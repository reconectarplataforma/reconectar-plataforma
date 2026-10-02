<?php
/**
 * Editor da Incubadora: o TinyMCE auto-hospedado, só para quem escreve.
 *
 * A tela de leitura é a mesma para todo logado; o editor é uma camada por cima
 * dela, carregada por `assets/js/incubadora-editor.js`. Este arquivo decide
 * **quem** recebe essa camada e entrega a ela o que o servidor sabe — rota,
 * nonce, versão da página, endereço do vendor.
 *
 * A árvore interativa — criar, mover, arrastar — também é entregue daqui, por
 * `assets/js/incubadora-arvore.js`, e com uma condição mais larga que a do
 * editor: ela vale também na âncora vazia, onde não há página para editar mas
 * há a primeira a criar.
 *
 * O TinyMCE em si não é enfileirado aqui. O script do editor o carrega no
 * primeiro clique em "Editar": são 1,8 MB que a maioria das leituras, mesmo de
 * quem pode escrever, nunca usa.
 *
 * @package reconectar-core
 */

defined( 'ABSPATH' ) || exit;

/**
 * Entrega o editor à tela de uma página da Incubadora.
 */
class Reconectar_Incubadora_Editor {

	/**
	 * Versão do TinyMCE vendorizado, que é também o nome da pasta.
	 *
	 * A pasta com versão no nome evita que o navegador misture, em cache,
	 * arquivos de duas versões — ver `assets/vendor/tinymce/<versão>/LEIAME.md`.
	 *
	 * @var string
	 */
	const VERSAO_TINYMCE = '8.9.2';

	/**
	 * Registra os ganchos.
	 *
	 * Prioridade 20 em `wp_enqueue_scripts`: o script do editor depende do
	 * `reconectar-incubadora`, que `Reconectar_Incubadora_Leitura` enfileira
	 * na 10.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enfileirar' ), 20 );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enfileirar_arvore' ), 20 );
	}

	/**
	 * Diz se a requisição corrente deve receber o editor.
	 *
	 * Só a página isolada: a âncora sem páginas não tem o que editar, e criar
	 * página é da árvore — ver `enfileirar_arvore()`. A capacidade é a mesma que os
	 * endpoints conferem antes do nonce, e `edit_post` cobre a página aberta —
	 * hoje todo detentor da capacidade edita qualquer página, mas a pergunta
	 * certa é a da página.
	 *
	 * @return bool
	 */
	public static function deve_carregar() {
		if ( ! is_singular( Reconectar_Incubadora::POST_TYPE ) ) {
			return false;
		}

		if ( ! current_user_can( Reconectar_Permissoes::CAP_GERIR_INCUBADORA ) ) {
			return false;
		}

		$pagina = get_queried_object();

		return $pagina instanceof WP_Post && current_user_can( 'edit_post', $pagina->ID );
	}

	/**
	 * Enfileira o script do editor e os dados de que ele precisa.
	 *
	 * `admin-post.php` vai como **caminho**, sem host: a regra do `WP_HOME`
	 * dinâmico, para a mesma página funcionar por `localhost` e pelo IP da rede.
	 * O endereço do vendor também, pelo mesmo motivo — o TinyMCE monta a URL
	 * de cada plugin, ícone e pele a partir do `base_url`.
	 *
	 * @return void
	 */
	public static function enfileirar() {
		if ( ! self::deve_carregar() ) {
			return;
		}

		$pagina = get_queried_object();

		wp_enqueue_script(
			'reconectar-incubadora-editor',
			RECONECTAR_CORE_URL . 'assets/js/incubadora-editor.js',
			array( 'reconectar-incubadora' ),
			Reconectar_Incubadora_Leitura::VERSAO_ASSETS,
			true
		);

		wp_localize_script(
			'reconectar-incubadora-editor',
			'reconectarIncubadoraEditor',
			array(
				'rota'       => self::caminho( admin_url( 'admin-post.php' ) ),
				'acao'       => Reconectar_Incubadora_Acoes::acao( 'salvar' ),
				'nonce'      => wp_create_nonce( Reconectar_Incubadora_Acoes::acao( 'salvar' ) ),
				'tinymce'    => self::caminho( RECONECTAR_CORE_URL . 'assets/vendor/tinymce/' . self::VERSAO_TINYMCE ),
				'pagina'     => (int) $pagina->ID,
				'titulo'     => $pagina->post_title,
				'modificado' => $pagina->post_modified_gmt,
				'status'     => $pagina->post_status,
				'usuario'    => wp_get_current_user()->display_name,
				'hoje'       => date_i18n( get_option( 'date_format' ) ),
				'textos'     => self::textos(),
			)
		);
	}

	/**
	 * Enfileira o script da árvore interativa e os nonces de criar e mover.
	 *
	 * Um objeto de dados próprio, e não campos a mais no do editor: o do editor
	 * só existe na página isolada, e a âncora vazia precisa criar a primeira
	 * página. A capacidade é a mesma dos endpoints; quem não a tem não recebe
	 * nem o script nem os nonces.
	 *
	 * @return void
	 */
	public static function enfileirar_arvore() {
		if ( ! Reconectar_Incubadora_Leitura::pagina_desenha_a_incubadora() || ! current_user_can( Reconectar_Permissoes::CAP_GERIR_INCUBADORA ) ) {
			return;
		}

		wp_enqueue_script(
			'reconectar-incubadora-arvore',
			RECONECTAR_CORE_URL . 'assets/js/incubadora-arvore.js',
			array( 'reconectar-incubadora' ),
			Reconectar_Incubadora_Leitura::VERSAO_ASSETS,
			true
		);

		$pagina = is_singular( Reconectar_Incubadora::POST_TYPE ) ? get_queried_object() : null;

		wp_localize_script(
			'reconectar-incubadora-arvore',
			'reconectarIncubadoraArvore',
			array(
				'rota'         => self::caminho( admin_url( 'admin-post.php' ) ),
				'acaoCriar'    => Reconectar_Incubadora_Acoes::acao( 'criar' ),
				'nonceCriar'   => wp_create_nonce( Reconectar_Incubadora_Acoes::acao( 'criar' ) ),
				'acaoMover'    => Reconectar_Incubadora_Acoes::acao( 'mover' ),
				'nonceMover'   => wp_create_nonce( Reconectar_Incubadora_Acoes::acao( 'mover' ) ),
				'atual'        => $pagina instanceof WP_Post ? (int) $pagina->ID : 0,
				'profundidade' => Reconectar_Incubadora::PROFUNDIDADE_MAXIMA,
				'textos'       => self::textos_da_arvore(),
			)
		);
	}

	/**
	 * Textos da árvore interativa.
	 *
	 * @return array<string, string>
	 */
	private static function textos_da_arvore() {
		return array(
			'tituloCampo'    => __( 'Título', 'reconectar-core' ),
			'criar'          => __( 'Criar e editar', 'reconectar-core' ),
			/* translators: %s: título da página mãe. */
			'criarDentroDe'  => __( 'A página nova fica dentro de “%s”, como rascunho.', 'reconectar-core' ),
			'criando'        => __( 'Criando a página…', 'reconectar-core' ),
			'movendo'        => __( 'Movendo…', 'reconectar-core' ),
			/* translators: 1: título da página, 2: título do destino. */
			'movida'         => __( '“%1$s” agora está em %2$s.', 'reconectar-core' ),
			'raiz'           => __( 'Incubadora (raiz)', 'reconectar-core' ),
			'semTitulo'      => __( '(sem título)', 'reconectar-core' ),
			'dialogoTitulo'  => __( 'Mover página', 'reconectar-core' ),
			/* translators: %s: título da página. */
			'dialogoAjuda'   => __( 'Escolha onde “%s” fica na árvore. As subpáginas dela vão junto.', 'reconectar-core' ),
			'mae'            => __( 'Página mãe', 'reconectar-core' ),
			'posicao'        => __( 'Posição', 'reconectar-core' ),
			'noInicio'       => __( 'No início', 'reconectar-core' ),
			/* translators: %s: título da página irmã. */
			'depoisDe'       => __( 'Depois de “%s”', 'reconectar-core' ),
			'mover'          => __( 'Mover', 'reconectar-core' ),
			'cancelar'       => __( 'Cancelar', 'reconectar-core' ),
			'semMudanca'     => __( 'A página já está nesse lugar.', 'reconectar-core' ),
			'arrastarAjuda'  => __( 'Arraste para reordenar. Pelo teclado, use o botão Mover… da página.', 'reconectar-core' ),
			'erroRede'       => __( 'Não foi possível falar com o servidor. Confira a conexão e tente de novo.', 'reconectar-core' ),
			'erroResposta'   => __( 'O servidor respondeu de um jeito inesperado. Recarregue a página e tente de novo.', 'reconectar-core' ),
			'recarregar'     => __( 'Recarregar a página', 'reconectar-core' ),
		);
	}

	/**
	 * Textos da interface do editor.
	 *
	 * No PHP, e não no JS, para passarem pelo `__()` como o resto do plugin.
	 *
	 * @return array<string, string>
	 */
	private static function textos() {
		return array(
			'carregando'       => __( 'Abrindo o editor…', 'reconectar-core' ),
			'falhaCarregar'    => __( 'O editor não pôde ser aberto. Recarregue a página e tente de novo.', 'reconectar-core' ),
			'editando'         => __( 'Editando. As alterações só ficam gravadas ao salvar.', 'reconectar-core' ),
			'salvando'         => __( 'Salvando…', 'reconectar-core' ),
			'salva'            => __( 'Alterações salvas.', 'reconectar-core' ),
			'publicada'        => __( 'Página publicada.', 'reconectar-core' ),
			'semAlteracoes'    => __( 'Nada mudou desde a última gravação.', 'reconectar-core' ),
			'atualizar'        => __( 'Atualizar', 'reconectar-core' ),
			'salvarRascunho'   => __( 'Salvar rascunho', 'reconectar-core' ),
			'publicar'         => __( 'Publicar', 'reconectar-core' ),
			'fechar'           => __( 'Fechar', 'reconectar-core' ),
			'barra'            => __( 'Edição da página', 'reconectar-core' ),
			'fechado'          => __( 'Edição encerrada.', 'reconectar-core' ),
			'descartar'        => __( 'Há alterações não salvas. Fechar o editor e descartá-las?', 'reconectar-core' ),
			'sobrescrever'     => __( 'Salvar por cima da versão nova', 'reconectar-core' ),
			'erroRede'         => __( 'Não foi possível falar com o servidor. Confira a conexão e tente de novo — o texto continua no editor.', 'reconectar-core' ),
			'erroResposta'     => __( 'O servidor respondeu de um jeito inesperado. O texto continua no editor; tente de novo.', 'reconectar-core' ),
			'avisos'           => __( 'Parte do conteúdo foi ajustada ao salvar:', 'reconectar-core' ),
			'tituloCampo'      => __( 'Título da página', 'reconectar-core' ),
			'corpoRotulo'      => __( 'Conteúdo da página', 'reconectar-core' ),
			'semTitulo'        => __( '(sem título)', 'reconectar-core' ),
			'codigo'           => __( 'Código', 'reconectar-core' ),
			'video'            => __( 'Inserir vídeo', 'reconectar-core' ),
			'videoUrl'         => __( 'Endereço do vídeo no YouTube ou no Vimeo', 'reconectar-core' ),
			'videoAjuda'       => __( 'Cole o link da barra de endereços ou do botão "Compartilhar" do vídeo.', 'reconectar-core' ),
			'videoInvalido'    => __( 'Este endereço não é de um vídeo do YouTube ou do Vimeo.', 'reconectar-core' ),
			'videoTitulo'      => __( 'Vídeo incorporado', 'reconectar-core' ),
			'inserir'          => __( 'Inserir', 'reconectar-core' ),
			'cancelar'         => __( 'Cancelar', 'reconectar-core' ),
			'metadados'        => __( 'Inserir tabela de metadados', 'reconectar-core' ),
			'metaStatus'       => __( 'Status', 'reconectar-core' ),
			'metaStatusValor'  => __( 'Em andamento', 'reconectar-core' ),
			'metaData'         => __( 'Data', 'reconectar-core' ),
			'metaResponsavel'  => __( 'Responsável', 'reconectar-core' ),
			'formatoParagrafo' => __( 'Parágrafo', 'reconectar-core' ),
			'formatoTitulo2'   => __( 'Título 2', 'reconectar-core' ),
			'formatoTitulo3'   => __( 'Título 3', 'reconectar-core' ),
			'formatoTitulo4'   => __( 'Título 4', 'reconectar-core' ),
			'formatoCodigo'    => __( 'Bloco de código', 'reconectar-core' ),
			'corPadrao'        => __( 'Grafite', 'reconectar-core' ),
			'corRoxo'          => __( 'Roxo', 'reconectar-core' ),
			'corVermelho'      => __( 'Vermelho', 'reconectar-core' ),
			'corVerde'         => __( 'Verde', 'reconectar-core' ),
			'corAzul'          => __( 'Azul', 'reconectar-core' ),
			'corMarrom'        => __( 'Marrom', 'reconectar-core' ),
		);
	}

	/**
	 * Caminho de uma URL do próprio site, sem esquema nem host.
	 *
	 * @param string $url URL absoluta.
	 * @return string
	 */
	private static function caminho( $url ) {
		$caminho = wp_parse_url( (string) $url, PHP_URL_PATH );

		return $caminho ? untrailingslashit( $caminho ) : '/';
	}
}
