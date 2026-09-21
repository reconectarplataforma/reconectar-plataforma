<?php
/**
 * Shortcode [reconectar_painel_transparencia] — exibe publicamente as
 * propostas de votação e o placar atual de votos a favor/contra.
 *
 * MVP/esqueleto: exibição somente leitura. O mecanismo de votação em si
 * (formulário, autenticação do beneficiário, regra de um voto por
 * pessoa) ainda depende da definição participativa das regras de
 * funcionamento da plataforma (Atividade 2.10 do edital).
 */

defined( 'ABSPATH' ) || exit;

class Reconectar_Painel_Transparencia {

	public static function init() {
		add_shortcode( 'reconectar_painel_transparencia', array( __CLASS__, 'renderizar' ) );
	}

	public static function renderizar() {
		$propostas = get_posts(
			array(
				'post_type'      => Reconectar_Proposta_Votacao::POST_TYPE,
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'orderby'        => 'date',
				'order'          => 'DESC',
			)
		);

		if ( empty( $propostas ) ) {
			return '<p class="reconectar-painel-vazio">' .
				esc_html__( 'Nenhuma proposta de votação publicada no momento.', 'reconectar-core' ) .
				'</p>';
		}

		ob_start();
		?>
		<div class="reconectar-painel-transparencia">
			<?php foreach ( $propostas as $proposta ) :
				$votos_favor  = (int) get_post_meta( $proposta->ID, '_reconectar_votos_favor', true );
				$votos_contra = (int) get_post_meta( $proposta->ID, '_reconectar_votos_contra', true );
				$total_votos  = $votos_favor + $votos_contra;
				?>
				<article class="reconectar-proposta" id="proposta-<?php echo esc_attr( $proposta->ID ); ?>">
					<h3 class="reconectar-proposta__titulo">
						<?php echo esc_html( get_the_title( $proposta ) ); ?>
					</h3>
					<div class="reconectar-proposta__resumo">
						<?php echo wp_kses_post( get_the_excerpt( $proposta ) ); ?>
					</div>
					<div class="reconectar-proposta__placar">
						<span class="reconectar-proposta__votos-favor">
							<?php
							printf(
								/* translators: %d: número de votos a favor */
								esc_html__( 'A favor: %d', 'reconectar-core' ),
								$votos_favor
							);
							?>
						</span>
						<span class="reconectar-proposta__votos-contra">
							<?php
							printf(
								/* translators: %d: número de votos contra */
								esc_html__( 'Contra: %d', 'reconectar-core' ),
								$votos_contra
							);
							?>
						</span>
						<span class="reconectar-proposta__votos-total">
							<?php
							printf(
								/* translators: %d: total de votos registrados */
								esc_html__( 'Total de votos: %d', 'reconectar-core' ),
								$total_votos
							);
							?>
						</span>
					</div>
				</article>
			<?php endforeach; ?>
		</div>
		<?php
		return ob_get_clean();
	}
}
