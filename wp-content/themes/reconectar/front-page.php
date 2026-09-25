<?php
/**
 * Template da home institucional da Reconectar.
 *
 * As seções são impressas por uma action própria do tema, `reconectar_home`,
 * e não pela action `homepage` do Storefront. A diferença importa na troca do
 * tema pai: `homepage` é disparada e povoada pelo Storefront, então a home
 * inteira desapareceria junto com ele. Com hook próprio, cada seção vive em
 * `inc/home/` e se registra sozinha, independente de quem seja o pai.
 *
 * @package reconectar
 */

get_header(); ?>

	<div id="primary" class="content-area">
		<main id="main" class="site-main" role="main">

			<?php
			/**
			 * Funções registradas na action `reconectar_home`.
			 *
			 * A prioridade é a posição na página, e as seis seções ocupam os
			 * degraus de dez em dez: assim uma seção nova entra entre duas
			 * existentes sem renumerar as outras — o que foi preciso fazer
			 * quando a vitrine coube num 45 espremido.
			 *
			 * @hooked reconectar_home_categorias               - 10
			 * @hooked reconectar_home_lojas_destaque           - 20
			 * @hooked reconectar_home_ofertas                  - 30
			 * @hooked reconectar_home_lojas                    - 40
			 * @hooked reconectar_home_hero                     - 50
			 * @hooked reconectar_home_comunidade_transparencia - 60
			 */
			do_action( 'reconectar_home' );
			?>

		</main><!-- #main -->
	</div><!-- #primary -->
<?php
get_footer();
