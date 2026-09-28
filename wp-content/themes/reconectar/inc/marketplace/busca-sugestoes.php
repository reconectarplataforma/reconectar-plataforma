<?php
/**
 * Sugestões da busca do cabeçalho.
 *
 * Endpoint REST consumido por `assets/js/busca.js` enquanto o usuário digita.
 * Devolve duas listas curtas — produtos e lojas — porque o placeholder do campo
 * promete as duas coisas ("Busque por item ou loja") e, até aqui, só a primeira
 * era encontrável: quem digitava o nome de uma loja recebia a página de
 * resultados de produtos, sem nenhuma menção à loja procurada.
 *
 * O endpoint não é um segundo motor de busca: ele responde o que a página de
 * resultados responderia, apenas antes e em menor quantidade. Toda regra de
 * "quais lojas existem" continua em `consultas.php`.
 *
 * @package reconectar
 */

defined( 'ABSPATH' ) || exit;

/**
 * Quantidade de sugestões por seção.
 */
const RECONECTAR_SUGESTOES_PRODUTOS = 6;
const RECONECTAR_SUGESTOES_LOJAS    = 4;

/**
 * Menor termo que dispara uma consulta.
 *
 * Com uma letra só, qualquer catálogo devolve quase tudo — uma lista que não
 * ajuda a escolher e custa uma consulta a cada tecla.
 */
const RECONECTAR_SUGESTOES_MINIMO = 2;

/**
 * Registra a rota das sugestões.
 *
 * @return void
 */
function reconectar_registrar_rota_sugestoes() {
	register_rest_route(
		'reconectar/v1',
		'/sugestoes',
		array(
			'methods'  => WP_REST_Server::READABLE,
			'callback' => 'reconectar_responder_sugestoes',

			/*
			 * Aberto de propósito: a busca do site é pública e este endpoint não
			 * devolve nada que a página de resultados já não mostre a qualquer
			 * visitante. Exigir nonce aqui quebraria a busca em página servida
			 * por cache — que é como o site roda em produção.
			 */
			'permission_callback' => '__return_true',
			'args'                => array(
				'termo' => array(
					'required'          => true,
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
					'validate_callback' => 'reconectar_validar_termo_de_sugestao',
				),
			),
		)
	);
}
add_action( 'rest_api_init', 'reconectar_registrar_rota_sugestoes' );

/**
 * O termo tem tamanho suficiente para valer uma consulta?
 *
 * @param string $valor Termo recebido.
 * @return bool|WP_Error Verdadeiro, ou o erro que vira 400 na resposta.
 */
function reconectar_validar_termo_de_sugestao( $valor ) {
	if ( mb_strlen( trim( (string) $valor ) ) < RECONECTAR_SUGESTOES_MINIMO ) {
		return new WP_Error(
			'reconectar_termo_curto',
			sprintf(
				/* translators: %d: número mínimo de caracteres. */
				__( 'Digite ao menos %d caracteres.', 'reconectar' ),
				RECONECTAR_SUGESTOES_MINIMO
			),
			array( 'status' => 400 )
		);
	}

	return true;
}

/**
 * Monta a resposta das sugestões.
 *
 * @param WP_REST_Request $requisicao Requisição.
 * @return WP_REST_Response
 */
function reconectar_responder_sugestoes( WP_REST_Request $requisicao ) {
	$termo = trim( (string) $requisicao->get_param( 'termo' ) );

	/*
	 * O cache é curto porque catálogo de marketplace muda ao longo do dia:
	 * produto esgotado ou loja desativada precisam sumir da sugestão sem
	 * esperar. Cinco minutos cobrem a rajada de teclas de uma mesma busca, que
	 * é o caso que realmente pesa.
	 *
	 * O host entra na chave porque a resposta carrega URL absoluta de produto,
	 * de loja e de miniatura, e a mesma instalação responde por `localhost:8090`
	 * e pelo IP da máquina na rede — `WP_HOME` é calculada a partir do `Host` da
	 * requisição. Sem isso, quem abrisse pelo celular receberia sugestões
	 * apontando para `localhost`, que no celular é o próprio celular.
	 */
	$chave = 'reconectar_sugestoes_' . md5( mb_strtolower( $termo ) . home_url() );
	$cache = get_transient( $chave );

	if ( is_array( $cache ) ) {
		return rest_ensure_response( $cache );
	}

	$resposta = array(
		'termo'     => $termo,
		'produtos'  => reconectar_sugerir_produtos( $termo ),
		'lojas'     => reconectar_sugerir_lojas( $termo ),
		'url_todos' => add_query_arg(
			array(
				's'         => rawurlencode( $termo ),
				'post_type' => 'product',
			),
			home_url( '/' )
		),
	);

	set_transient( $chave, $resposta, 5 * MINUTE_IN_SECONDS );

	return rest_ensure_response( $resposta );
}

/**
 * Produtos que casam com o termo.
 *
 * `WP_Query`, e não `wc_get_products()`: as APIs de alto nível do WooCommerce
 * reconhecem uma lista fechada de argumentos e descartam em silêncio o que está
 * fora dela — o mesmo defeito que já custou caro neste repositório com
 * `wc_get_orders()` (veja o CLAUDE.md). Uma consulta "filtrada" que na verdade
 * devolve o catálogo inteiro passa despercebida quando o limite é pequeno.
 *
 * O `tax_query` de visibilidade precisa ser explícito: quem o aplicaria é o
 * `WC_Query`, e ele só age na consulta principal do frontend. Sem essa cláusula,
 * um produto marcado como oculto da busca apareceria aqui — justamente no lugar
 * onde ninguém procuraria o vazamento.
 *
 * @param string $termo Termo buscado.
 * @return array[] Itens com `titulo`, `url`, `preco`, `loja` e `imagem`.
 */
function reconectar_sugerir_produtos( $termo ) {
	$argumentos = array(
		'post_type'              => 'product',
		'post_status'            => 'publish',
		's'                      => $termo,
		'posts_per_page'         => RECONECTAR_SUGESTOES_PRODUTOS,
		'no_found_rows'          => true,
		'ignore_sticky_posts'    => true,
		'update_post_term_cache' => false,
	);

	if ( function_exists( 'wc_get_product_visibility_term_ids' ) ) {
		$visibilidade = wc_get_product_visibility_term_ids();

		if ( ! empty( $visibilidade['exclude-from-search'] ) ) {
			$argumentos['tax_query'] = array(
				array(
					'taxonomy' => 'product_visibility',
					'field'    => 'term_taxonomy_id',
					'terms'    => array( $visibilidade['exclude-from-search'] ),
					'operator' => 'NOT IN',
				),
			);
		}
	}

	$consulta = new WP_Query( $argumentos );
	$itens    = array();

	foreach ( $consulta->posts as $post ) {
		$produto = function_exists( 'wc_get_product' ) ? wc_get_product( $post->ID ) : null;

		$itens[] = array(
			'titulo' => get_the_title( $post ),
			'url'    => get_permalink( $post ),
			'preco'  => reconectar_preco_em_texto( $produto ),
			'loja'   => reconectar_nome_da_loja_do_produto( (int) $post->post_author ),
			'imagem' => (string) get_the_post_thumbnail_url( $post, 'thumbnail' ),
		);
	}

	return $itens;
}

/**
 * Preço do produto como texto puro, para uma linha de sugestão.
 *
 * Montado a partir do valor, e **não** de `get_price_html()`: aquele método
 * embute avisos destinados a leitor de tela em `<span class="screen-reader-text">`,
 * e `wp_strip_all_tags()` tira as tags mas conserva o texto. Um produto em
 * promoção saía assim, medido na demonstração:
 *
 *     R$ 64,00 O preço original era: R$ 64,00.R$ 54,00O preço atual é: R$ 54,00.
 *
 * O JS monta cada item com `textContent`, nunca `innerHTML` — nome e preço são
 * texto de terceiro —, então o valor precisa chegar aqui já legível.
 *
 * @param WC_Product|null $produto Produto, ou null quando o WooCommerce está fora.
 * @return string Vazio quando o produto não tem preço cadastrado.
 */
function reconectar_preco_em_texto( $produto ) {
	if ( ! $produto || ! function_exists( 'wc_price' ) || '' === $produto->get_price() ) {
		return '';
	}

	$preco = wp_strip_all_tags( wc_price( wc_get_price_to_display( $produto ) ) );

	/*
	 * Produto variável reporta o menor preço da faixa. Sem o "a partir de", esse
	 * número viraria a promessa de que qualquer variação custa isso — e o
	 * desmentido só apareceria na página do produto.
	 */
	if ( $produto->is_type( 'variable' )
		&& $produto->get_variation_price( 'min' ) !== $produto->get_variation_price( 'max' ) ) {
		$preco = sprintf(
			/* translators: %s: menor preço da faixa, já formatado. */
			__( 'a partir de %s', 'reconectar' ),
			$preco
		);
	}

	return html_entity_decode( $preco, ENT_QUOTES, 'UTF-8' );
}

/**
 * Nome da loja dona do produto.
 *
 * Passa por `reconectar_normalizar_loja()` para não criar uma segunda definição
 * de "loja existente": lá está a guarda contra o Dokan ausente e a recusa de
 * vendedor sem `store_name`.
 *
 * @param int $autor_id ID do autor do produto, que no Dokan é o vendedor.
 * @return string Vazio quando não há loja identificável.
 */
function reconectar_nome_da_loja_do_produto( $autor_id ) {
	$loja = reconectar_normalizar_loja( $autor_id );

	return $loja ? $loja['nome'] : '';
}

/**
 * Lojas cujo nome contém o termo.
 *
 * O filtro é em PHP, sobre a lista que `reconectar_obter_lojas()` já monta, pelo
 * mesmo motivo documentado lá: nome de loja não é coluna consultável, é meta do
 * usuário. A função também é o que garante que só loja ativa e com nome
 * cadastrado chegue à sugestão.
 *
 * `remove_accents()` nos dois lados para que "acai" encontre "Açaí" — quem
 * digita com pressa na barra de busca quase nunca acentua.
 *
 * @param string $termo Termo buscado.
 * @return array[] Itens com `nome`, `url`, `cidade` e `imagem`.
 */
function reconectar_sugerir_lojas( $termo ) {
	$lojas = reconectar_obter_lojas(
		array(
			'numero'  => 200,
			'ordenar' => 'nome',
		)
	);

	$procurado = mb_strtolower( remove_accents( $termo ) );
	$itens     = array();

	foreach ( $lojas as $loja ) {
		if ( count( $itens ) >= RECONECTAR_SUGESTOES_LOJAS ) {
			break;
		}

		$nome = mb_strtolower( remove_accents( $loja['nome'] ) );

		if ( false === mb_strpos( $nome, $procurado ) ) {
			continue;
		}

		$itens[] = array(
			'nome'   => $loja['nome'],
			'url'    => $loja['url'],
			'cidade' => $loja['cidade'],
			'imagem' => $loja['logo_id']
				? (string) wp_get_attachment_image_url( (int) $loja['logo_id'], 'thumbnail' )
				: '',
		);
	}

	return $itens;
}
