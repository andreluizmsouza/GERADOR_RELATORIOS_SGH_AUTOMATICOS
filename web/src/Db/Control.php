<?php

declare(strict_types=1);

namespace Elogica\Db;

use Elogica\Config;
use PDO;

/** Conexão com o banco de controle (ReportService). */
final class Control
{
    private static ?PDO $pdo = null;

    public static function pdo(): PDO
    {
        if (self::$pdo === null) {
            self::$pdo = self::connect(
                Config::require('CONTROL_DB_SERVER'),
                Config::require('CONTROL_DB_NAME'),
                Config::require('CONTROL_DB_USER'),
                Config::get('CONTROL_DB_PASS', '') ?? '',
            );
        }

        return self::$pdo;
    }

    public static function connect(string $server, string $database, string $user, string $password): PDO
    {
        $dsn = sprintf('sqlsrv:Server=%s;Database=%s;Encrypt=1;TrustServerCertificate=%s;LoginTimeout=10', $server, $database, Config::get('DB_TRUST_SERVER_CERT', '0') === '1' ? '1' : '0');

        return new PDO($dsn, $user, $password, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    }
}
