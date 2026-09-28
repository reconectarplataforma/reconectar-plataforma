/**
 * Sugestões da busca do cabeçalho.
 *
 * Melhoria progressiva: o formulário funciona sem este arquivo — Enter e clique
 * na lupa levam à página de resultados como sempre levaram. O que se ganha aqui
 * é o atalho, e o fato de que uma loja passa a ser encontrável pelo nome.
 *
 * O padrão seguido é o combobox da WAI-ARIA 1.2: o foco nunca sai do campo de
 * texto, e a marcação do item corrente viaja por `aria-activedescendant`. Mover
 * o foco de verdade para o item — que seria o caminho óbvio — quebraria a
 * digitação, porque o campo deixaria de receber as teclas.
 *
 * @package reconectar
 */

( function () {
	'use strict';

	/** Espera antes de consultar, em milissegundos. */
	var ESPERA = 250;

	/** Menor termo que dispara consulta. Igual ao do servidor. */
	var MINIMO = 2;

	/**
	 * Lê os rótulos que o PHP imprimiu no markup.
	 *
	 * Os valores de reserva existem para o caso de o atributo não chegar — um
	 * painel sem rótulo nenhum seria pior que um painel em português.
	 *
	 * @param {HTMLFormElement} formulario Formulário da busca.
	 * @return {Object} Rótulos por chave.
	 */
	function lerTextos( formulario ) {
		var padrao = {
			produtos: 'Produtos',
			lojas: 'Lojas',
			vazio: 'Nada encontrado para “%s”.',
			todos: 'Ver todos os resultados para “%s”',
			nenhuma: 'Nenhuma sugestão.',
			uma: '1 sugestão disponível.',
			varias: '%d sugestões disponíveis.',
		};

		try {
			var lidos = JSON.parse( formulario.getAttribute( 'data-rc-busca-textos' ) || '{}' );

			Object.keys( padrao ).forEach( function ( chave ) {
				if ( lidos[ chave ] ) {
					padrao[ chave ] = lidos[ chave ];
				}
			} );
		} catch ( erro ) {
			// JSON malformado não derruba a busca: ficam os rótulos de reserva.
		}

		return padrao;
	}

	/**
	 * Liga um formulário de busca às suas sugestões.
	 *
	 * @param {HTMLFormElement} formulario Formulário com `data-rc-busca`.
	 * @return {void}
	 */
	function ligarBusca( formulario ) {
		var campo = formulario.querySelector( '[data-rc-busca-campo]' );
		var lista = formulario.querySelector( '[data-rc-busca-lista]' );
		var aviso = formulario.querySelector( '[data-rc-busca-aviso]' );
		var endereco = formulario.getAttribute( 'data-rc-busca-sugestoes' );

		if ( ! campo || ! lista || ! endereco ) {
			return;
		}

		var opcoes = [];
		var marcado = -1;
		var temporizador = null;
		var requisicao = null;
		var textos = lerTextos( formulario );

		/**
		 * Preenche o `%s` ou `%d` de um rótulo vindo do PHP.
		 *
		 * @param {string} molde Rótulo com um marcador.
		 * @param {string} valor Valor a inserir.
		 * @return {string} Texto pronto.
		 */
		function compor( molde, valor ) {
			return molde.replace( /%[sd]/, valor );
		}

		/**
		 * Situação corrente do painel para leitor de tela e para o CSS.
		 *
		 * @param {boolean} aberto Painel visível?
		 * @return {void}
		 */
		function definirAbertura( aberto ) {
			lista.hidden = ! aberto;
			campo.setAttribute( 'aria-expanded', aberto ? 'true' : 'false' );

			if ( ! aberto ) {
				marcar( -1 );
			}
		}

		/**
		 * Marca uma opção, sem tirar o foco do campo.
		 *
		 * @param {number} indice Posição na lista, ou -1 para nenhuma.
		 * @return {void}
		 */
		function marcar( indice ) {
			if ( opcoes[ marcado ] ) {
				opcoes[ marcado ].setAttribute( 'aria-selected', 'false' );
			}

			marcado = indice;

			if ( ! opcoes[ marcado ] ) {
				campo.removeAttribute( 'aria-activedescendant' );
				return;
			}

			opcoes[ marcado ].setAttribute( 'aria-selected', 'true' );
			campo.setAttribute( 'aria-activedescendant', opcoes[ marcado ].id );

			// A lista rola; sem isto, percorrer com a seta acabaria marcando um
			// item fora da área visível.
			if ( opcoes[ marcado ].scrollIntoView ) {
				opcoes[ marcado ].scrollIntoView( { block: 'nearest' } );
			}
		}

		/**
		 * Fecha o painel e descarta o que estiver em voo.
		 *
		 * @return {void}
		 */
		function fechar() {
			if ( temporizador ) {
				window.clearTimeout( temporizador );
				temporizador = null;
			}

			if ( requisicao ) {
				requisicao.abort();
				requisicao = null;
			}

			definirAbertura( false );
		}

		/**
		 * Cria o cabeçalho de uma seção do painel.
		 *
		 * `role="presentation"` porque um `<li>` dentro de `role="listbox"` que
		 * não seja opção precisa sair da árvore de acessibilidade: se ficasse,
		 * o leitor de tela o contaria como sugestão selecionável.
		 *
		 * @param {string} texto Título da seção.
		 * @return {HTMLLIElement} Item pronto.
		 */
		function criarTitulo( texto ) {
			var item = document.createElement( 'li' );

			item.className = 'rc-busca__titulo';
			item.setAttribute( 'role', 'presentation' );
			item.textContent = texto;

			return item;
		}

		/**
		 * Cria uma opção do painel.
		 *
		 * Tudo entra por `textContent`: nome de produto e nome de loja são texto
		 * de terceiro, e montar o item com `innerHTML` abriria uma injeção pelo
		 * cadastro do vendedor.
		 *
		 * @param {Object} dados        Campos da opção.
		 * @param {string} dados.url    Destino do item.
		 * @param {string} dados.titulo Linha principal.
		 * @param {string} dados.apoio  Linha secundária, opcional.
		 * @param {string} dados.imagem Miniatura, opcional.
		 * @return {HTMLLIElement} Item pronto.
		 */
		function criarOpcao( dados ) {
			var item = document.createElement( 'li' );

			item.className = 'rc-busca__opcao';
			item.id = 'rc-busca-opcao-' + opcoes.length;
			item.setAttribute( 'role', 'option' );
			item.setAttribute( 'aria-selected', 'false' );
			item.setAttribute( 'data-rc-url', dados.url );

			var figura = document.createElement( 'span' );

			figura.className = 'rc-busca__miniatura';

			if ( dados.imagem ) {
				var imagem = document.createElement( 'img' );

				imagem.src = dados.imagem;
				imagem.alt = '';
				imagem.loading = 'lazy';
				figura.appendChild( imagem );
			}

			var texto = document.createElement( 'span' );

			texto.className = 'rc-busca__texto';

			var titulo = document.createElement( 'span' );

			titulo.className = 'rc-busca__nome';
			titulo.textContent = dados.titulo;
			texto.appendChild( titulo );

			// Campo vazio fica vazio: preço ou cidade que não vieram do banco
			// não viram traço nem "não informado" inventado aqui.
			if ( dados.apoio ) {
				var apoio = document.createElement( 'span' );

				apoio.className = 'rc-busca__apoio';
				apoio.textContent = dados.apoio;
				texto.appendChild( apoio );
			}

			item.appendChild( figura );
			item.appendChild( texto );

			// `mousedown`, e não `click`: o `blur` do campo chega antes do clique
			// e fecharia o painel, deixando o clique cair no vazio.
			item.addEventListener( 'mousedown', function ( evento ) {
				evento.preventDefault();
				window.location.href = dados.url;
			} );

			opcoes.push( item );

			return item;
		}

		/**
		 * Junta produtos, lojas e o atalho final em uma lista de linhas.
		 *
		 * @param {Object} dados Resposta do endpoint.
		 * @param {string} termo Termo consultado.
		 * @return {void}
		 */
		function desenhar( dados, termo ) {
			lista.textContent = '';
			opcoes = [];
			marcado = -1;

			var produtos = dados.produtos || [];
			var lojas = dados.lojas || [];

			if ( produtos.length ) {
				lista.appendChild( criarTitulo( textos.produtos ) );

				produtos.forEach( function ( produto ) {
					lista.appendChild(
						criarOpcao( {
							url: produto.url,
							titulo: produto.titulo,
							apoio: [ produto.preco, produto.loja ]
								.filter( Boolean )
								.join( ' · ' ),
							imagem: produto.imagem,
						} )
					);
				} );
			}

			if ( lojas.length ) {
				lista.appendChild( criarTitulo( textos.lojas ) );

				lojas.forEach( function ( loja ) {
					lista.appendChild(
						criarOpcao( {
							url: loja.url,
							titulo: loja.nome,
							apoio: loja.cidade,
							imagem: loja.imagem,
						} )
					);
				} );
			}

			if ( ! opcoes.length ) {
				var vazio = document.createElement( 'li' );

				vazio.className = 'rc-busca__vazio';
				vazio.setAttribute( 'role', 'presentation' );
				vazio.textContent = compor( textos.vazio, termo );
				lista.appendChild( vazio );

				anunciar( textos.nenhuma );
				definirAbertura( true );
				return;
			}

			// O atalho para a página completa é a saída de quem não viu o que
			// queria entre as seis primeiras linhas.
			var quantidade = opcoes.length;

			lista.appendChild(
				criarOpcao( {
					url: dados.url_todos,
					titulo: compor( textos.todos, termo ),
					apoio: '',
					imagem: '',
				} )
			);

			opcoes[ opcoes.length - 1 ].classList.add( 'rc-busca__opcao--todos' );

			anunciar(
				1 === quantidade ? textos.uma : compor( textos.varias, quantidade )
			);

			definirAbertura( true );
		}

		/**
		 * Publica um texto na região viva.
		 *
		 * @param {string} texto Mensagem.
		 * @return {void}
		 */
		function anunciar( texto ) {
			if ( aviso ) {
				aviso.textContent = texto;
			}
		}

		/**
		 * Consulta o endpoint e desenha o resultado.
		 *
		 * @param {string} termo Termo digitado.
		 * @return {void}
		 */
		function consultar( termo ) {
			// Cada tecla cancela a requisição anterior. Sem isso, a resposta de
			// "me" pode chegar depois da de "mel" e repintar o painel com o
			// conteúdo antigo — um piscar que parece bug de digitação.
			if ( requisicao ) {
				requisicao.abort();
			}

			requisicao = new window.AbortController();

			var url = endereco + ( endereco.indexOf( '?' ) === -1 ? '?' : '&' ) +
				'termo=' + encodeURIComponent( termo );

			window
				.fetch( url, {
					signal: requisicao.signal,
					headers: { Accept: 'application/json' },
				} )
				.then( function ( resposta ) {
					if ( ! resposta.ok ) {
						throw new Error( 'resposta ' + resposta.status );
					}

					return resposta.json();
				} )
				.then( function ( dados ) {
					if ( campo.value.trim() === termo ) {
						desenhar( dados, termo );
					}
				} )
				.catch( function () {
					// Endpoint fora do ar, rede caída ou requisição abortada: o
					// painel some e o formulário continua submetendo. Um erro de
					// sugestão não pode impedir a busca de verdade.
					definirAbertura( false );
				} );
		}

		campo.addEventListener( 'input', function () {
			var termo = campo.value.trim();

			if ( temporizador ) {
				window.clearTimeout( temporizador );
			}

			if ( termo.length < MINIMO ) {
				fechar();
				return;
			}

			temporizador = window.setTimeout( function () {
				consultar( termo );
			}, ESPERA );
		} );

		campo.addEventListener( 'keydown', function ( evento ) {
			var aberto = ! lista.hidden;

			if ( 'Escape' === evento.key ) {
				fechar();
				return;
			}

			if ( 'Enter' === evento.key ) {
				// Sem item marcado, Enter é o Enter de sempre: submete o
				// formulário e vai para a página de resultados.
				if ( aberto && opcoes[ marcado ] ) {
					evento.preventDefault();
					window.location.href = opcoes[ marcado ].getAttribute( 'data-rc-url' );
				}

				return;
			}

			if ( 'Tab' === evento.key ) {
				definirAbertura( false );
				return;
			}

			if ( ! aberto || ! opcoes.length ) {
				return;
			}

			if ( 'ArrowDown' === evento.key ) {
				evento.preventDefault();
				marcar( marcado + 1 >= opcoes.length ? 0 : marcado + 1 );
			} else if ( 'ArrowUp' === evento.key ) {
				evento.preventDefault();
				marcar( marcado <= 0 ? opcoes.length - 1 : marcado - 1 );
			} else if ( 'Home' === evento.key ) {
				evento.preventDefault();
				marcar( 0 );
			} else if ( 'End' === evento.key ) {
				evento.preventDefault();
				marcar( opcoes.length - 1 );
			}
		} );

		campo.addEventListener( 'focus', function () {
			if ( opcoes.length && campo.value.trim().length >= MINIMO ) {
				definirAbertura( true );
			}
		} );

		document.addEventListener( 'click', function ( evento ) {
			if ( ! formulario.contains( evento.target ) ) {
				definirAbertura( false );
			}
		} );
	}

	/**
	 * Liga todos os formulários de busca da página.
	 *
	 * @return {void}
	 */
	function iniciar() {
		// `fetch` e `AbortController` são a linha de corte: onde não existirem,
		// nada é ligado e o formulário segue funcionando sozinho.
		if ( ! window.fetch || ! window.AbortController ) {
			return;
		}

		var formularios = document.querySelectorAll( '[data-rc-busca]' );

		Array.prototype.forEach.call( formularios, ligarBusca );
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', iniciar );
	} else {
		iniciar();
	}
} )();
