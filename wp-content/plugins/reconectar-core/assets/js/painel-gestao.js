/**
 * Repetidor de alternativas da enquete, sem dependência.
 *
 * Os índices de `opcoes[i]` são renumerados a cada mudança: o PHP lê a lista
 * em ordem, e um buraco deixado por remoção não causaria erro, mas o rótulo
 * "Alternativa 3" ao lado da segunda linha confundiria quem lê com leitor de
 * tela. Sem JavaScript, o formulário funciona com as linhas que vieram do
 * servidor.
 *
 * @package reconectar-core
 */
( function () {
	'use strict';

	document.querySelectorAll( '[data-rc-repetidor]' ).forEach( function ( repetidor ) {
		var lista = repetidor.querySelector( '[data-rc-repetidor-lista]' );
		var adicionar = repetidor.querySelector( '[data-rc-adicionar]' );
		var anuncio = repetidor.querySelector( '[data-rc-anuncio]' );
		var minimo = parseInt( repetidor.getAttribute( 'data-rc-minimo' ), 10 ) || 1;
		var limite = parseInt( repetidor.getAttribute( 'data-rc-limite' ), 10 ) || 10;

		if ( ! lista || ! adicionar ) {
			return;
		}

		/**
		 * As linhas na ordem do DOM.
		 *
		 * @return {Element[]}
		 */
		function linhas() {
			return Array.prototype.slice.call( lista.querySelectorAll( '[data-rc-repetidor-linha]' ) );
		}

		/**
		 * Renumera nomes, ids e rótulos, e liga ou desliga os botões nos limites.
		 */
		function renumerar() {
			var todas = linhas();

			todas.forEach( function ( linha, i ) {
				var id = linha.querySelector( '[data-rc-campo="id"]' );
				var texto = linha.querySelector( '[data-rc-campo="texto"]' );
				var rotulo = linha.querySelector( '[data-rc-rotulo]' );
				var remover = linha.querySelector( '[data-rc-remover]' );

				id.name = 'opcoes[' + i + '][id]';
				texto.name = 'opcoes[' + i + '][texto]';
				texto.id = 'rc-enquete-opcao-' + i;
				rotulo.htmlFor = texto.id;
				rotulo.textContent = 'Alternativa ' + ( i + 1 );
				// O botão diz qual linha remove: cinco "Remover" iguais seriam
				// indistinguíveis na lista de controles do leitor de tela.
				remover.setAttribute( 'aria-label', 'Remover alternativa ' + ( i + 1 ) );
				remover.disabled = todas.length <= minimo;
			} );

			adicionar.disabled = todas.length >= limite;
		}

		/**
		 * Põe o texto na região viva.
		 *
		 * @param {string} texto Mensagem.
		 */
		function anunciar( texto ) {
			if ( anuncio ) {
				anuncio.textContent = texto;
			}
		}

		adicionar.addEventListener( 'click', function () {
			var todas = linhas();

			if ( todas.length >= limite ) {
				return;
			}

			var nova = todas[ todas.length - 1 ].cloneNode( true );

			nova.querySelector( '[data-rc-campo="id"]' ).value = '';
			nova.querySelector( '[data-rc-campo="texto"]' ).value = '';
			lista.appendChild( nova );
			renumerar();
			nova.querySelector( '[data-rc-campo="texto"]' ).focus();
			anunciar( 'Alternativa ' + linhas().length + ' adicionada.' );
		} );

		lista.addEventListener( 'click', function ( evento ) {
			var botao = evento.target.closest( '[data-rc-remover]' );

			if ( ! botao || linhas().length <= minimo ) {
				return;
			}

			var linha = botao.closest( '[data-rc-repetidor-linha]' );
			var posicao = linhas().indexOf( linha );

			linha.remove();
			renumerar();

			// O foco ia embora com a linha removida; devolvê-lo à vizinha evita
			// que o teclado recomece do topo da página.
			var restantes = linhas();
			var vizinha = restantes[ Math.min( posicao, restantes.length - 1 ) ];
			vizinha.querySelector( '[data-rc-campo="texto"]' ).focus();
			anunciar( 'Alternativa removida. Restam ' + restantes.length + '.' );
		} );

		renumerar();
	} );
}() );
