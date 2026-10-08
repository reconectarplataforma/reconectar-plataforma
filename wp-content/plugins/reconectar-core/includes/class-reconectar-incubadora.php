<?php
/**
 * A entidade Incubadora: páginas e subpáginas de conhecimento, editadas no site.
 *
 * Moderador, Administrador e Super Administrador escrevem; todo usuário logado
 * lê. A experiência pedida é a de uma wiki — árvore lateral, edição no lugar —
 * e por isso nada daqui passa pelo `/wp-admin`: `show_ui` é falso, e a tela
 * vem de uma rota própria do front-end.
 *
 * Esta classe é só a **base**: o post type, o portão de leitura e o que impede
 * o conteúdo de vazar para fora da sessão — mecanismo de busca, feed e oEmbed.
 * Leitura, edição, árvore e upload moram em classes irmãs.
 *
 * @package reconectar-core
 */

defined( 'ABSPATH' ) || exit;

class Reconectar_Incubadora {

	/**
	 * Post type da página da Incubadora.
	 *
	 * 18 caracteres de propósito. `wp_posts.post_type` é `varchar(20)` e o
	 * `sql_mode` desta instalação não é estrito: `reconectar_incubadora`, que
	 * seria o nome natural no padrão dos outros CPTs, tem 21 e chegaria ao banco
	 * truncado, em silêncio, como um tipo que não casa com nenhum registrado.
	 */
	const POST_TYPE = 'incubadora_pagina';

	/**
	 * Slug da URL, da página âncora e do item de menu.
	 */
	const SLUG = 'incubadora';

	/**
	 * Quantos níveis a árvore admite abaixo da raiz.
	 *
	 * Sem teto, a árvore lateral vira escada, e a recursão de leitura não tem
	 * limite que proteja contra um ciclo de `post_parent` gravado por engano.
	 */
	const PROFUNDIDADE_MAXIMA = 8;

	/**
	 * Quantas revisões ficam por página.
	 *
	 * `WP_POST_REVISIONS` está ligado sem limite nesta instalação, e uma wiki
	 * salva muito mais vezes que um post. O teto vem por filtro e só para este
	 * post type, para não mexer no histórico do resto do site.
	 */
	const REVISOES_MAXIMAS = 30;

	/**
	 * Registra os ganchos da entidade.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'init', array( __CLASS__, 'registrar_post_type' ) );

		// Prioridade 1, no molde de `bloquear_comunidade()`: antes de
		// `redirect_canonical()`, em 10, que responderia ao visitante com um 301
		// para o permalink — e o slug da página já seria vazamento.
		add_action( 'template_redirect', array( __CLASS__, 'bloquear_leitura' ), 1 );

		add_filter( 'wp_robots', array( __CLASS__, 'impedir_indexacao' ) );
		add_filter( 'oembed_request_post_id', array( __CLASS__, 'recusar_oembed' ), 10, 2 );
		add_filter( 'oembed_discovery_links', array( __CLASS__, 'omitir_descoberta_de_oembed' ) );
		add_filter( 'wp_revisions_to_keep', array( __CLASS__, 'limitar_revisoes' ), 10, 2 );
	}

	/**
	 * Registra o post type.
	 *
	 * `public` é falso e `publicly_queryable` é verdadeiro: a página precisa de
	 * URL no front-end, mas não pode entrar em busca, menu automático nem
	 * listagem pública. O portão de leitura, abaixo, é quem decide quem vê.
	 *
	 * `query_var` tem de ficar ligado, embora abra a entrada
	 * `?incubadora_pagina=<slug>`. Desligado, o núcleo monta a regra com
	 * `pagename=$matches[1]`, e com o permalink `/%postname%/` desta instalação
	 * valem as `use_verbose_page_rules`: `WP::parse_request()` confere toda
	 * regra com `pagename` por `get_page_by_path()`, que só procura no tipo
	 * `page`, e a descarta em silêncio. Medido: `/incubadora/<slug>/` caía na
	 * regra de anexo `[^/]+/([^/]+)/?$` e respondia 404 — inclusive ao
	 * visitante, que nem chegava ao portão. A entrada pela query string não
	 * escapa do portão: o núcleo converte a query var em `post_type`, que
	 * `requisicao_e_da_incubadora()` confere.
	 *
	 * @return void
	 */
	public static function registrar_post_type() {
		$labels = array(
			'name'               => __( 'Incubadora', 'reconectar-core' ),
			'singular_name'      => __( 'Página da Incubadora', 'reconectar-core' ),
			'add_new'            => __( 'Adicionar página', 'reconectar-core' ),
			'add_new_item'       => __( 'Adicionar página', 'reconectar-core' ),
			'edit_item'          => __( 'Editar página', 'reconectar-core' ),
			'new_item'           => __( 'Nova página', 'reconectar-core' ),
			'view_item'          => __( 'Ver página', 'reconectar-core' ),
			'search_items'       => __( 'Buscar na Incubadora', 'reconectar-core' ),
			'not_found'          => __( 'Nenhuma página encontrada', 'reconectar-core' ),
			'not_found_in_trash' => __( 'Nenhuma página na lixeira', 'reconectar-core' ),
			'parent_item_colon'  => __( 'Página mãe:', 'reconectar-core' ),
			'menu_name'          => __( 'Incubadora', 'reconectar-core' ),
		);

		$capacidade = Reconectar_Permissoes::CAP_GERIR_INCUBADORA;

		register_post_type(
			self::POST_TYPE,
			array(
				'labels'              => $labels,
				'hierarchical'        => true,
				'public'              => false,
				'publicly_queryable'  => true,
				'exclude_from_search' => true,
				'show_ui'             => false,
				'show_in_menu'        => false,
				'show_in_nav_menus'   => false,
				'show_in_admin_bar'   => false,
				'show_in_rest'        => false,
				'has_archive'         => false,
				'query_var'           => self::POST_TYPE,
				'can_export'          => true,
				'delete_with_user'    => false,
				'supports'            => array( 'title', 'editor', 'author', 'revisions', 'page-attributes' ),
				'rewrite'             => array(
					'slug'       => self::SLUG,
					'with_front' => false,
					'feeds'      => false,
					'pages'      => false,
				),
				'map_meta_cap'        => true,
				/*
				 * Só primitivas, pela razão registrada em
				 * `class-reconectar-empresa.php`, no mesmo bloco: apontar
				 * `edit_post`, `read_post` ou `delete_post` para a capacidade
				 * monta o mapa reverso do núcleo ao contrário, o aviso de
				 * `_doing_it_wrong()` sai no meio do `admin_menu` e derruba os
				 * redirecionamentos do `/wp-admin`.
				 *
				 * Uma capacidade só para todas, sem "dos outros" à parte: é uma
				 * wiki colaborativa, e quem edita edita qualquer página.
				 */
				'capabilities'        => array(
					'edit_posts'             => $capacidade,
					'edit_others_posts'      => $capacidade,
					'edit_published_posts'   => $capacidade,
					'edit_private_posts'     => $capacidade,
					'publish_posts'          => $capacidade,
					'delete_posts'           => $capacidade,
					'delete_others_posts'    => $capacidade,
					'delete_published_posts' => $capacidade,
					'delete_private_posts'   => $capacidade,
					'read_private_posts'     => $capacidade,
					'create_posts'           => $capacidade,
				),
			)
		);
	}

	/**
	 * Diz se a requisição corrente é de conteúdo da Incubadora.
	 *
	 * Cobre a página isolada, a âncora `/incubadora/` e toda consulta que peça o
	 * post type pela query string — inclusive a que dá 404, porque o 404 de um
	 * slug inexistente ainda confirma ao visitante que a rota existe. Feed e
	 * embed caem no mesmo teste: são a mesma consulta com outra saída.
	 *
	 * Pública porque a camada de leitura e o tema também a consultam — classe do
	 * `<body>`, layout de largura total, cabeçalho de página. Um segundo teste
	 * escrito à parte divergiria deste no primeiro caso novo, e a tela passaria a
	 * se vestir de Incubadora onde o portão não a protege, ou o contrário.
	 *
	 * @return bool
	 */
	public static function requisicao_e_da_incubadora() {
		if ( is_singular( self::POST_TYPE ) || is_page( self::SLUG ) ) {
			return true;
		}

		$tipo = get_query_var( 'post_type' );

		if ( is_array( $tipo ) ) {
			return in_array( self::POST_TYPE, $tipo, true );
		}

		return self::POST_TYPE === $tipo;
	}

	/**
	 * O usuário lê a Incubadora?
	 *
	 * Pelo papel, na mesma divisão da tela `/modulos/`: a Incubadora é módulo
	 * da Loja, do Administrador e do Moderador, e o Super Administrador a
	 * alcança por ser a administração técnica. O cliente fica de fora — ele
	 * compra, e a Incubadora é a formação de quem vende. Até 2026-10-07 lia
	 * qualquer usuário logado; a decisão mudou, e o item do menu, a rota e os
	 * arquivos passaram a consultar só este método.
	 *
	 * @param int $usuario_id Usuário; 0 usa o corrente.
	 * @return bool
	 */
	public static function pode_ler( $usuario_id = 0 ) {
		$usuario_id = $usuario_id ? (int) $usuario_id : get_current_user_id();

		if ( ! $usuario_id ) {
			return false;
		}

		return Reconectar_Modulos::escolhe_modulo( $usuario_id )
			|| Reconectar_Permissoes::eh_administracao_tecnica( $usuario_id );
	}

	/**
	 * Fecha a Incubadora antes de qualquer conteúdo a quem não a lê.
	 *
	 * O visitante vai ao login, com a URL pedida de retorno, para voltar ao
	 * ponto em que estava. Quem já entrou e não lê — o cliente — vai para a
	 * home, como faz o portão de `/modulos/`: mandá-lo ao login seria um laço,
	 * e um 403 numa tela sem saída não diz para onde ir. `nocache_headers()`
	 * impede que um proxy guarde o redirecionamento e o sirva depois a quem
	 * tem o direito.
	 *
	 * @return void
	 */
	public static function bloquear_leitura() {
		if ( ! self::requisicao_e_da_incubadora() || self::pode_ler() ) {
			return;
		}

		nocache_headers();
		wp_safe_redirect( is_user_logged_in() ? home_url( '/' ) : wp_login_url( home_url( add_query_arg( array() ) ) ) );
		exit;
	}

	/**
	 * Pede aos buscadores que não indexem nem sigam a Incubadora.
	 *
	 * Redundante para o visitante, que nunca chega a ver o HTML; não para o
	 * robô que entre com sessão — um crawler de monitoramento, uma extensão de
	 * navegador — e cujo índice seria público.
	 *
	 * @param array $robots Diretivas já montadas.
	 * @return array
	 */
	public static function impedir_indexacao( $robots ) {
		if ( self::requisicao_e_da_incubadora() ) {
			$robots['noindex']  = true;
			$robots['nofollow'] = true;
		}

		return $robots;
	}

	/**
	 * Recusa o oEmbed de uma página da Incubadora.
	 *
	 * O endpoint `/wp-json/oembed/1.0/embed` não passa por `template_redirect`
	 * nem confere sessão: ele devolve título, autor e resumo de qualquer post
	 * publicado a quem pedir a URL. Sem este filtro, o portão de leitura seria
	 * contornado por uma chamada de REST.
	 *
	 * @param int    $post_id ID resolvido a partir da URL.
	 * @param string $url     URL pedida.
	 * @return int
	 */
	public static function recusar_oembed( $post_id, $url ) {
		unset( $url );

		if ( $post_id && self::POST_TYPE === get_post_type( $post_id ) ) {
			return 0;
		}

		return $post_id;
	}

	/**
	 * Tira do `<head>` os links de descoberta de oEmbed da Incubadora.
	 *
	 * O endpoint já recusa (ver `recusar_oembed()`); anunciá-lo levaria
	 * qualquer cliente que colasse o link a uma resposta de erro.
	 *
	 * @param string $links HTML dos links de descoberta.
	 * @return string
	 */
	public static function omitir_descoberta_de_oembed( $links ) {
		return is_singular( self::POST_TYPE ) ? '' : $links;
	}

	/**
	 * Limita o histórico de cada página a `REVISOES_MAXIMAS`.
	 *
	 * @param int     $quantidade Limite vigente.
	 * @param WP_Post $post       Post sendo revisado.
	 * @return int
	 */
	public static function limitar_revisoes( $quantidade, $post ) {
		if ( $post instanceof WP_Post && self::POST_TYPE === $post->post_type ) {
			return self::REVISOES_MAXIMAS;
		}

		return $quantidade;
	}
}
