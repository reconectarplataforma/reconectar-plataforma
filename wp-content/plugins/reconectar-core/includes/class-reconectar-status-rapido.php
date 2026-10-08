<?php
/**
 * Botões de status no detalhe do pedido do painel da loja.
 *
 * O Dokan oferece um único caminho para a loja andar com o pedido: o link
 * "Editar" ao lado do selo, que abre um `<select>` com **todos** os status do
 * WooCommerce — onze, contando os desta plataforma — e um botão "Atualizar".
 * Para o passo que a loja dá dezenas de vezes por semana, isso são quatro
 * cliques e uma leitura de lista, e é na lista que mora o erro: "Reembolsado"
 * e "Cancelado" ficam a uma linha de "Concluído".
 *
 * Este painel põe os **próximos** passos do fluxo em botões, a partir do status
 * atual, e deixa o `<select>` do Dokan como está para o resto — exceção é
 * exceção, e ela continua a um clique de distância.
 *
 * Só para frente, de propósito. Voltar um pedido de "Enviado" para "Em
 * preparação" é correção de engano, não rotina, e um botão para isso ao lado do
 * de concluir seria o mesmo convite ao erro que a lista já fazia.
 *
 * Três pontos do fluxo ficam sem botão, e cada um tem dono:
 *
 * - `pending` e `conferencia`: andar daqui é **confirmar pagamento**, e quem faz
 *   isso é `Reconectar_Comprovante::confirmar()`, que grava a meta de
 *   confirmação junto. Um botão "Em preparação" aqui pularia a meta e deixaria
 *   o painel do comprovante pedindo uma confirmação que já aconteceu.
 * - `solicitado`: andar daqui é **responder** o serviço, com valor e mensagem,
 *   pelo formulário de `Reconectar_Servicos`. Um "Respondido" sem resposta
 *   gravada seria um número plausível sobre nada.
 *
 * @package reconectar-core
 */

defined( 'ABSPATH' ) || exit;

class Reconectar_Status_Rapido {

	/**
	 * Ação de `admin-post.php` que troca o status.
	 *
	 * Sem par `nopriv`: andar com o pedido é ato de quem administra a loja.
	 */
	const ACAO = 'reconectar_status_rapido';

	/**
	 * Registra os ganchos.
	 *
	 * Prioridade 5 no gancho do detalhe: o comprovante e o serviço penduram
	 * painéis no mesmo lugar, na 10, e o de status é continuação do cartão
	 * "Detalhes gerais", onde está o selo — tem de sair colado nele.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'admin_post_' . self::ACAO, array( __CLASS__, 'processar' ) );
		add_action( 'dokan_order_detail_after_order_general_details', array( __CLASS__, 'painel_no_detalhe' ), 5 );
	}

	/**
	 * Próximos status que a loja pode escolher com um botão, a partir do atual.
	 *
	 * O primeiro de cada lista é o passo seguinte natural e sai em destaque; os
	 * outros são atalhos para quem já despachou ou entregou sem registrar o
	 * passo do meio. A ordem é a mesma de `Reconectar_Status_Pedido::rotulos()`.
	 *
	 * @param string $atual Status atual, sem o prefixo `wc-`.
	 * @return string[] Status de destino, com o prefixo `wc-`.
	 */
	public static function transicoes( $atual ) {
		$mapa = array(
			'processing' => array( Reconectar_Status_Pedido::PREPARACAO, Reconectar_Status_Pedido::ENVIADO, 'wc-completed' ),
			'preparacao' => array( Reconectar_Status_Pedido::ENVIADO, 'wc-completed' ),
			'enviado'    => array( 'wc-completed' ),
			'respondido' => array( 'wc-completed' ),
		);

		return isset( $mapa[ $atual ] ) ? $mapa[ $atual ] : array();
	}

	/**
	 * Texto do botão de cada destino.
	 *
	 * Verbo, e não o nome do status: o botão é uma ação, e "Enviado" num botão
	 * se lê como estado — a loja não saberia se ele informa ou se altera.
	 *
	 * @param string $destino Status de destino, com o prefixo `wc-`.
	 * @return string
	 */
	private static function rotulo_do_botao( $destino ) {
		$rotulos = array(
			Reconectar_Status_Pedido::PREPARACAO => __( 'Iniciar preparação', 'reconectar-core' ),
			Reconectar_Status_Pedido::ENVIADO    => __( 'Marcar como enviado', 'reconectar-core' ),
			'wc-completed'                       => __( 'Concluir pedido', 'reconectar-core' ),
		);

		return isset( $rotulos[ $destino ] ) ? $rotulos[ $destino ] : wc_get_order_status_name( $destino );
	}

	/**
	 * Diz se o usuário corrente pode trocar o status deste pedido.
	 *
	 * As três condições são as do próprio Dokan, em `orders/details.php` e em
	 * `Ajax::change_order_status()`: a capacidade, a opção que a plataforma
	 * pode desligar e a posse do pedido. Repetir a regra dele, e não uma mais
	 * larga, é o que impede o botão de existir onde o `<select>` não existe.
	 * `dokan_get_current_user_id()` e não `get_current_user_id()`: ela resolve
	 * o funcionário de loja para o dono, como o handler do Dokan faz.
	 *
	 * @param WC_Order $pedido Sub-pedido.
	 * @return bool
	 */
	private static function pode( $pedido ) {
		if ( ! $pedido instanceof WC_Order || ! is_user_logged_in() ) {
			return false;
		}

		if ( ! function_exists( 'dokan_is_seller_has_order' ) || ! function_exists( 'dokan_get_current_user_id' ) ) {
			return false;
		}

		if ( ! current_user_can( 'dokan_manage_order' ) || 'on' !== dokan_get_option( 'order_status_change', 'dokan_selling', 'on' ) ) {
			return false;
		}

		return (bool) dokan_is_seller_has_order( dokan_get_current_user_id(), $pedido->get_id() );
	}

	/**
	 * Identificador do painel, usado como âncora da volta.
	 *
	 * @param int $pedido_id ID do sub-pedido.
	 * @return string
	 */
	private static function ancora( $pedido_id ) {
		return 'rc-status-rapido-' . (int) $pedido_id;
	}

	/**
	 * Imprime o painel de botões logo abaixo de "Detalhes gerais".
	 *
	 * Um `<form>` por botão, cada um com o destino num campo oculto, e não um
	 * form só com `name="status"` nos botões: o valor do botão só entra no POST
	 * quando o envio parte dele, e o handler receberia destino vazio de
	 * qualquer outro envio.
	 *
	 * Depois do último passo não há botão, mas o painel ainda sai enquanto
	 * houver aviso a dar: "Concluir pedido" leva a um status sem transição, e
	 * sem isto a confirmação do clique sumiria junto com o painel — e com ele a
	 * âncora para onde `voltar()` manda a página.
	 *
	 * O gancho fica entre os painéis, fora dos formulários do template do Dokan
	 * (status, nota e rastreio), então não há aninhamento a contornar.
	 *
	 * @param WC_Order $pedido Sub-pedido aberto.
	 * @return void
	 */
	public static function painel_no_detalhe( $pedido ) {
		if ( ! self::pode( $pedido ) ) {
			return;
		}

		$destinos = self::transicoes( $pedido->get_status() );

		// phpcs:ignore WordPress.Security.NonceVerification -- só decide se há aviso a imprimir.
		if ( empty( $destinos ) && empty( $_GET['rc-status'] ) ) {
			return;
		}

		$titulo_id = self::ancora( $pedido->get_id() ) . '-titulo';
		// À mão, e não `wp_nonce_field()`: ele imprime `id="_wpnonce"`, que se
		// repetiria em cada botão e colidiria com os formulários do Dokan na
		// mesma página.
		$nonce = wp_create_nonce( self::ACAO . '_' . $pedido->get_id() );
		?>
		<div class="rc-status-rapido" style="width:100%">
			<div class="dokan-panel dokan-panel-default" id="<?php echo esc_attr( self::ancora( $pedido->get_id() ) ); ?>">
				<div class="dokan-panel-heading"><strong id="<?php echo esc_attr( $titulo_id ); ?>"><?php esc_html_e( 'Andamento do pedido', 'reconectar-core' ); ?></strong></div>
				<div class="dokan-panel-body rc-status-rapido__corpo" role="group" aria-labelledby="<?php echo esc_attr( $titulo_id ); ?>">
					<?php self::imprimir_aviso(); ?>
					<?php foreach ( $destinos as $indice => $destino ) : ?>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="rc-status-rapido__form">
							<input type="hidden" name="action" value="<?php echo esc_attr( self::ACAO ); ?>">
							<input type="hidden" name="pedido" value="<?php echo esc_attr( $pedido->get_id() ); ?>">
							<input type="hidden" name="status" value="<?php echo esc_attr( $destino ); ?>">
							<input type="hidden" name="_wpnonce" value="<?php echo esc_attr( $nonce ); ?>">
							<?php wp_referer_field(); ?>
							<button type="submit" class="rc-status-rapido__botao<?php echo 0 === $indice ? ' rc-status-rapido__botao--principal' : ''; ?>">
								<?php echo esc_html( self::rotulo_do_botao( $destino ) ); ?>
							</button>
						</form>
					<?php endforeach; ?>
					<?php if ( ! empty( $destinos ) ) : ?>
					<p class="rc-status-rapido__dica">
						<?php esc_html_e( 'Para cancelar, reembolsar ou voltar um passo, use "Editar" em Detalhes gerais.', 'reconectar-core' ); ?>
					</p>
					<?php endif; ?>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Atende ao POST de um botão.
	 *
	 * O destino é conferido contra `transicoes()` do status **gravado**, não do
	 * que a tela mostrava: com duas abas abertas, ou com o comprador mudando o
	 * pedido entre o carregamento e o clique, o botão pode ter ficado velho. Um
	 * "Iniciar preparação" num pedido já concluído voltaria o pedido atrás sem
	 * que ninguém tivesse pedido isso — daí o aviso de tela desatualizada no
	 * lugar da troca.
	 *
	 * Nada é impresso antes do redirect: um aviso do PHP aqui derrubaria os
	 * `header()` de `wp_safe_redirect()` e a troca pareceria não ter acontecido.
	 *
	 * @return void
	 */
	public static function processar() {
		// phpcs:ignore WordPress.Security.NonceVerification -- o nonce é conferido logo abaixo, e a ação dele inclui este id.
		$pedido_id = isset( $_POST['pedido'] ) ? (int) $_POST['pedido'] : 0;

		check_admin_referer( self::ACAO . '_' . $pedido_id );

		$destino = isset( $_POST['status'] ) ? sanitize_key( wp_unslash( $_POST['status'] ) ) : '';
		$pedido  = wc_get_order( $pedido_id );

		if ( ! self::pode( $pedido ) ) {
			wp_die(
				esc_html__( 'Você não administra a loja deste pedido.', 'reconectar-core' ),
				esc_html__( 'Ação não permitida', 'reconectar-core' ),
				array(
					'response'  => 403,
					'back_link' => true,
				)
			);
		}

		if ( ! in_array( $destino, self::transicoes( $pedido->get_status() ), true ) ) {
			self::voltar( 'desatualizado', $pedido_id );
		}

		$pedido->update_status(
			substr( $destino, 3 ),
			sprintf(
				/* translators: %s: nome de quem alterou o status. */
				__( 'Status alterado pelo painel da loja por %s.', 'reconectar-core' ),
				wp_get_current_user()->display_name
			)
		);

		self::voltar( 'alterado', $pedido_id );
	}

	/**
	 * Devolve a loja ao detalhe do pedido, com o aviso e a âncora do painel.
	 *
	 * `wp_safe_redirect` recusa destino externo, então o `Referer` serve direto.
	 * Quando ele falta, o destino é o detalhe do pedido no painel, montado pelo
	 * Dokan — nunca a raiz do painel, onde a loja perderia o pedido de vista.
	 *
	 * @param string $aviso     Chave do aviso, lida por `imprimir_aviso()`.
	 * @param int    $pedido_id ID do sub-pedido.
	 * @return void
	 */
	private static function voltar( $aviso, $pedido_id ) {
		$destino = wp_get_referer();

		if ( ! $destino && function_exists( 'dokan_get_navigation_url' ) ) {
			$destino = add_query_arg( 'order_id', $pedido_id, dokan_get_navigation_url( 'orders' ) );
		}

		$destino  = add_query_arg( 'rc-status', $aviso, remove_query_arg( 'rc-status', strtok( (string) $destino, '#' ) ) );
		$destino .= '#' . self::ancora( $pedido_id );

		wp_safe_redirect( $destino );
		exit;
	}

	/**
	 * Imprime o desfecho do último clique, se houver.
	 *
	 * `role="status"` para o leitor de tela anunciar sem roubar o foco: a
	 * página recarregou, e quem navega por teclado precisa saber se o clique
	 * valeu sem ter de procurar o selo lá em cima.
	 *
	 * @return void
	 */
	private static function imprimir_aviso() {
		// phpcs:ignore WordPress.Security.NonceVerification -- só escolhe qual texto fixo imprimir.
		$aviso = isset( $_GET['rc-status'] ) ? sanitize_key( wp_unslash( $_GET['rc-status'] ) ) : '';

		$mensagens = array(
			'alterado'      => __( 'Status do pedido atualizado.', 'reconectar-core' ),
			'desatualizado' => __( 'O pedido mudou desde que esta tela foi aberta. Confira o status atual e tente de novo.', 'reconectar-core' ),
		);

		if ( ! isset( $mensagens[ $aviso ] ) ) {
			return;
		}

		printf(
			'<p class="rc-status-rapido__aviso rc-status-rapido__aviso--%1$s" role="status">%2$s</p>',
			esc_attr( 'alterado' === $aviso ? 'ok' : 'erro' ),
			esc_html( $mensagens[ $aviso ] )
		);
	}
}
