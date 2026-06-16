<?php
/**
 * Livreur Yalidine.
 *
 * API : https://api.yalidine.app/v1/ — en-têtes X-API-ID / X-API-TOKEN, JSON.
 * Création : POST /parcels/ (tableau) → réponse indexée par order_id
 * (success, tracking, import_id, label PDF). Wilaya/commune par NOM.
 *
 * @package AOD_COD_Form
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AOD_Carrier_Yalidine extends AOD_Carrier {

	const API_BASE = 'https://api.yalidine.app/v1/';

	public function id() {
		return 'yalidine';
	}

	public function label() {
		return 'Yalidine';
	}

	public function brand_color() {
		return '#e63329';
	}

	public function initials() {
		return 'YA';
	}

	public function supports_stopdesk() {
		return true;
	}

	/** Base API (surchargeable pour les clones type Yalitec). */
	protected function api_base() {
		return self::API_BASE;
	}

	protected function defaults() {
		return array(
			'api_id'           => '',
			'api_token'        => '',
			'from_wilaya_id'   => '',
			'from_wilaya_name' => '',
			'weight'           => 1,
			'length'           => 20,
			'width'            => 20,
			'height'           => 20,
			'freeshipping'     => 1,
			'do_insurance'     => 0,
		);
	}

	public function is_configured() {
		$s = $this->settings();
		return '' !== $s['api_id'] && '' !== $s['api_token'];
	}

	public function render_settings_fields() {
		$s = $this->settings();
		$p = $this->id();
		?>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><?php esc_html_e( 'API ID', 'aod-cod-form' ); ?></th>
				<td><input type="text" class="regular-text" name="<?php echo esc_attr( $p ); ?>[api_id]" value="<?php echo esc_attr( $s['api_id'] ); ?>" autocomplete="off"></td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'API Token', 'aod-cod-form' ); ?></th>
				<td><input type="text" class="regular-text" name="<?php echo esc_attr( $p ); ?>[api_token]" value="<?php echo esc_attr( $s['api_token'] ); ?>" autocomplete="off"></td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Wilaya d’expédition', 'aod-cod-form' ); ?></th>
				<td>
					<select name="<?php echo esc_attr( $p ); ?>[from_wilaya_id]">
						<option value=""><?php esc_html_e( '— Choisir —', 'aod-cod-form' ); ?></option>
						<?php foreach ( AOD_COD_Data::places() as $w ) : ?>
							<option value="<?php echo esc_attr( $w['code'] ); ?>" <?php selected( (int) $s['from_wilaya_id'], (int) $w['code'] ); ?>><?php echo esc_html( sprintf( '%02d - %s', $w['code'], $w['name'] ) ); ?></option>
						<?php endforeach; ?>
					</select>
					<p class="description"><?php esc_html_e( 'Obligatoire pour Yalidine.', 'aod-cod-form' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Poids / dimensions', 'aod-cod-form' ); ?></th>
				<td>
					<label><?php esc_html_e( 'Poids (kg)', 'aod-cod-form' ); ?> <input type="number" min="1" step="1" name="<?php echo esc_attr( $p ); ?>[weight]" value="<?php echo esc_attr( $s['weight'] ); ?>" style="width:80px"></label>
					<label>L <input type="number" min="0" name="<?php echo esc_attr( $p ); ?>[length]" value="<?php echo esc_attr( $s['length'] ); ?>" style="width:70px"></label>
					<label>l <input type="number" min="0" name="<?php echo esc_attr( $p ); ?>[width]" value="<?php echo esc_attr( $s['width'] ); ?>" style="width:70px"></label>
					<label>H <input type="number" min="0" name="<?php echo esc_attr( $p ); ?>[height]" value="<?php echo esc_attr( $s['height'] ); ?>" style="width:70px"></label>
					<span class="description">(cm)</span>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Options', 'aod-cod-form' ); ?></th>
				<td>
					<label><input type="checkbox" name="<?php echo esc_attr( $p ); ?>[freeshipping]" value="1" <?php checked( 1, (int) $s['freeshipping'] ); ?>> <?php esc_html_e( 'Livraison « gratuite » côté Yalidine (le client paie pile le total)', 'aod-cod-form' ); ?></label><br>
					<label><input type="checkbox" name="<?php echo esc_attr( $p ); ?>[do_insurance]" value="1" <?php checked( 1, (int) $s['do_insurance'] ); ?>> <?php esc_html_e( 'Assurer le colis', 'aod-cod-form' ); ?></label>
				</td>
			</tr>
		</table>
		<?php
	}

	public function sanitize_settings( $input ) {
		$wid = isset( $input['from_wilaya_id'] ) ? absint( $input['from_wilaya_id'] ) : 0;
		return array(
			'api_id'           => isset( $input['api_id'] ) ? sanitize_text_field( $input['api_id'] ) : '',
			'api_token'        => isset( $input['api_token'] ) ? sanitize_text_field( $input['api_token'] ) : '',
			'from_wilaya_id'   => $wid ? $wid : '',
			'from_wilaya_name' => $wid ? AOD_COD_Data::wilaya_name( $wid ) : '',
			'weight'           => isset( $input['weight'] ) ? max( 1, absint( $input['weight'] ) ) : 1,
			'length'           => isset( $input['length'] ) ? max( 0, absint( $input['length'] ) ) : 0,
			'width'            => isset( $input['width'] ) ? max( 0, absint( $input['width'] ) ) : 0,
			'height'           => isset( $input['height'] ) ? max( 0, absint( $input['height'] ) ) : 0,
			'freeshipping'     => empty( $input['freeshipping'] ) ? 0 : 1,
			'do_insurance'     => empty( $input['do_insurance'] ) ? 0 : 1,
		);
	}

	/** Requête authentifiée Yalidine (JSON). */
	protected function request( $method, $endpoint, $body = null ) {
		if ( ! $this->is_configured() ) {
			return new WP_Error( 'aod_yalidine_no_creds', __( 'Clés API Yalidine manquantes.', 'aod-cod-form' ) );
		}
		$s    = $this->settings();
		$args = array(
			'headers' => array(
				'X-API-ID'     => $s['api_id'],
				'X-API-TOKEN'  => $s['api_token'],
				'Content-Type' => 'application/json',
				'Accept'       => 'application/json',
			),
		);
		if ( null !== $body ) {
			$args['body'] = wp_json_encode( $body );
		}
		return $this->remote( $method, $this->api_base() . $endpoint, $args );
	}

	public function get_centers( $wilaya_code ) {
		$wilaya_code = absint( $wilaya_code );
		$key         = 'aod_yalidine_centers_' . $wilaya_code;
		$cached      = get_transient( $key );
		if ( is_array( $cached ) ) {
			return $cached;
		}
		$res = $this->request( 'GET', 'centers/?wilaya_id=' . $wilaya_code );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		$list = array();
		$rows = isset( $res['data'] ) && is_array( $res['data'] ) ? $res['data'] : array();
		foreach ( $rows as $c ) {
			$list[] = array(
				'id'   => isset( $c['center_id'] ) ? (int) $c['center_id'] : 0,
				'name' => isset( $c['name'] ) ? $c['name'] : ( isset( $c['commune_name'] ) ? $c['commune_name'] : '' ),
			);
		}
		set_transient( $key, $list, DAY_IN_SECONDS );
		return $list;
	}

	public function build_payload( $order ) {
		$s = $this->settings();
		list( $firstname, $familyname ) = $this->split_name( $this->full_name( $order ) );
		$is_desk = $this->is_stopdesk_order( $order );
		$total   = $this->order_total( $order );

		$payload = array(
			'order_id'         => (string) $order->get_order_number(),
			'from_wilaya_name' => $s['from_wilaya_name'],
			'firstname'        => $firstname,
			'familyname'       => $familyname,
			'contact_phone'    => $order->get_billing_phone(),
			'address'          => $order->get_billing_address_1(),
			'to_commune_name'  => $order->get_meta( '_aod_commune' ) ? $order->get_meta( '_aod_commune' ) : $order->get_billing_city(),
			'to_wilaya_name'   => $order->get_meta( '_aod_wilaya_name' ),
			'product_list'     => $this->product_list( $order ),
			'price'            => $total,
			'do_insurance'     => (bool) $s['do_insurance'],
			'declared_value'   => $total,
			'length'           => (int) $s['length'],
			'width'            => (int) $s['width'],
			'height'           => (int) $s['height'],
			'weight'           => (int) $s['weight'],
			'freeshipping'     => (bool) $s['freeshipping'],
			'is_stopdesk'      => $is_desk,
			'has_exchange'     => false,
		);
		if ( $is_desk ) {
			$payload['stopdesk_id'] = (int) $order->get_meta( self::META_STOPDESK );
		}
		return $payload;
	}

	public function create_parcel( $order ) {
		$s = $this->settings();
		if ( '' === $s['from_wilaya_name'] ) {
			return new WP_Error( 'aod_yalidine_no_from', __( 'Wilaya d’expédition non définie (Réglages Yalidine).', 'aod-cod-form' ) );
		}
		if ( $this->is_stopdesk_order( $order ) && ! (int) $order->get_meta( self::META_STOPDESK ) ) {
			return new WP_Error( 'aod_yalidine_no_desk', __( 'Stop-desk : choisissez un centre avant l’envoi.', 'aod-cod-form' ) );
		}

		$payload = $this->build_payload( $order );
		$res     = $this->request( 'POST', 'parcels/', array( $payload ) );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		$entry = isset( $res[ $payload['order_id'] ] ) ? $res[ $payload['order_id'] ] : reset( $res );
		if ( ! is_array( $entry ) || empty( $entry['success'] ) ) {
			$msg = is_array( $entry ) && ! empty( $entry['message'] ) ? $entry['message'] : __( 'Yalidine a refusé le colis.', 'aod-cod-form' );
			return new WP_Error( 'aod_yalidine_rejected', $msg, $entry );
		}
		return array(
			'tracking'  => isset( $entry['tracking'] ) ? $entry['tracking'] : '',
			'label'     => isset( $entry['label'] ) ? $entry['label'] : '',
			'import_id' => isset( $entry['import_id'] ) ? $entry['import_id'] : '',
		);
	}

	/**
	 * Vérifie les clés API via un appel léger en lecture seule.
	 *
	 * GET /wilayas/ exige une authentification valide (X-API-ID / X-API-TOKEN) :
	 * une réponse 2xx confirme que les clés sont acceptées. Hérité tel quel par
	 * Yalitec (seule la base API change).
	 *
	 * @return array|WP_Error
	 */
	public function test_connection() {
		if ( ! $this->is_configured() ) {
			return parent::test_connection();
		}
		$res = $this->request( 'GET', 'wilayas/?page=1' );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		return array( 'live' => true, 'message' => '' );
	}
}
