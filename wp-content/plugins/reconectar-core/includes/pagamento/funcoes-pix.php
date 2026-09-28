<?php
/**
 * Montagem do BR Code — o "PIX copia e cola".
 *
 * É cálculo puro: estrutura TLV do padrão EMV® MPM mais um CRC16, sem
 * dependência externa, sem etapa de compilação e sem chamada de rede. Essas
 * três ausências são o que permite que o recurso exista neste projeto.
 *
 * O que **não** está aqui é o QR Code em imagem: desenhá-lo exigiria uma
 * biblioteca nova, e o copia-e-cola resolve o caso de quem paga pelo celular,
 * que é o caso real.
 *
 * @package reconectar-core
 */

defined( 'ABSPATH' ) || exit;

/**
 * Codifica um campo no formato TLV do EMV MPM.
 *
 * O comprimento é sempre dois dígitos com zero à esquerda — `sprintf( '%02d' )`
 * e não `strlen()` cru, porque um campo de 5 caracteres precisa sair como "05"
 * e não como "5". Um único dígito a menos desalinha todo o resto da cadeia, e o
 * app do banco recusa o código sem dizer onde.
 *
 * O comprimento conta **bytes**, não caracteres: `strlen()` é o certo aqui, e
 * `mb_strlen()` seria o erro. Os valores já chegam em ASCII por
 * `reconectar_pix_texto_ascii()`, então as duas contas coincidem — mas a
 * coincidência é consequência da limpeza anterior, não garantia desta função.
 *
 * @param string $id    Identificador de dois dígitos do campo.
 * @param string $valor Conteúdo do campo.
 * @return string Campo pronto, ou string vazia quando não há conteúdo.
 */
function reconectar_pix_campo( $id, $valor ) {
	if ( '' === $valor || null === $valor ) {
		return '';
	}

	return $id . sprintf( '%02d', strlen( $valor ) ) . $valor;
}

/**
 * Reduz um texto ao que o BR Code aceita.
 *
 * O padrão admite apenas caracteres imprimíveis de ASCII nos campos de nome e
 * cidade. "João" ou "São Paulo" escritos como estão ocupam mais bytes do que
 * letras — o acento é multibyte em UTF-8 — e o comprimento declarado no TLV
 * deixa de bater com o conteúdo. O resultado é um código que o app recusa, e
 * a mensagem do banco não aponta o campo.
 *
 * `remove_accents()` é do WordPress e resolve o caso brasileiro inteiro; o
 * `preg_replace` depois dela é a rede de segurança para o que sobrar.
 *
 * @param string $texto  Texto de origem.
 * @param int    $limite Comprimento máximo em caracteres.
 * @return string Texto em ASCII imprimível, truncado ao limite.
 */
function reconectar_pix_texto_ascii( $texto, $limite ) {
	$texto = remove_accents( (string) $texto );
	$texto = preg_replace( '/[^\x20-\x7E]/', '', $texto );
	$texto = trim( preg_replace( '/\s+/', ' ', $texto ) );

	return substr( $texto, 0, $limite );
}

/**
 * Calcula o CRC16 do BR Code.
 *
 * É a variante **CCITT-FALSE**: polinômio `0x1021`, valor inicial `0xFFFF`, sem
 * reflexão de entrada nem de saída e sem XOR final. As outras variantes de
 * CRC16 com o mesmo polinômio — a ARC, a XMODEM — produzem números diferentes
 * para a mesma entrada, e o padrão do BACEN exige esta. Trocar a inicialização
 * por `0x0000` gera um código de aparência idêntica que nenhum banco aceita.
 *
 * O `& 0xFFFF` dentro do laço não é zelo excessivo: em PHP o inteiro tem 64
 * bits, e sem o corte o deslocamento à esquerda acumularia bits acima do
 * décimo sexto, que deveriam ter transbordado.
 *
 * @param string $dados Cadeia do BR Code até "6304", inclusive.
 * @return string Quatro dígitos hexadecimais maiúsculos.
 */
function reconectar_pix_crc16( $dados ) {
	$crc = 0xFFFF;

	for ( $i = 0, $n = strlen( $dados ); $i < $n; $i++ ) {
		$crc ^= ord( $dados[ $i ] ) << 8;

		for ( $bit = 0; $bit < 8; $bit++ ) {
			if ( $crc & 0x8000 ) {
				$crc = ( ( $crc << 1 ) ^ 0x1021 ) & 0xFFFF;
			} else {
				$crc = ( $crc << 1 ) & 0xFFFF;
			}
		}
	}

	return strtoupper( sprintf( '%04X', $crc ) );
}

/**
 * Monta o BR Code estático de uma cobrança.
 *
 * Devolve `''` — e não um código incompleto — quando falta chave, nome ou
 * cidade. Um BR Code malformado é pior que nenhum: ele parece funcionar, o
 * comprador copia, cola no banco e recebe uma recusa genérica, sem saber que o
 * problema está na loja. Sem código, a tela mostra os dados em texto e o
 * comprador transfere à mão.
 *
 * O valor entra com ponto decimal e duas casas, e **sem** separador de milhar:
 * "1250.00", nunca "1.250,00". O padrão exige o ponto como separador decimal, e
 * `number_format()` com os quatro argumentos é o que garante isso independente
 * do locale do servidor — `number_format( $v, 2 )` sozinho já formata em
 * pt-BR em algumas configurações.
 *
 * @param string $chave  Chave PIX do recebedor.
 * @param string $nome   Nome do beneficiário.
 * @param string $cidade Cidade do beneficiário.
 * @param float  $valor  Valor da cobrança. Zero omite o campo.
 * @param string $txid   Identificador da transação. Vazio vira "***".
 * @return string BR Code pronto para copiar, ou string vazia.
 */
function reconectar_pix_br_code( $chave, $nome, $cidade, $valor = 0, $txid = '' ) {
	$chave  = trim( (string) $chave );
	$nome   = reconectar_pix_texto_ascii( $nome, 25 );
	$cidade = reconectar_pix_texto_ascii( $cidade, 15 );

	if ( '' === $chave || '' === $nome || '' === $cidade ) {
		return '';
	}

	// O identificador aceita letras e números; "***" é o valor que o padrão
	// reserva para "sem identificador", e é o que vale numa cobrança estática.
	$txid = preg_replace( '/[^A-Za-z0-9]/', '', (string) $txid );
	$txid = '' === $txid ? '***' : substr( $txid, 0, 25 );

	/*
	 * A GUI vai em **minúsculas**. O padrão manda compará-la sem diferenciar
	 * caixa, então "BR.GOV.BCB.PIX" também é aceito pelos bancos — mas o CRC
	 * não perdoa: são bytes diferentes e dão hash diferente. Escrever em
	 * maiúsculas faz o código continuar válido e deixa de bater com o payload
	 * de referência publicado pelo BACEN, que é justamente o que se usa para
	 * conferir esta implementação. Foi assim que a conferência daqui divergiu
	 * na primeira tentativa.
	 */
	$conta = reconectar_pix_campo( '00', 'br.gov.bcb.pix' )
		. reconectar_pix_campo( '01', $chave );

	$codigo = reconectar_pix_campo( '00', '01' )
		. reconectar_pix_campo( '26', $conta )
		. reconectar_pix_campo( '52', '0000' )
		. reconectar_pix_campo( '53', '986' );

	if ( $valor > 0 ) {
		$codigo .= reconectar_pix_campo( '54', number_format( (float) $valor, 2, '.', '' ) );
	}

	$codigo .= reconectar_pix_campo( '58', 'BR' )
		. reconectar_pix_campo( '59', $nome )
		. reconectar_pix_campo( '60', $cidade )
		. reconectar_pix_campo( '62', reconectar_pix_campo( '05', $txid ) );

	// O "6304" entra no cálculo do próprio CRC: o padrão manda incluir o
	// identificador e o comprimento do campo 63 nos dados verificados, e só o
	// valor de quatro dígitos é que fica de fora. Calcular sem ele produz um
	// número plausível e errado.
	$codigo .= '6304';

	return $codigo . reconectar_pix_crc16( $codigo );
}
