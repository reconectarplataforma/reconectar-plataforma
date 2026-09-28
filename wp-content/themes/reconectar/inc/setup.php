<?php
/**
 * Registro dos recursos suportados pelo tema.
 */

defined( 'ABSPATH' ) || exit;

/**
 * Declara o suporte a logotipo personalizado.
 *
 * Roda em prioridade 20 para acontecer depois do `after_setup_theme` do tema
 * pai, garantindo que estas dimensões prevaleçam sobre as dele.
 */
function reconectar_theme_support() {
	add_theme_support(
		'custom-logo',
		array(
			'height'      => 60,
			'width'       => 240,
			'flex-height' => true,
			'flex-width'  => true,
		)
	);
}
add_action( 'after_setup_theme', 'reconectar_theme_support', 20 );

/**
 * Ajusta as cores padrão do Storefront à paleta de texto do projeto.
 *
 * O CSS do tema pai é gerado a partir destes valores e alcança o que o
 * `marketplace.css` não alcança: carrinho, checkout, minha conta e o painel do
 * vendedor, que são markup do WooCommerce e do Dokan. Filtrar os *padrões* —
 * e não gravar `theme_mod` — só funciona porque `theme_mods_reconectar` não tem
 * nenhuma cor salva; no dia em que alguém mexer nas cores pelo Customizer, o
 * valor salvo vence este filtro, como deve ser.
 *
 * Três medidas de contraste sustentam as escolhas abaixo: #717171 dá 4,88:1
 * sobre branco e 4,56:1 sobre #f7f7f8, mas cai para 4,28:1 sobre o #f0f0f0 que
 * o Storefront usa no rodapé — reprovando o mínimo de 4,5:1 da WCAG 2.1. Daí o
 * fundo do rodapé subir para #f7f7f8, que é o mesmo `--rc-fundo` do rodapé
 * autoral.
 *
 * Duas chaves ficam de fora de propósito: `storefront_header_text_color` vale
 * para a barra fixa do celular, que tem fundo próprio não medido, e
 * `storefront_button_text_color` já está em 4,21:1 sobre o #eeeeee do botão —
 * clarear pioraria. Os títulos seguem em #333333 por decisão de projeto: a
 * hierarquia entre título e corpo é justamente o que sobra depois que texto
 * principal e texto de apoio convergem para o mesmo cinza.
 *
 * @param array $padroes Valores padrão das configurações do Storefront.
 * @return array Valores com as cores de texto do projeto.
 */
function reconectar_cores_padrao_storefront( $padroes ) {
	$padroes['storefront_text_color']              = '#717171';
	$padroes['storefront_footer_text_color']       = '#717171';
	$padroes['storefront_footer_background_color'] = '#f7f7f8';

	return $padroes;
}
add_filter( 'storefront_setting_default_values', 'reconectar_cores_padrao_storefront' );
