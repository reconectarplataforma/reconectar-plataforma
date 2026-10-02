<?php
/**
 * Um nível da árvore lateral. Incluído por `imprimir_ramo()`, que recursa.
 *
 * Nó com filhas é `<a>` seguido de `<details>`, e o `<summary>` traz só o
 * chevron. O link não pode ir dentro do `<summary>`: conteúdo interativo ali
 * dentro é anunciado como parte do botão de expandir, e o clique no título
 * abriria o ramo em vez de navegar. E não pode ir dentro do `<details>` depois
 * do `<summary>`, porque o `<details>` fechado esconderia o próprio título. O
 * CSS põe o chevron à esquerda do título, na mesma linha; no DOM ele vem
 * depois, e a tabulação lê título, expandir, filhas — a ordem em que se lê.
 *
 * `open` só nos ancestrais da página aberta e nela própria. Aqui ele não tem o
 * problema do `<details open>` no celular: a árvore inteira já está dentro da
 * gaveta fechada da moldura.
 *
 * `data-rc-id` no `<li>` é o que o arrastar e o "Mover…" leem para montar a
 * ordem das irmãs: a árvore inteira está no DOM, fechada ou não, e é ela a
 * cópia que a pessoa vê da estrutura — o servidor confere se ainda é a atual.
 *
 * @package reconectar-core
 *
 * @var int   $mae
 * @var array $contexto
 * @var int   $nivel
 * @var int[] $abertos
 */

defined( 'ABSPATH' ) || exit;
?>
<ul class="rc-incubadora__ramo">
	<?php foreach ( $contexto['filhos'][ $mae ] as $rc_no ) : ?>
		<?php
		$rc_id         = (int) $rc_no->ID;
		$rc_titulo     = get_the_title( $rc_no );
		$rc_titulo     = '' !== $rc_titulo ? $rc_titulo : __( '(sem título)', 'reconectar-core' );
		$rc_tem_filhas = ! empty( $contexto['filhos'][ $rc_id ] ) && $nivel <= Reconectar_Incubadora::PROFUNDIDADE_MAXIMA;
		$rc_e_atual    = $rc_id === (int) $contexto['atual'];
		$rc_aberto     = $rc_e_atual || in_array( $rc_id, $abertos, true );
		?>
		<li class="rc-incubadora__no<?php echo $rc_tem_filhas ? ' rc-incubadora__no--com-filhas' : ''; ?>" data-rc-id="<?php echo esc_attr( $rc_id ); ?>">
			<a class="rc-incubadora__no-link" href="<?php echo esc_url( get_permalink( $rc_no ) ); ?>"<?php echo $rc_e_atual ? ' aria-current="page"' : ''; ?>>
				<span class="rc-incubadora__no-titulo"><?php echo esc_html( $rc_titulo ); ?></span>
				<?php if ( 'draft' === $rc_no->post_status ) : ?>
					<span class="rc-incubadora__selo"><?php esc_html_e( 'Rascunho', 'reconectar-core' ); ?></span>
				<?php endif; ?>
			</a>

			<?php if ( $rc_tem_filhas ) : ?>
				<details class="rc-incubadora__no-gaveta"<?php echo $rc_aberto ? ' open' : ''; ?>>
					<summary class="rc-incubadora__no-gatilho">
						<span class="screen-reader-text">
							<?php
							/* translators: %s: título da página mãe. */
							echo esc_html( sprintf( __( 'Subpáginas de %s', 'reconectar-core' ), $rc_titulo ) );
							?>
						</span>
					</summary>
					<?php Reconectar_Incubadora_Leitura::imprimir_ramo( $rc_id, $contexto, $nivel + 1 ); ?>
				</details>
			<?php endif; ?>
		</li>
	<?php endforeach; ?>
</ul>
