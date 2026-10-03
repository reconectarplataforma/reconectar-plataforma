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
	const PAPEIS_DA_COMUNIDADE = array( 'administrator', 'seller', 'company_admin', 'content_moderator' );

	/**
	 * Papel do Administrador.
	 *
	 * A chave continua sendo `company_admin`, e isso é deliberado: ela está
	 * gravada em `wp_usermeta` de todas as contas que já têm o papel, e renomear
	 * chave de papel exigiria migração versionada para não deixar essas contas
	 * sem permissão nenhuma — o defeito silencioso que o `CLAUDE.md` registra.
	 * O que mudou foi o rótulo: este ator deixou de ser apenas "Administrador de
	 * Empresas" e passou a ser o **Administrador** da plataforma, que cadastra
	 * lojas e empresas, modera conteúdo e configura as contas das lojas.
	 *
	 * O que ele continua não podendo é o que define o papel: administrar a
	 * *tecnologia*. Sem `manage_options`, sem `manage_woocommerce`, sem
	 * `dokandar` e sem nenhuma `CAPS_DE_DESENVOLVIMENTO`.
	 */
	const PAPEL_ADMIN_EMPRESAS = 'company_admin';

	/**
	 * Papel do Moderador de Conteúdo.
	 *
	 * Cuida do que a plataforma publica — conteúdo da comunidade, campanhas na
	 * home, menus de navegação — e do fórum. Não toca em loja, em empresa, em
	 * produto, em pedido nem em conta de usuário.
	 *
	 * A chave fica em inglês por simetria com `company_admin`, que estabeleceu a
	 * convenção para chave de papel gravada no banco. O rótulo é em português,
	 * como todo o resto.
	 */
	const PAPEL_MODERADOR = 'content_moderator';

	/**
	 * Autoriza a entrada no `/wp-admin` sem conceder administração técnica.
	 *
	 * Existe por causa de um nó que só aparece quando se lê as duas travas
	 * juntas. `restringir_gestao_da_tecnologia()` barra a instalação de plugins
	 * **acrescentando `manage_options`** ao conjunto exigido, e
	 * `eh_administracao_tecnica()` — o portão do `/wp-admin` — é exatamente
	 * `manage_options || manage_woocommerce`. Dar `manage_options` a um perfil
	 * restrito para que ele entre no painel devolveria a ele, pela mesma linha,
	 * a instalação de plugins.
	 *
	 * `manage_woocommerce` abriria a porta sem quebrar aquela trava, mas tem
	 * outro preço: faria aparecer os menus do WooCommerce e do Dokan, cujas
	 * listagens **não têm escopo por empresa**. O Administrador é limitado às
	 * empresas dele dentro do `/painel-empresas/`; no `/wp-admin` ele veria
	 * produtos e pedidos de todas, desfazendo em silêncio o isolamento.
	 *
	 * Daí uma capacidade própria, consultada **só** nas duas travas de interface
	 * administrativa (`bloquear_area_administrativa()` e
	 * `ocultar_barra_administrativa()`). `eh_administracao_tecnica()` não a
	 * conhece: nos outros dois lugares que a consultam — o isolamento entre
	 * vendedores — a resposta certa para estes perfis continua sendo "não".
	 */
	const CAP_ADMIN_WP = 'reconectar_acessar_wp_admin';

	/**
	 * Portão da rota `/painel-empresas/`.
	 */
	const CAP_PAINEL_EMPRESAS = 'reconectar_acessar_painel_empresas';

	/**
	 * Criar, editar, ativar e desativar empresas.
	 */
	const CAP_GERIR_EMPRESAS = 'reconectar_gerir_empresas';

	/**
	 * Criar, editar, ativar e desativar lojas.
	 */
	const CAP_GERIR_LOJAS = 'reconectar_gerir_lojas';

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
	 * Criar, editar, publicar e despublicar campanhas da home.
	 *
	 * Uma primitiva só para o CPT inteiro, no molde de `CAP_GERIR_EMPRESAS`: o
	 * `register_post_type()` da campanha aponta para ela todas as primitivas de
	 * post e deixa as meta caps no padrão. O comentário longo em
	 * `class-reconectar-empresa.php:172` registra por que apontar meta cap ali é
	 * defeito, e não redundância — vale igual aqui.
	 */
	const CAP_GERIR_CAMPANHAS = 'reconectar_gerir_campanhas';

	/**
	 * Criar, editar, publicar e encerrar enquetes da comunidade.
	 *
	 * Mesma forma de `CAP_GERIR_CAMPANHAS`, e pela mesma razão. Antes dela o
	 * Moderador de Conteúdo administrava enquete **por acidente**: o post type
	 * nunca declarou `capability_type`, caiu no padrão `post`, e as primitivas de
	 * post que `CAPS_DE_CONTEUDO` dá para post e página abriam a tela de tabela.
	 * O Administrador de empresas, que tem as mesmas primitivas, esbarrava em
	 * `negar_escrita_ao_admin_de_empresas()` e via a tela sem conseguir salvar.
	 *
	 * Uma primitiva própria troca os dois acidentes por uma decisão.
	 */
	const CAP_GERIR_ENQUETES = 'reconectar_gerir_enquetes';

	/**
	 * Criar, editar, mover e excluir páginas da Incubadora.
	 *
	 * Mesma forma de `CAP_GERIR_ENQUETES`: o post type aponta para ela todas as
	 * primitivas e deixa as meta caps no padrão. Uma só capacidade, sem distinção
	 * entre "minhas" e "dos outros", porque a Incubadora é uma wiki
	 * colaborativa — quem a detém edita qualquer página, e `post_author` fica
	 * como crédito, não como posse. Loja e Usuário Comum só leem.
	 */
	const CAP_GERIR_INCUBADORA = 'reconectar_gerir_incubadora';

	/**
	 * Todas as capacidades ligadas à administração de empresas.
	 *
	 * @var string[]
	 */
	const CAPS_DE_EMPRESA = array(
		self::CAP_PAINEL_EMPRESAS,
		self::CAP_GERIR_EMPRESAS,
		self::CAP_GERIR_LOJAS,
		self::CAP_VER_OPERACAO,
		self::CAP_TODAS_AS_EMPRESAS,
	);

	/**
	 * A fatia de *operação* do papel `company_admin`.
	 *
	 * Deixou de ser a lista completa do papel: hoje ela é um dos três blocos que
	 * `capacidades_do_administrador()` soma — este, o do Moderador de Conteúdo e
	 * o `CAPS_DE_USUARIOS`. O nome sobrevive porque é ele que a documentação e o
	 * diário citam.
	 *
	 * O que continua **fora** importa tanto quanto o que está dentro:
	 * `manage_options`, `manage_woocommerce`, `dokandar` e qualquer
	 * `CAPS_DE_DESENVOLVIMENTO`. As duas primeiras abririam o `/wp-admin` inteiro
	 * com os menus do WooCommerce e do Dokan, cujas listagens não têm escopo por
	 * empresa — o acesso ao painel vem de `CAP_ADMIN_WP`, justamente para não
	 * passar por elas. `dokandar` é a capacidade que, para o Dokan, *define* que
	 * alguém é vendedor (`dokan_is_user_seller()` é literalmente
	 * `user_can( $id, 'dokandar' )`): concedê-la faria este ator herdar em
	 * silêncio tudo que o plugin liberar por ela daqui em diante.
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
		self::CAP_GERIR_LOJAS,
		self::CAP_VER_OPERACAO,
	);

	/**
	 * O que o Moderador de Conteúdo publica e modera.
	 *
	 * São capacidades nativas do WordPress de propósito: o que se modera aqui é
	 * post, página e comentário do próprio núcleo, e inventar primitivas
	 * autorais para eles só criaria um segundo vocabulário para a mesma coisa.
	 * Produto e pedido ficam de fora — quem administra o produto é a loja dona
	 * dele, e `negar_escrita_ao_admin_de_empresas()` mantém a negação mesmo se
	 * um plugin de terceiro conceder `edit_products` por papel.
	 *
	 * `edit_theme_options` merece a nota que o resto da lista não precisa: ela é
	 * a **única** capacidade que o WordPress oferece para editar menus de
	 * navegação, e vem grudada ao Customizer e aos widgets. Não há granularidade
	 * menor no núcleo — ou o moderador cria menus e alcança essas duas telas, ou
	 * não cria menus. A amplitude é do WordPress, não uma escolha nossa; o que
	 * está ao nosso alcance é impedir que dali se chegue a instalar tema, e é o
	 * que `CAPS_DE_DESENVOLVIMENTO` faz.
	 *
	 * A consequência visível é **Aparência → Temas abrindo com 200**, e ela não é
	 * falha de trava: `wp-admin/themes.php` exige `switch_themes` *ou*
	 * `edit_theme_options`, e a segunda é justamente a que os menus pedem. A tela
	 * fica só de leitura, e isso foi medido, não deduzido — o JSON que o núcleo
	 * imprime nela traz `"activate":null`, `"delete":null` e `"autoupdate":null`
	 * para cada tema, `theme-install.php` cai no `wp_die()` de negação e
	 * `theme-editor.php` responde 403.
	 *
	 * Fechá-la à força custaria mais do que resolve: seria um desvio por tela em
	 * `admin_init`, e `nav-menus.php` — a tela que o perfil existe para usar —
	 * depende exatamente da mesma capacidade. A trava que vale é a da lista de
	 * capacidades, não a da URL.
	 *
	 * @var string[]
	 */
	const CAPS_DE_CONTEUDO = array(
		'upload_files',
		'moderate_comments',
		'edit_posts',
		'edit_others_posts',
		'edit_published_posts',
		'publish_posts',
		'delete_posts',
		'delete_others_posts',
		'delete_published_posts',
		'read_private_posts',
		'edit_pages',
		'edit_others_pages',
		'edit_published_pages',
		'publish_pages',
		'delete_pages',
		'delete_others_pages',
		'delete_published_pages',
		'read_private_pages',
		'edit_theme_options',
		self::CAP_GERIR_CAMPANHAS,
		self::CAP_GERIR_ENQUETES,
		self::CAP_GERIR_INCUBADORA,
	);

	/**
	 * Gestão das contas de usuário, exclusiva do Administrador.
	 *
	 * "Configura os usuários das lojas" exige `edit_users`, e em single-site
	 * `edit_users` alcança **qualquer** conta, inclusive a do Super
	 * Administrador: quem a tem pode trocar a senha de um `administrator` e
	 * entrar com ela. `promote_users`, no mesmo golpe, permite promover a si
	 * mesmo. As duas juntas transformariam este papel no topo em dois cliques, e
	 * a promessa de não dar acesso de desenvolvedor viraria decoração.
	 *
	 * Quem fecha essa porta é `negar_gestao_de_usuarios_superiores()`, em
	 * `map_meta_cap`. Sem aquele filtro, esta lista é insegura — e a falha seria
	 * silenciosa, porque a tela funciona.
	 *
	 * @var string[]
	 */
	const CAPS_DE_USUARIOS = array(
		'list_users',
		'create_users',
		'edit_users',
		'delete_users',
		'promote_users',
	);

	/**
	 * Capacidades que já existiram e precisam sair dos papéis.
	 *
	 * Renomear uma capacidade não migra nada: o nome antigo continua gravado em
	 * `wp_user_roles` até que alguém o remova. Para o `company_admin` isso não é
	 * problema — `sincronizar_papel_do_admin_de_empresas()` recria o papel do zero
	 * —, mas o `administrator` recebe as capacidades por `add_cap()` e só perde o
	 * que for explicitamente removido. Sem esta lista, `reconectar_gerir_vendedores`
	 * ficaria no banco para sempre, concedida a quem instalou a plataforma.
	 *
	 * A lista é permanente, não transitória: é ela que faz uma instalação
	 * provisionada antes da renomeação migrar sozinha na próxima sincronização.
	 *
	 * @var string[]
	 */
	const CAPS_LEGADAS = array(
		// Renomeada para `reconectar_gerir_lojas` quando o vocabulário da
		// plataforma passou a chamar de Loja o que o código chamava de Vendedor.
		'reconectar_gerir_vendedores',
	);

	/**
	 * Capacidades de desenvolvimento, restritas ao Super Administrador.
	 *
	 * A especificação é taxativa quanto a plugins: apenas o Administrador
	 * instala, ativa, desativa, atualiza, configura ou remove. A lista nasceu
	 * com eles e precisou crescer quando o Moderador de Conteúdo ganhou
	 * `edit_theme_options` — a única capacidade do núcleo que edita menus.
	 * `edit_theme_options` abre o menu **Aparência**, e dali se chega a instalar
	 * e editar tema: barrar plugin e deixar tema livre seria fechar uma porta e
	 * deixar a do lado aberta, já que um tema também executa PHP arbitrário.
	 *
	 * `edit_files` e `update_core` entram pela mesma razão: as duas editam ou
	 * substituem código da instalação.
	 *
	 * @var string[]
	 */
	const CAPS_DE_DESENVOLVIMENTO = array(
		'install_plugins',
		'activate_plugins',
		'update_plugins',
		'delete_plugins',
		'edit_plugins',
		'upload_plugins',
		'switch_themes',
		'install_themes',
		'update_themes',
		'delete_themes',
		'edit_themes',
		'upload_themes',
		'edit_files',
		'update_core',
	);

	/**
	 * Versão do conjunto de capacidades aplicado aos papéis.
	 *
	 * Mexer em papéis grava no banco, então isso não pode acontecer a cada
	 * carregamento de página. A opção guarda a versão já aplicada e a
	 * sincronização só roda quando este número muda — incremente-o ao alterar
	 * `sincronizar_capacidades()`.
	 */
	const VERSAO_CAPACIDADES = 7;

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
		add_filter( 'map_meta_cap', array( __CLASS__, 'restringir_gestao_da_tecnologia' ), 10, 2 );
		add_filter( 'map_meta_cap', array( __CLASS__, 'negar_gestao_de_usuarios_superiores' ), 10, 4 );
		add_filter( 'map_meta_cap', array( __CLASS__, 'negar_escrita_ao_admin_de_empresas' ), 10, 4 );
		add_filter( 'map_meta_cap', array( __CLASS__, 'negar_escrita_no_forum' ), 10, 3 );
		// Prioridade 11: revisa o que `bbp_map_meta_caps` decide em 10.
		add_filter( 'map_meta_cap', array( __CLASS__, 'restaurar_gestao_de_foruns' ), 11, 3 );
		add_action( 'set_user_role', array( __CLASS__, 'sincronizar_papel_no_forum' ), 10, 2 );
		// Prioridade 20: depois de `bbp_user_register`, em 10 — ver o método.
		add_action( 'user_register', array( __CLASS__, 'sincronizar_papel_no_forum_ao_cadastrar' ), 20 );
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
		self::sincronizar_papel_do_moderador();
		self::renomear_papel( 'administrator', __( 'Super Administrador', 'reconectar-core' ) );

		$papeis = wp_roles();

		foreach ( $papeis->get_names() as $papel => $nome ) {
			$objeto = $papeis->get_role( $papel );

			if ( ! $objeto ) {
				continue;
			}

			// Antes de qualquer concessão: o que já não existe sai de todo papel,
			// inclusive do `administrator`. Ver `CAPS_LEGADAS`.
			foreach ( self::CAPS_LEGADAS as $capacidade ) {
				$objeto->remove_cap( $capacidade );
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
				// O Super Administrador acumula os dois lados: administra a
				// tecnologia e, por consequência, também a operação. Recebe
				// inclusive `CAP_TODAS_AS_EMPRESAS` — sem ela, o painel de
				// empresas ficaria vazio para quem instalou a plataforma.
				foreach ( self::CAPS_DE_EMPRESA as $capacidade ) {
					$objeto->add_cap( $capacidade );
				}

				// Ele já entra no `/wp-admin` por `manage_options`, mas as duas
				// abaixo não são decoração: `CAP_ADMIN_WP` mantém a trava com uma
				// resposta coerente caso alguém um dia retire `manage_options` de
				// um administrador a dedo, e as três de CPT são primitivas — sem
				// elas os menus Campanhas e Enquetes e a edição da Incubadora
				// somem para quem instalou a plataforma, exatamente como
				// aconteceria com as empresas.
				$objeto->add_cap( self::CAP_ADMIN_WP );
				$objeto->add_cap( self::CAP_GERIR_CAMPANHAS );
				$objeto->add_cap( self::CAP_GERIR_ENQUETES );
				$objeto->add_cap( self::CAP_GERIR_INCUBADORA );

				continue;
			}

			if ( self::PAPEL_ADMIN_EMPRESAS !== $papel ) {
				foreach ( self::CAPS_DE_EMPRESA as $capacidade ) {
					$objeto->remove_cap( $capacidade );
				}
			}

			// Os dois papéis autorais acabaram de nascer de `add_role()`, já com
			// a lista completa. Para qualquer outro, estas quatro não fazem
			// sentido nenhum — e uma concessão feita por engano sai aqui.
			if ( self::PAPEL_ADMIN_EMPRESAS !== $papel && self::PAPEL_MODERADOR !== $papel ) {
				$objeto->remove_cap( self::CAP_ADMIN_WP );
				$objeto->remove_cap( self::CAP_GERIR_CAMPANHAS );
				$objeto->remove_cap( self::CAP_GERIR_ENQUETES );
				$objeto->remove_cap( self::CAP_GERIR_INCUBADORA );
			}

			foreach ( self::CAPS_DE_DESENVOLVIMENTO as $capacidade ) {
				$objeto->remove_cap( $capacidade );
			}
		}

		update_option( self::OPCAO_VERSAO, self::VERSAO_CAPACIDADES );
	}

	/**
	 * Cria — ou recria — o papel do Administrador.
	 *
	 * `remove_role()` antes de `add_role()` não é redundância com o `add_role()`
	 * sozinho, que é inerte quando o papel já existe: sem a remoção, uma
	 * capacidade retirada de `capacidades_do_administrador()` continuaria gravada
	 * no banco para sempre. Como a lista é justamente o registro do que este ator
	 * *não* pode, ela precisa ser a verdade — e não o teto histórico.
	 *
	 * Os dois passos acontecem no mesmo tique, então nenhum usuário chega a ser
	 * observado sem capacidades no intervalo.
	 */
	private static function sincronizar_papel_do_admin_de_empresas() {
		remove_role( self::PAPEL_ADMIN_EMPRESAS );

		add_role(
			self::PAPEL_ADMIN_EMPRESAS,
			__( 'Administrador', 'reconectar-core' ),
			self::mapa_de_capacidades( self::capacidades_do_administrador() )
		);
	}

	/**
	 * Cria — ou recria — o papel do Moderador de Conteúdo.
	 *
	 * Mesmo molde do papel acima, e pela mesma razão: recriar do zero é o que
	 * faz a lista de constantes ser a verdade, e não o teto histórico.
	 */
	private static function sincronizar_papel_do_moderador() {
		remove_role( self::PAPEL_MODERADOR );

		add_role(
			self::PAPEL_MODERADOR,
			__( 'Moderador de Conteúdo', 'reconectar-core' ),
			self::mapa_de_capacidades( self::capacidades_do_moderador() )
		);
	}

	/**
	 * As capacidades do Moderador de Conteúdo.
	 *
	 * É método, e não constante, porque soma três listas — e expressão de
	 * constante em PHP não aceita `array_merge()` nem espalhamento. A soma fica
	 * aqui uma vez só, em vez de virar uma quarta lista literal que precisaria
	 * ser mantida em paralelo às outras três.
	 *
	 * @return string[]
	 */
	private static function capacidades_do_moderador() {
		return array_merge(
			array( 'read', self::CAP_COMUNIDADE, self::CAP_ADMIN_WP ),
			self::CAPS_DE_ESCRITA_NO_FORUM,
			self::CAPS_DE_CONTEUDO
		);
	}

	/**
	 * As capacidades do Administrador.
	 *
	 * Tudo do Moderador de Conteúdo, mais a operação e a gestão de contas. A
	 * herança é deliberada e está na especificação: quem administra a plataforma
	 * também modera o que ela publica.
	 *
	 * @return string[]
	 */
	private static function capacidades_do_administrador() {
		return array_merge(
			self::capacidades_do_moderador(),
			self::CAPS_DO_ADMIN_DE_EMPRESAS,
			self::CAPS_DE_USUARIOS
		);
	}

	/**
	 * Converte a lista de capacidades no mapa que `add_role()` espera.
	 *
	 * `array_unique()` não é zelo: as listas se sobrepõem de propósito — `read` e
	 * `CAP_COMUNIDADE` aparecem no Moderador e em `CAPS_DO_ADMIN_DE_EMPRESAS` —
	 * e uma chave repetida no mapa não faria mal, mas a duplicata escondida
	 * atrapalharia a conferência por `wp user list-caps`.
	 *
	 * @param string[] $capacidades Lista de capacidades.
	 * @return array<string,bool>
	 */
	private static function mapa_de_capacidades( array $capacidades ) {
		$mapa = array();

		foreach ( array_unique( $capacidades ) as $capacidade ) {
			$mapa[ $capacidade ] = true;
		}

		return $mapa;
	}

	/**
	 * Troca o rótulo de um papel sem tocar nas capacidades dele.
	 *
	 * O caminho óbvio seria `remove_role()` + `add_role()`, como fazem os dois
	 * papéis autorais acima — e é justamente o que não se pode fazer com o
	 * `administrator`. Ali a remoção é irreversível se algo falhar entre as duas
	 * chamadas, e o que ficaria para trás é uma instalação sem nenhum
	 * administrador: ninguém para recriar o papel, nem pelo painel.
	 *
	 * `WP_Roles` guarda tudo numa opção só (`$role_key`), então a escrita
	 * reaproveita a cópia em memória que o próprio núcleo usa em `add_cap()` e
	 * altera apenas o campo `name`. `role_names` é atualizado junto porque é dele
	 * que `get_names()` lê no resto da requisição.
	 *
	 * @param string $papel Chave do papel.
	 * @param string $nome  Rótulo novo.
	 */
	private static function renomear_papel( $papel, $nome ) {
		$papeis = wp_roles();

		if ( ! isset( $papeis->roles[ $papel ] ) || $nome === $papeis->roles[ $papel ]['name'] ) {
			return;
		}

		$papeis->roles[ $papel ]['name'] = $nome;
		$papeis->role_names[ $papel ]    = $nome;

		update_option( $papeis->role_key, $papeis->roles );
	}

	/**
	 * Nega plugins, temas e edição de arquivo a quem não for Super Administrador.
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
	public static function restringir_gestao_da_tecnologia( $caps, $cap ) {
		if ( in_array( $cap, self::CAPS_DE_DESENVOLVIMENTO, true ) && ! in_array( 'manage_options', $caps, true ) ) {
			// `manage_options` é a capacidade que, na prática, distingue o Super
			// Administrador dos demais papéis desta instalação. Exigi-la junto
			// preserva a decisão original quando ela já for restritiva e a
			// endurece quando não for.
			//
			// É também a razão de `CAP_ADMIN_WP` existir em vez de simplesmente
			// dar `manage_options` aos perfis novos: seria esta linha, e só ela,
			// devolvendo a eles a instalação de plugins.
			$caps[] = 'manage_options';
		}

		return $caps;
	}

	/**
	 * Impede que a gestão de contas alcance uma conta mais poderosa que a sua.
	 *
	 * Em single-site o WordPress não gradua `edit_users`: quem a tem edita
	 * **qualquer** conta, inclusive a de um `administrator` — e trocar a senha de
	 * um administrador é entrar com ela. `promote_users`, no mesmo golpe, deixa
	 * promover a si mesmo. O Administrador precisa das duas para configurar as
	 * contas das lojas, que é o que a especificação pede, e sem esta trava as
	 * duas juntas o levariam ao topo em dois cliques.
	 *
	 * São duas regras:
	 *
	 * 1. não se mexe em quem tem `manage_options`, salvo se você também tiver;
	 * 2. não se promove alguém a um papel que traga capacidade que você não tem —
	 *    o que cobre `administrator` e qualquer papel que alguém crie por cima.
	 *
	 * A segunda é a que sobrevive ao futuro: a primeira sozinha seria burlada por
	 * um papel novo com `install_plugins` e sem `manage_options`.
	 *
	 * @param string[] $caps       Capacidades primitivas exigidas.
	 * @param string   $cap        Capacidade consultada.
	 * @param int      $usuario_id Usuário que age.
	 * @param array    $args       `$args[0]` é o usuário alvo.
	 * @return string[]
	 */
	public static function negar_gestao_de_usuarios_superiores( $caps, $cap, $usuario_id, $args ) {
		if ( ! in_array( $cap, array( 'edit_user', 'delete_user', 'promote_user' ), true ) ) {
			return $caps;
		}

		// Quem administra a tecnologia não é limitado por esta trava — e a
		// checagem é por `manage_options`, não pelo papel, porque papel é rótulo.
		if ( user_can( $usuario_id, 'manage_options' ) ) {
			return $caps;
		}

		$alvo = empty( $args[0] ) ? 0 : (int) $args[0];

		if ( $alvo && $alvo !== $usuario_id && user_can( $alvo, 'manage_options' ) ) {
			$caps[] = 'do_not_allow';

			return $caps;
		}

		if ( 'promote_user' !== $cap ) {
			return $caps;
		}

		/*
		 * `promote_user` não diz para qual papel: o núcleo o consulta sempre como
		 * `current_user_can( 'promote_user', $id )`, sem destino. Ele precisa ser
		 * procurado, e em três lugares — a omissão de qualquer um deixa um
		 * caminho de promoção sem regra nenhuma:
		 *
		 * - `$args[1]`, quando quem pergunta informa o destino. O núcleo nunca o
		 *   faz, mas código autoral e teste podem, e aceitar aqui custa uma linha.
		 * - `role`, o campo de `user-edit.php`.
		 * - `new_role`, o da ação em massa de `users.php` (`wp-admin/users.php:123`)
		 *   — chave **diferente**, e é o caminho que promove vários de uma vez.
		 *
		 * A regra é conservadora de propósito: qualquer capacidade do papel de
		 * destino que o ator não tenha reprova a promoção inteira. Isso cobre
		 * `administrator` e também qualquer papel que um plugin crie depois.
		 */
		$destino = '';

		if ( isset( $args[1] ) && is_string( $args[1] ) ) {
			$destino = sanitize_key( $args[1] );
		} elseif ( isset( $_REQUEST['role'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$destino = sanitize_key( wp_unslash( $_REQUEST['role'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		} elseif ( isset( $_REQUEST['new_role'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$destino = sanitize_key( wp_unslash( $_REQUEST['new_role'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}

		if ( '' === $destino ) {
			return $caps;
		}

		$papel = wp_roles()->get_role( $destino );

		if ( ! $papel ) {
			return $caps;
		}

		foreach ( $papel->capabilities as $capacidade => $concedida ) {
			if ( $concedida && ! user_can( $usuario_id, $capacidade ) ) {
				$caps[] = 'do_not_allow';

				return $caps;
			}
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
	 * Ele **entra** no `/wp-admin` assim mesmo, e sem passar por aqui: as duas
	 * travas de interface administrativa aceitam `CAP_ADMIN_WP` como alternativa.
	 * As outras duas — o isolamento entre vendedores — continuam consultando só
	 * este método, porque ali a resposta certa para os perfis novos permanece
	 * "não": eles não devem ver os registros de todos os vendedores.
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
	 * **A exceção é uma allowlist, e isso mudou.** As três primeiras monitoradas
	 * (`edit_post`, `delete_post`, `publish_post`) são as meta caps genéricas de
	 * *qualquer* post type, e enquanto este ator não escrevia conteúdo bastava
	 * abrir uma fresta para o CPT de empresa. Quando ele ganhou a moderação de
	 * conteúdo, a mesma linha passou a negar post, página, campanha e fórum — o
	 * sintoma pior deste repositório, o da tela que funciona e da ação que
	 * silenciosamente não acontece. Post type que ele administra entra na lista;
	 * produto e pedido continuam de fora, que é a razão de a trava existir.
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

		if ( ! empty( $args[0] ) && in_array( get_post_type( (int) $args[0] ), self::post_types_que_o_administrador_escreve(), true ) ) {
			return $caps;
		}

		$caps[] = 'do_not_allow';

		return $caps;
	}

	/**
	 * Os post types que o Administrador pode escrever.
	 *
	 * `forum`, `topic` e `reply` são as chaves do bbPress, escritas literais de
	 * propósito: as constantes dele só existem depois que o plugin carrega, e
	 * `map_meta_cap` é consultado cedo demais para depender disso.
	 *
	 * @return string[]
	 */
	private static function post_types_que_o_administrador_escreve() {
		return array(
			Reconectar_Empresa::POST_TYPE,
			Reconectar_Campanha::POST_TYPE,
			Reconectar_Proposta_Votacao::POST_TYPE,
			Reconectar_Incubadora::POST_TYPE,
			'post',
			'page',
			'forum',
			'topic',
			'reply',
		);
	}

	/* ---------------------------------------------------------------------
	 * Isolamento entre vendedores
	 *
	 * "Vendedor" aqui é o papel `seller`, que é do Dokan — e é por isso que este
	 * bloco não acompanhou a renomeação de Vendedor para Loja no restante do
	 * plugin. Na camada de empresa a entidade se chama Loja (`Reconectar_Lojas`,
	 * `CAP_GERIR_LOJAS`); nesta, o nome é o do papel que o Dokan cria e cujo
	 * isolamento estas três funções implementam. Trocá-lo daria um nome nosso a
	 * um conceito de terceiro.
	 * ------------------------------------------------------------------ */

	/**
	 * O usuário tem o papel `seller`, do Dokan?
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
		// Os fóruns desta instalação nascem pela mão do Super Administrador. Sem
		// `edit_others_forums` o Administrador abriria a listagem de fóruns e não
		// poderia tocar em nenhum dos que estão lá — a entrega pela metade que a
		// especificação ("criar fóruns") não aceita.
		'edit_others_forums',
	);

	/**
	 * Papel de fórum que o bbPress atribui aos dois perfis administrativos.
	 *
	 * O bbPress mantém uma segunda camada de papéis — `bbp_keymaster`,
	 * `bbp_moderator`, `bbp_participant`, `bbp_spectator`, `bbp_blocked` —
	 * gravada como papel adicional do usuário. Ela **se sobrepõe** ao papel do
	 * WordPress: `bbp_participant`, que todo cadastro recebe, é o que fazia
	 * `edit_topics` e `delete_others_topics` responderem "não" a um Moderador de
	 * Conteúdo cujo papel declara moderação de conteúdo.
	 *
	 * `bbp_moderator` e não `bbp_keymaster`: o segundo traz `keep_gate`, que abre
	 * as Configurações do bbPress e a ferramenta de **redefinição** — a que apaga
	 * fóruns, tópicos e respostas da instalação inteira. Medido: com
	 * `bbp_moderator`, `options-general.php?page=bbpress` e
	 * `tools.php?page=bbp-repair` continuam respondendo 403.
	 */
	const PAPEL_DE_MODERACAO_NO_FORUM = 'bbp_moderator';

	/**
	 * Capacidades de fórum que o bbPress reserva ao `keep_gate`.
	 *
	 * `bbp_map_forum_meta_caps()` — sob `bbp_map_meta_caps`, em `map_meta_cap`
	 * prioridade 10 — troca estas duas por `do_not_allow` para quem não é
	 * keymaster, **independentemente do papel**. Medido nesta instalação, com o
	 * papel declarando as duas:
	 *
	 *     allcaps[edit_forums] = true
	 *     map_meta_cap( 'edit_forums' ) = do_not_allow
	 *
	 * É o sintoma pior deste repositório: a capacidade é escrita, é aplicada e se
	 * perde adiante. Repare que `publish_forums` **não** está na lista — aquela o
	 * bbPress mapeia para `moderate`, que o `bbp_moderator` tem. A assimetria é
	 * dele, não nossa.
	 *
	 * @var string[]
	 */
	const CAPS_DE_FORUM_RESERVADAS_AO_BBPRESS = array(
		'edit_forums',
		'edit_others_forums',
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
	 * Devolve a gestão de fóruns a quem o papel do WordPress já autorizou.
	 *
	 * Prioridade **11**, depois do `bbp_map_meta_caps` que roda em 10: a decisão
	 * que este filtro revisa é justamente a dele, e um filtro na mesma prioridade
	 * dependeria da ordem de registro dos plugins.
	 *
	 * A trava do bbPress existe por uma razão legítima — fórum é estrutura, não
	 * conteúdo, e o plugin não quer moderador criando seção nova. Aqui a
	 * especificação pede o contrário, e de forma explícita: o Administrador
	 * "cria fóruns". Então a negação cai, e cai **só** onde o papel do WordPress
	 * já tinha dito sim. Quem não declara a capacidade continua barrado, e
	 * `keep_gate` — Configurações e redefinição do bbPress — não é tocado.
	 *
	 * A leitura é feita em `allcaps` e não com `user_can()` de propósito: a
	 * segunda reentraria em `map_meta_cap` com a mesma capacidade, que voltaria a
	 * este filtro. Seria recursão infinita, não uma resposta errada.
	 *
	 * @param string[] $caps       Capacidades primitivas exigidas.
	 * @param string   $cap        Capacidade consultada.
	 * @param int      $usuario_id Usuário sob avaliação.
	 * @return string[]
	 */
	public static function restaurar_gestao_de_foruns( $caps, $cap, $usuario_id ) {
		if ( ! in_array( $cap, self::CAPS_DE_FORUM_RESERVADAS_AO_BBPRESS, true ) ) {
			return $caps;
		}

		if ( ! in_array( 'do_not_allow', $caps, true ) ) {
			return $caps;
		}

		$usuario = get_userdata( $usuario_id );

		if ( ! $usuario || empty( $usuario->allcaps[ $cap ] ) ) {
			return $caps;
		}

		return array( $cap );
	}

	/**
	 * Dá ao Moderador e ao Administrador o papel de fórum correspondente.
	 *
	 * Sem isto, os dois perfis ficam com o `bbp_participant` que o bbPress
	 * concede a todo cadastro, e nenhuma das capacidades de moderação declaradas
	 * no papel do WordPress chega a valer — ver `PAPEL_DE_MODERACAO_NO_FORUM`.
	 *
	 * Pendurado em `set_user_role`, e não em `add_user_role`: `bbp_set_user_role()`
	 * troca o papel com `WP_User::remove_role()` e `WP_User::add_role()`, que
	 * disparam `add_user_role` — este método chamaria a si mesmo. `set_user_role`
	 * é o gancho do caminho normal (a tela de usuários, `wp user set-role`) e não
	 * participa daquela troca.
	 *
	 * Nada é rebaixado: quem já é `bbp_keymaster` — o Super Administrador —
	 * passa intacto. Um moderador não pode perder alcance porque alguém salvou o
	 * perfil dele.
	 *
	 * @param int    $usuario_id Usuário cujo papel mudou.
	 * @param string $papel      Papel novo.
	 * @return void
	 */
	public static function sincronizar_papel_no_forum( $usuario_id, $papel ) {
		if ( ! in_array( $papel, array( self::PAPEL_MODERADOR, self::PAPEL_ADMIN_EMPRESAS ), true ) ) {
			return;
		}

		self::aplicar_moderacao_no_forum( $usuario_id );
	}

	/**
	 * Repete a sincronização depois que o bbPress dá o papel padrão ao cadastro.
	 *
	 * `wp_insert_user()` grava o papel com `set_role()` — o que dispara
	 * `set_user_role` e `sincronizar_papel_no_forum()` — e só **depois** dispara
	 * `user_register`. Ali o bbPress (`bbp_user_add_role_on_register()`, pelo
	 * `bbp_user_register` em prioridade 10) chama `bbp_set_user_role()` com o
	 * papel padrão e grava `bbp_participant` por cima do `bbp_moderator` que
	 * acabara de ser dado.
	 *
	 * O sintoma não é erro: Moderador e Administrador nascem sem moderar o fórum
	 * (`edit.php?post_type=topic` em 403), e só quem já existia quando a
	 * migração rodou fica certo. Foi a carga de demonstração, ao recriar os
	 * usuários, que revelou — mas vale para todo cadastro desses dois papéis.
	 *
	 * @param int $usuario_id Usuário recém-cadastrado.
	 * @return void
	 */
	public static function sincronizar_papel_no_forum_ao_cadastrar( $usuario_id ) {
		$usuario = get_userdata( $usuario_id );

		if ( ! $usuario || ! array_intersect( array( self::PAPEL_MODERADOR, self::PAPEL_ADMIN_EMPRESAS ), (array) $usuario->roles ) ) {
			return;
		}

		self::aplicar_moderacao_no_forum( $usuario_id );
	}

	/**
	 * Promove um usuário a moderador do fórum, se ainda não estiver acima disso.
	 *
	 * Idempotente por construção, que é requisito deste repositório: chamada duas
	 * vezes, a segunda não escreve nada.
	 *
	 * @param int $usuario_id Usuário.
	 * @return bool Se houve mudança.
	 */
	public static function aplicar_moderacao_no_forum( $usuario_id ) {
		if ( ! function_exists( 'bbp_set_user_role' ) || ! function_exists( 'bbp_get_user_role' ) ) {
			return false;
		}

		$atual = bbp_get_user_role( $usuario_id );

		// `bbp_keymaster` está acima e não se mexe; o papel já correto tampouco.
		if ( in_array( $atual, array( 'bbp_keymaster', self::PAPEL_DE_MODERACAO_NO_FORUM ), true ) ) {
			return false;
		}

		bbp_set_user_role( $usuario_id, self::PAPEL_DE_MODERACAO_NO_FORUM );

		return true;
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

		// A página "Comunidade" criada pelo provisionamento não é do BuddyPress —
		// `Reconectar_Comunidade` a redireciona ao diretório de atividade, em
		// prioridade 11. Ela precisa ser reconhecida pelo slug para que o cliente
		// receba a negação aqui, antes de o redirecionamento revelar o destino.
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

		return self::remover_links_de_widget( $conteudo, array( self::caminho_de_url( get_permalink( $comunidade ) ) ) );
	}

	/**
	 * Remove do HTML de um widget os links cujo caminho esteja na lista.
	 *
	 * Separado do filtro acima porque o painel da loja reaproveita a mesma
	 * remoção com outros destinos — veja `Reconectar_Navegacao_Da_Loja`. A
	 * comparação continua sendo pelo caminho, pela razão escrita lá.
	 *
	 * @param string   $conteudo HTML do widget.
	 * @param string[] $caminhos Caminhos já reduzidos por `caminho_de_url()`.
	 * @return string HTML sem os links daqueles caminhos.
	 */
	public static function remover_links_de_widget( $conteudo, $caminhos ) {
		$caminhos = array_filter( (array) $caminhos, 'strlen' );

		if ( ! is_string( $conteudo ) || false === stripos( $conteudo, '<a' ) || ! $caminhos ) {
			return $conteudo;
		}

		// Repetida aqui porque o método é público e tem outro chamador.
		if ( ! class_exists( 'DOMDocument' ) ) {
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
			if ( ! in_array( self::caminho_de_url( $link->getAttribute( 'href' ) ), $caminhos, true ) ) {
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
	public static function caminho_de_url( $url ) {
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

		if ( self::entra_no_painel_wp() ) {
			return;
		}

		if ( ! is_user_logged_in() ) {
			return;
		}

		wp_safe_redirect( self::destino_fora_do_painel() );
		exit;
	}

	/**
	 * O usuário passa pela porta do `/wp-admin`?
	 *
	 * Uma resposta só para as três decisões que dependem dela — o bloqueio, a
	 * barra administrativa e o link do painel em "Minha conta". Se divergissem, a
	 * tela ofereceria um link que o bloqueio devolve com um redirecionamento.
	 *
	 * @param int $usuario_id Usuário a avaliar; 0 usa o usuário atual.
	 * @return bool
	 */
	public static function entra_no_painel_wp( $usuario_id = 0 ) {
		if ( $usuario_id ) {
			return self::eh_administracao_tecnica( $usuario_id ) || user_can( $usuario_id, self::CAP_ADMIN_WP );
		}

		return self::eh_administracao_tecnica() || current_user_can( self::CAP_ADMIN_WP );
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
	 * A condição é a mesma de `bloquear_area_administrativa()` de propósito: uma
	 * barra com o link "Painel" que leva a um redirecionamento é pior que barra
	 * nenhuma.
	 *
	 * @param bool $exibir Decisão anterior.
	 * @return bool
	 */
	public static function ocultar_barra_administrativa( $exibir ) {
		if ( ! is_user_logged_in() ) {
			return $exibir;
		}

		if ( self::entra_no_painel_wp() ) {
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

/**
 * O usuário atual passa pela porta do `/wp-admin`?
 *
 * Fachada para o tema, pela mesma razão de
 * `reconectar_pode_participar_da_comunidade()`: o tema não conhece a classe nem
 * a capacidade, e decide o que fazer quando o plugin não está de pé.
 *
 * @return bool
 */
function reconectar_pode_entrar_no_painel_wp() {
	return Reconectar_Permissoes::entra_no_painel_wp();
}
