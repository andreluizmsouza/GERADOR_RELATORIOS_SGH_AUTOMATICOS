<?php

declare(strict_types=1);

namespace Elogica\Metadata;

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

    /** @param list<array<string, string>> $fks @return int relações novas */
    private function gravarFks(array $fks): int
    {
        if ($fks === []) {
            return 0;
        }
        $dic = $this->carregar();
        $existe = $this->pdo->prepare('SELECT COUNT(*) FROM dbo.dic_relacao WHERE origem_coluna_id = :o AND destino_coluna_id = :d');
        $ins = $this->pdo->prepare("INSERT INTO dbo.dic_relacao (origem_coluna_id, destino_coluna_id, origem, confirmada) VALUES (:o, :d, 'fk', 1)");
        $novas = 0;
        foreach ($fks as $fk) {
            $o = $dic[$fk['origem']]['colunas'][$fk['col_origem']]['id'] ?? null;
            $d = $dic[$fk['destino']]['colunas'][$fk['col_destino']]['id'] ?? null;
            if ($o === null || $d === null) {
                continue;
            }
            $existe->execute([':o' => $o, ':d' => $d]);
            if ((int) $existe->fetchColumn() === 0) {
                $ins->execute([':o' => $o, ':d' => $d]);
                $novas++;
            }
            $existe->closeCursor();
        }

        return $novas;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listarTabelas(string $filtro): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT t.id, t.[schema], t.tabela, t.descricao, t.situacao, t.existe_no_banco, '
            . '(SELECT COUNT(*) FROM dbo.dic_coluna c WHERE c.dic_tabela_id = t.id) AS colunas '
            . 'FROM dbo.dic_tabela t WHERE t.tabela LIKE :q ORDER BY t.tabela'
        );
        $stmt->execute([':q' => '%' . $filtro . '%']);

        return $stmt->fetchAll();
    }
}
