<?php
/** @var list<array<string, mixed>> $conexoes @var int $conexaoId @var ?array<string, mixed> $resultado @var ?string $erro @var \Elogica\Auth\Auth $auth */
$rotulos = [
    'tabelas_novas' => 'Tabelas novas', 'colunas_novas' => 'Colunas novas', 'colunas_alteradas' => 'Colunas com tipo, tamanho ou nulidade alterados',
    'tabelas_ausentes' => 'Tabelas ausentes do banco ou fora do escopo', 'colunas_ausentes' => 'Colunas ausentes do banco',
    'tabelas_reativadas' => 'Tabelas que voltaram', 'colunas_reativadas' => 'Colunas que voltaram',
];
?>
<div class="page-h">
  <div><div class="kicker">Dicionário</div><h1>Sincronizar</h1></div>
  <p class="lead">Lê tabelas, colunas e FKs do banco de um cliente de referência (só o catálogo, nunca dados) e compara com o dicionário. Nada é apagado e as descrições nunca são sobrescritas. O escopo vem do menu Escopo.</p>
</div>
<?php if ($erro): ?><div class="alert danger" role="alert"><?= e($erro) ?></div><?php endif; ?>
<form class="panel pad row" method="post" action="/admin/dicionario/sincronizar" style="align-items:end">
  <input type="hidden" name="csrf" value="<?= e($auth->csrfToken()) ?>">
  <div class="field" style="flex:1; min-width:240px; max-width:32rem"><label for="conexao">Cliente de referência</label>
    <select id="conexao" name="conexao_id" required>
      <option value="">Selecione…</option>
      <?php foreach ($conexoes as $c): ?><option value="<?= (int) $c['id'] ?>" <?= (int) $c['id'] === $conexaoId ? 'selected' : '' ?>><?= e($c['cliente']) ?> (<?= e($c['banco']) ?>)</option><?php endforeach; ?>
    </select></div>
  <button name="acao" value="analisar" class="btn">Analisar</button>
  <?php if ($resultado && $resultado['gravado'] === null && \Elogica\Metadata\Sync::temMudancas($resultado['plano'])): ?>
    <button name="acao" value="aplicar" class="btn primary" onclick="return confirm('Gravar estas mudanças no dicionário?')">Aplicar mudanças</button>
  <?php endif; ?>
</form>
<?php if ($resultado): $p = $resultado['plano']; ?>
  <?php if ($resultado['gravado'] !== null): $g = $resultado['gravado']; ?>
    <div class="alert ok" role="status">Gravado: <?= (int) $g['tabelas'] ?> tabelas, <?= (int) $g['colunas'] ?> colunas e <?= (int) $g['relacoes'] ?> relações (FK) novas.</div>
  <?php endif; ?>
  <div class="stats">
    <div class="stat"><b><?= (int) $resultado['banco_tabelas'] ?></b><span>tabelas no escopo</span></div>
    <div class="stat"><b><?= (int) $resultado['fks'] ?></b><span>FKs declaradas</span></div>
    <div class="stat"><b><?= (int) $p['sem_mudanca'] ?></b><span>colunas sem mudança</span></div>
  </div>
  <?php if (!\Elogica\Metadata\Sync::temMudancas($p)): ?><div class="alert">O dicionário já está igual ao banco.</div><?php endif; ?>
  <?php foreach ($rotulos as $k => $rotulo): if ($p[$k] === []) { continue; } ?>
    <details class="acc" <?= $k === 'colunas_alteradas' ? 'open' : '' ?>>
      <summary><b><?= e($rotulo) ?></b> <span class="pill"><?= count($p[$k]) ?></span></summary>
      <ul class="list mono">
        <?php foreach (array_slice($p[$k], 0, 300) as $i): ?>
          <li><?php
            if (isset($i['de'])) { echo e($i['tabela'] . '.' . $i['coluna'] . ': ' . $i['de'] . ' → ' . $i['para']); }
            elseif (isset($i['coluna'])) { echo e($i['tabela'] . '.' . $i['coluna']); }
            else { echo e($i['tabela']); if (isset($i['colunas'])) { echo ' (' . count($i['colunas']) . ' colunas)'; } }
          ?></li>
        <?php endforeach; ?>
        <?php if (count($p[$k]) > 300): ?><li class="muted">… e mais <?= count($p[$k]) - 300 ?></li><?php endif; ?>
      </ul>
    </details>
  <?php endforeach; ?>
<?php endif; ?>
