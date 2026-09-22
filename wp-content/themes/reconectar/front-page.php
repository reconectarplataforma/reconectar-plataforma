<?php
/**
 * Template da home institucional da Reconectar.
 *
 * Reaproveita a action `homepage` nativa do Storefront (ver
 * template-homepage.php do tema pai), com os hooks reordenados em
 * functions.php: hero institucional, categorias de produtos e chamada
 * para Comunidade/Transparência.
 *
 * @package reconectar
 */

get_header(); ?>

	<div id="primary" class="content-area">
		<main id="main" class="site-main" role="main">

			<?php
			/**
			 * Functions hooked in to homepage action
			 *
			 * @hooked reconectar_homepage_hero                     - 5
			 * @hooked storefront_product_categories                - 20
			 * @hooked reconectar_homepage_comunidade_transparencia - 25
			 */
			do_action( 'homepage' );
			?>

		</main><!-- #main -->
	</div><!-- #primary -->
<?php
get_footer();
