<?php
/**
 * A tela de módulos.
 *
 * Documento inteiro, e não um shortcode dentro do tema: ver o topo de
 * `class-reconectar-modulos.php`. `wp_head()` e `wp_footer()` continuam aqui —
 * sem eles não sairiam o estilo da tela nem o aviso de demonstração, que é de
 * `wp_body_open`.
 *
 * @package reconectar-core
 */

defined( 'ABSPATH' ) || exit;

$rc_usuario = wp_get_current_user();
$rc_nome    = '' !== trim( (string) $rc_usuario->first_name ) ? $rc_usuario->first_name : $rc_usuario->display_name;
?>
<!doctype html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<?php wp_head(); ?>
</head>
<body <?php body_class( 'rc-modulos-pagina' ); ?>>
<?php wp_body_open(); ?>
<a class="rc-modulos__pular" href="#rc-modulos-conteudo"><?php esc_html_e( 'Pular para o conteúdo', 'reconectar-core' ); ?></a>

<header class="rc-modulos__topo">
	<div class="rc-modulos__marca">
		<?php
		// A logo vem da theme mod, que o `provision.sh` aponta; sem ela, o nome
		// do site em texto — nunca um espaço vazio no lugar da marca.
		if ( has_custom_logo() ) {
			the_custom_logo();
		} else {
			printf( '<a class="rc-modulos__nome-do-site" href="%s">%s</a>', esc_url( home_url( '/' ) ), esc_html( get_bloginfo( 'name' ) ) );
		}
		?>
	</div>
	<a class="rc-modulos__sair" href="<?php echo esc_url( wp_logout_url( home_url( '/' ) ) ); ?>"><?php esc_html_e( 'Sair', 'reconectar-core' ); ?></a>
</header>

<main id="rc-modulos-conteudo" class="rc-modulos" tabindex="-1">
	<div class="rc-modulos__abertura">
		<h1 class="rc-modulos__titulo">
			<?php
			/* translators: %s: primeiro nome de quem entrou. */
			printf( esc_html__( 'Olá, %s. Por onde quer começar?', 'reconectar-core' ), esc_html( $rc_nome ) );
			?>
		</h1>
		<p class="rc-modulos__apoio"><?php esc_html_e( 'A plataforma é organizada em módulos. Escolha um para entrar; você pode voltar aqui pelo item “Módulos” do painel ou da sua conta.', 'reconectar-core' ); ?></p>
	</div>

	<?php
	// A quantidade vai na classe porque a grade muda com ela: três cartões cabem
	// numa linha, quatro não cabem sem espremer o título.
	$rc_cartoes = Reconectar_Modulos::cartoes();
	?>
	<ul class="rc-modulos__lista rc-modulos__lista--<?php echo (int) count( $rc_cartoes ); ?>">
		<?php foreach ( $rc_cartoes as $rc_cartao ) : ?>
			<?php $rc_id = 'rc-modulo-' . $rc_cartao['chave']; ?>
			<li class="rc-modulos__cartao rc-modulos__cartao--<?php echo esc_attr( $rc_cartao['chave'] ); ?>">
				<div class="rc-modulos__texto">
					<h2 class="rc-modulos__nome" id="<?php echo esc_attr( $rc_id ); ?>"><?php echo esc_html( $rc_cartao['titulo'] ); ?></h2>
					<p class="rc-modulos__subtitulo"><?php echo esc_html( $rc_cartao['subtitulo'] ); ?></p>
					<p class="rc-modulos__frase"><?php echo esc_html( $rc_cartao['frase'] ); ?></p>
					<?php if ( '' !== $rc_cartao['url'] ) : ?>
						<?php
						// O link é o botão e só ele: o cartão inteiro clicável
						// faria o leitor de tela anunciar título, subtítulo e frase
						// como um único nome de link. `aria-describedby` devolve o
						// nome do módulo a quem navega de link em link.
						?>
						<a class="rc-modulos__botao" href="<?php echo esc_url( $rc_cartao['url'] ); ?>" aria-describedby="<?php echo esc_attr( $rc_id ); ?>"><?php echo esc_html( $rc_cartao['botao'] ); ?></a>
					<?php else : ?>
						<p class="rc-modulos__indisponivel"><?php esc_html_e( 'Este módulo ainda não está configurado nesta instalação.', 'reconectar-core' ); ?></p>
					<?php endif; ?>
				</div>
				<?php echo Reconectar_Modulos::ilustracao( $rc_cartao['chave'] ); // phpcs:ignore WordPress.Security.EscapingOutput.OutputNotEscaped -- SVG literal da própria classe. ?>
			</li>
		<?php endforeach; ?>
	</ul>
</main>

<?php wp_footer(); ?>
</body>
</html>
