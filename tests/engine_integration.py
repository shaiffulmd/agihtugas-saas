#!/usr/bin/env python3
"""Ujian integrasi enjin agihan tugas (Fasa 3) — UI berperanan, auto-agihan, kelulusan, surat, API & pengasingan sekolah.

Persediaan pada pemasangan sedia ada (DEMO_MODE=0, laluan /KOD/):
  php tests/setup_engine_test.php > ujian.json      # cipta UJT0001 (SMK) & UJT0002 (SK)
  php -S 127.0.0.1:8091 -t public_html public_html/router.php
  TEST_URL=http://127.0.0.1:8091 SETUP_JSON=ujian.json python3 tests/engine_integration.py
"""
import base64, hashlib, hmac, html, http.cookiejar, json, os, re, struct, sys, time, urllib.error, urllib.parse, urllib.request

BASE = os.environ.get('TEST_URL', 'http://127.0.0.1:8091').rstrip('/')
CFG = json.load(open(os.environ.get('SETUP_JSON', 'ujian.json')))
ok = fail = 0


def check(cond, label):
    global ok, fail
    ok, fail = (ok + 1, fail) if cond else (ok, fail + 1)
    print(('  ✓ ' if cond else '  ✗ ') + label)


def totp(secret):
    key = base64.b32decode(secret)
    h = hmac.new(key, struct.pack('>Q', int(time.time()) // 30), hashlib.sha1).digest()
    o = h[19] & 15
    return '%06d' % ((struct.unpack('>I', h[o:o + 4])[0] & 0x7fffffff) % 1000000)


class Client:
    def __init__(self, code):
        self.code = code
        self.op = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
        self.last = ''

    def req(self, path, data=None, files=None):
        url = BASE + '/' + self.code + path
        body, headers = None, {}
        if files:
            b = '----ujian' + os.urandom(8).hex()
            parts = []
            for k, v in (data or {}).items():
                parts.append(f'--{b}\r\nContent-Disposition: form-data; name="{k}"\r\n\r\n{v}\r\n'.encode())
            for k, (fn, content, ctype) in files.items():
                parts.append(f'--{b}\r\nContent-Disposition: form-data; name="{k}"; filename="{fn}"\r\nContent-Type: {ctype}\r\n\r\n'.encode() + content + b'\r\n')
            body = b''.join(parts) + f'--{b}--\r\n'.encode()
            headers['Content-Type'] = 'multipart/form-data; boundary=' + b
        elif data is not None:
            body = urllib.parse.urlencode(data, doseq=True).encode()
        try:
            r = self.op.open(urllib.request.Request(url, data=body, headers=headers), timeout=60)
            raw = r.read()
            self.ctype = r.headers.get('Content-Type', '')
            self.last = raw.decode('utf-8', 'replace') if 'pdf' not in self.ctype else ''
            self.raw = raw
            return r.status, self.last, r.geturl()
        except urllib.error.HTTPError as e:
            self.ctype = e.headers.get('Content-Type', '')
            self.last = e.read().decode('utf-8', 'replace')
            return e.code, self.last, url

    def csrf(self):
        m = re.search(r'name="csrf" value="([0-9a-f]+)"', self.last)
        if not m:
            self.req('/?page=security')
            m = re.search(r'name="csrf" value="([0-9a-f]+)"', self.last)
        return m.group(1)

    def post(self, page, data, files=None):
        return self.req('/?page=' + page, {**data, 'csrf': self.csrf()}, files)

    def login(self, role):
        c = CFG[self.code]
        self.req('/?page=login')
        s, p, u = self.post('login', {'action': 'login', 'email': c['emails'][role], 'password': c['password']})
        if 'page=verify' in u:
            s, p, u = self.post('verify', {'action': 'verify', 'code': totp(c['totp'])})
        return u


def err(page):
    for m in re.finditer(r'class="notice error"[^>]*>(.*?)</div>', page, re.S):
        return html.unescape(re.sub('<[^>]+>', '', m.group(1))).strip()
    return ''


def flash(page):
    m = re.search(r'class="notice success"[^>]*>(.*?)</div>', page, re.S)
    return html.unescape(re.sub('<[^>]+>', '', m.group(1))).strip() if m else ''


def ids(page, pattern):
    return [int(x) for x in re.findall(pattern, page)]


def api(code, method, path, token, body=None):
    url = BASE + '/' + code + '/api/v1' + path
    data = json.dumps(body).encode() if body is not None else None
    h = {'Authorization': 'Bearer ' + token} if token else {}
    if body is not None:
        h['Content-Type'] = 'application/json'
    try:
        r = urllib.request.urlopen(urllib.request.Request(url, data=data, headers=h, method=method), timeout=30)
        return r.status, json.loads(r.read() or b'null')
    except urllib.error.HTTPError as e:
        raw = e.read()
        try:
            return e.code, json.loads(raw)
        except Exception:
            return e.code, {'raw': raw[:200].decode('utf-8', 'replace')}


def main():
    A, B = 'UJT0001', 'UJT0002'
    print('1. Log masuk & akses mengikut peranan')
    adm = Client(A)
    u = adm.login('admin')
    check('page=dashboard' in u and 'Beban mengikut bidang' in adm.last, 'pentadbir → dashboard enjin')
    nav = re.findall(r'class="nav-item[^"]*"[^>]*>.*?<span>([^<]+)</span>', adm.last)
    check(all(x in nav for x in ['Pengurusan Guru', 'Agihan Automatik', 'Semakan &amp; Kelulusan', 'API &amp; Integrasi']), 'menu pentadbir lengkap')
    pk = Client(A); pk.login('pk')
    head = Client(A); u = head.login('head')
    check('page=kelulusan' in u, 'Pengetua → terus ke Semakan & Kelulusan')
    tch = Client(A); u = tch.login('teacher')
    check('page=tugas_saya' in u and 'Belum ada lantikan muktamad' in tch.last, 'guru → Tugas Saya (belum muktamad)')
    for cl, role, pages, allowed in [(pk, 'pk', ['guru', 'auto', 'surat'], True), (pk, 'pk', ['api', 'sekolah', 'users'], False),
                                     (head, 'head', ['kelulusan', 'surat'], True), (head, 'head', ['guru', 'agihan', 'auto'], False),
                                     (tch, 'teacher', ['tugas_saya'], True), (tch, 'teacher', ['guru', 'kelulusan', 'surat', 'api'], False)]:
        for pg in pages:
            s, _, _ = cl.req('/?page=' + pg)
            check((s == 200) == allowed, f'{role}: ?page={pg} → {s}')

    print('2. Guru & jawatan')
    s, p, _ = pk.post('guru', {'action': 'guru_save', 'id': 0, 'name': 'Cikgu Beban Tinggi', 'email': 'tinggi@ujt.test', 'specialty': 'Sains', 'capacity': 10})
    check('Maklumat guru disimpan' in flash(p), 'PK tambah guru')
    s, p, _ = pk.post('guru', {'action': 'guru_save', 'id': 0, 'name': 'X', 'email': '', 'specialty': '', 'capacity': 101})
    check('Kapasiti' in err(p), 'kapasiti 101 ditolak')
    s, p, _ = pk.post('guru', {'action': 'guru_bulk_preview', 'bulk_text': 'name,email,specialty,capacity\nGuru Pukal Satu,p1@ujt.test,Sejarah,20\nGuru Pukal Dua,,Matematik,'})
    tok = re.search(r'name="token" value="([0-9a-f]+)"', p)
    check(tok is not None and 'Pratonton 2 guru' in p, 'pratonton guru pukal')
    s, p, _ = pk.post('guru', {'action': 'guru_bulk_apply', 'token': tok.group(1) if tok else ''})
    check('2 guru ditambah' in flash(p), 'guru pukal disimpan')
    s, p, _ = pk.post('guru', {'action': 'guru_bulk_preview', 'bulk_text': 'Guru Pukal Satu,,Sejarah,20'})
    check('sudah wujud' in err(p), 'nama guru berulang ditolak')
    s, p, _ = pk.req('/?page=guru')
    tids = ids(p, r'page=guru&amp;edit=(\d+)')
    check(len(tids) == 4, f'4 guru dalam senarai ({len(tids)})')
    s, p, _ = pk.post('pukal', {'action': 'jawatan_bulk_preview', 'bulk_text': 'name,category,weight,count\nAJK Disiplin,HEM,5,2\nSetiausaha Peperiksaan,Kurikulum,9,1\nPenyelaras Sukan,Kokurikulum,8,1\nPenyelaras Data,Pentadbiran,4,1'})
    tok = re.search(r'name="token" value="([0-9a-f]+)"', p)
    check(tok is not None and 'AJK Disiplin (1/2)' in p, 'pratonton jawatan pukal (bilangan → (1/2))')
    s, p, _ = pk.post('pukal', {'action': 'jawatan_bulk_apply', 'token': tok.group(1) if tok else ''})
    check('5 jawatan ditambah' in flash(p), 'jawatan pukal disimpan')
    s, p, _ = pk.post('jawatan', {'action': 'jawatan_save', 'id': 0, 'name': 'Bidang Salah', 'category': 'Sukan', 'weight': 5})
    check('Bidang' in err(p), 'bidang tidak sah ditolak')
    s, p, _ = pk.req('/?page=jawatan')
    pids = ids(p, r'page=jawatan&amp;edit=(\d+)')
    check(len(pids) == 5, f'5 jawatan dalam katalog ({len(pids)})')

    print('3. Lantikan manual & sekatan padam')
    s, p, _ = pk.post('agihan', {'action': 'agih', 'position_id': pids[0], 'teacher_id': tids[0]})
    check('Agihan tugas dikemas kini' in flash(p), 'lantik guru')
    s, p, _ = pk.post('guru', {'action': 'guru_delete', 'id': tids[0]})
    check('Lepaskan agihan' in err(p), 'guru dilantik tidak boleh dipadam')
    s, p, _ = pk.post('jawatan', {'action': 'jawatan_delete', 'id': pids[0]})
    check('Lepaskan agihan' in err(p), 'jawatan dilantik tidak boleh dipadam')
    s, p, _ = adm.req('/?page=users')
    linked = [t for t in tids if f'value="{t}"' in p]
    check(len(linked) >= 1, 'pilihan profil guru di Pengguna & Audit')

    print('4. Syarat & auto-agihan')
    s, p, _ = pk.post('syarat', {'action': 'syarat_umum', 'respect_capacity': '1', 'max_tasks': 3, 'max_heavy': 1, 'heavy_threshold': 8,
                                  'category_0': 4, 'category_1': 4, 'category_2': 4, 'category_3': 4})
    check('Syarat agihan automatik disimpan' in flash(p), 'syarat umum disimpan')
    s, p, _ = pk.post('syarat', {'action': 'syarat_jawatan', 'position_id': pids[1], 'fixed_teacher_id': tids[1], 'required_specialty': '', 'allowed_teachers[]': [tids[2]]})
    check('senarai guru layak' in err(p), 'penyandang tetap mesti dalam senarai layak')
    s, p, _ = pk.post('syarat', {'action': 'syarat_jawatan', 'position_id': pids[1], 'fixed_teacher_id': '', 'required_specialty': 'Matematik'})
    check('Syarat khusus jawatan disimpan' in flash(p), 'syarat jawatan (kepakaran Matematik)')
    s, p, _ = pk.post('auto', {'action': 'auto_preview', 'mode': 'vacant'})
    plan = re.search(r'name="plan_id" value="([0-9a-f]{48})"', p)
    check(plan is not None and 'Pratonton: isi jawatan kosong' in p, 'pratonton auto-agihan')
    s, p, _ = pk.post('auto', {'action': 'auto_preview', 'mode': 'vacant'})
    plan2 = re.search(r'name="plan_id" value="([0-9a-f]{48})"', p).group(1)
    pk.post('guru', {'action': 'guru_save', 'id': tids[3], 'name': 'Guru Pukal Dua', 'email': '', 'specialty': 'Matematik', 'capacity': 21})
    s, p, _ = pk.post('auto', {'action': 'auto_apply', 'plan_id': plan.group(1) if plan else ''})
    check('berubah selepas pratonton' in err(p), 'pratonton lapuk ditolak selepas data berubah')
    s, p, _ = pk.post('auto', {'action': 'auto_preview', 'mode': 'vacant'})
    plan3 = re.search(r'name="plan_id" value="([0-9a-f]{48})"', p).group(1)
    s, p, _ = pk.post('auto', {'action': 'auto_apply', 'plan_id': plan3})
    check('Agihan automatik disimpan' in flash(p), 'auto-agihan disimpan: ' + flash(p)[:70])
    s, p, _ = pk.post('auto', {'action': 'auto_apply', 'plan_id': plan3})
    check('sudah digunakan' in err(p), 'pratonton sekali guna')
    s, p, _ = pk.req('/?page=agihan')
    check('Belum diisi' not in p or p.count('badge green">Diisi') >= 4, 'jawatan diisi selepas auto-agihan')
    s, p, _ = pk.req('/?page=syarat')
    check('Ringkasan syarat khusus' in p, 'ringkasan syarat khusus')

    print('5. Aliran kelulusan')
    s, p, _ = head.req('/?page=kelulusan')
    check('Hantar untuk semakan' not in p, 'Pengetua tidak boleh menghantar draf')
    s, p, _ = pk.req('/?page=kelulusan')
    ver = re.search(r'name="transition" value="submit"><input type="hidden" name="version" value="(\d+)"', p)
    s, p, _ = pk.post('kelulusan', {'action': 'kelulusan', 'transition': 'submit', 'version': ver.group(1) if ver else 0})
    check('dikemas kini' in flash(p) and 'Semakan versi #' in p, 'PK hantar untuk semakan')
    s, p, _ = pk.post('guru', {'action': 'guru_save', 'id': 0, 'name': 'Semasa Dikunci', 'email': '', 'specialty': '', 'capacity': 20})
    check('dikunci' in err(p), 'data perancangan dikunci semasa semakan')
    s, p, _ = adm.post('settings', {'action': 'settings_save', 'school_name': 'Nama Baharu', 'app_name': 'UrusTugas', 'school_tagline': '', 'login_headline': 'a', 'login_text': '', 'banner_title': 'b', 'banner_text': '',
                                    'theme_preset': 'delima', 'theme_mode': 'light', 'twofa_policy': 'admin', 'school_type': 'SMK'})
    check('dikunci' in err(p), 'nama sekolah dikunci semasa semakan')
    s, p, _ = pk.post('kelulusan', {'action': 'kelulusan', 'transition': 'approve', 'version': 99})
    check('berubah' in err(p) or 'Hanya' in err(p), 'versi lapuk / peranan salah ditolak')
    s, p, _ = head.req('/?page=kelulusan')
    v = re.search(r'value="approve"><input type="hidden" name="version" value="(\d+)"', p)
    s, p, _ = head.post('kelulusan', {'action': 'kelulusan', 'transition': 'approve', 'version': v.group(1) if v else 0})
    check('dikemas kini' in flash(p), 'Pengetua luluskan')
    v = re.search(r'value="finalize"><input type="hidden" name="version" value="(\d+)"', p)
    s, p, _ = head.post('kelulusan', {'action': 'kelulusan', 'transition': 'finalize', 'version': v.group(1) if v else 0})
    check('Muktamad' in p and 'Lihat surat muktamad' in p, 'Pengetua muktamadkan')

    print('6. Surat & Tugas Saya')
    s, p, _ = head.req('/?page=surat&semua=1')
    check(p.count('<article class="letter">') >= 2 and 'PEJABAT PENGETUA' in p and 'Ketua UJT0001' in p, 'surat muktamad semua guru (Pengetua)')
    imgs = re.findall(r'src="(/UJT0001/\?page=aset&amp;id=[0-9a-f]{64})"', p)
    check(len(imgs) >= 1, 'kepala surat: Jata Negara')
    if imgs:
        s, _, _ = head.req(html.unescape(imgs[0])[len('/UJT0001'):])
        check(s == 200 and head.ctype.startswith('image/png'), 'imej aset dihantar')
    s, _, _ = head.req('/?page=surat&pdf=1')
    check(s == 200 and head.ctype == 'application/pdf' and head.raw[:4] == b'%PDF', 'PDF semua surat')
    s, p, _ = tch.req('/?page=tugas_saya')
    check('<article class="letter">' in p and 'Guru Pertama UJT0001' in p, 'guru melihat surat sendiri')
    s, _, _ = tch.req('/?page=tugas_saya&pdf=1')
    check(s == 200 and tch.raw[:4] == b'%PDF', 'guru muat turun PDF sendiri')
    s, _, _ = tch.req('/?page=surat&pdf=1')
    check(s == 403, 'guru tidak boleh muat turun semua surat')
    s, p, _ = adm.req('/?page=kelulusan')
    v = re.search(r'value="return"><input type="hidden" name="version" value="(\d+)"', p)
    s, p, _ = adm.post('kelulusan', {'action': 'kelulusan', 'transition': 'return', 'version': v.group(1) if v else 0, 'reason': 'Pindaan ujian'})
    check('Draf' in p and 'Belum ada versi' not in p, 'pentadbir buka draf pindaan (versi muktamad kekal)')
    s, p, _ = tch.req('/?page=tugas_saya')
    check('<article class="letter">' in p, 'surat muktamad lama kekal semasa draf pindaan')

    print('7. API v1 & kunci per sekolah')
    s, p, _ = adm.post('api', {'action': 'api_cipta', 'name': 'Sistem Ujian', 'scope': 'write'})
    m = re.search(r'(ut_[0-9a-f]{64})', p); tokA = m.group(1) if m else ''
    check(bool(tokA), 'kunci API (tulis) dicipta & dipaparkan sekali')
    s, p, _ = adm.req('/?page=api')
    check(tokA not in p, 'token tidak dipaparkan lagi')
    adm.post('api', {'action': 'api_cipta', 'name': 'Baca Sahaja', 'scope': 'read'})
    tokR = re.search(r'(ut_[0-9a-f]{64})', adm.last).group(1)
    st, j = api(A, 'GET', '/summary', tokA)
    check(st == 200 and j['data']['teachers'] == 4, f"GET /summary ({st})")
    st, j = api(A, 'GET', '/teachers?per_page=2', tokA)
    check(st == 200 and j['meta']['total'] == 4 and len(j['data']) == 2, 'senarai guru berhalaman')
    st, j = api(A, 'POST', '/teachers/sync', tokA, {'external_id': 'koku-multi:1:7', 'name': 'Guru Segerak', 'capacity': 15})
    check(st == 201 and j['data']['external_id'] == 'koku-multi:1:7', 'segerak guru baharu (201)')
    sync_id = j['data']['id'] if st == 201 else 0
    st, j = api(A, 'POST', '/teachers/sync', tokA, {'external_id': 'koku-multi:1:7', 'name': 'Guru Segerak Dikemas', 'capacity': 15})
    check(st == 200 and j['data']['name'] == 'Guru Segerak Dikemas', 'segerak semula = kemas kini (200)')
    st, j = api(A, 'POST', '/teachers', tokA, {'name': 'Pendua', 'capacity': 10, 'external_id': 'koku-multi:1:7'})
    check(st == 409 and j['error']['code'] == 'duplicate_external_id', 'external_id berganda → duplicate_external_id')
    st, j = api(A, 'POST', '/teachers', tokR, {'name': 'X', 'capacity': 10})
    check(st == 403 and j['error']['code'] == 'insufficient_scope', 'kunci baca sahaja tidak boleh menulis')
    st, j = api(B, 'GET', '/summary', tokA)
    check(st == 401 and j['error']['code'] == 'unauthorized', 'kunci sekolah A ditolak di URL sekolah B')
    st, j = api(A, 'GET', '/summary', 'ut_' + '0' * 64)
    check(st == 401, 'kunci palsu ditolak')
    st, j = api(A, 'GET', '/workflow/published', tokA)
    check(st == 200 and j['data']['revision_id'] > 0, 'GET /workflow/published')
    st, j = api(A, 'GET', '/letters/%d' % CFG[A]['teacher_id'], tokA)
    check(st == 200 and j['data']['teacher']['task_count'] >= 1, 'GET /letters/{guru}')
    st, j = api(A, 'POST', '/allocation/preview', tokA, {'mode': 'vacant'})
    check(st == 200 and len(j['data']['plan_id']) == 48, 'POST /allocation/preview')
    st, j2 = api(A, 'POST', '/allocation/apply', tokA, {'plan_id': j['data']['plan_id']})
    check(st == 200 and 'changed' in j2['data'], 'POST /allocation/apply')
    st, j2 = api(A, 'POST', '/allocation/apply', tokA, {'plan_id': j['data']['plan_id']})
    check(st == 409 and j2['error']['code'] == 'stale_or_used_plan', 'apply berulang → stale_or_used_plan')
    st, j = api(A, 'POST', '/positions/bulk', tokA, {'rows': [{'name': 'Jawatan API', 'category': 'HEM', 'weight': 3, 'count': 2}]})
    check(st == 201 and j['data']['created'] == 2, 'POST /positions/bulk')
    st, j = api(A, 'DELETE', '/teachers/%d' % CFG[A]['teacher_id'], tokA)
    check(st == 409 and j['error']['code'] == 'record_in_use', 'padam guru dilantik → record_in_use')
    st, j = api(A, 'POST', '/teachers', tokA, {'name': 'Medan Asing', 'capacity': 10, 'role': 'x'})
    check(st == 422 and j['error']['code'] == 'validation_error', 'medan tidak dikenali ditolak')

    print('8. Pengasingan sekolah')
    admB = Client(B); admB.login('admin')
    s, p, _ = admB.req('/?page=guru')
    check('Guru Segerak' not in p and 'Cikgu Beban Tinggi' not in p, 'sekolah B tidak melihat guru sekolah A')
    s, p, _ = admB.post('agihan', {'action': 'agih', 'position_id': pids[2], 'teacher_id': tids[2]})
    check('sah' in err(p), 'sekolah B tidak boleh melantik dengan ID sekolah A')
    s, p, _ = admB.post('guru', {'action': 'guru_delete', 'id': tids[2]})
    check('tidak ditemui' in err(p), 'sekolah B tidak boleh memadam guru sekolah A')
    s, p, _ = admB.req('/?page=surat&semua=1')
    check('Belum ada agihan muktamad' in p and 'Guru Pertama UJT0001' not in p, 'surat sekolah A tidak kelihatan di B')
    st, j = api(A, 'GET', '/teachers/%d' % sync_id, tokA)
    check(st == 200, 'GET guru sendiri')
    s, p, _ = admB.req('/?page=kelulusan')
    check('PEJABAT GURU BESAR' not in p and ('Guru Besar' in p), 'SK: gelaran Guru Besar')
    s, p, _ = adm.req('/?page=users')
    check(all(x in p for x in ['Muktamadkan agihan', 'Simpan agihan automatik', 'Hantar untuk semakan', 'Segerak guru (API)']), 'jejak audit merekod aliran, auto-agihan & API')
    check('API #' in p and 'Pindaan ujian' in p, 'butiran audit (kunci API, sebab pindaan)')

    print('\n%d lulus, %d gagal' % (ok, fail))
    sys.exit(1 if fail else 0)


if __name__ == '__main__':
    main()
