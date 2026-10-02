<?php
/**
 * A busca na Incubadora: `/incubadora/?q=termo`.
 *
 * O parâmetro é `q`, e não `s`: na âncora, `s` faria o núcleo trocar a página
 * pela busca global do site — que lê produto, loja e post, mas não este tipo,
 * excluído da busca de propósito.
 *
 * A consulta não vai ao banco por `LIKE`. Ela percorre o mesmo
 * `mapa_visivel()` que desenha a árvore, por três razões:
 *
 * - **Visibilidade.** O mapa já é o conjunto que o usuário pode ler —
 *   publicado, mais rascunho para quem edita —, e `alcancavel_pela_raiz()`
 *   tira a filha publicada sob mãe em rascunho, que para o comprador é 404. Um
 *   `LIKE` teria de refazer as duas regras, e a segunda não cabe em SQL.
 * - **Marcação.** `LIKE` no HTML gravado acha `class`, `href` e `data-rc-id`:
 *   buscar "video" traria toda página com vídeo, por um atributo que ninguém lê.
 * - **Acento.** A comparação é sobre texto sem acento e em minúsculas, e
 *   "negocio" acha "negócio". O agrupamento do banco depende da tabela; aqui
 *   não depende de nada.
 *
 * O custo é ler todas as páginas visíveis a cada busca — o que a árvore lateral
 * já faz em toda tela, com o mesmo mapa guardado por requisição.
 *
 * @package reconectar-core
 */

defined( 'ABSPATH' ) || exit;

class Reconectar_Incubadora_Busca {

	/**
	 * Parâmetro de URL com o texto buscado.
	 */
	const PARAM = 'q';

	/**
	 * Parâmetro de URL com a página de resultados.
	 *
	 * `pagina`, e não `paged` nem `page`: os dois são query vars do núcleo, e
	 * na âncora — uma página comum — `page` trocaria a página do post e `paged`
	 * levaria o `redirect_canonical()` a reescrever a URL.
	 */
	const PARAM_PAGINA = 'pagina';

	/**
	 * Mínimo de caracteres do texto buscado.
	 */
	const MINIMO = 3;

	/**
	 * Teto de caracteres do texto buscado.
	 *
	 * O texto volta impresso no campo, no título da aba e na contagem; sem teto,
	 * um link com um parágrafo inteiro no `?q=` vira o título da página.
	 */
	const MAXIMO = 100;

	/**
	 * Teto de termos distintos.
	 *
	 * Cada termo é uma varredura de todas as páginas. Dez cobrem qualquer busca
	 * escrita à mão.
	 */
	const TERMOS_MAXIMOS = 10;

	/**
	 * Resultados por página.
	 */
	const POR_PAGINA = 20;

	/**
	 * Caracteres de contexto antes da primeira ocorrência, no trecho.
	 */
	const CONTEXTO_ANTES = 60;

	/**
	 * Tamanho aproximado do trecho, em caracteres.
	 */
	const TAMANHO_DO_TRECHO = 220;

	/**
	 * Registra os ganchos da busca.
	 *
	 * @return void
	 */
	public static function init() {
		add_filter( 'document_title_parts', array( __CLASS__, 'titulo_do_documento' ) );
	}

	/**
	 * Diz se a requisição corrente é uma busca na âncora.
	 *
	 * Só na âncora: um `?q=` colado no endereço de uma página isolada é
	 * ignorado, e a página sai como sempre.
	 *
	 * @return bool
	 */
	public static function pedida() {
		return isset( $_GET[ self::PARAM ] ) && is_page( Reconectar_Incubadora::SLUG ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- leitura.
	}

	/**
	 * O texto buscado, limpo e cortado no teto.
	 *
	 * @return string
	 */
	public static function texto_pedido() {
		if ( ! isset( $_GET[ self::PARAM ] ) || ! is_string( $_GET[ self::PARAM ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- leitura.
			return '';
		}

		$texto = sanitize_text_field( wp_unslash( $_GET[ self::PARAM ] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- leitura.

		return trim( mb_substr( $texto, 0, self::MAXIMO ) );
	}

	/**
	 * A página de resultados pedida, a partir de 1.
	 *
	 * @return int
	 */
	public static function pagina_pedida() {
		$pagina = isset( $_GET[ self::PARAM_PAGINA ] ) ? absint( $_GET[ self::PARAM_PAGINA ] ) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- leitura.

		return max( 1, $pagina );
	}

	/**
	 * Executa a busca e devolve o que a tela de resultados lê.
	 *
	 * A página pedida é encaixada no intervalo que existe: um `?pagina=99` de
	 * um link antigo, depois de páginas apagadas, mostra a última, e não uma
	 * lista vazia que diria "nenhum resultado" com resultados existindo.
	 *
	 * Guardado por requisição: o `<title>` precisa da página já encaixada, e
	 * ele é montado antes do corpo — sem a guarda, a busca rodaria duas vezes.
	 *
	 * @return array{texto: string, curto: bool, total: int, pagina: int, paginas: int, itens: array}
	 */
	public static function resultado() {
		static $guardado = null;

		if ( null !== $guardado ) {
			return $guardado;
		}

		$guardado = self::calcular();

		return $guardado;
	}

	/**
	 * Executa a busca pedida na requisição; ver `resultado()`.
	 *
	 * @return array
	 */
	private static function calcular() {
		$texto = self::texto_pedido();

		$resultado = array(
			'texto'   => $texto,
			'curto'   => mb_strlen( $texto ) < self::MINIMO,
			'total'   => 0,
			'pagina'  => 1,
			'paginas' => 1,
			'itens'   => array(),
		);

		if ( $resultado['curto'] ) {
			return $resultado;
		}

		$termos = self::termos( $texto );

		if ( ! $termos ) {
			$resultado['curto'] = true;
			return $resultado;
		}

		$achados = self::buscar( $termos );

		$resultado['total']   = count( $achados );
		$resultado['paginas'] = max( 1, (int) ceil( $resultado['total'] / self::POR_PAGINA ) );
		$resultado['pagina']  = min( self::pagina_pedida(), $resultado['paginas'] );

		$fatia = array_slice( $achados, ( $resultado['pagina'] - 1 ) * self::POR_PAGINA, self::POR_PAGINA );
		$mapa  = Reconectar_Incubadora_Leitura::mapa_visivel();

		// O trecho e o destaque só para os itens exibidos: o mapa caractere a
		// caractere entre o texto normalizado e o original é o passo caro, e
		// numa busca que acha cem páginas oitenta delas não aparecem.
		foreach ( $fatia as $pagina ) {
			$resultado['itens'][] = array(
				'pagina'  => $pagina,
				'titulo'  => self::destacar( self::texto_de( $pagina->post_title ), $termos ),
				'caminho' => Reconectar_Incubadora_Leitura::ancestrais_visiveis( $pagina, $mapa ),
				'trecho'  => self::trecho( self::texto_de( $pagina->post_content ), $termos ),
			);
		}

		return $resultado;
	}

	/**
	 * Separa o texto buscado em termos normalizados e distintos.
	 *
	 * Termo de uma letra sai quando há outro mais longo: em "plano de
	 * negócio e gestão", o "e" casaria com todas as páginas e pintaria de
	 * amarelo todo "e" do trecho, sem mudar o conjunto de resultados.
	 *
	 * @param string $texto Texto buscado.
	 * @return string[]
	 */
	public static function termos( $texto ) {
		$partes = preg_split( '/\s+/u', self::normalizar( $texto ), -1, PREG_SPLIT_NO_EMPTY );
		$termos = array_values( array_unique( (array) $partes ) );

		$longos = array_values(
			array_filter(
				$termos,
				static function ( $termo ) {
					return mb_strlen( $termo ) > 1;
				}
			)
		);

		if ( $longos ) {
			$termos = $longos;
		}

		return array_slice( $termos, 0, self::TERMOS_MAXIMOS );
	}

	/**
	 * As páginas visíveis em que todos os termos aparecem, na ordem da tela.
	 *
	 * Todos, e não qualquer um: quem escreve duas palavras quer a página que
	 * fala das duas, e o "ou" afogaria a resposta numa lista que cresce a cada
	 * palavra acrescentada.
	 *
	 * A ordem é a quantidade de termos no título, depois a edição mais recente:
	 * a página chamada "Plano de negócio" vem antes das vinte que só citam o
	 * plano no meio do texto.
	 *
	 * @param string[] $termos Termos normalizados.
	 * @return WP_Post[]
	 */
	public static function buscar( $termos ) {
		$mapa    = Reconectar_Incubadora_Leitura::mapa_visivel();
		$achados = array();

		foreach ( $mapa as $pagina ) {
			if ( ! Reconectar_Incubadora_Leitura::alcancavel_pela_raiz( $pagina, $mapa ) ) {
				continue;
			}

			$titulo = self::normalizar( self::texto_de( $pagina->post_title ) );
			$texto  = self::normalizar( self::texto_de( $pagina->post_content ) );

			$no_titulo = 0;

			foreach ( $termos as $termo ) {
				$em_titulo = false !== mb_strpos( $titulo, $termo );

				if ( ! $em_titulo && false === mb_strpos( $texto, $termo ) ) {
					continue 2;
				}

				$no_titulo += $em_titulo ? 1 : 0;
			}

			$achados[] = array(
				'pagina'     => $pagina,
				'no_titulo'  => $no_titulo,
				'modificado' => (int) get_post_timestamp( $pagina, 'modified' ),
			);
		}

		usort(
			$achados,
			static function ( $a, $b ) {
				if ( $a['no_titulo'] !== $b['no_titulo'] ) {
					return $b['no_titulo'] - $a['no_titulo'];
				}

				if ( $a['modificado'] !== $b['modificado'] ) {
					return $b['modificado'] - $a['modificado'];
				}

				// Desempate estável pelo ID: sem ele, duas páginas gravadas no
				// mesmo segundo trocariam de lugar entre a página 1 e a 2.
				return (int) $a['pagina']->ID - (int) $b['pagina']->ID;
			}
		);

		return wp_list_pluck( $achados, 'pagina' );
	}

	/**
	 * O texto legível de um conteúdo gravado, em uma linha.
	 *
	 * Tag de bloco vira **espaço**: `<p>fim</p><p>começo</p>` sem ele daria
	 * "fimcomeço", e a busca por "fim começo" não acharia. Tag de linha some
	 * sem espaço: `<strong>plano</strong>.` com espaço sairia "plano ." no
	 * trecho, e `<em>in</em>cubadora` deixaria de casar com "incubadora". As entidades saem decodificadas, ou "&amp;" seria buscável como
	 * "amp". A forma NFC junta acento escrito como letra mais sinal
	 * combinante, que `remove_accents()` não trataria igual à letra acentuada.
	 *
	 * @param string $html Conteúdo gravado.
	 * @return string
	 */
	public static function texto_de( $html ) {
		$texto = preg_replace( '#</?(?:p|div|br|hr|li|ul|ol|h[1-6]|blockquote|pre|table|thead|tbody|tr|td|th|figure|figcaption)\b[^>]*>#i', ' ', (string) $html );
		$texto = preg_replace( '/<[^>]*>/', '', (string) $texto );
		$texto = html_entity_decode( (string) $texto, ENT_QUOTES | ENT_HTML5, 'UTF-8' );

		if ( class_exists( 'Normalizer' ) ) {
			$normal = Normalizer::normalize( $texto, Normalizer::FORM_C );
			$texto  = false === $normal ? $texto : $normal;
		}

		return trim( (string) preg_replace( '/\s+/u', ' ', $texto ) );
	}

	/**
	 * Texto em minúsculas e sem acento, para comparar.
	 *
	 * `remove_accents()` troca caractere por caractere — é um `strtr` —, e é
	 * isso que permite em `mapear()` normalizar um caractere de cada vez e
	 * chegar à mesma string.
	 *
	 * @param string $texto Texto.
	 * @return string
	 */
	public static function normalizar( $texto ) {
		return mb_strtolower( remove_accents( (string) $texto ), 'UTF-8' );
	}

	/**
	 * Texto normalizado e, para cada caractere dele, a posição no original.
	 *
	 * Normalizar pode mudar o comprimento — "Æ" vira "ae", "Œ" vira "oe" —, e
	 * a posição achada no texto normalizado não serve para recortar o
	 * original. O mapa é o que leva de um ao outro.
	 *
	 * @param string $texto Texto original.
	 * @return array{0: string[], 1: string, 2: int[]} Caracteres originais, texto normalizado e mapa.
	 */
	private static function mapear( $texto ) {
		static $cache = array();

		$originais   = preg_split( '//u', $texto, -1, PREG_SPLIT_NO_EMPTY );
		$normalizado = '';
		$mapa        = array();

		foreach ( (array) $originais as $posicao => $caractere ) {
			// O caminho rápido para ASCII: é quase todo o texto, e
			// `remove_accents()` monta uma tabela de centenas de pares a cada
			// chamada.
			if ( strlen( $caractere ) === 1 ) {
				$troca = strtolower( $caractere );
			} else {
				if ( ! isset( $cache[ $caractere ] ) ) {
					$cache[ $caractere ] = self::normalizar( $caractere );
				}

				$troca = $cache[ $caractere ];
			}

			$normalizado .= $troca;

			for ( $i = mb_strlen( $troca ); $i > 0; $i-- ) {
				$mapa[] = $posicao;
			}
		}

		return array( (array) $originais, $normalizado, $mapa );
	}

	/**
	 * Intervalos do original, `[início, fim)`, cobertos por algum termo, fundidos e em ordem.
	 *
	 * @param string   $normalizado Texto normalizado.
	 * @param int[]    $mapa        Posição no original de cada caractere normalizado.
	 * @param string[] $termos      Termos normalizados.
	 * @return array<int, int[]>
	 */
	private static function intervalos( $normalizado, $mapa, $termos ) {
		$intervalos = array();

		foreach ( $termos as $termo ) {
			$tamanho = mb_strlen( $termo );
			$desde   = 0;

			while ( false !== ( $achado = mb_strpos( $normalizado, $termo, $desde ) ) ) { // phpcs:ignore WordPress.CodeAnalysis.AssignmentInCondition.FoundInWhileCondition
				$intervalos[] = array( $mapa[ $achado ], $mapa[ $achado + $tamanho - 1 ] + 1 );
				$desde        = $achado + $tamanho;
			}
		}

		sort( $intervalos );

		$fundidos = array();

		foreach ( $intervalos as $intervalo ) {
			$ultimo = count( $fundidos ) - 1;

			if ( $ultimo >= 0 && $intervalo[0] <= $fundidos[ $ultimo ][1] ) {
				$fundidos[ $ultimo ][1] = max( $fundidos[ $ultimo ][1], $intervalo[1] );
				continue;
			}

			$fundidos[] = $intervalo;
		}

		return $fundidos;
	}

	/**
	 * Monta o HTML de um recorte com os termos em `<mark>`.
	 *
	 * Cada pedaço passa por `esc_html()` **separado**, e o `<mark>` é a única
	 * marcação escrita aqui: o texto vem de conteúdo de usuário, e escapar
	 * depois de inserir as tags apagaria o destaque, escapar antes deixaria o
	 * corte de um `&amp;` no meio.
	 *
	 * @param string[]          $originais  Caracteres do texto original.
	 * @param array<int, int[]> $intervalos Intervalos a destacar, fundidos e em ordem.
	 * @param int               $inicio     Primeira posição do recorte.
	 * @param int               $fim        Posição depois da última.
	 * @return string
	 */
	private static function montar( $originais, $intervalos, $inicio, $fim ) {
		$html     = '';
		$cursor   = $inicio;
		$recortar = static function ( $de, $ate ) use ( $originais ) {
			return esc_html( implode( '', array_slice( $originais, $de, $ate - $de ) ) );
		};

		foreach ( $intervalos as $intervalo ) {
			$de  = max( $intervalo[0], $inicio );
			$ate = min( $intervalo[1], $fim );

			if ( $de >= $ate ) {
				continue;
			}

			$html  .= $recortar( $cursor, $de ) . '<mark>' . $recortar( $de, $ate ) . '</mark>';
			$cursor = $ate;
		}

		return $html . $recortar( $cursor, $fim );
	}

	/**
	 * O texto inteiro, escapado, com os termos em `<mark>`.
	 *
	 * @param string   $texto  Texto original, sem marcação.
	 * @param string[] $termos Termos normalizados.
	 * @return string HTML.
	 */
	public static function destacar( $texto, $termos ) {
		list( $originais, $normalizado, $mapa ) = self::mapear( (string) $texto );

		return self::montar( $originais, self::intervalos( $normalizado, $mapa, $termos ), 0, count( $originais ) );
	}

	/**
	 * Um trecho do texto em torno da primeira ocorrência, escapado e destacado.
	 *
	 * A janela começa um pouco antes da ocorrência, para dar contexto, e as
	 * duas pontas são levadas ao espaço mais próximo, para não cortar palavra.
	 * Se o termo só aparece no título, o trecho é o começo do texto, sem
	 * destaque — ainda diz do que a página trata.
	 *
	 * @param string   $texto  Texto original, sem marcação.
	 * @param string[] $termos Termos normalizados.
	 * @return string HTML; vazio se a página não tem texto.
	 */
	public static function trecho( $texto, $termos ) {
		if ( '' === $texto ) {
			return '';
		}

		list( $originais, $normalizado, $mapa ) = self::mapear( $texto );

		$intervalos = self::intervalos( $normalizado, $mapa, $termos );
		$total      = count( $originais );
		$primeira   = $intervalos ? $intervalos[0][0] : 0;

		$inicio = max( 0, $primeira - self::CONTEXTO_ANTES );
		$fim    = min( $total, $inicio + self::TAMANHO_DO_TRECHO );

		// Uma página curta cabe inteira: a janela recua até o começo em vez de
		// cortar o início para sobrar espaço vazio no fim.
		if ( $fim - $inicio < self::TAMANHO_DO_TRECHO ) {
			$inicio = max( 0, $fim - self::TAMANHO_DO_TRECHO );
		}

		if ( $inicio > 0 ) {
			$espaco = array_search( ' ', array_slice( $originais, $inicio, $primeira - $inicio, true ), true );
			$inicio = false === $espaco ? $inicio : $espaco + 1;
		}

		if ( $fim < $total ) {
			$espacos = array_keys( array_slice( $originais, $primeira, $fim - $primeira, true ), ' ', true );
			$fim     = $espacos ? end( $espacos ) : $fim;
		}

		return ( $inicio > 0 ? '…' : '' ) . self::montar( $originais, $intervalos, $inicio, $fim ) . ( $fim < $total ? '…' : '' );
	}

	/**
	 * Endereço de uma página de resultados, como caminho.
	 *
	 * @param string $texto  Texto buscado.
	 * @param int    $pagina Página de resultados.
	 * @return string
	 */
	public static function url( $texto, $pagina = 1 ) {
		$args = array( self::PARAM => $texto );

		if ( $pagina > 1 ) {
			$args[ self::PARAM_PAGINA ] = $pagina;
		}

		$partes  = wp_parse_url( Reconectar_Incubadora_Leitura::url_da_ancora() );
		$caminho = isset( $partes['path'] ) ? $partes['path'] : '/';

		// `add_query_arg()` não codifica o valor: um "&" no texto buscado
		// partiria a URL em dois parâmetros, e um "+" voltaria como espaço.
		return add_query_arg( array_map( 'rawurlencode', $args ), $caminho . ( isset( $partes['query'] ) ? '?' . $partes['query'] : '' ) );
	}

	/**
	 * Põe a busca no `<title>` da aba.
	 *
	 * O critério 2.4.2 da WCAG pede título que descreva a página: sem isto as
	 * vinte telas de resultados se chamariam "Incubadora", e o histórico do
	 * navegador não distinguiria uma busca da outra nem da âncora.
	 *
	 * @param array $partes Partes do título.
	 * @return array
	 */
	public static function titulo_do_documento( $partes ) {
		if ( ! is_user_logged_in() || ! self::pedida() ) {
			return $partes;
		}

		$texto = self::texto_pedido();

		$partes['title'] = '' === $texto
			? __( 'Busca na Incubadora', 'reconectar-core' )
			/* translators: %s: texto buscado. */
			: sprintf( __( 'Busca por “%s” na Incubadora', 'reconectar-core' ), $texto );

		$pagina = self::resultado()['pagina'];

		if ( $pagina > 1 ) {
			/* translators: %d: número da página de resultados. */
			$partes['page'] = sprintf( __( 'Página %d', 'reconectar-core' ), $pagina );
		}

		return $partes;
	}
}
