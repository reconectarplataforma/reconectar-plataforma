<?php
/**
 * A gestão de enquetes em `/gestao/enquetes/`.
 *
 * A mesma enquete que o metabox do `/wp-admin` grava: o post type, as metas e a
 * normalização das alternativas são de `Reconectar_Proposta_Votacao`, e as duas
 * telas passam por `Reconectar_Proposta_Votacao::gravar()`. Duas gravações
 * paralelas divergiriam na primeira correção feita em só uma delas.
 *
 * @package reconectar-core
 */

defined( 'ABSPATH' ) || exit;

class Reconectar_Painel_Enquetes {

	/**
	 * Chave da área na rota.
	 */
	const AREA = 'enquetes';

	/**
	 * Mínimo de alternativas para publicar.
	 *
	 * O rascunho aceita menos — quem está escrevendo pode salvar no meio —, mas
	 * uma enquete publicada com uma alternativa só não pergunta nada.
	 */
	const MINIMO_PARA_PUBLICAR = 2;

	/**
	 * O nome da área.
	 *
	 * @return string
	 */
	public static function titulo() {
		return __( 'Enquetes', 'reconectar-core' );
	}

	/**
	 * Quem abre a área.
	 *
	 * @return bool
	 */
	public static function pode() {
		return current_user_can( Reconectar_Permissoes::CAP_GERIR_ENQUETES );
	}

	/**
	 * Quem abre `nova` ou uma enquete.
	 *
	 * O tipo do post é conferido antes da capacidade: `edit_post` sobre o id de um
	 * produto responderia pela regra do produto, e a tela abriria o formulário de
	 * enquete sobre ele.
	 *
	 * @param string $item `nova` ou o id.
	 * @return bool
	 */
	public static function pode_item( $item ) {
		if ( 'nova' === $item ) {
			return self::pode();
		}

		$enquete = get_post( (int) $item );

		if ( ! $enquete || Reconectar_Proposta_Votacao::POST_TYPE !== $enquete->post_type || 'trash' === $enquete->post_status ) {
			return false;
		}

		return current_user_can( 'edit_post', $enquete->ID );
	}

	/**
	 * O arquivo da tela.
	 *
	 * @param string $item Item corrente.
	 * @return string
	 */
	public static function template( $item ) {
		return '' === $item ? 'lista-enquetes.php' : 'form-enquete.php';
	}

	/**
	 * Os avisos da área.
	 *
	 * @return array<string,string>
	 */
	public static function avisos() {
		return array(
			'salva'         => __( 'Enquete salva.', 'reconectar-core' ),
			'publicada'     => __( 'Enquete publicada.', 'reconectar-core' ),
			'despublicada'  => __( 'Enquete despublicada. Ela voltou a ser rascunho e saiu do site.', 'reconectar-core' ),
			'na-lixeira'    => __( 'Enquete enviada para a lixeira.', 'reconectar-core' ),
		);
	}

	/**
	 * As enquetes da listagem, mais novas primeiro.
	 *
	 * @return WP_Post[]
	 */
	public static function enquetes() {
		return get_posts(
			array(
				'post_type'      => Reconectar_Proposta_Votacao::POST_TYPE,
				'post_status'    => array( 'publish', 'draft', 'pending', 'future', 'private' ),
				'posts_per_page' => 100,
				'orderby'        => 'date',
				'order'          => 'DESC',
			)
		);
	}

	/**
	 * Situação de uma enquete, para o selo.
	 *
	 * "Publicada" não diz se ela aceita voto: a janela de datas pode estar
	 * fechada ou ainda não aberta, e quem gere precisa ver isso sem abrir a
	 * enquete.
	 *
	 * @param WP_Post $enquete Enquete.
	 * @return array{rotulo:string,ativo:bool}
	 */
	public static function situacao( $enquete ) {
		if ( 'publish' !== $enquete->post_status ) {
			return array(
				'rotulo' => __( 'Rascunho', 'reconectar-core' ),
				'ativo'  => false,
			);
		}

		if ( Reconectar_Proposta_Votacao::esta_aberta( $enquete->ID ) ) {
			return array(
				'rotulo' => __( 'Recebendo votos', 'reconectar-core' ),
				'ativo'  => true,
			);
		}

		$inicio = (string) get_post_meta( $enquete->ID, Reconectar_Proposta_Votacao::META_INICIO, true );

		return array(
			'rotulo' => '' !== $inicio && current_time( 'Y-m-d' ) < $inicio
				? __( 'Publicada, ainda não abriu', 'reconectar-core' )
				: __( 'Publicada, encerrada', 'reconectar-core' ),
			'ativo'  => false,
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
		switch ( $acao ) {
			case 'enquete_salvar':
				self::salvar( $campos, $item );
				break;

			case 'enquete_lixeira':
				self::mandar_para_a_lixeira( $item );
				break;
		}
	}

	/**
	 * Cria ou atualiza a enquete.
	 *
	 * O estado vem do botão: "Salvar rascunho" e "Publicar" na nova, "Salvar" e
	 * "Despublicar" na publicada. Validar antes de gravar qualquer coisa: uma
	 * enquete criada e então recusada deixaria um rascunho órfão a cada tentativa.
	 *
	 * @param array  $campos POST sem barras.
	 * @param string $item   `nova` ou o id.
	 * @return void
	 */
	private static function salvar( $campos, $item ) {
		$titulo  = isset( $campos['titulo'] ) ? sanitize_text_field( $campos['titulo'] ) : '';
		$texto   = isset( $campos['texto'] ) ? wp_kses_post( $campos['texto'] ) : '';
		$resumo  = isset( $campos['resumo'] ) ? sanitize_textarea_field( $campos['resumo'] ) : '';
		$inicio  = isset( $campos['inicio'] ) ? (string) $campos['inicio'] : '';
		$fim     = isset( $campos['fim'] ) ? (string) $campos['fim'] : '';
		$opcoes  = isset( $campos['opcoes'] ) && is_array( $campos['opcoes'] ) ? $campos['opcoes'] : array();
		$estado  = isset( $campos['estado'] ) && 'publish' === $campos['estado'] ? 'publish' : 'draft';

		$preenchidas = 0;
		foreach ( $opcoes as $linha ) {
			if ( is_array( $linha ) && isset( $linha['texto'] ) && '' !== trim( sanitize_text_field( $linha['texto'] ) ) ) {
				++$preenchidas;
			}
		}

		$erro = '';

		if ( '' === $titulo ) {
			$erro = __( 'Dê uma pergunta à enquete.', 'reconectar-core' );
		} elseif ( '' !== $inicio && '' !== $fim && $fim < $inicio ) {
			$erro = __( 'O último dia de votação vem antes do primeiro.', 'reconectar-core' );
		} elseif ( 'publish' === $estado && $preenchidas < self::MINIMO_PARA_PUBLICAR ) {
			$erro = sprintf(
				/* translators: %d: mínimo de alternativas. */
				__( 'Para publicar, a enquete precisa de pelo menos %d alternativas preenchidas.', 'reconectar-core' ),
				self::MINIMO_PARA_PUBLICAR
			);
		}

		if ( '' !== $erro ) {
			Reconectar_Painel_Gestao::guardar_formulario( 'enquete-' . $item, $campos );
			Reconectar_Painel_Gestao::redirecionar( self::AREA, $item, 'erro', $erro );
		}

		$dados = array(
			'post_type'    => Reconectar_Proposta_Votacao::POST_TYPE,
			'post_title'   => $titulo,
			'post_content' => $texto,
			'post_excerpt' => $resumo,
			'post_status'  => $estado,
		);

		$anterior = 'nova' === $item ? '' : get_post_status( (int) $item );

		if ( 'nova' === $item ) {
			$id = wp_insert_post( $dados, true );
		} else {
			$dados['ID'] = (int) $item;
			$id          = wp_update_post( $dados, true );
		}

		if ( is_wp_error( $id ) || ! $id ) {
			Reconectar_Painel_Gestao::guardar_formulario( 'enquete-' . $item, $campos );
			Reconectar_Painel_Gestao::redirecionar( self::AREA, $item, 'erro', __( 'Não foi possível gravar a enquete.', 'reconectar-core' ) );
		}

		Reconectar_Proposta_Votacao::gravar(
			$id,
			array(
				'inicio' => $inicio,
				'fim'    => $fim,
				'opcoes' => $opcoes,
			)
		);

		// O `save_post` já esqueceu o índice — antes de as metas existirem. Sem
		// esquecer de novo, a flutuante seguiria sem esta enquete até o
		// transient expirar.
		Reconectar_Proposta_Votacao::esquecer_indice();

		if ( 'publish' === $estado && 'publish' !== $anterior ) {
			$aviso = 'publicada';
		} elseif ( 'draft' === $estado && 'publish' === $anterior ) {
			$aviso = 'despublicada';
		} else {
			$aviso = 'salva';
		}

		Reconectar_Painel_Gestao::redirecionar( self::AREA, $id, $aviso );
	}

	/**
	 * Manda a enquete para a lixeira.
	 *
	 * Lixeira, e não exclusão: os votos são registro público de uma consulta, e
	 * apagar por engano não teria volta. O Super Administrador esvazia a lixeira
	 * no `/wp-admin`.
	 *
	 * @param string $item Id da enquete.
	 * @return void
	 */
	private static function mandar_para_a_lixeira( $item ) {
		if ( 'nova' === $item || ! current_user_can( 'delete_post', (int) $item ) ) {
			Reconectar_Painel_Gestao::recusar();
		}

		if ( ! wp_trash_post( (int) $item ) ) {
			Reconectar_Painel_Gestao::redirecionar( self::AREA, $item, 'erro', __( 'Não foi possível enviar a enquete para a lixeira.', 'reconectar-core' ) );
		}

		Reconectar_Proposta_Votacao::esquecer_indice();
		Reconectar_Painel_Gestao::redirecionar( self::AREA, '', 'na-lixeira' );
	}
}
