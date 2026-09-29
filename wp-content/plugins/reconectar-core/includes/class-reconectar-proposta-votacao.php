<?php
/**
 * Enquetes da comunidade.
 *
 * O post type nasceu como esqueleto de "proposta de votação" — duas metas de
 * contagem, favor e contra, que nada no repositório jamais escreveu. O placar
 * nunca subiu porque não havia como votar.
 *
 * Esta versão dá corpo ao tipo: opções múltiplas cadastradas pelo Moderador de
 * Conteúdo, janela de vigência, um voto por conta logada e um componente
 * flutuante que aparece em todas as telas enquanto houver enquete aberta
 * (`Reconectar_Enquete_Flutuante`).
 *
 * O slug `proposta_votacao` e o nome da classe ficam como estavam **de
 * propósito**: renomear post type é `UPDATE` em `wp_posts` numa instalação que
 * já pode ter conteúdo, e o ganho seria só cosmético. Os rótulos de tela dizem
 * "Enquete", que é a palavra que o usuário usa e a que aparece no componente.
 *
 * O que fica adiado, e por quê: voto anônimo e a regra de uma-vez-por-
 * beneficiário dependem do processo participativo da Atividade 2.10 do edital.
 * Enquanto a regra não existe, a plataforma não finge tê-la.
 *
 * @package Reconectar_Core
 */

defined( 'ABSPATH' ) || exit;

/**
 * Registra o post type das enquetes, guarda os votos e atende ao formulário.
 */
class Reconectar_Proposta_Votacao {

	/**
	 * Post type das enquetes.
	 */
	const POST_TYPE = 'proposta_votacao';

	/**
	 * Alternativas cadastradas: lista de `array( 'id' => 'op-1', 'texto' => '…' )`.
	 */
	const META_OPCOES = '_reconectar_enquete_opcoes';

	/**
	 * Quem votou em quê: mapa `user_id => id_da_opcao`.
	 *
	 * É a **fonte da verdade** do placar. A contagem não é gravada em lugar
	 * nenhum: sai deste mapa a cada leitura, pela mesma razão que o saldo do
	 * fórum é recalculado e nunca somado — dois números que podem divergir sob
	 * concorrência divergem, e o que está errado não tem como ser descoberto
	 * depois.
	 *
	 * A limitação é honesta e vai registrada: um array único numa meta não
	 * escala para dezenas de milhares de votantes. Nessa faixa a estrutura vira
	 * tabela própria, e é melhor saber disso agora do que descobrir em produção.
	 */
	const META_VOTANTES = '_reconectar_enquete_votantes';

	/**
	 * Primeiro dia em que a enquete aceita voto, em `Y-m-d`. Vazio = sem limite.
	 */
	const META_INICIO = '_reconectar_enquete_inicio';

	/**
	 * Último dia em que a enquete aceita voto, em `Y-m-d`. Vazio = sem limite.
	 */
	const META_FIM = '_reconectar_enquete_fim';

	/**
	 * Nonce do metabox.
	 */
	const NONCE = 'reconectar_enquete_nonce';

	/**
	 * Ação do `admin-post.php` que recebe o voto.
	 */
	const ACAO_VOTAR = 'reconectar_votar_enquete';

	/**
	 * Índice das enquetes publicadas, com a janela de vigência de cada uma.
	 *
	 * Guarda só IDs e datas — nenhuma URL, portanto sem a exigência de host na
	 * chave que o `CLAUDE.md` impõe a transient com link absoluto.
	 */
	const TRANSIENT_INDICE = 'reconectar_enquetes_indice';

	/**
	 * Teto de alternativas por enquete.
	 *
	 * Não é limite técnico: é o ponto a partir do qual o cartão flutuante deixa
	 * de caber na coluna de 343px do celular sem virar uma lista de rolagem
	 * própria.
	 */
	const LIMITE_OPCOES = 10;

	/**
	 * Registra os ganchos da classe.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'init', array( __CLASS__, 'registrar_post_type' ) );
		add_action( 'init', array( __CLASS__, 'registrar_metas' ) );
		add_action( 'add_meta_boxes', array( __CLASS__, 'registrar_metabox' ) );
		add_action( 'save_post_' . self::POST_TYPE, array( __CLASS__, 'salvar_metas' ), 10, 2 );

		// O índice guarda o que a consulta de toda página lê. Qualquer mudança no
		// conjunto de enquetes publicadas o invalida — inclusive a lixeira, que
		// não passa por `save_post` quando o post vai direto para lá.
		add_action( 'save_post_' . self::POST_TYPE, array( __CLASS__, 'esquecer_indice' ) );
		add_action( 'deleted_post', array( __CLASS__, 'esquecer_indice' ) );
		add_action( 'trashed_post', array( __CLASS__, 'esquecer_indice' ) );
		add_action( 'untrashed_post', array( __CLASS__, 'esquecer_indice' ) );

		add_action( 'admin_post_' . self::ACAO_VOTAR, array( __CLASS__, 'processar_voto' ) );

		// O `nopriv` existe para responder "entre para votar". Sem ele o WordPress
		// devolve um `0` numa tela branca, que não diz nada a quem só esqueceu de
		// entrar.
		add_action( 'admin_post_nopriv_' . self::ACAO_VOTAR, array( __CLASS__, 'processar_voto' ) );
	}

	/* ---------------------------------------------------------------------
	 * Registro
	 * ------------------------------------------------------------------ */

	/**
	 * Declara o post type das enquetes.
	 *
	 * As capacidades são **declaradas**, e essa é a correção central deste
	 * arquivo. Sem `capability_type` o WordPress cai no padrão `post`, e o
	 * Moderador de Conteúdo administrava enquete por acidente — pelas
	 * `edit_posts`/`publish_posts` que `CAPS_DE_CONTEUDO` dá para post e página.
	 * Medido antes da correção:
	 *
	 *     moderador      edit_post( proposta ) → true   (por acidente)
	 *     company_admin  edit_post( proposta ) → false  (tela aparece, ação não acontece)
	 *
	 * O bloco segue o de `Reconectar_Campanha::registrar_post_type()`: **só
	 * primitivas, nunca meta cap**. Apontar `edit_post` ou `read_post` para a
	 * capacidade autoral provoca `_doing_it_wrong()` impresso dentro do
	 * `admin_menu`, e aviso impresso derruba em silêncio todo `wp_safe_redirect()`
	 * de `admin_init` — seis casos do `verificar-acessos.sh` caem de uma vez.
	 *
	 * `show_in_rest` fica em `false` para manter o editor clássico: o repetidor
	 * de opções é um metabox, e metabox no editor de blocos é território de
	 * compatibilidade que este projeto não precisa pagar.
	 *
	 * @return void
	 */
	public static function registrar_post_type() {
		$labels = array(
			'name'               => __( 'Enquetes', 'reconectar-core' ),
			'singular_name'      => __( 'Enquete', 'reconectar-core' ),
			'menu_name'          => __( 'Enquetes', 'reconectar-core' ),
			'add_new'            => __( 'Adicionar nova', 'reconectar-core' ),
			'add_new_item'       => __( 'Adicionar nova enquete', 'reconectar-core' ),
			'edit_item'          => __( 'Editar enquete', 'reconectar-core' ),
			'new_item'           => __( 'Nova enquete', 'reconectar-core' ),
			'view_item'          => __( 'Ver enquete', 'reconectar-core' ),
			'search_items'       => __( 'Buscar enquetes', 'reconectar-core' ),
			'not_found'          => __( 'Nenhuma enquete encontrada', 'reconectar-core' ),
			'not_found_in_trash' => __( 'Nenhuma enquete na lixeira', 'reconectar-core' ),
		);

		$capacidade = Reconectar_Permissoes::CAP_GERIR_ENQUETES;

		register_post_type(
			self::POST_TYPE,
			array(
				'labels'       => $labels,
				'public'       => true,
				'show_ui'      => true,
				'show_in_menu' => true,
				'show_in_rest' => false,
				'menu_icon'    => 'dashicons-forms',
				'menu_position' => 22,
				'supports'     => array( 'title', 'editor', 'excerpt' ),
				'has_archive'  => false,
				'rewrite'      => array( 'slug' => 'enquete' ),
				'map_meta_cap' => true,
				'capabilities' => array(
					'edit_posts'         => $capacidade,
					'edit_others_posts'  => $capacidade,
					'delete_posts'       => $capacidade,
					'delete_others_posts' => $capacidade,
					'publish_posts'      => $capacidade,
					'read_private_posts' => $capacidade,
					'create_posts'       => $capacidade,
				),
			)
		);
	}

	/**
	 * Registra as metas da enquete.
	 *
	 * Todas com `show_in_rest => false` e `auth_callback` fechado na capacidade
	 * de gestão: o placar é escrito pelo endpoint de voto, que faz as suas
	 * próprias verificações, e nunca por escrita direta de meta.
	 *
	 * @return void
	 */
	public static function registrar_metas() {
		$autorizar = function () {
			return current_user_can( Reconectar_Permissoes::CAP_GERIR_ENQUETES );
		};

		$metas = array(
			self::META_OPCOES   => 'array',
			self::META_VOTANTES => 'array',
			self::META_INICIO   => 'string',
			self::META_FIM      => 'string',
		);

		foreach ( $metas as $chave => $tipo ) {
			$argumentos = array(
				'type'          => $tipo,
				'single'        => true,
				'show_in_rest'  => false,
				'auth_callback' => $autorizar,
			);

			// `default` só para os escalares: um `array()` como padrão de meta
			// serializada faz `get_post_meta( …, true )` devolver o padrão onde o
			// certo seria vazio, e a diferença entre "sem opções" e "opções zeradas"
			// se perde.
			if ( 'string' === $tipo ) {
				$argumentos['default'] = '';
			}

			register_post_meta( self::POST_TYPE, $chave, $argumentos );
		}
	}

	/* ---------------------------------------------------------------------
	 * Leitura
	 * ------------------------------------------------------------------ */

	/**
	 * As alternativas cadastradas, normalizadas.
	 *
	 * @param int $enquete_id ID da enquete.
	 * @return array[] Lista de `array( 'id' => string, 'texto' => string )`.
	 */
	public static function opcoes( $enquete_id ) {
		$opcoes = get_post_meta( (int) $enquete_id, self::META_OPCOES, true );

		if ( ! is_array( $opcoes ) ) {
			return array();
		}

		$normalizadas = array();

		foreach ( $opcoes as $opcao ) {
			if ( ! is_array( $opcao ) || empty( $opcao['id'] ) || ! isset( $opcao['texto'] ) ) {
				continue;
			}

			$normalizadas[] = array(
				'id'    => (string) $opcao['id'],
				'texto' => (string) $opcao['texto'],
			);
		}

		return $normalizadas;
	}

	/**
	 * O mapa de votantes, normalizado.
	 *
	 * @param int $enquete_id ID da enquete.
	 * @return string[] `array( user_id => id_da_opcao )`.
	 */
	public static function votantes( $enquete_id ) {
		$votantes = get_post_meta( (int) $enquete_id, self::META_VOTANTES, true );

		return is_array( $votantes ) ? $votantes : array();
	}

	/**
	 * Quantos votos cada alternativa recebeu.
	 *
	 * Calculado do mapa de votantes a cada leitura — nunca lido de um contador
	 * gravado. O laço parte das opções, e não dos votos, para que uma
	 * alternativa sem voto apareça com zero em vez de sumir da tela.
	 *
	 * @param int $enquete_id ID da enquete.
	 * @return int[] `array( id_da_opcao => votos )`, na ordem das opções.
	 */
	public static function contagem( $enquete_id ) {
		$escolhas = array_count_values( array_map( 'strval', self::votantes( $enquete_id ) ) );
		$contagem = array();

		foreach ( self::opcoes( $enquete_id ) as $opcao ) {
			$contagem[ $opcao['id'] ] = isset( $escolhas[ $opcao['id'] ] ) ? (int) $escolhas[ $opcao['id'] ] : 0;
		}

		return $contagem;
	}

	/**
	 * Total de votos válidos da enquete.
	 *
	 * Soma a contagem por opção, e não `count()` sobre os votantes: voto em
	 * alternativa removida não entra no divisor do percentual, senão as barras
	 * somariam menos de 100% sem nada na tela explicando a diferença.
	 *
	 * @param int $enquete_id ID da enquete.
	 * @return int
	 */
	public static function total_de_votos( $enquete_id ) {
		return (int) array_sum( self::contagem( $enquete_id ) );
	}

	/**
	 * Em que alternativa este usuário votou.
	 *
	 * @param int $enquete_id ID da enquete.
	 * @param int $usuario_id Usuário; `0` usa o atual.
	 * @return string ID da opção, ou vazio se ainda não votou.
	 */
	public static function voto_de( $enquete_id, $usuario_id = 0 ) {
		$usuario_id = $usuario_id ? (int) $usuario_id : get_current_user_id();

		if ( ! $usuario_id ) {
			return '';
		}

		$votantes = self::votantes( $enquete_id );

		return isset( $votantes[ $usuario_id ] ) ? (string) $votantes[ $usuario_id ] : '';
	}

	/**
	 * A enquete está dentro da janela de votação nesta data?
	 *
	 * Comparação textual de `Y-m-d`, que é ordenável como string — e por isso a
	 * data passa por `normalizar_data()` antes de ser gravada.
	 *
	 * @param int    $enquete_id ID da enquete.
	 * @param string $hoje       Data de referência em `Y-m-d`; vazio usa hoje.
	 * @return bool
	 */
	public static function esta_aberta( $enquete_id, $hoje = '' ) {
		$inicio = (string) get_post_meta( (int) $enquete_id, self::META_INICIO, true );
		$fim    = (string) get_post_meta( (int) $enquete_id, self::META_FIM, true );

		return self::janela_aberta( $inicio, $fim, $hoje );
	}

	/**
	 * O placar desta enquete pode ser publicado para quem está lendo?
	 *
	 * **Enquanto a enquete está aberta, o resultado é privado.** Publicar o
	 * parcial convida a acompanhar a maioria, e o painel de transparência é a
	 * tela pública da plataforma — o mesmo motivo pelo qual o percentual já havia
	 * saído do cartão flutuante. Encerrada, o placar é público para todo mundo:
	 * publicá-lo é a razão de o painel existir.
	 *
	 * Quem administra a enquete vê o parcial em tempo real, porque ele precisa
	 * acompanhar a consulta que conduz. Isso não abre brecha de indução: o
	 * moderador vê o número **da enquete dele**, não de todas.
	 *
	 * A conferência é `edit_post` e não a capacidade crua: "o moderador daquela
	 * enquete" é quem pode editá-la, e a meta cap já resolve papel, autoria e os
	 * filtros de `Reconectar_Permissoes` — inclusive o que nega escrita ao
	 * Administrador de empresas fora da allowlist de post types. Conferir
	 * `CAP_GERIR_ENQUETES` direto responderia "sim" a quem o editor recusaria.
	 *
	 * @param int $enquete_id ID da enquete.
	 * @return bool
	 */
	public static function pode_ver_resultado( $enquete_id ) {
		if ( ! self::esta_aberta( $enquete_id ) ) {
			return true;
		}

		return current_user_can( 'edit_post', (int) $enquete_id );
	}

	/**
	 * As enquetes publicadas e abertas hoje, mais recente primeiro.
	 *
	 * A vigência é resolvida **em PHP**, de propósito. As duas rotas óbvias do
	 * `WP_Query` falham, como registra o `CLAUDE.md`: `meta_key` com
	 * `orderby => 'meta_value'` monta INNER JOIN e some com quem não tem a meta,
	 * e `meta_query` com `relation => 'OR'` e ramo `NOT EXISTS` traz todos de
	 * volta estragando a ordem.
	 *
	 * O índice em transient existe porque este método roda em **toda página** do
	 * site. Quando nenhuma enquete está aberta — que é o caso comum — a função
	 * devolve vazio sem tocar no banco. Só havendo aberta é que a consulta de
	 * posts acontece.
	 *
	 * Enquete publicada **sem alternativa cadastrada não entra**. Ela existe: são
	 * as três propostas que sobraram do esqueleto binário, e uma delas se chama
	 * "Nova Proposta". Aberta, para efeito desta consulta, é aquela em que dá para
	 * votar — sem esse filtro o componente flutuante anunciava "4 enquetes
	 * abertas" e desenhava uma, porque quem imprime já descarta as vazias. O
	 * painel de transparência não usa este método: lá as vazias aparecem de
	 * propósito, com o aviso de que faltam alternativas.
	 *
	 * @param int $limite Teto de enquetes devolvidas.
	 * @return WP_Post[]
	 */
	public static function abertas( $limite = 5 ) {
		$abertas = array();
		$hoje    = current_time( 'Y-m-d' );

		foreach ( self::indice() as $id => $janela ) {
			if ( self::janela_aberta( $janela['inicio'], $janela['fim'], $hoje ) ) {
				$abertas[] = (int) $id;
			}
		}

		if ( empty( $abertas ) ) {
			return array();
		}

		$enquetes = get_posts(
			array(
				'post_type'        => self::POST_TYPE,
				'post_status'      => 'publish',
				'post__in'         => $abertas,
				// O teto entra depois do filtro de alternativas: cortar aqui deixaria
				// o resultado menor do que o pedido sempre que uma vazia estivesse na
				// frente da fila.
				'numberposts'      => -1,
				'orderby'          => 'date',
				'order'            => 'DESC',
				'suppress_filters' => false,
			)
		);

		$votaveis = array();

		foreach ( $enquetes as $enquete ) {
			if ( ! empty( self::opcoes( $enquete->ID ) ) ) {
				$votaveis[] = $enquete;
			}
		}

		return array_slice( $votaveis, 0, max( 1, (int) $limite ) );
	}

	/**
	 * As enquetes abertas em que um usuário ainda **não** votou.
	 *
	 * É o que alimenta o contador do cabeçalho e o da barra inferior. "Pendente"
	 * quer dizer voto que falta *seu*, e por isso **deslogado devolve vazio**: quem
	 * não entrou não tem voto a faltar, e um selo numérico para visitante anônimo
	 * pediria uma ação que a tela seguinte recusaria. De quebra, é o que mantém o
	 * HTML servido a anônimo idêntico para todo mundo, caso um cache de página
	 * entre em cena algum dia.
	 *
	 * Memoizado por usuário porque a barra e o cabeçalho perguntam a mesma coisa na
	 * mesma requisição. O custo de banco já está resolvido em `abertas()`, pelo
	 * índice em transient.
	 *
	 * @param int $usuario_id Usuário; `0` usa o atual.
	 * @return WP_Post[]
	 */
	public static function pendentes_de( $usuario_id = 0 ) {
		static $cache = array();

		$usuario_id = $usuario_id ? (int) $usuario_id : get_current_user_id();

		if ( ! $usuario_id ) {
			return array();
		}

		if ( isset( $cache[ $usuario_id ] ) ) {
			return $cache[ $usuario_id ];
		}

		$pendentes = array();

		/*
		 * Sem teto, de propósito. O limite de `abertas()` é um `array_slice` no fim
		 * — a consulta ao banco acontece inteira de qualquer jeito —, então cortar
		 * aqui não economizaria nada e faria o selo anunciar um número menor que a
		 * verdade. Número plausível e errado é pior que campo vazio.
		 */
		foreach ( self::abertas( PHP_INT_MAX ) as $enquete ) {
			if ( '' === self::voto_de( $enquete->ID, $usuario_id ) ) {
				$pendentes[] = $enquete;
			}
		}

		$cache[ $usuario_id ] = $pendentes;

		return $pendentes;
	}

	/* ---------------------------------------------------------------------
	 * Voto
	 * ------------------------------------------------------------------ */

	/**
	 * Quem pode votar.
	 *
	 * **Não reusa `Reconectar_Forum::pode_votar()`, e a divergência é deliberada.**
	 * Aquela exige `CAP_COMUNIDADE`, que `PAPEIS_DA_COMUNIDADE` nega ao
	 * `customer`. Aqui a decisão do projeto é outra: enquete é consulta à
	 * comunidade inteira, e todo usuário logado vota, o comprador incluído.
	 * Quem ler os dois métodos lado a lado vai achar que este está errado —
	 * não está, e é por isso que o comentário existe.
	 *
	 * @param int $enquete_id ID da enquete.
	 * @param int $usuario_id Usuário; `0` usa o atual.
	 * @return bool
	 */
	public static function pode_votar( $enquete_id, $usuario_id = 0 ) {
		$usuario_id = $usuario_id ? (int) $usuario_id : get_current_user_id();
		$enquete    = get_post( (int) $enquete_id );

		if ( ! $usuario_id || ! $enquete || self::POST_TYPE !== $enquete->post_type ) {
			return false;
		}

		if ( 'publish' !== $enquete->post_status ) {
			return false;
		}

		return self::esta_aberta( $enquete->ID );
	}

	/**
	 * Registra ou troca o voto de um usuário.
	 *
	 * Um voto por conta, alterável enquanto a enquete estiver aberta: escolher
	 * outra alternativa sobrescreve a entrada do mapa, e o total não sobe. É o
	 * que o recálculo por `array_count_values()` garante de graça, e o que um
	 * contador somado erraria.
	 *
	 * @param int    $enquete_id ID da enquete.
	 * @param string $opcao_id   ID da alternativa escolhida.
	 * @param int    $usuario_id Usuário; `0` usa o atual.
	 * @return int[]|WP_Error A contagem nova, ou o erro.
	 */
	public static function votar( $enquete_id, $opcao_id, $usuario_id = 0 ) {
		$enquete_id = (int) $enquete_id;
		$opcao_id   = (string) $opcao_id;
		$usuario_id = $usuario_id ? (int) $usuario_id : get_current_user_id();

		if ( ! $usuario_id ) {
			return new WP_Error(
				'reconectar_voto_deslogado',
				__( 'Entre na sua conta para votar.', 'reconectar-core' )
			);
		}

		if ( ! self::pode_votar( $enquete_id, $usuario_id ) ) {
			return new WP_Error(
				'reconectar_voto_negado',
				__( 'Esta enquete não está aberta para voto.', 'reconectar-core' )
			);
		}

		if ( ! self::opcao_existe( $enquete_id, $opcao_id ) ) {
			return new WP_Error(
				'reconectar_voto_invalido',
				__( 'Escolha uma das alternativas da enquete.', 'reconectar-core' )
			);
		}

		$votantes                = self::votantes( $enquete_id );
		$votantes[ $usuario_id ] = $opcao_id;

		update_post_meta( $enquete_id, self::META_VOTANTES, $votantes );

		return self::contagem( $enquete_id );
	}

	/**
	 * Atende ao POST do formulário de voto.
	 *
	 * A rota é `admin-post.php`, que mora dentro de `/wp-admin` — a exceção
	 * explícita em `Reconectar_Permissoes::bloquear_area_administrativa()` já a
	 * cobre, e nada precisa ser aberto aqui.
	 *
	 * O caminho sem JavaScript é o principal, não o degradado: o formulário é um
	 * `<form method="post">` comum e o usuário volta para onde estava.
	 * `responder()` devolve JSON quando o pedido é assíncrono, para que o
	 * enriquecimento por `fetch()` não precise de um segundo endpoint.
	 *
	 * @return void
	 */
	public static function processar_voto() {
		$enquete_id = isset( $_POST['enquete'] ) ? (int) $_POST['enquete'] : 0;
		$opcao_id   = isset( $_POST['opcao'] ) ? sanitize_key( wp_unslash( $_POST['opcao'] ) ) : '';

		check_admin_referer( self::ACAO_VOTAR . '_' . $enquete_id );

		$resultado = self::votar( $enquete_id, $opcao_id );

		self::responder( $enquete_id, $resultado );
	}

	/* ---------------------------------------------------------------------
	 * Metabox
	 * ------------------------------------------------------------------ */

	/**
	 * Põe o painel de configuração na tela de edição.
	 *
	 * @return void
	 */
	public static function registrar_metabox() {
		add_meta_box(
			'reconectar-enquete',
			__( 'Alternativas e período', 'reconectar-core' ),
			array( __CLASS__, 'imprimir_metabox' ),
			self::POST_TYPE,
			'normal',
			'high'
		);
	}

	/**
	 * Desenha o painel de configuração da enquete.
	 *
	 * O repetidor de alternativas é HTML mais um punhado de JavaScript inline —
	 * o projeto não tem etapa de compilação, e um repetidor de campos de texto
	 * não justifica introduzir uma.
	 *
	 * Cada linha carrega o ID da alternativa num campo oculto. É isso que
	 * preserva os votos quando o moderador reordena ou remove uma opção: sem o
	 * ID estável, renumerar as linhas realocaria silenciosamente o voto de quem
	 * escolheu a segunda alternativa para a que passou a ocupar aquela posição.
	 *
	 * @param WP_Post $post Enquete em edição.
	 * @return void
	 */
	public static function imprimir_metabox( $post ) {
		wp_nonce_field( self::NONCE, self::NONCE );

		$opcoes   = self::opcoes( $post->ID );
		$contagem = self::contagem( $post->ID );
		$total    = self::total_de_votos( $post->ID );
		$inicio   = (string) get_post_meta( $post->ID, self::META_INICIO, true );
		$fim      = (string) get_post_meta( $post->ID, self::META_FIM, true );

		if ( empty( $opcoes ) ) {
			$opcoes = array(
				array(
					'id'    => '',
					'texto' => '',
				),
				array(
					'id'    => '',
					'texto' => '',
				),
			);
		}

		echo '<p id="reconectar-enquete-ajuda" class="description">';
		echo esc_html(
			sprintf(
				/* translators: %d: número máximo de alternativas. */
				__( 'Cadastre até %d alternativas. Uma alternativa sem texto é descartada ao salvar, junto dos votos que ela tinha.', 'reconectar-core' ),
				self::LIMITE_OPCOES
			)
		);
		echo '</p>';

		echo '<div id="reconectar-enquete-opcoes">';

		foreach ( $opcoes as $indice => $opcao ) {
			self::imprimir_linha_de_opcao(
				$indice,
				$opcao,
				isset( $contagem[ $opcao['id'] ] ) ? (int) $contagem[ $opcao['id'] ] : 0,
				$total
			);
		}

		echo '</div>';

		printf(
			'<p><button type="button" class="button" id="reconectar-enquete-adicionar">%s</button></p>',
			esc_html__( 'Adicionar alternativa', 'reconectar-core' )
		);

		echo '<table class="form-table" role="presentation"><tbody>';

		printf(
			'<tr><th scope="row"><label for="reconectar-enquete-inicio">%s</label></th>
			<td><input type="date" id="reconectar-enquete-inicio" name="reconectar_enquete[inicio]" value="%s" aria-describedby="reconectar-enquete-inicio-ajuda">
			<p class="description" id="reconectar-enquete-inicio-ajuda">%s</p></td></tr>',
			esc_html__( 'Abre em', 'reconectar-core' ),
			esc_attr( $inicio ),
			esc_html__( 'Vazio: aberta desde a publicação.', 'reconectar-core' )
		);

		printf(
			'<tr><th scope="row"><label for="reconectar-enquete-fim">%s</label></th>
			<td><input type="date" id="reconectar-enquete-fim" name="reconectar_enquete[fim]" value="%s" aria-describedby="reconectar-enquete-fim-ajuda">
			<p class="description" id="reconectar-enquete-fim-ajuda">%s</p></td></tr>',
			esc_html__( 'Encerra em', 'reconectar-core' ),
			esc_attr( $fim ),
			esc_html__( 'Vazio: sem data de encerramento. O último dia ainda aceita voto.', 'reconectar-core' )
		);

		echo '</tbody></table>';

		printf(
			'<p class="description">%s</p>',
			esc_html(
				sprintf(
					/* translators: %d: total de votos. */
					_n( '%d voto registrado.', '%d votos registrados.', $total, 'reconectar-core' ),
					$total
				)
			)
		);

		self::imprimir_script_do_repetidor();
	}

	/**
	 * Desenha uma linha do repetidor de alternativas.
	 *
	 * @param int   $indice   Posição da linha no formulário.
	 * @param array $opcao    `array( 'id' => string, 'texto' => string )`.
	 * @param int   $votos    Votos da alternativa.
	 * @param int   $total    Total de votos da enquete.
	 * @return void
	 */
	private static function imprimir_linha_de_opcao( $indice, $opcao, $votos, $total ) {
		$campo_id = 'reconectar-enquete-opcao-' . (int) $indice;

		printf(
			'<p class="reconectar-enquete-opcao">
				<label class="screen-reader-text" for="%1$s">%2$s</label>
				<input type="hidden" name="reconectar_enquete[opcoes][%3$d][id]" value="%4$s">
				<input type="text" class="regular-text" id="%1$s" name="reconectar_enquete[opcoes][%3$d][texto]" value="%5$s" aria-describedby="reconectar-enquete-ajuda">
				<span class="description">%6$s</span>
			</p>',
			esc_attr( $campo_id ),
			esc_html(
				sprintf(
					/* translators: %d: número da alternativa. */
					__( 'Alternativa %d', 'reconectar-core' ),
					(int) $indice + 1
				)
			),
			(int) $indice,
			esc_attr( isset( $opcao['id'] ) ? $opcao['id'] : '' ),
			esc_attr( isset( $opcao['texto'] ) ? $opcao['texto'] : '' ),
			esc_html( self::rotular_votos( $votos, $total ) )
		);
	}

	/**
	 * O texto de resultado de uma alternativa.
	 *
	 * Com nenhum voto na enquete o retorno é vazio: um `0%` exigiria dividir por
	 * zero, e imprimi-lo mesmo assim seria o número plausível que a regra de
	 * honestidade de dados do projeto proíbe.
	 *
	 * @param int $votos Votos da alternativa.
	 * @param int $total Total de votos da enquete.
	 * @return string
	 */
	private static function rotular_votos( $votos, $total ) {
		if ( $total < 1 ) {
			return '';
		}

		return sprintf(
			/* translators: 1: número de votos. 2: percentual. */
			__( '%1$d voto(s) — %2$s%%', 'reconectar-core' ),
			(int) $votos,
			number_format_i18n( self::percentual( $votos, $total ), 1 )
		);
	}

	/**
	 * O percentual de uma alternativa sobre o total.
	 *
	 * @param int $votos Votos da alternativa.
	 * @param int $total Total de votos da enquete.
	 * @return float Zero quando ainda não há voto — quem chama decide se imprime.
	 */
	public static function percentual( $votos, $total ) {
		if ( (int) $total < 1 ) {
			return 0.0;
		}

		return ( (int) $votos * 100 ) / (int) $total;
	}

	/**
	 * O JavaScript do repetidor, impresso junto do metabox.
	 *
	 * Inline e sem dependência porque vale só nesta tela do `/wp-admin` e tem
	 * vinte linhas. Enfileirar um arquivo para isso custaria mais em requisição
	 * do que o próprio código.
	 *
	 * @return void
	 */
	private static function imprimir_script_do_repetidor() {
		$limite = (int) self::LIMITE_OPCOES;
		$rotulo = esc_js( __( 'Alternativa', 'reconectar-core' ) );

		?>
		<script>
		( function () {
			var caixa = document.getElementById( 'reconectar-enquete-opcoes' );
			var botao = document.getElementById( 'reconectar-enquete-adicionar' );

			if ( ! caixa || ! botao ) {
				return;
			}

			botao.addEventListener( 'click', function () {
				var linhas = caixa.querySelectorAll( '.reconectar-enquete-opcao' );

				if ( linhas.length >= <?php echo $limite; ?> ) {
					botao.disabled = true;
					return;
				}

				var indice = linhas.length;
				var campo  = 'reconectar-enquete-opcao-' + indice;
				var linha  = document.createElement( 'p' );

				linha.className = 'reconectar-enquete-opcao';
				linha.innerHTML =
					'<label class="screen-reader-text" for="' + campo + '"><?php echo $rotulo; ?> ' + ( indice + 1 ) + '</label>' +
					// O `id` vazio é o que sinaliza alternativa nova: `salvar_metas()`
					// atribui um identificador inédito, sem reaproveitar o de nenhuma
					// removida — voto antigo não migra para alternativa nova.
					'<input type="hidden" name="reconectar_enquete[opcoes][' + indice + '][id]" value="">' +
					'<input type="text" class="regular-text" id="' + campo + '" name="reconectar_enquete[opcoes][' + indice + '][texto]" value="" aria-describedby="reconectar-enquete-ajuda">';

				caixa.appendChild( linha );
				linha.querySelector( 'input[type="text"]' ).focus();

				if ( caixa.querySelectorAll( '.reconectar-enquete-opcao' ).length >= <?php echo $limite; ?> ) {
					botao.disabled = true;
				}
			} );
		}() );
		</script>
		<?php
	}

	/* ---------------------------------------------------------------------
	 * Gravação
	 * ------------------------------------------------------------------ */

	/**
	 * Grava as alternativas e o período.
	 *
	 * @param int     $post_id ID da enquete.
	 * @param WP_Post $post    Enquete salva.
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

		$enviado = isset( $_POST['reconectar_enquete'] ) ? (array) wp_unslash( $_POST['reconectar_enquete'] ) : array();

		update_post_meta(
			$post_id,
			self::META_INICIO,
			self::normalizar_data( isset( $enviado['inicio'] ) ? sanitize_text_field( $enviado['inicio'] ) : '' )
		);

		update_post_meta(
			$post_id,
			self::META_FIM,
			self::normalizar_data( isset( $enviado['fim'] ) ? sanitize_text_field( $enviado['fim'] ) : '' )
		);

		$opcoes = self::normalizar_opcoes(
			$post_id,
			isset( $enviado['opcoes'] ) ? (array) $enviado['opcoes'] : array()
		);

		update_post_meta( $post_id, self::META_OPCOES, $opcoes );

		self::podar_votos_orfaos( $post_id, $opcoes );
	}

	/**
	 * Limpa a lista de alternativas enviada pelo formulário.
	 *
	 * Descarta as vazias, corta no teto e atribui identificador a quem chegou
	 * sem um. O identificador novo parte do maior já usado na enquete e nunca
	 * reaproveita o de uma alternativa removida: reaproveitar faria os votos da
	 * antiga ressuscitarem sob um texto que ninguém escolheu.
	 *
	 * @param int   $enquete_id ID da enquete.
	 * @param array $enviadas   Linhas cruas do formulário.
	 * @return array[] Lista de `array( 'id' => string, 'texto' => string )`.
	 */
	private static function normalizar_opcoes( $enquete_id, $enviadas ) {
		$conhecidos = array();

		foreach ( self::opcoes( $enquete_id ) as $opcao ) {
			$conhecidos[] = $opcao['id'];
		}

		$proximo = self::proximo_identificador( $enquete_id, $conhecidos );
		$opcoes  = array();
		$usados  = array();

		foreach ( $enviadas as $linha ) {
			if ( count( $opcoes ) >= self::LIMITE_OPCOES ) {
				break;
			}

			$texto = isset( $linha['texto'] ) ? sanitize_text_field( $linha['texto'] ) : '';

			if ( '' === $texto ) {
				continue;
			}

			$id = isset( $linha['id'] ) ? sanitize_key( $linha['id'] ) : '';

			// Identificador desconhecido ou repetido recebe um novo. Desconhecido
			// significa forjado no navegador; repetido, duas linhas somando votos na
			// mesma chave.
			if ( '' === $id || ! in_array( $id, $conhecidos, true ) || in_array( $id, $usados, true ) ) {
				$id = 'op-' . $proximo;
				++$proximo;
			}

			$usados[] = $id;

			$opcoes[] = array(
				'id'    => $id,
				'texto' => $texto,
			);
		}

		return $opcoes;
	}

	/**
	 * O próximo número livre de identificador de alternativa.
	 *
	 * Parte do maior sufixo já visto — inclusive o de alternativas apagadas, que
	 * continuam registradas nos votos até a poda.
	 *
	 * @param int      $enquete_id ID da enquete.
	 * @param string[] $conhecidos Identificadores das alternativas atuais.
	 * @return int
	 */
	private static function proximo_identificador( $enquete_id, $conhecidos ) {
		$candidatos = array_merge( $conhecidos, array_values( self::votantes( $enquete_id ) ) );
		$maior      = 0;

		foreach ( $candidatos as $id ) {
			if ( preg_match( '/^op-(\d+)$/', (string) $id, $partes ) ) {
				$maior = max( $maior, (int) $partes[1] );
			}
		}

		return $maior + 1;
	}

	/**
	 * Descarta os votos dados a alternativas que não existem mais.
	 *
	 * Sem isso, `contagem()` continuaria correta — ela parte das opções — mas o
	 * mapa de votantes cresceria guardando escolhas mortas, e quem já votou numa
	 * alternativa removida apareceria como "já votou" sem ver o próprio voto em
	 * lugar nenhum da tela.
	 *
	 * @param int   $enquete_id ID da enquete.
	 * @param array $opcoes     Alternativas que sobreviveram.
	 * @return void
	 */
	private static function podar_votos_orfaos( $enquete_id, $opcoes ) {
		$votantes = self::votantes( $enquete_id );

		if ( empty( $votantes ) ) {
			return;
		}

		$validos = wp_list_pluck( $opcoes, 'id' );
		$podados = array();

		foreach ( $votantes as $usuario_id => $opcao_id ) {
			if ( in_array( (string) $opcao_id, $validos, true ) ) {
				$podados[ $usuario_id ] = (string) $opcao_id;
			}
		}

		if ( count( $podados ) === count( $votantes ) ) {
			return;
		}

		if ( empty( $podados ) ) {
			delete_post_meta( $enquete_id, self::META_VOTANTES );

			return;
		}

		update_post_meta( $enquete_id, self::META_VOTANTES, $podados );
	}

	/* ---------------------------------------------------------------------
	 * Apoio
	 * ------------------------------------------------------------------ */

	/**
	 * A alternativa pertence a esta enquete?
	 *
	 * @param int    $enquete_id ID da enquete.
	 * @param string $opcao_id   ID da alternativa.
	 * @return bool
	 */
	private static function opcao_existe( $enquete_id, $opcao_id ) {
		foreach ( self::opcoes( $enquete_id ) as $opcao ) {
			if ( $opcao['id'] === (string) $opcao_id ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * A janela de datas está aberta nesta data?
	 *
	 * @param string $inicio Primeiro dia em `Y-m-d`, ou vazio.
	 * @param string $fim    Último dia em `Y-m-d`, ou vazio.
	 * @param string $hoje   Data de referência em `Y-m-d`; vazio usa hoje.
	 * @return bool
	 */
	private static function janela_aberta( $inicio, $fim, $hoje = '' ) {
		$hoje = '' !== $hoje ? $hoje : current_time( 'Y-m-d' );

		if ( '' !== $inicio && $hoje < $inicio ) {
			return false;
		}

		if ( '' !== $fim && $hoje > $fim ) {
			return false;
		}

		return true;
	}

	/**
	 * Aceita a data só no formato do `<input type="date">`.
	 *
	 * Qualquer outra coisa vira string vazia, que a vigência lê como "sem
	 * limite". Guardar `30/09/2026` faria a comparação — que é textual, em
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

	/**
	 * O índice de enquetes publicadas, com a janela de cada uma.
	 *
	 * @return array[] `array( id => array( 'inicio' => string, 'fim' => string ) )`.
	 */
	private static function indice() {
		$indice = get_transient( self::TRANSIENT_INDICE );

		if ( is_array( $indice ) ) {
			return $indice;
		}

		$enquetes = get_posts(
			array(
				'post_type'        => self::POST_TYPE,
				'post_status'      => 'publish',
				'numberposts'      => 50,
				'orderby'          => 'date',
				'order'            => 'DESC',
				'fields'           => 'ids',
				'suppress_filters' => false,
			)
		);

		$indice = array();

		foreach ( $enquetes as $enquete_id ) {
			$indice[ (int) $enquete_id ] = array(
				'inicio' => (string) get_post_meta( $enquete_id, self::META_INICIO, true ),
				'fim'    => (string) get_post_meta( $enquete_id, self::META_FIM, true ),
			);
		}

		set_transient( self::TRANSIENT_INDICE, $indice, 5 * MINUTE_IN_SECONDS );

		return $indice;
	}

	/**
	 * Apaga o índice em cache.
	 *
	 * @return void
	 */
	public static function esquecer_indice() {
		delete_transient( self::TRANSIENT_INDICE );
	}

	/**
	 * Devolve o usuário de onde ele veio, ou o JSON que o `fetch()` espera.
	 *
	 * @param int            $enquete_id ID da enquete.
	 * @param int[]|WP_Error $resultado  Contagem nova, ou erro.
	 * @return void
	 */
	private static function responder( $enquete_id, $resultado ) {
		$erro = is_wp_error( $resultado );

		if ( wp_is_json_request() || ! empty( $_POST['assincrono'] ) ) {
			if ( $erro ) {
				wp_send_json_error( array( 'mensagem' => $resultado->get_error_message() ), 403 );
			}

			wp_send_json_success(
				array(
					'contagem' => $resultado,
					'total'    => (int) array_sum( $resultado ),
					'escolha'  => self::voto_de( $enquete_id ),
				)
			);
		}

		if ( $erro ) {
			wp_die(
				esc_html( $resultado->get_error_message() ),
				esc_html__( 'Ação não permitida', 'reconectar-core' ),
				array(
					'response'  => 403,
					'back_link' => true,
				)
			);
		}

		// O componente aparece em todas as telas, então devolver sempre à página
		// da enquete tiraria o usuário de onde ele estava lendo.
		// `wp_safe_redirect` já recusa destino externo.
		$destino = wp_get_referer();

		if ( ! $destino ) {
			$destino = get_permalink( $enquete_id );
		}

		/*
		 * O fragmento nunca chega ao servidor — o navegador não o envia, nem no
		 * `Referer` —, então o formulário o declara num campo próprio. Sem isso,
		 * votar no painel de transparência, que empilha um cartão por enquete,
		 * devolveria o leitor ao topo da página: o voto aconteceu e o resultado
		 * dele fica fora da tela, o que se lê como "não funcionou".
		 */
		$ancora = isset( $_POST['ancora'] ) ? sanitize_key( wp_unslash( $_POST['ancora'] ) ) : '';

		if ( '' !== $ancora ) {
			$destino = strtok( $destino, '#' ) . '#' . $ancora;
		}

		wp_safe_redirect( $destino );
		exit;
	}
}
