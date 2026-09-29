<?php /** @var string $filtro @var string $status @var list<array<string, mixed>> $tabelas */ ?>
<div class="d-flex justify-content-between align-items-center mb-3">
  <h1 class="h4 m-0">Dicionário: tabelas <span class="badge text-bg-secondary"><?= count($tabelas) ?></span></h1>
  <form method="get" action="/admin/dicionario" class="d-flex gap-2">
    <input name="q" class="form-control" placeholder="Filtrar por nome" value="<?= e($filtro) ?>">
    <select name="status" class="form-select" style="width:auto">
      <option value="">Todos os status</option>
      <?php foreach (\Elogica\Admin\RevisaoForm::STATUS as $s): ?><option value="<?= e($s) ?>" <?= $s === $status ? 'selected' : '' ?>><?= e($s) ?></option><?php endforeach; ?>
    </select>
    <button class="btn btn-outline-secondary">Filtrar</button>
  </form>
</div>
<div class="table-responsive"><table class="table table-sm table-hover bg-white align-middle">
  <thead><tr><th>Tabela</th><th class="text-end">Campos</th><th class="text-end">Revisados</th><th>Status</th><th>Situação</th><th>No banco</th><th>Descrição</th></tr></thead>
  <tbody>
  <?php foreach ($tabelas as $t): ?>
    <tr>
      <td><a href="/admin/dicionario/tabela/<?= (int) $t['id'] ?>"><code><?= e($t['schema']) ?>.<?= e($t['tabela']) ?></code></a></td>
      <td class="text-end"><?= (int) $t['colunas'] ?></td>
      <td class="text-end"><?= (int) $t['revisadas'] ?></td>
      <td><?= e($t['status_revisao']) ?></td>
      <td><?= e($t['situacao']) ?></td>
      <td><?= $t['existe_no_banco'] ? 'Sim' : '<span class="text-danger">Não</span>' ?></td>
      <td class="text-muted small"><?= e($t['descricao'] ?? '') ?></td>
    </tr>
  <?php endforeach; ?>
  <?php if ($tabelas === []): ?><tr><td colspan="7" class="text-center text-muted py-4">Nenhuma tabela. Use "Sincronizar" para carregar do banco.</td></tr><?php endif; ?>
  </tbody>
</table></div>
