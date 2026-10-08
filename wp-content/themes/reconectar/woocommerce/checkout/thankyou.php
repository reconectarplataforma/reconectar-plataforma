<?php
/**
 * Tela de agradecimento do pedido.
 *
 * Override de `woocommerce/templates/checkout/thankyou.php` (@version 8.1.0).
 * A diferença é o resumo do topo: o original imprime número, data, e-mail,
 * total e meio de pagamento numa lista de cinco linhas altas, e nesta
 * plataforma a tela é, antes de tudo, a de **pagar** — o comprador rolava meia
 * página de dados que já conhecia até chegar no QR Code. Aqui os quatro dados
 * que importam cabem numa faixa só, e o e-mail sai: ele está no endereço de
 * cobrança, dentro dos detalhes do pedido.
 *
 * Os ganchos ficam exatamente onde estavam, na mesma ordem: as instruções de
 * pagamento (`woocommerce_thankyou`, prioridade 5) e o recolhimento dos detalhes
 * do pedido vêm do plugin e dependem deles.
 *
 * @package reconectar
 */

defined( 'ABSPATH' ) || exit;
?>

<div class="woocommerce-order">

	<?php
	if ( $order ) :

		do_action( 'woocommerce_before_thankyou', $order->get_id() );
		?>

		<?php if ( $order->has_status( 'failed' ) ) : ?>

			<p class="woocommerce-notice woocommerce-notice--error woocommerce-thankyou-order-failed"><?php esc_html_e( 'Unfortunately your order cannot be processed as the originating bank/merchant has declined your transaction. Please attempt your purchase again.', 'woocommerce' ); ?></p>

			<p class="woocommerce-notice woocommerce-notice--error woocommerce-thankyou-order-failed-actions">
				<a href="<?php echo esc_url( $order->get_checkout_payment_url() ); ?>" class="button pay"><?php esc_html_e( 'Pay', 'woocommerce' ); ?></a>
				<?php if ( is_user_logged_in() ) : ?>
					<a href="<?php echo esc_url( wc_get_page_permalink( 'myaccount' ) ); ?>" class="button pay"><?php esc_html_e( 'My account', 'woocommerce' ); ?></a>
				<?php endif; ?>
			</p>

		<?php else : ?>

			<div class="rc-pedido-recebido">
				<?php wc_get_template( 'checkout/order-received.php', array( 'order' => $order ) ); ?>

				<?php
				// `<dl>` e não a `<ul>` do original: são pares rótulo/valor, e é
				// assim que o leitor de tela os anuncia. Sem a classe
				// `order_details` de propósito — é nela que o Storefront pendura o
				// fundo serrilhado e as linhas altas que esta faixa substitui.
				?>
				<dl class="rc-pedido-recebido__dados">
					<div class="rc-pedido-recebido__dado">
						<dt><?php esc_html_e( 'Pedido', 'reconectar' ); ?></dt>
						<dd><?php echo esc_html( $order->get_order_number() ); ?></dd>
					</div>
					<div class="rc-pedido-recebido__dado">
						<dt><?php esc_html_e( 'Data', 'reconectar' ); ?></dt>
						<dd><?php echo esc_html( wc_format_datetime( $order->get_date_created() ) ); ?></dd>
					</div>
					<div class="rc-pedido-recebido__dado">
						<dt><?php esc_html_e( 'Total', 'reconectar' ); ?></dt>
						<dd><?php echo wp_kses_post( $order->get_formatted_order_total() ); ?></dd>
					</div>
					<?php if ( $order->get_payment_method_title() ) : ?>
						<div class="rc-pedido-recebido__dado">
							<dt><?php esc_html_e( 'Pagamento', 'reconectar' ); ?></dt>
							<dd><?php echo wp_kses_post( $order->get_payment_method_title() ); ?></dd>
						</div>
					<?php endif; ?>
				</dl>
			</div>

		<?php endif; ?>

		<?php do_action( 'woocommerce_thankyou_' . $order->get_payment_method(), $order->get_id() ); ?>
		<?php do_action( 'woocommerce_thankyou', $order->get_id() ); ?>

	<?php else : ?>

		<?php wc_get_template( 'checkout/order-received.php', array( 'order' => false ) ); ?>

	<?php endif; ?>

</div>
