<?php
/**
 * Plugin Name:       AOD Client Dashboard
 * Plugin URI:        https://artofdoing.net
 * Description:       Espace de gestion front-end pour le client de la boutique : commandes, produits, livraison, stats, pixels, WhatsApp — SANS accès à wp-admin. Rôle restreint + interface marquée au thème.
 * Version:           0.12.0
 * Author:            Art Of Doing
 * Author URI:        https://artofdoing.net
 * Text Domain:       aod-client-dashboard
 * Domain Path:       /languages
 * Requires Plugins:  woocommerce
 * License:           GPL-2.0-or-later
 *
 * @package AOD_Client_Dashboard
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Accès direct interdit.
}

define( 'AOD_CD_VERSION', '0.12.0' );
define( 'AOD_CD_FILE', __FILE__ );
define( 'AOD_CD_PATH', plugin_dir_path( __FILE__ ) );
define( 'AOD_CD_URL', plugin_dir_url( __FILE__ ) );

/** Rôle du gérant de boutique côté client. */
define( 'AOD_CD_ROLE', 'aod_shop_client' );
/** Capability custom qui ouvre l'accès au dashboard /gestion. */
define( 'AOD_CD_CAP', 'aod_use_dashboard' );
/** Slug de la page front-end de gestion. */
define( 'AOD_CD_SLUG', 'gestion' );

require_once AOD_CD_PATH . 'includes/class-aod-cd-roles.php';

/**
 * Activation : crée le rôle restreint et pose les règles de réécriture /gestion.
 */
register_activation_hook( __FILE__, function () {
	AOD_CD_Roles::create_role();
	// La règle de réécriture est ajoutée sur 'init' ; on force un flush ici.
	require_once AOD_CD_PATH . 'includes/class-aod-cd-dashboard.php';
	AOD_CD_Dashboard::add_rewrite();
	flush_rewrite_rules();
} );

/**
 * Désactivation : retire la règle de réécriture (on conserve le rôle et ses users).
 */
register_deactivation_hook( __FILE__, function () {
	flush_rewrite_rules();
} );

/**
 * Démarrage.
 */
add_action( 'plugins_loaded', function () {
	if ( ! class_exists( 'WooCommerce' ) ) {
		add_action( 'admin_notices', function () {
			echo '<div class="notice notice-error"><p>'
				. esc_html__( 'AOD Client Dashboard est inactif : WooCommerce est requis.', 'aod-client-dashboard' )
				. '</p></div>';
		} );
		return;
	}

	load_plugin_textdomain( 'aod-client-dashboard', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );

	// Dépendance forte (non bloquante) : AOD COD Form fournit le statut
	// « Confirmée », les tarifs par wilaya et les transporteurs. Sans lui, ces
	// parties de l'espace de gestion se désactivent proprement — on prévient
	// l'administrateur pour qu'il sache pourquoi elles manquent.
	if ( ! defined( 'AOD_COD_VERSION' ) ) {
		add_action( 'admin_notices', function () {
			if ( ! current_user_can( 'activate_plugins' ) ) {
				return;
			}
			echo '<div class="notice notice-warning"><p>'
				. esc_html__( 'AOD Client Dashboard : le plugin « AOD COD Form » est recommandé. Sans lui, les commandes « Confirmées », les tarifs de livraison par wilaya et l’envoi automatique aux transporteurs ne sont pas disponibles dans l’espace de gestion.', 'aod-client-dashboard' )
				. '</p></div>';
		} );
	}

	require_once AOD_CD_PATH . 'includes/class-aod-cd-roles.php';
	require_once AOD_CD_PATH . 'includes/class-aod-cd-access.php';
	require_once AOD_CD_PATH . 'includes/class-aod-cd-dashboard.php';
	require_once AOD_CD_PATH . 'includes/class-aod-cd-account.php';

	AOD_CD_Roles::instance();
	AOD_CD_Access::instance();
	AOD_CD_Dashboard::instance();
	AOD_CD_Account::instance();
} );

/**
 * Compatibilité HPOS (stockage des commandes haute performance).
 */
add_action( 'before_woocommerce_init', function () {
	if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
	}
} );
