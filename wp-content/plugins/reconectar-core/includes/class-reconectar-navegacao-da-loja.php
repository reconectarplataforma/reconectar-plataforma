<?php
/**
 * Comunidade, Fórum e Incubadora no painel da loja, fora da navegação de compra.
 *
 * Para quem tem papel `seller`, a comunidade e a Incubadora são ferramentas de
 * trabalho, não parte da vitrine: elas passam a morar no menu lateral do painel
 * do Dokan, e saem do menu principal, do rodapé e da home do site, onde
 * disputavam espaço com "Loja" e "Lojas". O cliente não muda: a comunidade já
 * lhe é negada, e a Incubadora, que ele lê, continua no menu.
 *
 * E não só o link: para a loja, as três áreas **abrem dentro** da moldura do
 * painel — a barra lateral do Dokan à esquerda, sem o cabeçalho, o rodapé e a
 * barra inferior da vitrine. As URLs são as de sempre (`/forums/`,
 * `/comunidade/`, `/incubadora/…`): mover as rotas para dentro de `/dashboard/`
 * obrigaria a reescrever o roteamento do bbPress, do BuddyPress e da
 * Incubadora, e cada link já gravado — notificação, e-mail, resposta citada —
 * levaria ao 404. O que muda é a casca, decidida aqui por
 * `area_corrente()` e desenhada pelo tema em `header.php` e `footer.php`.
 *
 * Nenhuma permissão é tocada: a loja segue abrindo as três áreas pelos mesmos
 * portões de antes.
 *
 * O painel de empresas (`/painel-empresas/`) usa a mesma moldura, para quem
 * tem o portão dele: Empresas e Lojas viram itens da barra lateral, e o
 * Administrador opera dentro do painel do Dokan **sem** receber `dokandar`. A
 * rota continua própria, pelo mesmo motivo das três áreas — e pelo registrado
 * no topo de `class-reconectar-painel-empresas.php`: a capacidade de loja faria
 * dele um vendedor para o plugin inteiro. O que vem do Dokan é só a casca.
 *
 * O Moderador de Conteúdo também trabalha na moldura, pelo mesmo arranjo: a
 * Incubadora, o Fórum e a Comunidade abrem com a barra lateral do Dokan, sem
 * `dokandar`, e o login dele cai na Incubadora. A razão é de orientação, não de
 * permissão: na vitrine, com carrinho e busca de produto no cabeçalho, o
 * Moderador não tinha como saber que tinha saído do mercado. Os menus do site
 * ficam como estão para ele — a moldura só existe nas três áreas, e fora delas
 * o menu é o caminho de volta.
 *
 * @package reconectar-core
 */

defined( 'ABSPATH' ) || exit;

/**
 * Navegação da comunidade e da Incubadora para a loja.
 */
class Reconectar_Navegacao_Da_Loja {

	/**
	 * Identificador do script do layout React do painel, no Dokan.
	 */
	const SCRIPT_DO_LAYOUT = 'dokan-vendor-dashboard';

	/**
	 * Registra os ganchos.
	 *
	 * Os dois filtros de ocultação rodam em prioridade 20, depois dos de
	 * `Reconectar_Permissoes` (10): para quem não participa da comunidade aqueles
	 * já tiraram os itens, e estes não têm o que fazer.
	 *
	 * @return void
	 */
	public static function init() {
		add_filter( 'dokan_get_dashboard_nav', array( __CLASS__, 'registrar_menu' ) );
		add_filter( 'wp_nav_menu_objects', array( __CLASS__, 'ocultar_itens_do_menu' ), 20 );
		add_filter( 'wp_nav_menu_objects', array( __CLASS__, 'acrescentar_item_loja_ao_menu' ), 20 );
		add_filter( 'widget_custom_html_content', array( __CLASS__, 'ocultar_links_do_widget' ), 20 );
		add_filter( 'dokan_dashboard_nav_active', array( __CLASS__, 'marcar_item_ativo' ) );
		add_filter( 'body_class', array( __CLASS__, 'classes_do_corpo' ), 30 );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enfileirar_layout_do_painel' ), 20 );
		add_filter( 'dokan_vendor_dashboard_layout_config', array( __CLASS__, 'ajustar_layout_para_quem_nao_e_loja' ) );
		add_filter( 'dokan_frontend_localize_script', array( __CLASS__, 'ajustar_vitrine_para_quem_nao_e_loja' ) );
		add_filter( 'load_script_translations', array( __CLASS__, 'rotular_volta_ao_mercado' ), 10, 4 );
		add_action( 'template_redirect', array( __CLASS__, 'levar_moderador_aos_modulos' ), 10 );
	}

	/**
	 * A comunidade e a Incubadora moram no painel da loja para o usuário corrente?
	 *
	 * Pelo papel, e não por `dokandar`: o Administrador também tem a capacidade de
	 * loja, e para ele o painel do Dokan não é a casa — tirar os itens do menu do
	 * site o deixaria sem caminho.
	 *
	 * @return bool
	 */
	public static function mora_no_painel() {
		return is_user_logged_in() && Reconectar_Permissoes::eh_vendedor( get_current_user_id() );
	}

	/**
	 * A Incubadora, o Fórum e a Comunidade abrem na moldura de moderação?
	 *
	 * Só o papel `content_moderator`, e só com o Dokan de pé: a moldura é a casca
	 * dele, e sem o plugin a tela volta à vitrine, que funciona.
	 *
	 * @return bool
	 */
	public static function modera_no_painel() {
		return is_user_logged_in()
			&& function_exists( 'dokan_get_dashboard_nav' )
			&& Reconectar_Permissoes::eh_moderador_de_conteudo( get_current_user_id() );
	}

	/**
	 * Chave do item do painel que corresponde à tela corrente, ou vazio.
	 *
	 * Vazio para Administrador e cliente, que seguem vendo as três áreas na
	 * moldura da vitrine; a loja e o Moderador as veem na do painel. O reconhecimento repete o de
	 * `Reconectar_Permissoes::requisicao_e_de_comunidade()` — os post types do
	 * bbPress, o componente do BuddyPress e a página `comunidade`, que na raiz
	 * não reporta componente nenhum. O fórum é testado primeiro porque o perfil
	 * de usuário do bbPress também é do BuddyPress quando os dois estão ligados.
	 *
	 * O painel de empresas vem antes do teste de papel porque o ator dele não é
	 * loja. A guarda por `dokan_get_dashboard_nav` mantém a promessa do painel de
	 * não depender do Dokan: sem o plugin, ele volta à casca própria.
	 *
	 * @return string `rc-empresas`, `rc-lojas`, `rc-forum`, `rc-comunidade`,
	 *                `rc-incubadora` ou vazio.
	 */
	public static function area_corrente() {
		if ( self::painel_de_empresas_na_moldura() ) {
			return 'rc-' . Reconectar_Painel_Empresas::secao_corrente();
		}

		if ( ! self::mora_no_painel() && ! self::modera_no_painel() ) {
			return '';
		}

		if ( function_exists( 'is_bbpress' ) && is_bbpress() ) {
			return 'rc-forum';
		}

		if ( ( function_exists( 'bp_current_component' ) && bp_current_component() ) || is_page( 'comunidade' ) ) {
			return 'rc-comunidade';
		}

		// Dois `if` e não um `&&` com `class_exists()`: com a classe ainda não
		// carregada, o primeiro termo curto-circuitaria o autoload.
		if ( class_exists( 'Reconectar_Incubadora' ) ) {
			if ( Reconectar_Incubadora::requisicao_e_da_incubadora() ) {
				return 'rc-incubadora';
			}
		}

		return '';
	}

	/**
	 * O painel de empresas abre na moldura do Dokan para o usuário corrente?
	 *
	 * A capacidade é conferida aqui, e não só em `Reconectar_Painel_Empresas::proteger()`,
	 * porque a casca é decidida em `header.php`: quem não passa no portão recebe o
	 * 403 dentro do cabeçalho da vitrine, e não dentro de um painel cuja barra
	 * lateral não teria item nenhum para ele.
	 *
	 * @return bool
	 */
	public static function painel_de_empresas_na_moldura() {
		if ( ! function_exists( 'dokan_get_dashboard_nav' ) || ! is_user_logged_in() ) {
			return false;
		}

		if ( ! current_user_can( Reconectar_Permissoes::CAP_PAINEL_EMPRESAS ) ) {
			return false;
		}

		return Reconectar_Painel_Empresas::eh_a_pagina();
	}

	/**
	 * A tela corrente está na moldura do painel aberta por quem não é loja?
	 *
	 * O painel de empresas, para o Administrador, e as três áreas, para o
	 * Moderador. É a condição dos dois ajustes do topo React, que o Dokan monta
	 * para um vendedor.
	 *
	 * @return bool
	 */
	private static function moldura_de_quem_nao_e_loja() {
		return self::painel_de_empresas_na_moldura() || ( self::modera_no_painel() && '' !== self::area_corrente() );
	}

	/**
	 * Ajusta o topo do painel React para quem o abre sem ser loja.
	 *
	 * O Dokan monta a configuração para um vendedor: o nome da loja no topo, e
	 * "Minha conta" e o lápis do perfil apontando para `/dashboard/edit-account/`.
	 * Para o Administrador e o Moderador, que não têm loja, o nome sairia vazio e
	 * os dois links levariam a uma tela que os recusa. A conta deles é a do
	 * WooCommerce.
	 *
	 * No lugar do nome da loja vai o nome do módulo. Para o Moderador é "Moderação",
	 * e é o rótulo que diz a ele que saiu do mercado — a razão de a moldura existir
	 * para esse perfil. Uma palavra só: o rodapé da barra corta em reticências, e
	 * "Moderação de conteúdo" saía "Moderação de con…".
	 *
	 * Só age sem `dokandar`: o Super Administrador que abrir o painel de empresas
	 * pelo `/dashboard/` dele é também loja, e o que o Dokan montou é o certo.
	 *
	 * @param array $config Configuração do layout, como o Dokan a montou.
	 * @return array
	 */
	public static function ajustar_layout_para_quem_nao_e_loja( $config ) {
		if ( ! is_array( $config ) || current_user_can( 'dokandar' ) || ! self::moldura_de_quem_nao_e_loja() ) {
			return $config;
		}

		$conta = function_exists( 'wc_get_account_endpoint_url' ) ? wc_get_account_endpoint_url( 'edit-account' ) : '';

		$config['vendor']['name'] = self::painel_de_empresas_na_moldura()
			? get_bloginfo( 'name' )
			: __( 'Moderação', 'reconectar-core' );

		if ( '' !== $conta ) {
			$config['editUrl'] = $conta;
		}

		if ( isset( $config['headerNav'] ) && is_array( $config['headerNav'] ) ) {
			$painel_da_loja = function_exists( 'dokan_get_navigation_url' ) ? dokan_get_navigation_url( 'edit-account' ) : '';

			foreach ( $config['headerNav'] as $indice => $item ) {
				if ( '' !== $conta && isset( $item['url'] ) && $painel_da_loja === $item['url'] ) {
					$config['headerNav'][ $indice ]['url'] = $conta;
				}
			}
		}

		return $config;
	}

	/**
	 * Aponta o "Visitar loja" do topo React para o catálogo da plataforma.
	 *
	 * O link não está na configuração do layout: o React o lê de
	 * `dokan.urls.storeUrl`, que o Dokan monta com `dokan_get_store_url()` do
	 * usuário corrente. Para o Administrador isso dava `/store/<login>/` — a
	 * vitrine de uma loja que não existe. O botão não pode ser tirado sem mexer
	 * no React; o catálogo é o destino que o rótulo promete, e é o item "Loja"
	 * do menu do site.
	 *
	 * @param array $dados Dados que o Dokan entrega ao JavaScript do painel.
	 * @return array
	 */
	public static function ajustar_vitrine_para_quem_nao_e_loja( $dados ) {
		if ( ! is_array( $dados ) || current_user_can( 'dokandar' ) || ! self::moldura_de_quem_nao_e_loja() ) {
			return $dados;
		}

		$catalogo = function_exists( 'wc_get_page_permalink' ) ? wc_get_page_permalink( 'shop' ) : home_url( '/' );

		$dados['urls']['storeUrl'] = $catalogo;

		return $dados;
	}

	/**
	 * Troca "Visitar loja" por "Ir ao mercado" no topo React.
	 *
	 * Quem não é loja não tem loja para visitar: o botão leva ao catálogo, por
	 * `ajustar_vitrine_para_quem_nao_e_loja()`, e para o Moderador ele é a volta
	 * do módulo de moderação ao mercado. O rótulo é um `__( 'Visit Store' )` dentro
	 * do bundle, sem filtro nem configuração que o alcance; a única entrada é a
	 * tradução que o WordPress injeta no script, e é ela que se reescreve.
	 *
	 * Sem arquivo de tradução — instalação em inglês, ou pacote ainda não baixado
	 * — `$traducoes` chega `false`, e se monta o mínimo: do contrário o rótulo
	 * voltaria a dizer "Visit Store" justamente onde ninguém conferiu. A chave do
	 * domínio varia com quem gerou o JSON (`messages` no wordpress.org,
	 * `dokan-lite` no Loco), e o `wp.i18n` aceita as duas.
	 *
	 * @param string|false $traducoes JSON das traduções do script, ou `false`.
	 * @param string       $arquivo   Caminho do JSON.
	 * @param string       $handle    Handle do script.
	 * @param string       $dominio   Domínio de texto.
	 * @return string|false
	 */
	public static function rotular_volta_ao_mercado( $traducoes, $arquivo, $handle, $dominio ) {
		if ( 'dokan-vendor-dashboard' !== $handle || 'dokan-lite' !== $dominio || current_user_can( 'dokandar' ) || ! self::moldura_de_quem_nao_e_loja() ) {
			return $traducoes;
		}

		$json = is_string( $traducoes ) ? json_decode( $traducoes, true ) : null;
		if ( ! is_array( $json ) || empty( $json['locale_data'] ) || ! is_array( $json['locale_data'] ) ) {
			$json = array( 'locale_data' => array( 'messages' => array( '' => array( 'domain' => 'messages' ) ) ) );
		}

		$rotulo = __( 'Ir ao mercado', 'reconectar-core' );
		foreach ( array_keys( $json['locale_data'] ) as $chave ) {
			$json['locale_data'][ $chave ]['Visit Store'] = array( $rotulo );
		}

		return wp_json_encode( $json );
	}

	/**
	 * Marca o item da área corrente na barra lateral do Dokan.
	 *
	 * O Dokan deduz o item ativo do caminho da requisição depois de `/dashboard/`,
	 * e fora dele a dedução não casa com chave nenhuma: sem este filtro, nenhum
	 * item sairia marcado, e a loja não saberia em que área está.
	 *
	 * @param string $ativo Chave que o Dokan deduziu.
	 * @return string
	 */
	public static function marcar_item_ativo( $ativo ) {
		$area = self::area_corrente();

		return '' !== $area ? $area : $ativo;
	}

	/**
	 * Veste o `<body>` das três áreas como o do painel.
	 *
	 * As sidebars do Storefront saem sempre: reservariam 26% da largura para uma
	 * coluna que a moldura não imprime (veja `reconectar_ajustar_classes_de_layout()`,
	 * no tema).
	 *
	 * As duas classes do Dokan só entram se o layout React tiver sido carregado.
	 * O `style.css` dele declara, sob `.dokan-dashboard`, a barra lateral clássica
	 * em `visibility: hidden` e o `.dokan-dashboard-content` em `display: none`, à
	 * espera de o React os remontar. Com a classe e sem o script, a tela sai em
	 * branco — medido, com a barra ocupando 250px invisíveis. Sem as duas, a
	 * moldura cai na barra lateral clássica, que é feia e funciona.
	 *
	 * @param string[] $classes Classes do `<body>`.
	 * @return string[]
	 */
	public static function classes_do_corpo( $classes ) {
		if ( '' === self::area_corrente() ) {
			return $classes;
		}

		$classes   = array_values( array_diff( $classes, array( 'right-sidebar', 'left-sidebar' ) ) );
		$classes[] = 'rc-no-painel-da-loja';

		if ( wp_script_is( self::SCRIPT_DO_LAYOUT, 'enqueued' ) ) {
			$classes[] = 'dokan-dashboard';
			$classes[] = 'dokan-dashboard-fullwidth-template';
		}

		return $classes;
	}

	/**
	 * Carrega, nas três áreas, o layout React do painel e os estilos dele.
	 *
	 * O Dokan 4 desenha o painel em React: a barra lateral e a barra do topo saem
	 * de `dokan-vendor-dashboard`, que `FullWidthVendorLayout` só registra quando
	 * `dokan_is_seller_dashboard()` responde sim — isto é, na página `/dashboard/`.
	 * O registro não é só o arquivo: monta `vendorDashboardLayoutConfig`, com o
	 * menu, a loja e o avatar, e copiar essa montagem para cá a congelaria na
	 * versão de hoje do Dokan.
	 *
	 * Por isso os dois métodos públicos dele são chamados aqui, com
	 * `dokan_get_current_page_id` respondendo a página do painel **só durante a
	 * chamada**. Filtrar a requisição inteira faria `dokan_is_seller_dashboard()`
	 * responder sim em todo o Dokan — e ele troca o template da página
	 * (`rewrite_vendor_dashboard_template`) e carrega os mais de 200 scripts do
	 * painel por esse mesmo teste.
	 *
	 * Tudo é condicional: sem Dokan, sem o contêiner ou com o layout `legacy`, o
	 * script não fica enfileirado, e `classes_do_corpo()` cai na barra clássica.
	 *
	 * @return void
	 */
	public static function enfileirar_layout_do_painel() {
		if ( '' === self::area_corrente() ) {
			return;
		}

		foreach ( array( 'dokan-style', 'dokan-fontawesome' ) as $estilo ) {
			if ( wp_style_is( $estilo, 'registered' ) ) {
				wp_enqueue_style( $estilo );
			}
		}

		// O pacote nouveau do BuddyPress, ao detectar o Storefront, dá ao
		// `#buddypress` margem negativa (-90px medidos) para ele vazar do
		// `.col-full`. Na moldura não há `.col-full`: o diretório invadia a barra
		// lateral, cortando a primeira aba, e transbordava à direita. Inline, preso
		// ao estilo do Dokan, porque só existe enquanto a moldura existe.
		if ( wp_style_is( 'dokan-style', 'enqueued' ) ) {
			wp_add_inline_style(
				'dokan-style',
				'.rc-painel-da-loja #buddypress.buddypress-wrap{margin-left:0;margin-right:0;width:auto;max-width:100%}'
			);
		}

		$classe = 'WeDevs\\Dokan\\Shortcodes\\FullWidthVendorLayout';
		if ( ! function_exists( 'dokan_get_container' ) || ! function_exists( 'dokan_get_option' ) || ! class_exists( $classe ) ) {
			return;
		}

		$pagina_do_painel = absint( dokan_get_option( 'dashboard', 'dokan_pages' ) );
		if ( ! $pagina_do_painel ) {
			return;
		}

		try {
			$layout = dokan_get_container()->get( $classe );
		} catch ( Throwable $erro ) {
			return;
		}

		if ( ! method_exists( $layout, 'register_vendor_dashboard_assets' ) || ! method_exists( $layout, 'enqueue_vendor_dashboard_assets' ) ) {
			return;
		}

		$fingir_painel = static function () use ( $pagina_do_painel ) {
			return $pagina_do_painel;
		};

		add_filter( 'dokan_get_current_page_id', $fingir_painel );
		try {
			$layout->register_vendor_dashboard_assets();
			$layout->enqueue_vendor_dashboard_assets();
		} finally {
			remove_filter( 'dokan_get_current_page_id', $fingir_painel );
		}
	}

	/**
	 * Acrescenta Comunidade, Fórum e Incubadora ao menu lateral do painel.
	 *
	 * São links para fora do painel, e por isso não têm query var nem pedem flush
	 * de reescrita: a chave do item só serve ao Dokan para marcar o ativo, e
	 * nenhuma tela do painel a usa. Cada item só entra se o destino existir —
	 * `get_page_by_path()` devolve rascunho e lixeira, e um link para o 404 é pior
	 * que um item a menos.
	 *
	 * As posições ficam depois de "Avaliações pendentes" (51) e antes de
	 * "Configurações", agrupando as três ferramentas que não são de venda.
	 *
	 * @param array $nav Itens do menu do painel.
	 * @return array
	 */
	public static function registrar_menu( $nav ) {
		$destinos = self::destinos();

		// O caminho de volta à escolha de módulo, no topo da barra. Sem
		// `permission`, porque nenhuma capacidade descreve "trabalha na
		// plataforma": o Dokan mostra item sem ela a todos, e o cliente, que não
		// passa pela tela, não deve vê-lo. Daí o `if`.
		if ( class_exists( 'Reconectar_Modulos' ) && Reconectar_Modulos::escolhe_modulo() ) {
			$nav['rc-modulos'] = array(
				'title'     => __( 'Módulos', 'reconectar-core' ),
				'icon'      => '<i class="fas fa-th-large"></i>',
				'icon_name' => 'LayoutGrid',
				'url'       => Reconectar_Modulos::url(),
				'pos'       => 5,
			);
		}

		// Empresas e Lojas vêm antes das ferramentas da comunidade: para o
		// Administrador são a razão de o painel existir. A permissão é o portão
		// do painel de empresas, e não `dokandar` — a loja não as vê, o Super
		// Administrador as vê no `/dashboard/` dele.
		if ( class_exists( 'Reconectar_Painel_Empresas' ) && '' !== Reconectar_Painel_Empresas::url() ) {
			$url_empresas = Reconectar_Painel_Empresas::url();

			// O React do Dokan acende item sem submenu por
			// `location.href.startsWith( item.url )`, e `/painel-empresas/` é
			// prefixo de `/painel-empresas/loja/`: nas telas de lojas os dois
			// itens saíam marcados. A listagem de empresas não tem outra URL, e
			// o fragmento `#content` — o alvo do "Pular para o conteúdo" da
			// moldura — tira o prefixo sem mudar o destino. Só nessas telas: nas
			// de empresas é justamente o prefixo que acende o item.
			if ( self::painel_de_empresas_na_moldura() && 'lojas' === Reconectar_Painel_Empresas::secao_corrente() ) {
				$url_empresas .= '#content';
			}

			$nav['rc-empresas'] = array(
				'title'      => __( 'Empresas', 'reconectar-core' ),
				'icon'       => '<i class="fas fa-building"></i>',
				'icon_name'  => 'Building2',
				'url'        => $url_empresas,
				'pos'        => 150,
				'permission' => Reconectar_Permissoes::CAP_PAINEL_EMPRESAS,
			);

			$nav['rc-lojas'] = array(
				'title'      => __( 'Lojas', 'reconectar-core' ),
				'icon'       => '<i class="fas fa-store"></i>',
				'icon_name'  => 'Store',
				'url'        => Reconectar_Painel_Empresas::url( Reconectar_Painel_Empresas::ENDPOINT_LOJA ),
				'pos'        => 151,
				'permission' => Reconectar_Permissoes::CAP_PAINEL_EMPRESAS,
			);
		}

		if ( '' !== $destinos['comunidade'] ) {
			$nav['rc-comunidade'] = array(
				'title'      => __( 'Comunidade', 'reconectar-core' ),
				'icon'       => '<i class="fas fa-users"></i>',
				'icon_name'  => 'Users',
				'url'        => $destinos['comunidade'],
				'pos'        => 160,
				'permission' => Reconectar_Permissoes::CAP_COMUNIDADE,
			);
		}

		if ( '' !== $destinos['forum'] ) {
			$nav['rc-forum'] = array(
				'title'      => __( 'Fórum', 'reconectar-core' ),
				'icon'       => '<i class="fas fa-comments"></i>',
				'icon_name'  => 'MessagesSquare',
				'url'        => $destinos['forum'],
				'pos'        => 161,
				'permission' => Reconectar_Permissoes::CAP_COMUNIDADE,
			);
		}

		// Para o Moderador a Incubadora é a casa, e vem primeiro; a permissão
		// passa a ser a de geri-la, porque ele não tem `dokandar`. O Administrador
		// também a tem, e por isso a troca é pelo papel: na barra dele, um item que
		// abrisse fora da moldura seria uma porta para a vitrine.
		$moderador = self::modera_no_painel();

		if ( '' !== $destinos['incubadora'] ) {
			$nav['rc-incubadora'] = array(
				'title'      => __( 'Incubadora', 'reconectar-core' ),
				'icon'       => '<i class="fas fa-book-open"></i>',
				'icon_name'  => 'BookOpen',
				'url'        => $destinos['incubadora'],
				'pos'        => $moderador ? 155 : 162,
				'permission' => $moderador ? Reconectar_Permissoes::CAP_GERIR_INCUBADORA : 'dokandar',
			);
		}

		// Campanhas, enquetes, publicações e comentários são moderados no
		// `/wp-admin`, e o item é a saída explícita para lá. Sai da moldura, e o
		// rótulo diz isso: um nome de tela ("Comentários") prometeria que ela
		// abre aqui.
		if ( $moderador ) {
			$nav['rc-painel-wp'] = array(
				'title'      => __( 'Painel do WordPress', 'reconectar-core' ),
				'icon'       => '<i class="fab fa-wordpress"></i>',
				'icon_name'  => 'LayoutDashboard',
				'url'        => admin_url(),
				'pos'        => 170,
				'permission' => Reconectar_Permissoes::CAP_ADMIN_WP,
			);
		}

		return $nav;
	}

	/**
	 * Leva o Moderador do `/dashboard/` à tela de módulos.
	 *
	 * O Dokan manda quem não é loja para a home (`dokan_redirect_if_not_seller()`,
	 * em `template_redirect` 11), e a home é o mercado — justamente o que o
	 * Moderador precisa entender que deixou. Prioridade 10, antes dele. O link
	 * "Painel do vendedor" do rodapé e o "Visitar painel" das notificações levam
	 * para lá, e por isso o caso não é teórico.
	 *
	 * Antes da tela de módulos o destino era a Incubadora. Agora é a escolha: o
	 * `/dashboard/` é o "painel" de quem trabalha na plataforma, e para o
	 * Moderador o painel não tem uma casa só.
	 *
	 * @return void
	 */
	public static function levar_moderador_aos_modulos() {
		if ( ! self::modera_no_painel() || ! function_exists( 'dokan_get_option' ) || ! class_exists( 'Reconectar_Modulos' ) ) {
			return;
		}

		$pagina_do_painel = absint( dokan_get_option( 'dashboard', 'dokan_pages' ) );

		if ( ! $pagina_do_painel || ! is_page( $pagina_do_painel ) ) {
			return;
		}

		wp_safe_redirect( Reconectar_Modulos::url() );
		exit;
	}

	/**
	 * Tira do menu do site os itens que, para a loja, moram no painel.
	 *
	 * A comparação é pelo caminho da URL, como em
	 * `Reconectar_Permissoes::ocultar_itens_da_comunidade()`: alcança o item
	 * `post_type` da página e o item `custom` do fórum, e sobrevive a alguém
	 * renomear o rótulo ou reordenar o menu pelo painel. Remover os itens do
	 * banco não serviria — o menu é o mesmo para todos os perfis.
	 *
	 * @param array $itens Itens do menu.
	 * @return array
	 */
	public static function ocultar_itens_do_menu( $itens ) {
		if ( ! self::mora_no_painel() ) {
			return $itens;
		}

		$caminhos = self::caminhos();

		foreach ( $itens as $indice => $item ) {
			$do_forum = in_array( $item->object, array( 'forum', 'topic' ), true );

			if ( $do_forum || in_array( Reconectar_Permissoes::caminho_de_url( $item->url ), $caminhos, true ) ) {
				unset( $itens[ $indice ] );
			}
		}

		return $itens;
	}

	/**
	 * Acrescenta o item "Loja", a vitrine da própria loja, para quem tem uma.
	 *
	 * O item da página de produtos do WooCommerce ("Produtos", o catálogo inteiro
	 * com filtros) é de todos e fica como veio. Logo depois dele, só para a loja,
	 * entra "Loja", que leva a `dokan_get_store_url()` do usuário corrente — a
	 * vitrine com os produtos dela. Quem não é loja não o recebe, inclusive o
	 * Administrador, que tem `dokandar` e nenhuma vitrine: a dele seria
	 * `/store/<login>/`, uma loja que não existe (a armadilha do "Visitar loja"
	 * no CLAUDE.md).
	 *
	 * O item é sintético, montado aqui, e não gravado no menu: gravado, seria um
	 * `custom` com a URL de **uma** loja, visível a todos; e o painel de menus não
	 * sabe exibir item por papel. A âncora é o item da página de produtos,
	 * reconhecido pelo ID da página — nunca pelo rótulo, que o administrador
	 * renomeia, nem pelo slug, que difere entre os ambientes (`/shop/` aqui,
	 * `/loja/` em produção). Sem essa âncora no menu, o item vai para o fim.
	 *
	 * O destaque de item corrente é calculado aqui porque o núcleo o calcula
	 * antes deste filtro, e um item que ainda não existia não foi avaliado.
	 *
	 * @param WP_Post[] $itens Itens do menu, já decorados pelo núcleo.
	 * @return WP_Post[]
	 */
	public static function acrescentar_item_loja_ao_menu( $itens ) {
		if ( ! $itens || ! self::mora_no_painel() || ! function_exists( 'dokan_get_store_url' ) ) {
			return $itens;
		}

		$vitrine            = dokan_get_store_url( get_current_user_id() );
		$caminho_da_vitrine = Reconectar_Permissoes::caminho_de_url( $vitrine );

		if ( '' === $caminho_da_vitrine ) {
			return $itens;
		}

		$caminho_corrente = Reconectar_Permissoes::caminho_de_url( isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '' ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- só o caminho é extraído, para comparação.

		// Prefixo com a barra, para que `/store/ana` não acenda em `/store/ana-maria/`.
		$na_vitrine     = 0 === strpos( $caminho_corrente . '/', $caminho_da_vitrine . '/' );
		$pagina_produto = function_exists( 'wc_get_page_id' ) ? (int) wc_get_page_id( 'shop' ) : 0;
		$ancora         = null;

		foreach ( $itens as $item ) {
			if ( $pagina_produto > 0 && 'post_type' === $item->type && (int) $item->object_id === $pagina_produto ) {
				$ancora = $item;
				break;
			}
		}

		// Clone de um item real, e não `new stdClass`: o walker e os filtros de
		// terceiros leem uma dúzia de propriedades de `WP_Post` decorado, e as que
		// faltassem sairiam como aviso de propriedade indefinida.
		$loja                   = clone ( $ancora ? $ancora : end( $itens ) );
		$loja->ID               = 0;
		$loja->db_id            = 0;
		$loja->title            = __( 'Loja', 'reconectar-core' );
		$loja->url              = $vitrine;
		$loja->type             = 'custom';
		$loja->object           = 'custom';
		$loja->object_id        = 0;
		$loja->attr_title       = '';
		$loja->target           = '';
		$loja->xfn              = '';
		$loja->menu_item_parent = $ancora ? $ancora->menu_item_parent : 0;
		$loja->current          = $na_vitrine;
		$loja->classes          = array( 'menu-item', 'menu-item-type-custom', 'menu-item-object-custom', 'rc-menu-item-loja' );

		if ( $na_vitrine ) {
			$loja->classes[] = 'current-menu-item';
		}

		$resultado = array();

		foreach ( $itens as $item ) {
			$resultado[] = $item;

			if ( $item === $ancora ) {
				$resultado[] = $loja;
			}
		}

		if ( ! $ancora ) {
			$resultado[] = $loja;
		}

		// O walker imprime na ordem do array, e não por `menu_order`: inserir na
		// posição basta.
		return $resultado;
	}

	/**
	 * Tira dos widgets de HTML (a coluna "Navegação" do rodapé) os mesmos links.
	 *
	 * @param string $conteudo HTML do widget.
	 * @return string
	 */
	public static function ocultar_links_do_widget( $conteudo ) {
		if ( ! self::mora_no_painel() ) {
			return $conteudo;
		}

		return Reconectar_Permissoes::remover_links_de_widget( $conteudo, self::caminhos() );
	}

	/**
	 * URLs das três áreas, cada uma vazia se o destino não estiver publicado.
	 *
	 * Pública porque a tela de módulos aponta os cartões da Incubadora e da Praça
	 * para os mesmos destinos da barra lateral: dois lugares decidindo a mesma
	 * URL acabariam divergindo.
	 *
	 * @return array{comunidade: string, forum: string, incubadora: string}
	 */
	public static function destinos() {
		$comunidade = get_page_by_path( 'comunidade' );
		$forum      = function_exists( 'bbp_get_forums_url' ) ? get_post_type_archive_link( 'forum' ) : '';

		return array(
			'comunidade' => ( $comunidade && 'publish' === $comunidade->post_status ) ? get_permalink( $comunidade ) : '',
			'forum'      => is_string( $forum ) ? $forum : '',
			'incubadora' => class_exists( 'Reconectar_Incubadora_Leitura' ) ? Reconectar_Incubadora_Leitura::url_da_ancora() : '',
		);
	}

	/**
	 * Os destinos reduzidos a caminho, prontos para comparar.
	 *
	 * @return string[]
	 */
	private static function caminhos() {
		return array_values(
			array_filter( array_map( array( 'Reconectar_Permissoes', 'caminho_de_url' ), self::destinos() ), 'strlen' )
		);
	}
}

/**
 * Fachada para o tema: a comunidade e a Incubadora moram no painel da loja?
 *
 * O tema consulta por `function_exists()` para não depender do plugin.
 *
 * @return bool
 */
function reconectar_comunidade_mora_no_painel_da_loja() {
	return Reconectar_Navegacao_Da_Loja::mora_no_painel();
}

/**
 * Fachada para o tema: a tela corrente abre na moldura do painel da loja?
 *
 * @return bool
 */
function reconectar_tela_no_painel_da_loja() {
	return '' !== Reconectar_Navegacao_Da_Loja::area_corrente();
}
