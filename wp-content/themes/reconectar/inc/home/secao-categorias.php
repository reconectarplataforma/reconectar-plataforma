<?php
/**
 * Seção de categorias de produtos da home.
 *
 * Substitui `storefront_product_categories()`, do tema pai. A troca não é
 * cosmética: aquela função monta a seção com o shortcode `[product_categories]`
 * e envolve tudo em `.storefront-product-section`, ou seja, o markup e as
 * classes da nossa home ficavam sob controle do tema pai. Com a consulta
 * própria aqui, a seção sobrevive à troca do tema pai sem alteração.
 */

defined( 'ABSPATH' ) || exit;

/**
 * Renderiza as categorias de produtos.
 *
 * Retorna sem imprimir nada quando o WooCommerce está inativo ou quando não há
 * nenhuma categoria com produtos — preferível a exibir um título de seção
 * seguido de espaço em branco.
 */
function reconectar_home_categorias() {
	if ( ! taxonomy_exists( 'product_cat' ) ) {
		return;
	}

	$categorias = get_terms(
		array(
			'taxonomy'   => 'product_cat',
			'hide_empty' => true,
			'number'     => 12,
			'orderby'    => 'count',
			'order'      => 'DESC',
		)
	);

	if ( is_wp_error( $categorias ) || empty( $categorias ) ) {
		return;
	}
	?>
	<section class="reconectar-home-categorias">
		<div class="container">
			<h2 class="reconectar-home-categorias__titulo"><?php esc_html_e( 'Categorias', 'reconectar' ); ?></h2>
			<ul class="reconectar-home-categorias__lista row row-cols-2 row-cols-md-4 row-cols-lg-6 g-3 list-unstyled">
				<?php foreach ( $categorias as $categoria ) : ?>
					<li class="col">
						<a class="reconectar-home-categorias__item text-decoration-none" href="<?php echo esc_url( get_term_link( $categoria ) ); ?>">
							<?php
							$thumbnail_id = get_term_meta( $categoria->term_id, 'thumbnail_id', true );

							if ( $thumbnail_id ) {
								echo wp_get_attachment_image(
									$thumbnail_id,
									'woocommerce_thumbnail',
									false,
									array(
										'class' => 'reconectar-home-categorias__imagem',
										'alt'   => '',
									)
								);
							}
							?>
							<span class="reconectar-home-categorias__nome"><?php echo esc_html( $categoria->name ); ?></span>
						</a>
					</li>
				<?php endforeach; ?>
			</ul>
		</div>
	</section>
	<?php
}
add_action( 'reconectar_home', 'reconectar_home_categorias', 20 );
