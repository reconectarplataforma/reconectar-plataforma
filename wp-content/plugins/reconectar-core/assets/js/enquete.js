/**
 * Comportamento do componente flutuante de enquete.
 *
 * O `<details>` já abre e fecha sozinho, e o navegador já expõe o estado à
 * tecnologia assistiva — sem este arquivo o componente continua inteiro. O que
 * ele acrescenta é só o que o HTML não faz:
 *
 * - memória da sessão, para o cartão não reabrir a cada navegação (o componente
 *   aparece em *todas* as telas, e reabrir sempre viraria perseguição);
 * - a tecla Esc, que é o que o usuário tenta antes de procurar o botão.
 */
( function () {
	'use strict';

	var CHAVE = 'rc-enquete-fechada';
	var LARGURA_DESKTOP = 768;

	var cartao = document.querySelector( '[data-rc-enquete]' );

	if ( ! cartao ) {
		return;
	}

	/*
	 * A chave carrega os IDs das enquetes em cartaz. Assim, quando uma enquete
	 * nova é publicada, o conjunto muda, a marca antiga deixa de casar e o cartão
	 * volta a se apresentar — que é o ponto de publicar uma enquete.
	 */
	var assinatura = CHAVE + ':' + ( cartao.getAttribute( 'data-rc-enquete' ) || '' );

	/**
	 * Lê a marca de "fechado por quem navega".
	 *
	 * `sessionStorage` lança em navegação privada de alguns navegadores e sob
	 * bloqueio de armazenamento de terceiros; sem o `try`, o componente pararia de
	 * abrir por causa de uma preferência que não é sobre ele.
	 *
	 * @return {boolean} Se o usuário fechou o cartão nesta sessão.
	 */
	function fechadoPeloUsuario() {
		try {
			return '1' === window.sessionStorage.getItem( assinatura );
		} catch ( erro ) {
			return false;
		}
	}

	/**
	 * Guarda o estado escolhido pelo usuário.
	 *
	 * @param {boolean} fechado Se o cartão ficou fechado.
	 * @return {void}
	 */
	function lembrar( fechado ) {
		try {
			if ( fechado ) {
				window.sessionStorage.setItem( assinatura, '1' );
			} else {
				window.sessionStorage.removeItem( assinatura );
			}
		} catch ( erro ) {
			// Sem armazenamento, o cartão simplesmente esquece entre as páginas.
		}
	}

	/*
	 * O cartão nasce fechado no HTML — é o estado seguro, e o único que não
	 * depende de script. No desktop ele se apresenta aberto na primeira visita da
	 * sessão; no celular fica sempre como pílula, porque ali o painel aberto cobre
	 * a coluna de leitura inteira.
	 */
	if ( window.innerWidth >= LARGURA_DESKTOP && ! fechadoPeloUsuario() ) {
		cartao.open = true;
	}

	cartao.addEventListener( 'toggle', function () {
		lembrar( ! cartao.open );
	} );

	document.addEventListener( 'keydown', function ( evento ) {
		if ( 'Escape' !== evento.key || ! cartao.open ) {
			return;
		}

		cartao.open = false;

		/*
		 * O foco volta para o gatilho: quem fechou pelo teclado estava dentro do
		 * painel, e sem isso o foco cairia no início do documento.
		 */
		var gatilho = cartao.querySelector( 'summary' );

		if ( gatilho ) {
			gatilho.focus();
		}
	} );
}() );
