<?php

declare(strict_types=1);

namespace Elogica\Metadata;

/**
 * Compara a documentação lida com os textos atuais do dicionário. Lógica pura.
 *
 * Para cada texto: 'preencher' (o dicionário está vazio) ou 'conflito' (ambos preenchidos e diferentes).
 * Textos iguais ou vazios na documentação não geram nada.
 */
final class DocImport
{
    public static function normalizar(string $s): string
    {
        return mb_strtolower(trim(preg_replace('/\s+/u', ' ', $s) ?? $s));
    }

    /** @param list<array{0: string, 1: string}> $v */
    public static function valoresComoTexto(array $v): string
    {
        return implode('; ', array_map(static fn (array $x): string => $x[0] . ' = ' . $x[1], $v));
    }

    /**
     * @param array<string, array<string, mixed>> $docs   DocLote::consolidar()['tabelas']
     * @param array<string, array<string, mixed>> $textos DicionarioRepository::carregarTextos()
     * @return array{itens: array<string, array<string, mixed>>, doc_sem_tabela: array<string, string>, dic_sem_doc: list<string>, colunas_sem_dic: int, colunas_sem_doc: int}
     */
    public static function planejar(array $docs, array $textos): array
    {
        $itens = [];
        $docSemTabela = [];
        $colunasSemDic = 0;
        $colunasSemDoc = 0;

        foreach ($docs as $nome => $doc) {
            $t = $textos[$nome] ?? null;
            if ($t === null) {
                $docSemTabela[$nome] = (string) $doc['arquivo'];
                continue;
            }
            $diffs = [];
            $d = self::comparar((string) $t['descricao'], (string) $doc['descricao']);
            if ($d !== null) {
                $diffs[] = ['alvo' => '(tabela)', 'campo' => 'descrição', 'tipo' => 'tabela_desc', 'id' => $t['id'], 'atual' => (string) $t['descricao'], 'doc' => (string) $doc['descricao'], 'estado' => $d];
            }
            foreach ($doc['colunas'] as $ck => $c) {
                $tc = $t['colunas'][$ck] ?? null;
                if ($tc === null) {
                    $colunasSemDic++;
                    continue;
                }
                $d = self::comparar((string) $tc['descricao'], (string) $c['descricao']);
                if ($d !== null) {
                    $diffs[] = ['alvo' => $c['coluna'], 'campo' => 'descrição', 'tipo' => 'coluna_desc', 'id' => $tc['id'], 'atual' => (string) $tc['descricao'], 'doc' => (string) $c['descricao'], 'estado' => $d];
                }
                if ($c['valores'] !== []) {
                    $atual = $tc['valores'];
                    $d = self::comparar(self::valoresComoTexto($atual), self::valoresComoTexto($c['valores']));
                    if ($d !== null) {
                        $diffs[] = ['alvo' => $c['coluna'], 'campo' => 'valores', 'tipo' => 'coluna_valores', 'id' => $tc['id'], 'atual' => self::valoresComoTexto($atual), 'doc' => self::valoresComoTexto($c['valores']), 'valores' => $c['valores'], 'estado' => $d];
                    }
                }
            }
            foreach ($t['colunas'] as $ck => $_) {
                if (!isset($doc['colunas'][$ck])) {
                    $colunasSemDoc++;
                }
            }
            if ($diffs !== []) {
                $itens[$nome] = [
                    'id' => $t['id'], 'tabela' => $nome, 'arquivo' => (string) $doc['arquivo'], 'diffs' => $diffs,
                    'preencher' => count(array_filter($diffs, static fn (array $x): bool => $x['estado'] === 'preencher')),
                    'conflitos' => count(array_filter($diffs, static fn (array $x): bool => $x['estado'] === 'conflito')),
                ];
            }
        }
        $semDoc = array_values(array_diff(array_keys($textos), array_keys($docs)));
        sort($semDoc);

        return ['itens' => $itens, 'doc_sem_tabela' => $docSemTabela, 'dic_sem_doc' => $semDoc, 'colunas_sem_dic' => $colunasSemDic, 'colunas_sem_doc' => $colunasSemDoc];
    }

    /** @return 'preencher'|'conflito'|null null = nada a fazer */
    private static function comparar(string $atual, string $doc): ?string
    {
        $doc = self::normalizar($doc);
        if ($doc === '') {
            return null;
        }
        $a = self::normalizar($atual);
        if ($a === '') {
            return 'preencher';
        }

        return $a === $doc ? null : 'conflito';
    }
}
