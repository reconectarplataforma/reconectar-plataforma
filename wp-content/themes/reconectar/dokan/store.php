<?php
/**
 * Página de uma loja — override do template do Dokan.
 *
 * O Dokan resolve esta página com `dokan_locate_template( 'store.php' )`
 * (`dokan-lite/includes/Rewrites.php:211`), e essa função procura o arquivo em
 * `dokan/` dentro do tema antes de cair no do plugin
 * (`dokan-lite/includes/functions.php:943`). É por isso que este arquivo existe
 * aqui: **nenhum arquivo do plugin é editado**, e uma atualização do Dokan não
 * desfaz a personalização.
 *
 * O arquivo é deliberadamente fino. Toda a montagem está em
 * `inc/marketplace/loja.php`, onde a atualização do plugin não alcança e onde o
 * resto do tema consegue encontrá-la.
 *
 * A `store-sidebar` do Dokan não é chamada: o conteúdo dela migrou para o painel
 * "Ver mais" do cabeçalho, e a página passou a ocupar a largura inteira (veja
 * `reconectar_ajustar_classes_de_layout()`, em `inc/layout.php`).
 *
 * @package reconectar
 */

defined( 'ABSPATH' ) || exit;

$reconectar_vendedor = dokan()->vendor->get( get_query_var( 'author' ) );

get_header( 'shop' );

do_action( 'woocommerce_before_main_content' );
?>

<div class="rc-loja">
	<?php reconectar_pagina_de_loja( $reconectar_vendedor ); ?>
</div>

<?php
do_action( 'woocommerce_after_main_content' );

get_footer( 'shop' );
