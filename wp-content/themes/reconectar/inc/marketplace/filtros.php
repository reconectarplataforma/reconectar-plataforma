<?php
/**
 * Barra de filtros da vitrine de lojas.
 *
 * Os filtros são **links**, não um formulário com JavaScript. Cada pílula é uma
 * URL com a query string ajustada, o que traz três coisas de graça: o estado do
 * filtro é compartilhável e favoritável, o botão "voltar" do navegador desfaz a
 * escolha, e tudo funciona com o JavaScript desativado ou ainda carregando.
 *
 * O preço é uma recarga de página a cada clique. Numa vitrine desta escala isso
 * é irrelevante perto do que se ganha em robustez e acessibilidade.
 *
 * @package reconectar
 */

defined( 'ABSPATH' ) || exit;

/**
 * Quantidade de lojas exibidas antes do botão "Ver mais".
 */
define( 'RECONECTAR_LOJAS_POR_PAGINA', 12 );

/**
 * Teto de lojas que a vitrine aceita exibir de uma vez.
 */
define( 'RECONECTAR_LOJAS_LIMITE_MAXIMO', 96 );

/**
 * Lê os filtros ativos a partir da query string, já sanitizados.
 *
 * Esta é a única porta de entrada dos parâmetros de filtro. Nenhum outro lugar
 * do tema lê `$_GET` para isso — centralizar garante que a sanitização aconteça
 * sempre, e não "quase sempre".
 *
 * @return array{categoria:string,cidade:string,so_gratis:bool,ordenar:string,limite:int}
 */
function reconectar_filtros_ativos() {
	// Leitura de filtro de navegação pública, sem efeito colateral: não há o que
	// um nonce protegeria aqui, e exigir um quebraria os links compartilháveis.
	// phpcs:disable WordPress.Security.NonceVerification.Recommended
	$categoria = isset( $_GET['categoria'] ) ? sanitize_title( wp_unslash( $_GET['categoria'] ) ) : '';
	$cidade    = isset( $_GET['cidade'] ) ? sanitize_text_field( wp_unslash( $_GET['cidade'] ) ) : '';
	$gratis    = isset( $_GET['entrega'] ) && 'gratis' === sanitize_key( wp_unslash( $_GET['entrega'] ) );
	$ordenar   = isset( $_GET['ordenar'] ) ? sanitize_key( wp_unslash( $_GET['ordenar'] ) ) : 'avaliacao';
	$limite    = isset( $_GET['lojas'] ) ? absint( wp_unslash( $_GET['lojas'] ) ) : 0;
	// phpcs:enable WordPress.Security.NonceVerification.Recommended

	// Ordenação vem de uma lista fechada: um valor inventado na URL volta ao
	// padrão em vez de chegar até a consulta.
	if ( ! in_array( $ordenar, array( 'avaliacao', 'nome' ), true ) ) {
		$ordenar = 'avaliacao';
	}

	/*
	 * O "Ver mais" da vitrine aumenta este limite pela URL. O teto existe porque
	 * o parâmetro é público: sem ele, `?lojas=100000` pediria a normalização de
	 * todas as lojas de uma vez, e cada normalização faz consultas próprias.
	 */
	$limite = min( max( $limite, RECONECTAR_LOJAS_POR_PAGINA ), RECONECTAR_LOJAS_LIMITE_MAXIMO );

	return array(
		'categoria' => $categoria,
		'cidade'    => $cidade,
		'so_gratis' => $gratis,
		'ordenar'   => $ordenar,
		'limite'    => $limite,
	);
}

/**
 * Monta a URL da página atual com um parâmetro de filtro alterado.
 *
 * @param string      $chave Nome do parâmetro.
 * @param string|null $valor Valor; `null` remove o parâmetro.
 * @return string
 */
function reconectar_url_de_filtro( $chave, $valor ) {
	$base = reconectar_url_base_da_vitrine();

	/*
	 * `lojas` fica de fora de propósito, embora seja um parâmetro conhecido:
	 * trocar de filtro refaz a lista do zero, e manter o "Ver mais" anterior
	 * faria a nova consulta já nascer expandida, escondendo do cliente quantos
	 * resultados o filtro escolhido de fato tem.
	 */
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$parametros = array_intersect_key(
		wp_unslash( $_GET ), // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		array_flip( array( 'categoria', 'cidade', 'entrega', 'ordenar' ) )
	);

	if ( null === $valor || '' === $valor ) {
		unset( $parametros[ $chave ] );
	} else {
		$parametros[ $chave ] = $valor;
	}

	return $parametros ? add_query_arg( array_map( 'rawurlencode', $parametros ), $base ) : $base;
}

/**
 * URL da página em que a vitrine está sendo exibida, sem query string.
 *
 * @return string
 */
function reconectar_url_base_da_vitrine() {
	if ( is_front_page() ) {
		return home_url( '/' );
	}

	$id = get_queried_object_id();

	return $id ? get_permalink( $id ) : home_url( '/' );
}

/**
 * Imprime a barra de filtros em pílulas.
 *
 * @param array $ativos Filtros ativos, de `reconectar_filtros_ativos()`.
 */
function reconectar_barra_de_filtros( $ativos ) {
	$tem_filtro = $ativos['categoria'] || $ativos['cidade'] || $ativos['so_gratis'] || 'avaliacao' !== $ativos['ordenar'];
	?>
	<nav class="rc-filtros" aria-label="<?php esc_attr_e( 'Filtros da vitrine de lojas', 'reconectar' ); ?>">
		<ul class="rc-filtros__lista">
			<li>
				<?php
				reconectar_pilula_de_filtro(
					array(
						'rotulo' => 'nome' === $ativos['ordenar']
							? __( 'Ordenar: nome', 'reconectar' )
							: __( 'Ordenar: melhor avaliadas', 'reconectar' ),
						'url'    => reconectar_url_de_filtro( 'ordenar', 'nome' === $ativos['ordenar'] ? null : 'nome' ),
						'ativo'  => 'nome' === $ativos['ordenar'],
					)
				);
				?>
			</li>

			<li>
				<?php
				reconectar_pilula_de_filtro(
					array(
						'rotulo' => __( 'Entrega grátis', 'reconectar' ),
						'url'    => reconectar_url_de_filtro( 'entrega', $ativos['so_gratis'] ? null : 'gratis' ),
						'ativo'  => $ativos['so_gratis'],
					)
				);
				?>
			</li>

			<?php foreach ( reconectar_obter_cidades() as $cidade ) : ?>
				<?php $marcada = sanitize_title( $cidade ) === sanitize_title( $ativos['cidade'] ); ?>
				<li>
					<?php
					reconectar_pilula_de_filtro(
						array(
							'rotulo' => $cidade,
							'url'    => reconectar_url_de_filtro( 'cidade', $marcada ? null : $cidade ),
							'ativo'  => $marcada,
						)
					);
					?>
				</li>
			<?php endforeach; ?>

			<?php if ( $tem_filtro ) : ?>
				<li>
					<a class="rc-filtros__limpar" href="<?php echo esc_url( reconectar_url_base_da_vitrine() ); ?>">
						<?php esc_html_e( 'Limpar filtros', 'reconectar' ); ?>
					</a>
				</li>
			<?php endif; ?>
		</ul>
	</nav>
	<?php
}

/**
 * Imprime uma pílula de filtro.
 *
 * A pílula é um link, então o estado "ligado" é marcado com `aria-current` — e
 * não com `aria-pressed`, que só é válido em elementos com papel de botão. Além
 * disso vai um texto oculto explícito: a cor de fundo sozinha não comunica nada
 * a quem não a enxerga (WCAG 2.1, critério 1.4.1).
 *
 * @param array $args {
 *     @type string $rotulo Texto da pílula.
 *     @type string $url    Destino.
 *     @type bool   $ativo  Se o filtro está aplicado.
 * }
 */
function reconectar_pilula_de_filtro( $args ) {
	?>
	<a
		class="rc-pilula<?php echo $args['ativo'] ? ' rc-pilula--ativa' : ''; ?>"
		href="<?php echo esc_url( $args['url'] ); ?>"
		<?php echo $args['ativo'] ? ' aria-current="true"' : ''; ?>
	>
		<?php echo esc_html( $args['rotulo'] ); ?>

		<?php if ( $args['ativo'] ) : ?>
			<span class="screen-reader-text"><?php esc_html_e( '(filtro ativo, clique para remover)', 'reconectar' ); ?></span>
		<?php endif; ?>
	</a>
	<?php
}
