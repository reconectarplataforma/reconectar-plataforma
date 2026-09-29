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
			'0.3.0'
		);

		wp_enqueue_script(
			'reconectar-enquete',
			RECONECTAR_CORE_URL . 'assets/js/enquete.js',
			array(),
			'0.3.0',
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
		 *
		 * O `<details>` está dentro de uma `<div>` que não existia antes, e o wrapper
		 * é o que permite ao × ficar **sobre** a faixa do gatilho sem estar dentro
		 * dela — veja o comentário do botão, logo abaixo. O `position: fixed` e a
		 * assinatura da dispensa moram no wrapper por isso.
		 */
		?>
		<div class="rc-enquete" data-rc-enquete="<?php echo esc_attr( implode( '-', wp_list_pluck( $enquetes, 'ID' ) ) ); ?>">
			<details class="rc-enquete__caixa" id="rc-enquete">
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
			/*
			 * O × dispensa o componente pelo resto da visita — esconde inclusive a
			 * pílula. Recolher pelo próprio `<summary>` já era lembrado na sessão,
			 * então um botão que apenas recolhesse seria um segundo caminho para o
			 * mesmo efeito, e o usuário que clica num × espera que aquilo saia da tela,
			 * não que encolha.
			 *
			 * Ele fica **sobre** a faixa do gatilho, e é irmão do `<details>`, não
			 * filho do `<summary>`. As duas razões que o mantinham no corpo continuam
			 * de pé, e é justamente por isso que ele saiu por cima:
			 *
			 * 1. A especificação desencoraja conteúdo interativo dentro de `<summary>`,
			 *    onde o clique no filho ainda alterna o `<details>` e o comportamento
			 *    diverge entre motores. Fora do `<details>`, o clique não alcança o
			 *    gatilho e o problema deixa de existir.
			 * 2. No celular fechado o gatilho vira um círculo de 44×44, sem espaço para
			 *    um segundo alvo — dois botões de 22px lado a lado ficariam abaixo do
			 *    piso do critério 2.5.5. Aqui isso não acontece porque o botão só é
			 *    revelado com o cartão aberto.
			 *
			 * Irmão, e não filho do `<summary>`, também porque conteúdo não-`<summary>`
			 * de um `<details>` fechado não é renderizado: dentro da caixa, o × só
			 * existiria quando o cartão já estivesse aberto — que é o efeito desejado,
			 * mas obtido de um jeito que o esconderia também de quem usa o teclado para
			 * tabular até ele.
			 *
			 * Nasce `hidden` e é o JS que o revela junto com o painel. Sem script ele
			 * não fazia nada e mesmo assim era impresso; agora só aparece onde funciona.
			 *
			 * Dispensar não esconde a participação: as mesmas enquetes seguem no painel
			 * de transparência, onde agora também se vota.
			 */
			?>
			<button
				type="button"
				class="rc-enquete__fechar"
				data-rc-enquete-fechar
				hidden
				title="<?php esc_attr_e( 'Fechar as enquetes nesta visita', 'reconectar-core' ); ?>"
				aria-label="<?php esc_attr_e( 'Fechar as enquetes nesta visita', 'reconectar-core' ); ?>"
			><span aria-hidden="true">&times;</span></button>
		</div>
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

		// Depois do ramo anônimo de propósito: quem não entrou não vê número
		// nenhum, e contar votos ali seria trabalho jogado fora em toda página
		// servida a visitante. A contagem por alternativa não é mais lida aqui —
		// o cartão imprime só o total, e o placar mora no painel de transparência.
		$escolha  = Reconectar_Proposta_Votacao::voto_de( $enquete->ID );
		$total    = Reconectar_Proposta_Votacao::total_de_votos( $enquete->ID );
		$ja_votou = '' !== $escolha;

		self::imprimir_formulario( $enquete, $opcoes, $total, $escolha, $identificador, $ja_votou );

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
	 * Quem já votou vê a sua alternativa marcada, a confirmação de que o voto foi
	 * registrado e o botão mudado de "Votar" para "Alterar meu voto". Um voto por
	 * conta, alterável enquanto a enquete estiver aberta — e o total não sobe na
	 * troca, porque a contagem é recalculada do mapa de votantes.
	 *
	 * **Nenhum percentual, em estado nenhum.** Esconder o placar só até o voto
	 * resolvia metade do problema: com o voto alterável, o percentual ao lado da
	 * própria escolha convida a trocá-la para acompanhar quem está ganhando, e a
	 * enquete passa a medir a si mesma. As duas saídas eram tirar o botão de
	 * alterar ou tirar o número; tirar o número preserva a chance de corrigir um
	 * clique errado, que num cartão de 343px no celular é acidente plausível.
	 *
	 * O resultado não desaparece do site — ele tem lugar próprio, o painel de
	 * transparência, onde é publicado no encerramento e fica para sempre. A
	 * reserva enquanto a enquete corre nasceu aqui e passou a valer lá também:
	 * esconder o percentual no cartão e publicá-lo na página seguinte não era
	 * proteção nenhuma, era um clique a mais.
	 *
	 * O total agregado fica: ele diz quantas pessoas participaram, não quem está
	 * ganhando, e não favorece alternativa nenhuma.
	 *
	 * @param WP_Post $enquete       Enquete.
	 * @param array[] $opcoes        Alternativas.
	 * @param int     $total         Total de votos.
	 * @param string  $escolha       Alternativa já escolhida, ou vazio.
	 * @param string  $identificador Prefixo dos IDs de campo.
	 * @param bool    $ja_votou      Se o usuário já votou.
	 * @return void
	 */
	private static function imprimir_formulario( $enquete, $opcoes, $total, $escolha, $identificador, $ja_votou ) {
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

			echo '<li class="rc-enquete__opcao">';

			printf(
				'<input type="radio" class="rc-enquete__radio" id="%1$s" name="opcao" value="%2$s"%3$s>
				<label class="rc-enquete__rotulo" for="%1$s">%4$s</label>',
				esc_attr( $campo ),
				esc_attr( $opcao['id'] ),
				checked( $escolha, $opcao['id'], false ),
				esc_html( $opcao['texto'] )
			);

			echo '</li>';
		}

		echo '</ul>';

		if ( $ja_votou ) {
			/*
			 * Sem placar, a opção marcada é a única pista de que o voto foi para o
			 * servidor — e um radio marcado é indistinguível de um radio que o
			 * navegador restaurou. A confirmação em texto é o que fecha essa lacuna,
			 * e é ela que torna a remoção do percentual aceitável.
			 */
			printf(
				'<p class="rc-enquete__confirmacao">%s</p>',
				esc_html__( 'Seu voto foi registrado.', 'reconectar-core' )
			);
		}

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

			self::imprimir_link_do_painel( $enquete->ID );
		}

		echo '</form>';
	}

	/**
	 * Imprime o caminho para o resultado, que saiu do cartão.
	 *
	 * O rótulo depende de quem lê. O cartão só existe para enquete **aberta**, e
	 * com o placar reservado até o encerramento o link antigo — "Ver o resultado
	 * no painel de transparência" — prometia ao leitor comum uma tela que não
	 * mostraria resultado nenhum. Promessa que a página seguinte desmente se lê
	 * como defeito, e o painel se chama transparência.
	 *
	 * Por isso o texto passa a dizer **quando** o número sai, e o link continua:
	 * ele leva à enquete inteira, com as alternativas, a janela e as encerradas ao
	 * lado. Quem administra a enquete vê o parcial lá e recebe o rótulo direto.
	 *
	 * Quem decide é `Reconectar_Proposta_Votacao::pode_ver_resultado()`, a mesma
	 * chamada que o painel usa — o cartão e o painel têm de contar a mesma
	 * história, e duplicar a regra aqui abriria espaço para uma divergir da outra
	 * na próxima mudança.
	 *
	 * A URL pode vir vazia — o painel é uma página do WordPress, e página se
	 * despublica. Nesse caso nada é impresso, em vez de um link que levaria ao 404
	 * de quem não está logado enquanto o editor, que está, vê tudo certo.
	 *
	 * @param int $enquete_id ID da enquete.
	 * @return void
	 */
	private static function imprimir_link_do_painel( $enquete_id ) {
		/*
		 * Na própria página do painel o link levaria de volta para ela, e o cartão
		 * já cobre parte do gráfico que o leitor veio ver — convidá-lo a ir aonde
		 * está seria dizer que falta algo à tela.
		 */
		if ( Reconectar_Painel_Transparencia::esta_na_pagina() ) {
			return;
		}

		$url = Reconectar_Painel_Transparencia::url();

		if ( '' === $url ) {
			return;
		}

		$rotulo = Reconectar_Proposta_Votacao::pode_ver_resultado( $enquete_id )
			? __( 'Ver o resultado no painel de transparência', 'reconectar-core' )
			: __( 'O resultado sai no painel de transparência quando a enquete encerrar', 'reconectar-core' );

		printf(
			'<p class="rc-enquete__resultado"><a class="rc-enquete__link" href="%s">%s</a></p>',
			esc_url( $url ),
			esc_html( $rotulo )
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
