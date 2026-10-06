<?php
/**
 * Página de detalhe de produto: a loja que vende.
 *
 * Num marketplace multi-loja, o comprador precisa saber de quem está
 * comprando antes de pôr no carrinho — o pedido se divide por loja, o meio de
 * pagamento é de cada loja, e a entrega também. O WooCommerce não tem esse
 * conceito, e o Dokan só imprime a informação com `dokan_general.show_vendor_info`
 * ligado (padrão `off`), em `woocommerce_product_meta_end`: no rodapé do resumo,
 * abaixo do botão de compra, e com o vocabulário "Vendor" que aqui é "Loja".
 *
 * Por isso o bloco é autoral e não a opção do Dokan: fica logo abaixo do título,
 * que é onde o olho está quando a pergunta surge, e reaproveita a loja
 * normalizada da vitrine — o mesmo nome, a mesma nota e o mesmo link dos cards.
 *
 * @package reconectar
 */

defined( 'ABSPATH' ) || exit;

/**
 * Imprime "Vendido por <Loja>" no resumo do produto.
 *
 * Prioridade 6: logo depois do título (5) e antes da nota e do preço (10). O
 * gancho é do WooCommerce, não do Storefront, e por isso pode ser registrado no
 * corpo do arquivo — a armadilha do `functions.php` do filho carregar antes do
 * pai vale para `remove_action`, não para acrescentar.
 *
 * Produto sem loja identificável — autor sem `store_name`, ou Dokan ausente —
 * não imprime nada: um "Vendido por" vazio seria pior que a ausência.
 *
 * O link é o bloco inteiro — logo, nome, nota e cidade —, para que o alvo de
 * toque seja o cartão e não só a palavra; o nome acessível sai "Vendido por
 * <Loja>, Nota 4,9 de 5 · <cidade>", que é o que se quer ouvir antes de entrar.
 * O logo é decorativo (`alt=""`), porque o nome está ao lado em texto.
 *
 * @return void
 */
function reconectar_produto_loja_vendedora() {
	global $product;

	if ( ! $product instanceof WC_Product ) {
		return;
	}

	$loja = reconectar_normalizar_loja( get_post_field( 'post_author', $product->get_id() ) );

	if ( ! $loja || empty( $loja['url'] ) ) {
		return;
	}
	?>
	<div class="rc-produto-loja">
		<a class="rc-produto-loja__link" href="<?php echo esc_url( $loja['url'] ); ?>">
			<span class="rc-produto-loja__logo" aria-hidden="true">
				<?php if ( ! empty( $loja['logo_id'] ) ) : ?>
					<?php
					echo wp_get_attachment_image(
						(int) $loja['logo_id'],
						'thumbnail',
						false,
						array(
							'alt'     => '',
							'loading' => 'lazy',
						)
					);
					?>
				<?php else : ?>
					<span class="rc-produto-loja__inicial">
						<?php echo esc_html( mb_substr( $loja['nome'], 0, 1 ) ); ?>
					</span>
				<?php endif; ?>
			</span>

			<span class="rc-produto-loja__conteudo">
				<span class="rc-produto-loja__rotulo"><?php esc_html_e( 'Vendido por', 'reconectar' ); ?></span>
				<span class="rc-produto-loja__nome"><?php echo esc_html( $loja['nome'] ); ?></span>

				<span class="rc-produto-loja__meta">
					<?php reconectar_nota_em_estrela( $loja['nota'] ); ?>

					<?php if ( ! empty( $loja['cidade'] ) ) : ?>
						<span class="rc-card-loja__separador" aria-hidden="true">&middot;</span>
						<span><?php echo esc_html( $loja['cidade'] ); ?></span>
					<?php endif; ?>
				</span>
			</span>
		</a>
	</div>
	<?php
}
add_action( 'woocommerce_single_product_summary', 'reconectar_produto_loja_vendedora', 6 );
