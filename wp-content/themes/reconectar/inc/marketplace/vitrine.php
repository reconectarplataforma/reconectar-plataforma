<?php
/**
 * Vitrine de lojas: o componente e a página que o hospeda.
 *
 * A vitrine nasceu como seção da home e por um bom tempo só existiu lá. O
 * resultado é que a plataforma não tinha onde listar lojas: o "Ver todos" do
 * carrossel de destaques apontava para a própria home, e a única página de
 * listagem era a `/store-listing/` do Dokan — em inglês, com markup do plugin e
 * sem nenhum dos filtros do tema.
 *
 * Aqui a vitrine deixa de pertencer à home. `reconectar_vitrine_de_lojas()` é o
 * componente, parametrizado pelo que muda entre os dois contextos, e o filtro
 * do fim do arquivo faz a página do Dokan renderizá-lo no lugar do shortcode.
 *
 * @package reconectar
 */

defined( 'ABSPATH' ) || exit;

/**
 * Imprime a vitrine de lojas: busca, filtros, grade e rodapé da lista.
 *
 * @param array $args {
 *     @type string $titulo Título da seção. Vazio suprime o cabeçalho, para a
 *                          página dedicada, em que o `<h1>` do WordPress já
 *                          nomeia a lista e um `<h2>` logo abaixo diria o mesmo
 *                          duas vezes ao leitor de tela.
 *     @type bool   $busca  Se imprime o campo de busca por nome de loja.
 *     @type string $mais   `'expandir'` aumenta a lista na própria página pelo
 *                          `?lojas=N`; `'pagina'` manda para a vitrine completa.
 *     @type int    $limite Quantas lojas exibir antes do rodapé da lista.
 *     @type bool   $cidades Se a barra traz as pílulas de município. Falso na
 *                           home, onde o município tem filtro próprio acima de
 *                           todas as seções: duas escolhas do mesmo parâmetro na
 *                           mesma tela, uma delas valendo só para esta lista,
 *                           fariam parecer que são filtros diferentes.
 * }
 */
function reconectar_vitrine_de_lojas( $args = array() ) {
	$args = wp_parse_args(
		$args,
		array(
			'titulo'  => '',
			'busca'   => false,
			'mais'    => 'expandir',
			'limite'  => 0,
			'cidades' => true,
		)
	);

	$ativos = reconectar_filtros_ativos();

	if ( $args['limite'] > 0 ) {
		$ativos['limite'] = $args['limite'];
	}

	/*
	 * Pede uma loja a mais do que vai exibir. É a forma mais barata de saber se
	 * ainda há resultados depois do corte: contar o total exigiria carregar e
	 * filtrar a lista inteira uma segunda vez, sem paginação, só para descobrir
	 * se o botão deve aparecer.
	 */
	$lojas = reconectar_obter_lojas(
		array(
			'numero'    => $ativos['limite'] + 1,
			'categoria' => $ativos['categoria'],
			'cidade'    => $ativos['cidade'],
			'busca'     => $ativos['busca'],
			'so_gratis' => $ativos['so_gratis'],
			'ordenar'   => $ativos['ordenar'],
		)
	);

	$tem_mais = count( $lojas ) > $ativos['limite'];

	if ( $tem_mais ) {
		$lojas = array_slice( $lojas, 0, $ativos['limite'] );
	}
	?>
	<section class="rc-vitrine" aria-labelledby="rc-vitrine-titulo">
		<?php if ( $args['titulo'] ) : ?>
			<div class="rc-vitrine__cabecalho">
				<h2 class="rc-vitrine__titulo" id="rc-vitrine-titulo">
					<?php echo esc_html( $args['titulo'] ); ?>
				</h2>
			</div>
		<?php endif; ?>

		<?php
		if ( $args['busca'] ) {
			reconectar_busca_de_lojas( $ativos );
		}
		?>

		<?php reconectar_barra_de_filtros( $ativos, $args['cidades'] ); ?>

		<?php if ( $lojas ) : ?>
			<div class="rc-vitrine__grade">
				<?php foreach ( $lojas as $loja ) : ?>
					<?php reconectar_card_loja( $loja ); ?>
				<?php endforeach; ?>
			</div>

			<?php if ( $tem_mais ) : ?>
				<p class="rc-vitrine__mais">
					<?php if ( 'pagina' === $args['mais'] ) : ?>
						<a class="rc-botao rc-botao--largo" href="<?php echo esc_url( reconectar_url_das_lojas( $ativos['cidade'] ) ); ?>">
							<?php esc_html_e( 'Ver todas as lojas', 'reconectar' ); ?>
						</a>
					<?php else : ?>
						<a
							class="rc-botao rc-botao--largo"
							href="<?php echo esc_url( reconectar_url_de_filtro( 'lojas', (string) ( $ativos['limite'] + RECONECTAR_LOJAS_POR_PAGINA ) ) ); ?>"
						>
							<?php esc_html_e( 'Ver mais lojas', 'reconectar' ); ?>
						</a>
					<?php endif; ?>
				</p>
			<?php endif; ?>
		<?php else : ?>
			<?php reconectar_vitrine_vazia( $ativos, $args['cidades'] ); ?>
		<?php endif; ?>
	</section>
	<?php
}

/**
 * Campo de busca por nome de loja.
 *
 * Um `<form method="get">` simples, sem autocomplete: o campo do cabeçalho já
 * tem endpoint REST próprio e cobre produtos e lojas do site inteiro. Aqui a
 * busca é um filtro a mais da vitrine, e se comporta como os outros — o termo
 * fica na URL, é compartilhável e o botão "voltar" o desfaz.
 *
 * Os filtros ativos viajam como campos ocultos. Sem eles, buscar um nome
 * apagaria em silêncio a categoria e a cidade escolhidas antes, o que não é o
 * que o resto da barra faz: `reconectar_url_de_filtro()` preserva os demais
 * parâmetros a cada clique, e o campo não pode ser a exceção.
 *
 * @param array $ativos Filtros ativos, de `reconectar_filtros_ativos()`.
 */
function reconectar_busca_de_lojas( $ativos ) {
	$ocultos = array(
		'categoria' => $ativos['categoria'],
		'cidade'    => $ativos['cidade'],
		'entrega'   => $ativos['so_gratis'] ? 'gratis' : '',
		'ordenar'   => 'avaliacao' !== $ativos['ordenar'] ? $ativos['ordenar'] : '',
	);
	?>
	<form class="rc-busca-filtro" method="get" action="<?php echo esc_url( reconectar_url_base_da_vitrine() ); ?>" role="search">
		<label class="screen-reader-text" for="rc-busca-loja-campo">
			<?php esc_html_e( 'Buscar loja pelo nome', 'reconectar' ); ?>
		</label>

		<button type="submit" class="rc-busca-filtro__botao" aria-label="<?php esc_attr_e( 'Buscar', 'reconectar' ); ?>">
			<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" focusable="false" aria-hidden="true">
				<circle cx="11" cy="11" r="7"></circle>
				<path d="m20 20-3.5-3.5"></path>
			</svg>
		</button>

		<input
			type="search"
			id="rc-busca-loja-campo"
			class="rc-busca-filtro__campo"
			name="busca"
			value="<?php echo esc_attr( $ativos['busca'] ); ?>"
			placeholder="<?php esc_attr_e( 'Busque uma loja pelo nome', 'reconectar' ); ?>"
		/>

		<?php foreach ( $ocultos as $chave => $valor ) : ?>
			<?php if ( '' !== $valor ) : ?>
				<input type="hidden" name="<?php echo esc_attr( $chave ); ?>" value="<?php echo esc_attr( $valor ); ?>" />
			<?php endif; ?>
		<?php endforeach; ?>
	</form>
	<?php
}

/**
 * Mensagem exibida quando nenhuma loja atende aos filtros.
 *
 * Distingue os três casos possíveis. Sem filtro nem busca, a plataforma
 * realmente ainda não tem lojas publicadas, e dizer "tente outros filtros"
 * seria enganoso. Com busca, o termo é ecoado — quem digitou errado precisa ver
 * o que foi procurado para corrigir. Com filtro, a saída existe e precisa estar
 * à mão, daí o link para limpar.
 *
 * @param array $ativos      Filtros ativos, de `reconectar_filtros_ativos()`.
 * @param bool  $com_cidades Se o município é filtro desta lista. Veja
 *                           `reconectar_url_sem_filtros()`.
 */
function reconectar_vitrine_vazia( $ativos, $com_cidades = true ) {
	$tem_filtro = $ativos['categoria'] || ( $com_cidades && $ativos['cidade'] ) || $ativos['busca'] || $ativos['so_gratis'];
	?>
	<div class="rc-vitrine__vazia">
		<?php if ( $ativos['busca'] ) : ?>
			<p>
				<?php
				printf(
					/* translators: %s: termo buscado. */
					esc_html__( 'Nenhuma loja com “%s” no nome.', 'reconectar' ),
					esc_html( $ativos['busca'] )
				);
				?>
			</p>
		<?php elseif ( $tem_filtro ) : ?>
			<p><?php esc_html_e( 'Nenhuma loja atende aos filtros selecionados.', 'reconectar' ); ?></p>
		<?php else : ?>
			<p><?php esc_html_e( 'Ainda não há lojas publicadas na plataforma.', 'reconectar' ); ?></p>
		<?php endif; ?>

		<?php if ( $tem_filtro ) : ?>
			<p>
				<a class="rc-botao" href="<?php echo esc_url( reconectar_url_sem_filtros( $ativos, $com_cidades ) ); ?>">
					<?php esc_html_e( 'Limpar filtros', 'reconectar' ); ?>
				</a>
			</p>
		<?php endif; ?>
	</div>
	<?php
}

/**
 * Faz o `[dokan-stores]` renderizar a vitrine do tema.
 *
 * A página `/store-listing/` é o endereço canônico da listagem: é para ela que
 * aponta o breadcrumb do Dokan em cada página de loja (`Rewrites.php:58`) e o
 * link do rodapé. O que ela imprimia era o shortcode cru do plugin — cards de
 * terceiro, filtros em inglês e nenhum dos filtros do tema.
 *
 * A substituição é feita pela saída do shortcode, e não trocando o conteúdo da
 * página por um shortcode nosso, por um motivo concreto: `dokan_is_store_listing()`
 * (`dokan-lite/includes/functions.php:3242`) identifica a página pelo ID **ou**
 * pela presença de `[dokan-stores` no conteúdo, e dela dependem o breadcrumb, a
 * classe do `<body>` e o item da barra de administração. Mantendo o conteúdo
 * intacto, nada disso precisa ser reimplementado — e uma instalação já
 * existente ganha a vitrine sem migração de dado nenhum.
 *
 * @param string $saida Saída original do shortcode.
 * @param string $tag   Nome do shortcode.
 * @param array  $atributos Atributos declarados no shortcode.
 * @return string
 */
function reconectar_substituir_listagem_do_dokan( $saida, $tag, $atributos ) {
	if ( 'dokan-stores' !== $tag ) {
		return $saida;
	}

	/*
	 * `per_page` é o único atributo do shortcode que sobrevive à troca: os
	 * demais (`per_row`, `search`, `orderby`) descrevem a interface do Dokan,
	 * que deixou de existir aqui. Ele só vale quando ninguém pediu expansão pela
	 * URL — do contrário o "Ver mais lojas" seria desfeito pelo atributo a cada
	 * clique. `$atributos` pode vir como string vazia quando o shortcode é
	 * escrito sem atributo nenhum, que é o caso da página instalada pelo plugin.
	 */
	$ativos     = reconectar_filtros_ativos();
	$por_pagina = is_array( $atributos ) && isset( $atributos['per_page'] ) ? absint( $atributos['per_page'] ) : 0;
	$limite     = ( $por_pagina > 0 && RECONECTAR_LOJAS_POR_PAGINA === $ativos['limite'] ) ? $por_pagina : 0;

	ob_start();

	reconectar_vitrine_de_lojas(
		array(
			'busca'  => true,
			'mais'   => 'expandir',
			'limite' => $limite,
		)
	);

	return ob_get_clean();
}
add_filter( 'do_shortcode_tag', 'reconectar_substituir_listagem_do_dokan', 10, 3 );
