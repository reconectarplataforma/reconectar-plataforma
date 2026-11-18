<?php
/**
 * Pagamento combinado com a loja, pelo WhatsApp.
 *
 * @package reconectar-core
 */

defined( 'ABSPATH' ) || exit;

/**
 * Oferece "combinar com a loja" no checkout e imprime o link da conversa.
 *
 * Ao contrário do PIX e da transferência, a plataforma não sabe **como** o
 * comprador vai pagar — dinheiro na entrega, cartão na maquininha, um PIX
 * passado na conversa. Por isso a instrução não traz dado de pagamento nenhum:
 * traz o caminho até a loja. O pedido fica em espera, com o estoque já
 * reservado pela base, até a loja confirmar mudando o status, como nos outros
 * dois meios.
 */
class Reconectar_Gateway_Combinar extends Reconectar_Gateway_Direto {

	/**
	 * Monta o gateway.
	 */
	public function __construct() {
		$this->id           = Reconectar_Pagamento_Direto::GATEWAY_COMBINAR;
		$this->metodo_dokan = Reconectar_Pagamento_Whatsapp::METODO;
		$this->icon         = '';

		parent::__construct();
	}

	/**
	 * Título de fábrica.
	 *
	 * @return string Título.
	 */
	protected function titulo_padrao() {
		return __( 'Combinar com a loja (WhatsApp)', 'reconectar-core' );
	}

	/**
	 * Descrição de fábrica.
	 *
	 * @return string Descrição.
	 */
	protected function descricao_padrao() {
		return __( 'Ao finalizar, você fala com a loja pelo WhatsApp e combina como pagar.', 'reconectar-core' );
	}

	/**
	 * Explicação para quem configura o gateway.
	 *
	 * @return string Descrição administrativa.
	 */
	protected function descricao_administrativa() {
		return __( 'O comprador combina o pagamento com cada loja pelo WhatsApp — dinheiro, cartão na entrega ou o que a loja aceitar. A plataforma não registra como foi pago: a loja confirma o recebimento mudando o status do pedido. O número é cadastrado pela própria loja em Configurações → Pagamento, na dashboard dela.', 'reconectar-core' );
	}

	/**
	 * Ícone: balão de conversa, em círculo.
	 *
	 * Mesma moldura dos outros dois — círculo de 32, traço de 2,2. Não é o
	 * logotipo do WhatsApp de propósito: a marca é de terceiro, e o nome já
	 * está escrito ao lado.
	 *
	 * @return string SVG do ícone.
	 */
	public function icone() {
		return '<svg class="rc-meio-icone rc-meio-icone--combinar" viewBox="0 0 32 32" role="presentation" focusable="false" aria-hidden="true">'
			. '<circle cx="16" cy="16" r="16" fill="currentColor" opacity="0.14"/>'
			. '<path d="M16 8.5c-4.4 0-8 3-8 6.8 0 2.1 1.1 4 2.9 5.2L10 24l4.1-2.1c.6.1 1.2.2 1.9.2 4.4 0 8-3 8-6.8s-3.6-6.8-8-6.8Z" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linejoin="round"/>'
			. '<path d="M12.5 15.3h.01M16 15.3h.01M19.5 15.3h.01" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round"/>'
			. '</svg>';
	}

	/**
	 * Diz se a loja tem WhatsApp discável cadastrado.
	 *
	 * @param int $loja_id Identificador da loja.
	 * @return bool Se a loja combina pagamento pelo WhatsApp.
	 */
	public function loja_recebe( $loja_id ) {
		return array() !== Reconectar_Pagamento_Whatsapp::dados_da_loja( $loja_id );
	}

	/**
	 * Monta a mensagem que já sai escrita na conversa.
	 *
	 * Número do pedido e valor vão no texto para a loja achar o pedido no
	 * painel sem perguntar — é a primeira coisa que ela perguntaria. O valor é
	 * o **desta loja**, não o total do pedido, pela mesma razão do BR Code: num
	 * carrinho com três lojas, o total faria cada uma achar que vai receber
	 * pelas outras.
	 *
	 * @param float         $valor  Valor que cabe a esta loja.
	 * @param WC_Order|null $pedido Pedido ou sub-pedido da loja.
	 * @return string Mensagem em texto puro.
	 */
	private static function mensagem( $valor, $pedido ) {
		// `wc_price()` devolve HTML com `&nbsp;` e `&#36;`; a URL leva texto.
		$valor_texto = html_entity_decode( wp_strip_all_tags( wc_price( $valor ) ), ENT_QUOTES, 'UTF-8' );

		if ( $pedido instanceof WC_Order ) {
			return sprintf(
				/* translators: 1: número do pedido, 2: nome do site, 3: valor. */
				__( 'Olá! Fiz o pedido nº %1$s em %2$s, no valor de %3$s, e quero combinar o pagamento.', 'reconectar-core' ),
				$pedido->get_order_number(),
				wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ),
				$valor_texto
			);
		}

		return sprintf(
			/* translators: 1: nome do site, 2: valor. */
			__( 'Olá! Estou comprando em %1$s, no valor de %2$s, e quero combinar o pagamento.', 'reconectar-core' ),
			wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ),
			$valor_texto
		);
	}

	/**
	 * Imprime o link da conversa com a loja.
	 *
	 * Os dois contextos saem iguais: o link é texto, funciona no cliente de
	 * e-mail e não depende de JavaScript. O número também sai escrito, e não só
	 * dentro do link — há quem compre num computador sem WhatsApp e vá
	 * escrever pelo celular.
	 *
	 * @param int           $loja_id  Identificador da loja.
	 * @param float         $valor    Valor que cabe a esta loja.
	 * @param WC_Order|null $pedido   Pedido ou sub-pedido da loja.
	 * @param string        $contexto `tela` ou `email`.
	 * @return void
	 */
	public function imprimir_instrucao( $loja_id, $valor, $pedido, $contexto = 'tela' ) {
		unset( $contexto );

		$dados = Reconectar_Pagamento_Whatsapp::dados_da_loja( $loja_id );

		if ( ! $dados ) {
			printf(
				'<p class="rc-pagamento__alerta">%s</p>',
				esc_html__( 'Esta loja ainda não cadastrou um WhatsApp. Entre em contato com a plataforma para combinar o pagamento.', 'reconectar-core' )
			);

			return;
		}

		$url = 'https://wa.me/' . $dados['numero'] . '?text=' . rawurlencode( self::mensagem( $valor, $pedido ) );

		echo '<div class="rc-pagamento__combinar">';

		if ( '' !== $dados['recado'] ) {
			printf(
				'<p class="rc-pagamento__recado">%s</p>',
				esc_html( $dados['recado'] )
			);
		}

		printf(
			'<p><a class="rc-pagamento__whatsapp" href="%1$s" target="_blank" rel="noopener">%2$s<span class="screen-reader-text"> %3$s</span></a></p>',
			esc_url( $url ),
			esc_html__( 'Conversar com a loja no WhatsApp', 'reconectar-core' ),
			esc_html__( '(abre em nova janela)', 'reconectar-core' )
		);

		echo '<dl class="rc-pagamento__dados">';
		printf(
			'<dt class="rc-pagamento__rotulo">%1$s</dt><dd class="rc-pagamento__valor-dado">%2$s</dd>',
			esc_html__( 'WhatsApp da loja', 'reconectar-core' ),
			esc_html( Reconectar_Pagamento_Whatsapp::formatar_numero( $dados['numero'] ) )
		);
		echo '</dl>';

		echo '</div>';
	}
}
