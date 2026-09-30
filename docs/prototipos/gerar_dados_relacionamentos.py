"""Gera o conjunto de dados do protótipo do mapa de relacionamentos a partir do dicionário real
(docs/dicionario_modelo.csv) e das referências lidas na documentação. Valida que toda coluna citada existe."""
import csv, json, sys

CSV = '../dicionario_modelo.csv'
cols = {}
for r in csv.DictReader(open(CSV, encoding='utf-8-sig'), delimiter=';'):
    cols.setdefault(r['tabela'].upper(), []).append((r['campo'], r['tipo'], int(r['tamanho'] or 0)))

def tipo(t, n):
    return f'{t}({n})' if t in ('char', 'varchar', 'nchar', 'nvarchar') else t

# descrições: as da documentação técnica (nome de tabela real); "" = sem documentação
DESC = {
 'MTTBCON': 'Tabela de Contratos', 'MTTBSE1': 'Tabela de Ficha Socio Economica', 'MTTBNUC': 'Tabela de Núcleos Habitacionais',
 'MTTBSCT': 'Tabela de Situações do Contrato', 'MTTBMUN': 'Tabela de Códigos dos Municipios (BNH)',
 'MTTBCTF': 'Tabela de Categorias de Financiamento', 'MTTBMOD': 'Tabela de Modalidades de Financiamento',
 'MTTBPPF': 'Tabela de Parâmetros dos Planos de Financiamento', 'MTTBPSE': 'Tabela de Planos de Seguros',
 'MTTBPRO': 'Tabela de Profissões', 'MTTBEMP': 'Cadastro de Empresas', 'MTTBSEG': 'Tabela de Segurança (Usuários)',
 'MTTBLURA': '', 'MTTBCASS': '', 'MTTBPEMP': '',
 'MTTBHIS': 'Tabela de Históricos das Renegociações', 'MTTBEVM': 'Tabela de Eventos Mensais', 'MTTBREC': 'Tabela de Recibos Emitidos',
 'MTTBADQ': 'Tabela de Adquirentes', 'MTTBCOP': 'Processo do Contrato', 'MTTBCOF': 'Tabela de Valores do Financiamento do Contrato (Contratação)',
 'MTTBBXA': 'Tabela de Baixas', 'MTTBARR': 'Tabela de Movimento de Arrecadação', 'MTTBCSG': 'Tabela Consolidado Geral',
 'MTTBLCT': 'Tabela de Lançamentos Contábeis',
}
tabelas = {}
for t, d in DESC.items():
    assert t in cols, t
    tabelas[t] = {'d': d, 'n': len(cols[t]), 'c': [[c, tipo(ty, n)] for c, ty, n in cols[t]]}

def canon(t, c):
    for x, ty, n in cols[t]:
        if x.upper() == c.upper():
            return x, tipo(ty, n)
    sys.exit(f'coluna inexistente: {t}.{c}')

def par(ta, ca, tb, cb):
    (a, ta_), (b, tb_) = canon(ta, ca), canon(tb, cb)
    return [a, ta_, b, tb_]

def rel(id, a, b, origem, estado, conf, evid, papeis, aviso=None, por=None):
    ps = [{'nome': nome, 'pares': [par(a, x, b, y) for x, y in pares], 'fonte': fonte} for nome, pares, fonte in papeis]
    r = {'id': id, 'a': a, 'b': b, 'origem': origem, 'estado': estado, 'conf': conf, 'evid': evid, 'papeis': ps}
    if aviso: r['aviso'] = aviso
    if por: r['por'] = por
    return r

K4 = [('CODEMP', 'CODEMP'), ('REGIAO', 'REGIAO'), ('NUCLEO', 'NUCLEO'), ('CONTRATO', 'CONTRATO')]
EV_CHAVE = 'Tem as 4 colunas-chave do contrato (CODEMP, REGIAO, NUCLEO, CONTRATO) com os mesmos tipos de MTTBCON. 177 tabelas do seu banco seguem esse padrão.'
rels = []
n = 0
def add(*a, **k):
    global n
    n += 1
    rels.append(rel(f'r{n}', *a, **k))

# --- filhas do contrato, pela chave composta ---
estados = {'MTTBCOP': 'confirmada', 'MTTBADQ': 'confirmada', 'MTTBEVM': 'confirmada'}
for t in ['MTTBHIS', 'MTTBEVM', 'MTTBREC', 'MTTBADQ', 'MTTBCOP', 'MTTBCOF', 'MTTBBXA', 'MTTBARR', 'MTTBCSG', 'MTTBLCT']:
    add(t, 'MTTBCON', 'chave', estados.get(t, 'sugerida'), 'alta', EV_CHAVE, [('Contrato', K4, 'Chave composta')])

# --- referências escritas na documentação ---
doc = lambda txt: f'Documentação: “{txt}”'
add('MTTBCON', 'MTTBSE1', 'doc', 'sugerida', 'alta', 'A documentação de MTTBCON cita a tabela MTTBSE1 em quatro campos, um para cada adquirente.', [
    ('Adquirente 1', [('CODEMP', 'CODEMP'), ('ADQ1_CPFCGC', 'CGCCPF')], doc('CPF ou CGC do adquirente principal (Original) (Tabela MTTBSE1)')),
    ('Adquirente 2', [('CODEMP', 'CODEMP'), ('ADQ2_CPF', 'CGCCPF')], doc('CPF do segundo adquirente (Tabela MTTBSE1)')),
    ('Adquirente 3', [('CODEMP', 'CODEMP'), ('ADQ3_CPF', 'CGCCPF')], doc('CPF do terceiro adquirente (Tabela MTTBSE1)')),
    ('Adquirente 4', [('CODEMP', 'CODEMP'), ('ADQ4_CPF', 'CGCCPF')], doc('CPF do quarto adquirente (Tabela MTTBSE1)')),
], aviso='CPF (char 11) e CGCCPF (char 14) têm tamanhos diferentes. Confirme a regra de comparação (por exemplo, zeros à esquerda) antes de aprovar.')
add('MTTBHIS', 'MTTBSE1', 'doc', 'sugerida', 'alta', 'A documentação de MTTBHIS cita a tabela MTTBSE1 nos campos dos adquirentes (novo número).', [
    ('Adquirente 1', [('CODEMP', 'CODEMP'), ('AD1_CGCCPF', 'CGCCPF')], doc('Novo numero do CNPJ/CPF do Adquirente Principal (Tabela MTTBSE1)')),
    ('Adquirente 2', [('CODEMP', 'CODEMP'), ('AD2_CPF', 'CGCCPF')], doc('Novo numero do CPF do Adquirente 2 (Tabela MTTBSE1)')),
], aviso='CPF (char 11) e CGCCPF (char 14) têm tamanhos diferentes. Confirme a regra de comparação antes de aprovar.')
add('MTTBCON', 'MTTBNUC', 'doc', 'confirmada', 'alta', 'A documentação cita a tabela MTTBNUC; o núcleo se completa com empresa e região.', [
    ('Núcleo', [('CODEMP', 'CODEMP'), ('REGIAO', 'REGIAO'), ('NUCLEO', 'CODIGO')], doc('Código do Núcleo Habitacional (Tabela MTTBNUC)'))], por='Aprovada por você')
add('MTTBCON', 'MTTBSCT', 'doc', 'sugerida', 'alta', 'A documentação cita a tabela MTTBSCT em cinco campos de situação do contrato.', [
    (f'Situação {i}', [('CODEMP', 'CODEMP'), (f'CTR_STC_COD{i}', 'CODIGO')], doc(f'Situação do Contrato: Código Situação {i} (Tabela MTTBSCT)') if i > 1 else doc('Situação do Contrato: Código Situação 1 (Na empresa de retorno do FCVS … Tabela MTTBSCT)')) for i in range(1, 6)],
    aviso='CTR_STC_COD1..5 são char(3) e MTTBSCT.Codigo é int. O gerador vai precisar converter o tipo no JOIN.')
add('MTTBCON', 'MTTBMUN', 'doc', 'sugerida', 'alta', 'A documentação cita a tabela MTTBMUN. O código do município é o do BNH.', [
    ('Município', [('CODMUN_BNH', 'COD_BNH')], doc('Código do Município (Tabela do BNH) (Tabela MTTBMUN)'))])
add('MTTBCON', 'MTTBCTF', 'doc', 'sugerida', 'alta', 'A documentação cita a tabela MTTBCTF.', [
    ('Categoria', [('CODEMP', 'CODEMP'), ('CATEGORIA', 'CODCTF')], doc('Código da Categoria do Financiamento (Tabela MTTBCTF)'))])
add('MTTBCON', 'MTTBMOD', 'doc', 'sugerida', 'alta', 'A documentação cita a tabela MTTBMOD.', [
    ('Modalidade', [('CODEMP', 'CODEMP'), ('MODALIDADE', 'CODMOD')], doc('Código da Modalidade do Financiamento (Tabela MTTBMOD)'))])
add('MTTBCON', 'MTTBPPF', 'doc', 'sugerida', 'alta', 'A documentação cita a tabela MTTBPPF no plano original e no plano atual.', [
    ('Plano original', [('CODEMP', 'CODEMP'), ('PLANO_FIN', 'CODIGO_PLANO')], doc('Plano/Sistema de financiamento (Tabela MTTBPPF)')),
    ('Plano atual', [('CODEMP', 'CODEMP'), ('DATU_PLANO', 'CODIGO_PLANO')], doc('Dados Atuais: Plano/Sistema (Tabela MTTBPPF)'))])
add('MTTBCON', 'MTTBPSE', 'doc', 'sugerida', 'media', 'A documentação cita a tabela MTTBPSE só pelo plano. A chave de MTTBPSE tem 3 colunas; CODIGO_SEG completa o par de CODSEG pelo nome parecido.', [
    ('Plano original', [('CODEMP', 'CODEMP'), ('CODIGO_SEG', 'CODSEG'), ('PLANO_SEG', 'PLANO_SEG')], doc('Plano da Seguradora (Tabela MTTBPSE)')),
    ('Plano atual', [('CODEMP', 'CODEMP'), ('DATU_COD_SEG', 'CODSEG'), ('DATU_PLA_SEG', 'PLANO_SEG')], doc('Dados Atuais: Plano da Seguradora (Tabela MTTBPSE)'))],
    aviso='O par de CODSEG foi deduzido pelo nome. Confira antes de aprovar.')
add('MTTBSE1', 'MTTBPRO', 'doc', 'sugerida', 'alta', 'A documentação de MTTBSE1 cita a tabela MTTBPRO nos campos de atividade profissional.', [
    ('Titular', [('ATVPRO', 'COD_PROF')], doc('ATIVIDADE PROFISSIONAL ou RAMO DE ATIVIDADE …')),
    ('Cônjuge', [('CONJTIT_ATVPRO', 'COD_PROF')], doc('Atividade Profissional do Cônjuge (PF) ou Titular (PJ)'))])
# --- pelo nome e tipo ---
add('MTTBCON', 'MTTBEMP', 'nome', 'sugerida', 'media', 'Mesmo nome e mesmo tipo de coluna, e MTTBEMP é o cadastro de empresas. CODEMP existe em 357 tabelas.', [
    ('Empresa', [('CODEMP', 'CODEMP')], 'Nome e tipo iguais')])
# --- manual ---
add('MTTBCON', 'MTTBSE1', 'manual', 'confirmada', 'alta', 'Ligação criada por você: o contrato de gaveta guarda o CPF de quem ocupa o imóvel.', [
    ('Contrato de gaveta', [('CODEMP', 'CODEMP'), ('CPF_GAVETA', 'CGCCPF')], 'Criada à mão')], por='Criada por você',
    aviso='CPF_GAVETA é char(11) e CGCCPF é char(14).')
# --- FKs reais ---
add('MTTBLURA', 'MTTBSEG', 'fk', 'confirmada', 'alta', 'Chave estrangeira declarada no banco.', [('FK declarada', [('fk_codemp_seg', 'CodEmp'), ('fk_codusr_seg', 'CodUsr')], 'FOREIGN KEY no SQL Server')])
add('MTTBLURA', 'MTTBCASS', 'fk', 'confirmada', 'alta', 'Chave estrangeira declarada no banco.', [('FK declarada', [('fk_codigo_ass', 'codigo')], 'FOREIGN KEY no SQL Server')])
add('MTTBPEMP', 'MTTBEMP', 'fk', 'confirmada', 'alta', 'Chave estrangeira declarada no banco.', [('FK declarada', [('fk_codemp_emp', 'CODEMP')], 'FOREIGN KEY no SQL Server')])

open('relacionamentos_dados.json', 'w', encoding='utf-8').write(json.dumps({'tabelas': tabelas, 'relacoes': rels}, ensure_ascii=False, separators=(',', ':')))
print(len(tabelas), 'tabelas,', len(rels), 'relações;', sum(len(p['pares']) for r in rels for p in r['papeis']), 'pares de colunas validados')
