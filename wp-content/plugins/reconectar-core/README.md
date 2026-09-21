# Reconectar Core

Plugin autoral da plataforma Reconectar — módulo de **Governança Digital**
(Atividade 2.11 do edital FUNDEPES/UNOPS).

## Status: MVP/esqueleto

Este plugin implementa apenas a **estrutura de dados** do módulo de
governança digital:

- Custom Post Type `proposta_votacao` ("Proposta de Votação"), com título,
  editor (descrição da proposta) e resumo.
- Post meta `_reconectar_votos_favor` e `_reconectar_votos_contra`, expostos
  via REST API.
- Shortcode `[reconectar_painel_transparencia]`, que lista as propostas
  publicadas e exibe o placar de votos em modo somente leitura.

## O que ainda não está implementado

A **interface de votação em si** (quem pode votar, um voto por
beneficiário, prazo de votação, autenticação) depende das **regras de
funcionamento da plataforma**, que precisam ser definidas em processo
participativo com os beneficiários (Atividade 2.10 do edital, ainda
pendente). Este esqueleto existe para que a estrutura técnica já esteja
pronta assim que essas regras forem definidas.

## Uso

1. Ative o plugin (`wp plugin activate reconectar-core` ou pelo painel do
   WordPress).
2. Crie posts do tipo "Proposta de Votação" no menu lateral do admin.
3. Insira o shortcode `[reconectar_painel_transparencia]` em qualquer
   página ou post para exibir o painel público.

## Licença

GPL-2.0-or-later, compatível com o núcleo do WordPress e com o WooCommerce.
