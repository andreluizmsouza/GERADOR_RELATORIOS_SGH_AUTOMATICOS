<?php /** @var string $prefixo @var list<string> $exclusoes @var list<string> $erros @var \Elogica\Auth\Auth $auth */ ?>
<h1 class="h4 mb-1">Escopo do dicionário</h1>
<p class="text-muted">Vale para todos os clientes: os bancos têm a mesma estrutura, então existe um único dicionário (a matriz).</p>
<?php foreach ($erros as $er): ?><div class="alert alert-danger py-2"><?= e($er) ?></div><?php endforeach; ?>
<form method="post" action="/admin/dicionario/escopo" autocomplete="off" class="bg-white p-3 border rounded">
  <input type="hidden" name="csrf" value="<?= e($auth->csrfToken()) ?>">
  <div class="mb-3" style="max-width: 16rem">
    <label class="form-label">Prefixo das tabelas</label>
    <input name="prefixo" class="form-control" value="<?= e($prefixo) ?>" required>
  </div>
  <div class="mb-3">
    <label class="form-label">Tabelas a ignorar <small class="text-muted">(um padrão por linha; % = qualquer texto; ex.: MTTBCOB, %[_]BKP%)</small></label>
    <textarea name="exclusoes" rows="10" class="form-control font-monospace"><?= e(implode("\n", $exclusoes)) ?></textarea>
  </div>
  <button class="btn btn-primary">Salvar</button>
</form>
