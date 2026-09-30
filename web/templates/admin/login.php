<?php /** @var ?string $erro @var \Elogica\Auth\Auth $auth */ ?>
<div class="login">
  <form class="panel" method="post" action="/admin/login" autocomplete="off">
    <div><div class="kicker">Elógica · SGH</div><h1>Entrar no admin</h1></div>
    <?php if ($erro): ?><div class="alert danger" role="alert"><?= e($erro) ?></div><?php endif; ?>
    <input type="hidden" name="csrf" value="<?= e($auth->csrfToken()) ?>">
    <div class="field"><label for="login">Usuário</label><input id="login" type="text" name="login" required autofocus></div>
    <div class="field"><label for="senha">Senha</label><input id="senha" type="password" name="senha" required></div>
    <button class="btn primary" style="justify-content:center">Entrar</button>
  </form>
</div>
