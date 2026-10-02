/**
 * Editor da Incubadora: TinyMCE em modo `inline` sobre a própria tela de leitura.
 *
 * Não há tela de edição separada nem botão de prévia: o corpo da página vira
 * editável no lugar, com as mesmas regras de CSS da leitura, e o que se vê
 * enquanto se escreve é o que fica.
 *
 * Só chega aqui quem tem a capacidade de escrita — ver
 * `Reconectar_Incubadora_Editor::deve_carregar()`. O TinyMCE (1,8 MB) só é
 * baixado no primeiro clique em "Editar".
 *
 * O servidor é quem decide o que é gravado: este script nunca confia que o
 * HTML que manda chegará igual. Quando o salvamento devolve avisos, o corpo
 * do editor é trocado pelo que o servidor efetivamente guardou.
 */
( function () {
	'use strict';

	var dados = window.reconectarIncubadoraEditor;
	var botaoEditar = document.querySelector( '[data-rc-incubadora="editar"]' );
	var pagina = document.querySelector( '.rc-incubadora__pagina' );
	var corpo = pagina && pagina.querySelector( '.rc-incubadora__conteudo' );
	var titulo = document.getElementById( 'rc-incubadora-titulo' );
	var acoes = pagina && pagina.querySelector( '.rc-incubadora__acoes' );
	var status = pagina && pagina.querySelector( '.rc-incubadora__status' );
	var alerta = pagina && pagina.querySelector( '.rc-incubadora__alerta' );

	if ( ! dados || ! botaoEditar || ! corpo || ! titulo || ! acoes || ! status || ! alerta ) {
		return;
	}

	var t = dados.textos;

	/*
	 * Os mesmos dois destinos de `playerAceito()` no `incubadora.js` e de
	 * `Reconectar_Incubadora_Conteudo::url_do_player()` no servidor.
	 */
	var PADRAO_YOUTUBE = /^[A-Za-z0-9_-]{11}$/;
	var PADRAO_VIMEO = /^[0-9]{1,12}$/;
	var PADRAO_VIMEO_HASH = /^[0-9a-f]{6,32}$/;

	var CORES = [
		'#1F1F1F', t.corPadrao,
		'#663191', t.corRoxo,
		'#B3261E', t.corVermelho,
		'#1E6B3A', t.corVerde,
		'#1A5FA8', t.corAzul,
		'#9A4A12', t.corMarrom
	];

	var estado = {
		titulo: dados.titulo,
		status: dados.status,
		modificado: dados.modificado,
		leitura: corpo.innerHTML,
		vazio: '',
		editor: null,
		salvando: false,
		carregando: null,
		focarTitulo: false
	};

	var campoTitulo = null;
	var barra = null;
	var botoesBarra = null;

	/* ---------------------------------------------------------------------
	 * Utilidades
	 * ------------------------------------------------------------------ */

	/**
	 * Escapa texto para entrar em HTML montado como string.
	 *
	 * @param {string} texto Texto cru.
	 * @return {string} Texto escapado.
	 */
	function escapar( texto ) {
		return String( texto )
			.replace( /&/g, '&amp;' )
			.replace( /</g, '&lt;' )
			.replace( />/g, '&gt;' )
			.replace( /"/g, '&quot;' );
	}

	/**
	 * Data do servidor (`Y-m-d H:i:s`, GMT) em ISO 8601.
	 *
	 * @param {string} data Data no formato do MySQL.
	 * @return {string} Data em ISO 8601.
	 */
	function iso( data ) {
		return String( data ).replace( ' ', 'T' ) + 'Z';
	}

	/**
	 * Escreve na região de status — mudança de estado, nunca erro.
	 *
	 * @param {string} texto Mensagem.
	 */
	function anunciar( texto ) {
		status.textContent = texto;
	}

	/**
	 * Escreve na região de alerta. Vazio limpa.
	 *
	 * @param {string}        texto  Mensagem.
	 * @param {string[]}      [itens] Lista de detalhes.
	 * @param {HTMLElement}   [extra] Elemento a acrescentar (um botão).
	 */
	function alertar( texto, itens, extra ) {
		alerta.textContent = '';

		if ( ! texto ) {
			return;
		}

		var paragrafo = document.createElement( 'p' );
		paragrafo.textContent = texto;
		alerta.appendChild( paragrafo );

		if ( itens && itens.length ) {
			var lista = document.createElement( 'ul' );

			itens.forEach( function ( item ) {
				var li = document.createElement( 'li' );
				li.textContent = item;
				lista.appendChild( li );
			} );

			alerta.appendChild( lista );
		}

		if ( extra ) {
			alerta.appendChild( extra );
		}
	}

	/* ---------------------------------------------------------------------
	 * Arquivos
	 * ------------------------------------------------------------------ */

	/**
	 * Envia um arquivo à página e resolve com os dados do servidor.
	 *
	 * Tipo e tamanho são conferidos aqui antes de enviar, para quem cola uma
	 * foto de 6 MB não esperar a transferência inteira para ouvir "não". A
	 * conferência que vale é a do servidor, que olha o conteúdo, e não o tipo
	 * que o navegador declara.
	 *
	 * @param {Blob}   arquivo Arquivo ou blob colado.
	 * @param {string} nome    Nome do arquivo.
	 * @return {Promise} Resolve com `{url, nome, imagem, largura, altura, descricao}`;
	 *                   rejeita com a mensagem para a pessoa.
	 */
	function enviarArquivo( arquivo, nome ) {
		var regras = dados.arquivos;
		var tipo = arquivo.type || '';
		var imagem = regras.imagens.indexOf( tipo ) !== -1;

		// Sem isso, a recusa feita aqui deixa no ar o "Arquivo anexado" do
		// envio anterior, ao lado do alerta que diz o contrário.
		anunciar( '' );

		if ( ! imagem && 'application/pdf' !== tipo ) {
			return Promise.reject( t.tipoRecusado );
		}

		if ( imagem && arquivo.size > regras.limiteImagem ) {
			return Promise.reject( t.grandeImagem.replace( '%s', regras.textoLimiteImagem ) );
		}

		if ( ! imagem && arquivo.size > regras.limitePdf ) {
			return Promise.reject( t.grandePdf.replace( '%s', regras.textoLimitePdf ) );
		}

		var corpoEnvio = new FormData();

		corpoEnvio.append( 'action', regras.acao );
		corpoEnvio.append( '_wpnonce', regras.nonce );
		corpoEnvio.append( 'pagina', String( dados.pagina ) );
		corpoEnvio.append( 'arquivo', arquivo, nome || 'arquivo' );

		anunciar( t.enviando );

		// A ação vai também na URL. Corpo acima de `post_max_size` chega com
		// `$_POST` vazio — e sem `action` o `admin-post.php` nem chama o
		// handler: a resposta seria um 200 em branco, lido aqui como "resposta
		// inválida". Pela query string ele chega, e devolve o 413 que explica.
		return fetch( dados.rota + '?action=' + encodeURIComponent( regras.acao ), {
			method: 'POST',
			body: corpoEnvio,
			credentials: 'same-origin'
		} ).then( function ( resposta ) {
			return resposta.json().catch( function () {
				return { success: false, data: { mensagem: t.erroResposta } };
			} );
		}, function () {
			return { success: false, data: { mensagem: t.erroRede } };
		} ).then( function ( json ) {
			if ( ! json || ! json.success || ! json.data || ! json.data.url ) {
				anunciar( '' );
				throw ( json && json.data && json.data.mensagem ) || t.erroResposta;
			}

			anunciar( t.enviado );

			return json.data;
		} );
	}

	/**
	 * Recebe as imagens coladas, arrastadas ou escolhidas na janela do TinyMCE.
	 *
	 * A rejeição leva `remove: true`: sem ela, a imagem recusada fica no
	 * editor como `blob:`, que existe só nesta aba — o servidor a apagaria ao
	 * salvar, e a pessoa só descobriria pelo aviso, depois.
	 *
	 * @param {Object} blobInfo Dados da imagem, do TinyMCE.
	 * @return {Promise} Resolve com o endereço gravado.
	 */
	function receberImagem( blobInfo ) {
		return enviarArquivo( blobInfo.blob(), blobInfo.filename() ).then( function ( resposta ) {
			return resposta.url;
		}, function ( mensagem ) {
			alertar( t.falhaEnvio + ' ' + mensagem );
			throw { message: mensagem, remove: true };
		} );
	}

	/**
	 * Abre a escolha de arquivo e insere o resultado no ponto do cursor.
	 *
	 * Imagem entra como `<img>` com largura e altura, que reservam o espaço
	 * antes de ela carregar; PDF entra como link com tipo e tamanho no texto,
	 * para quem lê saber o que vai baixar antes de clicar.
	 *
	 * @param {Object} editor Instância do TinyMCE.
	 */
	function anexar( editor ) {
		var campo = document.createElement( 'input' );

		campo.type = 'file';
		campo.accept = dados.arquivos.aceitos;

		campo.addEventListener( 'change', function () {
			var arquivo = campo.files && campo.files[ 0 ];

			if ( ! arquivo ) {
				return;
			}

			alertar( '' );

			enviarArquivo( arquivo, arquivo.name ).then( function ( resposta ) {
				var html;

				if ( resposta.imagem ) {
					html = '<img src="' + escapar( resposta.url ) + '" width="' + resposta.largura + '" height="' + resposta.altura + '">';
				} else {
					html = '<a href="' + escapar( resposta.url ) + '">' + escapar( resposta.nome ) + '</a> (' + escapar( resposta.descricao ) + ')';
				}

				editor.focus();
				editor.insertContent( html );

				// A imagem entra **sem** `alt`, e a janela abre sobre ela para
				// quem envia escrever a descrição ou marcar "decorativa". Com
				// `alt=""` o TinyMCE abriria a janela já com "decorativa"
				// marcada e o campo de descrição desabilitado — medido —, e a
				// decisão viria tomada. Fechar sem responder ainda resulta em
				// `alt=""` ao salvar: é o sanitizador que o garante.
				if ( resposta.imagem ) {
					var nova = editor.dom.select( 'img[src="' + resposta.url.replace( /"/g, '\\"' ) + '"]' ).pop();

					if ( nova ) {
						editor.selection.select( nova );
						editor.execCommand( 'mceImage' );
					}
				}
			}, function ( mensagem ) {
				alertar( t.falhaEnvio + ' ' + mensagem );
				editor.focus();
			} );
		} );

		campo.click();
	}

	/* ---------------------------------------------------------------------
	 * Vídeo
	 * ------------------------------------------------------------------ */

	/**
	 * Confere provedor, ID e hash, no molde de `video_valido()` do servidor.
	 *
	 * @param {string} provedor `youtube` ou `vimeo`.
	 * @param {string} id       ID do vídeo.
	 * @param {string} hash     Hash de privacidade do Vimeo, ou vazio.
	 * @return {Object|null} `{provedor, id, hash}`.
	 */
	function videoValido( provedor, id, hash ) {
		id = String( id || '' );
		hash = String( hash || '' );

		if ( 'youtube' === provedor && PADRAO_YOUTUBE.test( id ) ) {
			return { provedor: provedor, id: id, hash: '' };
		}

		if ( 'vimeo' === provedor && PADRAO_VIMEO.test( id ) && ( '' === hash || PADRAO_VIMEO_HASH.test( hash ) ) ) {
			return { provedor: provedor, id: id, hash: hash };
		}

		return null;
	}

	/**
	 * Identifica um vídeo pela URL, espelhando `identificar_video()`.
	 *
	 * Esta conferência é só para avisar cedo, na janela de inserção: quem
	 * decide o que é gravado é o servidor, que repete tudo.
	 *
	 * @param {string} endereco URL colada.
	 * @return {Object|null} `{provedor, id, hash}`.
	 */
	function identificarVideo( endereco ) {
		var url;

		try {
			url = new URL( String( endereco || '' ).trim() );
		} catch ( erro ) {
			return null;
		}

		if ( ( 'https:' !== url.protocol && 'http:' !== url.protocol ) || '' !== url.username || '' !== url.password ) {
			return null;
		}

		var host = url.hostname.toLowerCase().replace( /^(?:www|m)\./, '' );
		var segmento = url.pathname.split( '/' ).filter( Boolean );

		switch ( host ) {
			case 'youtu.be':
				return videoValido( 'youtube', segmento[ 0 ], '' );

			case 'youtube.com':
			case 'youtube-nocookie.com':
				if ( 'watch' === segmento[ 0 ] ) {
					return videoValido( 'youtube', url.searchParams.get( 'v' ), '' );
				}
				if ( segmento[ 1 ] && [ 'embed', 'shorts', 'live', 'v' ].indexOf( segmento[ 0 ] ) > -1 ) {
					return videoValido( 'youtube', segmento[ 1 ], '' );
				}
				return null;

			case 'vimeo.com':
				return videoValido( 'vimeo', segmento[ 0 ], segmento[ 1 ] || '' );

			case 'player.vimeo.com':
				if ( 'video' === segmento[ 0 ] ) {
					return videoValido( 'vimeo', segmento[ 1 ], url.searchParams.get( 'h' ) || '' );
				}
				return null;
		}

		return null;
	}

	/**
	 * Endereço do player, como `url_do_player()` do servidor.
	 *
	 * @param {Object} video `{provedor, id, hash}` já validado.
	 * @return {string} URL do player.
	 */
	function urlDoPlayer( video ) {
		if ( 'youtube' === video.provedor ) {
			return 'https://www.youtube-nocookie.com/embed/' + video.id;
		}

		return 'https://player.vimeo.com/video/' + video.id + '?dnt=1' + ( video.hash ? '&h=' + video.hash : '' );
	}

	/**
	 * O `<iframe>` que o editor mostra e grava.
	 *
	 * O servidor o reconhece pelo `src` e o troca pelo marcador; os demais
	 * atributos servem para o vídeo tocar dentro do editor com as mesmas
	 * restrições da tela de leitura. O `sandbox` só sobrevive porque os dois
	 * hosts estão em `sandbox_iframes_exclusions` — sem isso o TinyMCE o
	 * reescreve vazio e o player não roda.
	 *
	 * @param {Object} video  `{provedor, id, hash}` já validado.
	 * @param {string} rotulo Título acessível do iframe.
	 * @return {string} HTML.
	 */
	function iframeDoVideo( video, rotulo ) {
		return '<iframe src="' + escapar( urlDoPlayer( video ) ) + '"' +
			' title="' + escapar( rotulo || t.videoTitulo ) + '"' +
			' sandbox="allow-scripts allow-same-origin allow-presentation allow-popups"' +
			' allow="fullscreen; picture-in-picture; encrypted-media"' +
			' allowfullscreen="allowfullscreen"' +
			' referrerpolicy="strict-origin-when-cross-origin"></iframe>';
	}

	/**
	 * Troca cada facade de leitura pelo iframe vivo, para edição.
	 *
	 * Facade cujo vídeo não confere fica como está: o servidor a reconhece
	 * pelos `data-rc-*` e decide no salvamento.
	 *
	 * @param {HTMLElement} raiz Elemento com o conteúdo.
	 */
	function facadesParaIframes( raiz ) {
		Array.prototype.forEach.call( raiz.querySelectorAll( '.rc-video[data-rc-provedor]' ), function ( facade ) {
			var video = videoValido(
				facade.getAttribute( 'data-rc-provedor' ),
				facade.getAttribute( 'data-rc-id' ),
				facade.getAttribute( 'data-rc-hash' )
			);

			if ( ! video ) {
				return;
			}

			var botao = facade.querySelector( '.rc-video__carregar' );
			var modelo = document.createElement( 'div' );

			modelo.innerHTML = iframeDoVideo( video, botao ? botao.getAttribute( 'data-rc-titulo' ) : '' );
			facade.replaceWith( modelo.firstChild );
		} );
	}

	/**
	 * HTML devolvido pelo servidor, pronto para o editor.
	 *
	 * @param {string} html HTML de leitura, com facades.
	 * @return {string} HTML com iframes.
	 */
	function paraEdicao( html ) {
		var modelo = document.createElement( 'div' );

		modelo.innerHTML = html;
		facadesParaIframes( modelo );

		return modelo.innerHTML;
	}

	/* ---------------------------------------------------------------------
	 * Botões próprios da barra
	 * ------------------------------------------------------------------ */

	/**
	 * Janela de inserção de vídeo.
	 *
	 * Própria, e não a do plugin `media`: aquela aceita qualquer endereço e
	 * monta `<iframe>` de `www.youtube.com`, que o servidor recusaria só no
	 * salvamento, longe de quem colou o link. O plugin `media` continua
	 * carregado pela prévia viva do iframe dentro do editor.
	 *
	 * @param {Object} editor Instância do TinyMCE.
	 */
	function abrirJanelaDeVideo( editor ) {
		editor.windowManager.open( {
			title: t.video,
			body: {
				type: 'panel',
				items: [
					{ type: 'input', name: 'url', label: t.videoUrl, inputMode: 'url' },
					{ type: 'htmlpanel', html: '<p>' + escapar( t.videoAjuda ) + '</p>' }
				]
			},
			buttons: [
				{ type: 'cancel', text: t.cancelar },
				{ type: 'submit', text: t.inserir, buttonType: 'primary' }
			],
			initialData: { url: '' },
			onSubmit: function ( janela ) {
				var video = identificarVideo( janela.getData().url );

				if ( ! video ) {
					editor.windowManager.alert( t.videoInvalido );
					return;
				}

				janela.close();
				editor.insertContent( iframeDoVideo( video, '' ) );
			}
		} );
	}

	/**
	 * Tabela de metadados no topo de uma seção, à moda do Confluence.
	 *
	 * Sem classe: o sanitizador não aceita `class`, e a tabela sai com o
	 * mesmo estilo de qualquer outra do conteúdo.
	 *
	 * @param {Object} editor Instância do TinyMCE.
	 */
	function inserirMetadados( editor ) {
		var linhas = [
			[ t.metaStatus, t.metaStatusValor ],
			[ t.metaData, dados.hoje ],
			[ t.metaResponsavel, dados.usuario ]
		];

		editor.insertContent(
			'<table style="width: 100%"><tbody>' +
			linhas.map( function ( linha ) {
				return '<tr><th scope="row">' + escapar( linha[ 0 ] ) + '</th><td>' + escapar( linha[ 1 ] ) + '</td></tr>';
			} ).join( '' ) +
			'</tbody></table><p></p>'
		);
	}

	/**
	 * Registra os botões que o TinyMCE não traz.
	 *
	 * @param {Object} editor Instância do TinyMCE, ainda no `setup`.
	 */
	function registrarBotoes( editor ) {
		editor.ui.registry.addToggleButton( 'rccodigo', {
			icon: 'sourcecode',
			tooltip: t.codigo,
			onAction: function () {
				editor.execCommand( 'mceToggleFormat', false, 'code' );
			},
			onSetup: function ( botao ) {
				var vinculo = editor.formatter.formatChanged( 'code', function ( ativo ) {
					botao.setActive( ativo );
				} );

				return function () {
					vinculo.unbind();
				};
			}
		} );

		editor.ui.registry.addButton( 'rcvideo', {
			icon: 'embed',
			tooltip: t.video,
			onAction: function () {
				abrirJanelaDeVideo( editor );
			}
		} );

		editor.ui.registry.addButton( 'rcanexar', {
			icon: 'upload',
			tooltip: t.anexar,
			onAction: function () {
				anexar( editor );
			}
		} );

		editor.ui.registry.addButton( 'rcmetadados', {
			icon: 'table-insert-row-above',
			tooltip: t.metadados,
			onAction: function () {
				inserirMetadados( editor );
			}
		} );

		// Iframe de qualquer outro destino sai já na análise do HTML — ao colar
		// e no `setContent` —, antes de virar nó do documento. Esperar o
		// servidor recusá-lo não basta: o navegador já teria carregado o
		// endereço, e quem abriu o editor seria anunciado a um terceiro.
		editor.on( 'PreInit', function () {
			editor.parser.addNodeFilter( 'iframe', function ( nos ) {
				nos.forEach( function ( no ) {
					var video = identificarVideo( no.attr( 'src' ) || '' );
					var pai   = no.parent;

					if ( video && urlDoPlayer( video ) === no.attr( 'src' ) ) {
						return;
					}

					no.remove();

					// O TinyMCE embrulha o iframe solto num `<p>`, que ficaria
					// na página como uma linha em branco sem origem visível.
					if ( pai && 'p' === pai.name && ! pai.firstChild ) {
						pai.remove();
					}
				} );
			} );
		} );

		editor.addShortcut( 'meta+s', t.atualizar, function () {
			salvar( false, false );
		} );
	}

	/* ---------------------------------------------------------------------
	 * Carregamento
	 * ------------------------------------------------------------------ */

	/**
	 * Carrega o `tinymce.min.js` uma vez só.
	 *
	 * @return {Promise} Resolve com o objeto global `tinymce`.
	 */
	function carregarTinymce() {
		if ( window.tinymce ) {
			return Promise.resolve( window.tinymce );
		}

		if ( estado.carregando ) {
			return estado.carregando;
		}

		estado.carregando = new Promise( function ( resolver, rejeitar ) {
			var script = document.createElement( 'script' );

			script.src = dados.tinymce + '/tinymce.min.js';
			script.onload = function () {
				if ( window.tinymce ) {
					resolver( window.tinymce );
				} else {
					rejeitar( new Error( 'tinymce' ) );
				}
			};
			script.onerror = function () {
				// Uma falha não pode travar as tentativas seguintes.
				estado.carregando = null;
				script.remove();
				rejeitar( new Error( 'tinymce' ) );
			};

			document.head.appendChild( script );
		} );

		return estado.carregando;
	}

	/* ---------------------------------------------------------------------
	 * Interface de edição
	 * ------------------------------------------------------------------ */

	/**
	 * Cria um botão da barra de edição.
	 *
	 * @param {string}   texto    Rótulo.
	 * @param {string}   variante `primario` ou vazio.
	 * @param {Function} acao     Ao clicar.
	 * @return {HTMLButtonElement} Botão.
	 */
	function criarBotao( texto, variante, acao ) {
		var botao = document.createElement( 'button' );

		botao.type = 'button';
		botao.className = 'rc-incubadora__botao' + ( variante ? ' rc-incubadora__botao--' + variante : '' );
		botao.textContent = texto;
		botao.addEventListener( 'click', acao );

		return botao;
	}

	/**
	 * Desenha os botões conforme o status: rascunho publica, publicada atualiza.
	 */
	function desenharBotoes() {
		botoesBarra.textContent = '';

		if ( 'publish' === estado.status ) {
			botoesBarra.appendChild( criarBotao( t.atualizar, 'primario', function () {
				salvar( false, false );
			} ) );
		} else {
			botoesBarra.appendChild( criarBotao( t.salvarRascunho, '', function () {
				salvar( false, false );
			} ) );
			botoesBarra.appendChild( criarBotao( t.publicar, 'primario', function () {
				salvar( true, false );
			} ) );
		}

		botoesBarra.appendChild( criarBotao( t.fechar, '', fechar ) );
	}

	/**
	 * Liga ou desliga os botões da barra durante uma gravação.
	 *
	 * @param {boolean} ocupado Se há gravação em andamento.
	 */
	function ocupar( ocupado ) {
		estado.salvando = ocupado;

		Array.prototype.forEach.call( botoesBarra.querySelectorAll( 'button' ), function ( botao ) {
			botao.disabled = ocupado;
		} );

		barra.setAttribute( 'aria-busy', ocupado ? 'true' : 'false' );
	}

	/**
	 * Ajusta a altura do campo de título ao texto.
	 */
	function ajustarTitulo() {
		campoTitulo.style.height = 'auto';
		campoTitulo.style.height = campoTitulo.scrollHeight + 'px';
	}

	/**
	 * Monta a barra e o campo de título, antes de o TinyMCE subir.
	 *
	 * A barra fica antes do título no DOM, que é também a ordem visual: quem
	 * tabula encontra as ferramentas, o título e o corpo, nessa ordem.
	 */
	function montarInterface() {
		barra = document.createElement( 'div' );
		barra.className = 'rc-incubadora__edicao';
		barra.setAttribute( 'role', 'region' );
		barra.setAttribute( 'aria-label', t.barra );

		var ferramentas = document.createElement( 'div' );
		ferramentas.className = 'rc-incubadora__ferramentas';

		botoesBarra = document.createElement( 'div' );
		botoesBarra.className = 'rc-incubadora__edicao-botoes';

		barra.appendChild( ferramentas );
		barra.appendChild( botoesBarra );
		acoes.after( barra );
		acoes.hidden = true;

		campoTitulo = document.createElement( 'textarea' );
		campoTitulo.className = 'rc-incubadora__titulo rc-incubadora__titulo-campo';
		campoTitulo.rows = 1;
		campoTitulo.value = estado.titulo;
		campoTitulo.setAttribute( 'aria-label', t.tituloCampo );
		campoTitulo.setAttribute( 'maxlength', '200' );
		campoTitulo.addEventListener( 'input', ajustarTitulo );
		campoTitulo.addEventListener( 'keydown', function ( evento ) {
			// Título é uma linha só: Enter leva ao corpo, como no Confluence.
			if ( 'Enter' === evento.key ) {
				evento.preventDefault();
				if ( estado.editor ) {
					estado.editor.focus();
				}
			}

			if ( 's' === evento.key.toLowerCase() && ( evento.ctrlKey || evento.metaKey ) ) {
				evento.preventDefault();
				salvar( false, false );
			}
		} );

		titulo.hidden = true;
		titulo.after( campoTitulo );
		ajustarTitulo();

		desenharBotoes();

		return ferramentas;
	}

	/**
	 * Desfaz a barra e o campo de título.
	 */
	function desmontarInterface() {
		if ( barra ) {
			barra.remove();
		}
		if ( campoTitulo ) {
			campoTitulo.remove();
		}

		barra = null;
		botoesBarra = null;
		campoTitulo = null;
		titulo.hidden = false;
		acoes.hidden = false;
	}

	/**
	 * Diz se há alteração ainda não gravada.
	 *
	 * @return {boolean} Se há.
	 */
	function haPendencia() {
		if ( ! estado.editor ) {
			return false;
		}

		return estado.editor.isDirty() || ( campoTitulo && campoTitulo.value !== estado.titulo );
	}

	/**
	 * Entra no modo de edição.
	 */
	function editar() {
		if ( estado.editor ) {
			return;
		}

		botaoEditar.disabled = true;
		alertar( '' );
		anunciar( t.carregando );

		carregarTinymce().then( function ( tinymce ) {
			var vazio = corpo.querySelector( '.rc-incubadora__vazio' );

			estado.leitura = corpo.innerHTML;
			estado.vazio = vazio ? vazio.outerHTML : estado.vazio;

			if ( vazio ) {
				vazio.remove();
			}

			facadesParaIframes( corpo );

			var ferramentas = montarInterface();

			return tinymce.init( {
				target: corpo,
				inline: true,
				base_url: dados.tinymce,
				suffix: '.min',
				license_key: 'gpl',
				language: 'pt-BR',
				promotion: false,
				branding: false,
				menubar: false,
				fixed_toolbar_container_target: ferramentas,
				toolbar_persist: true,
				toolbar_mode: 'wrap',
				plugins: 'lists link media table autolink emoticons image',
				toolbar: 'blocks | bold italic underline rccodigo | bullist numlist | alignleft aligncenter alignright | forecolor | table link image rcanexar rcvideo rcmetadados emoticons | undo redo',
				block_formats: t.formatoParagrafo + '=p; ' + t.formatoTitulo2 + '=h2; ' + t.formatoTitulo3 + '=h3; ' + t.formatoTitulo4 + '=h4; ' + t.formatoCodigo + '=pre',
				color_map: CORES,
				color_cols: 3,
				custom_colors: false,
				valid_styles: { '*': 'color,text-align,width' },
				table_default_attributes: {},
				table_default_styles: { width: '100%' },
				table_header_type: 'cells',
				link_default_target: '',
				link_assume_external_targets: 'https',
				link_target_list: false,
				relative_urls: false,
				remove_script_host: true,
				convert_urls: true,
				// Colar e arrastar imagem envia na hora, pela mesma rota do
				// botão. `blob:` nunca chega ao servidor: `salvar()` espera os
				// envios pendentes antes de ler o conteúdo.
				paste_data_images: true,
				automatic_uploads: true,
				images_upload_handler: receberImagem,
				images_file_types: 'jpeg,jpg,png,gif,webp',
				images_reuse_filename: false,
				// A janela de imagem fica para o texto alternativo; a aba de
				// upload dela passa pelo mesmo `receberImagem`.
				image_uploadtab: true,
				image_dimensions: false,
				image_description: true,
				image_title: false,
				a11y_advanced_options: true,
				emoticons_database: 'emojis',
				media_live_embeds: true,
				sandbox_iframes: true,
				sandbox_iframes_exclusions: [ 'youtube-nocookie.com', 'player.vimeo.com' ],
				iframe_aria_text: t.corpoRotulo,
				setup: registrarBotoes
			} );
		} ).then( function ( editores ) {
			if ( ! editores || ! editores[ 0 ] ) {
				throw new Error( 'tinymce' );
			}

			estado.editor = editores[ 0 ];
			estado.editor.getBody().setAttribute( 'aria-label', t.corpoRotulo );
			estado.editor.setDirty( false );
			anunciar( t.editando );

			// Página recém-criada: o título provisório é o primeiro a trocar.
			if ( estado.focarTitulo && campoTitulo ) {
				estado.focarTitulo = false;
				campoTitulo.focus();
				campoTitulo.select();
				return;
			}

			estado.editor.focus();
		} ).catch( function () {
			desmontarInterface();
			corpo.innerHTML = estado.leitura;
			document.dispatchEvent( new CustomEvent( 'rc-incubadora:atualizada' ) );
			botaoEditar.disabled = false;
			anunciar( '' );
			alertar( t.falhaCarregar );
		} );
	}

	/**
	 * Sai do modo de edição, devolvendo a tela de leitura.
	 *
	 * O corpo volta ao HTML de leitura **do servidor** — o da última gravação,
	 * ou o de quando a edição começou —, e não ao HTML do editor: este tem
	 * iframes vivos, e a leitura tem facades.
	 */
	function fechar() {
		if ( ! estado.editor || estado.salvando ) {
			return;
		}

		if ( haPendencia() && ! window.confirm( t.descartar ) ) {
			return;
		}

		// No modo `inline`, `remove()` devolve ao elemento o conteúdo
		// serializado: os iframes renasceriam por um instante, o bastante para
		// o navegador chamar o provedor. Esvaziado antes, não há o que devolver.
		corpo.innerHTML = '';
		estado.editor.remove();
		estado.editor = null;

		corpo.removeAttribute( 'contenteditable' );
		corpo.innerHTML = estado.leitura;

		desmontarInterface();
		alertar( '' );
		anunciar( t.fechado );
		document.dispatchEvent( new CustomEvent( 'rc-incubadora:atualizada' ) );

		botaoEditar.disabled = false;
		botaoEditar.focus();
	}

	/* ---------------------------------------------------------------------
	 * Gravação
	 * ------------------------------------------------------------------ */

	/**
	 * Leva à tela o estado que o servidor devolveu.
	 *
	 * @param {Object} resposta `data` da resposta de sucesso.
	 */
	function aplicarEstado( resposta ) {
		var publicadaAgora = 'publish' === resposta.status && 'publish' !== estado.status;
		var nomeAnterior = titulo.textContent;

		estado.titulo = resposta.titulo;
		estado.status = resposta.status;
		estado.modificado = resposta.modificado;
		estado.leitura = resposta.html ? resposta.html : estado.vazio;

		var nome = '' !== resposta.titulo ? resposta.titulo : t.semTitulo;

		titulo.textContent = nome;
		// Troca o nome dentro do título da aba, sem supor qual separador o
		// tema usa antes do nome do site.
		if ( nomeAnterior && document.title.indexOf( nomeAnterior ) > -1 ) {
			document.title = document.title.replace( nomeAnterior, nome );
		}

		var noAtual = document.querySelector( '.rc-incubadora__arvore [aria-current="page"]' );
		var tituloNoAtual = noAtual && noAtual.querySelector( '.rc-incubadora__no-titulo' );
		var trilhaAtual = document.querySelector( '.rc-incubadora__trilha span[aria-current="page"]' );

		if ( tituloNoAtual ) {
			tituloNoAtual.textContent = nome;
		}
		if ( noAtual && resposta.url ) {
			noAtual.setAttribute( 'href', resposta.url );
		}
		if ( trilhaAtual ) {
			trilhaAtual.textContent = nome;
		}

		// A primeira publicação refaz o slug a partir do título: o endereço
		// da barra passaria a apontar para um que não existe mais.
		if ( resposta.url && window.history && window.history.replaceState ) {
			window.history.replaceState( null, '', resposta.url );
		}

		var tempo = pagina.querySelector( '.rc-incubadora__editado' );

		// Um "sem alterações" num rascunho que nunca foi salvo devolve a data
		// GMT zerada que o `wp_insert_post()` grava, e o ano 0 é uma data
		// válida para o `Date.parse()`: "Editado há 2026 anos".
		if ( tempo && ! /^0000/.test( resposta.modificado ) ) {
			tempo.setAttribute( 'datetime', iso( resposta.modificado ) );
			tempo.setAttribute( 'title', resposta.modificado_local );
		}

		var ultima = pagina.querySelector( '.rc-incubadora__ultima-edicao' );

		if ( ultima ) {
			ultima.textContent = ( ultima.getAttribute( 'data-rc-modelo' ) || '%1$s, %2$s' )
				.replace( '%1$s', resposta.modificado_local )
				.replace( '%2$s', resposta.editor );
		}

		if ( publicadaAgora ) {
			var selo = pagina.querySelector( '.rc-incubadora__selo--destaque' );

			if ( selo ) {
				selo.remove();
			}
		}

		if ( botoesBarra ) {
			desenharBotoes();
		}

		document.dispatchEvent( new CustomEvent( 'rc-incubadora:atualizada', { detail: { agora: iso( resposta.agora ) } } ) );
	}

	/**
	 * Trata uma resposta de erro do servidor.
	 *
	 * @param {Object}  erro     `data` da resposta de erro.
	 * @param {boolean} publicar Se a tentativa era de publicação.
	 */
	function tratarErro( erro, publicar ) {
		if ( 'conflito' === erro.codigo ) {
			var botao = criarBotao( t.sobrescrever, '', function () {
				alertar( '' );
				salvar( publicar, true );
			} );

			alertar( erro.mensagem, null, botao );
			botao.focus();
			return;
		}

		alertar( erro.mensagem || t.erroResposta );
	}

	/**
	 * Grava título e corpo.
	 *
	 * O corpo vai como o TinyMCE o serializa; o servidor reconstrói. No
	 * cliente, `getAttribute( 'action' )` e nunca `form.action` — mas aqui
	 * nem há formulário: o corpo é montado em `FormData`.
	 *
	 * @param {boolean} publicar Publica um rascunho.
	 * @param {boolean} forcar   Grava por cima de uma versão mais nova.
	 */
	function salvar( publicar, forcar ) {
		if ( ! estado.editor || estado.salvando ) {
			return;
		}

		ocupar( true );
		alertar( '' );
		anunciar( t.salvando );

		// Uma imagem colada há um instante ainda pode estar em `blob:`. Ler o
		// conteúdo antes de os envios terminarem gravaria um endereço que só
		// existe nesta aba — e o servidor o apagaria, com aviso, sem a pessoa
		// ter feito nada de errado. Envio que falhou já saiu do editor.
		estado.editor.uploadImages().catch( function () {
			return null;
		} ).then( function () {
			var corpoEnvio = new FormData();

			corpoEnvio.append( 'action', dados.acao );
			corpoEnvio.append( '_wpnonce', dados.nonce );
			corpoEnvio.append( 'pagina', String( dados.pagina ) );
			corpoEnvio.append( 'titulo', campoTitulo.value );
			corpoEnvio.append( 'conteudo', estado.editor.getContent() );
			corpoEnvio.append( 'modificado', estado.modificado );

			if ( publicar ) {
				corpoEnvio.append( 'publicar', '1' );
			}
			if ( forcar ) {
				corpoEnvio.append( 'forcar', '1' );
			}

			anunciar( t.salvando );

			return fetch( dados.rota, {
				method: 'POST',
				body: corpoEnvio,
				credentials: 'same-origin'
			} );
		} ).then( function ( resposta ) {
			return resposta.json().catch( function () {
				return { success: false, data: { codigo: 'resposta', mensagem: t.erroResposta } };
			} );
		}, function () {
			return { success: false, data: { codigo: 'rede', mensagem: t.erroRede } };
		} ).then( function ( json ) {
			ocupar( false );

			if ( ! json || ! json.data ) {
				anunciar( '' );
				alertar( t.erroResposta );
				return;
			}

			if ( ! json.success ) {
				anunciar( '' );
				tratarErro( json.data, publicar );
				return;
			}

			var resposta = json.data;

			aplicarEstado( resposta );

			if ( campoTitulo ) {
				campoTitulo.value = resposta.titulo;
				ajustarTitulo();
			}

			// O servidor mudou alguma coisa: o editor passa a mostrar o que
			// foi de fato gravado, para a pessoa não seguir editando sobre
			// um conteúdo que não existe.
			if ( resposta.avisos && resposta.avisos.length ) {
				estado.editor.setContent( paraEdicao( resposta.html ) );
				alertar( t.avisos, resposta.avisos );
			}

			estado.editor.setDirty( false );

			anunciar( {
				publicada: t.publicada,
				sem_alteracoes: t.semAlteracoes
			}[ resposta.codigo ] || t.salva );
		} );
	}

	/* ---------------------------------------------------------------------
	 * Arranque
	 * ------------------------------------------------------------------ */

	window.addEventListener( 'beforeunload', function ( evento ) {
		if ( haPendencia() ) {
			evento.preventDefault();
			evento.returnValue = '';
		}
	} );

	botaoEditar.hidden = false;
	botaoEditar.addEventListener( 'click', editar );

	/*
	 * `?editar=1` é como a árvore entrega a página que acabou de criar: abre já
	 * no editor. O parâmetro sai do endereço antes de abrir, para que recarregar
	 * ou copiar o link não reabra a edição.
	 */
	var endereco = new URL( window.location.href );

	if ( '1' === endereco.searchParams.get( 'editar' ) ) {
		endereco.searchParams.delete( 'editar' );
		window.history.replaceState( window.history.state, '', endereco.pathname + endereco.search + endereco.hash );
		estado.focarTitulo = true;
		editar();
	}
}() );
