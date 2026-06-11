# AOD Client Dashboard

Plugin WordPress/WooCommerce **maison** (Art Of Doing) — espace de gestion
**front-end** pour le client de la boutique. Le gérant administre ses commandes,
produits, livraison, pixels et notifications **sans jamais accéder à `wp-admin`**.
Sans licence, dupliquable à l'infini.

## Fonctionnalités

- 🧾 **Commandes** : liste filtrée par statut (avec compteurs), recherche (n°/nom/téléphone), pagination, détail en modale, changement de statut en AJAX
- 📦 **Produits** : CRUD complet, **variantes couleur** (prix / stock / photo par couleur), upload d'image, catégories, gestion du stock, recherche + pagination
- 🚚 **Livraison** : tarifs des 58 wilayas (Domicile / Stop-desk), livraison gratuite, transporteurs + envoi automatique — *réutilise le plugin [AOD COD Form](../aod-cod-form/)*
- 📊 **Statistiques** : filtre par période (7 j / 30 j / année / tout), chiffre d'affaires, panier moyen, commandes encaissées / en attente / annulées, produits en ligne, **top-produits** (par CA généré) et **graphe d'évolution du CA** (barres CSS, sans JS)
- 🎯 **Pixels & Tracking** : Meta, TikTok, Snapchat, Google Ads
- 💬 **WhatsApp** : notification de commande via CallMeBot + message test
- 👤 **Mon compte** : le gérant change lui-même son nom, son e-mail et son mot de passe
- 🛡️ **Rôle restreint** « Gestion Boutique » : capacités e-commerce uniquement, **aucune** capacité dangereuse (plugins, thèmes, réglages, utilisateurs, cœur)
- 🌐 **RTL / traduisible** — text domain `aod-client-dashboard`, **traduction arabe complète** fournie

## Accès & sécurité

- Interface plein écran à l'URL **`/gestion`** (prend la main sur le thème).
- Le rôle `aod_shop_client` est **bloqué hors de `wp-admin`** (redirigé vers `/gestion`), barre d'admin masquée, page de connexion marquée au logo de la boutique.
- Sécurité par **capabilities**, pas par masquage d'UI : la liste `FORBIDDEN` retire explicitement toute capacité pouvant casser le site, et un *resync* la maintient après les mises à jour de WooCommerce.
- Tous les appels AJAX vérifient **nonce + capability**.
- Compatible **HPOS** (High-Performance Order Storage).

## Utilisation

### Créer le compte du client (administrateur)

`Utilisateurs → Accès Gérant` → renseigner identifiant + mot de passe (un mot de
passe fort est pré-rempli). Le compte est créé avec le rôle « Gestion Boutique » ;
le client se connecte normalement et arrive directement sur `/gestion`.

> Alternative manuelle : créer un utilisateur et lui attribuer le rôle **Gestion Boutique**.

### Côté client

Le gérant ouvre **`/gestion`** et navigue dans les 7 sections via le menu latéral.
Toutes les actions (statut, produit, réglages) sont instantanées (AJAX).

## Dépendances

- **WooCommerce** : requis (le plugin se désactive proprement sinon).
- **AOD COD Form** : *fortement recommandé*. Il fournit le statut « Confirmée »,
  les tarifs par wilaya et les transporteurs. Sans lui, ces parties se masquent
  proprement et un avertissement s'affiche côté admin.

## Structure

```
aod-client-dashboard/
├── aod-client-dashboard.php          # bootstrap : rôle, /gestion, dépendances
├── uninstall.php                     # suppression du plugin : retire le rôle + la capability
├── includes/
│   ├── class-aod-cd-roles.php        # rôle restreint + liste FORBIDDEN + resync
│   ├── class-aod-cd-access.php       # blocage wp-admin, redirections, page login
│   ├── class-aod-cd-dashboard.php    # coque /gestion, routeur, sections + AJAX
│   └── class-aod-cd-account.php      # page admin « Accès Gérant » (création de compte)
├── assets/
│   ├── css/dashboard.css             # styles (RTL-aware)
│   └── js/dashboard.js               # AJAX (statuts, produits, réglages, modale)
└── languages/
    ├── aod-client-dashboard.pot      # gabarit d'extraction
    ├── aod-client-dashboard-ar.po    # traduction arabe (source)
    └── aod-client-dashboard-ar.mo    # traduction arabe (compilée)
```

## Internationalisation

L'espace de gestion est **bilingue FR ⇄ AR**. Un bouton dans l'en-tête (`AOD_CD_Lang`,
`includes/class-aod-cd-lang.php`) bascule la langue ; le choix est mémorisé dans le
cookie `aod_lang` (1 an), **partagé avec le sélecteur de la partie publique** — le
gérant retrouve donc la même langue côté boutique et côté gestion. L'arabe passe
automatiquement en RTL (`dir="rtl"` via `is_rtl()`).

> À défaut de bouton, le locale WordPress est respecté (profil du gérant → Langue → العربية).

### Régénérer les traductions après modification des chaînes

**Lance ce script à chaque ajout/modification de texte** (PHP `__()/_e()…` ou clés
`window.AOD_CD` injectées vers le JS). Il extrait, fusionne (en préservant l'existant),
recompile le `.mo` et **liste ce qu'il reste à traduire** :

```bash
plugins-dev/aod-client-dashboard/bin/make-translations.sh
```

Puis édite `languages/aod-client-dashboard-ar.po` pour les chaînes signalées « à
traduire » et relance le script (la traduction arabe reste manuelle). Détection
auto de wp-cli (hôte ou conteneur Docker `wp_cli_c1`) ; `--strict` sort en erreur
s'il manque des traductions (utile en CI/hook).

> ⚠️ Oublier cette étape = des libellés qui restent en français quand on bascule
> en arabe. C'est la cause classique d'un dashboard « à moitié traduit ».

## Développement local

Le dossier est monté en direct dans le conteneur WordPress
(`./plugins-dev/aod-client-dashboard` → `/var/www/html/wp-content/plugins/aod-client-dashboard`),
donc **toute édition depuis le PC est live** (rafraîchir la page ; bumper la
version du plugin force le rechargement du CSS/JS via `?v=`).

## Roadmap (idées d'évolution)

- [x] Statistiques enrichies : filtre par période, top-produits, graphe d'évolution du CA ✓
- [ ] Galerie multi-images produit + réutilisation des médias existants
- [ ] Variantes au-delà de la couleur (taille, etc.)
- [ ] Badge « nouvelles commandes » en temps réel
- [ ] Section d'aide / onboarding du client
```

