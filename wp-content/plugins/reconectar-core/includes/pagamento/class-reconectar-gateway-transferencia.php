<?php
/**
 * Pagamento por transferência bancária, direto na conta da loja.
 *
 * @package reconectar-core
 */

defined( 'ABSPATH' ) || exit;

/**
 * Oferece a transferência no checkout e imprime a conta de cada loja.
 */
class Reconectar_Gateway_Transferencia extends Reconectar_Gateway_Direto {

	/**
	 * Chave dos dados bancários no perfil Dokan.
	 */
	const METODO = 'bank';

	/**
	 * Campos sem os quais a conta não serve para receber.
	 *
	 * Agência entra na lista porque no Brasil uma conta sem ela não é
	 * identificável — é o mesmo critério de "tudo ou nada" do PIX, e pela mesma
	 * razão: dado pela metade produz uma tela que parece completa e não é.
	 *
	 * @var string[]
	 */
	const CAMPOS_OBRIGATORIOS = array( 'ac_name', 'ac_number', 'bank_name', 'routing_number' );

	/**
	 * Monta o gateway.
	 */
	public function __construct() {
		$this->id           = Reconectar_Pagamento_Direto::GATEWAY_TRANSFERENCIA;
		$this->metodo_dokan = self::METODO;
		$this->icon         = '';

		parent::__construct();
	}

	/**
	 * Rótulos dos campos bancários do Dokan, na ordem em que fazem sentido ler.
	 *
	 * Os nomes das chaves são do Dokan e não se traduzem no código; o que se
	 * traduz é o rótulo, e alguns não são tradução literal: `routing_number` é
	 * o campo de roteamento internacional, e no arranjo bancário brasileiro
	 * quem ocupa esse lugar é a agência.
	 *
	 * @return array<string,string> Chave do Dokan e rótulo em português.
	 */
	public static function rotulos() {
		return array(
			'ac_name'        => __( 'Titular da conta', 'reconectar-core' ),
			'bank_name'      => __( 'Banco', 'reconectar-core' ),
			'routing_number' => __( 'Agência', 'reconectar-core' ),
			'ac_number'      => __( 'Conta', 'reconectar-core' ),
			'ac_type'        => __( 'Tipo de conta', 'reconectar-core' ),
			'bank_addr'      => __( 'Endereço do banco', 'reconectar-core' ),
			'iban'           => __( 'IBAN', 'reconectar-core' ),
			'swift'          => __( 'SWIFT', 'reconectar-core' ),
		);
	}

	/**
	 * Título de fábrica.
	 *
	 * @return string Título.
	 */
	protected function titulo_padrao() {
		return __( 'Transferência bancária', 'reconectar-core' );
	}

	/**
	 * Descrição de fábrica.
	 *
	 * @return string Descrição.
	 */
	protected function descricao_padrao() {
		return __( 'Transfira o valor para a conta de cada loja, que aparece depois de fechar o pedido. A loja confirma o recebimento e o pedido segue.', 'reconectar-core' );
	}

	/**
	 * Explicação para quem configura o gateway.
	 *
	 * @return string Descrição administrativa.
	 */
	protected function descricao_administrativa() {
		return __( 'O comprador transfere direto para a conta de cada loja. O dinheiro não passa pela plataforma, e não há confirmação automática: a loja confirma o recebimento mudando o status do pedido. A conta é cadastrada pela própria loja em Configurações → Pagamento, na dashboard dela.', 'reconectar-core' );
	}

	/**
	 * Lê os dados bancários da loja, exigindo o conjunto completo.
	 *
	 * @param int $loja_id Identificador da loja.
	 * @return array<string,string> Dados gravados, ou vazio quando incompleto.
	 */
	public static function dados_da_loja( $loja_id ) {
		$banco = Reconectar_Pagamento_Direto::dados_de_pagamento( $loja_id, self::METODO );

		if ( ! $banco ) {
			return array();
		}

		foreach ( self::CAMPOS_OBRIGATORIOS as $campo ) {
			if ( empty( $banco[ $campo ] ) || '' === trim( (string) $banco[ $campo ] ) ) {
				return array();
			}
		}

		return $banco;
	}

	/**
	 * Diz se a loja tem conta bancária cadastrada.
	 *
	 * @param int $loja_id Identificador da loja.
	 * @return bool Se a loja recebe por transferência.
	 */
	public function loja_recebe( $loja_id ) {
		return array() !== self::dados_da_loja( $loja_id );
	}

	/**
	 * Imprime os dados bancários da loja.
	 *
	 * @param int      $loja_id  Identificador da loja.
	 * @param float    $valor    Valor que cabe a esta loja.
	 * @param WC_Order $pedido   Pedido ou sub-pedido da loja.
	 * @param string   $contexto `tela` ou `email`.
	 * @return void
	 */
	public function imprimir_instrucao( $loja_id, $valor, $pedido, $contexto = 'tela' ) {
		unset( $valor, $pedido, $contexto );

		$banco = self::dados_da_loja( $loja_id );

		if ( ! $banco ) {
			printf(
				'<p class="rc-pagamento__alerta">%s</p>',
				esc_html__( 'Esta loja ainda não cadastrou uma conta bancária. Entre em contato com a plataforma para combinar o pagamento.', 'reconectar-core' )
			);

			return;
		}

		echo '<dl class="rc-pagamento__dados">';

		foreach ( self::rotulos() as $campo => $rotulo ) {
			if ( empty( $banco[ $campo ] ) ) {
				continue;
			}

			printf(
				'<dt class="rc-pagamento__rotulo">%1$s</dt><dd class="rc-pagamento__valor-dado">%2$s</dd>',
				esc_html( $rotulo ),
				esc_html( (string) $banco[ $campo ] )
			);
		}

		echo '</dl>';
	}
}
