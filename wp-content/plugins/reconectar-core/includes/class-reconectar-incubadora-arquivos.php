<?php
/**
 * Arquivos da Incubadora: imagens e PDFs enviados pelo editor.
 *
 * **Fora da Media Library.** Arquivo em `/wp-content/uploads/` é servido direto
 * pelo Apache, sem consultar capacidade nenhuma, e a Incubadora só é lida por
 * quem está logado. Os papéis que escrevem nela também não têm `upload_files`,
 * de propósito. O arranjo é o de `Reconectar_Comprovante` — `diretorio()`,
 * `mimes()` e `baixar()` daquele arquivo —, copiado e não compartilhado: o
 * código de pagamento funciona, e refatorá-lo para servir a uma wiki mexeria
 * numa tela de pagamento sem ganho para ela.
 *
 * **Uma rota de entrega, sem nonce.** A imagem está embutida no conteúdo da
 * página, e um `<img src>` não carrega nonce que valha para todo leitor. A
 * trava é a sessão: logado recebe, visitante leva 401. O que impede alguém
 * logado de enumerar arquivos é o nome sorteado de 32 caracteres.
 *
 * **Sem `before_delete_post`.** Apagar os arquivos junto com a página parece o
 * natural e quebraria outra página: copiar e colar um trecho de uma página
 * para outra leva junto o `<img>`, e o arquivo passa a ser das duas. Quem decide
 * se um arquivo ainda serve é `limpar_orfaos()`, que procura o nome no conteúdo
 * de todas as páginas e de todas as revisões.
 *
 * `uploads/` não é versionado nem sincronizado pelo deploy: os arquivos existem
 * só no servidor que os recebeu, e o backup deles é da infraestrutura.
 *
 * @package reconectar-core
 */

defined( 'ABSPATH' ) || exit;

/**
 * Guarda, entrega e varre os arquivos da Incubadora.
 */
class Reconectar_Incubadora_Arquivos {

	/**
	 * Subdiretório de `uploads/`.
	 */
	const PASTA = 'reconectar-incubadora';

	/**
	 * Meta da página, uma linha por arquivo enviado nela.
	 *
	 * Uma linha por arquivo, com `add_post_meta()`, e não um array só: duas
	 * pessoas enviando imagem para a mesma página ao mesmo tempo fariam, num
	 * array lido e regravado, a segunda apagar o registro da primeira.
	 *
	 * Guarda `arquivo` (o nome em disco), `nome` (o original, para o download),
	 * `mime`, `tamanho`, `largura`, `altura`, `enviado_por` e `enviado_em`.
	 */
	const META = '_reconectar_incubadora_arquivos';

	/**
	 * Teto próprio de uma imagem, antes do teto do servidor.
	 */
	const LIMITE_IMAGEM = 5242880;

	/**
	 * Teto próprio de um PDF, antes do teto do servidor.
	 */
	const LIMITE_PDF = 10485760;

	/**
	 * Maior lado de uma imagem gravada, em pixels.
	 *
	 * Uma foto de celular tem 4000 px de lado e vai para uma coluna de leitura
	 * de 800: guardar o original seria fazer todo leitor baixar cinco vezes o
	 * que a tela mostra.
	 */
	const LADO_MAXIMO = 2000;

	/**
	 * Teto de pixels da imagem recebida.
	 *
	 * O GD descomprime a imagem inteira em memória, a 4 bytes por pixel: um
	 * PNG pequeno em disco e enorme em dimensões derrubaria o processo antes de
	 * qualquer redução. 25 milhões de pixels são cerca de 100 MB descomprimidos.
	 */
	const PIXELS_MAXIMOS = 25000000;

	/**
	 * Idade mínima de um arquivo para a varredura poder apagá-lo.
	 *
	 * A imagem é enviada no instante em que é colada, e a página só cita o
	 * arquivo quando a pessoa salva. Sem esta folga, a varredura apagaria o
	 * arquivo de quem ainda está editando.
	 */
	const IDADE_DE_ORFAO = DAY_IN_SECONDS;

	/**
	 * Registra a rota de entrega.
	 *
	 * O `nopriv` existe para o visitante receber 401, e não o 400 de corpo
	 * vazio que `admin-post.php` dá a uma ação sem handler.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'admin_post_' . Reconectar_Incubadora_Conteudo::ACAO_ARQUIVO, array( __CLASS__, 'entregar' ) );
		add_action( 'admin_post_nopriv_' . Reconectar_Incubadora_Conteudo::ACAO_ARQUIVO, array( __CLASS__, 'entregar' ) );
	}

	/* ---------------------------------------------------------------------
	 * Limites
	 * ------------------------------------------------------------------ */

	/**
	 * Tamanho máximo aceito por tipo, já limitado pelo servidor.
	 *
	 * O teto do servidor costuma ser menor que o próprio — medido no container
	 * de desenvolvimento, `upload_max_filesize` é 2 MB —, e prometer 5 MB na
	 * tela para o PHP recusar 3 MB seria mentir na interface. Elevar o limite
	 * do servidor é decisão de infraestrutura.
	 *
	 * @return array{imagem: int, pdf: int}
	 */
	public static function limites() {
		$servidor = (int) wp_max_upload_size();

		return array(
			'imagem' => $servidor > 0 ? min( self::LIMITE_IMAGEM, $servidor ) : self::LIMITE_IMAGEM,
			'pdf'    => $servidor > 0 ? min( self::LIMITE_PDF, $servidor ) : self::LIMITE_PDF,
		);
	}

	/**
	 * Extensões aceitas, no formato que `wp_check_filetype_and_ext()` espera.
	 *
	 * SVG fica de fora de propósito: é XML, carrega script, e uma imagem que
	 * executa código não se torna segura por sanitização de atributo.
	 *
	 * @return array<string, string>
	 */
	public static function mimes() {
		return array(
			'jpg|jpeg' => 'image/jpeg',
			'png'      => 'image/png',
			'gif'      => 'image/gif',
			'webp'     => 'image/webp',
			'pdf'      => 'application/pdf',
		);
	}

	/**
	 * Extensão gravada em disco para cada MIME.
	 *
	 * Sai do tipo **real**, não do nome enviado: um PNG chamado `foto.jpg` é
	 * gravado como `.png`, e a rota de entrega declara o tipo pela extensão.
	 *
	 * @return array<string, string>
	 */
	private static function extensoes() {
		return array(
			'image/jpeg'      => 'jpg',
			'image/png'       => 'png',
			'image/gif'       => 'gif',
			'image/webp'      => 'webp',
			'application/pdf' => 'pdf',
		);
	}

	/* ---------------------------------------------------------------------
	 * Envio
	 * ------------------------------------------------------------------ */

	/**
	 * Confere e guarda um arquivo recebido, registrando-o na página.
	 *
	 * Recebe um caminho, e não a entrada de `$_FILES`: a conferência de
	 * `is_uploaded_file()` fica no handler, e com isso esta metade é medível por
	 * WP-CLI, onde nenhum arquivo chega por upload.
	 *
	 * Imagem JPEG, PNG e WebP é **regravada**, não copiada. Foto de celular traz
	 * no EXIF o modelo do aparelho, a data e, muitas vezes, as coordenadas de
	 * onde foi tirada — dado pessoal que quem cola a imagem nem sabe que está
	 * publicando. Regravar pelo GD descarta todo metadado. GIF é copiado: o GD
	 * achataria a animação no primeiro quadro, e o formato não carrega GPS.
	 *
	 * @param WP_Post $pagina    Página em que o arquivo é enviado, já conferida.
	 * @param string  $caminho   Caminho do arquivo temporário.
	 * @param string  $nome      Nome original, como veio do navegador.
	 * @param int     $erro      Código de erro do PHP para o upload.
	 * @return array|WP_Error
	 */
	public static function guardar( $pagina, $caminho, $nome, $erro = UPLOAD_ERR_OK ) {
		// Segunda camada, no molde das outras operações: o handler já conferiu,
		// e esta é a trava que continua de pé se ele regredir — ou se alguém
		// chamar o método de outro lugar.
		if ( ! $pagina instanceof WP_Post || Reconectar_Incubadora::POST_TYPE !== $pagina->post_type || ! current_user_can( 'edit_post', $pagina->ID ) ) {
			return self::erro( 'capacidade', __( 'Você não tem permissão para anexar arquivos a esta página.', 'reconectar-core' ), 403 );
		}

		$limites = self::limites();

		if ( UPLOAD_ERR_INI_SIZE === $erro || UPLOAD_ERR_FORM_SIZE === $erro ) {
			return self::erro_de_tamanho( max( $limites ) );
		}

		if ( UPLOAD_ERR_NO_FILE === $erro ) {
			return self::erro( 'sem_arquivo', __( 'Nenhum arquivo foi recebido.', 'reconectar-core' ), 400 );
		}

		if ( UPLOAD_ERR_OK !== $erro || ! is_string( $caminho ) || '' === $caminho || ! is_file( $caminho ) ) {
			return self::erro( 'servidor', __( 'O arquivo não chegou inteiro ao servidor. Tente de novo.', 'reconectar-core' ), 500 );
		}

		$tamanho = (int) filesize( $caminho );

		if ( $tamanho <= 0 ) {
			return self::erro( 'sem_arquivo', __( 'O arquivo está vazio.', 'reconectar-core' ), 400 );
		}

		$nome = self::nome_original( $nome );
		$tipo = self::tipo_real( $caminho, $nome );

		if ( '' === $tipo ) {
			return self::erro( 'tipo', __( 'Este tipo de arquivo não é aceito. Envie imagem JPG, PNG, GIF ou WebP, ou documento PDF.', 'reconectar-core' ), 415 );
		}

		$imagem = 'application/pdf' !== $tipo;
		$limite = $imagem ? $limites['imagem'] : $limites['pdf'];

		if ( $tamanho > $limite ) {
			return self::erro_de_tamanho( $limite );
		}

		$largura = 0;
		$altura  = 0;

		if ( $imagem ) {
			$dimensoes = @getimagesize( $caminho ); // phpcs:ignore WordPress.PHP.NoSilencedErrors -- imagem corrompida devolve false, e o aviso iria para o log a cada tentativa.

			if ( ! $dimensoes || empty( $dimensoes[0] ) || empty( $dimensoes[1] ) ) {
				return self::erro( 'tipo', __( 'A imagem está corrompida ou incompleta.', 'reconectar-core' ), 415 );
			}

			if ( (int) $dimensoes[0] * (int) $dimensoes[1] > self::PIXELS_MAXIMOS ) {
				return self::erro( 'dimensoes', __( 'A imagem tem dimensões grandes demais. Reduza-a para menos de 5000 pixels de lado e envie de novo.', 'reconectar-core' ), 413 );
			}
		}

		$diretorio = self::diretorio();

		if ( '' === $diretorio ) {
			return self::erro( 'servidor', __( 'O servidor não conseguiu preparar a pasta de arquivos.', 'reconectar-core' ), 500 );
		}

		$extensoes = self::extensoes();
		$arquivo   = wp_generate_password( 32, false ) . '.' . $extensoes[ $tipo ];
		$destino   = trailingslashit( $diretorio ) . $arquivo;

		if ( in_array( $tipo, array( 'image/jpeg', 'image/png', 'image/webp' ), true ) ) {
			$gravado = self::regravar_imagem( $caminho, $tipo, $destino );

			if ( is_wp_error( $gravado ) ) {
				return $gravado;
			}

			$largura = $gravado['largura'];
			$altura  = $gravado['altura'];
		} else {
			if ( ! copy( $caminho, $destino ) ) {
				return self::erro( 'servidor', __( 'Não foi possível gravar o arquivo. Tente de novo.', 'reconectar-core' ), 500 );
			}

			// A mesma permissão que o núcleo dá a um upload comum: sem isto,
			// `copy()` herda a umask do processo.
			$permissao = fileperms( $diretorio ) & 0000666;
			chmod( $destino, $permissao ? $permissao : 0644 ); // phpcs:ignore WordPress.WP.AlternativeFunctions

			if ( $imagem ) {
				$largura = (int) $dimensoes[0];
				$altura  = (int) $dimensoes[1];
			}
		}

		clearstatcache( true, $destino );
		$tamanho = (int) filesize( $destino );

		add_post_meta(
			$pagina->ID,
			self::META,
			array(
				'arquivo'     => $arquivo,
				'nome'        => $nome,
				'mime'        => $tipo,
				'tamanho'     => $tamanho,
				'largura'     => $largura,
				'altura'      => $altura,
				'enviado_por' => get_current_user_id(),
				'enviado_em'  => gmdate( 'Y-m-d H:i:s' ),
			)
		);

		return array(
			'codigo'    => 'enviado',
			'arquivo'   => $arquivo,
			'url'       => Reconectar_Incubadora_Conteudo::url_de_arquivo( $arquivo ),
			'nome'      => $nome,
			'mime'      => $tipo,
			'imagem'    => $imagem,
			'largura'   => $largura,
			'altura'    => $altura,
			'tamanho'   => $tamanho,
			'descricao' => sprintf(
				/* translators: 1: tipo do arquivo (PDF, JPG…), 2: tamanho legível. */
				__( '%1$s, %2$s', 'reconectar-core' ),
				strtoupper( $extensoes[ $tipo ] ),
				// Uma casa decimal só a partir de 1 KB: "77,0 B" não diz nada a mais.
				size_format( $tamanho, $tamanho < KB_IN_BYTES ? 0 : 1 )
			),
		);
	}

	/**
	 * O MIME real do arquivo, se for de um tipo aceito; senão vazio.
	 *
	 * Três conferências, porque cada uma cobre o que a outra deixa passar.
	 * `wp_check_filetype_and_ext()` confere o conteúdo contra a lista fechada e
	 * recusa um `.php` renomeado. `getimagesize()` precisa decodificar o
	 * cabeçalho da imagem, e a assinatura `%PDF-` precisa estar nos primeiros
	 * bytes: um arquivo que só **diz** ser imagem ou PDF não passa em nenhuma das
	 * duas.
	 *
	 * @param string $caminho Caminho do arquivo.
	 * @param string $nome    Nome original, de onde sai a extensão declarada.
	 * @return string
	 */
	private static function tipo_real( $caminho, $nome ) {
		$conferido = wp_check_filetype_and_ext( $caminho, $nome, self::mimes() );
		$tipo      = is_array( $conferido ) && ! empty( $conferido['type'] ) ? $conferido['type'] : '';

		if ( ! isset( self::extensoes()[ $tipo ] ) ) {
			return '';
		}

		if ( 'application/pdf' === $tipo ) {
			$inicio = file_get_contents( $caminho, false, null, 0, 5 ); // phpcs:ignore WordPress.WP.AlternativeFunctions

			return '%PDF-' === $inicio ? $tipo : '';
		}

		$real = wp_get_image_mime( $caminho );

		return $real === $tipo ? $tipo : '';
	}

	/**
	 * Regrava uma imagem pelo GD, já girada e reduzida, sem metadados.
	 *
	 * O GD é imposto, e não escolhido: com o Imagick — instalado no container e
	 * comum em produção —, o editor do núcleo só descarta metadados ao gerar
	 * miniatura, e uma imagem já pequena seria gravada com o EXIF intacto. O GD
	 * não sabe escrever metadado nenhum, e é essa a garantia que se quer.
	 *
	 * `maybe_exif_rotate()` vem antes por consequência: a orientação de uma foto
	 * de celular **mora** no EXIF, e descartá-lo sem aplicar a rotação deitaria a
	 * imagem na página.
	 *
	 * @param string $caminho Arquivo de origem.
	 * @param string $tipo    MIME real.
	 * @param string $destino Caminho final.
	 * @return array{largura: int, altura: int}|WP_Error
	 */
	private static function regravar_imagem( $caminho, $tipo, $destino ) {
		$so_gd = static function () {
			return array( 'WP_Image_Editor_GD' );
		};

		add_filter( 'wp_image_editors', $so_gd, PHP_INT_MAX );

		try {
			$editor = wp_get_image_editor( $caminho, array( 'mime_type' => $tipo ) );
		} finally {
			remove_filter( 'wp_image_editors', $so_gd, PHP_INT_MAX );
		}

		if ( is_wp_error( $editor ) ) {
			return self::erro( 'tipo', __( 'O servidor não conseguiu ler esta imagem.', 'reconectar-core' ), 415 );
		}

		$editor->maybe_exif_rotate();

		$tamanho = $editor->get_size();

		if ( $tamanho['width'] > self::LADO_MAXIMO || $tamanho['height'] > self::LADO_MAXIMO ) {
			$reduzida = $editor->resize( self::LADO_MAXIMO, self::LADO_MAXIMO, false );

			if ( is_wp_error( $reduzida ) ) {
				return self::erro( 'servidor', __( 'O servidor não conseguiu reduzir esta imagem.', 'reconectar-core' ), 500 );
			}
		}

		$salvo = $editor->save( $destino, $tipo );

		if ( is_wp_error( $salvo ) || empty( $salvo['path'] ) || $salvo['path'] !== $destino ) {
			if ( is_array( $salvo ) && ! empty( $salvo['path'] ) && is_file( $salvo['path'] ) ) {
				wp_delete_file( $salvo['path'] );
			}

			return self::erro( 'servidor', __( 'Não foi possível gravar a imagem. Tente de novo.', 'reconectar-core' ), 500 );
		}

		return array(
			'largura' => (int) $salvo['width'],
			'altura'  => (int) $salvo['height'],
		);
	}

	/**
	 * Nome original limpo, para o download e o texto do link.
	 *
	 * Não passa por `sanitize_file_name()`: ele tira acento e troca espaço por
	 * hífen, e "Relatório final.pdf" chegava ao link como
	 * "Relatorio-final.pdf". Este nome nunca vira caminho em disco — o arquivo
	 * é gravado com o nome sorteado — e cada saída o escapa no próprio
	 * contexto (`nome_ascii()` e `rawurlencode()` no cabeçalho, o sanitizador
	 * no conteúdo). Basta tirar o que não é texto: marcação, controle e os
	 * separadores de caminho.
	 *
	 * @param string $nome Nome como veio do navegador.
	 * @return string
	 */
	private static function nome_original( $nome ) {
		$nome = str_replace( '\\', '/', (string) $nome );
		$nome = sanitize_text_field( wp_basename( $nome ) );
		$nome = trim( (string) preg_replace( '/[\x00-\x1F\x7F"<>|:*?]+/u', '', $nome ), ' .' );

		if ( mb_strlen( $nome ) > 200 ) {
			$nome = mb_substr( $nome, -200 );
		}

		return '' !== $nome ? $nome : 'arquivo';
	}

	/* ---------------------------------------------------------------------
	 * Entrega
	 * ------------------------------------------------------------------ */

	/**
	 * Entrega um arquivo a quem está logado.
	 *
	 * Os cabeçalhos são a segunda metade da conferência de tipo do envio.
	 * `nosniff` impede o navegador de adivinhar HTML num arquivo declarado como
	 * imagem; o `Content-Security-Policy` com `sandbox` faz que, mesmo aberto
	 * direto na aba, o arquivo não rode script nem alcance a sessão do site.
	 *
	 * O PDF sai como **download**. O leitor de PDF do Chrome não abre sob
	 * `sandbox`, e afrouxar a política só para ele abriria a exceção justamente
	 * no formato que carrega script próprio.
	 *
	 * `private` no cache: o arquivo é de quem está logado, e um proxy no caminho
	 * não pode guardá-lo para servir a outro.
	 *
	 * @return void
	 */
	public static function entregar() {
		$metodo = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_key( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : '';

		if ( ! in_array( $metodo, array( 'GET', 'HEAD' ), true ) ) {
			header( 'Allow: GET, HEAD' );
			self::recusar_entrega( 405, __( 'Método não aceito.', 'reconectar-core' ) );
		}

		if ( ! is_user_logged_in() ) {
			self::recusar_entrega( 401, __( 'Entre na plataforma para ver este arquivo.', 'reconectar-core' ) );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- rota de leitura embutida em `<img>`; a trava é a sessão.
		$arquivo = isset( $_GET['arquivo'] ) ? sanitize_text_field( wp_unslash( $_GET['arquivo'] ) ) : '';

		// A expressão é a trava contra travessia de diretório: com ela não há
		// barra, ponto extra nem extensão fora da lista que chegue ao disco.
		if ( ! preg_match( Reconectar_Incubadora_Conteudo::PADRAO_ARQUIVO, $arquivo ) ) {
			self::recusar_entrega( 404, __( 'Arquivo não encontrado.', 'reconectar-core' ) );
		}

		$diretorio = self::diretorio();
		$caminho   = '' !== $diretorio ? trailingslashit( $diretorio ) . $arquivo : '';

		if ( '' === $caminho || ! is_file( $caminho ) ) {
			self::recusar_entrega( 404, __( 'Arquivo não encontrado.', 'reconectar-core' ) );
		}

		$extensao = strtolower( pathinfo( $arquivo, PATHINFO_EXTENSION ) );
		$mime     = 'jpeg' === $extensao ? 'image/jpeg' : array_search( $extensao, self::extensoes(), true );
		$pdf      = 'application/pdf' === $mime;

		status_header( 200 );
		header( 'Content-Type: ' . $mime );
		header( 'Content-Length: ' . filesize( $caminho ) );
		header( 'X-Content-Type-Options: nosniff' );
		header( "Content-Security-Policy: default-src 'none'; sandbox" );
		header( 'Cache-Control: private, max-age=86400' );
		header( 'X-Robots-Tag: noindex, nofollow' );

		if ( $pdf ) {
			$nome = self::nome_registrado( $arquivo );
			header( 'Content-Disposition: attachment; filename="' . self::nome_ascii( $nome ) . '"; filename*=UTF-8\'\'' . rawurlencode( $nome ) );
		} else {
			header( 'Content-Disposition: inline' );
		}

		if ( 'HEAD' !== $metodo ) {
			readfile( $caminho ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		}

		exit;
	}

	/**
	 * Recusa a entrega com status e texto simples, e encerra.
	 *
	 * Texto, e não a tela do `wp_die()`: quem pede é quase sempre um `<img>`, e
	 * uma página HTML inteira como resposta a uma imagem só gastaria banda.
	 *
	 * @param int    $status   Status HTTP.
	 * @param string $mensagem Texto da resposta.
	 * @return void
	 */
	private static function recusar_entrega( $status, $mensagem ) {
		nocache_headers();
		status_header( $status );
		header( 'Content-Type: text/plain; charset=utf-8' );
		header( 'X-Content-Type-Options: nosniff' );
		echo esc_html( $mensagem );
		exit;
	}

	/**
	 * Nome original de um arquivo, pela meta da página em que foi enviado.
	 *
	 * @param string $arquivo Nome em disco, já conferido por `PADRAO_ARQUIVO`.
	 * @return string O nome original, ou o próprio nome em disco.
	 */
	private static function nome_registrado( $arquivo ) {
		global $wpdb;

		$valor = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT meta_value FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value LIKE %s LIMIT 1",
				self::META,
				'%' . $wpdb->esc_like( '"' . $arquivo . '"' ) . '%'
			)
		);

		$dados = maybe_unserialize( $valor );

		return is_array( $dados ) && ! empty( $dados['nome'] ) ? (string) $dados['nome'] : $arquivo;
	}

	/**
	 * Versão ASCII de um nome, para o `filename` sem asterisco.
	 *
	 * Navegador antigo lê só `filename`, e um acento cru ali sai como lixo ou
	 * quebra o cabeçalho; o `filename*` leva o nome de verdade.
	 *
	 * @param string $nome Nome original.
	 * @return string
	 */
	private static function nome_ascii( $nome ) {
		$ascii = preg_replace( '/[^A-Za-z0-9._-]+/', '-', remove_accents( $nome ) );

		return '' !== trim( (string) $ascii, '-' ) ? $ascii : 'arquivo';
	}

	/* ---------------------------------------------------------------------
	 * Varredura
	 * ------------------------------------------------------------------ */

	/**
	 * Lista — e, sem `$simular`, apaga — os arquivos que nenhuma página cita.
	 *
	 * Procura o nome no conteúdo de toda página da Incubadora, em qualquer
	 * situação, inclusive lixeira, **e em todas as revisões delas**. As revisões
	 * contam porque restaurar uma versão antiga traz de volta o `<img>` que ela
	 * tinha; apagar o arquivo de uma versão ainda restaurável faria o histórico
	 * devolver imagem quebrada.
	 *
	 * Não roda sozinha. Chame por WP-CLI, primeiro simulando:
	 *
	 *     wp eval 'print_r( Reconectar_Incubadora_Arquivos::limpar_orfaos() );'
	 *     wp eval 'print_r( Reconectar_Incubadora_Arquivos::limpar_orfaos( false ) );'
	 *
	 * @param bool $simular Só lista, sem apagar nada.
	 * @return string[] Nomes dos arquivos órfãos.
	 */
	public static function limpar_orfaos( $simular = true ) {
		$diretorio = self::diretorio();

		if ( '' === $diretorio ) {
			return array();
		}

		$citados = self::arquivos_citados();
		$limite  = time() - self::IDADE_DE_ORFAO;
		$orfaos  = array();

		foreach ( (array) scandir( $diretorio ) as $arquivo ) {
			if ( ! is_string( $arquivo ) || ! preg_match( Reconectar_Incubadora_Conteudo::PADRAO_ARQUIVO, $arquivo ) || isset( $citados[ $arquivo ] ) ) {
				continue;
			}

			$caminho = trailingslashit( $diretorio ) . $arquivo;

			if ( filemtime( $caminho ) > $limite ) {
				continue;
			}

			$orfaos[] = $arquivo;

			if ( ! $simular ) {
				wp_delete_file( $caminho );
				self::apagar_registros( $arquivo );
			}
		}

		return $orfaos;
	}

	/**
	 * Nomes de arquivo citados nas páginas e nas revisões delas.
	 *
	 * @return array<string, true>
	 */
	private static function arquivos_citados() {
		global $wpdb;

		$conteudos = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT post_content FROM {$wpdb->posts} WHERE post_type = %s
				UNION ALL
				SELECT r.post_content FROM {$wpdb->posts} r INNER JOIN {$wpdb->posts} m ON r.post_parent = m.ID WHERE r.post_type = 'revision' AND m.post_type = %s",
				Reconectar_Incubadora::POST_TYPE,
				Reconectar_Incubadora::POST_TYPE
			)
		);

		$citados = array();

		foreach ( $conteudos as $conteudo ) {
			if ( preg_match_all( '/arquivo=([A-Za-z0-9]{32}\.(?:jpe?g|png|gif|webp|pdf))/', (string) $conteudo, $m ) ) {
				foreach ( $m[1] as $nome ) {
					$citados[ $nome ] = true;
				}
			}
		}

		return $citados;
	}

	/**
	 * Apaga as linhas de meta que registram um arquivo.
	 *
	 * Por `delete_metadata_by_mid()`, e não por `DELETE` direto: o núcleo guarda
	 * a meta em cache de objeto, e o `DELETE` cru deixaria a leitura seguinte
	 * vendo o registro de um arquivo que já não existe.
	 *
	 * @param string $arquivo Nome em disco.
	 * @return void
	 */
	private static function apagar_registros( $arquivo ) {
		global $wpdb;

		$ids = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT meta_id FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value LIKE %s",
				self::META,
				'%' . $wpdb->esc_like( '"' . $arquivo . '"' ) . '%'
			)
		);

		foreach ( $ids as $id ) {
			delete_metadata_by_mid( 'post', (int) $id );
		}
	}

	/* ---------------------------------------------------------------------
	 * Apoio
	 * ------------------------------------------------------------------ */

	/**
	 * Devolve o diretório dos arquivos, garantindo a proteção dele.
	 *
	 * Cópia de `Reconectar_Comprovante::diretorio()`, e as razões são as mesmas:
	 * idempotente, roda em todo envio e toda entrega — é assim que a proteção
	 * sobrevive a um `uploads/` recriado do zero no deploy —, com as duas
	 * sintaxes do Apache no `.htaccess` e um `index.php` vazio para o servidor
	 * que ignora o `.htaccess`. Em nginx quem protege é o nome sorteado.
	 *
	 * @return string Caminho absoluto, ou vazio se não foi possível criá-lo.
	 */
	public static function diretorio() {
		$uploads = wp_upload_dir( null, false );

		if ( ! empty( $uploads['error'] ) || empty( $uploads['basedir'] ) ) {
			return '';
		}

		$caminho = trailingslashit( $uploads['basedir'] ) . self::PASTA;

		if ( ! is_dir( $caminho ) && ! wp_mkdir_p( $caminho ) ) {
			return '';
		}

		$htaccess = $caminho . '/.htaccess';

		if ( ! file_exists( $htaccess ) ) {
			file_put_contents( $htaccess, "Require all denied\n<IfModule !mod_authz_core.c>\ndeny from all\n</IfModule>\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		}

		$indice = $caminho . '/index.php';

		if ( ! file_exists( $indice ) ) {
			file_put_contents( $indice, "<?php\n// Silence is golden.\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		}

		return $caminho;
	}

	/**
	 * Erro de tamanho, com o limite escrito por extenso.
	 *
	 * @param int $limite Limite em bytes.
	 * @return WP_Error
	 */
	private static function erro_de_tamanho( $limite ) {
		return self::erro(
			'tamanho',
			sprintf(
				/* translators: %s: tamanho máximo legível, como "2 MB". */
				__( 'O arquivo passa do limite de %s. Reduza-o e envie de novo.', 'reconectar-core' ),
				size_format( $limite )
			),
			413
		);
	}

	/**
	 * Monta um `WP_Error` com o status HTTP, no formato de `Reconectar_Incubadora_Acoes`.
	 *
	 * @param string $codigo   Código que o editor lê.
	 * @param string $mensagem Mensagem para a pessoa.
	 * @param int    $status   Status HTTP.
	 * @return WP_Error
	 */
	private static function erro( $codigo, $mensagem, $status ) {
		return new WP_Error( $codigo, $mensagem, array( 'status' => (int) $status ) );
	}
}
