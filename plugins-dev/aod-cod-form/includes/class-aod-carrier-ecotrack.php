<?php
/**
 * Livreur Ecotrack (plateforme multi-transporteurs).
 *
 * API : https://{domaine}/api/v1/ — auth par api_token (corps + en-tête Bearer).
 * Le domaine dépend du transporteur (ex. monorg.ecotrack.dz). Création :
 * POST create/order, puis valid/order. Wilaya par CODE (1-58), commune par NOM.
 *
 * Cette classe est aussi utilisée pour tous les livreurs « white-label » EcoTrack
 * (Rex, Golivri, DHD, Speed, Rocket…). Ils partagent exactement la même API ;
 * seule l'URL (domaine) et la marque changent. On les instancie avec une config
 * (id/label/domaine fixe/couleur) au lieu de créer une classe par livreur.
 *
 * @package AOD_COD_Form
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AOD_Carrier_Ecotrack extends AOD_Carrier {

	/** @var array Config d'instance : id, label, domain (fixe), brand, initials. */
	protected $config;

	/**
	 * @param array $config {
	 *     @type string $id       Identifiant machine (défaut 'ecotrack').
	 *     @type string $label    Nom affiché (défaut 'EcoTrack').
	 *     @type string $domain   Domaine fixe ; si défini, le champ domaine est masqué.
	 *     @type string $brand    Couleur de marque (#RRGGBB).
	 *     @type string $initials Initiales de la pastille.
	 * }
	 */
	public function __construct( $config = array() ) {
		$this->config = wp_parse_args( $config, array(
			'id'       => 'ecotrack',
			'label'    => 'EcoTrack',
			'domain'   => '',
			'brand'    => '#16a34a',
			'initials' => '',
		) );
	}

	public function id() {
		return $this->config['id'];
	}

	public function label() {
		return $this->config['label'];
	}

	public function brand_color() {
		return $this->config['brand'];
	}

	public function initials() {
		return '' !== $this->config['initials'] ? $this->config['initials'] : parent::initials();
	}

	public function supports_stopdesk() {
		return true;
	}

	/** Domaine fixe imposé par le white-label (vide pour l'EcoTrack générique). */
	protected function fixed_domain() {
		return $this->config['domain'];
	}

	protected function defaults() {
		return array(
			'domain'        => $this->fixed_domain(),
			'api_token'     => '',
			'weight'        => 1,
			'fragile'       => 0,
			'auto_validate' => 1,
		);
	}

	/** Domaine effectif : le domaine fixe s'il existe, sinon celui saisi. */
	protected function effective_domain() {
		$fixed = $this->fixed_domain();
		if ( '' !== $fixed ) {
			return $fixed;
		}
		return (string) $this->settings()['domain'];
	}

	public function is_configured() {
		return '' !== $this->settings()['api_token'] && '' !== $this->effective_domain();
	}

	/** Base API construite à partir du domaine effectif. */
	protected function api_base() {
		$domain = preg_replace( '#^https?://#', '', trim( $this->effective_domain() ) );
		$domain = rtrim( $domain, '/' );
		return 'https://' . $domain . '/api/v1/';
	}

	public function render_settings_fields() {
		$s     = $this->settings();
		$p     = $this->id();
		$fixed = $this->fixed_domain();
		?>
		<table class="form-table" role="presentation">
			<?php if ( '' === $fixed ) : ?>
				<tr>
					<th scope="row"><?php esc_html_e( 'Domaine EcoTrack', 'aod-cod-form' ); ?></th>
					<td><input type="text" class="regular-text" name="<?php echo esc_attr( $p ); ?>[domain]" value="<?php echo esc_attr( $s['domain'] ); ?>" placeholder="monorg.ecotrack.dz" autocomplete="off">
					<p class="description"><?php esc_html_e( 'Le sous-domaine fourni par votre transporteur EcoTrack (sans https://).', 'aod-cod-form' ); ?></p></td>
				</tr>
			<?php else : ?>
				<tr>
					<th scope="row"><?php esc_html_e( 'Plateforme', 'aod-cod-form' ); ?></th>
					<td><code><?php echo esc_html( $fixed ); ?></code>
					<p class="description"><?php esc_html_e( 'Connecté via EcoTrack. Il vous suffit de coller votre token API.', 'aod-cod-form' ); ?></p></td>
				</tr>
			<?php endif; ?>
			<tr>
				<th scope="row"><?php esc_html_e( 'API Token', 'aod-cod-form' ); ?></th>
				<td><input type="text" class="regular-text" name="<?php echo esc_attr( $p ); ?>[api_token]" value="<?php echo esc_attr( $s['api_token'] ); ?>" autocomplete="off"></td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Poids par défaut (kg)', 'aod-cod-form' ); ?></th>
				<td><input type="number" min="1" step="1" name="<?php echo esc_attr( $p ); ?>[weight]" value="<?php echo esc_attr( $s['weight'] ); ?>" style="width:80px"></td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Options', 'aod-cod-form' ); ?></th>
				<td>
					<label><input type="checkbox" name="<?php echo esc_attr( $p ); ?>[fragile]" value="1" <?php checked( 1, (int) $s['fragile'] ); ?>> <?php esc_html_e( 'Colis fragile par défaut', 'aod-cod-form' ); ?></label><br>
					<label><input type="checkbox" name="<?php echo esc_attr( $p ); ?>[auto_validate]" value="1" <?php checked( 1, (int) $s['auto_validate'] ); ?>> <?php esc_html_e( 'Valider automatiquement le colis après création', 'aod-cod-form' ); ?></label>
				</td>
			</tr>
		</table>
		<?php
	}

	public function sanitize_settings( $input ) {
		$fixed = $this->fixed_domain();
		if ( '' !== $fixed ) {
			$domain = $fixed;
		} else {
			$domain = isset( $input['domain'] ) ? sanitize_text_field( $input['domain'] ) : '';
			$domain = rtrim( preg_replace( '#^https?://#', '', trim( $domain ) ), '/' );
		}
		return array(
			'domain'        => $domain,
			'api_token'     => isset( $input['api_token'] ) ? sanitize_text_field( $input['api_token'] ) : '',
			'weight'        => isset( $input['weight'] ) ? max( 1, absint( $input['weight'] ) ) : 1,
			'fragile'       => empty( $input['fragile'] ) ? 0 : 1,
			'auto_validate' => empty( $input['auto_validate'] ) ? 0 : 1,
		);
	}

	public function build_payload( $order ) {
		$s       = $this->settings();
		$is_desk = $this->is_stopdesk_order( $order );

		$payload = array(
			'api_token'    => $s['api_token'],
			'reference'    => (string) $order->get_order_number(),
			'nom_client'   => $this->full_name( $order ),
			'telephone'    => $order->get_billing_phone(),
			'telephone_2'  => '',
			'adresse'      => $order->get_billing_address_1() ? $order->get_billing_address_1() : $order->get_meta( '_aod_commune' ),
			'code_wilaya'  => (int) $order->get_meta( '_aod_wilaya_code' ),
			'commune'      => $order->get_meta( '_aod_commune' ) ? $order->get_meta( '_aod_commune' ) : $order->get_billing_city(),
			'montant'      => $this->order_total( $order ),
			'remarque'     => '',
			'produit'      => $this->product_list( $order ),
			'type'         => 1, // 1 = livraison. Les instances EcoTrack récentes (API v1.1.0) exigent « type ».
			'type_id'      => 1, // Conservé pour compatibilité avec les instances plus anciennes.
			'poids'        => (int) $s['weight'],
			'stop_desk'    => $is_desk ? 1 : 0,
			'stock'        => 0,
			'quantite'     => '1',
			'fragile'      => (int) $s['fragile'],
		);
		if ( $is_desk ) {
			$payload['station_code'] = (string) $order->get_meta( self::META_STOPDESK );
		}
		return $payload;
	}

	public function create_parcel( $order ) {
		if ( ! $this->is_configured() ) {
			return new WP_Error( 'aod_ecotrack_no_creds', __( 'Domaine ou token EcoTrack manquant.', 'aod-cod-form' ) );
		}
		if ( ! (int) $order->get_meta( '_aod_wilaya_code' ) ) {
			return new WP_Error( 'aod_ecotrack_no_wilaya', __( 'Wilaya de la commande manquante.', 'aod-cod-form' ) );
		}
		if ( $this->is_stopdesk_order( $order ) && '' === (string) $order->get_meta( self::META_STOPDESK ) ) {
			return new WP_Error( 'aod_ecotrack_no_desk', __( 'Stop-desk : code de station requis avant l’envoi.', 'aod-cod-form' ) );
		}

		$s       = $this->settings();
		$headers = array( 'Authorization' => 'Bearer ' . $s['api_token'], 'Accept' => 'application/json' );

		$payload = $this->build_payload( $order );
		$res     = $this->remote( 'POST', $this->api_base() . 'create/order', array( 'body' => $payload, 'headers' => $headers ) );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		if ( empty( $res['success'] ) || empty( $res['tracking'] ) ) {
			$msg = ! empty( $res['message'] ) ? $res['message'] : __( 'EcoTrack a refusé le colis.', 'aod-cod-form' );
			return new WP_Error( 'aod_ecotrack_rejected', $msg, $res );
		}
		$tracking = $res['tracking'];

		if ( ! empty( $s['auto_validate'] ) ) {
			$this->remote( 'POST', $this->api_base() . 'valid/order', array(
				'body'    => array( 'api_token' => $s['api_token'], 'tracking' => $tracking ),
				'headers' => $headers,
			) );
		}

		$label = add_query_arg(
			array( 'api_token' => $s['api_token'], 'tracking' => $tracking ),
			$this->api_base() . 'get/order/label'
		);

		return array(
			'tracking'  => $tracking,
			'label'     => $label,
			'import_id' => '',
		);
	}
}
