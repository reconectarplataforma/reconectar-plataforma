<?php
/**
 * Ficha de uma empresa: dados cadastrais, lojas e a operação delas.
 *
 * Incluída por `Reconectar_Painel_Empresas::renderizar()`, com `$contexto` no
 * escopo. A permissão sobre esta empresa já foi verificada em `proteger()`.
 *
 * @package reconectar-core
 */

defined( 'ABSPATH' ) || exit;

$empresa_id = (int) $contexto['id'];

// A edição reaproveita o formulário de cadastro; a ficha é a tela de leitura.
if ( ! empty( $_GET['editar'] ) ) {
	include RECONECTAR_CORE_PATH . 'includes/painel-empresas/form-empresa.php';
	return;
}

$ativa = Reconectar_Empresa::esta_ativa( $empresa_id );
$lojas = Reconectar_Empresa::lojas_da_empresa( $empresa_id );
?>

<div class="rc-painel-empresas__topo">
	<h1 class="rc-painel-empresas__titulo"><?php echo esc_html( get_the_title( $empresa_id ) ); ?></h1>

	<span class="rc-selo <?php echo $ativa ? 'rc-selo--ativo' : 'rc-selo--inativo'; ?>">
		<?php echo esc_html( $ativa ? __( 'Em operação', 'reconectar-core' ) : __( 'Desativada', 'reconectar-core' ) ); ?>
	</span>
</div>

<div class="rc-painel-empresas__acoes">
	<?php if ( current_user_can( Reconectar_Permissoes::CAP_GERIR_EMPRESAS ) ) : ?>
		<a class="rc-botao rc-botao--discreto"
			href="<?php echo esc_url( add_query_arg( 'editar', 1, Reconectar_Painel_Empresas::url( 'empresa/' . $empresa_id ) ) ); ?>">
			<?php esc_html_e( 'Editar dados', 'reconectar-core' ); ?>
		</a>

		<form method="post" class="rc-formulario--linha"
			action="<?php echo esc_url( Reconectar_Painel_Empresas::url( 'empresa/' . $empresa_id ) ); ?>">
			<?php wp_nonce_field( 'reconectar_painel_empresa_alternar' ); ?>
			<input type="hidden" name="reconectar_acao" value="empresa_alternar">
			<input type="hidden" name="empresa_id" value="<?php echo esc_attr( $empresa_id ); ?>">
			<button type="submit" class="rc-botao rc-botao--discreto">
				<?php echo esc_html( $ativa ? __( 'Desativar empresa', 'reconectar-core' ) : __( 'Reativar empresa', 'reconectar-core' ) ); ?>
			</button>
		</form>
	<?php endif; ?>

	<?php if ( current_user_can( Reconectar_Permissoes::CAP_GERIR_LOJAS ) ) : ?>
		<a class="rc-botao rc-botao--primario"
			href="<?php echo esc_url( add_query_arg( 'empresa', $empresa_id, Reconectar_Painel_Empresas::url( 'loja/nova' ) ) ); ?>">
			<?php esc_html_e( 'Cadastrar loja', 'reconectar-core' ); ?>
		</a>
	<?php endif; ?>
</div>

<?php if ( ! $ativa ) : ?>
	<p class="rc-painel-empresas__nota">
		<?php esc_html_e( 'Enquanto a empresa estiver desativada, nenhuma loja dela pode vender. Ao reativá-la, cada loja volta ao estado individual em que estava.', 'reconectar-core' ); ?>
	</p>
<?php endif; ?>

<h2 class="rc-painel-empresas__secao"><?php esc_html_e( 'Dados cadastrais', 'reconectar-core' ); ?></h2>

<dl class="rc-dados">
	<?php foreach ( Reconectar_Empresa::campos() as $chave => $campo ) : ?>
		<?php $valor = get_post_meta( $empresa_id, Reconectar_Empresa::PREFIXO_META . $chave, true ); ?>
		<div class="rc-dados__item">
			<dt><?php echo esc_html( $campo['rotulo'] ); ?></dt>
			<dd><?php echo esc_html( '' !== $valor ? $valor : '—' ); ?></dd>
		</div>
	<?php endforeach; ?>
</dl>

<h2 class="rc-painel-empresas__secao"><?php esc_html_e( 'Lojas', 'reconectar-core' ); ?></h2>

<?php if ( empty( $lojas ) ) : ?>

	<p class="rc-painel-empresas__vazio">
		<?php esc_html_e( 'Esta empresa ainda não tem lojas cadastradas.', 'reconectar-core' ); ?>
	</p>

<?php else : ?>

	<table class="rc-tabela">
		<caption class="rc-tabela__legenda">
			<?php esc_html_e( 'Lojas desta empresa, com produtos publicados e ganhos já liberados. Pedido ainda em andamento não entra na coluna de ganhos — é o mesmo critério do painel do Dokan.', 'reconectar-core' ); ?>
		</caption>
		<thead>
			<tr>
				<th scope="col"><?php esc_html_e( 'Loja', 'reconectar-core' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Produtos', 'reconectar-core' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Ganhos liberados', 'reconectar-core' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Situação', 'reconectar-core' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<?php foreach ( $lojas as $loja_id ) : ?>
				<?php
				$usuario  = get_userdata( $loja_id );
				$produtos = count_user_posts( $loja_id, 'product', true );
				$em_venda = Reconectar_Empresa::loja_esta_ativa( $loja_id );
				$ganhos   = Reconectar_Painel_Empresas::faturamento_da_loja( $loja_id );
				?>
				<tr>
					<th scope="row">
						<a href="<?php echo esc_url( Reconectar_Painel_Empresas::url( 'loja/' . $loja_id ) ); ?>">
							<?php echo esc_html( $usuario ? $usuario->display_name : '#' . $loja_id ); ?>
						</a>
					</th>
					<td><?php echo esc_html( number_format_i18n( (int) $produtos ) ); ?></td>
					<td><?php echo Reconectar_Painel_Empresas::dinheiro( $ganhos ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped — já escapado no método. ?></td>
					<td>
						<span class="rc-selo <?php echo $em_venda ? 'rc-selo--ativo' : 'rc-selo--inativo'; ?>">
							<?php echo esc_html( $em_venda ? __( 'Vendendo', 'reconectar-core' ) : __( 'Fora de operação', 'reconectar-core' ) ); ?>
						</span>
					</td>
				</tr>
			<?php endforeach; ?>
		</tbody>
	</table>

<?php endif; ?>
