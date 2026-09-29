<?php

declare(strict_types=1);

namespace Elogica\Admin;

use Elogica\Tenant\TenantResolver;

/** Validação do formulário de conexão de cliente. */
final class ConexaoForm
{
    /**
     * @param array<string, mixed> $in
     * @return array{dados: array<string, mixed>, exclusoes: list<string>, erros: list<string>}
     */
    public static function validar(array $in, bool $novo): array
    {
        $erros = [];
        $s = static fn (string $k): string => trim((string) ($in[$k] ?? ''));

        $slug = strtolower($s('slug'));
        if (!TenantResolver::isValidSlug($slug)) {
            $erros[] = 'Identificador (URL) inválido: use 2 a 60 caracteres entre letras minúsculas, números e hífen.';
        }
        if ($s('cliente') === '' || mb_strlen($s('cliente')) > 120) {
            $erros[] = 'Informe o nome do cliente (até 120 caracteres).';
        }
        // Servidor/banco/usuário vão para a string de conexão: bloqueia separadores do DSN.
        foreach (['servidor' => 'Servidor', 'banco' => 'Banco', 'usuario_readonly' => 'Usuário somente leitura'] as $k => $rotulo) {
            if ($s($k) === '' || preg_match('/^[A-Za-z0-9_.\\\\,\-]{1,200}$/', $s($k)) !== 1) {
                $erros[] = "{$rotulo} inválido (apenas letras, números e . _ - \\ ,).";
            }
        }
        $prefixo = strtoupper($s('filtro_prefixo'));
        if (preg_match('/^[A-Z0-9_]{1,30}$/', $prefixo) !== 1) {
            $erros[] = 'Prefixo das tabelas inválido (letras, números e _).';
        }
        if ($novo && (string) ($in['senha'] ?? '') === '') {
            $erros[] = 'Informe a senha do usuário somente leitura.';
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

        return [
            'dados' => [
                'slug' => $slug, 'cliente' => $s('cliente'), 'servidor' => $s('servidor'), 'banco' => $s('banco'),
                'usuario_readonly' => $s('usuario_readonly'), 'filtro_prefixo' => $prefixo, 'ativo' => isset($in['ativo']),
            ],
            'exclusoes' => $exclusoes,
            'erros' => $erros,
        ];
    }
}
