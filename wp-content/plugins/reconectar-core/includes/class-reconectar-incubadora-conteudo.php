<?php
/**
 * Sanitização e renderização do conteúdo das páginas da Incubadora.
 *
 * Moderador e Administrador escrevem HTML e **não** têm `unfiltered_html` —
 * nem devem ter: com ele, um `<script>` gravado por um deles rodaria na sessão
 * do Super Administrador que abrisse a página. A defesa aqui é **reconstruir,
 * não filtrar**: o HTML recebido é lido por um parser, e a saída é escrita do
 * zero, só com os elementos e atributos desta lista, cada valor conferido por
 * regra própria. O que não está na lista não passa por omissão — não depende
 * de alguém ter previsto o ataque.
 *
 * O `wp_kses()` vem depois, como **segunda** camada, com allowlist idêntica.
 * Sozinho ele não bastaria: aceitaria qualquer `<img src>` externa (rastreador,
 * e a LGPD é requisito do edital) e não sabe transformar `<iframe>` em marcador.
 *
 * Vídeo é guardado como marcador, nunca como `<iframe>`:
 * `<div class="rc-video" data-rc-provedor data-rc-id>`. O iframe só é montado
 * na hora — facade na leitura, player vivo no editor —, então trocar a política
 * de embed não exige reescrever o banco, e um iframe forjado não tem como chegar
 * gravado.
 *
 * @package reconectar-core
 */

defined( 'ABSPATH' ) || exit;

/**
 * Sanitizador por reconstrução e renderizador do conteúdo da Incubadora.
 */
class Reconectar_Incubadora_Conteudo {

	/**
	 * Ação de `admin-post.php` que entrega os arquivos enviados à Incubadora.
	 *
	 * A rota em si chega com o upload; o sanitizador já precisa dela, porque é
	 * a única origem de `<img>` que ele aceita.
	 */
	const ACAO_ARQUIVO = 'reconectar_incubadora_arquivo';

	/**
	 * Nome de arquivo enviado: 32 caracteres aleatórios mais extensão fechada.
	 *
	 * Estrito de propósito — é o que impede `../` e qualquer outro desvio de
	 * caminho de entrar por um `src` gravado.
	 */
	const PADRAO_ARQUIVO = '/^[A-Za-z0-9]{32}\.(?:jpe?g|png|gif|webp|pdf)$/';

	/**
	 * O subconjunto de `PADRAO_ARQUIVO` que pode ir num `<img>`: PDF não.
	 */
	const PADRAO_IMAGEM = '/^[A-Za-z0-9]{32}\.(?:jpe?g|png|gif|webp)$/';

	/**
	 * ID de vídeo do YouTube: sempre 11 caracteres deste alfabeto.
	 */
	const PADRAO_YOUTUBE = '/^[A-Za-z0-9_-]{11}$/';

	/**
	 * ID de vídeo do Vimeo: numérico.
	 */
	const PADRAO_VIMEO = '/^[0-9]{1,12}$/';

	/**
	 * Hash de privacidade do Vimeo, dos vídeos "não listados".
	 */
	const PADRAO_VIMEO_HASH = '/^[0-9a-f]{6,32}$/';

	/**
	 * Aninhamento máximo reconstruído. Além disso o trecho é descartado.
	 *
	 * Um editor de verdade não passa de uma dúzia de níveis; o teto existe para
	 * que HTML hostil não transforme a recursão do reconstrutor em estouro de
	 * pilha.
	 */
	const PROFUNDIDADE_MAXIMA = 48;

	/**
	 * Elementos que saem **com** o conteúdo.
	 *
	 * Desembrulhar um `<script>` ou um `<style>` deixaria o código como texto
	 * visível; desembrulhar `<svg>` ou `<math>` reabriria a porta do mXSS, em
	 * que o parser do navegador reinterpreta o filho num namespace diferente
	 * daquele em que foi sanitizado. Formulário sai inteiro porque um campo
	 * dentro do conteúdo é a forma clássica de colher senha numa página
	 * confiável.
	 *
	 * @var array<string, true>
	 */
	const DESCARTAR = array(
		'applet'   => true,
		'audio'    => true,
		'base'     => true,
		'button'   => true,
		'canvas'   => true,
		'dialog'   => true,
		'embed'    => true,
		'form'     => true,
		'frame'    => true,
		'frameset' => true,
		'head'     => true,
		'input'    => true,
		'link'     => true,
		'math'     => true,
		'meta'     => true,
		'noembed'  => true,
		'noframes' => true,
		'noscript' => true,
		'object'   => true,
		'option'   => true,
		'param'    => true,
		'script'   => true,
		'select'   => true,
		'slot'     => true,
		'source'   => true,
		'style'    => true,
		'svg'      => true,
		'template' => true,
		'textarea' => true,
		'title'    => true,
		'track'    => true,
		'video'    => true,
		'xmp'      => true,
	);

	/**
	 * Elementos trocados por um equivalente da lista.
	 *
	 * O `<h1>` desce a `<h2>` porque o título da página já é o único `<h1>` da
	 * tela; `<h5>`/`<h6>` sobem a `<h4>`, o degrau mais baixo que o editor
	 * oferece.
	 *
	 * @var array<string, string>
	 */
	const RENOMEAR = array(
		'b'      => 'strong',
		'i'      => 'em',
		'h1'     => 'h2',
		'h5'     => 'h4',
		'h6'     => 'h4',
		'strike' => 's',
		'del'    => 's',
		'ins'    => 'u',
	);

	/**
	 * Elementos aceitos e os atributos que cada um conserva.
	 *
	 * `style` não é o atributo cru: é reescrito por `estilo()`, que só deixa
	 * passar as propriedades listadas por elemento em `ESTILOS`. `<a>`, `<img>`
	 * e o marcador de vídeo têm reconstrução própria e não aparecem aqui.
	 *
	 * @var array<string, string[]>
	 */
	const PERMITIDOS = array(
		'p'          => array( 'style' ),
		'h2'         => array( 'style' ),
		'h3'         => array( 'style' ),
		'h4'         => array( 'style' ),
		'div'        => array( 'style' ),
		'blockquote' => array( 'style' ),
		'li'         => array( 'style' ),
		'ul'         => array(),
		'ol'         => array( 'start' ),
		'span'       => array( 'style' ),
		'strong'     => array(),
		'em'         => array(),
		'u'          => array(),
		's'          => array(),
		'sub'        => array(),
		'sup'        => array(),
		'code'       => array(),
		'pre'        => array(),
		'br'         => array(),
		'hr'         => array(),
		'figure'     => array(),
		'figcaption' => array(),
		'table'      => array( 'style' ),
		'caption'    => array(),
		'colgroup'   => array( 'span', 'style' ),
		'col'        => array( 'span', 'style' ),
		'thead'      => array(),
		'tbody'      => array(),
		'tfoot'      => array(),
		'tr'         => array(),
		'th'         => array( 'colspan', 'rowspan', 'scope', 'style' ),
		'td'         => array( 'colspan', 'rowspan', 'style' ),
	);

	/**
	 * Propriedades de `style` aceitas por elemento.
	 *
	 * É o que o editor produz — cor de texto, alinhamento, largura de coluna — e
	 * nada além. `background` fica de fora porque aceita `url()`, que é
	 * requisição a terceiro disparada só por abrir a página.
	 *
	 * @var array<string, string[]>
	 */
	const ESTILOS = array(
		'span'       => array( 'color' ),
		'p'          => array( 'text-align' ),
		'h2'         => array( 'text-align' ),
		'h3'         => array( 'text-align' ),
		'h4'         => array( 'text-align' ),
		'div'        => array( 'text-align' ),
		'blockquote' => array( 'text-align' ),
		'li'         => array( 'text-align' ),
		'table'      => array( 'width' ),
		'colgroup'   => array( 'width' ),
		'col'        => array( 'width' ),
		'th'         => array( 'text-align', 'width' ),
		'td'         => array( 'text-align', 'width' ),
	);

	/**
	 * Elementos sem fechamento.
	 *
	 * @var array<string, true>
	 */
	const VAZIOS = array(
		'br'  => true,
		'hr'  => true,
		'col' => true,
		'img' => true,
	);

	/**
	 * Provedores de vídeo aceitos, com o rótulo que a tela imprime.
	 *
	 * @var array<string, string>
	 */
	const PROVEDORES = array(
		'youtube' => 'YouTube',
		'vimeo'   => 'Vimeo',
	);

	/**
	 * Sanitiza o HTML de uma página para gravação.
	 *
	 * Devolve o HTML reconstruído e a lista de avisos — o que foi removido e
	 * por quê —, que o endpoint de gravação devolve ao editor. Remover em
	 * silêncio faria a pessoa achar que o vídeo de outro provedor foi salvo.
	 *
	 * Idempotente: sanitizar a própria saída devolve a mesma string. É isso que
	 * permite sanitizar de novo na leitura sem alterar o que foi gravado.
	 *
	 * @param string $html HTML vindo do editor, já sem barras (`wp_unslash`).
	 * @return array{html: string, avisos: string[]}
	 */
	public static function sanitizar( $html ) {
		$html = is_string( $html ) ? wp_check_invalid_utf8( $html, true ) : '';

		if ( '' === trim( $html ) ) {
			return array(
				'html'   => '',
				'avisos' => array(),
			);
		}

		$ocorrencias = array();

		// Sem `DOMDocument` não há reconstrução, e devolver o `wp_kses()` sozinho
		// seria aceitar imagem externa e perder os vídeos sem avisar. Melhor
		// recusar o conteúdo inteiro com um aviso claro: a extensão `dom` vem na
		// imagem oficial, e a falta dela é problema de infraestrutura.
		if ( ! class_exists( 'DOMDocument' ) ) {
			return array(
				'html'   => '',
				'avisos' => array( __( 'O servidor não tem a extensão DOM do PHP, e sem ela o conteúdo não pode ser conferido. Nada foi gravado no corpo da página.', 'reconectar-core' ) ),
			);
		}

		$corpo = self::analisar( $html, $ocorrencias );

		$reconstruido = $corpo ? self::reconstruir_filhos( $corpo, $ocorrencias, 0 ) : '';
		$reconstruido = wp_kses( $reconstruido, self::allowlist(), array( 'http', 'https', 'mailto', 'tel' ) );

		return array(
			'html'   => trim( $reconstruido ),
			'avisos' => self::redigir_avisos( $ocorrencias ),
		);
	}

	/**
	 * Sanitiza o título de uma página.
	 *
	 * Texto puro: o `<h1>` é montado pelo template, e um título com marcação
	 * apareceria cru na árvore, na trilha e no `<title>`.
	 *
	 * @param string $titulo Título recebido.
	 * @return string
	 */
	public static function titulo( $titulo ) {
		$titulo = sanitize_text_field( is_string( $titulo ) ? $titulo : '' );

		return mb_substr( $titulo, 0, 200 );
	}

	/**
	 * HTML pronto para a tela de leitura.
	 *
	 * Sanitiza de novo — defesa em profundidade contra o que tenha chegado ao
	 * banco por outro caminho (WP-CLI, restauração de backup, migração) — e
	 * troca cada marcador de vídeo pela facade. A troca é por expressão estrita
	 * sobre a saída **canônica** do sanitizador: um marcador forjado com
	 * atributo a mais não casa e não vira player.
	 *
	 * @param string $html Conteúdo gravado.
	 * @return string
	 */
	public static function html_de_leitura( $html ) {
		$limpo = self::sanitizar( $html );

		return preg_replace_callback(
			'#<div class="rc-video" data-rc-provedor="([a-z]+)" data-rc-id="([A-Za-z0-9_-]{1,12})"(?: data-rc-hash="([0-9a-f]{6,32})")?></div>#',
			static function ( $m ) {
				$video = self::video_valido( $m[1], $m[2], isset( $m[3] ) ? $m[3] : '' );

				return $video ? self::facade( $video ) : '';
			},
			$limpo['html']
		);
	}

	/**
	 * Identifica um vídeo aceito a partir de uma URL de página ou de player.
	 *
	 * Aceita as formas que a barra de endereços e o botão "Compartilhar" dos
	 * provedores produzem: `youtube.com/watch?v=`, `youtu.be/`, `/embed/`,
	 * `/shorts/`, `/live/`, `youtube-nocookie.com`, `vimeo.com/ID[/hash]` e
	 * `player.vimeo.com/video/ID?h=`. O host é comparado inteiro, nunca por
	 * sufixo: `youtube.com.exemplo.org` não é YouTube.
	 *
	 * @param string $url URL do vídeo.
	 * @return array{provedor: string, id: string, hash: string}|null
	 */
	public static function identificar_video( $url ) {
		$url = self::limpar_url( $url );

		if ( '' === $url ) {
			return null;
		}

		$partes = wp_parse_url( $url );

		if ( ! is_array( $partes ) || empty( $partes['host'] ) || empty( $partes['scheme'] ) ) {
			return null;
		}

		if ( ! in_array( strtolower( $partes['scheme'] ), array( 'http', 'https' ), true ) ) {
			return null;
		}

		$host     = preg_replace( '/^(?:www|m)\./', '', strtolower( $partes['host'] ) );
		$segmento = array_values( array_filter( explode( '/', isset( $partes['path'] ) ? $partes['path'] : '' ), 'strlen' ) );
		$consulta = array();

		if ( ! empty( $partes['query'] ) ) {
			parse_str( $partes['query'], $consulta );
		}

		$id   = '';
		$hash = '';

		switch ( $host ) {
			case 'youtu.be':
				$id = isset( $segmento[0] ) ? $segmento[0] : '';
				return self::video_valido( 'youtube', $id, '' );

			case 'youtube.com':
			case 'youtube-nocookie.com':
				if ( isset( $segmento[0] ) && 'watch' === $segmento[0] ) {
					$id = isset( $consulta['v'] ) && is_string( $consulta['v'] ) ? $consulta['v'] : '';
				} elseif ( isset( $segmento[0], $segmento[1] ) && in_array( $segmento[0], array( 'embed', 'shorts', 'live', 'v' ), true ) ) {
					$id = $segmento[1];
				}
				return self::video_valido( 'youtube', $id, '' );

			case 'vimeo.com':
				$id   = isset( $segmento[0] ) ? $segmento[0] : '';
				$hash = isset( $segmento[1] ) ? $segmento[1] : '';
				return self::video_valido( 'vimeo', $id, $hash );

			case 'player.vimeo.com':
				if ( isset( $segmento[0], $segmento[1] ) && 'video' === $segmento[0] ) {
					$id   = $segmento[1];
					$hash = isset( $consulta['h'] ) && is_string( $consulta['h'] ) ? $consulta['h'] : '';
				}
				return self::video_valido( 'vimeo', $id, $hash );
		}

		return null;
	}

	/**
	 * Confere provedor, ID e hash contra os padrões e devolve o vídeo.
	 *
	 * O hash só existe no Vimeo; no YouTube é ignorado. Um hash inválido no
	 * Vimeo invalida o vídeo inteiro, em vez de ser descartado: sem ele, um
	 * vídeo não listado abriria a tela de "vídeo privado".
	 *
	 * @param string $provedor `youtube` ou `vimeo`.
	 * @param string $id       ID do vídeo.
	 * @param string $hash     Hash de privacidade do Vimeo, ou vazio.
	 * @return array{provedor: string, id: string, hash: string}|null
	 */
	public static function video_valido( $provedor, $id, $hash ) {
		$id   = (string) $id;
		$hash = (string) $hash;

		if ( 'youtube' === $provedor && preg_match( self::PADRAO_YOUTUBE, $id ) ) {
			return array(
				'provedor' => 'youtube',
				'id'       => $id,
				'hash'     => '',
			);
		}

		if ( 'vimeo' === $provedor && preg_match( self::PADRAO_VIMEO, $id ) ) {
			if ( '' !== $hash && ! preg_match( self::PADRAO_VIMEO_HASH, $hash ) ) {
				return null;
			}

			return array(
				'provedor' => 'vimeo',
				'id'       => $id,
				'hash'     => $hash,
			);
		}

		return null;
	}

	/**
	 * URL do player embutido de um vídeo.
	 *
	 * `youtube-nocookie.com` e `dnt=1` são os modos de menor rastreamento que
	 * cada provedor oferece. Reduzem, não eliminam: o player continua sendo
	 * terceiro, e por isso só carrega depois do clique na facade.
	 *
	 * @param array{provedor: string, id: string, hash: string} $video Vídeo validado.
	 * @return string
	 */
	public static function url_do_player( $video ) {
		if ( 'vimeo' === $video['provedor'] ) {
			$url = 'https://player.vimeo.com/video/' . rawurlencode( $video['id'] ) . '?dnt=1';

			return '' !== $video['hash'] ? $url . '&h=' . rawurlencode( $video['hash'] ) : $url;
		}

		return 'https://www.youtube-nocookie.com/embed/' . rawurlencode( $video['id'] );
	}

	/**
	 * URL da página do vídeo no próprio provedor, para quem não usa o player.
	 *
	 * @param array{provedor: string, id: string, hash: string} $video Vídeo validado.
	 * @return string
	 */
	public static function url_no_provedor( $video ) {
		if ( 'vimeo' === $video['provedor'] ) {
			$url = 'https://vimeo.com/' . rawurlencode( $video['id'] );

			return '' !== $video['hash'] ? $url . '/' . rawurlencode( $video['hash'] ) : $url;
		}

		return 'https://www.youtube.com/watch?v=' . rawurlencode( $video['id'] );
	}

	/**
	 * Caminho relativo de entrega de um arquivo enviado à Incubadora.
	 *
	 * Relativo de propósito: `WP_HOME` resolve pelo `Host` da requisição, e uma
	 * URL absoluta gravada no conteúdo mandaria o celular na rede local para o
	 * `localhost` dele mesmo — a armadilha das URLs gravadas no banco.
	 *
	 * @param string $nome Nome do arquivo, no padrão `PADRAO_ARQUIVO`.
	 * @return string Caminho, ou vazio se o nome não casar.
	 */
	public static function url_de_arquivo( $nome ) {
		if ( ! is_string( $nome ) || ! preg_match( self::PADRAO_ARQUIVO, $nome ) ) {
			return '';
		}

		return self::caminho_do_admin_post() . '?action=' . self::ACAO_ARQUIVO . '&arquivo=' . $nome;
	}

	/**
	 * Allowlist da segunda camada, o `wp_kses()`.
	 *
	 * Espelha `PERMITIDOS` mais o que as reconstruções próprias emitem. Se as
	 * duas listas divergirem, o `wp_kses()` corta o que o reconstrutor deixou
	 * passar — o erro é para o lado seguro, e o teste de idempotência acusa.
	 *
	 * @return array<string, array<string, bool>>
	 */
	public static function allowlist() {
		$lista = array();

		foreach ( self::PERMITIDOS as $tag => $atributos ) {
			$lista[ $tag ] = array_fill_keys( $atributos, true );
		}

		$lista['a']   = array(
			'href'   => true,
			'title'  => true,
			'target' => true,
			'rel'    => true,
		);
		$lista['img'] = array(
			'src'      => true,
			'alt'      => true,
			'width'    => true,
			'height'   => true,
			'loading'  => true,
			'decoding' => true,
		);

		$lista['div']['class']            = true;
		$lista['div']['data-rc-provedor'] = true;
		$lista['div']['data-rc-id']       = true;
		$lista['div']['data-rc-hash']     = true;

		return $lista;
	}

	/**
	 * Lê o HTML com o parser e devolve o `<body>`.
	 *
	 * O `<meta charset>` não é enfeite: sem ele o `loadHTML()` assume
	 * ISO-8859-1 e todo acento volta como mojibake — o mesmo cuidado de
	 * `Reconectar_Permissoes::ocultar_links_da_comunidade_em_widget()`.
	 *
	 * O parser do `libxml` manda para o `<head>` o que vier antes do primeiro
	 * conteúdo de corpo — `<style>`, `<meta>`, `<title>`. Isso já fica fora da
	 * reconstrução, mas é contado como descarte para que o aviso não omita.
	 *
	 * @param string $html        HTML recebido.
	 * @param array  $ocorrencias Acumulador de ocorrências, por referência.
	 * @return DOMElement|null
	 */
	private static function analisar( $html, array &$ocorrencias ) {
		$documento = new DOMDocument();
		$anteriores = libxml_use_internal_errors( true );

		$carregou = $documento->loadHTML(
			'<!DOCTYPE html><html><head><meta http-equiv="Content-Type" content="text/html; charset=utf-8"></head><body>' . $html . '</body></html>',
			LIBXML_NONET
		);

		libxml_clear_errors();
		libxml_use_internal_errors( $anteriores );

		if ( ! $carregou ) {
			return null;
		}

		$cabeca = $documento->getElementsByTagName( 'head' )->item( 0 );

		if ( $cabeca ) {
			foreach ( $cabeca->childNodes as $no ) {
				if ( ! $no instanceof DOMElement ) {
					continue;
				}

				// O `<meta>` de codificação é o nosso, não do conteúdo.
				if ( 'meta' === strtolower( $no->nodeName ) && $no->hasAttribute( 'http-equiv' ) && false !== stripos( $no->getAttribute( 'content' ), 'charset=utf-8' ) && null === $no->previousSibling ) {
					continue;
				}

				$ocorrencias['descartados'][ strtolower( $no->nodeName ) ] = true;
			}
		}

		$corpo = $documento->getElementsByTagName( 'body' )->item( 0 );

		return $corpo instanceof DOMElement ? $corpo : null;
	}

	/**
	 * Reconstrói os filhos de um nó.
	 *
	 * Comentário, instrução de processamento e CDATA não passam: nenhum deles
	 * tem uso em conteúdo de página, e comentário condicional é vetor antigo.
	 *
	 * @param DOMNode $pai          Nó cujos filhos serão reconstruídos.
	 * @param array   $ocorrencias  Acumulador de ocorrências, por referência.
	 * @param int     $profundidade Profundidade do pai.
	 * @return string
	 */
	private static function reconstruir_filhos( DOMNode $pai, array &$ocorrencias, $profundidade ) {
		$saida = '';

		foreach ( $pai->childNodes as $no ) {
			if ( $no instanceof DOMCdataSection ) {
				continue;
			}

			if ( $no instanceof DOMText ) {
				$saida .= self::escapar( $no->data );
				continue;
			}

			if ( $no instanceof DOMElement ) {
				$saida .= self::reconstruir_elemento( $no, $ocorrencias, $profundidade + 1 );
			}
		}

		return $saida;
	}

	/**
	 * Reconstrói um elemento: aceito, trocado, desembrulhado ou descartado.
	 *
	 * Elemento desconhecido é **desembrulhado** — sai a marca, fica o texto —,
	 * porque quase sempre é formatação colada de outro editor (`<font>`,
	 * `<center>`, `<section>`), e perder o parágrafo inteiro seria pior.
	 *
	 * @param DOMElement $no           Elemento.
	 * @param array      $ocorrencias  Acumulador de ocorrências, por referência.
	 * @param int        $profundidade Profundidade do elemento.
	 * @return string
	 */
	private static function reconstruir_elemento( DOMElement $no, array &$ocorrencias, $profundidade ) {
		$tag = strtolower( $no->nodeName );

		if ( $profundidade > self::PROFUNDIDADE_MAXIMA ) {
			$ocorrencias['profundidade'] = true;
			return '';
		}

		if ( self::tem_classe( $no, 'rc-video' ) ) {
			return self::reconstruir_marcador( $no, $ocorrencias );
		}

		if ( 'iframe' === $tag ) {
			return self::reconstruir_iframe( $no, $ocorrencias );
		}

		if ( 'img' === $tag ) {
			return self::reconstruir_imagem( $no, $ocorrencias );
		}

		if ( isset( self::DESCARTAR[ $tag ] ) ) {
			$ocorrencias['descartados'][ $tag ] = true;
			return '';
		}

		if ( 'a' === $tag ) {
			return self::reconstruir_link( $no, $ocorrencias, $profundidade );
		}

		$tag = isset( self::RENOMEAR[ $tag ] ) ? self::RENOMEAR[ $tag ] : $tag;

		if ( ! isset( self::PERMITIDOS[ $tag ] ) ) {
			$ocorrencias['desembrulhados'][ $tag ] = true;
			return self::reconstruir_filhos( $no, $ocorrencias, $profundidade );
		}

		$abertura = '<' . $tag . self::serializar( self::atributos( $tag, $no ) ) . '>';

		if ( isset( self::VAZIOS[ $tag ] ) ) {
			return $abertura;
		}

		return $abertura . self::reconstruir_filhos( $no, $ocorrencias, $profundidade ) . '</' . $tag . '>';
	}

	/**
	 * Atributos conferidos de um elemento da lista.
	 *
	 * Cada atributo tem regra própria; o que não tem regra não passa, e por
	 * isso `on*`, `class`, `id` e `data-*` caem sem precisar de lista negra.
	 *
	 * @param string     $tag Nome do elemento, já renomeado.
	 * @param DOMElement $no  Elemento de origem.
	 * @return array<string, string>
	 */
	private static function atributos( $tag, DOMElement $no ) {
		$saida = array();

		foreach ( self::PERMITIDOS[ $tag ] as $nome ) {
			if ( ! $no->hasAttribute( $nome ) ) {
				continue;
			}

			$valor = trim( $no->getAttribute( $nome ) );

			switch ( $nome ) {
				case 'style':
					$valor = self::estilo( $tag, $valor );
					break;

				case 'colspan':
				case 'rowspan':
				case 'span':
				case 'start':
					$valor = self::inteiro( $valor, 1, 1000 );
					break;

				case 'scope':
					$valor = in_array( $valor, array( 'row', 'col', 'rowgroup', 'colgroup' ), true ) ? $valor : '';
					break;

				default:
					$valor = '';
			}

			if ( '' !== $valor ) {
				$saida[ $nome ] = $valor;
			}
		}

		return $saida;
	}

	/**
	 * Reescreve um `style` com as propriedades aceitas para o elemento.
	 *
	 * Cor só em hexadecimal: `rgb()` é convertido, porque o `safecss_filter_attr()`
	 * da segunda camada recusa qualquer valor com parêntese e apagaria a cor que
	 * o editor acabou de aplicar.
	 *
	 * @param string $tag   Elemento.
	 * @param string $valor Atributo `style` original.
	 * @return string
	 */
	private static function estilo( $tag, $valor ) {
		if ( empty( self::ESTILOS[ $tag ] ) ) {
			return '';
		}

		$aceitas = self::ESTILOS[ $tag ];
		$saida   = array();

		foreach ( explode( ';', $valor ) as $declaracao ) {
			$partes = explode( ':', $declaracao, 2 );

			if ( 2 !== count( $partes ) ) {
				continue;
			}

			$propriedade = strtolower( trim( $partes[0] ) );
			$conteudo    = strtolower( trim( $partes[1] ) );

			if ( ! in_array( $propriedade, $aceitas, true ) || isset( $saida[ $propriedade ] ) ) {
				continue;
			}

			if ( 'color' === $propriedade ) {
				$conteudo = self::cor( $conteudo );
			} elseif ( 'text-align' === $propriedade ) {
				$conteudo = in_array( $conteudo, array( 'left', 'right', 'center', 'justify' ), true ) ? $conteudo : '';
			} elseif ( 'width' === $propriedade ) {
				$conteudo = preg_match( '/^[0-9]{1,4}(?:\.[0-9]{1,2})?(?:px|%)$/', $conteudo ) ? $conteudo : '';
			}

			if ( '' !== $conteudo ) {
				$saida[ $propriedade ] = $propriedade . ': ' . $conteudo;
			}
		}

		return implode( '; ', $saida );
	}

	/**
	 * Normaliza uma cor para hexadecimal de seis dígitos, ou vazio.
	 *
	 * @param string $cor Valor da propriedade `color`.
	 * @return string
	 */
	private static function cor( $cor ) {
		if ( preg_match( '/^#([0-9a-f]{3})$/', $cor, $m ) ) {
			return '#' . $m[1][0] . $m[1][0] . $m[1][1] . $m[1][1] . $m[1][2] . $m[1][2];
		}

		if ( preg_match( '/^#[0-9a-f]{6}$/', $cor ) ) {
			return $cor;
		}

		if ( preg_match( '/^rgb\(\s*([0-9]{1,3})\s*,\s*([0-9]{1,3})\s*,\s*([0-9]{1,3})\s*\)$/', $cor, $m ) ) {
			if ( max( (int) $m[1], (int) $m[2], (int) $m[3] ) > 255 ) {
				return '';
			}

			return sprintf( '#%02x%02x%02x', (int) $m[1], (int) $m[2], (int) $m[3] );
		}

		return '';
	}

	/**
	 * Inteiro dentro de um intervalo, como string, ou vazio.
	 *
	 * @param string $valor  Valor do atributo.
	 * @param int    $minimo Mínimo aceito.
	 * @param int    $maximo Máximo aceito.
	 * @return string
	 */
	private static function inteiro( $valor, $minimo, $maximo ) {
		if ( ! preg_match( '/^[0-9]{1,5}$/', $valor ) ) {
			return '';
		}

		$numero = (int) $valor;

		return ( $numero >= $minimo && $numero <= $maximo ) ? (string) $numero : '';
	}

	/**
	 * Reconstrói um link, ou o desembrulha se o destino não for aceito.
	 *
	 * Link para fora abre em nova aba só se o editor pediu, e então sai com
	 * `rel="noopener noreferrer"` escrito aqui — não depende de filtro de
	 * terceiro. `noreferrer` porque o caminho da página na Incubadora, que só
	 * logados leem, não tem por que ir ao site de destino.
	 *
	 * @param DOMElement $no           Elemento `<a>`.
	 * @param array      $ocorrencias  Acumulador de ocorrências, por referência.
	 * @param int        $profundidade Profundidade do elemento.
	 * @return string
	 */
	private static function reconstruir_link( DOMElement $no, array &$ocorrencias, $profundidade ) {
		$filhos = self::reconstruir_filhos( $no, $ocorrencias, $profundidade );
		$bruto  = $no->getAttribute( 'href' );
		$href   = self::href( $bruto );

		if ( '' === $href ) {
			if ( '' !== trim( $bruto ) ) {
				$ocorrencias['links'] = ( isset( $ocorrencias['links'] ) ? $ocorrencias['links'] : 0 ) + 1;
			}

			return $filhos;
		}

		$atributos = array( 'href' => $href );
		$titulo    = trim( $no->getAttribute( 'title' ) );

		if ( '' !== $titulo ) {
			$atributos['title'] = mb_substr( $titulo, 0, 300 );
		}

		if ( '_blank' === $no->getAttribute( 'target' ) ) {
			$atributos['target'] = '_blank';
			$atributos['rel']    = 'noopener noreferrer';
		}

		return '<a' . self::serializar( $atributos ) . '>' . $filhos . '</a>';
	}

	/**
	 * Confere o destino de um link e o devolve normalizado, ou vazio.
	 *
	 * Aceita âncora (`#`), caminho a partir da raiz e os esquemas `http`,
	 * `https`, `mailto` e `tel`. Link para o próprio site é gravado como
	 * caminho, pela mesma razão de `url_de_arquivo()`. Caminho relativo sem
	 * barra inicial é recusado: o destino dependeria da URL da página, que muda
	 * quando a página é movida na árvore.
	 *
	 * @param string $href Valor do `href`, já decodificado pelo parser.
	 * @return string
	 */
	private static function href( $href ) {
		$href = self::limpar_url( $href );

		if ( '' === $href ) {
			return '';
		}

		if ( '#' === $href[0] ) {
			return preg_match( '/^#[A-Za-z0-9_.:-]{1,200}$/', $href ) ? $href : '';
		}

		if ( '/' === $href[0] && ( ! isset( $href[1] ) || '/' !== $href[1] ) ) {
			return $href;
		}

		if ( 0 === strpos( $href, '//' ) ) {
			$href = 'https:' . $href;
		}

		if ( ! preg_match( '/^([a-z][a-z0-9+.-]*):/i', $href, $m ) ) {
			return '';
		}

		$esquema = strtolower( $m[1] );

		if ( in_array( $esquema, array( 'mailto', 'tel' ), true ) ) {
			return $href;
		}

		if ( ! in_array( $esquema, array( 'http', 'https' ), true ) ) {
			return '';
		}

		$partes = wp_parse_url( $href );

		if ( ! is_array( $partes ) || empty( $partes['host'] ) ) {
			return '';
		}

		$interno = self::caminho_se_interno( $partes );

		return null !== $interno ? $interno : $href;
	}

	/**
	 * Tira o que o navegador ignora numa URL e que esconderia o esquema.
	 *
	 * `java&#9;script:` chega do parser como "java\tscript:", e o navegador
	 * descarta tabulação e quebra de linha dentro de URL antes de ler o esquema:
	 * sem esta limpeza, a conferência de esquema veria um caminho relativo
	 * inofensivo e o clique executaria o script. Espaço nas pontas sai pelo
	 * mesmo motivo.
	 *
	 * @param string $url URL bruta.
	 * @return string
	 */
	private static function limpar_url( $url ) {
		if ( ! is_string( $url ) ) {
			return '';
		}

		return trim( preg_replace( '/[\x00-\x1F\x7F]+/', '', $url ), " \u{00A0}" );
	}

	/**
	 * Caminho relativo, se a URL apontar para o próprio site; senão `null`.
	 *
	 * @param array $partes Resultado de `wp_parse_url()` da URL.
	 * @return string|null
	 */
	private static function caminho_se_interno( array $partes ) {
		$casa = wp_parse_url( home_url( '/' ) );

		if ( ! is_array( $casa ) || empty( $casa['host'] ) ) {
			return null;
		}

		$porta_padrao = array(
			'http'  => 80,
			'https' => 443,
		);
		$esquema      = strtolower( $partes['scheme'] );
		$esquema_casa = strtolower( $casa['scheme'] );
		$porta        = isset( $partes['port'] ) ? (int) $partes['port'] : $porta_padrao[ $esquema ];
		$porta_casa   = isset( $casa['port'] ) ? (int) $casa['port'] : ( isset( $porta_padrao[ $esquema_casa ] ) ? $porta_padrao[ $esquema_casa ] : 0 );

		if ( strtolower( $partes['host'] ) !== strtolower( $casa['host'] ) || $porta !== $porta_casa ) {
			return null;
		}

		$caminho = isset( $partes['path'] ) && '' !== $partes['path'] ? $partes['path'] : '/';

		if ( isset( $partes['query'] ) ) {
			$caminho .= '?' . $partes['query'];
		}

		if ( isset( $partes['fragment'] ) ) {
			$caminho .= '#' . $partes['fragment'];
		}

		return $caminho;
	}

	/**
	 * Reconstrói uma imagem, que só pode vir da rota de arquivos da Incubadora.
	 *
	 * Imagem de outro site sai: cada leitura da página avisaria àquele servidor
	 * quem a abriu, a que horas e de onde — é assim que funciona o pixel de
	 * rastreamento, e a Incubadora só é lida por quem está logado. Quem quiser a
	 * imagem a envia para a Incubadora.
	 *
	 * `width`/`height` são conservados para reservar o espaço e evitar o salto
	 * de layout durante o carregamento.
	 *
	 * @param DOMElement $no          Elemento `<img>`.
	 * @param array      $ocorrencias Acumulador de ocorrências, por referência.
	 * @return string
	 */
	private static function reconstruir_imagem( DOMElement $no, array &$ocorrencias ) {
		$src = self::src_de_imagem( $no->getAttribute( 'src' ) );

		if ( '' === $src ) {
			$ocorrencias['imagens'] = ( isset( $ocorrencias['imagens'] ) ? $ocorrencias['imagens'] : 0 ) + 1;
			return '';
		}

		$atributos = array(
			'src' => $src,
			'alt' => mb_substr( trim( $no->getAttribute( 'alt' ) ), 0, 300 ),
		);

		foreach ( array( 'width', 'height' ) as $dimensao ) {
			$valor = self::inteiro( trim( $no->getAttribute( $dimensao ) ), 1, 4000 );

			if ( '' !== $valor ) {
				$atributos[ $dimensao ] = $valor;
			}
		}

		$atributos['loading']  = 'lazy';
		$atributos['decoding'] = 'async';

		return '<img' . self::serializar( $atributos, array( 'alt' ) ) . '>';
	}

	/**
	 * Confere o `src` de uma imagem e devolve o caminho canônico, ou vazio.
	 *
	 * A consulta tem de ter **exatamente** `action` e `arquivo`: um parâmetro a
	 * mais é recusado, em vez de descartado, porque não há editor legítimo que o
	 * produza.
	 *
	 * @param string $src Valor do `src`.
	 * @return string
	 */
	private static function src_de_imagem( $src ) {
		$src = self::limpar_url( $src );

		if ( '' === $src ) {
			return '';
		}

		$partes = wp_parse_url( 0 === strpos( $src, '//' ) ? 'https:' . $src : $src );

		if ( ! is_array( $partes ) ) {
			return '';
		}

		if ( ! empty( $partes['host'] ) ) {
			if ( empty( $partes['scheme'] ) || ! in_array( strtolower( $partes['scheme'] ), array( 'http', 'https' ), true ) ) {
				return '';
			}

			if ( null === self::caminho_se_interno( $partes ) ) {
				return '';
			}
		} elseif ( ! empty( $partes['scheme'] ) ) {
			return '';
		}

		if ( empty( $partes['path'] ) || self::caminho_do_admin_post() !== $partes['path'] || empty( $partes['query'] ) || isset( $partes['fragment'] ) ) {
			return '';
		}

		parse_str( $partes['query'], $consulta );

		if ( array( 'action', 'arquivo' ) !== array_keys( $consulta ) && array( 'arquivo', 'action' ) !== array_keys( $consulta ) ) {
			return '';
		}

		if ( self::ACAO_ARQUIVO !== $consulta['action'] || ! is_string( $consulta['arquivo'] ) || ! preg_match( self::PADRAO_IMAGEM, $consulta['arquivo'] ) ) {
			return '';
		}

		return self::url_de_arquivo( $consulta['arquivo'] );
	}

	/**
	 * Caminho de `admin-post.php`, sem host.
	 *
	 * @return string
	 */
	private static function caminho_do_admin_post() {
		$caminho = wp_parse_url( admin_url( 'admin-post.php' ), PHP_URL_PATH );

		return is_string( $caminho ) ? $caminho : '/wp-admin/admin-post.php';
	}

	/**
	 * Converte um `<iframe>` de vídeo aceito no marcador; o resto sai.
	 *
	 * @param DOMElement $no          Elemento `<iframe>`.
	 * @param array      $ocorrencias Acumulador de ocorrências, por referência.
	 * @return string
	 */
	private static function reconstruir_iframe( DOMElement $no, array &$ocorrencias ) {
		$src   = $no->getAttribute( 'src' );
		$video = self::identificar_video( $src );

		if ( $video ) {
			return self::marcador( $video );
		}

		$host = wp_parse_url( self::limpar_url( $src ), PHP_URL_HOST );

		$ocorrencias['videos'][ is_string( $host ) && '' !== $host ? strtolower( $host ) : __( 'endereço inválido', 'reconectar-core' ) ] = true;

		return '';
	}

	/**
	 * Revalida um marcador de vídeo (ou a facade que o editor devolveu).
	 *
	 * O editor recebe a tela de leitura; se o script que troca a facade pelo
	 * player falhar, a facade volta inteira no salvamento. Ela traz os mesmos
	 * `data-rc-*` do marcador, e é por eles — nunca pelo `src` de um botão ou
	 * link de dentro — que o vídeo é reconhecido.
	 *
	 * @param DOMElement $no          Elemento com a classe `rc-video`.
	 * @param array      $ocorrencias Acumulador de ocorrências, por referência.
	 * @return string
	 */
	private static function reconstruir_marcador( DOMElement $no, array &$ocorrencias ) {
		$video = self::video_valido(
			$no->getAttribute( 'data-rc-provedor' ),
			$no->getAttribute( 'data-rc-id' ),
			$no->getAttribute( 'data-rc-hash' )
		);

		if ( $video ) {
			return self::marcador( $video );
		}

		$ocorrencias['videos'][ __( 'marcador inválido', 'reconectar-core' ) ] = true;

		return '';
	}

	/**
	 * O marcador canônico de um vídeo. A ordem dos atributos é fixa: é por ela
	 * que `html_de_leitura()` o reconhece.
	 *
	 * @param array{provedor: string, id: string, hash: string} $video Vídeo validado.
	 * @return string
	 */
	private static function marcador( $video ) {
		$atributos = array(
			'class'            => 'rc-video',
			'data-rc-provedor' => $video['provedor'],
			'data-rc-id'       => $video['id'],
		);

		if ( '' !== $video['hash'] ) {
			$atributos['data-rc-hash'] = $video['hash'];
		}

		return '<div' . self::serializar( $atributos ) . '></div>';
	}

	/**
	 * A facade de leitura: nenhum byte do provedor até o clique.
	 *
	 * Sem miniatura: a imagem de capa viria do servidor do provedor, e a
	 * facade existe justamente para que abrir a página não o contate. O botão
	 * nasce `hidden` e o `incubadora.js` o revela; sem JavaScript, resta o link
	 * para assistir no provedor, que funciona sozinho.
	 *
	 * O `data-rc-src` é conferido de novo no navegador contra uma lista de
	 * hosts antes de virar `src` de iframe.
	 *
	 * @param array{provedor: string, id: string, hash: string} $video Vídeo validado.
	 * @return string
	 */
	private static function facade( $video ) {
		$nome = self::PROVEDORES[ $video['provedor'] ];

		$atributos = array(
			'class'            => 'rc-video rc-video--facade',
			'data-rc-provedor' => $video['provedor'],
			'data-rc-id'       => $video['id'],
		);

		if ( '' !== $video['hash'] ) {
			$atributos['data-rc-hash'] = $video['hash'];
		}

		/* translators: %s: nome do provedor, como "YouTube". */
		$rotulo = sprintf( __( 'Vídeo do %s', 'reconectar-core' ), $nome );

		return '<figure' . self::serializar( $atributos ) . '>'
			. '<div class="rc-video__quadro">'
			. '<p class="rc-video__provedor">' . self::escapar( $rotulo ) . '</p>'
			/* translators: %s: nome do provedor, como "YouTube". */
			. '<p class="rc-video__aviso">' . self::escapar( sprintf( __( 'O vídeo só é carregado se você pedir. Ao carregar, o %s recebe o seu endereço IP.', 'reconectar-core' ), $nome ) ) . '</p>'
			. '<p class="rc-video__acoes">'
			. '<button type="button" class="rc-video__carregar" data-rc-src="' . self::escapar( self::url_do_player( $video ), true ) . '" data-rc-titulo="' . self::escapar( $rotulo, true ) . '" hidden>' . self::escapar( __( 'Carregar vídeo', 'reconectar-core' ) ) . '</button>'
			. '<a class="rc-video__link" href="' . self::escapar( self::url_no_provedor( $video ), true ) . '" target="_blank" rel="noopener noreferrer">'
			/* translators: %s: nome do provedor, como "YouTube". */
			. self::escapar( sprintf( __( 'Assistir no %s', 'reconectar-core' ), $nome ) )
			. '<span class="screen-reader-text"> ' . self::escapar( __( '(abre em nova aba)', 'reconectar-core' ) ) . '</span>'
			. '</a>'
			. '</p>'
			. '</div>'
			. '</figure>';
	}

	/**
	 * Diz se o elemento tem uma classe, como token inteiro.
	 *
	 * @param DOMElement $no     Elemento.
	 * @param string     $classe Classe procurada.
	 * @return bool
	 */
	private static function tem_classe( DOMElement $no, $classe ) {
		$classes = preg_split( '/\s+/', trim( $no->getAttribute( 'class' ) ) );

		return in_array( $classe, $classes, true );
	}

	/**
	 * Serializa atributos já conferidos.
	 *
	 * Atributo vazio é omitido, exceto os listados em `$mesmo_vazios` — o `alt`
	 * vazio é informação (imagem decorativa), não ausência.
	 *
	 * @param array<string, string> $atributos    Nome => valor.
	 * @param string[]              $mesmo_vazios Atributos impressos mesmo vazios.
	 * @return string
	 */
	private static function serializar( array $atributos, array $mesmo_vazios = array() ) {
		$saida = '';

		foreach ( $atributos as $nome => $valor ) {
			if ( '' === $valor && ! in_array( $nome, $mesmo_vazios, true ) ) {
				continue;
			}

			$saida .= ' ' . $nome . '="' . self::escapar( $valor, true ) . '"';
		}

		return $saida;
	}

	/**
	 * Escapa texto ou valor de atributo.
	 *
	 * `htmlspecialchars()` e não `esc_html()`: o do WordPress **não recodifica**
	 * entidade já presente, e o parser entrega o texto decodificado. Quem
	 * escreveu "&amp;lt;" na página — querendo mostrar a entidade — chega aqui
	 * como "&lt;", e o `esc_html()` o devolveria intacto: na tela, um "<".
	 * Inofensivo nesse caso, mas é uma ida e volta que não fecha, e a
	 * idempotência do sanitizador depende dela fechar.
	 *
	 * @param string $texto    Texto decodificado.
	 * @param bool   $atributo Se é valor de atributo (escapa aspas).
	 * @return string
	 */
	private static function escapar( $texto, $atributo = false ) {
		return htmlspecialchars( (string) $texto, ( $atributo ? ENT_QUOTES : ENT_NOQUOTES ) | ENT_SUBSTITUTE, 'UTF-8' );
	}

	/**
	 * Redige os avisos para quem editou, um por tipo de remoção.
	 *
	 * @param array $ocorrencias Ocorrências acumuladas.
	 * @return string[]
	 */
	private static function redigir_avisos( array $ocorrencias ) {
		$avisos = array();

		if ( ! empty( $ocorrencias['descartados'] ) ) {
			/* translators: %s: lista de elementos HTML, como "<script>, <form>". */
			$avisos[] = sprintf( __( 'Removido, com o que havia dentro: %s.', 'reconectar-core' ), self::listar_elementos( $ocorrencias['descartados'] ) );
		}

		if ( ! empty( $ocorrencias['videos'] ) ) {
			/* translators: %s: lista de endereços. */
			$avisos[] = sprintf( __( 'Vídeo removido — só YouTube e Vimeo são aceitos: %s.', 'reconectar-core' ), implode( ', ', array_keys( $ocorrencias['videos'] ) ) );
		}

		if ( ! empty( $ocorrencias['imagens'] ) ) {
			$avisos[] = sprintf(
				/* translators: %d: quantidade de imagens. */
				_n(
					'%d imagem removida: só são aceitas imagens enviadas para a Incubadora, porque imagem de outro site informa a ele quem abriu a página.',
					'%d imagens removidas: só são aceitas imagens enviadas para a Incubadora, porque imagem de outro site informa a ele quem abriu a página.',
					$ocorrencias['imagens'],
					'reconectar-core'
				),
				$ocorrencias['imagens']
			);
		}

		if ( ! empty( $ocorrencias['links'] ) ) {
			$avisos[] = sprintf(
				/* translators: %d: quantidade de links. */
				_n(
					'%d link removido por endereço não aceito; o texto foi mantido.',
					'%d links removidos por endereço não aceito; o texto foi mantido.',
					$ocorrencias['links'],
					'reconectar-core'
				),
				$ocorrencias['links']
			);
		}

		if ( ! empty( $ocorrencias['desembrulhados'] ) ) {
			/* translators: %s: lista de elementos HTML, como "<font>, <center>". */
			$avisos[] = sprintf( __( 'Formatação não suportada retirada, com o texto mantido: %s.', 'reconectar-core' ), self::listar_elementos( $ocorrencias['desembrulhados'] ) );
		}

		if ( ! empty( $ocorrencias['profundidade'] ) ) {
			$avisos[] = __( 'Um trecho aninhado demais foi removido.', 'reconectar-core' );
		}

		return $avisos;
	}

	/**
	 * Lista nomes de elemento como "<a>, <b>".
	 *
	 * @param array<string, true> $elementos Nomes como chave.
	 * @return string
	 */
	private static function listar_elementos( array $elementos ) {
		$nomes = array_keys( $elementos );
		sort( $nomes );

		return implode(
			', ',
			array_map(
				static function ( $nome ) {
					return '<' . $nome . '>';
				},
				$nomes
			)
		);
	}
}
