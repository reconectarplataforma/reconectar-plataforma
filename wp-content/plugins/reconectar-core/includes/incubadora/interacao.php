<?php
/**
 * "Gostei/Não gostei" e comentários, no pé da página.
 *
 * Incluído por `leitura.php`, depois do texto. Formulários comuns, que voltam
 * para a mesma página na âncora certa: funcionam sem script, e o botão de
 * "Gostei" não depende de nada carregar para contar.
 *
 * Quem não participa da comunidade — o comprador — lê os totais e a conversa,
 * e recebe uma frase dizendo quem escreve aqui. Sem ela, a ausência do campo
 * pareceria defeito.
 *
 * "Responder" abre num `<details>` **fechado**: com o formulário aberto em cada
 * comentário, a conversa viraria uma pilha de campos vazios.
 *
 * @package reconectar-core
 *
 * @var WP_Post $rc_pagina
 */

defined( 'ABSPATH' ) || exit;

if ( 'publish' !== $rc_pagina->post_status ) {
	return;
}

$rc_participa = Reconectar_Incubadora_Interacao::pode_participar();
$rc_modera    = Reconectar_Incubadora_Interacao::pode_moderar();
$rc_totais    = Reconectar_Incubadora_Interacao::totais( $rc_pagina->ID );
$rc_minha     = Reconectar_Incubadora_Interacao::avaliacao_do_usuario( $rc_pagina->ID );
$rc_conversa  = Reconectar_Incubadora_Interacao::conversa( $rc_pagina->ID );
$rc_total     = Reconectar_Incubadora_Interacao::contar( $rc_conversa );
$rc_destino   = Reconectar_Incubadora_Interacao::url_do_formulario();

/**
 * Imprime um comentário, com as ações que o usuário corrente tem sobre ele.
 *
 * Closure, e não função nomeada: o template pode ser incluído mais de uma vez
 * numa requisição, e uma função declarada aqui seria redeclarada.
 *
 * @param WP_Comment $comentario Comentário.
 * @return void
 */
$rc_imprimir = static function ( $comentario ) use ( $rc_pagina, $rc_participa, $rc_modera, $rc_destino ) {
	$id       = (int) $comentario->comment_ID;
	$oculto   = Reconectar_Incubadora_Interacao::oculto( $comentario );
	$momento  = (int) strtotime( $comentario->comment_date_gmt . ' UTC' );
	$excluir  = Reconectar_Incubadora_Interacao::pode_excluir( $comentario );
	$responde = $rc_participa && ! $oculto;
	?>
	<article class="rc-comentario<?php echo $oculto ? ' rc-comentario--oculto' : ''; ?>" id="rc-comentario-<?php echo esc_attr( $id ); ?>">
		<?php
		// `alt` vazio: o nome vem logo ao lado, e o leitor de tela o leria duas vezes.
		echo get_avatar( (int) $comentario->user_id, 40, '', '', array( 'class' => 'rc-comentario__avatar' ) );
		?>
		<div class="rc-comentario__corpo">
			<p class="rc-comentario__cabecalho">
				<span class="rc-comentario__autor"><?php echo esc_html( Reconectar_Incubadora_Interacao::autor( $comentario ) ); ?></span>
				<time class="rc-comentario__data" datetime="<?php echo esc_attr( gmdate( 'c', $momento ) ); ?>" title="<?php echo esc_attr( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $momento ) ); ?>">
					<?php
					/* translators: %s: intervalo, como "5 minutos". */
					echo esc_html( sprintf( __( 'há %s', 'reconectar-core' ), human_time_diff( $momento, time() ) ) );
					?>
				</time>
				<?php if ( $oculto ) : ?>
					<span class="rc-comentario__selo"><?php esc_html_e( 'Oculto — só a moderação vê', 'reconectar-core' ); ?></span>
				<?php endif; ?>
			</p>
			<p class="rc-comentario__texto"><?php echo Reconectar_Incubadora_Interacao::texto( $comentario ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- `esc_html()` antes do `nl2br()`. ?></p>

			<?php if ( $responde || $rc_modera || $excluir ) : ?>
				<div class="rc-comentario__acoes">
					<?php if ( $responde ) : ?>
						<details class="rc-comentario__responder">
							<summary><?php esc_html_e( 'Responder', 'reconectar-core' ); ?></summary>
							<form class="rc-comentarios__form" method="post" action="<?php echo esc_attr( $rc_destino ); ?>">
								<?php Reconectar_Incubadora_Interacao::campos( 'comentar', $rc_pagina->ID ); ?>
								<input type="hidden" name="pagina" value="<?php echo esc_attr( $rc_pagina->ID ); ?>">
								<input type="hidden" name="mae" value="<?php echo esc_attr( $id ); ?>">
								<label class="screen-reader-text" for="rc-resposta-<?php echo esc_attr( $id ); ?>">
									<?php
									/* translators: %s: nome de quem escreveu o comentário respondido. */
									echo esc_html( sprintf( __( 'Resposta a %s', 'reconectar-core' ), Reconectar_Incubadora_Interacao::autor( $comentario ) ) );
									?>
								</label>
								<textarea class="rc-comentarios__campo" id="rc-resposta-<?php echo esc_attr( $id ); ?>" name="texto" rows="2" maxlength="<?php echo esc_attr( Reconectar_Incubadora_Interacao::MAXIMO ); ?>" required></textarea>
								<button type="submit" class="rc-incubadora__botao rc-incubadora__botao--primario"><?php esc_html_e( 'Responder', 'reconectar-core' ); ?></button>
							</form>
						</details>
					<?php endif; ?>

					<?php if ( $rc_modera || $excluir ) : ?>
						<form class="rc-comentario__moderar" method="post" action="<?php echo esc_attr( $rc_destino ); ?>">
							<?php Reconectar_Incubadora_Interacao::campos( 'moderar', $id ); ?>
							<input type="hidden" name="comentario" value="<?php echo esc_attr( $id ); ?>">
							<?php if ( $rc_modera ) : ?>
								<button type="submit" class="rc-comentario__acao" name="operacao" value="<?php echo $oculto ? 'mostrar' : 'ocultar'; ?>"><?php echo esc_html( $oculto ? __( 'Mostrar', 'reconectar-core' ) : __( 'Ocultar', 'reconectar-core' ) ); ?></button>
							<?php endif; ?>
							<?php if ( $excluir ) : ?>
								<button type="submit" class="rc-comentario__acao rc-comentario__acao--perigo" name="operacao" value="excluir"><?php echo esc_html( (int) $comentario->comment_parent ? __( 'Excluir', 'reconectar-core' ) : __( 'Excluir com as respostas', 'reconectar-core' ) ); ?></button>
							<?php endif; ?>
						</form>
					<?php endif; ?>
				</div>
			<?php endif; ?>
		</div>
	</article>
	<?php
};
?>
<section class="rc-incubadora__interacao" aria-label="<?php esc_attr_e( 'Avaliação e comentários', 'reconectar-core' ); ?>">
	<div class="rc-avaliacao" id="rc-incubadora-avaliacao">
		<p class="rc-avaliacao__pergunta" id="rc-avaliacao-pergunta"><?php esc_html_e( 'Esta página ajudou você?', 'reconectar-core' ); ?></p>
		<?php
		$rc_opcoes = array(
			1  => array(
				'rotulo' => __( 'Gostei', 'reconectar-core' ),
				'total'  => $rc_totais['gostei'],
				'classe' => 'rc-avaliacao__botao--gostei',
			),
			-1 => array(
				'rotulo' => __( 'Não gostei', 'reconectar-core' ),
				'total'  => $rc_totais['nao_gostei'],
				'classe' => 'rc-avaliacao__botao--nao-gostei',
			),
		);

		if ( $rc_participa ) :
			?>
			<form class="rc-avaliacao__form" method="post" action="<?php echo esc_attr( $rc_destino ); ?>" aria-labelledby="rc-avaliacao-pergunta">
				<?php Reconectar_Incubadora_Interacao::campos( 'avaliar', $rc_pagina->ID ); ?>
				<input type="hidden" name="pagina" value="<?php echo esc_attr( $rc_pagina->ID ); ?>">
				<?php foreach ( $rc_opcoes as $rc_sentido => $rc_opcao ) : ?>
					<?php // `aria-pressed` em `<button>`, onde ele vale; clicar de novo desfaz, como diz o estado. ?>
					<button type="submit" class="rc-avaliacao__botao <?php echo esc_attr( $rc_opcao['classe'] ); ?>" name="sentido" value="<?php echo esc_attr( $rc_sentido ); ?>" aria-pressed="<?php echo $rc_minha === $rc_sentido ? 'true' : 'false'; ?>">
						<svg class="rc-avaliacao__icone" viewBox="0 0 24 24" width="20" height="20" aria-hidden="true" focusable="false"><path d="M2 21h4V9H2v12zm20-11a2 2 0 0 0-2-2h-6.3l1-4.6v-.3c0-.4-.2-.8-.4-1.1L13.2 1 6.6 7.6C6.2 8 6 8.5 6 9v10a2 2 0 0 0 2 2h9c.8 0 1.5-.5 1.8-1.2l3-7.1c.1-.2.2-.5.2-.7v-2z" fill="currentColor"/></svg>
						<span class="rc-avaliacao__rotulo"><?php echo esc_html( $rc_opcao['rotulo'] ); ?></span>
						<span class="rc-avaliacao__total"><?php echo esc_html( number_format_i18n( $rc_opcao['total'] ) ); ?></span>
					</button>
				<?php endforeach; ?>
			</form>
			<?php
		else :
			?>
			<p class="rc-avaliacao__totais">
				<?php
				printf(
					/* translators: 1: total de "Gostei", 2: total de "Não gostei". */
					esc_html__( '%1$s gostaram · %2$s não gostaram', 'reconectar-core' ),
					esc_html( number_format_i18n( $rc_totais['gostei'] ) ),
					esc_html( number_format_i18n( $rc_totais['nao_gostei'] ) )
				);
				?>
			</p>
			<?php
		endif;
		?>
	</div>

	<div class="rc-comentarios" id="rc-incubadora-comentarios">
		<h2 class="rc-comentarios__titulo">
			<?php
			echo esc_html(
				$rc_total
					/* translators: %s: número de comentários. */
					? sprintf( _n( '%s comentário', '%s comentários', $rc_total, 'reconectar-core' ), number_format_i18n( $rc_total ) )
					: __( 'Comentários', 'reconectar-core' )
			);
			?>
		</h2>

		<?php if ( $rc_participa ) : ?>
			<form class="rc-comentarios__form rc-comentarios__form--novo" method="post" action="<?php echo esc_attr( $rc_destino ); ?>">
				<?php Reconectar_Incubadora_Interacao::campos( 'comentar', $rc_pagina->ID ); ?>
				<input type="hidden" name="pagina" value="<?php echo esc_attr( $rc_pagina->ID ); ?>">
				<label class="rc-incubadora__rotulo" for="rc-comentario-novo"><?php esc_html_e( 'Adicione um comentário', 'reconectar-core' ); ?></label>
				<textarea class="rc-comentarios__campo" id="rc-comentario-novo" name="texto" rows="3" maxlength="<?php echo esc_attr( Reconectar_Incubadora_Interacao::MAXIMO ); ?>" required></textarea>
				<button type="submit" class="rc-incubadora__botao rc-incubadora__botao--primario"><?php esc_html_e( 'Comentar', 'reconectar-core' ); ?></button>
			</form>
		<?php else : ?>
			<p class="rc-comentarios__aviso"><?php esc_html_e( 'Avaliar e comentar é para quem participa da comunidade: as lojas e a equipe da Incubadora.', 'reconectar-core' ); ?></p>
		<?php endif; ?>

		<?php if ( $rc_conversa ) : ?>
			<ol class="rc-comentarios__lista">
				<?php foreach ( $rc_conversa as $rc_fio ) : ?>
					<li class="rc-comentarios__fio">
						<?php $rc_imprimir( $rc_fio['comentario'] ); ?>
						<?php if ( $rc_fio['respostas'] ) : ?>
							<ol class="rc-comentarios__respostas">
								<?php foreach ( $rc_fio['respostas'] as $rc_resposta ) : ?>
									<li><?php $rc_imprimir( $rc_resposta ); ?></li>
								<?php endforeach; ?>
							</ol>
						<?php endif; ?>
					</li>
				<?php endforeach; ?>
			</ol>
		<?php elseif ( ! $rc_participa ) : ?>
			<p class="rc-comentarios__vazio"><?php esc_html_e( 'Ainda não há comentários nesta página.', 'reconectar-core' ); ?></p>
		<?php endif; ?>
	</div>
</section>
