<?php
/**
 * Enfileiramento de estilos e scripts do tema.
 *
 * O JavaScript do Bootstrap não é carregado: ele existia apenas por causa da
 * navbar herdada do Storefront, substituída pelo cabeçalho autoral. Nenhum
 * componente do tema usa `data-bs-` hoje — o menu colapsa com `<details>` e os
 * carrosséis têm script próprio, bem menor que os ~80 KB do bundle.
 *
 * O CSS do Bootstrap permanece: grade, utilitários e tipografia dele são usados
 * em todo o projeto, inclusive pelas telas do plugin `reconectar-core`.
 */

defined( 'ABSPATH' ) || exit;

/**
 * Enfileira a cadeia de estilos do tema.
 *
 * A ordem das dependências define a ordem no `<head>`, e é ela que decide quem
 * vence em caso de empate de especificidade: Bootstrap → tema pai → fontes →
 * estilos do tema filho (por último, para poder sobrescrever todos os anteriores).
 */
function reconectar_enqueue_assets() {
	$theme_uri = get_stylesheet_directory_uri();

	wp_enqueue_style(
		'reconectar-bootstrap',
		$theme_uri . '/assets/bootstrap/bootstrap.min.css',
		array(),
		'5.3.3'
	);

	/*
	 * Estilo do tema pai. O handle carrega o nome do pai atual (Storefront) e,
	 * junto com a dependência declarada em `reconectar-style` mais abaixo,
	 * precisa ser revisto quando o tema pai for trocado — um handle inexistente
	 * faz o WordPress descartar o estilo dependente em silêncio, sem erro e sem
	 * entrada no log.
	 */
	wp_enqueue_style(
		'storefront-parent-style',
		get_template_directory_uri() . '/style.css',
		array( 'reconectar-bootstrap' )
	);

	wp_enqueue_style(
		'reconectar-fonts',
		$theme_uri . '/assets/css/fonts.css',
		array(),
		reconectar_versao_asset( 'assets/css/fonts.css' )
	);

	/*
	 * O `style.css` do tema filho é enfileirado explicitamente, via
	 * `get_stylesheet_uri()`, em vez de se confiar no tema pai para carregá-lo:
	 * nem todo tema pai faz isso, e depender desse comportamento deixaria o
	 * tema sem nenhum estilo próprio ao trocar de pai.
	 */
	wp_enqueue_style(
		'reconectar-style',
		get_stylesheet_uri(),
		array( 'storefront-parent-style', 'reconectar-fonts' ),
		reconectar_versao_asset( 'style.css' )
	);

	/*
	 * Estilos do marketplace por último, depois de `reconectar-style`: as regras
	 * de cabeçalho, carrossel e vitrine precisam vencer tanto o Bootstrap quanto
	 * o que o Storefront define para os mesmos seletores estruturais.
	 */
	wp_enqueue_style(
		'reconectar-marketplace',
		$theme_uri . '/assets/css/marketplace.css',
		array( 'reconectar-style' ),
		reconectar_versao_asset( 'assets/css/marketplace.css' )
	);

	/*
	 * O script dos carrosséis é um progressive enhancement: sem ele a faixa
	 * continua rolável no dedo, no trackpad e pelo teclado. Por isso vai no
	 * rodapé (`true`) e sem nenhuma dependência.
	 */
	wp_enqueue_script(
		'reconectar-marketplace',
		$theme_uri . '/assets/js/marketplace.js',
		array(),
		reconectar_versao_asset( 'assets/js/marketplace.js' ),
		true
	);
}
add_action( 'wp_enqueue_scripts', 'reconectar_enqueue_assets' );
