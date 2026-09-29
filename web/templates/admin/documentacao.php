<?php
/** @var ?array<string, mixed> $lote @var ?array<string, mixed> $plano @var list<string> $erros @var \Elogica\Auth\Auth $auth */
$totalPreencher = 0;
$totalConflitos = 0;
if ($plano) {
    foreach ($plano['itens'] as $i) {
        $totalPreencher += $i['preencher'];
        $totalConflitos += $i['conflitos'];
    }
}
?>
<h1 class="h4 mb-1">Importar documentação</h1>
<p class="text-muted">Lê os HTMLs da documentação técnica (nome e descrição da tabela, descrição dos campos e valores possíveis).
  Só preenche o que está vazio; onde já existe um texto diferente, você escolhe se sobrescreve, tabela a tabela ou todas.
  Nada é gravado antes da sua confirmação.</p>
<?php foreach ($erros as $er): ?><div class="alert alert-danger py-2"><?= e($er) ?></div><?php endforeach; ?>

<?php if (!$lote): ?>
<form method="post" action="/admin/dicionario/documentacao" enctype="multipart/form-data" class="bg-white p-3 border rounded">
  <input type="hidden" name="csrf" value="<?= e($auth->csrfToken()) ?>">
  <input type="hidden" name="acao" value="enviar">
  <label class="form-label">Arquivo .zip com os HTMLs (ou vários .htm/.html)</label>
  <input type="file" name="arquivos[]" class="form-control mb-3" accept=".zip,.htm,.html" multiple required>
  <button class="btn btn-primary">Ler e analisar</button>
  <small class="text-muted ms-2">Versões anteriores (<code>Anterior_*</code>, <code>ANT*</code>) e arquivos que não descrevem tabelas são ignorados.</small>
</form>
<?php else: ?>
  <div class="row g-2 mb-3">
    <?php foreach ([
        ['Arquivos lidos', $lote['arquivos']], ['Tabelas documentadas', count($lote['tabelas'])], ['Versões anteriores ignoradas', $lote['ignorados']],
        ['Arquivos sem tabela', $lote['sem_tabela']], ['Textos a preencher', $totalPreencher], ['Conflitos', $totalConflitos],
    ] as [$rotulo, $valor]): ?>
      <div class="col-6 col-md-2"><div class="bg-white border rounded p-2 text-center"><div class="fs-4"><?= (int) $valor ?></div><div class="small text-muted"><?= e($rotulo) ?></div></div></div>
    <?php endforeach; ?>
  </div>

  <?php if ($lote['avisos'] !== []): ?>
    <details class="mb-2 bg-white border rounded p-2"><summary><strong>Avisos</strong> <span class="badge text-bg-warning"><?= count($lote['avisos']) ?></span></summary>
      <ul class="small mb-0 mt-2"><?php foreach ($lote['avisos'] as $a): ?><li><?= e($a) ?></li><?php endforeach; ?></ul></details>
  <?php endif; ?>
  <?php if ($plano['doc_sem_tabela'] !== []): ?>
    <details class="mb-2 bg-white border rounded p-2"><summary><strong>Documentadas, mas fora do dicionário</strong> <span class="badge text-bg-secondary"><?= count($plano['doc_sem_tabela']) ?></span></summary>
      <p class="small text-muted mb-1">Não existem no dicionário (não foram sincronizadas, ou estão fora do escopo). Serão ignoradas.</p>
      <div class="small"><?= e(implode(', ', array_keys($plano['doc_sem_tabela']))) ?></div></details>
  <?php endif; ?>
  <?php if ($plano['dic_sem_doc'] !== []): ?>
    <details class="mb-3 bg-white border rounded p-2"><summary><strong>No dicionário, sem documentação</strong> <span class="badge text-bg-secondary"><?= count($plano['dic_sem_doc']) ?></span></summary>
      <div class="small mt-2"><?= e(implode(', ', $plano['dic_sem_doc'])) ?></div></details>
  <?php endif; ?>

  <form method="post" action="/admin/dicionario/documentacao">
    <input type="hidden" name="csrf" value="<?= e($auth->csrfToken()) ?>">
    <?php if ($plano['itens'] === []): ?>
      <div class="alert alert-info">O dicionário já está igual à documentação.</div>
    <?php else: ?>
      <div class="d-flex align-items-center gap-3 mb-2">
        <h2 class="h6 m-0">Tabelas com diferenças <span class="badge text-bg-secondary"><?= count($plano['itens']) ?></span></h2>
        <?php if ($totalConflitos > 0): ?>
          <label class="form-check-label small"><input type="checkbox" class="form-check-input me-1" onclick="document.querySelectorAll('.sobrescrever').forEach(c => c.checked = this.checked)">Sobrescrever todos os conflitos</label>
        <?php endif; ?>
      </div>
      <?php foreach ($plano['itens'] as $nome => $item): ?>
        <details class="mb-1 bg-white border rounded p-2">
          <summary class="d-flex align-items-center gap-2">
            <?php if ($item['conflitos'] > 0): ?>
              <input type="checkbox" class="form-check-input sobrescrever" name="sobrescrever[]" value="<?= e($nome) ?>" onclick="event.stopPropagation()" title="Sobrescrever os conflitos desta tabela">
            <?php else: ?><span style="width:1rem"></span><?php endif; ?>
            <code><?= e($nome) ?></code>
            <span class="small text-muted"><?= e($item['arquivo']) ?></span>
            <span class="badge text-bg-success">preencher <?= (int) $item['preencher'] ?></span>
            <?php if ($item['conflitos'] > 0): ?><span class="badge text-bg-warning">conflitos <?= (int) $item['conflitos'] ?></span><?php endif; ?>
          </summary>
          <table class="table table-sm small mt-2 mb-0">
            <thead><tr><th>Campo</th><th></th><th>Atual</th><th>Documentação</th></tr></thead>
            <tbody>
            <?php foreach (array_slice($item['diffs'], 0, 60) as $d): ?>
              <tr class="<?= $d['estado'] === 'conflito' ? 'table-warning' : '' ?>">
                <td><code><?= e($d['alvo']) ?></code></td><td><?= e($d['campo']) ?></td>
                <td><?= $d['atual'] === '' ? '<span class="text-muted">(vazio)</span>' : e($d['atual']) ?></td><td><?= e($d['doc']) ?></td>
              </tr>
            <?php endforeach; ?>
            <?php if (count($item['diffs']) > 60): ?><tr><td colspan="4" class="text-muted">… e mais <?= count($item['diffs']) - 60 ?></td></tr><?php endif; ?>
            </tbody>
          </table>
        </details>
      <?php endforeach; ?>
    <?php endif; ?>
    <div class="mt-3 d-flex gap-2">
      <?php if ($plano['itens'] !== []): ?>
        <button name="acao" value="aplicar" class="btn btn-primary" onclick="return confirm('Gravar no dicionário? Vazios serão preenchidos e só os conflitos marcados serão sobrescritos.')">Aplicar</button>
      <?php endif; ?>
      <button name="acao" value="descartar" class="btn btn-outline-secondary">Descartar</button>
    </div>
  </form>
<?php endif; ?>
