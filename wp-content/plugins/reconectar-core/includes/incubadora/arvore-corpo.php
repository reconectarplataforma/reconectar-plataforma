<?php
/**
 * O corpo da árvore lateral: a lista de páginas, ou o aviso de que não há nenhuma.
 *
 * Template à parte da moldura porque é também o que a resposta de `mover`
 * devolve, já refeito — ver `Reconectar_Incubadora_Leitura::fragmentos()`. O
 * link "Incubadora" e o botão de criar ficam fora dele, em `shell.php`: não
 * mudam com a árvore, e trocá-los junto tiraria o foco de quem os estivesse
 * usando.
 *
 * @package reconectar-core
 *
 * @var array $contexto
 */

defined( 'ABSPATH' ) || exit;

if ( empty( $contexto['filhos'][0] ) ) : ?>
	<p class="rc-incubadora__arvore-vazia"><?php esc_html_e( 'Nenhuma página ainda.', 'reconectar-core' ); ?></p>
<?php else : ?>
	<?php Reconectar_Incubadora_Leitura::imprimir_ramo( 0, $contexto ); ?>
	<?php
endif;
