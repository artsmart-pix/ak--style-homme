<?php
/**
 * Pied de page : marque, navigation, coordonnées, réseaux, mention COD.
 *
 * @package BoutiqueFemme
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
</main><!-- #bf-main -->

<footer class="bf-footer">
	<div class="bf-container bf-footer__grid">

		<div class="bf-footer__brand">
			<?php if ( has_custom_logo() ) : ?>
				<?php the_custom_logo(); ?>
			<?php else : ?>
				<?php echo bf_brand_wordmark( true ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
			<?php endif; ?>
			<p class="bf-footer__tag"><?php esc_html_e( 'Le vestiaire masculin, livré chez vous, payé à la livraison — partout en Algérie.', 'boutique-femme' ); ?></p>

			<div class="bf-footer__social">
				<?php
				$socials = array(
					'bf_instagram' => 'Instagram',
					'bf_facebook'  => 'Facebook',
					'bf_tiktok'    => 'TikTok',
				);
				foreach ( $socials as $key => $label ) {
					$url = bf_info( $key );
					if ( $url ) {
						printf(
							'<a href="%s" target="_blank" rel="noopener noreferrer">%s</a>',
							esc_url( $url ),
							esc_html( $label )
						);
					}
				}
				?>
			</div>
		</div>

		<nav class="bf-footer__col" aria-label="<?php esc_attr_e( 'Liens', 'boutique-femme' ); ?>">
			<h4><?php esc_html_e( 'Navigation', 'boutique-femme' ); ?></h4>
			<?php
			if ( has_nav_menu( 'primary' ) ) {
				wp_nav_menu( array(
					'theme_location' => 'primary',
					'container'      => false,
					'menu_class'     => 'bf-footer__menu',
					'depth'          => 1,
					'fallback_cb'    => false,
				) );
			}
			?>
		</nav>

		<div class="bf-footer__col">
			<h4><?php esc_html_e( 'Nous contacter', 'boutique-femme' ); ?></h4>
			<ul class="bf-footer__contact">
				<?php if ( bf_info( 'bf_phone' ) ) : ?>
					<li><a href="tel:<?php echo esc_attr( preg_replace( '/\s+/', '', bf_info( 'bf_phone' ) ) ); ?>"><?php echo esc_html( bf_info( 'bf_phone' ) ); ?></a></li>
				<?php endif; ?>
				<?php if ( bf_info( 'bf_email' ) ) : ?>
					<li><a href="mailto:<?php echo esc_attr( bf_info( 'bf_email' ) ); ?>"><?php echo esc_html( bf_info( 'bf_email' ) ); ?></a></li>
				<?php endif; ?>
				<?php if ( bf_info( 'bf_address' ) ) : ?>
					<li><?php echo esc_html( bf_info( 'bf_address' ) ); ?></li>
				<?php endif; ?>
				<?php if ( bf_info( 'bf_hours' ) ) : ?>
					<li><?php echo esc_html( bf_info( 'bf_hours' ) ); ?></li>
				<?php endif; ?>
			</ul>
		</div>

		<div class="bf-footer__col bf-footer__cod">
			<span class="bf-footer__cod-badge"><?php echo bf_icon( 'truck' ); // phpcs:ignore ?></span>
			<p><strong><?php esc_html_e( 'Paiement à la livraison', 'boutique-femme' ); ?></strong><br>
			<?php esc_html_e( 'Commandez sans carte. Vous payez en espèces à la réception.', 'boutique-femme' ); ?></p>
		</div>

	</div>

	<div class="bf-footer__bottom">
		<div class="bf-container">
			<span>&copy; <?php echo esc_html( date_i18n( 'Y' ) ); ?> <?php bloginfo( 'name' ); ?>. <?php esc_html_e( 'Tous droits réservés.', 'boutique-femme' ); ?></span>
		</div>
	</div>
</footer>

<?php wp_footer(); ?>
</body>
</html>
