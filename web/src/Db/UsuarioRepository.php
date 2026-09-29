<?php

declare(strict_types=1);

namespace Elogica\Db;

use PDO;

final class UsuarioRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @return array<string, mixed>|null */
    public function findByLogin(string $login): ?array
    {
        $stmt = $this->pdo->prepare('SELECT id, login, nome, senha_hash, perfil, ativo FROM dbo.usuario WHERE login = :login');
        $stmt->execute([':login' => $login]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    public function create(string $login, string $nome, string $senha, string $perfil): void
    {
        $stmt = $this->pdo->prepare('INSERT INTO dbo.usuario (login, nome, senha_hash, perfil) VALUES (:login, :nome, :hash, :perfil)');
        $stmt->execute([
            ':login' => $login,
            ':nome' => $nome,
            ':hash' => password_hash($senha, PASSWORD_DEFAULT),
            ':perfil' => $perfil,
        ]);
    }
}
