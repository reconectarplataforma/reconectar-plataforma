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
	 * Meta do pedido pai com o mapa `loja_id => gateway_id`.
	 */
	const META_MEIOS = '_reconectar_meios_por_loja';

	/**
	 * Chave da sessão do WooCommerce com a escolha em andamento.
	 */
	const SESSAO_MEIOS = 'reconectar_meios_por_loja';

	/**
	 * Nome do campo de escolha no formulário de checkout.
	 */
	const CAMPO_MEIOS = 'rc_pagamento';

	/**
	 * Status em que o pedido ainda espera o pagamento.
	 *
	 * `on-hold` é o que `process_payment()` grava; `pending` cobre o pedido que
	 * nunca chegou a passar por ele — fechamento interrompido, retorno para
	 * pagamento. `conferencia` entra porque o comprovante enviado ainda **não** é
	 * pagamento conferido: sem ele, `Reconectar_Comprovante::confirmado()` — que é
	 * a negação desta lista — passaria a responder "sim" no instante seguinte ao
	 * envio, e o campo de troca de comprovante sumiria da tela do comprador junto
	 * com o botão de confirmação da loja, sem erro nenhum. Os dois autorais
	 * restantes (`wc-preparacao`, `wc-enviado`) ficam de fora de propósito: ambos
	 * pressupõem recebimento já confirmado pela loja, e repetir ali um QR Code
	 * pediria ao comprador que pagasse de novo.
	 */
	const STATUS_AGUARDANDO = array( 'on-hold', 'pending', 'conferencia' );

	/**
	 * Registra os ganchos.
	 *
	 * A gravação do meio de cada loja fica em **prioridade 30** de propósito:
	 * `WeDevs\Dokan\Order\Hooks::split_vendor_orders` cria os sub-pedidos na 10 e
	 * `dokan_sync_insert_order` os espelha nas tabelas do Dokan na 20. Em
	 * qualquer prioridade menor não há sub-pedido para receber a meta.
	 *
	 * @return void
	 */
	public static function init() {
		add_filter( 'woocommerce_payment_gateways', array( __CLASS__, 'registrar_gateways' ) );
		add_filter( 'woocommerce_no_available_payment_methods_message', array( __CLASS__, 'explicar_ausencia_de_meios' ) );
		add_action( 'woocommerce_checkout_update_order_review', array( __CLASS__, 'guardar_escolha' ) );
		add_filter( 'woocommerce_checkout_posted_data', array( __CLASS__, 'impor_meio_representativo' ) );
		add_action( 'woocommerce_after_checkout_validation', array( __CLASS__, 'validar_meios_por_loja' ), 10, 2 );
		add_action( 'woocommerce_checkout_update_order_meta', array( __CLASS__, 'gravar_meios_no_pedido' ), 30 );
		add_action( 'woocommerce_thankyou', array( __CLASS__, 'instrucoes_na_tela' ), 5 );
		add_action( 'woocommerce_view_order', array( __CLASS__, 'instrucoes_no_pedido' ), 5 );
		add_action( 'woocommerce_email_before_order_table', array( __CLASS__, 'instrucoes_no_email' ), 10, 4 );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'carregar_assets' ) );
	}

	/**
	 * Carrega o CSS e o JavaScript das instruções.
	 *
	 * Só onde há instrução para mostrar, e o CSS não depende do tema ativo,
	 * porque o plugin não pode exigir tema nenhum: os tamanhos vêm dos degraus
	 * `--rc-fonte-*` com recuo literal, como no painel de empresas.
	 *
	 * `is_checkout()` cobre **duas** telas, e é por isso que ela substituiu o
	 * `is_wc_endpoint_url( 'order-received' )` que estava aqui: a de agradecimento
	 * é um endpoint da própria página de checkout. A de fechamento fica na conta de
	 * propósito, mesmo sem dado de recebimento algum: `payment_fields()` ainda
	 * imprime ali o `.rc-pagamento__alerta` que nomeia a loja que não recebe pelo
	 * meio escolhido, e um alerta sem estilo sai como parágrafo comum — texto que
	 * ninguém lê como aviso, numa tela de pagamento.
	 *
	 * `view-order` entra pelo mesmo bloco de instruções, que
	 * `instrucoes_no_pedido()` repete ali enquanto o pedido aguardar pagamento —
	 * e é de onde vem o botão de copiar o código PIX, que sem o JavaScript não
	 * copia nada.
	 *
	 * @return void
	 */
	public static function carregar_assets() {
		if ( ! function_exists( 'is_checkout' ) ) {
			return;
		}

		// A lista de pedidos do painel da loja consome o mesmo arquivo: a coluna
		// de comprovante é `.rc-comprovante-coluna`, irmã dos blocos do
		// comprador, e separar em dois CSS duplicaria os tokens `--rc-pg-*`.
		// O teste é o painel inteiro, não só a query var `orders`: na interface
		// nova a lista é rota React, e a coluna dela sairia sem estilo.
		$no_painel = class_exists( 'Reconectar_Comprovante' ) && Reconectar_Comprovante::esta_no_painel_da_loja();

		if ( ! is_checkout() && ! is_wc_endpoint_url( 'view-order' ) && ! $no_painel ) {
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
	 * Devolve as lojas do carrinho e quanto de **produto** cabe a cada uma.
	 *
	 * Serve às telas que precisam decidir antes de existir pedido: o
	 * `is_available()` dos gateways e o agrupamento por loja no resumo do checkout
	 * (`reconectar_checkout_itens_por_loja()`, no tema). A posse do produto no
	 * Dokan é o autor do post — a mesma fonte que o plugin usa —, e o item do
	 * carrinho já guarda o ID do produto pai, então a variação não precisa de
	 * tratamento à parte.
	 *
	 * O valor é a soma de `line_total` com `line_tax`, que é o item **depois** do
	 * cupom: o desconto do WooCommerce é rateado por item antes de chegar aqui.
	 * Frete fica de fora, e fica de fora porque não há como atribuí-lo a uma loja
	 * neste ponto sem repetir a divisão que o Dokan só faz ao criar os
	 * sub-pedidos. Por isso quem imprime tem de rotular o número pelo que ele é —
	 * produtos daquela loja — e declarar a diferença até o total do carrinho, em
	 * vez de distribuí-la por conta própria: um valor plausível por loja é
	 * exatamente o que a regra de honestidade de dados do projeto proíbe.
	 *
	 * @return array<int,float> Valor de produtos por loja, indexado pelo ID dela.
	 */
	public static function valores_do_carrinho() {
		$valores = array();

		if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
			return $valores;
		}

		foreach ( WC()->cart->get_cart() as $item ) {
			if ( empty( $item['product_id'] ) ) {
				continue;
			}

			$autor = (int) get_post_field( 'post_author', $item['product_id'] );

			if ( ! $autor ) {
				continue;
			}

			if ( ! isset( $valores[ $autor ] ) ) {
				$valores[ $autor ] = 0.0;
			}

			$valores[ $autor ] += (float) ( isset( $item['line_total'] ) ? $item['line_total'] : 0 )
				+ (float) ( isset( $item['line_tax'] ) ? $item['line_tax'] : 0 );
		}

		return $valores;
	}

	/**
	 * Devolve os identificadores das lojas presentes no carrinho.
	 *
	 * Deriva de `valores_do_carrinho()` de propósito: duas varreduras do carrinho
	 * escritas em paralelo são duas respostas que podem divergir sobre quais
	 * lojas estão no pedido — e a lista de quem recebe e a de quem não recebe
	 * sairiam de fontes diferentes.
	 *
	 * A loja que só tem **serviço** no carrinho fica de fora: ela não recebe nada
	 * agora — o valor é combinado depois, fora da plataforma —, então não escolhe
	 * meio, não precisa de PIX nem de conta, e não pode barrar o pedido por não
	 * tê-los. `valores_do_carrinho()` continua com ela, porque é a soma de
	 * referência do carrinho inteiro (ver a armadilha da soma no `CLAUDE.md`).
	 *
	 * A guarda é um `class_exists` sozinho, nunca num `||` com a chamada — a
	 * armadilha do curto-circuito registrada no `CLAUDE.md`.
	 *
	 * @return int[] Identificadores, sem repetição.
	 */
	public static function lojas_do_carrinho() {
		$lojas = array_map( 'intval', array_keys( self::valores_do_carrinho() ) );

		if ( class_exists( 'Reconectar_Servicos' ) ) {
			$lojas = array_values( array_diff( $lojas, Reconectar_Servicos::lojas_so_de_servico_no_carrinho() ) );
		}

		return $lojas;
	}

	/**
	 * Total do carrinho, cru, para confrontar com a soma das lojas.
	 *
	 * `get_total( 'edit' )` é o que devolve número; sem o contexto, o WooCommerce
	 * entrega o total já formatado em HTML, com símbolo de moeda, e a subtração
	 * silenciosamente viraria zero.
	 *
	 * @return float Total do carrinho.
	 */
	public static function total_do_carrinho() {
		if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
			return 0.0;
		}

		return (float) WC()->cart->get_total( 'edit' );
	}

	/**
	 * Devolve as instâncias de gateway de pagamento direto.
	 *
	 * Fonte única de quem são "os meios da plataforma": um meio novo passa a
	 * contar sozinho por ser instância do abstrato, e não há lista de
	 * identificadores escrita à mão para divergir da realidade.
	 *
	 * O parâmetro existe porque as duas perguntas são diferentes. Para **oferecer**
	 * um meio no checkout, um gateway desligado no `/wp-admin` não conta. Para
	 * **imprimir a instrução** de um pedido já fechado, conta: o meio pode ter sido
	 * desligado depois, e o comprador continua tendo de saber como pagar aquilo
	 * que já comprou.
	 *
	 * @param bool $somente_ativos Se descarta os gateways desligados.
	 * @return Reconectar_Gateway_Direto[] Gateways, indexados pelo identificador.
	 */
	public static function gateways_diretos( $somente_ativos = true ) {
		$diretos = array();

		if ( ! function_exists( 'WC' ) || ! WC()->payment_gateways() ) {
			return $diretos;
		}

		/*
		 * Sem `class_exists( 'Reconectar_Gateway_Direto' )` na guarda acima, e a
		 * ausência é a correção. Os arquivos das classes são exigidos dentro de
		 * `registrar_gateways()`, que é o filtro `woocommerce_payment_gateways` —
		 * ele só dispara quando alguém chama `WC()->payment_gateways()`. Testar a
		 * classe **antes** dessa chamada curto-circuita o `||` e devolve lista
		 * vazia sem nunca consultar o WooCommerce: a guarda impedia justamente o
		 * carregamento que ela estava conferindo.
		 *
		 * O sintoma é mudo e depende de quem chamou primeiro. Na renderização o
		 * `#payment` já havia inicializado os gateways, então a tela saía correta;
		 * em `woocommerce_checkout_update_order_review`, que roda antes disso,
		 * `guardar_escolha()` via zero meios e gravava escolha vazia na sessão.
		 * Medido na requisição AJAX, com o POST trazendo as três lojas:
		 *
		 *   carrinho = 3 itens   lojas = [26, 25, 24]
		 *   postado  = {26: transferência, 25: transferência, 24: pix}
		 *   gateways = []   →   meios de cada loja = []
		 *
		 * O `instanceof` logo abaixo é seguro com classe inexistente — devolve
		 * `false`, não fatal —, e depois da chamada acima ela sempre existe.
		 */
		foreach ( WC()->payment_gateways()->payment_gateways() as $id => $gateway ) {
			if ( ! $gateway instanceof Reconectar_Gateway_Direto ) {
				continue;
			}

			if ( $somente_ativos && 'yes' !== $gateway->enabled ) {
				continue;
			}

			$diretos[ $id ] = $gateway;
		}

		return $diretos;
	}

	/**
	 * Devolve os meios que **aquela** loja aceita.
	 *
	 * @param int $loja_id Identificador da loja.
	 * @return Reconectar_Gateway_Direto[] Gateways aceitos, indexados pelo identificador.
	 */
	public static function meios_da_loja( $loja_id ) {
		$aceitos = array();

		foreach ( self::gateways_diretos() as $id => $gateway ) {
			if ( $gateway->loja_recebe( $loja_id ) ) {
				$aceitos[ $id ] = $gateway;
			}
		}

		return $aceitos;
	}

	/**
	 * Lê as escolhas guardadas na sessão do carrinho.
	 *
	 * @return array<int,string> Mapa `loja_id => gateway_id`.
	 */
	protected static function escolhas_da_sessao() {
		if ( ! function_exists( 'WC' ) || ! WC()->session ) {
			return array();
		}

		$guardado = WC()->session->get( self::SESSAO_MEIOS );

		return is_array( $guardado ) ? $guardado : array();
	}

	/**
	 * Diz qual meio está escolhido para uma loja.
	 *
	 * Fonte única da renderização, da validação e da gravação — se cada uma
	 * decidisse por conta própria, a tela poderia mostrar um meio e o pedido
	 * gravar outro, sem nada acusar.
	 *
	 * A ordem das três fontes é a da recência: o POST é a submissão que está
	 * acontecendo, a sessão é a escolha da última recalculada por AJAX, e o
	 * primeiro meio aceito é o padrão de quem nunca escolheu nada. Em qualquer
	 * uma delas o valor só passa se estiver entre os meios **daquela** loja: é
	 * essa conferência, e não a origem do dado, que impede pedir para pagar por
	 * um caminho que a loja não tem.
	 *
	 * @param int $loja_id Identificador da loja.
	 * @return string Identificador do gateway, ou string vazia se a loja não recebe.
	 */
	public static function meio_escolhido( $loja_id ) {
		$loja_id = (int) $loja_id;
		$aceitos = self::meios_da_loja( $loja_id );

		if ( ! $aceitos ) {
			return '';
		}

		/*
		 * O nonce deste POST é o `woocommerce-process-checkout-nonce`, conferido
		 * por `WC_Checkout::process_checkout()` antes de qualquer gancho que chame
		 * este método. O caminho AJAX não passa por aqui: ele chega pela sessão,
		 * gravada em `guardar_escolha()`.
		 */
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( isset( $_POST[ self::CAMPO_MEIOS ][ $loja_id ] ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing
			$postado = sanitize_text_field( wp_unslash( $_POST[ self::CAMPO_MEIOS ][ $loja_id ] ) );

			if ( isset( $aceitos[ $postado ] ) ) {
				return $postado;
			}
		}

		$sessao = self::escolhas_da_sessao();

		if ( isset( $sessao[ $loja_id ] ) && isset( $aceitos[ $sessao[ $loja_id ] ] ) ) {
			return (string) $sessao[ $loja_id ];
		}

		$ids = array_keys( $aceitos );

		return (string) reset( $ids );
	}

	/**
	 * Mapa `loja_id => gateway_id` de todas as lojas do carrinho.
	 *
	 * Lojas que não recebem por meio nenhum ficam de fora — quem as nomeia é
	 * `validar_meios_por_loja()`, e inventar um meio para elas produziria um
	 * pedido que ninguém consegue pagar.
	 *
	 * @return array<int,string> Mapa das escolhas.
	 */
	public static function meios_escolhidos() {
		$mapa = array();

		foreach ( self::lojas_do_carrinho() as $loja_id ) {
			$meio = self::meio_escolhido( $loja_id );

			if ( $meio ) {
				$mapa[ (int) $loja_id ] = $meio;
			}
		}

		return $mapa;
	}

	/**
	 * Guarda na sessão a escolha feita em cada colapse de loja.
	 *
	 * `woocommerce_checkout_update_order_review` recebe o formulário serializado
	 * como **string** — é o `post_data` que o `checkout.js` monta —, e não o array
	 * já parseado. Sem isso, trocar o meio de uma loja recalcularia os totais com
	 * a escolha anterior, e o resumo mostraria um meio diferente do que está
	 * marcado na tela.
	 *
	 * A mescla sobre o que já estava guardado é o que permite que uma requisição
	 * parcial — ou um formulário ainda sem os rádios, no primeiro carregamento —
	 * não apague a escolha das outras lojas.
	 *
	 * @param string $post_data Formulário do checkout, serializado.
	 * @return void
	 */
	public static function guardar_escolha( $post_data ) {
		if ( ! function_exists( 'WC' ) || ! WC()->session ) {
			return;
		}

		$campos = array();
		parse_str( (string) $post_data, $campos );

		$postado  = isset( $campos[ self::CAMPO_MEIOS ] ) && is_array( $campos[ self::CAMPO_MEIOS ] )
			? $campos[ self::CAMPO_MEIOS ]
			: array();
		$anterior = self::escolhas_da_sessao();
		$escolhas = array();

		foreach ( self::lojas_do_carrinho() as $loja_id ) {
			$loja_id = (int) $loja_id;
			$aceitos = self::meios_da_loja( $loja_id );

			if ( ! $aceitos ) {
				continue;
			}

			if ( isset( $postado[ $loja_id ] ) && isset( $aceitos[ $postado[ $loja_id ] ] ) ) {
				$escolhas[ $loja_id ] = sanitize_text_field( $postado[ $loja_id ] );
				continue;
			}

			if ( isset( $anterior[ $loja_id ] ) && isset( $aceitos[ $anterior[ $loja_id ] ] ) ) {
				$escolhas[ $loja_id ] = (string) $anterior[ $loja_id ];
				continue;
			}

			/*
			 * O terceiro degrau é o mesmo de `meio_escolhido()` — o primeiro meio
			 * aceito — e existe para que as duas não divirjam. Sem ele a loja
			 * ficava **fora** do mapa enquanto ninguém tocasse no rádio dela, e a
			 * sessão registrava menos lojas do que a tela mostrava: o resumo
			 * imprimia um meio que `meio_representativo()` não enxergava, e o
			 * `chosen_payment_method` do rádio global ficava sem alinhamento.
			 */
			$ids                  = array_keys( $aceitos );
			$escolhas[ $loja_id ] = (string) reset( $ids );
		}

		WC()->session->set( self::SESSAO_MEIOS, $escolhas );

		/*
		 * Alinha o rádio global de `#payment`, que fica escondido e ainda assim é
		 * quem leva o `payment_method` do POST — mas **só a partir da requisição
		 * seguinte**, e é por isso que ele não é a trava do fluxo. Medido numa
		 * recalculada, com a primeira loja em transferência e o DOM ainda em PIX:
		 *
		 *   update_order_review[1]   antes do nosso  => reconectar_pix
		 *   update_order_review[999] depois do nosso => reconectar_transferencia
		 *
		 * O próprio `WC_AJAX::update_order_review()` grava `chosen_payment_method`
		 * a partir do `payment_method` postado **antes** de disparar a ação, e o
		 * `#payment` do fragmento já foi decidido com aquele valor. Escrever aqui
		 * corrige o carregamento por GET, quando não há POST para sobrepor — o
		 * que basta, porque quem impõe o meio na submissão é
		 * `impor_meio_representativo()`, em `woocommerce_checkout_posted_data`.
		 *
		 * Perseguir o alinhamento na mesma requisição custaria mexer na
		 * propriedade `chosen` dos objetos de gateway dentro de
		 * `woocommerce_available_payment_gateways`, dependendo de uma ordem
		 * interna que o WooCommerce não promete. Para um rádio que ninguém vê,
		 * e cujo valor é reescrito no POST, o preço não se paga.
		 */
		$representativo = self::meio_representativo( $escolhas );

		if ( $representativo ) {
			WC()->session->set( 'chosen_payment_method', $representativo );
		}
	}

	/**
	 * Escolhe o meio que representa o pedido **pai**.
	 *
	 * O WooCommerce processa um gateway por submissão, e é esse que o pai carrega.
	 * Não há perda: `process_payment()` é idêntico nos dois meios — deixa o pedido
	 * em `on-hold`, dá baixa no estoque e esvazia o carrinho —, então qual dos dois
	 * roda não muda o que acontece. A verdade por loja mora na meta
	 * `META_MEIOS` e no `_payment_method` de cada sub-pedido; o que um humano lê
	 * no painel sai do `_payment_method_title`, que lista os meios realmente usados.
	 *
	 * @param array<int,string> $escolhas Mapa `loja_id => gateway_id`.
	 * @return string Identificador do gateway, ou string vazia.
	 */
	public static function meio_representativo( $escolhas ) {
		if ( ! $escolhas ) {
			return '';
		}

		$ids = array_values( $escolhas );

		return (string) reset( $ids );
	}

	/**
	 * Alinha o `payment_method` da submissão ao meio da primeira loja.
	 *
	 * `woocommerce_checkout_posted_data` roda em `get_posted_data()`, **antes** de
	 * `validate_checkout()`: o gateway que o WooCommerce valida e executa é o que
	 * sai daqui. Sem este filtro, o rádio global escondido poderia levar um meio
	 * que nenhuma loja do carrinho escolheu — e o pedido pai gravaria esse meio.
	 *
	 * @param array $dados Dados postados do checkout.
	 * @return array Dados com o `payment_method` alinhado.
	 */
	public static function impor_meio_representativo( $dados ) {
		$representativo = self::meio_representativo( self::meios_escolhidos() );

		if ( $representativo ) {
			$dados['payment_method'] = $representativo;
		}

		return $dados;
	}

	/**
	 * Recusa o pedido quando alguma loja do carrinho não recebe por meio nenhum.
	 *
	 * Ficou aqui, e não em cada gateway, porque a pergunta deixou de ser "esta
	 * loja recebe pelo meio escolhido?" para ser "esta loja recebe por algum
	 * meio?" — com a escolha feita loja a loja, a primeira pergunta é respondida
	 * pela própria renderização, que só oferece o que a loja aceita.
	 *
	 * @param array $campos Campos do checkout.
	 * @param mixed $erros  Objeto de erros do WooCommerce.
	 * @return void
	 */
	public static function validar_meios_por_loja( $campos, $erros ) {
		unset( $campos );

		if ( ! is_object( $erros ) ) {
			return;
		}

		$sem_meio = array();

		foreach ( self::lojas_do_carrinho() as $loja_id ) {
			if ( self::meios_da_loja( $loja_id ) ) {
				continue;
			}

			$nome = self::nome_da_loja( $loja_id );

			if ( $nome ) {
				$sem_meio[] = $nome;
			}
		}

		if ( ! $sem_meio ) {
			return;
		}

		$erros->add(
			'reconectar_pagamento_indisponivel',
			sprintf(
				/* translators: %s: lista de nomes de lojas. */
				esc_html(
					_n(
						'Não é possível fechar o pedido: %s ainda não cadastrou uma forma de receber.',
						'Não é possível fechar o pedido: estas lojas ainda não cadastraram uma forma de receber: %s.',
						count( $sem_meio ),
						'reconectar-core'
					)
				),
				esc_html( implode( ', ', $sem_meio ) )
			)
		);
	}

	/**
	 * Grava o meio de cada loja no pedido pai e em cada sub-pedido.
	 *
	 * Prioridade 30 — ver o comentário de `init()`. Antes disso os sub-pedidos do
	 * Dokan ainda não existem, e a gravação cairia no vazio sem erro nenhum.
	 *
	 * @param int $pedido_id Identificador do pedido pai.
	 * @return void
	 */
	public static function gravar_meios_no_pedido( $pedido_id ) {
		$escolhas = self::meios_escolhidos();

		if ( ! $escolhas ) {
			return;
		}

		$pedido = wc_get_order( $pedido_id );

		if ( ! $pedido ) {
			return;
		}

		$gateways = self::gateways_diretos( false );

		$pedido->update_meta_data( self::META_MEIOS, $escolhas );
		$pedido->set_payment_method_title( self::juntar_titulos( $escolhas, $gateways ) );
		$pedido->save();

		if ( function_exists( 'WC' ) && WC()->session ) {
			WC()->session->set( self::SESSAO_MEIOS, array() );
		}

		$sub_ids = dokan_get_suborder_ids_by( $pedido_id );

		if ( empty( $sub_ids ) ) {
			return;
		}

		foreach ( $sub_ids as $sub_id ) {
			$sub = wc_get_order( $sub_id );

			if ( ! $sub ) {
				continue;
			}

			$loja_id = (int) dokan_get_seller_id_by_order( $sub_id );

			// O Dokan copia o meio do pai a todo sub-pedido. No do prestador de
			// serviço isso poria "PIX" num pedido de R$ 0 sem nada a pagar, e a
			// tela de agradecimento e o e-mail imprimiriam a instrução.
			if ( class_exists( 'Reconectar_Servicos' ) && Reconectar_Servicos::pedido_so_de_servico( $sub ) ) {
				$sub->set_payment_method( '' );
				$sub->set_payment_method_title( __( 'Serviço — valor combinado com o prestador', 'reconectar-core' ) );
				$sub->save();
				continue;
			}

			if ( ! isset( $escolhas[ $loja_id ] ) ) {
				continue;
			}

			$meio = $escolhas[ $loja_id ];

			$sub->set_payment_method( $meio );

			if ( isset( $gateways[ $meio ] ) ) {
				$sub->set_payment_method_title( $gateways[ $meio ]->get_title() );
			}

			$sub->save();
		}
	}

	/**
	 * Junta os títulos dos meios usados numa frase legível.
	 *
	 * @param array<int,string>              $escolhas Mapa `loja_id => gateway_id`.
	 * @param Reconectar_Gateway_Direto[]    $gateways Gateways conhecidos.
	 * @return string Títulos separados por vírgula, o último por "e".
	 */
	public static function juntar_titulos( $escolhas, $gateways ) {
		$titulos = array();

		foreach ( array_unique( $escolhas ) as $id ) {
			if ( isset( $gateways[ $id ] ) ) {
				$titulos[] = $gateways[ $id ]->get_title();
			}
		}

		if ( ! $titulos ) {
			return '';
		}

		if ( 1 === count( $titulos ) ) {
			return (string) reset( $titulos );
		}

		$ultimo = array_pop( $titulos );

		return sprintf(
			/* translators: 1: lista de meios separados por vírgula. 2: último meio da lista. */
			_x( '%1$s e %2$s', 'lista de meios de pagamento', 'reconectar-core' ),
			implode( ', ', $titulos ),
			$ultimo
		);
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
		if ( ! self::gateways_diretos() ) {
			return $mensagem;
		}

		$sem_meio = array();

		foreach ( self::lojas_do_carrinho() as $loja_id ) {
			if ( self::meios_da_loja( $loja_id ) ) {
				continue;
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
	 * Repete as instruções na tela de detalhes do pedido, enquanto ele aguardar.
	 *
	 * A tela de agradecimento é vista uma vez, no minuto do fechamento, e quem
	 * fecha o pedido no computador e vai pagar no celular precisa do QR Code
	 * outra vez — hoje o único caminho de volta é o e-mail, que não traz a
	 * imagem (`imprimir_instrucao()` a omite ali de propósito, porque cliente de
	 * e-mail descarta SVG embutido).
	 *
	 * Diferente da tela de agradecimento, esta é guardada por status: ela
	 * continua acessível depois de a loja confirmar o recebimento, e um bloco de
	 * pagamento num pedido já pago não é decoração — é um pedido para pagar de
	 * novo, na tela em que o comprador vai conferir se pagou.
	 *
	 * Prioridade 5 pela mesma razão da tela de agradecimento: é onde
	 * `woocommerce_order_details_table` está na 10, e as instruções vêm antes da
	 * conferência do pedido, não depois.
	 *
	 * @param int $pedido_id Identificador do pedido.
	 * @return void
	 */
	public static function instrucoes_no_pedido( $pedido_id ) {
		$pedido = wc_get_order( $pedido_id );

		if ( ! $pedido instanceof WC_Order || ! $pedido->has_status( self::STATUS_AGUARDANDO ) ) {
			return;
		}

		self::imprimir_instrucoes( $pedido );
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
	 * Cada loja escolhe o próprio meio, e é a meta `META_MEIOS` do pedido pai que
	 * guarda essa escolha. **O recuo para `get_payment_method()` é obrigatório**:
	 * todo pedido fechado antes desta entrega não tem a meta, e sem o recuo a
	 * tela de agradecimento e o e-mail deles ficariam mudos — sem erro nenhum,
	 * que é o pior jeito de quebrar uma instrução de pagamento.
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

		// Bloco de valor zero é o do prestador de serviço: não há o que pagar a
		// ele pela plataforma, e "Valor a pagar: R$ 0,00" com um QR PIX vazio
		// diria o contrário. Tirá-lo não mexe na soma de referência abaixo,
		// porque o que ele somaria é zero.
		$blocos = array_filter(
			self::lojas_do_pedido( $pedido ),
			static function ( $bloco ) {
				return (float) $bloco['total'] >= 0.01;
			}
		);

		if ( ! $blocos ) {
			return;
		}

		// Sem filtrar por ativo: um meio desligado depois da compra não desobriga
		// ninguém de dizer ao comprador como pagar o que ele já comprou.
		$gateways = self::gateways_diretos( false );
		$mapa     = $pedido->get_meta( self::META_MEIOS );
		$padrao   = $pedido->get_payment_method();

		if ( ! is_array( $mapa ) ) {
			$mapa = array();
		}

		$meios = array();

		foreach ( $blocos as $indice => $bloco ) {
			$id = isset( $mapa[ $bloco['loja_id'] ] ) ? $mapa[ $bloco['loja_id'] ] : $padrao;

			if ( isset( $gateways[ $id ] ) ) {
				$meios[ $indice ] = $gateways[ $id ];
			}
		}

		if ( ! $meios ) {
			return;
		}

		$titulos = array();

		foreach ( $meios as $gateway ) {
			$titulos[ $gateway->id ] = $gateway->get_title();
		}

		echo '<section class="rc-pagamento">';
		printf(
			'<h2 class="rc-pagamento__titulo">%s</h2>',
			esc_html( implode( ' · ', $titulos ) )
		);
		printf(
			'<p class="rc-pagamento__aviso">%s</p>',
			esc_html__( 'O pagamento é feito direto para cada loja. O pedido é liberado quando a loja confirmar o recebimento.', 'reconectar-core' )
		);

		$somado = 0.0;

		foreach ( $blocos as $indice => $bloco ) {
			$somado += (float) $bloco['total'];

			echo '<div class="rc-pagamento__loja">';
			printf(
				'<h3 class="rc-pagamento__nome">%s</h3>',
				esc_html( self::nome_da_loja( $bloco['loja_id'] ) )
			);

			// Só quando há mais de um meio no pedido: com um meio só, o `<h2>` acima
			// já disse qual é, e repetir em cada bloco seria ruído.
			if ( count( $titulos ) > 1 && isset( $meios[ $indice ] ) ) {
				printf(
					'<p class="rc-pagamento__meio">%s</p>',
					esc_html( $meios[ $indice ]->get_title() )
				);
			}

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

			if ( isset( $meios[ $indice ] ) ) {
				$meios[ $indice ]->imprimir_instrucao( $bloco['loja_id'], $bloco['total'], $bloco['pedido'], $contexto );
			} else {
				printf(
					'<p class="rc-pagamento__alerta">%s</p>',
					esc_html__( 'Não há instrução de pagamento registrada para esta loja. Entre em contato com a plataforma antes de pagar.', 'reconectar-core' )
				);
			}

			/*
			 * Só no contexto `tela`: um `<form>` dentro do e-mail do pedido não
			 * envia nada em cliente nenhum, e ainda espalharia a rota de upload
			 * por caixas de entrada.
			 *
			 * O `class_exists` fica sozinho na condição, nunca num `||` com a
			 * chamada — a armadilha do curto-circuito antes do autoload.
			 */
			if ( 'tela' === $contexto && class_exists( 'Reconectar_Comprovante' ) ) {
				Reconectar_Comprovante::campo( $bloco['pedido'], $bloco['loja_id'] );
			}

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
