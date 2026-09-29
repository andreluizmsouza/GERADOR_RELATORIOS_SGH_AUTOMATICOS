<?php
/** @var array<string, mixed> $t @var list<array<string, mixed>> $colunas @var list<string> $erros @var ?array<string, mixed> $post @var \Elogica\Auth\Auth $auth */
use Elogica\Admin\RevisaoForm;
use Elogica\Metadata\Sensiveis;

$v = static fn (string $campo, mixed $atual): string => (string) ($post !== null && array_key_exists($campo, $post) ? $post[$campo] : $atual);
$opcoes = static function (array $lista, string $sel): string {
    $h = '';
    foreach ($lista as $o) {
        $h .= '<option value="' . e($o) . '"' . ($o === $sel ? ' selected' : '') . '>' . e($o) . '</option>';
    }

    return $h;
};
$valoresTexto = static fn (array $vals): string => implode("\n", array_map(static fn (array $x): string => $x[0] . ' = ' . $x[1], $vals));
?>
<div class="d-flex justify-content-between align-items-center mb-2">
  <h1 class="h4 m-0"><code><?= e($t['schema']) ?>.<?= e($t['tabela']) ?></code>
    <?php if (!$t['existe_no_banco']): ?><span class="badge text-bg-danger">não existe no banco</span><?php endif; ?></h1>
  <a class="btn btn-outline-secondary btn-sm" href="/admin/dicionario">Voltar à lista</a>
</div>
<?php foreach ($erros as $er): ?><div class="alert alert-danger py-2"><?= e($er) ?></div><?php endforeach; ?>
<form method="post" action="/admin/dicionario/tabela/<?= (int) $t['id'] ?>" autocomplete="off">
  <input type="hidden" name="csrf" value="<?= e($auth->csrfToken()) ?>">
  <div class="bg-white p-3 border rounded mb-3">
    <div class="row g-3">
      <div class="col-md-8"><label class="form-label">Descrição da tabela</label>
        <textarea name="descricao" rows="2" class="form-control"><?= e($v('descricao', $t['descricao'] ?? '')) ?></textarea></div>
      <div class="col-md-2"><label class="form-label">Situação</label>
        <select name="situacao" class="form-select"><?= $opcoes(RevisaoForm::SITUACOES, $v('situacao', $t['situacao'])) ?></select>
        <div class="form-text">excluir/interna: fora dos relatórios</div></div>
      <div class="col-md-2"><label class="form-label">Status</label>
        <select name="status_revisao" class="form-select"><?= $opcoes(RevisaoForm::STATUS, $v('status_revisao', $t['status_revisao'])) ?></select></div>
    </div>
    <div class="mt-3 d-flex flex-wrap align-items-center gap-3">
      <input id="filtro" class="form-control" style="max-width: 16rem" placeholder="Filtrar campos por nome">
      <button type="button" class="btn btn-outline-secondary btn-sm" onclick="document.querySelectorAll('[data-sugerido=\'1\']').forEach(c => c.checked = true)">Marcar sugeridos como sensíveis</button>
      <label class="form-check-label small"><input type="checkbox" class="form-check-input me-1" name="todas_revisadas" value="1">Marcar tudo como revisado ao salvar</label>
      <span class="text-muted small"><?= count($colunas) ?> campos</span>
    </div>
  </div>

  <div class="table-responsive"><table class="table table-sm bg-white align-top" id="campos">
    <thead><tr><th style="min-width:9rem">Campo</th><th style="min-width:16rem">Descrição</th><th style="min-width:9rem">Nome de negócio</th><th style="min-width:9rem">Sinônimos</th><th>Sensível</th><th style="min-width:13rem">Valores possíveis<br><small class="text-muted fw-normal">código = significado</small></th><th>Status</th></tr></thead>
    <tbody>
    <?php foreach ($colunas as $c): $id = (int) $c['id']; $p = $post['col'][$id] ?? null;
        $sug = Sensiveis::sugerir((string) $c['coluna'], (string) ($c['descricao'] ?? '')) && !$c['sensivel']; ?>
      <tr data-nome="<?= e(strtolower((string) $c['coluna'])) ?>" class="<?= $c['existe_no_banco'] ? '' : 'table-secondary' ?>">
        <td><code><?= e($c['coluna']) ?></code><div class="small text-muted"><?= e($c['tipo']) ?>(<?= (int) $c['tamanho'] ?>)<?= $c['nulo'] ? '' : ' NN' ?></div>
          <?php if (!$c['existe_no_banco']): ?><span class="badge text-bg-danger">ausente</span><?php endif; ?></td>
        <td><textarea name="col[<?= $id ?>][descricao]" rows="2" class="form-control form-control-sm"><?= e($p['descricao'] ?? $c['descricao'] ?? '') ?></textarea></td>
        <td><input name="col[<?= $id ?>][nome_negocio]" class="form-control form-control-sm" value="<?= e($p['nome_negocio'] ?? $c['nome_negocio'] ?? '') ?>"></td>
        <td><input name="col[<?= $id ?>][sinonimos]" class="form-control form-control-sm" value="<?= e($p['sinonimos'] ?? $c['sinonimos'] ?? '') ?>"></td>
        <td class="text-center"><input type="checkbox" class="form-check-input" name="col[<?= $id ?>][sensivel]" value="1" data-sugerido="<?= $sug ? '1' : '0' ?>" <?= ($p !== null ? isset($p['sensivel']) : (bool) $c['sensivel']) ? 'checked' : '' ?>>
          <?php if ($sug): ?><div class="badge text-bg-warning mt-1" title="Nome ou descrição sugerem dado pessoal (LGPD)">possível</div><?php endif; ?></td>
        <td><textarea name="col[<?= $id ?>][valores]" rows="<?= max(1, min(6, count($c['valores']))) ?>" class="form-control form-control-sm font-monospace"><?= e($p['valores'] ?? $valoresTexto($c['valores'])) ?></textarea></td>
        <td><select name="col[<?= $id ?>][status]" class="form-select form-select-sm"><?= $opcoes(RevisaoForm::STATUS, (string) ($p['status'] ?? $c['status_revisao'])) ?></select></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <input type="hidden" name="fim" value="1">
  <div class="position-sticky bottom-0 bg-light py-2 border-top d-flex gap-2">
    <button class="btn btn-primary">Salvar revisão</button>
    <a class="btn btn-outline-secondary" href="/admin/dicionario">Cancelar</a>
  </div>
</form>
<script>
document.getElementById('filtro').addEventListener('input', function () {
  const q = this.value.trim().toLowerCase();
  document.querySelectorAll('#campos tbody tr').forEach(tr => { tr.style.display = tr.dataset.nome.includes(q) ? '' : 'none'; });
});
</script>
