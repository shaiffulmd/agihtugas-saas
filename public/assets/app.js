const sidebar = document.querySelector('.sidebar');
const scrim = document.querySelector('.scrim');
const toggle = document.querySelector('.mobile-toggle');
function menu(open) { sidebar.classList.toggle('open', open); scrim.hidden = !open; toggle.setAttribute('aria-expanded', String(open)); if (!open) toggle.focus(); }
toggle.addEventListener('click', () => menu(!sidebar.classList.contains('open')));
document.querySelector('.side-tutup').addEventListener('click', () => menu(false));
scrim.addEventListener('click', () => menu(false));
document.addEventListener('keydown', e => { if (e.key === 'Escape' && sidebar.classList.contains('open')) menu(false); });
document.querySelectorAll('[data-confirm]').forEach(form => form.addEventListener('submit', e => { if (!confirm(form.dataset.confirm)) e.preventDefault(); }));
document.querySelectorAll('[data-search]').forEach(input => input.addEventListener('input', () => {
 const table = document.getElementById(input.dataset.search); let visible = 0;
 table.querySelectorAll('tbody tr').forEach(row => { row.hidden = !row.textContent.toLocaleLowerCase('ms').includes(input.value.trim().toLocaleLowerCase('ms')); if (!row.hidden) visible++; });
 table.parentElement.querySelector('.search-empty').hidden = visible > 0;
}));
const position = document.getElementById('position-select');
const teacher = document.getElementById('teacher-select');
function preview() {
 if (!position?.value || !teacher?.value) return;
 const p = position.selectedOptions[0].dataset, t = teacher.selectedOptions[0].dataset;
 const after = Number(t.load) + (p.current === teacher.value ? 0 : Number(p.weight));
 const hint = document.getElementById('load-preview');
 hint.textContent = `Beban selepas lantikan: ${after} / ${t.capacity} mata.${after > Number(t.capacity) ? ' Melebihi kapasiti — semak kesesuaian sebelum menyimpan.' : ' Masih dalam kapasiti guru.'}`;
 hint.classList.toggle('warning-text', after > Number(t.capacity));
}
position?.addEventListener('change', preview); teacher?.addEventListener('change', preview);
document.querySelectorAll('[data-pick]').forEach(button => button.addEventListener('click', () => { position.value = button.dataset.pick; position.scrollIntoView({behavior:'smooth',block:'center'}); teacher.focus({preventScroll:true}); preview(); }));
document.querySelector('[data-print]')?.addEventListener('click', () => window.print());
const changeFilter = document.querySelector('[data-change-filter]');
changeFilter?.addEventListener('change', () => {
 document.querySelectorAll('#auto-preview-table tbody tr').forEach(row => { row.hidden = changeFilter.checked && row.dataset.changed !== 'true'; });
});
const schoolType = document.getElementById('school-type');
schoolType?.addEventListener('change', () => {
 document.getElementById('principal-label').textContent = schoolType.value === 'SMK' ? 'Nama Pengetua' : 'Nama Guru Besar';
});
