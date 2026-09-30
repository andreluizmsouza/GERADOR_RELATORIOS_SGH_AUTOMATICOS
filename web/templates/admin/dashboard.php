<?php /** @var list<array<string, mixed>> $conexoes @var callable $hostFor */ ?>
<div class="page-h">
  <div><div class="kicker">Cadastro</div><h1>Clientes e conexões</h1></div>
  <div class="actions"><a class="btn primary" href="/admin/conexoes/nova">Novo cliente</a></div>
  <p class="lead">Cada cliente tem o próprio endereço e o próprio banco. O dicionário é único e vale para todos; a conexão só é usada para executar os relatórios.</p>
</div>
<div class="panel"><div class="table-wrap"><table class="tbl">
  <thead><tr><th>Cliente</th><th>Endereço</th><th>Servidor</th><th>Banco</th><th>Situação</th><th></th></tr></thead>
  <tbody>
  <?php foreach ($conexoes as $c): ?>
    <tr class="<?= $c['ativo'] ? '' : 'off' ?>">
      <td><b><?= e($c['cliente']) ?></b></td>
      <td><code><?= e($hostFor((string) $c['slug'])) ?></code></td>
      <td><?= e($c['servidor']) ?></td><td><?= e($c['banco']) ?></td>
      <td><?= $c['ativo'] ? '<span class="badge ok">Ativo</span>' : '<span class="badge">Inativo</span>' ?></td>
      <td class="num"><a class="btn sm" href="/admin/conexoes/<?= (int) $c['id'] ?>">Editar</a></td>
    </tr>
  <?php endforeach; ?>
  <?php if ($conexoes === []): ?><tr><td colspan="6" class="empty">Nenhum cliente cadastrado. Comece por “Novo cliente”.</td></tr><?php endif; ?>
  </tbody>
</table></div></div>
