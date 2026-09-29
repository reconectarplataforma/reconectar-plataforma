<?php
/**
 * Acrescenta ao rodapé de uma instalação já provisionada os links que faltam.
 *
 * Executa por `wp eval-file`, a partir do `provision.sh`:
 *
 *     wp eval-file /var/www/scripts/reparar-rodape.php
 *
 * As colunas do rodapé só são escritas pelo `provision.sh` quando estão vazias, e
 * é isso que mantêm o script idempotente e preservam o que o administrador editar
 * depois. O efeito colateral é o mesmo do menu: uma instalação provisionada antes
 * de uma página existir nunca recebe o link dela — a guarda vê a coluna
 * preenchida e pula, para sempre.
 *
 * Foi assim que a Transparência ficou de fora do rodapé de produção, junto com
 * "Loja" e "Minha conta": as duas eram procuradas pelo slug em inglês, que só
 * existe na instalação nascida em inglês. Este arquivo alcança essas colunas.
 *
 * O reparo é **aditivo e cirúrgico**: insere apenas o `<li>` que falta, antes do
 * `</ul>` final, e não toca em mais nada. Reescrever a coluna inteira seria
 * simples e desfaria a edição do administrador, que é justamente o que a guarda
 * de idempotência existe para proteger. O item entra no fim da lista — o mesmo
 * preço já aceito no reparo do menu, onde reordenar é um arrasto no painel.
 *
 * Idempotente: um caminho já presente no HTML não é reinserido.
 *
 * @package reconectar
 */

defined( 'ABSPATH' ) || exit;

/**
 * Devolve o caminho público de uma página publicada, ou string vazia.
 *
 * O retorno é caminho, e não URL absoluta, pela razão que `normalizar-urls.php`
 * documenta: o HTML do widget fica gravado como texto e não acompanha o `WP_HOME`
 * que varia por requisição.
 *
 * @param int $pagina_id ID da página.
 * @return string Caminho com barras nas pontas, ou '' se a página não serve.
 */
function reconectar_caminho_de_pagina_do_rodape( $pagina_id ) {
	$pagina_id = (int) $pagina_id;

	if ( $pagina_id < 1 || 'publish' !== get_post_status( $pagina_id ) ) {
		return '';
	}

	$url = get_permalink( $pagina_id );

	return $url ? wp_make_link_relative( $url ) : '';
}

/**
 * Devolve o caminho de uma página pelo slug.
 *
 * Só serve para página que **nós** ou o Dokan criamos: as do WooCommerce nascem
 * com o slug no idioma ativo na ativação e têm de ser resolvidas pela opção de
 * ID — veja `reconectar_id_de_pagina_do_woo()`, no `provision.sh`.
 *
 * @param string $slug Slug da página.
 * @return string Caminho, ou '' se ela não existir.
 */
function reconectar_caminho_por_slug_do_rodape( $slug ) {
	$pagina = get_page_by_path( $slug );

	return $pagina ? reconectar_caminho_de_pagina_do_rodape( $pagina->ID ) : '';
}

/**
 * Insere num HTML de lista os itens cujo caminho ainda não aparece nele.
 *
 * A presença é conferida pelo caminho seguido de aspa (`/loja/"`), e não pelo
 * caminho solto: assim o teste casa tanto com `href="/loja/"` quanto com um
 * `href="http://localhost:8090/loja/"` que tenha sobrado de antes, sem casar por
 * engano com o prefixo de um caminho mais longo.
 *
 * @param string $conteudo HTML atual do widget.
 * @param array  $itens    Pares caminho => rótulo.
 * @return array {string HTML resultante, int quantidade inserida}.
 */
function reconectar_completar_lista_do_rodape( $conteudo, $itens ) {
	$fecha = strrpos( $conteudo, '</ul>' );

	/*
	 * Sem `</ul>` o administrador trocou a lista por outra coisa. Inserir `<li>`
	 * solto ali produziria HTML inválido numa coluna que alguém escreveu à mão —
	 * melhor sair sem link do que sair com o rodapé quebrado.
	 */
	if ( false === $fecha ) {
		return array( $conteudo, 0 );
	}

	$novos = '';
	$total = 0;

	foreach ( $itens as $caminho => $rotulo ) {
		if ( '' === $caminho || false !== strpos( $conteudo, $caminho . '"' ) ) {
			continue;
		}

		$novos .= sprintf(
			'<li><a href="%s">%s</a></li>',
			esc_url( $caminho ),
			esc_html( $rotulo )
		);
		++$total;
	}

	if ( 0 === $total ) {
		return array( $conteudo, 0 );
	}

	return array( substr_replace( $conteudo, $novos, $fecha, 0 ), $total );
}

/*
 * O widget de cada coluna é encontrado pela sidebar, e não pelo índice da opção:
 * `widget_custom_html` guarda todos os widgets de HTML da instalação juntos, e o
 * de índice 2 pode estar em qualquer lugar — inclusive numa barra lateral que
 * nada tem a ver com o rodapé.
 */
$colunas = array(
	'reconectar-rodape-2' => array(
		reconectar_caminho_de_pagina_do_rodape( wc_get_page_id( 'shop' ) )      => 'Loja',
		reconectar_caminho_por_slug_do_rodape( 'store-listing' )                => 'Lojas parceiras',
		reconectar_caminho_por_slug_do_rodape( 'comunidade' )                   => 'Comunidade',
		reconectar_caminho_por_slug_do_rodape( 'transparencia' )                => 'Transparência',
	),
	'reconectar-rodape-3' => array(
		reconectar_caminho_por_slug_do_rodape( 'vendor-onboarding' )            => 'Quero vender',
		reconectar_caminho_por_slug_do_rodape( 'dashboard' )                    => 'Painel da loja',
		reconectar_caminho_de_pagina_do_rodape( wc_get_page_id( 'myaccount' ) ) => 'Minha conta',
		reconectar_caminho_por_slug_do_rodape( 'my-orders' )                    => 'Meus pedidos',
	),
);

$widgets   = get_option( 'widget_custom_html' );
$atribuidos = wp_get_sidebars_widgets();
$inseridos = 0;

if ( is_array( $widgets ) ) {
	$alterou = false;

	foreach ( $colunas as $sidebar => $itens ) {
		if ( empty( $atribuidos[ $sidebar ] ) || ! is_array( $atribuidos[ $sidebar ] ) ) {
			continue;
		}

		foreach ( $atribuidos[ $sidebar ] as $widget_id ) {
			if ( 0 !== strpos( $widget_id, 'custom_html-' ) ) {
				continue;
			}

			$indice = (int) substr( $widget_id, strlen( 'custom_html-' ) );

			if ( ! isset( $widgets[ $indice ]['content'] ) ) {
				continue;
			}

			list( $conteudo, $total ) = reconectar_completar_lista_do_rodape(
				$widgets[ $indice ]['content'],
				$itens
			);

			if ( $total > 0 ) {
				$widgets[ $indice ]['content'] = $conteudo;
				$alterou                       = true;
				$inseridos                    += $total;
			}
		}
	}

	if ( $alterou ) {
		update_option( 'widget_custom_html', $widgets );
	}
}

WP_CLI::log(
	sprintf( 'Rodapé: %d link(s) acrescentado(s) às colunas já preenchidas.', $inseridos )
);
