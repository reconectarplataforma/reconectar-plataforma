<?php
/**
 * Formulário de cadastro de loja.
 *
 * A empresa vem da query string e já foi validada em `proteger()` — mas o campo
 * oculto abaixo não é a fonte da verdade: `Reconectar_Lojas::criar()`
 * revalida a permissão sobre ela no servidor, porque um campo oculto é só uma
 * sugestão que o navegador faz.
 *
 * Note o que **não** existe aqui: campo de papel. O papel é constante no código
 * (`Reconectar_Lojas::PAPEL`), justamente para que nenhuma requisição possa
 * pedir um diferente.
 *
 * @package reconectar-core
 */

defined( 'ABSPATH' ) || exit;

$empresa_id = isset( $_GET['empresa'] ) ? (int) $_GET['empresa'] : 0;
?>

<h1 class="rc-painel-empresas__titulo"><?php esc_html_e( 'Cadastrar loja', 'reconectar-core' ); ?></h1>

<p class="rc-painel-empresas__nota">
	<?php
	printf(
		/* translators: %s: nome da empresa. */
		esc_html__( 'A loja será vinculada à empresa %s.', 'reconectar-core' ),
		'<strong>' . esc_html( get_the_title( $empresa_id ) ) . '</strong>'
	);
	?>
</p>

<form class="rc-formulario" method="post"
	action="<?php echo esc_url( add_query_arg( 'empresa', $empresa_id, Reconectar_Painel_Empresas::url( 'loja/nova' ) ) ); ?>">
	<?php wp_nonce_field( 'reconectar_painel_loja_criar' ); ?>
	<input type="hidden" name="reconectar_acao" value="loja_criar">
	<input type="hidden" name="empresa_id" value="<?php echo esc_attr( $empresa_id ); ?>">

	<p class="rc-formulario__campo">
		<label for="rc-loja-nome"><?php esc_html_e( 'Nome da loja', 'reconectar-core' ); ?></label>
		<input type="text" id="rc-loja-nome" name="nome" required>
	</p>

	<p class="rc-formulario__campo">
		<label for="rc-loja-login"><?php esc_html_e( 'Nome de usuário', 'reconectar-core' ); ?></label>
		<input type="text" id="rc-loja-login" name="login" required autocomplete="off">
	</p>

	<p class="rc-formulario__campo">
		<label for="rc-loja-email"><?php esc_html_e( 'E-mail', 'reconectar-core' ); ?></label>
		<input type="email" id="rc-loja-email" name="email" required autocomplete="off">
	</p>

	<p class="rc-formulario__campo">
		<label for="rc-loja-primeiro"><?php esc_html_e( 'Nome', 'reconectar-core' ); ?></label>
		<input type="text" id="rc-loja-primeiro" name="primeiro">
	</p>

	<p class="rc-formulario__campo">
		<label for="rc-loja-ultimo"><?php esc_html_e( 'Sobrenome', 'reconectar-core' ); ?></label>
		<input type="text" id="rc-loja-ultimo" name="ultimo">
	</p>

	<p class="rc-formulario__campo">
		<label for="rc-loja-telefone"><?php esc_html_e( 'Telefone', 'reconectar-core' ); ?></label>
		<input type="text" id="rc-loja-telefone" name="telefone">
	</p>

	<p class="rc-formulario__campo">
		<label for="rc-loja-descricao"><?php esc_html_e( 'Descrição da loja', 'reconectar-core' ); ?></label>
		<textarea id="rc-loja-descricao" name="descricao" rows="4"></textarea>
	</p>

	<p class="rc-formulario__ajuda">
		<?php esc_html_e( 'Nenhum e-mail é enviado. Depois de cadastrar, esta tela mostra um link de definição de senha para você repassar à pessoa responsável pela loja.', 'reconectar-core' ); ?>
	</p>

	<p class="rc-formulario__acoes">
		<button type="submit" class="rc-botao rc-botao--primario">
			<?php esc_html_e( 'Cadastrar', 'reconectar-core' ); ?>
		</button>
		<a class="rc-botao rc-botao--discreto"
			href="<?php echo esc_url( Reconectar_Painel_Empresas::url( 'empresa/' . $empresa_id ) ); ?>">
			<?php esc_html_e( 'Cancelar', 'reconectar-core' ); ?>
		</a>
	</p>
</form>
