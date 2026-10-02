<?php
/**
 * Escrita da Incubadora: salvar, criar, mover e excluir páginas.
 *
 * Os quatro pontos de entrada passam por `admin-post.php` e respondem JSON. A
 * rota é a mesma do voto do fórum e do comprovante, e já está excetuada em
 * `Reconectar_Permissoes::bloquear_area_administrativa()` — um endpoint em
 * qualquer outro lugar do `/wp-admin` devolveria 302 para a área do usuário, e
 * a gravação simplesmente não aconteceria.
 *
 * Cada ação é dividida em duas metades. O handler (`processar_*`) cuida do
 * transporte: método, sessão, capacidade e nonce, nessa ordem. A operação
 * (`salvar`, `criar`, `mover`, `excluir`) cuida do domínio — a página existe, é deste
 * post type, o usuário pode mexer **nela** — e devolve array ou `WP_Error`. A
 * divisão existe para a verificação: `wp_send_json()` encerra o processo, e
 * um teste por WP-CLI que chamasse o handler morreria junto. Pela operação, o
 * caminho feliz e a segunda camada de recusa ficam mensuráveis sem nonce, que
 * o WP-CLI não consegue gerar de forma que o servidor aceite.
 *
 * @package reconectar-core
 */

defined( 'ABSPATH' ) || exit;

/**
 * Endpoints de escrita da Incubadora.
 */
class Reconectar_Incubadora_Acoes {

	/**
	 * Prefixo das ações de `admin-post.php` e dos nonces.
	 *
	 * Um nonce por ação, e não por página: o editor cria página nova, que ainda
	 * não tem ID para entrar na ação do nonce. O que amarra o pedido à página é a
	 * conferência de `edit_post` dentro da operação.
	 */
	const PREFIXO = 'reconectar_incubadora_';

	/**
	 * Ações expostas, com o método que as processa.
	 *
	 * @var array<string, string>
	 */
	const ACOES = array(
		'salvar'  => 'processar_salvar',
		'criar'   => 'processar_criar',
		'mover'   => 'processar_mover',
		'excluir' => 'processar_excluir',
	);

	/**
	 * Registra os handlers.
	 *
	 * O `nopriv` não é cortesia: sem ele, `admin-post.php` responde ao visitante
	 * com um 400 de corpo vazio, que o editor leria como erro de servidor. Com
	 * ele, o visitante cai no mesmo handler e recebe o 401 que diz o que fazer —
	 * entrar de novo, porque a sessão expirou com a página aberta.
	 *
	 * @return void
	 */
	public static function init() {
		foreach ( self::ACOES as $acao => $metodo ) {
			add_action( 'admin_post_' . self::PREFIXO . $acao, array( __CLASS__, $metodo ) );
			add_action( 'admin_post_nopriv_' . self::PREFIXO . $acao, array( __CLASS__, $metodo ) );
		}
	}

	/**
	 * Nome completo de uma ação, que é também a ação do nonce dela.
	 *
	 * @param string $acao `salvar`, `criar`, `mover` ou `excluir`.
	 * @return string
	 */
	public static function acao( $acao ) {
		return self::PREFIXO . $acao;
	}

	/* ---------------------------------------------------------------------
	 * Handlers
	 * ------------------------------------------------------------------ */

	/**
	 * Handler de `salvar`.
	 *
	 * @return void
	 */
	public static function processar_salvar() {
		self::exigir_requisicao( 'salvar' );

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- conferido em `exigir_requisicao()`.
		$resultado = self::salvar(
			array(
				'pagina'     => isset( $_POST['pagina'] ) ? absint( $_POST['pagina'] ) : 0,
				'titulo'     => isset( $_POST['titulo'] ) ? wp_unslash( $_POST['titulo'] ) : null, // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitizado em `salvar()`.
				'conteudo'   => isset( $_POST['conteudo'] ) ? wp_unslash( $_POST['conteudo'] ) : null, // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- reconstruído em `salvar()`.
				'modificado' => isset( $_POST['modificado'] ) ? sanitize_text_field( wp_unslash( $_POST['modificado'] ) ) : '',
				'publicar'   => ! empty( $_POST['publicar'] ),
				'forcar'     => ! empty( $_POST['forcar'] ),
			)
		);
		// phpcs:enable

		self::responder( $resultado );
	}

	/**
	 * Handler de `criar`.
	 *
	 * @return void
	 */
	public static function processar_criar() {
		self::exigir_requisicao( 'criar' );

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- conferido em `exigir_requisicao()`.
		$resultado = self::criar(
			array(
				'titulo' => isset( $_POST['titulo'] ) ? wp_unslash( $_POST['titulo'] ) : '', // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitizado em `criar()`.
				'mae'    => isset( $_POST['mae'] ) ? absint( $_POST['mae'] ) : 0,
			)
		);
		// phpcs:enable

		self::responder( $resultado );
	}

	/**
	 * Handler de `mover`.
	 *
	 * A resposta leva, além do resultado, a árvore e a trilha já refeitas. Mover
	 * muda o endereço da página e de toda a subárvore dela: remendar no
	 * navegador os `href` de cada descendente, o chevron da mãe que ganhou a
	 * primeira filha e o da que perdeu a última seria reimplementar
	 * `arvore.php` em JavaScript — e as duas versões divergiriam no primeiro
	 * ajuste. `atual` e `abertos` só servem a esse desenho; não entram na
	 * operação.
	 *
	 * @return void
	 */
	public static function processar_mover() {
		self::exigir_requisicao( 'mover' );

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- conferido em `exigir_requisicao()`.
		$resultado = self::mover(
			array(
				'pagina' => isset( $_POST['pagina'] ) ? absint( $_POST['pagina'] ) : 0,
				'pai'    => isset( $_POST['pai'] ) ? absint( $_POST['pai'] ) : 0,
				'ordem'  => isset( $_POST['ordem'] ) ? array_map( 'absint', (array) wp_unslash( $_POST['ordem'] ) ) : array(),
			)
		);

		if ( ! is_wp_error( $resultado ) ) {
			$abertos   = isset( $_POST['abertos'] ) ? array_map( 'absint', (array) wp_unslash( $_POST['abertos'] ) ) : array();
			$abertos[] = $resultado['pai'];

			$atual     = isset( $_POST['atual'] ) ? absint( $_POST['atual'] ) : 0;

			// O endereço da página aberta muda se ela — ou uma ancestral dela —
			// foi a movida; a tela corrige a barra de endereço com ele.
			$resultado = array_merge(
				$resultado,
				Reconectar_Incubadora_Leitura::fragmentos( $atual, $abertos ),
				array( 'url_atual' => $atual ? self::caminho( get_permalink( $atual ) ) : '' )
			);
		}
		// phpcs:enable

		self::responder( $resultado );
	}

	/**
	 * Handler de `excluir`.
	 *
	 * @return void
	 */
	public static function processar_excluir() {
		self::exigir_requisicao( 'excluir' );

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- conferido em `exigir_requisicao()`.
		$resultado = self::excluir( isset( $_POST['pagina'] ) ? absint( $_POST['pagina'] ) : 0 );

		self::responder( $resultado );
	}

	/* ---------------------------------------------------------------------
	 * Operações
	 * ------------------------------------------------------------------ */

	/**
	 * Grava título e conteúdo de uma página, e a publica se pedido.
	 *
	 * Bloqueio otimista: o editor manda o `post_modified_gmt` que viu ao abrir, e
	 * se a página mudou desde então a gravação é recusada com 409. Sem isso, duas
	 * pessoas editando a mesma página perderiam o trabalho uma da outra, e a
	 * última a salvar nem saberia que apagou algo. `forcar` é a resposta
	 * consciente a esse 409 — o editor só o manda depois de perguntar.
	 *
	 * A granularidade é de segundo, a do campo. Duas gravações no mesmo segundo
	 * não se detectam; para uma wiki editada à mão, é uma janela aceitável.
	 *
	 * @param array $dados {
	 *     @type int         $pagina     ID da página.
	 *     @type string|null $titulo     Título; `null` mantém o atual.
	 *     @type string|null $conteudo   HTML do editor, sem barras; `null` mantém o atual.
	 *     @type string      $modificado `post_modified_gmt` visto pelo editor.
	 *     @type bool        $publicar   Publica um rascunho.
	 *     @type bool        $forcar     Ignora o conflito de versão.
	 * }
	 * @return array|WP_Error
	 */
	public static function salvar( $dados ) {
		$dados = wp_parse_args(
			$dados,
			array(
				'pagina'     => 0,
				'titulo'     => null,
				'conteudo'   => null,
				'modificado' => '',
				'publicar'   => false,
				'forcar'     => false,
			)
		);

		$pagina = self::pagina_editavel( (int) $dados['pagina'], 'edit_post' );

		if ( is_wp_error( $pagina ) ) {
			return $pagina;
		}

		// Sem `DOMDocument` o sanitizador devolve conteúdo vazio com um aviso. Na
		// gravação, isso apagaria o corpo da página inteira por um problema de
		// infraestrutura; recusar mantém o que já estava lá.
		if ( null !== $dados['conteudo'] && ! class_exists( 'DOMDocument' ) ) {
			return self::erro( 'servidor', __( 'O servidor não tem a extensão DOM do PHP, e sem ela o conteúdo não pode ser conferido. Nada foi gravado.', 'reconectar-core' ), 500 );
		}

		if ( ! $dados['forcar'] && $dados['modificado'] !== $pagina->post_modified_gmt ) {
			return self::erro(
				'conflito',
				sprintf(
					/* translators: %s: nome de quem editou por último. */
					__( 'Esta página foi alterada por %s depois que você a abriu. Copie o seu texto antes de recarregar, ou salve por cima da versão nova.', 'reconectar-core' ),
					Reconectar_Incubadora_Leitura::nome_de_usuario( Reconectar_Incubadora_Leitura::editado_por( $pagina ) )
				),
				409,
				array( 'modificado' => $pagina->post_modified_gmt )
			);
		}

		$titulo = null === $dados['titulo'] ? $pagina->post_title : Reconectar_Incubadora_Conteudo::titulo( $dados['titulo'] );

		// Título vazio deixaria a página sem nome na árvore e sem slug na
		// primeira publicação — `sanitize_title( '' )` devolve vazio, e o núcleo
		// cairia no ID como slug.
		if ( '' === trim( $titulo ) ) {
			return self::erro( 'titulo_vazio', __( 'Dê um título à página antes de salvar.', 'reconectar-core' ), 422 );
		}

		$avisos = array();

		if ( null === $dados['conteudo'] ) {
			$conteudo = $pagina->post_content;
		} else {
			$limpo    = Reconectar_Incubadora_Conteudo::sanitizar( $dados['conteudo'] );
			$conteudo = $limpo['html'];
			$avisos   = $limpo['avisos'];
		}

		$publicar = $dados['publicar'] && 'publish' !== $pagina->post_status;

		// Sem alteração real, nada é gravado: cada `wp_update_post()` gera uma
		// revisão, e com o teto de 30 um "Atualizar" clicado por hábito
		// empurraria para fora do histórico as versões que importam.
		if ( ! $publicar && $titulo === $pagina->post_title && $conteudo === $pagina->post_content ) {
			return array_merge(
				self::estado( $pagina ),
				array(
					'codigo' => 'sem_alteracoes',
					'avisos' => $avisos,
				)
			);
		}

		$campos = array(
			'ID'           => $pagina->ID,
			'post_title'   => $titulo,
			'post_content' => $conteudo,
		);

		if ( $publicar ) {
			$campos['post_status'] = 'publish';
			// O slug nasce do título provisório da criação ("Página sem título")
			// e é refeito aqui, com o título que a pessoa escolheu. Depois da
			// primeira publicação ele fica fixo: trocar o slug a cada edição de
			// título quebraria todo link já copiado para a página.
			$campos['post_name'] = self::slug_unico( $titulo, $pagina->ID, (int) $pagina->post_parent );
		}

		$gravado = self::gravar( $campos );

		if ( is_wp_error( $gravado ) ) {
			return $gravado;
		}

		$pagina = get_post( $pagina->ID );

		return array_merge(
			self::estado( $pagina ),
			array(
				'codigo' => $publicar ? 'publicada' : 'salva',
				'avisos' => $avisos,
			)
		);
	}

	/**
	 * Cria uma página em rascunho, na raiz ou sob uma mãe.
	 *
	 * Nasce `draft` sempre: a página aparece na árvore de quem escreve e só
	 * chega a loja e comprador quando alguém a publicar de propósito.
	 *
	 * @param array $dados {
	 *     @type string $titulo Título; vazio vira "Página sem título".
	 *     @type int    $mae    ID da página mãe, ou 0 para a raiz.
	 * }
	 * @return array|WP_Error
	 */
	public static function criar( $dados ) {
		$dados = wp_parse_args(
			$dados,
			array(
				'titulo' => '',
				'mae'    => 0,
			)
		);

		$tipo = get_post_type_object( Reconectar_Incubadora::POST_TYPE );

		if ( ! $tipo || ! current_user_can( $tipo->cap->create_posts ) ) {
			return self::erro( 'capacidade', __( 'Você não tem permissão para criar páginas na Incubadora.', 'reconectar-core' ), 403 );
		}

		$mae_id = (int) $dados['mae'];

		if ( $mae_id ) {
			// A mãe pode ser rascunho — é assim que se monta uma seção inteira
			// antes de publicá-la —, mas não pode estar na lixeira, ou a filha
			// nasceria sob um ramo que a árvore não desenha.
			$mae = self::pagina_editavel( $mae_id, 'edit_post' );

			if ( is_wp_error( $mae ) ) {
				return self::erro( 'mae_invalida', __( 'A página onde você quer criar a subpágina não existe mais ou não pode ser editada.', 'reconectar-core' ), 422 );
			}

			// A nova página fica um nível abaixo dos ancestrais da mãe e da
			// própria mãe. O teto protege a recursão da árvore, que não tem outra
			// defesa contra uma escada sem fim.
			if ( count( get_post_ancestors( $mae ) ) + 1 > Reconectar_Incubadora::PROFUNDIDADE_MAXIMA ) {
				return self::erro(
					'profundidade',
					sprintf(
						/* translators: %d: número máximo de níveis. */
						__( 'A Incubadora admite até %d níveis de subpágina. Crie esta página num nível acima.', 'reconectar-core' ),
						Reconectar_Incubadora::PROFUNDIDADE_MAXIMA
					),
					422
				);
			}
		}

		$titulo = Reconectar_Incubadora_Conteudo::titulo( (string) $dados['titulo'] );

		if ( '' === trim( $titulo ) ) {
			$titulo = __( 'Página sem título', 'reconectar-core' );
		}

		$campos = array(
			'post_type'    => Reconectar_Incubadora::POST_TYPE,
			'post_status'  => 'draft',
			'post_title'   => $titulo,
			'post_content' => '',
			'post_parent'  => $mae_id,
			'post_author'  => get_current_user_id(),
			'menu_order'   => self::proxima_ordem( $mae_id ),
			// Explícito porque o núcleo deixa rascunho sem slug, e uma filha
			// publicada sob mãe sem slug teria um segmento vazio no caminho.
			'post_name'    => self::slug_unico( $titulo, 0, $mae_id ),
		);

		$id = self::gravar( $campos );

		if ( is_wp_error( $id ) ) {
			return $id;
		}

		return array_merge(
			self::estado( get_post( $id ) ),
			array( 'codigo' => 'criada' )
		);
	}

	/**
	 * Põe uma página sob outra mãe, ou na raiz, numa posição entre as irmãs.
	 *
	 * `ordem` é a lista **inteira** das filhas do destino, já com a página no
	 * lugar escolhido — e não um índice. Com índice, o servidor teria de supor
	 * que a lista que a pessoa via é a do banco; se alguém criou ou moveu uma
	 * irmã nesse meio-tempo, "terceira posição" cairia ao lado de outra página.
	 * Com a lista inteira, a divergência se detecta: o conjunto recebido tem de
	 * ser exatamente o das filhas atuais mais a página, ou a resposta é 409 e a
	 * tela se recarrega antes de tentar de novo.
	 *
	 * Grava por `$wpdb`, numa transação, e não por `wp_update_post()`. Mover não
	 * é editar: pelo núcleo, cada irmã renumerada ganharia uma revisão — e o
	 * teto de 30 do histórico se gastaria em reordenação —, um
	 * `post_modified` novo, que faria o editor aberto de outra pessoa acusar
	 * conflito sem ninguém ter mexido no texto, e uma passada do kses no
	 * conteúdo. A transação é o que impede uma reordenação pela metade, com
	 * duas irmãs na mesma posição, se uma das gravações falhar.
	 *
	 * Só as situações que a árvore de quem escreve mostra — publicado e
	 * rascunho — entram na conferência e na numeração. Uma página `pending` ou
	 * `private`, criada por fora, não está na tela; exigi-la na lista faria toda
	 * tentativa responder 409.
	 *
	 * @param array $dados {
	 *     @type int   $pagina ID da página movida.
	 *     @type int   $pai    ID da nova mãe, ou 0 para a raiz.
	 *     @type int[] $ordem  IDs das filhas do destino, na ordem final.
	 * }
	 * @return array|WP_Error
	 */
	public static function mover( $dados ) {
		global $wpdb;

		$dados = wp_parse_args(
			$dados,
			array(
				'pagina' => 0,
				'pai'    => 0,
				'ordem'  => array(),
			)
		);

		$pagina = self::pagina_editavel( (int) $dados['pagina'], 'edit_post' );

		if ( is_wp_error( $pagina ) ) {
			return $pagina;
		}

		$pagina_id = (int) $pagina->ID;
		$pai_id    = (int) $dados['pai'];

		if ( $pai_id ) {
			if ( is_wp_error( self::pagina_editavel( $pai_id, 'edit_post' ) ) ) {
				return self::erro( 'pai_invalido', __( 'A página de destino não existe mais ou não pode ser editada.', 'reconectar-core' ), 422 );
			}

			// Sem esta guarda, a página passaria a ser filha de uma descendente
			// dela: o ramo inteiro formaria um ciclo, sem caminho até a raiz, e
			// sumiria da árvore — publicado, mas inalcançável.
			if ( self::descende_de( $pai_id, $pagina_id ) ) {
				return self::erro( 'pai_invalido', __( 'Uma página não pode ir para dentro dela mesma nem de uma das subpáginas dela.', 'reconectar-core' ), 422 );
			}
		}

		// O teto vale para o ramo inteiro, não só para a página: a subpágina mais
		// funda dela desce junto. A conta é a mesma de `criar()` — nenhuma página
		// com mais de `PROFUNDIDADE_MAXIMA` ancestrais.
		$ancestrais = $pai_id ? count( get_post_ancestors( $pai_id ) ) + 1 : 0;

		if ( $ancestrais + self::altura( $pagina_id ) > Reconectar_Incubadora::PROFUNDIDADE_MAXIMA ) {
			return self::erro(
				'profundidade',
				sprintf(
					/* translators: %d: número máximo de níveis. */
					__( 'A Incubadora admite até %d níveis de subpágina, e as subpáginas desta página passariam do limite. Escolha um destino num nível acima.', 'reconectar-core' ),
					Reconectar_Incubadora::PROFUNDIDADE_MAXIMA
				),
				422
			);
		}

		$ordem    = array_values( array_map( 'intval', (array) $dados['ordem'] ) );
		$esperado = array_values( array_diff( self::filhas( $pai_id ), array( $pagina_id ) ) );
		$esperado[] = $pagina_id;

		$recebido = $ordem;
		sort( $recebido );
		sort( $esperado );

		// `array_unique` à parte: `[5, 5, 7]` contra `[5, 7]` falharia já na
		// contagem, mas `[5, 5]` contra `[5, 7]` só se pega comparando os dois.
		if ( $recebido !== $esperado || count( array_unique( $ordem ) ) !== count( $ordem ) ) {
			return self::erro( 'conflito', __( 'A árvore de páginas mudou desde que você a abriu — outra pessoa criou ou moveu uma página. Recarregue para ver a árvore atual e tente de novo.', 'reconectar-core' ), 409 );
		}

		$muda_de_mae = (int) $pagina->post_parent !== $pai_id;
		$slug        = $pagina->post_name;

		// O caminho é feito dos slugs, e duas irmãs com o mesmo slug teriam o
		// mesmo endereço: a segunda ficaria inalcançável. O slug só muda quando
		// colide, porque cada troca quebra os links copiados para a página.
		if ( $muda_de_mae && '' !== $slug ) {
			$slug = wp_unique_post_slug( $slug, $pagina_id, 'publish', Reconectar_Incubadora::POST_TYPE, $pai_id );
		}

		$wpdb->query( 'START TRANSACTION' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		$gravou = true;

		foreach ( $ordem as $posicao => $id ) {
			$campos = array( 'menu_order' => $posicao );

			if ( $id === $pagina_id ) {
				$campos['post_parent'] = $pai_id;
				$campos['post_name']   = $slug;
			}

			// `update()` devolve 0 quando a linha já tinha esses valores; só
			// `false` é falha.
			if ( false === $wpdb->update( $wpdb->posts, $campos, array( 'ID' => $id ) ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$gravou = false;
				break;
			}
		}

		$wpdb->query( $gravou ? 'COMMIT' : 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		// Depois do `ROLLBACK` também: o cache não sabe da transação, e nada
		// garante que ele não tenha sido aquecido no meio dela.
		foreach ( $ordem as $id ) {
			clean_post_cache( $id );
		}

		if ( ! $gravou ) {
			return self::erro( 'servidor', __( 'Não foi possível mover a página. Nada foi alterado; tente de novo.', 'reconectar-core' ), 500 );
		}

		$pagina = get_post( $pagina_id );

		return array(
			'codigo'  => 'movida',
			'id'      => $pagina_id,
			'pai'     => $pai_id,
			'posicao' => (int) array_search( $pagina_id, $ordem, true ),
			'url'     => self::caminho( get_permalink( $pagina ) ),
		);
	}

	/**
	 * Manda uma página para a lixeira.
	 *
	 * Recusa página com filhas: levar uma página com subpáginas à lixeira
	 * deixaria as filhas penduradas numa mãe que a árvore não desenha — fora de
	 * alcance, mas ainda publicadas. Quem exclui decide antes o destino delas.
	 *
	 * Lixeira, e não exclusão definitiva: é o que permite restaurar, e o
	 * histórico de revisões vai junto.
	 *
	 * @param int $pagina_id ID da página.
	 * @return array|WP_Error
	 */
	public static function excluir( $pagina_id ) {
		$pagina = self::pagina_editavel( (int) $pagina_id, 'delete_post' );

		if ( is_wp_error( $pagina ) ) {
			return $pagina;
		}

		$filhas = get_posts(
			array(
				'post_type'      => Reconectar_Incubadora::POST_TYPE,
				'post_parent'    => $pagina->ID,
				'post_status'    => array( 'publish', 'draft', 'pending', 'private', 'future' ),
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
			)
		);

		if ( $filhas ) {
			return self::erro( 'tem_filhas', __( 'Esta página tem subpáginas. Mova ou exclua as subpáginas antes.', 'reconectar-core' ), 409 );
		}

		$mae_id = (int) $pagina->post_parent;

		if ( ! wp_trash_post( $pagina->ID ) ) {
			return self::erro( 'servidor', __( 'Não foi possível excluir a página. Tente de novo.', 'reconectar-core' ), 500 );
		}

		// Para onde o editor leva a pessoa: a mãe, se ainda estiver de pé, ou a
		// âncora, que abre a primeira página da árvore.
		$destino = $mae_id && 'trash' !== get_post_status( $mae_id ) ? get_permalink( $mae_id ) : Reconectar_Incubadora_Leitura::url_da_ancora();

		return array(
			'codigo'  => 'excluida',
			'id'      => (int) $pagina->ID,
			'destino' => self::caminho( $destino ),
		);
	}

	/* ---------------------------------------------------------------------
	 * Apoio
	 * ------------------------------------------------------------------ */

	/**
	 * Confere método, sessão, capacidade e nonce, e encerra com o código certo.
	 *
	 * A ordem é deliberada. A capacidade vem **antes** do nonce: a loja e o
	 * comprador nunca recebem nonce de escrita, então para eles a recusa por
	 * nonce seria a única possível — e um 403 por nonce não prova que a
	 * capacidade foi consultada. Nesta ordem, um POST forjado por `curl`
	 * recebe `capacidade`, e é essa a trava que a verificação mede.
	 *
	 * @param string $acao `salvar`, `criar`, `mover` ou `excluir`.
	 * @return void
	 */
	private static function exigir_requisicao( $acao ) {
		nocache_headers();

		// `admin-post.php` dispara a ação também em GET, porque lê `action` de
		// `$_REQUEST`. Um link na página — ou uma imagem num comentário — não
		// pode gravar nada.
		$metodo = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_key( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : '';

		if ( 'POST' !== $metodo ) {
			header( 'Allow: POST' );
			self::responder( self::erro( 'metodo', __( 'Esta ação só aceita envio de formulário.', 'reconectar-core' ), 405 ) );
		}

		if ( ! is_user_logged_in() ) {
			self::responder( self::erro( 'login', __( 'Sua sessão expirou. Entre de novo para continuar — copie o texto antes, se ainda não foi salvo.', 'reconectar-core' ), 401 ) );
		}

		if ( ! current_user_can( Reconectar_Permissoes::CAP_GERIR_INCUBADORA ) ) {
			self::responder( self::erro( 'capacidade', __( 'Você não tem permissão para editar a Incubadora.', 'reconectar-core' ), 403 ) );
		}

		$nonce = isset( $_POST['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ) : '';

		if ( ! wp_verify_nonce( $nonce, self::acao( $acao ) ) ) {
			self::responder( self::erro( 'nonce', __( 'O formulário expirou. Recarregue a página para continuar — copie o texto antes, se ainda não foi salvo.', 'reconectar-core' ), 403 ) );
		}
	}

	/**
	 * A página pelo ID, se existir, for da Incubadora e o usuário puder mexer nela.
	 *
	 * Mesma resposta, 404, para inexistente, de outro post type e na lixeira:
	 * diferenciar diria a quem tenta IDs ao acaso o que existe no banco.
	 *
	 * @param int    $pagina_id  ID recebido.
	 * @param string $capacidade `edit_post` ou `delete_post`.
	 * @return WP_Post|WP_Error
	 */
	private static function pagina_editavel( $pagina_id, $capacidade ) {
		$pagina = $pagina_id ? get_post( $pagina_id ) : null;

		if ( ! $pagina || Reconectar_Incubadora::POST_TYPE !== $pagina->post_type || in_array( $pagina->post_status, array( 'trash', 'auto-draft', 'inherit' ), true ) ) {
			return self::erro( 'inexistente', __( 'A página não existe mais. Ela pode ter sido excluída por outra pessoa.', 'reconectar-core' ), 404 );
		}

		// Segunda camada, depois da capacidade do handler. Hoje as duas dizem a
		// mesma coisa, porque toda primitiva do post type aponta para a mesma
		// capacidade; a conferência por página é o que continua valendo no dia em
		// que alguma página ganhar regra própria.
		if ( ! current_user_can( $capacidade, $pagina->ID ) ) {
			return self::erro( 'capacidade', __( 'Você não tem permissão para alterar esta página.', 'reconectar-core' ), 403 );
		}

		return $pagina;
	}

	/**
	 * Grava pelo núcleo sem o kses padrão, que desfaria a sanitização autoral.
	 *
	 * Moderador e Administrador não têm `unfiltered_html`, então o núcleo passa
	 * o conteúdo por `wp_filter_post_kses()` com a allowlist genérica de post —
	 * que não é a da Incubadora. O conteúdo que chega aqui já foi reconstruído e
	 * passado por `wp_kses()` com a allowlist certa em
	 * `Reconectar_Incubadora_Conteudo::sanitizar()`; um segundo filtro com
	 * outra lista só poderia divergir dela.
	 *
	 * O `finally` é o que torna isso seguro: os filtros voltam mesmo se a
	 * gravação lançar, e o resto da requisição — revisão, ganchos de terceiros —
	 * não roda sem kses por acidente. `kses_init()` religa conforme a
	 * capacidade do usuário corrente, exatamente como o núcleo faz em `init`.
	 *
	 * Grava também `_edit_last`, que só a tela de edição do `/wp-admin` grava
	 * por conta própria; sem ele a linha "por fulano" da leitura cairia sempre
	 * no autor.
	 *
	 * @param array $campos Campos de `wp_insert_post()` / `wp_update_post()`.
	 * @return int|WP_Error ID gravado.
	 */
	private static function gravar( $campos ) {
		kses_remove_filters();

		try {
			// `wp_slash()` porque as duas funções esperam dado com barras, como
			// vindo de `$_POST`: sem ele, uma barra invertida num trecho de
			// código da página sumiria na gravação.
			$id = empty( $campos['ID'] ) ? wp_insert_post( wp_slash( $campos ), true ) : wp_update_post( wp_slash( $campos ), true );
		} finally {
			kses_init();
		}

		if ( is_wp_error( $id ) || ! $id ) {
			return self::erro( 'servidor', __( 'Não foi possível gravar a página. Tente de novo.', 'reconectar-core' ), 500 );
		}

		update_post_meta( $id, '_edit_last', get_current_user_id() );

		return (int) $id;
	}

	/**
	 * O que o editor precisa saber da página depois de uma operação.
	 *
	 * `html` sai já com a facade de vídeo: é o mesmo HTML da tela de leitura, e
	 * o editor o usa para trocar o conteúdo sem recarregar. `agora` é a hora do
	 * servidor, para o "Editado há N minutos" não depender do relógio de quem
	 * edita. URLs saem como caminho, pela regra de `WP_HOME` dinâmico.
	 *
	 * `editor` e `modificado_local` refazem a linha "Última edição" da tabela de
	 * metadados: sem eles, a tabela seguiria citando a edição anterior até a
	 * página ser recarregada.
	 *
	 * @param WP_Post $pagina Página gravada.
	 * @return array
	 */
	private static function estado( $pagina ) {
		return array(
			'id'               => (int) $pagina->ID,
			'titulo'           => $pagina->post_title,
			'status'           => $pagina->post_status,
			'html'             => Reconectar_Incubadora_Conteudo::html_de_leitura( $pagina->post_content ),
			'modificado'       => $pagina->post_modified_gmt,
			'modificado_local' => Reconectar_Incubadora_Leitura::data_local( $pagina, 'post_modified' ),
			'editor'           => Reconectar_Incubadora_Leitura::nome_de_usuario( Reconectar_Incubadora_Leitura::editado_por( $pagina ) ),
			'agora'            => gmdate( 'Y-m-d H:i:s' ),
			'url'              => self::caminho( get_permalink( $pagina ) ),
		);
	}

	/**
	 * Slug único entre as irmãs, calculado como se a página fosse publicada.
	 *
	 * `wp_unique_post_slug()` devolve o slug intacto para rascunho, então o
	 * status vai fixo em `publish`: o que se quer é o slug que valerá quando a
	 * página for ao ar, sem colidir com nenhuma irmã já publicada.
	 *
	 * @param string $titulo    Título de onde sai o slug.
	 * @param int    $pagina_id ID da página, ou 0 na criação.
	 * @param int    $mae_id    ID da mãe.
	 * @return string
	 */
	private static function slug_unico( $titulo, $pagina_id, $mae_id ) {
		$base = sanitize_title( $titulo );

		if ( '' === $base ) {
			$base = 'pagina';
		}

		return wp_unique_post_slug( $base, $pagina_id, 'publish', Reconectar_Incubadora::POST_TYPE, $mae_id );
	}

	/**
	 * `menu_order` para uma página nova entrar no fim das irmãs.
	 *
	 * Sem isso, toda página nasceria com 0 e a árvore a ordenaria pelo título,
	 * longe de onde a pessoa acabou de clicar em "criar".
	 *
	 * @param int $mae_id ID da mãe, ou 0 para a raiz.
	 * @return int
	 */
	private static function proxima_ordem( $mae_id ) {
		global $wpdb;

		$maior = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT MAX( menu_order ) FROM {$wpdb->posts} WHERE post_type = %s AND post_parent = %d AND post_status NOT IN ( 'trash', 'auto-draft', 'inherit' )",
				Reconectar_Incubadora::POST_TYPE,
				$mae_id
			)
		);

		return null === $maior ? 0 : (int) $maior + 1;
	}

	/**
	 * IDs das filhas de uma mãe que a árvore de quem escreve mostra, na ordem dela.
	 *
	 * A mesma ordenação de `Reconectar_Incubadora_Leitura::mapa_visivel()`:
	 * `menu_order` e, no empate, o título.
	 *
	 * @param int $mae_id ID da mãe, ou 0 para a raiz.
	 * @return int[]
	 */
	private static function filhas( $mae_id ) {
		global $wpdb;

		$ids = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT ID FROM {$wpdb->posts} WHERE post_type = %s AND post_parent = %d AND post_status IN ( 'publish', 'draft' ) ORDER BY menu_order ASC, post_title ASC",
				Reconectar_Incubadora::POST_TYPE,
				$mae_id
			)
		);

		return array_map( 'intval', $ids );
	}

	/**
	 * Diz se uma página é a outra ou está abaixo dela.
	 *
	 * Sobe por `post_parent` a partir do candidato, com um conjunto de
	 * visitados: um ciclo já gravado no banco — por importação, por edição
	 * direta — faria um laço sem ele.
	 *
	 * @param int $candidato_id ID da página que se quer saber se é descendente.
	 * @param int $ancestral_id ID da possível ancestral.
	 * @return bool
	 */
	private static function descende_de( $candidato_id, $ancestral_id ) {
		$id        = (int) $candidato_id;
		$visitados = array();

		while ( $id && ! isset( $visitados[ $id ] ) ) {
			if ( $id === (int) $ancestral_id ) {
				return true;
			}

			$visitados[ $id ] = true;
			$id               = (int) wp_get_post_parent_id( $id );
		}

		return false;
	}

	/**
	 * Quantos níveis de subpágina há abaixo de uma página — 0 se ela não tem filhas.
	 *
	 * Conta todas as situações fora da lixeira, e não só as que a árvore mostra:
	 * uma neta `pending` também desce junto quando a avó muda de lugar, e
	 * passar do teto com ela quebraria a recursão da árvore do mesmo jeito.
	 *
	 * Uma consulta só, com o mapa de filhas montado em PHP: descer por consulta
	 * a cada nível custaria uma ida ao banco por página do ramo.
	 *
	 * @param int $pagina_id ID da página.
	 * @return int
	 */
	private static function altura( $pagina_id ) {
		global $wpdb;

		$linhas = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT ID, post_parent FROM {$wpdb->posts} WHERE post_type = %s AND post_status NOT IN ( 'trash', 'auto-draft', 'inherit' )",
				Reconectar_Incubadora::POST_TYPE
			)
		);

		$filhas = array();

		foreach ( $linhas as $linha ) {
			$filhas[ (int) $linha->post_parent ][] = (int) $linha->ID;
		}

		$altura    = 0;
		$nivel     = array( (int) $pagina_id );
		$visitados = array( (int) $pagina_id => true );

		// Em largura, nível a nível: a altura é o número de níveis não vazios
		// abaixo da página. O teto do laço é defesa contra ciclo, como em
		// `descende_de()`.
		while ( $altura <= Reconectar_Incubadora::PROFUNDIDADE_MAXIMA + 1 ) {
			$proximo = array();

			foreach ( $nivel as $id ) {
				foreach ( isset( $filhas[ $id ] ) ? $filhas[ $id ] : array() as $filha ) {
					if ( ! isset( $visitados[ $filha ] ) ) {
						$visitados[ $filha ] = true;
						$proximo[]           = $filha;
					}
				}
			}

			if ( ! $proximo ) {
				break;
			}

			++$altura;
			$nivel = $proximo;
		}

		return $altura;
	}

	/**
	 * Caminho de uma URL do próprio site, sem esquema nem host.
	 *
	 * @param string $url URL absoluta.
	 * @return string
	 */
	private static function caminho( $url ) {
		$partes = wp_parse_url( (string) $url );

		if ( empty( $partes['path'] ) ) {
			return '/';
		}

		return $partes['path'] . ( isset( $partes['query'] ) ? '?' . $partes['query'] : '' );
	}

	/**
	 * Monta um `WP_Error` com o status HTTP e os dados extras da resposta.
	 *
	 * @param string $codigo   Código que o editor lê para decidir o que fazer.
	 * @param string $mensagem Mensagem para a pessoa.
	 * @param int    $status   Status HTTP.
	 * @param array  $extra    Campos a mais no JSON.
	 * @return WP_Error
	 */
	private static function erro( $codigo, $mensagem, $status, $extra = array() ) {
		return new WP_Error( $codigo, $mensagem, array_merge( $extra, array( 'status' => (int) $status ) ) );
	}

	/**
	 * Responde em JSON e encerra.
	 *
	 * Erro sai como `{success: false, data: {codigo, mensagem, …}}` com o
	 * status do `WP_Error`; sucesso, como `{success: true, data: …}` com 200.
	 * Nada pode ter sido impresso antes — um aviso PHP no corpo quebraria o
	 * `JSON.parse()` do editor —, e é por isso que o `provision.sh` mantém
	 * `WP_DEBUG_DISPLAY` desligado.
	 *
	 * @param array|WP_Error $resultado Retorno da operação.
	 * @return void
	 */
	private static function responder( $resultado ) {
		if ( is_wp_error( $resultado ) ) {
			$dados  = (array) $resultado->get_error_data();
			$status = isset( $dados['status'] ) ? (int) $dados['status'] : 400;
			unset( $dados['status'] );

			wp_send_json_error(
				array_merge(
					array(
						'codigo'   => $resultado->get_error_code(),
						'mensagem' => $resultado->get_error_message(),
					),
					$dados
				),
				$status
			);
		}

		wp_send_json_success( $resultado );
	}
}
