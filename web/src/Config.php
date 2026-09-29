<?php

declare(strict_types=1);

namespace Elogica;

use Dotenv\Dotenv;
use RuntimeException;

final class Config
{
    /** Carrega o .env do primeiro diretório que o contiver (o primeiro da lista tem prioridade). */
    public static function load(string ...$dirs): void
    {
        if (!class_exists(Dotenv::class)) {
            return;
        }
        foreach ($dirs as $dir) {
            if (is_file($dir . '/.env')) {
                Dotenv::createImmutable($dir)->safeLoad();

                return;
            }
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
