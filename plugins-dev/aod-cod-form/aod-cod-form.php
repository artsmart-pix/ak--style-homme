<?php
/**
 * Plugin Name:       AOD COD Form
 * Plugin URI:        https://artofdoing.net
 * Description:       Formulaire de commande COD (paiement à la livraison) pour l'Algérie : 58 wilayas + 1541 communes, frais de livraison par wilaya, commande directe sur la page produit. RTL/FR/AR.
 * Version:           1.13.0
 * Author:            Art Of Doing
 * Author URI:        https://artofdoing.net
 * Text Domain:       aod-cod-form
 * Domain Path:       /languages
 * Requires Plugins:  woocommerce
 * License:           GPL-2.0-or-later
 *
 * @package AOD_COD_Form
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Accès direct interdit.
}

define( 'AOD_COD_VERSION', '1.13.0' );
define( 'AOD_COD_FILE', __FILE__ );
define( 'AOD_COD_PATH', plugin_dir_path( __FILE__ ) );
define( 'AOD_COD_URL', plugin_dir_url( __FILE__ ) );

/**
 * Bloque l'activation si WooCommerce n'est pas présent.
 */
register_activation_hook( __FILE__, function () {
	if ( ! class_exists( 'WooCommerce' ) ) {
		deactivate_plugins( plugin_basename( __FILE__ ) );
		wp_die(
			esc_html__( 'AOD COD Form nécessite WooCommerce actif.', 'aod-cod-form' ),
			esc_html__( 'Dépendance manquante', 'aod-cod-form' ),
			array( 'back_link' => true )
		);
	}

	// Crée la table des paniers abandonnés (prospects).
	require_once AOD_COD_PATH . 'includes/class-aod-cod-leads.php';
	AOD_COD_Leads::maybe_migrate();
} );

/**
 * Démarre le plugin une fois tous les plugins chargés
 * (garantit que WooCommerce est disponible).
 */
add_action( 'plugins_loaded', function () {
	if ( ! class_exists( 'WooCommerce' ) ) {
		add_action( 'admin_notices', function () {
			echo '<div class="notice notice-error"><p>'
				. esc_html__( 'AOD COD Form est inactif : WooCommerce est requis.', 'aod-cod-form' )
				. '</p></div>';
		} );
		return;
	}

	// Sélecteur de langue visiteur : branché AVANT le chargement des traductions
	// pour que le filtre de locale s'applique à toutes les chaînes.
	require_once AOD_COD_PATH . 'includes/class-aod-cod-lang.php';
	AOD_COD_Lang::init();

	load_plugin_textdomain( 'aod-cod-form', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );

	require_once AOD_COD_PATH . 'includes/class-aod-cod-data.php';
	require_once AOD_COD_PATH . 'includes/class-aod-cod-form.php';
	require_once AOD_COD_PATH . 'includes/class-aod-cod-admin.php';
	require_once AOD_COD_PATH . 'includes/class-aod-cod-whatsapp.php';
	require_once AOD_COD_PATH . 'includes/class-aod-cod-leads.php';
	require_once AOD_COD_PATH . 'includes/class-aod-cod-block.php';
	require_once AOD_COD_PATH . 'includes/class-aod-cod-pixels.php';
	require_once AOD_COD_PATH . 'includes/class-aod-shipping.php';

	AOD_COD_Form::instance();
	// Notif WhatsApp : doit écouter aussi en front (commande créée via AJAX).
	AOD_COD_WhatsApp::instance();
	// Paniers abandonnés : capture AJAX en front + migration de la table.
	AOD_COD_Leads::instance();
	// Bloc Gutenberg (dynamique) + widget Elementor : enregistrement front & éditeur.
	AOD_COD_Block::instance();
	// Pixels publicitaires (Meta/TikTok/Snapchat/Google) : <head> + conversion en front.
	AOD_COD_Pixels::instance();
	// Expédition : instanciée partout (statut « Confirmée » + envoi auto valides hors admin).
	AOD_Shipping::instance();
	if ( is_admin() ) {
		AOD_COD_Admin::instance();
	}
} );

/**
 * Déclare la compatibilité avec le stockage des commandes haute performance (HPOS).
 */
add_action( 'before_woocommerce_init', function () {
	if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
	}
} );
