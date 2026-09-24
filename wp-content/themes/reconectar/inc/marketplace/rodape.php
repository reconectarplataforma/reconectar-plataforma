<?php
/**
 * Rodapé do marketplace.
 *
 * As colunas são áreas de widget **próprias do tema**, e não as `footer-1`..`4`
 * do Storefront. O motivo é o mesmo que levou a home a ter uma action autoral:
 * áreas registradas pelo tema pai desaparecem junto com ele, e com elas o
 * conteúdo que o administrador tiver cadastrado. Registradas aqui, sobrevivem à
 * troca do pai.
 *
 * @package reconectar
 */

defined( 'ABSPATH' ) || exit;

/**
 * Registra as três colunas de widget do rodapé.
 */
function reconectar_registrar_rodape() {
	for ( $coluna = 1; $coluna <= 3; $coluna++ ) {
		register_sidebar(
			array(
				'id'            => 'reconectar-rodape-' . $coluna,
				'name'          => sprintf(
					/* translators: %d: número da coluna do rodapé. */
					__( 'Rodapé — coluna %d', 'reconectar' ),
					$coluna
				),
				'description'   => __( 'Links e informações exibidos no rodapé do site.', 'reconectar' ),
				'before_widget' => '<div id="%1$s" class="rc-rodape__bloco %2$s">',
				'after_widget'  => '</div>',
				'before_title'  => '<h2 class="rc-rodape__titulo">',
				'after_title'   => '</h2>',
			)
		);
	}
}
add_action( 'widgets_init', 'reconectar_registrar_rodape' );

/**
 * Imprime as colunas do rodapé.
 *
 * Coluna vazia não é impressa: uma `<div>` sem conteúdo ainda ocupa espaço na
 * grade e deixaria um buraco no layout enquanto o administrador não preencher.
 */
function reconectar_rodape_colunas() {
	$ativas = array();

	for ( $coluna = 1; $coluna <= 3; $coluna++ ) {
		if ( is_active_sidebar( 'reconectar-rodape-' . $coluna ) ) {
			$ativas[] = 'reconectar-rodape-' . $coluna;
		}
	}

	if ( ! $ativas ) {
		return;
	}
	?>
	<div class="rc-rodape__colunas">
		<?php foreach ( $ativas as $sidebar ) : ?>
			<div class="rc-rodape__coluna">
				<?php dynamic_sidebar( $sidebar ); ?>
			</div>
		<?php endforeach; ?>
	</div>
	<?php
}

/**
 * Imprime a linha legal do rodapé.
 *
 * O CNPJ e a razão social não estão escritos no código: quem opera a plataforma
 * pode não ser quem a desenvolveu, e um identificador fiscal chumbado em um
 * template é o tipo de dado que ninguém lembra de trocar. Vêm das opções
 * `reconectar_razao_social` e `reconectar_cnpj`; quando não estão preenchidas, a
 * linha simplesmente sai sem elas, em vez de exibir um rótulo vazio.
 */
function reconectar_rodape_legal() {
	$razao = get_option( 'reconectar_razao_social', '' );
	$cnpj  = get_option( 'reconectar_cnpj', '' );
	?>
	<div class="rc-rodape__legal">
		<p class="rc-rodape__copyright">
			<?php
			printf(
				/* translators: 1: ano corrente; 2: nome do site. */
				esc_html__( '© %1$s %2$s. Todos os direitos reservados.', 'reconectar' ),
				esc_html( gmdate( 'Y' ) ),
				esc_html( get_bloginfo( 'name' ) )
			);
			?>
		</p>

		<?php if ( $razao || $cnpj ) : ?>
			<p class="rc-rodape__identificacao">
				<?php if ( $razao ) : ?>
					<span class="rc-rodape__razao"><?php echo esc_html( $razao ); ?></span>
				<?php endif; ?>

				<?php if ( $cnpj ) : ?>
					<span class="rc-rodape__cnpj">
						<?php
						printf(
							/* translators: %s: número do CNPJ. */
							esc_html__( 'CNPJ %s', 'reconectar' ),
							esc_html( $cnpj )
						);
						?>
					</span>
				<?php endif; ?>
			</p>
		<?php endif; ?>
	</div>
	<?php
}
