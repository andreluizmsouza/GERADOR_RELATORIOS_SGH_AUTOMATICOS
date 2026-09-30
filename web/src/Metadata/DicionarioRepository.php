<?php

declare(strict_types=1);

namespace Elogica\Metadata;

use Elogica\Relacionamentos\RelacionamentoRepository;
use PDO;

/** Persistência do dicionário (matriz única). SQL portável entre SQL Server e SQLite (testes). */
final class DicionarioRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * @return array<string, array{id: int, schema: string, tabela: string, existe: bool, colunas: array<string, array<string, mixed>>}>
     */
    public function carregar(): array
    {
        $out = [];
        $porId = [];
        foreach ($this->pdo->query('SELECT id, [schema], tabela, existe_no_banco FROM dbo.dic_tabela')->fetchAll() as $t) {
            $key = Sync::chave((string) $t['schema'], (string) $t['tabela']);
            $out[$key] = ['id' => (int) $t['id'], 'schema' => (string) $t['schema'], 'tabela' => (string) $t['tabela'], 'existe' => (bool) $t['existe_no_banco'], 'colunas' => []];
            $porId[(int) $t['id']] = $key;
        }
        foreach ($this->pdo->query('SELECT id, dic_tabela_id, coluna, tipo, tamanho, nulo, existe_no_banco FROM dbo.dic_coluna')->fetchAll() as $c) {
            $key = $porId[(int) $c['dic_tabela_id']] ?? null;
            if ($key === null) {
                continue;
            }
            $out[$key]['colunas'][strtoupper((string) $c['coluna'])] = [
                'id' => (int) $c['id'], 'coluna' => (string) $c['coluna'], 'tipo' => (string) $c['tipo'],
                'tamanho' => (int) $c['tamanho'], 'nulo' => (bool) $c['nulo'], 'existe' => (bool) $c['existe_no_banco'],
            ];
        }

        return $out;
    }

    /**
     * Grava o plano numa transação. Nunca apaga registros nem altera descrições.
     *
     * @param array<string, mixed> $plano  saída de Sync::planejar
     * @param list<array<string, string>> $fks
     * @return array<string, int> contagens do que foi gravado
     */
    public function aplicar(array $plano, array $fks): array
    {
        $n = ['tabelas' => 0, 'colunas' => 0, 'relacoes' => 0];
        $this->pdo->beginTransaction();
        try {
            $insTab = $this->pdo->prepare('INSERT INTO dbo.dic_tabela ([schema], tabela) VALUES (:s, :t)');
            $selTab = $this->pdo->prepare('SELECT id FROM dbo.dic_tabela WHERE [schema] = :s AND tabela = :t');
            foreach ($plano['tabelas_novas'] as $tb) {
                $insTab->execute([':s' => $tb['schema'], ':t' => $tb['tabela']]);
                $selTab->execute([':s' => $tb['schema'], ':t' => $tb['tabela']]);
                $id = (int) $selTab->fetchColumn();
                $selTab->closeCursor();
                foreach ($tb['colunas'] as $col) {
                    $this->inserirColuna($id, $col);
                    $n['colunas']++;
                }
                $n['tabelas']++;
            }
            foreach ($plano['colunas_novas'] as $col) {
                $this->inserirColuna((int) $col['tabela_id'], $col);
                $n['colunas']++;
            }
            $this->marcar('dbo.dic_tabela', $plano['tabelas_ausentes'], 0);
            $this->marcar('dbo.dic_tabela', $plano['tabelas_reativadas'], 1);
            $this->marcar('dbo.dic_coluna', $plano['colunas_ausentes'], 0);
            $this->marcar('dbo.dic_coluna', $plano['colunas_reativadas'], 1);

            $upd = $this->pdo->prepare('UPDATE dbo.dic_coluna SET tipo = :tipo, tamanho = :tam, nulo = :nulo WHERE id = :id');
            foreach ($plano['colunas_alteradas'] as $c) {
                $upd->execute([':tipo' => $c['novo']['tipo'], ':tam' => $c['novo']['tamanho'], ':nulo' => $c['novo']['nulo'] ? 1 : 0, ':id' => $c['id']]);
            }
            $n['relacoes'] = $this->gravarFks($fks);
            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }

        return $n;
    }

    /** @param array<string, mixed> $col */
    private function inserirColuna(int $tabelaId, array $col): void
    {
        $this->pdo->prepare('INSERT INTO dbo.dic_coluna (dic_tabela_id, coluna, ordem, tipo, tamanho, nulo) VALUES (:t, :c, :o, :ti, :ta, :n)')
            ->execute([':t' => $tabelaId, ':c' => $col['coluna'], ':o' => $col['ordem'], ':ti' => $col['tipo'], ':ta' => $col['tamanho'], ':n' => $col['nulo'] ? 1 : 0]);
    }

    /** @param list<array<string, mixed>> $itens */
    private function marcar(string $tabela, array $itens, int $existe): void
    {
        $stmt = $this->pdo->prepare("UPDATE {$tabela} SET existe_no_banco = :e WHERE id = :id");
        foreach ($itens as $i) {
            $stmt->execute([':e' => $existe, ':id' => $i['id']]);
        }
    }

    /**
     * FKs declaradas viram ligações já confirmadas; uma FK composta é um papel com vários pares de colunas.
     *
     * @param list<array{origem: string, destino: string, nome: string, pares: list<array{0: string, 1: string}>}> $fks
     * @return int relações novas
     */
    private function gravarFks(array $fks): int
    {
        if ($fks === []) {
            return 0;
        }
        $nome = static fn (string $chave): string => strtoupper(str_contains($chave, '.') ? explode('.', $chave, 2)[1] : $chave);
        $cands = [];
        foreach ($fks as $fk) {
            $a = $nome($fk['origem']);
            $b = $nome($fk['destino']);
            $cands["{$a}|{$b}"] ??= [
                'a' => $a, 'b' => $b, 'tipo' => 'fk', 'conf' => 'alta', 'estado' => 'confirmada',
                'evid' => 'Chave estrangeira declarada no banco.', 'aviso' => null, 'papeis' => [],
            ];
            $cands["{$a}|{$b}"]['papeis'][] = ['nome' => $fk['nome'], 'fonte' => 'FOREIGN KEY no SQL Server', 'pares' => $fk['pares']];
        }

        return (new RelacionamentoRepository($this->pdo))->inserirCandidatos(array_values($cands), null, date('Y-m-d H:i:s'), false)['novas'];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listarTabelas(string $filtro, string $status = ''): array
    {
        $sql = 'SELECT t.id, t.[schema], t.tabela, t.descricao, t.situacao, t.existe_no_banco, t.status_revisao, '
            . '(SELECT COUNT(*) FROM dbo.dic_coluna c WHERE c.dic_tabela_id = t.id) AS colunas, '
            . "(SELECT COUNT(*) FROM dbo.dic_coluna c WHERE c.dic_tabela_id = t.id AND c.status_revisao = 'revisado') AS revisadas "
            . 'FROM dbo.dic_tabela t WHERE t.tabela LIKE :q';
        $params = [':q' => '%' . $filtro . '%'];
        if ($status !== '') {
            $sql .= ' AND t.status_revisao = :s';
            $params[':s'] = $status;
        }
        $stmt = $this->pdo->prepare($sql . ' ORDER BY t.tabela');
        $stmt->execute($params);

        return $stmt->fetchAll();
    }

    /** @return array<string, mixed>|null */
    public function tabela(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT id, [schema], tabela, descricao, situacao, existe_no_banco, status_revisao, revisado_por, revisado_em FROM dbo.dic_tabela WHERE id = :id');
        $stmt->execute([':id' => $id]);
        $r = $stmt->fetch();

        return $r === false ? null : $r;
    }

    /**
     * Colunas da tabela com seus valores possíveis, na ordem original.
     *
     * @return list<array<string, mixed>>
     */
    public function colunasDaTabela(int $tabelaId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, coluna, ordem, tipo, tamanho, nulo, descricao, nome_negocio, sinonimos, sensivel, existe_no_banco, status_revisao '
            . 'FROM dbo.dic_coluna WHERE dic_tabela_id = :t ORDER BY ordem, id'
        );
        $stmt->execute([':t' => $tabelaId]);
        $cols = $stmt->fetchAll();
        $porId = [];
        foreach ($cols as $i => $c) {
            $cols[$i]['valores'] = [];
            $porId[(int) $c['id']] = $i;
        }
        $v = $this->pdo->prepare(
            'SELECT v.dic_coluna_id, v.codigo, v.significado FROM dbo.dic_coluna_valor v '
            . 'JOIN dbo.dic_coluna c ON c.id = v.dic_coluna_id WHERE c.dic_tabela_id = :t ORDER BY v.ordem, v.id'
        );
        $v->execute([':t' => $tabelaId]);
        foreach ($v->fetchAll() as $r) {
            $i = $porId[(int) $r['dic_coluna_id']] ?? null;
            if ($i !== null) {
                $cols[$i]['valores'][] = [(string) $r['codigo'], (string) $r['significado']];
            }
        }

        return $cols;
    }

    /**
     * Grava a revisão de uma tabela numa transação. Só toca nos campos editáveis; a estrutura vem da sincronização.
     *
     * @param array<string, string> $tabela
     * @param array<int, array<string, mixed>> $colunas  por id de coluna (já validado e restrito à tabela)
     * @return array{colunas: int, valores: int}
     */
    public function salvarRevisao(int $tabelaId, array $tabela, array $colunas, int $usuarioId, string $agora): array
    {
        $n = ['colunas' => 0, 'valores' => 0];
        $atuais = [];
        foreach ($this->colunasDaTabela($tabelaId) as $c) {
            $atuais[(int) $c['id']] = $c;
        }
        $this->pdo->beginTransaction();
        try {
            $revisada = $tabela['status_revisao'] === 'revisado';
            $this->pdo->prepare(
                'UPDATE dbo.dic_tabela SET descricao = :d, situacao = :s, status_revisao = :st, revisado_por = :u, revisado_em = :e WHERE id = :id'
            )->execute([
                ':d' => $tabela['descricao'] === '' ? null : $tabela['descricao'], ':s' => $tabela['situacao'], ':st' => $tabela['status_revisao'],
                ':u' => $revisada ? $usuarioId : null, ':e' => $revisada ? $agora : null, ':id' => $tabelaId,
            ]);

            $upd = $this->pdo->prepare('UPDATE dbo.dic_coluna SET descricao = :d, nome_negocio = :nn, sinonimos = :si, sensivel = :se, status_revisao = :st WHERE id = :id');
            $del = $this->pdo->prepare('DELETE FROM dbo.dic_coluna_valor WHERE dic_coluna_id = :id');
            $ins = $this->pdo->prepare('INSERT INTO dbo.dic_coluna_valor (dic_coluna_id, codigo, significado, ordem) VALUES (:id, :c, :s, :o)');
            foreach ($colunas as $id => $c) {
                $a = $atuais[$id] ?? null;
                if ($a === null) {
                    continue;
                }
                $mudou = (string) ($a['descricao'] ?? '') !== $c['descricao'] || (string) ($a['nome_negocio'] ?? '') !== $c['nome_negocio']
                    || (string) ($a['sinonimos'] ?? '') !== $c['sinonimos'] || (bool) $a['sensivel'] !== $c['sensivel'] || $a['status_revisao'] !== $c['status_revisao'];
                if ($mudou) {
                    $upd->execute([
                        ':d' => $c['descricao'] === '' ? null : $c['descricao'], ':nn' => $c['nome_negocio'] === '' ? null : $c['nome_negocio'],
                        ':si' => $c['sinonimos'] === '' ? null : $c['sinonimos'], ':se' => $c['sensivel'] ? 1 : 0, ':st' => $c['status_revisao'], ':id' => $id,
                    ]);
                    $n['colunas']++;
                }
                if ($a['valores'] !== $c['valores']) {
                    $del->execute([':id' => $id]);
                    foreach (array_values($c['valores']) as $i => [$codigo, $significado]) {
                        $ins->execute([':id' => $id, ':c' => $codigo, ':s' => $significado, ':o' => $i]);
                    }
                    $n['valores']++;
                }
            }
            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }

        return $n;
    }

    /**
     * Textos atuais (descrições e valores possíveis), por nome de tabela em maiúsculas.
     *
     * @return array<string, array{id: int, descricao: string, colunas: array<string, array{id: int, coluna: string, descricao: string, valores: list<array{0: string, 1: string}>}>}>
     */
    public function carregarTextos(): array
    {
        $out = [];
        $tabPorId = [];
        foreach ($this->pdo->query('SELECT id, tabela, descricao FROM dbo.dic_tabela')->fetchAll() as $t) {
            $k = strtoupper((string) $t['tabela']);
            $out[$k] = ['id' => (int) $t['id'], 'descricao' => (string) ($t['descricao'] ?? ''), 'colunas' => []];
            $tabPorId[(int) $t['id']] = $k;
        }
        $colPorId = [];
        foreach ($this->pdo->query('SELECT id, dic_tabela_id, coluna, descricao FROM dbo.dic_coluna')->fetchAll() as $c) {
            $tk = $tabPorId[(int) $c['dic_tabela_id']] ?? null;
            if ($tk === null) {
                continue;
            }
            $ck = strtoupper((string) $c['coluna']);
            $out[$tk]['colunas'][$ck] = ['id' => (int) $c['id'], 'coluna' => (string) $c['coluna'], 'descricao' => (string) ($c['descricao'] ?? ''), 'valores' => []];
            $colPorId[(int) $c['id']] = [$tk, $ck];
        }
        foreach ($this->pdo->query('SELECT dic_coluna_id, codigo, significado FROM dbo.dic_coluna_valor ORDER BY ordem, id')->fetchAll() as $v) {
            [$tk, $ck] = $colPorId[(int) $v['dic_coluna_id']] ?? [null, null];
            if ($tk !== null) {
                $out[$tk]['colunas'][$ck]['valores'][] = [(string) $v['codigo'], (string) $v['significado']];
            }
        }

        return $out;
    }

    /**
     * Grava a documentação: preenche o que está vazio e sobrescreve os conflitos apenas das tabelas escolhidas.
     *
     * @param array<string, array<string, mixed>> $itens    DocImport::planejar()['itens']
     * @param array<string, true> $sobrescrever            nomes de tabela (maiúsculas) cujos conflitos devem ser aplicados
     * @return array{descricoes: int, valores: int, conflitos_mantidos: int}
     */
    public function aplicarDocumentacao(array $itens, array $sobrescrever): array
    {
        $n = ['descricoes' => 0, 'valores' => 0, 'conflitos_mantidos' => 0];
        $this->pdo->beginTransaction();
        try {
            $updTab = $this->pdo->prepare('UPDATE dbo.dic_tabela SET descricao = :d WHERE id = :id');
            $updCol = $this->pdo->prepare('UPDATE dbo.dic_coluna SET descricao = :d WHERE id = :id');
            $delVal = $this->pdo->prepare('DELETE FROM dbo.dic_coluna_valor WHERE dic_coluna_id = :id');
            $insVal = $this->pdo->prepare('INSERT INTO dbo.dic_coluna_valor (dic_coluna_id, codigo, significado, ordem) VALUES (:id, :c, :s, :o)');
            foreach ($itens as $nome => $item) {
                foreach ($item['diffs'] as $d) {
                    if ($d['estado'] === 'conflito' && !isset($sobrescrever[$nome])) {
                        $n['conflitos_mantidos']++;
                        continue;
                    }
                    if ($d['tipo'] === 'tabela_desc') {
                        $updTab->execute([':d' => mb_substr($d['doc'], 0, 1000), ':id' => $d['id']]);
                        $n['descricoes']++;
                    } elseif ($d['tipo'] === 'coluna_desc') {
                        $updCol->execute([':d' => mb_substr($d['doc'], 0, 2000), ':id' => $d['id']]);
                        $n['descricoes']++;
                    } else {
                        $delVal->execute([':id' => $d['id']]);
                        foreach (array_values($d['valores']) as $i => [$codigo, $significado]) {
                            $insVal->execute([':id' => $d['id'], ':c' => $codigo, ':s' => $significado, ':o' => $i]);
                        }
                        $n['valores']++;
                    }
                }
            }
            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }

        return $n;
    }
}
