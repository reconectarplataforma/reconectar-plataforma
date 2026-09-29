/**
 * Comportamento do componente flutuante de enquete.
 *
 * O `<details>` já abre e fecha sozinho, e o navegador já expõe o estado à
 * tecnologia assistiva — sem este arquivo o componente continua inteiro. O que
 * ele acrescenta é só o que o HTML não faz:
 *
 * - memória da sessão, para o cartão não reabrir a cada navegação (o componente
 *   aparece em *todas* as telas, e reabrir sempre viraria perseguição);
 * - a tecla Esc, que é o que o usuário tenta antes de procurar o botão;
 * - o × que dispensa o cartão pelo resto da visita.
 *
 * Recolher e dispensar são estados diferentes, e por isso são duas chaves. O
 * cartão recolhido continua na tela como pílula, esperando um clique; o cartão
 * dispensado sai inteiro. Uma chave só faria o × equivaler ao `<summary>`, que é
 * justamente o que o usuário já tinha e pediu para complementar.
 */
( function () {
	'use strict';

	var CHAVE = 'rc-enquete-fechada';
	var CHAVE_DISPENSA = 'rc-enquete-dispensada';
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
	var enquetes = cartao.getAttribute( 'data-rc-enquete' ) || '';
	var assinatura = CHAVE + ':' + enquetes;
	var assinaturaDispensa = CHAVE_DISPENSA + ':' + enquetes;

	/**
	 * Lê uma marca desta sessão.
	 *
	 * `sessionStorage` lança em navegação privada de alguns navegadores e sob
	 * bloqueio de armazenamento de terceiros; sem o `try`, o componente pararia de
	 * abrir por causa de uma preferência que não é sobre ele.
	 *
	 * @param {string} chave Assinatura completa da marca.
	 * @return {boolean} Se a marca está gravada.
	 */
	function marcado( chave ) {
		try {
			return '1' === window.sessionStorage.getItem( chave );
		} catch ( erro ) {
			return false;
		}
	}

	/**
	 * Guarda ou apaga uma marca desta sessão.
	 *
	 * @param {string}  chave Assinatura completa da marca.
	 * @param {boolean} ligar Se a marca deve existir.
	 * @return {void}
	 */
	function marcar( chave, ligar ) {
		try {
			if ( ligar ) {
				window.sessionStorage.setItem( chave, '1' );
			} else {
				window.sessionStorage.removeItem( chave );
			}
		} catch ( erro ) {
			// Sem armazenamento, o cartão simplesmente esquece entre as páginas.
		}
	}

	/*
	 * A dispensa é conferida antes de qualquer outra coisa: quem fechou o cartão
	 * não deve vê-lo reaparecer, nem por um quadro, na página seguinte. O `hidden`
	 * é o mesmo estado que o × produz, e por isso ele nunca depende de o resto
	 * deste arquivo ter rodado.
	 */
	if ( marcado( assinaturaDispensa ) ) {
		cartao.hidden = true;
		return;
	}

	var fechar = cartao.querySelector( '[data-rc-enquete-fechar]' );

	if ( fechar ) {
		fechar.addEventListener( 'click', function () {
			cartao.open = false;
			cartao.hidden = true;
			marcar( assinaturaDispensa, true );

			/*
			 * Sem JS o botão não existe — ele é impresso no HTML, mas fechar é a
			 * única coisa que ele faz, e quem chega aqui já tem script. O foco cai no
			 * `<body>` quando o elemento focado some; forçá-lo para outro ponto da
			 * página moveria a leitura de quem só quis dispensar um aviso.
			 */
		} );
	}

	/*
	 * O cartão nasce fechado no HTML — é o estado seguro, e o único que não
	 * depende de script. No desktop ele se apresenta aberto na primeira visita da
	 * sessão; no celular fica sempre como pílula, porque ali o painel aberto cobre
	 * a coluna de leitura inteira.
	 */
	if ( window.innerWidth >= LARGURA_DESKTOP && ! marcado( assinatura ) ) {
		cartao.open = true;
	}

	cartao.addEventListener( 'toggle', function () {
		marcar( assinatura, ! cartao.open );
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
