<?php

declare(strict_types=1);

namespace Elogica\Metadata;

/** Consolida vários arquivos de documentação em uma definição por tabela, escolhendo entre versões duplicadas. */
final class DocLote
{
    /**
     * @param list<array{nome: string, bytes: string}> $arquivos
     * @return array{tabelas: array<string, array<string, mixed>>, avisos: list<string>, ignorados: int, sem_tabela: int}
     */
    public static function consolidar(array $arquivos): array
    {
        $porTabela = [];
        $avisos = [];
        $stems = [];
        foreach ($arquivos as $a) {
            $stems[strtoupper(pathinfo($a['nome'], PATHINFO_FILENAME))] = true;
        }
        $ignorados = 0;
        $semTabela = 0;
        foreach ($arquivos as $a) {
            if (preg_match('/^(anterior_|ant(?=mttb))/i', $a['nome']) === 1) {
                $ignorados++;
                continue;
            }
            $docs = DocParser::parse($a['bytes'], $a['nome']);
            if ($docs === []) {
                $semTabela++;
                continue;
            }
            $stem = strtoupper(pathinfo($a['nome'], PATHINFO_FILENAME));
            foreach ($docs as $d) {
                // Erro de digitação no cabeçalho (ex.: MTTEND em mttbend.htm): o nome do arquivo é o da tabela,
                // desde que nenhum outro arquivo se chame como o nome declarado.
                if (count($docs) === 1 && $d['tabela'] !== $stem && !isset($stems[$d['tabela']])
                    && preg_match('/^MTTB[A-Z0-9_]+$/', $stem) === 1 && levenshtein($d['tabela'], $stem) <= 2) {
                    $avisos[] = sprintf('%s: o cabeçalho de %s diz "%s"; corrigido para %s.', $stem, $a['nome'], $d['tabela'], $stem);
                    $d['tabela'] = $stem;
                }
                $porTabela[$d['tabela']][] = $d;
            }
        }

        $tabelas = [];
        ksort($porTabela);
        foreach ($porTabela as $nome => $candidatos) {
            usort($candidatos, static fn (array $x, array $y): int => strcmp($x['arquivo'], $y['arquivo']));
            $escolhido = $candidatos[0];
            foreach ($candidatos as $c) {
                if (strtoupper(pathinfo($c['arquivo'], PATHINFO_FILENAME)) === $nome) {
                    $escolhido = $c;
                    break;
                }
            }
            if (count($candidatos) > 1) {
                $avisos[] = sprintf('%s: descrita em %d arquivos (%s); usado %s.', $nome, count($candidatos), implode(', ', array_column($candidatos, 'arquivo')), $escolhido['arquivo']);
            } elseif (strtoupper(pathinfo($escolhido['arquivo'], PATHINFO_FILENAME)) !== $nome) {
                $avisos[] = sprintf('%s: vem do arquivo %s, cujo nome é diferente da tabela.', $nome, $escolhido['arquivo']);
            }
            $tabelas[$nome] = $escolhido;
        }

        return ['tabelas' => $tabelas, 'avisos' => $avisos, 'ignorados' => $ignorados, 'sem_tabela' => $semTabela];
    }
}
