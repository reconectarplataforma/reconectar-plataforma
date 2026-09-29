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
	 * Registra o shortcode.
	 *
	 * @return void
	 */
	public static function init() {
		add_shortcode( 'reconectar_painel_transparencia', array( __CLASS__, 'renderizar' ) );
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
								<p class="reconectar-proposta__sem-votos">
									<?php esc_html_e( 'Nenhum voto ainda.', 'reconectar-core' ); ?>
								</p>
							<?php else : ?>
								<ul class="reconectar-proposta__opcoes list-unstyled mt-3">
									<?php
									foreach ( $opcoes as $opcao ) :
										$votos      = isset( $contagem[ $opcao['id'] ] ) ? (int) $contagem[ $opcao['id'] ] : 0;
										$percentual = Reconectar_Proposta_Votacao::percentual( $votos, $total );
										?>
										<li class="reconectar-proposta__opcao">
											<span class="reconectar-proposta__rotulo">
												<?php echo esc_html( $opcao['texto'] ); ?>
											</span>
											<?php
											/*
											 * A barra é decorativa: o mesmo número já está no texto ao
											 * lado, então ela leva `aria-hidden`. Um `role="progressbar"`
											 * aqui faria o leitor de tela anunciar o percentual duas
											 * vezes seguidas.
											 */
											?>
											<span class="reconectar-proposta__barra" aria-hidden="true">
												<span class="reconectar-proposta__preenchimento" style="width: <?php echo esc_attr( round( $percentual, 1 ) ); ?>%"></span>
											</span>
											<span class="reconectar-proposta__numero">
												<?php
												printf(
													/* translators: 1: percentual. 2: número de votos. */
													esc_html__( '%1$s%% (%2$d)', 'reconectar-core' ),
													esc_html( number_format_i18n( $percentual, 1 ) ),
													(int) $votos
												);
												?>
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
