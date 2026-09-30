<?php

declare(strict_types=1);

// Testes da sincronização do dicionário: php tests/sync_test.php  (usa SQLite em memória; requer pdo_sqlite)
require __DIR__ . '/../src/Metadata/Sync.php';
require __DIR__ . '/../src/Relacionamentos/RelacionamentoRepository.php';
require __DIR__ . '/../src/Metadata/DicionarioRepository.php';

use Elogica\Metadata\DicionarioRepository;
use Elogica\Metadata\Sync;

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
function col(string $c, int $ordem, string $tipo = 'char', int $tam = 8, bool $nulo = true): array
{
    return ['coluna' => $c, 'ordem' => $ordem, 'tipo' => $tipo, 'tamanho' => $tam, 'nulo' => $nulo];
}
/** @param list<array<string, mixed>> $cols @return array<string, mixed> */
function tab(string $t, array $cols): array
{
    $m = [];
    foreach ($cols as $c) {
        $m[strtoupper($c['coluna'])] = $c;
    }

    return ['schema' => 'dbo', 'tabela' => $t, 'colunas' => $m];
}

$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$pdo->exec("ATTACH ':memory:' AS dbo");
$pdo->exec('CREATE TABLE dbo.dic_tabela (id INTEGER PRIMARY KEY, [schema] TEXT NOT NULL DEFAULT \'dbo\', tabela TEXT NOT NULL, descricao TEXT, situacao TEXT NOT NULL DEFAULT \'incluir\', existe_no_banco INTEGER NOT NULL DEFAULT 1, status_revisao TEXT NOT NULL DEFAULT \'sugerido_ia\', UNIQUE ([schema], tabela))');
$pdo->exec('CREATE TABLE dbo.dic_coluna (id INTEGER PRIMARY KEY, dic_tabela_id INTEGER NOT NULL, coluna TEXT NOT NULL, ordem INTEGER NOT NULL DEFAULT 0, tipo TEXT NOT NULL, tamanho INTEGER, nulo INTEGER NOT NULL DEFAULT 1, descricao TEXT, existe_no_banco INTEGER NOT NULL DEFAULT 1, status_revisao TEXT NOT NULL DEFAULT \'sugerido_ia\', UNIQUE (dic_tabela_id, coluna))');
$pdo->exec('CREATE TABLE dbo.dic_coluna_valor (id INTEGER PRIMARY KEY, dic_coluna_id INTEGER, codigo TEXT, significado TEXT, ordem INTEGER DEFAULT 0)');
$pdo->exec('CREATE TABLE dbo.dic_relacao_tabela (id INTEGER PRIMARY KEY, origem_tabela_id INTEGER, destino_tabela_id INTEGER, tipo TEXT, estado TEXT, confianca TEXT, evidencia TEXT, aviso TEXT, criado_por INTEGER, criado_em TEXT, decidido_por INTEGER, decidido_em TEXT, UNIQUE (origem_tabela_id, destino_tabela_id, tipo))');
$pdo->exec('CREATE TABLE dbo.dic_relacao_papel (id INTEGER PRIMARY KEY, relacao_id INTEGER, nome TEXT, fonte TEXT, UNIQUE (relacao_id, nome))');
$pdo->exec('CREATE TABLE dbo.dic_relacao_par (id INTEGER PRIMARY KEY, papel_id INTEGER, ordem INTEGER, origem_coluna_id INTEGER, destino_coluna_id INTEGER)');
$repo = new DicionarioRepository($pdo);

// --- 1ª sincronização: dicionário vazio ---
$banco = [
    'DBO.MTTBCON' => tab('MTTBCON', [col('CODEMP', 1, 'smallint', 2, false), col('CONTRATO', 2, 'char', 10)]),
    'DBO.MTTBNUC' => tab('MTTBNUC', [col('NUCLEO', 1, 'smallint', 2, false)]),
];
$fks = [['origem' => 'DBO.MTTBCON', 'destino' => 'DBO.MTTBNUC', 'nome' => 'FK_CON_NUC', 'pares' => [['CODEMP', 'NUCLEO']]]];
$plano = Sync::planejar($banco, $repo->carregar());
check('dic vazio: tabelas novas', count($plano['tabelas_novas']) === 2);
check('há mudanças', Sync::temMudancas($plano));
$r = $repo->aplicar($plano, $fks);
check('gravou tabelas e colunas', $r['tabelas'] === 2 && $r['colunas'] === 3);
check('gravou FK como relação', $r['relacoes'] === 1);

// --- idempotência ---
$plano = Sync::planejar($banco, $repo->carregar());
check('2ª análise sem mudanças', !Sync::temMudancas($plano) && $plano['sem_mudanca'] === 3);
$r = $repo->aplicar($plano, $fks);
check('reaplicar não duplica', $r === ['tabelas' => 0, 'colunas' => 0, 'relacoes' => 0]);
check('relação única', (int) $pdo->query('SELECT COUNT(*) FROM dbo.dic_relacao_tabela')->fetchColumn() === 1 && (int) $pdo->query('SELECT COUNT(*) FROM dbo.dic_relacao_par')->fetchColumn() === 1);
check('FK entra confirmada', $pdo->query('SELECT estado FROM dbo.dic_relacao_tabela')->fetchColumn() === 'confirmada');

// --- descrição revisada por humano não é sobrescrita ---
$pdo->exec("UPDATE dbo.dic_tabela SET descricao = 'Contratos', situacao = 'excluir' WHERE tabela = 'MTTBCON'");
$pdo->exec("UPDATE dbo.dic_coluna SET descricao = 'Código da empresa' WHERE coluna = 'CODEMP'");

// --- mudanças no banco: coluna nova, alterada, removida; tabela removida ---
$banco2 = [
    'DBO.MTTBCON' => tab('MTTBCON', [col('CODEMP', 1, 'int', 4, false), col('NOVA', 3, 'float', 8)]),
];
$plano = Sync::planejar($banco2, $repo->carregar());
check('coluna nova', count($plano['colunas_novas']) === 1 && $plano['colunas_novas'][0]['coluna'] === 'NOVA');
check('coluna alterada', count($plano['colunas_alteradas']) === 1 && $plano['colunas_alteradas'][0]['de'] === 'smallint(2) NOT NULL' && $plano['colunas_alteradas'][0]['para'] === 'int(4) NOT NULL');
check('coluna ausente', count($plano['colunas_ausentes']) === 1 && $plano['colunas_ausentes'][0]['coluna'] === 'CONTRATO');
check('tabela ausente', count($plano['tabelas_ausentes']) === 1 && $plano['tabelas_ausentes'][0]['tabela'] === 'MTTBNUC');
$repo->aplicar($plano, []);
check('nada é apagado', (int) $pdo->query('SELECT COUNT(*) FROM dbo.dic_tabela')->fetchColumn() === 2 && (int) $pdo->query('SELECT COUNT(*) FROM dbo.dic_coluna')->fetchColumn() === 4);
check('tabela ausente marcada', (int) $pdo->query("SELECT existe_no_banco FROM dbo.dic_tabela WHERE tabela = 'MTTBNUC'")->fetchColumn() === 0);
check('coluna ausente marcada', (int) $pdo->query("SELECT existe_no_banco FROM dbo.dic_coluna WHERE coluna = 'CONTRATO'")->fetchColumn() === 0);
check('tipo atualizado', $pdo->query("SELECT tipo FROM dbo.dic_coluna WHERE coluna = 'CODEMP'")->fetchColumn() === 'int');
check('descrição da tabela preservada', $pdo->query("SELECT descricao FROM dbo.dic_tabela WHERE tabela = 'MTTBCON'")->fetchColumn() === 'Contratos');
check('situação da tabela preservada', $pdo->query("SELECT situacao FROM dbo.dic_tabela WHERE tabela = 'MTTBCON'")->fetchColumn() === 'excluir');
check('descrição da coluna preservada', $pdo->query("SELECT descricao FROM dbo.dic_coluna WHERE coluna = 'CODEMP'")->fetchColumn() === 'Código da empresa');

// --- reaparecem ---
$plano = Sync::planejar($banco, $repo->carregar());
check('tabela reativada', count($plano['tabelas_reativadas']) === 1 && count($plano['colunas_reativadas']) === 1);
$repo->aplicar($plano, []);
check('reativada no banco', (int) $pdo->query("SELECT existe_no_banco FROM dbo.dic_tabela WHERE tabela = 'MTTBNUC'")->fetchColumn() === 1);

// --- caixa dos nomes ---
$plano = Sync::planejar(['DBO.MTTBCON' => tab('mttbcon', [col('codemp', 1, 'smallint', 2, false)])], $repo->carregar());
check('compara sem diferenciar caixa', $plano['tabelas_novas'] === []);

// --- listagem ---
check('lista com filtro', count($repo->listarTabelas('NUC')) === 1 && count($repo->listarTabelas('')) === 2);

echo $falhas === 0 ? "OK: {$total} testes de sincronização\n" : "{$falhas} de {$total} falharam\n";
exit($falhas === 0 ? 0 : 1);
