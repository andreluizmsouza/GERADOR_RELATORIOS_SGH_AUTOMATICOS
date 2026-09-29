<?php

declare(strict_types=1);

namespace Elogica\Admin;

/** Validação da tela de revisão de uma tabela do dicionário. */
final class RevisaoForm
{
    public const SITUACOES = ['incluir', 'excluir', 'interna'];
    public const STATUS = ['sugerido_ia', 'revisado', 'rejeitado'];

    /**
     * @param array<string, mixed> $in
     * @param list<int> $idsColunas colunas que pertencem à tabela (o resto do POST é ignorado)
     * @return array{tabela: array<string, string>, colunas: array<int, array<string, mixed>>, erros: list<string>}
     */
    public static function validar(array $in, array $idsColunas): array
    {
        $erros = [];
        $s = static fn (mixed $v): string => trim((string) $v);

        $tabela = [
            'descricao' => $s($in['descricao'] ?? ''),
            'situacao' => $s($in['situacao'] ?? ''),
            'status_revisao' => $s($in['status_revisao'] ?? ''),
        ];
        if (mb_strlen($tabela['descricao']) > 1000) {
            $erros[] = 'A descrição da tabela passa de 1000 caracteres.';
        }
        if (!in_array($tabela['situacao'], self::SITUACOES, true)) {
            $erros[] = 'Situação da tabela inválida.';
        }
        if (!in_array($tabela['status_revisao'], self::STATUS, true)) {
            $erros[] = 'Status da tabela inválido.';
        }

        $todasRevisadas = isset($in['todas_revisadas']);
        $colunas = [];
        $bruto = is_array($in['col'] ?? null) ? $in['col'] : [];
        foreach ($idsColunas as $id) {
            if (!is_array($bruto[$id] ?? null)) {
                continue; // coluna ausente do envio: fica como está (nunca é apagada)
            }
            $c = $bruto[$id];
            $nome = $s($c['nome'] ?? '');
            $status = $todasRevisadas ? 'revisado' : $s($c['status'] ?? 'sugerido_ia');
            $valores = self::valores((string) ($c['valores'] ?? ''), $nome, $erros);
            $col = [
                'descricao' => $s($c['descricao'] ?? ''),
                'nome_negocio' => $s($c['nome_negocio'] ?? ''),
                'sinonimos' => $s($c['sinonimos'] ?? ''),
                'sensivel' => isset($c['sensivel']),
                'status_revisao' => $status,
                'valores' => $valores,
            ];
            foreach (['descricao' => 2000, 'nome_negocio' => 200, 'sinonimos' => 500] as $campo => $max) {
                if (mb_strlen($col[$campo]) > $max) {
                    $erros[] = "Campo {$nome}: {$campo} passa de {$max} caracteres.";
                }
            }
            if (!in_array($status, self::STATUS, true)) {
                $erros[] = "Campo {$nome}: status inválido.";
            }
            $colunas[$id] = $col;
        }
        if ($todasRevisadas) {
            $tabela['status_revisao'] = 'revisado';
        }

        return ['tabela' => $tabela, 'colunas' => $colunas, 'erros' => $erros];
    }

    /**
     * Uma linha por valor, no formato "código = significado".
     *
     * @param list<string> $erros
     * @return list<array{0: string, 1: string}>
     */
    public static function valores(string $texto, string $campo, array &$erros): array
    {
        $out = [];
        $vistos = [];
        foreach (preg_split('/\R/', $texto) ?: [] as $linha) {
            $linha = trim($linha);
            if ($linha === '') {
                continue;
            }
            if (preg_match('/^(\S{1,60})\s*=\s*(\S.{0,399})$/u', $linha, $m) !== 1) {
                $erros[] = "Campo {$campo}: valor inválido \"" . mb_substr($linha, 0, 40) . '" (use: código = significado).';
                continue;
            }
            if (isset($vistos[$m[1]])) {
                $erros[] = "Campo {$campo}: código {$m[1]} repetido.";
                continue;
            }
            $vistos[$m[1]] = true;
            $out[] = [$m[1], trim($m[2])];
        }

        return $out;
    }
}
