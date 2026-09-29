<?php

declare(strict_types=1);

namespace Elogica\Metadata;

/**
 * Compara o banco de referência com o dicionário. Lógica pura: não acessa banco.
 *
 * Nunca remove nada do dicionário (só marca como ausente) e nunca altera descrições.
 */
final class Sync
{
    public static function chave(string $schema, string $tabela): string
    {
        return strtoupper($schema . '.' . $tabela);
    }

    /**
     * @param array<string, array<string, mixed>> $banco  saída de SchemaReader (tabelas)
     * @param array<string, array<string, mixed>> $dic    dicionário atual (DicionarioRepository::carregar)
     * @return array{
     *   tabelas_novas: list<array<string, mixed>>, tabelas_ausentes: list<array<string, mixed>>,
     *   tabelas_reativadas: list<array<string, mixed>>, colunas_novas: list<array<string, mixed>>,
     *   colunas_ausentes: list<array<string, mixed>>, colunas_reativadas: list<array<string, mixed>>,
     *   colunas_alteradas: list<array<string, mixed>>, sem_mudanca: int
     * }
     */
    public static function planejar(array $banco, array $dic): array
    {
        $p = [
            'tabelas_novas' => [], 'tabelas_ausentes' => [], 'tabelas_reativadas' => [],
            'colunas_novas' => [], 'colunas_ausentes' => [], 'colunas_reativadas' => [],
            'colunas_alteradas' => [], 'sem_mudanca' => 0,
        ];

        foreach ($banco as $key => $tb) {
            if (!isset($dic[$key])) {
                $p['tabelas_novas'][] = $tb;
                continue;
            }
            $d = $dic[$key];
            if (!$d['existe']) {
                $p['tabelas_reativadas'][] = ['id' => $d['id'], 'tabela' => $d['tabela']];
            }
            foreach ($tb['colunas'] as $ck => $col) {
                $dc = $d['colunas'][$ck] ?? null;
                if ($dc === null) {
                    $p['colunas_novas'][] = ['tabela_id' => $d['id'], 'tabela' => $d['tabela']] + $col;
                    continue;
                }
                if (!$dc['existe']) {
                    $p['colunas_reativadas'][] = ['id' => $dc['id'], 'tabela' => $d['tabela'], 'coluna' => $col['coluna']];
                }
                if ($dc['tipo'] !== $col['tipo'] || $dc['tamanho'] !== $col['tamanho'] || $dc['nulo'] !== $col['nulo']) {
                    $p['colunas_alteradas'][] = [
                        'id' => $dc['id'], 'tabela' => $d['tabela'], 'coluna' => $col['coluna'],
                        'de' => self::descreve($dc), 'para' => self::descreve($col), 'novo' => $col,
                    ];
                } elseif ($dc['existe']) {
                    $p['sem_mudanca']++;
                }
            }
            foreach ($d['colunas'] as $ck => $dc) {
                if ($dc['existe'] && !isset($tb['colunas'][$ck])) {
                    $p['colunas_ausentes'][] = ['id' => $dc['id'], 'tabela' => $d['tabela'], 'coluna' => $dc['coluna']];
                }
            }
        }
        foreach ($dic as $key => $d) {
            if ($d['existe'] && !isset($banco[$key])) {
                $p['tabelas_ausentes'][] = ['id' => $d['id'], 'tabela' => $d['tabela']];
            }
        }

        return $p;
    }

    /** @param array<string, mixed> $c */
    private static function descreve(array $c): string
    {
        return sprintf('%s(%d) %s', $c['tipo'], $c['tamanho'], $c['nulo'] ? 'NULL' : 'NOT NULL');
    }

    /** @param array<string, list<mixed>> $plano */
    public static function temMudancas(array $plano): bool
    {
        foreach ($plano as $k => $v) {
            if ($k !== 'sem_mudanca' && $v !== []) {
                return true;
            }
        }

        return false;
    }
}
