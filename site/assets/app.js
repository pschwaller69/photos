(() => {
  'use strict';
  const $ = (s) => document.querySelector(s);
  const form = $('#f');
  const grid = $('#grid');
  const MOIS = ['janv.', 'févr.', 'mars', 'avr.', 'mai', 'juin', 'juil.', 'août', 'sept.', 'oct.', 'nov.', 'déc.'];
  const FILTERS = ['y1', 'y2', 'month', 'camera', 'lens', 'rating', 'orient', 'folder', 'sort', 'gps', 'ia', 'day'];

  let items = [];
  let page = 0;
  let total = 0;
  let loading = false;
  let done = false;
  let reqId = 0;
  let current = -1;

  // ---------- état <-> formulaire <-> URL ----------
  function readForm() {
    const p = new URLSearchParams();
    const q = form.q.value.trim();
    if (q) p.set('q', q);
    for (const n of FILTERS) {
      const el = form.elements[n];
      if (!el) continue;
      const v = el.type === 'checkbox' ? (el.checked ? el.value : '') : el.value.trim();
      if (v) p.set(n, v);
    }
    return p;
  }

  function writeForm(p) {
    form.q.value = p.get('q') || '';
    for (const n of FILTERS) {
      const el = form.elements[n];
      if (!el) continue;
      if (el.type === 'checkbox') el.checked = p.get(n) === el.value;
      else el.value = p.get(n) || '';
    }
  }

  function updateBadges(p) {
    const n = FILTERS.filter((k) => p.has(k) && k !== 'sort').length;
    $('#nf').textContent = n ? `(${n})` : '';
    const parts = [];
    if (p.get('day')) parts.push(`le ${fmtDate(p.get('day'), false)}`);
    $('#active').innerHTML = parts.length
      ? ` · ${esc(parts.join(', '))} <button class="link" id="clearDay">retirer</button>`
      : '';
    const cd = $('#clearDay');
    if (cd) cd.onclick = () => { form.day.value = ''; run(true); };
  }

  // ---------- chargement ----------
  function run(push) {
    const p = readForm();
    const qs = p.toString();
    const url = qs ? `?${qs}` : location.pathname;
    if (push) history.pushState(null, '', url);
    else history.replaceState(null, '', url);
    updateBadges(p);
    items = [];
    page = 0;
    done = false;
    grid.innerHTML = '';
    $('#empty').hidden = true;
    loadMore();
  }

  async function loadMore() {
    if (loading || done) return;
    loading = true;
    const my = ++reqId;
    const p = readForm();
    p.set('page', String(page + 1));
    try {
      const r = await fetch(`search.php?${p}`, { credentials: 'same-origin' });
      if (r.status === 401) { location.href = 'login.php'; return; }
      const data = await r.json();
      if (my !== reqId) return; // une recherche plus récente a été lancée
      if (data.error) throw new Error(data.error);
      page = data.page;
      total = data.total;
      if (data.years) renderYears(data.years);
      const start = items.length;
      items.push(...data.items);
      renderTiles(data.items, start);
      done = items.length >= total || data.items.length === 0;
      $('#count').textContent = total === 0 ? '' : `${total.toLocaleString('fr-FR')} photo${total > 1 ? 's' : ''}`;
      $('#empty').hidden = total !== 0;
    } catch (e) {
      $('#count').textContent = `Erreur : ${e.message}`;
    } finally {
      if (my === reqId) loading = false;
    }
    // remplit l'écran si nécessaire
    requestAnimationFrame(() => {
      if (!done && $('#sentinel').getBoundingClientRect().top < innerHeight + 600) loadMore();
    });
  }

  function renderTiles(list, start) {
    const frag = document.createDocumentFragment();
    list.forEach((it, i) => {
      const a = document.createElement('a');
      a.className = 'tile';
      a.href = `img.php?uid=${it.uid}&s=m`;
      a.dataset.i = String(start + i);
      a.title = it.t || '';
      a.innerHTML = `<img loading="lazy" decoding="async" src="img.php?uid=${it.uid}" alt="${esc(it.t || '')}">`
        + (it.r > 0 ? `<span class="stars">${'★'.repeat(it.r)}</span>` : '');
      frag.appendChild(a);
    });
    grid.appendChild(frag);
    layout();
  }

  // Mise en page « justifiée » : chaque ligne remplit toute la largeur, hauteurs proches de la cible.
  function layout() {
    const W = grid.clientWidth - parseFloat(getComputedStyle(grid).paddingLeft) * 2;
    const gap = innerWidth < 800 ? 3 : 4;
    const target = innerWidth < 600 ? 120 : innerWidth < 1200 ? 200 : 230;
    const tiles = grid.children;
    let row = [];
    let sum = 0;
    const place = (rowItems, h) => {
      for (const [el, r] of rowItems) {
        el.style.width = `${Math.floor(r * h)}px`;
        el.style.height = `${Math.floor(h)}px`;
      }
    };
    for (let i = 0; i < tiles.length; i++) {
      const it = items[i];
      const r = Math.min(Math.max(it.w / it.h, 0.4), 4);
      row.push([tiles[i], r]);
      sum += r;
      const avail = W - gap * (row.length - 1);
      if (sum * target >= avail) {
        place(row, avail / sum);
        row = [];
        sum = 0;
      }
    }
    if (row.length) place(row, target);
  }
  let rt;
  addEventListener('resize', () => { clearTimeout(rt); rt = setTimeout(layout, 120); });

  function renderYears(years) {
    const nav = $('#years');
    if (!years.length) { nav.innerHTML = ''; return; }
    const y1 = form.y1.value; const y2 = form.y2.value;
    const max = Math.max(...years.map((y) => y[1]));
    nav.innerHTML = years.map(([y, n]) => {
      const on = y1 && y2 && String(y) === y1 && y1 === y2;
      const hgt = 6 + Math.round((n / max) * 26);
      return `<button type="button" class="yr${on ? ' on' : ''}" data-y="${y}" title="${n.toLocaleString('fr-FR')} photos">`
        + `<span class="bar" style="height:${hgt}px"></span><span>${y}</span></button>`;
    }).join('');
  }

  $('#years').addEventListener('click', (e) => {
    const b = e.target.closest('.yr');
    if (!b) return;
    const y = b.dataset.y;
    const already = form.y1.value === y && form.y2.value === y;
    form.y1.value = already ? '' : y;
    form.y2.value = already ? '' : y;
    run(true);
  });

  // ---------- événements du formulaire ----------
  let t;
  form.q.addEventListener('input', () => { clearTimeout(t); t = setTimeout(() => run(false), 350); });
  form.q.addEventListener('keydown', (e) => { if (e.key === 'Enter') { clearTimeout(t); run(true); } });
  form.addEventListener('change', (e) => { if (e.target !== form.q) run(true); });
  $('#reset').onclick = () => { writeForm(new URLSearchParams()); run(true); };
  $('#toggleFilters').onclick = (e) => {
    const f = $('#filters');
    f.hidden = !f.hidden;
    e.currentTarget.setAttribute('aria-expanded', String(!f.hidden));
  };
  addEventListener('popstate', () => { writeForm(new URLSearchParams(location.search)); run(false); });

  new IntersectionObserver((en) => { if (en[0].isIntersecting) loadMore(); }, { rootMargin: '800px' })
    .observe($('#sentinel'));

  // ---------- visionneuse ----------
  const lb = $('#lb');
  grid.addEventListener('click', (e) => {
    const a = e.target.closest('.tile');
    if (!a || e.ctrlKey || e.metaKey) return;
    e.preventDefault();
    openAt(Number(a.dataset.i));
  });

  async function openAt(i) {
    if (i < 0 || i >= items.length) return;
    current = i;
    const it = items[i];
    lb.hidden = false;
    document.body.classList.add('noscroll');
    const img = $('#lbImg');
    img.src = `img.php?uid=${it.uid}`; // miniature d'abord (déjà en cache), puis l'aperçu
    const big = new Image();
    big.onload = () => { if (current === i) img.src = big.src; };
    big.src = `img.php?uid=${it.uid}&s=m`;
    img.alt = it.t || '';
    $('#lbInfo').innerHTML = '<p class="muted">Chargement…</p>';
    if (i >= items.length - 5) loadMore();
    try {
      const r = await fetch(`photo.php?uid=${it.uid}`, { credentials: 'same-origin' });
      const p = await r.json();
      if (current === i) $('#lbInfo').innerHTML = infoHtml(p);
    } catch (e) {
      $('#lbInfo').innerHTML = `<p class="err">${esc(e.message)}</p>`;
    }
  }

  function closeLb() {
    lb.hidden = true;
    current = -1;
    document.body.classList.remove('noscroll');
  }

  $('#lbClose').onclick = closeLb;
  $('#lbPrev').onclick = () => openAt(current - 1);
  $('#lbNext').onclick = () => openAt(current + 1);
  lb.addEventListener('click', (e) => { if (e.target === lb || e.target.classList.contains('lb-img')) closeLb(); });
  addEventListener('keydown', (e) => {
    if (lb.hidden) {
      if (e.key === '/' && document.activeElement !== form.q) { e.preventDefault(); form.q.focus(); }
      return;
    }
    if (e.key === 'Escape') closeLb();
    if (e.key === 'ArrowLeft') openAt(current - 1);
    if (e.key === 'ArrowRight') openAt(current + 1);
  });
  // balayage sur mobile
  let sx = null;
  lb.addEventListener('touchstart', (e) => { sx = e.touches[0].clientX; }, { passive: true });
  lb.addEventListener('touchend', (e) => {
    if (sx === null) return;
    const dx = e.changedTouches[0].clientX - sx;
    if (Math.abs(dx) > 50) openAt(current + (dx < 0 ? 1 : -1));
    sx = null;
  });

  $('#lbInfo').addEventListener('click', (e) => {
    const tag = e.target.closest('[data-q]');
    if (tag) {
      closeLb();
      writeForm(new URLSearchParams());
      form.q.value = tag.dataset.q;
      run(true);
      return;
    }
    const day = e.target.closest('[data-day]');
    if (day) {
      closeLb();
      writeForm(new URLSearchParams({ day: day.dataset.day, sort: 'ancien' }));
      run(true);
      return;
    }
    const cp = e.target.closest('[data-copy]');
    if (cp) {
      navigator.clipboard.writeText(cp.dataset.copy).then(() => {
        cp.textContent = 'Copié ✓';
        setTimeout(() => { cp.textContent = 'Copier le chemin'; }, 1500);
      });
    }
  });

  function infoHtml(p) {
    const rows = [];
    const add = (k, v) => { if (v !== null && v !== undefined && v !== '') rows.push(`<dt>${k}</dt><dd>${v}</dd>`); };
    const settings = [
      p.focal ? `${Math.round(p.focal)} mm` : '',
      p.aperture ? `f/${Number(p.aperture).toLocaleString('fr-FR')}` : '',
      p.exposure ? `${esc(p.exposure)} s` : '',
      p.iso ? `ISO ${p.iso}` : '',
    ].filter(Boolean).join(' · ');
    const place = [p.location, p.city, p.region, p.country].filter(Boolean).map(esc).join(', ');

    add('Date', p.taken_at
      ? `${fmtDate(p.taken_at, true)} <button class="link" data-day="${p.taken_at.slice(0, 10)}">voir la journée</button>`
      : '');
    add('Lieu', place);
    if (p.lat !== null && p.lon !== null) {
      add('GPS', `<a target="_blank" rel="noopener" href="https://www.openstreetmap.org/?mlat=${p.lat}&mlon=${p.lon}#map=15/${p.lat}/${p.lon}">voir sur la carte</a>`);
    }
    add('Appareil', esc(p.camera || ''));
    add('Objectif', esc(p.lens || ''));
    add('Réglages', settings);
    if (p.width) add('Taille', `${p.width} × ${p.height} px`);
    if (p.rating > 0) add('Note', '★'.repeat(p.rating));
    add('Étiquette', esc(p.label || ''));

    const chips = (list, cls) => list.map((k) => `<button class="chip ${cls}" data-q="${esc(k)}">${esc(k)}</button>`).join('');
    return `
      ${p.title ? `<h2>${esc(p.title)}</h2>` : ''}
      ${p.caption ? `<p>${esc(p.caption)}</p>` : ''}
      ${p.ai_description ? `<p class="ai"><span class="badge">IA</span> ${esc(p.ai_description)}</p>` : ''}
      ${p.ai_text ? `<p class="muted">Texte visible : « ${esc(p.ai_text)} »</p>` : ''}
      ${p.keywords.length ? `<h3>Mots-clés Lightroom</h3><div class="chips">${chips(p.keywords, 'lr')}</div>` : ''}
      ${p.ai_tags.length ? `<h3>Mots-clés IA</h3><div class="chips">${chips(p.ai_tags, '')}</div>` : ''}
      <dl>${rows.join('')}</dl>
      <h3>Original sur le NAS</h3>
      <p class="path">${esc(p.path)}</p>
      <p class="actions">
        <button class="btn ghost small" data-copy="${esc(p.path)}">Copier le chemin</button>
        <a class="btn ghost small" href="img.php?uid=${p.uid}&s=m&dl=1">Télécharger l'aperçu</a>
        <a class="btn small" href="publier.php?uid=${p.uid}">Publier cette photo</a>
      </p>`;
  }

  // ---------- utilitaires ----------
  function esc(s) {
    return String(s).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  }
  function fmtDate(s, withTime) {
    const [d, tm] = s.split(' ');
    const [y, m, j] = d.split('-').map(Number);
    let out = `${j} ${MOIS[m - 1]} ${y}`;
    if (withTime && tm && tm !== '00:00:00') out += ` à ${tm.slice(0, 5).replace(':', 'h')}`;
    return out;
  }

  // ---------- démarrage ----------
  const init = new URLSearchParams(location.search);
  writeForm(init);
  if ([...init.keys()].some((k) => k !== 'q')) {
    $('#filters').hidden = false;
    $('#toggleFilters').setAttribute('aria-expanded', 'true');
  }
  run(false);
})();
