<?php
/**
 * Listagem completa de categorias do catálogo.
 *
 * O carrossel da home mostra 14 categorias de primeiro nível e oferece um
 * "Ver todos" ao lado do título. Até aqui esse link apontava para `/shop/`, o
 * catálogo de **produtos**: quem clicava esperando ver as categorias que não
 * couberam na faixa caía numa lista de itens à venda. É o mesmo defeito que já
 * havia sido corrigido no carrossel de lojas em destaque — lá o "Ver todos"
 * recarregava a própria home.
 *
 * Este arquivo cria o destino que faltava. A página é montada por shortcode e
 * não por template porque é assim que as demais páginas autorais da plataforma
 * funcionam (`[reconectar_painel_empresas]`, `[reconectar_painel_transparencia]`), e porque uma
 * página no banco pode ser renomeada, movida ou tirada do menu pelo
 * administrador sem exigir alteração de código.
 *
 * @package reconectar
 */

defined( 'ABSPATH' ) || exit;

/**
 * Categorias de produto agrupadas por categoria-mãe.
 *
 * A ordenação é alfabética, e não por quantidade de produtos como em
 * `reconectar_obter_categorias()`. Os dois critérios servem a telas diferentes:
 * a faixa da home precisa colocar as categorias mais movimentadas nas primeiras
 * posições, porque só 14 cabem e as demais ficam fora; aqui está tudo, e o que
 * uma listagem completa precisa oferecer é previsibilidade — quem procura
 * "Artesanato" quer encontrá-lo onde o alfabeto manda.
 *
 * O `hide_empty` deixa de fora as categorias sem produto. Sem ele a página
 * ofereceria caminhos que terminam em lista vazia, e a promessa de um cartão de
 * categoria é que exista algo do outro lado.
 *
 * Uma subcategoria com produtos cuja mãe esteja vazia é um caso real: a mãe não
 * volta da consulta, e sem tratamento a filha ficaria órfã, fora de todos os
 * grupos e portanto invisível na página. Por isso a mãe ausente é buscada em
 * separado — ela entra como título do grupo mesmo sem produtos próprios, que é
 * exatamente o papel dela ali.
 *
 * @return array<int,array{termo:WP_Term,filhas:WP_Term[]}> Grupos na ordem de exibição.
 */
function reconectar_obter_categorias_agrupadas() {
	$termos = get_terms(
		array(
			'taxonomy'   => 'product_cat',
			'hide_empty' => true,
			'orderby'    => 'name',
			'order'      => 'ASC',
		)
	);

	if ( is_wp_error( $termos ) || ! $termos ) {
		return array();
	}

	$grupos = array();
	$filhas = array();

	foreach ( $termos as $termo ) {
		if ( $termo->parent ) {
			$filhas[ $termo->parent ][] = $termo;
			continue;
		}

		$grupos[ $termo->term_id ] = array(
			'termo'  => $termo,
			'filhas' => array(),
		);
	}

	foreach ( $filhas as $mae_id => $lista ) {
		if ( ! isset( $grupos[ $mae_id ] ) ) {
			$mae = get_term( $mae_id, 'product_cat' );

			// Uma mãe que não é termo válido — apagada com as filhas ainda
			// apontando para ela — descartaria o grupo inteiro em silêncio. As
			// filhas seguem para o fim da página, sob um grupo sem título, em
			// vez de sumirem.
			if ( ! $mae || is_wp_error( $mae ) ) {
				continue;
			}

			$grupos[ $mae_id ] = array(
				'termo'  => $mae,
				'filhas' => array(),
			);
		}

		$grupos[ $mae_id ]['filhas'] = $lista;
	}

	// A ordem do array reflete a consulta, que só ordenou o primeiro nível; as
	// mães recuperadas acima entraram no fim. Reordenar aqui mantém a promessa
	// alfabética do bloco inteiro.
	uasort(
		$grupos,
		static function ( $a, $b ) {
			return strnatcasecmp( $a['termo']->name, $b['termo']->name );
		}
	);

	return array_values( $grupos );
}

/**
 * Imprime a listagem completa de categorias.
 *
 * Cada categoria-mãe vira uma seção com as suas subcategorias em grade. A mãe
 * também é clicável: o título leva ao arquivo dela, que reúne os produtos de
 * todas as filhas — sem isso, uma mãe sem subcategoria não teria para onde
 * levar, e uma com subcategorias perderia a visão do conjunto.
 *
 * Os cartões são os mesmos de `reconectar_card_categoria()`, usados na faixa da
 * home. Repetir o desenho aqui daria duas linguagens visuais para a mesma
 * entidade.
 *
 * @return string Markup da listagem, ou aviso quando não há categorias.
 */
function reconectar_shortcode_categorias() {
	$grupos = reconectar_obter_categorias_agrupadas();

	ob_start();

	if ( ! $grupos ) {
		?>
		<p class="rc-categorias__vazio">
			<?php esc_html_e( 'Nenhuma categoria com produtos no momento.', 'reconectar' ); ?>
		</p>
		<?php

		return (string) ob_get_clean();
	}

	?>
	<div class="rc-categorias">
		<?php foreach ( $grupos as $grupo ) : ?>
			<?php
			$termo = $grupo['termo'];
			$url   = get_term_link( $termo );

			// `get_term_link()` devolve `WP_Error` quando o termo deixou de ser
			// resolvível, e `esc_url()` receberia um objeto no lugar da string.
			// O grupo sai da página em vez de imprimir um `href` quebrado.
			if ( is_wp_error( $url ) ) {
				continue;
			}

			$id_termo = 'rc-categoria-' . (int) $termo->term_id;
			?>
			<section class="rc-categorias__grupo" aria-labelledby="<?php echo esc_attr( $id_termo ); ?>">
				<h2 class="rc-categorias__titulo" id="<?php echo esc_attr( $id_termo ); ?>">
					<a href="<?php echo esc_url( $url ); ?>">
						<?php echo esc_html( $termo->name ); ?>
					</a>
				</h2>

				<?php if ( $grupo['filhas'] ) : ?>
					<ul class="rc-categorias__grade">
						<?php foreach ( $grupo['filhas'] as $filha ) : ?>
							<li><?php reconectar_card_categoria( $filha ); ?></li>
						<?php endforeach; ?>
					</ul>
				<?php else : ?>
					<ul class="rc-categorias__grade">
						<li><?php reconectar_card_categoria( $termo ); ?></li>
					</ul>
				<?php endif; ?>

				<p class="rc-categorias__todos">
					<a href="<?php echo esc_url( $url ); ?>">
						<?php
						printf(
							/* translators: %s: nome da categoria. */
							esc_html__( 'Ver todos os produtos de %s', 'reconectar' ),
							esc_html( $termo->name )
						);
						?>
					</a>
				</p>
			</section>
		<?php endforeach; ?>
	</div>
	<?php

	return (string) ob_get_clean();
}
add_shortcode( 'reconectar_categorias', 'reconectar_shortcode_categorias' );
