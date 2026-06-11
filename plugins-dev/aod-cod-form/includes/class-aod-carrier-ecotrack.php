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

	/**
	 * Liste des communes EcoTrack d'une wilaya (cache 12 h).
	 *
	 * EcoTrack impose l'orthographe EXACTE de la commune (ex. « Tichy », pas
	 * « Tichi ») et indique par commune si un point relais (stop-desk) existe.
	 * On met le résultat en cache par domaine + wilaya pour éviter un appel
	 * réseau à chaque envoi.
	 *
	 * @param int $wilaya_code 1-58.
	 * @return array Liste [ [ 'nom' => string, 'has_stop_desk' => bool ], … ].
	 */
	protected function communes( $wilaya_code ) {
		$wilaya_code = (int) $wilaya_code;
		if ( $wilaya_code < 1 || ! $this->is_configured() ) {
			return array();
		}
		$key    = 'aod_eco_communes_' . md5( $this->effective_domain() ) . '_' . $wilaya_code;
		$cached = get_transient( $key );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$s   = $this->settings();
		$url = add_query_arg(
			array( 'api_token' => $s['api_token'], 'wilaya_id' => $wilaya_code ),
			$this->api_base() . 'get/communes'
		);
		$res = $this->remote( 'GET', $url, array(
			'headers' => array( 'Authorization' => 'Bearer ' . $s['api_token'], 'Accept' => 'application/json' ),
		) );
		if ( is_wp_error( $res ) || ! is_array( $res ) ) {
			return array();
		}

		// La réponse est une liste d'objets ; on ne garde que la wilaya demandée.
		$list = array();
		foreach ( $res as $row ) {
			if ( is_array( $row ) && isset( $row['nom'] ) && (int) $row['wilaya_id'] === $wilaya_code ) {
				$list[] = array(
					'nom'           => (string) $row['nom'],
					'has_stop_desk' => ! empty( $row['has_stop_desk'] ),
				);
			}
		}
		if ( ! empty( $list ) ) {
			set_transient( $key, $list, 12 * HOUR_IN_SECONDS );
		}
		return $list;
	}

	/**
	 * Normalise un nom de commune pour une comparaison tolérante
	 * (accents, casse, apostrophes/tirets, et « y » ≈ « i »).
	 *
	 * @param string $name
	 * @return string
	 */
	protected function normalize_commune( $name ) {
		$name = remove_accents( (string) $name );
		$name = strtolower( $name );
		$name = str_replace( 'y', 'i', $name );           // Tichy ≈ Tichi.
		$name = preg_replace( '/[^a-z0-9]+/', '', $name ); // Retire espaces, apostrophes, tirets.
		return (string) $name;
	}

	/**
	 * Résout le nom de commune saisi vers l'orthographe canonique EcoTrack.
	 *
	 * Évite l'erreur « Commune mal écrite » en alignant le nom envoyé sur la
	 * base EcoTrack (correspondance normalisée puis distance de Levenshtein).
	 *
	 * @param int    $wilaya_code
	 * @param string $commune
	 * @return array [ 'nom' => string, 'has_stop_desk' => bool, 'matched' => bool ].
	 */
	protected function resolve_commune( $wilaya_code, $commune ) {
		$commune = trim( (string) $commune );
		$list    = $this->communes( $wilaya_code );
		if ( empty( $list ) ) {
			// Pas de référentiel disponible : on laisse EcoTrack valider tel quel.
			return array( 'nom' => $commune, 'has_stop_desk' => false, 'matched' => false );
		}

		$target = $this->normalize_commune( $commune );

		// 1) Correspondance normalisée exacte (gère accents/casse/ponctuation/y≈i).
		foreach ( $list as $c ) {
			if ( $this->normalize_commune( $c['nom'] ) === $target ) {
				return array( 'nom' => $c['nom'], 'has_stop_desk' => $c['has_stop_desk'], 'matched' => true );
			}
		}

		// 2) Repli : commune la plus proche (petites fautes de frappe).
		$best = null;
		$best_d = PHP_INT_MAX;
		foreach ( $list as $c ) {
			$d = levenshtein( $target, $this->normalize_commune( $c['nom'] ) );
			if ( $d < $best_d ) {
				$best_d = $d;
				$best   = $c;
			}
		}
		if ( $best && $best_d <= 2 ) {
			return array( 'nom' => $best['nom'], 'has_stop_desk' => $best['has_stop_desk'], 'matched' => true );
		}

		return array( 'nom' => $commune, 'has_stop_desk' => false, 'matched' => false );
	}

	/**
	 * Communes (clés normalisées) de la wilaya disposant d'un point relais EcoTrack.
	 *
	 * Permet au formulaire public de n'afficher « bureau » que là où EcoTrack a
	 * réellement un stop-desk. On renvoie null si le référentiel est indisponible
	 * (livreur non configuré ou API muette) afin de NE PAS masquer l'option à tort
	 * sur une panne réseau — la validation finale reste faite à la confirmation.
	 *
	 * @param int $wilaya_code 1-58.
	 * @return string[]|null Clés normalisées des communes avec bureau, ou null.
	 */
	public function desk_communes( $wilaya_code ) {
		$list = $this->communes( $wilaya_code );
		if ( empty( $list ) ) {
			return null; // Pas de référentiel : on ne filtre pas (fallback sûr).
		}
		$keys = array();
		foreach ( $list as $c ) {
			if ( ! empty( $c['has_stop_desk'] ) ) {
				$keys[] = $this->normalize_commune( $c['nom'] );
			}
		}
		return array_values( array_unique( $keys ) );
	}

	public function build_payload( $order ) {
		$s       = $this->settings();
		$is_desk = $this->is_stopdesk_order( $order );

		$raw_commune = $order->get_meta( '_aod_commune' ) ? $order->get_meta( '_aod_commune' ) : $order->get_billing_city();
		$match       = $this->resolve_commune( (int) $order->get_meta( '_aod_wilaya_code' ), $raw_commune );

		// EcoTrack pilote le stop-desk par la COMMUNE (champ has_stop_desk), pas
		// par un code de station : on ne transmet donc aucun « station_code ».
		$payload = array(
			'api_token'    => $s['api_token'],
			'reference'    => (string) $order->get_order_number(),
			'nom_client'   => $this->full_name( $order ),
			'telephone'    => $order->get_billing_phone(),
			'telephone_2'  => '',
			'adresse'      => $order->get_billing_address_1() ? $order->get_billing_address_1() : $raw_commune,
			'code_wilaya'  => (int) $order->get_meta( '_aod_wilaya_code' ),
			'commune'      => $match['nom'], // Orthographe canonique EcoTrack.
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
		return $payload;
	}

	public function create_parcel( $order ) {
		if ( ! $this->is_configured() ) {
			return new WP_Error( 'aod_ecotrack_no_creds', __( 'Domaine ou token EcoTrack manquant.', 'aod-cod-form' ) );
		}
		$wilaya = (int) $order->get_meta( '_aod_wilaya_code' );
		if ( ! $wilaya ) {
			return new WP_Error( 'aod_ecotrack_no_wilaya', __( 'Wilaya de la commande manquante.', 'aod-cod-form' ) );
		}

		// Stop-desk demandé mais la commune n'a pas de point relais EcoTrack :
		// message clair plutôt qu'un refus générique « commune mal écrite ».
		if ( $this->is_stopdesk_order( $order ) ) {
			$raw_commune = $order->get_meta( '_aod_commune' ) ? $order->get_meta( '_aod_commune' ) : $order->get_billing_city();
			$match       = $this->resolve_commune( $wilaya, $raw_commune );
			if ( $match['matched'] && ! $match['has_stop_desk'] ) {
				return new WP_Error( 'aod_ecotrack_no_desk', sprintf(
					/* translators: %s : nom de la commune */
					__( 'La commune « %s » ne dispose pas de point relais (stop-desk) EcoTrack. Choisissez la livraison à domicile.', 'aod-cod-form' ),
					$match['nom']
				) );
			}
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
