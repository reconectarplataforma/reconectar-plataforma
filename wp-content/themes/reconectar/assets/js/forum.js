/**
 * Campo de tags do formulário de pergunta: `input[data-role="tagsinput"]`.
 *
 * O `data-role` é o do `bootstrap-tagsinput`, e o comportamento também — vírgula
 * ou Enter fecham uma etiqueta, Backspace com o campo vazio apaga a última, o
 * "×" remove uma qualquer. A biblioteca em si ficou de fora: é plugin de jQuery
 * para Bootstrap 3, e o tema não carrega nenhum dos dois.
 *
 * Melhoria progressiva: sem este arquivo o campo é o texto separado por vírgula
 * do bbPress, e o envio funciona igual. Com ele, o campo original vira oculto e
 * continua levando o mesmo `name` com a mesma string — o handler do bbPress não
 * sabe que as etiquetas existiram.
 *
 * @package reconectar
 */

( function () {
	'use strict';

	/**
	 * Lê os textos que o PHP imprimiu no atributo.
	 *
	 * @param {HTMLInputElement} original Campo do bbPress.
	 * @return {Object} Textos por chave, com `%s` no lugar da tag.
	 */
	function lerTextos( original ) {
		var padrao = {
			remover: 'Remover a tag %s',
			adicionada: 'Tag %s adicionada.',
			removida: 'Tag %s removida.',
			repetida: 'A tag %s já está na lista.',
		};

		try {
			var lidos = JSON.parse( original.getAttribute( 'data-rc-tags-textos' ) || '{}' );

			Object.keys( padrao ).forEach( function ( chave ) {
				if ( lidos[ chave ] ) {
					padrao[ chave ] = lidos[ chave ];
				}
			} );
		} catch ( erro ) {
			// JSON malformado não derruba o campo: ficam os textos de reserva.
		}

		return padrao;
	}

	/**
	 * Quebra uma string separada por vírgula em tags limpas.
	 *
	 * @param {string} texto Texto digitado ou colado.
	 * @return {string[]} Tags sem espaço nas pontas e sem vazias.
	 */
	function separar( texto ) {
		return texto.split( ',' ).map( function ( parte ) {
			return parte.replace( /\s+/g, ' ' ).trim();
		} ).filter( Boolean );
	}

	/**
	 * Transforma um campo de texto em campo de etiquetas.
	 *
	 * @param {HTMLInputElement} original Campo do bbPress.
	 */
	function montar( original ) {
		var textos = lerTextos( original );
		var tags = separar( original.value );

		var caixa = document.createElement( 'div' );
		var lista = document.createElement( 'ul' );
		var digitacao = document.createElement( 'input' );
		var aviso = document.createElement( 'span' );

		caixa.className = 'rc-tags';
		lista.className = 'rc-tags__lista';
		digitacao.className = 'rc-tags__digitacao';
		aviso.className = 'screen-reader-text';

		/*
		 * O campo visível herda `id` e `aria-describedby` do original, e é isso
		 * que mantém o `<label for>` e a ajuda apontando para onde o foco está. O
		 * oculto fica só com o `name`: um `id` repetido quebraria os dois.
		 */
		digitacao.type = 'text';
		digitacao.id = original.id;
		digitacao.autocomplete = 'off';
		digitacao.setAttribute( 'aria-describedby', original.getAttribute( 'aria-describedby' ) || '' );

		if ( original.getAttribute( 'data-rc-tags-sugestoes' ) ) {
			digitacao.setAttribute( 'list', original.getAttribute( 'data-rc-tags-sugestoes' ) );
		}

		aviso.setAttribute( 'aria-live', 'polite' );

		original.removeAttribute( 'id' );
		original.type = 'hidden';

		/**
		 * Anuncia uma mudança ao leitor de tela.
		 *
		 * A etiqueta nova aparece fora do campo em que o foco está, e sem o anúncio
		 * quem não vê a tela não sabe se a vírgula fez alguma coisa.
		 *
		 * @param {string} modelo Texto com `%s`.
		 * @param {string} tag    Tag envolvida.
		 */
		function anunciar( modelo, tag ) {
			aviso.textContent = modelo.replace( '%s', tag );
		}

		/** Grava as tags no campo que vai no POST. */
		function sincronizar() {
			original.value = tags.join( ', ' );
		}

		/** Redesenha as etiquetas a partir da lista. */
		function desenhar() {
			lista.textContent = '';

			tags.forEach( function ( tag, indice ) {
				var item = document.createElement( 'li' );
				var nome = document.createElement( 'span' );
				var remover = document.createElement( 'button' );

				item.className = 'rc-tags__item';
				nome.textContent = tag;
				remover.type = 'button';
				remover.className = 'rc-tags__remover';
				remover.setAttribute( 'aria-label', textos.remover.replace( '%s', tag ) );
				remover.textContent = '×';

				remover.addEventListener( 'click', function () {
					tags.splice( indice, 1 );
					sincronizar();
					desenhar();
					anunciar( textos.removida, tag );
					// O botão clicado deixou de existir: o foco volta ao campo, e não ao <body>.
					digitacao.focus();
				} );

				item.appendChild( nome );
				item.appendChild( remover );
				lista.appendChild( item );
			} );
		}

		/**
		 * Fecha em etiquetas o que estiver digitado.
		 *
		 * A comparação ignora caixa: "Frete" e "frete" viram o mesmo termo no
		 * bbPress, e duas etiquetas iguais na tela fariam parecer que são duas.
		 */
		function confirmar() {
			/*
			 * Sem texto, nada a redesenhar — e redesenhar seria defeito: clicar no
			 * "×" tira o foco do campo antes do clique, o `blur` chega aqui, e um
			 * redesenho trocaria o botão por outro antes de o `click` alcançá-lo.
			 */
			if ( ! digitacao.value.trim() ) {
				digitacao.value = '';
				return;
			}

			separar( digitacao.value ).forEach( function ( tag ) {
				var repetida = tags.some( function ( existente ) {
					return existente.toLowerCase() === tag.toLowerCase();
				} );

				if ( repetida ) {
					anunciar( textos.repetida, tag );
					return;
				}

				tags.push( tag );
				anunciar( textos.adicionada, tag );
			} );

			digitacao.value = '';
			sincronizar();
			desenhar();
		}

		digitacao.addEventListener( 'keydown', function ( evento ) {
			if ( ',' === evento.key || ( 'Enter' === evento.key && digitacao.value.trim() ) ) {
				// Enter com texto fecha a etiqueta; sem texto, segue enviando o formulário.
				evento.preventDefault();
				confirmar();
				return;
			}

			if ( 'Backspace' === evento.key && '' === digitacao.value && tags.length ) {
				anunciar( textos.removida, tags.pop() );
				sincronizar();
				desenhar();
			}
		} );

		/*
		 * Colar "frete, embalagem" e escolher uma sugestão do <datalist> não passam
		 * pela vírgula digitada. A sugestão escolhida chega como
		 * `insertReplacementText` no Chrome e sem `inputType` no Firefox — é o que a
		 * distingue de uma tecla.
		 */
		digitacao.addEventListener( 'input', function ( evento ) {
			if ( -1 !== digitacao.value.indexOf( ',' ) || ! evento.inputType || 'insertReplacementText' === evento.inputType ) {
				confirmar();
			}
		} );

		// Quem digita a última tag e sai do campo espera vê-la contada.
		digitacao.addEventListener( 'blur', confirmar );

		if ( original.form ) {
			original.form.addEventListener( 'submit', confirmar );
		}

		// A caixa parece um campo só; o clique no espaço vazio dela tem de focar.
		caixa.addEventListener( 'click', function ( evento ) {
			if ( evento.target === caixa || evento.target === lista ) {
				digitacao.focus();
			}
		} );

		caixa.appendChild( lista );
		caixa.appendChild( digitacao );
		caixa.appendChild( aviso );
		original.parentNode.insertBefore( caixa, original );

		sincronizar();
		desenhar();
	}

	document.querySelectorAll( 'input[data-role="tagsinput"]' ).forEach( function ( campo ) {
		if ( ! campo.disabled ) {
			montar( campo );
		}
	} );
} )();
