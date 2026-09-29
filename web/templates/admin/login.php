<?php /** @var ?string $erro @var \Elogica\Auth\Auth $auth */ ?>
<div class="row justify-content-center"><div class="col-md-4">
  <h1 class="h4 mb-3">Entrar</h1>
  <?php if ($erro): ?><div class="alert alert-danger"><?= e($erro) ?></div><?php endif; ?>
  <form method="post" action="/admin/login" autocomplete="off">
    <input type="hidden" name="csrf" value="<?= e($auth->csrfToken()) ?>">
    <div class="mb-3"><label class="form-label">Usuário</label><input name="login" class="form-control" required autofocus></div>
    <div class="mb-3"><label class="form-label">Senha</label><input name="senha" type="password" class="form-control" required></div>
    <button class="btn btn-primary w-100">Entrar</button>
  </form>
</div></div>
