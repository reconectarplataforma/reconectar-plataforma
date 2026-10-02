/**
 * Tela de leitura da Incubadora.
 *
 * Quatro coisas, independentes:
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
 *
 * 3. "Copiar link", com o endereço por ID, que sobrevive a mover a página.
 *
 * 4. O "Editado há N minutos", refeito a cada meio minuto. O editor avisa por
 *    `rc-incubadora:atualizada` quando grava, e o relógio recomeça da hora
 *    nova que o servidor devolveu.
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

	/**
	 * Revela os botões cujo destino passa na conferência.
	 *
	 * Roda de novo a cada `rc-incubadora:atualizada`: ao fechar, o editor
	 * devolve ao corpo o HTML de leitura, com facades novas e botões `hidden`.
	 */
	function prepararBotoes() {
		var botoes = document.querySelectorAll( '.rc-incubadora__conteudo .rc-video__carregar[hidden]' );

		Array.prototype.forEach.call( botoes, function ( botao ) {
			if ( playerAceito( botao.getAttribute( 'data-rc-src' ) || '' ) ) {
				botao.hidden = false;
			}
		} );
	}

	// Delegado, e não um ouvinte por botão, pelo mesmo motivo de
	// `prepararBotoes()`: os botões são trocados sem recarregar a página.
	document.addEventListener( 'click', function ( evento ) {
		var botao = evento.target.closest && evento.target.closest( '.rc-incubadora__conteudo .rc-video__carregar' );

		if ( botao && ! botao.hidden && ! botao.closest( '[contenteditable="true"]' ) ) {
			carregar( botao );
		}
	} );

	document.addEventListener( 'rc-incubadora:atualizada', prepararBotoes );
	prepararBotoes();
}() );

( function () {
	'use strict';

	var botao = document.querySelector( '[data-rc-incubadora="compartilhar"]' );
	var status = document.querySelector( '.rc-incubadora__status' );

	if ( ! botao || ! status ) {
		return;
	}

	/**
	 * Copia por um campo temporário, para quando a API de área de
	 * transferência não existe.
	 *
	 * A API só existe em contexto seguro: `localhost` é, mas o IP da rede
	 * local por `http://` não é — e é por ele que a plataforma é aberta no
	 * celular durante o desenvolvimento. `execCommand` está obsoleto e ainda
	 * é o único caminho ali.
	 *
	 * @param {string} texto Texto a copiar.
	 * @return {boolean} Se o navegador disse que copiou.
	 */
	function copiarPorCampo( texto ) {
		var campo = document.createElement( 'textarea' );
		var copiou = false;

		campo.value = texto;
		campo.setAttribute( 'readonly', '' );
		campo.style.position = 'fixed';
		campo.style.opacity = '0';
		document.body.appendChild( campo );
		campo.select();

		try {
			copiou = document.execCommand( 'copy' );
		} catch ( erro ) {
			copiou = false;
		}

		campo.remove();
		botao.focus();

		return copiou;
	}

	/**
	 * Anuncia o resultado na região de status.
	 *
	 * Em caso de falha o link vai escrito por extenso: quem não conseguiu
	 * copiar ainda consegue selecionar.
	 *
	 * @param {boolean} copiou Se a cópia deu certo.
	 * @param {string}  link   Endereço completo.
	 */
	function anunciar( copiou, link ) {
		status.textContent = copiou ?
			botao.getAttribute( 'data-rc-copiado' ) :
			botao.getAttribute( 'data-rc-falhou' ) + ' ' + link;
	}

	botao.hidden = false;
	botao.addEventListener( 'click', function () {
		var link = window.location.origin + botao.getAttribute( 'data-rc-link' );

		if ( navigator.clipboard && window.isSecureContext ) {
			navigator.clipboard.writeText( link ).then(
				function () {
					anunciar( true, link );
				},
				function () {
					anunciar( copiarPorCampo( link ), link );
				}
			);
			return;
		}

		anunciar( copiarPorCampo( link ), link );
	} );
}() );

( function () {
	'use strict';

	var pagina = document.querySelector( '.rc-incubadora__pagina[data-rc-agora]' );
	var tempo = pagina && pagina.querySelector( '.rc-incubadora__editado' );

	if ( ! tempo || 'function' !== typeof Intl.RelativeTimeFormat ) {
		return;
	}

	var formato = new Intl.RelativeTimeFormat( document.documentElement.lang || 'pt-BR', { numeric: 'auto' } );
	var modelo = tempo.getAttribute( 'data-rc-modelo' ) || '%s';
	var desvio = 0;

	/**
	 * Diferença entre o relógio do servidor e o deste navegador, em ms.
	 *
	 * @param {string} agora Hora do servidor, em ISO 8601.
	 */
	function acertar( agora ) {
		var servidor = Date.parse( agora );

		desvio = isNaN( servidor ) ? 0 : servidor - Date.now();
	}

	/**
	 * Intervalo em palavras, nos mesmos degraus do `human_time_diff()`.
	 *
	 * @param {number} segundos Segundos desde a edição.
	 * @return {string} Como "há 5 minutos".
	 */
	function relativo( segundos ) {
		var degraus = [
			[ 60, 1, 'second' ],
			[ 3600, 60, 'minute' ],
			[ 86400, 3600, 'hour' ],
			[ 604800, 86400, 'day' ],
			[ 2592000, 604800, 'week' ],
			[ 31536000, 2592000, 'month' ]
		];

		if ( segundos < 45 ) {
			return formato.format( 0, 'second' );
		}

		for ( var i = 0; i < degraus.length; i++ ) {
			if ( segundos < degraus[ i ][ 0 ] ) {
				return formato.format( -Math.max( 1, Math.round( segundos / degraus[ i ][ 1 ] ) ), degraus[ i ][ 2 ] );
			}
		}

		return formato.format( -Math.max( 1, Math.round( segundos / 31536000 ) ), 'year' );
	}

	/**
	 * Reescreve o texto a partir do `datetime` corrente do elemento.
	 */
	function desenhar() {
		var editado = Date.parse( tempo.getAttribute( 'datetime' ) );

		if ( isNaN( editado ) ) {
			return;
		}

		tempo.textContent = modelo.replace( '%s', relativo( Math.max( 0, ( Date.now() + desvio - editado ) / 1000 ) ) );
	}

	acertar( pagina.getAttribute( 'data-rc-agora' ) );
	desenhar();

	// Fora de região viva de propósito: um anúncio a cada 30 segundos
	// tornaria a página inutilizável no leitor de tela.
	window.setInterval( function () {
		if ( ! document.hidden ) {
			desenhar();
		}
	}, 30000 );

	document.addEventListener( 'rc-incubadora:atualizada', function ( evento ) {
		if ( evento.detail && evento.detail.agora ) {
			acertar( evento.detail.agora );
		}
		desenhar();
	} );
}() );
