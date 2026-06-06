# Template WordPress E-commerce COD — Algérie 🇩🇿

Template **réutilisable** pour lancer rapidement des boutiques en ligne **COD**
(paiement à la livraison) en Algérie sous WordPress + WooCommerce.

> **Philosophie :** un seul socle générique pour tous les clients. Pour chaque
> nouveau projet, on **clone ce dépôt** et on **applique le thème** correspondant
> aux exigences du client — les plugins maison restent inchangés. Aucune donnée
> client n'est codée en dur : tout se configure depuis l'interface.

---

## ✨ Ce que contient le template

### 🔌 Deux plugins maison (Art Of Doing)

#### [`aod-cod-form`](plugins-dev/aod-cod-form/) — Formulaire COD
- Commande directe sur la page produit (sans panier ni compte)
- **58 wilayas / 1541 communes**, tarifs de livraison par wilaya (Domicile / Stop-desk)
- Livraison gratuite à partir d'un montant
- Transporteurs **Yalidine / Noest / Ecotrack** + envoi automatique (API)
- **Pixels** (Meta, TikTok, Snapchat, Google Ads) et **notification WhatsApp** (CallMeBot)
- Suivi des **prospects** (paniers abandonnés)
- FR / AR + RTL

#### [`aod-client-dashboard`](plugins-dev/aod-client-dashboard/) — Espace gérant `/gestion`
- Interface front-end pour que le client gère sa boutique **sans accéder à `wp-admin`**
- **Commandes** (filtres, recherche, détail, changement de statut AJAX)
- **Produits** (CRUD complet + variantes couleur, upload, stock, catégories)
- **Livraison**, **Pixels**, **WhatsApp**, **Mon compte**
- **Statistiques** : filtre par période, CA, panier moyen, top-produits, **graphe d'évolution** (CSS pur)
- **Rôle restreint** « Gestion Boutique » (capabilities sûres uniquement, resync auto)
- FR / AR + RTL, traduction arabe complète

### 🐳 Stack de développement (Docker)
WordPress + MariaDB + phpMyAdmin + WP-CLI, prêts à l'emploi via `docker-compose.yml`.

---

## 🚀 Démarrage rapide

```bash
# 1. Cloner le dépôt
git clone git@github.com:artsmart-pix/Template-WordPress-Complet.git mon-client
cd mon-client

# 2. Créer le fichier d'environnement (mots de passe, ports, titre du site)
cp .env.example .env   # puis éditer .env
#    ⚠️  Le .env n'est PAS versionné : choisis des mots de passe propres au projet.

# 3. Déposer les thèmes/plugins tiers dans library/ (voir note ci-dessous)
#    library/plugins/*.zip   (woocommerce, polylang, chargily-epay…)
#    library/themes/*.zip    (astra, kadence, neve…)

# 4. Lancer la stack
docker compose up -d

# 5. Installer & configurer WordPress + WooCommerce (Algérie / DZD / COD)
docker compose exec wpcli bash /scripts/setup.sh
```

Accès par défaut (ports définis dans `.env`) :
- Boutique : `http://localhost:8090`
- Admin : `http://localhost:8090/wp-admin`
- phpMyAdmin : `http://localhost:8091`

> ℹ️ Les **plugins tiers et thèmes ne sont pas versionnés** (poids + licences).
> `scripts/setup.sh` installe automatiquement tous les `.zip` présents dans
> `library/`. Voir [`README-LOCAL.md`](README-LOCAL.md) pour les détails de l'environnement local.

---

## 🧩 Mettre en place un nouveau client

1. **Cloner** ce dépôt → éditer `.env` (titre du site, mots de passe, ports).
2. `docker compose up -d` puis `setup.sh`.
3. Activer **WooCommerce** + les 2 plugins `aod-*`.
4. **Appliquer le thème du client** et définir son **logo** (Personnalisateur)
   et le **nom du site** → le branding du dashboard/login suit automatiquement.
5. Créer le compte du gérant : **Utilisateurs → Accès Gérant**.
6. Côté client, tout se règle dans `/gestion` : WhatsApp, pixels, tarifs de
   livraison, transporteurs.
7. _(Optionnel)_ Locale arabe par utilisateur pour le gérant.

---

## 🗂️ Structure du dépôt

```
.
├── docker-compose.yml                # db (MariaDB) + wordpress + wpcli + phpmyadmin
├── .env                              # secrets & ports — NON versionné
├── scripts/setup.sh                  # installe/configure WP + WooCommerce (idempotent)
├── library/                          # zips thèmes/plugins tiers — NON versionnés
├── plugins-dev/
│   ├── aod-cod-form/                 # plugin formulaire COD
│   └── aod-client-dashboard/         # plugin espace gérant /gestion
├── README-LOCAL.md                   # notes d'environnement local
└── CHECKLIST-SITE-MASTER-ALGERIE.md  # checklist de mise en place
```

---

## 🔒 Sécurité & bonnes pratiques

- Le `.env` (mots de passe BDD + admin) **n'est jamais versionné** (`.gitignore`).
- Génère des mots de passe **propres à chaque projet** ; ceux du dev local ne
  doivent pas servir en production.
- Désinstallation propre : chaque plugin fournit un `uninstall.php` (le dashboard
  retire son rôle ; le COD efface ses réglages et **conserve la table des
  prospects** par défaut).

---

## 📄 Licence

Plugins maison sous **GPL-2.0-or-later**. Les thèmes et plugins tiers déposés
dans `library/` restent soumis à leurs licences respectives.

—  Développé par [Art Of Doing](https://artofdoing.net)
