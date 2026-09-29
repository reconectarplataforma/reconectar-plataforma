<?php
/**
 * Shortcode `[reconectar_painel_transparencia]` — mostra publicamente as
 * enquetes e o resultado de cada alternativa.
 *
 * O painel é o arquivo das enquetes — alcança também as encerradas — e desde
 * esta versão também vota, ao lado do componente flutuante.
 *
 * **O gráfico só é público depois do encerramento.** Enquanto a enquete aceita
 * voto, o leitor comum vê as alternativas e a cédula, nunca o placar: um parcial
 * à vista no momento da escolha convida a acompanhar a maioria, e é a mesma
 * razão pela qual o percentual já havia saído do cartão flutuante. Quem
 * administra a enquete vê o parcial em tempo real, com o aviso de que aquele
 * número ainda não é público.
 *
 * Uma versão anterior deste arquivo justificava o contrário — que aqui o placar
 * **era** o conteúdo da página e por isso podia acompanhar o voto. A regra
 * mudou por decisão do projeto, e o argumento antigo tem um furo que vale
 * registrar para ninguém o refazer: o painel continua sendo o lugar do
 * resultado, só que no tempo certo. Ele não fica vazio no meio disso — a
 * participação aparece, e as enquetes encerradas seguem com o gráfico inteiro.
 *
 * O que decide é `Reconectar_Proposta_Votacao::pode_ver_resultado()`, num lugar
 * só: o cartão flutuante e o painel precisam contar a mesma história, e um link
 * que promete resultado onde não há se lê como defeito.
 *
 * O que ainda depende da definição participativa das regras da plataforma
 * (Atividade 2.10 do edital) é o voto anônimo e a regra de uma-vez-por-
 * beneficiário. O que existe hoje é um voto por conta logada.
 *
 * @package Reconectar_Core
 */

defined( 'ABSPATH' ) || exit;

/**
 * Publica o resultado das enquetes.
 */
class Reconectar_Painel_Transparencia {

	/**
	 * Registra o shortcode e a folha do gráfico.
	 *
	 * @return void
	 */
	public static function init() {
		add_shortcode( 'reconectar_painel_transparencia', array( __CLASS__, 'renderizar' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enfileirar_assets' ) );
	}

	/**
	 * Enfileira o CSS do gráfico, e só onde o painel existe.
	 *
	 * A guarda não é economia de bytes: sem ela, toda página do site carregaria a
	 * folha de um componente que não desenha em lugar nenhum. É a mesma decisão já
	 * tomada no componente flutuante de enquete.
	 *
	 * `is_singular()` antes do `get_post()` porque em arquivo e em busca o segundo
	 * devolve o primeiro post do laço — o conteúdo de um item da lista, que nada
	 * tem a ver com a página pedida.
	 *
	 * @return void
	 */
	public static function enfileirar_assets() {
		if ( ! is_singular() ) {
			return;
		}

		$pagina = get_post();

		if ( ! $pagina instanceof WP_Post || ! has_shortcode( $pagina->post_content, 'reconectar_painel_transparencia' ) ) {
			return;
		}

		wp_enqueue_style(
			'reconectar-transparencia',
			RECONECTAR_CORE_URL . 'assets/css/transparencia.css',
			array(),
			'0.3.0'
		);
	}

	/**
	 * URL da página que publica o painel.
	 *
	 * O componente flutuante deixou de imprimir percentual — ver o placar ao lado
	 * do próprio voto convida a trocá-lo para acompanhar a maioria. Quem quiser o
	 * resultado vem para cá, e é esta função que leva. Ela vive no painel, e não
	 * no componente, porque quem sabe onde a tela mora é a tela.
	 *
	 * A guarda de `post_status` não é zelo: `get_page_by_path()` devolve rascunho
	 * e lixeira sem reclamar, e um link para página despublicada leva ao 404 de
	 * quem não está logado — enquanto o editor, que está, vê tudo certo.
	 *
	 * @return string URL do painel, ou vazio se a página não existir publicada.
	 */
	public static function url() {
		$pagina = get_page_by_path( 'transparencia' );

		if ( ! $pagina || 'publish' !== $pagina->post_status ) {
			return '';
		}

		return (string) get_permalink( $pagina );
	}

	/**
	 * Se a tela sendo servida é a que publica o painel.
	 *
	 * O componente flutuante aparece em todas as telas, inclusive nesta — e aqui
	 * o convite "ver o resultado no painel de transparência" apontaria para a
	 * página em que o leitor já está, por cima do gráfico que ele veio ver.
	 *
	 * @return bool
	 */
	public static function esta_na_pagina() {
		$pagina = get_page_by_path( 'transparencia' );

		return $pagina instanceof WP_Post && is_page( $pagina->ID );
	}

	/**
	 * Desenha o painel.
	 *
	 * @return string HTML do painel.
	 */
	public static function renderizar() {
		$enquetes = get_posts(
			array(
				'post_type'      => Reconectar_Proposta_Votacao::POST_TYPE,
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'orderby'        => 'date',
				'order'          => 'DESC',
			)
		);

		if ( empty( $enquetes ) ) {
			return '<p class="reconectar-painel-vazio">' .
				esc_html__( 'Nenhuma enquete publicada no momento.', 'reconectar-core' ) .
				'</p>';
		}

		ob_start();
		?>
		<div class="reconectar-painel-transparencia row row-cols-1 row-cols-md-2 g-3">
			<?php
			foreach ( $enquetes as $enquete ) :
				$opcoes   = Reconectar_Proposta_Votacao::opcoes( $enquete->ID );
				$contagem = Reconectar_Proposta_Votacao::contagem( $enquete->ID );
				$total    = Reconectar_Proposta_Votacao::total_de_votos( $enquete->ID );
				$aberta   = Reconectar_Proposta_Votacao::esta_aberta( $enquete->ID );
				$escolha  = Reconectar_Proposta_Votacao::voto_de( $enquete->ID );
				$ja_votou = '' !== $escolha;

				/*
				 * `pode_votar()` e não `$aberta && is_user_logged_in()` escrito à mão: a
				 * regra de quem vota mora num lugar só, e ela também confere o
				 * `post_status`. Duplicá-la aqui faria o painel oferecer o formulário
				 * para quem o endpoint recusaria — botão que existe e não funciona.
				 */
				$votavel = Reconectar_Proposta_Votacao::pode_votar( $enquete->ID );
				$ancora  = 'enquete-' . (int) $enquete->ID;

				/*
				 * Aberta, a enquete só mostra o placar a quem a administra. A regra
				 * inteira mora no método — aqui fica apenas a pergunta, para que mudar o
				 * critério não exija caçar condições espalhadas pelo template.
				 */
				$ver_resultado = Reconectar_Proposta_Votacao::pode_ver_resultado( $enquete->ID );
				$parcial       = $ver_resultado && $aberta;
				?>
				<div class="col">
					<article class="reconectar-proposta card h-100" id="<?php echo esc_attr( $ancora ); ?>">
						<div class="card-body">
							<h3 class="reconectar-proposta__titulo card-title h5">
								<?php echo esc_html( get_the_title( $enquete ) ); ?>
							</h3>
							<div class="reconectar-proposta__resumo card-text">
								<?php echo wp_kses_post( get_the_excerpt( $enquete ) ); ?>
							</div>

							<?php if ( empty( $opcoes ) ) : ?>
								<p class="reconectar-proposta__vazia">
									<?php esc_html_e( 'Esta enquete ainda não tem alternativas cadastradas.', 'reconectar-core' ); ?>
								</p>
							<?php else : ?>
								<?php
								/*
								 * Uma lista só, que serve de gráfico e de cédula. Enquanto o voto
								 * ficou fora daqui, havia três ramos — sem alternativa, sem voto,
								 * com voto —, e o do meio repetia a lista inteira só para imprimir
								 * texto puro no lugar das barras. Repetir de novo, agora com radios
								 * em cada cópia, garantiria que uma delas ficasse para trás na
								 * próxima mudança.
								 *
								 * O que varia por estado é o que a linha **acrescenta**: o radio só
								 * quando a enquete aceita o voto deste leitor, o valor e a barra só
								 * quando há voto a mostrar.
								 */
								if ( $votavel ) {
									printf(
										'<form class="rc-grafico-votos__form" method="post" action="%s">',
										esc_url( admin_url( 'admin-post.php' ) )
									);

									printf(
										'<input type="hidden" name="action" value="%s">',
										esc_attr( Reconectar_Proposta_Votacao::ACAO_VOTAR )
									);

									printf( '<input type="hidden" name="enquete" value="%d">', (int) $enquete->ID );

									/*
									 * O fragmento não viaja na requisição — nem na URL do POST, nem
									 * no `Referer` —, então ele vai declarado. Sem este campo o
									 * leitor volta ao topo de uma página de vários cartões e não vê
									 * o efeito do próprio voto, o que se lê como falha.
									 */
									printf( '<input type="hidden" name="ancora" value="%s">', esc_attr( $ancora ) );

									wp_nonce_field( Reconectar_Proposta_Votacao::ACAO_VOTAR . '_' . $enquete->ID );
								}
								?>
								<ul class="rc-grafico-votos reconectar-proposta__opcoes list-unstyled mt-3<?php echo $votavel ? ' rc-grafico-votos--votavel' : ''; ?>">
									<?php
									foreach ( $opcoes as $indice => $opcao ) :
										$votos      = isset( $contagem[ $opcao['id'] ] ) ? (int) $contagem[ $opcao['id'] ] : 0;
										$percentual = Reconectar_Proposta_Votacao::percentual( $votos, $total );
										$campo      = $ancora . '-op-' . (int) $indice;
										?>
										<li class="rc-grafico-votos__linha reconectar-proposta__opcao">
											<?php if ( $votavel ) : ?>
												<input
													type="radio"
													class="rc-grafico-votos__radio"
													id="<?php echo esc_attr( $campo ); ?>"
													name="opcao"
													value="<?php echo esc_attr( $opcao['id'] ); ?>"
													<?php checked( $escolha, $opcao['id'] ); ?>
												>
												<label class="rc-grafico-votos__rotulo reconectar-proposta__rotulo" for="<?php echo esc_attr( $campo ); ?>">
													<?php echo esc_html( $opcao['texto'] ); ?>
												</label>
											<?php else : ?>
												<span class="rc-grafico-votos__rotulo reconectar-proposta__rotulo">
													<?php echo esc_html( $opcao['texto'] ); ?>
												</span>
											<?php endif; ?>

											<?php
											/*
											 * Total zero não imprime percentual nem barra: o cálculo
											 * exigiria dividir por zero, e um `0%` no lugar seria o
											 * número plausível que a honestidade de dados proíbe. O
											 * aviso de "nenhum voto ainda" vem abaixo da lista, uma vez
											 * só, em vez de repetido em cada alternativa.
											 *
											 * As classes `rc-grafico-votos__*` são novas e as
											 * `reconectar-proposta__*` ficam ao lado porque são o nome
											 * público do painel desde o começo — remover uma delas
											 * quebraria personalização feita por fora.
											 *
											 * A ordem é a cadastrada pelo moderador, nunca a do placar.
											 * Reordenar por votos poria a mais votada sempre no topo, e
											 * aqui, com o radio ao lado, isso não seria só leitura
											 * enviesada: seria a alternativa líder no caminho do clique.
											 *
											 * `$ver_resultado` guarda as duas saídas juntas, e não só a
											 * barra: o texto "40,0% (2 votos)" é o mesmo dado, e esconder
											 * o desenho deixando o número ao lado não esconderia nada.
											 */
											?>
											<?php if ( $ver_resultado && $total > 0 ) : ?>
												<span class="rc-grafico-votos__valor reconectar-proposta__numero">
													<?php
													echo esc_html(
														sprintf(
															/* translators: 1: percentual. 2: número de votos. */
															_n( '%1$s%% (%2$s voto)', '%1$s%% (%2$s votos)', $votos, 'reconectar-core' ),
															number_format_i18n( $percentual, 1 ),
															number_format_i18n( $votos )
														)
													);
													?>
												</span>
												<?php
												/*
												 * A barra é decorativa: o mesmo número já está no texto
												 * ao lado, então ela leva `aria-hidden`. Um
												 * `role="progressbar"` aqui faria o leitor de tela
												 * anunciar o percentual duas vezes seguidas.
												 *
												 * A trilha e a barra são as duas que **precisavam** de
												 * CSS e nunca tiveram: `width` inline num `<span>` não
												 * desenha nada, porque a propriedade não se aplica a
												 * caixa inline não substituída. Medido na página antes da
												 * correção: `width: 0`, fundo transparente. É
												 * `transparencia.css` que as põe em `display: block` —
												 * sem ela o markup volta a ser texto.
												 */
												?>
												<span class="rc-grafico-votos__trilha reconectar-proposta__barra" aria-hidden="true">
													<span class="rc-grafico-votos__barra reconectar-proposta__preenchimento" style="width: <?php echo esc_attr( round( $percentual, 1 ) ); ?>%"></span>
												</span>
											<?php endif; ?>
										</li>
									<?php endforeach; ?>
								</ul>

								<?php
								/*
								 * O aviso de reserva existe para que a ausência do placar se leia
								 * como regra e não como tela quebrada: o painel se chama
								 * transparência, e sumir com o dado sem dizer por quê é o oposto
								 * do nome.
								 *
								 * Ele **substitui** o "nenhum voto ainda" em vez de somar-se a
								 * ele — com o resultado fechado, dizer que ninguém votou já seria
								 * placar. O de parcial, ao contrário, convive: quem administra vê
								 * o gráfico e precisa saber das duas coisas, que não é público e
								 * que ainda está vazio.
								 */
								?>
								<?php if ( ! $ver_resultado ) : ?>
									<p class="rc-grafico-votos__reserva">
										<?php esc_html_e( 'O resultado é publicado aqui quando a enquete encerrar. Até lá ele fica fechado, para que o voto de ninguém seja levado pelo de outro.', 'reconectar-core' ); ?>
									</p>
								<?php else : ?>
									<?php if ( $total < 1 ) : ?>
										<p class="rc-grafico-votos__vazio reconectar-proposta__sem-votos">
											<?php esc_html_e( 'Nenhum voto ainda.', 'reconectar-core' ); ?>
										</p>
									<?php endif; ?>
									<?php if ( $parcial ) : ?>
										<p class="rc-grafico-votos__parcial">
											<?php esc_html_e( 'Resultado parcial: enquanto a enquete estiver aberta, só quem a administra vê estes números.', 'reconectar-core' ); ?>
										</p>
									<?php endif; ?>
								<?php endif; ?>

								<?php if ( $votavel ) : ?>
									<?php if ( $ja_votou ) : ?>
										<p class="rc-grafico-votos__confirmacao">
											<?php esc_html_e( 'Seu voto está marcado acima.', 'reconectar-core' ); ?>
										</p>
									<?php endif; ?>
									<button type="submit" class="rc-grafico-votos__enviar">
										<?php
										echo esc_html(
											$ja_votou
												? __( 'Alterar meu voto', 'reconectar-core' )
												: __( 'Votar', 'reconectar-core' )
										);
										?>
									</button>
									</form>
								<?php elseif ( $aberta ) : ?>
									<?php
									/*
									 * Enquete aberta e ninguém logado: o convite, nunca o silêncio.
									 * Esconder a enquete de quem não entrou tiraria justamente o
									 * motivo de entrar.
									 *
									 * O retorno vem de `home_url( add_query_arg( array() ) )` e não do
									 * `HTTP_HOST` cru — cabeçalho de requisição é dado do cliente, e a
									 * allowlist de host desta instalação existe porque um `Host`
									 * forjado sairia dentro de um link. A âncora entra aqui porque
									 * este link é GET: aqui o fragmento viaja.
									 */
									$retorno = home_url( add_query_arg( array() ) ) . '#' . $ancora;
									?>
									<p class="rc-grafico-votos__convite">
										<a class="rc-grafico-votos__login" href="<?php echo esc_url( wp_login_url( $retorno ) ); ?>">
											<?php esc_html_e( 'Entre na sua conta para votar', 'reconectar-core' ); ?>
										</a>
									</p>
								<?php endif; ?>
							<?php endif; ?>

							<?php
							/*
							 * O total sobrevive à reserva do resultado de propósito, e a
							 * distinção não é sutileza: ele conta **participação**, não
							 * preferência. Saber que 40 pessoas já votaram não inclina ninguém a
							 * uma alternativa — é o placar por alternativa que faz isso —, e é o
							 * único sinal de vida que a enquete aberta dá a quem chega. Sem ele,
							 * uma consulta movimentada e uma abandonada ficam idênticas na tela.
							 */
							?>
							<p class="reconectar-proposta__placar">
								<span class="reconectar-proposta__votos-total badge text-bg-secondary">
									<?php
									printf(
										/* translators: %d: total de votos registrados. */
										esc_html__( 'Total de votos: %d', 'reconectar-core' ),
										(int) $total
									);
									?>
								</span>
								<?php if ( $aberta ) : ?>
									<span class="reconectar-proposta__situacao badge text-bg-success">
										<?php esc_html_e( 'Aberta para voto', 'reconectar-core' ); ?>
									</span>
								<?php else : ?>
									<span class="reconectar-proposta__situacao badge text-bg-secondary">
										<?php esc_html_e( 'Encerrada', 'reconectar-core' ); ?>
									</span>
								<?php endif; ?>
							</p>
						</div>
					</article>
				</div>
			<?php endforeach; ?>
		</div>
		<?php
		return ob_get_clean();
	}
}
