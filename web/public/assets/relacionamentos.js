/* Tela de relacionamentos entre tabelas (admin). Dados vêm de /admin/relacionamentos/* em JSON. */
(() => {
  'use strict';
  const D = JSON.parse(document.getElementById('dados').textContent);
  const ORIGEM = { fk: 'FK declarada', doc: 'Documentação', chave: 'Chave composta', nome: 'Nome e tipo', manual: 'Criada por você' };
  const ESTADO = { confirmada: 'Confirmada', sugerida: 'Sugerida', rejeitada: 'Rejeitada' };
  const CONF = { alta: 'Confiança alta', media: 'Confiança média', baixa: 'Confiança baixa' };
  const MAX_LADO = 16;   // vizinhas mostradas por lado; o resto vira um nó "+N"
  const POR_PAGINA = 30; // cartões de sugestão por vez

  const S = {
    focus: D.foco, hops: 1, sel: null, tab: 'sug', busca: '', mostrar: POR_PAGINA,
    f: { fk: true, doc: true, chave: true, nome: true, manual: true, rej: false },
    mapa: null, tabelas: D.tabelas, resumo: D.resumo, maes: D.maes, last: null, cols: {},
  };
  let cy = null;
  let toastTimer = null;

  const $ = (s) => document.querySelector(s);
  const esc = (s) => String(s).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const css = (v) => getComputedStyle(document.documentElement).getPropertyValue(v).trim();
  const short = (d, n = 26) => (d.length > n ? d.slice(0, n - 1) + '…' : d);
  const npar = (r) => r.papeis.reduce((a, p) => a + p.pares.length, 0);
  const rels = () => (S.mapa ? S.mapa.relacoes : []);

  /* ---------- servidor ---------- */
  async function get(url) {
    const r = await fetch(url, { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
    if (!r.ok) throw new Error('Falha ao carregar (' + r.status + ').');
    return r.json();
  }
  async function post(campos) {
    const fd = new FormData();
    fd.append('csrf', D.csrf);
    for (const [k, v] of Object.entries(campos)) {
      if (Array.isArray(v)) v.forEach((x, i) => (Array.isArray(x) ? x.forEach((y, j) => fd.append(`${k}[${i}][${j}]`, y)) : fd.append(`${k}[]`, x)));
      else fd.append(k, v);
    }
    const r = await fetch('/admin/relacionamentos/acao', { method: 'POST', body: fd, credentials: 'same-origin' });
    let j = {};
    try { j = await r.json(); } catch (e) { /* resposta sem JSON */ }
    if (!r.ok && !j.erro) j.erro = 'Falha ao gravar (' + r.status + ').';
    return j;
  }
  async function carregarMapa() {
    S.mapa = S.focus ? await get(`/admin/relacionamentos/mapa?tabela=${encodeURIComponent(S.focus)}&saltos=${S.hops}`) : null;
  }
  async function carregarLista() {
    const d = await get('/admin/relacionamentos/dados');
    S.tabelas = d.tabelas; S.resumo = d.resumo; S.maes = d.maes;
  }
  async function refresh(fit = true) {
    try { await Promise.all([carregarMapa(), carregarLista()]); } catch (e) { toast(e.message); return; }
    renderTudo(fit);
  }

  /* ---------- vizinhança visível ---------- */
  function visible(r) {
    if (r.estado === 'rejeitada' && !S.f.rej) return false;
    return S.f[r.tipo] !== false;
  }
  function vizinhanca() {
    const rs = rels().filter(visible);
    const dist = { [S.focus]: 0 };
    let fronteira = [S.focus];
    const max = S.hops;
    for (let h = 1; h <= max; h++) {
      const prox = [];
      for (const r of rs) for (const [x, y] of [[r.a, r.b], [r.b, r.a]]) if (fronteira.includes(x) && !(y in dist)) { dist[y] = h; prox.push(y); }
      fronteira = prox;
    }
    const pend = (t) => (S.mapa.tabelas[t] ? S.mapa.tabelas[t].p : 0);
    const prio = (t) => rs.filter((r) => (r.a === t || r.b === t) && r.estado === 'sugerida').length * 1000 - (t.charCodeAt(0));
    const pais = [], filhas = [], ambos = [];
    for (const r of rs) {
      if (r.a === S.focus && dist[r.b] === 1) pais.push(r.b);
      else if (r.b === S.focus && dist[r.a] === 1) filhas.push(r.a);
    }
    const uniq = (l) => [...new Set(l)];
    const ordena = (l) => uniq(l).sort((x, y) => (pend(y) - pend(x)) || x.localeCompare(y));
    let P = ordena(pais.filter((t) => !filhas.includes(t))), F = ordena(filhas.filter((t) => !pais.includes(t)));
    ambos.push(...uniq(pais.filter((t) => filhas.includes(t))).sort());
    const oculto = { l: Math.max(0, P.length - MAX_LADO), r: Math.max(0, F.length - MAX_LADO) };
    P = P.slice(0, MAX_LADO); F = F.slice(0, MAX_LADO);
    const manter = new Set([S.focus, ...P, ...F, ...ambos]);
    for (const [t, d] of Object.entries(dist)) if (d === 2 && rs.some((r) => (r.a === t && manter.has(r.b)) || (r.b === t && manter.has(r.a)))) manter.add(t);
    const arestas = rs.filter((r) => manter.has(r.a) && manter.has(r.b));
    return { dist, manter, arestas, pais: P, filhas: F, ambos, oculto, todas: rs.filter((r) => r.a === S.focus || r.b === S.focus) };
  }

  /* ---------- topo e lista ---------- */
  function renderTop() {
    const { confirmada: c, sugerida: s, rejeitada: j } = S.resumo;
    const tot = c + s || 1;
    $('#s-conf').textContent = c; $('#s-sug').textContent = s;
    $('#p-conf').style.width = (c / tot * 100) + '%';
    $('#s-conf').closest('.row').title = `${c} confirmadas, ${s} aguardando revisão, ${j} rejeitadas`;
  }
  function renderList() {
    const q = S.busca.trim().toUpperCase();
    const l = S.tabelas.filter((t) => !q || t.n.includes(q) || t.d.toUpperCase().includes(q));
    l.sort((a, b) => (a.n === S.focus ? -1 : b.n === S.focus ? 1 : b.p - a.p || a.n.localeCompare(b.n)));
    $('#tlist').innerHTML = l.slice(0, 400).map((t) => `<li><button class="titem" type="button" data-t="${esc(t.n)}" aria-current="${t.n === S.focus}"><span class="nm">${esc(t.n)}</span>${t.p ? `<span class="pill p" title="${t.p} aguardando revisão">${t.p}</span>` : '<span></span>'}<span class="ds">${esc(t.d || 'sem documentação')}</span></button></li>`).join('')
      || '<li class="empty">Nenhuma tabela encontrada.</li>';
  }

  /* ---------- mapa ---------- */
  function edgeColor(r) { return r.tipo === 'fk' ? css('--fk') : r.estado === 'confirmada' ? css('--conf') : r.estado === 'sugerida' ? css('--sug') : css('--line-strong'); }
  function estilos() {
    const ink = css('--node-ink'), node = css('--node'), line = css('--line-strong');
    return [
      { selector: 'node', style: { shape: 'round-rectangle', 'background-color': node, 'border-width': 1.5, 'border-color': line, label: 'data(label)', 'text-wrap': 'wrap', 'text-valign': 'center', 'text-halign': 'center', color: ink, 'font-family': css('--font-mono'), 'font-size': 15, width: 'label', height: 'label', padding: '10px', 'text-max-width': 210 } },
      { selector: 'node.pend', style: { 'border-color': css('--sug'), 'border-width': 2 } },
      { selector: 'node.mais', style: { 'border-style': 'dashed', 'border-color': css('--muted'), color: css('--muted'), 'background-color': css('--canvas') } },
      { selector: 'node.focus', style: { 'background-color': css('--focus-fill'), color: css('--focus-ink'), 'border-color': css('--focus-fill'), 'font-size': 16, padding: '16px', 'font-weight': 600, 'text-max-width': 240, 'line-height': 1.3 } },
      { selector: 'edge', style: { width: 'data(w)', 'line-color': 'data(cor)', 'target-arrow-color': 'data(cor)', 'target-arrow-shape': 'triangle', 'arrow-scale': 1.1, 'curve-style': 'bezier', label: '', 'font-size': 12, color: css('--muted'), 'text-background-color': css('--canvas'), 'text-background-opacity': 1, 'text-background-padding': '2px', 'text-rotation': 'autorotate', opacity: 0.95 } },
      { selector: 'edge.lab, edge.sel', style: { label: 'data(rot)' } },
      { selector: 'edge.sug', style: { 'line-style': 'dashed', 'line-dash-pattern': [7, 5] } },
      { selector: 'edge.rej', style: { 'line-style': 'dotted', opacity: 0.6 } },
      { selector: 'edge.mais', style: { 'line-style': 'dashed', 'target-arrow-shape': 'none', label: 'data(rot)', opacity: 0.6 } },
      { selector: 'edge.sel', style: { width: 5, opacity: 1, 'z-index': 99, 'underlay-color': css('--fk'), 'underlay-opacity': 0.16, 'underlay-padding': 6 } },
    ];
  }
  /* À esquerda, as tabelas que a central consulta; à direita, as que a consultam; ligações a dois saltos ficam mais fora. */
  function posicoes(v, extras) {
    const w = $('#cy').clientWidth || 800, h = $('#cy').clientHeight || 600;
    const rx = w * 0.34, ry = h * 0.36, pos = { [S.focus]: { x: 0, y: 0 } }, ang = {};
    const lado = (lista, centro) => {
      const n = lista.length, s = Math.cos(centro) < 0 ? -1 : 1;
      if (n > 9) { // lado cheio: duas colunas com os nós distribuídos na altura
        const meio = Math.ceil(n / 2);
        lista.forEach((t, i) => {
          const col = i < meio ? 0 : 1, k = col ? i - meio : i, tam = col ? n - meio : meio, y = tam === 1 ? 0 : (k / (tam - 1) - 0.5) * 2 * ry * 0.98;
          ang[t] = Math.atan2(y, s * rx * (col ? 1.2 : 0.7)); pos[t] = { x: s * rx * (col ? 1.2 : 0.7), y };
        });
        return;
      }
      const abre = Math.min(150, Math.max(30, n * 17)) * Math.PI / 180;
      lista.forEach((t, i) => {
        const a = centro + (n === 1 ? 0 : (i / (n - 1) - 0.5) * abre);
        ang[t] = a; pos[t] = { x: Math.cos(a) * rx, y: Math.sin(a) * ry };
      });
    };
    lado([...v.pais, ...(extras.l ? ['__mais_l'] : [])], Math.PI); lado([...v.filhas, ...(extras.r ? ['__mais_r'] : [])], 0);
    v.ambos.forEach((t, i) => { const a = -Math.PI / 2 + (i - (v.ambos.length - 1) / 2) * 0.5; ang[t] = a; pos[t] = { x: Math.cos(a) * rx * 0.7, y: Math.sin(a) * ry * 0.95 }; });
    let k = 0;
    for (const t of v.manter) {
      if (t in pos) continue;
      const viz = v.arestas.find((r) => (r.a === t && ang[r.b] !== undefined) || (r.b === t && ang[r.a] !== undefined));
      const u = viz ? (viz.a === t ? viz.b : viz.a) : null, a = (u ? ang[u] : 0) + ((k++ % 3) - 1) * 0.22;
      pos[t] = { x: Math.cos(a) * rx * 1.42, y: Math.sin(a) * ry * 1.42 };
    }
    return pos;
  }
  function renderGraph(fit = true) {
    if (!S.mapa) { if (cy) cy.destroy(); cy = null; $('#cur').innerHTML = '<span class="muted">Nenhuma tabela no dicionário</span>'; return; }
    const v = vizinhanca();
    const els = [];
    const extras = { l: v.oculto.l, r: v.oculto.r };
    for (const t of v.manter) {
      const info = S.mapa.tabelas[t] || { d: '', p: 0 };
      els.push({ data: { id: t, label: t === S.focus ? t + '\n' + short(info.d || 'sem documentação', 30) : t }, classes: (t === S.focus ? 'focus ' : '') + (info.p ? 'pend' : '') });
    }
    if (extras.l) { els.push({ data: { id: '__mais_l', label: `+${extras.l} tabelas` }, classes: 'mais' }); els.push({ data: { id: 'x-l', source: S.focus, target: '__mais_l', cor: css('--muted'), w: 2, rot: extras.l + ' ligações' }, classes: 'mais' }); }
    if (extras.r) { els.push({ data: { id: '__mais_r', label: `+${extras.r} tabelas` }, classes: 'mais' }); els.push({ data: { id: 'x-r', source: '__mais_r', target: S.focus, cor: css('--muted'), w: 2, rot: extras.r + ' ligações' }, classes: 'mais' }); }
    for (const r of v.arestas) {
      els.push({ data: { id: 'r' + r.id, rid: r.id, source: r.a, target: r.b, cor: edgeColor(r), w: Math.min(2 + npar(r) * 0.35, 4), rot: r.papeis.length > 1 ? r.papeis.length + ' papéis' : npar(r) + (npar(r) > 1 ? ' colunas' : ' coluna') },
        classes: (v.arestas.length <= 8 ? 'lab ' : '') + (r.estado === 'sugerida' ? 'sug ' : '') + (r.estado === 'rejeitada' ? 'rej ' : '') + (S.sel === r.id ? 'sel' : '') });
    }
    if (!cy) {
      cy = cytoscape({ container: $('#cy'), elements: els, style: estilos(), wheelSensitivity: 0.25, minZoom: 0.3, maxZoom: 1.25, boxSelectionEnabled: false });
      cy.on('tap', 'node', (e) => { const id = e.target.id(); if (id.startsWith('__mais')) { S.tab = 'sug'; renderPanel(); } else setFocus(id); });
      cy.on('tap', 'edge', (e) => { const rid = e.target.data('rid'); if (rid) select(rid); });
      cy.on('tap', (e) => { if (e.target === cy) { S.sel = null; cy.edges().removeClass('sel'); if (S.tab === 'det') renderPanel(); } });
      cy.on('mouseover', 'node', (e) => {
        $('#cy').style.cursor = 'pointer';
        const id = e.target.id(), tip = $('#tip'), p = e.target.renderedPosition(), c = $('#cy'), t = S.mapa.tabelas[id];
        tip.innerHTML = id.startsWith('__mais') ? '<b>Mais tabelas</b>Use a aba Sugestões para ver todas' : `<b>${esc(id)}</b>${esc((t && t.d) || 'Sem documentação')} · ${t ? t.n : 0} campos`;
        tip.hidden = false;
        tip.style.left = Math.min(Math.max(p.x - 20, 8), c.clientWidth - 240) + 'px';
        tip.style.top = (p.y + 22) + 'px';
      });
      cy.on('mouseout', 'node', () => { $('#cy').style.cursor = 'default'; $('#tip').hidden = true; });
    } else { cy.json({ elements: els }); cy.style(estilos()); }
    const pos = posicoes(v, extras);
    cy.layout({ name: 'preset', positions: (n) => pos[n.id()], fit, padding: 28, animate: false }).run();
    const f = S.mapa.tabelas[S.focus] || { d: '' };
    $('#cur').innerHTML = `<span class="mono">${esc(S.focus)}</span><small>${esc(f.d || 'sem documentação')} · ${v.todas.length} ligações</small>`;
    $('#cy').setAttribute('aria-label', `Mapa de relacionamentos de ${S.focus}: ${v.todas.length} ligações`);
  }

  /* ---------- painel direito ---------- */
  const bOrigem = (r) => `<span class="badge b-${r.tipo}">${ORIGEM[r.tipo]}</span>`;
  const bEstado = (r) => `<span class="badge ${r.estado === 'confirmada' ? 'conf' : r.estado === 'sugerida' ? 'ok' : ''}">${ESTADO[r.estado]}</span>`;
  function acoes(r, grande) {
    if (r.estado === 'sugerida') return `<div class="acts"><button class="btn ok sm" data-act="ok" data-id="${r.id}" type="button">Aprovar</button><button class="btn sm" data-act="no" data-id="${r.id}" type="button">Rejeitar</button>${grande ? '' : `<button class="btn ghost sm" data-act="ver" data-id="${r.id}" type="button">Ver colunas</button>`}</div>`;
    return `<div class="acts"><button class="btn sm" data-act="re" data-id="${r.id}" type="button">Voltar a sugerida</button>${r.tipo === 'manual' ? `<button class="btn danger sm" data-act="del" data-id="${r.id}" type="button">Excluir</button>` : ''}</div>`;
  }
  const tipoAviso = (p) => (p[1] !== p[3] ? `<span class="tw">tipos diferentes: ${esc(p[1])} → ${esc(p[3])}</span>` : `<span class="t">${esc(p[1])}</span>`);
  function renderSug() {
    if (!S.mapa) return '<div class="empty">Sincronize o dicionário para ver as tabelas.</div>';
    const v = vizinhanca();
    const lista = v.todas.filter((r) => r.estado === 'sugerida').sort((a, b) => (a.conf === b.conf ? 0 : a.conf === 'alta' ? -1 : 1) || (a.aviso ? 1 : 0) - (b.aviso ? 1 : 0) || (a.a + a.b).localeCompare(b.a + b.b));
    const seguras = lista.filter((r) => r.conf === 'alta' && !r.aviso);
    if (!lista.length) {
      return rels().length === 0 && S.resumo.sugerida + S.resumo.confirmada === 0
        ? '<div class="empty">Ainda não há relacionamentos.<br>Clique em <b>Gerar sugestões</b> para começar.</div>'
        : '<div class="empty">Nada aguardando revisão nesta tabela.<br>Escolha outra tabela ou ajuste os filtros.</div>';
    }
    let h = `<button class="btn primary" data-act="lote" type="button" ${seguras.length ? '' : 'disabled'}>Aprovar as ${seguras.length} de confiança alta e sem alerta</button><div class="kicker">Aguardando você · ${lista.length}</div>`;
    h += lista.slice(0, S.mostrar).map((r) => `<div class="card ${S.sel === r.id ? 'on' : ''}" data-id="${r.id}"><div class="row"><span class="ttl">${esc(r.a)} → ${esc(r.b)}</span></div><div class="row">${bOrigem(r)}<span class="badge b-${r.conf}">${CONF[r.conf]}</span><span class="badge">${npar(r)} col · ${r.papeis.length > 1 ? r.papeis.length + ' papéis' : 'N:1'}</span></div><div class="why">${esc(r.evid)}</div>${r.aviso ? `<div class="alert warn"><span class="x" aria-hidden="true">!</span><span>${esc(r.aviso)}</span></div>` : ''}${acoes(r)}</div>`).join('');
    if (lista.length > S.mostrar) h += `<button class="btn" data-act="mais" type="button">Mostrar mais ${Math.min(POR_PAGINA, lista.length - S.mostrar)} de ${lista.length - S.mostrar}</button>`;
    return h;
  }
  function renderDet() {
    const r = rels().find((x) => x.id === S.sel);
    if (!r) {
      const t = S.mapa && S.mapa.tabelas[S.focus] ? S.mapa.tabelas[S.focus] : { d: '', n: 0, p: 0 };
      const out = rels().filter((x) => x.a === S.focus && x.estado !== 'rejeitada').length, inn = rels().filter((x) => x.b === S.focus && x.estado !== 'rejeitada').length;
      return `<div><div class="kicker">Tabela</div><h3 class="mono" style="font-size:15px">${esc(S.focus)}</h3><p class="muted" style="margin:.3rem 0 0">${esc(t.d || 'Sem documentação. Importe a documentação ou descreva a tabela na revisão.')}</p></div>
        <dl class="kv"><dt>Campos</dt><dd>${t.n}</dd><dt>Aponta para</dt><dd>${out} tabelas</dd><dt>É apontada por</dt><dd>${inn} tabelas</dd><dt>Aguardando</dt><dd>${t.p} ligações</dd></dl>
        <div class="empty" style="padding:10px 0">Clique numa linha do mapa ou num cartão de sugestão para ver as colunas de cada ligação.</div>`;
    }
    const pap = r.papeis.map((p) => `<section class="papel"><header>${esc(p.nome)}<span>${p.pares.length} ${p.pares.length > 1 ? 'pares' : 'par'}</span></header><table class="pares">${p.pares.map((q) => `<tr><td><span class="mono">${esc(r.a)}.${esc(q[0])}</span>${tipoAviso(q)}</td><td class="ar" aria-hidden="true">→</td><td><span class="mono">${esc(r.b)}.${esc(q[2])}</span><span class="t">${esc(q[3])}</span></td></tr>`).join('')}</table>${p.fonte ? `<div class="src">${esc(p.fonte)}</div>` : ''}</section>`).join('');
    return `<h3 class="mono" style="font-size:14px;overflow-wrap:anywhere">${esc(r.a)} → ${esc(r.b)}</h3>
      <div class="row">${bEstado(r)}${bOrigem(r)}<span class="badge b-${r.conf}">${CONF[r.conf]}</span></div>
      <div class="ev">${esc(r.evid)}</div>${r.aviso ? `<div class="alert warn"><span class="x" aria-hidden="true">!</span><span>${esc(r.aviso)}</span></div>` : ''}
      <dl class="kv"><dt>Cardinalidade</dt><dd>N:1 (${esc(r.a)} tem vários por ${esc(r.b)})</dd><dt>Colunas</dt><dd>${npar(r)} em ${r.papeis.length} ${r.papeis.length > 1 ? 'papéis' : 'papel'}</dd></dl>${pap}${acoes(r, true)}`;
  }
  function renderNew() {
    $('#dl-t').innerHTML = S.tabelas.map((t) => `<option value="${esc(t.n)}">`).join('');
    return `<p class="muted" style="margin:0">Crie uma ligação que o sistema não encontrou. Ela entra já confirmada.</p>
      <div class="field"><label for="n-a">Tabela de origem (o lado “N”)</label><input id="n-a" type="text" list="dl-t" value="${esc(S.focus || '')}" autocomplete="off"></div>
      <div class="field"><label for="n-b">Tabela de destino (o lado “1”)</label><input id="n-b" type="text" list="dl-t" placeholder="ex.: MTTBSE1" autocomplete="off"></div>
      <div class="field"><label for="n-p">Nome do papel</label><input id="n-p" type="text" placeholder="ex.: Contrato de gaveta" autocomplete="off"></div>
      <div class="kicker">Colunas que se correspondem</div>
      <div id="n-pares" class="stack" style="gap:6px"></div>
      <div class="row"><button class="btn sm" id="n-add" type="button">Adicionar par</button><button class="btn ok" id="n-go" type="button">Criar ligação</button></div>
      <div class="alert warn" id="n-msg" hidden></div>`;
  }
  async function colunas(t) {
    t = (t || '').toUpperCase();
    if (!t || !S.tabelas.some((x) => x.n === t)) return [];
    if (!S.cols[t]) { try { S.cols[t] = await get('/admin/relacionamentos/colunas?tabela=' + encodeURIComponent(t)); } catch (e) { S.cols[t] = []; } }
    return S.cols[t];
  }
  async function preencherColunas() {
    const opt = (l) => l.map((c) => `<option value="${esc(c[0])}">${esc(c[1])}</option>`).join('');
    $('#dl-ca').innerHTML = opt(await colunas($('#n-a').value)); $('#dl-cb').innerHTML = opt(await colunas($('#n-b').value));
  }
  function addPar(x = '', y = '') {
    const d = document.createElement('div'); d.className = 'prow';
    d.innerHTML = `<input type="text" list="dl-ca" placeholder="coluna de origem" value="${esc(x)}" aria-label="Coluna de origem"><input type="text" list="dl-cb" placeholder="coluna de destino" value="${esc(y)}" aria-label="Coluna de destino"><button class="btn ghost sm" type="button" aria-label="Remover par">×</button>`;
    d.querySelector('button').onclick = () => d.remove();
    $('#n-pares').appendChild(d);
  }
  function renderPanel() {
    document.querySelectorAll('.tabs [role=tab]').forEach((b) => b.setAttribute('aria-selected', String(b.dataset.tab === S.tab)));
    if (S.mapa) $('#n-sug').textContent = vizinhanca().todas.filter((r) => r.estado === 'sugerida').length; else $('#n-sug').textContent = '0';
    $('#body').innerHTML = S.tab === 'sug' ? renderSug() : S.tab === 'det' ? renderDet() : renderNew();
    if (S.tab === 'new') {
      addPar('CODEMP', 'CODEMP'); preencherColunas();
      $('#n-a').oninput = preencherColunas; $('#n-b').oninput = preencherColunas;
      $('#n-add').onclick = () => addPar();
      $('#n-go').onclick = criarManual;
    }
  }
  function renderTudo(fit = true) { renderTop(); renderList(); renderGraph(fit); renderPanel(); }

  /* ---------- ações ---------- */
  async function criarManual() {
    const msg = $('#n-msg'); const falha = (t) => { msg.hidden = false; msg.textContent = t; };
    const pares = [...document.querySelectorAll('#n-pares .prow')].map((r) => [...r.querySelectorAll('input')].map((i) => i.value.trim()));
    const res = await post({ acao: 'criar', a: $('#n-a').value, b: $('#n-b').value, papel: $('#n-p').value, pares });
    if (!res.ok) return falha(res.erro || 'Não foi possível criar a ligação.');
    S.focus = $('#n-a').value.toUpperCase().trim(); S.sel = res.id; S.tab = 'det';
    await refresh(); toast('Ligação criada e confirmada.');
  }
  async function definir(id, estado, msg) {
    const r = rels().find((x) => x.id === id); if (!r) return;
    const antes = r.estado;
    const res = await post({ acao: 'estado', id, estado });
    if (!res.ok) return toast(res.erro || 'Não foi possível gravar.');
    S.last = { id, estado: antes };
    await refresh(false); toast(msg, true);
  }
  async function desfazer() {
    if (!S.last) return;
    await post({ acao: 'estado', id: S.last.id, estado: S.last.estado });
    S.last = null; $('#toast').hidden = true; await refresh(false);
  }
  function select(id) { S.sel = id; S.tab = 'det'; if (cy) { cy.edges().removeClass('sel'); cy.getElementById('r' + id).addClass('sel'); } renderPanel(); }
  function setFocus(t) { S.focus = t; S.sel = null; S.mostrar = POR_PAGINA; refresh(true); }
  function toast(msg, desfazivel) {
    const el = $('#toast'); el.hidden = false;
    el.innerHTML = `<span>${esc(msg)}</span>${desfazivel ? '<button type="button" id="undo">Desfazer</button>' : ''}`;
    if (desfazivel) $('#undo').onclick = desfazer;
    clearTimeout(toastTimer); toastTimer = setTimeout(() => { el.hidden = true; }, 6000);
  }

  document.addEventListener('click', async (e) => {
    const t = e.target.closest('[data-t]'); if (t) return setFocus(t.dataset.t);
    const tab = e.target.closest('[data-tab]'); if (tab) { S.tab = tab.dataset.tab; return renderPanel(); }
    const hp = e.target.closest('[data-hops]');
    if (hp) { S.hops = +hp.dataset.hops; document.querySelectorAll('[data-hops]').forEach((b) => b.setAttribute('aria-pressed', String(b === hp))); return refresh(); }
    const a = e.target.closest('[data-act]');
    if (a) {
      const id = +a.dataset.id;
      switch (a.dataset.act) {
        case 'ok': return definir(id, 'confirmada', 'Ligação aprovada.');
        case 'no': return definir(id, 'rejeitada', 'Ligação rejeitada.');
        case 're': return definir(id, 'sugerida', 'Voltou para as sugestões.');
        case 'ver': return select(id);
        case 'mais': S.mostrar += POR_PAGINA; return renderPanel();
        case 'del': { const r = await post({ acao: 'excluir', id }); S.sel = null; S.tab = 'sug'; await refresh(false); return toast(r.ok ? 'Ligação excluída.' : 'Só ligações criadas por você podem ser excluídas.'); }
        case 'lote': {
          const ids = vizinhanca().todas.filter((r) => r.estado === 'sugerida' && r.conf === 'alta' && !r.aviso).map((r) => r.id);
          a.disabled = true;
          const r = await post({ acao: 'lote', ids });
          await refresh(false); return toast(r.ok ? `${r.aprovadas} ligações aprovadas.` : (r.erro || 'Falha ao aprovar em lote.'));
        }
        default: return;
      }
    }
    const card = e.target.closest('.card[data-id]'); if (card) select(+card.dataset.id);
  });
  $('#busca').addEventListener('input', (e) => { S.busca = e.target.value; renderList(); });
  $('#fit').addEventListener('click', () => cy && cy.fit(undefined, 30));
  $('#gerar').addEventListener('click', async () => {
    const b = $('#gerar'); b.disabled = true; b.textContent = 'Gerando…';
    const r = await post({ acao: 'gerar', maes: $('#maes').value });
    b.disabled = false; b.textContent = 'Gerar sugestões';
    if (!r.ok) return toast(r.erro || 'Não foi possível gerar as sugestões.');
    await refresh(); toast(`${r.novas} sugestões novas (${r.existentes} já existiam).`);
  });
  for (const k of Object.keys(S.f)) $('#f-' + k).addEventListener('change', (e) => { S.f[k] = e.target.checked; renderGraph(false); renderPanel(); });
  matchMedia('(prefers-color-scheme: dark)').addEventListener('change', () => renderGraph(false));
  new MutationObserver(() => renderGraph(false)).observe(document.documentElement, { attributes: true, attributeFilter: ['data-theme'] });

  $('#maes').value = (S.maes || []).join(', ');
  refresh();
})();
