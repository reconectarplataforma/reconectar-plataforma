<?php
/**
 * Tela inicial do painel: as empresas no escopo do usuário — para o
 * Administrador, todas as da plataforma.
 *
 * Incluída por `Reconectar_Painel_Empresas::renderizar()`, com `$contexto` no
 * escopo.
 *
 * Os três números do resumo saem todos de consulta real — contagem de empresas,
 * de lojas e de lojas em operação. Nada agregado por estimativa, e nada de
 * faturamento somado: totalizar `faturamento_da_loja()` de todas as lojas a cada
 * carregamento seria caro, e um número aproximado num painel gerencial é pior
 * que nenhum.
 *
 * @package reconectar-core
 */

defined( 'ABSPATH' ) || exit;

$empresas = Reconectar_Empresa::listar();
$lojas    = Reconectar_Empresa::lojas_no_escopo();

$lojas_em_operacao = 0;

foreach ( $lojas as $loja_id ) {
	if ( Reconectar_Empresa::loja_esta_ativa( $loja_id ) ) {
		++$lojas_em_operacao;
	}
}

$resumo = array(
	array(
		'rotulo' => __( 'Empresas', 'reconectar-core' ),
		'numero' => count( $empresas ),
	),
	array(
		'rotulo' => __( 'Lojas', 'reconectar-core' ),
		'numero' => count( $lojas ),
	),
	array(
		'rotulo' => __( 'Lojas em operação', 'reconectar-core' ),
		'numero' => $lojas_em_operacao,
	),
);
?>

<div class="rc-painel-empresas__topo">
	<h1 class="rc-painel-empresas__titulo"><?php esc_html_e( 'Empresas', 'reconectar-core' ); ?></h1>

	<?php if ( current_user_can( Reconectar_Permissoes::CAP_GERIR_EMPRESAS ) ) : ?>
		<a class="rc-botao rc-botao--primario" href="<?php echo esc_url( Reconectar_Painel_Empresas::url( 'empresa/nova' ) ); ?>">
			<?php esc_html_e( 'Cadastrar empresa', 'reconectar-core' ); ?>
		</a>
	<?php endif; ?>
</div>

<ul class="rc-painel-empresas__resumo">
	<?php foreach ( $resumo as $cartao ) : ?>
		<li class="rc-painel-empresas__cartao">
			<span class="rc-painel-empresas__cartao-numero"><?php echo esc_html( number_format_i18n( $cartao['numero'] ) ); ?></span>
			<span class="rc-painel-empresas__cartao-rotulo"><?php echo esc_html( $cartao['rotulo'] ); ?></span>
		</li>
	<?php endforeach; ?>
</ul>

<?php if ( empty( $empresas ) ) : ?>

	<p class="rc-painel-empresas__vazio">
		<?php esc_html_e( 'Nenhuma empresa cadastrada ainda.', 'reconectar-core' ); ?>
	</p>

<?php else : ?>

	<table class="rc-tabela">
		<caption class="rc-tabela__legenda">
			<?php esc_html_e( 'Empresas da plataforma, com o número de lojas e a situação de cada uma.', 'reconectar-core' ); ?>
		</caption>
		<thead>
			<tr>
				<th scope="col"><?php esc_html_e( 'Empresa', 'reconectar-core' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Município', 'reconectar-core' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Lojas', 'reconectar-core' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Situação', 'reconectar-core' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<?php foreach ( $empresas as $empresa ) : ?>
				<?php
				$ativa          = Reconectar_Empresa::esta_ativa( $empresa->ID );
				$municipio      = get_post_meta( $empresa->ID, Reconectar_Empresa::PREFIXO_META . 'municipio', true );
				$uf             = get_post_meta( $empresa->ID, Reconectar_Empresa::PREFIXO_META . 'uf', true );
				$lojas_da_linha = Reconectar_Empresa::lojas_da_empresa( $empresa->ID );
				$local          = trim( $municipio . ( $uf ? ' / ' . $uf : '' ) );
				?>
				<tr>
					<th scope="row">
						<a href="<?php echo esc_url( Reconectar_Painel_Empresas::url( 'empresa/' . $empresa->ID ) ); ?>">
							<?php echo esc_html( get_the_title( $empresa ) ); ?>
						</a>
					</th>
					<td><?php echo esc_html( '' !== $local ? $local : '—' ); ?></td>
					<td><?php echo esc_html( number_format_i18n( count( $lojas_da_linha ) ) ); ?></td>
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
