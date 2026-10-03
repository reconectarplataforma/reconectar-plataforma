<?php
/**
 * O que acontece em volta do texto de uma página da Incubadora: o vídeo em
 * destaque, o "Gostei/Não gostei" e os comentários com respostas.
 *
 * O desenho é o de uma página de vídeo: o player grande no topo, o texto como
 * descrição, a avaliação logo abaixo e a conversa no pé. O vídeo é a função
 * principal do gerenciamento de conteúdo — por isso ele tem formulário próprio
 * na leitura, de um campo só, e não depende de abrir o editor.
 *
 * As quatro ações passam por `admin-post.php`, a rota já excetuada em
 * `Reconectar_Permissoes::bloquear_area_administrativa()`, e são formulários
 * comuns que voltam para a página: funcionam sem script. A divisão em handler
 * (transporte) e operação (domínio) é a de `Reconectar_Incubadora_Acoes`, e
 * pelo mesmo motivo — a operação se mede por WP-CLI, o handler não.
 *
 * **Comentários são comentários do núcleo, de tipo próprio.** A alternativa —
 * meta da página ou post type filho — teria de reinventar o aninhamento e a
 * contagem. O preço é que comentário do núcleo aparece em todo canto: feed de
 * comentários, widget de recentes, lista do `/wp-admin`, REST. A Incubadora é
 * fechada a quem não entrou, e um comentário dela no feed público seria
 * vazamento. Três travas cuidam disso:
 *
 * - `comments_open` responde `false` para o post type, e o
 *   `wp-comments-post.php` e o `POST /wp/v2/comments` recusam;
 * - `pre_get_comments` tira o tipo de **toda** consulta que não o peça pelo
 *   nome — é o que cobre widget, REST e a lista do painel;
 * - `comment_feed_where` faz o mesmo no feed, que monta a consulta à mão.
 *
 * O comentário oculto pelo moderador vai a `comment_approved = 'rc-oculto'`, e
 * não a `'0'`: o núcleo conta `'0'` como pendente, e o Moderador veria no
 * menu do `/wp-admin` um "1 comentário pendente" que nenhuma lista mostra.
 *
 * @package reconectar-core
 */

defined( 'ABSPATH' ) || exit;

/**
 * Vídeo em destaque, avaliação e comentários das páginas da Incubadora.
 */
class Reconectar_Incubadora_Interacao {

	/**
	 * Vídeo em destaque: `array( provedor, id, hash )`, já validado.
	 *
	 * Guardado em partes, e não como URL: é a forma que
	 * `Reconectar_Incubadora_Conteudo::video_valido()` confere de novo na
	 * leitura. Uma meta escrita por outro caminho não vira player sem passar
	 * por ela.
	 */
	const META_VIDEO = '_rc_incubadora_video';

	/**
	 * Quem avaliou e como: `array( user_id => 1|-1 )`. É a fonte da verdade.
	 */
	const META_AVALIACOES = '_rc_incubadora_avaliacoes';

	/**
	 * Total de "Gostei", derivado de `META_AVALIACOES`.
	 */
	const META_GOSTEI = '_rc_incubadora_gostei';

	/**
	 * Total de "Não gostei", derivado de `META_AVALIACOES`.
	 */
	const META_NAO_GOSTEI = '_rc_incubadora_nao_gostei';

	/**
	 * `comment_type` dos comentários da Incubadora.
	 */
	const TIPO_COMENTARIO = 'rc_incubadora';

	/**
	 * `comment_approved` de um comentário oculto pela moderação.
	 *
	 * A coluna é `varchar(20)`, e o `sql_mode` desta instalação não é estrito:
	 * um valor maior seria truncado em silêncio, como o `post_status`.
	 */
	const OCULTO = 'rc-oculto';

	/**
	 * Tamanho máximo de um comentário, em caracteres.
	 */
	const MAXIMO = 2000;

	/**
	 * Intervalo mínimo entre dois comentários da mesma pessoa, em segundos.
	 *
	 * Não é antispam — quem comenta já entrou e participa da comunidade. É o
	 * duplo clique no "Comentar" de uma conexão lenta, que sem isto gravaria o
	 * mesmo texto duas vezes.
	 */
	const INTERVALO = 10;

	/**
	 * Ações expostas, com o método que as processa.
	 *
	 * O nome completo sai de `Reconectar_Incubadora_Acoes::acao()`, com o mesmo
	 * prefixo das ações do editor, e nenhuma das chaves daqui repete uma de lá.
	 *
	 * @var array<string, string>
	 */
	const ACOES = array(
		'video'    => 'processar_video',
		'avaliar'  => 'processar_avaliar',
		'comentar' => 'processar_comentar',
		'moderar'  => 'processar_moderar',
	);

	/**
	 * Registra handlers e travas.
	 *
	 * O `nopriv` existe pela razão de `Reconectar_Incubadora_Acoes::init()`: sem
	 * ele o visitante receberia um 400 de corpo vazio, e não o 401 que diz o
	 * que fazer.
	 *
	 * @return void
	 */
	public static function init() {
		foreach ( self::ACOES as $acao => $metodo ) {
			add_action( 'admin_post_' . Reconectar_Incubadora_Acoes::acao( $acao ), array( __CLASS__, $metodo ) );
			add_action( 'admin_post_nopriv_' . Reconectar_Incubadora_Acoes::acao( $acao ), array( __CLASS__, $metodo ) );
		}

		add_filter( 'comments_open', array( __CLASS__, 'fechar_rotas_do_nucleo' ), 10, 2 );
		add_action( 'pre_get_comments', array( __CLASS__, 'esconder_das_consultas' ) );
		add_filter( 'comment_feed_where', array( __CLASS__, 'esconder_do_feed' ) );
	}

	/* ---------------------------------------------------------------------
	 * Travas contra vazamento
	 * ------------------------------------------------------------------ */

	/**
	 * Fecha os comentários da Incubadora às rotas do núcleo.
	 *
	 * Quem grava é `comentar()`, por `wp_insert_comment()`, que não consulta
	 * `comments_open()`. Com a resposta em `false`, o `wp-comments-post.php` e a
	 * REST recusam um comentário que pularia a capacidade e o limite de tamanho.
	 *
	 * @param bool $aberto  Resposta até aqui.
	 * @param int  $post_id Post consultado.
	 * @return bool
	 */
	public static function fechar_rotas_do_nucleo( $aberto, $post_id ) {
		return Reconectar_Incubadora::POST_TYPE === get_post_type( $post_id ) ? false : $aberto;
	}

	/**
	 * Tira os comentários da Incubadora de toda consulta que não os peça.
	 *
	 * Pedir é passar o tipo **pelo nome** em `type`. O padrão do núcleo é
	 * `all`, e é ele que o widget, a REST e a lista do `/wp-admin` usam.
	 *
	 * @param WP_Comment_Query $consulta Consulta em montagem.
	 * @return void
	 */
	public static function esconder_das_consultas( $consulta ) {
		$tipos = (array) $consulta->query_vars['type'];

		if ( in_array( self::TIPO_COMENTARIO, $tipos, true ) ) {
			return;
		}

		$fora = (array) $consulta->query_vars['type__not_in'];

		$consulta->query_vars['type__not_in'] = array_values( array_unique( array_merge( array_filter( $fora ), array( self::TIPO_COMENTARIO ) ) ) );
	}

	/**
	 * Tira os comentários da Incubadora do feed de comentários.
	 *
	 * O feed monta a própria consulta em `WP_Query::get_posts()`, sem passar
	 * por `WP_Comment_Query`, e por isso escapa de `esconder_das_consultas()`.
	 *
	 * @param string $where Cláusula montada.
	 * @return string
	 */
	public static function esconder_do_feed( $where ) {
		global $wpdb;

		return $where . $wpdb->prepare( " AND {$wpdb->comments}.comment_type <> %s", self::TIPO_COMENTARIO );
	}

	/* ---------------------------------------------------------------------
	 * Leitura
	 * ------------------------------------------------------------------ */

	/**
	 * O vídeo em destaque da página, validado de novo, ou `null`.
	 *
	 * @param int $pagina_id Página.
	 * @return array{provedor: string, id: string, hash: string}|null
	 */
	public static function video( $pagina_id ) {
		$video = get_post_meta( (int) $pagina_id, self::META_VIDEO, true );

		if ( ! is_array( $video ) || ! isset( $video['provedor'], $video['id'] ) ) {
			return null;
		}

		return Reconectar_Incubadora_Conteudo::video_valido( (string) $video['provedor'], (string) $video['id'], isset( $video['hash'] ) ? (string) $video['hash'] : '' );
	}

	/**
	 * O endereço do vídeo no provedor, para preencher o campo de quem edita.
	 *
	 * @param int $pagina_id Página.
	 * @return string
	 */
	public static function url_do_video( $pagina_id ) {
		$video = self::video( $pagina_id );

		return $video ? Reconectar_Incubadora_Conteudo::url_no_provedor( $video ) : '';
	}

	/**
	 * Os dois totais da avaliação.
	 *
	 * @param int $pagina_id Página.
	 * @return array{gostei: int, nao_gostei: int}
	 */
	public static function totais( $pagina_id ) {
		return array(
			'gostei'     => (int) get_post_meta( (int) $pagina_id, self::META_GOSTEI, true ),
			'nao_gostei' => (int) get_post_meta( (int) $pagina_id, self::META_NAO_GOSTEI, true ),
		);
	}

	/**
	 * Como o usuário avaliou a página: `1`, `-1` ou `0`.
	 *
	 * @param int $pagina_id  Página.
	 * @param int $usuario_id Usuário; `0` usa o atual.
	 * @return int
	 */
	public static function avaliacao_do_usuario( $pagina_id, $usuario_id = 0 ) {
		$usuario_id  = $usuario_id ? (int) $usuario_id : get_current_user_id();
		$avaliacoes  = self::avaliacoes( $pagina_id );

		return $usuario_id && isset( $avaliacoes[ $usuario_id ] ) ? (int) $avaliacoes[ $usuario_id ] : 0;
	}

	/**
	 * Diz se o usuário corrente participa: avalia e comenta.
	 *
	 * A mesma capacidade do fórum e da comunidade. O comprador lê a página e a
	 * conversa, e não escreve nela.
	 *
	 * @return bool
	 */
	public static function pode_participar() {
		return current_user_can( Reconectar_Permissoes::CAP_COMUNIDADE );
	}

	/**
	 * Diz se o usuário corrente modera os comentários: oculta, mostra e exclui.
	 *
	 * Quem escreve a Incubadora cuida da conversa dela — Moderador,
	 * Administrador e Super Administrador.
	 *
	 * @return bool
	 */
	public static function pode_moderar() {
		return current_user_can( Reconectar_Permissoes::CAP_GERIR_INCUBADORA );
	}

	/**
	 * Diz se o usuário corrente pode excluir este comentário.
	 *
	 * Além da moderação, quem escreveu exclui o que escreveu.
	 *
	 * @param WP_Comment $comentario Comentário.
	 * @return bool
	 */
	public static function pode_excluir( $comentario ) {
		return self::pode_moderar() || ( is_user_logged_in() && (int) $comentario->user_id === get_current_user_id() );
	}

	/**
	 * A conversa da página: comentários de primeiro nível, cada um com as respostas.
	 *
	 * Comentários do mais novo para o mais antigo, respostas na ordem em que
	 * foram escritas — a ordem de uma página de vídeo, em que a conversa nova
	 * fica no alto e a resposta se lê como diálogo.
	 *
	 * Quem modera vê os ocultos, marcados; os outros não veem o oculto nem as
	 * respostas dele, porque resposta solta, sem a pergunta, não se entende.
	 *
	 * @param int $pagina_id Página.
	 * @return array<int, array{comentario: WP_Comment, respostas: WP_Comment[]}>
	 */
	public static function conversa( $pagina_id ) {
		$comentarios = get_comments(
			array(
				'post_id' => (int) $pagina_id,
				'type'    => self::TIPO_COMENTARIO,
				'status'  => self::pode_moderar() ? array( '1', self::OCULTO ) : '1',
				'orderby' => array( 'comment_date_gmt', 'comment_ID' ),
				'order'   => 'ASC',
			)
		);

		$fios      = array();
		$respostas = array();

		foreach ( $comentarios as $comentario ) {
			if ( (int) $comentario->comment_parent ) {
				$respostas[ (int) $comentario->comment_parent ][] = $comentario;
			} else {
				$fios[ (int) $comentario->comment_ID ] = array(
					'comentario' => $comentario,
					'respostas'  => array(),
				);
			}
		}

		foreach ( $respostas as $mae_id => $lista ) {
			if ( isset( $fios[ $mae_id ] ) ) {
				$fios[ $mae_id ]['respostas'] = $lista;
			}
		}

		return array_reverse( $fios );
	}

	/**
	 * Quantos comentários a conversa tem à vista do usuário corrente.
	 *
	 * @param array $conversa Retorno de `conversa()`.
	 * @return int
	 */
	public static function contar( $conversa ) {
		$total = 0;

		foreach ( $conversa as $fio ) {
			$total += 1 + count( $fio['respostas'] );
		}

		return $total;
	}

	/**
	 * Diz se o comentário está oculto.
	 *
	 * @param WP_Comment $comentario Comentário.
	 * @return bool
	 */
	public static function oculto( $comentario ) {
		return self::OCULTO === $comentario->comment_approved;
	}

	/**
	 * Texto do comentário pronto para a tela.
	 *
	 * Gravado como texto puro, e escapado na saída: comentário não tem HTML.
	 *
	 * @param WP_Comment $comentario Comentário.
	 * @return string
	 */
	public static function texto( $comentario ) {
		return nl2br( esc_html( $comentario->comment_content ) );
	}

	/**
	 * Nome de quem comentou, pelo usuário e não pelo campo gravado.
	 *
	 * O campo gravado congela o nome do dia do comentário; a loja que trocar o
	 * nome de exibição apareceria com dois nomes na mesma conversa.
	 *
	 * @param WP_Comment $comentario Comentário.
	 * @return string
	 */
	public static function autor( $comentario ) {
		$usuario = (int) $comentario->user_id ? get_userdata( (int) $comentario->user_id ) : false;

		return $usuario ? $usuario->display_name : __( 'Conta removida', 'reconectar-core' );
	}

	/* ---------------------------------------------------------------------
	 * Operações
	 * ------------------------------------------------------------------ */

	/**
	 * Define, troca ou remove o vídeo em destaque.
	 *
	 * Só YouTube: é a função pedida, e o player do topo é desenhado para ele.
	 * Vimeo continua aceito **no texto**, pelo editor. Endereço vazio remove.
	 *
	 * @param int    $pagina_id Página.
	 * @param string $url       Endereço do vídeo, ou vazio.
	 * @return array|WP_Error
	 */
	public static function definir_video( $pagina_id, $url ) {
		$pagina = self::pagina( $pagina_id );

		if ( is_wp_error( $pagina ) ) {
			return $pagina;
		}

		if ( ! current_user_can( 'edit_post', $pagina->ID ) ) {
			return self::erro( 'capacidade', __( 'Você não tem permissão para alterar esta página.', 'reconectar-core' ), 403 );
		}

		$url = trim( (string) $url );

		if ( '' === $url ) {
			delete_post_meta( $pagina->ID, self::META_VIDEO );

			return array(
				'codigo' => 'video_removido',
				'pagina' => (int) $pagina->ID,
			);
		}

		$video = Reconectar_Incubadora_Conteudo::identificar_video( $url );

		if ( ! $video || 'youtube' !== $video['provedor'] ) {
			return self::erro( 'video_invalido', __( 'Cole o endereço de um vídeo do YouTube — da barra de endereços ou do botão "Compartilhar".', 'reconectar-core' ), 422 );
		}

		update_post_meta( $pagina->ID, self::META_VIDEO, $video );
		update_post_meta( $pagina->ID, '_edit_last', get_current_user_id() );

		return array(
			'codigo' => 'video_definido',
			'pagina' => (int) $pagina->ID,
			'video'  => $video,
		);
	}

	/**
	 * Registra, troca ou desfaz uma avaliação.
	 *
	 * Avaliar de novo no mesmo sentido desfaz, como em todo botão de "Gostei".
	 * Os totais são recontados da lista, e não somados ao guardado — a razão
	 * registrada em `Reconectar_Forum::votar()`.
	 *
	 * @param int $pagina_id Página.
	 * @param int $sentido   `1` ou `-1`.
	 * @return array|WP_Error
	 */
	public static function avaliar( $pagina_id, $sentido ) {
		$pagina = self::pagina( $pagina_id, true );

		if ( is_wp_error( $pagina ) ) {
			return $pagina;
		}

		if ( ! self::pode_participar() ) {
			return self::erro( 'capacidade', __( 'Só quem participa da comunidade avalia as páginas da Incubadora.', 'reconectar-core' ), 403 );
		}

		$usuario_id = get_current_user_id();
		$sentido    = 0 > (int) $sentido ? -1 : 1;
		$avaliacoes = self::avaliacoes( $pagina->ID );
		$anterior   = isset( $avaliacoes[ $usuario_id ] ) ? (int) $avaliacoes[ $usuario_id ] : 0;

		if ( $anterior === $sentido ) {
			unset( $avaliacoes[ $usuario_id ] );
		} else {
			$avaliacoes[ $usuario_id ] = $sentido;
		}

		$contagem = array_count_values( array_map( 'intval', $avaliacoes ) );

		if ( empty( $avaliacoes ) ) {
			delete_post_meta( $pagina->ID, self::META_AVALIACOES );
		} else {
			update_post_meta( $pagina->ID, self::META_AVALIACOES, $avaliacoes );
		}

		update_post_meta( $pagina->ID, self::META_GOSTEI, isset( $contagem[1] ) ? $contagem[1] : 0 );
		update_post_meta( $pagina->ID, self::META_NAO_GOSTEI, isset( $contagem[-1] ) ? $contagem[-1] : 0 );

		return array_merge(
			array(
				'codigo'    => 'avaliada',
				'pagina'    => (int) $pagina->ID,
				'avaliacao' => isset( $avaliacoes[ $usuario_id ] ) ? (int) $avaliacoes[ $usuario_id ] : 0,
			),
			self::totais( $pagina->ID )
		);
	}

	/**
	 * Grava um comentário ou uma resposta.
	 *
	 * Um nível de resposta só, como numa página de vídeo: responder a uma
	 * resposta pendura o texto no comentário de cima. Árvore funda, numa
	 * coluna de celular, vira escada.
	 *
	 * Sem e-mail e sem IP no registro: o usuário já está identificado pelo
	 * `user_id`, e guardar os dois seria dado pessoal sem uso — a LGPD conta.
	 *
	 * @param int    $pagina_id Página.
	 * @param string $texto     Texto, como chegou.
	 * @param int    $mae_id    Comentário respondido, ou 0.
	 * @return array|WP_Error
	 */
	public static function comentar( $pagina_id, $texto, $mae_id = 0 ) {
		$pagina = self::pagina( $pagina_id, true );

		if ( is_wp_error( $pagina ) ) {
			return $pagina;
		}

		if ( ! self::pode_participar() ) {
			return self::erro( 'capacidade', __( 'Só quem participa da comunidade comenta nas páginas da Incubadora.', 'reconectar-core' ), 403 );
		}

		$texto = trim( sanitize_textarea_field( (string) $texto ) );

		if ( '' === $texto ) {
			return self::erro( 'vazio', __( 'Escreva o comentário antes de enviar.', 'reconectar-core' ), 422 );
		}

		if ( mb_strlen( $texto ) > self::MAXIMO ) {
			/* translators: %s: número máximo de caracteres. */
			return self::erro( 'longo', sprintf( __( 'O comentário passa de %s caracteres. Encurte-o e envie de novo.', 'reconectar-core' ), number_format_i18n( self::MAXIMO ) ), 422 );
		}

		$mae_id = (int) $mae_id;

		if ( $mae_id ) {
			$mae = get_comment( $mae_id );

			// Responder a uma resposta grava no fio do comentário de topo, e o
			// topo passa pela mesma conferência. Sem isso, uma resposta visível
			// abria caminho para escrever num fio oculto — medido: a Loja
			// respondeu ao 343 e o texto entrou sob o 342, que ela não enxerga.
			if ( $mae && (int) $mae->comment_parent ) {
				$mae = get_comment( (int) $mae->comment_parent );
			}

			// A mesma resposta para inexistente, de outra página e oculto: a
			// diferença diria a quem tenta IDs o que existe no banco.
			if ( ! $mae || (int) $mae->comment_post_ID !== (int) $pagina->ID || self::TIPO_COMENTARIO !== $mae->comment_type || '1' !== (string) $mae->comment_approved ) {
				return self::erro( 'mae_inexistente', __( 'O comentário que você respondia não está mais disponível.', 'reconectar-core' ), 404 );
			}

			$mae_id = (int) $mae->comment_ID;
		}

		$usuario = wp_get_current_user();
		$chave   = 'rc_incubadora_comentou_' . $usuario->ID;

		if ( get_transient( $chave ) ) {
			return self::erro( 'repetido', __( 'Seu comentário anterior acabou de ser enviado. Aguarde alguns segundos antes do próximo.', 'reconectar-core' ), 429 );
		}

		$id = wp_insert_comment(
			wp_slash(
				array(
					'comment_post_ID'      => (int) $pagina->ID,
					'comment_parent'       => $mae_id,
					'comment_type'         => self::TIPO_COMENTARIO,
					'comment_approved'     => 1,
					'comment_content'      => $texto,
					'comment_author'       => $usuario->display_name,
					'comment_author_email' => '',
					'comment_author_url'   => '',
					'comment_author_IP'    => '',
					'comment_agent'        => '',
					'user_id'              => (int) $usuario->ID,
				)
			)
		);

		if ( ! $id ) {
			return self::erro( 'servidor', __( 'Não foi possível gravar o comentário. Tente de novo.', 'reconectar-core' ), 500 );
		}

		set_transient( $chave, 1, self::INTERVALO );

		return array(
			'codigo'     => 'comentado',
			'pagina'     => (int) $pagina->ID,
			'comentario' => (int) $id,
			'mae'        => $mae_id,
		);
	}

	/**
	 * Oculta, mostra ou exclui um comentário.
	 *
	 * Excluir leva as respostas junto. O `wp_delete_comment()` do núcleo as
	 * penduraria na avó — que aqui é a página —, e a resposta viraria
	 * comentário solto, sem a pergunta que ela responde.
	 *
	 * @param int    $comentario_id Comentário.
	 * @param string $operacao      `ocultar`, `mostrar` ou `excluir`.
	 * @return array|WP_Error
	 */
	public static function moderar( $comentario_id, $operacao ) {
		$comentario = get_comment( (int) $comentario_id );

		if ( ! $comentario || self::TIPO_COMENTARIO !== $comentario->comment_type || ! in_array( (string) $comentario->comment_approved, array( '1', self::OCULTO ), true ) ) {
			return self::erro( 'inexistente', __( 'O comentário não existe mais.', 'reconectar-core' ), 404 );
		}

		$pagina_id = (int) $comentario->comment_post_ID;

		if ( 'excluir' === $operacao ) {
			if ( ! self::pode_excluir( $comentario ) ) {
				return self::erro( 'capacidade', __( 'Você não tem permissão para excluir este comentário.', 'reconectar-core' ), 403 );
			}

			$respostas = get_comments(
				array(
					'parent' => (int) $comentario->comment_ID,
					'type'   => self::TIPO_COMENTARIO,
					'status' => 'all',
					'fields' => 'ids',
				)
			);

			// `status => 'all'` não alcança o oculto, que é status próprio.
			$ocultas = get_comments(
				array(
					'parent' => (int) $comentario->comment_ID,
					'type'   => self::TIPO_COMENTARIO,
					'status' => self::OCULTO,
					'fields' => 'ids',
				)
			);

			foreach ( array_merge( $respostas, $ocultas ) as $resposta_id ) {
				wp_delete_comment( (int) $resposta_id, true );
			}

			wp_delete_comment( (int) $comentario->comment_ID, true );

			return array(
				'codigo' => 'excluido',
				'pagina' => $pagina_id,
			);
		}

		if ( ! in_array( $operacao, array( 'ocultar', 'mostrar' ), true ) ) {
			return self::erro( 'operacao', __( 'Operação desconhecida.', 'reconectar-core' ), 400 );
		}

		if ( ! self::pode_moderar() ) {
			return self::erro( 'capacidade', __( 'Só a moderação oculta comentários.', 'reconectar-core' ), 403 );
		}

		wp_update_comment(
			array(
				'comment_ID'       => (int) $comentario->comment_ID,
				'comment_approved' => 'ocultar' === $operacao ? self::OCULTO : '1',
			)
		);

		return array(
			'codigo'     => 'ocultar' === $operacao ? 'ocultado' : 'mostrado',
			'pagina'     => $pagina_id,
			'comentario' => (int) $comentario->comment_ID,
		);
	}

	/* ---------------------------------------------------------------------
	 * Handlers
	 * ------------------------------------------------------------------ */

	/**
	 * Handler do vídeo em destaque.
	 *
	 * @return void
	 */
	public static function processar_video() {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- conferido em `exigir_requisicao()`.
		$pagina_id = isset( $_POST['pagina'] ) ? absint( $_POST['pagina'] ) : 0;

		self::exigir_requisicao( 'video', Reconectar_Permissoes::CAP_GERIR_INCUBADORA, $pagina_id );

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- conferido em `exigir_requisicao()`.
		$url = isset( $_POST['remover'] ) ? '' : ( isset( $_POST['video'] ) ? esc_url_raw( wp_unslash( $_POST['video'] ) ) : '' );
		// phpcs:enable

		self::voltar( self::definir_video( $pagina_id, $url ), 'rc-incubadora-video' );
	}

	/**
	 * Handler do "Gostei/Não gostei".
	 *
	 * @return void
	 */
	public static function processar_avaliar() {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- conferido em `exigir_requisicao()`.
		$pagina_id = isset( $_POST['pagina'] ) ? absint( $_POST['pagina'] ) : 0;

		self::exigir_requisicao( 'avaliar', Reconectar_Permissoes::CAP_COMUNIDADE, $pagina_id );

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- conferido em `exigir_requisicao()`.
		$sentido = isset( $_POST['sentido'] ) && '-1' === (string) wp_unslash( $_POST['sentido'] ) ? -1 : 1;

		self::voltar( self::avaliar( $pagina_id, $sentido ), 'rc-incubadora-avaliacao' );
	}

	/**
	 * Handler do comentário e da resposta.
	 *
	 * @return void
	 */
	public static function processar_comentar() {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- conferido em `exigir_requisicao()`.
		$pagina_id = isset( $_POST['pagina'] ) ? absint( $_POST['pagina'] ) : 0;

		self::exigir_requisicao( 'comentar', Reconectar_Permissoes::CAP_COMUNIDADE, $pagina_id );

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- conferido em `exigir_requisicao()`.
		$resultado = self::comentar(
			$pagina_id,
			isset( $_POST['texto'] ) ? wp_unslash( $_POST['texto'] ) : '', // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitizado em `comentar()`.
			isset( $_POST['mae'] ) ? absint( $_POST['mae'] ) : 0
		);
		// phpcs:enable

		$ancora = ! is_wp_error( $resultado ) ? 'rc-comentario-' . $resultado['comentario'] : 'rc-incubadora-comentarios';

		self::voltar( $resultado, $ancora );
	}

	/**
	 * Handler da moderação.
	 *
	 * A capacidade conferida no transporte é a de participar, e não a de
	 * moderar: quem escreveu também exclui o próprio comentário. A distinção
	 * entre ocultar e excluir é de `moderar()`.
	 *
	 * @return void
	 */
	public static function processar_moderar() {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- conferido em `exigir_requisicao()`.
		$comentario_id = isset( $_POST['comentario'] ) ? absint( $_POST['comentario'] ) : 0;

		self::exigir_requisicao( 'moderar', Reconectar_Permissoes::CAP_COMUNIDADE, $comentario_id );

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- conferido em `exigir_requisicao()`.
		$operacao = isset( $_POST['operacao'] ) ? sanitize_key( wp_unslash( $_POST['operacao'] ) ) : '';

		self::voltar( self::moderar( $comentario_id, $operacao ), 'rc-incubadora-comentarios' );
	}

	/**
	 * Ação do nonce de um formulário: a ação mais o ID do alvo.
	 *
	 * Um nonce por página ou por comentário, ao contrário do editor: aqui o
	 * alvo existe sempre, e o nonce de um não serve para o outro.
	 *
	 * @param string $acao Chave de `ACOES`.
	 * @param int    $alvo ID da página ou do comentário.
	 * @return string
	 */
	public static function acao_do_nonce( $acao, $alvo ) {
		return Reconectar_Incubadora_Acoes::acao( $acao ) . '_' . (int) $alvo;
	}

	/**
	 * Imprime os campos ocultos comuns de um formulário de interação.
	 *
	 * O nonce sai à mão, e não por `wp_nonce_field()`: aquela função imprime
	 * `id="_wpnonce"`, e com um formulário por comentário a página teria o
	 * mesmo `id` dezenas de vezes — reprovado no critério 4.1.1 da WCAG 2.1.
	 * `wp_referer_field()` não leva `id` e é o que `voltar()` lê.
	 *
	 * @param string $acao Chave de `ACOES`.
	 * @param int    $alvo ID da página ou do comentário.
	 * @return void
	 */
	public static function campos( $acao, $alvo ) {
		printf( '<input type="hidden" name="action" value="%s">', esc_attr( Reconectar_Incubadora_Acoes::acao( $acao ) ) );
		printf( '<input type="hidden" name="_wpnonce" value="%s">', esc_attr( wp_create_nonce( self::acao_do_nonce( $acao, $alvo ) ) ) );
		wp_referer_field();
	}

	/**
	 * URL de destino dos formulários.
	 *
	 * Caminho, e não URL absoluta, pela regra de `WP_HOME` dinâmico.
	 *
	 * @return string
	 */
	public static function url_do_formulario() {
		return (string) wp_parse_url( admin_url( 'admin-post.php' ), PHP_URL_PATH );
	}

	/* ---------------------------------------------------------------------
	 * Apoio
	 * ------------------------------------------------------------------ */

	/**
	 * Lista de avaliações, normalizada.
	 *
	 * @param int $pagina_id Página.
	 * @return int[] `array( user_id => 1|-1 )`
	 */
	private static function avaliacoes( $pagina_id ) {
		$avaliacoes = get_post_meta( (int) $pagina_id, self::META_AVALIACOES, true );

		return is_array( $avaliacoes ) ? $avaliacoes : array();
	}

	/**
	 * A página pelo ID, se existir e for da Incubadora.
	 *
	 * Avaliação e comentário só em página publicada: o rascunho é de quem
	 * escreve, e a conversa nele sumiria da vista de quem participou assim que
	 * alguém o despublicasse.
	 *
	 * @param int  $pagina_id   ID recebido.
	 * @param bool $publicada   Exige `publish`.
	 * @return WP_Post|WP_Error
	 */
	private static function pagina( $pagina_id, $publicada = false ) {
		$pagina = $pagina_id ? get_post( (int) $pagina_id ) : null;

		if ( ! $pagina || Reconectar_Incubadora::POST_TYPE !== $pagina->post_type || in_array( $pagina->post_status, array( 'trash', 'auto-draft', 'inherit' ), true ) ) {
			return self::erro( 'inexistente', __( 'A página não existe mais.', 'reconectar-core' ), 404 );
		}

		if ( $publicada && 'publish' !== $pagina->post_status ) {
			return self::erro( 'rascunho', __( 'Só páginas publicadas recebem avaliação e comentários.', 'reconectar-core' ), 409 );
		}

		return $pagina;
	}

	/**
	 * Confere método, sessão, capacidade e nonce, e encerra com o código certo.
	 *
	 * A ordem é a de `Reconectar_Incubadora_Acoes::exigir_requisicao()`, e pela
	 * mesma razão: a capacidade vem antes do nonce, para que o 403 de um POST
	 * forjado prove que a capacidade foi consultada.
	 *
	 * @param string $acao       Chave de `ACOES`.
	 * @param string $capacidade Capacidade exigida no transporte.
	 * @param int    $alvo       ID da página ou do comentário, que entra no nonce.
	 * @return void
	 */
	private static function exigir_requisicao( $acao, $capacidade, $alvo ) {
		nocache_headers();

		// `admin-post.php` dispara a ação também em GET. Um link — ou uma imagem
		// num comentário — não pode avaliar nem excluir nada.
		$metodo = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_key( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : '';

		if ( 'POST' !== $metodo ) {
			header( 'Allow: POST' );
			self::encerrar( self::erro( 'metodo', __( 'Esta ação só aceita envio de formulário.', 'reconectar-core' ), 405 ) );
		}

		if ( ! is_user_logged_in() ) {
			self::encerrar( self::erro( 'login', __( 'Sua sessão expirou. Entre de novo para continuar.', 'reconectar-core' ), 401 ) );
		}

		if ( ! current_user_can( $capacidade ) ) {
			self::encerrar( self::erro( 'capacidade', __( 'Você não tem permissão para esta ação na Incubadora.', 'reconectar-core' ), 403 ) );
		}

		$nonce = isset( $_POST['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ) : '';

		if ( ! wp_verify_nonce( $nonce, self::acao_do_nonce( $acao, $alvo ) ) ) {
			self::encerrar( self::erro( 'nonce', __( 'O formulário expirou. Volte, recarregue a página e tente de novo.', 'reconectar-core' ), 403 ) );
		}
	}

	/**
	 * Volta para a página depois de uma operação, ou encerra com o erro.
	 *
	 * A volta é pelo referer do formulário: a mesma página da Incubadora pode
	 * estar aberta dentro do painel da loja ou fora dele, e o permalink levaria
	 * quem estava no painel para fora. O permalink é só o recuo.
	 *
	 * @param array|WP_Error $resultado Retorno da operação.
	 * @param string         $ancora    Âncora na página de destino.
	 * @return void
	 */
	private static function voltar( $resultado, $ancora ) {
		if ( is_wp_error( $resultado ) ) {
			self::encerrar( $resultado );
		}

		$destino = wp_get_referer();

		if ( ! $destino && ! empty( $resultado['pagina'] ) ) {
			$destino = get_permalink( (int) $resultado['pagina'] );
		}

		$destino = strtok( (string) ( $destino ? $destino : home_url( '/' ) ), '#' );

		wp_safe_redirect( $destino . '#' . $ancora, 303 );
		exit;
	}

	/**
	 * Encerra com a mensagem do erro, no status dele, e um link de volta.
	 *
	 * @param WP_Error $erro Erro.
	 * @return void
	 */
	private static function encerrar( $erro ) {
		$dados  = (array) $erro->get_error_data();
		$status = isset( $dados['status'] ) ? (int) $dados['status'] : 400;

		wp_die(
			esc_html( $erro->get_error_message() ),
			esc_html__( 'Incubadora', 'reconectar-core' ),
			array(
				'response'  => $status,
				'back_link' => true,
			)
		);
	}

	/**
	 * Monta um `WP_Error` com o status HTTP.
	 *
	 * @param string $codigo   Código.
	 * @param string $mensagem Mensagem para a pessoa.
	 * @param int    $status   Status HTTP.
	 * @return WP_Error
	 */
	private static function erro( $codigo, $mensagem, $status ) {
		return new WP_Error( $codigo, $mensagem, array( 'status' => (int) $status ) );
	}
}
