// Reload Monitor – Single-Page-Oberfläche (Vanilla JS).
/* global Chart, MapEditor, RM_ICONS, RM_STATUS_COLOR, RM_STATUS_TEXT */
(function () {
  'use strict';

  const CSRF = document.querySelector('meta[name="csrf"]').content;
  const $ = (s, el = document) => el.querySelector(s);
  const $$ = (s, el = document) => [...el.querySelectorAll(s)];
  const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const SC = () => window.RM_STATUS_COLOR;
  const ST = () => window.RM_STATUS_TEXT;
  const ORDER = ['down', 'warning', 'acked', 'unknown', 'up', 'paused'];

  const S = { boot: null, tree: null, counts: {}, heartbeat: 0, now: 0, view: null, editor: null, chart: null, drawerSensor: null, filter: '', search: '' };

  /* ------------------------------------------------------------ Helfer */
  async function api(action, data, params = {}) {
    const q = new URLSearchParams(Object.assign({ action }, params)).toString();
    const opt = data !== undefined
      ? { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF }, body: JSON.stringify(data) }
      : { headers: { Accept: 'application/json' } };
    const r = await fetch('api.php?' + q, opt);
    if (r.status === 401) { location.href = 'login.php'; throw new Error('Nicht angemeldet'); }
    let j;
    try { j = await r.json(); } catch (e) { throw new Error('Serverfehler (' + r.status + ')'); }
    if (!j.ok) throw new Error(j.error || 'Fehler');
    return j;
  }

  function toast(msg, type = 'ok') {
    const t = document.createElement('div');
    t.className = 'toast ' + type;
    t.textContent = msg;
    $('#toasts').appendChild(t);
    setTimeout(() => t.classList.add('out'), 3200);
    setTimeout(() => t.remove(), 3700);
  }
  const fail = (e) => toast(e.message || String(e), 'err');

  const fmt = (v) => (v === null || v === undefined ? '–' : (Math.abs(v) >= 100 ? Math.round(v) : Math.round(v * 100) / 100).toLocaleString('de-DE'));
  const fmtVal = (s) => (s.value === null || s.value === undefined ? '' : fmt(s.value) + (s.unit ? ' ' + s.unit : ''));
  function ago(ts) {
    if (!ts) return 'nie';
    const d = Math.max(0, (S.now || Date.now() / 1000) - ts);
    if (d < 60) return 'vor ' + Math.round(d) + ' s';
    if (d < 3600) return 'vor ' + Math.round(d / 60) + ' min';
    if (d < 86400) return 'vor ' + Math.round(d / 3600) + ' h';
    return 'vor ' + Math.round(d / 86400) + ' T';
  }
  function dur(ts) {
    if (!ts) return '';
    const d = Math.max(0, (S.now || Date.now() / 1000) - ts);
    if (d < 3600) return Math.round(d / 60) + ' min';
    if (d < 86400) return Math.floor(d / 3600) + ' h ' + Math.round((d % 3600) / 60) + ' min';
    return Math.floor(d / 86400) + ' T ' + Math.round((d % 86400) / 3600) + ' h';
  }
  const dt = (ts) => new Date(ts * 1000).toLocaleString('de-DE', { day: '2-digit', month: '2-digit', year: '2-digit', hour: '2-digit', minute: '2-digit', second: '2-digit' });
  const dot = (st) => `<i class="dot st-${st}" title="${esc(ST()[st] || st)}"></i>`;
  const isAdmin = () => S.boot && S.boot.user.role === 'admin';

  /* ---------------------------------------------------- Baum-Abfragen */
  function walk(fn) {
    const rec = (g, path) => {
      fn('group', g, path);
      for (const d of g.devices) { fn('device', d, path.concat(g)); for (const s of d.sensors) fn('sensor', s, path.concat(g), d); }
      for (const c of g.groups) rec(c, path.concat(g));
    };
    if (S.tree) rec(S.tree, []);
  }
  function findSensor(id) { let r = null; walk((t, o, p, d) => { if (t === 'sensor' && o.id === id) r = { s: o, d, path: p }; }); return r; }
  function findDevice(id) { let r = null; walk((t, o, p) => { if (t === 'device' && o.id === id) r = { d: o, path: p }; }); return r; }
  function findGroup(id) { let r = null; walk((t, o, p) => { if (t === 'group' && o.id === id) r = { g: o, path: p }; }); return r; }
  function allGroups() { const out = []; walk((t, o, p) => { if (t === 'group' && o.kind !== 'root') out.push({ id: o.id, name: p.filter((x) => x.kind !== 'root').map((x) => x.name).concat(o.name).join(' › ') }); }); return out; }
  function allSensors() { const out = []; walk((t, o, p, d) => { if (t === 'sensor') out.push({ id: o.id, name: d.name + ' › ' + o.name }); }); return out; }

  /* ------------------------------------------------------- Refresh */
  async function loadTree() {
    const j = await api('tree');
    S.tree = j.tree; S.counts = j.counts; S.heartbeat = j.heartbeat; S.now = j.now;
    renderStatusbar();
    const pw = $('#pollerWarn');
    const age = j.now - j.heartbeat;
    if (!j.heartbeat || age > 180) {
      pw.hidden = false;
      pw.innerHTML = `⚠️ Der Poller (Cron) läuft nicht${j.heartbeat ? ' – letzter Lauf ' + ago(j.heartbeat) : ''}. Sensoren werden nicht automatisch geprüft. Einrichtung: <a href="#/settings/system">Einstellungen › System</a>`;
    } else pw.hidden = true;
  }

  function renderStatusbar() {
    const c = S.counts || {};
    $('#statusbar').innerHTML = ORDER.filter((k) => c[k] || k === 'up' || k === 'down').map((k) =>
      `<a class="pill st-${k}${c[k] ? '' : ' zero'}" href="#/devices?f=${k}" title="${ST()[k]}">${c[k] || 0}</a>`).join('');
    const bad = (c.down || 0) + (c.warning || 0);
    const b = $('#alarmBadge');
    b.hidden = !bad; b.textContent = bad;
    document.title = (c.down ? `(${c.down}) ` : '') + 'Reload Monitor';
  }

  let refreshTimer;
  function scheduleRefresh() {
    clearTimeout(refreshTimer);
    refreshTimer = setTimeout(async () => {
      try {
        await loadTree();
        if (!document.hidden && $('#modalBack').hidden) {
          if (['home', 'devices', 'alarms'].includes(S.view)) route(true);
        }
        if (S.drawerSensor && !$('#drawer').hidden) openSensor(S.drawerSensor, true);
      } catch (e) { /* still */ }
      scheduleRefresh();
    }, 15000);
  }

  /* ========================================================== Router */
  function route(soft) {
    const h = location.hash.replace(/^#/, '') || '/';
    const [path, qs] = h.split('?');
    const params = new URLSearchParams(qs || '');
    const parts = path.split('/').filter(Boolean);
    const v = parts[0] || 'home';
    if (S.editor && !(v === 'maps' && parts[1])) { S.editor.destroy(); S.editor = null; }
    $$('.mainnav a').forEach((a) => a.classList.toggle('on', a.dataset.nav === v));
    document.body.classList.toggle('fullbleed', v === 'maps' && !!parts[1]);
    S.view = v;
    const el = $('#view');
    const keepScroll = soft ? el.scrollTop : 0;
    try {
      if (v === 'home') viewHome(el);
      else if (v === 'devices') viewDevices(el, params);
      else if (v === 'alarms') viewAlarms(el);
      else if (v === 'maps' && parts[1]) { if (!soft) viewMapEditor(el, parts[1], params); }
      else if (v === 'maps') viewMaps(el);
      else if (v === 'log') viewLog(el, params);
      else if (v === 'settings') viewSettings(el, parts[1] || 'notifications');
      else if (v === 'sensor') { location.hash = '#/devices'; openSensor(+parts[1]); }
      else el.innerHTML = '<div class="page"><h1>Nicht gefunden</h1></div>';
    } catch (e) { console.error(e); el.innerHTML = `<div class="page"><div class="alert">${esc(e.message)}</div></div>`; }
    if (soft) el.scrollTop = keepScroll;
  }

  /* ========================================================= Übersicht */
  function viewHome(el) {
    const c = S.counts;
    const total = ORDER.reduce((a, k) => a + (c[k] || 0), 0);
    const alarms = [];
    let devices = 0;
    walk((t, o, p, d) => { if (t === 'device') devices++; if (t === 'sensor' && ['down', 'warning', 'acked', 'unknown'].includes(o.status)) alarms.push({ s: o, d }); });
    alarms.sort((a, b) => window.RM_PRIO[b.s.status] - window.RM_PRIO[a.s.status]);
    const okPct = total ? Math.round(((c.up || 0) / Math.max(1, total - (c.paused || 0))) * 1000) / 10 : 0;
    const donut = ORDER.filter((k) => c[k]).map((k) => ({ k, v: c[k] }));
    let acc = 0;
    const R = 52, C = 2 * Math.PI * R;
    const arcs = donut.map(({ k, v }) => { const len = (v / Math.max(1, total)) * C; const a = `<circle r="${R}" cx="64" cy="64" fill="none" stroke="${SC()[k]}" stroke-width="18" stroke-dasharray="${len} ${C - len}" stroke-dashoffset="${-acc}" transform="rotate(-90 64 64)"/>`; acc += len; return a; }).join('');
    const groups = S.tree ? flatGroups(S.tree) : [];
    el.innerHTML = `<div class="page">
      <div class="pagehead"><h1>Übersicht</h1><div class="actions">${isAdmin() ? '<button class="btn primary" data-act="add-device">+ Gerät hinzufügen</button>' : ''}</div></div>
      <div class="cards4">
        <div class="card kpi donutcard"><svg viewBox="0 0 128 128" width="128" height="128" role="img" aria-label="Sensorstatus"><circle r="${R}" cx="64" cy="64" fill="none" stroke="var(--line)" stroke-width="18"/>${arcs}<text x="64" y="62" text-anchor="middle" class="donut-num">${total}</text><text x="64" y="80" text-anchor="middle" class="donut-lbl">Sensoren</text></svg>
          <ul class="legend">${ORDER.map((k) => `<li><a href="#/devices?f=${k}">${dot(k)} ${ST()[k]} <b>${c[k] || 0}</b></a></li>`).join('')}</ul></div>
        <div class="card kpi"><span class="kpi-l">Verfügbarkeit jetzt</span><span class="kpi-v">${okPct.toLocaleString('de-DE')} %</span><span class="kpi-s">${c.up || 0} von ${Math.max(0, total - (c.paused || 0))} aktiven Sensoren OK</span></div>
        <div class="card kpi"><span class="kpi-l">Offene Alarme</span><span class="kpi-v ${c.down ? 't-down' : (c.warning ? 't-warning' : 't-up')}">${(c.down || 0) + (c.warning || 0)}</span><span class="kpi-s">${c.down || 0} Fehler · ${c.warning || 0} Warnungen · ${c.acked || 0} bestätigt</span></div>
        <div class="card kpi"><span class="kpi-l">Geräte</span><span class="kpi-v">${devices}</span><span class="kpi-s">Poller ${S.heartbeat ? ago(S.heartbeat) : 'inaktiv'}</span></div>
      </div>
      <div class="cols2">
        <section class="card"><h2>Aktuelle Alarme</h2>${alarms.length ? `<table class="tbl"><tbody>${alarms.slice(0, 12).map(({ s, d }) => `<tr class="clickable" data-sensor="${s.id}"><td>${dot(s.status)}</td><td><b>${esc(d.name)}</b> › ${esc(s.name)}<div class="sub">${esc(s.message || '')}</div></td><td class="nowrap muted">${dur(s.since)}</td></tr>`).join('')}</tbody></table>` : '<p class="empty">🎉 Keine Alarme – alles im grünen Bereich.</p>'}
          ${alarms.length > 12 ? '<a href="#/alarms" class="more">Alle Alarme anzeigen →</a>' : ''}</section>
        <section class="card"><h2>Gruppen</h2><table class="tbl"><tbody>${groups.map((g) => `<tr class="clickable" data-goto="#/devices"><td>${dot(g.status)}</td><td style="padding-left:${8 + g.depth * 14}px">${g.kind === 'probe' ? '🛰️' : '📁'} ${esc(g.name)}</td><td class="counts">${miniCounts(g.counts)}</td></tr>`).join('')}</tbody></table></section>
      </div>
      <section class="card"><h2>Letzte Ereignisse</h2><div id="homeEvents" class="muted">Lade …</div></section>
    </div>`;
    api('events', undefined, { limit: 12, type: 'status' }).then((j) => {
      const box = $('#homeEvents');
      if (box) box.innerHTML = eventsTable(j.events);
    }).catch(() => {});
  }

  function flatGroups(g, depth = 0, out = []) {
    if (g.kind !== 'root') out.push({ id: g.id, name: g.name, kind: g.kind, status: g.status, counts: g.counts, depth: depth - 1 });
    for (const c of g.groups) flatGroups(c, depth + 1, out);
    return out;
  }
  const miniCounts = (c) => ORDER.filter((k) => c[k]).map((k) => `<span class="mc st-${k}" title="${ST()[k]}">${c[k]}</span>`).join('');

  function eventsTable(evs) {
    if (!evs.length) return '<p class="empty">Noch keine Ereignisse.</p>';
    return `<table class="tbl"><tbody>${evs.map((e) => `<tr${e.sensor_id ? ` class="clickable" data-sensor="${e.sensor_id}"` : ''}><td class="nowrap muted">${dt(e.ts)}</td><td>${e.status ? dot(e.status) : `<span class="tag">${esc(e.type)}</span>`}</td><td>${esc(e.message)}</td></tr>`).join('')}</tbody></table>`;
  }

  /* =========================================================== Geräte */
  function viewDevices(el, params) {
    S.filter = params.get('f') || '';
    const f = S.filter;
    const q = (S.search || '').toLowerCase();
    const sensorVisible = (s, d) => (!f || s.status === f) && (!q || (d.name + ' ' + d.host + ' ' + s.name).toLowerCase().includes(q));
    const deviceVisible = (d) => d.sensors.some((s) => sensorVisible(s, d)) || ((!f) && (!q || (d.name + ' ' + d.host).toLowerCase().includes(q)));
    const groupVisible = (g) => (!f && !q) || g.devices.some(deviceVisible) || g.groups.some(groupVisible);

    const sensorTile = (s, d) => `<button class="stile st-${s.status}" data-sensor="${s.id}" title="${esc(s.name + ': ' + (s.message || ''))}">
        <span class="stile-n">${esc(s.icon)} ${esc(s.name)}</span><span class="stile-v">${esc(fmtVal(s) || ST()[s.status])}</span></button>`;
    const devRow = (d) => `<div class="devrow st-${d.status}" data-device="${d.id}" draggable="${isAdmin()}">
        <div class="devhead">
          <span class="devico">${RM_ICONS.html(d.icon, 22)}</span>
          <div class="devname"><b>${esc(d.name)}</b><small>${esc(d.host || '')}${d.paused ? ' · pausiert' : ''}${d.depends_on ? ' · 🔗 abhängig' : ''}</small></div>
          <span class="devcounts">${miniCounts(d.counts)}</span>
          ${isAdmin() ? `<span class="devact"><button class="ghost" data-act="add-sensor" data-id="${d.id}" title="Sensor hinzufügen">+ Sensor</button><button class="ghost" data-act="device-menu" data-id="${d.id}" title="Mehr">⋯</button></span>` : ''}
        </div>
        <div class="stiles">${d.sensors.filter((s) => sensorVisible(s, d)).map((s) => sensorTile(s, d)).join('') || '<span class="muted small">Keine Sensoren' + (isAdmin() ? ' – <a href="#" data-act="add-sensor" data-id="' + d.id + '">hinzufügen</a>' : '') + '</span>'}</div>
      </div>`;
    const grp = (g, depth) => {
      if (!groupVisible(g)) return '';
      const isRoot = g.kind === 'root';
      const body = `${g.devices.filter(deviceVisible).map(devRow).join('')}${g.groups.map((c) => grp(c, depth + 1)).join('')}`;
      if (isRoot) return body;
      const collapsed = g.collapsed && !f && !q;
      return `<section class="grp kind-${g.kind}${collapsed ? ' collapsed' : ''}" data-group="${g.id}">
        <header class="grphead st-${g.status}" data-drop-group="${g.id}">
          <button class="twisty" data-act="toggle-group" data-id="${g.id}" aria-label="Auf-/Zuklappen">${collapsed ? '▸' : '▾'}</button>
          ${dot(g.status)}<span class="grpname">${g.kind === 'probe' ? '🛰️' : '📁'} ${esc(g.name)}</span>
          <span class="devcounts">${miniCounts(g.counts)}</span>
          ${isAdmin() ? `<span class="devact"><button class="ghost" data-act="add-device" data-group="${g.id}">+ Gerät</button><button class="ghost" data-act="group-menu" data-id="${g.id}">⋯</button></span>` : ''}
        </header>
        <div class="grpbody">${body || '<p class="muted small pad">Leer</p>'}</div></section>`;
    };
    el.innerHTML = `<div class="page">
      <div class="pagehead"><h1>Geräte</h1>
        <div class="filters">${['', ...ORDER].map((k) => `<button class="fbtn${f === k ? ' on' : ''}" data-filter="${k}">${k ? dot(k) + ' ' + ST()[k] : 'Alle'}${k ? ` <b>${S.counts[k] || 0}</b>` : ''}</button>`).join('')}</div>
        <div class="actions">${isAdmin() ? '<button class="btn" data-act="add-group">+ Gruppe</button><button class="btn primary" data-act="add-device">+ Gerät</button>' : ''}</div></div>
      ${q ? `<p class="muted">Suche: „${esc(q)}“ <a href="#" data-act="clear-search">zurücksetzen</a></p>` : ''}
      <div class="tree">${S.tree ? grp(S.tree, 0) : ''}</div></div>`;
  }

  /* =========================================================== Alarme */
  function viewAlarms(el) {
    const rows = [];
    walk((t, o, p, d) => { if (t === 'sensor' && ['down', 'warning', 'acked', 'unknown'].includes(o.status)) rows.push({ s: o, d, p }); });
    rows.sort((a, b) => window.RM_PRIO[b.s.status] - window.RM_PRIO[a.s.status] || (a.s.since || 0) - (b.s.since || 0));
    el.innerHTML = `<div class="page"><div class="pagehead"><h1>Alarme</h1><span class="muted">${rows.length} Sensor(en) nicht OK</span></div>
      <div class="card nopad">${rows.length ? `<table class="tbl big"><thead><tr><th></th><th>Status</th><th>Gerät › Sensor</th><th>Meldung</th><th>Seit</th><th></th></tr></thead><tbody>
      ${rows.map(({ s, d, p }) => `<tr><td>${dot(s.status)}</td><td class="nowrap"><span class="badge st-${s.status}">${ST()[s.status]}</span></td>
        <td class="clickable" data-sensor="${s.id}"><b>${esc(d.name)}</b> › ${esc(s.name)}<div class="sub">${esc(p.filter((x) => x.kind !== 'root').map((x) => x.name).join(' › '))}</div></td>
        <td>${esc(s.message || '')}</td><td class="nowrap">${dur(s.since)}</td>
        <td class="nowrap right">${s.status === 'down' ? `<button class="btn sm" data-act="ack" data-id="${s.id}">Bestätigen</button>` : ''}<button class="btn sm" data-act="check" data-id="${s.id}">Jetzt prüfen</button></td></tr>`).join('')}
      </tbody></table>` : '<p class="empty pad">🎉 Keine Alarme.</p>'}</div></div>`;
  }

  /* ============================================================ Maps */
  async function viewMaps(el) {
    el.innerHTML = '<div class="page"><div class="pagehead"><h1>Maps</h1></div><p class="muted">Lade …</p></div>';
    const j = await api('maps').catch(fail);
    if (!j || S.view !== 'maps') return;
    el.innerHTML = `<div class="page"><div class="pagehead"><h1>Maps</h1><div class="actions">${isAdmin() ? '<button class="btn primary" data-act="new-map">+ Neue Map</button>' : ''}</div></div>
      <p class="muted">Maps sind frei gestaltbare Netzwerkpläne mit Live-Status – Editor wie draw.io: Formen und Geräte per Drag & Drop, Verbindungen über die blauen ×-Punkte ziehen.</p>
      <div class="mapgrid">${j.maps.map((m) => `<a class="card mapcard" href="#/maps/${m.id}">
        <div class="mapthumb" data-thumb="${m.id}"></div>
        <div class="mapmeta"><b>${esc(m.name)}</b><small>geändert ${dt(m.updated_at)}${m.public_token ? ' · 🌐 öffentlich' : ''}</small></div></a>`).join('') || '<p class="empty">Noch keine Maps. Lege die erste an!</p>'}</div></div>`;
    // Mini-Vorschau
    for (const m of j.maps) {
      api('map', undefined, { id: m.id }).then((r) => {
        const box = $(`[data-thumb="${m.id}"]`);
        if (!box) return;
        const tmp = document.createElement('div');
        tmp.style.cssText = 'position:absolute;left:-9999px;width:400px;height:240px';
        document.body.appendChild(tmp);
        const ed = new MapEditor(tmp, { data: r.map.data, readonly: true, name: m.name, status: statusIndexFromTree() });
        box.innerHTML = ed.exportSvg().replace(/^<\?xml[^>]*>/, '');
        ed.destroy(); tmp.remove();
      }).catch(() => {});
    }
  }

  function statusIndexFromTree() {
    const idx = { group: {}, device: {}, sensor: {} };
    walk((t, o, p, d) => {
      if (t === 'group') idx.group[o.id] = { name: o.name, status: o.status, counts: o.counts };
      if (t === 'device') idx.device[o.id] = { name: o.name, host: o.host, status: o.status, counts: o.counts };
      if (t === 'sensor') idx.sensor[o.id] = { name: o.name, device: d.name, status: o.status, value: o.value, unit: o.unit, message: o.message };
    });
    return idx;
  }

  async function viewMapEditor(el, id, params) {
    el.innerHTML = '<div class="page"><p class="muted">Lade Map …</p></div>';
    let m;
    if (id === 'new') m = { id: null, name: 'Neue Map', data: { nodes: [], edges: [] }, refresh_s: 30, public_token: null };
    else {
      const j = await api('map', undefined, { id }).catch(fail);
      if (!j) return;
      m = j.map;
    }
    el.innerHTML = '<div class="editor-host"></div>';
    const host = $('.editor-host', el);
    const shareHtml = () => {
      if (!m.id) return '<h4>Freigabe</h4><p class="me-hint">Erst speichern, dann kann ein öffentlicher Link erzeugt werden.</p>';
      const url = m.public_token ? new URL('map.php?t=' + m.public_token, location.href).href : '';
      return `<h4>Öffentlicher Link (NOC-Wand)</h4>${m.public_token
        ? `<input readonly value="${esc(url)}" class="me-copy" onclick="this.select()"><div class="me-row"><a class="me-btn" href="${esc(url)}" target="_blank" rel="noopener">Öffnen ↗</a><button data-cmd="share-off" class="me-btn">Freigabe beenden</button></div>`
        : '<p class="me-hint">Ohne Login lesbar, nur Objekte dieser Map werden angezeigt.</p><button data-cmd="share-on" class="me-wide">🌐 Öffentlichen Link erzeugen</button>'}
        <h4>Map</h4><button data-cmd="delete-map" class="me-wide danger">Map löschen</button>`;
    };
    S.editor = new MapEditor(host, {
      data: m.data, name: m.name, refresh: m.refresh_s, readonly: !isAdmin(),
      startMode: m.id && params.get('edit') !== '1' ? 'view' : 'edit',
      objects: S.tree, status: statusIndexFromTree(),
      fetchStatus: () => api('status').then((j) => j.index),
      renderShare: shareHtml,
      onOpenObject: (b) => {
        if (b.type === 'sensor') openSensor(b.id);
        else if (b.type === 'device') openDevice(b.id);
        else { location.hash = '#/devices'; }
      },
      onSave: async (d) => {
        try {
          const r = await api('map_save', { id: m.id, name: d.name, data: d.data, refresh_s: d.refresh_s });
          toast('Map gespeichert');
          if (!m.id) { m.id = r.id; history.replaceState(null, '', '#/maps/' + r.id + '?edit=1'); }
          m.name = d.name;
          return true;
        } catch (e) { fail(e); return false; }
      },
      onClose: (dirty) => { if (!dirty || confirm('Ungespeicherte Änderungen verwerfen?')) location.hash = '#/maps'; },
      onCommand: async (c) => {
        if (c === 'share-on' || c === 'share-off') {
          try { const r = await api('map_share', { id: m.id, enable: c === 'share-on' }); m.public_token = r.token; S.editor.render(); toast(r.token ? 'Öffentlicher Link erstellt' : 'Freigabe beendet'); } catch (e) { fail(e); }
        }
        if (c === 'delete-map' && confirm(`Map „${m.name}“ wirklich löschen?`)) {
          try { await api('map_delete', { id: m.id }); S.editor.dirty = false; location.hash = '#/maps'; toast('Map gelöscht'); } catch (e) { fail(e); }
        }
      },
    });
    window.onbeforeunload = () => (S.editor && S.editor.dirty ? 'Ungespeicherte Änderungen' : undefined);
  }

  /* ======================================================== Protokoll */
  async function viewLog(el, params) {
    const type = params.get('type') || '';
    el.innerHTML = `<div class="page"><div class="pagehead"><h1>Protokoll</h1>
      <div class="filters">${[['', 'Alle'], ['status', 'Statuswechsel'], ['notify', 'Benachrichtigungen'], ['user', 'Benutzeraktionen']].map(([k, l]) => `<a class="fbtn${type === k ? ' on' : ''}" href="#/log${k ? '?type=' + k : ''}">${l}</a>`).join('')}</div></div>
      <div class="card nopad" id="logBox"><p class="pad muted">Lade …</p></div></div>`;
    const j = await api('events', undefined, { limit: 500, type }).catch(fail);
    if (j && $('#logBox')) $('#logBox').innerHTML = eventsTable(j.events);
  }

  /* ===================================================== Einstellungen */
  async function viewSettings(el, sub) {
    const tabs = [['notifications', 'Benachrichtigungen'], ['users', 'Benutzer'], ['system', 'System'], ['account', 'Mein Konto']];
    el.innerHTML = `<div class="page"><div class="pagehead"><h1>Einstellungen</h1></div>
      <div class="subtabs">${tabs.map(([k, l]) => `<a href="#/settings/${k}" class="${sub === k ? 'on' : ''}">${l}</a>`).join('')}</div><div id="setBox"></div></div>`;
    const box = $('#setBox');
    if (sub === 'notifications') {
      const j = await api('notifications').catch(fail);
      if (!j) return;
      const nt = S.boot.notification_types;
      box.innerHTML = `<div class="card"><div class="cardhead"><h2>Benachrichtigungen</h2>${isAdmin() ? '<button class="btn primary" data-act="notif-edit">+ Hinzufügen</button>' : ''}</div>
        <p class="muted">Wird bei Statuswechseln eines Sensors ausgelöst (Fehler, Warnung, wieder OK). Wie bei PRTG: zur Sicherheit mindestens zwei Wege einrichten.</p>
        ${j.notifications.length ? `<table class="tbl"><thead><tr><th>Name</th><th>Typ</th><th>Auslöser</th><th>Aktiv</th><th></th></tr></thead><tbody>${j.notifications.map((n) => `<tr>
          <td><b>${esc(n.name)}</b></td><td>${esc(nt[n.type] ? nt[n.type].label : n.type)}</td>
          <td>${[n.on_down && '🔴 Fehler', n.on_warning && '🟡 Warnung', n.on_up && '🟢 Wieder OK'].filter(Boolean).join(' · ')}</td><td>${n.enabled ? '✔' : '–'}</td>
          <td class="right nowrap">${isAdmin() ? `<button class="btn sm" data-act="notif-test" data-id="${n.id}">Test</button><button class="btn sm" data-act="notif-edit" data-id="${n.id}">Bearbeiten</button><button class="btn sm danger" data-act="notif-del" data-id="${n.id}">Löschen</button>` : ''}</td></tr>`).join('')}</tbody></table>` : '<p class="empty">Noch keine Benachrichtigung eingerichtet.</p>'}</div>`;
      S.notifs = j.notifications;
    } else if (sub === 'users') {
      const j = await api('users').catch(fail);
      if (!j) return;
      S.users = j.users;
      box.innerHTML = `<div class="card"><div class="cardhead"><h2>Benutzer</h2>${isAdmin() ? '<button class="btn primary" data-act="user-edit">+ Benutzer</button>' : ''}</div>
        <table class="tbl"><thead><tr><th>Benutzer</th><th>Rolle</th><th>Angelegt</th><th></th></tr></thead><tbody>${j.users.map((u) => `<tr><td><b>${esc(u.username)}</b></td><td>${u.role === 'admin' ? 'Administrator' : 'Nur lesen'}</td><td>${dt(u.created_at)}</td>
        <td class="right">${isAdmin() ? `<button class="btn sm" data-act="user-edit" data-id="${u.id}">Bearbeiten</button><button class="btn sm danger" data-act="user-del" data-id="${u.id}">Löschen</button>` : ''}</td></tr>`).join('')}</tbody></table></div>`;
    } else if (sub === 'system') {
      const j = await api('settings').catch(fail);
      if (!j) return;
      const path = location.pathname.replace(/[^/]*$/, '');
      box.innerHTML = `<div class="cols2"><div class="card"><h2>Poller (Probe)</h2>
          <p>Status: ${S.heartbeat && S.now - S.heartbeat < 180 ? '<span class="badge st-up">läuft</span>' : '<span class="badge st-down">läuft nicht</span>'} · letzter Lauf ${ago(S.heartbeat)}</p>
          <p class="muted">Der Poller prüft alle fälligen Sensoren parallel. Er wird per Cron jede Minute gestartet und läuft dann knapp 60 Sekunden:</p>
          <pre class="code">* * * * * www-data php ${esc('/pfad/zur/app')}/cron/poller.php &gt;/dev/null 2&gt;&amp;1</pre>
          <p class="muted small">Datei z. B. <code>/etc/cron.d/reload-monitor</code>. Manueller Test: <code>php cron/poller.php --once --verbose</code></p></div>
        <form class="card" id="sysForm"><h2>Datenhaltung & Leistung</h2>
          <label class="f"><span>Messwerte aufbewahren (Tage)</span><input type="number" name="retention_days" min="1" max="400" value="${j.settings.retention_days}"></label>
          <label class="f"><span>Parallele Prüf-Prozesse</span><input type="number" name="poller_workers" min="1" max="32" value="${j.settings.poller_workers}"></label>
          ${isAdmin() ? '<button class="btn primary">Speichern</button>' : ''}</form></div>
        <div class="card"><h2>HTTP-Push & API</h2><p class="muted">Push-Sensoren erhalten eine eigene URL: <code>${esc(location.origin + path)}push.php?token=…&amp;value=42&amp;status=up&amp;msg=Text</code></p>
        <p class="muted small">Version ${esc(S.boot.version)}</p></div>`;
      $('#sysForm').addEventListener('submit', async (ev) => {
        ev.preventDefault();
        const fd = Object.fromEntries(new FormData(ev.target));
        try { await api('settings_save', fd); toast('Gespeichert'); } catch (e) { fail(e); }
      });
    } else if (sub === 'account') {
      box.innerHTML = `<form class="card narrow" id="pwForm"><h2>Passwort ändern</h2>
        <label class="f"><span>Aktuelles Passwort</span><input type="password" name="old" required autocomplete="current-password"></label>
        <label class="f"><span>Neues Passwort (min. 8 Zeichen)</span><input type="password" name="new" minlength="8" required autocomplete="new-password"></label>
        <button class="btn primary">Ändern</button></form>`;
      $('#pwForm').addEventListener('submit', async (ev) => {
        ev.preventDefault();
        try { await api('password', Object.fromEntries(new FormData(ev.target))); toast('Passwort geändert'); ev.target.reset(); } catch (e) { fail(e); }
      });
    }
  }

  /* ========================================================== Drawer */
  function closeDrawer() {
    $('#drawer').hidden = true;
    S.drawerSensor = null;
    if (S.chart) { S.chart.destroy(); S.chart = null; }
  }

  async function openSensor(id, soft) {
    let j;
    try { j = await api('sensor', undefined, { id }); } catch (e) { fail(e); return; }
    const s = j.sensor;
    const def = S.boot.types[s.type] || { label: s.type, icon: '•' };
    S.drawerSensor = id;
    const st = s.effective;
    const range = S.range || 86400;
    const uptime = s.stats24 && s.stats24.n ? Math.round((s.stats24.ok / s.stats24.n) * 1000) / 10 : null;
    const lim = [['warn_max', 'Warnung über'], ['err_max', 'Fehler über'], ['warn_min', 'Warnung unter'], ['err_min', 'Fehler unter']].filter(([k]) => s[k] !== null).map(([k, l]) => `${l} ${fmt(s[k])}`).join(' · ');
    const pushUrl = s.type === 'push' ? new URL('push.php?token=' + s.push_token, location.href).href : '';
    const inner = $('#drawerInner');
    const wasOpen = !$('#drawer').hidden && soft;
    inner.innerHTML = `
      <div class="dhead st-${st}">
        <div><small>${esc(s.device_name)}${s.host ? ' · ' + esc(s.host) : ''}</small><h2>${esc(def.icon)} ${esc(s.name)}</h2><span class="badge inv">${ST()[st]}${s.status_since ? ' seit ' + dur(s.status_since) : ''}</span>${s.acked && s.ack_msg ? `<div class="ackmsg">✔ ${esc(s.ack_msg)}</div>` : ''}</div>
        <button class="iconbtn light" data-act="close-drawer" aria-label="Schließen">✕</button>
      </div>
      <div class="dbody">
        <div class="dactions">
          <button class="btn sm" data-act="check" data-id="${s.id}">⟳ Jetzt prüfen</button>
          ${s.status === 'down' && !+s.acked ? `<button class="btn sm" data-act="ack" data-id="${s.id}">✔ Bestätigen</button>` : ''}
          ${isAdmin() ? `<button class="btn sm" data-act="pause" data-obj="sensor" data-id="${s.id}" data-p="${+s.paused ? 0 : 1}">${+s.paused ? '▶ Fortsetzen' : '⏸ Pausieren'}</button>
          <button class="btn sm" data-act="edit-sensor" data-id="${s.id}">✎ Bearbeiten</button>
          <button class="btn sm danger" data-act="del-sensor" data-id="${s.id}">Löschen</button>` : ''}
        </div>
        <div class="bigval"><span class="v t-${st}">${s.last_value !== null ? fmt(+s.last_value) : '–'}</span><span class="u">${esc(s.last_unit || '')}</span></div>
        <p class="dmsg">${esc(s.last_message || 'Noch keine Messung')}</p>
        <dl class="facts">
          <dt>Typ</dt><dd>${esc(def.label)}</dd>
          <dt>Intervall</dt><dd>${s.interval_s} s · zuletzt ${ago(s.last_check)}</dd>
          <dt>Verfügbarkeit 24 h</dt><dd>${uptime !== null ? uptime.toLocaleString('de-DE') + ' %' : '–'}</dd>
          <dt>Ø / Min / Max 24 h</dt><dd>${fmt(s.stats24 && s.stats24.avg !== null ? +s.stats24.avg : null)} / ${fmt(s.stats24 && s.stats24.min !== null ? +s.stats24.min : null)} / ${fmt(s.stats24 && s.stats24.max !== null ? +s.stats24.max : null)}</dd>
          ${lim ? `<dt>Grenzwerte</dt><dd>${lim}</dd>` : ''}
          ${Object.entries(s.channels || {}).map(([k, v]) => `<dt>${esc(k)}</dt><dd>${esc(fmt(v))}</dd>`).join('')}
        </dl>
        ${pushUrl ? `<div class="pushbox"><b>Push-URL</b><input readonly value="${esc(pushUrl)}&value=42&status=up&msg=OK" onclick="this.select()"><small>Parameter: <code>value</code> (Zahl), <code>status</code> up|warning|down, <code>msg</code> Text. Bleibt die Meldung länger als das Intervall aus, geht der Sensor auf Fehler.</small></div>` : ''}
        <div class="chartbox"><div class="ranges">${[[3600, '1 h'], [86400, '24 h'], [604800, '7 T'], [2592000, '30 T']].map(([r, l]) => `<button class="fbtn${r === range ? ' on' : ''}" data-act="range" data-r="${r}">${l}</button>`).join('')}</div><div class="chartwrap"><canvas id="sensorChart"></canvas></div></div>
        <h3>Ereignisse</h3>${eventsTable(s.events || [])}
      </div>`;
    $('#drawer').hidden = false;
    if (!wasOpen) $('#drawer').scrollTop = 0;
    drawChart(s, range);
  }

  async function drawChart(s, range) {
    const j = await api('history', undefined, { id: s.id, range }).catch(() => null);
    const cv = $('#sensorChart');
    if (!j || !cv || typeof Chart === 'undefined') return;
    if (S.chart) S.chart.destroy();
    const css = getComputedStyle(document.documentElement);
    const ink = css.getPropertyValue('--muted').trim() || '#64748b';
    const grid = css.getPropertyValue('--line').trim() || '#e5e7eb';
    const pts = j.points;
    const lineCol = '#1e6fd9';
    const ds = [{
      label: s.name, data: pts.map((p) => ({ x: p[0] * 1000, y: p[1] })), borderColor: lineCol, backgroundColor: 'rgba(30,111,217,.10)',
      fill: true, tension: 0.25, pointRadius: 0, borderWidth: 2, spanGaps: false,
      segment: { borderColor: (c) => { const p = pts[c.p1DataIndex]; return p && p[4] === 3 ? SC().down : p && p[4] === 2 ? SC().warning : lineCol; } },
    }];
    const limLines = [['warn_max', SC().warning], ['err_max', SC().down], ['warn_min', SC().warning], ['err_min', SC().down]].filter(([k]) => s[k] !== null);
    for (const [k, c] of limLines) {
      ds.push({ label: k.startsWith('warn') ? 'Warngrenze' : 'Fehlergrenze', data: pts.length ? [{ x: pts[0][0] * 1000, y: +s[k] }, { x: pts[pts.length - 1][0] * 1000, y: +s[k] }] : [], borderColor: c, borderDash: [5, 4], borderWidth: 1.2, pointRadius: 0, fill: false });
    }
    const tf = range <= 86400 ? { hour: '2-digit', minute: '2-digit' } : { day: '2-digit', month: '2-digit', hour: '2-digit' };
    S.chart = new Chart(cv, {
      type: 'line', data: { datasets: ds },
      options: {
        responsive: true, maintainAspectRatio: false, animation: false, parsing: false,
        interaction: { mode: 'nearest', axis: 'x', intersect: false },
        plugins: { legend: { display: limLines.length > 0, labels: { color: ink, boxWidth: 12 } }, tooltip: { callbacks: { title: (it) => new Date(it[0].parsed.x).toLocaleString('de-DE'), label: (it) => `${it.dataset.label}: ${fmt(it.parsed.y)} ${s.last_unit || ''}` } } },
        scales: {
          x: { type: 'linear', min: Date.now() - range * 1000, max: Date.now(), ticks: { color: ink, maxTicksLimit: 7, callback: (v) => new Date(v).toLocaleString('de-DE', tf) }, grid: { color: grid } },
          y: { beginAtZero: true, ticks: { color: ink }, grid: { color: grid }, title: { display: !!s.last_unit, text: s.last_unit || '', color: ink } },
        },
      },
    });
    if (!pts.length) cv.parentElement.insertAdjacentHTML('beforeend', '<p class="chart-empty">Noch keine Messwerte im Zeitraum</p>');
  }

  function openDevice(id) {
    const r = findDevice(id);
    if (!r) return;
    const d = r.d;
    $('#drawerInner').innerHTML = `<div class="dhead st-${d.status}"><div><small>${esc(r.path.filter((x) => x.kind !== 'root').map((x) => x.name).join(' › '))}</small><h2>${RM_ICONS.html(d.icon, 22)} ${esc(d.name)}</h2><span class="badge inv">${ST()[d.status]}</span></div><button class="iconbtn light" data-act="close-drawer">✕</button></div>
      <div class="dbody"><p class="muted">${esc(d.host || '')}</p>${d.notes ? `<p class="notes">${esc(d.notes)}</p>` : ''}
      <table class="tbl">${d.sensors.map((s) => `<tr class="clickable" data-sensor="${s.id}"><td>${dot(s.status)}</td><td>${esc(s.icon)} ${esc(s.name)}<div class="sub">${esc(s.message || '')}</div></td><td class="right nowrap">${esc(fmtVal(s))}</td></tr>`).join('')}</table></div>`;
    S.drawerSensor = null;
    $('#drawer').hidden = false;
  }

  /* =========================================================== Modals */
  function modal(html, onSubmit, opts = {}) {
    const m = $('#modal');
    m.className = 'modal' + (opts.wide ? ' wide' : '');
    m.innerHTML = html;
    $('#modalBack').hidden = false;
    const form = $('form', m);
    if (form) {
      form.addEventListener('submit', async (ev) => {
        ev.preventDefault();
        const btn = $('button[type=submit], .btn.primary', form);
        if (btn) btn.disabled = true;
        try { if ((await onSubmit(form)) !== false) closeModal(); } catch (e) { fail(e); }
        if (btn) btn.disabled = false;
      });
      const first = $('input:not([type=hidden]),select,textarea', form);
      if (first) setTimeout(() => first.focus(), 30);
    }
    return m;
  }
  function closeModal() { $('#modalBack').hidden = true; $('#modal').innerHTML = ''; }

  const opt = (v, l, sel) => `<option value="${esc(v)}"${String(sel) === String(v) ? ' selected' : ''}>${esc(l)}</option>`;
  function fieldHtml(f, val) {
    const v = val !== undefined ? val : f.default;
    const name = `cfg_${f.key}`;
    if (f.type === 'bool') return `<label class="f check"><input type="checkbox" name="${name}"${v ? ' checked' : ''}> ${esc(f.label)}</label>`;
    if (f.type === 'select') return `<label class="f"><span>${esc(f.label)}</span><select name="${name}">${f.options.map((o) => opt(o, o, v)).join('')}</select></label>`;
    return `<label class="f"><span>${esc(f.label)}</span><input name="${name}" type="${f.type === 'number' ? 'number' : 'text'}" value="${esc(v ?? '')}"${f.type === 'number' ? ' step="any"' : ''}></label>`;
  }
  function readCfg(form, fields) {
    const c = {};
    for (const f of fields) {
      const el = form.elements['cfg_' + f.key];
      if (!el) continue;
      c[f.key] = f.type === 'bool' ? el.checked : el.value;
    }
    return c;
  }

  function groupForm(id, parentId) {
    const g = id ? findGroup(id) : null;
    const parent = g ? (g.path[g.path.length - 1] || {}).id : (parentId || 2);
    const groups = allGroups().filter((x) => x.id !== id);
    modal(`<form><h2>${id ? 'Gruppe bearbeiten' : 'Neue Gruppe'}</h2>
      <label class="f"><span>Name</span><input name="name" required value="${esc(g ? g.g.name : '')}"></label>
      ${g && g.g.kind === 'probe' ? '' : `<label class="f"><span>Übergeordnet</span><select name="parent_id">${groups.map((x) => opt(x.id, x.name, parent)).join('')}</select></label>`}
      <div class="mfoot"><button type="button" class="btn" data-act="close-modal">Abbrechen</button><button class="btn primary">Speichern</button></div></form>`,
    async (f) => { await api('group_save', { id, name: f.name.value, parent_id: f.parent_id ? f.parent_id.value : null }); toast('Gruppe gespeichert'); await reload(); });
  }

  function deviceForm(id, groupId) {
    const r = id ? findDevice(id) : null;
    const d = r ? r.d : { name: '', host: '', icon: 'server', notes: '', depends_on: null };
    const gid = r ? r.path[r.path.length - 1].id : (groupId || 2);
    const icons = Object.entries(RM_ICONS.icons);
    const sensors = allSensors();
    modal(`<form class="devform"><h2>${id ? 'Gerät bearbeiten' : 'Neues Gerät'}</h2>
      <div class="grid2"><label class="f"><span>Name</span><input name="name" required value="${esc(d.name)}" placeholder="z. B. Core-Switch"></label>
      <label class="f"><span>Host / IP-Adresse</span><div class="row"><input name="host" value="${esc(d.host)}" placeholder="192.168.1.1 oder server.example.com">${id ? '' : '<button type="button" class="btn" data-act="discover">🔍 Erkennen</button>'}</div></label></div>
      <div class="grid2"><label class="f"><span>Gruppe</span><select name="group_id">${allGroups().map((x) => opt(x.id, x.name, gid)).join('')}</select></label>
      <label class="f"><span>Abhängig von (Master-Sensor)</span><select name="depends_on"><option value="">— keine —</option>${sensors.map((s) => opt(s.id, s.name, d.depends_on)).join('')}</select></label></div>
      <div class="f"><span>Icon</span><div class="iconpick">${icons.map(([k, v]) => `<label title="${esc(v.label)}"><input type="radio" name="icon" value="${k}"${d.icon === k ? ' checked' : ''}>${RM_ICONS.html(k, 26)}</label>`).join('')}</div></div>
      <label class="f"><span>Notizen</span><textarea name="notes" rows="2">${esc(d.notes)}</textarea></label>
      ${id ? '' : `<div class="f"><span>Sensoren anlegen</span><div id="discList" class="disclist">
        <label class="check"><input type="checkbox" data-sensor='{"type":"ping","name":"Ping","config":{}}' checked> 📶 Ping</label>
        <p class="muted small">Tipp: „Erkennen“ prüft gängige Ports (SSH, HTTP/S, RDP, SMB, DB …) und schlägt passende Sensoren vor.</p></div></div>`}
      <div class="mfoot"><button type="button" class="btn" data-act="close-modal">Abbrechen</button><button class="btn primary">Speichern</button></div></form>`,
    async (f) => {
      const add = $$('#discList input[type=checkbox]:checked', f).map((c) => JSON.parse(c.dataset.sensor));
      const icon = (f.querySelector('input[name=icon]:checked') || {}).value || 'server';
      const r2 = await api('device_save', { id, name: f.name.value, host: f.host.value.trim(), group_id: f.group_id.value, depends_on: f.depends_on.value, notes: f.notes.value, icon, add_sensors: add });
      toast(id ? 'Gerät gespeichert' : 'Gerät angelegt');
      await reload();
      if (!id && add.length) {
        // neue Sensoren sofort einmal prüfen lassen
        const nd = findDevice(r2.id);
        if (nd) for (const s of nd.d.sensors) api('sensor_check', { id: s.id }).catch(() => {});
        setTimeout(reload, 4000);
      }
    }, { wide: true });
  }

  async function discover(btn) {
    const f = btn.closest('form');
    const host = f.host.value.trim();
    if (!host) { toast('Erst Host/IP eingeben', 'err'); return; }
    btn.disabled = true; btn.textContent = '⏳ Suche …';
    try {
      const j = await api('discover', { host });
      const list = $('#discList', f);
      list.innerHTML = j.found.length ? j.found.map((s) => `<label class="check"><input type="checkbox" checked data-sensor='${esc(JSON.stringify({ type: s.type, name: s.name, config: s.config }))}'> ${esc(S.boot.types[s.type].icon)} ${esc(s.name)} <small class="muted">${esc(s.info)}</small></label>`).join('')
        : '<p class="muted">Nichts gefunden (Host antwortet nicht auf Ping / gängige Ports). Sensoren kannst du später manuell anlegen.</p>';
      if (!f.name.value) f.name.value = host;
    } catch (e) { fail(e); }
    btn.disabled = false; btn.textContent = '🔍 Erkennen';
  }

  async function sensorForm(id, deviceId, presetType) {
    let s = null;
    if (id) s = (await api('sensor', undefined, { id })).sensor;
    const types = S.boot.types;
    if (!s && !presetType) {
      modal(`<div><h2>Sensor hinzufügen</h2><p class="muted">Sensortyp wählen:</p><div class="typegrid">${Object.entries(types).map(([k, t]) => `<button class="typecard" data-act="pick-type" data-type="${k}" data-dev="${deviceId}"><span class="ti">${t.icon}</span><b>${esc(t.label)}</b><small>${esc(t.desc)}</small></button>`).join('')}</div>
        <div class="mfoot"><button type="button" class="btn" data-act="close-modal">Abbrechen</button></div></div>`, null, { wide: true });
      return;
    }
    const type = s ? s.type : presetType;
    const t = types[type];
    const cfg = s ? s.config : {};
    const lim = (k) => (s ? (s[k] ?? '') : (t.limits[k] ?? ''));
    modal(`<form><h2>${t.icon} ${s ? 'Sensor bearbeiten' : t.label + '-Sensor anlegen'}</h2><p class="muted">${esc(t.desc)}</p>
      <div class="grid2"><label class="f"><span>Name</span><input name="name" value="${esc(s ? s.name : '')}" placeholder="${esc(t.label)}"></label>
      <label class="f"><span>Prüfintervall</span><select name="interval_s">${[[10, '10 Sekunden'], [30, '30 Sekunden'], [60, '1 Minute'], [120, '2 Minuten'], [300, '5 Minuten'], [600, '10 Minuten'], [900, '15 Minuten'], [3600, '1 Stunde'], [21600, '6 Stunden'], [86400, '24 Stunden']].map(([v, l]) => opt(v, l, s ? s.interval_s : t.interval)).join('')}</select></label></div>
      ${t.fields.map((f) => fieldHtml(f, cfg[f.key])).join('')}
      <fieldset class="limits"><legend>Grenzwerte (${esc(t.unit || 'Wert')}) – leer = aus</legend><div class="grid4">
        <label class="f"><span>Warnung über</span><input name="warn_max" type="number" step="any" value="${lim('warn_max')}"></label>
        <label class="f"><span>Fehler über</span><input name="err_max" type="number" step="any" value="${lim('err_max')}"></label>
        <label class="f"><span>Warnung unter</span><input name="warn_min" type="number" step="any" value="${lim('warn_min')}"></label>
        <label class="f"><span>Fehler unter</span><input name="err_min" type="number" step="any" value="${lim('err_min')}"></label></div></fieldset>
      <div class="mfoot"><button type="button" class="btn" data-act="close-modal">Abbrechen</button><button class="btn primary">Speichern</button></div></form>`,
    async (f) => {
      const data = { id, device_id: deviceId, type, name: f.name.value, interval_s: f.interval_s.value, config: readCfg(f, t.fields) };
      for (const k of ['warn_max', 'err_max', 'warn_min', 'err_min']) data[k] = f[k].value;
      const r = await api('sensor_save', data);
      toast('Sensor gespeichert');
      api('sensor_check', { id: r.id }).then(reload).catch(() => {});
      await reload();
      if (S.drawerSensor === r.id) openSensor(r.id, true);
    }, { wide: true });
  }

  function notifForm(id) {
    const n = id ? S.notifs.find((x) => x.id === id) : { name: '', type: 'email', config: {}, on_down: 1, on_warning: 0, on_up: 1, enabled: 1 };
    const nt = S.boot.notification_types;
    const fieldsFor = (type, cfg) => nt[type].fields.map((f) => `<label class="f"><span>${esc(f.label)}</span><input name="cfg_${f.key}" value="${esc(cfg[f.key] || '')}"></label>`).join('');
    const m = modal(`<form><h2>${id ? 'Benachrichtigung bearbeiten' : 'Neue Benachrichtigung'}</h2>
      <div class="grid2"><label class="f"><span>Name</span><input name="name" required value="${esc(n.name)}" placeholder="z. B. Admin per Telegram"></label>
      <label class="f"><span>Typ</span><select name="type">${Object.entries(nt).map(([k, t]) => opt(k, t.label, n.type)).join('')}</select></label></div>
      <div id="nfields">${fieldsFor(n.type, n.config)}</div>
      <div class="f"><span>Auslösen bei</span><div class="row wrap">
        <label class="check"><input type="checkbox" name="on_down"${+n.on_down ? ' checked' : ''}> 🔴 Fehler</label>
        <label class="check"><input type="checkbox" name="on_warning"${+n.on_warning ? ' checked' : ''}> 🟡 Warnung</label>
        <label class="check"><input type="checkbox" name="on_up"${+n.on_up ? ' checked' : ''}> 🟢 Wieder OK</label>
        <label class="check"><input type="checkbox" name="enabled"${+n.enabled ? ' checked' : ''}> Aktiv</label></div></div>
      <div class="mfoot"><button type="button" class="btn" data-act="close-modal">Abbrechen</button><button class="btn primary">Speichern</button></div></form>`,
    async (f) => {
      const cfg = {};
      for (const fl of nt[f.type.value].fields) cfg[fl.key] = f['cfg_' + fl.key].value;
      await api('notification_save', { id, name: f.name.value, type: f.type.value, config: cfg, on_down: f.on_down.checked, on_warning: f.on_warning.checked, on_up: f.on_up.checked, enabled: f.enabled.checked });
      toast('Gespeichert'); route();
    });
    $('select[name=type]', m).addEventListener('change', (ev) => { $('#nfields', m).innerHTML = fieldsFor(ev.target.value, {}); });
  }

  function userForm(id) {
    const u = id ? S.users.find((x) => x.id === id) : { username: '', role: 'viewer' };
    modal(`<form><h2>${id ? 'Benutzer bearbeiten' : 'Neuer Benutzer'}</h2>
      <label class="f"><span>Benutzername</span><input name="username" required value="${esc(u.username)}"></label>
      <label class="f"><span>Rolle</span><select name="role">${opt('admin', 'Administrator (alles)', u.role)}${opt('viewer', 'Nur lesen (+ Alarme bestätigen)', u.role)}</select></label>
      <label class="f"><span>${id ? 'Neues Passwort (leer = unverändert)' : 'Passwort (min. 8 Zeichen)'}</span><input name="password" type="password" ${id ? '' : 'required minlength="8"'} autocomplete="new-password"></label>
      <div class="mfoot"><button type="button" class="btn" data-act="close-modal">Abbrechen</button><button class="btn primary">Speichern</button></div></form>`,
    async (f) => { await api('user_save', { id, username: f.username.value, role: f.role.value, password: f.password.value }); toast('Gespeichert'); route(); });
  }

  function popMenu(anchor, items) {
    $$('.popmenu').forEach((p) => p.remove());
    const m = document.createElement('div');
    m.className = 'popmenu';
    m.innerHTML = items.map(([act, label, extra]) => (act === '-' ? '<hr>' : `<button data-act="${act}" ${extra || ''}>${label}</button>`)).join('');
    document.body.appendChild(m);
    const r = anchor.getBoundingClientRect();
    m.style.top = (r.bottom + window.scrollY + 4) + 'px';
    m.style.left = Math.max(8, Math.min(window.innerWidth - m.offsetWidth - 8, r.right - m.offsetWidth)) + 'px';
  }

  async function reload() { await loadTree(); route(true); }

  /* ======================================================= Aktionen */
  document.addEventListener('click', async (ev) => {
    const a = ev.target.closest('[data-act]');
    if (!ev.target.closest('.popmenu') && !ev.target.closest('[data-act$="-menu"]')) $$('.popmenu').forEach((p) => p.remove());
    if (!ev.target.closest('.usermenu')) $('#userMenu').hidden = true;
    if (!a) {
      const sens = ev.target.closest('[data-sensor]');
      if (sens && !ev.target.closest('.me')) { ev.preventDefault(); openSensor(+sens.dataset.sensor); return; }
      const g = ev.target.closest('[data-goto]');
      if (g) location.hash = g.dataset.goto;
      const fb = ev.target.closest('[data-filter]');
      if (fb) { S.filter = fb.dataset.filter; history.replaceState(null, '', '#/devices' + (S.filter ? '?f=' + S.filter : '')); route(true); }
      if (ev.target === $('#modalBack')) closeModal();
      return;
    }
    const id = a.dataset.id ? +a.dataset.id : null;
    const act = a.dataset.act;
    if (a.tagName === 'A') ev.preventDefault();
    try {
      switch (act) {
        case 'close-drawer': closeDrawer(); break;
        case 'close-modal': closeModal(); break;
        case 'add-group': groupForm(null); break;
        case 'add-device': deviceForm(null, a.dataset.group ? +a.dataset.group : null); break;
        case 'add-sensor': sensorForm(null, id); break;
        case 'pick-type': sensorForm(null, +a.dataset.dev, a.dataset.type); break;
        case 'edit-sensor': sensorForm(id); break;
        case 'discover': discover(a); break;
        case 'toggle-group': {
          const g = findGroup(id).g;
          g.collapsed = !g.collapsed;
          route(true);
          api('group_toggle', { id, collapsed: g.collapsed }).catch(() => {});
          break;
        }
        case 'device-menu': {
          const d = findDevice(id).d;
          popMenu(a, [['add-sensor', '+ Sensor hinzufügen', `data-id="${id}"`], ['edit-device', '✎ Bearbeiten', `data-id="${id}"`], ['clone-device', '⧉ Klonen', `data-id="${id}"`],
            ['pause', d.paused ? '▶ Fortsetzen' : '⏸ Pausieren', `data-obj="device" data-id="${id}" data-p="${d.paused ? 0 : 1}"`], ['check-device', '⟳ Alle Sensoren prüfen', `data-id="${id}"`], ['-'], ['del-device', '🗑 Löschen', `data-id="${id}" class="danger"`]]);
          break;
        }
        case 'group-menu': {
          const g = findGroup(id).g;
          popMenu(a, [['add-device', '+ Gerät hinzufügen', `data-group="${id}"`], ['add-subgroup', '+ Untergruppe', `data-id="${id}"`], ['edit-group', '✎ Bearbeiten', `data-id="${id}"`],
            ['pause', g.paused ? '▶ Fortsetzen' : '⏸ Pausieren', `data-obj="group" data-id="${id}" data-p="${g.paused ? 0 : 1}"`], ...(g.kind === 'probe' ? [] : [['-'], ['del-group', '🗑 Löschen', `data-id="${id}" class="danger"`]])]);
          break;
        }
        case 'add-subgroup': groupForm(null, id); break;
        case 'edit-group': groupForm(id); break;
        case 'edit-device': deviceForm(id); break;
        case 'clone-device': {
          const host = prompt('Host/IP des neuen Geräts (leer = gleich):', findDevice(id).d.host);
          if (host === null) break;
          await api('device_clone', host ? { id, host } : { id }); toast('Gerät geklont'); await reload();
          break;
        }
        case 'check-device': for (const s of findDevice(id).d.sensors) await api('sensor_check', { id: s.id }).catch(() => {}); toast('Geprüft'); await reload(); break;
        case 'del-device': if (confirm(`Gerät „${findDevice(id).d.name}“ inkl. aller Sensoren und Messwerte löschen?`)) { await api('device_delete', { id }); toast('Gelöscht'); closeDrawer(); await reload(); } break;
        case 'del-group': if (confirm(`Gruppe „${findGroup(id).g.name}“ inkl. ALLER enthaltenen Geräte löschen?`)) { await api('group_delete', { id }); toast('Gelöscht'); await reload(); } break;
        case 'del-sensor': if (confirm('Sensor inkl. Messwerten löschen?')) { await api('sensor_delete', { id }); toast('Gelöscht'); closeDrawer(); await reload(); } break;
        case 'pause': await api('pause', { object: a.dataset.obj, id, paused: a.dataset.p === '1' }); toast(a.dataset.p === '1' ? 'Pausiert' : 'Fortgesetzt'); await reload(); if (S.drawerSensor) openSensor(S.drawerSensor, true); break;
        case 'check': {
          a.disabled = true;
          const r = await api('sensor_check', { id });
          toast(`${ST()[r.result.status] || r.result.status}: ${r.result.message}`, r.result.status === 'down' ? 'err' : 'ok');
          await reload(); if (S.drawerSensor === id) openSensor(id, true);
          break;
        }
        case 'ack': {
          const msg = prompt('Kommentar zur Bestätigung (z. B. „Wartung, kümmere mich“):', '');
          if (msg === null) break;
          await api('sensor_ack', { id, message: msg }); toast('Alarm bestätigt'); await reload(); if (S.drawerSensor === id) openSensor(id, true);
          break;
        }
        case 'range': S.range = +a.dataset.r; if (S.drawerSensor) openSensor(S.drawerSensor, true); break;
        case 'new-map': location.hash = '#/maps/new'; break;
        case 'notif-edit': notifForm(id); break;
        case 'notif-del': if (confirm('Benachrichtigung löschen?')) { await api('notification_delete', { id }); route(); } break;
        case 'notif-test': { const r = await fetch('api.php?action=notification_test', { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF }, body: JSON.stringify({ id }) }).then((x) => x.json()); toast(r.ok ? 'Testnachricht gesendet' : r.error, r.ok ? 'ok' : 'err'); break; }
        case 'user-edit': userForm(id); break;
        case 'user-del': if (confirm('Benutzer löschen?')) { await api('user_delete', { id }); route(); } break;
        case 'clear-search': S.search = ''; $('#globalSearch').value = ''; route(true); break;
        default: break;
      }
    } catch (e) { fail(e); }
  });

  // Gerät per Drag & Drop in andere Gruppe verschieben
  document.addEventListener('dragstart', (ev) => {
    const d = ev.target.closest && ev.target.closest('.devrow[draggable="true"]');
    if (d) { ev.dataTransfer.setData('text/x-device', d.dataset.device); ev.dataTransfer.effectAllowed = 'move'; }
  });
  document.addEventListener('dragover', (ev) => {
    const g = ev.target.closest && ev.target.closest('[data-drop-group]');
    if (g && [...ev.dataTransfer.types].includes('text/x-device')) { ev.preventDefault(); g.classList.add('dropping'); }
  });
  document.addEventListener('dragleave', (ev) => { const g = ev.target.closest && ev.target.closest('[data-drop-group]'); if (g) g.classList.remove('dropping'); });
  document.addEventListener('drop', async (ev) => {
    const g = ev.target.closest && ev.target.closest('[data-drop-group]');
    const did = ev.dataTransfer.getData('text/x-device');
    if (!g || !did) return;
    ev.preventDefault();
    g.classList.remove('dropping');
    try { await api('device_move', { id: +did, group_id: +g.dataset.dropGroup }); toast('Gerät verschoben'); await reload(); } catch (e) { fail(e); }
  });

  document.addEventListener('keydown', (ev) => {
    if (ev.key === 'Escape') { if (!$('#modalBack').hidden) closeModal(); else if (!$('#drawer').hidden) closeDrawer(); }
  });

  $('#userBtn').addEventListener('click', () => { $('#userMenu').hidden = !$('#userMenu').hidden; });
  $('#globalSearch').addEventListener('input', (ev) => {
    S.search = ev.target.value.trim();
    if (S.view !== 'devices') location.hash = '#/devices'; else route(true);
  });

  window.addEventListener('hashchange', () => {
    if (S.editor && S.editor.dirty && !location.hash.startsWith('#/maps/') && !confirm('Ungespeicherte Änderungen an der Map verwerfen?')) { history.back(); return; }
    window.onbeforeunload = null;
    closeDrawer();
    route();
  });

  /* ============================================================ Start */
  (async function init() {
    try {
      S.boot = await api('bootstrap');
      await loadTree();
      route();
      scheduleRefresh();
    } catch (e) { $('#view').innerHTML = `<div class="page"><div class="alert">Fehler beim Laden: ${esc(e.message)}</div></div>`; }
  })();
})();
