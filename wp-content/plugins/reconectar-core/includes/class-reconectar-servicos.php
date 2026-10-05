<?php
/**
 * Serviços no Mercado: solicitação sem pagamento e resposta do prestador.
 *
 * O RF24 pede divulgação e busca de serviços. O caminho escolhido aproveita o
 * catálogo que já existe: um produto na categoria Serviços (ou numa filha dela)
 * é um serviço, e a loja que o publica é o prestador. Quem precisa dele o põe
 * no carrinho como qualquer produto, descreve o que quer na observação do
 * pedido e finaliza **sem pagar nada**. A loja é avisada, responde pela
 * plataforma com um valor ou com "farei orçamento", e a partir dali pagamento e
 * execução são combinados entre as partes, fora daqui — decisão do projeto.
 *
 * Por que produto, e não um tipo novo: o Dokan Lite só cadastra produto
 * simples, e todo o resto — vitrine, busca, página da loja, carrinho, divisão
 * em sub-pedidos, e-mail ao vendedor — já funciona para ele. Um post type
 * próprio teria de reconstruir tudo isso.
 *
 * Por que preço `0`, e não vazio: para o WooCommerce preço vazio torna o
 * produto **não comprável** — o botão some e o carrinho o recusa. Com `0` o
 * produto entra, o total do pedido zera, e o WooCommerce pula os gateways
 * (`process_order_without_payment()`). O preço nunca aparece como "R$ 0,00":
 * os filtros desta classe o trocam por "A combinar" em toda tela.
 *
 * @package reconectar-core
 */

defined( 'ABSPATH' ) || exit;

class Reconectar_Servicos {

	/**
	 * Meta de termo que identifica a categoria Serviços.
	 *
	 * A busca é pela meta, nunca pelo slug ou pelo nome: o administrador renomeia
	 * a categoria pelo painel, e o slug muda junto se ele quiser. É o mesmo
	 * critério do fórum de cooperação (`_reconectar_forum_chave`). Quem grava é o
	 * `provision.sh`.
	 */
	const META_CATEGORIA = '_reconectar_categoria_chave';

	/**
	 * Valor da meta de termo na categoria Serviços.
	 */
	const CHAVE_CATEGORIA = 'servicos';

	/**
	 * Meta do pedido com a resposta do prestador.
	 *
	 * Mapa `tipo` (`valor` ou `orcamento`), `valor`, `mensagem`, `data` e
	 * `autor`. Gravada no sub-pedido da loja, que é o pedido que ela enxerga.
	 */
	const META_RESPOSTA = '_reconectar_servico_resposta';

	/**
	 * Ação de `admin-post.php` que grava a resposta.
	 */
	const ACAO_RESPONDER = 'reconectar_responder_servico';

	/**
	 * Cache, por requisição, dos termos que contam como serviço.
	 *
	 * `null` enquanto não consultado. A consulta é por meta de termo e roda a
	 * cada card de produto da vitrine; sem o cache seriam dezenas de consultas
	 * iguais numa página só.
	 *
	 * @var int[]|null
	 */
	private static $termos = null;

	/**
	 * Registra os ganchos.
	 *
	 * `ACAO_RESPONDER` não tem par `nopriv`: responder é ato de quem administra a
	 * loja, e não existe caminho de convidado para ele.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'woocommerce_before_product_object_save', array( __CLASS__, 'gravar_como_servico' ) );

		add_filter( 'woocommerce_get_price_html', array( __CLASS__, 'preco_html' ), 20, 2 );
		add_filter( 'woocommerce_cart_item_price', array( __CLASS__, 'preco_no_carrinho' ), 20, 2 );
		add_filter( 'woocommerce_cart_item_subtotal', array( __CLASS__, 'preco_no_carrinho' ), 20, 2 );
		add_filter( 'woocommerce_order_formatted_line_subtotal', array( __CLASS__, 'subtotal_no_pedido' ), 20, 2 );
		add_filter( 'woocommerce_product_single_add_to_cart_text', array( __CLASS__, 'texto_do_botao' ), 20, 2 );
		add_filter( 'woocommerce_product_add_to_cart_text', array( __CLASS__, 'texto_do_botao' ), 20, 2 );

		add_filter( 'woocommerce_checkout_fields', array( __CLASS__, 'exigir_descricao' ) );

		// Prioridade 20: depois de o Dokan propagar o status do pai aos
		// sub-pedidos (10) e antes de ele concluir sozinho o sub-pedido pago
		// que não precisa de processamento (`on_sub_order_change`, 99) — que,
		// sem isto, levaria a solicitação a "Concluído" sem resposta nenhuma.
		add_action( 'woocommerce_order_status_changed', array( __CLASS__, 'encaminhar_solicitacao' ), 20, 4 );

		// `after_order_table` porque é o único gancho de corpo que o template do
		// Dokan (`emails/vendor-new-order.php`) dispara: ele não chama
		// `woocommerce_email_order_details` nem `before_order_table`, e o bloco
		// pendurado neles sumiria só do e-mail que importa, o da loja.
		add_action( 'woocommerce_email_after_order_table', array( __CLASS__, 'bloco_no_email_da_loja' ), 10, 4 );

		add_action( 'admin_post_' . self::ACAO_RESPONDER, array( __CLASS__, 'responder' ) );
		add_action( 'dokan_order_content_inside_before', array( __CLASS__, 'aviso_na_lista' ) );
		add_action( 'dokan_order_detail_after_order_general_details', array( __CLASS__, 'painel_no_detalhe' ) );

		// Prioridade 11, depois da de `Reconectar_Comprovante` (10): as duas
		// acrescentam o critério **à frente** do `orderby`, então a última a
		// rodar é a que manda. Solicitação de serviço sobe acima do comprovante
		// em conferência porque é o cliente que espera, e não o dinheiro.
		add_filter( 'posts_clauses', array( __CLASS__, 'priorizar_solicitacoes' ), 11, 2 );

		add_action( 'woocommerce_order_details_after_order_table', array( __CLASS__, 'resposta_ao_cliente' ) );
	}

	/* ---------------------------------------------------------------------
	 * Identificação
	 * ------------------------------------------------------------------ */

	/**
	 * Devolve os IDs dos termos que contam como serviço: a categoria e as filhas.
	 *
	 * As filhas entram porque o cadastro natural de uma loja de serviços é numa
	 * subcategoria ("Reparos", "Aulas"), e um serviço em "Reparos" que não fosse
	 * reconhecido sairia com "R$ 0,00" e botão de compra.
	 *
	 * @return int[]
	 */
	public static function termos() {
		if ( null !== self::$termos ) {
			return self::$termos;
		}

		self::$termos = array();

		// `meta_query`, e não `meta_key`/`meta_value`: em `product_cat` o
		// WooCommerce troca `meta_key` pela meta `order` da ordenação
		// (`wc_change_pre_get_terms`), e a consulta devolve **vazio** sem
		// aviso. Medido. Com `orderby` explícito ele nem entra nessa troca.
		$raiz = get_terms(
			array(
				'taxonomy'   => 'product_cat',
				'hide_empty' => false,
				'fields'     => 'ids',
				'number'     => 1,
				'orderby'    => 'term_id',
				'meta_query' => array( // phpcs:ignore WordPress.DB.SlowDBQuery
					array(
						'key'   => self::META_CATEGORIA,
						'value' => self::CHAVE_CATEGORIA,
					),
				),
			)
		);

		if ( is_wp_error( $raiz ) || empty( $raiz ) ) {
			return self::$termos;
		}

		$raiz   = (int) $raiz[0];
		$filhas = get_term_children( $raiz, 'product_cat' );
		$filhas = is_wp_error( $filhas ) ? array() : array_map( 'intval', $filhas );

		self::$termos = array_merge( array( $raiz ), $filhas );

		return self::$termos;
	}

	/**
	 * ID da categoria Serviços, ou 0 se ela não existir.
	 *
	 * @return int
	 */
	public static function categoria_id() {
		$termos = self::termos();

		return $termos ? (int) $termos[0] : 0;
	}

	/**
	 * Diz se o produto é um serviço.
	 *
	 * Variação conta pelo produto pai, que é quem leva a categoria.
	 *
	 * @param WC_Product|int $produto Produto ou ID.
	 * @return bool
	 */
	public static function eh_servico( $produto ) {
		$termos = self::termos();

		if ( ! $termos ) {
			return false;
		}

		if ( ! $produto instanceof WC_Product ) {
			$produto = wc_get_product( $produto );
		}

		if ( ! $produto ) {
			return false;
		}

		$id = $produto->get_parent_id() ? $produto->get_parent_id() : $produto->get_id();

		return (bool) array_intersect( $termos, wc_get_product_term_ids( $id, 'product_cat' ) );
	}

	/**
	 * Diz se o pedido tem pelo menos um item de serviço.
	 *
	 * @param WC_Order $pedido Pedido.
	 * @return bool
	 */
	public static function pedido_tem_servico( $pedido ) {
		if ( ! $pedido instanceof WC_Order ) {
			return false;
		}

		foreach ( $pedido->get_items() as $item ) {
			if ( self::eh_servico( $item->get_product_id() ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Diz se **todos** os itens do pedido são serviço.
	 *
	 * Pedido vazio responde que não: sem item não há o que solicitar, e um
	 * "sim" aqui levaria a "Aguardando resposta" um pedido que não espera nada.
	 *
	 * @param WC_Order $pedido Pedido.
	 * @return bool
	 */
	public static function pedido_so_de_servico( $pedido ) {
		if ( ! $pedido instanceof WC_Order ) {
			return false;
		}

		$itens = $pedido->get_items();

		if ( ! $itens ) {
			return false;
		}

		foreach ( $itens as $item ) {
			if ( ! self::eh_servico( $item->get_product_id() ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Diz se o carrinho tem algum serviço.
	 *
	 * @return bool
	 */
	public static function carrinho_tem_servico() {
		if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
			return false;
		}

		foreach ( WC()->cart->get_cart() as $item ) {
			if ( ! empty( $item['product_id'] ) && self::eh_servico( $item['product_id'] ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Devolve as lojas cujos itens no carrinho são **todos** serviço.
	 *
	 * É a lista de quem não recebe nada agora, e por isso não precisa de PIX
	 * nem de conta cadastrada. A posse vem do autor do post, o mesmo critério de
	 * `Reconectar_Pagamento_Direto::valores_do_carrinho()` — duas fontes
	 * diferentes poderiam discordar sobre quais lojas estão no carrinho.
	 *
	 * @return int[]
	 */
	public static function lojas_so_de_servico_no_carrinho() {
		$lojas = array();

		if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
			return array();
		}

		foreach ( WC()->cart->get_cart() as $item ) {
			if ( empty( $item['product_id'] ) ) {
				continue;
			}

			$autor = (int) get_post_field( 'post_author', $item['product_id'] );

			if ( ! $autor ) {
				continue;
			}

			$servico = self::eh_servico( $item['product_id'] );

			$lojas[ $autor ] = isset( $lojas[ $autor ] ) ? ( $lojas[ $autor ] && $servico ) : $servico;
		}

		return array_map( 'intval', array_keys( array_filter( $lojas ) ) );
	}

	/* ---------------------------------------------------------------------
	 * Cadastro e vitrine
	 * ------------------------------------------------------------------ */

	/**
	 * Grava o serviço com preço zero, sem promoção e como virtual.
	 *
	 * Fica no salvamento do objeto, e não no formulário do Dokan, porque é o
	 * único ponto que cobre os três caminhos de cadastro — painel da loja,
	 * `/wp-admin` e WP-CLI. A loja não aprende campo novo: basta escolher a
	 * categoria. O preço que ela tiver digitado é descartado de propósito; um
	 * valor de vitrine num serviço que "é combinado" seria cobrado no checkout.
	 *
	 * Vendido individualmente porque a quantidade não tem o que dizer: o que o
	 * cliente quer — três toalhas, dois cômodos — vai na observação, e o
	 * prestador responde ao pedido inteiro. Medido antes: o serviço adicionado
	 * três vezes chegava ao pedido como "× 3" de um preço "A combinar".
	 *
	 * Virtual tira o frete e o endereço de entrega. Downloadable **não**: um
	 * pedido só de itens virtuais e baixáveis vai direto a "Concluído", e o
	 * serviço precisa ficar à espera da resposta.
	 *
	 * Só produto simples: o Dokan Lite não cadastra outro tipo, e um variável
	 * exigiria zerar cada variação — sem caso de uso que o justifique hoje.
	 *
	 * @param WC_Product $produto Produto prestes a ser gravado.
	 * @return void
	 */
	public static function gravar_como_servico( $produto ) {
		if ( ! $produto instanceof WC_Product || ! $produto->is_type( 'simple' ) ) {
			return;
		}

		$termos = self::termos();

		// `get_category_ids()` do próprio objeto, e não `eh_servico()`: a
		// categoria pode estar mudando neste mesmo salvamento, e o banco ainda
		// guarda a anterior.
		if ( ! $termos || ! array_intersect( $termos, array_map( 'intval', $produto->get_category_ids( 'edit' ) ) ) ) {
			return;
		}

		$produto->set_regular_price( '0' );
		$produto->set_sale_price( '' );
		$produto->set_date_on_sale_from( '' );
		$produto->set_date_on_sale_to( '' );
		$produto->set_price( '0' );
		$produto->set_virtual( true );
		$produto->set_sold_individually( true );
	}

	/**
	 * Texto que substitui o preço do serviço.
	 *
	 * @return string HTML.
	 */
	private static function a_combinar() {
		return '<span class="rc-preco-a-combinar">' . esc_html__( 'A combinar', 'reconectar-core' ) . '</span>';
	}

	/**
	 * Troca o preço do serviço por "A combinar" em cards, página e listas.
	 *
	 * @param string     $html    HTML do preço.
	 * @param WC_Product $produto Produto.
	 * @return string
	 */
	public static function preco_html( $html, $produto ) {
		return self::eh_servico( $produto ) ? self::a_combinar() : $html;
	}

	/**
	 * Troca preço e subtotal do serviço no carrinho e no resumo do checkout.
	 *
	 * @param string $html Valor formatado.
	 * @param array  $item Item do carrinho.
	 * @return string
	 */
	public static function preco_no_carrinho( $html, $item ) {
		if ( empty( $item['product_id'] ) || ! self::eh_servico( $item['product_id'] ) ) {
			return $html;
		}

		return self::a_combinar();
	}

	/**
	 * Troca o subtotal da linha de serviço no recibo, na conta e nos e-mails.
	 *
	 * @param string                $subtotal Subtotal formatado.
	 * @param WC_Order_Item_Product $item     Item do pedido.
	 * @return string
	 */
	public static function subtotal_no_pedido( $subtotal, $item ) {
		if ( ! $item instanceof WC_Order_Item_Product || ! self::eh_servico( $item->get_product_id() ) ) {
			return $subtotal;
		}

		return self::a_combinar();
	}

	/**
	 * Troca "Adicionar ao carrinho" por "Solicitar serviço".
	 *
	 * @param string     $texto   Texto do botão.
	 * @param WC_Product $produto Produto.
	 * @return string
	 */
	public static function texto_do_botao( $texto, $produto = null ) {
		// Sem produto, `wc_get_product()` cairia no post global — que, num
		// widget ou num bloco, pode ser outro produto que não o do botão.
		if ( ! $produto instanceof WC_Product ) {
			return $texto;
		}

		return self::eh_servico( $produto ) ? __( 'Solicitar serviço', 'reconectar-core' ) : $texto;
	}

	/* ---------------------------------------------------------------------
	 * Checkout e status
	 * ------------------------------------------------------------------ */

	/**
	 * Torna a observação obrigatória quando há serviço no carrinho.
	 *
	 * A observação **é** a solicitação: sem ela o prestador recebe um pedido de
	 * R$ 0 sem saber o que fazer. A validação de campo obrigatório do
	 * WooCommerce percorre todos os grupos de `get_checkout_fields()`, o grupo
	 * `order` inclusive, então marcar `required` basta para a recusa no
	 * servidor.
	 *
	 * Num carrinho com mais de uma loja o Dokan copia a mesma nota para cada
	 * sub-pedido; a ajuda do campo diz isso, para ninguém escrever ao prestador
	 * algo que a loja de produtos também vai ler sem esperar.
	 *
	 * @param array $campos Campos do checkout, por grupo.
	 * @return array
	 */
	public static function exigir_descricao( $campos ) {
		if ( ! isset( $campos['order']['order_comments'] ) || ! self::carrinho_tem_servico() ) {
			return $campos;
		}

		$campos['order']['order_comments']['required']    = true;
		$campos['order']['order_comments']['label']       = __( 'Descreva o serviço que você precisa', 'reconectar-core' );
		$campos['order']['order_comments']['placeholder'] = __( 'O que precisa ser feito, onde, para quando e qualquer detalhe que ajude o prestador a responder com o valor.', 'reconectar-core' );
		$campos['order']['order_comments']['description'] = __( 'O prestador recebe este texto e responde pela plataforma. Se houver outras lojas no pedido, elas também o leem.', 'reconectar-core' );

		return $campos;
	}

	/**
	 * Leva o pedido só de serviço a "Aguardando resposta" ou "Respondido".
	 *
	 * Os dois caminhos que chegam aqui:
	 *
	 * - carrinho só de serviço: total zero, `payment_complete()` leva a
	 *   `processing`;
	 * - carrinho misto: o pai vai a `on-hold` pelo gateway das outras lojas, e o
	 *   Dokan propaga o mesmo status ao sub-pedido do prestador.
	 *
	 * O objeto alterado é o **recebido** pelo gancho: o Dokan, na prioridade 99,
	 * lê o status desse mesmo objeto para decidir se conclui o sub-pedido, e
	 * outra instância deixaria a dele em `processing`.
	 *
	 * O e-mail de pedido novo da loja já saiu quando isto roda — o WooCommerce
	 * dispara as notificações de transição antes de `woocommerce_order_status_changed`.
	 * A guarda contra laço é o próprio status de destino: `solicitado` e
	 * `respondido` não estão na lista que dispara a troca.
	 *
	 * @param int      $pedido_id Pedido.
	 * @param string   $de        Status anterior, sem `wc-`.
	 * @param string   $para      Status novo, sem `wc-`.
	 * @param WC_Order $pedido    Pedido.
	 * @return void
	 */
	public static function encaminhar_solicitacao( $pedido_id, $de, $para, $pedido ) {
		// `pending` fica de fora: é o status de nascimento de todo pedido, antes
		// de o checkout terminar, e a solicitação ainda nem foi registrada.
		if ( ! in_array( $para, array( 'on-hold', 'processing' ), true ) ) {
			return;
		}

		if ( ! $pedido instanceof WC_Order ) {
			$pedido = wc_get_order( $pedido_id );
		}

		if ( ! self::pedido_so_de_servico( $pedido ) ) {
			return;
		}

		$destino = self::todas_respondidas( $pedido ) ? Reconectar_Status_Pedido::RESPONDIDO : Reconectar_Status_Pedido::SOLICITADO;

		$pedido->update_status(
			substr( $destino, 3 ),
			__( 'Solicitação de serviço: sem pagamento pela plataforma; o prestador responde com o valor.', 'reconectar-core' )
		);
	}

	/**
	 * Diz se o pedido — ou todos os sub-pedidos dele — já têm resposta.
	 *
	 * @param WC_Order $pedido Pedido, pai ou avulso.
	 * @return bool
	 */
	private static function todas_respondidas( $pedido ) {
		$sub_ids = function_exists( 'dokan_get_suborder_ids_by' ) ? dokan_get_suborder_ids_by( $pedido->get_id() ) : null;

		if ( empty( $sub_ids ) ) {
			return (bool) self::resposta( $pedido );
		}

		foreach ( $sub_ids as $sub_id ) {
			$sub = wc_get_order( $sub_id );

			if ( $sub && self::pedido_tem_servico( $sub ) && ! self::resposta( $sub ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Devolve a resposta gravada no pedido, ou lista vazia.
	 *
	 * @param WC_Order $pedido Pedido.
	 * @return array
	 */
	public static function resposta( $pedido ) {
		if ( ! $pedido instanceof WC_Order ) {
			return array();
		}

		$resposta = $pedido->get_meta( self::META_RESPOSTA );

		return is_array( $resposta ) && ! empty( $resposta['tipo'] ) ? $resposta : array();
	}

	/**
	 * Descreve a resposta em uma frase, para nota, e-mail e tela.
	 *
	 * @param array $resposta Resposta gravada.
	 * @return string Texto puro.
	 */
	private static function resumo_da_resposta( $resposta ) {
		if ( 'valor' === $resposta['tipo'] ) {
			return sprintf(
				/* translators: %s: valor proposto pelo prestador. */
				__( 'Valor proposto: %s', 'reconectar-core' ),
				html_entity_decode( wp_strip_all_tags( wc_price( (float) $resposta['valor'] ) ), ENT_QUOTES, 'UTF-8' )
			);
		}

		return __( 'O prestador vai fazer um orçamento.', 'reconectar-core' );
	}

	/* ---------------------------------------------------------------------
	 * Aviso à loja
	 * ------------------------------------------------------------------ */

	/**
	 * URL do pedido no painel da loja, para um link que sai por e-mail.
	 *
	 * `_view_mode=email` é a rota que o próprio Dokan prevê para isso
	 * (`Dashboard\Templates\Orders::order_details_content()`). O link de nonce
	 * que a lista usa não serve: o e-mail é montado na requisição do cliente, e
	 * o nonce sairia com a sessão **dele**. A trava de propriedade continua no
	 * template do detalhe, por `dokan_is_seller_has_order()`.
	 *
	 * @param WC_Order $pedido Pedido.
	 * @return string
	 */
	private static function url_no_painel( $pedido ) {
		if ( ! function_exists( 'dokan_get_navigation_url' ) ) {
			return '';
		}

		return add_query_arg(
			array(
				'order_id'   => $pedido->get_id(),
				'_view_mode' => 'email',
			),
			dokan_get_navigation_url( 'orders' )
		) . '#' . self::ancora( $pedido->get_id() );
	}

	/**
	 * Acrescenta a solicitação ao e-mail de pedido novo da loja.
	 *
	 * Sem isto a loja recebe "Novo pedido" com um total de R$ 0, e nada diz que
	 * há alguém esperando resposta. A observação já sai no e-mail do Dokan, mas
	 * no rodapé e sem contexto; aqui ela vem com o pedido do que fazer e o link.
	 *
	 * @param WC_Order $pedido        Pedido.
	 * @param bool     $para_admin    Se o e-mail vai ao administrador.
	 * @param bool     $texto_puro    Se o e-mail é em texto puro.
	 * @param WC_Email $email         E-mail sendo montado.
	 * @return void
	 */
	public static function bloco_no_email_da_loja( $pedido, $para_admin, $texto_puro, $email ) {
		if ( ! is_object( $email ) || empty( $email->id ) || 'dokan_vendor_new_order' !== $email->id ) {
			return;
		}

		if ( ! self::pedido_tem_servico( $pedido ) ) {
			return;
		}

		$titulo = __( 'Solicitação de serviço: o cliente aguarda sua resposta', 'reconectar-core' );
		$apoio  = __( 'Este pedido não foi pago pela plataforma. Responda com o valor do serviço ou avise que vai fazer um orçamento; pagamento e execução são combinados diretamente com o cliente.', 'reconectar-core' );
		$nota   = (string) $pedido->get_customer_note();
		$url    = self::url_no_painel( $pedido );
		$botao  = __( 'Responder pela plataforma', 'reconectar-core' );

		if ( $texto_puro ) {
			echo "\n" . esc_html( $titulo ) . "\n\n" . esc_html( $apoio ) . "\n\n";

			if ( '' !== $nota ) {
				echo esc_html__( 'O que o cliente pediu:', 'reconectar-core' ) . "\n" . esc_html( $nota ) . "\n\n";
			}

			if ( '' !== $url ) {
				echo esc_html( $botao ) . ': ' . esc_url_raw( $url ) . "\n\n";
			}

			return;
		}

		echo '<h2>' . esc_html( $titulo ) . '</h2>';
		echo '<p>' . esc_html( $apoio ) . '</p>';

		if ( '' !== $nota ) {
			echo '<p><strong>' . esc_html__( 'O que o cliente pediu:', 'reconectar-core' ) . '</strong></p>';
			echo '<blockquote>' . wp_kses_post( wpautop( wptexturize( $nota ) ) ) . '</blockquote>';
		}

		if ( '' !== $url ) {
			printf( '<p><a href="%1$s">%2$s</a></p>', esc_url( $url ), esc_html( $botao ) );
		}
	}

	/**
	 * Conta as solicitações da loja que ainda aguardam resposta.
	 *
	 * Consulta direta e não `wc_get_orders()`: aquela função **descarta em
	 * silêncio** o filtro por meta e devolveria a contagem da instalação inteira
	 * (armadilha registrada no `CLAUDE.md`). A loja do sub-pedido é a meta
	 * `_dokan_vendor_id`, a mesma que a lista do painel usa.
	 *
	 * @param int $loja_id Loja.
	 * @return int
	 */
	private static function pendentes_da_loja( $loja_id ) {
		global $wpdb;

		return (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->posts} p
				INNER JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = '_dokan_vendor_id'
				WHERE p.post_type = 'shop_order' AND p.post_status = %s AND m.meta_value = %d",
				Reconectar_Status_Pedido::SOLICITADO,
				$loja_id
			)
		);
	}

	/**
	 * Avisa, no alto da lista de pedidos, quantas solicitações esperam resposta.
	 *
	 * Só a interface antiga do painel dispara este gancho; na nova, a lista é
	 * React e o aviso fica a cargo do rótulo "Aguardando resposta" e da
	 * ordenação, que valem nas duas.
	 *
	 * @return void
	 */
	public static function aviso_na_lista() {
		self::imprimir_aviso();

		$pendentes = self::pendentes_da_loja( get_current_user_id() );

		if ( $pendentes < 1 ) {
			return;
		}

		printf(
			'<p class="rc-servico__aviso" role="status">%s</p>',
			esc_html(
				sprintf(
					/* translators: %s: número de solicitações. */
					_n( '%s solicitação de serviço aguarda sua resposta. Ela está no topo da lista.', '%s solicitações de serviço aguardam sua resposta. Elas estão no topo da lista.', $pendentes, 'reconectar-core' ),
					number_format_i18n( $pendentes )
				)
			)
		);
	}

	/**
	 * Põe as solicitações sem resposta no topo da lista de pedidos da loja.
	 *
	 * Mesmo reconhecimento de `Reconectar_Comprovante::priorizar_conferencia()`:
	 * `shop_order` no `post_type` e `_dokan_vendor_id` no `where` já montado.
	 * Não é `dokan_get_vendor_orders`, que só reordena a página corrente.
	 *
	 * @param array    $clausulas Cláusulas da consulta.
	 * @param WP_Query $consulta  Consulta.
	 * @return array
	 */
	public static function priorizar_solicitacoes( $clausulas, $consulta ) {
		global $wpdb;

		if ( ! $consulta instanceof WP_Query ) {
			return $clausulas;
		}

		if ( ! in_array( 'shop_order', (array) $consulta->get( 'post_type' ), true ) ) {
			return $clausulas;
		}

		if ( empty( $clausulas['where'] ) || false === strpos( $clausulas['where'], '_dokan_vendor_id' ) ) {
			return $clausulas;
		}

		$status = Reconectar_Status_Pedido::SOLICITADO;

		$prioridade = $wpdb->prepare(
			"CASE WHEN {$wpdb->posts}.post_status = %s THEN 0 ELSE 1 END ASC, CASE WHEN {$wpdb->posts}.post_status = %s THEN {$wpdb->posts}.post_date END ASC",
			$status,
			$status
		);

		$original = isset( $clausulas['orderby'] ) ? trim( (string) $clausulas['orderby'] ) : '';

		$clausulas['orderby'] = '' === $original ? $prioridade : $prioridade . ', ' . $original;

		return $clausulas;
	}

	/* ---------------------------------------------------------------------
	 * Resposta do prestador
	 * ------------------------------------------------------------------ */

	/**
	 * Diz se o usuário atual administra a loja do pedido.
	 *
	 * Mesmo critério de `Reconectar_Comprovante::loja_pode()`: a loja dona pela
	 * função do Dokan, ou quem administra o WooCommerce.
	 *
	 * @param WC_Order $pedido Pedido.
	 * @return bool
	 */
	private static function loja_pode( $pedido ) {
		if ( ! $pedido instanceof WC_Order || ! is_user_logged_in() ) {
			return false;
		}

		if ( current_user_can( 'manage_woocommerce' ) ) {
			return true;
		}

		if ( ! function_exists( 'dokan_get_seller_id_by_order' ) ) {
			return false;
		}

		return (int) dokan_get_seller_id_by_order( $pedido->get_id() ) === get_current_user_id();
	}

	/**
	 * Âncora do painel de resposta, para voltar a ele depois do envio.
	 *
	 * @param int $pedido_id Pedido.
	 * @return string
	 */
	private static function ancora( $pedido_id ) {
		return 'rc-servico-' . (int) $pedido_id;
	}

	/**
	 * Recusa a ação com 403.
	 *
	 * @param string $mensagem Motivo.
	 * @return void
	 */
	private static function recusar( $mensagem ) {
		wp_die(
			esc_html( $mensagem ),
			esc_html__( 'Ação não permitida', 'reconectar-core' ),
			array(
				'response'  => 403,
				'back_link' => true,
			)
		);
	}

	/**
	 * Volta à tela de origem com um aviso.
	 *
	 * @param string $aviso     Chave do aviso.
	 * @param int    $pedido_id Pedido.
	 * @return void
	 */
	private static function voltar( $aviso, $pedido_id ) {
		$destino = wp_get_referer();

		if ( ! $destino && function_exists( 'dokan_get_navigation_url' ) ) {
			$destino = dokan_get_navigation_url( 'orders' );
		}

		$destino  = add_query_arg( 'rc-servico', $aviso, strtok( (string) $destino, '#' ) );
		$destino .= '#' . self::ancora( $pedido_id );

		wp_safe_redirect( $destino );
		exit;
	}

	/**
	 * Imprime o aviso do resultado da última resposta, se houver.
	 *
	 * @return void
	 */
	private static function imprimir_aviso() {
		// phpcs:ignore WordPress.Security.NonceVerification
		$aviso = isset( $_GET['rc-servico'] ) ? sanitize_key( wp_unslash( $_GET['rc-servico'] ) ) : '';

		$mensagens = array(
			'respondido'   => __( 'Resposta enviada. O cliente recebeu por e-mail e a vê no pedido.', 'reconectar-core' ),
			'sem-mensagem' => __( 'Escreva uma mensagem ao cliente antes de enviar.', 'reconectar-core' ),
			'sem-valor'    => __( 'Informe um valor maior que zero, ou escolha "Vou fazer um orçamento".', 'reconectar-core' ),
		);

		if ( ! isset( $mensagens[ $aviso ] ) ) {
			return;
		}

		printf(
			'<p class="rc-servico__aviso rc-servico__aviso--%1$s" role="status">%2$s</p>',
			esc_attr( 'respondido' === $aviso ? 'ok' : 'erro' ),
			esc_html( $mensagens[ $aviso ] )
		);
	}

	/**
	 * Grava a resposta do prestador.
	 *
	 * Ordem das guardas: nonce por pedido, existência, propriedade, conteúdo. O
	 * nonce leva o ID, então um `pedido=` trocado à mão cai na primeira guarda —
	 * a de propriedade só se alcança por reflexão, e é assim que o
	 * `verificar-acessos.sh` a mede.
	 *
	 * O valor **não** altera o total do pedido. Ele é o registro da proposta;
	 * mexer no total poria na plataforma uma cobrança que ela não faz, e o
	 * pedido de R$ 0 viraria uma dívida em aberto nas telas do WooCommerce.
	 *
	 * Responder de novo é permitido: a meta é substituída e a nota nova entra no
	 * histórico, que guarda as anteriores.
	 *
	 * @return void
	 */
	public static function responder() {
		$pedido_id = isset( $_POST['pedido'] ) ? absint( wp_unslash( $_POST['pedido'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification

		check_admin_referer( self::ACAO_RESPONDER . '_' . $pedido_id );

		$pedido = wc_get_order( $pedido_id );

		if ( ! $pedido || ! self::pedido_tem_servico( $pedido ) ) {
			self::recusar( __( 'Pedido não encontrado.', 'reconectar-core' ) );
		}

		if ( ! self::loja_pode( $pedido ) ) {
			self::recusar( __( 'Só a loja responsável por este pedido pode respondê-lo.', 'reconectar-core' ) );
		}

		$tipo     = isset( $_POST['tipo'] ) && 'orcamento' === $_POST['tipo'] ? 'orcamento' : 'valor';
		$valor    = isset( $_POST['valor'] ) ? wc_format_decimal( sanitize_text_field( wp_unslash( $_POST['valor'] ) ) ) : '';
		$mensagem = isset( $_POST['mensagem'] ) ? sanitize_textarea_field( wp_unslash( $_POST['mensagem'] ) ) : '';

		if ( '' === trim( $mensagem ) ) {
			self::voltar( 'sem-mensagem', $pedido_id );
		}

		if ( 'valor' === $tipo && (float) $valor <= 0 ) {
			self::voltar( 'sem-valor', $pedido_id );
		}

		$resposta = array(
			'tipo'     => $tipo,
			'valor'    => 'valor' === $tipo ? $valor : '',
			'mensagem' => $mensagem,
			'data'     => current_time( 'mysql' ),
			'autor'    => get_current_user_id(),
		);

		$pedido->update_meta_data( self::META_RESPOSTA, $resposta );
		$pedido->save();

		// Nota de cliente: o WooCommerce manda o e-mail `customer_note`, e o
		// Dokan põe a loja como Reply-To — o cliente responde direto a ela.
		$pedido->add_order_note(
			self::resumo_da_resposta( $resposta ) . "\n\n" . $mensagem,
			1,
			true
		);

		if ( Reconectar_Status_Pedido::RESPONDIDO !== 'wc-' . $pedido->get_status() && self::pedido_so_de_servico( $pedido ) ) {
			$pedido->update_status( substr( Reconectar_Status_Pedido::RESPONDIDO, 3 ) );
		}

		self::fechar_pai( $pedido );

		self::voltar( 'respondido', $pedido_id );
	}

	/**
	 * Leva o pedido pai a "Respondido" quando todos os sub-pedidos foram.
	 *
	 * O Dokan propaga do pai para os filhos, nunca o contrário. Sem isto o
	 * cliente veria, na conta dele, o pedido inteiro em "Aguardando resposta"
	 * depois de todos os prestadores terem respondido.
	 *
	 * @param WC_Order $sub Sub-pedido recém-respondido.
	 * @return void
	 */
	private static function fechar_pai( $sub ) {
		$pai = $sub->get_parent_id() ? wc_get_order( $sub->get_parent_id() ) : null;

		if ( ! $pai || 'wc-' . $pai->get_status() !== Reconectar_Status_Pedido::SOLICITADO ) {
			return;
		}

		foreach ( (array) dokan_get_suborder_ids_by( $pai->get_id() ) as $sub_id ) {
			$filho = wc_get_order( $sub_id );

			if ( $filho && 'wc-' . $filho->get_status() !== Reconectar_Status_Pedido::RESPONDIDO ) {
				return;
			}
		}

		$pai->update_status( substr( Reconectar_Status_Pedido::RESPONDIDO, 3 ) );
	}

	/**
	 * Painel de resposta no detalhe do pedido, no painel da loja.
	 *
	 * Template PHP nas duas interfaces do Dokan, então serve às duas. O
	 * formulário é próprio e vai ao `admin-post.php`, que tem exceção no portão
	 * do `/wp-admin`; aqui não há form de terceiro em volta, ao contrário da
	 * lista de pedidos.
	 *
	 * Aparece para todo pedido **com** serviço, inclusive o que tem produto da
	 * mesma loja junto: esse segue o fluxo de pagamento normal, mas o serviço
	 * dele também espera resposta.
	 *
	 * @param WC_Order $pedido Pedido.
	 * @return void
	 */
	public static function painel_no_detalhe( $pedido ) {
		if ( ! $pedido instanceof WC_Order || ! self::pedido_tem_servico( $pedido ) || ! self::loja_pode( $pedido ) ) {
			return;
		}

		$resposta = self::resposta( $pedido );
		$tipo     = $resposta ? $resposta['tipo'] : 'valor';
		$id       = $pedido->get_id();
		?>
		<div class="rc-servico-detalhe" style="width:100%">
			<div class="dokan-panel dokan-panel-default" id="<?php echo esc_attr( self::ancora( $id ) ); ?>">
				<div class="dokan-panel-heading"><strong><?php esc_html_e( 'Solicitação de serviço', 'reconectar-core' ); ?></strong></div>
				<div class="dokan-panel-body rc-servico">
					<?php self::imprimir_aviso(); ?>

					<p class="rc-servico__pedido-titulo"><strong><?php esc_html_e( 'O que o cliente pediu', 'reconectar-core' ); ?></strong></p>
					<div class="rc-servico__pedido-texto"><?php echo wp_kses_post( wpautop( wptexturize( (string) $pedido->get_customer_note() ) ) ); ?></div>

					<?php if ( $resposta ) : ?>
						<p class="rc-servico__estado">
							<?php
							echo esc_html(
								sprintf(
									/* translators: 1: resumo da resposta, 2: data. */
									__( 'Respondido em %2$s. %1$s', 'reconectar-core' ),
									self::resumo_da_resposta( $resposta ),
									mysql2date( get_option( 'date_format' ) . ' \à\s ' . get_option( 'time_format' ), $resposta['data'] )
								)
							);
							?>
						</p>
					<?php endif; ?>

					<form class="rc-servico__formulario" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<input type="hidden" name="action" value="<?php echo esc_attr( self::ACAO_RESPONDER ); ?>">
						<input type="hidden" name="pedido" value="<?php echo esc_attr( $id ); ?>">
						<?php wp_nonce_field( self::ACAO_RESPONDER . '_' . $id ); ?>

						<fieldset class="rc-servico__tipo">
							<legend><?php echo esc_html( $resposta ? __( 'Atualizar a resposta', 'reconectar-core' ) : __( 'Sua resposta', 'reconectar-core' ) ); ?></legend>
							<label>
								<input type="radio" name="tipo" value="valor" <?php checked( 'valor', $tipo ); ?>>
								<?php esc_html_e( 'Informar o valor', 'reconectar-core' ); ?>
							</label>
							<label>
								<input type="radio" name="tipo" value="orcamento" <?php checked( 'orcamento', $tipo ); ?>>
								<?php esc_html_e( 'Vou fazer um orçamento', 'reconectar-core' ); ?>
							</label>
						</fieldset>

						<p>
							<label for="rc-servico-valor-<?php echo esc_attr( $id ); ?>"><?php esc_html_e( 'Valor (R$), se escolheu informar', 'reconectar-core' ); ?></label>
							<input class="rc-servico__valor" id="rc-servico-valor-<?php echo esc_attr( $id ); ?>" type="text" inputmode="decimal" name="valor" value="<?php echo esc_attr( $resposta && 'valor' === $resposta['tipo'] ? wc_format_localized_price( $resposta['valor'] ) : '' ); ?>">
						</p>

						<p>
							<label for="rc-servico-mensagem-<?php echo esc_attr( $id ); ?>"><?php esc_html_e( 'Mensagem ao cliente', 'reconectar-core' ); ?></label>
							<textarea class="rc-servico__mensagem" id="rc-servico-mensagem-<?php echo esc_attr( $id ); ?>" name="mensagem" rows="4" required placeholder="<?php esc_attr_e( 'Prazo, o que está incluído, como combinar o pagamento e a execução.', 'reconectar-core' ); ?>"></textarea>
						</p>

						<p class="rc-servico__apoio"><?php esc_html_e( 'O cliente recebe a resposta por e-mail e a vê no pedido. O valor não é cobrado pela plataforma: pagamento e execução são combinados entre vocês.', 'reconectar-core' ); ?></p>

						<button type="submit" class="dokan-btn dokan-btn-theme"><?php esc_html_e( 'Enviar resposta', 'reconectar-core' ); ?></button>
					</form>
				</div>
			</div>
		</div>
		<?php
	}

	/* ---------------------------------------------------------------------
	 * Visão do cliente
	 * ------------------------------------------------------------------ */

	/**
	 * Mostra ao cliente a resposta de cada prestador, no recibo e na conta.
	 *
	 * Sub-pedido por sub-pedido, porque é nele que cada loja responde. O pai,
	 * que é o que o cliente vê, não guarda resposta nenhuma.
	 *
	 * @param WC_Order $pedido Pedido exibido.
	 * @return void
	 */
	public static function resposta_ao_cliente( $pedido ) {
		if ( ! $pedido instanceof WC_Order ) {
			return;
		}

		$sub_ids = function_exists( 'dokan_get_suborder_ids_by' ) ? dokan_get_suborder_ids_by( $pedido->get_id() ) : null;
		$pedidos = empty( $sub_ids ) ? array( $pedido ) : array_filter( array_map( 'wc_get_order', $sub_ids ) );
		$pedidos = array_filter( $pedidos, array( __CLASS__, 'pedido_tem_servico' ) );

		if ( ! $pedidos ) {
			return;
		}

		echo '<section class="rc-servico-cliente">';
		printf( '<h2 class="rc-servico-cliente__titulo">%s</h2>', esc_html__( 'Resposta do prestador', 'reconectar-core' ) );
		printf(
			'<p class="rc-servico-cliente__apoio">%s</p>',
			esc_html__( 'Serviços não são pagos pela plataforma: o valor e a execução são combinados diretamente com o prestador.', 'reconectar-core' )
		);

		echo '<ul class="rc-servico-cliente__lista">';

		foreach ( $pedidos as $sub ) {
			$loja_id  = function_exists( 'dokan_get_seller_id_by_order' ) ? (int) dokan_get_seller_id_by_order( $sub->get_id() ) : 0;
			$loja     = $loja_id && function_exists( 'dokan_get_store_info' ) ? dokan_get_store_info( $loja_id ) : array();
			$nome     = ! empty( $loja['store_name'] ) ? $loja['store_name'] : __( 'Prestador', 'reconectar-core' );
			$resposta = self::resposta( $sub );

			echo '<li class="rc-servico-cliente__item">';
			printf( '<p class="rc-servico-cliente__loja"><strong>%s</strong></p>', esc_html( $nome ) );

			if ( ! $resposta ) {
				printf( '<p class="rc-servico-cliente__estado">%s</p>', esc_html__( 'Aguardando resposta.', 'reconectar-core' ) );
			} else {
				printf(
					'<p class="rc-servico-cliente__estado">%1$s <span class="rc-servico-cliente__data">%2$s</span></p>',
					esc_html( self::resumo_da_resposta( $resposta ) ),
					esc_html(
						sprintf(
							/* translators: %s: data da resposta. */
							__( '(respondido em %s)', 'reconectar-core' ),
							mysql2date( get_option( 'date_format' ), $resposta['data'] )
						)
					)
				);
				echo '<div class="rc-servico-cliente__mensagem">' . wp_kses_post( wpautop( esc_html( $resposta['mensagem'] ) ) ) . '</div>';
			}

			echo '</li>';
		}

		echo '</ul></section>';
	}
}
