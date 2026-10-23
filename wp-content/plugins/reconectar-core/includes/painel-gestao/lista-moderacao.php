<?php
/**
 * A fila de moderação: avaliações de produto e itens retidos do fórum.
 *
 * Incluída por `tela.php`. O acesso já foi decidido em
 * `Reconectar_Painel_Gestao::proteger()`.
 *
 * Um `<form>` por linha, com um botão por decisão. Os botões levam
 * `name="decisao"`, e o navegador envia só o valor do que foi clicado — sem
 * JavaScript e sem um `<select>` que exigiria dois passos para uma ação só.
 *
 * @package reconectar-core
 */

defined( 'ABSPATH' ) || exit;

$rc_aba        = Reconectar_Painel_Moderacao::aba();
$rc_situacao   = Reconectar_Painel_Moderacao::situacao( $rc_aba );
$rc_pagina     = Reconectar_Painel_Moderacao::pagina();
$rc_situacoes  = Reconectar_Painel_Moderacao::situacoes( $rc_aba );
$rc_decisoes   = Reconectar_Painel_Moderacao::decisoes( $rc_situacao, $rc_aba );
$rc_eh_forum   = 'forum' === $rc_aba;
$rc_acao_form  = Reconectar_Painel_Moderacao::url( $rc_aba, $rc_situacao, $rc_pagina );

if ( $rc_eh_forum ) {
	$rc_consulta = Reconectar_Painel_Moderacao::fila_do_forum( $rc_situacao, $rc_pagina );
	$rc_itens    = $rc_consulta->posts;
	$rc_total    = (int) $rc_consulta->found_posts;
} else {
	$rc_itens = Reconectar_Painel_Moderacao::avaliacoes( $rc_situacao, $rc_pagina );
	$rc_total = Reconectar_Painel_Moderacao::contar_avaliacoes( $rc_situacao );
}

$rc_paginas = (int) ceil( $rc_total / Reconectar_Painel_Moderacao::POR_PAGINA );
?>

<div class="rc-painel-empresas__topo">
	<h1 class="rc-painel-empresas__titulo"><?php esc_html_e( 'Moderação', 'reconectar-core' ); ?></h1>
</div>

<?php if ( count( Reconectar_Painel_Moderacao::abas() ) > 1 ) : ?>
	<nav class="rc-painel-gestao__abas" aria-label="<?php esc_attr_e( 'O que moderar', 'reconectar-core' ); ?>">
		<ul>
			<?php foreach ( Reconectar_Painel_Moderacao::abas() as $rc_chave => $rc_rotulo ) : ?>
				<li>
					<a href="<?php echo esc_url( Reconectar_Painel_Moderacao::url( $rc_chave, 'pendentes' ) ); ?>"<?php echo $rc_chave === $rc_aba ? ' aria-current="page"' : ''; ?>>
						<?php echo esc_html( $rc_rotulo ); ?>
					</a>
				</li>
			<?php endforeach; ?>
		</ul>
	</nav>
<?php endif; ?>

<nav class="rc-painel-gestao__situacoes" aria-label="<?php esc_attr_e( 'Situação', 'reconectar-core' ); ?>">
	<ul>
		<?php foreach ( $rc_situacoes as $rc_chave => $rc_rotulo ) : ?>
			<?php
			$rc_contagem = $rc_eh_forum
				? Reconectar_Painel_Moderacao::contar_do_forum( $rc_chave )
				: Reconectar_Painel_Moderacao::contar_avaliacoes( $rc_chave );
			?>
			<li>
				<a href="<?php echo esc_url( Reconectar_Painel_Moderacao::url( $rc_aba, $rc_chave ) ); ?>"<?php echo $rc_chave === $rc_situacao ? ' aria-current="page"' : ''; ?>>
					<?php echo esc_html( $rc_rotulo ); ?>
					<span class="rc-painel-gestao__contagem">(<?php echo esc_html( number_format_i18n( $rc_contagem ) ); ?>)</span>
				</a>
			</li>
		<?php endforeach; ?>
	</ul>
</nav>

<?php if ( empty( $rc_itens ) ) : ?>

	<p class="rc-painel-empresas__vazio">
		<?php esc_html_e( 'Nada nesta situação.', 'reconectar-core' ); ?>
	</p>

<?php else : ?>

	<div class="rc-painel-gestao__rolagem">
		<table class="rc-tabela rc-painel-gestao__moderacao">
			<caption class="rc-tabela__legenda">
				<?php
				echo esc_html(
					$rc_eh_forum
						? __( 'Perguntas e respostas do fórum nesta situação, com autor, onde foram escritas, trecho, data e as ações possíveis.', 'reconectar-core' )
						: __( 'Avaliações de produto nesta situação, com autor, nota, produto, trecho, data e as ações possíveis.', 'reconectar-core' )
				);
				?>
			</caption>
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Autor', 'reconectar-core' ); ?></th>
					<?php if ( $rc_eh_forum ) : ?>
						<th scope="col"><?php esc_html_e( 'Tipo', 'reconectar-core' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Pergunta', 'reconectar-core' ); ?></th>
					<?php else : ?>
						<th scope="col"><?php esc_html_e( 'Nota', 'reconectar-core' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Produto', 'reconectar-core' ); ?></th>
					<?php endif; ?>
					<th scope="col"><?php esc_html_e( 'Texto', 'reconectar-core' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Data', 'reconectar-core' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Ações', 'reconectar-core' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $rc_itens as $rc_item ) : ?>
					<?php
					if ( $rc_eh_forum ) {
						$rc_id        = (int) $rc_item->ID;
						$rc_eh_topico = bbp_get_topic_post_type() === $rc_item->post_type;
						$rc_autor     = $rc_eh_topico ? bbp_get_topic_author_display_name( $rc_id ) : bbp_get_reply_author_display_name( $rc_id );
						$rc_topico_id = $rc_eh_topico ? $rc_id : (int) bbp_get_reply_topic_id( $rc_id );
						$rc_onde      = get_the_title( $rc_topico_id );
						$rc_texto     = $rc_item->post_content;
						$rc_data      = get_post_timestamp( $rc_item );
					} else {
						$rc_id    = (int) $rc_item->comment_ID;
						$rc_autor = $rc_item->comment_author;
						$rc_nota  = (int) get_comment_meta( $rc_id, 'rating', true );
						$rc_onde  = get_the_title( (int) $rc_item->comment_post_ID );
						$rc_texto = $rc_item->comment_content;
						$rc_data  = strtotime( $rc_item->comment_date_gmt . ' UTC' );
					}
					?>
					<tr>
						<th scope="row"><?php echo esc_html( '' !== (string) $rc_autor ? $rc_autor : __( 'Anônimo', 'reconectar-core' ) ); ?></th>
						<?php if ( $rc_eh_forum ) : ?>
							<td><?php echo esc_html( $rc_eh_topico ? __( 'Pergunta', 'reconectar-core' ) : __( 'Resposta', 'reconectar-core' ) ); ?></td>
						<?php else : ?>
							<td>
								<?php
								echo esc_html(
									$rc_nota > 0
										/* translators: %d: nota de 1 a 5. */
										? sprintf( _n( '%d estrela', '%d estrelas', $rc_nota, 'reconectar-core' ), $rc_nota )
										: __( 'Sem nota', 'reconectar-core' )
								);
								?>
							</td>
						<?php endif; ?>
						<td><?php echo esc_html( $rc_onde ); ?></td>
						<td class="rc-painel-gestao__trecho"><?php echo esc_html( wp_trim_words( wp_strip_all_tags( $rc_texto ), 24 ) ); ?></td>
						<td>
							<?php
							echo esc_html( $rc_data ? wp_date( get_option( 'date_format' ), $rc_data ) : '—' );
							?>
						</td>
						<td>
							<form class="rc-painel-gestao__decisoes" method="post" action="<?php echo esc_url( $rc_acao_form ); ?>">
								<?php Reconectar_Painel_Gestao::campos_da_acao( 'moderar', '' ); ?>
								<input type="hidden" name="aba" value="<?php echo esc_attr( $rc_aba ); ?>">
								<input type="hidden" name="situacao" value="<?php echo esc_attr( $rc_situacao ); ?>">
								<input type="hidden" name="<?php echo esc_attr( Reconectar_Painel_Moderacao::PARAMETRO_PAGINA ); ?>" value="<?php echo esc_attr( $rc_pagina ); ?>">
								<input type="hidden" name="alvo" value="<?php echo esc_attr( $rc_id ); ?>">
								<?php foreach ( $rc_decisoes as $rc_decisao => $rc_rotulo ) : ?>
									<button
										type="submit"
										name="decisao"
										value="<?php echo esc_attr( $rc_decisao ); ?>"
										class="rc-botao <?php echo 'aprovar' === $rc_decisao || 'restaurar' === $rc_decisao || 'nao_spam' === $rc_decisao ? 'rc-botao--primario' : 'rc-botao--discreto'; ?>"
									>
										<?php echo esc_html( $rc_rotulo ); ?>
									</button>
								<?php endforeach; ?>
							</form>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	</div>

	<?php if ( $rc_paginas > 1 ) : ?>
		<nav class="rc-painel-gestao__paginacao" aria-label="<?php esc_attr_e( 'Páginas da lista', 'reconectar-core' ); ?>">
			<?php
			echo wp_kses_post(
				paginate_links(
					array(
						'base'      => add_query_arg( Reconectar_Painel_Moderacao::PARAMETRO_PAGINA, '%#%', Reconectar_Painel_Moderacao::url( $rc_aba, $rc_situacao ) ),
						'format'    => '',
						'current'   => $rc_pagina,
						'total'     => $rc_paginas,
						'type'      => 'list',
						'prev_text' => __( 'Anterior', 'reconectar-core' ),
						'next_text' => __( 'Próxima', 'reconectar-core' ),
					)
				)
			);
			?>
		</nav>
	<?php endif; ?>

<?php endif; ?>
