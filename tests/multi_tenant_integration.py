#!/usr/bin/env python3
"""Ujian pengasingan berbilang sekolah UrusTugas: rangkak semua halaman (4 peranan × 2 sekolah), IDOR GET/POST pada setiap
halaman & tindakan enjin, kuki sesi terikat sekolah, kunci API silang, panel platform (cipta → gantung → padam) dan sesi sokongan.

Pemasangan UJIAN sahaja (mengubah data):
  A) mod laluan (demo):     TEST_URL=http://127.0.0.1:8091 python3 tests/multi_tenant_integration.py          (DEMO_MODE=1)
  B) mod subdomain (local): TEST_URL=http://localhost:8092 MODE=subdomain SCHOOLS=DEM0001,LMA1234 python3 tests/multi_tenant_integration.py
Akaun QA (qa-*@…) dicipta dalam setiap sekolah dan dinyahaktifkan selepas ujian (log audit berantai dikekalkan).
Bahagian panel platform & sesi sokongan hanya dalam mod demo (akaun platform@demo.test)."""
import base64, hashlib, hmac, html, http.cookiejar, json, os, re, struct, subprocess, sys, time, urllib.error, urllib.parse, urllib.request
from pathlib import Path

BASE = os.environ.get('TEST_URL', 'http://127.0.0.1:8091').rstrip('/')
MODE = os.environ.get('MODE', 'path')
SCHOOLS = os.environ.get('SCHOOLS', 'DEM0001,DEM0002').split(',')
ROOT = Path(__file__).resolve().parents[1]
PW = 'Kata-Laluan-QA-Tenant-2026'
SECRET = 'JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP'
PLATFORM = ('platform@demo.test', 'Demo-Platform-2026')
ROLES = ['admin', 'pk', 'head', 'teacher']
ok = 0


def check(c, label):
    global ok
    assert c, label
    ok += 1
    print('  ✓', label, flush=True)


def php(code):
    return subprocess.check_output(['php', '-r', "require 'app/bootstrap.php'; foreach(['settings','install','integrity','images','users','demo','platform','subscription','enjin','tugas_auto','kelulusan','surat_lantikan'] as $f) require_once 'app/'.$f.'.php';" + code], cwd=ROOT, text=True).strip()


def totp():
    h = hmac.new(base64.b32decode(SECRET), struct.pack('>Q', int(time.time()) // 30), hashlib.sha1).digest()
    o = h[19] & 15
    return '%06d' % ((struct.unpack('>I', h[o:o + 4])[0] & 0x7fffffff) % 1000000)


class NoRedir(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, *a, **k):
        return None


class Client:
    def __init__(s, base):
        s.base = base
        s.jar = http.cookiejar.CookieJar()
        s.op = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(s.jar), NoRedir())

    def req(s, path, data=None, headers=None, follow=True):
        url = path if path.startswith('http') else s.base + path
        for _ in range(5):
            r = urllib.request.Request(url, None if data is None else urllib.parse.urlencode(data, doseq=True).encode(), headers or {})
            try:
                x = s.op.open(r, timeout=60); st, body, hd = x.status, x.read(), x.headers
            except urllib.error.HTTPError as e:
                st, body, hd = e.code, e.read(), e.headers
            if follow and st in (301, 302, 303) and hd.get('Location'):
                url = urllib.parse.urljoin(url, hd['Location']); data = None; continue
            return st, body.decode('utf-8', 'replace'), hd
        return st, body.decode('utf-8', 'replace'), hd

    def post(s, path, data):
        _, body, _ = s.req(path)
        m = re.search(r'name="csrf" value="([a-f0-9]+)"', body)
        return s.req(path, {'csrf': m[1] if m else '', **data})


def sbase(code):
    if MODE == 'subdomain':
        u = urllib.parse.urlparse(BASE)
        return f'{u.scheme}://{code.lower()}.{u.netloc}'
    return BASE + '/' + code


def login(code, role):
    c = Client(sbase(code))
    st, body, _ = c.post('/?page=login', {'action': 'login', 'email': f'qa-{role}@{code.lower()}.test', 'password': PW, 'website': ''})
    if 'name="action" value="verify"' in body:
        st, body, _ = c.post('/?page=verify', {'action': 'verify', 'code': totp()})
        if 'name="action" value="verify"' in body:
            time.sleep(31 - int(time.time()) % 30)
            st, body, _ = c.post('/?page=verify', {'action': 'verify', 'code': totp()})
    check('Log keluar' in body, f'{code}: log masuk QA {role}')
    return c


# ---------- Persediaan: akaun QA, penanda unik, rekod sasaran IDOR ----------
check(MODE in ('path', 'subdomain') and len(SCHOOLS) == 2, f'mod {MODE}, sekolah {SCHOOLS}')
php("query('DELETE FROM rate_limits');")
info = {}
for code, tag in zip(SCHOOLS, ['SATU', 'DUA']):
    info[code] = json.loads(php("""$s=school_find('%s') ?? exit('tiada'); echo json_encode(with_school($s,function(){
        $pw=hash_password('%s'); $sec=encrypt_value('%s'); $t=(int)query('SELECT t.id FROM teachers t WHERE t.school_id='.sid().' AND NOT EXISTS (SELECT 1 FROM users u WHERE u.school_id=t.school_id AND u.teacher_id=t.id AND u.email NOT LIKE "qa-%%") ORDER BY t.id LIMIT 1')->fetchColumn();
        if(!$t){ query('INSERT INTO teachers(school_id,name,capacity) VALUES ('.sid().',"ZZGURU",20)'); $t=(int)db()->lastInsertId(); }
        if(!query('SELECT 1 FROM positions WHERE school_id='.sid().' LIMIT 1')->fetchColumn()) query('INSERT INTO positions(school_id,name,category,weight) VALUES ('.sid().',"ZZJAWATAN","Pentadbiran",3)');
        foreach(['admin','pk','head','teacher'] as $r){ $e='qa-'.$r.'@'.strtolower(school()['code']).'.test';
            if($id=query('SELECT id FROM users WHERE school_id='.sid().' AND email=?',[$e])->fetchColumn()) query('UPDATE users SET password_hash=?,active=1,must_change=0,totp_secret=?,last_totp_step=-1,teacher_id=?,auth_version=auth_version+1 WHERE id=?',[$pw,$r==='admin'?$sec:null,$r==='teacher'?$t:null,$id]);
            else query('INSERT INTO users(school_id,name,email,password_hash,role,totp_secret,teacher_id) VALUES ('.sid().',?,?,?,?,?,?)',['QA '.$r,$e,$pw,$r,$r==='admin'?$sec:null,$r==='teacher'?$t:null]); }
        query('UPDATE teachers SET name=? WHERE school_id='.sid().' AND id=?',['ZZGURU-%s',$t]);
        query('UPDATE positions SET name="ZZJAWATAN-%s" WHERE school_id='.sid().' ORDER BY id LIMIT 1');
        query('INSERT INTO staff_notices(school_id,title,body,status) VALUES ('.sid().',"ZZHEBAHAN-%s","<p>QA</p>","terbit")');
        [,$tok]=api_key_create('QA tenant','write');
        $im=imagecreatetruecolor(9,9); imagefill($im,0,0,imagecolorallocate($im,%d,1,2)); ob_start(); imagepng($im); $logo=branding_store((string)ob_get_clean(),9,9);
        return ['sid'=>sid(),'name'=>school()['name'],'teacher'=>$t,'position'=>(int)query('SELECT id FROM positions WHERE school_id='.sid().' AND name LIKE "ZZJAWATAN%%"')->fetchColumn(),
            'assignment'=>(int)query('SELECT id FROM assignments WHERE school_id='.sid().' ORDER BY id LIMIT 1')->fetchColumn(),'notice'=>(int)query('SELECT MAX(id) FROM staff_notices WHERE school_id='.sid())->fetchColumn(),
            'user'=>(int)query('SELECT id FROM users WHERE school_id='.sid().' AND email LIKE "qa-pk@%%"')->fetchColumn(),'key'=>(int)query('SELECT MAX(id) FROM api_keys WHERE school_id='.sid())->fetchColumn(),'token'=>$tok,'logo'=>$logo];
    }));""" % (code, PW, SECRET, tag, tag, tag, 40 if tag == 'SATU' else 200)))
A, B = SCHOOLS
# Nama sekolah lain dijadikan penanda hanya jika bukan subrentetan nama sekolah sendiri (cth. "SMK Ujian" ⊂ "SMK Ujian Enjin").
nm = lambda me, other: [info[other]['name']] if info[other]['name'] not in info[me]['name'] else []
MARK = {A: ['ZZGURU-DUA', 'ZZJAWATAN-DUA', 'ZZHEBAHAN-DUA'] + nm(A, B), B: ['ZZGURU-SATU', 'ZZJAWATAN-SATU', 'ZZHEBAHAN-SATU'] + nm(B, A)}
SKIP = re.compile(r'logout|pdf=1|template|\.csv|/assets/|openapi|page=aset|bayar_qr|resit=')

print('1. Rangkak semua halaman (peranan × sekolah)')
clients = {}
for code in SCHOOLS:
    for role in ROLES:
        c = login(code, role); clients[(code, role)] = c
        seen, q, errors, leaks, unpref = set(), ['/?page=dashboard', '/'], [], [], []
        while q and len(seen) < 120:
            u = q.pop(0)
            if u in seen:
                continue
            seen.add(u)
            st, body, hd = c.req(u)
            if st >= 500:
                errors.append((st, u))
            leaks += [(m, u) for m in MARK[code] if m in body]
            if 'html' not in hd.get('Content-Type', ''):
                continue
            for l in map(html.unescape, re.findall(r'href="([^"#]+)', body)):
                rel = l[len('/' + code):] if MODE == 'path' and l.startswith('/' + code + '/') else l
                if MODE == 'path' and l.startswith('/') and not l.startswith(('/' + code + '/', '/assets/', '/platform')) and l != '/':
                    unpref.append((l, u))
                if rel.startswith('/?') and not SKIP.search(rel) and rel not in seen and rel != '/?page=login':
                    q.append(rel)
        check(not errors, f'{code}/{role}: {len(seen)} halaman tanpa 5xx' + (f' {errors[:3]}' if errors else ''))
        check(not leaks, f'{code}/{role}: tiada data sekolah lain' + (f' {leaks[:3]}' if leaks else ''))
        if MODE == 'path':
            check(not unpref, f'{code}/{role}: semua pautan dalaman berawalan /{code}/' + (f' {unpref[:3]}' if unpref else ''))

print('2. IDOR GET: rekod sekolah lain melalui sesi sekolah ini')
a = clients[(A, 'admin')]; o = info[B]
probes = [f"guru&edit={o['teacher']}", f"jawatan&edit={o['position']}", f"syarat&jawatan={o['position']}", f"surat&guru={o['teacher']}", f"users&user={o['user']}",
          f"avatar&id={o['user']}", f"hebahan&lihat={o['notice']}", f"hebahan&edit={o['notice']}", f"aset&id={o['logo']}"]
bad = [p for p in probes if any(m in a.req('/?page=' + p)[1] for m in MARK[A])]
check(not bad, f'{len(probes)} prob GET tidak mendedahkan data {B}' + (f': {bad}' if bad else ''))
st, body, hd = a.req('/?page=aset&id=' + o['logo'])
check(st == 404, f'logo sekolah {B} (hash kandungan unik) → 404 di {A}')
st, body, hd = a.req('/?page=aset&id=' + info[A]['logo'])
check(st == 200 and hd.get('Content-Type') == 'image/png', 'logo sekolah sendiri → 200')

print('3. IDOR POST: tindakan dengan ID sekolah lain tidak mengubah apa-apa')
snap = lambda: php("echo json_encode(with_school(school_find('%s'),fn()=>[query('SELECT GROUP_CONCAT(name ORDER BY id) FROM teachers WHERE school_id='.sid())->fetchColumn(),query('SELECT GROUP_CONCAT(CONCAT(name,weight) ORDER BY id) FROM positions WHERE school_id='.sid())->fetchColumn(),query('SELECT GROUP_CONCAT(CONCAT(position_id,\":\",teacher_id) ORDER BY id) FROM assignments WHERE school_id='.sid())->fetchColumn(),query('SELECT COUNT(*) FROM position_rules WHERE school_id='.sid())->fetchColumn(),query('SELECT GROUP_CONCAT(CONCAT(id,IFNULL(revoked_at,\"-\")) ORDER BY id) FROM api_keys WHERE school_id='.sid())->fetchColumn(),query('SELECT GROUP_CONCAT(CONCAT(role,active) ORDER BY id) FROM users WHERE school_id='.sid())->fetchColumn(),query('SELECT GROUP_CONCAT(title ORDER BY id) FROM staff_notices WHERE school_id='.sid())->fetchColumn()]));" % B)
before = snap()
php("with_school(school_find('%s'),fn()=>query('UPDATE workflow_state SET status=\"draft\" WHERE school_id='.sid()));" % A)
for page, d in [('guru', {'action': 'guru_delete', 'id': o['teacher']}), ('guru', {'action': 'guru_save', 'id': o['teacher'], 'name': 'DIUBAH', 'email': '', 'specialty': '', 'capacity': '9'}),
                ('jawatan', {'action': 'jawatan_delete', 'id': o['position']}), ('jawatan', {'action': 'jawatan_save', 'id': o['position'], 'name': 'DIUBAH', 'category': 'HEM', 'weight': '1'}),
                ('agihan', {'action': 'agih', 'position_id': o['position'], 'teacher_id': o['teacher']}), ('agihan', {'action': 'agih_lepas', 'id': o['assignment']}),
                ('syarat', {'action': 'syarat_jawatan', 'position_id': o['position'], 'fixed_teacher_id': '', 'required_specialty': 'X'}),
                ('api', {'action': 'api_batal', 'id': o['key']}), ('users', {'action': 'user_update', 'user_id': o['user'], 'name': 'X', 'role': 'admin', 'active': '0', 'admin_password': PW}),
                ('hebahan', {'action': 'hebahan_delete', 'id': o['notice']})]:
    a.post('/?page=' + page, d)
check(snap() == before, f'10 tindakan POST dengan ID {B}: data {B} tidak berubah')

print('4. Sesi & kunci API terikat sekolah')
ck = next(x for x in a.jar if x.name.endswith('urustugas_sesi'))
check(ck.path == ('/' + A + '/' if MODE == 'path' else '/'), f'kuki sesi terhad kepada {A} (path={ck.path}, domain={ck.domain})')
st, body, _ = Client(sbase(B)).req('/?page=dashboard', headers={'Cookie': ck.name + '=' + ck.value})
check('Log keluar' not in body, f'kuki sesi {A} tidak sah di {B}')
req = urllib.request.Request(sbase(B) + '/api/v1/summary', headers={'Authorization': 'Bearer ' + info[A]['token']})
try:
    st = urllib.request.urlopen(req, timeout=30).status
except urllib.error.HTTPError as e:
    st = e.code
check(st == 401, f'kunci API {A} di URL {B} → 401')
check(Client(sbase('XYZ9999') if MODE == 'path' else BASE).req('/XYZ9999/' if MODE == 'path' else '/XYZ9999/')[0] == 404, 'kod sekolah tidak wujud → 404')

if MODE == 'path' and php("echo env('DEMO_MODE');") == '1':
    print('5. Panel platform: cipta → log masuk → gantung → padam')
    p = Client(BASE)
    st, body, _ = p.post('/platform/', {'action': 'p_login', 'email': PLATFORM[0], 'password': PLATFORM[1]})
    check('Senarai sekolah' in body or 'Tambah sekolah' in body, 'log masuk panel platform (demo)')
    php("if($s=school_find('TST0001')) school_delete($s);")
    st, body, _ = p.post('/platform/?p=baru', {'action': 'p_school_create', 'code': 'TST0001', 'name': 'SK Ujian Tenant', 'type': 'SK', 'admin_name': 'Cikgu Uji', 'admin_email': 'utama@tst.test'})
    m = re.search(r'kata laluan sementara <code class="secret">([^<]+)', body)
    check(m is not None, 'sekolah baharu dicipta, kata laluan sementara dipaparkan sekali')
    t = Client(BASE + '/TST0001'); st, body, _ = t.post('/?page=login', {'action': 'login', 'email': 'utama@tst.test', 'password': html.unescape(m[1]), 'website': ''})
    check('kata laluan sementara' in body or 'page=security' in body or 'Keselamatan Akaun' in body, 'Pentadbir Utama baharu wajib tukar kata laluan')
    sid = php("echo school_find('TST0001')['id'];")
    check(php("echo with_school(school_find('TST0001'),fn()=>free_count('guru').'/'.workflow_state()['status'].'/'.head_title());") == '0/draft/Guru Besar', 'sekolah baharu: kosong, draf, SK → Guru Besar')
    loc = '/platform/?p=sekolah&id=' + sid
    p.post(loc, {'action': 'p_school_status', 'school_id': sid}); check(Client(BASE).req('/TST0001/')[0] == 503, 'sekolah digantung → 503')
    p.post(loc, {'action': 'p_school_status', 'school_id': sid}); check(Client(BASE).req('/TST0001/')[0] == 200, 'sekolah diaktifkan semula')
    p.post(loc, {'action': 'p_school_delete', 'school_id': sid, 'confirm_code': 'TST0001', 'password': PLATFORM[1]})
    check(Client(BASE).req('/TST0001/')[0] == 404 and php("echo query('SELECT COUNT(*) FROM users WHERE email=\"utama@tst.test\"')->fetchColumn();") == '0', 'sekolah dipadam bersama datanya')
    check(php("echo free_count('guru',%d);" % info[A]['sid']) != '0', 'sekolah lain tidak terjejas oleh pemadaman')
    print('6. Sesi sokongan platform (masuk sebagai pentadbir sekolah)')
    st, body, _ = p.req(f"/platform/?p=sekolah&id={info[A]['sid']}")
    uid = re.search(r'name="user_id"><option value="(\d+)"', body)[1]
    st, body, _ = p.post(f"/platform/?p=sekolah&id={info[A]['sid']}", {'action': 'p_impersonate', 'school_id': info[A]['sid'], 'user_id': uid, 'reason': ''})
    check('Nyatakan alasan' in body, 'alasan wajib')
    def issue(why):
        r = urllib.request.Request(BASE + f"/platform/?p=sekolah&id={info[A]['sid']}")
        _, b, _ = p.req(f"/platform/?p=sekolah&id={info[A]['sid']}")
        tok = re.search(r'name="csrf" value="([a-f0-9]+)"', b)[1]
        st, b2, hd = p.req(f"/platform/?p=sekolah&id={info[A]['sid']}", {'csrf': tok, 'action': 'p_impersonate', 'school_id': info[A]['sid'], 'user_id': uid, 'reason': why}, follow=False)
        return re.sub(r'^https?://[^/]+', '', hd.get('Location', ''))
    loc = issue('Ujian tenant #1'); check(f'/{A}/?page=masuk_platform&t=' in loc, 'token dijana')
    tok = urllib.parse.unquote(loc.split('t=', 1)[1])
    check(Client(BASE).req(f'/{B}/?page=masuk_platform&t=' + urllib.parse.quote(tok))[0] == 403, f'token {A} ditolak di {B}')
    s = Client(BASE); st, bd, _ = s.req(loc, follow=False); check(st == 200 and 'http-equiv="refresh"' in bd, 'masuk ke laman sekolah')
    check(Client(BASE).req(loc)[0] == 403, 'token sekali guna')
    st, body, _ = s.req(f'/{A}/?page=dashboard'); check('Sesi sokongan platform' in body, 'sepanduk sesi sokongan')
    st, body, _ = s.post(f'/{A}/?page=api', {'action': 'api_cipta', 'name': 'X', 'scope': 'read'}); check(('pentadbir sekolah sendiri' in body or 'Mod demo' in body) and php("echo query('SELECT COUNT(*) FROM api_keys WHERE name=\"X\"')->fetchColumn();")=='0', 'kunci API tidak boleh dicipta semasa sesi sokongan (sekatan sesi sokongan / demo)')
    p.post('/platform/', {'action': 'p_logout'}); check(s.req(f'/{A}/?page=dashboard')[0] == 401, 'log keluar platform menamatkan sesi sokongan')

# ---------- Kemas: nyahaktif akaun QA, buang penanda & kunci ----------
for code in SCHOOLS:
    php("with_school(school_find('%s'),function(){ query('UPDATE users SET active=0,auth_version=auth_version+1 WHERE school_id='.sid().' AND email LIKE \"qa-%%\"'); query('DELETE FROM api_keys WHERE school_id='.sid().' AND name=\"QA tenant\"'); query('DELETE FROM staff_notices WHERE school_id='.sid().' AND title LIKE \"ZZHEBAHAN%%\"'); });" % code)
    php("$s=school_find('%s'); with_school($s,fn()=>check_audit_ok());".replace('with_school($s,fn()=>check_audit_ok());', "echo audit_verify((int)$s['id'])['ok']?'':'RANTAI';") % code) == '' or check(False, f'{code}: rantai audit sah')
print(f'\n{ok} semakan berbilang sekolah lulus ({MODE}).')
