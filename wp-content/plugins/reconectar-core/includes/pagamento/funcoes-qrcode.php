<?php
/**
 * Desenho do QR Code do BR Code, em SVG.
 *
 * Implementação autoral do codificador do ISO/IEC 18004, pelas mesmas três
 * ausências que `funcoes-pix.php` declara: sem dependência externa, sem etapa de
 * compilação e sem chamada de rede. Um gerador em JavaScript exigiria versionar
 * biblioteca de terceiros; um serviço de imagem exigiria mandar o payload pela
 * rede — e o payload contém a chave PIX, que costuma ser o CPF de uma pessoa.
 *
 * **A saída é SVG embutido no HTML, nunca `<img src>`.** Um `src` com o payload
 * em query string publicaria a chave na URL, que vai para o log do servidor, para
 * o histórico do navegador e para o cabeçalho `Referer` de qualquer link clicado
 * depois. `data:` resolveria a URL e ainda assim seria bloqueado como imagem
 * externa por vários clientes de e-mail. SVG embutido não vai a lugar nenhum.
 *
 * **Escopo deliberadamente estreito.** Só modo byte, só correção de erro M, só
 * versões 1 a 15. É o que um BR Code estático precisa e nada além:
 *
 * - **Modo byte**, porque o modo alfanumérico do QR não tem letras minúsculas e
 *   o payload começa com `br.gov.bcb.pix`. Tentar alfanumérico produziria um
 *   código que nenhum leitor decodifica de volta ao texto original.
 * - **Correção M** (recupera ~15%), o meio-termo que o BACEN recomenda para
 *   cobrança estática: L falha em tela suja ou foto torta, e Q/H gastariam
 *   módulos a mais para um código que é lido a 20 cm de distância.
 * - **Versões 1 a 15**, cujo teto em M é 415 bytes. O BR Code mais longo que este
 *   projeto produz — chave aleatória de 36 caracteres, nome e cidade no limite —
 *   mede menos de 200. Suportar até a versão 40 seria triplicar as tabelas para
 *   uma faixa que nenhum payload alcança.
 *
 * @package reconectar-core
 */

defined( 'ABSPATH' ) || exit;

/**
 * Nível de correção de erro usado em todo o arquivo.
 *
 * Os dois bits que o vão para a informação de formato. Ao contrário do que a
 * ordem L→M→Q→H sugere, a codificação **não** é sequencial: L é `01`, M é `00`,
 * Q é `11` e H é `10`. Trocar por `01` gera um código de aparência perfeita que
 * todo leitor recusa, porque ele passa a tentar corrigir com o polinômio errado.
 */
const RECONECTAR_QR_NIVEL_M = 0b00;

/**
 * Especificação de blocos de cada versão, no nível M.
 *
 * Cada linha é `array( codewords de correção por bloco, blocos do grupo 1,
 * codewords de dados por bloco do grupo 1, blocos do grupo 2, codewords de dados
 * por bloco do grupo 2 )`.
 *
 * O grupo 2 existe porque os codewords de dados raramente dividem o número de
 * blocos por igual: a partir da versão 8 alguns blocos levam um codeword a mais.
 * Ignorar essa assimetria e dividir tudo em partes iguais é o erro que produz um
 * código com CRC certo, tamanho certo e dados embaralhados — o leitor decodifica
 * e devolve lixo, sem nenhum erro que aponte a causa.
 *
 * @return array<int,int[]> Versão => especificação.
 */
function reconectar_qr_especificacoes() {
	return array(
		1  => array( 10, 1, 16, 0, 0 ),
		2  => array( 16, 1, 28, 0, 0 ),
		3  => array( 26, 1, 44, 0, 0 ),
		4  => array( 18, 2, 32, 0, 0 ),
		5  => array( 24, 2, 43, 0, 0 ),
		6  => array( 16, 4, 27, 0, 0 ),
		7  => array( 18, 4, 31, 0, 0 ),
		8  => array( 22, 2, 38, 2, 39 ),
		9  => array( 22, 3, 36, 2, 37 ),
		10 => array( 26, 4, 43, 1, 44 ),
		11 => array( 30, 1, 50, 4, 51 ),
		12 => array( 22, 6, 36, 2, 37 ),
		13 => array( 22, 8, 37, 1, 38 ),
		14 => array( 24, 4, 40, 5, 41 ),
		15 => array( 24, 5, 41, 5, 42 ),
	);
}

/**
 * Coordenadas dos centros dos padrões de alinhamento, por versão.
 *
 * A versão 1 não tem nenhum. As demais combinam cada coordenada com cada outra,
 * descartando as três combinações que cairiam sobre os localizadores dos cantos —
 * desenhar um alinhamento ali destruiria o localizador, e o leitor não encontra
 * mais o código na imagem.
 *
 * @return array<int,int[]> Versão => coordenadas.
 */
function reconectar_qr_coordenadas_de_alinhamento() {
	return array(
		1  => array(),
		2  => array( 6, 18 ),
		3  => array( 6, 22 ),
		4  => array( 6, 26 ),
		5  => array( 6, 30 ),
		6  => array( 6, 34 ),
		7  => array( 6, 22, 38 ),
		8  => array( 6, 24, 42 ),
		9  => array( 6, 26, 46 ),
		10 => array( 6, 28, 50 ),
		11 => array( 6, 30, 54 ),
		12 => array( 6, 32, 58 ),
		13 => array( 6, 34, 62 ),
		14 => array( 6, 26, 46, 66 ),
		15 => array( 6, 26, 48, 70 ),
	);
}

/**
 * Tabelas de logaritmo e antilogaritmo do corpo de Galois GF(256).
 *
 * O corpo é gerado pelo polinômio primitivo `0x11D` (x⁸+x⁴+x³+x²+1), que é o
 * que o ISO/IEC 18004 fixa. Outros polinômios primitivos de grau 8 também geram
 * corpos válidos e produzem correção de erro **incompatível**: o código sai
 * bem-formado e o leitor o rejeita ao conferir os síndromes.
 *
 * Memoizado porque cada QR Code faz centenas de multiplicações, e um pedido com
 * três lojas desenha três códigos na mesma requisição.
 *
 * @return array{0:int[],1:int[]} Antilogaritmos (expoente => valor) e
 *                                logaritmos (valor => expoente).
 */
function reconectar_qr_tabelas_do_corpo() {
	static $tabelas = null;

	if ( null !== $tabelas ) {
		return $tabelas;
	}

	$exp = array_fill( 0, 512, 0 );
	$log = array_fill( 0, 256, 0 );
	$x   = 1;

	for ( $i = 0; $i < 255; $i++ ) {
		$exp[ $i ]   = $x;
		$log[ $x ]   = $i;
		$x         <<= 1;

		if ( $x & 0x100 ) {
			$x ^= 0x11D;
		}
	}

	// A cauda duplicada evita um `% 255` em cada multiplicação: a soma de dois
	// expoentes nunca passa de 508, e o valor já está no índice certo.
	for ( $i = 255; $i < 512; $i++ ) {
		$exp[ $i ] = $exp[ $i - 255 ];
	}

	$tabelas = array( $exp, $log );

	return $tabelas;
}

/**
 * Multiplica dois valores em GF(256).
 *
 * @param int $a Primeiro fator.
 * @param int $b Segundo fator.
 * @return int Produto no corpo.
 */
function reconectar_qr_multiplicar( $a, $b ) {
	if ( 0 === $a || 0 === $b ) {
		return 0;
	}

	list( $exp, $log ) = reconectar_qr_tabelas_do_corpo();

	return $exp[ $log[ $a ] + $log[ $b ] ];
}

/**
 * Monta o polinômio gerador de Reed-Solomon de um grau.
 *
 * É o produto de `(x - α⁰)(x - α¹)…(x - α^(grau-1))` no corpo. Subtração e soma
 * são a mesma operação aqui — o XOR —, e é por isso que o sinal não aparece.
 *
 * @param int $grau Quantidade de codewords de correção.
 * @return int[] Coeficientes, do mais significativo ao termo constante.
 */
function reconectar_qr_polinomio_gerador( $grau ) {
	static $cache = array();

	if ( isset( $cache[ $grau ] ) ) {
		return $cache[ $grau ];
	}

	list( $exp ) = reconectar_qr_tabelas_do_corpo();

	$gerador = array( 1 );

	for ( $i = 0; $i < $grau; $i++ ) {
		$novo = array_fill( 0, count( $gerador ) + 1, 0 );

		/*
		 * O índice 0 é o coeficiente **líder**, e é multiplicar por `x` que o
		 * mantém ali: o termo α^i desce uma posição. Trocar os dois lados produz
		 * o polinômio recíproco, cujo líder deixa de ser 1 — e a divisão sintética
		 * de `reconectar_qr_correcao()`, que conta com o gerador mônico para
		 * zerar o coeficiente da vez, passa a devolver resto errado.
		 *
		 * Medido: os dados continuam decodificando (não são tocados) e **só a
		 * correção sai inválida**, então o código tem tamanho certo, texto certo
		 * e é recusado por qualquer leitor ao conferir os síndromes.
		 */
		foreach ( $gerador as $indice => $coeficiente ) {
			$novo[ $indice ]     ^= $coeficiente;
			$novo[ $indice + 1 ] ^= reconectar_qr_multiplicar( $coeficiente, $exp[ $i ] );
		}

		$gerador = $novo;
	}

	$cache[ $grau ] = $gerador;

	return $gerador;
}

/**
 * Calcula os codewords de correção de erro de um bloco.
 *
 * É a divisão polinomial do bloco deslocado por `$quantidade` posições pelo
 * gerador; o resto é a correção. O laço opera sobre uma cópia estendida em vez
 * de sobre o bloco, porque a divisão consome os coeficientes.
 *
 * @param int[] $bloco      Codewords de dados do bloco.
 * @param int   $quantidade Codewords de correção a produzir.
 * @return int[] Codewords de correção.
 */
function reconectar_qr_correcao( $bloco, $quantidade ) {
	$gerador = reconectar_qr_polinomio_gerador( $quantidade );
	$resto   = array_merge( $bloco, array_fill( 0, $quantidade, 0 ) );
	$total   = count( $bloco );

	for ( $i = 0; $i < $total; $i++ ) {
		$fator = $resto[ $i ];

		if ( 0 === $fator ) {
			continue;
		}

		foreach ( $gerador as $indice => $coeficiente ) {
			$resto[ $i + $indice ] ^= reconectar_qr_multiplicar( $coeficiente, $fator );
		}
	}

	return array_slice( $resto, $total );
}

/**
 * Devolve a menor versão que cabe um texto, ou zero.
 *
 * O cabeçalho do modo byte custa 4 bits de indicador mais a contagem de
 * caracteres, e a contagem muda de tamanho na versão 10: 8 bits até a 9, 16 bits
 * daí em diante. Ignorar o degrau faz o payload estourar por um byte exatamente
 * nas versões grandes — o código sai com um caractere truncado, e o app do banco
 * recusa sem dizer por quê.
 *
 * @param int $bytes Comprimento do texto em bytes.
 * @return int Versão de 1 a 15, ou 0 quando o texto não cabe.
 */
function reconectar_qr_escolher_versao( $bytes ) {
	foreach ( reconectar_qr_especificacoes() as $versao => $especificacao ) {
		list( , $blocos_1, $dados_1, $blocos_2, $dados_2 ) = $especificacao;

		$capacidade = ( $blocos_1 * $dados_1 ) + ( $blocos_2 * $dados_2 );
		$cabecalho  = $versao < 10 ? 12 : 20;

		if ( $bytes + (int) ceil( $cabecalho / 8 ) <= $capacidade ) {
			// A conta acima arredonda o cabeçalho para byte cheio, que é o pior
			// caso: os 4 bits do indicador e o terminador ocupam o resto.
			return $versao;
		}
	}

	return 0;
}

/**
 * Monta a cadeia de codewords de dados de um texto, já com enchimento.
 *
 * @param string $texto  Texto a codificar.
 * @param int    $versao Versão escolhida.
 * @return int[] Codewords de dados, no comprimento exato da versão.
 */
function reconectar_qr_codewords_de_dados( $texto, $versao ) {
	list( , $blocos_1, $dados_1, $blocos_2, $dados_2 ) = reconectar_qr_especificacoes()[ $versao ];

	$capacidade = ( $blocos_1 * $dados_1 ) + ( $blocos_2 * $dados_2 );
	$tamanho    = strlen( $texto );

	$bits = '0100';
	$bits .= str_pad( decbin( $tamanho ), $versao < 10 ? 8 : 16, '0', STR_PAD_LEFT );

	for ( $i = 0; $i < $tamanho; $i++ ) {
		$bits .= str_pad( decbin( ord( $texto[ $i ] ) ), 8, '0', STR_PAD_LEFT );
	}

	// Terminador: até quatro zeros, e menos que isso quando o espaço restante for
	// menor. Escrever os quatro sempre estouraria a capacidade no limite.
	$bits .= str_repeat( '0', min( 4, ( $capacidade * 8 ) - strlen( $bits ) ) );

	// Completa o último codeword. O `% 8` sobre um múltiplo de 8 dá zero, e
	// `str_repeat` com zero devolve string vazia — não há caso especial.
	$bits .= str_repeat( '0', ( 8 - ( strlen( $bits ) % 8 ) ) % 8 );

	$codewords = array();

	foreach ( str_split( $bits, 8 ) as $byte ) {
		$codewords[] = bindec( $byte );
	}

	/*
	 * O enchimento alterna 0xEC e 0x11 — os dois valores que o padrão fixa. Não
	 * é decoração nem aleatoriedade: a alternância espalha módulos claros e
	 * escuros pela região vazia, e um enchimento de zeros produziria um bloco
	 * uniforme que as regras de penalidade de máscara não conseguem quebrar.
	 */
	$enchimento = array( 0xEC, 0x11 );
	$indice     = 0;

	while ( count( $codewords ) < $capacidade ) {
		$codewords[] = $enchimento[ $indice % 2 ];
		++$indice;
	}

	return $codewords;
}

/**
 * Intercala os codewords de dados e de correção na ordem final.
 *
 * A ordem não é "bloco 1 inteiro, bloco 2 inteiro": é o primeiro codeword de
 * cada bloco, depois o segundo de cada bloco, e assim por diante — e só então os
 * de correção, na mesma dança. É isso que faz um arranhão na etiqueta atingir um
 * codeword de cada bloco em vez de destruir um bloco inteiro, que é o limite do
 * que Reed-Solomon recupera.
 *
 * Escrever sequencialmente produz um código que qualquer leitor aceita como bem
 * formado e devolve embaralhado.
 *
 * @param int[] $codewords Codewords de dados da versão.
 * @param int   $versao    Versão.
 * @return int[] Sequência final.
 */
function reconectar_qr_intercalar( $codewords, $versao ) {
	list( $ec_por_bloco, $blocos_1, $dados_1, $blocos_2, $dados_2 ) = reconectar_qr_especificacoes()[ $versao ];

	$blocos    = array();
	$correcoes = array();
	$posicao   = 0;

	foreach ( array( array( $blocos_1, $dados_1 ), array( $blocos_2, $dados_2 ) ) as $grupo ) {
		list( $quantos, $tamanho ) = $grupo;

		for ( $i = 0; $i < $quantos; $i++ ) {
			$bloco       = array_slice( $codewords, $posicao, $tamanho );
			$posicao    += $tamanho;
			$blocos[]    = $bloco;
			$correcoes[] = reconectar_qr_correcao( $bloco, $ec_por_bloco );
		}
	}

	$saida  = array();
	$maximo = max( $dados_1, $dados_2 );

	for ( $i = 0; $i < $maximo; $i++ ) {
		foreach ( $blocos as $bloco ) {
			if ( isset( $bloco[ $i ] ) ) {
				$saida[] = $bloco[ $i ];
			}
		}
	}

	for ( $i = 0; $i < $ec_por_bloco; $i++ ) {
		foreach ( $correcoes as $correcao ) {
			$saida[] = $correcao[ $i ];
		}
	}

	return $saida;
}

/**
 * Calcula os quinze bits da informação de formato.
 *
 * Cinco bits de dados — nível de correção e máscara — protegidos por um código
 * BCH(15,5) e depois combinados por XOR com a máscara fixa `0x5412`. O XOR final
 * existe para que a informação de formato nunca saia toda em zeros, que seria
 * indistinguível de uma área não escrita.
 *
 * @param int $mascara Índice da máscara, de 0 a 7.
 * @return int Quinze bits.
 */
function reconectar_qr_bits_de_formato( $mascara ) {
	$dados = ( RECONECTAR_QR_NIVEL_M << 3 ) | $mascara;
	$bch   = $dados << 10;

	for ( $i = 4; $i >= 0; $i-- ) {
		if ( $bch & ( 1 << ( $i + 10 ) ) ) {
			$bch ^= 0x537 << $i;
		}
	}

	return ( ( $dados << 10 ) | $bch ) ^ 0x5412;
}

/**
 * Calcula os dezoito bits da informação de versão.
 *
 * Só existe da versão 7 em diante: abaixo dela o leitor deduz a versão pelo
 * tamanho da imagem, e o campo não é desenhado. São seis bits de número de
 * versão protegidos por um BCH(18,6), sem XOR final — ao contrário da informação
 * de formato.
 *
 * @param int $versao Versão.
 * @return int Dezoito bits.
 */
function reconectar_qr_bits_de_versao( $versao ) {
	$bch = $versao << 12;

	for ( $i = 5; $i >= 0; $i-- ) {
		if ( $bch & ( 1 << ( $i + 12 ) ) ) {
			$bch ^= 0x1F25 << $i;
		}
	}

	return ( $versao << 12 ) | $bch;
}

/**
 * Desenha os padrões fixos numa matriz vazia.
 *
 * Devolve duas matrizes em paralelo: os módulos e um mapa do que é área de
 * função. O mapa é o que impede a fase de dados de escrever sobre um localizador
 * e a máscara de inverter um módulo de formato — as duas coisas destroem o
 * código de formas que nenhum leitor consegue diagnosticar.
 *
 * @param int $versao Versão.
 * @return array{0:array<int,int[]>,1:array<int,bool[]>} Módulos e reservas.
 */
function reconectar_qr_padroes_fixos( $versao ) {
	$lado     = ( $versao * 4 ) + 17;
	$modulos  = array_fill( 0, $lado, array_fill( 0, $lado, 0 ) );
	$reservas = array_fill( 0, $lado, array_fill( 0, $lado, false ) );

	/**
	 * Escreve um módulo e o marca como área de função.
	 *
	 * @param int $linha  Linha.
	 * @param int $coluna Coluna.
	 * @param int $valor  1 escuro, 0 claro.
	 * @return void
	 */
	$pintar = static function ( $linha, $coluna, $valor ) use ( &$modulos, &$reservas, $lado ) {
		if ( $linha < 0 || $coluna < 0 || $linha >= $lado || $coluna >= $lado ) {
			return;
		}

		$modulos[ $linha ][ $coluna ]  = $valor;
		$reservas[ $linha ][ $coluna ] = true;
	};

	// Localizadores dos três cantos, com o separador claro de um módulo em volta.
	foreach ( array( array( 0, 0 ), array( 0, $lado - 7 ), array( $lado - 7, 0 ) ) as $canto ) {
		list( $topo, $esquerda ) = $canto;

		for ( $linha = -1; $linha <= 7; $linha++ ) {
			for ( $coluna = -1; $coluna <= 7; $coluna++ ) {
				$borda  = 0 === $linha || 6 === $linha || 0 === $coluna || 6 === $coluna;
				$centro = $linha >= 2 && $linha <= 4 && $coluna >= 2 && $coluna <= 4;
				$dentro = $linha >= 0 && $linha <= 6 && $coluna >= 0 && $coluna <= 6;

				$pintar( $topo + $linha, $esquerda + $coluna, $dentro && ( $borda || $centro ) ? 1 : 0 );
			}
		}
	}

	// Linhas de tempo: a sexta linha e a sexta coluna, alternando a partir do
	// escuro. São elas que dão ao leitor a régua para achar o centro de cada
	// módulo numa foto em perspectiva.
	for ( $i = 8; $i < $lado - 8; $i++ ) {
		$valor = 0 === $i % 2 ? 1 : 0;

		$pintar( 6, $i, $valor );
		$pintar( $i, 6, $valor );
	}

	// Alinhamentos, exceto os três que cairiam sobre os localizadores.
	$coordenadas = reconectar_qr_coordenadas_de_alinhamento()[ $versao ];

	foreach ( $coordenadas as $centro_linha ) {
		foreach ( $coordenadas as $centro_coluna ) {
			$sobre_localizador = ( 6 === $centro_linha && 6 === $centro_coluna )
				|| ( 6 === $centro_linha && $centro_coluna === $lado - 7 )
				|| ( $centro_linha === $lado - 7 && 6 === $centro_coluna );

			if ( $sobre_localizador ) {
				continue;
			}

			for ( $linha = -2; $linha <= 2; $linha++ ) {
				for ( $coluna = -2; $coluna <= 2; $coluna++ ) {
					$escuro = 2 === max( abs( $linha ), abs( $coluna ) ) || ( 0 === $linha && 0 === $coluna );

					$pintar( $centro_linha + $linha, $centro_coluna + $coluna, $escuro ? 1 : 0 );
				}
			}
		}
	}

	// Módulo escuro fixo. Ele não carrega informação: existe porque o padrão o
	// exige, e sem ele o leitor não valida a informação de formato ao lado.
	$pintar( $lado - 8, 8, 1 );

	// A informação de formato é reservada agora e escrita depois de a máscara
	// estar escolhida — ela depende do índice da máscara.
	foreach ( reconectar_qr_posicoes_de_formato( $lado ) as $par ) {
		$reservas[ $par[0] ][ $par[1] ] = true;
		$reservas[ $par[2] ][ $par[3] ] = true;
	}

	if ( $versao >= 7 ) {
		$bits = reconectar_qr_bits_de_versao( $versao );

		for ( $i = 0; $i < 18; $i++ ) {
			$valor = ( $bits >> $i ) & 1;

			$pintar( $lado - 11 + ( $i % 3 ), intdiv( $i, 3 ), $valor );
			$pintar( intdiv( $i, 3 ), $lado - 11 + ( $i % 3 ), $valor );
		}
	}

	return array( $modulos, $reservas );
}

/**
 * Devolve, para cada bit de formato, as duas posições que o recebem.
 *
 * A informação de formato é escrita **duas vezes**, em L invertido junto do
 * localizador superior esquerdo e partida entre os outros dois cantos. A
 * duplicação é o que permite ler o código quando um dos cantos está danificado —
 * é a única parte do QR Code que não é protegida por Reed-Solomon.
 *
 * A numeração salta em dois pontos: a coluna 6 da primeira cópia é linha de
 * tempo e é pulada, e a linha 6 também. Escrever sem os saltos põe dois bits
 * sobre a régua do leitor e desloca todos os demais.
 *
 * @param int $lado Lado da matriz.
 * @return array<int,int[]> Lista de `array( linha1, coluna1, linha2, coluna2 )`.
 */
function reconectar_qr_posicoes_de_formato( $lado ) {
	$posicoes = array();

	for ( $i = 0; $i < 15; $i++ ) {
		if ( $i < 6 ) {
			$primeira = array( 8, $i );
		} elseif ( 6 === $i ) {
			$primeira = array( 8, 7 );
		} elseif ( 7 === $i ) {
			$primeira = array( 8, 8 );
		} elseif ( 8 === $i ) {
			$primeira = array( 7, 8 );
		} else {
			$primeira = array( 14 - $i, 8 );
		}

		/*
		 * A segunda cópia é partida em **sete** bits na coluna 8, subindo do canto
		 * inferior esquerdo, e oito na linha 8, à esquerda do canto inferior
		 * direito. Não em oito e sete: o oitavo módulo daquela coluna é o módulo
		 * escuro fixo, em `( $lado - 8, 8 )`.
		 *
		 * Escrever o bit 7 ali destrói o módulo escuro e deixa livre a coluna
		 * `$lado - 8` da linha 8 — e a conferência de ida e volta **não acusa**,
		 * porque o decodificador lê pela mesma tabela errada e concorda. Quem
		 * pegou foi contar os módulos livres da matriz contra a tabela de blocos:
		 * sobrava exatamente um, em todas as quinze versões.
		 */
		if ( $i < 7 ) {
			$segunda = array( $lado - 1 - $i, 8 );
		} else {
			$segunda = array( 8, $lado - 15 + $i );
		}

		$posicoes[] = array( $primeira[0], $primeira[1], $segunda[0], $segunda[1] );
	}

	return $posicoes;
}

/**
 * Distribui os codewords pela matriz, em zigue-zague de duas colunas.
 *
 * O percurso começa no canto inferior direito e sobe; a cada par de colunas ele
 * inverte o sentido. A coluna 6 é pulada por inteiro — é linha de tempo, e
 * incluí-la desloca todo o restante do percurso por um módulo.
 *
 * @param array<int,int[]>  $modulos   Matriz com os padrões fixos, por referência.
 * @param array<int,bool[]> $reservas  Mapa de áreas de função.
 * @param int[]             $codewords Sequência intercalada.
 * @return void
 */
function reconectar_qr_distribuir( &$modulos, $reservas, $codewords ) {
	$lado  = count( $modulos );
	$bits  = '';
	$total = count( $codewords );

	for ( $i = 0; $i < $total; $i++ ) {
		$bits .= str_pad( decbin( $codewords[ $i ] ), 8, '0', STR_PAD_LEFT );
	}

	$posicao   = 0;
	$restantes = strlen( $bits );
	$subindo   = true;

	for ( $coluna = $lado - 1; $coluna > 0; $coluna -= 2 ) {
		if ( 6 === $coluna ) {
			--$coluna;
		}

		for ( $passo = 0; $passo < $lado; $passo++ ) {
			$linha = $subindo ? $lado - 1 - $passo : $passo;

			foreach ( array( $coluna, $coluna - 1 ) as $atual ) {
				if ( $reservas[ $linha ][ $atual ] ) {
					continue;
				}

				/*
				 * Os módulos que sobram depois do último bit ficam claros. Não são
				 * enchimento esquecido: as versões 2 a 6 e 14 a 20 têm de 3 a 7
				 * módulos que nenhum codeword alcança, e o padrão manda deixá-los
				 * em zero.
				 */
				$modulos[ $linha ][ $atual ] = $posicao < $restantes ? (int) $bits[ $posicao ] : 0;
				++$posicao;
			}
		}

		$subindo = ! $subindo;
	}
}

/**
 * Diz se uma máscara inverte um módulo.
 *
 * @param int $mascara Índice da máscara, de 0 a 7.
 * @param int $linha   Linha.
 * @param int $coluna  Coluna.
 * @return bool Se o módulo é invertido.
 */
function reconectar_qr_mascara_inverte( $mascara, $linha, $coluna ) {
	switch ( $mascara ) {
		case 0:
			return 0 === ( $linha + $coluna ) % 2;
		case 1:
			return 0 === $linha % 2;
		case 2:
			return 0 === $coluna % 3;
		case 3:
			return 0 === ( $linha + $coluna ) % 3;
		case 4:
			return 0 === ( intdiv( $linha, 2 ) + intdiv( $coluna, 3 ) ) % 2;
		case 5:
			return 0 === ( ( $linha * $coluna ) % 2 ) + ( ( $linha * $coluna ) % 3 );
		case 6:
			return 0 === ( ( ( $linha * $coluna ) % 2 ) + ( ( $linha * $coluna ) % 3 ) ) % 2;
		default:
			return 0 === ( ( ( $linha + $coluna ) % 2 ) + ( ( $linha * $coluna ) % 3 ) ) % 2;
	}
}

/**
 * Pontua uma matriz mascarada pelas quatro regras de penalidade do padrão.
 *
 * Menor é melhor. As quatro regras medem coisas diferentes, e é a soma que o
 * padrão manda comparar:
 *
 * 1. sequências de cinco ou mais módulos iguais em linha ou coluna, que fazem o
 *    leitor perder a régua;
 * 2. blocos 2×2 de cor uniforme, que confundem a detecção de perspectiva;
 * 3. a proporção 1:1:3:1:1 seguida de quatro módulos claros — o desenho do
 *    localizador aparecendo no meio dos dados, que manda o leitor procurar um
 *    canto onde não há nenhum;
 * 4. desequilíbrio entre claro e escuro, que estreita a margem do limiar de
 *    binarização em foto mal iluminada.
 *
 * Fixar uma máscara em vez de escolher produz um código válido — e é justamente
 * o que se paga com leitura falhando em celular de câmera fraca, que é a metade
 * do público deste projeto.
 *
 * @param array<int,int[]> $modulos Matriz mascarada.
 * @return int Penalidade.
 */
function reconectar_qr_penalidade( $modulos ) {
	$lado  = count( $modulos );
	$total = 0;

	// Regras 1 e 3, sobre linhas e colunas. O mesmo laço serve às duas
	// orientações porque a matriz é quadrada.
	$padrao_a = array( 1, 0, 1, 1, 1, 0, 1, 0, 0, 0, 0 );
	$padrao_b = array( 0, 0, 0, 0, 1, 0, 1, 1, 1, 0, 1 );

	for ( $eixo = 0; $eixo < 2; $eixo++ ) {
		for ( $i = 0; $i < $lado; $i++ ) {
			$linha_atual = array();

			for ( $j = 0; $j < $lado; $j++ ) {
				$linha_atual[] = 0 === $eixo ? $modulos[ $i ][ $j ] : $modulos[ $j ][ $i ];
			}

			$corrida = 1;

			for ( $j = 1; $j < $lado; $j++ ) {
				if ( $linha_atual[ $j ] === $linha_atual[ $j - 1 ] ) {
					++$corrida;

					continue;
				}

				if ( $corrida >= 5 ) {
					$total += 3 + ( $corrida - 5 );
				}

				$corrida = 1;
			}

			if ( $corrida >= 5 ) {
				$total += 3 + ( $corrida - 5 );
			}

			for ( $j = 0; $j + 11 <= $lado; $j++ ) {
				$trecho = array_slice( $linha_atual, $j, 11 );

				if ( $trecho === $padrao_a || $trecho === $padrao_b ) {
					$total += 40;
				}
			}
		}
	}

	// Regra 2: cada quadrado 2×2 uniforme, contados com sobreposição.
	for ( $linha = 0; $linha < $lado - 1; $linha++ ) {
		for ( $coluna = 0; $coluna < $lado - 1; $coluna++ ) {
			$valor = $modulos[ $linha ][ $coluna ];

			if ( $valor === $modulos[ $linha ][ $coluna + 1 ]
				&& $valor === $modulos[ $linha + 1 ][ $coluna ]
				&& $valor === $modulos[ $linha + 1 ][ $coluna + 1 ]
			) {
				$total += 3;
			}
		}
	}

	// Regra 4: dez pontos por cada 5% de afastamento da metade.
	$escuros = 0;

	foreach ( $modulos as $linha_atual ) {
		$escuros += array_sum( $linha_atual );
	}

	$proporcao = ( $escuros * 100 ) / ( $lado * $lado );
	$total    += 10 * (int) floor( abs( $proporcao - 50 ) / 5 );

	return $total;
}

/**
 * Monta a matriz final de um texto: máscara escolhida e formato escrito.
 *
 * @param string $texto Texto a codificar.
 * @return array<int,int[]> Matriz de módulos, ou lista vazia quando não cabe.
 */
function reconectar_qr_matriz( $texto ) {
	$texto = (string) $texto;

	if ( '' === $texto ) {
		return array();
	}

	$versao = reconectar_qr_escolher_versao( strlen( $texto ) );

	if ( ! $versao ) {
		return array();
	}

	$codewords = reconectar_qr_intercalar(
		reconectar_qr_codewords_de_dados( $texto, $versao ),
		$versao
	);

	list( $base, $reservas ) = reconectar_qr_padroes_fixos( $versao );

	reconectar_qr_distribuir( $base, $reservas, $codewords );

	$lado      = count( $base );
	$posicoes  = reconectar_qr_posicoes_de_formato( $lado );
	$melhor    = null;
	$penalidade_menor = null;

	for ( $mascara = 0; $mascara < 8; $mascara++ ) {
		$candidata = $base;

		for ( $linha = 0; $linha < $lado; $linha++ ) {
			for ( $coluna = 0; $coluna < $lado; $coluna++ ) {
				if ( $reservas[ $linha ][ $coluna ] ) {
					continue;
				}

				if ( reconectar_qr_mascara_inverte( $mascara, $linha, $coluna ) ) {
					$candidata[ $linha ][ $coluna ] ^= 1;
				}
			}
		}

		$bits = reconectar_qr_bits_de_formato( $mascara );

		foreach ( $posicoes as $indice => $par ) {
			$valor = ( $bits >> $indice ) & 1;

			$candidata[ $par[0] ][ $par[1] ] = $valor;
			$candidata[ $par[2] ][ $par[3] ] = $valor;
		}

		$penalidade = reconectar_qr_penalidade( $candidata );

		if ( null === $penalidade_menor || $penalidade < $penalidade_menor ) {
			$penalidade_menor = $penalidade;
			$melhor           = $candidata;
		}
	}

	return $melhor;
}

/**
 * Desenha o QR Code de um texto como SVG embutido.
 *
 * A zona de silêncio de quatro módulos vai **dentro** do `viewBox`, e não como
 * margem em CSS: ela é parte do símbolo — sem ela o leitor não separa o código do
 * que houver em volta —, e uma margem declarada em folha de estilo desaparece no
 * momento em que alguém salva a imagem ou a copia para outro lugar.
 *
 * Os módulos saem num único `<path>`, agrupados em corridas horizontais, em vez
 * de um `<rect>` por módulo: a versão 8 tem 2.401 módulos, e dois mil elementos
 * por loja — três lojas num pedido — pesariam mais que o resto da página.
 *
 * `shape-rendering="crispEdges"` evita que o antialiasing do navegador borre a
 * fronteira entre módulos vizinhos. Sem ele, em escala fracionária, a câmera lê
 * meio-tom onde deveria haver preto e branco.
 *
 * @param string $texto   Texto a codificar.
 * @param string $rotulo  Texto alternativo, para quem não vê a imagem.
 * @param array  $atributos Pares atributo => valor acrescentados ao `<svg>`.
 * @return string SVG pronto para imprimir, ou string vazia.
 */
function reconectar_qr_svg( $texto, $rotulo = '', $atributos = array() ) {
	$modulos = reconectar_qr_matriz( $texto );

	if ( ! $modulos ) {
		return '';
	}

	$lado    = count( $modulos );
	$silence = 4;
	$medida  = $lado + ( $silence * 2 );
	$caminho = '';

	foreach ( $modulos as $linha => $colunas ) {
		$coluna = 0;

		while ( $coluna < $lado ) {
			if ( ! $colunas[ $coluna ] ) {
				++$coluna;

				continue;
			}

			$inicio = $coluna;

			while ( $coluna < $lado && $colunas[ $coluna ] ) {
				++$coluna;
			}

			$caminho .= sprintf(
				'M%d %dh%dv1h-%dz',
				$inicio + $silence,
				$linha + $silence,
				$coluna - $inicio,
				$coluna - $inicio
			);
		}
	}

	$extras = '';

	foreach ( $atributos as $nome => $valor ) {
		$extras .= sprintf( ' %s="%s"', esc_attr( $nome ), esc_attr( $valor ) );
	}

	/*
	 * `role="img"` com `<title>` é o par que o leitor de tela anuncia; um
	 * `aria-label` no `<svg>` funciona na maioria dos navegadores e é ignorado
	 * por alguns, e aqui o rótulo é a única forma de saber o que a imagem é.
	 *
	 * O fundo branco é explícito, e não herdado: um QR Code sobre fundo escuro
	 * inverte o contraste e nenhum leitor o decodifica. Quem vier a pôr este
	 * componente num tema escuro precisa que o branco esteja no próprio símbolo.
	 */
	return sprintf(
		'<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 %1$d %1$d" role="img" shape-rendering="crispEdges"%2$s>'
			. '<title>%3$s</title>'
			. '<rect width="%1$d" height="%1$d" fill="#ffffff"/>'
			. '<path d="%4$s" fill="#000000"/>'
			. '</svg>',
		(int) $medida,
		$extras,
		esc_html( '' !== $rotulo ? $rotulo : __( 'QR Code para pagamento por PIX', 'reconectar-core' ) ),
		esc_attr( $caminho )
	);
}
