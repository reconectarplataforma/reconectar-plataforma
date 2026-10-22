<?php
/**
 * As categorias do fórum, na ordem em que o fórum as mostra.
 *
 * Incluída por `tela.php`. O acesso já foi decidido em
 * `Reconectar_Painel_Gestao::proteger()`.
 *
 * @package reconectar-core
 */

defined( 'ABSPATH' ) || exit;

$rc_categorias = Reconectar_Painel_Foruns::categorias();
?>

<div class="rc-painel-empresas__topo">
	<h1 class="rc-painel-empresas__titulo"><?php esc_html_e( 'Categorias do fórum', 'reconectar-core' ); ?></h1>
	<?php if ( current_user_can( 'publish_forums' ) ) : ?>
		<a class="rc-botao rc-botao--primario" href="<?php echo esc_url( Reconectar_Painel_Gestao::url( Reconectar_Painel_Foruns::AREA, 'nova' ) ); ?>">
			<?php esc_html_e( 'Nova categoria', 'reconectar-core' ); ?>
		</a>
	<?php endif; ?>
</div>

<?php if ( empty( $rc_categorias ) ) : ?>

	<p class="rc-painel-empresas__vazio">
		<?php esc_html_e( 'Nenhuma categoria criada.', 'reconectar-core' ); ?>
	</p>

<?php else : ?>

	<div class="rc-painel-gestao__rolagem">
		<table class="rc-tabela">
			<caption class="rc-tabela__legenda">
				<?php esc_html_e( 'Categorias do fórum, com descrição, posição na lista, número de perguntas e se aceitam perguntas novas.', 'reconectar-core' ); ?>
			</caption>
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Categoria', 'reconectar-core' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Descrição', 'reconectar-core' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Ordem', 'reconectar-core' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Perguntas', 'reconectar-core' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Situação', 'reconectar-core' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $rc_categorias as $rc_categoria ) : ?>
					<?php $rc_fechada = bbp_is_forum_closed( $rc_categoria->ID, false ); ?>
					<tr>
						<th scope="row">
							<a href="<?php echo esc_url( Reconectar_Painel_Gestao::url( Reconectar_Painel_Foruns::AREA, $rc_categoria->ID ) ); ?>">
								<?php echo esc_html( $rc_categoria->post_title ); ?>
							</a>
						</th>
						<td><?php echo esc_html( wp_trim_words( $rc_categoria->post_content, 12 ) ); ?></td>
						<td><?php echo esc_html( number_format_i18n( $rc_categoria->menu_order ) ); ?></td>
						<td><?php echo esc_html( number_format_i18n( (int) bbp_get_forum_topic_count( $rc_categoria->ID, false, true ) ) ); ?></td>
						<td>
							<span class="rc-selo <?php echo $rc_fechada ? 'rc-selo--inativo' : 'rc-selo--ativo'; ?>">
								<?php echo esc_html( $rc_fechada ? __( 'Fechada', 'reconectar-core' ) : __( 'Aberta', 'reconectar-core' ) ); ?>
							</span>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	</div>

<?php endif; ?>
