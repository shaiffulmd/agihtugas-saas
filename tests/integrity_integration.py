"""Ujian integriti fail & semakan kesihatan/uptime (tempatan sahaja). Mencipta fail QA sementara dan membuangnya semula.
Edisi multi: halaman Integriti & Status berada di panel platform (/platform/?p=sistem); admin sekolah disekat.
EXPOSURE_TEST=1 → jalankan juga ujian pendedahan fail (perlu pelayan berbilang benang, bukan `php -S`)."""
import http.cookiejar, json, os, re, secrets, subprocess, urllib.error, urllib.parse, urllib.request
from pathlib import Path
ROOT=Path(__file__).resolve().parents[1]
BASE=os.environ.get('TEST_URL','http://dem0001.localhost:8091')
PBASE=os.environ.get('PLATFORM_URL',re.sub(r'//[a-z]{3}[0-9]{4}\.','//',BASE))   # domain induk (panel platform)
prefix='QAINT'+secrets.token_hex(3).upper(); password=secrets.token_hex(18); secret='JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP'; passed=0
def run(code,school=True): return subprocess.check_output(['php','-r',"require 'app/bootstrap.php'; "+("tenant_use(school_find(getenv('TEST_SCHOOL')?:'DEM0001'),'subdomain'); " if school else '')+"require 'app/integrity.php';"+code],cwd=ROOT,text=True).strip()
def php(code): return run(code)
def sql(q,p=None,fetch=False,school=True): return json.loads(run('echo json_encode(query('+json.dumps(q)+','+json.dumps(p or [])+')->'+('fetchAll()' if fetch else 'rowCount()')+');',school))
def check(ok,label):
    global passed
    assert ok,label
    passed+=1; print('PASS:',label,flush=True)
def client(base=BASE):
    c=urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar())); c.base=base; return c
def req(c,path,data=None):
    if isinstance(data,dict): data=urllib.parse.urlencode(data).encode()
    try: r=c.open(c.base+path,data)
    except urllib.error.HTTPError as e: r=e
    return r.code,r.read().decode('utf-8','replace'),r.headers
def csrf(b): return re.search(r'name="csrf" value="([a-f0-9]+)"',b)[1]
def post(c,path,data):
    _,b,_=req(c,path); return req(c,path,{'csrf':csrf(b),**data})
def code(): return php('echo totp('+json.dumps(secret)+',intdiv(time(),30));')
def login(c,email):
    sql('UPDATE users SET last_totp_step=-1 WHERE email=?',[email]); post(c,'/?page=login',{'action':'login','email':email,'password':password})
    return post(c,'/?page=verify',{'action':'verify','code':code()})
def plogin(c,email):
    sql('UPDATE platform_admins SET last_totp_step=-1 WHERE email=?',[email],school=False); post(c,'/platform/',{'action':'p_login','email':email,'password':password})
    return post(c,'/platform/?p=verify',{'action':'p_verify','code':code()})
SYS='/platform/?p=sistem'

check(php("echo env('APP_ENV');")=='local','local environment only')
sql('DELETE FROM rate_limits')   # pangkalan data ujian sahaja: elak had cubaan log masuk daripada ujian terdahulu
emails={'admin':prefix.lower()+'a@demo.test','teacher':prefix.lower()+'g@demo.test'}; pemail=prefix.lower()+'p@platform.test'
shell=ROOT/'public_html'/'qa-int-test.php'; evil=ROOT/'storage'/'qa-evil.php'; basefile=ROOT/'storage'/'integrity.json'
saved_base=basefile.read_bytes() if basefile.exists() else None
saved_sys=sql('SELECT name,value FROM platform_settings WHERE name LIKE "sys\\_%"',fetch=True,school=False)
try:
    for role,email in emails.items():
        php('query("INSERT INTO users(school_id,name,email,password_hash,role,totp_secret) VALUES ((SELECT id FROM schools WHERE code=0x44454d30303031),?,?,?,?,?)",['+json.dumps(prefix+' '+role)+','+json.dumps(email)+',hash_password('+json.dumps(password)+'),'+json.dumps('admin' if role=='admin' else 'teacher')+',encrypt_value('+json.dumps(secret)+')]);')
    # Pentadbir platform: rahsia 2FA disulit dengan subkunci platform (konteks tanpa sekolah).
    run('query("INSERT INTO platform_admins(name,email,password_hash,totp_secret) VALUES (?,?,?,?)",['+json.dumps(prefix+' platform')+','+json.dumps(pemail)+',hash_password('+json.dumps(password)+'),encrypt_value('+json.dumps(secret)+')]);',False)
    admin,guru,plat=client(),client(),client(PBASE); login(admin,emails['admin']); login(guru,emails['teacher']); plogin(plat,pemail)
    check(req(plat,SYS)[0]==200,'platform admin logged in (2FA)')
    anon=client()
    s,b,h=req(anon,'/sihat.php'); check(s==200 and b.strip()=='OK','basic health OK')
    check('Set-Cookie' not in h,'health endpoint starts no session')
    s,b,_=req(anon,'/sihat.php?k=salah'); check(s==200 and b.strip()=='OK','wrong key falls back to basic (no oracle)')
    check(req(guru,'/?page=system')[0]==403 and req(admin,'/?page=system')[0]==403,'school users (incl. admin) blocked from system page')
    _,b,_=req(admin,'/?page=dashboard'); s,_,_=req(admin,'/?page=dashboard',{'csrf':csrf(b),'action':'system_health_key'}); check(s==403,'school admin cannot run system actions')
    check('Log masuk platform' in req(client(PBASE),SYS)[1],'platform system page needs platform login')
    s,b,_=req(plat,SYS); check(s==200 and 'Integriti & Status' in b,'platform system page')
    post(plat,SYS,{'action':'system_health_key'}); key=php("echo health_key();"); check(len(key)==32,'monitor key generated')
    php("integrity_accept('qa'); integrity_check();")   # mulakan daripada asas yang bersih
    sql('DELETE FROM health_log WHERE source="monitor" AND checked_at>DATE_SUB(NOW(), INTERVAL 10 MINUTE)')   # had 1 rekod / 4 minit
    before=sql('SELECT COUNT(*) n FROM health_log',fetch=True)[0]['n']
    s,b,_=req(anon,'/sihat.php?k='+key); check(s==200 and b.strip()=='OK','full health OK')
    check(sql('SELECT COUNT(*) n FROM health_log',fetch=True)[0]['n']==before+1,'monitor check logged')
    s,b,_=post(plat,SYS,{'action':'system_integrity_check'}); check('semua fail sepadan' in b or 'Semakan selesai' in b,'integrity check runs')
    php("integrity_accept('qa'); integrity_check();")
    shell.write_text('<?php echo 1;'); evil.write_text('<?php system($_GET["c"]);')
    s,b,_=post(plat,SYS,{'action':'system_integrity_check'})
    r=json.loads(php("echo json_encode(integrity_last());"))
    check(r['status']=='changed' and 'public_html/qa-int-test.php' in r['added'],'new PHP file detected')
    check('storage/qa-evil.php' in r['suspicious'],'web shell in storage detected')
    _,b,_=req(plat,'/platform/'); check('Amaran integriti' in b,'platform admin warned on every panel page')
    _,b,_=req(admin,'/?page=dashboard'); check('Amaran integriti' not in b,'school admin not shown server internals')
    s,b,_=req(anon,'/sihat.php?k='+key); check(s==503 and 'integriti fail' in b,'full health reports integrity problem')
    s,b,_=req(anon,'/sihat.php'); check(s==200 and b.strip()=='OK','basic health hides details')
    s,b,_=post(plat,SYS,{'action':'system_integrity_accept','confirm_accept':'yes','admin_password':'salah-salah'}); check('tidak sah' in b,'accept needs platform admin password')
    s,b,_=post(plat,SYS,{'action':'system_integrity_accept','admin_password':password}); check('Tandakan pengesahan' in b,'accept needs confirmation')
    evil.unlink(); shell.unlink()
    s,b,_=post(plat,SYS,{'action':'system_integrity_accept','confirm_accept':'yes','admin_password':password}); check('Asas integriti baharu' in b,'baseline accepted')
    check(json.loads(php("echo json_encode(integrity_last());"))['problem'] is False,'clean after accept')
    j=json.loads(basefile.read_text()); first=next(iter(j['files'])); j['files'][first]='0'*64; basefile.write_text(json.dumps(j))
    check(json.loads(php("echo json_encode(integrity_check());"))['status']=='baseline_broken','tampered baseline detected (HMAC)')
    basefile.unlink(); check(json.loads(php("echo json_encode(integrity_check());"))['status']=='baseline_missing','deleted baseline detected')
    if os.environ.get('EXPOSURE_TEST')=='1':
        s,b,_=post(plat,SYS,{'action':'system_exposure'}); check('Ujian pendedahan fail selesai' in b and 'TERDEDAH' not in b,'exposure test: nothing exposed')
    else: print('LANGKAU: ujian pendedahan fail (tetapkan EXPOSURE_TEST=1 pada pelayan berbilang benang)')
    s,b,_=post(plat,SYS,{'action':'system_health_now'}); check('Semakan kesihatan' in b,'manual health check')
    _,b,_=req(plat,SYS); check('uptime-bars' in b and 'style=' not in b.split('uptime-bars')[1][:4000],'uptime chart rendered without inline styles')
    check(sql('SELECT COUNT(*) n FROM platform_log WHERE action IN ("integrity_check","integrity_accept","health_key")',fetch=True,school=False)[0]['n']>0,'system actions logged in platform_log')
finally:
    for f in (shell,evil):
        if f.exists(): f.unlink()
    if saved_base is not None: basefile.write_bytes(saved_base)
    elif basefile.exists(): basefile.unlink()
    sql('DELETE FROM platform_settings WHERE name LIKE "sys\\_%"',school=False)
    for r in saved_sys: sql('INSERT INTO platform_settings(name,value) VALUES (?,?)',[r['name'],r['value']],school=False)
    for i in [u['id'] for u in sql('SELECT id FROM users WHERE email LIKE ?',[prefix.lower()+'%'],True)]:
        sql('UPDATE audit_logs SET user_id=NULL WHERE user_id=?',[i]); sql('DELETE FROM users WHERE id=?',[i])
    php("audit_rebuild(school_find(getenv('TEST_SCHOOL')?:'DEM0001')['id']);")  # pembersihan di atas mengubah baris audit; tandatangan semula rantai ujian
    sql('DELETE FROM platform_admins WHERE email=?',[pemail],school=False)
print(f'All {passed} integrity/uptime checks passed.')
