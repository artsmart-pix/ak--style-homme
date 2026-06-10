<?php
/**
 * Sélecteur de langue visiteur (FR ⇄ AR) sans plugin tiers.
 *
 * Mémorise le choix du visiteur dans un cookie et force la locale WordPress
 * (déterminée par determine_locale/locale) pour ce visiteur uniquement.
 * N'affecte pas l'administration.
 *
 * @package AOD_COD_Form
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AOD_COD_Lang {

	/** Nom du cookie mémorisant le choix. */
	const COOKIE = 'aod_lang';

	/** Paramètre d'URL déclenchant le changement. */
	const PARAM = 'aod_lang';

	/** Langues supportées : clé courte => locale WordPress. */
	const SUPPORTED = array(
		'fr' => 'fr_FR',
		'ar' => 'ar',
	);

	/**
	 * Branche les hooks. Appelé tôt (plugins_loaded) pour que le filtre de
	 * locale soit actif avant le chargement des traductions.
	 */
	/** Emplacements de menu où injecter le sélecteur (en-tête desktop + mobile/off-canvas). */
	const MENU_LOCATIONS = array( 'primary', 'secondary_menu', 'mobile_menu' );

	public static function init() {
		add_filter( 'locale', array( __CLASS__, 'filter_locale' ) );
		add_filter( 'determine_locale', array( __CLASS__, 'filter_locale' ) );

		add_action( 'init', array( __CLASS__, 'maybe_set_cookie' ), 1 );

		// CSS dans le <head> (front uniquement).
		add_action( 'wp_head', array( __CLASS__, 'print_inline_css' ) );

		// Injection dans le menu de l'en-tête.
		add_filter( 'wp_nav_menu_items', array( __CLASS__, 'add_to_menu' ), 100, 2 );

		// Reste disponible manuellement : [aod_lang_switcher].
		add_shortcode( 'aod_lang_switcher', array( __CLASS__, 'shortcode' ) );
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
		// On garde l'admin dans la langue du site (mais on applique sur admin-ajax).
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
	protected static function active() {
		return ( 0 === strpos( get_locale(), 'ar' ) ) ? 'ar' : 'fr';
	}

	/**
	 * HTML du sélecteur (2 liens).
	 *
	 * @return string
	 */
	protected static function html() {
		$active = self::active();
		$fr_url = esc_url( add_query_arg( self::PARAM, 'fr' ) );
		$ar_url = esc_url( add_query_arg( self::PARAM, 'ar' ) );

		ob_start();
		?>
		<div class="aod-lang" role="group" aria-label="<?php esc_attr_e( 'Choix de la langue', 'aod-cod-form' ); ?>">
			<a class="aod-lang__btn<?php echo ( 'fr' === $active ) ? ' is-active' : ''; ?>" href="<?php echo $fr_url; ?>" hreflang="fr" lang="fr" rel="nofollow">FR</a>
			<a class="aod-lang__btn<?php echo ( 'ar' === $active ) ? ' is-active' : ''; ?>" href="<?php echo $ar_url; ?>" hreflang="ar" lang="ar" rel="nofollow">العربية</a>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * Injecte le sélecteur à la fin du menu de l'en-tête.
	 *
	 * @param string   $items Le HTML des éléments du menu.
	 * @param stdClass $args  Arguments de wp_nav_menu (dont theme_location).
	 * @return string
	 */
	public static function add_to_menu( $items, $args ) {
		if ( is_admin() ) {
			return $items;
		}
		$location = isset( $args->theme_location ) ? $args->theme_location : '';
		if ( ! in_array( $location, self::MENU_LOCATIONS, true ) ) {
			return $items;
		}
		return $items . '<li class="menu-item menu-item-aod-lang">' . self::html() . '</li>';
	}

	/**
	 * Shortcode [aod_lang_switcher] — à placer dans un widget, header, etc.
	 *
	 * @return string
	 */
	public static function shortcode() {
		return self::html();
	}

	/**
	 * CSS minimal, injecté une seule fois dans le <head> (front).
	 */
	public static function print_inline_css() {
		if ( is_admin() ) {
			return;
		}
		static $done = false;
		if ( $done ) {
			return;
		}
		$done = true;
		?>
		<style id="aod-lang-css">
			.menu-item-aod-lang{display:flex;align-items:center}
			.menu-item-aod-lang > a,.menu-item-aod-lang:hover > a{background:none!important}
			.aod-lang{display:inline-flex;gap:3px;background:#f3f4f6;border-radius:999px;padding:3px;vertical-align:middle}
			.aod-lang__btn{display:inline-block!important;min-width:34px;text-align:center;padding:5px 10px!important;border-radius:999px;font-size:13px;font-weight:600;line-height:1;text-decoration:none!important;color:#374151!important;transition:.15s}
			.aod-lang__btn:hover{background:#e5e7eb;color:#111!important}
			.aod-lang__btn.is-active{background:#16a34a;color:#fff!important}
		</style>
		<?php
	}
}
