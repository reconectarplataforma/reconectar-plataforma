<?php
/**
 * Camada de perguntas e respostas sobre o bbPress.
 *
 * O bbPress é um fórum clássico: tópico, resposta, categoria e tag. O que a
 * plataforma pede é um Q&A — e as três peças que faltam para isso são **votos**,
 * **visualizações** e **melhor resposta**. Esta classe é só elas.
 *
 * Nada aqui reimplementa o que o bbPress já faz. Pergunta continua sendo
 * `topic`, resposta continua sendo `reply`, categoria continua sendo `forum` e
 * tag continua sendo `topic-tag`; a contagem de respostas sai de
 * `_bbp_reply_count`, que o próprio plugin mantém. Um dia em que estas três
 * metas sumirem, o fórum continua de pé — só deixa de ser Q&A.
 *
 * Fica no plugin, e não no tema, pela razão que `reconectar-core.php` já
 * documenta: voto é regra de negócio, e regra de negócio não pode depender de
 * qual tema esteja ativo.
 *
 * @package reconectar-core
 */

defined( 'ABSPATH' ) || exit;

/**
 * Votos, visualizações e melhor resposta.
 */
class Reconectar_Forum {

	/**
	 * Saldo de votos do tópico ou da resposta.
	 *
	 * Inteiro, podendo ser negativo. É derivado — veja `META_VOTANTES`.
	 */
	const META_VOTOS = '_reconectar_votos';

	/**
	 * Quem votou e como: `array( user_id => 1|-1 )`.
	 *
	 * Esta é a fonte da verdade, e `META_VOTOS` é o total dela. Guardar os dois
	 * é o que permite impedir o voto duplo, desfazer um voto e trocar de sinal
	 * sem que o saldo derive: ele é sempre recalculado da lista, nunca
	 * incrementado às cegas.
	 */
	const META_VOTANTES = '_reconectar_votantes';

	/**
	 * Leituras do tópico.
	 */
	const META_VISUALIZACOES = '_reconectar_visualizacoes';

	/**
	 * ID da resposta aceita pelo autor da pergunta.
	 */
	const META_MELHOR_RESPOSTA = '_reconectar_melhor_resposta';

	/**
	 * Ação de voto em `admin-post.php`.
	 */
	const ACAO_VOTAR = 'reconectar_votar';

	/**
	 * Ação de marcação da melhor resposta em `admin-post.php`.
	 */
	const ACAO_MELHOR_RESPOSTA = 'reconectar_melhor_resposta';

	/**
	 * Janela em que uma segunda leitura do mesmo tópico não conta de novo.
	 */
	const JANELA_DE_LEITURA = 12 * HOUR_IN_SECONDS;

	/**
	 * Os seis filtros de URL de perfil da integração bbPress↔BuddyPress.
	 *
	 * Cada entrada é `filtro => array( método do bbPress, função de slug )`. O
	 * slug é o nome de uma função, e não o valor: `bbp_get_topic_archive_slug()`
	 * lê opção, e no momento do registro as opções do bbPress ainda não estão
	 * todas de pé.
	 *
	 * @see substituir_urls_de_perfil()
	 */
	const FILTROS_DE_PERFIL = array(
		'bbp_pre_get_user_profile_url'         => array( 'get_user_profile_url', '' ),
		'bbp_pre_get_user_topics_created_url'  => array( 'get_topics_created_url', 'bbp_get_topic_archive_slug' ),
		'bbp_pre_get_user_replies_created_url' => array( 'get_replies_created_url', 'bbp_get_reply_archive_slug' ),
		'bbp_pre_get_user_engagements_url'     => array( 'get_engagements_permalink', 'bbp_get_user_engagements_slug' ),
		'bbp_pre_get_favorites_permalink'      => array( 'get_favorites_permalink', 'bbp_get_user_favorites_slug' ),
		'bbp_pre_get_subscriptions_permalink'  => array( 'get_subscriptions_permalink', 'bbp_get_user_subscriptions_slug' ),
	);

	/**
	 * Registra os ganchos.
	 */
	public static function init() {
		add_filter( 'posts_clauses', array( __CLASS__, 'ordenar_por_votos' ), 10, 2 );

		add_action( 'admin_post_' . self::ACAO_VOTAR, array( __CLASS__, 'processar_voto' ) );
		add_action( 'admin_post_' . self::ACAO_MELHOR_RESPOSTA, array( __CLASS__, 'processar_melhor_resposta' ) );

		// Prioridade 20 para contar depois de `bloquear_comunidade()`, que está na
		// padrão: leitura negada não é leitura.
		add_action( 'template_redirect', array( __CLASS__, 'contar_leitura' ), 20 );

		// `bp_init` roda no `init` do WP, quando a integração já se construiu em
		// `bp_include`. Se o BuddyPress não estiver ativo, o gancho nunca dispara.
		add_action( 'bp_init', array( __CLASS__, 'substituir_urls_de_perfil' ) );
	}

	/* ---------------------------------------------------------------------
	 * Integração bbPress ↔ BuddyPress
	 * ------------------------------------------------------------------ */

	/**
	 * Troca as URLs de perfil da integração por versões sem função obsoleta.
	 *
	 * `BBP_BuddyPress_Members::get_profile_url()`
	 * (`bbpress/includes/extend/buddypress/members.php:232`) testa
	 * `function_exists( 'bp_core_get_user_domain' )` **antes** de
	 * `bp_members_get_user_url()`. No BuddyPress 12+ a primeira continua
	 * existindo como casca depreciada — então o teste passa, o ramo antigo roda
	 * e `_deprecated_function()` **imprime** o aviso, porque `WP_DEBUG` está
	 * ligado no ambiente de desenvolvimento.
	 *
	 * O sintoma não foi um aviso na tela: foi o **redirect não acontecer**. Ao
	 * salvar uma pergunta, o bbPress termina em `wp_safe_redirect()`, e o
	 * `header()` de `pluggable.php:1539` falha com "headers already sent" —
	 * saída já tinha ido para o navegador. A pergunta era gravada e o usuário
	 * ficava numa tela de erros, sem nunca ver o tópico criado.
	 *
	 * Silenciar o aviso resolveria a tela e deixaria a chamada obsoleta de pé,
	 * para quebrar de novo quando o BuddyPress remover a casca. Trocar o
	 * callback resolve a causa: o corpo abaixo é o mesmo de `get_profile_url()`,
	 * com o ramo que o próprio bbPress usaria se a ordem dos testes estivesse
	 * certa.
	 *
	 * @return void
	 */
	public static function substituir_urls_de_perfil() {
		if ( ! function_exists( 'bbpress' ) || ! function_exists( 'bp_members_get_user_url' ) ) {
			return;
		}

		$bbpress = bbpress();

		if ( empty( $bbpress->extend->buddypress->members ) ) {
			return;
		}

		$membros = $bbpress->extend->buddypress->members;

		foreach ( self::FILTROS_DE_PERFIL as $filtro => $destino ) {
			list( $metodo, $funcao_de_slug ) = $destino;

			remove_filter( $filtro, array( $membros, $metodo ) );

			add_filter(
				$filtro,
				static function ( $usuario_id ) use ( $funcao_de_slug ) {
					return self::url_de_perfil( $usuario_id, $funcao_de_slug );
				}
			);
		}
	}

	/**
	 * URL do perfil do usuário no BuddyPress, com sufixo opcional do bbPress.
	 *
	 * Devolver `false` — e não string vazia — é o que mantém o comportamento
	 * original: `bbp_get_user_profile_url()` só aceita o atalho do filtro quando
	 * o retorno é string, e cai no caminho nativo do bbPress em qualquer outro
	 * caso.
	 *
	 * @param int    $usuario_id     Usuário.
	 * @param string $funcao_de_slug Nome da função que devolve o slug da seção,
	 *                               ou string vazia para o perfil raiz.
	 * @return string|false
	 */
	public static function url_de_perfil( $usuario_id, $funcao_de_slug = '' ) {
		$usuario_id = (int) $usuario_id;

		if ( ! $usuario_id || ! function_exists( 'bp_is_root_blog' ) || ! bp_is_root_blog() ) {
			return false;
		}

		$partes = array( bp_members_get_user_url( $usuario_id ) );

		if ( $funcao_de_slug && function_exists( $funcao_de_slug ) ) {
			$partes[] = bbpress()->extend->buddypress->slug;
			$partes[] = call_user_func( $funcao_de_slug );
		}

		return implode( '', array_map( 'trailingslashit', $partes ) );
	}

	/* ---------------------------------------------------------------------
	 * Votos
	 * ------------------------------------------------------------------ */

	/**
	 * Saldo de votos.
	 *
	 * @param int $post_id Tópico ou resposta.
	 * @return int
	 */
	public static function votos( $post_id ) {
		return (int) get_post_meta( (int) $post_id, self::META_VOTOS, true );
	}

	/**
	 * Como este usuário votou: `1`, `-1` ou `0` para nenhum voto.
	 *
	 * @param int $post_id    Tópico ou resposta.
	 * @param int $usuario_id Usuário; `0` usa o atual.
	 * @return int
	 */
	public static function voto_do_usuario( $post_id, $usuario_id = 0 ) {
		$usuario_id = $usuario_id ? (int) $usuario_id : get_current_user_id();

		if ( ! $usuario_id ) {
			return 0;
		}

		$votantes = self::votantes( $post_id );

		return isset( $votantes[ $usuario_id ] ) ? (int) $votantes[ $usuario_id ] : 0;
	}

	/**
	 * Lista de votantes, normalizada.
	 *
	 * @param int $post_id Tópico ou resposta.
	 * @return int[] `array( user_id => 1|-1 )`
	 */
	private static function votantes( $post_id ) {
		$votantes = get_post_meta( (int) $post_id, self::META_VOTANTES, true );

		return is_array( $votantes ) ? $votantes : array();
	}

	/**
	 * Quem pode votar neste conteúdo.
	 *
	 * Duas condições, e a segunda é a que importa: ninguém vota no que escreveu.
	 * A checagem está aqui, e não só na ausência do botão, porque o botão é
	 * interface — e interface não autoriza nada.
	 *
	 * @param int $post_id    Tópico ou resposta.
	 * @param int $usuario_id Usuário; `0` usa o atual.
	 * @return bool
	 */
	public static function pode_votar( $post_id, $usuario_id = 0 ) {
		$usuario_id = $usuario_id ? (int) $usuario_id : get_current_user_id();
		$post       = get_post( (int) $post_id );

		if ( ! $usuario_id || ! $post || ! in_array( $post->post_type, self::tipos_votaveis(), true ) ) {
			return false;
		}

		if ( ! user_can( $usuario_id, Reconectar_Permissoes::CAP_COMUNIDADE ) ) {
			return false;
		}

		return (int) $post->post_author !== $usuario_id;
	}

	/**
	 * Post types que aceitam voto.
	 *
	 * @return string[]
	 */
	private static function tipos_votaveis() {
		return array( 'topic', 'reply' );
	}

	/**
	 * Registra, troca ou desfaz um voto.
	 *
	 * Votar de novo no mesmo sentido desfaz — é o comportamento que todo Q&A tem
	 * e a única forma de corrigir um clique errado sem um segundo controle na
	 * tela.
	 *
	 * @param int $post_id    Tópico ou resposta.
	 * @param int $sentido    `1` ou `-1`.
	 * @param int $usuario_id Usuário; `0` usa o atual.
	 * @return int|WP_Error Saldo novo, ou erro.
	 */
	public static function votar( $post_id, $sentido, $usuario_id = 0 ) {
		$post_id    = (int) $post_id;
		$usuario_id = $usuario_id ? (int) $usuario_id : get_current_user_id();
		$sentido    = ( 0 > (int) $sentido ) ? -1 : 1;

		if ( ! self::pode_votar( $post_id, $usuario_id ) ) {
			return new WP_Error( 'reconectar_voto_negado', __( 'Você não pode votar neste conteúdo.', 'reconectar-core' ) );
		}

		$votantes = self::votantes( $post_id );
		$anterior = isset( $votantes[ $usuario_id ] ) ? (int) $votantes[ $usuario_id ] : 0;

		if ( $anterior === $sentido ) {
			unset( $votantes[ $usuario_id ] );
		} else {
			$votantes[ $usuario_id ] = $sentido;
		}

		/*
		 * O saldo é recalculado da lista, e não somado ao valor guardado. Somar
		 * seria mais barato e deixaria os dois números divergirem para sempre no
		 * primeiro voto perdido por concorrência — e um saldo que não confere com
		 * a lista de votantes não tem como ser corrigido depois.
		 */
		$saldo = array_sum( array_map( 'intval', $votantes ) );

		if ( empty( $votantes ) ) {
			delete_post_meta( $post_id, self::META_VOTANTES );
		} else {
			update_post_meta( $post_id, self::META_VOTANTES, $votantes );
		}

		update_post_meta( $post_id, self::META_VOTOS, $saldo );

		return $saldo;
	}

	/**
	 * Atende ao POST do formulário de voto.
	 *
	 * O caminho sem JavaScript é o caminho principal, não o degradado: o
	 * formulário é um `<form method="post">` comum e o usuário volta para onde
	 * estava. `responder()` já devolve JSON quando o pedido for assíncrono, para
	 * que um enriquecimento por `fetch()` não precise de um segundo endpoint.
	 */
	public static function processar_voto() {
		$post_id = isset( $_POST['conteudo'] ) ? (int) $_POST['conteudo'] : 0;
		$sentido = isset( $_POST['sentido'] ) && '-1' === (string) $_POST['sentido'] ? -1 : 1;

		check_admin_referer( self::ACAO_VOTAR . '_' . $post_id );

		$resultado = self::votar( $post_id, $sentido );

		self::responder( $post_id, $resultado, 'votos' );
	}

	/**
	 * Sinaliza que a consulta deve ser ordenada pelo saldo de votos.
	 */
	const ARG_ORDENAR_POR_VOTOS = 'reconectar_ordenar_por_votos';

	/**
	 * Monta os argumentos da aba "Votos".
	 *
	 * A ordenação **não** passa por `meta_key` nem por `meta_query`, e o motivo
	 * está medido. As duas rotas óbvias falham, cada uma do seu jeito:
	 *
	 * 1. `meta_key => '_reconectar_votos'` com `orderby => 'meta_value'` monta um
	 *    **INNER JOIN** no `postmeta`, e todo tópico sem a meta some da lista. Não
	 *    dá erro: dá uma lista incompleta, que é pior, porque continua plausível.
	 * 2. A correção intuitiva para (1) — `meta_query` com `relation => 'OR'` e um
	 *    ramo `compare => 'NOT EXISTS'` — traz todo mundo de volta e **estraga a
	 *    ordem**. Com relação OR o `WP_Meta_Query` tira a condição de `meta_key`
	 *    do `ON` e a joga no `WHERE`:
	 *
	 *        LEFT JOIN wp_postmeta ON ( wp_posts.ID = wp_postmeta.post_id )
	 *        WHERE ( wp_postmeta.meta_key = '_reconectar_votos' OR mt1.post_id IS NULL )
	 *        GROUP BY wp_posts.ID
	 *        ORDER BY CAST( wp_postmeta.meta_value AS SIGNED ) DESC
	 *
	 *    O join passa a casar **todas** as metas de cada post, e o `GROUP BY`
	 *    escolhe um `meta_value` qualquer entre elas para ordenar. Medido nesta
	 *    instalação: um tópico com saldo 7 ficou **atrás** de um sem voto nenhum.
	 *
	 * O `LEFT JOIN` próprio de `ordenar_por_votos()` resolve as duas de uma vez —
	 * a condição fica no `ON`, e o `COALESCE` dá zero a quem não tem a meta.
	 *
	 * @return array Fragmento para `bbp_has_topics()`.
	 */
	public static function consulta_por_votos() {
		return array(
			self::ARG_ORDENAR_POR_VOTOS => true,
			/*
			 * Os dois precisam ser zerados juntos. `bbp_has_topics()` tem por padrão
			 * `meta_key => '_bbp_last_active_time'` com `meta_type => 'DATETIME'`
			 * (`bbpress/includes/topics/template.php:167`), e deixar o par de pé
			 * acrescentaria à consulta um join que esta ordenação não usa.
			 */
			'meta_key'                  => '', // phpcs:ignore WordPress.DB.SlowDBQuery
			'meta_type'                 => '',
			// Ordem de reserva: se o filtro abaixo não rodar — outro plugin
			// devolvendo `$clauses` sem ele, um `suppress_filters` —, a lista sai
			// pela data em vez de sair numa ordem arbitrária.
			'orderby'                   => 'date',
			'order'                     => 'DESC',
		);
	}

	/**
	 * Ordena a listagem pelo saldo de votos, sem perder quem não tem nenhum.
	 *
	 * `posts_clauses` em vez de `meta_query` pela razão que `consulta_por_votos()`
	 * documenta. Duas propriedades que só este caminho dá:
	 *
	 * - a condição de `meta_key` fica no `ON` do `LEFT JOIN`, então o join casa no
	 *   máximo uma linha por post e não multiplica resultado;
	 * - `COALESCE( …, 0 )` trata "sem voto" como zero, que é o que ele é — em vez
	 *   de `NULL`, que o MySQL joga para o fim em `DESC` e para o começo em `ASC`.
	 *
	 * O alias `rc_votos` é fixo de propósito: a consulta é uma só por página, e um
	 * alias previsível é o que permite depurar lendo `$query->request`.
	 *
	 * @param string[] $clausulas Cláusulas SQL da consulta.
	 * @param WP_Query $consulta  Consulta em montagem.
	 * @return string[]
	 */
	public static function ordenar_por_votos( $clausulas, $consulta ) {
		global $wpdb;

		if ( ! $consulta->get( self::ARG_ORDENAR_POR_VOTOS ) ) {
			return $clausulas;
		}

		$clausulas['join'] .= $wpdb->prepare(
			" LEFT JOIN {$wpdb->postmeta} AS rc_votos ON ( rc_votos.post_id = {$wpdb->posts}.ID AND rc_votos.meta_key = %s )",
			self::META_VOTOS
		);

		$clausulas['orderby'] = "COALESCE( CAST( rc_votos.meta_value AS SIGNED ), 0 ) DESC, {$wpdb->posts}.post_date DESC";

		return $clausulas;
	}

	/* ---------------------------------------------------------------------
	 * Melhor resposta
	 * ------------------------------------------------------------------ */

	/**
	 * Resposta aceita do tópico, se houver.
	 *
	 * @param int $topico_id Tópico.
	 * @return int ID da resposta, ou `0`.
	 */
	public static function melhor_resposta( $topico_id ) {
		return (int) get_post_meta( (int) $topico_id, self::META_MELHOR_RESPOSTA, true );
	}

	/**
	 * Quem pode marcar a melhor resposta de um tópico.
	 *
	 * O autor da pergunta, porque é dele a dúvida; e quem administra a operação,
	 * para o caso de o autor não voltar mais.
	 *
	 * @param int $topico_id  Tópico.
	 * @param int $usuario_id Usuário; `0` usa o atual.
	 * @return bool
	 */
	public static function pode_marcar_melhor_resposta( $topico_id, $usuario_id = 0 ) {
		$usuario_id = $usuario_id ? (int) $usuario_id : get_current_user_id();
		$topico     = get_post( (int) $topico_id );

		if ( ! $usuario_id || ! $topico || 'topic' !== $topico->post_type ) {
			return false;
		}

		// A capacidade de gerir lojas serve aqui como "administra a operação". Se
		// ela mudar de nome de novo, esta linha precisa acompanhar: `user_can()`
		// com capacidade inexistente devolve `false` em silêncio, e o fórum
		// perderia o caminho de moderação sem nenhum erro na tela.
		if ( user_can( $usuario_id, Reconectar_Permissoes::CAP_GERIR_LOJAS ) ) {
			return true;
		}

		return (int) $topico->post_author === $usuario_id
			&& user_can( $usuario_id, Reconectar_Permissoes::CAP_COMUNIDADE );
	}

	/**
	 * Marca ou desmarca a resposta aceita.
	 *
	 * Uma por tópico: marcar outra troca, marcar a mesma desfaz.
	 *
	 * @param int $resposta_id Resposta.
	 * @param int $usuario_id  Usuário; `0` usa o atual.
	 * @return int|WP_Error ID da resposta aceita depois da operação (`0` se desmarcou), ou erro.
	 */
	public static function marcar_melhor_resposta( $resposta_id, $usuario_id = 0 ) {
		$resposta_id = (int) $resposta_id;
		$resposta    = get_post( $resposta_id );

		if ( ! $resposta || 'reply' !== $resposta->post_type ) {
			return new WP_Error( 'reconectar_resposta_invalida', __( 'Resposta não encontrada.', 'reconectar-core' ) );
		}

		$topico_id = (int) $resposta->post_parent;

		if ( ! self::pode_marcar_melhor_resposta( $topico_id, $usuario_id ) ) {
			return new WP_Error( 'reconectar_melhor_negada', __( 'Só quem fez a pergunta pode escolher a melhor resposta.', 'reconectar-core' ) );
		}

		if ( self::melhor_resposta( $topico_id ) === $resposta_id ) {
			delete_post_meta( $topico_id, self::META_MELHOR_RESPOSTA );

			return 0;
		}

		update_post_meta( $topico_id, self::META_MELHOR_RESPOSTA, $resposta_id );

		return $resposta_id;
	}

	/**
	 * Atende ao POST da marcação de melhor resposta.
	 */
	public static function processar_melhor_resposta() {
		$resposta_id = isset( $_POST['resposta'] ) ? (int) $_POST['resposta'] : 0;

		check_admin_referer( self::ACAO_MELHOR_RESPOSTA . '_' . $resposta_id );

		$resultado = self::marcar_melhor_resposta( $resposta_id );

		self::responder( $resposta_id, $resultado, 'melhor' );
	}

	/* ---------------------------------------------------------------------
	 * Visualizações
	 * ------------------------------------------------------------------ */

	/**
	 * Conta a leitura de um tópico.
	 *
	 * Três exclusões, todas para que o número signifique alguma coisa: o próprio
	 * autor não conta, uma segunda leitura dentro de `JANELA_DE_LEITURA` não
	 * conta, e quem não pode ver o tópico não chegou aqui — o gate de
	 * `Reconectar_Permissoes` roda antes, na prioridade padrão.
	 *
	 * O registro do que já foi lido vai em transient, e não em cookie: um cookie
	 * de rastreio de leitura precisaria entrar no aviso de privacidade, e o
	 * transient resolve sem coletar nada de quem visita.
	 */
	public static function contar_leitura() {
		if ( ! is_singular( 'topic' ) ) {
			return;
		}

		$topico_id  = get_queried_object_id();
		$usuario_id = get_current_user_id();

		if ( ! $topico_id || ! $usuario_id ) {
			return;
		}

		if ( (int) get_post_field( 'post_author', $topico_id ) === $usuario_id ) {
			return;
		}

		$marca = 'reconectar_lido_' . $usuario_id . '_' . $topico_id;

		if ( get_transient( $marca ) ) {
			return;
		}

		set_transient( $marca, 1, self::JANELA_DE_LEITURA );
		update_post_meta( $topico_id, self::META_VISUALIZACOES, self::visualizacoes( $topico_id ) + 1 );
	}

	/**
	 * Leituras registradas do tópico.
	 *
	 * @param int $topico_id Tópico.
	 * @return int
	 */
	public static function visualizacoes( $topico_id ) {
		return (int) get_post_meta( (int) $topico_id, self::META_VISUALIZACOES, true );
	}

	/* ---------------------------------------------------------------------
	 * Apoio
	 * ------------------------------------------------------------------ */

	/**
	 * Devolve o usuário de onde ele veio, ou o JSON que o `fetch()` espera.
	 *
	 * @param int           $post_id   Conteúdo afetado, para montar o destino de retorno.
	 * @param int|WP_Error  $resultado Retorno da operação.
	 * @param string        $campo     Nome do dado no JSON: `votos` ou `melhor`.
	 */
	private static function responder( $post_id, $resultado, $campo ) {
		$erro = is_wp_error( $resultado );

		if ( wp_is_json_request() || ! empty( $_POST['assincrono'] ) ) {
			if ( $erro ) {
				wp_send_json_error( array( 'mensagem' => $resultado->get_error_message() ), 403 );
			}

			wp_send_json_success( array( $campo => (int) $resultado ) );
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

		// `wp_get_referer()` porque o voto acontece tanto na listagem quanto no
		// tópico, e devolver sempre ao tópico tiraria o usuário da lista que ele
		// estava lendo. `wp_safe_redirect` já recusa destino externo.
		$destino = wp_get_referer();

		if ( ! $destino ) {
			$destino = get_permalink( $post_id );
		}

		wp_safe_redirect( $destino );
		exit;
	}
}
