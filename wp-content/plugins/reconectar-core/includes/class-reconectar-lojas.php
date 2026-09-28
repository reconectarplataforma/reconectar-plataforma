<?php
/**
 * Cadastro e manutenção de lojas pelo Administrador de Empresas.
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
 * Uma loja é, no banco, um usuário com o papel `seller` do Dokan. "Loja" é o
 * nome da entidade na plataforma; `seller` é o nome do papel no plugin de
 * terceiro, e os dois não se confundem — ver `PAPEL`.
 *
 * @package reconectar-core
 */

defined( 'ABSPATH' ) || exit;

class Reconectar_Lojas {

	/**
	 * O papel de toda loja criada por aqui.
	 *
	 * Continua `seller` — e continuará — porque é o papel que o Dokan cria e
	 * consulta: renomeá-lo por causa do vocabulário da plataforma daria um nome
	 * nosso a um conceito de terceiro e quebraria o plugin inteiro.
	 *
	 * Constante, e nunca um valor vindo da requisição. Esta é a trava contra
	 * escalada de privilégio de todo o módulo: `wp_insert_user()` não verifica
	 * capacidade nenhuma — ela cria o que mandarem, inclusive um
	 * `administrator` — e quem verifica somos nós. Um `role` lido do `$_POST`
	 * transformaria o cadastro de loja em criação de administrador por
	 * requisição forjada, e nada mais neste módulo conteria isso.
	 */
	const PAPEL = 'seller';

	/**
	 * Cadastra uma loja e a vincula a uma empresa.
	 *
	 * @param array $dados {
	 *     @type string $login      Obrigatório. Nome de usuário.
	 *     @type string $email      Obrigatório. E-mail.
	 *     @type int    $empresa_id Obrigatório. Empresa a que a loja pertence.
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
				__( 'Você não tem permissão para cadastrar lojas nesta empresa.', 'reconectar-core' )
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
		// representação correta de uma loja independente.
		if ( $empresa_id ) {
			update_user_meta( $usuario_id, Reconectar_Empresa::META_VINCULO, $empresa_id );
		}

		update_user_meta( $usuario_id, Reconectar_Empresa::META_LOJA_ATIVA, 'sim' );

		// Nasce herdando o estado da empresa: uma loja cadastrada em empresa
		// desativada não entra em operação sozinha.
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
	 * Atualiza os dados cadastrais de uma loja.
	 *
	 * Não mexe em papel, em login nem em vínculo: mudar a empresa de uma loja
	 * move junto o histórico de pedidos dela para outro administrador, e essa é
	 * uma decisão de outro peso que a edição de um telefone.
	 *
	 * @param int   $loja_id ID da loja.
	 * @param array $dados   Campos a atualizar (`nome`, `primeiro`, `ultimo`, `email`, `telefone`, `descricao`).
	 * @return true|WP_Error
	 */
	public static function atualizar( $loja_id, array $dados ) {
		$loja_id = (int) $loja_id;

		if ( ! self::pode_gerir( Reconectar_Empresa::empresa_da_loja( $loja_id ) ) ) {
			return new WP_Error(
				'reconectar_sem_permissao',
				__( 'Você não tem permissão para editar esta loja.', 'reconectar-core' )
			);
		}

		$campos = array( 'ID' => $loja_id );

		if ( isset( $dados['email'] ) ) {
			$email = sanitize_email( $dados['email'] );

			if ( ! is_email( $email ) ) {
				return new WP_Error(
					'reconectar_dados_invalidos',
					__( 'Informe um e-mail válido.', 'reconectar-core' )
				);
			}

			$dono = email_exists( $email );

			if ( $dono && (int) $dono !== $loja_id ) {
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

		$perfil = get_user_meta( $loja_id, 'dokan_profile_settings', true );
		$perfil = is_array( $perfil ) ? $perfil : array();

		if ( isset( $campos['display_name'] ) ) {
			$perfil['store_name'] = $campos['display_name'];
			update_user_meta( $loja_id, 'dokan_store_name', $campos['display_name'] );
		}

		if ( isset( $dados['telefone'] ) ) {
			$perfil['phone'] = sanitize_text_field( $dados['telefone'] );
		}

		update_user_meta( $loja_id, 'dokan_profile_settings', $perfil );

		return true;
	}

	/**
	 * Ativa ou desativa uma loja.
	 *
	 * Grava o estado individual e deixa o cálculo do efetivo com
	 * `Reconectar_Empresa::aplicar_permissao_de_venda()` — que é quem sabe
	 * combinar este estado com o da empresa.
	 *
	 * @param int  $loja_id ID da loja.
	 * @param bool $ativa   Novo estado.
	 * @return true|WP_Error
	 */
	public static function definir_ativa( $loja_id, $ativa ) {
		$loja_id = (int) $loja_id;

		if ( ! self::pode_gerir( Reconectar_Empresa::empresa_da_loja( $loja_id ) ) ) {
			return new WP_Error(
				'reconectar_sem_permissao',
				__( 'Você não tem permissão para alterar esta loja.', 'reconectar-core' )
			);
		}

		update_user_meta( $loja_id, Reconectar_Empresa::META_LOJA_ATIVA, $ativa ? 'sim' : 'nao' );
		Reconectar_Empresa::aplicar_permissao_de_venda( $loja_id );

		return true;
	}

	/* ---------------------------------------------------------------------
	 * Apoio
	 * ------------------------------------------------------------------ */

	/**
	 * O usuário atual pode cadastrar ou alterar lojas desta empresa?
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

		if ( ! current_user_can( Reconectar_Permissoes::CAP_GERIR_LOJAS ) ) {
			return false;
		}

		return Reconectar_Empresa::pode_gerir_empresa( $empresa_id );
	}

	/**
	 * Grava o perfil mínimo que o Dokan espera de uma loja.
	 *
	 * Sem `dokan_profile_settings` e `dokan_store_name` a loja existe como
	 * usuário e não como loja: o painel dela abre sem nome e a vitrine não sabe o
	 * que exibir no card.
	 *
	 * @param int    $usuario_id ID da loja.
	 * @param string $nome       Nome da loja.
	 * @param string $telefone   Telefone de contato.
	 * @return void
	 */
	private static function preparar_loja( $usuario_id, $nome, $telefone ) {
		$perfil = array(
			'store_name'         => $nome,
			'social'             => array(),
			// O `pix` nasce vazio junto dos outros para que a estrutura exista antes
			// de a loja preencher. `Reconectar_Pagamento_Pix` lê por chave e um
			// `payment` sem ela obrigaria toda leitura a checar existência — e a que
			// esquecesse emitiria aviso, que neste projeto cancela redirect.
			'payment'            => array(
				'paypal' => array( 'email' => '' ),
				'bank'   => array(),
				'pix'    => array(),
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

		// Sem `dokan_publishing` o produto da loja nasce pendente de revisão,
		// o que não é a regra desta plataforma.
		update_user_meta( $usuario_id, 'dokan_publishing', 'yes' );
	}

	/**
	 * Monta o link de definição de senha da loja recém-criada.
	 *
	 * O painel mostra este link para o administrador repassar, em vez de anunciar
	 * um e-mail que pode não ter saído: o ambiente de demonstração usa endereços
	 * em `exemplo.invalid` e não tem SMTP, e dizer "e-mail enviado" ali seria
	 * informação falsa na tela — o oposto da honestidade de dados que o resto da
	 * plataforma segue.
	 *
	 * @param int $usuario_id ID da loja.
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
