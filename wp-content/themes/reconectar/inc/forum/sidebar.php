<?php
/**
 * As duas colunas laterais do fórum.
 *
 * Nenhum bloco é impresso vazio: uma coluna com o título "Em alta" e nada
 * embaixo não informa, ocupa. Cada função devolve cedo quando a consulta que a
 * alimenta volta sem resultado.
 *
 * @package reconectar
 */

defined( 'ABSPATH' ) || exit;

/**
 * Imprime a coluna de atividade recente.
 */
function reconectar_forum_sidebar_esquerda() {
	$atividade = reconectar_forum_atividade_recente();

	if ( ! $atividade ) {
		return;
	}
	?>
	<aside class="rc-forum__lateral rc-forum__lateral--esquerda" aria-labelledby="rc-forum-atividade-titulo">
		<section class="rc-forum-bloco">
			<h2 class="rc-forum-bloco__titulo" id="rc-forum-atividade-titulo">
				<?php esc_html_e( 'Atividade recente', 'reconectar' ); ?>
			</h2>

			<ul class="rc-forum-atividade">
				<?php foreach ( $atividade as $item ) : ?>
					<?php
					// A resposta não tem título próprio no bbPress — o que ela tem é o
					// título do tópico com um prefixo. Linkar para a pergunta, e dizer
					// que foi uma resposta, é mais honesto que repetir o prefixo.
					$e_resposta = 'reply' === $item->post_type;
					$destino    = $e_resposta ? (int) $item->post_parent : (int) $item->ID;

					if ( ! $destino ) {
						continue;
					}
					?>
					<li class="rc-forum-atividade__item">
						<?php echo get_avatar( $item->post_author, 32, '', '', array( 'class' => 'rc-forum-atividade__avatar' ) ); ?>
						<div class="rc-forum-atividade__texto">
							<p class="rc-forum-atividade__acao">
								<?php
								$autor = get_userdata( $item->post_author );
								echo esc_html( $autor ? $autor->display_name : __( 'Conta removida', 'reconectar' ) );
								echo ' ';
								echo esc_html( $e_resposta ? __( 'respondeu em', 'reconectar' ) : __( 'perguntou', 'reconectar' ) );
								?>
							</p>
							<p class="rc-forum-atividade__alvo">
								<a href="<?php echo esc_url( get_permalink( $destino ) ); ?>">
									<?php echo esc_html( get_the_title( $destino ) ); ?>
								</a>
							</p>
							<time class="rc-forum-atividade__data" datetime="<?php echo esc_attr( gmdate( 'c', strtotime( $item->post_date_gmt ) ) ); ?>">
								<?php
								printf(
									/* translators: %s: tempo decorrido, por exemplo "3 dias". */
									esc_html__( 'há %s', 'reconectar' ),
									esc_html( human_time_diff( strtotime( $item->post_date_gmt ) ) )
								);
								?>
							</time>
						</div>
					</li>
				<?php endforeach; ?>
			</ul>
		</section>
	</aside>
	<?php
}

/**
 * Imprime a coluna de números, tags e perguntas em alta.
 */
function reconectar_forum_sidebar_direita() {
	?>
	<aside class="rc-forum__lateral rc-forum__lateral--direita">
		<?php
		reconectar_forum_bloco_de_numeros();
		reconectar_forum_bloco_de_tags();
		reconectar_forum_bloco_em_alta();
		?>
	</aside>
	<?php
}

/**
 * Imprime os dois contadores da comunidade.
 */
function reconectar_forum_bloco_de_numeros() {
	$perguntas = reconectar_forum_total_de_perguntas();
	$membros   = reconectar_forum_total_de_membros();
	?>
	<section class="rc-forum-bloco rc-forum-bloco--numeros" aria-labelledby="rc-forum-numeros-titulo">
		<h2 class="rc-forum-bloco__titulo" id="rc-forum-numeros-titulo">
			<?php esc_html_e( 'A comunidade em números', 'reconectar' ); ?>
		</h2>

		<dl class="rc-forum-numeros">
			<div class="rc-forum-numeros__item">
				<dt><?php esc_html_e( 'Perguntas', 'reconectar' ); ?></dt>
				<dd><?php echo esc_html( number_format_i18n( $perguntas ) ); ?></dd>
			</div>
			<div class="rc-forum-numeros__item">
				<dt><?php esc_html_e( 'Participantes', 'reconectar' ); ?></dt>
				<dd><?php echo esc_html( number_format_i18n( $membros ) ); ?></dd>
			</div>
		</dl>
	</section>
	<?php
}

/**
 * Imprime as tags mais usadas.
 *
 * O "ver todas" só aparece quando há mais tags do que as exibidas, e é por isso
 * que a consulta pede uma a mais do que imprime: contar o total exigiria uma
 * segunda consulta sem limite, só para decidir se um link aparece.
 */
function reconectar_forum_bloco_de_tags() {
	$tags = reconectar_forum_tags_populares( RECONECTAR_FORUM_TAGS_NA_SIDEBAR + 1 );

	if ( ! $tags ) {
		return;
	}

	$ha_mais = count( $tags ) > RECONECTAR_FORUM_TAGS_NA_SIDEBAR;
	$tags    = array_slice( $tags, 0, RECONECTAR_FORUM_TAGS_NA_SIDEBAR );
	?>
	<section class="rc-forum-bloco" aria-labelledby="rc-forum-tags-titulo">
		<h2 class="rc-forum-bloco__titulo" id="rc-forum-tags-titulo">
			<?php esc_html_e( 'Tags mais usadas', 'reconectar' ); ?>
		</h2>

		<ul class="rc-forum-bloco__tags">
			<?php foreach ( $tags as $tag ) : ?>
				<li>
					<a class="rc-forum-tag" href="<?php echo esc_url( get_term_link( $tag ) ); ?>">
						<?php echo esc_html( $tag->name ); ?>
						<span class="rc-forum-tag__contagem" aria-hidden="true"><?php echo esc_html( number_format_i18n( $tag->count ) ); ?></span>
						<span class="screen-reader-text">
							<?php
							printf(
								/* translators: %s: quantidade de perguntas com a tag. */
								esc_html( _n( '%s pergunta', '%s perguntas', $tag->count, 'reconectar' ) ),
								esc_html( number_format_i18n( $tag->count ) )
							);
							?>
						</span>
					</a>
				</li>
			<?php endforeach; ?>
		</ul>

		<?php if ( $ha_mais ) : ?>
			<p class="rc-forum-bloco__mais">
				<a href="<?php echo esc_url( reconectar_forum_url_das_tags() ); ?>">
					<?php esc_html_e( 'Ver todas as tags', 'reconectar' ); ?>
				</a>
			</p>
		<?php endif; ?>
	</section>
	<?php
}

/**
 * URL do índice de tags do bbPress.
 *
 * `bbp_get_topic_tag_tax_slug()` porque a base da taxonomia é configurável, como
 * a raiz do fórum: um `/topic-tag/` fixo quebraria na primeira tradução.
 *
 * @return string
 */
function reconectar_forum_url_das_tags() {
	if ( ! function_exists( 'bbp_get_topic_tag_tax_slug' ) ) {
		return reconectar_forum_url_base();
	}

	return home_url( user_trailingslashit( bbp_get_topic_tag_tax_slug() ) );
}

/**
 * Imprime as perguntas em alta.
 */
function reconectar_forum_bloco_em_alta() {
	$perguntas = reconectar_forum_em_alta();

	if ( ! $perguntas ) {
		return;
	}
	?>
	<section class="rc-forum-bloco" aria-labelledby="rc-forum-alta-titulo">
		<h2 class="rc-forum-bloco__titulo" id="rc-forum-alta-titulo">
			<?php esc_html_e( 'Perguntas em alta', 'reconectar' ); ?>
		</h2>

		<ul class="rc-forum-alta">
			<?php foreach ( $perguntas as $pergunta ) : ?>
				<li class="rc-forum-alta__item">
					<a class="rc-forum-alta__titulo" href="<?php echo esc_url( get_permalink( $pergunta ) ); ?>">
						<?php echo esc_html( get_the_title( $pergunta ) ); ?>
					</a>
					<p class="rc-forum-alta__meta">
						<?php
						$respostas = (int) get_post_meta( $pergunta->ID, '_bbp_reply_count', true );

						printf(
							/* translators: %s: quantidade de respostas. */
							esc_html( _n( '%s resposta', '%s respostas', $respostas, 'reconectar' ) ),
							esc_html( number_format_i18n( $respostas ) )
						);
						?>
					</p>
				</li>
			<?php endforeach; ?>
		</ul>
	</section>
	<?php
}
