# Environnement local — Master Boutique Algérie 🇩🇿

Stack WordPress + WooCommerce sous Docker, prête à travailler.

## 🔗 Accès

| Service | URL | Identifiants |
|---|---|---|
| **Boutique** | http://localhost:8090 | — |
| **Admin WordPress** | http://localhost:8090/wp-admin | `admin` / _(voir `.env` → `WP_ADMIN_PASSWORD`)_ |
| **phpMyAdmin** | http://localhost:8091 | `wp_user` / (voir `.env`) — serveur : `db` |

> ⚠️ Mots de passe de **dev local** (dans `.env`). À changer avant toute mise en production.

## 📦 Ce qui est installé

- **WordPress** + **WooCommerce 10.8.1** (actif) — devise **DZD**, pays **Algérie**, **COD activé**
- **Thèmes** (6) : Astra *(actif)*, Botiga, Kadence, Neve, OceanWP, Storefront
- **Plugins** (inactifs, à configurer) : Chargily ePay (CIB/Edahabia), Polylang (FR/AR)

## 🗂️ Structure

```
.
├── docker-compose.yml   # db (MariaDB) + wordpress + wpcli + phpmyadmin
├── .env                 # mots de passe & ports
├── wordpress/           # le site — ÉDITABLE depuis ton PC (volume monté)
├── library/             # zips des thèmes/plugins (source réutilisable)
├── scripts/setup.sh     # ré-installe/configure tout (idempotent)
└── CHECKLIST-SITE-MASTER-ALGERIE.md
```

## ⚙️ Commandes utiles

```bash
cd "/home/yanis/Bureau/ARTOFDOING/Wordpress/Templates-E-commerce"

# Démarrer / arrêter
docker compose up -d
docker compose stop
docker compose down              # stop + supprime conteneurs (garde les volumes/données)

# Logs
docker compose logs -f wordpress

# WP-CLI (ligne de commande WordPress)
docker compose exec wpcli wp plugin list
docker compose exec wpcli wp theme activate botiga
docker compose exec wpcli wp option get blogname
```

## 🔑 Droits d'écriture (à faire une fois)

Les fichiers sont créés par Apache (uid 33). Pour les éditer depuis ton PC :

```bash
sudo setfacl -R    -m u:1000:rwX wordpress
sudo setfacl -R -d -m u:1000:rwX wordpress
```

## ⏭️ Étapes suivantes (cf. CHECKLIST)

1. Choisir le thème définitif (`wp theme activate <nom>`)
2. Activer + configurer **Chargily** (clés API) et **Polylang** (FR/AR + RTL)
3. Configurer la **livraison** par wilayas (58)
4. Adapter le **checkout** (tél obligatoire, email facultatif)
5. Exporter le site comme **template réutilisable** (Duplicator)
