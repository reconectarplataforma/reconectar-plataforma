<?php
/**
 * Funções do tema Reconectar (child theme do Storefront).
 */

defined( 'ABSPATH' ) || exit;

function reconectar_enqueue_assets() {
	$theme_uri = get_stylesheet_directory_uri();
	$theme_dir = get_stylesheet_directory();

	wp_enqueue_style(
		'reconectar-bootstrap',
		$theme_uri . '/assets/bootstrap/bootstrap.min.css',
		array(),
		'5.3.3'
	);

	wp_enqueue_style(
		'storefront-parent-style',
		get_template_directory_uri() . '/style.css',
		array( 'reconectar-bootstrap' )
	);

	wp_enqueue_style(
		'reconectar-fonts',
		$theme_uri . '/assets/css/fonts.css',
		array(),
		filemtime( $theme_dir . '/assets/css/fonts.css' )
	);

	wp_enqueue_style(
		'reconectar-style',
		$theme_uri . '/style.css',
		array( 'storefront-parent-style', 'reconectar-fonts' ),
		filemtime( $theme_dir . '/style.css' )
	);

	wp_enqueue_script(
		'reconectar-bootstrap',
		$theme_uri . '/assets/bootstrap/bootstrap.bundle.min.js',
		array(),
		'5.3.3',
		true
	);
}
add_action( 'wp_enqueue_scripts', 'reconectar_enqueue_assets' );

function reconectar_theme_support() {
	add_theme_support(
		'custom-logo',
		array(
			'height'      => 60,
			'width'       => 240,
			'flex-height' => true,
			'flex-width'  => true,
		)
	);
}
add_action( 'after_setup_theme', 'reconectar_theme_support', 20 );

/**
 * Sobrescreve a navegação primária do Storefront (protegida por
 * function_exists() no tema pai) com uma navbar Bootstrap responsiva,
 * que colapsa em um botão hambúrguer nas telas pequenas.
 */
function storefront_primary_navigation() {
	?>
	<nav id="site-navigation" class="main-navigation reconectar-navbar navbar navbar-expand-lg" role="navigation" aria-label="<?php esc_attr_e( 'Primary Navigation', 'storefront' ); ?>">
		<div class="container-fluid px-0">
			<button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#reconectar-primary-menu" aria-controls="reconectar-primary-menu" aria-expanded="false" aria-label="<?php esc_attr_e( 'Alternar navegação', 'reconectar' ); ?>">
				<span class="navbar-toggler-icon"></span>
			</button>
			<div class="collapse navbar-collapse" id="reconectar-primary-menu">
				<?php
				wp_nav_menu(
					array(
						'theme_location' => 'primary',
						'container'      => false,
						'menu_class'     => 'navbar-nav me-auto mb-2 mb-lg-0',
						'items_wrap'     => '<ul id="%1$s" class="%2$s">%3$s</ul>',
						'fallback_cb'    => false,
					)
				);
				?>
			</div>
		</div>
	</nav><!-- #site-navigation -->
	<?php
}

/**
 * Adiciona as classes Bootstrap (nav-item / nav-link) aos itens do menu
 * primário, sem precisar de um Walker completo.
 */
function reconectar_nav_menu_css_class( $classes, $item, $args ) {
	if ( isset( $args->theme_location ) && 'primary' === $args->theme_location ) {
		$classes[] = 'nav-item';
	}
	return $classes;
}
add_filter( 'nav_menu_css_class', 'reconectar_nav_menu_css_class', 10, 3 );

function reconectar_nav_menu_link_attributes( $atts, $item, $args ) {
	if ( isset( $args->theme_location ) && 'primary' === $args->theme_location ) {
		$atts['class'] = isset( $atts['class'] ) ? trim( $atts['class'] . ' nav-link' ) : 'nav-link';
	}
	return $atts;
}
add_filter( 'nav_menu_link_attributes', 'reconectar_nav_menu_link_attributes', 10, 3 );

/**
 * Home institucional — Task 9.
 *
 * A home passa a usar `front-page.php`, que reaproveita a mesma action
 * `homepage` do template-homepage.php nativo do Storefront. Removemos os
 * hooks de produtos que não fazem parte do escopo escolhido (recentes,
 * destaque, populares, em promoção, mais vendidos) e o conteúdo de página
 * (não há page associada à home), mantendo apenas as categorias de
 * produtos. A remoção precisa ocorrer depois que o Storefront já registrou
 * esses hooks (feito em `after_setup_theme`/`init` do tema pai), por isso
 * usamos `init` com prioridade tardia.
 */
function reconectar_ajustar_hooks_homepage() {
	remove_action( 'homepage', 'storefront_homepage_content', 10 );
	remove_action( 'homepage', 'storefront_recent_products', 30 );
	remove_action( 'homepage', 'storefront_featured_products', 40 );
	remove_action( 'homepage', 'storefront_popular_products', 50 );
	remove_action( 'homepage', 'storefront_on_sale_products', 60 );
	remove_action( 'homepage', 'storefront_best_selling_products', 70 );
	// "Shop by Brand" é registrado condicionalmente pelo Storefront quando a
	// classe WC_Brands existe (marcas nativas do WooCommerce). Não faz parte
	// do escopo de seções aprovado para esta home (hero, categorias,
	// comunidade/transparência), por isso também é removido aqui.
	remove_action( 'homepage', 'storefront_woocommerce_brands_homepage_section', 80 );
}
add_action( 'init', 'reconectar_ajustar_hooks_homepage', 20 );

function reconectar_homepage_hero() {
	?>
	<section class="reconectar-hero">
		<div class="container">
			<div class="row justify-content-center text-center">
				<div class="col-lg-8">
					<h1 class="reconectar-hero__titulo"><?php esc_html_e( 'Reconectar — Incubadora Digital para Vínculos e Negócios', 'reconectar' ); ?></h1>
					<p class="reconectar-hero__subtitulo"><?php esc_html_e( 'Uma plataforma colaborativa que conecta pessoas, negócios locais e iniciativas de comunidade.', 'reconectar' ); ?></p>
					<a class="btn btn-lg reconectar-hero__cta" href="<?php echo esc_url( wc_get_page_permalink( 'shop' ) ); ?>">
						<?php esc_html_e( 'Ver produtos', 'reconectar' ); ?>
					</a>
				</div>
			</div>
		</div>
	</section>
	<?php
}
add_action( 'homepage', 'reconectar_homepage_hero', 5 );

function reconectar_homepage_comunidade_transparencia() {
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
add_action( 'homepage', 'reconectar_homepage_comunidade_transparencia', 25 );
