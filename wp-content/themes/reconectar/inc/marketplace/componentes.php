<?php
/**
 * Componentes visuais reaproveitáveis do marketplace.
 *
 * Cada função aqui imprime um pedaço de interface que aparece em mais de um
 * lugar — o card de loja está na home e na página de lojas, o carrossel embrulha
 * categorias e produtos. Duplicar essa marcação nos templates faria com que um
 * ajuste de acessibilidade ou de classe CSS precisasse ser repetido em cada
 * cópia, e a que fosse esquecida viraria a inconsistência que ninguém acha.
 *
 * Todas as funções **imprimem** (não retornam). O escape é feito aqui dentro,
 * na borda da saída, e não na camada de consulta — assim o mesmo dado pode ir
 * para HTML, para um atributo ou para JSON sem escape duplicado.
 *
 * @package reconectar
 */

defined( 'ABSPATH' ) || exit;

/**
 * Abre um carrossel horizontal.
 *
 * O carrossel é uma faixa com rolagem horizontal nativa (`overflow-x`) mais dois
 * botões de seta. A rolagem nativa é intencional: ela já funciona com toque,
 * com trackpad, com roda do mouse e com navegação por teclado sem uma linha de
 * JavaScript. Os botões são um acréscimo para quem usa mouse em telas grandes,
 * onde arrastar não é natural — e por isso ficam escondidos de leitores de tela
 * (`aria-hidden`), que já têm a lista completa à disposição.
 *
 * @param array $args {
 *     @type string $id      Identificador único da faixa. Obrigatório.
 *     @type string $titulo  Título exibido acima da faixa. Opcional.
 *     @type string $link    URL de um "ver todos" ao lado do título. Opcional.
 *     @type string $classe  Classes extras para a faixa. Opcional.
 * }
 */
function reconectar_abrir_carrossel( $args ) {
	$args = wp_parse_args(
		$args,
		array(
			'id'     => '',
			'titulo' => '',
			'link'   => '',
			'classe' => '',
		)
	);

	$id = sanitize_html_class( $args['id'] );
	?>
	<section class="rc-carrossel" data-rc-carrossel>
		<?php if ( $args['titulo'] ) : ?>
			<div class="rc-carrossel__cabecalho">
				<h2 class="rc-carrossel__titulo" id="<?php echo esc_attr( $id . '-titulo' ); ?>">
					<?php echo esc_html( $args['titulo'] ); ?>
				</h2>

				<div class="rc-carrossel__controles">
					<?php if ( $args['link'] ) : ?>
						<a class="rc-carrossel__ver-todos" href="<?php echo esc_url( $args['link'] ); ?>">
							<?php esc_html_e( 'Ver todos', 'reconectar' ); ?>
						</a>
					<?php endif; ?>

					<button type="button" class="rc-carrossel__seta" data-rc-carrossel-anterior aria-hidden="true" tabindex="-1">
						<span class="rc-carrossel__seta-icone" aria-hidden="true">&#8249;</span>
					</button>
					<button type="button" class="rc-carrossel__seta" data-rc-carrossel-proximo aria-hidden="true" tabindex="-1">
						<span class="rc-carrossel__seta-icone" aria-hidden="true">&#8250;</span>
					</button>
				</div>
			</div>
		<?php endif; ?>

		<?php
		/*
		 * `tabindex="0"` na área rolável é exigência da WCAG 2.1 (critério 2.1.1):
		 * um contêiner com rolagem precisa ser alcançável pelo teclado, senão a
		 * parte que está fora da tela fica inacessível para quem não usa mouse.
		 */
		?>
		<div
			class="rc-carrossel__faixa <?php echo esc_attr( $args['classe'] ); ?>"
			data-rc-carrossel-faixa
			tabindex="0"
			role="group"
			<?php if ( $args['titulo'] ) : ?>
				aria-labelledby="<?php echo esc_attr( $id . '-titulo' ); ?>"
			<?php endif; ?>
		>
	<?php
}

/**
 * Fecha o carrossel aberto por `reconectar_abrir_carrossel()`.
 */
function reconectar_fechar_carrossel() {
	?>
		</div>
	</section>
	<?php
}

/**
 * Card de loja da vitrine.
 *
 * @param array $loja Loja normalizada por `reconectar_normalizar_loja()`.
 */
function reconectar_card_loja( $loja ) {
	if ( empty( $loja['nome'] ) ) {
		return;
	}

	$entrega = isset( $loja['entrega'] ) ? $loja['entrega'] : array();
	?>
	<article class="rc-card-loja">
		<a class="rc-card-loja__link" href="<?php echo esc_url( $loja['url'] ); ?>">
			<div class="rc-card-loja__logo">
				<?php if ( ! empty( $loja['logo_id'] ) ) : ?>
					<?php
					echo wp_get_attachment_image(
						(int) $loja['logo_id'],
						'thumbnail',
						false,
						array(
							// A imagem é decorativa: o nome da loja está logo ao
							// lado, em texto. Um `alt` com o nome repetido faria
							// o leitor de tela anunciar a mesma coisa duas vezes.
							'alt'     => '',
							'loading' => 'lazy',
						)
					);
					?>
				<?php else : ?>
					<span class="rc-card-loja__inicial" aria-hidden="true">
						<?php echo esc_html( mb_substr( $loja['nome'], 0, 1 ) ); ?>
					</span>
				<?php endif; ?>
			</div>

			<div class="rc-card-loja__conteudo">
				<h3 class="rc-card-loja__nome"><?php echo esc_html( $loja['nome'] ); ?></h3>

				<p class="rc-card-loja__meta">
					<?php reconectar_nota_em_estrela( $loja['nota'] ); ?>

					<?php if ( ! empty( $loja['categoria'] ) ) : ?>
						<span class="rc-card-loja__separador" aria-hidden="true">&middot;</span>
						<span class="rc-card-loja__categoria"><?php echo esc_html( $loja['categoria'] ); ?></span>
					<?php endif; ?>

					<?php if ( ! empty( $entrega['distancia'] ) ) : ?>
						<span class="rc-card-loja__separador" aria-hidden="true">&middot;</span>
						<span class="rc-card-loja__distancia"><?php echo esc_html( $entrega['distancia'] ); ?></span>
					<?php endif; ?>
				</p>

				<?php if ( ! empty( $entrega['tempo'] ) || '' !== ( $entrega['taxa'] ?? '' ) ) : ?>
					<p class="rc-card-loja__entrega">
						<?php if ( ! empty( $entrega['tempo'] ) ) : ?>
							<span class="rc-card-loja__tempo"><?php echo esc_html( $entrega['tempo'] ); ?></span>
						<?php endif; ?>

						<?php if ( '' !== ( $entrega['taxa'] ?? '' ) ) : ?>
							<span class="rc-card-loja__separador" aria-hidden="true">&middot;</span>
							<?php reconectar_taxa_de_entrega( $entrega['taxa'] ); ?>
						<?php endif; ?>
					</p>
				<?php endif; ?>
			</div>
		</a>
	</article>
	<?php
}

/**
 * Card largo de loja, com o banner da loja como fundo.
 *
 * Usado na faixa de lojas em destaque. É um formato diferente do card da grade
 * porque o objetivo também é diferente: a grade serve para comparar lojas
 * (nota, distância, taxa lado a lado), a faixa serve para dar visibilidade a
 * algumas delas. Loja sem banner cadastrado cai para um fundo sólido em vez de
 * aparecer com um retângulo quebrado.
 *
 * @param array $loja Loja normalizada por `reconectar_normalizar_loja()`.
 */
function reconectar_card_loja_banner( $loja ) {
	if ( empty( $loja['nome'] ) ) {
		return;
	}
	?>
	<article class="rc-card-destaque<?php echo empty( $loja['banner_id'] ) ? ' rc-card-destaque--sem-banner' : ''; ?>">
		<a class="rc-card-destaque__link" href="<?php echo esc_url( $loja['url'] ); ?>">
			<?php if ( ! empty( $loja['banner_id'] ) ) : ?>
				<div class="rc-card-destaque__banner">
					<?php
					echo wp_get_attachment_image(
						(int) $loja['banner_id'],
						'medium_large',
						false,
						array(
							'alt'     => '',
							'loading' => 'lazy',
						)
					);
					?>
				</div>
			<?php endif; ?>

			<div class="rc-card-destaque__conteudo">
				<h3 class="rc-card-destaque__nome"><?php echo esc_html( $loja['nome'] ); ?></h3>

				<p class="rc-card-destaque__meta">
					<?php reconectar_nota_em_estrela( $loja['nota'] ); ?>

					<?php if ( ! empty( $loja['cidade'] ) ) : ?>
						<span class="rc-card-destaque__separador" aria-hidden="true">&middot;</span>
						<span class="rc-card-destaque__cidade"><?php echo esc_html( $loja['cidade'] ); ?></span>
					<?php endif; ?>
				</p>
			</div>
		</a>
	</article>
	<?php
}

/**
 * Imprime a taxa de entrega, destacando quando ela é gratuita.
 *
 * @param string $taxa Valor bruto gravado na meta do vendedor.
 */
function reconectar_taxa_de_entrega( $taxa ) {
	$valor = (float) str_replace( ',', '.', $taxa );

	if ( $valor <= 0 ) {
		?>
		<span class="rc-card-loja__taxa rc-card-loja__taxa--gratis">
			<?php esc_html_e( 'Entrega grátis', 'reconectar' ); ?>
		</span>
		<?php
		return;
	}
	?>
	<span class="rc-card-loja__taxa">
		<?php echo wp_kses_post( wc_price( $valor ) ); ?>
	</span>
	<?php
}

/**
 * Nota da loja em formato "★ 4,9".
 *
 * A estrela é decorativa e o número vem acompanhado de um texto alternativo
 * completo ("Nota 4,9 de 5"), porque "★ 4,9" lido em voz alta não diz de quanto
 * é a escala.
 *
 * @param float $nota Nota de 0 a 5.
 */
function reconectar_nota_em_estrela( $nota ) {
	$nota = (float) $nota;

	if ( $nota <= 0 ) {
		?>
		<span class="rc-nota rc-nota--sem-avaliacao">
			<?php esc_html_e( 'Sem avaliações', 'reconectar' ); ?>
		</span>
		<?php
		return;
	}

	$formatada = number_format_i18n( $nota, 1 );
	?>
	<span class="rc-nota">
		<span class="rc-nota__estrela" aria-hidden="true">&#9733;</span>
		<span aria-hidden="true"><?php echo esc_html( $formatada ); ?></span>
		<span class="screen-reader-text">
			<?php
			printf(
				/* translators: %s: nota da loja, de 0 a 5. */
				esc_html__( 'Nota %s de 5', 'reconectar' ),
				esc_html( $formatada )
			);
			?>
		</span>
	</span>
	<?php
}

/**
 * Card de categoria do carrossel.
 *
 * @param WP_Term $categoria Termo de `product_cat`.
 */
function reconectar_card_categoria( $categoria ) {
	$imagem_id = (int) get_term_meta( $categoria->term_id, 'thumbnail_id', true );
	?>
	<a class="rc-card-categoria" href="<?php echo esc_url( get_term_link( $categoria ) ); ?>">
		<span class="rc-card-categoria__figura">
			<?php if ( $imagem_id ) : ?>
				<?php
				echo wp_get_attachment_image(
					$imagem_id,
					'thumbnail',
					false,
					array(
						'alt'     => '',
						'loading' => 'lazy',
					)
				);
				?>
			<?php else : ?>
				<span class="rc-card-categoria__inicial" aria-hidden="true">
					<?php echo esc_html( mb_substr( $categoria->name, 0, 1 ) ); ?>
				</span>
			<?php endif; ?>
		</span>
		<span class="rc-card-categoria__nome"><?php echo esc_html( $categoria->name ); ?></span>
	</a>
	<?php
}

/**
 * Card de produto usado nas faixas da home.
 *
 * Não reaproveita `wc_get_template_part( 'content', 'product' )` de propósito: o
 * template do WooCommerce assume o laço da loja (`$GLOBALS['product']`, hooks de
 * arquivo, colunas) e carrega um conjunto de ganchos que aqui produziria botões
 * e badges fora de lugar. Um card próprio é mais curto do que domar aquele.
 *
 * @param WC_Product $produto Produto do WooCommerce.
 */
function reconectar_card_produto( $produto ) {
	if ( ! $produto instanceof WC_Product ) {
		return;
	}

	/*
	 * O vendedor do produto é o autor do post. `get_post_data()` daria o mesmo
	 * resultado, mas está depreciado desde o WooCommerce 3.0 e avisa no log.
	 */
	$vendedor_id = (int) get_post_field( 'post_author', $produto->get_id() );
	$loja        = reconectar_normalizar_loja( $vendedor_id );
	?>
	<article class="rc-card-produto">
		<a class="rc-card-produto__link" href="<?php echo esc_url( $produto->get_permalink() ); ?>">
			<div class="rc-card-produto__figura">
				<?php
				echo wp_kses_post(
					$produto->get_image(
						'woocommerce_thumbnail',
						array(
							'alt'     => '',
							'loading' => 'lazy',
						)
					)
				);
				?>

				<?php if ( $produto->is_on_sale() ) : ?>
					<span class="rc-card-produto__selo">
						<?php esc_html_e( 'Promoção', 'reconectar' ); ?>
					</span>
				<?php endif; ?>
			</div>

			<div class="rc-card-produto__conteudo">
				<h3 class="rc-card-produto__nome"><?php echo esc_html( $produto->get_name() ); ?></h3>

				<?php if ( $loja ) : ?>
					<p class="rc-card-produto__loja"><?php echo esc_html( $loja['nome'] ); ?></p>
				<?php endif; ?>

				<p class="rc-card-produto__preco">
					<?php echo wp_kses_post( $produto->get_price_html() ); ?>
				</p>
			</div>
		</a>
	</article>
	<?php
}
