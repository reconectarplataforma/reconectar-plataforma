/**
 * Checkout: recolhe endereço e complementos num colapse.
 *
 * Enriquecimento, como o resto do JS deste tema: o HTML do servidor já é um
 * checkout completo, com todos os campos à vista. Sem este arquivo ninguém fica
 * preso — apenas vê a lista inteira de uma vez.
 *
 * O ponto delicado é que `billing_address_1`, `billing_city`, `billing_state` e
 * `billing_postcode` são `validate-required`. Esconder campo obrigatório vazio
 * faria o comprador clicar em finalizar e receber erro num campo que **não vê**
 * — o colapse nasce aberto nesse caso, e reabre quando a validação reprova algo
 * lá dentro.
 */
( function () {
	'use strict';

	var textos = window.reconectarCheckout || {};

	/*
	 * Os quatro que ficam à vista. Nome e sobrenome identificam; telefone e
	 * e-mail são por onde a loja avisa que o pedido saiu — sem eles o comprador
	 * não sabe se a plataforma tem como falar com ele. Endereço, complemento,
	 * empresa, país, estado e CEP vão para o colapse: são muitos campos, e a
	 * maioria já vem preenchida para quem compra pela segunda vez.
	 */
	var PRINCIPAIS = [
		'billing_first_name',
		'billing_last_name',
		'billing_phone',
		'billing_email'
	];

	var wrapper = document.querySelector( '.woocommerce-billing-fields__field-wrapper' );

	if ( ! wrapper ) {
		return;
	}

	var detalhes = document.createElement( 'details' );
	var resumo   = document.createElement( 'summary' );
	var corpo    = document.createElement( 'div' );

	detalhes.className = 'rc-checkout__mais';
	resumo.className   = 'rc-checkout__mais-gatilho';
	corpo.className    = 'rc-checkout__mais-corpo';
	resumo.textContent = textos.maisDados || 'Endereço e mais dados';

	detalhes.appendChild( resumo );
	detalhes.appendChild( corpo );

	/**
	 * Diz se a linha contém um dos campos que ficam à vista.
	 *
	 * @param {Element} linha Elemento `.form-row`.
	 * @return {boolean} Se deve permanecer fora do colapse.
	 */
	function ePrincipal( linha ) {
		var i;

		for ( i = 0; i < PRINCIPAIS.length; i++ ) {
			if ( linha.querySelector( '#' + PRINCIPAIS[ i ] ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Diz se algum campo obrigatório dentro do colapse está vazio.
	 *
	 * @return {boolean} Se falta preencher algo escondido.
	 */
	function faltaObrigatorio() {
		var campos = corpo.querySelectorAll(
			'.validate-required input, .validate-required select, .validate-required textarea'
		);
		var i;

		for ( i = 0; i < campos.length; i++ ) {
			if ( ! campos[ i ].value || '' === String( campos[ i ].value ).trim() ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Diz se há linha fora de lugar — filha direta do wrapper e não principal.
	 *
	 * É o que torna `organizar()` seguro sob o observador abaixo: sem esta guarda,
	 * o `appendChild` do próprio método geraria mutação a cada passagem e o par
	 * observador/organizador entraria em laço infinito.
	 *
	 * A última condição cobre o caso em que só o `<details>` está fora de ordem —
	 * campos todos no lugar, mas o colapse acima deles.
	 *
	 * @return {boolean} Se `organizar()` tem trabalho a fazer.
	 */
	function precisaOrganizar() {
		var i;

		for ( i = 0; i < wrapper.children.length; i++ ) {
			if ( wrapper.children[ i ] === detalhes ) {
				continue;
			}

			if ( ! ePrincipal( wrapper.children[ i ] ) ) {
				return true;
			}
		}

		return wrapper.lastElementChild !== detalhes;
	}

	/*
	 * Idempotente de propósito, e chamada de novo pelo observador: o
	 * `address-i18n.js` do WooCommerce reordena as `.form-row` por prioridade com
	 * `appendTo( wrapper )`, o que puxa de volta tudo o que está aqui dentro.
	 */
	function organizar() {
		var aberto = detalhes.open;
		var mover  = [];
		var i;

		if ( ! precisaOrganizar() ) {
			return;
		}

		for ( i = 0; i < wrapper.children.length; i++ ) {
			if ( wrapper.children[ i ] === detalhes ) {
				continue;
			}

			if ( ! ePrincipal( wrapper.children[ i ] ) ) {
				mover.push( wrapper.children[ i ] );
			}
		}

		for ( i = 0; i < mover.length; i++ ) {
			corpo.appendChild( mover[ i ] );
		}

		// Sempre ao fim: os principais ficam acima, e reanexar um nó já filho do
		// wrapper apenas o move para a última posição.
		wrapper.appendChild( detalhes );

		detalhes.open = aberto || faltaObrigatorio();
	}

	organizar();

	/*
	 * Observador, e não um `on( 'country_to_state_changed' )`: medido, a primeira
	 * reordenação do `address-i18n.js` acontece no **init** do checkout, depois
	 * deste arquivo — que roda síncrono no rodapé — e sem passar por aquele evento.
	 * O resultado era o pior sintoma possível: o `<details>` no DOM, com o corpo
	 * vazio e os dez campos de volta à vista, numa tela que parece apenas não ter
	 * recebido a melhoria. Medido no carregamento, antes desta correção:
	 *
	 *   corpo = 0 elementos, wrapper = 11 filhos (o colapse vazio, em primeiro)
	 *
	 * O evento continua correto para a troca de país e ainda assim ficou de fora:
	 * o observador cobre aquele caso e qualquer outro caminho que reanexe linha ao
	 * wrapper, sem depender de adivinhar qual gancho do WooCommerce o produziu. E
	 * funciona sem jQuery, ao contrário do `checkout_error` logo abaixo.
	 */
	new MutationObserver( organizar ).observe( wrapper, { childList: true } );

	/*
	 * jQuery aqui não é comodidade: `checkout_error` é disparado por `.trigger()`
	 * do jQuery, que **não** cria evento DOM real — um `addEventListener` nativo
	 * nunca seria chamado. É também por isso que o enfileiramento deste arquivo
	 * declara a dependência, ao contrário do resto do JS do tema: no checkout o
	 * WooCommerce já carrega o seu.
	 */
	if ( ! window.jQuery ) {
		return;
	}

	window.jQuery( document.body ).on( 'checkout_error', function () {
		var invalido = corpo.querySelector(
			'.woocommerce-invalid input, .woocommerce-invalid select, .woocommerce-invalid textarea'
		);

		if ( ! invalido ) {
			return;
		}

		detalhes.open = true;

		// Sem mover o foco, o aviso de erro no topo aponta para um campo que a
		// pessoa acabou de ver aparecer e ainda precisa procurar.
		invalido.focus();
	} );
} )();

/**
 * Checkout: o meio de pagamento escolhido loja a loja.
 *
 * IIFE própria, e não um trecho da de cima, porque os dois enriquecimentos são
 * independentes: aquela desiste cedo quando não acha o wrapper de cobrança, e
 * nada aqui depende dele.
 *
 * Enriquecimento também: sem jQuery — ou sem JavaScript nenhum — a escolha ainda
 * chega ao servidor, porque o `<form>` serializa por contenção e os rádios estão
 * dentro dele. O que se perde é só o resumo acompanhar a troca antes de
 * finalizar.
 */
( function () {
	'use strict';

	// O mesmo nome de `Reconectar_Pagamento_Direto::CAMPO_MEIOS`. Os campos saem
	// como `rc_pagamento[<loja_id>]`, daí o seletor por prefixo.
	var SELETOR_RADIO = 'input[name^="rc_pagamento"]';

	if ( ! window.jQuery ) {
		return;
	}

	var $      = window.jQuery;
	var estado = null;

	/**
	 * Guarda o que a troca de fragmento apagaria.
	 *
	 * O fragmento troca `.rc-checkout__pedido` inteiro: os `<details>` voltam ao
	 * estado que o servidor imprime — primeiro aberto, os demais fechados — e o
	 * rádio recém-clicado deixa de existir, levando o foco junto. A chave estável
	 * entre o antes e o depois é o `data-loja`.
	 *
	 * @return {void}
	 */
	function guardar() {
		var abertos = [];
		var ativo   = document.activeElement;

		$( '.rc-checkout__loja[data-loja]' ).each( function () {
			if ( this.open ) {
				abertos.push( this.getAttribute( 'data-loja' ) );
			}
		} );

		estado = {
			abertos: abertos,
			// Só o foco que estava num rádio nosso: devolvê-lo em qualquer outro
			// caso o roubaria de quem tivesse seguido para o campo seguinte — o
			// `update_checkout` também dispara ao sair do CEP e ao aplicar cupom.
			foco: ( ativo && ativo.matches && ativo.matches( SELETOR_RADIO ) ) ? ativo.id : ''
		};
	}

	/**
	 * Devolve os colapses abertos e o foco ao rádio.
	 *
	 * @return {void}
	 */
	function restaurar() {
		if ( ! estado ) {
			return;
		}

		$( '.rc-checkout__loja[data-loja]' ).each( function () {
			this.open = estado.abertos.indexOf( this.getAttribute( 'data-loja' ) ) !== -1;
		} );

		var radio = estado.foco ? document.getElementById( estado.foco ) : null;

		if ( radio ) {
			// `preventScroll` porque o card acabou de ser repintado: sem ele o
			// navegador rolaria a página até o rádio, e quem clicou já estava
			// olhando para ele.
			try {
				radio.focus( { preventScroll: true } );
			} catch ( erro ) {
				radio.focus();
			}
		}

		estado = null;
	}

	/*
	 * Delegado no documento: os rádios são repintados a cada fragmento, e um
	 * `on( 'change', … )` ligado direto neles morreria na primeira troca.
	 */
	$( document ).on( 'change', SELETOR_RADIO, function () {
		$( document.body ).trigger( 'update_checkout' );
	} );

	// Em `update_checkout` o DOM ainda é o antigo — a requisição nem partiu —, e
	// em `updated_checkout` já é o novo. É o par que torna a captura confiável
	// para **qualquer** origem da atualização, não só o clique no rádio: aplicar
	// cupom fechava os colapses desde a entrega anterior.
	$( document.body ).on( 'update_checkout', guardar );
	$( document.body ).on( 'updated_checkout', restaurar );
} )();
