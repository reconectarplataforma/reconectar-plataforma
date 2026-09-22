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
