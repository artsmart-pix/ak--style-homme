<?php
/**
 * Dashboard front-end /gestion : coque d'application autonome + routeur.
 *
 * Prend totalement la main sur le rendu de la page /gestion (n'utilise pas le
 * template du thème) pour offrir une interface marquée et plein écran. Chaque
 * section est rendue par une méthode dédiée ; les actions passent par AJAX.
 *
 * @package AOD_Client_Dashboard
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AOD_CD_Dashboard {

	/** @var AOD_CD_Dashboard|null */
	protected static $instance = null;

	/** Sections du menu : slug => [ label, dashicon ]. */
	protected $sections = array();

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		$this->sections = array(
			'orders'     => array( __( 'Commandes', 'aod-client-dashboard' ), '🧾' ),
			'products'   => array( __( 'Produits', 'aod-client-dashboard' ), '📦' ),
			'categories' => array( __( 'Catégories', 'aod-client-dashboard' ), '🏷️' ),
			'shipping'   => array( __( 'Livraison', 'aod-client-dashboard' ), '🚚' ),
			'stats'      => array( __( 'Statistiques', 'aod-client-dashboard' ), '📊' ),
			'marketing'  => array( __( 'Pixels & Tracking', 'aod-client-dashboard' ), '🎯' ),
			'whatsapp'   => array( __( 'WhatsApp', 'aod-client-dashboard' ), '💬' ),
			'account'    => array( __( 'Mon compte', 'aod-client-dashboard' ), '👤' ),
		);

		add_action( 'init', array( __CLASS__, 'add_rewrite' ) );
		add_filter( 'query_vars', array( $this, 'query_vars' ) );
		add_action( 'template_redirect', array( $this, 'maybe_render' ) );

		// Actions AJAX du dashboard.
		add_action( 'wp_ajax_aod_cd_order_status', array( $this, 'ajax_order_status' ) );
		add_action( 'wp_ajax_aod_cd_order_detail', array( $this, 'ajax_order_detail' ) );
		add_action( 'wp_ajax_aod_cd_order_note', array( $this, 'ajax_order_note' ) );
		add_action( 'wp_ajax_aod_cd_order_save_info', array( $this, 'ajax_order_save_info' ) );
		add_action( 'wp_ajax_aod_cd_save_product', array( $this, 'ajax_save_product' ) );
		add_action( 'wp_ajax_aod_cd_delete_product', array( $this, 'ajax_delete_product' ) );
		add_action( 'wp_ajax_aod_cd_save_category', array( $this, 'ajax_save_category' ) );
		add_action( 'wp_ajax_aod_cd_delete_category', array( $this, 'ajax_delete_category' ) );
		add_action( 'wp_ajax_aod_cd_save_shipping', array( $this, 'ajax_save_shipping' ) );
		add_action( 'wp_ajax_aod_cd_save_pixels', array( $this, 'ajax_save_pixels' ) );
		add_action( 'wp_ajax_aod_cd_save_whatsapp', array( $this, 'ajax_save_whatsapp' ) );
		add_action( 'wp_ajax_aod_cd_test_whatsapp', array( $this, 'ajax_test_whatsapp' ) );
		add_action( 'wp_ajax_aod_cd_save_account', array( $this, 'ajax_save_account' ) );

		// Pack assortiment : décrémente le stock des composants quand la commande est prise.
		add_action( 'woocommerce_order_status_processing', array( $this, 'reduce_pack_components_stock' ) );
		add_action( 'woocommerce_order_status_completed', array( $this, 'reduce_pack_components_stock' ) );
	}

	/**
	 * Réduit le stock des produits composant un pack présent dans une commande.
	 *
	 * WooCommerce ne réduit que le stock du produit-pack lui-même ; ce hook répercute
	 * la baisse sur chaque produit inclus (quantité du pack × quantité commandée).
	 * Un drapeau sur la commande empêche tout double décompte.
	 *
	 * @param int $order_id
	 */
	public function reduce_pack_components_stock( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order || 'yes' === $order->get_meta( '_aod_pack_stock_done' ) ) {
			return;
		}
		$touched = false;
		foreach ( $order->get_items() as $item ) {
			$product = $item->get_product();
			if ( ! $product || '1' !== (string) $product->get_meta( '_aod_is_pack' ) ) {
				continue;
			}
			$components = $product->get_meta( '_aod_pack_items' );
			if ( ! is_array( $components ) || ! $components ) {
				continue;
			}
			$order_qty = (int) $item->get_quantity();
			foreach ( $components as $comp ) {
				$cid = isset( $comp['id'] ) ? (int) $comp['id'] : 0;
				$cq  = isset( $comp['qty'] ) ? (int) $comp['qty'] : 0;
				$cp  = $cid ? wc_get_product( $cid ) : null;
				if ( ! $cp || ! $cp->managing_stock() || $cq < 1 ) {
					continue;
				}
				wc_update_product_stock( $cp, $cq * max( 1, $order_qty ), 'decrease' );
				$touched = true;
			}
		}
		if ( $touched ) {
			$order->update_meta_data( '_aod_pack_stock_done', 'yes' );
			$order->save();
		}
	}

	/**
	 * Enregistre la règle de réécriture /gestion (et /gestion/<section>).
	 */
	public static function add_rewrite() {
		add_rewrite_rule(
			'^' . AOD_CD_SLUG . '(?:/([^/]+))?/?$',
			'index.php?aod_cd=1&aod_cd_section=$matches[1]',
			'top'
		);
	}

	public function query_vars( $vars ) {
		$vars[] = 'aod_cd';
		$vars[] = 'aod_cd_section';
		return $vars;
	}

	/* ============================================================
	 * Rendu de la page
	 * ========================================================== */

	/**
	 * Intercepte /gestion : vérifie l'accès puis rend l'application.
	 */
	public function maybe_render() {
		if ( ! get_query_var( 'aod_cd' ) ) {
			return;
		}

		// Non connecté → page de connexion, retour sur /gestion ensuite.
		if ( ! is_user_logged_in() ) {
			$back = home_url( '/' . AOD_CD_SLUG . '/' );
			wp_safe_redirect( wp_login_url( $back ) );
			exit;
		}

		// Connecté mais sans la capacité → refus.
		if ( ! current_user_can( AOD_CD_CAP ) && ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die(
				esc_html__( "Vous n'avez pas accès à l'espace de gestion.", 'aod-client-dashboard' ),
				esc_html__( 'Accès refusé', 'aod-client-dashboard' ),
				array( 'response' => 403 )
			);
		}

		$section = sanitize_key( get_query_var( 'aod_cd_section' ) );
		if ( ! $section || ! isset( $this->sections[ $section ] ) ) {
			$section = 'orders';
		}

		$this->render_app( $section );
		exit;
	}

	/**
	 * Document HTML complet de l'application.
	 *
	 * @param string $section
	 */
	protected function render_app( $section ) {
		$user      = wp_get_current_user();
		$base       = home_url( '/' . AOD_CD_SLUG . '/' );
		$is_rtl     = function_exists( 'is_rtl' ) && is_rtl();
		nocache_headers();
		?>
<!DOCTYPE html>
<html <?php echo $is_rtl ? 'dir="rtl"' : ''; ?> lang="<?php echo esc_attr( get_bloginfo( 'language' ) ); ?>">
<head>
	<meta charset="<?php echo esc_attr( get_bloginfo( 'charset' ) ); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<meta name="robots" content="noindex,nofollow">
	<title><?php echo esc_html( get_bloginfo( 'name' ) . ' — ' . __( 'Gestion', 'aod-client-dashboard' ) ); ?></title>
	<link rel="preconnect" href="https://fonts.googleapis.com">
	<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
	<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Barlow:wght@400;500;600;700;800&display=swap">
	<link rel="stylesheet" href="<?php echo esc_url( AOD_CD_URL . 'assets/css/dashboard.css?v=' . $this->asset_ver( 'assets/css/dashboard.css' ) ); ?>">
	<script>
		window.AOD_CD = {
			ajaxUrl: <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>,
			nonce:   <?php echo wp_json_encode( wp_create_nonce( 'aod_cd' ) ); ?>,
			base:    <?php echo wp_json_encode( $base ); ?>,
			i18nNotePrompt: <?php echo wp_json_encode( __( 'Note pour cette commande :', 'aod-client-dashboard' ) ); ?>,
			i18nCatRename:  <?php echo wp_json_encode( __( 'Renommer', 'aod-client-dashboard' ) ); ?>,
			i18nCatDelete:  <?php echo wp_json_encode( __( 'Supprimer', 'aod-client-dashboard' ) ); ?>,
			i18nCatDelConfirm: <?php echo wp_json_encode( __( 'Supprimer cette catégorie ? Les produits concernés perdront ce classement.', 'aod-client-dashboard' ) ); ?>,
			i18nCatZero:    <?php echo wp_json_encode( __( '0 produit', 'aod-client-dashboard' ) ); ?>
		};
	</script>
</head>
<body class="aod-cd">
	<aside class="aod-cd-side">
		<div class="aod-cd-brand"><?php echo esc_html( get_bloginfo( 'name' ) ); ?></div>
		<nav class="aod-cd-nav">
			<?php foreach ( $this->sections as $slug => $meta ) :
				$active = ( $slug === $section ) ? ' is-active' : '';
				?>
				<a class="aod-cd-navlink<?php echo esc_attr( $active ); ?>" href="<?php echo esc_url( $base . $slug ); ?>">
					<span class="aod-cd-ico"><?php echo esc_html( $meta[1] ); ?></span>
					<span><?php echo esc_html( $meta[0] ); ?></span>
				</a>
			<?php endforeach; ?>
		</nav>
		<div class="aod-cd-side-foot">
			<a class="aod-cd-view" href="<?php echo esc_url( home_url( '/' ) ); ?>" target="_blank" rel="noopener">🌐 <?php esc_html_e( 'Voir la boutique', 'aod-client-dashboard' ); ?></a>
			<a class="aod-cd-logout" href="<?php echo esc_url( wp_logout_url( $base ) ); ?>"><?php esc_html_e( 'Déconnexion', 'aod-client-dashboard' ); ?></a>
		</div>
	</aside>

	<main class="aod-cd-main">
		<header class="aod-cd-top">
			<h1 class="aod-cd-h1"><?php echo esc_html( $this->sections[ $section ][0] ); ?></h1>
			<div class="aod-cd-user"><?php echo esc_html( $user->display_name ); ?></div>
		</header>
		<div class="aod-cd-content">
			<?php $this->render_section( $section ); ?>
		</div>
	</main>

	<div class="aod-cd-modal" id="aod-cd-modal" hidden>
		<div class="aod-cd-modal-backdrop" data-close></div>
		<div class="aod-cd-modal-box" role="dialog" aria-modal="true" aria-labelledby="aod-cd-modal-title">
			<header class="aod-cd-modal-head">
				<h2 class="aod-cd-modal-title" id="aod-cd-modal-title"></h2>
				<button type="button" class="aod-cd-modal-x" data-close aria-label="<?php esc_attr_e( 'Fermer', 'aod-client-dashboard' ); ?>">&times;</button>
			</header>
			<div class="aod-cd-modal-body"></div>
		</div>
	</div>

	<script src="<?php echo esc_url( AOD_CD_URL . 'assets/js/dashboard.js?v=' . $this->asset_ver( 'assets/js/dashboard.js' ) ); ?>"></script>
</body>
</html>
		<?php
	}

	/**
	 * Aiguille vers la section demandée.
	 *
	 * @param string $section
	 */
	protected function render_section( $section ) {
		switch ( $section ) {
			case 'orders':
				$this->section_orders();
				break;
			case 'stats':
				$this->section_stats();
				break;
			case 'products':
				$this->section_products();
				break;
			case 'categories':
				$this->render_categories_page();
				break;
			case 'shipping':
				$this->section_shipping();
				break;
			case 'marketing':
				$this->section_marketing();
				break;
			case 'whatsapp':
				$this->section_whatsapp();
				break;
			case 'account':
				$this->section_account();
				break;
			default:
				$this->section_soon( $section );
		}
	}

	/* ============================================================
	 * Section : Commandes
	 * ========================================================== */

	protected function section_orders() {
		$current = isset( $_GET['statut'] ) ? sanitize_key( wp_unslash( $_GET['statut'] ) ) : 'all';

		// Onglets de filtrage par statut (avec compteurs).
		$tabs = array(
			'all'          => __( 'Toutes', 'aod-client-dashboard' ),
			'pending'      => __( 'En attente', 'aod-client-dashboard' ),
			'aod-confirmed'=> __( 'Confirmées', 'aod-client-dashboard' ),
			'completed'    => __( 'Terminées', 'aod-client-dashboard' ),
			'cancelled'    => __( 'Annulées', 'aod-client-dashboard' ),
		);

		echo '<div class="aod-cd-tabs">';
		foreach ( $tabs as $slug => $label ) {
			$count  = $this->count_orders( $slug );
			$active = ( $slug === $current ) ? ' is-active' : '';
			$url    = add_query_arg( 'statut', $slug, home_url( '/' . AOD_CD_SLUG . '/orders' ) );
			printf(
				'<a class="aod-cd-tab%s" href="%s">%s <span class="aod-cd-badge">%d</span></a>',
				esc_attr( $active ), esc_url( $url ), esc_html( $label ), (int) $count
			);
		}
		echo '</div>';

		$query_status = ( 'all' === $current )
			? array_keys( wc_get_order_statuses() )
			: array( 'wc-' . $current );

		$search   = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
		$paged    = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;
		$per_page = 20;
		$base_url = home_url( '/' . AOD_CD_SLUG . '/orders' );

		// Recherche (n° de commande, nom ou téléphone du client).
		$this->render_search_bar( $base_url, $search, __( 'Rechercher : n°, nom, téléphone…', 'aod-client-dashboard' ), array( 'statut' => $current ) );

		$args = array(
			'limit'    => $per_page,
			'paged'    => $paged,
			'paginate' => true,
			'orderby'  => 'date',
			'order'    => 'DESC',
			'status'   => $query_status,
		);
		if ( '' !== $search ) {
			$args['s'] = $search;
		}
		$result = wc_get_orders( $args );
		$orders = $result->orders;

		if ( empty( $orders ) ) {
			echo '<p class="aod-cd-empty">' . esc_html__( 'Aucune commande dans cette catégorie.', 'aod-client-dashboard' ) . '</p>';
			return;
		}

		$status_options = $this->status_labels(); // Libellés de statut forcés en français.

		echo '<div class="aod-cd-tablewrap"><table class="aod-cd-table"><thead><tr>';
		foreach ( array(
			__( 'N°', 'aod-client-dashboard' ),
			__( 'Date', 'aod-client-dashboard' ),
			__( 'Client', 'aod-client-dashboard' ),
			__( 'Téléphone', 'aod-client-dashboard' ),
			__( 'Wilaya', 'aod-client-dashboard' ),
			__( 'Commune', 'aod-client-dashboard' ),
			__( 'Articles', 'aod-client-dashboard' ),
			__( 'Prix produit', 'aod-client-dashboard' ),
			__( 'Prix livraison', 'aod-client-dashboard' ),
			__( 'Total', 'aod-client-dashboard' ),
			__( 'Suivi', 'aod-client-dashboard' ),
			__( 'Statut', 'aod-client-dashboard' ),
			__( 'Note', 'aod-client-dashboard' ),
		) as $h ) {
			echo '<th>' . esc_html( $h ) . '</th>';
		}
		echo '</tr></thead><tbody>';

		foreach ( $orders as $order ) {
			$this->render_order_row( $order, $status_options );
		}
		echo '</tbody></table></div>';

		$this->render_pagination( $base_url, $paged, (int) $result->max_num_pages, array( 'statut' => $current, 's' => $search ) );
	}

	/* ============================================================
	 * Recherche + pagination (réutilisables)
	 * ========================================================== */

	/**
	 * Barre de recherche GET. Conserve les filtres passés en arguments cachés.
	 *
	 * @param string $base_url    URL de destination du formulaire.
	 * @param string $value       Valeur actuelle de la recherche.
	 * @param string $placeholder Texte indicatif du champ.
	 * @param array  $hidden      Couples nom => valeur à conserver (champs cachés).
	 */
	protected function render_search_bar( $base_url, $value, $placeholder, $hidden = array() ) {
		echo '<form class="aod-cd-search" method="get" action="' . esc_url( $base_url ) . '">';
		foreach ( $hidden as $k => $v ) {
			if ( '' === (string) $v ) {
				continue;
			}
			echo '<input type="hidden" name="' . esc_attr( $k ) . '" value="' . esc_attr( $v ) . '">';
		}
		echo '<input type="search" name="s" value="' . esc_attr( $value ) . '" placeholder="' . esc_attr( $placeholder ) . '">';
		echo '<button type="submit" class="aod-cd-btn aod-cd-btn-sm">' . esc_html__( 'Rechercher', 'aod-client-dashboard' ) . '</button>';
		if ( '' !== $value ) {
			$clear = $hidden ? add_query_arg( array_filter( $hidden, function ( $v ) { return '' !== (string) $v; } ), $base_url ) : $base_url;
			echo ' <a class="aod-cd-btn aod-cd-btn-sm" href="' . esc_url( $clear ) . '">' . esc_html__( 'Réinitialiser', 'aod-client-dashboard' ) . '</a>';
		}
		echo '</form>';
	}

	/**
	 * Pagination numérotée (fenêtre glissante ±2). Conserve les arguments fournis.
	 *
	 * @param string $base_url  URL de base.
	 * @param int    $paged     Page courante (>= 1).
	 * @param int    $max_pages Nombre total de pages.
	 * @param array  $args      Arguments de requête à conserver (ex. statut, s).
	 */
	protected function render_pagination( $base_url, $paged, $max_pages, $args = array() ) {
		if ( $max_pages <= 1 ) {
			return;
		}
		$keep = array_filter( $args, function ( $v ) { return '' !== (string) $v && 'all' !== $v; } );
		$link = function ( $page ) use ( $base_url, $keep ) {
			return esc_url( add_query_arg( array_merge( $keep, array( 'paged' => $page ) ), $base_url ) );
		};

		echo '<nav class="aod-cd-pagination" aria-label="' . esc_attr__( 'Pagination', 'aod-client-dashboard' ) . '">';

		if ( $paged > 1 ) {
			echo '<a class="aod-cd-page" href="' . $link( $paged - 1 ) . '" rel="prev">&larr;</a>';
		}

		// Fenêtre glissante : 1 … (p-2..p+2) … N.
		$window = 2;
		$start  = max( 1, $paged - $window );
		$end    = min( $max_pages, $paged + $window );

		if ( $start > 1 ) {
			echo '<a class="aod-cd-page" href="' . $link( 1 ) . '">1</a>';
			if ( $start > 2 ) {
				echo '<span class="aod-cd-page-gap">…</span>';
			}
		}
		for ( $i = $start; $i <= $end; $i++ ) {
			$cls = ( $i === $paged ) ? ' is-active' : '';
			echo '<a class="aod-cd-page' . esc_attr( $cls ) . '" href="' . $link( $i ) . '">' . (int) $i . '</a>';
		}
		if ( $end < $max_pages ) {
			if ( $end < $max_pages - 1 ) {
				echo '<span class="aod-cd-page-gap">…</span>';
			}
			echo '<a class="aod-cd-page" href="' . $link( $max_pages ) . '">' . (int) $max_pages . '</a>';
		}

		if ( $paged < $max_pages ) {
			echo '<a class="aod-cd-page" href="' . $link( $paged + 1 ) . '" rel="next">&rarr;</a>';
		}
		echo '</nav>';
	}

	/**
	 * Une ligne de commande.
	 *
	 * @param WC_Order $order
	 * @param array    $status_options
	 */
	protected function render_order_row( $order, $status_options ) {
		$wilaya_code = (int) $order->get_meta( '_aod_wilaya_code' );
		$wilaya      = $wilaya_code && class_exists( 'AOD_COD_Data' )
			? AOD_COD_Data::wilaya_name( $wilaya_code )
			: $order->get_billing_state();
		$phone    = $order->get_billing_phone();
		$commune  = $order->get_meta( '_aod_commune' );
		$commune  = $commune ? $commune : $order->get_billing_city();
		$tracking = $order->get_meta( '_aod_ship_tracking' );
		$status   = 'wc-' . $order->get_status();

		// Décomposition du montant : produit + livraison = total.
		$currency      = $order->get_currency();
		$shipping_cost = (float) $order->get_shipping_total();
		$products_cost = (float) $order->get_total() - $shipping_cost;

		echo '<tr>';
		echo '<td><button type="button" class="aod-cd-order-detail" data-order="' . esc_attr( $order->get_id() ) . '"><strong>#' . esc_html( $order->get_order_number() ) . '</strong></button></td>';
		echo '<td>' . esc_html( wc_format_datetime( $order->get_date_created(), 'd/m/Y H:i' ) ) . '</td>';
		echo '<td>' . esc_html( trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() ) ) . '</td>';
		echo '<td>' . ( $phone ? '<a href="tel:' . esc_attr( $phone ) . '">' . esc_html( $phone ) . '</a>' : '&mdash;' ) . '</td>';
		echo '<td>' . esc_html( $wilaya ? $wilaya : '—' ) . '</td>';
		echo '<td>' . esc_html( $commune ? $commune : '—' ) . '</td>';
		echo '<td class="aod-cd-articles">' . $this->order_articles_html( $order ) . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput
		echo '<td>' . wp_kses_post( wc_price( $products_cost, array( 'currency' => $currency ) ) ) . '</td>';
		echo '<td>' . wp_kses_post( wc_price( $shipping_cost, array( 'currency' => $currency ) ) ) . '</td>';
		echo '<td>' . wp_kses_post( $order->get_formatted_order_total() ) . '</td>';
		echo '<td>' . $this->ship_cell_html( $order ) . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput

		echo '<td><select class="aod-cd-status" data-order="' . esc_attr( $order->get_id() ) . '">';
		foreach ( $status_options as $key => $label ) {
			printf(
				'<option value="%s"%s>%s</option>',
				esc_attr( $key ), selected( $status, $key, false ), esc_html( $label )
			);
		}
		echo '</select></td>';

		$note_count = count( wc_get_order_notes( array( 'order_id' => $order->get_id() ) ) );
		echo '<td><button type="button" class="aod-cd-btn aod-cd-btn-sm aod-cd-note-btn" data-order="' . esc_attr( $order->get_id() ) . '" title="' . esc_attr__( 'Ajouter une note', 'aod-client-dashboard' ) . '">📝'
			. ( $note_count ? ' <span class="aod-cd-badge">' . (int) $note_count . '</span>' : '' )
			. '</button></td>';
		echo '</tr>';
	}

	/**
	 * Version d'un asset pour le cache-busting : date de modif du fichier,
	 * avec repli sur la version du plugin si le fichier est introuvable.
	 *
	 * @param string $rel Chemin relatif au dossier du plugin (ex. 'assets/js/dashboard.js').
	 * @return string
	 */
	protected function asset_ver( $rel ) {
		$path = AOD_CD_PATH . $rel;
		$mt   = is_file( $path ) ? filemtime( $path ) : 0;
		return $mt ? (string) $mt : AOD_CD_VERSION;
	}

	/**
	 * Variantes choisies d'une ligne de commande (ex. ['XXL', 'Gris']).
	 *
	 * @param WC_Order_Item $item
	 * @return string[] Valeurs des variantes (sans les libellés de section).
	 */
	protected function item_variants( $item ) {
		$out = array();
		if ( ! is_callable( array( $item, 'get_formatted_meta_data' ) ) ) {
			return $out;
		}
		foreach ( $item->get_formatted_meta_data() as $m ) {
			$val = wp_strip_all_tags( $m->display_value );
			if ( '' !== trim( $val ) ) {
				$out[] = $val;
			}
		}
		return $out;
	}

	/**
	 * Colonne « Articles » : produits commandés + variantes + quantité.
	 *
	 * @param WC_Order $order
	 * @return string HTML sûr.
	 */
	protected function order_articles_html( $order ) {
		$items = $order->get_items();
		if ( empty( $items ) ) {
			return '<span class="aod-cd-muted">—</span>';
		}
		$lines = array();
		foreach ( $items as $item ) {
			$txt      = '<strong>' . esc_html( $item->get_name() ) . '</strong>';
			$variants = $this->item_variants( $item );
			if ( $variants ) {
				$txt .= ' <span class="aod-cd-variants">(' . esc_html( implode( ', ', $variants ) ) . ')</span>';
			}
			$txt    .= ' <span class="aod-cd-qty">×' . esc_html( $item->get_quantity() ) . '</span>';
			$lines[] = $txt;
		}
		return '<div class="aod-cd-articles-list">' . implode( '<br>', $lines ) . '</div>';
	}

	/**
	 * Cellule « Suivi » : icône camion (gris = à envoyer, vert = envoyé, rouge = échec).
	 *
	 * Réutilise l'icône du module d'expédition (AOD_Shipping) si disponible.
	 *
	 * @param WC_Order $order
	 * @return string HTML sûr.
	 */
	protected function ship_cell_html( $order ) {
		$tracking = $order->get_meta( '_aod_ship_tracking' );

		if ( class_exists( 'AOD_Shipping' ) ) {
			$ship   = AOD_Shipping::instance();
			$status = $ship->ship_status( $order );
			if ( 'none' === $status ) {
				return '<span class="aod-cd-muted">—</span>';
			}
			$icon  = $ship->status_badge_html( $order, 22, false );
			$extra = ( 'sent' === $status && $tracking )
				? ' <code class="aod-cd-trackcode">' . esc_html( $tracking ) . '</code>'
				: '';
			return '<span class="aod-cd-ship">' . $icon . $extra . '</span>';
		}

		// Repli si le module d'expédition n'est pas actif.
		return $tracking
			? '<span class="aod-cd-track">✓ <code>' . esc_html( $tracking ) . '</code></span>'
			: '<span class="aod-cd-muted">—</span>';
	}

	/**
	 * Libellés des statuts de commande, forcés en français.
	 *
	 * WooCommerce renvoie ses libellés natifs en anglais lorsque le pack de
	 * traduction n'est pas chargé (même sous locale fr_FR). On conserve l'ordre
	 * et les statuts réels de WooCommerce (y compris les statuts personnalisés)
	 * et on remplace uniquement les libellés connus par leur version française.
	 *
	 * @return array [ 'wc-pending' => 'En attente', ... ]
	 */
	protected function status_labels() {
		$fr = array(
			'wc-pending'        => __( 'En attente', 'aod-client-dashboard' ),
			'wc-processing'     => __( 'En cours', 'aod-client-dashboard' ),
			'wc-aod-confirmed'  => __( 'Confirmée', 'aod-client-dashboard' ),
			'wc-on-hold'        => __( 'En pause', 'aod-client-dashboard' ),
			'wc-completed'      => __( 'Terminée', 'aod-client-dashboard' ),
			'wc-cancelled'      => __( 'Annulée', 'aod-client-dashboard' ),
			'wc-refunded'       => __( 'Remboursée', 'aod-client-dashboard' ),
			'wc-failed'         => __( 'Échouée', 'aod-client-dashboard' ),
			'wc-checkout-draft' => __( 'Brouillon', 'aod-client-dashboard' ),
		);

		$labels = array();
		foreach ( wc_get_order_statuses() as $key => $label ) {
			$labels[ $key ] = isset( $fr[ $key ] ) ? $fr[ $key ] : $label;
		}

		return $labels;
	}

	/**
	 * Compte les commandes d'un statut (ou toutes).
	 *
	 * @param string $slug 'all' ou statut sans préfixe wc-.
	 * @param string $date Filtre date_created optionnel (ex. '>=2026-01-01').
	 *                     Format HPOS-safe accepté par wc_get_orders.
	 * @return int
	 */
	protected function count_orders( $slug, $date = '' ) {
		// Comptage côté base (COUNT SQL) via la pagination de wc_get_orders :
		// limit=1 + paginate=true renvoie ->total sans charger toutes les lignes.
		// HPOS-safe, contrairement à un wc_get_orders( limit => -1 ) qui chargeait
		// tous les IDs de chaque statut à chaque affichage de la page Commandes.
		$status = ( 'all' === $slug )
			? array_keys( wc_get_order_statuses() )
			: array( 'wc-' . $slug );

		$args = array(
			'limit'    => 1,
			'paginate' => true,
			'return'   => 'ids',
			'status'   => $status,
		);
		if ( '' !== $date ) {
			$args['date_created'] = $date;
		}

		$res = wc_get_orders( $args );

		return isset( $res->total ) ? (int) $res->total : 0;
	}

	/* ============================================================
	 * Section : Statistiques (lecture seule)
	 * ========================================================== */

	/** URL de base de la section statistiques. */
	protected function stats_url( $args = array() ) {
		$url = home_url( '/' . AOD_CD_SLUG . '/stats' );
		return $args ? add_query_arg( $args, $url ) : $url;
	}

	/**
	 * Traduit la période sélectionnée en filtre date_created HPOS-safe.
	 *
	 * Renvoie une date au format « >=YYYY-MM-DD » interprétée dans le fuseau
	 * du site par wc_get_orders (compatible CPT et HPOS), ou '' pour « tout ».
	 *
	 * @param string $period '7' | '30' | 'year' | 'all'.
	 * @return string
	 */
	protected function stats_date_arg( $period ) {
		$now = time(); // wp_date() applique le fuseau du site.
		switch ( $period ) {
			case '7':
				return '>=' . wp_date( 'Y-m-d', $now - 6 * DAY_IN_SECONDS );
			case '30':
				return '>=' . wp_date( 'Y-m-d', $now - 29 * DAY_IN_SECONDS );
			case 'year':
				return '>=' . wp_date( 'Y', $now ) . '-01-01';
			case 'all':
			default:
				return '';
		}
	}

	protected function section_stats() {
		$periods = array(
			'7'    => __( '7 jours', 'aod-client-dashboard' ),
			'30'   => __( '30 jours', 'aod-client-dashboard' ),
			'year' => __( 'Cette année', 'aod-client-dashboard' ),
			'all'  => __( 'Tout', 'aod-client-dashboard' ),
		);
		$period = isset( $_GET['periode'] ) ? sanitize_key( wp_unslash( $_GET['periode'] ) ) : '30';
		if ( ! isset( $periods[ $period ] ) ) {
			$period = '30';
		}
		$date_arg = $this->stats_date_arg( $period );

		// Une seule requête : les commandes encaissées de la période servent à la
		// fois au CA, au panier moyen et au top-produits (évite plusieurs scans).
		$paid_statuses = array( 'wc-processing', 'wc-aod-confirmed', 'wc-completed' );
		$paid_args     = array(
			'limit'  => -1,
			'status' => $paid_statuses,
		);
		if ( '' !== $date_arg ) {
			$paid_args['date_created'] = $date_arg;
		}
		$paid = wc_get_orders( $paid_args );

		// Bacs du graphe : jours pour 7/30, mois pour l'année, rien pour « tout »
		// (fenêtre inconnue). Préremplis à 0 pour afficher aussi les creux.
		$bins    = $this->stats_bins( $period );
		$monthly = ( 'year' === $period );

		$revenue   = 0.0;
		$prod_rev  = 0.0;   // CA produits (hors livraison) — base de la marge.
		$cost_tot  = 0.0;   // Coût d'achat cumulé des lignes dont le coût est connu.
		$miss_cost = false; // Au moins une ligne sans prix d'achat renseigné.
		$top       = array(); // pid|hash => [ name, qty, rev, cost, pid, cknown ].
		foreach ( $paid as $o ) {
			$total    = (float) $o->get_total();
			$revenue += $total;

			if ( $bins ) {
				$dc = $o->get_date_created();
				if ( $dc ) {
					$bkey = wp_date( $monthly ? 'Y-m' : 'Y-m-d', $dc->getTimestamp() );
					if ( isset( $bins[ $bkey ] ) ) {
						$bins[ $bkey ]['val'] += $total;
					}
				}
			}

			foreach ( $o->get_items() as $item ) {
				$pid = $item->get_product_id();
				$key = $pid ? (string) $pid : 'x' . md5( $item->get_name() );
				if ( ! isset( $top[ $key ] ) ) {
					$top[ $key ] = array(
						'name'   => $item->get_name(),
						'qty'    => 0,
						'rev'    => 0.0,
						'cost'   => 0.0,
						'cknown' => true,
						'pid'    => $pid,
					);
				}
				$qty       = (int) $item->get_quantity();
				$line_rev  = (float) $item->get_total();
				$prod_rev += $line_rev;
				$top[ $key ]['qty'] += $qty;
				$top[ $key ]['rev'] += $line_rev;

				// Coût d'achat : meta produit (ou produit parent pour une variation).
				$cost = $this->item_cost_price( $item );
				if ( null === $cost ) {
					$miss_cost              = true;
					$top[ $key ]['cknown']  = false;
				} else {
					$cost_tot               += $cost * $qty;
					$top[ $key ]['cost']    += $cost * $qty;
				}
			}
		}

		$nb_paid = count( $paid );
		$avg     = $nb_paid > 0 ? $revenue / $nb_paid : 0.0;
		$margin  = $prod_rev - $cost_tot;
		$margin_rate = $prod_rev > 0 ? ( $margin / $prod_rev ) * 100 : 0.0;

		// « En attente » reste un indicateur actionnable (toutes périodes) ;
		// « Annulées » est rapporté à la période ; « Produits » est l'état courant.
		$nb_pending = $this->count_orders( 'pending' );
		$nb_cancel  = $this->count_orders( 'cancelled', $date_arg );
		$nb_prod    = (int) wp_count_posts( 'product' )->publish;

		// Chaque carte : libellé, valeur (HTML autorisé), icône, tonalité de couleur.
		$cards = array(
			array( __( 'Chiffre d’affaires', 'aod-client-dashboard' ), wp_kses_post( wc_price( $revenue ) ), '💰', 'blue' ),
			array( __( 'Commandes encaissées', 'aod-client-dashboard' ), (string) $nb_paid, '🧾', 'indigo' ),
			array( __( 'Panier moyen', 'aod-client-dashboard' ), wp_kses_post( wc_price( $avg ) ), '🛒', 'teal' ),
			array( __( 'Marge estimée', 'aod-client-dashboard' ), wp_kses_post( wc_price( $margin ) ), '📈', 'green' ),
			array( __( 'Taux de marge', 'aod-client-dashboard' ), esc_html( number_format_i18n( $margin_rate, 1 ) . ' %' ), '💹', 'green' ),
			array( __( 'En attente (en cours)', 'aod-client-dashboard' ), (string) $nb_pending, '⏳', 'amber' ),
			array( __( 'Annulées', 'aod-client-dashboard' ), (string) $nb_cancel, '✖', 'red' ),
			array( __( 'Produits en ligne', 'aod-client-dashboard' ), (string) $nb_prod, '📦', 'slate' ),
		);

		echo '<div class="aod-cd-cards">';
		foreach ( $cards as $c ) {
			echo '<div class="aod-cd-card">';
			echo '<span class="aod-cd-card-ico t-' . esc_attr( $c[3] ) . '" aria-hidden="true">' . esc_html( $c[2] ) . '</span>';
			echo '<div class="aod-cd-card-body"><div class="aod-cd-card-val">' . wp_kses_post( $c[1] ) . '</div><div class="aod-cd-card-lab">' . esc_html( $c[0] ) . '</div></div>';
			echo '</div>';
		}
		echo '</div>';

		// Onglets de période, placés juste au-dessus du graphe (ils pilotent
		// toute la page : cartes, graphe et top-produits).
		echo '<div class="aod-cd-tabs aod-cd-tabs-chart">';
		foreach ( $periods as $slug => $label ) {
			$active = ( $slug === $period ) ? ' is-active' : '';
			$url    = ( 'all' === $slug ) ? $this->stats_url() : $this->stats_url( array( 'periode' => $slug ) );
			printf(
				'<a class="aod-cd-tab%s" href="%s">%s</a>',
				esc_attr( $active ), esc_url( $url ), esc_html( $label )
			);
		}
		echo '</div>';

		// Graphe du CA dans le temps (courbe SVG).
		$this->render_stats_chart( $bins );

		// Top-produits de la période (par CA généré).
		echo '<h2 class="aod-cd-form-subtitle" style="margin-top:26px">' . esc_html__( 'Top produits', 'aod-client-dashboard' ) . '</h2>';
		if ( empty( $top ) ) {
			echo '<p class="aod-cd-empty">' . esc_html__( 'Aucune vente sur cette période.', 'aod-client-dashboard' ) . '</p>';
		} else {
			usort( $top, function ( $a, $b ) {
				if ( $a['rev'] === $b['rev'] ) {
					return $b['qty'] - $a['qty'];
				}
				return ( $a['rev'] < $b['rev'] ) ? 1 : -1;
			} );
			$top = array_slice( $top, 0, 10 );

			echo '<div class="aod-cd-tablewrap"><table class="aod-cd-table"><thead><tr>';
			foreach ( array(
				__( 'Produit', 'aod-client-dashboard' ),
				__( 'Quantité vendue', 'aod-client-dashboard' ),
				__( 'CA généré', 'aod-client-dashboard' ),
				__( 'Marge', 'aod-client-dashboard' ),
			) as $h ) {
				echo '<th>' . esc_html( $h ) . '</th>';
			}
			echo '</tr></thead><tbody>';
			foreach ( $top as $row ) {
				$name = $row['name'];
				if ( $row['pid'] && current_user_can( 'edit_product', $row['pid'] ) ) {
					$name = '<a href="' . esc_url( $this->products_url( array( 'action' => 'edit', 'product' => $row['pid'] ) ) ) . '">' . esc_html( $row['name'] ) . '</a>';
				} else {
					$name = esc_html( $name );
				}
				// Marge produit : « — » si aucun prix d'achat n'est renseigné pour ce produit.
				if ( $row['cknown'] && $row['cost'] > 0 ) {
					$pm     = $row['rev'] - $row['cost'];
					$margin_cell = wp_kses_post( wc_price( $pm ) );
				} else {
					$margin_cell = '<span class="aod-cd-muted">—</span>';
				}
				echo '<tr><td>' . $name . '</td><td>' . (int) $row['qty'] . '</td><td>' . wp_kses_post( wc_price( $row['rev'] ) ) . '</td><td>' . $margin_cell . '</td></tr>';
			}
			echo '</tbody></table></div>';
		}

		echo '<p class="aod-cd-note">' . esc_html__( 'CA = total des commandes en cours, confirmées et terminées sur la période sélectionnée. « En attente » et « Produits en ligne » reflètent l’état actuel, toutes périodes confondues.', 'aod-client-dashboard' ) . '</p>';
		echo '<p class="aod-cd-note">' . esc_html__( 'Marge = CA produits (hors livraison) − prix d’achat. Renseignez le « Prix d’achat » d’un produit pour qu’il compte dans la marge ; les produits sans prix d’achat sont ignorés (un « — » s’affiche).', 'aod-client-dashboard' ) . '</p>';
		if ( $miss_cost ) {
			echo '<p class="aod-cd-note">' . esc_html__( 'Note : certains produits vendus n’ont pas de prix d’achat renseigné — la marge affichée est donc une estimation partielle.', 'aod-client-dashboard' ) . '</p>';
		}
	}

	/**
	 * Prix d'achat unitaire d'une ligne de commande.
	 *
	 * Lit le meta `_aod_cost_price` du produit ; pour une variation sans coût
	 * propre, retombe sur le coût du produit parent. Retourne null quand aucun
	 * prix d'achat n'est renseigné (la ligne est alors exclue du calcul de marge).
	 *
	 * @param WC_Order_Item_Product $item Ligne de commande.
	 * @return float|null Coût unitaire, ou null si inconnu.
	 */
	protected function item_cost_price( $item ) {
		$product = $item->get_product();
		if ( ! $product ) {
			return null;
		}
		$cost = $product->get_meta( '_aod_cost_price' );
		if ( ( '' === $cost || null === $cost ) && $product->get_parent_id() ) {
			$parent = wc_get_product( $product->get_parent_id() );
			if ( $parent ) {
				$cost = $parent->get_meta( '_aod_cost_price' );
			}
		}
		if ( '' === $cost || null === $cost ) {
			return null;
		}
		return (float) $cost;
	}

	/**
	 * Construit les bacs (vides) du graphe pour la période donnée.
	 *
	 * Jours pour 7/30, mois pour l'année, tableau vide pour « tout » (fenêtre non
	 * bornée → graphe masqué). Chaque bac : val, label court, libellé complet, show.
	 *
	 * @param string $period '7' | '30' | 'year' | 'all'.
	 * @return array<string,array>
	 */
	protected function stats_bins( $period ) {
		$bins = array();

		if ( '7' === $period || '30' === $period ) {
			$days  = ( '7' === $period ) ? 7 : 30;
			$every = ( 7 === $days ) ? 1 : 5; // 30 j : une étiquette tous les 5 jours.
			$now   = time();
			for ( $i = $days - 1; $i >= 0; $i-- ) {
				$ts             = $now - $i * DAY_IN_SECONDS;
				$bins[ wp_date( 'Y-m-d', $ts ) ] = array(
					'val'   => 0.0,
					'label' => wp_date( 'd/m', $ts ),
					'full'  => wp_date( 'd/m/Y', $ts ),
					'show'  => ( 0 === $i % $every ),
				);
			}
		} elseif ( 'year' === $period ) {
			$year  = wp_date( 'Y', time() );
			$month = (int) wp_date( 'n', time() );
			for ( $m = 1; $m <= $month; $m++ ) {
				$ts                                = strtotime( sprintf( '%s-%02d-01', $year, $m ) );
				$bins[ sprintf( '%s-%02d', $year, $m ) ] = array(
					'val'   => 0.0,
					'label' => wp_date( 'M', $ts ),
					'full'  => wp_date( 'F Y', $ts ),
					'show'  => true,
				);
			}
		}

		return $bins;
	}

	/**
	 * Affiche un graphe à courbe (SVG lissé, sans librairie externe) du CA par bac.
	 *
	 * Le tracé est calculé côté serveur en unités viewBox ; un léger script
	 * (bindCharts) ajoute une infobulle interactive au survol. Aucune dépendance.
	 *
	 * @param array $bins Bacs renvoyés par stats_bins() et alimentés en CA.
	 */
	protected function render_stats_chart( $bins ) {
		if ( empty( $bins ) ) {
			return; // Période « tout » : pas de fenêtre fixe à représenter.
		}

		$total = 0.0;
		$max   = 0.0;
		foreach ( $bins as $b ) {
			$total += (float) $b['val'];
			if ( (float) $b['val'] > $max ) {
				$max = (float) $b['val'];
			}
		}

		echo '<div class="aod-cd-chartcard">';
		echo '<div class="aod-cd-chart-head">';
		echo '<h2 class="aod-cd-chart-title">' . esc_html__( 'Évolution du chiffre d’affaires', 'aod-client-dashboard' ) . '</h2>';
		echo '<span class="aod-cd-chart-total">' . esc_html__( 'Total', 'aod-client-dashboard' ) . ' <b>' . wp_kses_post( wc_price( $total ) ) . '</b></span>';
		echo '</div>';

		if ( $max <= 0 ) {
			echo '<p class="aod-cd-empty">' . esc_html__( 'Aucune vente sur cette période.', 'aod-client-dashboard' ) . '</p>';
			echo '</div>';
			return;
		}

		// Géométrie du tracé (unités viewBox ; le SVG est mis à l'échelle en CSS).
		$vw = 920;
		$vh = 215;
		$pl = 50;  // marge gauche (étiquettes Y)
		$pr = 16;
		$pt = 16;
		$pb = 28;  // marge basse (étiquettes X)
		$pw = $vw - $pl - $pr;
		$ph = $vh - $pt - $pb;
		$base_y = $pt + $ph;
		$n = count( $bins );

		// Points (x,y) + données pour l'infobulle JS.
		$pts  = array();
		$data = array();
		$i    = 0;
		foreach ( $bins as $b ) {
			$x = ( $n <= 1 ) ? ( $pl + $pw / 2 ) : ( $pl + $i * $pw / ( $n - 1 ) );
			$y = $base_y - ( (float) $b['val'] / $max ) * $ph;
			$pts[] = array( $x, $y );
			$data[] = array(
				'x'      => round( $x, 1 ),
				'y'      => round( $y, 1 ),
				'label'  => $b['full'],
				'amount' => wp_strip_all_tags( wc_price( (float) $b['val'] ) ),
			);
			$i++;
		}

		// Courbe lissée : spline Catmull-Rom convertie en Béziers cubiques.
		$r    = function ( $v ) { return round( $v, 1 ); };
		$line = 'M' . $r( $pts[0][0] ) . ' ' . $r( $pts[0][1] );
		for ( $k = 1; $k < $n; $k++ ) {
			$p0  = $pts[ max( 0, $k - 2 ) ];
			$p1  = $pts[ $k - 1 ];
			$p2  = $pts[ $k ];
			$p3  = $pts[ min( $n - 1, $k + 1 ) ];
			$c1x = $p1[0] + ( $p2[0] - $p0[0] ) / 6;
			$c1y = $p1[1] + ( $p2[1] - $p0[1] ) / 6;
			$c2x = $p2[0] - ( $p3[0] - $p1[0] ) / 6;
			$c2y = $p2[1] - ( $p3[1] - $p1[1] ) / 6;
			$line .= ' C' . $r( $c1x ) . ' ' . $r( $c1y ) . ' ' . $r( $c2x ) . ' ' . $r( $c2y ) . ' ' . $r( $p2[0] ) . ' ' . $r( $p2[1] );
		}
		$area = $line . ' L' . $r( $pts[ $n - 1 ][0] ) . ' ' . $r( $base_y ) . ' L' . $r( $pts[0][0] ) . ' ' . $r( $base_y ) . ' Z';

		echo '<div class="aod-cd-linechart" data-vw="' . (int) $vw . '" data-points="' . esc_attr( wp_json_encode( $data ) ) . '">';
		echo '<svg viewBox="0 0 ' . (int) $vw . ' ' . (int) $vh . '" preserveAspectRatio="xMidYMid meet" role="img" aria-label="' . esc_attr__( 'Chiffre d’affaires sur la période', 'aod-client-dashboard' ) . '">';
		echo '<defs><linearGradient id="aodAreaGrad" x1="0" y1="0" x2="0" y2="1">'
			. '<stop offset="0%" stop-color="#FFE21E" stop-opacity="0.22"/>'
			. '<stop offset="100%" stop-color="#FFE21E" stop-opacity="0"/>'
			. '</linearGradient></defs>';

		// Grille horizontale + étiquettes de l'axe Y.
		for ( $g = 0; $g <= 4; $g++ ) {
			$frac = $g / 4;
			$gy   = $base_y - $frac * $ph;
			$gv   = $max * $frac;
			echo '<line class="aod-cd-grid-line" x1="' . (int) $pl . '" y1="' . esc_attr( $r( $gy ) ) . '" x2="' . (int) ( $vw - $pr ) . '" y2="' . esc_attr( $r( $gy ) ) . '"/>';
			echo '<text class="aod-cd-axis-lbl" x="' . (int) ( $pl - 10 ) . '" y="' . esc_attr( $r( $gy + 3.5 ) ) . '" text-anchor="end">' . esc_html( $this->stats_compact_amount( $gv ) ) . '</text>';
		}

		// Aire dégradée + courbe.
		echo '<path class="aod-cd-area" d="' . esc_attr( $area ) . '"/>';
		echo '<path class="aod-cd-line" d="' . esc_attr( $line ) . '"/>';

		// Repère vertical (déplacé par le JS au survol).
		echo '<line class="aod-cd-guide" x1="' . (int) $pl . '" y1="' . (int) $pt . '" x2="' . (int) $pl . '" y2="' . esc_attr( $r( $base_y ) ) . '"/>';

		// Étiquettes X + points.
		$i = 0;
		foreach ( $bins as $b ) {
			$x = $pts[ $i ][0];
			$y = $pts[ $i ][1];
			if ( ! empty( $b['show'] ) ) {
				echo '<text class="aod-cd-x-lbl" x="' . esc_attr( $r( $x ) ) . '" y="' . (int) ( $vh - 13 ) . '" text-anchor="middle">' . esc_html( $b['label'] ) . '</text>';
			}
			echo '<circle class="aod-cd-dot" cx="' . esc_attr( $r( $x ) ) . '" cy="' . esc_attr( $r( $y ) ) . '" r="2.6"/>';
			$i++;
		}

		echo '</svg>';
		echo '<div class="aod-cd-tip" aria-hidden="true"></div>';
		echo '</div>'; // .aod-cd-linechart
		echo '</div>'; // .aod-cd-chartcard
	}

	/**
	 * Formate un montant de façon compacte pour les étiquettes d'axe (12,5k, 1,2M).
	 *
	 * @param float $n Montant.
	 * @return string
	 */
	protected function stats_compact_amount( $n ) {
		$n = (float) $n;
		if ( $n >= 1000000 ) {
			return number_format_i18n( $n / 1000000, ( $n < 10000000 ) ? 1 : 0 ) . 'M';
		}
		if ( $n >= 1000 ) {
			return number_format_i18n( $n / 1000, ( $n < 10000 ) ? 1 : 0 ) . 'k';
		}
		return number_format_i18n( $n );
	}

	/* ============================================================
	 * Section : Produits (CRUD complet + variantes couleur)
	 * ========================================================== */

	protected function section_products() {
		$action = isset( $_GET['action'] ) ? sanitize_key( wp_unslash( $_GET['action'] ) ) : '';
		if ( 'new' === $action || 'edit' === $action ) {
			$pid = ( 'edit' === $action && isset( $_GET['product'] ) ) ? absint( $_GET['product'] ) : 0;
			$this->render_product_form( $pid );
			return;
		}
		$this->render_product_list();
	}

	/** URL de base de la section produits. */
	protected function products_url( $args = array() ) {
		$url = home_url( '/' . AOD_CD_SLUG . '/products' );
		return $args ? add_query_arg( $args, $url ) : $url;
	}

	/* ============================================================
	 * Catégories de produits : page de gestion (créer / renommer / supprimer)
	 * ========================================================== */

	/**
	 * Page « Catégories » : création, renommage et suppression des catégories
	 * de produits (taxonomie product_cat). La suppression ne touche pas aux
	 * produits — ils perdent simplement ce classement.
	 */
	protected function render_categories_page() {
		$terms = get_terms( array( 'taxonomy' => 'product_cat', 'hide_empty' => false, 'orderby' => 'name', 'order' => 'ASC' ) );
		if ( is_wp_error( $terms ) ) {
			$terms = array();
		}
		?>
		<div class="aod-cd-cats" id="aod-cd-cats">
			<h2 class="aod-cd-form-title"><?php esc_html_e( 'Catégories de produits', 'aod-client-dashboard' ); ?></h2>
			<p class="aod-cd-note" style="margin-top:0"><?php esc_html_e( 'Créez, renommez ou supprimez les catégories qui servent à classer vos produits. Supprimer une catégorie n’efface aucun produit : les produits concernés perdent simplement ce classement.', 'aod-client-dashboard' ); ?></p>

			<form class="aod-cd-cat-new" id="aod-cd-cat-new">
				<input type="text" name="cat_name" class="aod-cd-cat-newname" placeholder="<?php esc_attr_e( 'Nom de la nouvelle catégorie', 'aod-client-dashboard' ); ?>" required>
				<button type="submit" class="aod-cd-btn aod-cd-btn-primary">+ <?php esc_html_e( 'Ajouter', 'aod-client-dashboard' ); ?></button>
			</form>

			<div class="aod-cd-cat-list">
				<?php if ( empty( $terms ) ) : ?>
					<p class="aod-cd-empty aod-cd-cat-empty"><?php esc_html_e( 'Aucune catégorie pour le moment.', 'aod-client-dashboard' ); ?></p>
				<?php else :
					foreach ( $terms as $t ) {
						$this->render_category_row( $t->term_id, $t->name, (int) $t->count );
					}
				endif; ?>
			</div>
			<template id="aod-cd-cat-row-tpl"><?php $this->render_category_row( 0, '', 0 ); ?></template>
		</div>
		<?php
	}

	/**
	 * Une ligne de catégorie dans la page de gestion.
	 *
	 * @param int    $id    Term ID.
	 * @param string $name  Nom de la catégorie.
	 * @param int    $count Nombre de produits rattachés.
	 */
	protected function render_category_row( $id, $name, $count ) {
		?>
		<div class="aod-cd-cat-row" data-id="<?php echo esc_attr( (string) $id ); ?>">
			<input type="text" class="aod-cd-cat-name" value="<?php echo esc_attr( $name ); ?>">
			<span class="aod-cd-cat-count"><?php
				/* translators: %d: nombre de produits */
				printf( esc_html( _n( '%d produit', '%d produits', $count, 'aod-client-dashboard' ) ), (int) $count );
			?></span>
			<button type="button" class="aod-cd-btn aod-cd-btn-sm aod-cd-cat-save"><?php esc_html_e( 'Renommer', 'aod-client-dashboard' ); ?></button>
			<button type="button" class="aod-cd-color-del aod-cd-cat-del" aria-label="<?php esc_attr_e( 'Supprimer la catégorie', 'aod-client-dashboard' ); ?>">&times;</button>
		</div>
		<?php
	}

	/**
	 * Liste des produits avec actions (ajouter / éditer / supprimer).
	 */
	protected function render_product_list() {
		echo '<div class="aod-cd-bar">';
		echo '<a class="aod-cd-btn aod-cd-btn-primary" href="' . esc_url( $this->products_url( array( 'action' => 'new' ) ) ) . '">+ ' . esc_html__( 'Ajouter un produit', 'aod-client-dashboard' ) . '</a>';
		echo '</div>';

		$search   = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
		$paged    = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;
		$per_page = 20;
		$base_url = $this->products_url();

		// Recherche par nom de produit ou SKU.
		$this->render_search_bar( $base_url, $search, __( 'Rechercher un produit…', 'aod-client-dashboard' ) );

		$args = array(
			'limit'    => $per_page,
			'page'     => $paged,
			'paginate' => true,
			'orderby'  => 'date',
			'order'    => 'DESC',
			'status'   => array( 'publish', 'draft', 'pending', 'private' ),
		);
		if ( '' !== $search ) {
			$args['s'] = $search;
		}
		$result   = wc_get_products( $args );
		$products = $result->products;

		if ( empty( $products ) ) {
			$msg = ( '' !== $search )
				? __( 'Aucun produit ne correspond à cette recherche.', 'aod-client-dashboard' )
				: __( 'Aucun produit pour le moment. Cliquez sur « Ajouter un produit » pour commencer.', 'aod-client-dashboard' );
			echo '<p class="aod-cd-empty">' . esc_html( $msg ) . '</p>';
			return;
		}

		echo '<div class="aod-cd-tablewrap"><table class="aod-cd-table"><thead><tr>';
		foreach ( array(
			__( 'Produit', 'aod-client-dashboard' ),
			__( 'Prix', 'aod-client-dashboard' ),
			__( 'Stock', 'aod-client-dashboard' ),
			__( 'Statut', 'aod-client-dashboard' ),
			__( 'Actions', 'aod-client-dashboard' ),
		) as $h ) {
			echo '<th>' . esc_html( $h ) . '</th>';
		}
		echo '</tr></thead><tbody>';

		foreach ( $products as $p ) {
			$img      = $p->get_image( array( 40, 40 ) );
			$edit_url = $this->products_url( array( 'action' => 'edit', 'product' => $p->get_id() ) );
			echo '<tr data-product="' . esc_attr( $p->get_id() ) . '">';
			echo '<td><span class="aod-cd-prod">' . wp_kses_post( $img ) . '<a href="' . esc_url( $edit_url ) . '">' . esc_html( $p->get_name() ) . '</a></span></td>';
			echo '<td>' . wp_kses_post( $p->get_price_html() ? $p->get_price_html() : '&mdash;' ) . '</td>';
			echo '<td>' . esc_html( $p->managing_stock() ? (string) $p->get_stock_quantity() : '—' ) . '</td>';
			echo '<td>' . esc_html( 'publish' === $p->get_status() ? __( 'En ligne', 'aod-client-dashboard' ) : __( 'Brouillon', 'aod-client-dashboard' ) ) . '</td>';
			echo '<td class="aod-cd-rowactions">';
			echo '<a class="aod-cd-btn aod-cd-btn-sm" href="' . esc_url( $edit_url ) . '">' . esc_html__( 'Éditer', 'aod-client-dashboard' ) . '</a> ';
			echo '<button type="button" class="aod-cd-btn aod-cd-btn-sm aod-cd-btn-danger aod-cd-del-product" data-product="' . esc_attr( $p->get_id() ) . '" data-name="' . esc_attr( $p->get_name() ) . '">' . esc_html__( 'Supprimer', 'aod-client-dashboard' ) . '</button>';
			echo '</td>';
			echo '</tr>';
		}
		echo '</tbody></table></div>';

		$this->render_pagination( $base_url, $paged, (int) $result->max_num_pages, array( 's' => $search ) );
	}

	/**
	 * Formulaire d'ajout / édition d'un produit.
	 *
	 * @param int $pid 0 pour un nouveau produit.
	 */
	protected function render_product_form( $pid ) {
		$product = $pid ? wc_get_product( $pid ) : null;
		if ( $pid && ! $product ) {
			echo '<p class="aod-cd-empty">' . esc_html__( 'Produit introuvable.', 'aod-client-dashboard' ) . '</p>';
			return;
		}

		$name       = $product ? $product->get_name() : '';
		$desc       = $product ? $product->get_description() : '';
		$reg        = $product ? $product->get_regular_price() : '';
		$sale       = $product ? $product->get_sale_price() : '';
		$sku        = $product ? $product->get_sku() : '';
		$manage     = $product ? $product->managing_stock() : false;
		$qty        = $product ? $product->get_stock_quantity() : '';
		$status     = $product ? $product->get_status() : 'publish';
		$cat_ids    = $product ? $product->get_category_ids() : array();
		$img_url    = ( $product && $product->get_image_id() ) ? wp_get_attachment_image_url( $product->get_image_id(), 'thumbnail' ) : '';
		$currency   = get_woocommerce_currency_symbol();

		// Sections d'options (Taille, Couleur, Pointure…) — modèle multi-sections.
		$options = $this->get_product_options( $product );

		$categories = get_terms( array( 'taxonomy' => 'product_cat', 'hide_empty' => false ) );

		// Offres (prix par quantité) : N unités de ce produit à un prix de lot.
		// Fusionne les anciennes sections « Prix par quantité » et « Pack/Assortiment ».
		$offers = $this->get_product_offers( $product );

		// Lot 2 : poids, prix d'achat, arguments de vente, galerie.
		$weight      = $product ? (string) $product->get_weight() : '';
		$cost        = $product ? (string) $product->get_meta( '_aod_cost_price' ) : '';
		$points      = $product ? $product->get_meta( '_aod_selling_points' ) : array();
		$points_text = is_array( $points ) ? implode( "\n", $points ) : '';
		$gallery_ids = $product ? $product->get_gallery_image_ids() : array();

		// Lot 3 : seuil de stock bas, programmation de la promo.
		$low_stock = $product ? (string) $product->get_low_stock_amount() : '';
		$sale_from = ( $product && $product->get_date_on_sale_from() ) ? $product->get_date_on_sale_from()->date( 'Y-m-d' ) : '';
		$sale_to   = ( $product && $product->get_date_on_sale_to() ) ? $product->get_date_on_sale_to()->date( 'Y-m-d' ) : '';

		$title = $pid ? __( 'Modifier le produit', 'aod-client-dashboard' ) : __( 'Nouveau produit', 'aod-client-dashboard' );
		?>
		<div class="aod-cd-bar">
			<a class="aod-cd-btn" href="<?php echo esc_url( $this->products_url() ); ?>">&larr; <?php esc_html_e( 'Retour à la liste', 'aod-client-dashboard' ); ?></a>
		</div>

		<form class="aod-cd-form aod-cd-acc-form" id="aod-cd-product-form" enctype="multipart/form-data">
			<input type="hidden" name="product_id" value="<?php echo esc_attr( (string) $pid ); ?>">
			<h2 class="aod-cd-form-title"><?php echo esc_html( $title ); ?></h2>

			<!-- Section : Informations générales -->
			<details class="aod-cd-acc" open>
				<summary class="aod-cd-acc-sum"><span class="aod-cd-acc-ic">📝</span> <?php esc_html_e( 'Informations générales', 'aod-client-dashboard' ); ?></summary>
				<div class="aod-cd-acc-body">
					<label class="aod-cd-field">
						<span class="aod-cd-label"><?php esc_html_e( 'Nom du produit', 'aod-client-dashboard' ); ?> *</span>
						<input type="text" name="name" required value="<?php echo esc_attr( $name ); ?>">
					</label>

					<label class="aod-cd-field">
						<span class="aod-cd-label"><?php esc_html_e( 'Description', 'aod-client-dashboard' ); ?></span>
						<textarea name="description" rows="6"><?php echo esc_textarea( $desc ); ?></textarea>
					</label>

					<label class="aod-cd-field">
						<span class="aod-cd-label"><?php esc_html_e( 'Arguments de vente (un par ligne)', 'aod-client-dashboard' ); ?></span>
						<textarea name="selling_points" rows="4" placeholder="<?php esc_attr_e( "Livraison rapide\nGarantie 1 an\nQualité premium", 'aod-client-dashboard' ); ?>"><?php echo esc_textarea( $points_text ); ?></textarea>
						<span class="aod-cd-note" style="font-size:12px;margin-top:2px"><?php esc_html_e( 'Affichés en liste à puces sur la page produit.', 'aod-client-dashboard' ); ?></span>
					</label>

					<div class="aod-cd-row2">
						<label class="aod-cd-field">
							<span class="aod-cd-label"><?php esc_html_e( 'Catégories', 'aod-client-dashboard' ); ?></span>
							<select name="category[]" multiple size="5" class="aod-cd-catselect">
								<?php if ( ! is_wp_error( $categories ) ) :
									foreach ( $categories as $cat ) :
										$sel = in_array( $cat->term_id, (array) $cat_ids, true ) ? ' selected' : '';
										echo '<option value="' . esc_attr( $cat->term_id ) . '"' . esc_attr( $sel ) . '>' . esc_html( $cat->name ) . '</option>';
									endforeach;
								endif; ?>
							</select>
							<span class="aod-cd-note" style="font-size:12px;margin-top:2px"><?php esc_html_e( 'Maintenez Ctrl (Cmd sur Mac) pour en sélectionner plusieurs.', 'aod-client-dashboard' ); ?></span>
						</label>
						<div class="aod-cd-field">
							<label class="aod-cd-field" style="margin:0">
								<span class="aod-cd-label"><?php esc_html_e( 'Nouvelle catégorie (optionnel)', 'aod-client-dashboard' ); ?></span>
								<input type="text" name="new_category" placeholder="<?php esc_attr_e( 'Crée et assigne une catégorie', 'aod-client-dashboard' ); ?>">
							</label>
							<label class="aod-cd-field" style="margin:14px 0 0">
								<span class="aod-cd-label"><?php esc_html_e( 'Statut', 'aod-client-dashboard' ); ?></span>
								<select name="status">
									<option value="publish" <?php selected( $status, 'publish' ); ?>><?php esc_html_e( 'En ligne', 'aod-client-dashboard' ); ?></option>
									<option value="draft" <?php selected( $status, 'draft' ); ?>><?php esc_html_e( 'Brouillon', 'aod-client-dashboard' ); ?></option>
								</select>
							</label>
						</div>
					</div>
				</div>
			</details>

			<!-- Section : Prix & promotion -->
			<details class="aod-cd-acc" open>
				<summary class="aod-cd-acc-sum"><span class="aod-cd-acc-ic">💰</span> <?php esc_html_e( 'Prix & promotion', 'aod-client-dashboard' ); ?></summary>
				<div class="aod-cd-acc-body">
					<div class="aod-cd-row2">
						<label class="aod-cd-field">
							<span class="aod-cd-label"><?php printf( esc_html__( 'Prix (%s)', 'aod-client-dashboard' ), esc_html( $currency ) ); ?> *</span>
							<input type="number" step="0.01" min="0" name="regular_price" required value="<?php echo esc_attr( $reg ); ?>">
							<span class="aod-cd-note" style="font-size:12px;margin-top:2px"><?php esc_html_e( 'Si vous ajoutez des variantes, ce prix sert de prix par défaut (utilisé quand une variante n’a pas son propre prix).', 'aod-client-dashboard' ); ?></span>
						</label>
						<label class="aod-cd-field">
							<span class="aod-cd-label"><?php esc_html_e( 'Prix promo (optionnel)', 'aod-client-dashboard' ); ?></span>
							<input type="number" step="0.01" min="0" name="sale_price" value="<?php echo esc_attr( $sale ); ?>">
						</label>
					</div>

					<div class="aod-cd-row2">
						<label class="aod-cd-field">
							<span class="aod-cd-label"><?php esc_html_e( 'Date début promo (optionnel)', 'aod-client-dashboard' ); ?></span>
							<input type="date" name="sale_from" value="<?php echo esc_attr( $sale_from ); ?>">
						</label>
						<label class="aod-cd-field">
							<span class="aod-cd-label"><?php esc_html_e( 'Date fin promo (optionnel)', 'aod-client-dashboard' ); ?></span>
							<input type="date" name="sale_to" value="<?php echo esc_attr( $sale_to ); ?>">
						</label>
					</div>

					<label class="aod-cd-field" style="max-width:280px">
						<span class="aod-cd-label"><?php printf( esc_html__( 'Prix d’achat (%s, optionnel)', 'aod-client-dashboard' ), esc_html( $currency ) ); ?></span>
						<input type="number" step="0.01" min="0" name="cost_price" value="<?php echo esc_attr( $cost ); ?>">
						<span class="aod-cd-note" style="font-size:12px;margin-top:2px"><?php esc_html_e( 'Sert au calcul de la marge (non affiché au client).', 'aod-client-dashboard' ); ?></span>
					</label>
				</div>
			</details>

			<!-- Section : Images -->
			<details class="aod-cd-acc">
				<summary class="aod-cd-acc-sum"><span class="aod-cd-acc-ic">🖼️</span> <?php esc_html_e( 'Images', 'aod-client-dashboard' ); ?> <span class="aod-cd-acc-sub"><?php esc_html_e( 'photo principale & galerie', 'aod-client-dashboard' ); ?></span></summary>
				<div class="aod-cd-acc-body aod-cd-grid2">
					<div class="aod-cd-field">
						<span class="aod-cd-label"><?php esc_html_e( 'Photo principale', 'aod-client-dashboard' ); ?></span>
						<div class="aod-cd-imgbox">
							<img class="aod-cd-imgprev" src="<?php echo esc_url( $img_url ); ?>" alt="" <?php echo $img_url ? '' : 'style="display:none"'; ?>>
							<span class="aod-cd-imgempty" <?php echo $img_url ? 'style="display:none"' : ''; ?>><?php esc_html_e( 'Aucune image', 'aod-client-dashboard' ); ?></span>
						</div>
						<input type="file" name="image" accept="image/*" class="aod-cd-imgfile">
					</div>

					<div class="aod-cd-field">
						<span class="aod-cd-label"><?php esc_html_e( 'Galerie (photos supplémentaires)', 'aod-client-dashboard' ); ?></span>
						<?php if ( $gallery_ids ) : ?>
							<div class="aod-cd-gallery">
								<?php foreach ( $gallery_ids as $gid ) :
									$gurl = wp_get_attachment_image_url( $gid, 'thumbnail' );
									if ( ! $gurl ) { continue; } ?>
									<label class="aod-cd-gallery-item">
										<img src="<?php echo esc_url( $gurl ); ?>" alt="">
										<span class="aod-cd-gallery-rm">
											<input type="checkbox" name="gallery_remove[]" value="<?php echo esc_attr( $gid ); ?>"> <?php esc_html_e( 'Retirer', 'aod-client-dashboard' ); ?>
										</span>
									</label>
								<?php endforeach; ?>
							</div>
						<?php endif; ?>
						<input type="file" name="gallery_images[]" accept="image/*" multiple class="aod-cd-galleryfile">
						<span class="aod-cd-note" style="font-size:12px;margin-top:2px"><?php esc_html_e( 'Ajoutez une ou plusieurs photos. Cochez « Retirer » pour enlever une photo existante.', 'aod-client-dashboard' ); ?></span>
					</div>
				</div>
			</details>

			<!-- Section : Stock & logistique -->
			<details class="aod-cd-acc">
				<summary class="aod-cd-acc-sum"><span class="aod-cd-acc-ic">📦</span> <?php esc_html_e( 'Stock & logistique', 'aod-client-dashboard' ); ?></summary>
				<div class="aod-cd-acc-body">
					<div class="aod-cd-field">
						<label class="aod-cd-check">
							<input type="checkbox" name="manage_stock" value="1" <?php checked( $manage ); ?> class="aod-cd-stock-toggle">
							<?php esc_html_e( 'Gérer le stock', 'aod-client-dashboard' ); ?>
						</label>
						<input type="number" min="0" name="stock_quantity" class="aod-cd-stock-qty" value="<?php echo esc_attr( (string) $qty ); ?>" placeholder="<?php esc_attr_e( 'Quantité', 'aod-client-dashboard' ); ?>" <?php echo $manage ? '' : 'style="display:none"'; ?>>
						<input type="number" min="0" name="low_stock_amount" class="aod-cd-stock-qty" value="<?php echo esc_attr( $low_stock ); ?>" placeholder="<?php esc_attr_e( 'Alerte stock bas (optionnel)', 'aod-client-dashboard' ); ?>" <?php echo $manage ? '' : 'style="display:none"'; ?>>
					</div>

					<div class="aod-cd-row2">
						<label class="aod-cd-field">
							<span class="aod-cd-label"><?php esc_html_e( 'Référence / SKU (optionnel)', 'aod-client-dashboard' ); ?></span>
							<input type="text" name="sku" value="<?php echo esc_attr( $sku ); ?>">
						</label>
						<label class="aod-cd-field">
							<span class="aod-cd-label"><?php esc_html_e( 'Poids (kg, optionnel)', 'aod-client-dashboard' ); ?></span>
							<input type="number" step="0.001" min="0" name="weight" value="<?php echo esc_attr( $weight ); ?>" placeholder="<?php esc_attr_e( 'ex : 0.5', 'aod-client-dashboard' ); ?>">
						</label>
					</div>
				</div>
			</details>

			<!-- Section : Variantes -->
			<details class="aod-cd-acc">
				<summary class="aod-cd-acc-sum"><span class="aod-cd-acc-ic">🎨</span> <?php esc_html_e( 'Variantes', 'aod-client-dashboard' ); ?> <span class="aod-cd-acc-sub"><?php esc_html_e( 'Taille, Couleur, Pointure… (optionnel)', 'aod-client-dashboard' ); ?></span></summary>
				<div class="aod-cd-acc-body">
					<div class="aod-cd-options" id="aod-cd-options">
						<p class="aod-cd-note" style="margin-top:0"><?php esc_html_e( 'Crée une section par caractéristique (Taille, Pointure, Couleur…). Pour chaque section, ajoute les valeurs que le client pourra choisir. Une section « avec photos » affiche des pastilles cliquables (couleurs, modèles…) ; chaque valeur peut ajouter un supplément de prix. Laisse vide pour un produit simple.', 'aod-client-dashboard' ); ?></p>

						<div class="aod-cd-size-presets">
							<span class="aod-cd-label" style="margin:0"><?php esc_html_e( 'Sections rapides :', 'aod-client-dashboard' ); ?></span>
							<button type="button" class="aod-cd-btn aod-cd-btn-sm aod-cd-opt-preset" data-label="<?php esc_attr_e( 'Taille', 'aod-client-dashboard' ); ?>" data-visual="0" data-values="S,M,L,XL,XXL"><?php esc_html_e( 'Tailles S–XXL', 'aod-client-dashboard' ); ?></button>
							<button type="button" class="aod-cd-btn aod-cd-btn-sm aod-cd-opt-preset" data-label="<?php esc_attr_e( 'Pointure', 'aod-client-dashboard' ); ?>" data-visual="0" data-values="35,36,37,38,39,40,41,42,43,44,45,46"><?php esc_html_e( 'Pointures 35–46', 'aod-client-dashboard' ); ?></button>
							<button type="button" class="aod-cd-btn aod-cd-btn-sm aod-cd-opt-preset" data-label="<?php esc_attr_e( 'Couleur', 'aod-client-dashboard' ); ?>" data-visual="1" data-values=""><?php esc_html_e( 'Couleur (avec photos)', 'aod-client-dashboard' ); ?></button>
						</div>

						<div class="aod-cd-opt-sections">
							<?php
							$si = 0;
							foreach ( $options as $section ) {
								$this->render_option_section( $si, $section );
								$si++;
							}
							?>
						</div>

						<button type="button" class="aod-cd-btn aod-cd-btn-sm aod-cd-opt-add" data-next="<?php echo esc_attr( (string) $si ); ?>">+ <?php esc_html_e( 'Ajouter une section', 'aod-client-dashboard' ); ?></button>

						<template id="aod-cd-opt-section-tpl"><?php $this->render_option_section( '{SI}', array( 'values' => array( array() ) ) ); ?></template>
						<?php $this->render_color_palette_popover(); ?>
					</div>
				</div>
			</details>

			<!-- Section : Offres (prix par quantité) -->
			<details class="aod-cd-acc"<?php echo $offers ? ' open' : ''; ?>>
				<summary class="aod-cd-acc-sum"><span class="aod-cd-acc-ic">🏷️</span> <?php esc_html_e( 'Offres', 'aod-client-dashboard' ); ?> <span class="aod-cd-acc-sub"><?php esc_html_e( 'packs « 2 produits », « 3 produits »… (optionnel)', 'aod-client-dashboard' ); ?></span></summary>
				<div class="aod-cd-acc-body">
					<div class="aod-cd-offers" id="aod-cd-offers">
						<p class="aod-cd-note" style="margin-top:0"><?php esc_html_e( 'Proposez d’acheter plusieurs unités de ce produit à un prix de lot avantageux. Indiquez le NOMBRE d’unités et le PRIX TOTAL du lot (ex. : 2 → 2500, 3 → 3300). Ce total doit être inférieur au prix normal multiplié par le nombre d’unités, sinon l’offre n’apporte aucune réduction et sera ignorée. Sur la page produit, le client verra une carte « 1 produit » par défaut, puis une carte par offre ; au clic, il choisit une variante pour chaque unité.', 'aod-client-dashboard' ); ?></p>

						<div class="aod-cd-offer-head">
							<span><?php esc_html_e( 'Nombre d’unités', 'aod-client-dashboard' ); ?></span>
							<span><?php printf( esc_html__( 'Prix total du lot (%s)', 'aod-client-dashboard' ), esc_html( $currency ) ); ?></span>
							<span></span>
						</div>

						<div class="aod-cd-offer-rows">
							<?php
							$oi = 0;
							foreach ( $offers as $row ) {
								$this->render_offer_row( $oi, $row );
								$oi++;
							}
							?>
						</div>

						<button type="button" class="aod-cd-btn aod-cd-btn-sm aod-cd-offer-add" data-next="<?php echo esc_attr( (string) $oi ); ?>">+ <?php esc_html_e( 'Ajouter une offre', 'aod-client-dashboard' ); ?></button>

						<template id="aod-cd-offer-tpl">
							<?php $this->render_offer_row( '__i__', array() ); ?>
						</template>
					</div>
				</div>
			</details>

			<div class="aod-cd-form-foot">
				<button type="submit" class="aod-cd-btn aod-cd-btn-primary"><?php esc_html_e( 'Enregistrer', 'aod-client-dashboard' ); ?></button>
				<a class="aod-cd-btn" href="<?php echo esc_url( $this->products_url() ); ?>"><?php esc_html_e( 'Annuler', 'aod-client-dashboard' ); ?></a>
				<span class="aod-cd-form-msg"></span>
			</div>
		</form>
		<?php
	}

	/**
	 * Normalise les sections d'options d'un produit pour l'édition.
	 *
	 * Lit la méta `_aod_options` ; à défaut, migre un ancien produit variable
	 * mono-axe (variations couleur) vers une section visuelle unique.
	 *
	 * @param WC_Product|null $product
	 * @return array Liste de [ 'label', 'visual', 'values' => [ ['name','price','image_id','img'], … ] ].
	 */
	protected function get_product_options( $product ) {
		if ( ! $product ) {
			return array();
		}
		$raw = $product->get_meta( '_aod_options' );
		$out = array();
		if ( is_array( $raw ) && $raw ) {
			foreach ( $raw as $sec ) {
				if ( ! is_array( $sec ) ) {
					continue;
				}
				$values = array();
				$src    = ( isset( $sec['values'] ) && is_array( $sec['values'] ) ) ? $sec['values'] : array();
				foreach ( $src as $val ) {
					$name = isset( $val['name'] ) ? (string) $val['name'] : '';
					if ( '' === $name ) {
						continue;
					}
					$img_id   = isset( $val['image_id'] ) ? (int) $val['image_id'] : 0;
					$values[] = array(
						'name'     => $name,
						'price'    => ( isset( $val['price'] ) && '' !== $val['price'] ) ? (string) $val['price'] : '',
						'image_id' => $img_id,
						'img'      => $img_id ? wp_get_attachment_image_url( $img_id, 'thumbnail' ) : '',
						'hex'      => isset( $val['hex'] ) ? $this->sanitize_hex( (string) $val['hex'] ) : '',
					);
				}
				if ( ! $values ) {
					continue;
				}
				$out[] = array(
					'label'  => isset( $sec['label'] ) ? (string) $sec['label'] : '',
					'visual' => ! empty( $sec['visual'] ),
					'values' => $values,
				);
			}
			if ( $out ) {
				return $out;
			}
		}

		// Compat : ancien produit variable mono-axe → une section visuelle unique.
		if ( $product->is_type( 'variable' ) ) {
			$label = (string) $product->get_meta( '_aod_variant_label' );
			if ( '' === $label ) {
				$label = __( 'Couleur', 'aod-client-dashboard' );
			}
			$values = array();
			foreach ( $product->get_children() as $cid ) {
				$v = wc_get_product( $cid );
				if ( ! $v ) {
					continue;
				}
				$atts = $v->get_attributes();
				$name = $atts ? (string) reset( $atts ) : '';
				if ( '' === $name ) {
					continue;
				}
				$img_id   = (int) $v->get_image_id();
				$values[] = array(
					'name'     => $name,
					'price'    => '',
					'image_id' => $img_id,
					'img'      => $img_id ? wp_get_attachment_image_url( $img_id, 'thumbnail' ) : '',
				);
			}
			if ( $values ) {
				$out[] = array( 'label' => $label, 'visual' => true, 'values' => $values );
			}
		}
		return $out;
	}

	/**
	 * Une section d'options (Taille, Couleur…) dans l'éditeur de produit.
	 *
	 * @param int|string $si      Index de section (ou « {SI} » pour le gabarit JS).
	 * @param array      $section label, visual, values[].
	 */
	protected function render_option_section( $si, $section ) {
		$section = wp_parse_args( $section, array( 'label' => '', 'visual' => false, 'values' => array() ) );
		$s       = esc_attr( (string) $si );
		$visual  = ! empty( $section['visual'] );
		$values  = is_array( $section['values'] ) ? $section['values'] : array();
		?>
		<div class="aod-cd-opt-section" data-si="<?php echo $s; ?>">
			<div class="aod-cd-opt-head">
				<input type="text" name="opt_label[<?php echo $s; ?>]" class="aod-cd-opt-label" value="<?php echo esc_attr( $section['label'] ); ?>" placeholder="<?php esc_attr_e( 'ex : Taille, Couleur, Pointure…', 'aod-client-dashboard' ); ?>">
				<label class="aod-cd-opt-visual">
					<input type="checkbox" name="opt_visual[<?php echo $s; ?>]" value="1" class="aod-cd-opt-visual-cb" <?php checked( $visual ); ?>>
					<?php esc_html_e( 'Avec photos', 'aod-client-dashboard' ); ?>
				</label>
				<button type="button" class="aod-cd-color-del aod-cd-opt-sec-del" aria-label="<?php esc_attr_e( 'Supprimer cette section', 'aod-client-dashboard' ); ?>">&times;</button>
			</div>
			<div class="aod-cd-opt-values<?php echo $visual ? ' is-visual' : ''; ?>">
				<?php
				$vi = 0;
				foreach ( $values as $val ) {
					$this->render_option_value( $si, $vi, $val );
					$vi++;
				}
				?>
			</div>
			<button type="button" class="aod-cd-btn aod-cd-btn-sm aod-cd-opt-val-add" data-next="<?php echo esc_attr( (string) $vi ); ?>">+ <?php esc_html_e( 'Ajouter une valeur', 'aod-client-dashboard' ); ?></button>
			<template class="aod-cd-opt-val-tpl"><?php $this->render_option_value( $si, '{VI}', array() ); ?></template>
		</div>
		<?php
	}

	/**
	 * Une valeur d'option (Rouge, L, 42…) avec photo et supplément de prix optionnels.
	 *
	 * @param int|string $si  Index de section.
	 * @param int|string $vi  Index de valeur (ou « {VI} » pour le gabarit JS).
	 * @param array      $val name, price, image_id, img.
	 */
	protected function render_option_value( $si, $vi, $val ) {
		$val = wp_parse_args( $val, array( 'name' => '', 'price' => '', 'image_id' => 0, 'img' => '', 'hex' => '' ) );
		$s   = esc_attr( (string) $si );
		$v   = esc_attr( (string) $vi );
		$hex = $this->sanitize_hex( (string) $val['hex'] );
		?>
		<div class="aod-cd-opt-value" data-vi="<?php echo $v; ?>">
			<label class="aod-cd-opt-imgbox">
				<img class="aod-cd-opt-imgprev" src="<?php echo esc_url( $val['img'] ); ?>" alt="" <?php echo $val['img'] ? '' : 'style="display:none"'; ?>>
				<span class="aod-cd-opt-imgempty" <?php echo $val['img'] ? 'style="display:none"' : ''; ?>>📷</span>
				<input type="hidden" name="opt_value_imgid[<?php echo $s; ?>][<?php echo $v; ?>]" value="<?php echo esc_attr( (string) $val['image_id'] ); ?>">
				<input type="file" name="opt_img[<?php echo $s; ?>][<?php echo $v; ?>]" accept="image/*" class="aod-cd-opt-imgfile">
			</label>
			<button type="button" class="aod-cd-opt-swatch<?php echo $hex ? ' has-color' : ''; ?>" style="<?php echo $hex ? 'background-color:' . esc_attr( $hex ) : ''; ?>" aria-label="<?php esc_attr_e( 'Choisir une couleur', 'aod-client-dashboard' ); ?>" title="<?php esc_attr_e( 'Choisir une couleur prédéfinie', 'aod-client-dashboard' ); ?>">🎨</button>
			<input type="hidden" name="opt_value_hex[<?php echo $s; ?>][<?php echo $v; ?>]" class="aod-cd-opt-hex" value="<?php echo esc_attr( $hex ); ?>">
			<input type="text" name="opt_value_name[<?php echo $s; ?>][<?php echo $v; ?>]" class="aod-cd-opt-name" value="<?php echo esc_attr( $val['name'] ); ?>" placeholder="<?php esc_attr_e( 'ex : Rouge, L, 42…', 'aod-client-dashboard' ); ?>">
			<span class="aod-cd-opt-plus" aria-hidden="true">+</span>
			<input type="number" step="0.01" min="0" name="opt_value_price[<?php echo $s; ?>][<?php echo $v; ?>]" class="aod-cd-opt-price" value="<?php echo esc_attr( $val['price'] ); ?>" placeholder="<?php esc_attr_e( 'supplément', 'aod-client-dashboard' ); ?>">
			<button type="button" class="aod-cd-color-del aod-cd-opt-val-del" aria-label="<?php esc_attr_e( 'Supprimer cette valeur', 'aod-client-dashboard' ); ?>">&times;</button>
		</div>
		<?php
	}

	/**
	 * Palette de couleurs prédéfinies (nom → code hex réel). Permet au marchand
	 * de piocher une couleur sans la saisir à la main ; la pastille affiche la
	 * vraie couleur côté client (formulaire de commande).
	 *
	 * @return array Liste de [ nom, hex ].
	 */
	protected function color_palette() {
		return array(
			array( __( 'Noir', 'aod-client-dashboard' ), '#1a1a1a' ),
			array( __( 'Blanc', 'aod-client-dashboard' ), '#ffffff' ),
			array( __( 'Gris', 'aod-client-dashboard' ), '#9ca3af' ),
			array( __( 'Gris clair', 'aod-client-dashboard' ), '#d1d5db' ),
			array( __( 'Gris foncé', 'aod-client-dashboard' ), '#4b5563' ),
			array( __( 'Beige', 'aod-client-dashboard' ), '#e8d9b5' ),
			array( __( 'Crème', 'aod-client-dashboard' ), '#f7f1de' ),
			array( __( 'Marron', 'aod-client-dashboard' ), '#7b4a2b' ),
			array( __( 'Bordeaux', 'aod-client-dashboard' ), '#5c1a2b' ),
			array( __( 'Rouge', 'aod-client-dashboard' ), '#e02b2b' ),
			array( __( 'Rouge foncé', 'aod-client-dashboard' ), '#9b1c1c' ),
			array( __( 'Rose', 'aod-client-dashboard' ), '#f48fb1' ),
			array( __( 'Fuchsia', 'aod-client-dashboard' ), '#d6336c' ),
			array( __( 'Corail', 'aod-client-dashboard' ), '#ff6f61' ),
			array( __( 'Orange', 'aod-client-dashboard' ), '#f97316' ),
			array( __( 'Moutarde', 'aod-client-dashboard' ), '#c99700' ),
			array( __( 'Jaune', 'aod-client-dashboard' ), '#f5c518' ),
			array( __( 'Or', 'aod-client-dashboard' ), '#d4af37' ),
			array( __( 'Vert clair', 'aod-client-dashboard' ), '#8bc34a' ),
			array( __( 'Vert', 'aod-client-dashboard' ), '#2e9e4f' ),
			array( __( 'Vert foncé', 'aod-client-dashboard' ), '#1b5e20' ),
			array( __( 'Kaki', 'aod-client-dashboard' ), '#6b6b3a' ),
			array( __( 'Turquoise', 'aod-client-dashboard' ), '#1abc9c' ),
			array( __( 'Cyan', 'aod-client-dashboard' ), '#06b6d4' ),
			array( __( 'Bleu ciel', 'aod-client-dashboard' ), '#60a5fa' ),
			array( __( 'Bleu', 'aod-client-dashboard' ), '#2563eb' ),
			array( __( 'Bleu marine', 'aod-client-dashboard' ), '#1e3a5f' ),
			array( __( 'Mauve', 'aod-client-dashboard' ), '#b39ddb' ),
			array( __( 'Violet', 'aod-client-dashboard' ), '#7c3aed' ),
			array( __( 'Argent', 'aod-client-dashboard' ), '#c0c0c0' ),
		);
	}

	/**
	 * Valide un code couleur hexadécimal #RRGGBB. Renvoie '' si invalide.
	 *
	 * @param string $raw
	 * @return string
	 */
	protected function sanitize_hex( $raw ) {
		$raw = trim( (string) $raw );
		return preg_match( '/^#[0-9a-fA-F]{6}$/', $raw ) ? strtolower( $raw ) : '';
	}

	/**
	 * Popover de la palette de couleurs prédéfinies, rendu une seule fois dans
	 * le formulaire produit. Ouvert via les pastilles 🎨 des valeurs (JS).
	 */
	protected function render_color_palette_popover() {
		?>
		<div class="aod-cd-palette" id="aod-cd-palette" hidden>
			<div class="aod-cd-palette-grid">
				<?php foreach ( $this->color_palette() as $c ) :
					list( $cname, $chex ) = $c; ?>
					<button type="button" class="aod-cd-palette-sw" data-name="<?php echo esc_attr( $cname ); ?>" data-hex="<?php echo esc_attr( $chex ); ?>" title="<?php echo esc_attr( $cname ); ?>">
						<span class="aod-cd-palette-dot" style="background-color:<?php echo esc_attr( $chex ); ?>"></span>
						<span class="aod-cd-palette-lbl"><?php echo esc_html( $cname ); ?></span>
					</button>
				<?php endforeach; ?>
			</div>
		</div>
		<?php
	}

	/**
	 * Une ligne de palier de prix par quantité.
	 *
	 * @param int|string $i   Index de la ligne (ou « __i__ » pour le gabarit JS).
	 * @param array      $row min, price.
	 */
	/**
	 * Offres d'un produit (N unités à prix de lot), pour l'édition.
	 *
	 * Lit la méta `_aod_offers` ; à défaut, migre l'ancienne méta `_aod_qty_tiers`
	 * (prix par pièce → prix total du lot). La carte « 1 produit » est implicite et
	 * n'est jamais stockée ici.
	 *
	 * @param WC_Product|null $product
	 * @return array Liste de [ 'qty' => int, 'price' => float (total du lot) ], triée par qty.
	 */
	protected function get_product_offers( $product ) {
		if ( ! $product ) {
			return array();
		}
		$offers = array();
		$raw    = $product->get_meta( '_aod_offers' );
		if ( is_array( $raw ) && $raw ) {
			foreach ( $raw as $o ) {
				$qty   = isset( $o['qty'] ) ? (int) $o['qty'] : 0;
				$price = isset( $o['price'] ) ? (float) $o['price'] : 0;
				if ( $qty >= 2 && $price > 0 ) {
					$offers[] = array( 'qty' => $qty, 'price' => $price );
				}
			}
			if ( $offers ) {
				return $offers;
			}
		}

		// Compat : ancien « prix par quantité » (prix par pièce) → offres (prix total du lot).
		$tiers = $product->get_meta( '_aod_qty_tiers' );
		if ( is_array( $tiers ) ) {
			foreach ( $tiers as $t ) {
				$min = isset( $t['min'] ) ? (int) $t['min'] : 0;
				$pp  = isset( $t['price'] ) ? (float) $t['price'] : 0;
				if ( $min >= 2 && $pp > 0 ) {
					$offers[] = array( 'qty' => $min, 'price' => $pp * $min );
				}
			}
		}
		usort( $offers, function ( $a, $b ) {
			return $a['qty'] - $b['qty'];
		} );
		return $offers;
	}

	/**
	 * Une ligne d'offre (nombre d'unités + prix total du lot).
	 *
	 * @param int|string $i   Index (ou « __i__ » pour le gabarit JS).
	 * @param array      $row qty, price (total du lot).
	 */
	protected function render_offer_row( $i, $row ) {
		$row = wp_parse_args( $row, array( 'qty' => '', 'price' => '' ) );
		$idx = esc_attr( (string) $i );
		?>
		<div class="aod-cd-offer-row" data-row="<?php echo $idx; ?>">
			<input type="number" min="2" step="1" name="offer_qty[<?php echo $idx; ?>]" value="<?php echo esc_attr( (string) $row['qty'] ); ?>" placeholder="<?php esc_attr_e( 'ex : 2', 'aod-client-dashboard' ); ?>">
			<input type="number" min="0" step="0.01" name="offer_price[<?php echo $idx; ?>]" value="<?php echo esc_attr( (string) $row['price'] ); ?>" placeholder="<?php esc_attr_e( 'ex : 2500 (pour 2)', 'aod-client-dashboard' ); ?>">
			<button type="button" class="aod-cd-color-del aod-cd-offer-del" aria-label="<?php esc_attr_e( 'Supprimer cette offre', 'aod-client-dashboard' ); ?>">&times;</button>
		</div>
		<?php
	}

	/* ============================================================
	 * AJAX : enregistrer (créer / mettre à jour) un produit
	 * ========================================================== */

	public function ajax_save_product() {
		check_ajax_referer( 'aod_cd', 'nonce' );
		if ( ! current_user_can( 'edit_products' ) ) {
			wp_send_json_error( array( 'message' => __( 'Accès refusé.', 'aod-client-dashboard' ) ), 403 );
		}

		$pid  = isset( $_POST['product_id'] ) ? absint( $_POST['product_id'] ) : 0;
		$name = isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '';
		if ( '' === $name ) {
			wp_send_json_error( array( 'message' => __( 'Le nom du produit est obligatoire.', 'aod-client-dashboard' ) ), 400 );
		}

		// Sections d'options (Taille, Couleur…) postées. Au moins une section valide → variantes.
		$options = $this->collect_options();

		$product = $pid ? wc_get_product( $pid ) : new WC_Product_Simple();
		if ( ! $product ) {
			wp_send_json_error( array( 'message' => __( 'Produit introuvable.', 'aod-client-dashboard' ) ), 404 );
		}

		$product->set_name( $name );
		$product->set_description( isset( $_POST['description'] ) ? wp_kses_post( wp_unslash( $_POST['description'] ) ) : '' );

		$reg  = isset( $_POST['regular_price'] ) ? wc_format_decimal( wp_unslash( $_POST['regular_price'] ) ) : '';
		$sale = isset( $_POST['sale_price'] ) ? wc_format_decimal( wp_unslash( $_POST['sale_price'] ) ) : '';

		// Garde-fou : le prix promo ne peut pas dépasser le prix normal.
		if ( '' !== $sale && '' !== $reg && (float) $sale > (float) $reg ) {
			wp_send_json_error( array( 'message' => __( 'Le prix promo doit être inférieur ou égal au prix normal.', 'aod-client-dashboard' ) ), 400 );
		}

		// SKU (peut lever une exception si doublon).
		if ( isset( $_POST['sku'] ) ) {
			$sku = sanitize_text_field( wp_unslash( $_POST['sku'] ) );
			try {
				$product->set_sku( $sku );
			} catch ( Exception $e ) {
				wp_send_json_error( array( 'message' => __( 'Cette référence (SKU) est déjà utilisée.', 'aod-client-dashboard' ) ), 400 );
			}
		}

		$status = ( isset( $_POST['status'] ) && 'draft' === $_POST['status'] ) ? 'draft' : 'publish';
		$product->set_status( $status );

		// Catégories : sélection multiple + éventuelle nouvelle.
		$cat_ids = array();
		if ( ! empty( $_POST['category'] ) ) {
			foreach ( (array) $_POST['category'] as $c ) {
				$cat_ids[] = absint( $c );
			}
		}
		if ( ! empty( $_POST['new_category'] ) ) {
			$new = sanitize_text_field( wp_unslash( $_POST['new_category'] ) );
			$term = term_exists( $new, 'product_cat' );
			if ( ! $term ) {
				$term = wp_insert_term( $new, 'product_cat' );
			}
			if ( ! is_wp_error( $term ) && isset( $term['term_id'] ) ) {
				$cat_ids[] = (int) $term['term_id'];
			}
		}
		// Toujours appliquer (permet aussi de tout retirer si rien n'est sélectionné).
		$product->set_category_ids( array_values( array_unique( array_filter( $cat_ids ) ) ) );

		// Poids (expédition) + prix d'achat (marge) + arguments de vente.
		if ( isset( $_POST['weight'] ) ) {
			$w = wc_format_decimal( wp_unslash( $_POST['weight'] ) );
			$product->set_weight( '' !== $w ? $w : '' );
		}
		if ( isset( $_POST['cost_price'] ) ) {
			$cost = wc_format_decimal( wp_unslash( $_POST['cost_price'] ) );
			if ( '' !== $cost ) {
				$product->update_meta_data( '_aod_cost_price', $cost );
			} else {
				$product->delete_meta_data( '_aod_cost_price' );
			}
		}
		if ( isset( $_POST['selling_points'] ) ) {
			$lines  = preg_split( '/\r\n|\r|\n/', (string) wp_unslash( $_POST['selling_points'] ) );
			$points = array();
			foreach ( $lines as $l ) {
				$l = sanitize_text_field( trim( $l ) );
				if ( '' !== $l ) {
					$points[] = $l;
				}
			}
			if ( $points ) {
				$product->update_meta_data( '_aod_selling_points', $points );
			} else {
				$product->delete_meta_data( '_aod_selling_points' );
			}
		}

		// Programmation de la promo (dates de début / fin).
		$product->set_date_on_sale_from( ! empty( $_POST['sale_from'] ) ? sanitize_text_field( wp_unslash( $_POST['sale_from'] ) ) : '' );
		$product->set_date_on_sale_to( ! empty( $_POST['sale_to'] ) ? sanitize_text_field( wp_unslash( $_POST['sale_to'] ) ) : '' );

		// Produit toujours simple désormais : prix + stock classiques.
		$product->set_regular_price( $reg );
		$product->set_sale_price( '' !== $sale ? $sale : '' );
		$manage = ! empty( $_POST['manage_stock'] );
		$product->set_manage_stock( $manage );
		if ( $manage ) {
			$qty = isset( $_POST['stock_quantity'] ) ? wc_stock_amount( wp_unslash( $_POST['stock_quantity'] ) ) : 0;
			$product->set_stock_quantity( $qty );
			$product->set_stock_status( $qty > 0 ? 'instock' : 'outofstock' );
			$low = isset( $_POST['low_stock_amount'] ) ? trim( (string) wp_unslash( $_POST['low_stock_amount'] ) ) : '';
			$product->set_low_stock_amount( '' !== $low ? wc_stock_amount( $low ) : '' );
		} else {
			$product->set_low_stock_amount( '' );
		}

		// Libellé d'axe mono-variante : obsolète (remplacé par les sections d'options).
		$product->delete_meta_data( '_aod_variant_label' );

		if ( ! $options ) {
			$product->delete_meta_data( '_aod_options' );
		}

		// Pack « produits différents » : éditeur retiré (fusionné dans les Offres).
		// On nettoie les métas legacy pour migrer proprement les anciens produits.
		$product->delete_meta_data( '_aod_is_pack' );
		$product->delete_meta_data( '_aod_pack_items' );

		// Offres (prix par quantité) : N unités de ce produit à prix de lot — compatibles
		// avec les sections d'options. Référence = PRIX NORMAL (jamais la promo) : une offre
		// est une remise sur le prix habituel, pas sur une promo temporaire. Sinon un produit
		// en promo ferait disparaître des offres pourtant avantageuses par rapport au prix normal.
		$base           = (float) $reg;
		$dropped_offers = 0;
		$offers         = $this->collect_offers( $base, $dropped_offers );
		if ( $offers ) {
			$product->update_meta_data( '_aod_offers', $offers );
		} else {
			$product->delete_meta_data( '_aod_offers' );
		}
		// Legacy migré vers `_aod_offers` : on retire l'ancienne méta paliers.
		$product->delete_meta_data( '_aod_qty_tiers' );

		$product->save();
		$new_id = $product->get_id();

		// Produit toujours simple désormais : on convertit d'anciens produits variables
		// (suppression des variations + attributs) avant de basculer le type.
		if ( ! $product->is_type( 'simple' ) ) {
			foreach ( $product->get_children() as $cid ) {
				$old = wc_get_product( $cid );
				if ( $old ) {
					$old->delete( true );
				}
			}
			$product->set_attributes( array() );
			$product->save();
			wp_set_object_terms( $new_id, 'simple', 'product_type' );
			$product = wc_get_product( $new_id );
		}

		// Photo principale (upload optionnel) — sert d'image par défaut.
		if ( ! empty( $_FILES['image']['name'] ) ) {
			require_once ABSPATH . 'wp-admin/includes/image.php';
			require_once ABSPATH . 'wp-admin/includes/file.php';
			require_once ABSPATH . 'wp-admin/includes/media.php';
			$att_id = media_handle_upload( 'image', $new_id );
			if ( is_wp_error( $att_id ) ) {
				wp_send_json_error( array( 'message' => __( 'Produit enregistré, mais échec de l’image : ', 'aod-client-dashboard' ) . $att_id->get_error_message() ), 200 );
			}
			set_post_thumbnail( $new_id, $att_id );
		}

		// Galerie : retrait des photos cochées + ajout des nouvelles.
		$this->save_gallery( $product, $new_id );

		// Sections d'options : upload des photos de valeurs puis enregistrement de la méta.
		if ( $options ) {
			$options = $this->upload_option_images( $options, $new_id );
			$product->update_meta_data( '_aod_options', $this->normalize_options( $options ) );
			$product->save();
		}

		$data = array(
			'message'  => $pid ? __( 'Produit mis à jour.', 'aod-client-dashboard' ) : __( 'Produit créé.', 'aod-client-dashboard' ),
			'redirect' => $this->products_url(),
		);
		if ( $dropped_offers > 0 ) {
			$data['warning'] = sprintf(
				/* translators: %d: nombre d'offres ignorées */
				_n(
					'%d offre a été ignorée : le prix total du lot doit être INFÉRIEUR au prix normal multiplié par le nombre d’unités (sinon l’offre n’apporte aucune réduction).',
					'%d offres ont été ignorées : le prix total du lot doit être INFÉRIEUR au prix normal multiplié par le nombre d’unités (sinon l’offre n’apporte aucune réduction).',
					$dropped_offers,
					'aod-client-dashboard'
				),
				$dropped_offers
			);
		}
		wp_send_json_success( $data );
	}

	/**
	 * Rassemble les sections d'options postées (sections et valeurs valides uniquement).
	 *
	 * Conserve les index postés (clés associatives) afin que l'upload des photos
	 * puisse retrouver le bon champ fichier opt_img[si][vi]. La normalisation en
	 * liste séquentielle est faite plus tard par normalize_options().
	 *
	 * @return array
	 */
	protected function collect_options() {
		if ( empty( $_POST['opt_label'] ) || ! is_array( $_POST['opt_label'] ) ) {
			return array();
		}
		$labels = wp_unslash( $_POST['opt_label'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$out    = array();
		foreach ( $labels as $si => $raw_label ) {
			$label  = sanitize_text_field( trim( (string) $raw_label ) );
			$visual = ! empty( $_POST['opt_visual'][ $si ] );
			$names  = ( isset( $_POST['opt_value_name'][ $si ] ) && is_array( $_POST['opt_value_name'][ $si ] ) )
				? wp_unslash( $_POST['opt_value_name'][ $si ] ) // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
				: array();
			$values = array();
			foreach ( $names as $vi => $raw_name ) {
				$name = sanitize_text_field( trim( (string) $raw_name ) );
				if ( '' === $name ) {
					continue;
				}
				$price = isset( $_POST['opt_value_price'][ $si ][ $vi ] ) ? wc_format_decimal( wp_unslash( $_POST['opt_value_price'][ $si ][ $vi ] ) ) : '';
				if ( '' !== $price && (float) $price <= 0 ) {
					$price = '';
				}
				$imgid = ( $visual && isset( $_POST['opt_value_imgid'][ $si ][ $vi ] ) ) ? absint( $_POST['opt_value_imgid'][ $si ][ $vi ] ) : 0;
				$hex   = isset( $_POST['opt_value_hex'][ $si ][ $vi ] ) ? $this->sanitize_hex( (string) wp_unslash( $_POST['opt_value_hex'][ $si ][ $vi ] ) ) : '';
				$values[ $vi ] = array(
					'name'     => $name,
					'price'    => $price,
					'image_id' => $imgid,
					'hex'      => $hex,
				);
			}
			if ( '' === $label || ! $values ) {
				continue;
			}
			$out[ $si ] = array(
				'label'  => $label,
				'visual' => $visual,
				'values' => $values,
			);
		}
		return $out;
	}

	/**
	 * Upload des photos de valeurs (champ fichier opt_img[si][vi]) et fusion des
	 * identifiants d'attachement dans les sections. Conserve l'image existante si
	 * aucun nouveau fichier n'est fourni.
	 *
	 * @param array $options Sections issues de collect_options() (clés postées).
	 * @param int   $pid     Produit parent (pour rattacher les médias).
	 * @return array
	 */
	protected function upload_option_images( $options, $pid ) {
		if ( empty( $_FILES['opt_img']['name'] ) || ! is_array( $_FILES['opt_img']['name'] ) ) {
			return $options;
		}
		require_once ABSPATH . 'wp-admin/includes/image.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';

		$files = $_FILES['opt_img'];
		foreach ( $options as $si => $section ) {
			if ( empty( $section['visual'] ) ) {
				continue;
			}
			foreach ( $section['values'] as $vi => $value ) {
				if ( empty( $files['name'][ $si ][ $vi ] ) ) {
					continue;
				}
				$_FILES['opt_img_single'] = array(
					'name'     => $files['name'][ $si ][ $vi ],
					'type'     => $files['type'][ $si ][ $vi ],
					'tmp_name' => $files['tmp_name'][ $si ][ $vi ],
					'error'    => $files['error'][ $si ][ $vi ],
					'size'     => $files['size'][ $si ][ $vi ],
				);
				$att = media_handle_upload( 'opt_img_single', $pid );
				if ( ! is_wp_error( $att ) ) {
					$options[ $si ]['values'][ $vi ]['image_id'] = (int) $att;
				}
			}
		}
		unset( $_FILES['opt_img_single'] );
		return $options;
	}

	/**
	 * Réindexe les sections/valeurs en listes séquentielles et nettoie les champs
	 * pour le stockage en méta `_aod_options`.
	 *
	 * @param array $options
	 * @return array
	 */
	protected function normalize_options( $options ) {
		$out = array();
		foreach ( $options as $section ) {
			$values = array();
			foreach ( $section['values'] as $value ) {
				$values[] = array(
					'name'     => (string) $value['name'],
					'price'    => ( isset( $value['price'] ) && '' !== $value['price'] ) ? (string) $value['price'] : '',
					'image_id' => isset( $value['image_id'] ) ? (int) $value['image_id'] : 0,
					'hex'      => isset( $value['hex'] ) ? $this->sanitize_hex( (string) $value['hex'] ) : '',
				);
			}
			if ( ! $values ) {
				continue;
			}
			$out[] = array(
				'label'  => (string) $section['label'],
				'visual' => ! empty( $section['visual'] ),
				'values' => $values,
			);
		}
		return $out;
	}

	/**
	 * Rassemble les paliers de prix par quantité postés (lignes valides uniquement).
	 *
	 * Une ligne est retenue si : quantité ≥ 2 et prix > 0. Le prix unitaire doit
	 * être inférieur au prix de base (sinon le palier n'a pas d'intérêt et est ignoré).
	 * Le résultat est trié par quantité croissante.
	 *
	 * @param float $base_price Prix unitaire de référence (promo sinon normal).
	 * @return array Liste de [ 'min' => int, 'price' => float ].
	 */
	protected function collect_offers( $base_price, &$dropped = 0 ) {
		$dropped = 0; // Nb d'offres renseignées mais écartées faute de réduction réelle.
		if ( empty( $_POST['offer_qty'] ) || ! is_array( $_POST['offer_qty'] ) ) {
			return array();
		}
		$qtys   = wp_unslash( $_POST['offer_qty'] );   // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$prices = isset( $_POST['offer_price'] ) ? wp_unslash( $_POST['offer_price'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$offers = array();
		$seen   = array();
		foreach ( $qtys as $i => $raw_qty ) {
			$qty   = absint( $raw_qty );
			// Le champ contient le PRIX TOTAL DU LOT ; on le stocke tel quel.
			$total = isset( $prices[ $i ] ) ? (float) wc_format_decimal( $prices[ $i ] ) : 0;
			if ( $qty < 2 || $total <= 0 ) {
				continue; // Ligne vide ou incomplète : on l'ignore sans alerter.
			}
			if ( $base_price > 0 && $total >= $base_price * $qty ) {
				$dropped++; // Lot pas plus avantageux que l'achat à l'unité : ignoré + alerte.
				continue;
			}
			if ( isset( $seen[ $qty ] ) ) {
				continue; // Doublon de quantité : on garde le premier.
			}
			$seen[ $qty ] = true;
			$offers[]     = array( 'qty' => $qty, 'price' => $total );
		}
		usort( $offers, function ( $a, $b ) {
			return $a['qty'] - $b['qty'];
		} );
		return $offers;
	}

	/**
	 * Met à jour la galerie d'images d'un produit : retire les images cochées,
	 * ajoute les nouveaux fichiers uploadés (champ multiple gallery_images[]).
	 *
	 * @param WC_Product $product
	 * @param int        $new_id
	 */
	protected function save_gallery( $product, $new_id ) {
		$gallery = $product->get_gallery_image_ids();

		// Retraits demandés.
		if ( ! empty( $_POST['gallery_remove'] ) && is_array( $_POST['gallery_remove'] ) ) {
			$remove  = array_map( 'absint', wp_unslash( $_POST['gallery_remove'] ) );
			$gallery = array_values( array_diff( $gallery, $remove ) );
		}

		// Ajouts (upload multiple).
		if ( ! empty( $_FILES['gallery_images']['name'][0] ) ) {
			require_once ABSPATH . 'wp-admin/includes/image.php';
			require_once ABSPATH . 'wp-admin/includes/file.php';
			require_once ABSPATH . 'wp-admin/includes/media.php';
			$files = $_FILES['gallery_images'];
			$count = count( $files['name'] );
			for ( $i = 0; $i < $count; $i++ ) {
				if ( empty( $files['name'][ $i ] ) ) {
					continue;
				}
				$_FILES['gallery_single'] = array(
					'name'     => $files['name'][ $i ],
					'type'     => $files['type'][ $i ],
					'tmp_name' => $files['tmp_name'][ $i ],
					'error'    => $files['error'][ $i ],
					'size'     => $files['size'][ $i ],
				);
				$att = media_handle_upload( 'gallery_single', $new_id );
				if ( ! is_wp_error( $att ) ) {
					$gallery[] = (int) $att;
				}
			}
			unset( $_FILES['gallery_single'] );
		}

		$product->set_gallery_image_ids( array_values( array_unique( array_filter( $gallery ) ) ) );
		$product->save();
	}

	/* ============================================================
	 * AJAX : supprimer (corbeille) un produit
	 * ========================================================== */

	public function ajax_delete_product() {
		check_ajax_referer( 'aod_cd', 'nonce' );
		if ( ! current_user_can( 'delete_products' ) ) {
			wp_send_json_error( array( 'message' => __( 'Accès refusé.', 'aod-client-dashboard' ) ), 403 );
		}
		$pid     = isset( $_POST['product_id'] ) ? absint( $_POST['product_id'] ) : 0;
		$product = $pid ? wc_get_product( $pid ) : false;
		if ( ! $product ) {
			wp_send_json_error( array( 'message' => __( 'Produit introuvable.', 'aod-client-dashboard' ) ), 404 );
		}
		$product->delete( false ); // false = corbeille (réversible), pas suppression définitive.
		wp_send_json_success( array( 'message' => __( 'Produit déplacé dans la corbeille.', 'aod-client-dashboard' ) ) );
	}

	/* ============================================================
	 * AJAX : catégories de produits (créer / renommer / supprimer)
	 * ========================================================== */

	/**
	 * Crée (term_id absent ou 0) ou renomme (term_id fourni) une catégorie de
	 * produits. Renvoie l'identifiant, le nom et le compteur à jour.
	 */
	public function ajax_save_category() {
		check_ajax_referer( 'aod_cd', 'nonce' );
		if ( ! current_user_can( 'edit_products' ) ) {
			wp_send_json_error( array( 'message' => __( 'Accès refusé.', 'aod-client-dashboard' ) ), 403 );
		}
		$term_id = isset( $_POST['term_id'] ) ? absint( $_POST['term_id'] ) : 0;
		$name    = isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '';
		if ( '' === $name ) {
			wp_send_json_error( array( 'message' => __( 'Le nom de la catégorie est obligatoire.', 'aod-client-dashboard' ) ), 400 );
		}

		if ( $term_id ) {
			$res = wp_update_term( $term_id, 'product_cat', array( 'name' => $name ) );
		} else {
			$res = wp_insert_term( $name, 'product_cat' );
		}
		if ( is_wp_error( $res ) ) {
			$msg = $res->get_error_message();
			if ( 'term_exists' === $res->get_error_code() ) {
				$msg = __( 'Cette catégorie existe déjà.', 'aod-client-dashboard' );
			}
			wp_send_json_error( array( 'message' => $msg ), 400 );
		}

		$id   = is_array( $res ) ? (int) $res['term_id'] : (int) $term_id;
		$term = get_term( $id, 'product_cat' );
		wp_send_json_success( array(
			'message' => $term_id ? __( 'Catégorie renommée.', 'aod-client-dashboard' ) : __( 'Catégorie créée.', 'aod-client-dashboard' ),
			'id'      => $id,
			'name'    => $term && ! is_wp_error( $term ) ? $term->name : $name,
			'count'   => $term && ! is_wp_error( $term ) ? (int) $term->count : 0,
		) );
	}

	/**
	 * Supprime une catégorie de produits. Les produits rattachés sont conservés.
	 */
	public function ajax_delete_category() {
		check_ajax_referer( 'aod_cd', 'nonce' );
		if ( ! current_user_can( 'edit_products' ) ) {
			wp_send_json_error( array( 'message' => __( 'Accès refusé.', 'aod-client-dashboard' ) ), 403 );
		}
		$term_id = isset( $_POST['term_id'] ) ? absint( $_POST['term_id'] ) : 0;
		if ( ! $term_id ) {
			wp_send_json_error( array( 'message' => __( 'Catégorie introuvable.', 'aod-client-dashboard' ) ), 400 );
		}
		$res = wp_delete_term( $term_id, 'product_cat' );
		if ( is_wp_error( $res ) || ! $res ) {
			wp_send_json_error( array( 'message' => __( 'Suppression impossible.', 'aod-client-dashboard' ) ), 400 );
		}
		wp_send_json_success( array( 'message' => __( 'Catégorie supprimée.', 'aod-client-dashboard' ) ) );
	}

	/* ============================================================
	 * Section : Livraison (tarifs wilayas + transporteurs)
	 * ========================================================== */

	protected function section_shipping() {
		if ( ! class_exists( 'AOD_COD_Data' ) ) {
			echo '<p class="aod-cd-empty">' . esc_html__( 'Le module de livraison (AOD COD Form) n’est pas actif.', 'aod-client-dashboard' ) . '</p>';
			return;
		}

		$prices   = AOD_COD_Data::prices();
		$free     = AOD_COD_Data::free_shipping();
		$symbol   = get_woocommerce_currency_symbol();
		$shipping = class_exists( 'AOD_Shipping' ) ? AOD_Shipping::instance() : null;
		?>
		<form class="aod-cd-form aod-cd-settings-form" data-action="aod_cd_save_shipping">

			<h2 class="aod-cd-form-title"><?php esc_html_e( 'Livraison gratuite', 'aod-client-dashboard' ); ?></h2>
			<div class="aod-cd-field">
				<label class="aod-cd-check">
					<input type="checkbox" name="fs_enabled" value="1" <?php checked( ! empty( $free['enabled'] ) ); ?>>
					<?php esc_html_e( 'Offrir la livraison à partir d’un certain montant de produits', 'aod-client-dashboard' ); ?>
				</label>
			</div>
			<label class="aod-cd-field" style="max-width:260px">
				<span class="aod-cd-label"><?php printf( esc_html__( 'Seuil (%s)', 'aod-client-dashboard' ), esc_html( $symbol ) ); ?></span>
				<input type="number" min="0" step="any" name="fs_threshold" value="<?php echo ( '' !== (string) $free['threshold'] && (float) $free['threshold'] > 0 ) ? esc_attr( $free['threshold'] ) : ''; ?>">
			</label>

			<h2 class="aod-cd-form-title" style="margin-top:26px"><?php esc_html_e( 'Tarifs par wilaya', 'aod-client-dashboard' ); ?></h2>
			<p class="aod-cd-note" style="margin-top:0"><?php esc_html_e( 'Laissez une wilaya vide pour la rendre non livrable. Domicile = livraison à l’adresse ; Stop-desk = retrait au bureau du livreur.', 'aod-client-dashboard' ); ?></p>
			<div class="aod-cd-search">
				<input type="search" class="aod-cd-search-input" data-filter="prices" placeholder="<?php esc_attr_e( 'Rechercher une wilaya…', 'aod-client-dashboard' ); ?>" autocomplete="off">
			</div>
			<div class="aod-cd-scroll">
				<table class="aod-cd-table aod-cd-pricetable">
					<thead><tr>
						<th><?php esc_html_e( 'Wilaya', 'aod-client-dashboard' ); ?></th>
						<th><?php printf( esc_html__( 'Domicile (%s)', 'aod-client-dashboard' ), esc_html( $symbol ) ); ?></th>
						<th><?php printf( esc_html__( 'Stop-desk (%s)', 'aod-client-dashboard' ), esc_html( $symbol ) ); ?></th>
						<th class="aod-cd-free-col">
							<label class="aod-cd-free-head">
								<input type="checkbox" class="aod-cd-free-all">
								<span><?php esc_html_e( 'Livraison gratuite', 'aod-client-dashboard' ); ?></span>
							</label>
						</th>
					</tr></thead>
					<tbody>
						<?php foreach ( AOD_COD_Data::places() as $w ) :
							$code = (int) $w['code']; ?>
							<tr>
								<td><?php echo esc_html( sprintf( '%02d - %s', $code, $w['name'] ) ); ?></td>
								<td><input type="number" min="0" step="any" name="home[<?php echo esc_attr( $code ); ?>]" value="<?php echo isset( $prices[ $code ]['home'] ) ? esc_attr( $prices[ $code ]['home'] ) : ''; ?>"></td>
								<td><input type="number" min="0" step="any" name="desk[<?php echo esc_attr( $code ); ?>]" value="<?php echo isset( $prices[ $code ]['desk'] ) ? esc_attr( $prices[ $code ]['desk'] ) : ''; ?>"></td>
								<td class="aod-cd-free-col"><input type="checkbox" class="aod-cd-free-one" name="free[<?php echo esc_attr( $code ); ?>]" value="1" <?php checked( ! empty( $prices[ $code ]['free'] ) ); ?>></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>

			<?php if ( $shipping ) :
				$auto     = $shipping->auto_settings();
				$carriers = $shipping->carriers();
				?>
				<h2 class="aod-cd-form-title" style="margin-top:26px"><?php esc_html_e( 'Transporteurs', 'aod-client-dashboard' ); ?></h2>
				<p class="aod-cd-note" style="margin-top:0"><?php esc_html_e( 'Cliquez sur un transporteur pour saisir vos identifiants API et le connecter.', 'aod-client-dashboard' ); ?></p>

				<div class="aod-cd-field">
					<label class="aod-cd-check">
						<input type="checkbox" name="auto[enabled]" value="1" <?php checked( ! empty( $auto['enabled'] ) ); ?>>
						<?php esc_html_e( 'Envoyer automatiquement au livreur quand une commande passe à « Confirmée »', 'aod-client-dashboard' ); ?>
					</label>
				</div>
				<label class="aod-cd-field" style="max-width:320px">
					<span class="aod-cd-label"><?php esc_html_e( 'Livreur par défaut (envoi auto)', 'aod-client-dashboard' ); ?></span>
					<select name="auto[carrier]">
						<?php foreach ( $carriers as $id => $c ) : ?>
							<option value="<?php echo esc_attr( $id ); ?>" <?php selected( $auto['carrier'], $id ); ?>>
								<?php echo esc_html( $c->label() ); ?><?php echo $c->is_configured() ? '' : ' ' . esc_html__( '(non configuré)', 'aod-client-dashboard' ); ?>
							</option>
						<?php endforeach; ?>
					</select>
				</label>

				<div class="aod-cd-search">
					<input type="search" class="aod-cd-search-input" data-filter="carriers" placeholder="<?php esc_attr_e( 'Rechercher un transporteur…', 'aod-client-dashboard' ); ?>" autocomplete="off">
				</div>
				<div class="aod-cd-scroll">
					<table class="aod-cd-table aod-cd-carriers">
						<thead><tr>
							<th><?php esc_html_e( 'Transporteur', 'aod-client-dashboard' ); ?></th>
							<th><?php esc_html_e( 'Statut', 'aod-client-dashboard' ); ?></th>
							<th aria-hidden="true"></th>
						</tr></thead>
						<tbody>
							<?php foreach ( $carriers as $id => $c ) :
								$ok = $c->is_configured(); ?>
								<tr class="aod-cd-carrier-row<?php echo $ok ? ' is-on' : ''; ?>" data-carrier="<?php echo esc_attr( $id ); ?>" tabindex="0" role="button" aria-expanded="false">
									<td class="aod-cd-carrier-name">
										<?php echo $c->icon_html(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped — HTML sûr (pastille). ?>
										<span><?php echo esc_html( $c->label() ); ?></span>
									</td>
									<td>
										<?php if ( $ok ) : ?>
											<span class="aod-cd-ok-badge"><?php esc_html_e( 'Connecté', 'aod-client-dashboard' ); ?></span>
										<?php else : ?>
											<span class="aod-cd-off-badge"><?php esc_html_e( 'Non connecté', 'aod-client-dashboard' ); ?></span>
										<?php endif; ?>
									</td>
									<td class="aod-cd-carrier-chev" aria-hidden="true">›</td>
								</tr>
								<tr class="aod-cd-carrier-panel" hidden>
									<td colspan="3">
										<div class="aod-cd-carrier-fields">
											<?php $c->render_settings_fields(); // Réutilise les champs du plugin COD (noms <id>[champ]). ?>
										</div>
									</td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				</div>
			<?php endif; ?>

			<div class="aod-cd-form-foot">
				<button type="submit" class="aod-cd-btn aod-cd-btn-primary"><?php esc_html_e( 'Enregistrer la livraison', 'aod-client-dashboard' ); ?></button>
				<span class="aod-cd-form-msg"></span>
			</div>
		</form>
		<?php
	}

	/**
	 * AJAX : enregistre tarifs wilayas, livraison gratuite, envoi auto et transporteurs.
	 */
	public function ajax_save_shipping() {
		check_ajax_referer( 'aod_cd', 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'Accès refusé.', 'aod-client-dashboard' ) ), 403 );
		}
		if ( ! class_exists( 'AOD_COD_Data' ) ) {
			wp_send_json_error( array( 'message' => __( 'Module de livraison indisponible.', 'aod-client-dashboard' ) ), 400 );
		}

		// Tarifs par wilaya (même schéma que le plugin COD).
		$home   = isset( $_POST['home'] ) ? (array) $_POST['home'] : array();
		$desk   = isset( $_POST['desk'] ) ? (array) $_POST['desk'] : array();
		$free   = isset( $_POST['free'] ) ? (array) $_POST['free'] : array();
		$prices = array();
		foreach ( AOD_COD_Data::places() as $w ) {
			$code = (int) $w['code'];
			$h    = isset( $home[ $code ] ) ? wc_format_decimal( wp_unslash( $home[ $code ] ) ) : '';
			$d    = isset( $desk[ $code ] ) ? wc_format_decimal( wp_unslash( $desk[ $code ] ) ) : '';
			$prices[ $code ] = array(
				'home' => ( '' === $h ) ? '' : (float) $h,
				'desk' => ( '' === $d ) ? '' : (float) $d,
				'free' => empty( $free[ $code ] ) ? 0 : 1,
			);
		}
		update_option( AOD_COD_Data::OPTION_PRICES, $prices );

		// Livraison gratuite.
		update_option( AOD_COD_Data::OPTION_FREE_SHIP, array(
			'enabled'   => isset( $_POST['fs_enabled'] ) ? 1 : 0,
			'threshold' => isset( $_POST['fs_threshold'] ) ? (float) wc_format_decimal( wp_unslash( $_POST['fs_threshold'] ) ) : 0,
		) );

		// Transporteurs + envoi auto (réutilise les classes du plugin COD).
		if ( class_exists( 'AOD_Shipping' ) ) {
			$shipping = AOD_Shipping::instance();
			foreach ( $shipping->carriers() as $id => $carrier ) {
				$slice = isset( $_POST[ $id ] ) && is_array( $_POST[ $id ] ) ? wp_unslash( $_POST[ $id ] ) : array();
				$carrier->save_settings( $carrier->sanitize_settings( $slice ) );
			}
			$auto = isset( $_POST['auto'] ) && is_array( $_POST['auto'] ) ? wp_unslash( $_POST['auto'] ) : array();
			update_option( AOD_Shipping::AUTO_OPTION, array(
				'enabled' => empty( $auto['enabled'] ) ? 0 : 1,
				'carrier' => isset( $auto['carrier'] ) ? sanitize_key( $auto['carrier'] ) : 'ecotrack',
			) );
		}

		wp_send_json_success( array( 'message' => __( 'Réglages de livraison enregistrés.', 'aod-client-dashboard' ) ) );
	}

	/* ============================================================
	 * Section : Pixels & Tracking
	 * ========================================================== */

	/** Valeurs par défaut + valeurs enregistrées de l'option pixels. */
	protected function pixels_settings() {
		$saved = get_option( 'aod_cod_pixels', array() );
		return wp_parse_args( (array) $saved, array(
			'meta'         => '',
			'tiktok'       => '',
			'snapchat'     => '',
			'google_ads'   => '',
			'google_label' => '',
		) );
	}

	/** Nettoie un identifiant de pixel (mêmes caractères sûrs que le plugin COD). */
	protected static function clean_pixel_id( $v ) {
		return preg_replace( '/[^A-Za-z0-9_\-]/', '', (string) $v );
	}

	protected function section_marketing() {
		$s = $this->pixels_settings();

		$fields = array(
			array( 'meta', __( 'Meta (Facebook) Pixel ID', 'aod-client-dashboard' ), '1234567890', __( 'Gestionnaire d’événements Meta → Paramètres → ID du pixel.', 'aod-client-dashboard' ) ),
			array( 'tiktok', __( 'TikTok Pixel ID', 'aod-client-dashboard' ), 'CXXXXXXXXXXXXXXXXXXX', __( 'TikTok Ads → Outils → Événements → Pixel Web.', 'aod-client-dashboard' ) ),
			array( 'snapchat', __( 'Snapchat Pixel ID', 'aod-client-dashboard' ), '00000000-0000-0000-0000-000000000000', __( 'Snapchat Ads → Gestionnaire d’événements → Pixel ID.', 'aod-client-dashboard' ) ),
			array( 'google_ads', __( 'Google Ads — ID de conversion', 'aod-client-dashboard' ), 'AW-123456789', __( 'Commence par AW-. Google Ads → Objectifs → Conversions.', 'aod-client-dashboard' ) ),
			array( 'google_label', __( 'Google Ads — Libellé de conversion', 'aod-client-dashboard' ), 'AbC-D_efG-h12', __( 'La partie après « / » dans send_to. Sans elle, seule la balise de base est posée.', 'aod-client-dashboard' ) ),
		);
		?>
		<form class="aod-cd-form aod-cd-settings-form" data-action="aod_cd_save_pixels">
			<h2 class="aod-cd-form-title"><?php esc_html_e( 'Pixels publicitaires', 'aod-client-dashboard' ); ?></h2>
			<p class="aod-cd-note" style="margin-top:0"><?php esc_html_e( 'Colle l’identifiant de chaque régie. Laisse vide pour désactiver. Le PageView est suivi sur tout le site ; l’achat est mesuré sur la page de remerciement (une fois par commande).', 'aod-client-dashboard' ); ?></p>

			<?php foreach ( $fields as $f ) :
				list( $key, $label, $ph, $desc ) = $f; ?>
				<label class="aod-cd-field" style="max-width:480px">
					<span class="aod-cd-label"><?php echo esc_html( $label ); ?></span>
					<input type="text" name="<?php echo esc_attr( $key ); ?>" value="<?php echo esc_attr( $s[ $key ] ); ?>" placeholder="<?php echo esc_attr( $ph ); ?>">
					<span class="aod-cd-note" style="font-size:12px;margin-top:2px"><?php echo esc_html( $desc ); ?></span>
				</label>
			<?php endforeach; ?>

			<div class="aod-cd-form-foot">
				<button type="submit" class="aod-cd-btn aod-cd-btn-primary"><?php esc_html_e( 'Enregistrer les pixels', 'aod-client-dashboard' ); ?></button>
				<span class="aod-cd-form-msg"></span>
			</div>
		</form>
		<?php
	}

	/** AJAX : enregistre les pixels (option aod_cod_pixels). */
	public function ajax_save_pixels() {
		check_ajax_referer( 'aod_cd', 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'Accès refusé.', 'aod-client-dashboard' ) ), 403 );
		}
		$settings = array(
			'meta'         => isset( $_POST['meta'] ) ? self::clean_pixel_id( wp_unslash( $_POST['meta'] ) ) : '',
			'tiktok'       => isset( $_POST['tiktok'] ) ? self::clean_pixel_id( wp_unslash( $_POST['tiktok'] ) ) : '',
			'snapchat'     => isset( $_POST['snapchat'] ) ? self::clean_pixel_id( wp_unslash( $_POST['snapchat'] ) ) : '',
			'google_ads'   => isset( $_POST['google_ads'] ) ? self::clean_pixel_id( wp_unslash( $_POST['google_ads'] ) ) : '',
			'google_label' => isset( $_POST['google_label'] ) ? self::clean_pixel_id( wp_unslash( $_POST['google_label'] ) ) : '',
		);
		update_option( 'aod_cod_pixels', $settings );
		wp_send_json_success( array( 'message' => __( 'Pixels enregistrés.', 'aod-client-dashboard' ) ) );
	}

	/* ============================================================
	 * Section : Notification WhatsApp
	 * ========================================================== */

	/** Valeurs par défaut + valeurs enregistrées de l'option WhatsApp. */
	protected function whatsapp_settings() {
		$saved = get_option( 'aod_cod_whatsapp', array() );
		return wp_parse_args( (array) $saved, array(
			'enabled' => 0,
			'phone'   => '',
			'apikey'  => '',
		) );
	}

	protected function section_whatsapp() {
		$s          = $this->whatsapp_settings();
		$configured = ! empty( $s['enabled'] ) && '' !== $s['phone'] && '' !== $s['apikey'];
		?>
		<form class="aod-cd-form aod-cd-settings-form" data-action="aod_cd_save_whatsapp">
			<h2 class="aod-cd-form-title"><?php esc_html_e( 'Notification WhatsApp des commandes', 'aod-client-dashboard' ); ?></h2>
			<p class="aod-cd-note" style="margin-top:0"><?php esc_html_e( 'Reçois un message WhatsApp instantané à chaque nouvelle commande. Service gratuit via CallMeBot.', 'aod-client-dashboard' ); ?></p>

			<div class="aod-cd-soon" style="text-align:start;padding:16px 18px;margin-bottom:18px">
				<strong><?php esc_html_e( 'Mise en route (une seule fois) :', 'aod-client-dashboard' ); ?></strong>
				<ol style="margin:8px 0 0 1.1em;padding:0;line-height:1.7">
					<li><?php printf( esc_html__( 'Enregistre le numéro %s dans tes contacts (nom : CallMeBot).', 'aod-client-dashboard' ), '<code>+34 644 84 71 89</code>' ); ?></li>
					<li><?php printf( esc_html__( 'Sur WhatsApp, envoie-lui : %s', 'aod-client-dashboard' ), '<code>I allow callmebot to send me messages</code>' ); ?></li>
					<li><?php esc_html_e( 'Tu reçois ta clé API (apikey). Colle-la ci-dessous.', 'aod-client-dashboard' ); ?></li>
				</ol>
			</div>

			<div class="aod-cd-field">
				<label class="aod-cd-check">
					<input type="checkbox" name="enabled" value="1" <?php checked( ! empty( $s['enabled'] ) ); ?>>
					<?php esc_html_e( 'Envoyer un WhatsApp à chaque commande', 'aod-client-dashboard' ); ?>
				</label>
			</div>

			<label class="aod-cd-field" style="max-width:360px">
				<span class="aod-cd-label"><?php esc_html_e( 'Ton numéro WhatsApp', 'aod-client-dashboard' ); ?></span>
				<input type="text" name="phone" value="<?php echo esc_attr( $s['phone'] ); ?>" placeholder="213550123456">
				<span class="aod-cd-note" style="font-size:12px;margin-top:2px"><?php esc_html_e( 'Avec l’indicatif pays, sans « + » ni espaces. Algérie = 213. Ex : 213550123456.', 'aod-client-dashboard' ); ?></span>
			</label>

			<label class="aod-cd-field" style="max-width:480px">
				<span class="aod-cd-label"><?php esc_html_e( 'Clé API (apikey)', 'aod-client-dashboard' ); ?></span>
				<input type="text" name="apikey" value="<?php echo esc_attr( $s['apikey'] ); ?>">
				<span class="aod-cd-note" style="font-size:12px;margin-top:2px"><?php esc_html_e( 'Fournie par CallMeBot dans sa réponse WhatsApp.', 'aod-client-dashboard' ); ?></span>
			</label>

			<div class="aod-cd-form-foot">
				<button type="submit" class="aod-cd-btn aod-cd-btn-primary"><?php esc_html_e( 'Enregistrer', 'aod-client-dashboard' ); ?></button>
				<button type="button" class="aod-cd-btn aod-cd-wa-test"<?php echo $configured ? '' : ' disabled'; ?>><?php esc_html_e( 'Envoyer un message test', 'aod-client-dashboard' ); ?></button>
				<span class="aod-cd-form-msg"></span>
			</div>
		</form>
		<?php
	}

	/** AJAX : enregistre les réglages WhatsApp (option aod_cod_whatsapp). */
	public function ajax_save_whatsapp() {
		check_ajax_referer( 'aod_cd', 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'Accès refusé.', 'aod-client-dashboard' ) ), 403 );
		}
		$settings = array(
			'enabled' => isset( $_POST['enabled'] ) ? 1 : 0,
			'phone'   => isset( $_POST['phone'] ) ? preg_replace( '/[^\d+]/', '', wp_unslash( $_POST['phone'] ) ) : '',
			'apikey'  => isset( $_POST['apikey'] ) ? sanitize_text_field( wp_unslash( $_POST['apikey'] ) ) : '',
		);
		update_option( 'aod_cod_whatsapp', $settings );
		wp_send_json_success( array( 'message' => __( 'Réglages WhatsApp enregistrés.', 'aod-client-dashboard' ) ) );
	}

	/** AJAX : envoie un message WhatsApp de test via CallMeBot (bloquant). */
	public function ajax_test_whatsapp() {
		check_ajax_referer( 'aod_cd', 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'Accès refusé.', 'aod-client-dashboard' ) ), 403 );
		}
		$s = $this->whatsapp_settings();
		if ( empty( $s['enabled'] ) || '' === $s['phone'] || '' === $s['apikey'] ) {
			wp_send_json_error( array( 'message' => __( 'Active et renseigne le numéro + la clé, puis enregistre avant de tester.', 'aod-client-dashboard' ) ), 400 );
		}

		$url = add_query_arg(
			array(
				'phone'  => preg_replace( '/\D+/', '', $s['phone'] ),
				'text'   => rawurlencode( __( '✅ Test : la notification WhatsApp de votre boutique fonctionne.', 'aod-client-dashboard' ) ),
				'apikey' => rawurldecode( $s['apikey'] ),
			),
			'https://api.callmebot.com/whatsapp.php'
		);

		$res = wp_remote_get( $url, array( 'timeout' => 20 ) );
		if ( is_wp_error( $res ) ) {
			wp_send_json_error( array( 'message' => __( 'Échec de l’envoi : ', 'aod-client-dashboard' ) . $res->get_error_message() ), 502 );
		}
		$code = (int) wp_remote_retrieve_response_code( $res );
		if ( $code >= 200 && $code < 300 ) {
			wp_send_json_success( array( 'message' => __( 'Message test envoyé. Vérifie ton WhatsApp.', 'aod-client-dashboard' ) ) );
		}
		wp_send_json_error( array( 'message' => sprintf( __( 'Réponse inattendue (HTTP %d). Vérifie ton numéro et ta clé.', 'aod-client-dashboard' ), $code ) ), 502 );
	}

	/* ============================================================
	 * Section : Mon compte (self-service du gérant)
	 * ========================================================== */

	protected function section_account() {
		$user = wp_get_current_user();
		?>
		<form class="aod-cd-form aod-cd-settings-form" data-action="aod_cd_save_account">
			<h2 class="aod-cd-form-title"><?php esc_html_e( 'Mon compte', 'aod-client-dashboard' ); ?></h2>

			<label class="aod-cd-field">
				<span class="aod-cd-label"><?php esc_html_e( 'Identifiant de connexion', 'aod-client-dashboard' ); ?></span>
				<input type="text" value="<?php echo esc_attr( $user->user_login ); ?>" disabled>
				<span class="aod-cd-note" style="font-size:12px;margin-top:2px"><?php esc_html_e( 'L’identifiant ne peut pas être modifié.', 'aod-client-dashboard' ); ?></span>
			</label>

			<label class="aod-cd-field">
				<span class="aod-cd-label"><?php esc_html_e( 'Nom affiché', 'aod-client-dashboard' ); ?></span>
				<input type="text" name="display_name" value="<?php echo esc_attr( $user->display_name ); ?>">
			</label>

			<label class="aod-cd-field">
				<span class="aod-cd-label"><?php esc_html_e( 'Adresse e-mail', 'aod-client-dashboard' ); ?></span>
				<input type="email" name="email" value="<?php echo esc_attr( $user->user_email ); ?>">
			</label>

			<h3 class="aod-cd-form-subtitle"><?php esc_html_e( 'Changer le mot de passe', 'aod-client-dashboard' ); ?></h3>
			<p class="aod-cd-note" style="margin-top:0"><?php esc_html_e( 'Laissez ces deux champs vides pour conserver le mot de passe actuel.', 'aod-client-dashboard' ); ?></p>
			<div class="aod-cd-row2">
				<label class="aod-cd-field">
					<span class="aod-cd-label"><?php esc_html_e( 'Nouveau mot de passe', 'aod-client-dashboard' ); ?></span>
					<input type="password" name="new_password" autocomplete="new-password">
				</label>
				<label class="aod-cd-field">
					<span class="aod-cd-label"><?php esc_html_e( 'Confirmer', 'aod-client-dashboard' ); ?></span>
					<input type="password" name="confirm_password" autocomplete="new-password">
				</label>
			</div>

			<div class="aod-cd-form-foot">
				<button type="submit" class="aod-cd-btn aod-cd-btn-primary"><?php esc_html_e( 'Enregistrer', 'aod-client-dashboard' ); ?></button>
				<span class="aod-cd-form-msg"></span>
			</div>
		</form>
		<?php
	}

	/**
	 * AJAX : met à jour le compte de l'utilisateur connecté (nom, e-mail, mot de passe).
	 * Réservé aux utilisateurs ayant accès au dashboard (gérant ou admin).
	 */
	public function ajax_save_account() {
		check_ajax_referer( 'aod_cd', 'nonce' );

		$user = wp_get_current_user();
		if ( ! $user || ! $user->exists() ) {
			wp_send_json_error( array( 'message' => __( 'Session expirée, reconnectez-vous.', 'aod-client-dashboard' ) ), 403 );
		}
		if ( ! current_user_can( AOD_CD_CAP ) && ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'Accès refusé.', 'aod-client-dashboard' ) ), 403 );
		}

		$display = isset( $_POST['display_name'] ) ? sanitize_text_field( wp_unslash( $_POST['display_name'] ) ) : '';
		$email   = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '';
		$pass    = isset( $_POST['new_password'] ) ? (string) wp_unslash( $_POST['new_password'] ) : '';
		$confirm = isset( $_POST['confirm_password'] ) ? (string) wp_unslash( $_POST['confirm_password'] ) : '';

		$data = array( 'ID' => $user->ID );

		if ( '' !== $display ) {
			$data['display_name'] = $display;
		}

		if ( '' !== $email && $email !== $user->user_email ) {
			if ( ! is_email( $email ) ) {
				wp_send_json_error( array( 'message' => __( 'Adresse e-mail invalide.', 'aod-client-dashboard' ) ), 400 );
			}
			$owner = email_exists( $email );
			if ( $owner && (int) $owner !== (int) $user->ID ) {
				wp_send_json_error( array( 'message' => __( 'Cette adresse e-mail est déjà utilisée par un autre compte.', 'aod-client-dashboard' ) ), 400 );
			}
			$data['user_email'] = $email;
		}

		if ( '' !== $pass || '' !== $confirm ) {
			if ( $pass !== $confirm ) {
				wp_send_json_error( array( 'message' => __( 'Les deux mots de passe ne correspondent pas.', 'aod-client-dashboard' ) ), 400 );
			}
			if ( strlen( $pass ) < 8 ) {
				wp_send_json_error( array( 'message' => __( 'Le mot de passe doit faire au moins 8 caractères.', 'aod-client-dashboard' ) ), 400 );
			}
			$data['user_pass'] = $pass;
		}

		$res = wp_update_user( $data );
		if ( is_wp_error( $res ) ) {
			wp_send_json_error( array( 'message' => $res->get_error_message() ), 400 );
		}

		// Changer le mot de passe invalide la session courante : on la rétablit
		// pour que le gérant reste connecté au lieu d'être éjecté du dashboard.
		if ( isset( $data['user_pass'] ) ) {
			wp_set_current_user( $user->ID );
			wp_set_auth_cookie( $user->ID, true );
		}

		wp_send_json_success( array( 'message' => __( 'Compte mis à jour.', 'aod-client-dashboard' ) ) );
	}

	/* ============================================================
	 * Placeholder
	 * ========================================================== */

	protected function section_soon( $slug ) {
		echo '<div class="aod-cd-soon"><p>🚧 ' . esc_html__( 'Cette section arrive bientôt.', 'aod-client-dashboard' ) . '</p></div>';
	}

	/* ============================================================
	 * AJAX : changement de statut de commande
	 * ========================================================== */

	public function ajax_order_status() {
		check_ajax_referer( 'aod_cd', 'nonce' );
		if ( ! current_user_can( 'edit_shop_orders' ) && ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'Accès refusé.', 'aod-client-dashboard' ) ), 403 );
		}

		$order_id = isset( $_POST['order_id'] ) ? absint( $_POST['order_id'] ) : 0;
		$status   = isset( $_POST['status'] ) ? sanitize_key( wp_unslash( $_POST['status'] ) ) : '';
		$status   = preg_replace( '/^wc-/', '', $status );

		$order = $order_id ? wc_get_order( $order_id ) : false;
		if ( ! $order instanceof WC_Order ) {
			wp_send_json_error( array( 'message' => __( 'Commande introuvable.', 'aod-client-dashboard' ) ), 404 );
		}
		if ( ! array_key_exists( 'wc-' . $status, wc_get_order_statuses() ) ) {
			wp_send_json_error( array( 'message' => __( 'Statut invalide.', 'aod-client-dashboard' ) ), 400 );
		}

		$order->update_status( $status, __( 'Statut modifié depuis l’espace de gestion.', 'aod-client-dashboard' ) );

		// Le passage à « Confirmée » déclenche l'envoi auto au livreur (AOD_Shipping).
		$order    = wc_get_order( $order_id );
		$tracking = $order ? $order->get_meta( '_aod_ship_tracking' ) : '';

		wp_send_json_success( array(
			'message'  => __( 'Statut mis à jour.', 'aod-client-dashboard' ),
			'tracking' => $tracking,
		) );
	}

	/**
	 * Ajoute une note (privée) à une commande.
	 */
	public function ajax_order_note() {
		check_ajax_referer( 'aod_cd', 'nonce' );
		if ( ! current_user_can( 'edit_shop_orders' ) && ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'Accès refusé.', 'aod-client-dashboard' ) ), 403 );
		}

		$order_id = isset( $_POST['order_id'] ) ? absint( $_POST['order_id'] ) : 0;
		$note     = isset( $_POST['note'] ) ? sanitize_textarea_field( wp_unslash( $_POST['note'] ) ) : '';

		if ( '' === trim( $note ) ) {
			wp_send_json_error( array( 'message' => __( 'La note est vide.', 'aod-client-dashboard' ) ), 400 );
		}

		$order = $order_id ? wc_get_order( $order_id ) : false;
		if ( ! $order instanceof WC_Order ) {
			wp_send_json_error( array( 'message' => __( 'Commande introuvable.', 'aod-client-dashboard' ) ), 404 );
		}

		$order->add_order_note( $note, 0, true );

		$count = count( wc_get_order_notes( array( 'order_id' => $order_id ) ) );

		wp_send_json_success( array(
			'message' => __( 'Note ajoutée.', 'aod-client-dashboard' ),
			'count'   => $count,
		) );
	}

	/* ============================================================
	 * AJAX : détail d'une commande (modale)
	 * ========================================================== */

	public function ajax_order_detail() {
		check_ajax_referer( 'aod_cd', 'nonce' );
		if ( ! current_user_can( 'edit_shop_orders' ) && ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'Accès refusé.', 'aod-client-dashboard' ) ), 403 );
		}
		$order_id = isset( $_POST['order_id'] ) ? absint( $_POST['order_id'] ) : 0;
		$order    = $order_id ? wc_get_order( $order_id ) : false;
		if ( ! $order instanceof WC_Order ) {
			wp_send_json_error( array( 'message' => __( 'Commande introuvable.', 'aod-client-dashboard' ) ), 404 );
		}

		ob_start();
		$this->render_order_detail( $order );
		$html = ob_get_clean();

		wp_send_json_success( array(
			'title' => sprintf( __( 'Commande #%s', 'aod-client-dashboard' ), $order->get_order_number() ),
			'html'  => $html,
		) );
	}

	/* ============================================================
	 * AJAX : modification des infos client / livraison d'une commande
	 * ========================================================== */

	public function ajax_order_save_info() {
		check_ajax_referer( 'aod_cd', 'nonce' );
		if ( ! current_user_can( 'edit_shop_orders' ) && ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'Accès refusé.', 'aod-client-dashboard' ) ), 403 );
		}

		$order_id = isset( $_POST['order_id'] ) ? absint( $_POST['order_id'] ) : 0;
		$order    = $order_id ? wc_get_order( $order_id ) : false;
		if ( ! $order instanceof WC_Order ) {
			wp_send_json_error( array( 'message' => __( 'Commande introuvable.', 'aod-client-dashboard' ) ), 404 );
		}

		$name     = isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '';
		$phone    = isset( $_POST['phone'] ) ? sanitize_text_field( wp_unslash( $_POST['phone'] ) ) : '';
		$addr     = isset( $_POST['address'] ) ? sanitize_text_field( wp_unslash( $_POST['address'] ) ) : '';
		$wilaya   = isset( $_POST['wilaya'] ) ? absint( $_POST['wilaya'] ) : 0;
		$commune  = isset( $_POST['commune'] ) ? sanitize_text_field( wp_unslash( $_POST['commune'] ) ) : '';
		$delivery = isset( $_POST['delivery_type'] ) ? sanitize_key( wp_unslash( $_POST['delivery_type'] ) ) : '';

		if ( '' === trim( $phone ) ) {
			wp_send_json_error( array( 'message' => __( 'Le téléphone est obligatoire.', 'aod-client-dashboard' ) ), 400 );
		}
		if ( ! in_array( $delivery, array( 'home', 'desk' ), true ) ) {
			$delivery = $order->get_meta( '_aod_delivery_type' );
		}

		$has_data = class_exists( 'AOD_COD_Data' );
		if ( $has_data && $wilaya ) {
			if ( '' === AOD_COD_Data::wilaya_name( $wilaya ) ) {
				wp_send_json_error( array( 'message' => __( 'Wilaya invalide.', 'aod-client-dashboard' ) ), 400 );
			}
			if ( '' !== $commune && ! AOD_COD_Data::commune_valid( $wilaya, $commune ) ) {
				wp_send_json_error( array( 'message' => __( 'Commune invalide pour cette wilaya.', 'aod-client-dashboard' ) ), 400 );
			}
		}

		// Adresse de facturation + livraison (mêmes clés que la création de commande COD).
		$parts = array(
			'first_name' => $name,
			'last_name'  => '',
			'phone'      => $phone,
			'address_1'  => $addr,
			'country'    => 'DZ',
		);
		if ( '' !== $commune ) {
			$parts['city'] = $commune;
		}
		if ( $wilaya && $has_data ) {
			$parts['state'] = 'DZ-' . str_pad( (string) $wilaya, 2, '0', STR_PAD_LEFT );
		}
		$order->set_address( $parts, 'billing' );
		$order->set_address( $parts, 'shipping' );

		// Méta spécifiques à la livraison COD.
		if ( $wilaya && $has_data ) {
			$order->update_meta_data( '_aod_wilaya_code', $wilaya );
			$order->update_meta_data( '_aod_wilaya_name', AOD_COD_Data::wilaya_name( $wilaya ) );
			$order->update_meta_data( '_aod_wilaya_name_ar', AOD_COD_Data::wilaya_name_ar( $wilaya ) );
		}
		if ( '' !== $commune ) {
			$order->update_meta_data( '_aod_commune', $commune );
			if ( $wilaya && $has_data ) {
				$order->update_meta_data( '_aod_commune_ar', AOD_COD_Data::commune_name_ar( $wilaya, $commune ) );
			}
		}
		if ( $delivery ) {
			$order->update_meta_data( '_aod_delivery_type', $delivery );
		}

		// Articles : variantes, quantités, retrait de lignes (recalcul des totaux).
		$items_changed = false;
		$items_json    = isset( $_POST['items_json'] ) ? wp_unslash( $_POST['items_json'] ) : '';
		$items_in      = $items_json ? json_decode( $items_json, true ) : null;
		if ( is_array( $items_in ) && $items_in ) {
			// Plan de modification + quantités cumulées par produit (pour les paliers de prix).
			$plan      = array();
			$group_qty = array();
			foreach ( $items_in as $row ) {
				$iid  = isset( $row['id'] ) ? absint( $row['id'] ) : 0;
				$item = $iid ? $order->get_item( $iid ) : null;
				if ( ! $item instanceof WC_Order_Item_Product ) {
					continue;
				}
				if ( ! empty( $row['remove'] ) ) {
					$plan[ $iid ] = array( 'remove' => true );
					continue;
				}
				$qty     = isset( $row['qty'] ) ? max( 1, absint( $row['qty'] ) ) : (int) $item->get_quantity();
				$product = $item->get_product();
				$opts_in = ( isset( $row['opts'] ) && is_array( $row['opts'] ) ) ? $row['opts'] : array();

				$valid_opts = array();
				$supplement = 0.0;
				if ( $product ) {
					foreach ( $this->get_product_options( $product ) as $sec ) {
						$slabel = (string) $sec['label'];
						$chosen = isset( $opts_in[ $slabel ] ) ? sanitize_text_field( (string) $opts_in[ $slabel ] ) : (string) $item->get_meta( $slabel );
						$match  = null;
						foreach ( $sec['values'] as $val ) {
							if ( $val['name'] === $chosen ) {
								$match = $val;
								break;
							}
						}
						if ( $match ) {
							$valid_opts[ $slabel ] = $match['name'];
							if ( '' !== $match['price'] ) {
								$supplement += (float) $match['price'];
							}
						} elseif ( '' !== $chosen ) {
							$valid_opts[ $slabel ] = $chosen; // valeur héritée inconnue : conservée sans supplément.
						}
					}
					$pid               = $product->get_id();
					$group_qty[ $pid ] = ( isset( $group_qty[ $pid ] ) ? $group_qty[ $pid ] : 0 ) + $qty;
				}
				$plan[ $iid ] = array(
					'remove'     => false,
					'qty'        => $qty,
					'opts'       => $valid_opts,
					'product'    => $product,
					'supplement' => $supplement,
				);
			}

			// Refus si toutes les lignes sont retirées.
			$kept = 0;
			foreach ( $plan as $p ) {
				if ( empty( $p['remove'] ) ) {
					$kept++;
				}
			}
			if ( $plan && 0 === $kept ) {
				wp_send_json_error( array( 'message' => __( 'Une commande doit contenir au moins un article.', 'aod-client-dashboard' ) ), 400 );
			}

			foreach ( $plan as $iid => $p ) {
				$item = $order->get_item( $iid );
				if ( ! $item ) {
					continue;
				}
				if ( ! empty( $p['remove'] ) ) {
					$order->remove_item( $iid );
					$items_changed = true;
					continue;
				}
				$product = $p['product'];
				if ( $product ) {
					$qty_total = isset( $group_qty[ $product->get_id() ] ) ? $group_qty[ $product->get_id() ] : $p['qty'];
					$unit      = $this->tier_unit_price( $product, $qty_total ) + $p['supplement'];
				} else {
					// Produit supprimé du catalogue : on garde le prix unitaire actuel.
					$prev_qty = max( 1, (int) $item->get_quantity() );
					$unit     = ( (float) $item->get_subtotal() / $prev_qty ) + $p['supplement'];
				}
				$line_total = $unit * $p['qty'];

				$item->set_quantity( $p['qty'] );
				$item->set_subtotal( $line_total );
				$item->set_total( $line_total );
				foreach ( $p['opts'] as $slabel => $val ) {
					$item->update_meta_data( $slabel, $val );
				}
				$item->save();
				$items_changed = true;
			}
		}

		$order->save();

		if ( $items_changed ) {
			$order->calculate_totals();
		}

		$order->add_order_note(
			$items_changed
				? __( 'Infos client / livraison et articles modifiés depuis l’espace de gestion.', 'aod-client-dashboard' )
				: __( 'Infos client / livraison modifiées depuis l’espace de gestion.', 'aod-client-dashboard' ),
			0,
			true
		);

		$order = wc_get_order( $order_id );
		ob_start();
		$this->render_order_detail( $order );
		$html = ob_get_clean();

		wp_send_json_success( array(
			'message' => __( 'Commande mise à jour.', 'aod-client-dashboard' ),
			'title'   => sprintf( __( 'Commande #%s', 'aod-client-dashboard' ), $order->get_order_number() ),
			'html'    => $html,
		) );
	}

	/**
	 * Prix unitaire d'un produit pour une quantité donnée, offres de lot appliquées.
	 *
	 * Réplique la logique du formulaire COD : on retient le prix par unité de la plus
	 * grande offre dont le nombre d'unités est atteint, s'il est inférieur au prix de base.
	 *
	 * @param WC_Product $product
	 * @param int        $qty
	 * @return float
	 */
	protected function tier_unit_price( $product, $qty ) {
		$base = (float) wc_get_price_to_display( $product );
		if ( ! $product || $product->is_type( 'variation' ) ) {
			return $base;
		}
		$offers = $this->get_product_offers( $product );
		if ( ! $offers ) {
			return $base;
		}
		$unit     = $base;
		$best_qty = 1;
		foreach ( $offers as $o ) {
			$oqty = (int) $o['qty'];
			$u    = $oqty > 0 ? (float) $o['price'] / $oqty : 0; // price = prix total du lot.
			if ( $oqty >= 2 && $u > 0 && $u < $base && $qty >= $oqty && $oqty >= $best_qty ) {
				$best_qty = $oqty;
				$unit     = $u;
			}
		}
		return $unit;
	}

	/**
	 * Formulaire d'édition des infos client / livraison (affiché dans la modale détail).
	 *
	 * @param WC_Order $order
	 */
	protected function render_order_edit_form( $order ) {
		$wilaya_code = (int) $order->get_meta( '_aod_wilaya_code' );
		$commune     = $order->get_meta( '_aod_commune' );
		$delivery    = $order->get_meta( '_aod_delivery_type' );
		$has_data    = class_exists( 'AOD_COD_Data' );

		$full_name = trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() );

		echo '<form class="aod-cd-form aod-cd-od-edit" data-order="' . esc_attr( $order->get_id() ) . '" hidden>';

		echo '<label class="aod-cd-field"><span class="aod-cd-label">' . esc_html__( 'Nom du client', 'aod-client-dashboard' ) . '</span>';
		echo '<input type="text" name="name" value="' . esc_attr( $full_name ) . '"></label>';

		echo '<div class="aod-cd-row2">';
		echo '<label class="aod-cd-field"><span class="aod-cd-label">' . esc_html__( 'Téléphone', 'aod-client-dashboard' ) . ' *</span>';
		echo '<input type="text" name="phone" required value="' . esc_attr( $order->get_billing_phone() ) . '"></label>';
		echo '<label class="aod-cd-field"><span class="aod-cd-label">' . esc_html__( 'Type de livraison', 'aod-client-dashboard' ) . '</span>';
		echo '<select name="delivery_type">';
		echo '<option value="home" ' . selected( $delivery, 'home', false ) . '>' . esc_html__( 'À domicile', 'aod-client-dashboard' ) . '</option>';
		echo '<option value="desk" ' . selected( $delivery, 'desk', false ) . '>' . esc_html__( 'Stop-desk (bureau)', 'aod-client-dashboard' ) . '</option>';
		echo '</select></label>';
		echo '</div>';

		echo '<label class="aod-cd-field"><span class="aod-cd-label">' . esc_html__( 'Adresse', 'aod-client-dashboard' ) . '</span>';
		echo '<input type="text" name="address" value="' . esc_attr( $order->get_billing_address_1() ) . '"></label>';

		echo '<div class="aod-cd-row2">';

		// Wilaya.
		echo '<label class="aod-cd-field"><span class="aod-cd-label">' . esc_html__( 'Wilaya', 'aod-client-dashboard' ) . '</span>';
		echo '<select name="wilaya" class="aod-cd-od-wilaya">';
		echo '<option value="">' . esc_html__( '—', 'aod-client-dashboard' ) . '</option>';
		if ( $has_data ) {
			foreach ( AOD_COD_Data::places() as $w ) {
				$label = AOD_COD_Data::label( $w['name'], isset( $w['name_ar'] ) ? $w['name_ar'] : '' );
				echo '<option value="' . esc_attr( $w['code'] ) . '" ' . selected( $wilaya_code, (int) $w['code'], false ) . '>'
					. esc_html( sprintf( '%02d - %s', $w['code'], $label ) ) . '</option>';
			}
		}
		echo '</select></label>';

		// Commune (peuplée pour la wilaya courante, recalculée en JS au changement).
		echo '<label class="aod-cd-field"><span class="aod-cd-label">' . esc_html__( 'Commune', 'aod-client-dashboard' ) . '</span>';
		echo '<select name="commune" class="aod-cd-od-commune">';
		echo '<option value="">' . esc_html__( '—', 'aod-client-dashboard' ) . '</option>';
		if ( $has_data && $wilaya_code ) {
			foreach ( AOD_COD_Data::communes( $wilaya_code ) as $c ) {
				$label = AOD_COD_Data::label( $c['name'], isset( $c['name_ar'] ) ? $c['name_ar'] : '' );
				echo '<option value="' . esc_attr( $c['name'] ) . '" ' . selected( $commune, $c['name'], false ) . '>'
					. esc_html( $label ) . '</option>';
			}
		} elseif ( '' !== $commune ) {
			echo '<option value="' . esc_attr( $commune ) . '" selected>' . esc_html( $commune ) . '</option>';
		}
		echo '</select></label>';

		echo '</div>'; // /row2

		// Articles : variantes + quantité, avec possibilité de retirer une ligne.
		$items = $order->get_items();
		if ( $items ) {
			echo '<h4 class="aod-cd-od-sub">' . esc_html__( 'Articles', 'aod-client-dashboard' ) . '</h4>';
			echo '<div class="aod-cd-od-items">';
			foreach ( $items as $item_id => $item ) {
				$product = $item->get_product();
				$options = $product ? $this->get_product_options( $product ) : array();

				echo '<div class="aod-cd-od-item" data-item="' . esc_attr( $item_id ) . '">';
				echo '<div class="aod-cd-od-item-head">';
				echo '<strong>' . esc_html( $item->get_name() ) . '</strong>';
				echo '<label class="aod-cd-check aod-cd-od-item-rm"><input type="checkbox" class="aod-cd-od-itemremove"> ' . esc_html__( 'Retirer', 'aod-client-dashboard' ) . '</label>';
				echo '</div>';

				echo '<div class="aod-cd-od-item-fields">';
				foreach ( $options as $sec ) {
					$slabel  = (string) $sec['label'];
					$current = (string) $item->get_meta( $slabel );
					$found   = false;
					echo '<label class="aod-cd-field"><span class="aod-cd-label">' . esc_html( $slabel ) . '</span>';
					echo '<select class="aod-cd-od-itemopt" data-label="' . esc_attr( $slabel ) . '">';
					foreach ( $sec['values'] as $val ) {
						$price  = ( '' !== $val['price'] ) ? (float) $val['price'] : 0;
						$suffix = $price > 0 ? ' (+' . wp_strip_all_tags( wc_price( $price, array( 'currency' => $order->get_currency() ) ) ) . ')' : '';
						$sel    = selected( $current, $val['name'], false );
						if ( '' !== $sel ) {
							$found = true;
						}
						echo '<option value="' . esc_attr( $val['name'] ) . '" ' . $sel . '>' . esc_html( $val['name'] . $suffix ) . '</option>';
					}
					if ( ! $found && '' !== $current ) {
						echo '<option value="' . esc_attr( $current ) . '" selected>' . esc_html( $current ) . '</option>';
					}
					echo '</select></label>';
				}
				echo '<label class="aod-cd-field aod-cd-od-item-qty"><span class="aod-cd-label">' . esc_html__( 'Quantité', 'aod-client-dashboard' ) . '</span>';
				echo '<input type="number" min="1" step="1" class="aod-cd-od-itemqty" value="' . esc_attr( $item->get_quantity() ) . '"></label>';
				echo '</div>'; // /item-fields
				echo '</div>'; // /item
			}
			echo '</div>'; // /items
			echo '<p class="aod-cd-note aod-cd-od-items-note">' . esc_html__( 'Le total est recalculé automatiquement (prix de base + suppléments des variantes).', 'aod-client-dashboard' ) . '</p>';
		}

		// Carte des communes par wilaya pour le cascade JS (uniquement les valeurs nécessaires).
		if ( $has_data ) {
			$map = array();
			foreach ( AOD_COD_Data::places() as $w ) {
				$list = array();
				foreach ( $w['communes'] as $c ) {
					$list[] = array(
						'v' => $c['name'],
						'l' => AOD_COD_Data::label( $c['name'], isset( $c['name_ar'] ) ? $c['name_ar'] : '' ),
					);
				}
				$map[ (int) $w['code'] ] = $list;
			}
			echo '<script type="application/json" class="aod-cd-od-wilmap">' . wp_json_encode( $map ) . '</script>';
		}

		echo '<div class="aod-cd-form-foot">';
		echo '<button type="submit" class="aod-cd-btn aod-cd-btn-primary">' . esc_html__( 'Enregistrer', 'aod-client-dashboard' ) . '</button>';
		echo '<button type="button" class="aod-cd-btn aod-cd-od-edit-cancel">' . esc_html__( 'Annuler', 'aod-client-dashboard' ) . '</button>';
		echo '<span class="aod-cd-form-msg"></span>';
		echo '</div>';

		echo '</form>';
	}

	/**
	 * Contenu HTML du détail d'une commande (produits, adresse, livraison, historique).
	 *
	 * @param WC_Order $order
	 */
	protected function render_order_detail( $order ) {
		$wilaya_code = (int) $order->get_meta( '_aod_wilaya_code' );
		$wilaya      = $order->get_meta( '_aod_wilaya_name' );
		if ( ! $wilaya && $wilaya_code && class_exists( 'AOD_COD_Data' ) ) {
			$wilaya = AOD_COD_Data::wilaya_name( $wilaya_code );
		}
		$commune  = $order->get_meta( '_aod_commune' );
		$delivery = $order->get_meta( '_aod_delivery_type' );
		$tracking = $order->get_meta( '_aod_ship_tracking' );
		$phone    = $order->get_billing_phone();
		$name     = trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() );
		$addr     = $order->get_billing_address_1();
		$status   = wc_get_order_status_name( $order->get_status() );

		// En-tête : statut + date + bouton de modification.
		echo '<div class="aod-cd-od-head">';
		echo '<span class="aod-cd-od-meta">';
		echo '<span class="aod-cd-od-status">' . esc_html( $status ) . '</span>';
		echo '<span class="aod-cd-od-date">' . esc_html( wc_format_datetime( $order->get_date_created(), 'd/m/Y H:i' ) ) . '</span>';
		echo '</span>';
		echo '<button type="button" class="aod-cd-btn aod-cd-btn-sm aod-cd-od-edit-toggle">✏️ ' . esc_html__( 'Modifier', 'aod-client-dashboard' ) . '</button>';
		echo '</div>';

		// Bloc client / livraison (vue en lecture).
		echo '<div class="aod-cd-od-readview">';
		echo '<div class="aod-cd-od-grid">';

		echo '<div class="aod-cd-od-box"><h4>' . esc_html__( 'Client', 'aod-client-dashboard' ) . '</h4><ul class="aod-cd-od-list">';
		echo '<li>👤 ' . esc_html( $name ? $name : '—' ) . '</li>';
		echo '<li>📞 ' . ( $phone ? '<a href="tel:' . esc_attr( $phone ) . '">' . esc_html( $phone ) . '</a>' : '—' ) . '</li>';
		echo '</ul></div>';

		echo '<div class="aod-cd-od-box"><h4>' . esc_html__( 'Livraison', 'aod-client-dashboard' ) . '</h4><ul class="aod-cd-od-list">';
		echo '<li>📍 ' . esc_html( trim( ( $commune ? $commune : '' ) . ( $wilaya ? ( $commune ? ', ' : '' ) . $wilaya : '' ) ) ?: '—' ) . '</li>';
		if ( $addr ) {
			echo '<li>🏠 ' . esc_html( $addr ) . '</li>';
		}
		if ( $delivery ) {
			echo '<li>🚚 ' . esc_html( 'desk' === $delivery ? __( 'Stop-desk (bureau)', 'aod-client-dashboard' ) : __( 'À domicile', 'aod-client-dashboard' ) ) . '</li>';
		}
		if ( class_exists( 'AOD_Shipping' ) ) {
			$ship  = AOD_Shipping::instance();
			$badge = $ship->status_badge_html( $order, 22, true );
			if ( '' !== $badge ) {
				echo '<li class="aod-cd-ship">' . $badge // phpcs:ignore WordPress.Security.EscapeOutput
					. ( $tracking ? ' <code>' . esc_html( $tracking ) . '</code>' : '' ) . '</li>';
			}
		} elseif ( $tracking ) {
			echo '<li class="aod-cd-track">✓ ' . esc_html__( 'Suivi', 'aod-client-dashboard' ) . ' : <code>' . esc_html( $tracking ) . '</code></li>';
		}
		echo '</ul></div>';

		echo '</div>'; // /grid

		// Produits (vue lecture).
		echo '<h4 class="aod-cd-od-sub">' . esc_html__( 'Produits', 'aod-client-dashboard' ) . '</h4>';
		echo '<table class="aod-cd-table" style="min-width:0"><thead><tr>';
		echo '<th>' . esc_html__( 'Article', 'aod-client-dashboard' ) . '</th><th>' . esc_html__( 'Qté', 'aod-client-dashboard' ) . '</th><th>' . esc_html__( 'Total', 'aod-client-dashboard' ) . '</th>';
		echo '</tr></thead><tbody>';
		foreach ( $order->get_items() as $item ) {
			echo '<tr>';
			echo '<td>' . esc_html( $item->get_name() );
			$variants = $this->item_variants( $item );
			if ( $variants ) {
				echo '<br><span class="aod-cd-variants">' . esc_html( implode( ' · ', $variants ) ) . '</span>';
			}
			echo '</td>';
			echo '<td>' . esc_html( $item->get_quantity() ) . '</td>';
			echo '<td>' . wp_kses_post( wc_price( $item->get_total(), array( 'currency' => $order->get_currency() ) ) ) . '</td>';
			echo '</tr>';
		}
		echo '</tbody><tfoot>';
		if ( (float) $order->get_shipping_total() > 0 ) {
			echo '<tr><td colspan="2">' . esc_html__( 'Livraison', 'aod-client-dashboard' ) . '</td><td>' . wp_kses_post( wc_price( $order->get_shipping_total(), array( 'currency' => $order->get_currency() ) ) ) . '</td></tr>';
		}
		echo '<tr><td colspan="2"><strong>' . esc_html__( 'Total', 'aod-client-dashboard' ) . '</strong></td><td><strong>' . wp_kses_post( $order->get_formatted_order_total() ) . '</strong></td></tr>';
		echo '</tfoot></table>';

		echo '</div>'; // /readview

		// Formulaire d'édition (client / livraison + articles), masqué par défaut, basculé en JS.
		$this->render_order_edit_form( $order );

		// Notes & historique de la commande.
		$notes = wc_get_order_notes( array( 'order_id' => $order->get_id() ) );
		if ( $notes ) {
			echo '<h4 class="aod-cd-od-sub">' . esc_html__( 'Notes & historique', 'aod-client-dashboard' ) . '</h4>';
			echo '<ul class="aod-cd-od-notes">';
			foreach ( $notes as $note ) {
				$is_system = ( 'system' === $note->added_by || '' === (string) $note->added_by );
				$author    = $is_system ? __( 'Système', 'aod-client-dashboard' ) : $note->added_by;
				echo '<li class="' . ( $is_system ? 'is-system' : 'is-user' ) . '">';
				echo '<span class="aod-cd-od-notedate">' . esc_html( date_i18n( 'd/m H:i', strtotime( $note->date_created ) ) ) . ' · ' . esc_html( $author ) . '</span> ';
				echo wp_kses_post( wpautop( wptexturize( $note->content ) ) );
				echo '</li>';
			}
			echo '</ul>';
		} else {
			echo '<h4 class="aod-cd-od-sub">' . esc_html__( 'Notes & historique', 'aod-client-dashboard' ) . '</h4>';
			echo '<p class="aod-cd-muted">' . esc_html__( 'Aucune note pour le moment.', 'aod-client-dashboard' ) . '</p>';
		}
	}
}
