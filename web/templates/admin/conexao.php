<?php /** @var string $hostAntes @var string $hostDepois @var callable $hostFor @var ?int $id @var array<string, mixed> $dados @var list<string> $erros @var \Elogica\Auth\Auth $auth */ ?>
<div class="page-h">
  <div><div class="kicker"><?= $id === null ? 'Novo cliente' : 'Cliente' ?></div><h1><?= $id === null ? 'Cadastrar conexão' : e($dados['cliente']) ?></h1></div>
</div>
<?php foreach ($erros as $er): ?><div class="alert danger" role="alert"><?= e($er) ?></div><?php endforeach; ?>
<form class="panel pad stack" method="post" action="<?= $id === null ? '/admin/conexoes/nova' : '/admin/conexoes/' . (int) $id ?>" autocomplete="off" style="gap:16px">
  <input type="hidden" name="csrf" value="<?= e($auth->csrfToken()) ?>">
  <div class="grid">
    <div class="field c-6"><label for="cliente">Nome do cliente</label><input id="cliente" type="text" name="cliente" value="<?= e($dados['cliente']) ?>" required></div>
    <div class="field c-6"><label for="slug">Identificador na URL</label>
      <div class="affix">
        <?php if ($hostAntes !== ''): ?><span><?= e($hostAntes) ?></span><?php endif; ?>
        <input id="slug" type="text" name="slug" placeholder="campinas" value="<?= e($dados['slug']) ?>" required>
        <?php if ($hostDepois !== ''): ?><span><?= e($hostDepois) ?></span><?php endif; ?>
      </div>
      <span class="hint">Só o nome do cliente (ex.: <code>campinas</code>). Endereço final: <code><?= e($hostFor($dados['slug'] !== '' ? (string) $dados['slug'] : 'cliente')) ?></code></span></div>
    <div class="field c-6"><label for="servidor">Servidor SQL</label><input id="servidor" type="text" name="servidor" value="<?= e($dados['servidor']) ?>" required></div>
    <div class="field c-6"><label for="banco">Banco</label><input id="banco" type="text" name="banco" value="<?= e($dados['banco']) ?>" required></div>
    <div class="field c-6"><label for="usuario">Usuário somente leitura</label><input id="usuario" type="text" name="usuario_readonly" value="<?= e($dados['usuario_readonly']) ?>" required></div>
    <div class="field c-6"><label for="senha">Senha <?= $id === null ? '' : '<span class="muted">(vazio mantém a atual)</span>' ?></label><input id="senha" type="password" name="senha" autocomplete="new-password" <?= $id === null ? 'required' : '' ?>></div>
  </div>
  <label class="check"><input type="checkbox" name="ativo" <?= $dados['ativo'] ? 'checked' : '' ?>>Cliente ativo</label>
  <div class="row"><button class="btn primary">Salvar</button><a class="btn" href="/admin">Voltar</a></div>
</form>
<?php if ($id !== null): ?>
<form class="panel pad row" method="post" action="/admin/conexoes/<?= (int) $id ?>/testar">
  <input type="hidden" name="csrf" value="<?= e($auth->csrfToken()) ?>">
  <button class="btn">Testar conexão</button>
  <span class="muted small">Confere o acesso e se o login é somente leitura.</span>
</form>
<?php endif; ?>
