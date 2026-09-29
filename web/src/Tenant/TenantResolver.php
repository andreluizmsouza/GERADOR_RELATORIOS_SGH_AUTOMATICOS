<?php

declare(strict_types=1);

namespace Elogica\Tenant;

/** Descobre o cliente (slug) a partir do host da requisição. */
final class TenantResolver
{
    public function __construct(private readonly string $hostRegex)
    {
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
}
