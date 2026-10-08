<?php
/**
 * Escreve no `wp-config.php` o bloco que resolve a URL do site por requisição.
 *
 * Executa com PHP puro, a partir do `provision.sh`:
 *
 *     php /var/www/scripts/configurar-url-dinamica.php <wp-config.php> <host-padrao> [esquema-padrao]
 *
 * Existe porque `wp config set` **não serve** para estas três constantes. O
 * `wp-config-transformer`, que está por baixo do comando, não reconhece uma
 * definição cujo valor é expressão — `define( 'WP_SITEURL', 'http://' . RECONECTAR_HOST )`
 * é invisível para ele. Medido: com a linha presente no arquivo,
 * `wp config delete WP_SITEURL --type=constant` responde "is not defined". O
 * efeito colateral é que `wp config set` nunca encontra a definição anterior e
 * **acrescenta outra** a cada provisionamento, até o PHP avisar "Constant
 * already defined" — e aviso impresso antes de um redirect cancela a ação, que
 * é uma das armadilhas registradas no `CLAUDE.md`.
 *
 * O bloco vai entre marcadores, no molde do que o próprio WordPress faz com o
 * `# BEGIN WordPress` do `.htaccess`: reescrever é apagar o trecho antigo e pôr
 * o novo, sem depender de encontrar cada constante.
 *
 * Idempotente de verdade: o arquivo só é gravado quando o conteúdo muda, então
 * a segunda execução não altera nem o mtime.
 *
 * @package reconectar
 */

$caminho        = $argv[1] ?? '';
$host_padrao    = $argv[2] ?? '';
$esquema_padrao = $argv[3] ?? 'http';

if ( '' === $caminho || ! is_file( $caminho ) ) {
	fwrite( STDERR, "configurar-url-dinamica.php: wp-config.php não encontrado.\n" );
	exit( 1 );
}

if ( '' === $host_padrao ) {
	fwrite( STDERR, "configurar-url-dinamica.php: host padrão não informado.\n" );
	exit( 1 );
}

/*
 * Lista fechada, e não "o que vier antes de `://`": o valor vai parar dentro de
 * `WP_HOME`, e um `WP_URL` sem esquema faria o `provision.sh` passar o host
 * inteiro aqui — o site sairia com links `exemplo.com://exemplo.com/`.
 */
if ( ! in_array( $esquema_padrao, array( 'http', 'https' ), true ) ) {
	fwrite( STDERR, "configurar-url-dinamica.php: esquema '{$esquema_padrao}' não é http nem https. Confira o WP_URL.\n" );
	exit( 1 );
}

const RECONECTAR_MARCA_INICIO = '/* BEGIN Reconectar: URL do site por requisição */';
const RECONECTAR_MARCA_FIM    = '/* END Reconectar: URL do site por requisição */';

/*
 * A allowlist mora aqui, e não numa string montada pelo shell, porque só assim
 * ela atravessa uma camada de escape em vez de duas. O ponto continua escrito
 * como `[.]`: a expressão já foi validada contra `Host` forjado nesta forma, e
 * trocar por `\.` seria reabrir a única parte do arquivo onde um escape errado
 * não daria erro nenhum — só deixaria o filtro passar o que deveria barrar.
 *
 * `nowdoc`, e não `heredoc`: nada aqui pode ser interpolado por engano. O host
 * padrão entra depois, por `var_export()`, que escapa o que precisar.
 */
$modelo = <<<'PHP'
/* BEGIN Reconectar: URL do site por requisição */
/*
 * `wp core install --url` grava `home` e `siteurl` com um endereço fixo, e todo
 * asset, link e miniatura sai carimbado com ele. Quem abre pelo IP da máquina
 * recebe a página crua, sem CSS nenhum, porque `localhost` no celular é o
 * próprio celular. Estas constantes têm precedência sobre as opções e saem do
 * `Host` da requisição, atendendo os dois acessos ao mesmo tempo.
 *
 * Precisa ser constante aqui, e não filtro `option_siteurl` num mu-plugin:
 * `wp-settings.php` congela `WP_CONTENT_URL` e `WP_PLUGIN_URL` dentro de
 * `wp_plugin_directory_constants()`, que roda antes de incluir os mu-plugins. O
 * filtro chegaria tarde e deixaria justamente o CSS do tema e o do plugin
 * presos ao host antigo — exatamente o sintoma que se quer corrigir.
 *
 * O `Host` é conferido contra uma allowlist em vez de aceito cru. Cabeçalho de
 * requisição é dado do cliente, e esta plataforma envia link de definição de
 * senha no cadastro de loja: um `Host` forjado sairia dentro desse link. Passam
 * `localhost`, o loopback e os três blocos privados.
 *
 * O esquema segue o host. Os da allowlist são a rede local, sempre em HTTP; o
 * host padrão leva o esquema do `WP_URL`. Na produção ele é `https`, e quem
 * chega por ele chegou pelo proxy TLS (`docker/Caddyfile`): a porta do
 * WordPress fica presa ao loopback, e é isso que autoriza o `HTTPS = on`
 * abaixo. Sem ele `is_ssl()` responderia "não" atrás do proxy, e o
 * `redirect_canonical()` mandaria cada página para a versão HTTPS dela mesma,
 * que chega de novo como HTTP — laço de redirecionamento.
 *
 * O esquema não sai do `X-Forwarded-Proto`, porque no desenvolvimento a porta
 * do container é alcançável direto e o cabeçalho viria do cliente. Mas atenção:
 * o `wp-config.php` da imagem oficial do WordPress, acima deste bloco, já
 * liga `HTTPS` quando o cabeçalho diz `https`. Medido: `curl -H
 * 'X-Forwarded-Proto: https' localhost:8090` devolve a página com links
 * `https://localhost`. Na produção quem fecha isso é o Caddy, que descarta o
 * cabeçalho do cliente, somado à porta do `wordpress` presa ao loopback.
 *
 * Bloco gerado por `scripts/configurar-url-dinamica.php`. Editar à mão não
 * adianta: o provisionamento seguinte reescreve tudo entre os marcadores.
 */
define(
	'RECONECTAR_HOST',
	isset( $_SERVER['HTTP_HOST'] )
		&& preg_match(
			'#^(localhost|127([.][0-9]{1,3}){3}|10([.][0-9]{1,3}){3}|192[.]168([.][0-9]{1,3}){2}|172[.](1[6-9]|2[0-9]|3[01])([.][0-9]{1,3}){2})(:[0-9]{1,5})?$#',
			$_SERVER['HTTP_HOST']
		)
		? $_SERVER['HTTP_HOST']
		: RECONECTAR_HOST_PADRAO
);
define(
	'RECONECTAR_ESQUEMA',
	RECONECTAR_HOST === RECONECTAR_HOST_PADRAO ? RECONECTAR_ESQUEMA_PADRAO : 'http'
);
if ( 'https' === RECONECTAR_ESQUEMA && isset( $_SERVER['HTTP_HOST'] ) ) {
	$_SERVER['HTTPS'] = 'on';
}
define( 'WP_HOME', RECONECTAR_ESQUEMA . '://' . RECONECTAR_HOST );
define( 'WP_SITEURL', RECONECTAR_ESQUEMA . '://' . RECONECTAR_HOST );
/* END Reconectar: URL do site por requisição */
PHP;

/*
 * A constante do host padrão vem antes das outras no bloco final, e não é
 * interpolada dentro do modelo, para que o valor passe por `var_export()` — um
 * host com aspas quebraria o arquivo em vez de virar string.
 */
$bloco = "/* BEGIN Reconectar: URL do site por requisição */\n"
	. "define( 'RECONECTAR_HOST_PADRAO', " . var_export( $host_padrao, true ) . " );\n"
	. "define( 'RECONECTAR_ESQUEMA_PADRAO', " . var_export( $esquema_padrao, true ) . " );\n"
	. substr( $modelo, strlen( RECONECTAR_MARCA_INICIO ) + 1 );

$original = file_get_contents( $caminho );
$conteudo = $original;

/*
 * Remove o bloco anterior inteiro. O `s` faz o ponto casar quebra de linha, e o
 * `U` deixa o quantificador preguiçoso: sem ele, um arquivo com dois blocos —
 * que não deveria existir, mas já existiu — seria engolido do primeiro início
 * ao último fim, levando junto o que houvesse no meio.
 */
$conteudo = preg_replace(
	'#' . preg_quote( RECONECTAR_MARCA_INICIO, '#' ) . '.*' . preg_quote( RECONECTAR_MARCA_FIM, '#' ) . "#sU",
	'',
	$conteudo
);

/*
 * Remove as definições soltas que o `wp config set` espalhou antes desta
 * correção, uma por linha. Sem este passo a instalação que já duplicou linha
 * continuaria duplicada: o bloco novo entraria por baixo das antigas, e o aviso
 * de constante redefinida seguiria saindo.
 */
$conteudo = preg_replace(
	"#^[ \t]*define\(\s*'(RECONECTAR_HOST|RECONECTAR_HOST_PADRAO|RECONECTAR_ESQUEMA_PADRAO|WP_HOME|WP_SITEURL)'.*\n#m",
	'',
	$conteudo
);

$conteudo = preg_replace( "#\n{3,}#", "\n\n", $conteudo );

/*
 * O bloco entra antes da linha que o WordPress reserva para isso. Quando ela
 * não existe — `wp-config.php` reescrito à mão —, vai para o fim: ainda assim
 * antes de `wp-settings.php`, que é o que a precedência exige.
 */
$ancora = "/* That's all, stop editing!";
$pos    = strpos( $conteudo, $ancora );

if ( false !== $pos ) {
	$conteudo = substr( $conteudo, 0, $pos ) . $bloco . "\n\n" . substr( $conteudo, $pos );
} else {
	$conteudo = rtrim( $conteudo ) . "\n\n" . $bloco . "\n";
}

if ( $conteudo === $original ) {
	echo "URL por requisição: bloco já estava em dia (fora da rede local: {$esquema_padrao}://{$host_padrao}).\n";
	exit( 0 );
}

if ( false === file_put_contents( $caminho, $conteudo ) ) {
	fwrite( STDERR, "configurar-url-dinamica.php: falha ao gravar {$caminho}.\n" );
	exit( 1 );
}

echo "URL por requisição: bloco gravado (fora da rede local: {$esquema_padrao}://{$host_padrao}).\n";
