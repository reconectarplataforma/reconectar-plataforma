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
 * "Nova página" fica no topo da árvore, e não ao pé: com cinquenta páginas, o
 * pé está fora da tela. Nasce `hidden` pelo motivo dos botões da página —
 * sem script ele não faria nada.
 *
 * A busca fica **fora** do `<details>`: no celular a árvore nasce recolhida, e
 * a busca recolhida junto seria a busca que ninguém acha. É um `<form>` GET
 * comum, que funciona sem script, e vai à âncora — a única tela que a lê.
 *
 * @package reconectar-core
 *
 * @var array $contexto
 */

defined( 'ABSPATH' ) || exit;

$rc_pagina   = $contexto['pagina'];
$rc_url_raiz = $contexto['url_raiz'];
$rc_destino  = $rc_url_raiz ? wp_parse_url( $rc_url_raiz ) : array();
$rc_ocultos  = array();

// Com permalink simples a âncora é `/?page_id=N`, e um `<form>` GET troca a
// query string do `action` inteira pela dos campos: o `page_id` iria embora e
// a busca cairia na home. Os parâmetros da âncora voltam como campos ocultos.
if ( isset( $rc_destino['query'] ) ) {
	wp_parse_str( $rc_destino['query'], $rc_ocultos );
}
?>
<div class="rc-incubadora">
	<a class="rc-incubadora__pular" href="#rc-incubadora-conteudo"><?php esc_html_e( 'Pular a árvore de páginas', 'reconectar-core' ); ?></a>

	<aside class="rc-incubadora__lateral">
		<?php if ( $rc_url_raiz ) : ?>
			<form class="rc-incubadora__busca" role="search" method="get" action="<?php echo esc_url( isset( $rc_destino['path'] ) ? $rc_destino['path'] : '/' ); ?>">
				<label class="rc-incubadora__busca-rotulo" for="rc-incubadora-busca"><?php esc_html_e( 'Buscar na Incubadora', 'reconectar-core' ); ?></label>
				<div class="rc-incubadora__busca-linha">
					<input class="rc-incubadora__busca-campo" type="search" id="rc-incubadora-busca" name="<?php echo esc_attr( Reconectar_Incubadora_Busca::PARAM ); ?>" value="<?php echo esc_attr( Reconectar_Incubadora_Busca::pedida() ? Reconectar_Incubadora_Busca::texto_pedido() : '' ); ?>" maxlength="<?php echo esc_attr( Reconectar_Incubadora_Busca::MAXIMO ); ?>" autocomplete="off">
					<?php foreach ( $rc_ocultos as $rc_chave => $rc_valor ) : ?>
						<?php if ( is_string( $rc_valor ) ) : ?>
							<input type="hidden" name="<?php echo esc_attr( $rc_chave ); ?>" value="<?php echo esc_attr( $rc_valor ); ?>">
						<?php endif; ?>
					<?php endforeach; ?>
					<button type="submit" class="rc-incubadora__botao rc-incubadora__busca-botao"><?php esc_html_e( 'Buscar', 'reconectar-core' ); ?></button>
				</div>
			</form>
		<?php endif; ?>

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

				<?php if ( Reconectar_Incubadora_Leitura::ve_rascunho() ) : ?>
					<button type="button" class="rc-incubadora__botao rc-incubadora__nova" data-rc-incubadora="criar" data-rc-mae="0" hidden><?php esc_html_e( 'Nova página', 'reconectar-core' ); ?></button>
				<?php endif; ?>

				<div class="rc-incubadora__arvore-corpo">
					<?php echo Reconectar_Incubadora_Leitura::html_do_template( 'arvore-corpo.php', $contexto ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escapado no template. ?>
				</div>
			</nav>
		</details>
	</aside>

	<div class="rc-incubadora__principal" id="rc-incubadora-conteudo" tabindex="-1">
		<?php if ( $rc_pagina instanceof WP_Post ) : ?>
			<?php echo Reconectar_Incubadora_Leitura::html_do_template( 'trilha.php', $contexto ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escapado no template. ?>

			<?php
			// Lista e versão antiga no lugar da leitura, com a mesma árvore e a
			// mesma trilha: quem está no histórico continua sabendo em que
			// página está.
			if ( 'historico' === $contexto['modo'] ) {
				include __DIR__ . '/historico.php';
			} elseif ( 'versao' === $contexto['modo'] && $contexto['versao'] instanceof WP_Post ) {
				include __DIR__ . '/versao.php';
			} else {
				include __DIR__ . '/leitura.php';
			}
			?>
		<?php elseif ( 'busca' === $contexto['modo'] ) : ?>
			<?php include __DIR__ . '/busca.php'; ?>
		<?php else : ?>
			<?php include __DIR__ . '/indice.php'; ?>
		<?php endif; ?>
	</div>
</div>
