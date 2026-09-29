<?php

declare(strict_types=1);

namespace Elogica\Tenant;

/** Relaciona o host da requisição ao cliente (slug), a partir de um modelo como "relatorios-{slug}.elogica.info". */
final class TenantResolver
{
    public const MODELO_PADRAO = 'relatorios-{slug}.elogica.info';

    private readonly string $hostRegex;

    public function __construct(private readonly string $modelo = self::MODELO_PADRAO)
    {
        if (substr_count($modelo, '{slug}') !== 1) {
            throw new \InvalidArgumentException('O modelo de host deve conter {slug} exatamente uma vez.');
        }
        $this->hostRegex = '/^' . str_replace(preg_quote('{slug}', '/'), '([a-z0-9-]{2,60})', preg_quote(strtolower($modelo), '/')) . '$/';
    }

    public static function isValidSlug(string $slug): bool
    {
        return preg_match('/^[a-z0-9-]{2,60}$/', $slug) === 1;
    }

    public function slugFromHost(string $host): ?string
    {
        $host = strtolower(trim($host));
        $host = preg_replace('/:\d+$/', '', $host) ?? $host;
        if ($host === '' || preg_match($this->hostRegex, $host, $m) !== 1) {
            return null;
        }

        return self::isValidSlug($m[1]) ? $m[1] : null;
    }

    /** @return array{0: string, 1: string} texto fixo antes e depois do {slug} no modelo */
    public function partes(): array
    {
        [$antes, $depois] = explode('{slug}', $this->modelo, 2);

        return [$antes, $depois];
    }

    /** Aceita o slug puro, o prefixo/sufixo do modelo ou o host completo e devolve só o slug. */
    public function normalizarSlug(string $entrada): string
    {
        $entrada = strtolower(trim($entrada));
        $entrada = preg_replace('#^https?://#', '', $entrada) ?? $entrada;
        $entrada = rtrim(explode('/', $entrada)[0], '.');
        [$antes, $depois] = array_map('strtolower', $this->partes());
        if ($antes !== '' && str_starts_with($entrada, $antes)) {
            $entrada = substr($entrada, strlen($antes));
        }
        if ($depois !== '' && str_ends_with($entrada, $depois)) {
            $entrada = substr($entrada, 0, -strlen($depois));
        }

        return $entrada;
    }

    public function hostFor(string $slug): string
    {
        return str_replace('{slug}', $slug, $this->modelo);
    }
}
