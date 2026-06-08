<?php
/**
 * Livreur Noest Express.
 *
 * API : https://app.noest-dz.com/api/public/ — auth par api_token + user_guid
 * (paramètres de formulaire). Création : POST create/order, puis valid/order
 * pour confirmer. Wilaya par CODE (1-58), commune par NOM.
 *
 * @package AOD_COD_Form
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AOD_Carrier_Noest extends AOD_Carrier {

	const API_BASE = 'https://app.noest-dz.com/api/public/';

	public function id() {
		return 'noest';
	}

	public function label() {
		return 'Noest Express';
	}

	public function brand_color() {
		return '#0ea5e9';
	}

	public function initials() {
		return 'NE';
	}

	public function supports_stopdesk() {
		return true;
	}

	protected function defaults() {
		return array(
			'api_token'     => '',
			'user_guid'     => '',
			'weight'        => 1,
			'can_open'      => 0,
			'auto_validate' => 1,
		);
	}

	public function is_configured() {
		$s = $this->settings();
		return '' !== $s['api_token'] && '' !== $s['user_guid'];
	}

	public function render_settings_fields() {
		$s = $this->settings();
		$p = $this->id();
		?>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><?php esc_html_e( 'API Token', 'aod-cod-form' ); ?></th>
				<td><input type="text" class="regular-text" name="<?php echo esc_attr( $p ); ?>[api_token]" value="<?php echo esc_attr( $s['api_token'] ); ?>" autocomplete="off"></td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'GUID utilisateur', 'aod-cod-form' ); ?></th>
				<td><input type="text" class="regular-text" name="<?php echo esc_attr( $p ); ?>[user_guid]" value="<?php echo esc_attr( $s['user_guid'] ); ?>" autocomplete="off">
				<p class="description"><?php esc_html_e( 'API Token + GUID : espace Noest → Paramètres → API.', 'aod-cod-form' ); ?></p></td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Poids par défaut (kg)', 'aod-cod-form' ); ?></th>
				<td><input type="number" min="1" step="1" name="<?php echo esc_attr( $p ); ?>[weight]" value="<?php echo esc_attr( $s['weight'] ); ?>" style="width:80px"></td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Options', 'aod-cod-form' ); ?></th>
				<td>
					<label><input type="checkbox" name="<?php echo esc_attr( $p ); ?>[can_open]" value="1" <?php checked( 1, (int) $s['can_open'] ); ?>> <?php esc_html_e( 'Autoriser l’ouverture du colis à la livraison', 'aod-cod-form' ); ?></label><br>
					<label><input type="checkbox" name="<?php echo esc_attr( $p ); ?>[auto_validate]" value="1" <?php checked( 1, (int) $s['auto_validate'] ); ?>> <?php esc_html_e( 'Valider automatiquement le colis après création (prêt à expédier)', 'aod-cod-form' ); ?></label>
				</td>
			</tr>
		</table>
		<?php
	}

	public function sanitize_settings( $input ) {
		return array(
			'api_token'     => isset( $input['api_token'] ) ? sanitize_text_field( $input['api_token'] ) : '',
			'user_guid'     => isset( $input['user_guid'] ) ? sanitize_text_field( $input['user_guid'] ) : '',
			'weight'        => isset( $input['weight'] ) ? max( 1, absint( $input['weight'] ) ) : 1,
			'can_open'      => empty( $input['can_open'] ) ? 0 : 1,
			'auto_validate' => empty( $input['auto_validate'] ) ? 0 : 1,
		);
	}

	public function build_payload( $order ) {
		$s       = $this->settings();
		$is_desk = $this->is_stopdesk_order( $order );

		$payload = array(
			'api_token'   => $s['api_token'],
			'user_guid'   => $s['user_guid'],
			'reference'   => (string) $order->get_order_number(),
			'client'      => $this->full_name( $order ),
			'phone'       => $order->get_billing_phone(),
			'phone_2'     => '',
			'adresse'     => $order->get_billing_address_1() ? $order->get_billing_address_1() : $order->get_meta( '_aod_commune' ),
			'wilaya_id'   => (int) $order->get_meta( '_aod_wilaya_code' ),
			'commune'     => $order->get_meta( '_aod_commune' ) ? $order->get_meta( '_aod_commune' ) : $order->get_billing_city(),
			'montant'     => $this->order_total( $order ),
			'remarque'    => '',
			'produit'     => $this->product_list( $order ),
			'type_id'     => 1,
			'poids'       => (int) $s['weight'],
			'stop_desk'   => $is_desk ? 1 : 0,
			'stock'       => 0,
			'quantite'    => '1',
			'can_open'    => (int) $s['can_open'],
		);
		if ( $is_desk ) {
			$payload['station_code'] = (string) $order->get_meta( self::META_STOPDESK );
		}
		return $payload;
	}

	public function create_parcel( $order ) {
		if ( ! $this->is_configured() ) {
			return new WP_Error( 'aod_noest_no_creds', __( 'Clés API Noest manquantes.', 'aod-cod-form' ) );
		}
		if ( ! (int) $order->get_meta( '_aod_wilaya_code' ) ) {
			return new WP_Error( 'aod_noest_no_wilaya', __( 'Wilaya de la commande manquante.', 'aod-cod-form' ) );
		}
		if ( $this->is_stopdesk_order( $order ) && '' === (string) $order->get_meta( self::META_STOPDESK ) ) {
			return new WP_Error( 'aod_noest_no_desk', __( 'Stop-desk : code de station requis avant l’envoi.', 'aod-cod-form' ) );
		}

		$payload = $this->build_payload( $order );
		$res     = $this->remote( 'POST', self::API_BASE . 'create/order', array( 'body' => $payload ) );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		if ( empty( $res['success'] ) || empty( $res['tracking'] ) ) {
			$msg = ! empty( $res['message'] ) ? $res['message'] : __( 'Noest a refusé le colis.', 'aod-cod-form' );
			return new WP_Error( 'aod_noest_rejected', $msg, $res );
		}
		$tracking = $res['tracking'];

		// Validation (rend le colis prêt à être ramassé).
		$s = $this->settings();
		if ( ! empty( $s['auto_validate'] ) ) {
			$this->remote( 'POST', self::API_BASE . 'valid/order', array(
				'body' => array(
					'api_token' => $s['api_token'],
					'user_guid' => $s['user_guid'],
					'tracking'  => $tracking,
				),
			) );
		}

		$label = add_query_arg(
			array(
				'api_token' => $s['api_token'],
				'user_guid' => $s['user_guid'],
				'tracking'  => $tracking,
			),
			self::API_BASE . 'get/order/label'
		);

		return array(
			'tracking'  => $tracking,
			'label'     => $label,
			'import_id' => '',
		);
	}
}
