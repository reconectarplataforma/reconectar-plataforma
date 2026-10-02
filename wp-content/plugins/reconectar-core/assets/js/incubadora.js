/**
 * Tela de leitura da Incubadora.
 *
 * Duas coisas, independentes:
 *
 * 1. Trazer o item da página aberta para dentro da árvore lateral no desktop.
 *    A árvore é `sticky` com rolagem própria, e numa wiki longa a página aberta
 *    pode estar abaixo da altura da janela — a árvore nasceria mostrando o
 *    começo da lista, sem o item destacado à vista.
 *
 *    Sem `scrollIntoView()`: ele rola **todos** os ancestrais roláveis,
 *    inclusive o documento, e a página abriria deslocada para baixo do título.
 *
 * 2. Trocar a facade de vídeo pelo player, só quando a pessoa pedir. O botão
 *    nasce `hidden` no HTML: sem este script, sobra o link para assistir no
 *    provedor, que funciona sozinho.
 */
( function () {
	'use strict';

	var lateral = document.querySelector( '.rc-incubadora__lateral' );
	var ativo = lateral && lateral.querySelector( '[aria-current="page"]' );

	if ( ! ativo || lateral.scrollHeight <= lateral.clientHeight ) {
		return;
	}

	var caixaLateral = lateral.getBoundingClientRect();
	var caixaAtivo = ativo.getBoundingClientRect();

	if ( caixaAtivo.bottom > caixaLateral.bottom ) {
		lateral.scrollTop += caixaAtivo.top - caixaLateral.top - lateral.clientHeight / 3;
	}
}() );

( function () {
	'use strict';

	/*
	 * Segunda conferência do destino, depois da do servidor. O `data-rc-src`
	 * sai de `Reconectar_Incubadora_Conteudo::url_do_player()`, mas este script
	 * é o que transforma texto em iframe: se algum dia chegar aqui um atributo
	 * que não passou pelo sanitizador, ele não vira janela para outro site.
	 * Comparação por host inteiro e por prefixo de caminho — nunca por
	 * `indexOf` na URL, que `https://evil.example/?www.youtube-nocookie.com`
	 * satisfaria.
	 */
	var PLAYERS = {
		'www.youtube-nocookie.com': '/embed/',
		'player.vimeo.com': '/video/'
	};

	/**
	 * Confere a URL do player contra a lista.
	 *
	 * @param {string} endereco URL vinda do `data-rc-src`.
	 * @return {URL|null} A URL, se aceita.
	 */
	function playerAceito( endereco ) {
		var url;

		try {
			url = new URL( endereco );
		} catch ( erro ) {
			return null;
		}

		if ( 'https:' !== url.protocol || '' !== url.username || '' !== url.password || '' !== url.port ) {
			return null;
		}

		if ( ! Object.prototype.hasOwnProperty.call( PLAYERS, url.hostname ) ) {
			return null;
		}

		return 0 === url.pathname.indexOf( PLAYERS[ url.hostname ] ) ? url : null;
	}

	/**
	 * Troca o quadro da facade pelo iframe e leva o foco até ele.
	 *
	 * O foco vai para o iframe porque o botão que o tinha deixa de existir:
	 * sem isso, quem navega por teclado voltaria ao começo do documento.
	 *
	 * @param {HTMLButtonElement} botao Botão "Carregar vídeo".
	 */
	function carregar( botao ) {
		var url = playerAceito( botao.getAttribute( 'data-rc-src' ) || '' );
		var quadro = botao.closest( '.rc-video__quadro' );

		if ( ! url || ! quadro ) {
			return;
		}

		var player = document.createElement( 'iframe' );

		player.className = 'rc-video__player';
		player.src = url.href;
		player.title = botao.getAttribute( 'data-rc-titulo' ) || '';
		player.setAttribute( 'sandbox', 'allow-scripts allow-same-origin allow-presentation allow-popups' );
		player.setAttribute( 'allow', 'fullscreen; picture-in-picture; encrypted-media' );
		player.setAttribute( 'allowfullscreen', '' );
		player.setAttribute( 'referrerpolicy', 'strict-origin-when-cross-origin' );

		quadro.replaceWith( player );
		player.closest( '.rc-video' ).classList.remove( 'rc-video--facade' );
		player.focus();
	}

	var botoes = document.querySelectorAll( '.rc-incubadora__conteudo .rc-video__carregar' );

	Array.prototype.forEach.call( botoes, function ( botao ) {
		if ( ! playerAceito( botao.getAttribute( 'data-rc-src' ) || '' ) ) {
			return;
		}

		botao.hidden = false;
		botao.addEventListener( 'click', function () {
			carregar( botao );
		} );
	} );
}() );
