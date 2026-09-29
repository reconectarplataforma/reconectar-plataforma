<?php
/**
 * Como a enquete aberta se anuncia fora do cartão flutuante.
 *
 * O cartão do plugin vive no canto inferior direito e some abaixo de 768px — ele
 * era ancorado exatamente sobre a barra inferior, e os dois anunciariam a mesma
 * enquete no mesmo canto da tela. Quem avisa no celular é o item "Votar" da
 * barra; no desktop, o ícone deste arquivo no cabeçalho. Os dois levam ao painel
 * de transparência, que é onde a cédula completa mora.
 *
 * Tudo aqui é guardado por `class_exists( 'Reconectar_Proposta_Votacao' )`: o
 * tema não pode exigir o plugin. É o mesmo padrão que `reconectar_atalho_de_conta()`
 * já usa para o painel de empresas — com o plugin desativado, o tema continua
 * inteiro e os avisos simplesmente não existem.
 *
 * @package reconectar
 */

defined( 'ABSPATH' ) || exit;

/**
 * Se há enquete aceitando voto agora.
 *
 * Memoizado porque a barra inferior e o cabeçalho perguntam na mesma
 * requisição, e nada muda entre as duas leituras.
 *
 * @return bool
 */
function reconectar_ha_enquete_aberta() {
	static $ha = null;

	if ( null !== $ha ) {
		return $ha;
	}

	if ( ! class_exists( 'Reconectar_Proposta_Votacao' ) ) {
		$ha = false;

		return $ha;
	}

	$ha = array() !== Reconectar_Proposta_Votacao::abertas( 1 );

	return $ha;
}

/**
 * Quantas enquetes abertas ainda esperam o voto de quem está olhando.
 *
 * Zero para visitante deslogado, e isso não é omissão: "pendente" é voto que
 * falta *seu*, e quem não entrou não tem voto a faltar. De quebra, o HTML
 * anônimo fica idêntico para todo mundo — imune ao dia em que um cache de página
 * entrar em cena.
 *
 * @return int
 */
function reconectar_enquetes_pendentes() {
	static $total = null;

	if ( null !== $total ) {
		return $total;
	}

	if ( ! class_exists( 'Reconectar_Proposta_Votacao' ) ) {
		$total = 0;

		return $total;
	}

	$total = count( Reconectar_Proposta_Votacao::pendentes_de() );

	return $total;
}

/**
 * Monta o nome acessível de um atalho de enquete.
 *
 * O número do selo é `aria-hidden`, e é aqui que ele volta como texto: dígito
 * solto não é nome de link, e "Votar" sozinho não diria que há pendência. É a
 * mesma regra já aplicada à unidade do carrinho.
 *
 * @param string $acao      Rótulo visível da ação ("Votar", "Enquetes").
 * @param int    $pendentes Quantas enquetes esperam o voto deste usuário.
 * @return string
 */
function reconectar_nome_do_atalho_de_enquete( $acao, $pendentes ) {
	if ( $pendentes < 1 ) {
		return $acao;
	}

	return sprintf(
		/* translators: 1: rótulo da ação, 2: número de enquetes pendentes. */
		_n(
			'%1$s, %2$d enquete esperando seu voto',
			'%1$s, %2$d enquetes esperando seu voto',
			$pendentes,
			'reconectar'
		),
		$acao,
		$pendentes
	);
}

/**
 * Imprime o selo numérico de enquetes pendentes.
 *
 * Nada sai quando não há pendência: um selo zerado ocupa o mesmo espaço de um
 * aviso e não avisa nada.
 *
 * O número é `aria-hidden` de propósito — quem lê a tela recebe a mesma
 * informação pelo nome do link, montado em
 * `reconectar_nome_do_atalho_de_enquete()`, e sem isso ele seria anunciado duas
 * vezes seguidas.
 *
 * @param string $classe    Classe CSS do selo, que difere entre barra e cabeçalho.
 * @param int    $pendentes Quantas enquetes esperam o voto deste usuário.
 */
function reconectar_selo_de_enquete( $classe, $pendentes ) {
	if ( $pendentes < 1 ) {
		return;
	}

	// Mais que dois dígitos estouraria a bolinha sobre um ícone de 22px, e a
	// diferença entre 99 e 132 enquetes abertas não muda decisão nenhuma.
	$texto = $pendentes > 99 ? '99+' : number_format_i18n( $pendentes );
	?>
	<span class="<?php echo esc_attr( $classe ); ?>" aria-hidden="true"><?php echo esc_html( $texto ); ?></span>
	<?php
}

/**
 * Imprime o aviso de enquete aberta no cabeçalho.
 *
 * Aparece para qualquer visitante enquanto houver enquete aberta; o selo
 * numérico, só para quem tem voto pendente. Deslogado vê o ícone sem número — é
 * o mesmo argumento que mantém o convite de login dentro do cartão flutuante: a
 * consulta existe e é pública, o que falta é a sessão.
 *
 * O destino é conferido antes de imprimir. `Reconectar_Painel_Transparencia::url()`
 * devolve vazio quando a página não existe publicada, e um `href=""` aponta para
 * a própria página — um link que parece funcionar e não leva a lugar nenhum.
 *
 * Abaixo de 768px o CSS esconde este elemento, porque lá o item "Votar" da barra
 * inferior tem o mesmo destino. A decisão é da folha de estilo e não daqui: a
 * largura da tela não existe no servidor.
 */
function reconectar_aviso_de_enquete() {
	if ( ! reconectar_ha_enquete_aberta() || ! class_exists( 'Reconectar_Painel_Transparencia' ) ) {
		return;
	}

	$destino = Reconectar_Painel_Transparencia::url();

	if ( '' === $destino ) {
		return;
	}

	$pendentes = reconectar_enquetes_pendentes();
	?>
	<a
		class="rc-enquete-aviso"
		href="<?php echo esc_url( $destino ); ?>"
		aria-label="<?php echo esc_attr( reconectar_nome_do_atalho_de_enquete( __( 'Enquetes abertas', 'reconectar' ), $pendentes ) ); ?>"
	>
		<span class="rc-enquete-aviso__icone" aria-hidden="true">
			<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" focusable="false">
				<path d="M5 3h14v18l-7-4-7 4z"></path>
				<path d="m9 10 2 2 4-4"></path>
			</svg>
			<?php reconectar_selo_de_enquete( 'rc-enquete-aviso__selo', $pendentes ); ?>
		</span>
		<span class="rc-enquete-aviso__rotulo"><?php esc_html_e( 'Enquetes', 'reconectar' ); ?></span>
	</a>
	<?php
}
