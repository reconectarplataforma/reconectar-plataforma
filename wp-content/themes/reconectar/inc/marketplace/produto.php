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

/**
 * Omite a descrição curta quando ela só repete o nome do produto.
 *
 * A descrição curta é o campo logo abaixo do título no formulário do Dokan, e
 * loja que acabou de abrir costuma preenchê-lo com o próprio nome. No primeiro
 * teste em produção a tela lia "Pingente" no título e "Pingente" de novo abaixo
 * do preço. O template `single-product/short-description.php` não imprime nada
 * quando o filtro devolve vazio, então o bloco some inteiro, sem `<div>` órfã.
 *
 * Restrito à página do produto porque o filtro é global e o `$product` só é
 * confiável ali. Os cards da vitrine leem o campo direto e tratam a repetição
 * em `reconectar_card_produto_linha()`.
 *
 * @param string $resumo Descrição curta, com HTML.
 * @return string
 */
function reconectar_omitir_resumo_igual_ao_nome( $resumo ) {
	global $product;

	if ( ! is_product() || ! $product instanceof WC_Product ) {
		return $resumo;
	}

	if ( 0 === strcasecmp( trim( wp_strip_all_tags( $resumo ) ), trim( $product->get_name() ) ) ) {
		return '';
	}

	return $resumo;
}
add_filter( 'woocommerce_short_description', 'reconectar_omitir_resumo_igual_ao_nome' );

/**
 * Limita cada foto da galeria à largura do arquivo original.
 *
 * A moldura da galeria (`.woocommerce-product-gallery__image`, em
 * `marketplace.css`) estica a foto até a largura dela, para que a foto boa
 * preencha o quadro. A foto pequena não pode acompanhar: o pingente do primeiro
 * teste em produção foi enviado com 309px, e esticá-lo a 560 o borraria. O CSS
 * não conhece o tamanho do arquivo, e o `width` do `<img>` também não serve —
 * é o do tamanho intermediário servido, que pode ser menor que o original. A
 * largura que vale é a do original, que o WooCommerce já calcula para o zoom.
 *
 * O `sizes` acompanha o teto da moldura: o padrão do núcleo descreve a imagem
 * pelo tamanho intermediário, e o navegador escolheria no `srcset` um arquivo
 * menor que o quadro em que ele vai ser desenhado.
 *
 * @param array $atributos Atributos do `<img>` da galeria.
 * @return array
 */
function reconectar_galeria_sem_ampliar( $atributos ) {
	$largura = isset( $atributos['data-large_image_width'] ) ? absint( $atributos['data-large_image_width'] ) : 0;

	if ( $largura > 0 ) {
		$atributos['style'] = 'max-width: ' . $largura . 'px';
	}

	$atributos['sizes'] = '(max-width: 767px) 100vw, 560px';

	return $atributos;
}
add_filter( 'woocommerce_gallery_image_html_attachment_image_params', 'reconectar_galeria_sem_ampliar' );

/*
 * Leva o selo de oferta para dentro da coluna do resumo, acima do título.
 *
 * O WooCommerce imprime o selo em `woocommerce_before_single_product_summary`,
 * fora das duas colunas, e ele só aparecia sobre o resumo por acaso: como
 * `inline-block` logo depois da galeria flutuante, ele encostava na borda dela,
 * que coincidia com o início da coluna do resumo. Com o teto de largura da
 * galeria (veja `.woocommerce-product-gallery` em `marketplace.css`) a borda
 * recuou, e o selo foi parar no vão entre as duas colunas.
 *
 * Prioridade 4, antes do título (5). O gancho é do WooCommerce, que carrega
 * antes do tema, então o `remove_action` no corpo do arquivo encontra o
 * registro — a armadilha do pai que carrega depois é do Storefront.
 */
remove_action( 'woocommerce_before_single_product_summary', 'woocommerce_show_product_sale_flash', 10 );
add_action( 'woocommerce_single_product_summary', 'woocommerce_show_product_sale_flash', 4 );
