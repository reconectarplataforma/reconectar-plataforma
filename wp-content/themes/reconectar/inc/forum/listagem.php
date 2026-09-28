<?php
/**
 * A tela "Todas as perguntas".
 *
 * Monta o cabeçalho, os filtros, a lista e a paginação. Os cards vêm de
 * `componentes.php`; as consultas, de `consultas.php`.
 *
 * @package reconectar
 */

defined( 'ABSPATH' ) || exit;

/**
 * Imprime a listagem de perguntas com seus filtros.
 *
 * @param array $args {
 *     Opcional.
 *
 *     @type string $titulo    Título da seção.
 *     @type bool   $filtros   Se imprime abas, categoria e busca.
 *     @type bool   $sidebars  Se imprime as duas colunas laterais.
 *     @type int    $categoria Categoria fixada pelo template, que vence o `$_GET`.
 * }
 */
function reconectar_forum_listagem( $args = array() ) {
	$args = wp_parse_args(
		$args,
		array(
			'titulo'    => __( 'Todas as perguntas', 'reconectar' ),
			'filtros'   => true,
			'sidebars'  => true,
			'categoria' => 0,
		)
	);

	$filtros = reconectar_forum_filtros_ativos();

	/*
	 * A categoria do template vence a da query string. Numa página de categoria a
	 * URL não carrega `?categoria=`, e sem esta linha a tela mostraria as
	 * perguntas de todas as categorias sob o título de uma só — uma lista errada
	 * que continua plausível, que é o pior tipo.
	 */
	if ( $args['categoria'] ) {
		$filtros['categoria'] = (int) $args['categoria'];
	}

	$consulta = new WP_Query( reconectar_forum_argumentos( $filtros ) );
	?>
	<div class="rc-forum">
		<?php reconectar_forum_cabecalho( $args['titulo'], $filtros, $consulta->found_posts ); ?>

		<div class="rc-forum__colunas<?php echo $args['sidebars'] ? '' : ' rc-forum__colunas--sem-laterais'; ?>">
			<?php
			if ( $args['sidebars'] ) {
				reconectar_forum_sidebar_esquerda();
			}
			?>

			<main class="rc-forum__lista" id="rc-forum-lista">
				<?php
				if ( $args['filtros'] ) {
					reconectar_forum_barra_de_filtros( $filtros );
				}
				?>

				<?php if ( $consulta->have_posts() ) : ?>
					<ul class="rc-forum__perguntas">
						<?php foreach ( $consulta->posts as $topico ) : ?>
							<li><?php reconectar_forum_card( $topico ); ?></li>
						<?php endforeach; ?>
					</ul>

					<?php reconectar_forum_paginacao( $filtros, (int) $consulta->max_num_pages ); ?>
				<?php else : ?>
					<?php reconectar_forum_vazio( $filtros ); ?>
				<?php endif; ?>
			</main>

			<?php
			if ( $args['sidebars'] ) {
				reconectar_forum_sidebar_direita();
			}
			?>
		</div>
	</div>
	<?php
	// A consulta é própria e não toca no laço principal, então não há
	// `wp_reset_postdata()` a fazer: nada aqui chamou `the_post()`.
}

/**
 * Imprime o título da tela e o botão de perguntar.
 *
 * O botão só aparece para quem pode publicar. Quem não pode não vê um controle
 * que levaria a um 403 — a regra é a mesma de `Reconectar_Permissoes`, que já
 * nega `publish_topics` a quem está fora da comunidade.
 *
 * @param string $titulo Título da seção.
 * @param array  $filtros Filtros ativos.
 * @param int    $total   Perguntas encontradas.
 */
function reconectar_forum_cabecalho( $titulo, $filtros, $total ) {
	?>
	<header class="rc-forum__cabecalho">
		<div class="rc-forum__cabecalho-texto">
			<h1 class="rc-forum__titulo" id="rc-forum-titulo"><?php echo esc_html( $titulo ); ?></h1>
			<p class="rc-forum__total">
				<?php
				printf(
					/* translators: %s: quantidade de perguntas. */
					esc_html( _n( '%s pergunta', '%s perguntas', $total, 'reconectar' ) ),
					esc_html( number_format_i18n( $total ) )
				);
				?>
			</p>
		</div>

		<?php if ( current_user_can( 'publish_topics' ) ) : ?>
			<a class="rc-forum__perguntar" href="<?php echo esc_url( reconectar_forum_url_da_pergunta( $filtros ) ); ?>">
				<?php esc_html_e( 'Fazer uma pergunta', 'reconectar' ); ?>
			</a>
		<?php endif; ?>
	</header>
	<?php
}

/**
 * URL do formulário de nova pergunta.
 *
 * O bbPress imprime o formulário no rodapé do fórum, e não em uma página
 * própria: o link leva à categoria escolhida — ou à primeira que existir, quando
 * nenhuma está filtrada — com a âncora do formulário.
 *
 * @param array $filtros Filtros ativos.
 * @return string
 */
function reconectar_forum_url_da_pergunta( $filtros ) {
	$categoria = $filtros['categoria'];

	if ( ! $categoria ) {
		$categorias = reconectar_forum_categorias();
		$categoria  = $categorias ? (int) $categorias[0]->ID : 0;
	}

	if ( ! $categoria ) {
		return reconectar_forum_url_base();
	}

	return get_permalink( $categoria ) . '#new-post';
}

/**
 * Imprime abas, seletor de categoria e busca.
 *
 * As abas são links, e não botões: cada uma é um endereço próprio, que o usuário
 * pode guardar e compartilhar. Por serem links, a ativa leva `aria-current` — um
 * `<a>` não é um controle de estado, e `aria-pressed` ali seria mentira para o
 * leitor de tela.
 *
 * @param array $filtros Filtros ativos.
 */
function reconectar_forum_barra_de_filtros( $filtros ) {
	$categorias = reconectar_forum_categorias();
	?>
	<div class="rc-forum__filtros">
		<nav class="rc-forum__abas" aria-label="<?php esc_attr_e( 'Ordenação das perguntas', 'reconectar' ); ?>">
			<ul>
				<?php foreach ( reconectar_forum_abas() as $chave => $rotulo ) : ?>
					<?php $ativa = $chave === $filtros['aba']; ?>
					<li>
						<a
							class="rc-forum__aba<?php echo $ativa ? ' is-ativa' : ''; ?>"
							href="<?php echo esc_url( reconectar_forum_url( $filtros, array( 'aba' => $chave, 'pagina' => 1 ) ) ); ?>"
							<?php echo $ativa ? ' aria-current="page"' : ''; ?>
						>
							<?php echo esc_html( $rotulo ); ?>
						</a>
					</li>
				<?php endforeach; ?>
			</ul>
		</nav>

		<form class="rc-forum__form-filtro" method="get" action="<?php echo esc_url( reconectar_forum_url_base() ); ?>">
			<?php
			/*
			 * A aba viaja em campo oculto: sem ele, filtrar por categoria jogaria o
			 * usuário de volta para "Recentes" sem que nada na tela explicasse por quê.
			 */
			?>
			<?php if ( 'recentes' !== $filtros['aba'] ) : ?>
				<input type="hidden" name="ordem" value="<?php echo esc_attr( $filtros['aba'] ); ?>" />
			<?php endif; ?>

			<?php if ( $categorias ) : ?>
				<p class="rc-forum__campo">
					<label class="rc-forum__rotulo" for="rc-forum-categoria">
						<?php esc_html_e( 'Filtrar por categoria', 'reconectar' ); ?>
					</label>
					<select class="rc-forum__select" id="rc-forum-categoria" name="categoria">
						<option value="0"><?php esc_html_e( 'Todas as categorias', 'reconectar' ); ?></option>
						<?php foreach ( $categorias as $categoria ) : ?>
							<option value="<?php echo esc_attr( $categoria->ID ); ?>" <?php selected( $filtros['categoria'], $categoria->ID ); ?>>
								<?php echo esc_html( get_the_title( $categoria ) ); ?>
							</option>
						<?php endforeach; ?>
					</select>
				</p>
			<?php endif; ?>

			<p class="rc-forum__campo">
				<label class="rc-forum__rotulo" for="rc-forum-busca">
					<?php esc_html_e( 'Buscar nas perguntas', 'reconectar' ); ?>
				</label>
				<input
					class="rc-forum__busca"
					type="search"
					id="rc-forum-busca"
					name="q"
					value="<?php echo esc_attr( $filtros['busca'] ); ?>"
					placeholder="<?php esc_attr_e( 'Buscar…', 'reconectar' ); ?>"
				/>
			</p>

			<button type="submit" class="rc-forum__aplicar"><?php esc_html_e( 'Aplicar', 'reconectar' ); ?></button>
		</form>
	</div>
	<?php
}

/**
 * Imprime a paginação da listagem.
 *
 * Os links são montados aqui, e não por `paginate_links()`. A razão é a mesma que
 * leva a paginação a usar `?pagina=` em vez do `/page/2/` nativo: a listagem já
 * carrega aba, categoria e busca na query string, e misturar os dois formatos
 * daria URLs em que o filtro se perde ao virar a página. `paginate_links()`
 * monta o endereço a partir de `$wp_rewrite` e do `$_SERVER['REQUEST_URI']`, e
 * dobrá-la para preservar três parâmetros daria mais código que o laço abaixo.
 *
 * O laço imprime todas as páginas, sem reticências. É uma decisão de escala: com
 * 12 perguntas por página, o número de páginas deste fórum cabe numa linha por
 * muito tempo. Quando não couber, o lugar de resolver é aqui.
 *
 * @param array $filtros Filtros ativos.
 * @param int   $paginas Total de páginas.
 */
function reconectar_forum_paginacao( $filtros, $paginas ) {
	if ( 2 > $paginas ) {
		return;
	}

	$links = array();

	for ( $pagina = 1; $pagina <= $paginas; $pagina++ ) {
		$atual = $pagina === $filtros['pagina'];

		$links[] = sprintf(
			'<li><a class="rc-forum__pagina%1$s" href="%2$s"%3$s>%4$s</a></li>',
			$atual ? ' is-atual' : '',
			esc_url( reconectar_forum_url( $filtros, array( 'pagina' => $pagina ) ) ),
			$atual ? ' aria-current="page"' : '',
			esc_html( number_format_i18n( $pagina ) )
		);
	}
	?>
	<nav class="rc-forum__paginacao" aria-label="<?php esc_attr_e( 'Páginas de perguntas', 'reconectar' ); ?>">
		<ul>
			<?php echo wp_kses_post( implode( '', $links ) ); ?>
		</ul>
	</nav>
	<?php
}

/**
 * Imprime o estado vazio.
 *
 * O texto muda conforme haja ou não filtro aplicado: "nenhuma pergunta ainda" e
 * "nenhuma pergunta com esse filtro" são situações diferentes, e a segunda pede
 * um caminho de volta.
 *
 * @param array $filtros Filtros ativos.
 */
function reconectar_forum_vazio( $filtros ) {
	$filtrado = $filtros['categoria'] || '' !== $filtros['busca'] || 'recentes' !== $filtros['aba'];
	?>
	<div class="rc-forum__vazio">
		<?php if ( $filtrado ) : ?>
			<p><?php esc_html_e( 'Nenhuma pergunta corresponde a esses filtros.', 'reconectar' ); ?></p>
			<p>
				<a href="<?php echo esc_url( reconectar_forum_url_base() ); ?>">
					<?php esc_html_e( 'Ver todas as perguntas', 'reconectar' ); ?>
				</a>
			</p>
		<?php else : ?>
			<p><?php esc_html_e( 'Ainda não há perguntas por aqui.', 'reconectar' ); ?></p>
			<?php if ( current_user_can( 'publish_topics' ) ) : ?>
				<p>
					<a href="<?php echo esc_url( reconectar_forum_url_da_pergunta( $filtros ) ); ?>">
						<?php esc_html_e( 'Seja a primeira pessoa a perguntar', 'reconectar' ); ?>
					</a>
				</p>
			<?php endif; ?>
		<?php endif; ?>
	</div>
	<?php
}
