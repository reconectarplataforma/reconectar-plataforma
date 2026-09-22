<?php
/**
 * Funções utilitárias compartilhadas pelo tema.
 */

defined( 'ABSPATH' ) || exit;

/**
 * Versão de um asset do tema, derivada da data de modificação do arquivo.
 *
 * Serve para invalidar o cache do navegador a cada edição, sem precisar bumpar
 * a versão do tema manualmente. A guarda `file_exists()` não é zelo excessivo:
 * `filemtime()` em caminho inexistente emite um warning do PHP e devolve
 * `false`, o que produziria uma tag `<link>` com `?ver=` vazio e sujaria o log
 * em todas as requisições. Sem o arquivo, cai para a versão declarada no
 * cabeçalho do `style.css`.
 *
 * @param string $caminho_relativo Caminho a partir da raiz do tema filho.
 * @return string Versão a ser usada no enqueue.
 */
function reconectar_versao_asset( $caminho_relativo ) {
	$caminho = get_stylesheet_directory() . '/' . ltrim( $caminho_relativo, '/' );

	if ( file_exists( $caminho ) ) {
		return (string) filemtime( $caminho );
	}

	return (string) wp_get_theme()->get( 'Version' );
}

/**
 * URL da página da loja, com fallback para a home.
 *
 * `wc_get_page_permalink()` é declarada pelo WooCommerce. Chamá-la sem guarda
 * derruba a home com erro fatal caso o plugin seja desativado — cenário real
 * durante manutenção ou conflito de atualização, em que a home deveria
 * continuar de pé.
 *
 * @return string
 */
function reconectar_url_loja() {
	if ( function_exists( 'wc_get_page_permalink' ) ) {
		$url = wc_get_page_permalink( 'shop' );

		if ( $url ) {
			return $url;
		}
	}

	return home_url( '/' );
}
