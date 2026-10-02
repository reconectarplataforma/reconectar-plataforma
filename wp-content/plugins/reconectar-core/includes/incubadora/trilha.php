<?php
/**
 * A trilha da página aberta, da âncora até ela.
 *
 * Template à parte pelo mesmo motivo de `arvore-corpo.php`: mover uma
 * ancestral muda a trilha, e a resposta de `mover` a devolve refeita.
 *
 * @package reconectar-core
 *
 * @var array $contexto
 */

defined( 'ABSPATH' ) || exit;
?>
<nav class="rc-incubadora__trilha" aria-label="<?php esc_attr_e( 'Trilha da Incubadora', 'reconectar-core' ); ?>">
	<ol>
		<?php if ( $contexto['url_raiz'] ) : ?>
			<li><a href="<?php echo esc_url( $contexto['url_raiz'] ); ?>"><?php esc_html_e( 'Incubadora', 'reconectar-core' ); ?></a></li>
		<?php endif; ?>
		<?php foreach ( $contexto['caminho'] as $rc_ancestral ) : ?>
			<li><a href="<?php echo esc_url( get_permalink( $rc_ancestral ) ); ?>"><?php echo esc_html( get_the_title( $rc_ancestral ) ); ?></a></li>
		<?php endforeach; ?>
		<li><span aria-current="page"><?php echo esc_html( get_the_title( $contexto['pagina'] ) ); ?></span></li>
	</ol>
</nav>
