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

/**
 * URL da vitrine de lojas, com fallback para a página da loja.
 *
 * O destino é a página que o Dokan registra como `store_listing` — a mesma que
 * o breadcrumb do plugin usa no caminho de cada loja. Consultá-la pela opção, e
 * não pelo slug, importa porque a página é criada com nome em inglês
 * (`store-listing`) e pode ser renomeada no painel: um caminho chumbado
 * sobreviveria à renomeação apontando para o 404.
 *
 * A guarda `function_exists()` segue o mesmo motivo de `reconectar_url_loja()`:
 * chamar a API do Dokan sem ela derruba a home com erro fatal caso o plugin
 * seja desativado, e é justamente aí que a home precisa continuar de pé.
 *
 * @return string
 */
function reconectar_url_das_lojas() {
	if ( function_exists( 'dokan_get_page_url' ) ) {
		$url = dokan_get_page_url( 'store_listing' );

		if ( $url ) {
			return $url;
		}
	}

	return reconectar_url_loja();
}

/**
 * URL da listagem de categorias, com fallback para a página da loja.
 *
 * A página é criada pelo `provision.sh` com o slug `categorias` e o shortcode
 * `[reconectar_categorias]`. A busca é por slug, e não por título, porque o
 * título é do administrador: renomear a página no painel não pode quebrar o
 * "Ver todos" do carrossel da home.
 *
 * O fallback existe para a instalação em que a página ainda não foi criada — um
 * `home_url( '/categorias/' )` chumbado levaria ao 404, que é pior do que
 * chegar ao catálogo. Continua sendo fallback, não destino: o link certo só
 * aparece quando a página existe.
 *
 * @return string
 */
function reconectar_url_das_categorias() {
	$pagina = get_page_by_path( 'categorias' );

	if ( $pagina && 'publish' === $pagina->post_status ) {
		return get_permalink( $pagina );
	}

	return reconectar_url_loja();
}
