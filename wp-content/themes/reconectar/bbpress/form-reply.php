<?php
/**
 * Formulário de resposta — e de edição de uma resposta existente.
 *
 * Override de `bbpress/templates/default/bbpress/form-reply.php`, só de markup,
 * no mesmo molde de `form-topic.php`: nomes de campo, `id="new-post"` e ganchos
 * `bbp_theme_*` são os do original, e o envio segue no handler do bbPress.
 *
 * A classe `rc-forum-form` no contêiner é o que traz os estilos dos campos — o
 * CSS do formulário de pergunta está escopado nela, e não no card, justamente
 * para servir aos dois formulários.
 *
 * Aqui os campos ocultos ficam no fim, como no original: diferente do de
 * pergunta, nenhum campo visível repete o nome de um oculto. O `bbp_reply_to`
 * oculto só sai na criação, e o seletor de mesmo nome só na edição.
 *
 * @package reconectar
 */

defined( 'ABSPATH' ) || exit;

$reconectar_editando = bbp_is_reply_edit();

$reconectar_textos_de_tags = array(
	'remover'    => __( 'Remover a tag %s', 'reconectar' ),
	'adicionada' => __( 'Tag %s adicionada.', 'reconectar' ),
	'removida'   => __( 'Tag %s removida.', 'reconectar' ),
	'repetida'   => __( 'A tag %s já está na lista.', 'reconectar' ),
);

$reconectar_tags_sugeridas = function_exists( 'reconectar_forum_tags_populares' ) ? reconectar_forum_tags_populares( 30 ) : array();

if ( $reconectar_editando ) : ?>

<div id="bbpress-forums" class="bbpress-wrapper">

	<?php bbp_breadcrumb(); ?>

<?php endif; ?>

<?php if ( bbp_current_user_can_access_create_reply_form() ) : ?>

	<div id="new-reply-<?php bbp_topic_id(); ?>" class="bbp-reply-form rc-forum-form">

		<form id="new-post" name="new-post" method="post">

			<?php do_action( 'bbp_theme_before_reply_form' ); ?>

			<fieldset class="bbp-form">
				<legend>
					<?php
					if ( $reconectar_editando ) {
						esc_html_e( 'Editando resposta', 'reconectar' );
					} elseif ( bbp_get_form_reply_to() ) {
						/* translators: %s: número da resposta citada. */
						printf( esc_html__( 'Responder à resposta #%s', 'reconectar' ), esc_html( bbp_get_form_reply_to() ) );
					} else {
						esc_html_e( 'Sua resposta', 'reconectar' );
					}
					?>
				</legend>

				<?php do_action( 'bbp_theme_before_reply_form_notices' ); ?>

				<?php if ( ! bbp_is_topic_open() && ! $reconectar_editando ) : ?>

					<div class="bbp-template-notice">
						<ul>
							<li><?php esc_html_e( 'Esta pergunta está fechada para novas respostas, mas o seu perfil ainda pode responder.', 'reconectar' ); ?></li>
						</ul>
					</div>

				<?php endif; ?>

				<?php if ( ! $reconectar_editando && bbp_is_forum_closed() ) : ?>

					<div class="bbp-template-notice">
						<ul>
							<li><?php esc_html_e( 'Esta categoria está fechada para novas publicações, mas o seu perfil ainda pode publicar nela.', 'reconectar' ); ?></li>
						</ul>
					</div>

				<?php endif; ?>

				<?php if ( current_user_can( 'unfiltered_html' ) ) : ?>

					<div class="bbp-template-notice">
						<ul>
							<li><?php esc_html_e( 'Sua conta pode publicar HTML sem restrição.', 'reconectar' ); ?></li>
						</ul>
					</div>

				<?php endif; ?>

				<?php do_action( 'bbp_template_notices' ); ?>

				<div class="rc-forum-form__campos">

					<?php bbp_get_template_part( 'form', 'anonymous' ); ?>

					<?php do_action( 'bbp_theme_before_reply_form_content' ); ?>

					<div class="rc-forum__campo rc-forum-form__conteudo">
						<?php // Mesmo motivo do formulário de pergunta: o bbPress deixa o corpo sem rótulo. ?>
						<label class="rc-forum__rotulo" for="bbp_reply_content"><?php esc_html_e( 'Resposta', 'reconectar' ); ?></label>
						<?php bbp_the_content( array( 'context' => 'reply' ) ); ?>
					</div>

					<?php do_action( 'bbp_theme_after_reply_form_content' ); ?>

					<?php bbp_get_template_part( 'form', 'allowed-tags' ); ?>

					<?php if ( bbp_allow_topic_tags() && current_user_can( 'assign_topic_tags', bbp_get_topic_id() ) ) : ?>

						<?php do_action( 'bbp_theme_before_reply_form_tags' ); ?>

						<p class="rc-forum__campo">
							<?php
							/*
							 * As tags são da pergunta, não da resposta: o handler de resposta grava
							 * este campo nos termos do tópico. O rótulo diz isso, para ninguém
							 * achar que está etiquetando só o que escreveu.
							 */
							?>
							<label class="rc-forum__rotulo" for="bbp_topic_tags"><?php esc_html_e( 'Tags da pergunta', 'reconectar' ); ?></label>
							<input
								class="rc-forum-form__entrada"
								type="text"
								id="bbp_topic_tags"
								name="bbp_topic_tags"
								value="<?php bbp_form_topic_tags(); ?>"
								data-role="tagsinput"
								data-rc-tags-textos="<?php echo esc_attr( wp_json_encode( $reconectar_textos_de_tags ) ); ?>"
								<?php echo $reconectar_tags_sugeridas ? 'data-rc-tags-sugestoes="rc-forum-form-tags-sugestoes"' : ''; ?>
								aria-describedby="rc-forum-form-tags-ajuda"
								<?php disabled( bbp_is_topic_spam() ); ?>
							/>
							<span class="rc-forum-form__ajuda" id="rc-forum-form-tags-ajuda">
								<?php esc_html_e( 'Separe as tags com vírgula ou Enter. Ex.: frete, embalagem.', 'reconectar' ); ?>
							</span>
						</p>

						<?php if ( $reconectar_tags_sugeridas ) : ?>
							<datalist id="rc-forum-form-tags-sugestoes">
								<?php foreach ( $reconectar_tags_sugeridas as $reconectar_tag ) : ?>
									<option value="<?php echo esc_attr( $reconectar_tag->name ); ?>"></option>
								<?php endforeach; ?>
							</datalist>
						<?php endif; ?>

						<?php do_action( 'bbp_theme_after_reply_form_tags' ); ?>

					<?php endif; ?>

					<?php if ( bbp_is_subscriptions_active() && ! bbp_is_anonymous() && ( ! $reconectar_editando || ( $reconectar_editando && ! bbp_is_reply_anonymous() ) ) ) : ?>

						<?php do_action( 'bbp_theme_before_reply_form_subscription' ); ?>

						<p class="rc-forum-form__opcao">
							<input name="bbp_topic_subscription" id="bbp_topic_subscription" type="checkbox" value="bbp_subscribe" <?php bbp_form_topic_subscribed(); ?> />

							<?php if ( $reconectar_editando && ( bbp_get_reply_author_id() !== bbp_get_current_user_id() ) ) : ?>
								<label for="bbp_topic_subscription"><?php esc_html_e( 'Avisar o autor por e-mail quando houver novas respostas', 'reconectar' ); ?></label>
							<?php else : ?>
								<label for="bbp_topic_subscription"><?php esc_html_e( 'Avisar-me por e-mail quando houver novas respostas', 'reconectar' ); ?></label>
							<?php endif; ?>
						</p>

						<?php do_action( 'bbp_theme_after_reply_form_subscription' ); ?>

					<?php endif; ?>

					<?php if ( $reconectar_editando ) : ?>

						<?php if ( current_user_can( 'moderate', bbp_get_reply_id() ) ) : ?>

							<div class="rc-forum-form__linha">

								<?php do_action( 'bbp_theme_before_reply_form_reply_to' ); ?>

								<p class="rc-forum__campo form-reply-to">
									<label class="rc-forum__rotulo" for="bbp_reply_to"><?php esc_html_e( 'Em resposta a', 'reconectar' ); ?></label>
									<?php
									/*
									 * `bbp_get_reply_to_dropdown()` fixa `bbp_dropdown` e não aceita
									 * classe: trocar na saída evita reescrever a consulta dele.
									 */
									echo str_replace( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- HTML já escapado pelo bbPress.
										'class="bbp_dropdown"',
										'class="rc-forum__select rc-forum-form__select"',
										bbp_get_reply_to_dropdown()
									);
									?>
								</p>

								<?php do_action( 'bbp_theme_after_reply_form_reply_to' ); ?>

								<?php do_action( 'bbp_theme_before_reply_form_status' ); ?>

								<p class="rc-forum__campo">
									<label class="rc-forum__rotulo" for="bbp_reply_status_select"><?php esc_html_e( 'Status', 'reconectar' ); ?></label>
									<?php bbp_form_reply_status_dropdown( array( 'select_class' => 'rc-forum__select rc-forum-form__select' ) ); ?>
								</p>

								<?php do_action( 'bbp_theme_after_reply_form_status' ); ?>

							</div>

						<?php endif; ?>

						<?php if ( bbp_allow_revisions() ) : ?>

							<?php do_action( 'bbp_theme_before_reply_form_revisions' ); ?>

							<div class="rc-forum-form__revisao">
								<p class="rc-forum-form__opcao">
									<input name="bbp_log_reply_edit" id="bbp_log_reply_edit" type="checkbox" value="1" <?php bbp_form_reply_log_edit(); ?> />
									<label for="bbp_log_reply_edit"><?php esc_html_e( 'Registrar esta edição no histórico', 'reconectar' ); ?></label>
								</p>

								<p class="rc-forum__campo">
									<label class="rc-forum__rotulo" for="bbp_reply_edit_reason"><?php esc_html_e( 'Motivo da edição (opcional)', 'reconectar' ); ?></label>
									<input class="rc-forum-form__entrada" type="text" id="bbp_reply_edit_reason" name="bbp_reply_edit_reason" value="<?php bbp_form_reply_edit_reason(); ?>" />
								</p>
							</div>

							<?php do_action( 'bbp_theme_after_reply_form_revisions' ); ?>

						<?php endif; ?>

					<?php endif; ?>

				</div>

				<?php do_action( 'bbp_theme_before_reply_form_submit_wrapper' ); ?>

				<div class="bbp-submit-wrapper">

					<?php do_action( 'bbp_theme_before_reply_form_submit_button' ); ?>

					<?php bbp_cancel_reply_to_link(); ?>

					<button type="submit" id="bbp_reply_submit" name="bbp_reply_submit" class="rc-forum__perguntar rc-forum-form__enviar">
						<?php $reconectar_editando ? esc_html_e( 'Salvar alterações', 'reconectar' ) : esc_html_e( 'Publicar resposta', 'reconectar' ); ?>
					</button>

					<?php do_action( 'bbp_theme_after_reply_form_submit_button' ); ?>

				</div>

				<?php do_action( 'bbp_theme_after_reply_form_submit_wrapper' ); ?>

				<?php bbp_reply_form_fields(); ?>

			</fieldset>

			<?php do_action( 'bbp_theme_after_reply_form' ); ?>

		</form>
	</div>

<?php elseif ( bbp_is_topic_closed() ) : ?>

	<div id="no-reply-<?php bbp_topic_id(); ?>" class="bbp-no-reply">
		<div class="bbp-template-notice">
			<ul>
				<li><?php esc_html_e( 'Esta pergunta está fechada para novas respostas.', 'reconectar' ); ?></li>
			</ul>
		</div>
	</div>

<?php elseif ( bbp_is_forum_closed( bbp_get_topic_forum_id() ) ) : ?>

	<div id="no-reply-<?php bbp_topic_id(); ?>" class="bbp-no-reply">
		<div class="bbp-template-notice">
			<ul>
				<li>
					<?php
					/* translators: %s: nome da categoria. */
					printf( esc_html__( 'A categoria “%s” está fechada para novas perguntas e respostas.', 'reconectar' ), esc_html( bbp_get_forum_title( bbp_get_topic_forum_id() ) ) );
					?>
				</li>
			</ul>
		</div>
	</div>

<?php else : ?>

	<div id="no-reply-<?php bbp_topic_id(); ?>" class="bbp-no-reply">
		<div class="bbp-template-notice">
			<ul>
				<li>
					<?php
					is_user_logged_in()
						? esc_html_e( 'Seu perfil não pode responder a esta pergunta.', 'reconectar' )
						: esc_html_e( 'Entre na sua conta para responder a esta pergunta.', 'reconectar' );
					?>
				</li>
			</ul>
		</div>

		<?php if ( ! is_user_logged_in() ) : ?>

			<?php bbp_get_template_part( 'form', 'user-login' ); ?>

		<?php endif; ?>

	</div>

<?php endif; ?>

<?php if ( $reconectar_editando ) : ?>

</div>

<?php endif; ?>
