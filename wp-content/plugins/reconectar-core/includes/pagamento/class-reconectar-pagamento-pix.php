<?php
/**
 * PIX e transferência bancária como dados da própria loja.
 *
 * O comprador paga a loja direto: a plataforma não retém valor, não repassa e
 * não confirma. Esta classe cuida do lado da loja — os campos onde ela cadastra
 * a chave, na tela **Configurações → Pagamento** da dashboard do Dokan.
 *
 * **Vocabulário.** O Dokan chama isso internamente de *withdraw method*, porque
 * no desenho original do plugin a plataforma recebe e depois saca para o
 * vendedor. Aqui não há saque nenhum, e a tela que o lojista vê se chama
 * Pagamento. O descompasso fica: construir uma aba própria só para renomear um
 * conceito interno custaria um template inteiro e a manutenção dele a cada
 * atualização do Dokan.
 *
 * @package reconectar-core
 */

defined( 'ABSPATH' ) || exit;

/**
 * Registra o método PIX na dashboard do Dokan e guarda os dados da loja.
 */
class Reconectar_Pagamento_Pix {

	/**
	 * Chave do método dentro do array de pagamento do perfil Dokan.
	 *
	 * @var string
	 */
	const METODO = 'pix';

	/**
	 * Tipos de chave aceitos, do rótulo à validação.
	 *
	 * O tipo é gravado junto da chave porque a validação depende dele e porque
	 * a tela de agradecimento precisa dizer ao comprador **o que** ele está
	 * colando — "chave aleatória" e "CPF" se digitam no mesmo campo do app do
	 * banco, mas quem paga confere de formas diferentes.
	 *
	 * @return array<string,string> Tipo => rótulo traduzido.
	 */
	public static function tipos_de_chave() {
		return array(
			'cpf'       => __( 'CPF', 'reconectar-core' ),
			'cnpj'      => __( 'CNPJ', 'reconectar-core' ),
			'email'     => __( 'E-mail', 'reconectar-core' ),
			'telefone'  => __( 'Telefone', 'reconectar-core' ),
			'aleatoria' => __( 'Chave aleatória', 'reconectar-core' ),
		);
	}

	/**
	 * Registra os ganchos.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'parse_request', array( __CLASS__, 'reparar_query_var_de_configuracoes' ) );
		add_filter( 'dokan_withdraw_methods', array( __CLASS__, 'registrar_metodo' ) );
		add_filter( 'dokan_withdraw_method_icon', array( __CLASS__, 'icone' ), 10, 2 );
		add_filter( 'dokan_withdraw_method_settings_title', array( __CLASS__, 'titulo_da_tela' ), 10, 2 );
		add_filter( 'dokan_withdraw_method_additional_info', array( __CLASS__, 'informacao_adicional' ), 10, 2 );
		add_filter( 'dokan_payment_settings_required_fields', array( __CLASS__, 'campos_obrigatorios' ), 10, 2 );
		add_filter( 'dokan_store_profile_settings_args', array( __CLASS__, 'gravar' ) );
		add_filter( 'dokan_get_dashboard_nav', array( __CLASS__, 'ocultar_menu_de_saque' ) );
	}

	/**
	 * Devolve à query var `settings` o valor que veio do permalink.
	 *
	 * `WP::parse_request()` monta cada query var pública nesta ordem:
	 * `extra_query_vars`, depois `$_POST`, depois `$_GET` e **só então** o que
	 * o permalink resolveu. Quem chega por POST vence o permalink.
	 *
	 * O Dokan registra `settings` como query var pública — é o endpoint das
	 * telas de configuração da dashboard — e os formulários dessas mesmas telas
	 * têm campos chamados `settings[bank][…]`. Quando esse formulário é
	 * submetido **sem JavaScript**, o array do POST sobrescreve em silêncio o
	 * nome da sub-tela: medido, `get_query_var( 'settings' )` devolvia
	 * `{"bank":{"ac_name":"Teste"}}` onde deveria estar
	 * `"payment-manage-bank"`. Sem saber em que sub-tela está, o Dokan cai no
	 * template da loja e `render_settings_header()` morre em
	 * `substr( Array, 0, 7 )`: erro 500 numa tela pública. **O defeito é do
	 * Dokan contra ele mesmo** — derruba o `bank` nativo exatamente igual.
	 *
	 * O que este reparo **não** faz é salvar. A gravação do perfil só existe
	 * em `Settings::ajax_settings()`, acionada por `admin-ajax.php`; não há
	 * caminho de POST comum no Dokan Lite 5.1.3. Sem JavaScript o formulário
	 * não grava de jeito nenhum, e o que se ganha aqui é a tela voltar em vez
	 * de o servidor devolver 500.
	 *
	 * O reparo tem de acontecer em `parse_request` — que roda depois de as
	 * query vars estarem montadas e antes de o Dokan as ler. A fonte da verdade
	 * é `$wp->matched_query`, a query string que a regra de reescrita produziu:
	 * ali `settings` ainda é a string do permalink, intocada pelo POST.
	 *
	 * A guarda é `is_array()`, e não o nome do método: nenhum slug de sub-tela é
	 * array, então a condição só alcança o caso corrompido e não interfere em
	 * quem mais use uma query var homônima.
	 *
	 * @param WP $wp Ambiente da requisição, por referência.
	 * @return void
	 */
	public static function reparar_query_var_de_configuracoes( $wp ) {
		if ( ! isset( $wp->query_vars['settings'] ) || ! is_array( $wp->query_vars['settings'] ) ) {
			return;
		}

		$do_permalink = array();
		parse_str( (string) $wp->matched_query, $do_permalink );

		if ( isset( $do_permalink['settings'] ) && is_string( $do_permalink['settings'] ) ) {
			$wp->query_vars['settings'] = $do_permalink['settings'];

			return;
		}

		// Sem valor de permalink, tirar a query var é mais seguro que deixar o
		// array: o Dokan trata a ausência como "tela principal" e segue de pé.
		unset( $wp->query_vars['settings'] );
	}

	/**
	 * Acrescenta o PIX à lista de métodos que o Dokan conhece.
	 *
	 * Registrar aqui **não** basta para o método aparecer: o Dokan só exibe o
	 * que estiver na opção `dokan_withdraw['withdraw_methods']`, cujo padrão é
	 * `['paypal']`. Sem o `wp option patch` do `provision.sh`, a tela de
	 * pagamento abre dizendo que nenhum método está disponível — e nada nela
	 * indica que a causa é uma opção, o que manda a investigação para este
	 * arquivo, onde não há defeito nenhum.
	 *
	 * @param array $metodos Métodos já registrados.
	 * @return array Métodos com o PIX incluído.
	 */
	public static function registrar_metodo( $metodos ) {
		$metodos[ self::METODO ] = array(
			'title'    => __( 'PIX', 'reconectar-core' ),
			'callback' => array( __CLASS__, 'imprimir_campos' ),
		);

		return $metodos;
	}

	/**
	 * Ícone do método na lista.
	 *
	 * @param string $icone  Ícone atual.
	 * @param string $metodo Método sendo desenhado.
	 * @return string Ícone.
	 */
	public static function icone( $icone, $metodo ) {
		if ( self::METODO !== $metodo ) {
			return $icone;
		}

		return '<i class="fas fa-bolt" aria-hidden="true"></i>';
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

		return __( 'Receber por PIX', 'reconectar-core' );
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

		return __( 'O comprador paga direto na sua chave. A plataforma não recebe nem repassa esse valor.', 'reconectar-core' );
	}

	/**
	 * Declara quais campos precisam estar preenchidos para o método contar como configurado.
	 *
	 * São os quatro, e não só a chave: nome e cidade do beneficiário são o que
	 * falta para montar um BR Code válido. Uma loja com chave e sem cidade
	 * apareceria como "conectada" e entregaria ao comprador um copia-e-cola que
	 * o app do banco recusa.
	 *
	 * O filtro **já vem por método** — o segundo argumento diz qual —, então o
	 * retorno é a lista rasa dos nomes de campo, não um mapa indexado pelo
	 * método. Devolver `array( 'pix' => array( … ) )` monta uma estrutura de
	 * aparência correta e faz o Dokan usar cada sub-array como chave de
	 * `isset()`: fatal de "Illegal offset type" em `is_seller_connected()`, que
	 * derruba a tela inteira de pagamento — inclusive a dos outros métodos.
	 *
	 * @param array  $campos Campos exigidos para o método recebido.
	 * @param string $metodo Método em questão.
	 * @return array Campos exigidos.
	 */
	public static function campos_obrigatorios( $campos, $metodo = '' ) {
		if ( self::METODO !== $metodo ) {
			return $campos;
		}

		return array( 'tipo_chave', 'chave', 'beneficiario', 'cidade' );
	}

	/**
	 * Põe a chave na forma que o arranjo PIX exige.
	 *
	 * O BACEN registra a chave sem formatação: CPF e CNPJ em dígitos puros,
	 * telefone em `+55` seguido de DDD e número. Quem cadastra digita como
	 * escreve — `12.345.678/0001-90` —, e a pontuação entra inteira no campo 26
	 * do BR Code. O payload continua com CRC válido, então nada acusa o
	 * problema aqui: **o app do banco é que recusa**, na hora do pagamento, longe
	 * de qualquer tela que pudesse explicar o motivo.
	 *
	 * A chave aleatória é UUID e passa intacta: ela já vem na forma canônica, e
	 * tirar os hifens dela quebraria a chave em vez de arrumá-la.
	 *
	 * @param string $tipo  Tipo de chave já validado.
	 * @param string $chave Chave como veio do formulário.
	 * @return string Chave normalizada.
	 */
	public static function normalizar_chave( $tipo, $chave ) {
		$chave = trim( $chave );

		if ( 'cpf' === $tipo || 'cnpj' === $tipo ) {
			return preg_replace( '/\D/', '', $chave );
		}

		if ( 'telefone' === $tipo ) {
			$digitos = preg_replace( '/\D/', '', $chave );

			// Dez ou onze dígitos é número brasileiro sem código de país: DDD
			// mais oito ou nove. Acima disso o código já veio digitado, e
			// acrescentar outro geraria uma chave que não existe.
			if ( 10 === strlen( $digitos ) || 11 === strlen( $digitos ) ) {
				$digitos = '55' . $digitos;
			}

			return $digitos ? '+' . $digitos : '';
		}

		if ( 'email' === $tipo ) {
			return strtolower( $chave );
		}

		return $chave;
	}

	/**
	 * Lê os dados de PIX de uma loja.
	 *
	 * @param int $loja_id ID do usuário da loja.
	 * @return array<string,string> Dados normalizados; vazio quando incompleto.
	 */
	public static function dados_da_loja( $loja_id ) {
		$perfil = get_user_meta( $loja_id, 'dokan_profile_settings', true );

		if ( ! is_array( $perfil ) || empty( $perfil['payment'][ self::METODO ] ) ) {
			return array();
		}

		$pix = $perfil['payment'][ self::METODO ];

		$tipo = isset( $pix['tipo_chave'] ) ? (string) $pix['tipo_chave'] : '';

		$dados = array(
			'tipo_chave'   => $tipo,
			// Normalizada também na leitura, e não só na gravação: as chaves
			// cadastradas antes desta regra continuam pontuadas no banco, e uma
			// migração versionada seria desproporcional para um valor que se
			// recalcula sem perda a cada leitura.
			'chave'        => self::normalizar_chave( $tipo, isset( $pix['chave'] ) ? (string) $pix['chave'] : '' ),
			'beneficiario' => isset( $pix['beneficiario'] ) ? (string) $pix['beneficiario'] : '',
			'cidade'       => isset( $pix['cidade'] ) ? (string) $pix['cidade'] : '',
		);

		// Tudo ou nada: um conjunto parcial geraria BR Code inválido, e a regra
		// de honestidade de dados do projeto prefere campo ausente a valor que
		// parece certo.
		foreach ( $dados as $valor ) {
			if ( '' === trim( $valor ) ) {
				return array();
			}
		}

		return $dados;
	}

	/**
	 * Diz se a loja pode receber por PIX.
	 *
	 * @param int $loja_id ID do usuário da loja.
	 * @return bool
	 */
	public static function loja_recebe( $loja_id ) {
		return array() !== self::dados_da_loja( $loja_id );
	}

	/**
	 * Imprime os campos do PIX na tela de pagamento da dashboard.
	 *
	 * @param array $perfil Perfil Dokan da loja.
	 * @return void
	 */
	public static function imprimir_campos( $perfil ) {
		$pix = isset( $perfil['payment'][ self::METODO ] ) && is_array( $perfil['payment'][ self::METODO ] )
			? $perfil['payment'][ self::METODO ]
			: array();

		$valor = static function ( $chave ) use ( $pix ) {
			return isset( $pix[ $chave ] ) ? $pix[ $chave ] : '';
		};
		?>
		<div class="dokan-form-group">
			<label class="dokan-w3 dokan-control-label" for="reconectar-pix-tipo">
				<?php esc_html_e( 'Tipo de chave', 'reconectar-core' ); ?>
			</label>
			<div class="dokan-w5 dokan-text-left">
				<select
					class="dokan-form-control"
					id="reconectar-pix-tipo"
					name="settings[<?php echo esc_attr( self::METODO ); ?>][tipo_chave]"
				>
					<option value=""><?php esc_html_e( 'Selecione…', 'reconectar-core' ); ?></option>
					<?php foreach ( self::tipos_de_chave() as $tipo => $rotulo ) : ?>
						<option value="<?php echo esc_attr( $tipo ); ?>" <?php selected( $valor( 'tipo_chave' ), $tipo ); ?>>
							<?php echo esc_html( $rotulo ); ?>
						</option>
					<?php endforeach; ?>
				</select>
			</div>
		</div>

		<div class="dokan-form-group">
			<label class="dokan-w3 dokan-control-label" for="reconectar-pix-chave">
				<?php esc_html_e( 'Chave PIX', 'reconectar-core' ); ?>
			</label>
			<div class="dokan-w5 dokan-text-left">
				<input
					type="text"
					class="dokan-form-control"
					id="reconectar-pix-chave"
					name="settings[<?php echo esc_attr( self::METODO ); ?>][chave]"
					value="<?php echo esc_attr( $valor( 'chave' ) ); ?>"
					autocomplete="off"
				>
				<p class="description">
					<?php esc_html_e( 'É para esta chave que o comprador vai transferir. Confira dígito por dígito.', 'reconectar-core' ); ?>
				</p>
			</div>
		</div>

		<div class="dokan-form-group">
			<label class="dokan-w3 dokan-control-label" for="reconectar-pix-beneficiario">
				<?php esc_html_e( 'Nome do beneficiário', 'reconectar-core' ); ?>
			</label>
			<div class="dokan-w5 dokan-text-left">
				<input
					type="text"
					class="dokan-form-control"
					id="reconectar-pix-beneficiario"
					name="settings[<?php echo esc_attr( self::METODO ); ?>][beneficiario]"
					value="<?php echo esc_attr( $valor( 'beneficiario' ) ); ?>"
					maxlength="25"
				>
				<p class="description">
					<?php esc_html_e( 'Como aparece na sua conta. Até 25 caracteres — é o limite do padrão do PIX, não nosso.', 'reconectar-core' ); ?>
				</p>
			</div>
		</div>

		<div class="dokan-form-group">
			<label class="dokan-w3 dokan-control-label" for="reconectar-pix-cidade">
				<?php esc_html_e( 'Cidade do beneficiário', 'reconectar-core' ); ?>
			</label>
			<div class="dokan-w5 dokan-text-left">
				<input
					type="text"
					class="dokan-form-control"
					id="reconectar-pix-cidade"
					name="settings[<?php echo esc_attr( self::METODO ); ?>][cidade]"
					value="<?php echo esc_attr( $valor( 'cidade' ) ); ?>"
					maxlength="15"
				>
				<p class="description">
					<?php esc_html_e( 'Até 15 caracteres, também por exigência do padrão.', 'reconectar-core' ); ?>
				</p>
			</div>
		</div>
		<?php
	}

	/**
	 * Grava os campos do PIX ao salvar as configurações de pagamento.
	 *
	 * **Esta é a lacuna que o Dokan deixa, e a razão de o método existir.**
	 * `Dashboard\Templates\Settings::insert_settings_info()` monta o array de
	 * pagamento com `bank` e `paypal` escritos à mão; qualquer outro método que
	 * um plugin registre chega em `$_POST['settings']` e é **descartado em
	 * silêncio**. O formulário salva, a tela recarrega sem erro e o campo volta
	 * vazio — o sintoma pior deste repositório, o de código que está no lugar
	 * certo e não faz nada.
	 *
	 * O único gancho que alcança o array antes da gravação é
	 * `dokan_store_profile_settings_args`, e ele dispara em **todos** os
	 * caminhos de salvamento do perfil: Loja, Pagamento, SEO. Sem a guarda de
	 * nonce abaixo, salvar a aba Loja entraria aqui com `$_POST['settings']`
	 * sem a chave `pix` e sobrescreveria os dados de pagamento com vazio.
	 *
	 * @param array $args Perfil prestes a ser gravado.
	 * @return array Perfil com os dados de PIX preservados.
	 */
	public static function gravar( $args ) {
		/*
		 * Duas condições, e as duas são necessárias.
		 *
		 * O botão de submit é o que **identifica o formulário**: só a tela de
		 * pagamento o envia, e é ele que distingue este salvamento do da aba
		 * Loja, que entra por aqui com um `settings` sem a chave `pix`.
		 *
		 * O nonce é o que **valida a origem**. Ele não chega num campo com nome
		 * próprio: o Dokan usa `wp_nonce_field( 'dokan_payment_settings_nonce' )`,
		 * e essa função grava o campo com o nome padrão `_wpnonce` — o nome da
		 * ação some do HTML. Procurar um `$_POST['dokan_payment_settings_nonce']`
		 * devolve vazio para sempre, a função retorna cedo e o campo volta em
		 * branco depois de salvar, sem erro nenhum na tela. Foi exatamente o que
		 * aconteceu aqui na primeira tentativa.
		 */
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

		$tipo = isset( $enviado['tipo_chave'] ) ? sanitize_key( $enviado['tipo_chave'] ) : '';

		if ( ! array_key_exists( $tipo, self::tipos_de_chave() ) ) {
			$tipo = '';
		}

		$args['payment'][ self::METODO ] = array(
			'tipo_chave'   => $tipo,
			'chave'        => self::normalizar_chave( $tipo, isset( $enviado['chave'] ) ? sanitize_text_field( $enviado['chave'] ) : '' ),
			// O truncamento acompanha o `maxlength` do formulário, porque
			// `maxlength` é do navegador e some num POST montado à mão. Cortar
			// aqui é o que garante que o BR Code nunca estoure o campo.
			'beneficiario' => isset( $enviado['beneficiario'] ) ? substr( sanitize_text_field( $enviado['beneficiario'] ), 0, 25 ) : '',
			'cidade'       => isset( $enviado['cidade'] ) ? substr( sanitize_text_field( $enviado['cidade'] ), 0, 15 ) : '',
		);

		return $args;
	}

	/**
	 * Tira o menu Saque da dashboard da loja.
	 *
	 * Com o comprador pagando a loja direto, a plataforma nunca retém saldo. Um
	 * menu de saque prometeria um repasse que não existe, e o lojista ficaria
	 * esperando por um dinheiro que já está na conta dele.
	 *
	 * @param array $nav Itens de navegação da dashboard.
	 * @return array Navegação sem o saque.
	 */
	public static function ocultar_menu_de_saque( $nav ) {
		unset( $nav['withdraw'] );

		return $nav;
	}
}
