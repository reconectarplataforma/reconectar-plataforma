<?php
/**
 * Consultas do marketplace.
 *
 * Todo acesso a dados de loja e de catálogo que as telas da vitrine precisam
 * passa por aqui. O motivo é a fronteira com o Dokan: as funções dele
 * (`dokan_get_sellers`, `dokan_get_store_info`, `dokan_get_seller_rating`) são
 * de um plugin de terceiro que pode ser desativado, atualizado ou substituído,
 * e nenhum arquivo de apresentação deveria quebrar por causa disso.
 *
 * Concentrando as chamadas em um arquivo, cada uma fica com sua guarda
 * `function_exists()` e um retorno vazio previsível — e a troca do Dokan por
 * outro motor de marketplace vira a reescrita deste arquivo, não uma caçada
 * pelos templates.
 *
 * @package reconectar
 */

defined( 'ABSPATH' ) || exit;

/**
 * Lista as lojas ativas da plataforma, já com os dados que os cards exibem.
 *
 * @param array $args {
 *     Argumentos opcionais.
 *
 *     @type int    $numero     Quantidade máxima de lojas. Padrão 12.
 *     @type int    $pagina     Página da listagem, base 1. Padrão 1.
 *     @type string $categoria  Slug de `product_cat` para filtrar. Padrão vazio.
 *     @type string $cidade     Nome do município para filtrar. Padrão vazio.
 *     @type bool   $so_gratis  Só lojas com entrega gratuita. Padrão false.
 *     @type string $ordenar    `avaliacao`, `nome` ou `recentes`. Padrão `avaliacao`.
 * }
 * @return array[] Lista de lojas normalizadas por `reconectar_normalizar_loja()`.
 */
function reconectar_obter_lojas( $args = array() ) {
	if ( ! function_exists( 'dokan_get_sellers' ) ) {
		return array();
	}

	$args = wp_parse_args(
		$args,
		array(
			'numero'    => 12,
			'pagina'    => 1,
			'categoria' => '',
			'cidade'    => '',
			'busca'     => '',
			'so_gratis' => false,
			'ordenar'   => 'avaliacao',
		)
	);

	/*
	 * Nem a ordenação por avaliação nem os filtros de categoria, cidade e
	 * entrega grátis podem ser delegados ao Dokan: a nota é uma média calculada
	 * a partir dos comentários dos produtos, a categoria é deduzida do catálogo
	 * e a taxa mora em meta do usuário. Nenhum deles é coluna consultável.
	 *
	 * Por isso a lista inteira é carregada e tratada em PHP, com a paginação
	 * aplicada só no fim. Isso é aceitável na escala desta plataforma (dezenas
	 * de lojas de economia solidária em Alagoas) e o cache de um minuto abaixo
	 * evita refazer o trabalho a cada visita. Se um dia forem milhares de lojas,
	 * o caminho é uma tabela de índice própria — não remendar esta função.
	 */
	/*
	 * O host entra na chave porque cada item da lista carrega a URL absoluta da
	 * loja, e a mesma instalação responde por `localhost:8090` e pelo IP da
	 * máquina na rede — `WP_HOME` é calculada a partir do `Host` da requisição.
	 * Sem o host aqui, o cache gravado num acesso serviria links do outro, e o
	 * sintoma seria dos piores de diagnosticar: intermitente, porque desaparece
	 * sozinho quando o transient de um minuto expira.
	 */
	$chave_cache = 'reconectar_lojas_' . md5( wp_json_encode( $args ) . home_url() );
	$cache       = get_transient( $chave_cache );

	if ( is_array( $cache ) ) {
		return $cache;
	}

	$resultado = dokan_get_sellers( array( 'number' => 200 ) );

	// `dokan_get_sellers()` devolve `array( 'users' => [...], 'count' => n )`.
	$usuarios = isset( $resultado['users'] ) ? $resultado['users'] : array();
	$lojas    = array();

	foreach ( $usuarios as $usuario ) {
		$loja = reconectar_normalizar_loja( $usuario->ID );

		if ( ! $loja || ! reconectar_loja_passa_nos_filtros( $loja, $args ) ) {
			continue;
		}

		$lojas[] = $loja;
	}

	$lojas = reconectar_ordenar_lojas( $lojas, $args['ordenar'] );

	$numero = max( 1, (int) $args['numero'] );
	$lojas  = array_slice( $lojas, ( max( 1, (int) $args['pagina'] ) - 1 ) * $numero, $numero );

	set_transient( $chave_cache, $lojas, MINUTE_IN_SECONDS );

	return $lojas;
}

/**
 * Aplica os filtros da barra de pílulas a uma loja já normalizada.
 *
 * @param array $loja Loja normalizada.
 * @param array $args Argumentos de `reconectar_obter_lojas()`.
 * @return bool
 */
function reconectar_loja_passa_nos_filtros( $loja, $args ) {
	if ( ! empty( $args['categoria'] ) && $args['categoria'] !== $loja['categoria_slug'] ) {
		return false;
	}

	if ( ! empty( $args['busca'] ) && ! reconectar_nome_casa_com_busca( $loja['nome'], $args['busca'] ) ) {
		return false;
	}

	if ( ! empty( $args['cidade'] ) && sanitize_title( $args['cidade'] ) !== sanitize_title( $loja['cidade'] ) ) {
		return false;
	}

	if ( ! empty( $args['so_gratis'] ) ) {
		$taxa = $loja['entrega']['taxa'];

		// Loja sem taxa cadastrada não entra no filtro de entrega grátis:
		// "não informado" não é promessa de gratuidade, e prometer frete zero
		// que depois será cobrado no carrinho é o pior resultado possível.
		if ( '' === $taxa || (float) str_replace( ',', '.', $taxa ) > 0 ) {
			return false;
		}
	}

	return true;
}

/**
 * Diz se o nome de uma loja atende ao termo buscado.
 *
 * A comparação ignora caixa e acento nos dois lados. Não é preciosismo: quem
 * digita "raizes" no celular, sem parar para achar o til, está procurando o
 * "Ateliê Raízes" — e uma busca que devolve vazio nesse caso é lida como "a
 * loja não existe", não como "faltou um acento".
 *
 * `remove_accents()` é do núcleo do WordPress e trabalha sobre a tabela de
 * caracteres do idioma ativo, o que a torna mais confiável aqui que qualquer
 * transliteração escrita à mão. `mb_strtolower()` vem depois porque a função do
 * núcleo preserva a caixa.
 *
 * @param string $nome  Nome da loja.
 * @param string $termo Termo buscado.
 * @return bool
 */
function reconectar_nome_casa_com_busca( $nome, $termo ) {
	$nome  = mb_strtolower( remove_accents( $nome ) );
	$termo = mb_strtolower( remove_accents( $termo ) );

	return '' !== $termo && false !== mb_strpos( $nome, $termo );
}

/**
 * Municípios distintos em que há lojas cadastradas.
 *
 * Alimenta o filtro de município da home. A lista sai do que existe no
 * banco em vez de uma relação fixa de municípios: assim o filtro nunca oferece
 * uma cidade que devolveria vitrine vazia.
 *
 * @return string[] Nomes de municípios, em ordem alfabética.
 */
function reconectar_obter_cidades() {
	$cache = get_transient( 'reconectar_cidades_lojas' );

	if ( is_array( $cache ) ) {
		return $cache;
	}

	$cidades = array();

	foreach ( reconectar_obter_lojas( array( 'numero' => 200 ) ) as $loja ) {
		if ( ! empty( $loja['cidade'] ) ) {
			$cidades[ sanitize_title( $loja['cidade'] ) ] = $loja['cidade'];
		}
	}

	$cidades = array_values( $cidades );
	sort( $cidades, SORT_LOCALE_STRING );

	set_transient( 'reconectar_cidades_lojas', $cidades, HOUR_IN_SECONDS );

	return $cidades;
}

/**
 * Ordena a lista de lojas já normalizadas.
 *
 * @param array[] $lojas   Lojas normalizadas.
 * @param string  $criterio Critério de ordenação.
 * @return array[]
 */
function reconectar_ordenar_lojas( $lojas, $criterio ) {
	switch ( $criterio ) {
		case 'nome':
			usort(
				$lojas,
				static function ( $a, $b ) {
					return strcoll( $a['nome'], $b['nome'] );
				}
			);
			break;

		case 'avaliacao':
			usort(
				$lojas,
				static function ( $a, $b ) {
					// Loja sem nota fica no fim da lista junto com as de nota
					// baixa. Não é ideal — "ainda não avaliada" não é o mesmo
					// que "mal avaliada" —, mas a alternativa (empurrar loja
					// nova para cima) premiaria quem nunca vendeu.
					if ( $a['nota'] === $b['nota'] ) {
						return 0;
					}

					return $a['nota'] > $b['nota'] ? -1 : 1;
				}
			);
			break;
	}

	return $lojas;
}

/**
 * Reúne, em um array plano, tudo o que um card de loja precisa exibir.
 *
 * Os templates recebem só este array. Eles não chamam o Dokan, não sabem que
 * `dokan_profile_settings` existe e não precisam saber onde cada informação
 * está guardada.
 *
 * @param int $vendedor_id ID do usuário vendedor.
 * @return array|null Dados da loja, ou null se o vendedor não tiver loja utilizável.
 */
function reconectar_normalizar_loja( $vendedor_id ) {
	if ( ! function_exists( 'dokan_get_store_info' ) ) {
		return null;
	}

	$vendedor_id = (int) $vendedor_id;
	$info        = dokan_get_store_info( $vendedor_id );

	if ( empty( $info['store_name'] ) ) {
		return null;
	}

	$categoria = reconectar_categoria_principal_da_loja( $vendedor_id );

	return array(
		'id'             => $vendedor_id,
		'nome'           => $info['store_name'],
		'url'            => function_exists( 'dokan_get_store_url' ) ? dokan_get_store_url( $vendedor_id ) : '',
		'logo_id'        => isset( $info['gravatar'] ) ? (int) $info['gravatar'] : 0,
		'banner_id'      => isset( $info['banner'] ) ? (int) $info['banner'] : 0,
		'nota'           => reconectar_nota_da_loja( $vendedor_id ),
		'categoria'      => $categoria ? $categoria->name : '',
		'categoria_slug' => $categoria ? $categoria->slug : '',
		'cidade'         => isset( $info['address']['city'] ) ? $info['address']['city'] : '',
		'entrega'        => reconectar_dados_de_entrega( $vendedor_id ),
	);
}

/**
 * Nota média da loja, de 0 a 5.
 *
 * @param int $vendedor_id ID do vendedor.
 * @return float 0.0 quando a loja ainda não tem avaliação.
 */
function reconectar_nota_da_loja( $vendedor_id ) {
	if ( ! function_exists( 'dokan_get_seller_rating' ) ) {
		return 0.0;
	}

	$avaliacao = dokan_get_seller_rating( $vendedor_id );

	// A chave `rating` vem como string e, em loja sem avaliação, pode vir nula.
	return isset( $avaliacao['rating'] ) ? (float) $avaliacao['rating'] : 0.0;
}

/**
 * Categoria de produto mais frequente entre os produtos publicados da loja.
 *
 * O Dokan tem um campo de "categoria da loja", mas ele é opcional e fica vazio
 * na maioria dos cadastros. Deduzir a categoria do que a loja de fato vende dá
 * um resultado correto sem exigir preenchimento de ninguém.
 *
 * @param int $vendedor_id ID do vendedor.
 * @return WP_Term|null
 */
function reconectar_categoria_principal_da_loja( $vendedor_id ) {
	$chave = 'reconectar_cat_loja_' . (int) $vendedor_id;
	$cache = get_transient( $chave );

	if ( false !== $cache ) {
		return $cache ? get_term( (int) $cache ) : null;
	}

	$produtos = get_posts(
		array(
			'post_type'              => 'product',
			'post_status'            => 'publish',
			'author'                 => (int) $vendedor_id,
			'posts_per_page'         => 20,
			'fields'                 => 'ids',
			'no_found_rows'          => true,
			'update_post_meta_cache' => false,
		)
	);

	$contagem = array();

	foreach ( $produtos as $produto_id ) {
		foreach ( wp_get_post_terms( $produto_id, 'product_cat', array( 'fields' => 'ids' ) ) as $termo_id ) {
			$contagem[ $termo_id ] = isset( $contagem[ $termo_id ] ) ? $contagem[ $termo_id ] + 1 : 1;
		}
	}

	$termo_id = 0;

	if ( $contagem ) {
		arsort( $contagem );
		$termo_id = (int) key( $contagem );
	}

	// Uma hora é curto o bastante para um produto novo aparecer refletido no
	// card no mesmo expediente, e longo o bastante para a home não refazer
	// dezenas de consultas a cada visita.
	set_transient( $chave, $termo_id, HOUR_IN_SECONDS );

	return $termo_id ? get_term( $termo_id ) : null;
}

/**
 * Informações de entrega exibidas no card da loja.
 *
 * Tempo, taxa e distância **não** são calculados: são lidos de metas do
 * vendedor. A plataforma ainda não tem integração de logística nem cálculo de
 * rota — e exibir um número inventado como se fosse cálculo real seria mentir
 * para o cliente. Estas metas são preenchidas pela carga de demonstração
 * (`scripts/seed/demo.php`) justamente para que a vitrine possa ser avaliada
 * com os cards completos.
 *
 * Quando a meta não existe, a chave volta vazia e o template simplesmente não
 * desenha aquela linha do card.
 *
 * @param int $vendedor_id ID do vendedor.
 * @return array{tempo:string,taxa:string,distancia:string}
 */
function reconectar_dados_de_entrega( $vendedor_id ) {
	$tempo     = get_user_meta( $vendedor_id, '_reconectar_tempo_entrega', true );
	$taxa      = get_user_meta( $vendedor_id, '_reconectar_taxa_entrega', true );
	$distancia = get_user_meta( $vendedor_id, '_reconectar_distancia', true );

	return array(
		'tempo'     => $tempo ? (string) $tempo : '',
		'taxa'      => '' !== $taxa ? (string) $taxa : '',
		'distancia' => $distancia ? (string) $distancia : '',
	);
}

/**
 * Categorias de produto para o carrossel da home.
 *
 * Só as de primeiro nível. O catálogo passou a ter subcategorias — é delas que a
 * página de loja monta as seções —, e sem o recorte por `parent` elas subiriam
 * para o carrossel da home misturadas às suas próprias mães: "Doces" ao lado de
 * "Alimentos e Bebidas", como se fossem escolhas do mesmo nível.
 *
 * @param int $numero Quantidade máxima. Padrão 14.
 * @return WP_Term[]
 */
function reconectar_obter_categorias( $numero = 14 ) {
	$termos = get_terms(
		array(
			'taxonomy'   => 'product_cat',
			'hide_empty' => true,
			'number'     => (int) $numero,
			'orderby'    => 'count',
			'order'      => 'DESC',
			'parent'     => 0,
		)
	);

	return is_wp_error( $termos ) ? array() : $termos;
}

/**
 * Produtos em destaque da vitrine.
 *
 * Prefere os marcados como "em destaque" no WooCommerce e completa a lista com
 * os mais recentes quando não houver destaques suficientes — assim a faixa
 * nunca aparece pela metade em uma loja que ainda não configurou destaques.
 *
 * O município é o **da loja**, o mesmo que a vitrine compara: o produto não
 * tem endereço próprio, e o recorte sai dos autores que
 * `reconectar_obter_lojas()` devolve para aquela cidade. Assim a faixa de
 * produtos e a de lojas nunca discordam sobre quem está em qual município.
 *
 * @param int    $numero Quantidade desejada.
 * @param string $cidade Nome do município das lojas. Vazio não filtra.
 * @return WC_Product[]
 */
function reconectar_obter_produtos_destaque( $numero = 8, $cidade = '' ) {
	if ( ! function_exists( 'wc_get_products' ) ) {
		return array();
	}

	$numero = (int) $numero;
	$filtro = array();

	if ( '' !== $cidade ) {
		$autores = array_map(
			static function ( $loja ) {
				return $loja['id'];
			},
			reconectar_obter_lojas(
				array(
					'numero' => 200,
					'cidade' => $cidade,
				)
			)
		);

		/*
		 * Sem loja no município, nada a listar — e a guarda não é opcional:
		 * `author__in` vazio é ignorado pela `WP_Query`, e a faixa sairia com os
		 * produtos de todas as cidades sob um filtro que diz o contrário.
		 *
		 * `author__in` não está na lista de argumentos documentados do
		 * `wc_get_products()`, e a armadilha do `wc_get_orders()` manda
		 * desconfiar. Medido: chega à consulta — 17, 8, 8 e 8 produtos por
		 * município, os mesmos números de uma `WP_Query` com o mesmo argumento.
		 */
		if ( ! $autores ) {
			return array();
		}

		$filtro['author__in'] = $autores;
	}

	$destaques = wc_get_products(
		array(
			'status'   => 'publish',
			'limit'    => $numero,
			'featured' => true,
		) + $filtro
	);

	if ( count( $destaques ) >= $numero ) {
		return $destaques;
	}

	/*
	 * `wp_list_pluck()` leria `$produto->get_id` como propriedade, e em
	 * `WC_Product` isso cai no `__get()` mágico: devolve null e registra um
	 * aviso de uso indevido. Os IDs precisam vir da chamada ao método.
	 */
	$ja_listados = array_map(
		static function ( $produto ) {
			return $produto->get_id();
		},
		$destaques
	);

	$complemento = wc_get_products(
		array(
			'status'  => 'publish',
			'limit'   => $numero - count( $destaques ),
			'orderby' => 'date',
			'order'   => 'DESC',
			'exclude' => $ja_listados,
		) + $filtro
	);

	return array_merge( $destaques, $complemento );
}

/**
 * Teto de produtos carregados de uma vez na página de loja.
 *
 * Acima dele a página abandona o agrupamento por categoria e volta à lista
 * paginada do Dokan: agrupar um catálogo que não cabe na página produziria
 * seções truncadas sem nenhum aviso de que faltam itens.
 */
const RECONECTAR_PRODUTOS_POR_LOJA = 200;

/**
 * Produtos publicados de uma loja.
 *
 * `WP_Query` e não `wc_get_products()`: as APIs de alto nível do WooCommerce
 * reconhecem uma lista fechada de argumentos e descartam em silêncio o que está
 * fora dela — o defeito que já custou caro aqui com `wc_get_orders()` (veja o
 * CLAUDE.md).
 *
 * O `tax_query` de visibilidade precisa ser explícito pelo mesmo motivo já
 * registrado em `reconectar_sugerir_produtos()`: quem aplicaria a exclusão é o
 * `WC_Query`, e ele só age na consulta principal do frontend. Esta não é.
 *
 * @param int    $vendedor_id ID do vendedor, que no Dokan é o autor do produto.
 * @param string $busca       Termo digitado na busca do catálogo. Opcional.
 * @return WC_Product[] Vazio quando o WooCommerce está fora ou a loja não vende nada.
 */
function reconectar_produtos_da_loja( $vendedor_id, $busca = '' ) {
	if ( ! function_exists( 'wc_get_product' ) ) {
		return array();
	}

	$argumentos = array(
		'post_type'           => 'product',
		'post_status'         => 'publish',
		'author'              => (int) $vendedor_id,
		'posts_per_page'      => RECONECTAR_PRODUTOS_POR_LOJA,
		'orderby'             => 'title',
		'order'               => 'ASC',
		'no_found_rows'       => true,
		'ignore_sticky_posts' => true,
	);

	if ( '' !== trim( (string) $busca ) ) {
		$argumentos['s'] = trim( (string) $busca );
	}

	if ( function_exists( 'wc_get_product_visibility_term_ids' ) ) {
		$visibilidade = wc_get_product_visibility_term_ids();

		if ( ! empty( $visibilidade['exclude-from-catalog'] ) ) {
			$argumentos['tax_query'] = array(
				array(
					'taxonomy' => 'product_visibility',
					'field'    => 'term_taxonomy_id',
					'terms'    => array( $visibilidade['exclude-from-catalog'] ),
					'operator' => 'NOT IN',
				),
			);
		}
	}

	$consulta = new WP_Query( $argumentos );
	$produtos = array();

	foreach ( $consulta->posts as $post ) {
		$produto = wc_get_product( $post->ID );

		if ( $produto ) {
			$produtos[] = $produto;
		}
	}

	return $produtos;
}

/**
 * Agrupa os produtos da loja em seções de catálogo.
 *
 * Cada produto cai na sua **subcategoria** — o termo com `parent > 0` —, porque
 * é ela que descreve o item dentro daquela loja: numa padaria, "Doces" e
 * "Salgados" separam o cardápio, enquanto "Alimentos e Bebidas" vale para tudo
 * que está à venda e não separa nada. A carga atribui as duas a cada produto
 * (veja `reconectar_demo_criar_produto()`), e é justamente a mãe que mantém o
 * filtro por categoria da vitrine funcionando.
 *
 * Produto que só tem a categoria-mãe forma um grupo com o nome dela, colocado
 * por último — é o resto do cardápio, não a primeira coisa a mostrar.
 *
 * Quando o produto está em mais de uma subcategoria, vale a primeira em ordem
 * alfabética: repeti-lo em cada seção faria o mesmo item aparecer duas vezes na
 * mesma página, e quem rolasse leria como se fossem produtos diferentes.
 *
 * @param WC_Product[] $produtos Produtos da loja.
 * @return array[] Grupos com `nome`, `slug` e `produtos`, prontos para o template.
 */
function reconectar_agrupar_produtos_por_categoria( $produtos ) {
	$secoes = array();
	$restos = array();

	foreach ( $produtos as $produto ) {
		$termos = wp_get_post_terms( $produto->get_id(), 'product_cat' );

		if ( is_wp_error( $termos ) ) {
			$termos = array();
		}

		$subcategorias = array_filter(
			$termos,
			static function ( $termo ) {
				return $termo->parent > 0;
			}
		);

		if ( ! $subcategorias ) {
			$mae = reset( $termos );

			$chave = $mae ? $mae->slug : 'sem-categoria';

			if ( ! isset( $restos[ $chave ] ) ) {
				$restos[ $chave ] = array(
					'nome'     => $mae ? $mae->name : __( 'Outros produtos', 'reconectar' ),
					'slug'     => $chave,
					'produtos' => array(),
				);
			}

			$restos[ $chave ]['produtos'][] = $produto;
			continue;
		}

		usort(
			$subcategorias,
			static function ( $a, $b ) {
				return strnatcasecmp( $a->name, $b->name );
			}
		);

		$escolhida = reset( $subcategorias );

		if ( ! isset( $secoes[ $escolhida->slug ] ) ) {
			$secoes[ $escolhida->slug ] = array(
				'nome'     => $escolhida->name,
				'slug'     => $escolhida->slug,
				'produtos' => array(),
			);
		}

		$secoes[ $escolhida->slug ]['produtos'][] = $produto;
	}

	/*
	 * Ordem alfabética, e não por quantidade: assim a posição de uma seção não
	 * muda quando a loja cadastra um produto novo, e quem já visitou a página
	 * encontra "Bebidas" onde encontrou da última vez.
	 */
	uasort(
		$secoes,
		static function ( $a, $b ) {
			return strnatcasecmp( $a['nome'], $b['nome'] );
		}
	);

	return array_values( array_merge( $secoes, $restos ) );
}

/**
 * Produtos da loja que merecem a faixa de destaques.
 *
 * O recorte é objetivo: em promoção ou marcado como destaque no WooCommerce.
 * Loja sem nenhum dos dois devolve lista vazia e a seção inteira não é impressa
 * — eleger um produto qualquer como "destaque" seria inventar uma curadoria que
 * a loja não fez.
 *
 * @param WC_Product[] $produtos Produtos da loja.
 * @return WC_Product[]
 */
function reconectar_destaques_da_loja( $produtos ) {
	$destaques = array();

	foreach ( $produtos as $produto ) {
		if ( $produto->is_on_sale() || $produto->is_featured() ) {
			$destaques[] = $produto;
		}
	}

	return $destaques;
}
