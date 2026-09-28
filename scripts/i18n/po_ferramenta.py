#!/usr/bin/env python3
"""
Ferramenta de apoio à tradução de catálogos gettext (.po).

Escrita para o catálogo do Dokan Lite, que tem 3.736 entradas — entre elas 12
com forma plural e 44 com contexto (`msgctxt`). Um `sed` sobre as linhas
quebraria os três casos que importam: strings multilinha, plurais com
`msgstr[0]`/`msgstr[1]` e entradas que só se distinguem pelo contexto. Por isso
o parse é por bloco, e a chave de cada entrada carrega o contexto junto.

Não usa `polib` de propósito: a biblioteca não está instalada no host e o
projeto não tem etapa de build. Python puro roda em qualquer lugar.

Dois modos:

    python3 po_ferramenta.py extrair <entrada.po> <entradas.json>
    python3 po_ferramenta.py aplicar <entrada.po> <saida.po> <chaves1.json> [chaves2.json ...]

`aplicar` preserva comentários, referências, o cabeçalho e a ordem original —
o .po resultante é sempre reimportável no Loco Translate, mesmo parcial.
"""

import json
import re
import sys


def desescapar(texto):
    """Converte a forma escapada do .po para o texto real."""
    saida = []
    i = 0
    while i < len(texto):
        if texto[i] == '\\' and i + 1 < len(texto):
            proximo = texto[i + 1]
            mapa = {'n': '\n', 't': '\t', 'r': '\r', '"': '"', '\\': '\\'}
            saida.append(mapa.get(proximo, '\\' + proximo))
            i += 2
        else:
            saida.append(texto[i])
            i += 1
    return ''.join(saida)


def escapar(texto):
    """Converte o texto real para a forma escapada do .po."""
    return (texto.replace('\\', '\\\\')
                 .replace('"', '\\"')
                 .replace('\n', '\\n')
                 .replace('\t', '\\t'))


def concatenar(linhas):
    """Junta as partes entre aspas de um campo multilinha do .po."""
    partes = []
    for linha in linhas:
        for achado in re.findall(r'"((?:[^"\\]|\\.)*)"', linha):
            partes.append(achado)
    return desescapar(''.join(partes))


def formatar_campo(nome, valor):
    """
    Emite um campo do .po.

    Texto com quebra de linha vira bloco multilinha com `msgid ""` na primeira
    linha, que é a convenção do gettext e o que o Loco espera ao reimportar.
    """
    if '\n' in valor:
        linhas = [f'{nome} ""']
        partes = valor.split('\n')
        for indice, parte in enumerate(partes):
            if indice < len(partes) - 1:
                linhas.append(f'"{escapar(parte)}\\n"')
            elif parte:
                linhas.append(f'"{escapar(parte)}"')
        return '\n'.join(linhas)
    return f'{nome} "{escapar(valor)}"'


def analisar(caminho):
    """Lê o .po e devolve a lista de blocos com seus campos."""
    bruto = open(caminho, encoding='utf-8').read()
    blocos = []
    for cru in bruto.split('\n\n'):
        if not cru.strip():
            continue
        comentarios = []
        campos = {}
        atual = None
        acumulado = []

        def fechar():
            if atual:
                campos[atual] = concatenar(acumulado)

        for linha in cru.split('\n'):
            if linha.startswith('#'):
                comentarios.append(linha)
                continue
            cabeca = re.match(r'^(msgctxt|msgid_plural|msgid|msgstr(?:\[\d+\])?)\s', linha)
            if cabeca:
                fechar()
                atual = cabeca.group(1)
                acumulado = [linha[len(atual):]]
            elif linha.startswith('"') and atual:
                acumulado.append(linha)
        fechar()
        blocos.append({'comentarios': comentarios, 'campos': campos, 'cru': cru})
    return blocos


def chave_de(campos):
    """Chave única da entrada: contexto e original, quando há contexto."""
    msgid = campos.get('msgid', '')
    contexto = campos.get('msgctxt')
    return f'{contexto}\x04{msgid}' if contexto else msgid


def extrair(caminho_po, caminho_json):
    """Despeja as entradas do .po em JSON, para tradução em lotes."""
    blocos = analisar(caminho_po)
    entradas = []
    for bloco in blocos:
        campos = bloco['campos']
        msgid = campos.get('msgid', '')
        if not msgid:
            continue  # cabeçalho
        registro = {'chave': chave_de(campos), 'msgid': msgid}
        if 'msgctxt' in campos:
            registro['msgctxt'] = campos['msgctxt']
        if 'msgid_plural' in campos:
            registro['msgid_plural'] = campos['msgid_plural']
        # Referências ajudam a decidir o registro: painel do vendedor e painel
        # administrativo pedem tons diferentes.
        refs = ' '.join(c for c in bloco['comentarios'] if c.startswith('#:'))
        registro['ref'] = refs.replace('#:', '').strip()[:160]
        entradas.append(registro)
    json.dump(entradas, open(caminho_json, 'w', encoding='utf-8'),
              ensure_ascii=False, indent=1)
    print(f'{len(entradas)} entradas extraídas para {caminho_json}')


def aplicar(caminho_po, caminho_saida, caminhos_json):
    """
    Reescreve o .po inserindo as traduções dos JSONs informados.

    A ordem dos parâmetros acompanha a da linha de comando (entrada, saída,
    lotes) de propósito: a assinatura anterior trazia a saída por último e já
    provocou uma inversão silenciosa entre o .po de origem e o de destino.
    """
    traducoes = {}
    for caminho in caminhos_json:
        try:
            dados = json.load(open(caminho, encoding='utf-8'))
        except FileNotFoundError:
            print(f'aviso: {caminho} não existe, ignorado')
            continue
        traducoes.update(dados)

    blocos = analisar(caminho_po)
    saida = []
    aplicadas = 0
    pendentes = 0

    for bloco in blocos:
        campos = bloco['campos']
        msgid = campos.get('msgid', '')

        if not msgid:
            saida.append(bloco['cru'])  # cabeçalho intacto
            continue

        chave = chave_de(campos)
        valor = traducoes.get(chave)

        linhas = list(bloco['comentarios'])
        if 'msgctxt' in campos:
            linhas.append(formatar_campo('msgctxt', campos['msgctxt']))
        linhas.append(formatar_campo('msgid', msgid))

        if 'msgid_plural' in campos:
            linhas.append(formatar_campo('msgid_plural', campos['msgid_plural']))
            if isinstance(valor, list) and len(valor) == 2:
                linhas.append(formatar_campo('msgstr[0]', valor[0]))
                linhas.append(formatar_campo('msgstr[1]', valor[1]))
                aplicadas += 1
            else:
                linhas.append('msgstr[0] ""')
                linhas.append('msgstr[1] ""')
                pendentes += 1
        else:
            if isinstance(valor, str) and valor:
                linhas.append(formatar_campo('msgstr', valor))
                aplicadas += 1
            else:
                linhas.append('msgstr ""')
                pendentes += 1

        saida.append('\n'.join(linhas))

    open(caminho_saida, 'w', encoding='utf-8').write('\n\n'.join(saida) + '\n')
    print(f'aplicadas: {aplicadas}   pendentes: {pendentes}   -> {caminho_saida}')


if __name__ == '__main__':
    if len(sys.argv) < 4:
        print(__doc__.strip())
        sys.exit(1)

    modo = sys.argv[1]
    if modo == 'extrair':
        extrair(sys.argv[2], sys.argv[3])
    elif modo == 'aplicar':
        if len(sys.argv) < 5:
            print('uso: aplicar <entrada.po> <saida.po> <chaves1.json> [...]')
            sys.exit(1)
        aplicar(sys.argv[2], sys.argv[3], sys.argv[4:])
    else:
        print(f'modo desconhecido: {modo}')
        sys.exit(1)
