<?php
/**
 * Faixa de lojas em destaque da home.
 *
 * Equivale à fileira de marcas do layout de referência, com uma diferença que
 * importa: não há curadoria editorial nem espaço publicitário pago. As lojas
 * que aparecem aqui são as mais bem avaliadas pelos próprios clientes, o que
 * torna o destaque uma consequência do uso da plataforma e não uma decisão
 * comercial de quem a opera.
 *
 * @package reconectar
 */

defined( 'ABSPATH' ) || exit;

/**
 * Renderiza a faixa de lojas em destaque.
 *
 * A seção só é impressa a partir de quatro lojas. Abaixo disso, a vitrine
 * completa logo adiante já mostra todas elas, e o destaque viraria uma
 * repetição do que o cliente verá dois blocos depois.
 */
function reconectar_home_lojas_destaque() {
	$lojas = reconectar_obter_lojas(
		array(
			'numero'  => 8,
			'ordenar' => 'avaliacao',
		)
	);

	if ( count( $lojas ) < 4 ) {
		return;
	}

	reconectar_abrir_carrossel(
		array(
			'id'     => 'rc-lojas-destaque',
			'titulo' => __( 'Lojas em destaque', 'reconectar' ),
			// `reconectar_url_base_da_vitrine()` estava aqui e devolvia a própria
			// home: o "Ver todos" existia, era clicável e recarregava a mesma
			// página. O destino certo é a listagem completa de lojas.
			'link'   => reconectar_url_das_lojas(),
			'classe' => 'rc-carrossel__faixa--destaque',
		)
	);

	foreach ( $lojas as $loja ) {
		reconectar_card_loja_banner( $loja );
	}

	reconectar_fechar_carrossel();
}
add_action( 'reconectar_home', 'reconectar_home_lojas_destaque', 30 );
