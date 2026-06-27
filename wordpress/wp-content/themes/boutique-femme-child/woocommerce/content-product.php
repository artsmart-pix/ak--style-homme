<?php
/**
 * Carte produit « affiche » — AK Style.
 *
 * Override du gabarit WooCommerce (loop). Conserve la règle d'or n°4 :
 * AUCUN bouton panier — le bouton « Commander » renvoie vers la fiche produit
 * (formulaire COD). Média en affiche + overlay animé + corps soigné.
 *
 * @package BoutiqueFemme
 */

defined( 'ABSPATH' ) || exit;

global $product;

if ( empty( $product ) || ! $product->is_visible() ) {
	return;
}

$link = get_permalink( $product->get_id() );
$name = $product->get_name();

// Première catégorie → puce.
$cat   = '';
$terms = get_the_terms( $product->get_id(), 'product_cat' );
if ( $terms && ! is_wp_error( $terms ) ) {
	$first = array_shift( $terms );
	$cat   = $first ? $first->name : '';
}

$arrow = function_exists( 'bf_icon' ) ? bf_icon( 'arrow' ) : '';
?>
<li <?php wc_product_class( 'bf-card', $product ); ?>>

	<div class="bf-card__media">
		<?php if ( $product->is_on_sale() ) : ?>
			<span class="bf-badge bf-badge--sale"><?php esc_html_e( 'Promo', 'boutique-femme' ); ?></span>
		<?php endif; ?>

		<?php if ( $cat ) : ?>
			<span class="bf-card__cat"><?php echo esc_html( $cat ); ?></span>
		<?php endif; ?>

		<a class="bf-card__link" href="<?php echo esc_url( $link ); ?>" aria-label="<?php echo esc_attr( $name ); ?>">
			<?php echo woocommerce_get_product_thumbnail( 'bf-portrait' ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
			<span class="bf-card__overlay" aria-hidden="true">
				<span class="bf-card__view"><?php esc_html_e( 'Voir le produit', 'boutique-femme' ); ?> <?php echo $arrow; // phpcs:ignore ?></span>
			</span>
		</a>
	</div>

	<div class="bf-card__body">
		<h2 class="woocommerce-loop-product__title">
			<a href="<?php echo esc_url( $link ); ?>"><?php echo esc_html( $name ); ?></a>
		</h2>
		<div class="bf-card__price"><?php echo $product->get_price_html(); // phpcs:ignore WordPress.Security.EscapeOutput ?></div>
		<a href="<?php echo esc_url( $link ); ?>" class="bf-btn bf-btn--card">
			<?php esc_html_e( 'Commander', 'boutique-femme' ); ?> <?php echo $arrow; // phpcs:ignore ?>
		</a>
	</div>

</li>
