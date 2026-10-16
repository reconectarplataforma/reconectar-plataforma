<?php
/**
 * Detalhes da conta do cliente.
 *
 * Sobrescreve `woocommerce/templates/myaccount/form-edit-account.php` (versão
 * 11.0.0). O que muda é a apresentação: dados pessoais e troca de senha em dois
 * cartões lado a lado, nome e sobrenome numa grade própria em vez dos floats de
 * 47% do Storefront, e o "deixe em branco para não alterar" dito uma vez só, na
 * descrição do grupo de senha, em vez de repetido em dois rótulos.
 *
 * O que **não** muda é o formulário: nomes, IDs e `autocomplete` dos campos são
 * os do original, porque `WC_Form_Handler::save_account_details()` os lê pelo
 * nome e o gerenciador de senhas do navegador os reconhece pelo `autocomplete`.
 * Todos os `do_action` estão preservados na ordem original — o Dokan e qualquer
 * plugin de campo extra se penduram neles, e um `do_action` ausente vira um
 * recurso que some sem erro. Ao atualizar o WooCommerce, comparar este arquivo
 * com o original.
 *
 * Os endereços de cobrança e entrega também moram aqui, no lugar da tela
 * "Endereços" do núcleo. Os campos são os de `form-edit-address.php`, montados por
 * `woocommerce_form_field()` com os mesmos nomes; quem os valida e grava são as
 * funções `reconectar_conta_*` de `inc/marketplace/conta.php`, em volta do handler
 * do núcleo.
 *
 * @package reconectar
 */

defined( 'ABSPATH' ) || exit;

/**
 * Hook - woocommerce_before_edit_account_form.
 *
 * @since 2.6.0
 */
do_action( 'woocommerce_before_edit_account_form' );
?>

<form class="woocommerce-EditAccountForm edit-account rc-editar-conta" action="" method="post" <?php do_action( 'woocommerce_edit_account_form_tag' ); ?> >

	<?php do_action( 'woocommerce_edit_account_form_start' ); ?>

	<?php
	/*
	 * O título visível não é cabeçalho: a página já tem o `<h1>` "Detalhes da
	 * conta", oculto só do olho pela técnica de `.screen-reader-text`, e um `<h2>`
	 * com o mesmo texto seria anunciado duas vezes em seguida. Daí `aria-hidden`
	 * aqui, e os cartões abaixo como `<h2>`, filhos diretos daquele `<h1>`.
	 */
	?>
	<header class="rc-editar-conta__topo">
		<p class="rc-editar-conta__titulo" aria-hidden="true"><?php esc_html_e( 'Detalhes da conta', 'reconectar' ); ?></p>
		<p class="rc-editar-conta__descricao"><?php esc_html_e( 'Seus dados de acesso, o nome que aparece para as lojas e na comunidade, e os endereços de cobrança e entrega.', 'reconectar' ); ?></p>
	</header>

	<div class="rc-editar-conta__grade">

		<section class="rc-editar-conta__cartao" aria-labelledby="rc-editar-conta-dados">
			<h2 class="rc-editar-conta__subtitulo" id="rc-editar-conta-dados"><?php esc_html_e( 'Dados pessoais', 'reconectar' ); ?></h2>

			<div class="rc-editar-conta__dupla">
				<p class="woocommerce-form-row woocommerce-form-row--first form-row form-row-first">
					<label for="account_first_name"><?php esc_html_e( 'First name', 'woocommerce' ); ?>&nbsp;<span class="required" aria-hidden="true">*</span></label>
					<input type="text" class="woocommerce-Input woocommerce-Input--text input-text" name="account_first_name" id="account_first_name" autocomplete="given-name" value="<?php echo esc_attr( $user->first_name ); ?>" aria-required="true" />
				</p>
				<p class="woocommerce-form-row woocommerce-form-row--last form-row form-row-last">
					<label for="account_last_name"><?php esc_html_e( 'Last name', 'woocommerce' ); ?>&nbsp;<span class="required" aria-hidden="true">*</span></label>
					<input type="text" class="woocommerce-Input woocommerce-Input--text input-text" name="account_last_name" id="account_last_name" autocomplete="family-name" value="<?php echo esc_attr( $user->last_name ); ?>" aria-required="true" />
				</p>
			</div>

			<p class="woocommerce-form-row woocommerce-form-row--wide form-row form-row-wide">
				<label for="account_display_name"><?php esc_html_e( 'Display name', 'woocommerce' ); ?>&nbsp;<span class="required" aria-hidden="true">*</span></label>
				<input type="text" class="woocommerce-Input woocommerce-Input--text input-text" name="account_display_name" id="account_display_name" aria-describedby="account_display_name_description" value="<?php echo esc_attr( $user->display_name ); ?>" aria-required="true" />
				<?php // Sem o `<em>` do original: itálico em Poppins de 14px pesa a leitura, e a frase é ajuda, não ênfase. ?>
				<span class="rc-editar-conta__ajuda" id="account_display_name_description"><?php echo wc_reviews_enabled() ? esc_html__( 'This will be how your name will be displayed in the account section and in reviews', 'woocommerce' ) : esc_html__( 'This will be how your name will be displayed in the account section', 'woocommerce' ); ?></span>
			</p>

			<p class="woocommerce-form-row woocommerce-form-row--wide form-row form-row-wide">
				<label for="account_email"><?php esc_html_e( 'Email address', 'woocommerce' ); ?>&nbsp;<span class="required" aria-hidden="true">*</span></label>
				<input type="email" class="woocommerce-Input woocommerce-Input--email input-text" name="account_email" id="account_email" autocomplete="email" value="<?php echo esc_attr( $user->user_email ); ?>" aria-required="true" />
			</p>

			<?php
				/**
				 * Hook where additional fields should be rendered.
				 *
				 * @since 8.7.0
				 */
				do_action( 'woocommerce_edit_account_form_fields' );
			?>
		</section>

		<?php
		/*
		 * `aria-describedby` no `<fieldset>` e não em cada campo: o leitor de tela
		 * anuncia a descrição do grupo ao entrar nele, uma vez, junto da legenda —
		 * que é o que a tela faz para quem enxerga.
		 */
		?>
		<fieldset class="rc-editar-conta__cartao" aria-describedby="rc-editar-conta-senha-ajuda">
			<legend class="rc-editar-conta__subtitulo"><?php esc_html_e( 'Alterar senha', 'reconectar' ); ?></legend>
			<p class="rc-editar-conta__ajuda rc-editar-conta__ajuda--grupo" id="rc-editar-conta-senha-ajuda"><?php esc_html_e( 'Deixe os campos em branco para manter a senha atual.', 'reconectar' ); ?></p>

			<p class="woocommerce-form-row woocommerce-form-row--wide form-row form-row-wide">
				<label for="password_current"><?php esc_html_e( 'Senha atual', 'reconectar' ); ?></label>
				<input type="password" class="woocommerce-Input woocommerce-Input--password input-text" name="password_current" id="password_current" autocomplete="current-password" />
			</p>
			<p class="woocommerce-form-row woocommerce-form-row--wide form-row form-row-wide">
				<label for="password_1"><?php esc_html_e( 'Nova senha', 'reconectar' ); ?></label>
				<input type="password" class="woocommerce-Input woocommerce-Input--password input-text" name="password_1" id="password_1" autocomplete="new-password" />
			</p>
			<p class="woocommerce-form-row woocommerce-form-row--wide form-row form-row-wide">
				<label for="password_2"><?php esc_html_e( 'Confirm new password', 'woocommerce' ); ?></label>
				<input type="password" class="woocommerce-Input woocommerce-Input--password input-text" name="password_2" id="password_2" autocomplete="new-password" />
			</p>
		</fieldset>

	</div>

	<?php
	/*
	 * Os dois scripts que `WC_Shortcode_My_Account::edit_address()` enfileira: o
	 * primeiro troca o campo de estado conforme o país, o segundo ajusta rótulo,
	 * obrigatoriedade e ordem. Ambos procuram os campos dentro de
	 * `.woocommerce-{tipo}-fields` e reordenam os filhos diretos de
	 * `.woocommerce-{tipo}-fields__field-wrapper` — daí as duas classes abaixo, e
	 * nada além de `.form-row` dentro do segundo.
	 */
	wp_enqueue_script( 'wc-country-select' );
	wp_enqueue_script( 'wc-address-i18n' );

	$rc_tem_entrega = reconectar_conta_tem_entrega();
	$rc_mesmo       = $rc_tem_entrega && reconectar_conta_entrega_igual_a_cobranca();
	?>
	<input type="hidden" name="<?php echo esc_attr( RECONECTAR_CONTA_CAMPO_ENDERECOS ); ?>" value="1" />

	<section class="rc-editar-conta__cartao rc-editar-conta__endereco woocommerce-billing-fields" id="rc-editar-conta-endereco" aria-labelledby="rc-editar-conta-cobranca">
		<h2 class="rc-editar-conta__subtitulo" id="rc-editar-conta-cobranca"><?php esc_html_e( 'Endereço de cobrança', 'reconectar' ); ?></h2>

		<?php do_action( 'woocommerce_before_edit_address_form_billing' ); ?>

		<div class="rc-editar-conta__campos woocommerce-billing-fields__field-wrapper">
			<?php
			foreach ( reconectar_conta_campos_de_endereco( 'billing' ) as $rc_chave => $rc_campo ) {
				woocommerce_form_field( $rc_chave, $rc_campo, wc_get_post_data_by_key( $rc_chave, $rc_campo['value'] ) );
			}
			?>
		</div>

		<?php do_action( 'woocommerce_after_edit_address_form_billing' ); ?>

		<?php if ( $rc_tem_entrega ) : ?>
			<?php
			/*
			 * A caixa fica no cartão da cobrança porque é sobre ele que ela fala.
			 * `aria-controls` aponta o grupo que ela abre e fecha; o leitor de tela
			 * não anuncia "expandido" numa caixa de seleção, e o estado dela já diz
			 * o mesmo.
			 */
			?>
			<p class="rc-editar-conta__mesmo">
				<input type="checkbox" class="rc-editar-conta__mesmo-caixa" id="<?php echo esc_attr( RECONECTAR_CONTA_CAMPO_MESMO_ENDERECO ); ?>" name="<?php echo esc_attr( RECONECTAR_CONTA_CAMPO_MESMO_ENDERECO ); ?>" value="1" aria-controls="rc-editar-conta-entrega" <?php checked( $rc_mesmo ); ?> />
				<label for="<?php echo esc_attr( RECONECTAR_CONTA_CAMPO_MESMO_ENDERECO ); ?>"><?php esc_html_e( 'A entrega é neste mesmo endereço', 'reconectar' ); ?></label>
			</p>
		<?php endif; ?>
	</section>

	<?php if ( $rc_tem_entrega ) : ?>
		<?php
		/*
		 * `hidden` some com o grupo; `disabled` o tira da validação do navegador e do
		 * POST — sem ele, um campo obrigatório escondido barraria o envio sem que a
		 * pessoa visse por quê. Os dois nascem do servidor, para não haver um
		 * instante com o grupo aberto antes do script; o script só os alterna.
		 */
		?>
		<fieldset class="rc-editar-conta__cartao rc-editar-conta__endereco woocommerce-shipping-fields" id="rc-editar-conta-entrega" <?php echo $rc_mesmo ? 'hidden disabled' : ''; ?>>
			<legend class="rc-editar-conta__subtitulo"><?php esc_html_e( 'Endereço de entrega', 'reconectar' ); ?></legend>

			<?php do_action( 'woocommerce_before_edit_address_form_shipping' ); ?>

			<div class="rc-editar-conta__campos woocommerce-shipping-fields__field-wrapper">
				<?php
				foreach ( reconectar_conta_campos_de_endereco( 'shipping' ) as $rc_chave => $rc_campo ) {
					woocommerce_form_field( $rc_chave, $rc_campo, wc_get_post_data_by_key( $rc_chave, $rc_campo['value'] ) );
				}
				?>
			</div>

			<?php do_action( 'woocommerce_after_edit_address_form_shipping' ); ?>
		</fieldset>

		<script>
		( function () {
			var caixa = document.getElementById( <?php echo wp_json_encode( RECONECTAR_CONTA_CAMPO_MESMO_ENDERECO ); ?> );
			var grupo = document.getElementById( 'rc-editar-conta-entrega' );

			if ( ! caixa || ! grupo ) {
				return;
			}

			caixa.addEventListener( 'change', function () {
				grupo.hidden   = caixa.checked;
				grupo.disabled = caixa.checked;
			} );
		}() );
		</script>
	<?php endif; ?>

	<?php
		/**
		 * My Account edit account form.
		 *
		 * @since 2.6.0
		 */
		do_action( 'woocommerce_edit_account_form' );
	?>

	<p class="rc-editar-conta__acoes">
		<?php wp_nonce_field( 'save_account_details', 'save-account-details-nonce' ); ?>
		<button type="submit" class="woocommerce-Button button rc-editar-conta__salvar<?php echo esc_attr( wc_wp_theme_get_element_class_name( 'button' ) ? ' ' . wc_wp_theme_get_element_class_name( 'button' ) : '' ); ?>" name="save_account_details" value="<?php esc_attr_e( 'Save changes', 'woocommerce' ); ?>"><?php esc_html_e( 'Save changes', 'woocommerce' ); ?></button>
		<input type="hidden" name="action" value="save_account_details" />
	</p>

	<?php do_action( 'woocommerce_edit_account_form_end' ); ?>
</form>

<?php do_action( 'woocommerce_after_edit_account_form' ); ?>
