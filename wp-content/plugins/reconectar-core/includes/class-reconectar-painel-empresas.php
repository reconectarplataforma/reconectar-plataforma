<?php
/**
 * O painel do Administrador de Empresas — rota própria, fora do `/wp-admin`.
 *
 * A especificação exige que este ator opere "através de uma interface
 * administrativa própria da aplicação, sem necessidade de acesso ao `/wp-admin`
 * completo". Duas portas já existentes foram descartadas, e por motivos que vale
 * registrar porque não são óbvios de fora:
 *
 *   - **O `/wp-admin`.** Qualquer tela ali dentro exige uma capacidade que o
 *     WordPress também usa para liberar o resto do painel. Não há como abrir uma
 *     página administrativa e manter fechadas as outras sem reimplementar o
 *     controle de acesso do núcleo.
 *   - **O dashboard do Dokan.** Pendurar uma aba em `/dashboard/` parecia o
 *     caminho mais curto até a leitura de `Shortcodes/Dashboard.php:31`: ele barra
 *     quem não passa em `dokan_is_user_seller()`, que é literalmente
 *     `user_can( $id, 'dokandar' )`. Conceder `dokandar` ao administrador de
 *     empresas faria dele um *vendedor* para todo o plugin — herdando em silêncio
 *     o que o Dokan liberar a vendedores hoje e em cada atualização futura.
 *
 * Daí a rota própria: página do WordPress com shortcode, endpoints de reescrita
 * para as subtelas, visual do tema, e nenhuma dependência do Dokan além da
 * leitura de dados — sempre sob `function_exists()`.
 *
 * @package reconectar-core
 */

defined( 'ABSPATH' ) || exit;

class Reconectar_Painel_Empresas {

	/**
	 * Slug da página que hospeda o painel.
	 *
	 * A página é criada pelo provisionamento. A URL é resolvida a partir deste
	 * slug, nunca chumbada: o site pode rodar em subdiretório, e o slug pode ser
	 * traduzido sem que nada aqui precise saber disso.
	 */
	const SLUG = 'painel-empresas';

	/**
	 * Endpoint de reescrita das telas de empresa.
	 */
	const ENDPOINT_EMPRESA = 'empresa';

	/**
	 * Endpoint de reescrita das telas de loja.
	 *
	 * Três telas saem daqui: `/loja/` lista, `/loja/nova/` cadastra e `/loja/12/`
	 * é a ficha. O endpoint já se chamou `vendedor`, e a troca apaga a rota
	 * antiga — URLs guardadas por alguém passam a cair na listagem de empresas.
	 * Aceitável porque o painel é interno; e exige `wp rewrite flush`.
	 */
	const ENDPOINT_LOJA = 'loja';

	/**
	 * Registra os ganchos.
	 */
	public static function init() {
		add_action( 'init', array( __CLASS__, 'registrar_endpoints' ) );
		add_shortcode( 'reconectar_painel_empresas', array( __CLASS__, 'renderizar' ) );

		// `template_redirect` roda antes de qualquer saída: é onde a trava de
		// acesso e o processamento de formulário cabem, porque ainda dá para
		// redirecionar sem "headers already sent".
		add_action( 'template_redirect', array( __CLASS__, 'proteger' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enfileirar_assets' ), 40 );
	}

	/**
	 * Registra os endpoints das subtelas.
	 *
	 * `EP_PAGES` é o mesmo mecanismo que o Dokan usa para as abas do painel do
	 * vendedor: o WordPress continua resolvendo a página, e o trecho seguinte da
	 * URL chega como query var.
	 *
	 * Endpoint novo exige `wp rewrite flush` — o provisionamento faz isso ao final.
	 *
	 * @return void
	 */
	public static function registrar_endpoints() {
		add_rewrite_endpoint( self::ENDPOINT_EMPRESA, EP_PAGES );
		add_rewrite_endpoint( self::ENDPOINT_LOJA, EP_PAGES );
	}

	/* ---------------------------------------------------------------------
	 * Endereços
	 * ------------------------------------------------------------------ */

	/**
	 * URL do painel, opcionalmente com um caminho de subtela.
	 *
	 * @param string $caminho Trecho a acrescentar, como `empresa/12`.
	 * @return string URL, ou string vazia se a página não existir.
	 */
	public static function url( $caminho = '' ) {
		$pagina = self::pagina();

		if ( ! $pagina ) {
			return '';
		}

		$base = get_permalink( $pagina );

		if ( '' === $caminho ) {
			return $base;
		}

		return trailingslashit( $base ) . trailingslashit( ltrim( $caminho, '/' ) );
	}

	/**
	 * A página que hospeda o painel.
	 *
	 * @return WP_Post|null
	 */
	public static function pagina() {
		$pagina = get_page_by_path( self::SLUG );

		return ( $pagina && 'publish' === $pagina->post_status ) ? $pagina : null;
	}

	/**
	 * Estamos na página do painel?
	 *
	 * @return bool
	 */
	public static function eh_a_pagina() {
		$pagina = self::pagina();

		return $pagina && is_page( $pagina->ID );
	}

	/**
	 * Qual tela a URL pede?
	 *
	 * @return array{tela:string,id:int} Tela e o ID citado na URL, quando houver.
	 */
	public static function contexto() {
		global $wp_query;

		$vars = ( $wp_query instanceof WP_Query ) ? $wp_query->query_vars : array();

		// `isset()` e não `get_query_var()`: o endpoint presente e vazio
		// (`/painel-empresas/empresa/`) devolve string vazia, que é falsy — e
		// confundi-la com "endpoint ausente" mandaria para a listagem uma URL que
		// na verdade está incompleta.
		if ( isset( $vars[ self::ENDPOINT_LOJA ] ) ) {
			$valor = trim( (string) $vars[ self::ENDPOINT_LOJA ], '/' );

			// O ramo do valor vazio vem **antes** do teste de id, e não depois:
			// `(int) 'nova'` é 0 tanto quanto `(int) ''`, e os dois casos
			// colidiriam numa ficha de loja com id 0 — que termina em 403.
			if ( '' === $valor ) {
				return array(
					'tela' => 'lojas',
					'id'   => 0,
				);
			}

			return array(
				'tela' => 'nova' === $valor ? 'loja-nova' : 'loja',
				'id'   => (int) $valor,
			);
		}

		if ( isset( $vars[ self::ENDPOINT_EMPRESA ] ) ) {
			$valor = trim( (string) $vars[ self::ENDPOINT_EMPRESA ], '/' );

			return array(
				'tela' => 'nova' === $valor ? 'empresa-nova' : 'empresa',
				'id'   => (int) $valor,
			);
		}

		return array(
			'tela' => 'lista',
			'id'   => 0,
		);
	}

	/* ---------------------------------------------------------------------
	 * Controle de acesso
	 * ------------------------------------------------------------------ */

	/**
	 * Tranca a rota e processa o formulário, nesta ordem.
	 *
	 * A checagem vem antes de qualquer consulta ao banco: um ID de empresa alheia
	 * na barra de endereços tem de ser recusado sem que a existência dela chegue a
	 * ser confirmada por um tempo de resposta diferente.
	 *
	 * @return void
	 */
	public static function proteger() {
		if ( ! self::eh_a_pagina() ) {
			return;
		}

		if ( ! is_user_logged_in() ) {
			wp_safe_redirect( wp_login_url( self::url_atual() ) );
			exit;
		}

		if ( ! current_user_can( Reconectar_Permissoes::CAP_PAINEL_EMPRESAS ) ) {
			self::recusar();
		}

		$contexto = self::contexto();

		switch ( $contexto['tela'] ) {
			case 'empresa-nova':
				if ( ! current_user_can( Reconectar_Permissoes::CAP_GERIR_EMPRESAS ) ) {
					self::recusar();
				}
				break;

			case 'empresa':
				if ( ! Reconectar_Empresa::pode_gerir_empresa( $contexto['id'] ) ) {
					self::recusar();
				}
				break;

			case 'loja-nova':
				$empresa_id = isset( $_GET['empresa'] ) ? (int) $_GET['empresa'] : 0;

				if ( ! current_user_can( Reconectar_Permissoes::CAP_GERIR_LOJAS )
					|| ! Reconectar_Empresa::pode_gerir_empresa( $empresa_id ) ) {
					self::recusar();
				}
				break;

			case 'loja':
				if ( ! Reconectar_Empresa::pode_gerir_loja( $contexto['id'] ) ) {
					self::recusar();
				}
				break;

			// `lojas` não tem caso próprio: a listagem já é filtrada pelo escopo
			// do usuário em `Reconectar_Empresa::lojas_no_escopo()`, e o
			// `CAP_PAINEL_EMPRESAS` conferido acima é a única trava que falta.
		}

		self::processar( $contexto );
	}

	/**
	 * Recusa o acesso.
	 *
	 * 403, e não redirecionamento, pelo mesmo motivo que o `/wp-admin/plugins.php`
	 * responde 403 a quem não pode: o `verificar-acessos.sh` lê o código de status,
	 * e uma recusa disfarçada de 302 passaria por "funcionou" na verificação.
	 *
	 * @return void
	 */
	private static function recusar() {
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

	/* ---------------------------------------------------------------------
	 * Escrita
	 * ------------------------------------------------------------------ */

	/**
	 * Processa o POST da tela atual e redireciona.
	 *
	 * Redirecionar depois de gravar (padrão POST/Redirect/GET) evita que um F5
	 * reenvie o formulário — o que, em "cadastrar loja", significaria uma
	 * segunda conta criada por um toque de teclado.
	 *
	 * Cada ação revalida permissão no servidor. Esconder o botão na tela não é
	 * controle de acesso: a requisição não passa pela tela.
	 *
	 * @param array $contexto Saída de `contexto()`.
	 * @return void
	 */
	private static function processar( $contexto ) {
		if ( 'POST' !== ( isset( $_SERVER['REQUEST_METHOD'] ) ? $_SERVER['REQUEST_METHOD'] : '' ) ) {
			return;
		}

		$acao = isset( $_POST['reconectar_acao'] ) ? sanitize_key( wp_unslash( $_POST['reconectar_acao'] ) ) : '';

		if ( '' === $acao ) {
			return;
		}

		$nonce = isset( $_POST['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ) : '';

		if ( ! wp_verify_nonce( $nonce, 'reconectar_painel_' . $acao ) ) {
			self::recusar();
		}

		$campos = wp_unslash( $_POST );

		switch ( $acao ) {
			case 'empresa_salvar':
				self::acao_empresa_salvar( $campos );
				break;

			case 'empresa_alternar':
				self::acao_empresa_alternar( $campos );
				break;

			case 'loja_criar':
				self::acao_loja_criar( $campos );
				break;

			case 'loja_salvar':
				self::acao_loja_salvar( $campos );
				break;

			case 'loja_alternar':
				self::acao_loja_alternar( $campos );
				break;
		}
	}

	/**
	 * Cria ou atualiza uma empresa.
	 *
	 * @param array $campos Dados do POST, já sem barras.
	 * @return void
	 */
	private static function acao_empresa_salvar( $campos ) {
		$empresa_id = isset( $campos['empresa_id'] ) ? (int) $campos['empresa_id'] : 0;

		$dados = array( 'nome' => isset( $campos['nome'] ) ? $campos['nome'] : '' );

		foreach ( array_keys( Reconectar_Empresa::campos() ) as $chave ) {
			$dados[ $chave ] = isset( $campos[ $chave ] ) ? $campos[ $chave ] : '';
		}

		$resultado = Reconectar_Empresa::salvar( $empresa_id, $dados );

		if ( is_wp_error( $resultado ) ) {
			self::guardar_formulario( 'empresa', $dados );
			self::redirecionar(
				$empresa_id ? 'empresa/' . $empresa_id : 'empresa/nova',
				'erro',
				$resultado->get_error_message(),
				$empresa_id ? array( 'editar' => 1 ) : array()
			);
		}

		self::redirecionar( 'empresa/' . (int) $resultado, 'empresa-salva' );
	}

	/**
	 * Ativa ou desativa uma empresa.
	 *
	 * @param array $campos Dados do POST.
	 * @return void
	 */
	private static function acao_empresa_alternar( $campos ) {
		$empresa_id = isset( $campos['empresa_id'] ) ? (int) $campos['empresa_id'] : 0;

		if ( ! Reconectar_Empresa::pode_gerir_empresa( $empresa_id ) ) {
			self::recusar();
		}

		$ativa = ! Reconectar_Empresa::esta_ativa( $empresa_id );
		Reconectar_Empresa::definir_ativa( $empresa_id, $ativa );

		self::redirecionar( 'empresa/' . $empresa_id, $ativa ? 'empresa-ativada' : 'empresa-desativada' );
	}

	/**
	 * Cadastra uma loja.
	 *
	 * @param array $campos Dados do POST.
	 * @return void
	 */
	private static function acao_loja_criar( $campos ) {
		$empresa_id = isset( $campos['empresa_id'] ) ? (int) $campos['empresa_id'] : 0;

		$resultado = Reconectar_Lojas::criar(
			array(
				'empresa_id' => $empresa_id,
				'login'      => isset( $campos['login'] ) ? $campos['login'] : '',
				'email'      => isset( $campos['email'] ) ? $campos['email'] : '',
				'nome'       => isset( $campos['nome'] ) ? $campos['nome'] : '',
				'primeiro'   => isset( $campos['primeiro'] ) ? $campos['primeiro'] : '',
				'ultimo'     => isset( $campos['ultimo'] ) ? $campos['ultimo'] : '',
				'telefone'   => isset( $campos['telefone'] ) ? $campos['telefone'] : '',
				'descricao'  => isset( $campos['descricao'] ) ? $campos['descricao'] : '',
			)
		);

		if ( is_wp_error( $resultado ) ) {
			self::redirecionar(
				'loja/nova',
				'erro',
				$resultado->get_error_message(),
				array( 'empresa' => $empresa_id )
			);
		}

		// O link de definição de senha não pode viajar na URL — ela fica no
		// histórico do navegador, nos logs do servidor e no cabeçalho `Referer` de
		// qualquer recurso externo da próxima página. Vai por transient, de vida
		// curta e endereçado a quem cadastrou.
		set_transient(
			self::chave_do_aviso_de_senha(),
			array(
				'loja_id' => $resultado['usuario_id'],
				'link'    => $resultado['link_de_senha'],
			),
			5 * MINUTE_IN_SECONDS
		);

		self::redirecionar( 'loja/' . $resultado['usuario_id'], 'loja-criada' );
	}

	/**
	 * Atualiza os dados de uma loja.
	 *
	 * @param array $campos Dados do POST.
	 * @return void
	 */
	private static function acao_loja_salvar( $campos ) {
		$loja_id = isset( $campos['loja_id'] ) ? (int) $campos['loja_id'] : 0;

		$resultado = Reconectar_Lojas::atualizar(
			$loja_id,
			array(
				'email'     => isset( $campos['email'] ) ? $campos['email'] : '',
				'nome'      => isset( $campos['nome'] ) ? $campos['nome'] : '',
				'primeiro'  => isset( $campos['primeiro'] ) ? $campos['primeiro'] : '',
				'ultimo'    => isset( $campos['ultimo'] ) ? $campos['ultimo'] : '',
				'telefone'  => isset( $campos['telefone'] ) ? $campos['telefone'] : '',
				'descricao' => isset( $campos['descricao'] ) ? $campos['descricao'] : '',
			)
		);

		if ( is_wp_error( $resultado ) ) {
			self::redirecionar( 'loja/' . $loja_id, 'erro', $resultado->get_error_message() );
		}

		self::redirecionar( 'loja/' . $loja_id, 'loja-salva' );
	}

	/**
	 * Ativa ou desativa uma loja.
	 *
	 * @param array $campos Dados do POST.
	 * @return void
	 */
	private static function acao_loja_alternar( $campos ) {
		$loja_id = isset( $campos['loja_id'] ) ? (int) $campos['loja_id'] : 0;

		if ( ! Reconectar_Empresa::pode_gerir_loja( $loja_id ) ) {
			self::recusar();
		}

		$ativa     = 'nao' === get_user_meta( $loja_id, Reconectar_Empresa::META_LOJA_ATIVA, true );
		$resultado = Reconectar_Lojas::definir_ativa( $loja_id, $ativa );

		if ( is_wp_error( $resultado ) ) {
			self::redirecionar( 'loja/' . $loja_id, 'erro', $resultado->get_error_message() );
		}

		self::redirecionar( 'loja/' . $loja_id, $ativa ? 'loja-ativada' : 'loja-desativada' );
	}

	/**
	 * Redireciona para uma subtela com um aviso, e encerra.
	 *
	 * @param string $caminho    Subtela de destino.
	 * @param string $aviso      Código do aviso.
	 * @param string $mensagem   Mensagem livre, para o aviso `erro`.
	 * @param array  $argumentos Query args adicionais.
	 * @return void
	 */
	private static function redirecionar( $caminho, $aviso, $mensagem = '', $argumentos = array() ) {
		$argumentos['aviso'] = $aviso;

		if ( '' !== $mensagem ) {
			$argumentos['motivo'] = rawurlencode( $mensagem );
		}

		wp_safe_redirect( add_query_arg( $argumentos, self::url( $caminho ) ) );
		exit;
	}

	/**
	 * Guarda o que foi digitado num formulário que o servidor recusou.
	 *
	 * Transient por usuário, e não query string: os dados voltam pela URL de um
	 * redirect, e cadastro de empresa tem campo demais — razão social e nome de
	 * responsável apareceriam na barra de endereços e no log do servidor. O
	 * transient vive cinco minutos, o bastante para a volta imediata do redirect.
	 *
	 * @param string $formulario Identificador do formulário.
	 * @param array  $dados      Campos submetidos.
	 * @return void
	 */
	private static function guardar_formulario( $formulario, array $dados ) {
		set_transient( self::chave_do_formulario( $formulario ), $dados, 5 * MINUTE_IN_SECONDS );
	}

	/**
	 * Consome os dados recusados de um formulário, se houver.
	 *
	 * Consome mesmo: o transient é apagado na leitura, ou um cadastro abandonado
	 * reapareceria preenchido na próxima vez que a tela fosse aberta, com dados de
	 * outra empresa.
	 *
	 * @param string $formulario Identificador do formulário.
	 * @return array Campos submetidos, ou array vazio.
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
		return 'reconectar_form_' . sanitize_key( $formulario ) . '_' . get_current_user_id();
	}

	/**
	 * Chave do transient que guarda o link de senha recém-gerado.
	 *
	 * @return string
	 */
	private static function chave_do_aviso_de_senha() {
		return 'reconectar_senha_nova_' . get_current_user_id();
	}

	/**
	 * Consome o link de senha guardado, se houver.
	 *
	 * @return array{loja_id:int,link:string}|null
	 */
	public static function link_de_senha_pendente() {
		$dados = get_transient( self::chave_do_aviso_de_senha() );

		if ( ! is_array( $dados ) ) {
			return null;
		}

		delete_transient( self::chave_do_aviso_de_senha() );

		return $dados;
	}

	/* ---------------------------------------------------------------------
	 * Leitura da operação
	 * ------------------------------------------------------------------ */

	/**
	 * Produtos de uma loja.
	 *
	 * `WP_Query` com `author`, e não uma consulta por meta: a API central honra os
	 * argumentos que recebe, ao contrário de `wc_get_orders()` — ver a armadilha
	 * registrada no CLAUDE.md.
	 *
	 * @param int $loja_id ID da loja.
	 * @param int $limite  Quantidade máxima.
	 * @return WP_Post[]
	 */
	public static function produtos_da_loja( $loja_id, $limite = 20 ) {
		return get_posts(
			array(
				'post_type'      => 'product',
				'post_status'    => array( 'publish', 'draft', 'pending', 'private' ),
				'author'         => (int) $loja_id,
				'posts_per_page' => (int) $limite,
				'orderby'        => 'date',
				'order'          => 'DESC',
			)
		);
	}

	/**
	 * Pedidos de uma loja.
	 *
	 * Passa pelo Dokan porque é ele quem sabe relacionar pedido e loja — inclusive
	 * quando o pedido tem itens de lojas diferentes, caso em que o WooCommerce
	 * sozinho não tem resposta.
	 *
	 * **Nunca** `wc_get_orders()` filtrando por meta: a função descarta
	 * `meta_key`, `meta_value` e `meta_query` em silêncio, e o retorno não é vazio
	 * — é o banco inteiro, que com um `limit` pequeno passa por resposta plausível.
	 *
	 * `seller_id` fica: é o nome do argumento na API do Dokan, não vocabulário
	 * nosso.
	 *
	 * @param int $loja_id ID da loja.
	 * @param int $limite  Quantidade máxima.
	 * @return array Pedidos como o Dokan os devolve, ou array vazio.
	 */
	public static function pedidos_da_loja( $loja_id, $limite = 10 ) {
		if ( ! function_exists( 'dokan' ) ) {
			return array();
		}

		$pedidos = dokan()->order->all(
			array(
				'seller_id' => (int) $loja_id,
				'limit'     => (int) $limite,
				'paged'     => 1,
			)
		);

		return is_array( $pedidos ) ? $pedidos : array();
	}

	/**
	 * Faturamento acumulado de uma loja.
	 *
	 * O segundo argumento não é opcional na prática. A assinatura do Dokan é
	 * `dokan_get_seller_earnings( $seller_id, $formatted = true )`, e o padrão
	 * `true` devolve o resultado já passado por `wc_price()` — uma string de HTML,
	 * não um número. Chamando sem ele, o `is_numeric()` abaixo reprovava todo
	 * valor, a função devolvia `null` e a coluna Faturamento mostrava travessão
	 * para todas as lojas, inclusive as que tinham pedidos pagos. O defeito
	 * era invisível na leitura do código: a chamada parecia correta e o "—" tem
	 * significado legítimo neste painel (dado indisponível), então passava por
	 * comportamento esperado.
	 *
	 * O número é o mesmo que a própria loja vê no painel do Dokan — o
	 * `big-counter-widget.php` chama esta mesma função. São ganhos liberados: o
	 * Dokan só soma as linhas de `wp_dokan_vendor_balance` cujo status está entre
	 * os de saque (`dokan_withdraw_get_active_order_status_in_comma()`), hoje
	 * `wc-completed` e `wc-refunded`. Pedido em preparação ou a caminho ainda não
	 * entra na conta, e é assim que deve ser: os dois painéis precisam mostrar o
	 * mesmo número para a mesma loja.
	 *
	 * @param int $loja_id ID da loja.
	 * @return float|null Valor, ou null quando o Dokan não puder informar.
	 */
	public static function faturamento_da_loja( $loja_id ) {
		if ( ! function_exists( 'dokan_get_seller_earnings' ) ) {
			return null;
		}

		$valor = dokan_get_seller_earnings( (int) $loja_id, false );

		return is_numeric( $valor ) ? (float) $valor : null;
	}

	/**
	 * Formata um valor monetário, ou devolve o travessão.
	 *
	 * Campo vazio quando o dado não existe — nunca um número plausível inventado
	 * para preencher a tela. É a mesma regra de honestidade que vale para tempo de
	 * entrega e taxa no resto da plataforma.
	 *
	 * @param float|null $valor Valor a formatar.
	 * @return string HTML já escapado.
	 */
	public static function dinheiro( $valor ) {
		if ( null === $valor ) {
			return '—';
		}

		return function_exists( 'wc_price' )
			? wp_kses_post( wc_price( $valor ) )
			: esc_html( number_format_i18n( $valor, 2 ) );
	}

	/* ---------------------------------------------------------------------
	 * Renderização
	 * ------------------------------------------------------------------ */

	/**
	 * Enfileira estilo e script do painel, só nesta página.
	 *
	 * O estilo depende de `reconectar-bootstrap`, que o tema já registra: a grade
	 * e os utilitários vêm de lá, auto-hospedados. Sem build e sem CDN.
	 *
	 * O script é a máscara dos campos cadastrais. Vai com `defer` e sem
	 * dependência: ele não usa jQuery nem espera nada além do próprio DOM, e o
	 * formulário funciona igual se o arquivo não carregar — a validação que
	 * importa está no servidor, em `Reconectar_Empresa::normalizar_campo()`.
	 *
	 * @return void
	 */
	public static function enfileirar_assets() {
		if ( ! self::eh_a_pagina() ) {
			return;
		}

		wp_enqueue_style(
			'reconectar-painel-empresas',
			RECONECTAR_CORE_URL . 'assets/css/painel-empresas.css',
			array( 'reconectar-bootstrap' ),
			'0.1.0'
		);

		wp_enqueue_script(
			'reconectar-painel-empresas',
			RECONECTAR_CORE_URL . 'assets/js/painel-empresas.js',
			array(),
			'0.1.0',
			array(
				'strategy'  => 'defer',
				'in_footer' => true,
			)
		);
	}

	/**
	 * Renderiza o painel.
	 *
	 * As checagens de acesso já correram em `proteger()`; o shortcode repete a
	 * primeira delas porque um shortcode pode ser colado em qualquer página, e ali
	 * `proteger()` não teria rodado.
	 *
	 * @return string HTML do painel.
	 */
	public static function renderizar() {
		if ( ! is_user_logged_in() || ! current_user_can( Reconectar_Permissoes::CAP_PAINEL_EMPRESAS ) ) {
			return '<p class="rc-painel-empresas__negado">'
				. esc_html__( 'Você não tem permissão para acessar esta área.', 'reconectar-core' )
				. '</p>';
		}

		$contexto = self::contexto();

		$views = array(
			'lista'        => 'lista-empresas',
			'empresa-nova' => 'form-empresa',
			'empresa'      => 'ficha-empresa',
			'lojas'        => 'lista-lojas',
			'loja-nova'    => 'form-loja',
			'loja'         => 'ficha-loja',
		);

		if ( ! isset( $views[ $contexto['tela'] ] ) ) {
			return '';
		}

		ob_start();

		echo '<div class="rc-painel-empresas rc-painel-empresas--dashboard">';
		self::menu();

		echo '<div class="rc-painel-empresas__area">';
		self::aviso();

		include RECONECTAR_CORE_PATH . 'includes/painel-empresas/' . $views[ $contexto['tela'] ] . '.php';

		echo '</div>';
		echo '</div>';

		return ob_get_clean();
	}

	/**
	 * Seção do menu a que uma tela pertence.
	 *
	 * Existe para que a ficha e o formulário marquem a seção da listagem de onde
	 * saíram — sem isto, entrar numa ficha apagaria o item ativo e o menu passaria
	 * a dizer que o usuário não está em lugar nenhum.
	 *
	 * @param string $tela Tela corrente, como `contexto()` a devolve.
	 * @return string `empresas` ou `lojas`.
	 */
	private static function secao_da_tela( $tela ) {
		return in_array( $tela, array( 'lojas', 'loja-nova', 'loja' ), true ) ? 'lojas' : 'empresas';
	}

	/**
	 * Menu lateral do painel.
	 *
	 * Traz o encerramento de sessão porque este painel é, para o Administrador de
	 * Empresas, a aplicação inteira: ele não tem `/wp-admin`, não tem o painel do
	 * Dokan e o atalho de conta do tema o traz para cá. Sem um "Sair" aqui, o
	 * único jeito de encerrar a sessão era digitar `/wp-login.php?action=logout`
	 * na barra de endereços — ou fechar o navegador e torcer, que é como sessão
	 * administrativa fica aberta em computador compartilhado.
	 *
	 * `wp_logout_url()` já embute o nonce: sem ele, qualquer página de terceiro
	 * poderia derrubar a sessão do usuário com uma imagem apontando para a URL.
	 *
	 * A seção corrente é marcada com `aria-current="page"`, nunca com
	 * `aria-pressed`: o item é um `<a>`, e `aria-pressed` só tem sentido em
	 * controle de dois estados.
	 *
	 * Em telas estreitas o menu vira faixa rolável — e não um `<details>`, que
	 * abriria sozinho no celular.
	 *
	 * @return void
	 */
	private static function menu() {
		$secao   = self::secao_da_tela( self::contexto()['tela'] );
		$usuario = wp_get_current_user();

		$itens = array(
			'empresas' => array(
				'rotulo' => __( 'Empresas', 'reconectar-core' ),
				'url'    => self::url(),
			),
			'lojas'    => array(
				'rotulo' => __( 'Lojas', 'reconectar-core' ),
				'url'    => self::url( self::ENDPOINT_LOJA ),
			),
		);
		?>
		<aside class="rc-painel-empresas__menu">
			<p class="rc-painel-empresas__marca"><?php esc_html_e( 'Painel gerencial', 'reconectar-core' ); ?></p>

			<nav class="rc-painel-empresas__navegacao" aria-label="<?php esc_attr_e( 'Seções do painel', 'reconectar-core' ); ?>">
				<ul>
					<?php foreach ( $itens as $chave => $item ) : ?>
						<li>
							<a class="rc-painel-empresas__item<?php echo $chave === $secao ? ' rc-painel-empresas__item--ativo' : ''; ?>"
								href="<?php echo esc_url( $item['url'] ); ?>"
								<?php echo $chave === $secao ? 'aria-current="page"' : ''; ?>>
								<?php echo esc_html( $item['rotulo'] ); ?>
							</a>
						</li>
					<?php endforeach; ?>
				</ul>
			</nav>

			<div class="rc-painel-empresas__sessao">
				<span class="rc-painel-empresas__usuario">
					<?php
					printf(
						/* translators: %s: nome de quem está conectado. */
						esc_html__( 'Sessão de %s', 'reconectar-core' ),
						'<strong>' . esc_html( $usuario->display_name ) . '</strong>'
					);
					?>
				</span>

				<a class="rc-painel-empresas__sair" href="<?php echo esc_url( wp_logout_url( home_url( '/' ) ) ); ?>">
					<?php esc_html_e( 'Sair', 'reconectar-core' ); ?>
				</a>
			</div>
		</aside>
		<?php
	}

	/**
	 * Imprime o aviso da última ação, quando houver.
	 *
	 * @return void
	 */
	private static function aviso() {
		$codigo = isset( $_GET['aviso'] ) ? sanitize_key( wp_unslash( $_GET['aviso'] ) ) : '';

		if ( '' === $codigo ) {
			return;
		}

		$mensagens = array(
			'empresa-salva'      => __( 'Empresa salva.', 'reconectar-core' ),
			'empresa-ativada'    => __( 'Empresa ativada. As lojas dela voltaram ao estado individual de cada uma.', 'reconectar-core' ),
			'empresa-desativada' => __( 'Empresa desativada. As lojas dela estão fora de operação.', 'reconectar-core' ),
			'loja-criada'        => __( 'Loja cadastrada.', 'reconectar-core' ),
			'loja-salva'         => __( 'Dados da loja atualizados.', 'reconectar-core' ),
			'loja-ativada'       => __( 'Loja ativada.', 'reconectar-core' ),
			'loja-desativada'    => __( 'Loja desativada.', 'reconectar-core' ),
		);

		if ( 'erro' === $codigo ) {
			$motivo = isset( $_GET['motivo'] ) ? sanitize_text_field( rawurldecode( wp_unslash( $_GET['motivo'] ) ) ) : '';
			printf(
				'<div class="rc-painel-empresas__aviso rc-painel-empresas__aviso--erro" role="alert">%s</div>',
				esc_html( '' !== $motivo ? $motivo : __( 'Não foi possível concluir a ação.', 'reconectar-core' ) )
			);
			return;
		}

		if ( ! isset( $mensagens[ $codigo ] ) ) {
			return;
		}

		printf(
			'<div class="rc-painel-empresas__aviso" role="status">%s</div>',
			esc_html( $mensagens[ $codigo ] )
		);
	}
}
