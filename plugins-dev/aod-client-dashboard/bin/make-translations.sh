#!/usr/bin/env bash
#
# make-translations.sh — Rafraîchit les traductions du plugin AOD Client Dashboard.
#
# À LANCER après chaque ajout/modification de texte dans le code PHP ou JS.
# Il enchaîne automatiquement les 3 étapes qui, oubliées, laissent des libellés
# en français quand on bascule en arabe :
#
#   1. EXTRACTION  : régénère le .pot à partir de TOUTES les chaînes __()/_e()…
#   2. FUSION      : reporte le .pot dans chaque .po de langue en CONSERVANT
#                    les traductions déjà faites (les nouvelles chaînes sont
#                    simplement marquées « à traduire »).
#   3. COMPILATION : génère le .mo (le binaire réellement lu par WordPress) et
#                    affiche ce qu'il reste à traduire.
#
# Ce que le script NE fait PAS : traduire à ta place. L'arabe (qualité) reste
# humain. À la fin, il liste précisément les chaînes manquantes par langue.
#
# Usage :
#   bin/make-translations.sh                 # toutes les langues présentes
#   bin/make-translations.sh ar              # une langue précise
#   bin/make-translations.sh --strict        # code de sortie ≠ 0 s'il manque des trads
#
# Réglages (variables d'environnement, valeurs par défaut entre parenthèses) :
#   AOD_WPCLI_CONTAINER  conteneur Docker contenant wp-cli   (wp_cli_c1)
#   AOD_WPCLI            commande wp-cli si installée sur l'hôte (auto-détection)
#
set -euo pipefail

# --- Localisation du plugin (indépendant du répertoire courant) -------------
SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" >/dev/null 2>&1 && pwd -P)"
PLUGIN_DIR="$(cd -- "$SCRIPT_DIR/.." >/dev/null 2>&1 && pwd -P)"
SLUG="$(basename -- "$PLUGIN_DIR")"          # ex. aod-client-dashboard
LANG_DIR="$PLUGIN_DIR/languages"
POT="$LANG_DIR/$SLUG.pot"

# --- Couleurs (désactivées si pas un terminal) ------------------------------
if [ -t 1 ]; then C_OK=$'\033[32m'; C_WARN=$'\033[33m'; C_ERR=$'\033[31m'; C_DIM=$'\033[2m'; C_B=$'\033[1m'; C_X=$'\033[0m'
else C_OK=; C_WARN=; C_ERR=; C_DIM=; C_B=; C_X=; fi
say()  { printf '%s\n' "$*"; }
ok()   { printf '%s✓%s %s\n' "$C_OK" "$C_X" "$*"; }
warn() { printf '%s⚠%s %s\n' "$C_WARN" "$C_X" "$*"; }
die()  { printf '%s✗ %s%s\n' "$C_ERR" "$*" "$C_X" >&2; exit 1; }

# --- Pré-requis : gettext sur l'hôte ----------------------------------------
for t in msgmerge msgfmt msgattrib; do
	command -v "$t" >/dev/null 2>&1 || die "« $t » introuvable. Installe gettext : sudo dnf install -y gettext"
done

# --- Détermination de la commande wp-cli ------------------------------------
# Priorité : variable AOD_WPCLI > wp sur l'hôte > docker exec <conteneur> wp.
# Le .pot est écrit DIRECTEMENT dans languages/ (dossier monté en volume),
# donc aucun « docker cp » n'est nécessaire.
WPCLI_MODE=""
POT_TARGET_DIR="$PLUGIN_DIR"   # chemin passé à wp i18n make-pot

if [ -n "${AOD_WPCLI:-}" ]; then
	WPCLI=$AOD_WPCLI; WPCLI_MODE="custom"
elif command -v wp >/dev/null 2>&1; then
	WPCLI="wp"; WPCLI_MODE="host"
else
	CONTAINER="${AOD_WPCLI_CONTAINER:-wp_cli_c1}"
	command -v docker >/dev/null 2>&1 || die "Ni wp-cli sur l'hôte, ni docker. Définis AOD_WPCLI=… ou installe wp-cli."
	docker ps --format '{{.Names}}' | grep -qx "$CONTAINER" \
		|| die "Conteneur « $CONTAINER » introuvable/arrêté. Démarre-le ou exporte AOD_WPCLI_CONTAINER=<nom>."
	CONTAINER_PLUGIN="/var/www/html/wp-content/plugins/$SLUG"
	docker exec "$CONTAINER" test -d "$CONTAINER_PLUGIN" \
		|| die "Le plugin n'est pas monté à $CONTAINER_PLUGIN dans « $CONTAINER »."
	WPCLI="docker exec $CONTAINER wp"
	WPCLI_MODE="docker"
	POT_TARGET_DIR="$CONTAINER_PLUGIN"
fi

say "${C_B}AOD — mise à jour des traductions${C_X} ${C_DIM}($SLUG)${C_X}"
say "${C_DIM}wp-cli : $WPCLI_MODE — gettext : hôte${C_X}"
say ""

# --- 1) EXTRACTION : (re)génère le .pot -------------------------------------
say "${C_B}1/3${C_X} Extraction des chaînes → $SLUG.pot"
if [ "$WPCLI_MODE" = "docker" ]; then
	# Le dossier languages/ est monté depuis l'hôte : l'utilisateur du conteneur
	# n'a pas le droit d'y écrire. On génère dans /tmp du conteneur puis on
	# rapatrie le .pot avec « docker cp » (qui écrit côté hôte avec nos droits).
	# shellcheck disable=SC2086
	$WPCLI i18n make-pot "$POT_TARGET_DIR" "/tmp/$SLUG.pot" --slug="$SLUG" --skip-audit >/dev/null
	docker cp "$CONTAINER:/tmp/$SLUG.pot" "$POT" >/dev/null
	docker exec "$CONTAINER" rm -f "/tmp/$SLUG.pot" 2>/dev/null || true
else
	# shellcheck disable=SC2086
	$WPCLI i18n make-pot "$POT_TARGET_DIR" "$POT" --slug="$SLUG" --skip-audit >/dev/null
fi
[ -f "$POT" ] || die "Le .pot n'a pas été généré ($POT)."
POT_N=$(grep -c '^msgid "' "$POT" || true)
ok "$POT_N chaînes extraites."
say ""

# --- Langues à traiter ------------------------------------------------------
# Argument explicite, sinon toutes les langues présentes (<slug>-XX.po).
STRICT=0; LANGS=()
for a in "$@"; do
	case "$a" in
		--strict) STRICT=1 ;;
		-*) die "Option inconnue : $a" ;;
		*) LANGS+=("$a") ;;
	esac
done
if [ "${#LANGS[@]}" -eq 0 ]; then
	for po in "$LANG_DIR/$SLUG"-*.po; do
		[ -e "$po" ] || continue
		f="$(basename -- "$po")"; f="${f#"$SLUG"-}"; LANGS+=("${f%.po}")
	done
fi
[ "${#LANGS[@]}" -gt 0 ] || die "Aucun fichier de langue ($SLUG-*.po) trouvé dans $LANG_DIR."

# --- 2) + 3) FUSION puis COMPILATION, par langue ----------------------------
MISSING_TOTAL=0
for L in "${LANGS[@]}"; do
	PO="$LANG_DIR/$SLUG-$L.po"
	MO="$LANG_DIR/$SLUG-$L.mo"
	say "${C_B}2/3${C_X} Fusion du .pot dans $SLUG-$L.po ${C_DIM}(préserve l'existant)${C_X}"
	[ -f "$PO" ] || die "Fichier de langue absent : $PO. Pour créer une langue : msginit --no-translator -l $L -i \"$POT\" -o \"$PO\""
	msgmerge --update --backup=none --no-fuzzy-matching --quiet "$PO" "$POT"
	ok "Fusion faite."

	say "${C_B}3/3${C_X} Compilation → $SLUG-$L.mo"
	msgfmt --check --output-file="$MO" "$PO"
	ok "$(msgfmt --statistics --output-file=/dev/null "$PO" 2>&1)"

	# Chaînes encore à traduire (hors en-tête vide et hors métadonnées de marque).
	UNTR=$(msgattrib --untranslated --no-obsolete "$PO" \
		| grep '^msgid "' | grep -v '^msgid ""$' \
		| grep -vE '^msgid "(AOD Client Dashboard|Art Of Doing|https?://)' || true)
	N=$(printf '%s' "$UNTR" | grep -c '^msgid' || true)
	if [ "$N" -gt 0 ]; then
		MISSING_TOTAL=$((MISSING_TOTAL + N))
		warn "[$L] $N chaîne(s) à traduire en arabe :"
		printf '%s\n' "$UNTR" | sed 's/^msgid /    /'
	else
		ok "[$L] Tout est traduit."
	fi
	say ""
done

# --- Bilan ------------------------------------------------------------------
if [ "$MISSING_TOTAL" -gt 0 ]; then
	warn "$MISSING_TOTAL chaîne(s) restent à traduire. Édite le(s) .po puis relance ce script."
	[ "$STRICT" -eq 1 ] && exit 2 || exit 0
fi
ok "Traductions à jour. .mo recompilé(s)."
