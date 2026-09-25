<?php
/**
 * Cadastro e manutenção de vendedores pelo Administrador de Empresas.
 *
 * Esta classe existe porque a via natural do WordPress para criar usuários —
 * conceder `create_users` e mandar a pessoa ao `/wp-admin/user-new.php` — abriria
 * o painel técnico inteiro, que é exatamente o que a especificação proíbe a este
 * ator. O cadastro precisa então acontecer por um caminho próprio, e a
 * autorização dele é responsabilidade deste código, não do núcleo.
 *
 * É uma API de aplicação, não um formulário: quem desenha tela é o painel de
 * empresas. A separação importa porque estas mesmas funções são chamadas pela
 * carga de demonstração — o que garante que a demonstração exercite o caminho
 * real de cadastro, em vez de uma segunda implementação que diverge em silêncio.
 *
 * @package reconectar-core
 */

defined( 'ABSPATH' ) || exit;

class Reconectar_Vendedores {

	/**
	 * O papel de todo vendedor criado por aqui.
	 *
	 * Constante, e nunca um valor vindo da requisição. Esta é a trava contra
	 * escalada de privilégio de todo o módulo: `wp_insert_user()` não verifica
	 * capacidade nenhuma — ela cria o que mandarem, inclusive um
	 * `administrator` — e quem verifica somos nós. Um `role` lido do `$_POST`
	 * transformaria o cadastro de vendedor em criação de administrador por
	 * requisição forjada, e nada mais neste módulo conteria isso.
	 */
	const PAPEL = 'seller';

	/**
	 * Cadastra um vendedor e o vincula a uma empresa.
	 *
	 * @param array $dados {
	 *     @type string $login      Obrigatório. Nome de usuário.
	 *     @type string $email      Obrigatório. E-mail.
	 *     @type int    $empresa_id Obrigatório. Empresa a que o vendedor pertence.
	 *     @type string $nome       Nome de exibição e nome da loja.
	 *     @type string $primeiro   Primeiro nome.
	 *     @type string $ultimo     Sobrenome.
	 *     @type string $telefone   Telefone de contato.
	 *     @type string $descricao  Descrição da loja.
	 *     @type string $senha      Senha; quando ausente, é gerada.
	 *     @type bool   $notificar  Enviar o e-mail de boas-vindas. Padrão: false.
	 * }
	 * @return array|WP_Error Array com `usuario_id` e `link_de_senha`, ou erro.
	 */
	public static function criar( array $dados ) {
		$empresa_id = isset( $dados['empresa_id'] ) ? (int) $dados['empresa_id'] : 0;

		if ( ! self::pode_gerir( $empresa_id ) ) {
			return new WP_Error(
				'reconectar_sem_permissao',
				__( 'Você não tem permissão para cadastrar vendedores nesta empresa.', 'reconectar-core' )
			);
		}

		$login = isset( $dados['login'] ) ? sanitize_user( $dados['login'], true ) : '';
		$email = isset( $dados['email'] ) ? sanitize_email( $dados['email'] ) : '';

		if ( '' === $login || ! is_email( $email ) ) {
			return new WP_Error(
				'reconectar_dados_invalidos',
				__( 'Informe um nome de usuário e um e-mail válidos.', 'reconectar-core' )
			);
		}

		if ( username_exists( $login ) ) {
			return new WP_Error(
				'reconectar_login_em_uso',
				__( 'Já existe uma conta com este nome de usuário.', 'reconectar-core' )
			);
		}

		if ( email_exists( $email ) ) {
			return new WP_Error(
				'reconectar_email_em_uso',
				__( 'Já existe uma conta com este e-mail.', 'reconectar-core' )
			);
		}

		$nome  = isset( $dados['nome'] ) && '' !== $dados['nome'] ? sanitize_text_field( $dados['nome'] ) : $login;
		$senha = isset( $dados['senha'] ) && '' !== $dados['senha'] ? $dados['senha'] : wp_generate_password( 20 );

		$usuario_id = wp_insert_user(
			array(
				'user_login'   => $login,
				'user_email'   => $email,
				'user_pass'    => $senha,
				'display_name' => $nome,
				'first_name'   => isset( $dados['primeiro'] ) ? sanitize_text_field( $dados['primeiro'] ) : '',
				'last_name'    => isset( $dados['ultimo'] ) ? sanitize_text_field( $dados['ultimo'] ) : '',
				'description'  => isset( $dados['descricao'] ) ? sanitize_textarea_field( $dados['descricao'] ) : '',

				// Literal, jamais vindo de `$dados` — ver o comentário da constante.
				'role'         => self::PAPEL,
			)
		);

		if ( is_wp_error( $usuario_id ) ) {
			return $usuario_id;
		}

		self::preparar_loja( $usuario_id, $nome, isset( $dados['telefone'] ) ? $dados['telefone'] : '' );

		// Sem empresa, sem meta de vínculo: um `0` gravado seria indistinguível de
		// "pertence à empresa 0" em qualquer leitura futura, e a ausência já é a
		// representação correta de um vendedor independente.
		if ( $empresa_id ) {
			update_user_meta( $usuario_id, Reconectar_Empresa::META_VINCULO, $empresa_id );
		}

		update_user_meta( $usuario_id, Reconectar_Empresa::META_VENDEDOR_ATIVO, 'sim' );

		// Nasce herdando o estado da empresa: um vendedor cadastrado em empresa
		// desativada não entra em operação sozinho.
		Reconectar_Empresa::aplicar_permissao_de_venda( $usuario_id );

		if ( ! empty( $dados['notificar'] ) ) {
			wp_new_user_notification( $usuario_id, null, 'user' );
		}

		return array(
			'usuario_id'    => (int) $usuario_id,
			'link_de_senha' => self::link_de_definicao_de_senha( $usuario_id ),
		);
	}

	/**
	 * Atualiza os dados cadastrais de um vendedor.
	 *
	 * Não mexe em papel, em login nem em vínculo: mudar a empresa de um vendedor
	 * move junto o histórico de pedidos dele para outro administrador, e essa é
	 * uma decisão de outro peso que a edição de um telefone.
	 *
	 * @param int   $vendedor_id ID do vendedor.
	 * @param array $dados       Campos a atualizar (`nome`, `primeiro`, `ultimo`, `email`, `telefone`, `descricao`).
	 * @return true|WP_Error
	 */
	public static function atualizar( $vendedor_id, array $dados ) {
		$vendedor_id = (int) $vendedor_id;

		if ( ! self::pode_gerir( Reconectar_Empresa::empresa_do_vendedor( $vendedor_id ) ) ) {
			return new WP_Error(
				'reconectar_sem_permissao',
				__( 'Você não tem permissão para editar este vendedor.', 'reconectar-core' )
			);
		}

		$campos = array( 'ID' => $vendedor_id );

		if ( isset( $dados['email'] ) ) {
			$email = sanitize_email( $dados['email'] );

			if ( ! is_email( $email ) ) {
				return new WP_Error(
					'reconectar_dados_invalidos',
					__( 'Informe um e-mail válido.', 'reconectar-core' )
				);
			}

			$dono = email_exists( $email );

			if ( $dono && (int) $dono !== $vendedor_id ) {
				return new WP_Error(
					'reconectar_email_em_uso',
					__( 'Já existe uma conta com este e-mail.', 'reconectar-core' )
				);
			}

			$campos['user_email'] = $email;
		}

		if ( isset( $dados['nome'] ) && '' !== $dados['nome'] ) {
			$campos['display_name'] = sanitize_text_field( $dados['nome'] );
		}

		if ( isset( $dados['primeiro'] ) ) {
			$campos['first_name'] = sanitize_text_field( $dados['primeiro'] );
		}

		if ( isset( $dados['ultimo'] ) ) {
			$campos['last_name'] = sanitize_text_field( $dados['ultimo'] );
		}

		if ( isset( $dados['descricao'] ) ) {
			$campos['description'] = sanitize_textarea_field( $dados['descricao'] );
		}

		$resultado = wp_update_user( $campos );

		if ( is_wp_error( $resultado ) ) {
			return $resultado;
		}

		$perfil = get_user_meta( $vendedor_id, 'dokan_profile_settings', true );
		$perfil = is_array( $perfil ) ? $perfil : array();

		if ( isset( $campos['display_name'] ) ) {
			$perfil['store_name'] = $campos['display_name'];
			update_user_meta( $vendedor_id, 'dokan_store_name', $campos['display_name'] );
		}

		if ( isset( $dados['telefone'] ) ) {
			$perfil['phone'] = sanitize_text_field( $dados['telefone'] );
		}

		update_user_meta( $vendedor_id, 'dokan_profile_settings', $perfil );

		return true;
	}

	/**
	 * Ativa ou desativa um vendedor.
	 *
	 * Grava o estado individual e deixa o cálculo do efetivo com
	 * `Reconectar_Empresa::aplicar_permissao_de_venda()` — que é quem sabe
	 * combinar este estado com o da empresa.
	 *
	 * @param int  $vendedor_id ID do vendedor.
	 * @param bool $ativo       Novo estado.
	 * @return true|WP_Error
	 */
	public static function definir_ativo( $vendedor_id, $ativo ) {
		$vendedor_id = (int) $vendedor_id;

		if ( ! self::pode_gerir( Reconectar_Empresa::empresa_do_vendedor( $vendedor_id ) ) ) {
			return new WP_Error(
				'reconectar_sem_permissao',
				__( 'Você não tem permissão para alterar este vendedor.', 'reconectar-core' )
			);
		}

		update_user_meta( $vendedor_id, Reconectar_Empresa::META_VENDEDOR_ATIVO, $ativo ? 'sim' : 'nao' );
		Reconectar_Empresa::aplicar_permissao_de_venda( $vendedor_id );

		return true;
	}

	/* ---------------------------------------------------------------------
	 * Apoio
	 * ------------------------------------------------------------------ */

	/**
	 * O usuário atual pode cadastrar ou alterar vendedores desta empresa?
	 *
	 * A exceção do WP-CLI não é uma brecha: quem roda a linha de comando já tem
	 * acesso irrestrito ao banco de dados por baixo desta camada, e exigir
	 * capacidade ali seria encenação — além de impedir que a carga de
	 * demonstração use este mesmo caminho, que é o que mantém a demonstração
	 * honesta em relação ao código de produção.
	 *
	 * @param int $empresa_id Empresa alvo.
	 * @return bool
	 */
	private static function pode_gerir( $empresa_id ) {
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			return true;
		}

		if ( ! current_user_can( Reconectar_Permissoes::CAP_GERIR_VENDEDORES ) ) {
			return false;
		}

		return Reconectar_Empresa::pode_gerir_empresa( $empresa_id );
	}

	/**
	 * Grava o perfil mínimo que o Dokan espera de uma loja.
	 *
	 * Sem `dokan_profile_settings` e `dokan_store_name` a loja existe como
	 * usuário e não como loja: o painel do vendedor abre sem nome e a vitrine não
	 * sabe o que exibir no card.
	 *
	 * @param int    $usuario_id ID do vendedor.
	 * @param string $nome       Nome da loja.
	 * @param string $telefone   Telefone de contato.
	 * @return void
	 */
	private static function preparar_loja( $usuario_id, $nome, $telefone ) {
		$perfil = array(
			'store_name'         => $nome,
			'social'             => array(),
			'payment'            => array(
				'paypal' => array( 'email' => '' ),
				'bank'   => array(),
			),
			'phone'              => sanitize_text_field( $telefone ),
			'show_email'         => 'no',
			'address'            => array(
				'street_1' => '',
				'street_2' => '',
				'city'     => '',
				'zip'      => '',
				'country'  => 'BR',
				'state'    => '',
			),
			'location'           => '',
			'find_address'       => '',
			'banner'             => 0,
			'gravatar'           => 0,
			'icon'               => '',
			'enable_tnc'         => 'off',
			'store_tnc'          => '',
			'store_seo'          => array(),
			'dokan_store_time'   => array(),
			'store_ppp'          => 12,
			'profile_completion' => array( 'progress' => 100 ),
		);

		update_user_meta( $usuario_id, 'dokan_profile_settings', $perfil );
		update_user_meta( $usuario_id, 'dokan_store_name', $nome );

		// Sem `dokan_publishing` o produto do vendedor nasce pendente de revisão,
		// o que não é a regra desta plataforma.
		update_user_meta( $usuario_id, 'dokan_publishing', 'yes' );
	}

	/**
	 * Monta o link de definição de senha do vendedor recém-criado.
	 *
	 * O painel mostra este link para o administrador repassar, em vez de anunciar
	 * um e-mail que pode não ter saído: o ambiente de demonstração usa endereços
	 * em `exemplo.invalid` e não tem SMTP, e dizer "e-mail enviado" ali seria
	 * informação falsa na tela — o oposto da honestidade de dados que o resto da
	 * plataforma segue.
	 *
	 * @param int $usuario_id ID do vendedor.
	 * @return string URL, ou string vazia se a chave não pôde ser gerada.
	 */
	public static function link_de_definicao_de_senha( $usuario_id ) {
		$usuario = get_userdata( (int) $usuario_id );

		if ( ! $usuario ) {
			return '';
		}

		$chave = get_password_reset_key( $usuario );

		if ( is_wp_error( $chave ) ) {
			return '';
		}

		return network_site_url(
			'wp-login.php?action=rp&key=' . rawurlencode( $chave ) . '&login=' . rawurlencode( $usuario->user_login ),
			'login'
		);
	}
}
