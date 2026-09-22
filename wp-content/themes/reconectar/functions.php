<?php
/**
 * Funções do tema Reconectar.
 *
 * Este arquivo não implementa nada: ele apenas carrega os módulos de `inc/`.
 * A lista é explícita (e não um `glob()`) por dois motivos: a ordem de
 * carregamento fica previsível — `setup.php` antes de quem depende dele — e um
 * arquivo novo só passa a valer quando for deliberadamente registrado aqui,
 * evitando que um rascunho esquecido em `inc/` entre em produção sozinho.
 */

defined( 'ABSPATH' ) || exit;

$reconectar_modulos = array(
	'inc/helpers.php',
	'inc/setup.php',
	'inc/enqueue.php',

	/*
	 * Código que só existe para conviver com o tema pai Storefront. Está
	 * isolado em um único arquivo para que a migração para o Blocksy seja a
	 * remoção deste require e do arquivo — e não uma caça a trechos espalhados.
	 */
	'inc/legado-storefront.php',

	// Seções da home. Cada arquivo se registra sozinho na action
	// `reconectar_home`, com a prioridade que define sua posição na página.
	'inc/home/secao-hero.php',
	'inc/home/secao-categorias.php',
	'inc/home/secao-comunidade-transparencia.php',
);

foreach ( $reconectar_modulos as $reconectar_modulo ) {
	$reconectar_caminho = get_stylesheet_directory() . '/' . $reconectar_modulo;

	if ( file_exists( $reconectar_caminho ) ) {
		require_once $reconectar_caminho;
	}
}

unset( $reconectar_modulos, $reconectar_modulo, $reconectar_caminho );
