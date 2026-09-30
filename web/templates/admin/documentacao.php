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
<div class="page-h">
  <div><div class="kicker">Dicionário</div><h1>Documentação</h1></div>
  <p class="lead">Lê os HTMLs da documentação técnica (nome e descrição da tabela, descrição dos campos e valores possíveis). Só preenche o que está vazio; onde já existe um texto diferente, você escolhe se sobrescreve, tabela a tabela ou todas. Nada é gravado antes da sua confirmação.</p>
</div>
<?php foreach ($erros as $er): ?><div class="alert danger" role="alert"><?= e($er) ?></div><?php endforeach; ?>

<?php if (!$lote): ?>
<form class="dropzone" method="post" action="/admin/dicionario/documentacao" enctype="multipart/form-data">
  <input type="hidden" name="csrf" value="<?= e($auth->csrfToken()) ?>">
  <input type="hidden" name="acao" value="enviar">
  <div class="field"><label for="arquivos">Arquivo .zip com os HTMLs (ou vários .htm/.html)</label>
    <input id="arquivos" type="file" name="arquivos[]" accept=".zip,.htm,.html" multiple required></div>
  <div class="row"><button class="btn primary">Ler e analisar</button>
    <span class="muted small">Versões anteriores (<code>Anterior_*</code>, <code>ANT*</code>) e arquivos que não descrevem tabelas são ignorados.</span></div>
</form>
<?php else: ?>
  <div class="stats">
    <?php foreach ([
        ['Arquivos lidos', $lote['arquivos']], ['Tabelas documentadas', count($lote['tabelas'])], ['Versões anteriores ignoradas', $lote['ignorados']],
        ['Arquivos sem tabela', $lote['sem_tabela']], ['Textos a preencher', $totalPreencher], ['Conflitos', $totalConflitos],
    ] as [$rotulo, $valor]): ?>
      <div class="stat"><b><?= (int) $valor ?></b><span><?= e($rotulo) ?></span></div>
    <?php endforeach; ?>
  </div>

  <?php if ($lote['avisos'] !== []): ?>
    <details class="acc"><summary><b>Avisos</b> <span class="badge warn"><?= count($lote['avisos']) ?></span></summary>
      <ul class="list"><?php foreach ($lote['avisos'] as $a): ?><li><?= e($a) ?></li><?php endforeach; ?></ul></details>
  <?php endif; ?>
  <?php if ($plano['doc_sem_tabela'] !== []): ?>
    <details class="acc"><summary><b>Documentadas, mas fora do dicionário</b> <span class="pill"><?= count($plano['doc_sem_tabela']) ?></span></summary>
      <p class="small muted" style="margin:8px 0 4px">Não existem no dicionário (não foram sincronizadas, ou estão fora do escopo). Serão ignoradas.</p>
      <div class="small mono"><?= e(implode(', ', array_keys($plano['doc_sem_tabela']))) ?></div></details>
  <?php endif; ?>
  <?php if ($plano['dic_sem_doc'] !== []): ?>
    <details class="acc"><summary><b>No dicionário, sem documentação</b> <span class="pill"><?= count($plano['dic_sem_doc']) ?></span></summary>
      <div class="small mono" style="margin-top:8px"><?= e(implode(', ', $plano['dic_sem_doc'])) ?></div></details>
  <?php endif; ?>

  <form method="post" action="/admin/dicionario/documentacao" class="stack" style="gap:8px">
    <input type="hidden" name="csrf" value="<?= e($auth->csrfToken()) ?>">
    <?php if ($plano['itens'] === []): ?>
      <div class="alert">O dicionário já está igual à documentação.</div>
    <?php else: ?>
      <div class="row" style="gap:16px">
        <h2 style="margin:0;font-size:14px">Tabelas com diferenças <span class="pill"><?= count($plano['itens']) ?></span></h2>
        <?php if ($totalConflitos > 0): ?>
          <label class="check small"><input type="checkbox" onclick="document.querySelectorAll('.sobrescrever').forEach(c => c.checked = this.checked)">Sobrescrever todos os conflitos</label>
        <?php endif; ?>
      </div>
      <?php foreach ($plano['itens'] as $nome => $item): ?>
        <details class="acc">
          <summary>
            <?php if ($item['conflitos'] > 0): ?>
              <input type="checkbox" class="sobrescrever" name="sobrescrever[]" value="<?= e($nome) ?>" onclick="event.stopPropagation()" aria-label="Sobrescrever os conflitos de <?= e($nome) ?>" title="Sobrescrever os conflitos desta tabela">
            <?php else: ?><span style="width:16px"></span><?php endif; ?>
            <code><?= e($nome) ?></code>
            <span class="small muted"><?= e($item['arquivo']) ?></span>
            <span class="badge ok">preencher <?= (int) $item['preencher'] ?></span>
            <?php if ($item['conflitos'] > 0): ?><span class="badge warn">conflitos <?= (int) $item['conflitos'] ?></span><?php endif; ?>
          </summary>
          <div class="table-wrap" style="margin-top:10px"><table class="tbl diff small">
            <thead><tr><th>Campo</th><th></th><th>Atual</th><th>Documentação</th></tr></thead>
            <tbody>
            <?php foreach (array_slice($item['diffs'], 0, 60) as $d): ?>
              <tr class="<?= $d['estado'] === 'conflito' ? 'conflito' : '' ?>">
                <td><code><?= e($d['alvo']) ?></code></td><td class="muted"><?= e($d['campo']) ?></td>
                <td><?= $d['atual'] === '' ? '<span class="muted">(vazio)</span>' : e($d['atual']) ?></td><td><?= e($d['doc']) ?></td>
              </tr>
            <?php endforeach; ?>
            <?php if (count($item['diffs']) > 60): ?><tr><td colspan="4" class="muted">… e mais <?= count($item['diffs']) - 60 ?></td></tr><?php endif; ?>
            </tbody>
          </table></div>
        </details>
      <?php endforeach; ?>
    <?php endif; ?>
    <div class="row" style="margin-top:6px">
      <?php if ($plano['itens'] !== []): ?>
        <button name="acao" value="aplicar" class="btn primary" onclick="return confirm('Gravar no dicionário? Vazios serão preenchidos e só os conflitos marcados serão sobrescritos.')">Aplicar</button>
      <?php endif; ?>
      <button name="acao" value="descartar" class="btn">Descartar</button>
    </div>
  </form>
<?php endif; ?>
