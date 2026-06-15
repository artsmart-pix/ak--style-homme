# TEMPLATE.md — Guide pour Claude Code

> **À lire en premier.** Ce dépôt est un **template e-commerce réutilisable**
> (WordPress + WooCommerce, COD / paiement à la livraison, Algérie). Il est
> **cloné** pour chaque nouveau client. Ce fichier explique ce que tu dois savoir
> avant de toucher au code — surtout pour la mission la plus fréquente :
> **construire les 3 pages publiques avec le thème fourni par le client.**

---

## 0. Ta mission la plus probable sur un clone

L'owner **dépose le thème choisi dans `library/themes/`** du projet (voir §4). À
partir de ce thème, tu dois **construire / habiller les 3 pages publiques** de
façon **élégante, moderne et attirante**, en respectant strictement les règles
ci-dessous. Si aucun thème n'est fourni, demande-le avant de commencer.

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

> 🚨 **ISOLATION — à vérifier AVANT toute installation.** Chaque clone doit avoir
> sa **propre** stack. Les conteneurs sont nommés `wp_<service>_${PROJECT_SLUG}`
> (slug défini dans `.env`). Avant de lancer/installer quoi que ce soit :
> 1. `pwd` → tu es bien dans CE clone, pas dans le template ni un autre projet.
> 2. `docker ps -a --format 'table {{.Names}}\t{{.Label "com.docker.compose.project"}}'`
>    → repère les stacks existantes ; **ne touche jamais** aux conteneurs d'un autre projet.
> 3. `.env` : `PROJECT_SLUG` + `COMPOSE_PROJECT_NAME` **uniques**, ports libres
>    (`WP_PORT`/`PMA_PORT`) — sinon collision / installation dans le mauvais projet.
> 4. Lancer **`bash scripts/preflight.sh`** AVANT `docker compose up` : il refuse
>    de continuer (sortie ≠ 0) si le slug est resté au défaut, ou si un conteneur /
>    port est déjà pris par un autre projet. C'est le garde-fou automatique.
> 5. **Toujours** piloter via `docker compose exec <service>` **depuis le dossier
>    du clone** — JAMAIS `docker exec wp_cli_c1 …` (nom global = risque de viser un
>    autre projet). C'est l'erreur qui a déjà fait installer des plugins sur le
>    mauvais site.

Services (voir `docker-compose.yml`), à piloter depuis le dossier du projet :

- `wpcli` — WP-CLI : `docker compose exec wpcli wp <cmd>`
- `wordpress` — Apache (le site), `db` — MariaDB, `phpmyadmin` — phpMyAdmin

Accès (ports dans `.env`) : Boutique `http://localhost:${WP_PORT}` · Admin
`/wp-admin` · phpMyAdmin `http://localhost:${PMA_PORT}`.

**À savoir :**

- **HPOS actif** → le meta des commandes n'est **pas** dans `postmeta`. Ne pas
  utiliser `wp post meta get` pour une commande. Utiliser :
  `docker compose exec wpcli wp eval '$o=wc_get_order($id); echo $o->get_meta("_cle");'`
  Lister : `docker compose exec wpcli wp wc shop_order list --user=1 --fields=id,status`.
- L'hôte **n'a pas de PHP** : faire les `php -l` / lint **dans le conteneur**.
- **Pas d'internet sortant fiable** par défaut. Un bloc `dns: [8.8.8.8, 1.1.1.1]`
  dans `docker-compose.yml` débloque les appels API externes (Ecotrack…). Si un
  clone neuf échoue sur des appels externes, vérifier ce bloc en premier.
  → Conséquence : **les packs de langue / traductions AR se font à la main**
  (pas de `wp language core install ar` sans réseau).

---

## 4. Le thème du projet — fourni dans `library/themes/`

**Méthode :** l'owner **choisit lui-même** le thème et le **dépose dans le dossier
du projet**, sous `library/themes/`. Tu construis les 3 pages à partir de CE
thème-là. (Plus de bibliothèque externe à parcourir : un seul thème, local au clone.)

> Si aucun thème n'est présent dans `library/themes/`, **demande-le à l'owner**
> avant de commencer — ne choisis pas à sa place.

### Où le déposer

- **`library/themes/*.zip`** — dossier project-local, **gitignoré** (licences/poids),
  monté dans le conteneur à `/library` et **installé automatiquement par
  `setup.sh`** (`wp theme install`).
- Plugins tiers nécessaires au thème (Elementor, Hello…) : **`library/plugins/*.zip`**
  (même mécanisme d'installation auto).

### Deux cas selon l'artefact fourni

1. **Vrai thème WordPress** (`.zip` Astra / Hello / child theme…) → `setup.sh`
   l'installe ; tu l'actives et tu bâtis les 3 pages avec.
2. **Elementor Template Kit** (`.zip` Envato : `manifest.json` + `templates/*.json`
   + `screenshots/*.jpg`) → ce n'est **pas** un thème : il s'importe via Elementor
   (Outils → *Importer un Kit*), sur un thème de base léger (**Hello Elementor**
   ou **Astra**) + **Elementor** déposé dans `library/plugins/`. N'importe **que**
   header, footer, home→Accueil, contact→Contact — **jamais** tout le démo
   multi-pages (règle 3).

### Comprendre le thème avant de coder

Si c'est un kit Elementor, inspecte-le sans tout importer :

```bash
THEME_ZIP="library/themes/<le-zip>.zip"
unzip -p "$THEME_ZIP" manifest.json | head -60        # pages incluses + styles
unzip -o "$THEME_ZIP" 'screenshots/*' -d /tmp/kit      # extraire les aperçus
#   puis ouvre-les avec l'outil Read (il lit les images) :
#   Read /tmp/kit/screenshots/home.jpg  et  global-kit-styles.png
```

Regarde `home.jpg` (mise en page) et `global-kit-styles.png` (couleurs/typo) pour
t'imprégner du style, puis **reproduis-le fidèlement** sur les 3 pages.

### Contraintes d'intégration

- Adapter le thème en **3 pages** seulement (Accueil / Boutique / Contact),
  **mobile-first**, **RTL-compatible** (éviter les mises en page trop dépendantes
  du sens LTR).
- **Pas** de tunnel panier/compte ; la **Boutique = page Woo Shop** habillée au
  style du thème (pas une page Elementor déconnectée de WooCommerce).
- **Branding** (logo + nom) via le Personnalisateur, jamais codé en dur.

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
cp .env.example .env            # éditer : PROJECT_SLUG + COMPOSE_PROJECT_NAME UNIQUES,
                                #          ports libres (WP_PORT/PMA_PORT), mots de passe propres
bash scripts/preflight.sh       # GARDE-FOU : refuse de continuer si mal isolé (slug défaut,
                                #             conteneurs/ports déjà pris par un autre projet)
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
