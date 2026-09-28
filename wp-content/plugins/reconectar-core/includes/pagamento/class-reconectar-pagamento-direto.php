<?php
/**
 * Pagamento direto do comprador à loja, por PIX ou transferência.
 *
 * O dinheiro **não passa pela plataforma**. A loja cadastra os dados de
 * recebimento na dashboard do Dokan, o comprador vê esses dados depois de
 * fechar o pedido e paga por fora; a loja confirma à mão, mudando o status.
 * Não há confirmação automática porque não há API de banco envolvida —
 * fabricar uma seria inventar um dado, e a regra deste projeto é não inventar.
 *
 * Esta classe é a parte comum dos dois meios: registra os gateways, descobre
 * quais lojas há num pedido e imprime as instruções. O que muda de um meio
 * para o outro são os campos e a forma de pagar, e isso mora em cada gateway.
 *
 * @package reconectar-core
 */

defined( 'ABSPATH' ) || exit;

/**
 * Coordena os dois meios de pagamento direto.
 */
class Reconectar_Pagamento_Direto {

	/**
	 * Identificador do gateway de PIX.
	 */
	const GATEWAY_PIX = 'reconectar_pix';

	/**
	 * Identificador do gateway de transferência bancária.
	 */
	const GATEWAY_TRANSFERENCIA = 'reconectar_transferencia';

	/**
	 * Registra os ganchos.
	 *
	 * @return void
	 */
	public static function init() {
		add_filter( 'woocommerce_payment_gateways', array( __CLASS__, 'registrar_gateways' ) );
		add_filter( 'woocommerce_no_available_payment_methods_message', array( __CLASS__, 'explicar_ausencia_de_meios' ) );
		add_action( 'woocommerce_thankyou', array( __CLASS__, 'instrucoes_na_tela' ), 5 );
		add_action( 'woocommerce_email_before_order_table', array( __CLASS__, 'instrucoes_no_email' ), 10, 4 );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'carregar_assets' ) );
	}

	/**
	 * Carrega o CSS e o JavaScript das instruções.
	 *
	 * Só onde há instrução para mostrar — a tela de agradecimento e a de um
	 * pedido em "Minha conta" — e o CSS não depende do tema ativo, porque o
	 * plugin não pode exigir tema nenhum: os tamanhos vêm dos degraus
	 * `--rc-fonte-*` com recuo literal, como no painel de empresas.
	 *
	 * @return void
	 */
	public static function carregar_assets() {
		if ( ! function_exists( 'is_wc_endpoint_url' ) ) {
			return;
		}

		if ( ! is_wc_endpoint_url( 'order-received' ) && ! is_wc_endpoint_url( 'view-order' ) ) {
			return;
		}

		wp_enqueue_style(
			'reconectar-pagamento',
			RECONECTAR_CORE_URL . 'assets/css/pagamento.css',
			array(),
			'0.1.0'
		);
		wp_enqueue_script(
			'reconectar-pagamento',
			RECONECTAR_CORE_URL . 'assets/js/pagamento.js',
			array(),
			'0.1.0',
			true
		);
		wp_localize_script(
			'reconectar-pagamento',
			'reconectarPagamento',
			array(
				'copiado' => __( 'Código copiado', 'reconectar-core' ),
				'falhou'  => __( 'Não foi possível copiar. Selecione o código e copie à mão.', 'reconectar-core' ),
			)
		);
	}

	/**
	 * Acrescenta os dois gateways à lista do WooCommerce.
	 *
	 * Os arquivos das classes são exigidos **aqui**, e não no carregamento do
	 * plugin: `WC_Payment_Gateway` é do WooCommerce, e um `extends` de classe
	 * inexistente é fatal em tempo de compilação do arquivo. Este filtro só
	 * dispara quando o WooCommerce já montou as próprias classes, o que torna a
	 * ordem de carregamento irrelevante.
	 *
	 * @param array $gateways Gateways já conhecidos.
	 * @return array Gateways com os dois autorais no fim.
	 */
	public static function registrar_gateways( $gateways ) {
		require_once RECONECTAR_CORE_PATH . 'includes/pagamento/class-reconectar-gateway-direto.php';
		require_once RECONECTAR_CORE_PATH . 'includes/pagamento/class-reconectar-gateway-pix.php';
		require_once RECONECTAR_CORE_PATH . 'includes/pagamento/class-reconectar-gateway-transferencia.php';

		$gateways[] = 'Reconectar_Gateway_Pix';
		$gateways[] = 'Reconectar_Gateway_Transferencia';

		return $gateways;
	}

	/**
	 * Devolve as lojas de um pedido, cada uma com o valor que lhe cabe.
	 *
	 * O Dokan Lite divide o pedido em sub-pedidos, um por loja
	 * (`woocommerce_checkout_update_order_meta`). É deles que sai o valor: um
	 * bloco único com o total mandaria o comprador pagar o carrinho inteiro
	 * para uma das lojas.
	 *
	 * `dokan_get_suborder_ids_by()` devolve `null` — e não lista vazia — quando
	 * o pedido tem uma loja só, que é o caso comum. Medido também que os totais
	 * dos sub-pedidos somam o total do pai; ainda assim, quem imprime confere a
	 * soma, porque um frete lançado no pai apareceria como diferença.
	 *
	 * @param WC_Order $pedido Pedido, pai ou avulso.
	 * @return array Lista de `array( 'loja_id', 'total', 'pedido' )`.
	 */
	public static function lojas_do_pedido( $pedido ) {
		$blocos = array();

		if ( ! $pedido instanceof WC_Order ) {
			return $blocos;
		}

		$sub_ids = dokan_get_suborder_ids_by( $pedido->get_id() );

		if ( ! empty( $sub_ids ) ) {
			foreach ( $sub_ids as $sub_id ) {
				$sub = wc_get_order( $sub_id );

				if ( ! $sub ) {
					continue;
				}

				$blocos[] = array(
					'loja_id' => (int) dokan_get_seller_id_by_order( $sub_id ),
					'total'   => (float) $sub->get_total(),
					'pedido'  => $sub,
				);
			}

			return $blocos;
		}

		$loja_id = (int) dokan_get_seller_id_by_order( $pedido->get_id() );

		if ( $loja_id ) {
			$blocos[] = array(
				'loja_id' => $loja_id,
				'total'   => (float) $pedido->get_total(),
				'pedido'  => $pedido,
			);
		}

		return $blocos;
	}

	/**
	 * Devolve os identificadores das lojas presentes no carrinho.
	 *
	 * Serve ao `is_available()` dos gateways, que precisa decidir antes de
	 * existir pedido. A posse do produto no Dokan é o autor do post — a mesma
	 * fonte que o plugin usa —, e o item do carrinho já guarda o ID do produto
	 * pai, então a variação não precisa de tratamento à parte.
	 *
	 * @return int[] Identificadores, sem repetição.
	 */
	public static function lojas_do_carrinho() {
		$lojas = array();

		if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
			return $lojas;
		}

		foreach ( WC()->cart->get_cart() as $item ) {
			if ( empty( $item['product_id'] ) ) {
				continue;
			}

			$autor = (int) get_post_field( 'post_author', $item['product_id'] );

			if ( $autor ) {
				$lojas[ $autor ] = $autor;
			}
		}

		return array_values( $lojas );
	}

	/**
	 * Diz por que a lista de meios de pagamento está vazia.
	 *
	 * A mensagem de fábrica do WooCommerce — "não há métodos de pagamento
	 * disponíveis, entre em contato" — descreve o efeito e esconde a causa. Quem
	 * lê não tem como saber que o problema é de uma loja específica do carrinho,
	 * nem que remover os produtos dela resolveria; o caminho plausível, e errado,
	 * é concluir que a plataforma está quebrada.
	 *
	 * O motivo verdadeiro é sempre o mesmo: alguma loja não declarou como recebe.
	 * A lista sai dos gateways instanciados, não de uma relação de meios escrita
	 * aqui — um meio novo passa a contar sozinho, e não há duas listas para
	 * divergirem.
	 *
	 * @param string $mensagem Mensagem de fábrica do WooCommerce.
	 * @return string Mensagem com o nome das lojas, ou a de fábrica.
	 */
	public static function explicar_ausencia_de_meios( $mensagem ) {
		if ( ! class_exists( 'Reconectar_Gateway_Direto' ) || ! WC()->payment_gateways() ) {
			return $mensagem;
		}

		$diretos = array();

		foreach ( WC()->payment_gateways()->payment_gateways() as $gateway ) {
			if ( $gateway instanceof Reconectar_Gateway_Direto ) {
				$diretos[] = $gateway;
			}
		}

		if ( ! $diretos ) {
			return $mensagem;
		}

		$sem_meio = array();

		foreach ( self::lojas_do_carrinho() as $loja_id ) {
			foreach ( $diretos as $gateway ) {
				if ( $gateway->loja_recebe( $loja_id ) ) {
					continue 2;
				}
			}

			$nome = self::nome_da_loja( $loja_id );

			if ( $nome ) {
				$sem_meio[] = $nome;
			}
		}

		if ( ! $sem_meio ) {
			return $mensagem;
		}

		return sprintf(
			/* translators: %s: lista de nomes de lojas. */
			_n(
				'A loja %s ainda não cadastrou uma forma de receber, e por isso este pedido não pode ser fechado. Remova os produtos dela do carrinho ou fale com a plataforma.',
				'Estas lojas ainda não cadastraram uma forma de receber, e por isso este pedido não pode ser fechado: %s. Remova os produtos delas do carrinho ou fale com a plataforma.',
				count( $sem_meio ),
				'reconectar-core'
			),
			implode( ', ', $sem_meio )
		);
	}

	/**
	 * Lê um bloco de dados de recebimento do perfil da loja.
	 *
	 * @param int    $loja_id Identificador da loja.
	 * @param string $metodo  Chave do método dentro de `payment`.
	 * @return array Dados gravados, ou vazio.
	 */
	public static function dados_de_pagamento( $loja_id, $metodo ) {
		$perfil = get_user_meta( (int) $loja_id, 'dokan_profile_settings', true );

		if ( empty( $perfil['payment'][ $metodo ] ) || ! is_array( $perfil['payment'][ $metodo ] ) ) {
			return array();
		}

		return $perfil['payment'][ $metodo ];
	}

	/**
	 * Nome da loja, com recuo para o nome de exibição do usuário.
	 *
	 * @param int $loja_id Identificador da loja.
	 * @return string Nome da loja.
	 */
	public static function nome_da_loja( $loja_id ) {
		$loja = dokan()->vendor->get( (int) $loja_id );

		if ( $loja && $loja->get_shop_name() ) {
			return $loja->get_shop_name();
		}

		$usuario = get_userdata( (int) $loja_id );

		return $usuario ? $usuario->display_name : '';
	}

	/**
	 * Imprime as instruções na tela de agradecimento.
	 *
	 * @param int $pedido_id Identificador do pedido.
	 * @return void
	 */
	public static function instrucoes_na_tela( $pedido_id ) {
		self::imprimir_instrucoes( wc_get_order( $pedido_id ) );
	}

	/**
	 * Imprime as instruções no corpo do e-mail do pedido.
	 *
	 * Só no e-mail destinado ao comprador: o da loja não precisa repetir os
	 * dados que ela mesma cadastrou, e mandá-los para o administrador seria
	 * espalhar uma chave PIX — que costuma ser o CPF de uma pessoa — por mais
	 * caixas de entrada do que o necessário.
	 *
	 * @param WC_Order $pedido      Pedido.
	 * @param bool     $para_a_loja Se o e-mail vai para a loja/administração.
	 * @param bool     $texto_puro  Se o e-mail é em texto puro.
	 * @param WC_Email $email       Objeto do e-mail.
	 * @return void
	 */
	public static function instrucoes_no_email( $pedido, $para_a_loja = false, $texto_puro = false, $email = null ) {
		unset( $texto_puro, $email );

		if ( $para_a_loja ) {
			return;
		}

		self::imprimir_instrucoes( $pedido, 'email' );
	}

	/**
	 * Monta o bloco de instruções de um pedido, uma loja por vez.
	 *
	 * Quando a soma dos sub-pedidos não fecha com o total, a diferença é
	 * declarada em vez de omitida: o comprador precisa saber que ainda há algo
	 * a acertar, e um valor calculado para parecer certo seria pior que a
	 * diferença exposta.
	 *
	 * @param WC_Order|false $pedido   Pedido.
	 * @param string         $contexto `tela` ou `email`.
	 * @return void
	 */
	public static function imprimir_instrucoes( $pedido, $contexto = 'tela' ) {
		if ( ! $pedido instanceof WC_Order ) {
			return;
		}

		$gateways = WC()->payment_gateways() ? WC()->payment_gateways()->payment_gateways() : array();
		$metodo   = $pedido->get_payment_method();

		if ( ! isset( $gateways[ $metodo ] ) || ! $gateways[ $metodo ] instanceof Reconectar_Gateway_Direto ) {
			return;
		}

		$gateway = $gateways[ $metodo ];
		$blocos  = self::lojas_do_pedido( $pedido );

		if ( ! $blocos ) {
			return;
		}

		echo '<section class="rc-pagamento">';
		printf(
			'<h2 class="rc-pagamento__titulo">%s</h2>',
			esc_html( $gateway->get_title() )
		);
		printf(
			'<p class="rc-pagamento__aviso">%s</p>',
			esc_html__( 'O pagamento é feito direto para cada loja. O pedido é liberado quando a loja confirmar o recebimento.', 'reconectar-core' )
		);

		$somado = 0.0;

		foreach ( $blocos as $bloco ) {
			$somado += (float) $bloco['total'];

			echo '<div class="rc-pagamento__loja">';
			printf(
				'<h3 class="rc-pagamento__nome">%s</h3>',
				esc_html( self::nome_da_loja( $bloco['loja_id'] ) )
			);
			printf(
				'<p class="rc-pagamento__valor">%s</p>',
				wp_kses_post(
					sprintf(
						/* translators: %s: valor a pagar para esta loja. */
						__( 'Valor a pagar: %s', 'reconectar-core' ),
						wc_price( $bloco['total'] )
					)
				)
			);

			$gateway->imprimir_instrucao( $bloco['loja_id'], $bloco['total'], $bloco['pedido'], $contexto );

			echo '</div>';
		}

		$diferenca = round( (float) $pedido->get_total() - $somado, 2 );

		if ( abs( $diferenca ) >= 0.01 ) {
			printf(
				'<p class="rc-pagamento__diferenca">%s</p>',
				wp_kses_post(
					sprintf(
						/* translators: %s: diferença entre o total do pedido e a soma das lojas. */
						__( 'Os valores acima não fecham o total do pedido: faltam %s, que serão cobrados à parte. Entre em contato com a plataforma antes de pagar.', 'reconectar-core' ),
						wc_price( $diferenca )
					)
				)
			);
		}

		echo '</section>';
	}
}
