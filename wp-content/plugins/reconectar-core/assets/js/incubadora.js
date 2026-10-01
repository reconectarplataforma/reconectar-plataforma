/**
 * Tela de leitura da Incubadora.
 *
 * Por enquanto, só uma coisa: trazer o item da página aberta para dentro da
 * árvore lateral no desktop. A árvore é `sticky` com rolagem própria, e numa
 * wiki longa a página aberta pode estar abaixo da altura da janela — a árvore
 * nasceria mostrando o começo da lista, sem o item destacado à vista.
 *
 * Sem `scrollIntoView()`: ele rola **todos** os ancestrais roláveis, inclusive
 * o documento, e a página abriria deslocada para baixo do título.
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
