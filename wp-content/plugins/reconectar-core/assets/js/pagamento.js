/**
 * Copiar o PIX copia-e-cola da tela de agradecimento.
 *
 * O código também fica visível em texto: quem não tiver JavaScript, ou estiver
 * num navegador que recuse a área de transferência, seleciona e copia à mão. O
 * botão é melhoria progressiva, não o único caminho.
 *
 * Sem build e sem dependência — o arquivo é carregado como está.
 */
( function () {
	'use strict';

	var textos = window.reconectarPagamento || {};

	/**
	 * Copia um texto usando a API moderna, com recuo para o comando antigo.
	 *
	 * `navigator.clipboard` só existe em contexto seguro. A plataforma roda em
	 * `http://` durante o desenvolvimento em rede local, onde o IP da máquina
	 * não é contexto seguro e a API simplesmente não está definida — daí o
	 * recuo, que não é zelo com navegador velho.
	 *
	 * @param {string} texto Conteúdo a copiar.
	 * @return {Promise<void>} Resolvida quando o texto foi copiado.
	 */
	function copiar( texto ) {
		if ( navigator.clipboard && window.isSecureContext ) {
			return navigator.clipboard.writeText( texto );
		}

		return new Promise( function ( resolver, rejeitar ) {
			var campo = document.createElement( 'textarea' );

			campo.value = texto;
			campo.setAttribute( 'readonly', 'readonly' );
			// Fora da tela sem `display: none`: o elemento precisa ser
			// selecionável para o comando de cópia enxergá-lo.
			campo.style.position = 'fixed';
			campo.style.top = '-9999px';

			document.body.appendChild( campo );
			campo.select();

			try {
				if ( document.execCommand( 'copy' ) ) {
					resolver();
				} else {
					rejeitar();
				}
			} catch ( erro ) {
				rejeitar( erro );
			} finally {
				document.body.removeChild( campo );
			}
		} );
	}

	/**
	 * Devolve o aviso vizinho ao botão, criando-o na primeira vez.
	 *
	 * O aviso é `aria-live`: sem ele, o retorno de "copiado" seria só visual e
	 * quem usa leitor de tela não saberia se a ação funcionou.
	 *
	 * @param {HTMLElement} botao Botão que disparou a cópia.
	 * @return {HTMLElement} Elemento de aviso.
	 */
	function avisoDe( botao ) {
		var aviso = botao.nextElementSibling;

		if ( aviso && aviso.classList.contains( 'rc-pagamento__retorno' ) ) {
			return aviso;
		}

		aviso = document.createElement( 'p' );
		aviso.className = 'rc-pagamento__retorno';
		aviso.setAttribute( 'role', 'status' );
		aviso.setAttribute( 'aria-live', 'polite' );
		botao.parentNode.insertBefore( aviso, botao.nextSibling );

		return aviso;
	}

	document.addEventListener( 'click', function ( evento ) {
		var botao = evento.target.closest( '.rc-pagamento__copiar' );

		if ( ! botao ) {
			return;
		}

		var codigo = botao.getAttribute( 'data-rc-copiar' );

		if ( ! codigo ) {
			return;
		}

		copiar( codigo ).then(
			function () {
				avisoDe( botao ).textContent = textos.copiado || '';
			},
			function () {
				avisoDe( botao ).textContent = textos.falhou || '';
			}
		);
	} );
}() );
