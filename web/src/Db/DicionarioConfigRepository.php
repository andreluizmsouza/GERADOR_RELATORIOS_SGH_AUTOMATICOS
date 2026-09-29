<?php

declare(strict_types=1);

namespace Elogica\Db;

use PDO;

/** Escopo global do dicionário: prefixo das tabelas e padrões de exclusão. */
final class DicionarioConfigRepository
{
    /** Exclusões (LIKE T-SQL) sugeridas quando ainda não há nenhuma. */
    public const EXCLUSOES_PADRAO = [
        '%[_]BKP%', '%[_]OLD', '%[_]ANT', '%[_]ALT', '%[_]CTRL', '%[_]LIMPEZA', '%[_]PENDENCIAS', '%[_]20[0-9][0-9]%',
        'MTTBCOB', 'MTTBEXC',
    ];

    public function __construct(private readonly PDO $pdo)
    {
    }

    public function prefixo(): string
    {
        $stmt = $this->pdo->prepare("SELECT valor FROM dbo.dic_config WHERE chave = 'prefixo'");
        $stmt->execute();
        $v = $stmt->fetchColumn();

        return $v === false ? 'MTTB' : (string) $v;
    }

    /** @return list<string> */
    public function exclusoes(): array
    {
        return array_map('strval', $this->pdo->query('SELECT padrao FROM dbo.dic_exclusao ORDER BY padrao')->fetchAll(PDO::FETCH_COLUMN));
    }

    /** @param list<string> $exclusoes */
    public function salvar(string $prefixo, array $exclusoes): void
    {
        $this->pdo->beginTransaction();
        try {
            $up = $this->pdo->prepare("UPDATE dbo.dic_config SET valor = :v WHERE chave = 'prefixo'");
            $up->execute([':v' => $prefixo]);
            if ($up->rowCount() === 0) {
                $this->pdo->prepare("INSERT INTO dbo.dic_config (chave, valor) VALUES ('prefixo', :v)")->execute([':v' => $prefixo]);
            }
            $this->pdo->exec('DELETE FROM dbo.dic_exclusao');
            $ins = $this->pdo->prepare('INSERT INTO dbo.dic_exclusao (padrao) VALUES (:p)');
            foreach (array_unique($exclusoes) as $padrao) {
                $ins->execute([':p' => $padrao]);
            }
            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }
}
