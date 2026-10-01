<?php
/**
 * Moldura da Incubadora: árvore lateral à esquerda, trilha e conteúdo à direita.
 *
 * Recebe `$contexto` de `Reconectar_Incubadora_Leitura::renderizar()`.
 *
 * A árvore vai dentro de um `<details>` **fechado**. No celular é o colapso que
 * impede a árvore inteira de empurrar a página para baixo da dobra; a partir de
 * 992px o CSS força o conteúdo visível com o `<details>` fechado mesmo, pelos
 * dois mecanismos que o `.rc-menu` do cabeçalho já documenta. `open` no HTML
 * abriria a árvore também no celular.
 *
 * O "Pular a árvore" vem antes dela na ordem do DOM: numa wiki de cinquenta
 * páginas, quem navega por teclado atravessaria cinquenta links a cada página
 * aberta antes de chegar ao texto.
 *
 * @package reconectar-core
 *
 * @var array $contexto
 */

defined( 'ABSPATH' ) || exit;

$rc_pagina   = $contexto['pagina'];
$rc_url_raiz = $contexto['url_raiz'];
?>
<div class="rc-incubadora">
	<a class="rc-incubadora__pular" href="#rc-incubadora-conteudo"><?php esc_html_e( 'Pular a árvore de páginas', 'reconectar-core' ); ?></a>

	<aside class="rc-incubadora__lateral">
		<details class="rc-incubadora__gaveta">
			<summary class="rc-incubadora__gatilho"><?php esc_html_e( 'Páginas da Incubadora', 'reconectar-core' ); ?></summary>

			<nav class="rc-incubadora__arvore" aria-label="<?php esc_attr_e( 'Páginas da Incubadora', 'reconectar-core' ); ?>">
				<p class="rc-incubadora__espaco">
					<?php if ( $rc_url_raiz ) : ?>
						<a href="<?php echo esc_url( $rc_url_raiz ); ?>"><?php esc_html_e( 'Incubadora', 'reconectar-core' ); ?></a>
					<?php else : ?>
						<?php esc_html_e( 'Incubadora', 'reconectar-core' ); ?>
					<?php endif; ?>
				</p>

				<?php if ( empty( $contexto['filhos'][0] ) ) : ?>
					<p class="rc-incubadora__arvore-vazia"><?php esc_html_e( 'Nenhuma página ainda.', 'reconectar-core' ); ?></p>
				<?php else : ?>
					<?php Reconectar_Incubadora_Leitura::imprimir_ramo( 0, $contexto ); ?>
				<?php endif; ?>
			</nav>
		</details>
	</aside>

	<div class="rc-incubadora__principal" id="rc-incubadora-conteudo" tabindex="-1">
		<?php if ( $rc_pagina instanceof WP_Post ) : ?>
			<nav class="rc-incubadora__trilha" aria-label="<?php esc_attr_e( 'Trilha da Incubadora', 'reconectar-core' ); ?>">
				<ol>
					<?php if ( $rc_url_raiz ) : ?>
						<li><a href="<?php echo esc_url( $rc_url_raiz ); ?>"><?php esc_html_e( 'Incubadora', 'reconectar-core' ); ?></a></li>
					<?php endif; ?>
					<?php foreach ( $contexto['caminho'] as $rc_ancestral ) : ?>
						<li><a href="<?php echo esc_url( get_permalink( $rc_ancestral ) ); ?>"><?php echo esc_html( get_the_title( $rc_ancestral ) ); ?></a></li>
					<?php endforeach; ?>
					<li><span aria-current="page"><?php echo esc_html( get_the_title( $rc_pagina ) ); ?></span></li>
				</ol>
			</nav>

			<?php include __DIR__ . '/leitura.php'; ?>
		<?php else : ?>
			<?php include __DIR__ . '/indice.php'; ?>
		<?php endif; ?>
	</div>
</div>
