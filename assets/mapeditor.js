// Map-Editor im Stil von draw.io: SVG-Canvas mit Pan/Zoom, Raster, Formen, Icons,
// Live-Status-Objekten, Verbindern (gerade/orthogonal/kurvig) mit Wegpunkten,
// Mehrfachauswahl, Ausrichtungshilfen, Undo/Redo, Copy/Paste, Export.
/* global RM_ICONS */
(function () {
  'use strict';

  const STATUS_COLOR = {
    up: '#2fb344', warning: '#f2b705', down: '#d63939', acked: '#f08ca0',
    unknown: '#9aa0a6', paused: '#3b82f6', none: '#9aa0a6',
  };
  const STATUS_TEXT = {
    up: 'OK', warning: 'Warnung', down: 'Fehler', acked: 'Fehler (bestätigt)',
    unknown: 'Unbekannt', paused: 'Pausiert', none: '–',
  };
  const PRIO = { down: 6, warning: 5, acked: 4, unknown: 3, up: 2, paused: 1, none: 0 };
  const FONT = 'Inter, Segoe UI, Roboto, Helvetica, Arial, sans-serif';

  const SHAPES = [
    { kind: 'shape', shape: 'rect', label: 'Rechteck', w: 120, h: 60 },
    { kind: 'shape', shape: 'rounded', label: 'Abgerundet', w: 120, h: 60 },
    { kind: 'shape', shape: 'ellipse', label: 'Ellipse', w: 120, h: 70 },
    { kind: 'shape', shape: 'diamond', label: 'Raute', w: 100, h: 80 },
    { kind: 'shape', shape: 'hexagon', label: 'Sechseck', w: 120, h: 70 },
    { kind: 'shape', shape: 'cylinder', label: 'Zylinder', w: 80, h: 90 },
    { kind: 'shape', shape: 'cloud', label: 'Wolke', w: 140, h: 90 },
    { kind: 'zone', shape: 'rect', label: 'Zone / Bereich', w: 320, h: 220 },
    { kind: 'text', shape: 'rect', label: 'Text', w: 120, h: 30 },
  ];

  const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const uid = (p) => p + Math.random().toString(36).slice(2, 9);
  const clamp = (v, a, b) => Math.max(a, Math.min(b, v));
  const clone = (o) => JSON.parse(JSON.stringify(o));
  const fmtNum = (v) => (v === null || v === undefined ? '–' : (Math.abs(v) >= 100 ? Math.round(v) : Math.round(v * 10) / 10).toLocaleString('de-DE'));

  function defaultStyle(kind) {
    if (kind === 'zone') return { fill: '#3b82f6', fillOpacity: 0.06, stroke: '#3b82f6', strokeWidth: 1.5, dash: true, fontSize: 14, fontColor: '#1e3a8a', bold: true, labelPos: 'topleft', radius: 10 };
    if (kind === 'text') return { fill: 'none', stroke: 'none', strokeWidth: 0, fontSize: 16, fontColor: '#1f2937', bold: false, labelPos: 'center' };
    if (kind === 'icon') return { fill: '#ffffff', stroke: '#334155', strokeWidth: 0, fontSize: 12, fontColor: '#1f2937', bold: true, labelPos: 'bottom', iconColor: '#1e5fa8', statusFill: false };
    if (kind === 'status') return { fill: '#ffffff', stroke: '#cbd5e1', strokeWidth: 1, fontSize: 12, fontColor: '#1f2937', bold: false, labelPos: 'center', radius: 8, statusFill: false };
    if (kind === 'image') return { fill: 'none', stroke: 'none', strokeWidth: 0, fontSize: 12, fontColor: '#1f2937', labelPos: 'bottom' };
    return { fill: '#e8f1fb', fillOpacity: 1, stroke: '#1e5fa8', strokeWidth: 1.5, dash: false, fontSize: 13, fontColor: '#0f172a', bold: false, labelPos: 'center', radius: 10, statusFill: false };
  }
  const defaultEdgeStyle = () => ({ stroke: '#64748b', width: 2, dash: false, routing: 'orthogonal', arrowEnd: false, arrowStart: false, animated: false, autoStatus: true, fontSize: 11 });

  /* ======================================================================= */

  class MapEditor {
    constructor(root, opts) {
      this.root = root;
      this.opts = Object.assign({ readonly: false, objects: null, onSave: null, onOpenObject: null, onClose: null, fetchStatus: null, name: 'Map' }, opts || {});
      const d = clone(opts.data || {});
      this.data = { nodes: d.nodes || [], edges: d.edges || [], settings: Object.assign({ grid: true, snap: true, gridSize: 10, background: '#ffffff', guides: true }, d.settings || {}) };
      this.name = this.opts.name;
      this.refresh = this.opts.refresh || 30;
      this.mode = this.opts.readonly ? 'view' : (this.opts.startMode || 'edit');
      this.sel = new Set();
      this.view = { tx: 40, ty: 40, k: 1 };
      this.status = this.opts.status || { group: {}, device: {}, sensor: {} };
      this.undo = []; this.redo = [];
      this.dirty = false;
      this.drag = null;
      this.hover = null;
      this.clip = null;
      this.lastDown = { t: 0, id: null };
      this.guides = [];
      this.buildDom();
      this.bind();
      this.render();
      setTimeout(() => this.fit(), 0);
      if (this.opts.fetchStatus) this.startPolling();
    }

    /* ------------------------------------------------------------- DOM */
    buildDom() {
      const ro = this.opts.readonly;
      this.root.classList.add('me');
      this.root.innerHTML = `
      <div class="me-toolbar">
        ${ro ? `<b class="me-title">${esc(this.name)}</b>` : `<input class="me-name" value="${esc(this.name)}" title="Name der Map">`}
        ${ro ? '' : `<div class="me-seg" role="group" aria-label="Modus">
          <button data-cmd="mode-edit" title="Bearbeiten">✏️ Bearbeiten</button>
          <button data-cmd="mode-view" title="Live-Ansicht">👁 Ansicht</button></div>`}
        <span class="me-sep"></span>
        <span class="me-edit-only">
          <button data-cmd="undo" title="Rückgängig (Strg+Z)">↶</button>
          <button data-cmd="redo" title="Wiederholen (Strg+Y)">↷</button>
          <span class="me-sep"></span>
          <button data-cmd="delete" title="Löschen (Entf)">🗑</button>
          <button data-cmd="duplicate" title="Duplizieren (Strg+D)">⧉</button>
          <button data-cmd="front" title="Nach vorne">⬆︎</button>
          <button data-cmd="back" title="Nach hinten">⬇︎</button>
          <span class="me-sep"></span>
          <button data-cmd="align-left" title="Links ausrichten">⇤</button>
          <button data-cmd="align-center" title="Horizontal zentrieren">↔</button>
          <button data-cmd="align-right" title="Rechts ausrichten">⇥</button>
          <button data-cmd="align-top" title="Oben ausrichten">⤒</button>
          <button data-cmd="align-middle" title="Vertikal zentrieren">↕</button>
          <button data-cmd="align-bottom" title="Unten ausrichten">⤓</button>
          <button data-cmd="dist-h" title="Horizontal verteilen">⋯</button>
          <button data-cmd="dist-v" title="Vertikal verteilen">⋮</button>
          <span class="me-sep"></span>
          <button data-cmd="grid" title="Raster ein/aus">#</button>
          <button data-cmd="snap" title="Am Raster einrasten">🧲</button>
        </span>
        <span class="me-sep"></span>
        <button data-cmd="zoom-out" title="Verkleinern (Strg+-)">−</button>
        <button data-cmd="zoom-reset" class="me-zoom" title="100 %">100%</button>
        <button data-cmd="zoom-in" title="Vergrößern (Strg++)">+</button>
        <button data-cmd="fit" title="Alles anzeigen (Strg+Shift+H)">⛶</button>
        <span class="me-flex"></span>
        <span class="me-live" title="Live-Status"><i></i><span>live</span></span>
        <div class="me-menu-wrap">
          <button data-cmd="export-menu" title="Export">⤓ Export</button>
          <div class="me-menu" hidden>
            <button data-cmd="export-png">Als PNG-Bild</button>
            <button data-cmd="export-svg">Als SVG-Vektorgrafik</button>
            <button data-cmd="export-json">Als JSON (Backup)</button>
            ${ro ? '' : '<button data-cmd="import-json">JSON importieren …</button>'}
          </div>
        </div>
        ${ro ? `<button data-cmd="fullscreen" title="Vollbild">⛶ Vollbild</button>` : `<button data-cmd="save" class="primary" title="Speichern (Strg+S)">💾 Speichern</button>`}
        ${this.opts.onClose ? '<button data-cmd="close" title="Schließen">✕</button>' : ''}
      </div>
      <div class="me-body">
        <aside class="me-left me-edit-only">
          <div class="me-tabs"><button data-tab="shapes" class="on">Formen</button><button data-tab="objects">Objekte</button></div>
          <div class="me-pane" data-pane="shapes">
            <h4>Allgemein</h4><div class="me-palette" data-group="shapes"></div>
            <h4>Netzwerk</h4><div class="me-palette" data-group="icons"></div>
            <h4>Sonstiges</h4><div class="me-palette" data-group="misc"></div>
          </div>
          <div class="me-pane" data-pane="objects" hidden>
            <input class="me-search" placeholder="Objekte suchen …">
            <p class="me-hint">Gerät, Gruppe oder Sensor auf die Fläche ziehen – zeigt dort den Live-Status.</p>
            <div class="me-objtree"></div>
          </div>
        </aside>
        <div class="me-canvas-wrap">
          <svg class="me-svg" tabindex="0" xmlns="http://www.w3.org/2000/svg">
            <defs>
              <pattern id="me-grid-s" width="10" height="10" patternUnits="userSpaceOnUse"><path d="M10 0H0V10" fill="none" stroke="#e5e9f0" stroke-width="0.6"/></pattern>
              <pattern id="me-grid-l" width="50" height="50" patternUnits="userSpaceOnUse"><rect width="50" height="50" fill="url(#me-grid-s)"/><path d="M50 0H0V50" fill="none" stroke="#d3dae5" stroke-width="0.9"/></pattern>
              <filter id="me-shadow" x="-20%" y="-20%" width="140%" height="140%"><feDropShadow dx="0" dy="1.5" stdDeviation="2" flood-opacity=".18"/></filter>
            </defs>
            <rect class="me-bg" x="0" y="0" width="100%" height="100%"/>
            <g class="me-vp">
              <rect class="me-gridrect" x="-20000" y="-20000" width="40000" height="40000"/>
              <g class="me-content"></g>
              <g class="me-overlay"></g>
            </g>
          </svg>
          <div class="me-tip" hidden></div>
          <textarea class="me-inline" hidden></textarea>
          <div class="me-ctx" hidden></div>
          <div class="me-empty" hidden>Leere Map – ziehe links Formen oder Objekte auf die Fläche.</div>
        </div>
        <aside class="me-right me-edit-only"><div class="me-props"></div></aside>
      </div>
      <input type="file" class="me-file" accept="application/json,.json" hidden>`;
      const q = (s) => this.root.querySelector(s);
      this.svg = q('.me-svg'); this.vp = q('.me-vp'); this.content = q('.me-content'); this.overlay = q('.me-overlay');
      this.gridRect = q('.me-gridrect'); this.bg = q('.me-bg');
      this.tip = q('.me-tip'); this.inline = q('.me-inline'); this.ctx = q('.me-ctx'); this.props = q('.me-props');
      this.wrap = q('.me-canvas-wrap'); this.emptyHint = q('.me-empty');
      this.buildPalette();
      this.buildObjectTree();
      this.applyMode();
    }

    buildPalette() {
      const mk = (item, i, group) => {
        const prev = this.previewSvg(item);
        return `<div class="me-pal" draggable="true" data-group="${group}" data-i="${i}" title="${esc(item.label)}">${prev}<span>${esc(item.label)}</span></div>`;
      };
      this.palItems = {
        shapes: SHAPES,
        icons: Object.entries(RM_ICONS.all()).map(([k, v]) => ({ kind: 'icon', icon: k, label: v.label, w: 64, h: 64 })),
        misc: [
          { kind: 'image', label: 'Bild (URL)', w: 160, h: 100 },
          { kind: 'link', label: 'Link-Kachel', w: 140, h: 44 },
        ],
      };
      for (const g of Object.keys(this.palItems)) {
        const el = this.root.querySelector(`.me-palette[data-group="${g}"]`);
        if (el) el.innerHTML = this.palItems[g].map((it, i) => mk(it, i, g)).join('');
      }
    }

    previewSvg(it) {
      const n = Object.assign({ x: 4, y: 4, w: 40, h: 28, style: defaultStyle(it.kind), label: '' }, clone(it));
      n.w = 40; n.h = it.kind === 'icon' ? 32 : 28; n.label = '';
      if (it.kind === 'icon') return `<svg viewBox="0 0 48 48" width="34" height="34" style="color:#1e5fa8">${RM_ICONS.inner(it.icon)}</svg>`;
      if (it.kind === 'text') return '<svg viewBox="0 0 48 36" width="40" height="30"><text x="24" y="24" text-anchor="middle" font-size="18" font-family="serif" fill="#334155">Tt</text></svg>';
      if (it.kind === 'image') return '<svg viewBox="0 0 48 36" width="40" height="30"><rect x="4" y="4" width="40" height="28" rx="2" fill="#eef2f7" stroke="#94a3b8"/><path d="M8 28l10-12 8 9 5-5 9 8z" fill="#94a3b8"/><circle cx="34" cy="12" r="3" fill="#94a3b8"/></svg>';
      if (it.kind === 'link') return '<svg viewBox="0 0 48 36" width="40" height="30"><rect x="3" y="8" width="42" height="20" rx="5" fill="#1e5fa8"/><text x="24" y="22" text-anchor="middle" font-size="10" fill="#fff" font-family="sans-serif">Link ↗</text></svg>';
      return `<svg viewBox="0 0 48 36" width="40" height="30">${this.shapeSvg(n, n.style, 0.9)}</svg>`;
    }

    buildObjectTree() {
      const box = this.root.querySelector('.me-objtree');
      if (!box) return;
      const t = this.opts.objects;
      if (!t) { box.innerHTML = '<p class="me-hint">Keine Objekte geladen.</p>'; return; }
      const sensorRow = (s, d) => `<div class="me-obj" draggable="true" data-bt="sensor" data-bid="${s.id}" data-name="${esc(s.name)}" data-search="${esc((d.name + ' ' + s.name).toLowerCase())}"><i class="dot" style="background:${STATUS_COLOR[s.status]}"></i>${esc(s.icon || '')} ${esc(s.name)}</div>`;
      const dev = (d) => `<div class="me-objdev" data-search="${esc((d.name + ' ' + d.host).toLowerCase())}">
          <div class="me-obj dev" draggable="true" data-bt="device" data-bid="${d.id}" data-icon="${esc(d.icon)}" data-name="${esc(d.name)}"><i class="dot" style="background:${STATUS_COLOR[d.status]}"></i><span class="ico">${RM_ICONS.html(d.icon, 16)}</span>${esc(d.name)}</div>
          <div class="me-objsens">${d.sensors.map((s) => sensorRow(s, d)).join('')}</div></div>`;
      const grp = (g, depth) => `<div class="me-objgrp" style="--d:${depth}">
          <div class="me-obj grp" draggable="true" data-bt="group" data-bid="${g.id}" data-name="${esc(g.name)}"><i class="dot" style="background:${STATUS_COLOR[g.status]}"></i>📁 ${esc(g.name)}</div>
          ${g.devices.map(dev).join('')}${g.groups.map((c) => grp(c, depth + 1)).join('')}</div>`;
      box.innerHTML = grp(t, 0);
    }

    applyMode() {
      this.root.classList.toggle('me-mode-view', this.mode === 'view');
      this.root.classList.toggle('me-mode-edit', this.mode === 'edit');
      this.root.querySelectorAll('[data-cmd="mode-edit"],[data-cmd="mode-view"]').forEach((b) => b.classList.toggle('on', b.dataset.cmd === 'mode-' + this.mode));
      if (this.mode === 'view') this.sel.clear();
      this.render();
    }

    /* ------------------------------------------------------- Koordinaten */
    toWorld(cx, cy) {
      const r = this.svg.getBoundingClientRect();
      return { x: (cx - r.left - this.view.tx) / this.view.k, y: (cy - r.top - this.view.ty) / this.view.k };
    }
    toScreen(x, y) {
      const r = this.svg.getBoundingClientRect();
      return { x: x * this.view.k + this.view.tx + r.left, y: y * this.view.k + this.view.ty + r.top };
    }
    snap(v) {
      const g = this.data.settings.gridSize || 10;
      return this.data.settings.snap ? Math.round(v / g) * g : Math.round(v);
    }
    node(id) { return this.data.nodes.find((n) => n.id === id); }
    edge(id) { return this.data.edges.find((e) => e.id === id); }
    selNodes() { return this.data.nodes.filter((n) => this.sel.has(n.id)); }
    selEdges() { return this.data.edges.filter((e) => this.sel.has(e.id)); }

    nodeAt(p, pad = 0) {
      for (let i = this.data.nodes.length - 1; i >= 0; i--) {
        const n = this.data.nodes[i];
        if (n.kind === 'zone') continue;
        if (p.x >= n.x - pad && p.x <= n.x + n.w + pad && p.y >= n.y - pad && p.y <= n.y + n.h + pad) return n;
      }
      for (let i = this.data.nodes.length - 1; i >= 0; i--) {
        const n = this.data.nodes[i];
        if (n.kind !== 'zone') continue;
        if (p.x >= n.x - pad && p.x <= n.x + n.w + pad && p.y >= n.y - pad && p.y <= n.y + n.h + pad) return n;
      }
      return null;
    }

    /* -------------------------------------------------------------- Status */
    bindInfo(b) {
      if (!b || !b.type) return null;
      return (this.status[b.type] || {})[b.id] || { name: '(gelöscht)', status: 'unknown' };
    }
    statusOf(b) { const i = this.bindInfo(b); return i ? i.status : null; }

    labelText(n) {
      let t = n.label ?? '';
      const i = this.bindInfo(n.bind);
      if (i) {
        t = t.replace(/\{name\}/g, i.name || '').replace(/\{status\}/g, STATUS_TEXT[i.status] || '')
          .replace(/\{value\}/g, i.value !== undefined ? fmtNum(i.value) + (i.unit ? ' ' + i.unit : '') : '')
          .replace(/\{message\}/g, i.message || '').replace(/\{host\}/g, i.host || '');
      }
      return t;
    }

    /* ============================================================ Rendering */
    render() {
      if (this.destroyed) return;
      const s = this.data.settings;
      this.vp.setAttribute('transform', `translate(${this.view.tx},${this.view.ty}) scale(${this.view.k})`);
      this.gridRect.setAttribute('fill', s.grid ? 'url(#me-grid-l)' : 'transparent');
      this.bg.setAttribute('fill', s.background || '#fff');
      this.root.querySelector('.me-zoom').textContent = Math.round(this.view.k * 100) + '%';
      this.root.querySelectorAll('[data-cmd="grid"]').forEach((b) => b.classList.toggle('on', !!s.grid));
      this.root.querySelectorAll('[data-cmd="snap"]').forEach((b) => b.classList.toggle('on', !!s.snap));
      const sm = this.root.querySelector('[data-cmd="save"]');
      if (sm) sm.classList.toggle('dirty', this.dirty);
      this.emptyHint.hidden = this.data.nodes.length > 0 || !!(s.bg && s.bg.src);

      this.content.innerHTML = this.contentSvg();
      this.renderOverlay();
      if (!this.opts.readonly && this.mode === 'edit') this.renderProps();
    }

    contentSvg() {
      // Zonen zuerst (immer hinten), dann Kanten, dann Knoten, Kantenbeschriftungen ganz oben
      let out = '';
      this._labels = [];
      const bg = this.data.settings.bg;
      if (bg && bg.src) {
        out += `<image class="me-bgimg" href="${esc(bg.src)}" x="${bg.x || 0}" y="${bg.y || 0}" width="${bg.w}" height="${bg.h}" opacity="${bg.opacity ?? 1}" preserveAspectRatio="none" pointer-events="none"/>`;
        if (this.bgMove && this.mode === 'edit') out += `<rect x="${bg.x || 0}" y="${bg.y || 0}" width="${bg.w}" height="${bg.h}" fill="none" stroke="#f59e0b" stroke-width="${2 / this.view.k}" stroke-dasharray="${8 / this.view.k} ${5 / this.view.k}" pointer-events="none"/>`;
      }
      for (const n of this.data.nodes) if (n.kind === 'zone') out += this.nodeSvg(n);
      for (const e of this.data.edges) out += this.edgeSvg(e);
      for (const n of this.data.nodes) if (n.kind !== 'zone') out += this.nodeSvg(n);
      return out + this._labels.join('');
    }

    shapeSvg(n, st, sw) {
      const { x, y, w, h } = n;
      const status = n.bind ? this.statusOf(n.bind) : null;
      let fill = st.fill || 'none';
      if (st.statusFill && status) fill = STATUS_COLOR[status];
      const stroke = st.stroke || 'none';
      const a = `fill="${esc(fill)}" fill-opacity="${st.fillOpacity ?? 1}" stroke="${esc(stroke)}" stroke-width="${sw ?? st.strokeWidth ?? 1}"${st.dash ? ' stroke-dasharray="6 4"' : ''}`;
      switch (n.shape) {
        case 'ellipse': return `<ellipse cx="${x + w / 2}" cy="${y + h / 2}" rx="${w / 2}" ry="${h / 2}" ${a}/>`;
        case 'diamond': return `<polygon points="${x + w / 2},${y} ${x + w},${y + h / 2} ${x + w / 2},${y + h} ${x},${y + h / 2}" ${a}/>`;
        case 'hexagon': { const q = Math.min(w * 0.25, h / 2); return `<polygon points="${x + q},${y} ${x + w - q},${y} ${x + w},${y + h / 2} ${x + w - q},${y + h} ${x + q},${y + h} ${x},${y + h / 2}" ${a}/>`; }
        case 'cylinder': { const ry = Math.min(h * 0.15, 14); return `<path d="M${x},${y + ry} A${w / 2},${ry} 0 0 1 ${x + w},${y + ry} V${y + h - ry} A${w / 2},${ry} 0 0 1 ${x},${y + h - ry} Z" ${a}/><path d="M${x},${y + ry} A${w / 2},${ry} 0 0 0 ${x + w},${y + ry}" fill="none" stroke="${esc(stroke)}" stroke-width="${sw ?? st.strokeWidth}"/>`; }
        case 'cloud': {
          const p = (px, py) => `${x + px * w},${y + py * h}`;
          return `<path d="M${p(0.25, 0.85)} C${p(0.02, 0.85)} ${p(0.0, 0.5)} ${p(0.2, 0.45)} C${p(0.15, 0.15)} ${p(0.45, 0.05)} ${p(0.55, 0.25)} C${p(0.65, 0.02)} ${p(0.95, 0.12)} ${p(0.85, 0.42)} C${p(1.02, 0.5)} ${p(1.0, 0.85)} ${p(0.78, 0.85)} Z" ${a}/>`;
        }
        case 'rounded': return `<rect x="${x}" y="${y}" width="${w}" height="${h}" rx="${Math.min(st.radius ?? 12, h / 2)}" ${a}/>`;
        default: return `<rect x="${x}" y="${y}" width="${w}" height="${h}" rx="${n.kind === 'zone' ? (st.radius ?? 10) : (n.shape === 'rect' ? 0 : 4)}" ${a}/>`;
      }
    }

    textSvg(n, text, st, pos) {
      if (!text) return '';
      const lines = String(text).split('\n');
      const fs = st.fontSize || 12;
      const lh = fs * 1.25;
      const { x, y, w, h } = n;
      let tx = x + w / 2, ty, anchor = 'middle';
      if (pos === 'bottom') ty = y + h + fs + 2;
      else if (pos === 'top') ty = y - 6 - (lines.length - 1) * lh;
      else if (pos === 'topleft') { tx = x + 10; ty = y + fs + 6; anchor = 'start'; }
      else ty = y + h / 2 - ((lines.length - 1) * lh) / 2 + fs * 0.35;
      const halo = pos === 'bottom' || pos === 'top' ? ` paint-order="stroke" stroke="${esc(this.data.settings.background || '#fff')}" stroke-width="3" stroke-linejoin="round"` : '';
      return `<text x="${tx}" y="${ty}" text-anchor="${anchor}" font-family="${FONT}" font-size="${fs}" font-weight="${st.bold ? 600 : 400}" fill="${esc(st.fontColor || '#111')}"${halo}>${lines.map((l, i) => `<tspan x="${tx}" dy="${i ? lh : 0}">${esc(l)}</tspan>`).join('')}</text>`;
    }

    nodeSvg(n) {
      const st = Object.assign(defaultStyle(n.kind), n.style || {});
      const status = n.bind ? this.statusOf(n.bind) : null;
      const info = n.bind ? this.bindInfo(n.bind) : null;
      const clickable = this.mode === 'view' && (n.bind || n.link);
      let g = `<g data-id="${n.id}" class="me-node${clickable ? ' me-click' : ''}"${st.opacity !== undefined ? ` opacity="${st.opacity}"` : ''}>`;
      const { x, y, w, h } = n;
      const label = this.labelText(n);
      if (n.kind === 'icon') {
        if (st.fill && st.fill !== 'none') g += `<rect x="${x}" y="${y}" width="${w}" height="${h}" rx="10" fill="${esc(st.statusFill && status ? STATUS_COLOR[status] : st.fill)}" stroke="${esc(st.stroke)}" stroke-width="${st.strokeWidth || 0}" filter="url(#me-shadow)"/>`;
        if (status) g += `<rect x="${x - 3}" y="${y - 3}" width="${w + 6}" height="${h + 6}" rx="12" fill="none" stroke="${STATUS_COLOR[status]}" stroke-width="3"${status === 'down' ? ' class="me-pulse"' : ''}/>`;
        const pad = Math.min(w, h) * 0.14;
        const color = st.statusFill && status ? '#fff' : (st.iconColor || '#1e5fa8');
        g += `<svg x="${x + pad}" y="${y + pad}" width="${w - 2 * pad}" height="${h - 2 * pad}" viewBox="0 0 48 48" color="${esc(color)}" style="color:${esc(color)}" overflow="visible">${RM_ICONS.inner(n.icon, esc(color))}</svg>`;
        if (status) {
          const r = Math.max(6, Math.min(w, h) * 0.13);
          g += `<circle cx="${x + w - 2}" cy="${y + 2}" r="${r}" fill="${STATUS_COLOR[status]}" stroke="#fff" stroke-width="2"/>`;
          if (info && info.counts) {
            const bad = (info.counts.down || 0) + (info.counts.warning || 0);
            if (bad) g += `<text x="${x + w - 2}" y="${y + 2 + r * 0.38}" text-anchor="middle" font-size="${r * 1.1}" font-weight="700" fill="#fff" font-family="${FONT}">${bad}</text>`;
          }
        }
        g += this.textSvg(n, label, st, st.labelPos || 'bottom');
      } else if (n.kind === 'status') {
        const c = STATUS_COLOR[status || 'unknown'];
        const bg = st.statusFill ? c : (st.fill || '#fff');
        const fc = st.statusFill ? '#fff' : (st.fontColor || '#111');
        g += `<rect x="${x}" y="${y}" width="${w}" height="${h}" rx="${st.radius ?? 8}" fill="${esc(bg)}" stroke="${esc(st.stroke)}" stroke-width="${st.strokeWidth ?? 1}" filter="url(#me-shadow)"/>`;
        g += `<rect x="${x}" y="${y}" width="7" height="${h}" rx="3" fill="${c}"/>`;
        const fs = st.fontSize || 12;
        const title = label || (info ? info.name + (n.channel ? ' · ' + n.channel : '') : '');
        let sub = '';
        if (n.bind && n.bind.type === 'sensor' && info) {
          if (n.channel && info.channels && info.channels[n.channel] !== undefined) {
            const last = n.channel.split(' ').pop();
            sub = `${fmtNum(info.channels[n.channel])}${/^(%|Mbit\/s|GB|MB|ms|h|Tage|States)$/.test(last) ? ' ' + last : ''}`;
          }
          else sub = `${fmtNum(info.value)}${info.unit ? ' ' + info.unit : ''}`;
        }
        else if (info && info.counts) {
          const c2 = info.counts;
          sub = ['down', 'warning', 'up', 'paused'].filter((k) => c2[k]).map((k) => `${c2[k]}× ${STATUS_TEXT[k]}`).join(' · ') || STATUS_TEXT[status];
        }
        g += `<text x="${x + 16}" y="${y + h / 2 - (sub ? fs * 0.25 : -fs * 0.35)}" font-family="${FONT}" font-size="${fs}" font-weight="600" fill="${esc(fc)}">${esc(this.trunc(title, w - 24, fs))}</text>`;
        if (sub) g += `<text x="${x + 16}" y="${y + h / 2 + fs * 1.0}" font-family="${FONT}" font-size="${fs * (n.bind && n.bind.type === 'sensor' ? 1.25 : 0.95)}" font-weight="${n.bind && n.bind.type === 'sensor' ? 700 : 400}" fill="${esc(st.statusFill ? '#fff' : (n.bind && n.bind.type === 'sensor' ? c : '#475569'))}">${esc(this.trunc(sub, w - 24, fs))}</text>`;
      } else if (n.kind === 'image') {
        if (n.src) g += `<image href="${esc(n.src)}" x="${x}" y="${y}" width="${w}" height="${h}" preserveAspectRatio="xMidYMid meet"/>`;
        else g += `<rect x="${x}" y="${y}" width="${w}" height="${h}" fill="#f1f5f9" stroke="#94a3b8" stroke-dasharray="4 3"/><text x="${x + w / 2}" y="${y + h / 2 + 4}" text-anchor="middle" font-size="12" fill="#64748b" font-family="${FONT}">Bild-URL rechts eintragen</text>`;
        g += this.textSvg(n, label, st, st.labelPos || 'bottom');
      } else if (n.kind === 'link') {
        g += `<rect x="${x}" y="${y}" width="${w}" height="${h}" rx="${st.radius ?? 8}" fill="${esc(st.fill)}" stroke="${esc(st.stroke)}" stroke-width="${st.strokeWidth ?? 0}" filter="url(#me-shadow)"/>`;
        g += this.textSvg(n, (label || 'Link') + ' ↗', st, 'center');
      } else {
        if (n.kind !== 'text') g += this.shapeSvg(n, st);
        else g += `<rect x="${x}" y="${y}" width="${w}" height="${h}" fill="transparent"/>`;
        if (status && n.kind !== 'zone' && !st.statusFill) g += `<circle cx="${x + w - 8}" cy="${y + 8}" r="6" fill="${STATUS_COLOR[status]}" stroke="#fff" stroke-width="1.5"/>`;
        if (status && n.kind === 'zone') g += `<circle cx="${x + w - 14}" cy="${y + 14}" r="7" fill="${STATUS_COLOR[status]}" stroke="#fff" stroke-width="1.5"/>`;
        g += this.textSvg(n, label, st, n.kind === 'zone' ? 'topleft' : (st.labelPos || 'center'));
      }
      return g + '</g>';
    }

    trunc(s, maxW, fs) {
      const max = Math.max(3, Math.floor(maxW / (fs * 0.56)));
      return s.length > max ? s.slice(0, max - 1) + '…' : s;
    }

    /* --------------------------------------------------------- Kanten-Geometrie */
    portPoint(n, port) {
      const cx = n.x + n.w / 2, cy = n.y + n.h / 2;
      return { n: { x: cx, y: n.y }, s: { x: cx, y: n.y + n.h }, w: { x: n.x, y: cy }, e: { x: n.x + n.w, y: cy } }[port] || { x: cx, y: cy };
    }
    autoPort(n, toward) {
      const cx = n.x + n.w / 2, cy = n.y + n.h / 2;
      const dx = (toward.x - cx) / Math.max(1, n.w), dy = (toward.y - cy) / Math.max(1, n.h);
      if (Math.abs(dx) > Math.abs(dy)) return dx > 0 ? 'e' : 'w';
      return dy > 0 ? 's' : 'n';
    }
    perimeter(n, toward) {
      const cx = n.x + n.w / 2, cy = n.y + n.h / 2;
      const dx = toward.x - cx, dy = toward.y - cy;
      if (!dx && !dy) return { x: cx, y: cy };
      const pad = n.kind === 'icon' && n.bind ? 3 : 0;
      const hw = n.w / 2 + pad, hh = n.h / 2 + pad;
      if (n.shape === 'ellipse' && n.kind === 'shape') {
        const t = 1 / Math.sqrt((dx * dx) / (hw * hw) + (dy * dy) / (hh * hh));
        return { x: cx + dx * t, y: cy + dy * t };
      }
      const t = Math.min(hw / Math.abs(dx || 1e-9), hh / Math.abs(dy || 1e-9));
      return { x: cx + dx * t, y: cy + dy * t };
    }
    endRef(end) {
      if (end.node) {
        const n = this.node(end.node);
        if (n) return { n, port: end.port || null };
      }
      return { pt: { x: end.x ?? 0, y: end.y ?? 0 } };
    }
    center(r) { return r.n ? { x: r.n.x + r.n.w / 2, y: r.n.y + r.n.h / 2 } : r.pt; }

    /** Liefert Polylinienpunkte einer Kante (Kurven werden abgetastet). */
    edgePoints(e) {
      const st = Object.assign(defaultEdgeStyle(), e.style || {});
      const A = this.endRef(e.from), B = this.endRef(e.to);
      const wps = (e.points || []).map((p) => ({ x: p.x, y: p.y }));
      const firstT = wps[0] || this.center(B), lastT = wps[wps.length - 1] || this.center(A);
      const routing = st.routing;
      const endPt = (R, toward, ortho) => {
        if (!R.n) return { p: R.pt, port: null };
        if (R.port) return { p: this.portPoint(R.n, R.port), port: R.port };
        if (ortho) { const pp = this.autoPort(R.n, toward); return { p: this.portPoint(R.n, pp), port: pp }; }
        return { p: this.perimeter(R.n, toward), port: null };
      };
      const ortho = routing === 'orthogonal';
      const a = endPt(A, firstT, ortho || routing === 'curved'), b = endPt(B, lastT, ortho || routing === 'curved');
      if (routing === 'straight') return [a.p, ...wps, b.p];
      if (routing === 'curved') {
        const ctrl = [a.p, ...wps, b.p];
        if (ctrl.length === 2) {
          const d = Math.max(40, Math.hypot(b.p.x - a.p.x, b.p.y - a.p.y) * 0.4);
          const off = (port, p) => port === 'e' ? { x: p.x + d, y: p.y } : port === 'w' ? { x: p.x - d, y: p.y } : port === 'n' ? { x: p.x, y: p.y - d } : port === 's' ? { x: p.x, y: p.y + d } : p;
          return this.bezier(a.p, off(a.port, a.p), off(b.port, b.p), b.p);
        }
        // Catmull-Rom durch Wegpunkte
        const pts = [];
        for (let i = 0; i < ctrl.length - 1; i++) {
          const p0 = ctrl[i - 1] || ctrl[i], p1 = ctrl[i], p2 = ctrl[i + 1], p3 = ctrl[i + 2] || p2;
          const c1 = { x: p1.x + (p2.x - p0.x) / 6, y: p1.y + (p2.y - p0.y) / 6 };
          const c2 = { x: p2.x - (p3.x - p1.x) / 6, y: p2.y - (p3.y - p1.y) / 6 };
          const seg = this.bezier(p1, c1, c2, p2);
          if (i) seg.shift();
          pts.push(...seg);
        }
        return pts;
      }
      // Orthogonal
      const stub = 16;
      const out = (p, port) => port === 'e' ? { x: p.x + stub, y: p.y } : port === 'w' ? { x: p.x - stub, y: p.y } : port === 'n' ? { x: p.x, y: p.y - stub } : port === 's' ? { x: p.x, y: p.y + stub } : p;
      const aOut = out(a.p, a.port), bOut = out(b.p, b.port);
      const pts = [a.p, aOut];
      const bh = b.port === 'e' || b.port === 'w';
      let horiz = a.port === 'e' || a.port === 'w';
      const ctrl = [...wps, bOut];
      let prev = aOut;
      for (let i = 0; i < ctrl.length; i++) {
        const p = ctrl[i];
        const last = i === ctrl.length - 1;
        if (prev.x !== p.x && prev.y !== p.y) {
          if (last && wps.length === 0) {
            if (horiz && bh) { const mx = (prev.x + p.x) / 2; pts.push({ x: mx, y: prev.y }, { x: mx, y: p.y }); }
            else if (!horiz && !bh) { const my = (prev.y + p.y) / 2; pts.push({ x: prev.x, y: my }, { x: p.x, y: my }); }
            else pts.push(horiz ? { x: p.x, y: prev.y } : { x: prev.x, y: p.y });
          } else if (last) {
            pts.push(bh ? { x: prev.x, y: p.y } : { x: p.x, y: prev.y });
          } else {
            pts.push(horiz ? { x: p.x, y: prev.y } : { x: prev.x, y: p.y });
            horiz = !horiz;
          }
        }
        pts.push(p);
        prev = p;
      }
      pts.push(b.p);
      return this.simplify(pts);
    }
    bezier(p0, p1, p2, p3, n = 28) {
      const out = [];
      for (let i = 0; i <= n; i++) {
        const t = i / n, u = 1 - t;
        out.push({ x: u * u * u * p0.x + 3 * u * u * t * p1.x + 3 * u * t * t * p2.x + t * t * t * p3.x, y: u * u * u * p0.y + 3 * u * u * t * p1.y + 3 * u * t * t * p2.y + t * t * t * p3.y });
      }
      return out;
    }
    simplify(pts) {
      const out = [];
      for (const p of pts) {
        const l = out[out.length - 1];
        if (l && Math.abs(l.x - p.x) < 0.01 && Math.abs(l.y - p.y) < 0.01) continue;
        if (out.length >= 2) {
          const a = out[out.length - 2];
          if ((Math.abs(a.x - l.x) < 0.01 && Math.abs(l.x - p.x) < 0.01) || (Math.abs(a.y - l.y) < 0.01 && Math.abs(l.y - p.y) < 0.01)) { out[out.length - 1] = p; continue; }
        }
        out.push(p);
      }
      return out;
    }
    polyLen(pts) { let L = 0; for (let i = 1; i < pts.length; i++) L += Math.hypot(pts[i].x - pts[i - 1].x, pts[i].y - pts[i - 1].y); return L; }
    splitAt(pts, frac) {
      const L = this.polyLen(pts) * frac;
      let acc = 0;
      for (let i = 1; i < pts.length; i++) {
        const s = Math.hypot(pts[i].x - pts[i - 1].x, pts[i].y - pts[i - 1].y);
        if (acc + s >= L) {
          const t = s ? (L - acc) / s : 0;
          const m = { x: pts[i - 1].x + (pts[i].x - pts[i - 1].x) * t, y: pts[i - 1].y + (pts[i].y - pts[i - 1].y) * t };
          return [[...pts.slice(0, i), m], [m, ...pts.slice(i)], m];
        }
        acc += s;
      }
      const m = pts[pts.length - 1];
      return [pts, [m], m];
    }
    d(pts) { return pts.map((p, i) => (i ? 'L' : 'M') + p.x.toFixed(1) + ',' + p.y.toFixed(1)).join(' '); }

    arrow(p, from, color, w) {
      const ang = Math.atan2(p.y - from.y, p.x - from.x);
      const L = 8 + w * 2, W = 4 + w;
      const a = { x: p.x - L * Math.cos(ang) + W * Math.sin(ang), y: p.y - L * Math.sin(ang) - W * Math.cos(ang) };
      const b = { x: p.x - L * Math.cos(ang) - W * Math.sin(ang), y: p.y - L * Math.sin(ang) + W * Math.cos(ang) };
      return `<polygon points="${p.x},${p.y} ${a.x},${a.y} ${b.x},${b.y}" fill="${color}"/>`;
    }

    edgeColors(e, st) {
      if (e.bind) { const s = this.statusOf(e.bind); return [STATUS_COLOR[s], STATUS_COLOR[s]]; }
      if (st.autoStatus) {
        const na = e.from.node && this.node(e.from.node), nb = e.to.node && this.node(e.to.node);
        const sa = na && na.bind ? this.statusOf(na.bind) : null, sb = nb && nb.bind ? this.statusOf(nb.bind) : null;
        if (sa && sb) {
          // Wie bei PRTG: Linie zweifarbig, wenn die Enden unterschiedlichen Status haben
          return [STATUS_COLOR[sa === 'up' || sa === 'paused' ? sa : sa], STATUS_COLOR[sb]];
        }
      }
      return [st.stroke, st.stroke];
    }

    edgeSvg(e) {
      const st = Object.assign(defaultEdgeStyle(), e.style || {});
      const pts = this.edgePoints(e);
      if (pts.length < 2) return '';
      const [c1, c2] = this.edgeColors(e, st);
      const dash = st.dash ? ` stroke-dasharray="${st.width * 3} ${st.width * 2}"` : (st.animated ? ` stroke-dasharray="${st.width * 4} ${st.width * 3}"` : '');
      const anim = st.animated ? `<animate attributeName="stroke-dashoffset" from="${st.width * 14}" to="0" dur="1s" repeatCount="indefinite"/>` : '';
      let g = `<g data-id="${e.id}" class="me-edge${this.mode === 'view' && (e.bind || e.link) ? ' me-click' : ''}">`;
      g += `<path d="${this.d(pts)}" fill="none" stroke="transparent" stroke-width="${Math.max(12, st.width + 10)}" class="me-hit"/>`;
      if (c1 === c2) g += `<path d="${this.d(pts)}" fill="none" stroke="${esc(c1)}" stroke-width="${st.width}" stroke-linejoin="round"${dash}>${anim}</path>`;
      else {
        const [p1, p2] = this.splitAt(pts, 0.5);
        g += `<path d="${this.d(p1)}" fill="none" stroke="${esc(c1)}" stroke-width="${st.width}" stroke-linejoin="round"${dash}>${anim}</path>`;
        g += `<path d="${this.d(p2)}" fill="none" stroke="${esc(c2)}" stroke-width="${st.width}" stroke-linejoin="round"${dash}>${anim}</path>`;
      }
      if (st.arrowEnd) g += this.arrow(pts[pts.length - 1], pts[pts.length - 2], c2, st.width);
      if (st.arrowStart) g += this.arrow(pts[0], pts[1], c1, st.width);
      let label = e.label || '';
      if (e.bind) {
        const i = this.bindInfo(e.bind);
        label = label.replace(/\{value\}/g, i && i.value !== undefined ? fmtNum(i.value) + (i.unit ? ' ' + i.unit : '') : '').replace(/\{name\}/g, i ? i.name : '').replace(/\{status\}/g, i ? STATUS_TEXT[i.status] : '');
      }
      if (label) {
        let lg = '';
        const [, , m] = this.splitAt(pts, 0.5);
        const fs = st.fontSize || 11;
        const lines = label.split('\n');
        const wpx = Math.max(...lines.map((l) => l.length)) * fs * 0.58 + 10, hpx = lines.length * fs * 1.25 + 6;
        lg += `<rect x="${m.x - wpx / 2}" y="${m.y - hpx / 2}" width="${wpx}" height="${hpx}" rx="4" fill="${esc(this.data.settings.background || '#fff')}" stroke="${esc(c1)}" stroke-width="0.8"/>`;
        lg += `<text x="${m.x}" y="${m.y - hpx / 2 + fs + 2}" text-anchor="middle" font-family="${FONT}" font-size="${fs}" fill="#1f2937">${lines.map((l, i) => `<tspan x="${m.x}" dy="${i ? fs * 1.25 : 0}">${esc(l)}</tspan>`).join('')}</text>`;
        (this._labels || (this._labels = [])).push(`<g data-id="${e.id}" class="me-edge">${lg}</g>`);
      }
      return g + '</g>';
    }

    /* ----------------------------------------------------------- Overlay */
    renderOverlay() {
      if (this.mode !== 'edit') { this.overlay.innerHTML = this.guides.length ? '' : ''; return; }
      const k = this.view.k;
      const hs = 7 / k;
      let o = '';
      const nodes = this.selNodes();
      for (const n of nodes) {
        o += `<rect x="${n.x}" y="${n.y}" width="${n.w}" height="${n.h}" fill="none" stroke="#2563eb" stroke-width="${1.2 / k}" stroke-dasharray="${4 / k} ${3 / k}" pointer-events="none"/>`;
      }
      if (nodes.length === 1 && this.selEdges().length === 0) {
        const n = nodes[0];
        const hx = { nw: [n.x, n.y], n: [n.x + n.w / 2, n.y], ne: [n.x + n.w, n.y], e: [n.x + n.w, n.y + n.h / 2], se: [n.x + n.w, n.y + n.h], s: [n.x + n.w / 2, n.y + n.h], sw: [n.x, n.y + n.h], w: [n.x, n.y + n.h / 2] };
        for (const [k2, [x, y]] of Object.entries(hx)) {
          o += `<rect data-handle="${k2}" class="me-h me-h-${k2}" x="${x - hs / 2}" y="${y - hs / 2}" width="${hs}" height="${hs}" fill="#fff" stroke="#2563eb" stroke-width="${1.4 / k}"/>`;
        }
      }
      for (const e of this.selEdges()) {
        const pts = this.edgePoints(e);
        o += `<path d="${this.d(pts)}" fill="none" stroke="#2563eb" stroke-width="${1 / k}" stroke-dasharray="${4 / k} ${3 / k}" pointer-events="none"/>`;
        const r = 5.5 / k;
        o += `<circle data-ehandle="from" data-eid="${e.id}" cx="${pts[0].x}" cy="${pts[0].y}" r="${r}" fill="#2563eb" stroke="#fff" stroke-width="${1.5 / k}" class="me-eh"/>`;
        o += `<circle data-ehandle="to" data-eid="${e.id}" cx="${pts[pts.length - 1].x}" cy="${pts[pts.length - 1].y}" r="${r}" fill="#2563eb" stroke="#fff" stroke-width="${1.5 / k}" class="me-eh"/>`;
        (e.points || []).forEach((p, i) => {
          o += `<rect data-wp="${i}" data-eid="${e.id}" x="${p.x - r}" y="${p.y - r}" width="${2 * r}" height="${2 * r}" fill="#f59e0b" stroke="#fff" stroke-width="${1.5 / k}" class="me-wp" transform="rotate(45 ${p.x} ${p.y})"/>`;
        });
        // virtuelle Wegpunkte zwischen Segmenten (Ziehen erzeugt neuen Wegpunkt)
        const ctrl = [pts[0], ...(e.points || []), pts[pts.length - 1]];
        for (let i = 0; i < ctrl.length - 1; i++) {
          const mx = (ctrl[i].x + ctrl[i + 1].x) / 2, my = (ctrl[i].y + ctrl[i + 1].y) / 2;
          o += `<circle data-vwp="${i}" data-eid="${e.id}" cx="${mx}" cy="${my}" r="${r * 0.8}" fill="#fff" stroke="#f59e0b" stroke-width="${1.5 / k}" class="me-wp"/>`;
        }
      }
      // Verbindungspunkte beim Hover (wie draw.io)
      const hn = this.hover && this.node(this.hover);
      if (hn && (!this.drag || this.drag.type === 'connect' || this.drag.type === 'ehandle')) {
        for (const p of ['n', 'e', 's', 'w']) {
          const pt = this.portPoint(hn, p);
          const active = this.drag && this.drag.hoverPort === p && this.drag.target === hn.id;
          o += `<g data-port="${p}" data-pnode="${hn.id}" class="me-port"><circle cx="${pt.x}" cy="${pt.y}" r="${9 / k}" fill="transparent"/><path d="M${pt.x - 4 / k},${pt.y - 4 / k}L${pt.x + 4 / k},${pt.y + 4 / k}M${pt.x + 4 / k},${pt.y - 4 / k}L${pt.x - 4 / k},${pt.y + 4 / k}" stroke="${active ? '#16a34a' : '#2563eb'}" stroke-width="${(active ? 2.6 : 1.8) / k}"/></g>`;
        }
        if (this.drag && this.drag.target === hn.id && !this.drag.hoverPort) {
          o += `<rect x="${hn.x - 3 / k}" y="${hn.y - 3 / k}" width="${hn.w + 6 / k}" height="${hn.h + 6 / k}" fill="none" stroke="#16a34a" stroke-width="${2 / k}" pointer-events="none"/>`;
        }
      }
      if (this.drag && (this.drag.type === 'connect' || this.drag.type === 'ehandle') && this.drag.preview) {
        o += `<path d="${this.d(this.drag.preview)}" fill="none" stroke="#2563eb" stroke-width="${1.6 / k}" stroke-dasharray="${5 / k} ${4 / k}" pointer-events="none"/>`;
      }
      if (this.drag && this.drag.type === 'rubber') {
        const r = this.drag.rect;
        o += `<rect x="${r.x}" y="${r.y}" width="${r.w}" height="${r.h}" fill="rgba(37,99,235,.08)" stroke="#2563eb" stroke-width="${1 / k}" pointer-events="none"/>`;
      }
      for (const gl of this.guides) {
        o += gl.v ? `<line x1="${gl.v}" y1="-20000" x2="${gl.v}" y2="20000" stroke="#ec4899" stroke-width="${1 / k}" pointer-events="none"/>`
          : `<line x1="-20000" y1="${gl.h}" x2="20000" y2="${gl.h}" stroke="#ec4899" stroke-width="${1 / k}" pointer-events="none"/>`;
      }
      this.overlay.innerHTML = o;
    }

    /* ======================================================== Eigenschaften */
    renderProps() {
      if (this.drag && this.drag.type !== 'none') return; // nicht während Ziehen neu aufbauen
      const nodes = this.selNodes(), edges = this.selEdges();
      const f = (label, html) => `<label class="me-f"><span>${label}</span>${html}</label>`;
      const color = (key, val, obj = 'style') => `<input type="color" data-p="${obj}.${key}" value="${/^#[0-9a-f]{6}$/i.test(val || '') ? val : '#000000'}">`;
      const num = (key, val, step = 1, obj = 'style') => `<input type="number" data-p="${obj}.${key}" value="${val ?? ''}" step="${step}">`;
      const chk = (key, val, obj = 'style') => `<input type="checkbox" data-p="${obj}.${key}"${val ? ' checked' : ''}>`;
      const sel = (key, val, opts, obj = 'style') => `<select data-p="${obj}.${key}">${opts.map(([v, l]) => `<option value="${v}"${String(val) === String(v) ? ' selected' : ''}>${l}</option>`).join('')}</select>`;
      const bindSel = (b) => {
        const opts = ['<option value="">— keine —</option>'];
        for (const t of ['group', 'device', 'sensor']) {
          const lab = { group: 'Gruppe', device: 'Gerät', sensor: 'Sensor' }[t];
          const entries = Object.entries(this.status[t] || {});
          if (!entries.length) continue;
          opts.push(`<optgroup label="${lab}">` + entries.map(([id, i]) => `<option value="${t}:${id}"${b && b.type === t && String(b.id) === id ? ' selected' : ''}>${esc((i.device ? i.device + ' › ' : '') + i.name)}</option>`).join('') + '</optgroup>');
        }
        return `<select data-p="bind">${opts.join('')}</select>`;
      };
      let h = '';
      if (nodes.length === 1 && edges.length === 0) {
        const n = nodes[0];
        const st = Object.assign(defaultStyle(n.kind), n.style || {});
        h += `<h3>${{ shape: 'Form', zone: 'Zone', text: 'Text', icon: 'Icon', status: 'Status-Kachel', image: 'Bild', link: 'Link' }[n.kind] || 'Element'}</h3>`;
        h += f('Beschriftung', `<textarea data-p="label" rows="2">${esc(n.label || '')}</textarea>`);
        if (n.bind) h += '<p class="me-hint">Platzhalter: {name} {value} {status} {message} {host}</p>';
        h += `<div class="me-grid4">${f('X', `<input type="number" data-p="x" value="${Math.round(n.x)}">`)}${f('Y', `<input type="number" data-p="y" value="${Math.round(n.y)}">`)}${f('B', `<input type="number" data-p="w" value="${Math.round(n.w)}" min="8">`)}${f('H', `<input type="number" data-p="h" value="${Math.round(n.h)}" min="8">`)}</div>`;
        h += '<h4>Live-Status</h4>';
        h += f('Verknüpftes Objekt', bindSel(n.bind));
        if (n.bind) {
          const i = this.bindInfo(n.bind);
          h += `<p class="me-st"><i style="background:${STATUS_COLOR[i.status]}"></i>${STATUS_TEXT[i.status]}${i.message ? ' – ' + esc(i.message) : ''}</p>`;
          h += f('Füllung nach Status', chk('statusFill', st.statusFill));
          if (n.kind === 'status' && n.bind.type === 'sensor' && i.channels && Object.keys(i.channels).length) {
            h += f('Angezeigter Wert', `<select data-p="node.channel"><option value="">Hauptwert</option>${Object.keys(i.channels).map((c) => `<option value="${esc(c)}"${n.channel === c ? ' selected' : ''}>${esc(c)}</option>`).join('')}</select>`);
          }
        }
        h += '<h4>Darstellung</h4>';
        if (n.kind === 'shape') h += f('Form', sel('shape', n.shape, [['rect', 'Rechteck'], ['rounded', 'Abgerundet'], ['ellipse', 'Ellipse'], ['diamond', 'Raute'], ['hexagon', 'Sechseck'], ['cylinder', 'Zylinder'], ['cloud', 'Wolke']], 'node'));
        if (n.kind === 'icon') {
          h += f('Icon', sel('icon', n.icon, Object.entries(RM_ICONS.all()).map(([k, v]) => [k, v.label]), 'node'));
          h += `<div class="me-grid2">${f('Icon-Farbe', color('iconColor', st.iconColor))}${f('Hintergrund', `<span class="me-row">${color('fill', st.fill === 'none' ? '#ffffff' : st.fill)}<button data-clear="style.fill" title="Kein Hintergrund">⌀</button></span>`)}</div>`;
        }
        if (n.kind === 'image') h += f('Bild-URL', `<input data-p="src" value="${esc(n.src || '')}" placeholder="https://…/plan.png">`);
        if (['shape', 'zone', 'status', 'link'].includes(n.kind)) {
          h += `<div class="me-grid2">${f('Füllung', `<span class="me-row">${color('fill', st.fill)}<button data-clear="style.fill" title="Keine Füllung">⌀</button></span>`)}${f('Linie', `<span class="me-row">${color('stroke', st.stroke)}<button data-clear="style.stroke" title="Keine Linie">⌀</button></span>`)}</div>`;
          h += `<div class="me-grid2">${f('Linienstärke', num('strokeWidth', st.strokeWidth, 0.5))}${f('Gestrichelt', chk('dash', st.dash))}</div>`;
          if (n.kind !== 'status') h += f('Deckkraft Füllung', `<input type="range" min="0" max="1" step="0.05" data-p="style.fillOpacity" value="${st.fillOpacity ?? 1}">`);
        }
        h += `<div class="me-grid2">${f('Schriftgröße', num('fontSize', st.fontSize))}${f('Schriftfarbe', color('fontColor', st.fontColor))}</div>`;
        h += `<div class="me-grid2">${f('Fett', chk('bold', st.bold))}${n.kind !== 'zone' && n.kind !== 'status' ? f('Text-Position', sel('labelPos', st.labelPos, [['center', 'Mitte'], ['bottom', 'Unten'], ['top', 'Oben']])) : ''}</div>`;
        h += '<h4>Interaktion</h4>';
        h += f('Link beim Klick (Ansicht)', `<input data-p="link" value="${esc(n.link || '')}" placeholder="https://… (optional)">`);
      } else if (edges.length === 1 && nodes.length === 0) {
        const e = edges[0];
        const st = Object.assign(defaultEdgeStyle(), e.style || {});
        h += '<h3>Verbindung</h3>';
        h += f('Beschriftung', `<textarea data-p="label" rows="2" placeholder="z. B. 1 Gbit/s oder {value}">${esc(e.label || '')}</textarea>`);
        h += f('Verlauf', sel('routing', st.routing, [['orthogonal', 'Orthogonal (Winkel)'], ['straight', 'Gerade'], ['curved', 'Kurve']]));
        h += `<div class="me-grid2">${f('Farbe', color('stroke', st.stroke))}${f('Stärke', num('width', st.width, 0.5))}</div>`;
        h += `<div class="me-grid2">${f('Gestrichelt', chk('dash', st.dash))}${f('Animiert (Fluss)', chk('animated', st.animated))}</div>`;
        h += `<div class="me-grid2">${f('Pfeil Anfang', chk('arrowStart', st.arrowStart))}${f('Pfeil Ende', chk('arrowEnd', st.arrowEnd))}</div>`;
        h += '<h4>Live-Status</h4>';
        h += f('Farbe nach Endpunkten', chk('autoStatus', st.autoStatus));
        h += '<p class="me-hint">Sind beide Enden mit Objekten verknüpft, färbt sich die Linie – bei unterschiedlichem Status zweifarbig.</p>';
        h += f('Mit Sensor verknüpfen (z. B. Uplink)', bindSel(e.bind));
        h += `<p class="me-hint">Wegpunkte: orange Raute ziehen · weißen Punkt ziehen = neuer Wegpunkt · Doppelklick auf Raute = entfernen.</p>`;
        h += `<button data-cmd="clear-waypoints" class="me-wide">Wegpunkte zurücksetzen</button>`;
      } else if (nodes.length + edges.length > 1) {
        h += `<h3>${nodes.length + edges.length} Elemente</h3>`;
        h += '<p class="me-hint">Ausrichten & Verteilen über die Werkzeugleiste. Gemeinsame Farbe:</p>';
        h += `<div class="me-grid2">${f('Füllung', '<input type="color" data-multi="fill" value="#e8f1fb">')}${f('Linie', '<input type="color" data-multi="stroke" value="#1e5fa8">')}</div>`;
        h += f('Schriftgröße', '<input type="number" data-multi="fontSize" value="13">');
      } else {
        const s = this.data.settings;
        h += '<h3>Map</h3>';
        h += f('Hintergrund', `<input type="color" data-p="settings.background" value="${s.background || '#ffffff'}">`);
        h += `<div class="me-grid2">${f('Raster', `<input type="checkbox" data-p="settings.grid"${s.grid ? ' checked' : ''}>`)}${f('Einrasten', `<input type="checkbox" data-p="settings.snap"${s.snap ? ' checked' : ''}>`)}</div>`;
        h += `<div class="me-grid2">${f('Rastergröße', `<input type="number" data-p="settings.gridSize" value="${s.gridSize}" min="2" max="100">`)}${f('Hilfslinien', `<input type="checkbox" data-p="settings.guides"${s.guides ? ' checked' : ''}>`)}</div>`;
        h += f('Aktualisierung Live-Ansicht (Sek.)', `<input type="number" data-p="refresh" value="${this.refresh}" min="5" max="3600">`);
        const bg = s.bg;
        h += '<h4>Hintergrundbild</h4>';
        if (!bg || !bg.src) {
          h += '<p class="me-hint">Grundriss, Netzplan, Lageplan oder Logo (PNG, JPG, SVG, WebP). Große Bilder werden automatisch verkleinert.</p>';
          h += '<label class="me-wide me-filebtn">🖼️ Bild auswählen …<input type="file" accept="image/*" data-bgfile hidden></label>';
        } else {
          h += `<img class="me-bgthumb" src="${esc(bg.src)}" alt="">`;
          h += f('Deckkraft', `<input type="range" min="0.05" max="1" step="0.05" data-p="bg.opacity" value="${bg.opacity ?? 1}">`);
          h += `<div class="me-grid4">${f('X', `<input type="number" data-p="bg.x" value="${Math.round(bg.x || 0)}">`)}${f('Y', `<input type="number" data-p="bg.y" value="${Math.round(bg.y || 0)}">`)}${f('B', `<input type="number" data-p="bg.w" value="${Math.round(bg.w)}" min="10">`)}${f('H', `<input type="number" data-p="bg.h" value="${Math.round(bg.h)}" min="10">`)}</div>`;
          h += `<div class="me-grid2"><button data-cmd="bg-smaller" class="me-wide">− 10 %</button><button data-cmd="bg-bigger" class="me-wide">+ 10 %</button></div>`;
          h += `<button data-cmd="bg-move" class="me-wide${this.bgMove ? ' on' : ''}">${this.bgMove ? '✔ Verschieben aktiv – auf leere Fläche ziehen' : '✋ Hintergrund verschieben'}</button>`;
          h += '<button data-cmd="bg-ratio" class="me-wide">Seitenverhältnis wiederherstellen</button>';
          h += '<label class="me-wide me-filebtn">Anderes Bild …<input type="file" accept="image/*" data-bgfile hidden></label>';
          h += '<button data-cmd="bg-remove" class="me-wide danger">Hintergrundbild entfernen</button>';
        }
        if (this.opts.renderShare) h += this.opts.renderShare();
        h += `<h4>Tastatur</h4><ul class="me-keys">
          <li><kbd>Entf</kbd> löschen · <kbd>Strg</kbd>+<kbd>D</kbd> duplizieren</li>
          <li><kbd>Strg</kbd>+<kbd>C</kbd>/<kbd>V</kbd> kopieren/einfügen</li>
          <li><kbd>Strg</kbd>+<kbd>Z</kbd>/<kbd>Y</kbd> rückgängig/wiederholen</li>
          <li><kbd>Pfeile</kbd> verschieben (<kbd>Shift</kbd> = 10)</li>
          <li><kbd>Strg</kbd>+Mausrad zoomen · Leertaste+Ziehen verschieben</li>
          <li>Doppelklick = Text bearbeiten · Rechtsklick = Menü</li>
          <li>Blaue × am Element ziehen = Verbindung</li></ul>`;
      }
      this.props.innerHTML = h;
    }

    applyProp(path, value, live) {
      const nodes = this.selNodes(), edges = this.selEdges();
      if (!live) this.pushUndo();
      const set = (obj, key, v) => { obj[key] = v; };
      const [a, b] = path.split('.');
      if (a === 'settings') { this.data.settings[b] = value; if (b === 'gridSize') this.updateGridPattern(); }
      else if (a === 'bg') { if (this.data.settings.bg) this.data.settings.bg[b] = b === 'opacity' ? value : Math.max(b === 'w' || b === 'h' ? 10 : -1e6, parseFloat(value) || 0); }
      else if (a === 'refresh') this.refresh = clamp(parseInt(value, 10) || 30, 5, 3600);
      else for (const o of [...nodes, ...edges]) {
        if (a === 'style') { o.style = o.style || {}; set(o.style, b, value); }
        else if (a === 'node') set(o, b, value);
        else if (a === 'bind') {
          if (!value) delete o.bind;
          else {
            const [t, id] = value.split(':');
            o.bind = { type: t, id: parseInt(id, 10) };
            const info = this.status[t] && this.status[t][id];
            // Element übernimmt sofort den Namen des verknüpften Objekts (bei Status-Kacheln kommt er automatisch)
            if (info && o.kind && o.kind !== 'status') o.label = info.name;
            if (info && o.kind === 'status') o.label = '';
            // Gerät verknüpft → passendes Icon übernehmen
            if (info && t === 'device' && o.kind === 'icon' && this.opts.deviceIcon) { const ic = this.opts.deviceIcon(parseInt(id, 10)); if (ic) o.icon = ic; }
          }
        } else if (['x', 'y', 'w', 'h'].includes(a)) o[a] = Math.max(a === 'w' || a === 'h' ? 8 : -1e6, parseFloat(value) || 0);
        else set(o, a, value);
      }
      this.markDirty();
      if (live) { const p = this.props; this.props = document.createElement('div'); this.render(); this.props = p; }
      else this.render();
    }

    updateGridPattern() {
      const g = clamp(parseInt(this.data.settings.gridSize, 10) || 10, 2, 100);
      this.data.settings.gridSize = g;
      const s = this.svg.querySelector('#me-grid-s'), l = this.svg.querySelector('#me-grid-l');
      s.setAttribute('width', g); s.setAttribute('height', g); s.querySelector('path').setAttribute('d', `M${g} 0H0V${g}`);
      l.setAttribute('width', g * 5); l.setAttribute('height', g * 5);
      l.querySelector('rect').setAttribute('width', g * 5); l.querySelector('rect').setAttribute('height', g * 5);
      l.querySelector('path').setAttribute('d', `M${g * 5} 0H0V${g * 5}`);
    }

    /* ============================================================== Events */
    bind() {
      const R = this.root;
      R.addEventListener('click', (ev) => {
        const b = ev.target.closest('[data-cmd]');
        if (b && R.contains(b)) { this.cmd(b.dataset.cmd, b); return; }
        const t = ev.target.closest('.me-tabs [data-tab]');
        if (t) {
          R.querySelectorAll('.me-tabs [data-tab]').forEach((x) => x.classList.toggle('on', x === t));
          R.querySelectorAll('.me-pane').forEach((p) => { p.hidden = p.dataset.pane !== t.dataset.tab; });
        }
        const pal = ev.target.closest('.me-pal');
        if (pal) { const c = this.viewCenter(); this.addFromPalette(pal.dataset.group, +pal.dataset.i, c.x, c.y); }
        const clr = ev.target.closest('[data-clear]');
        if (clr) this.applyProp(clr.dataset.clear, 'none');
        if (!ev.target.closest('.me-menu-wrap')) R.querySelectorAll('.me-menu').forEach((m) => { m.hidden = true; });
        if (!ev.target.closest('.me-ctx')) this.ctx.hidden = true;
      });
      // Eigenschaften
      this.props.parentElement.addEventListener('input', (ev) => {
        const el = ev.target;
        if (el.dataset.multi) { this.multiStyle(el.dataset.multi, el.type === 'number' ? parseFloat(el.value) : el.value); return; }
        if (!el.dataset.p || el.type === 'checkbox' || el.tagName === 'SELECT') return;
        const v = el.type === 'number' || el.type === 'range' ? parseFloat(el.value) : el.value;
        if (!this._liveUndo) { this.pushUndo(); this._liveUndo = true; }
        this.applyProp(el.dataset.p, v, true);
      });
      this.props.parentElement.addEventListener('change', (ev) => {
        const el = ev.target;
        if (el.hasAttribute && el.hasAttribute('data-bgfile')) { this.loadBackground(el.files[0]); return; }
        this._liveUndo = false;
        if (!el.dataset.p) return;
        if (el.type === 'checkbox' || el.tagName === 'SELECT') this.applyProp(el.dataset.p, el.type === 'checkbox' ? el.checked : el.value);
        else this.render();
      });
      const nameIn = R.querySelector('.me-name');
      if (nameIn) nameIn.addEventListener('input', () => { this.name = nameIn.value; this.markDirty(); });
      // Palette & Objektbaum: Drag & Drop
      R.addEventListener('dragstart', (ev) => {
        const p = ev.target.closest('.me-pal, .me-obj');
        if (!p) return;
        const payload = p.classList.contains('me-pal') ? { pal: p.dataset.group, i: +p.dataset.i } : { bt: p.dataset.bt, bid: +p.dataset.bid, icon: p.dataset.icon, name: p.dataset.name };
        ev.dataTransfer.setData('application/x-rm', JSON.stringify(payload));
        ev.dataTransfer.effectAllowed = 'copy';
      });
      this.wrap.addEventListener('dragover', (ev) => { ev.preventDefault(); ev.dataTransfer.dropEffect = 'copy'; });
      this.wrap.addEventListener('drop', (ev) => {
        ev.preventDefault();
        let p;
        try { p = JSON.parse(ev.dataTransfer.getData('application/x-rm')); } catch (e) { return; }
        const w = this.toWorld(ev.clientX, ev.clientY);
        if (p.pal) this.addFromPalette(p.pal, p.i, w.x, w.y);
        else this.addBound(p, w.x, w.y);
      });
      const search = R.querySelector('.me-search');
      if (search) search.addEventListener('input', () => {
        const q = search.value.trim().toLowerCase();
        R.querySelectorAll('.me-objdev').forEach((d) => {
          const hitDev = d.dataset.search.includes(q);
          let any = false;
          d.querySelectorAll('.me-objsens .me-obj').forEach((s) => { const hit = !q || hitDev || s.dataset.search.includes(q); s.hidden = !hit; any = any || hit; });
          d.hidden = q && !hitDev && !any;
        });
      });

      // Canvas
      this.svg.addEventListener('pointerdown', (ev) => this.onDown(ev));
      this.svg.addEventListener('pointermove', (ev) => this.onMove(ev));
      this.svg.addEventListener('pointerup', (ev) => this.onUp(ev));
      this.svg.addEventListener('pointercancel', (ev) => this.onUp(ev));
      this.svg.addEventListener('pointerleave', () => { this.tip.hidden = true; });
      this.svg.addEventListener('wheel', (ev) => this.onWheel(ev), { passive: false });
      this.svg.addEventListener('contextmenu', (ev) => this.onContext(ev));
      this._key = (ev) => this.onKey(ev);
      this._keyup = (ev) => { if (ev.code === 'Space') { this.space = false; this.svg.classList.remove('me-grab'); } };
      window.addEventListener('keydown', this._key);
      window.addEventListener('keyup', this._keyup);
      this._resize = () => this.render();
      window.addEventListener('resize', this._resize);
      this.inline.addEventListener('keydown', (ev) => {
        if (ev.key === 'Escape') { this.inline.hidden = true; ev.stopPropagation(); }
        if (ev.key === 'Enter' && !ev.shiftKey) { ev.preventDefault(); this.commitInline(); }
      });
      this.inline.addEventListener('blur', () => { if (Date.now() - (this._inlineAt || 0) > 200) this.commitInline(); });
      R.querySelector('.me-file').addEventListener('change', (ev) => this.importJson(ev.target.files[0]));
      this.ctx.addEventListener('click', (ev) => {
        const b = ev.target.closest('[data-ctx]');
        if (!b) return;
        this.ctx.hidden = true;
        this.cmd(b.dataset.ctx);
      });
    }

    destroy() {
      window.removeEventListener('keydown', this._key);
      window.removeEventListener('keyup', this._keyup);
      window.removeEventListener('resize', this._resize);
      clearInterval(this.poll);
      this.destroyed = true;
      this.root.innerHTML = '';
    }

    viewCenter() {
      const r = this.svg.getBoundingClientRect();
      return this.toWorld(r.left + r.width / 2, r.top + r.height / 2);
    }

    hitFrom(ev) {
      const t = ev.target;
      const h = t.closest && t.closest('[data-handle]');
      if (h) return { handle: h.dataset.handle };
      const eh = t.closest && t.closest('[data-ehandle]');
      if (eh) return { ehandle: eh.dataset.ehandle, eid: eh.dataset.eid };
      const wp = t.closest && t.closest('[data-wp]');
      if (wp) return { wp: +wp.dataset.wp, eid: wp.dataset.eid };
      const vwp = t.closest && t.closest('[data-vwp]');
      if (vwp) return { vwp: +vwp.dataset.vwp, eid: vwp.dataset.eid };
      const port = t.closest && t.closest('[data-port]');
      if (port) return { port: port.dataset.port, pnode: port.dataset.pnode };
      const el = t.closest && t.closest('[data-id]');
      if (el) return { id: el.dataset.id };
      return {};
    }

    onDown(ev) {
      this.svg.focus({ preventScroll: true });
      this.ctx.hidden = true;
      if (!this.inline.hidden && Date.now() - (this._inlineAt || 0) > 200) this.commitInline();
      const w = this.toWorld(ev.clientX, ev.clientY);
      // Pan: mittlere Maustaste, Leertaste oder Ansicht-Modus auf leerer Fläche
      const hit = this.hitFrom(ev);
      if (ev.button === 1 || this.space || (this.mode === 'view' && !hit.id) || (ev.button === 0 && ev.pointerType === 'touch' && !hit.id && this.mode === 'view')) {
        this.drag = { type: 'pan', sx: ev.clientX, sy: ev.clientY, tx: this.view.tx, ty: this.view.ty };
        this.svg.setPointerCapture(ev.pointerId);
        this.svg.classList.add('me-grabbing');
        return;
      }
      if (ev.button !== 0) return;
      if (this.mode === 'view') {
        if (hit.id) this.openObject(hit.id);
        return;
      }
      // Doppelklick-Erkennung
      const now = Date.now();
      const key = hit.id || hit.wp !== undefined && 'wp' + hit.eid + hit.wp || 'canvas';
      const dbl = now - this.lastDown.t < 380 && this.lastDown.id === key;
      this.lastDown = { t: now, id: key };
      if (dbl) { this.onDouble(hit, w); return; }

      this.svg.setPointerCapture(ev.pointerId);
      if (hit.handle) {
        const n = this.selNodes()[0];
        this.pushUndo();
        this.drag = { type: 'resize', h: hit.handle, n, orig: { x: n.x, y: n.y, w: n.w, h: n.h }, start: w };
        return;
      }
      if (hit.port) {
        this.drag = { type: 'connect', from: { node: hit.pnode, port: hit.port }, start: w, preview: null, target: null };
        return;
      }
      if (hit.ehandle) {
        this.pushUndo();
        this.drag = { type: 'ehandle', which: hit.ehandle, e: this.edge(hit.eid), preview: null };
        return;
      }
      if (hit.wp !== undefined) {
        this.pushUndo();
        this.drag = { type: 'wp', e: this.edge(hit.eid), i: hit.wp };
        return;
      }
      if (hit.vwp !== undefined) {
        this.pushUndo();
        const e = this.edge(hit.eid);
        e.points = e.points || [];
        e.points.splice(hit.vwp, 0, { x: this.snap(w.x), y: this.snap(w.y) });
        this.drag = { type: 'wp', e, i: hit.vwp };
        this.render();
        return;
      }
      if (hit.id) {
        const isEdge = !!this.edge(hit.id);
        if (ev.shiftKey || ev.ctrlKey || ev.metaKey) {
          if (this.sel.has(hit.id)) this.sel.delete(hit.id); else this.sel.add(hit.id);
        } else if (!this.sel.has(hit.id)) {
          this.sel.clear(); this.sel.add(hit.id);
        }
        if (!isEdge) {
          // Kanten, deren beide Enden mitbewegt werden, verschieben ihre Wegpunkte mit
          const moving = this.selNodes();
          const ids = new Set(moving.map((n) => n.id));
          // Zonen nehmen enthaltene Elemente mit
          for (const z of moving.filter((n) => n.kind === 'zone')) {
            for (const n of this.data.nodes) if (!ids.has(n.id) && n.x >= z.x && n.y >= z.y && n.x + n.w <= z.x + z.w && n.y + n.h <= z.y + z.h) { ids.add(n.id); moving.push(n); }
          }
          const edges = this.data.edges.filter((e) => ids.has(e.from.node) && ids.has(e.to.node) && e.points && e.points.length);
          this.drag = {
            type: 'move', start: w, moved: false,
            items: moving.map((n) => ({ n, x: n.x, y: n.y })),
            wps: edges.map((e) => ({ e, pts: clone(e.points) })),
          };
        } else {
          this.drag = { type: 'none' };
        }
        this.render();
        return;
      }
      // Hintergrund verschieben
      if (this.bgMove && this.data.settings.bg) {
        this.pushUndo();
        this.drag = { type: 'bg', start: w, x: this.data.settings.bg.x || 0, y: this.data.settings.bg.y || 0 };
        return;
      }
      // leere Fläche: Auswahlrahmen
      if (!ev.shiftKey) this.sel.clear();
      this.drag = { type: 'rubber', start: w, rect: { x: w.x, y: w.y, w: 0, h: 0 }, base: new Set(this.sel) };
      this.render();
    }

    onMove(ev) {
      const w = this.toWorld(ev.clientX, ev.clientY);
      const d = this.drag;
      if (!d) {
        if (this.mode === 'edit') {
          const n = this.nodeAt(w, 10 / this.view.k);
          const hid = n ? n.id : null;
          if (hid !== this.hover) { this.hover = hid; this.renderOverlay(); }
        } else this.showTip(ev);
        return;
      }
      if (d.type === 'pan') {
        this.view.tx = d.tx + (ev.clientX - d.sx);
        this.view.ty = d.ty + (ev.clientY - d.sy);
        this.vp.setAttribute('transform', `translate(${this.view.tx},${this.view.ty}) scale(${this.view.k})`);
        return;
      }
      if (d.type === 'move') {
        let dx = w.x - d.start.x, dy = w.y - d.start.y;
        if (!d.moved && Math.hypot(dx, dy) * this.view.k < 3) return;
        if (!d.moved) { this.pushUndo(); d.moved = true; }
        // Einrasten am Raster bezogen auf erstes Element + Hilfslinien
        const f = d.items[0];
        let nx = this.snap(f.x + dx), ny = this.snap(f.y + dy);
        this.guides = [];
        if (this.data.settings.guides && !ev.altKey) {
          const g = this.smartGuides(d.items.map((i) => i.n), f, nx, ny);
          nx = g.x; ny = g.y; this.guides = g.lines;
        }
        dx = nx - f.x; dy = ny - f.y;
        for (const it of d.items) { it.n.x = it.x + dx; it.n.y = it.y + dy; }
        for (const wp of d.wps) wp.e.points = wp.pts.map((p) => ({ x: p.x + dx, y: p.y + dy }));
        this.markDirty(); this.renderFast();
        return;
      }
      if (d.type === 'resize') {
        const o = d.orig, n = d.n;
        let x1 = o.x, y1 = o.y, x2 = o.x + o.w, y2 = o.y + o.h;
        const sx = this.snap(w.x), sy = this.snap(w.y);
        if (d.h.includes('w')) x1 = Math.min(sx, x2 - 10);
        if (d.h.includes('e')) x2 = Math.max(sx, x1 + 10);
        if (d.h.includes('n')) y1 = Math.min(sy, y2 - 10);
        if (d.h.includes('s')) y2 = Math.max(sy, y1 + 10);
        if (ev.shiftKey && o.w && o.h) { // Seitenverhältnis
          const ratio = o.w / o.h;
          if (d.h.length === 2) { const nw = x2 - x1; const nh = nw / ratio; if (d.h.includes('n')) y1 = y2 - nh; else y2 = y1 + nh; }
        }
        n.x = x1; n.y = y1; n.w = x2 - x1; n.h = y2 - y1;
        this.markDirty(); this.renderFast();
        return;
      }
      if (d.type === 'connect' || d.type === 'ehandle') {
        const tgt = this.nodeAt(w, 12 / this.view.k);
        const fixedEnd = d.type === 'connect' ? d.from : (d.which === 'from' ? d.e.to : d.e.from);
        const excludeId = fixedEnd.node;
        d.target = tgt && tgt.id !== excludeId ? tgt.id : null;
        d.hoverPort = null;
        let endP = w;
        if (d.target) {
          for (const p of ['n', 'e', 's', 'w']) {
            const pp = this.portPoint(tgt, p);
            if (Math.hypot(pp.x - w.x, pp.y - w.y) < 12 / this.view.k) { d.hoverPort = p; endP = pp; }
          }
          if (!d.hoverPort) endP = { x: tgt.x + tgt.w / 2, y: tgt.y + tgt.h / 2 };
        }
        this.hover = d.target || (d.type === 'connect' ? d.from.node : null);
        const fr = this.endRef(fixedEnd);
        const fp = fr.n ? (fr.port ? this.portPoint(fr.n, fr.port) : this.perimeter(fr.n, endP)) : fr.pt;
        d.preview = [fp, endP];
        this.renderOverlay();
        return;
      }
      if (d.type === 'bg') {
        const bg = this.data.settings.bg;
        bg.x = Math.round(d.x + (w.x - d.start.x)); bg.y = Math.round(d.y + (w.y - d.start.y));
        this.markDirty(); this.renderFast();
        return;
      }
      if (d.type === 'wp') {
        d.e.points[d.i] = { x: this.snap(w.x), y: this.snap(w.y) };
        this.markDirty(); this.renderFast();
        return;
      }
      if (d.type === 'rubber') {
        const s = d.start;
        d.rect = { x: Math.min(s.x, w.x), y: Math.min(s.y, w.y), w: Math.abs(w.x - s.x), h: Math.abs(w.y - s.y) };
        this.sel = new Set(d.base);
        const r = d.rect;
        for (const n of this.data.nodes) if (n.x >= r.x && n.y >= r.y && n.x + n.w <= r.x + r.w && n.y + n.h <= r.y + r.h) this.sel.add(n.id);
        for (const e of this.data.edges) {
          const pts = this.edgePoints(e);
          if (pts.every((p) => p.x >= r.x && p.y >= r.y && p.x <= r.x + r.w && p.y <= r.y + r.h)) this.sel.add(e.id);
        }
        this.renderFast();
      }
    }

    onUp(ev) {
      const d = this.drag;
      if (!d) return;
      try { this.svg.releasePointerCapture(ev.pointerId); } catch (e) { /* ignore */ }
      this.svg.classList.remove('me-grabbing');
      this.drag = null;
      this.guides = [];
      if (d.type === 'connect') {
        const w = this.toWorld(ev.clientX, ev.clientY);
        if (d.target) {
          this.pushUndo();
          const e = { id: uid('e'), from: d.from, to: { node: d.target, port: d.hoverPort || null }, style: defaultEdgeStyle() };
          this.data.edges.push(e);
          this.sel.clear(); this.sel.add(e.id);
          this.markDirty();
        } else if (Math.hypot(w.x - d.start.x, w.y - d.start.y) > 30) {
          // Ins Leere gezogen: neues Element (Kopie des Ursprungs) erzeugen und verbinden – wie draw.io
          const src = this.node(d.from.node);
          if (src) {
            this.pushUndo();
            const n = clone(src);
            n.id = uid('n'); delete n.bind;
            if (n.kind === 'status') { n.kind = 'shape'; n.shape = 'rounded'; }
            n.label = n.kind === 'icon' ? n.label : '';
            n.x = this.snap(w.x - n.w / 2); n.y = this.snap(w.y - n.h / 2);
            this.data.nodes.push(n);
            const e = { id: uid('e'), from: d.from, to: { node: n.id, port: null }, style: defaultEdgeStyle() };
            this.data.edges.push(e);
            this.sel.clear(); this.sel.add(n.id);
            this.markDirty();
          }
        }
      } else if (d.type === 'ehandle') {
        const end = d.which;
        if (d.target) d.e[end] = { node: d.target, port: d.hoverPort || null };
        else {
          const w = this.toWorld(ev.clientX, ev.clientY);
          d.e[end] = { x: this.snap(w.x), y: this.snap(w.y) };
        }
        this.markDirty();
      } else if (d.type === 'move' && !d.moved) {
        // einfacher Klick in Mehrfachauswahl ohne Shift: nur dieses Element wählen
        if (!(ev.shiftKey || ev.ctrlKey || ev.metaKey) && this.sel.size > 1) {
          const hit = this.hitFrom(ev);
          if (hit.id) { this.sel.clear(); this.sel.add(hit.id); }
        }
      }
      this.render();
    }

    renderFast() {
      // Rendern ohne Eigenschaften-Panel (flüssiger beim Ziehen)
      const p = this.props;
      this.props = document.createElement('div');
      this.render();
      this.props = p;
    }

    smartGuides(moving, first, nx, ny) {
      const ids = new Set(moving.map((n) => n.id));
      const thr = 6 / this.view.k;
      const bx = { l: nx, c: nx + first.n.w / 2, r: nx + first.n.w };
      const by = { t: ny, m: ny + first.n.h / 2, b: ny + first.n.h };
      let best = { dx: thr + 1, dy: thr + 1, gx: null, gy: null };
      for (const o of this.data.nodes) {
        if (ids.has(o.id)) continue;
        const ox = [o.x, o.x + o.w / 2, o.x + o.w], oy = [o.y, o.y + o.h / 2, o.y + o.h];
        for (const v of Object.values(bx)) for (const t of ox) { const dd = t - v; if (Math.abs(dd) < Math.abs(best.dx)) best.dx = dd, best.gx = t; }
        for (const v of Object.values(by)) for (const t of oy) { const dd = t - v; if (Math.abs(dd) < Math.abs(best.dy)) best.dy = dd, best.gy = t; }
      }
      const lines = [];
      if (Math.abs(best.dx) <= thr) { nx += best.dx; lines.push({ v: best.gx }); }
      if (Math.abs(best.dy) <= thr) { ny += best.dy; lines.push({ h: best.gy }); }
      return { x: nx, y: ny, lines };
    }

    onDouble(hit, w) {
      if (hit.wp !== undefined) {
        const e = this.edge(hit.eid);
        this.pushUndo(); e.points.splice(hit.wp, 1); this.markDirty(); this.render();
        return;
      }
      if (hit.id) {
        const o = this.node(hit.id) || this.edge(hit.id);
        if (o) {
          this.sel.clear(); this.sel.add(o.id); this.render();
          if (this.edge(hit.id)) {
            const pts = this.edgePoints(o);
            const [, , m] = this.splitAt(pts, 0.5);
            this.startInline(o, m.x - 60, m.y - 14, 120, 28);
          } else {
            const pos = (Object.assign(defaultStyle(o.kind), o.style || {})).labelPos;
            if (o.kind === 'icon' || pos === 'bottom') this.startInline(o, o.x - 30, o.y + o.h + 2, o.w + 60, 40);
            else this.startInline(o, o.x, o.y, Math.max(o.w, 80), Math.max(o.h, 30));
          }
        }
        return;
      }
      // Doppelklick auf leere Fläche: Text einfügen
      this.pushUndo();
      const n = { id: uid('n'), kind: 'text', shape: 'rect', x: this.snap(w.x - 60), y: this.snap(w.y - 15), w: 120, h: 30, label: '', style: defaultStyle('text') };
      this.data.nodes.push(n);
      this.sel.clear(); this.sel.add(n.id);
      this.markDirty(); this.render();
      this.startInline(n, n.x, n.y, n.w, n.h);
    }

    startInline(o, x, y, w, h) {
      const s = this.toScreen(x, y);
      const r = this.wrap.getBoundingClientRect();
      const t = this.inline;
      t.value = o.label || '';
      t.style.left = (s.x - r.left) + 'px';
      t.style.top = (s.y - r.top) + 'px';
      t.style.width = Math.max(100, w * this.view.k) + 'px';
      t.style.height = Math.max(28, h * this.view.k) + 'px';
      t.hidden = false;
      t.dataset.target = o.id;
      this._inlineAt = Date.now();
      setTimeout(() => { t.focus(); t.select(); }, 30);
    }

    commitInline() {
      const t = this.inline;
      if (t.hidden) return;
      t.hidden = true;
      const o = this.node(t.dataset.target) || this.edge(t.dataset.target);
      if (o && (o.label || '') !== t.value) { this.pushUndo(); o.label = t.value; this.markDirty(); }
      this.render();
    }

    onWheel(ev) {
      ev.preventDefault();
      if (ev.ctrlKey || ev.metaKey || this.mode === 'view') {
        const f = Math.exp(-ev.deltaY * (ev.deltaMode === 1 ? 0.05 : 0.0018));
        this.zoomAt(ev.clientX, ev.clientY, this.view.k * f);
      } else {
        this.view.tx -= ev.shiftKey ? ev.deltaY : ev.deltaX;
        this.view.ty -= ev.shiftKey ? 0 : ev.deltaY;
        this.render();
      }
    }

    zoomAt(cx, cy, k) {
      k = clamp(k, 0.1, 5);
      const r = this.svg.getBoundingClientRect();
      const px = cx - r.left, py = cy - r.top;
      this.view.tx = px - ((px - this.view.tx) * k) / this.view.k;
      this.view.ty = py - ((py - this.view.ty) * k) / this.view.k;
      this.view.k = k;
      this.render();
    }

    bbox(nodes) {
      const bgi = this.data.settings.bg;
      if (!nodes.length && !(bgi && bgi.src)) return null;
      let x1 = Infinity, y1 = Infinity, x2 = -Infinity, y2 = -Infinity;
      if (bgi && bgi.src) { x1 = bgi.x || 0; y1 = bgi.y || 0; x2 = x1 + bgi.w; y2 = y1 + bgi.h; }
      for (const n of nodes) { x1 = Math.min(x1, n.x); y1 = Math.min(y1, n.y); x2 = Math.max(x2, n.x + n.w); y2 = Math.max(y2, n.y + n.h + ((n.kind === 'icon' || (n.style && n.style.labelPos === 'bottom')) ? 30 : 0)); }
      for (const e of this.data.edges) for (const p of e.points || []) { x1 = Math.min(x1, p.x); y1 = Math.min(y1, p.y); x2 = Math.max(x2, p.x); y2 = Math.max(y2, p.y); }
      return { x: x1, y: y1, w: x2 - x1, h: y2 - y1 };
    }

    fit() {
      const b = this.bbox(this.data.nodes);
      const r = this.svg.getBoundingClientRect();
      if (!b || !r.width) { this.view = { tx: 40, ty: 40, k: 1 }; this.render(); return; }
      const pad = 40;
      const k = clamp(Math.min((r.width - pad * 2) / Math.max(b.w, 1), (r.height - pad * 2) / Math.max(b.h, 1)), 0.1, this.mode === 'view' ? 3 : 1.5);
      this.view.k = k;
      this.view.tx = (r.width - b.w * k) / 2 - b.x * k;
      this.view.ty = (r.height - b.h * k) / 2 - b.y * k;
      this.render();
    }

    onKey(ev) {
      if (!this.root.isConnected || this.root.offsetParent === null) return;
      const tag = (ev.target.tagName || '').toLowerCase();
      if (['input', 'textarea', 'select'].includes(tag)) {
        if ((ev.ctrlKey || ev.metaKey) && ev.key === 's') { ev.preventDefault(); this.cmd('save'); }
        return;
      }
      const mod = ev.ctrlKey || ev.metaKey;
      if (ev.code === 'Space' && !this.space) { this.space = true; this.svg.classList.add('me-grab'); ev.preventDefault(); return; }
      if (mod && ev.key.toLowerCase() === 's') { ev.preventDefault(); this.cmd('save'); return; }
      if (mod && (ev.key === '+' || ev.key === '=')) { ev.preventDefault(); this.cmd('zoom-in'); return; }
      if (mod && ev.key === '-') { ev.preventDefault(); this.cmd('zoom-out'); return; }
      if (mod && ev.key === '0') { ev.preventDefault(); this.cmd('zoom-reset'); return; }
      if (mod && ev.shiftKey && ev.key.toLowerCase() === 'h') { ev.preventDefault(); this.fit(); return; }
      if (this.mode !== 'edit') return;
      if (ev.key === 'Delete' || ev.key === 'Backspace') { ev.preventDefault(); this.cmd('delete'); return; }
      if (mod && ev.key.toLowerCase() === 'z' && !ev.shiftKey) { ev.preventDefault(); this.cmd('undo'); return; }
      if (mod && (ev.key.toLowerCase() === 'y' || (ev.shiftKey && ev.key.toLowerCase() === 'z'))) { ev.preventDefault(); this.cmd('redo'); return; }
      if (mod && ev.key.toLowerCase() === 'd') { ev.preventDefault(); this.cmd('duplicate'); return; }
      if (mod && ev.key.toLowerCase() === 'c') { this.cmd('copy'); return; }
      if (mod && ev.key.toLowerCase() === 'x') { this.cmd('copy'); this.cmd('delete'); return; }
      if (mod && ev.key.toLowerCase() === 'v') { this.cmd('paste'); return; }
      if (mod && ev.key.toLowerCase() === 'a') { ev.preventDefault(); this.data.nodes.forEach((n) => this.sel.add(n.id)); this.data.edges.forEach((e) => this.sel.add(e.id)); this.render(); return; }
      if (ev.key === 'Escape') { this.sel.clear(); this.render(); return; }
      if (ev.key === 'F2' && this.sel.size === 1) { const id = [...this.sel][0]; const n = this.node(id); if (n) this.onDouble({ id }, { x: n.x, y: n.y }); return; }
      const arrows = { ArrowLeft: [-1, 0], ArrowRight: [1, 0], ArrowUp: [0, -1], ArrowDown: [0, 1] };
      if (arrows[ev.key] && this.sel.size) {
        ev.preventDefault();
        const step = ev.shiftKey ? 10 : 1;
        this.pushUndo(true);
        for (const n of this.selNodes()) { n.x += arrows[ev.key][0] * step; n.y += arrows[ev.key][1] * step; }
        this.markDirty(); this.render();
      }
    }

    onContext(ev) {
      ev.preventDefault();
      if (this.mode !== 'edit') return;
      const hit = this.hitFrom(ev);
      if (hit.id && !this.sel.has(hit.id)) { this.sel.clear(); this.sel.add(hit.id); this.render(); }
      this._ctxPos = this.toWorld(ev.clientX, ev.clientY);
      const items = this.sel.size ? [
        ['edit-label', '✏️ Text bearbeiten (F2)'], ['copy', '📋 Kopieren (Strg+C)'], ['paste', '📥 Einfügen (Strg+V)'],
        ['duplicate', '⧉ Duplizieren (Strg+D)'], ['-'], ['front', '⬆︎ Nach vorne'], ['back', '⬇︎ Nach hinten'], ['-'], ['delete', '🗑 Löschen'],
      ] : [['paste', '📥 Einfügen (Strg+V)'], ['select-all', '☑️ Alles auswählen'], ['fit', '⛶ Alles anzeigen']];
      this.ctx.innerHTML = items.map(([c, l]) => (c === '-' ? '<hr>' : `<button data-ctx="${c}">${l}</button>`)).join('');
      const r = this.wrap.getBoundingClientRect();
      this.ctx.style.left = (ev.clientX - r.left) + 'px';
      this.ctx.style.top = (ev.clientY - r.top) + 'px';
      this.ctx.hidden = false;
    }

    showTip(ev) {
      const hit = this.hitFrom(ev);
      const o = hit.id && (this.node(hit.id) || this.edge(hit.id));
      const i = o && o.bind ? this.bindInfo(o.bind) : null;
      if (!i) { this.tip.hidden = true; return; }
      const r = this.wrap.getBoundingClientRect();
      const c = i.counts ? Object.entries(i.counts).filter(([, v]) => v).map(([k, v]) => `<span><i style="background:${STATUS_COLOR[k]}"></i>${v} ${STATUS_TEXT[k]}</span>`).join('') : '';
      this.tip.innerHTML = `<b>${esc((i.device ? i.device + ' › ' : '') + i.name)}</b><div class="me-tipst"><i style="background:${STATUS_COLOR[i.status]}"></i>${STATUS_TEXT[i.status]}${i.value !== undefined && i.value !== null ? ' · ' + fmtNum(i.value) + ' ' + esc(i.unit || '') : ''}</div>${i.message ? `<div class="me-tipmsg">${esc(i.message)}</div>` : ''}${c ? `<div class="me-tipc">${c}</div>` : ''}`;
      this.tip.style.left = Math.min(ev.clientX - r.left + 14, r.width - 260) + 'px';
      this.tip.style.top = (ev.clientY - r.top + 14) + 'px';
      this.tip.hidden = false;
    }

    openObject(id) {
      const o = this.node(id) || this.edge(id);
      if (!o) return;
      if (o.link) { window.open(o.link, '_blank', 'noopener'); return; }
      if (o.bind && this.opts.onOpenObject) this.opts.onOpenObject(o.bind);
    }

    /* ============================================================ Befehle */
    pushUndo(coalesce) {
      const snap = JSON.stringify(this.data);
      if (coalesce && this._lastUndoT && Date.now() - this._lastUndoT < 600) return;
      this._lastUndoT = coalesce ? Date.now() : 0;
      if (this.undo[this.undo.length - 1] === snap) return;
      this.undo.push(snap);
      if (this.undo.length > 150) this.undo.shift();
      this.redo = [];
    }
    markDirty() {
      this.dirty = true;
      const sm = this.root.querySelector('[data-cmd="save"]');
      if (sm) sm.classList.add('dirty');
    }

    addFromPalette(group, i, x, y) {
      const it = this.palItems[group][i];
      if (!it) return;
      this.pushUndo();
      const n = { id: uid('n'), kind: it.kind === 'link' ? 'link' : it.kind, shape: it.shape || 'rect', icon: it.icon, x: this.snap(x - it.w / 2), y: this.snap(y - it.h / 2), w: it.w, h: it.h, label: it.kind === 'icon' ? it.label : (it.kind === 'zone' ? 'Zone' : (it.kind === 'text' ? 'Text' : '')), style: defaultStyle(it.kind) };
      if (it.kind === 'link') { n.label = 'Link'; n.link = 'https://'; n.style = Object.assign(defaultStyle('shape'), { fill: '#1e5fa8', stroke: 'none', strokeWidth: 0, fontColor: '#ffffff', bold: true, radius: 8 }); }
      if (it.kind === 'zone') this.data.nodes.unshift(n); else this.data.nodes.push(n);
      this.sel.clear(); this.sel.add(n.id);
      this.markDirty(); this.render();
    }

    addBound(p, x, y) {
      this.pushUndo();
      let n;
      if (p.bt === 'device') n = { kind: 'icon', icon: p.icon || 'server', w: 64, h: 64, label: p.name };
      else if (p.bt === 'sensor') n = { kind: 'status', w: 170, h: 52, label: '' };
      else n = { kind: 'status', w: 190, h: 56, label: '' };
      Object.assign(n, { id: uid('n'), shape: 'rect', x: this.snap(x - n.w / 2), y: this.snap(y - n.h / 2), bind: { type: p.bt, id: p.bid }, style: defaultStyle(n.kind) });
      this.data.nodes.push(n);
      this.sel.clear(); this.sel.add(n.id);
      this.markDirty(); this.render();
    }

    multiStyle(key, v) {
      if (!this._liveUndo) { this.pushUndo(); this._liveUndo = true; }
      for (const o of this.selNodes()) { o.style = o.style || {}; o.style[key] = v; }
      if (key === 'stroke') for (const e of this.selEdges()) { e.style = e.style || {}; e.style.stroke = v; }
      this.markDirty(); this.renderFast();
    }

    cmd(c, btn) {
      const nodes = this.selNodes();
      switch (c) {
        case 'mode-edit': this.mode = 'edit'; this.applyMode(); break;
        case 'mode-view': this.mode = 'view'; this.applyMode(); break;
        case 'undo': if (this.undo.length) { this.redo.push(JSON.stringify(this.data)); this.data = JSON.parse(this.undo.pop()); this.cleanSel(); this.markDirty(); this.render(); } break;
        case 'redo': if (this.redo.length) { this.undo.push(JSON.stringify(this.data)); this.data = JSON.parse(this.redo.pop()); this.cleanSel(); this.markDirty(); this.render(); } break;
        case 'delete': {
          if (!this.sel.size) break;
          this.pushUndo();
          const ids = this.sel;
          this.data.nodes = this.data.nodes.filter((n) => !ids.has(n.id));
          this.data.edges = this.data.edges.filter((e) => !ids.has(e.id) && !(e.from.node && ids.has(e.from.node)) && !(e.to.node && ids.has(e.to.node)));
          this.sel.clear(); this.markDirty(); this.render();
          break;
        }
        case 'copy': {
          const ids = new Set(nodes.map((n) => n.id));
          this.clip = { nodes: clone(nodes), edges: clone(this.data.edges.filter((e) => ids.has(e.from.node) && ids.has(e.to.node))) };
          break;
        }
        case 'paste': if (this.clip) this.pasteClip(this.clip, 20); break;
        case 'duplicate': { const ids = new Set(nodes.map((n) => n.id)); this.pasteClip({ nodes: clone(nodes), edges: clone(this.data.edges.filter((e) => ids.has(e.from.node) && ids.has(e.to.node))) }, 20); break; }
        case 'front': case 'back': {
          if (!this.sel.size) break;
          this.pushUndo();
          const sel = this.data.nodes.filter((n) => this.sel.has(n.id)), rest = this.data.nodes.filter((n) => !this.sel.has(n.id));
          this.data.nodes = c === 'front' ? [...rest, ...sel] : [...sel, ...rest];
          const se = this.data.edges.filter((e) => this.sel.has(e.id)), re = this.data.edges.filter((e) => !this.sel.has(e.id));
          this.data.edges = c === 'front' ? [...re, ...se] : [...se, ...re];
          this.markDirty(); this.render();
          break;
        }
        case 'align-left': case 'align-center': case 'align-right': case 'align-top': case 'align-middle': case 'align-bottom': {
          if (nodes.length < 2) break;
          this.pushUndo();
          const b = this.bboxPlain(nodes);
          for (const n of nodes) {
            if (c === 'align-left') n.x = b.x;
            if (c === 'align-right') n.x = b.x + b.w - n.w;
            if (c === 'align-center') n.x = b.x + b.w / 2 - n.w / 2;
            if (c === 'align-top') n.y = b.y;
            if (c === 'align-bottom') n.y = b.y + b.h - n.h;
            if (c === 'align-middle') n.y = b.y + b.h / 2 - n.h / 2;
          }
          this.markDirty(); this.render();
          break;
        }
        case 'dist-h': case 'dist-v': {
          if (nodes.length < 3) break;
          this.pushUndo();
          const hz = c === 'dist-h';
          const s = [...nodes].sort((a, b) => (hz ? a.x - b.x : a.y - b.y));
          const total = s.reduce((acc, n) => acc + (hz ? n.w : n.h), 0);
          const first = s[0], last = s[s.length - 1];
          const span = hz ? last.x + last.w - first.x : last.y + last.h - first.y;
          const gap = (span - total) / (s.length - 1);
          let pos = hz ? first.x : first.y;
          for (const n of s) { if (hz) n.x = pos; else n.y = pos; pos += (hz ? n.w : n.h) + gap; }
          this.markDirty(); this.render();
          break;
        }
        case 'grid': this.data.settings.grid = !this.data.settings.grid; this.markDirty(); this.render(); break;
        case 'snap': this.data.settings.snap = !this.data.settings.snap; this.markDirty(); this.render(); break;
        case 'zoom-in': { const r = this.svg.getBoundingClientRect(); this.zoomAt(r.left + r.width / 2, r.top + r.height / 2, this.view.k * 1.2); break; }
        case 'zoom-out': { const r = this.svg.getBoundingClientRect(); this.zoomAt(r.left + r.width / 2, r.top + r.height / 2, this.view.k / 1.2); break; }
        case 'zoom-reset': { const r = this.svg.getBoundingClientRect(); this.zoomAt(r.left + r.width / 2, r.top + r.height / 2, 1); break; }
        case 'fit': this.fit(); break;
        case 'select-all': this.data.nodes.forEach((n) => this.sel.add(n.id)); this.render(); break;
        case 'edit-label': if (this.sel.size === 1) { const id = [...this.sel][0]; this.onDouble({ id }, { x: 0, y: 0 }); } break;
        case 'bg-remove': this.pushUndo(); delete this.data.settings.bg; this.bgMove = false; this.markDirty(); this.render(); break;
        case 'bg-move': this.bgMove = !this.bgMove; this.sel.clear(); this.render(); break;
        case 'bg-bigger': case 'bg-smaller': { const bg = this.data.settings.bg; if (!bg) break; this.pushUndo(); const f = c === 'bg-bigger' ? 1.1 : 1 / 1.1; bg.w *= f; bg.h *= f; this.markDirty(); this.render(); break; }
        case 'bg-ratio': { const bg = this.data.settings.bg; if (!bg || !bg.nw) break; this.pushUndo(); bg.h = bg.w * bg.nh / bg.nw; this.markDirty(); this.render(); break; }
        case 'clear-waypoints': this.pushUndo(); for (const e of this.selEdges()) delete e.points; this.markDirty(); this.render(); break;
        case 'export-menu': { const m = btn.parentElement.querySelector('.me-menu'); m.hidden = !m.hidden; break; }
        case 'export-svg': this.download(this.name + '.svg', new Blob([this.exportSvg()], { type: 'image/svg+xml' })); break;
        case 'export-png': this.exportPng(); break;
        case 'export-json': this.download(this.name + '.json', new Blob([JSON.stringify({ type: 'reload-monitor-map', version: 1, name: this.name, data: this.data }, null, 2)], { type: 'application/json' })); break;
        case 'import-json': this.root.querySelector('.me-file').click(); break;
        case 'fullscreen': if (document.fullscreenElement) document.exitFullscreen(); else this.root.requestFullscreen && this.root.requestFullscreen(); break;
        case 'save': if (this.opts.onSave && !this.opts.readonly) Promise.resolve(this.opts.onSave(this.serialize())).then((ok) => { if (ok !== false) { this.dirty = false; this.render(); } }); break;
        case 'close': if (this.opts.onClose) this.opts.onClose(this.dirty); break;
        default: if (this.opts.onCommand) this.opts.onCommand(c, btn);
      }
    }

    cleanSel() { for (const id of [...this.sel]) if (!this.node(id) && !this.edge(id)) this.sel.delete(id); }
    bboxPlain(nodes) {
      let x1 = Infinity, y1 = Infinity, x2 = -Infinity, y2 = -Infinity;
      for (const n of nodes) { x1 = Math.min(x1, n.x); y1 = Math.min(y1, n.y); x2 = Math.max(x2, n.x + n.w); y2 = Math.max(y2, n.y + n.h); }
      return { x: x1, y: y1, w: x2 - x1, h: y2 - y1 };
    }

    pasteClip(clip, off) {
      if (!clip.nodes.length) return;
      this.pushUndo();
      const map = {};
      const nn = clip.nodes.map((n) => { const c = clone(n); map[n.id] = c.id = uid('n'); c.x += off; c.y += off; return c; });
      const ee = clip.edges.map((e) => {
        const c = clone(e); c.id = uid('e');
        c.from = Object.assign({}, c.from, { node: map[c.from.node] });
        c.to = Object.assign({}, c.to, { node: map[c.to.node] });
        if (c.points) c.points = c.points.map((p) => ({ x: p.x + off, y: p.y + off }));
        return c;
      });
      this.data.nodes.push(...nn); this.data.edges.push(...ee);
      this.sel = new Set(nn.map((n) => n.id));
      this.clip = { nodes: clone(nn), edges: clone(ee) };
      this.markDirty(); this.render();
    }

    serialize() { return { name: this.name, refresh_s: this.refresh, data: this.data }; }

    /** Bild laden, große Rasterbilder verkleinern (max. 2400 px, JPEG wenn > 1,5 MB), als data-URI speichern. */
    loadBackground(file) {
      if (!file) return;
      const rd = new FileReader();
      rd.onload = () => {
        const src = rd.result;
        const img = new Image();
        img.onload = () => {
          let out = src, nw = img.naturalWidth || 1200, nh = img.naturalHeight || 800;
          const isSvg = /^data:image\/svg/.test(src);
          if (!isSvg && (Math.max(nw, nh) > 2400 || src.length > 1.5e6)) {
            const k = Math.min(1, 2400 / Math.max(nw, nh));
            const c = document.createElement('canvas');
            c.width = Math.round(nw * k); c.height = Math.round(nh * k);
            c.getContext('2d').drawImage(img, 0, 0, c.width, c.height);
            out = c.toDataURL('image/png');
            if (out.length > 1.5e6) out = c.toDataURL('image/jpeg', 0.85);
            nw = c.width; nh = c.height;
          }
          if (out.length > 10e6) { alert('Bild ist auch verkleinert noch zu groß.'); return; }
          this.pushUndo();
          const b = this.bboxPlain(this.data.nodes.length ? this.data.nodes : [{ x: 0, y: 0, w: 0, h: 0 }]);
          const w = Math.min(nw, 1600), h = w * nh / nw;
          const old = this.data.settings.bg;
          this.data.settings.bg = old ? Object.assign(old, { src: out, nw, nh, h: old.w * nh / nw }) : { src: out, x: Math.round(b.x), y: Math.round(b.y), w, h, nw, nh, opacity: 0.6 };
          this.markDirty(); this.render(); if (!old) this.fit();
        };
        img.onerror = () => alert('Bild konnte nicht gelesen werden.');
        img.src = src;
      };
      rd.readAsDataURL(file);
    }

    importJson(file) {
      if (!file) return;
      file.text().then((t) => {
        const j = JSON.parse(t);
        const d = j.data || j;
        if (!Array.isArray(d.nodes)) throw new Error('Kein gültiges Map-JSON');
        this.pushUndo();
        this.data = { nodes: d.nodes, edges: d.edges || [], settings: Object.assign(this.data.settings, d.settings || {}) };
        this.updateGridPattern(); this.markDirty(); this.fit();
      }).catch((e) => alert('Import fehlgeschlagen: ' + e.message));
    }

    exportSvg() {
      const b = this.bbox(this.data.nodes) || { x: 0, y: 0, w: 200, h: 100 };
      const pad = 30;
      const savedMode = this.mode, savedSel = this.sel;
      this.mode = 'view'; this.sel = new Set();
      let body = this.contentSvg();
      this.mode = savedMode; this.sel = savedSel;
      body = body.replace(/ class="[^"]*"/g, '').replace(/ data-id="[^"]*"/g, '');
      const vb = `${b.x - pad} ${b.y - pad} ${b.w + 2 * pad} ${b.h + 2 * pad}`;
      return `<?xml version="1.0" encoding="UTF-8"?>\n<svg xmlns="http://www.w3.org/2000/svg" viewBox="${vb}" width="${Math.ceil(b.w + 2 * pad)}" height="${Math.ceil(b.h + 2 * pad)}"><defs><filter id="me-shadow" x="-20%" y="-20%" width="140%" height="140%"><feDropShadow dx="0" dy="1.5" stdDeviation="2" flood-opacity=".18"/></filter></defs><rect x="${b.x - pad}" y="${b.y - pad}" width="${b.w + 2 * pad}" height="${b.h + 2 * pad}" fill="${esc(this.data.settings.background || '#fff')}"/>${body}</svg>`;
    }

    exportPng() {
      const svg = this.exportSvg();
      const img = new Image();
      const url = URL.createObjectURL(new Blob([svg], { type: 'image/svg+xml' }));
      img.onload = () => {
        const scale = 2;
        const c = document.createElement('canvas');
        c.width = img.width * scale; c.height = img.height * scale;
        const ctx = c.getContext('2d');
        ctx.scale(scale, scale);
        ctx.drawImage(img, 0, 0);
        URL.revokeObjectURL(url);
        c.toBlob((blob) => this.download(this.name + '.png', blob), 'image/png');
      };
      img.onerror = () => alert('PNG-Export fehlgeschlagen (externe Bilder blockieren evtl. den Export – SVG nutzen).');
      img.src = url;
    }

    download(name, blob) {
      const a = document.createElement('a');
      a.href = URL.createObjectURL(blob);
      a.download = name.replace(/[^\w.\- äöüÄÖÜß]/g, '_');
      document.body.appendChild(a); a.click(); a.remove();
      setTimeout(() => URL.revokeObjectURL(a.href), 2000);
    }

    /* ======================================================= Live-Status */
    setStatus(idx) {
      this.status = idx || this.status;
      const live = this.root.querySelector('.me-live');
      if (live) { live.classList.remove('stale'); live.title = 'Aktualisiert ' + new Date().toLocaleTimeString('de-DE'); }
      if (!this.drag) { if (this.mode === 'edit') this.renderFast(); else this.render(); }
    }
    startPolling() {
      const tick = () => Promise.resolve(this.opts.fetchStatus()).then((idx) => idx && this.setStatus(idx)).catch(() => {
        const live = this.root.querySelector('.me-live');
        if (live) live.classList.add('stale');
      });
      clearInterval(this.poll);
      this.poll = setInterval(tick, Math.max(5, this.refresh) * 1000);
    }
  }

  window.MapEditor = MapEditor;
  window.RM_STATUS_COLOR = STATUS_COLOR;
  window.RM_STATUS_TEXT = STATUS_TEXT;
  window.RM_PRIO = PRIO;
})();
