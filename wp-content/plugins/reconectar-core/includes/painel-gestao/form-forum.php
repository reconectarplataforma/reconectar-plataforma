<?php
/**
 * Criar ou editar uma categoria do fórum.
 *
 * Incluída por `tela.php`. O acesso ao item já foi decidido em
 * `Reconectar_Painel_Gestao::proteger()`.
 *
 * @package reconectar-core
 */

defined( 'ABSPATH' ) || exit;

$rc_item      = Reconectar_Painel_Gestao::item();
$rc_nova      = 'nova' === $rc_item;
$rc_categoria = $rc_nova ? null : get_post( (int) $rc_item );
$rc_pendente  = Reconectar_Painel_Gestao::formulario_pendente( 'forum-' . $rc_item );

if ( ! empty( $rc_pendente ) ) {
	$rc_valores = array(
		'nome'      => isset( $rc_pendente['nome'] ) ? (string) $rc_pendente['nome'] : '',
		'descricao' => isset( $rc_pendente['descricao'] ) ? (string) $rc_pendente['descricao'] : '',
		'ordem'     => isset( $rc_pendente['ordem'] ) ? (int) $rc_pendente['ordem'] : 0,
		'fechada'   => ! empty( $rc_pendente['fechada'] ),
	);
} elseif ( $rc_categoria ) {
	$rc_valores = array(
		'nome'      => $rc_categoria->post_title,
		'descricao' => $rc_categoria->post_content,
		'ordem'     => (int) $rc_categoria->menu_order,
		'fechada'   => bbp_is_forum_closed( $rc_categoria->ID, false ),
	);
} else {
	$rc_valores = array(
		'nome'      => '',
		'descricao' => '',
		'ordem'     => 0,
		'fechada'   => false,
	);
}
?>

<div class="rc-painel-empresas__topo">
	<h1 class="rc-painel-empresas__titulo">
		<?php echo esc_html( $rc_nova ? __( 'Nova categoria', 'reconectar-core' ) : __( 'Editar categoria', 'reconectar-core' ) ); ?>
	</h1>
	<?php if ( $rc_categoria && 'publish' === $rc_categoria->post_status ) : ?>
		<a class="rc-botao rc-botao--discreto" href="<?php echo esc_url( get_permalink( $rc_categoria ) ); ?>">
			<?php esc_html_e( 'Ver no fórum', 'reconectar-core' ); ?>
		</a>
	<?php endif; ?>
</div>

<form class="rc-formulario" method="post" action="<?php echo esc_url( Reconectar_Painel_Gestao::url( Reconectar_Painel_Foruns::AREA, $rc_item ) ); ?>">
	<?php Reconectar_Painel_Gestao::campos_da_acao( 'forum_salvar', $rc_item ); ?>

	<p class="rc-formulario__campo">
		<label for="rc-forum-nome"><?php esc_html_e( 'Nome', 'reconectar-core' ); ?></label>
		<input type="text" id="rc-forum-nome" name="nome" required maxlength="120" value="<?php echo esc_attr( $rc_valores['nome'] ); ?>">
	</p>

	<p class="rc-formulario__campo">
		<label for="rc-forum-descricao"><?php esc_html_e( 'Descrição', 'reconectar-core' ); ?></label>
		<textarea id="rc-forum-descricao" name="descricao" rows="3" aria-describedby="rc-forum-descricao-ajuda"><?php echo esc_textarea( $rc_valores['descricao'] ); ?></textarea>
		<span class="rc-formulario__ajuda" id="rc-forum-descricao-ajuda">
			<?php esc_html_e( 'Que tipo de pergunta cabe aqui. Aparece abaixo do nome, na lista do fórum.', 'reconectar-core' ); ?>
		</span>
	</p>

	<p class="rc-formulario__campo">
		<label for="rc-forum-ordem"><?php esc_html_e( 'Ordem', 'reconectar-core' ); ?></label>
		<input type="number" id="rc-forum-ordem" name="ordem" step="1" value="<?php echo esc_attr( $rc_valores['ordem'] ); ?>" aria-describedby="rc-forum-ordem-ajuda">
		<span class="rc-formulario__ajuda" id="rc-forum-ordem-ajuda">
			<?php esc_html_e( 'Números menores vêm primeiro. Empates saem em ordem alfabética.', 'reconectar-core' ); ?>
		</span>
	</p>

	<p class="rc-formulario__campo rc-painel-gestao__marcar">
		<input type="checkbox" id="rc-forum-fechada" name="fechada" value="1" <?php checked( $rc_valores['fechada'] ); ?> aria-describedby="rc-forum-fechada-ajuda">
		<label for="rc-forum-fechada"><?php esc_html_e( 'Fechada para novas perguntas', 'reconectar-core' ); ?></label>
		<span class="rc-formulario__ajuda" id="rc-forum-fechada-ajuda">
			<?php esc_html_e( 'As perguntas e respostas que já existem continuam visíveis.', 'reconectar-core' ); ?>
		</span>
	</p>

	<p class="rc-formulario__acoes">
		<button type="submit" class="rc-botao rc-botao--primario">
			<?php echo esc_html( $rc_nova ? __( 'Criar categoria', 'reconectar-core' ) : __( 'Salvar', 'reconectar-core' ) ); ?>
		</button>
		<a class="rc-botao rc-botao--discreto" href="<?php echo esc_url( Reconectar_Painel_Gestao::url( Reconectar_Painel_Foruns::AREA ) ); ?>">
			<?php esc_html_e( 'Voltar', 'reconectar-core' ); ?>
		</a>
	</p>
</form>

<p class="rc-painel-empresas__nota">
	<?php esc_html_e( 'Categorias não são excluídas por aqui: excluir levaria junto as perguntas e respostas dela. Para parar de receber perguntas, feche a categoria.', 'reconectar-core' ); ?>
</p>
