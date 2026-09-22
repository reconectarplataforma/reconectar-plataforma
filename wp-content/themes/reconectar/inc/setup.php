<?php
/**
 * Registro dos recursos suportados pelo tema.
 */

defined( 'ABSPATH' ) || exit;

/**
 * Declara o suporte a logotipo personalizado.
 *
 * Roda em prioridade 20 para acontecer depois do `after_setup_theme` do tema
 * pai, garantindo que estas dimensões prevaleçam sobre as dele.
 */
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
