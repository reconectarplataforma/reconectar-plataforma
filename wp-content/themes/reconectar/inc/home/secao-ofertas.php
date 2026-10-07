<?php
/**
 * Faixa de produtos em destaque da home.
 *
 * Ocupa, no layout de referência, o lugar dos banners promocionais. A troca é
 * deliberada: banner é peça publicitária, exige arte por campanha e envelhece
 * sem avisar. Uma faixa de produtos reais se mantém sozinha, leva direto à
 * página de compra e dá visibilidade ao catálogo dos vendedores, que é o que a
 * home precisa fazer.
 *
 * @package reconectar
 */

defined( 'ABSPATH' ) || exit;

/**
 * Renderiza a faixa de produtos em destaque.
 *
 * Segue o filtro de município da home. Sem produto no município escolhido a
 * faixa não sai, como já não saía sem produto nenhum: completar com produtos de
 * outra cidade encheria a tela desmentindo o filtro que está logo acima dela.
 *
 * O "Ver todos" segue para o catálogo inteiro, sem o município: o catálogo do
 * WooCommerce não lê `?cidade=`, e levar o parâmetro até lá faria parecer
 * filtrada uma lista que não é.
 */
function reconectar_home_ofertas() {
	$ativos   = reconectar_filtros_ativos();
	$produtos = reconectar_obter_produtos_destaque( 10, $ativos['cidade'] );

	if ( ! $produtos ) {
		return;
	}

	reconectar_abrir_carrossel(
		array(
			'id'     => 'rc-ofertas',
			'titulo' => __( 'Produtos em destaque', 'reconectar' ),
			'link'   => reconectar_url_loja(),
			'classe' => 'rc-carrossel__faixa--produtos',
		)
	);

	foreach ( $produtos as $produto ) {
		reconectar_card_produto( $produto );
	}

	reconectar_fechar_carrossel();
}
add_action( 'reconectar_home', 'reconectar_home_ofertas', 30 );
