<?php
/**
 * A tela de leitura da Incubadora: índice, página isolada e árvore lateral.
 *
 * Um renderizador só serve as duas entradas. A âncora `/incubadora/` é uma
 * página comum com o shortcode `[reconectar_incubadora]`, e a página isolada
 * `/incubadora/mae/filha/` chega por `template_include`. Se cada uma montasse a
 * própria tela, a árvore lateral e a trilha divergiriam no primeiro ajuste.
 *
 * Nada daqui escreve. Edição, criação e arrastar para reordenar moram na classe
 * de ações; esta só lê, e por isso o único dado de permissão que consulta é se
 * o usuário corrente enxerga rascunho.
 *
 * @package reconectar-core
 */

defined( 'ABSPATH' ) || exit;

class Reconectar_Incubadora_Leitura {

	/**
	 * Nome do shortcode da página âncora.
	 */
	const SHORTCODE = 'reconectar_incubadora';

	/**
	 * Versão dos assets, para invalidar o cache do navegador.
	 */
	const VERSAO_ASSETS = '0.6.1';

	/**
	 * Registra os ganchos da tela de leitura.
	 *
	 * @return void
	 */
	public static function init() {
		add_shortcode( self::SHORTCODE, array( __CLASS__, 'shortcode' ) );

		// Prioridade 99: depois do tema e do WooCommerce, que também filtram o
		// template e devolveriam o `single.php` genérico por cima do nosso.
		add_filter( 'template_include', array( __CLASS__, 'escolher_template' ), 99 );

		// Prioridade 2: logo depois do portão de leitura, que está em 1 e manda
		// o visitante ao login antes de qualquer 404 confirmar que a rota existe.
		// E antes de `redirect_canonical()`, em 10.
		add_action( 'template_redirect', array( __CLASS__, 'exigir_caminho_visivel' ), 2 );
		add_action( 'template_redirect', array( __CLASS__, 'abrir_primeira_pagina' ), 2 );

		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enfileirar_assets' ) );
		add_filter( 'body_class', array( __CLASS__, 'classe_do_corpo' ) );
		add_filter( 'wp_nav_menu_objects', array( __CLASS__, 'ocultar_item_do_visitante' ) );
	}

	/**
	 * Imprime o índice da Incubadora no lugar do shortcode.
	 *
	 * O portão de leitura já mandou o visitante ao login em `template_redirect`;
	 * a guarda de login aqui cobre o shortcode colado em outra página, que o
	 * portão não reconhece como da Incubadora.
	 *
	 * @return string
	 */
	public static function shortcode() {
		if ( ! is_user_logged_in() ) {
			return '';
		}

		return self::renderizar( null );
	}

	/**
	 * Troca o template da página isolada pelo da Incubadora.
	 *
	 * @param string $template Caminho escolhido pelo núcleo.
	 * @return string
	 */
	public static function escolher_template( $template ) {
		if ( is_singular( Reconectar_Incubadora::POST_TYPE ) ) {
			return RECONECTAR_CORE_PATH . 'includes/incubadora/single.php';
		}

		return $template;
	}

	/**
	 * Responde 404 à página que a árvore não alcança a partir da raiz.
	 *
	 * Ver `alcancavel_pela_raiz()`: uma filha publicada sob mãe em rascunho
	 * existe para o núcleo, que libera a leitura dela, e não existe para a
	 * árvore. Sem esta guarda, o comprador que tivesse o link leria a página
	 * com uma trilha que pula a mãe.
	 *
	 * @return void
	 */
	public static function exigir_caminho_visivel() {
		if ( ! is_user_logged_in() || ! is_singular( Reconectar_Incubadora::POST_TYPE ) ) {
			return;
		}

		$pagina = get_queried_object();

		if ( $pagina instanceof WP_Post && self::alcancavel_pela_raiz( $pagina, self::mapa_visivel() ) ) {
			return;
		}

		global $wp_query;

		$wp_query->set_404();
		status_header( 404 );
		nocache_headers();
	}

	/**
	 * Leva da âncora `/incubadora/` à primeira página da raiz.
	 *
	 * É a entrada de um espaço do Confluence: quem clica no menu cai na página
	 * inicial do espaço, com a árvore ao lado, e não numa lista que repete a
	 * árvore. O índice só aparece quando não há página nenhuma visível — o
	 * estado vazio.
	 *
	 * 302, e não 301: a primeira página muda quando alguém reordena a raiz, e
	 * um 301 ficaria guardado no navegador apontando para a antiga.
	 *
	 * @return void
	 */
	public static function abrir_primeira_pagina() {
		if ( ! is_user_logged_in() || ! is_page( Reconectar_Incubadora::SLUG ) ) {
			return;
		}

		$pagina = get_queried_object();

		if ( ! $pagina instanceof WP_Post || ! has_shortcode( $pagina->post_content, self::SHORTCODE ) ) {
			return;
		}

		$filhos = self::agrupar_por_mae( self::mapa_visivel() );

		if ( empty( $filhos[0] ) ) {
			return;
		}

		nocache_headers();
		wp_safe_redirect( get_permalink( $filhos[0][0] ), 302 );
		exit;
	}

	/**
	 * Enfileira CSS e JS só onde a Incubadora desenha.
	 *
	 * Na âncora, a condição é o shortcode no `post_content`, no molde de
	 * `Reconectar_Painel_Transparencia::enfileirar_assets()`: uma página
	 * `incubadora` criada à mão sem o shortcode responde 200 e sai sem estilo,
	 * e é por isso que o `provision.sh` a cria com ele.
	 *
	 * @return void
	 */
	public static function enfileirar_assets() {
		if ( ! self::pagina_desenha_a_incubadora() ) {
			return;
		}

		wp_enqueue_style(
			'reconectar-incubadora',
			RECONECTAR_CORE_URL . 'assets/css/incubadora.css',
			array(),
			self::VERSAO_ASSETS
		);

		wp_enqueue_script(
			'reconectar-incubadora',
			RECONECTAR_CORE_URL . 'assets/js/incubadora.js',
			array(),
			self::VERSAO_ASSETS,
			true
		);
	}

	/**
	 * Diz se a requisição corrente imprime a tela da Incubadora.
	 *
	 * `is_singular()` antes do `get_post()` porque em arquivo e em busca o
	 * segundo devolve o primeiro post do laço, que nada tem a ver com a página.
	 *
	 * @return bool
	 */
	public static function pagina_desenha_a_incubadora() {
		if ( is_singular( Reconectar_Incubadora::POST_TYPE ) ) {
			return true;
		}

		if ( ! is_singular() ) {
			return false;
		}

		$pagina = get_post();

		return $pagina instanceof WP_Post && has_shortcode( $pagina->post_content, self::SHORTCODE );
	}

	/**
	 * Marca o `<body>` das telas da Incubadora.
	 *
	 * É a classe que escopa o `overflow-x: clip` do `.site`: sem ela, o
	 * `overflow-x: hidden` do Storefront transforma o `.site` em scroll container
	 * e a árvore lateral, que é `sticky`, sobe com a página.
	 *
	 * @param string[] $classes Classes já montadas.
	 * @return string[]
	 */
	public static function classe_do_corpo( $classes ) {
		if ( Reconectar_Incubadora::requisicao_e_da_incubadora() ) {
			$classes[] = 'rc-tela-incubadora';
		}

		return $classes;
	}

	/**
	 * Tira do menu, para o visitante, o item que leva à Incubadora.
	 *
	 * O portão já o manda ao login; oferecer um link que só leva ao login é
	 * defeito de interface, a mesma razão de `ocultar_itens_da_comunidade()`.
	 * A comparação é pelo ID da âncora, e não pelo rótulo nem pela URL, que o
	 * administrador pode trocar pelo painel.
	 *
	 * @param WP_Post[] $itens Itens do menu.
	 * @return WP_Post[]
	 */
	public static function ocultar_item_do_visitante( $itens ) {
		if ( is_user_logged_in() ) {
			return $itens;
		}

		$ancora    = get_page_by_path( Reconectar_Incubadora::SLUG );
		$ancora_id = $ancora ? (int) $ancora->ID : 0;

		if ( ! $ancora_id ) {
			return $itens;
		}

		foreach ( $itens as $indice => $item ) {
			if ( 'post_type' === $item->type && (int) $item->object_id === $ancora_id ) {
				unset( $itens[ $indice ] );
			}
		}

		return $itens;
	}

	/**
	 * URL da página âncora, ou string vazia se ela não estiver publicada.
	 *
	 * A guarda de `post_status` é a de sempre: `get_page_by_path()` devolve
	 * rascunho e lixeira, e o link levaria ao 404.
	 *
	 * @return string
	 */
	public static function url_da_ancora() {
		$ancora = get_page_by_path( Reconectar_Incubadora::SLUG );

		if ( ! $ancora instanceof WP_Post || 'publish' !== $ancora->post_status ) {
			return '';
		}

		return get_permalink( $ancora );
	}

	/**
	 * Diz se o usuário corrente enxerga rascunho.
	 *
	 * Rascunho é de quem escreve. Loja e comprador leem só o publicado.
	 *
	 * @return bool
	 */
	public static function ve_rascunho() {
		return current_user_can( Reconectar_Permissoes::CAP_GERIR_INCUBADORA );
	}

	/**
	 * Monta a tela inteira: árvore, trilha e índice ou página.
	 *
	 * @param WP_Post|null $pagina Página aberta, ou `null` para o índice.
	 * @return string
	 */
	public static function renderizar( $pagina ) {
		$contexto = self::contexto( $pagina );

		ob_start();
		include RECONECTAR_CORE_PATH . 'includes/incubadora/shell.php';
		return (string) ob_get_clean();
	}

	/**
	 * A árvore e a trilha refeitas, para a resposta de uma ação que as altere.
	 *
	 * Saem dos mesmos templates da tela inteira — ver `processar_mover()`. Os
	 * ramos que a pessoa tinha aberto na tela vão em `$abertos`, para a árvore
	 * nova não se fechar inteira debaixo do cursor.
	 *
	 * Chamar só **depois** da gravação: `mapa_visivel()` guarda o resultado por
	 * requisição, e uma leitura anterior congelaria a árvore de antes.
	 *
	 * @param int   $atual_id ID da página aberta na tela, ou 0 na âncora.
	 * @param int[] $abertos  IDs dos ramos abertos na tela.
	 * @return array{arvore: string, trilha: string}
	 */
	public static function fragmentos( $atual_id, $abertos = array() ) {
		$mapa     = self::mapa_visivel();
		$contexto = self::contexto( isset( $mapa[ $atual_id ] ) ? $mapa[ $atual_id ] : null, $abertos );

		return array(
			'arvore' => self::html_do_template( 'arvore-corpo.php', $contexto ),
			'trilha' => $contexto['pagina'] instanceof WP_Post ? self::html_do_template( 'trilha.php', $contexto ) : '',
		);
	}

	/**
	 * Inclui um template da Incubadora e devolve o que ele imprimiu.
	 *
	 * @param string $arquivo  Nome do arquivo em `includes/incubadora/`.
	 * @param array  $contexto Contexto montado em `contexto()`.
	 * @return string
	 */
	public static function html_do_template( $arquivo, $contexto ) {
		ob_start();
		include RECONECTAR_CORE_PATH . 'includes/incubadora/' . $arquivo;
		return (string) ob_get_clean();
	}

	/**
	 * Monta o contexto que os templates da Incubadora leem.
	 *
	 * @param WP_Post|null $pagina  Página aberta, ou `null` para o índice.
	 * @param int[]        $abertos IDs de ramos a desenhar abertos, além do caminho até a página.
	 * @return array
	 */
	private static function contexto( $pagina, $abertos = array() ) {
		$mapa  = self::mapa_visivel();
		$atual = $pagina instanceof WP_Post ? (int) $pagina->ID : 0;

		// A cópia do mapa, e não o objeto da consulta principal. Num rascunho, o
		// `WP_Query` marca a requisição como prévia e **sobrescreve** o
		// `post_date` do post consultado com a hora corrente
		// (`class-wp-query.php`, ramo `protected`) — a linha de autor diria
		// "criada hoje" para uma página de semanas. As instâncias do mapa vêm de
		// outra consulta e trazem a data gravada.
		if ( $atual && isset( $mapa[ $atual ] ) ) {
			$pagina = $mapa[ $atual ];
		}

		$caminho = $atual ? self::ancestrais_visiveis( $pagina, $mapa ) : array();

		return array(
			'pagina'   => $pagina,
			'mapa'     => $mapa,
			'filhos'   => self::agrupar_por_mae( $mapa ),
			'atual'    => $atual,
			'caminho'  => $caminho,
			'abertos'  => array_values( array_unique( array_merge( array_map( 'intval', wp_list_pluck( $caminho, 'ID' ) ), array_map( 'intval', (array) $abertos ) ) ) ),
			'url_raiz' => self::url_da_ancora(),
		);
	}

	/**
	 * Imprime um nível da árvore lateral e, por recursão, os de baixo.
	 *
	 * O nível vem do chamador, e não de uma pilha de visitados, porque a
	 * árvore desce a partir da raiz por `agrupar_por_mae()`: um ciclo de
	 * `post_parent` nunca é alcançado a partir do zero, e o teto de
	 * `PROFUNDIDADE_MAXIMA` corta o que sobrar.
	 *
	 * @param int   $mae      ID da mãe do nível; 0 para a raiz.
	 * @param array $contexto Contexto montado em `renderizar()`.
	 * @param int   $nivel    Profundidade do nível, a partir de 1.
	 * @return void
	 */
	public static function imprimir_ramo( $mae, $contexto, $nivel = 1 ) {
		if ( empty( $contexto['filhos'][ $mae ] ) || $nivel > Reconectar_Incubadora::PROFUNDIDADE_MAXIMA + 1 ) {
			return;
		}

		$abertos = $contexto['abertos'];

		include RECONECTAR_CORE_PATH . 'includes/incubadora/arvore.php';
	}

	/**
	 * HTML do conteúdo de uma página, pronto para a leitura.
	 *
	 * Delegado a `Reconectar_Incubadora_Conteudo::html_de_leitura()`, que
	 * sanitiza de novo o que está gravado e troca o marcador de vídeo pela
	 * facade. Sem `the_content`: os filtros de terceiros que pendurariam ali
	 * (embed automático, compartilhamento, `wpautop`, shortcode) desfariam a
	 * reconstrução — um `[shortcode]` escrito por quem edita a Incubadora
	 * rodaria com os privilégios de quem lê.
	 *
	 * @param WP_Post $pagina Página.
	 * @return string
	 */
	public static function conteudo( $pagina ) {
		return Reconectar_Incubadora_Conteudo::html_de_leitura( $pagina->post_content );
	}

	/**
	 * ID de quem fez a última edição, ou do autor se ninguém editou depois.
	 *
	 * `_edit_last` só é gravado pelo núcleo na tela de edição do `/wp-admin`
	 * (`wp-admin/includes/post.php`), não em `wp_update_post()`; aqui quem o
	 * grava é `Reconectar_Incubadora_Acoes::gravar()`. Uma página criada por
	 * WP-CLI não o tem.
	 *
	 * @param WP_Post $pagina Página.
	 * @return int
	 */
	public static function editado_por( $pagina ) {
		$ultimo = (int) get_post_meta( $pagina->ID, '_edit_last', true );

		return $ultimo ? $ultimo : (int) $pagina->post_author;
	}

	/**
	 * Todas as páginas que o usuário corrente pode ver, indexadas por ID.
	 *
	 * Uma consulta só para a árvore inteira: a recursão por nível faria uma por
	 * página com filhas, e uma wiki de 200 páginas viraria 200 consultas a cada
	 * leitura. Sem cache de meta e de termos, que a árvore não lê.
	 *
	 * Guardada por requisição: o 404 de `exigir_caminho_visivel()`, o
	 * redirecionamento da âncora e a tela consultam o mesmo mapa. A chave é a
	 * visibilidade de rascunho, que é o único dado do usuário que a consulta lê.
	 *
	 * @return WP_Post[]
	 */
	public static function mapa_visivel() {
		static $guardados = array();

		$ve_rascunho = self::ve_rascunho();

		if ( isset( $guardados[ (int) $ve_rascunho ] ) ) {
			return $guardados[ (int) $ve_rascunho ];
		}

		$situacoes = $ve_rascunho ? array( 'publish', 'draft' ) : array( 'publish' );

		$paginas = get_posts(
			array(
				'post_type'              => Reconectar_Incubadora::POST_TYPE,
				'post_status'            => $situacoes,
				'posts_per_page'         => -1,
				'orderby'                => array(
					'menu_order' => 'ASC',
					'title'      => 'ASC',
				),
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
				'suppress_filters'       => false,
			)
		);

		$mapa = array();

		foreach ( $paginas as $item ) {
			$mapa[ (int) $item->ID ] = $item;
		}

		$guardados[ (int) $ve_rascunho ] = $mapa;

		return $mapa;
	}

	/**
	 * Agrupa as páginas visíveis pelo ID da mãe, preservando a ordem da consulta.
	 *
	 * @param WP_Post[] $mapa Páginas visíveis, por ID.
	 * @return array<int, WP_Post[]>
	 */
	public static function agrupar_por_mae( $mapa ) {
		$filhos = array();

		foreach ( $mapa as $item ) {
			$filhos[ (int) $item->post_parent ][] = $item;
		}

		return $filhos;
	}

	/**
	 * Ancestrais da página, da raiz para baixo, só os que o usuário enxerga.
	 *
	 * `get_post_ancestors()` sobe por `post_parent` sem olhar a situação, e
	 * imprimir o título de uma mãe em rascunho na trilha de um comprador seria
	 * vazar o que ainda não foi publicado. O corte em `PROFUNDIDADE_MAXIMA` é
	 * defesa contra ciclo de `post_parent`: o núcleo já para no primeiro ID
	 * repetido, mas não tem teto de altura.
	 *
	 * @param WP_Post   $pagina Página aberta.
	 * @param WP_Post[] $mapa   Páginas visíveis, por ID.
	 * @return WP_Post[]
	 */
	public static function ancestrais_visiveis( $pagina, $mapa ) {
		$ids = array_slice( get_post_ancestors( $pagina ), 0, Reconectar_Incubadora::PROFUNDIDADE_MAXIMA );

		$ancestrais = array();

		foreach ( array_reverse( $ids ) as $id ) {
			if ( isset( $mapa[ (int) $id ] ) ) {
				$ancestrais[] = $mapa[ (int) $id ];
			}
		}

		return $ancestrais;
	}

	/**
	 * Diz se a página e toda a cadeia de mães até a raiz estão visíveis.
	 *
	 * A árvore percorre a partir da raiz, então uma filha publicada sob mãe em
	 * rascunho não aparece nela para quem lê só o publicado. A página isolada
	 * segue a mesma regra: abri-la pela URL mostraria uma trilha com buraco e
	 * uma página que a árvore diz não existir. Para o comprador ela é 404 até a
	 * mãe ser publicada.
	 *
	 * @param WP_Post   $pagina Página pedida.
	 * @param WP_Post[] $mapa   Páginas visíveis, por ID.
	 * @return bool
	 */
	public static function alcancavel_pela_raiz( $pagina, $mapa ) {
		$id        = (int) $pagina->ID;
		$visitados = array();

		for ( $nivel = 0; $nivel <= Reconectar_Incubadora::PROFUNDIDADE_MAXIMA; $nivel++ ) {
			if ( ! isset( $mapa[ $id ] ) || isset( $visitados[ $id ] ) ) {
				return false;
			}

			$visitados[ $id ] = true;
			$mae              = (int) $mapa[ $id ]->post_parent;

			if ( 0 === $mae ) {
				return true;
			}

			$id = $mae;
		}

		return false;
	}

	/**
	 * Formata a data local de um post no padrão do site.
	 *
	 * @param WP_Post $pagina Página.
	 * @param string  $campo  `post_date` ou `post_modified`.
	 * @return string
	 */
	public static function data_local( $pagina, $campo ) {
		$data = 'post_modified' === $campo ? get_post_modified_time( 'U', false, $pagina ) : get_post_time( 'U', false, $pagina );

		return date_i18n( get_option( 'date_format' ), (int) $data );
	}

	/**
	 * Nome público de um usuário, ou um rótulo neutro se a conta não existir.
	 *
	 * Conta removida deixa o ID gravado em `post_author` e em `_edit_last`; sem
	 * este recuo a linha sairia com o nome vazio.
	 *
	 * @param int $usuario_id ID do usuário.
	 * @return string
	 */
	public static function nome_de_usuario( $usuario_id ) {
		$usuario = $usuario_id ? get_userdata( (int) $usuario_id ) : false;

		return $usuario ? $usuario->display_name : __( 'Conta removida', 'reconectar-core' );
	}
}
