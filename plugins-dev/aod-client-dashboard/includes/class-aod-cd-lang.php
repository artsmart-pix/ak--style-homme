<?php
/**
 * Sélecteur de langue (FR ⇄ AR) pour l'espace de gestion /gestion.
 *
 * Autonome (aucune dépendance à un plugin tiers). Mémorise le choix dans un
 * cookie et force la locale WordPress pour ce visiteur. Le cookie est partagé
 * avec le sélecteur de la partie publique (même nom « aod_lang ») : le gérant
 * retrouve donc la même langue côté boutique et côté gestion.
 *
 * @package AOD_Client_Dashboard
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Accès direct interdit.
}

class AOD_CD_Lang {

	/** Nom du cookie mémorisant le choix (partagé avec la partie publique). */
	const COOKIE = 'aod_lang';

	/** Paramètre d'URL déclenchant le changement. */
	const PARAM = 'aod_lang';

	/** Langues supportées : clé courte => locale WordPress. */
	const SUPPORTED = array(
		'fr' => 'fr_FR',
		'ar' => 'ar',
	);

	/**
	 * Branche les hooks. À appeler tôt (plugins_loaded), avant le chargement
	 * des traductions, pour que le filtre de locale soit déjà actif.
	 */
	public static function init() {
		add_filter( 'locale', array( __CLASS__, 'filter_locale' ) );
		add_filter( 'determine_locale', array( __CLASS__, 'filter_locale' ) );
		add_action( 'init', array( __CLASS__, 'maybe_set_cookie' ), 1 );
	}

	/**
	 * Choix courant (clé courte : 'fr' / 'ar') ou '' si aucun.
	 *
	 * @return string
	 */
	protected static function current_choice() {
		if ( isset( $_GET[ self::PARAM ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			$k = sanitize_key( wp_unslash( $_GET[ self::PARAM ] ) ); // phpcs:ignore WordPress.Security.NonceVerification
			if ( isset( self::SUPPORTED[ $k ] ) ) {
				return $k;
			}
		}
		if ( isset( $_COOKIE[ self::COOKIE ] ) ) {
			$k = sanitize_key( wp_unslash( $_COOKIE[ self::COOKIE ] ) );
			if ( isset( self::SUPPORTED[ $k ] ) ) {
				return $k;
			}
		}
		return '';
	}

	/**
	 * Force la locale pour le visiteur (front + AJAX), jamais en admin réel.
	 *
	 * @param string $locale Locale calculée par WordPress.
	 * @return string
	 */
	public static function filter_locale( $locale ) {
		if ( is_admin() && ! wp_doing_ajax() ) {
			return $locale;
		}
		$k = self::current_choice();
		return $k ? self::SUPPORTED[ $k ] : $locale;
	}

	/**
	 * Si ?aod_lang=xx est présent : pose le cookie puis nettoie l'URL.
	 */
	public static function maybe_set_cookie() {
		if ( ! isset( $_GET[ self::PARAM ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			return;
		}
		$k = sanitize_key( wp_unslash( $_GET[ self::PARAM ] ) ); // phpcs:ignore WordPress.Security.NonceVerification
		if ( ! isset( self::SUPPORTED[ $k ] ) ) {
			return;
		}

		// Cookie 1 an, sur tout le site.
		if ( ! headers_sent() ) {
			setcookie( self::COOKIE, $k, time() + YEAR_IN_SECONDS, COOKIEPATH ? COOKIEPATH : '/', COOKIE_DOMAIN );
		}
		$_COOKIE[ self::COOKIE ] = $k;

		// Redirige vers l'URL sans le paramètre (évite la mise en cache du lien).
		$clean = remove_query_arg( self::PARAM );
		if ( ! headers_sent() ) {
			wp_safe_redirect( $clean );
			exit;
		}
	}

	/**
	 * Langue actuellement affichée (clé courte).
	 *
	 * @return string 'ar' ou 'fr'
	 */
	public static function active() {
		return ( 0 === strpos( get_locale(), 'ar' ) ) ? 'ar' : 'fr';
	}

	/**
	 * HTML du sélecteur (2 boutons-liens), à placer dans l'en-tête du dashboard.
	 *
	 * @return string
	 */
	public static function switcher() {
		$active = self::active();
		$fr_url = esc_url( add_query_arg( self::PARAM, 'fr' ) );
		$ar_url = esc_url( add_query_arg( self::PARAM, 'ar' ) );

		ob_start();
		?>
		<div class="aod-cd-lang" role="group" aria-label="<?php esc_attr_e( 'Choix de la langue', 'aod-client-dashboard' ); ?>">
			<a class="aod-cd-lang__btn<?php echo ( 'fr' === $active ) ? ' is-active' : ''; ?>" href="<?php echo $fr_url; // phpcs:ignore WordPress.Security.EscapeOutput ?>" hreflang="fr" lang="fr" rel="nofollow">FR</a>
			<a class="aod-cd-lang__btn<?php echo ( 'ar' === $active ) ? ' is-active' : ''; ?>" href="<?php echo $ar_url; // phpcs:ignore WordPress.Security.EscapeOutput ?>" hreflang="ar" lang="ar" rel="nofollow">العربية</a>
		</div>
		<?php
		return ob_get_clean();
	}
}
