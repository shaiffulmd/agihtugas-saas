#!/usr/bin/env python3
"""Ujian halaman awam & mod demo (Fasa 6) pada pemasangan DEMO (DEMO_MODE=1, laluan /KOD/).
  TEST_URL=http://127.0.0.1:8091 python3 tests/public_demo.py
Semak: halaman awam (utama, direktori, FAQ, terma, polisi, lapor) tanpa teks koku, panel demo, akaun demo 4 peranan,
data demo (versi muktamad + draf pindaan), sekatan demo, reset demo, rangkak semua halaman 4 peranan × 2 sekolah tanpa 5xx."""
import html, http.cookiejar, os, re, subprocess, sys, urllib.error, urllib.parse, urllib.request
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
BASE = os.environ.get('TEST_URL', 'http://127.0.0.1:8091').rstrip('/')
ok = 0
DEMO = {'admin': ('admin@demo.test', 'Demo-Admin-2026'), 'pk': ('pk@demo.test', 'Demo-PK-2026'), 'head': ('ketua@demo.test', 'Demo-Ketua-2026'), 'teacher': ('guru@demo.test', 'Demo-Guru-2026')}
KOKU = re.compile(r'(?i)\bmurid\b|(pengurusan|portal|platform|urus) kokurikulum|pajsk|\bunit\b|sekolah\s*koku(?!\))')  # 'Kokurikulum' sebagai bidang jawatan dibenarkan


def check(c, label):
    global ok
    assert c, label
    ok += 1
    print('  ✓', label, flush=True)


class C:
    def __init__(s):
        s.op = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
        s.last = ''

    def req(s, path, data=None):
        body = urllib.parse.urlencode(data, doseq=True).encode() if data is not None else None
        try:
            r = s.op.open(BASE + path, body, timeout=60)
        except urllib.error.HTTPError as e:
            r = e
        s.last = r.read().decode('utf-8', 'replace')
        return r.status if hasattr(r, 'status') else r.code, s.last, r.geturl() if hasattr(r, 'geturl') else path

    def post(s, path, data):
        m = re.search(r'name="csrf" value="([0-9a-f]+)"', s.last) or re.search(r'name="csrf" value="([0-9a-f]+)"', s.req(path)[1])
        return s.req(path, {**data, 'csrf': m.group(1)})


def visible(page):
    page = re.sub(r'(?s)<(script|style)\b.*?</\1>', ' ', page)
    return html.unescape(re.sub(r'<[^>]+>', ' ', page))


def notice(page, cls):
    m = re.search(r'class="notice ' + cls + r'"[^>]*>(.*?)</div>', page, re.S)
    return html.unescape(re.sub('<[^>]+>', '', m.group(1))).strip() if m else ''


def login(code, role):
    c = C(); c.req('/%s/?page=login' % code)
    e, p = DEMO[role]
    s, b, u = c.post('/%s/?page=login' % code, {'action': 'login', 'email': e, 'password': p})
    return c, u


print('1. Halaman awam platform')
a = C()
s, b, _ = a.req('/')
t = visible(b)
check(s == 200 and 'Cara ia berfungsi' in t and 'Demo tanpa daftar' in t, 'halaman utama: langkah & panel demo')
check('/DEM0001/?page=login' in b and '/DEM0002/?page=login' in b, 'panel demo memaut kedua-dua sekolah demo')
check('Agihan automatik & Pusat Syarat' in t and 'Free' in t and 'Pro' in t, 'pakej menyenaraikan ciri Pro')
check('Soalan lazim' in t or 'Apakah UrusTugas' in t, 'bahagian FAQ di halaman utama')
for path in ['/', '/direktori', '/direktori?q=DEM', '/faq', '/terma', '/polisi', '/lapor-keselamatan']:
    s, b, _ = a.req(path)
    bad = KOKU.findall(visible(b))
    check(s == 200 and not bad, f'{path} → {s}, tiada teks koku' + (f' (dijumpai: {bad[:3]})' if bad else ''))
s, b, _ = a.req('/direktori?q=DEM')
check('SMK Demo UrusTugas Satu' in b and 'SK Demo UrusTugas Dua' in b, 'direktori menemui sekolah demo')
s, b, _ = a.req('/faq')
check(b.count('<details') >= 10 or visible(b).count('?') >= 10, 'FAQ UrusTugas dipaparkan')

print('2. Akaun demo & data')
s, b, _ = C().req('/DEM0002/?page=login')
check('Akaun demo' in b and all(DEMO[r][0] in b for r in DEMO) and 'Guru Besar' in b, 'log masuk SK: 4 akaun demo, label Guru Besar')
s, b, _ = C().req('/DEM0001/?page=login')
check('Pengetua' in b, 'log masuk SMK: label Pengetua')
tch, u = login('DEM0001', 'teacher')
s, b, _ = tch.req('/DEM0001/?page=tugas_saya')
check('<article class="letter">' in b and 'Nur Aisyah Abdullah' in b, 'guru demo terus melihat surat versi muktamad')
pk, u = login('DEM0001', 'pk')
s, b, _ = pk.req('/DEM0001/?page=kelulusan')
check('Draf' in b and 'versi muktamad #1' in b and 'Muktamad' in b, 'demo bermula sebagai draf pindaan dengan versi muktamad #1')
s, b, _ = pk.req('/DEM0001/?page=syarat')
check('Ringkasan syarat khusus' in b and 'Matematik' in b, 'syarat jawatan contoh')
s, b, _ = pk.post('/DEM0001/?page=auto', {'action': 'auto_preview', 'mode': 'vacant'})
check('Pratonton: isi jawatan kosong' in b, 'PK demo boleh jana auto-agihan')
s, b, _ = tch.req('/DEM0001/?page=hebahan')
check('Semak senarai tugas' in b, 'hebahan contoh kepada guru')

print('3. Sekatan demo')
adm, u = login('DEM0001', 'admin')
s, b, _ = adm.post('/DEM0001/?page=api', {'action': 'api_cipta', 'name': 'X', 'scope': 'read'})
check('Mod demo' in notice(b, 'error'), 'kunci API disekat')
s, b, _ = adm.post('/DEM0001/?page=users', {'action': 'user_create', 'name': 'X', 'email': 'x@demo.test', 'password': 'Sementara-Ujian-2026', 'role': 'pk'})
check('Mod demo' in notice(b, 'error'), 'urus pengguna disekat')
s, b, _ = adm.post('/DEM0001/?page=security', {'action': 'password', 'old_password': DEMO['admin'][1], 'new_password': 'Baharu-Ujian-2026', 'new_password_confirm': 'Baharu-Ujian-2026'})
check('Mod demo' in notice(b, 'error'), 'tukar kata laluan akaun demo disekat')

print('4. Rangkak halaman (4 peranan × 2 sekolah)')
pages = ['dashboard', 'guru', 'jawatan', 'agihan', 'auto', 'syarat', 'pukal', 'kelulusan', 'surat', 'surat&semua=1', 'tugas_saya', 'sekolah', 'api', 'users', 'settings',
         'urus_akaun', 'ipsekat', 'langganan', 'sokongan', 'hebahan', 'versi', 'security']
bad = []
for code in ['DEM0001', 'DEM0002']:
    for role in DEMO:
        c, _ = login(code, role)
        for pg in pages:
            s, b, _ = c.req('/%s/?page=%s' % (code, pg))
            if s >= 500 or 'UrusTugas error' in b:
                bad.append((code, role, pg, s))
check(not bad, f'{len(pages) * 8} permintaan tanpa 5xx' + (f': {bad[:4]}' if bad else ''))

print('5. Reset demo')
pk.post('/DEM0001/?page=guru', {'action': 'guru_save', 'id': 0, 'name': 'Guru Sementara Demo', 'email': '', 'specialty': '', 'capacity': 20})
subprocess.run(['php', 'bin/school.php', 'demo-reset', 'DEM0001'], cwd=ROOT, check=True, capture_output=True)
s, b, _ = login('DEM0001', 'pk')[0].req('/DEM0001/?page=guru')
check('Guru Sementara Demo' not in b and b.count('page=guru&amp;edit=') == 12, 'reset demo menjana semula 12 guru')
print(f'\n{ok} semakan halaman awam & demo lulus.')
