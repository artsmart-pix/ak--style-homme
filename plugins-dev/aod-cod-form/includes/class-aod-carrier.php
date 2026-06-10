<?php
/**
 * Classe de base des transporteurs (livreurs).
 *
 * Chaque livreur (Yalidine, Noest, Ecotrack…) hérite de cette classe et
 * implémente son authentification, ses champs de réglages et la création
 * de colis. L'orchestrateur AOD_Shipping gère l'UI commune.
 *
 * @package AOD_COD_Form
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

abstract class AOD_Carrier {

	/* Métadonnées de commande partagées par tous les livreurs. */
	const META_CARRIER   = '_aod_ship_carrier';
	const META_TRACKING  = '_aod_ship_tracking';
	const META_LABEL     = '_aod_ship_label';
	const META_IMPORT_ID = '_aod_ship_import_id';
	const META_STOPDESK  = '_aod_ship_stopdesk';
	const META_ERROR     = '_aod_ship_error';

	/* ---- À implémenter par chaque livreur ---- */

	/** Identifiant machine (ex. 'yalidine'). */
	abstract public function id();

	/** Nom affiché (ex. 'Yalidine'). */
	abstract public function label();

	/** Valeurs de réglages par défaut. */
	abstract protected function defaults();

	/** L'API est-elle configurée (clés présentes) ? */
	abstract public function is_configured();

	/** Affiche les lignes de réglages (dans le <form> commun, noms préfixés par l'id). */
	abstract public function render_settings_fields();

	/** Nettoie/valide les réglages soumis et renvoie le tableau à sauvegarder. */
	abstract public function sanitize_settings( $input );

	/** Construit la charge utile du colis (utile pour debug/preview). */
	abstract public function build_payload( $order );

	/**
	 * Crée le colis chez le livreur.
	 *
	 * @param WC_Order $order
	 * @return array|WP_Error [ 'tracking'=>, 'label'=>, 'import_id'=> ] ou WP_Error.
	 */
	abstract public function create_parcel( $order );

	/* ---- Comportements par défaut (surchargeables) ---- */

	/** Le livreur gère-t-il le stop-desk (point relais) ? */
	public function supports_stopdesk() {
		return false;
	}

	/**
	 * Couleur de marque (pastille/icône). Surchargée par chaque livreur.
	 *
	 * @return string Code couleur hexadécimal (#RRGGBB).
	 */
	public function brand_color() {
		return '#64748b';
	}

	/**
	 * Initiales affichées dans la pastille (1 à 3 lettres).
	 *
	 * Par défaut : déduites du libellé (ex. « Speed Delivery » → « SD »).
	 *
	 * @return string
	 */
	public function initials() {
		$words = preg_split( '/[\s\-]+/', (string) $this->label(), -1, PREG_SPLIT_NO_EMPTY );
		if ( empty( $words ) ) {
			return '?';
		}
		if ( 1 === count( $words ) ) {
			return strtoupper( substr( $words[0], 0, 2 ) );
		}
		return strtoupper( substr( $words[0], 0, 1 ) . substr( $words[1], 0, 1 ) );
	}

	/**
	 * Pastille-icône HTML du livreur (monogramme coloré).
	 *
	 * @return string HTML sûr (déjà échappé).
	 */
	public function icon_html() {
		return '<span class="aod-carrier-ic" style="--ic:' . esc_attr( $this->brand_color() ) . '">'
			. esc_html( $this->initials() ) . '</span>';
	}

	/**
	 * Liste des centres/points relais d'une wilaya.
	 *
	 * @param int $wilaya_code 1-58.
	 * @return array|WP_Error Liste [ ['id'=>, 'name'=>], ... ].
	 */
	public function get_centers( $wilaya_code ) {
		return array();
	}

	/* ---- Helpers communs ---- */

	/** Clé d'option WordPress des réglages de ce livreur. */
	public function option_key() {
		return 'aod_carrier_' . $this->id();
	}

	/** Réglages enregistrés fusionnés avec les valeurs par défaut. */
	public function settings() {
		$saved = get_option( $this->option_key(), array() );
		return wp_parse_args( is_array( $saved ) ? $saved : array(), $this->defaults() );
	}

	/** Enregistre les réglages. */
	public function save_settings( $data ) {
		update_option( $this->option_key(), $data );
	}

	/**
	 * Requête HTTP générique avec décodage JSON et gestion d'erreurs.
	 *
	 * @param string $method GET/POST…
	 * @param string $url    URL complète.
	 * @param array  $args   Arguments wp_remote_request (headers, body…).
	 * @return array|WP_Error
	 */
	protected function remote( $method, $url, $args = array() ) {
		$args = wp_parse_args( $args, array( 'method' => $method, 'timeout' => 30 ) );

		$resp = wp_remote_request( $url, $args );
		if ( is_wp_error( $resp ) ) {
			return $resp;
		}
		$code = (int) wp_remote_retrieve_response_code( $resp );
		$raw  = wp_remote_retrieve_body( $resp );
		$data = json_decode( $raw, true );

		if ( $code < 200 || $code >= 300 ) {
			$msg = ( is_array( $data ) && ! empty( $data['message'] ) )
				? $data['message']
				: sprintf( __( 'Erreur API %1$s (HTTP %2$d).', 'aod-cod-form' ), $this->label(), $code );
			// Détaille les erreurs de validation (format Laravel { errors: { champ: [..] } })
			// au lieu du résumé tronqué « … (and 1 more error) ».
			if ( is_array( $data ) && ! empty( $data['errors'] ) && is_array( $data['errors'] ) ) {
				$details = array();
				foreach ( $data['errors'] as $field_errors ) {
					foreach ( (array) $field_errors as $fe ) {
						$details[] = (string) $fe;
					}
				}
				$details = array_values( array_unique( array_filter( $details ) ) );
				if ( ! empty( $details ) ) {
					$msg = implode( ' ', $details );
				}
			}
			return new WP_Error( 'aod_carrier_http', $msg, array( 'http' => $code, 'data' => $data, 'raw' => $raw ) );
		}
		return is_array( $data ) ? $data : array( '_raw' => $raw );
	}

	/** Découpe un nom complet en [prénom, nom]. */
	protected function split_name( $full ) {
		$full = trim( preg_replace( '/\s+/', ' ', (string) $full ) );
		if ( '' === $full ) {
			return array( '-', '-' );
		}
		$parts = explode( ' ', $full );
		if ( 1 === count( $parts ) ) {
			return array( $parts[0], $parts[0] );
		}
		$first = array_shift( $parts );
		return array( $first, implode( ' ', $parts ) );
	}

	/** Nom complet du client de la commande. */
	protected function full_name( $order ) {
		return trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() );
	}

	/** Description des produits : « Nom x2, Autre x1 ». */
	protected function product_list( $order ) {
		$items = array();
		foreach ( $order->get_items() as $item ) {
			$items[] = $item->get_name() . ' x' . $item->get_quantity();
		}
		return implode( ', ', $items );
	}

	/** Total de la commande arrondi à l'entier (montant COD à encaisser). */
	protected function order_total( $order ) {
		return (int) round( (float) $order->get_total() );
	}

	/** La commande est-elle en stop-desk ? */
	protected function is_stopdesk_order( $order ) {
		return 'desk' === $order->get_meta( '_aod_delivery_type' );
	}
}
