<?php

declare(strict_types=1);

namespace Elogica\Admin;

use Elogica\Tenant\TenantResolver;

/** Validação do formulário de conexão de cliente. */
final class ConexaoForm
{
    /**
     * @param array<string, mixed> $in
     * @return array{dados: array<string, mixed>, erros: list<string>}
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
        if ($novo && (string) ($in['senha'] ?? '') === '') {
            $erros[] = 'Informe a senha do usuário somente leitura.';
        }

        return [
            'dados' => [
                'slug' => $slug, 'cliente' => $s('cliente'), 'servidor' => $s('servidor'), 'banco' => $s('banco'),
                'usuario_readonly' => $s('usuario_readonly'), 'ativo' => isset($in['ativo']),
            ],
            'erros' => $erros,
        ];
    }
}
