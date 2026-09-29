<?php /** @var string $hostAntes @var string $hostDepois @var callable $hostFor @var ?int $id @var array<string, mixed> $dados @var list<string> $erros @var \Elogica\Auth\Auth $auth */ ?>
<h1 class="h4 mb-3"><?= $id === null ? 'Novo cliente' : 'Cliente: ' . e($dados['cliente']) ?></h1>
<?php foreach ($erros as $er): ?><div class="alert alert-danger py-2"><?= e($er) ?></div><?php endforeach; ?>
<form method="post" action="<?= $id === null ? '/admin/conexoes/nova' : '/admin/conexoes/' . (int) $id ?>" autocomplete="off" class="bg-white p-3 border rounded">
  <input type="hidden" name="csrf" value="<?= e($auth->csrfToken()) ?>">
  <div class="row g-3">
    <div class="col-md-6"><label class="form-label">Nome do cliente</label><input name="cliente" class="form-control" value="<?= e($dados['cliente']) ?>" required></div>
    <div class="col-md-6"><label class="form-label">Identificador na URL</label>
      <div class="input-group">
        <?php if ($hostAntes !== ''): ?><span class="input-group-text"><?= e($hostAntes) ?></span><?php endif; ?>
        <input name="slug" class="form-control" placeholder="campinas" value="<?= e($dados['slug']) ?>" required>
        <?php if ($hostDepois !== ''): ?><span class="input-group-text"><?= e($hostDepois) ?></span><?php endif; ?>
      </div>
      <div class="form-text">Digite só o nome do cliente (ex.: <code>campinas</code>). Endereço final: <code><?= e($hostFor($dados['slug'] !== '' ? (string) $dados['slug'] : 'cliente')) ?></code></div></div>
    <div class="col-md-6"><label class="form-label">Servidor SQL</label><input name="servidor" class="form-control" value="<?= e($dados['servidor']) ?>" required></div>
    <div class="col-md-6"><label class="form-label">Banco</label><input name="banco" class="form-control" value="<?= e($dados['banco']) ?>" required></div>
    <div class="col-md-6"><label class="form-label">Usuário somente leitura</label><input name="usuario_readonly" class="form-control" value="<?= e($dados['usuario_readonly']) ?>" required></div>
    <div class="col-md-6"><label class="form-label">Senha <?= $id === null ? '' : '<small class="text-muted">(vazio mantém a atual)</small>' ?></label><input name="senha" type="password" class="form-control" autocomplete="new-password" <?= $id === null ? 'required' : '' ?>></div>
    <div class="col-12 form-check ms-2"><input class="form-check-input" type="checkbox" name="ativo" id="ativo" <?= $dados['ativo'] ? 'checked' : '' ?>><label class="form-check-label" for="ativo">Cliente ativo</label></div>
  </div>
  <div class="mt-3 d-flex gap-2">
    <button class="btn btn-primary">Salvar</button>
    <a class="btn btn-outline-secondary" href="/admin">Voltar</a>
  </div>
</form>
<?php if ($id !== null): ?>
<form method="post" action="/admin/conexoes/<?= (int) $id ?>/testar" class="mt-3">
  <input type="hidden" name="csrf" value="<?= e($auth->csrfToken()) ?>">
  <button class="btn btn-outline-primary">Testar conexão</button>
  <small class="text-muted ms-2">Verifica o acesso e se o login é somente leitura.</small>
</form>
<?php endif; ?>
