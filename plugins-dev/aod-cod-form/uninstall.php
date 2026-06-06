<?php
/**
 * Désinstallation propre du plugin AOD COD Form.
 *
 * Exécuté par WordPress UNIQUEMENT à la suppression du plugin (bouton
 * « Supprimer » dans Extensions, après désactivation) — JAMAIS à la simple
 * désactivation ni pendant l'usage normal du site.
 *
 * Mode par défaut = PRUDENT : retire les réglages et les caches, mais CONSERVE
 * la table des prospects « {prefix}aod_cod_leads » (donnée commerciale : paniers
 * abandonnés). Pour une suppression TOTALE (table comprise), passer la variable
 * $aod_cod_drop_leads ci-dessous à true.
 *
 * @package AOD_COD_Form
 */

// Sécurité : ne s'exécute que dans le contexte de désinstallation WordPress.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

/**
 * true  = supprimer AUSSI la table des prospects (table rase, irréversible).
 * false = conserver la table des prospects (recommandé par défaut).
 */
$aod_cod_drop_leads = false;

$aod_cod_cleanup = static function () use ( $aod_cod_drop_leads ) {
	global $wpdb;

	// 1) Options de réglage possédées par le plugin.
	$options = array(
		'aod_cod_pixels',          // pixels & tracking
		'aod_cod_whatsapp',        // notification WhatsApp (CallMeBot)
		'aod_cod_delivery_prices', // tarifs de livraison par wilaya
		'aod_cod_free_shipping',   // seuil de livraison gratuite
		'aod_shipping_auto',       // envoi automatique aux transporteurs
	);
	foreach ( $options as $opt ) {
		delete_option( $opt );
	}

	// 2) Réglages des transporteurs : clés dynamiques « aod_carrier_{id} »
	//    (yalidine, noest, ecotrack… — supprimées par motif, sans énumérer).
	$like_carrier = $wpdb->esc_like( 'aod_carrier_' ) . '%';
	$wpdb->query(
		$wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $like_carrier )
	);

	// 3) Caches transitoires du plugin (ex. centres Yalidine par wilaya).
	//    Pur cache régénérable → suppression sûre.
	$like_tr  = $wpdb->esc_like( '_transient_aod_' ) . '%';
	$like_trt = $wpdb->esc_like( '_transient_timeout_aod_' ) . '%';
	$wpdb->query(
		$wpdb->prepare(
			"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
			$like_tr,
			$like_trt
		)
	);

	// 4) Table des prospects + marqueur de version de schéma.
	if ( $aod_cod_drop_leads ) {
		$table = $wpdb->prefix . 'aod_cod_leads';
		$wpdb->query( "DROP TABLE IF EXISTS {$table}" );
		delete_option( 'aod_cod_leads_db_version' );
	}
	// Sinon (mode prudent) : on conserve « {prefix}aod_cod_leads » et son option
	// de version, pour préserver les prospects et permettre une réinstallation
	// transparente (le schéma sera reconnu tel quel).
};

// Prise en charge multisite : nettoyer chaque sous-site.
if ( is_multisite() ) {
	$site_ids = get_sites( array( 'fields' => 'ids', 'number' => 0 ) );
	foreach ( $site_ids as $site_id ) {
		switch_to_blog( $site_id );
		$aod_cod_cleanup();
		restore_current_blog();
	}
} else {
	$aod_cod_cleanup();
}
