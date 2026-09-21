<?php
/**
 * Funções do tema Reconectar (child theme do Storefront).
 */

defined( 'ABSPATH' ) || exit;

function reconectar_enqueue_assets() {
	$theme_uri = get_stylesheet_directory_uri();
	$theme_dir = get_stylesheet_directory();

	wp_enqueue_style(
		'storefront-parent-style',
		get_template_directory_uri() . '/style.css'
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
