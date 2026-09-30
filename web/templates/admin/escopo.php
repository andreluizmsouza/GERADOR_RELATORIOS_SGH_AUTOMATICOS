<?php /** @var string $prefixo @var list<string> $exclusoes @var list<string> $erros @var \Elogica\Auth\Auth $auth */ ?>
<div class="page-h">
  <div><div class="kicker">Dicionário</div><h1>Escopo</h1></div>
  <p class="lead">Vale para todos os clientes: os bancos têm a mesma estrutura, então existe um único dicionário. Aqui você define quais tabelas entram nele.</p>
</div>
<?php foreach ($erros as $er): ?><div class="alert danger" role="alert"><?= e($er) ?></div><?php endforeach; ?>
<form class="panel pad stack" method="post" action="/admin/dicionario/escopo" autocomplete="off" style="gap:16px">
  <input type="hidden" name="csrf" value="<?= e($auth->csrfToken()) ?>">
  <div class="field" style="max-width: 16rem"><label for="prefixo">Prefixo das tabelas</label><input id="prefixo" type="text" name="prefixo" value="<?= e($prefixo) ?>" required></div>
  <div class="field"><label for="exclusoes">Tabelas a ignorar</label>
    <textarea id="exclusoes" name="exclusoes" rows="10" class="mono"><?= e(implode("\n", $exclusoes)) ?></textarea>
    <span class="hint">Um padrão por linha. <code>%</code> vale por qualquer texto. Exemplos: <code>MTTBCOB</code>, <code>%[_]BKP%</code>.</span></div>
  <div class="row"><button class="btn primary">Salvar</button></div>
</form>
