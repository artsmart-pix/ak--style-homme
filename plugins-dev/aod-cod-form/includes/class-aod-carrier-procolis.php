<?php
/**
 * Livreur E-com Delivery (ecom-dz.com) — API v2.
 *
 * API : https://ecom-dz.com/api_v2/ — auth par en-têtes X-API-Key (« KEY », clé
 * fixe) + X-API-Token (« Token », régénérable). Référentiels en lecture :
 * /wilayas, /communes, /stopdesks, /tarifs. Création : POST /colis (tableau de
 * 1..100 colis) → HTTP 201 ; vérifier resultats[i].ok / .tracking / .erreur.
 *
 * Domicile : champ `commune` (validé par nom + wilaya, casse/accents ignorés).
 * Stop-desk : `stopdesk=1` + `code_stopdesk` du bureau choisi (cf. /stopdesks).
 * Étiquette (bordereau) : GET /colis/{tracking}/bordereau (endpoint authentifié,
 * pas de lien direct public).
 *
 * Ce compte partage le backend de l'ancienne marque « ZR Express / Procolis » :
 * mêmes identifiants. L'id interne reste « zrexpress » (compat des réglages
 * déjà enregistrés + envoi auto).
 *
 * @package AOD_COD_Form
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AOD_Carrier_Procolis extends AOD_Carrier {

	const API_BASE = 'https://ecom-dz.com/api_v2/';

	/** @var array Config d'instance : id, label, brand, initials. */
	protected $config;

	public function __construct( $config = array() ) {
		$this->config = wp_parse_args( $config, array(
			'id'       => 'zrexpress',
			'label'    => 'E-com Delivery',
			'brand'    => '#ef4444',
			'initials' => 'EC',
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

	/** E-com Delivery gère le stop-desk via le code_stopdesk d'un bureau de la wilaya. */
	public function supports_stopdesk() {
		return true;
	}

	protected function defaults() {
		return array(
			'token' => '',
			'key'   => '',
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
				<th scope="row"><?php esc_html_e( 'KEY', 'aod-cod-form' ); ?></th>
				<td><input type="text" class="regular-text" name="<?php echo esc_attr( $p ); ?>[key]" value="<?php echo esc_attr( $s['key'] ); ?>" autocomplete="off"></td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Token', 'aod-cod-form' ); ?></th>
				<td><input type="text" class="regular-text" name="<?php echo esc_attr( $p ); ?>[token]" value="<?php echo esc_attr( $s['token'] ); ?>" autocomplete="off">
				<p class="description"><?php esc_html_e( 'ecom-dz.com → Identifiants API (KEY = clé fixe, Token = régénérable).', 'aod-cod-form' ); ?></p></td>
			</tr>
		</table>
		<?php
	}

	public function sanitize_settings( $input ) {
		return array(
			'token' => isset( $input['token'] ) ? sanitize_text_field( $input['token'] ) : '',
			'key'   => isset( $input['key'] ) ? sanitize_text_field( $input['key'] ) : '',
		);
	}

	/** En-têtes d'authentification E-com Delivery (API v2). */
	protected function headers() {
		$s = $this->settings();
		return array(
			'X-API-Key'    => $s['key'],
			'X-API-Token'  => $s['token'],
			'Content-Type' => 'application/json',
			'Accept'       => 'application/json',
		);
	}

	/**
	 * Vérifie KEY + Token via GET /test (renvoie l'identité du compte fournisseur).
	 *
	 * @return array|WP_Error
	 */
	public function test_connection() {
		if ( ! $this->is_configured() ) {
			return parent::test_connection();
		}
		$res = $this->remote( 'GET', self::API_BASE . 'test', array( 'headers' => $this->headers() ) );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		if ( ! isset( $res['id_fournisseur'] ) ) {
			return new WP_Error( 'aod_ecomdz_denied', __( 'Identifiants refusés (KEY / Token).', 'aod-cod-form' ) );
		}
		$nom = isset( $res['nom_fournisseur'] ) ? (string) $res['nom_fournisseur'] : '';
		return array( 'live' => true, 'message' => $nom );
	}

	/**
	 * Bureaux stop-desk bruts d'une wilaya (cache 24 h).
	 *
	 * @param int $wilaya_code 1-58.
	 * @return array Liste [ [ 'code' =>, 'commune' =>, 'name' => ], … ].
	 */
	protected function desks( $wilaya_code ) {
		$wilaya_code = absint( $wilaya_code );
		if ( $wilaya_code < 1 || ! $this->is_configured() ) {
			return array();
		}
		// v2 dans la clé : le format du cache a évolué (id/name → code/commune/name) ;
		// éviter de lire un ancien transient au format incompatible après mise à jour.
		$key    = 'aod_ecomdz_desks2_' . $wilaya_code;
		$cached = get_transient( $key );
		if ( is_array( $cached ) ) {
			return $cached;
		}
		$res = $this->remote( 'GET', self::API_BASE . 'stopdesks?id_wilaya=' . $wilaya_code, array( 'headers' => $this->headers() ) );
		if ( is_wp_error( $res ) || ! is_array( $res ) ) {
			return array();
		}
		$list = array();
		foreach ( $res as $row ) {
			if ( ! is_array( $row ) || empty( $row['code_stopdesk'] ) ) {
				continue;
			}
			$commune = isset( $row['commune'] ) ? trim( (string) $row['commune'] ) : '';
			$name    = isset( $row['nom_bureau'] ) ? (string) $row['nom_bureau'] : (string) $row['code_stopdesk'];
			if ( '' !== $commune && false === stripos( $name, $commune ) ) {
				$name .= ' — ' . $commune;
			}
			$list[] = array( 'code' => (string) $row['code_stopdesk'], 'commune' => $commune, 'name' => $name );
		}
		if ( ! empty( $list ) ) {
			set_transient( $key, $list, DAY_IN_SECONDS );
		}
		return $list;
	}

	/**
	 * Bureaux stop-desk d'une wilaya, format générique [ ['id'=>, 'name'=>], … ]
	 * (utilisé par le dashboard gérant pour un éventuel choix manuel).
	 *
	 * @param int $wilaya_code 1-58.
	 * @return array
	 */
	public function get_centers( $wilaya_code ) {
		$list = array();
		foreach ( $this->desks( $wilaya_code ) as $d ) {
			$list[] = array( 'id' => $d['code'], 'name' => $d['name'] );
		}
		return $list;
	}

	/**
	 * Déduit le bureau de retrait d'une commande « bureau » à partir de sa commune
	 * (l'acheteur ne choisit plus de bureau dans le formulaire). On cherche le bureau
	 * dont la commune correspond à celle de la commande ; repli : premier bureau de
	 * la wilaya.
	 *
	 * @param WC_Order $order
	 * @return string Code du bureau, ou '' si la wilaya n'a aucun bureau.
	 */
	public function resolve_stopdesk_code( $order ) {
		$wilaya  = (int) $order->get_meta( '_aod_wilaya_code' );
		$desks   = $this->desks( $wilaya );
		if ( empty( $desks ) ) {
			return '';
		}
		$commune = $order->get_meta( '_aod_commune' ) ? $order->get_meta( '_aod_commune' ) : $order->get_billing_city();
		$target  = $this->norm( $commune );
		if ( '' !== $target ) {
			foreach ( $desks as $d ) {
				if ( '' !== $d['commune'] && $this->norm( $d['commune'] ) === $target ) {
					return $d['code'];
				}
			}
		}
		return $desks[0]['code']; // Aucun bureau dans la commune : premier bureau de la wilaya.
	}

	/** Normalise un nom de commune pour la comparaison (sans accents/espaces/casse). */
	protected function norm( $str ) {
		$str = remove_accents( (string) $str );
		$str = strtolower( $str );
		return preg_replace( '/[^a-z0-9]/', '', $str );
	}

	/**
	 * Résout le nom de commune saisi vers l'orthographe officielle E-com (domicile).
	 *
	 * L'API valide la commune par nom + wilaya ; on aligne donc le nom envoyé sur
	 * le référentiel /communes (correspondance normalisée puis Levenshtein) pour
	 * éviter l'erreur « commune introuvable ». Cache 12 h par wilaya.
	 *
	 * @param int    $wilaya_code
	 * @param string $commune
	 * @return string Nom de commune à envoyer.
	 */
	protected function resolve_commune( $wilaya_code, $commune ) {
		$wilaya_code = absint( $wilaya_code );
		$commune     = trim( (string) $commune );
		$key         = 'aod_ecomdz_communes_' . $wilaya_code;
		$list        = get_transient( $key );

		if ( ! is_array( $list ) ) {
			$res  = $this->remote( 'GET', self::API_BASE . 'communes?id_wilaya=' . $wilaya_code, array( 'headers' => $this->headers() ) );
			$list = array();
			if ( ! is_wp_error( $res ) && is_array( $res ) ) {
				foreach ( $res as $row ) {
					if ( is_array( $row ) && ! empty( $row['commune'] ) ) {
						$list[] = (string) $row['commune'];
					}
				}
			}
			if ( ! empty( $list ) ) {
				set_transient( $key, $list, 12 * HOUR_IN_SECONDS );
			}
		}
		if ( empty( $list ) ) {
			return $commune; // Pas de référentiel : on envoie tel quel (l'API tranchera).
		}

		$target = $this->norm( $commune );
		foreach ( $list as $name ) {
			if ( $this->norm( $name ) === $target ) {
				return $name;
			}
		}
		// Repli : commune la plus proche (petites fautes de frappe).
		$best = null;
		$bd   = PHP_INT_MAX;
		foreach ( $list as $name ) {
			$d = levenshtein( $target, $this->norm( $name ) );
			if ( $d < $bd ) {
				$bd   = $d;
				$best = $name;
			}
		}
		return ( null !== $best && $bd <= 2 ) ? $best : $commune;
	}

	/** Tronque une chaîne à la longueur max imposée par l'API. */
	protected function clip( $str, $max ) {
		$str = trim( (string) $str );
		return function_exists( 'mb_substr' ) ? mb_substr( $str, 0, $max ) : substr( $str, 0, $max );
	}

	/** Quantité totale (somme des quantités d'articles). */
	protected function total_qty( $order ) {
		$q = 0;
		foreach ( $order->get_items() as $item ) {
			$q += (int) $item->get_quantity();
		}
		return max( 1, $q );
	}

	public function build_payload( $order ) {
		$is_desk     = $this->is_stopdesk_order( $order );
		$wilaya_code = (int) $order->get_meta( '_aod_wilaya_code' );
		$commune_raw = $order->get_meta( '_aod_commune' ) ? $order->get_meta( '_aod_commune' ) : $order->get_billing_city();

		$colis = array(
			'nom_complet' => $this->clip( $this->full_name( $order ), 20 ),
			'mobile_1'    => $this->clip( $order->get_billing_phone(), 25 ),
			'id_wilaya'   => $wilaya_code,
			'article'     => $this->clip( $this->product_list( $order ), 255 ),
			'quantite'    => $this->total_qty( $order ),
			'total'       => $this->order_total( $order ),
			'adresse'     => $this->clip( $order->get_billing_address_1() ? $order->get_billing_address_1() : $commune_raw, 30 ),
			'id_externe'  => $this->clip( $order->get_order_number(), 20 ),
			'stopdesk'    => $is_desk ? 1 : 0,
		);
		if ( $is_desk ) {
			// Bureau déjà fixé (choix manuel dashboard) ou déduit de la commune.
			$code = (string) $order->get_meta( self::META_STOPDESK );
			if ( '' === $code ) {
				$code = $this->resolve_stopdesk_code( $order );
			}
			$colis['code_stopdesk'] = $code;
		} else {
			$colis['commune'] = $this->resolve_commune( $wilaya_code, $commune_raw );
		}
		return $colis;
	}

	public function create_parcel( $order ) {
		if ( ! $this->is_configured() ) {
			return new WP_Error( 'aod_ecomdz_no_creds', __( 'Identifiants E-com Delivery manquants (KEY / Token).', 'aod-cod-form' ) );
		}
		if ( ! (int) $order->get_meta( '_aod_wilaya_code' ) ) {
			return new WP_Error( 'aod_ecomdz_no_wilaya', __( 'Wilaya de la commande manquante.', 'aod-cod-form' ) );
		}

		$payload = $this->build_payload( $order );
		// Stop-desk : le bureau est déduit de la wilaya/commune ; on n'échoue que si
		// la wilaya n'expose réellement aucun bureau.
		if ( $this->is_stopdesk_order( $order ) && empty( $payload['code_stopdesk'] ) ) {
			return new WP_Error( 'aod_ecomdz_no_desk', __( 'Aucun bureau stop-desk disponible pour cette wilaya. Choisissez la livraison à domicile.', 'aod-cod-form' ) );
		}

		$res = $this->remote( 'POST', self::API_BASE . 'colis', array(
			'headers' => $this->headers(),
			'body'    => wp_json_encode( array( $payload ) ),
		) );
		if ( is_wp_error( $res ) ) {
			return $res;
		}

		// HTTP 201 même si la ligne échoue : se fier à resultats[0].ok / .erreur.
		$row = isset( $res['resultats'][0] ) && is_array( $res['resultats'][0] ) ? $res['resultats'][0] : array();
		if ( empty( $row['ok'] ) || empty( $row['tracking'] ) ) {
			$msg = ! empty( $row['erreur'] ) ? $row['erreur'] : __( 'E-com Delivery a refusé le colis.', 'aod-cod-form' );
			return new WP_Error( 'aod_ecomdz_rejected', $msg, $row );
		}

		return array(
			'tracking'  => (string) $row['tracking'],
			'label'     => '', // Bordereau via endpoint authentifié (pas de lien direct).
			'import_id' => isset( $row['id_colis'] ) ? (string) $row['id_colis'] : '',
		);
	}
}
