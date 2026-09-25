<?php
/**
 * Paginação numerada do catálogo.
 *
 * Override de `woocommerce/templates/loop/pagination.php` (@version 9.3.0). Três
 * diferenças em relação ao original, todas de acessibilidade e idioma:
 *
 * O `aria-label` do `<nav>` era "Product Pagination", em inglês. O domínio de
 * tradução ali é `woocommerce`, e depender de o `.po` do plugin cobrir a string
 * é o tipo de aposta que já custou caro neste repositório — a do tema resolve
 * de uma vez.
 *
 * As setas eram `&larr;` e `&rarr;` puros. O nome acessível do link passava a
 * ser a própria seta: quem usa leitor de tela ouvia "→, link", sem saber para
 * onde. É falha do critério 2.4.4 da WCAG 2.1, que o edital exige. Aqui a seta
 * é decoração (`aria-hidden`) e o nome vem do texto oculto ao lado.
 *
 * A classe `rc-paginacao` entra ao lado da do WooCommerce para que o CSS do tema
 * não precise pendurar o layout num seletor de terceiro — se um dia o plugin
 * renomear `woocommerce-pagination`, o que quebra é o reset dele, não o nosso.
 *
 * @package reconectar
 */

defined( 'ABSPATH' ) || exit;

$total   = isset( $total ) ? $total : wc_get_loop_prop( 'total_pages' );
$current = isset( $current ) ? $current : wc_get_loop_prop( 'current_page' );
$base    = isset( $base ) ? $base : esc_url_raw( str_replace( 999999999, '%#%', remove_query_arg( 'add-to-cart', get_pagenum_link( 999999999, false ) ) ) );
$format  = isset( $format ) ? $format : '';

if ( $total <= 1 ) {
	return;
}

// A seta aponta para a direita em "próxima" e para a esquerda em "anterior" — e
// troca de lado em RTL, como no original. O texto acessível não troca.
$reconectar_seta_anterior = is_rtl() ? '&rarr;' : '&larr;';
$reconectar_seta_proxima  = is_rtl() ? '&larr;' : '&rarr;';

$reconectar_rotulo_anterior = sprintf(
	'<span aria-hidden="true">%1$s</span><span class="screen-reader-text">%2$s</span>',
	$reconectar_seta_anterior,
	esc_html__( 'Página anterior', 'reconectar' )
);

$reconectar_rotulo_proxima = sprintf(
	'<span aria-hidden="true">%1$s</span><span class="screen-reader-text">%2$s</span>',
	$reconectar_seta_proxima,
	esc_html__( 'Próxima página', 'reconectar' )
);
?>
<nav class="woocommerce-pagination rc-paginacao" aria-label="<?php esc_attr_e( 'Paginação de produtos', 'reconectar' ); ?>">
	<?php
	echo paginate_links( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- `paginate_links()` escapa as URLs; os rótulos são montados acima.
		apply_filters(
			'woocommerce_pagination_args',
			array(
				'base'      => $base,
				'format'    => $format,
				'add_args'  => false,
				'current'   => max( 1, $current ),
				'total'     => $total,
				'prev_text' => $reconectar_rotulo_anterior,
				'next_text' => $reconectar_rotulo_proxima,
				'type'      => 'list',
				'end_size'  => 3,
				'mid_size'  => 3,
			)
		)
	);
	?>
</nav>
