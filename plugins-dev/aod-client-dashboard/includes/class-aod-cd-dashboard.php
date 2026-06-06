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
			'orders'    => array( __( 'Commandes', 'aod-client-dashboard' ), '🧾' ),
			'products'  => array( __( 'Produits', 'aod-client-dashboard' ), '📦' ),
			'shipping'  => array( __( 'Livraison', 'aod-client-dashboard' ), '🚚' ),
			'stats'     => array( __( 'Statistiques', 'aod-client-dashboard' ), '📊' ),
			'marketing' => array( __( 'Pixels & Tracking', 'aod-client-dashboard' ), '🎯' ),
			'whatsapp'  => array( __( 'WhatsApp', 'aod-client-dashboard' ), '💬' ),
			'account'   => array( __( 'Mon compte', 'aod-client-dashboard' ), '👤' ),
		);

		add_action( 'init', array( __CLASS__, 'add_rewrite' ) );
		add_filter( 'query_vars', array( $this, 'query_vars' ) );
		add_action( 'template_redirect', array( $this, 'maybe_render' ) );

		// Actions AJAX du dashboard.
		add_action( 'wp_ajax_aod_cd_order_status', array( $this, 'ajax_order_status' ) );
		add_action( 'wp_ajax_aod_cd_order_detail', array( $this, 'ajax_order_detail' ) );
		add_action( 'wp_ajax_aod_cd_save_product', array( $this, 'ajax_save_product' ) );
		add_action( 'wp_ajax_aod_cd_delete_product', array( $this, 'ajax_delete_product' ) );
		add_action( 'wp_ajax_aod_cd_save_shipping', array( $this, 'ajax_save_shipping' ) );
		add_action( 'wp_ajax_aod_cd_save_pixels', array( $this, 'ajax_save_pixels' ) );
		add_action( 'wp_ajax_aod_cd_save_whatsapp', array( $this, 'ajax_save_whatsapp' ) );
		add_action( 'wp_ajax_aod_cd_test_whatsapp', array( $this, 'ajax_test_whatsapp' ) );
		add_action( 'wp_ajax_aod_cd_save_account', array( $this, 'ajax_save_account' ) );
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
	<link rel="stylesheet" href="<?php echo esc_url( AOD_CD_URL . 'assets/css/dashboard.css?v=' . AOD_CD_VERSION ); ?>">
	<script>
		window.AOD_CD = {
			ajaxUrl: <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>,
			nonce:   <?php echo wp_json_encode( wp_create_nonce( 'aod_cd' ) ); ?>,
			base:    <?php echo wp_json_encode( $base ); ?>
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

	<script src="<?php echo esc_url( AOD_CD_URL . 'assets/js/dashboard.js?v=' . AOD_CD_VERSION ); ?>"></script>
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

		$status_options = wc_get_order_statuses(); // [ 'wc-pending' => 'En attente', ... ]

		echo '<div class="aod-cd-tablewrap"><table class="aod-cd-table"><thead><tr>';
		foreach ( array(
			__( 'N°', 'aod-client-dashboard' ),
			__( 'Date', 'aod-client-dashboard' ),
			__( 'Client', 'aod-client-dashboard' ),
			__( 'Téléphone', 'aod-client-dashboard' ),
			__( 'Wilaya', 'aod-client-dashboard' ),
			__( 'Total', 'aod-client-dashboard' ),
			__( 'Livraison', 'aod-client-dashboard' ),
			__( 'Statut', 'aod-client-dashboard' ),
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
		$tracking = $order->get_meta( '_aod_ship_tracking' );
		$status   = 'wc-' . $order->get_status();

		echo '<tr>';
		echo '<td><button type="button" class="aod-cd-order-detail" data-order="' . esc_attr( $order->get_id() ) . '"><strong>#' . esc_html( $order->get_order_number() ) . '</strong></button></td>';
		echo '<td>' . esc_html( wc_format_datetime( $order->get_date_created(), 'd/m/Y H:i' ) ) . '</td>';
		echo '<td>' . esc_html( trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() ) ) . '</td>';
		echo '<td>' . ( $phone ? '<a href="tel:' . esc_attr( $phone ) . '">' . esc_html( $phone ) . '</a>' : '&mdash;' ) . '</td>';
		echo '<td>' . esc_html( $wilaya ? $wilaya : '—' ) . '</td>';
		echo '<td>' . wp_kses_post( $order->get_formatted_order_total() ) . '</td>';
		echo '<td>' . ( $tracking
			? '<span class="aod-cd-track">✓ <code>' . esc_html( $tracking ) . '</code></span>'
			: '<span class="aod-cd-muted">—</span>' ) . '</td>';

		echo '<td><select class="aod-cd-status" data-order="' . esc_attr( $order->get_id() ) . '">';
		foreach ( $status_options as $key => $label ) {
			printf(
				'<option value="%s"%s>%s</option>',
				esc_attr( $key ), selected( $status, $key, false ), esc_html( $label )
			);
		}
		echo '</select></td>';
		echo '</tr>';
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

		// Onglets de période (réutilise le style des onglets de statut).
		echo '<div class="aod-cd-tabs">';
		foreach ( $periods as $slug => $label ) {
			$active = ( $slug === $period ) ? ' is-active' : '';
			$url    = ( 'all' === $slug ) ? $this->stats_url() : $this->stats_url( array( 'periode' => $slug ) );
			printf(
				'<a class="aod-cd-tab%s" href="%s">%s</a>',
				esc_attr( $active ), esc_url( $url ), esc_html( $label )
			);
		}
		echo '</div>';

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

		$revenue = 0.0;
		$top     = array(); // pid|hash => [ name, qty, rev, pid ].
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
						'name' => $item->get_name(),
						'qty'  => 0,
						'rev'  => 0.0,
						'pid'  => $pid,
					);
				}
				$top[ $key ]['qty'] += (int) $item->get_quantity();
				$top[ $key ]['rev'] += (float) $item->get_total();
			}
		}

		$nb_paid = count( $paid );
		$avg     = $nb_paid > 0 ? $revenue / $nb_paid : 0.0;

		// « En attente » reste un indicateur actionnable (toutes périodes) ;
		// « Annulées » est rapporté à la période ; « Produits » est l'état courant.
		$nb_pending = $this->count_orders( 'pending' );
		$nb_cancel  = $this->count_orders( 'cancelled', $date_arg );
		$nb_prod    = (int) wp_count_posts( 'product' )->publish;

		$cards = array(
			array( __( 'Chiffre d’affaires', 'aod-client-dashboard' ), wp_kses_post( wc_price( $revenue ) ) ),
			array( __( 'Commandes encaissées', 'aod-client-dashboard' ), (string) $nb_paid ),
			array( __( 'Panier moyen', 'aod-client-dashboard' ), wp_kses_post( wc_price( $avg ) ) ),
			array( __( 'En attente (en cours)', 'aod-client-dashboard' ), (string) $nb_pending ),
			array( __( 'Annulées', 'aod-client-dashboard' ), (string) $nb_cancel ),
			array( __( 'Produits en ligne', 'aod-client-dashboard' ), (string) $nb_prod ),
		);

		echo '<div class="aod-cd-cards">';
		foreach ( $cards as $c ) {
			echo '<div class="aod-cd-card"><div class="aod-cd-card-val">' . wp_kses_post( $c[1] ) . '</div><div class="aod-cd-card-lab">' . esc_html( $c[0] ) . '</div></div>';
		}
		echo '</div>';

		// Graphe du CA dans le temps (barres CSS, sans JS).
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
				echo '<tr><td>' . $name . '</td><td>' . (int) $row['qty'] . '</td><td>' . wp_kses_post( wc_price( $row['rev'] ) ) . '</td></tr>';
			}
			echo '</tbody></table></div>';
		}

		echo '<p class="aod-cd-note">' . esc_html__( 'CA = total des commandes en cours, confirmées et terminées sur la période sélectionnée. « En attente » et « Produits en ligne » reflètent l’état actuel, toutes périodes confondues.', 'aod-client-dashboard' ) . '</p>';
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
	 * Affiche un graphe en barres (CSS pur, sans JS) du CA par bac.
	 *
	 * @param array $bins Bacs renvoyés par stats_bins() et alimentés en CA.
	 */
	protected function render_stats_chart( $bins ) {
		if ( empty( $bins ) ) {
			return; // Période « tout » : pas de fenêtre fixe à représenter.
		}

		$max = 0.0;
		foreach ( $bins as $b ) {
			if ( $b['val'] > $max ) {
				$max = $b['val'];
			}
		}

		echo '<h2 class="aod-cd-form-subtitle" style="margin-top:26px">' . esc_html__( 'Évolution du chiffre d’affaires', 'aod-client-dashboard' ) . '</h2>';

		if ( $max <= 0 ) {
			echo '<p class="aod-cd-empty">' . esc_html__( 'Aucune vente sur cette période.', 'aod-client-dashboard' ) . '</p>';
			return;
		}

		echo '<div class="aod-cd-chart" role="img" aria-label="' . esc_attr__( 'Chiffre d’affaires sur la période', 'aod-client-dashboard' ) . '">';
		foreach ( $bins as $b ) {
			$h      = ( $b['val'] > 0 ) ? max( 2, (int) round( $b['val'] / $max * 100 ) ) : 0;
			$amount = wp_strip_all_tags( wc_price( $b['val'] ) ); // wc_price renvoie du HTML.
			echo '<div class="aod-cd-chart-col">';
			echo '<div class="aod-cd-chart-barwrap" title="' . esc_attr( $b['full'] . ' — ' . $amount ) . '">';
			echo '<span class="aod-cd-chart-bar" style="height:' . (int) $h . '%"></span>';
			echo '</div>';
			echo '<span class="aod-cd-chart-lab">' . ( ! empty( $b['show'] ) ? esc_html( $b['label'] ) : '&nbsp;' ) . '</span>';
			echo '</div>';
		}
		echo '</div>';
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

		// Variations couleur existantes (produit variable).
		$color_rows = array();
		if ( $product && $product->is_type( 'variable' ) ) {
			foreach ( $product->get_children() as $child_id ) {
				$v = wc_get_product( $child_id );
				if ( ! $v ) {
					continue;
				}
				$atts  = $v->get_attributes();
				$color = isset( $atts['couleur'] ) ? $atts['couleur'] : ( $atts ? reset( $atts ) : '' );
				$color_rows[] = array(
					'varid' => $child_id,
					'name'  => $color,
					'price' => $v->get_regular_price(),
					'sale'  => $v->get_sale_price(),
					'stock' => $v->managing_stock() ? (string) $v->get_stock_quantity() : '',
					'sku'   => $v->get_sku(),
					'img'   => $v->get_image_id() ? wp_get_attachment_image_url( $v->get_image_id(), 'thumbnail' ) : '',
				);
			}
		}

		$categories = get_terms( array( 'taxonomy' => 'product_cat', 'hide_empty' => false ) );

		$title = $pid ? __( 'Modifier le produit', 'aod-client-dashboard' ) : __( 'Nouveau produit', 'aod-client-dashboard' );
		?>
		<div class="aod-cd-bar">
			<a class="aod-cd-btn" href="<?php echo esc_url( $this->products_url() ); ?>">&larr; <?php esc_html_e( 'Retour à la liste', 'aod-client-dashboard' ); ?></a>
		</div>

		<form class="aod-cd-form" id="aod-cd-product-form" enctype="multipart/form-data">
			<input type="hidden" name="product_id" value="<?php echo esc_attr( (string) $pid ); ?>">
			<h2 class="aod-cd-form-title"><?php echo esc_html( $title ); ?></h2>

			<div class="aod-cd-grid">
				<div class="aod-cd-col">
					<label class="aod-cd-field">
						<span class="aod-cd-label"><?php esc_html_e( 'Nom du produit', 'aod-client-dashboard' ); ?> *</span>
						<input type="text" name="name" required value="<?php echo esc_attr( $name ); ?>">
					</label>

					<label class="aod-cd-field">
						<span class="aod-cd-label"><?php esc_html_e( 'Description', 'aod-client-dashboard' ); ?></span>
						<textarea name="description" rows="6"><?php echo esc_textarea( $desc ); ?></textarea>
					</label>

					<div class="aod-cd-row2">
						<label class="aod-cd-field">
							<span class="aod-cd-label"><?php printf( esc_html__( 'Prix (%s)', 'aod-client-dashboard' ), esc_html( $currency ) ); ?> *</span>
							<input type="number" step="0.01" min="0" name="regular_price" required value="<?php echo esc_attr( $reg ); ?>">
							<span class="aod-cd-note" style="font-size:12px;margin-top:2px"><?php esc_html_e( 'Si vous ajoutez des couleurs ci-dessous, ce prix sert de prix par défaut (utilisé quand une couleur n’a pas son propre prix).', 'aod-client-dashboard' ); ?></span>
						</label>
						<label class="aod-cd-field">
							<span class="aod-cd-label"><?php esc_html_e( 'Prix promo (optionnel)', 'aod-client-dashboard' ); ?></span>
							<input type="number" step="0.01" min="0" name="sale_price" value="<?php echo esc_attr( $sale ); ?>">
						</label>
					</div>

					<label class="aod-cd-field">
						<span class="aod-cd-label"><?php esc_html_e( 'Référence / SKU (optionnel)', 'aod-client-dashboard' ); ?></span>
						<input type="text" name="sku" value="<?php echo esc_attr( $sku ); ?>">
					</label>
				</div>

				<div class="aod-cd-col">
					<div class="aod-cd-field">
						<span class="aod-cd-label"><?php esc_html_e( 'Photo principale', 'aod-client-dashboard' ); ?></span>
						<div class="aod-cd-imgbox">
							<img class="aod-cd-imgprev" src="<?php echo esc_url( $img_url ); ?>" alt="" <?php echo $img_url ? '' : 'style="display:none"'; ?>>
							<span class="aod-cd-imgempty" <?php echo $img_url ? 'style="display:none"' : ''; ?>><?php esc_html_e( 'Aucune image', 'aod-client-dashboard' ); ?></span>
						</div>
						<input type="file" name="image" accept="image/*" class="aod-cd-imgfile">
					</div>

					<label class="aod-cd-field">
						<span class="aod-cd-label"><?php esc_html_e( 'Catégorie', 'aod-client-dashboard' ); ?></span>
						<select name="category">
							<option value="0"><?php esc_html_e( '— Aucune —', 'aod-client-dashboard' ); ?></option>
							<?php if ( ! is_wp_error( $categories ) ) :
								foreach ( $categories as $cat ) :
									$sel = in_array( $cat->term_id, (array) $cat_ids, true ) ? ' selected' : '';
									echo '<option value="' . esc_attr( $cat->term_id ) . '"' . esc_attr( $sel ) . '>' . esc_html( $cat->name ) . '</option>';
								endforeach;
							endif; ?>
						</select>
					</label>

					<label class="aod-cd-field">
						<span class="aod-cd-label"><?php esc_html_e( 'Nouvelle catégorie (optionnel)', 'aod-client-dashboard' ); ?></span>
						<input type="text" name="new_category" placeholder="<?php esc_attr_e( 'Crée et assigne une catégorie', 'aod-client-dashboard' ); ?>">
					</label>

					<div class="aod-cd-field">
						<label class="aod-cd-check">
							<input type="checkbox" name="manage_stock" value="1" <?php checked( $manage ); ?> class="aod-cd-stock-toggle">
							<?php esc_html_e( 'Gérer le stock', 'aod-client-dashboard' ); ?>
						</label>
						<input type="number" min="0" name="stock_quantity" class="aod-cd-stock-qty" value="<?php echo esc_attr( (string) $qty ); ?>" placeholder="<?php esc_attr_e( 'Quantité', 'aod-client-dashboard' ); ?>" <?php echo $manage ? '' : 'style="display:none"'; ?>>
					</div>

					<label class="aod-cd-field">
						<span class="aod-cd-label"><?php esc_html_e( 'Statut', 'aod-client-dashboard' ); ?></span>
						<select name="status">
							<option value="publish" <?php selected( $status, 'publish' ); ?>><?php esc_html_e( 'En ligne', 'aod-client-dashboard' ); ?></option>
							<option value="draft" <?php selected( $status, 'draft' ); ?>><?php esc_html_e( 'Brouillon', 'aod-client-dashboard' ); ?></option>
						</select>
					</label>
				</div>
			</div>

			<div class="aod-cd-colors" id="aod-cd-colors">
				<h3 class="aod-cd-form-subtitle"><?php esc_html_e( 'Couleurs / Variantes', 'aod-client-dashboard' ); ?></h3>
				<p class="aod-cd-note" style="margin-top:0"><?php esc_html_e( 'Laissez vide pour un produit simple. Ajoutez une ligne par couleur : chacune peut avoir son prix, son stock et sa photo. Le client choisira la couleur sur la page produit.', 'aod-client-dashboard' ); ?></p>

				<div class="aod-cd-color-head">
					<span></span>
					<span><?php esc_html_e( 'Couleur', 'aod-client-dashboard' ); ?></span>
					<span><?php printf( esc_html__( 'Prix (%s)', 'aod-client-dashboard' ), esc_html( $currency ) ); ?></span>
					<span><?php esc_html_e( 'Promo', 'aod-client-dashboard' ); ?></span>
					<span><?php esc_html_e( 'Stock', 'aod-client-dashboard' ); ?></span>
					<span></span>
				</div>

				<div class="aod-cd-color-rows">
					<?php
					$ri = 0;
					foreach ( $color_rows as $row ) {
						$this->render_color_row( $ri, $row );
						$ri++;
					}
					?>
				</div>

				<button type="button" class="aod-cd-btn aod-cd-btn-sm aod-cd-color-add" data-next="<?php echo esc_attr( (string) $ri ); ?>">+ <?php esc_html_e( 'Ajouter une couleur', 'aod-client-dashboard' ); ?></button>

				<template id="aod-cd-color-tpl">
					<?php $this->render_color_row( '__i__', array() ); ?>
				</template>
			</div>

			<div class="aod-cd-form-foot">
				<button type="submit" class="aod-cd-btn aod-cd-btn-primary"><?php esc_html_e( 'Enregistrer', 'aod-client-dashboard' ); ?></button>
				<a class="aod-cd-btn" href="<?php echo esc_url( $this->products_url() ); ?>"><?php esc_html_e( 'Annuler', 'aod-client-dashboard' ); ?></a>
				<span class="aod-cd-form-msg"></span>
			</div>
		</form>
		<?php
	}

	/**
	 * Une ligne de variante couleur (édition produit).
	 *
	 * @param int|string $i   Index de la ligne (ou « __i__ » pour le gabarit JS).
	 * @param array      $row varid, name, price, sale, stock, sku, img.
	 */
	protected function render_color_row( $i, $row ) {
		$row = wp_parse_args( $row, array( 'varid' => 0, 'name' => '', 'price' => '', 'sale' => '', 'stock' => '', 'sku' => '', 'img' => '' ) );
		$idx = esc_attr( (string) $i );
		?>
		<div class="aod-cd-color-row" data-row="<?php echo $idx; ?>">
			<input type="hidden" name="color_varid[<?php echo $idx; ?>]" value="<?php echo esc_attr( (string) $row['varid'] ); ?>">
			<label class="aod-cd-color-imgbox">
				<img class="aod-cd-color-imgprev" src="<?php echo esc_url( $row['img'] ); ?>" alt="" <?php echo $row['img'] ? '' : 'style="display:none"'; ?>>
				<span class="aod-cd-color-imgempty" <?php echo $row['img'] ? 'style="display:none"' : ''; ?>>📷</span>
				<input type="file" name="color_image_<?php echo $idx; ?>" accept="image/*" class="aod-cd-color-imgfile">
			</label>
			<input type="text" name="color_name[<?php echo $idx; ?>]" class="aod-cd-color-name" value="<?php echo esc_attr( $row['name'] ); ?>" placeholder="<?php esc_attr_e( 'ex : Rouge', 'aod-client-dashboard' ); ?>">
			<input type="number" step="0.01" min="0" name="color_price[<?php echo $idx; ?>]" value="<?php echo esc_attr( $row['price'] ); ?>" placeholder="<?php esc_attr_e( 'défaut', 'aod-client-dashboard' ); ?>">
			<input type="number" step="0.01" min="0" name="color_sale[<?php echo $idx; ?>]" value="<?php echo esc_attr( $row['sale'] ); ?>" placeholder="—">
			<input type="number" min="0" name="color_stock[<?php echo $idx; ?>]" value="<?php echo esc_attr( $row['stock'] ); ?>" placeholder="<?php esc_attr_e( '∞', 'aod-client-dashboard' ); ?>">
			<button type="button" class="aod-cd-color-del" aria-label="<?php esc_attr_e( 'Supprimer cette couleur', 'aod-client-dashboard' ); ?>">&times;</button>
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

		// Couleurs envoyées ? (au moins une ligne avec un nom non vide → produit variable.)
		$colors = $this->collect_color_rows();

		$product = $pid ? wc_get_product( $pid ) : new WC_Product_Simple();
		if ( ! $product ) {
			wp_send_json_error( array( 'message' => __( 'Produit introuvable.', 'aod-client-dashboard' ) ), 404 );
		}

		$product->set_name( $name );
		$product->set_description( isset( $_POST['description'] ) ? wp_kses_post( wp_unslash( $_POST['description'] ) ) : '' );

		$reg  = isset( $_POST['regular_price'] ) ? wc_format_decimal( wp_unslash( $_POST['regular_price'] ) ) : '';
		$sale = isset( $_POST['sale_price'] ) ? wc_format_decimal( wp_unslash( $_POST['sale_price'] ) ) : '';

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

		// Catégories : existante sélectionnée + éventuelle nouvelle.
		$cat_ids = array();
		if ( ! empty( $_POST['category'] ) ) {
			$cat_ids[] = absint( $_POST['category'] );
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
		if ( $cat_ids ) {
			$product->set_category_ids( array_values( array_unique( array_filter( $cat_ids ) ) ) );
		}

		if ( $colors ) {
			// Produit variable : le parent n'a ni stock ni prix propres ; le prix
			// principal sert de prix par défaut pour les couleurs sans prix.
			$product->set_regular_price( $reg );
			$product->set_sale_price( '' !== $sale ? $sale : '' );
			$product->set_manage_stock( false );
		} else {
			// Produit simple : prix + stock classiques.
			$product->set_regular_price( $reg );
			$product->set_sale_price( '' !== $sale ? $sale : '' );
			$manage = ! empty( $_POST['manage_stock'] );
			$product->set_manage_stock( $manage );
			if ( $manage ) {
				$qty = isset( $_POST['stock_quantity'] ) ? wc_stock_amount( wp_unslash( $_POST['stock_quantity'] ) ) : 0;
				$product->set_stock_quantity( $qty );
				$product->set_stock_status( $qty > 0 ? 'instock' : 'outofstock' );
			}
		}

		$product->save();
		$new_id = $product->get_id();

		// Bascule du type de produit si nécessaire (simple <-> variable), puis ré-instanciation.
		$want_type = $colors ? 'variable' : 'simple';
		if ( ! $product->is_type( $want_type ) ) {
			wp_set_object_terms( $new_id, $want_type, 'product_type' );
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

		// Variations couleur.
		if ( $colors ) {
			$this->save_color_variations( $product, $colors, '' !== $reg ? $reg : '' );
		}

		wp_send_json_success( array(
			'message'  => $pid ? __( 'Produit mis à jour.', 'aod-client-dashboard' ) : __( 'Produit créé.', 'aod-client-dashboard' ),
			'redirect' => $this->products_url(),
		) );
	}

	/**
	 * Rassemble les lignes de couleur postées (uniquement celles avec un nom).
	 *
	 * @return array Liste de [ index, name, price, sale, stock, sku ].
	 */
	protected function collect_color_rows() {
		if ( empty( $_POST['color_name'] ) || ! is_array( $_POST['color_name'] ) ) {
			return array();
		}
		$names = wp_unslash( $_POST['color_name'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$rows  = array();
		foreach ( $names as $i => $raw ) {
			$label = sanitize_text_field( trim( (string) $raw ) );
			if ( '' === $label ) {
				continue;
			}
			$rows[] = array(
				'index' => $i,
				'name'  => $label,
				'varid' => isset( $_POST['color_varid'][ $i ] ) ? absint( $_POST['color_varid'][ $i ] ) : 0,
				'price' => isset( $_POST['color_price'][ $i ] ) ? trim( (string) wp_unslash( $_POST['color_price'][ $i ] ) ) : '',
				'sale'  => isset( $_POST['color_sale'][ $i ] ) ? trim( (string) wp_unslash( $_POST['color_sale'][ $i ] ) ) : '',
				'stock' => isset( $_POST['color_stock'][ $i ] ) ? trim( (string) wp_unslash( $_POST['color_stock'][ $i ] ) ) : '',
				'sku'   => isset( $_POST['color_sku'][ $i ] ) ? sanitize_text_field( wp_unslash( $_POST['color_sku'][ $i ] ) ) : '',
			);
		}
		return $rows;
	}

	/**
	 * Crée / met à jour les variations couleur d'un produit variable et supprime
	 * celles qui ne sont plus présentes.
	 *
	 * @param WC_Product_Variable $product
	 * @param array               $colors        Lignes issues de collect_color_rows().
	 * @param string              $default_price Prix par défaut (prix principal).
	 */
	protected function save_color_variations( $product, $colors, $default_price ) {
		$pid = $product->get_id();

		// 1) Attribut « Couleur » (local, utilisé pour les variations).
		$attribute = new WC_Product_Attribute();
		$attribute->set_id( 0 );
		$attribute->set_name( 'Couleur' );
		$attribute->set_options( wp_list_pluck( $colors, 'name' ) );
		$attribute->set_position( 0 );
		$attribute->set_visible( true );
		$attribute->set_variation( true );
		$product->set_attributes( array( $attribute ) );
		$product->save();

		require_once ABSPATH . 'wp-admin/includes/image.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';

		// 2) Index des variations existantes : varid + couleur => id.
		$existing_by_id    = array();
		$existing_by_color = array();
		foreach ( $product->get_children() as $cid ) {
			$existing_by_id[ (int) $cid ] = true;
			$cv = wc_get_product( $cid );
			if ( $cv ) {
				$a = $cv->get_attributes();
				$c = isset( $a['couleur'] ) ? $a['couleur'] : ( $a ? reset( $a ) : '' );
				if ( '' !== $c ) {
					$existing_by_color[ $c ] = (int) $cid;
				}
			}
		}

		$kept = array();
		foreach ( $colors as $row ) {
			$label = $row['name'];

			// Retrouver la variation : par varid posté, sinon par couleur, sinon nouvelle.
			$variation = null;
			if ( $row['varid'] && isset( $existing_by_id[ $row['varid'] ] ) ) {
				$variation = wc_get_product( $row['varid'] );
			}
			if ( ( ! $variation || ! $variation->is_type( 'variation' ) ) && isset( $existing_by_color[ $label ] ) ) {
				$variation = wc_get_product( $existing_by_color[ $label ] );
			}
			if ( ! $variation || ! $variation->is_type( 'variation' ) ) {
				$variation = new WC_Product_Variation();
				$variation->set_parent_id( $pid );
			}

			$variation->set_attributes( array( 'couleur' => $label ) );

			$price = ( '' !== $row['price'] ) ? wc_format_decimal( $row['price'] ) : $default_price;
			$variation->set_regular_price( $price );
			$sale = ( '' !== $row['sale'] ) ? wc_format_decimal( $row['sale'] ) : '';
			$variation->set_sale_price( '' !== $sale ? $sale : '' );

			if ( '' !== $row['stock'] ) {
				$q = wc_stock_amount( $row['stock'] );
				$variation->set_manage_stock( true );
				$variation->set_stock_quantity( $q );
				$variation->set_stock_status( $q > 0 ? 'instock' : 'outofstock' );
			} else {
				$variation->set_manage_stock( false );
				$variation->set_stock_status( 'instock' );
			}

			if ( '' !== $row['sku'] ) {
				try {
					$variation->set_sku( $row['sku'] );
				} catch ( Exception $e ) {
					// SKU en doublon : on ignore pour ne pas bloquer l'enregistrement.
				}
			}

			$variation->set_status( 'publish' );
			$vid = $variation->save();

			// Photo de la couleur (champ fichier color_image_<index>).
			$file_key = 'color_image_' . $row['index'];
			if ( ! empty( $_FILES[ $file_key ]['name'] ) ) {
				$att = media_handle_upload( $file_key, $pid );
				if ( ! is_wp_error( $att ) ) {
					$variation->set_image_id( $att );
					$variation->save();
				}
			}

			$kept[] = (int) $vid;
		}

		// 3) Supprimer les variations retirées.
		foreach ( $product->get_children() as $cid ) {
			if ( ! in_array( (int) $cid, $kept, true ) ) {
				$old = wc_get_product( $cid );
				if ( $old ) {
					$old->delete( true );
				}
			}
		}

		// 4) Resynchroniser le produit variable (fourchette de prix, stock global).
		if ( class_exists( 'WC_Product_Variable' ) ) {
			WC_Product_Variable::sync( $pid );
		}
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
			<div class="aod-cd-scroll">
				<table class="aod-cd-table aod-cd-pricetable">
					<thead><tr>
						<th><?php esc_html_e( 'Wilaya', 'aod-client-dashboard' ); ?></th>
						<th><?php printf( esc_html__( 'Domicile (%s)', 'aod-client-dashboard' ), esc_html( $symbol ) ); ?></th>
						<th><?php printf( esc_html__( 'Stop-desk (%s)', 'aod-client-dashboard' ), esc_html( $symbol ) ); ?></th>
					</tr></thead>
					<tbody>
						<?php foreach ( AOD_COD_Data::places() as $w ) :
							$code = (int) $w['code']; ?>
							<tr>
								<td><?php echo esc_html( sprintf( '%02d - %s', $code, $w['name'] ) ); ?></td>
								<td><input type="number" min="0" step="any" name="home[<?php echo esc_attr( $code ); ?>]" value="<?php echo isset( $prices[ $code ]['home'] ) ? esc_attr( $prices[ $code ]['home'] ) : ''; ?>"></td>
								<td><input type="number" min="0" step="any" name="desk[<?php echo esc_attr( $code ); ?>]" value="<?php echo isset( $prices[ $code ]['desk'] ) ? esc_attr( $prices[ $code ]['desk'] ) : ''; ?>"></td>
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

				<?php foreach ( $carriers as $c ) : ?>
					<div class="aod-cd-carrier">
						<h3 class="aod-cd-carrier-h">
							<?php echo esc_html( $c->label() ); ?>
							<?php if ( $c->is_configured() ) : ?>
								<span class="aod-cd-ok-badge"><?php esc_html_e( 'Configuré', 'aod-client-dashboard' ); ?></span>
							<?php endif; ?>
						</h3>
						<?php $c->render_settings_fields(); // Réutilise les champs du plugin COD (noms <id>[champ]). ?>
					</div>
				<?php endforeach; ?>
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
		$prices = array();
		foreach ( AOD_COD_Data::places() as $w ) {
			$code = (int) $w['code'];
			$h    = isset( $home[ $code ] ) ? wc_format_decimal( wp_unslash( $home[ $code ] ) ) : '';
			$d    = isset( $desk[ $code ] ) ? wc_format_decimal( wp_unslash( $desk[ $code ] ) ) : '';
			$prices[ $code ] = array(
				'home' => ( '' === $h ) ? '' : (float) $h,
				'desk' => ( '' === $d ) ? '' : (float) $d,
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
		<form class="aod-cd-form aod-cd-settings-form" data-action="aod_cd_save_account" style="max-width:520px">
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

		// En-tête : statut + date.
		echo '<div class="aod-cd-od-head">';
		echo '<span class="aod-cd-od-status">' . esc_html( $status ) . '</span>';
		echo '<span class="aod-cd-od-date">' . esc_html( wc_format_datetime( $order->get_date_created(), 'd/m/Y H:i' ) ) . '</span>';
		echo '</div>';

		// Bloc client / livraison.
		echo '<div class="aod-cd-od-grid">';

		echo '<div class="aod-cd-od-box"><h4>' . esc_html__( 'Client', 'aod-client-dashboard' ) . '</h4><ul class="aod-cd-od-list">';
		echo '<li>👤 ' . esc_html( $name ? $name : '—' ) . '</li>';
		echo '<li>📞 ' . ( $phone ? '<a href="tel:' . esc_attr( $phone ) . '">' . esc_html( $phone ) . '</a>' : '—' ) . '</li>';
		if ( $order->get_billing_email() ) {
			echo '<li>✉️ ' . esc_html( $order->get_billing_email() ) . '</li>';
		}
		echo '</ul></div>';

		echo '<div class="aod-cd-od-box"><h4>' . esc_html__( 'Livraison', 'aod-client-dashboard' ) . '</h4><ul class="aod-cd-od-list">';
		echo '<li>📍 ' . esc_html( trim( ( $commune ? $commune : '' ) . ( $wilaya ? ( $commune ? ', ' : '' ) . $wilaya : '' ) ) ?: '—' ) . '</li>';
		if ( $addr ) {
			echo '<li>🏠 ' . esc_html( $addr ) . '</li>';
		}
		if ( $delivery ) {
			echo '<li>🚚 ' . esc_html( 'desk' === $delivery ? __( 'Stop-desk (bureau)', 'aod-client-dashboard' ) : __( 'À domicile', 'aod-client-dashboard' ) ) . '</li>';
		}
		if ( $tracking ) {
			echo '<li class="aod-cd-track">✓ ' . esc_html__( 'Suivi', 'aod-client-dashboard' ) . ' : <code>' . esc_html( $tracking ) . '</code></li>';
		}
		echo '</ul></div>';

		echo '</div>'; // /grid

		// Produits.
		echo '<h4 class="aod-cd-od-sub">' . esc_html__( 'Produits', 'aod-client-dashboard' ) . '</h4>';
		echo '<table class="aod-cd-table" style="min-width:0"><thead><tr>';
		echo '<th>' . esc_html__( 'Article', 'aod-client-dashboard' ) . '</th><th>' . esc_html__( 'Qté', 'aod-client-dashboard' ) . '</th><th>' . esc_html__( 'Total', 'aod-client-dashboard' ) . '</th>';
		echo '</tr></thead><tbody>';
		foreach ( $order->get_items() as $item ) {
			echo '<tr>';
			echo '<td>' . esc_html( $item->get_name() ) . '</td>';
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

		// Historique (notes de commande).
		$notes = wc_get_order_notes( array( 'order_id' => $order->get_id() ) );
		if ( $notes ) {
			echo '<h4 class="aod-cd-od-sub">' . esc_html__( 'Historique', 'aod-client-dashboard' ) . '</h4>';
			echo '<ul class="aod-cd-od-notes">';
			foreach ( $notes as $note ) {
				echo '<li><span class="aod-cd-od-notedate">' . esc_html( date_i18n( 'd/m H:i', strtotime( $note->date_created ) ) ) . '</span> ' . wp_kses_post( wpautop( wptexturize( $note->content ) ) ) . '</li>';
			}
			echo '</ul>';
		}
	}
}
