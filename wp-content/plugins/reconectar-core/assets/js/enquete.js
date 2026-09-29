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
 *
 * **Não há botão de trazer o cartão de volta, e isso é deliberado.** Quem clicou
 * no × disse "some"; devolvê-lo na mesma visita é o que a dispensa existe para
 * evitar. O caminho até a enquete não se perde junto: o atalho "Enquetes" do
 * cabeçalho e o item "Votar" da barra inferior seguem na tela, com o selo de
 * pendência, e levam ao painel de transparência — onde moram a cédula completa e
 * o gráfico, que o cartão nunca teve. O cartão volta sozinho em sessão nova ou
 * quando uma enquete nova entra em cartaz, pela assinatura logo abaixo.
 */
( function () {
	'use strict';

	var CHAVE = 'rc-enquete-fechada';
	var CHAVE_DISPENSA = 'rc-enquete-dispensada';
	var LARGURA_DESKTOP = 768;

	/*
	 * Dois elementos, e não um: `cartao` é o wrapper — quem sai da tela na
	 * dispensa e quem ancora o × —, e `caixa` é o `<details>`, quem abre e fecha.
	 * Confundir os dois devolve `undefined` em `cartao.open`, que é falso e não
	 * lança: o cartão simplesmente deixaria de abrir, sem erro no console.
	 */
	var cartao = document.querySelector( '[data-rc-enquete]' );
	var caixa = cartao && cartao.querySelector( 'details' );

	if ( ! cartao || ! caixa ) {
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
			caixa.open = false;
			cartao.hidden = true;
			marcar( assinaturaDispensa, true );

			/*
			 * O foco cai no `<body>` quando o elemento focado some; forçá-lo para
			 * outro ponto da página moveria a leitura de quem só quis dispensar um
			 * aviso.
			 */
		} );
	}

	/*
	 * O cartão nasce fechado no HTML — é o estado seguro, e o único que não
	 * depende de script. No desktop ele se apresenta aberto na primeira visita da
	 * sessão; abaixo de 768px o componente inteiro está fora da tela por CSS, e
	 * quem anuncia a enquete ali é a barra inferior.
	 */
	if ( window.innerWidth >= LARGURA_DESKTOP && ! marcado( assinatura ) ) {
		caixa.open = true;
	}

	/*
	 * O × acompanha o painel: ele flutua sobre a faixa do gatilho, e sobre a
	 * pílula fechada seria um segundo alvo espremido ao lado do primeiro. Nasce
	 * `hidden` no HTML, então esta linha é também o que o torna visível pela
	 * primeira vez — sem script ele não fazia nada e mesmo assim era impresso.
	 */
	function sincronizarFechar() {
		if ( fechar ) {
			fechar.hidden = ! caixa.open;
		}
	}

	sincronizarFechar();

	caixa.addEventListener( 'toggle', function () {
		marcar( assinatura, ! caixa.open );
		sincronizarFechar();
	} );

	document.addEventListener( 'keydown', function ( evento ) {
		if ( 'Escape' !== evento.key || ! caixa.open ) {
			return;
		}

		caixa.open = false;

		/*
		 * O foco volta para o gatilho: quem fechou pelo teclado estava dentro do
		 * painel, e sem isso o foco cairia no início do documento.
		 */
		var gatilho = caixa.querySelector( 'summary' );

		if ( gatilho ) {
			gatilho.focus();
		}
	} );
}() );
