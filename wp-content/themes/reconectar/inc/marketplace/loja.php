<?php
/**
 * Página de detalhe de uma loja.
 *
 * Toda a montagem da página vive aqui; `dokan/store.php` é só o ponto em que o
 * Dokan entrega o controle ao tema. A separação existe porque aquele arquivo é
 * um override de template de terceiro — quanto menos código ele tiver, menor a
 * chance de uma mudança futura do plugin exigir reescrevê-lo.
 *
 * A sidebar do Dokan não é chamada. O que ela oferecia — endereço, telefone e o
 * formulário de contato com o vendedor — migrou para o painel "Ver mais" do
 * cabeçalho, onde ocupa espaço só quando alguém pede. Nada se perdeu: o
 * formulário continua sendo o widget do próprio plugin, com o nonce e a ação
 * dele.
 *
 * @package reconectar
 */

defined( 'ABSPATH' ) || exit;

/**
 * Esconde a barra de filtros que o Dokan imprime dentro da página da loja.
 *
 * `Product\Hooks::store_products_orderby()` se pendura em
 * `dokan_store_profile_frame_after` — o mesmo gancho que este tema dispara, de
 * propósito, para não quebrar integrações — e imprime um segundo campo
 * `product_name` logo abaixo do nosso, com o rótulo "Enter product name", o
 * botão "Search" e a ordenação "Default sorting". Três problemas de uma vez:
 * está em inglês num site `pt-BR`, duplica a busca que a barra de controles já
 * oferece, e dois campos com o mesmo `name` na mesma página confundem tanto o
 * visitante quanto o leitor de tela.
 *
 * A supressão é feita pela porta que o próprio plugin abriu: a opção
 * `hide_product_filter`, que aquele método consulta antes de imprimir qualquer
 * coisa (`Product/Hooks.php:191`). Filtrar a opção em vez de chamar
 * `remove_action()` evita depender do container de injeção do Dokan para
 * alcançar a instância da classe — um detalhe interno que uma atualização pode
 * reorganizar sem aviso.
 *
 * A chave é aninhada: o plugin lê `dokan_get_option( 'store_products',
 * 'dokan_appearance' )` e só então procura `hide_product_filter` no sub-array
 * devolvido. Escrever a chave na raiz de `dokan_appearance` não surte efeito
 * nenhum — e não gera erro, o que torna o engano difícil de notar.
 *
 * O filtro age **apenas** na página de loja. Em qualquer outra tela a opção
 * volta ao valor que o administrador gravou, e o painel de aparência do Dokan
 * continua mandando no que é dele.
 *
 * O que se perde junto é o seletor de ordenação, que vinha na mesma barra. É
 * deliberado: ele também está em inglês, o protótipo não o prevê, e o catálogo
 * da loja agora sai agrupado por seção — uma ordenação global reordenaria
 * dentro de cada seção sem que isso ficasse evidente na tela.
 *
 * @param mixed $valor Valor gravado da opção `dokan_appearance`.
 * @return mixed Valor com `hide_product_filter` ligado, na página de loja.
 */
function reconectar_ocultar_filtro_do_dokan( $valor ) {
	if ( ! function_exists( 'dokan_is_store_page' ) || ! dokan_is_store_page() ) {
		return $valor;
	}

	if ( ! is_array( $valor ) ) {
		$valor = array();
	}

	if ( ! isset( $valor['store_products'] ) || ! is_array( $valor['store_products'] ) ) {
		$valor['store_products'] = array();
	}

	$valor['store_products']['hide_product_filter'] = 'on';

	return $valor;
}
add_filter( 'option_dokan_appearance', 'reconectar_ocultar_filtro_do_dokan' );

/**
 * Imprime a página inteira de uma loja.
 *
 * O parâmetro é o objeto `Vendor` que o Dokan resolve no template, e não um
 * `WP_User`: ele guarda o ID em `$id` minúsculo e expõe `get_id()`. Ler `->ID`
 * nele devolveria null em silêncio, porque a classe tem `__get()` mágico e ele
 * responde null para qualquer propriedade inexistente.
 *
 * @param WP_User|object|int $vendedor Vendedor dono da loja, ou o ID dele.
 * @return void
 */
function reconectar_pagina_de_loja( $vendedor ) {
	if ( is_object( $vendedor ) && method_exists( $vendedor, 'get_id' ) ) {
		$vendedor_id = (int) $vendedor->get_id();
	} elseif ( isset( $vendedor->ID ) ) {
		$vendedor_id = (int) $vendedor->ID;
	} else {
		$vendedor_id = (int) $vendedor;
	}

	$loja = reconectar_normalizar_loja( $vendedor_id );

	if ( ! $loja ) {
		?>
		<p class="rc-loja__aviso">
			<?php esc_html_e( 'Esta loja não está disponível no momento.', 'reconectar' ); ?>
		</p>
		<?php
		return;
	}

	reconectar_loja_cabecalho( $loja );

	/*
	 * O gancho do Dokan fica exatamente onde ficava no template original: logo
	 * depois do cabeçalho de perfil. Extensões do plugin penduram abas e avisos
	 * nele, e mudar a posição as faria aparecer no meio do catálogo.
	 */
	$dados = is_object( $vendedor ) && isset( $vendedor->data ) ? $vendedor->data : get_userdata( $vendedor_id );
	do_action( 'dokan_store_profile_frame_after', $dados, dokan_get_store_info( $loja['id'] ) );

	reconectar_loja_controles( $loja );
	reconectar_loja_catalogo( $loja );
}

/**
 * Banner, logo, nome, nota e o painel "Ver mais".
 *
 * @param array $loja Loja normalizada por `reconectar_normalizar_loja()`.
 * @return void
 */
function reconectar_loja_cabecalho( $loja ) {
	$info      = function_exists( 'dokan_get_store_info' ) ? dokan_get_store_info( $loja['id'] ) : array();
	$descricao = get_the_author_meta( 'description', $loja['id'] );
	$telefone  = isset( $info['phone'] ) ? $info['phone'] : '';
	$endereco  = reconectar_loja_endereco_em_linha( $info );
	?>
	<header class="rc-loja__cabecalho">
		<div class="rc-loja__banner<?php echo empty( $loja['banner_id'] ) ? ' rc-loja__banner--vazio' : ''; ?>">
			<?php if ( ! empty( $loja['banner_id'] ) ) : ?>
				<?php
				/*
				 * Sem `loading="lazy"`: o banner é o maior elemento visível na
				 * abertura da página, e adiar o carregamento dele é adiar o
				 * próprio LCP.
				 */
				echo wp_get_attachment_image(
					(int) $loja['banner_id'],
					'large',
					false,
					array(
						'alt'           => '',
						'fetchpriority' => 'high',
					)
				);
				?>
			<?php endif; ?>
		</div>

		<div class="rc-loja__identificacao">
			<div class="rc-loja__logo">
				<?php if ( ! empty( $loja['logo_id'] ) ) : ?>
					<?php
					echo wp_get_attachment_image(
						(int) $loja['logo_id'],
						'thumbnail',
						false,
						array( 'alt' => '' )
					);
					?>
				<?php else : ?>
					<span class="rc-loja__inicial" aria-hidden="true">
						<?php echo esc_html( mb_substr( $loja['nome'], 0, 1 ) ); ?>
					</span>
				<?php endif; ?>
			</div>

			<div class="rc-loja__titulo">
				<h1 class="rc-loja__nome"><?php echo esc_html( $loja['nome'] ); ?></h1>

				<p class="rc-loja__meta">
					<?php reconectar_nota_em_estrela( $loja['nota'] ); ?>

					<?php if ( ! empty( $loja['categoria'] ) ) : ?>
						<span class="rc-loja__separador" aria-hidden="true">&middot;</span>
						<span class="rc-loja__categoria"><?php echo esc_html( $loja['categoria'] ); ?></span>
					<?php endif; ?>

					<?php if ( ! empty( $loja['cidade'] ) ) : ?>
						<span class="rc-loja__separador" aria-hidden="true">&middot;</span>
						<span class="rc-loja__cidade"><?php echo esc_html( $loja['cidade'] ); ?></span>
					<?php endif; ?>
				</p>
			</div>
		</div>

		<?php
		/*
		 * Sem o atributo `open`: no celular o painel aberto empurraria o
		 * catálogo inteiro para fora da primeira tela, e quem chegou pelo card
		 * da vitrine veio ver o que a loja vende, não o endereço dela.
		 */
		?>
		<details class="rc-loja__mais">
			<summary class="rc-loja__mais-titulo">
				<?php esc_html_e( 'Ver mais sobre a loja', 'reconectar' ); ?>
			</summary>

			<div class="rc-loja__mais-conteudo">
				<?php if ( $descricao ) : ?>
					<p class="rc-loja__descricao"><?php echo esc_html( $descricao ); ?></p>
				<?php endif; ?>

				<?php if ( $endereco || $telefone ) : ?>
					<ul class="rc-loja__contatos">
						<?php if ( $endereco ) : ?>
							<li class="rc-loja__contato">
								<span class="rc-loja__contato-rotulo"><?php esc_html_e( 'Endereço', 'reconectar' ); ?></span>
								<span class="rc-loja__contato-valor"><?php echo esc_html( $endereco ); ?></span>
							</li>
						<?php endif; ?>

						<?php if ( $telefone ) : ?>
							<li class="rc-loja__contato">
								<span class="rc-loja__contato-rotulo"><?php esc_html_e( 'Telefone', 'reconectar' ); ?></span>
								<a class="rc-loja__contato-valor" href="<?php echo esc_url( 'tel:' . preg_replace( '/\D+/', '', $telefone ) ); ?>">
									<?php echo esc_html( $telefone ); ?>
								</a>
							</li>
						<?php endif; ?>
					</ul>
				<?php endif; ?>

				<?php
				/*
				 * O formulário é o widget do próprio Dokan, reaproveitado como
				 * está. Ele já respeita a opção `contact_seller` do plugin e já
				 * traz o nonce `dokan_contact_seller_nonce` que a ação de envio
				 * confere — reescrevê-lo aqui significaria manter uma segunda
				 * cópia dessa verificação.
				 */
				if ( function_exists( 'dokan_store_contact_widget' ) ) {
					echo '<div class="rc-loja__contato-formulario">';
					dokan_store_contact_widget();
					echo '</div>';
				}
				?>
			</div>
		</details>
	</header>
	<?php
}

/**
 * Endereço da loja em uma linha só.
 *
 * Monta o texto a partir das partes que existem, sem placeholder para as que
 * faltam: uma loja que só cadastrou a cidade mostra a cidade, e não "—, Maceió".
 *
 * @param array $info Perfil da loja, como `dokan_get_store_info()` devolve.
 * @return string Vazio quando não há nenhuma parte preenchida.
 */
function reconectar_loja_endereco_em_linha( $info ) {
	$endereco = isset( $info['address'] ) && is_array( $info['address'] ) ? $info['address'] : array();

	$partes = array();

	foreach ( array( 'street_1', 'street_2', 'city', 'state', 'zip' ) as $campo ) {
		if ( ! empty( $endereco[ $campo ] ) ) {
			$partes[] = $endereco[ $campo ];
		}
	}

	return implode( ', ', $partes );
}

/**
 * Barra com a busca no catálogo, a entrega e o horário.
 *
 * @param array $loja Loja normalizada por `reconectar_normalizar_loja()`.
 * @return void
 */
function reconectar_loja_controles( $loja ) {
	$entrega = isset( $loja['entrega'] ) ? $loja['entrega'] : array();
	$horario = reconectar_loja_horario( $loja['id'] );
	$termo   = reconectar_loja_termo_buscado();
	$campo   = 'rc-busca-filtro-' . (int) $loja['id'];
	?>
	<div class="rc-loja__controles">
		<?php
		/*
		 * `product_name` não é invenção do tema: é o parâmetro que o Dokan já
		 * intercepta na consulta principal da loja (`Rewrites.php:368`), onde
		 * ele vira o `s` da busca. Por isso este formulário funciona sem uma
		 * linha de PHP do nosso lado — e por isso o nome do campo não pode ser
		 * trocado por um mais bonito.
		 */
		?>
		<form class="rc-busca-filtro" method="get" action="<?php echo esc_url( $loja['url'] ); ?>" role="search">
			<label class="screen-reader-text" for="<?php echo esc_attr( $campo ); ?>">
				<?php esc_html_e( 'Buscar no catálogo desta loja', 'reconectar' ); ?>
			</label>

			<input
				type="search"
				id="<?php echo esc_attr( $campo ); ?>"
				class="rc-busca-filtro__campo"
				name="product_name"
				value="<?php echo esc_attr( $termo ); ?>"
				placeholder="<?php esc_attr_e( 'Busque no catálogo', 'reconectar' ); ?>"
			>

			<button type="submit" class="rc-busca-filtro__botao">
				<span class="rc-busca-filtro__icone" aria-hidden="true">&#9906;</span>
				<span class="screen-reader-text"><?php esc_html_e( 'Buscar', 'reconectar' ); ?></span>
			</button>
		</form>

		<?php if ( ! empty( $entrega['tempo'] ) || '' !== ( $entrega['taxa'] ?? '' ) || ! empty( $entrega['distancia'] ) ) : ?>
			<p class="rc-loja__entrega">
				<span class="rc-loja__entrega-rotulo"><?php esc_html_e( 'Entrega', 'reconectar' ); ?></span>

				<?php if ( ! empty( $entrega['tempo'] ) ) : ?>
					<span class="rc-loja__entrega-tempo"><?php echo esc_html( $entrega['tempo'] ); ?></span>
				<?php endif; ?>

				<?php if ( '' !== ( $entrega['taxa'] ?? '' ) ) : ?>
					<span class="rc-loja__separador" aria-hidden="true">&middot;</span>
					<?php reconectar_taxa_de_entrega( $entrega['taxa'] ); ?>
				<?php endif; ?>

				<?php if ( ! empty( $entrega['distancia'] ) ) : ?>
					<span class="rc-loja__separador" aria-hidden="true">&middot;</span>
					<span class="rc-loja__entrega-distancia"><?php echo esc_html( $entrega['distancia'] ); ?></span>
				<?php endif; ?>
			</p>
		<?php endif; ?>

		<?php if ( $horario ) : ?>
			<p class="rc-loja__horario">
				<span class="rc-loja__estado rc-loja__estado--<?php echo $horario['aberta'] ? 'aberta' : 'fechada'; ?>">
					<?php echo $horario['aberta'] ? esc_html__( 'Aberta agora', 'reconectar' ) : esc_html__( 'Fechada agora', 'reconectar' ); ?>
				</span>

				<?php if ( $horario['intervalo'] ) : ?>
					<span class="rc-loja__separador" aria-hidden="true">&middot;</span>
					<span class="rc-loja__intervalo"><?php echo esc_html( $horario['intervalo'] ); ?></span>
				<?php endif; ?>
			</p>
		<?php endif; ?>
	</div>
	<?php
}

/**
 * Estado de funcionamento da loja hoje.
 *
 * Devolve `null` — e a linha inteira some da página — quando a loja não cadastrou
 * horário nenhum. Exibir "Fechada agora" nesse caso seria afirmar uma coisa que
 * ninguém declarou, e a diferença entre "está fechada" e "não informou" é o que
 * decide se o cliente volta amanhã ou desiste da loja.
 *
 * O dia é resolvido por `dokan_current_datetime()`, o mesmo relógio que
 * `dokan_is_store_open()` usa: consultar `date_i18n()` aqui abriria a chance de
 * o rótulo dizer uma coisa e o estado dizer outra na virada do dia.
 *
 * @param int $vendedor_id ID do vendedor.
 * @return array{aberta:bool,intervalo:string}|null
 */
function reconectar_loja_horario( $vendedor_id ) {
	if ( ! function_exists( 'dokan_is_store_open' ) || ! function_exists( 'dokan_get_store_times' ) ) {
		return null;
	}

	$info = function_exists( 'dokan_get_store_info' ) ? dokan_get_store_info( $vendedor_id ) : array();

	if ( empty( $info['dokan_store_time'] ) || ! is_array( $info['dokan_store_time'] ) ) {
		return null;
	}

	$hoje = function_exists( 'dokan_current_datetime' )
		? strtolower( dokan_current_datetime()->format( 'l' ) )
		: strtolower( gmdate( 'l' ) );

	$abertura   = dokan_get_store_times( $hoje, 'opening_time', null, $vendedor_id );
	$fechamento = dokan_get_store_times( $hoje, 'closing_time', null, $vendedor_id );
	$fechada    = isset( $info['dokan_store_time'][ $hoje ]['status'] )
		&& 'close' === $info['dokan_store_time'][ $hoje ]['status'];

	$intervalo = '';

	if ( ! $fechada && $abertura && $fechamento ) {
		$intervalo = sprintf(
			/* translators: 1: horário de abertura. 2: horário de fechamento. */
			__( 'Hoje das %1$s às %2$s', 'reconectar' ),
			reconectar_loja_hora_em_pt( $abertura ),
			reconectar_loja_hora_em_pt( $fechamento )
		);
	} elseif ( $fechada ) {
		$intervalo = __( 'Não abre hoje', 'reconectar' );
	}

	return array(
		'aberta'    => (bool) dokan_is_store_open( $vendedor_id ),
		'intervalo' => $intervalo,
	);
}

/**
 * Converte o horário do Dokan para o relógio de 24 horas.
 *
 * O Dokan grava e devolve no formato de 12 horas com sufixo em inglês — "07:00
 * am", "05:00 pm" —, que é o que `DateTime::modify()` interpreta sem
 * ambiguidade e por isso não pode ser trocado no banco. Na tela, porém, "05:00
 * pm" não é português: aqui se escreve 17:00, e um visitante que lê "am" e "pm"
 * numa loja de Maceió precisa traduzir mentalmente um dado que deveria ser
 * imediato.
 *
 * A conversão é da exibição apenas. O valor gravado continua sendo o do plugin,
 * e é ele que `dokan_is_store_open()` usa para decidir aberta ou fechada.
 *
 * Um valor que não case com o formato esperado é devolvido como veio — pode ter
 * sido digitado à mão no painel do vendedor, e exibir o que a pessoa escreveu é
 * melhor que exibir vazio.
 *
 * @param string $hora Horário como o Dokan o devolve.
 * @return string Horário em 24 horas, ou o original quando não reconhecido.
 */
function reconectar_loja_hora_em_pt( $hora ) {
	$hora = trim( (string) $hora );

	$momento = date_create_immutable_from_format( 'h:i a', strtolower( $hora ) );

	if ( ! $momento ) {
		return $hora;
	}

	return $momento->format( 'H:i' );
}

/**
 * Termo digitado na busca do catálogo da loja.
 *
 * @return string Vazio quando não há busca ativa.
 */
function reconectar_loja_termo_buscado() {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Busca pública, sem efeito colateral.
	if ( empty( $_GET['product_name'] ) ) {
		return '';
	}

	// phpcs:ignore WordPress.Security.NonceVerification.Recommended
	return sanitize_text_field( wp_unslash( $_GET['product_name'] ) );
}

/**
 * Destaques e catálogo da loja.
 *
 * @param array $loja Loja normalizada por `reconectar_normalizar_loja()`.
 * @return void
 */
function reconectar_loja_catalogo( $loja ) {
	$termo    = reconectar_loja_termo_buscado();
	$produtos = reconectar_produtos_da_loja( $loja['id'], $termo );

	if ( ! $produtos ) {
		?>
		<p class="rc-loja__vazio">
			<?php if ( $termo ) : ?>
				<?php
				printf(
					/* translators: %s: termo buscado. */
					esc_html__( 'Nenhum produto desta loja corresponde a “%s”.', 'reconectar' ),
					esc_html( $termo )
				);
				?>
			<?php else : ?>
				<?php esc_html_e( 'Esta loja ainda não publicou produtos.', 'reconectar' ); ?>
			<?php endif; ?>
		</p>
		<?php
		return;
	}

	/*
	 * Durante uma busca o catálogo vira lista única. Agrupar resultados por
	 * categoria espalharia o que a pessoa procurou entre seções e cabeçalhos:
	 * ela digitou "mel" e receberia de volta um índice, não uma resposta.
	 */
	if ( $termo ) {
		?>
		<section class="rc-loja__secao" aria-labelledby="rc-loja-resultados">
			<h2 class="rc-loja__secao-titulo" id="rc-loja-resultados">
				<?php
				printf(
					/* translators: %s: termo buscado. */
					esc_html__( 'Resultados para “%s”', 'reconectar' ),
					esc_html( $termo )
				);
				?>
			</h2>

			<div class="rc-loja__grade">
				<?php foreach ( $produtos as $produto ) : ?>
					<?php reconectar_card_produto_linha( $produto ); ?>
				<?php endforeach; ?>
			</div>
		</section>
		<?php
		return;
	}

	reconectar_loja_destaques( $loja, $produtos );

	foreach ( reconectar_agrupar_produtos_por_categoria( $produtos ) as $secao ) {
		$id = 'rc-loja-secao-' . sanitize_html_class( $secao['slug'] );
		?>
		<section class="rc-loja__secao" aria-labelledby="<?php echo esc_attr( $id ); ?>">
			<h2 class="rc-loja__secao-titulo" id="<?php echo esc_attr( $id ); ?>">
				<?php echo esc_html( $secao['nome'] ); ?>
			</h2>

			<div class="rc-loja__grade">
				<?php foreach ( $secao['produtos'] as $produto ) : ?>
					<?php reconectar_card_produto_linha( $produto ); ?>
				<?php endforeach; ?>
			</div>
		</section>
		<?php
	}
}

/**
 * Faixa de destaques da loja.
 *
 * Reaproveita o carrossel da vitrine — setas, rolagem por teclado e o
 * `data-rc-carrossel` que `assets/js/marketplace.js` já lê. Sem promoção nem
 * produto marcado como destaque, a seção não é impressa.
 *
 * @param array        $loja     Loja normalizada.
 * @param WC_Product[] $produtos Produtos já carregados da loja.
 * @return void
 */
function reconectar_loja_destaques( $loja, $produtos ) {
	$destaques = reconectar_destaques_da_loja( $produtos );

	if ( ! $destaques ) {
		return;
	}

	reconectar_abrir_carrossel(
		array(
			'id'     => 'rc-loja-destaques-' . (int) $loja['id'],
			'titulo' => __( 'Destaques', 'reconectar' ),
			'classe' => 'rc-carrossel__faixa--produtos',
		)
	);

	foreach ( $destaques as $produto ) {
		reconectar_card_produto( $produto );
	}

	reconectar_fechar_carrossel();
}
