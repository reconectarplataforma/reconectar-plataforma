<?php
/**
 * Pagamento por PIX, direto na chave da loja.
 *
 * @package reconectar-core
 */

defined( 'ABSPATH' ) || exit;

/**
 * Oferece o PIX no checkout e imprime a chave de cada loja depois do pedido.
 */
class Reconectar_Gateway_Pix extends Reconectar_Gateway_Direto {

	/**
	 * Monta o gateway.
	 */
	public function __construct() {
		$this->id           = Reconectar_Pagamento_Direto::GATEWAY_PIX;
		$this->metodo_dokan = Reconectar_Pagamento_Pix::METODO;
		$this->icon         = '';

		parent::__construct();
	}

	/**
	 * Título de fábrica.
	 *
	 * @return string Título.
	 */
	protected function titulo_padrao() {
		return __( 'PIX', 'reconectar-core' );
	}

	/**
	 * Descrição de fábrica.
	 *
	 * @return string Descrição.
	 */
	protected function descricao_padrao() {
		return __( 'Ao finalizar, daremos instruções para pagar.', 'reconectar-core' );
	}

	/**
	 * Explicação para quem configura o gateway.
	 *
	 * @return string Descrição administrativa.
	 */
	protected function descricao_administrativa() {
		return __( 'O comprador paga direto na chave PIX de cada loja. O dinheiro não passa pela plataforma, e não há confirmação automática: a loja confirma o recebimento mudando o status do pedido. A chave é cadastrada pela própria loja em Configurações → Pagamento, na dashboard dela.', 'reconectar-core' );
	}

	/**
	 * Ícone do PIX: o losango da marca, em círculo.
	 *
	 * Desenho autoral e não a marca registrada do Banco Central — a forma
	 * evoca o meio sem reproduzir logotipo de terceiro, que teria licença
	 * própria e não caberia num repositório GPL.
	 *
	 * @return string SVG do ícone.
	 */
	public function icone() {
		return '<svg class="rc-meio-icone rc-meio-icone--pix" viewBox="0 0 32 32" role="presentation" focusable="false" aria-hidden="true">'
			. '<circle cx="16" cy="16" r="16" fill="currentColor" opacity="0.14"/>'
			. '<path d="M16 7.5 24.5 16 16 24.5 7.5 16Z" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linejoin="round"/>'
			. '<path d="M12.4 12.4 16 16l3.6-3.6M12.4 19.6 16 16l3.6 3.6" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"/>'
			. '</svg>';
	}

	/**
	 * Diz se a loja tem chave PIX cadastrada.
	 *
	 * @param int $loja_id Identificador da loja.
	 * @return bool Se a loja recebe por PIX.
	 */
	public function loja_recebe( $loja_id ) {
		return Reconectar_Pagamento_Pix::loja_recebe( $loja_id );
	}

	/**
	 * Imprime a chave da loja, o QR Code e o código copia-e-cola.
	 *
	 * O BR Code é montado com o valor **desta loja**, não com o total do pedido:
	 * quem paga por copia-e-cola não confere o número, e um payload com o total
	 * faria o comprador pagar o carrinho inteiro para a primeira loja da lista.
	 * O QR Code carrega o mesmo payload, e por isso herda a mesma regra.
	 *
	 * @param int           $loja_id  Identificador da loja.
	 * @param float         $valor    Valor que cabe a esta loja.
	 * @param WC_Order|null $pedido   Pedido ou sub-pedido da loja.
	 * @param string        $contexto `tela` ou `email`.
	 * @return void
	 */
	public function imprimir_instrucao( $loja_id, $valor, $pedido, $contexto = 'tela' ) {
		$dados = Reconectar_Pagamento_Pix::dados_da_loja( $loja_id );

		if ( ! $dados ) {
			printf(
				'<p class="rc-pagamento__alerta">%s</p>',
				esc_html__( 'Esta loja ainda não cadastrou uma chave PIX. Entre em contato com a plataforma para combinar o pagamento.', 'reconectar-core' )
			);

			return;
		}

		$tipos  = Reconectar_Pagamento_Pix::tipos_de_chave();
		$rotulo = isset( $tipos[ $dados['tipo_chave'] ] ) ? $tipos[ $dados['tipo_chave'] ] : $dados['tipo_chave'];

		echo '<dl class="rc-pagamento__dados">';
		self::imprimir_par( __( 'Tipo de chave', 'reconectar-core' ), $rotulo );
		self::imprimir_par( __( 'Chave PIX', 'reconectar-core' ), $dados['chave'] );
		self::imprimir_par( __( 'Beneficiário', 'reconectar-core' ), $dados['beneficiario'] );
		self::imprimir_par( __( 'Cidade', 'reconectar-core' ), $dados['cidade'] );
		echo '</dl>';

		/*
		 * O `txid` sai do número do pedido, e é ele que a loja usa para conciliar o
		 * PIX recebido. É também a razão de este bloco não existir no checkout: lá
		 * não há pedido, o `txid` sairia vazio, e o valor ainda pode mudar com frete
		 * e cupom — um código lido antes do fechamento poderia pagar o valor errado.
		 */
		$codigo = reconectar_pix_br_code(
			$dados['chave'],
			$dados['beneficiario'],
			$dados['cidade'],
			$valor,
			$pedido instanceof WC_Order ? (string) $pedido->get_id() : ''
		);

		if ( ! $codigo ) {
			return;
		}

		/*
		 * O QR Code é exclusivo da tela. Não é escolha de layout: SVG embutido é
		 * removido por Gmail, Outlook e outros clientes, e `data:` em `<img>` é
		 * bloqueado como imagem externa por quase todos. No e-mail a imagem sairia
		 * como um retângulo vazio sobre o copia-e-cola que funciona — pior que a
		 * ausência dela, pelo mesmo raciocínio do botão de copiar logo abaixo.
		 */
		if ( 'tela' === $contexto ) {
			$qr = reconectar_qr_svg(
				$codigo,
				sprintf(
					/* translators: %s: nome do beneficiário da loja. */
					__( 'QR Code do PIX de %s', 'reconectar-core' ),
					$dados['beneficiario']
				),
				array( 'class' => 'rc-pagamento__qr-imagem' )
			);

			if ( $qr ) {
				printf(
					'<figure class="rc-pagamento__qr">%1$s<figcaption class="rc-pagamento__qr-legenda">%2$s</figcaption></figure>',
					$qr, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- SVG montado por reconectar_qr_svg(), que escapa cada atributo.
					esc_html__( 'Abra o app do seu banco, escolha PIX e aponte a câmera para este código.', 'reconectar-core' )
				);
			}
		}

		printf(
			'<p class="rc-pagamento__rotulo">%s</p>',
			esc_html__( 'PIX copia e cola', 'reconectar-core' )
		);
		printf(
			'<code class="rc-pagamento__codigo">%s</code>',
			esc_html( $codigo )
		);

		// O botão só existe na tela: e-mail não executa JavaScript, e um botão
		// morto no corpo da mensagem seria pior que a ausência dele.
		if ( 'tela' === $contexto ) {
			printf(
				'<button type="button" class="rc-pagamento__copiar" data-rc-copiar="%1$s">%2$s</button>',
				esc_attr( $codigo ),
				esc_html__( 'Copiar código', 'reconectar-core' )
			);
		}
	}

	/**
	 * Imprime um par rótulo/valor da lista de dados.
	 *
	 * @param string $rotulo Rótulo.
	 * @param string $valor  Valor.
	 * @return void
	 */
	private static function imprimir_par( $rotulo, $valor ) {
		printf(
			'<dt class="rc-pagamento__rotulo">%1$s</dt><dd class="rc-pagamento__valor-dado">%2$s</dd>',
			esc_html( $rotulo ),
			esc_html( $valor )
		);
	}
}
