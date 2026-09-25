<?php
/**
 * Fechamento do autocadastro de lojas.
 *
 * Quem cadastra loja nesta plataforma é o Administrador e o Administrador de
 * Empresas, pelo caminho que já existe: `Reconectar_Vendedores::criar()`, que
 * confere capacidade, vincula a loja a uma empresa e fixa o papel numa
 * constante. Esta classe não acrescenta nenhum caminho novo — ela fecha os
 * quatro atalhos do Dokan que contornavam aquele, e por isso
 * `class-reconectar-vendedores.php` não muda uma linha.
 *
 * São quatro portas independentes, e a opção `show_register_as_vendor` do Dokan
 * fecha apenas a primeira — e mesmo essa pela metade, como registra o comentário
 * de `ajustar_formularios()`:
 *
 *   1. o checkbox "I am a vendor" no formulário de registro do WooCommerce;
 *   2. o shortcode `[dokan-vendor-registration]`, em qualquer página;
 *   3. o shortcode de onboarding, que a instalação publica em
 *      `/vendor-onboarding/`;
 *   4. o "Become a vendor" que aparece dentro de `/my-account/` já logado.
 *
 * As duas do meio escapam da opção porque
 * `Registration::get_allowed_registration_roles()` reabre o papel `seller`
 * assim que reconhece o nonce do formulário dedicado; a quarta é outro caminho
 * inteiro, que chama `dokan_user_update_to_seller()` sem consultar opção
 * alguma. Daí as camadas abaixo serem cinco para quatro portas: a última não
 * fecha porta nenhuma — ela existe para o dia em que uma atualização do Dokan
 * abrir a quinta.
 *
 * @package reconectar-core
 */

defined( 'ABSPATH' ) || exit;

/**
 * Impede que qualquer pessoa se torne vendedor por conta própria.
 */
class Reconectar_Cadastro_De_Lojas {

	/**
	 * Papel de vendedor do Dokan.
	 */
	const PAPEL_VENDEDOR = 'seller';

	/**
	 * Papel para o qual uma promoção não autorizada é revertida.
	 *
	 * O usuário fica como cliente comum em vez de perder o papel: sem papel
	 * nenhum ele não conseguiria nem abrir a própria conta.
	 */
	const PAPEL_PADRAO = 'customer';

	/**
	 * Evita que a reversão do papel dispare a si mesma.
	 *
	 * @var bool
	 */
	private static $revertendo = false;

	/**
	 * Registra os ganchos das cinco camadas.
	 */
	public static function init() {
		add_filter( 'dokan_register_user_role', array( __CLASS__, 'apenas_cliente' ) );

		// O Dokan registra os shortcodes em `init`; a prioridade 20 chega depois.
		add_action( 'init', array( __CLASS__, 'ajustar_formularios' ), 20 );

		// `wp_loaded` é tarde o bastante para o contêiner do Dokan já existir e
		// cedo o bastante para preceder `template_redirect`, que é onde o
		// manipulador do "Become a vendor" atende ao POST.
		add_action( 'wp_loaded', array( __CLASS__, 'remover_virar_vendedor' ) );

		add_action( 'set_user_role', array( __CLASS__, 'reverter_promocao' ), 10, 3 );
		add_action( 'add_user_role', array( __CLASS__, 'reverter_adicao' ), 10, 2 );
	}

	/**
	 * Restringe a `customer` os papéis aceitos no registro do Dokan.
	 *
	 * Este é o gancho que `get_allowed_registration_roles()` aplica por último,
	 * então ele vale para as três vias de formulário de uma vez — inclusive
	 * quando o nonce do formulário dedicado reabriria `seller`. Com a lista
	 * reduzida, `validate_registration()` recusa qualquer `role=seller` vindo do
	 * `$_POST` e `set_new_vendor_names()` rebaixa quem passar.
	 *
	 * @param string[] $papeis Papéis que o Dokan aceitaria no registro.
	 * @return string[] Apenas o papel de cliente.
	 */
	public static function apenas_cliente( $papeis ) {
		unset( $papeis );

		return array( self::PAPEL_PADRAO );
	}

	/**
	 * Tira do ar os formulários de cadastro de vendedor.
	 *
	 * Sem isto a página de onboarding continuaria exibindo um formulário completo
	 * que o servidor recusaria no envio — pior experiência que não ter formulário
	 * nenhum, e pior indício de segurança: a tela sugere que a via existe.
	 *
	 * `dokan-customer-migration` entra na lista pelo mesmo motivo: é o formulário
	 * que converte um cliente já cadastrado em vendedor.
	 *
	 * Os campos de loja dentro do registro do WooCommerce saem junto, e este é o
	 * detalhe que a opção `show_register_as_vendor` deixa passar: desligada, ela
	 * remove o checkbox mas mantém `dokan_seller_reg_form_fields()` pendurada em
	 * `woocommerce_register_form`. Como era o JavaScript do checkbox que ocultava
	 * esses campos, desligar a opção sem esta linha produz o pior dos dois
	 * mundos — "Nome da loja", "URL" e "Telefone" passam a aparecer sempre, em
	 * todo cadastro de cliente, e com `required`.
	 *
	 * A troca por `campo_papel_cliente()` não é cosmética: aquela mesma função do
	 * Dokan era quem imprimia o `<input type="hidden" name="role">`, e
	 * `Registration::validate_registration()` recusa com "Cheating, eh?" todo
	 * registro que chegue sem `role` preenchido. Remover uma sem pôr a outra
	 * quebraria o cadastro de cliente comum — que é justamente o que esta entrega
	 * precisa manter aberto.
	 */
	public static function ajustar_formularios() {
		$shortcodes = array(
			'dokan-vendor-registration',
			'dokan-vendor-onboarding-registration',
			'dokan-customer-migration',
		);

		foreach ( $shortcodes as $shortcode ) {
			if ( shortcode_exists( $shortcode ) ) {
				remove_shortcode( $shortcode );
			}
		}

		remove_action( 'woocommerce_register_form', 'dokan_seller_reg_form_fields' );
		add_action( 'woocommerce_register_form', array( __CLASS__, 'campo_papel_cliente' ) );
	}

	/**
	 * Declara no formulário de registro o único papel que o servidor aceita.
	 *
	 * O valor não é uma decisão de confiança: ele repete no campo o que
	 * `apenas_cliente()` já impõe no servidor. Um `role` forjado no `$_POST`
	 * continua caindo na lista de papéis permitidos, que tem um item só.
	 */
	public static function campo_papel_cliente() {
		printf( '<input type="hidden" name="role" value="%s" />', esc_attr( self::PAPEL_PADRAO ) );
	}

	/**
	 * Desliga a via "Become a vendor" de dentro da conta do cliente.
	 *
	 * Ela não passa por `get_allowed_registration_roles()` nem por opção alguma:
	 * `BecomeAVendor::become_a_seller_form_handler()` chama
	 * `dokan_user_update_to_seller()`, que faz `add_role( 'seller' )` direto. Os
	 * três ganchos saem juntos — o manipulador do POST, o bloco que o oferece na
	 * conta e o endpoint que serve o formulário.
	 *
	 * A instância precisa ser a que o Dokan registrou, e não uma nova: os
	 * callbacks são arrays `[ $objeto, 'metodo' ]`, e o WordPress compara a
	 * identidade do objeto ao remover. `frontend_manager` está no contêiner como
	 * `addShared`, então `get()` devolve sempre a mesma.
	 *
	 * A guarda é `is_object()` e não `isset()` porque a propriedade não existe de
	 * verdade: ela vem do `__get()` do trait `ChainableContainer`, que não declara
	 * `__isset()`. Um `isset( $frontend->become_a_vendor )` responde `false`
	 * mesmo com o controlador presente — e a primeira versão deste método saía
	 * por aí, em silêncio, deixando as três ações no ar.
	 */
	public static function remover_virar_vendedor() {
		if ( ! function_exists( 'dokan_get_container' ) ) {
			return;
		}

		$frontend    = dokan_get_container()->get( 'frontend_manager' );
		$controlador = is_object( $frontend ) ? $frontend->become_a_vendor : null;

		if ( ! is_object( $controlador ) ) {
			return;
		}

		remove_action( 'template_redirect', array( $controlador, 'become_a_seller_form_handler' ) );
		remove_action( 'woocommerce_after_my_account', array( $controlador, 'render_become_a_vendor_section' ) );
		remove_action( 'woocommerce_account_account-migration_endpoint', array( $controlador, 'load_customer_to_vendor_update_template' ) );
	}

	/**
	 * Desfaz uma troca de papel para vendedor feita sem autorização.
	 *
	 * @param int      $usuario_id     Usuário afetado.
	 * @param string   $papel          Papel atribuído.
	 * @param string[] $papeis_antigos Papéis que ele tinha antes.
	 */
	public static function reverter_promocao( $usuario_id, $papel, $papeis_antigos = array() ) {
		if ( self::PAPEL_VENDEDOR !== $papel || self::autorizado() ) {
			return;
		}

		$anterior = is_array( $papeis_antigos ) && ! empty( $papeis_antigos ) ? reset( $papeis_antigos ) : self::PAPEL_PADRAO;

		if ( self::PAPEL_VENDEDOR === $anterior ) {
			$anterior = self::PAPEL_PADRAO;
		}

		self::aplicar( $usuario_id, 'set_role', $anterior );
	}

	/**
	 * Desfaz o acúmulo do papel de vendedor feito sem autorização.
	 *
	 * Este é o gancho que pega `dokan_user_update_to_seller()`, que acrescenta o
	 * papel em vez de trocá-lo.
	 *
	 * @param int    $usuario_id Usuário afetado.
	 * @param string $papel      Papel acrescentado.
	 */
	public static function reverter_adicao( $usuario_id, $papel ) {
		if ( self::PAPEL_VENDEDOR !== $papel || self::autorizado() ) {
			return;
		}

		self::aplicar( $usuario_id, 'remove_role', self::PAPEL_VENDEDOR );
	}

	/**
	 * Diz se quem está agindo pode cadastrar vendedores.
	 *
	 * Espelha `Reconectar_Vendedores::pode_gerir()`, inclusive a saída antecipada
	 * para a linha de comando: sem ela a carga de demonstração pararia de criar
	 * vendedores, porque `wp_insert_user()` dispara `set_user_role` e não há
	 * usuário logado num processo de CLI.
	 *
	 * @return bool
	 */
	private static function autorizado() {
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			return true;
		}

		if ( ! class_exists( 'Reconectar_Permissoes' ) ) {
			return false;
		}

		return current_user_can( Reconectar_Permissoes::CAP_GERIR_VENDEDORES );
	}

	/**
	 * Aplica a reversão sem reentrar nos próprios ganchos.
	 *
	 * `set_role()` dispara `set_user_role` de novo, agora com `customer`, o que
	 * já não entraria nesta classe; a guarda existe para que uma alteração futura
	 * na condição de entrada não transforme isso em recursão infinita.
	 *
	 * @param int    $usuario_id Usuário afetado.
	 * @param string $metodo     `set_role` ou `remove_role`.
	 * @param string $papel      Papel a aplicar ou remover.
	 */
	private static function aplicar( $usuario_id, $metodo, $papel ) {
		if ( self::$revertendo ) {
			return;
		}

		$usuario = get_userdata( $usuario_id );

		if ( ! $usuario ) {
			return;
		}

		self::$revertendo = true;
		$usuario->$metodo( $papel );
		self::$revertendo = false;
	}
}
