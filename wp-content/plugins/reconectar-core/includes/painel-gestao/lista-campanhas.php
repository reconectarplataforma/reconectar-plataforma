<?php
/**
 * As campanhas da home, com arte, período, ordem e situação.
 *
 * Incluída por `tela.php`. O acesso já foi decidido em
 * `Reconectar_Painel_Gestao::proteger()`.
 *
 * @package reconectar-core
 */

defined( 'ABSPATH' ) || exit;

$rc_campanhas = Reconectar_Painel_Campanhas::campanhas();
?>

<div class="rc-painel-empresas__topo">
	<h1 class="rc-painel-empresas__titulo"><?php esc_html_e( 'Campanhas', 'reconectar-core' ); ?></h1>
	<a class="rc-botao rc-botao--primario" href="<?php echo esc_url( Reconectar_Painel_Gestao::url( Reconectar_Painel_Campanhas::AREA, 'nova' ) ); ?>">
		<?php esc_html_e( 'Nova campanha', 'reconectar-core' ); ?>
	</a>
</div>

<p class="rc-painel-empresas__nota">
	<?php esc_html_e( 'As campanhas publicadas e dentro do período aparecem na faixa da home, na ordem desta lista.', 'reconectar-core' ); ?>
</p>

<?php if ( empty( $rc_campanhas ) ) : ?>

	<p class="rc-painel-empresas__vazio">
		<?php esc_html_e( 'Nenhuma campanha criada.', 'reconectar-core' ); ?>
	</p>

<?php else : ?>

	<div class="rc-painel-gestao__rolagem">
		<table class="rc-tabela">
			<caption class="rc-tabela__legenda">
				<?php esc_html_e( 'Campanhas da home, com arte, período, ordem e situação de cada uma.', 'reconectar-core' ); ?>
			</caption>
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Arte', 'reconectar-core' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Campanha', 'reconectar-core' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Período', 'reconectar-core' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Ordem', 'reconectar-core' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Situação', 'reconectar-core' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $rc_campanhas as $rc_campanha ) : ?>
					<?php
					$rc_situacao = Reconectar_Painel_Campanhas::situacao( $rc_campanha );
					$rc_inicio   = (string) get_post_meta( $rc_campanha->ID, Reconectar_Campanha::META_INICIO, true );
					$rc_fim      = (string) get_post_meta( $rc_campanha->ID, Reconectar_Campanha::META_FIM, true );
					?>
					<tr>
						<td>
							<?php
							// `alt` vazio: a miniatura repete o nome da linha, que o
							// leitor de tela já lê na célula ao lado.
							if ( has_post_thumbnail( $rc_campanha ) ) {
								echo get_the_post_thumbnail(
									$rc_campanha,
									'thumbnail',
									array(
										'class'   => 'rc-painel-gestao__miniatura',
										'alt'     => '',
										'loading' => 'lazy',
									)
								);
							} else {
								echo '<span class="rc-painel-gestao__miniatura rc-painel-gestao__miniatura--vazia" aria-hidden="true"></span>';
							}
							?>
						</td>
						<th scope="row">
							<a href="<?php echo esc_url( Reconectar_Painel_Gestao::url( Reconectar_Painel_Campanhas::AREA, $rc_campanha->ID ) ); ?>">
								<?php echo esc_html( '' !== $rc_campanha->post_title ? $rc_campanha->post_title : __( '(sem nome)', 'reconectar-core' ) ); ?>
							</a>
						</th>
						<td>
							<?php
							printf(
								/* translators: 1: primeiro dia. 2: último dia. */
								esc_html__( '%1$s a %2$s', 'reconectar-core' ),
								esc_html( Reconectar_Painel_Gestao::data_legivel( $rc_inicio, __( 'desde a publicação', 'reconectar-core' ) ) ),
								esc_html( Reconectar_Painel_Gestao::data_legivel( $rc_fim, __( 'sem data de fim', 'reconectar-core' ) ) )
							);
							?>
						</td>
						<td><?php echo esc_html( number_format_i18n( (int) get_post_meta( $rc_campanha->ID, Reconectar_Campanha::META_ORDEM, true ) ) ); ?></td>
						<td>
							<span class="rc-selo <?php echo $rc_situacao['ativo'] ? 'rc-selo--ativo' : 'rc-selo--inativo'; ?>">
								<?php echo esc_html( $rc_situacao['rotulo'] ); ?>
							</span>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	</div>

<?php endif; ?>
