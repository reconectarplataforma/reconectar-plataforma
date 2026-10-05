<?php
/**
 * Associa ícones SVG às categorias de produto.
 *
 *   wp eval-file /var/www/scripts/icones-de-categoria.php
 *
 * Chamado pelo `provision.sh` e, ao fim da instalação, pela carga de
 * demonstração (`seed/demo.php`): o `demo-completa.sh` provisiona **antes** de
 * popular, e as categorias da carga ainda não existem quando o provisionamento
 * passa por aqui.
 *
 * Os desenhos ficam em `themes/reconectar/assets/icones/categorias/`, e o termo
 * guarda só o nome deles na meta `_reconectar_categoria_icone`. Ver
 * `reconectar_icone_de_categoria()` para a razão de não serem anexos.
 *
 * Idempotente, e só aditivo: termo que já tem ícone não é tocado, então a
 * escolha feita depois por quem administra sobrevive a um novo provisionamento.
 * O script também não cria categoria nenhuma — categoria é cadastro, e quem as
 * cria são o administrador e a carga.
 *
 * O termo é encontrado pelo slug, e só nesta primeira associação. Depois dela o
 * vínculo é a meta, e renomear a categoria ou trocar o slug não o desfaz.
 * Serviços é a exceção: ela tem identidade estável própria
 * (`_reconectar_categoria_chave`), gravada pelo `provision.sh`, e é por ela que
 * se procura.
 *
 * @package reconectar
 */

defined( 'ABSPATH' ) || exit;

$reconectar_icones_por_slug = array(
	// Categorias-mãe do conjunto de partida (`docs/CADASTRO_MANUAL.md`, 2.4).
	'alimentos-e-bebidas'  => 'alimentos-e-bebidas',
	'artesanato'           => 'artesanato',
	'moda-e-acessorios'    => 'moda-e-acessorios',
	'casa-e-decoracao'     => 'casa-e-decoracao',
	'beleza-e-cuidados'    => 'beleza-e-cuidados',
	'cultura-e-educacao'   => 'cultura-e-educacao',

	// Subcategorias da carga de demonstração.
	'farinaceos'           => 'farinaceos',
	'doces-e-conservas'    => 'doces-e-conservas',
	'bebidas-da-terra'     => 'bebidas-da-terra',
	'bordados'             => 'bordados',
	'ceramica'             => 'ceramica',
	'fibras-naturais'      => 'fibras-naturais',
	'roupas'               => 'roupas',
	'bolsas-e-mochilas'    => 'bolsas-e-mochilas',
	'acessorios-de-moda'   => 'acessorios-de-moda',
	'decoracao'            => 'decoracao',
	'iluminacao'           => 'iluminacao',
	'moveis-e-prateleiras' => 'moveis-e-prateleiras',
	'sabonetes'            => 'sabonetes',
	'oleos-e-hidratantes'  => 'oleos-e-hidratantes',
	'kits-e-presentes'     => 'kits-e-presentes',
);

$reconectar_icones_dir = get_theme_root() . '/reconectar/assets/icones/categorias';

/**
 * Grava o ícone num termo, se ele ainda não tiver um.
 *
 * Confere o arquivo antes de gravar: um nome sem desenho correspondente faria o
 * card cair na miniatura ou na inicial sem aviso nenhum, e o log diria que o
 * ícone foi aplicado.
 *
 * @param int    $term_id Termo de `product_cat`.
 * @param string $icone   Nome do arquivo, sem `.svg`.
 * @param string $rotulo  Como o termo aparece no log.
 * @param string $dir     Diretório dos ícones.
 */
function reconectar_aplicar_icone_de_categoria( $term_id, $icone, $rotulo, $dir ) {
	$atual = (string) get_term_meta( $term_id, '_reconectar_categoria_icone', true );

	if ( '' !== $atual ) {
		WP_CLI::log( "  = $rotulo já tem ícone ($atual)." );
		return;
	}

	if ( ! is_readable( "$dir/$icone.svg" ) ) {
		WP_CLI::warning( "  Ícone $icone.svg não encontrado em $dir; $rotulo segue sem ícone." );
		return;
	}

	update_term_meta( $term_id, '_reconectar_categoria_icone', $icone );
	WP_CLI::log( "  + $rotulo: ícone $icone." );
}

foreach ( $reconectar_icones_por_slug as $reconectar_slug => $reconectar_icone ) {
	$reconectar_termo = get_term_by( 'slug', $reconectar_slug, 'product_cat' );

	if ( ! $reconectar_termo ) {
		continue;
	}

	reconectar_aplicar_icone_de_categoria(
		(int) $reconectar_termo->term_id,
		$reconectar_icone,
		$reconectar_termo->name,
		$reconectar_icones_dir
	);
}

// `meta_query` e `orderby` explícito, nunca `meta_key`: em `product_cat` o
// WooCommerce troca o `meta_key` pelo da ordenação e a busca volta vazia.
$reconectar_servicos = get_terms(
	array(
		'taxonomy'   => 'product_cat',
		'hide_empty' => false,
		'orderby'    => 'term_id',
		'meta_query' => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
			array(
				'key'   => '_reconectar_categoria_chave',
				'value' => 'servicos',
			),
		),
	)
);

if ( ! is_wp_error( $reconectar_servicos ) ) {
	foreach ( $reconectar_servicos as $reconectar_termo ) {
		reconectar_aplicar_icone_de_categoria(
			(int) $reconectar_termo->term_id,
			'servicos',
			$reconectar_termo->name,
			$reconectar_icones_dir
		);
	}
}
