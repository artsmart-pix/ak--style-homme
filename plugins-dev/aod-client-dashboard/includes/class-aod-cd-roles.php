<?php
/**
 * Rôle « Gestion Boutique » (client) : capacités restreintes.
 *
 * La sécurité vient des capabilities, pas du masquage de l'UI : ce rôle ne
 * possède AUCUNE capacité pouvant casser le site (plugins, thèmes, réglages,
 * utilisateurs, mise à jour du cœur). Il peut uniquement gérer commandes,
 * produits et catégories, plus la capacité custom d'ouvrir le dashboard.
 *
 * @package AOD_Client_Dashboard
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AOD_CD_Roles {

	/** @var AOD_CD_Roles|null */
	protected static $instance = null;

	/**
	 * Capacités EXPLICITEMENT interdites au gérant client, même si shop_manager
	 * les possède. C'est la liste qui garantit qu'il ne peut pas casser le site.
	 *
	 * @var string[]
	 */
	const FORBIDDEN = array(
		// Utilisateurs (sinon il pourrait changer le mot de passe admin).
		'edit_users', 'create_users', 'delete_users', 'remove_users', 'add_users',
		'list_users', 'promote_users', 'edit_user',
		// Extensions / thèmes / éditeur de fichiers.
		'install_plugins', 'activate_plugins', 'update_plugins', 'delete_plugins', 'edit_plugins',
		'install_themes', 'switch_themes', 'update_themes', 'delete_themes', 'edit_themes', 'edit_theme_options',
		'edit_files',
		// Réglages du site & cœur.
		'manage_options', 'update_core', 'export', 'import',
		'customize', 'edit_dashboard',
	);

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		// Réaligne les capacités si le rôle existe mais a dérivé (ex. mise à jour WC).
		add_action( 'init', array( $this, 'maybe_sync_role' ), 20 );
	}

	/**
	 * Construit la table des capacités du rôle client.
	 *
	 * Base : les capacités de shop_manager (compatibilité native WooCommerce
	 * pour commandes/produits), MOINS la liste FORBIDDEN, PLUS la capacité du
	 * dashboard et le strict nécessaire en lecture/upload.
	 *
	 * @return array<string,bool>
	 */
	public static function build_caps() {
		$caps = array();

		$sm = get_role( 'shop_manager' );
		if ( $sm && ! empty( $sm->capabilities ) ) {
			$caps = $sm->capabilities;
		} else {
			// Repli si shop_manager absent : liste explicite minimale e-commerce.
			$caps = array_fill_keys( array(
				'read', 'upload_files',
				'manage_woocommerce', 'view_woocommerce_reports',
				'edit_products', 'edit_others_products', 'publish_products', 'read_private_products',
				'delete_products', 'delete_others_products', 'edit_published_products', 'delete_published_products',
				'manage_product_terms', 'edit_product_terms', 'delete_product_terms', 'assign_product_terms',
				'edit_shop_orders', 'edit_others_shop_orders', 'publish_shop_orders', 'read_private_shop_orders',
				'delete_shop_orders', 'edit_published_shop_orders', 'read_shop_order', 'edit_shop_order',
			), true );
		}

		// Retire toute capacité dangereuse.
		foreach ( self::FORBIDDEN as $bad ) {
			unset( $caps[ $bad ] );
		}

		// Garde-fous indispensables.
		$caps['read']         = true;
		$caps['upload_files'] = true;        // photos produits
		$caps[ AOD_CD_CAP ]   = true;        // ouvre /gestion

		return $caps;
	}

	/**
	 * Crée (ou recrée) le rôle. Appelé à l'activation.
	 */
	public static function create_role() {
		$caps = self::build_caps();
		// remove_role puis add_role = recréation propre et idempotente.
		remove_role( AOD_CD_ROLE );
		add_role( AOD_CD_ROLE, __( 'Gestion Boutique', 'aod-client-dashboard' ), $caps );
	}

	/**
	 * Si le rôle existe, s'assure qu'il n'a pas gagné de capacité interdite
	 * (par ex. après une mise à jour de WooCommerce qui recopie shop_manager).
	 */
	public function maybe_sync_role() {
		$role = get_role( AOD_CD_ROLE );
		if ( ! $role ) {
			return;
		}
		foreach ( self::FORBIDDEN as $bad ) {
			if ( isset( $role->capabilities[ $bad ] ) ) {
				$role->remove_cap( $bad );
			}
		}
		if ( empty( $role->capabilities[ AOD_CD_CAP ] ) ) {
			$role->add_cap( AOD_CD_CAP );
		}
	}
}
