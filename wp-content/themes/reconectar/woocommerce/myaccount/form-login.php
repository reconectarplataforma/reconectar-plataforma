<?php
/**
 * Tela de acesso à plataforma.
 *
 * Sobrescreve `woocommerce/templates/myaccount/form-login.php` (versão 9.9.0).
 * O que muda é a apresentação: arte institucional à esquerda e, à direita, o
 * formulário de entrada já aberto, com os provedores de login social acima dele
 * quando houver algum configurado, e o cadastro atrás de um botão.
 *
 * O e-mail e a senha já estiveram atrás de um botão "E-mail e senha", ao lado
 * dos provedores sociais. Só que, na versão gratuita do Nextend, os botões não
 * aparecem no formulário do WooCommerce, e a tela virava um menu de uma opção só:
 * um clique a mais, sem escolha nenhuma, antes de digitar.
 *
 * O que **não** muda são os dois formulários. Todos os `do_action` do
 * WooCommerce estão preservados na ordem original porque não são decoração: o
 * Dokan, o Nextend e qualquer plugin de campo extra se penduram neles, e um
 * `do_action` ausente vira um recurso que some sem erro nenhum no log. Ao
 * atualizar o WooCommerce, comparar este arquivo com o original.
 *
 * @package reconectar
 */

defined( 'ABSPATH' ) || exit;

$reconectar_registro_aberto = 'yes' === get_option( 'woocommerce_enable_myaccount_registration' );

/*
 * O Nextend não imprime nada para um provedor sem Client ID e Secret gravados,
 * e é por isso que o retorno é medido antes de abrir o contêiner: enquanto as
 * credenciais OAuth não existirem, a tela não mostra separador nem moldura
 * vazia — ela simplesmente começa no e-mail. A alternativa seria condicionar a
 * `shortcode_exists()`, que responderia "sim" com o plugin ativo e nenhum
 * provedor configurado.
 */
$reconectar_sso = '';

if ( shortcode_exists( 'nextend_social_login' ) ) {
	$reconectar_sso  = do_shortcode( '[nextend_social_login provider="facebook"]' );
	$reconectar_sso .= do_shortcode( '[nextend_social_login provider="google"]' );
	$reconectar_sso  = trim( $reconectar_sso );
}

do_action( 'woocommerce_before_customer_login_form' );
?>

<div class="rc-login" data-rc-login>

	<?php
	/*
	 * Gradiente da identidade, o mesmo de `.reconectar-hero`, em vez de arte
	 * nova: é o que o projeto já tem e não cria dependência de um arquivo que
	 * ninguém entregou. A coluna some abaixo de 782px — no celular ela empurraria
	 * o formulário para fora da primeira tela.
	 */
	?>
	<div class="rc-login__arte" aria-hidden="true">
		<div class="rc-login__arte-conteudo">
			<?php if ( has_custom_logo() ) : ?>
				<?php the_custom_logo(); ?>
			<?php else : ?>
				<p class="rc-login__marca"><?php bloginfo( 'name' ); ?></p>
			<?php endif; ?>

			<p class="rc-login__chamada">
				<?php esc_html_e( 'Produtos de quem planta, cria e faz — direto de quem produz para quem consome.', 'reconectar' ); ?>
			</p>
		</div>
	</div>

	<div class="rc-login__painel">

		<h1 class="rc-login__titulo"><?php esc_html_e( 'Acessar a plataforma', 'reconectar' ); ?></h1>
		<p class="rc-login__subtitulo"><?php esc_html_e( 'Entre com seu e-mail ou usuário e a senha.', 'reconectar' ); ?></p>

		<?php if ( '' !== $reconectar_sso ) : ?>
			<div class="rc-login__sso">
				<?php echo $reconectar_sso; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- HTML do shortcode do Nextend. ?>
			</div>

			<p class="rc-login__separador"><span><?php esc_html_e( 'ou', 'reconectar' ); ?></span></p>
		<?php endif; ?>

		<div class="rc-login__bloco" id="rc-login-entrar">

			<?php
			/*
			 * Some da tela e fica para o leitor de tela: o título e o subtítulo
			 * logo acima já dizem que é aqui que se entra, e sem este `<h2>` o
			 * "Criar uma conta" abaixo seria a única seção da página, com o
			 * formulário de entrada solto debaixo do `<h1>`.
			 */
			?>
			<h2 class="rc-login__bloco-titulo screen-reader-text"><?php esc_html_e( 'Entrar', 'reconectar' ); ?></h2>

			<form class="woocommerce-form woocommerce-form-login login" method="post" novalidate>

				<?php do_action( 'woocommerce_login_form_start' ); ?>

				<p class="woocommerce-form-row woocommerce-form-row--wide form-row form-row-wide">
					<label for="username"><?php esc_html_e( 'E-mail ou usuário', 'reconectar' ); ?>&nbsp;<span class="required" aria-hidden="true">*</span><span class="screen-reader-text"><?php esc_html_e( 'Obrigatório', 'reconectar' ); ?></span></label>
					<input type="text" class="woocommerce-Input woocommerce-Input--text input-text" name="username" id="username" autocomplete="username" value="<?php echo ( ! empty( $_POST['username'] ) && is_string( $_POST['username'] ) ) ? esc_attr( wp_unslash( $_POST['username'] ) ) : ''; ?>" required aria-required="true" /><?php // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Repreenchimento do campo, como no template original. ?>
				</p>
				<p class="woocommerce-form-row woocommerce-form-row--wide form-row form-row-wide">
					<label for="password"><?php esc_html_e( 'Senha', 'reconectar' ); ?>&nbsp;<span class="required" aria-hidden="true">*</span><span class="screen-reader-text"><?php esc_html_e( 'Obrigatório', 'reconectar' ); ?></span></label>
					<input class="woocommerce-Input woocommerce-Input--text input-text" type="password" name="password" id="password" autocomplete="current-password" required aria-required="true" />
				</p>

				<?php do_action( 'woocommerce_login_form' ); ?>

				<p class="form-row">
					<label class="woocommerce-form__label woocommerce-form__label-for-checkbox woocommerce-form-login__rememberme">
						<input class="woocommerce-form__input woocommerce-form__input-checkbox" name="rememberme" type="checkbox" id="rememberme" value="forever" /> <span><?php esc_html_e( 'Continuar conectado', 'reconectar' ); ?></span>
					</label>
					<?php wp_nonce_field( 'woocommerce-login', 'woocommerce-login-nonce' ); ?>
					<button type="submit" class="rc-login__botao rc-login__botao--primario woocommerce-button button woocommerce-form-login__submit<?php echo esc_attr( wc_wp_theme_get_element_class_name( 'button' ) ? ' ' . wc_wp_theme_get_element_class_name( 'button' ) : '' ); ?>" name="login" value="<?php esc_attr_e( 'Entrar', 'reconectar' ); ?>"><?php esc_html_e( 'Entrar', 'reconectar' ); ?></button>
				</p>
				<p class="woocommerce-LostPassword lost_password">
					<a href="<?php echo esc_url( wp_lostpassword_url() ); ?>"><?php esc_html_e( 'Esqueci minha senha', 'reconectar' ); ?></a>
				</p>

				<?php do_action( 'woocommerce_login_form_end' ); ?>

			</form>

		</div>

		<?php if ( $reconectar_registro_aberto ) : ?>

			<p class="rc-login__separador"><span><?php esc_html_e( 'Ainda não tem conta?', 'reconectar' ); ?></span></p>

			<?php
			/*
			 * O botão nasce com `hidden` e é o JavaScript que o revela, ao mesmo
			 * tempo em que fecha o bloco do cadastro. Assim a tela sem script é
			 * linear, com os dois formulários abertos, e nunca exibe um botão que
			 * não abriria coisa alguma.
			 */
			?>
			<button
				type="button"
				class="rc-login__botao rc-login__botao--secundario"
				id="rc-login-gatilho-criar"
				aria-expanded="true"
				aria-controls="rc-login-criar"
				hidden
			><?php esc_html_e( 'Criar uma conta', 'reconectar' ); ?></button>

			<div class="rc-login__bloco" id="rc-login-criar">

				<h2 class="rc-login__bloco-titulo"><?php esc_html_e( 'Criar uma conta', 'reconectar' ); ?></h2>

				<form method="post" class="woocommerce-form woocommerce-form-register register" <?php do_action( 'woocommerce_register_form_tag' ); ?> >

					<?php do_action( 'woocommerce_register_form_start' ); ?>

					<?php if ( 'no' === get_option( 'woocommerce_registration_generate_username' ) ) : ?>

						<p class="woocommerce-form-row woocommerce-form-row--wide form-row form-row-wide">
							<label for="reg_username"><?php esc_html_e( 'Nome de usuário', 'reconectar' ); ?>&nbsp;<span class="required" aria-hidden="true">*</span><span class="screen-reader-text"><?php esc_html_e( 'Obrigatório', 'reconectar' ); ?></span></label>
							<input type="text" class="woocommerce-Input woocommerce-Input--text input-text" name="username" id="reg_username" autocomplete="username" value="<?php echo ( ! empty( $_POST['username'] ) && is_string( $_POST['username'] ) ) ? esc_attr( wp_unslash( $_POST['username'] ) ) : ''; ?>" required aria-required="true" /><?php // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Repreenchimento do campo, como no template original. ?>
						</p>

					<?php endif; ?>

					<p class="woocommerce-form-row woocommerce-form-row--wide form-row form-row-wide">
						<label for="reg_email"><?php esc_html_e( 'E-mail', 'reconectar' ); ?>&nbsp;<span class="required" aria-hidden="true">*</span><span class="screen-reader-text"><?php esc_html_e( 'Obrigatório', 'reconectar' ); ?></span></label>
						<input type="email" class="woocommerce-Input woocommerce-Input--text input-text" name="email" id="reg_email" autocomplete="email" value="<?php echo ( ! empty( $_POST['email'] ) && is_string( $_POST['email'] ) ) ? esc_attr( wp_unslash( $_POST['email'] ) ) : ''; ?>" required aria-required="true" /><?php // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Repreenchimento do campo, como no template original. ?>
					</p>

					<?php if ( 'no' === get_option( 'woocommerce_registration_generate_password' ) ) : ?>

						<p class="woocommerce-form-row woocommerce-form-row--wide form-row form-row-wide">
							<label for="reg_password"><?php esc_html_e( 'Senha', 'reconectar' ); ?>&nbsp;<span class="required" aria-hidden="true">*</span><span class="screen-reader-text"><?php esc_html_e( 'Obrigatório', 'reconectar' ); ?></span></label>
							<input type="password" class="woocommerce-Input woocommerce-Input--text input-text" name="password" id="reg_password" autocomplete="new-password" required aria-required="true" />
						</p>

					<?php else : ?>

						<p class="rc-login__ajuda"><?php esc_html_e( 'Enviaremos um link por e-mail para você definir a senha.', 'reconectar' ); ?></p>

					<?php endif; ?>

					<?php do_action( 'woocommerce_register_form' ); ?>

					<p class="woocommerce-form-row form-row">
						<?php wp_nonce_field( 'woocommerce-register', 'woocommerce-register-nonce' ); ?>
						<button type="submit" class="rc-login__botao rc-login__botao--primario woocommerce-Button woocommerce-button button<?php echo esc_attr( wc_wp_theme_get_element_class_name( 'button' ) ? ' ' . wc_wp_theme_get_element_class_name( 'button' ) : '' ); ?> woocommerce-form-register__submit" name="register" value="<?php esc_attr_e( 'Criar conta', 'reconectar' ); ?>"><?php esc_html_e( 'Criar conta', 'reconectar' ); ?></button>
					</p>

					<?php do_action( 'woocommerce_register_form_end' ); ?>

				</form>

			</div>

		<?php endif; ?>

	</div>

</div>

<?php do_action( 'woocommerce_after_customer_login_form' ); ?>
