#!/bin/bash
# ───────────────────────────────────────────────────────────────
# Installe et configure WordPress + WooCommerce pour le master DZ.
# S'exécute DANS le conteneur wpcli :
#   docker compose exec -e ... wpcli bash /scripts/setup.sh
# Idempotent : on peut le relancer sans tout casser.
# ───────────────────────────────────────────────────────────────
set -e
cd /var/www/html

echo "==> 1. Installation du coeur WordPress"
if ! wp core is-installed 2>/dev/null; then
  wp core install \
    --url="${WP_URL}" \
    --title="${WP_TITLE}" \
    --admin_user="${WP_ADMIN_USER}" \
    --admin_password="${WP_ADMIN_PASSWORD}" \
    --admin_email="${WP_ADMIN_EMAIL}" \
    --skip-email
else
  echo "    WordPress déjà installé, on continue."
fi

echo "==> 2. Réglages de base (Algérie)"
wp option update timezone_string "Africa/Algiers"
wp option update blogdescription "Boutique en ligne"
wp rewrite structure '/%postname%/' --hard
wp option update date_format 'd/m/Y'

echo "==> 3. Thèmes (depuis /library/themes)"
for z in /library/themes/*.zip; do
  [ -e "$z" ] || continue
  echo "    -> $z"
  wp theme install "$z" --force || echo "    (échec $z, on continue)"
done

echo "==> 4. Plugins (depuis /library/plugins)"
for z in /library/plugins/*.zip; do
  [ -e "$z" ] || continue
  echo "    -> $z"
  wp plugin install "$z" --force || echo "    (échec $z, on continue)"
done

echo "==> 5. Activation WooCommerce + thème Astra"
wp plugin activate woocommerce 2>/dev/null || echo "    woocommerce: vérifier le slug"
wp theme activate astra 2>/dev/null || echo "    astra non activé (choix plus tard)"

echo "==> 6. Config WooCommerce (DZD / Algérie)"
wp option update woocommerce_currency "DZD" || true
wp option update woocommerce_default_country "DZ" || true
wp option update woocommerce_currency_pos "right_space" || true
wp option update woocommerce_price_num_decimals "2" || true
wp option update woocommerce_weight_unit "kg" || true
wp option update woocommerce_dimension_unit "cm" || true
# Activer le paiement à la livraison (COD)
wp option update woocommerce_cod_settings '{"enabled":"yes","title":"Paiement à la livraison","description":"Payez en espèces à la réception."}' --format=json || true

echo "==> 7. État final"
echo "--- Thèmes ---";  wp theme list  --fields=name,status,version 2>/dev/null || true
echo "--- Plugins ---"; wp plugin list --fields=name,status,version 2>/dev/null || true

echo ""
echo "✅ TERMINÉ. Site : ${WP_URL}  | Admin : ${WP_URL}/wp-admin"
