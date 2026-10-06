<?php
/**
 * Página "Produtos": o catálogo inteiro, com filtros numa coluna lateral.
 *
 * É a página da loja do WooCommerce (`/shop/` aqui, `/loja/` em produção), e
 * não uma página nova. Uma segunda listagem de todos os produtos teria de
 * reimplementar o que o laço do WooCommerce já faz certo — paginação, ordenação,
 * contagem de resultados, a exclusão de produto oculto pela visibilidade — e as
 * duas divergiriam no primeiro ajuste feito em uma só.
 *
 * Os filtros seguem a regra da vitrine de lojas (`filtros.php`): são **links**,
 * cada um com a query string ajustada. Estado compartilhável, "voltar" que
 * desfaz a escolha, e nada depende de JavaScript. O único formulário é o da
 * faixa de preço, que não cabe numa lista fechada de opções.
 *
 * Os parâmetros são próprios — `categoria`, `loja`, `oferta` —, e não as query
 * vars do WordPress. `?product_cat=` transformaria a requisição num arquivo de
 * taxonomia, `is_shop()` passaria a responder falso e a coluna sumiria no
 * primeiro clique. Ordenação e preço, ao contrário, usam os nomes do
 * WooCommerce (`orderby`, `min_price`, `max_price`): é ele quem os aplica, e o
 * seletor de ordenação acima da grade continua falando a mesma língua da coluna.
 *
 * @package reconectar
 */

defined( 'ABSPATH' ) || exit;

/**
 * Parâmetros de URL que a coluna de filtros conhece e preserva entre cliques.
 *
 * `s` e `post_type` entram para que filtrar o resultado de uma busca não
 * descarte o termo buscado. `paged` fica de fora de propósito: trocar de filtro
 * refaz a lista, e manter a página 3 levaria a uma página que talvez não exista.
 */
const RECONECTAR_CATALOGO_PARAMETROS = array( 'categoria', 'loja', 'oferta', 'orderby', 'min_price', 'max_price', 's', 'post_type' );

/**
 * Diz se a requisição corrente é a página "Produtos".
 *
 * @return bool
 */
function reconectar_catalogo_e_a_pagina() {
	return function_exists( 'is_shop' ) && is_shop();
}

/**
 * Lojas que aparecem como filtro: as ativas que têm produto publicado.
 *
 * Loja sem produto fica de fora porque o filtro levaria a uma lista vazia — um
 * clique que responde "nada encontrado" sobre uma loja que existe é lido como
 * defeito, não como catálogo vazio.
 *
 * @return array[] Cada item com `id`, `nome` e `slug`, em ordem alfabética.
 */
function reconectar_catalogo_lojas() {
	static $lojas = null;

	if ( null !== $lojas ) {
		return $lojas;
	}

	$lojas = array();

	foreach ( reconectar_obter_lojas( array( 'numero' => 200, 'ordenar' => 'nome' ) ) as $loja ) {
		$usuario = get_userdata( $loja['id'] );

		if ( ! $usuario || ! count_user_posts( $loja['id'], 'product', true ) ) {
			continue;
		}

		$lojas[] = array(
			'id'   => (int) $loja['id'],
			'nome' => $loja['nome'],
			'slug' => $usuario->user_nicename,
		);
	}

	return $lojas;
}

/**
 * Lê os filtros ativos da query string, já validados.
 *
 * Categoria e loja são conferidas contra o que existe: um slug inventado na URL
 * volta a "todas" em vez de chegar à consulta e devolver uma lista vazia sem
 * explicação. A loja, em particular, só é aceita se estiver na lista de
 * `reconectar_catalogo_lojas()` — o parâmetro vira `author` na consulta, e não
 * há razão para ele alcançar outro usuário que não uma loja.
 *
 * @return array{categoria: ?WP_Term, loja: ?array, oferta: bool, orderby: string, min_price: string, max_price: string}
 */
function reconectar_catalogo_filtros_ativos() {
	static $ativos = null;

	if ( null !== $ativos ) {
		return $ativos;
	}

	// Leitura de filtro de navegação pública, sem efeito colateral: não há o que
	// um nonce protegeria, e exigir um quebraria os links compartilháveis.
	// phpcs:disable WordPress.Security.NonceVerification.Recommended
	$slug_categoria = isset( $_GET['categoria'] ) ? sanitize_title( wp_unslash( $_GET['categoria'] ) ) : '';
	$slug_loja      = isset( $_GET['loja'] ) ? sanitize_title( wp_unslash( $_GET['loja'] ) ) : '';
	$oferta         = isset( $_GET['oferta'] ) && '1' === sanitize_key( wp_unslash( $_GET['oferta'] ) );
	$orderby        = isset( $_GET['orderby'] ) ? sanitize_key( wp_unslash( $_GET['orderby'] ) ) : '';
	$min_price      = isset( $_GET['min_price'] ) ? wc_format_decimal( wp_unslash( $_GET['min_price'] ) ) : '';
	$max_price      = isset( $_GET['max_price'] ) ? wc_format_decimal( wp_unslash( $_GET['max_price'] ) ) : '';
	// phpcs:enable WordPress.Security.NonceVerification.Recommended

	$categoria = $slug_categoria ? get_term_by( 'slug', $slug_categoria, 'product_cat' ) : null;
	$loja      = null;

	foreach ( $slug_loja ? reconectar_catalogo_lojas() : array() as $candidata ) {
		if ( $candidata['slug'] === $slug_loja ) {
			$loja = $candidata;
			break;
		}
	}

	$ativos = array(
		'categoria' => $categoria instanceof WP_Term ? $categoria : null,
		'loja'      => $loja,
		'oferta'    => $oferta,
		'orderby'   => $orderby,
		'min_price' => $min_price,
		'max_price' => $max_price,
	);

	return $ativos;
}

/**
 * Aplica categoria, loja e oferta à consulta principal da página "Produtos".
 *
 * `woocommerce_product_query` e não `pre_get_posts` solto: é o gancho em que o
 * WooCommerce já reconheceu a consulta do catálogo e montou a dele, e é ele que
 * aplica ordenação e faixa de preço logo em seguida, sobre o mesmo objeto.
 *
 * "Em oferta" vai por `post__in` porque o preço promocional é meta com data de
 * início e fim, e `wc_get_product_ids_on_sale()` já resolve as datas e as
 * variações. Sem nenhum produto em oferta, a lista precisa sair vazia — um
 * `post__in` vazio é ignorado pelo `WP_Query`, e o filtro devolveria o catálogo
 * inteiro como se fossem todos ofertas.
 *
 * @param WP_Query $consulta Consulta principal do catálogo.
 * @return void
 */
function reconectar_catalogo_filtrar_consulta( $consulta ) {
	if ( ! $consulta->is_main_query() || ! $consulta->is_post_type_archive( 'product' ) ) {
		return;
	}

	$ativos = reconectar_catalogo_filtros_ativos();

	if ( $ativos['categoria'] ) {
		$tax_query   = (array) $consulta->get( 'tax_query' );
		$tax_query[] = array(
			'taxonomy'         => 'product_cat',
			'field'            => 'term_id',
			'terms'            => array( $ativos['categoria']->term_id ),
			'include_children' => true,
		);
		$consulta->set( 'tax_query', $tax_query );
	}

	if ( $ativos['loja'] ) {
		$consulta->set( 'author', $ativos['loja']['id'] );
	}

	if ( $ativos['oferta'] ) {
		$em_oferta = wc_get_product_ids_on_sale();
		$consulta->set( 'post__in', $em_oferta ? $em_oferta : array( 0 ) );
	}
}
add_action( 'woocommerce_product_query', 'reconectar_catalogo_filtrar_consulta' );

/**
 * URL da página "Produtos" com um parâmetro trocado e os demais preservados.
 *
 * @param array<string, string|null> $trocas Parâmetros a definir; `null` ou `''` remove.
 * @return string
 */
function reconectar_catalogo_url( $trocas = array() ) {
	$parametros = array_intersect_key(
		wp_unslash( $_GET ), // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- leitura de filtro público, ver `reconectar_catalogo_filtros_ativos()`.
		array_flip( RECONECTAR_CATALOGO_PARAMETROS )
	);
	$parametros = array_filter( array_map( 'strval', array_filter( $parametros, 'is_scalar' ) ), 'strlen' );

	foreach ( $trocas as $chave => $valor ) {
		if ( null === $valor || '' === $valor ) {
			unset( $parametros[ $chave ] );
		} else {
			$parametros[ $chave ] = $valor;
		}
	}

	$base = reconectar_url_loja();

	return $parametros ? add_query_arg( array_map( 'rawurlencode', $parametros ), $base ) : $base;
}

/**
 * Quantos filtros estão aplicados, para o rótulo do botão no celular.
 *
 * A ordenação não conta: ela muda a ordem, não o que aparece.
 *
 * @return int
 */
function reconectar_catalogo_quantos_filtros() {
	$ativos = reconectar_catalogo_filtros_ativos();

	return (int) (bool) $ativos['categoria']
		+ (int) (bool) $ativos['loja']
		+ (int) $ativos['oferta']
		+ (int) ( '' !== $ativos['min_price'] || '' !== $ativos['max_price'] );
}

/**
 * Remove a barra lateral do tema pai da página "Produtos".
 *
 * A página passa a ter a própria coluna, à esquerda. A `sidebar-1` hoje está
 * vazia e `get_sidebar()` não imprime nada — mas um widget posto lá pelo painel
 * daria à página uma terceira coluna, à direita, comprimindo a grade. É a mesma
 * proteção de `reconectar_ajustar_classes_de_layout()`, pelo lado do markup.
 *
 * Em `template_redirect`, e não no corpo do arquivo: o `functions.php` do filho
 * carrega antes do pai, e o `remove_action` ali não acharia o que remover.
 *
 * @return void
 */
function reconectar_catalogo_sem_barra_do_tema_pai() {
	if ( reconectar_catalogo_e_a_pagina() ) {
		remove_action( 'woocommerce_sidebar', 'storefront_get_sidebar', 10 );
	}
}
add_action( 'template_redirect', 'reconectar_catalogo_sem_barra_do_tema_pai' );

/**
 * Abre a grade de duas colunas e imprime a coluna de filtros.
 *
 * Em `woocommerce_before_main_content`, e não em `woocommerce_before_shop_loop`:
 * este segundo não dispara quando a consulta volta vazia — o
 * `archive-product.php` cai em `woocommerce_no_products_found` —, e a coluna
 * sumiria exatamente quando o cliente precisa dela para desfazer o filtro.
 * Prioridade 30 para abrir depois do `#primary` do Storefront, que entra na 10.
 *
 * @return void
 */
function reconectar_catalogo_abrir() {
	if ( ! reconectar_catalogo_e_a_pagina() ) {
		return;
	}

	echo '<div class="rc-catalogo">';
	reconectar_catalogo_coluna_de_filtros();
	echo '<div class="rc-catalogo__principal">';
}
add_action( 'woocommerce_before_main_content', 'reconectar_catalogo_abrir', 30 );

/**
 * Fecha a grade aberta por `reconectar_catalogo_abrir()`.
 *
 * Prioridade 5, antes do `storefront_after_content` (10), que fecha o
 * `#primary`: fechar depois dele cruzaria as tags.
 *
 * @return void
 */
function reconectar_catalogo_fechar() {
	if ( reconectar_catalogo_e_a_pagina() ) {
		echo '</div></div>';
	}
}
add_action( 'woocommerce_after_main_content', 'reconectar_catalogo_fechar', 5 );

/**
 * Imprime a coluna de filtros.
 *
 * Um `<details>` **fechado** no HTML: no celular a coluna inteira empurraria a
 * grade para baixo de uma tela de opções, e o cliente nem veria um produto. No
 * desktop o `marketplace.js` abre o painel e esconde o `<summary>`; sem
 * JavaScript, o desktop mostra o mesmo botão "Filtrar" do celular, o que é pior
 * de usar e nunca esconde nada.
 *
 * @return void
 */
function reconectar_catalogo_coluna_de_filtros() {
	$ativos   = reconectar_catalogo_filtros_ativos();
	$quantos  = reconectar_catalogo_quantos_filtros();
	$ordenado = in_array( $ativos['orderby'], array( 'popularity', 'rating', 'date' ), true );
	?>
	<details class="rc-catalogo__lateral" data-rc-catalogo-filtros>
		<summary class="rc-catalogo__alternar">
			<?php esc_html_e( 'Filtrar', 'reconectar' ); ?>
			<?php if ( $quantos ) : ?>
				<span class="rc-catalogo__contagem">
					<?php
					/* translators: %d: quantidade de filtros aplicados. */
					echo esc_html( sprintf( _n( '%d filtro', '%d filtros', $quantos, 'reconectar' ), $quantos ) );
					?>
				</span>
			<?php endif; ?>
		</summary>

		<nav class="rc-catalogo__filtros" aria-label="<?php esc_attr_e( 'Filtros de produtos', 'reconectar' ); ?>">
			<?php if ( $quantos || $ordenado ) : ?>
				<a class="rc-catalogo__limpar" href="<?php echo esc_url( reconectar_catalogo_url( array_fill_keys( array( 'categoria', 'loja', 'oferta', 'orderby', 'min_price', 'max_price' ), null ) ) ); ?>">
					<?php esc_html_e( 'Limpar filtros', 'reconectar' ); ?>
				</a>
			<?php endif; ?>

			<section class="rc-catalogo__grupo" aria-labelledby="rc-catalogo-destaques">
				<h2 class="rc-catalogo__titulo" id="rc-catalogo-destaques"><?php esc_html_e( 'Destaques', 'reconectar' ); ?></h2>
				<ul class="rc-catalogo__opcoes">
					<?php
					/*
					 * Os três primeiros são ordenações do próprio WooCommerce, e por
					 * isso se excluem entre si: clicar no ativo devolve a ordem padrão.
					 * "Mais vendidos" lê `total_sales`, que o WooCommerce incrementa a
					 * cada pedido pago — é dado gravado, não estimativa.
					 */
					$ordens = array(
						'popularity' => __( 'Mais vendidos', 'reconectar' ),
						'rating'     => __( 'Mais bem avaliados', 'reconectar' ),
						'date'       => __( 'Novidades', 'reconectar' ),
					);

					foreach ( $ordens as $valor => $rotulo ) {
						$ligado = $ativos['orderby'] === $valor;
						reconectar_catalogo_opcao( $rotulo, reconectar_catalogo_url( array( 'orderby' => $ligado ? null : $valor ) ), $ligado );
					}

					reconectar_catalogo_opcao(
						__( 'Em oferta', 'reconectar' ),
						reconectar_catalogo_url( array( 'oferta' => $ativos['oferta'] ? null : '1' ) ),
						$ativos['oferta']
					);
					?>
				</ul>
			</section>

			<?php reconectar_catalogo_grupo_de_categorias( $ativos['categoria'] ); ?>
			<?php reconectar_catalogo_grupo_de_lojas( $ativos['loja'] ); ?>
			<?php reconectar_catalogo_grupo_de_preco( $ativos ); ?>
		</nav>
	</details>
	<?php
}

/**
 * Imprime o grupo de categorias.
 *
 * Só as categorias de primeiro nível aparecem de saída; as filhas se abrem sob a
 * mãe escolhida (ou sob a mãe da filha escolhida). Todas de uma vez seriam mais
 * de vinte linhas, e a coluna viraria a página.
 *
 * Sem contagem ao lado do nome, e de propósito: o `count` do termo é do catálogo
 * inteiro, e com uma loja escolhida ele diria "8" sobre uma lista de 2. Um
 * número que não confere com a tela é pior do que nenhum.
 *
 * @param WP_Term|null $ativa Categoria escolhida.
 * @return void
 */
function reconectar_catalogo_grupo_de_categorias( $ativa ) {
	$excluidas = array_filter( array( (int) get_option( 'default_product_cat' ) ) );
	$maes      = get_terms(
		array(
			'taxonomy'   => 'product_cat',
			'parent'     => 0,
			'hide_empty' => true,
			'exclude'    => $excluidas,
			'orderby'    => 'name',
		)
	);

	if ( is_wp_error( $maes ) || ! $maes ) {
		return;
	}

	$mae_aberta = $ativa ? ( $ativa->parent ? (int) $ativa->parent : (int) $ativa->term_id ) : 0;
	?>
	<section class="rc-catalogo__grupo" aria-labelledby="rc-catalogo-categorias">
		<h2 class="rc-catalogo__titulo" id="rc-catalogo-categorias"><?php esc_html_e( 'Categorias', 'reconectar' ); ?></h2>
		<ul class="rc-catalogo__opcoes">
			<?php reconectar_catalogo_opcao( __( 'Todas', 'reconectar' ), reconectar_catalogo_url( array( 'categoria' => null ) ), ! $ativa ); ?>

			<?php foreach ( $maes as $mae ) : ?>
				<?php
				$filhas = (int) $mae->term_id === $mae_aberta
					? get_terms(
						array(
							'taxonomy'   => 'product_cat',
							'parent'     => $mae->term_id,
							'hide_empty' => true,
							'orderby'    => 'name',
						)
					)
					: array();

				reconectar_catalogo_opcao(
					$mae->name,
					reconectar_catalogo_url( array( 'categoria' => $mae->slug ) ),
					$ativa && (int) $ativa->term_id === (int) $mae->term_id,
					is_wp_error( $filhas ) ? array() : $filhas,
					$ativa
				);
				?>
			<?php endforeach; ?>
		</ul>
	</section>
	<?php
}

/**
 * Imprime o grupo de lojas.
 *
 * @param array|null $ativa Loja escolhida, de `reconectar_catalogo_lojas()`.
 * @return void
 */
function reconectar_catalogo_grupo_de_lojas( $ativa ) {
	$lojas = reconectar_catalogo_lojas();

	if ( ! $lojas ) {
		return;
	}
	?>
	<section class="rc-catalogo__grupo" aria-labelledby="rc-catalogo-lojas">
		<h2 class="rc-catalogo__titulo" id="rc-catalogo-lojas"><?php esc_html_e( 'Lojas', 'reconectar' ); ?></h2>
		<ul class="rc-catalogo__opcoes">
			<?php
			reconectar_catalogo_opcao( __( 'Todas', 'reconectar' ), reconectar_catalogo_url( array( 'loja' => null ) ), ! $ativa );

			foreach ( $lojas as $loja ) {
				reconectar_catalogo_opcao(
					$loja['nome'],
					reconectar_catalogo_url( array( 'loja' => $loja['slug'] ) ),
					$ativa && $ativa['id'] === $loja['id']
				);
			}
			?>
		</ul>
	</section>
	<?php
}

/**
 * Imprime o grupo de faixa de preço.
 *
 * É o único filtro em formulário, e ele é `GET` para a mesma URL: o resultado
 * continua compartilhável como os links. Os demais filtros vão em campos ocultos
 * — sem eles, aplicar o preço descartaria a categoria e a loja escolhidas.
 *
 * `min_price` e `max_price` são os nomes que o `WC_Query` já reconhece e aplica
 * pela tabela de busca de preço do WooCommerce, inclusive para variações.
 *
 * @param array $ativos Filtros ativos.
 * @return void
 */
function reconectar_catalogo_grupo_de_preco( $ativos ) {
	$preservados = array_diff_key(
		array_filter(
			array(
				'categoria' => $ativos['categoria'] ? $ativos['categoria']->slug : '',
				'loja'      => $ativos['loja'] ? $ativos['loja']['slug'] : '',
				'oferta'    => $ativos['oferta'] ? '1' : '',
				'orderby'   => $ativos['orderby'],
				// phpcs:disable WordPress.Security.NonceVerification.Recommended
				's'         => isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '',
				'post_type' => isset( $_GET['post_type'] ) ? sanitize_key( wp_unslash( $_GET['post_type'] ) ) : '',
				// phpcs:enable WordPress.Security.NonceVerification.Recommended
			),
			'strlen'
		),
		array_flip( array( 'min_price', 'max_price' ) )
	);
	?>
	<section class="rc-catalogo__grupo" aria-labelledby="rc-catalogo-preco">
		<h2 class="rc-catalogo__titulo" id="rc-catalogo-preco"><?php esc_html_e( 'Preço', 'reconectar' ); ?></h2>
		<form class="rc-catalogo__preco" method="get" action="<?php echo esc_url( reconectar_url_loja() ); ?>">
			<?php foreach ( $preservados as $chave => $valor ) : ?>
				<input type="hidden" name="<?php echo esc_attr( $chave ); ?>" value="<?php echo esc_attr( $valor ); ?>">
			<?php endforeach; ?>

			<p class="rc-catalogo__campo">
				<label for="rc-catalogo-min"><?php esc_html_e( 'De (R$)', 'reconectar' ); ?></label>
				<input class="rc-catalogo__numero" type="number" id="rc-catalogo-min" name="min_price" min="0" step="any" inputmode="decimal" value="<?php echo esc_attr( $ativos['min_price'] ); ?>">
			</p>
			<p class="rc-catalogo__campo">
				<label for="rc-catalogo-max"><?php esc_html_e( 'Até (R$)', 'reconectar' ); ?></label>
				<input class="rc-catalogo__numero" type="number" id="rc-catalogo-max" name="max_price" min="0" step="any" inputmode="decimal" value="<?php echo esc_attr( $ativos['max_price'] ); ?>">
			</p>
			<button class="rc-catalogo__aplicar" type="submit"><?php esc_html_e( 'Aplicar', 'reconectar' ); ?></button>
		</form>
	</section>
	<?php
}

/**
 * Imprime uma opção de filtro, com as filhas por baixo quando houver.
 *
 * O estado ligado vai em `aria-current="true"` mais texto oculto, como na
 * pílula da vitrine de lojas: é link, e `aria-pressed` só vale em botão; e a
 * cor sozinha não comunica nada a quem não a enxerga (WCAG 2.1, 1.4.1).
 *
 * @param string       $rotulo Texto da opção.
 * @param string       $url    Destino.
 * @param bool         $ligado Se a opção está aplicada.
 * @param WP_Term[]    $filhas Categorias filhas a listar por baixo.
 * @param WP_Term|null $ativa  Categoria escolhida, para marcar a filha.
 * @return void
 */
function reconectar_catalogo_opcao( $rotulo, $url, $ligado, $filhas = array(), $ativa = null ) {
	?>
	<li>
		<a class="rc-catalogo__opcao<?php echo $ligado ? ' rc-catalogo__opcao--ativa' : ''; ?>" href="<?php echo esc_url( $url ); ?>"<?php echo $ligado ? ' aria-current="true"' : ''; ?>>
			<?php echo esc_html( $rotulo ); ?>
			<?php if ( $ligado ) : ?>
				<span class="screen-reader-text"><?php esc_html_e( '(selecionado)', 'reconectar' ); ?></span>
			<?php endif; ?>
		</a>

		<?php if ( $filhas ) : ?>
			<ul class="rc-catalogo__opcoes rc-catalogo__opcoes--filhas">
				<?php
				foreach ( $filhas as $filha ) {
					reconectar_catalogo_opcao(
						$filha->name,
						reconectar_catalogo_url( array( 'categoria' => $filha->slug ) ),
						$ativa && (int) $ativa->term_id === (int) $filha->term_id
					);
				}
				?>
			</ul>
		<?php endif; ?>
	</li>
	<?php
}
