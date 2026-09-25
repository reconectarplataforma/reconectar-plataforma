<?php
/**
 * A tela de uma categoria de perguntas.
 *
 * A mesma listagem do índice, com a categoria fixada pelo template — e não pela
 * query string, que aqui não a carrega. O formulário de nova pergunta continua
 * sendo o do bbPress: é ele que trata nonce, KSES e a criação do tópico, e
 * reescrevê-lo daria uma segunda porta de entrada para manter em dia.
 *
 * @package reconectar
 */

defined( 'ABSPATH' ) || exit;

$reconectar_forum_id = bbp_get_forum_id();

?>

<div id="bbpress-forums" class="bbpress-wrapper rc-forum-wrapper">

	<?php do_action( 'bbp_template_before_single_forum' ); ?>

	<?php if ( post_password_required() ) : ?>

		<?php bbp_get_template_part( 'form', 'protected' ); ?>

	<?php else : ?>

		<?php if ( function_exists( 'reconectar_forum_listagem' ) ) : ?>

			<?php
			reconectar_forum_listagem(
				array(
					'titulo'    => get_the_title( $reconectar_forum_id ),
					'categoria' => $reconectar_forum_id,
				)
			);
			?>

		<?php else : ?>

			<?php bbp_single_forum_description(); ?>

			<?php if ( ! bbp_is_forum_category() && bbp_has_topics() ) : ?>
				<?php bbp_get_template_part( 'loop', 'topics' ); ?>
			<?php endif; ?>

		<?php endif; ?>

		<?php
		/*
		 * Uma categoria do bbPress (`bbp_is_forum_category()`) é um agrupador que
		 * não aceita tópicos: imprimir ali o formulário daria um campo que recusa
		 * qualquer envio.
		 */
		?>
		<?php if ( ! bbp_is_forum_category() ) : ?>
			<div class="rc-forum__form-pergunta">
				<?php bbp_get_template_part( 'form', 'topic' ); ?>
			</div>
		<?php endif; ?>

	<?php endif; ?>

	<?php do_action( 'bbp_template_after_single_forum' ); ?>

</div>
