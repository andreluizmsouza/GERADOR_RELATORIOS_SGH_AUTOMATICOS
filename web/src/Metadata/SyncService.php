<?php

declare(strict_types=1);

namespace Elogica\Metadata;

use Elogica\Db\ConexaoRepository;
use Elogica\Db\Control;
use Elogica\Db\DicionarioConfigRepository;

/** Orquestra a sincronização: lê o banco do cliente de referência, planeja e (opcionalmente) grava. */
final class SyncService
{
    public function __construct(
        private readonly ConexaoRepository $conexoes,
        private readonly DicionarioConfigRepository $escopo,
        private readonly DicionarioRepository $dicionario,
    ) {
    }

    /**
     * @return array{plano: array<string, mixed>, gravado: array<string, int>|null, banco_tabelas: int, fks: int}
     */
    public function executar(int $conexaoId, bool $aplicar): array
    {
        $c = $this->conexoes->find($conexaoId);
        if ($c === null) {
            throw new \RuntimeException('Conexão não encontrada.');
        }
        $pdo = Control::connect((string) $c['servidor'], (string) $c['banco'], (string) $c['usuario_readonly'], $this->conexoes->senha($conexaoId));
        $pdo->setAttribute(\PDO::SQLSRV_ATTR_QUERY_TIMEOUT, 120);

        $lido = (new SchemaReader($pdo))->ler($this->escopo->prefixo(), $this->escopo->exclusoes());
        $plano = Sync::planejar($lido['tabelas'], $this->dicionario->carregar());
        $gravado = $aplicar ? $this->dicionario->aplicar($plano, $lido['fks']) : null;

        return ['plano' => $plano, 'gravado' => $gravado, 'banco_tabelas' => count($lido['tabelas']), 'fks' => count($lido['fks'])];
    }
}
