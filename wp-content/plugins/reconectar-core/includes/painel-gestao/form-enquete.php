<?php
/**
 * Criar ou editar uma enquete, com os resultados dela.
 *
 * Incluída por `tela.php`. O acesso ao item já foi decidido em
 * `Reconectar_Painel_Gestao::proteger()`.
 *
 * @package reconectar-core
 */

defined( 'ABSPATH' ) || exit;

$rc_item     = Reconectar_Painel_Gestao::item();
$rc_nova     = 'nova' === $rc_item;
$rc_enquete  = $rc_nova ? null : get_post( (int) $rc_item );
$rc_pendente = Reconectar_Painel_Gestao::formulario_pendente( 'enquete-' . $rc_item );
$rc_publica  = $rc_enquete && 'publish' === $rc_enquete->post_status;

// O formulário recusado volta com o que foi digitado: refazer tudo por causa de
// uma data trocada faria quem escreve desistir da enquete.
if ( ! empty( $rc_pendente ) ) {
	$rc_valores = array(
		'titulo' => isset( $rc_pendente['titulo'] ) ? (string) $rc_pendente['titulo'] : '',
		'texto'  => isset( $rc_pendente['texto'] ) ? (string) $rc_pendente['texto'] : '',
		'resumo' => isset( $rc_pendente['resumo'] ) ? (string) $rc_pendente['resumo'] : '',
		'inicio' => isset( $rc_pendente['inicio'] ) ? (string) $rc_pendente['inicio'] : '',
		'fim'    => isset( $rc_pendente['fim'] ) ? (string) $rc_pendente['fim'] : '',
		'opcoes' => array(),
	);

	if ( isset( $rc_pendente['opcoes'] ) && is_array( $rc_pendente['opcoes'] ) ) {
		foreach ( $rc_pendente['opcoes'] as $rc_linha ) {
			if ( is_array( $rc_linha ) ) {
				$rc_valores['opcoes'][] = array(
					'id'    => isset( $rc_linha['id'] ) ? (string) $rc_linha['id'] : '',
					'texto' => isset( $rc_linha['texto'] ) ? (string) $rc_linha['texto'] : '',
				);
			}
		}
	}
} elseif ( $rc_enquete ) {
	$rc_valores = array(
		'titulo' => $rc_enquete->post_title,
		'texto'  => $rc_enquete->post_content,
		'resumo' => $rc_enquete->post_excerpt,
		'inicio' => (string) get_post_meta( $rc_enquete->ID, Reconectar_Proposta_Votacao::META_INICIO, true ),
		'fim'    => (string) get_post_meta( $rc_enquete->ID, Reconectar_Proposta_Votacao::META_FIM, true ),
		'opcoes' => Reconectar_Proposta_Votacao::opcoes( $rc_enquete->ID ),
	);
} else {
	$rc_valores = array(
		'titulo' => '',
		'texto'  => '',
		'resumo' => '',
		'inicio' => '',
		'fim'    => '',
		'opcoes' => array(),
	);
}

// Duas linhas vazias no mínimo: é o que a publicação exige, e a tela já nasce
// pronta para isso em vez de pedir dois cliques antes de começar.
while ( count( $rc_valores['opcoes'] ) < Reconectar_Painel_Enquetes::MINIMO_PARA_PUBLICAR ) {
	$rc_valores['opcoes'][] = array(
		'id'    => '',
		'texto' => '',
	);
}

$rc_limite = 10;
?>

<div class="rc-painel-empresas__topo">
	<h1 class="rc-painel-empresas__titulo">
		<?php echo esc_html( $rc_nova ? __( 'Nova enquete', 'reconectar-core' ) : __( 'Editar enquete', 'reconectar-core' ) ); ?>
	</h1>
	<?php if ( $rc_publica ) : ?>
		<a class="rc-botao rc-botao--discreto" href="<?php echo esc_url( get_permalink( $rc_enquete ) ); ?>">
			<?php esc_html_e( 'Ver no site', 'reconectar-core' ); ?>
		</a>
	<?php endif; ?>
</div>

<form class="rc-formulario" method="post" action="<?php echo esc_url( Reconectar_Painel_Gestao::url( Reconectar_Painel_Enquetes::AREA, $rc_item ) ); ?>">
	<?php Reconectar_Painel_Gestao::campos_da_acao( 'enquete_salvar', $rc_item ); ?>

	<p class="rc-formulario__campo">
		<label for="rc-enquete-titulo"><?php esc_html_e( 'Pergunta', 'reconectar-core' ); ?></label>
		<input type="text" id="rc-enquete-titulo" name="titulo" required maxlength="200" value="<?php echo esc_attr( $rc_valores['titulo'] ); ?>">
	</p>

	<p class="rc-formulario__campo">
		<label for="rc-enquete-texto"><?php esc_html_e( 'Contexto', 'reconectar-core' ); ?></label>
		<textarea id="rc-enquete-texto" name="texto" rows="6" aria-describedby="rc-enquete-texto-ajuda"><?php echo esc_textarea( $rc_valores['texto'] ); ?></textarea>
		<span class="rc-formulario__ajuda" id="rc-enquete-texto-ajuda">
			<?php esc_html_e( 'O que quem vota precisa saber antes de escolher. Aparece na página da enquete.', 'reconectar-core' ); ?>
		</span>
	</p>

	<p class="rc-formulario__campo">
		<label for="rc-enquete-resumo"><?php esc_html_e( 'Resumo', 'reconectar-core' ); ?></label>
		<textarea id="rc-enquete-resumo" name="resumo" rows="2" maxlength="300" aria-describedby="rc-enquete-resumo-ajuda"><?php echo esc_textarea( $rc_valores['resumo'] ); ?></textarea>
		<span class="rc-formulario__ajuda" id="rc-enquete-resumo-ajuda">
			<?php esc_html_e( 'Uma ou duas frases, para o cartão da enquete na Transparência.', 'reconectar-core' ); ?>
		</span>
	</p>

	<div class="rc-painel-gestao__datas">
		<p class="rc-formulario__campo">
			<label for="rc-enquete-inicio"><?php esc_html_e( 'Primeiro dia de votação', 'reconectar-core' ); ?></label>
			<input type="date" id="rc-enquete-inicio" name="inicio" value="<?php echo esc_attr( $rc_valores['inicio'] ); ?>" aria-describedby="rc-enquete-inicio-ajuda">
			<span class="rc-formulario__ajuda" id="rc-enquete-inicio-ajuda">
				<?php esc_html_e( 'Em branco, a votação abre na publicação.', 'reconectar-core' ); ?>
			</span>
		</p>

		<p class="rc-formulario__campo">
			<label for="rc-enquete-fim"><?php esc_html_e( 'Último dia de votação', 'reconectar-core' ); ?></label>
			<input type="date" id="rc-enquete-fim" name="fim" value="<?php echo esc_attr( $rc_valores['fim'] ); ?>" aria-describedby="rc-enquete-fim-ajuda">
			<span class="rc-formulario__ajuda" id="rc-enquete-fim-ajuda">
				<?php esc_html_e( 'Em branco, a votação fica aberta até alguém encerrá-la.', 'reconectar-core' ); ?>
			</span>
		</p>
	</div>

	<fieldset class="rc-painel-gestao__alternativas" data-rc-repetidor data-rc-minimo="<?php echo esc_attr( Reconectar_Painel_Enquetes::MINIMO_PARA_PUBLICAR ); ?>" data-rc-limite="<?php echo esc_attr( $rc_limite ); ?>">
		<legend><?php esc_html_e( 'Alternativas', 'reconectar-core' ); ?></legend>

		<p class="rc-formulario__ajuda">
			<?php
			printf(
				/* translators: 1: mínimo para publicar. 2: máximo. */
				esc_html__( 'De %1$d a %2$d. Remover uma alternativa que já recebeu votos apaga esses votos.', 'reconectar-core' ),
				(int) Reconectar_Painel_Enquetes::MINIMO_PARA_PUBLICAR,
				(int) $rc_limite
			);
			?>
		</p>

		<ol class="rc-painel-gestao__lista-alternativas" data-rc-repetidor-lista>
			<?php foreach ( array_slice( $rc_valores['opcoes'], 0, $rc_limite ) as $rc_i => $rc_opcao ) : ?>
				<li class="rc-painel-gestao__alternativa" data-rc-repetidor-linha>
					<input type="hidden" name="opcoes[<?php echo (int) $rc_i; ?>][id]" value="<?php echo esc_attr( $rc_opcao['id'] ); ?>" data-rc-campo="id">
					<label for="rc-enquete-opcao-<?php echo (int) $rc_i; ?>" data-rc-rotulo>
						<?php
						/* translators: %d: número da alternativa. */
						echo esc_html( sprintf( __( 'Alternativa %d', 'reconectar-core' ), $rc_i + 1 ) );
						?>
					</label>
					<input type="text" id="rc-enquete-opcao-<?php echo (int) $rc_i; ?>" name="opcoes[<?php echo (int) $rc_i; ?>][texto]" maxlength="200" value="<?php echo esc_attr( $rc_opcao['texto'] ); ?>" data-rc-campo="texto">
					<button type="button" class="rc-botao rc-botao--discreto" data-rc-remover>
						<?php esc_html_e( 'Remover', 'reconectar-core' ); ?>
					</button>
				</li>
			<?php endforeach; ?>
		</ol>

		<button type="button" class="rc-botao rc-botao--discreto" data-rc-adicionar>
			<?php esc_html_e( 'Adicionar alternativa', 'reconectar-core' ); ?>
		</button>
		<p class="rc-painel-gestao__anuncio" role="status" data-rc-anuncio></p>
	</fieldset>

	<p class="rc-formulario__acoes">
		<?php if ( $rc_publica ) : ?>
			<button type="submit" class="rc-botao rc-botao--primario" name="estado" value="publish">
				<?php esc_html_e( 'Salvar', 'reconectar-core' ); ?>
			</button>
			<button type="submit" class="rc-botao rc-botao--discreto" name="estado" value="draft">
				<?php esc_html_e( 'Despublicar', 'reconectar-core' ); ?>
			</button>
		<?php else : ?>
			<button type="submit" class="rc-botao rc-botao--primario" name="estado" value="publish">
				<?php esc_html_e( 'Publicar', 'reconectar-core' ); ?>
			</button>
			<button type="submit" class="rc-botao rc-botao--discreto" name="estado" value="draft">
				<?php esc_html_e( 'Salvar rascunho', 'reconectar-core' ); ?>
			</button>
		<?php endif; ?>
		<a class="rc-botao rc-botao--discreto" href="<?php echo esc_url( Reconectar_Painel_Gestao::url( Reconectar_Painel_Enquetes::AREA ) ); ?>">
			<?php esc_html_e( 'Voltar', 'reconectar-core' ); ?>
		</a>
	</p>
</form>

<?php if ( $rc_enquete ) : ?>

	<?php
	$rc_contagem = Reconectar_Proposta_Votacao::contagem( $rc_enquete->ID );
	$rc_total    = (int) array_sum( $rc_contagem );
	?>

	<section class="rc-painel-empresas__secao" aria-labelledby="rc-enquete-resultados">
		<h2 id="rc-enquete-resultados"><?php esc_html_e( 'Resultados', 'reconectar-core' ); ?></h2>

		<?php if ( 0 === $rc_total ) : ?>
			<p class="rc-painel-empresas__vazio"><?php esc_html_e( 'Nenhum voto até agora.', 'reconectar-core' ); ?></p>
		<?php else : ?>
			<p class="rc-painel-empresas__nota">
				<?php
				printf(
					/* translators: %s: total de votos. */
					esc_html( _n( '%s voto. Quem votou não é identificado aqui.', '%s votos. Quem votou não é identificado aqui.', $rc_total, 'reconectar-core' ) ),
					esc_html( number_format_i18n( $rc_total ) )
				);
				?>
			</p>
			<ul class="rc-painel-gestao__resultados">
				<?php foreach ( Reconectar_Proposta_Votacao::opcoes( $rc_enquete->ID ) as $rc_opcao ) : ?>
					<?php
					$rc_votos      = isset( $rc_contagem[ $rc_opcao['id'] ] ) ? (int) $rc_contagem[ $rc_opcao['id'] ] : 0;
					$rc_percentual = Reconectar_Proposta_Votacao::percentual( $rc_votos, $rc_total );
					?>
					<li class="rc-painel-gestao__resultado">
						<span class="rc-painel-gestao__resultado-texto"><?php echo esc_html( $rc_opcao['texto'] ); ?></span>
						<span class="rc-painel-gestao__resultado-numero">
							<?php
							printf(
								/* translators: 1: votos. 2: percentual. */
								esc_html__( '%1$s (%2$s%%)', 'reconectar-core' ),
								esc_html( number_format_i18n( $rc_votos ) ),
								esc_html( number_format_i18n( $rc_percentual, 0 ) )
							);
							?>
						</span>
						<span class="rc-painel-gestao__barra" aria-hidden="true">
							<span style="width: <?php echo esc_attr( number_format( $rc_percentual, 2, '.', '' ) ); ?>%"></span>
						</span>
					</li>
				<?php endforeach; ?>
			</ul>
		<?php endif; ?>
	</section>

	<?php if ( current_user_can( 'delete_post', $rc_enquete->ID ) ) : ?>
		<section class="rc-painel-empresas__secao" aria-labelledby="rc-enquete-lixeira">
			<h2 id="rc-enquete-lixeira"><?php esc_html_e( 'Excluir', 'reconectar-core' ); ?></h2>
			<p class="rc-painel-empresas__nota">
				<?php esc_html_e( 'A enquete sai do site e vai para a lixeira, com os votos. Só o Super Administrador a restaura ou apaga de vez.', 'reconectar-core' ); ?>
			</p>
			<form method="post" action="<?php echo esc_url( Reconectar_Painel_Gestao::url( Reconectar_Painel_Enquetes::AREA, $rc_item ) ); ?>">
				<?php Reconectar_Painel_Gestao::campos_da_acao( 'enquete_lixeira', $rc_item ); ?>
				<button type="submit" class="rc-botao rc-botao--discreto">
					<?php esc_html_e( 'Enviar para a lixeira', 'reconectar-core' ); ?>
				</button>
			</form>
		</section>
	<?php endif; ?>

<?php endif; ?>
