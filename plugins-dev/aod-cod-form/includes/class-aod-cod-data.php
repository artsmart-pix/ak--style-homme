<?php
/**
 * Données géographiques (wilayas/communes) et tarifs de livraison.
 *
 * @package AOD_COD_Form
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AOD_COD_Data {

	/** Clé d'option pour les tarifs de livraison. */
	const OPTION_PRICES = 'aod_cod_delivery_prices';

	/** Clé d'option pour le seuil de livraison gratuite. */
	const OPTION_FREE_SHIP = 'aod_cod_free_shipping';

	/** @var array|null Cache des wilayas+communes. */
	protected static $places = null;

	/**
	 * Charge le JSON des 58 wilayas + communes.
	 *
	 * @return array Liste : [ ['code'=>1,'name'=>'Adrar','communes'=>[...]], ... ]
	 */
	public static function places() {
		if ( null !== self::$places ) {
			return self::$places;
		}
		$file = AOD_COD_PATH . 'includes/data/dz-places.json';
		$raw  = is_readable( $file ) ? file_get_contents( $file ) : '';
		$data = json_decode( $raw, true );
		self::$places = is_array( $data ) ? $data : array();
		return self::$places;
	}

	/**
	 * Communes d'une wilaya donnée.
	 *
	 * @param int $code Code wilaya (1-58).
	 * @return array[] Liste d'objets [ 'name' => 'Adrar', 'name_ar' => 'أدرار' ].
	 */
	public static function communes( $code ) {
		foreach ( self::places() as $w ) {
			if ( (int) $w['code'] === (int) $code ) {
				return $w['communes'];
			}
		}
		return array();
	}

	/**
	 * Nom latin (FR) d'une wilaya.
	 *
	 * @param int $code
	 * @return string
	 */
	public static function wilaya_name( $code ) {
		foreach ( self::places() as $w ) {
			if ( (int) $w['code'] === (int) $code ) {
				return $w['name'];
			}
		}
		return '';
	}

	/**
	 * Nom arabe d'une wilaya.
	 *
	 * @param int $code
	 * @return string
	 */
	public static function wilaya_name_ar( $code ) {
		foreach ( self::places() as $w ) {
			if ( (int) $w['code'] === (int) $code ) {
				return isset( $w['name_ar'] ) ? $w['name_ar'] : '';
			}
		}
		return '';
	}

	/**
	 * Le site doit-il afficher les noms en arabe ?
	 *
	 * @return bool
	 */
	public static function is_arabic() {
		return ( 0 === strpos( get_locale(), 'ar' ) ) || is_rtl();
	}

	/**
	 * Choisit le libellé à afficher (arabe si site arabe, sinon latin).
	 *
	 * @param string $name    Nom latin.
	 * @param string $name_ar Nom arabe.
	 * @return string
	 */
	public static function label( $name, $name_ar ) {
		return ( self::is_arabic() && '' !== (string) $name_ar ) ? $name_ar : $name;
	}

	/**
	 * Récupère le nom arabe d'une commune (chaîne vide si inconnue).
	 *
	 * @param int    $code    Code wilaya.
	 * @param string $commune Nom latin de la commune.
	 * @return string
	 */
	public static function commune_name_ar( $code, $commune ) {
		foreach ( self::communes( $code ) as $c ) {
			if ( isset( $c['name'] ) && $c['name'] === $commune ) {
				return isset( $c['name_ar'] ) ? $c['name_ar'] : '';
			}
		}
		return '';
	}

	/**
	 * Vérifie qu'une commune (nom latin) appartient bien à la wilaya (anti-triche).
	 *
	 * @param int    $code    Code wilaya.
	 * @param string $commune Nom latin de commune.
	 * @return bool
	 */
	public static function commune_valid( $code, $commune ) {
		foreach ( self::communes( $code ) as $c ) {
			if ( isset( $c['name'] ) && $c['name'] === $commune ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Table des tarifs de livraison.
	 *
	 * @return array [ wilaya_code => [ 'home'=>int, 'desk'=>int ] ]
	 */
	public static function prices() {
		$saved = get_option( self::OPTION_PRICES, array() );
		return is_array( $saved ) ? $saved : array();
	}

	/**
	 * Tarif pour une wilaya + un mode de livraison.
	 *
	 * @param int    $code Code wilaya.
	 * @param string $type 'home' (domicile) ou 'desk' (stop-desk).
	 * @return float
	 */
	public static function price_for( $code, $type ) {
		$prices = self::prices();
		$type   = ( 'desk' === $type ) ? 'desk' : 'home';
		if ( isset( $prices[ $code ][ $type ] ) && '' !== $prices[ $code ][ $type ] ) {
			return (float) $prices[ $code ][ $type ];
		}
		return 0.0;
	}

	/**
	 * Réglages de la livraison gratuite (avec valeurs par défaut).
	 *
	 * @return array { 'enabled' => 0|1, 'threshold' => float }
	 */
	public static function free_shipping() {
		$saved = get_option( self::OPTION_FREE_SHIP, array() );
		return wp_parse_args( (array) $saved, array(
			'enabled'   => 0,
			'threshold' => 0,
		) );
	}

	/**
	 * Seuil de livraison gratuite effectif (0 = désactivé).
	 *
	 * @return float Montant de sous-total à atteindre, ou 0 si la gratuité est off.
	 */
	public static function free_shipping_threshold() {
		$s = self::free_shipping();
		return ! empty( $s['enabled'] ) ? (float) $s['threshold'] : 0.0;
	}

	/**
	 * Frais de livraison effectifs, seuil de gratuité appliqué.
	 *
	 * @param int    $code     Code wilaya.
	 * @param string $type     'home' ou 'desk'.
	 * @param float  $subtotal Sous-total produits de la commande.
	 * @return float 0 si le seuil de gratuité est atteint, sinon le tarif de base.
	 */
	public static function effective_shipping( $code, $type, $subtotal ) {
		$threshold = self::free_shipping_threshold();
		if ( $threshold > 0 && (float) $subtotal >= $threshold ) {
			return 0.0;
		}
		return self::price_for( $code, $type );
	}
}
