<?php

declare(strict_types=1);

namespace Elogica\Relacionamentos;

/**
 * Sugere relacionamentos entre tabelas a partir do dicionário. Lógica pura: não acessa banco nem IA.
 *
 * Regras (as FKs declaradas vêm da sincronização):
 *  - doc:   a descrição de um campo cita "(Tabela MTTBxxx)"; os pares de colunas são deduzidos por nome e tipo.
 *  - chave: tabelas que carregam a chave composta de uma tabela-mãe (ex.: CODEMP+REGIAO+NUCLEO+CONTRATO de MTTBCON).
 * Tudo sai como sugestão: quem confirma é uma pessoa.
 */
final class Sugestor
{
    private const NOTA_MINIMA = 6;
    private const FREQ_CHAVE = 0.15;   // fração das tabelas em que uma coluna deve aparecer para contar como chave comum
    private const SUPORTE_CHAVE = 10;  // tabelas mínimas que devem repetir uma chave composta

    /**
     * @param array<string, array{colunas: list<array{c: string, t: string, d?: string}>}> $tabelas por nome em maiúsculas; c = coluna, t = tipo ("char(10)"), d = descrição
     * @param list<string> $maes tabelas-mãe da chave composta
     * @return list<array<string, mixed>>
     */
    public static function gerar(array $tabelas, array $maes = []): array
    {
        $idx = self::indexar($tabelas);
        $out = array_merge(self::porDocumentacao($idx), self::porChave($idx, array_map('strtoupper', $maes)));
        usort($out, static fn (array $x, array $y): int => [$x['tipo'], $x['a'], $x['b']] <=> [$y['tipo'], $y['a'], $y['b']]);

        return $out;
    }

    /** @return array<string, array{cols: array<string, array<string, mixed>>, ordem: list<string>}> */
    private static function indexar(array $tabelas): array
    {
        $idx = [];
        foreach ($tabelas as $nome => $t) {
            $cols = [];
            $ordem = [];
            foreach ($t['colunas'] as $i => $c) {
                $k = strtoupper($c['c']);
                $cols[$k] = ['c' => $c['c'], 't' => $c['t'], 'd' => $c['d'] ?? '', 'i' => $i];
                $ordem[] = $k;
            }
            $idx[strtoupper($nome)] = ['cols' => $cols, 'ordem' => $ordem];
        }

        return $idx;
    }

    /* ---------- documentação ---------- */

    private static function porDocumentacao(array $idx): array
    {
        $freq = [];
        foreach ($idx as $t) {
            foreach ($t['ordem'] as $k) {
                $freq[$k] = ($freq[$k] ?? 0) + 1;
            }
        }
        $comum = static fn (string $k): bool => ($freq[$k] ?? 0) >= max(20, (int) (count($idx) * self::FREQ_CHAVE));

        $grupos = [];
        foreach ($idx as $a => $ta) {
            foreach ($ta['cols'] as $xk => $x) {
                if ($x['d'] === '' || preg_match_all('/(?:tabela|tab\.?)\s+(MTTB[A-Z0-9_]+)/iu', $x['d'], $m) < 1) {
                    continue;
                }
                foreach (array_unique(array_map('strtoupper', $m[1])) as $d) {
                    if ($d !== $a && isset($idx[$d])) {
                        $grupos[$a][$d][$xk] = $x;
                    }
                }
            }
        }

        $out = [];
        foreach ($grupos as $a => $destinos) {
            foreach ($destinos as $d => $campos) {
                $r = self::relacaoDocumentada($idx, $a, $d, $campos, $comum);
                if ($r !== null) {
                    $out[] = $r;
                }
            }
        }

        return $out;
    }

    /** @param array<string, array<string, mixed>> $campos */
    private static function relacaoDocumentada(array $idx, string $a, string $d, array $campos, callable $comum): ?array
    {
        $A = $idx[$a];
        $D = $idx[$d];
        // chaves comuns: primeiras colunas de D, com o mesmo nome em A e nome frequente no banco (CODEMP, REGIAO...)
        $comuns = [];
        foreach (array_slice($D['ordem'], 0, 3) as $dk) {
            if (isset($A['cols'][$dk]) && $comum($dk) && self::classe($A['cols'][$dk]['t']) === self::classe($D['cols'][$dk]['t'])) {
                $comuns[$dk] = true;
            }
        }
        $papeis = [];
        $avisos = [];
        $difTipos = [];
        $semPar = [];
        $usadasEmD = $comuns;
        $piorNota = PHP_INT_MAX;
        $fracos = [];
        foreach ($campos as $xk => $x) {
            [$dk, $nota, $sim] = self::melhorDestino($x, $D, $comuns);
            if ($dk === null) {
                $semPar[] = $x['c'];
                continue;
            }
            $piorNota = min($piorNota, $nota);
            if ($sim === 0) {
                $fracos[] = $x['c'] . ' → ' . $D['cols'][$dk]['c'];
            }
            $pares = [];
            foreach (array_keys($comuns) as $ck) {
                $pares[] = [$A['cols'][$ck]['c'], $D['cols'][$ck]['c']];
            }
            $pares[] = [$x['c'], $D['cols'][$dk]['c']];
            $usadasEmD[$dk] = true;
            self::registrarDiferencaDeTipo($difTipos, $x, $D['cols'][$dk]);
            $papeis[] = ['nome' => $x['c'], 'fonte' => 'Documentação: “' . mb_substr($x['d'], 0, 120) . '”', 'pares' => $pares];
        }
        if ($papeis === []) {
            return null;
        }
        // colunas iniciais de D que parecem código e ficaram sem par: a chave pode ter mais colunas do que a documentação cita
        $incompleta = [];
        foreach (array_slice($D['ordem'], 0, 3) as $dk) {
            if (!isset($usadasEmD[$dk]) && self::pareceCodigo($dk) && !self::algumPapelUsa($papeis, $D['cols'][$dk]['c'])) {
                $incompleta[] = $D['cols'][$dk]['c'];
            }
        }
        $avisos = array_merge(self::avisosDeTipo($a, $d, $difTipos), $avisos);
        if ($incompleta !== []) {
            $avisos[] = "A chave de {$d} parece ter mais colunas (" . implode(', ', $incompleta) . ') sem par. Complete antes de aprovar.';
        }
        if ($fracos !== []) {
            $avisos[] = 'Par deduzido só pela posição e pelo tipo, sem semelhança de nome (' . implode('; ', $fracos) . '): confira.';
        }
        if ($semPar !== []) {
            $avisos[] = 'Não achei a coluna de destino de ' . implode(', ', $semPar) . '.';
        }
        $n = count($campos);

        return [
            'a' => $a, 'b' => $d, 'tipo' => 'doc',
            'conf' => ($incompleta !== [] || $semPar !== [] || $fracos !== [] || $piorNota < 10) ? 'media' : 'alta',
            'evid' => "A documentação de {$a} cita a tabela {$d} em {$n} " . ($n > 1 ? 'campos.' : 'campo.'),
            'aviso' => $avisos === [] ? null : implode(' ', $avisos),
            'papeis' => $papeis,
        ];
    }

    /**
     * Escolhe a coluna de D que corresponde ao campo documentado.
     *
     * @param array<string, bool> $comuns colunas de D já pareadas por serem chave comum
     * @return array{0: ?string, 1: int, 2: int} coluna, nota e semelhança de nome
     */
    private static function melhorDestino(array $x, array $D, array $comuns): array
    {
        $xk = strtoupper($x['c']);
        if (isset($D['cols'][$xk]) && !isset($comuns[$xk]) && self::classe($x['t']) === self::classe($D['cols'][$xk]['t'])) {
            return [$xk, 100, 3]; // mesmo nome e mesma família de tipo (NUCLEO número não casa com Nucleo texto)
        }
        $melhor = null;
        $nota = -PHP_INT_MAX;
        $simMelhor = 0;
        foreach ($D['cols'] as $dk => $dc) {
            if (isset($comuns[$dk])) {
                continue;
            }
            $sim = min(3, self::similaridade($xk, $dk));
            $classeIgual = self::classe($x['t']) === self::classe($dc['t']);
            $codigo = self::pareceCodigo($dk);
            // chaves ficam no começo da tabela: a posição pesa mais que a semelhança de nome
            $n = 4 * $sim + ($classeIgual ? 2 : 0) + ($dc['i'] < 3 ? 6 : 0) + ($codigo ? 4 : 0) - (!$classeIgual && !$codigo ? 6 : 0);
            if ($n > $nota) {
                $nota = $n;
                $melhor = $dk;
                $simMelhor = $sim;
            }
        }

        return $nota >= self::NOTA_MINIMA ? [$melhor, $nota, $simMelhor] : [null, 0, 0];
    }

    /** @param list<array<string, mixed>> $papeis */
    private static function algumPapelUsa(array $papeis, string $colunaD): bool
    {
        foreach ($papeis as $p) {
            foreach ($p['pares'] as $par) {
                if (strcasecmp($par[1], $colunaD) === 0) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Agrupa os pares com tipos diferentes por coluna de destino, para dar um só alerta em vez de um por papel.
     *
     * @param array<string, array{tipoD: string, dcol: string, tamanho: bool, xs: list<string>, tipoX: string}> $dif
     */
    private static function registrarDiferencaDeTipo(array &$dif, array $x, array $dc): void
    {
        if ($x['t'] === $dc['t']) {
            return;
        }
        $mesmoTipoBase = self::classe($x['t']) === self::classe($dc['t']) && preg_replace('/\(.*/', '', $x['t']) === preg_replace('/\(.*/', '', $dc['t']);
        $k = $dc['c'] . '|' . $dc['t'] . '|' . $x['t'];
        $dif[$k] ??= ['tipoD' => $dc['t'], 'dcol' => $dc['c'], 'tamanho' => $mesmoTipoBase, 'xs' => [], 'tipoX' => $x['t']];
        $dif[$k]['xs'][] = $x['c'];
    }

    /**
     * @param array<string, array{tipoD: string, dcol: string, tamanho: bool, xs: list<string>, tipoX: string}> $dif
     * @return list<string>
     */
    private static function avisosDeTipo(string $a, string $d, array $dif): array
    {
        $out = [];
        foreach ($dif as $g) {
            $campos = implode(', ', array_map(static fn (string $c): string => "{$a}.{$c}", $g['xs']));
            $out[] = $g['tamanho']
                ? "{$campos} ({$g['tipoX']}) e {$d}.{$g['dcol']} ({$g['tipoD']}) têm tamanhos diferentes: confirme a regra de comparação."
                : "{$campos} ({$g['tipoX']}) e {$d}.{$g['dcol']} ({$g['tipoD']}) têm tipos diferentes: o JOIN vai exigir conversão.";
        }

        return $out;
    }

    /* ---------- chave composta ---------- */

    private static function porChave(array $idx, array $maes): array
    {
        $out = [];
        foreach ($maes as $p) {
            if (!isset($idx[$p])) {
                continue;
            }
            $prefixo = [];
            $melhor = [];
            foreach (array_slice($idx[$p]['ordem'], 0, 6) as $k) {
                $prefixo[] = $k;
                if (count($prefixo) >= 2 && count(self::quemTem($idx, $p, $prefixo, $maes)) >= self::SUPORTE_CHAVE) {
                    $melhor = $prefixo;
                }
            }
            if ($melhor === []) {
                continue;
            }
            $filhas = self::quemTem($idx, $p, $melhor, $maes);
            $nomes = array_map(static fn (string $k): string => $idx[$p]['cols'][$k]['c'], $melhor);
            foreach ($filhas as $a) {
                $pares = array_map(static fn (string $k): array => [$idx[$a]['cols'][$k]['c'], $idx[$p]['cols'][$k]['c']], $melhor);
                $out[] = [
                    'a' => $a, 'b' => $p, 'tipo' => 'chave', 'conf' => 'alta',
                    'evid' => 'Tem as ' . count($melhor) . ' colunas-chave de ' . $p . ' (' . implode(', ', $nomes) . ') com os mesmos tipos. ' . count($filhas) . ' tabelas seguem esse padrão.',
                    'aviso' => null,
                    'papeis' => [['nome' => 'Chave composta', 'fonte' => implode(' + ', $nomes), 'pares' => $pares]],
                ];
            }
        }

        return $out;
    }

    /** @param list<string> $chave @param list<string> $maes @return list<string> */
    private static function quemTem(array $idx, string $p, array $chave, array $maes): array
    {
        $out = [];
        foreach ($idx as $a => $t) {
            if ($a === $p || in_array($a, $maes, true)) {
                continue;
            }
            foreach ($chave as $k) {
                if (!isset($t['cols'][$k]) || $t['cols'][$k]['t'] !== $idx[$p]['cols'][$k]['t']) {
                    continue 2;
                }
            }
            $out[] = $a;
        }

        return $out;
    }

    /* ---------- utilidades ---------- */

    private static function classe(string $tipo): string
    {
        $base = strtolower((string) preg_replace('/\(.*/', '', $tipo));

        return match (true) {
            in_array($base, ['smallint', 'int', 'bigint', 'tinyint', 'decimal', 'numeric', 'float', 'real', 'money', 'bit'], true) => 'num',
            in_array($base, ['char', 'varchar', 'nchar', 'nvarchar', 'text', 'ntext'], true) => 'txt',
            str_starts_with($base, 'date') || $base === 'smalldatetime' => 'data',
            default => 'outro',
        };
    }

    private static function pareceCodigo(string $nome): bool
    {
        return preg_match('/^(COD|CODIGO|ID|NUM|SEQ)([A-Z0-9_]|$)/', $nome) === 1;
    }

    /** Palavras em comum (separadas por "_") mais trechos de 3 letras em comum. */
    private static function similaridade(string $a, string $b): int
    {
        $tok = static fn (string $s): array => array_filter(array_unique(explode('_', (string) preg_replace('/\d+/', '', $s))), static fn (string $t): bool => strlen($t) >= 2);
        $tri = static function (string $s): array {
            $l = (string) preg_replace('/[^A-Z]/', '', $s);
            $r = [];
            for ($i = 0; $i + 3 <= strlen($l); $i++) {
                $r[substr($l, $i, 3)] = true;
            }

            return array_keys($r);
        };

        return count(array_intersect($tok($a), $tok($b))) + count(array_intersect($tri($a), $tri($b)));
    }
}
