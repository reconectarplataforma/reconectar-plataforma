#!/usr/bin/env python3
"""
Seleção de entradas para tradução em lotes.

A tradução do catálogo do Dokan Lite é feita por superfície visível, não em
ordem de arquivo: primeiro o que vendedor e cliente veem (templates e painel do
vendedor), depois o JavaScript, por último o painel administrativo. Isso faz
com que cada lote seja um conjunto de índices esparsos, e não um intervalo —
daí o modo `filtrar`, que monta o lote a partir das referências `#:` do .po.

Os lotes já gravados em `lotes/` são lidos a cada execução para que nenhuma
entrada seja oferecida duas vezes.

    python3 lote.py status
    python3 lote.py etapa <B1|B2|B3> [limite]
    python3 lote.py filtrar '<regex sobre a referência>' [limite]
    python3 lote.py mostrar <inicio> <fim>
    python3 lote.py indices <i1> <i2> ...
    python3 lote.py gravar <indices.json> <chaves.json>

`indices.json` é o arquivo que a tradução produz: {"<índice>": "<tradução>"}.
`gravar` o converte para {"<chave>": "<tradução>"}, que é o que
`po_ferramenta.py aplicar` consome — a chave carrega o `msgctxt` quando existe,
e por isso não dá para usar o msgid cru.
"""

import json
import os
import re
import sys

AQUI = os.path.dirname(os.path.abspath(__file__))
ENTRADAS = os.path.join(AQUI, 'po_entradas.json')
LOTES = os.path.join(AQUI, 'lotes')

E = json.load(open(ENTRADAS, encoding='utf-8'))

# As três etapas da tradução, em ordem de prioridade — e a ordem importa duas
# vezes. Ela decide o que é traduzido primeiro (a superfície que vendedor e
# cliente veem) e também a que etapa cada entrada pertence: a primeira que casa
# vence, o que torna a lista uma partição em vez de quatro conjuntos que se
# sobrepõem. Sem isso um arquivo em `includes/Admin/` contaria duas vezes, e o
# total das etapas passaria do total do catálogo.
#
# B2 vem antes de B1 na checagem, e não por engano: um `.js` pode morar em
# `templates/` ou em `includes/Vendor/`, e traduzir JavaScript exige gerar os
# .json de script — é outro mecanismo, não outra pasta.
ETAPAS = [
    ('B2', r'\.js|\.vue', 'javascript (painel Vue do vendedor)'),
    ('B1', r'templates/|includes/(Vendor|Order|Product|ProductEditor|Withdraw'
           r'|ReverseWithdrawal|Commission|Emails|functions|Assets)',
     'templates e painel do vendedor'),
    ('B3', r'', 'REST, administração e o restante'),
]


def etapa_de(indice):
    """A que etapa uma entrada pertence — a primeira cujo padrão casa."""
    ref = E[indice].get('ref', '')
    for nome, padrao, _ in ETAPAS:
        if not padrao or re.search(padrao, ref):
            return nome
    return 'B3'


def traduzidas():
    """Índices já cobertos por algum lote em `lotes/`."""
    feitas = set()
    if not os.path.isdir(LOTES):
        return feitas
    for nome in sorted(os.listdir(LOTES)):
        if not nome.endswith('.json'):
            continue
        dados = json.load(open(os.path.join(LOTES, nome), encoding='utf-8'))
        for chave in dados:
            feitas.add(int(chave))
    return feitas


FEITAS = traduzidas()


def linha(i):
    """Uma entrada no formato de trabalho: índice, original e marcações."""
    x = E[i]
    extra = ''
    if 'msgctxt' in x:
        extra += f"  [ctx:{x['msgctxt']}]"
    if 'msgid_plural' in x:
        extra += f"  [PLURAL: {x['msgid_plural']}]"
    return f"{i}\t{x['msgid']}{extra}"


def mostrar(ini, fim):
    """Imprime uma faixa contígua de entradas."""
    for i in range(ini, min(fim, len(E))):
        print(linha(i))


def indices(lista):
    """Imprime entradas avulsas, na ordem informada."""
    for i in lista:
        print(linha(int(i)))


def filtrar(padrao, limite=None):
    """
    Imprime as entradas ainda não traduzidas cuja referência casa com o padrão.

    A referência é o caminho do arquivo de origem gravado pelo gettext, o que
    permite separar superfícies: `templates/` é o que o visitante vê,
    `\\.js|\\.vue` é o painel Vue do vendedor, `Admin/` é o painel do
    administrador.
    """
    regex = re.compile(padrao)
    achados = 0
    for i, x in enumerate(E):
        if i in FEITAS or not regex.search(x.get('ref', '')):
            continue
        print(linha(i))
        achados += 1
        if limite and achados >= limite:
            break
    print(f'--- {achados} entradas', file=sys.stderr)


def etapa(nome, limite=None):
    """Imprime as entradas pendentes de uma etapa, na ordem do catálogo."""
    achados = 0
    for i in range(len(E)):
        if i in FEITAS or etapa_de(i) != nome:
            continue
        print(linha(i))
        achados += 1
        if limite and achados >= limite:
            break
    print(f'--- {achados} entradas de {nome}', file=sys.stderr)


def status():
    """Andamento por etapa. As etapas particionam o catálogo: os totais somam."""
    print(f'total: {len(E)}   traduzidas: {len(FEITAS)}   '
          f'pendentes: {len(E) - len(FEITAS)}\n')
    soma = 0
    for nome, _, descricao in ETAPAS:
        casam = [i for i in range(len(E)) if etapa_de(i) == nome]
        prontas = sum(1 for i in casam if i in FEITAS)
        soma += len(casam)
        print(f'  {nome}  {descricao:38} {prontas:5} / {len(casam)}')
    print(f'\n  soma das etapas: {soma}')


def gravar(caminho_indices, caminho_saida):
    """Converte {índice: tradução} em {chave: tradução}."""
    d = json.load(open(caminho_indices, encoding='utf-8'))
    out = {}
    for k, v in d.items():
        out[E[int(k)]['chave']] = v
    json.dump(out, open(caminho_saida, 'w', encoding='utf-8'),
              ensure_ascii=False, indent=1)
    print(f'{len(out)} traduções -> {caminho_saida}')


if __name__ == '__main__':
    if len(sys.argv) < 2:
        print(__doc__.strip())
        sys.exit(1)

    modo = sys.argv[1]
    if modo == 'status':
        status()
    elif modo == 'mostrar':
        mostrar(int(sys.argv[2]), int(sys.argv[3]))
    elif modo == 'indices':
        indices(sys.argv[2:])
    elif modo == 'etapa':
        etapa(sys.argv[2], int(sys.argv[3]) if len(sys.argv) > 3 else None)
    elif modo == 'filtrar':
        filtrar(sys.argv[2], int(sys.argv[3]) if len(sys.argv) > 3 else None)
    elif modo == 'gravar':
        gravar(sys.argv[2], sys.argv[3])
    else:
        print(f'modo desconhecido: {modo}')
        sys.exit(1)
