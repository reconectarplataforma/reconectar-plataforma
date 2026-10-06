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
	'inc/layout.php',

	/*
	 * Marketplace. A ordem importa: `consultas.php` define as funções de dados
	 * que os componentes e o cabeçalho consomem, e `filtros.php` define as
	 * constantes de paginação que a vitrine usa.
	 */
	'inc/marketplace/consultas.php',
	'inc/marketplace/componentes.php',
	// Depois de `componentes.php`: a listagem reaproveita
	// `reconectar_card_categoria()`.
	'inc/marketplace/categorias.php',
	'inc/marketplace/filtros.php',
	'inc/marketplace/vitrine.php',
	'inc/marketplace/busca-sugestoes.php',
	'inc/marketplace/loja.php',
	'inc/marketplace/produto.php',
	// Depois de `consultas.php`: a coluna de lojas lê `reconectar_obter_lojas()`.
	'inc/marketplace/catalogo.php',
	'inc/marketplace/checkout.php',
	'inc/marketplace/so-com-login.php',
	'inc/marketplace/conta.php',
	'inc/marketplace/cabecalho.php',
	'inc/marketplace/rodape.php',
	'inc/marketplace/barra-inferior.php',
	'inc/marketplace/painel-da-loja.php',

	// Seções da home. Cada arquivo se registra sozinho na action
	// `reconectar_home`, com a prioridade que define sua posição na página.
	'inc/home/secao-hero.php',
	'inc/home/secao-campanhas.php',
	'inc/home/secao-categorias.php',
	'inc/home/secao-lojas-destaque.php',
	'inc/home/secao-ofertas.php',
	'inc/home/secao-lojas.php',
	'inc/home/secao-comunidade-transparencia.php',
	// O fórum vem depois da home porque `listagem.php` e `sidebar.php` dependem
	// das funções de `consultas.php`, e esta lista é a ordem de carga.
	'inc/forum/consultas.php',
	'inc/forum/componentes.php',
	'inc/forum/listagem.php',
	'inc/forum/sidebar.php',
);

foreach ( $reconectar_modulos as $reconectar_modulo ) {
	$reconectar_caminho = get_stylesheet_directory() . '/' . $reconectar_modulo;

	if ( file_exists( $reconectar_caminho ) ) {
		require_once $reconectar_caminho;
	}
}

unset( $reconectar_modulos, $reconectar_modulo, $reconectar_caminho );
