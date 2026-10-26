'use strict';
// Menu sisi (mudah alih): buka/tutup dengan butang, latar gelap atau Escape.
const sidebar = document.getElementById('sidebar'), toggle = document.querySelector('.mobile-toggle'), scrim = document.querySelector('.scrim');
const setMenu = open => { sidebar?.classList.toggle('open', open); if (scrim) scrim.hidden = !open; toggle?.setAttribute('aria-expanded', String(open)); };
toggle?.addEventListener('click', () => setMenu(!sidebar.classList.contains('open')));
document.querySelector('.side-tutup')?.addEventListener('click', () => setMenu(false));
scrim?.addEventListener('click', () => setMenu(false));
document.addEventListener('keydown', event => { if (event.key === 'Escape') setMenu(false); });

// Pengesahan sebelum tindakan yang tidak boleh diundur.
document.querySelectorAll('.delete-form').forEach(form => form.addEventListener('submit', event => {
 const text = form.dataset.confirm || 'Padam rekod ini? Rekod berkaitan mungkin turut dipadam. Tindakan ini tidak boleh diundur.';
 if(!confirm(text)) event.preventDefault();
}));
document.querySelectorAll('[data-confirm-simple]').forEach(button => button.addEventListener('click', event => {
 if(!confirm(button.dataset.confirmSimple)) event.preventDefault();
}));
document.querySelectorAll('.print-btn').forEach(button => button.addEventListener('click', async () => { await Promise.all(Array.from(document.images, image => image.decode ? image.decode().catch(() => {}) : Promise.resolve())); window.print(); }));

// Penapis senarai dihantar terus apabila dipilih.
document.querySelectorAll('select.auto-submit').forEach(select => select.addEventListener('change', () => select.form.submit()));

// Carian dalam senarai pilihan yang panjang (contoh: nama murid).
document.querySelectorAll('select[data-searchable]').forEach(select => {
 const box = document.createElement('input');
 box.type = 'search'; box.className = 'select-search'; box.placeholder = 'Taip untuk cari…';
 box.setAttribute('aria-label', 'Cari dalam senarai pilihan');
 select.before(box);
 const options = Array.from(select.options);
 box.addEventListener('input', () => {
  const q = box.value.trim().toLowerCase();
  let first = null, shown = 0;
  options.forEach(option => {
   const match = !option.value || option.text.toLowerCase().includes(q);
   option.hidden = !match;
   if(match && option.value) { shown++; first ??= option; }
  });
  if(q && first && (select.selectedOptions[0]?.hidden || !select.value)) select.value = first.value;
  box.classList.toggle('no-match', q !== '' && shown === 0);
 });
});

// Kehadiran: tandakan semua dan kira ringkasan.
const attendanceForm = document.querySelector('.attendance-form');
if(attendanceForm) {
 const groups = {};
 attendanceForm.querySelectorAll('input[type=radio][name^="attendance["]').forEach(r => { (groups[r.name] ||= []).push(r); });
 const names = Object.keys(groups);
 const val = n => (groups[n].find(r => r.checked) || {}).value || '';
 const tally = attendanceForm.querySelector('.attendance-tally');
 const update = () => {
  if(!tally) return;
  const count = s => names.filter(n => val(n) === s).length;
  const blank = names.filter(n => !val(n)).length;
  tally.textContent = `Hadir ${count('Hadir')} · Tidak hadir ${count('Tidak hadir')} · Berizin ${count('Berizin')}` + (blank ? ` · Belum direkod ${blank}` : '');
 };
 attendanceForm.querySelectorAll('[data-mark]').forEach(button => button.addEventListener('click', () => {
  names.forEach(n => { const r = groups[n].find(x => x.value === button.dataset.mark); if(r) r.checked = true; });
  update();
 }));
 // Tekan nama murid = tandakan Hadir (cepat untuk telefon)
 attendanceForm.querySelectorAll('tr').forEach(tr => { const r = tr.querySelector('.att-h input'); const td = tr.querySelector('td'); if(r && td){ td.classList.add('att-name'); td.addEventListener('click', () => { r.checked = true; update(); }); } });
 // Mod "tanda yang tidak hadir sahaja": yang belum ditanda = Hadir; tekan Tidak/Izin sekali lagi untuk kembali Hadir.
 const setMode = m => {
  attendanceForm.classList.toggle('mode-tidak', m === 'tidak');
  attendanceForm.querySelectorAll('[data-att-mode]').forEach(b => b.classList.toggle('on', b.dataset.attMode === m));
  if(m === 'tidak') names.forEach(n => { if(!val(n)) { const r = groups[n].find(x => x.value === 'Hadir'); if(r) r.checked = true; } });
  try { localStorage.setItem('att-mode', m); } catch(e) {}
  update();
 };
 attendanceForm.querySelectorAll('[data-att-mode]').forEach(b => b.addEventListener('click', () => setMode(b.dataset.attMode)));
 attendanceForm.querySelectorAll('.att-t input, .att-i input').forEach(r => {
  let was = false;
  r.addEventListener('pointerdown', () => { was = r.checked; });
  r.addEventListener('click', () => { if(was && attendanceForm.classList.contains('mode-tidak')) { const h = groups[r.name].find(x => x.value === 'Hadir'); if(h) h.checked = true; update(); } was = false; });
 });
 attendanceForm.addEventListener('change', update);
 let mode = 'semua'; try { mode = localStorage.getItem('att-mode') || 'semua'; } catch(e) {}
 if(attendanceForm.querySelector('[data-att-mode]')) setMode(mode); else update();
}

// Tetapan tema: pratetap, warna tersuai dan pratonton langsung.
const settingsForm = document.getElementById('settings-form');
if(settingsForm) {
 const root = document.documentElement;
 const colors = { primary: settingsForm.elements.color_primary, dark: settingsForm.elements.color_dark, accent: settingsForm.elements.color_accent };
 const custom = settingsForm.querySelector('.custom-colors');
 const apply = () => {
  root.style.setProperty('--primary', colors.primary.value); root.style.setProperty('--teal', colors.primary.value);
  root.style.setProperty('--btn', colors.primary.value); root.style.setProperty('--dark', colors.dark.value); root.style.setProperty('--accent', colors.accent.value);
 };
 const syncPreset = () => {
  const chosen = settingsForm.querySelector('input[name="theme_preset"]:checked');
  const isCustom = chosen?.value === 'custom';
  custom.classList.toggle('disabled', !isCustom);
  Object.values(colors).forEach(input => { input.disabled = !isCustom; });
  if(chosen && !isCustom) { colors.primary.value = chosen.dataset.primary; colors.dark.value = chosen.dataset.dark; colors.accent.value = chosen.dataset.accent; }
  apply();
 };
 settingsForm.querySelectorAll('input[name="theme_preset"]').forEach(radio => radio.addEventListener('change', syncPreset));
 Object.values(colors).forEach(input => input.addEventListener('input', apply));
 settingsForm.addEventListener('submit', () => Object.values(colors).forEach(input => { input.disabled = false; }));
 const title = settingsForm.elements.banner_title, preview = document.getElementById('pv-title');
 title?.addEventListener('input', () => { preview.textContent = title.value; });
 syncPreset();
}

// Pilih keseluruhan URL apabila medan salin diklik.
document.querySelectorAll('.copy-field').forEach(input => input.addEventListener('focus', () => input.select()));

// PAJSK: komitmen — hadkan bilangan tanda (cth. maksimum 4 item).
document.querySelectorAll('[data-max-check]').forEach(box => {
  const max = parseInt(box.dataset.maxCheck, 10) || 0;
  const inputs = [...box.querySelectorAll('input[type=checkbox]')];
  const sync = () => {
    const n = inputs.filter(i => i.checked).length;
    inputs.forEach(i => { if (!i.checked) i.disabled = n >= max; });
    box.classList.toggle('full-limit', n >= max);
  };
  inputs.forEach(i => i.addEventListener('change', sync)); sync();
});

// Pengesahan umum untuk borang lain (bukan .delete-form) yang mempunyai data-confirm.
document.querySelectorAll('form[data-confirm]:not(.delete-form)').forEach(form => form.addEventListener('submit', event => {
  if (!confirm(form.dataset.confirm)) event.preventDefault();
}));
// Butang salin (cth. pautan langganan takwim).
document.querySelectorAll('[data-copy-target]').forEach(btn => btn.addEventListener('click', () => {
  const input = document.querySelector(btn.dataset.copyTarget); if (!input) return;
  input.select();
  const done = () => { const t = btn.textContent; btn.textContent = 'Disalin ✓'; setTimeout(() => { btn.textContent = t; }, 1600); };
  if (navigator.clipboard) navigator.clipboard.writeText(input.value).then(done, () => { document.execCommand('copy'); done(); });
  else { document.execCommand('copy'); done(); }
}));
// Takwim: sembunyikan medan masa apabila "Sepanjang hari" ditanda.
document.querySelectorAll('[data-toggle-times]').forEach(cb => {
  const form = cb.closest('form'); const fields = form ? form.querySelectorAll('.time-field') : [];
  const sync = () => fields.forEach(f => { f.hidden = cb.checked; });
  cb.addEventListener('change', sync); sync();
});

// Laman awam: lightbox galeri (CSS :target) — Escape tutup, anak panah tukar gambar.
document.addEventListener('keydown', event => {
  const box = location.hash && document.querySelector('.lightbox' + location.hash.replace(/[^#\w-]/g, ''));
  if (!box) return;
  if (event.key === 'Escape') { history.replaceState(null, '', '#tutup'); box.blur(); location.hash = 'tutup'; }
  else if (event.key === 'ArrowRight') { const n = box.querySelector('.lb-next'); if (n) location.hash = n.getAttribute('href'); }
  else if (event.key === 'ArrowLeft') { const p = box.querySelector('.lb-prev'); if (p) location.hash = p.getAttribute('href'); }
});

// Laman awam: galeri karusel & 3D coverflow. Tanpa JS kedua-duanya kekal sebagai gelongsor mendatar.
(() => {
  const reduce = window.matchMedia && matchMedia('(prefers-reduced-motion: reduce)').matches;
  const autoplay = (root, step, ms) => {
    if (reduce) return;
    let t = null; const stop = () => { clearInterval(t); t = null; }; const start = () => { stop(); t = setInterval(step, ms); };
    root.addEventListener('mouseenter', stop); root.addEventListener('mouseleave', start);
    root.addEventListener('focusin', stop); root.addEventListener('focusout', start);
    document.addEventListener('visibilitychange', () => document.hidden ? stop() : start());
    start();
  };
  document.querySelectorAll('[data-carousel]').forEach(root => {
    const track = root.querySelector('.gc-track'); if (!track) return;
    const width = () => (track.querySelector('.gal-item')?.getBoundingClientRect().width || 300) + 16;
    const go = dir => {
      const end = track.scrollLeft + track.clientWidth >= track.scrollWidth - 4;
      if (dir > 0 && end) track.scrollTo({ left: 0 }); else if (dir < 0 && track.scrollLeft <= 2) track.scrollTo({ left: track.scrollWidth });
      else track.scrollBy({ left: dir * width() });
    };
    root.querySelector('.g-prev')?.addEventListener('click', () => go(-1));
    root.querySelector('.g-next')?.addEventListener('click', () => go(1));
    track.addEventListener('keydown', e => { if (e.key === 'ArrowRight') { e.preventDefault(); go(1); } if (e.key === 'ArrowLeft') { e.preventDefault(); go(-1); } });
    autoplay(root, () => go(1), 4500);
  });
  document.querySelectorAll('[data-coverflow]').forEach(root => {
    const stage = root.querySelector('.cf-stage'); const items = [...root.querySelectorAll('.gal-item')]; if (!stage || !items.length) return;
    const cap = root.querySelector('.cf-caption'); let cur = 0; const n = items.length;
    root.classList.add('is-3d');
    const render = () => items.forEach((el, i) => {
      let o = i - cur; if (o > n / 2) o -= n; if (o < -n / 2) o += n;
      const a = Math.abs(o);
      el.style.transform = `translateX(${o * 58}%) translateZ(${-a * 160}px) rotateY(${o === 0 ? 0 : (o > 0 ? -42 : 42)}deg)`;
      el.style.zIndex = String(100 - a); el.style.opacity = a > 3 ? '0' : '1'; el.style.pointerEvents = a > 3 ? 'none' : 'auto';
      el.classList.toggle('active', o === 0); el.tabIndex = o === 0 ? 0 : -1; el.setAttribute('aria-hidden', o === 0 ? 'false' : 'true');
      if (o === 0 && cap) cap.textContent = el.querySelector('span')?.textContent || el.querySelector('img')?.alt || '';
    });
    const go = d => { cur = (cur + d + n) % n; render(); };
    items.forEach((el, i) => el.addEventListener('click', e => { if (i !== cur) { e.preventDefault(); cur = i; render(); } }));
    root.querySelector('.g-prev')?.addEventListener('click', () => go(-1));
    root.querySelector('.g-next')?.addEventListener('click', () => go(1));
    stage.addEventListener('keydown', e => { if (e.key === 'ArrowRight') { e.preventDefault(); go(1); } if (e.key === 'ArrowLeft') { e.preventDefault(); go(-1); } });
    let x0 = null; stage.addEventListener('pointerdown', e => { x0 = e.clientX; });
    stage.addEventListener('pointerup', e => { if (x0 !== null && Math.abs(e.clientX - x0) > 40) go(e.clientX < x0 ? 1 : -1); x0 = null; });
    render(); autoplay(root, () => go(1), 5000);
  });
})();

// Captcha: "kod lain" (id sama, kod baharu; maks 5 kali).
document.querySelectorAll('.captcha-refresh').forEach(btn => btn.addEventListener('click', () => {
  const box = btn.closest('[data-cap]'), img = box && box.querySelector('img[data-src]'); if (!img) return;
  img.src = img.dataset.src + '&baru=1&t=' + Date.now();
  const input = box.querySelector('input[name=captcha]'); if (input) { input.value = ''; input.focus(); }
}));
// Tetapan e-mel: papar medan SMTP hanya bila dipilih.
document.querySelectorAll('[data-mail-method]').forEach(sel => {
  const box = sel.closest('form')?.querySelector('.smtp-fields'); if (!box) return;
  const sync = () => { box.hidden = sel.value !== 'smtp'; }; sel.addEventListener('change', sync); sync();
});
// Pratetap pembekal SMTP (Brevo, Gmail, iCloud …): isi hos, port & penyulitan; papar panduan pengguna/kata laluan.
document.querySelectorAll('[data-smtp-preset]').forEach(sel => {
  const form = sel.closest('form'); const hint = sel.closest('label')?.querySelector('[data-smtp-hint]');
  sel.addEventListener('change', () => {
    const o = sel.selectedOptions[0]; if (!o || !form) return;
    if (hint) hint.textContent = o.dataset.hint || '';
    if (sel.value === 'custom') return;
    const set = (n, v) => { const f = form.querySelector('[name="' + n + '"]'); if (f && v !== undefined && v !== '') f.value = v; };
    const host = form.querySelector('[name="smtp_host"]'); if (host) host.value = o.dataset.host || '';
    set('smtp_port', o.dataset.port); set('smtp_secure', o.dataset.secure);
    if (!o.dataset.host && host) host.focus();
  });
});

// Editor WYSIWYG ringan (laporan). Tanpa pustaka luar; pelayan tetap membersihkan HTML (senarai putih).
(() => {
  const ALLOW = new Set(['P','BR','STRONG','EM','U','S','UL','OL','LI','H3','H4','BLOCKQUOTE','A']);
  const MAP = { B: 'STRONG', I: 'EM', STRIKE: 'S', DEL: 'S', H1: 'H3', H2: 'H3', H5: 'H4', H6: 'H4', DIV: 'P' };
  const DROP = new Set(['SCRIPT','STYLE','IFRAME','OBJECT','EMBED','SVG','MATH','TEMPLATE','META','LINK','IMG','VIDEO','AUDIO','CANVAS','FORM','INPUT','BUTTON','SELECT','TEXTAREA','NOSCRIPT','TITLE','HEAD']);
  const esc = s => s.replace(/[&<>"]/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));
  const clean = html => {
    html = html.replace(/\s(style|on\w+)\s*=\s*("[^"]*"|'[^']*'|[^\s>]+)/gi, ''); // elak amaran CSP semasa DOMParser
    const doc = new DOMParser().parseFromString('<div>' + html + '</div>', 'text/html');
    const walk = node => {
      let out = '';
      node.childNodes.forEach(c => {
        if (c.nodeType === 3) { out += esc(c.nodeValue); return; }
        if (c.nodeType !== 1) return;
        let tag = c.tagName; tag = MAP[tag] || tag;
        if (DROP.has(tag)) return;
        const inner = walk(c);
        if (!ALLOW.has(tag)) { out += inner; return; }
        if (tag === 'BR') { out += '<br>'; return; }
        if (tag === 'A') { const h = (c.getAttribute('href') || '').replace(/[\x00-\x20\x7f]+/g, ''); out += /^(https?:\/\/|mailto:)/i.test(h) ? '<a href="' + esc(h) + '">' + inner + '</a>' : inner; return; }
        if ((tag === 'P' || tag === 'H3' || tag === 'H4') && /<(p|ul|ol|h3|h4|blockquote)>/.test(inner)) { out += inner; return; }
        const t = tag.toLowerCase(); out += '<' + t + '>' + inner + '</' + t + '>';
      });
      return out;
    };
    return walk(doc.body.firstChild || doc.body).replace(/<p>(\s|<br>|&nbsp;)*<\/p>/g, '');
  };
  const isHtml = s => /<(p|br|strong|em|u|s|ul|ol|li|h3|h4|blockquote|a)\b/i.test(s);
  const plainToHtml = s => s.trim() === '' ? '' : s.split(/\n{2,}/).map(p => '<p>' + esc(p).replace(/\n/g, '<br>') + '</p>').join('');
  const TOOLS = [
    ['bold', '<b>B</b>', 'Tebal (Ctrl+B)'], ['italic', '<i>I</i>', 'Condong (Ctrl+I)'], ['underline', '<u>U</u>', 'Garis bawah (Ctrl+U)'], ['sep'],
    ['h3', 'H', 'Subtajuk'], ['insertUnorderedList', '•&nbsp;≡', 'Senarai titik'], ['insertOrderedList', '1.&nbsp;≡', 'Senarai bernombor'], ['blockquote', '❝', 'Petikan'], ['sep'],
    ['link', '🔗', 'Pautan (https://)'], ['removeFormat', 'T̸', 'Buang format'], ['sep'], ['undo', '↶', 'Buat asal (Ctrl+Z)'], ['redo', '↷', 'Buat semula']
  ];
  document.querySelectorAll('textarea[data-rich]').forEach(ta => {
    const max = parseInt(ta.dataset.max, 10) || 0;
    const wrap = document.createElement('div'); wrap.className = 'rte';
    const bar = document.createElement('div'); bar.className = 'rte-bar'; bar.setAttribute('role', 'toolbar'); bar.setAttribute('aria-label', 'Format teks');
    const ed = document.createElement('div'); ed.className = 'rte-area'; ed.contentEditable = 'true';
    ed.setAttribute('role', 'textbox'); ed.setAttribute('aria-multiline', 'true'); ed.setAttribute('aria-label', ta.getAttribute('aria-label') || 'Teks');
    const foot = document.createElement('div'); foot.className = 'rte-foot';
    TOOLS.forEach(([cmd, html, title]) => {
      if (cmd === 'sep') { const s = document.createElement('span'); s.className = 'rte-sep'; bar.appendChild(s); return; }
      const b = document.createElement('button'); b.type = 'button'; b.innerHTML = html; b.title = title; b.setAttribute('aria-label', title); b.dataset.cmd = cmd;
      b.addEventListener('mousedown', e => e.preventDefault());
      b.addEventListener('click', () => {
        ed.focus();
        if (cmd === 'h3' || cmd === 'blockquote') {
          const cur = (document.queryCommandValue('formatBlock') || '').toLowerCase();
          document.execCommand('formatBlock', false, cur === cmd ? 'p' : cmd);
        } else if (cmd === 'link') {
          const url = (prompt('Alamat pautan (bermula dengan https://):', 'https://') || '').trim();
          if (/^(https?:\/\/[^\s]+|mailto:[^\s]+)$/i.test(url)) document.execCommand('createLink', false, url);
          else if (url && url !== 'https://') alert('Pautan mesti bermula dengan https://, http:// atau mailto:');
        } else document.execCommand(cmd, false, null);
        sync(); state();
      });
      bar.appendChild(b);
    });
    const sync = () => {
      const empty = ed.textContent.trim() === ''; wrap.classList.toggle('is-empty', empty);
      if (empty && !ed.firstElementChild) ed.innerHTML = '<p><br></p>'; // kekalkan perenggan supaya teks baharu masuk <p>
      ta.value = empty ? '' : clean(ed.innerHTML);
      if (max) { const n = ed.textContent.length; foot.textContent = n.toLocaleString('ms-MY') + ' / ' + max.toLocaleString('ms-MY') + ' aksara'; foot.classList.toggle('over', n > max); }
    };
    const state = () => bar.querySelectorAll('button').forEach(b => {
      let on = false; const c = b.dataset.cmd;
      try { if (['bold','italic','underline','insertUnorderedList','insertOrderedList'].includes(c)) on = document.queryCommandState(c); else if (c === 'h3' || c === 'blockquote') on = (document.queryCommandValue('formatBlock') || '').toLowerCase() === c; } catch (e) {}
      b.setAttribute('aria-pressed', on ? 'true' : 'false');
    });
    try { document.execCommand('defaultParagraphSeparator', false, 'p'); } catch (e) {}
    ed.innerHTML = (isHtml(ta.value) ? clean(ta.value) : plainToHtml(ta.value)) || '<p><br></p>';
    ed.dataset.placeholder = 'Tulis di sini…';
    const lid = ta.getAttribute('aria-labelledby'); if (lid) ed.setAttribute('aria-labelledby', lid);
    ed.addEventListener('input', sync);
    ed.addEventListener('keyup', state); ed.addEventListener('mouseup', state);
    ed.addEventListener('paste', e => {
      e.preventDefault(); const dt = e.clipboardData; const html = dt.getData('text/html');
      if (html) {
        const sel = getSelection(); if (!sel.rangeCount) return;
        const r = sel.getRangeAt(0); r.deleteContents();
        const frag = r.createContextualFragment(clean(html)); const last = frag.lastChild;
        r.insertNode(frag); if (last) { r.setStartAfter(last); r.collapse(true); sel.removeAllRanges(); sel.addRange(r); }
      } else document.execCommand('insertText', false, dt.getData('text/plain'));
      sync();
    });
    ed.addEventListener('drop', e => e.preventDefault()); // elak seret gambar/fail ke dalam editor
    ta.required = false; ta.hidden = true;
    ta.parentNode.insertBefore(wrap, ta); wrap.append(bar, ed, foot);
    ta.form?.addEventListener('submit', sync);
    sync(); state();
  });
})();

// Combobox guru: cari dalam senarai guru berdaftar (datalist) atau guna nama baharu.
document.querySelectorAll('input[data-combo]').forEach((input, n) => {
 const dl = document.getElementById(input.getAttribute('list')); if (!dl) return;
 const items = Array.from(dl.options).map(o => ({ name: o.value, sub: o.dataset.sub || '' }));
 input.removeAttribute('list');
 const wrap = document.createElement('div'); wrap.className = 'combo' + (input.dataset.noTag !== undefined ? ' compact' : '');
 input.before(wrap); wrap.appendChild(input);
 const list = document.createElement('ul'); list.className = 'combo-list'; list.id = 'combo-list-' + n; list.setAttribute('role', 'listbox'); list.hidden = true;
 const tag = document.createElement('span'); tag.className = 'combo-tag'; tag.setAttribute('aria-live', 'polite');
 wrap.append(list, tag);
 input.setAttribute('role', 'combobox'); input.setAttribute('aria-autocomplete', 'list'); input.setAttribute('aria-expanded', 'false'); input.setAttribute('aria-controls', list.id);
 const norm = s => s.toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '').replace(/\s+/g, ' ').trim();
 let active = -1, shown = [];
 const exact = () => items.find(i => norm(i.name) === norm(input.value));
 const status = () => {
  const v = input.value.trim(), hit = exact();
  tag.textContent = !v ? '' : hit ? (input.dataset.ok || '✓ Guru berdaftar') : (input.dataset.new || 'Nama baharu');
  tag.className = 'combo-tag' + (!v ? '' : hit ? ' ok' : ' new');
 };
 // Senarai diletak 'fixed' mengikut kedudukan input supaya tidak dipotong oleh jadual/panel (overflow).
 const place = () => {
  if (list.hidden) return;
  const r = input.getBoundingClientRect(), below = window.innerHeight - r.bottom, above = r.top;
  const up = below < Math.min(290, list.scrollHeight + 12) && above > below;
  list.style.left = Math.max(8, r.left) + 'px'; list.style.width = Math.max(r.width, 220) + 'px';
  list.style.maxHeight = Math.max(140, Math.min(280, (up ? above : below) - 16)) + 'px';
  if (up) { list.style.top = 'auto'; list.style.bottom = (window.innerHeight - r.top + 4) + 'px'; }
  else { list.style.bottom = 'auto'; list.style.top = (r.bottom + 4) + 'px'; }
 };
 window.addEventListener('scroll', place, true); window.addEventListener('resize', place);
 const close = () => { list.hidden = true; input.setAttribute('aria-expanded', 'false'); input.removeAttribute('aria-activedescendant'); active = -1; };
 const choose = name => { input.value = name; close(); status(); input.dispatchEvent(new Event('change', { bubbles: true })); };
 const mark = i => {
  active = i; list.querySelectorAll('li').forEach((li, k) => li.setAttribute('aria-selected', k === i ? 'true' : 'false'));
  const li = list.children[i]; if (li) { input.setAttribute('aria-activedescendant', li.id); li.scrollIntoView({ block: 'nearest' }); }
 };
 const render = (all) => {
  const q = all === true ? '' : norm(input.value);
  shown = items.filter(i => !q || norm(i.name).includes(q) || norm(i.sub).includes(q)).slice(0, 50);
  list.replaceChildren();
  shown.forEach((it, k) => {
   const li = document.createElement('li'); li.id = list.id + '-' + k; li.setAttribute('role', 'option');
   const b = document.createElement('strong'); b.textContent = it.name; const s = document.createElement('small'); s.textContent = it.sub;
   li.append(b, s); li.addEventListener('mousedown', e => { e.preventDefault(); choose(it.name); }); list.appendChild(li);
  });
  const v = input.value.trim();
  if (v && !exact()) {
   const li = document.createElement('li'); li.id = list.id + '-new'; li.className = 'combo-new'; li.setAttribute('role', 'option');
   li.textContent = (input.dataset.newText || '＋ Guna nama baharu: ') + '“' + v + '”'; li.addEventListener('mousedown', e => { e.preventDefault(); choose(v); });
   list.appendChild(li); shown.push({ name: v });
  }
  if (!items.length && !v) { const li = document.createElement('li'); li.className = 'combo-empty'; li.textContent = input.dataset.empty || 'Tiada dalam senarai — taip nama.'; list.appendChild(li); }
  list.hidden = list.children.length === 0; input.setAttribute('aria-expanded', list.hidden ? 'false' : 'true'); active = -1;
  place();
 };
 input.addEventListener('focus', () => render(input.dataset.showAll !== undefined && !!exact())); // nilai standard sedia ada: papar semua pilihan
 input.addEventListener('input', () => { render(); status(); });
 input.addEventListener('blur', () => setTimeout(close, 120));
 input.addEventListener('keydown', e => {
  if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
   if (list.hidden) render(input.dataset.showAll !== undefined && !!exact()); const max = shown.length - 1; if (max < 0) return;
   e.preventDefault(); mark(e.key === 'ArrowDown' ? (active >= max ? 0 : active + 1) : (active <= 0 ? max : active - 1));
  } else if (e.key === 'Enter' && !list.hidden && active >= 0) { e.preventDefault(); choose(shown[active].name); }
  else if (e.key === 'Escape' && !list.hidden) { e.preventDefault(); close(); }
 });
 status();
});

// Tambah unit pukal: pilih semua / kosongkan / kiraan.
document.querySelectorAll('[data-check-all],[data-check-none]').forEach(btn => btn.addEventListener('click', () => {
 const name = btn.dataset.checkAll || btn.dataset.checkNone;
 document.querySelectorAll('input[type=checkbox][name="' + name + '"]:not(:disabled)').forEach(c => { if (c.closest('[hidden]')) return; if (btn.dataset.checkAll && c.dataset.move !== undefined) return; c.checked = !!btn.dataset.checkAll; });
 document.dispatchEvent(new Event('checkcount'));
}));
document.querySelectorAll('[data-check-count]').forEach(out => {
 const boxes = () => document.querySelectorAll('input[type=checkbox][name="' + out.dataset.checkCount + '"]');
 const upd = () => { const n = Array.from(boxes()).filter(c => c.checked && !c.disabled).length; out.textContent = n ? n + ' dipilih' : ''; };
 boxes().forEach(c => c.addEventListener('change', upd)); document.addEventListener('checkcount', upd); upd();
});

// Tapis senarai murid (panel tambah ahli) mengikut nama.
document.querySelectorAll('input[data-filter-list]').forEach(box => {
 const list = document.getElementById(box.dataset.filterList); if (!list) return;
 box.addEventListener('input', () => { const q = box.value.trim().toLowerCase(); list.querySelectorAll('li[data-name]').forEach(li => { li.hidden = q !== '' && !li.dataset.name.includes(q); }); });
});
// Murid dalam unit lain (pentadbir): tandakan kotak pindah secara automatik apabila dipilih.
document.querySelectorAll('.member-pick').forEach(form => {
 const move = form.querySelector('input[name=move]'); if (!move) return;
 form.querySelectorAll('input[data-move]').forEach(c => c.addEventListener('change', () => { if (c.checked) move.checked = true; }));
});

// Edit jawatan dalam senarai keahlian: butang Simpan hanya muncul apabila nilai berubah.
document.querySelectorAll('form.pos-edit').forEach(form => {
 const input = form.querySelector('input[name=position]'); const orig = input.value;
 const upd = () => form.classList.toggle('changed', input.value.trim() !== orig);
 form.classList.add('js'); ['input', 'change'].forEach(ev => input.addEventListener(ev, upd));
 input.addEventListener('keydown', e => { if (e.key === 'Escape' && input.getAttribute('aria-expanded') !== 'true') { input.value = orig; upd(); } });
});

// Halaman ralat: butang Kembali (tanpa skrip sebaris).
document.querySelectorAll('[data-back]').forEach(btn => btn.addEventListener('click', () => {
 if (history.length > 1 && document.referrer && new URL(document.referrer).origin === location.origin) history.back(); else location.href = btn.dataset.back || '/';
}));

// Butang tunjuk/sembunyi kata laluan pada setiap medan kata laluan (tanpa gaya/skrip sebaris, patuh CSP).
(() => {
 const NS = 'http://www.w3.org/2000/svg';
 const icon = paths => { const s = document.createElementNS(NS, 'svg'); s.setAttribute('viewBox', '0 0 24 24'); s.setAttribute('aria-hidden', 'true'); s.setAttribute('focusable', 'false');
  paths.forEach(d => { const p = document.createElementNS(NS, 'path'); p.setAttribute('d', d); s.appendChild(p); }); return s; };
 const eye = ['M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12Z', 'M12 15a3 3 0 1 0 0-6 3 3 0 0 0 0 6Z'];
 const eyeOff = ['M3 3l18 18', 'M10.6 5.1A10.5 10.5 0 0 1 12 5c6.5 0 10 7 10 7a17.6 17.6 0 0 1-3.2 4.2M6.6 6.6C3.8 8.4 2 12 2 12s3.5 7 10 7c1.8 0 3.4-.5 4.8-1.3', 'M9.9 9.9a3 3 0 0 0 4.2 4.2'];
 const enhance = input => {
  if (input.dataset.pwToggle) return; input.dataset.pwToggle = '1';
  const wrap = document.createElement('span'); wrap.className = 'pw-wrap';
  input.parentNode.insertBefore(wrap, input); wrap.appendChild(input);
  const btn = document.createElement('button'); btn.type = 'button'; btn.className = 'pw-toggle';
  const show = icon(eye), hide = icon(eyeOff); hide.classList.add('pw-hidden'); btn.append(show, hide);
  const set = visible => { input.type = visible ? 'text' : 'password'; btn.setAttribute('aria-pressed', String(visible));
   btn.setAttribute('aria-label', visible ? 'Sembunyikan kata laluan' : 'Tunjukkan kata laluan'); btn.title = btn.getAttribute('aria-label');
   show.classList.toggle('pw-hidden', visible); hide.classList.toggle('pw-hidden', !visible); };
  set(false);
  btn.addEventListener('mousedown', e => e.preventDefault()); // kekalkan fokus & papan kekunci mudah alih pada medan
  btn.addEventListener('click', () => { const pos = input.selectionStart; set(input.type === 'password'); input.focus(); try { input.setSelectionRange(pos, pos); } catch (_) {} });
  wrap.appendChild(btn);
  input.form?.addEventListener('submit', () => set(false)); // jangan biar pelayar simpan sebagai teks biasa
 };
 document.querySelectorAll('input[type=password]').forEach(enhance);
})();

// Takwim: selepas klik petak hari, terus fokus ke tajuk acara baharu (autofocus diabaikan pelayar apabila URL ada #sauh).
(() => {
 const form = document.getElementById('borang-acara'); if (!form || location.hash !== '#borang-acara') return;
 const title = form.querySelector('input[name=title]'); if (!title || title.value !== '') return;
 requestAnimationFrame(() => title.focus({ preventScroll: true }));
})();

// Mod demo: butang akaun mengisi borang log masuk; medan muat naik fail dimatikan dengan penjelasan.
(() => {
 document.querySelectorAll('.demo-fill').forEach(btn => btn.addEventListener('click', () => {
  const form = document.querySelector('form input[name=email]')?.form; if (!form) return;
  form.querySelector('input[name=email]').value = btn.dataset.email;
  const pw = form.querySelector('input[name=password]'); pw.value = btn.dataset.password; pw.type = 'password';
  document.querySelectorAll('.demo-fill').forEach(b => b.setAttribute('aria-pressed', String(b === btn)));
  form.querySelector('button[type=submit]')?.focus();
 }));
 if (document.body?.dataset.demo !== '1') return;
 document.querySelectorAll('input[type=file]').forEach(input => {
  input.disabled = true; input.required = false;
  const note = document.createElement('small'); note.className = 'demo-file-note';
  note.textContent = 'Muat naik fail dimatikan dalam mod demo.';
  input.insertAdjacentElement('afterend', note);
 });
})();

// Versi: tanda "Baharu" pada label versi di footer sehingga pengguna membuka halaman Versi & Perubahan.
(() => {
 const link = document.querySelector('.app-version'); if (!link) return;
 const v = link.dataset.version, key = 'urustugas-versi-dilihat';
 let seen = null; try { seen = localStorage.getItem(key); } catch (_) {}
 if (new URLSearchParams(location.search).get('page') === 'versi') { try { localStorage.setItem(key, v); } catch (_) {} return; }
 if (seen !== v) { const b = document.createElement('span'); b.className = 'app-version-new'; b.textContent = 'Baharu'; link.appendChild(b); }
})();

// Carta organisasi: pratonton terus apabila pilihan reka bentuk diubah (tidak disimpan sehingga klik Simpan).
(() => {
 const form = document.querySelector('form[data-org-preview]'); if (!form) return;
 const color = form.querySelector('[data-org-color]'), custom = form.querySelector('.org-custom');
 const go = () => {
  const q = new URLSearchParams(); q.set('pratonton', '1');
  form.querySelectorAll('[data-org-field]').forEach(f => q.set(f.name, f.value));
  location.href = form.dataset.orgPreview + '&' + q.toString() + '#reka-carta';
 };
 color?.addEventListener('change', () => custom?.classList.toggle('is-hidden', color.value !== 'sendiri'));
 form.querySelectorAll('[data-org-field]').forEach(f => f.addEventListener('change', () => {
  if (f === color && color.value === 'sendiri') return; // tunggu warna dipilih
  go();
 }));
 form.querySelector('input[name="org[title]"]')?.addEventListener('keydown', e => { if (e.key === 'Enter') { e.preventDefault(); go(); } });
})();

// Panel boleh dilipat yang mengingati keadaan pada peranti ini (cth. Statistik kehadiran hari ini).
(() => {
 document.querySelectorAll('details[data-remember]').forEach(d => {
  const k = 'urustugas-lipat-' + d.dataset.remember;
  try { const v = localStorage.getItem(k); if (v === '0') d.open = false; else if (v === '1') d.open = true; } catch (e) {}
  d.addEventListener('toggle', () => { try { localStorage.setItem(k, d.open ? '1' : '0'); } catch (e) {} });
 });
})();

// Hebahan guru: tunjuk/sembunyi pemilih guru, carian dan pilih semua.
(() => {
 const pick = document.querySelector('[data-hb-pick]'); if (!pick) return;
 const boxes = [...pick.querySelectorAll('input[name="targets[]"]')];
 const count = pick.querySelector('[data-hb-count]');
 const upd = () => { if (count) count.textContent = boxes.filter(b => b.checked).length + ' dipilih'; };
 const pilih = document.querySelector('[data-hb-aud][value="pilih"]');
 document.querySelectorAll('[data-hb-aud]').forEach(r => r.addEventListener('change', () => pick.classList.toggle('is-hidden', !pilih.checked)));
 pick.querySelector('[data-hb-filter]')?.addEventListener('input', e => { const q = e.target.value.trim().toLowerCase(); pick.querySelectorAll('[data-hb-name]').forEach(l => l.classList.toggle('is-hidden', q !== '' && !l.dataset.hbName.includes(q))); });
 pick.querySelectorAll('[data-hb-all]').forEach(b => b.addEventListener('click', () => { boxes.forEach(x => { if (!x.closest('label').classList.contains('is-hidden')) x.checked = b.dataset.hbAll === '1'; }); upd(); }));
 boxes.forEach(b => b.addEventListener('change', upd)); upd();
})();
