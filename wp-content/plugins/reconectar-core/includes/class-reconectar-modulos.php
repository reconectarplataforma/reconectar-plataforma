<?php
/**
 * A tela de módulos: a escolha entre Mercado, Incubadora e Praça, a cada login.
 *
 * O edital organiza a plataforma em módulos — Mercado, Incubadora, Praça e
 * Cooperação —, e quem trabalha nela (Loja, Administrador, Moderador e Super
 * Administrador) passa por mais de um. Antes desta tela, o login decidia por eles: a loja caía no
 * `/dashboard/`, o Moderador na Incubadora, o Administrador em "Minha conta". A
 * pessoa só descobria os outros módulos pela barra lateral, e não tinha como
 * saber em qual deles estava. Aqui ela escolhe, e a escolha é o que a orienta.
 *
 * A Cooperação ainda não existe na plataforma e por isso não tem cartão: um
 * cartão que levasse a uma tela vazia diria ao avaliador que há algo pronto. A
 * lista de `cartoes()` já está montada para receber o quarto.
 *
 * Fora dos módulos do edital há um cartão de trabalho, conforme o perfil: o
 * "Painel" da Loja, que é o `/dashboard/` do Dokan, e o "Painel Admin" do Super
 * Administrador, que é o `/wp-admin`. São as duas casas que o login decidia
 * sozinho antes desta tela, e sem o cartão a pessoa teria de descobrir o caminho
 * de volta a elas pela barra lateral ou pelo menu da conta.
 *
 * **Rota própria, sem página.** O painel de empresas mora numa página criada
 * pelo `provision.sh`, e a Transparência ensinou o preço disso: página que não
 * atravessa o deploy some, e o consumidor dela cala. Esta rota é uma regra de
 * reescrita do próprio plugin — existe onde o plugin existir. O flush que ela
 * exige numa instalação já de pé é o da migração 9.
 *
 * **Template próprio, sem o tema.** A tela não é a vitrine: com carrinho e
 * busca no cabeçalho ela repetiria o defeito que levou o Moderador à moldura do
 * painel. E, como o painel de empresas, ela não pode exigir tema nenhum — os
 * tokens do CSS trazem fallback literal.
 *
 * @package reconectar-core
 */

defined( 'ABSPATH' ) || exit;

/**
 * Rota, portão, login e navegação da tela de módulos.
 */
class Reconectar_Modulos {

	/**
	 * Caminho da rota, relativo à raiz do site.
	 */
	const CAMINHO = 'modulos';

	/**
	 * Query var que identifica a rota.
	 *
	 * Com prefixo: `modulos` sozinho colidiria com qualquer parâmetro de mesmo
	 * nome que um plugin de terceiro leia.
	 */
	const QUERY_VAR = 'rc_modulos';

	/**
	 * Chave do item "Módulos" no menu da conta.
	 *
	 * Não é endpoint do WooCommerce; só identifica o item para que
	 * `url_do_item_da_conta()` troque o destino dele.
	 */
	const ITEM_DA_CONTA = 'rc-modulos';

	/**
	 * Registra os ganchos.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'init', array( __CLASS__, 'registrar_rota' ) );
		add_filter( 'query_vars', array( __CLASS__, 'registrar_query_var' ) );
		add_action( 'template_redirect', array( __CLASS__, 'proteger' ), 9 );
		add_filter( 'template_include', array( __CLASS__, 'template' ), 99 );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enfileirar_assets' ), 40 );
		add_filter( 'document_title_parts', array( __CLASS__, 'titulo_do_documento' ) );

		// Prioridade 25: depois do Dokan, que manda a loja para o `/dashboard/`
		// em 1, e de `reconectar_login_do_super_administrador()`, também em 25,
		// que não disputa o mesmo usuário; antes de `reconectar_login_volta_ao_checkout()`
		// (30), para que quem entrou pelo checkout continue voltando a ele.
		add_filter( 'woocommerce_login_redirect', array( __CLASS__, 'depois_do_login' ), 25, 2 );
		add_filter( 'login_redirect', array( __CLASS__, 'depois_do_login' ), 25, 3 );

		add_filter( 'woocommerce_account_menu_items', array( __CLASS__, 'item_da_conta' ), 15 );
		add_filter( 'woocommerce_get_endpoint_url', array( __CLASS__, 'url_do_item_da_conta' ), 10, 2 );
	}

	/**
	 * Registra a regra de reescrita de `/modulos/`.
	 *
	 * Regra nova só vale depois de um flush: o `provision.sh` faz o dele no fim,
	 * e a migração 9 faz o das instalações que já estavam de pé.
	 *
	 * @return void
	 */
	public static function registrar_rota() {
		add_rewrite_rule( '^' . self::CAMINHO . '/?$', 'index.php?' . self::QUERY_VAR . '=1', 'top' );
	}

	/**
	 * Declara a query var, sem a qual o WordPress a descarta da requisição.
	 *
	 * @param string[] $vars Query vars públicas.
	 * @return string[]
	 */
	public static function registrar_query_var( $vars ) {
		$vars[] = self::QUERY_VAR;

		return $vars;
	}

	/**
	 * URL da tela.
	 *
	 * @return string
	 */
	public static function url() {
		return home_url( '/' . self::CAMINHO . '/' );
	}

	/**
	 * A requisição corrente é a da tela?
	 *
	 * @return bool
	 */
	public static function eh_a_tela() {
		return '1' === (string) get_query_var( self::QUERY_VAR );
	}

	/**
	 * O usuário escolhe módulo ao entrar?
	 *
	 * Pelo papel, como `Reconectar_Permissoes::eh_vendedor()`: é identidade, não
	 * autorização — cada cartão leva a uma área que confere a própria permissão.
	 * O cliente fica de fora de propósito: ele tem a home e o menu, que já são a
	 * vitrine. O Super Administrador ficou de fora até ganhar o cartão "Painel
	 * Admin": antes a tela não tinha como levá-lo ao `/wp-admin`, que é a casa dele.
	 *
	 * @param int $usuario_id Usuário; 0 usa o corrente.
	 * @return bool
	 */
	public static function escolhe_modulo( $usuario_id = 0 ) {
		$usuario_id = $usuario_id ? (int) $usuario_id : get_current_user_id();

		if ( ! $usuario_id ) {
			return false;
		}

		return Reconectar_Permissoes::eh_vendedor( $usuario_id )
			|| Reconectar_Permissoes::eh_admin_de_empresas( $usuario_id )
			|| Reconectar_Permissoes::eh_moderador_de_conteudo( $usuario_id )
			|| self::eh_super_administrador( $usuario_id );
	}

	/**
	 * O usuário tem o papel `administrator`?
	 *
	 * Pelo papel, e não por `manage_options`: a capacidade pode ser concedida a
	 * outro papel, e o cartão "Painel Admin" é do Super Administrador. O mesmo
	 * teste de `reconectar_login_do_super_administrador()`, no tema.
	 *
	 * @param int $usuario_id ID do usuário.
	 * @return bool
	 */
	private static function eh_super_administrador( $usuario_id ) {
		$usuario = get_userdata( $usuario_id );

		return $usuario && in_array( 'administrator', (array) $usuario->roles, true );
	}

	/**
	 * Recusa quem não escolhe módulo, antes de qualquer saída.
	 *
	 * Prioridade 9: antes de `Reconectar_Navegacao_Da_Loja::levar_moderador_aos_modulos()`
	 * e do portão do Dokan (11), que não têm o que decidir aqui mas não precisam
	 * ser consultados. Ninguém recebe 403: quem chega sem login vai ao formulário
	 * e volta, e quem não tem a escolha vai para a casa dele.
	 *
	 * @return void
	 */
	public static function proteger() {
		if ( ! self::eh_a_tela() ) {
			return;
		}

		if ( ! is_user_logged_in() ) {
			wp_safe_redirect( wp_login_url( self::url() ) );
			exit;
		}

		if ( self::escolhe_modulo() ) {
			nocache_headers();
			return;
		}

		wp_safe_redirect( Reconectar_Permissoes::entra_no_painel_wp() ? admin_url() : home_url( '/' ) );
		exit;
	}

	/**
	 * Troca o template da tela pelo do plugin.
	 *
	 * @param string $template Template escolhido pelo tema.
	 * @return string
	 */
	public static function template( $template ) {
		if ( ! self::eh_a_tela() || ! self::escolhe_modulo() ) {
			return $template;
		}

		return RECONECTAR_CORE_PATH . 'includes/modulos/tela.php';
	}

	/**
	 * Põe "Módulos" no título da aba.
	 *
	 * Sem isto a rota, que não tem post, sairia só com o nome do site — e o
	 * título da aba é a primeira coisa que o leitor de tela anuncia.
	 *
	 * @param array $partes Partes do título.
	 * @return array
	 */
	public static function titulo_do_documento( $partes ) {
		if ( self::eh_a_tela() ) {
			$partes['title'] = __( 'Módulos', 'reconectar-core' );
		}

		return $partes;
	}

	/**
	 * Enfileira o estilo da tela, só nela.
	 *
	 * Sem dependência do Bootstrap do tema, ao contrário do painel de empresas: a
	 * tela é uma grade de três cartões, e exigir o tema por isso não compensa.
	 *
	 * @return void
	 */
	public static function enfileirar_assets() {
		if ( ! self::eh_a_tela() ) {
			return;
		}

		wp_enqueue_style(
			'reconectar-modulos',
			RECONECTAR_CORE_URL . 'assets/css/modulos.css',
			array(),
			'0.2.0'
		);
	}

	/**
	 * Leva à tela, depois do login, quem escolhe módulo.
	 *
	 * Serve aos dois formulários: o de "Minha conta" (`woocommerce_login_redirect`,
	 * dois argumentos) e o `wp-login.php` (`login_redirect`, três). O usuário é
	 * sempre o último.
	 *
	 * Só substitui o destino **padrão** de cada caminho — a home, "Minha conta",
	 * o `/wp-admin` e o `/dashboard/` que o Dokan impõe à loja. Um destino
	 * explícito vence: quem clicou num link do fórum deslogado volta ao fórum, e
	 * quem entrou pelo checkout volta ao checkout.
	 *
	 * O `/dashboard/` entra na lista porque o Dokan o põe ali em prioridade 1 para
	 * toda loja que não trouxe `redirect_to`; deixá-lo de fora faria a tela nunca
	 * aparecer para justamente o perfil mais numeroso.
	 *
	 * @param string $destino  URL decidida até aqui.
	 * @param mixed  ...$resto O usuário, por último; no `login_redirect` vem antes
	 *                         dele o destino pedido.
	 * @return string
	 */
	public static function depois_do_login( $destino, ...$resto ) {
		$usuario = end( $resto );

		if ( ! $usuario instanceof WP_User || ! self::escolhe_modulo( $usuario->ID ) ) {
			return $destino;
		}

		$padroes = array( '', Reconectar_Permissoes::caminho_de_url( admin_url() ) );

		if ( function_exists( 'wc_get_page_permalink' ) ) {
			$padroes[] = Reconectar_Permissoes::caminho_de_url( wc_get_page_permalink( 'myaccount' ) );
		}

		if ( function_exists( 'dokan_get_navigation_url' ) ) {
			$padroes[] = Reconectar_Permissoes::caminho_de_url( dokan_get_navigation_url() );
		}

		if ( ! in_array( Reconectar_Permissoes::caminho_de_url( (string) $destino ), $padroes, true ) ) {
			return $destino;
		}

		return self::url();
	}

	/**
	 * Põe "Módulos" no topo do menu da conta.
	 *
	 * É o caminho de volta para quem saiu da tela pela vitrine: o menu da conta
	 * é o único que os três perfis têm em comum fora da moldura do painel.
	 *
	 * @param array $itens Itens do menu, no formato `chave => rótulo`.
	 * @return array
	 */
	public static function item_da_conta( $itens ) {
		if ( ! self::escolhe_modulo() ) {
			return $itens;
		}

		return array( self::ITEM_DA_CONTA => __( 'Módulos', 'reconectar-core' ) ) + $itens;
	}

	/**
	 * Aponta o item "Módulos" da conta para a tela.
	 *
	 * O menu da conta monta todo link por `wc_get_account_endpoint_url()`, e sem
	 * este filtro a chave viraria um endpoint inexistente — o item levaria ao 404.
	 *
	 * @param string $url      URL montada pelo WooCommerce.
	 * @param string $endpoint Chave do item.
	 * @return string
	 */
	public static function url_do_item_da_conta( $url, $endpoint ) {
		return self::ITEM_DA_CONTA === $endpoint ? self::url() : $url;
	}

	/**
	 * Os cartões da tela, na ordem do edital, com o destino de cada perfil.
	 *
	 * O Mercado é o único que muda de destino: para a Loja é a vitrine dela no
	 * mercado (`/store/<loja>/`), para o Administrador o painel de empresas, e
	 * para o Moderador — que não vende nem administra loja — a vitrine geral. A
	 * Loja já apontou para o próprio `/dashboard/`, e quem escolhia "Mercado"
	 * esperava o mercado; o painel fica a um clique, no ícone da conta e no item
	 * "Painel" da barra lateral. A Incubadora e a Praça são as mesmas para os
	 * três; a moldura em que abrem é decidida por `Reconectar_Navegacao_Da_Loja`.
	 *
	 * O cartão de trabalho vem por último, depois dos módulos do edital: "Painel"
	 * para a Loja e "Painel Admin" para o Super Administrador. Administrador e
	 * Moderador não o recebem — o painel do Administrador é o de empresas, que já
	 * é o Mercado dele, e o Moderador não tem painel próprio.
	 *
	 * Um destino vazio **não** some com o cartão: ele sai sem link e dizendo por
	 * quê. Esconder um módulo inteiro em silêncio é o defeito que a Transparência
	 * já teve, e aqui ele apagaria um terço da tela sem uma linha de erro.
	 *
	 * @return array<int, array{chave:string, titulo:string, subtitulo:string, frase:string, botao:string, url:string}>
	 */
	public static function cartoes() {
		$usuario_id = get_current_user_id();
		$destinos   = Reconectar_Navegacao_Da_Loja::destinos();

		if ( Reconectar_Permissoes::eh_vendedor( $usuario_id ) && function_exists( 'dokan_get_store_url' ) ) {
			$mercado = array(
				'frase' => __( 'Seus produtos e serviços como o comprador os vê no mercado.', 'reconectar-core' ),
				'botao' => __( 'Ver minha loja', 'reconectar-core' ),
				'url'   => dokan_get_store_url( $usuario_id ),
			);
		} elseif ( Reconectar_Permissoes::eh_admin_de_empresas( $usuario_id ) && class_exists( 'Reconectar_Painel_Empresas' ) && '' !== Reconectar_Painel_Empresas::url() ) {
			$mercado = array(
				'frase' => __( 'As empresas e as lojas que você acompanha, com a operação de cada uma.', 'reconectar-core' ),
				'botao' => __( 'Abrir painel de empresas', 'reconectar-core' ),
				'url'   => Reconectar_Painel_Empresas::url(),
			);
		} else {
			$mercado = array(
				'frase' => __( 'O catálogo de produtos e serviços das lojas da rede.', 'reconectar-core' ),
				'botao' => __( 'Ir ao mercado', 'reconectar-core' ),
				'url'   => function_exists( 'wc_get_page_permalink' ) ? wc_get_page_permalink( 'shop' ) : home_url( '/' ),
			);
		}

		// A Praça abre pela Comunidade, que é a página de entrada dela; o Fórum
		// fica a um clique na barra lateral. Sem a Comunidade, o Fórum sozinho
		// ainda é a Praça.
		$praca = '' !== $destinos['comunidade'] ? $destinos['comunidade'] : $destinos['forum'];

		$cartoes = array(
			array_merge(
				array(
					'chave'     => 'mercado',
					'titulo'    => __( 'Mercado', 'reconectar-core' ),
					'subtitulo' => __( 'Produtos e serviços', 'reconectar-core' ),
				),
				$mercado
			),
			array(
				'chave'     => 'incubadora',
				'titulo'    => __( 'Incubadora', 'reconectar-core' ),
				'subtitulo' => __( 'Conhecimento e empreendedorismo', 'reconectar-core' ),
				'frase'     => __( 'Guias, materiais e capacitação para quem empreende.', 'reconectar-core' ),
				'botao'     => __( 'Abrir a Incubadora', 'reconectar-core' ),
				'url'       => $destinos['incubadora'],
			),
			array(
				'chave'     => 'praca',
				'titulo'    => __( 'Praça', 'reconectar-core' ),
				'subtitulo' => __( 'Comunidade e memória', 'reconectar-core' ),
				'frase'     => __( 'Fórum, conversas e as histórias do lugar.', 'reconectar-core' ),
				'botao'     => __( 'Entrar na Praça', 'reconectar-core' ),
				'url'       => $praca,
			),
		);

		if ( Reconectar_Permissoes::eh_vendedor( $usuario_id ) ) {
			$cartoes[] = array(
				'chave'     => 'painel',
				'titulo'    => __( 'Painel', 'reconectar-core' ),
				'subtitulo' => __( 'Gestão da loja', 'reconectar-core' ),
				'frase'     => __( 'Pedidos, produtos, saques e as configurações da sua loja.', 'reconectar-core' ),
				'botao'     => __( 'Abrir o painel', 'reconectar-core' ),
				'url'       => function_exists( 'dokan_get_navigation_url' ) ? dokan_get_navigation_url() : '',
			);
		} elseif ( self::eh_super_administrador( $usuario_id ) ) {
			$cartoes[] = array(
				'chave'     => 'painel-admin',
				'titulo'    => __( 'Painel Admin', 'reconectar-core' ),
				'subtitulo' => __( 'Administração da plataforma', 'reconectar-core' ),
				'frase'     => __( 'Usuários, plugins, configurações e o conteúdo da plataforma inteira.', 'reconectar-core' ),
				'botao'     => __( 'Abrir o Painel Admin', 'reconectar-core' ),
				'url'       => admin_url(),
			);
		}

		return $cartoes;
	}

	/**
	 * Ilustração de um cartão, em SVG inline e autoral.
	 *
	 * Inline e não arquivo: são quatro desenhos pequenos, e cada um como imagem
	 * custaria uma requisição na tela que é a primeira depois do login. Decorativa
	 * — o título do cartão já diz o que ela mostra —, por isso `aria-hidden`.
	 *
	 * Os dois cartões de trabalho dividem o desenho: são o mesmo tipo de lugar,
	 * e a cor do cartão e o título já os distinguem.
	 *
	 * @param string $chave `mercado`, `incubadora`, `praca`, `painel` ou `painel-admin`.
	 * @return string SVG, ou vazio para chave desconhecida.
	 */
	public static function ilustracao( $chave ) {
		$desenhos = array(
			// Uma banca de feira: toldo listrado, balcão e três caixas de produto.
			'mercado'    => '<path d="M28 58h144l-10-30H38z" fill="#fff"/>'
				. '<path d="M52 28h16l-4 30H44zM84 28h16v30H84zM132 28h16l8 30h-20z" fill="#F1BF3D"/>'
				. '<path d="M28 58c0 9 8 14 18 14s18-5 18-14c0 9 8 14 18 14s18-5 18-14c0 9 8 14 18 14s18-5 18-14c0 9 8 14 18 14s18-5 18-14" fill="none" stroke="#fff" stroke-width="4"/>'
				. '<rect x="40" y="76" width="120" height="66" rx="4" fill="#fff" fill-opacity=".25"/>'
				. '<rect x="34" y="138" width="132" height="10" rx="3" fill="#fff"/>'
				. '<rect x="52" y="108" width="28" height="30" rx="3" fill="#F1BF3D"/>'
				. '<rect x="86" y="96" width="28" height="42" rx="3" fill="#fff"/>'
				. '<rect x="120" y="112" width="28" height="26" rx="3" fill="#F1BF3D"/>',
			// Um livro aberto com um broto saindo das páginas.
			'incubadora' => '<path d="M100 60c-20-12-46-14-66-8v84c20-6 46-4 66 8z" fill="#fff"/>'
				. '<path d="M100 60c20-12 46-14 66-8v84c-20-6-46-4-66 8z" fill="#fff" fill-opacity=".8"/>'
				. '<path d="M48 76c14-3 28-2 40 3M48 92c14-3 28-2 40 3M48 108c14-3 28-2 40 3M112 79c12-5 26-6 40-3M112 95c12-5 26-6 40-3M112 111c12-5 26-6 40-3" stroke="#663191" stroke-opacity=".35" stroke-width="3" stroke-linecap="round"/>'
				. '<path d="M100 60V22" stroke="#F1BF3D" stroke-width="5" stroke-linecap="round"/>'
				. '<path d="M100 40c-4-12-16-18-28-14 2 12 14 18 28 14zM100 32c4-12 16-18 28-14-2 12-14 18-28 14z" fill="#F1BF3D"/>',
			// Uma árvore de praça, um banco e dois balões de conversa.
			'praca'      => '<circle cx="62" cy="58" r="30" fill="#fff" fill-opacity=".9"/>'
				. '<rect x="57" y="78" width="10" height="56" rx="3" fill="#fff"/>'
				. '<rect x="92" y="108" width="80" height="8" rx="3" fill="#fff"/>'
				. '<rect x="92" y="94" width="80" height="6" rx="3" fill="#fff" fill-opacity=".7"/>'
				. '<path d="M100 116v18M164 116v18" stroke="#fff" stroke-width="6" stroke-linecap="round"/>'
				. '<path d="M98 30h40a8 8 0 0 1 8 8v16a8 8 0 0 1-8 8h-26l-10 10V62h-4a8 8 0 0 1-8-8V38a8 8 0 0 1 8-8z" fill="#F1BF3D"/>'
				. '<path d="M146 52h26a7 7 0 0 1 7 7v12a7 7 0 0 1-7 7h-2v8l-8-8h-16a7 7 0 0 1-7-7V59a7 7 0 0 1 7-7z" fill="#fff"/>'
				. '<path d="M34 134h150" stroke="#fff" stroke-width="4" stroke-linecap="round"/>',
			// Uma janela de painel: barra de título e um gráfico de colunas.
			'painel'     => '<rect x="30" y="24" width="140" height="112" rx="8" fill="#fff" fill-opacity=".25"/>'
				. '<path d="M38 24h124a8 8 0 0 1 8 8v12H30V32a8 8 0 0 1 8-8z" fill="#fff"/>'
				. '<circle cx="44" cy="34" r="3.5" fill="#F1BF3D"/><circle cx="56" cy="34" r="3.5" fill="#F1BF3D" fill-opacity=".6"/>'
				. '<rect x="50" y="96" width="18" height="28" rx="3" fill="#fff"/>'
				. '<rect x="78" y="76" width="18" height="48" rx="3" fill="#F1BF3D"/>'
				. '<rect x="106" y="88" width="18" height="36" rx="3" fill="#fff"/>'
				. '<rect x="134" y="60" width="18" height="64" rx="3" fill="#F1BF3D"/>'
				. '<path d="M42 128h118" stroke="#fff" stroke-width="4" stroke-linecap="round"/>',
		);

		if ( 'painel-admin' === $chave ) {
			$chave = 'painel';
		}

		if ( ! isset( $desenhos[ $chave ] ) ) {
			return '';
		}

		return '<svg class="rc-modulos__ilustracao" viewBox="0 0 200 160" aria-hidden="true" focusable="false">' . $desenhos[ $chave ] . '</svg>';
	}
}
