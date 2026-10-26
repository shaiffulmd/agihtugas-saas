"""Ujian Subdomain Sekolah (Panel Platform → Subdomain Sekolah): tetapan panel mengatasi .env, mod semua / Pro sahaja,
   lencongan antara /KOD/ dan KOD.domain, titik semakan /hos-semak bertandatangan, ujian DNS/SSL/vhost & pengawal hos.
   Pemasangan ujian: PLATFORM_DOMAIN=localhost:PORT, TENANT_PRIMARY=subdomain. Tetapan dipulihkan (kembali ikut .env)."""
import http.cookiejar, json, os, re, secrets, subprocess, urllib.error, urllib.parse, urllib.request
from pathlib import Path
ROOT=Path(__file__).resolve().parents[1]
BASE=os.environ.get('TEST_URL','http://dem0001.localhost:8091')
PBASE=os.environ.get('PLATFORM_URL',re.sub(r'//[a-z]{3}[0-9]{4}\.','//',BASE)); DOM=PBASE.split('//',1)[1]
P='qadm'+secrets.token_hex(3); password=secrets.token_hex(18); secret='JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP'; passed=0
def run(code): return subprocess.check_output(['php','-r',"require 'app/bootstrap.php'; require_once 'app/settings.php'; require_once 'app/install.php'; require_once 'app/platform.php';"+code],cwd=ROOT,text=True).strip()
def sql(q,p=None,fetch=False): return json.loads(run('echo json_encode(query('+json.dumps(q)+','+json.dumps(p or [])+')->'+('fetchAll()' if fetch else 'rowCount()')+');'))
def check(ok,label):
    global passed
    assert ok,label
    passed+=1; print('PASS:',label,flush=True)
class NoRedir(urllib.request.HTTPRedirectHandler):
    def redirect_request(self,*a,**k): return None
class C:
    def __init__(s,base,follow=True):
        s.base=base; h=[urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar())]+([] if follow else [NoRedir()]); s.op=urllib.request.build_opener(*h)
    def req(s,path,data=None):
        if isinstance(data,dict): data=urllib.parse.urlencode(data,doseq=True).encode()
        try: r=s.op.open(path if path.startswith('http') else s.base+path,data)
        except urllib.error.HTTPError as e: r=e
        return r.code,r.read().decode('utf-8','replace'),r.headers
    def post(s,path,data):
        _,b,_=s.req(path); m=re.search(r'name="csrf" value="([a-f0-9]+)"',b); return s.req(path,{'csrf':m[1] if m else '',**data})
def code(): return run('echo totp('+json.dumps(secret)+',intdiv(time(),30));')
def loc(url): s,_,h=C('',False).req(url); return s,h.get('Location','')
pemail=P+'@platform.test'; saved=sql('SELECT name,value FROM platform_settings WHERE name IN ("cfg_domain_induk","cfg_subdomain_aktif","cfg_subdomain_siapa")',fetch=True)
run('query("INSERT INTO platform_admins(name,email,password_hash,totp_secret) VALUES (?,?,?,?)",["QA Domain",'+json.dumps(pemail)+',hash_password('+json.dumps(password)+'),encrypt_value('+json.dumps(secret)+')]);')
FREE='QDM'+str(secrets.randbelow(9000)+1000)
try:
    sql('DELETE FROM platform_settings WHERE name IN ("cfg_domain_induk","cfg_subdomain_aktif","cfg_subdomain_siapa")')
    run("school_create("+json.dumps(FREE)+",'SK Ujian Domain','SK','Admin Domain','"+FREE.lower()+"@sekolah.test');"); sql('UPDATE schools SET expires_on=DATE_SUB(CURDATE(),INTERVAL 5 DAY) WHERE code=?',[FREE])
    sql('UPDATE schools SET expires_on=DATE_ADD(CURDATE(),INTERVAL 100 DAY) WHERE code="DEM0001"')
    check(run("echo school_url('DEM0001','/');")==f'http://dem0001.{DOM}/','tanpa tetapan panel: ikut .env (TENANT_PRIMARY=subdomain)')
    c=bin2=secrets.token_hex(16); s,b,h=C('').req(f'http://uji0000.{DOM}/hos-semak?c='+c)
    check(s==200 and json.loads(b)['tanda']==run("echo host_check_sig('"+c+"');"),'/hos-semak di subdomain uji0000 menjawab tandatangan pemasangan ini')
    check(C('').req(f'http://uji0000.{DOM}/hos-semak?c=bukan')[0]==400,'/hos-semak menolak cabaran tidak sah')
    check(json.loads(run("echo json_encode(subdomain_test('"+DOM+"'));"))[0] is True,'ujian DNS/SSL/vhost berjaya untuk domain sebenar pemasangan')
    p=C(PBASE); sql('UPDATE platform_admins SET last_totp_step=-1 WHERE email=?',[pemail]); p.post('/platform/',{'action':'p_login','email':pemail,'password':password}); p.post('/platform/?p=verify',{'action':'p_verify','code':code()})
    s,b,_=p.req('/platform/?p=domain'); check(s==200 and 'Subdomain Sekolah' in b and '.env' in b and 'uji0000' in b,'halaman Subdomain Sekolah (nota .env, langkah persediaan)')
    s,b,_=p.post('/platform/?p=domain',{'action':'p_dom_test','domain_induk':'tiada-domain-qa.invalid'}); check('DNS: uji0000.tiada-domain-qa.invalid tidak ditemui' in b,'Uji sekarang: domain tanpa DNS wildcard dilaporkan')
    s,b,_=p.post('/platform/?p=domain',{'action':'p_dom_save','domain_induk':'contoh-lain.my','subdomain_aktif':'1','subdomain_siapa':'semua'}); check('Anda membuka panel ini melalui' in b and run("echo pset('domain_induk');")=='','domain induk berbeza daripada hos panel ditolak (elak terkunci)')
    s,b,_=p.post('/platform/?p=domain',{'action':'p_dom_save','domain_induk':'https://'+DOM+'/','subdomain_siapa':'semua'})
    check('Tetapan disimpan' in b and run("echo pset('domain_induk'),'|',pset('subdomain_aktif');")==DOM+'|0','simpan: domain dinormalkan, subdomain dimatikan (mengatasi .env)')
    s,l=loc(f'http://dem0001.{DOM}/?page=login'); check(s==302 and l==f'http://{DOM}/DEM0001/?page=login','subdomain dimatikan: KOD.domain dilencong ke /KOD/')
    check(C('').req(f'http://{DOM}/DEM0001/?page=login')[0]==200,'/KOD/ dilayan terus')
    s,b,_=p.post('/platform/?p=domain',{'action':'p_dom_save','domain_induk':DOM,'subdomain_aktif':'1','subdomain_siapa':'semua','paksa':'1'})
    s,l=loc(f'http://{DOM}/DEM0001/?page=login'); check('Subdomain sekolah aktif' in b and s==302 and l==f'http://dem0001.{DOM}/?page=login','hidupkan (semua sekolah): /KOD/ dilencong ke KOD.domain')
    p.post('/platform/?p=domain',{'action':'p_dom_save','domain_induk':DOM,'subdomain_aktif':'1','subdomain_siapa':'pro','paksa':'1'})
    s,l=loc(f'http://{DOM}/DEM0001/?page=login'); check(s==302 and l.startswith(f'http://dem0001.{DOM}/'),'Pro sahaja: sekolah Pro guna subdomain')
    s,l=loc(f'http://{FREE.lower()}.{DOM}/?page=login'); check(s==302 and l==f'http://{DOM}/{FREE}/?page=login','Pro sahaja: sekolah Free dilencong ke /KOD/')
    check(run("echo school_url('"+FREE+"','/');")==f'http://{DOM}/{FREE}/' and run("echo school_url('DEM0001','/');")==f'http://dem0001.{DOM}/','school_url ikut kelayakan Pro')
    s,b,_=p.req('/platform/?p=domain'); check('sekolah Pro' in b and 'Aktif' in b,'status di panel: aktif untuk sekolah Pro')
    s,b,_=C(PBASE).post('/platform/?p=domain',{'action':'p_dom_save','domain_induk':DOM}); check(run("echo pset('subdomain_siapa');")=='pro','tindakan tanpa log masuk platform tidak mengubah tetapan')
    print(f'All {passed} domain checks passed.',flush=True)
finally:
    sql('DELETE FROM platform_settings WHERE name IN ("cfg_domain_induk","cfg_subdomain_aktif","cfg_subdomain_siapa")')
    for r in saved: sql('INSERT INTO platform_settings(name,value) VALUES (?,?)',[r['name'],r['value']])
    for t in sql('SELECT id FROM schools WHERE code=?',[FREE],True): run("school_delete(school_by_id("+str(t['id'])+"));")
    sql('DELETE FROM platform_log WHERE admin_id IN (SELECT id FROM platform_admins WHERE email=?)',[pemail]); sql('DELETE FROM platform_admins WHERE email=?',[pemail]); run('audit_rebuild(0);')
