<?php
/**
 * Ajustes da área "Minha conta" do WooCommerce.
 *
 * @package reconectar
 */

defined( 'ABSPATH' ) || exit;

/**
 * Tira "Downloads" do menu lateral da conta.
 *
 * A aba é estrutural no WooCommerce: ela aparece para todo comprador, tenha ou
 * não comprado algo baixável. Aqui não há o que baixar — o catálogo inteiro é de
 * produto físico, e nem a carga de demonstração nem o plugin criam produto com
 * `downloadable`. O item levava a uma tabela vazia com uma frase de sistema, o
 * que gasta um dos poucos atalhos que o comprador enxerga na conta.
 *
 * Só o **item de menu** sai; o endpoint continua registrado. Isso é deliberado:
 * quem desliga a rota é a opção `woocommerce_myaccount_downloads_endpoint`, que
 * mora no banco e exigiria provisionamento para atravessar o deploy — e, pior,
 * deixaria sem destino os links de download que o WooCommerce imprime no e-mail
 * de pedido concluído. Sem item nenhum apontando para ela, a rota é inerte.
 *
 * Se algum dia a plataforma vender produto digital, este filtro é o que precisa
 * sair — o comprador ficaria sem a tela onde os arquivos dele ficam.
 *
 * @param array $itens Itens do menu, no formato `endpoint => rótulo`.
 * @return array Itens sem o de downloads.
 */
function reconectar_remover_downloads_da_conta( $itens ) {
	unset( $itens['downloads'] );

	return $itens;
}
add_filter( 'woocommerce_account_menu_items', 'reconectar_remover_downloads_da_conta' );
