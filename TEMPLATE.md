# TEMPLATE.md — Guide pour Claude Code

> **À lire en premier.** Ce dépôt est un **template e-commerce réutilisable**
> (WordPress + WooCommerce, COD / paiement à la livraison, Algérie). Il est
> **cloné** pour chaque nouveau client. Ce fichier explique ce que tu dois savoir
> avant de toucher au code — surtout pour la mission la plus fréquente :
> **construire les 3 pages publiques avec le thème fourni par le client.**

---

## 0. Ta mission la plus probable sur un clone

Le client fournit **un thème** (zip, maquette, ou simplement une direction
artistique : couleurs, logo, polices, ambiance) — **ou rien de précis**, et c'est
alors à toi de **choisir le kit le plus adapté dans la bibliothèque de thèmes**
(voir §4). À partir de ce thème, tu dois **construire / habiller les 3 pages
publiques** de façon **élégante, moderne et attirante**, en respectant strictement
les règles ci-dessous.

Le socle technique (plugins maison, COD, dashboard gérant, i18n) **existe déjà et
ne change pas**. Ton travail côté public = **présentation + intégration du thème**,
pas réécriture de la logique métier.

---

## 1. Les règles d'or (ne jamais les enfreindre)

1. **C'est un template générique.** Tout choix est hérité par tous les futurs
   clones. → Rester **générique, minimal, reproductible**. Pas de valeurs client
   en dur (nom, couleurs, clés API) : tout se configure depuis l'interface
   (Personnalisateur WordPress + espace `/gestion`).
2. **Aucune fonctionnalité superflue.** N'ajoute que ce qui est demandé.
   « Je veux pas des choses inutiles ou de plus. »
3. **Côté public = exactement 3 pages :** **Accueil**, **Boutique**, **Contact**.
   La barre de navigation ne montre **que ces 3 entrées**.
4. **JAMAIS de panier ni de compte** côté acheteur. Pas d'icône Woo cart /
   checkout / my-account / wishlist. L'achat passe **uniquement** par le
   formulaire COD sur la page produit.
5. **Bilingue FR ⇄ AR + RTL.** Toute chaîne visible doit être traduisible
   (voir §7). L'arabe doit fonctionner sur la zone publique **et** le formulaire
   de contact.
6. **Mobile d'abord.** La majorité du trafic DZ est mobile : teste tout en
   responsive avant de considérer une page « finie ».

---

## 2. Ce que contient le template

### Deux plugins maison (Art Of Doing) — `plugins-dev/`

| Plugin                                                      | Rôle                                                                                | Notes                                                                                                                                                                                                                                                             |
| ----------------------------------------------------------- | ----------------------------------------------------------------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| [`aod-cod-form`](plugins-dev/aod-cod-form/)                 | Formulaire de commande COD sur la page produit (sans panier ni compte)              | 58 wilayas / 1541 communes, tarifs livraison Domicile/Stop-desk, transporteurs Yalidine/Noest/Ecotrack + envoi auto, pixels (Meta/TikTok/Snap/Google), WhatsApp (CallMeBot), suivi prospects, FR/AR + RTL. Contient aussi le **switcher de langue FR/AR** public. |
| [`aod-client-dashboard`](plugins-dev/aod-client-dashboard/) | Espace gérant front-end `/gestion` (le client gère sa boutique **sans `wp-admin`**) | Commandes, Produits (CRUD + variantes couleur), Livraison, Pixels, WhatsApp, Stats, rôle restreint « Gestion Boutique », FR/AR + RTL complet.                                                                                                                     |

Ces plugins sont **bind-montés** dans le conteneur → toute édition dans
`plugins-dev/` est immédiatement live.

### Stack de dev (Docker) — `docker-compose.yml`

WordPress + MariaDB + phpMyAdmin + WP-CLI. Voir [`README-LOCAL.md`](README-LOCAL.md).

### Ce qui n'est PAS versionné

- `library/` : zips des **thèmes & plugins tiers** (poids + licences). `scripts/setup.sh`
  installe automatiquement tous les `.zip` qui s'y trouvent.
- `.env` : secrets (mots de passe BDD/admin) + ports. **À créer depuis `.env.example`.**
- Le **thème du client** : il arrive au moment du projet, pas dans le template de base.

---

## 3. Environnement de dev local (Docker)

Conteneurs (voir `docker-compose.yml`) :

- `wp_cli_c1` — WP-CLI : `docker exec wp_cli_c1 wp <cmd>`
- `wp_app_c1` — Apache (le site), `wp_db_c1` — MariaDB, `wp_pma_c1` — phpMyAdmin

Accès (ports dans `.env`) : Boutique `http://localhost:8090` · Admin
`/wp-admin` · phpMyAdmin `http://localhost:8091`.

**À savoir :**

- **HPOS actif** → le meta des commandes n'est **pas** dans `postmeta`. Ne pas
  utiliser `wp post meta get` pour une commande. Utiliser :
  `docker exec wp_cli_c1 wp eval '$o=wc_get_order($id); echo $o->get_meta("_cle");'`
  Lister : `wp wc shop_order list --user=1 --fields=id,status`.
- L'hôte **n'a pas de PHP** : faire les `php -l` / lint **dans le conteneur**.
- **Pas d'internet sortant fiable** par défaut. Un bloc `dns: [8.8.8.8, 1.1.1.1]`
  dans `docker-compose.yml` débloque les appels API externes (Ecotrack…). Si un
  clone neuf échoue sur des appels externes, vérifier ce bloc en premier.
  → Conséquence : **les packs de langue / traductions AR se font à la main**
  (pas de `wp language core install ar` sans réseau).

---

## 4. Choisir le thème — bibliothèque de kits Elementor

Le client n'arrive pas toujours avec un thème précis. Il existe une **grande
bibliothèque de kits prêts à l'emploi** rangés **par secteur d'activité** ; ta
mission est d'**analyser cette bibliothèque toi-même** et de proposer le kit qui
colle le mieux au projet.

### Où elle se trouve

```
/home/ouazene/Dev/wordpress-ecommerce/themes/Wordpress - Template kits-*/Wordpress - Template kits/
```

⚠️ Le dossier est **hors du dépôt** et son nom contient un suffixe horodaté
(téléchargement type Google Drive) qui **change à chaque export**. Ne code jamais
ce chemin en dur : localise-le par motif, p. ex.
`ls -d /home/ouazene/Dev/wordpress-ecommerce/themes/*/Wordpress\ -\ Template\ kits 2>/dev/null`.

### Ce que c'est

Des **Elementor Template Kits** (format Envato). Chaque `.zip` contient :

- `manifest.json` — métadonnées : titre, liste des templates, types de contenu ;
- `templates/*.json` — les pages Elementor (`header`, `footer`, `home`, `about`,
  `services`, `contact`, `pricing`, `blog`…) ;
- `screenshots/*.jpg` — **un aperçu visuel par template** + `global-kit-styles.png`
  (palette de couleurs + typographies globales du kit).

> Conséquence importante : utiliser un kit **introduit Elementor** (+ un thème de
> base léger : **Hello Elementor** ou **Astra**). C'est un choix par projet —
> garde-le minimal (règle 2) et n'importe **que** ce dont les 3 pages ont besoin.

### Catégories disponibles (≈ 10–11 kits chacune)

Agence de Voyage & Tourisme · AI & Technology · Corporate / Business ·
Education & Formation · Gym & Coach Sportif · Hotel & Riad · IPTV ·
Local associations (assoc., immobilier local, déménagement, padel, ONG…) ·
Marketing Agency · Portfolio & Personal Brand · Real Estate · Restaurant & Café ·
Santé & Medical.

### Procédure de sélection (à suivre par toi-même)

1. **Cerner le besoin** : secteur d'activité du client + direction artistique
   (couleurs, ambiance, niveau de luxe/minimalisme). Au besoin, pose 1–2 questions.
2. **Catégorie** : choisir le dossier dont le thème métier est le plus proche
   (ex. boutique de produits cosmétiques → souvent « Santé & Medical » ou
   « Restaurant & Café » selon l'ambiance ; à défaut, le plus neutre/« Corporate »).
3. **Présélection** : lister les kits de la catégorie et lire leurs `manifest.json`
   (titre + pages incluses).
4. **Jugement visuel — fais-le vraiment** : extraire les aperçus d'un kit candidat
   et **les ouvrir avec l'outil Read** (il lit les images). Regarde surtout
   `screenshots/home.jpg` (mise en page) et `screenshots/global-kit-styles.png`
   (couleurs/typo). C'est ça « analyser par soi-même ».
5. **Filtrer selon nos contraintes** : mobile-first, RTL-compatible (éviter les
   mises en page trop dépendantes du sens LTR), pas de tunnel panier/compte à
   réintroduire, et adaptable en **3 pages** (Accueil/Boutique/Contact).
6. **Proposer 2–3 finalistes** avec une justification courte + l'aperçu de chacun,
   et laisser l'owner trancher (`AskUserQuestion`). Ne jamais importer en masse
   tout le démo multi-pages : on ne garde que header, footer, home→Accueil,
   contact→Contact, et on habille la Boutique Woo au même style.

### Inspecter un kit sans l'importer

```bash
KITS="$(ls -d /home/ouazene/Dev/wordpress-ecommerce/themes/*/'Wordpress - Template kits' 2>/dev/null | head -1)"

# 1) Tous les kits, par catégorie
for c in "$KITS"/*/; do echo "## $(basename "$c")"; ls "$c" | grep -i '\.zip$'; done

# 2) Pages incluses + styles d'un kit (sans dézipper tout le zip)
unzip -p "$KITS/<Catégorie>/<Kit>.zip" manifest.json | head -60

# 3) Extraire UNIQUEMENT les aperçus pour les regarder avec l'outil Read
unzip -o "$KITS/<Catégorie>/<Kit>.zip" 'screenshots/*' -d /tmp/kit-preview
#   puis Read /tmp/kit-preview/screenshots/home.jpg  et  global-kit-styles.png
```

Une fois le kit retenu → l'importer dans `library/themes/` du clone (ou via
Elementor → Outils → *Importer un Kit*), puis appliquer le cahier des charges §5.

---

## 5. Les 3 pages publiques — cahier des charges

| Page         | Source actuelle                                                                   | Ce que tu fais                                                                                                                              |
| ------------ | --------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------- |
| **Accueil**  | Page statique (front page)                                                        | Vitrine : héro, mise en avant produits/catégories, réassurance (COD, livraison 58 wilayas), CTA vers la Boutique. Habillée au thème client. |
| **Boutique** | Page **Boutique WooCommerce** (Shop) — contient **toutes les boutiques/produits** | Grille produits propre et rapide ; au clic produit → page produit avec le **formulaire COD** (pas de bouton « Ajouter au panier »).         |
| **Contact**  | Page statique                                                                     | Coordonnées + **formulaire de contact** bilingue FR/AR (RTL).                                                                               |

**Navigation :** un seul menu (ex. « Menu principal ») = Accueil / Boutique /
Contact, assigné aux emplacements de thème **`primary` ET `mobile_menu`**
(important avec Astra : sans `mobile_menu`, le menu off-canvas retombe sur
`wp_page_menu` et **réexpose Cart/Checkout/My-account** → violation de la
règle 4). Le switcher FR/AR du plugin `aod-cod-form` ne s'affiche **que** si un
menu est assigné à `primary`/`secondary_menu`/`mobile_menu` → faire la nav
**avant** de traduire.

**Intégration du thème client :**

- Préfère un **child theme** (`wordpress/wp-content/themes/<theme>-child/`) pour
  que les personnalisations survivent aux mises à jour du thème parent.
- Logo + nom du site via le **Personnalisateur** → le branding du dashboard/login
  suit automatiquement. Ne code pas le logo en dur.
- Vérifie le **RTL** du thème (passe le site en AR et contrôle le rendu).
- Objectif qualité : élégant, moderne, rapide (< 3 s mobile), accessible.

---

## 6. Le flux de commande (à respecter dans toute page produit)

Acheteur → page produit → **formulaire COD** (`aod-cod-form`) :
Nom, **Téléphone (obligatoire)**, Wilaya + Commune (listes déroulantes),
Adresse, e-mail **facultatif**. → commande créée sans panier ni compte → le
gérant la traite dans `/gestion` → envoi transporteur (Yalidine/Noest/Ecotrack).

Ne réintroduis jamais le tunnel WooCommerce standard (panier → checkout → compte).

---

## 7. i18n — workflow FR ⇄ AR (obligatoire après toute chaîne UI)

Le toggle FR/AR pose un cookie `aod_lang` partagé entre le public (`aod-cod-form`)
et `/gestion` (`aod-client-dashboard`) ; l'AR bascule en RTL via `is_rtl()`.

- **Toute** chaîne visible doit passer par `__()/_e()` (PHP) ou l'objet i18n JS
  (`window.AOD_CD` / équivalent) — jamais de texte FR en dur.
- Après avoir ajouté/modifié une chaîne du dashboard : lancer
  [`plugins-dev/aod-client-dashboard/bin/make-translations.sh`](plugins-dev/aod-client-dashboard/bin/make-translations.sh)
  (extract → merge → compile `.mo` + liste les non-traduites), puis traduire les
  chaînes signalées dans `languages/*-ar.po`, et relancer. `--strict` sort en
  erreur s'il en reste (hook/CI).
- La traduction AR est **manuelle** (pas d'internet). **Glossaire de référence** :
  `woo-jude-form-premium.1.0.7/woo-jude-form-premium/languages/woo-jude-form-ar-complete.po`
  (source AR la plus riche) + données géo DZ (`include/places/DZ.php`,
  `include/states/DZ.php`) si besoin.
- Les chaînes de **marque** (nom du plugin, auteur, URL) restent volontairement
  non traduites.

---

## 8. Mettre en route un nouveau clone

```bash
git clone <repo> mon-client && cd mon-client
cp .env.example .env            # éditer : titre, mots de passe PROPRES au projet, ports
# déposer les zips dans library/ :  plugins/*.zip (woocommerce…)  themes/*.zip (thème client)
docker compose up -d
docker compose exec wpcli bash /scripts/setup.sh   # idempotent : installe/configure WP+Woo (Algérie/DZD/COD)
```

Puis : activer WooCommerce + les 2 plugins `aod-*` → appliquer le **thème client**
(logo + nom via Personnalisateur) → créer le compte gérant (Utilisateurs → Accès
Gérant) → le client règle WhatsApp/pixels/livraison dans `/gestion`.

---

## 9. Références utiles

- [`README.md`](README.md) — présentation générale du template.
- [`README-LOCAL.md`](README-LOCAL.md) — détails environnement Docker local.
- [`CHECKLIST-SITE-MASTER-ALGERIE.md`](CHECKLIST-SITE-MASTER-ALGERIE.md) — checklist de mise en place complète (hébergement → SEO → sécurité).
- `woo-jude-form-premium.1.0.7/` — **template de référence** (formulaire COD/RTL/AR
  premium). Non utilisé tel quel ; on y **pioche** : traductions AR, patterns RTL,
  données géo DZ. ⚠️ Pas de formulaire de contact prêt à l'emploi dedans — on adapte.

## 10. Checklist avant de dire « c'est fini » (côté public)

- [ ] Topbar = **exactement** Accueil / Boutique / Contact (desktop **et** mobile)
- [ ] **Aucun** panier / compte / wishlist visible nulle part
- [ ] Page produit = formulaire COD (pas de « Ajouter au panier »)
- [ ] FR **et** AR OK, switcher visible, `<html lang="ar">` + RTL correct en AR
- [ ] Rendu **mobile** soigné, rapide (< 3 s)
- [ ] Logo / nom du site pris depuis le Personnalisateur (rien codé en dur)
- [ ] Rien de spécifique à un seul client ajouté au template
