/**
 * Incubadora — o botão "Restaurar esta versão".
 *
 * Carregado só na tela de uma versão antiga e só para quem pode restaurá-la;
 * os dados vêm de `reconectarIncubadoraHistorico`. Depois de restaurar, leva
 * à página: a tela da versão continuaria mostrando o texto antigo com uma
 * faixa que já não é verdade.
 *
 * O `action` vai no corpo e na URL: acima de `post_max_size` o PHP descarta o
 * corpo inteiro, e a URL é o que sobra para o servidor saber que resposta dar.
 *
 * @package reconectar-core
 */
( function () {
	'use strict';

	var dados = window.reconectarIncubadoraHistorico;
	var botao = document.querySelector( '[data-rc-incubadora="restaurar"]' );
	var pagina = botao && botao.closest( '.rc-incubadora__pagina' );
	var status = pagina && pagina.querySelector( '.rc-incubadora__status' );
	var alerta = pagina && pagina.querySelector( '.rc-incubadora__alerta' );

	if ( ! dados || ! botao || ! status || ! alerta || ! window.fetch || ! window.FormData ) {
		return;
	}

	var t = dados.textos;

	/**
	 * Escreve na região de status, que o leitor de tela anuncia sem tirar o foco.
	 *
	 * @param {string} texto Mensagem; vazio limpa.
	 */
	function anunciar( texto ) {
		status.textContent = texto;
	}

	/**
	 * Escreve na região de alerta, para erro que pede ação.
	 *
	 * @param {string} texto Mensagem; vazio limpa.
	 */
	function alertar( texto ) {
		alerta.textContent = texto;
	}

	botao.addEventListener( 'click', function () {
		// `disabled` tiraria o foco do botão, e o leitor de tela o perderia no
		// meio da página; `aria-disabled` mais a guarda aqui seguram o
		// segundo clique sem mover nada.
		if ( 'true' === botao.getAttribute( 'aria-disabled' ) ) {
			return;
		}

		var corpo = new FormData();

		corpo.append( 'action', dados.acao );
		corpo.append( '_wpnonce', dados.nonce );
		corpo.append( 'pagina', String( dados.pagina ) );
		corpo.append( 'versao', String( dados.versao ) );
		corpo.append( 'modificado', dados.modificado );

		botao.setAttribute( 'aria-disabled', 'true' );
		alertar( '' );
		anunciar( t.restaurando );

		fetch( dados.rota + '?action=' + encodeURIComponent( dados.acao ), {
			method: 'POST',
			body: corpo,
			credentials: 'same-origin'
		} ).then( function ( resposta ) {
			return resposta.json().catch( function () {
				return { success: false, data: { mensagem: t.erroResposta } };
			} );
		}, function () {
			return { success: false, data: { mensagem: t.erroRede } };
		} ).then( function ( json ) {
			var resultado = json && json.data;

			if ( json && json.success && resultado && resultado.url ) {
				anunciar( 'restaurada' === resultado.codigo ? t.restaurada : t.semAlteracao );
				window.location.assign( resultado.url );
				return;
			}

			botao.removeAttribute( 'aria-disabled' );
			anunciar( '' );
			alertar( ( resultado && resultado.mensagem ) || t.erroResposta );
		} );
	} );

	botao.hidden = false;
}() );
