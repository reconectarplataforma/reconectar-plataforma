<?php
/**
 * Peças do cabeçalho do marketplace.
 *
 * Busca, seletor de município, atalho de conta e resumo do carrinho. Ficam
 * separadas do `header.php` porque o resumo do carrinho precisa ser reimpresso
 * pelo WooCommerce via AJAX (sem recarregar a página) e, para isso, tem de ser
 * uma função chamável — não um trecho solto dentro do template.
 *
 * @package reconectar
 */

defined( 'ABSPATH' ) || exit;

/**
 * Registra o resumo do carrinho como fragmento AJAX do WooCommerce.
 *
 * Sem isto, adicionar um produto pela vitrine atualizaria o carrinho no servidor
 * mas deixaria o cabeçalho mostrando "R$ 0,00 / 0 itens" até a próxima recarga —
 * e o cliente concluiria que o clique não funcionou e clicaria de novo.
 */
add_filter( 'woocommerce_add_to_cart_fragments', 'reconectar_fragmento_do_carrinho' );

/**
 * Devolve o HTML atualizado do resumo do carrinho.
 *
 * A chave do array é o seletor CSS que o WooCommerce vai substituir na página.
 *
 * @param array $fragmentos Fragmentos registrados por outros componentes.
 * @return array
 */
function reconectar_fragmento_do_carrinho( $fragmentos ) {
	ob_start();
	reconectar_resumo_do_carrinho();
	$fragmentos['div.rc-carrinho'] = ob_get_clean();

	return $fragmentos;
}

/**
 * Imprime o resumo do carrinho do cabeçalho.
 *
 * O elemento externo precisa ser exatamente `div.rc-carrinho`: é o seletor
 * declarado no fragmento acima, e o WooCommerce troca o nó inteiro por este
 * mesmo HTML. Mudar a tag ou a classe aqui sem mudar lá quebra a atualização
 * silenciosamente — o carrinho simplesmente para de se atualizar.
 */
function reconectar_resumo_do_carrinho() {
	$total  = '';
	$itens  = 0;
	$objeto = function_exists( 'WC' ) ? WC()->cart : null;

	// O carrinho não existe no admin nem em requisições REST: `WC()->cart` é
	// nulo nesses contextos, e chamar métodos nele derrubaria a página.
	if ( $objeto ) {
		$total = $objeto->get_cart_subtotal();
		$itens = $objeto->get_cart_contents_count();
	}
	?>
	<div class="rc-carrinho">
		<a class="rc-carrinho__link" href="<?php echo esc_url( wc_get_cart_url() ); ?>">
			<span class="rc-carrinho__icone" aria-hidden="true">
				<svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" focusable="false">
					<circle cx="9" cy="21" r="1"></circle>
					<circle cx="20" cy="21" r="1"></circle>
					<path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6"></path>
				</svg>
			</span>

			<span class="rc-carrinho__texto">
				<span class="rc-carrinho__total"><?php echo wp_kses_post( $total ); ?></span>
				<span class="rc-carrinho__itens">
					<?php
					printf(
						/* translators: %s: quantidade de itens no carrinho. */
						esc_html( _n( '%s item', '%s itens', $itens, 'reconectar' ) ),
						esc_html( number_format_i18n( $itens ) )
					);
					?>
				</span>
			</span>
		</a>
	</div>
	<?php
}

/**
 * Imprime o campo de busca do cabeçalho.
 *
 * Busca restrita a `post_type=product`: quem digita na barra de um marketplace
 * quer encontrar o que comprar, não uma página institucional. As páginas
 * continuam encontráveis pela busca padrão do WordPress.
 */
function reconectar_campo_de_busca() {
	?>
	<form class="rc-busca" role="search" method="get" action="<?php echo esc_url( home_url( '/' ) ); ?>">
		<label class="screen-reader-text" for="rc-busca-campo">
			<?php esc_html_e( 'Buscar produtos e lojas', 'reconectar' ); ?>
		</label>

		<span class="rc-busca__icone" aria-hidden="true">
			<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" focusable="false">
				<circle cx="11" cy="11" r="7"></circle>
				<path d="m20 20-3.5-3.5"></path>
			</svg>
		</span>

		<input
			type="search"
			id="rc-busca-campo"
			class="rc-busca__campo"
			name="s"
			value="<?php echo esc_attr( get_search_query() ); ?>"
			placeholder="<?php esc_attr_e( 'Busque por item ou loja', 'reconectar' ); ?>"
		/>

		<input type="hidden" name="post_type" value="product" />

		<button type="submit" class="rc-busca__enviar">
			<?php esc_html_e( 'Buscar', 'reconectar' ); ?>
		</button>
	</form>
	<?php
}

/**
 * Imprime o seletor de município.
 *
 * É um `<details>` com uma lista de links, e não um `<select>` com JavaScript:
 * abre, fecha e navega sem script nenhum, e cada opção é uma URL de verdade.
 *
 * A lista vem dos municípios que **têm loja cadastrada**
 * (`reconectar_obter_cidades()`), então escolher uma opção nunca leva a uma
 * vitrine vazia. Quando não há loja alguma, o seletor não é impresso.
 */
function reconectar_seletor_de_municipio() {
	$cidades = reconectar_obter_cidades();

	if ( ! $cidades ) {
		return;
	}

	$ativos = reconectar_filtros_ativos();
	$atual  = $ativos['cidade'] ? $ativos['cidade'] : __( 'Todos os municípios', 'reconectar' );
	?>
	<details class="rc-municipio">
		<summary class="rc-municipio__gatilho">
			<span class="rc-municipio__icone" aria-hidden="true">
				<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" focusable="false">
					<path d="M12 21s7-5.5 7-11a7 7 0 1 0-14 0c0 5.5 7 11 7 11z"></path>
					<circle cx="12" cy="10" r="2.5"></circle>
				</svg>
			</span>

			<span class="rc-municipio__texto">
				<span class="rc-municipio__rotulo"><?php esc_html_e( 'Entregando em', 'reconectar' ); ?></span>
				<span class="rc-municipio__valor"><?php echo esc_html( $atual ); ?></span>
			</span>
		</summary>

		<ul class="rc-municipio__lista">
			<li>
				<a class="rc-municipio__opcao" href="<?php echo esc_url( reconectar_url_de_filtro( 'cidade', null ) ); ?>">
					<?php esc_html_e( 'Todos os municípios', 'reconectar' ); ?>
				</a>
			</li>

			<?php foreach ( $cidades as $cidade ) : ?>
				<li>
					<a
						class="rc-municipio__opcao"
						href="<?php echo esc_url( reconectar_url_de_filtro( 'cidade', $cidade ) ); ?>"
						<?php echo sanitize_title( $cidade ) === sanitize_title( $ativos['cidade'] ) ? ' aria-current="true"' : ''; ?>
					>
						<?php echo esc_html( $cidade ); ?>
					</a>
				</li>
			<?php endforeach; ?>
		</ul>
	</details>
	<?php
}

/**
 * Imprime o atalho de conta do cabeçalho.
 *
 * O destino depende de quem está olhando, porque "minha conta" significa coisas
 * diferentes para cada ator da plataforma: o vendedor gerencia a loja, o cliente
 * acompanha pedidos. Mandar os dois para a mesma tela obrigaria o vendedor a
 * navegar até o painel a cada acesso.
 *
 * Quem não está autenticado vê "Entrar", com o retorno para a página atual.
 */
function reconectar_atalho_de_conta() {
	if ( ! is_user_logged_in() ) {
		$destino = wp_login_url( home_url( add_query_arg( array() ) ) );
		$rotulo  = __( 'Entrar', 'reconectar' );
	} elseif ( function_exists( 'dokan_is_user_seller' ) && dokan_is_user_seller( get_current_user_id() ) ) {
		$destino = function_exists( 'dokan_get_navigation_url' ) ? dokan_get_navigation_url() : home_url( '/' );
		$rotulo  = __( 'Minha loja', 'reconectar' );
	} else {
		$destino = wc_get_page_permalink( 'myaccount' );
		$rotulo  = __( 'Minha conta', 'reconectar' );
	}
	?>
	<a class="rc-conta" href="<?php echo esc_url( $destino ); ?>">
		<span class="rc-conta__icone" aria-hidden="true">
			<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" focusable="false">
				<circle cx="12" cy="8" r="4"></circle>
				<path d="M4 21a8 8 0 0 1 16 0"></path>
			</svg>
		</span>
		<span class="rc-conta__rotulo"><?php echo esc_html( $rotulo ); ?></span>
	</a>
	<?php
}
