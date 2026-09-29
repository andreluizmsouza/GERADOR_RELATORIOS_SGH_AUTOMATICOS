<?php

declare(strict_types=1);

namespace Elogica\Admin;

/** Validação do escopo global do dicionário (prefixo e exclusões). */
final class EscopoForm
{
    /**
     * @param array<string, mixed> $in
     * @return array{prefixo: string, exclusoes: list<string>, erros: list<string>}
     */
    public static function validar(array $in): array
    {
        $erros = [];
        $prefixo = strtoupper(trim((string) ($in['prefixo'] ?? '')));
        if (preg_match('/^[A-Z0-9_]{1,30}$/', $prefixo) !== 1) {
            $erros[] = 'Prefixo das tabelas inválido (letras, números e _).';
        }

        $exclusoes = [];
        foreach (preg_split('/\R/', (string) ($in['exclusoes'] ?? '')) ?: [] as $linha) {
            $linha = trim($linha);
            if ($linha === '') {
                continue;
            }
            if (preg_match('/^[A-Za-z0-9_%\[\]\-\^]{1,128}$/', $linha) !== 1) {
                $erros[] = "Padrão de exclusão inválido: {$linha}";
                continue;
            }
            $exclusoes[] = $linha;
        }

        return ['prefixo' => $prefixo, 'exclusoes' => $exclusoes, 'erros' => $erros];
    }
}
