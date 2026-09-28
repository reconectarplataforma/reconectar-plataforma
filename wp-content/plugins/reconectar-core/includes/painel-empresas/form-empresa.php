<?php
/**
 * Formulário de cadastro e edição de empresa.
 *
 * Serve às duas telas — `empresa/nova` e `empresa/<id>?editar=1` — porque os
 * campos são os mesmos e manter dois arquivos quase idênticos é como um campo
 * novo acaba gravado em um caminho e esquecido no outro.
 *
 * @package reconectar-core
 */

defined( 'ABSPATH' ) || exit;

$empresa_id = isset( $empresa_id ) ? (int) $empresa_id : 0;
$titulo     = $empresa_id
	? __( 'Editar empresa', 'reconectar-core' )
	: __( 'Cadastrar empresa', 'reconectar-core' );

// Devolve o que foi digitado quando o servidor recusou o cadastro. Sem isso, um
// CNPJ com um dígito trocado apagaria o formulário inteiro na volta do redirect
// — o usuário perderia sete campos corretos por causa de um errado, e a própria
// validação nova é que teria criado o problema.
$recusado = Reconectar_Painel_Empresas::formulario_pendente( 'empresa' );
?>

<h1 class="rc-painel-empresas__titulo"><?php echo esc_html( $titulo ); ?></h1>

<form class="rc-formulario" method="post" action="<?php echo esc_url( Reconectar_Painel_Empresas::url( $empresa_id ? 'empresa/' . $empresa_id : 'empresa/nova' ) ); ?>">
	<?php wp_nonce_field( 'reconectar_painel_empresa_salvar' ); ?>
	<input type="hidden" name="reconectar_acao" value="empresa_salvar">
	<input type="hidden" name="empresa_id" value="<?php echo esc_attr( $empresa_id ); ?>">

	<p class="rc-formulario__campo">
		<label for="rc-empresa-nome"><?php esc_html_e( 'Nome da empresa', 'reconectar-core' ); ?></label>
		<?php
		$nome = isset( $recusado['nome'] )
			? $recusado['nome']
			: ( $empresa_id ? get_the_title( $empresa_id ) : '' );
		?>
		<input type="text" id="rc-empresa-nome" name="nome" required
			value="<?php echo esc_attr( $nome ); ?>">
	</p>

	<?php foreach ( Reconectar_Empresa::campos() as $chave => $campo ) : ?>
		<?php
		$valor = $empresa_id ? get_post_meta( $empresa_id, Reconectar_Empresa::PREFIXO_META . $chave, true ) : '';
		$valor = isset( $recusado[ $chave ] ) ? $recusado[ $chave ] : $valor;
		$ajuda = isset( $campo['ajuda'] ) ? $campo['ajuda'] : '';
		$id    = 'rc-empresa-' . $chave;
		?>
		<p class="rc-formulario__campo">
			<label for="<?php echo esc_attr( $id ); ?>">
				<?php echo esc_html( $campo['rotulo'] ); ?>
			</label>
			<input type="<?php echo esc_attr( $campo['tipo'] ); ?>"
				id="<?php echo esc_attr( $id ); ?>"
				name="<?php echo esc_attr( $chave ); ?>"
				value="<?php echo esc_attr( $valor ); ?>"
				<?php if ( isset( $campo['mascara'] ) ) : ?>
					data-rc-mascara="<?php echo esc_attr( $campo['mascara'] ); ?>"
				<?php endif; ?>
				<?php if ( isset( $campo['inputmode'] ) ) : ?>
					inputmode="<?php echo esc_attr( $campo['inputmode'] ); ?>"
				<?php endif; ?>
				<?php if ( isset( $campo['autocomplete'] ) ) : ?>
					autocomplete="<?php echo esc_attr( $campo['autocomplete'] ); ?>"
				<?php endif; ?>
				<?php if ( isset( $campo['maxlength'] ) ) : ?>
					maxlength="<?php echo esc_attr( $campo['maxlength'] ); ?>"
				<?php endif; ?>
				<?php if ( '' !== $ajuda ) : ?>
					aria-describedby="<?php echo esc_attr( $id . '-ajuda' ); ?>"
				<?php endif; ?>>

			<?php if ( '' !== $ajuda ) : ?>
				<span class="rc-formulario__ajuda" id="<?php echo esc_attr( $id . '-ajuda' ); ?>">
					<?php echo esc_html( $ajuda ); ?>
				</span>
			<?php endif; ?>
		</p>
	<?php endforeach; ?>

	<p class="rc-formulario__acoes">
		<button type="submit" class="rc-botao rc-botao--primario">
			<?php esc_html_e( 'Salvar', 'reconectar-core' ); ?>
		</button>
		<a class="rc-botao rc-botao--discreto"
			href="<?php echo esc_url( $empresa_id ? Reconectar_Painel_Empresas::url( 'empresa/' . $empresa_id ) : Reconectar_Painel_Empresas::url() ); ?>">
			<?php esc_html_e( 'Cancelar', 'reconectar-core' ); ?>
		</a>
	</p>
</form>
