<?php
/**
 * Seed catalogue HOMME pour AK Style (COD / DZD) — VRAIES PHOTOS.
 *
 * Images réelles téléchargées depuis loremflickr.com (photos Flickr libres,
 * ciblées par mot-clé, déterministes via ?lock=N). Le conteneur a un DNS
 * public (bloc dns: dans docker-compose.yml) → le téléchargement sort bien.
 *
 * Idempotent : repart d'un catalogue propre à chaque exécution.
 * Lancer : docker compose exec wpcli wp eval-file \
 *          wp-content/themes/boutique-femme-child/inc/seed-homme.php
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }
require_once ABSPATH . 'wp-admin/includes/file.php';
require_once ABSPATH . 'wp-admin/includes/image.php';
require_once ABSPATH . 'wp-admin/includes/media.php';

// Nettoyage produits + catégories (repart propre).
foreach ( get_posts( array( 'post_type' => 'product', 'numberposts' => -1, 'post_status' => 'any', 'fields' => 'ids' ) ) as $pid ) { wp_delete_post( $pid, true ); }
$def = (int) get_option( 'default_product_cat' );
foreach ( get_terms( array( 'taxonomy' => 'product_cat', 'hide_empty' => false, 'fields' => 'ids' ) ) as $tid ) { if ( (int) $tid !== $def ) { wp_delete_term( $tid, 'product_cat' ); } }

/**
 * Télécharge une vraie photo produit (Pexels) et l'attache au produit.
 * Photos sélectionnées une à une (homme portant l'article ou packshot).
 * Réessaie quelques fois : le CDN peut renvoyer un 503 ponctuel.
 *
 * @param int    $pexels ID de la photo Pexels.
 * @param string $slug   Nom de fichier propre (sans extension).
 * @param int    $parent ID du produit parent.
 * @return int  ID de la pièce jointe (0 si échec).
 */
function akh_photo( $pexels, $slug, $parent = 0 ) {
	$url = "https://images.pexels.com/photos/{$pexels}/pexels-photo-{$pexels}.jpeg?auto=compress&cs=tinysrgb&w=1024&h=1280&fit=crop";
	for ( $try = 0; $try < 3; $try++ ) {
		$tmp = download_url( $url, 45 );
		if ( ! is_wp_error( $tmp ) ) {
			$file = array( 'name' => $slug . '.jpg', 'tmp_name' => $tmp );
			$id   = media_handle_sideload( $file, $parent, $slug );
			if ( ! is_wp_error( $id ) ) { return (int) $id; }
			@unlink( $tmp );
		}
		sleep( 2 );
	}
	return 0;
}

// Catégories : les 3 vêtements en tête (plus de produits) → mises en avant sur
// l'accueil ; Accessoires complète la boutique.
$cats = array( 'T-shirts & Polos', 'Pantalons', 'Sweats', 'Accessoires' );
$cat_ids = array();
foreach ( $cats as $name ) {
	$t = wp_insert_term( $name, 'product_cat' );
	$cat_ids[ $name ] = is_wp_error( $t ) ? (int) get_term_by( 'name', $name, 'product_cat' )->term_id : (int) $t['term_id'];
}

// nom, catégorie, prix DZD, prix soldé (0 = pas de promo), ID photo Pexels, slug
$products = array(
	// — T-shirts & Polos —
	array( 'T-shirt col rond — Essentiel',     'T-shirts & Polos', 2200, 1700, 28261878, 'tshirt-essentiel' ),
	array( 'T-shirt col V — Coton',            'T-shirts & Polos', 2500, 0,    30197672, 'tshirt-colv' ),
	array( 'Polo piqué — Riviera',             'T-shirts & Polos', 3500, 0,    7877538,  'polo-riviera' ),
	array( 'T-shirt imprimé — Logo',           'T-shirts & Polos', 3200, 2600, 37075908, 'tshirt-imprime' ),
	// — Pantalons —
	array( 'Jean slim — Brut',                 'Pantalons',        4900, 0,    20451857, 'jean-slim' ),
	array( 'Chino — Beige',                    'Pantalons',        4200, 3500, 9464625,  'chino-beige' ),
	array( 'Pantalon cargo — Utility',         'Pantalons',        4700, 0,    11716437, 'cargo-utility' ),
	array( 'Jogger — Confort',                 'Pantalons',        3800, 0,    11668726, 'jogger-confort' ),
	// — Sweats —
	array( 'Hoodie essentiel — Urbain',        'Sweats',           5500, 0,    19461563, 'hoodie-urbain' ),
	array( 'Sweat col rond — Classique',       'Sweats',           4800, 3900, 7240246,  'sweat-classique' ),
	array( 'Hoodie zippé — Signature',         'Sweats',           6500, 0,    7763190,  'hoodie-zippe' ),
	// — Accessoires —
	array( 'Casquette — City',                 'Accessoires',      1800, 0,    9321573,  'casquette-city' ),
	array( 'Bonnet maille — Nord',             'Accessoires',      1500, 0,    11170599, 'bonnet-nord' ),
	array( 'Ceinture cuir — Méridien',         'Accessoires',      2500, 0,    31959216, 'ceinture-meridien' ),
);

$created = 0; $thumb_done = array(); $img_ok = 0;
foreach ( $products as $i => $p ) {
	list( $name, $cat, $price, $sale, $pexels, $slug ) = $p;
	$product = new WC_Product_Simple();
	$product->set_name( $name );
	$product->set_status( 'publish' );
	$product->set_catalog_visibility( 'visible' );
	// Ordre d'affichage = ordre du tableau (catégories regroupées logiquement).
	$product->set_menu_order( $i );
	$product->set_regular_price( (string) $price );
	if ( $sale ) { $product->set_sale_price( (string) $sale ); }
	$product->set_category_ids( array( $cat_ids[ $cat ] ) );
	$product->set_short_description( 'Pièce essentielle du vestiaire homme — coupe moderne, matière de qualité. Commande sans compte, paiement à la livraison.' );
	$product->set_description( "Un indispensable masculin signé AK Style. Matière agréable, finitions soignées. Livraison partout en Algérie, payée à la réception." );
	$pid = $product->save();

	$aid = akh_photo( $pexels, $slug, $pid );
	if ( $aid ) {
		set_post_thumbnail( $pid, $aid );
		$img_ok++;
		if ( empty( $thumb_done[ $cat ] ) ) { update_term_meta( $cat_ids[ $cat ], 'thumbnail_id', $aid ); $thumb_done[ $cat ] = true; }
	}
	$created++;
}
echo "Catégories: " . count( $cat_ids ) . " | Produits: $created | Photos OK: $img_ok/$created\n";
