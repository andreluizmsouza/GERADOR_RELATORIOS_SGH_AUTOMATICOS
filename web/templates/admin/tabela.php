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
<div class="page-h">
  <div><div class="kicker">Revisão · <a href="/admin/dicionario">Tabelas</a></div>
    <h1><code><?= e($t['schema']) ?>.<?= e($t['tabela']) ?></code> <?php if (!$t['existe_no_banco']): ?><span class="badge danger">não existe no banco</span><?php endif; ?></h1></div>
</div>
<?php foreach ($erros as $er): ?><div class="alert danger" role="alert"><?= e($er) ?></div><?php endforeach; ?>
<form method="post" action="/admin/dicionario/tabela/<?= (int) $t['id'] ?>" autocomplete="off" class="stack" style="gap:14px">
  <input type="hidden" name="csrf" value="<?= e($auth->csrfToken()) ?>">
  <div class="panel pad stack" style="gap:14px">
    <div class="grid">
      <div class="field c-8"><label for="descricao">Descrição da tabela</label>
        <textarea id="descricao" name="descricao" rows="2"><?= e($v('descricao', $t['descricao'] ?? '')) ?></textarea></div>
      <div class="field c-2"><label for="situacao">Situação</label>
        <select id="situacao" name="situacao"><?= $opcoes(RevisaoForm::SITUACOES, $v('situacao', $t['situacao'])) ?></select>
        <span class="hint">excluir e interna ficam fora dos relatórios</span></div>
      <div class="field c-2"><label for="status">Status</label>
        <select id="status" name="status_revisao"><?= $opcoes(RevisaoForm::STATUS, $v('status_revisao', $t['status_revisao'])) ?></select></div>
    </div>
    <div class="row" style="gap:14px">
      <input id="filtro" type="search" placeholder="Filtrar campos por nome" style="max-width:16rem" aria-label="Filtrar campos por nome">
      <button type="button" class="btn sm" onclick="document.querySelectorAll('[data-sugerido=\'1\']').forEach(c => c.checked = true)">Marcar sugeridos como sensíveis</button>
      <label class="check small"><input type="checkbox" name="todas_revisadas" value="1">Marcar tudo como revisado ao salvar</label>
      <span class="muted small"><?= count($colunas) ?> campos</span>
    </div>
  </div>

  <div class="panel"><div class="table-wrap"><table class="tbl tbl-edit" id="campos">
    <thead><tr><th style="min-width:9rem">Campo</th><th style="min-width:16rem">Descrição</th><th style="min-width:9rem">Nome de negócio</th><th style="min-width:9rem">Sinônimos</th><th>Sensível</th><th style="min-width:13rem">Valores possíveis<br><span class="muted" style="text-transform:none;letter-spacing:0;font-weight:400">código = significado</span></th><th>Status</th></tr></thead>
    <tbody>
    <?php foreach ($colunas as $c): $id = (int) $c['id']; $p = $post['col'][$id] ?? null;
        $sug = Sensiveis::sugerir((string) $c['coluna'], (string) ($c['descricao'] ?? '')) && !$c['sensivel']; ?>
      <tr data-nome="<?= e(strtolower((string) $c['coluna'])) ?>" class="<?= $c['existe_no_banco'] ? '' : 'off' ?>">
        <td><code><?= e($c['coluna']) ?></code><div class="small muted"><?= e($c['tipo']) ?>(<?= (int) $c['tamanho'] ?>)<?= $c['nulo'] ? '' : ' · NN' ?></div>
          <?php if (!$c['existe_no_banco']): ?><span class="badge danger">ausente</span><?php endif; ?></td>
        <td><textarea name="col[<?= $id ?>][descricao]" rows="2"><?= e($p['descricao'] ?? $c['descricao'] ?? '') ?></textarea></td>
        <td><input type="text" name="col[<?= $id ?>][nome_negocio]" value="<?= e($p['nome_negocio'] ?? $c['nome_negocio'] ?? '') ?>"></td>
        <td><input type="text" name="col[<?= $id ?>][sinonimos]" value="<?= e($p['sinonimos'] ?? $c['sinonimos'] ?? '') ?>"></td>
        <td style="text-align:center"><input type="checkbox" name="col[<?= $id ?>][sensivel]" value="1" aria-label="Sensível" data-sugerido="<?= $sug ? '1' : '0' ?>" <?= ($p !== null ? isset($p['sensivel']) : (bool) $c['sensivel']) ? 'checked' : '' ?>>
          <?php if ($sug): ?><div><span class="badge warn" title="Nome ou descrição sugerem dado pessoal (LGPD)">possível</span></div><?php endif; ?></td>
        <td><textarea class="mono" name="col[<?= $id ?>][valores]" rows="<?= max(1, min(6, count($c['valores']))) ?>"><?= e($p['valores'] ?? $valoresTexto($c['valores'])) ?></textarea></td>
        <td><select name="col[<?= $id ?>][status]"><?= $opcoes(RevisaoForm::STATUS, (string) ($p['status'] ?? $c['status_revisao'])) ?></select></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div></div>
  <input type="hidden" name="fim" value="1">
  <div class="sticky-bar"><button class="btn primary">Salvar revisão</button><a class="btn" href="/admin/dicionario">Cancelar</a></div>
</form>
<script>
document.getElementById('filtro').addEventListener('input', function () {
  const q = this.value.trim().toLowerCase();
  document.querySelectorAll('#campos tbody tr').forEach(tr => { tr.style.display = tr.dataset.nome.includes(q) ? '' : 'none'; });
});
</script>
