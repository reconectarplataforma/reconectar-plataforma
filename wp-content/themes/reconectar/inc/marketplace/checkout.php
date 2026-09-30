<?php
/**
 * Redesenho do checkout: cards, colapses e resumo lateral.
 *
 * O checkout clássico entrega dois blocos lado a lado — `#customer_details` à
 * esquerda, `#order_review` à direita — com a tabela de itens, os totais e o
 * seletor de pagamento empilhados no mesmo bloco. Aqui cada seção é um card, os
 * dados do comprador ficam acima do pedido, os itens são agrupados por loja em
 * `<details>`, e os totais saem num resumo próprio na coluna da direita.
 *
 * **Nada é sobrescrito por template.** Medidos os 21 hooks do checkout no
 * ambiente rodando, o `woocommerce_checkout_order_review` tem só dois callbacks
 * (`woocommerce_order_review` em 10 e `woocommerce_checkout_payment` em 20) e
 * nenhum dos oito `woocommerce_review_order_*` tem callback algum — o Dokan
 * inclusive. Substituir o callback de 10 por função autoral não perde nada de
 * terceiro, e evita copiar `checkout/review-order.php`, que é um arquivo do
 * WooCommerce e não está versionado aqui.
 *
 * @package reconectar
 */

defined( 'ABSPATH' ) || exit;

/**
 * Troca a tabela de revisão do WooCommerce pelo pedido agrupado por loja.
 *
 * O `remove_action` fica em `wp`, não no corpo do arquivo: o WooCommerce registra
 * `woocommerce_order_review` ao carregar `wc-template-functions.php`, o que
 * acontece em `plugins_loaded` — depois deste arquivo, que vem do
 * `functions.php` do tema. É a mesma armadilha do `functions.php` do filho
 * carregar antes do pai, registrada no `CLAUDE.md`: um `remove_action` escrito
 * cedo devolve `false` e não faz nada.
 *
 * O pedido vai em `woocommerce_checkout_before_customer_details`, e não no lugar
 * de onde a tabela saiu: é o que o põe **acima** dos dados do comprador no DOM.
 * Inverter pelo CSS com `order` inverteria a pintura e deixaria a ordem de foco e
 * de tabulação como estava — dois blocos deste tamanho em ordem discordante
 * reprovam o critério 2.4.3 da WCAG 2.1, que é requisito do edital.
 *
 * `is_checkout()` responde `true` **também** no endpoint `order-pay`, que é uma
 * tela de pagamento de pedido já criado: ali não há `#customer_details`, não há
 * resumo autoral, e nada disto deve valer. Daí a segunda guarda.
 *
 * @return void
 */
function reconectar_checkout_trocar_revisao() {
	if ( ! function_exists( 'is_checkout' ) || ! is_checkout() || is_checkout_pay_page() ) {
		return;
	}

	remove_action( 'woocommerce_checkout_order_review', 'woocommerce_order_review', 10 );
	add_action( 'woocommerce_checkout_before_customer_details', 'reconectar_checkout_pedido', 10 );
	reconectar_checkout_tirar_privacidade();
}
add_action( 'wp', 'reconectar_checkout_trocar_revisao' );

/**
 * Tira o texto de privacidade do card de pagamento.
 *
 * Decisão de produto: o texto qualifica o ato de finalizar, e o botão de
 * finalizar mora no resumo — é de lá que `reconectar_checkout_resumo()` o
 * imprime de novo, logo abaixo do botão. O callback [30]
 * (`wc_terms_and_conditions_page_content`) fica onde está: hoje não imprime nada
 * (`wc_get_page_id( 'terms' )` = -1) e, se um dia imprimir, o lugar dele é ali.
 *
 * Função à parte, e enganchada nas rotas AJAX, porque o `wp` **não alcança** uma
 * requisição `?wc-ajax=update_order_review`: `WC_AJAX::update_order_review()`
 * remonta o fragmento `.woocommerce-checkout-payment` do zero e repintava a
 * privacidade que o carregamento inicial havia removido. Medido no corpo da
 * resposta AJAX, antes desta correção, com o texto também presente no resumo:
 *
 *   .woocommerce-checkout-payment → <div class="woocommerce-privacy-policy-text">
 *
 * O sintoma é mudo e chega depois: a tela nasce correta e ganha a segunda cópia
 * no primeiro recálculo — trocar o meio de pagamento, aplicar cupom, sair do
 * CEP. Quem investigar o carregamento não encontra nada.
 *
 * Os três hooks cobrem as duas rotas que o WooCommerce oferece para a mesma
 * ação — `?wc-ajax=` e `admin-ajax.php`, esta com par logado e anônimo —, todos
 * medidos com `WC_AJAX::update_order_review` em prioridade 10. Daí o 5.
 *
 * @return void
 */
function reconectar_checkout_tirar_privacidade() {
	remove_action( 'woocommerce_checkout_terms_and_conditions', 'wc_checkout_privacy_policy_text', 20 );
}
add_action( 'wc_ajax_update_order_review', 'reconectar_checkout_tirar_privacidade', 5 );
add_action( 'wp_ajax_woocommerce_update_order_review', 'reconectar_checkout_tirar_privacidade', 5 );
add_action( 'wp_ajax_nopriv_woocommerce_update_order_review', 'reconectar_checkout_tirar_privacidade', 5 );

/**
 * Agrupa os itens do carrinho por loja.
 *
 * A posse vem do autor do post do produto — a **mesma** fonte de
 * `Reconectar_Pagamento_Direto::valores_do_carrinho()`, e a mesma que o Dokan
 * usa. Escrever uma segunda varredura com critério próprio produziria duas
 * respostas que podem divergir sobre quais lojas estão no pedido: o resumo
 * mostraria um agrupamento e o gateway cobraria por outro.
 *
 * O item do carrinho guarda `product_id` do produto **pai**, então variação não
 * precisa de tratamento à parte.
 *
 * @return array<int,array<string,mixed>> Por ID de loja: `nome`, `itens`, `total`.
 */
function reconectar_checkout_itens_por_loja() {
	$lojas = array();

	if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
		return $lojas;
	}

	foreach ( WC()->cart->get_cart() as $chave => $item ) {
		if ( empty( $item['product_id'] ) ) {
			continue;
		}

		$autor = (int) get_post_field( 'post_author', $item['product_id'] );

		if ( ! isset( $lojas[ $autor ] ) ) {
			$lojas[ $autor ] = array(
				'nome'  => reconectar_checkout_nome_da_loja( $autor ),
				'itens' => array(),
				'total' => 0.0,
			);
		}

		$lojas[ $autor ]['itens'][ $chave ] = $item;
		$lojas[ $autor ]['total']          += (float) ( isset( $item['line_subtotal'] ) ? $item['line_subtotal'] : 0 )
			+ (float) ( isset( $item['line_subtotal_tax'] ) ? $item['line_subtotal_tax'] : 0 );
	}

	return $lojas;
}

/**
 * Nome de exibição de uma loja, com recuo para quando o Dokan não responde.
 *
 * Delega ao plugin quando ele está de pé, para que o rótulo do checkout e o das
 * instruções de pagamento saiam idênticos. O recuo existe porque o tema não pode
 * exigir o plugin: sem ele, um `autor` sem nome de loja imprimiria `<summary>`
 * vazio, e um colapse sem rótulo não se abre por teclado com sentido nenhum.
 *
 * @param int $loja_id Identificador da loja.
 * @return string Nome, ou "Loja" quando nada responde.
 */
function reconectar_checkout_nome_da_loja( $loja_id ) {
	if ( class_exists( 'Reconectar_Pagamento_Direto' ) ) {
		$nome = Reconectar_Pagamento_Direto::nome_da_loja( $loja_id );

		if ( $nome ) {
			return $nome;
		}
	}

	$usuario = get_userdata( (int) $loja_id );

	if ( $usuario && $usuario->display_name ) {
		return $usuario->display_name;
	}

	return __( 'Loja', 'reconectar' );
}

/**
 * Imprime o card do pedido, com um colapse por loja.
 *
 * Substitui `woocommerce_order_review()`. Não imprime totais: eles saem no card
 * de resumo, na coluna da direita — o que atende ao pedido de separar o que se
 * está comprando do quanto se vai pagar.
 *
 * Os filtros `woocommerce_cart_item_name` e
 * `woocommerce_checkout_cart_item_quantity` são mantidos mesmo sem callback
 * algum no ambiente atual: são o contrato por onde um plugin futuro adornaria o
 * nome ou a quantidade, e retirá-los seria tirar essa porta sem ganho nenhum.
 *
 * @return void
 */
function reconectar_checkout_pedido() {
	$lojas = reconectar_checkout_itens_por_loja();

	echo '<section class="rc-checkout__card rc-checkout__pedido">';
	printf(
		'<h2 class="rc-checkout__titulo">%s</h2>',
		esc_html__( 'Seu pedido', 'reconectar' )
	);

	if ( ! $lojas ) {
		printf(
			'<p class="rc-checkout__vazio">%s</p>',
			esc_html__( 'Seu carrinho está vazio.', 'reconectar' )
		);
		echo '</section>';

		return;
	}

	// Só a primeira loja nasce aberta. Com três lojas todas abertas, o card do
	// pedido empurraria o de pagamento abaixo da primeira dobra — e é o de
	// pagamento que o comprador precisa alcançar.
	$primeira = true;

	foreach ( $lojas as $loja_id => $loja ) {
		// O `data-loja` é a chave estável entre o antes e o depois de cada
		// `update_order_review`: o fragmento troca o card inteiro, e sem um
		// identificador que sobreviva à troca o JS não saberia quais colapses
		// reabrir nem a qual rádio devolver o foco.
		printf(
			'<details class="rc-checkout__loja" data-loja="%1$d"%2$s>',
			(int) $loja_id,
			$primeira ? ' open' : ''
		);

		printf(
			'<summary class="rc-checkout__loja-resumo"><span class="rc-checkout__loja-nome">%1$s</span><span class="rc-checkout__loja-meta">%2$s</span><span class="rc-checkout__loja-total">%3$s</span></summary>',
			esc_html( $loja['nome'] ),
			esc_html(
				sprintf(
					/* translators: %s: quantidade de itens da loja no carrinho. */
					_n( '%s item', '%s itens', count( $loja['itens'] ), 'reconectar' ),
					number_format_i18n( count( $loja['itens'] ) )
				)
			),
			wp_kses_post( wc_price( $loja['total'] ) )
		);

		echo '<ul class="rc-checkout__itens">';

		foreach ( $loja['itens'] as $chave => $item ) {
			$produto = $item['data'];

			if ( ! $produto ) {
				continue;
			}

			printf(
				'<li class="rc-checkout__item"><span class="rc-checkout__item-imagem">%1$s</span><span class="rc-checkout__item-nome">%2$s</span><span class="rc-checkout__item-qtd">%3$s</span><span class="rc-checkout__item-valor">%4$s</span></li>',
				wp_kses_post( reconectar_checkout_miniatura( $produto, $item, $chave ) ),
				wp_kses_post( apply_filters( 'woocommerce_cart_item_name', $produto->get_name(), $item, $chave ) ),
				wp_kses_post(
					apply_filters(
						'woocommerce_checkout_cart_item_quantity',
						sprintf(
							/* translators: %s: quantidade do item no carrinho. */
							esc_html__( '× %s', 'reconectar' ),
							number_format_i18n( $item['quantity'] )
						),
						$item,
						$chave
					)
				),
				wp_kses_post( apply_filters( 'woocommerce_cart_item_subtotal', WC()->cart->get_product_subtotal( $produto, $item['quantity'] ), $item, $chave ) )
			);
		}

		echo '</ul>';

		reconectar_checkout_meios_da_loja( (int) $loja_id );

		echo '</details>';

		$primeira = false;
	}

	echo '</section>';
}

/**
 * Devolve a miniatura de um item do carrinho.
 *
 * O filtro é o `woocommerce_cart_item_thumbnail`, o mesmo que o template do
 * carrinho aplica: é por ele que um plugin troca a imagem de uma variação ou de
 * um item de assinatura, e usar um nome autoral aqui deixaria o checkout com uma
 * imagem e o carrinho com outra para o mesmo produto.
 *
 * `alt=""` e `aria-hidden="true"` de propósito: o nome do produto sai no `<span>`
 * ao lado, e uma miniatura com texto alternativo faria o leitor de tela anunciar
 * o mesmo produto duas vezes em cada linha da lista.
 *
 * @param WC_Product $produto Produto do item.
 * @param array      $item    Item do carrinho.
 * @param string     $chave   Chave do item no carrinho.
 * @return string HTML da imagem.
 */
function reconectar_checkout_miniatura( $produto, $item, $chave ) {
	$imagem = $produto->get_image(
		'woocommerce_thumbnail',
		array(
			'alt'         => '',
			'aria-hidden' => 'true',
			'class'       => 'rc-checkout__item-foto',
		)
	);

	$imagem = (string) apply_filters( 'woocommerce_cart_item_thumbnail', $imagem, $item, $chave );

	// `get_image()` já recua para o placeholder do WooCommerce; o recuo aqui cobre
	// o filtro acima devolvendo vazio, que deixaria a coluna da imagem em branco e
	// desalinharia a linha inteira da lista.
	if ( '' === trim( $imagem ) ) {
		$imagem = wc_placeholder_img( 'woocommerce_thumbnail', array( 'alt' => '', 'aria-hidden' => 'true' ) );
	}

	return $imagem;
}

/**
 * Imprime os meios de pagamento que **aquela** loja aceita.
 *
 * O seletor global de `#payment` continua no DOM e é ele que leva um
 * `payment_method` válido no POST; o que decide de verdade são estes rádios, um
 * conjunto por loja, que `Reconectar_Pagamento_Direto` grava em cada sub-pedido
 * do Dokan. Com uma loja só o comprador não percebe diferença nenhuma — é a
 * mesma escolha única de hoje, agora dentro do colapse dela.
 *
 * Loja sem meio nenhum não recebe `<fieldset>` vazio nem opção falsa: recebe um
 * alerta que a nomeia. O texto é irmão do de
 * `Reconectar_Pagamento_Direto::explicar_ausencia_de_meios()`, e o motivo é o
 * mesmo — um bloco mudo numa tela de pagamento é o pior desfecho possível.
 *
 * @param int $loja_id Identificador da loja.
 * @return void
 */
function reconectar_checkout_meios_da_loja( $loja_id ) {
	if ( ! class_exists( 'Reconectar_Pagamento_Direto' ) ) {
		return;
	}

	$meios = Reconectar_Pagamento_Direto::meios_da_loja( $loja_id );

	if ( ! $meios ) {
		printf(
			'<p class="rc-checkout__sem-meio">%s</p>',
			esc_html__( 'Esta loja ainda não cadastrou uma forma de recebimento. Remova os produtos dela do carrinho ou entre em contato com a plataforma.', 'reconectar' )
		);

		return;
	}

	$escolhido = Reconectar_Pagamento_Direto::meio_escolhido( $loja_id );

	printf(
		'<fieldset class="rc-checkout__meios"><legend class="rc-checkout__meios-titulo">%s</legend>',
		esc_html__( 'Forma de pagamento', 'reconectar' )
	);

	foreach ( $meios as $id => $gateway ) {
		$campo   = 'rc-pagamento-' . (int) $loja_id . '-' . sanitize_html_class( $id );
		$marcado = ( (string) $id === (string) $escolhido );

		printf(
			'<label class="rc-checkout__meio%1$s" for="%2$s">'
				. '<input type="radio" class="rc-checkout__meio-radio" name="%3$s[%4$d]" id="%2$s" value="%5$s"%6$s>'
				. '<span class="rc-checkout__meio-icone">%7$s</span>'
				. '<span class="rc-checkout__meio-nome">%8$s</span>'
				. '<span class="rc-checkout__meio-apoio">%9$s</span>'
				. '</label>',
			// A classe acompanha o `:checked` para quem não tem `:has()`, e é ela
			// que sobrevive ao fragmento: o estado vem do servidor, não do DOM.
			$marcado ? ' is-escolhido' : '',
			esc_attr( $campo ),
			esc_attr( Reconectar_Pagamento_Direto::CAMPO_MEIOS ),
			(int) $loja_id,
			esc_attr( $id ),
			checked( $marcado, true, false ),
			// SVG literal do código, sem dado de usuário: `wp_kses_post()`
			// descartaria o elemento inteiro, que não está em `$allowedposttags`.
			$gateway->icone(), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			esc_html( $gateway->get_title() ),
			esc_html( wp_strip_all_tags( $gateway->get_description() ) )
		);
	}

	echo '</fieldset>';
}

/**
 * Imprime o card de resumo, com os totais e o botão de finalizar.
 *
 * Vai em `woocommerce_checkout_after_order_review`, que é **irmão** do
 * `#order_review` e filho direto do `form.checkout` — a posição que permite pôr
 * o card na coluna da direita da grade sem tocar no DOM de ninguém.
 *
 * Cada linha reusa a função pública de `wc-cart-functions.php` correspondente,
 * **nunca** recalculando: o total do WooCommerce já embute cupom rateado,
 * imposto e taxa, e reproduzir essa conta aqui produziria um segundo número
 * plausível ao lado do verdadeiro, numa tela de pagamento. E só saem as linhas
 * que **existem** — inventar "Frete: R$ 0,00" onde não há método configurado
 * seria fabricar dado.
 *
 * @return void
 */
function reconectar_checkout_resumo() {
	if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
		return;
	}

	$carrinho = WC()->cart;

	echo '<aside class="rc-checkout__card rc-checkout__resumo" aria-labelledby="rc-checkout-resumo-titulo">';
	printf(
		'<h2 class="rc-checkout__titulo" id="rc-checkout-resumo-titulo">%s</h2>',
		esc_html__( 'Resumo da compra', 'reconectar' )
	);

	echo '<dl class="rc-checkout__totais">';

	$quantidade = (int) $carrinho->get_cart_contents_count();
	$promocao   = reconectar_checkout_desconto_de_promocao();

	/*
	 * Com promoção no carrinho, a linha de produtos traz a soma dos preços
	 * **cheios**, não o subtotal do WooCommerce — que já sai promocional. Sem
	 * isso os três números da tela não fecham, e foi assim que saiu, medido:
	 *
	 *   Produtos (4)          R$ 283,90
	 *   Desconto do produto   -R$ 4,10
	 *   Você pagará           R$ 283,90
	 *
	 * Uma subtração que não bate numa tela de pagamento é pior que a linha de
	 * desconto não existir: o comprador não tem como saber qual dos três está
	 * certo. Com o valor cheio acima, 288,00 − 4,10 = 283,90 se lê na tela.
	 *
	 * Sem promoção o caminho continua sendo a função de fábrica, que passa pelo
	 * filtro `woocommerce_cart_subtotal` — não há o que reconciliar ali, e
	 * substituí-la tiraria de terceiros um gancho que hoje funciona.
	 */
	reconectar_checkout_linha_de_total(
		sprintf(
			/* translators: %s: quantidade de itens no carrinho. */
			_n( 'Produtos (%s)', 'Produtos (%s)', $quantidade, 'reconectar' ),
			number_format_i18n( $quantidade )
		),
		$promocao > 0
			? reconectar_checkout_subtotal_cheio_html( $promocao )
			: reconectar_checkout_capturar( 'wc_cart_totals_subtotal_html' ),
		'subtotal'
	);

	if ( $promocao > 0 ) {
		reconectar_checkout_linha_de_total(
			__( 'Desconto do produto', 'reconectar' ),
			'-' . wc_price( $promocao ),
			'desconto'
		);
	}

	foreach ( $carrinho->get_coupons() as $codigo => $cupom ) {
		// Sem prefixo autoral: `wc_cart_totals_coupon_label()` já devolve
		// "Cupom: <código>", e envolvê-la em `sprintf( 'Cupom %s', … )` saía na
		// tela como "Cupom Cupom: teste-resumo" — medido.
		reconectar_checkout_linha_de_total(
			wc_cart_totals_coupon_label( $cupom, false ),
			reconectar_checkout_capturar( 'wc_cart_totals_coupon_html', $cupom ),
			'desconto',
			$codigo
		);
	}

	reconectar_checkout_linha_de_frete();

	foreach ( $carrinho->get_fees() as $taxa ) {
		reconectar_checkout_linha_de_total(
			$taxa->name,
			reconectar_checkout_capturar( 'wc_cart_totals_fee_html', $taxa ),
			'taxa'
		);
	}

	reconectar_checkout_linha_de_total(
		__( 'Você pagará', 'reconectar' ),
		reconectar_checkout_capturar( 'wc_cart_totals_order_total_html' ) . reconectar_checkout_meios_escolhidos_html(),
		'total'
	);

	echo '</dl>';

	$economia = $promocao + (float) $carrinho->get_discount_total();

	if ( $economia > 0 ) {
		printf(
			'<p class="rc-checkout__economia">%s</p>',
			wp_kses_post(
				sprintf(
					/* translators: %s: valor economizado, já formatado como moeda. */
					__( 'Você economizou %s', 'reconectar' ),
					wc_price( $economia )
				)
			)
		);
	}

	/*
	 * O botão real do checkout mora aqui, e o `#place_order` de fábrica é
	 * esvaziado por `reconectar_checkout_botao_vazio()`. Só a posição visual muda:
	 * o `checkout.js` do WooCommerce escuta `submit` do `form.checkout`, e o
	 * `<form>` serializa os campos onde eles estiverem — o nonce
	 * (`woocommerce-process-checkout-nonce`), o `_wp_http_referer` e o bloco de
	 * termos continuam em `.place-order`, dentro do fragmento de pagamento.
	 */
	printf(
		'<button type="submit" class="button alt rc-checkout__finalizar" name="woocommerce_checkout_place_order" id="place_order" value="%1$s" data-value="%1$s">%1$s</button>',
		esc_attr( apply_filters( 'woocommerce_order_button_text', __( 'Finalizar compra', 'reconectar' ) ) )
	);

	/*
	 * A privacidade sai daqui, e não do card de pagamento: ela qualifica o ato de
	 * finalizar ("ao finalizar você concorda…"), e o botão de finalizar mora neste
	 * card. Quem a removeu do `#payment` é `reconectar_checkout_trocar_revisao()`.
	 * A função é a de fábrica, com o filtro
	 * `woocommerce_checkout_privacy_policy_text` intacto — reescrever o texto aqui
	 * congelaria a política numa string do tema.
	 */
	if ( function_exists( 'wc_checkout_privacy_policy_text' ) ) {
		echo '<div class="rc-checkout__privacidade">';
		wc_checkout_privacy_policy_text();
		echo '</div>';
	}

	echo '</aside>';
}
add_action( 'woocommerce_checkout_after_order_review', 'reconectar_checkout_resumo', 10 );

/**
 * Soma o desconto de promoção dos itens do carrinho.
 *
 * É `regular_price − price` vezes a quantidade, item a item: dado gravado no
 * produto, não estimativa. Nada é somado quando o preço cheio está vazio ou não é
 * maior que o atual — variação sem preço regular próprio cairia nesse caso, e
 * inventar a diferença produziria um "você economizou" que ninguém concedeu.
 *
 * O desconto de cupom **não** entra aqui: ele já sai em linha própria, por
 * `wc_cart_totals_coupon_html()`, e contá-lo duas vezes inflaria a economia.
 *
 * @return float Desconto em promoção, ou zero.
 */
function reconectar_checkout_desconto_de_promocao() {
	$total = 0.0;

	if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
		return $total;
	}

	foreach ( WC()->cart->get_cart() as $item ) {
		$produto = isset( $item['data'] ) ? $item['data'] : null;

		if ( ! $produto instanceof WC_Product ) {
			continue;
		}

		$cheio = $produto->get_regular_price();
		$atual = $produto->get_price();

		if ( '' === $cheio || '' === $atual || (float) $cheio <= (float) $atual ) {
			continue;
		}

		$total += ( (float) $cheio - (float) $atual ) * (int) $item['quantity'];
	}

	return $total;
}

/**
 * Formata a soma dos preços cheios dos itens: o subtotal mais a promoção.
 *
 * A base é `WC_Cart::get_subtotal()`, e não o HTML de
 * `wc_cart_totals_subtotal_html()`, porque o que se precisa aqui é do **número**
 * para somar — o outro devolve moeda já formatada, e desmontá-la de volta a
 * float por expressão regular seria reconstruir mal o que a API entrega pronto.
 *
 * O imposto acompanha a mesma decisão de exibição do resto da tela: se o
 * carrinho mostra preços com imposto, o subtotal do imposto entra na conta. Sem
 * isso a linha divergiria do total logo abaixo assim que alguém ligasse imposto
 * — hoje desligado nesta instalação (`woocommerce_calc_taxes = no`), o que é
 * exatamente a razão de a divergência não aparecer em teste.
 *
 * @param float $promocao Desconto de promoção já apurado, em unidades de moeda.
 * @return string HTML do valor, formatado como moeda.
 */
function reconectar_checkout_subtotal_cheio_html( $promocao ) {
	$carrinho = WC()->cart;
	$valor    = (float) $carrinho->get_subtotal() + (float) $promocao;

	if ( $carrinho->display_prices_including_tax() ) {
		$valor += (float) $carrinho->get_subtotal_tax();
	}

	return wc_price( $valor );
}

/**
 * Nomeia, abaixo do total, os meios escolhidos loja a loja.
 *
 * Com uma loja só — o caso comum — isto é uma linha dizendo "PIX", exatamente o
 * que a captura de referência mostra. Com várias, os títulos **distintos**: o
 * comprador precisa saber que vai pagar de duas formas antes de clicar, não
 * depois, na tela de agradecimento.
 *
 * @return string HTML do complemento, ou vazio.
 */
function reconectar_checkout_meios_escolhidos_html() {
	if ( ! class_exists( 'Reconectar_Pagamento_Direto' ) ) {
		return '';
	}

	$escolhas = Reconectar_Pagamento_Direto::meios_escolhidos();

	if ( ! $escolhas ) {
		return '';
	}

	$gateways = Reconectar_Pagamento_Direto::gateways_diretos( false );
	$titulos  = array();

	foreach ( $escolhas as $id ) {
		if ( isset( $gateways[ $id ] ) ) {
			$titulos[ $id ] = $gateways[ $id ]->get_title();
		}
	}

	if ( ! $titulos ) {
		return '';
	}

	return sprintf(
		'<span class="rc-checkout__linha-meio">%s</span>',
		esc_html( implode( ' · ', $titulos ) )
	);
}

/**
 * Imprime uma linha do resumo.
 *
 * @param string $rotulo Rótulo da linha.
 * @param string $valor  HTML do valor, vindo de uma função do WooCommerce.
 * @param string $tipo   Sufixo de classe: `subtotal`, `desconto`, `frete`, `taxa` ou `total`.
 * @param string $extra  Identificador auxiliar, hoje o código do cupom.
 * @return void
 */
function reconectar_checkout_linha_de_total( $rotulo, $valor, $tipo, $extra = '' ) {
	printf(
		'<div class="rc-checkout__linha rc-checkout__linha--%1$s"%2$s><dt class="rc-checkout__linha-rotulo">%3$s</dt><dd class="rc-checkout__linha-valor">%4$s</dd></div>',
		esc_attr( $tipo ),
		$extra ? ' data-rc-cupom="' . esc_attr( $extra ) . '"' : '',
		esc_html( $rotulo ),
		// Sem `wp_kses_post()` de propósito: `$valor` já vem escapado da origem
		// (`wc_price()`, `esc_attr()`, `wp_kses_post()` dentro de cada montador), e
		// `$allowedposttags` **não inclui `<input>`** — o filtro apagaria em silêncio
		// os rádios e o campo oculto do seletor de frete, deixando um resumo com o
		// rótulo do método e sem como escolhê-lo.
		$valor // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	);
}

/**
 * Captura a saída de uma função de total do WooCommerce.
 *
 * As funções de `includes/wc-cart-functions.php` **imprimem** em vez de devolver
 * — `wc_cart_totals_subtotal_html()` é literalmente um `echo`. Passá-las como
 * argumento de `reconectar_checkout_linha_de_total()` ecoaria o valor *antes* da
 * linha que deveria contê-lo e entregaria `null` ao `<dd>`: os números sairiam
 * todos amontoados no topo do card e cada linha ficaria sem valor. A única
 * exceção no arquivo é `wc_cart_totals_coupon_label( $cupom, false )`, que
 * devolve; e `wc_cart_totals_shipping_method_label()`, que também devolve.
 *
 * @param callable $funcao    Função de total do WooCommerce.
 * @param mixed    $argumento Argumento único, quando a função pede um.
 * @return string HTML capturado.
 */
function reconectar_checkout_capturar( $funcao, $argumento = null ) {
	ob_start();

	if ( null === $argumento ) {
		call_user_func( $funcao );
	} else {
		call_user_func( $funcao, $argumento );
	}

	return (string) ob_get_clean();
}

/**
 * Imprime a linha de frete de cada pacote, ou diz por que ela não tem valor.
 *
 * Não usa `wc_cart_totals_shipping_html()`, apesar de ela ser a função pública do
 * WooCommerce para isto: ela inclui `cart/cart-shipping.php`, cujo markup começa
 * em `<tr class="woocommerce-shipping-totals shipping">`. Dentro de um `<dl>` as
 * tags de tabela órfãs são descartadas pelo parser e o conteúdo **vaza para fora
 * do lugar** — o valor do frete apareceria antes do card inteiro.
 *
 * Por isso o seletor de método é remontado aqui. Ele não é enfeite: trocar o
 * `#order_review` pelo card autoral tirou da tela o único lugar onde o comprador
 * escolhia o frete, e uma instalação com zona cadastrada ficaria sem essa
 * escolha. Os nomes dos campos são os que o `checkout.js` do WooCommerce observa
 * — ele liga em `input[name^="shipping_method"]`, não no `#shipping_method`, então
 * a lista autoral dispara `update_checkout` igual à de fábrica.
 *
 * Três estados sem método, e o último é o que importa: medido neste ambiente,
 * todos os produtos têm `needs_shipping = true` e **não há zona nem método de
 * envio cadastrado** (`zonas: 0`). Nesse estado o WooCommerce simplesmente não
 * imprime linha de frete — e um resumo silencioso deixa o comprador somando na
 * cabeça uma parcela que ele não sabe se existe. Escrever "R$ 0,00" seria pior:
 * um número que a plataforma não pode honrar. A saída é nomear a causa, no mesmo
 * espírito de `Reconectar_Pagamento_Direto::explicar_ausencia_de_meios()`.
 *
 * @return void
 */
function reconectar_checkout_linha_de_frete() {
	$carrinho = WC()->cart;

	if ( ! $carrinho->needs_shipping() ) {
		return;
	}

	// `show_shipping()` responde `false` quando a loja exige endereço antes de
	// calcular e ele ainda não foi informado. Omitir a linha aqui diria ao
	// comprador que não há frete; o que é verdade é que ainda não se sabe.
	if ( ! $carrinho->show_shipping() ) {
		reconectar_checkout_aviso_de_frete(
			__( 'Frete', 'reconectar' ),
			__( 'calculado após informar o endereço', 'reconectar' )
		);

		return;
	}

	$pacotes  = WC()->shipping() ? WC()->shipping()->get_packages() : array();
	$imprimiu = false;

	foreach ( $pacotes as $indice => $pacote ) {
		if ( empty( $pacote['rates'] ) ) {
			continue;
		}

		reconectar_checkout_linha_de_total(
			reconectar_checkout_nome_do_pacote( $indice, $pacote ),
			reconectar_checkout_opcoes_de_frete( $indice, $pacote['rates'] ),
			'frete'
		);

		$imprimiu = true;
	}

	if ( $imprimiu ) {
		return;
	}

	reconectar_checkout_aviso_de_frete(
		__( 'Frete', 'reconectar' ),
		__( 'a combinar com a loja', 'reconectar' )
	);
}

/**
 * Imprime uma linha de frete sem valor, nomeando a causa.
 *
 * @param string $rotulo Rótulo da linha.
 * @param string $aviso  Texto que substitui o valor.
 * @return void
 */
function reconectar_checkout_aviso_de_frete( $rotulo, $aviso ) {
	printf(
		'<div class="rc-checkout__linha rc-checkout__linha--frete"><dt class="rc-checkout__linha-rotulo">%1$s</dt><dd class="rc-checkout__linha-valor rc-checkout__linha-valor--aviso">%2$s</dd></div>',
		esc_html( $rotulo ),
		esc_html( $aviso )
	);
}

/**
 * Devolve o rótulo de um pacote de envio.
 *
 * O filtro `woocommerce_shipping_package_name` é aplicado com o mesmo padrão do
 * template de fábrica porque é por ele que o Dokan nomeia o pacote de cada loja:
 * o marketplace divide o carrinho por vendedor, e sem o filtro as linhas sairiam
 * como "Frete 1", "Frete 2" — números sem dono numa tela de pagamento. O
 * `wp_strip_all_tags()` existe porque esse nome volta com link da loja em alguns
 * casos, e o rótulo entra por `esc_html()`.
 *
 * @param int   $indice Posição do pacote.
 * @param array $pacote Pacote de envio.
 * @return string Rótulo em texto puro.
 */
function reconectar_checkout_nome_do_pacote( $indice, $pacote ) {
	$padrao = $indice > 0
		/* translators: %d: número do pacote de envio, quando há mais de um. */
		? sprintf( __( 'Frete %d', 'reconectar' ), $indice + 1 )
		: __( 'Frete', 'reconectar' );

	return wp_strip_all_tags(
		(string) apply_filters( 'woocommerce_shipping_package_name', $padrao, $indice, $pacote )
	);
}

/**
 * Monta o valor — ou o seletor — de frete de um pacote.
 *
 * Com um método só, campo oculto mais o rótulo, como o template de fábrica: o
 * valor precisa ser submetido mesmo sem escolha a fazer. Com vários, rádios.
 *
 * @param int               $indice Posição do pacote.
 * @param WC_Shipping_Rate[] $taxas Métodos disponíveis.
 * @return string HTML do valor.
 */
function reconectar_checkout_opcoes_de_frete( $indice, $taxas ) {
	$escolhidos = WC()->session ? (array) WC()->session->get( 'chosen_shipping_methods' ) : array();
	$escolhido  = isset( $escolhidos[ $indice ] ) ? $escolhidos[ $indice ] : '';

	if ( 1 === count( $taxas ) ) {
		$taxa = reset( $taxas );

		return sprintf(
			'<input type="hidden" name="shipping_method[%1$s]" data-index="%1$s" id="shipping_method_%1$s" value="%2$s" class="shipping_method">%3$s',
			esc_attr( $indice ),
			esc_attr( $taxa->get_id() ),
			wp_kses_post( wc_cart_totals_shipping_method_label( $taxa ) )
		);
	}

	$html = '<ul class="rc-checkout__fretes">';

	foreach ( $taxas as $id => $taxa ) {
		$campo = 'shipping_method_' . $indice . '_' . sanitize_title( $id );

		$html .= sprintf(
			'<li class="rc-checkout__frete"><input type="radio" name="shipping_method[%1$s]" data-index="%1$s" id="%2$s" value="%3$s" class="shipping_method"%4$s><label for="%2$s">%5$s</label></li>',
			esc_attr( $indice ),
			esc_attr( $campo ),
			esc_attr( $taxa->get_id() ),
			checked( $taxa->get_id(), $escolhido, false ),
			wp_kses_post( wc_cart_totals_shipping_method_label( $taxa ) )
		);
	}

	return $html . '</ul>';
}

/**
 * Esvazia o botão de fábrica, porque o real está no card de resumo.
 *
 * Devolver string vazia é o caminho, e não `display: none` no CSS: um `<button
 * type="submit">` escondido continua submetível por teclado, e dois botões de
 * finalizar no mesmo formulário são dois `id="place_order"` — HTML inválido que
 * faz o `checkout.js` do WooCommerce ligar o bloqueio de duplo envio a um deles
 * só.
 *
 * @return string Sempre vazio.
 */
function reconectar_checkout_botao_vazio() {
	return '';
}
add_filter( 'woocommerce_order_button_html', 'reconectar_checkout_botao_vazio' );

/**
 * Mantém o pedido e o resumo em dia quando o WooCommerce recalcula por AJAX.
 *
 * `WC_AJAX::update_order_review()` faz `$( chave ).replaceWith( valor )` para
 * **cada** chave do array devolvido por este filtro, e o seletor é livre — os
 * dois de fábrica (`.woocommerce-checkout-review-order-table` e
 * `.woocommerce-checkout-payment`) não têm nada de especial além de estarem
 * registrados aqui.
 *
 * Sem estes dois, o resumo congelaria no estado anterior ao cupom ou à troca de
 * endereço: o comprador veria um total plausível e **errado** numa tela de
 * pagamento, que é exatamente o que a regra de honestidade de dados proíbe.
 *
 * @param array $fragmentos Fragmentos a substituir, por seletor.
 * @return array Fragmentos com o pedido e o resumo autorais.
 */
function reconectar_checkout_fragmentos( $fragmentos ) {
	ob_start();
	reconectar_checkout_pedido();
	$fragmentos['.rc-checkout__pedido'] = ob_get_clean();

	ob_start();
	reconectar_checkout_resumo();
	$fragmentos['.rc-checkout__resumo'] = ob_get_clean();

	return $fragmentos;
}
add_filter( 'woocommerce_update_order_review_fragments', 'reconectar_checkout_fragmentos' );

/**
 * Imprime o título do card de dados do comprador.
 *
 * O `<h3>` de fábrica ("Detalhes de cobrança") sai por CSS, e não por filtro:
 * não há gancho para ele — `woocommerce_checkout_billing()` o escreve direto no
 * template. Esconder pelo CSS aqui é seguro, ao contrário da armadilha do
 * `woocommerce_show_page_title`, porque o título **não desaparece**: ele é
 * substituído por este, que fica no DOM e é lido pelo leitor de tela.
 *
 * @return void
 */
function reconectar_checkout_titulo_dos_dados() {
	printf(
		'<h2 class="rc-checkout__titulo">%s</h2>',
		esc_html__( 'Seus dados', 'reconectar' )
	);
}
add_action( 'woocommerce_before_checkout_billing_form', 'reconectar_checkout_titulo_dos_dados', 5 );

/**
 * Imprime o título do card de observações do pedido.
 *
 * @return void
 */
function reconectar_checkout_titulo_das_observacoes() {
	printf(
		'<h2 class="rc-checkout__titulo">%s</h2>',
		esc_html__( 'Observações', 'reconectar' )
	);
}
add_action( 'woocommerce_before_order_notes', 'reconectar_checkout_titulo_das_observacoes', 5 );

/**
 * Marca o `<body>` do checkout, para o CSS alcançar a página inteira.
 *
 * A grade de duas colunas e os cards são escopados por esta classe: sem ela, as
 * regras alcançariam também o carrinho e a tela de agradecimento, que
 * compartilham vários seletores do WooCommerce (`.col2-set`, `#customer_details`)
 * e têm layout próprio.
 *
 * @param string[] $classes Classes do `<body>`.
 * @return string[] Classes com a marca do checkout.
 */
function reconectar_checkout_marcar_body( $classes ) {
	if ( function_exists( 'is_checkout' ) && is_checkout() && ! is_wc_endpoint_url( 'order-received' ) ) {
		$classes[] = 'rc-checkout';
	}

	return $classes;
}
add_filter( 'body_class', 'reconectar_checkout_marcar_body' );
