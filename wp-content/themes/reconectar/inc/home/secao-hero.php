<?php
/**
 * Seção "hero" da home.
 *
 * Primeiro bloco da página inicial: apresenta a plataforma e leva à loja.
 */

defined( 'ABSPATH' ) || exit;

/**
 * Renderiza o hero institucional.
 */
function reconectar_home_hero() {
	?>
	<section class="reconectar-hero">
		<div class="container">
			<div class="row justify-content-center text-center">
				<div class="col-lg-8">
					<h1 class="reconectar-hero__titulo"><?php esc_html_e( 'Reconectar — Incubadora Digital para Vínculos e Negócios', 'reconectar' ); ?></h1>
					<p class="reconectar-hero__subtitulo"><?php esc_html_e( 'Uma plataforma colaborativa que conecta pessoas, negócios locais e iniciativas de comunidade.', 'reconectar' ); ?></p>
					<a class="btn btn-lg reconectar-hero__cta" href="<?php echo esc_url( reconectar_url_loja() ); ?>">
						<?php esc_html_e( 'Ver produtos', 'reconectar' ); ?>
					</a>
				</div>
			</div>
		</div>
	</section>
	<?php
}
add_action( 'reconectar_home', 'reconectar_home_hero', 10 );
