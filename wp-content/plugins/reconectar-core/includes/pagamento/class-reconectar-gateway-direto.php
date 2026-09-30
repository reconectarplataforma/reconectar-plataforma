<?php
/**
 * Base dos meios de pagamento em que o comprador paga a loja direto.
 *
 * O que os dois meios têm em comum é quase tudo: nenhum cobra, nenhum confirma,
 * os dois deixam o pedido aguardando e mostram depois, por loja, para quem
 * pagar. O que muda é o dado lido do perfil da loja e a forma de apresentá-lo.
 *
 * @package reconectar-core
 */

defined( 'ABSPATH' ) || exit;

/**
 * Gateway que não processa transação: só instrui.
 */
abstract class Reconectar_Gateway_Direto extends WC_Payment_Gateway {

	/**
	 * Chave do método dentro de `dokan_profile_settings['payment']`.
	 *
	 * @var string
	 */
	protected $metodo_dokan = '';

	/**
	 * Monta o gateway com os campos comuns de administração.
	 */
	public function __construct() {
		$this->has_fields         = false;
		$this->method_title       = $this->titulo_padrao();
		$this->method_description = $this->descricao_administrativa();

		$this->init_form_fields();
		$this->init_settings();

		$this->title       = $this->get_option( 'title', $this->titulo_padrao() );
		$this->description = $this->get_option( 'description', $this->descricao_padrao() );
		$this->enabled     = $this->get_option( 'enabled', 'yes' );

		add_action(
			'woocommerce_update_options_payment_gateways_' . $this->id,
			array( $this, 'process_admin_options' )
		);
	}

	/**
	 * Devolve o ícone do meio, em SVG embutido.
	 *
	 * Não é `WC_Payment_Gateway::$icon` nem o filtro `woocommerce_gateway_icon`:
	 * os dois montam `<img src>`, e um arquivo de imagem não herda as cores do
	 * tema nem acompanha o contraste do modo escuro. Quem consome isto é a
	 * renderização autoral de meios por loja, no colapse do checkout.
	 *
	 * O padrão genérico existe para que um meio futuro — cartão, boleto — não
	 * precise declarar o próprio ícone só para aparecer na tela.
	 *
	 * O retorno é **literal do código**, sem nenhum dado de usuário, e por isso
	 * sai por `echo` direto: `wp_kses_post()` descartaria o `<svg>` inteiro, que
	 * não está em `$allowedposttags`.
	 *
	 * @return string SVG do ícone.
	 */
	public function icone() {
		return '<svg class="rc-meio-icone" viewBox="0 0 32 32" role="presentation" focusable="false" aria-hidden="true">'
			. '<circle cx="16" cy="16" r="16" fill="currentColor" opacity="0.14"/>'
			. '<path d="M9 12h14a1 1 0 0 1 1 1v8a1 1 0 0 1-1 1H9a1 1 0 0 1-1-1v-8a1 1 0 0 1 1-1Zm0 3h14" fill="none" stroke="currentColor" stroke-width="2" stroke-linejoin="round"/>'
			. '</svg>';
	}

	/**
	 * Título de fábrica do meio, como o comprador o vê.
	 *
	 * @return string Título.
	 */
	abstract protected function titulo_padrao();

	/**
	 * Descrição de fábrica, impressa abaixo do título no checkout.
	 *
	 * @return string Descrição.
	 */
	abstract protected function descricao_padrao();

	/**
	 * Explicação para quem configura o gateway no `/wp-admin`.
	 *
	 * @return string Descrição administrativa.
	 */
	abstract protected function descricao_administrativa();

	/**
	 * Diz se a loja tem os dados deste meio preenchidos.
	 *
	 * @param int $loja_id Identificador da loja.
	 * @return bool Se a loja pode receber por aqui.
	 */
	abstract public function loja_recebe( $loja_id );

	/**
	 * Imprime os dados de recebimento de uma loja.
	 *
	 * Os dois contextos existem porque o e-mail não executa JavaScript e vários
	 * clientes removem SVG embutido: o que é interativo ou depende de imagem
	 * gerada sai só em `tela`. O pedido existe nos dois — estes blocos saem
	 * **depois** do fechamento, nunca no checkout.
	 *
	 * @param int           $loja_id  Identificador da loja.
	 * @param float         $valor    Valor que cabe a esta loja.
	 * @param WC_Order|null $pedido   Pedido ou sub-pedido da loja.
	 * @param string        $contexto `tela` ou `email`.
	 * @return void
	 */
	abstract public function imprimir_instrucao( $loja_id, $valor, $pedido, $contexto = 'tela' );

	/**
	 * Campos de configuração no `/wp-admin`.
	 *
	 * Não há campo de credencial, e isso é a característica do cenário: quem
	 * recebe é a loja, com a chave que ela mesma cadastrou na dashboard. A
	 * plataforma não intermedia, então não há nada de secreto para guardar aqui.
	 *
	 * @return void
	 */
	public function init_form_fields() {
		$this->form_fields = array(
			'enabled'     => array(
				'title'   => __( 'Ativação', 'reconectar-core' ),
				'type'    => 'checkbox',
				'label'   => __( 'Oferecer este meio de pagamento no checkout', 'reconectar-core' ),
				'default' => 'yes',
			),
			'title'       => array(
				'title'       => __( 'Título', 'reconectar-core' ),
				'type'        => 'text',
				'description' => __( 'Nome do meio de pagamento na tela de checkout.', 'reconectar-core' ),
				'default'     => $this->titulo_padrao(),
				'desc_tip'    => true,
			),
			'description' => array(
				'title'       => __( 'Descrição', 'reconectar-core' ),
				'type'        => 'textarea',
				'description' => __( 'Texto exibido quando o comprador seleciona este meio.', 'reconectar-core' ),
				'default'     => $this->descricao_padrao(),
			),
		);
	}

	/**
	 * Decide se o meio aparece no checkout.
	 *
	 * Basta **uma** loja do carrinho poder receber. Esconder o meio porque uma
	 * das lojas não cadastrou a chave deixaria o comprador sem alternativa para
	 * as outras; o caminho honesto é oferecer e dizer, em `payment_fields()`,
	 * quais lojas não recebem por aqui.
	 *
	 * @return bool Se o meio deve ser oferecido.
	 */
	public function is_available() {
		if ( ! parent::is_available() ) {
			return false;
		}

		foreach ( Reconectar_Pagamento_Direto::lojas_do_carrinho() as $loja_id ) {
			if ( $this->loja_recebe( $loja_id ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Lojas do carrinho que **não** têm dados para este meio.
	 *
	 * @return string[] Nomes das lojas.
	 */
	public function lojas_sem_dados() {
		$nomes = array();

		foreach ( Reconectar_Pagamento_Direto::lojas_do_carrinho() as $loja_id ) {
			if ( $this->loja_recebe( $loja_id ) ) {
				continue;
			}

			$nome = Reconectar_Pagamento_Direto::nome_da_loja( $loja_id );

			if ( $nome ) {
				$nomes[] = $nome;
			}
		}

		return $nomes;
	}

	/**
	 * Imprime a descrição e quem não recebe por aqui.
	 *
	 * Nenhum dado de recebimento sai no checkout: chave PIX, conta bancária, QR
	 * Code e copia-e-cola são da tela de agradecimento e do e-mail, onde o número
	 * do pedido e o valor definitivo de cada loja já existem. Aqui a descrição diz
	 * apenas que as instruções vêm depois.
	 *
	 * @return void
	 */
	public function payment_fields() {
		if ( $this->description ) {
			echo wp_kses_post( wpautop( wptexturize( $this->description ) ) );
		}

		$sem_dados = $this->lojas_sem_dados();

		if ( ! $sem_dados ) {
			return;
		}

		printf(
			'<p class="rc-pagamento__alerta">%s</p>',
			esc_html(
				sprintf(
					/* translators: %s: lista de nomes de lojas. */
					_n(
						'A loja %s não recebe por este meio. Escolha outro meio ou remova os produtos dela do carrinho.',
						'Estas lojas não recebem por este meio: %s. Escolha outro meio ou remova os produtos delas do carrinho.',
						count( $sem_dados ),
						'reconectar-core'
					),
					implode( ', ', $sem_dados )
				)
			)
		);
	}

	/**
	 * Fecha o pedido sem cobrar nada.
	 *
	 * O status é o `on-hold` do WooCommerce, e não um dos autorais: tanto
	 * `wc-preparacao` quanto `wc-enviado` pressupõem pagamento já recebido —
	 * usá-los aqui declararia pago um pedido que ninguém conferiu.
	 *
	 * Mudar o status do pai basta: `WeDevs\Dokan\Order\Hooks::on_order_status_change`
	 * está pendurado em `woocommerce_order_status_changed` e leva a mudança aos
	 * sub-pedidos. Repetir a troca aqui, loja a loja, dispararia a cadeia duas
	 * vezes — e com ela os e-mails de cada sub-pedido.
	 *
	 * @param int $pedido_id Identificador do pedido.
	 * @return array Resultado no formato que o WooCommerce espera.
	 */
	public function process_payment( $pedido_id ) {
		$pedido = wc_get_order( $pedido_id );

		if ( ! $pedido ) {
			return array( 'result' => 'failure' );
		}

		$pedido->update_status(
			'on-hold',
			sprintf(
				/* translators: %s: nome do meio de pagamento. */
				__( 'Aguardando pagamento por %s, feito direto à loja. A loja confirma o recebimento à mão.', 'reconectar-core' ),
				$this->get_title()
			)
		);

		// A transição para `on-hold` já chama `wc_maybe_reduce_stock_levels()`.
		// A chamada explícita continua aqui porque é o que o `bacs` do núcleo
		// faz, e porque ela é guardada por `_order_stock_reduced` — não há baixa
		// dupla, e o dia em que a lista de ganchos do status mudar o estoque
		// continua saindo.
		wc_reduce_stock_levels( $pedido_id );

		if ( WC()->cart ) {
			WC()->cart->empty_cart();
		}

		return array(
			'result'   => 'success',
			'redirect' => $this->get_return_url( $pedido ),
		);
	}
}
