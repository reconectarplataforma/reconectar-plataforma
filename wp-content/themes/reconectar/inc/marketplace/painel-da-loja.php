<?php
/**
 * Moldura do painel da loja em volta do Fórum, da Comunidade e da Incubadora.
 *
 * Para quem tem papel `seller`, as três áreas são ferramentas de trabalho e abrem
 * como se fossem abas do painel do Dokan: a barra lateral dele à esquerda, o
 * conteúdo à direita, e nada do cabeçalho, do menu, do rodapé e da barra
 * inferior da vitrine. Quem decide se a tela corrente é uma delas é o plugin
 * (`Reconectar_Navegacao_Da_Loja::area_corrente()`); este arquivo só desenha.
 *
 * A moldura é a casca de `header.php` e `footer.php`, e não um template à parte,
 * porque os três conteúdos vêm de lugares diferentes — o bbPress pelo `page.php`
 * do Storefront, o BuddyPress pela página `comunidade`, a Incubadora pelo
 * `single.php` dela — e todos passam por `get_header()` e `get_footer()`.
 * Trocar a casca alcança os três sem tocar em nenhum.
 *
 * O markup copia o que o Dokan imprime no painel de verdade
 * (`templates/dashboard/fullwidth-dashboard.php` mais o `[dokan-dashboard]`):
 * `#dokan-dashboard-fullwidth-wrapper` → `#dokan-vendor-dashboard-layout-root`,
 * onde o React monta a barra lateral e a do topo → `.dokan-dashboard-wrap` → a
 * barra lateral clássica, em `dokan_dashboard_content_before` →
 * `.dokan-dashboard-content`. O React procura este último exatamente por
 * `#dokan-dashboard-fullwidth-wrapper .dokan-dashboard-content` e o puxa para
 * dentro do layout dele: trocar um id ou uma classe deixa a tela em branco.
 * Sem o layout React (Dokan no modo `legacy`), a barra clássica é que aparece.
 *
 * @package reconectar
 */

defined( 'ABSPATH' ) || exit;

/**
 * A tela corrente abre na moldura do painel da loja?
 *
 * Consulta o plugin por `function_exists()`, porque o tema não pode exigi-lo.
 * `header.php` e `footer.php` perguntam cada um por si, e a resposta é a mesma
 * nas duas pontas: depende só da consulta principal e do usuário, que não mudam
 * no meio do template.
 *
 * @return bool
 */
function reconectar_tela_e_do_painel_da_loja() {
	return function_exists( 'reconectar_tela_no_painel_da_loja' ) && reconectar_tela_no_painel_da_loja();
}

/**
 * Abre a moldura, no lugar do cabeçalho da vitrine.
 *
 * O `id="content"` fica no contêiner do conteúdo para que o "Pular para o
 * conteúdo" funcione: a barra lateral do Dokan tem mais de dez links, e quem
 * navega por teclado os atravessaria a cada tela aberta.
 *
 * `storefront_before_content` não é disparada: é nela que mora a trilha do
 * WooCommerce ("Início / Tópico / …"), que o painel não tem. A
 * `storefront_content_top` é, porque é nela que saem os avisos do WooCommerce.
 *
 * @return void
 */
function reconectar_painel_da_loja_abrir() {
	?>
	<a class="skip-link screen-reader-text" href="#content">
		<?php esc_html_e( 'Pular para o conteúdo', 'reconectar' ); ?>
	</a>

	<div id="dokan-dashboard-fullwidth-wrapper" class="dokan-fullwidth-container">
		<div id="dokan-vendor-dashboard-layout-root" class="dokan-layout"></div>
		<?php do_action( 'dokan_dashboard_wrap_start' ); ?>

		<div class="dokan-dashboard-wrap">
			<?php do_action( 'dokan_dashboard_content_before' ); ?>

			<div id="content" class="dokan-dashboard-content rc-painel-da-loja" tabindex="-1">
				<?php do_action( 'storefront_content_top' ); ?>
	<?php
}

/**
 * Fecha a moldura, no lugar do rodapé e da barra inferior da vitrine.
 *
 * @return void
 */
function reconectar_painel_da_loja_fechar() {
	?>
			</div><!-- .dokan-dashboard-content -->

			<?php do_action( 'dokan_dashboard_content_after' ); ?>
		</div><!-- .dokan-dashboard-wrap -->

		<?php do_action( 'dokan_dashboard_wrap_end' ); ?>
	</div><!-- #dokan-dashboard-fullwidth-wrapper -->
	<?php
}
