<?php
/**
 * Controle de acesso por perfil (RBAC) da plataforma Reconectar.
 *
 * A especificação do projeto define três atores e é explícita quanto a duas
 * exigências que o WordPress, o WooCommerce e o Dokan não atendem sozinhos:
 *
 * 1. o Usuário Comum (papel `customer`) **não** tem acesso a fóruns,
 *    comunidade, área administrativa nem painel de vendedor;
 * 2. o isolamento entre vendedores vale "tanto na interface quanto nas regras
 *    de autorização do backend" — não basta esconder o link.
 *
 * O Dokan isola o painel do vendedor, mas esse isolamento é do painel: ele não
 * governa o que acontece quando alguém chama a REST API, monta uma URL de
 * edição na mão ou dispara uma ação administrativa por outro caminho. O bbPress
 * e o BuddyPress, por sua vez, são abertos por padrão a qualquer pessoa
 * autenticada — a comunidade da plataforma não é.
 *
 * Esta classe fecha essas duas lacunas em um único lugar. A regra é sempre
 * aplicada na autorização (`map_meta_cap`, `template_redirect`, `admin_init`) e,
 * só então, refletida na interface (itens de menu). Fazer o contrário — esconder
 * primeiro e confiar nisso — é o erro que a especificação nomeia.
 *
 * @package reconectar-core
 */

defined( 'ABSPATH' ) || exit;

class Reconectar_Permissoes {

	/**
	 * Capacidade própria que autoriza o uso dos fóruns e da comunidade.
	 *
	 * Poderíamos checar o papel do usuário diretamente ("é `customer`?"), mas
	 * papel não é permissão: um dia pode existir um moderador, um curador ou um
	 * técnico do FUNDEPES que precise entrar na comunidade sem virar vendedor.
	 * Com uma capacidade própria, isso vira uma linha no painel de papéis em vez
	 * de uma alteração neste arquivo.
	 */
	const CAP_COMUNIDADE = 'reconectar_participar_comunidade';

	/**
	 * Papéis que recebem a capacidade acima na sincronização.
	 *
	 * @var string[]
	 */
	const PAPEIS_DA_COMUNIDADE = array( 'administrator', 'seller' );

	/**
	 * Capacidades de gestão de plugins, restritas ao Administrador.
	 *
	 * A especificação é taxativa: apenas o Administrador instala, ativa,
	 * desativa, atualiza, configura ou remove plugins.
	 *
	 * @var string[]
	 */
	const CAPS_DE_PLUGIN = array(
		'install_plugins',
		'activate_plugins',
		'update_plugins',
		'delete_plugins',
		'edit_plugins',
		'upload_plugins',
	);

	/**
	 * Versão do conjunto de capacidades aplicado aos papéis.
	 *
	 * Mexer em papéis grava no banco, então isso não pode acontecer a cada
	 * carregamento de página. A opção guarda a versão já aplicada e a
	 * sincronização só roda quando este número muda — incremente-o ao alterar
	 * `sincronizar_capacidades()`.
	 */
	const VERSAO_CAPACIDADES = 1;

	/**
	 * Nome da opção que guarda a versão aplicada.
	 */
	const OPCAO_VERSAO = 'reconectar_permissoes_versao';

	/**
	 * Registra os ganchos.
	 */
	public static function init() {
		add_action( 'init', array( __CLASS__, 'sincronizar_capacidades' ) );

		// Autorização.
		add_filter( 'map_meta_cap', array( __CLASS__, 'restringir_por_vendedor' ), 10, 4 );
		add_filter( 'map_meta_cap', array( __CLASS__, 'restringir_gestao_de_plugins' ), 10, 2 );
		add_action( 'template_redirect', array( __CLASS__, 'bloquear_comunidade' ) );
		add_action( 'admin_init', array( __CLASS__, 'bloquear_area_administrativa' ) );
		add_action( 'pre_get_posts', array( __CLASS__, 'restringir_listagens_do_vendedor' ) );

		// Interface: o que já foi negado acima também não deve ser oferecido.
		add_filter( 'wp_nav_menu_objects', array( __CLASS__, 'ocultar_itens_da_comunidade' ) );
		add_filter( 'show_admin_bar', array( __CLASS__, 'ocultar_barra_administrativa' ) );
	}

	/* ---------------------------------------------------------------------
	 * Capacidades
	 * ------------------------------------------------------------------ */

	/**
	 * Concede e revoga capacidades nos papéis, uma vez por versão.
	 *
	 * Roda em `init` em vez de no gancho de ativação do plugin porque o papel
	 * `seller` é criado pelo Dokan: se o Reconectar Core for ativado antes dele,
	 * o papel ainda não existe e a concessão se perderia em silêncio.
	 */
	public static function sincronizar_capacidades() {
		if ( (int) get_option( self::OPCAO_VERSAO ) === self::VERSAO_CAPACIDADES ) {
			return;
		}

		$papeis = wp_roles();

		foreach ( $papeis->get_names() as $papel => $nome ) {
			$objeto = $papeis->get_role( $papel );

			if ( ! $objeto ) {
				continue;
			}

			if ( in_array( $papel, self::PAPEIS_DA_COMUNIDADE, true ) ) {
				$objeto->add_cap( self::CAP_COMUNIDADE );
			} else {
				// `remove_cap` também é chamado para papéis que nunca tiveram a
				// capacidade: é barato e garante que uma concessão feita por
				// engano (ou por um plugin) seja desfeita na próxima versão.
				$objeto->remove_cap( self::CAP_COMUNIDADE );
			}

			if ( 'administrator' === $papel ) {
				continue;
			}

			foreach ( self::CAPS_DE_PLUGIN as $capacidade ) {
				$objeto->remove_cap( $capacidade );
			}
		}

		update_option( self::OPCAO_VERSAO, self::VERSAO_CAPACIDADES );
	}

	/**
	 * Nega a gestão de plugins a quem não for Administrador.
	 *
	 * A revogação em `sincronizar_capacidades()` já resolve o caso normal, mas
	 * ela age sobre o papel — e uma capacidade pode ser concedida diretamente a
	 * um usuário, ou por outro plugin via `user_has_cap`. Esta checagem fecha
	 * essa porta na hora da decisão.
	 *
	 * @param string[] $caps Capacidades primitivas exigidas.
	 * @param string   $cap  Capacidade consultada.
	 * @return string[]
	 */
	public static function restringir_gestao_de_plugins( $caps, $cap ) {
		if ( in_array( $cap, self::CAPS_DE_PLUGIN, true ) && ! in_array( 'manage_options', $caps, true ) ) {
			// `manage_options` é a capacidade que, na prática, distingue o
			// Administrador dos demais papéis desta instalação. Exigi-la junto
			// preserva a decisão original quando ela já for restritiva e a
			// endurece quando não for.
			$caps[] = 'manage_options';
		}

		return $caps;
	}

	/* ---------------------------------------------------------------------
	 * Isolamento entre vendedores
	 * ------------------------------------------------------------------ */

	/**
	 * O usuário tem o papel de vendedor?
	 *
	 * @param int $usuario_id ID do usuário.
	 * @return bool
	 */
	public static function eh_vendedor( $usuario_id ) {
		$usuario = get_userdata( $usuario_id );

		return $usuario && in_array( 'seller', (array) $usuario->roles, true );
	}

	/**
	 * Impede que um vendedor leia ou altere registro de outro vendedor.
	 *
	 * Esta é a trava de backend exigida pela especificação: ela vale para o
	 * painel administrativo, para a REST API e para qualquer código que consulte
	 * `current_user_can()` antes de agir — que é o contrato do WordPress para
	 * autorização.
	 *
	 * @param string[] $caps       Capacidades primitivas exigidas.
	 * @param string   $cap        Capacidade consultada.
	 * @param int      $usuario_id Usuário sob avaliação.
	 * @param array    $args       Argumentos; `$args[0]` costuma ser o ID do objeto.
	 * @return string[]
	 */
	public static function restringir_por_vendedor( $caps, $cap, $usuario_id, $args ) {
		$monitoradas = array(
			'edit_post',
			'delete_post',
			'read_post',
			'edit_product',
			'delete_product',
			'read_product',
			'edit_shop_order',
			'delete_shop_order',
			'read_shop_order',
		);

		if ( ! in_array( $cap, $monitoradas, true ) || empty( $args[0] ) ) {
			return $caps;
		}

		// Quem administra a loja enxerga tudo — é o papel dele. A consulta usa
		// uma capacidade fora da lista monitorada, então não há recursão.
		if ( user_can( $usuario_id, 'manage_woocommerce' ) ) {
			return $caps;
		}

		if ( ! self::eh_vendedor( $usuario_id ) ) {
			return $caps;
		}

		$dono = self::dono_do_objeto( (int) $args[0] );

		// `null` significa "não é produto nem pedido": um post comum, uma página,
		// um anexo. Regra nenhuma daqui se aplica, e inventar uma quebraria
		// funcionalidade que não é nossa.
		if ( null !== $dono && (int) $dono !== (int) $usuario_id ) {
			$caps[] = 'do_not_allow';
		}

		return $caps;
	}

	/**
	 * Descobre a qual vendedor pertence um produto ou um pedido.
	 *
	 * @param int $objeto_id ID do post ou do pedido.
	 * @return int|null ID do vendedor, ou null se o objeto não for de nenhum dos dois tipos.
	 */
	private static function dono_do_objeto( $objeto_id ) {
		// Pedidos vêm primeiro porque, com o armazenamento HPOS ativo, eles não
		// são posts e `get_post_type()` devolveria `false` para um ID válido.
		if ( function_exists( 'wc_get_order' ) ) {
			$pedido = wc_get_order( $objeto_id );

			if ( $pedido ) {
				// Em pedido de vendedor único o Dokan grava `_dokan_vendor_id` no
				// próprio pedido; em pedido multi-vendedor, em cada sub-pedido.
				$vendedor = $pedido->get_meta( '_dokan_vendor_id' );

				return $vendedor ? (int) $vendedor : null;
			}
		}

		if ( 'product' === get_post_type( $objeto_id ) ) {
			return (int) get_post_field( 'post_author', $objeto_id );
		}

		return null;
	}

	/**
	 * Restringe as listagens de produto do vendedor às suas próprias.
	 *
	 * `map_meta_cap` protege o acesso a um registro específico, mas não filtra
	 * uma consulta: sem isto, a lista de produtos do admin e a coleção de
	 * produtos da REST API devolveriam o catálogo inteiro para um vendedor —
	 * revelando nomes, preços e estoque dos concorrentes, ainda que ele não
	 * conseguisse abrir nenhum deles.
	 *
	 * @param WP_Query $consulta Consulta em execução.
	 */
	public static function restringir_listagens_do_vendedor( $consulta ) {
		if ( ! is_user_logged_in() || ! $consulta->is_main_query() ) {
			return;
		}

		// Somente no que é área de gestão. A vitrine pública precisa continuar
		// mostrando o catálogo completo — inclusive para um vendedor logado.
		$eh_gestao = is_admin() || ( defined( 'REST_REQUEST' ) && REST_REQUEST );

		if ( ! $eh_gestao || 'product' !== $consulta->get( 'post_type' ) ) {
			return;
		}

		$usuario_id = get_current_user_id();

		if ( current_user_can( 'manage_woocommerce' ) || ! self::eh_vendedor( $usuario_id ) ) {
			return;
		}

		$consulta->set( 'author', $usuario_id );
	}

	/* ---------------------------------------------------------------------
	 * Comunidade e fóruns
	 * ------------------------------------------------------------------ */

	/**
	 * A requisição atual aponta para conteúdo de comunidade?
	 *
	 * @return bool
	 */
	private static function requisicao_e_de_comunidade() {
		// bbPress registra três post types; qualquer um deles, isolado ou em
		// arquivo, é fórum.
		$tipos_bbpress = array( 'forum', 'topic', 'reply' );

		if ( is_singular( $tipos_bbpress ) || is_post_type_archive( $tipos_bbpress ) || is_tax( array( 'topic-tag' ) ) ) {
			return true;
		}

		// O BuddyPress não usa post types para seus componentes: ele responde a
		// URLs próprias e informa o componente ativo pela função abaixo.
		if ( function_exists( 'bp_current_component' ) && bp_current_component() ) {
			return true;
		}

		// A página "Comunidade" criada pelo provisionamento hospeda o shortcode
		// `[buddypress]`. Na raiz dela o BuddyPress ainda não reporta componente
		// algum, então ela precisa ser reconhecida pelo slug.
		if ( is_page( 'comunidade' ) ) {
			return true;
		}

		return false;
	}

	/**
	 * Interrompe o acesso à comunidade para quem não tem a capacidade.
	 */
	public static function bloquear_comunidade() {
		if ( ! self::requisicao_e_de_comunidade() || current_user_can( self::CAP_COMUNIDADE ) ) {
			return;
		}

		if ( ! is_user_logged_in() ) {
			// Visitante pode simplesmente não ter entrado ainda; mandá-lo ao
			// login com retorno é mais útil do que um 403.
			wp_safe_redirect( wp_login_url( home_url( add_query_arg( array() ) ) ) );
			exit;
		}

		wp_die(
			esc_html__( 'Esta área é reservada a vendedores e à administração da plataforma. Sua conta de cliente não tem acesso aos fóruns e à comunidade.', 'reconectar-core' ),
			esc_html__( 'Acesso restrito', 'reconectar-core' ),
			array(
				'response'  => 403,
				'back_link' => true,
			)
		);
	}

	/**
	 * Remove do menu os itens que levam a áreas bloqueadas.
	 *
	 * O bloqueio acima já é suficiente do ponto de vista de segurança; este
	 * filtro existe pela razão oposta, de usabilidade: oferecer um link que
	 * devolve 403 é um defeito de interface.
	 *
	 * @param array $itens Itens do menu.
	 * @return array
	 */
	public static function ocultar_itens_da_comunidade( $itens ) {
		if ( current_user_can( self::CAP_COMUNIDADE ) ) {
			return $itens;
		}

		$comunidade = get_page_by_path( 'comunidade' );
		$pagina_id  = $comunidade ? (int) $comunidade->ID : 0;

		foreach ( $itens as $indice => $item ) {
			$aponta_para_pagina = $pagina_id
				&& 'post_type' === $item->type
				&& (int) $item->object_id === $pagina_id;

			$aponta_para_forum = in_array( $item->object, array( 'forum', 'topic' ), true );

			if ( $aponta_para_pagina || $aponta_para_forum ) {
				unset( $itens[ $indice ] );
			}
		}

		return $itens;
	}

	/* ---------------------------------------------------------------------
	 * Área administrativa
	 * ------------------------------------------------------------------ */

	/**
	 * Mantém clientes e vendedores fora do `/wp-admin`.
	 *
	 * O vendedor trabalha no painel do Dokan, no front-end, e o cliente na área
	 * "Minha conta". Nenhum dos dois tem o que fazer no painel do WordPress, e a
	 * especificação lista a área administrativa como exclusiva do Administrador.
	 */
	public static function bloquear_area_administrativa() {
		// `admin-ajax.php` mora dentro de `/wp-admin` e atende requisições do
		// front-end — inclusive as do carrinho e as do painel do Dokan. Bloqueá-lo
		// quebraria a loja para as duas pessoas que este método protege.
		if ( wp_doing_ajax() ) {
			return;
		}

		if ( current_user_can( 'manage_woocommerce' ) || current_user_can( 'manage_options' ) ) {
			return;
		}

		if ( ! is_user_logged_in() ) {
			return;
		}

		$destino = self::eh_vendedor( get_current_user_id() ) && function_exists( 'dokan_get_navigation_url' )
			? dokan_get_navigation_url()
			: ( function_exists( 'wc_get_page_permalink' ) ? wc_get_page_permalink( 'myaccount' ) : home_url( '/' ) );

		wp_safe_redirect( $destino );
		exit;
	}

	/**
	 * Esconde a barra administrativa de quem não entra no painel.
	 *
	 * @param bool $exibir Decisão anterior.
	 * @return bool
	 */
	public static function ocultar_barra_administrativa( $exibir ) {
		if ( ! is_user_logged_in() ) {
			return $exibir;
		}

		if ( current_user_can( 'manage_woocommerce' ) || current_user_can( 'manage_options' ) ) {
			return $exibir;
		}

		return false;
	}
}
