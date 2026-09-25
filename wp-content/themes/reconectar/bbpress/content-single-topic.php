<?php
/**
 * A tela de uma pergunta.
 *
 * O laço nativo de respostas (`loop-replies`) ordena por data e não sabe de
 * melhor resposta. Aqui as respostas vêm de `reconectar_forum_respostas()`, que
 * traz a aceita à frente — que é o que distingue um Q&A de um fórum.
 *
 * O formulário de resposta continua sendo o do bbPress, pela mesma razão que o
 * de pergunta: nonce, KSES e criação são dele.
 *
 * @package reconectar
 */

defined( 'ABSPATH' ) || exit;

$reconectar_topico_id = bbp_get_topic_id();
$reconectar_topico    = get_post( $reconectar_topico_id );

?>

<div id="bbpress-forums" class="bbpress-wrapper rc-forum-wrapper">

	<?php bbp_breadcrumb(); ?>

	<?php do_action( 'bbp_template_before_single_topic' ); ?>

	<?php if ( post_password_required() ) : ?>

		<?php bbp_get_template_part( 'form', 'protected' ); ?>

	<?php elseif ( ! $reconectar_topico || ! function_exists( 'reconectar_forum_pergunta' ) ) : ?>

		<?php
		// Sem o tema — ou sem o tópico —, entrega o caminho nativo em vez de uma
		// página em branco.
		bbp_get_template_part( 'content', 'single-topic-lead' );

		if ( bbp_has_replies() ) {
			bbp_get_template_part( 'loop', 'replies' );
		}
		?>

	<?php else : ?>

		<div class="rc-forum-topico">

			<?php reconectar_forum_pergunta( $reconectar_topico ); ?>

			<?php $reconectar_respostas = reconectar_forum_respostas( $reconectar_topico_id ); ?>

			<section class="rc-forum-topico__respostas" aria-labelledby="rc-forum-respostas-titulo">
				<h2 class="rc-forum-topico__titulo-respostas" id="rc-forum-respostas-titulo">
					<?php
					printf(
						/* translators: %s: quantidade de respostas. */
						esc_html( _n( '%s resposta', '%s respostas', count( $reconectar_respostas ), 'reconectar' ) ),
						esc_html( number_format_i18n( count( $reconectar_respostas ) ) )
					);
					?>
				</h2>

				<?php if ( $reconectar_respostas ) : ?>
					<ul class="rc-forum-topico__lista">
						<?php foreach ( $reconectar_respostas as $reconectar_resposta ) : ?>
							<li><?php reconectar_forum_resposta( $reconectar_resposta, $reconectar_topico_id ); ?></li>
						<?php endforeach; ?>
					</ul>
				<?php else : ?>
					<p class="rc-forum-topico__sem-resposta">
						<?php esc_html_e( 'Esta pergunta ainda não foi respondida.', 'reconectar' ); ?>
					</p>
				<?php endif; ?>
			</section>

			<div class="rc-forum__form-resposta">
				<?php bbp_get_template_part( 'form', 'reply' ); ?>
			</div>

		</div>

	<?php endif; ?>

	<?php bbp_get_template_part( 'alert', 'topic-lock' ); ?>

	<?php do_action( 'bbp_template_after_single_topic' ); ?>

</div>
