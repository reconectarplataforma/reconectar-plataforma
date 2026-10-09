<?php
/**
 * Envio e aparência dos e-mails da plataforma.
 *
 * @package reconectar-core
 */

defined( 'ABSPATH' ) || exit;

/**
 * SMTP por variável de ambiente e a identidade visual do Reconectar em todo
 * e-mail que sai da instalação.
 *
 * As cores, a fonte, o alinhamento e o texto do rodapé são **opções** do
 * WooCommerce, gravadas pelo `provision.sh` e editáveis em WooCommerce →
 * Configurações → E-mails. Aqui fica só o que uma opção não consegue guardar:
 *
 *   - a credencial do SMTP, que não pode morar no banco nem no Git;
 *   - a URL da logo, que precisa sair do host da requisição — gravada na
 *     opção, levaria `localhost:8090` para a caixa de quem recebe;
 *   - o link da política de privacidade no rodapé, pelo mesmo motivo;
 *   - o embrulho dos e-mails de texto puro do núcleo e do BuddyPress, que
 *     não passam pelo mailer do WooCommerce.
 */
class Reconectar_Email {

	/**
	 * Logo do cabeçalho, relativa ao tema.
	 *
	 * Versão reduzida e recortada da `logo-apoio-cor.png`, que tem 6250px e
	 * 200 KB. PNG, não SVG: o Gmail não exibe SVG em e-mail.
	 */
	const LOGO = 'assets/img/logo-email.png';

	/**
	 * Registra os ganchos.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'phpmailer_init', array( __CLASS__, 'configurar_smtp' ) );
		add_filter( 'wp_mail_from', array( __CLASS__, 'remetente' ) );
		add_filter( 'wp_mail_from_name', array( __CLASS__, 'nome_do_remetente' ) );
		add_filter( 'woocommerce_email_from_address', array( __CLASS__, 'remetente' ) );
		add_filter( 'woocommerce_email_from_name', array( __CLASS__, 'nome_do_remetente' ) );

		add_filter( 'default_option_woocommerce_email_header_image', array( __CLASS__, 'logo' ) );
		add_filter( 'option_woocommerce_email_header_image', array( __CLASS__, 'logo' ) );
		add_filter( 'woocommerce_email_footer_text', array( __CLASS__, 'rodape' ) );
		add_filter( 'woocommerce_email_styles', array( __CLASS__, 'estilos' ), 20 );

		add_filter( 'wp_mail', array( __CLASS__, 'embrulhar_texto_puro' ), 20 );

		// O BuddyPress tem template HTML e mailer próprios, com outra cara e
		// sem passar pelo `phpmailer_init`. Pelo `wp_mail` ele manda a versão
		// em texto, que o embrulho acima veste como os demais.
		add_filter( 'bp_email_use_wp_mail', '__return_true' );
	}

	/**
	 * Lê uma variável de ambiente do SMTP.
	 *
	 * Vem do `.env` pelo `environment` do serviço `wordpress` no
	 * `docker-compose.yml`. Fora do container, uma constante de mesmo nome no
	 * `wp-config.php` serve igual.
	 *
	 * @param string $nome Sufixo depois de `RECONECTAR_SMTP_`.
	 * @return string
	 */
	private static function variavel( $nome ) {
		$chave = 'RECONECTAR_SMTP_' . $nome;

		if ( defined( $chave ) ) {
			return trim( (string) constant( $chave ) );
		}

		$valor = getenv( $chave );

		return false === $valor ? '' : trim( $valor );
	}

	/**
	 * Diz se há SMTP configurado.
	 *
	 * Sem host, nada muda: o WordPress segue no `mail()` do PHP, que no
	 * container não entrega — é o caso do ambiente de desenvolvimento, e o
	 * motivo de o painel de empresas exibir o link de senha em vez de
	 * prometer um e-mail.
	 *
	 * @return bool
	 */
	public static function smtp_configurado() {
		return '' !== self::variavel( 'HOST' );
	}

	/**
	 * Aponta o PHPMailer para o servidor SMTP.
	 *
	 * @param PHPMailer\PHPMailer\PHPMailer $mailer Instância do envio corrente.
	 * @return void
	 */
	public static function configurar_smtp( $mailer ) {
		if ( ! self::smtp_configurado() ) {
			return;
		}

		$porta = (int) self::variavel( 'PORTA' );
		$porta = $porta > 0 ? $porta : 587;

		$mailer->isSMTP();
		$mailer->Host     = self::variavel( 'HOST' );
		$mailer->Port     = $porta;
		$mailer->SMTPAuth = '' !== self::variavel( 'USUARIO' );
		$mailer->Username = self::variavel( 'USUARIO' );
		$mailer->Password = self::variavel( 'SENHA' );

		// A segurança sai da porta, não de uma variável a mais que pudesse
		// divergir dela: 465 fala TLS desde o primeiro byte, 587 começa em
		// texto e sobe com STARTTLS. Trocadas, a conexão fica pendurada até o
		// tempo esgotar, sem mensagem que aponte a causa.
		$mailer->SMTPSecure = 465 === $porta ? 'ssl' : 'tls';
		$mailer->Timeout    = 15;

		// O Gmail reescreve o From para a conta autenticada; o Sender igual
		// a ela evita que o retorno de erro vá para um endereço que não existe.
		$mailer->Sender = self::remetente( $mailer->From );
	}

	/**
	 * Endereço do remetente.
	 *
	 * Só troca quando há SMTP: sem ele, o padrão do núcleo e a opção do
	 * WooCommerce continuam valendo, e nada no desenvolvimento muda de cara.
	 *
	 * @param string $atual Endereço que o núcleo ou o WooCommerce escolheu.
	 * @return string
	 */
	public static function remetente( $atual ) {
		if ( ! self::smtp_configurado() ) {
			return $atual;
		}

		$remetente = self::variavel( 'REMETENTE' );
		$remetente = '' !== $remetente ? $remetente : self::variavel( 'USUARIO' );

		return is_email( $remetente ) ? $remetente : $atual;
	}

	/**
	 * Nome do remetente.
	 *
	 * O padrão do núcleo é "WordPress", que na caixa de entrada parece spam.
	 *
	 * @param string $atual Nome que o núcleo ou o WooCommerce escolheu.
	 * @return string
	 */
	public static function nome_do_remetente( $atual ) {
		$nome = self::variavel( 'NOME' );

		if ( '' !== $nome ) {
			return $nome;
		}

		return 'WordPress' === $atual ? wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) : $atual;
	}

	/**
	 * URL da logo do cabeçalho, quando a opção está vazia.
	 *
	 * Resolvida a cada envio pelo `WP_HOME` dinâmico. Uma imagem escolhida
	 * pelo administrador em Configurações → E-mails continua valendo.
	 *
	 * @param mixed $valor Valor gravado na opção.
	 * @return mixed
	 */
	public static function logo( $valor ) {
		if ( ! empty( $valor ) || ! file_exists( get_theme_file_path( self::LOGO ) ) ) {
			return $valor;
		}

		return get_theme_file_uri( self::LOGO );
	}

	/**
	 * Acrescenta ao rodapé o link da política de privacidade.
	 *
	 * Pela LGPD, quem recebe precisa achar a política a partir do e-mail. O
	 * link vem do núcleo (`wp_page_for_privacy_policy`), que devolve vazio
	 * enquanto a página não estiver publicada — e aí o rodapé sai sem ele.
	 *
	 * @param string $texto Rodapé com os marcadores já substituídos.
	 * @return string
	 */
	public static function rodape( $texto ) {
		$url = get_privacy_policy_url();

		if ( '' === $url || false !== strpos( $texto, $url ) ) {
			return $texto;
		}

		return $texto . '<br /><a href="' . esc_url( $url ) . '">' . esc_html__( 'Política de privacidade', 'reconectar-core' ) . '</a>';
	}

	/**
	 * Acentos da marca sobre o CSS do WooCommerce.
	 *
	 * A cor base é a institucional (`#663191`), e não o teal primário, por
	 * contraste: o WooCommerce escolhe entre texto branco e escuro pelo
	 * brilho da base, e o `#31BEB1` cai do lado do branco — 2,3:1, reprovado
	 * pela WCAG. O teal entra só onde não carrega texto: a faixa do topo e os
	 * divisores. O CSS é embutido nos elementos pelo Emogrifier do WooCommerce,
	 * então seletor por id funciona mesmo no Gmail.
	 *
	 * @param string $css CSS montado pelo WooCommerce.
	 * @return string
	 */
	public static function estilos( $css ) {
		$css .= '
			#template_container { border-top: 6px solid #31BEB1 !important; }
			#template_header_image { padding-bottom: 8px; }
			#template_header_image img { max-width: 240px; height: auto; }
			h1 { color: #663191 !important; }
			h2, h3 { color: #663191; }
			a, .link { color: #663191; }
			.hr, #template_footer { border-color: #31BEB1 !important; }
			#template_footer #credit a { color: #4d4d57; }
			.order-totals-total th, .order-totals-total td { color: #663191; }
			a.rc-email-url { word-break: break-all; }
		';

		return $css;
	}

	/**
	 * Veste com o template do WooCommerce os e-mails que saem em texto puro.
	 *
	 * Redefinição de senha, conta nova, troca de e-mail, avisos do fórum: o
	 * núcleo, o bbPress e o BuddyPress mandam texto cru, e eram os únicos
	 * e-mails da plataforma sem a marca. Só entra quem não declarou tipo de
	 * conteúdo e não traz HTML — o WooCommerce e o Dokan já mandam o deles
	 * montado, e embrulhar de novo os aninharia.
	 *
	 * @param array $email Argumentos do `wp_mail()`: to, subject, message, headers, attachments.
	 * @return array
	 */
	public static function embrulhar_texto_puro( $email ) {
		if ( ! function_exists( 'WC' ) || empty( $email['message'] ) ) {
			return $email;
		}

		$cabecalhos = is_array( $email['headers'] ) ? implode( "\n", $email['headers'] ) : (string) $email['headers'];

		if ( false !== stripos( $cabecalhos, 'content-type' ) || preg_match( '/<(html|body|table|p|div|br)[\s>\/]/i', $email['message'] ) ) {
			return $email;
		}

		$texto = str_replace( array( "\r\n", "\r" ), "\n", $email['message'] );

		// O núcleo cerca o link de redefinição com `<…>`, herança do texto puro.
		// Escapado, o par viraria `&lt;…&gt;` visível ao redor do link.
		$texto = preg_replace( '/<(https?:\/\/[^\s>]+)>/', '$1', $texto );
		$html  = wpautop( make_clickable( esc_html( $texto ) ) );

		// O texto do link é a própria URL, com a chave de redefinição: sem
		// espaço onde quebrar, ela vazava do cartão de 600px. Medido no link
		// de senha do núcleo. Classe, e não `style` no atributo: o Emogrifier
		// reescreve o atributo com o que calculou e a regra se perdia — medido.
		$html = str_replace( '<a href=', '<a class="rc-email-url" href=', $html );

		// O assunto do núcleo começa por "[Nome do site]", que no título do
		// e-mail repetiria a marca logo abaixo da logo.
		$titulo = trim( preg_replace( '/^\[[^\]]*\]\s*/', '', wp_specialchars_decode( $email['subject'], ENT_QUOTES ) ) );

		// `wrap_message()` devolve o template com o CSS num `<style>`, que o
		// Gmail descarta; `style_inline()` o embute nos elementos, como o
		// WooCommerce faz nos e-mails dele.
		$email['message'] = ( new WC_Email() )->style_inline( WC()->mailer()->wrap_message( $titulo, $html ) );

		$cabecalho_html = 'Content-Type: text/html; charset=UTF-8';
		if ( is_array( $email['headers'] ) ) {
			$email['headers'][] = $cabecalho_html;
		} else {
			$email['headers'] = trim( $cabecalhos . "\n" . $cabecalho_html );
		}

		return $email;
	}
}
