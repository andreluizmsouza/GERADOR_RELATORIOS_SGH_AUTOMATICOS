<?php
/** @var string $conteudo @var \Elogica\Auth\Auth $auth @var ?string $flash @var bool $flashOk @var string $nav */
$css = '/assets/admin.css?v=' . (int) @filemtime(__DIR__ . '/../../public/assets/admin.css');
$itens = [
    ['/admin', 'Clientes', ['/', '/conexoes']],
    ['/admin/dicionario', 'Tabelas', ['/dicionario']],
    ['/admin/relacionamentos', 'Relacionamentos', ['/relacionamentos']],
    ['/admin/dicionario/sincronizar', 'Sincronizar', ['/dicionario/sincronizar']],
    ['/admin/dicionario/documentacao', 'Documentação', ['/dicionario/documentacao']],
    ['/admin/dicionario/escopo', 'Escopo', ['/dicionario/escopo']],
];
$ativo = static function (array $prefixos) use ($nav): bool {
    // o item mais específico vence: /dicionario/sincronizar não deve acender "Tabelas"
    foreach (['/dicionario/sincronizar', '/dicionario/documentacao', '/dicionario/escopo'] as $especifico) {
        if (str_starts_with($nav, $especifico)) {
            return in_array($especifico, $prefixos, true);
        }
    }
    foreach ($prefixos as $p) {
        if ($p === '/' ? $nav === '/' : str_starts_with($nav, $p)) {
            return true;
        }
    }

    return false;
};
?>
<!doctype html>
<html lang="pt-BR">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Admin · Elógica Relatórios</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=IBM+Plex+Mono:wght@400;500&family=IBM+Plex+Sans:wght@400;500;600&display=swap">
  <link rel="stylesheet" href="<?= e($css) ?>">
</head>
<body>
<div class="app">
  <header class="top">
    <a class="brand" href="/admin"><span class="kicker">Elógica · SGH</span><b>Relatórios</b></a>
    <?php if ($auth->user()): ?>
      <nav class="nav" aria-label="Principal">
        <?php foreach ($itens as [$href, $rotulo, $prefixos]): ?>
          <a href="<?= e($href) ?>" <?= $ativo($prefixos) ? 'aria-current="page"' : '' ?>><?= e($rotulo) ?></a>
        <?php endforeach; ?>
      </nav>
      <div class="user">
        <span><?= e($auth->user()['nome']) ?></span>
        <form method="post" action="/admin/logout">
          <input type="hidden" name="csrf" value="<?= e($auth->csrfToken()) ?>">
          <button class="btn sm">Sair</button>
        </form>
      </div>
    <?php endif; ?>
  </header>
  <?php if ($flash): ?><div class="alert <?= $flashOk ? 'ok' : 'danger' ?>" role="status"><?= e($flash) ?></div><?php endif; ?>
  <main class="stack" style="gap:14px"><?= $conteudo ?></main>
</div>
</body>
</html>
