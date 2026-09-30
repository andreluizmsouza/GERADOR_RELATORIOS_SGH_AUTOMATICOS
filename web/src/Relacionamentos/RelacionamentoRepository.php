<?php

declare(strict_types=1);

namespace Elogica\Relacionamentos;

use InvalidArgumentException;
use PDO;

/** Persistência dos relacionamentos entre tabelas. SQL portável entre SQL Server e SQLite (testes). */
final class RelacionamentoRepository
{
    public const ESTADOS = ['sugerida', 'confirmada', 'rejeitada'];

    public function __construct(private readonly PDO $pdo)
    {
    }

    public static function tipoTexto(string $tipo, mixed $tamanho): string
    {
        return in_array(strtolower($tipo), ['char', 'varchar', 'nchar', 'nvarchar'], true) ? $tipo . '(' . (int) $tamanho . ')' : $tipo;
    }

    /* ---------- leitura ---------- */

    /** @return list<array{n: string, d: string, c: int, p: int}> tabelas do escopo, com quantas ligações aguardam revisão */
    public function tabelas(): array
    {
        $rows = $this->pdo->query(
            'SELECT t.tabela, t.descricao, '
            . '(SELECT COUNT(*) FROM dbo.dic_coluna c WHERE c.dic_tabela_id = t.id) AS n, '
            . "(SELECT COUNT(*) FROM dbo.dic_relacao_tabela r WHERE (r.origem_tabela_id = t.id OR r.destino_tabela_id = t.id) AND r.estado = 'sugerida') AS pend "
            . "FROM dbo.dic_tabela t WHERE t.situacao = 'incluir' AND t.existe_no_banco = 1 ORDER BY t.tabela"
        )->fetchAll();

        return array_map(static fn (array $r): array => ['n' => strtoupper((string) $r['tabela']), 'd' => (string) ($r['descricao'] ?? ''), 'c' => (int) $r['n'], 'p' => (int) $r['pend']], $rows);
    }

    /** @return array{confirmada: int, sugerida: int, rejeitada: int} */
    public function resumo(): array
    {
        $out = ['confirmada' => 0, 'sugerida' => 0, 'rejeitada' => 0];
        foreach ($this->pdo->query('SELECT estado, COUNT(*) AS n FROM dbo.dic_relacao_tabela GROUP BY estado')->fetchAll() as $r) {
            $out[(string) $r['estado']] = (int) $r['n'];
        }

        return $out;
    }

    /** @return list<string> */
    public function tabelasMae(): array
    {
        $v = $this->pdo->query("SELECT valor FROM dbo.dic_config WHERE chave = 'tabelas_mae'")->fetchColumn();

        return $v === false || trim((string) $v) === '' ? [] : array_values(array_filter(array_map(static fn (string $s): string => strtoupper(trim($s)), explode(',', (string) $v))));
    }

    /** @param list<string> $maes */
    public function salvarMaes(array $maes): void
    {
        $v = implode(',', $maes);
        $up = $this->pdo->prepare("UPDATE dbo.dic_config SET valor = :v WHERE chave = 'tabelas_mae'");
        $up->execute([':v' => $v]);
        if ($up->rowCount() === 0) {
            $this->pdo->prepare("INSERT INTO dbo.dic_config (chave, valor) VALUES ('tabelas_mae', :v)")->execute([':v' => $v]);
        }
    }

    /**
     * Vizinhança de uma tabela: tabelas e ligações (com papéis e pares de colunas) a até $saltos ligações de distância.
     *
     * @return array{foco: string, tabelas: array<string, array<string, mixed>>, relacoes: list<array<string, mixed>>}|null
     */
    public function mapa(string $tabela, int $saltos): ?array
    {
        $tabela = strtoupper($tabela);
        $ids = [];
        $nomes = [];
        foreach ($this->pdo->query('SELECT id, tabela, descricao FROM dbo.dic_tabela')->fetchAll() as $t) {
            $ids[strtoupper((string) $t['tabela'])] = (int) $t['id'];
            $nomes[(int) $t['id']] = ['n' => strtoupper((string) $t['tabela']), 'd' => (string) ($t['descricao'] ?? '')];
        }
        if (!isset($ids[$tabela])) {
            return null;
        }
        $arestas = $this->pdo->query('SELECT id, origem_tabela_id AS o, destino_tabela_id AS d FROM dbo.dic_relacao_tabela')->fetchAll();

        $dist = [$ids[$tabela] => 0];
        $fronteira = [$ids[$tabela]];
        for ($h = 1; $h <= max(1, min(2, $saltos)); $h++) {
            $prox = [];
            foreach ($arestas as $r) {
                foreach ([[(int) $r['o'], (int) $r['d']], [(int) $r['d'], (int) $r['o']]] as [$x, $y]) {
                    if (in_array($x, $fronteira, true) && !isset($dist[$y])) {
                        $dist[$y] = $h;
                        $prox[] = $y;
                    }
                }
            }
            $fronteira = $prox;
        }
        $relIds = [];
        foreach ($arestas as $r) {
            if (isset($dist[(int) $r['o']], $dist[(int) $r['d']])) {
                $relIds[] = (int) $r['id'];
            }
        }
        $tabs = [];
        foreach (array_keys($dist) as $id) {
            $tabs[$nomes[$id]['n']] = ['d' => $nomes[$id]['d'], 'n' => 0, 'p' => 0, 'r' => $dist[$id]];
        }
        $this->completarTabelas($tabs);

        return ['foco' => $tabela, 'tabelas' => $tabs, 'relacoes' => $this->detalhes($relIds)];
    }

    /** @param array<string, array<string, mixed>> $tabs */
    private function completarTabelas(array &$tabs): void
    {
        foreach ($this->tabelas() as $t) {
            if (isset($tabs[$t['n']])) {
                $tabs[$t['n']]['n'] = $t['c'];
                $tabs[$t['n']]['p'] = $t['p'];
            }
        }
    }

    /**
     * @param list<int> $relIds
     * @return list<array<string, mixed>>
     */
    private function detalhes(array $relIds): array
    {
        if ($relIds === []) {
            return [];
        }
        $in = implode(',', array_fill(0, count($relIds), '?'));
        $stmt = $this->pdo->prepare(
            'SELECT r.id, r.tipo, r.estado, r.confianca, r.evidencia, r.aviso, r.criado_por, ta.tabela AS a, tb.tabela AS b '
            . 'FROM dbo.dic_relacao_tabela r JOIN dbo.dic_tabela ta ON ta.id = r.origem_tabela_id JOIN dbo.dic_tabela tb ON tb.id = r.destino_tabela_id '
            . "WHERE r.id IN ({$in}) ORDER BY r.id"
        );
        $stmt->execute($relIds);
        $rels = [];
        foreach ($stmt->fetchAll() as $r) {
            $rels[(int) $r['id']] = [
                'id' => (int) $r['id'], 'a' => strtoupper((string) $r['a']), 'b' => strtoupper((string) $r['b']), 'tipo' => (string) $r['tipo'],
                'estado' => (string) $r['estado'], 'conf' => (string) $r['confianca'], 'evid' => (string) $r['evidencia'], 'aviso' => $r['aviso'] === null ? null : (string) $r['aviso'],
                'papeis' => [],
            ];
        }
        $stmt = $this->pdo->prepare(
            'SELECT p.relacao_id, p.id AS papel_id, p.nome, p.fonte, q.ordem, co.coluna AS ca, co.tipo AS ta, co.tamanho AS za, cd.coluna AS cb, cd.tipo AS tb, cd.tamanho AS zb '
            . 'FROM dbo.dic_relacao_papel p JOIN dbo.dic_relacao_par q ON q.papel_id = p.id '
            . 'JOIN dbo.dic_coluna co ON co.id = q.origem_coluna_id JOIN dbo.dic_coluna cd ON cd.id = q.destino_coluna_id '
            . "WHERE p.relacao_id IN ({$in}) ORDER BY p.relacao_id, p.id, q.ordem"
        );
        $stmt->execute($relIds);
        $papeis = [];
        foreach ($stmt->fetchAll() as $r) {
            $rid = (int) $r['relacao_id'];
            $pid = (int) $r['papel_id'];
            if (!isset($papeis[$pid])) {
                $papeis[$pid] = ['rel' => $rid, 'nome' => (string) $r['nome'], 'fonte' => (string) ($r['fonte'] ?? ''), 'pares' => []];
            }
            $papeis[$pid]['pares'][] = [(string) $r['ca'], self::tipoTexto((string) $r['ta'], $r['za']), (string) $r['cb'], self::tipoTexto((string) $r['tb'], $r['zb'])];
        }
        foreach ($papeis as $p) {
            if (isset($rels[$p['rel']])) {
                $rels[$p['rel']]['papeis'][] = ['nome' => $p['nome'], 'fonte' => $p['fonte'], 'pares' => $p['pares']];
            }
        }

        return array_values($rels);
    }

    /** @return list<array{0: string, 1: string}> */
    public function colunas(string $tabela): array
    {
        $stmt = $this->pdo->prepare('SELECT c.coluna, c.tipo, c.tamanho FROM dbo.dic_coluna c JOIN dbo.dic_tabela t ON t.id = c.dic_tabela_id WHERE UPPER(t.tabela) = :t ORDER BY c.ordem, c.id');
        $stmt->execute([':t' => strtoupper($tabela)]);

        return array_map(static fn (array $r): array => [(string) $r['coluna'], self::tipoTexto((string) $r['tipo'], $r['tamanho'])], $stmt->fetchAll());
    }

    /**
     * Entrada do motor de sugestões: tabelas do escopo, com colunas em ordem.
     *
     * @return array<string, array{colunas: list<array{c: string, t: string, d: string}>}>
     */
    public function tabelasParaSugestor(): array
    {
        $porId = [];
        $out = [];
        foreach ($this->pdo->query("SELECT id, tabela FROM dbo.dic_tabela WHERE situacao = 'incluir' AND existe_no_banco = 1")->fetchAll() as $t) {
            $porId[(int) $t['id']] = strtoupper((string) $t['tabela']);
            $out[strtoupper((string) $t['tabela'])] = ['colunas' => []];
        }
        foreach ($this->pdo->query('SELECT dic_tabela_id, coluna, tipo, tamanho, descricao FROM dbo.dic_coluna WHERE existe_no_banco = 1 ORDER BY dic_tabela_id, ordem, id')->fetchAll() as $c) {
            $t = $porId[(int) $c['dic_tabela_id']] ?? null;
            if ($t !== null) {
                $out[$t]['colunas'][] = ['c' => (string) $c['coluna'], 't' => self::tipoTexto((string) $c['tipo'], $c['tamanho']), 'd' => (string) ($c['descricao'] ?? '')];
            }
        }

        return $out;
    }

    /* ---------- escrita ---------- */

    public function definirEstado(int $id, string $estado, int $usuarioId, string $agora): bool
    {
        if (!in_array($estado, self::ESTADOS, true)) {
            throw new InvalidArgumentException('Estado inválido.');
        }
        $stmt = $this->pdo->prepare('UPDATE dbo.dic_relacao_tabela SET estado = :e, decidido_por = :u, decidido_em = :d WHERE id = :id');
        $stmt->execute([':e' => $estado, ':u' => $estado === 'sugerida' ? null : $usuarioId, ':d' => $estado === 'sugerida' ? null : $agora, ':id' => $id]);

        return $stmt->rowCount() > 0;
    }

    /**
     * Aprova em lote só o que é seguro: sugerida, de confiança alta e sem aviso.
     *
     * @param list<int> $ids
     */
    public function aprovarLote(array $ids, int $usuarioId, string $agora): int
    {
        $n = 0;
        $sel = $this->pdo->prepare("SELECT COUNT(*) FROM dbo.dic_relacao_tabela WHERE id = :id AND estado = 'sugerida' AND confianca = 'alta' AND aviso IS NULL");
        foreach (array_unique($ids) as $id) {
            $sel->execute([':id' => $id]);
            if ((int) $sel->fetchColumn() === 1 && $this->definirEstado((int) $id, 'confirmada', $usuarioId, $agora)) {
                $n++;
            }
            $sel->closeCursor();
        }

        return $n;
    }

    public function excluirManual(int $id): bool
    {
        $stmt = $this->pdo->prepare("DELETE FROM dbo.dic_relacao_tabela WHERE id = :id AND tipo = 'manual'");
        $stmt->execute([':id' => $id]);

        return $stmt->rowCount() > 0;
    }

    /**
     * Cria uma ligação à mão, já confirmada.
     *
     * @param list<array{0: string, 1: string}> $pares [coluna de origem, coluna de destino]
     */
    public function criarManual(string $a, string $b, string $papel, array $pares, int $usuarioId, string $agora): int
    {
        $a = strtoupper(trim($a));
        $b = strtoupper(trim($b));
        if ($a === $b) {
            throw new InvalidArgumentException('Origem e destino devem ser tabelas diferentes.');
        }
        if ($pares === []) {
            throw new InvalidArgumentException('Informe ao menos um par de colunas.');
        }
        if (mb_strlen($papel) > 200) {
            throw new InvalidArgumentException('O nome do papel passa de 200 caracteres.');
        }
        $tabs = $this->idsTabelas();
        if (!isset($tabs[$a], $tabs[$b])) {
            throw new InvalidArgumentException('Escolha duas tabelas do dicionário.');
        }
        $cols = $this->idsColunas();
        $ps = [];
        foreach ($pares as [$x, $y]) {
            $ix = $cols[$tabs[$a]][strtoupper(trim($x))] ?? null;
            $iy = $cols[$tabs[$b]][strtoupper(trim($y))] ?? null;
            if ($ix === null || $iy === null) {
                throw new InvalidArgumentException('Coluna não encontrada: ' . ($ix === null ? "{$a}.{$x}" : "{$b}.{$y}") . '.');
            }
            $ps[] = [$ix, $iy];
        }
        $nome = trim($papel) === '' ? 'Ligação manual' : trim($papel);
        $cand = ['a' => $a, 'b' => $b, 'tipo' => 'manual', 'conf' => 'alta', 'estado' => 'confirmada', 'evid' => 'Criada à mão no admin.', 'aviso' => null,
            'papeis' => [['nome' => $nome, 'fonte' => 'Criada à mão', 'pares' => array_map(static fn (array $p): array => [$p[0], $p[1]], $ps)]]];
        $id = $this->existente($tabs[$a], $tabs[$b], 'manual');
        if ($id !== null) {
            $this->acrescentarPapel($id, $cand['papeis'][0]['nome'], 'Criada à mão', $ps);

            return $id;
        }

        return $this->inserir($cand, $tabs[$a], $tabs[$b], $ps, $usuarioId, $agora);
    }

    /**
     * Grava sugestões novas. Não altera o que já existe (uma relação é identificada por origem, destino e tipo).
     *
     * @param list<array<string, mixed>> $candidatos saída de Sugestor::gerar (ou FKs no mesmo formato, com 'estado')
     * @return array{novas: int, existentes: int, ignoradas: int}
     */
    public function inserirCandidatos(array $candidatos, ?int $usuarioId, string $agora, bool $transacao = true): array
    {
        $n = ['novas' => 0, 'existentes' => 0, 'ignoradas' => 0];
        $tabs = $this->idsTabelas();
        $cols = $this->idsColunas();
        if ($transacao) {
            $this->pdo->beginTransaction();
        }
        try {
            foreach ($candidatos as $c) {
                $ta = $tabs[$c['a']] ?? null;
                $tb = $tabs[$c['b']] ?? null;
                if ($ta === null || $tb === null || $ta === $tb) {
                    $n['ignoradas']++;
                    continue;
                }
                $papeis = [];
                foreach ($c['papeis'] as $p) {
                    $pares = [];
                    foreach ($p['pares'] as [$x, $y]) {
                        $ix = $cols[$ta][strtoupper($x)] ?? null;
                        $iy = $cols[$tb][strtoupper($y)] ?? null;
                        if ($ix !== null && $iy !== null) {
                            $pares[] = [$ix, $iy];
                        }
                    }
                    if ($pares !== []) {
                        $papeis[] = ['nome' => $p['nome'], 'fonte' => $p['fonte'] ?? null, 'pares' => $pares];
                    }
                }
                if ($papeis === []) {
                    $n['ignoradas']++;
                    continue;
                }
                $id = $this->existente($ta, $tb, (string) $c['tipo']);
                if ($id !== null) {
                    // FKs novas de uma relação já conhecida entram como papéis novos; o resto não muda
                    if ($c['tipo'] === 'fk') {
                        foreach ($papeis as $p) {
                            $this->acrescentarPapel($id, (string) $p['nome'], $p['fonte'], $p['pares']);
                        }
                    }
                    $n['existentes']++;
                    continue;
                }
                $this->inserirComPapeis($c, $ta, $tb, $papeis, $usuarioId, $agora);
                $n['novas']++;
            }
            if ($transacao) {
                $this->pdo->commit();
            }
        } catch (\Throwable $e) {
            if ($transacao) {
                $this->pdo->rollBack();
            }
            throw $e;
        }

        return $n;
    }

    /* ---------- internos ---------- */

    /** @return array<string, int> */
    private function idsTabelas(): array
    {
        $out = [];
        foreach ($this->pdo->query('SELECT id, tabela FROM dbo.dic_tabela')->fetchAll() as $t) {
            $out[strtoupper((string) $t['tabela'])] = (int) $t['id'];
        }

        return $out;
    }

    /** @return array<int, array<string, int>> */
    private function idsColunas(): array
    {
        $out = [];
        foreach ($this->pdo->query('SELECT id, dic_tabela_id, coluna FROM dbo.dic_coluna')->fetchAll() as $c) {
            $out[(int) $c['dic_tabela_id']][strtoupper((string) $c['coluna'])] = (int) $c['id'];
        }

        return $out;
    }

    private function existente(int $a, int $b, string $tipo): ?int
    {
        $s = $this->pdo->prepare('SELECT id FROM dbo.dic_relacao_tabela WHERE origem_tabela_id = :a AND destino_tabela_id = :b AND tipo = :t');
        $s->execute([':a' => $a, ':b' => $b, ':t' => $tipo]);
        $id = $s->fetchColumn();
        $s->closeCursor();

        return $id === false ? null : (int) $id;
    }

    /** @param array<string, mixed> $c @param list<array{0: int, 1: int}> $pares */
    private function inserir(array $c, int $ta, int $tb, array $pares, int $usuarioId, string $agora): int
    {
        return $this->inserirComPapeis($c, $ta, $tb, [['nome' => $c['papeis'][0]['nome'], 'fonte' => $c['papeis'][0]['fonte'] ?? null, 'pares' => $pares]], $usuarioId, $agora);
    }

    /** @param array<string, mixed> $c @param list<array{nome: string, fonte: ?string, pares: list<array{0: int, 1: int}>}> $papeis */
    private function inserirComPapeis(array $c, int $ta, int $tb, array $papeis, ?int $usuarioId, string $agora): int
    {
        $estado = (string) ($c['estado'] ?? 'sugerida');
        $decidido = $estado !== 'sugerida';
        $this->pdo->prepare(
            'INSERT INTO dbo.dic_relacao_tabela (origem_tabela_id, destino_tabela_id, tipo, estado, confianca, evidencia, aviso, criado_por, criado_em, decidido_por, decidido_em) '
            . 'VALUES (:a, :b, :t, :e, :c, :ev, :av, :u, :cr, :du, :de)'
        )->execute([
            ':a' => $ta, ':b' => $tb, ':t' => $c['tipo'], ':e' => $estado, ':c' => $c['conf'], ':ev' => mb_substr((string) $c['evid'], 0, 500),
            ':av' => ($c['aviso'] ?? null) === null ? null : mb_substr((string) $c['aviso'], 0, 500), ':u' => $usuarioId, ':cr' => $agora,
            ':du' => $decidido ? $usuarioId : null, ':de' => $decidido ? $agora : null,
        ]);
        $id = (int) $this->existente($ta, $tb, (string) $c['tipo']);
        foreach ($papeis as $p) {
            $this->acrescentarPapel($id, (string) $p['nome'], $p['fonte'], $p['pares']);
        }

        return $id;
    }

    /** @param list<array{0: int, 1: int}> $pares */
    private function acrescentarPapel(int $relacaoId, string $nome, ?string $fonte, array $pares): void
    {
        $nome = mb_substr($nome, 0, 200);
        $s = $this->pdo->prepare('SELECT id FROM dbo.dic_relacao_papel WHERE relacao_id = :r AND nome = :n');
        $s->execute([':r' => $relacaoId, ':n' => $nome]);
        $pid = $s->fetchColumn();
        $s->closeCursor();
        if ($pid !== false) {
            return; // o papel já existe: não duplica
        }
        $this->pdo->prepare('INSERT INTO dbo.dic_relacao_papel (relacao_id, nome, fonte) VALUES (:r, :n, :f)')
            ->execute([':r' => $relacaoId, ':n' => $nome, ':f' => $fonte === null ? null : mb_substr($fonte, 0, 500)]);
        $s->execute([':r' => $relacaoId, ':n' => $nome]);
        $pid = (int) $s->fetchColumn();
        $s->closeCursor();
        $ins = $this->pdo->prepare('INSERT INTO dbo.dic_relacao_par (papel_id, ordem, origem_coluna_id, destino_coluna_id) VALUES (:p, :o, :x, :y)');
        foreach (array_values($pares) as $i => [$x, $y]) {
            $ins->execute([':p' => $pid, ':o' => $i, ':x' => $x, ':y' => $y]);
        }
    }
}
