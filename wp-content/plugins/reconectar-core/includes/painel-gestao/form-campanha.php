<?php
/**
 * Criar ou editar uma campanha da home.
 *
 * Incluída por `tela.php`. O acesso ao item já foi decidido em
 * `Reconectar_Painel_Gestao::proteger()`.
 *
 * @package reconectar-core
 */

defined( 'ABSPATH' ) || exit;

$rc_item     = Reconectar_Painel_Gestao::item();
$rc_nova     = 'nova' === $rc_item;
$rc_campanha = $rc_nova ? null : get_post( (int) $rc_item );
$rc_pendente = Reconectar_Painel_Gestao::formulario_pendente( 'campanha-' . $rc_item );
$rc_publica  = $rc_campanha && 'publish' === $rc_campanha->post_status;
$rc_tem_arte = $rc_campanha && has_post_thumbnail( $rc_campanha );
$rc_campos   = array( 'titulo', 'link', 'inicio', 'fim', 'ordem', 'alt' );

if ( ! empty( $rc_pendente ) ) {
	$rc_valores = array();
	foreach ( $rc_campos as $rc_campo ) {
		$rc_valores[ $rc_campo ] = isset( $rc_pendente[ $rc_campo ] ) && is_scalar( $rc_pendente[ $rc_campo ] ) ? (string) $rc_pendente[ $rc_campo ] : '';
	}
} elseif ( $rc_campanha ) {
	$rc_valores = array(
		'titulo' => $rc_campanha->post_title,
		'link'   => (string) get_post_meta( $rc_campanha->ID, Reconectar_Campanha::META_LINK, true ),
		'inicio' => (string) get_post_meta( $rc_campanha->ID, Reconectar_Campanha::META_INICIO, true ),
		'fim'    => (string) get_post_meta( $rc_campanha->ID, Reconectar_Campanha::META_FIM, true ),
		'ordem'  => (string) (int) get_post_meta( $rc_campanha->ID, Reconectar_Campanha::META_ORDEM, true ),
		'alt'    => (string) get_post_meta( $rc_campanha->ID, Reconectar_Campanha::META_ALT, true ),
	);
} else {
	$rc_valores = array_fill_keys( $rc_campos, '' );

	$rc_valores['ordem'] = '0';
}

$rc_url = Reconectar_Painel_Gestao::url( Reconectar_Painel_Campanhas::AREA, $rc_item );
?>

<div class="rc-painel-empresas__topo">
	<h1 class="rc-painel-empresas__titulo">
		<?php echo esc_html( $rc_nova ? __( 'Nova campanha', 'reconectar-core' ) : __( 'Editar campanha', 'reconectar-core' ) ); ?>
	</h1>
	<?php if ( $rc_publica ) : ?>
		<a class="rc-botao rc-botao--discreto" href="<?php echo esc_url( home_url( '/' ) ); ?>">
			<?php esc_html_e( 'Ver a home', 'reconectar-core' ); ?>
		</a>
	<?php endif; ?>
</div>

<?php if ( $rc_campanha ) : ?>
	<?php $rc_situacao = Reconectar_Painel_Campanhas::situacao( $rc_campanha ); ?>
	<p class="rc-painel-empresas__nota">
		<?php esc_html_e( 'Situação:', 'reconectar-core' ); ?>
		<span class="rc-selo <?php echo $rc_situacao['ativo'] ? 'rc-selo--ativo' : 'rc-selo--inativo'; ?>">
			<?php echo esc_html( $rc_situacao['rotulo'] ); ?>
		</span>
	</p>
<?php endif; ?>

<form class="rc-formulario" method="post" enctype="multipart/form-data" action="<?php echo esc_url( $rc_url ); ?>">
	<?php Reconectar_Painel_Gestao::campos_da_acao( 'campanha_salvar', $rc_item ); ?>

	<p class="rc-formulario__campo">
		<label for="rc-campanha-titulo"><?php esc_html_e( 'Nome da campanha', 'reconectar-core' ); ?></label>
		<input type="text" id="rc-campanha-titulo" name="titulo" required maxlength="200" value="<?php echo esc_attr( $rc_valores['titulo'] ); ?>" aria-describedby="rc-campanha-titulo-ajuda">
		<span class="rc-formulario__ajuda" id="rc-campanha-titulo-ajuda">
			<?php esc_html_e( 'Para reconhecê-la nesta lista. Não aparece na home.', 'reconectar-core' ); ?>
		</span>
	</p>

	<div class="rc-formulario__campo">
		<label for="rc-campanha-arte"><?php echo esc_html( $rc_tem_arte ? __( 'Trocar a arte', 'reconectar-core' ) : __( 'Arte', 'reconectar-core' ) ); ?></label>
		<?php if ( $rc_tem_arte ) : ?>
			<?php
			// `alt` vazio: a descrição da arte está no campo logo abaixo, e lê-la
			// duas vezes seguidas não acrescenta nada a quem usa leitor de tela.
			echo get_the_post_thumbnail(
				$rc_campanha,
				'medium_large',
				array(
					'class' => 'rc-painel-gestao__arte',
					'alt'   => '',
				)
			);
			?>
		<?php endif; ?>
		<input type="file" id="rc-campanha-arte" name="<?php echo esc_attr( Reconectar_Painel_Campanhas::CAMPO_ARTE ); ?>" accept="image/jpeg,image/png,image/webp" aria-describedby="rc-campanha-arte-ajuda">
		<span class="rc-formulario__ajuda" id="rc-campanha-arte-ajuda">
			<?php
			printf(
				/* translators: %s: tamanho máximo legível, como "2 MB". */
				esc_html__( 'JPG, PNG ou WebP, até %s. A faixa da home é três vezes mais larga que alta: 1200 × 400 pixels é o tamanho ideal, e texto perto das bordas pode ser cortado no celular.', 'reconectar-core' ),
				esc_html( Reconectar_Painel_Campanhas::limite_legivel() )
			);
			?>
		</span>
	</div>

	<p class="rc-formulario__campo">
		<label for="rc-campanha-alt"><?php esc_html_e( 'Descrição da arte', 'reconectar-core' ); ?></label>
		<input type="text" id="rc-campanha-alt" name="alt" required maxlength="250" value="<?php echo esc_attr( $rc_valores['alt'] ); ?>" aria-describedby="rc-campanha-alt-ajuda">
		<span class="rc-formulario__ajuda" id="rc-campanha-alt-ajuda">
			<?php esc_html_e( 'O que a arte mostra e o texto escrito nela, para quem usa leitor de tela.', 'reconectar-core' ); ?>
		</span>
	</p>

	<p class="rc-formulario__campo">
		<label for="rc-campanha-link"><?php esc_html_e( 'Link', 'reconectar-core' ); ?></label>
		<input type="url" id="rc-campanha-link" name="link" maxlength="500" value="<?php echo esc_attr( $rc_valores['link'] ); ?>" aria-describedby="rc-campanha-link-ajuda" placeholder="https://">
		<span class="rc-formulario__ajuda" id="rc-campanha-link-ajuda">
			<?php esc_html_e( 'Para onde a arte leva quando clicada. Em branco, ela não é clicável.', 'reconectar-core' ); ?>
		</span>
	</p>

	<div class="rc-painel-gestao__datas">
		<p class="rc-formulario__campo">
			<label for="rc-campanha-inicio"><?php esc_html_e( 'Primeiro dia', 'reconectar-core' ); ?></label>
			<input type="date" id="rc-campanha-inicio" name="inicio" value="<?php echo esc_attr( $rc_valores['inicio'] ); ?>" aria-describedby="rc-campanha-inicio-ajuda">
			<span class="rc-formulario__ajuda" id="rc-campanha-inicio-ajuda">
				<?php esc_html_e( 'Em branco, aparece desde a publicação.', 'reconectar-core' ); ?>
			</span>
		</p>

		<p class="rc-formulario__campo">
			<label for="rc-campanha-fim"><?php esc_html_e( 'Último dia', 'reconectar-core' ); ?></label>
			<input type="date" id="rc-campanha-fim" name="fim" value="<?php echo esc_attr( $rc_valores['fim'] ); ?>" aria-describedby="rc-campanha-fim-ajuda">
			<span class="rc-formulario__ajuda" id="rc-campanha-fim-ajuda">
				<?php esc_html_e( 'Em branco, fica até ser despublicada.', 'reconectar-core' ); ?>
			</span>
		</p>
	</div>

	<p class="rc-formulario__campo">
		<label for="rc-campanha-ordem"><?php esc_html_e( 'Ordem', 'reconectar-core' ); ?></label>
		<input type="number" id="rc-campanha-ordem" name="ordem" step="1" value="<?php echo esc_attr( $rc_valores['ordem'] ); ?>" aria-describedby="rc-campanha-ordem-ajuda">
		<span class="rc-formulario__ajuda" id="rc-campanha-ordem-ajuda">
			<?php esc_html_e( 'Números menores aparecem primeiro na faixa.', 'reconectar-core' ); ?>
		</span>
	</p>

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
		<a class="rc-botao rc-botao--discreto" href="<?php echo esc_url( Reconectar_Painel_Gestao::url( Reconectar_Painel_Campanhas::AREA ) ); ?>">
			<?php esc_html_e( 'Voltar', 'reconectar-core' ); ?>
		</a>
	</p>
</form>

<?php if ( $rc_campanha && current_user_can( 'delete_post', $rc_campanha->ID ) ) : ?>
	<section class="rc-painel-empresas__secao" aria-labelledby="rc-campanha-lixeira">
		<h2 id="rc-campanha-lixeira"><?php esc_html_e( 'Excluir', 'reconectar-core' ); ?></h2>
		<p class="rc-painel-empresas__nota">
			<?php esc_html_e( 'A campanha sai da home e vai para a lixeira. Só o Super Administrador a restaura ou apaga de vez.', 'reconectar-core' ); ?>
		</p>
		<form method="post" action="<?php echo esc_url( $rc_url ); ?>">
			<?php Reconectar_Painel_Gestao::campos_da_acao( 'campanha_lixeira', $rc_item ); ?>
			<button type="submit" class="rc-botao rc-botao--discreto">
				<?php esc_html_e( 'Enviar para a lixeira', 'reconectar-core' ); ?>
			</button>
		</form>
	</section>
<?php endif; ?>
