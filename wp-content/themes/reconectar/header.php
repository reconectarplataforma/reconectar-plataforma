<?php
/**
 * Cabeçalho do site.
 *
 * Este template substitui o `header.php` do tema pai, mas **mantém a mesma
 * cadeia de wrappers** (`#page` → `#masthead` → `#content` → `.col-full`) e as
 * actions estruturais do Storefront. Não é apego ao pai: templates do
 * WooCommerce e do Dokan, além de boa parte do CSS já escrito, posicionam-se a
 * partir desses contêineres. Trocar os nomes agora quebraria as páginas de
 * loja, carrinho e painel do vendedor sem ganho nenhum de layout.
 *
 * O que **não** é disparado aqui é a action `storefront_header`: é nela que o
 * Storefront pendura o próprio logotipo, menu, busca e carrinho. Dispará-la
 * imprimiria um segundo cabeçalho, do tema pai, logo abaixo deste. As demais
 * (`storefront_before_content`, por exemplo, que carrega o breadcrumb do
 * WooCommerce) continuam de pé, porque são pontos de extensão de posição, não
 * de conteúdo.
 *
 * @package reconectar
 */

?><!doctype html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo( 'charset' ); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1">
<?php wp_head(); ?>
</head>

<body <?php body_class(); ?>>

<?php wp_body_open(); ?>

<?php do_action( 'storefront_before_site' ); ?>

<div id="page" class="hfeed site">
	<?php do_action( 'storefront_before_header' ); ?>

	<a class="skip-link screen-reader-text" href="#content">
		<?php esc_html_e( 'Pular para o conteúdo', 'reconectar' ); ?>
	</a>

	<header id="masthead" class="site-header rc-cabecalho" role="banner">
		<div class="rc-cabecalho__topo">
			<div class="rc-cabecalho__interno">
				<div class="rc-cabecalho__marca">
					<?php if ( has_custom_logo() ) : ?>
						<?php the_custom_logo(); ?>
					<?php else : ?>
						<a class="rc-cabecalho__nome" href="<?php echo esc_url( home_url( '/' ) ); ?>" rel="home">
							<?php bloginfo( 'name' ); ?>
						</a>
					<?php endif; ?>
				</div>

				<?php reconectar_campo_de_busca(); ?>

				<div class="rc-cabecalho__acoes">
					<?php
					reconectar_seletor_de_municipio();
					reconectar_atalho_de_conta();
					reconectar_resumo_do_carrinho();
					?>
				</div>
			</div>
		</div>

		<?php if ( has_nav_menu( 'primary' ) ) : ?>
			<?php
			/*
			 * O menu colapsa em telas estreitas com `<details>`, e não com um
			 * botão + JavaScript: abrir e fechar já é comportamento nativo do
			 * elemento, com teclado e leitor de tela incluídos.
			 *
			 * Sai fechado de propósito. Em telas largas o CSS esconde o
			 * `<summary>` e força a lista a aparecer mesmo com o `<details>`
			 * fechado — o atributo `open` no HTML abriria também no celular,
			 * que é exatamente o que o colapso existe para evitar.
			 */
			?>
			<details class="rc-menu">
				<summary class="rc-menu__gatilho">
					<span class="rc-menu__gatilho-icone" aria-hidden="true"></span>
					<span class="rc-menu__gatilho-rotulo"><?php esc_html_e( 'Menu', 'reconectar' ); ?></span>
				</summary>

				<nav class="rc-menu__nav" aria-label="<?php esc_attr_e( 'Navegação principal', 'reconectar' ); ?>">
					<div class="rc-cabecalho__interno">
						<?php
						wp_nav_menu(
							array(
								'theme_location' => 'primary',
								'container'      => false,
								'menu_class'     => 'rc-menu__lista',
								'depth'          => 2,
								'fallback_cb'    => false,
							)
						);
						?>
					</div>
				</nav>
			</details>
		<?php endif; ?>
	</header><!-- #masthead -->

	<?php
	/**
	 * Funções enganchadas em `storefront_before_content`.
	 *
	 * @hooked woocommerce_breadcrumb - 10
	 */
	do_action( 'storefront_before_content' );
	?>

	<div id="content" class="site-content" tabindex="-1">
		<div class="col-full">

		<?php do_action( 'storefront_content_top' ); ?>
