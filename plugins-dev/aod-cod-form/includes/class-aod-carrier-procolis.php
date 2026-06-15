<?php
/**
 * Livreur basé sur l'API Procolis (ZR Express).
 *
 * API : https://procolis.com/api_v1 — auth par en-têtes `token` + `key`, JSON.
 * Test : GET /token (Statut = « Accès activé »). Création : POST /add_colis avec
 * { "Colis": [ {...} ] } → réponse Colis[0] (MessageRetour = « Good », Tracking).
 * Wilaya par CODE (1-58), commune par NOM. Pas d'étiquette PDF via l'API.
 *
 * Comme EcoTrack, l'API Procolis est partagée ; on l'instancie avec une config
 * (id/label/couleur) — ZR Express par défaut.
 *
 * @package AOD_COD_Form
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AOD_Carrier_Procolis extends AOD_Carrier {

	const API_BASE = 'https://procolis.com/api_v1/';

	/** @var array Config d'instance : id, label, brand, initials. */
	protected $config;

	public function __construct( $config = array() ) {
		$this->config = wp_parse_args( $config, array(
			'id'       => 'zrexpress',
			'label'    => 'ZR Express',
			'brand'    => '#ef4444',
			'initials' => 'ZR',
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

	/**
	 * ZR Express / Procolis : intégration en livraison à DOMICILE uniquement.
	 *
	 * Le stop-desk de ZR Express exige une agence valide par commune (« Code
	 * Stopdesk incorrect » sinon) et l'API ne fournit pas de référentiel d'agences
	 * exploitable ici. On déclare donc le stop-desk non géré : le formulaire public
	 * masque l'option « bureau » et build_payload() force la livraison à domicile.
	 */
	public function supports_stopdesk() {
		return false;
	}

	protected function defaults() {
		return array(
			'token'         => '',
			'key'           => '',
			'confirmed'     => 1,
		);
	}

	public function is_configured() {
		$s = $this->settings();
		return '' !== $s['token'] && '' !== $s['key'];
	}

	public function render_settings_fields() {
		$s = $this->settings();
		$p = $this->id();
		?>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><?php esc_html_e( 'Token', 'aod-cod-form' ); ?></th>
				<td><input type="text" class="regular-text" name="<?php echo esc_attr( $p ); ?>[token]" value="<?php echo esc_attr( $s['token'] ); ?>" autocomplete="off"></td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Clé (key)', 'aod-cod-form' ); ?></th>
				<td><input type="text" class="regular-text" name="<?php echo esc_attr( $p ); ?>[key]" value="<?php echo esc_attr( $s['key'] ); ?>" autocomplete="off">
				<p class="description"><?php esc_html_e( 'Token + Clé : espace ZR Express → Paramètres → Info personnelles (API).', 'aod-cod-form' ); ?></p></td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Options', 'aod-cod-form' ); ?></th>
				<td>
					<label><input type="checkbox" name="<?php echo esc_attr( $p ); ?>[confirmed]" value="1" <?php checked( 1, (int) $s['confirmed'] ); ?>> <?php esc_html_e( 'Créer le colis directement « prêt à expédier » (confirmé)', 'aod-cod-form' ); ?></label>
				</td>
			</tr>
		</table>
		<?php
	}

	public function sanitize_settings( $input ) {
		return array(
			'token'     => isset( $input['token'] ) ? sanitize_text_field( $input['token'] ) : '',
			'key'       => isset( $input['key'] ) ? sanitize_text_field( $input['key'] ) : '',
			'confirmed' => empty( $input['confirmed'] ) ? 0 : 1,
		);
	}

	/** En-têtes d'authentification Procolis. */
	protected function headers() {
		$s = $this->settings();
		return array(
			'token'        => $s['token'],
			'key'          => $s['key'],
			'Content-Type' => 'application/json',
			'Accept'       => 'application/json',
		);
	}

	public function build_payload( $order ) {
		$s = $this->settings();

		return array(
			'Tracking'      => '',
			// Toujours « domicile » (0) : stop-desk ZR Express non géré (cf. supports_stopdesk()).
			'TypeLivraison' => '0',
			'TypeColis'     => '0',
			'Confrimee'     => $s['confirmed'] ? '1' : '0',
			'Client'        => $this->full_name( $order ),
			'MobileA'       => $order->get_billing_phone(),
			'MobileB'       => '',
			'Adresse'       => $order->get_billing_address_1() ? $order->get_billing_address_1() : $order->get_meta( '_aod_commune' ),
			'IDWilaya'      => (string) (int) $order->get_meta( '_aod_wilaya_code' ),
			'Commune'       => $order->get_meta( '_aod_commune' ) ? $order->get_meta( '_aod_commune' ) : $order->get_billing_city(),
			'Total'         => (string) $this->order_total( $order ),
			'Note'          => '',
			'TProduit'      => $this->product_list( $order ),
			'id_Externe'    => (string) $order->get_order_number(),
			'Source'        => '',
		);
	}

	public function create_parcel( $order ) {
		if ( ! $this->is_configured() ) {
			return new WP_Error( 'aod_procolis_no_creds', __( 'Token ou clé ZR Express manquant.', 'aod-cod-form' ) );
		}
		if ( ! (int) $order->get_meta( '_aod_wilaya_code' ) ) {
			return new WP_Error( 'aod_procolis_no_wilaya', __( 'Wilaya de la commande manquante.', 'aod-cod-form' ) );
		}

		$payload = $this->build_payload( $order );
		$res     = $this->remote( 'POST', self::API_BASE . 'add_colis', array(
			'headers' => $this->headers(),
			'body'    => wp_json_encode( array( 'Colis' => array( $payload ) ) ),
		) );
		if ( is_wp_error( $res ) ) {
			return $res;
		}

		$entry = isset( $res['Colis'][0] ) && is_array( $res['Colis'][0] ) ? $res['Colis'][0] : array();
		$msg   = isset( $entry['MessageRetour'] ) ? $entry['MessageRetour'] : '';
		if ( 'Good' !== $msg ) {
			$detail = '' !== $msg ? $msg : __( 'ZR Express a refusé le colis.', 'aod-cod-form' );
			return new WP_Error( 'aod_procolis_rejected', $detail, $entry );
		}

		return array(
			'tracking'  => isset( $entry['Tracking'] ) ? $entry['Tracking'] : '',
			'label'     => '',
			'import_id' => '',
		);
	}

	/**
	 * Vérifie le couple token + clé via l'endpoint de test Procolis.
	 *
	 * GET /token renvoie { "Statut": "Accès activé" } si les identifiants sont
	 * valides ; tout autre statut (ex. « Accès refusé ») signale un rejet.
	 *
	 * @return array|WP_Error
	 */
	public function test_connection() {
		if ( ! $this->is_configured() ) {
			return parent::test_connection();
		}
		$res = $this->remote( 'GET', self::API_BASE . 'token', array( 'headers' => $this->headers() ) );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		$statut = isset( $res['Statut'] ) ? trim( (string) $res['Statut'] ) : '';
		if ( false === stripos( $statut, 'activ' ) ) {
			return new WP_Error( 'aod_procolis_denied', '' !== $statut ? $statut : __( 'Accès refusé : vérifiez le token et la clé.', 'aod-cod-form' ) );
		}
		return array( 'live' => true, 'message' => $statut );
	}
}
