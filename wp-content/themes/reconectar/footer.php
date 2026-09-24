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

<?php wp_footer(); ?>

</body>
</html>
