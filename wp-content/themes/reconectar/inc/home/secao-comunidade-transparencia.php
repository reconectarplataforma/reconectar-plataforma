<?php
/**
 * Seção institucional de Comunidade e Transparência da home.
 *
 * Não é seção de e-commerce: são as duas frentes exigidas pelo edital
 * (participação da comunidade e transparência das decisões), por isso a seção
 * permanece mesmo quando não há produto nenhum cadastrado.
 */

defined( 'ABSPATH' ) || exit;

/**
 * Renderiza os cards de Comunidade e Transparência.
 *
 * Cada card só aparece se a página correspondente existir, e a seção inteira é
 * omitida quando nenhuma das duas existe — evita renderizar um bloco vazio em
 * uma instalação recém-provisionada.
 */
function reconectar_home_comunidade_transparencia() {
	$comunidade    = get_page_by_path( 'comunidade' );
	$transparencia = get_page_by_path( 'transparencia' );

	if ( ! $comunidade && ! $transparencia ) {
		return;
	}
	?>
	<section class="reconectar-home-comunidade-transparencia">
		<div class="container">
			<div class="row row-cols-1 row-cols-md-2 g-3">
				<?php if ( $comunidade ) : ?>
					<div class="col">
						<a class="card h-100 text-decoration-none" href="<?php echo esc_url( get_permalink( $comunidade ) ); ?>">
							<div class="card-body">
								<h3 class="card-title h5"><?php esc_html_e( 'Comunidade', 'reconectar' ); ?></h3>
								<p class="card-text"><?php esc_html_e( 'Participe da rede de pessoas e negócios conectados pela plataforma.', 'reconectar' ); ?></p>
							</div>
						</a>
					</div>
				<?php endif; ?>
				<?php if ( $transparencia ) : ?>
					<div class="col">
						<a class="card h-100 text-decoration-none" href="<?php echo esc_url( get_permalink( $transparencia ) ); ?>">
							<div class="card-body">
								<h3 class="card-title h5"><?php esc_html_e( 'Transparência', 'reconectar' ); ?></h3>
								<p class="card-text"><?php esc_html_e( 'Acompanhe as propostas de votação e as decisões coletivas da plataforma.', 'reconectar' ); ?></p>
							</div>
						</a>
					</div>
				<?php endif; ?>
			</div>
		</div>
	</section>
	<?php
}
add_action( 'reconectar_home', 'reconectar_home_comunidade_transparencia', 50 );
