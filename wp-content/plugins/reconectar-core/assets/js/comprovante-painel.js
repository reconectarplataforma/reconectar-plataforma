/**
 * Coluna e ação de comprovante na lista React de pedidos do painel da loja.
 *
 * Na interface nova do Dokan (`vendor_layout_style = latest`) a lista de
 * pedidos é um `<DataViews namespace="dokan-orders-data-view">`, e os ganchos
 * de template que imprimiam a coluna antiga não disparam nela. O ponto de
 * extensão que sobra são os filtros que o componente aplica a `fields`, `view`
 * e `actions` a cada renderização; o dado de cada linha chega em
 * `rc_comprovante`, que `Reconectar_Comprovante::dados_na_api_do_painel()`
 * acrescenta à resposta de `/dokan/v1/orders`.
 *
 * Sem build e sem dependência além de `wp.hooks` e `wp.element`, que precisam
 * ser as **mesmas** instâncias globais do Dokan: um registro de filtros
 * próprio nunca seria consultado pela tabela.
 */
( function ( wp, textos ) {
	'use strict';

	if ( ! wp || ! wp.hooks || ! wp.element ) {
		return;
	}

	var el = wp.element.createElement;
	var CAMPO = 'rc_comprovante';

	/*
	 * Dois nomes para o mesmo filtro. O `DataViews` que a lista usa hoje vem do
	 * `@wedevs/plugin-ui` e monta `{namespace}_dataviews_{elemento}`; o
	 * `DataViewTable` anterior do Dokan prefixava `dokan_` de novo. Lido no
	 * código da 5.1.3 (a instalada), vale o primeiro — o segundo fica para a
	 * versão que voltar ao componente antigo.
	 * As funções abaixo são idempotentes, então os dois juntos não duplicam
	 * nada.
	 */
	var PREFIXOS = [
		'dokan_orders_data_view_dataviews_',
		'dokan_dokan_orders_data_view_dataviews_',
	];

	/**
	 * Registra um filtro sob os dois nomes possíveis.
	 *
	 * @param {string}   elemento `fields`, `view` ou `actions`.
	 * @param {Function} funcao   Callback do filtro.
	 */
	function filtrar( elemento, funcao ) {
		PREFIXOS.forEach( function ( prefixo ) {
			wp.hooks.addFilter( prefixo + elemento, 'reconectar/comprovante', funcao );
		} );
	}

	/**
	 * Troca o `%s` de uma frase traduzida pelo valor.
	 *
	 * @param {string} frase Frase com um `%s`.
	 * @param {string} valor Valor a inserir.
	 * @return {string} Frase montada.
	 */
	function montar( frase, valor ) {
		return String( frase || '' ).replace( '%s', valor );
	}

	/**
	 * Texto só para leitor de tela.
	 *
	 * As duas classes porque a página mistura dois mundos: `screen-reader-text`
	 * é a do WordPress e do tema, `sr-only` a do Tailwind com que o Dokan veste
	 * a interface nova — qualquer uma que estiver carregada esconde.
	 *
	 * @param {string} texto Conteúdo.
	 * @return {Object} Elemento.
	 */
	function paraLeitor( texto ) {
		return el( 'span', { className: 'screen-reader-text sr-only' }, texto );
	}

	/**
	 * Célula da coluna.
	 *
	 * Os três estados são os da coluna antiga, e o primeiro existe pelo mesmo
	 * motivo: pedido pago por meio que não passa por comprovante não pode
	 * dizer "Não enviado".
	 *
	 * @param {Object} props Props do `DataViews`, com `item`.
	 * @return {Object} Elemento.
	 */
	function celula( props ) {
		var item = props.item || {};
		var dado = item[ CAMPO ];

		if ( ! dado || ! dado.aplica ) {
			return el(
				'span',
				{ className: 'rc-comprovante-coluna' },
				el( 'span', { className: 'rc-comprovante-coluna__vazio', 'aria-hidden': 'true' }, '—' ),
				paraLeitor( textos.naoAplica )
			);
		}

		if ( ! dado.enviado || ! dado.url ) {
			return el(
				'span',
				{ className: 'rc-comprovante-coluna rc-comprovante-coluna__pendente' },
				textos.naoEnviado
			);
		}

		return el(
			'span',
			{ className: 'rc-comprovante-coluna' },
			el(
				'a',
				{
					className: 'rc-comprovante-coluna__link',
					href: dado.url,
					target: '_blank',
					rel: 'noopener',
					// A linha inteira é clicável e leva ao detalhe
					// (`onClickItem`); sem isto o clique no link abriria o
					// comprovante **e** trocaria a página de baixo.
					onClick: function ( evento ) {
						evento.stopPropagation();
					},
				},
				textos.ver,
				paraLeitor( ' ' + montar( textos.verLeitor, item.number || item.id ) )
			)
		);
	}

	filtrar( 'fields', function ( campos ) {
		if ( ! Array.isArray( campos ) ) {
			return campos;
		}

		for ( var i = 0; i < campos.length; i++ ) {
			if ( campos[ i ] && CAMPO === campos[ i ].id ) {
				return campos;
			}
		}

		return campos.concat( [
			{
				id: CAMPO,
				label: textos.titulo,
				enableSorting: false,
				enableHiding: false,
				render: celula,
			},
		] );
	} );

	// A tabela só mostra os campos listados em `view.fields`: declarar o campo
	// sem pô-lo aqui deixa a coluna registrada e invisível, sem erro.
	filtrar( 'view', function ( vista ) {
		if ( ! vista || ! Array.isArray( vista.fields ) || -1 !== vista.fields.indexOf( CAMPO ) ) {
			return vista;
		}

		return Object.assign( {}, vista, { fields: vista.fields.concat( [ CAMPO ] ) } );
	} );

	// A mesma ação no menu "⋮" da linha, que é o que sobra na vista em lista do
	// celular, onde a coluna pode não caber.
	filtrar( 'actions', function ( acoes ) {
		if ( ! Array.isArray( acoes ) ) {
			return acoes;
		}

		for ( var i = 0; i < acoes.length; i++ ) {
			if ( acoes[ i ] && 'rc-ver-comprovante' === acoes[ i ].id ) {
				return acoes;
			}
		}

		return [
			{
				id: 'rc-ver-comprovante',
				label: textos.verAcao,
				isEligible: function ( item ) {
					return !! ( item && item[ CAMPO ] && item[ CAMPO ].url );
				},
				callback: function ( itens ) {
					var item = itens && itens[ 0 ];

					if ( item && item[ CAMPO ] && item[ CAMPO ].url ) {
						window.open( item[ CAMPO ].url, '_blank', 'noopener' );
					}
				},
			},
		].concat( acoes );
	} );
}( window.wp, window.reconectarComprovantePainel || {} ) );
