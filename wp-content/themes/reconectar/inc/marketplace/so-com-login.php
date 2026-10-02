<?php
/**
 * Compra e avaliação só com login: o que o visitante vê no lugar delas.
 *
 * A trava em si são duas opções que o `provision.sh` grava —
 * `woocommerce_enable_guest_checkout = no` e `comment_registration = 1` — e quem
 * as aplica no servidor é o próprio núcleo: `WC_Checkout` recusa o pedido sem
 * conta, e `wp_handle_comment_submission()` responde 403 à avaliação anônima.
 * Avaliação de loja não tem trava própria porque não existe à parte: o Dokan Lite
 * calcula a nota da loja a partir das avaliações dos produtos dela.
 *
 * Este arquivo cuida só do texto, porque o que o WooCommerce entrega com as
 * opções ligadas não serve:
 *
 * - O lembrete de login do checkout vem **escondido** atrás de "Clique aqui para
 *   entrar", e o parágrafo dele manda o novo cliente "para a seção de cobrança" —
 *   que não existe para quem não entrou. O que sobra na tela é a frase "Você
 *   precisa estar conectado", sem link nenhum e sem caminho para criar conta.
 * - O aviso das avaliações sai, na tradução atual, como "Você precisa fazer
 *   logged in para enviar uma avaliação".
 *
 * @package reconectar
 */

defined( 'ABSPATH' ) || exit;

/**
 * Bloco de entrada no lugar do formulário de checkout, para o visitante.
 *
 * Fica em `woocommerce_before_checkout_form`, que roda **antes** de
 * `form-checkout.php` desistir por falta de login. Por isso o lembrete nativo
 * (`woocommerce_enable_checkout_login_reminder`) fica desligado no
 * `provision.sh`: ligado, ele imprimiria um segundo formulário de login, oculto,
 * acima deste.
 *
 * O `redirect` devolve ao checkout depois do login, e o carrinho do visitante
 * passa para a conta — o WooCommerce migra a sessão no login.
 *
 * @param WC_Checkout $checkout Checkout corrente (não usado).
 * @return void
 */
function reconectar_checkout_entrada_do_visitante( $checkout = null ) {
	if ( is_user_logged_in() || ! function_exists( 'woocommerce_login_form' ) ) {
		return;
	}

	$url_da_conta = wc_get_page_permalink( 'myaccount' );
	?>
	<section class="rc-entrada-checkout" aria-labelledby="rc-entrada-checkout-titulo">
		<h2 id="rc-entrada-checkout-titulo"><?php esc_html_e( 'Entre para finalizar a compra', 'reconectar' ); ?></h2>
		<?php
		woocommerce_login_form(
			array(
				'message'  => __( 'Só quem tem conta compra na plataforma. Seu carrinho fica guardado enquanto você entra.', 'reconectar' ),
				'redirect' => wc_get_checkout_url(),
				'hidden'   => false,
			)
		);
		?>
		<p class="rc-entrada-checkout__cadastro">
			<?php esc_html_e( 'Ainda não tem conta?', 'reconectar' ); ?>
			<a href="<?php echo esc_url( $url_da_conta ); ?>"><?php esc_html_e( 'Crie a sua', 'reconectar' ); ?></a>
		</p>
	</section>
	<?php
}
add_action( 'woocommerce_before_checkout_form', 'reconectar_checkout_entrada_do_visitante', 10 );

/**
 * Devolve ao checkout quem entrou pelo bloco acima.
 *
 * O `redirect` do formulário não basta: o Dokan pendura em
 * `woocommerce_login_redirect`, prioridade 20, um filtro do cadastro de vendedor
 * (`Shortcodes\VendorOnboardingRegistration`) que manda **todo cliente** para
 * "Minha conta", qualquer que seja o destino pedido. Medido: cliente indo ao
 * checkout, a um produto ou à home sai sempre em `/my-account/`; vendedor e
 * moderador passam intactos. O sintoma é o comprador entrar e perder o caminho
 * do pedido, com o carrinho cheio.
 *
 * Só o destino do checkout é restaurado — os demais logins de cliente seguem
 * como o Dokan decide. O POST já passou pelo nonce de login do WooCommerce
 * quando este filtro roda.
 *
 * @param string $destino URL decidida até aqui.
 * @return string
 */
function reconectar_login_volta_ao_checkout( $destino ) {
	// phpcs:ignore WordPress.Security.NonceVerification.Missing -- conferido em WC_Form_Handler::process_login().
	$pedido = isset( $_POST['redirect'] ) ? esc_url_raw( wp_unslash( $_POST['redirect'] ) ) : '';

	if ( '' !== $pedido && untrailingslashit( $pedido ) === untrailingslashit( wc_get_checkout_url() ) ) {
		return wc_get_checkout_url();
	}

	return $destino;
}
add_filter( 'woocommerce_login_redirect', 'reconectar_login_volta_ao_checkout', 30 );

/**
 * Cala a frase "Você precisa estar conectado para finalizar a compra".
 *
 * `form-checkout.php` a imprime solta, sem elemento em volta e com `esc_html` —
 * não cabe link nela. O bloco acima já diz o mesmo e oferece as duas saídas.
 *
 * @return string
 */
function reconectar_checkout_sem_aviso_de_login() {
	return '';
}
add_filter( 'woocommerce_checkout_must_be_logged_in_message', 'reconectar_checkout_sem_aviso_de_login' );

/**
 * Leva de volta ao checkout quem cria a conta com o carrinho cheio.
 *
 * Sem isto o cadastro termina no painel da conta, e o comprador precisa achar o
 * carrinho de novo. Com o carrinho vazio, o destino padrão continua valendo.
 *
 * @param string $destino URL que o WooCommerce usaria.
 * @return string
 */
function reconectar_cadastro_volta_ao_checkout( $destino ) {
	if ( function_exists( 'WC' ) && WC()->cart && ! WC()->cart->is_empty() ) {
		return wc_get_checkout_url();
	}

	return $destino;
}
add_filter( 'woocommerce_registration_redirect', 'reconectar_cadastro_volta_ao_checkout' );

/**
 * Aviso de login das avaliações, no lugar da tradução quebrada.
 *
 * @param array $argumentos Argumentos de `comment_form()` montados pelo WooCommerce.
 * @return array
 */
function reconectar_avaliacao_aviso_de_login( $argumentos ) {
	$argumentos['must_log_in'] = sprintf(
		'<p class="must-log-in"><a href="%1$s">%2$s</a> %3$s</p>',
		esc_url( wc_get_page_permalink( 'myaccount' ) ),
		esc_html__( 'Entre na sua conta', 'reconectar' ),
		esc_html__( 'para avaliar este produto.', 'reconectar' )
	);

	return $argumentos;
}
add_filter( 'woocommerce_product_review_comment_form_args', 'reconectar_avaliacao_aviso_de_login' );
