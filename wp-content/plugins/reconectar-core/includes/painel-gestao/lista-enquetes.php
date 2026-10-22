<?php
/**
 * As enquetes, com período, votos e situação.
 *
 * Incluída por `tela.php`. O acesso já foi decidido em
 * `Reconectar_Painel_Gestao::proteger()`.
 *
 * @package reconectar-core
 */

defined( 'ABSPATH' ) || exit;

$rc_enquetes = Reconectar_Painel_Enquetes::enquetes();
?>

<div class="rc-painel-empresas__topo">
	<h1 class="rc-painel-empresas__titulo"><?php esc_html_e( 'Enquetes', 'reconectar-core' ); ?></h1>
	<a class="rc-botao rc-botao--primario" href="<?php echo esc_url( Reconectar_Painel_Gestao::url( Reconectar_Painel_Enquetes::AREA, 'nova' ) ); ?>">
		<?php esc_html_e( 'Nova enquete', 'reconectar-core' ); ?>
	</a>
</div>

<?php if ( empty( $rc_enquetes ) ) : ?>

	<p class="rc-painel-empresas__vazio">
		<?php esc_html_e( 'Nenhuma enquete criada.', 'reconectar-core' ); ?>
	</p>

<?php else : ?>

	<div class="rc-painel-gestao__rolagem">
		<table class="rc-tabela">
			<caption class="rc-tabela__legenda">
				<?php esc_html_e( 'Enquetes da plataforma, com período de votação, total de votos e situação de cada uma.', 'reconectar-core' ); ?>
			</caption>
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Enquete', 'reconectar-core' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Período', 'reconectar-core' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Votos', 'reconectar-core' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Situação', 'reconectar-core' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $rc_enquetes as $rc_enquete ) : ?>
					<?php
					$rc_situacao = Reconectar_Painel_Enquetes::situacao( $rc_enquete );
					$rc_inicio   = (string) get_post_meta( $rc_enquete->ID, Reconectar_Proposta_Votacao::META_INICIO, true );
					$rc_fim      = (string) get_post_meta( $rc_enquete->ID, Reconectar_Proposta_Votacao::META_FIM, true );
					?>
					<tr>
						<th scope="row">
							<a href="<?php echo esc_url( Reconectar_Painel_Gestao::url( Reconectar_Painel_Enquetes::AREA, $rc_enquete->ID ) ); ?>">
								<?php echo esc_html( '' !== $rc_enquete->post_title ? $rc_enquete->post_title : __( '(sem pergunta)', 'reconectar-core' ) ); ?>
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
						<td><?php echo esc_html( number_format_i18n( Reconectar_Proposta_Votacao::total_de_votos( $rc_enquete->ID ) ) ); ?></td>
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
