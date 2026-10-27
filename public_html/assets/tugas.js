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
