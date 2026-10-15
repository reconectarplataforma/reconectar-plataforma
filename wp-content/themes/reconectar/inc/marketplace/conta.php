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
 * Só o papel `administrator`, e só sem o plugin. Com ele, o Super Administrador
 * vai à tela de módulos como Loja, Administrador e Moderador, por
 * `Reconectar_Modulos::depois_do_login()` — que também roda em 25 e antes desta,
 * por ser do plugin; sem a guarda, esta função desfaria a escolha dele. Sem o
 * plugin não há tela, e o Dokan o mandaria para `/dashboard/` (ele tem a
 * capacidade de loja, e o filtro do Dokan roda em 20): uma tela de vendedor que
 * não é a dele e que não tem o link do painel.
 *
 * Prioridade 25: depois do Dokan, e antes de `reconectar_login_volta_ao_checkout()`
 * (30), para que quem entrou pelo checkout continue voltando ao checkout.
 *
 * @param string  $destino URL decidida até aqui.
 * @param WP_User $usuario Usuário que acabou de entrar.
 * @return string
 */
function reconectar_login_do_super_administrador( $destino, $usuario = null ) {
	if ( class_exists( 'Reconectar_Modulos' ) ) {
		return $destino;
	}

	if ( $usuario instanceof WP_User && in_array( 'administrator', (array) $usuario->roles, true ) ) {
		return admin_url();
	}

	return $destino;
}
add_filter( 'woocommerce_login_redirect', 'reconectar_login_do_super_administrador', 25, 2 );

/*
 * Endereços dentro de "Detalhes da conta".
 *
 * O WooCommerce separa a conta em duas telas — "Endereços", com um formulário por
 * tipo, e "Detalhes da conta" — e quem queria corrigir o CEP e o telefone passava
 * por três páginas e dois botões de salvar. Aqui tudo fica num formulário só, o de
 * `form-edit-account.php`, e a entrega vira uma caixa "é o mesmo endereço".
 *
 * O formulário continua sendo o de `save_account_details`: o nonce, o `action` e o
 * handler do núcleo são os mesmos. Este arquivo valida os endereços **antes** dele
 * (prioridade 9) e grava **depois** dele (`woocommerce_save_account_details`). O
 * handler do núcleo só grava a conta com zero avisos de erro, então um CEP inválido
 * barra a conta inteira, e uma senha errada barra os endereços: nada sai salvo pela
 * metade.
 */

/**
 * Campo que marca o envio com endereços.
 *
 * Sem ele o handler não toca nos endereços: um formulário de detalhes da conta
 * vindo de outro lugar — um override futuro, um plugin — chegaria sem os campos
 * `billing_*` e, tratados como vazios, apagaria o endereço gravado.
 */
const RECONECTAR_CONTA_CAMPO_ENDERECOS = 'rc_enderecos_na_conta';

/**
 * Caixa "a entrega é no mesmo endereço da cobrança".
 */
const RECONECTAR_CONTA_CAMPO_MESMO_ENDERECO = 'rc_entrega_igual_cobranca';

/**
 * A tela deve oferecer endereço de entrega próprio?
 *
 * As duas condições são as mesmas que fazem o WooCommerce listar "Entrega" na tela
 * de endereços: sem envio ligado, ou com envio só para o endereço de cobrança, um
 * segundo endereço não seria usado por ninguém.
 *
 * @return bool
 */
function reconectar_conta_tem_entrega() {
	return wc_shipping_enabled() && ! wc_ship_to_billing_address_only();
}

/**
 * País de um tipo de endereço, no molde de `WC_Shortcode_My_Account::edit_address()`.
 *
 * O gravado, quando é um país permitido para aquele tipo; senão o país da loja, e
 * senão o primeiro permitido. O POST vence quando houver, para que uma troca de
 * país recusada pela validação volte à tela com os campos daquele país.
 *
 * @param string $tipo `billing` ou `shipping`.
 * @return string Código do país.
 */
function reconectar_conta_pais( $tipo ) {
	// phpcs:ignore WordPress.Security.NonceVerification.Missing -- só repovoa a tela; quem grava confere o nonce.
	$pais = isset( $_POST[ $tipo . '_country' ] ) ? wc_clean( wp_unslash( $_POST[ $tipo . '_country' ] ) ) : '';

	if ( ! $pais ) {
		$pais = get_user_meta( get_current_user_id(), $tipo . '_country', true );
	}

	if ( ! $pais ) {
		$pais = WC()->countries->get_base_country();
	}

	$permitidos = 'shipping' === $tipo ? WC()->countries->get_shipping_countries() : WC()->countries->get_allowed_countries();

	if ( ! array_key_exists( $pais, $permitidos ) ) {
		$pais = current( array_keys( $permitidos ) );
	}

	return (string) $pais;
}

/**
 * Campos de um tipo de endereço, já com o valor gravado.
 *
 * Reproduz a preparação de `WC_Shortcode_My_Account::edit_address()`, inclusive os
 * dois filtros — `woocommerce_my_account_edit_address_field_value` e
 * `woocommerce_address_to_edit` —, para que plugin que acrescente campo ao
 * endereço continue aparecendo agora que a tela é outra.
 *
 * @param string $tipo `billing` ou `shipping`.
 * @return array Campos no formato de `WC_Countries::get_address_fields()`, com `value`.
 */
function reconectar_conta_campos_de_endereco( $tipo ) {
	$usuario = wp_get_current_user();
	$campos  = WC()->countries->get_address_fields( reconectar_conta_pais( $tipo ), $tipo . '_' );

	foreach ( $campos as $chave => $campo ) {
		$valor = get_user_meta( $usuario->ID, $chave, true );

		if ( ! $valor && in_array( $chave, array( 'billing_email', 'shipping_email' ), true ) ) {
			$valor = $usuario->user_email;
		}

		/** Este filtro é do WooCommerce; documentado em `WC_Shortcode_My_Account::edit_address()`. */
		$campos[ $chave ]['value'] = apply_filters( 'woocommerce_my_account_edit_address_field_value', $valor, $chave, $tipo );
	}

	/** Este filtro é do WooCommerce; documentado em `WC_Shortcode_My_Account::edit_address()`. */
	return apply_filters( 'woocommerce_address_to_edit', $campos, $tipo );
}

/**
 * Nome do campo de cobrança que corresponde a um de entrega.
 *
 * @param string $chave_de_entrega Por exemplo, `shipping_postcode`.
 * @return string Por exemplo, `billing_postcode`.
 */
function reconectar_conta_chave_de_cobranca( $chave_de_entrega ) {
	return 'billing_' . substr( $chave_de_entrega, strlen( 'shipping_' ) );
}

/**
 * A caixa "mesmo endereço" nasce marcada?
 *
 * Num envio recusado, vale o que a pessoa deixou na caixa. Fora dele, marcada
 * quando a entrega está vazia — o caso de quase todo comprador, que nunca abriu a
 * tela de endereços — ou é cópia exata da cobrança. Qualquer diferença desmarca: a
 * caixa marcada **sobrescreve** a entrega ao salvar, e um endereço de entrega
 * próprio não pode sumir porque alguém salvou a troca de senha.
 *
 * @return bool
 */
function reconectar_conta_entrega_igual_a_cobranca() {
	// phpcs:ignore WordPress.Security.NonceVerification.Missing -- só repovoa a tela; quem grava confere o nonce.
	if ( isset( $_POST[ RECONECTAR_CONTA_CAMPO_ENDERECOS ] ) ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		return isset( $_POST[ RECONECTAR_CONTA_CAMPO_MESMO_ENDERECO ] );
	}

	$id_usuario = get_current_user_id();

	if ( '' === (string) get_user_meta( $id_usuario, 'shipping_address_1', true ) ) {
		return true;
	}

	$chaves = array_keys( WC()->countries->get_address_fields( reconectar_conta_pais( 'shipping' ), 'shipping_' ) );

	foreach ( $chaves as $chave ) {
		$entrega  = (string) get_user_meta( $id_usuario, $chave, true );
		$cobranca = (string) get_user_meta( $id_usuario, reconectar_conta_chave_de_cobranca( $chave ), true );

		if ( $entrega !== $cobranca ) {
			return false;
		}
	}

	return true;
}

/**
 * Valores apurados pela validação, à espera de que a conta também passe.
 *
 * Entre a validação (prioridade 9) e a gravação (`woocommerce_save_account_details`)
 * roda o handler do núcleo. Guardar **valores** e não um `WC_Customer` montado é
 * deliberado: um objeto carregado antes daquele handler traria o nome e o e-mail
 * antigos e, salvo depois, desfaria a troca que o handler acabou de gravar.
 *
 * @param array|null $novos Valores a guardar; `null` só lê.
 * @return array `tipo => [ chave => valor ]`.
 */
function reconectar_conta_enderecos_pendentes( $novos = null ) {
	static $pendentes = array();

	if ( null !== $novos ) {
		$pendentes = $novos;
	}

	return $pendentes;
}

/**
 * Valida um tipo de endereço vindo do POST, com as regras de `WC_Form_Handler::save_address()`.
 *
 * Obrigatório, CEP, telefone e e-mail, na mesma ordem e com as mesmas mensagens do
 * núcleo, e o `set_*` num cliente descartável para que as exceções de dado
 * inválido do `WC_Customer` também virem aviso. O descartável é o que vai ao gancho
 * `woocommerce_after_save_address_validation`, que plugin usa para recusar.
 *
 * @param string $tipo `billing` ou `shipping`.
 * @return array `chave => valor` já limpos e formatados.
 */
function reconectar_conta_validar_endereco( $tipo ) {
	$id_usuario = get_current_user_id();
	$cliente    = new WC_Customer( $id_usuario );
	// phpcs:ignore WordPress.Security.NonceVerification.Missing -- o nonce foi conferido por quem chama.
	$pais    = wc_clean( wp_unslash( $_POST[ $tipo . '_country' ] ) );
	$pais    = is_string( $pais ) ? $pais : '';
	$campos  = WC()->countries->get_address_fields( $pais, $tipo . '_' );
	$valores = array();

	foreach ( $campos as $chave => $campo ) {
		$tipo_do_campo = isset( $campo['type'] ) ? $campo['type'] : 'text';

		// phpcs:disable WordPress.Security.NonceVerification.Missing
		if ( 'checkbox' === $tipo_do_campo ) {
			$valor = (int) isset( $_POST[ $chave ] );
		} else {
			$valor = isset( $_POST[ $chave ] ) ? wc_clean( wp_unslash( $_POST[ $chave ] ) ) : '';
		}
		// phpcs:enable

		/** Este filtro é do WooCommerce; documentado em `WC_Form_Handler::save_address()`. */
		$valor = apply_filters( 'woocommerce_process_myaccount_field_' . $chave, $valor );

		if ( ! empty( $campo['required'] ) && empty( $valor ) ) {
			/* translators: %s: Field name. */
			wc_add_notice( sprintf( __( '%s is a required field.', 'woocommerce' ), $campo['label'] ), 'error', array( 'id' => $chave ) );
		}

		if ( ! empty( $valor ) && ! empty( $campo['validate'] ) && is_array( $campo['validate'] ) ) {
			foreach ( $campo['validate'] as $regra ) {
				if ( 'postcode' === $regra ) {
					$valor = wc_format_postcode( $valor, $pais );

					if ( '' !== $valor && ! WC_Validation::is_postcode( $valor, $pais ) ) {
						wc_add_notice( 'IE' === $pais ? __( 'Please enter a valid Eircode.', 'woocommerce' ) : __( 'Please enter a valid postcode / ZIP.', 'woocommerce' ), 'error' );
					}
				} elseif ( 'phone' === $regra && ! WC_Validation::is_phone( $valor, $pais ) ) {
					/* translators: %s: Phone number. */
					wc_add_notice( sprintf( __( '%s is not a valid phone number.', 'woocommerce' ), '<strong>' . $campo['label'] . '</strong>' ), 'error' );
				} elseif ( 'email' === $regra ) {
					$valor = strtolower( $valor );

					if ( ! is_email( $valor ) ) {
						/* translators: %s: Email address. */
						wc_add_notice( sprintf( __( '%s is not a valid email address.', 'woocommerce' ), '<strong>' . $campo['label'] . '</strong>' ), 'error' );
					}
				}
			}
		}

		reconectar_conta_aplicar_valor( $cliente, $chave, $valor );
		$valores[ $chave ] = $valor;
	}

	/** Este gancho é do WooCommerce; documentado em `WC_Form_Handler::save_address()`. */
	do_action( 'woocommerce_after_save_address_validation', $id_usuario, $tipo, $campos, $cliente );

	return $valores;
}

/**
 * Põe um valor de endereço no cliente, como `WC_Form_Handler::save_address()` faz.
 *
 * O e-mail de cobrança inválido não vira aviso aqui porque a validação acima já o
 * acusou — o núcleo ignora a mesma exceção pelo mesmo motivo.
 *
 * @param WC_Customer $cliente Cliente a alterar.
 * @param string      $chave   Nome do campo, com prefixo.
 * @param mixed       $valor   Valor limpo.
 * @return void
 */
function reconectar_conta_aplicar_valor( $cliente, $chave, $valor ) {
	try {
		if ( is_callable( array( $cliente, "set_$chave" ) ) ) {
			$cliente->{"set_$chave"}( $valor );
		} else {
			$cliente->update_meta_data( $chave, $valor );
		}
	} catch ( WC_Data_Exception $e ) {
		if ( 'customer_invalid_billing_email' !== $e->getErrorCode() ) {
			wc_add_notice( $e->getMessage(), 'error' );
		}
	}
}

/**
 * Valida os endereços do formulário de detalhes da conta, antes do handler do núcleo.
 *
 * Prioridade 9 em `template_redirect`, uma antes de `WC_Form_Handler::save_account_details()`:
 * um aviso de erro posto aqui é o que impede o núcleo de gravar a conta.
 *
 * A entrega tem três destinos. Com a caixa marcada, é cópia da cobrança, campo a
 * campo, e a validação da cobrança vale por ela. Desmarcada e com os campos no
 * POST, é validada como endereço próprio. Desmarcada e **sem** os campos — quem
 * desmarcou sem JavaScript, com o grupo ainda desabilitado — fica como está: a
 * tela volta com a caixa desmarcada e o grupo aberto, e o próximo envio o leva.
 *
 * @return void
 */
function reconectar_conta_validar_enderecos() {
	// phpcs:disable WordPress.Security.NonceVerification.Missing -- conferido logo abaixo, com o nonce do núcleo.
	if ( empty( $_POST[ RECONECTAR_CONTA_CAMPO_ENDERECOS ] ) || empty( $_POST['action'] ) || 'save_account_details' !== $_POST['action'] ) {
		return;
	}

	$nonce = isset( $_POST['save-account-details-nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['save-account-details-nonce'] ) ) : '';

	if ( ! wp_verify_nonce( $nonce, 'save_account_details' ) || get_current_user_id() <= 0 || ! isset( $_POST['billing_country'] ) ) {
		return;
	}

	$enderecos = array( 'billing' => reconectar_conta_validar_endereco( 'billing' ) );

	if ( reconectar_conta_tem_entrega() ) {
		if ( isset( $_POST[ RECONECTAR_CONTA_CAMPO_MESMO_ENDERECO ] ) ) {
			$entrega = array();
			$campos  = WC()->countries->get_address_fields( $enderecos['billing']['billing_country'], 'shipping_' );

			foreach ( array_keys( $campos ) as $chave ) {
				$cobranca          = reconectar_conta_chave_de_cobranca( $chave );
				$entrega[ $chave ] = isset( $enderecos['billing'][ $cobranca ] ) ? $enderecos['billing'][ $cobranca ] : '';
			}

			$enderecos['shipping'] = $entrega;
		} elseif ( isset( $_POST['shipping_country'] ) ) {
			$enderecos['shipping'] = reconectar_conta_validar_endereco( 'shipping' );
		}
	}
	// phpcs:enable

	reconectar_conta_enderecos_pendentes( $enderecos );
}
add_action( 'template_redirect', 'reconectar_conta_validar_enderecos', 9 );

/**
 * Grava os endereços validados, depois que o núcleo gravou a conta.
 *
 * `woocommerce_save_account_details` só dispara quando a conta passou na validação
 * dela — e, como os avisos são contados juntos, quando os endereços também
 * passaram. O cliente é carregado **aqui**, depois da gravação do núcleo, pelo
 * motivo registrado em `reconectar_conta_enderecos_pendentes()`.
 *
 * Os campos de cobrança vencem a sincronia que o núcleo faz do nome e do e-mail da
 * conta para a cobrança: os dois estavam na mesma tela, e o que a pessoa digitou no
 * endereço é a resposta mais específica.
 *
 * @param int $id_usuario Usuário cuja conta acabou de ser gravada.
 * @return void
 */
function reconectar_conta_gravar_enderecos( $id_usuario ) {
	$enderecos = reconectar_conta_enderecos_pendentes();

	if ( ! $enderecos || 0 < wc_notice_count( 'error' ) ) {
		return;
	}

	$cliente = new WC_Customer( $id_usuario );

	foreach ( $enderecos as $valores ) {
		foreach ( $valores as $chave => $valor ) {
			reconectar_conta_aplicar_valor( $cliente, $chave, $valor );
		}
	}

	$cliente->save();

	foreach ( $enderecos as $tipo => $valores ) {
		$campos = WC()->countries->get_address_fields( $cliente->{"get_{$tipo}_country"}(), $tipo . '_' );

		/** Este gancho é do WooCommerce; documentado em `WC_Form_Handler::save_address()`. */
		do_action( 'woocommerce_customer_save_address', $id_usuario, $tipo, $campos, $cliente );
	}

	reconectar_conta_enderecos_pendentes( array() );
}
add_action( 'woocommerce_save_account_details', 'reconectar_conta_gravar_enderecos' );

/**
 * Tira "Endereços" do menu da conta.
 *
 * Os endereços agora estão em "Detalhes da conta", e um item que leva a uma
 * segunda cópia dos mesmos campos faria a pessoa se perguntar qual das duas vale.
 *
 * @param array $itens Itens do menu, no formato `endpoint => rótulo`.
 * @return array
 */
function reconectar_remover_enderecos_da_conta( $itens ) {
	unset( $itens['edit-address'] );

	return $itens;
}
add_filter( 'woocommerce_account_menu_items', 'reconectar_remover_enderecos_da_conta' );

/**
 * Manda a tela de endereços para "Detalhes da conta".
 *
 * Só o item de menu sair não basta: o painel da conta e o recibo do pedido
 * imprimem links para `edit-address`, e o e-mail antigo de alguém também. Só GET:
 * um POST para lá é o `save_address` do núcleo, que ainda funciona e redireciona
 * para a própria tela — e cai aqui na volta.
 *
 * @return void
 */
function reconectar_redirecionar_enderecos_da_conta() {
	if ( ! is_wc_endpoint_url( 'edit-address' ) || 'GET' !== ( isset( $_SERVER['REQUEST_METHOD'] ) ? $_SERVER['REQUEST_METHOD'] : '' ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		return;
	}

	wp_safe_redirect( wc_get_account_endpoint_url( 'edit-account' ) . '#rc-editar-conta-endereco' );
	exit;
}
add_action( 'template_redirect', 'reconectar_redirecionar_enderecos_da_conta' );
