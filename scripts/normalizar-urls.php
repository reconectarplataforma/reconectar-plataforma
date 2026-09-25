<?php
/**
 * Converte em caminho as URLs absolutas que o provisionamento gravou no banco.
 *
 * Executa por `wp eval-file`, a partir do `provision.sh`:
 *
 *     wp eval-file /var/www/scripts/normalizar-urls.php
 *
 * `WP_HOME` passou a ser calculada a partir do `Host` da requisição, para que a
 * mesma instalação atenda `localhost:8090` e o IP da máquina na rede local. Isso
 * resolve tudo que o WordPress monta na hora — asset, permalink, miniatura —,
 * mas não alcança o que já está **gravado como texto**: o `_menu_item_url` dos
 * itens custom do menu e o HTML dos widgets do rodapé, ambos escritos com
 * `$WP_URL` pelo provisionamento. Esses continuariam mandando o celular para
 * `localhost`, que no celular é o próprio celular.
 *
 * As duas gravações já nascem em caminho relativo depois desta entrega. Este
 * arquivo existe para a instalação que veio de antes: ela passa pelas guardas de
 * idempotência do `provision.sh` — menu já atribuído, coluna de rodapé já
 * preenchida — e por isso nunca seria reescrita.
 *
 * Idempotente: uma URL que já é caminho não casa com nada e fica como está.
 *
 * @package reconectar
 */

defined( 'ABSPATH' ) || exit;

/**
 * Expressão que reconhece o começo de uma URL absoluta do ambiente local.
 *
 * É a mesma allowlist de `RECONECTAR_HOST`, no `wp-config.php`: `localhost`, o
 * loopback e os três blocos privados. Restringir importa porque este arquivo
 * reescreve conteúdo — um link legítimo para fora sairia mutilado, virando
 * caminho de um site que não é o dele.
 *
 * O ponto vai como `[.]` pelo mesmo motivo de lá: nenhuma barra invertida no
 * caminho, nenhum escape para errar.
 */
const RECONECTAR_PADRAO_HOST_LOCAL = '#https?://(localhost|127([.][0-9]{1,3}){3}|10([.][0-9]{1,3}){3}|192[.]168([.][0-9]{1,3}){2}|172[.](1[6-9]|2[0-9]|3[01])([.][0-9]{1,3}){2})(:[0-9]{1,5})?#';

/**
 * Remove de um texto o esquema e o host das URLs locais, deixando o caminho.
 *
 * @param string $texto Texto a normalizar.
 * @return string Texto com as URLs locais reduzidas a caminho.
 */
function reconectar_normalizar_urls_locais( $texto ) {
	if ( ! is_string( $texto ) || '' === $texto ) {
		return $texto;
	}

	return preg_replace( RECONECTAR_PADRAO_HOST_LOCAL, '', $texto );
}

$itens_ajustados   = 0;
$widgets_ajustados = 0;

/*
 * Itens de menu. Só os `custom` guardam URL em meta; os `post_type` guardam o ID
 * e resolvem o permalink na hora, então já acompanham o host sozinhos.
 */
$metas = get_posts(
	array(
		'post_type'      => 'nav_menu_item',
		'post_status'    => 'any',
		'posts_per_page' => -1,
		'fields'         => 'ids',
	)
);

foreach ( $metas as $item_id ) {
	$url = get_post_meta( $item_id, '_menu_item_url', true );

	if ( ! is_string( $url ) || '' === $url ) {
		continue;
	}

	$caminho = reconectar_normalizar_urls_locais( $url );

	if ( $caminho !== $url && '' !== $caminho ) {
		update_post_meta( $item_id, '_menu_item_url', $caminho );
		++$itens_ajustados;
	}
}

/*
 * Widgets de HTML personalizado. A opção é um array serializado, o que descarta
 * `UPDATE` direto no SQL: o comprimento das strings muda com a reescrita, e um
 * serializado com comprimento errado não é lido de volta — vira widget vazio.
 * Passar por `get_option()`/`update_option()` deixa o WordPress reserializar.
 */
$widgets = get_option( 'widget_custom_html' );

if ( is_array( $widgets ) ) {
	$alterou = false;

	foreach ( $widgets as $indice => $widget ) {
		if ( ! is_array( $widget ) || ! isset( $widget['content'] ) ) {
			continue;
		}

		$conteudo = reconectar_normalizar_urls_locais( $widget['content'] );

		if ( $conteudo !== $widget['content'] ) {
			$widgets[ $indice ]['content'] = $conteudo;
			$alterou                       = true;
			++$widgets_ajustados;
		}
	}

	if ( $alterou ) {
		update_option( 'widget_custom_html', $widgets );
	}
}

/*
 * Os transients do tema guardam URL absoluta de loja, produto e miniatura. A
 * chave deles passou a incluir o host, então os antigos viraram lixo que ninguém
 * mais lê — mas seguiriam ocupando `wp_options` até expirar. Apagar aqui evita
 * que um deles seja servido por engano numa instalação que ainda não recarregou
 * o tema novo.
 */
global $wpdb;

$apagados = $wpdb->query(
	"DELETE FROM {$wpdb->options}
	 WHERE option_name LIKE '\_transient\_reconectar\_lojas\_%'
	    OR option_name LIKE '\_transient\_timeout\_reconectar\_lojas\_%'
	    OR option_name LIKE '\_transient\_reconectar\_sugestoes\_%'
	    OR option_name LIKE '\_transient\_timeout\_reconectar\_sugestoes\_%'"
);

WP_CLI::log(
	sprintf(
		'URLs normalizadas: %d item(ns) de menu, %d widget(s), %d transient(s) de catálogo descartado(s).',
		$itens_ajustados,
		$widgets_ajustados,
		(int) $apagados
	)
);
