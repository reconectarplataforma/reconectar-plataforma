<?php
/**
 * Visão geral do painel da loja: os indicadores que fazem sentido aqui.
 *
 * A tela é o painel de análise do WooCommerce Admin, que o Dokan reaproveita em
 * React e completa com indicadores de marketplace. Três deles não descrevem
 * esta plataforma:
 *
 * - **Comissão do marketplace** e **Desconto do marketplace**: a plataforma não
 *   retém nada (o `provision.sh` zera a comissão), então os dois cards saem
 *   sempre em R$ 0,00 — e um card fixo em zero com o nome "comissão" sugere à
 *   loja um desconto que ninguém cobra.
 * - **Variações vendidas**: o Dokan Lite não cria produto variável pelo painel
 *   da loja, e o número repetiria "Produtos vendidos" com outro nome.
 *
 * Sem eles a grade fecha em seis cards — exatamente as seis colunas que a
 * `.woocommerce-summary.has-6-items` desenha em tela larga; com nove, a segunda
 * linha saía com três cards e três buracos.
 *
 * Os indicadores de comissão saem pelo **schema**, e não pelo `hiddenBlocks` do
 * painel, de propósito: o `hiddenBlocks` é só o padrão, e o menu "Mostrar"
 * da própria seção os traria de volta. Variações vêm de outro relatório, cujo
 * schema não tem filtro, e por isso ficam no padrão de seções.
 *
 * @package reconectar-core
 */

defined( 'ABSPATH' ) || exit;

/**
 * Ajusta indicadores, ordem, período padrão e leitura da Visão geral.
 */
class Reconectar_Visao_Geral_Da_Loja {

	/**
	 * Handle do bloco do Dokan que aplica `woocommerce_dashboard_default_sections`.
	 *
	 * O filtro é lido no topo do módulo, no instante em que o script avalia: o
	 * `addFilter` tem de rodar antes dele, e não no `DOMContentLoaded`.
	 *
	 * @var string
	 */
	const SCRIPT_DAS_SECOES = 'dokan_analytics_customizable-dashboard';

	/**
	 * Indicadores de marketplace que a loja não vê.
	 *
	 * @var string[]
	 */
	const INDICADORES_SEM_SENTIDO = array( 'total_admin_commission', 'total_admin_discount' );

	/**
	 * Ordem dos seis cards da loja.
	 *
	 * "Ganho total" logo depois das vendas porque é o que a loja recebe. O
	 * Dokan tenta pô-lo ali, mas pede `revenue/total_seller_earning`, chave que
	 * não existe — a do schema dele é `total_vendor_earning` —, e o card caía
	 * para o fim da fila.
	 *
	 * @var string[]
	 */
	const ORDEM = array(
		'revenue/total_sales',
		'revenue/total_vendor_earning',
		'revenue/net_revenue',
		'orders/orders_count',
		'products/items_sold',
		'revenue/total_vendor_discount',
	);

	/**
	 * Registra os ganchos.
	 *
	 * @return void
	 */
	public static function init() {
		// Prioridade 20: o Dokan acrescenta os indicadores na 10.
		add_filter( 'woocommerce_rest_report_revenue_stats_schema', array( __CLASS__, 'tirar_indicadores_de_comissao' ), 20 );
		add_filter( 'woocommerce_rest_report_sort_performance_indicators', array( __CLASS__, 'ordenar_indicadores' ), 20 );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enfileirar' ), 30 );
	}

	/**
	 * Quem está vendo é uma loja, e não quem administra o marketplace?
	 *
	 * Os dois filtros de servidor rodam também na análise do `/wp-admin`, onde
	 * a comissão é um dado legítimo para o Super Administrador.
	 *
	 * @return bool
	 */
	private static function eh_loja() {
		$usuario_id = get_current_user_id();

		return $usuario_id
			&& Reconectar_Permissoes::eh_vendedor( $usuario_id )
			&& ! current_user_can( 'manage_woocommerce' );
	}

	/**
	 * Tira dos cards os indicadores que a plataforma sempre zera.
	 *
	 * Só a marca `indicator` sai: o campo continua no relatório, para quem o
	 * pedir pela API, e some apenas da lista de cards.
	 *
	 * @param array $schema Schema de `revenue/stats`.
	 * @return array
	 */
	public static function tirar_indicadores_de_comissao( $schema ) {
		if ( ! self::eh_loja() ) {
			return $schema;
		}

		foreach ( self::INDICADORES_SEM_SENTIDO as $chave ) {
			if ( isset( $schema['totals']['properties'][ $chave ] ) ) {
				unset( $schema['totals']['properties'][ $chave ]['indicator'] );
			}
		}

		return $schema;
	}

	/**
	 * Põe os seis cards da loja na ordem de `ORDEM`, à frente do resto.
	 *
	 * @param string[] $ordem Ordem recebida do WooCommerce e do Dokan.
	 * @return string[]
	 */
	public static function ordenar_indicadores( $ordem ) {
		if ( ! self::eh_loja() ) {
			return $ordem;
		}

		return array_values( array_unique( array_merge( self::ORDEM, (array) $ordem ) ) );
	}

	/**
	 * Período que a tela abre: o mês contra o mês anterior.
	 *
	 * O padrão do WooCommerce é `previous_year`, pensado para loja com anos de
	 * histórico. Uma loja recém-cadastrada não tem ano anterior: todo card saía
	 * com variação contra R$ 0,00, uma comparação que não diz nada.
	 *
	 * Uma escolha gravada no `/wp-admin` continua mandando. Ela só não chegaria
	 * aqui sozinha: o painel lê a opção do repositório `wc/admin/settings`, que
	 * no `/wp-admin` vem pré-carregado em `wcSettings.admin` e no painel da loja
	 * nasce vazio — por isso a tela caía sempre no fallback embutido no script.
	 *
	 * @return string
	 */
	private static function periodo_padrao() {
		$gravado = get_option( 'woocommerce_default_date_range' );

		return is_string( $gravado ) && '' !== $gravado ? $gravado : 'period=month&compare=previous_period';
	}

	/**
	 * Ajusta período e seções padrão no navegador e carrega o CSS da tela.
	 *
	 * @return void
	 */
	public static function enfileirar() {
		if ( ! function_exists( 'dokan_is_seller_dashboard' ) || ! dokan_is_seller_dashboard() || ! self::eh_loja() ) {
			return;
		}

		wp_add_inline_script(
			self::SCRIPT_DAS_SECOES,
			sprintf(
				"( function ( repositorio ) {
					var atuais = wp.data.select( repositorio ).getSetting( 'wc_admin', 'wcAdminSettings' ) || {};
					if ( atuais.woocommerce_default_date_range ) {
						return;
					}
					wp.data.dispatch( repositorio ).updateSettingsForGroup( 'wc_admin', {
						wcAdminSettings: Object.assign( {}, atuais, { woocommerce_default_date_range: %s } )
					} );
				} )( 'wc/admin/settings' );",
				wp_json_encode( self::periodo_padrao() )
			),
			'before'
		);

		wp_add_inline_script(
			self::SCRIPT_DAS_SECOES,
			"wp.hooks.addFilter( 'woocommerce_dashboard_default_sections', 'reconectar/visao-geral', function ( secoes ) {
				return secoes.map( function ( secao ) {
					if ( 'store-performance' !== secao.key ) {
						return secao;
					}
					return Object.assign( {}, secao, { hiddenBlocks: ( secao.hiddenBlocks || [] ).concat( [ 'variations/items_sold' ] ) } );
				} );
			} );",
			'before'
		);

		wp_enqueue_style(
			'reconectar-visao-geral-da-loja',
			RECONECTAR_CORE_URL . 'assets/css/visao-geral-da-loja.css',
			array(),
			'0.1.1'
		);
	}
}
