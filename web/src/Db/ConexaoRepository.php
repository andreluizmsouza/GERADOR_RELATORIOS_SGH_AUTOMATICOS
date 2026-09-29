<?php

declare(strict_types=1);

namespace Elogica\Db;

use Elogica\Security\Crypto;
use PDO;

final class ConexaoRepository
{
    public function __construct(private readonly PDO $pdo, private readonly Crypto $crypto)
    {
    }

    /** @return list<array<string, mixed>> */
    public function all(): array
    {
        return $this->pdo->query(
            'SELECT id, slug, cliente, servidor, banco, usuario_readonly, ativo FROM dbo.conexao ORDER BY cliente'
        )->fetchAll();
    }

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT id, slug, cliente, servidor, banco, usuario_readonly, ativo FROM dbo.conexao WHERE id = :id');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /** @return array<string, mixed>|null */
    public function findAtivaBySlug(string $slug): ?array
    {
        $stmt = $this->pdo->prepare('SELECT id, slug, cliente FROM dbo.conexao WHERE slug = :slug AND ativo = 1');
        $stmt->execute([':slug' => $slug]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    public function slugExists(string $slug, ?int $exceptId = null): bool
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM dbo.conexao WHERE slug = :slug AND id <> :id');
        $stmt->execute([':slug' => $slug, ':id' => $exceptId ?? 0]);

        return (int) $stmt->fetchColumn() > 0;
    }

    /** @param array<string, mixed> $d */
    public function create(array $d, string $senha): int
    {
        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare(
                'INSERT INTO dbo.conexao (slug, cliente, servidor, banco, usuario_readonly, senha_cripto, ativo) '
                . 'OUTPUT INSERTED.id VALUES (:slug, :cliente, :servidor, :banco, :usuario, :senha, :ativo)'
            );
            $stmt->execute([
                ':slug' => $d['slug'], ':cliente' => $d['cliente'], ':servidor' => $d['servidor'], ':banco' => $d['banco'],
                ':usuario' => $d['usuario_readonly'], ':senha' => $this->crypto->encrypt($senha),
                ':ativo' => $d['ativo'] ? 1 : 0,
            ]);
            $id = (int) $stmt->fetchColumn();
            $stmt->closeCursor();
            $this->pdo->commit();

            return $id;
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    /** @param array<string, mixed> $d  $senha vazia mantém a atual. */
    public function update(int $id, array $d, string $senha): void
    {
        $this->pdo->beginTransaction();
        try {
            $sql = 'UPDATE dbo.conexao SET slug = :slug, cliente = :cliente, servidor = :servidor, banco = :banco, '
                . 'usuario_readonly = :usuario, ativo = :ativo';
            $params = [
                ':slug' => $d['slug'], ':cliente' => $d['cliente'], ':servidor' => $d['servidor'], ':banco' => $d['banco'],
                ':usuario' => $d['usuario_readonly'], ':ativo' => $d['ativo'] ? 1 : 0, ':id' => $id,
            ];
            if ($senha !== '') {
                $sql .= ', senha_cripto = :senha';
                $params[':senha'] = $this->crypto->encrypt($senha);
            }
            $this->pdo->prepare($sql . ' WHERE id = :id')->execute($params);
            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    public function senha(int $id): string
    {
        $stmt = $this->pdo->prepare('SELECT senha_cripto FROM dbo.conexao WHERE id = :id');
        $stmt->execute([':id' => $id]);

        return $this->crypto->decrypt((string) $stmt->fetchColumn());
    }
}
