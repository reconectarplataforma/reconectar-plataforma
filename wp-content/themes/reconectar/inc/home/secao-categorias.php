<?php
/**
 * Seção de categorias de produtos da home.
 *
 * Substitui `storefront_product_categories()`, do tema pai. A troca não é
 * cosmética: aquela função monta a seção com o shortcode `[product_categories]`
 * e envolve tudo em `.storefront-product-section`, ou seja, o markup e as
 * classes da nossa home ficavam sob controle do tema pai. Com a consulta
 * própria aqui, a seção sobrevive à troca do tema pai sem alteração.
 *
 * O formato é um carrossel horizontal: com muitas categorias, uma grade em
 * várias linhas empurra a vitrine de lojas para baixo da dobra, e é a vitrine —
 * não a lista de categorias — que responde ao que o cliente veio fazer aqui.
 *
 * @package reconectar
 */

defined( 'ABSPATH' ) || exit;

/**
 * Renderiza o carrossel de categorias de produtos.
 *
 * Retorna sem imprimir nada quando o WooCommerce está inativo ou quando não há
 * nenhuma categoria com produtos — preferível a exibir um título de seção
 * seguido de espaço em branco.
 */
function reconectar_home_categorias() {
	$categorias = reconectar_obter_categorias( 14 );

	if ( ! $categorias ) {
		return;
	}

	reconectar_abrir_carrossel(
		array(
			'id'     => 'rc-categorias',
			'titulo' => __( 'Explore por categoria', 'reconectar' ),
			'link'   => reconectar_url_loja(),
			'classe' => 'rc-carrossel__faixa--categorias',
		)
	);

	foreach ( $categorias as $categoria ) {
		reconectar_card_categoria( $categoria );
	}

	reconectar_fechar_carrossel();
}
add_action( 'reconectar_home', 'reconectar_home_categorias', 20 );
