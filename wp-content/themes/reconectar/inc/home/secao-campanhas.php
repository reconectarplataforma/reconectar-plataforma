<?php
/**
 * Faixa de campanhas da home.
 *
 * A seção que `inc/home/secao-ofertas.php` diz ter substituído volta aqui, e com
 * a diferença que justificava a substituição resolvida: a campanha tem vigência
 * gravada, some sozinha no dia seguinte ao fim, e é conteúdo editável pelo
 * Moderador de Conteúdo — não HTML colado numa área de widget.
 *
 * Ela abre a home (prioridade 5) porque é peça de comunicação institucional:
 * mostrar a chamada da campanha depois da vitrine seria mostrá-la a quem já
 * decidiu o que veio fazer.
 *
 * @package reconectar
 */

defined( 'ABSPATH' ) || exit;

/**
 * Renderiza a faixa de campanhas vigentes.
 *
 * **Sem campanha vigente, nada é impresso** — nem título, nem moldura vazia. É a
 * regra de honestidade de dados do projeto: a home de uma plataforma sem
 * campanha no ar começa na vitrine, e não num retângulo cinza anunciando que
 * poderia haver algo ali.
 *
 * Campanha sem arte também não entra: `Reconectar_Campanha::dados()` devolve
 * `null` nesse caso, e um banner sem imagem não é banner.
 */
function reconectar_home_campanhas() {
	if ( ! class_exists( 'Reconectar_Campanha' ) ) {
		return;
	}

	$campanhas = array();

	foreach ( Reconectar_Campanha::vigentes() as $campanha ) {
		$dados = Reconectar_Campanha::dados( $campanha );

		if ( $dados ) {
			$campanhas[] = $dados;
		}
	}

	if ( ! $campanhas ) {
		return;
	}

	$varias = count( $campanhas ) > 1;
	$classe = 'rc-campanhas__faixa' . ( $varias ? ' rc-campanhas__faixa--rolavel' : '' );
	?>
	<section class="rc-campanhas" aria-label="<?php esc_attr_e( 'Campanhas', 'reconectar' ); ?>">
		<?php
		/*
		 * `tabindex="0"` só quando a faixa de fato rola: um contêiner rolável
		 * precisa ser alcançável pelo teclado (WCAG 2.1, critério 2.1.1), mas uma
		 * campanha só não rola, e uma parada de tabulação que não leva a lugar
		 * nenhum atrapalha quem navega assim.
		 */
		?>
		<ul
			class="<?php echo esc_attr( $classe ); ?>"
			<?php if ( $varias ) : ?>
				tabindex="0"
				role="group"
				aria-label="<?php esc_attr_e( 'Campanhas em cartaz', 'reconectar' ); ?>"
			<?php endif; ?>
		>
			<?php foreach ( $campanhas as $indice => $campanha ) : ?>
				<li class="rc-campanhas__item">
					<?php reconectar_campanha_banner( $campanha, 0 === $indice ); ?>
				</li>
			<?php endforeach; ?>
		</ul>
	</section>
	<?php
}
add_action( 'reconectar_home', 'reconectar_home_campanhas', 5 );

/**
 * Imprime a arte de uma campanha, com ou sem link.
 *
 * O texto alternativo vai no `<img>` e é o nome acessível do link quando há um:
 * é por isso que `Reconectar_Campanha` o preenche com o título quando o campo
 * fica em branco, em vez de aceitar vazio. Um `<a>` em volta de uma imagem sem
 * alternativa textual é um link que o leitor de tela anuncia pela URL.
 *
 * @param array $campanha Dados devolvidos por `Reconectar_Campanha::dados()`.
 * @param bool  $primeira Se é a primeira da faixa — a única que carrega ansiosa.
 * @return void
 */
function reconectar_campanha_banner( $campanha, $primeira = false ) {
	/*
	 * A primeira campanha abre a home, acima da dobra: `loading="lazy"` nela
	 * atrasaria justamente a imagem que o navegador deveria pedir primeiro. As
	 * seguintes ficam com o padrão preguiçoso.
	 */
	$imagem = wp_get_attachment_image(
		$campanha['imagem_id'],
		'full',
		false,
		array(
			'class'   => 'rc-campanha__imagem',
			'alt'     => $campanha['alt'],
			'loading' => $primeira ? 'eager' : 'lazy',
		)
	);

	if ( ! $imagem ) {
		return;
	}

	if ( '' === $campanha['link'] ) {
		echo '<div class="rc-campanha">' . $imagem . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- `wp_get_attachment_image()` já devolve HTML escapado.
		return;
	}

	printf(
		'<a class="rc-campanha" href="%s">%s</a>',
		esc_url( $campanha['link'] ),
		$imagem // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- idem.
	);
}
