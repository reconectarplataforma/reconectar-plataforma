<?php
/**
 * Componentes visuais do fórum.
 *
 * Só impressão. Os dados vêm de `consultas.php` e de `Reconectar_Forum`.
 *
 * @package reconectar
 */

defined( 'ABSPATH' ) || exit;

/**
 * Imprime o card de uma pergunta na listagem.
 *
 * @param WP_Post $topico Pergunta.
 */
function reconectar_forum_card( $topico ) {
	$autor_id  = (int) $topico->post_author;
	$categoria = get_post( $topico->post_parent );
	$tags      = get_the_terms( $topico->ID, 'topic-tag' );
	$tags      = is_wp_error( $tags ) || ! $tags ? array() : $tags;
	?>
	<article class="rc-forum-card">
		<div class="rc-forum-card__corpo">
			<h3 class="rc-forum-card__titulo">
				<a href="<?php echo esc_url( get_permalink( $topico ) ); ?>">
					<?php echo esc_html( get_the_title( $topico ) ); ?>
				</a>
			</h3>

			<?php if ( $categoria && 'forum' === $categoria->post_type ) : ?>
				<p class="rc-forum-card__categoria">
					<a href="<?php echo esc_url( reconectar_forum_url( reconectar_forum_filtros_ativos(), array( 'categoria' => $categoria->ID, 'pagina' => 1 ) ) ); ?>">
						<?php echo esc_html( get_the_title( $categoria ) ); ?>
					</a>
				</p>
			<?php endif; ?>

			<p class="rc-forum-card__resumo">
				<?php echo esc_html( wp_trim_words( wp_strip_all_tags( $topico->post_content ), 28 ) ); ?>
			</p>

			<?php if ( $tags ) : ?>
				<ul class="rc-forum-card__tags">
					<?php foreach ( $tags as $tag ) : ?>
						<li>
							<a class="rc-forum-tag" href="<?php echo esc_url( get_term_link( $tag ) ); ?>">
								<?php echo esc_html( $tag->name ); ?>
							</a>
						</li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>

			<?php reconectar_forum_assinatura( $autor_id, $topico->post_date_gmt ); ?>
		</div>

		<?php reconectar_forum_contadores( $topico ); ?>
	</article>
	<?php
}

/**
 * Imprime autor, papel e data de uma pergunta ou resposta.
 *
 * @param int    $autor_id Autor.
 * @param string $data_gmt Data de publicação em GMT.
 */
function reconectar_forum_assinatura( $autor_id, $data_gmt = '' ) {
	$autor = get_userdata( $autor_id );
	?>
	<p class="rc-forum-assinatura">
		<?php echo get_avatar( $autor_id, 32, '', '', array( 'class' => 'rc-forum-assinatura__avatar' ) ); ?>
		<span class="rc-forum-assinatura__nome">
			<?php echo esc_html( $autor ? $autor->display_name : __( 'Conta removida', 'reconectar' ) ); ?>
		</span>
		<?php reconectar_forum_selo_de_papel( $autor_id ); ?>
		<?php if ( $data_gmt ) : ?>
			<time class="rc-forum-assinatura__data" datetime="<?php echo esc_attr( gmdate( 'c', strtotime( $data_gmt ) ) ); ?>">
				<?php
				printf(
					/* translators: %s: tempo decorrido, por exemplo "3 dias". */
					esc_html__( 'há %s', 'reconectar' ),
					esc_html( human_time_diff( strtotime( $data_gmt ) ) )
				);
				?>
			</time>
		<?php endif; ?>
	</p>
	<?php
}

/**
 * Imprime o selo do papel de quem escreveu.
 *
 * Os três papéis da comunidade têm selo; qualquer outro não imprime nada, em vez
 * de imprimir um selo genérico. Um selo só informa enquanto distinguir.
 *
 * @param int $autor_id Autor.
 */
function reconectar_forum_selo_de_papel( $autor_id ) {
	$usuario = get_userdata( $autor_id );

	if ( ! $usuario ) {
		return;
	}

	$selos = array(
		'administrator' => array( 'admin', __( 'Administração', 'reconectar' ) ),
		'company_admin' => array( 'empresa', __( 'Administração de empresa', 'reconectar' ) ),
		'seller'        => array( 'vendedor', __( 'Vendedor', 'reconectar' ) ),
	);

	// A ordem do array é a precedência: quem acumula papéis recebe o de maior
	// alcance, e não o primeiro que o WordPress devolver — `$usuario->roles` sai
	// na ordem em que foram atribuídos, que não significa nada.
	foreach ( $selos as $papel => $selo ) {
		if ( in_array( $papel, (array) $usuario->roles, true ) ) {
			printf(
				'<span class="rc-forum-selo rc-forum-selo--%s">%s</span>',
				esc_attr( $selo[0] ),
				esc_html( $selo[1] )
			);

			return;
		}
	}
}

/**
 * Imprime os três contadores de uma pergunta.
 *
 * Cada número leva o próprio rótulo por extenso no `aria-label`: "12" sozinho
 * não diz nada a quem navega por leitor de tela, e o ícone ao lado é decorativo.
 *
 * @param WP_Post $topico Pergunta.
 */
function reconectar_forum_contadores( $topico ) {
	$respostas = (int) get_post_meta( $topico->ID, '_bbp_reply_count', true );
	$votos     = class_exists( 'Reconectar_Forum' ) ? Reconectar_Forum::votos( $topico->ID ) : 0;
	$vistas    = class_exists( 'Reconectar_Forum' ) ? Reconectar_Forum::visualizacoes( $topico->ID ) : 0;
	$resolvida = class_exists( 'Reconectar_Forum' ) && Reconectar_Forum::melhor_resposta( $topico->ID );

	$itens = array(
		array(
			'valor'  => $votos,
			'classe' => 'rc-forum-contador--votos',
			/* translators: %s: quantidade de votos. */
			'texto'  => _n( '%s voto', '%s votos', abs( $votos ), 'reconectar' ),
		),
		array(
			/*
			 * O contador de respostas é o único que muda de cor — verde quando a
			 * pergunta tem resposta aceita. A distinção não fica só na cor: o item
			 * seguinte da lista diz "Pergunta resolvida" por extenso, para leitor
			 * de tela e para quem não distingue o verde.
			 */
			'valor'  => $respostas,
			'classe' => $resolvida
				? 'rc-forum-contador--respostas rc-forum-contador--resolvida'
				: 'rc-forum-contador--respostas',
			/* translators: %s: quantidade de respostas. */
			'texto'  => _n( '%s resposta', '%s respostas', $respostas, 'reconectar' ),
		),
		array(
			'valor'  => $vistas,
			'classe' => 'rc-forum-contador--vistas',
			/* translators: %s: quantidade de visualizações. */
			'texto'  => _n( '%s visualização', '%s visualizações', $vistas, 'reconectar' ),
		),
	);
	?>
	<ul class="rc-forum-card__contadores">
		<?php foreach ( $itens as $item ) : ?>
			<li class="rc-forum-contador <?php echo esc_attr( $item['classe'] ); ?>">
				<span class="rc-forum-contador__valor" aria-hidden="true"><?php echo esc_html( number_format_i18n( $item['valor'] ) ); ?></span>
				<span class="screen-reader-text">
					<?php
					printf(
						esc_html( $item['texto'] ),
						esc_html( number_format_i18n( $item['valor'] ) )
					);
					?>
				</span>
			</li>
		<?php endforeach; ?>
		<?php if ( $resolvida ) : ?>
			<li class="rc-forum-contador rc-forum-contador--selo">
				<span aria-hidden="true">&#10003;</span>
				<span class="screen-reader-text"><?php esc_html_e( 'Pergunta resolvida', 'reconectar' ); ?></span>
			</li>
		<?php endif; ?>
	</ul>
	<?php
}

/**
 * Imprime os botões de voto de uma pergunta ou resposta.
 *
 * Dois `<form method="post">` independentes, e não um formulário com dois
 * `<button name="sentido">`: assim cada botão tem o próprio destino e o teclado
 * chega aos dois na ordem esperada, sem JavaScript nenhum.
 *
 * Quem não pode votar vê o saldo sem os botões — informação sem controle morto.
 *
 * @param int $post_id Pergunta ou resposta.
 */
function reconectar_forum_votacao( $post_id ) {
	if ( ! class_exists( 'Reconectar_Forum' ) ) {
		return;
	}

	$saldo = Reconectar_Forum::votos( $post_id );
	$pode  = Reconectar_Forum::pode_votar( $post_id );
	$meu   = Reconectar_Forum::voto_do_usuario( $post_id );
	?>
	<div class="rc-forum-votacao">
		<?php if ( $pode ) : ?>
			<?php
			reconectar_forum_botao_de_voto(
				$post_id,
				1,
				1 === $meu,
				__( 'Votar a favor', 'reconectar' ),
				__( 'Remover meu voto a favor', 'reconectar' )
			);
			?>
		<?php endif; ?>

		<p class="rc-forum-votacao__saldo">
			<span aria-hidden="true"><?php echo esc_html( number_format_i18n( $saldo ) ); ?></span>
			<span class="screen-reader-text">
				<?php
				printf(
					/* translators: %s: saldo de votos. */
					esc_html( _n( '%s voto', '%s votos', abs( $saldo ), 'reconectar' ) ),
					esc_html( number_format_i18n( $saldo ) )
				);
				?>
			</span>
		</p>

		<?php if ( $pode ) : ?>
			<?php
			reconectar_forum_botao_de_voto(
				$post_id,
				-1,
				-1 === $meu,
				__( 'Votar contra', 'reconectar' ),
				__( 'Remover meu voto contra', 'reconectar' )
			);
			?>
		<?php endif; ?>
	</div>
	<?php
}

/**
 * Imprime um dos dois botões de voto.
 *
 * @param int    $post_id    Pergunta ou resposta.
 * @param int    $sentido    `1` ou `-1`.
 * @param bool   $e_o_meu    Se este é o voto atual do usuário.
 * @param string $rotulo     Texto de quem ainda não votou assim.
 * @param string $rotulo_meu Texto de quem já votou assim, e clicaria para desfazer.
 */
function reconectar_forum_botao_de_voto( $post_id, $sentido, $e_o_meu, $rotulo, $rotulo_meu ) {
	$classe = 1 === $sentido ? 'favor' : 'contra';
	?>
	<form class="rc-forum-votacao__form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<input type="hidden" name="action" value="<?php echo esc_attr( Reconectar_Forum::ACAO_VOTAR ); ?>" />
		<input type="hidden" name="conteudo" value="<?php echo esc_attr( $post_id ); ?>" />
		<input type="hidden" name="sentido" value="<?php echo esc_attr( $sentido ); ?>" />
		<?php wp_nonce_field( Reconectar_Forum::ACAO_VOTAR . '_' . $post_id ); ?>
		<button
			type="submit"
			class="rc-forum-voto rc-forum-voto--<?php echo esc_attr( $classe ); ?><?php echo $e_o_meu ? ' is-meu' : ''; ?>"
			<?php echo $e_o_meu ? 'aria-pressed="true"' : 'aria-pressed="false"'; ?>
		>
			<span aria-hidden="true"><?php echo 1 === $sentido ? '&#9650;' : '&#9660;'; ?></span>
			<span class="screen-reader-text"><?php echo esc_html( $e_o_meu ? $rotulo_meu : $rotulo ); ?></span>
		</button>
	</form>
	<?php
}

/**
 * Imprime o controle de melhor resposta.
 *
 * Quem pode marcar vê um botão; quem não pode vê apenas o selo, se houver. A
 * marcação não se apoia só na cor: ela imprime a palavra "Melhor resposta".
 *
 * @param int $resposta_id Resposta.
 * @param int $topico_id   Pergunta a que ela pertence.
 */
function reconectar_forum_melhor_resposta( $resposta_id, $topico_id ) {
	if ( ! class_exists( 'Reconectar_Forum' ) ) {
		return;
	}

	$aceita = Reconectar_Forum::melhor_resposta( $topico_id ) === (int) $resposta_id;

	if ( ! Reconectar_Forum::pode_marcar_melhor_resposta( $topico_id ) ) {
		if ( $aceita ) {
			printf(
				'<p class="rc-forum-aceita"><span aria-hidden="true">&#10003;</span> %s</p>',
				esc_html__( 'Melhor resposta', 'reconectar' )
			);
		}

		return;
	}
	?>
	<form class="rc-forum-aceita__form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<input type="hidden" name="action" value="<?php echo esc_attr( Reconectar_Forum::ACAO_MELHOR_RESPOSTA ); ?>" />
		<input type="hidden" name="resposta" value="<?php echo esc_attr( $resposta_id ); ?>" />
		<?php wp_nonce_field( Reconectar_Forum::ACAO_MELHOR_RESPOSTA . '_' . $resposta_id ); ?>
		<button type="submit" class="rc-forum-aceita__botao<?php echo $aceita ? ' is-aceita' : ''; ?>" aria-pressed="<?php echo $aceita ? 'true' : 'false'; ?>">
			<span aria-hidden="true">&#10003;</span>
			<?php echo esc_html( $aceita ? __( 'Melhor resposta', 'reconectar' ) : __( 'Marcar como melhor resposta', 'reconectar' ) ); ?>
		</button>
	</form>
	<?php
}

/**
 * Imprime a pergunta na sua própria página.
 *
 * @param WP_Post $topico Pergunta.
 */
function reconectar_forum_pergunta( $topico ) {
	$tags = get_the_terms( $topico->ID, 'topic-tag' );
	$tags = is_wp_error( $tags ) || ! $tags ? array() : $tags;
	?>
	<article class="rc-forum-pergunta">
		<?php reconectar_forum_votacao( $topico->ID ); ?>

		<div class="rc-forum-pergunta__corpo">
			<h1 class="rc-forum-pergunta__titulo"><?php echo esc_html( get_the_title( $topico ) ); ?></h1>

			<?php reconectar_forum_assinatura( (int) $topico->post_author, $topico->post_date_gmt ); ?>

			<div class="rc-forum-pergunta__texto">
				<?php
				/*
				 * `bbp_get_topic_content()` e não `the_content()`: o bbPress guarda o
				 * texto já filtrado pelo seu próprio sanitizador de KSES, que é mais
				 * estreito que o do post comum — e é ele que decide o que um vendedor
				 * pode publicar aqui.
				 */
				echo function_exists( 'bbp_get_topic_content' )
					? wp_kses_post( bbp_get_topic_content( $topico->ID ) )
					: wp_kses_post( wpautop( $topico->post_content ) );
				?>
			</div>

			<?php if ( $tags ) : ?>
				<ul class="rc-forum-pergunta__tags">
					<?php foreach ( $tags as $tag ) : ?>
						<li>
							<a class="rc-forum-tag" href="<?php echo esc_url( get_term_link( $tag ) ); ?>">
								<?php echo esc_html( $tag->name ); ?>
							</a>
						</li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>
		</div>
	</article>
	<?php
}

/**
 * Imprime uma resposta.
 *
 * @param WP_Post $resposta  Resposta.
 * @param int     $topico_id Pergunta a que ela pertence.
 */
function reconectar_forum_resposta( $resposta, $topico_id ) {
	$aceita = class_exists( 'Reconectar_Forum' )
		&& Reconectar_Forum::melhor_resposta( $topico_id ) === (int) $resposta->ID;
	?>
	<article class="rc-forum-resposta<?php echo $aceita ? ' is-aceita' : ''; ?>" id="post-<?php echo esc_attr( $resposta->ID ); ?>">
		<?php reconectar_forum_votacao( $resposta->ID ); ?>

		<div class="rc-forum-resposta__corpo">
			<?php reconectar_forum_assinatura( (int) $resposta->post_author, $resposta->post_date_gmt ); ?>

			<div class="rc-forum-resposta__texto">
				<?php
				echo function_exists( 'bbp_get_reply_content' )
					? wp_kses_post( bbp_get_reply_content( $resposta->ID ) )
					: wp_kses_post( wpautop( $resposta->post_content ) );
				?>
			</div>

			<?php reconectar_forum_melhor_resposta( $resposta->ID, $topico_id ); ?>
		</div>
	</article>
	<?php
}
