<?php
/**
 * Status de pedido próprios do fluxo da Reconectar.
 *
 * O fluxo definido para a plataforma tem cinco etapas visíveis ao cliente:
 *
 *     Pedido realizado → Pagamento aprovado → Preparação → Enviado → Entregue
 *
 * O WooCommerce cobre a primeira, a segunda e a última com status nativos
 * (`pending`, `processing` e `completed`), mas não tem equivalente para
 * "Preparação" nem para "Enviado". Sem eles o cliente ficaria olhando
 * "processando" desde a aprovação do pagamento até a entrega — justamente o
 * trecho em que ele mais quer saber o que está acontecendo.
 *
 * A alternativa seria usar `on-hold` e `pending` com rótulos trocados, mas
 * esses status têm semântica própria dentro do WooCommerce (redução de
 * estoque, e-mails automáticos, relatórios de venda) e reaproveitá-los
 * quebraria comportamento que não é nosso. Status novos custam este arquivo e
 * não mexem em nada que já existe.
 *
 * @package reconectar-core
 */

defined( 'ABSPATH' ) || exit;

class Reconectar_Status_Pedido {

	/**
	 * Comprovante de pagamento enviado, aguardando a conferência da loja.
	 *
	 * O slug é curto de propósito: `wp_posts.post_status` é `varchar(20)` e o
	 * `sql_mode` desta instalação não tem `STRICT_TRANS_TABLES`, então um slug
	 * mais longo seria truncado **em silêncio** e o pedido ficaria gravado com um
	 * status que não casa com nenhum registrado.
	 */
	const CONFERENCIA = 'wc-conferencia';

	/**
	 * Pedido pago, com a loja separando/produzindo os itens.
	 */
	const PREPARACAO = 'wc-preparacao';

	/**
	 * Pedido despachado, a caminho do endereço de entrega.
	 */
	const ENVIADO = 'wc-enviado';

	/**
	 * Registra os ganchos.
	 *
	 * `init` na prioridade 9 (antes da padrão) garante que os status estejam
	 * registrados quando o WooCommerce monta suas próprias listas em `init`.
	 */
	public static function init() {
		add_action( 'init', array( __CLASS__, 'registrar_status' ), 9 );
		add_filter( 'wc_order_statuses', array( __CLASS__, 'inserir_no_fluxo' ) );
		add_filter( 'woocommerce_order_is_paid_statuses', array( __CLASS__, 'considerar_pagos' ) );
		add_filter( 'woocommerce_reports_order_statuses', array( __CLASS__, 'considerar_pagos' ) );

		// A lista de pedidos do admin tem dois endereços: o antigo, baseado em
		// posts, e o novo (HPOS), baseado em tabelas próprias. Qual dos dois
		// está no ar depende de uma configuração da loja, então os dois filtros
		// são registrados — o que não estiver em uso simplesmente nunca dispara.
		add_filter( 'bulk_actions-edit-shop_order', array( __CLASS__, 'acoes_em_massa' ) );
		add_filter( 'bulk_actions-woocommerce_page_wc-orders', array( __CLASS__, 'acoes_em_massa' ) );
	}

	/**
	 * Rótulos dos status, na ordem em que acontecem.
	 *
	 * As chaves trazem o prefixo `wc-` porque é assim que o status é gravado no
	 * banco; `wc_order_statuses` também espera esse formato.
	 *
	 * A ordem do array é o que ordena o seletor de status na interface:
	 * `inserir_no_fluxo()` cola este array inteiro logo depois de `wc-processing`,
	 * preservando a ordem interna.
	 *
	 * @return array<string,string>
	 */
	public static function rotulos() {
		return array(
			self::CONFERENCIA => __( 'Pagamento em conferência', 'reconectar-core' ),
			self::PREPARACAO  => __( 'Em preparação', 'reconectar-core' ),
			self::ENVIADO     => __( 'Enviado', 'reconectar-core' ),
		);
	}

	/**
	 * Registra os status como post status do WordPress.
	 *
	 * `show_in_admin_all_list` e `show_in_admin_status_list` fazem os pedidos
	 * aparecerem na listagem e no filtro por status do painel; sem eles o
	 * pedido some da tela assim que entra em um status novo.
	 */
	public static function registrar_status() {
		foreach ( self::rotulos() as $status => $rotulo ) {
			register_post_status(
				$status,
				array(
					'label'                     => $rotulo,
					'public'                    => true,
					'exclude_from_search'       => false,
					'show_in_admin_all_list'    => true,
					'show_in_admin_status_list' => true,
					/* translators: %s: número de pedidos. */
					'label_count'               => _n_noop( $rotulo . ' <span class="count">(%s)</span>', $rotulo . ' <span class="count">(%s)</span>', 'reconectar-core' ),
				)
			);
		}
	}

	/**
	 * Insere os status novos entre "Processando" e "Concluído".
	 *
	 * A ordem do array é a ordem em que o WooCommerce desenha o seletor de
	 * status do pedido, e é por ela que quem opera a loja se orienta. Remontar
	 * o array preservando as posições vizinhas é mais trabalhoso do que um
	 * `array_merge` no fim, mas evita que "Em preparação" apareça depois de
	 * "Cancelado" na lista.
	 *
	 * @param array<string,string> $status Lista original do WooCommerce.
	 * @return array<string,string>
	 */
	public static function inserir_no_fluxo( $status ) {
		$reordenado = array();

		foreach ( $status as $chave => $rotulo ) {
			$reordenado[ $chave ] = $rotulo;

			if ( 'wc-processing' === $chave ) {
				$reordenado = array_merge( $reordenado, self::rotulos() );
			}
		}

		// Se o WooCommerce um dia deixar de expor `wc-processing`, os status
		// ainda precisam existir na lista — caso contrário um pedido em
		// preparação ficaria sem rótulo na interface.
		foreach ( self::rotulos() as $chave => $rotulo ) {
			if ( ! isset( $reordenado[ $chave ] ) ) {
				$reordenado[ $chave ] = $rotulo;
			}
		}

		return $reordenado;
	}

	/**
	 * Declara os status novos como "pedido pago".
	 *
	 * Um pedido em preparação ou já enviado teve o pagamento aprovado; se o
	 * WooCommerce não souber disso, a venda some dos relatórios e a data de
	 * pagamento não é gravada. Aqui o prefixo `wc-` não entra: estes dois
	 * filtros trabalham com o status "cru".
	 *
	 * `conferencia` fica de fora de propósito, e a lista é escrita à mão por
	 * causa disso: naquele status o comprovante chegou, mas ninguém conferiu se o
	 * dinheiro caiu. Declará-lo pago lançaria receita não verificada nos
	 * relatórios do WooCommerce.
	 *
	 * @param string[] $status Lista de status considerados pagos.
	 * @return string[]
	 */
	public static function considerar_pagos( $status ) {
		$status[] = 'preparacao';
		$status[] = 'enviado';

		return $status;
	}

	/**
	 * Disponibiliza os status novos nas ações em massa da lista de pedidos.
	 *
	 * @param array<string,string> $acoes Ações registradas.
	 * @return array<string,string>
	 */
	public static function acoes_em_massa( $acoes ) {
		foreach ( self::rotulos() as $status => $rotulo ) {
			// `mark_` + status sem o prefixo `wc-` é o formato que o
			// WooCommerce reconhece no handler das ações em massa.
			$acoes[ 'mark_' . substr( $status, 3 ) ] = sprintf(
				/* translators: %s: nome do status. */
				__( 'Alterar status para %s', 'reconectar-core' ),
				$rotulo
			);
		}

		return $acoes;
	}
}
