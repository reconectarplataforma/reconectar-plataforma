/**
 * Árvore interativa da Incubadora: criar página, mover pelo diálogo e
 * arrastar e soltar na árvore lateral.
 *
 * Só chega aqui quem tem a capacidade de escrita — ver
 * `Reconectar_Incubadora_Editor::enfileirar_arvore()`. Os botões nascem
 * `hidden` no HTML e só aparecem com este script de pé.
 *
 * O arrastar é para o mouse; o teclado e o toque têm o botão "Mover…" da
 * página, que faz a mesma coisa por um diálogo com duas listas. O HTML5 drag
 * and drop não dispara em tela de toque na maioria dos navegadores, e não há
 * jeito acessível de arrastar pelo teclado — por isso o diálogo não é
 * alternativa de segunda: é o caminho completo.
 *
 * A estrutura que se manda ao servidor é lida do DOM da árvore, que está
 * inteiro no HTML, fechado ou não. Depois de mover, a árvore e a trilha são
 * trocadas pelo HTML que o servidor devolve, e não remendadas aqui: o mover
 * muda o endereço de toda a subárvore, e refazer cada `href` no cliente seria
 * reimplementar o permalink.
 */
( function () {
	'use strict';

	var dados = window.reconectarIncubadoraArvore;
	var arvore = document.querySelector( '.rc-incubadora__arvore-corpo' );

	if ( ! dados || ! arvore ) {
		return;
	}

	var t = dados.textos;
	var status = document.querySelector( '.rc-incubadora__status' );
	var alerta = document.querySelector( '.rc-incubadora__alerta' );
	var arrastada = null;
	var alvoMarcado = null;
	var dialogo = null;

	/* ---------------------------------------------------------------------
	 * Avisos
	 * ------------------------------------------------------------------ */

	/**
	 * Escreve na região de status. O esvaziamento antes da troca é o que faz o
	 * leitor de tela anunciar duas mensagens iguais seguidas.
	 *
	 * @param {string} texto Mensagem.
	 */
	function anunciar( texto ) {
		if ( ! status ) {
			return;
		}

		status.textContent = '';
		window.setTimeout( function () {
			status.textContent = texto;
		}, 50 );
	}

	/**
	 * Escreve na região de alerta; com `recarregar`, acrescenta o botão que
	 * recarrega a página — a saída do 409, em que a árvore da tela ficou velha.
	 *
	 * @param {string}  texto      Mensagem, ou vazio para limpar.
	 * @param {boolean} recarregar Se oferece recarregar.
	 */
	function alertar( texto, recarregar ) {
		if ( ! alerta ) {
			if ( texto ) {
				window.alert( texto );
			}
			return;
		}

		alerta.textContent = '';

		if ( ! texto ) {
			return;
		}

		var paragrafo = document.createElement( 'p' );
		paragrafo.textContent = texto;
		alerta.appendChild( paragrafo );

		if ( recarregar ) {
			var botao = document.createElement( 'button' );
			botao.type = 'button';
			botao.className = 'rc-incubadora__botao';
			botao.textContent = t.recarregar;
			botao.addEventListener( 'click', function () {
				window.location.reload();
			} );
			alerta.appendChild( botao );
		}
	}

	/**
	 * Monta `%s`/`%1$s` como o `sprintf` do servidor, só com texto.
	 *
	 * @param {string} modelo Texto com marcadores.
	 * @return {string}
	 */
	function formatar( modelo ) {
		var valores = Array.prototype.slice.call( arguments, 1 );
		var proximo = 0;

		return modelo.replace( /%(?:(\d+)\$)?s/g, function ( marcador, posicao ) {
			var valor = posicao ? valores[ posicao - 1 ] : valores[ proximo++ ];
			return undefined === valor ? '' : String( valor );
		} );
	}

	/* ---------------------------------------------------------------------
	 * Leitura da árvore do DOM
	 * ------------------------------------------------------------------ */

	/**
	 * O `<li>` de uma página, ou `null`.
	 *
	 * @param {number} id ID da página.
	 * @return {?HTMLElement}
	 */
	function no( id ) {
		return arvore.querySelector( 'li[data-rc-id="' + id + '"]' );
	}

	/**
	 * ID da página de um `<li>`.
	 *
	 * @param {HTMLElement} li Nó.
	 * @return {number}
	 */
	function idDe( li ) {
		return parseInt( li.getAttribute( 'data-rc-id' ), 10 ) || 0;
	}

	/**
	 * Título visível de um `<li>`, sem o selo de rascunho.
	 *
	 * @param {HTMLElement} li Nó.
	 * @return {string}
	 */
	function tituloDe( li ) {
		var titulo = li.querySelector( ':scope > a .rc-incubadora__no-titulo' );
		return titulo ? titulo.textContent.trim() : t.semTitulo;
	}

	/**
	 * O `<li>` mãe de um nó, ou `null` na raiz.
	 *
	 * @param {HTMLElement} li Nó.
	 * @return {?HTMLElement}
	 */
	function maeDe( li ) {
		return li.parentElement.closest( 'li[data-rc-id]' );
	}

	/**
	 * IDs das filhas de uma página, na ordem da tela. `0` é a raiz.
	 *
	 * @param {number} id ID da mãe.
	 * @return {number[]}
	 */
	function filhasDe( id ) {
		var lista = id ? no( id ) && no( id ).querySelector( ':scope > details > ul' ) : arvore.querySelector( ':scope > ul' );

		if ( ! lista ) {
			return [];
		}

		return Array.prototype.map.call( lista.querySelectorAll( ':scope > li[data-rc-id]' ), idDe );
	}

	/**
	 * Quantos ancestrais um nó tem na árvore.
	 *
	 * @param {HTMLElement} li Nó.
	 * @return {number}
	 */
	function nivelDe( li ) {
		var nivel = 0;
		var mae = maeDe( li );

		while ( mae ) {
			nivel++;
			mae = maeDe( mae );
		}

		return nivel;
	}

	/**
	 * Altura da subárvore de um nó: 0 sem filhas, 1 com filhas e sem netas…
	 * A mesma conta de `altura()` no servidor, para o diálogo não oferecer o
	 * destino que o servidor recusaria.
	 *
	 * @param {HTMLElement} li Nó.
	 * @return {number}
	 */
	function alturaDe( li ) {
		var base = nivelDe( li );
		var maior = 0;

		Array.prototype.forEach.call( li.querySelectorAll( 'li[data-rc-id]' ), function ( descendente ) {
			maior = Math.max( maior, nivelDe( descendente ) - base );
		} );

		return maior;
	}

	/**
	 * Se `pagina` pode ir para dentro de `destino` (0 é a raiz): não para
	 * dentro de si nem de descendente, e sem passar do teto de níveis.
	 *
	 * @param {HTMLElement} pagina  Nó movido.
	 * @param {number}      destino ID da nova mãe.
	 * @return {boolean}
	 */
	function destinoValido( pagina, destino ) {
		if ( ! destino ) {
			return true;
		}

		var li = no( destino );

		if ( ! li || pagina === li || pagina.contains( li ) ) {
			return false;
		}

		return nivelDe( li ) + 1 + alturaDe( pagina ) <= dados.profundidade;
	}

	/**
	 * Os `<details>` abertos da árvore, para o servidor devolvê-los abertos.
	 *
	 * @return {number[]}
	 */
	function abertos() {
		return Array.prototype.map.call( arvore.querySelectorAll( 'details[open]' ), function ( gaveta ) {
			return idDe( gaveta.closest( 'li[data-rc-id]' ) );
		} );
	}

	/* ---------------------------------------------------------------------
	 * Servidor
	 * ------------------------------------------------------------------ */

	/**
	 * POST para `admin-post.php`, já resolvido em `{success, data}`.
	 *
	 * Falha de rede e corpo que não é JSON viram o mesmo formato de erro, para
	 * quem chama tratar um caso só.
	 *
	 * @param {FormData} corpo Campos.
	 * @return {Promise<Object>}
	 */
	function enviar( corpo ) {
		return fetch( dados.rota, {
			method: 'POST',
			body: corpo,
			credentials: 'same-origin'
		} ).then( function ( resposta ) {
			return resposta.json().catch( function () {
				return { success: false, data: { codigo: 'resposta', mensagem: t.erroResposta } };
			} );
		}, function () {
			return { success: false, data: { codigo: 'rede', mensagem: t.erroRede } };
		} ).then( function ( json ) {
			return json && json.data ? json : { success: false, data: { codigo: 'resposta', mensagem: t.erroResposta } };
		} );
	}

	/**
	 * Cria uma página e abre ela já no editor.
	 *
	 * @param {string} titulo Título.
	 * @param {number} mae    ID da mãe, ou 0.
	 * @return {Promise<?Object>} O erro, ou `null` quando vai navegar.
	 */
	function criar( titulo, mae ) {
		var corpo = new FormData();
		corpo.append( 'action', dados.acaoCriar );
		corpo.append( '_wpnonce', dados.nonceCriar );
		corpo.append( 'titulo', titulo );
		corpo.append( 'mae', String( mae ) );

		anunciar( t.criando );

		return enviar( corpo ).then( function ( json ) {
			if ( ! json.success || ! json.data.url ) {
				anunciar( '' );
				return json.data;
			}

			var destino = new URL( json.data.url, window.location.href );
			destino.searchParams.set( 'editar', '1' );
			window.location.assign( destino.href );

			return null;
		} );
	}

	/**
	 * Move uma página e troca árvore e trilha pelo que o servidor desenhou.
	 *
	 * @param {number}   pagina ID da página.
	 * @param {number}   pai    ID da nova mãe, ou 0.
	 * @param {number[]} ordem  Filhas do destino, na ordem final.
	 * @return {Promise<?Object>} O erro, ou `null` no sucesso.
	 */
	function mover( pagina, pai, ordem ) {
		var titulo = no( pagina ) ? tituloDe( no( pagina ) ) : '';
		var nomeDestino = pai && no( pai ) ? tituloDe( no( pai ) ) : t.raiz;
		var corpo = new FormData();

		corpo.append( 'action', dados.acaoMover );
		corpo.append( '_wpnonce', dados.nonceMover );
		corpo.append( 'pagina', String( pagina ) );
		corpo.append( 'pai', String( pai ) );
		corpo.append( 'atual', String( dados.atual ) );
		ordem.forEach( function ( id ) {
			corpo.append( 'ordem[]', String( id ) );
		} );
		abertos().forEach( function ( id ) {
			corpo.append( 'abertos[]', String( id ) );
		} );

		alertar( '' );
		anunciar( t.movendo );

		return enviar( corpo ).then( function ( json ) {
			if ( ! json.success ) {
				anunciar( '' );
				return json.data;
			}

			var resposta = json.data;
			var trilha = document.querySelector( '.rc-incubadora__trilha' );

			arvore.innerHTML = resposta.arvore;

			if ( trilha && resposta.trilha ) {
				trilha.outerHTML = resposta.trilha;
			}

			// A página aberta pode ter sido ela própria movida, ou estar abaixo
			// da movida: o endereço na barra tem de ser o novo, ou recarregar
			// daria 404.
			if ( resposta.url_atual && resposta.url_atual !== window.location.pathname ) {
				window.history.replaceState( window.history.state, '', resposta.url_atual + window.location.search + window.location.hash );
			}

			anunciar( formatar( t.movida, titulo, nomeDestino ) );

			return null;
		} );
	}

	/**
	 * Mostra o erro de uma operação; o 409 ganha o botão de recarregar.
	 *
	 * @param {Object} erro `data` da resposta de erro.
	 */
	function mostrarErro( erro ) {
		alertar( erro.mensagem || t.erroResposta, 'conflito' === erro.codigo );
	}

	/* ---------------------------------------------------------------------
	 * Diálogo
	 * ------------------------------------------------------------------ */

	/**
	 * Cria um campo com rótulo dentro do formulário do diálogo.
	 *
	 * @param {HTMLElement} formulario Formulário.
	 * @param {string}      tag        `input` ou `select`.
	 * @param {string}      id         ID do campo.
	 * @param {string}      rotulo     Texto do rótulo.
	 * @return {HTMLElement}
	 */
	function campo( formulario, tag, id, rotulo ) {
		var grupo = document.createElement( 'p' );
		var label = document.createElement( 'label' );
		var elemento = document.createElement( tag );

		grupo.className = 'rc-incubadora__dialogo-campo';
		label.setAttribute( 'for', id );
		label.textContent = rotulo;
		elemento.id = id;
		elemento.className = 'rc-incubadora__dialogo-entrada';

		grupo.appendChild( label );
		grupo.appendChild( elemento );
		formulario.appendChild( grupo );

		return elemento;
	}

	/**
	 * Abre o diálogo modal com o conteúdo que `montar` puser no formulário.
	 *
	 * `<dialog>` com `showModal()` dá de graça o que um modal acessível pede:
	 * foco preso dentro, Esc para fechar, fundo inerte e o papel `dialog`. Ao
	 * fechar, o foco volta a quem abriu.
	 *
	 * @param {string}   titulo  Título do diálogo.
	 * @param {string}   rotulo  Texto do botão de confirmar.
	 * @param {Function} montar  Recebe o formulário; devolve a função de envio,
	 *                           que devolve a Promise do erro (ou `null`).
	 * @param {HTMLElement} origem Quem abriu.
	 */
	function abrirDialogo( titulo, rotulo, montar, origem ) {
		if ( dialogo ) {
			dialogo.remove();
		}

		dialogo = document.createElement( 'dialog' );
		dialogo.className = 'rc-incubadora__dialogo';
		dialogo.setAttribute( 'aria-labelledby', 'rc-incubadora-dialogo-titulo' );

		var cabecalho = document.createElement( 'h2' );
		var formulario = document.createElement( 'form' );
		var erro = document.createElement( 'div' );
		var botoes = document.createElement( 'p' );
		var confirmar = document.createElement( 'button' );
		var cancelar = document.createElement( 'button' );

		cabecalho.id = 'rc-incubadora-dialogo-titulo';
		cabecalho.className = 'rc-incubadora__dialogo-titulo';
		cabecalho.textContent = titulo;

		formulario.method = 'dialog';
		formulario.noValidate = false;

		erro.className = 'rc-incubadora__alerta';
		erro.setAttribute( 'role', 'alert' );

		botoes.className = 'rc-incubadora__dialogo-botoes';
		confirmar.type = 'submit';
		confirmar.className = 'rc-incubadora__botao rc-incubadora__botao--primario';
		confirmar.textContent = rotulo;
		cancelar.type = 'button';
		cancelar.className = 'rc-incubadora__botao';
		cancelar.textContent = t.cancelar;

		dialogo.appendChild( cabecalho );
		dialogo.appendChild( formulario );

		var executar = montar( formulario );

		formulario.appendChild( erro );
		botoes.appendChild( confirmar );
		botoes.appendChild( cancelar );
		formulario.appendChild( botoes );

		cancelar.addEventListener( 'click', function () {
			dialogo.close();
		} );

		formulario.addEventListener( 'submit', function ( evento ) {
			evento.preventDefault();

			if ( confirmar.disabled ) {
				return;
			}

			confirmar.disabled = true;
			erro.textContent = '';

			executar().then( function ( falha ) {
				confirmar.disabled = false;

				if ( falha ) {
					erro.textContent = falha.mensagem || t.erroResposta;

					if ( 'conflito' === falha.codigo ) {
						var recarregar = document.createElement( 'button' );
						recarregar.type = 'button';
						recarregar.className = 'rc-incubadora__botao';
						recarregar.textContent = t.recarregar;
						recarregar.addEventListener( 'click', function () {
							window.location.reload();
						} );
						erro.appendChild( document.createTextNode( ' ' ) );
						erro.appendChild( recarregar );
					}
					return;
				}

				dialogo.close();
			} );
		} );

		dialogo.addEventListener( 'close', function () {
			var este = dialogo;

			window.setTimeout( function () {
				este.remove();
			}, 0 );
			dialogo = null;

			if ( origem && document.contains( origem ) ) {
				origem.focus();
			}
		} );

		// Dentro da moldura, e não no `<body>`: as custom properties de cor
		// dos botões são declaradas em `.rc-incubadora`. Na camada do topo o
		// `<dialog>` não ocupa célula da grade.
		( document.querySelector( '.rc-incubadora' ) || document.body ).appendChild( dialogo );
		dialogo.showModal();
	}

	/**
	 * Diálogo de "Nova página" / "Nova subpágina": só o título.
	 *
	 * @param {HTMLElement} botao Botão que abriu.
	 */
	function dialogoCriar( botao ) {
		var mae = parseInt( botao.getAttribute( 'data-rc-mae' ), 10 ) || 0;

		abrirDialogo( botao.textContent.trim(), t.criar, function ( formulario ) {
			var titulo = campo( formulario, 'input', 'rc-incubadora-novo-titulo', t.tituloCampo );

			titulo.type = 'text';
			titulo.required = true;
			titulo.maxLength = 200;
			titulo.autocomplete = 'off';

			if ( mae && no( mae ) ) {
				var ajuda = document.createElement( 'p' );
				ajuda.className = 'rc-incubadora__dialogo-ajuda';
				ajuda.textContent = formatar( t.criarDentroDe, tituloDe( no( mae ) ) );
				formulario.insertBefore( ajuda, formulario.firstChild );
			}

			return function () {
				return criar( titulo.value.trim(), mae );
			};
		}, botao );
	}

	/**
	 * Diálogo de "Mover…": página mãe e posição entre as irmãs.
	 *
	 * A lista de mães é a árvore inteira, recuada, sem a própria página, sem as
	 * subpáginas dela e sem os destinos que passariam do teto de níveis. A de
	 * posição se refaz a cada troca de mãe.
	 *
	 * @param {HTMLElement} botao Botão que abriu.
	 */
	function dialogoMover( botao ) {
		var paginaId = parseInt( botao.getAttribute( 'data-rc-pagina' ), 10 ) || 0;
		var pagina = no( paginaId );

		if ( ! pagina ) {
			alertar( t.erroResposta, true );
			return;
		}

		var maeAtual = maeDe( pagina ) ? idDe( maeDe( pagina ) ) : 0;

		abrirDialogo( t.dialogoTitulo, t.mover, function ( formulario ) {
			var ajuda = document.createElement( 'p' );
			ajuda.className = 'rc-incubadora__dialogo-ajuda';
			ajuda.textContent = formatar( t.dialogoAjuda, tituloDe( pagina ) );
			formulario.appendChild( ajuda );

			var mae = campo( formulario, 'select', 'rc-incubadora-mover-mae', t.mae );
			var posicao = campo( formulario, 'select', 'rc-incubadora-mover-posicao', t.posicao );

			mae.appendChild( new Option( t.raiz, '0' ) );

			Array.prototype.forEach.call( arvore.querySelectorAll( 'li[data-rc-id]' ), function ( li ) {
				var id = idDe( li );

				if ( ! destinoValido( pagina, id ) ) {
					return;
				}

				// Recuo por traço: `<option>` não aceita padding confiável
				// entre navegadores, e o traço é lido como pausa, não como
				// símbolo.
				mae.appendChild( new Option( new Array( nivelDe( li ) + 2 ).join( '— ' ) + tituloDe( li ), String( id ) ) );
			} );

			mae.value = String( maeAtual );

			function preencherPosicoes() {
				var irmas = filhasDe( parseInt( mae.value, 10 ) || 0 ).filter( function ( id ) {
					return id !== paginaId;
				} );
				var escolha = '';

				posicao.textContent = '';
				posicao.appendChild( new Option( t.noInicio, '' ) );

				irmas.forEach( function ( id ) {
					posicao.appendChild( new Option( formatar( t.depoisDe, tituloDe( no( id ) ) ), String( id ) ) );
				} );

				if ( ( parseInt( mae.value, 10 ) || 0 ) === maeAtual ) {
					// Na mãe de agora, a posição de agora: confirmar sem mexer
					// não muda nada.
					var anterior = pagina.previousElementSibling;
					escolha = anterior ? String( idDe( anterior ) ) : '';
				} else if ( irmas.length ) {
					escolha = String( irmas[ irmas.length - 1 ] );
				}

				posicao.value = escolha;
			}

			mae.addEventListener( 'change', preencherPosicoes );
			preencherPosicoes();

			return function () {
				var pai = parseInt( mae.value, 10 ) || 0;
				var depois = parseInt( posicao.value, 10 ) || 0;
				var ordem = filhasDe( pai ).filter( function ( id ) {
					return id !== paginaId;
				} );
				var indice = depois ? ordem.indexOf( depois ) + 1 : 0;

				ordem.splice( indice, 0, paginaId );

				if ( pai === maeAtual && ordem.join( ',' ) === filhasDe( pai ).join( ',' ) ) {
					return Promise.resolve( { codigo: 'sem_mudanca', mensagem: t.semMudanca } );
				}

				return mover( paginaId, pai, ordem );
			};
		}, botao );
	}

	/* ---------------------------------------------------------------------
	 * Arrastar e soltar
	 * ------------------------------------------------------------------ */

	/**
	 * Tira a marca de zona do nó marcado.
	 */
	function desmarcar() {
		if ( alvoMarcado ) {
			alvoMarcado.classList.remove( 'rc-incubadora__no--antes', 'rc-incubadora__no--dentro', 'rc-incubadora__no--depois' );
			alvoMarcado = null;
		}
	}

	/**
	 * Em que zona do link o ponteiro está: o quarto de cima põe antes, o de
	 * baixo depois, o meio dentro. "Dentro" é descartado quando a página não
	 * cabe ali — e então o meio vira "depois", que é o vizinho mais próximo.
	 *
	 * @param {HTMLElement} link   Link sob o ponteiro.
	 * @param {number}      y      `clientY` do evento.
	 * @param {HTMLElement} li     Nó do link.
	 * @return {string} `antes`, `dentro` ou `depois`.
	 */
	function zona( link, y, li ) {
		var caixa = link.getBoundingClientRect();
		var fracao = ( y - caixa.top ) / ( caixa.height || 1 );

		if ( fracao < 0.25 ) {
			return 'antes';
		}

		if ( fracao > 0.75 || ! destinoValido( arrastada, idDe( li ) ) ) {
			return 'depois';
		}

		return 'dentro';
	}

	/**
	 * Destino de uma soltura: mãe e ordem final, ou `null` se inválido.
	 *
	 * @param {HTMLElement} li     Nó alvo.
	 * @param {string}      onde   Zona.
	 * @return {?{pai: number, ordem: number[]}}
	 */
	function destinoDaSoltura( li, onde ) {
		var paginaId = idDe( arrastada );

		if ( arrastada === li || arrastada.contains( li ) ) {
			return null;
		}

		if ( 'dentro' === onde ) {
			var alvo = idDe( li );
			var filhas = filhasDe( alvo ).filter( function ( id ) {
				return id !== paginaId;
			} );

			filhas.push( paginaId );
			return { pai: alvo, ordem: filhas };
		}

		var pai = maeDe( li ) ? idDe( maeDe( li ) ) : 0;

		if ( ! destinoValido( arrastada, pai ) ) {
			return null;
		}

		var ordem = filhasDe( pai ).filter( function ( id ) {
			return id !== paginaId;
		} );

		ordem.splice( ordem.indexOf( idDe( li ) ) + ( 'depois' === onde ? 1 : 0 ), 0, paginaId );

		return { pai: pai, ordem: ordem };
	}

	arvore.addEventListener( 'dragstart', function ( evento ) {
		var link = evento.target.closest && evento.target.closest( '.rc-incubadora__no-link' );

		if ( ! link ) {
			return;
		}

		arrastada = link.closest( 'li[data-rc-id]' );
		arrastada.classList.add( 'rc-incubadora__no--arrastada' );
		evento.dataTransfer.effectAllowed = 'move';
		evento.dataTransfer.setData( 'text/plain', String( idDe( arrastada ) ) );
	} );

	arvore.addEventListener( 'dragover', function ( evento ) {
		var link = arrastada && evento.target.closest && evento.target.closest( '.rc-incubadora__no-link' );

		if ( ! link ) {
			desmarcar();
			return;
		}

		var li = link.closest( 'li[data-rc-id]' );
		var onde = zona( link, evento.clientY, li );

		if ( ! destinoDaSoltura( li, onde ) ) {
			desmarcar();
			evento.dataTransfer.dropEffect = 'none';
			return;
		}

		// Sem `preventDefault()` o navegador não aceita soltar ali.
		evento.preventDefault();
		evento.dataTransfer.dropEffect = 'move';

		if ( alvoMarcado !== li ) {
			desmarcar();
			alvoMarcado = li;
		}

		li.classList.remove( 'rc-incubadora__no--antes', 'rc-incubadora__no--dentro', 'rc-incubadora__no--depois' );
		li.classList.add( 'rc-incubadora__no--' + onde );
	} );

	arvore.addEventListener( 'dragleave', function ( evento ) {
		if ( ! arvore.contains( evento.relatedTarget ) ) {
			desmarcar();
		}
	} );

	arvore.addEventListener( 'drop', function ( evento ) {
		var link = arrastada && evento.target.closest && evento.target.closest( '.rc-incubadora__no-link' );

		if ( ! link ) {
			return;
		}

		evento.preventDefault();

		var li = link.closest( 'li[data-rc-id]' );
		var paginaId = idDe( arrastada );
		var antigaMae = maeDe( arrastada ) ? idDe( maeDe( arrastada ) ) : 0;
		var antigaOrdem = filhasDe( antigaMae ).join( ',' );
		var destino = destinoDaSoltura( li, zona( link, evento.clientY, li ) );

		desmarcar();

		if ( ! destino || ( destino.pai === antigaMae && destino.ordem.join( ',' ) === antigaOrdem ) ) {
			return;
		}

		// Ao soltar dentro de um ramo fechado, ele se abre na volta: a pessoa
		// vê onde a página foi parar.
		if ( destino.pai && no( destino.pai ) ) {
			var gaveta = no( destino.pai ).querySelector( ':scope > details' );
			if ( gaveta ) {
				gaveta.open = true;
			}
		}

		mover( paginaId, destino.pai, destino.ordem ).then( function ( erro ) {
			if ( erro ) {
				mostrarErro( erro );
				return;
			}

			var movida = no( paginaId );
			var foco = movida && movida.querySelector( ':scope > a' );

			if ( foco ) {
				foco.focus();
			}
		} );
	} );

	arvore.addEventListener( 'dragend', function () {
		desmarcar();

		if ( arrastada ) {
			arrastada.classList.remove( 'rc-incubadora__no--arrastada' );
			arrastada = null;
		}
	} );

	/* ---------------------------------------------------------------------
	 * Arranque
	 * ------------------------------------------------------------------ */

	document.addEventListener( 'click', function ( evento ) {
		var botao = evento.target.closest && evento.target.closest( '[data-rc-incubadora="criar"], [data-rc-incubadora="mover"]' );

		if ( ! botao ) {
			return;
		}

		alertar( '' );

		if ( 'criar' === botao.getAttribute( 'data-rc-incubadora' ) ) {
			dialogoCriar( botao );
		} else {
			dialogoMover( botao );
		}
	} );

	Array.prototype.forEach.call( document.querySelectorAll( '[data-rc-incubadora="criar"], [data-rc-incubadora="mover"]' ), function ( botao ) {
		botao.hidden = false;
	} );

	// A dica só onde há mouse: no toque, arrastar não funciona e a frase
	// prometeria o que não há.
	if ( window.matchMedia && window.matchMedia( '(pointer: fine)' ).matches && arvore.querySelector( 'li[data-rc-id]' ) ) {
		var dica = document.createElement( 'p' );
		dica.className = 'rc-incubadora__arvore-dica';
		dica.textContent = t.arrastarAjuda;
		arvore.parentNode.insertBefore( dica, arvore.nextSibling );
	}
}() );
