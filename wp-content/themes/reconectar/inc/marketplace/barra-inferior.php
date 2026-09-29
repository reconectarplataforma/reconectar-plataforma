<?php
/**
 * Barra de navegação inferior do celular.
 *
 * Quatro atalhos fixos no rodapé da tela — Início, Buscar, Pedidos e Perfil —,
 * mais um quinto, "Votar", que só existe enquanto houver enquete aberta.
 * Visíveis apenas abaixo de 768px: em telas largas o CSS a esconde, porque lá o
 * cabeçalho já exibe busca, conta e carrinho na mesma linha, e uma segunda barra
 * seria redundância ocupando altura útil.
 *
 * O condicional entra pela **ponta**, depois de "Perfil", e não no meio: assim
 * os quatro fixos nunca trocam de lugar quando uma enquete é publicada ou
 * encerrada. Posição de ícone em barra inferior é memória muscular, e movê-la
 * sob o polegar de quem já aprendeu o caminho custa mais do que a ordem ideal
 * rende.
 *
 * Ela **não** substitui o menu `<details>` do cabeçalho. São coisas diferentes:
 * o menu lista as páginas que a administração cadastrou em Aparência → Menus, e
 * muda de site para site; estes atalhos são as ações da plataforma, e não
 * dependem de configuração nenhuma para existir.
 *
 * @package reconectar
 */

defined( 'ABSPATH' ) || exit;

/**
 * Diz se uma URL aponta para a página que está sendo exibida.
 *
 * A comparação é de **caminho**, e não dos condicionais do WooCommerce, porque
 * os três destinos possíveis de "Pedidos" e "Perfil" vivem em mundos diferentes:
 * `is_account_page()` e `is_wc_endpoint_url()` só enxergam as telas do Woo, e
 * dentro do painel do Dokan ou do painel de empresas devolvem `false` sempre.
 * Marcar o item por condicional deixaria a barra sem página atual justamente
 * para quem mais navega nela — vendedor e administrador de empresas.
 *
 * Comparar caminho também resolve de graça a sobreposição que exigia um teste
 * extra: `/dashboard/` é prefixo de `/dashboard/orders/`, mas a igualdade exata
 * casa com um só dos dois, e nunca há dois itens marcados ao mesmo tempo.
 *
 * @param string $url URL de destino do item.
 * @return bool
 */
function reconectar_url_e_a_pagina_atual( $url ) {
	static $caminho_atual = null;

	if ( null === $caminho_atual ) {
		$requisicao    = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '/';
		$caminho_atual = trailingslashit( (string) wp_parse_url( $requisicao, PHP_URL_PATH ) );
	}

	$caminho_alvo = wp_parse_url( $url, PHP_URL_PATH );

	// Âncora pura (`#rc-busca-campo`) não tem caminho, e `trailingslashit( '' )`
	// devolveria `/` — o que marcaria "Buscar" como atual em toda a home.
	if ( empty( $caminho_alvo ) ) {
		return false;
	}

	return trailingslashit( $caminho_alvo ) === $caminho_atual;
}

/**
 * Monta os itens da barra inferior.
 *
 * Separado da impressão porque cada destino depende de quem está olhando, e a
 * decisão é longa o bastante para não caber no meio do markup.
 *
 * O destino de "Perfil" repete deliberadamente a regra de
 * `reconectar_atalho_de_conta()`: os dois são a mesma porta, e divergir faria o
 * mesmo ícone levar a lugares diferentes conforme a largura da tela.
 *
 * @return array Lista de itens, cada um com `url`, `rotulo`, `icone` e `atual`,
 *               e opcionalmente `selo` e `nome` (o nome acessível do link).
 */
function reconectar_itens_da_barra_inferior() {
	$conta_url   = function_exists( 'wc_get_page_permalink' ) ? wc_get_page_permalink( 'myaccount' ) : home_url( '/' );
	$pedidos_url = $conta_url;
	$eh_vendedor = function_exists( 'dokan_is_user_seller' ) && dokan_is_user_seller( get_current_user_id() );

	if ( $eh_vendedor && function_exists( 'dokan_get_navigation_url' ) ) {
		// O vendedor tem uma lista de pedidos própria, a do painel do Dokan.
		// Mandá-lo para a conta do WooCommerce mostraria as compras que ele fez
		// como cliente — zero, no caso comum — em vez das vendas que recebeu.
		$pedidos_url = dokan_get_navigation_url( 'orders' );
		$perfil_url  = dokan_get_navigation_url();
		$perfil_nome = __( 'Minha loja', 'reconectar' );
	} elseif ( class_exists( 'Reconectar_Painel_Empresas' )
		&& current_user_can( Reconectar_Permissoes::CAP_PAINEL_EMPRESAS )
		&& '' !== Reconectar_Painel_Empresas::url() ) {
		$perfil_url  = Reconectar_Painel_Empresas::url();
		$perfil_nome = __( 'Painel', 'reconectar' );
	} else {
		if ( function_exists( 'wc_get_account_endpoint_url' ) ) {
			$pedidos_url = wc_get_account_endpoint_url( 'orders' );
		}

		$perfil_url  = $conta_url;
		$perfil_nome = is_user_logged_in() ? __( 'Perfil', 'reconectar' ) : __( 'Entrar', 'reconectar' );
	}

	// Colhidos antes do envelope de login: é com estes caminhos que a marcação de
	// página atual compara. Depois de `wp_login_url()` os dois viram
	// `/wp-login.php` e nenhum item voltaria a acender.
	$pedidos_destino = $pedidos_url;
	$perfil_destino  = $perfil_url;

	// Sem sessão, os dois atalhos de área logada passam pelo login e voltam ao
	// destino pedido. A alternativa seria um link que leva a uma tela de login
	// sem retorno, e o usuário teria de refazer o caminho à mão depois de entrar.
	if ( ! is_user_logged_in() ) {
		$pedidos_url = wp_login_url( $pedidos_url );
		$perfil_url  = wp_login_url( $perfil_url );
	}

	$itens = array(
		array(
			'url'    => home_url( '/' ),
			'rotulo' => __( 'Início', 'reconectar' ),
			'icone'  => 'inicio',
			'atual'  => is_front_page(),
		),
		array(
			// Âncora, e não página: a busca do marketplace mora no cabeçalho e
			// não tem tela própria. Navegar para o fragmento leva o foco ao
			// campo nos navegadores que seguem a especificação, e nos demais
			// ainda rola até ele — que é o essencial do gesto.
			'url'    => '#rc-busca-campo',
			'rotulo' => __( 'Buscar', 'reconectar' ),
			'icone'  => 'buscar',
			'atual'  => false,
		),
		array(
			'url'    => $pedidos_url,
			'rotulo' => __( 'Pedidos', 'reconectar' ),
			'icone'  => 'pedidos',
			'atual'  => reconectar_url_e_a_pagina_atual( $pedidos_destino ),
		),
		array(
			'url'    => $perfil_url,
			'rotulo' => $perfil_nome,
			'icone'  => 'perfil',
			'atual'  => reconectar_url_e_a_pagina_atual( $perfil_destino ),
		),
	);

	/*
	 * "Votar" só existe enquanto houver enquete aceitando voto. Um item
	 * permanente levaria ao painel de transparência sem cédula nenhuma — link que
	 * abre uma tela plausível e errada, que é o defeito mais caro deste
	 * repositório.
	 *
	 * O destino é conferido antes: `url()` devolve vazio quando a página
	 * `transparencia` não existe publicada, e `href=""` aponta para a página atual.
	 *
	 * Sem envelope de login, ao contrário de "Pedidos" e "Perfil": o painel é
	 * público, e a cédula lá dentro é que convida a entrar. Mandar quem só quer
	 * ler o resultado para uma tela de login seria cobrar sessão por leitura.
	 */
	if ( function_exists( 'reconectar_ha_enquete_aberta' )
		&& reconectar_ha_enquete_aberta()
		&& class_exists( 'Reconectar_Painel_Transparencia' ) ) {

		$votacao_url = Reconectar_Painel_Transparencia::url();

		if ( '' !== $votacao_url ) {
			$pendentes = reconectar_enquetes_pendentes();
			$rotulo    = __( 'Votar', 'reconectar' );

			$itens[] = array(
				'url'    => $votacao_url,
				'rotulo' => $rotulo,
				'icone'  => 'votar',
				'atual'  => reconectar_url_e_a_pagina_atual( $votacao_url ),
				'selo'   => $pendentes,
				'nome'   => reconectar_nome_do_atalho_de_enquete( $rotulo, $pendentes ),
			);
		}
	}

	return $itens;
}

/**
 * Imprime o ícone de um item da barra inferior.
 *
 * Os traçados ficam aqui, e não em um sprite ou em `background-image`, para
 * herdarem `currentColor`: é assim que o item ativo muda de cor junto com o
 * rótulo, sem um segundo arquivo para manter em sincronia.
 *
 * @param string $nome Identificador do ícone.
 */
function reconectar_icone_da_barra_inferior( $nome ) {
	$tracados = array(
		'inicio'  => '<path d="M3 10.5 12 3l9 7.5"></path><path d="M5.5 9.5V20h13V9.5"></path>',
		'buscar'  => '<circle cx="11" cy="11" r="7"></circle><path d="m20 20-3.5-3.5"></path>',
		'pedidos' => '<path d="M6 3h12v18l-3-2-3 2-3-2-3 2z"></path><path d="M9 8h6"></path><path d="M9 12h6"></path>',
		'perfil'  => '<circle cx="12" cy="8" r="4"></circle><path d="M4 21a8 8 0 0 1 16 0"></path>',
		/*
		 * Urna com a cédula marcada saindo por cima — e não a cédula sozinha, que
		 * é o desenho óbvio: com o traçado de 2px numa caixa de 22px ela ficaria
		 * quase idêntica ao marcador de "Pedidos", dois itens ao lado. Dois ícones
		 * parecidos na mesma barra é pior que um ícone feio.
		 *
		 * A caixa é `path`, não `rect`: o `wp_kses` logo abaixo permite apenas
		 * `path[d]` e `circle[cx,cy,r]`, e ampliar a allowlist por comodidade de um
		 * traçado abriria a porta para o próximo.
		 */
		'votar'   => '<path d="M4 12h16v9H4z"></path><path d="M8 12V4h8v8"></path><path d="m10 8 1.5 1.5L15 6"></path>',
	);

	if ( ! isset( $tracados[ $nome ] ) ) {
		return;
	}
	?>
	<svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" focusable="false" aria-hidden="true">
		<?php echo wp_kses( $tracados[ $nome ], array( 'path' => array( 'd' => array() ), 'circle' => array( 'cx' => array(), 'cy' => array(), 'r' => array() ) ) ); ?>
	</svg>
	<?php
}

/**
 * Imprime a barra de navegação inferior.
 *
 * O rótulo da região é "Navegação rápida", e não "Navegação principal": este
 * último já pertence ao menu do cabeçalho, e duas regiões de mesmo nome na
 * mesma página deixam quem navega por landmarks sem como escolher entre elas.
 *
 * O item atual é marcado com `aria-current="page"` — nunca `aria-pressed`, que
 * não vale em `<a>`.
 *
 * O `aria-label` só é escrito quando o item traz `nome`, e não em todos: um
 * rótulo repetido em `aria-label` não acrescenta nada e ainda quebra o comando
 * de voz, que casa pelo texto visível.
 */
function reconectar_barra_inferior() {
	$itens = reconectar_itens_da_barra_inferior();
	?>
	<nav class="rc-barra-inferior" aria-label="<?php esc_attr_e( 'Navegação rápida', 'reconectar' ); ?>">
		<ul class="rc-barra-inferior__lista">
			<?php foreach ( $itens as $item ) : ?>
				<li class="rc-barra-inferior__item">
					<a
						class="rc-barra-inferior__link"
						href="<?php echo esc_url( $item['url'] ); ?>"
						<?php echo $item['atual'] ? ' aria-current="page"' : ''; ?>
						<?php
						if ( ! empty( $item['nome'] ) && $item['nome'] !== $item['rotulo'] ) {
							printf( ' aria-label="%s"', esc_attr( $item['nome'] ) );
						}
						?>
					>
						<span class="rc-barra-inferior__icone">
							<?php
							reconectar_icone_da_barra_inferior( $item['icone'] );

							if ( ! empty( $item['selo'] ) && function_exists( 'reconectar_selo_de_enquete' ) ) {
								reconectar_selo_de_enquete( 'rc-barra-inferior__selo', (int) $item['selo'] );
							}
							?>
						</span>
						<span class="rc-barra-inferior__rotulo"><?php echo esc_html( $item['rotulo'] ); ?></span>
					</a>
				</li>
			<?php endforeach; ?>
		</ul>
	</nav>
	<?php
}
