/**
 * Comportamento dos carrosséis e da coluna de filtros do marketplace.
 *
 * Progressive enhancement, no sentido estrito: a faixa é um contêiner com
 * `overflow-x` e rola sozinha no toque, no trackpad e pelo teclado. Este arquivo
 * só acrescenta as setas para quem usa mouse em tela grande, onde arrastar uma
 * faixa horizontal não é gesto natural. Se o script não carregar, nada se perde.
 *
 * Por isso as setas nascem escondidas no CSS e só aparecem quando a classe
 * `is-interativo` é aplicada aqui — nunca há um botão inerte na tela.
 *
 * @package reconectar
 */

( function () {
	'use strict';

	/**
	 * Distância percorrida a cada clique, como fração da largura visível.
	 *
	 * Menor que 1 de propósito: rolar exatamente uma tela esconde por completo o
	 * que estava visível, e o cliente perde a referência de onde parou.
	 */
	var FRACAO_DO_PASSO = 0.8;

	/**
	 * Margem de tolerância, em pixels, para considerar a faixa no fim.
	 *
	 * `scrollLeft` é fracionário quando há zoom do navegador ou densidade de tela
	 * não inteira. Comparar com igualdade deixaria a seta da direita ativa para
	 * sempre em alguns monitores.
	 */
	var TOLERANCIA = 2;

	/**
	 * Liga um carrossel.
	 *
	 * @param {HTMLElement} carrossel Elemento com `data-rc-carrossel`.
	 */
	function ligarCarrossel( carrossel ) {
		var faixa = carrossel.querySelector( '[data-rc-carrossel-faixa]' );
		var anterior = carrossel.querySelector( '[data-rc-carrossel-anterior]' );
		var proximo = carrossel.querySelector( '[data-rc-carrossel-proximo]' );

		if ( ! faixa || ! anterior || ! proximo ) {
			return;
		}

		/**
		 * Habilita ou desabilita as setas conforme a posição da rolagem.
		 *
		 * As setas ficam `disabled` em vez de sumirem: um botão que desaparece
		 * desloca o que está ao lado e faz o cabeçalho da seção "pular" a cada
		 * clique.
		 */
		function atualizarSetas() {
			var maximo = faixa.scrollWidth - faixa.clientWidth;

			// Sem conteúdo excedente não há o que rolar: as setas somem inteiras.
			carrossel.classList.toggle( 'is-interativo', maximo > TOLERANCIA );

			anterior.disabled = faixa.scrollLeft <= TOLERANCIA;
			proximo.disabled = faixa.scrollLeft >= maximo - TOLERANCIA;
		}

		/**
		 * Rola a faixa em uma direção.
		 *
		 * @param {number} direcao -1 para a esquerda, 1 para a direita.
		 */
		function rolar( direcao ) {
			faixa.scrollBy( {
				left: direcao * faixa.clientWidth * FRACAO_DO_PASSO,
				behavior: 'smooth',
			} );
		}

		anterior.addEventListener( 'click', function () {
			rolar( -1 );
		} );

		proximo.addEventListener( 'click', function () {
			rolar( 1 );
		} );

		faixa.addEventListener( 'scroll', atualizarSetas, { passive: true } );

		/*
		 * A largura da faixa muda ao girar o celular, ao redimensionar a janela e
		 * quando as imagens dos cards terminam de carregar. `ResizeObserver` cobre
		 * os três; onde ele não existe, o evento de resize cobre os dois primeiros.
		 */
		if ( 'ResizeObserver' in window ) {
			new ResizeObserver( atualizarSetas ).observe( faixa );
		} else {
			window.addEventListener( 'resize', atualizarSetas );
		}

		atualizarSetas();
	}

	/**
	 * Mantém a coluna de filtros do catálogo aberta no desktop.
	 *
	 * O `<details>` nasce fechado no HTML, que é o certo para o celular: aberto,
	 * ele poria uma tela inteira de opções antes do primeiro produto. No desktop
	 * a coluna tem lugar próprio ao lado da grade, e um botão "Filtrar" ali seria
	 * um clique a mais para nada — por isso abre, e a classe `is-fixo` esconde o
	 * `<summary>` no CSS. Sem este script, o desktop fica com o botão: pior de
	 * usar, nunca com filtro escondido.
	 *
	 * A largura casa com o `@media` de `.rc-catalogo` em `marketplace.css`.
	 *
	 * @param {HTMLDetailsElement} filtros Elemento com `data-rc-catalogo-filtros`.
	 */
	function ligarFiltrosDoCatalogo( filtros ) {
		var largo = window.matchMedia( '(min-width: 768px)' );

		function aplicar() {
			filtros.open = largo.matches;
			filtros.classList.toggle( 'is-fixo', largo.matches );
		}

		// `addListener` para o Safari anterior ao 14, que não tem `addEventListener`
		// em `MediaQueryList`.
		if ( largo.addEventListener ) {
			largo.addEventListener( 'change', aplicar );
		} else {
			largo.addListener( aplicar );
		}

		aplicar();
	}

	function iniciar() {
		var carrosseis = document.querySelectorAll( '[data-rc-carrossel]' );
		var filtros = document.querySelectorAll( '[data-rc-catalogo-filtros]' );

		Array.prototype.forEach.call( carrosseis, ligarCarrossel );
		Array.prototype.forEach.call( filtros, ligarFiltrosDoCatalogo );
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', iniciar );
	} else {
		iniciar();
	}
}() );
