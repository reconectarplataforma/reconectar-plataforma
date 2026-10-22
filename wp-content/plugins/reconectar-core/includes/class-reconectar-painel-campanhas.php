<?php
/**
 * A gestão das campanhas da home em `/gestao/campanhas/`.
 *
 * A mesma campanha que o metabox do `/wp-admin` grava: post type, metas e
 * normalização são de `Reconectar_Campanha`, e as duas telas passam por
 * `Reconectar_Campanha::gravar()`.
 *
 * A diferença está na arte. No `/wp-admin` ela é a imagem destacada escolhida
 * na biblioteca de mídia; aqui é um envio de arquivo, porque abrir a biblioteca
 * exigiria trazer o `wp.media` inteiro para o front-end — e com ele a listagem
 * de todo anexo da instalação, comprovantes de pagamento inclusive, para quem
 * só precisa trocar um banner.
 *
 * @package reconectar-core
 */

defined( 'ABSPATH' ) || exit;

class Reconectar_Painel_Campanhas {

	/**
	 * Chave da área na rota.
	 */
	const AREA = 'campanhas';

	/**
	 * Nome do campo de arquivo da arte.
	 */
	const CAMPO_ARTE = 'arte';

	/**
	 * Os formatos aceitos para a arte, no mapa que `wp_handle_upload()` espera.
	 *
	 * Sem GIF e sem SVG: o GIF animado na faixa da home reprova o critério 2.2.2
	 * da WCAG 2.1 (não há como pausá-lo), e o SVG é documento, que carrega script.
	 */
	const FORMATOS = array(
		'jpg|jpeg|jpe' => 'image/jpeg',
		'png'          => 'image/png',
		'webp'         => 'image/webp',
	);

	/**
	 * O nome da área.
	 *
	 * @return string
	 */
	public static function titulo() {
		return __( 'Campanhas', 'reconectar-core' );
	}

	/**
	 * Quem abre a área.
	 *
	 * @return bool
	 */
	public static function pode() {
		return current_user_can( Reconectar_Permissoes::CAP_GERIR_CAMPANHAS );
	}

	/**
	 * Quem abre `nova` ou uma campanha.
	 *
	 * O tipo do post é conferido antes da capacidade, pelo mesmo motivo de
	 * `Reconectar_Painel_Enquetes::pode_item()`.
	 *
	 * @param string $item `nova` ou o id.
	 * @return bool
	 */
	public static function pode_item( $item ) {
		if ( 'nova' === $item ) {
			return self::pode();
		}

		$campanha = get_post( (int) $item );

		if ( ! $campanha || Reconectar_Campanha::POST_TYPE !== $campanha->post_type || 'trash' === $campanha->post_status ) {
			return false;
		}

		return current_user_can( 'edit_post', $campanha->ID );
	}

	/**
	 * O arquivo da tela.
	 *
	 * @param string $item Item corrente.
	 * @return string
	 */
	public static function template( $item ) {
		return '' === $item ? 'lista-campanhas.php' : 'form-campanha.php';
	}

	/**
	 * Os avisos da área.
	 *
	 * @return array<string,string>
	 */
	public static function avisos() {
		return array(
			'salva'        => __( 'Campanha salva.', 'reconectar-core' ),
			'publicada'    => __( 'Campanha publicada. Ela aparece na home durante o período definido.', 'reconectar-core' ),
			'despublicada' => __( 'Campanha despublicada. Ela voltou a ser rascunho e saiu da home.', 'reconectar-core' ),
			'na-lixeira'   => __( 'Campanha enviada para a lixeira.', 'reconectar-core' ),
		);
	}

	/**
	 * As campanhas da listagem, na ordem da faixa da home.
	 *
	 * A mesma ordem de `Reconectar_Campanha::vigentes()` — menor `ordem`
	 * primeiro, empate pelo id —, para que a tabela leia como a home.
	 *
	 * @return WP_Post[]
	 */
	public static function campanhas() {
		$campanhas = get_posts(
			array(
				'post_type'      => Reconectar_Campanha::POST_TYPE,
				'post_status'    => array( 'publish', 'draft', 'pending', 'future', 'private' ),
				'posts_per_page' => 100,
			)
		);

		usort(
			$campanhas,
			function ( $a, $b ) {
				$ordem_a = (int) get_post_meta( $a->ID, Reconectar_Campanha::META_ORDEM, true );
				$ordem_b = (int) get_post_meta( $b->ID, Reconectar_Campanha::META_ORDEM, true );

				return $ordem_a !== $ordem_b ? $ordem_a - $ordem_b : $a->ID - $b->ID;
			}
		);

		return $campanhas;
	}

	/**
	 * Situação de uma campanha, para o selo.
	 *
	 * "Publicada" não diz se ela está na home: a vigência pode não ter começado
	 * ou já ter acabado, e sem arte `Reconectar_Campanha::dados()` a descarta.
	 * Quem gere precisa ver os três casos sem abrir a home.
	 *
	 * @param WP_Post $campanha Campanha.
	 * @return array{rotulo:string,ativo:bool}
	 */
	public static function situacao( $campanha ) {
		if ( 'publish' !== $campanha->post_status ) {
			return array(
				'rotulo' => __( 'Rascunho', 'reconectar-core' ),
				'ativo'  => false,
			);
		}

		if ( ! has_post_thumbnail( $campanha ) ) {
			return array(
				'rotulo' => __( 'Publicada, sem arte: não aparece', 'reconectar-core' ),
				'ativo'  => false,
			);
		}

		if ( Reconectar_Campanha::esta_vigente( $campanha->ID ) ) {
			return array(
				'rotulo' => __( 'Na home agora', 'reconectar-core' ),
				'ativo'  => true,
			);
		}

		$inicio = (string) get_post_meta( $campanha->ID, Reconectar_Campanha::META_INICIO, true );

		return array(
			'rotulo' => '' !== $inicio && current_time( 'Y-m-d' ) < $inicio
				? __( 'Publicada, ainda não começou', 'reconectar-core' )
				: __( 'Publicada, encerrada', 'reconectar-core' ),
			'ativo'  => false,
		);
	}

	/**
	 * O limite de tamanho da arte, para a ajuda do campo e a mensagem de erro.
	 *
	 * O do PHP, e não um número desta classe: o container tem
	 * `upload_max_filesize=2M`, e prometer mais na tela levaria a um envio
	 * recusado depois de escolhido o arquivo.
	 *
	 * @return string
	 */
	public static function limite_legivel() {
		return size_format( wp_max_upload_size() );
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
			case 'campanha_salvar':
				self::salvar( $campos, $item );
				break;

			case 'campanha_lixeira':
				self::mandar_para_a_lixeira( $item );
				break;
		}
	}

	/**
	 * Cria ou atualiza a campanha.
	 *
	 * Tudo que pode recusar roda antes de criar o post — inclusive o envio da
	 * arte, que vai sem post pai e só depois é amarrado a ele. Na ordem inversa,
	 * cada arte recusada deixaria uma campanha-rascunho órfã na lista.
	 *
	 * @param array  $campos POST sem barras.
	 * @param string $item   `nova` ou o id.
	 * @return void
	 */
	private static function salvar( $campos, $item ) {
		$texto  = function ( $chave ) use ( $campos ) {
			return isset( $campos[ $chave ] ) && is_scalar( $campos[ $chave ] ) ? sanitize_text_field( (string) $campos[ $chave ] ) : '';
		};
		$titulo = $texto( 'titulo' );
		$link   = $texto( 'link' );
		$alt    = $texto( 'alt' );
		$inicio = Reconectar_Campanha::normalizar_data( $texto( 'inicio' ) );
		$fim    = Reconectar_Campanha::normalizar_data( $texto( 'fim' ) );
		$estado = 'publish' === $texto( 'estado' ) ? 'publish' : 'draft';
		$enviou = self::arquivo_escolhido();
		$tinha  = 'nova' !== $item && has_post_thumbnail( (int) $item );

		$erro = '';

		if ( '' === $titulo ) {
			$erro = __( 'Dê um nome à campanha.', 'reconectar-core' );
		} elseif ( '' === $alt ) {
			// Aqui é obrigatório, e não o recuo para o título que
			// `Reconectar_Campanha::gravar()` aplica: quem tem a arte diante de si
			// é quem sabe descrevê-la, e o nome interno da campanha raramente diz
			// o que a imagem mostra.
			$erro = __( 'Descreva a arte para quem não a vê.', 'reconectar-core' );
		} elseif ( '' !== $link && '' === esc_url_raw( $link ) ) {
			$erro = __( 'O link não é um endereço válido.', 'reconectar-core' );
		} elseif ( '' !== $inicio && '' !== $fim && $fim < $inicio ) {
			$erro = __( 'O último dia da campanha vem antes do primeiro.', 'reconectar-core' );
		} elseif ( 'publish' === $estado && ! $enviou && ! $tinha ) {
			$erro = __( 'Para publicar, a campanha precisa de uma arte. Sem ela, nada aparece na home.', 'reconectar-core' );
		} elseif ( $enviou && ! current_user_can( 'upload_files' ) ) {
			$erro = __( 'Seu perfil não pode enviar arquivos.', 'reconectar-core' );
		}

		$anexo_id = 0;

		if ( '' === $erro && $enviou ) {
			$anexo_id = self::receber_arte( $titulo );

			if ( is_wp_error( $anexo_id ) ) {
				$erro     = $anexo_id->get_error_message();
				$anexo_id = 0;
			}
		}

		if ( '' !== $erro ) {
			self::recusar_formulario( $campos, $item, $erro );
		}

		$dados = array(
			'post_type'   => Reconectar_Campanha::POST_TYPE,
			'post_title'  => $titulo,
			'post_status' => $estado,
		);

		$anterior = 'nova' === $item ? '' : get_post_status( (int) $item );

		if ( 'nova' === $item ) {
			$id = wp_insert_post( $dados, true );
		} else {
			$dados['ID'] = (int) $item;
			$id          = wp_update_post( $dados, true );
		}

		if ( is_wp_error( $id ) || ! $id ) {
			if ( $anexo_id ) {
				wp_delete_attachment( $anexo_id, true );
			}

			self::recusar_formulario( $campos, $item, __( 'Não foi possível gravar a campanha.', 'reconectar-core' ) );
		}

		Reconectar_Campanha::gravar(
			$id,
			array(
				'link'   => $link,
				'inicio' => $inicio,
				'fim'    => $fim,
				'ordem'  => $texto( 'ordem' ),
				'alt'    => $alt,
			)
		);

		if ( $anexo_id ) {
			wp_update_post(
				array(
					'ID'          => $anexo_id,
					'post_parent' => $id,
				)
			);
			update_post_meta( $anexo_id, '_wp_attachment_image_alt', $alt );
			set_post_thumbnail( $id, $anexo_id );
		}

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
	 * Guarda o que foi digitado e volta ao formulário com o erro.
	 *
	 * O arquivo não volta: navegador nenhum deixa um formulário vir preenchido
	 * com arquivo, e o aviso diz isso para que ninguém publique achando que a
	 * arte foi junto.
	 *
	 * @param array  $campos POST sem barras.
	 * @param string $item   `nova` ou o id.
	 * @param string $erro   Mensagem.
	 * @return void
	 */
	private static function recusar_formulario( $campos, $item, $erro ) {
		if ( self::arquivo_escolhido() ) {
			$erro .= ' ' . __( 'Escolha a arte de novo antes de enviar.', 'reconectar-core' );
		}

		Reconectar_Painel_Gestao::guardar_formulario( 'campanha-' . $item, $campos );
		Reconectar_Painel_Gestao::redirecionar( self::AREA, $item, 'erro', $erro );
	}

	/**
	 * Algum arquivo foi escolhido no campo da arte?
	 *
	 * Campo de arquivo vazio chega com `UPLOAD_ERR_NO_FILE`, não ausente: sem
	 * conferir o código, todo salvamento sem arte nova tentaria um envio.
	 *
	 * @return bool
	 */
	private static function arquivo_escolhido() {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- conferido em `Reconectar_Painel_Gestao::processar()`.
		return isset( $_FILES[ self::CAMPO_ARTE ]['error'] ) && UPLOAD_ERR_NO_FILE !== (int) $_FILES[ self::CAMPO_ARTE ]['error'];
	}

	/**
	 * Recebe a arte e cria o anexo, ainda sem post pai.
	 *
	 * Os códigos de tamanho são traduzidos aqui: a mensagem de
	 * `media_handle_upload()` para eles cita `upload_max_filesize`, que é nome de
	 * diretiva do PHP, não algo que quem gere campanhas saiba resolver.
	 *
	 * @param string $titulo Nome da campanha, para o título do anexo.
	 * @return int|WP_Error ID do anexo.
	 */
	private static function receber_arte( $titulo ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- conferido em `Reconectar_Painel_Gestao::processar()`.
		$codigo = (int) $_FILES[ self::CAMPO_ARTE ]['error'];

		if ( UPLOAD_ERR_INI_SIZE === $codigo || UPLOAD_ERR_FORM_SIZE === $codigo ) {
			return new WP_Error(
				'tamanho',
				sprintf(
					/* translators: %s: tamanho máximo legível, como "2 MB". */
					__( 'A arte passa do limite de %s.', 'reconectar-core' ),
					self::limite_legivel()
				)
			);
		}

		// O front-end não carrega as funções de mídia: elas moram no
		// `/wp-admin`, e `media_handle_upload()` depende das três.
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$anexo_id = media_handle_upload(
			self::CAMPO_ARTE,
			0,
			array( 'post_title' => $titulo ),
			array(
				'test_form' => false,
				'mimes'     => self::FORMATOS,
			)
		);

		if ( is_wp_error( $anexo_id ) && 'upload_error' === $anexo_id->get_error_code() ) {
			// O tipo é conferido pelo conteúdo, não pela extensão: um `.png` que
			// seja SVG por dentro cai aqui, com uma mensagem que fala de
			// "permissões de segurança" e não diz o que fazer.
			return new WP_Error( 'formato', __( 'A arte precisa ser uma imagem JPG, PNG ou WebP.', 'reconectar-core' ) );
		}

		return $anexo_id;
	}

	/**
	 * Manda a campanha para a lixeira.
	 *
	 * Lixeira, e não exclusão: o Super Administrador a restaura ou apaga de vez no
	 * `/wp-admin`, e a arte segue na biblioteca de mídia até lá.
	 *
	 * @param string $item Id da campanha.
	 * @return void
	 */
	private static function mandar_para_a_lixeira( $item ) {
		if ( 'nova' === $item || ! current_user_can( 'delete_post', (int) $item ) ) {
			Reconectar_Painel_Gestao::recusar();
		}

		if ( ! wp_trash_post( (int) $item ) ) {
			Reconectar_Painel_Gestao::redirecionar( self::AREA, $item, 'erro', __( 'Não foi possível enviar a campanha para a lixeira.', 'reconectar-core' ) );
		}

		Reconectar_Painel_Gestao::redirecionar( self::AREA, '', 'na-lixeira' );
	}
}
