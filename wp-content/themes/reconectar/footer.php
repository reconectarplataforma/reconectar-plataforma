<?php
/**
 * Rodapé do site.
 *
 * Fecha os contêineres abertos em `header.php` na mesma ordem em que o tema pai
 * os fecharia. A action `storefront_footer` não é disparada — é onde o
 * Storefront imprime os próprios widgets e o crédito "Built with Storefront &
 * WooCommerce", que aqui viraria um segundo rodapé. As colunas e a linha legal
 * vêm de `inc/marketplace/rodape.php`, com áreas de widget registradas pelo
 * próprio tema.
 *
 * @package reconectar
 */

?>

<?php
// A moldura do painel da loja não tem rodapé nem barra inferior: o painel do
// Dokan não tem, e a navegação dela é a barra lateral.
if ( reconectar_tela_e_do_painel_da_loja() ) :
	reconectar_painel_da_loja_fechar();
else :
	?>

		</div><!-- .col-full -->
	</div><!-- #content -->

	<?php do_action( 'storefront_before_footer' ); ?>

	<footer id="colophon" class="site-footer rc-rodape" role="contentinfo">
		<div class="col-full">
			<?php
			reconectar_rodape_colunas();
			reconectar_rodape_legal();
			?>
		</div><!-- .col-full -->
	</footer><!-- #colophon -->

	<?php do_action( 'storefront_after_footer' ); ?>

</div><!-- #page -->

<?php
/*
 * Fora de `#page`, e não dentro dele junto do rodapé, porque o Storefront
 * declara `.site { overflow-x: hidden }` — e `overflow-x` definido sozinho faz
 * o `overflow-y` computar `auto`, o que transforma o `<div class="hfeed site">`
 * num scroll container. A propagação que salva o `<body>` (o overflow dele sobe
 * para o viewport enquanto o `<html>` for `visible`) não vale para um `<div>`
 * comum, e `position: fixed` dentro de scroll container é terreno onde
 * navegador de celular diverge da especificação.
 *
 * Registre-se que **não era isto** que fazia a barra sumir na home: a causa
 * medida era a rolagem horizontal do documento, corrigida em
 * `.rc-carrossel__faixa` (veja o comentário lá). Esta chamada ficou aqui mesmo
 * assim por ser a posição defensável de um componente fixo, e porque a única
 * coisa que o lugar no DOM decide é a ordem de tabulação: quem usa teclado
 * percorre o conteúdo inteiro antes de chegar aos quatro atalhos, em vez de
 * esbarrar neles logo depois do cabeçalho.
 */
reconectar_barra_inferior();
endif;
?>

<?php wp_footer(); ?>

</body>
</html>
