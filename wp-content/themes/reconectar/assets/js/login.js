/**
 * Tela de acesso: revela o gatilho do cadastro e colapsa o formulário dele.
 *
 * Enriquecimento, como os carrosséis e a busca: o HTML entregue pelo servidor
 * já é uma tela funcional, com os dois formulários abertos e o botão marcado
 * com `hidden`. É este arquivo que inverte a situação — mostra o botão e fecha
 * o que ele abre. O formulário de entrada não tem gatilho e fica sempre aberto. Sem ele, ninguém fica preso atrás de um gatilho que
 * não responde.
 */
( function () {
	'use strict';

	var raiz = document.querySelector( '[data-rc-login]' );

	if ( ! raiz ) {
		return;
	}

	/*
	 * Um erro de login ou de cadastro volta como aviso do WooCommerce no topo da
	 * página, e o campo que o causou está dentro de um dos blocos. Colapsar aqui
	 * esconderia justamente o que a pessoa precisa corrigir, deixando na tela uma
	 * mensagem de erro sem nada para consertar. Nesse caso o script não faz nada.
	 */
	if ( document.querySelector( '.woocommerce-error, .woocommerce-message, .woocommerce-info' ) ) {
		return;
	}

	var gatilhos = raiz.querySelectorAll( '[aria-controls]' );

	Array.prototype.forEach.call( gatilhos, function ( gatilho ) {
		var bloco = document.getElementById( gatilho.getAttribute( 'aria-controls' ) );

		if ( ! bloco ) {
			return;
		}

		gatilho.hidden = false;
		bloco.hidden = true;
		gatilho.setAttribute( 'aria-expanded', 'false' );

		gatilho.addEventListener( 'click', function () {
			var aberto = 'true' === gatilho.getAttribute( 'aria-expanded' );

			gatilho.setAttribute( 'aria-expanded', aberto ? 'false' : 'true' );
			bloco.hidden = aberto;

			if ( aberto ) {
				return;
			}

			// Abrir sem mover o foco obrigaria a pessoa que navega por teclado a
			// percorrer de novo o caminho até o primeiro campo.
			var primeiro = bloco.querySelector( 'input:not([type="hidden"]), select, textarea' );

			if ( primeiro ) {
				primeiro.focus();
			}
		} );
	} );
} )();
