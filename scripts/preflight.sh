#!/usr/bin/env bash
# ───────────────────────────────────────────────────────────────
# preflight.sh — garde-fou d'ISOLATION. À lancer DEPUIS L'HÔTE,
# AVANT « docker compose up » :
#       bash scripts/preflight.sh
#
# Refuse de continuer (sortie ≠ 0) si le projet n'est pas correctement
# isolé — pour ne JAMAIS écraser ni piloter les conteneurs d'un autre
# projet (cause des installations qui atterrissaient sur le mauvais site).
# Vérifie : .env présent, PROJECT_SLUG / COMPOSE_PROJECT_NAME définis et
# non-défaut, aucun conteneur wp_*_<slug> appartenant à un autre dossier,
# ports WP_PORT/PMA_PORT libres.
# ───────────────────────────────────────────────────────────────
set -euo pipefail

cd "$(dirname -- "${BASH_SOURCE[0]}")/.." >/dev/null 2>&1
ROOT="$(pwd -P)"

grn(){ printf '\033[32m✓\033[0m %s\n' "$*"; }
ylw(){ printf '\033[33m⚠\033[0m %s\n' "$*"; }
fail(){ printf '\033[31m✗ %s\033[0m\n' "$*" >&2
        printf '\n\033[31mPréflight ÉCHOUÉ — corrige le .env puis relance. Aucun conteneur démarré.\033[0m\n' >&2
        exit 1; }

[ -f .env ] || fail ".env absent. Fais: cp .env.example .env  puis personnalise PROJECT_SLUG / ports."

# Charger le .env (fichier contrôlé par toi)
set -a; . ./.env; set +a

# 1) PROJECT_SLUG défini, non-défaut, format propre
[ -n "${PROJECT_SLUG:-}" ]        || fail "PROJECT_SLUG vide dans .env."
[ "${PROJECT_SLUG}" != "mon-projet" ] || fail "PROJECT_SLUG vaut encore le défaut « mon-projet ». Choisis un slug UNIQUE (ex. pantalon-femme)."
printf '%s' "$PROJECT_SLUG" | grep -qE '^[a-z0-9][a-z0-9-]*$' || fail "PROJECT_SLUG invalide « $PROJECT_SLUG » (attendu: [a-z0-9-])."
grn "PROJECT_SLUG = $PROJECT_SLUG"

# 2) COMPOSE_PROJECT_NAME défini, non-défaut
[ -n "${COMPOSE_PROJECT_NAME:-}" ]        || fail "COMPOSE_PROJECT_NAME vide dans .env."
[ "${COMPOSE_PROJECT_NAME}" != "mon-projet" ] || fail "COMPOSE_PROJECT_NAME vaut encore « mon-projet ». Mets une valeur unique."
grn "COMPOSE_PROJECT_NAME = $COMPOSE_PROJECT_NAME"

if command -v docker >/dev/null 2>&1; then
  # 3) Un conteneur wp_*_<slug> appartient-il déjà à un AUTRE dossier de projet ?
  for svc in app db cli pma; do
    name="wp_${svc}_${PROJECT_SLUG}"
    line="$(docker ps -a --filter "name=^${name}$" \
            --format '{{.Label "com.docker.compose.project.working_dir"}}' 2>/dev/null || true)"
    [ -n "$line" ] || continue
    if [ "$line" != "$ROOT" ]; then
      fail "Conteneur « $name » existe déjà pour un AUTRE projet (dossier: $line). Choisis un PROJECT_SLUG différent."
    fi
  done
  grn "Aucun conteneur wp_*_${PROJECT_SLUG} en conflit."

  # 4) Ports déjà publiés par un conteneur d'un autre projet ?
  for p in "${WP_PORT:-}" "${PMA_PORT:-}"; do
    [ -n "$p" ] || continue
    used="$(docker ps --format '{{.Names}} {{.Ports}}' \
            | grep -E "(^|[^0-9]):${p}->" \
            | grep -vE "wp_[a-z]+_${PROJECT_SLUG}( |\$)" || true)"
    [ -z "$used" ] || fail "Port $p déjà publié par un autre conteneur:
    ${used}
  Change WP_PORT/PMA_PORT dans .env."
  done
  grn "Ports ${WP_PORT:-?}/${PMA_PORT:-?} libres côté Docker."
else
  ylw "docker introuvable sur l'hôte — vérif conteneurs/ports sautée."
fi

printf '\n'
grn "Préflight OK — isolation validée. Tu peux lancer : docker compose up -d"
