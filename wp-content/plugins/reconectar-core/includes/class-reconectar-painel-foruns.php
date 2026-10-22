<?php
/**
 * A gestão das categorias do fórum em `/gestao/foruns/`.
 *
 * Cada categoria é um `forum` do bbPress. A listagem pública
 * (`reconectar_forum_categorias()`, no tema) lê os publicados por `menu_order`
 * e título — os mesmos dois campos que esta tela grava, e por isso a ordem
 * daqui é a ordem de lá.
 *
 * Não há exclusão. Um fórum excluído leva os tópicos junto, e o que se quer
 * quando uma categoria deixa de servir é parar de receber perguntas nela sem
 * apagar as respostas que já deu: é o "fechado" do bbPress.
 *
 * @package reconectar-core
 */

defined( 'ABSPATH' ) || exit;

class Reconectar_Painel_Foruns {

	/**
	 * Chave da área na rota.
	 */
	const AREA = 'foruns';

	/**
	 * O nome da área.
	 *
	 * @return string
	 */
	public static function titulo() {
		return __( 'Categorias do fórum', 'reconectar-core' );
	}

	/**
	 * Quem abre a área.
	 *
	 * `edit_forums`, e não `moderate`: é a capacidade que o bbPress reserva ao
	 * keymaster e que `Reconectar_Permissoes::restaurar_gestao_de_foruns()`
	 * devolve só a quem o papel autorizou. Sem o bbPress a área não existe.
	 *
	 * @return bool
	 */
	public static function pode() {
		return function_exists( 'bbp_get_forum_post_type' ) && current_user_can( 'edit_forums' );
	}

	/**
	 * Quem abre `nova` ou uma categoria.
	 *
	 * @param string $item `nova` ou o id.
	 * @return bool
	 */
	public static function pode_item( $item ) {
		if ( ! self::pode() ) {
			return false;
		}

		if ( 'nova' === $item ) {
			return current_user_can( 'publish_forums' );
		}

		$forum = get_post( (int) $item );

		if ( ! $forum || bbp_get_forum_post_type() !== $forum->post_type || 'trash' === $forum->post_status ) {
			return false;
		}

		return current_user_can( 'edit_forum', $forum->ID );
	}

	/**
	 * O arquivo da tela.
	 *
	 * @param string $item Item corrente.
	 * @return string
	 */
	public static function template( $item ) {
		return '' === $item ? 'lista-foruns.php' : 'form-forum.php';
	}

	/**
	 * Os avisos da área.
	 *
	 * @return array<string,string>
	 */
	public static function avisos() {
		return array(
			'criada' => __( 'Categoria criada. Ela já aparece no fórum.', 'reconectar-core' ),
			'salva'  => __( 'Categoria salva.', 'reconectar-core' ),
		);
	}

	/**
	 * As categorias, na ordem em que o fórum as mostra.
	 *
	 * Consulta própria, e não `reconectar_forum_categorias()`: aquela é do tema,
	 * e o plugin não pode exigir tema nenhum.
	 *
	 * @return WP_Post[]
	 */
	public static function categorias() {
		return get_posts(
			array(
				'post_type'      => bbp_get_forum_post_type(),
				'post_status'    => array( 'publish', 'private', 'hidden' ),
				'posts_per_page' => -1,
				'orderby'        => 'menu_order title',
				'order'          => 'ASC',
			)
		);
	}

	/**
	 * Executa a ação do POST.
	 *
	 * @param string $acao   Ação do formulário.
	 * @param array  $campos POST sem barras.
	 * @param string $item   Item corrente, já autorizado em `proteger()`.
	 * @return void
	 */
	public static function processar( $acao, $campos, $item ) {
		if ( 'forum_salvar' === $acao ) {
			self::salvar( $campos, $item );
		}
	}

	/**
	 * Cria ou atualiza a categoria.
	 *
	 * `bbp_insert_forum()` na criação, e não `wp_insert_post()`: ele grava as
	 * metas de contagem e de visibilidade que o fórum lê, e sem elas a categoria
	 * nova sairia com "0 tópicos" fixo mesmo depois da primeira pergunta.
	 *
	 * @param array  $campos POST sem barras.
	 * @param string $item   `nova` ou o id.
	 * @return void
	 */
	private static function salvar( $campos, $item ) {
		$nome      = isset( $campos['nome'] ) ? sanitize_text_field( $campos['nome'] ) : '';
		$descricao = isset( $campos['descricao'] ) ? sanitize_textarea_field( $campos['descricao'] ) : '';
		$ordem     = isset( $campos['ordem'] ) ? (int) $campos['ordem'] : 0;
		$fechada   = ! empty( $campos['fechada'] );

		if ( '' === $nome ) {
			Reconectar_Painel_Gestao::guardar_formulario( 'forum-' . $item, $campos );
			Reconectar_Painel_Gestao::redirecionar( self::AREA, $item, 'erro', __( 'Dê um nome à categoria.', 'reconectar-core' ) );
		}

		if ( 'nova' === $item ) {
			$id = bbp_insert_forum(
				array(
					'post_title'   => $nome,
					'post_content' => $descricao,
					'menu_order'   => $ordem,
					'post_status'  => bbp_get_public_status_id(),
				)
			);
		} else {
			$id = wp_update_post(
				array(
					'ID'           => (int) $item,
					'post_title'   => $nome,
					'post_content' => $descricao,
					'menu_order'   => $ordem,
				),
				true
			);
		}

		if ( is_wp_error( $id ) || ! $id ) {
			Reconectar_Painel_Gestao::guardar_formulario( 'forum-' . $item, $campos );
			Reconectar_Painel_Gestao::redirecionar( self::AREA, $item, 'erro', __( 'Não foi possível gravar a categoria.', 'reconectar-core' ) );
		}

		// Só quando muda: as duas funções do bbPress disparam ganchos de
		// fechamento e abertura, e repeti-los a cada salvamento deixaria rastro
		// de uma mudança que não houve.
		if ( $fechada !== bbp_is_forum_closed( $id, false ) ) {
			if ( $fechada ) {
				bbp_close_forum( $id );
			} else {
				bbp_open_forum( $id );
			}
		}

		Reconectar_Painel_Gestao::redirecionar( self::AREA, $id, 'nova' === $item ? 'criada' : 'salva' );
	}
}
