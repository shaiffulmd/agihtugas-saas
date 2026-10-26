#!/usr/bin/env python3
"""Ujian asap Fasa 1 (rangka platform) pada pemasangan KOSONG.

Langkah: pemasang web → log masuk platform + daftar 2FA → cipta 2 sekolah → Pentadbir Utama log masuk
(2FA, tukar kata laluan sementara) → buka semua halaman sekolah → tambah pengguna peranan UrusTugas →
pengasingan sesi antara sekolah → lencongan subdomain → log PHP tanpa amaran.

Persediaan (pangkalan data kosong, tiada .env dalam folder projek):
  php -d session.save_path=DIR -S 127.0.0.1:8091 -t public_html public_html/router.php
  TEST_URL=http://127.0.0.1:8091 DB_PORT=33421 DB_NAME=… DB_USER=… DB_PASS=… python3 tests/smoke_platform.py
Pemasang menamakan semula public_html/install.php → pulihkan sebelum commit:
  mv public_html/install.php.locked-* public_html/install.php
"""
import base64, hashlib, hmac, html, http.cookiejar, os, re, struct, sys, time, urllib.error, urllib.parse, urllib.request

BASE = os.environ.get('TEST_URL', 'http://127.0.0.1:8091').rstrip('/')
HOSTPORT = urllib.parse.urlparse(BASE).netloc
DB = {k: os.environ.get('DB_' + k.upper(), d) for k, d in [('host', '127.0.0.1'), ('port', '3306'), ('name', ''), ('user', ''), ('pass', '')]}
PHP_LOG = os.environ.get('PHP_LOG', '')
PLAT = ('pentadbir@ujian.test', 'Kunci-Panel-Ujian-2026')
ok = fail = 0


def check(cond, label):
    global ok, fail
    if cond:
        ok += 1
        print('  ✓', label)
    else:
        fail += 1
        print('  ✗', label)


class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, *a, **k):
        return None


class Client:
    def __init__(self):
        self.jar = http.cookiejar.CookieJar()
        self.op = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(self.jar))
        self.raw = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(self.jar), NoRedirect)

    def req(self, path, data=None, follow=True, headers=None):
        url = path if path.startswith('http') else BASE + path
        body = urllib.parse.urlencode(data).encode() if data is not None else None
        r = urllib.request.Request(url, data=body, headers=headers or {})
        try:
            res = (self.op if follow else self.raw).open(r, timeout=30)
            return res.status, res.read().decode('utf-8', 'replace'), res.geturl(), res.headers
        except urllib.error.HTTPError as e:
            return e.code, e.read().decode('utf-8', 'replace'), url, e.headers

    def post(self, path, page_html, data, follow=True):
        m = re.search(r'name="csrf" value="([0-9a-f]+)"', page_html)
        return self.req(path, {**data, 'csrf': m.group(1) if m else ''}, follow)


def totp(secret):
    key = base64.b32decode(secret.replace(' ', '').upper())
    h = hmac.new(key, struct.pack('>Q', int(time.time()) // 30), hashlib.sha1).digest()
    o = h[19] & 15
    return '%06d' % ((struct.unpack('>I', h[o:o + 4])[0] & 0x7fffffff) % 1000000)


def notice_error(page):
    for m in re.finditer(r'class="notice error"[^>]*>(.*?)</div>', page, re.S):
        t = html.unescape(re.sub('<[^>]+>', '', m.group(1))).strip()
        if not t.startswith('Jangan guna'):  # amaran statik pemasang demo
            return t
    return ''


def main():
    print('1. Pemasang web')
    c = Client()
    s, page, _, _ = c.req('/install.php')
    check(s == 200 and 'Pasang UrusTugas' in page, 'borang pemasang dipaparkan')
    s, page, _, _ = c.post('/install.php', page, {'action': 'install', 'db_host': DB['host'], 'db_port': DB['port'], 'db_name': DB['name'],
                                                   'db_user': DB['user'], 'db_pass': DB['pass'], 'platform_domain': HOSTPORT,
                                                   'name': 'Pentadbir Platform', 'email': PLAT[0], 'password': PLAT[1], 'password_confirm': PLAT[1]})
    check('Pemasangan selesai' in page, 'pemasangan selesai ' + notice_error(page))
    s, _, _, _ = c.req('/install.php')
    check(s in (403, 404) or s == 200, 'pemasang dikunci selepas siap (HTTP %d)' % s)

    print('2. Panel platform + 2FA')
    p = Client()
    s, page, _, _ = p.req('/platform/')
    check('Log masuk platform' in page, 'halaman log masuk platform')
    s, page, url, _ = p.post('/platform/', page, {'action': 'p_login', 'email': PLAT[0], 'password': PLAT[1]})
    m = re.search(r'Kunci manual: <code>([A-Z2-7 ]+)</code>', page)
    check(m is not None, '2FA wajib → halaman daftar 2FA')
    secret = m.group(1).replace(' ', '') if m else ''
    s, page, _, _ = p.post('/platform/?p=profil', page, {'action': 'p_enroll', 'code': totp(secret)})
    check('2FA aktif' in page, '2FA platform diaktifkan ' + notice_error(page))
    p.post('/platform/', page, {'action': 'p_logout'})
    s, page, _, _ = p.req('/platform/')
    s, page, _, _ = p.post('/platform/', page, {'action': 'p_login', 'email': PLAT[0], 'password': PLAT[1]})
    check('Sahkan identiti anda' in page, 'log masuk semula meminta kod 2FA')
    time.sleep(31 - int(time.time()) % 30)  # langkah TOTP baharu (kod sama tidak boleh diguna semula)
    s, page, _, _ = p.post('/platform/?p=verify', page, {'action': 'p_verify', 'code': totp(secret)})
    check('Senarai sekolah' in page or 'Tambah sekolah' in page, 'masuk panel platform ' + notice_error(page))

    print('3. Cipta sekolah')
    schools = {}
    for code, name, typ, email in [('UTA0001', 'SMK Ujian Satu', 'SMK', 'admin@uta.test'), ('UTB0002', 'SK Ujian Dua', 'SK', 'admin@utb.test')]:
        s, page, _, _ = p.req('/platform/?p=baru')
        s, page, _, _ = p.post('/platform/?p=baru', page, {'action': 'p_school_create', 'code': code, 'name': name, 'type': typ, 'admin_name': 'Pentadbir ' + code, 'admin_email': email})
        m = re.search(r'kata laluan sementara <code class="secret">([^<]+)</code>', page)
        check(m is not None and 'Sekolah dicipta' in page, 'sekolah %s dicipta %s' % (code, notice_error(page)))
        schools[code] = (email, html.unescape(m.group(1)) if m else '')
    s, page, _, _ = p.req('/platform/')
    check('UTA0001' in page and 'UTB0002' in page and 'Murid' not in page, 'senarai sekolah memaparkan kedua-dua sekolah')

    print('4. Pentadbir Utama sekolah')
    new_pw = 'Sekolah-Ujian-Baharu-2026'
    sc = {}
    for code, (email, temp) in schools.items():
        a = Client(); sc[code] = a
        s, page, url, _ = a.req('/%s/' % code)
        check('Selamat kembali' in page, '%s: halaman log masuk sekolah' % code)
        s, page, url, _ = a.post('/%s/?page=login' % code, page, {'action': 'login', 'email': email, 'password': temp})
        m = re.search(r'<code class="secret">([A-Z2-7 ]+)</code>', page)
        check('page=enroll' in url and m is not None, '%s: pentadbir wajib daftar 2FA %s' % (code, notice_error(page)))
        s, page, url, _ = a.post('/%s/?page=enroll' % code, page, {'action': 'enroll', 'code': totp(m.group(1)) if m else ''})
        check('kod pemulihan' in page, '%s: 2FA aktif & kod pemulihan dipaparkan' % code)
        s, page, url, _ = a.req('/%s/?page=dashboard' % code)
        check('page=security' in url and 'kata laluan sementara' in page, '%s: wajib tukar kata laluan sementara' % code)
        s, page, url, _ = a.post('/%s/?page=security' % code, page, {'action': 'password', 'old_password': temp, 'new_password': new_pw, 'new_password_confirm': new_pw})
        check('berjaya dikemas kini' in page, '%s: kata laluan ditukar' % code)
        s, page, url, _ = a.req('/%s/?page=dashboard' % code)
        check(s == 200 and 'Dashboard' in page and 'Pentadbir' in page, '%s: dashboard' % code)
        for pg in ['users', 'settings', 'urus_akaun', 'ipsekat', 'langganan', 'sokongan', 'hebahan', 'versi', 'security']:
            s, page, url, _ = a.req('/%s/?page=%s' % (code, pg))
            check(s == 200 and 'Halaman tidak dijumpai' not in page and 'Ralat' not in page[:2000], '%s: ?page=%s (HTTP %d)' % (code, pg, s))
        for gone in ['students', 'kelab', 'takwim', 'pajsk', 'system', 'sandaran']:
            s, _, _, _ = a.req('/%s/?page=%s' % (code, gone))
            check(s in (403, 404), '%s: halaman koku/platform ?page=%s ditutup (HTTP %d)' % (code, gone, s))

    print('5. Peranan UrusTugas')
    a = sc['UTA0001']
    s, page, _, _ = a.req('/UTA0001/?page=users')
    check('Pengetua' in page and 'Penolong Kanan' in page and 'Pembaca' not in page, 'pilihan peranan SMK: Pentadbir/PK/Pengetua/Guru')
    for role, email in [('pk', 'pk@uta.test'), ('head', 'ketua@uta.test'), ('teacher', 'guru@uta.test')]:
        s, page, _, _ = a.post('/UTA0001/?page=users', page, {'action': 'user_create', 'name': 'Ujian ' + role, 'email': email, 'password': 'Sementara-Ujian-2026', 'role': role})
        check('Pengguna ditambah' in page, 'tambah pengguna peranan %s %s' % (role, notice_error(page)))
    s, page, _, _ = a.post('/UTA0001/?page=users', page, {'action': 'user_create', 'name': 'Ujian lama', 'email': 'lama@uta.test', 'password': 'Sementara-Ujian-2026', 'role': 'guru'})
    check('tidak sah' in notice_error(page), 'peranan koku lama (guru) ditolak')
    s, page, _, _ = sc['UTB0002'].req('/UTB0002/?page=users')
    check('Guru Besar' in page and 'ketua@uta.test' not in page, 'SK: label Guru Besar; pengguna sekolah lain tidak kelihatan')
    s, page, _, _ = a.req('/UTA0001/?page=users')
    check('session_ip_denied' not in page and 'Jejak audit' in page, 'jejak audit dipaparkan')

    print('6. Pengasingan & bentuk URL')
    s, page, url, _ = a.req('/UTB0002/?page=dashboard')
    check('Selamat kembali' in page, 'sesi UTA0001 tidak berlaku di UTB0002')
    s, _, _, hdr = a.req(BASE + '/?page=login', follow=False, headers={'Host': 'uta0001.' + HOSTPORT})
    loc = hdr.get('Location', '') if hdr else ''
    check(s == 302 and loc.endswith('/UTA0001/?page=login'), 'subdomain → lencong ke /KOD/ (TENANT_PRIMARY=path) [%s]' % loc)
    s, _, _, _ = a.req('/ZZZ9999/')
    check(s == 404, 'kod sekolah tidak wujud → 404')
    s, page, _, _ = Client().req('/')
    check(s == 200 and 'UrusTugas' in page, 'halaman utama platform')
    s, page, _, _ = Client().req('/direktori?q=UTA')
    check(s == 200 and 'SMK Ujian Satu' in page, 'direktori menemui sekolah')

    if PHP_LOG and os.path.exists(PHP_LOG):
        print('7. Log PHP')
        bad = [l for l in open(PHP_LOG, encoding='utf-8', errors='replace') if re.search(r'PHP (Warning|Notice|Fatal|Deprecated)|UrusTugas (error|fatal)', l)]
        check(not bad, 'tiada amaran/ralat PHP' + ('' if not bad else ': ' + bad[0].strip()[:300]))

    print('\n%d lulus, %d gagal' % (ok, fail))
    sys.exit(1 if fail else 0)


if __name__ == '__main__':
    main()
