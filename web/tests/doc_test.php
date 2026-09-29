<?php

declare(strict_types=1);

// Testes da importação da documentação: php tests/doc_test.php  (requer pdo_sqlite e mbstring)
require __DIR__ . '/../src/Metadata/DocParser.php';
require __DIR__ . '/../src/Metadata/DocLote.php';
require __DIR__ . '/../src/Metadata/DocImport.php';
require __DIR__ . '/../src/Metadata/Sync.php';
require __DIR__ . '/../src/Metadata/DicionarioRepository.php';

use Elogica\Metadata\DicionarioRepository;
use Elogica\Metadata\DocImport;
use Elogica\Metadata\DocLote;
use Elogica\Metadata\DocParser;

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
function linha(string $c1, string $c2, string $c3): string
{
    return "<tr><td><p class=MsoNormal><span>{$c1}&nbsp;<o:p></o:p></span></p></td><td><p>{$c2}</p></td><td><p class=MsoNormal><span>{$c3}</span></p></td></tr>";
}
/** @param list<string> $linhas */
function doc(string $tabela, string $desc, array $linhas, string $cab = 'Colunas'): string
{
    return "<html><head><style>p{margin:0}</style><!--[if gte mso 9]><xml><o:x>lixo</o:x></xml><![endif]--></head><body>"
        . "<p>SGH-2000 - SISTEMA</p><p>Tabela</p><p>: {$tabela}</p><p>Descrição : {$desc}</p>"
        . "<table>" . linha($cab, 'Tipo do Dado', 'Descrição') . implode('', $linhas) . "</table></body></html>";
}

// --- codificação ---
check('utf-8 mantido', DocParser::decodificar('Situação') === 'Situação');
check('cp1252 convertido', DocParser::decodificar("Situa\xE7\xE3o") === 'Situação');
check('BOM removido', DocParser::decodificar("\xEF\xBB\xBFabc") === 'abc');

// --- parse básico ---
$html = doc('MTTBNUC', 'Tabela de Núcleos Habitacionais', [
    linha('CODEMP', 'Integer', 'Código<br>da Empresa'),
    linha('CTR_SIT_CONT', 'Integer', 'Situação Contabil:<br>0 = Normal<br>1 = Atraso<br>2 = CL'),
    linha('FISJUR', 'Integer', 'Indicativo:<br>Pessoa Física = 1<br>Pessoa Jurídica = 2'),
    linha('CONTRATO', 'String
        * 10', 'Número
              do Contrato'),
]);
$r = DocParser::parse($html, 'mttbnuc.htm');
check('achou 1 tabela', count($r) === 1 && $r[0]['tabela'] === 'MTTBNUC');
check('descrição da tabela', $r[0]['descricao'] === 'Tabela de Núcleos Habitacionais');
check('cabeçalho não vira coluna', !isset($r[0]['colunas']['COLUNAS']) && count($r[0]['colunas']) === 4);
check('quebra de formatação vira espaço', $r[0]['colunas']['CONTRATO']['descricao'] === 'Número do Contrato' && $r[0]['colunas']['CONTRATO']['tipo'] === 'String * 10');
check('<br> vira linha e junta descrição', $r[0]['colunas']['CODEMP']['descricao'] === 'Código da Empresa');
check('valores "cod = texto"', $r[0]['colunas']['CTR_SIT_CONT']['valores'] === [['0', 'Normal'], ['1', 'Atraso'], ['2', 'CL']] && $r[0]['colunas']['CTR_SIT_CONT']['descricao'] === 'Situação Contabil:');
check('valores invertidos "texto = cod"', $r[0]['colunas']['FISJUR']['valores'] === [['1', 'Pessoa Física'], ['2', 'Pessoa Jurídica']]);
check('estilo e comentários ignorados', !str_contains(json_encode($r), 'lixo'));

// --- variações de cabeçalho e linhas ---
$semCab = '<p>Tabela : MTTBABC</p><p>Descrição : X</p><table>' . linha('CODEMP', 'INTEGER 0', 'Código da Empresa') . linha('REGIAO', 'INTEGER 0', 'Região') . '</table>';
$r = DocParser::parse($semCab, 'a.htm');
check('sem linha de cabeçalho: 1ª linha é dado', array_keys($r[0]['colunas']) === ['CODEMP', 'REGIAO'] && $r[0]['colunas']['CODEMP']['descricao'] === 'Código da Empresa');
$r = DocParser::parse(doc('MTTBABC', 'X', [linha('CODEMP', 'Integer', 'Empresa')], 'Coluna'), 'a.htm');
check('cabeçalho "Coluna" ignorado', array_keys($r[0]['colunas']) === ['CODEMP']);
$r = DocParser::parse(str_replace('<table>', '<table><tr><td>a</td><td>b</td><td>c</td><td>d</td></tr>', doc('MTTBABC', 'X', [linha('CODEMP', 'Integer', 'E')])), 'a.htm');
check('linha com 4 células ignorada', array_keys($r[0]['colunas']) === ['CODEMP']);
$r = DocParser::parse('<p>Tabela : MTTBLAY Descrição : Y</p><table><tr><td>layout</td></tr></table>' . doc('MTTBREAL', 'Real', [linha('A', 'I', 'campo a')]), 'a.htm');
check('tabela de layout não consome o cabeçalho', count($r) === 1 && $r[0]['tabela'] === 'MTTBREAL');
$r = DocParser::parse('<p>Texto qualquer</p><table>' . linha('CODEMP', 'Integer', 'x') . '</table>', 'p0612.htm');
check('arquivo sem "Tabela :" é ignorado', $r === []);
$aninhada = str_replace('Número', '<table><tr><td>x</td></tr></table>Número', doc('MTTBNEST', 'N', [linha('CAMPO1', 'I', 'Número'), linha('CAMPO2', 'I', 'b')]));
check('tabela aninhada não quebra', isset(DocParser::parse($aninhada, 'n.htm')[0]['colunas']['CAMPO2']));
$dois = doc('MTTBUM', 'Um', [linha('A', 'I', 'a')]) . doc('MTTBDOIS', 'Dois', [linha('B', 'I', 'b')]);
check('duas tabelas no mesmo arquivo', array_column(DocParser::parse($dois, 'd.htm'), 'tabela') === ['MTTBUM', 'MTTBDOIS']);

// --- valores ---
$s = DocParser::separar(['1 = PLENA E 2 = PARCIAL']);
check('1 linha com "=" não vira lista de linhas', $s['descricao'] === '1 = PLENA E 2 = PARCIAL');
$s = DocParser::separar(['Situações: 1-Ativo 2-quebrada 9-Cancelada']);
check('lista na mesma linha extrai valores e mantém o texto', $s['valores'] === [['1', 'Ativo'], ['2', 'quebrada'], ['9', 'Cancelada']] && str_contains($s['descricao'], 'Situações'));
$s = DocParser::separar(['Situação do Grupo (0-Aberta / 1-Concluida / 9-Excluida)']);
check('lista com barras', array_column($s['valores'], 0) === ['0', '1', '9']);
$s = DocParser::separar(['Data de nascimento no formato AAAAMMDD']);
check('texto corrido sem valores', $s['valores'] === []);
$s = DocParser::separar(['S = Sim', 'N = Não', 'S = repetido']);
check('código repetido fica só o primeiro', $s['valores'] === [['S', 'Sim'], ['N', 'Não']]);
$s = DocParser::separar(['Data: 8 dígitos']);
check('"Data: 8" não é valor', $s['valores'] === []);

// --- consolidação de arquivos ---
$a = doc('MTTBCON', 'Contratos', [linha('A', 'I', 'a')]);
$lote = DocLote::consolidar([
    ['nome' => 'Anterior_mttbcon.htm', 'bytes' => doc('MTTBCON', 'Antigo', [linha('A', 'I', 'velho')])],
    ['nome' => 'mttbcon - Nova Plano Empresario.htm', 'bytes' => doc('MTTBCON', 'Variante', [linha('A', 'I', 'variante')])],
    ['nome' => 'mttbcon.htm', 'bytes' => $a],
    ['nome' => 'ANTmttbclp.htm', 'bytes' => doc('MTTBCLP', 'Antigo', [linha('A', 'I', 'x')])],
    ['nome' => 'mttbcpc.htm', 'bytes' => doc('MTTBCPI', 'Errado', [linha('A', 'I', 'x')])],
    ['nome' => 'p0612.htm', 'bytes' => '<p>programa</p>'],
]);
check('escolhe o arquivo cujo nome é a tabela', $lote['tabelas']['MTTBCON']['arquivo'] === 'mttbcon.htm' && $lote['tabelas']['MTTBCON']['descricao'] === 'Contratos');
check('ignora versões anteriores', $lote['ignorados'] === 2 && !isset($lote['tabelas']['MTTBCLP']));
check('conta arquivos sem tabela', $lote['sem_tabela'] === 1);
check('avisa duplicidade e nome divergente', count($lote['avisos']) === 2 && str_contains(implode('|', $lote['avisos']), 'MTTBCPI'));

$lote = DocLote::consolidar([
    ['nome' => 'mttbend.htm', 'bytes' => doc('MTTEND', 'Endereços', [linha('A', 'I', 'a')])],
    ['nome' => 'mttbxyz.htm', 'bytes' => doc('OUTRATABELA', 'Nada a ver', [linha('A', 'I', 'a')])],
]);
check('corrige nome digitado errado no cabeçalho', isset($lote['tabelas']['MTTBEND']) && !isset($lote['tabelas']['MTTEND']));
check('nome muito diferente não é corrigido', isset($lote['tabelas']['OUTRATABELA']));

// --- planejar e gravar ---
$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$pdo->exec("ATTACH ':memory:' AS dbo");
$pdo->exec('CREATE TABLE dbo.dic_tabela (id INTEGER PRIMARY KEY, [schema] TEXT DEFAULT \'dbo\', tabela TEXT, descricao TEXT, existe_no_banco INTEGER DEFAULT 1)');
$pdo->exec('CREATE TABLE dbo.dic_coluna (id INTEGER PRIMARY KEY, dic_tabela_id INTEGER, coluna TEXT, descricao TEXT, existe_no_banco INTEGER DEFAULT 1)');
$pdo->exec('CREATE TABLE dbo.dic_coluna_valor (id INTEGER PRIMARY KEY, dic_coluna_id INTEGER, codigo TEXT, significado TEXT, ordem INTEGER DEFAULT 0)');
$pdo->exec("INSERT INTO dbo.dic_tabela (id, tabela, descricao) VALUES (1, 'MTTBNUC', NULL), (2, 'MTTBOUT', 'Descrição humana')");
$pdo->exec("INSERT INTO dbo.dic_coluna (id, dic_tabela_id, coluna, descricao) VALUES (1, 1, 'CODEMP', NULL), (2, 1, 'CTR_SIT_CONT', NULL), (3, 1, 'SEM_DOC', NULL), (4, 2, 'X', 'Texto humano do X')");
$repo = new DicionarioRepository($pdo);

$docs = [
    'MTTBNUC' => DocParser::parse($html, 'mttbnuc.htm')[0],
    'MTTBOUT' => ['tabela' => 'MTTBOUT', 'descricao' => 'Outra descrição da doc', 'arquivo' => 'mttbout.htm', 'colunas' => ['X' => ['coluna' => 'X', 'tipo' => 'I', 'descricao' => 'Texto da doc do X', 'valores' => []]]],
    'MTTBFORA' => ['tabela' => 'MTTBFORA', 'descricao' => 'Não existe no dicionário', 'arquivo' => 'mttbfora.htm', 'colunas' => []],
];
$plano = DocImport::planejar($docs, $repo->carregarTextos());
check('itens com mudança', array_keys($plano['itens']) === ['MTTBNUC', 'MTTBOUT']);
check('tabela sem par no dicionário', $plano['doc_sem_tabela'] === ['MTTBFORA' => 'mttbfora.htm']);
check('colunas sem documentação contadas', $plano['colunas_sem_doc'] === 1 && $plano['colunas_sem_dic'] === 2);
check('conflitos detectados', $plano['itens']['MTTBOUT']['conflitos'] === 2 && $plano['itens']['MTTBNUC']['conflitos'] === 0);

$r = $repo->aplicarDocumentacao($plano['itens'], []);
check('preencheu vazios', $r['descricoes'] === 3 && $r['valores'] === 1);
check('conflitos mantidos sem escolha', $r['conflitos_mantidos'] === 2 && $pdo->query("SELECT descricao FROM dbo.dic_tabela WHERE id = 2")->fetchColumn() === 'Descrição humana');
check('valores gravados em ordem', $pdo->query('SELECT group_concat(codigo, \',\') FROM (SELECT codigo FROM dbo.dic_coluna_valor WHERE dic_coluna_id = 2 ORDER BY ordem)')->fetchColumn() === '0,1,2');
check('descrição da tabela preenchida', $pdo->query("SELECT descricao FROM dbo.dic_tabela WHERE id = 1")->fetchColumn() === 'Tabela de Núcleos Habitacionais');

$plano = DocImport::planejar($docs, $repo->carregarTextos());
check('reaplicar só deixa os conflitos', array_keys($plano['itens']) === ['MTTBOUT']);
$r = $repo->aplicarDocumentacao($plano['itens'], ['MTTBOUT' => true]);
check('sobrescreve os escolhidos', $r['descricoes'] === 2 && $pdo->query("SELECT descricao FROM dbo.dic_tabela WHERE id = 2")->fetchColumn() === 'Outra descrição da doc' && $pdo->query("SELECT descricao FROM dbo.dic_coluna WHERE id = 4")->fetchColumn() === 'Texto da doc do X');
check('nada mais a fazer', DocImport::planejar($docs, $repo->carregarTextos())['itens'] === []);

// troca de valores em conflito
$pdo->exec("UPDATE dbo.dic_coluna_valor SET significado = 'Editado' WHERE dic_coluna_id = 2 AND codigo = '0'");
$plano = DocImport::planejar($docs, $repo->carregarTextos());
check('valores editados viram conflito', ($plano['itens']['MTTBNUC']['conflitos'] ?? 0) === 1);
$repo->aplicarDocumentacao($plano['itens'], ['MTTBNUC' => true]);
check('sobrescrita de valores substitui a lista', $pdo->query("SELECT significado FROM dbo.dic_coluna_valor WHERE dic_coluna_id = 2 AND codigo = '0'")->fetchColumn() === 'Normal' && (int) $pdo->query('SELECT COUNT(*) FROM dbo.dic_coluna_valor WHERE dic_coluna_id = 2')->fetchColumn() === 3);

echo $falhas === 0 ? "OK: {$total} testes de documentação\n" : "{$falhas} de {$total} falharam\n";
exit($falhas === 0 ? 0 : 1);
