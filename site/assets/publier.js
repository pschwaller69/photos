(() => {
  'use strict';
  const $ = (s) => document.querySelector(s);
  const NET = { instagram: 'Instagram', facebook: 'Facebook', x: 'X / Threads', autre: 'Autre' };
  let photo = null;   // photo choisie
  let pub = null;     // publication en cours
  let pollTimer = null;

  // ---------- appels ----------
  async function api(action, params = {}, post = false) {
    const opt = { credentials: 'same-origin' };
    let url = `pub.php?action=${action}`;
    if (post) {
      opt.method = 'POST';
      opt.headers = { 'Content-Type': 'application/json', 'X-Requested-With': 'photos' };
      opt.body = JSON.stringify(params);
    } else {
      const qs = new URLSearchParams(Object.entries(params).filter(([, v]) => v !== '' && v != null));
      if ([...qs].length) url += `&${qs}`;
    }
    const r = await fetch(url, opt);
    if (r.status === 401) { location.href = 'login.php'; throw new Error('non connecté'); }
    const j = await r.json();
    if (j.error) throw new Error(j.error);
    return j;
  }

  // ---------- choix de la photo ----------
  async function shuffle() {
    const grid = $('#pickGrid');
    grid.classList.add('loading');
    try {
      const j = await api('random', {
        n: innerWidth > 900 ? 15 : 12, rating: $('#fRating').value, year: $('#fYear').value,
        q: $('#fQ').value.trim(), all: $('#fAll').checked ? 1 : '',
      });
      showWorker(j.worker);
      grid.innerHTML = j.items.map((p) => `
        <button class="pick-item" data-uid="${p.uid}" type="button" aria-label="${esc(label(p))}">
          <img src="img.php?uid=${p.uid}" alt="" loading="lazy">
          ${p.rating > 0 ? `<span class="stars">${'★'.repeat(p.rating)}</span>` : ''}
        </button>`).join('');
      $('#pickEmpty').hidden = j.items.length > 0;
      scrollTo({ top: 0, behavior: 'smooth' });
    } catch (e) {
      toast(e.message);
    } finally {
      grid.classList.remove('loading');
    }
  }

  function label(p) {
    return p.title || p.ai_description || '';
  }

  function showWorker(w) {
    const el = $('#worker');
    if (!w || !w.seen) {
      el.innerHTML = '⚠️ Ton PC n\'a encore jamais rédigé de texte. Lance <code>lancer-veille.bat</code> dessus.';
      el.className = 'worker warn';
    } else if (w.ago < 90) {
      el.textContent = `🟢 PC prêt à rédiger${w.model ? ` (${w.model})` : ''}`;
      el.className = 'worker ok';
    } else {
      el.textContent = `🟠 PC hors ligne depuis ${dur(w.ago)} : les textes seront rédigés à son retour.`;
      el.className = 'worker warn';
    }
  }

  $('#shuffle').onclick = shuffle;
  ['#fRating', '#fYear', '#fAll'].forEach((s) => $(s).addEventListener('change', shuffle));
  let qt;
  $('#fQ').addEventListener('input', () => { clearTimeout(qt); qt = setTimeout(shuffle, 500); });
  $('#pickGrid').addEventListener('click', (e) => {
    const b = e.target.closest('.pick-item');
    if (b) openPhoto(b.dataset.uid);
  });

  // ---------- préparation ----------
  async function openPhoto(uid, push = true) {
    stopPoll();
    pub = null;
    photo = { uid, publications: [] };
    $('#result').hidden = true;
    $('#sInstr').value = '';
    $('#sImg').src = `img.php?uid=${uid}`;
    const big = new Image();
    big.onload = () => { if (photo && photo.uid === uid) $('#sImg').src = big.src; };
    big.src = `img.php?uid=${uid}&s=m`;
    $('#dl').href = `img.php?uid=${uid}&s=m&dl=1`;
    $('#pick').hidden = true;
    $('#sheet').hidden = false;
    scrollTo(0, 0);
    if (push) history.pushState({ uid }, '', `?uid=${uid}`);
    try {
      photo = await api('photo', { uid });
      const bits = [];
      if (photo.taken_at) bits.push(new Date(photo.taken_at.replace(' ', 'T')).toLocaleDateString('fr-FR', { day: 'numeric', month: 'long', year: 'numeric' }));
      const place = [photo.location, photo.city, photo.country].filter(Boolean).join(', ');
      if (place) bits.push(place);
      $('#sMeta').textContent = bits.join(' · ') + (photo.title ? ` — ${photo.title}` : '');
      $('#prev').innerHTML = photo.publications.filter((x) => x.text).length
        ? `<h3>Textes déjà rédigés pour cette photo</h3>${photo.publications.filter((x) => x.text).map((x) => `
            <div class="prev-item"><span class="muted">${NET[x.network] || x.network}${x.published_at ? ' · publiée' : ''}</span>
            <p>${esc(x.text).replace(/\n/g, '<br>')}</p>
            <button class="link" data-reuse="${x.id}">reprendre ce texte</button></div>`).join('')}`
        : '';
      photo.uid = uid;
    } catch (e) {
      toast(e.message);
    }
  }

  function closeSheet() {
    stopPoll();
    $('#sheet').hidden = true;
    $('#pick').hidden = false;
    photo = null;
    if (!$('#pickGrid').children.length) shuffle();
  }

  let direct = false; // page ouverte directement sur une photo (lien depuis la galerie)
  $('#back').onclick = () => {
    if (direct) { direct = false; history.replaceState(null, '', location.pathname); closeSheet(); } else history.back();
  };
  addEventListener('popstate', () => {
    const uid = new URLSearchParams(location.search).get('uid');
    if (uid) openPhoto(uid, false); else closeSheet();
  });

  $('#prev').addEventListener('click', async (e) => {
    const b = e.target.closest('[data-reuse]');
    if (!b) return;
    const j = await api('get', { id: b.dataset.reuse });
    showPub(j.pub, j.worker);
    $('#result').scrollIntoView({ behavior: 'smooth' });
  });

  async function generate() {
    if (!photo) return;
    $('#gen').disabled = true;
    try {
      const j = await api('create', {
        uid: photo.uid,
        network: document.querySelector('input[name=net]:checked').value,
        tone: $('#sTone').value,
        hashtags: $('#sTags').checked ? 1 : 0,
        instructions: $('#sInstr').value,
      }, true);
      showPub(j.pub, j.worker);
      $('#result').scrollIntoView({ behavior: 'smooth', block: 'center' });
      poll();
    } catch (e) {
      toast(e.message);
    } finally {
      $('#gen').disabled = false;
    }
  }
  $('#gen').onclick = generate;
  $('#again').onclick = generate;

  function showPub(p, worker) {
    pub = p;
    $('#result').hidden = false;
    const st = $('#rStatus');
    const ready = p.status === 'pret';
    $('#rText').hidden = !ready;
    $('#rActions').hidden = !ready;
    if (ready) {
      if (document.activeElement !== $('#rText')) $('#rText').value = p.text || '';
      st.innerHTML = p.published_at ? '✅ Marquée comme publiée' : '';
      st.className = 'r-status';
      $('#done').textContent = p.published_at ? 'Annuler « publiée »' : 'Marquer comme publiée';
    } else if (p.status === 'erreur') {
      st.innerHTML = `❌ Échec : ${esc(p.error || 'erreur inconnue')}`;
      st.className = 'r-status err';
    } else {
      const off = !worker || !worker.seen || worker.ago > 90;
      st.innerHTML = `<span class="spinner"></span> ${p.status === 'en_cours'
        ? 'Ton PC rédige le texte…'
        : off ? 'En attente de ton PC (hors ligne). Tu peux fermer la page : le texte sera prêt dans l\'historique.'
          : `En file d'attente${p.position ? ` (${p.position} avant)` : ''}…`}`;
      st.className = 'r-status';
    }
  }

  function poll() {
    stopPoll();
    pollTimer = setTimeout(async () => {
      if (!pub) return;
      try {
        const j = await api('get', { id: pub.id });
        showPub(j.pub, j.worker);
        if (j.pub.status === 'attente' || j.pub.status === 'en_cours') poll();
        else if (j.pub.status === 'pret' && navigator.vibrate) navigator.vibrate(30);
      } catch (e) { poll(); }
    }, 2500);
  }
  function stopPoll() { clearTimeout(pollTimer); pollTimer = null; }

  // ---------- actions sur le texte ----------
  let saveT;
  $('#rText').addEventListener('input', () => {
    clearTimeout(saveT);
    saveT = setTimeout(() => api('save', { id: pub.id, text: $('#rText').value }, true).catch(() => {}), 800);
  });

  async function copyText() {
    const t = $('#rText').value;
    try {
      await navigator.clipboard.writeText(t);
    } catch {
      $('#rText').select();
      document.execCommand('copy');
    }
  }
  $('#copy').onclick = async () => { await copyText(); toast('Texte copié'); };

  $('#share').onclick = async () => {
    const text = $('#rText').value;
    await copyText(); // Instagram n'accepte que l'image : le texte est dans le presse-papiers
    try {
      const blob = await (await fetch(`img.php?uid=${photo.uid}&s=m`, { credentials: 'same-origin' })).blob();
      const file = new File([blob], 'photo.jpg', { type: 'image/jpeg' });
      if (navigator.canShare && navigator.canShare({ files: [file] })) {
        await navigator.share({ files: [file], text });
        toast('Texte copié : colle-le dans l\'application si besoin');
        return;
      }
    } catch (e) {
      if (e.name === 'AbortError') return;
    }
    toast('Partage non disponible ici : texte copié, la photo se télécharge');
    $('#dl').click();
  };

  $('#done').onclick = async () => {
    const undo = !!pub.published_at;
    const j = await api('published', { id: pub.id, text: $('#rText').value, ...(undo ? { undo: 1 } : {}) }, true);
    showPub(j.pub);
    toast(undo ? 'Ce n\'est plus marqué comme publié' : 'Notée comme publiée : elle ne sera plus proposée au hasard');
  };

  // ---------- historique ----------
  $('#histBox').addEventListener('toggle', async () => {
    if (!$('#histBox').open) return;
    const j = await api('history');
    $('#hist').innerHTML = j.items.length ? j.items.map((x) => `
      <button class="hist-item" data-uid="${x.uid}" data-id="${x.id}" type="button">
        <img src="img.php?uid=${x.uid}" alt="" loading="lazy">
        <span><small class="muted">${NET[x.network] || x.network} · ${x.published_at ? `publiée le ${fmt(x.published_at)}` : `rédigé le ${fmt(x.created_at)}`}</small>
        ${esc((x.text || '').slice(0, 140))}${(x.text || '').length > 140 ? '…' : ''}</span>
      </button>`).join('') : '<p class="muted">Aucun texte rédigé pour l\'instant.</p>';
  });
  $('#hist').addEventListener('click', async (e) => {
    const b = e.target.closest('.hist-item');
    if (!b) return;
    await openPhoto(b.dataset.uid);
    const j = await api('get', { id: b.dataset.id });
    showPub(j.pub, j.worker);
  });

  // ---------- utilitaires ----------
  function esc(s) {
    return String(s).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  }
  function fmt(s) { return new Date(s.replace(' ', 'T')).toLocaleDateString('fr-FR'); }
  function dur(s) {
    if (s < 3600) return `${Math.max(1, Math.round(s / 60))} min`;
    if (s < 172800) return `${Math.round(s / 3600)} h`;
    return `${Math.round(s / 86400)} jours`;
  }
  let tt;
  function toast(msg) {
    const t = $('#toast');
    t.textContent = msg;
    t.hidden = false;
    clearTimeout(tt);
    tt = setTimeout(() => { t.hidden = true; }, 3200);
  }

  // ---------- démarrage ----------
  const start = new URLSearchParams(location.search).get('uid');
  if (start) { direct = true; history.replaceState({ uid: start }, '', `?uid=${start}`); openPhoto(start, false); } else shuffle();
})();
