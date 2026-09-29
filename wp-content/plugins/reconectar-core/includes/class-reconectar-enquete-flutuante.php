<?php
/**
 * O componente de enquete que acompanha todas as telas.
 *
 * Aparece só quando há enquete aberta, e some sozinho quando a última encerra —
 * nenhum lugar da interface precisa ser reservado para ele.
 *
 * Mora no plugin, e não no tema, por duas razões. A primeira é a de sempre:
 * consulta à comunidade é conteúdo da instituição, e trocar de tema não pode
 * apagar uma votação em andamento. A segunda é de posicionamento: ele se pendura
 * em `wp_footer`, que `footer.php:56` chama **fora de `#page`**, e herda de graça
 * a defesa que a barra inferior tem por escrito ali — o Storefront declara
 * `.site { overflow-x: hidden }`, o que faz `overflow-y` computar `auto` e
 * transforma a `<div class="hfeed site">` em scroll container, e `position: fixed`
 * dentro de scroll container é terreno onde navegador de celular diverge da
 * especificação.
 *
 * @package Reconectar_Core
 */

defined( 'ABSPATH' ) || exit;

/**
 * Imprime o cartão flutuante de votação.
 */
class Reconectar_Enquete_Flutuante {

	/**
	 * Quantas enquetes o cartão mostra de uma vez.
	 */
	const LIMITE = 3;

	/**
	 * Registra os ganchos do componente.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enfileirar_assets' ), 40 );
		add_action( 'wp_footer', array( __CLASS__, 'imprimir' ), 20 );
	}

	/**
	 * As enquetes que este componente mostra nesta requisição.
	 *
	 * Memoizada porque o enqueue e a impressão perguntam a mesma coisa em dois
	 * momentos da página, e a consulta não precisa acontecer duas vezes.
	 *
	 * @return WP_Post[]
	 */
	private static function enquetes() {
		static $enquetes = null;

		if ( null === $enquetes ) {
			$enquetes = Reconectar_Proposta_Votacao::abertas( self::LIMITE );
		}

		return $enquetes;
	}

	/**
	 * Enfileira o CSS e o JS do componente.
	 *
	 * **Só quando houver enquete aberta.** Sem essa guarda o site inteiro
	 * carregaria o CSS de um componente que não desenha em lugar nenhum.
	 *
	 * @return void
	 */
	public static function enfileirar_assets() {
		if ( is_admin() || empty( self::enquetes() ) ) {
			return;
		}

		wp_enqueue_style(
			'reconectar-enquete',
			RECONECTAR_CORE_URL . 'assets/css/enquete.css',
			array(),
			'0.1.0'
		);

		wp_enqueue_script(
			'reconectar-enquete',
			RECONECTAR_CORE_URL . 'assets/js/enquete.js',
			array(),
			'0.1.0',
			array(
				'strategy'  => 'defer',
				'in_footer' => true,
			)
		);
	}

	/**
	 * Imprime o cartão.
	 *
	 * @return void
	 */
	public static function imprimir() {
		$enquetes = self::enquetes();

		if ( empty( $enquetes ) ) {
			return;
		}

		/*
		 * `<details>` e não um `<button aria-expanded>` operado por JavaScript: o
		 * elemento nativo abre e fecha sem script nenhum, e o navegador já expõe o
		 * estado à tecnologia assistiva. O JS só acrescenta a memória da sessão e a
		 * tecla Esc — se ele falhar, o componente continua inteiro.
		 *
		 * Nasce **fechado**, inclusive no desktop. É o JS que abre o cartão na
		 * primeira visita de cada sessão, e é isso que impede o componente de virar
		 * perseguição: presente em todas as telas, ele reabriria a cada navegação.
		 */
		?>
		<details class="rc-enquete" id="rc-enquete" data-rc-enquete="<?php echo esc_attr( implode( '-', wp_list_pluck( $enquetes, 'ID' ) ) ); ?>">
			<summary class="rc-enquete__gatilho">
				<span class="rc-enquete__icone" aria-hidden="true">✓</span>
				<span class="rc-enquete__gatilho-texto">
					<?php
					echo esc_html(
						sprintf(
							/* translators: %d: número de enquetes abertas. */
							_n( 'Enquete aberta', '%d enquetes abertas', count( $enquetes ), 'reconectar-core' ),
							count( $enquetes )
						)
					);
					?>
				</span>
			</summary>

			<div class="rc-enquete__corpo">
				<?php foreach ( $enquetes as $enquete ) : ?>
					<?php self::imprimir_enquete( $enquete ); ?>
				<?php endforeach; ?>
			</div>
		</details>
		<?php
	}

	/**
	 * Desenha uma enquete dentro do cartão.
	 *
	 * @param WP_Post $enquete Enquete aberta.
	 * @return void
	 */
	private static function imprimir_enquete( $enquete ) {
		$opcoes = Reconectar_Proposta_Votacao::opcoes( $enquete->ID );

		// Enquete publicada sem alternativa não tem o que perguntar. Desenhar o
		// título sozinho seria um convite para uma tela vazia.
		if ( empty( $opcoes ) ) {
			return;
		}

		$identificador = 'rc-enquete-' . (int) $enquete->ID;

		echo '<section class="rc-enquete__item" aria-labelledby="' . esc_attr( $identificador ) . '-titulo">';

		printf(
			'<h2 class="rc-enquete__titulo" id="%s-titulo">%s</h2>',
			esc_attr( $identificador ),
			esc_html( get_the_title( $enquete ) )
		);

		if ( ! is_user_logged_in() ) {
			self::imprimir_leitura( $opcoes );
			self::imprimir_convite_de_login();

			echo '</section>';

			return;
		}

		// Depois do ramo anônimo de propósito: sem placar em leitura, contar votos
		// ali seria trabalho jogado fora em toda página servida a visitante.
		$escolha  = Reconectar_Proposta_Votacao::voto_de( $enquete->ID );
		$contagem = Reconectar_Proposta_Votacao::contagem( $enquete->ID );
		$total    = Reconectar_Proposta_Votacao::total_de_votos( $enquete->ID );
		$ja_votou = '' !== $escolha;

		self::imprimir_formulario( $enquete, $opcoes, $contagem, $total, $escolha, $identificador, $ja_votou );

		echo '</section>';
	}

	/**
	 * Desenha a enquete em leitura, para quem não está logado.
	 *
	 * Esconder a enquete de quem não entrou seria perder o convite à
	 * participação, que é o motivo de o componente existir.
	 *
	 * **Sem placar**, pela mesma razão pela qual o formulário só o mostra depois
	 * do voto: ver o resultado antes conduz a escolha de quem ainda não decidiu.
	 * Aqui isso pesa mais, não menos — imprimir o placar para o visitante anônimo
	 * transformaria a regra num contorno de uma janela anônima, e quem chegasse
	 * por ela votaria já sabendo quem está ganhando. Quem quiser o resultado tem
	 * o painel de transparência, que é público de propósito e é o lugar dele.
	 *
	 * @param array[] $opcoes Alternativas.
	 * @return void
	 */
	private static function imprimir_leitura( $opcoes ) {
		echo '<ul class="rc-enquete__opcoes">';

		foreach ( $opcoes as $opcao ) {
			echo '<li class="rc-enquete__opcao">';
			printf( '<span class="rc-enquete__rotulo">%s</span>', esc_html( $opcao['texto'] ) );
			echo '</li>';
		}

		echo '</ul>';
	}

	/**
	 * Desenha o formulário de voto.
	 *
	 * Quem já votou vê a sua alternativa marcada e o resultado ao lado de cada
	 * uma; o botão muda de "Votar" para "Alterar meu voto". Um voto por conta,
	 * alterável enquanto a enquete estiver aberta — e o total não sobe na troca,
	 * porque a contagem é recalculada do mapa de votantes.
	 *
	 * @param WP_Post $enquete       Enquete.
	 * @param array[] $opcoes        Alternativas.
	 * @param int[]   $contagem      Votos por alternativa.
	 * @param int     $total         Total de votos.
	 * @param string  $escolha       Alternativa já escolhida, ou vazio.
	 * @param string  $identificador Prefixo dos IDs de campo.
	 * @param bool    $ja_votou      Se o usuário já votou.
	 * @return void
	 */
	private static function imprimir_formulario( $enquete, $opcoes, $contagem, $total, $escolha, $identificador, $ja_votou ) {
		printf(
			'<form class="rc-enquete__form" method="post" action="%s">',
			esc_url( admin_url( 'admin-post.php' ) )
		);

		printf(
			'<input type="hidden" name="action" value="%s">',
			esc_attr( Reconectar_Proposta_Votacao::ACAO_VOTAR )
		);

		printf( '<input type="hidden" name="enquete" value="%d">', (int) $enquete->ID );

		wp_nonce_field( Reconectar_Proposta_Votacao::ACAO_VOTAR . '_' . $enquete->ID );

		echo '<ul class="rc-enquete__opcoes">';

		foreach ( $opcoes as $indice => $opcao ) {
			$campo = $identificador . '-op-' . (int) $indice;
			$votos = isset( $contagem[ $opcao['id'] ] ) ? (int) $contagem[ $opcao['id'] ] : 0;

			echo '<li class="rc-enquete__opcao">';

			printf(
				'<input type="radio" class="rc-enquete__radio" id="%1$s" name="opcao" value="%2$s"%3$s>
				<label class="rc-enquete__rotulo" for="%1$s">%4$s</label>',
				esc_attr( $campo ),
				esc_attr( $opcao['id'] ),
				checked( $escolha, $opcao['id'], false ),
				esc_html( $opcao['texto'] )
			);

			// O resultado só aparece depois do voto: mostrar o placar antes
			// conduziria a escolha de quem ainda não decidiu.
			if ( $ja_votou ) {
				self::imprimir_resultado( $votos, $total );
			}

			echo '</li>';
		}

		echo '</ul>';

		printf(
			'<button type="submit" class="rc-enquete__enviar">%s</button>',
			$ja_votou
				? esc_html__( 'Alterar meu voto', 'reconectar-core' )
				: esc_html__( 'Votar', 'reconectar-core' )
		);

		if ( $ja_votou ) {
			printf(
				'<p class="rc-enquete__total">%s</p>',
				esc_html(
					sprintf(
						/* translators: %d: total de votos. */
						_n( '%d voto até agora.', '%d votos até agora.', $total, 'reconectar-core' ),
						$total
					)
				)
			);
		}

		echo '</form>';
	}

	/**
	 * Desenha o resultado de uma alternativa.
	 *
	 * Com nenhum voto, imprime texto — nunca `0%`, que exigiria dividir por zero,
	 * nem um número plausível no lugar dele.
	 *
	 * A barra leva `aria-hidden` porque o mesmo número já está no texto ao lado:
	 * um `role="progressbar"` faria o leitor de tela anunciar o percentual duas
	 * vezes seguidas.
	 *
	 * @param int $votos Votos da alternativa.
	 * @param int $total Total de votos da enquete.
	 * @return void
	 */
	private static function imprimir_resultado( $votos, $total ) {
		if ( $total < 1 ) {
			printf(
				'<span class="rc-enquete__sem-votos">%s</span>',
				esc_html__( 'Nenhum voto ainda', 'reconectar-core' )
			);

			return;
		}

		$percentual = Reconectar_Proposta_Votacao::percentual( $votos, $total );

		printf(
			'<span class="rc-enquete__barra" aria-hidden="true"><span class="rc-enquete__preenchimento" style="width: %s%%"></span></span>',
			esc_attr( round( $percentual, 1 ) )
		);

		printf(
			'<span class="rc-enquete__numero">%s</span>',
			esc_html(
				sprintf(
					/* translators: 1: percentual. 2: número de votos. */
					__( '%1$s%% (%2$d)', 'reconectar-core' ),
					number_format_i18n( $percentual, 1 ),
					(int) $votos
				)
			)
		);
	}

	/**
	 * Desenha o convite de login.
	 *
	 * @return void
	 */
	private static function imprimir_convite_de_login() {
		/*
		 * O retorno é a página atual, montada de `wp_login_url()` — que já passa
		 * pelo `redirect_to`. A URL vem de `home_url( add_query_arg( array() ) )` e
		 * não do `HTTP_HOST` cru: cabeçalho de requisição é dado do cliente, e a
		 * allowlist de host desta instalação existe justamente porque um `Host`
		 * forjado sairia dentro de um link enviado ao usuário.
		 */
		$atual = home_url( add_query_arg( array() ) );

		printf(
			'<p class="rc-enquete__convite"><a class="rc-enquete__login" href="%s">%s</a></p>',
			esc_url( wp_login_url( $atual ) ),
			esc_html__( 'Entre na sua conta para votar', 'reconectar-core' )
		);
	}
}
