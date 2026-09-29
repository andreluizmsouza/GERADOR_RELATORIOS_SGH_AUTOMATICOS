<?php

declare(strict_types=1);

namespace Elogica\Db;

use Throwable;

/** Teste de conexão com o banco de um cliente, verificando que o login é somente leitura. */
final class ClientConnection
{
    /** @return array{ok: bool, mensagem: string, tabelas: int} */
    public static function test(string $server, string $database, string $user, string $password, string $prefix): array
    {
        try {
            $pdo = Control::connect($server, $database, $user, $password);
            $pdo->setAttribute(\PDO::SQLSRV_ATTR_QUERY_TIMEOUT, 15);

            $perm = $pdo->query(
                "SELECT ISNULL(IS_MEMBER('db_owner'), 0) AS owner, ISNULL(IS_MEMBER('db_datawriter'), 0) AS writer, "
                . "ISNULL(IS_MEMBER('db_ddladmin'), 0) AS ddl, ISNULL(IS_SRVROLEMEMBER('sysadmin'), 0) AS sa"
            )->fetch();
            if ($perm && array_sum(array_map('intval', $perm)) > 0) {
                return ['ok' => false, 'tabelas' => 0, 'mensagem' => 'Conectou, mas o login tem permissão de escrita ou administração. Use um login somente leitura (db_datareader).'];
            }

            $stmt = $pdo->prepare('SELECT COUNT(*) AS n FROM sys.tables WHERE name LIKE :p');
            $stmt->execute([':p' => $prefix . '%']);
            $n = (int) ($stmt->fetch()['n'] ?? 0);

            return ['ok' => true, 'tabelas' => $n, 'mensagem' => "Conexão OK (somente leitura). {$n} tabelas com prefixo {$prefix}."];
        } catch (Throwable) {
            // Detalhes do driver podem conter servidor/usuário; não expor na tela.
            return ['ok' => false, 'tabelas' => 0, 'mensagem' => 'Não foi possível conectar. Verifique servidor, banco, usuário e senha.'];
        }
    }
}
