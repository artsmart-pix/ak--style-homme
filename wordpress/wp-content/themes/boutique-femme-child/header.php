<?php
/**
 * En-tête du site : logo, navigation (3 entrées), switcher FR/AR, menu mobile.
 *
 * @package BoutiqueFemme
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<!doctype html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<link rel="profile" href="https://gmpg.org/xfn/11">
	<?php wp_head(); ?>
</head>
<body <?php body_class( 'bf' ); ?>>
<?php if ( function_exists( 'wp_body_open' ) ) { wp_body_open(); } ?>

<a class="bf-skip" href="#bf-main"><?php esc_html_e( 'Aller au contenu', 'boutique-femme' ); ?></a>

<header class="bf-header" id="bf-header" data-header>
	<div class="bf-container bf-header__inner">

		<div class="bf-header__brand">
			<?php if ( has_custom_logo() ) : ?>
				<?php the_custom_logo(); ?>
			<?php else : ?>
				<?php echo bf_brand_wordmark(); // phpcs:ignore WordPress.Security.EscapeOutput ?>
			<?php endif; ?>
		</div>

		<nav class="bf-nav" aria-label="<?php esc_attr_e( 'Navigation principale', 'boutique-femme' ); ?>">
			<?php
			if ( has_nav_menu( 'primary' ) ) {
				wp_nav_menu( array(
					'theme_location' => 'primary',
					'container'      => false,
					'menu_class'     => 'bf-menu',
					'depth'          => 1,
					'fallback_cb'    => false,
				) );
			}
			?>
		</nav>

		<div class="bf-header__actions">
			<?php echo do_shortcode( '[aod_lang_switcher]' ); ?>
			<button class="bf-burger" type="button" aria-label="<?php esc_attr_e( 'Ouvrir le menu', 'boutique-femme' ); ?>" aria-expanded="false" data-burger>
				<span></span><span></span><span></span>
			</button>
		</div>
	</div>
</header>

<div class="bf-offcanvas" id="bf-offcanvas" data-offcanvas hidden>
	<div class="bf-offcanvas__scrim" data-offcanvas-close></div>
	<button class="bf-offcanvas__close" type="button" aria-label="<?php esc_attr_e( 'Fermer le menu', 'boutique-femme' ); ?>" data-offcanvas-close>&times;</button>
	<nav class="bf-offcanvas__panel" aria-label="<?php esc_attr_e( 'Navigation principale', 'boutique-femme' ); ?>">
		<?php
		if ( has_nav_menu( 'mobile_menu' ) ) {
			wp_nav_menu( array(
				'theme_location' => 'mobile_menu',
				'container'      => false,
				'menu_class'     => 'bf-menu-mobile',
				'depth'          => 1,
				'fallback_cb'    => false,
			) );
		} elseif ( has_nav_menu( 'primary' ) ) {
			wp_nav_menu( array(
				'theme_location' => 'primary',
				'container'      => false,
				'menu_class'     => 'bf-menu-mobile',
				'depth'          => 1,
				'fallback_cb'    => false,
			) );
		}
		?>
		<div class="bf-offcanvas__lang"><?php echo do_shortcode( '[aod_lang_switcher]' ); ?></div>
	</nav>
</div>

<main id="bf-main" class="bf-site-main">
