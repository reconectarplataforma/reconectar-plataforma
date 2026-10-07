<?php
/**
 * Formulário de nova pergunta — e de edição de uma existente.
 *
 * Override de `bbpress/templates/default/bbpress/form-topic.php`, só de markup:
 * os nomes dos campos, o `id="new-post"` do formulário e os ganchos
 * `bbp_theme_*` são os do original, e quem trata o envio continua sendo o
 * handler do bbPress — nonce, KSES, permissão e criação do tópico. Reescrever o
 * envio daria uma segunda porta de entrada para manter em dia.
 *
 * O que muda é o desenho: os campos usam o rótulo e o controle dos filtros da
 * listagem (`rc-forum__campo`, `rc-forum__rotulo`, `rc-forum__select`), a
 * categoria vira um seletor visível também dentro de uma categoria, e as tags
 * ganham o campo de etiquetas de `assets/js/forum.js`.
 *
 * @package reconectar
 */

defined( 'ABSPATH' ) || exit;

$reconectar_editando = bbp_is_topic_edit();

/*
 * Na categoria o bbPress esconde a escolha de fórum e prende o tópico à página
 * aberta. O seletor aqui aparece sempre: chegar pelo "Fazer uma pergunta" da
 * listagem leva à primeira categoria que existir, e sem ele a pergunta ficaria
 * lá. Ficam de fora os agrupadores (`bbp_is_forum_category()`), que recusam
 * tópico — oferecê-los seria oferecer um envio que volta com erro.
 */
$reconectar_categorias = array();

if ( function_exists( 'reconectar_forum_categorias' ) ) {
	foreach ( reconectar_forum_categorias() as $reconectar_categoria ) {
		if ( ! bbp_is_forum_category( $reconectar_categoria->ID ) ) {
			$reconectar_categorias[] = $reconectar_categoria;
		}
	}
}

$reconectar_categoria_escolhida = (int) bbp_get_form_topic_forum();

if ( ! $reconectar_categoria_escolhida && bbp_is_single_forum() ) {
	$reconectar_categoria_escolhida = (int) bbp_get_forum_id();
}

/*
 * Sugestões para o campo de tags, das mais usadas. Uma lista curta basta: o
 * objetivo é que "frete" não vire também "Frete" e "fretes", não oferecer o
 * vocabulário inteiro.
 */
$reconectar_tags_sugeridas = function_exists( 'reconectar_forum_tags_populares' ) ? reconectar_forum_tags_populares( 30 ) : array();

$reconectar_textos_de_tags = array(
	'remover'    => __( 'Remover a tag %s', 'reconectar' ),
	'adicionada' => __( 'Tag %s adicionada.', 'reconectar' ),
	'removida'   => __( 'Tag %s removida.', 'reconectar' ),
	'repetida'   => __( 'A tag %s já está na lista.', 'reconectar' ),
);

if ( ! bbp_is_single_forum() ) : ?>

<div id="bbpress-forums" class="bbpress-wrapper">

	<?php bbp_breadcrumb(); ?>

<?php endif; ?>

<?php if ( $reconectar_editando ) : ?>

	<?php bbp_topic_tag_list( bbp_get_topic_id() ); ?>

	<?php bbp_single_topic_description( array( 'topic_id' => bbp_get_topic_id() ) ); ?>

	<?php bbp_get_template_part( 'alert', 'topic-lock' ); ?>

<?php endif; ?>

<?php if ( bbp_current_user_can_access_create_topic_form() ) : ?>

	<div id="new-topic-<?php bbp_topic_id(); ?>" class="bbp-topic-form rc-forum-form">

		<form id="new-post" name="new-post" method="post">

			<?php do_action( 'bbp_theme_before_topic_form' ); ?>

			<fieldset class="bbp-form">
				<legend>
					<?php
					if ( $reconectar_editando ) {
						/* translators: %s: título da pergunta. */
						printf( esc_html__( 'Editando “%s”', 'reconectar' ), esc_html( bbp_get_topic_title() ) );
					} else {
						esc_html_e( 'Fazer uma pergunta', 'reconectar' );
					}
					?>
				</legend>

				<?php
				/*
				 * Os campos ocultos vêm **antes** dos visíveis, e a ordem é a razão de o
				 * seletor de categoria funcionar. Dentro de uma categoria o bbPress
				 * imprime ali um `bbp_forum_id` oculto com a categoria da página; o
				 * seletor abaixo usa o mesmo nome, e o PHP fica com o **último** valor
				 * de um nome repetido no POST. Com os ocultos no fim, como no original,
				 * a escolha do usuário seria enviada e descartada sem aviso — a
				 * pergunta cairia na categoria da página.
				 */
				bbp_topic_form_fields();
				?>

				<?php do_action( 'bbp_theme_before_topic_form_notices' ); ?>

				<?php if ( ! $reconectar_editando && bbp_is_forum_closed() ) : ?>

					<div class="bbp-template-notice">
						<ul>
							<li><?php esc_html_e( 'Esta categoria está fechada para novas perguntas, mas o seu perfil ainda pode publicar nela.', 'reconectar' ); ?></li>
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

					<?php do_action( 'bbp_theme_before_topic_form_title' ); ?>

					<p class="rc-forum__campo">
						<label class="rc-forum__rotulo" for="bbp_topic_title"><?php esc_html_e( 'Título da pergunta', 'reconectar' ); ?></label>
						<input
							class="rc-forum-form__entrada"
							type="text"
							id="bbp_topic_title"
							name="bbp_topic_title"
							value="<?php bbp_form_topic_title(); ?>"
							maxlength="<?php bbp_title_max_length(); ?>"
							aria-describedby="rc-forum-form-titulo-ajuda"
							required
						/>
						<span class="rc-forum-form__ajuda" id="rc-forum-form-titulo-ajuda">
							<?php
							/* translators: %d: número máximo de caracteres do título. */
							printf( esc_html__( 'Até %d caracteres. Escreva a pergunta como você a faria a alguém.', 'reconectar' ), (int) bbp_get_title_max_length() );
							?>
						</span>
					</p>

					<?php do_action( 'bbp_theme_after_topic_form_title' ); ?>

					<?php if ( $reconectar_categorias ) : ?>

						<?php do_action( 'bbp_theme_before_topic_form_forum' ); ?>

						<p class="rc-forum__campo">
							<label class="rc-forum__rotulo" for="rc-forum-form-categoria"><?php esc_html_e( 'Categoria', 'reconectar' ); ?></label>
							<select class="rc-forum__select rc-forum-form__select" id="rc-forum-form-categoria" name="bbp_forum_id" required>
								<?php if ( ! $reconectar_categoria_escolhida ) : ?>
									<option value=""><?php esc_html_e( 'Escolha uma categoria', 'reconectar' ); ?></option>
								<?php endif; ?>
								<?php foreach ( $reconectar_categorias as $reconectar_categoria ) : ?>
									<option value="<?php echo esc_attr( $reconectar_categoria->ID ); ?>" <?php selected( $reconectar_categoria_escolhida, $reconectar_categoria->ID ); ?>>
										<?php echo esc_html( get_the_title( $reconectar_categoria ) ); ?>
									</option>
								<?php endforeach; ?>
							</select>
						</p>

						<?php do_action( 'bbp_theme_after_topic_form_forum' ); ?>

					<?php elseif ( $reconectar_editando ) : ?>

						<?php
						/*
						 * O handler de edição recusa o envio sem `bbp_forum_id` ("Forum ID is
						 * missing"), e `bbp_topic_form_fields()` só o imprime na criação. Sem
						 * categoria para oferecer, a pergunta fica onde está.
						 */
						?>
						<input type="hidden" name="bbp_forum_id" value="<?php echo esc_attr( $reconectar_categoria_escolhida ); ?>" />

					<?php endif; ?>

					<?php do_action( 'bbp_theme_before_topic_form_content' ); ?>

					<div class="rc-forum__campo rc-forum-form__conteudo">
						<?php
						/*
						 * O bbPress não imprime rótulo para o corpo: o `<textarea>` só tinha a
						 * barra de formatação em cima, e o leitor de tela o anunciava como
						 * "campo de edição", sem nome.
						 */
						?>
						<label class="rc-forum__rotulo" for="bbp_topic_content"><?php esc_html_e( 'Detalhes', 'reconectar' ); ?></label>
						<?php bbp_the_content( array( 'context' => 'topic' ) ); ?>
					</div>

					<?php do_action( 'bbp_theme_after_topic_form_content' ); ?>

					<?php bbp_get_template_part( 'form', 'allowed-tags' ); ?>

					<?php if ( bbp_allow_topic_tags() && current_user_can( 'assign_topic_tags', bbp_get_topic_id() ) ) : ?>

						<?php do_action( 'bbp_theme_before_topic_form_tags' ); ?>

						<p class="rc-forum__campo">
							<label class="rc-forum__rotulo" for="bbp_topic_tags"><?php esc_html_e( 'Tags', 'reconectar' ); ?></label>
							<?php
							/*
							 * Sem JavaScript este é o campo do bbPress, separado por vírgula. O
							 * `data-role="tagsinput"` é o gancho de `forum.js`, que o troca por
							 * etiquetas e mantém este mesmo `name` como campo oculto — o handler
							 * recebe a mesma string nos dois casos.
							 */
							?>
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

						<?php do_action( 'bbp_theme_after_topic_form_tags' ); ?>

					<?php endif; ?>

					<?php
					/*
					 * Tipo e status só na edição. Toda pergunta nasce normal e aberta — é para
					 * ser respondida —, e fixar ou fechar é decisão de moderação sobre uma
					 * pergunta que já existe. Na criação os dois selects apareciam para quem
					 * modera e não mudavam nada útil: ninguém fixa a própria pergunta antes de
					 * saber se ela merece. Sem os campos no POST, o handler do bbPress grava
					 * os valores padrão.
					 */
					?>
					<?php if ( $reconectar_editando && current_user_can( 'moderate', bbp_get_topic_id() ) ) : ?>

						<div class="rc-forum-form__linha">

							<?php do_action( 'bbp_theme_before_topic_form_type' ); ?>

							<p class="rc-forum__campo">
								<label class="rc-forum__rotulo" for="bbp_stick_topic"><?php esc_html_e( 'Tipo', 'reconectar' ); ?></label>
								<?php bbp_form_topic_type_dropdown( array( 'select_class' => 'rc-forum__select rc-forum-form__select' ) ); ?>
							</p>

							<?php do_action( 'bbp_theme_after_topic_form_type' ); ?>

							<?php do_action( 'bbp_theme_before_topic_form_status' ); ?>

							<p class="rc-forum__campo">
								<label class="rc-forum__rotulo" for="bbp_topic_status"><?php esc_html_e( 'Status', 'reconectar' ); ?></label>
								<?php bbp_form_topic_status_dropdown( array( 'select_class' => 'rc-forum__select rc-forum-form__select' ) ); ?>
							</p>

							<?php do_action( 'bbp_theme_after_topic_form_status' ); ?>

						</div>

					<?php endif; ?>

					<?php if ( bbp_is_subscriptions_active() && ! bbp_is_anonymous() && ( ! $reconectar_editando || ( $reconectar_editando && ! bbp_is_topic_anonymous() ) ) ) : ?>

						<?php do_action( 'bbp_theme_before_topic_form_subscriptions' ); ?>

						<p class="rc-forum-form__opcao">
							<input name="bbp_topic_subscription" id="bbp_topic_subscription" type="checkbox" value="bbp_subscribe" <?php bbp_form_topic_subscribed(); ?> />

							<?php if ( $reconectar_editando && ( bbp_get_topic_author_id() !== bbp_get_current_user_id() ) ) : ?>
								<label for="bbp_topic_subscription"><?php esc_html_e( 'Avisar o autor por e-mail quando houver respostas', 'reconectar' ); ?></label>
							<?php else : ?>
								<label for="bbp_topic_subscription"><?php esc_html_e( 'Avisar-me por e-mail quando houver respostas', 'reconectar' ); ?></label>
							<?php endif; ?>
						</p>

						<?php do_action( 'bbp_theme_after_topic_form_subscriptions' ); ?>

					<?php endif; ?>

					<?php if ( bbp_allow_revisions() && $reconectar_editando ) : ?>

						<?php do_action( 'bbp_theme_before_topic_form_revisions' ); ?>

						<div class="rc-forum-form__revisao">
							<p class="rc-forum-form__opcao">
								<input name="bbp_log_topic_edit" id="bbp_log_topic_edit" type="checkbox" value="1" <?php bbp_form_topic_log_edit(); ?> />
								<label for="bbp_log_topic_edit"><?php esc_html_e( 'Registrar esta edição no histórico', 'reconectar' ); ?></label>
							</p>

							<p class="rc-forum__campo">
								<label class="rc-forum__rotulo" for="bbp_topic_edit_reason"><?php esc_html_e( 'Motivo da edição (opcional)', 'reconectar' ); ?></label>
								<input class="rc-forum-form__entrada" type="text" id="bbp_topic_edit_reason" name="bbp_topic_edit_reason" value="<?php bbp_form_topic_edit_reason(); ?>" />
							</p>
						</div>

						<?php do_action( 'bbp_theme_after_topic_form_revisions' ); ?>

					<?php endif; ?>

				</div>

				<?php do_action( 'bbp_theme_before_topic_form_submit_wrapper' ); ?>

				<div class="bbp-submit-wrapper">

					<?php do_action( 'bbp_theme_before_topic_form_submit_button' ); ?>

					<button type="submit" id="bbp_topic_submit" name="bbp_topic_submit" class="rc-forum__perguntar rc-forum-form__enviar">
						<?php $reconectar_editando ? esc_html_e( 'Salvar alterações', 'reconectar' ) : esc_html_e( 'Publicar pergunta', 'reconectar' ); ?>
					</button>

					<?php do_action( 'bbp_theme_after_topic_form_submit_button' ); ?>

				</div>

				<?php do_action( 'bbp_theme_after_topic_form_submit_wrapper' ); ?>

			</fieldset>

			<?php do_action( 'bbp_theme_after_topic_form' ); ?>

		</form>
	</div>

<?php elseif ( bbp_is_forum_closed() ) : ?>

	<div id="forum-closed-<?php bbp_forum_id(); ?>" class="bbp-forum-closed">
		<div class="bbp-template-notice">
			<ul>
				<li>
					<?php
					/* translators: %s: nome da categoria. */
					printf( esc_html__( 'A categoria “%s” está fechada para novas perguntas e respostas.', 'reconectar' ), esc_html( bbp_get_forum_title() ) );
					?>
				</li>
			</ul>
		</div>
	</div>

<?php else : ?>

	<div id="no-topic-<?php bbp_forum_id(); ?>" class="bbp-no-topic">
		<div class="bbp-template-notice">
			<ul>
				<li>
					<?php
					is_user_logged_in()
						? esc_html_e( 'Seu perfil não pode publicar perguntas.', 'reconectar' )
						: esc_html_e( 'Entre na sua conta para publicar uma pergunta.', 'reconectar' );
					?>
				</li>
			</ul>
		</div>

		<?php if ( ! is_user_logged_in() ) : ?>

			<?php bbp_get_template_part( 'form', 'user-login' ); ?>

		<?php endif; ?>

	</div>

<?php endif; ?>

<?php if ( ! bbp_is_single_forum() ) : ?>

</div>

<?php endif; ?>
