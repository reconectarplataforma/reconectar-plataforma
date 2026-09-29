<?php
/**
 * Shortcode `[reconectar_painel_transparencia]` — mostra publicamente as
 * enquetes e o resultado de cada alternativa.
 *
 * Somente leitura, e de propósito: votar é no componente flutuante, que aparece
 * em todas as telas enquanto a enquete estiver aberta. Aqui o papel é de
 * arquivo, e alcança também as encerradas.
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
			'0.1.0'
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
				?>
				<div class="col">
					<article class="reconectar-proposta card h-100" id="enquete-<?php echo esc_attr( $enquete->ID ); ?>">
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
							<?php elseif ( $total < 1 ) : ?>
								<?php
								/*
								 * Total zero imprime texto, nunca `0%`: o percentual exigiria
								 * dividir por zero, e um zero inventado no lugar seria o número
								 * plausível que a regra de honestidade de dados proíbe.
								 */
								?>
								<ul class="reconectar-proposta__opcoes list-unstyled mt-3">
									<?php foreach ( $opcoes as $opcao ) : ?>
										<li class="reconectar-proposta__opcao">
											<?php echo esc_html( $opcao['texto'] ); ?>
										</li>
									<?php endforeach; ?>
								</ul>
								<p class="rc-grafico-votos__vazio reconectar-proposta__sem-votos">
									<?php esc_html_e( 'Nenhum voto ainda.', 'reconectar-core' ); ?>
								</p>
							<?php else : ?>
								<?php
								/*
								 * O gráfico. As classes `rc-grafico-votos__*` são novas, e as
								 * `reconectar-proposta__*` ficam ao lado porque são o nome público
								 * do painel desde o começo — remover uma delas quebraria qualquer
								 * personalização feita por fora.
								 *
								 * A trilha e a barra são as duas que **precisavam** de CSS e nunca
								 * tiveram: `width` inline num `<span>` não desenha nada, porque a
								 * propriedade não se aplica a caixa inline não substituída. Medido
								 * na página antes da correção: `width: 0`, fundo transparente. A
								 * folha `transparencia.css` as põe em `display: block`, e é ela que
								 * faz o gráfico existir — sem ela o markup volta a ser texto.
								 *
								 * A ordem é a cadastrada pelo moderador, nunca a do placar.
								 * Reordenar por votos poria a mais votada sempre no topo, que é a
								 * mesma indução ao voto de maioria que tirou o percentual do cartão
								 * flutuante.
								 */
								?>
								<ul class="rc-grafico-votos reconectar-proposta__opcoes list-unstyled mt-3">
									<?php
									foreach ( $opcoes as $opcao ) :
										$votos      = isset( $contagem[ $opcao['id'] ] ) ? (int) $contagem[ $opcao['id'] ] : 0;
										$percentual = Reconectar_Proposta_Votacao::percentual( $votos, $total );
										?>
										<li class="rc-grafico-votos__linha reconectar-proposta__opcao">
											<span class="rc-grafico-votos__rotulo reconectar-proposta__rotulo">
												<?php echo esc_html( $opcao['texto'] ); ?>
											</span>
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
											 * acima, então ela leva `aria-hidden`. Um `role="progressbar"`
											 * aqui faria o leitor de tela anunciar o percentual duas
											 * vezes seguidas.
											 */
											?>
											<span class="rc-grafico-votos__trilha reconectar-proposta__barra" aria-hidden="true">
												<span class="rc-grafico-votos__barra reconectar-proposta__preenchimento" style="width: <?php echo esc_attr( round( $percentual, 1 ) ); ?>%"></span>
											</span>
										</li>
									<?php endforeach; ?>
								</ul>
							<?php endif; ?>

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
