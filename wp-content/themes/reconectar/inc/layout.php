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

	// A Incubadora é o sexto caso: ela imprime a própria coluna lateral — a
	// árvore de páginas — e não chama `get_sidebar()`. A pergunta é feita ao
	// plugin, que é quem sabe o que é da Incubadora. Dois `if` e não um `||`
	// com `class_exists()`: com a classe ainda não carregada, o primeiro termo
	// curto-circuitaria o segundo, e a tela sairia ora larga, ora estreita.
	$e_da_incubadora = false;

	if ( class_exists( 'Reconectar_Incubadora' ) ) {
		$e_da_incubadora = Reconectar_Incubadora::requisicao_e_da_incubadora();
	}

	if ( ! is_front_page() && ! $painel_de_empresas && ! $pagina_de_loja && ! $listagem_de_lojas && ! $e_do_forum && ! $e_da_incubadora ) {
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

/**
 * Tira o título do tema pai na listagem do fórum.
 *
 * É o mesmo defeito que `reconectar_ajustar_cabecalho_da_pergunta()` corrigiu na
 * tela de uma pergunta, reaparecido um nível acima: a listagem nasce com
 * `<h1 class="entry-title">Fóruns</h1>`, do tema pai, e logo abaixo com o
 * `<h1 class="rc-forum__titulo">Todas as perguntas</h1>`, da camada autoral. Dois
 * cabeçalhos de primeiro nível na mesma página, e quem navega por cabeçalhos
 * precisa decidir qual dos dois nomeia o documento.
 *
 * Sai o do tema pai porque "Todas as perguntas" é o que descreve a tela — o
 * outro é o nome do post type.
 *
 * O gancho é `storefront_page`, e não `storefront_archive`, embora o `<body>`
 * receba `post-type-archive-forum`: o bbPress serve a listagem pela camada de
 * compatibilidade de tema, que injeta o conteúdo num post falso (`post-0`) e
 * deixa o Storefront renderizar `content-page.php`. Medido no DOM antes de
 * escrever o `remove_action` — presumir `storefront_archive` aqui custaria uma
 * remoção que não remove nada, e o sintoma seria "o código está lá e não faz".
 *
 * Restrito à listagem: a pergunta única já é tratada pela função irmã, e a
 * categoria de fórum não imprime título próprio.
 *
 * @return void
 */
function reconectar_remover_titulo_da_listagem_do_forum() {
	if ( ! is_post_type_archive( 'forum' ) ) {
		return;
	}

	remove_action( 'storefront_page', 'storefront_page_header', 10 );
}
add_action( 'wp', 'reconectar_remover_titulo_da_listagem_do_forum' );

/**
 * Tira o título e a trilha do tema pai nas telas da Incubadora.
 *
 * A âncora `/incubadora/` é uma página comum, e o Storefront imprimiria o
 * `<h1 class="entry-title">Incubadora</h1>` dela por cima do `<h1>` que a
 * Incubadora imprime para a página aberta — dois cabeçalhos de primeiro nível,
 * o mesmo defeito das funções irmãs acima. Sai o do tema pai: o da Incubadora é
 * o que nomeia a página que se está lendo.
 *
 * A página isolada não passa por `storefront_page`, porque o template é do
 * plugin; a remoção cobre só a âncora, mas a pergunta ao plugin já responde às
 * duas, e não há por que separá-las aqui.
 *
 * A trilha do WooCommerce sai pelo motivo da pergunta do fórum: a Incubadora
 * imprime a dela, que segue a árvore e omite a mãe em rascunho para quem não a
 * enxerga. A do WooCommerce sobe por `post_parent` sem olhar a situação, e
 * imprimiria o título de um rascunho na tela de um comprador.
 *
 * @return void
 */
function reconectar_remover_titulo_da_incubadora() {
	if ( ! class_exists( 'Reconectar_Incubadora' ) ) {
		return;
	}

	if ( ! Reconectar_Incubadora::requisicao_e_da_incubadora() ) {
		return;
	}

	remove_action( 'storefront_page', 'storefront_page_header', 10 );
	remove_action( 'storefront_before_content', 'woocommerce_breadcrumb', 10 );
}
add_action( 'wp', 'reconectar_remover_titulo_da_incubadora' );

/**
 * Marca as páginas cujo título deve sair da tela, mas não do documento.
 *
 * Nas páginas de catálogo e nas do WooCommerce o `<h1>` diz o mesmo que a trilha
 * de navegação logo acima — "Início / Shop" seguido de "Shop" —, e ainda o diz
 * em 41,89px, acima do teto de `--rc-fonte-3xl`. Ele ocupa a primeira dobra sem
 * informar nada que já não esteja dito.
 *
 * O que separa esta função das duas acima é o que se faz com o cabeçalho, e a
 * distinção não é visível na leitura: ali há **dois** `<h1>` na página, e um
 * precisa deixar de existir; aqui há **um só**, que é o nome do documento. Tirá-lo
 * do DOM deixaria quem navega por cabeçalhos sem saber em que página está, o que
 * a WCAG 2.1 exigida pelo edital não admite. Por isso a saída é uma classe no
 * `<body>` e um `.screen-reader-text` aplicado por CSS: some da tela, fica para o
 * leitor de tela.
 *
 * Pelo mesmo motivo não se usa o filtro `woocommerce_show_page_title`, que seria
 * o caminho óbvio para o catálogo: ele não esconde o título, ele apaga o `<h1>`
 * de `loop/header.php`.
 *
 * A tela de acesso fica de fora porque já é caso da função acima — lá existe o
 * segundo `<h1>` ("Acessar a plataforma") e a remoção é a correta. Daí o
 * `is_user_logged_in()` na condição de `is_account_page()`: dentro do painel,
 * "Minha conta" volta a ser título único e passa a ser caso desta função.
 *
 * `function_exists()` em tudo que é de terceiro, como as cinco condições de
 * `reconectar_ajustar_classes_de_layout()` já fazem: o tema não pode exigir o
 * WooCommerce nem o Dokan.
 *
 * @param string[] $classes Classes do elemento `<body>`.
 * @return string[] Classes ajustadas.
 */
function reconectar_marcar_pagina_sem_titulo( $classes ) {
	$do_woocommerce = function_exists( 'is_shop' )
		&& ( is_shop() || is_product_taxonomy() || is_cart() || is_checkout() || ( is_account_page() && is_user_logged_in() ) );

	$listagem_de_lojas = function_exists( 'dokan_is_store_listing' ) && dokan_is_store_listing();

	if ( $do_woocommerce || $listagem_de_lojas ) {
		$classes[] = 'rc-sem-titulo-de-pagina';
	}

	return $classes;
}
add_filter( 'body_class', 'reconectar_marcar_pagina_sem_titulo' );

/**
 * Recoloca a paginação do catálogo fora da barra de ordenação, só no rodapé.
 *
 * O Storefront monta duas barras simétricas
 * (`storefront-woocommerce-template-hooks.php:45-55`): `storefront_sorting_wrapper`
 * na prioridade 9, ordenação em 10, contagem em 20, paginação em 30 e o
 * fechamento da `<div>` em 31 — a mesma sequência em `woocommerce_before_shop_loop`
 * e em `woocommerce_after_shop_loop`. O resultado medido em `/shop/` eram duas
 * `.storefront-sorting` na página, com paginação impressa **antes** de o visitante
 * ver um único produto.
 *
 * Cada barra perde uma coisa diferente, e a assimetria é o ponto:
 *
 * De cima sai só a paginação. Ordenação e contagem ficam, porque dizem o que a
 * lista logo abaixo contém; paginar antes de existir lista, não.
 *
 * De baixo sai a barra inteira — o `<div>`, a ordenação e a contagem —, porque
 * ali elas eram repetição do que já está no topo, e repetição custa mais a quem
 * navega por teclado ou por leitor de tela do que a quem só rola a página.
 *
 * Fica a paginação, e ela passa da prioridade 30 para a 40, isto é, para depois
 * do `storefront_sorting_wrapper_close` da 31. Isso importaria mesmo que a barra
 * continuasse existindo: dentro dela a paginação herda o `float: right`, e era
 * isso que a jogava contra a margem direita. Fora, centralizar é uma declaração.
 *
 * O `after_setup_theme` não é cerimônia. O `functions.php` do tema **filho** é
 * carregado antes do pai (`wp-settings.php` inclui `STYLESHEETPATH` primeiro),
 * então um `remove_action` escrito no corpo do arquivo tentaria remover um gancho
 * que ainda não foi registrado: a chamada devolve `false`, nada acontece e o
 * sintoma é o pior de todos — o código está no lugar certo e não faz nada.
 *
 * @return void
 */
function reconectar_reposicionar_paginacao_do_catalogo() {
	remove_action( 'woocommerce_before_shop_loop', 'storefront_woocommerce_pagination', 30 );

	remove_action( 'woocommerce_after_shop_loop', 'storefront_sorting_wrapper', 9 );
	remove_action( 'woocommerce_after_shop_loop', 'woocommerce_catalog_ordering', 10 );
	remove_action( 'woocommerce_after_shop_loop', 'woocommerce_result_count', 20 );
	remove_action( 'woocommerce_after_shop_loop', 'storefront_sorting_wrapper_close', 31 );

	remove_action( 'woocommerce_after_shop_loop', 'woocommerce_pagination', 30 );
	add_action( 'woocommerce_after_shop_loop', 'woocommerce_pagination', 40 );
}
add_action( 'after_setup_theme', 'reconectar_reposicionar_paginacao_do_catalogo', 20 );

/**
 * Tira da página de produto a navegação para o produto anterior e o seguinte.
 *
 * O Storefront pendura `storefront_single_product_pagination` em
 * `woocommerce_after_single_product_summary` na prioridade 30 — medido pelo
 * `$wp_filter`. A ordem que ela segue é a de publicação no catálogo inteiro,
 * então o "próximo" de uma almofada pode ser o produto de outra loja, de outra
 * categoria: um atalho que não leva a lugar relacionado com o que se olha. Os
 * relacionados, logo abaixo, já fazem esse papel com critério.
 *
 * Em `after_setup_theme` prioridade 20 pela mesma razão de
 * `reconectar_reposicionar_paginacao_do_catalogo()`: o filho carrega antes do pai.
 *
 * @return void
 */
function reconectar_remover_paginacao_de_produto() {
	remove_action( 'woocommerce_after_single_product_summary', 'storefront_single_product_pagination', 30 );
}
add_action( 'after_setup_theme', 'reconectar_remover_paginacao_de_produto', 20 );

/**
 * Chama a loja de "Loja" na linha do carrinho, onde o Dokan escreve "Vendedor".
 *
 * `dokan_product_seller_info()` está pendurado em `woocommerce_get_item_data` na
 * prioridade 10 e acrescenta ao item uma entrada com o nome da loja, que o
 * WooCommerce imprime como `<dl class="variation">` — o mesmo mecanismo das
 * variações de produto. O rótulo sai de `__( 'Vendor', 'dokan-lite' )`, que a
 * tradução do plugin entrega como "Vendedor".
 *
 * O vocabulário do projeto reserva "vendedor" ao que é nome do Dokan: o papel
 * `seller`, a capacidade `dokandar`, as metas `dokan_*`. Nada disso aparece para
 * quem compra — para o comprador a entidade é a **Loja**, como em toda a
 * vitrine, e a linha do carrinho era o último lugar onde o termo antigo
 * sobrevivia na interface.
 *
 * A comparação é contra a string traduzida **e** contra o literal em inglês: o
 * mesmo item muda de rótulo conforme o idioma ativo, e casar só com um dos dois
 * deixaria o outro passar sem erro nenhum — a falha seria uma palavra na tela,
 * que nenhum teste apanha.
 *
 * @param array $dados_do_item Entradas que o WooCommerce imprime sob o nome do produto.
 * @return array Entradas com o rótulo do Dokan renomeado.
 */
function reconectar_renomear_vendedor_no_carrinho( $dados_do_item ) {
	if ( ! is_array( $dados_do_item ) ) {
		return $dados_do_item;
	}

	$rotulos_do_dokan = array( 'Vendor', __( 'Vendor', 'dokan-lite' ) );

	foreach ( $dados_do_item as $indice => $entrada ) {
		if ( isset( $entrada['name'] ) && in_array( $entrada['name'], $rotulos_do_dokan, true ) ) {
			$dados_do_item[ $indice ]['name'] = __( 'Loja', 'reconectar' );
		}
	}

	return $dados_do_item;
}
add_filter( 'woocommerce_get_item_data', 'reconectar_renomear_vendedor_no_carrinho', 11 );

/**
 * Tira do conteúdo o link "Editar" que o tema pai imprime a quem pode editar.
 *
 * `edit_post_link()` sai no fim do `.entry-content` de todo template que o
 * Storefront usa para conteúdo único. Medido com uma requisição autenticada como
 * Super Administrador, ele aparece na página institucional, no tópico do fórum e
 * no produto — três telas em que o botão fica **dentro** do texto que o visitante
 * lê, misturando ferramenta de edição com conteúdo publicado. A edição continua
 * onde sempre esteve: no `/wp-admin` e no item "Editar" da barra superior, que
 * este filtro não toca.
 *
 * Não é `remove_action`: o Storefront chama a função direto no template, sem
 * gancho, então não há o que remover — e sobrescrever `content-page.php`,
 * `content-single.php` e o template de produto no tema filho criaria três cópias
 * de arquivo de terceiro para apagar uma linha em cada.
 *
 * O filtro alcança a âncora, não o invólucro: `edit_post_link()` faz
 * `echo $before . apply_filters( 'edit_post_link', … ) . $after`, de modo que o
 * `<div class="edit-link">` sobrevive vazio no DOM. Quem o esconde é
 * `.edit-link:empty` em `marketplace.css`, e o comentário de lá registra por que
 * a alternativa — filtrar `get_edit_post_link`, que faria a função desistir antes
 * do `$before` — foi descartada.
 *
 * A guarda de `is_admin()` é literal ao que se pediu: fora do painel, nada; no
 * painel, o que o WordPress montou.
 *
 * @param string $link Âncora que o WordPress montou para editar o post.
 * @return string String vazia no front-end; o link original no painel.
 */
function reconectar_remover_link_de_edicao_no_conteudo( $link ) {
	return is_admin() ? $link : '';
}
add_filter( 'edit_post_link', 'reconectar_remover_link_de_edicao_no_conteudo' );
