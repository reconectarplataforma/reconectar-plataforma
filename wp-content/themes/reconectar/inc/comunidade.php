<?php
/**
 * Comunidade: as peças de rede social em volta do BuddyPress.
 *
 * Os templates em `buddypress/` (overrides do pacote Nouveau) desenham o
 * esqueleto — feed em três colunas, perfil com capa e coluna "Sobre" — e chamam
 * as funções deste arquivo para o que não existe no BuddyPress: o cartão de
 * quem está logado, os atalhos, os membros ativos e os dados do "Sobre".
 *
 * Tudo aqui é leitura. Nenhuma função liga componente, grava meta ou decide
 * acesso: quem entra na Comunidade é `Reconectar_Permissoes::bloquear_comunidade()`,
 * no plugin. Por isso cada função confere o BuddyPress com `function_exists()`
 * e desiste em silêncio: o tema não pode exigir o plugin.
 *
 * @package reconectar
 */

defined( 'ABSPATH' ) || exit;

/**
 * Atalhos da coluna esquerda do feed.
 *
 * Só destinos que existem nesta instalação: Groups e Friends estão desligados
 * de propósito, e um atalho "Grupos" levaria a um 404 com aparência de recurso.
 * O fórum e as notificações entram condicionados ao plugin e ao componente.
 *
 * @return array<int, array{rotulo: string, url: string, icone: string}>
 */
function reconectar_comunidade_atalhos() {
	if ( ! function_exists( 'bp_loggedin_user_url' ) || ! is_user_logged_in() ) {
		return array();
	}

	$atalhos = array(
		array(
			'rotulo' => __( 'Feed da comunidade', 'reconectar' ),
			'url'    => bp_get_activity_directory_permalink(),
			'icone'  => 'feed',
		),
		array(
			'rotulo' => __( 'Meu perfil', 'reconectar' ),
			'url'    => bp_loggedin_user_url(),
			'icone'  => 'perfil',
		),
		array(
			'rotulo' => __( 'Menções a mim', 'reconectar' ),
			'url'    => bp_loggedin_user_url( bp_members_get_path_chunks( array( bp_get_activity_slug(), 'mentions' ) ) ),
			'icone'  => 'mencao',
		),
		array(
			'rotulo' => __( 'Membros', 'reconectar' ),
			'url'    => bp_get_members_directory_permalink(),
			'icone'  => 'membros',
		),
	);

	if ( function_exists( 'bbp_get_forums_url' ) ) {
		$atalhos[] = array(
			'rotulo' => __( 'Fórum', 'reconectar' ),
			'url'    => bbp_get_forums_url(),
			'icone'  => 'forum',
		);
	}

	if ( bp_is_active( 'notifications' ) ) {
		$atalhos[] = array(
			'rotulo' => __( 'Notificações', 'reconectar' ),
			'url'    => bp_loggedin_user_url( bp_members_get_path_chunks( array( bp_get_notifications_slug() ) ) ),
			'icone'  => 'notificacao',
		);
	}

	return $atalhos;
}

/**
 * Ícone de traço de um atalho, em SVG inline.
 *
 * Inline e não fonte de ícones: o tema não carrega Dashicons para o visitante,
 * e o Font Awesome que aparece na moldura do painel é do Dokan — some na
 * moldura da vitrine. `aria-hidden` porque o rótulo ao lado já diz tudo.
 *
 * @param string $nome Chave do ícone, como em `reconectar_comunidade_atalhos()`.
 * @return string Markup do SVG, já seguro.
 */
function reconectar_comunidade_icone( $nome ) {
	$tracos = array(
		'feed'        => '<path d="M4 5h16M4 12h16M4 19h10"/>',
		'perfil'      => '<circle cx="12" cy="8" r="4"/><path d="M4 21c0-4 4-6 8-6s8 2 8 6"/>',
		'mencao'      => '<circle cx="12" cy="12" r="4"/><path d="M16 12v1.5a2.5 2.5 0 0 0 5 0V12a9 9 0 1 0-3.5 7.1"/>',
		'membros'     => '<circle cx="9" cy="8" r="3.5"/><path d="M2 20c0-3.5 3-5.5 7-5.5s7 2 7 5.5"/><path d="M16 4.5a3.5 3.5 0 0 1 0 7M18 14.8c2.4.6 4 2.3 4 5.2"/>',
		'forum'       => '<path d="M4 5h16v10H9l-5 4z"/>',
		'notificacao' => '<path d="M6 16V11a6 6 0 0 1 12 0v5l2 2H4z"/><path d="M10 21h4"/>',
	);

	if ( ! isset( $tracos[ $nome ] ) ) {
		return '';
	}

	return '<svg class="rc-comunidade__icone" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">' . $tracos[ $nome ] . '</svg>';
}

/**
 * Coluna esquerda do feed: o cartão de quem está logado e os atalhos.
 *
 * O `aria-current` marca o atalho da tela corrente pela URL sem query string,
 * comparada à requisitada: o diretório e o perfil não têm outro sinal comum, e
 * a comparação por prefixo acenderia "Meu perfil" também em "Menções a mim" —
 * a mesma armadilha da barra React do Dokan registrada no CLAUDE.md.
 *
 * @return void
 */
function reconectar_comunidade_coluna_de_atalhos() {
	$atalhos = reconectar_comunidade_atalhos();

	if ( ! $atalhos ) {
		return;
	}

	$usuario  = bp_loggedin_user_id();
	$corrente = trailingslashit( strtok( home_url( add_query_arg( array() ) ), '?' ) );
	?>
	<aside class="rc-comunidade__lateral rc-comunidade__lateral--atalhos" aria-label="<?php esc_attr_e( 'Atalhos da comunidade', 'reconectar' ); ?>">
		<div class="rc-comunidade__cartao rc-comunidade__eu">
			<a class="rc-comunidade__eu-link" href="<?php echo esc_url( bp_loggedin_user_url() ); ?>">
				<?php
				echo bp_core_fetch_avatar( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- markup do núcleo do BuddyPress.
					array(
						'item_id' => $usuario,
						'type'    => 'full',
						'width'   => 56,
						'height'  => 56,
						'alt'     => '',
						'class'   => 'avatar rc-comunidade__avatar',
					)
				);
				?>
				<span class="rc-comunidade__eu-nome"><?php echo esc_html( bp_core_get_user_displayname( $usuario ) ); ?></span>
			</a>
			<?php if ( bp_is_active( 'activity' ) && bp_activity_do_mentions() ) : ?>
				<span class="rc-comunidade__eu-apoio">@<?php echo esc_html( bp_activity_get_user_mentionname( $usuario ) ); ?></span>
			<?php endif; ?>
		</div>

		<nav class="rc-comunidade__cartao rc-comunidade__atalhos" aria-label="<?php esc_attr_e( 'Navegação da comunidade', 'reconectar' ); ?>">
			<ul>
				<?php foreach ( $atalhos as $atalho ) : ?>
					<?php $atual = trailingslashit( $atalho['url'] ) === $corrente; ?>
					<li>
						<a href="<?php echo esc_url( $atalho['url'] ); ?>"<?php echo $atual ? ' aria-current="page"' : ''; ?>>
							<?php echo reconectar_comunidade_icone( $atalho['icone'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- SVG fixo do próprio tema. ?>
							<span><?php echo esc_html( $atalho['rotulo'] ); ?></span>
						</a>
					</li>
				<?php endforeach; ?>
			</ul>
		</nav>
	</aside>
	<?php
}

/**
 * Coluna direita do feed: quem esteve ativo por último.
 *
 * `bp_core_get_users()` e não `bp_has_members()`: o segundo monta o
 * `$members_template` global, que o diretório de membros e os widgets do
 * BuddyPress leem — uma coluna lateral não pode deixar estado para trás.
 *
 * O "ativo há" vem de `last_activity`, gravado pelo próprio BuddyPress. Nada de
 * "sugestões" calculadas: sem Friends não há vínculo de onde sugerir, e uma
 * lista que se dissesse personalizada sem ser seria um dado inventado.
 *
 * @param int $quantidade Quantos membros listar.
 * @return void
 */
function reconectar_comunidade_coluna_de_membros( $quantidade = 6 ) {
	if ( ! function_exists( 'bp_core_get_users' ) ) {
		return;
	}

	$consulta = bp_core_get_users(
		array(
			'type'     => 'active',
			'per_page' => $quantidade,
			'exclude'  => array( bp_loggedin_user_id() ),
		)
	);

	$membros = isset( $consulta['users'] ) ? $consulta['users'] : array();
	?>
	<aside class="rc-comunidade__lateral rc-comunidade__lateral--membros" aria-labelledby="rc-comunidade-ativos">
		<div class="rc-comunidade__cartao">
			<h2 id="rc-comunidade-ativos" class="rc-comunidade__titulo-cartao"><?php esc_html_e( 'Ativos recentemente', 'reconectar' ); ?></h2>

			<?php if ( $membros ) : ?>
				<ul class="rc-comunidade__membros">
					<?php foreach ( $membros as $membro ) : ?>
						<li>
							<a href="<?php echo esc_url( bp_members_get_user_url( $membro->ID ) ); ?>">
								<?php
								echo bp_core_fetch_avatar( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- markup do núcleo do BuddyPress.
									array(
										'item_id' => $membro->ID,
										'type'    => 'thumb',
										'width'   => 40,
										'height'  => 40,
										'alt'     => '',
										'class'   => 'avatar rc-comunidade__avatar',
									)
								);
								?>
								<span class="rc-comunidade__membro-texto">
									<span class="rc-comunidade__membro-nome"><?php echo esc_html( bp_core_get_user_displayname( $membro->ID ) ); ?></span>
									<?php if ( ! empty( $membro->last_activity ) ) : ?>
										<span class="rc-comunidade__membro-apoio"><?php echo esc_html( bp_core_time_since( $membro->last_activity ) ); ?></span>
									<?php endif; ?>
								</span>
							</a>
						</li>
					<?php endforeach; ?>
				</ul>
			<?php else : ?>
				<p class="rc-comunidade__vazio"><?php esc_html_e( 'Ninguém mais passou por aqui ainda.', 'reconectar' ); ?></p>
			<?php endif; ?>

			<a class="rc-comunidade__ver-todos" href="<?php echo esc_url( bp_get_members_directory_permalink() ); ?>"><?php esc_html_e( 'Ver todos os membros', 'reconectar' ); ?></a>
		</div>
	</aside>
	<?php
}

/**
 * Linhas do cartão "Sobre" do perfil.
 *
 * Só dados gravados: data de cadastro, último acesso, a loja do Dokan quando o
 * membro tem uma ativa e os campos do xProfile que o visitante tem direito de
 * ver. O campo de nome fica de fora porque o cabeçalho já o mostra.
 *
 * Os campos vêm de `bp_has_profile()`, que aplica a visibilidade de cada campo
 * para quem está olhando. O laço deixa `$profile_template` montado — e é por
 * isso que o cartão não aparece na aba Perfil, cujo template roda o mesmo laço
 * e começaria do ponto em que este parou.
 *
 * @param int $usuario ID do membro exibido.
 * @return array<int, array{rotulo: string, valor: string, url?: string}>
 */
function reconectar_comunidade_sobre( $usuario ) {
	$linhas = array();
	$dados  = get_userdata( $usuario );

	if ( ! $dados ) {
		return $linhas;
	}

	$linhas[] = array(
		'rotulo' => __( 'Membro desde', 'reconectar' ),
		'valor'  => date_i18n( 'F \d\e Y', strtotime( $dados->user_registered ) ),
	);

	$ultimo_acesso = function_exists( 'bp_get_user_last_activity' ) ? bp_get_user_last_activity( $usuario ) : '';
	if ( $ultimo_acesso ) {
		$linhas[] = array(
			'rotulo' => __( 'Último acesso', 'reconectar' ),
			'valor'  => bp_core_time_since( $ultimo_acesso ),
		);
	}

	if ( function_exists( 'dokan_is_user_seller' ) && dokan_is_user_seller( $usuario ) && function_exists( 'dokan_is_seller_enabled' ) && dokan_is_seller_enabled( $usuario ) ) {
		$linhas[] = array(
			'rotulo' => __( 'Loja', 'reconectar' ),
			'valor'  => __( 'Visitar a loja', 'reconectar' ),
			'url'    => dokan_get_store_url( $usuario ),
		);
	}

	if ( function_exists( 'bp_has_profile' ) && bp_is_active( 'xprofile' ) ) {
		$nome = function_exists( 'bp_xprofile_fullname_field_id' ) ? bp_xprofile_fullname_field_id() : 1;

		if ( bp_has_profile( array( 'user_id' => $usuario, 'hide_empty_fields' => true ) ) ) {
			while ( bp_profile_groups() ) {
				bp_the_profile_group();

				while ( bp_profile_fields() ) {
					bp_the_profile_field();

					if ( (int) bp_get_the_profile_field_id() === (int) $nome || ! bp_field_has_data() ) {
						continue;
					}

					$linhas[] = array(
						'rotulo' => bp_get_the_profile_field_name(),
						'valor'  => wp_strip_all_tags( bp_get_the_profile_field_value() ),
					);
				}
			}
		}
	}

	return $linhas;
}
