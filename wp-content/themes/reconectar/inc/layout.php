<?php
/**
 * Ajustes de layout que corrigem decisões do tema pai.
 *
 * @package reconectar
 */

defined( 'ABSPATH' ) || exit;

/**
 * Remove a coluna reservada à sidebar nos templates que não imprimem sidebar.
 *
 * O Storefront decide a largura do conteúdo pela classe do `<body>`: com
 * `right-sidebar`, `.content-area` fica em 73,9% e flutua à esquerda; só com
 * `storefront-full-width-content` ele ocupa a linha inteira. A escolha entre as
 * duas é feita em `Storefront::body_classes()` por um critério único — se a
 * `sidebar-1` tem widget —, e esse critério nunca pergunta se o template em uso
 * chega a chamar `get_sidebar()`.
 *
 * A home não chama: `front-page.php` imprime apenas `#primary`, e `#secondary`
 * não existe no DOM. Enquanto a `sidebar-1` teve widgets, o Storefront reservava
 * 26% da largura para uma coluna que nunca é renderizada — o conteúdo travava em
 * 786px mesmo em 1920px, deixando quase 700px de vazio à direita, e o
 * `--rc-largura` de 1200px nunca era alcançado.
 *
 * Hoje a `sidebar-1` está vazia: o provisionamento remove os cinco widgets que o
 * WordPress instala de fábrica, e com isso o próprio Storefront já entrega a
 * largura inteira. Este filtro deixou de ser o que corrige a home e passou a ser
 * o que a protege — basta um widget novo na `sidebar-1`, por qualquer caminho do
 * painel, para a home voltar a perder 26% sem que ninguém relacione as duas
 * coisas. É uma garantia barata para uma regressão silenciosa; não o remova por
 * parecer redundante.
 *
 * O ajuste é restrito à home de propósito. Os demais templates vêm do tema pai
 * e chamam `get_sidebar()` normalmente; lá a reserva de espaço está correta, e
 * removê-la deixaria a sidebar real sem lugar. Ao acrescentar um template
 * próprio que também dispense a sidebar, inclua-o na condição abaixo.
 *
 * @param string[] $classes Classes do elemento `<body>`.
 * @return string[] Classes ajustadas.
 */
function reconectar_ajustar_classes_de_layout( $classes ) {
	// O painel de empresas é o segundo caso previsto no parágrafo acima: ele
	// imprime tabelas largas dentro do conteúdo da página e não chama
	// `get_sidebar()`. `class_exists()` porque o tema não pode exigir o plugin —
	// o inverso é que vale, e o plugin segue de pé sem este tema.
	$painel_de_empresas = class_exists( 'Reconectar_Painel_Empresas' )
		&& Reconectar_Painel_Empresas::eh_a_pagina();

	// A página de loja é o terceiro caso. O override em `dokan/store.php` não
	// chama a `store-sidebar` do Dokan — endereço, telefone e contato passaram
	// para o painel "Ver mais" —, mas o `<body>` continuava recebendo
	// `right-sidebar` do tema pai e reservando 26% da largura para uma coluna
	// que não existe no DOM. `function_exists()` porque o tema não pode exigir o
	// Dokan: sem o plugin, esta página sequer é roteada.
	$pagina_de_loja = function_exists( 'dokan_is_store_page' ) && dokan_is_store_page();

	// A listagem de lojas é o quarto caso. O conteúdo dela é o shortcode
	// `[dokan-stores]`, cuja saída o tema substitui pela vitrine própria (veja
	// `inc/marketplace/vitrine.php`): uma grade de cards que quer a linha inteira,
	// numa página que também não chama `get_sidebar()`.
	$listagem_de_lojas = function_exists( 'dokan_is_store_listing' ) && dokan_is_store_listing();

	// O fórum é o quinto caso. Ele monta as próprias três colunas — atividade,
	// perguntas e blocos laterais — dentro do conteúdo, e uma sexta coluna vinda
	// do tema pai comprimiria a do meio, que é a que importa. `is_post_type_archive`
	// não cobre tudo: a pergunta única e a categoria também são do fórum.
	$e_do_forum = is_post_type_archive( array( 'forum', 'topic' ) )
		|| is_singular( array( 'forum', 'topic', 'reply' ) )
		|| is_tax( 'topic-tag' );

	if ( ! is_front_page() && ! $painel_de_empresas && ! $pagina_de_loja && ! $listagem_de_lojas && ! $e_do_forum ) {
		return $classes;
	}

	// `array_values()` porque `array_diff()` preserva as chaves originais, e o
	// `body_class()` do WordPress imprime o array como veio.
	$classes = array_values( array_diff( $classes, array( 'right-sidebar', 'left-sidebar' ) ) );

	if ( ! in_array( 'storefront-full-width-content', $classes, true ) ) {
		$classes[] = 'storefront-full-width-content';
	}

	return $classes;
}
// Prioridade 20 para rodar depois de `Storefront::body_classes()`, que se
// registra na prioridade padrão: só dá para remover uma classe depois que ela
// foi adicionada.
add_filter( 'body_class', 'reconectar_ajustar_classes_de_layout', 20 );

/**
 * Tira o título e a trilha do tema pai na tela de uma pergunta.
 *
 * A tela nascia com dois de cada. O `<h1 class="entry-title">` de
 * `storefront_page_header()` imprimia "Teste de pergunta", e o card da pergunta
 * imprime o mesmo texto no seu próprio `<h1>` — dois cabeçalhos de primeiro
 * nível idênticos, um atrás do outro, que quem navega por cabeçalhos ouve duas
 * vezes sem ter como saber que são a mesma coisa. Sai o do tema pai, porque o do
 * card é o que carrega a pergunta junto de autor, data e votos.
 *
 * A trilha é o mesmo caso com uma diferença: as duas dizem coisas diferentes. A
 * do WooCommerce entrega "Início / Tópico / Teste de pergunta", em que "Tópico"
 * é o nome do post type e leva a um arquivo que a plataforma não usa; a do
 * bbPress entrega "Início › Fóruns › Fórum Geral › Teste de pergunta", com a
 * categoria em que a pergunta foi feita — que num Q&A é justamente o caminho de
 * volta que interessa. Fica a segunda.
 *
 * Restrito ao singular de propósito: a listagem e a categoria não imprimem a
 * trilha do bbPress, e removê-la lá as deixaria sem nenhuma.
 *
 * @return void
 */
function reconectar_ajustar_cabecalho_da_pergunta() {
	if ( ! is_singular( array( 'topic', 'reply' ) ) ) {
		return;
	}

	remove_action( 'storefront_page', 'storefront_page_header', 10 );
	remove_action( 'storefront_before_content', 'woocommerce_breadcrumb', 10 );
}
// `wp` é o primeiro gancho em que as condicionais já respondem, e ainda falta
// disparar tanto `storefront_before_content` quanto `storefront_page`.
add_action( 'wp', 'reconectar_ajustar_cabecalho_da_pergunta' );

/**
 * Tira o título da página na tela de acesso.
 *
 * `storefront_page_header()` imprime `<h1 class="entry-title">` com o título do
 * post — "My account", em inglês, vindo do WooCommerce. Na tela de acesso isso
 * produzia dois `<h1>` na mesma página, o segundo sendo o "Acessar a plataforma"
 * do painel, que é o título verdadeiro do documento. Dois `<h1>` não são só
 * redundância visual: quem navega por cabeçalhos ouve os dois e precisa decidir
 * qual dos dois nomeia a página.
 *
 * Quem sai é o do tema pai, porque o do painel é o que descreve a tarefa. A
 * trilha de navegação fica: ela é o que continua situando a página.
 *
 * Restrito a quem ainda não entrou — `is_account_page()` responde verdadeiro
 * também no painel do cliente, onde "Minha conta" é o único título e precisa
 * continuar de pé.
 */
function reconectar_remover_titulo_da_tela_de_acesso() {
	if ( ! function_exists( 'is_account_page' ) || ! is_account_page() || is_user_logged_in() ) {
		return;
	}

	remove_action( 'storefront_page', 'storefront_page_header', 10 );
}
// `wp` é o primeiro gancho em que as condicionais já respondem e ainda falta
// disparar `storefront_page`, que só acontece dentro do template.
add_action( 'wp', 'reconectar_remover_titulo_da_tela_de_acesso' );
