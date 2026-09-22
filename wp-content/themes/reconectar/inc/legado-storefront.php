<?php
/**
 * Código acoplado ao tema pai Storefront.
 *
 * Tudo neste arquivo existe apenas para conviver com o Storefront e sai em
 * bloco quando o tema pai for trocado. Está reunido aqui — em vez de espalhado
 * por `enqueue.php` e `setup.php` — justamente para que essa remoção seja
 * apagar um arquivo e um `require`, sem varredura por trechos esquecidos.
 *
 * O que mora aqui e por quê:
 *
 * - `storefront_primary_navigation()` sobrescreve uma função *pluggable* do
 *   Storefront. O truque funciona porque o `functions.php` do tema filho é
 *   carregado antes do pai: quando o Storefront chega no seu
 *   `if ( ! function_exists( ... ) )`, a nossa versão já está declarada. Temas
 *   sem funções pluggable simplesmente nunca chamam esta função — ela não
 *   quebra nada, apenas deixa de existir para efeitos práticos.
 * - Os dois filtros `nav_menu_*` só servem para injetar as classes do Bootstrap
 *   (`nav-item` / `nav-link`) nos itens dessa navbar, evitando um Walker inteiro.
 * - O JavaScript do Bootstrap é carregado exclusivamente por causa do
 *   `data-bs-toggle` do botão hambúrguer abaixo — a única ocorrência de
 *   `data-bs-` em todo o código autoral do projeto. O CSS do Bootstrap, esse
 *   sim, é usado em toda parte (inclusive pelo plugin `reconectar-core`) e não
 *   pertence a este arquivo.
 */

defined( 'ABSPATH' ) || exit;

/**
 * Enfileira o bundle JS do Bootstrap, necessário para o menu colapsável.
 */
function reconectar_legado_enqueue_bootstrap_js() {
	wp_enqueue_script(
		'reconectar-bootstrap',
		get_stylesheet_directory_uri() . '/assets/bootstrap/bootstrap.bundle.min.js',
		array(),
		'5.3.3',
		true
	);
}
add_action( 'wp_enqueue_scripts', 'reconectar_legado_enqueue_bootstrap_js' );

/**
 * Substitui a navegação primária do Storefront por uma navbar Bootstrap
 * responsiva, que colapsa em um botão hambúrguer nas telas pequenas.
 */
function storefront_primary_navigation() {
	?>
	<nav id="site-navigation" class="main-navigation reconectar-navbar navbar navbar-expand-lg" role="navigation" aria-label="<?php esc_attr_e( 'Navegação principal', 'reconectar' ); ?>">
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
 * Adiciona a classe `nav-item` aos <li> do menu primário.
 */
function reconectar_nav_menu_css_class( $classes, $item, $args ) {
	if ( isset( $args->theme_location ) && 'primary' === $args->theme_location ) {
		$classes[] = 'nav-item';
	}
	return $classes;
}
add_filter( 'nav_menu_css_class', 'reconectar_nav_menu_css_class', 10, 3 );

/**
 * Adiciona a classe `nav-link` aos <a> do menu primário.
 */
function reconectar_nav_menu_link_attributes( $atts, $item, $args ) {
	if ( isset( $args->theme_location ) && 'primary' === $args->theme_location ) {
		$atts['class'] = isset( $atts['class'] ) ? trim( $atts['class'] . ' nav-link' ) : 'nav-link';
	}
	return $atts;
}
add_filter( 'nav_menu_link_attributes', 'reconectar_nav_menu_link_attributes', 10, 3 );
