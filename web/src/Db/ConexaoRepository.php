<?php

declare(strict_types=1);

namespace Elogica\Db;

use Elogica\Security\Crypto;
use PDO;

final class ConexaoRepository
{
    /** Exclusões padrão (LIKE T-SQL) aplicadas a toda nova conexão. */
    public const EXCLUSOES_PADRAO = [
        '%[_]BKP%', '%[_]OLD', '%[_]ANT', '%[_]ALT', '%[_]CTRL', '%[_]LIMPEZA', '%[_]PENDENCIAS', '%[_]20[0-9][0-9]%',
        'MTTBCOB', 'MTTBEXC',
    ];

    public function __construct(private readonly PDO $pdo, private readonly Crypto $crypto)
    {
    }

    /** @return list<array<string, mixed>> */
    public function all(): array
    {
        return $this->pdo->query(
            'SELECT id, slug, cliente, servidor, banco, usuario_readonly, filtro_prefixo, ativo FROM dbo.conexao ORDER BY cliente'
        )->fetchAll();
    }

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT id, slug, cliente, servidor, banco, usuario_readonly, filtro_prefixo, ativo FROM dbo.conexao WHERE id = :id');
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

    /** @return list<string> */
    public function exclusoes(int $id): array
    {
        $stmt = $this->pdo->prepare('SELECT padrao FROM dbo.conexao_exclusao WHERE conexao_id = :id ORDER BY padrao');
        $stmt->execute([':id' => $id]);

        return array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    public function slugExists(string $slug, ?int $exceptId = null): bool
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM dbo.conexao WHERE slug = :slug AND id <> :id');
        $stmt->execute([':slug' => $slug, ':id' => $exceptId ?? 0]);

        return (int) $stmt->fetchColumn() > 0;
    }

    /** @param array<string, mixed> $d @param list<string> $exclusoes */
    public function create(array $d, string $senha, array $exclusoes): int
    {
        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare(
                'INSERT INTO dbo.conexao (slug, cliente, servidor, banco, usuario_readonly, senha_cripto, filtro_prefixo, ativo) '
                . 'OUTPUT INSERTED.id VALUES (:slug, :cliente, :servidor, :banco, :usuario, :senha, :prefixo, :ativo)'
            );
            $stmt->execute([
                ':slug' => $d['slug'], ':cliente' => $d['cliente'], ':servidor' => $d['servidor'], ':banco' => $d['banco'],
                ':usuario' => $d['usuario_readonly'], ':senha' => $this->crypto->encrypt($senha),
                ':prefixo' => $d['filtro_prefixo'], ':ativo' => $d['ativo'] ? 1 : 0,
            ]);
            $id = (int) $stmt->fetchColumn();
            $stmt->closeCursor();
            $this->replaceExclusoes($id, $exclusoes);
            $this->pdo->commit();

            return $id;
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    /** @param array<string, mixed> $d @param list<string> $exclusoes  $senha vazia mantém a atual. */
    public function update(int $id, array $d, string $senha, array $exclusoes): void
    {
        $this->pdo->beginTransaction();
        try {
            $sql = 'UPDATE dbo.conexao SET slug = :slug, cliente = :cliente, servidor = :servidor, banco = :banco, '
                . 'usuario_readonly = :usuario, filtro_prefixo = :prefixo, ativo = :ativo';
            $params = [
                ':slug' => $d['slug'], ':cliente' => $d['cliente'], ':servidor' => $d['servidor'], ':banco' => $d['banco'],
                ':usuario' => $d['usuario_readonly'], ':prefixo' => $d['filtro_prefixo'], ':ativo' => $d['ativo'] ? 1 : 0, ':id' => $id,
            ];
            if ($senha !== '') {
                $sql .= ', senha_cripto = :senha';
                $params[':senha'] = $this->crypto->encrypt($senha);
            }
            $this->pdo->prepare($sql . ' WHERE id = :id')->execute($params);
            $this->replaceExclusoes($id, $exclusoes);
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

    /** @param list<string> $exclusoes */
    private function replaceExclusoes(int $id, array $exclusoes): void
    {
        $this->pdo->prepare('DELETE FROM dbo.conexao_exclusao WHERE conexao_id = :id')->execute([':id' => $id]);
        $ins = $this->pdo->prepare('INSERT INTO dbo.conexao_exclusao (conexao_id, padrao) VALUES (:id, :padrao)');
        foreach (array_unique($exclusoes) as $padrao) {
            $ins->execute([':id' => $id, ':padrao' => $padrao]);
        }
    }
}
