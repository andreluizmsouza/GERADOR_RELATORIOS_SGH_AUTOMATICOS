<?php /** @var string $filtro @var list<array<string, mixed>> $tabelas */ ?>
<div class="d-flex justify-content-between align-items-center mb-3">
  <h1 class="h4 m-0">Dicionário: tabelas <span class="badge text-bg-secondary"><?= count($tabelas) ?></span></h1>
  <form method="get" action="/admin/dicionario" class="d-flex gap-2">
    <input name="q" class="form-control" placeholder="Filtrar por nome" value="<?= e($filtro) ?>">
    <button class="btn btn-outline-secondary">Filtrar</button>
  </form>
</div>
<div class="table-responsive"><table class="table table-sm table-hover bg-white align-middle">
  <thead><tr><th>Tabela</th><th class="text-end">Colunas</th><th>Situação</th><th>No banco</th><th>Descrição</th></tr></thead>
  <tbody>
  <?php foreach ($tabelas as $t): ?>
    <tr>
      <td><code><?= e($t['schema']) ?>.<?= e($t['tabela']) ?></code></td>
      <td class="text-end"><?= (int) $t['colunas'] ?></td>
      <td><?= e($t['situacao']) ?></td>
      <td><?= $t['existe_no_banco'] ? 'Sim' : '<span class="text-danger">Não</span>' ?></td>
      <td class="text-muted small"><?= e($t['descricao'] ?? '') ?></td>
    </tr>
  <?php endforeach; ?>
  <?php if ($tabelas === []): ?><tr><td colspan="5" class="text-center text-muted py-4">Nenhuma tabela. Use "Sincronizar" para carregar do banco.</td></tr><?php endif; ?>
  </tbody>
</table></div>
