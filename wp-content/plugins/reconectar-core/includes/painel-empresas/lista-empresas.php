<?php
/**
 * Tela inicial do painel: as empresas sob gestão do usuário.
 *
 * Incluída por `Reconectar_Painel_Empresas::renderizar()`, com `$contexto` no
 * escopo.
 *
 * @package reconectar-core
 */

defined( 'ABSPATH' ) || exit;

$empresas = Reconectar_Empresa::listar();
?>

<div class="rc-painel-empresas__topo">
	<h1 class="rc-painel-empresas__titulo"><?php esc_html_e( 'Empresas', 'reconectar-core' ); ?></h1>

	<?php if ( current_user_can( Reconectar_Permissoes::CAP_GERIR_EMPRESAS ) ) : ?>
		<a class="rc-botao rc-botao--primario" href="<?php echo esc_url( Reconectar_Painel_Empresas::url( 'empresa/nova' ) ); ?>">
			<?php esc_html_e( 'Cadastrar empresa', 'reconectar-core' ); ?>
		</a>
	<?php endif; ?>
</div>

<?php if ( empty( $empresas ) ) : ?>

	<p class="rc-painel-empresas__vazio">
		<?php esc_html_e( 'Nenhuma empresa sob sua gestão ainda.', 'reconectar-core' ); ?>
	</p>

<?php else : ?>

	<table class="rc-tabela">
		<caption class="rc-tabela__legenda">
			<?php esc_html_e( 'Empresas que você administra, com o número de vendedores e a situação de cada uma.', 'reconectar-core' ); ?>
		</caption>
		<thead>
			<tr>
				<th scope="col"><?php esc_html_e( 'Empresa', 'reconectar-core' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Município', 'reconectar-core' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Vendedores', 'reconectar-core' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Situação', 'reconectar-core' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<?php foreach ( $empresas as $empresa ) : ?>
				<?php
				$ativa      = Reconectar_Empresa::esta_ativa( $empresa->ID );
				$municipio  = get_post_meta( $empresa->ID, Reconectar_Empresa::PREFIXO_META . 'municipio', true );
				$uf         = get_post_meta( $empresa->ID, Reconectar_Empresa::PREFIXO_META . 'uf', true );
				$vendedores = Reconectar_Empresa::vendedores_da_empresa( $empresa->ID );
				$local      = trim( $municipio . ( $uf ? ' / ' . $uf : '' ) );
				?>
				<tr>
					<th scope="row">
						<a href="<?php echo esc_url( Reconectar_Painel_Empresas::url( 'empresa/' . $empresa->ID ) ); ?>">
							<?php echo esc_html( get_the_title( $empresa ) ); ?>
						</a>
					</th>
					<td><?php echo esc_html( '' !== $local ? $local : '—' ); ?></td>
					<td><?php echo esc_html( number_format_i18n( count( $vendedores ) ) ); ?></td>
					<td>
						<span class="rc-selo <?php echo $ativa ? 'rc-selo--ativo' : 'rc-selo--inativo'; ?>">
							<?php echo esc_html( $ativa ? __( 'Em operação', 'reconectar-core' ) : __( 'Desativada', 'reconectar-core' ) ); ?>
						</span>
					</td>
				</tr>
			<?php endforeach; ?>
		</tbody>
	</table>

<?php endif; ?>
