/**
 * Máscaras dos campos cadastrais do Painel de Empresas.
 *
 * Conforto de digitação, nunca controle: quem decide o que entra na meta é
 * `Reconectar_Empresa::normalizar_campo()`, no servidor. Este arquivo pode falhar
 * em carregar, ser bloqueado por extensão ou encontrar o JS desligado, e o
 * cadastro continua correto — só menos confortável.
 *
 * As regras são lidas do atributo `data-rc-mascara`, que o formulário imprime a
 * partir da mesma `campos()` consultada pelo normalizador do servidor. Dois
 * lugares descrevendo o formato do CNPJ é como a tela e a gravação divergem.
 *
 * @package reconectar-core
 */

( function () {
	'use strict';

	/**
	 * Formatadores, por nome de máscara.
	 *
	 * Cada um recebe o que já foi digitado e devolve o texto formatado. São
	 * progressivos de propósito: precisam produzir algo legível a cada tecla, e
	 * não só quando o campo está completo.
	 */
	var MASCARAS = {
		/**
		 * 00.000.000/0001-91
		 *
		 * @param {string} valor Texto digitado.
		 * @return {string} Texto formatado.
		 */
		cnpj: function ( valor ) {
			var d = valor.replace( /\D/g, '' ).slice( 0, 14 );

			if ( d.length > 12 ) {
				return d.replace( /^(\d{2})(\d{3})(\d{3})(\d{4})(\d{1,2})$/, '$1.$2.$3/$4-$5' );
			}

			if ( d.length > 8 ) {
				return d.replace( /^(\d{2})(\d{3})(\d{3})(\d{1,4})$/, '$1.$2.$3/$4' );
			}

			if ( d.length > 5 ) {
				return d.replace( /^(\d{2})(\d{3})(\d{1,3})$/, '$1.$2.$3' );
			}

			if ( d.length > 2 ) {
				return d.replace( /^(\d{2})(\d{1,3})$/, '$1.$2' );
			}

			return d;
		},

		/**
		 * (82) 90000-0000, ou (82) 3000-0000 no fixo.
		 *
		 * O hífen só entra a partir do sexto dígito porque antes disso não há como
		 * saber se o número terá oito ou nove: pontuar cedo faria a pontuação
		 * pular de lugar enquanto o usuário digita.
		 *
		 * @param {string} valor Texto digitado.
		 * @return {string} Texto formatado.
		 */
		telefone: function ( valor ) {
			var d = valor.replace( /\D/g, '' ).slice( 0, 11 );

			if ( d.length > 10 ) {
				return d.replace( /^(\d{2})(\d{5})(\d{1,4})$/, '($1) $2-$3' );
			}

			if ( d.length > 6 ) {
				return d.replace( /^(\d{2})(\d{4})(\d{1,4})$/, '($1) $2-$3' );
			}

			if ( d.length > 2 ) {
				return d.replace( /^(\d{2})(\d{1,5})$/, '($1) $2' );
			}

			if ( d.length > 0 ) {
				return '(' + d;
			}

			return d;
		},

		/**
		 * AL
		 *
		 * @param {string} valor Texto digitado.
		 * @return {string} Texto formatado.
		 */
		uf: function ( valor ) {
			return valor.replace( /[^A-Za-z]/g, '' ).toUpperCase().slice( 0, 2 );
		},
	};

	/**
	 * O CNPJ passa nos dois dígitos verificadores?
	 *
	 * Mesmo algoritmo de `Reconectar_Empresa::cnpj_e_valido()`. A duplicação é
	 * deliberada e tem limite claro: aqui ela serve para avisar antes do envio,
	 * lá para decidir. Se as duas divergirem, quem vale é a do servidor — no pior
	 * caso o usuário recebe a recusa um passo depois, que é o comportamento de
	 * quem está com o JS desligado de qualquer forma.
	 *
	 * @param {string} digitos Somente dígitos.
	 * @return {boolean} Verdadeiro quando os verificadores conferem.
	 */
	function cnpjEhValido( digitos ) {
		if ( digitos.length !== 14 || /^(\d)\1{13}$/.test( digitos ) ) {
			return false;
		}

		var posicoes = [ 12, 13 ];

		for ( var p = 0; p < posicoes.length; p++ ) {
			var limite = posicoes[ p ];
			var peso = 2;
			var soma = 0;

			for ( var i = limite - 1; i >= 0; i-- ) {
				soma += parseInt( digitos.charAt( i ), 10 ) * peso;
				peso = peso === 9 ? 2 : peso + 1;
			}

			var resto = soma % 11;
			var esperado = resto < 2 ? 0 : 11 - resto;

			if ( parseInt( digitos.charAt( limite ), 10 ) !== esperado ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Valida o campo e publica a mensagem na validação nativa do navegador.
	 *
	 * `setCustomValidity()` em vez de mensagem própria: a bolha nativa já é
	 * anunciada por leitor de tela, move o foco para o campo e é traduzida pelo
	 * navegador. Reimplementar isso em HTML daria mais trabalho e menos
	 * acessibilidade.
	 *
	 * Campo vazio nunca é invalidado aqui — só o nome da empresa é obrigatório, e
	 * marcar o resto como erro contrariaria a regra do servidor.
	 *
	 * @param {HTMLInputElement} campo Campo a validar.
	 * @return {void}
	 */
	function validar( campo ) {
		var valor = campo.value.trim();

		if ( '' === valor ) {
			campo.setCustomValidity( '' );
			return;
		}

		var mascara = campo.getAttribute( 'data-rc-mascara' );
		var digitos = valor.replace( /\D/g, '' );
		var mensagem = '';

		if ( 'cnpj' === mascara && ! cnpjEhValido( digitos ) ) {
			mensagem = 'CNPJ inválido. Confira os 14 dígitos.';
		}

		if ( 'telefone' === mascara && digitos.length !== 10 && digitos.length !== 11 ) {
			mensagem = 'Informe DDD e número: 10 dígitos para fixo, 11 para celular.';
		}

		if ( 'uf' === mascara && valor.length !== 2 ) {
			mensagem = 'Use a sigla de duas letras do estado, como AL.';
		}

		campo.setCustomValidity( mensagem );
	}

	/**
	 * Aplica a máscara preservando a posição do cursor.
	 *
	 * Reescrever `value` joga o cursor para o fim. Sem esta correção, editar um
	 * dígito no meio de um CNPJ já preenchido seria impossível: cada tecla
	 * arremessaria o cursor para a última posição. A âncora é a contagem de
	 * dígitos à esquerda do cursor, e não o índice do caractere, porque a
	 * pontuação inserida desloca o índice e não desloca os dígitos.
	 *
	 * @param {HTMLInputElement} campo    Campo mascarado.
	 * @param {Function}         formatar Formatador correspondente.
	 * @return {void}
	 */
	function aplicar( campo, formatar ) {
		var selecao = campo.selectionStart;
		var antes = campo.value;
		var digitosAEsquerda = antes.slice( 0, selecao ).replace( /[^0-9A-Za-z]/g, '' ).length;
		var depois = formatar( antes );

		if ( depois === antes ) {
			validar( campo );
			return;
		}

		campo.value = depois;

		// Reposiciona depois do mesmo número de caracteres significativos.
		var contados = 0;
		var posicao = depois.length;

		for ( var i = 0; i < depois.length; i++ ) {
			if ( /[0-9A-Za-z]/.test( depois.charAt( i ) ) ) {
				contados++;
			}

			if ( contados >= digitosAEsquerda ) {
				posicao = i + 1;
				break;
			}
		}

		if ( 0 === digitosAEsquerda ) {
			posicao = 0;
		}

		try {
			campo.setSelectionRange( posicao, posicao );
		} catch ( erro ) {
			// `setSelectionRange` lança em tipos de campo que não têm seleção de
			// texto (`email`, por exemplo, em parte dos navegadores). A máscara já
			// foi aplicada; só o cursor fica no fim.
		}

		validar( campo );
	}

	/**
	 * Liga as máscaras aos campos que as declaram.
	 *
	 * @return {void}
	 */
	function iniciar() {
		var campos = document.querySelectorAll( '[data-rc-mascara]' );

		Array.prototype.forEach.call( campos, function ( campo ) {
			var formatar = MASCARAS[ campo.getAttribute( 'data-rc-mascara' ) ];

			if ( ! formatar ) {
				return;
			}

			// Valor que veio do banco ou de um envio recusado também passa pela
			// máscara: um cadastro antigo, gravado antes desta validação existir,
			// abriria a tela com pontuação diferente da que o campo produz agora.
			if ( '' !== campo.value ) {
				campo.value = formatar( campo.value );
				validar( campo );
			}

			campo.addEventListener( 'input', function () {
				aplicar( campo, formatar );
			} );

			// A mensagem some assim que o usuário começa a corrigir; mantê-la
			// deixaria o campo marcado como inválido enquanto ele digita a correção.
			campo.addEventListener( 'blur', function () {
				validar( campo );
			} );
		} );
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', iniciar );
	} else {
		iniciar();
	}
} )();
