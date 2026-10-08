<?php
/**
 * Apagador e exportador de dados pessoais da plataforma.
 *
 * @package reconectar-core
 */

defined( 'ABSPATH' ) || exit;

/**
 * Pendura nas ferramentas de privacidade do núcleo o que só esta plataforma
 * grava.
 *
 * A página `/exclusao-de-dados/` promete o que sai e o que fica, e o pedido é
 * atendido em **Ferramentas → Apagar dados pessoais** do `/wp-admin`. O núcleo,
 * o WooCommerce e o Dokan já registram apagadores próprios — comentários comuns,
 * endereços, perfil e dados de recebimento da loja —, e nenhum deles alcança o
 * que está abaixo:
 *
 *   - os três mapas de voto (`user_id => escolha`), no fórum, na Incubadora e
 *     nas enquetes. Excluir a conta **não** os limpa: o ID fica como chave de um
 *     array serializado, e ali nenhuma rotina do núcleo procura;
 *   - os comentários da Incubadora, que não guardam e-mail — e é por e-mail que
 *     o apagador do núcleo encontra comentário;
 *   - o IP que o bbPress grava em cada publicação, e as assinaturas e favoritos;
 *   - o vínculo do login social, que o Nextend exporta e não apaga;
 *   - a loja: desativar e mandar os produtos para a lixeira.
 *
 * O apagador anonimiza e **não** exclui a conta. É a ordem que o núcleo impõe:
 * todo apagador acha o titular pelo e-mail, e uma conta excluída no meio da
 * fila deixaria os seguintes sem titular, em silêncio. Excluir a conta é o
 * passo seguinte, em Usuários.
 */
class Reconectar_Privacidade {

	/**
	 * Produtos mandados para a lixeira por chamada do apagador.
	 *
	 * O núcleo chama de novo enquanto `done` for falso, e cada chamada é uma
	 * requisição AJAX: uma loja com centenas de produtos, num lote só, esbarraria
	 * no `max_execution_time` no meio e deixaria o pedido pela metade.
	 */
	const LOTE_DE_PRODUTOS = 50;

	/**
	 * Registra o apagador e o exportador.
	 *
	 * @return void
	 */
	public static function init() {
		add_filter( 'wp_privacy_personal_data_erasers', array( __CLASS__, 'registrar_apagador' ) );
		add_filter( 'wp_privacy_personal_data_exporters', array( __CLASS__, 'registrar_exportador' ) );
	}

	/**
	 * Acrescenta o apagador da plataforma à lista do núcleo.
	 *
	 * @param array $apagadores Apagadores já registrados.
	 * @return array
	 */
	public static function registrar_apagador( $apagadores ) {
		$apagadores['reconectar'] = array(
			'eraser_friendly_name' => __( 'Reconectar — participação, login social e loja', 'reconectar-core' ),
			'callback'             => array( __CLASS__, 'apagar' ),
		);

		return $apagadores;
	}

	/**
	 * Acrescenta o exportador da plataforma à lista do núcleo.
	 *
	 * @param array $exportadores Exportadores já registrados.
	 * @return array
	 */
	public static function registrar_exportador( $exportadores ) {
		$exportadores['reconectar'] = array(
			'exporter_friendly_name' => __( 'Reconectar — participação e comprovantes', 'reconectar-core' ),
			'callback'               => array( __CLASS__, 'exportar' ),
		);

		return $exportadores;
	}

	/* ---------------------------------------------------------------------
	 * Apagador
	 * ------------------------------------------------------------------ */

	/**
	 * Apaga ou anonimiza os dados do titular do e-mail.
	 *
	 * Só a lixeira de produtos é paginada. O resto roda na primeira página, e só
	 * nela: repetir em cada página não erraria — tudo ali é idempotente —, mas
	 * somaria a mesma mensagem uma vez por lote no relatório do pedido.
	 *
	 * @param string $email  E-mail do titular.
	 * @param int    $pagina Página, a partir de 1.
	 * @return array No formato que `wp_privacy_process_personal_data_erasure_page()` espera.
	 */
	public static function apagar( $email, $pagina = 1 ) {
		$resposta = array(
			'items_removed'  => false,
			'items_retained' => false,
			'messages'       => array(),
			'done'           => true,
		);

		$usuario = get_user_by( 'email', $email );

		if ( ! $usuario ) {
			return $resposta;
		}

		$usuario_id = (int) $usuario->ID;

		if ( 1 === (int) $pagina ) {
			$passos = array(
				self::apagar_votos( $usuario_id ),
				self::anonimizar_comentarios_da_incubadora( $usuario_id ),
				self::apagar_rastros_do_forum( $usuario_id ),
				self::apagar_login_social( $usuario_id ),
				self::desativar_loja( $usuario_id ),
				self::relatar_comprovantes( $usuario_id ),
			);

			foreach ( $passos as $passo ) {
				$resposta['items_removed']  = $resposta['items_removed'] || $passo['removido'];
				$resposta['items_retained'] = $resposta['items_retained'] || $passo['retido'];
				$resposta['messages']       = array_merge( $resposta['messages'], $passo['mensagens'] );
			}
		}

		$produtos = self::mandar_produtos_para_a_lixeira( $usuario_id );

		$resposta['items_removed'] = $resposta['items_removed'] || $produtos['removido'];
		$resposta['messages']      = array_merge( $resposta['messages'], $produtos['mensagens'] );
		$resposta['done']          = $produtos['concluido'];

		return $resposta;
	}

	/**
	 * Tira o titular dos três mapas de voto e recalcula o que deriva deles.
	 *
	 * O voto é **removido**, e não atribuído a um anônimo: o saldo, o "Gostei" e
	 * o placar mudam. É o que a página de exclusão promete, e um voto mantido sem
	 * dono seria um voto que ninguém mais pode desfazer.
	 *
	 * @param int $usuario_id Titular.
	 * @return array Resultado do passo; veja `resultado()`.
	 */
	private static function apagar_votos( $usuario_id ) {
		$removidos = 0;

		foreach ( self::votos_do_usuario( $usuario_id ) as $voto ) {
			$mapa = get_post_meta( $voto['post_id'], $voto['meta'], true );

			if ( ! is_array( $mapa ) || ! array_key_exists( $usuario_id, $mapa ) ) {
				continue;
			}

			unset( $mapa[ $usuario_id ] );

			if ( empty( $mapa ) ) {
				delete_post_meta( $voto['post_id'], $voto['meta'] );
			} else {
				update_post_meta( $voto['post_id'], $voto['meta'], $mapa );
			}

			self::recalcular_derivados( $voto['post_id'], $voto['meta'], $mapa );
			++$removidos;
		}

		return self::resultado(
			$removidos > 0,
			false,
			$removidos
				/* translators: %d: quantidade de votos e avaliações. */
				? array( sprintf( _n( '%d voto ou avaliação removido.', '%d votos e avaliações removidos.', $removidos, 'reconectar-core' ), $removidos ) )
				: array()
		);
	}

	/**
	 * Regrava os totais que o fórum e a Incubadora guardam ao lado do mapa.
	 *
	 * As contas são as mesmas de `Reconectar_Forum::votar()` e
	 * `Reconectar_Incubadora_Interacao::avaliar()`, que não servem aqui porque
	 * alternam o voto de quem está logado. A enquete não tem total gravado: o
	 * placar sai do mapa a cada leitura.
	 *
	 * @param int    $post_id Post do mapa.
	 * @param string $meta    Chave do mapa.
	 * @param array  $mapa    Mapa já sem o titular.
	 * @return void
	 */
	private static function recalcular_derivados( $post_id, $meta, $mapa ) {
		if ( Reconectar_Forum::META_VOTANTES === $meta ) {
			update_post_meta( $post_id, Reconectar_Forum::META_VOTOS, array_sum( array_map( 'intval', $mapa ) ) );

			return;
		}

		if ( Reconectar_Incubadora_Interacao::META_AVALIACOES === $meta ) {
			$contagem = array_count_values( array_map( 'intval', $mapa ) );

			update_post_meta( $post_id, Reconectar_Incubadora_Interacao::META_GOSTEI, isset( $contagem[1] ) ? $contagem[1] : 0 );
			update_post_meta( $post_id, Reconectar_Incubadora_Interacao::META_NAO_GOSTEI, isset( $contagem[-1] ) ? $contagem[-1] : 0 );
		}
	}

	/**
	 * Desvincula do titular os comentários da Incubadora.
	 *
	 * Desvincula, e não apaga: uma resposta a ele perderia o contexto, e a página
	 * de exclusão deixa a remoção como escolha de quem pede — feita à mão, depois,
	 * na moderação. Sem `user_id`, `Reconectar_Incubadora_Interacao::autor()` já
	 * imprime "Conta removida"; o nome gravado em `comment_author` passa a dizer
	 * o mesmo, porque é ele que a lista de comentários do `/wp-admin` mostra.
	 *
	 * Vai por SQL, e não por `get_comments()`: o status `rc-oculto` fica de fora
	 * de toda consulta do núcleo que não o peça, e o oculto é justamente o que
	 * não pode sobrar com o nome de alguém.
	 *
	 * @param int $usuario_id Titular.
	 * @return array Resultado do passo; veja `resultado()`.
	 */
	private static function anonimizar_comentarios_da_incubadora( $usuario_id ) {
		global $wpdb;

		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT comment_ID FROM {$wpdb->comments} WHERE user_id = %d AND comment_type = %s",
				$usuario_id,
				Reconectar_Incubadora_Interacao::TIPO_COMENTARIO
			)
		);

		foreach ( $ids as $id ) {
			$wpdb->update(
				$wpdb->comments,
				array(
					'user_id'              => 0,
					'comment_author'       => __( 'Conta removida', 'reconectar-core' ),
					'comment_author_email' => '',
					'comment_author_url'   => '',
					'comment_author_IP'    => '',
					'comment_agent'        => '',
				),
				array( 'comment_ID' => (int) $id )
			);
			clean_comment_cache( (int) $id );
		}

		$total = count( $ids );

		return self::resultado(
			$total > 0,
			false,
			$total
				/* translators: %d: quantidade de comentários. */
				? array( sprintf( _n( '%d comentário na Incubadora desvinculado da conta.', '%d comentários na Incubadora desvinculados da conta.', $total, 'reconectar-core' ), $total ) )
				: array()
		);
	}

	/**
	 * Tira do fórum o IP gravado, as assinaturas, os favoritos e as marcas de
	 * leitura.
	 *
	 * Os tópicos e as respostas em si ficam: quem decide se saem ou mudam de
	 * autor é a exclusão da conta, onde o núcleo pergunta. O IP sai já, porque
	 * sobreviveria a qualquer das duas escolhas.
	 *
	 * As marcas de leitura (`reconectar_lido_<usuário>_<tópico>`) expiram em 12
	 * horas, e mesmo assim saem: até lá dizem quais tópicos aquela pessoa abriu.
	 *
	 * @param int $usuario_id Titular.
	 * @return array Resultado do passo; veja `resultado()`.
	 */
	private static function apagar_rastros_do_forum( $usuario_id ) {
		global $wpdb;

		$publicacoes = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT p.ID FROM {$wpdb->posts} p
				INNER JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = '_bbp_author_ip'
				WHERE p.post_author = %d AND p.post_type IN ( 'topic', 'reply' )",
				$usuario_id
			)
		);

		foreach ( $publicacoes as $post_id ) {
			delete_post_meta( (int) $post_id, '_bbp_author_ip' );
		}

		// O bbPress 2.6 guarda assinatura e favorito como uma linha de meta por
		// pessoa no post, com o ID dela como valor.
		$vinculos = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT post_id, meta_key FROM {$wpdb->postmeta}
				WHERE meta_key IN ( '_bbp_subscription', '_bbp_favorite' ) AND meta_value = %s",
				(string) $usuario_id
			)
		);

		foreach ( $vinculos as $vinculo ) {
			delete_post_meta( (int) $vinculo->post_id, $vinculo->meta_key, (string) $usuario_id );
		}

		$marcas = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s",
				$wpdb->esc_like( '_transient_reconectar_lido_' . $usuario_id . '_' ) . '%'
			)
		);

		foreach ( $marcas as $marca ) {
			delete_transient( substr( $marca, strlen( '_transient_' ) ) );
		}

		$mensagens = array();

		if ( $publicacoes ) {
			$mensagens[] = sprintf(
				/* translators: %d: quantidade de publicações. */
				_n( 'IP removido de %d publicação no fórum.', 'IP removido de %d publicações no fórum.', count( $publicacoes ), 'reconectar-core' ),
				count( $publicacoes )
			);
		}

		if ( $vinculos ) {
			$mensagens[] = __( 'Assinaturas e favoritos do fórum removidos.', 'reconectar-core' );
		}

		return self::resultado( $publicacoes || $vinculos || $marcas, false, $mensagens );
	}

	/**
	 * Desfaz o vínculo com Google, Facebook e qualquer outro provedor.
	 *
	 * Pela API do Nextend, e não por `DELETE` na tabela dele: é o plugin que
	 * sabe o que mais acompanha o vínculo. O laço passa por todos os provedores,
	 * ligados ou não — um provedor desligado depois do cadastro continua com a
	 * linha de quem entrou por ele.
	 *
	 * A lista mistura provedores reais com as vitrines da versão Pro
	 * (`NextendSocialProviderDummy`: Slack, Apple, GitHub…), que não herdam de
	 * `NextendSocialProvider` nem têm a API de vínculo. Medido: chamar
	 * `isUserConnected()` num deles é erro fatal, e o apagador morria depois de
	 * ter removido o Google e antes de desativar a loja.
	 *
	 * @param int $usuario_id Titular.
	 * @return array Resultado do passo; veja `resultado()`.
	 */
	private static function apagar_login_social( $usuario_id ) {
		if ( ! class_exists( 'NextendSocialLogin' ) || ! class_exists( 'NextendSocialProvider' ) || empty( NextendSocialLogin::$providers ) ) {
			return self::resultado( false, false, array() );
		}

		$mensagens = array();

		foreach ( NextendSocialLogin::$providers as $provedor ) {
			if ( ! $provedor instanceof NextendSocialProvider || ! $provedor->isUserConnected( $usuario_id ) ) {
				continue;
			}

			$provedor->removeConnectionByUserID( $usuario_id );

			$mensagens[] = sprintf(
				/* translators: %s: nome do provedor, como Google ou Facebook. */
				__( 'Vínculo com %s removido.', 'reconectar-core' ),
				$provedor->getLabel()
			);
		}

		return self::resultado( ! empty( $mensagens ), false, $mensagens );
	}

	/**
	 * Desativa a loja do titular, se ele tiver uma.
	 *
	 * Pelo mesmo estado individual que o painel de empresas grava, e não por
	 * `dokan_enable_selling` direto: senão reativar a empresa da loja a poria de
	 * volta em operação. Os dados de recebimento — PIX, banco, telefone,
	 * endereço — quem apaga é o apagador do Dokan, que zera o perfil inteiro.
	 *
	 * @param int $usuario_id Titular.
	 * @return array Resultado do passo; veja `resultado()`.
	 */
	private static function desativar_loja( $usuario_id ) {
		if ( ! user_can( $usuario_id, 'dokandar' ) || 'nao' === get_user_meta( $usuario_id, Reconectar_Empresa::META_LOJA_ATIVA, true ) ) {
			return self::resultado( false, false, array() );
		}

		update_user_meta( $usuario_id, Reconectar_Empresa::META_LOJA_ATIVA, 'nao' );
		Reconectar_Empresa::aplicar_permissao_de_venda( $usuario_id );

		return self::resultado( true, false, array( __( 'Loja desativada.', 'reconectar-core' ) ) );
	}

	/**
	 * Manda para a lixeira um lote de produtos do titular.
	 *
	 * Lixeira, e não exclusão definitiva: um pedido de exclusão chega por e-mail,
	 * e o engano de titular só se desfaz se houver de onde voltar. A lixeira do
	 * WordPress se esvazia sozinha em 30 dias.
	 *
	 * A consulta é sempre a primeira página dos que restam, e não uma página
	 * pelo número que o núcleo passa: cada lote sai do conjunto, e um deslocamento
	 * pularia produtos.
	 *
	 * @param int $usuario_id Titular.
	 * @return array Resultado do passo, mais `concluido`.
	 */
	private static function mandar_produtos_para_a_lixeira( $usuario_id ) {
		$produtos = get_posts(
			array(
				'post_type'      => 'product',
				'author'         => $usuario_id,
				'post_status'    => array( 'publish', 'draft', 'pending', 'private', 'future' ),
				'fields'         => 'ids',
				'posts_per_page' => self::LOTE_DE_PRODUTOS,
				'orderby'        => 'ID',
				'order'          => 'ASC',
			)
		);

		$movidos = 0;

		foreach ( $produtos as $produto_id ) {
			if ( wp_trash_post( $produto_id ) ) {
				++$movidos;
			}
		}

		$resultado = self::resultado(
			$movidos > 0,
			false,
			$movidos
				/* translators: %d: quantidade de produtos. */
				? array( sprintf( _n( '%d produto da loja enviado para a lixeira.', '%d produtos da loja enviados para a lixeira.', $movidos, 'reconectar-core' ), $movidos ) )
				: array()
		);

		// Um lote que falhou inteiro encerra mesmo assim: repetir a mesma consulta
		// traria os mesmos produtos, e o núcleo chamaria de novo para sempre.
		$resultado['concluido'] = count( $produtos ) < self::LOTE_DE_PRODUTOS || 0 === $movidos;

		return $resultado;
	}

	/**
	 * Relata os comprovantes que ficam, sem tocar neles.
	 *
	 * A política de privacidade os retém pelo prazo fiscal, e o relatório do
	 * pedido tem de dizer isso com o número: "nada foi retido" seria falso, e a
	 * resposta ao titular é escrita a partir desta tela.
	 *
	 * @param int $usuario_id Titular.
	 * @return array Resultado do passo; veja `resultado()`.
	 */
	private static function relatar_comprovantes( $usuario_id ) {
		$total = count( self::comprovantes_do_usuario( $usuario_id ) );

		return self::resultado(
			false,
			$total > 0,
			$total
				/* translators: %d: quantidade de comprovantes. */
				? array( sprintf( _n( '%d comprovante de pagamento mantido pelo prazo da legislação fiscal.', '%d comprovantes de pagamento mantidos pelo prazo da legislação fiscal.', $total, 'reconectar-core' ), $total ) )
				: array()
		);
	}

	/* ---------------------------------------------------------------------
	 * Exportador
	 * ------------------------------------------------------------------ */

	/**
	 * Exporta votos, avaliações, comentários da Incubadora e comprovantes.
	 *
	 * Uma página só: são algumas dezenas de linhas por pessoa, e o arquivo dos
	 * comprovantes não vai junto — sai pela rota autoral, que confere o direito
	 * de quem baixa. O que vai é o registro de que ele existe.
	 *
	 * @param string $email  E-mail do titular.
	 * @param int    $pagina Página, a partir de 1.
	 * @return array No formato que `wp_privacy_process_personal_data_export_page()` espera.
	 */
	public static function exportar( $email, $pagina = 1 ) {
		$usuario = get_user_by( 'email', $email );

		if ( ! $usuario || 1 !== (int) $pagina ) {
			return array(
				'data' => array(),
				'done' => true,
			);
		}

		$usuario_id = (int) $usuario->ID;

		return array(
			'data' => array_merge(
				self::exportar_votos( $usuario_id ),
				self::exportar_comentarios( $usuario_id ),
				self::exportar_comprovantes( $usuario_id )
			),
			'done' => true,
		);
	}

	/**
	 * Itens de exportação dos três mapas de voto.
	 *
	 * @param int $usuario_id Titular.
	 * @return array[]
	 */
	private static function exportar_votos( $usuario_id ) {
		$itens = array();

		foreach ( self::votos_do_usuario( $usuario_id ) as $voto ) {
			$post_id = $voto['post_id'];

			if ( Reconectar_Forum::META_VOTANTES === $voto['meta'] ) {
				$grupo  = array( 'reconectar-votos-forum', __( 'Votos na Comunidade', 'reconectar-core' ) );
				$rotulo = __( 'Voto', 'reconectar-core' );
				$valor  = 0 < (int) $voto['valor'] ? __( 'Positivo', 'reconectar-core' ) : __( 'Negativo', 'reconectar-core' );
			} elseif ( Reconectar_Incubadora_Interacao::META_AVALIACOES === $voto['meta'] ) {
				$grupo  = array( 'reconectar-avaliacoes-incubadora', __( 'Avaliações na Incubadora', 'reconectar-core' ) );
				$rotulo = __( 'Avaliação', 'reconectar-core' );
				$valor  = 0 < (int) $voto['valor'] ? __( 'Gostei', 'reconectar-core' ) : __( 'Não gostei', 'reconectar-core' );
			} else {
				$grupo  = array( 'reconectar-votos-enquete', __( 'Votos em enquetes', 'reconectar-core' ) );
				$rotulo = __( 'Alternativa escolhida', 'reconectar-core' );
				$valor  = self::texto_da_alternativa( $post_id, $voto['valor'] );
			}

			$itens[] = array(
				'group_id'    => $grupo[0],
				'group_label' => $grupo[1],
				'item_id'     => $grupo[0] . '-' . $post_id,
				'data'        => array(
					array(
						'name'  => __( 'Conteúdo', 'reconectar-core' ),
						'value' => self::titulo( $post_id ),
					),
					array(
						'name'  => __( 'Endereço', 'reconectar-core' ),
						'value' => (string) get_permalink( $post_id ),
					),
					array(
						'name'  => $rotulo,
						'value' => $valor,
					),
				),
			);
		}

		return $itens;
	}

	/**
	 * Itens de exportação dos comentários na Incubadora, ocultos inclusive.
	 *
	 * @param int $usuario_id Titular.
	 * @return array[]
	 */
	private static function exportar_comentarios( $usuario_id ) {
		global $wpdb;

		$comentarios = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT comment_ID, comment_post_ID, comment_date, comment_content FROM {$wpdb->comments}
				WHERE user_id = %d AND comment_type = %s ORDER BY comment_ID",
				$usuario_id,
				Reconectar_Incubadora_Interacao::TIPO_COMENTARIO
			)
		);

		$itens = array();

		foreach ( $comentarios as $comentario ) {
			$itens[] = array(
				'group_id'    => 'reconectar-comentarios-incubadora',
				'group_label' => __( 'Comentários na Incubadora', 'reconectar-core' ),
				'item_id'     => 'reconectar-comentario-' . $comentario->comment_ID,
				'data'        => array(
					array(
						'name'  => __( 'Página', 'reconectar-core' ),
						'value' => self::titulo( (int) $comentario->comment_post_ID ),
					),
					array(
						'name'  => __( 'Data', 'reconectar-core' ),
						'value' => $comentario->comment_date,
					),
					array(
						'name'  => __( 'Comentário', 'reconectar-core' ),
						'value' => $comentario->comment_content,
					),
				),
			);
		}

		return $itens;
	}

	/**
	 * Itens de exportação dos comprovantes que o titular enviou.
	 *
	 * @param int $usuario_id Titular.
	 * @return array[]
	 */
	private static function exportar_comprovantes( $usuario_id ) {
		$itens = array();

		foreach ( self::comprovantes_do_usuario( $usuario_id ) as $pedido_id => $comprovante ) {
			$itens[] = array(
				'group_id'    => 'reconectar-comprovantes',
				'group_label' => __( 'Comprovantes de pagamento', 'reconectar-core' ),
				'item_id'     => 'reconectar-comprovante-' . $pedido_id,
				'data'        => array(
					array(
						'name'  => __( 'Pedido', 'reconectar-core' ),
						'value' => '#' . $pedido_id,
					),
					array(
						'name'  => __( 'Arquivo enviado', 'reconectar-core' ),
						'value' => isset( $comprovante['nome'] ) ? (string) $comprovante['nome'] : '',
					),
					array(
						'name'  => __( 'Enviado em', 'reconectar-core' ),
						'value' => isset( $comprovante['enviado_em'] ) ? (string) $comprovante['enviado_em'] : '',
					),
				),
			);
		}

		return $itens;
	}

	/* ---------------------------------------------------------------------
	 * Apoio
	 * ------------------------------------------------------------------ */

	/**
	 * Os votos do titular nos três mapas.
	 *
	 * O `LIKE` por `i:<id>;` é só um pré-filtro: ele casa também com **valor** —
	 * o voto `1` serializa como `i:1;`, e o titular de ID 1 traria todo post
	 * votado. Quem decide é a leitura do mapa em PHP, pela chave.
	 *
	 * @param int $usuario_id Titular.
	 * @return array[] Lista de `array( 'post_id', 'meta', 'valor' )`.
	 */
	private static function votos_do_usuario( $usuario_id ) {
		global $wpdb;

		$linhas = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT post_id, meta_key, meta_value FROM {$wpdb->postmeta}
				WHERE meta_key IN ( %s, %s, %s ) AND meta_value LIKE %s ORDER BY post_id",
				Reconectar_Forum::META_VOTANTES,
				Reconectar_Incubadora_Interacao::META_AVALIACOES,
				Reconectar_Proposta_Votacao::META_VOTANTES,
				'%' . $wpdb->esc_like( 'i:' . $usuario_id . ';' ) . '%'
			)
		);

		$votos = array();

		foreach ( $linhas as $linha ) {
			$mapa = maybe_unserialize( $linha->meta_value );

			if ( ! is_array( $mapa ) || ! array_key_exists( $usuario_id, $mapa ) ) {
				continue;
			}

			$votos[] = array(
				'post_id' => (int) $linha->post_id,
				'meta'    => $linha->meta_key,
				'valor'   => $mapa[ $usuario_id ],
			);
		}

		return $votos;
	}

	/**
	 * Os comprovantes que o titular enviou, por pedido.
	 *
	 * Filtra em PHP de propósito: `wc_get_orders()` descarta em silêncio filtro
	 * por meta e devolveria todos os pedidos. `customer` é um dos argumentos que
	 * ele respeita, e o comprovante mora no sub-pedido, que herda o comprador.
	 *
	 * @param int $usuario_id Titular.
	 * @return array[] `array( pedido_id => meta do comprovante )`.
	 */
	private static function comprovantes_do_usuario( $usuario_id ) {
		if ( ! function_exists( 'wc_get_orders' ) ) {
			return array();
		}

		$pedidos = wc_get_orders(
			array(
				'customer' => $usuario_id,
				'limit'    => -1,
				'status'   => array_keys( wc_get_order_statuses() ),
			)
		);

		$comprovantes = array();

		foreach ( $pedidos as $pedido ) {
			$comprovante = $pedido->get_meta( Reconectar_Comprovante::META_ARQUIVO );

			if ( is_array( $comprovante ) && (int) ( isset( $comprovante['enviado_por'] ) ? $comprovante['enviado_por'] : 0 ) === $usuario_id ) {
				$comprovantes[ $pedido->get_id() ] = $comprovante;
			}
		}

		return $comprovantes;
	}

	/**
	 * O texto da alternativa escolhida, ou o ID dela se já não existir.
	 *
	 * @param int    $enquete_id Enquete.
	 * @param string $opcao_id   Alternativa.
	 * @return string
	 */
	private static function texto_da_alternativa( $enquete_id, $opcao_id ) {
		foreach ( Reconectar_Proposta_Votacao::opcoes( $enquete_id ) as $opcao ) {
			if ( $opcao['id'] === (string) $opcao_id ) {
				return $opcao['texto'];
			}
		}

		return (string) $opcao_id;
	}

	/**
	 * Título de um post, com a resposta do fórum nomeada pelo tópico.
	 *
	 * A resposta do bbPress nasce com o título "Resposta a: …" ou vazio,
	 * conforme o caminho que a criou; o tópico dela é o que a pessoa reconhece.
	 *
	 * @param int $post_id Post.
	 * @return string
	 */
	private static function titulo( $post_id ) {
		if ( 'reply' === get_post_type( $post_id ) ) {
			$topico_id = (int) get_post_meta( $post_id, '_bbp_topic_id', true );

			if ( $topico_id ) {
				/* translators: %s: título do tópico. */
				return sprintf( __( 'Resposta em: %s', 'reconectar-core' ), get_the_title( $topico_id ) );
			}
		}

		return get_the_title( $post_id );
	}

	/**
	 * Resultado de um passo do apagador.
	 *
	 * @param bool     $removido  Algo foi apagado ou anonimizado.
	 * @param bool     $retido    Algo foi mantido de propósito.
	 * @param string[] $mensagens Linhas do relatório.
	 * @return array
	 */
	private static function resultado( $removido, $retido, $mensagens ) {
		return array(
			'removido'  => (bool) $removido,
			'retido'    => (bool) $retido,
			'mensagens' => $mensagens,
		);
	}
}
