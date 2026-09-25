<?php
/**
 * Formulário de cadastro de vendedor.
 *
 * A empresa vem da query string e já foi validada em `proteger()` — mas o campo
 * oculto abaixo não é a fonte da verdade: `Reconectar_Vendedores::criar()`
 * revalida a permissão sobre ela no servidor, porque um campo oculto é só uma
 * sugestão que o navegador faz.
 *
 * Note o que **não** existe aqui: campo de papel. O papel é constante no código
 * (`Reconectar_Vendedores::PAPEL`), justamente para que nenhuma requisição possa
 * pedir um diferente.
 *
 * @package reconectar-core
 */

defined( 'ABSPATH' ) || exit;

$empresa_id = isset( $_GET['empresa'] ) ? (int) $_GET['empresa'] : 0;
?>

<h1 class="rc-painel-empresas__titulo"><?php esc_html_e( 'Cadastrar vendedor', 'reconectar-core' ); ?></h1>

<p class="rc-painel-empresas__nota">
	<?php
	printf(
		/* translators: %s: nome da empresa. */
		esc_html__( 'O vendedor será vinculado à empresa %s.', 'reconectar-core' ),
		'<strong>' . esc_html( get_the_title( $empresa_id ) ) . '</strong>'
	);
	?>
</p>

<form class="rc-formulario" method="post"
	action="<?php echo esc_url( add_query_arg( 'empresa', $empresa_id, Reconectar_Painel_Empresas::url( 'vendedor/novo' ) ) ); ?>">
	<?php wp_nonce_field( 'reconectar_painel_vendedor_criar' ); ?>
	<input type="hidden" name="reconectar_acao" value="vendedor_criar">
	<input type="hidden" name="empresa_id" value="<?php echo esc_attr( $empresa_id ); ?>">

	<p class="rc-formulario__campo">
		<label for="rc-vendedor-nome"><?php esc_html_e( 'Nome da loja', 'reconectar-core' ); ?></label>
		<input type="text" id="rc-vendedor-nome" name="nome" required>
	</p>

	<p class="rc-formulario__campo">
		<label for="rc-vendedor-login"><?php esc_html_e( 'Nome de usuário', 'reconectar-core' ); ?></label>
		<input type="text" id="rc-vendedor-login" name="login" required autocomplete="off">
	</p>

	<p class="rc-formulario__campo">
		<label for="rc-vendedor-email"><?php esc_html_e( 'E-mail', 'reconectar-core' ); ?></label>
		<input type="email" id="rc-vendedor-email" name="email" required autocomplete="off">
	</p>

	<p class="rc-formulario__campo">
		<label for="rc-vendedor-primeiro"><?php esc_html_e( 'Nome', 'reconectar-core' ); ?></label>
		<input type="text" id="rc-vendedor-primeiro" name="primeiro">
	</p>

	<p class="rc-formulario__campo">
		<label for="rc-vendedor-ultimo"><?php esc_html_e( 'Sobrenome', 'reconectar-core' ); ?></label>
		<input type="text" id="rc-vendedor-ultimo" name="ultimo">
	</p>

	<p class="rc-formulario__campo">
		<label for="rc-vendedor-telefone"><?php esc_html_e( 'Telefone', 'reconectar-core' ); ?></label>
		<input type="text" id="rc-vendedor-telefone" name="telefone">
	</p>

	<p class="rc-formulario__campo">
		<label for="rc-vendedor-descricao"><?php esc_html_e( 'Descrição da loja', 'reconectar-core' ); ?></label>
		<textarea id="rc-vendedor-descricao" name="descricao" rows="4"></textarea>
	</p>

	<p class="rc-formulario__ajuda">
		<?php esc_html_e( 'Nenhum e-mail é enviado. Depois de cadastrar, esta tela mostra um link de definição de senha para você repassar ao vendedor.', 'reconectar-core' ); ?>
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
