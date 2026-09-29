<?php

declare(strict_types=1);

namespace Elogica\Metadata;

use PDO;

/** Lê tabelas, colunas e FKs do banco de um cliente (somente catálogo do sistema; nunca linhas de dados). */
final class SchemaReader
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * @param list<string> $exclusoes padrões LIKE do T-SQL
     * @return array{tabelas: array<string, array<string, mixed>>, fks: list<array<string, string>>}
     */
    public function ler(string $prefixo, array $exclusoes): array
    {
        $tabelas = $this->tabelas($prefixo, $exclusoes);

        return ['tabelas' => $tabelas, 'fks' => $this->fks($tabelas)];
    }

    /**
     * FKs declaradas entre as tabelas lidas.
     *
     * @param array<string, array<string, mixed>> $tabelas
     * @return list<array{origem: string, col_origem: string, destino: string, col_destino: string}>
     */
    private function fks(array $tabelas): array
    {
        $vistas = [];
        foreach ($this->pdo->query(self::SQL_FKS)->fetchAll() as $r) {
            $origem = Sync::chave((string) $r['sch_origem'], (string) $r['tab_origem']);
            $destino = Sync::chave((string) $r['sch_destino'], (string) $r['tab_destino']);
            if (!isset($tabelas[$origem], $tabelas[$destino])) {
                continue;
            }
            $fk = ['origem' => $origem, 'col_origem' => strtoupper((string) $r['col_origem']), 'destino' => $destino, 'col_destino' => strtoupper((string) $r['col_destino'])];
            $vistas[implode('|', $fk)] = $fk; // o catálogo repete FKs duplicadas
        }

        return array_values($vistas);
    }

    /**
     * @param list<string> $exclusoes
     * @return array<string, array{schema: string, tabela: string, colunas: array<string, array<string, mixed>>}>
     */
    private function tabelas(string $prefixo, array $exclusoes): array
    {
        $params = [':prefixo' => str_replace('_', '[_]', strtoupper($prefixo)) . '%'];
        $where = 'UPPER(t.name) LIKE :prefixo';
        foreach (array_values($exclusoes) as $i => $padrao) {
            $where .= " AND UPPER(t.name) NOT LIKE :ex{$i}";
            $params[":ex{$i}"] = strtoupper($padrao);
        }
        $stmt = $this->pdo->prepare(self::sqlTabelas($where));
        $stmt->execute($params);

        $out = [];
        foreach ($stmt->fetchAll() as $r) {
            $key = Sync::chave((string) $r['schema'], (string) $r['tabela']);
            $out[$key]['schema'] = (string) $r['schema'];
            $out[$key]['tabela'] = (string) $r['tabela'];
            $out[$key]['colunas'][strtoupper((string) $r['coluna'])] = [
                'coluna' => (string) $r['coluna'],
                'ordem' => (int) $r['ordem'],
                'tipo' => (string) $r['tipo'],
                'tamanho' => (int) $r['tamanho'],
                'nulo' => (bool) $r['nulo'],
            ];
        }

        return $out;
    }

    public static function sqlTabelas(string $where): string
    {
        return 'SELECT s.name AS [schema], t.name AS tabela, c.name AS coluna, c.column_id AS ordem, ty.name AS tipo, '
            . "CASE WHEN ty.name IN ('nchar', 'nvarchar') AND c.max_length > 0 THEN c.max_length / 2 ELSE c.max_length END AS tamanho, "
            . 'c.is_nullable AS nulo '
            . 'FROM sys.tables t '
            . 'JOIN sys.schemas s ON s.schema_id = t.schema_id '
            . 'JOIN sys.columns c ON c.object_id = t.object_id '
            . 'JOIN sys.types ty ON ty.user_type_id = c.user_type_id '
            . "WHERE {$where} ORDER BY s.name, t.name, c.column_id";
    }

    public const SQL_FKS = 'SELECT OBJECT_SCHEMA_NAME(fk.parent_object_id) AS sch_origem, OBJECT_NAME(fk.parent_object_id) AS tab_origem, '
        . 'pc.name AS col_origem, OBJECT_SCHEMA_NAME(fk.referenced_object_id) AS sch_destino, '
        . 'OBJECT_NAME(fk.referenced_object_id) AS tab_destino, rc.name AS col_destino '
        . 'FROM sys.foreign_key_columns fkc '
        . 'JOIN sys.foreign_keys fk ON fk.object_id = fkc.constraint_object_id '
        . 'JOIN sys.columns pc ON pc.object_id = fkc.parent_object_id AND pc.column_id = fkc.parent_column_id '
        . 'JOIN sys.columns rc ON rc.object_id = fkc.referenced_object_id AND rc.column_id = fkc.referenced_column_id';
}
