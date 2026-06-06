# AOD COD Form

Plugin WordPress/WooCommerce **maison** (Art Of Doing) — formulaire de commande
**COD (paiement à la livraison)** pour l'Algérie. Sans licence, dupliquable à l'infini.

## Fonctionnalités

- 🛒 **Commande directe sur la page produit** (sous le bouton d'ajout au panier)
- 💵 **COD** — paiement à la livraison
- 🗺️ **58 wilayas + 1541 communes** (données GPL, cascade wilaya → commune)
- 🚚 **Frais de livraison par wilaya** : Domicile / Stop-desk (page admin dédiée)
- 🧮 **Total en direct** (sous-total + livraison) calculé côté client
- 🛡️ **Validations serveur** : téléphone DZ, commune appartenant à la wilaya, prix produit recalculé côté serveur (anti-triche), nonce CSRF
- 🌐 **RTL / traduisible** (text domain `aod-cod-form`)
- 🧾 **Métadonnées commande** : wilaya, commune, type de livraison (pour la préparation/expédition)

## Utilisation

- **Automatique** : s'affiche sur chaque page produit (hook `woocommerce_single_product_summary`, priorité 35).
- **Shortcode** : `[aod_cod_form product_id="123"]` (ou `[aod_cod_form]` sur une page produit).
- **Tarifs livraison** : `WooCommerce → Livraison COD` → saisir Domicile/Stop-desk par wilaya.

## Structure

```
aod-cod-form/
├── aod-cod-form.php              # bootstrap (hooks, dépendance WooCommerce)
├── uninstall.php                 # suppression du plugin : efface les réglages (garde la table prospects par défaut)
├── includes/
│   ├── class-aod-cod-data.php    # wilayas/communes + tarifs
│   ├── class-aod-cod-form.php    # rendu formulaire + AJAX commande
│   ├── class-aod-cod-admin.php   # page tarifs de livraison
│   └── data/dz-places.json       # 58 wilayas / 1541 communes
└── assets/
    ├── css/aod-cod-form.css      # styles (RTL-aware)
    └── js/aod-cod-form.js        # cascade + total live + AJAX
```

## Développement local

Le dossier est monté en direct dans le conteneur WordPress
(`./plugins-dev/aod-cod-form` → `/var/www/html/wp-content/plugins/aod-cod-form`),
donc **toute édition depuis le PC est live** (rafraîchir la page).

## Roadmap (idées d'évolution)

- [ ] Champ wilaya/commune en arabe (le JSON contient les noms latins ; ajouter colonne AR)
- [ ] Livraison gratuite à partir d'un montant
- [ ] Export des commandes vers livreurs (Yalidine/Noest/Ecotrack) — API
- [ ] Notification WhatsApp à la commande
- [ ] Suivi des paniers/commandes abandonnés
- [ ] Bloc Gutenberg / widget Elementor
```
