/**
 * Comportamento dos carrosséis do marketplace.
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

	function iniciar() {
		var carrosseis = document.querySelectorAll( '[data-rc-carrossel]' );

		Array.prototype.forEach.call( carrosseis, ligarCarrossel );
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', iniciar );
	} else {
		iniciar();
	}
}() );
