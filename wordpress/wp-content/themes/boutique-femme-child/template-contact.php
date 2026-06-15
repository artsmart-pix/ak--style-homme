<?php
/**
 * Template Name: Contact
 *
 * Page Contact : coordonnées + formulaire de contact bilingue (FR/AR, RTL).
 *
 * @package BoutiqueFemme
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

get_header();

$phone    = bf_info( 'bf_phone' );
$whatsapp = bf_info( 'bf_whatsapp' );
$email    = bf_info( 'bf_email' ) ?: get_option( 'admin_email' );
$address  = bf_info( 'bf_address' );
$hours    = bf_info( 'bf_hours' );
?>

<section class="bf-section bf-section--nude">
	<div class="bf-container">
		<div class="bf-section__head reveal">
			<p class="bf-eyebrow"><?php esc_html_e( 'On vous écoute', 'boutique-femme' ); ?></p>
			<h1 class="bf-shop-title"><?php the_title(); ?></h1>
			<p><?php esc_html_e( 'Une question sur une taille, une commande, une livraison ? Écrivez-nous, on répond vite.', 'boutique-femme' ); ?></p>
		</div>

		<?php
		// Contenu libre éventuel saisi dans l'éditeur de la page.
		while ( have_posts() ) :
			the_post();
			$content = trim( get_the_content() );
			if ( $content ) {
				echo '<div class="bf-page-content reveal" style="max-width:720px;margin:0 auto 2.4rem;text-align:center">';
				the_content();
				echo '</div>';
			}
		endwhile;
		?>

		<div class="bf-contact">
			<div class="bf-contact__info reveal">
				<h2><?php esc_html_e( 'Nos coordonnées', 'boutique-femme' ); ?></h2>
				<ul class="bf-contact__list">
					<?php if ( $phone ) : ?>
						<li>
							<span class="bf-reassure__icon"><?php echo bf_icon( 'truck' ); // phpcs:ignore ?></span>
							<div><strong><?php esc_html_e( 'Téléphone', 'boutique-femme' ); ?></strong>
							<a href="tel:<?php echo esc_attr( preg_replace( '/\s+/', '', $phone ) ); ?>"><?php echo esc_html( $phone ); ?></a></div>
						</li>
					<?php endif; ?>
					<?php if ( $whatsapp ) : ?>
						<li>
							<span class="bf-reassure__icon"><?php echo bf_icon( 'heart' ); // phpcs:ignore ?></span>
							<div><strong><?php esc_html_e( 'WhatsApp', 'boutique-femme' ); ?></strong>
							<a href="https://wa.me/<?php echo esc_attr( preg_replace( '/\D+/', '', $whatsapp ) ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $phone ?: '+' . preg_replace( '/\D+/', '', $whatsapp ) ); ?></a></div>
						</li>
					<?php endif; ?>
					<li>
						<span class="bf-reassure__icon"><?php echo bf_icon( 'pin' ); // phpcs:ignore ?></span>
						<div><strong><?php esc_html_e( 'E-mail', 'boutique-femme' ); ?></strong>
						<a href="mailto:<?php echo esc_attr( $email ); ?>"><?php echo esc_html( $email ); ?></a></div>
					</li>
					<?php if ( $address ) : ?>
						<li>
							<span class="bf-reassure__icon"><?php echo bf_icon( 'pin' ); // phpcs:ignore ?></span>
							<div><strong><?php esc_html_e( 'Adresse', 'boutique-femme' ); ?></strong>
							<span><?php echo esc_html( $address ); ?></span></div>
						</li>
					<?php endif; ?>
					<?php if ( $hours ) : ?>
						<li>
							<span class="bf-reassure__icon"><?php echo bf_icon( 'check' ); // phpcs:ignore ?></span>
							<div><strong><?php esc_html_e( 'Horaires', 'boutique-femme' ); ?></strong>
							<span><?php echo esc_html( $hours ); ?></span></div>
						</li>
					<?php endif; ?>
				</ul>

				<p class="bf-reassure-inline" style="margin-top:1.4rem">
					<?php esc_html_e( 'Astuce : pour commander, ajoutez l\'article au formulaire sur sa fiche produit. Le paiement se fait à la livraison.', 'boutique-femme' ); ?>
				</p>
			</div>

			<div class="bf-contact__form reveal d1">
				<?php echo bf_contact_form_html(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			</div>
		</div>
	</div>
</section>

<?php
get_footer();
