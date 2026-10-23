<?php
/**
 * As telas de gestão do Administrador e do Moderador — rota `/gestao/`.
 *
 * O que esses dois papéis faziam no `/wp-admin` (enquetes, categorias do fórum,
 * campanhas e moderação) passa a ter tela aqui, na
 * moldura do painel do Dokan, para que o `/wp-admin` fique só com o Super
 * Administrador.
 *
 * Esta classe é só o trilho comum: rota, portão, POST/Redirect/GET, avisos e a
 * casca da tela. Cada área é uma classe à parte, registrada em `areas()`, que
 * responde a mesma interface de métodos estáticos:
 *
 *   - `titulo()`                  — o nome da área, para o `<title>`;
 *   - `pode()`                    — quem abre a listagem;
 *   - `pode_item( $item )`        — quem abre `nova` ou o id;
 *   - `processar( $acao, $campos, $item )` — as ações do POST;
 *   - `template( $item )`         — o arquivo em `includes/painel-gestao/`;
 *   - `avisos()`                  — o mapa de códigos de aviso.
 *
 * Rota própria, e não aba do `/dashboard/`, pelo mesmo motivo do painel de
 * empresas: a página do Dokan barra quem não tem `dokandar`, e conceder essa
 * capacidade faria do Moderador um vendedor para o plugin inteiro.
 *
 * @package reconectar-core
 */

defined( 'ABSPATH' ) || exit;

class Reconectar_Painel_Gestao {

	/**
	 * Primeiro segmento da URL.
	 */
	const CAMINHO = 'gestao';

	/**
	 * Query var da área.
	 */
	const QUERY_VAR = 'rc_gestao';

	/**
	 * Query var do item: `nova` ou o id.
	 */
	const QUERY_VAR_ITEM = 'rc_gestao_item';

	/**
	 * Prefixo da ação dos nonces.
	 */
	const PREFIXO_NONCE = 'reconectar_gestao_';

	/**
	 * Registra os ganchos.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'init', array( __CLASS__, 'registrar_rota' ) );
		add_filter( 'query_vars', array( __CLASS__, 'registrar_query_vars' ) );
		add_action( 'template_redirect', array( __CLASS__, 'proteger' ), 9 );
		add_filter( 'template_include', array( __CLASS__, 'template' ), 99 );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enfileirar_assets' ), 40 );
		add_filter( 'document_title_parts', array( __CLASS__, 'titulo_do_documento' ) );
	}

	/**
	 * As áreas e a classe de cada uma.
	 *
	 * @return array<string,string>
	 */
	public static function areas() {
		return array(
			'enquetes'  => 'Reconectar_Painel_Enquetes',
			'foruns'    => 'Reconectar_Painel_Foruns',
			'campanhas' => 'Reconectar_Painel_Campanhas',
			'moderacao' => 'Reconectar_Painel_Moderacao',
		);
	}

	/**
	 * Registra as regras de reescrita.
	 *
	 * Regra nova só vale depois de um flush: o `provision.sh` faz o dele no fim,
	 * e a migração 10 faz o das instalações que já estavam de pé.
	 *
	 * @return void
	 */
	public static function registrar_rota() {
		add_rewrite_rule(
			'^' . self::CAMINHO . '/([a-z-]+)/?$',
			'index.php?' . self::QUERY_VAR . '=$matches[1]',
			'top'
		);
		add_rewrite_rule(
			'^' . self::CAMINHO . '/([a-z-]+)/(nova|\d+)/?$',
			'index.php?' . self::QUERY_VAR . '=$matches[1]&' . self::QUERY_VAR_ITEM . '=$matches[2]',
			'top'
		);
	}

	/**
	 * Declara as query vars, sem as quais o WordPress as descarta.
	 *
	 * @param string[] $vars Query vars públicas.
	 * @return string[]
	 */
	public static function registrar_query_vars( $vars ) {
		$vars[] = self::QUERY_VAR;
		$vars[] = self::QUERY_VAR_ITEM;

		return $vars;
	}

	/**
	 * URL de uma área, ou de um item dela.
	 *
	 * @param string     $area Chave da área.
	 * @param string|int $item `nova`, o id, ou vazio para a listagem.
	 * @return string
	 */
	public static function url( $area, $item = '' ) {
		$caminho = '/' . self::CAMINHO . '/' . $area . '/';

		if ( '' !== (string) $item ) {
			$caminho .= $item . '/';
		}

		return home_url( $caminho );
	}

	/**
	 * A requisição corrente pediu a rota, conhecida ou não?
	 *
	 * @return bool
	 */
	public static function eh_a_rota() {
		return '' !== (string) get_query_var( self::QUERY_VAR );
	}

	/**
	 * Chave da área corrente, ou vazio se a rota não for esta ou a área não existir.
	 *
	 * @return string
	 */
	public static function area() {
		$area = sanitize_key( (string) get_query_var( self::QUERY_VAR ) );

		return isset( self::areas()[ $area ] ) ? $area : '';
	}

	/**
	 * Classe da área corrente, ou vazio.
	 *
	 * @return string
	 */
	public static function classe() {
		$area = self::area();

		return '' !== $area ? self::areas()[ $area ] : '';
	}

	/**
	 * Item corrente: `nova`, o id em string, ou vazio na listagem.
	 *
	 * @return string
	 */
	public static function item() {
		$item = (string) get_query_var( self::QUERY_VAR_ITEM );

		if ( 'nova' === $item || ctype_digit( $item ) ) {
			return $item;
		}

		return '';
	}

	/**
	 * A tela corrente abre na moldura do painel do Dokan?
	 *
	 * Confere a permissão da área aqui, e não só em `proteger()`, pelo mesmo
	 * motivo de `Reconectar_Navegacao_Da_Loja::painel_de_empresas_na_moldura()`:
	 * a casca é decidida em `header.php`, e quem não passa no portão recebe o 403
	 * no cabeçalho da vitrine, não numa barra lateral sem item para ele.
	 *
	 * @return bool
	 */
	public static function na_moldura() {
		if ( ! function_exists( 'dokan_get_dashboard_nav' ) || ! is_user_logged_in() ) {
			return false;
		}

		$classe = self::classe();

		return '' !== $classe && call_user_func( array( $classe, 'pode' ) );
	}

	/**
	 * O portão: login, área, permissão e então o POST.
	 *
	 * Prioridade 9, como em `Reconectar_Modulos`: antes do portão do Dokan e de
	 * `levar_moderador_aos_modulos()`, que não têm o que decidir aqui.
	 *
	 * Área desconhecida responde 404 antes da permissão. Um 403 ali confirmaria a
	 * quem tateia a URL que algo existe com aquele nome.
	 *
	 * @return void
	 */
	public static function proteger() {
		if ( ! self::eh_a_rota() ) {
			return;
		}

		if ( ! is_user_logged_in() ) {
			wp_safe_redirect( wp_login_url( self::url_atual() ) );
			exit;
		}

		$classe = self::classe();

		if ( '' === $classe ) {
			global $wp_query;
			$wp_query->set_404();
			status_header( 404 );
			nocache_headers();
			return;
		}

		if ( ! call_user_func( array( $classe, 'pode' ) ) ) {
			self::recusar();
		}

		$item = self::item();

		if ( '' !== $item && ! call_user_func( array( $classe, 'pode_item' ), $item ) ) {
			self::recusar();
		}

		nocache_headers();
		self::processar( $classe, $item );
	}

	/**
	 * Recusa o acesso com 403.
	 *
	 * Pública porque as áreas recusam também dentro das ações, quando o id do
	 * POST não é o que a URL autorizou.
	 *
	 * 403, e não redirecionamento: o `verificar-acessos.sh` lê o código de
	 * status, e uma recusa disfarçada de 302 passaria por "funcionou".
	 *
	 * @return void
	 */
	public static function recusar() {
		wp_die(
			esc_html__( 'Você não tem permissão para acessar esta área.', 'reconectar-core' ),
			esc_html__( 'Acesso negado', 'reconectar-core' ),
			array( 'response' => 403 )
		);
	}

	/**
	 * A URL sendo acessada, para voltar a ela depois do login.
	 *
	 * @return string
	 */
	private static function url_atual() {
		$caminho = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '';

		return home_url( esc_url_raw( $caminho ) );
	}

	/**
	 * Confere o nonce do POST e entrega a ação à área.
	 *
	 * POST/Redirect/GET: cada ação termina em `redirecionar()`, e um F5 não
	 * reenvia o formulário — o que aqui seria uma segunda enquete criada.
	 *
	 * @param string $classe Classe da área.
	 * @param string $item   Item corrente.
	 * @return void
	 */
	private static function processar( $classe, $item ) {
		if ( 'POST' !== ( isset( $_SERVER['REQUEST_METHOD'] ) ? $_SERVER['REQUEST_METHOD'] : '' ) ) {
			return;
		}

		// Acima de `post_max_size` o PHP esvazia `$_POST` inteiro, ação e nonce
		// junto: sem esta conferência, enviar uma arte grande demais recarregaria
		// a tela calada, como se nada tivesse sido pedido.
		$tamanho = isset( $_SERVER['CONTENT_LENGTH'] ) ? (int) $_SERVER['CONTENT_LENGTH'] : 0;
		$teto    = wp_convert_hr_to_bytes( (string) ini_get( 'post_max_size' ) );

		if ( empty( $_POST ) && $teto > 0 && $tamanho > $teto ) {
			self::redirecionar(
				self::area(),
				$item,
				'erro',
				sprintf(
					/* translators: %s: tamanho máximo legível, como "2 MB". */
					__( 'O arquivo passa do limite de %s. Nada foi gravado.', 'reconectar-core' ),
					size_format( wp_max_upload_size() )
				)
			);
		}

		$acao = isset( $_POST['reconectar_acao'] ) ? sanitize_key( wp_unslash( $_POST['reconectar_acao'] ) ) : '';

		if ( '' === $acao ) {
			return;
		}

		$nonce = isset( $_POST['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ) : '';

		if ( ! wp_verify_nonce( $nonce, self::acao_do_nonce( $acao, $item ) ) ) {
			self::recusar();
		}

		call_user_func( array( $classe, 'processar' ), $acao, wp_unslash( $_POST ), $item );
	}

	/**
	 * A ação do nonce, amarrada ao item.
	 *
	 * Com o id dentro da ação, um nonce emitido para a enquete 12 não vale num
	 * POST montado à mão para a 13: o servidor só emite o nonce a quem a tela de
	 * 12 já autorizou.
	 *
	 * @param string $acao Ação do formulário.
	 * @param string $item Item corrente.
	 * @return string
	 */
	private static function acao_do_nonce( $acao, $item ) {
		return self::PREFIXO_NONCE . $acao . '_' . ( '' !== $item ? $item : 'lista' );
	}

	/**
	 * Imprime o par oculto de ação e nonce de um formulário.
	 *
	 * @param string $acao Ação do formulário.
	 * @param string $item Item a que o formulário se refere.
	 * @return void
	 */
	public static function campos_da_acao( $acao, $item ) {
		wp_nonce_field( self::acao_do_nonce( $acao, (string) $item ) );
		printf( '<input type="hidden" name="reconectar_acao" value="%s">', esc_attr( $acao ) );
	}

	/**
	 * Redireciona com aviso e encerra.
	 *
	 * @param string     $area     Área de destino.
	 * @param string|int $item     Item de destino, ou vazio.
	 * @param string     $aviso    Código do aviso.
	 * @param string     $mensagem Mensagem livre, para o aviso `erro`.
	 * @param array      $extras   Parâmetros da listagem a preservar — aba,
	 *                             situação e página da moderação, que sem isto
	 *                             voltaria à primeira aba depois de cada ação.
	 * @return void
	 */
	public static function redirecionar( $area, $item, $aviso, $mensagem = '', array $extras = array() ) {
		$argumentos = array_merge( $extras, array( 'aviso' => $aviso ) );

		if ( '' !== $mensagem ) {
			$argumentos['motivo'] = rawurlencode( $mensagem );
		}

		wp_safe_redirect( add_query_arg( $argumentos, self::url( $area, $item ) ) );
		exit;
	}

	/**
	 * Guarda o que foi digitado num formulário que o servidor recusou.
	 *
	 * Transient por usuário, e não query string, pelo motivo registrado em
	 * `Reconectar_Painel_Empresas::guardar_formulario()`: o texto de uma enquete
	 * não cabe numa URL, nem deve parar no log do servidor.
	 *
	 * @param string $formulario Identificador do formulário.
	 * @param array  $dados      Campos submetidos.
	 * @return void
	 */
	public static function guardar_formulario( $formulario, array $dados ) {
		set_transient( self::chave_do_formulario( $formulario ), $dados, 5 * MINUTE_IN_SECONDS );
	}

	/**
	 * Consome os dados recusados de um formulário, se houver.
	 *
	 * Apaga na leitura: um formulário abandonado reapareceria preenchido na
	 * próxima visita, com o que foi digitado para outro item.
	 *
	 * @param string $formulario Identificador do formulário.
	 * @return array
	 */
	public static function formulario_pendente( $formulario ) {
		$chave = self::chave_do_formulario( $formulario );
		$dados = get_transient( $chave );

		if ( ! is_array( $dados ) ) {
			return array();
		}

		delete_transient( $chave );

		return $dados;
	}

	/**
	 * Chave do transient de um formulário recusado.
	 *
	 * @param string $formulario Identificador do formulário.
	 * @return string
	 */
	private static function chave_do_formulario( $formulario ) {
		return 'reconectar_gestao_form_' . sanitize_key( $formulario ) . '_' . get_current_user_id();
	}

	/**
	 * Troca o template pela casca da gestão.
	 *
	 * Só para área conhecida: a desconhecida já virou 404 em `proteger()`, e o
	 * template que o WordPress escolheu é o certo.
	 *
	 * @param string $template Template escolhido pelo WordPress.
	 * @return string
	 */
	public static function template( $template ) {
		if ( '' === self::classe() ) {
			return $template;
		}

		return RECONECTAR_CORE_PATH . 'includes/painel-gestao/tela.php';
	}

	/**
	 * Põe o nome da área no `<title>`.
	 *
	 * @param array $partes Partes do título.
	 * @return array
	 */
	public static function titulo_do_documento( $partes ) {
		$classe = self::classe();

		if ( '' !== $classe ) {
			$partes['title'] = call_user_func( array( $classe, 'titulo' ) );
		}

		return $partes;
	}

	/**
	 * Enfileira estilo e script, só nestas telas.
	 *
	 * O estilo do painel de empresas vem junto porque é ele que define tabela,
	 * formulário, botão e selo; o da gestão acrescenta só o que lá não existe.
	 *
	 * @return void
	 */
	public static function enfileirar_assets() {
		if ( '' === self::classe() ) {
			return;
		}

		wp_enqueue_style(
			'reconectar-painel-empresas',
			RECONECTAR_CORE_URL . 'assets/css/painel-empresas.css',
			array( 'reconectar-bootstrap' ),
			'0.1.0'
		);

		wp_enqueue_style(
			'reconectar-painel-gestao',
			RECONECTAR_CORE_URL . 'assets/css/painel-gestao.css',
			array( 'reconectar-painel-empresas' ),
			'0.1.0'
		);

		wp_enqueue_script(
			'reconectar-painel-gestao',
			RECONECTAR_CORE_URL . 'assets/js/painel-gestao.js',
			array(),
			'0.1.0',
			array(
				'strategy'  => 'defer',
				'in_footer' => true,
			)
		);
	}

	/**
	 * Imprime o aviso da última ação, quando houver.
	 *
	 * @return void
	 */
	public static function aviso() {
		$codigo = isset( $_GET['aviso'] ) ? sanitize_key( wp_unslash( $_GET['aviso'] ) ) : '';
		$classe = self::classe();

		if ( '' === $codigo || '' === $classe ) {
			return;
		}

		if ( 'erro' === $codigo ) {
			$motivo = isset( $_GET['motivo'] ) ? sanitize_text_field( rawurldecode( wp_unslash( $_GET['motivo'] ) ) ) : '';
			printf(
				'<div class="rc-painel-empresas__aviso rc-painel-empresas__aviso--erro" role="alert">%s</div>',
				esc_html( '' !== $motivo ? $motivo : __( 'Não foi possível concluir a ação.', 'reconectar-core' ) )
			);
			return;
		}

		$mensagens = call_user_func( array( $classe, 'avisos' ) );

		if ( ! isset( $mensagens[ $codigo ] ) ) {
			return;
		}

		printf(
			'<div class="rc-painel-empresas__aviso" role="status">%s</div>',
			esc_html( $mensagens[ $codigo ] )
		);
	}

	/**
	 * Converte `Y-m-d` em data legível, ou devolve o texto de recuo.
	 *
	 * @param string $data  Data em `Y-m-d`, ou vazio.
	 * @param string $recuo Texto quando não há data.
	 * @return string
	 */
	public static function data_legivel( $data, $recuo ) {
		$instante = '' !== $data ? strtotime( $data . ' 00:00:00' ) : false;

		return $instante ? date_i18n( get_option( 'date_format' ), $instante ) : $recuo;
	}
}
