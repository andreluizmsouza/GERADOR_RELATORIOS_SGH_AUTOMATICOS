<?php

declare(strict_types=1);

// Testes dos relacionamentos: php tests/relacionamento_test.php  (requer pdo_sqlite e mbstring)
require __DIR__ . '/../src/Relacionamentos/Sugestor.php';
require __DIR__ . '/../src/Relacionamentos/RelacionamentoRepository.php';

use Elogica\Relacionamentos\RelacionamentoRepository;
use Elogica\Relacionamentos\Sugestor;

$falhas = 0;
$total = 0;
function check(string $nome, bool $ok): void
{
    global $falhas, $total;
    $total++;
    if (!$ok) {
        $falhas++;
        echo "FALHOU: {$nome}\n";
    }
}
/** @return array<string, mixed> */
function c(string $nome, string $tipo, string $desc = ''): array
{
    return ['c' => $nome, 't' => $tipo, 'd' => $desc];
}
function achar(array $rels, string $a, string $b, string $tipo): ?array
{
    foreach ($rels as $r) {
        if ($r['a'] === $a && $r['b'] === $b && $r['tipo'] === $tipo) {
            return $r;
        }
    }

    return null;
}
/** @return array<string, array{colunas: list<array<string, mixed>>}> */
function cenario(int $filhas): array
{
    $t = [
        'MTTBCON' => ['colunas' => [c('CODEMP', 'smallint'), c('REGIAO', 'smallint'), c('NUCLEO', 'smallint', 'Código do Núcleo Habitacional (Tabela MTTBNUC)'), c('CONTRATO', 'char(10)'),
            c('ADQ1_CPFCGC', 'char(14)', 'CPF ou CGC do adquirente principal (Tabela MTTBSE1)'), c('ADQ2_CPF', 'char(11)', 'CPF do segundo adquirente (Tabela MTTBSE1)'),
            c('CTR_STC_COD1', 'char(3)', 'Situação do Contrato: Código Situação 1 (Tabela MTTBSCT)'), c('CATEGORIA', 'smallint', 'Código da Categoria (Tabela MTTBCTF)'),
            c('FLG_X', 'char(1)', 'Flag de despesa (Tabela MTTBFAS)')]],
        'MTTBSE1' => ['colunas' => [c('CODEMP', 'smallint'), c('CGCCPF', 'char(14)'), c('FISJUR', 'smallint'), c('CPF_REP_LEGAL2', 'char(11)')]],
        'MTTBNUC' => ['colunas' => [c('CodEmp', 'smallint'), c('Regiao', 'smallint'), c('Codigo', 'smallint'), c('Nucleo', 'char(45)')]],
        'MTTBSCT' => ['colunas' => [c('CodEmp', 'smallint'), c('Codigo', 'int'), c('Descricao', 'char(50)')]],
        'MTTBCTF' => ['colunas' => [c('CODEMP', 'smallint'), c('CODCTF', 'smallint'), c('DESCRICAO', 'char(30)')]],
        'MTTBFAS' => ['colunas' => [c('CODEMP', 'smallint'), c('COD_FASE', 'smallint'), c('DESCRICAO', 'char(30)')]],
    ];
    for ($i = 1; $i <= $filhas; $i++) {
        $t[sprintf('MTTBF%02d', $i)] = ['colunas' => [c('CODEMP', 'smallint'), c('REGIAO', 'smallint'), c('NUCLEO', 'smallint'), c('CONTRATO', 'char(10)'), c('VALOR', 'float')]];
    }

    return $t;
}

// --- motor de sugestões ---
$tabs = cenario(25);
$rels = Sugestor::gerar($tabs, ['MTTBCON']);
$se1 = achar($rels, 'MTTBCON', 'MTTBSE1', 'doc');
check('doc: relação com SE1 encontrada com 2 papéis', $se1 !== null && count($se1['papeis']) === 2);
check('doc: chave comum + CPF ↔ CGCCPF', $se1 !== null && $se1['papeis'][0]['pares'] === [['CODEMP', 'CODEMP'], ['ADQ1_CPFCGC', 'CGCCPF']] && $se1['papeis'][1]['pares'][1] === ['ADQ2_CPF', 'CGCCPF']);
check('doc: CPF 11 x 14 gera aviso de tamanho', $se1 !== null && str_contains((string) $se1['aviso'], 'tamanhos diferentes') && !str_contains((string) $se1['aviso'], 'CPF_REP_LEGAL2'));
$nuc = achar($rels, 'MTTBCON', 'MTTBNUC', 'doc');
check('doc: NUCLEO liga em Codigo e não em Nucleo (nome)', $nuc !== null && $nuc['papeis'][0]['pares'] === [['CODEMP', 'CodEmp'], ['REGIAO', 'Regiao'], ['NUCLEO', 'Codigo']]);
$sct = achar($rels, 'MTTBCON', 'MTTBSCT', 'doc');
check('doc: char(3) x int gera aviso de tipos', $sct !== null && str_contains((string) $sct['aviso'], 'tipos diferentes') && $sct['papeis'][0]['pares'][1] === ['CTR_STC_COD1', 'Codigo']);
$ctf = achar($rels, 'MTTBCON', 'MTTBCTF', 'doc');
check('doc: par por posição e tipo fica em confiança média com aviso', $ctf !== null && $ctf['conf'] === 'media' && str_contains((string) $ctf['aviso'], 'só pela posição'));
$fas = achar($rels, 'MTTBCON', 'MTTBFAS', 'doc');
check('doc: palpite só por posição (FLG_X → COD_FASE) nunca sai como confiança alta', $fas === null || ($fas['conf'] === 'media' && str_contains((string) $fas['aviso'], 'só pela posição')));
check('doc: nenhuma relação consigo mesma', array_filter($rels, static fn (array $r): bool => $r['a'] === $r['b']) === []);

$ch = achar($rels, 'MTTBF07', 'MTTBCON', 'chave');
check('chave: filha carrega as 4 colunas da mãe', $ch !== null && $ch['conf'] === 'alta' && $ch['papeis'][0]['pares'] === [['CODEMP', 'CODEMP'], ['REGIAO', 'REGIAO'], ['NUCLEO', 'NUCLEO'], ['CONTRATO', 'CONTRATO']]);
check('chave: 25 filhas', count(array_filter($rels, static fn (array $r): bool => $r['tipo'] === 'chave')) === 25);
check('chave: a mãe não é filha de si mesma', achar($rels, 'MTTBCON', 'MTTBCON', 'chave') === null);
$sem = cenario(25);
$sem['MTTBF03']['colunas'][3] = c('CONTRATO', 'int');
check('chave: tipo diferente exclui a filha', achar(Sugestor::gerar($sem, ['MTTBCON']), 'MTTBF03', 'MTTBCON', 'chave') === null);
check('chave: suporte pequeno não gera nada', array_filter(Sugestor::gerar(cenario(5), ['MTTBCON']), static fn (array $r): bool => $r['tipo'] === 'chave') === []);
check('chave: sem tabela-mãe não gera chave', array_filter(Sugestor::gerar($tabs, []), static fn (array $r): bool => $r['tipo'] === 'chave') === []);
check('chave: mãe inexistente é ignorada', Sugestor::gerar($tabs, ['NAOEXISTE']) !== null);
check('determinístico', Sugestor::gerar($tabs, ['MTTBCON']) === Sugestor::gerar($tabs, ['MTTBCON']));

// --- repositório ---
$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$pdo->exec("ATTACH ':memory:' AS dbo");
$pdo->exec('PRAGMA foreign_keys = ON');
$pdo->exec("CREATE TABLE dbo.dic_tabela (id INTEGER PRIMARY KEY, tabela TEXT, descricao TEXT, situacao TEXT DEFAULT 'incluir', existe_no_banco INTEGER DEFAULT 1)");
$pdo->exec('CREATE TABLE dbo.dic_coluna (id INTEGER PRIMARY KEY, dic_tabela_id INTEGER, coluna TEXT, ordem INTEGER DEFAULT 0, tipo TEXT, tamanho INTEGER, descricao TEXT, existe_no_banco INTEGER DEFAULT 1)');
$pdo->exec('CREATE TABLE dbo.dic_config (chave TEXT PRIMARY KEY, valor TEXT)');
$pdo->exec('CREATE TABLE dbo.dic_relacao_tabela (id INTEGER PRIMARY KEY, origem_tabela_id INTEGER, destino_tabela_id INTEGER, tipo TEXT, estado TEXT, confianca TEXT, evidencia TEXT, aviso TEXT, criado_por INTEGER, criado_em TEXT, decidido_por INTEGER, decidido_em TEXT, UNIQUE (origem_tabela_id, destino_tabela_id, tipo))');
$pdo->exec('CREATE TABLE dbo.dic_relacao_papel (id INTEGER PRIMARY KEY, relacao_id INTEGER REFERENCES dic_relacao_tabela (id) ON DELETE CASCADE, nome TEXT, fonte TEXT, UNIQUE (relacao_id, nome))');
$pdo->exec('CREATE TABLE dbo.dic_relacao_par (id INTEGER PRIMARY KEY, papel_id INTEGER REFERENCES dic_relacao_papel (id) ON DELETE CASCADE, ordem INTEGER, origem_coluna_id INTEGER, destino_coluna_id INTEGER, UNIQUE (papel_id, origem_coluna_id, destino_coluna_id))');
foreach ($tabs as $nome => $t) {
    $pdo->prepare('INSERT INTO dbo.dic_tabela (tabela, descricao) VALUES (?, ?)')->execute([$nome, "Tabela {$nome}"]);
    $tid = (int) $pdo->lastInsertId();
    foreach ($t['colunas'] as $i => $col) {
        preg_match('/^(\w+)(?:\((\d+)\))?$/', $col['t'], $m);
        $pdo->prepare('INSERT INTO dbo.dic_coluna (dic_tabela_id, coluna, ordem, tipo, tamanho, descricao) VALUES (?, ?, ?, ?, ?, ?)')->execute([$tid, $col['c'], $i, $m[1], (int) ($m[2] ?? 0), $col['d']]);
    }
}
$repo = new RelacionamentoRepository($pdo);
$agora = '2026-09-30 10:00:00';

$entrada = $repo->tabelasParaSugestor();
check('entrada do motor tem tipos com tamanho e descrição', $entrada['MTTBCON']['colunas'][3]['t'] === 'char(10)' && str_contains($entrada['MTTBCON']['colunas'][2]['d'], 'MTTBNUC'));
$rels = Sugestor::gerar($entrada, ['MTTBCON']);
$r = $repo->inserirCandidatos($rels, null, $agora);
check('gravou as sugestões', $r['novas'] === count($rels) && $r['existentes'] === 0 && $r['ignoradas'] === 0);
check('todas entram como sugeridas', $repo->resumo() === ['confirmada' => 0, 'sugerida' => count($rels), 'rejeitada' => 0]);
$r2 = $repo->inserirCandidatos($rels, null, $agora);
check('gerar de novo não duplica', $r2['novas'] === 0 && $r2['existentes'] === count($rels));
check('pares gravados', (int) $pdo->query('SELECT COUNT(*) FROM dbo.dic_relacao_par')->fetchColumn() === array_sum(array_map(static fn (array $x): int => array_sum(array_map(static fn (array $p): int => count($p['pares']), $x['papeis'])), $rels)));

// mapa
$m1 = $repo->mapa('MTTBCON', 1);
check('mapa: foco e vizinhas', $m1 !== null && $m1['foco'] === 'MTTBCON' && isset($m1['tabelas']['MTTBSE1'], $m1['tabelas']['MTTBF01']) && $m1['tabelas']['MTTBCON']['r'] === 0);
$se1 = null;
foreach ($m1['relacoes'] as $x) {
    if ($x['b'] === 'MTTBSE1') {
        $se1 = $x;
    }
}
check('mapa: relação traz papéis, pares e tipos', $se1 !== null && count($se1['papeis']) === 2 && $se1['papeis'][0]['pares'][1] === ['ADQ1_CPFCGC', 'char(14)', 'CGCCPF', 'char(14)']);
check('mapa: tabela inexistente', $repo->mapa('NAOEXISTE', 1) === null);
$m2 = $repo->mapa('MTTBSE1', 2);
check('mapa: 2 saltos alcança as filhas de CON passando por CON', isset($m2['tabelas']['MTTBF01']) && $m2['tabelas']['MTTBF01']['r'] === 2);
check('mapa: 1 salto de SE1 não alcança as filhas', !isset($repo->mapa('MTTBSE1', 1)['tabelas']['MTTBF01']));

// estados
$id = $se1['id'];
check('aprovar', $repo->definirEstado($id, 'confirmada', 7, $agora));
$linha = $pdo->query("SELECT estado, decidido_por, decidido_em FROM dbo.dic_relacao_tabela WHERE id = {$id}")->fetch();
check('aprovar registra quem e quando', $linha['estado'] === 'confirmada' && (int) $linha['decidido_por'] === 7 && $linha['decidido_em'] === $agora);
$repo->definirEstado($id, 'sugerida', 7, $agora);
check('voltar a sugerida limpa a decisão', $pdo->query("SELECT decidido_por FROM dbo.dic_relacao_tabela WHERE id = {$id}")->fetchColumn() === null);
check('estado inválido é recusado', (static function () use ($repo, $id, $agora): bool { try { $repo->definirEstado($id, 'xx', 1, $agora); } catch (InvalidArgumentException) { return true; } return false; })());

$ids = array_map(static fn (array $x): int => $x['id'], $m1['relacoes']);
$seguros = count(array_filter($m1['relacoes'], static fn (array $x): bool => $x['estado'] === 'sugerida' && $x['conf'] === 'alta' && $x['aviso'] === null));
$n = $repo->aprovarLote($ids, 7, $agora);
check('lote aprova só alta e sem aviso (' . $seguros . ')', $n === $seguros && $n > 0);
check('lote não toca as com aviso ou média', (int) $pdo->query("SELECT COUNT(*) FROM dbo.dic_relacao_tabela WHERE estado = 'confirmada' AND (aviso IS NOT NULL OR confianca <> 'alta')")->fetchColumn() === 0);
check('lote repetido não aprova de novo', $repo->aprovarLote($ids, 7, $agora) === 0);

// manual
$mid = $repo->criarManual('MTTBCON', 'MTTBSE1', 'Contrato de gaveta', [['CODEMP', 'CODEMP'], ['ADQ2_CPF', 'CGCCPF']], 7, $agora);
check('manual entra confirmada', $pdo->query("SELECT estado FROM dbo.dic_relacao_tabela WHERE id = {$mid}")->fetchColumn() === 'confirmada' && $pdo->query("SELECT tipo FROM dbo.dic_relacao_tabela WHERE id = {$mid}")->fetchColumn() === 'manual');
$mid2 = $repo->criarManual('MTTBCON', 'MTTBSE1', 'Outro papel', [['CODEMP', 'CODEMP'], ['ADQ1_CPFCGC', 'CGCCPF']], 7, $agora);
check('segundo papel manual entra na mesma relação', $mid2 === $mid && (int) $pdo->query("SELECT COUNT(*) FROM dbo.dic_relacao_papel WHERE relacao_id = {$mid}")->fetchColumn() === 2);
foreach ([
    'mesma tabela' => ['MTTBCON', 'MTTBCON', [['CODEMP', 'CODEMP']]],
    'tabela inexistente' => ['MTTBCON', 'MTTBXXX', [['CODEMP', 'CODEMP']]],
    'coluna inexistente' => ['MTTBCON', 'MTTBSE1', [['NAO_EXISTE', 'CODEMP']]],
    'sem pares' => ['MTTBCON', 'MTTBSE1', []],
] as $nome => [$a, $b, $pares]) {
    $recusou = false;
    try {
        $repo->criarManual($a, $b, 'x', $pares, 7, $agora);
    } catch (InvalidArgumentException) {
        $recusou = true;
    }
    check("manual recusa: {$nome}", $recusou);
}
check('excluir só apaga manual', !$repo->excluirManual($ids[0]) && $repo->excluirManual($mid));
check('excluir manual leva papéis e pares', (int) $pdo->query("SELECT COUNT(*) FROM dbo.dic_relacao_tabela WHERE tipo = 'manual'")->fetchColumn() === 0 && (int) $pdo->query("SELECT COUNT(*) FROM dbo.dic_relacao_papel WHERE nome IN ('Contrato de gaveta', 'Outro papel')")->fetchColumn() === 0);

// edição de uma ligação sugerida
$ed = $repo->mapa('MTTBSE1', 1)['relacoes'];
$ed = current(array_filter($ed, static fn (array $x): bool => $x['a'] === 'MTTBCON' && $x['b'] === 'MTTBSE1'));
$eid = $ed['id'];
$repo->definirEstado($eid, 'sugerida', 7, $agora);
$pdo->exec("UPDATE dbo.dic_relacao_tabela SET aviso = 'alerta antigo' WHERE id = {$eid}");
$repo->editar($eid, [['nome' => 'Adquirente 1', 'pares' => [['CODEMP', 'CODEMP'], ['ADQ1_CPFCGC', 'CGCCPF']]]], false, 7, $agora);
$dep = current(array_filter($repo->mapa('MTTBSE1', 1)['relacoes'], static fn (array $x): bool => $x['id'] === $eid));
check('editar troca papéis e pares', count($dep['papeis']) === 1 && $dep['papeis'][0]['nome'] === 'Adquirente 1' && count($dep['papeis'][0]['pares']) === 2 && $dep['papeis'][0]['fonte'] === 'Editada à mão');
check('editar limpa o alerta e mantém o estado', $dep['aviso'] === null && $dep['estado'] === 'sugerida' && $dep['tipo'] === $ed['tipo']);
$repo->editar($eid, [['nome' => 'Um', 'pares' => [['CODEMP', 'CODEMP']]], ['nome' => 'Dois', 'pares' => [['ADQ2_CPF', 'CGCCPF']]]], true, 9, $agora);
$linha = $pdo->query("SELECT estado, decidido_por FROM dbo.dic_relacao_tabela WHERE id = {$eid}")->fetch();
check('editar e confirmar registra a decisão', $linha['estado'] === 'confirmada' && (int) $linha['decidido_por'] === 9);
check('editar não deixa pares órfãos', (int) $pdo->query("SELECT COUNT(*) FROM dbo.dic_relacao_par WHERE papel_id NOT IN (SELECT id FROM dbo.dic_relacao_papel)")->fetchColumn() === 0);
$antes = (int) $pdo->query('SELECT COUNT(*) FROM dbo.dic_relacao_par')->fetchColumn();
foreach ([
    'sem papéis' => [$eid, []],
    'papel sem colunas' => [$eid, [['nome' => 'x', 'pares' => []]]],
    'coluna inexistente' => [$eid, [['nome' => 'x', 'pares' => [['NAO_EXISTE', 'CODEMP']]]]],
    'coluna na tabela errada' => [$eid, [['nome' => 'x', 'pares' => [['CODEMP', 'ADQ1_CPFCGC']]]]],
    'papéis com o mesmo nome' => [$eid, [['nome' => 'x', 'pares' => [['CODEMP', 'CODEMP']]], ['nome' => 'X', 'pares' => [['ADQ2_CPF', 'CGCCPF']]]]],
    'par repetido' => [$eid, [['nome' => 'x', 'pares' => [['CODEMP', 'CODEMP'], ['CODEMP', 'CODEMP']]]]],
    'ligação inexistente' => [999999, [['nome' => 'x', 'pares' => [['CODEMP', 'CODEMP']]]]],
] as $nome => [$i, $p]) {
    $recusou = false;
    try {
        $repo->editar($i, $p, false, 7, $agora);
    } catch (InvalidArgumentException) {
        $recusou = true;
    }
    check("editar recusa: {$nome}", $recusou);
}
check('recusa não altera nada', (int) $pdo->query('SELECT COUNT(*) FROM dbo.dic_relacao_par')->fetchColumn() === $antes);

// FKs: relação nova e papel novo em relação existente
$fk = ['a' => 'MTTBCON', 'b' => 'MTTBNUC', 'tipo' => 'fk', 'conf' => 'alta', 'estado' => 'confirmada', 'evid' => 'FK', 'aviso' => null, 'papeis' => [['nome' => 'FK_1', 'fonte' => 'sql', 'pares' => [['CODEMP', 'CodEmp'], ['REGIAO', 'Regiao']]]]];
check('FK nova', $repo->inserirCandidatos([$fk], null, $agora)['novas'] === 1);
$fk['papeis'][0]['nome'] = 'FK_2';
$fk['papeis'][0]['pares'] = [['NUCLEO', 'Codigo']];
$r = $repo->inserirCandidatos([$fk], null, $agora);
check('FK repetida vira papel novo, não relação nova', $r['novas'] === 0 && $r['existentes'] === 1 && (int) $pdo->query("SELECT COUNT(*) FROM dbo.dic_relacao_papel p JOIN dbo.dic_relacao_tabela t ON t.id = p.relacao_id WHERE t.tipo = 'fk'")->fetchColumn() === 2);
$fk['a'] = 'MTTBXXX';
check('FK de tabela desconhecida é ignorada', $repo->inserirCandidatos([$fk], null, $agora)['ignoradas'] === 1);

// listas
check('colunas de uma tabela', $repo->colunas('mttbnuc') === [['CodEmp', 'smallint'], ['Regiao', 'smallint'], ['Codigo', 'smallint'], ['Nucleo', 'char(45)']]);
$lista = $repo->tabelas();
$con = current(array_filter($lista, static fn (array $t): bool => $t['n'] === 'MTTBCON'));
check('lista de tabelas com pendências', $con['c'] === 9 && $con['p'] > 0 && count($lista) === count($tabs));
$pdo->exec("UPDATE dbo.dic_tabela SET situacao = 'excluir' WHERE tabela = 'MTTBFAS'");
check('tabela excluída some da lista', count($repo->tabelas()) === count($tabs) - 1);
check('tabelas-mãe: padrão vazio', $repo->tabelasMae() === []);
$repo->salvarMaes(['MTTBCON', 'MTTBEMP']);
check('tabelas-mãe: salvar e ler', $repo->tabelasMae() === ['MTTBCON', 'MTTBEMP']);
$repo->salvarMaes(['MTTBCON']);
check('tabelas-mãe: atualizar', $repo->tabelasMae() === ['MTTBCON']);

echo $falhas === 0 ? "OK: {$total} testes de relacionamentos\n" : "{$falhas} de {$total} falharam\n";
exit($falhas === 0 ? 0 : 1);
