<?php
/**
 * Ficha de um vendedor: edição cadastral, situação e a operação dele.
 *
 * Tudo abaixo da edição cadastral é **leitura**. O alcance deste ator, decidido
 * com o usuário, é consultar produtos, pedidos, estoque e faturamento — não
 * criar nem alterar nenhum deles. Não acrescente aqui um botão de editar produto
 * ou de mudar status de pedido sem que a decisão de alcance mude junto.
 *
 * @package reconectar-core
 */

defined( 'ABSPATH' ) || exit;

$vendedor_id = (int) $contexto['id'];
$usuario     = get_userdata( $vendedor_id );

if ( ! $usuario ) {
	return;
}

$empresa_id = Reconectar_Empresa::empresa_do_vendedor( $vendedor_id );
$em_venda   = Reconectar_Empresa::vendedor_esta_ativo( $vendedor_id );
$individual = 'nao' !== get_user_meta( $vendedor_id, Reconectar_Empresa::META_VENDEDOR_ATIVO, true );
$perfil     = get_user_meta( $vendedor_id, 'dokan_profile_settings', true );
$telefone   = is_array( $perfil ) && isset( $perfil['phone'] ) ? $perfil['phone'] : '';
$senha      = Reconectar_Painel_Empresas::link_de_senha_pendente();
?>

<div class="rc-painel-empresas__topo">
	<h1 class="rc-painel-empresas__titulo"><?php echo esc_html( $usuario->display_name ); ?></h1>

	<span class="rc-selo <?php echo $em_venda ? 'rc-selo--ativo' : 'rc-selo--inativo'; ?>">
		<?php echo esc_html( $em_venda ? __( 'Vendendo', 'reconectar-core' ) : __( 'Fora de operação', 'reconectar-core' ) ); ?>
	</span>
</div>

<?php if ( $empresa_id ) : ?>
	<p class="rc-painel-empresas__nota">
		<?php esc_html_e( 'Empresa:', 'reconectar-core' ); ?>
		<a href="<?php echo esc_url( Reconectar_Painel_Empresas::url( 'empresa/' . $empresa_id ) ); ?>">
			<?php echo esc_html( get_the_title( $empresa_id ) ); ?>
		</a>
	</p>
<?php endif; ?>

<?php if ( $senha && (int) $senha['vendedor_id'] === $vendedor_id && '' !== $senha['link'] ) : ?>
	<div class="rc-painel-empresas__aviso rc-painel-empresas__aviso--senha" role="status">
		<p><strong><?php esc_html_e( 'Link de definição de senha', 'reconectar-core' ); ?></strong></p>
		<p><?php esc_html_e( 'Repasse este link ao vendedor. Ele aparece uma única vez e expira como qualquer link de redefinição de senha do WordPress.', 'reconectar-core' ); ?></p>
		<p class="rc-painel-empresas__link"><code><?php echo esc_url( $senha['link'] ); ?></code></p>
	</div>
<?php endif; ?>

<?php if ( ! $individual ) : ?>
	<p class="rc-painel-empresas__nota">
		<?php esc_html_e( 'Este vendedor está desativado individualmente. Reativar a empresa não o coloca de volta em operação.', 'reconectar-core' ); ?>
	</p>
<?php elseif ( ! $em_venda && $empresa_id && ! Reconectar_Empresa::esta_ativa( $empresa_id ) ) : ?>
	<p class="rc-painel-empresas__nota">
		<?php esc_html_e( 'Este vendedor está fora de operação porque a empresa dele está desativada.', 'reconectar-core' ); ?>
	</p>
<?php endif; ?>

<?php if ( current_user_can( Reconectar_Permissoes::CAP_GERIR_VENDEDORES ) ) : ?>

	<h2 class="rc-painel-empresas__secao"><?php esc_html_e( 'Dados cadastrais', 'reconectar-core' ); ?></h2>

	<form class="rc-formulario" method="post"
		action="<?php echo esc_url( Reconectar_Painel_Empresas::url( 'vendedor/' . $vendedor_id ) ); ?>">
		<?php wp_nonce_field( 'reconectar_painel_vendedor_salvar' ); ?>
		<input type="hidden" name="reconectar_acao" value="vendedor_salvar">
		<input type="hidden" name="vendedor_id" value="<?php echo esc_attr( $vendedor_id ); ?>">

		<p class="rc-formulario__campo">
			<label for="rc-vendedor-nome"><?php esc_html_e( 'Nome da loja', 'reconectar-core' ); ?></label>
			<input type="text" id="rc-vendedor-nome" name="nome" required
				value="<?php echo esc_attr( $usuario->display_name ); ?>">
		</p>

		<p class="rc-formulario__campo">
			<label for="rc-vendedor-email"><?php esc_html_e( 'E-mail', 'reconectar-core' ); ?></label>
			<input type="email" id="rc-vendedor-email" name="email" required
				value="<?php echo esc_attr( $usuario->user_email ); ?>">
		</p>

		<p class="rc-formulario__campo">
			<label for="rc-vendedor-primeiro"><?php esc_html_e( 'Nome', 'reconectar-core' ); ?></label>
			<input type="text" id="rc-vendedor-primeiro" name="primeiro"
				value="<?php echo esc_attr( $usuario->first_name ); ?>">
		</p>

		<p class="rc-formulario__campo">
			<label for="rc-vendedor-ultimo"><?php esc_html_e( 'Sobrenome', 'reconectar-core' ); ?></label>
			<input type="text" id="rc-vendedor-ultimo" name="ultimo"
				value="<?php echo esc_attr( $usuario->last_name ); ?>">
		</p>

		<p class="rc-formulario__campo">
			<label for="rc-vendedor-telefone"><?php esc_html_e( 'Telefone', 'reconectar-core' ); ?></label>
			<input type="text" id="rc-vendedor-telefone" name="telefone"
				value="<?php echo esc_attr( $telefone ); ?>">
		</p>

		<p class="rc-formulario__campo">
			<label for="rc-vendedor-descricao"><?php esc_html_e( 'Descrição da loja', 'reconectar-core' ); ?></label>
			<textarea id="rc-vendedor-descricao" name="descricao" rows="4"><?php echo esc_textarea( $usuario->description ); ?></textarea>
		</p>

		<p class="rc-formulario__acoes">
			<button type="submit" class="rc-botao rc-botao--primario">
				<?php esc_html_e( 'Salvar', 'reconectar-core' ); ?>
			</button>
		</p>
	</form>

	<form method="post" class="rc-formulario--linha"
		action="<?php echo esc_url( Reconectar_Painel_Empresas::url( 'vendedor/' . $vendedor_id ) ); ?>">
		<?php wp_nonce_field( 'reconectar_painel_vendedor_alternar' ); ?>
		<input type="hidden" name="reconectar_acao" value="vendedor_alternar">
		<input type="hidden" name="vendedor_id" value="<?php echo esc_attr( $vendedor_id ); ?>">
		<button type="submit" class="rc-botao rc-botao--discreto">
			<?php echo esc_html( $individual ? __( 'Desativar vendedor', 'reconectar-core' ) : __( 'Reativar vendedor', 'reconectar-core' ) ); ?>
		</button>
	</form>

<?php endif; ?>

<h2 class="rc-painel-empresas__secao"><?php esc_html_e( 'Produtos', 'reconectar-core' ); ?></h2>

<?php $produtos = Reconectar_Painel_Empresas::produtos_do_vendedor( $vendedor_id ); ?>

<?php if ( empty( $produtos ) ) : ?>

	<p class="rc-painel-empresas__vazio"><?php esc_html_e( 'Nenhum produto cadastrado.', 'reconectar-core' ); ?></p>

<?php else : ?>

	<table class="rc-tabela">
		<caption class="rc-tabela__legenda">
			<?php esc_html_e( 'Produtos deste vendedor, com preço, estoque e situação de publicação.', 'reconectar-core' ); ?>
		</caption>
		<thead>
			<tr>
				<th scope="col"><?php esc_html_e( 'Produto', 'reconectar-core' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Preço', 'reconectar-core' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Estoque', 'reconectar-core' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Situação', 'reconectar-core' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<?php foreach ( $produtos as $post_produto ) : ?>
				<?php
				$produto = function_exists( 'wc_get_product' ) ? wc_get_product( $post_produto->ID ) : null;
				$estoque = $produto ? $produto->get_stock_quantity() : null;
				$preco   = $produto && '' !== $produto->get_price() ? (float) $produto->get_price() : null;
				?>
				<tr>
					<th scope="row"><?php echo esc_html( get_the_title( $post_produto ) ); ?></th>
					<td><?php echo Reconectar_Painel_Empresas::dinheiro( $preco ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped — já escapado no método. ?></td>
					<td><?php echo esc_html( null === $estoque ? '—' : number_format_i18n( (int) $estoque ) ); ?></td>
					<td><?php echo esc_html( get_post_status_object( $post_produto->post_status )->label ); ?></td>
				</tr>
			<?php endforeach; ?>
		</tbody>
	</table>

<?php endif; ?>

<h2 class="rc-painel-empresas__secao"><?php esc_html_e( 'Pedidos recentes', 'reconectar-core' ); ?></h2>

<?php $pedidos = Reconectar_Painel_Empresas::pedidos_do_vendedor( $vendedor_id ); ?>

<?php if ( empty( $pedidos ) ) : ?>

	<p class="rc-painel-empresas__vazio"><?php esc_html_e( 'Nenhum pedido registrado.', 'reconectar-core' ); ?></p>

<?php else : ?>

	<table class="rc-tabela">
		<caption class="rc-tabela__legenda">
			<?php esc_html_e( 'Pedidos mais recentes deste vendedor.', 'reconectar-core' ); ?>
		</caption>
		<thead>
			<tr>
				<th scope="col"><?php esc_html_e( 'Pedido', 'reconectar-core' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Data', 'reconectar-core' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Total', 'reconectar-core' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Situação', 'reconectar-core' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<?php foreach ( $pedidos as $bruto ) : ?>
				<?php
				// O Dokan devolve ora objetos de pedido, ora linhas da tabela
				// paralela dele, conforme a versão e o modo de armazenamento
				// (HPOS ou posts). Normalizar aqui evita que a tela quebre com
				// um "call to a member function on null" ao trocar de um para o
				// outro.
				$numero = is_object( $bruto ) && method_exists( $bruto, 'get_id' )
					? $bruto->get_id()
					: ( isset( $bruto->order_id ) ? (int) $bruto->order_id : 0 );

				$pedido = ( $numero && function_exists( 'wc_get_order' ) ) ? wc_get_order( $numero ) : null;

				if ( ! $pedido ) {
					continue;
				}

				$data = $pedido->get_date_created();
				?>
				<tr>
					<th scope="row"><?php echo esc_html( '#' . $pedido->get_order_number() ); ?></th>
					<td><?php echo esc_html( $data ? wp_date( get_option( 'date_format' ), $data->getTimestamp() ) : '—' ); ?></td>
					<td><?php echo Reconectar_Painel_Empresas::dinheiro( (float) $pedido->get_total() ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped — já escapado no método. ?></td>
					<td><?php echo esc_html( function_exists( 'wc_get_order_status_name' ) ? wc_get_order_status_name( $pedido->get_status() ) : $pedido->get_status() ); ?></td>
				</tr>
			<?php endforeach; ?>
		</tbody>
	</table>

<?php endif; ?>

<h2 class="rc-painel-empresas__secao"><?php esc_html_e( 'Ganhos liberados', 'reconectar-core' ); ?></h2>

<p class="rc-painel-empresas__numero">
	<?php echo Reconectar_Painel_Empresas::dinheiro( Reconectar_Painel_Empresas::faturamento_do_vendedor( $vendedor_id ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped — já escapado no método. ?>
</p>

<p class="rc-painel-empresas__nota">
	<?php esc_html_e( 'Soma dos pedidos concluídos, descontadas as devoluções. Pedido em preparação ou a caminho ainda não entra na conta — é o mesmo número que o vendedor vê no painel dele.', 'reconectar-core' ); ?>
</p>
