<?php
/**
 * Consultas do fórum de perguntas e respostas.
 *
 * Só dados. Quem imprime é `componentes.php` e `listagem.php`.
 *
 * O motor é o bbPress, e aqui vale o contrário do que vale para os pedidos:
 * `bbp_has_topics()` monta `$r` e chama `new WP_Query( $r )` direto
 * (`bbpress/includes/topics/template.php:155`), então `meta_query`, `orderby`,
 * `tax_query` e `s` funcionam de verdade. Não é `wc_get_orders()`, que reconhece
 * uma lista fechada de argumentos e descarta o resto em silêncio.
 *
 * @package reconectar
 */

defined( 'ABSPATH' ) || exit;

/**
 * Perguntas por página na listagem.
 *
 * Doze é o que o protótipo mostra no seletor, e é múltiplo de 2 e 3 — a grade
 * fecha sem órfão tanto em duas colunas quanto em três.
 */
const RECONECTAR_FORUM_POR_PAGINA = 12;

/**
 * Tags exibidas no bloco "Mais usadas" antes do "ver todas".
 */
const RECONECTAR_FORUM_TAGS_NA_SIDEBAR = 10;

/**
 * Abas de ordenação da listagem.
 *
 * A chave é o valor que trafega em `?ordem=`; o valor é o rótulo.
 *
 * @return string[]
 */
function reconectar_forum_abas() {
	return array(
		'recentes'     => __( 'Recentes', 'reconectar' ),
		'votos'        => __( 'Votos', 'reconectar' ),
		'sem-resposta' => __( 'Sem resposta', 'reconectar' ),
	);
}

/**
 * Lê da URL os filtros ativos da listagem, já validados.
 *
 * Nada aqui confia no que veio do `$_GET`: a aba cai na primeira se não estiver
 * na lista, a categoria precisa ser um fórum que existe, e a página nunca é
 * menor que 1.
 *
 * @return array{aba:string,categoria:int,busca:string,pagina:int}
 */
function reconectar_forum_filtros_ativos() {
	// phpcs:disable WordPress.Security.NonceVerification.Recommended -- leitura de filtros públicos, sem efeito colateral.
	$aba = isset( $_GET['ordem'] ) ? sanitize_key( wp_unslash( $_GET['ordem'] ) ) : 'recentes';

	if ( ! array_key_exists( $aba, reconectar_forum_abas() ) ) {
		$aba = 'recentes';
	}

	$categoria = isset( $_GET['categoria'] ) ? absint( $_GET['categoria'] ) : 0;

	if ( $categoria && 'forum' !== get_post_type( $categoria ) ) {
		$categoria = 0;
	}

	$busca = isset( $_GET['q'] ) ? sanitize_text_field( wp_unslash( $_GET['q'] ) ) : '';

	$pagina = isset( $_GET['pagina'] ) ? absint( $_GET['pagina'] ) : 1;
	// phpcs:enable WordPress.Security.NonceVerification.Recommended

	return array(
		'aba'       => $aba,
		'categoria' => $categoria,
		'busca'     => $busca,
		'pagina'    => max( 1, $pagina ),
	);
}

/**
 * Monta os argumentos da consulta de perguntas para os filtros dados.
 *
 * Uma `WP_Query` própria, e não `bbp_has_topics()`. Os quatro comportamentos que
 * a função do bbPress acrescenta ou não se aplicam aqui ou não são desejados: um
 * tópico fixado furaria a ordenação por votos sem nenhuma indicação visual de que
 * furou, os status ocultos não interessam a uma listagem pública, o `post_parent`
 * default `'any'` é o mesmo que omitir o argumento, e o pré-aquecimento de cache
 * da família do tópico é otimização, não comportamento.
 *
 * Em troca, a consulta própria devolve `->posts` e `->max_num_pages` direto —
 * que é do que a paginação e os componentes precisam — em vez de depender do
 * estado global que `bbp_has_topics()` mantém em `bbpress()->topic_query`.
 *
 * Os quatro filtros ganham default aqui, ainda que
 * `reconectar_forum_filtros_ativos()` sempre devolva os quatro preenchidos. A
 * razão é que esta função é o ponto por onde se mede a listagem contra o banco —
 * `wp eval` com `array( 'aba' => 'votos' )` é o teste que prova cada aba —, e sem
 * os defaults cada medição dessas sai enterrada em quatro avisos de chave
 * indefinida, com a lista que interessa no meio deles.
 *
 * @param array $filtros Saída de `reconectar_forum_filtros_ativos()`, ou um
 *                       recorte dela.
 * @return array
 */
function reconectar_forum_argumentos( $filtros ) {
	$filtros = wp_parse_args(
		$filtros,
		array(
			'aba'       => 'recentes',
			'categoria' => 0,
			'busca'     => '',
			'pagina'    => 1,
		)
	);

	$args = array(
		'post_type'      => 'topic',
		'post_status'    => 'publish',
		'posts_per_page' => RECONECTAR_FORUM_POR_PAGINA,
		'paged'          => $filtros['pagina'],
		/*
		 * Ordem de partida: a última atividade, que é o default do bbPress. A meta
		 * existe desde a criação — `bbp_insert_topic()` a grava junto de
		 * `_bbp_reply_count` e das demais —, então o INNER JOIN que `meta_key`
		 * monta não some com tópico nenhum. Isso foi medido, e não presumido: é o
		 * mesmo risco que a aba "Votos" corre e que lá precisou de `posts_clauses`.
		 */
		'meta_key'       => '_bbp_last_active_time', // phpcs:ignore WordPress.DB.SlowDBQuery
		'meta_type'      => 'DATETIME',
		'orderby'        => 'meta_value',
		'order'          => 'DESC',
	);

	if ( $filtros['categoria'] ) {
		$args['post_parent'] = $filtros['categoria'];
	}

	if ( '' !== $filtros['busca'] ) {
		$args['s'] = $filtros['busca'];
	}

	if ( 'votos' === $filtros['aba'] && class_exists( 'Reconectar_Forum' ) ) {
		$args = array_merge( $args, Reconectar_Forum::consulta_por_votos() );
	}

	if ( 'sem-resposta' === $filtros['aba'] ) {
		/*
		 * `_bbp_reply_count` é mantido pelo próprio bbPress, mas só passa a
		 * existir quando a primeira resposta chega: um tópico recém-criado não
		 * tem a meta. Sem o ramo `NOT EXISTS` a aba mostraria apenas as perguntas
		 * que já tiveram resposta e voltaram a zero — que é quase nenhuma, e é o
		 * oposto do que a aba promete.
		 */
		$args['meta_query'] = array( // phpcs:ignore WordPress.DB.SlowDBQuery
			'relation' => 'OR',
			array(
				'key'     => '_bbp_reply_count',
				'value'   => 0,
				'compare' => '=',
				'type'    => 'NUMERIC',
			),
			array(
				'key'     => '_bbp_reply_count',
				'compare' => 'NOT EXISTS',
			),
		);
		$args['meta_key']   = ''; // phpcs:ignore WordPress.DB.SlowDBQuery
		$args['meta_type']  = '';
		$args['orderby']    = 'date';
		$args['order']      = 'DESC';
	}

	return $args;
}

/**
 * Monta a URL da listagem com um filtro trocado.
 *
 * Mantém os demais parâmetros, e some com `pagina` sempre que o conjunto muda —
 * ficar na página 3 de uma lista que acabou de encolher devolve vazio.
 *
 * @param array $filtros   Filtros ativos.
 * @param array $alteracao Pares a sobrescrever.
 * @return string
 */
function reconectar_forum_url( $filtros, $alteracao = array() ) {
	$filtros = array_merge( $filtros, $alteracao );
	$query   = array();

	if ( 'recentes' !== $filtros['aba'] ) {
		$query['ordem'] = $filtros['aba'];
	}

	if ( $filtros['categoria'] ) {
		$query['categoria'] = $filtros['categoria'];
	}

	if ( '' !== $filtros['busca'] ) {
		$query['q'] = $filtros['busca'];
	}

	if ( ! isset( $alteracao['pagina'] ) && $filtros['pagina'] > 1 ) {
		$query['pagina'] = $filtros['pagina'];
	} elseif ( isset( $alteracao['pagina'] ) && $alteracao['pagina'] > 1 ) {
		$query['pagina'] = $alteracao['pagina'];
	}

	$base = reconectar_forum_url_base();

	return $query ? add_query_arg( $query, $base ) : $base;
}

/**
 * URL da listagem de perguntas.
 *
 * `bbp_get_forums_url()` porque a raiz é configurável no painel do bbPress:
 * fixar `/forums/` daria um link quebrado assim que alguém traduzir a raiz.
 *
 * @return string
 */
function reconectar_forum_url_base() {
	return function_exists( 'bbp_get_forums_url' ) ? bbp_get_forums_url() : home_url( '/forums/' );
}

/**
 * Categorias do fórum, para o seletor e para a sidebar.
 *
 * @return WP_Post[]
 */
function reconectar_forum_categorias() {
	return get_posts(
		array(
			'post_type'      => 'forum',
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'orderby'        => 'menu_order title',
			'order'          => 'ASC',
		)
	);
}

/**
 * Tags mais usadas nas perguntas.
 *
 * @param int $limite Quantas trazer.
 * @return WP_Term[]
 */
function reconectar_forum_tags_populares( $limite = RECONECTAR_FORUM_TAGS_NA_SIDEBAR ) {
	$tags = get_terms(
		array(
			'taxonomy'   => 'topic-tag',
			'orderby'    => 'count',
			'order'      => 'DESC',
			'number'     => $limite,
			'hide_empty' => true,
		)
	);

	return is_wp_error( $tags ) ? array() : $tags;
}

/**
 * Perguntas em alta: as mais votadas entre as que tiveram atividade recente.
 *
 * "Em alta" é sobre o que está acontecendo agora, então a janela vem primeiro e
 * a votação ordena dentro dela. Ordenar só por voto devolveria sempre as mesmas
 * perguntas antigas, que é o que o bloco não deve ser.
 *
 * @param int $limite Quantas trazer.
 * @param int $dias   Tamanho da janela de atividade.
 * @return WP_Post[]
 */
function reconectar_forum_em_alta( $limite = 5, $dias = 30 ) {
	if ( ! class_exists( 'Reconectar_Forum' ) ) {
		return array();
	}

	$args = array_merge(
		Reconectar_Forum::consulta_por_votos(),
		array(
			'post_type'      => 'topic',
			'post_status'    => 'publish',
			'posts_per_page' => $limite,
			'date_query'     => array(
				array( 'after' => $dias . ' days ago' ),
			),
		)
	);

	$consulta = new WP_Query( $args );

	return $consulta->posts;
}

/**
 * Quantidade de perguntas publicadas.
 *
 * @return int
 */
function reconectar_forum_total_de_perguntas() {
	$contagem = wp_count_posts( 'topic' );

	return $contagem ? (int) $contagem->publish : 0;
}

/**
 * Quantidade de pessoas com acesso à comunidade.
 *
 * Conta os papéis autorizados, e não os usuários do site: o número ao lado de
 * "perguntas" precisa dizer quantas pessoas podem participar do fórum, não
 * quantas têm conta na plataforma. São coisas diferentes, e a segunda é maior.
 *
 * @return int
 */
function reconectar_forum_total_de_membros() {
	if ( ! class_exists( 'Reconectar_Permissoes' ) ) {
		return 0;
	}

	$contagem = count_users();
	$total    = 0;

	foreach ( Reconectar_Permissoes::PAPEIS_DA_COMUNIDADE as $papel ) {
		$total += isset( $contagem['avail_roles'][ $papel ] ) ? (int) $contagem['avail_roles'][ $papel ] : 0;
	}

	return $total;
}

/**
 * Últimas perguntas e respostas, para a coluna de atividade.
 *
 * Sai do próprio bbPress, e não da tabela de atividades do BuddyPress, embora o
 * componente `activity` esteja ativo e `BBP_BuddyPress_Activity` exista nesta
 * instalação. O motivo é medido: aquela integração se pendura em `bbp_new_topic`
 * e `bbp_new_reply`, que são disparados pelos manipuladores do formulário do
 * frontend — **não** por `bbp_insert_topic()`/`bbp_insert_reply()`. Toda pergunta
 * criada programaticamente, a carga de demonstração inclusive, não gera registro
 * de atividade, e a coluna nasceria vazia numa tela cheia de perguntas.
 *
 * A consulta direta responde pelo que existe, e não pelo que foi registrado.
 *
 * @param int $limite Quantos itens trazer.
 * @return WP_Post[]
 */
function reconectar_forum_atividade_recente( $limite = 6 ) {
	return get_posts(
		array(
			'post_type'      => array( 'topic', 'reply' ),
			'post_status'    => 'publish',
			'posts_per_page' => $limite,
			'orderby'        => 'date',
			'order'          => 'DESC',
		)
	);
}

/**
 * Respostas de uma pergunta, com a aceita à frente.
 *
 * O laço nativo do bbPress ordena por data e não sabe de melhor resposta. Trazer
 * tudo de uma vez é aceitável aqui porque o conjunto é o de uma única pergunta;
 * o `posts_per_page` continua limitado para que uma discussão muito longa não
 * derrube a página.
 *
 * @param int $topico_id Pergunta.
 * @param int $limite    Teto de respostas carregadas.
 * @return WP_Post[]
 */
function reconectar_forum_respostas( $topico_id, $limite = 100 ) {
	$respostas = get_posts(
		array(
			'post_type'      => 'reply',
			'post_parent'    => $topico_id,
			'post_status'    => 'publish',
			'posts_per_page' => $limite,
			'orderby'        => 'date',
			'order'          => 'ASC',
		)
	);

	if ( ! class_exists( 'Reconectar_Forum' ) ) {
		return $respostas;
	}

	$aceita = Reconectar_Forum::melhor_resposta( $topico_id );

	if ( ! $aceita ) {
		return $respostas;
	}

	$destacada = array();
	$demais    = array();

	foreach ( $respostas as $resposta ) {
		if ( (int) $resposta->ID === $aceita ) {
			$destacada[] = $resposta;
		} else {
			$demais[] = $resposta;
		}
	}

	return array_merge( $destacada, $demais );
}
