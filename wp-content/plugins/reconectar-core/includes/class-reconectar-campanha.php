<?php
/**
 * A entidade Campanha: o banner da home, com vigência.
 *
 * `inc/home/secao-ofertas.php` registra que a faixa de ofertas ocupou de
 * propósito o lugar dos banners promocionais — "banner é peça publicitária,
 * exige arte por campanha e envelhece". Esta classe reverte metade daquela
 * decisão, e só metade: a campanha volta como **conteúdo gerenciado**, não como
 * HTML solto numa área de widget.
 *
 * A diferença está na vigência. Um widget com imagem depende de alguém lembrar
 * de apagá-lo no dia seguinte ao fim da promoção; uma campanha com data de fim
 * some sozinha. É isso que justifica um post type em vez de um campo de texto no
 * Customizer — e é a razão de `_reconectar_campanha_fim` não ser opcional por
 * conveniência, mas o motivo de a entidade existir.
 *
 * O texto alternativo é obrigatório porque banner é imagem com função: ele leva
 * a algum lugar. WCAG 2.1 é requisito do edital, e uma imagem clicável sem
 * alternativa textual é um link sem nome acessível.
 *
 * @package reconectar-core
 */

defined( 'ABSPATH' ) || exit;

class Reconectar_Campanha {

	/**
	 * Post type da campanha.
	 */
	const POST_TYPE = 'reconectar_campanha';

	/**
	 * Prefixo das metas.
	 */
	const PREFIXO_META = '_reconectar_campanha_';

	/**
	 * Destino do clique no banner.
	 */
	const META_LINK = '_reconectar_campanha_link';

	/**
	 * Primeiro dia de exibição, em `Y-m-d`. Vazio: vale desde sempre.
	 */
	const META_INICIO = '_reconectar_campanha_inicio';

	/**
	 * Último dia de exibição, em `Y-m-d`. Vazio: não expira.
	 */
	const META_FIM = '_reconectar_campanha_fim';

	/**
	 * Posição na faixa. Menor primeiro.
	 */
	const META_ORDEM = '_reconectar_campanha_ordem';

	/**
	 * Texto alternativo da arte.
	 */
	const META_ALT = '_reconectar_campanha_alt';

	/**
	 * Nonce do formulário do metabox.
	 */
	const NONCE = 'reconectar_campanha_nonce';

	/**
	 * Registra os ganchos da entidade.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'init', array( __CLASS__, 'registrar_post_type' ) );
		add_action( 'init', array( __CLASS__, 'registrar_metas' ) );
		add_action( 'after_setup_theme', array( __CLASS__, 'garantir_suporte_a_miniatura' ), 20 );
		add_action( 'add_meta_boxes', array( __CLASS__, 'registrar_metabox' ) );
		add_action( 'save_post_' . self::POST_TYPE, array( __CLASS__, 'salvar_metas' ), 10, 2 );
	}

	/* ---------------------------------------------------------------------
	 * Estrutura de dados
	 * ------------------------------------------------------------------ */

	/**
	 * Registra o post type da campanha.
	 *
	 * `public => false` porque campanha não tem página própria: ela **é** um link
	 * para outro lugar, e publicar uma URL canônica para o banner criaria uma tela
	 * com uma imagem e nada mais, indexável, que ninguém pediu.
	 *
	 * O bloco `capabilities` segue o de `Reconectar_Empresa::registrar_post_type()`
	 * — só primitivas, nunca meta cap. O comentário longo lá
	 * (`class-reconectar-empresa.php:172`) explica o defeito que apontar uma meta
	 * cap para a capacidade autoral provoca: `_doing_it_wrong()` impresso dentro do
	 * `admin_menu` derruba em silêncio todo `wp_safe_redirect()` de `admin_init`, e
	 * seis casos do `verificar-acessos.sh` caem de uma vez, nenhum deles em código
	 * de RBAC.
	 *
	 * @return void
	 */
	public static function registrar_post_type() {
		$labels = array(
			'name'               => __( 'Campanhas', 'reconectar-core' ),
			'singular_name'      => __( 'Campanha', 'reconectar-core' ),
			'add_new'            => __( 'Adicionar nova', 'reconectar-core' ),
			'add_new_item'       => __( 'Adicionar nova campanha', 'reconectar-core' ),
			'edit_item'          => __( 'Editar campanha', 'reconectar-core' ),
			'new_item'           => __( 'Nova campanha', 'reconectar-core' ),
			'view_item'          => __( 'Ver campanha', 'reconectar-core' ),
			'search_items'       => __( 'Buscar campanhas', 'reconectar-core' ),
			'not_found'          => __( 'Nenhuma campanha encontrada', 'reconectar-core' ),
			'not_found_in_trash' => __( 'Nenhuma campanha na lixeira', 'reconectar-core' ),
			'menu_name'          => __( 'Campanhas', 'reconectar-core' ),
			'featured_image'     => __( 'Arte da campanha', 'reconectar-core' ),
			'set_featured_image' => __( 'Definir a arte', 'reconectar-core' ),
		);

		$capacidade = Reconectar_Permissoes::CAP_GERIR_CAMPANHAS;

		register_post_type(
			self::POST_TYPE,
			array(
				'labels'              => $labels,
				'public'              => false,
				'show_ui'             => true,
				'show_in_menu'        => true,
				'show_in_rest'        => false,
				'menu_icon'           => 'dashicons-megaphone',
				'menu_position'       => 21,
				'supports'            => array( 'title', 'thumbnail' ),
				'has_archive'         => false,
				'rewrite'             => false,
				'exclude_from_search' => true,
				'map_meta_cap'        => true,
				'capabilities'        => array(
					'edit_posts'          => $capacidade,
					'edit_others_posts'   => $capacidade,
					'delete_posts'        => $capacidade,
					'delete_others_posts' => $capacidade,
					'publish_posts'       => $capacidade,
					'read_private_posts'  => $capacidade,
					'create_posts'        => $capacidade,
				),
			)
		);
	}

	/**
	 * Registra as metas da campanha.
	 *
	 * @return void
	 */
	public static function registrar_metas() {
		$autorizar = function () {
			return current_user_can( Reconectar_Permissoes::CAP_GERIR_CAMPANHAS );
		};

		$metas = array(
			self::META_LINK   => 'string',
			self::META_INICIO => 'string',
			self::META_FIM    => 'string',
			self::META_ALT    => 'string',
			self::META_ORDEM  => 'integer',
		);

		foreach ( $metas as $chave => $tipo ) {
			register_post_meta(
				self::POST_TYPE,
				$chave,
				array(
					'type'          => $tipo,
					'single'        => true,
					'default'       => 'integer' === $tipo ? 0 : '',
					'show_in_rest'  => false,
					'auth_callback' => $autorizar,
				)
			);
		}
	}

	/**
	 * Garante que a caixa de imagem destacada apareça, venha o tema que vier.
	 *
	 * O metabox de miniatura só é renderizado se o **tema** declarar
	 * `post-thumbnails`. O Storefront declara, mas o plugin não pode depender
	 * disso: sem a caixa, a campanha fica sem arte e a tela não diz por quê —
	 * o sintoma pior deste repositório, o de código no lugar certo que não faz
	 * nada.
	 *
	 * Prioridade 20 porque o `functions.php` do tema filho carrega **antes** do
	 * pai, e é em `after_setup_theme` que os ganchos do Storefront se estabilizam.
	 * O suporte é acrescentado só para este post type quando não existe suporte
	 * global — sobrescrever a lista do tema tiraria a miniatura de produto e post.
	 *
	 * @return void
	 */
	public static function garantir_suporte_a_miniatura() {
		if ( current_theme_supports( 'post-thumbnails' ) ) {
			return;
		}

		add_theme_support( 'post-thumbnails', array( self::POST_TYPE ) );
	}

	/* ---------------------------------------------------------------------
	 * Edição no /wp-admin
	 * ------------------------------------------------------------------ */

	/**
	 * Registra a caixa de campos da campanha.
	 *
	 * @return void
	 */
	public static function registrar_metabox() {
		add_meta_box(
			'reconectar-campanha-dados',
			__( 'Dados da campanha', 'reconectar-core' ),
			array( __CLASS__, 'imprimir_metabox' ),
			self::POST_TYPE,
			'normal',
			'high'
		);
	}

	/**
	 * Imprime o formulário da campanha.
	 *
	 * @param WP_Post $post Campanha em edição.
	 * @return void
	 */
	public static function imprimir_metabox( $post ) {
		wp_nonce_field( self::NONCE, self::NONCE );

		$link   = (string) get_post_meta( $post->ID, self::META_LINK, true );
		$inicio = (string) get_post_meta( $post->ID, self::META_INICIO, true );
		$fim    = (string) get_post_meta( $post->ID, self::META_FIM, true );
		$alt    = (string) get_post_meta( $post->ID, self::META_ALT, true );
		$ordem  = (int) get_post_meta( $post->ID, self::META_ORDEM, true );

		$campos = array(
			array(
				'chave'  => 'link',
				'rotulo' => __( 'Destino do clique', 'reconectar-core' ),
				'tipo'   => 'url',
				'valor'  => $link,
				'ajuda'  => __( 'Endereço completo, com https://. Em branco, o banner é exibido sem link.', 'reconectar-core' ),
			),
			array(
				'chave'  => 'alt',
				'rotulo' => __( 'Texto alternativo da arte', 'reconectar-core' ),
				'tipo'   => 'text',
				'valor'  => $alt,
				'ajuda'  => __( 'O que a arte comunica, para quem não a enxerga. Em branco, o título da campanha é usado.', 'reconectar-core' ),
			),
			array(
				'chave'  => 'inicio',
				'rotulo' => __( 'Exibir a partir de', 'reconectar-core' ),
				'tipo'   => 'date',
				'valor'  => $inicio,
				'ajuda'  => __( 'Em branco, vale desde já.', 'reconectar-core' ),
			),
			array(
				'chave'  => 'fim',
				'rotulo' => __( 'Exibir até', 'reconectar-core' ),
				'tipo'   => 'date',
				'valor'  => $fim,
				'ajuda'  => __( 'Em branco, a campanha não expira — e alguém vai precisar lembrar de despublicá-la.', 'reconectar-core' ),
			),
			array(
				'chave'  => 'ordem',
				'rotulo' => __( 'Ordem na faixa', 'reconectar-core' ),
				'tipo'   => 'number',
				'valor'  => (string) $ordem,
				'ajuda'  => __( 'Menor aparece primeiro.', 'reconectar-core' ),
			),
		);

		echo '<table class="form-table" role="presentation"><tbody>';

		foreach ( $campos as $campo ) {
			$id    = 'reconectar-campanha-' . $campo['chave'];
			$ajuda = $id . '-ajuda';

			printf(
				'<tr><th scope="row"><label for="%1$s">%2$s</label></th><td>'
					. '<input type="%3$s" id="%1$s" name="reconectar_campanha[%4$s]" value="%5$s" class="regular-text" aria-describedby="%6$s" />'
					. '<p class="description" id="%6$s">%7$s</p>'
					. '</td></tr>',
				esc_attr( $id ),
				esc_html( $campo['rotulo'] ),
				esc_attr( $campo['tipo'] ),
				esc_attr( $campo['chave'] ),
				esc_attr( $campo['valor'] ),
				esc_attr( $ajuda ),
				esc_html( $campo['ajuda'] )
			);
		}

		echo '</tbody></table>';
	}

	/**
	 * Grava as metas enviadas pelo metabox.
	 *
	 * @param int     $post_id ID da campanha.
	 * @param WP_Post $post    Campanha salva.
	 * @return void
	 */
	public static function salvar_metas( $post_id, $post ) {
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		$nonce = isset( $_POST[ self::NONCE ] ) ? sanitize_text_field( wp_unslash( $_POST[ self::NONCE ] ) ) : '';

		if ( ! wp_verify_nonce( $nonce, self::NONCE ) || ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		$enviado = isset( $_POST['reconectar_campanha'] ) ? (array) wp_unslash( $_POST['reconectar_campanha'] ) : array();
		$valor   = function ( $chave ) use ( $enviado ) {
			return isset( $enviado[ $chave ] ) ? sanitize_text_field( $enviado[ $chave ] ) : '';
		};

		update_post_meta( $post_id, self::META_LINK, esc_url_raw( $valor( 'link' ) ) );
		update_post_meta( $post_id, self::META_INICIO, self::normalizar_data( $valor( 'inicio' ) ) );
		update_post_meta( $post_id, self::META_FIM, self::normalizar_data( $valor( 'fim' ) ) );
		update_post_meta( $post_id, self::META_ORDEM, (int) $valor( 'ordem' ) );

		/*
		 * Alternativa em branco cai para o título. A arte é clicável: sem texto
		 * algum, o leitor de tela anuncia um link sem nome — e exigir o campo só no
		 * navegador (`required`) deixaria passar qualquer POST vindo de fora da
		 * tela, além da carga de demonstração e do WP-CLI, que não passam por
		 * formulário nenhum.
		 */
		$alt = $valor( 'alt' );
		update_post_meta( $post_id, self::META_ALT, '' !== $alt ? $alt : $post->post_title );
	}

	/**
	 * Aceita a data só no formato do `<input type="date">`.
	 *
	 * Qualquer outra coisa vira string vazia, que a vigência lê como "sem limite".
	 * Guardar `30/09/2026` faria a comparação de `vigentes()` — que é textual, em
	 * `Y-m-d` — devolver o resultado errado sem erro nenhum.
	 *
	 * @param string $valor Valor cru vindo do POST.
	 * @return string Data em `Y-m-d`, ou vazio.
	 */
	private static function normalizar_data( $valor ) {
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $valor ) ) {
			return '';
		}

		list( $ano, $mes, $dia ) = array_map( 'intval', explode( '-', $valor ) );

		return checkdate( $mes, $dia, $ano ) ? $valor : '';
	}

	/* ---------------------------------------------------------------------
	 * Consulta
	 * ------------------------------------------------------------------ */

	/**
	 * As campanhas publicadas e vigentes hoje, na ordem da faixa.
	 *
	 * A vigência e a ordenação são resolvidas **em PHP**, de propósito, e não por
	 * `meta_query` com `orderby`. As duas rotas óbvias do `WP_Query` falham, como
	 * registra o `CLAUDE.md`: `meta_key` + `orderby => 'meta_value'` monta INNER
	 * JOIN e some com quem não tem a meta; `meta_query` com `relation => 'OR'` e
	 * ramo `NOT EXISTS` traz todos de volta estragando a ordem. A alternativa que
	 * funciona — `posts_clauses` com LEFT JOIN próprio — só se paga em volume, e
	 * campanha vigente são poucas por definição.
	 *
	 * @param int $limite Teto de campanhas lidas do banco.
	 * @return WP_Post[] Ordenadas por `META_ORDEM`, menor primeiro.
	 */
	public static function vigentes( $limite = 20 ) {
		$campanhas = get_posts(
			array(
				'post_type'        => self::POST_TYPE,
				'post_status'      => 'publish',
				'numberposts'      => (int) $limite,
				'orderby'          => 'date',
				'order'            => 'DESC',
				'suppress_filters' => false,
			)
		);

		$hoje = current_time( 'Y-m-d' );

		$campanhas = array_values(
			array_filter(
				$campanhas,
				function ( $campanha ) use ( $hoje ) {
					return self::esta_vigente( $campanha->ID, $hoje );
				}
			)
		);

		usort(
			$campanhas,
			function ( $a, $b ) {
				$ordem_a = (int) get_post_meta( $a->ID, self::META_ORDEM, true );
				$ordem_b = (int) get_post_meta( $b->ID, self::META_ORDEM, true );

				// Empate resolvido pelo ID: sem critério de desempate, `usort` devolve
				// ordem instável e a faixa muda de arranjo a cada carregamento.
				return $ordem_a === $ordem_b ? $a->ID - $b->ID : $ordem_a - $ordem_b;
			}
		);

		return $campanhas;
	}

	/**
	 * A campanha está dentro da vigência nesta data?
	 *
	 * Comparação textual de `Y-m-d`, que é ordenável como string — e por isso a
	 * data passa por `normalizar_data()` antes de ser gravada.
	 *
	 * @param int    $campanha_id ID da campanha.
	 * @param string $hoje        Data de referência em `Y-m-d`.
	 * @return bool
	 */
	public static function esta_vigente( $campanha_id, $hoje = '' ) {
		$hoje   = '' !== $hoje ? $hoje : current_time( 'Y-m-d' );
		$inicio = (string) get_post_meta( $campanha_id, self::META_INICIO, true );
		$fim    = (string) get_post_meta( $campanha_id, self::META_FIM, true );

		if ( '' !== $inicio && $hoje < $inicio ) {
			return false;
		}

		if ( '' !== $fim && $hoje > $fim ) {
			return false;
		}

		return true;
	}

	/**
	 * Os dados de exibição de uma campanha, prontos para a tela.
	 *
	 * Devolve `null` quando falta a arte: banner sem imagem não é banner, e
	 * imprimir um bloco vazio no lugar seria inventar conteúdo — a regra de
	 * honestidade de dados do projeto.
	 *
	 * @param WP_Post|int $campanha Campanha ou ID.
	 * @return array|null Com `id`, `titulo`, `link`, `alt` e `imagem_id`.
	 */
	public static function dados( $campanha ) {
		$campanha = get_post( $campanha );

		if ( ! $campanha instanceof WP_Post || self::POST_TYPE !== $campanha->post_type ) {
			return null;
		}

		$imagem_id = (int) get_post_thumbnail_id( $campanha->ID );

		if ( ! $imagem_id ) {
			return null;
		}

		$alt = (string) get_post_meta( $campanha->ID, self::META_ALT, true );

		return array(
			'id'        => $campanha->ID,
			'titulo'    => get_the_title( $campanha ),
			'link'      => (string) get_post_meta( $campanha->ID, self::META_LINK, true ),
			'alt'       => '' !== $alt ? $alt : get_the_title( $campanha ),
			'imagem_id' => $imagem_id,
		);
	}
}
