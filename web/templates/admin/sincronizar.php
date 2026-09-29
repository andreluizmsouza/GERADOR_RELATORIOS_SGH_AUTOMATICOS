<?php
/** @var list<array<string, mixed>> $conexoes @var int $conexaoId @var ?array<string, mixed> $resultado @var ?string $erro @var \Elogica\Auth\Auth $auth */
$rotulos = [
    'tabelas_novas' => 'Tabelas novas', 'colunas_novas' => 'Colunas novas', 'colunas_alteradas' => 'Colunas com tipo/tamanho/nulidade alterados',
    'tabelas_ausentes' => 'Tabelas ausentes do banco ou fora do escopo', 'colunas_ausentes' => 'Colunas ausentes do banco',
    'tabelas_reativadas' => 'Tabelas que voltaram', 'colunas_reativadas' => 'Colunas que voltaram',
];
?>
<h1 class="h4 mb-1">Sincronizar dicionário</h1>
<p class="text-muted">Lê tabelas, colunas e FKs do banco de um cliente de referência (só o catálogo, nunca dados) e compara com o dicionário.
  Nada é apagado e as descrições nunca são sobrescritas. O escopo (prefixo e exclusões) vem do menu Escopo.</p>
<?php if ($erro): ?><div class="alert alert-danger"><?= e($erro) ?></div><?php endif; ?>
<form method="post" action="/admin/dicionario/sincronizar" class="bg-white p-3 border rounded mb-4">
  <input type="hidden" name="csrf" value="<?= e($auth->csrfToken()) ?>">
  <div class="row g-2 align-items-end">
    <div class="col-md-6"><label class="form-label">Cliente de referência</label>
      <select name="conexao_id" class="form-select" required>
        <option value="">Selecione…</option>
        <?php foreach ($conexoes as $c): ?><option value="<?= (int) $c['id'] ?>" <?= (int) $c['id'] === $conexaoId ? 'selected' : '' ?>><?= e($c['cliente']) ?> (<?= e($c['banco']) ?>)</option><?php endforeach; ?>
      </select></div>
    <div class="col-auto d-flex gap-2">
      <button name="acao" value="analisar" class="btn btn-outline-primary">Analisar</button>
      <?php if ($resultado && $resultado['gravado'] === null && \Elogica\Metadata\Sync::temMudancas($resultado['plano'])): ?>
        <button name="acao" value="aplicar" class="btn btn-primary" onclick="return confirm('Gravar estas mudanças no dicionário?')">Aplicar mudanças</button>
      <?php endif; ?>
    </div>
  </div>
</form>
<?php if ($resultado): $p = $resultado['plano']; ?>
  <?php if ($resultado['gravado'] !== null): $g = $resultado['gravado']; ?>
    <div class="alert alert-success">Gravado: <?= (int) $g['tabelas'] ?> tabelas, <?= (int) $g['colunas'] ?> colunas e <?= (int) $g['relacoes'] ?> relações (FK) novas.</div>
  <?php endif; ?>
  <p>Banco de referência: <strong><?= (int) $resultado['banco_tabelas'] ?></strong> tabelas no escopo, <strong><?= (int) $resultado['fks'] ?></strong> FKs declaradas.
     Colunas sem mudança: <strong><?= (int) $p['sem_mudanca'] ?></strong>.</p>
  <?php if (!\Elogica\Metadata\Sync::temMudancas($p)): ?><div class="alert alert-info">O dicionário já está igual ao banco.</div><?php endif; ?>
  <?php foreach ($rotulos as $k => $rotulo): if ($p[$k] === []) { continue; } ?>
    <details class="mb-2 bg-white border rounded p-2" <?= $k === 'colunas_alteradas' ? 'open' : '' ?>>
      <summary><strong><?= e($rotulo) ?></strong> <span class="badge text-bg-secondary"><?= count($p[$k]) ?></span></summary>
      <ul class="small mb-0 mt-2" style="max-height: 16rem; overflow: auto">
        <?php foreach (array_slice($p[$k], 0, 300) as $i): ?>
          <li><?php
            if (isset($i['de'])) { echo e($i['tabela'] . '.' . $i['coluna'] . ': ' . $i['de'] . ' → ' . $i['para']); }
            elseif (isset($i['coluna'])) { echo e($i['tabela'] . '.' . $i['coluna']); }
            else { echo e($i['tabela']); if (isset($i['colunas'])) { echo ' (' . count($i['colunas']) . ' colunas)'; } }
          ?></li>
        <?php endforeach; ?>
        <?php if (count($p[$k]) > 300): ?><li class="text-muted">… e mais <?= count($p[$k]) - 300 ?></li><?php endif; ?>
      </ul>
    </details>
  <?php endforeach; ?>
<?php endif; ?>
