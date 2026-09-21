# Plano de Desenvolvimento — Plataforma "Reconectar"

**Reconectar: Incubadora Digital para Vínculos e Negócios**

Documento consolidado a partir de:
- Anexo 02 — Proposta de Projeto (Edital "Nosso Chão Nossa História" / Comitê Gestor dos Danos Extrapatrimoniais / UNOPS), organização candidata FUNDEPES
- Proposta Comercial — Plataforma Digital Colaborativa v2 (HABE LUX / David Zoroastro Evangelista, Tech Lead)
- Identidade visual do projeto (logos, cores, tipografia)

Objetivo deste documento: detalhar **todas as tasks de desenvolvimento** necessárias para deixar a plataforma pronta para validação (piloto com grupo de empreendedores), respeitando os prazos e entregáveis já comprometidos com a UNOPS no cronograma do edital (Resultado 2, atividades 2.1 a 2.12).

---

## 1. Contexto do projeto

O projeto nasce como reparação de danos extrapatrimoniais coletivos causados por um desastre socioambiental de mineração em Maceió-AL (afundamento de bairros, deslocamento forçado de famílias e ruptura de redes econômicas e sociais). A plataforma digital é o **Resultado 2** de um projeto maior de 5 Resultados (incubadora híbrida, plataforma, capacitação, reconexão de redes, sustentabilidade), com público prioritário de grupos vulneráveis (mulheres da economia lagunar, jovens, idosos, pessoas negras, comunidades tradicionais, pessoas LGBTQIAP+, pessoas com deficiência). Alcance estimado: ~300 empreendedores(as) diretos, +1.000 pessoas impactadas indiretamente.

Isso impõe requisitos não negociáveis ao desenvolvimento:
- **Código aberto obrigatório**: repositório público (GitHub/GitLab), licença livre (GPL, MIT ou equivalente).
- **Acessibilidade digital WCAG 2.1**.
- **Abordagem de Gênero, Diversidade e Inclusão (GDI)** refletida na UX (linguagem inclusiva, imagens representativas, fluxos simples para baixa literacia digital).
- **Conformidade com a LGPD** (criptografia, backups automáticos, controle de acessos).
- **Governança digital participativa** (comitê de usuários, sistema de votação, painel de transparência com dados financeiros anonimizados).
- Prestação de contas técnica via commits públicos e relatórios mensais/trimestrais à UNOPS.

## 2. Stack tecnológica definida

Conforme Proposta Comercial (HABE LUX):

| Camada | Tecnologia |
|---|---|
| CMS / E-commerce | WordPress + WooCommerce |
| Marketplace multi-vendedor | Plugin de marketplace (ex.: Dokan ou WC Vendors) |
| Comunidade / rede social interna | BuddyPress (+ bbPress para fóruns, se necessário) |
| Pagamentos | Gateway com suporte a Pix, Cartão de Crédito e Boleto (ex.: Mercado Pago, PagSeguro ou Asaas) |
| Hospedagem | Infraestrutura em nuvem escalável (AWS, Azure ou DigitalOcean) |
| Segurança/Compliance | Criptografia, backups automáticos, controle de acessos (LGPD) |
| Versionamento | Git + repositório público (GitHub), licença GPL/MIT |

## 3. Identidade visual

- **Nome da marca**: Reconectar — Incubadora Digital para Vínculos e Negócios
- **Paleta de cores**:
  - Teal `#31BEB1`
  - Terracota `#CF6442`
  - Amarelo `#F1BF3D`
  - Roxo `#663191`
- **Tipografia**: Poppins (família completa, de Thin a Black, incluindo itálicos) como fonte de texto/UI; Thoge (.otf) como fonte de destaque para títulos/branding.
- **Logos**: versões vertical (principal) e horizontal (apoio), em cor, preto e branco, disponíveis em PDF/EPS/PNG/JPEG.

---

## 4. Fases e Tasks de Desenvolvimento

Estrutura alinhada ao cronograma oficial do Resultado 2 do edital (atividades 2.1–2.12), detalhando as tasks técnicas de cada etapa.

### Fase 0 — Pré-requisitos (Mês 2)

**2.1 Contratação da empresa de tecnologia**
- [ ] Formalização de contrato com cláusulas de garantia de suporte técnico e fases de análise/desenvolvimento/testes/manutenção
- [ ] Definição de responsáveis técnicos (Tech Lead, suporte de TI)
- [ ] Criação do repositório Git privado de trabalho (a ser tornado público antes do Mês 9, conforme exigência do edital)
- [ ] Setup de ambiente de desenvolvimento seguro (staging isolado de produção)

### Fase 1 — Levantamento de Requisitos e Arquitetura (Mês 2–3)

**2.2 Oficinas de levantamento de requisitos com beneficiários**
- [ ] Planejar e conduzir oficinas participativas com empreendedores(as) do público-alvo
- [ ] Registrar necessidades específicas de grupos vulneráveis (baixa literacia digital, acessibilidade, idiomas/linguagem simples)
- [ ] Consolidar achados em ata/relatório de oficina

**2.3 Especificação de requisitos e desenho da arquitetura da solução**
- [ ] Elaborar Documento de Especificação de Requisitos de Software (ERS) cobrindo:
  - [ ] Cadastro de empreendedores / perfis de usuários (comprador, vendedor, administrador, membro do comitê)
  - [ ] Catálogo digital (vitrine virtual)
  - [ ] Sistema de pedidos e carrinho de compras
  - [ ] Integração com pagamentos digitais (Pix, Cartão, Boleto)
  - [ ] Ferramenta de gestão para o empreendedor (estoque, vendas, relatórios)
  - [ ] Área comunitária (trocas, fóruns, rede social interna)
  - [ ] Painel de governança para monitoramento coletivo e autogestão
- [ ] Definir arquitetura tecnológica final (WordPress + WooCommerce + plugin de marketplace + BuddyPress) e justificar viabilidade técnica
- [ ] Mapear plugins/extensões necessários e avaliar licenciamento (compatibilidade com GPL/código aberto)
- [ ] Definir modelo de dados (usuários, produtos, pedidos, transações, fóruns, votações)
- [ ] Definir estratégia de hospedagem, ambientes (dev/staging/produção) e política de backups

### Fase 2 — Design de Interface (Mês 3–4)

**2.4 Design de interface (UX/UI), protótipos e validação participativa**
- [ ] Aplicar identidade visual (cores, Poppins, Thoge, logos) em guia de estilo / design system básico
- [ ] Wireframes de baixa fidelidade das telas principais (home, vitrine, produto, carrinho, checkout, painel do vendedor, área comunitária, painel de governança)
- [ ] Protótipos navegáveis de alta fidelidade (Figma ou similar)
- [ ] Validação dos protótipos com beneficiários (rodada de testes de usabilidade participativos)
- [ ] Revisão de acessibilidade dos protótipos (contraste de cores da paleta, tamanho de fonte, hierarquia visual) conforme WCAG 2.1
- [ ] Ajustes de design com base no feedback coletado
- [ ] Planejar responsividade (mobile, tablet, desktop)

### Fase 3 — Desenvolvimento Core (Mês 4–5)

**2.5 Desenvolvimento dos módulos principais**
- [ ] Instalação e configuração base do WordPress + WooCommerce
- [ ] Implementação do tema/child theme com a identidade visual (cores, tipografia Poppins/Thoge, logos)
- [ ] Instalação e configuração do plugin de marketplace multi-vendedor (cadastro de lojas, aprovação de vendedores, comissionamento)
- [ ] Módulo de cadastro e autenticação de usuários (compradores, vendedores, perfis diferenciados)
- [ ] Módulo de vitrine/catálogo digital (categorias, busca, filtros)
- [ ] Módulo de pedidos e carrinho de compras
- [ ] Integração dos gateways de pagamento (Pix, Cartão de Crédito, Boleto Bancário)
- [ ] Painel de gestão do empreendedor (estoque, vendas, relatórios básicos)
- [ ] Instalação e configuração do BuddyPress (perfis sociais, grupos, atividades, fóruns/bbPress) para o módulo de comunidade
- [ ] Estrutura inicial do módulo de comunicação/chat/fóruns entre empreendedores
- [ ] Implementação de textos e navegação em linguagem simples e inclusiva (abordagem GDI)
- [ ] Testes de acessibilidade WCAG 2.1 nos componentes desenvolvidos (leitores de tela, navegação por teclado, contraste)

### Fase 4 — Infraestrutura e Segurança (Mês 5)

**2.6 Configuração da infraestrutura de hospedagem e segurança**
- [ ] Provisionamento do ambiente de hospedagem em nuvem escalável (AWS/Azure/DigitalOcean)
- [ ] Configuração de certificado SSL/HTTPS
- [ ] Implementação de criptografia de dados sensíveis (em trânsito e em repouso)
- [ ] Configuração de backups automáticos (rotina e retenção definidas)
- [ ] Implementação de controle de acessos e permissões (RBAC) por perfil de usuário
- [ ] Hardening básico do WordPress (atualizações, plugins de segurança, proteção contra brute-force)
- [ ] Elaboração de política de privacidade e termos de uso alinhados à LGPD
- [ ] Configuração de monitoramento de performance e disponibilidade (uptime, tempo de resposta)

### Fase 5 — Testes e Homologação (Mês 5–6)

**2.7 Testes técnicos e homologação com grupo piloto**
- [ ] Testes funcionais dos módulos (cadastro, vitrine, pedidos, pagamentos, comunidade)
- [ ] Testes de usabilidade com grupo piloto de empreendedores(as)
- [ ] Testes de segurança (verificação de vulnerabilidades básicas, validação de LGPD)
- [ ] Testes de responsividade em diferentes dispositivos (mobile/tablet/desktop)
- [ ] Testes de carga/performance básicos
- [ ] Coleta estruturada de feedback do piloto (formulários/entrevistas)

**2.8 Ajustes pós-piloto e preparação para lançamento**
- [ ] Priorização e correção dos problemas identificados no piloto
- [ ] Ajustes de UX/UI com base no feedback real de uso
- [ ] Revalidação dos ajustes com o grupo piloto (quando aplicável)
- [ ] Checklist final de prontidão para lançamento

### Fase 6 — Documentação e Publicação em Código Aberto (Mês 6)

**2.9 Documentação técnica, manual do usuário e publicação do código**
- [ ] Redigir documentação técnica (arquitetura, instalação, configuração, dependências)
- [ ] Redigir manual do usuário em linguagem acessível (para empreendedores e compradores)
- [ ] Definir e aplicar a licença livre ao repositório (GPL ou MIT)
- [ ] Publicar o código-fonte em repositório aberto (GitHub) com histórico de commits organizado
- [ ] README completo com instruções de instalação/contribuição
- [ ] Validar transparência do repositório para futuras manutenções (verificação em GitHub/GitLab conforme exigido pelo Plano de Monitoramento)

### Fase 7 — Governança Digital (Mês 6–8)

**2.10 Definição participativa da política de funcionamento da plataforma**
- [ ] Facilitar processo participativo para definição de: regras de precificação, modelo de faturamento/cobrança coletiva, critérios de entrada/saída de vendedores, normas de uso e convivência digital
- [ ] Redigir e publicar regulamento da plataforma (versão final)

**2.11 Implementação dos mecanismos de governança digital**
- [ ] Desenvolver/configurar painel de governança com dados financeiros anonimizados (transparência)
- [ ] Implementar sistema de votação (plugin ou módulo customizado) para decisões coletivas
- [ ] Estruturar e formalizar o comitê de usuários (papéis, permissões de acesso ao painel)
- [ ] Testar fluxo completo de proposta → votação → resultado

### Fase 8 — Suporte e Evolução (Mês 8–12)

**2.12 Suporte técnico e manutenção evolutiva**
- [ ] Estruturar canal/sistema de chamados de suporte técnico
- [ ] Monitoramento contínuo de desempenho (tempo de resposta, disponibilidade, acessos, transações) — relatórios mensais
- [ ] Rotina de correção de erros e atualizações de segurança
- [ ] Backlog de manutenção evolutiva com base no uso real
- [ ] Elaborar plano de transição tecnológica para autogestão da plataforma pelos empreendedores após o fim do projeto

---

## 5. Requisitos transversais (aplicam-se a todas as fases)

- [ ] **Acessibilidade WCAG 2.1**: revisão contínua a cada entrega de módulo, não apenas no final
- [ ] **LGPD**: minimização de dados coletados, consentimento explícito, direito de exclusão/portabilidade
- [ ] **Abordagem GDI**: linguagem inclusiva, imagens representativas dos grupos vulneráveis priorizados, fluxos simplificados para baixa literacia digital
- [ ] **Código aberto**: commits organizados e regulares desde o início (não apenas na publicação final), para permitir rastreabilidade exigida pelo Plano de Monitoramento
- [ ] **Identidade visual**: aplicação consistente da paleta (`#31BEB1`, `#CF6442`, `#F1BF3D`, `#663191`) e tipografia (Poppins/Thoge) em todas as telas

## 6. Critérios de "pronto para validação" (piloto)

A plataforma estará pronta para o piloto (Atividade 2.7) quando:
1. Módulos principais (cadastro, vitrine, pedidos, pagamentos, painel do vendedor, comunidade) estiverem funcionais em ambiente de homologação;
2. Infraestrutura de hospedagem, backups e segurança/LGPD estiverem configurados (Atividade 2.6 concluída);
3. Identidade visual estiver aplicada de forma consistente;
4. Testes de acessibilidade básicos (WCAG 2.1) tiverem sido executados nos fluxos críticos (cadastro, compra, venda);
5. Ambiente estiver pronto para receber o grupo piloto de empreendedores(as) com dados de teste e suporte técnico disponível durante o piloto.

## 7. Referências de prazo (cronograma oficial do edital)

| Atividade | Entregável | Mês |
|---|---|---|
| 2.1 | Contrato assinado e registrado | 2 |
| 2.2 | Oficinas de requisitos realizadas | 2 |
| 2.3 | ERS e arquitetura validados | 3–4 |
| 2.4 | Protótipos testados com beneficiários | 4–5 |
| 2.5 | Módulos básicos programados | 5–6 |
| 2.6 | Infraestrutura configurada (LGPD, backups, criptografia) | 6 |
| 2.7 | Testes/homologação com piloto | 7–8 |
| 2.8 | Ajustes pós-piloto | — |
| 2.9 | Código publicado em repositório aberto + manual do usuário | 9 |
| 2.10 | Política de funcionamento definida e publicada | 9–10 |
| 2.11 | Comitê de governança digital implementado | 10 |
| 2.12 | Suporte técnico e manutenção contínua | 9–12 |

> Nota: o cronograma oficial do edital usa uma numeração de meses de execução do projeto como um todo (15 meses totais, Resultado 1 iniciando no Mês 1). Os prazos acima referem-se a essa linha do tempo do projeto, e não necessariamente coincidem em números absolutos com o cronograma de 6 meses apresentado na Proposta Comercial — este último deve ser tratado como o ritmo de execução técnica *dentro* da janela definida pelo edital para o Resultado 2.
