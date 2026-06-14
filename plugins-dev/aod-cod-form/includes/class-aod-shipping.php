<?php
/**
 * Orchestrateur des livreurs : réglages, encart commande, AJAX, actions groupées.
 *
 * @package AOD_COD_Form
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AOD_Shipping {

	/** @var AOD_Shipping|null */
	protected static $instance = null;

	/** @var AOD_Carrier[] Livreurs enregistrés, indexés par id. */
	protected $carriers = array();

	/** Option des réglages d'envoi automatique. */
	const AUTO_OPTION = 'aod_shipping_auto';

	/** Slug du statut de commande déclencheur (sans le préfixe wc-). */
	const STATUS = 'aod-confirmed';

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		require_once AOD_COD_PATH . 'includes/class-aod-carrier.php';
		require_once AOD_COD_PATH . 'includes/class-aod-carrier-yalidine.php';
		require_once AOD_COD_PATH . 'includes/class-aod-carrier-yalitec.php';
		require_once AOD_COD_PATH . 'includes/class-aod-carrier-noest.php';
		require_once AOD_COD_PATH . 'includes/class-aod-carrier-procolis.php';
		require_once AOD_COD_PATH . 'includes/class-aod-carrier-maystro.php';
		require_once AOD_COD_PATH . 'includes/class-aod-carrier-ecotrack.php';

		// Transporteurs « majors » (API propre).
		foreach ( array(
			new AOD_Carrier_Yalidine(),
			new AOD_Carrier_Procolis(),   // ZR Express
			new AOD_Carrier_Maystro(),
			new AOD_Carrier_Noest(),
			new AOD_Carrier_Yalitec(),
			new AOD_Carrier_Ecotrack(),   // EcoTrack générique (domaine + token manuels)
		) as $c ) {
			$this->carriers[ $c->id() ] = $c;
		}

		// Transporteurs « white-label » EcoTrack : même API, domaine + marque pré-remplis.
		// Le client n'a qu'à coller son token. Voir la liste CourierDZ.
		foreach ( $this->ecotrack_whitelabels() as $cfg ) {
			$c = new AOD_Carrier_Ecotrack( $cfg );
			$this->carriers[ $c->id() ] = $c;
		}

		// Statut de commande personnalisé « Confirmée » (déclencheur d'envoi auto).
		// Enregistré partout (pas seulement en admin) pour rester valide côté REST/e-mails.
		add_action( 'init', array( $this, 'register_order_status' ) );
		add_filter( 'wc_order_statuses', array( $this, 'add_order_status' ) );
		add_action( 'woocommerce_order_status_' . self::STATUS, array( $this, 'auto_send' ), 10, 2 );

		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_init', array( $this, 'maybe_save_settings' ) );

		add_action( 'add_meta_boxes', array( $this, 'add_metabox' ) );

		// Colonne « Livraison » dans la liste des commandes (HPOS + legacy).
		add_filter( 'manage_woocommerce_page_wc-orders_columns', array( $this, 'add_order_column' ) );
		add_action( 'manage_woocommerce_page_wc-orders_custom_column', array( $this, 'render_order_column_hpos' ), 10, 2 );
		add_filter( 'manage_edit-shop_order_columns', array( $this, 'add_order_column' ) );
		add_action( 'manage_shop_order_posts_custom_column', array( $this, 'render_order_column_legacy' ), 10, 2 );

		add_action( 'wp_ajax_aod_ship_send', array( $this, 'ajax_send' ) );
		add_action( 'wp_ajax_aod_ship_centers', array( $this, 'ajax_centers' ) );

		// Actions groupées (HPOS + legacy).
		foreach ( array( 'bulk_actions-woocommerce_page_wc-orders', 'bulk_actions-edit-shop_order' ) as $f ) {
			add_filter( $f, array( $this, 'register_bulk' ) );
		}
		foreach ( array( 'handle_bulk_actions-woocommerce_page_wc-orders', 'handle_bulk_actions-edit-shop_order' ) as $f ) {
			add_filter( $f, array( $this, 'handle_bulk' ), 10, 3 );
		}
		add_action( 'admin_notices', array( $this, 'bulk_notice' ) );
	}

	/**
	 * Liste des transporteurs « white-label » EcoTrack (même API, domaine + marque).
	 *
	 * @return array[] Config : id, label, domain, brand, initials.
	 */
	protected function ecotrack_whitelabels() {
		return array(
			array( 'id' => 'allolivraison', 'label' => 'Allo Livraison',    'domain' => 'allolivraison.ecotrack.dz',    'brand' => '#0ea5e9', 'initials' => 'AL' ),
			array( 'id' => 'rex',          'label' => 'Rex Livraison',     'domain' => 'rex.ecotrack.dz',              'brand' => '#db2777', 'initials' => 'REX' ),
			array( 'id' => 'golivri',      'label' => 'GOLIVRI',           'domain' => 'golivri.ecotrack.dz',          'brand' => '#0d9488', 'initials' => 'GO' ),
			array( 'id' => 'dhd',          'label' => 'DHD',               'domain' => 'dhd.ecotrack.dz',              'brand' => '#b45309', 'initials' => 'DHD' ),
			array( 'id' => 'anderson',     'label' => 'Anderson Delivery', 'domain' => 'anderson.ecotrack.dz',         'brand' => '#4f46e5', 'initials' => 'AN' ),
			array( 'id' => 'areex',        'label' => 'Areex',             'domain' => 'areex.ecotrack.dz',            'brand' => '#0891b2', 'initials' => 'AR' ),
			array( 'id' => 'bacexpress',   'label' => 'BAC Express',       'domain' => 'bacexpress.ecotrack.dz',       'brand' => '#9333ea', 'initials' => 'BAC' ),
			array( 'id' => 'conexlog',     'label' => 'Conexlog (UPS)',    'domain' => 'app.conexlog-dz.com',          'brand' => '#a16207', 'initials' => 'UPS' ),
			array( 'id' => 'coyote',       'label' => 'Coyote Express',    'domain' => 'coyoteexpressdz.ecotrack.dz',  'brand' => '#c2410c', 'initials' => 'CY' ),
			array( 'id' => 'distazero',    'label' => 'Distazero',         'domain' => 'distazero.ecotrack.dz',        'brand' => '#2563eb', 'initials' => 'DZ' ),
			array( 'id' => 'e48hr',        'label' => '48HR Livraison',    'domain' => '48hr.ecotrack.dz',             'brand' => '#16a34a', 'initials' => '48' ),
			array( 'id' => 'fretdirect',   'label' => 'Fret Direct',       'domain' => 'fret.ecotrack.dz',             'brand' => '#475569', 'initials' => 'FD' ),
			array( 'id' => 'monohub',      'label' => 'MonoHub',           'domain' => 'mono.ecotrack.dz',             'brand' => '#7c3aed', 'initials' => 'MH' ),
			array( 'id' => 'msmgo',        'label' => 'MSM Go',            'domain' => 'msmgo.ecotrack.dz',            'brand' => '#dc2626', 'initials' => 'MSM' ),
			array( 'id' => 'negmar',       'label' => 'Negmar Express',    'domain' => 'negmar.ecotrack.dz',           'brand' => '#0f766e', 'initials' => 'NG' ),
			array( 'id' => 'packers',      'label' => 'Packers',           'domain' => 'packers.ecotrack.dz',          'brand' => '#ca8a04', 'initials' => 'PK' ),
			array( 'id' => 'prest',        'label' => 'Prest',             'domain' => 'prest.ecotrack.dz',            'brand' => '#e11d48', 'initials' => 'PR' ),
			array( 'id' => 'rblivraison',  'label' => 'RB Livraison',      'domain' => 'rblivraison.ecotrack.dz',      'brand' => '#1d4ed8', 'initials' => 'RB' ),
			array( 'id' => 'rocket',       'label' => 'Rocket Delivery',   'domain' => 'rocket.ecotrack.dz',           'brand' => '#f59e0b', 'initials' => 'RK' ),
			array( 'id' => 'salva',        'label' => 'Salva Delivery',    'domain' => 'salvadelivery.ecotrack.dz',    'brand' => '#15803d', 'initials' => 'SV' ),
			array( 'id' => 'speed',        'label' => 'Speed Delivery',    'domain' => 'speeddelivery.ecotrack.dz',    'brand' => '#ea580c', 'initials' => 'SP' ),
			array( 'id' => 'tsl',          'label' => 'TSL Express',       'domain' => 'tsl.ecotrack.dz',              'brand' => '#6d28d9', 'initials' => 'TSL' ),
			array( 'id' => 'worldexpress', 'label' => 'Worldexpress',      'domain' => 'worldexpress.ecotrack.dz',     'brand' => '#0369a1', 'initials' => 'WX' ),
		);
	}

	/** @return AOD_Carrier[] */
	public function carriers() {
		return $this->carriers;
	}

	public function carrier( $id ) {
		return isset( $this->carriers[ $id ] ) ? $this->carriers[ $id ] : null;
	}

	/** @return AOD_Carrier[] Livreurs configurés. */
	public function configured() {
		return array_filter( $this->carriers, function ( $c ) {
			return $c->is_configured();
		} );
	}

	/* ============================================================
	 * Statut « Confirmée » + envoi automatique
	 * ========================================================== */

	/**
	 * Enregistre le statut de commande personnalisé « Confirmée ».
	 */
	public function register_order_status() {
		register_post_status( 'wc-' . self::STATUS, array(
			'label'                     => _x( 'Confirmée', 'Order status', 'aod-cod-form' ),
			'public'                    => false,
			'internal'                  => false,
			'exclude_from_search'       => false,
			'show_in_admin_all_list'    => true,
			'show_in_admin_status_list' => true,
			/* translators: %s: nombre de commandes */
			'label_count'               => _n_noop( 'Confirmée <span class="count">(%s)</span>', 'Confirmées <span class="count">(%s)</span>', 'aod-cod-form' ),
		) );
	}

	/**
	 * Ajoute « Confirmée » au menu déroulant des statuts (juste après « En cours »).
	 *
	 * @param array $statuses
	 * @return array
	 */
	public function add_order_status( $statuses ) {
		$out = array();
		foreach ( $statuses as $key => $label ) {
			$out[ $key ] = $label;
			if ( 'wc-processing' === $key ) {
				$out[ 'wc-' . self::STATUS ] = _x( 'Confirmée', 'Order status', 'aod-cod-form' );
			}
		}
		// Filet de sécurité si « wc-processing » n'existe pas.
		if ( ! isset( $out[ 'wc-' . self::STATUS ] ) ) {
			$out[ 'wc-' . self::STATUS ] = _x( 'Confirmée', 'Order status', 'aod-cod-form' );
		}
		return $out;
	}

	/**
	 * Réglages de l'envoi automatique (avec valeurs par défaut).
	 *
	 * @return array { 'enabled' => 0|1, 'carrier' => string }
	 */
	public function auto_settings() {
		$saved = get_option( self::AUTO_OPTION, array() );
		return wp_parse_args( (array) $saved, array(
			'enabled' => 0,
			'carrier' => 'ecotrack',
		) );
	}

	/**
	 * Envoi automatique au livreur quand la commande passe à « Confirmée ».
	 *
	 * @param int           $order_id
	 * @param WC_Order|null $order
	 */
	public function auto_send( $order_id, $order = null ) {
		$auto = $this->auto_settings();
		if ( empty( $auto['enabled'] ) ) {
			return;
		}

		if ( ! $order instanceof WC_Order ) {
			$order = wc_get_order( $order_id );
		}
		if ( ! $order instanceof WC_Order ) {
			return;
		}

		// On n'expédie que les commandes COD (type de livraison renseigné : home/desk).
		// Évite de marquer en erreur des commandes qui ne concernent pas ces livreurs.
		if ( '' === (string) $order->get_meta( '_aod_delivery_type' ) ) {
			return;
		}

		// Déjà expédié : on ne renvoie pas.
		if ( $order->get_meta( AOD_Carrier::META_TRACKING ) ) {
			return;
		}

		$carrier = $this->carrier( $auto['carrier'] );
		if ( ! $carrier || ! $carrier->is_configured() ) {
			// Repli : si un seul transporteur est configuré, on l'utilise plutôt
			// que d'abandonner (cas fréquent : défaut resté sur « EcoTrack »
			// générique alors qu'un white-label précis a été connecté).
			$configured = $this->configured();
			if ( 1 === count( $configured ) ) {
				$carrier = reset( $configured );
			} else {
				$msg = empty( $configured )
					? __( 'Envoi automatique annulé : aucun transporteur n’est configuré (Livraison → Transporteurs).', 'aod-cod-form' )
					: __( 'Envoi automatique annulé : le livreur par défaut n’est pas configuré. Choisissez-le dans Livraison → Transporteurs.', 'aod-cod-form' );
				$order->update_meta_data( AOD_Carrier::META_ERROR, $msg );
				$order->add_order_note( $msg );
				$order->save();
				return;
			}
		}

		// Stop-desk sans centre choisi : on tente le premier centre disponible.
		if ( $this->is_desk_order( $order ) && $carrier->supports_stopdesk() && '' === (string) $order->get_meta( AOD_Carrier::META_STOPDESK ) ) {
			$centers = $carrier->get_centers( (int) $order->get_meta( '_aod_wilaya_code' ) );
			if ( is_array( $centers ) && ! empty( $centers ) && isset( $centers[0]['id'] ) ) {
				$order->update_meta_data( AOD_Carrier::META_STOPDESK, (string) $centers[0]['id'] );
				$order->save();
			}
		}

		$result = $carrier->create_parcel( $order );
		if ( is_wp_error( $result ) ) {
			$order->update_meta_data( AOD_Carrier::META_ERROR, $result->get_error_message() );
			$order->add_order_note( sprintf(
				/* translators: 1: nom du livreur, 2: message d'erreur */
				__( 'Envoi automatique échoué (%1$s) : %2$s', 'aod-cod-form' ),
				$carrier->label(),
				$result->get_error_message()
			) );
			$order->save();
			return;
		}

		$this->store_result( $order, $carrier, $result );
	}

	/* ============================================================
	 * Colonne « Livraison » dans la liste des commandes
	 * ========================================================== */

	/**
	 * Ajoute la colonne « Livraison » après la colonne « Statut ».
	 *
	 * @param array $columns
	 * @return array
	 */
	public function add_order_column( $columns ) {
		$out = array();
		foreach ( $columns as $key => $label ) {
			$out[ $key ] = $label;
			if ( 'order_status' === $key ) {
				$out['aod_ship'] = __( 'Livraison', 'aod-cod-form' );
			}
		}
		if ( ! isset( $out['aod_ship'] ) ) {
			$out['aod_ship'] = __( 'Livraison', 'aod-cod-form' );
		}
		return $out;
	}

	/**
	 * Rendu de la colonne — liste HPOS (reçoit l'objet commande).
	 *
	 * @param string   $column
	 * @param WC_Order $order
	 */
	public function render_order_column_hpos( $column, $order ) {
		if ( 'aod_ship' === $column ) {
			$this->render_ship_cell( $order );
		}
	}

	/**
	 * Rendu de la colonne — ancienne liste (reçoit l'ID du post).
	 *
	 * @param string $column
	 * @param int    $post_id
	 */
	public function render_order_column_legacy( $column, $post_id ) {
		if ( 'aod_ship' === $column ) {
			$this->render_ship_cell( wc_get_order( $post_id ) );
		}
	}

	/**
	 * État de suivi d'une commande pour l'icône camion.
	 *
	 * @param WC_Order $order
	 * @return string 'sent' | 'failed' | 'pending' | 'none'
	 */
	public function ship_status( $order ) {
		if ( ! $order instanceof WC_Order ) {
			return 'none';
		}
		if ( $order->get_meta( AOD_Carrier::META_TRACKING ) ) {
			return 'sent';
		}
		if ( $order->get_meta( AOD_Carrier::META_ERROR ) ) {
			return 'failed';
		}
		// Commandes annulées / remboursées / corbeille : pas de livraison à prévoir.
		if ( in_array( $order->get_status(), array( 'cancelled', 'refunded', 'trash', 'failed' ), true ) ) {
			return 'none';
		}
		// Toute autre commande est « à envoyer » → camion gris (jamais de colonne vide).
		return 'pending';
	}

	/**
	 * Couleur + libellé associés à un état de suivi.
	 *
	 * @param string $status
	 * @return array [ 'color' => '#hex', 'label' => '...' ]
	 */
	protected function ship_status_meta( $status ) {
		$map = array(
			'sent'    => array( 'color' => '#16a34a', 'label' => __( 'Envoyée au livreur', 'aod-cod-form' ) ),
			'failed'  => array( 'color' => '#dc2626', 'label' => __( 'Échec de l’envoi', 'aod-cod-form' ) ),
			'pending' => array( 'color' => '#9ca3af', 'label' => __( 'Pas encore envoyée', 'aod-cod-form' ) ),
		);
		return isset( $map[ $status ] ) ? $map[ $status ] : array( 'color' => '#9ca3af', 'label' => '' );
	}

	/**
	 * Icône camion de livraison (SVG inline) colorée selon l'état.
	 *
	 * @param string $color  Couleur hexadécimale.
	 * @param string $title  Texte d'infobulle.
	 * @param int    $size   Taille en pixels.
	 * @return string HTML sûr (déjà échappé).
	 */
	public function truck_icon( $color, $title = '', $size = 26 ) {
		$c = esc_attr( $color );
		$s = (int) $size;
		$t = $title ? ' title="' . esc_attr( $title ) . '"' : '';
		return '<span class="aod-truck" style="display:inline-flex;line-height:0;vertical-align:middle"' . $t . '>'
			. '<svg width="' . $s . '" height="' . $s . '" viewBox="0 0 24 24" aria-hidden="true" focusable="false">'
			. '<path fill="' . $c . '" d="M1 5.5h12.2c.6 0 1 .4 1 1V14H2c-.6 0-1-.4-1-1V5.5z"/>'
			. '<path fill="' . $c . '" d="M15.2 8h3.1l2.7 3v3h-5.8V8z"/>'
			. '<circle cx="6" cy="16.5" r="2.3" fill="#fff" stroke="' . $c . '" stroke-width="1.6"/>'
			. '<circle cx="17.4" cy="16.5" r="2.3" fill="#fff" stroke="' . $c . '" stroke-width="1.6"/>'
			. '</svg></span>';
	}

	/**
	 * Badge de suivi réutilisable (icône camion + libellé optionnel) pour une commande.
	 *
	 * Exposé pour que d'autres modules (tableau de bord client, etc.) affichent
	 * exactement la même icône avec la même logique d'état.
	 *
	 * @param WC_Order $order
	 * @param int      $size       Taille de l'icône en pixels.
	 * @param bool     $with_label Ajouter le libellé textuel à côté de l'icône.
	 * @return string HTML sûr (déjà échappé), ou '' si aucun suivi pertinent.
	 */
	public function status_badge_html( $order, $size = 22, $with_label = true ) {
		$status = $this->ship_status( $order );
		if ( 'none' === $status ) {
			return '';
		}
		$m    = $this->ship_status_meta( $status );
		$html = $this->truck_icon( $m['color'], $m['label'], $size );
		if ( $with_label ) {
			$html .= '<span style="color:' . esc_attr( $m['color'] ) . ';font-weight:600;margin-inline-start:6px">' . esc_html( $m['label'] ) . '</span>';
		}
		return $html;
	}

	/**
	 * Contenu de la cellule « Livraison » : icône camion (gris/vert/rouge) + détails.
	 *
	 * @param WC_Order|false $order
	 */
	protected function render_ship_cell( $order ) {
		if ( ! $order instanceof WC_Order ) {
			echo '&mdash;';
			return;
		}

		$status = $this->ship_status( $order );
		if ( 'none' === $status ) {
			echo '&mdash;';
			return;
		}

		$meta  = $this->ship_status_meta( $status );
		$color = $meta['color'];

		if ( 'sent' === $status ) {
			$cid      = $order->get_meta( AOD_Carrier::META_CARRIER );
			$carrier  = $this->carrier( $cid );
			$name     = $carrier ? $carrier->label() : $cid;
			$tracking = $order->get_meta( AOD_Carrier::META_TRACKING );
			$pdf      = $order->get_meta( AOD_Carrier::META_LABEL );

			echo '<span style="display:inline-flex;align-items:center;gap:6px">';
			echo $this->truck_icon( $color, $name . ' — ' . $meta['label'] ); // phpcs:ignore WordPress.Security.EscapeOutput
			echo '<span style="color:' . esc_attr( $color ) . ';font-weight:600">' . esc_html( $name ) . '</span></span>';
			echo '<br><code style="font-size:11px">' . esc_html( $tracking ) . '</code>';
			if ( $pdf ) {
				echo '<br><a href="' . esc_url( $pdf ) . '" target="_blank" rel="noopener">' . esc_html__( 'Étiquette', 'aod-cod-form' ) . '</a>';
			}
			return;
		}

		$tooltip = $meta['label'];
		if ( 'failed' === $status ) {
			$err = $order->get_meta( AOD_Carrier::META_ERROR );
			if ( $err ) {
				$tooltip = $meta['label'] . ' : ' . $err;
			}
		}

		echo '<span style="display:inline-flex;align-items:center;gap:6px">';
		echo $this->truck_icon( $color, $tooltip ); // phpcs:ignore WordPress.Security.EscapeOutput
		echo '<span style="color:' . esc_attr( $color ) . ';font-weight:600">' . esc_html( $meta['label'] ) . '</span></span>';
	}

	/* ============================================================
	 * Réglages
	 * ========================================================== */

	public function menu() {
		add_submenu_page(
			'woocommerce',
			__( 'Expédition (livreurs)', 'aod-cod-form' ),
			__( 'Expédition', 'aod-cod-form' ),
			'manage_woocommerce',
			'aod-shipping',
			array( $this, 'render_settings' )
		);
	}

	public function maybe_save_settings() {
		if ( ! isset( $_POST['aod_shipping_save'] ) ) {
			return;
		}
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		check_admin_referer( 'aod_shipping_settings' );

		$in = wp_unslash( $_POST );
		foreach ( $this->carriers as $id => $carrier ) {
			$slice = isset( $in[ $id ] ) && is_array( $in[ $id ] ) ? $in[ $id ] : array();
			$carrier->save_settings( $carrier->sanitize_settings( $slice ) );
		}

		// Réglages d'envoi automatique.
		$auto = isset( $in['auto'] ) && is_array( $in['auto'] ) ? $in['auto'] : array();
		update_option( self::AUTO_OPTION, array(
			'enabled' => empty( $auto['enabled'] ) ? 0 : 1,
			'carrier' => isset( $auto['carrier'] ) ? sanitize_key( $auto['carrier'] ) : 'ecotrack',
		) );

		add_settings_error( 'aod_shipping', 'saved', __( 'Réglages d’expédition enregistrés.', 'aod-cod-form' ), 'updated' );
	}

	public function render_settings() {
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Expédition — Livreurs', 'aod-cod-form' ); ?></h1>
			<?php settings_errors( 'aod_shipping' ); ?>
			<p><?php esc_html_e( 'Configurez un ou plusieurs transporteurs. Vous choisirez le livreur au moment d’envoyer chaque commande.', 'aod-cod-form' ); ?></p>
			<form method="post">
				<?php wp_nonce_field( 'aod_shipping_settings' ); ?>

				<?php $auto = $this->auto_settings(); ?>
				<h2><?php esc_html_e( 'Envoi automatique', 'aod-cod-form' ); ?></h2>
				<table class="form-table" role="presentation" style="max-width:760px">
					<tr>
						<th scope="row"><?php esc_html_e( 'Déclencheur', 'aod-cod-form' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="auto[enabled]" value="1" <?php checked( ! empty( $auto['enabled'] ) ); ?>>
								<?php esc_html_e( 'Envoyer automatiquement au livreur quand la commande passe au statut « Confirmée »', 'aod-cod-form' ); ?>
							</label>
							<p class="description"><?php esc_html_e( 'Recommandé : appelez d’abord le client pour confirmer, puis passez la commande à « Confirmée ». Le bordereau part alors tout seul. Le bouton « Envoyer » manuel reste disponible.', 'aod-cod-form' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="aod_auto_carrier"><?php esc_html_e( 'Livreur par défaut', 'aod-cod-form' ); ?></label></th>
						<td>
							<select id="aod_auto_carrier" name="auto[carrier]">
								<?php foreach ( $this->carriers as $id => $c ) : ?>
									<option value="<?php echo esc_attr( $id ); ?>" <?php selected( $auto['carrier'], $id ); ?>>
										<?php echo esc_html( $c->label() ); ?><?php echo $c->is_configured() ? '' : ' ' . esc_html__( '(non configuré)', 'aod-cod-form' ); ?>
									</option>
								<?php endforeach; ?>
							</select>
							<p class="description"><?php esc_html_e( 'Le transporteur utilisé pour l’envoi automatique. Doit être configuré ci-dessous.', 'aod-cod-form' ); ?></p>
						</td>
					</tr>
				</table>

				<?php foreach ( $this->carriers as $carrier ) : ?>
					<h2 style="margin-top:2em">
						<?php echo esc_html( $carrier->label() ); ?>
						<?php if ( $carrier->is_configured() ) : ?>
							<span class="dashicons dashicons-yes" style="color:#1a7f37" title="<?php esc_attr_e( 'Configuré', 'aod-cod-form' ); ?>"></span>
						<?php endif; ?>
					</h2>
					<?php $carrier->render_settings_fields(); ?>
				<?php endforeach; ?>
				<p><button type="submit" name="aod_shipping_save" class="button button-primary"><?php esc_html_e( 'Enregistrer', 'aod-cod-form' ); ?></button></p>
			</form>
		</div>
		<?php
	}

	/* ============================================================
	 * Résultat sur la commande
	 * ========================================================== */

	protected function store_result( $order, $carrier, $result ) {
		$order->update_meta_data( AOD_Carrier::META_CARRIER, $carrier->id() );
		$order->update_meta_data( AOD_Carrier::META_TRACKING, $result['tracking'] );
		$order->update_meta_data( AOD_Carrier::META_LABEL, $result['label'] );
		$order->update_meta_data( AOD_Carrier::META_IMPORT_ID, $result['import_id'] );
		$order->delete_meta_data( AOD_Carrier::META_ERROR );
		$order->add_order_note( sprintf( __( 'Colis %1$s créé. Suivi : %2$s', 'aod-cod-form' ), $carrier->label(), $result['tracking'] ) );
		$order->save();
	}

	/* ============================================================
	 * Encart commande
	 * ========================================================== */

	public function add_metabox() {
		add_meta_box(
			'aod_ship_box',
			__( 'Expédition (livreur)', 'aod-cod-form' ),
			array( $this, 'render_metabox' ),
			array( 'shop_order', 'woocommerce_page_wc-orders' ),
			'side',
			'high'
		);

		// Récapitulatif complet des informations client / livraison.
		add_meta_box(
			'aod_order_info',
			__( 'Détails de la commande (COD)', 'aod-cod-form' ),
			array( $this, 'render_info_metabox' ),
			array( 'shop_order', 'woocommerce_page_wc-orders' ),
			'normal',
			'high'
		);
	}

	/**
	 * Petite ligne « libellé : valeur » du récapitulatif.
	 *
	 * @param string $label
	 * @param string $value HTML déjà échappé.
	 * @return string
	 */
	protected function info_row( $label, $value ) {
		if ( '' === (string) $value ) {
			return '';
		}
		return '<tr><th style="text-align:left;padding:6px 12px 6px 0;vertical-align:top;color:#555;font-weight:600;white-space:nowrap">'
			. esc_html( $label ) . '</th><td style="padding:6px 0;vertical-align:top">' . $value . '</td></tr>';
	}

	/**
	 * Metabox « Détails de la commande » : toutes les infos saisies par le client,
	 * la livraison, le suivi (camion) et les articles avec leurs variantes.
	 *
	 * @param WP_Post|WC_Order $post_or_order
	 */
	public function render_info_metabox( $post_or_order ) {
		$order = ( $post_or_order instanceof WP_Post ) ? wc_get_order( $post_or_order->ID ) : $post_or_order;
		if ( ! $order instanceof WC_Order ) {
			return;
		}

		$name       = trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() );
		$phone      = $order->get_billing_phone();
		$address    = $order->get_billing_address_1();
		$commune    = $order->get_meta( '_aod_commune' );
		$commune_ar = $order->get_meta( '_aod_commune_ar' );
		$w_code     = $order->get_meta( '_aod_wilaya_code' );
		$w_name     = $order->get_meta( '_aod_wilaya_name' );
		$w_ar       = $order->get_meta( '_aod_wilaya_name_ar' );
		$delivery   = $order->get_meta( '_aod_delivery_type' );
		$is_aod     = $order->get_meta( '_aod_source' ) || $delivery;

		echo '<div class="aod-oinfo" style="font-size:13px">';

		/* ---- Client ---- */
		$rows = '';
		if ( '' !== $name ) {
			$rows .= $this->info_row( __( 'Nom du client', 'aod-cod-form' ), esc_html( $name ) );
		}
		if ( '' !== $phone ) {
			$tel   = preg_replace( '/[^0-9+]/', '', $phone );
			$value = '<a href="tel:' . esc_attr( $tel ) . '" style="font-weight:600;font-size:15px;text-decoration:none">' . esc_html( $phone ) . '</a>'
				. ' <button type="button" class="button button-small aod-oinfo-copy" data-copy="' . esc_attr( $phone ) . '" style="margin-inline-start:6px">' . esc_html__( 'Copier', 'aod-cod-form' ) . '</button>';
			$rows .= $this->info_row( __( 'Téléphone', 'aod-cod-form' ), $value );
		}
		if ( $rows ) {
			echo '<h4 style="margin:0 0 4px">' . esc_html__( 'Client', 'aod-cod-form' ) . '</h4>';
			echo '<table style="width:100%;border-collapse:collapse;margin-bottom:14px">' . $rows . '</table>'; // phpcs:ignore WordPress.Security.EscapeOutput
		}

		/* ---- Livraison ---- */
		$rows = '';
		if ( '' !== (string) $delivery ) {
			$dlabel = ( 'desk' === $delivery ) ? __( 'Stop-desk (point relais)', 'aod-cod-form' ) : __( 'Domicile', 'aod-cod-form' );
			$dcolor = ( 'desk' === $delivery ) ? '#7c3aed' : '#2563eb';
			$badge  = '<span style="display:inline-block;padding:2px 10px;border-radius:999px;background:' . esc_attr( $dcolor ) . ';color:#fff;font-weight:600;font-size:12px">' . esc_html( $dlabel ) . '</span>';
			$rows  .= $this->info_row( __( 'Type de livraison', 'aod-cod-form' ), $badge );
		}
		if ( '' !== (string) $w_name || '' !== (string) $w_code ) {
			$wv = esc_html( trim( sprintf( '%s %s', $w_code ? sprintf( '%02d -', (int) $w_code ) : '', $w_name ) ) );
			if ( '' !== (string) $w_ar ) {
				$wv .= ' <span dir="rtl" style="color:#666">(' . esc_html( $w_ar ) . ')</span>';
			}
			$rows .= $this->info_row( __( 'Wilaya', 'aod-cod-form' ), $wv );
		}
		if ( '' !== (string) $commune ) {
			$cv = esc_html( $commune );
			if ( '' !== (string) $commune_ar ) {
				$cv .= ' <span dir="rtl" style="color:#666">(' . esc_html( $commune_ar ) . ')</span>';
			}
			$rows .= $this->info_row( __( 'Commune', 'aod-cod-form' ), $cv );
		}
		if ( '' !== (string) $address ) {
			$rows .= $this->info_row( __( 'Adresse', 'aod-cod-form' ), esc_html( $address ) );
		}
		$stopdesk = $order->get_meta( AOD_Carrier::META_STOPDESK );
		if ( '' !== (string) $stopdesk ) {
			$rows .= $this->info_row( __( 'Centre / station', 'aod-cod-form' ), esc_html( $stopdesk ) );
		}
		if ( $rows ) {
			echo '<h4 style="margin:0 0 4px">' . esc_html__( 'Livraison', 'aod-cod-form' ) . '</h4>';
			echo '<table style="width:100%;border-collapse:collapse;margin-bottom:14px">' . $rows . '</table>'; // phpcs:ignore WordPress.Security.EscapeOutput
		}

		/* ---- Suivi (camion) ---- */
		$status = $this->ship_status( $order );
		if ( 'none' !== $status ) {
			$sm  = $this->ship_status_meta( $status );
			echo '<h4 style="margin:0 0 4px">' . esc_html__( 'Suivi', 'aod-cod-form' ) . '</h4>';
			echo '<p style="display:flex;align-items:center;gap:8px;margin:0 0 14px">';
			echo $this->truck_icon( $sm['color'], $sm['label'], 30 ); // phpcs:ignore WordPress.Security.EscapeOutput
			echo '<span style="color:' . esc_attr( $sm['color'] ) . ';font-weight:600">' . esc_html( $sm['label'] ) . '</span>';
			if ( 'sent' === $status ) {
				$cid     = $order->get_meta( AOD_Carrier::META_CARRIER );
				$carrier = $this->carrier( $cid );
				$cname   = $carrier ? $carrier->label() : $cid;
				$track   = $order->get_meta( AOD_Carrier::META_TRACKING );
				echo '<span style="color:#555"> — ' . esc_html( $cname ) . ' · <code>' . esc_html( $track ) . '</code></span>';
				$pdf = $order->get_meta( AOD_Carrier::META_LABEL );
				if ( $pdf ) {
					echo ' <a href="' . esc_url( $pdf ) . '" target="_blank" rel="noopener" class="button button-small">' . esc_html__( 'Étiquette', 'aod-cod-form' ) . '</a>';
				}
			} elseif ( 'failed' === $status ) {
				$err = $order->get_meta( AOD_Carrier::META_ERROR );
				if ( $err ) {
					echo '<span style="color:#b32d2e"> — ' . esc_html( $err ) . '</span>';
				}
			}
			echo '</p>';
		}

		/* ---- Articles ---- */
		$items = $order->get_items();
		if ( ! empty( $items ) ) {
			echo '<h4 style="margin:0 0 4px">' . esc_html__( 'Articles commandés', 'aod-cod-form' ) . '</h4>';
			echo '<table style="width:100%;border-collapse:collapse" cellspacing="0">';
			echo '<thead><tr style="text-align:left;border-bottom:1px solid #e2e4e7">'
				. '<th style="padding:6px 8px 6px 0">' . esc_html__( 'Produit', 'aod-cod-form' ) . '</th>'
				. '<th style="padding:6px 8px;text-align:center">' . esc_html__( 'Qté', 'aod-cod-form' ) . '</th>'
				. '<th style="padding:6px 0;text-align:right">' . esc_html__( 'Total', 'aod-cod-form' ) . '</th></tr></thead><tbody>';
			foreach ( $items as $item ) {
				$variants = array();
				foreach ( $item->get_formatted_meta_data() as $m ) {
					$variants[] = wp_strip_all_tags( $m->display_key ) . ' : ' . wp_strip_all_tags( $m->display_value );
				}
				echo '<tr style="border-bottom:1px solid #f0f0f1">';
				echo '<td style="padding:6px 8px 6px 0;vertical-align:top">' . esc_html( $item->get_name() );
				if ( $variants ) {
					echo '<br><span style="color:#666;font-size:12px">' . esc_html( implode( ' · ', $variants ) ) . '</span>';
				}
				echo '</td>';
				echo '<td style="padding:6px 8px;text-align:center;vertical-align:top">' . esc_html( $item->get_quantity() ) . '</td>';
				echo '<td style="padding:6px 0;text-align:right;vertical-align:top">' . wp_kses_post( wc_price( $item->get_total() ) ) . '</td>';
				echo '</tr>';
			}
			echo '</tbody></table>';

			echo '<table style="width:100%;border-collapse:collapse;margin-top:8px">';
			echo $this->info_row( __( 'Sous-total produits', 'aod-cod-form' ), wp_kses_post( wc_price( $order->get_subtotal() ) ) ); // phpcs:ignore WordPress.Security.EscapeOutput
			$ship_total = (float) $order->get_shipping_total();
			$ship_value = $ship_total > 0 ? wp_kses_post( wc_price( $ship_total ) ) : '<span style="color:#16a34a;font-weight:600">' . esc_html__( 'Offerte', 'aod-cod-form' ) . '</span>';
			echo $this->info_row( __( 'Livraison', 'aod-cod-form' ), $ship_value ); // phpcs:ignore WordPress.Security.EscapeOutput
			echo $this->info_row( __( 'Total à encaisser', 'aod-cod-form' ), '<strong style="font-size:15px">' . wp_kses_post( $order->get_formatted_order_total() ) . '</strong>' ); // phpcs:ignore WordPress.Security.EscapeOutput
			echo '</table>';
		}

		if ( ! $is_aod && '' === $name && empty( $items ) ) {
			echo '<p style="color:#777">' . esc_html__( 'Aucune information COD pour cette commande.', 'aod-cod-form' ) . '</p>';
		}

		echo '</div>';
		?>
		<script>
		( function () {
			var box = document.getElementById( 'aod_order_info' );
			if ( ! box ) { return; }
			box.addEventListener( 'click', function ( e ) {
				var b = e.target.closest( '.aod-oinfo-copy' );
				if ( ! b ) { return; }
				e.preventDefault();
				var val = b.getAttribute( 'data-copy' ) || '';
				var done = function () {
					var t = b.textContent;
					b.textContent = '<?php echo esc_js( __( 'Copié ✓', 'aod-cod-form' ) ); ?>';
					setTimeout( function () { b.textContent = t; }, 1200 );
				};
				if ( navigator.clipboard && navigator.clipboard.writeText ) {
					navigator.clipboard.writeText( val ).then( done ).catch( done );
				} else {
					var ta = document.createElement( 'textarea' );
					ta.value = val; document.body.appendChild( ta ); ta.select();
					try { document.execCommand( 'copy' ); } catch ( err ) {}
					document.body.removeChild( ta ); done();
				}
			} );
		} )();
		</script>
		<?php
	}

	/** Champ de sélection du centre/station (select si dispo, sinon saisie manuelle). */
	protected function build_desk_field( $carrier, $order ) {
		if ( ! $carrier || ! $carrier->supports_stopdesk() || ! $this->is_desk_order( $order ) ) {
			return '';
		}
		$wilaya  = (int) $order->get_meta( '_aod_wilaya_code' );
		$centers = $carrier->get_centers( $wilaya );
		$current = (string) $order->get_meta( AOD_Carrier::META_STOPDESK );

		$out = '<p><label><strong>' . esc_html__( 'Centre / station stop-desk :', 'aod-cod-form' ) . '</strong><br>';
		if ( is_array( $centers ) && ! empty( $centers ) ) {
			$out .= '<select class="aod-ship-desk" style="width:100%">';
			foreach ( $centers as $c ) {
				$cid  = isset( $c['id'] ) ? (string) $c['id'] : '';
				$name = isset( $c['name'] ) ? $c['name'] : $cid;
				$out .= '<option value="' . esc_attr( $cid ) . '" ' . selected( $current, $cid, false ) . '>' . esc_html( $name ) . '</option>';
			}
			$out .= '</select>';
		} else {
			$note = is_wp_error( $centers ) ? $centers->get_error_message() : __( 'Saisissez le code du centre/station (liste indisponible).', 'aod-cod-form' );
			$out .= '<input type="text" class="aod-ship-desk" style="width:100%" value="' . esc_attr( $current ) . '" placeholder="' . esc_attr__( 'Code centre / station', 'aod-cod-form' ) . '">';
			$out .= '<span class="description">' . esc_html( $note ) . '</span>';
		}
		$out .= '</label></p>';
		return $out;
	}

	protected function is_desk_order( $order ) {
		return 'desk' === $order->get_meta( '_aod_delivery_type' );
	}

	/**
	 * @param WP_Post|WC_Order $post_or_order
	 */
	public function render_metabox( $post_or_order ) {
		$order = ( $post_or_order instanceof WP_Post ) ? wc_get_order( $post_or_order->ID ) : $post_or_order;
		if ( ! $order instanceof WC_Order ) {
			return;
		}

		// Déjà expédié ?
		$tracking = $order->get_meta( AOD_Carrier::META_TRACKING );
		if ( $tracking ) {
			$cid     = $order->get_meta( AOD_Carrier::META_CARRIER );
			$carrier = $this->carrier( $cid );
			$label   = $order->get_meta( AOD_Carrier::META_LABEL );
			echo '<p><strong>' . esc_html__( 'Livreur :', 'aod-cod-form' ) . '</strong> ' . esc_html( $carrier ? $carrier->label() : $cid ) . '</p>';
			echo '<p><strong>' . esc_html__( 'Suivi :', 'aod-cod-form' ) . '</strong><br><code>' . esc_html( $tracking ) . '</code></p>';
			if ( $label ) {
				echo '<p><a href="' . esc_url( $label ) . '" target="_blank" class="button">' . esc_html__( 'Étiquette (PDF)', 'aod-cod-form' ) . '</a></p>';
			}
			return;
		}

		$configured = $this->configured();
		if ( empty( $configured ) ) {
			echo '<p>' . esc_html__( 'Aucun livreur configuré.', 'aod-cod-form' ) . ' <a href="' . esc_url( admin_url( 'admin.php?page=aod-shipping' ) ) . '">' . esc_html__( 'Configurer', 'aod-cod-form' ) . '</a></p>';
			return;
		}

		$nonce   = wp_create_nonce( 'aod_ship' );
		$first   = reset( $configured );
		$is_desk = $this->is_desk_order( $order );
		$error   = $order->get_meta( AOD_Carrier::META_ERROR );

		// Carte des livreurs gérant le stop-desk (pour le JS).
		$desk_support = array();
		foreach ( $configured as $id => $c ) {
			$desk_support[ $id ] = $c->supports_stopdesk();
		}

		echo '<div class="aod-ship" data-order="' . esc_attr( $order->get_id() ) . '" data-nonce="' . esc_attr( $nonce ) . '" data-desk="' . esc_attr( $is_desk ? 1 : 0 ) . '" data-support="' . esc_attr( wp_json_encode( $desk_support ) ) . '">';

		echo '<p><label><strong>' . esc_html__( 'Livreur :', 'aod-cod-form' ) . '</strong><br><select class="aod-ship-carrier" style="width:100%">';
		foreach ( $configured as $id => $c ) {
			echo '<option value="' . esc_attr( $id ) . '">' . esc_html( $c->label() ) . '</option>';
		}
		echo '</select></label></p>';

		echo '<div class="aod-ship-deskwrap">' . $this->build_desk_field( $first, $order ) . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput

		if ( $error ) {
			echo '<p style="color:#b32d2e"><strong>' . esc_html__( 'Erreur :', 'aod-cod-form' ) . '</strong> ' . esc_html( $error ) . '</p>';
		}

		echo '<p><button type="button" class="button button-primary aod-ship-send">' . esc_html__( 'Envoyer', 'aod-cod-form' ) . '</button></p>';
		echo '<p class="aod-ship-msg" style="margin:0"></p>';
		echo '</div>';
		?>
		<script>
		( function () {
			var box = document.currentScript.previousElementSibling;
			while ( box && ! ( box.classList && box.classList.contains( 'aod-ship' ) ) ) { box = box.previousElementSibling; }
			if ( ! box ) { return; }
			var carrierSel = box.querySelector( '.aod-ship-carrier' );
			var deskWrap   = box.querySelector( '.aod-ship-deskwrap' );
			var btn        = box.querySelector( '.aod-ship-send' );
			var msg        = box.querySelector( '.aod-ship-msg' );
			var isDesk     = box.dataset.desk === '1';
			var support    = {};
			try { support = JSON.parse( box.dataset.support ); } catch ( e ) {}

			function loadDesk() {
				if ( ! isDesk || ! support[ carrierSel.value ] ) { deskWrap.innerHTML = ''; return; }
				deskWrap.innerHTML = '<p class="description"><?php echo esc_js( __( 'Chargement des centres…', 'aod-cod-form' ) ); ?></p>';
				var body = new URLSearchParams();
				body.append( 'action', 'aod_ship_centers' );
				body.append( 'nonce', box.dataset.nonce );
				body.append( 'order_id', box.dataset.order );
				body.append( 'carrier', carrierSel.value );
				fetch( ajaxurl, { method: 'POST', credentials: 'same-origin', body: body } )
					.then( function ( r ) { return r.json(); } )
					.then( function ( res ) { deskWrap.innerHTML = ( res && res.success ) ? res.data.html : ''; } )
					.catch( function () { deskWrap.innerHTML = ''; } );
			}
			carrierSel.addEventListener( 'change', loadDesk );

			btn.addEventListener( 'click', function () {
				btn.disabled = true;
				msg.style.color = '';
				msg.textContent = '<?php echo esc_js( __( 'Envoi en cours…', 'aod-cod-form' ) ); ?>';
				var deskField = box.querySelector( '.aod-ship-desk' );
				var body = new URLSearchParams();
				body.append( 'action', 'aod_ship_send' );
				body.append( 'nonce', box.dataset.nonce );
				body.append( 'order_id', box.dataset.order );
				body.append( 'carrier', carrierSel.value );
				if ( deskField ) { body.append( 'stopdesk', deskField.value ); }
				fetch( ajaxurl, { method: 'POST', credentials: 'same-origin', body: body } )
					.then( function ( r ) { return r.json(); } )
					.then( function ( res ) {
						if ( res && res.success ) {
							msg.style.color = '#1a7f37';
							msg.textContent = res.data.message || 'OK';
							setTimeout( function () { location.reload(); }, 800 );
						} else {
							msg.style.color = '#b32d2e';
							msg.textContent = ( res && res.data && res.data.message ) || 'Erreur.';
							btn.disabled = false;
						}
					} )
					.catch( function () {
						msg.style.color = '#b32d2e';
						msg.textContent = '<?php echo esc_js( __( 'Erreur réseau.', 'aod-cod-form' ) ); ?>';
						btn.disabled = false;
					} );
			} );
		} )();
		</script>
		<?php
	}

	/* ============================================================
	 * AJAX
	 * ========================================================== */

	protected function check_ajax() {
		check_ajax_referer( 'aod_ship', 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'Accès refusé.', 'aod-cod-form' ) ), 403 );
		}
	}

	public function ajax_centers() {
		$this->check_ajax();
		$order_id = isset( $_POST['order_id'] ) ? absint( $_POST['order_id'] ) : 0;
		$carrier  = isset( $_POST['carrier'] ) ? $this->carrier( sanitize_key( wp_unslash( $_POST['carrier'] ) ) ) : null;
		$order    = $order_id ? wc_get_order( $order_id ) : false;
		if ( ! $carrier || ! $order instanceof WC_Order ) {
			wp_send_json_error( array( 'message' => __( 'Paramètres invalides.', 'aod-cod-form' ) ) );
		}
		wp_send_json_success( array( 'html' => $this->build_desk_field( $carrier, $order ) ) );
	}

	public function ajax_send() {
		$this->check_ajax();
		$order_id = isset( $_POST['order_id'] ) ? absint( $_POST['order_id'] ) : 0;
		$carrier  = isset( $_POST['carrier'] ) ? $this->carrier( sanitize_key( wp_unslash( $_POST['carrier'] ) ) ) : null;
		$order    = $order_id ? wc_get_order( $order_id ) : false;
		if ( ! $carrier || ! $order instanceof WC_Order ) {
			wp_send_json_error( array( 'message' => __( 'Commande ou livreur introuvable.', 'aod-cod-form' ) ), 404 );
		}

		if ( isset( $_POST['stopdesk'] ) ) {
			$order->update_meta_data( AOD_Carrier::META_STOPDESK, sanitize_text_field( wp_unslash( $_POST['stopdesk'] ) ) );
			$order->save();
		}

		$result = $carrier->create_parcel( $order );
		if ( is_wp_error( $result ) ) {
			$order->update_meta_data( AOD_Carrier::META_ERROR, $result->get_error_message() );
			$order->save();
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		$this->store_result( $order, $carrier, $result );
		wp_send_json_success( array(
			'message'  => sprintf( __( 'Colis créé ✓ Suivi : %s', 'aod-cod-form' ), $result['tracking'] ),
			'tracking' => $result['tracking'],
		) );
	}

	/* ============================================================
	 * Actions groupées (une par livreur configuré)
	 * ========================================================== */

	public function register_bulk( $actions ) {
		foreach ( $this->configured() as $id => $carrier ) {
			$actions[ 'aod_ship_' . $id ] = sprintf( __( 'Envoyer à %s', 'aod-cod-form' ), $carrier->label() );
		}
		return $actions;
	}

	public function handle_bulk( $redirect, $action, $ids ) {
		if ( 0 !== strpos( (string) $action, 'aod_ship_' ) ) {
			return $redirect;
		}
		$carrier = $this->carrier( substr( $action, strlen( 'aod_ship_' ) ) );
		if ( ! $carrier ) {
			return $redirect;
		}
		$ok = 0;
		$ko = 0;
		foreach ( (array) $ids as $id ) {
			$order = wc_get_order( $id );
			if ( ! $order instanceof WC_Order || $order->get_meta( AOD_Carrier::META_TRACKING ) ) {
				continue;
			}
			$result = $carrier->create_parcel( $order );
			if ( is_wp_error( $result ) ) {
				$order->update_meta_data( AOD_Carrier::META_ERROR, $result->get_error_message() );
				$order->save();
				$ko++;
			} else {
				$this->store_result( $order, $carrier, $result );
				$ok++;
			}
		}
		return add_query_arg( array( 'aod_ship_ok' => $ok, 'aod_ship_ko' => $ko ), $redirect );
	}

	public function bulk_notice() {
		if ( ! isset( $_GET['aod_ship_ok'] ) && ! isset( $_GET['aod_ship_ko'] ) ) {
			return;
		}
		$ok    = isset( $_GET['aod_ship_ok'] ) ? absint( $_GET['aod_ship_ok'] ) : 0;
		$ko    = isset( $_GET['aod_ship_ko'] ) ? absint( $_GET['aod_ship_ko'] ) : 0;
		$class = $ko ? 'notice-warning' : 'notice-success';
		echo '<div class="notice ' . esc_attr( $class ) . ' is-dismissible"><p>'
			. esc_html( sprintf( __( 'Expédition : %1$d colis créé(s), %2$d échec(s).', 'aod-cod-form' ), $ok, $ko ) )
			. '</p></div>';
	}
}
