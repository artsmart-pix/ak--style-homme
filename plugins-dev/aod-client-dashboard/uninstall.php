<?php
/**
 * Désinstallation propre du plugin AOD Client Dashboard.
 *
 * Exécuté par WordPress uniquement quand l'administrateur SUPPRIME le plugin
 * (pas à la simple désactivation). Retire le rôle « Gestion Boutique » et la
 * capability custom partout où elle a pu être posée, pour laisser le site net.
 *
 * NB : les réglages Pixels/WhatsApp (options « aod_cod_* ») ne sont PAS touchés
 * ici — ils appartiennent au plugin AOD COD Form, qui les possède et les édite.
 * Le dashboard n'en est qu'un éditeur front-end ; les supprimer casserait la
 * config du COD s'il reste actif. Ce nettoyage relève de l'uninstall du COD.
 *
 * @package AOD_Client_Dashboard
 */

// Sécurité : ne s'exécute que dans le contexte de désinstallation WordPress.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// Valeurs en dur : les constantes du plugin (AOD_CD_ROLE / AOD_CD_CAP) ne sont
// pas chargées dans le contexte uninstall. Miroir de aod-client-dashboard.php.
$aod_cd_role = 'aod_shop_client';
$aod_cd_cap  = 'aod_use_dashboard';

/**
 * Nettoie un site : retire la capability custom de tous les rôles, puis le rôle.
 */
$aod_cd_cleanup = static function () use ( $aod_cd_role, $aod_cd_cap ) {
	$roles = wp_roles();

	// 1) Retirer la capability custom de tout rôle qui l'aurait reçue
	//    (ex. administrateur/shop_manager à qui on l'aurait accordée).
	foreach ( $roles->role_objects as $role ) {
		if ( $role->has_cap( $aod_cd_cap ) ) {
			$role->remove_cap( $aod_cd_cap );
		}
	}

	// 2) Supprimer le rôle client lui-même.
	remove_role( $aod_cd_role );
};

// Prise en charge multisite : nettoyer chaque sous-site.
if ( is_multisite() ) {
	$site_ids = get_sites( array( 'fields' => 'ids', 'number' => 0 ) );
	foreach ( $site_ids as $site_id ) {
		switch_to_blog( $site_id );
		$aod_cd_cleanup();
		restore_current_blog();
	}
} else {
	$aod_cd_cleanup();
}
