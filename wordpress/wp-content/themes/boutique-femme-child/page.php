<?php
/**
 * Gabarit de page générique (pages statiques simples).
 *
 * @package BoutiqueFemme
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

get_header();
?>
<section class="bf-section bf-section--cream">
	<div class="bf-container" style="max-width:820px">
		<?php
		while ( have_posts() ) :
			the_post();
			?>
			<header class="bf-section__head reveal" style="text-align:start">
				<h1 class="bf-shop-title"><?php the_title(); ?></h1>
			</header>
			<div class="bf-page-content reveal"><?php the_content(); ?></div>
			<?php
		endwhile;
		?>
	</div>
</section>
<?php
get_footer();
