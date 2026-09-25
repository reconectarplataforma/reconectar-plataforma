<?php
/**
 * Vitrine de lojas da home.
 *
 * É a seção central da página: barra de filtros, grade de cards e um botão que
 * leva à listagem completa. Vem depois das faixas de categorias e destaques
 * porque essas servem para descobrir; esta serve para escolher.
 *
 * O componente em si mora em `inc/marketplace/vitrine.php` — a home é só um dos
 * dois lugares onde ele aparece, e o outro é a página `/store-listing/`. Aqui a
 * lista não expande na própria página: o botão manda para a vitrine completa,
 * onde a busca por nome existe e a lista inteira cabe.
 *
 * @package reconectar
 */

defined( 'ABSPATH' ) || exit;

/**
 * Renderiza a vitrine de lojas na home.
 */
function reconectar_home_lojas() {
	reconectar_vitrine_de_lojas(
		array(
			'titulo' => __( 'Lojas', 'reconectar' ),
			'busca'  => false,
			'mais'   => 'pagina',
		)
	);
}
add_action( 'reconectar_home', 'reconectar_home_lojas', 45 );
