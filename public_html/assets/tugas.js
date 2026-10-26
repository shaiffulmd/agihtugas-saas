// UrusTugas — tingkah laku modul agihan tugas (patuh CSP: fail berasingan, tiada skrip sebaris).
document.querySelectorAll('[data-search]').forEach(input => input.addEventListener('input', () => {
  const table = document.getElementById(input.dataset.search); if (!table) return;
  const q = input.value.trim().toLocaleLowerCase('ms'); let visible = 0;
  table.querySelectorAll('tbody tr').forEach(row => { row.hidden = !row.textContent.toLocaleLowerCase('ms').includes(q); if (!row.hidden) visible++; });
  const empty = table.parentElement.querySelector('.search-empty'); if (empty) empty.hidden = visible > 0;
}));
const posSel = document.getElementById('pilih-jawatan'), teacherSel = document.getElementById('pilih-guru');
function loadPreview() {
  if (!posSel?.value || !teacherSel?.value) return;
  const p = posSel.selectedOptions[0].dataset, t = teacherSel.selectedOptions[0].dataset;
  const after = Number(t.load) + (p.current === teacherSel.value ? 0 : Number(p.weight)), cap = Number(t.capacity);
  const hint = document.getElementById('anggaran-beban');
  hint.textContent = `Beban selepas lantikan: ${after} / ${cap} mata.` + (after > cap ? ' Melebihi kapasiti — semak kesesuaian sebelum menyimpan.' : ' Masih dalam kapasiti guru.');
  hint.classList.toggle('warning-text', after > cap);
}
posSel?.addEventListener('change', loadPreview); teacherSel?.addEventListener('change', loadPreview);
document.querySelectorAll('[data-pick]').forEach(btn => btn.addEventListener('click', () => {
  if (!posSel) return; posSel.value = btn.dataset.pick; posSel.dispatchEvent(new Event('change'));
  posSel.scrollIntoView({ behavior: 'smooth', block: 'center' }); teacherSel?.focus({ preventScroll: true }); loadPreview();
}));
const changeFilter = document.querySelector('[data-change-filter]');
changeFilter?.addEventListener('change', () => document.querySelectorAll('#jadual-pratonton tbody tr').forEach(row => { row.hidden = changeFilter.checked && row.dataset.changed !== 'true'; }));
