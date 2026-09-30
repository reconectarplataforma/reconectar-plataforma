<?php
/**
 * Comprovante de pagamento: o comprador envia, a loja confere e confirma.
 *
 * O pagamento desta plataforma é direto do comprador para cada loja — PIX ou
 * transferência, sem gateway intermediário e sem dinheiro passando pela
 * Reconectar. Isso deixa um vão no fluxo: quem pagou não tem como dizer que
 * pagou, e quem vendeu só descobre olhando o próprio extrato bancário, sem
 * nenhum vínculo com o número do pedido. Este módulo fecha esse vão, e só ele:
 * continua não existindo cobrança automática, conciliação nem retenção.
 *
 * **A granularidade é por loja, não por pedido.** Num carrinho de três lojas há
 * três pagamentos, com três meios possivelmente diferentes, e portanto três
 * comprovantes. `Reconectar_Pagamento_Direto::lojas_do_pedido()` já devolve o
 * sub-pedido do Dokan de cada loja, e é nele que a meta é gravada — um arquivo
 * único no pedido pai faria o vendedor A enxergar o comprovante do pagamento
 * feito ao vendedor B, que é dado bancário de terceiro e o oposto do que a LGPD
 * pede.
 *
 * **O arquivo não vai para a Media Library.** Comprovante bancário traz nome,
 * valor, agência e conta; um anexo comum recebe URL pública em
 * `/wp-content/uploads/`, entra no sitemap de mídia e é enumerável. O arquivo
 * mora num subdiretório protegido por `.htaccess`, com nome sorteado, e a única
 * porta é a rota `admin-post.php` deste módulo, que confere quem pede antes de
 * entregar.
 *
 * **Confirmar move o sub-pedido, nunca o pai.** Numa compra multi-loja, a loja
 * que recebeu confirma o que é dela; o pedido pai continua aguardando até a
 * última confirmar. Sincronizar o pai exigiria inventar o que significa "metade
 * pago", e uma semântica inventada numa tela de pagamento é pior que a ausência
 * dela.
 *
 * @package reconectar-core
 */

defined( 'ABSPATH' ) || exit;

class Reconectar_Comprovante {

	/**
	 * Dados do arquivo enviado, gravados **no sub-pedido** da loja.
	 *
	 * Guarda `arquivo` (o nome sorteado, que é o que existe em disco), `nome`
	 * (o nome original, só para exibição), `mime`, `enviado_em` e `enviado_por`.
	 */
	const META_ARQUIVO = '_reconectar_comprovante';

	/**
	 * Marca de que a loja conferiu e deu o pagamento por recebido.
	 *
	 * Existe além do status porque o status pode ser mudado por qualquer outro
	 * caminho do WooCommerce, e aí a tela não saberia dizer quem confirmou nem
	 * quando.
	 */
	const META_CONFIRMADO = '_reconectar_pagamento_confirmado';

	/**
	 * Ações de `admin-post.php`.
	 *
	 * `admin-post.php` mora dentro de `/wp-admin` e teria caído no portão de
	 * `Reconectar_Permissoes::bloquear_area_administrativa()`, que já abre uma
	 * exceção explícita para ela — nada precisa ser liberado aqui.
	 */
	const ACAO_ENVIAR = 'reconectar_enviar_comprovante';

	/**
	 * Entrega do arquivo. É a única porta para ele.
	 */
	const ACAO_BAIXAR = 'reconectar_baixar_comprovante';

	/**
	 * Confirmação do recebimento pela loja.
	 */
	const ACAO_CONFIRMAR = 'reconectar_confirmar_pagamento';

	/**
	 * Subdiretório de `wp_upload_dir()['basedir']` onde os arquivos ficam.
	 */
	const PASTA = 'reconectar-comprovantes';

	/**
	 * Teto do arquivo.
	 *
	 * Comprovante é uma página; 5 MB cobre com folga um PDF de banco ou uma foto
	 * de tela de celular, e segura o volume de um diretório que não é limpo por
	 * ninguém.
	 */
	const TAMANHO_MAX = 5242880;

	/**
	 * Ids dos sub-pedidos cuja célula pediu um formulário de confirmação.
	 *
	 * A tabela de pedidos do Dokan é impressa **dentro** de um
	 * `<form id="order-filter" method="POST">`, o das ações em massa. Um `<form>`
	 * escrito na célula seria aninhado — o navegador descarta o interno e o botão
	 * passa a submeter as ações em massa do Dokan, em silêncio. Daí o arranjo em
	 * duas partes: a célula imprime só o `<button form="rc-confirmar-<id>">` e
	 * anota o id aqui; `formularios_de_confirmacao()` imprime os `<form>`
	 * correspondentes em `dokan_order_content_inside_after`, que fica **fora** do
	 * form de terceiro. O atributo `form` do HTML5 define o dono explicitamente e
	 * sobrepõe o ancestral, então nenhum campo do Dokan é serializado junto.
	 *
	 * @var int[]
	 */
	private static $confirmacoes_pendentes = array();

	/**
	 * Registra os ganchos.
	 *
	 * As três rotas ganham o par `nopriv` porque a compra pode ser de convidado:
	 * sem ele, o comprador sem conta receberia um `0` numa tela branca
	 * exatamente na tela de agradecimento, que é onde o campo mais aparece. Quem
	 * autoriza nesse caso é a chave do pedido, a mesma credencial que o
	 * WooCommerce usa para deixar o convidado ver aquela tela.
	 *
	 * `ACAO_CONFIRMAR` fica de fora do `nopriv` de propósito: confirmar
	 * recebimento é ato de quem administra a loja, e não existe caminho de
	 * convidado para ele.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'admin_post_' . self::ACAO_ENVIAR, array( __CLASS__, 'processar_envio' ) );
		add_action( 'admin_post_nopriv_' . self::ACAO_ENVIAR, array( __CLASS__, 'processar_envio' ) );

		add_action( 'admin_post_' . self::ACAO_BAIXAR, array( __CLASS__, 'baixar' ) );
		add_action( 'admin_post_nopriv_' . self::ACAO_BAIXAR, array( __CLASS__, 'baixar' ) );

		add_action( 'admin_post_' . self::ACAO_CONFIRMAR, array( __CLASS__, 'confirmar' ) );

		add_action( 'dokan_order_content_inside_before', array( __CLASS__, 'aviso_no_painel' ) );
		add_action( 'dokan_order_listing_header_before_action_column', array( __CLASS__, 'coluna_cabecalho' ) );
		add_action( 'dokan_order_listing_row_before_action_field', array( __CLASS__, 'coluna_celula' ) );
		add_action( 'dokan_order_content_inside_after', array( __CLASS__, 'formularios_de_confirmacao' ) );

		// Interface nova do painel (`vendor_layout_style = latest`): a lista de
		// pedidos é React e lê `/dokan/v1/orders`, então nenhum dos ganchos de
		// template acima dispara nela. O dado chega pela API e a coluna, pelo
		// script; o detalhe do pedido continua sendo template PHP nas duas
		// interfaces, e o painel dele serve às duas.
		add_filter( 'dokan_rest_prepare_shop_order_object', array( __CLASS__, 'dados_na_api_do_painel' ), 10, 2 );
		add_action( 'dokan_order_detail_after_order_general_details', array( __CLASS__, 'painel_no_detalhe' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'carregar_script_do_painel' ), 20 );

		add_filter( 'posts_clauses', array( __CLASS__, 'priorizar_conferencia' ), 10, 2 );
	}

	/* ---------------------------------------------------------------------
	 * Armazenamento
	 * ------------------------------------------------------------------ */

	/**
	 * Devolve o diretório dos comprovantes, garantindo a proteção dele.
	 *
	 * Idempotente: só escreve o que ainda não existe, e roda em toda requisição
	 * que envia ou entrega arquivo — é assim que a proteção sobrevive a um
	 * `uploads/` recriado do zero, que é justamente o caso do deploy, onde
	 * `uploads/` não é versionado nem sincronizado.
	 *
	 * As duas diretivas do `.htaccess` são propositais: `Require all denied` é a
	 * sintaxe do Apache 2.4 e `deny from all` a do 2.2, e um servidor entende
	 * uma e ignora a outra. O `index.php` vazio é a terceira camada, para o caso
	 * de o `.htaccess` ser ignorado por completo — em nginx, por exemplo, ele é
	 * inerte, e aí quem protege é o nome sorteado do arquivo.
	 *
	 * @return string Caminho absoluto, ou string vazia se o upload falhou.
	 */
	private static function diretorio() {
		$uploads = wp_upload_dir();

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
	 * Extensões aceitas, no formato que `wp_check_filetype_and_ext()` espera.
	 *
	 * A lista é fechada e passa como terceiro argumento daquela função, o que
	 * faz a checagem ser por **conteúdo** e não por nome: um `.exe` renomeado
	 * para `.pdf` não casa o tipo real e é recusado antes de tocar o disco.
	 *
	 * @return array<string,string> Mapa de extensão para MIME.
	 */
	private static function mimes() {
		return array(
			'pdf'      => 'application/pdf',
			'jpg|jpeg' => 'image/jpeg',
			'png'      => 'image/png',
		);
	}

	/**
	 * Apaga do disco o arquivo descrito por uma meta.
	 *
	 * Chamado quando o comprador substitui um comprovante: sem isso o diretório
	 * acumularia toda tentativa, e nenhuma delas alcançável por rota nenhuma.
	 *
	 * @param array $dados Conteúdo de `META_ARQUIVO`.
	 * @return void
	 */
	private static function apagar_arquivo( $dados ) {
		if ( empty( $dados['arquivo'] ) ) {
			return;
		}

		$diretorio = self::diretorio();

		if ( '' === $diretorio ) {
			return;
		}

		// `basename` contra um nome que tenha vindo do banco com travessia de
		// diretório: a meta é nossa, mas um `../../wp-config.php` gravado por
		// outro caminho apagaria o que não deve.
		$caminho = trailingslashit( $diretorio ) . basename( (string) $dados['arquivo'] );

		if ( is_file( $caminho ) ) {
			wp_delete_file( $caminho );
		}
	}

	/* ---------------------------------------------------------------------
	 * Leitura
	 * ------------------------------------------------------------------ */

	/**
	 * Devolve os dados do comprovante de um sub-pedido.
	 *
	 * @param WC_Order $pedido Sub-pedido da loja.
	 * @return array Dados gravados, ou array vazio.
	 */
	public static function comprovante( $pedido ) {
		if ( ! $pedido instanceof WC_Order ) {
			return array();
		}

		$dados = $pedido->get_meta( self::META_ARQUIVO );

		return is_array( $dados ) ? $dados : array();
	}

	/**
	 * Diz se a loja já deu este sub-pedido por pago.
	 *
	 * Confere a meta **e** o status: a meta responde por quem confirmou pela
	 * tela deste módulo, e o status cobre quem mudou o pedido por qualquer outro
	 * caminho do WooCommerce — o painel do Dokan, o admin, um script.
	 *
	 * @param WC_Order $pedido Sub-pedido da loja.
	 * @return bool
	 */
	public static function confirmado( $pedido ) {
		if ( ! $pedido instanceof WC_Order ) {
			return false;
		}

		if ( $pedido->get_meta( self::META_CONFIRMADO ) ) {
			return true;
		}

		return ! $pedido->has_status( Reconectar_Pagamento_Direto::STATUS_AGUARDANDO );
	}

	/**
	 * Diz se o usuário atual é o comprador do pedido.
	 *
	 * O recuo pela chave do pedido existe para a compra sem conta: é a mesma
	 * credencial que o WooCommerce exige para o convidado abrir a tela de
	 * agradecimento, e sem ela o campo não funcionaria justamente ali.
	 *
	 * A chave é comparada com `hash_equals()`, que não vaza por tempo de
	 * resposta o quanto duas chaves têm de prefixo em comum.
	 *
	 * @param WC_Order $pedido Pedido ou sub-pedido.
	 * @return bool
	 */
	private static function comprador_pode( $pedido ) {
		if ( ! $pedido instanceof WC_Order ) {
			return false;
		}

		// Num carrinho multi-loja quem guarda o cliente é o pedido pai; o
		// sub-pedido do Dokan nasce com o mesmo cliente, mas comparar contra o
		// pai quando ele existe evita depender disso.
		$pai      = $pedido->get_parent_id() ? wc_get_order( $pedido->get_parent_id() ) : $pedido;
		$dono     = $pai instanceof WC_Order ? (int) $pai->get_customer_id() : (int) $pedido->get_customer_id();
		$usuario  = get_current_user_id();

		if ( $dono && $dono === $usuario ) {
			return true;
		}

		$chave = isset( $_REQUEST['chave'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['chave'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification

		if ( '' === $chave || ! $pai instanceof WC_Order ) {
			return false;
		}

		return hash_equals( (string) $pai->get_order_key(), $chave );
	}

	/**
	 * Diz se o usuário atual administra a loja dona deste sub-pedido.
	 *
	 * O dono vem de `dokan_get_seller_id_by_order()`, a mesma função que
	 * `lojas_do_pedido()` usa — nunca de `post_author`, que sob HPOS deixa de
	 * ser a fonte da verdade e devolveria o dono errado sem erro nenhum.
	 *
	 * @param WC_Order $pedido Sub-pedido da loja.
	 * @return bool
	 */
	private static function loja_pode( $pedido ) {
		if ( ! $pedido instanceof WC_Order || ! is_user_logged_in() ) {
			return false;
		}

		if ( current_user_can( 'manage_woocommerce' ) ) {
			return true;
		}

		if ( ! function_exists( 'dokan_get_seller_id_by_order' ) ) {
			return false;
		}

		return (int) dokan_get_seller_id_by_order( $pedido->get_id() ) === get_current_user_id();
	}

	/* ---------------------------------------------------------------------
	 * Rotas
	 * ------------------------------------------------------------------ */

	/**
	 * Atende ao POST de envio do comprovante.
	 *
	 * Toda recusa volta para a tela de origem com um aviso na query string, e
	 * nenhuma delas imprime nada antes do redirect: um único aviso do PHP
	 * impresso aqui derrubaria os `header()` de `wp_safe_redirect()` e a ação
	 * simplesmente não aconteceria, com a tela parecendo apenas feia — a
	 * armadilha já registrada no `CLAUDE.md`.
	 *
	 * @return void
	 */
	public static function processar_envio() {
		$pedido_id = isset( $_POST['pedido'] ) ? (int) $_POST['pedido'] : 0;

		check_admin_referer( self::ACAO_ENVIAR . '_' . $pedido_id );

		$pedido = wc_get_order( $pedido_id );

		if ( ! $pedido instanceof WC_Order || ! self::comprador_pode( $pedido ) ) {
			self::recusar( __( 'Este pedido não é seu, ou a sessão expirou.', 'reconectar-core' ) );
		}

		if ( self::confirmado( $pedido ) ) {
			self::voltar( 'ja-confirmado', $pedido_id );
		}

		if ( empty( $_FILES['comprovante']['name'] ) ) {
			self::voltar( 'sem-arquivo', $pedido_id );
		}

		$arquivo = $_FILES['comprovante']; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput

		if ( ! isset( $arquivo['error'] ) || UPLOAD_ERR_OK !== (int) $arquivo['error'] ) {
			// `UPLOAD_ERR_INI_SIZE` chega aqui com o arquivo já descartado pelo
			// PHP, antes de qualquer verificação nossa — tratar junto do teto
			// próprio dá ao comprador a mesma frase nos dois casos, que é o que
			// ele precisa saber.
			self::voltar( 'grande', $pedido_id );
		}

		if ( (int) $arquivo['size'] > self::TAMANHO_MAX ) {
			self::voltar( 'grande', $pedido_id );
		}

		$conferido = wp_check_filetype_and_ext( $arquivo['tmp_name'], $arquivo['name'], self::mimes() );

		if ( empty( $conferido['ext'] ) || empty( $conferido['type'] ) ) {
			self::voltar( 'tipo', $pedido_id );
		}

		$diretorio = self::diretorio();

		if ( '' === $diretorio ) {
			self::voltar( 'disco', $pedido_id );
		}

		// O nome vai sorteado, e não derivado do original: é a camada que
		// continua de pé se o `.htaccess` um dia virar inerte. O nome que o
		// comprador reconhece fica só na meta, para a tela.
		$nome_em_disco = wp_generate_password( 32, false ) . '.' . $conferido['ext'];
		$destino       = trailingslashit( $diretorio ) . $nome_em_disco;

		if ( ! move_uploaded_file( $arquivo['tmp_name'], $destino ) ) {
			self::voltar( 'disco', $pedido_id );
		}

		// O padrão do PHP para arquivo movido é a máscara do processo, que pode
		// deixá-lo executável; 0644 é o mesmo que o WordPress aplica aos anexos.
		chmod( $destino, 0644 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors

		$anterior = self::comprovante( $pedido );

		if ( $anterior ) {
			self::apagar_arquivo( $anterior );
		}

		$pedido->update_meta_data(
			self::META_ARQUIVO,
			array(
				'arquivo'     => $nome_em_disco,
				'nome'        => sanitize_file_name( $arquivo['name'] ),
				'mime'        => $conferido['type'],
				'enviado_em'  => current_time( 'mysql' ),
				'enviado_por' => get_current_user_id(),
			)
		);

		// Nota privada: ela é o registro que sobrevive se a meta for
		// sobrescrita por um envio seguinte, e é onde a loja lê o histórico.
		$pedido->add_order_note(
			$anterior
				? __( 'O comprador substituiu o comprovante de pagamento.', 'reconectar-core' )
				: __( 'O comprador enviou um comprovante de pagamento.', 'reconectar-core' )
		);

		$pedido->save();

		// Depois do `save()`, nunca antes: `update_status()` dispara ganchos de
		// terceiro que podem ler a meta, e no meio da transação eles leriam o
		// comprovante anterior. A guarda evita nota duplicada quando o comprador
		// substitui o arquivo — `update_status()` para o status em que o pedido já
		// está não muda nada e ainda grava a nota.
		if ( ! $pedido->has_status( self::status_de_conferencia() ) ) {
			$pedido->update_status(
				self::status_de_conferencia(),
				__( 'O comprador enviou o comprovante; o pagamento aguarda conferência da loja.', 'reconectar-core' )
			);
		}

		self::voltar( 'enviado', $pedido_id );
	}

	/**
	 * O status de conferência sem o prefixo `wc-`.
	 *
	 * A constante guarda o status como ele é gravado no banco, com prefixo;
	 * `update_status()` e `has_status()` trabalham com a forma crua. O helper
	 * existe para a conversão não aparecer escrita à mão em cada ponto de uso —
	 * foi o que aconteceu com `PREPARACAO` em `confirmar()`.
	 *
	 * @return string
	 */
	private static function status_de_conferencia() {
		return str_replace( 'wc-', '', Reconectar_Status_Pedido::CONFERENCIA );
	}

	/**
	 * Entrega o arquivo a quem tem direito a ele.
	 *
	 * `admin-post.php` dispara `admin_post_<ação>` também em GET, porque lê
	 * `$_REQUEST['action']` — é o que permite que esta rota seja um link comum,
	 * sem formulário e sem JavaScript.
	 *
	 * @return void
	 */
	public static function baixar() {
		$pedido_id = isset( $_GET['pedido'] ) ? (int) $_GET['pedido'] : 0;

		check_admin_referer( self::ACAO_BAIXAR . '_' . $pedido_id );

		$pedido = wc_get_order( $pedido_id );

		if ( ! $pedido instanceof WC_Order ) {
			self::recusar( __( 'Pedido não encontrado.', 'reconectar-core' ) );
		}

		if ( ! self::comprador_pode( $pedido ) && ! self::loja_pode( $pedido ) ) {
			self::recusar( __( 'Você não tem permissão para ver este comprovante.', 'reconectar-core' ) );
		}

		$dados     = self::comprovante( $pedido );
		$diretorio = self::diretorio();

		if ( empty( $dados['arquivo'] ) || '' === $diretorio ) {
			self::recusar( __( 'Não há comprovante enviado para este pedido.', 'reconectar-core' ) );
		}

		$caminho = trailingslashit( $diretorio ) . basename( (string) $dados['arquivo'] );

		if ( ! is_file( $caminho ) ) {
			self::recusar( __( 'O arquivo do comprovante não está mais disponível.', 'reconectar-core' ) );
		}

		$nome = ! empty( $dados['nome'] ) ? $dados['nome'] : basename( $caminho );
		$mime = ! empty( $dados['mime'] ) ? $dados['mime'] : 'application/octet-stream';

		// `inline` porque o pedido é **ver** o comprovante: PDF, JPG e PNG — a
		// lista fechada de `mimes()`, conferida pelo conteúdo no envio — abrem
		// no próprio navegador, e o botão de salvar dele continua ali. Com
		// `attachment` a loja baixava um arquivo por pedido só para conferir um
		// valor, e a pasta de downloads virava arquivo de dado bancário alheio.
		$disposicao = in_array( $mime, array_values( self::mimes() ), true ) ? 'inline' : 'attachment';

		nocache_headers();
		header( 'Content-Type: ' . $mime );
		header( 'Content-Disposition: ' . $disposicao . '; filename="' . rawurlencode( $nome ) . '"' );
		header( 'Content-Length: ' . filesize( $caminho ) );
		// `X-Content-Type-Options` porque o arquivo é conteúdo de terceiro: sem
		// ele um navegador antigo pode adivinhar o tipo e interpretar como HTML
		// o que o servidor declarou como imagem.
		header( 'X-Content-Type-Options: nosniff' );

		readfile( $caminho ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		exit;
	}

	/**
	 * Registra que a loja conferiu o comprovante e recebeu o pagamento.
	 *
	 * Move **só o sub-pedido** para `preparacao`. O pedido pai fica como está,
	 * pelo motivo registrado no cabeçalho deste arquivo.
	 *
	 * @return void
	 */
	public static function confirmar() {
		$pedido_id = isset( $_POST['pedido'] ) ? (int) $_POST['pedido'] : 0;

		check_admin_referer( self::ACAO_CONFIRMAR . '_' . $pedido_id );

		$pedido = wc_get_order( $pedido_id );

		if ( ! $pedido instanceof WC_Order || ! self::loja_pode( $pedido ) ) {
			self::recusar( __( 'Você não administra a loja deste pedido.', 'reconectar-core' ) );
		}

		if ( self::confirmado( $pedido ) ) {
			self::voltar( 'ja-confirmado', $pedido_id );
		}

		$usuario = wp_get_current_user();

		$pedido->update_meta_data(
			self::META_CONFIRMADO,
			array(
				'em'  => current_time( 'mysql' ),
				'por' => get_current_user_id(),
			)
		);
		$pedido->save();

		$pedido->update_status(
			str_replace( 'wc-', '', Reconectar_Status_Pedido::PREPARACAO ),
			sprintf(
				/* translators: %s: nome de quem confirmou o recebimento. */
				__( 'Pagamento confirmado por %s a partir do comprovante enviado.', 'reconectar-core' ),
				$usuario->display_name
			)
		);

		self::voltar( 'confirmado', $pedido_id );
	}

	/* ---------------------------------------------------------------------
	 * Volta e recusa
	 * ------------------------------------------------------------------ */

	/**
	 * Devolve o usuário para onde ele estava, com um aviso e a âncora do bloco.
	 *
	 * A âncora importa porque numa compra multi-loja há um cartão por loja: sem
	 * ela o comprador voltaria ao topo da página, e o resultado do envio ficaria
	 * fora da tela — o que se lê como "não funcionou".
	 *
	 * `wp_safe_redirect` já recusa destino externo, então o `Referer` pode ser
	 * usado direto.
	 *
	 * @param string $aviso     Chave do aviso, lida por `imprimir_aviso()`.
	 * @param int    $pedido_id Sub-pedido, para montar a âncora.
	 * @return void
	 */
	private static function voltar( $aviso, $pedido_id ) {
		$destino = wp_get_referer();

		if ( ! $destino ) {
			$destino = wc_get_page_permalink( 'myaccount' );
		}

		$destino = add_query_arg( 'rc-comprovante', $aviso, strtok( $destino, '#' ) );
		$destino .= '#' . self::ancora( $pedido_id );

		wp_safe_redirect( $destino );
		exit;
	}

	/**
	 * Interrompe a requisição quando quem pede não tem direito ao que pediu.
	 *
	 * Separado de `voltar()` porque aqui não há tela de origem confiável: um
	 * pedido que não é do usuário pode ter chegado por link montado à mão.
	 *
	 * @param string $mensagem Texto exibido.
	 * @return void
	 */
	private static function recusar( $mensagem ) {
		wp_die(
			esc_html( $mensagem ),
			esc_html__( 'Ação não permitida', 'reconectar-core' ),
			array(
				'response'  => 403,
				'back_link' => true,
			)
		);
	}

	/**
	 * Identificador do bloco de um sub-pedido, usado como âncora e como `id`.
	 *
	 * @param int $pedido_id ID do sub-pedido.
	 * @return string
	 */
	private static function ancora( $pedido_id ) {
		return 'rc-comprovante-' . (int) $pedido_id;
	}

	/**
	 * Imprime o aviso do último envio, se houver.
	 *
	 * O aviso vem por query string porque o envio é um POST que redireciona, e
	 * `wp_get_referer()` não carrega estado. Uma sessão do WooCommerce guardaria
	 * melhor, mas ela não existe para o convidado que chega pela chave do
	 * pedido.
	 *
	 * Não recebe o sub-pedido de propósito: o aviso é do **último envio**, e a
	 * query string carrega só o desfecho, não a qual loja ele pertence. Num
	 * carrinho multi-loja o mesmo texto sai em todos os blocos — preferível a
	 * inventar a correspondência.
	 *
	 * @return void
	 */
	private static function imprimir_aviso() {
		// phpcs:ignore WordPress.Security.NonceVerification
		$aviso = isset( $_GET['rc-comprovante'] ) ? sanitize_key( wp_unslash( $_GET['rc-comprovante'] ) ) : '';

		if ( '' === $aviso ) {
			return;
		}

		$mensagens = array(
			'enviado'      => __( 'Comprovante enviado. A loja vai conferir e confirmar o recebimento.', 'reconectar-core' ),
			'confirmado'   => __( 'Pagamento confirmado.', 'reconectar-core' ),
			'sem-arquivo'  => __( 'Escolha um arquivo antes de enviar.', 'reconectar-core' ),
			'grande'       => sprintf(
				/* translators: %s: tamanho máximo aceito, já formatado. */
				__( 'O arquivo passa de %s. Envie um menor.', 'reconectar-core' ),
				size_format( self::TAMANHO_MAX )
			),
			'tipo'         => __( 'Formato não aceito. Envie um PDF, JPG ou PNG.', 'reconectar-core' ),
			'disco'        => __( 'Não foi possível guardar o arquivo. Tente de novo em instantes.', 'reconectar-core' ),
			'ja-confirmado' => __( 'Este pagamento já foi confirmado pela loja.', 'reconectar-core' ),
		);

		if ( ! isset( $mensagens[ $aviso ] ) ) {
			return;
		}

		$sucesso = in_array( $aviso, array( 'enviado', 'confirmado' ), true );

		printf(
			'<p class="rc-comprovante__aviso rc-comprovante__aviso--%1$s" role="status">%2$s</p>',
			esc_attr( $sucesso ? 'ok' : 'erro' ),
			esc_html( $mensagens[ $aviso ] )
		);
	}

	/**
	 * Monta a URL de download do comprovante de um sub-pedido.
	 *
	 * A chave do pedido entra na URL só quando quem olha é convidado — para o
	 * comprador logado e para a loja, a sessão já responde por quem é, e uma
	 * credencial a mais na query string seria superfície sem contrapartida.
	 *
	 * @param WC_Order $pedido Sub-pedido da loja.
	 * @return string URL com nonce.
	 */
	public static function url_de_download( $pedido ) {
		$argumentos = array(
			'action' => self::ACAO_BAIXAR,
			'pedido' => $pedido->get_id(),
		);

		if ( ! is_user_logged_in() ) {
			$pai = $pedido->get_parent_id() ? wc_get_order( $pedido->get_parent_id() ) : $pedido;

			if ( $pai instanceof WC_Order ) {
				$argumentos['chave'] = $pai->get_order_key();
			}
		}

		$url = add_query_arg( $argumentos, admin_url( 'admin-post.php' ) );

		return wp_nonce_url( $url, self::ACAO_BAIXAR . '_' . $pedido->get_id() );
	}

	/* ---------------------------------------------------------------------
	 * O campo na tela do comprador
	 * ------------------------------------------------------------------ */

	/**
	 * Imprime o bloco de comprovante de uma loja.
	 *
	 * Chamado de dentro do laço de `Reconectar_Pagamento_Direto::imprimir_instrucoes()`,
	 * logo abaixo da instrução daquela loja — que é o que o comprador acabou de
	 * seguir para pagar.
	 *
	 * São três estados, nesta ordem:
	 *
	 * 1. pagamento já confirmado → nada. Um campo de upload num pedido já
	 *    liberado convida a pagar de novo, na tela em que o comprador vai
	 *    justamente conferir se pagou;
	 * 2. comprovante enviado → nome, data, link de download e a opção de trocar.
	 *    Substituir é permitido enquanto a loja não confirmou: quem mandou a
	 *    foto errada não tem outro caminho;
	 * 3. nada enviado → o formulário.
	 *
	 * A guarda de status é a do **sub-pedido**, não a do pai: é o sub-pedido que
	 * a loja confirma. Com loja única os dois são o mesmo objeto e a regra não
	 * muda.
	 *
	 * @param WC_Order $pedido  Sub-pedido da loja.
	 * @param int      $loja_id ID da loja, para o texto.
	 * @return void
	 */
	public static function campo( $pedido, $loja_id ) {
		if ( ! $pedido instanceof WC_Order || self::confirmado( $pedido ) ) {
			return;
		}

		$enviado = self::comprovante( $pedido );

		echo '<div class="rc-comprovante" id="' . esc_attr( self::ancora( $pedido->get_id() ) ) . '">';

		printf(
			'<h4 class="rc-comprovante__titulo">%s</h4>',
			esc_html__( 'Comprovante de pagamento', 'reconectar-core' )
		);

		self::imprimir_aviso();

		if ( $enviado ) {
			self::imprimir_estado_enviado( $pedido, $enviado );
		}

		self::imprimir_formulario( $pedido, (bool) $enviado );

		echo '</div>';
	}

	/**
	 * Imprime o resumo de um comprovante já enviado.
	 *
	 * @param WC_Order $pedido  Sub-pedido da loja.
	 * @param array    $enviado Dados de `META_ARQUIVO`.
	 * @return void
	 */
	private static function imprimir_estado_enviado( $pedido, $enviado ) {
		$quando = ! empty( $enviado['enviado_em'] )
			? mysql2date( get_option( 'date_format' ) . ' \à\s ' . get_option( 'time_format' ), $enviado['enviado_em'] )
			: '';

		echo '<p class="rc-comprovante__enviado">';
		printf(
			'<a class="rc-comprovante__arquivo" href="%1$s">%2$s</a>',
			esc_url( self::url_de_download( $pedido ) ),
			esc_html( ! empty( $enviado['nome'] ) ? $enviado['nome'] : __( 'Ver comprovante', 'reconectar-core' ) )
		);

		if ( '' !== $quando ) {
			printf(
				' <span class="rc-comprovante__data">%s</span>',
				esc_html(
					sprintf(
						/* translators: %s: data e hora do envio. */
						__( 'enviado em %s', 'reconectar-core' ),
						$quando
					)
				)
			);
		}

		echo '</p>';

		printf(
			'<p class="rc-comprovante__apoio">%s</p>',
			esc_html__( 'A loja ainda não confirmou o recebimento. Enquanto isso, você pode enviar outro arquivo no lugar deste.', 'reconectar-core' )
		);
	}

	/**
	 * Imprime o formulário de envio.
	 *
	 * `enctype` é o que faz o arquivo chegar ao servidor; sem ele o POST viaja
	 * com o nome do arquivo e nada mais, e o envio falha sem erro.
	 *
	 * A chave do pedido vai num campo oculto para a compra de convidado — é ela
	 * que autoriza o envio quando não há sessão.
	 *
	 * @param WC_Order $pedido   Sub-pedido da loja.
	 * @param bool     $substitui Se já existe comprovante enviado.
	 * @return void
	 */
	private static function imprimir_formulario( $pedido, $substitui ) {
		$pai   = $pedido->get_parent_id() ? wc_get_order( $pedido->get_parent_id() ) : $pedido;
		$campo = 'rc-comprovante-arquivo-' . $pedido->get_id();
		?>
		<form class="rc-comprovante__form" method="post" enctype="multipart/form-data" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="<?php echo esc_attr( self::ACAO_ENVIAR ); ?>">
			<input type="hidden" name="pedido" value="<?php echo esc_attr( $pedido->get_id() ); ?>">
			<?php if ( ! is_user_logged_in() && $pai instanceof WC_Order ) : ?>
				<input type="hidden" name="chave" value="<?php echo esc_attr( $pai->get_order_key() ); ?>">
			<?php endif; ?>
			<?php wp_nonce_field( self::ACAO_ENVIAR . '_' . $pedido->get_id() ); ?>

			<label class="rc-comprovante__rotulo" for="<?php echo esc_attr( $campo ); ?>">
				<?php
				echo esc_html(
					$substitui
						? __( 'Enviar outro arquivo', 'reconectar-core' )
						: __( 'Anexe o comprovante desta loja', 'reconectar-core' )
				);
				?>
			</label>
			<input
				class="rc-comprovante__arquivo-campo"
				type="file"
				id="<?php echo esc_attr( $campo ); ?>"
				name="comprovante"
				accept=".pdf,.jpg,.jpeg,.png"
				aria-describedby="<?php echo esc_attr( $campo ); ?>-apoio"
				required>

			<p class="rc-comprovante__apoio" id="<?php echo esc_attr( $campo ); ?>-apoio">
				<?php
				echo esc_html(
					sprintf(
						/* translators: %s: tamanho máximo aceito, já formatado. */
						__( 'PDF, JPG ou PNG, até %s.', 'reconectar-core' ),
						size_format( self::TAMANHO_MAX )
					)
				);
				?>
			</p>

			<button class="rc-comprovante__botao" type="submit">
				<?php esc_html_e( 'Enviar comprovante', 'reconectar-core' ); ?>
			</button>
		</form>
		<?php
	}

	/* ---------------------------------------------------------------------
	 * A coluna na lista de pedidos do painel da loja
	 * ------------------------------------------------------------------ */

	/**
	 * Diz se a requisição atual é a lista de pedidos do painel da loja.
	 *
	 * Usada pela guarda de enfileiramento de assets, que roda em
	 * `wp_enqueue_scripts` — depois de `parse_request`, portanto com `$wp` já
	 * resolvido. A query var é a do Dokan (`orders`), não uma nossa: a coluna
	 * mora na tela dele.
	 *
	 * @return bool
	 */
	public static function esta_na_lista_de_pedidos() {
		global $wp;

		if ( ! function_exists( 'dokan_is_seller_dashboard' ) || ! dokan_is_seller_dashboard() ) {
			return false;
		}

		return isset( $wp->query_vars['orders'] );
	}

	/**
	 * Imprime o aviso do último envio ou confirmação acima da tabela.
	 *
	 * `voltar()` redireciona pelo `Referer`, então a confirmação feita a partir
	 * da lista volta para a lista — e sem este aviso a tela recarregaria sem
	 * dizer nada, com a única pista sendo a coluna de status ter mudado.
	 *
	 * @return void
	 */
	public static function aviso_no_painel() {
		self::imprimir_aviso();
	}

	/**
	 * Imprime o `<th>` da coluna.
	 *
	 * @return void
	 */
	public static function coluna_cabecalho() {
		printf(
			'<th class="rc-comprovante-coluna__titulo">%s</th>',
			esc_html__( 'Comprovante', 'reconectar-core' )
		);
	}

	/**
	 * Imprime o `<td>` da coluna para um sub-pedido.
	 *
	 * O `<td>` sai em **todos** os casos, mesmo vazio: a contagem de células tem
	 * de casar com a de cabeçalhos, ou a linha inteira desalinha e a tabela
	 * passa a mostrar o valor de uma coluna sob o título de outra.
	 *
	 * O primeiro ramo é o que impede a coluna de mentir. Um pedido pago por
	 * outro meio nunca passou por este módulo, e dizer "Não enviado" ali seria
	 * cobrar da loja um documento que ninguém pediu ao comprador.
	 *
	 * @param WC_Order $pedido Sub-pedido daquela loja.
	 * @return void
	 */
	public static function coluna_celula( $pedido ) {
		echo '<td class="rc-comprovante-coluna" data-title="' . esc_attr__( 'Comprovante', 'reconectar-core' ) . '">';

		if ( ! $pedido instanceof WC_Order ) {
			echo '</td>';

			return;
		}

		if ( ! self::aplica( $pedido ) ) {
			printf(
				'<span class="rc-comprovante-coluna__vazio" aria-hidden="true">&mdash;</span><span class="screen-reader-text">%s</span>',
				esc_html__( 'Não se aplica a este meio de pagamento.', 'reconectar-core' )
			);
			echo '</td>';

			return;
		}

		$comprovante = self::comprovante( $pedido );

		if ( ! $comprovante ) {
			printf(
				'<span class="rc-comprovante-coluna__pendente">%s</span>',
				esc_html__( 'Não enviado', 'reconectar-core' )
			);
			echo '</td>';

			return;
		}

		printf(
			'<a class="rc-comprovante-coluna__link" href="%1$s">%2$s<span class="screen-reader-text"> %3$s</span></a>',
			esc_url( self::url_de_download( $pedido ) ),
			esc_html__( 'Baixar', 'reconectar-core' ),
			esc_html(
				sprintf(
					/* translators: %s: número do pedido. */
					__( 'o comprovante do pedido %s', 'reconectar-core' ),
					$pedido->get_order_number()
				)
			)
		);

		// Confirmado, a coluna de status da própria linha já diz "Em preparação";
		// repetir a confirmação aqui seria ruído.
		if ( ! self::confirmado( $pedido ) && self::loja_pode( $pedido ) ) {
			self::$confirmacoes_pendentes[] = $pedido->get_id();

			printf(
				'<button class="rc-comprovante-coluna__confirmar" type="submit" form="%1$s">%2$s<span class="screen-reader-text"> %3$s</span></button>',
				esc_attr( self::id_do_formulario( $pedido->get_id() ) ),
				esc_html__( 'Confirmar', 'reconectar-core' ),
				esc_html(
					sprintf(
						/* translators: %s: número do pedido. */
						__( 'o pagamento recebido do pedido %s', 'reconectar-core' ),
						$pedido->get_order_number()
					)
				)
			);
		}

		echo '</td>';
	}

	/**
	 * Imprime os formulários dos botões de confirmação acumulados.
	 *
	 * Pendurado em `dokan_order_content_inside_after`, que sai **depois** das
	 * células e **fora** do `<form>` de ações em massa do Dokan — as duas
	 * condições que fazem o atributo `form` dos botões funcionar. Ver o PHPDoc
	 * de `$confirmacoes_pendentes`.
	 *
	 * @return void
	 */
	public static function formularios_de_confirmacao() {
		foreach ( self::$confirmacoes_pendentes as $pedido_id ) {
			?>
			<form class="rc-comprovante-coluna__form" id="<?php echo esc_attr( self::id_do_formulario( $pedido_id ) ); ?>" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="<?php echo esc_attr( self::ACAO_CONFIRMAR ); ?>">
				<input type="hidden" name="pedido" value="<?php echo esc_attr( $pedido_id ); ?>">
				<?php wp_nonce_field( self::ACAO_CONFIRMAR . '_' . $pedido_id ); ?>
			</form>
			<?php
		}

		// Zerado para o caso de a tabela ser impressa mais de uma vez na mesma
		// requisição: dois `<form>` com o mesmo `id` deixariam o atributo `form`
		// do segundo botão apontando para o primeiro formulário.
		self::$confirmacoes_pendentes = array();
	}

	/**
	 * `id` do formulário de confirmação de um sub-pedido.
	 *
	 * @param int $pedido_id ID do sub-pedido.
	 * @return string
	 */
	private static function id_do_formulario( $pedido_id ) {
		return 'rc-confirmar-' . (int) $pedido_id;
	}

	/**
	 * Diz se o sub-pedido foi pago por um meio que passa por comprovante.
	 *
	 * Um pedido pago por outro meio nunca passou por este módulo, e dizer "Não
	 * enviado" nele seria cobrar da loja um documento que ninguém pediu ao
	 * comprador. É a guarda que impede a coluna e o painel de mentir.
	 *
	 * @param WC_Order $pedido Sub-pedido da loja.
	 * @return bool
	 */
	private static function aplica( $pedido ) {
		$meios = array(
			Reconectar_Pagamento_Direto::GATEWAY_PIX,
			Reconectar_Pagamento_Direto::GATEWAY_TRANSFERENCIA,
		);

		return $pedido instanceof WC_Order && in_array( $pedido->get_payment_method(), $meios, true );
	}

	/* ---------------------------------------------------------------------
	 * Interface nova do painel da loja
	 * ------------------------------------------------------------------ */

	/**
	 * Diz se a requisição é uma tela do painel da loja, em qualquer interface.
	 *
	 * Mais largo que `esta_na_lista_de_pedidos()` de propósito: na interface
	 * nova a lista é uma rota React, e a query var `orders` não é a única
	 * porta para ela.
	 *
	 * @return bool
	 */
	public static function esta_no_painel_da_loja() {
		return function_exists( 'dokan_is_seller_dashboard' ) && dokan_is_seller_dashboard();
	}

	/**
	 * Acrescenta o estado do comprovante a cada pedido de `/dokan/v1/orders`.
	 *
	 * É daqui que a coluna da lista React lê. A guarda é a mesma de `baixar()`
	 * — `loja_pode()` —, não a permissão do endpoint: a URL levada na resposta
	 * já vem com nonce, e ela só deve existir para quem a rota de download vai
	 * atender. Quem não passa recebe a resposta do Dokan intacta, sem a chave.
	 *
	 * A URL sai com o nonce do usuário da requisição REST, que é o mesmo da
	 * sessão do navegador (cookie mais `X-WP-Nonce`), então vale no clique.
	 *
	 * @param WP_REST_Response $resposta Resposta já montada pelo Dokan.
	 * @param WC_Order         $pedido   Sub-pedido da linha.
	 * @return WP_REST_Response
	 */
	public static function dados_na_api_do_painel( $resposta, $pedido ) {
		if ( ! $resposta instanceof WP_REST_Response || ! $pedido instanceof WC_Order || ! self::loja_pode( $pedido ) ) {
			return $resposta;
		}

		$dados       = $resposta->get_data();
		$comprovante = self::comprovante( $pedido );
		$enviado     = ! empty( $comprovante['arquivo'] );

		// Só o que a coluna consome. O nome original do arquivo fica de fora:
		// a lista não o mostra, e a resposta desta rota vai inteira para o
		// navegador a cada página da lista.
		$dados['rc_comprovante'] = array(
			'aplica'  => self::aplica( $pedido ),
			'enviado' => $enviado,
			// `wp_nonce_url()` devolve a URL já passada por `esc_html()`, com
			// `&amp;` entre os argumentos. Num atributo HTML o navegador desfaz
			// a entidade; em JSON ela chega literal ao `href`, o nonce vira o
			// argumento `amp;_wpnonce`, e o clique cai na recusa genérica de
			// link expirado.
			'url'     => $enviado ? html_entity_decode( self::url_de_download( $pedido ), ENT_QUOTES, 'UTF-8' ) : '',
		);

		$resposta->set_data( $dados );

		return $resposta;
	}

	/**
	 * Carrega o script que põe a coluna e a ação na lista React de pedidos.
	 *
	 * Sem build: o arquivo usa `wp.element` e `wp.hooks` globais, as mesmas
	 * instâncias que o Dokan usa — e é por isso que as duas entram como
	 * dependência, e não um pacote próprio: um `@wordpress/hooks` empacotado à
	 * parte teria outro registro de filtros, e a tabela nunca os veria.
	 *
	 * @return void
	 */
	public static function carregar_script_do_painel() {
		if ( ! self::esta_no_painel_da_loja() ) {
			return;
		}

		wp_enqueue_script(
			'reconectar-comprovante-painel',
			RECONECTAR_CORE_URL . 'assets/js/comprovante-painel.js',
			array( 'wp-hooks', 'wp-element' ),
			'0.1.0',
			true
		);
		wp_localize_script(
			'reconectar-comprovante-painel',
			'reconectarComprovantePainel',
			array(
				'titulo'       => __( 'Comprovante', 'reconectar-core' ),
				'ver'          => __( 'Ver', 'reconectar-core' ),
				'verAcao'      => __( 'Ver comprovante', 'reconectar-core' ),
				/* translators: %s: número do pedido. */
				'verLeitor'    => __( 'o comprovante do pedido %s (abre em nova aba)', 'reconectar-core' ),
				'naoEnviado'   => __( 'Não enviado', 'reconectar-core' ),
				'naoAplica'    => __( 'Não se aplica a este meio de pagamento.', 'reconectar-core' ),
			)
		);
	}

	/**
	 * Imprime o painel do comprovante no detalhe do pedido do painel da loja.
	 *
	 * O detalhe é `orders/details.php` nas duas interfaces do Dokan, e é a
	 * única tela da interface nova em que a loja pode **confirmar** o
	 * recebimento: a lista React não tem onde pendurar o botão que a coluna
	 * antiga tinha. O `<form>` sai direto aqui porque o gancho fica entre os
	 * painéis, fora dos três formulários do template (status, nota e
	 * rastreio) — não há aninhamento a contornar, ao contrário da lista.
	 *
	 * O `id` do painel é a âncora de `voltar()`, que devolve a loja a este
	 * ponto da página depois de confirmar, com o aviso logo acima do botão.
	 *
	 * @param WC_Order $pedido Sub-pedido aberto.
	 * @return void
	 */
	public static function painel_no_detalhe( $pedido ) {
		if ( ! $pedido instanceof WC_Order || ! self::aplica( $pedido ) || ! self::loja_pode( $pedido ) ) {
			return;
		}

		$comprovante = self::comprovante( $pedido );
		?>
		<div class="rc-comprovante-detalhe" style="width:100%">
			<div class="dokan-panel dokan-panel-default" id="<?php echo esc_attr( self::ancora( $pedido->get_id() ) ); ?>">
				<div class="dokan-panel-heading"><strong><?php esc_html_e( 'Comprovante de pagamento', 'reconectar-core' ); ?></strong></div>
				<div class="dokan-panel-body rc-comprovante-coluna">
					<?php
					self::imprimir_aviso();

					if ( empty( $comprovante['arquivo'] ) ) {
						printf(
							'<p class="rc-comprovante-coluna__pendente">%s</p>',
							esc_html__( 'O comprador ainda não enviou o comprovante deste pagamento.', 'reconectar-core' )
						);
					} else {
						$quando = ! empty( $comprovante['enviado_em'] )
							? mysql2date( get_option( 'date_format' ) . ' \à\s ' . get_option( 'time_format' ), $comprovante['enviado_em'] )
							: '';

						printf(
							'<p><a class="rc-comprovante-coluna__link" href="%1$s" target="_blank" rel="noopener">%2$s<span class="screen-reader-text"> %3$s</span></a>%4$s</p>',
							esc_url( self::url_de_download( $pedido ) ),
							esc_html( ! empty( $comprovante['nome'] ) ? $comprovante['nome'] : __( 'Ver comprovante', 'reconectar-core' ) ),
							esc_html__( '(abre em nova aba)', 'reconectar-core' ),
							'' !== $quando
								? ' <span class="rc-comprovante-coluna__pendente">' . esc_html(
									sprintf(
										/* translators: %s: data e hora do envio. */
										__( 'enviado em %s', 'reconectar-core' ),
										$quando
									)
								) . '</span>'
								: ''
						);
					}

					if ( self::confirmado( $pedido ) ) {
						printf(
							'<p>%s</p>',
							esc_html__( 'Pagamento confirmado.', 'reconectar-core' )
						);
					} elseif ( ! empty( $comprovante['arquivo'] ) ) {
						?>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
							<input type="hidden" name="action" value="<?php echo esc_attr( self::ACAO_CONFIRMAR ); ?>">
							<input type="hidden" name="pedido" value="<?php echo esc_attr( $pedido->get_id() ); ?>">
							<?php wp_nonce_field( self::ACAO_CONFIRMAR . '_' . $pedido->get_id() ); ?>
							<button class="rc-comprovante-coluna__confirmar" type="submit">
								<?php esc_html_e( 'Confirmar pagamento recebido', 'reconectar-core' ); ?>
							</button>
						</form>
						<?php
					}
					?>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Leva os pedidos em conferência ao topo da lista do painel da loja.
	 *
	 * "Cronológica e prioritária": quem enviou comprovante sobe, e **dentro** do
	 * grupo o mais antigo vem primeiro, porque já pagou há mais tempo. Fora dele
	 * o `CASE` devolve `NULL` para todas as linhas, que empatam e caem na chave
	 * seguinte — o `post_date DESC` original do Dokan, preservado.
	 *
	 * Em `posts_clauses`, e não sobre o resultado: `dokan_get_vendor_orders` é
	 * filtro sobre a **página corrente**, cortada em 10, e reordenar ali deixaria
	 * o pedido em conferência na página 3 exatamente onde estava.
	 *
	 * A consulta se reconhece por `post_type` mais a presença de
	 * `_dokan_vendor_id` no `where` já montado — os dois juntos são a lista de
	 * pedidos de uma loja, e nada mais. O Dokan filtra por essa meta, nunca por
	 * `post_author`, e é isso que dispensa a flag global que o arranjo alternativo
	 * exigiria: uma flag setada em `woocommerce_order_query_args` e lida no
	 * `posts_orderby` reordenaria qualquer consulta de pedido que rodasse no meio.
	 *
	 * @param array    $clausulas Cláusulas SQL da consulta.
	 * @param WP_Query $consulta  Consulta em curso.
	 * @return array
	 */
	public static function priorizar_conferencia( $clausulas, $consulta ) {
		global $wpdb;

		if ( ! $consulta instanceof WP_Query ) {
			return $clausulas;
		}

		if ( ! in_array( 'shop_order', (array) $consulta->get( 'post_type' ), true ) ) {
			return $clausulas;
		}

		if ( empty( $clausulas['where'] ) || false === strpos( $clausulas['where'], '_dokan_vendor_id' ) ) {
			return $clausulas;
		}

		$status = Reconectar_Status_Pedido::CONFERENCIA;

		// O literal vem de constante nossa, mas passa pelo `prepare` de qualquer
		// forma: é o que mantém a linha auditável sem que o leitor precise ir
		// conferir a origem do valor.
		$prioridade = $wpdb->prepare(
			"CASE WHEN {$wpdb->posts}.post_status = %s THEN 0 ELSE 1 END ASC, CASE WHEN {$wpdb->posts}.post_status = %s THEN {$wpdb->posts}.post_date END ASC",
			$status,
			$status
		);

		$original = isset( $clausulas['orderby'] ) ? trim( (string) $clausulas['orderby'] ) : '';

		$clausulas['orderby'] = '' === $original ? $prioridade : $prioridade . ', ' . $original;

		return $clausulas;
	}
}
