<?php

declare(strict_types=1);

namespace Elogica;

use Dotenv\Dotenv;
use RuntimeException;

final class Config
{
    public static function load(string $dir): void
    {
        if (class_exists(Dotenv::class) && is_file($dir . '/.env')) {
            Dotenv::createImmutable($dir)->safeLoad();
        }
    }

    public static function get(string $key, ?string $default = null): ?string
    {
        $value = $_ENV[$key] ?? getenv($key);

        return ($value === false || $value === null || $value === '') ? $default : (string) $value;
    }

    public static function require(string $key): string
    {
        $value = self::get($key);
        if ($value === null) {
            throw new RuntimeException("Variável de ambiente obrigatória não definida: {$key}");
        }

        return $value;
    }
}
