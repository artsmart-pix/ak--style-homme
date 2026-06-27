<?php
/**
 * Page d'accueil — vitrine de la boutique.
 *
 * Héro · réassurance · catégories · sélection produits · argument grande
 * taille · témoignages · appel à l'action. Mobile-first, animée, RTL.
 *
 * @package BoutiqueFemme
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

get_header();

$shop = bf_shop_url();
?>

<!-- ============================ HÉROS ============================ -->
<section class="bf-hero" style="<?php echo esc_attr( bf_bg_style( 'hero' ) ); ?>">
	<div class="bf-container">
		<div class="bf-hero__inner reveal is-visible">
			<p class="bf-eyebrow"><?php esc_html_e( 'Nouvelle collection — Homme', 'boutique-femme' ); ?></p>
			<h1><?php echo esc_html( get_theme_mod( 'bf_hero_title', __( 'Le style masculin, sans effort', 'boutique-femme' ) ) ); ?></h1>
			<p class="bf-hero__sub"><?php esc_html_e( 'Des essentiels masculins bien coupés — t-shirts, polos, sweats et accessoires. Qualité, confort et livraison partout en Algérie, payée à la réception.', 'boutique-femme' ); ?></p>
			<div class="bf-hero__cta">
				<a class="bf-btn bf-btn--primary" href="<?php echo esc_url( $shop ); ?>">
					<?php esc_html_e( 'Découvrir la boutique', 'boutique-femme' ); ?> <?php echo bf_icon( 'arrow' ); // phpcs:ignore ?>
				</a>
				<a class="bf-btn bf-btn--light" href="#bf-selection"><?php esc_html_e( 'Nos best-sellers', 'boutique-femme' ); ?></a>
			</div>
			<div class="bf-hero__chips">
				<span><?php echo bf_icon( 'truck' ); // phpcs:ignore ?> <?php esc_html_e( 'Paiement à la livraison', 'boutique-femme' ); ?></span>
				<span><?php echo bf_icon( 'pin' ); // phpcs:ignore ?> <?php esc_html_e( '58 wilayas', 'boutique-femme' ); ?></span>
				<span><?php echo bf_icon( 'heart' ); // phpcs:ignore ?> <?php esc_html_e( 'Du S au 3XL', 'boutique-femme' ); ?></span>
			</div>
		</div>
	</div>
	<span class="bf-hero__sheen" aria-hidden="true"></span>
	<span class="bf-hero__scroll" aria-hidden="true"></span>
</section>

<!-- ======================== RÉASSURANCE ========================= -->
<?php bf_reassurance_band(); ?>

<!-- ===================== BANDEAU DÉFILANT ======================= -->
<?php bf_marquee(); ?>

<!-- ========================= CATÉGORIES ========================= -->
<?php
$cats = get_terms( array(
	'taxonomy'   => 'product_cat',
	'hide_empty' => true,
	'number'     => 3,
	'orderby'    => 'count',
	'order'      => 'DESC',
	'exclude'    => array( get_option( 'default_product_cat' ) ),
) );
?>
<section class="bf-section bf-section--cream">
	<div class="bf-container">
		<div class="bf-section__head reveal">
			<p class="bf-eyebrow"><?php esc_html_e( 'Trouvez votre style', 'boutique-femme' ); ?></p>
			<h2><?php esc_html_e( 'Nos catégories phares', 'boutique-femme' ); ?></h2>
			<p><?php esc_html_e( 'T-shirts, polos, sweats ou accessoires — tout le vestiaire masculin au même endroit.', 'boutique-femme' ); ?></p>
		</div>

		<div class="bf-cats reveal">
			<?php
			if ( ! empty( $cats ) && ! is_wp_error( $cats ) ) :
				$i = 0;
				foreach ( $cats as $cat ) :
					$i++;
					$thumb_id = (int) get_term_meta( $cat->term_id, 'thumbnail_id', true );
					$img_url  = $thumb_id ? wp_get_attachment_image_url( $thumb_id, 'bf-portrait' ) : '';
					?>
					<a class="bf-cat" href="<?php echo esc_url( get_term_link( $cat ) ); ?>">
						<?php if ( $img_url ) : ?>
							<img class="bf-pic" src="<?php echo esc_url( $img_url ); ?>" alt="<?php echo esc_attr( $cat->name ); ?>" loading="lazy">
						<?php else : ?>
							<span class="bf-pic--ph" style="background:<?php echo esc_attr( bf_placeholder_gradient( 'cat-' . $cat->slug ) ); ?>"></span>
						<?php endif; ?>
						<div class="bf-cat__body">
							<h3><?php echo esc_html( $cat->name ); ?></h3>
							<span><?php echo esc_html( sprintf( _n( '%d modèle', '%d modèles', $cat->count, 'boutique-femme' ), $cat->count ) ); ?> <?php echo bf_icon( 'arrow' ); // phpcs:ignore ?></span>
						</div>
					</a>
					<?php
				endforeach;
			else :
				// Aucune catégorie encore : repères visuels par défaut.
				$fallback = array(
					__( 'Taille haute', 'boutique-femme' ),
					__( 'Coupe large', 'boutique-femme' ),
					__( 'Cargo & utilitaire', 'boutique-femme' ),
				);
				foreach ( $fallback as $name ) :
					?>
					<a class="bf-cat" href="<?php echo esc_url( $shop ); ?>">
						<span class="bf-pic--ph" style="background:<?php echo esc_attr( bf_placeholder_gradient( 'cat-' . $name ) ); ?>"></span>
						<div class="bf-cat__body">
							<h3><?php echo esc_html( $name ); ?></h3>
							<span><?php esc_html_e( 'Voir la boutique', 'boutique-femme' ); ?> <?php echo bf_icon( 'arrow' ); // phpcs:ignore ?></span>
						</div>
					</a>
					<?php
				endforeach;
			endif;
			?>
		</div>
	</div>
</section>

<!-- ====================== SÉLECTION PRODUITS ==================== -->
<section class="bf-section bf-section--nude" id="bf-selection">
	<div class="bf-container">
		<div class="bf-section__head reveal">
			<p class="bf-eyebrow"><?php esc_html_e( 'Coups de cœur', 'boutique-femme' ); ?></p>
			<h2><?php esc_html_e( 'Les modèles les plus aimés', 'boutique-femme' ); ?></h2>
			<p><?php esc_html_e( 'Sélectionnés pour leur style et leur confort au quotidien.', 'boutique-femme' ); ?></p>
		</div>
		<div class="reveal">
			<?php
			// Réutilise la boucle Woo (donc nos cartes + bouton « Commander »).
			echo do_shortcode( '[products limit="6" columns="3" orderby="date" visibility="visible"]' );
			?>
		</div>
		<div style="text-align:center;margin-top:2.4rem" class="reveal">
			<a class="bf-btn bf-btn--ghost" href="<?php echo esc_url( $shop ); ?>"><?php esc_html_e( 'Voir toute la collection', 'boutique-femme' ); ?> <?php echo bf_icon( 'arrow' ); // phpcs:ignore ?></a>
		</div>
	</div>
</section>

<!-- ==================== ARGUMENT GRANDE TAILLE ================== -->
<section class="bf-section bf-section--cream">
	<div class="bf-container">
		<div class="bf-split reveal">
			<div class="bf-split__media">
				<?php bf_picture( 'fit', __( 'Style masculin mis en valeur', 'boutique-femme' ), array( 'ratio' => '4 / 5', 'label' => __( 'Photo lifestyle', 'boutique-femme' ) ) ); ?>
			</div>
			<div class="bf-split__body">
				<p class="bf-eyebrow"><?php esc_html_e( 'Conçu pour vous', 'boutique-femme' ); ?></p>
				<h2><?php esc_html_e( 'Une coupe moderne, un confort toute la journée', 'boutique-femme' ); ?></h2>
				<p><?php esc_html_e( 'Des pièces pensées pour l\'homme moderne : matières agréables, coupe nette ni trop ajustée ni trop ample, et des finitions qui durent lavage après lavage.', 'boutique-femme' ); ?></p>
				<ul class="bf-split__list">
					<li><span class="bf-reassure-inline__i"><?php echo bf_icon( 'check' ); // phpcs:ignore ?></span> <?php esc_html_e( 'Tailles S à 3XL, coupe ajustée moderne', 'boutique-femme' ); ?></li>
					<li><span class="bf-reassure-inline__i"><?php echo bf_icon( 'check' ); // phpcs:ignore ?></span> <?php esc_html_e( 'Coton de qualité qui ne se déforme pas', 'boutique-femme' ); ?></li>
					<li><span class="bf-reassure-inline__i"><?php echo bf_icon( 'check' ); // phpcs:ignore ?></span> <?php esc_html_e( 'Guide des tailles clair, échange facile', 'boutique-femme' ); ?></li>
				</ul>
				<a class="bf-btn bf-btn--primary" href="<?php echo esc_url( $shop ); ?>"><?php esc_html_e( 'Trouver ma taille', 'boutique-femme' ); ?></a>
			</div>
		</div>
	</div>
</section>

<!-- ========================= TÉMOIGNAGES ======================= -->
<section class="bf-section bf-section--ink">
	<div class="bf-container">
		<div class="bf-section__head reveal">
			<p class="bf-eyebrow"><?php esc_html_e( 'Ils nous font confiance', 'boutique-femme' ); ?></p>
			<h2><?php esc_html_e( 'Ce que disent nos clients', 'boutique-femme' ); ?></h2>
		</div>
		<div class="bf-testimonials">
			<?php
			$quotes = array(
				array(
					'text'   => __( '« Qualité au top et livraison en 48h, payée à la réception. Le t-shirt taille parfaitement, je recommande. »', 'boutique-femme' ),
					'author' => __( 'Karim', 'boutique-femme' ),
					'city'   => __( 'Alger', 'boutique-femme' ),
				),
				array(
					'text'   => __( '« Le hoodie est exactement comme sur les photos, matière épaisse et coupe nickel. Service rapide. »', 'boutique-femme' ),
					'author' => __( 'Yacine', 'boutique-femme' ),
					'city'   => __( 'Oran', 'boutique-femme' ),
				),
				array(
					'text'   => __( '« Livraison jusqu\'à Constantine, paiement à la réception. Le polo est de qualité, je vais recommander. »', 'boutique-femme' ),
					'author' => __( 'Sofiane', 'boutique-femme' ),
					'city'   => __( 'Constantine', 'boutique-femme' ),
				),
			);
			$d = 0;
			foreach ( $quotes as $q ) :
				$d++;
				?>
				<figure class="bf-quote reveal d<?php echo (int) $d; ?>">
					<div class="bf-quote__stars"><?php echo str_repeat( bf_icon( 'star' ), 5 ); // phpcs:ignore ?></div>
					<blockquote><p><?php echo esc_html( $q['text'] ); ?></p></blockquote>
					<figcaption class="bf-quote__author"><?php echo esc_html( $q['author'] ); ?><span><?php echo esc_html( $q['city'] ); ?></span></figcaption>
				</figure>
			<?php endforeach; ?>
		</div>
	</div>
</section>

<!-- ============================ CTA ============================= -->
<section class="bf-section bf-section--cream">
	<div class="bf-container">
		<div class="bf-cta reveal" style="<?php echo esc_attr( bf_bg_style( 'cta' ) ); ?>">
			<h2><?php esc_html_e( 'Votre nouveau look vous attend', 'boutique-femme' ); ?></h2>
			<p><?php esc_html_e( 'Commandez en 1 minute, sans compte ni carte bancaire. Vous payez à la livraison, partout en Algérie.', 'boutique-femme' ); ?></p>
			<a class="bf-btn bf-btn--light" href="<?php echo esc_url( $shop ); ?>"><?php esc_html_e( 'Je commande maintenant', 'boutique-femme' ); ?> <?php echo bf_icon( 'arrow' ); // phpcs:ignore ?></a>
		</div>
	</div>
</section>

<?php
get_footer();
