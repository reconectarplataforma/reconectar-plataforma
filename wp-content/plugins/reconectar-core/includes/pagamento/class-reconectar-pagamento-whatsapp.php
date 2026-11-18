<?php
/**
 * WhatsApp da loja, para combinar o pagamento com o comprador.
 *
 * Pedido de quem vende: parte das lojas recebe em dinheiro na entrega, no
 * cartão da maquininha ou por um PIX que prefere passar na conversa, e nenhum
 * desses caminhos cabe num dado que a plataforma possa mostrar de antemão. O
 * que a loja cadastra aqui é só o número para onde o comprador vai escrever.
 *
 * Mora na mesma tela que o PIX e a conta bancária — **Configurações →
 * Pagamento** da dashboard do Dokan — e pelos mesmos ganchos, com as mesmas
 * guardas. O comentário longo de cada um está em `Reconectar_Pagamento_Pix`;
 * aqui fica só o que muda.
 *
 * @package reconectar-core
 */

defined( 'ABSPATH' ) || exit;

/**
 * Registra o método WhatsApp na dashboard do Dokan e guarda o número da loja.
 */
class Reconectar_Pagamento_Whatsapp {

	/**
	 * Chave do método dentro do array de pagamento do perfil Dokan.
	 *
	 * É também a chave do mapa `dokan_withdraw['withdraw_methods']`, que o
	 * `provision.sh` liga: sem ela o método não aparece na tela da loja.
	 *
	 * @var string
	 */
	const METODO = 'whatsapp';

	/**
	 * Limite do recado da loja ao comprador.
	 *
	 * Curto de propósito: o recado sai no cartão de pagamento e no e-mail, ao
	 * lado do botão, e um texto longo empurraria o botão para fora da tela do
	 * celular. Quem tiver mais a dizer diz na conversa.
	 *
	 * @var int
	 */
	const LIMITE_RECADO = 140;

	/**
	 * Registra os ganchos.
	 *
	 * @return void
	 */
	public static function init() {
		add_filter( 'dokan_withdraw_methods', array( __CLASS__, 'registrar_metodo' ) );
		add_filter( 'dokan_withdraw_method_icon', array( __CLASS__, 'icone' ), 10, 2 );
		add_filter( 'dokan_withdraw_method_settings_title', array( __CLASS__, 'titulo_da_tela' ), 10, 2 );
		add_filter( 'dokan_withdraw_method_additional_info', array( __CLASS__, 'informacao_adicional' ), 10, 2 );
		add_filter( 'dokan_payment_settings_required_fields', array( __CLASS__, 'campos_obrigatorios' ), 10, 2 );
		add_filter( 'dokan_store_profile_settings_args', array( __CLASS__, 'gravar' ) );
	}

	/**
	 * Acrescenta o WhatsApp à lista de métodos que o Dokan conhece.
	 *
	 * @param array $metodos Métodos já registrados.
	 * @return array Métodos com o WhatsApp incluído.
	 */
	public static function registrar_metodo( $metodos ) {
		$metodos[ self::METODO ] = array(
			'title'    => __( 'Combinar pelo WhatsApp', 'reconectar-core' ),
			'callback' => array( __CLASS__, 'imprimir_campos' ),
		);

		return $metodos;
	}

	/**
	 * URL do ícone do método na lista.
	 *
	 * @param string $icone  URL do ícone atual.
	 * @param string $metodo Método sendo desenhado.
	 * @return string URL do ícone.
	 */
	public static function icone( $icone, $metodo ) {
		if ( self::METODO !== $metodo ) {
			return $icone;
		}

		return RECONECTAR_CORE_URL . 'assets/img/meio-whatsapp.svg';
	}

	/**
	 * Título da tela de configuração do método.
	 *
	 * @param string $titulo Título atual.
	 * @param string $metodo Método sendo configurado.
	 * @return string Título.
	 */
	public static function titulo_da_tela( $titulo, $metodo ) {
		if ( self::METODO !== $metodo ) {
			return $titulo;
		}

		return __( 'Combinar o pagamento pelo WhatsApp', 'reconectar-core' );
	}

	/**
	 * Linha de apoio sob o nome do método.
	 *
	 * @param string $info   Texto atual.
	 * @param string $metodo Método sendo listado.
	 * @return string Texto de apoio.
	 */
	public static function informacao_adicional( $info, $metodo ) {
		if ( self::METODO !== $metodo ) {
			return $info;
		}

		return __( 'O comprador fala com você pelo WhatsApp e vocês combinam como pagar — dinheiro, cartão na entrega ou o que você aceitar.', 'reconectar-core' );
	}

	/**
	 * Declara o número como único campo exigido.
	 *
	 * Lista rasa, pelo mesmo motivo registrado em
	 * `Reconectar_Pagamento_Pix::campos_obrigatorios()`.
	 *
	 * @param array  $campos Campos exigidos para o método recebido.
	 * @param string $metodo Método em questão.
	 * @return array Campos exigidos.
	 */
	public static function campos_obrigatorios( $campos, $metodo = '' ) {
		if ( self::METODO !== $metodo ) {
			return $campos;
		}

		return array( 'numero' );
	}

	/**
	 * Reduz o número aos dígitos, com o código do país.
	 *
	 * O `wa.me` recebe o número **só em dígitos**, com o código do país e sem o
	 * `+`; qualquer pontuação na URL e o WhatsApp abre dizendo que o número não
	 * está no aplicativo, longe de qualquer tela que explique o porquê. É a
	 * mesma regra do telefone como chave PIX, menos o `+`.
	 *
	 * @param string $numero Número como veio do formulário.
	 * @return string Só dígitos, com o 55 quando faltava.
	 */
	public static function normalizar_numero( $numero ) {
		$digitos = preg_replace( '/\D/', '', (string) $numero );

		// Dez ou onze dígitos é número brasileiro sem código de país: DDD mais
		// oito ou nove. Acima disso o código já veio digitado.
		if ( 10 === strlen( $digitos ) || 11 === strlen( $digitos ) ) {
			$digitos = '55' . $digitos;
		}

		return $digitos;
	}

	/**
	 * Diz se o número normalizado tem tamanho de telefone discável.
	 *
	 * De 12 a 15 dígitos: o brasileiro com 55 tem 12 ou 13, e 15 é o teto do
	 * E.164. Abaixo de 12 faltou o DDD, e o link abriria uma conversa com
	 * ninguém.
	 *
	 * @param string $digitos Número já normalizado.
	 * @return bool
	 */
	public static function numero_valido( $digitos ) {
		return (bool) preg_match( '/^\d{12,15}$/', (string) $digitos );
	}

	/**
	 * Formata o número para leitura humana.
	 *
	 * Só o brasileiro ganha máscara; o estrangeiro sai em dígitos com `+`, que é
	 * o que se disca em qualquer lugar.
	 *
	 * @param string $digitos Número já normalizado.
	 * @return string Número legível.
	 */
	public static function formatar_numero( $digitos ) {
		if ( preg_match( '/^55(\d{2})(\d{4,5})(\d{4})$/', $digitos, $partes ) ) {
			return sprintf( '+55 (%s) %s-%s', $partes[1], $partes[2], $partes[3] );
		}

		return '+' . $digitos;
	}

	/**
	 * Lê o WhatsApp de uma loja.
	 *
	 * O número é normalizado também na leitura, pela mesma razão da chave PIX:
	 * um valor gravado à mão, ou por carga, não passou pela gravação.
	 *
	 * @param int $loja_id ID do usuário da loja.
	 * @return array{numero:string,recado:string} Vazio quando não há número discável.
	 */
	public static function dados_da_loja( $loja_id ) {
		$dados = Reconectar_Pagamento_Direto::dados_de_pagamento( $loja_id, self::METODO );

		if ( ! $dados ) {
			return array();
		}

		$numero = self::normalizar_numero( isset( $dados['numero'] ) ? $dados['numero'] : '' );

		if ( ! self::numero_valido( $numero ) ) {
			return array();
		}

		return array(
			'numero' => $numero,
			'recado' => isset( $dados['recado'] ) ? trim( (string) $dados['recado'] ) : '',
		);
	}

	/**
	 * Imprime os campos na tela de pagamento da dashboard.
	 *
	 * @param array $perfil Perfil Dokan da loja.
	 * @return void
	 */
	public static function imprimir_campos( $perfil ) {
		$dados = isset( $perfil['payment'][ self::METODO ] ) && is_array( $perfil['payment'][ self::METODO ] )
			? $perfil['payment'][ self::METODO ]
			: array();

		$numero = isset( $dados['numero'] ) ? (string) $dados['numero'] : '';
		$recado = isset( $dados['recado'] ) ? (string) $dados['recado'] : '';

		// Número gravado e não discável é o caso que mais engana: o Dokan dá o
		// método por configurado, porque o campo não está vazio, e o comprador
		// nunca vê a opção no checkout. Dizer aqui é o único lugar onde a loja
		// pode descobrir.
		$incompleto = '' !== $numero && ! self::numero_valido( self::normalizar_numero( $numero ) );
		?>
		<div class="dokan-form-group">
			<label class="dokan-w3 dokan-control-label" for="reconectar-whatsapp-numero">
				<?php esc_html_e( 'Número do WhatsApp', 'reconectar-core' ); ?>
			</label>
			<div class="dokan-w5 dokan-text-left">
				<input
					type="tel"
					class="dokan-form-control"
					id="reconectar-whatsapp-numero"
					name="settings[<?php echo esc_attr( self::METODO ); ?>][numero]"
					value="<?php echo esc_attr( '' !== $numero && ! $incompleto ? self::formatar_numero( self::normalizar_numero( $numero ) ) : $numero ); ?>"
					autocomplete="tel"
					inputmode="tel"
					aria-describedby="reconectar-whatsapp-numero-ajuda"
					<?php echo $incompleto ? 'aria-invalid="true"' : ''; ?>
				>
				<p class="description" id="reconectar-whatsapp-numero-ajuda">
					<?php
					if ( $incompleto ) {
						esc_html_e( 'Este número parece incompleto, e por isso a opção não aparece para o comprador. Informe o DDD e o número, por exemplo (82) 99999-0000.', 'reconectar-core' );
					} else {
						esc_html_e( 'Com DDD, por exemplo (82) 99999-0000. É para este número que o comprador vai escrever depois do pedido.', 'reconectar-core' );
					}
					?>
				</p>
			</div>
		</div>

		<div class="dokan-form-group">
			<label class="dokan-w3 dokan-control-label" for="reconectar-whatsapp-recado">
				<?php esc_html_e( 'Recado ao comprador (opcional)', 'reconectar-core' ); ?>
			</label>
			<div class="dokan-w5 dokan-text-left">
				<input
					type="text"
					class="dokan-form-control"
					id="reconectar-whatsapp-recado"
					name="settings[<?php echo esc_attr( self::METODO ); ?>][recado]"
					value="<?php echo esc_attr( $recado ); ?>"
					maxlength="<?php echo esc_attr( self::LIMITE_RECADO ); ?>"
					aria-describedby="reconectar-whatsapp-recado-ajuda"
				>
				<p class="description" id="reconectar-whatsapp-recado-ajuda">
					<?php
					printf(
						/* translators: %d: limite de caracteres. */
						esc_html__( 'Aparece junto do botão do WhatsApp. Por exemplo: "Aceito dinheiro e cartão na entrega." Até %d caracteres.', 'reconectar-core' ),
						(int) self::LIMITE_RECADO
					);
					?>
				</p>
			</div>
		</div>
		<?php
	}

	/**
	 * Grava o número e o recado ao salvar as configurações de pagamento.
	 *
	 * As três guardas são as de `Reconectar_Pagamento_Pix::gravar()`, e pelo
	 * mesmo motivo: o gancho dispara em todo salvamento do perfil, e sem elas
	 * salvar a aba Loja apagaria o WhatsApp.
	 *
	 * O número é gravado normalizado **mesmo quando não é discável**: apagá-lo
	 * faria o campo voltar vazio depois de salvar, sem dizer o porquê. Gravado,
	 * ele volta com o aviso de `imprimir_campos()`, e `dados_da_loja()` é quem o
	 * recusa para o comprador.
	 *
	 * @param array $args Perfil prestes a ser gravado.
	 * @return array Perfil com o WhatsApp preservado.
	 */
	public static function gravar( $args ) {
		if ( ! isset( $_POST['dokan_update_payment_settings'] ) ) {
			return $args;
		}

		$nonce = isset( $_POST['_wpnonce'] )
			? sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) )
			: '';

		if ( ! $nonce || ! wp_verify_nonce( $nonce, 'dokan_payment_settings_nonce' ) ) {
			return $args;
		}

		if ( empty( $_POST['settings'][ self::METODO ] ) || ! is_array( $_POST['settings'][ self::METODO ] ) ) {
			return $args;
		}

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- cada campo é sanitizado abaixo.
		$enviado = wp_unslash( $_POST['settings'][ self::METODO ] );

		$numero = isset( $enviado['numero'] ) ? sanitize_text_field( $enviado['numero'] ) : '';

		$args['payment'][ self::METODO ] = array(
			'numero' => '' === trim( $numero ) ? '' : self::normalizar_numero( $numero ),
			// Cortado aqui também: `maxlength` é do navegador e some num POST
			// montado à mão.
			'recado' => isset( $enviado['recado'] )
				? mb_substr( sanitize_text_field( $enviado['recado'] ), 0, self::LIMITE_RECADO )
				: '',
		);

		return $args;
	}
}
