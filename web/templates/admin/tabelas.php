<?php /** @var string $filtro @var string $status @var list<array<string, mixed>> $tabelas */
$selo = ['sugerido_ia' => 'warn', 'revisado' => 'conf', 'rejeitado' => 'danger'];
?>
<div class="page-h">
  <div><div class="kicker">Dicionário</div><h1>Tabelas <span class="pill" style="font-size:13px"><?= count($tabelas) ?></span></h1></div>
  <form class="actions" method="get" action="/admin/dicionario">
    <input type="search" name="q" placeholder="Buscar tabela" value="<?= e($filtro) ?>" aria-label="Buscar tabela" style="width:14rem">
    <select name="status" aria-label="Status" style="width:auto">
      <option value="">Todos os status</option>
      <?php foreach (\Elogica\Admin\RevisaoForm::STATUS as $s): ?><option value="<?= e($s) ?>" <?= $s === $status ? 'selected' : '' ?>><?= e($s) ?></option><?php endforeach; ?>
    </select>
    <button class="btn">Filtrar</button>
  </form>
</div>
<div class="panel"><div class="table-wrap"><table class="tbl">
  <thead><tr><th>Tabela</th><th class="num">Campos</th><th>Revisão</th><th>Status</th><th>Situação</th><th>No banco</th><th>Descrição</th></tr></thead>
  <tbody>
  <?php foreach ($tabelas as $t): $pct = $t['colunas'] > 0 ? (int) round($t['revisadas'] / $t['colunas'] * 100) : 0; ?>
    <tr class="<?= $t['existe_no_banco'] ? '' : 'off' ?>">
      <td><a href="/admin/dicionario/tabela/<?= (int) $t['id'] ?>"><code><?= e($t['schema']) ?>.<?= e($t['tabela']) ?></code></a></td>
      <td class="num"><?= (int) $t['colunas'] ?></td>
      <td style="min-width:110px"><div class="row" style="gap:8px;flex-wrap:nowrap"><div class="bar" style="flex:1"><i style="width:<?= $pct ?>%"></i></div><span class="small muted"><?= (int) $t['revisadas'] ?></span></div></td>
      <td><span class="badge <?= $selo[$t['status_revisao']] ?? '' ?>"><?= e($t['status_revisao']) ?></span></td>
      <td><?= $t['situacao'] === 'incluir' ? '<span class="muted">incluir</span>' : '<span class="badge">' . e($t['situacao']) . '</span>' ?></td>
      <td><?= $t['existe_no_banco'] ? 'Sim' : '<span class="badge danger">Não</span>' ?></td>
      <td class="muted small"><?= e($t['descricao'] ?? '') ?></td>
    </tr>
  <?php endforeach; ?>
  <?php if ($tabelas === []): ?><tr><td colspan="7" class="empty">Nenhuma tabela. Use “Sincronizar” para carregar do banco.</td></tr><?php endif; ?>
  </tbody>
</table></div></div>
