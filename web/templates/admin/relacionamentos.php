<?php
/** @var array<string, mixed> $dados @var \Elogica\Auth\Auth $auth */
$v = static fn (string $f): int => (int) @filemtime(__DIR__ . '/../../public/assets/' . $f);
$json = json_encode($dados, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_INVALID_UTF8_SUBSTITUTE);
?>
<div class="rel stack" style="gap:14px">
  <div class="page-h">
    <div><div class="kicker">Dicionário</div><h1>Relacionamentos</h1></div>
    <div class="actions" style="align-items:center; gap:18px">
      <div class="row" style="gap:18px">
        <div><b id="s-conf" style="font-size:18px">0</b> <span class="muted small">confirmadas</span></div>
        <div><b id="s-sug" style="font-size:18px">0</b> <span class="muted small">aguardando revisão</span></div>
        <div class="bar" style="width:140px" role="img" aria-label="Progresso da revisão"><i id="p-conf"></i></div>
      </div>
      <button class="btn" id="gerar" type="button">Gerar sugestões</button>
    </div>
    <p class="lead">Ligações entre tabelas, do lado “N” para o lado “1”. Cada ligação tem um ou mais papéis, e cada papel é um conjunto de colunas que só faz sentido junto (chave composta). As sugestões vêm da documentação e da chave composta, e nada vale até você aprovar.</p>
  </div>

  <div class="shell">
    <aside class="col left" aria-label="Tabelas">
      <div class="col-h"><input id="busca" type="search" placeholder="Buscar tabela" aria-label="Buscar tabela"></div>
      <div class="scroll"><ul class="tlist" id="tlist"></ul></div>
      <div class="filters" role="group" aria-label="Filtros">
        <div class="kicker">Mostrar</div>
        <label><input type="checkbox" id="f-fk" checked><i class="sw fk"></i>FK declarada</label>
        <label><input type="checkbox" id="f-doc" checked><i class="sw sug"></i>Documentação</label>
        <label><input type="checkbox" id="f-chave" checked><i class="sw sug"></i>Chave composta</label>
        <label><input type="checkbox" id="f-nome" checked><i class="sw sug"></i>Nome e tipo iguais</label>
        <label><input type="checkbox" id="f-manual" checked><i class="sw conf"></i>Criada por você</label>
        <label><input type="checkbox" id="f-rej"><i class="sw" style="border-color: var(--line-strong)"></i>Rejeitadas</label>
        <div class="kicker" style="margin-top:6px">Tabelas-mãe da chave composta</div>
        <input id="maes" type="text" placeholder="ex.: MTTBCON" aria-label="Tabelas-mãe" class="mono">
        <span class="hint muted" style="font-size:11.5px">Separe por vírgula. As tabelas que carregam a chave da mãe viram sugestões.</span>
      </div>
    </aside>

    <main class="col stage" aria-label="Mapa">
      <div class="col-h">
        <div class="cur" id="cur"></div>
        <div class="seg" role="group" aria-label="Distância" style="margin-inline-start:auto">
          <button type="button" data-hops="1" aria-pressed="true">Vizinhas</button>
          <button type="button" data-hops="2" aria-pressed="false">2 saltos</button>
        </div>
        <button class="btn sm" id="fit" type="button">Centralizar</button>
      </div>
      <div class="legend-bar" aria-hidden="true">
        <div><i class="sw fk"></i>FK declarada</div>
        <div><i class="sw sug"></i>Sugerida, aguardando você</div>
        <div><i class="sw conf"></i>Confirmada ou criada por você</div>
        <span class="hint">Clique numa tabela para centralizar · numa linha para ver as colunas</span>
      </div>
      <div class="cy-wrap">
        <span class="side-cap l">◀ Aponta para (consulta)</span><span class="side-cap r">É apontada por (filhas) ▶</span>
        <div id="cy" role="img" aria-label="Mapa de relacionamentos"></div>
        <div class="tip" id="tip" hidden></div>
      </div>
    </main>

    <aside class="col right" aria-label="Revisão">
      <div class="tabs" role="tablist">
        <button role="tab" id="tab-sug" aria-selected="true" data-tab="sug">Sugestões<span class="n" id="n-sug">0</span></button>
        <button role="tab" id="tab-det" aria-selected="false" data-tab="det">Detalhe</button>
        <button role="tab" id="tab-new" aria-selected="false" data-tab="new">Nova ligação</button>
      </div>
      <div class="scroll"><div class="body" id="body"></div></div>
    </aside>
  </div>
  <div class="toast" id="toast" hidden></div>
  <datalist id="dl-t"></datalist><datalist id="dl-ca"></datalist><datalist id="dl-cb"></datalist>
</div>
<script type="application/json" id="dados"><?= $json ?></script>
<script src="/assets/vendor/cytoscape.min.js?v=<?= $v('vendor/cytoscape.min.js') ?>"></script>
<script src="/assets/relacionamentos.js?v=<?= $v('relacionamentos.js') ?>"></script>
