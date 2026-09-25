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
 *
 * As prioridades dos três ganchos abaixo não são arbitrárias — elas se
 * encaixam nas do tema pai, que enfileira em 10 (`Storefront::scripts()`) e em
 * 30 (`Storefront::child_scripts()`, "After WooCommerce"):
 *
 *   5  → Bootstrap, para o tema pai poder sobrescrevê-lo.
 *   40 → estilos autorais, depois de tudo: tema pai, WooCommerce e Dokan.
 *
 * Uma versão anterior deste arquivo enfileirava tudo em 10, e o resultado era
 * o mesmo arquivo baixado duas vezes sob handles diferentes — ver
 * `reconectar_remover_estilos_duplicados()`.
 */

defined( 'ABSPATH' ) || exit;

/**
 * Enfileira o Bootstrap antes do tema pai.
 *
 * Vai sozinho na prioridade 5 porque é a única folha que precisa vir *antes*
 * do Storefront: ele é a base sobre a qual o tema pai e o filho escrevem. Todo
 * o resto vai na prioridade 40, depois de tudo.
 */
function reconectar_enqueue_base() {
	wp_enqueue_style(
		'reconectar-bootstrap',
		get_stylesheet_directory_uri() . '/assets/bootstrap/bootstrap.min.css',
		array(),
		'5.3.3'
	);
}
add_action( 'wp_enqueue_scripts', 'reconectar_enqueue_base', 5 );

/**
 * Enfileira os estilos e scripts autorais, por último na cascata.
 *
 * A prioridade 40 coloca estas folhas depois de `storefront-woocommerce-style`
 * e do CSS do Dokan, que antes venciam o `marketplace.css` em qualquer empate
 * de especificidade — o tema filho carregava na prioridade 10 e portanto saía
 * *antes* deles no `<head>`, ao contrário do que a ordem das dependências
 * sugeria ler.
 *
 * O `style.css` do tema filho é enfileirado aqui, e não deixado a cargo do
 * `Storefront::child_scripts()`, por dois motivos: nem todo tema pai enfileira
 * o estilo do filho (depender disso deixaria o tema sem estilo próprio numa
 * troca de pai), e o handle precisa existir para o `marketplace.css` declarar
 * dependência dele.
 */
function reconectar_enqueue_assets() {
	$theme_uri = get_stylesheet_directory_uri();

	wp_enqueue_style(
		'reconectar-fonts',
		$theme_uri . '/assets/css/fonts.css',
		array(),
		reconectar_versao_asset( 'assets/css/fonts.css' )
	);

	wp_enqueue_style(
		'reconectar-style',
		get_stylesheet_uri(),
		array( 'reconectar-fonts' ),
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
add_action( 'wp_enqueue_scripts', 'reconectar_enqueue_assets', 40 );

/**
 * Remove folhas que o tema pai enfileira em duplicidade ou de fora do servidor.
 *
 * `storefront-child-style` aponta para o mesmo `get_stylesheet_uri()` que
 * `reconectar-style`: com os dois no ar, o `style.css` do tema filho descia
 * duas vezes por página, e a cópia do pai — enfileirada na prioridade 30 —
 * saía depois do `marketplace.css`, invertendo a cascata que este arquivo
 * declara. Quem sai é a do pai, porque é a que não controlamos.
 *
 * `storefront-fonts` é a Source Sans Pro servida pelo Google. Ela sai por três
 * razões: o projeto se comprometeu a autohospedar tudo (sem CDN), uma
 * requisição a `fonts.googleapis.com` entrega o IP de cada visitante a um
 * terceiro sem base legal declarada, e a fonte sequer é a do projeto — a
 * identidade visual é Poppins. Enquanto o link existia, os únicos elementos
 * que a usavam eram campos e botões, por causa do seletor
 * `body, button, input, textarea` do tema pai contra o `body` do tema filho:
 * o site tinha uma fonte na interface e outra nos formulários. O `style.css`
 * do tema filho passou a cobrir os mesmos elementos.
 *
 * Não se desenfileira `storefront-style`, embora o tema filho já carregue o
 * mesmo arquivo no `reconectar-style`: o Customizer pendura o CSS das cores
 * nele com `wp_add_inline_style()`, e `storefront-woocommerce-style` o declara
 * como dependência. Removê-lo apagaria as duas coisas em silêncio.
 */
function reconectar_remover_estilos_duplicados() {
	wp_dequeue_style( 'storefront-child-style' );

	wp_dequeue_style( 'storefront-fonts' );
	wp_deregister_style( 'storefront-fonts' );
}
// Prioridade 40 para rodar depois do `child_scripts()` do tema pai, que se
// registra em 30: só dá para desenfileirar o que já foi enfileirado.
add_action( 'wp_enqueue_scripts', 'reconectar_remover_estilos_duplicados', 40 );
