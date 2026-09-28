<?php
/**
 * Seção "hero" da home.
 *
 * Bloco institucional: apresenta a plataforma e leva à loja.
 *
 * Fica perto do fim da página, e não no topo, por decisão de layout: a home
 * abre pela vitrine — categorias, lojas e produtos —, deixando a apresentação
 * para quem rolou até o fim sem encontrar o que procurava.
 */

defined( 'ABSPATH' ) || exit;

/**
 * Renderiza o hero institucional.
 *
 * O `<h1>` da home mora aqui. Com o hero deslocado para o fim, ele passa a vir
 * **depois** dos `<h2>` das quatro seções de vitrine — quem navega por
 * cabeçalhos chega ao título da página no meio do caminho. É consequência
 * conhecida da ordem escolhida, não descuido: se a hierarquia precisar voltar a
 * subir, o caminho é dar à home um `<h1>` próprio no topo e rebaixar este a
 * `<h2>`, nunca reordenar as seções por conta disso.
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
add_action( 'reconectar_home', 'reconectar_home_hero', 50 );
