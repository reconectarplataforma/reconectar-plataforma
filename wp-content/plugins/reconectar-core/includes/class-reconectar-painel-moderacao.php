<?php
/**
 * A moderação em `/gestao/moderacao/`: avaliações de produto e a fila do fórum.
 *
 * Duas abas. **Avaliações** traz o que o WooCommerce grava como comentário de
 * produto — `review`, e `comment` para o que foi escrito sem nota. **Fórum**
 * traz tópicos e respostas que o bbPress segurou para moderação ou marcou como
 * spam, mais a lixeira. O que está publicado no fórum se modera no próprio
 * tópico, pelo menu "Moderar" do tema: ali o moderador lê a conversa inteira
 * antes de decidir, e uma tabela com trechos não daria isso.
 *
 * Fica de fora o `rc_incubadora`, que a Incubadora modera na própria página, e
 * o comentário de post comum, que não tem tela pública nesta plataforma.
 *
 * Cada ação passa pela função do dono do dado — `wp_set_comment_status()`, e
 * `bbp_approve_*`/`bbp_spam_*` no fórum — e não por um `UPDATE` de status: são
 * elas que recalculam a nota média do produto e as contagens do tópico. Um
 * status trocado à mão deixaria a avaliação aprovada fora da média.
 *
 * @package reconectar-core
 */

defined( 'ABSPATH' ) || exit;

class Reconectar_Painel_Moderacao {

	/**
	 * Chave da área na rota.
	 */
	const AREA = 'moderacao';

	/**
	 * Itens por página.
	 */
	const POR_PAGINA = 20;

	/**
	 * Parâmetro da página da listagem.
	 *
	 * `pagenum`, e não `paged` nem `page`: os dois são query vars do núcleo, e a
	 * rota os leria como paginação de um arquivo que não existe — 404, medido em
	 * `Reconectar_Avaliacoes_Pendentes`.
	 */
	const PARAMETRO_PAGINA = 'pagenum';

	/**
	 * Tipos de comentário de produto que a aba Avaliações alcança.
	 *
	 * `review` explícito: sem ele no `type`, o WooCommerce tira as avaliações de
	 * toda consulta de comentários (`WC_Comments::exclude_order_comments()` e
	 * irmãos), e a aba sairia vazia com dezesseis avaliações no banco.
	 *
	 * @var string[]
	 */
	const TIPOS_DE_AVALIACAO = array( 'review', 'comment' );

	/**
	 * O nome da área.
	 *
	 * @return string
	 */
	public static function titulo() {
		return __( 'Moderação', 'reconectar-core' );
	}

	/**
	 * Quem abre a área.
	 *
	 * `CAP_MODERAR`, e não `moderate_comments` — ver a constante.
	 *
	 * @return bool
	 */
	public static function pode() {
		return current_user_can( Reconectar_Permissoes::CAP_MODERAR );
	}

	/**
	 * A área não tem item: tudo acontece na listagem.
	 *
	 * @param string $item Item pedido.
	 * @return bool
	 */
	public static function pode_item( $item ) {
		return false;
	}

	/**
	 * O arquivo da tela.
	 *
	 * @param string $item Item corrente.
	 * @return string
	 */
	public static function template( $item ) {
		return 'lista-moderacao.php';
	}

	/**
	 * Os avisos da área.
	 *
	 * @return array<string,string>
	 */
	public static function avisos() {
		return array(
			'aprovado'    => __( 'Aprovado. Já aparece no site.', 'reconectar-core' ),
			'desaprovado' => __( 'Desaprovado. Saiu do site e voltou para Pendentes.', 'reconectar-core' ),
			'spam'        => __( 'Marcado como spam.', 'reconectar-core' ),
			'nao-spam'    => __( 'Retirado do spam.', 'reconectar-core' ),
			'na-lixeira'  => __( 'Enviado para a lixeira.', 'reconectar-core' ),
			'restaurado'  => __( 'Restaurado da lixeira.', 'reconectar-core' ),
		);
	}

	/**
	 * As abas que o usuário corrente alcança.
	 *
	 * O fórum depende de dois fatos: o bbPress ativo e `moderate`, que é a
	 * capacidade do papel de fórum e não do papel do WordPress.
	 *
	 * @return array<string,string> Chave da aba => rótulo.
	 */
	public static function abas() {
		$abas = array( 'avaliacoes' => __( 'Avaliações de produto', 'reconectar-core' ) );

		if ( self::alcanca_o_forum() ) {
			$abas['forum'] = __( 'Fórum', 'reconectar-core' );
		}

		return $abas;
	}

	/**
	 * O usuário corrente modera o fórum?
	 *
	 * @return bool
	 */
	private static function alcanca_o_forum() {
		return function_exists( 'bbp_get_topic_post_type' ) && current_user_can( 'moderate' );
	}

	/**
	 * As situações de cada aba, na ordem das sub-abas.
	 *
	 * O fórum não tem "Aprovados": o publicado se modera no tópico, e repetir
	 * aqui o fórum inteiro faria da fila uma segunda listagem de perguntas.
	 *
	 * @param string $aba Chave da aba.
	 * @return array<string,string> Chave da situação => rótulo.
	 */
	public static function situacoes( $aba ) {
		if ( 'forum' === $aba ) {
			return array(
				'pendentes' => __( 'Aguardando aprovação', 'reconectar-core' ),
				'spam'      => __( 'Spam', 'reconectar-core' ),
				'lixeira'   => __( 'Lixeira', 'reconectar-core' ),
			);
		}

		return array(
			'pendentes' => __( 'Pendentes', 'reconectar-core' ),
			'aprovados' => __( 'Aprovadas', 'reconectar-core' ),
			'spam'      => __( 'Spam', 'reconectar-core' ),
			'lixeira'   => __( 'Lixeira', 'reconectar-core' ),
		);
	}

	/**
	 * A aba pedida na URL, ou a primeira.
	 *
	 * @param array $origem `$_GET` ou o POST.
	 * @return string
	 */
	public static function aba( $origem = null ) {
		$origem = null === $origem ? $_GET : $origem; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$aba    = isset( $origem['aba'] ) ? sanitize_key( wp_unslash( $origem['aba'] ) ) : '';

		return isset( self::abas()[ $aba ] ) ? $aba : 'avaliacoes';
	}

	/**
	 * A situação pedida na URL, ou a primeira da aba.
	 *
	 * @param string $aba    Aba corrente.
	 * @param array  $origem `$_GET` ou o POST.
	 * @return string
	 */
	public static function situacao( $aba, $origem = null ) {
		$origem   = null === $origem ? $_GET : $origem; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$situacao = isset( $origem['situacao'] ) ? sanitize_key( wp_unslash( $origem['situacao'] ) ) : '';

		return isset( self::situacoes( $aba )[ $situacao ] ) ? $situacao : 'pendentes';
	}

	/**
	 * A página corrente da listagem.
	 *
	 * @param array $origem `$_GET` ou o POST.
	 * @return int
	 */
	public static function pagina( $origem = null ) {
		$origem = null === $origem ? $_GET : $origem; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		return isset( $origem[ self::PARAMETRO_PAGINA ] ) ? max( 1, absint( $origem[ self::PARAMETRO_PAGINA ] ) ) : 1;
	}

	/**
	 * URL da listagem numa aba e situação.
	 *
	 * @param string $aba      Aba.
	 * @param string $situacao Situação.
	 * @param int    $pagina   Página, 1 omite o parâmetro.
	 * @return string
	 */
	public static function url( $aba, $situacao, $pagina = 1 ) {
		$argumentos = array(
			'aba'      => $aba,
			'situacao' => $situacao,
		);

		if ( $pagina > 1 ) {
			$argumentos[ self::PARAMETRO_PAGINA ] = $pagina;
		}

		return add_query_arg( $argumentos, Reconectar_Painel_Gestao::url( self::AREA ) );
	}

	/* ---------------------------------------------------------------------
	 * Consultas
	 * ------------------------------------------------------------------ */

	/**
	 * O status de comentário do núcleo que corresponde a cada situação.
	 *
	 * @return array<string,string>
	 */
	private static function status_de_comentario() {
		return array(
			'pendentes' => 'hold',
			'aprovados' => 'approve',
			'spam'      => 'spam',
			'lixeira'   => 'trash',
		);
	}

	/**
	 * Os argumentos comuns às consultas de avaliação.
	 *
	 * @param string $situacao Situação.
	 * @return array
	 */
	private static function argumentos_de_avaliacao( $situacao ) {
		return array(
			'post_type' => 'product',
			'type__in'  => self::TIPOS_DE_AVALIACAO,
			'status'    => self::status_de_comentario()[ $situacao ],
		);
	}

	/**
	 * Quantas avaliações há numa situação.
	 *
	 * @param string $situacao Situação.
	 * @return int
	 */
	public static function contar_avaliacoes( $situacao ) {
		return (int) get_comments( array_merge( self::argumentos_de_avaliacao( $situacao ), array( 'count' => true ) ) );
	}

	/**
	 * Uma página de avaliações, mais novas primeiro.
	 *
	 * @param string $situacao Situação.
	 * @param int    $pagina   Página.
	 * @return WP_Comment[]
	 */
	public static function avaliacoes( $situacao, $pagina ) {
		return get_comments(
			array_merge(
				self::argumentos_de_avaliacao( $situacao ),
				array(
					'number'  => self::POR_PAGINA,
					'offset'  => ( $pagina - 1 ) * self::POR_PAGINA,
					'orderby' => 'comment_date_gmt',
					'order'   => 'DESC',
				)
			)
		);
	}

	/**
	 * O status de post do bbPress que corresponde a cada situação.
	 *
	 * @return array<string,string>
	 */
	private static function status_do_forum() {
		return array(
			'pendentes' => bbp_get_pending_status_id(),
			'spam'      => bbp_get_spam_status_id(),
			'lixeira'   => bbp_get_trash_status_id(),
		);
	}

	/**
	 * Uma página da fila do fórum, tópicos e respostas juntos.
	 *
	 * @param string $situacao Situação.
	 * @param int    $pagina   Página.
	 * @return WP_Query
	 */
	public static function fila_do_forum( $situacao, $pagina ) {
		return new WP_Query(
			array(
				'post_type'           => array( bbp_get_topic_post_type(), bbp_get_reply_post_type() ),
				'post_status'         => self::status_do_forum()[ $situacao ],
				'posts_per_page'      => self::POR_PAGINA,
				'paged'               => $pagina,
				'orderby'             => 'date',
				'order'               => 'DESC',
				'ignore_sticky_posts' => true,
				'no_found_rows'       => false,
				// O bbPress filtra as consultas de tópico pelo que o usuário pode
				// ver; quem chega aqui modera, e a fila tem de vir inteira.
				'suppress_filters'    => true,
			)
		);
	}

	/**
	 * Quantos itens do fórum há numa situação.
	 *
	 * @param string $situacao Situação.
	 * @return int
	 */
	public static function contar_do_forum( $situacao ) {
		$status = self::status_do_forum()[ $situacao ];
		$total  = 0;

		foreach ( array( bbp_get_topic_post_type(), bbp_get_reply_post_type() ) as $tipo ) {
			$contagem = wp_count_posts( $tipo );
			$total   += isset( $contagem->$status ) ? (int) $contagem->$status : 0;
		}

		return $total;
	}

	/* ---------------------------------------------------------------------
	 * Decisões
	 * ------------------------------------------------------------------ */

	/**
	 * As decisões possíveis a partir de uma situação, com o rótulo do botão.
	 *
	 * A mesma tabela desenha os botões e valida o POST: a decisão é conferida
	 * contra a situação **real** do item, lida do banco, e não contra a aba de
	 * onde o formulário veio. Um formulário velho — a avaliação já aprovada em
	 * outra aba — não reaprova nem "desfaz spam" de quem não está no spam.
	 *
	 * @param string $situacao Situação real do item.
	 * @param string $aba      `avaliacoes` ou `forum`.
	 * @return array<string,string>
	 */
	public static function decisoes( $situacao, $aba ) {
		switch ( $situacao ) {
			case 'pendentes':
				return array(
					'aprovar' => __( 'Aprovar', 'reconectar-core' ),
					'spam'    => __( 'Spam', 'reconectar-core' ),
					'lixeira' => __( 'Lixeira', 'reconectar-core' ),
				);

			case 'aprovados':
				return 'forum' === $aba ? array() : array(
					'desaprovar' => __( 'Desaprovar', 'reconectar-core' ),
					'spam'       => __( 'Spam', 'reconectar-core' ),
					'lixeira'    => __( 'Lixeira', 'reconectar-core' ),
				);

			case 'spam':
				return array(
					'nao_spam' => __( 'Não é spam', 'reconectar-core' ),
					'lixeira'  => __( 'Lixeira', 'reconectar-core' ),
				);

			case 'lixeira':
				return array( 'restaurar' => __( 'Restaurar', 'reconectar-core' ) );
		}

		return array();
	}

	/**
	 * A situação de uma avaliação, pelo status gravado.
	 *
	 * @param WP_Comment $comentario Comentário.
	 * @return string
	 */
	private static function situacao_da_avaliacao( $comentario ) {
		$mapa = array(
			'unapproved' => 'pendentes',
			'approved'   => 'aprovados',
			'spam'       => 'spam',
			'trash'      => 'lixeira',
		);

		$status = wp_get_comment_status( $comentario );

		return isset( $mapa[ $status ] ) ? $mapa[ $status ] : '';
	}

	/**
	 * A situação de um item do fórum, pelo status gravado.
	 *
	 * Publicado, fechado e privado respondem vazio: não estão na fila.
	 *
	 * @param WP_Post $post Tópico ou resposta.
	 * @return string
	 */
	private static function situacao_no_forum( $post ) {
		$situacao = array_search( $post->post_status, self::status_do_forum(), true );

		return false === $situacao ? '' : $situacao;
	}

	/**
	 * Executa a ação do POST.
	 *
	 * @param string $acao   Ação do formulário.
	 * @param array  $campos POST sem barras.
	 * @param string $item   Sempre vazio nesta área.
	 * @return void
	 */
	public static function processar( $acao, $campos, $item ) {
		if ( 'moderar' !== $acao ) {
			return;
		}

		$aba      = self::aba( $campos );
		$retorno  = array(
			'aba'      => $aba,
			'situacao' => self::situacao( $aba, $campos ),
		);
		$pagina   = self::pagina( $campos );
		$alvo     = isset( $campos['alvo'] ) ? absint( $campos['alvo'] ) : 0;
		$decisao  = isset( $campos['decisao'] ) ? sanitize_key( $campos['decisao'] ) : '';

		if ( $pagina > 1 ) {
			$retorno[ self::PARAMETRO_PAGINA ] = $pagina;
		}

		if ( 'forum' === $aba ) {
			$aviso = self::moderar_no_forum( $alvo, $decisao );
		} else {
			$aviso = self::moderar_avaliacao( $alvo, $decisao );
		}

		if ( '' === $aviso ) {
			Reconectar_Painel_Gestao::redirecionar(
				self::AREA,
				'',
				'erro',
				__( 'Não foi possível concluir a ação. O item pode já ter sido moderado por outra pessoa.', 'reconectar-core' ),
				$retorno
			);
		}

		Reconectar_Painel_Gestao::redirecionar( self::AREA, '', $aviso, '', $retorno );
	}

	/**
	 * Aplica uma decisão a uma avaliação.
	 *
	 * Comentário que não é de produto, ou de tipo fora da lista, recebe 403: o
	 * id veio de um formulário que esta tela não imprimiria, e a capacidade
	 * sozinha alcançaria o comentário da Incubadora e a nota de pedido.
	 *
	 * `moderate_comments`, e não `edit_comment`: o núcleo mapeia este para
	 * `edit_post` do produto pai, que vira `edit_others_products` — e essa o
	 * Moderador não tem, de propósito, porque o produto é da loja. Medido: com
	 * `edit_comment` a aprovação legítima respondia 403. É a mesma capacidade
	 * que a tela de avaliações do WooCommerce confere no `/wp-admin`.
	 *
	 * @param int    $alvo    Id do comentário.
	 * @param string $decisao Decisão.
	 * @return string Código do aviso, ou vazio se a decisão não cabe.
	 */
	private static function moderar_avaliacao( $alvo, $decisao ) {
		$comentario = get_comment( $alvo );

		if (
			! $comentario
			|| ! in_array( $comentario->comment_type, self::TIPOS_DE_AVALIACAO, true )
			|| 'product' !== get_post_type( (int) $comentario->comment_post_ID )
			|| ! current_user_can( 'moderate_comments' )
		) {
			Reconectar_Painel_Gestao::recusar();
		}

		if ( ! isset( self::decisoes( self::situacao_da_avaliacao( $comentario ), 'avaliacoes' )[ $decisao ] ) ) {
			return '';
		}

		switch ( $decisao ) {
			case 'aprovar':
				return wp_set_comment_status( $alvo, 'approve' ) ? 'aprovado' : '';

			case 'desaprovar':
				return wp_set_comment_status( $alvo, 'hold' ) ? 'desaprovado' : '';

			case 'spam':
				return wp_spam_comment( $alvo ) ? 'spam' : '';

			// `wp_unspam_comment()` e `wp_untrash_comment()`, e não um status
			// fixo: as duas devolvem o comentário ao status que ele tinha antes,
			// gravado em `_wp_trash_meta_status`. Uma avaliação aprovada que foi
			// para a lixeira por engano volta aprovada, sem passar por Pendentes.
			case 'nao_spam':
				return wp_unspam_comment( $alvo ) ? 'nao-spam' : '';

			case 'lixeira':
				return wp_trash_comment( $alvo ) ? 'na-lixeira' : '';

			case 'restaurar':
				return wp_untrash_comment( $alvo ) ? 'restaurado' : '';
		}

		return '';
	}

	/**
	 * Aplica uma decisão a um tópico ou resposta.
	 *
	 * `moderate` sobre o id, e não a capacidade solta: o bbPress a mapeia por
	 * fórum, e é assim que um moderador de um fórum só não alcança os outros.
	 * A lixeira pede ainda `delete_topic`/`delete_reply`, como o próprio
	 * `bbp_toggle_topic_handler()` pede.
	 *
	 * @param int    $alvo    Id do post.
	 * @param string $decisao Decisão.
	 * @return string Código do aviso, ou vazio se a decisão não cabe.
	 */
	private static function moderar_no_forum( $alvo, $decisao ) {
		$post = get_post( $alvo );

		if ( ! $post || ! self::alcanca_o_forum() ) {
			Reconectar_Painel_Gestao::recusar();
		}

		$eh_topico = bbp_get_topic_post_type() === $post->post_type;

		if ( ( ! $eh_topico && bbp_get_reply_post_type() !== $post->post_type ) || ! current_user_can( 'moderate', $alvo ) ) {
			Reconectar_Painel_Gestao::recusar();
		}

		if ( ! isset( self::decisoes( self::situacao_no_forum( $post ), 'forum' )[ $decisao ] ) ) {
			return '';
		}

		switch ( $decisao ) {
			case 'aprovar':
				$feito = $eh_topico ? bbp_approve_topic( $alvo ) : bbp_approve_reply( $alvo );
				return $feito ? 'aprovado' : '';

			case 'spam':
				$feito = $eh_topico ? bbp_spam_topic( $alvo ) : bbp_spam_reply( $alvo );
				return $feito ? 'spam' : '';

			case 'nao_spam':
				$feito = $eh_topico ? bbp_unspam_topic( $alvo ) : bbp_unspam_reply( $alvo );
				return $feito ? 'nao-spam' : '';

			case 'lixeira':
				if ( ! current_user_can( $eh_topico ? 'delete_topic' : 'delete_reply', $alvo ) ) {
					Reconectar_Painel_Gestao::recusar();
				}
				return wp_trash_post( $alvo ) ? 'na-lixeira' : '';

			// O bbPress devolve o status anterior por `bbp_fix_untrash_post_status`,
			// no filtro `wp_untrash_post_status`; sem ele o núcleo restauraria
			// como rascunho, e o tópico sumiria do fórum depois de "restaurado".
			case 'restaurar':
				return wp_untrash_post( $alvo ) ? 'restaurado' : '';
		}

		return '';
	}
}
