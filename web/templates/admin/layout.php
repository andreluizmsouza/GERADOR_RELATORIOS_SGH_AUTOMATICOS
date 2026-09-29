<?php /** @var string $conteudo @var \Elogica\Auth\Auth $auth @var ?string $flash @var bool $flashOk */ ?>
<!doctype html>
<html lang="pt-BR">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Admin - Elógica Relatórios</title>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
</head>
<body class="bg-light">
<nav class="navbar navbar-dark bg-dark mb-4">
  <div class="container">
    <a class="navbar-brand" href="/admin">Elógica Relatórios · Admin</a>
    <?php if ($auth->user()): ?>
      <form method="post" action="/admin/logout" class="d-flex align-items-center gap-2 m-0">
        <span class="text-light small"><?= e($auth->user()['nome']) ?></span>
        <input type="hidden" name="csrf" value="<?= e($auth->csrfToken()) ?>">
        <button class="btn btn-outline-light btn-sm">Sair</button>
      </form>
    <?php endif; ?>
  </div>
</nav>
<main class="container">
  <?php if ($flash): ?><div class="alert alert-<?= $flashOk ? 'success' : 'danger' ?>"><?= e($flash) ?></div><?php endif; ?>
  <?= $conteudo ?>
</main>
</body>
</html>
