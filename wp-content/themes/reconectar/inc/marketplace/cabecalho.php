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
					/*
					 * Número e unidade em elementos separados, e não um `_n( '%s
					 * itens' )` montado numa string só, porque o cabeçalho do celular
					 * exibe apenas a contagem: a palavra é escondida por CSS e
					 * continua na árvore de acessibilidade, já que "3" sozinho não é
					 * nome de link.
					 *
					 * O preço é a ordem fixa — um idioma que ponha a unidade antes do
					 * número não tem como invertê-la pela tradução. É o que a
					 * separação custa, e cabe a quem traduzir para um desses idiomas
					 * reabrir a decisão.
					 */
					?>
					<span class="rc-carrinho__quantidade"><?php echo esc_html( number_format_i18n( $itens ) ); ?></span>
					<span class="rc-carrinho__unidade"><?php echo esc_html( _n( 'item', 'itens', $itens, 'reconectar' ) ); ?></span>
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
 *
 * A lupa é o botão de envio, e não um enfeite ao lado de um botão escrito
 * "Buscar": o rótulo textual consumia cerca de 60px da largura útil do campo —
 * num componente que no celular ocupa a linha inteira, esse é o espaço de mais
 * três ou quatro caracteres visíveis.
 *
 * O markup de `combobox` só ganha sentido com `assets/js/busca.js` carregado.
 * Sem ele — JS desligado, bloqueado por extensão, arquivo que não chegou — o
 * que sobra é o formulário de sempre: Enter e clique na lupa submetem para a
 * página de resultados. A lista de sugestões nasce vazia e assim permanece.
 */
function reconectar_campo_de_busca() {
	/*
	 * Os rótulos do painel viajam em JSON no markup em vez de morarem no JS: o
	 * tema não carrega `wp-i18n`, e string cravada em arquivo `.js` fica fora do
	 * alcance do `.pot` — invisível para quem traduzir a plataforma depois.
	 */
	$textos = array(
		'produtos' => __( 'Produtos', 'reconectar' ),
		'lojas'    => __( 'Lojas', 'reconectar' ),
		/* translators: %s: termo digitado. */
		'vazio'    => __( 'Nada encontrado para “%s”.', 'reconectar' ),
		/* translators: %s: termo digitado. */
		'todos'    => __( 'Ver todos os resultados para “%s”', 'reconectar' ),
		'nenhuma'  => __( 'Nenhuma sugestão.', 'reconectar' ),
		'uma'      => __( '1 sugestão disponível.', 'reconectar' ),
		/* translators: %d: quantidade de sugestões. */
		'varias'   => __( '%d sugestões disponíveis.', 'reconectar' ),
	);
	?>
	<form
		class="rc-busca"
		role="search"
		method="get"
		action="<?php echo esc_url( home_url( '/' ) ); ?>"
		data-rc-busca
		data-rc-busca-sugestoes="<?php echo esc_url( rest_url( 'reconectar/v1/sugestoes' ) ); ?>"
		data-rc-busca-textos="<?php echo esc_attr( wp_json_encode( $textos ) ); ?>"
	>
		<label class="screen-reader-text" for="rc-busca-campo">
			<?php esc_html_e( 'Buscar produtos e lojas', 'reconectar' ); ?>
		</label>

		<button type="submit" class="rc-busca__enviar" aria-label="<?php esc_attr_e( 'Buscar', 'reconectar' ); ?>">
			<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" focusable="false" aria-hidden="true">
				<circle cx="11" cy="11" r="7"></circle>
				<path d="m20 20-3.5-3.5"></path>
			</svg>
		</button>

		<?php
		/*
		 * `autocomplete="off"` porque o histórico do navegador desenha sua
		 * própria lista por cima da nossa, e as duas juntas viram uma pilha de
		 * sugestões que ninguém sabe operar.
		 */
		?>
		<input
			type="search"
			id="rc-busca-campo"
			class="rc-busca__campo"
			name="s"
			value="<?php echo esc_attr( get_search_query() ); ?>"
			placeholder="<?php esc_attr_e( 'Busque por item ou loja', 'reconectar' ); ?>"
			autocomplete="off"
			role="combobox"
			aria-expanded="false"
			aria-controls="rc-busca-sugestoes"
			aria-autocomplete="list"
			data-rc-busca-campo
		/>

		<input type="hidden" name="post_type" value="product" />

		<ul class="rc-busca__sugestoes" id="rc-busca-sugestoes" role="listbox" hidden
			aria-label="<?php esc_attr_e( 'Sugestões de produtos e lojas', 'reconectar' ); ?>"
			data-rc-busca-lista></ul>

		<?php
		/*
		 * O painel aparece sem nenhum aviso para quem não o vê: esta região é o
		 * que anuncia quantas sugestões surgiram.
		 */
		?>
		<span class="screen-reader-text" role="status" aria-live="polite" data-rc-busca-aviso></span>
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
 * Quem não está autenticado vê "Entrar", que leva a "Minha conta": a tela de
 * login e de cadastro com a cara da plataforma, no lugar do `wp-login.php` do
 * núcleo, que não tem o caminho de criar conta de comprador. Sem `redirect_to`,
 * porque para o cliente ele já não valia: o Dokan manda todo cliente para "Minha
 * conta" depois do login (veja `reconectar_login_volta_ao_checkout()`).
 */
function reconectar_atalho_de_conta() {
	if ( ! is_user_logged_in() ) {
		$destino = wc_get_page_permalink( 'myaccount' );
		$rotulo  = __( 'Entrar', 'reconectar' );
	} elseif ( class_exists( 'Reconectar_Painel_Empresas' )
		&& current_user_can( Reconectar_Permissoes::CAP_PAINEL_EMPRESAS )
		&& '' !== Reconectar_Painel_Empresas::url() ) {
		// Antes do ramo do vendedor: o Administrador de Empresas não é vendedor,
		// e mandá-lo para "Minha conta" esconderia justamente a única área que ele
		// administra.
		$destino = Reconectar_Painel_Empresas::url();
		$rotulo  = __( 'Painel de Empresas', 'reconectar' );
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
