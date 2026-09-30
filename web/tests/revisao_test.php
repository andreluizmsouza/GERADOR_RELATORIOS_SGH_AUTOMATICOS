<?php

declare(strict_types=1);

// Testes da tela de revisão: php tests/revisao_test.php  (requer pdo_sqlite e mbstring)
require __DIR__ . '/../src/Admin/RevisaoForm.php';
require __DIR__ . '/../src/Metadata/Sensiveis.php';
require __DIR__ . '/../src/Metadata/Sync.php';
require __DIR__ . '/../src/Relacionamentos/RelacionamentoRepository.php';
require __DIR__ . '/../src/Metadata/DicionarioRepository.php';

use Elogica\Admin\RevisaoForm;
use Elogica\Metadata\DicionarioRepository;
use Elogica\Metadata\Sensiveis;

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

// --- sensíveis ---
foreach (['CPF', 'ADQ1_CPFCGC', 'DATU_CGC_CPF', 'NOME', 'AD2_NOME', 'BAIRRO', 'CEP', 'DTA_NASC', 'RENDA', 'EMAIL', 'PIS', 'NOME_PAI'] as $c) {
    check("sensível pelo nome: {$c}", Sensiveis::sugerir($c, ''));
}
check('sensível pela descrição', Sensiveis::sugerir('X1', 'Estado civil do adquirente') && Sensiveis::sugerir('Y', 'Telefone do cliente'));
foreach (['CONTRATO', 'PAINEL', 'TELA', 'HOTEL', 'CODEMP', 'SALDO_DEV', 'ACCEPT', 'GARAGEM_PIS'] as $c) {
    check("não sensível: {$c}", !Sensiveis::sugerir($c, $c === 'GARAGEM_PIS' ? 'GARAGEM PISO' : ''));
}
check('nome de cidade não é pessoal', !Sensiveis::sugerir('DESCRICAO', 'Nome da cidade'));
check('salário mínimo não é pessoal', !Sensiveis::sugerir('SAL_MINIMO', 'Valor do Salario Mínimo Oficial'));

// --- valores ---
$e = [];
check('valores válidos', RevisaoForm::valores("0 = Normal\n1= Atraso\n\n", 'X', $e) === [['0', 'Normal'], ['1', 'Atraso']] && $e === []);
RevisaoForm::valores('sem separador', 'X', $e);
check('valor inválido gera erro', count($e) === 1);
$e = [];
RevisaoForm::valores("1 = a\n1 = b", 'X', $e);
check('código repetido gera erro', count($e) === 1);

// --- formulário ---
$post = ['descricao' => 'Contratos', 'situacao' => 'incluir', 'status_revisao' => 'revisado',
    'col' => [10 => ['descricao' => 'Empresa', 'nome_negocio' => 'Código da empresa', 'sinonimos' => 'agente', 'sensivel' => '1', 'status' => 'revisado', 'valores' => '']]];
$v = RevisaoForm::validar($post, [10, 11]);
check('válido', $v['erros'] === [] && $v['colunas'][10]['sensivel'] === true);
check('coluna ausente do envio é ignorada, não apagada', !isset($v['colunas'][11]));
check('coluna de outra tabela é ignorada', !isset(RevisaoForm::validar($post + ['col' => [99 => ['descricao' => 'x']]], [10])['colunas'][99]));
check('situação inválida', RevisaoForm::validar(['situacao' => 'x'] + $post, [10])['erros'] !== []);
check('status inválido', RevisaoForm::validar(['status_revisao' => 'x'] + $post, [10])['erros'] !== []);
check('descrição longa demais', RevisaoForm::validar(['descricao' => str_repeat('a', 1001)] + $post, [10])['erros'] !== []);
$post2 = $post;
$post2['status_revisao'] = 'sugerido_ia';
$post2['todas_revisadas'] = '1';
$v = RevisaoForm::validar($post2, [10]);
check('marcar tudo como revisado', $v['tabela']['status_revisao'] === 'revisado' && $v['colunas'][10]['status_revisao'] === 'revisado');

// --- gravação ---
$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$pdo->exec("ATTACH ':memory:' AS dbo");
$pdo->exec("CREATE TABLE dbo.dic_tabela (id INTEGER PRIMARY KEY, [schema] TEXT DEFAULT 'dbo', tabela TEXT, descricao TEXT, situacao TEXT DEFAULT 'incluir', existe_no_banco INTEGER DEFAULT 1, status_revisao TEXT DEFAULT 'sugerido_ia', revisado_por INTEGER, revisado_em TEXT)");
$pdo->exec("CREATE TABLE dbo.dic_coluna (id INTEGER PRIMARY KEY, dic_tabela_id INTEGER, coluna TEXT, ordem INTEGER DEFAULT 0, tipo TEXT, tamanho INTEGER, nulo INTEGER DEFAULT 1, descricao TEXT, nome_negocio TEXT, sinonimos TEXT, sensivel INTEGER DEFAULT 0, existe_no_banco INTEGER DEFAULT 1, status_revisao TEXT DEFAULT 'sugerido_ia')");
$pdo->exec('CREATE TABLE dbo.dic_coluna_valor (id INTEGER PRIMARY KEY, dic_coluna_id INTEGER, codigo TEXT, significado TEXT, ordem INTEGER DEFAULT 0)');
$pdo->exec("INSERT INTO dbo.dic_tabela (id, tabela) VALUES (1, 'MTTBCON'), (2, 'MTTBOUT')");
$pdo->exec("INSERT INTO dbo.dic_coluna (id, dic_tabela_id, coluna, ordem, tipo, tamanho, descricao) VALUES (10, 1, 'CODEMP', 1, 'smallint', 2, 'Antiga'), (11, 1, 'CPF', 2, 'char', 11, 'CPF do adquirente'), (12, 2, 'X', 1, 'char', 1, 'De outra tabela')");
$repo = new DicionarioRepository($pdo);

$cols = $repo->colunasDaTabela(1);
check('lista só as colunas da tabela, em ordem', array_column($cols, 'coluna') === ['CODEMP', 'CPF']);
$post['col'][11] = ['descricao' => 'CPF do adquirente', 'nome_negocio' => '', 'sinonimos' => '', 'status' => 'sugerido_ia', 'valores' => "S = Sim\nN = Não"];
$v = RevisaoForm::validar($post, [10, 11]);
$r = $repo->salvarRevisao(1, $v['tabela'], $v['colunas'], 7, '2026-09-29 20:00:00');
check('gravou 1 coluna alterada e 1 lista de valores', $r === ['colunas' => 1, 'valores' => 1]);
$t = $repo->tabela(1);
check('tabela revisada com usuário e data', $t['status_revisao'] === 'revisado' && (int) $t['revisado_por'] === 7 && $t['revisado_em'] === '2026-09-29 20:00:00' && $t['descricao'] === 'Contratos');
$c10 = $pdo->query('SELECT * FROM dbo.dic_coluna WHERE id = 10')->fetch();
check('campos editados gravados', $c10['descricao'] === 'Empresa' && $c10['nome_negocio'] === 'Código da empresa' && (int) $c10['sensivel'] === 1 && $c10['status_revisao'] === 'revisado');
check('estrutura (tipo/tamanho) intacta', $c10['tipo'] === 'smallint' && (int) $c10['tamanho'] === 2);
check('vazio vira NULL', $pdo->query('SELECT nome_negocio FROM dbo.dic_coluna WHERE id = 11')->fetchColumn() === null);
check('não mexe em outra tabela', $pdo->query('SELECT descricao FROM dbo.dic_coluna WHERE id = 12')->fetchColumn() === 'De outra tabela');
check('valores gravados', $pdo->query('SELECT group_concat(codigo, \',\') FROM (SELECT codigo FROM dbo.dic_coluna_valor WHERE dic_coluna_id = 11 ORDER BY ordem)')->fetchColumn() === 'S,N');

$r = $repo->salvarRevisao(1, $v['tabela'], $v['colunas'], 7, '2026-09-29 21:00:00');
check('salvar de novo não altera colunas nem valores', $r === ['colunas' => 0, 'valores' => 0]);

// coluna ausente do envio não é tocada
$v = RevisaoForm::validar(['col' => [10 => $post['col'][10]]] + $post, [10, 11]);
$repo->salvarRevisao(1, $v['tabela'], $v['colunas'], 7, '2026-09-29 22:00:00');
check('coluna ausente do envio mantém os dados', $pdo->query('SELECT descricao FROM dbo.dic_coluna WHERE id = 11')->fetchColumn() === 'CPF do adquirente' && (int) $pdo->query('SELECT COUNT(*) FROM dbo.dic_coluna_valor WHERE dic_coluna_id = 11')->fetchColumn() === 2);

// rebaixar o status limpa a marca de revisão
$v = RevisaoForm::validar(['status_revisao' => 'sugerido_ia'] + $post, [10, 11]);
$repo->salvarRevisao(1, $v['tabela'], $v['colunas'], 7, '2026-09-29 23:00:00');
check('sair de "revisado" limpa revisor', $pdo->query('SELECT revisado_por FROM dbo.dic_tabela WHERE id = 1')->fetchColumn() === null);

// listagem
check('listagem com contagem de revisados', $repo->listarTabelas('CON')[0]['colunas'] == 2 && $repo->listarTabelas('CON')[0]['revisadas'] == 1);
check('filtro por status', count($repo->listarTabelas('', 'revisado')) === 0 && count($repo->listarTabelas('', 'sugerido_ia')) === 2);

echo $falhas === 0 ? "OK: {$total} testes de revisão\n" : "{$falhas} de {$total} falharam\n";
exit($falhas === 0 ? 0 : 1);
