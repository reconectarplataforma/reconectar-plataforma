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
	const PAPEIS_DA_COMUNIDADE = array( 'administrator', 'seller', 'company_admin' );

	/**
	 * Papel do Administrador de Empresas.
	 *
	 * Quarto ator da plataforma: administra a *operação* — cadastra empresas e
	 * vendedores, consulta produtos e pedidos de quem gere — sem administrar a
	 * *tecnologia*. É o princípio que a especificação enuncia, e a razão de este
	 * papel não receber nenhuma capacidade nativa do WordPress para isso.
	 */
	const PAPEL_ADMIN_EMPRESAS = 'company_admin';

	/**
	 * Portão da rota `/painel-empresas/`.
	 */
	const CAP_PAINEL_EMPRESAS = 'reconectar_acessar_painel_empresas';

	/**
	 * Criar, editar, ativar e desativar empresas.
	 */
	const CAP_GERIR_EMPRESAS = 'reconectar_gerir_empresas';

	/**
	 * Criar, editar, ativar e desativar vendedores.
	 */
	const CAP_GERIR_VENDEDORES = 'reconectar_gerir_vendedores';

	/**
	 * Ler produtos, pedidos, estoque e faturamento das empresas sob gestão.
	 */
	const CAP_VER_OPERACAO = 'reconectar_ver_operacao_da_empresa';

	/**
	 * Alcance total: administra todas as empresas, e não apenas as vinculadas.
	 *
	 * Fica **fora** do papel por padrão. O vínculo normal é N:N e explícito, na
	 * user meta `_reconectar_empresas_geridas`; esta capacidade é a exceção
	 * prevista pela especificação ("salvo quando possuir uma permissão específica
	 * para administrar múltiplas empresas") e precisa ser concedida a dedo, a um
	 * usuário por vez. Separá-la do papel é o que impede que "administrar uma
	 * empresa" vire, por descuido, "administrar todas".
	 */
	const CAP_TODAS_AS_EMPRESAS = 'reconectar_gerir_todas_as_empresas';

	/**
	 * Todas as capacidades ligadas à administração de empresas.
	 *
	 * @var string[]
	 */
	const CAPS_DE_EMPRESA = array(
		self::CAP_PAINEL_EMPRESAS,
		self::CAP_GERIR_EMPRESAS,
		self::CAP_GERIR_VENDEDORES,
		self::CAP_VER_OPERACAO,
		self::CAP_TODAS_AS_EMPRESAS,
	);

	/**
	 * Capacidades do papel `company_admin`.
	 *
	 * A lista é deliberadamente curta, e o que ela *não* traz importa tanto
	 * quanto o que traz: sem `manage_options`, `manage_woocommerce`,
	 * `edit_users`, `create_users`, `promote_users`, `list_users`, sem nenhuma
	 * `CAPS_DE_PLUGIN` e sem `dokandar`.
	 *
	 * As três primeiras abririam o `/wp-admin` inteiro — `bloquear_area_administrativa()`
	 * usa `manage_options` e `manage_woocommerce` como portão, e `edit_users` dá
	 * acesso a qualquer conta, inclusive às administrativas. `dokandar` é a
	 * capacidade que, para o Dokan, *define* que alguém é vendedor
	 * (`dokan_is_user_seller()` é literalmente `user_can( $id, 'dokandar' )`):
	 * concedê-la faria este ator herdar em silêncio tudo que o plugin liberar por
	 * ela daqui em diante.
	 *
	 * `CAP_TODAS_AS_EMPRESAS` também está de fora — ver o comentário dela.
	 *
	 * @var string[]
	 */
	const CAPS_DO_ADMIN_DE_EMPRESAS = array(
		'read',
		self::CAP_COMUNIDADE,
		self::CAP_PAINEL_EMPRESAS,
		self::CAP_GERIR_EMPRESAS,
		self::CAP_GERIR_VENDEDORES,
		self::CAP_VER_OPERACAO,
	);

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
	const VERSAO_CAPACIDADES = 2;

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
		add_filter( 'map_meta_cap', array( __CLASS__, 'negar_escrita_ao_admin_de_empresas' ), 10, 4 );
		add_filter( 'map_meta_cap', array( __CLASS__, 'negar_escrita_no_forum' ), 10, 3 );
		add_action( 'template_redirect', array( __CLASS__, 'bloquear_comunidade' ) );
		// Prioridade 1, e não a padrão: o WooCommerce registra em `admin_init` o
		// seu próprio bloqueio (`WC_Admin::prevent_admin_access()`), que manda para
		// "Minha conta" quem não tem `edit_posts`, `manage_woocommerce` nem
		// `view_admin_dashboard`. O Administrador de Empresas não tem nenhuma das
		// três — e caía lá, em vez de no painel dele, porque o WooCommerce chegava
		// primeiro. Com o vendedor a inversão não aparecia (o Dokan lhe dá
		// `edit_posts`) e com o cliente o destino coincidia, então o defeito só se
		// revelou no ator novo. Quem decide para onde vai cada ator da plataforma
		// é a plataforma.
		add_action( 'admin_init', array( __CLASS__, 'bloquear_area_administrativa' ), 1 );
		add_action( 'pre_get_posts', array( __CLASS__, 'restringir_listagens_do_vendedor' ) );

		// Interface: o que já foi negado acima também não deve ser oferecido.
		add_filter( 'wp_nav_menu_objects', array( __CLASS__, 'ocultar_itens_da_comunidade' ) );
		add_filter( 'widget_custom_html_content', array( __CLASS__, 'ocultar_links_da_comunidade_em_widget' ) );
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

		self::sincronizar_papel_do_admin_de_empresas();

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
				// O Administrador acumula os dois lados: administra a tecnologia
				// e, por consequência, também a operação. Recebe inclusive
				// `CAP_TODAS_AS_EMPRESAS` — sem ela, o painel de empresas ficaria
				// vazio para quem instalou a plataforma.
				foreach ( self::CAPS_DE_EMPRESA as $capacidade ) {
					$objeto->add_cap( $capacidade );
				}

				continue;
			}

			if ( self::PAPEL_ADMIN_EMPRESAS !== $papel ) {
				foreach ( self::CAPS_DE_EMPRESA as $capacidade ) {
					$objeto->remove_cap( $capacidade );
				}
			}

			foreach ( self::CAPS_DE_PLUGIN as $capacidade ) {
				$objeto->remove_cap( $capacidade );
			}
		}

		update_option( self::OPCAO_VERSAO, self::VERSAO_CAPACIDADES );
	}

	/**
	 * Cria — ou recria — o papel do Administrador de Empresas.
	 *
	 * `remove_role()` antes de `add_role()` não é redundância com o `add_role()`
	 * sozinho, que é inerte quando o papel já existe: sem a remoção, uma
	 * capacidade retirada de `CAPS_DO_ADMIN_DE_EMPRESAS` continuaria gravada no
	 * banco para sempre. Como a lista é justamente o registro do que este ator
	 * *não* pode, ela precisa ser a verdade — e não o teto histórico.
	 *
	 * Os dois passos acontecem no mesmo tique, então nenhum usuário chega a ser
	 * observado sem capacidades no intervalo.
	 */
	private static function sincronizar_papel_do_admin_de_empresas() {
		$capacidades = array();

		foreach ( self::CAPS_DO_ADMIN_DE_EMPRESAS as $capacidade ) {
			$capacidades[ $capacidade ] = true;
		}

		remove_role( self::PAPEL_ADMIN_EMPRESAS );

		add_role(
			self::PAPEL_ADMIN_EMPRESAS,
			__( 'Administrador de Empresas', 'reconectar-core' ),
			$capacidades
		);
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
	 * Administração técnica × administração operacional
	 * ------------------------------------------------------------------ */

	/**
	 * O usuário administra a *tecnologia* da plataforma?
	 *
	 * Este par de capacidades funciona como portão em quatro travas desta classe:
	 * quem passa aqui enxerga o `/wp-admin`, a barra administrativa, os registros
	 * de todos os vendedores e as listagens sem filtro. Estava repetido nos quatro
	 * lugares, o que tornava difícil responder à pergunta que a especificação do
	 * Administrador de Empresas obriga a responder: *quem*, exatamente, é
	 * administração técnica aqui dentro.
	 *
	 * Reunir a resposta em um método não muda comportamento nenhum — muda o fato
	 * de que a resposta agora tem um nome e um lugar só. O `company_admin` não
	 * tem nenhuma das duas capacidades e, portanto, não passa: ele administra a
	 * operação, não a tecnologia.
	 *
	 * @param int $usuario_id Usuário a avaliar; 0 usa o usuário atual.
	 * @return bool
	 */
	public static function eh_administracao_tecnica( $usuario_id = 0 ) {
		if ( $usuario_id ) {
			return user_can( $usuario_id, 'manage_options' ) || user_can( $usuario_id, 'manage_woocommerce' );
		}

		return current_user_can( 'manage_options' ) || current_user_can( 'manage_woocommerce' );
	}

	/**
	 * O usuário tem o papel de Administrador de Empresas?
	 *
	 * A pergunta é pelo papel, e não pela capacidade, porque quem também a
	 * responde "sim" é o Administrador — que tem `CAP_PAINEL_EMPRESAS` e não deve
	 * ser atingido pela trava de escrita abaixo.
	 *
	 * @param int $usuario_id ID do usuário.
	 * @return bool
	 */
	public static function eh_admin_de_empresas( $usuario_id ) {
		$usuario = get_userdata( $usuario_id );

		return $usuario && in_array( self::PAPEL_ADMIN_EMPRESAS, (array) $usuario->roles, true );
	}

	/**
	 * Nega ao Administrador de Empresas qualquer escrita em produto ou pedido.
	 *
	 * O alcance dele sobre a operação é de **consulta**: ele vê o catálogo, o
	 * estoque, os pedidos e o faturamento dos vendedores que administra, e não
	 * altera nenhum deles — quem administra o produto é o vendedor dono da loja.
	 *
	 * O papel já não traz essas capacidades, então em uma instalação intacta este
	 * filtro nunca decide nada. Ele existe para a instalação que não está intacta:
	 * basta um plugin de terceiro conceder `edit_products` por papel — o Dokan
	 * concede seis capacidades de produto ao `seller` desse jeito — para o alcance
	 * silenciosamente deixar de ser consulta. A garantia precisa estar na
	 * autorização, não na lista de capacidades do papel.
	 *
	 * @param string[] $caps       Capacidades primitivas exigidas.
	 * @param string   $cap        Capacidade consultada.
	 * @param int      $usuario_id Usuário sob avaliação.
	 * @param array    $args       Argumentos; `$args[0]` costuma ser o ID do objeto.
	 * @return string[]
	 */
	public static function negar_escrita_ao_admin_de_empresas( $caps, $cap, $usuario_id, $args ) {
		$monitoradas = array(
			'edit_post',
			'delete_post',
			'publish_post',
			'edit_product',
			'delete_product',
			'publish_products',
			'edit_products',
			'edit_shop_order',
			'delete_shop_order',
		);

		if ( ! in_array( $cap, $monitoradas, true ) ) {
			return $caps;
		}

		if ( ! self::eh_admin_de_empresas( $usuario_id ) ) {
			return $caps;
		}

		// A empresa é justamente o que este ator administra, e o CPT dela responde
		// pelas meta caps genéricas de post. Sem esta exceção, a trava cortaria a
		// própria razão de ser do papel.
		if ( ! empty( $args[0] ) && Reconectar_Empresa::POST_TYPE === get_post_type( (int) $args[0] ) ) {
			return $caps;
		}

		$caps[] = 'do_not_allow';

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

		// Quem administra a tecnologia enxerga tudo — é o papel dele. A consulta
		// usa capacidades fora da lista monitorada, então não há recursão.
		if ( self::eh_administracao_tecnica( $usuario_id ) ) {
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

		if ( self::eh_administracao_tecnica() || ! self::eh_vendedor( $usuario_id ) ) {
			return;
		}

		$consulta->set( 'author', $usuario_id );
	}

	/* ---------------------------------------------------------------------
	 * Comunidade e fóruns
	 * ------------------------------------------------------------------ */

	/**
	 * Capacidades com que o bbPress autoriza escrita.
	 *
	 * Leitura fica de fora de propósito: `spectate` e `read_forum` continuam
	 * livres, para que uma autorização concedida por outro caminho — um papel
	 * novo, uma capacidade a dedo — não perca a leitura por causa desta trava.
	 *
	 * @var string[]
	 */
	const CAPS_DE_ESCRITA_NO_FORUM = array(
		'publish_topics',
		'edit_topics',
		'publish_replies',
		'edit_replies',
		'assign_topic_tags',
		'publish_forums',
		'edit_forums',
	);

	/**
	 * Nega a escrita no fórum a quem não participa da comunidade.
	 *
	 * `bloquear_comunidade()` fecha a porta da frente, e é por onde todo mundo
	 * passa — mas ela não é a única. O bbPress atende ao POST de criação em
	 * `bbp_template_redirect`, pendurado em `template_redirect` com **prioridade
	 * 8** (`bbpress/includes/core/actions.php:50`), enquanto o bloqueio está na
	 * prioridade padrão, **10**. O handler roda antes, e o que ele consulta é a
	 * capacidade primitiva:
	 *
	 *     if ( ! current_user_can( 'publish_topics' ) ) { … return; }
	 *
	 * Medido nesta instalação: `demo-cliente-ana` responde **sim** a
	 * `publish_topics` e a `publish_replies`. O bbPress dá `bbp_participant` a
	 * todo usuário que se registra, cliente inclusive, e esse papel traz as duas.
	 *
	 * Hoje o nonce `bbp-new-topic` ainda barra — o cliente não chega a ver o
	 * formulário que o imprimiria. Esta camada existe porque "o nonce segura" não
	 * é uma regra de autorização: basta um widget, um shortcode ou uma versão
	 * nova do plugin imprimir aquele campo numa página que o cliente possa abrir.
	 * É o mesmo raciocínio de `Reconectar_Cadastro_De_Lojas`, onde quatro portas
	 * independentes exigiram cinco camadas justamente porque nenhuma cobria as
	 * outras.
	 *
	 * `map_meta_cap` alcança capacidade primitiva: `WP_User::has_cap()` chama
	 * `map_meta_cap()` para toda checagem, e para uma cap que não é meta o retorno
	 * é `array( $cap )` — que passa por este filtro como qualquer outro.
	 *
	 * @param string[] $caps       Capacidades primitivas exigidas.
	 * @param string   $cap        Capacidade consultada.
	 * @param int      $usuario_id Usuário sob avaliação.
	 * @return string[]
	 */
	public static function negar_escrita_no_forum( $caps, $cap, $usuario_id ) {
		if ( ! in_array( $cap, self::CAPS_DE_ESCRITA_NO_FORUM, true ) ) {
			return $caps;
		}

		// `user_can()` reentra em `map_meta_cap`, agora com `CAP_COMUNIDADE` —
		// que não está na lista acima e sai pela linha anterior. Não há recursão.
		if ( user_can( $usuario_id, self::CAP_COMUNIDADE ) ) {
			return $caps;
		}

		$caps[] = 'do_not_allow';

		return $caps;
	}

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
	 * O usuário atual pode entrar na comunidade e nos fóruns?
	 *
	 * Existe para que as três superfícies de navegação que oferecem esse link —
	 * o menu, o card da home e o widget do rodapé — decidam pelo mesmo critério.
	 * Enquanto cada uma perguntava por conta própria, elas divergiram: o menu
	 * escondia o item do visitante deslogado e as outras duas o exibiam.
	 *
	 * @return bool
	 */
	public static function pode_participar_da_comunidade() {
		return current_user_can( self::CAP_COMUNIDADE );
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
		if ( self::pode_participar_da_comunidade() ) {
			return $itens;
		}

		$comunidade = get_page_by_path( 'comunidade' );
		$pagina_id  = $comunidade ? (int) $comunidade->ID : 0;

		/*
		 * O item "Fórum" que o provisionamento cria é `custom`, e não `post_type`:
		 * a listagem de perguntas mora no arquivo de `forum`, que não tem post a
		 * que um item de menu possa apontar. Um item custom chega aqui com
		 * `object` valendo 'custom', de modo que a comparação por post type abaixo
		 * não o alcança — e o cliente voltaria a ver no menu um link que devolve
		 * 403, que é exatamente o defeito que este filtro existe para evitar.
		 *
		 * A comparação é pelo caminho da URL, como no widget do rodapé, e pela
		 * mesma razão: sobrevive a alguém reordenar o menu ou trocar o rótulo
		 * pelo painel.
		 */
		$caminho_do_forum = self::caminho_de_url( get_post_type_archive_link( 'forum' ) );

		foreach ( $itens as $indice => $item ) {
			$aponta_para_pagina = $pagina_id
				&& 'post_type' === $item->type
				&& (int) $item->object_id === $pagina_id;

			$aponta_para_forum = in_array( $item->object, array( 'forum', 'topic' ), true );

			$aponta_para_listagem = '' !== $caminho_do_forum
				&& 'custom' === $item->type
				&& self::caminho_de_url( $item->url ) === $caminho_do_forum;

			if ( $aponta_para_pagina || $aponta_para_forum || $aponta_para_listagem ) {
				unset( $itens[ $indice ] );
			}
		}

		return $itens;
	}

	/**
	 * Remove do HTML de um widget os links que levam à comunidade.
	 *
	 * O menu chega ao filtro acima como estrutura de dados, item a item. O widget
	 * "Navegação" do rodapé é HTML livre, digitado no painel, e não passa por
	 * `wp_nav_menu_objects` — por isso as duas superfícies divergiram: o visitante
	 * deslogado não via "Comunidade" no menu e via no rodapé, e o cliente logado
	 * que clicasse ali recebia o 403 de `bloquear_comunidade()`.
	 *
	 * A comparação é pelo caminho da URL, não pelo texto do link nem pelo formato
	 * do HTML. É a única forma que sobrevive a uma edição pelo painel, e edição
	 * pelo painel é o caso esperado num site de edital, que troca de mãos: um
	 * `str_replace()` do trecho que o provisionamento escreve pararia de funcionar
	 * no dia em que alguém reordenasse a lista ou trocasse o rótulo.
	 *
	 * @param string $conteudo HTML do widget.
	 * @return string HTML sem os links bloqueados.
	 */
	public static function ocultar_links_da_comunidade_em_widget( $conteudo ) {
		if ( ! is_string( $conteudo ) || false === stripos( $conteudo, '<a' ) ) {
			return $conteudo;
		}

		if ( self::pode_participar_da_comunidade() ) {
			return $conteudo;
		}

		// `DOMDocument` vem de uma extensão que pode não estar compilada. Sem ela
		// o link volta a aparecer, que é exatamente o estado anterior a este
		// filtro — degradar para o comportamento antigo é melhor que derrubar o
		// rodapé inteiro com um fatal.
		if ( ! class_exists( 'DOMDocument' ) ) {
			return $conteudo;
		}

		$comunidade = get_page_by_path( 'comunidade' );

		if ( ! $comunidade ) {
			return $conteudo;
		}

		$alvo = self::caminho_de_url( get_permalink( $comunidade ) );

		if ( '' === $alvo ) {
			return $conteudo;
		}

		$documento = new DOMDocument();

		// O `<meta charset>` não é enfeite: sem ele o `loadHTML()` assume
		// ISO-8859-1 e "Transparência" volta da serialização como mojibake. A
		// receita antiga para isso — `mb_convert_encoding()` com `HTML-ENTITIES` —
		// está descontinuada desde o PHP 8.2, e o container roda 8.2.
		//
		// O `libxml_use_internal_errors()` silencia os avisos que o parser emite
		// diante de tags que ele não conhece. Vale notar o limite disso: o parser
		// também *normaliza* o que estiver mal fechado, e num HTML inválido — uma
		// âncora sem `</a>`, por exemplo — o item seguinte pode acabar dentro do
		// que será removido. O widget que o provisionamento escreve é bem formado;
		// quem editar o dele à mão precisa fechar as tags.
		$erros_internos = libxml_use_internal_errors( true );

		$carregou = $documento->loadHTML(
			'<html><head><meta http-equiv="Content-Type" content="text/html; charset=utf-8"></head><body>' . $conteudo . '</body></html>'
		);

		libxml_clear_errors();
		libxml_use_internal_errors( $erros_internos );

		if ( ! $carregou ) {
			return $conteudo;
		}

		$xpath = new DOMXPath( $documento );
		$links = $xpath->query( '//a[@href]' );

		if ( ! $links instanceof DOMNodeList || 0 === $links->length ) {
			return $conteudo;
		}

		$removeu = false;

		// A lista devolvida pelo XPath é estática, ao contrário da de
		// `getElementsByTagName()`: dá para remover nós durante a iteração sem
		// embaralhar o que ainda falta percorrer.
		foreach ( $links as $link ) {
			if ( self::caminho_de_url( $link->getAttribute( 'href' ) ) !== $alvo ) {
				continue;
			}

			// Some o item inteiro, não só a âncora: deixar o `<li>` para trás
			// produziria um marcador solto na coluna do rodapé.
			$remover = $link;
			$pai     = $link->parentNode;

			while ( $pai instanceof DOMElement && 'body' !== strtolower( $pai->nodeName ) ) {
				if ( 'li' === strtolower( $pai->nodeName ) ) {
					$remover = $pai;
					break;
				}

				$pai = $pai->parentNode;
			}

			if ( $remover->parentNode ) {
				$remover->parentNode->removeChild( $remover );
				$removeu = true;
			}
		}

		if ( ! $removeu ) {
			return $conteudo;
		}

		$corpo = $documento->getElementsByTagName( 'body' )->item( 0 );

		if ( ! $corpo ) {
			return $conteudo;
		}

		// Serializa filho a filho para não devolver o `<html><body>` que só existe
		// porque foi preciso dar ao parser um documento completo.
		$html = '';

		foreach ( $corpo->childNodes as $filho ) {
			$html .= $documento->saveHTML( $filho );
		}

		return $html;
	}

	/**
	 * Reduz uma URL ao caminho, em forma comparável.
	 *
	 * Compara-se o caminho, e não a URL inteira, porque os dois lados chegam em
	 * formatos diferentes sem que isso signifique destinos diferentes: o
	 * permalink vem absoluto, e o link do widget pode ter sido digitado relativo,
	 * com `?` de rastreio ou com âncora.
	 *
	 * @param string $url URL absoluta ou relativa.
	 * @return string Caminho sem a barra final, ou string vazia se não houver.
	 */
	private static function caminho_de_url( $url ) {
		if ( ! is_string( $url ) || '' === $url ) {
			return '';
		}

		$caminho = wp_parse_url( $url, PHP_URL_PATH );

		if ( ! is_string( $caminho ) || '' === $caminho ) {
			return '';
		}

		return untrailingslashit( $caminho );
	}

	/* ---------------------------------------------------------------------
	 * Área administrativa
	 * ------------------------------------------------------------------ */

	/**
	 * Mantém clientes e vendedores fora do `/wp-admin`.
	 *
	 * O vendedor trabalha no painel do Dokan, no front-end; o Administrador de
	 * Empresas, no painel de empresas; e o cliente, na área "Minha conta".
	 * Nenhum dos três tem o que fazer no painel do WordPress, e a especificação
	 * lista a área administrativa como exclusiva do Administrador.
	 */
	public static function bloquear_area_administrativa() {
		// `admin-ajax.php` mora dentro de `/wp-admin` e atende requisições do
		// front-end — inclusive as do carrinho e as do painel do Dokan. Bloqueá-lo
		// quebraria a loja para as pessoas que este método protege.
		if ( wp_doing_ajax() ) {
			return;
		}

		/*
		 * `admin-post.php` é irmão do `admin-ajax.php` e a exceção existe pela
		 * mesma razão, só que descoberta mais tarde: ele é a rota padrão do
		 * WordPress para um `<form method="post">` de front-end, e é para lá que o
		 * voto do fórum e a marcação de melhor resposta postam.
		 *
		 * Medido antes da correção: o vendedor que clicasse em votar recebia
		 * 302 para `/dashboard/` e o voto não acontecia — sem erro nenhum na tela,
		 * porque o redirecionamento devolve o painel dele, que é uma página
		 * plausível. O cliente recebia 302 para `/my-account/` pelo mesmo caminho,
		 * o que dava a impressão de que a trava do endpoint estava funcionando
		 * quando na verdade ela nunca era alcançada.
		 *
		 * Liberar aqui não abre o painel: `admin-post.php` só executa o que
		 * estiver pendurado em `admin_post_*` e morre com 400 quando a ação não
		 * existe. As travas do voto seguem sendo o nonce e a capacidade, dentro do
		 * próprio handler.
		 */
		if ( isset( $GLOBALS['pagenow'] ) && 'admin-post.php' === $GLOBALS['pagenow'] ) {
			return;
		}

		if ( self::eh_administracao_tecnica() ) {
			return;
		}

		if ( ! is_user_logged_in() ) {
			return;
		}

		wp_safe_redirect( self::destino_fora_do_painel() );
		exit;
	}

	/**
	 * Para onde mandar quem foi barrado na porta do `/wp-admin`.
	 *
	 * A ordem das três tentativas é a da especificidade: cada ator tem uma área
	 * própria de trabalho, e devolvê-lo a ela é mais útil que um 403. O
	 * `home_url()` do fim é o caso em que nenhum dos plugins está de pé.
	 *
	 * @return string URL absoluta.
	 */
	private static function destino_fora_do_painel() {
		$usuario_id = get_current_user_id();

		if ( user_can( $usuario_id, self::CAP_PAINEL_EMPRESAS ) ) {
			$painel = Reconectar_Painel_Empresas::url();

			if ( '' !== $painel ) {
				return $painel;
			}
		}

		if ( self::eh_vendedor( $usuario_id ) && function_exists( 'dokan_get_navigation_url' ) ) {
			return dokan_get_navigation_url();
		}

		return function_exists( 'wc_get_page_permalink' )
			? wc_get_page_permalink( 'myaccount' )
			: home_url( '/' );
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

		if ( self::eh_administracao_tecnica() ) {
			return $exibir;
		}

		return false;
	}
}

/**
 * O usuário atual pode entrar na comunidade e nos fóruns?
 *
 * Fachada para o tema, que não deve conhecer a classe nem o nome da capacidade.
 * Existe por um motivo concreto, e não por estilo: se o tema perguntasse direto
 * por `current_user_can( 'reconectar_participar_comunidade' )` e alguém
 * desativasse este plugin, ninguém teria a capacidade e o link sumiria para
 * todos — inclusive para o administrador. Seria o pior resultado possível, já
 * que sem o plugin também não existe bloqueio nenhum: a área estaria aberta e
 * escondida ao mesmo tempo.
 *
 * Por isso o tema consulta esta função sob `function_exists()` e, quando ela não
 * existe, exibe o link. Sem plugin, sem restrição.
 *
 * @return bool
 */
function reconectar_pode_participar_da_comunidade() {
	return Reconectar_Permissoes::pode_participar_da_comunidade();
}
