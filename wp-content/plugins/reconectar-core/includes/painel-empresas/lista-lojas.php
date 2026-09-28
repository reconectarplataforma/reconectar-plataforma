<?php
/**
 * Todas as lojas no escopo do usuário, de todas as empresas que ele administra.
 *
 * Incluída por `Reconectar_Painel_Empresas::renderizar()`, com `$contexto` no
 * escopo.
 *
 * O dado vem de `Reconectar_Empresa::lojas_no_escopo()`, que já filtra pelo
 * escopo do usuário — esta tela não tem caso próprio em `proteger()` justamente
 * por isso: a única trava que falta é o `CAP_PAINEL_EMPRESAS`, conferido antes.
 *
 * Não há botão de cadastrar aqui: uma loja nasce vinculada a uma empresa, e o
 * caminho de criação passa pela ficha dela, que é onde o vínculo é conhecido.
 *
 * @package reconectar-core
 */

defined( 'ABSPATH' ) || exit;

$lojas = Reconectar_Empresa::lojas_no_escopo();
?>

<div class="rc-painel-empresas__topo">
	<h1 class="rc-painel-empresas__titulo"><?php esc_html_e( 'Lojas', 'reconectar-core' ); ?></h1>
</div>

<?php if ( empty( $lojas ) ) : ?>

	<p class="rc-painel-empresas__vazio">
		<?php esc_html_e( 'Nenhuma loja cadastrada nas empresas sob sua gestão.', 'reconectar-core' ); ?>
	</p>

<?php else : ?>

	<table class="rc-tabela">
		<caption class="rc-tabela__legenda">
			<?php esc_html_e( 'Lojas das empresas que você administra, com produtos publicados, ganhos já liberados e situação de cada uma.', 'reconectar-core' ); ?>
		</caption>
		<thead>
			<tr>
				<th scope="col"><?php esc_html_e( 'Loja', 'reconectar-core' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Empresa', 'reconectar-core' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Produtos', 'reconectar-core' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Ganhos liberados', 'reconectar-core' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Situação', 'reconectar-core' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<?php foreach ( $lojas as $loja_id ) : ?>
				<?php
				$usuario    = get_userdata( $loja_id );
				$empresa_id = Reconectar_Empresa::empresa_da_loja( $loja_id );
				$produtos   = count_user_posts( $loja_id, 'product', true );
				$em_venda   = Reconectar_Empresa::loja_esta_ativa( $loja_id );
				$ganhos     = Reconectar_Painel_Empresas::faturamento_da_loja( $loja_id );
				?>
				<tr>
					<th scope="row">
						<a href="<?php echo esc_url( Reconectar_Painel_Empresas::url( 'loja/' . $loja_id ) ); ?>">
							<?php echo esc_html( $usuario ? $usuario->display_name : '#' . $loja_id ); ?>
						</a>
					</th>
					<td>
						<?php if ( $empresa_id ) : ?>
							<a href="<?php echo esc_url( Reconectar_Painel_Empresas::url( 'empresa/' . $empresa_id ) ); ?>">
								<?php echo esc_html( get_the_title( $empresa_id ) ); ?>
							</a>
						<?php else : ?>
							<?php echo esc_html( '—' ); ?>
						<?php endif; ?>
					</td>
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
