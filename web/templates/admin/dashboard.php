<?php /** @var list<array<string, mixed>> $conexoes */ ?>
<div class="d-flex justify-content-between align-items-center mb-3">
  <h1 class="h4 m-0">Clientes e conexões</h1>
  <a class="btn btn-primary" href="/admin/conexoes/nova">Novo cliente</a>
</div>
<div class="table-responsive"><table class="table table-sm table-hover bg-white align-middle">
  <thead><tr><th>Cliente</th><th>URL</th><th>Servidor</th><th>Banco</th><th>Prefixo</th><th>Situação</th><th></th></tr></thead>
  <tbody>
  <?php foreach ($conexoes as $c): ?>
    <tr>
      <td><?= e($c['cliente']) ?></td>
      <td><code>relatorio.<?= e($c['slug']) ?>.elogica.info</code></td>
      <td><?= e($c['servidor']) ?></td><td><?= e($c['banco']) ?></td><td><?= e($c['filtro_prefixo']) ?></td>
      <td><?= $c['ativo'] ? '<span class="badge text-bg-success">Ativo</span>' : '<span class="badge text-bg-secondary">Inativo</span>' ?></td>
      <td class="text-end"><a class="btn btn-outline-secondary btn-sm" href="/admin/conexoes/<?= (int) $c['id'] ?>">Editar</a></td>
    </tr>
  <?php endforeach; ?>
  <?php if ($conexoes === []): ?><tr><td colspan="7" class="text-center text-muted py-4">Nenhum cliente cadastrado.</td></tr><?php endif; ?>
  </tbody>
</table></div>
