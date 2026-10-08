# Exclusão de dados — atender um pedido

A página `/exclusao-de-dados/` promete o que sai e o que fica. Quem cumpre é o
Super Administrador, pelo `/wp-admin`, em **dois passos e nesta ordem**:
primeiro apagar os dados, depois excluir a conta.

A ordem não é opcional. Todo apagador do WordPress encontra o titular **pelo
e-mail da conta**. Se a conta for excluída antes, os apagadores do WooCommerce,
do Dokan e da plataforma não encontram ninguém, respondem "nada a remover" e o
pedido parece atendido.

## 0. Exportar, se a pessoa pediu cópia

**Ferramentas → Exportar dados pessoais** → e-mail da conta → **Enviar
solicitação**. A pessoa recebe um link de confirmação; depois de confirmado, a
linha ganha **Baixar dados pessoais** ou **Enviar exportação por e-mail**.

## 1. Apagar os dados

**Ferramentas → Apagar dados pessoais** → e-mail da conta. Marque **Confirmação
não necessária** só se o pedido já veio confirmado por outro canal — por exemplo,
do próprio e-mail da conta. Depois, na linha da solicitação, **Forçar apagamento
de dados pessoais**.

O relatório lista cada apagador. O da plataforma se chama **Reconectar —
participação, login social e loja** e faz:

| O que | Como |
| --- | --- |
| Votos na Comunidade, avaliações da Incubadora, votos em enquetes | removidos; saldos e "Gostei" recalculados |
| Comentários na Incubadora, ocultos inclusive | desvinculados da conta, autor "Conta removida" |
| IP das publicações no fórum, assinaturas, favoritos, marcas de leitura | removidos |
| Vínculo com Google e Facebook | removido |
| Loja | desativada |
| Produtos da loja | para a lixeira, que se esvazia em 30 dias |
| Comprovantes de pagamento | **mantidos**, e o relatório diz quantos |

O WooCommerce apaga os endereços e anonimiza os pedidos conforme
**WooCommerce → Configurações → Contas e privacidade**. O Dokan apaga o perfil
da loja: nome, telefone, endereço e os dados de recebimento por PIX e por banco.

Pode rodar de novo sem medo. Na segunda vez, só os comprovantes aparecem no
relatório.

## 2. Excluir a conta

**Usuários** → passe o mouse sobre a conta → **Excluir**. O WordPress pergunta o
que fazer com o conteúdo da pessoa:

- **Excluir todo o conteúdo** apaga os tópicos e as respostas do fórum.
- **Atribuir todo o conteúdo a:** mantém as publicações sob outra conta. Use
  isso quando a conversa no fórum precisar continuar legível para quem
  respondeu.

A página de exclusão deixa a escolha a quem pede. Se a pessoa não disse nada,
exclua.

## O que fica, e por quê

- **Pedidos e comprovantes:** cerca de 5 anos, pela legislação fiscal e para
  defesa em disputa.
- **Registros de acesso:** pelo menos 6 meses, pelo Marco Civil da Internet
  (art. 15). Ficam nos logs do servidor, fora do banco, e nenhum apagador os
  alcança.
- **Cópias de segurança:** saem quando a rotação de backup as substituir.
