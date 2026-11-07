// UrusTugas — tingkah laku modul agihan tugas (patuh CSP: fail berasingan, tiada skrip sebaris).
// Carian jadual: teks setiap baris dikira sekali (jadual besar beratus baris) dan penapisan ditangguhkan sedikit semasa menaip.
document.querySelectorAll('[data-search]').forEach(input => {
  const table = document.getElementById(input.dataset.search); if (!table) return;
  let rows = null, timer = 0;
  input.addEventListener('input', () => { clearTimeout(timer); timer = setTimeout(() => {
    rows ??= [...table.querySelectorAll('tbody tr')].map(row => [row, row.textContent.toLocaleLowerCase('ms')]);
    const q = input.value.trim().toLocaleLowerCase('ms'); let visible = 0;
    rows.forEach(([row, text]) => { row.hidden = !text.includes(q); if (!row.hidden) visible++; });
    const empty = table.parentElement.querySelector('.search-empty'); if (empty) empty.hidden = visible > 0;
  }, 80); });
});
const posSel = document.getElementById('pilih-jawatan'), teacherSel = document.getElementById('pilih-guru');
function loadPreview() {
  if (!posSel?.value || !teacherSel?.value) return;
  const p = posSel.selectedOptions[0].dataset, t = teacherSel.selectedOptions[0].dataset;
  const after = Number(t.load) + (p.current === teacherSel.value ? 0 : Number(p.weight)), cap = Number(t.capacity);
  const hint = document.getElementById('anggaran-beban'), h = hint.dataset;
  // Anggaran indeks: median sekolah dianggap tidak berubah (indeks sebenar dikira semula selepas disimpan).
  let idx = '', heavy = false;
  if (t.index !== '' && Number(h.divScore) > 0) {
    const score = Number(t.score) + (p.current === teacherSel.value ? 0 : Number(p.score)), share = Number(h.share);
    const next = share * Number(t.teachRatio) + (1 - share) * score / Number(h.divScore);
    heavy = next > Number(h.high);
    idx = ` Indeks beban: ${Number(t.index).toFixed(2)} → ${next.toFixed(2)} (${heavy ? 'Terbeban' : next < Number(h.low) ? 'Kurang beban' : 'Seimbang'}, anggaran).`;
  }
  hint.textContent = `Beban selepas lantikan: ${after} / ${cap} mata.` + (after > cap ? ' Melebihi kapasiti — semak kesesuaian sebelum menyimpan.' : ' Masih dalam kapasiti guru.') + idx;
  hint.classList.toggle('warning-text', after > cap || heavy);
}
// Jawatan yang menyebut mata pelajaran (cth. Ketua Panitia Bahasa Melayu): guru yang mengajar MP itu disusun di atas dengan ★,
// kemudian guru yang mengajar MP lain dalam kumpulan katalog yang sama (cth. Bahasa) dengan ☆ beserta kepakarannya.
// Susunan asal (indeks terendah dahulu) dikekalkan dalam setiap kumpulan.
const teacherOpts = teacherSel ? [...teacherSel.options].slice(1) : [];
teacherOpts.forEach(o => { o.dataset.label = o.textContent; });
function rankBySubject() {
  if (!posSel || !teacherSel) return;
  const pd = posSel.selectedOptions[0]?.dataset || {};
  const subj = (pd.subject || '').toLocaleLowerCase('ms'), grp = pd.group || '';
  const tier = o => subj === '' ? 0 : (o.dataset.subjects || '').split('|').includes(subj) ? 2 : (grp !== '' && (o.dataset.groups || '').split('|').includes(grp) ? 1 : 0);
  const sorted = [2, 1, 0].flatMap(t => teacherOpts.filter(o => tier(o) === t));
  sorted.forEach(o => { const t = tier(o); o.textContent = t === 2 ? '★ ' + o.dataset.label : t === 1 ? '☆ ' + o.dataset.label.replace(' · ', ` (${o.dataset.spec || ''}) · `) : o.dataset.label; teacherSel.appendChild(o); });
  const hint = document.getElementById('anggaran-beban'), n2 = teacherOpts.filter(o => tier(o) === 2).length, n1 = teacherOpts.filter(o => tier(o) === 1).length;
  if (hint && !teacherSel.value) hint.textContent = !subj ? 'Calon disusun daripada indeks beban paling rendah. Pilih jawatan dan guru untuk melihat anggaran beban selepas lantikan.'
    : (n2 ? `${n2} guru mengajar ${pd.subject} (★)` : `Tiada guru dengan kepakaran ${pd.subject}`) + (n1 ? `, ${n1} guru mengajar MP berkaitan${grp ? ' (' + grp + ')' : ''} (☆)` : '') + ' — disusun di atas, indeks terendah dahulu.';
}
posSel?.addEventListener('change', rankBySubject);
posSel?.addEventListener('change', loadPreview); teacherSel?.addEventListener('change', loadPreview);
document.querySelectorAll('[data-pick]').forEach(btn => btn.addEventListener('click', () => {
  if (!posSel) return; posSel.value = btn.dataset.pick; posSel.dispatchEvent(new Event('change'));
  posSel.scrollIntoView({ behavior: 'smooth', block: 'center' }); teacherSel?.focus({ preventScroll: true }); loadPreview();
}));
const changeFilter = document.querySelector('[data-change-filter]');
changeFilter?.addEventListener('change', () => document.querySelectorAll('#jadual-pratonton tbody tr').forEach(row => { row.hidden = changeFilter.checked && row.dataset.changed !== 'true'; }));
// Pemilih mata pelajaran: tanda dalam senarai → kepakaran guru (teks dipisah koma). Teks bebas yang tiada dalam senarai dikekalkan.
document.querySelectorAll('[data-subject-pick]').forEach(box => {
  const input = document.getElementById(box.dataset.subjectPick); if (!input) return;
  const checks = [...box.querySelectorAll('input[type=checkbox]')], known = new Set(checks.map(c => c.value.toLocaleLowerCase('ms')));
  const tokens = () => input.value.split(',').map(x => x.trim()).filter(Boolean);
  checks.forEach(c => c.addEventListener('change', () => {
    const free = tokens().filter(x => !known.has(x.toLocaleLowerCase('ms')));
    input.value = [...checks.filter(x => x.checked).map(x => x.value), ...free].join(', ');
  }));
  input.addEventListener('input', () => { const have = new Set(tokens().map(x => x.toLocaleLowerCase('ms'))); checks.forEach(c => { c.checked = have.has(c.value.toLocaleLowerCase('ms')); }); });
});
// Jawatan: peranan dipilih → pemberat ikut skor peranan (baca sahaja).
const roleSel = document.getElementById('pilih-peranan'), weightInp = document.getElementById('pemberat-jawatan');
roleSel?.addEventListener('change', () => { const o = roleSel.selectedOptions[0]; if (o?.dataset.score) weightInp.value = o.dataset.score; weightInp.readOnly = !!roleSel.value; });
