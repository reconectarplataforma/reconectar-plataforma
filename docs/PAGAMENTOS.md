# Módulo de Pagamentos (Pix / Cartão / Boleto)

Este documento descreve como o módulo de pagamentos está **estruturado** na
plataforma Reconectar nesta etapa do projeto. **Nenhuma credencial real de
gateway de pagamento foi instalada, configurada ou commitada** — esta é uma
decisão deliberada, documentada aqui para a equipe do projeto.

## Por que nenhum gateway foi instalado ainda

A escolha do provedor de pagamento (ex.: Mercado Pago, Asaas, PagSeguro,
Stripe) depende de fatores que ainda não foram decididos pela equipe do
projeto:

- Taxas e prazos de repasse de cada provedor, considerando o modelo de
  marketplace multi-vendedor (Dokan Lite) — cada provedor tem regras
  diferentes de split de pagamento entre a plataforma e os vendedores.
- Qual CNPJ/conta bancária receberá os repasses.
- Se o Pix será processado via QR Code estático/dinâmico de um provedor de
  pagamento ou via integração bancária direta.
- Prazos de homologação e documentação exigidos por cada provedor
  (geralmente exigem CNPJ ativo e, em alguns casos, aprovação de cadastro).

Instalar um gateway com credenciais de teste/produção antes dessa decisão
criaria retrabalho e risco de vincular a plataforma a um provedor não
escolhido pela equipe.

## Como a estrutura já está pronta para receber um gateway

O **WooCommerce**, já instalado e ativo no ambiente (ver
`scripts/provision.sh`), define uma **API de gateways de pagamento**
(`WC_Payment_Gateway`) nativa e extensível. Isso significa que:

- Assim que a equipe decidir o provedor, basta instalar o plugin oficial do
  gateway escolhido (a maioria dos provedores brasileiros — Mercado Pago,
  Asaas, PagSeguro — distribui plugins oficiais para WooCommerce, alguns
  gratuitos, disponíveis no repositório oficial de plugins do WordPress ou
  no site do provedor).
- O plugin do gateway se registra automaticamente nessa API assim que
  ativado. Não é necessário nenhum código autoral adicional no
  `reconectar-core` para que Pix, cartão e boleto apareçam como formas de
  pagamento no checkout — isso é responsabilidade do plugin do gateway
  escolhido.
- O Dokan Lite (marketplace multi-vendedor) tem suporte nativo a alguns
  métodos de split de pagamento entre vendedores; provedores compatíveis com
  split (ex.: Mercado Pago Connect) podem exigir a versão paga do Dokan
  (Dokan Pro) — **esta é uma decisão de custo/orçamento que cabe à equipe**,
  não tratada nesta etapa técnica.

## Como as credenciais deverão ser configuradas (quando a equipe decidir)

Quando a equipe escolher o provedor, as credenciais (chaves de API,
tokens de acesso) devem ser inseridas **apenas pela interface administrativa
do WooCommerce** (`WooCommerce → Configurações → Pagamentos`), nunca em
código-fonte versionado. O `.env` do ambiente (já ignorado pelo Git, ver
`.gitignore`) pode ser usado para credenciais de **ambiente de teste/sandbox**
durante o desenvolvimento local, seguindo o mesmo padrão já usado para as
demais variáveis sensíveis do projeto.

## Pendências que dependem de decisão da equipe

- Escolha do provedor de pagamento (Mercado Pago, Asaas, PagSeguro ou
  outro), considerando taxas, prazos de repasse e suporte a split de
  pagamento para o marketplace multi-vendedor.
- Definição de quem (qual CNPJ/conta) recebe os repasses de cada
  vendedor/iniciativa cadastrada na plataforma.
- Decisão sobre uso do Dokan Pro (pago) caso o split de pagamento nativo do
  Dokan Lite não seja suficiente para o modelo de negócio do projeto.
- Homologação/cadastro junto ao provedor escolhido e obtenção das
  credenciais de produção.
