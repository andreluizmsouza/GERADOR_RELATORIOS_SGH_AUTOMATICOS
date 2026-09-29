<?php

declare(strict_types=1);

namespace Elogica\Metadata;

/**
 * Lê a documentação técnica das tabelas (HTML exportado do Word).
 *
 * O nome e a descrição da tabela vêm do texto "Tabela : X / Descrição : Y" que precede a tabela de campos
 * (3 colunas: campo, tipo, descrição). Lógica pura: recebe bytes e devolve dados.
 */
final class DocParser
{
    private const MARCA = "\x01";

    public static function decodificar(string $bytes): string
    {
        $bytes = preg_replace('/^\xEF\xBB\xBF/', '', $bytes) ?? $bytes;

        return mb_check_encoding($bytes, 'UTF-8') ? $bytes : mb_convert_encoding($bytes, 'UTF-8', 'Windows-1252');
    }

    /**
     * Quebras semânticas (<br>, fim de parágrafo) viram linhas; quebras de formatação do código-fonte viram espaço.
     *
     * @return list<string>
     */
    public static function linhas(string $html): array
    {
        $s = preg_replace('#<br\s*/?>|</p\s*>|</div\s*>|</li\s*>#i', self::MARCA, $html) ?? $html;
        $s = preg_replace('#<[^>]*>#', '', $s) ?? $s;
        $s = str_replace("\u{00A0}", ' ', html_entity_decode($s, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $out = [];
        foreach (explode(self::MARCA, $s) as $l) {
            $l = trim(preg_replace('/\s+/u', ' ', $l) ?? $l);
            if ($l !== '') {
                $out[] = $l;
            }
        }

        return $out;
    }

    /**
     * @return list<array{tabela: string, descricao: string, arquivo: string, colunas: array<string, array{coluna: string, tipo: string, descricao: string, valores: list<array{0: string, 1: string}>}>}>
     */
    public static function parse(string $bytes, string $arquivo): array
    {
        $html = self::decodificar($bytes);
        $html = preg_replace('#<!--.*?-->#s', '', $html) ?? $html;
        $html = preg_replace('#<(style|script|xml)\b.*?</\1>#is', '', $html) ?? $html;

        $out = [];
        $pendente = null;
        $anterior = 0;
        foreach (self::tabelasDeTopo($html) as [$ini, $fim]) {
            $cab = self::cabecalho(substr($html, $anterior, $ini - $anterior));
            $anterior = $fim;
            $pendente = $cab ?? $pendente;
            if ($pendente === null) {
                continue;
            }
            $colunas = self::colunas(substr($html, $ini, $fim - $ini));
            if ($colunas === []) {
                continue; // tabela de layout: mantém o cabeçalho pendente para a próxima
            }
            $out[] = ['tabela' => $pendente['tabela'], 'descricao' => $pendente['descricao'], 'arquivo' => $arquivo, 'colunas' => $colunas];
            $pendente = null;
        }

        return $out;
    }

    /** @return list<array{0: int, 1: int}> posições [início, fim) das tabelas de nível superior */
    private static function tabelasDeTopo(string $html): array
    {
        preg_match_all('#<(/?)table\b#i', $html, $m, PREG_OFFSET_CAPTURE);
        $spans = [];
        $prof = 0;
        $ini = 0;
        foreach ($m[0] as $i => [, $pos]) {
            if ($m[1][$i][0] === '') {
                if ($prof++ === 0) {
                    $ini = $pos;
                }
            } elseif ($prof > 0 && --$prof === 0) {
                $spans[] = [$ini, $pos + strlen('</table>')];
            }
        }

        return $spans;
    }

    /** @return array{tabela: string, descricao: string}|null */
    private static function cabecalho(string $antes): ?array
    {
        $flat = implode(' ', self::linhas($antes));
        if (preg_match_all('/Tabela\s*:\s*([A-Za-z0-9_]+)/u', $flat, $m, PREG_OFFSET_CAPTURE) < 1) {
            return null;
        }
        $ultimo = count($m[0]) - 1;
        $resto = substr($flat, $m[0][$ultimo][1] + strlen($m[0][$ultimo][0]));
        $desc = preg_match('/^\s*Descri\S*\s*:\s*(.+)$/u', $resto, $d) === 1 ? trim($d[1]) : '';

        return ['tabela' => strtoupper($m[1][$ultimo][0]), 'descricao' => mb_substr($desc, 0, 1000)];
    }

    /** @return array<string, array{coluna: string, tipo: string, descricao: string, valores: list<array{0: string, 1: string}>}> */
    private static function colunas(string $tabelaHtml): array
    {
        $out = [];
        preg_match_all('#<tr\b.*?</tr>#is', $tabelaHtml, $rows);
        foreach ($rows[0] as $tr) {
            preg_match_all('#<t[dh]\b[^>]*>(.*?)</t[dh]>#is', $tr, $c);
            if (count($c[1]) !== 3) {
                continue;
            }
            $nomeLinhas = self::linhas($c[1][0]);
            $nome = $nomeLinhas === [] ? '' : explode(' ', $nomeLinhas[0])[0];
            if (preg_match('/^[A-Za-z_][A-Za-z0-9_]{0,127}$/', $nome) !== 1 || in_array(strtolower($nome), ['coluna', 'colunas', 'campo', 'campos'], true)) {
                continue;
            }
            ['descricao' => $descricao, 'valores' => $valores] = self::separar(self::linhas($c[1][2]));
            $out[strtoupper($nome)] ??= [
                'coluna' => $nome,
                'tipo' => implode(' ', self::linhas($c[1][1])),
                'descricao' => mb_substr($descricao, 0, 2000),
                'valores' => $valores,
            ];
        }

        return $out;
    }

    /**
     * Separa o texto explicativo da lista de valores possíveis.
     *
     * 1) Lista em linhas ("0 = Normal", "1 - Ativo", "Pessoa Física = 1"): exige pelo menos 2 itens; os itens saem da descrição.
     * 2) Lista na mesma linha ("Situação: 1-Ativo 2-Cancelada", "(0-Aberta / 1-Concluída)"): os valores são extraídos,
     *    mas a descrição fica inteira, porque o texto original é a fonte.
     *
     * @param list<string> $linhas
     * @return array{descricao: string, valores: list<array{0: string, 1: string}>}
     */
    public static function separar(array $linhas): array
    {
        $cand = [];
        foreach ($linhas as $i => $l) {
            if (preg_match('/^([A-Za-z0-9]{1,4})\s*([-=:–])\s*(\S.*)$/u', $l, $m) === 1) {
                $cand[$i] = [$m[1], trim($m[3])];
            } elseif (preg_match('/^(\S.*?)\s*=\s*([A-Za-z0-9]{1,4})$/u', $l, $m) === 1) {
                $cand[$i] = [$m[2], trim($m[1])];
            }
        }
        if (count($cand) < 2) {
            $texto = implode(' ', $linhas);

            return ['descricao' => $texto, 'valores' => self::valoresEmLinha($texto)];
        }
        $texto = [];
        $valores = [];
        $vistos = [];
        foreach ($linhas as $i => $l) {
            if (!isset($cand[$i])) {
                $texto[] = $l;
                continue;
            }
            [$codigo, $significado] = $cand[$i];
            if (!isset($vistos[$codigo])) {
                $vistos[$codigo] = true;
                $valores[] = [$codigo, mb_substr($significado, 0, 400)];
            }
        }

        return ['descricao' => implode(' ', $texto), 'valores' => $valores];
    }

    /** @return list<array{0: string, 1: string}> vazio se não houver pelo menos 2 itens distintos */
    private static function valoresEmLinha(string $texto): array
    {
        $re = '#(?:^|[\s(/;,:])(\d{1,2}|[A-Z])\s*[-=]\s*([^/;,()]*?\pL[^/;,()]*?)(?=\s*(?:[/;,)]|$)|\s+(?:\d{1,2}|[A-Z])\s*[-=]\s*\S)#u';
        if (preg_match_all($re, $texto, $m, PREG_SET_ORDER) < 2) {
            return [];
        }
        $valores = [];
        $vistos = [];
        foreach ($m as $x) {
            $sig = trim($x[2], " \t.-");
            if ($sig === '' || isset($vistos[$x[1]])) {
                continue;
            }
            $vistos[$x[1]] = true;
            $valores[] = [$x[1], mb_substr($sig, 0, 400)];
        }

        return count($valores) >= 2 ? $valores : [];
    }
}
