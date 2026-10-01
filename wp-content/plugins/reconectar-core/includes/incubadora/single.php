<?php
/**
 * Template da página isolada da Incubadora, servido por `template_include`.
 *
 * Imprime o mesmo `#primary > #main` do `page.php` do Storefront, para que o
 * tema vista a tela como vestiria uma página comum, e chama o renderizador que
 * a âncora também usa. Não chama `get_sidebar()`: a árvore é a coluna lateral,
 * e o tema tira a reserva da sidebar pela classe `rc-tela-incubadora`.
 *
 * Sem o laço do WordPress: a página vem de `get_queried_object()`, e nada aqui
 * dispara `the_post()` — nenhum filtro de terceiro pendurado em `the_content`
 * ou em `loop_start` alcança a tela.
 *
 * @package reconectar-core
 */

defined( 'ABSPATH' ) || exit;

get_header();
?>

	<div id="primary" class="content-area">
		<main id="main" class="site-main">
			<?php
			// O renderizador escapa o que imprime; o retorno é HTML pronto.
			echo Reconectar_Incubadora_Leitura::renderizar( get_queried_object() ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			?>
		</main>
	</div>

<?php
get_footer();
