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

/**
 * Chave do item "Painel administrativo" no menu da conta.
 *
 * Não é endpoint do WooCommerce: só identifica o item para que
 * `reconectar_url_do_painel_na_conta()` troque o destino dele.
 */
const RECONECTAR_CONTA_ITEM_PAINEL = 'rc-painel-administrativo';

/**
 * O usuário atual pode entrar no painel do WordPress?
 *
 * A resposta vem do plugin, que é quem barra o `/wp-admin`: um link oferecido
 * aqui e devolvido lá com um redirecionamento seria pior que link nenhum. Sem o
 * plugin não há bloqueio, e o critério cai no do núcleo.
 *
 * @return bool
 */
function reconectar_conta_mostra_painel() {
	if ( function_exists( 'reconectar_pode_entrar_no_painel_wp' ) ) {
		return reconectar_pode_entrar_no_painel_wp();
	}

	return current_user_can( 'manage_options' );
}

/**
 * Põe "Painel administrativo" no menu da conta, antes de "Sair".
 *
 * Um link, e não um redirecionamento depois do login: quem administra também
 * compra, e mandá-lo direto ao `/wp-admin` a cada entrada tirava dele a própria
 * conta. Com o link, a escolha fica com quem entrou.
 *
 * @param array $itens Itens do menu, no formato `chave => rótulo`.
 * @return array
 */
function reconectar_conta_item_do_painel( $itens ) {
	if ( ! reconectar_conta_mostra_painel() ) {
		return $itens;
	}

	$sair = isset( $itens['customer-logout'] ) ? array( 'customer-logout' => $itens['customer-logout'] ) : array();
	unset( $itens['customer-logout'] );

	$itens[ RECONECTAR_CONTA_ITEM_PAINEL ] = __( 'Painel administrativo', 'reconectar' );

	return $itens + $sair;
}
add_filter( 'woocommerce_account_menu_items', 'reconectar_conta_item_do_painel', 20 );

/**
 * Aponta o item do painel para o `/wp-admin`.
 *
 * O menu da conta monta todo link por `wc_get_account_endpoint_url()`, que sem
 * este filtro faria da chave um endpoint inexistente — e o item levaria ao 404.
 *
 * @param string $url      URL montada pelo WooCommerce.
 * @param string $endpoint Chave do item.
 * @return string
 */
function reconectar_url_do_painel_na_conta( $url, $endpoint ) {
	return RECONECTAR_CONTA_ITEM_PAINEL === $endpoint ? admin_url() : $url;
}
add_filter( 'woocommerce_get_endpoint_url', 'reconectar_url_do_painel_na_conta', 10, 2 );

/**
 * Leva o Super Administrador ao `/wp-admin` depois do login por "Minha conta".
 *
 * Só o papel `administrator`. Loja, Administrador e Moderador vão à tela de
 * módulos, por `Reconectar_Modulos::depois_do_login()`, no plugin, que é quem
 * conhece a rota. O Super Administrador é diferente
 * porque, sem isto, o Dokan o manda para `/dashboard/` (ele tem a capacidade de
 * loja, e o filtro do Dokan roda em 20): uma tela de vendedor que não é a dele e
 * que não tem o link do painel.
 *
 * Prioridade 25: depois do Dokan, e antes de `reconectar_login_volta_ao_checkout()`
 * (30), para que quem entrou pelo checkout continue voltando ao checkout.
 *
 * @param string  $destino URL decidida até aqui.
 * @param WP_User $usuario Usuário que acabou de entrar.
 * @return string
 */
function reconectar_login_do_super_administrador( $destino, $usuario = null ) {
	if ( $usuario instanceof WP_User && in_array( 'administrator', (array) $usuario->roles, true ) ) {
		return admin_url();
	}

	return $destino;
}
add_filter( 'woocommerce_login_redirect', 'reconectar_login_do_super_administrador', 25, 2 );
