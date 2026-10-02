<?php
/**
 * Resultados da busca na Incubadora.
 *
 * Incluído por `shell.php` no modo `busca`. O campo de busca é o da lateral,
 * já preenchido com o texto: um segundo campo aqui daria dois marcos de busca
 * na mesma tela, e o leitor de tela os anunciaria como iguais.
 *
 * Cada resultado tem um `<h2>`: quem navega por cabeçalhos pula de um
 * resultado ao outro sem atravessar trechos. O título e o trecho chegam já
 * escapados de `Reconectar_Incubadora_Busca`, com o `<mark>` como única
 * marcação — ver `montar()`.
 *
 * A paginação é um `<nav>` com nome próprio, para não se confundir com a
 * árvore, e a página corrente sai em texto, não em link.
 *
 * @package reconectar-core
 */

defined( 'ABSPATH' ) || exit;

$rc_busca = Reconectar_Incubadora_Busca::resultado();
?>
<section class="rc-incubadora__busca-resultados" aria-labelledby="rc-incubadora-titulo">
	<h1 class="rc-incubadora__titulo" id="rc-incubadora-titulo"><?php esc_html_e( 'Busca na Incubadora', 'reconectar-core' ); ?></h1>

	<?php if ( $rc_busca['curto'] ) : ?>
		<p class="rc-incubadora__estado-vazio">
			<?php
			echo esc_html(
				sprintf(
					/* translators: %d: mínimo de caracteres. */
					_n( 'Escreva ao menos %d caractere para buscar.', 'Escreva ao menos %d caracteres para buscar.', Reconectar_Incubadora_Busca::MINIMO, 'reconectar-core' ),
					Reconectar_Incubadora_Busca::MINIMO
				)
			);
			?>
		</p>
	<?php elseif ( ! $rc_busca['total'] ) : ?>
		<div class="rc-incubadora__estado-vazio">
			<p>
				<?php
				/* translators: %s: texto buscado. */
				echo esc_html( sprintf( __( 'Nenhuma página encontrada para “%s”.', 'reconectar-core' ), $rc_busca['texto'] ) );
				?>
			</p>
			<p><?php esc_html_e( 'A busca procura todas as palavras no título e no texto, sem diferença de acento nem de maiúscula. Tente menos palavras ou uma palavra mais curta.', 'reconectar-core' ); ?></p>
		</div>
	<?php else : ?>
		<p class="rc-incubadora__autoria">
			<?php
			echo esc_html(
				sprintf(
					/* translators: 1: quantidade de resultados, 2: texto buscado. */
					_n( '%1$d página encontrada para “%2$s”.', '%1$d páginas encontradas para “%2$s”.', $rc_busca['total'], 'reconectar-core' ),
					$rc_busca['total'],
					$rc_busca['texto']
				)
			);
			?>
		</p>

		<ol class="rc-incubadora__resultados" start="<?php echo esc_attr( ( $rc_busca['pagina'] - 1 ) * Reconectar_Incubadora_Busca::POR_PAGINA + 1 ); ?>">
			<?php foreach ( $rc_busca['itens'] as $rc_item ) : ?>
				<li class="rc-incubadora__resultado">
					<h2 class="rc-incubadora__resultado-titulo">
						<a href="<?php echo esc_url( get_permalink( $rc_item['pagina'] ) ); ?>">
							<?php
							// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escapado pedaço a pedaço em `Reconectar_Incubadora_Busca::montar()`.
							echo '' !== $rc_item['titulo'] ? $rc_item['titulo'] : esc_html__( '(sem título)', 'reconectar-core' );
							?>
						</a>
					</h2>

					<?php if ( $rc_item['caminho'] || 'draft' === $rc_item['pagina']->post_status ) : ?>
						<p class="rc-incubadora__resultado-meta">
							<?php if ( 'draft' === $rc_item['pagina']->post_status ) : ?>
								<span class="rc-incubadora__selo"><?php esc_html_e( 'Rascunho', 'reconectar-core' ); ?></span>
							<?php endif; ?>
							<?php if ( $rc_item['caminho'] ) : ?>
								<span>
									<span class="screen-reader-text"><?php esc_html_e( 'Em:', 'reconectar-core' ); ?></span>
									<?php echo esc_html( implode( ' / ', array_map( 'get_the_title', $rc_item['caminho'] ) ) ); ?>
								</span>
							<?php endif; ?>
						</p>
					<?php endif; ?>

					<?php if ( '' !== $rc_item['trecho'] ) : ?>
						<p class="rc-incubadora__resultado-trecho"><?php echo $rc_item['trecho']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escapado pedaço a pedaço em `Reconectar_Incubadora_Busca::montar()`. ?></p>
					<?php endif; ?>
				</li>
			<?php endforeach; ?>
		</ol>

		<?php if ( $rc_busca['paginas'] > 1 ) : ?>
			<nav class="rc-incubadora__paginacao" aria-label="<?php esc_attr_e( 'Páginas de resultados', 'reconectar-core' ); ?>">
				<?php if ( $rc_busca['pagina'] > 1 ) : ?>
					<a class="rc-incubadora__botao" rel="prev" href="<?php echo esc_url( Reconectar_Incubadora_Busca::url( $rc_busca['texto'], $rc_busca['pagina'] - 1 ) ); ?>"><?php esc_html_e( 'Anteriores', 'reconectar-core' ); ?></a>
				<?php endif; ?>
				<span class="rc-incubadora__paginacao-atual" aria-current="page">
					<?php
					/* translators: 1: página corrente, 2: total de páginas. */
					echo esc_html( sprintf( __( 'Página %1$d de %2$d', 'reconectar-core' ), $rc_busca['pagina'], $rc_busca['paginas'] ) );
					?>
				</span>
				<?php if ( $rc_busca['pagina'] < $rc_busca['paginas'] ) : ?>
					<a class="rc-incubadora__botao" rel="next" href="<?php echo esc_url( Reconectar_Incubadora_Busca::url( $rc_busca['texto'], $rc_busca['pagina'] + 1 ) ); ?>"><?php esc_html_e( 'Próximas', 'reconectar-core' ); ?></a>
				<?php endif; ?>
			</nav>
		<?php endif; ?>
	<?php endif; ?>
</section>
