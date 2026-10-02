<?php
/**
 * Avaliações pendentes: o que a loja vendeu e o comprador ainda não avaliou.
 *
 * Uma aba na dashboard do Dokan, logo abaixo de Pedidos. Cada linha é um par
 * **comprador × produto** de pedido concluído sem avaliação daquele comprador
 * naquele produto — é a lista de quem a loja pode lembrar de avaliar.
 *
 * "Concluído" (`wc-completed`) é o único status que conta: antes dele o
 * comprador pode não ter recebido nada, e pedir avaliação de produto que ainda
 * está a caminho produziria nota sobre a entrega, não sobre o produto.
 *
 * A avaliação da loja não tem linha própria porque não existe à parte: o Dokan
 * Lite calcula a nota da loja a partir das avaliações dos produtos dela.
 *
 * @package reconectar-core
 */

defined( 'ABSPATH' ) || exit;

/**
 * Registra a aba e monta a lista.
 */
class Reconectar_Avaliacoes_Pendentes {

	/**
	 * Query var da aba, que é também o trecho da URL.
	 *
	 * Mudar este valor exige subir `Reconectar_Migracoes::VERSAO`: a regra de
	 * reescrita fica gravada com o nome antigo.
	 *
	 * @var string
	 */
	const QUERY_VAR = 'avaliacoes-pendentes';

	/**
	 * Linhas por página.
	 *
	 * @var int
	 */
	const POR_PAGINA = 20;

	/**
	 * Parâmetro de paginação na URL.
	 *
	 * Nem `paged` nem `page`: os dois são query vars do núcleo, e na página da
	 * dashboard o WordPress os interpretaria como paginação **da página** —
	 * levando ao 404 a partir da segunda.
	 *
	 * @var string
	 */
	const PARAMETRO_PAGINA = 'pagenum';

	/**
	 * Registra os ganchos.
	 *
	 * Os três são necessários e nenhum basta sozinho: sem a query var a URL
	 * cai no 404, sem o item de menu a tela existe e ninguém chega nela, e sem
	 * o template a dashboard abre vazia. A query var nova só vale depois do
	 * flush de reescrita — ver `Reconectar_Migracoes`, versão 7.
	 *
	 * @return void
	 */
	public static function init() {
		add_filter( 'dokan_query_var_filter', array( __CLASS__, 'registrar_query_var' ) );
		add_filter( 'dokan_get_dashboard_nav', array( __CLASS__, 'registrar_menu' ) );
		add_action( 'dokan_load_custom_template', array( __CLASS__, 'carregar_tela' ) );
	}

	/**
	 * Acrescenta a query var da aba.
	 *
	 * @param array $query_vars Query vars da dashboard.
	 * @return array
	 */
	public static function registrar_query_var( $query_vars ) {
		$query_vars[] = self::QUERY_VAR;

		return $query_vars;
	}

	/**
	 * Põe o item no menu lateral, logo depois de Pedidos (posição 50).
	 *
	 * A permissão é a do menu de pedidos: a lista é derivada dos pedidos da
	 * loja, e quem não pode ver um não deve ver o outro.
	 *
	 * @param array $nav Itens do menu.
	 * @return array
	 */
	public static function registrar_menu( $nav ) {
		$nav[ self::QUERY_VAR ] = array(
			'title'      => __( 'Avaliações pendentes', 'reconectar-core' ),
			'icon'       => '<i class="fas fa-star"></i>',
			'icon_name'  => 'Star',
			'url'        => dokan_get_navigation_url( self::QUERY_VAR ),
			'pos'        => 51,
			'permission' => 'dokan_view_order_menu',
		);

		return $nav;
	}

	/**
	 * Imprime a tela quando a query var da aba está na requisição.
	 *
	 * @param array $query_vars Query vars resolvidas.
	 * @return void
	 */
	public static function carregar_tela( $query_vars ) {
		if ( ! isset( $query_vars[ self::QUERY_VAR ] ) ) {
			return;
		}

		// A dashboard já recusa quem não é loja; esta guarda é a do menu, que
		// a URL digitada à mão contornaria.
		if ( ! current_user_can( 'dokan_view_order_menu' ) ) {
			dokan_get_template_part( 'global/dokan-error', '', array( 'deleted' => false, 'message' => __( 'Você não tem permissão para ver esta página.', 'reconectar-core' ) ) );
			return;
		}

		$pendentes = self::pendentes( (int) dokan_get_current_user_id() );
		$total     = count( $pendentes );
		$paginas   = max( 1, (int) ceil( $total / self::POR_PAGINA ) );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- leitura de paginação.
		$pagina    = isset( $_GET[ self::PARAMETRO_PAGINA ] ) ? absint( $_GET[ self::PARAMETRO_PAGINA ] ) : 1;
		$pagina    = min( max( 1, $pagina ), $paginas );
		$linhas    = array_slice( $pendentes, ( $pagina - 1 ) * self::POR_PAGINA, self::POR_PAGINA );

		do_action( 'dokan_dashboard_wrap_start' );
		?>
		<div class="dokan-dashboard-wrap">
			<?php do_action( 'dokan_dashboard_content_before' ); ?>
			<div class="dokan-dashboard-content">
				<?php do_action( 'dokan_dashboard_content_inside_before' ); ?>
				<article class="rc-avaliacoes-pendentes">
					<header class="dokan-dashboard-header">
						<h1 class="entry-title"><?php esc_html_e( 'Avaliações pendentes', 'reconectar-core' ); ?></h1>
					</header>
					<p>
						<?php esc_html_e( 'Produtos de pedidos concluídos que o comprador ainda não avaliou. A nota da loja é a média das avaliações dos produtos.', 'reconectar-core' ); ?>
					</p>
					<?php if ( ! $pendentes ) : ?>
						<div class="dokan-info"><?php esc_html_e( 'Nenhuma avaliação pendente. Todo produto entregue já foi avaliado pelo comprador.', 'reconectar-core' ); ?></div>
					<?php else : ?>
						<?php self::imprimir_tabela( $linhas ); ?>
						<?php self::imprimir_paginacao( $pagina, $paginas ); ?>
					<?php endif; ?>
				</article>
				<?php do_action( 'dokan_dashboard_content_inside_after' ); ?>
			</div>
			<?php do_action( 'dokan_dashboard_content_after' ); ?>
		</div>
		<?php
		do_action( 'dokan_dashboard_wrap_end' );
	}

	/**
	 * Monta a lista de pares comprador × produto sem avaliação.
	 *
	 * Os pedidos vêm de `wp_dokan_orders`, a tabela que o Dokan usa para a
	 * própria lista de pedidos da loja: `wc_get_orders()` descartaria em
	 * silêncio um filtro por `_dokan_vendor_id` e devolveria os pedidos de
	 * todas as lojas — a armadilha do `CLAUDE.md`. O status também é lido de
	 * lá, e conferido de novo no objeto do pedido, porque linha órfã nessa
	 * tabela existe.
	 *
	 * O conjunto inteiro é montado em PHP e paginado depois: a avaliação é
	 * filtro que o SQL do pedido não enxerga, e paginar antes de filtrar
	 * deixaria páginas vazias no meio da lista. É dimensionado para lojas
	 * locais; com milhares de pedidos concluídos por loja, a conta passa a
	 * merecer SQL próprio.
	 *
	 * @global wpdb $wpdb
	 * @param int $loja_id Usuário dono da loja.
	 * @return array<int,array{pedido:WC_Order,item:WC_Order_Item_Product,produto_id:int,concluido:?WC_DateTime}>
	 */
	public static function pendentes( $loja_id ) {
		global $wpdb;

		if ( $loja_id <= 0 ) {
			return array();
		}

		$pedido_ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT order_id FROM {$wpdb->prefix}dokan_orders WHERE seller_id = %d AND order_status = %s ORDER BY order_id DESC",
				$loja_id,
				'wc-completed'
			)
		);

		// Um comprador que levou o mesmo produto duas vezes deve uma avaliação,
		// não duas: a chave é o par, e vale o pedido mais recente — a consulta
		// já vem em ordem decrescente, então o primeiro visto fica.
		$pares = array();

		foreach ( $pedido_ids as $pedido_id ) {
			$pedido = wc_get_order( (int) $pedido_id );

			if ( ! $pedido || ! $pedido->has_status( 'completed' ) ) {
				continue;
			}

			// Pedido sem conta fica de fora: avaliar exige login, e quem comprou
			// como visitante — antes da trava de checkout — não tem conta para
			// entrar. Listá-lo seria pendência que ninguém pode resolver.
			$comprador_id = (int) $pedido->get_customer_id();

			if ( $comprador_id <= 0 ) {
				continue;
			}

			foreach ( $pedido->get_items() as $item ) {
				if ( ! $item instanceof WC_Order_Item_Product ) {
					continue;
				}

				// O pai, não a variação: a avaliação é gravada no produto.
				$produto_id = (int) $item->get_product_id();
				$chave      = $comprador_id . ':' . $produto_id;

				if ( $produto_id <= 0 || isset( $pares[ $chave ] ) ) {
					continue;
				}

				$pares[ $chave ] = array(
					'pedido'     => $pedido,
					'item'       => $item,
					'produto_id' => $produto_id,
					'concluido'  => $pedido->get_date_completed(),
				);
			}
		}

		if ( ! $pares ) {
			return array();
		}

		$avaliados = self::pares_avaliados( $pares );

		return array_values( array_diff_key( $pares, $avaliados ) );
	}

	/**
	 * Descobre quais pares comprador × produto já têm avaliação.
	 *
	 * Uma consulta só, pelos produtos envolvidos, e o casamento em PHP.
	 *
	 * Avaliação **aguardando moderação** conta como feita: o comprador cumpriu
	 * a parte dele, e listá-lo seria cobrar de novo quem já respondeu. Spam e
	 * lixeira não contam.
	 *
	 * O comprador é reconhecido pelo `user_id` da avaliação **ou** pelo e-mail
	 * dela, na mesma regra de `wc_customer_bought_product()`: avaliação antiga,
	 * de antes da trava de login, foi gravada com `user_id` 0 e só o e-mail a
	 * liga à conta.
	 *
	 * O `type => review` é obrigatório. O WooCommerce tira `review` da consulta
	 * padrão de comentários, e sem ele a resposta é vazia — todo produto
	 * pareceria pendente, uma lista plausível e inteiramente errada.
	 *
	 * @param array $pares Pares montados em `pendentes()`.
	 * @return array<string,true> Chaves `comprador:produto` já avaliadas.
	 */
	private static function pares_avaliados( $pares ) {
		$produtos  = array();
		$emails    = array();
		$avaliados = array();

		foreach ( $pares as $par ) {
			$produtos[ $par['produto_id'] ] = true;

			$comprador_id = (int) $par['pedido']->get_customer_id();
			if ( isset( $emails[ $comprador_id ] ) ) {
				continue;
			}

			$usuario = get_userdata( $comprador_id );
			$emails[ $comprador_id ] = array_filter(
				array_map(
					'strtolower',
					array(
						$usuario ? $usuario->user_email : '',
						(string) $par['pedido']->get_billing_email(),
					)
				)
			);
		}

		$avaliacoes = get_comments(
			array(
				'type'                      => 'review',
				'status'                    => 'all',
				'post__in'                  => array_keys( $produtos ),
				'update_comment_meta_cache' => false,
			)
		);

		foreach ( $avaliacoes as $avaliacao ) {
			$produto_id = (int) $avaliacao->comment_post_ID;
			$autor_id   = (int) $avaliacao->user_id;
			$autor_mail = strtolower( (string) $avaliacao->comment_author_email );

			foreach ( $emails as $comprador_id => $enderecos ) {
				if ( $autor_id === $comprador_id || ( '' !== $autor_mail && in_array( $autor_mail, $enderecos, true ) ) ) {
					$avaliados[ $comprador_id . ':' . $produto_id ] = true;
				}
			}
		}

		return $avaliados;
	}

	/**
	 * Imprime a tabela de pendências.
	 *
	 * @param array $linhas Pares da página corrente.
	 * @return void
	 */
	private static function imprimir_tabela( $linhas ) {
		?>
		<table class="dokan-table dokan-table-striped rc-avaliacoes-pendentes__tabela">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Produto', 'reconectar-core' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Comprador', 'reconectar-core' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Pedido', 'reconectar-core' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Concluído em', 'reconectar-core' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $linhas as $linha ) : ?>
					<?php
					$pedido  = $linha['pedido'];
					$produto = wc_get_product( $linha['produto_id'] );
					?>
					<tr>
						<td data-title="<?php esc_attr_e( 'Produto', 'reconectar-core' ); ?>">
							<?php if ( $produto ) : ?>
								<a href="<?php echo esc_url( get_permalink( $produto->get_id() ) ); ?>"><?php echo esc_html( $produto->get_name() ); ?></a>
							<?php else : ?>
								<?php // Produto apagado depois da venda: o nome gravado no item é o que resta. ?>
								<?php echo esc_html( $linha['item']->get_name() ); ?>
							<?php endif; ?>
						</td>
						<td data-title="<?php esc_attr_e( 'Comprador', 'reconectar-core' ); ?>">
							<?php echo esc_html( $pedido->get_formatted_billing_full_name() ); ?>
						</td>
						<td data-title="<?php esc_attr_e( 'Pedido', 'reconectar-core' ); ?>">
							<a href="<?php echo esc_url( self::url_do_pedido( $pedido ) ); ?>">
								<?php
								/* translators: %s: número do pedido. */
								echo esc_html( sprintf( __( 'Pedido nº %s', 'reconectar-core' ), $pedido->get_order_number() ) );
								?>
							</a>
						</td>
						<td data-title="<?php esc_attr_e( 'Concluído em', 'reconectar-core' ); ?>">
							<?php
							// Sem data de conclusão gravada, fica o travessão: inventar a do
							// pedido seria dizer "entregue em" uma data em que não foi.
							echo $linha['concluido'] ? esc_html( wc_format_datetime( $linha['concluido'] ) ) : '&mdash;';
							?>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	/**
	 * Endereço da tela de detalhes do pedido na dashboard.
	 *
	 * O mesmo par de argumento e nonce que a lista de pedidos do Dokan usa.
	 *
	 * @param WC_Order $pedido Pedido.
	 * @return string
	 */
	private static function url_do_pedido( $pedido ) {
		return wp_nonce_url(
			add_query_arg( array( 'order_id' => $pedido->get_id() ), dokan_get_navigation_url( 'orders' ) ),
			'dokan_view_order'
		);
	}

	/**
	 * Imprime a paginação, quando há mais de uma página.
	 *
	 * @param int $pagina  Página corrente.
	 * @param int $paginas Total de páginas.
	 * @return void
	 */
	private static function imprimir_paginacao( $pagina, $paginas ) {
		if ( $paginas <= 1 ) {
			return;
		}

		$links = paginate_links(
			array(
				'base'      => add_query_arg( self::PARAMETRO_PAGINA, '%#%', dokan_get_navigation_url( self::QUERY_VAR ) ),
				'format'    => '',
				'current'   => $pagina,
				'total'     => $paginas,
				'type'      => 'list',
				'prev_text' => __( '&laquo; Anteriores', 'reconectar-core' ),
				'next_text' => __( 'Próximas &raquo;', 'reconectar-core' ),
			)
		);
		?>
		<nav class="pagination-wrap" aria-label="<?php esc_attr_e( 'Páginas de avaliações pendentes', 'reconectar-core' ); ?>">
			<?php echo wp_kses_post( $links ); ?>
		</nav>
		<?php
	}
}
