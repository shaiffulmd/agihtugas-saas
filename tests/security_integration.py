"""Ujian keselamatan UrusTugas (rangka koku-multi Fasa C): rantai audit HMAC, sekatan IP (platform/sekolah/auto, senarai IP, had IP akaun),
   Cloudflare & CAPTCHA pihak ketiga (pelayan olok-olok 127.0.0.1:8095), wajib 2FA, mod kecemasan, security.txt & laporan.
   Tempatan sahaja: APP_ENV=local, PAYMENT_TEST_BASE=http://127.0.0.1:8095 dalam .env, SESSION_DIR = session.save_path pelayan.
   Klien ujian datang dari 127.0.0.1 — sekatan terhadap IP itu sentiasa dibuang dalam finally."""
import base64, glob, http.cookiejar, http.server, json, os, re, secrets, subprocess, threading, time, urllib.error, urllib.parse, urllib.request
from pathlib import Path
ROOT=Path(__file__).resolve().parents[1]
BASE=os.environ.get('TEST_URL','http://dem0001.localhost:8091')
PBASE=os.environ.get('PLATFORM_URL',re.sub(r'//[a-z]{3}[0-9]{4}\.','//',BASE))
SESS=os.environ.get('SESSION_DIR','/var/lib/php/sessions'); GW_PORT=8095
P='qasc'+secrets.token_hex(3); password=secrets.token_hex(18); secret='JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP'; passed=0
MAILDIR=ROOT/'storage'/'mail'; ME='127.0.0.1'
def run(code): return subprocess.check_output(['php','-r',"require 'app/bootstrap.php'; require_once 'app/settings.php'; require_once 'app/install.php'; require_once 'app/platform.php';"+code],cwd=ROOT,text=True).strip()
def sql(q,p=None,fetch=False): return json.loads(run('echo json_encode(query('+json.dumps(q)+','+json.dumps(p or [])+')->'+('fetchAll()' if fetch else 'rowCount()')+');'))
def one(q,p=None): r=sql(q,p,True); return r[0] if r else None
def check(ok,label):
    global passed
    assert ok,label
    passed+=1; print('PASS:',label,flush=True)
class C:
    def __init__(s,base): s.base=base; s.jar=http.cookiejar.CookieJar(); s.op=urllib.request.build_opener(urllib.request.HTTPCookieProcessor(s.jar))
    def req(s,path,data=None):
        if isinstance(data,dict): data=urllib.parse.urlencode(data,doseq=True).encode()
        try: r=s.op.open(path if path.startswith('http') else s.base+path,data)
        except urllib.error.HTTPError as e: r=e
        return r.code,r.read().decode('utf-8','replace'),r.headers
    def csrf(s,path): _,b,_=s.req(path); m=re.search(r'name="csrf" value="([a-f0-9]+)"',b); return m[1] if m else ''
    def post(s,path,data): return s.req(path,{'csrf':s.csrf(path),**data})
    def cap(s,b,name):
        cid=re.search(r'name="cap_id" value="([a-f0-9]{16})"',b)
        if not cid: return {}
        sid=next(c.value for c in s.jar if c.name.endswith(name)); raw=Path(SESS,'sess_'+sid).read_text(errors='ignore')
        return {'cap_id':cid[1],'captcha':re.search(r's:16:"'+cid[1]+r'";a:4:\{i:0;s:5:"([A-Z0-9]{5})"',raw)[1]}
def code(): return run('echo totp('+json.dumps(secret)+',intdiv(time(),30));')
def plogin(c,email):
    sql('UPDATE platform_admins SET last_totp_step=-1 WHERE email=?',[email]); c.post('/platform/',{'action':'p_login','email':email,'password':password})
    return c.post('/platform/?p=verify',{'action':'p_verify','code':code()})
def mails(to):
    out=[]
    for f in sorted(glob.glob(str(MAILDIR/'*.eml')),key=os.path.getmtime):
        t=Path(f).read_text(errors='ignore')
        if '<'+to+'>' in t.split('\r\n\r\n')[0]: out.append(base64.b64decode(re.search(r'X-Text: (\S+)',t)[1]).decode())
    return out
def school_new(code,pw):
    run("[$s]=school_create("+json.dumps(code)+",'SK Ujian Keselamatan','SK','Admin Keselamatan',"+json.dumps(code.lower()+'@sekolah.test')+","+json.dumps(pw)+"); tenant_use($s,'path'); query('UPDATE users SET must_change=0,totp_secret=? WHERE school_id=?',[encrypt_value("+json.dumps(secret)+"),sid()]);"
        +"query('INSERT INTO users(school_id,name,email,password_hash,role,totp_secret) VALUES (?,?,?,?,\"teacher\",?)',[sid(),'Guru QA','guru@"+code.lower()+".test',hash_password("+json.dumps(pw)+"),encrypt_value("+json.dumps(secret)+")]);")
    return int(one('SELECT id FROM schools WHERE code=?',[code])['id'])
def sbase(sc): return re.sub(r'//',r'//'+sc.lower()+'.',PBASE,count=1)
def slogin(c,email,pw):
    s,b,_=c.post('/?page=login',{'action':'login','email':email,'password':pw,'website':''})
    if 'value="verify"' not in b: return s,b
    sql('UPDATE users SET last_totp_step=-1 WHERE email=?',[email]); return c.post('/?page=verify',{'action':'verify','code':code()})
def verify(code_): return json.loads(run("$s="+("0" if code_=='PLATFORM' else "(int)school_find("+json.dumps(code_)+")['id']")+"; echo json_encode(audit_verify($s));"))
def unblock_me(): sql('DELETE FROM ip_blocks WHERE ip LIKE "127.%" OR ip LIKE "::%"')

# ---------- Pelayan olok-olok: Cloudflare API & siteverify CAPTCHA ----------
SEEN=[]; ZONE=secrets.token_hex(16); CF_TOKEN='cf-'+secrets.token_hex(8); TS_SECRET='ts-secret-'+secrets.token_hex(6)
class GW(http.server.BaseHTTPRequestHandler):
    def log_message(self,*a): pass
    def send(self,obj,code=200):
        b=json.dumps(obj).encode(); self.send_response(code); self.send_header('Content-Type','application/json'); self.send_header('Content-Length',str(len(b))); self.end_headers(); self.wfile.write(b)
    def rec(self):
        n=int(self.headers.get('Content-Length') or 0); raw=self.rfile.read(n).decode(); SEEN.append((self.command,self.path,raw,self.headers.get('Authorization',''))); return raw
    def auth(self): return self.headers.get('Authorization','')=='Bearer '+CF_TOKEN
    def do_GET(self):
        self.rec(); return self.send({'success':self.auth() and self.path==f'/zones/{ZONE}','result':{'id':ZONE}},200 if self.auth() else 403)
    def do_DELETE(self): self.rec(); return self.send({'success':self.auth(),'result':{'id':self.path.rsplit('/',1)[1]}})
    def do_POST(self):
        raw=self.rec()
        if self.path=='/turnstile/v0/siteverify':
            f=dict(urllib.parse.parse_qsl(raw)); return self.send({'success':f.get('secret')==TS_SECRET and f.get('response')=='lulus-ok'})
        if self.path==f'/zones/{ZONE}/firewall/access_rules/rules' and self.auth(): return self.send({'success':True,'result':{'id':'cf'+secrets.token_hex(4)}})
        return self.send({'success':False,'errors':[{'message':'tidak dikenali'}]},400)
srv=http.server.ThreadingHTTPServer(('127.0.0.1',GW_PORT),GW); threading.Thread(target=srv.serve_forever,daemon=True).start()

check(run("echo env('APP_ENV');")=='local','persekitaran tempatan')
pemail=P+'@platform.test'; saved=sql('SELECT name,value FROM platform_settings WHERE name LIKE "cfg\\_%"',fetch=True); sql('DELETE FROM rate_limits'); unblock_me()
run('query("INSERT INTO platform_admins(name,email,password_hash,totp_secret) VALUES (?,?,?,?)",['+json.dumps('QA Keselamatan')+','+json.dumps(pemail)+',hash_password('+json.dumps(password)+'),encrypt_value('+json.dumps(secret)+')]);')
p2email=P+'b@platform.test'; run('query("INSERT INTO platform_admins(name,email,password_hash,totp_secret) VALUES (?,?,?,?)",["QA Kedua",'+json.dumps(p2email)+',hash_password('+json.dumps(password)+'),encrypt_value('+json.dumps(secret)+')]);')
run("pset_set(['mail_method'=>'log','mail_from'=>'noreply@"+P+".test','emel_notis'=>'"+P+"@notis.test','ip_lindung'=>'1','ip_auto'=>'1','ip_auto_emel'=>'1','ip_platform'=>'','ip_platform_wajib'=>'0','kecemasan'=>'','cap_luar'=>'0','wajib_2fa'=>'0','cf_aktif'=>'0']);")
run('audit_rebuild(0);')
K='QSC'+str(secrets.randbelow(9000)+1000); kpw=secrets.token_hex(12)
try:
    kid=school_new(K,kpw); p=C(PBASE); plogin(p,pemail)
    # ---- Rantai audit ----
    a=C(sbase(K)); slogin(a,K.lower()+'@sekolah.test',kpw); a.req('/?page=dashboard')
    v=verify(K); check(v['ok'] and v['rows']>=2,'rantai audit sekolah sah selepas log masuk (baris berantai)')
    check(verify('PLATFORM')['ok'],'rantai log platform sah')
    row=one('SELECT id,seq,chain FROM audit_logs WHERE school_id=? ORDER BY seq LIMIT 1',[kid]); check(row['seq']==1 and len(row['chain'])==64,'baris pertama seq 1 dengan HMAC 64 aksara')
    sql('UPDATE audit_logs SET action="tukar_senyap" WHERE id=?',[row['id']]); v=verify(K); check(not v['ok'] and any('diubah' in x for x in v['problems']),'baris diubah terus dalam DB dikesan')
    s,b,_=p.post('/platform/?p=audit',{'action':'p_sec_audit_rebuild','kod':K,'admin_password':'salah'}); check('tidak sah' in b and not verify(K)['ok'],'tandatangan semula memerlukan kata laluan pentadbir platform')
    s,b,_=p.post('/platform/?p=audit',{'action':'p_sec_audit_rebuild','kod':K,'admin_password':password}); check('ditandatangani semula' in b and verify(K)['ok'],'tandatangan semula (dilog) → rantai sah semula')
    a.req('/?page=users'); a.req('/?page=settings'); run("tenant_use(school_find("+json.dumps(K)+"),'path'); audit('qa_1','x'); audit('qa_2','x'); audit('qa_3','x');")
    mid=one('SELECT id FROM audit_logs WHERE school_id=? AND action="qa_2"',[kid])['id']; sql('DELETE FROM audit_logs WHERE id=?',[mid]); v=verify(K); check(not v['ok'] and any('Baris hilang' in x for x in v['problems']),'baris dipadam di tengah dikesan (seq melompat)')
    run(f'audit_rebuild({kid});'); last=one('SELECT id FROM audit_logs WHERE school_id=? ORDER BY seq DESC LIMIT 1',[kid])['id']; sql('DELETE FROM audit_logs WHERE id=?',[last]); v=verify(K)
    check(not v['ok'] and any('terakhir dipadam' in x for x in v['problems']),'baris terakhir dibuang dikesan (kepala rantai)')
    run(f'audit_rebuild({kid});')
    s,b,_=p.req('/platform/?p=audit&semak=1'); check(K in b and 'Sah' in b,'platform: sahkan semua rantai')
    s,b,h=p.post('/platform/?p=audit',{'action':'p_sec_audit_csv','kod':K,'dari':'2020-01-01','hingga':time.strftime('%Y-%m-%d')}); check('text/csv' in h.get('Content-Type','') and 'Seq' in b and 'qa_1' in b,'eksport CSV log sekolah dengan seq & rantai')
    # ---- Sekatan IP sekolah ----
    s,b,_=a.post('/?page=ipsekat',{'action':'sec_ip_add','ip':ME,'skop':'akaun','tempoh':'60','sebab':'x'}); check('alamat anda sendiri' in b,'sekolah tidak boleh menyekat IP sendiri')
    s,b,_=a.post('/?page=ipsekat',{'action':'sec_ip_add','ip':'198.51.100.0/24','skop':'semua','tempoh':'60','sebab':'QA'}); bid=one('SELECT id,school_id FROM ip_blocks WHERE ip="198.51.100.0/24"')
    check('disekat' in b and bid['school_id']==kid,'sekolah menyekat julat IP untuk laman sendiri')
    s,b,_=a.post('/?page=ipsekat',{'action':'sec_ip_add','ip':'0.0.0.0/0','skop':'semua','tempoh':'60','sebab':''}); check('tidak sah' in b,'CIDR terlalu luas (/0) ditolak')
    run(f"ip_block_add({kid},'{ME}','akaun',60,'QA tetamu','qa');")
    anon=C(sbase(K)); s,b,_=anon.req('/?page=login'); check(s==403 and 'disekat' in b,'skop akaun: tetamu dari IP disekat → 403')
    s,b,_=a.req('/?page=dashboard'); check(s==200,'skop akaun: pengguna yang sudah log masuk tidak terjejas')
    check(C(sbase('DEM0001')).req('/?page=login')[0]==200,'sekatan sekolah tidak terpakai di sekolah lain')
    unblock_me(); run(f"ip_block_add({kid},'{ME}','semua',60,'QA semua','qa');"); check(a.req('/?page=dashboard')[0]==403,'skop semua: pengguna log masuk juga disekat')
    out=subprocess.check_output(['php','bin/security.php','unblock',ME],cwd=ROOT,text=True); check('1 sekatan dibuang' in out and a.req('/?page=dashboard')[0]==200,'CLI unblock membuang sekatan (pemulihan)')
    run("pset_set(['ip_lindung'=>'0']);"); run(f"ip_block_add({kid},'{ME}','semua',60,'QA','qa');"); check(a.req('/?page=dashboard')[0]==200,'suis utama mati: sekatan diabaikan'); unblock_me(); run("pset_set(['ip_lindung'=>'1']);")
    # ---- Sekatan automatik ----
    n0=len(mails(P+'@notis.test')); run("tenant_use(school_find("+json.dumps(K)+"),'path'); query('INSERT INTO rate_limits(bucket,attempts,window_start) VALUES (?,30,NOW()) ON DUPLICATE KEY UPDATE attempts=30,window_start=NOW()',[rate_key('login-ip','"+ME+"')]);")
    s,b,_=C(sbase(K)).post('/?page=login',{'action':'login','email':'x@x.test','password':'x','website':''}); ab=one('SELECT * FROM ip_blocks WHERE school_id=? AND auto=1',[kid])
    check(ab and ab['ip']==ME and ab['scope']=='akaun' and len(mails(P+'@notis.test'))==n0+1,'cubaan log masuk berlebihan → sekatan automatik sekolah (skop akaun) + e-mel notis')
    check(one('SELECT COUNT(*) n FROM platform_log WHERE action="ip_auto_block" AND school_id=?',[kid])['n']>=1,'sekatan automatik dilog di log platform')
    unblock_me(); sql('DELETE FROM rate_limits')
    run("pset_set(['ip_platform'=>'"+ME+"']);"); run("tenant_use(school_find("+json.dumps(K)+"),'path'); query('INSERT INTO rate_limits(bucket,attempts,window_start) VALUES (?,30,NOW()) ON DUPLICATE KEY UPDATE attempts=30',[rate_key('login-ip','"+ME+"')]);")
    C(sbase(K)).post('/?page=login',{'action':'login','email':'x@x.test','password':'x','website':''}); check(not one('SELECT id FROM ip_blocks WHERE auto=1 AND ip=?',[ME]),'IP pentadbir platform tidak pernah disekat secara automatik')
    sql('DELETE FROM rate_limits'); run("pset_set(['ip_platform'=>'']);")
    run("query('INSERT INTO rate_limits(bucket,attempts,window_start) VALUES (?,10,NOW()) ON DUPLICATE KEY UPDATE attempts=10,window_start=NOW()',[rate_key('platform-login-ip','"+ME+"')]);")
    C(PBASE).post('/platform/',{'action':'p_login','email':'x@x.test','password':'x'}); pb=one('SELECT * FROM ip_blocks WHERE school_id IS NULL AND auto=1 AND ip=?',[ME])
    check(pb and C(PBASE).req('/platform/')[0]==403 and C(sbase(K)).req('/?page=login')[0]==200,'auto-sekat log masuk platform: panel platform disekat, laman sekolah (IP NAT) tidak terjejas')
    unblock_me(); sql('DELETE FROM rate_limits')
    # ---- Platform: sekatan, senarai IP panel, had IP pentadbir ----
    s,b,_=p.post('/platform/?p=ipsekat',{'action':'p_sec_ip_add','ip':ME,'skop':'akaun','tempoh':'60','kod':'','sebab':'x'}); check('meliputi alamat anda' in b,'platform: amaran menyekat IP sendiri tanpa pengesahan')
    s,b,_=p.post('/platform/?p=ipsekat',{'action':'p_sec_ip_add','ip':ME,'skop':'akaun','tempoh':'60','kod':'','sebab':'QA','saya':'1'})
    check('disekat' in b and C(PBASE).req('/')[0]==403 and C(sbase('DEM0001')).req('/?page=login')[0]==403 and p.req('/platform/')[0]==200,'sekatan platform (akaun): tetamu di semua sekolah & direktori disekat; pentadbir log masuk tidak terjejas')
    bid=one('SELECT id FROM ip_blocks WHERE school_id IS NULL AND ip=?',[ME])['id']; p.post('/platform/?p=ipsekat',{'action':'p_sec_ip_remove','id':bid}); check(C(PBASE).req('/')[0]==200,'platform membuang sekatan')
    s,b,_=p.post('/platform/?p=ipsekat',{'action':'p_sec_ip_settings','ip_lindung':'1','ip_auto':'1','ip_auto_minit':'60','ip_auto_emel':'1','ip_platform':'10.9.8.7','ip_platform_wajib':'1'}); check('mesti mengandungi IP anda' in b,'senarai IP panel tanpa IP sendiri ditolak (elak terkunci)')
    s,b,_=p.post('/platform/?p=ipsekat',{'action':'p_sec_ip_settings','ip_lindung':'1','ip_auto':'1','ip_auto_minit':'60','ip_auto_emel':'1','ip_platform':ME+'\n10.9.8.0/24','ip_platform_wajib':'1'}); check('disimpan' in b and C(PBASE).req('/platform/')[0]==200,'senarai IP panel platform disimpan (IP sendiri disertakan)')
    run("pset_set(['ip_platform'=>'10.9.8.7']);"); check(C(PBASE).req('/platform/')[0]==403 and C(PBASE).req('/')[0]==200,'panel platform di luar senarai IP → 403 (direktori awam tidak terjejas)')
    subprocess.check_output(['php','bin/security.php','allow-clear'],cwd=ROOT); check(C(PBASE).req('/platform/')[0]==200,'CLI allow-clear memulihkan akses panel')
    run("pset_set(['ip_platform'=>'']);")
    p2=one('SELECT id FROM platform_admins WHERE email=?',[p2email])['id']
    s,b,_=p.post('/platform/?p=pentadbir',{'action':'pa_ips','admin_id':p2,'allowed_ips':'10.1.2.3','admin_password':password}); check('Had IP' in b,'had IP pentadbir platform lain disimpan')
    c2=C(PBASE); s,b,_=c2.post('/platform/',{'action':'p_login','email':p2email,'password':password}); check('tidak dibenarkan dari alamat IP' in b,'pentadbir platform dengan had IP tidak boleh log masuk dari IP lain')
    # ---- Had IP pengguna sekolah & IP pentadbir sekolah ----
    gid=one('SELECT id FROM users WHERE email=?',['guru@'+K.lower()+'.test'])['id']; g=C(sbase(K)); slogin(g,'guru@'+K.lower()+'.test',kpw); check(g.req('/?page=dashboard')[0]==200,'guru log masuk')
    s,b,_=a.post('/?page=users&user='+str(gid),{'action':'user_ips','user_id':gid,'allowed_ips':'10.20.30.0/24','admin_password':kpw})
    check(one('SELECT allowed_ips FROM users WHERE id=?',[gid])['allowed_ips']=='10.20.30.0/24' and 'Log keluar' not in g.req('/?page=dashboard')[1],'had IP akaun guru disimpan; sesi sedia ada ditamatkan')
    sql('UPDATE users SET allowed_ips=NULL WHERE id=?',[gid]); g=C(sbase(K)); slogin(g,'guru@'+K.lower()+'.test',kpw); sql('UPDATE users SET allowed_ips="10.20.30.0/24" WHERE id=?',[gid])
    check(g.req('/?page=dashboard')[0]==403,'had IP disemak setiap permintaan (sesi dari IP lain → 403)')
    s,b=slogin(C(sbase(K)),'guru@'+K.lower()+'.test',kpw); check('tidak dibenarkan dari alamat IP' in b,'guru dengan had IP tidak boleh log masuk dari IP lain')
    aid=one('SELECT id FROM users WHERE email=?',[K.lower()+'@sekolah.test'])['id']
    s,b,_=a.post('/?page=users&user='+str(aid),{'action':'user_ips','user_id':aid,'allowed_ips':'10.20.30.40','admin_password':kpw}); check('mesti mengandungi IP anda' in b,'pentadbir tidak boleh mengunci akaun sendiri')
    s,b,_=a.post('/?page=ipsekat',{'action':'sec_ip_admin','ip_admin':'10.0.0.1','admin_password':kpw}); check('mesti mengandungi IP anda' in b,'senarai IP pentadbir sekolah tanpa IP sendiri ditolak')
    s,b,_=a.post('/?page=ipsekat',{'action':'sec_ip_admin','ip_admin':ME,'admin_password':kpw}); check('hanya boleh log masuk dari IP' in b,'senarai IP pentadbir sekolah disimpan')
    run("tenant_use(school_find("+json.dumps(K)+"),'path'); save_settings(['ip_admin'=>'10.0.0.1']);"); check(a.req('/?page=dashboard')[0]==403,'pentadbir sekolah di luar senarai IP → disekat setiap permintaan')
    run("tenant_use(school_find("+json.dumps(K)+"),'path'); save_settings(['ip_admin'=>'']);"); a=C(sbase(K)); slogin(a,K.lower()+'@sekolah.test',kpw)
    # ---- Cloudflare ----
    p.post('/platform/?p=ipsekat',{'action':'p_sec_cf_save','cf_aktif':'1','cf_zone':ZONE,'cf_token':CF_TOKEN,'cf_auto':''})
    check(CF_TOKEN not in one('SELECT value FROM platform_settings WHERE name="cfg_cf_token"')['value'],'token Cloudflare disimpan bersulit')
    s,b,_=p.post('/platform/?p=ipsekat',{'action':'p_sec_cf_test'}); check('Sambungan Cloudflare berjaya' in b,'uji sambungan Cloudflare')
    p.post('/platform/?p=ipsekat',{'action':'p_sec_ip_add','ip':'203.0.113.0/24','skop':'semua','tempoh':'60','kod':'','sebab':'QA CF'}); r=one('SELECT * FROM ip_blocks WHERE ip="203.0.113.0/24"')
    req=[x for x in SEEN if x[0]=='POST' and 'access_rules' in x[1]]
    check(r['cf_id'] and req and json.loads(req[-1][2])['configuration']=={'target':'ip_range','value':'203.0.113.0/24'} and req[-1][3]=='Bearer '+CF_TOKEN,'sekatan platform disegerak ke Cloudflare (ip_range, token Bearer)')
    p.post('/platform/?p=ipsekat',{'action':'p_sec_ip_add','ip':'203.0.112.0/20','skop':'semua','tempoh':'60','kod':'','sebab':'QA'}); check('tidak disokong' in one('SELECT cf_error FROM ip_blocks WHERE ip="203.0.112.0/20"')['cf_error'],'julat /20 tidak disokong Cloudflare → ditanda')
    p.post('/platform/?p=ipsekat',{'action':'p_sec_ip_add','ip':'198.18.0.9','skop':'semua','tempoh':'60','kod':K,'sebab':'QA'}); check(one('SELECT cf_id FROM ip_blocks WHERE ip="198.18.0.9"')['cf_id'] is None,'sekatan peringkat sekolah tidak dihantar ke Cloudflare')
    p.post('/platform/?p=ipsekat',{'action':'p_sec_ip_remove','id':r['id']}); check(any(x[0]=='DELETE' and r['cf_id'] in x[1] for x in SEEN),'buang sekatan → peraturan Cloudflare dipadam')
    sql('UPDATE ip_blocks SET expires_at=DATE_SUB(NOW(),INTERVAL 1 MINUTE) WHERE ip="198.18.0.9"'); out=json.loads(run('echo json_encode(security_daily());')); check(out['ip_tamat']>=1 and not one('SELECT id FROM ip_blocks WHERE ip="198.18.0.9"'),'kerja harian membuang sekatan tamat tempoh')
    run("pset_set(['cf_aktif'=>'0']);")
    # ---- CAPTCHA pihak ketiga ----
    p.post('/platform/?p=keselamatan',{'action':'p_sec_cap_save','cap_penyedia':'turnstile','cap_gagal':'tutup','ts_site':'0x4AAAAAAAqa'+secrets.token_hex(4),'ts_secret':TS_SECRET,'cap_luar':'1'})
    check(run("echo cap_external()?'ya':'tidak';")=='tidak' and run("echo pset('cap_luar');")=='0','penyedia luar tidak aktif sebelum lulus ujian (elak terkunci)')
    s,b,h=p.req('/platform/?p=keselamatan'); check('cf-turnstile' in b and 'challenges.cloudflare.com' in h.get('Content-Security-Policy',''),'widget ujian Turnstile dengan CSP penyedia')
    s,b,_=p.post('/platform/?p=keselamatan',{'action':'p_sec_cap_test','cf-turnstile-response':'palsu'}); check('Ujian CAPTCHA gagal' in b,'token ujian salah ditolak')
    p.post('/platform/?p=keselamatan',{'action':'p_sec_cap_test','cf-turnstile-response':'lulus-ok'}); p.post('/platform/?p=keselamatan',{'action':'p_sec_cap_save','cap_penyedia':'turnstile','cap_gagal':'tutup','ts_site':run("echo pset('ts_site');"),'ts_secret':'','cap_luar':'1'})
    check(run("echo cap_external()?'ya':'tidak';")=='ya','lulus ujian → Turnstile diaktifkan')
    r1=C(PBASE); s,b,h=r1.req('/lapor-keselamatan'); check('cf-turnstile' in b and 'cap_id' not in b and 'challenges.cloudflare.com' in h.get('Content-Security-Policy',''),'borang awam memaparkan Turnstile (bukan CAPTCHA sendiri)')
    f={'nama':'Penyelidik','emel':P+'@lapor.test','tajuk':'XSS QA','butiran':'Langkah menghasilkan semula isu keselamatan ujian.','website':''}
    def slow_post(c,path,data): t=c.csrf(path); time.sleep(2.1); return c.req(path,{'csrf':t,**data})
    s,b,_=slow_post(r1,'/lapor-keselamatan',{**f,'cf-turnstile-response':'palsu'}); check('Pengesahan CAPTCHA gagal' in b,'token Turnstile palsu ditolak')
    s,b,_=slow_post(r1,'/lapor-keselamatan',{**f,'cf-turnstile-response':'lulus-ok'}); check('Terima kasih' in b,'token Turnstile sah → laporan diterima')
    p.post('/platform/?p=keselamatan',{'action':'p_sec_cap_save','cap_penyedia':'turnstile','cap_gagal':'tutup','ts_site':run("echo pset('ts_site');"),'ts_secret':'kunci-baharu-'+secrets.token_hex(4),'cap_luar':'1'})
    check(run("echo cap_external()?'ya':'tidak';")=='tidak','kunci ditukar → perlu lulus ujian semula')
    subprocess.check_output(['php','bin/security.php','captcha-off'],cwd=ROOT); check(run("echo pset('cap_luar');")=='0','CLI captcha-off')
    # ---- Laporan keselamatan (CAPTCHA sendiri) ----
    r2=C(PBASE); s,b,_=r2.req('/lapor-keselamatan'); cap=r2.cap(b,'urustugas_platform'); time.sleep(2.1)
    n0=len(mails(P+'@notis.test')); s,b,_=r2.req('/lapor-keselamatan',{'csrf':re.search(r'name="csrf" value="([a-f0-9]+)"',b)[1],**cap,'nama':'','emel':P+'@lapor.test','tajuk':'CSRF QA','butiran':'Butiran laporan kedua yang cukup panjang.','website':''})
    rep=one('SELECT * FROM security_reports WHERE title="CSRF QA"'); check('Terima kasih' in b and rep and len(mails(P+'@notis.test'))==n0+1,'laporan dengan CAPTCHA sendiri diterima + e-mel notis')
    s,b,_=p.req('/platform/?p=laporan'); check('CSRF QA' in b and 'nav-badge' in b,'platform: senarai laporan dengan lencana baharu')
    p.post('/platform/?p=laporan&id='+str(rep['id']),{'action':'p_sec_report','id':rep['id'],'status':'selesai','catatan':'dibaiki'}); check(one('SELECT status FROM security_reports WHERE id=?',[rep['id']])['status']=='selesai','status laporan dikemas kini')
    s,b,h=C(PBASE).req('/.well-known/security.txt'); check(s==200 and 'Contact:' in b and '/lapor-keselamatan' in b and 'Expires:' in b and 'text/plain' in h.get('Content-Type',''),'security.txt (Contact, Expires, Policy)')
    # ---- Wajib 2FA ----
    check(run("tenant_use(school_find("+json.dumps(K)+"),'path'); echo twofa_required('teacher')?'ya':'tidak';")=='tidak','asal: 2FA guru ikut dasar sekolah')
    p.post('/platform/?p=keselamatan',{'action':'p_sec_2fa','wajib_2fa':'1'}); check(run("tenant_use(school_find("+json.dumps(K)+"),'path'); echo twofa_required('teacher'),twofa_required('pk')?'ya':'tidak';").endswith('ya'),'wajib 2FA semua: guru & PK semua sekolah')
    s,b,_=p.req('/platform/?p=keselamatan'); check('tanpa 2FA' in b,'platform: senarai pengguna tanpa 2FA')
    p.post('/platform/?p=keselamatan',{'action':'p_sec_2fa'})
    # ---- Mod kecemasan ----
    s,b,_=p.post('/platform/?p=keselamatan',{'action':'p_sec_emergency','kecemasan':'baca','kecemasan_mesej':'Siasatan QA','admin_password':'salah'}); check(run("echo pset('kecemasan');")=='','mod kecemasan memerlukan kata laluan')
    p.post('/platform/?p=keselamatan',{'action':'p_sec_emergency','kecemasan':'baca','kecemasan_mesej':'Siasatan QA','admin_password':password})
    s,b,_=a.req('/?page=dashboard'); check(s==200 and 'Mod baca sahaja' in b and 'Siasatan QA' in b,'mod baca sahaja: laman dibuka dengan sepanduk')
    s,b,_=a.post('/?page=sokongan',{'action':'sk_new','subject':'Ujian','category':'lain','body':'Ujian mod baca sahaja.'}); check(s==503 and not one('SELECT id FROM support_tickets WHERE school_id=?',[kid]),'mod baca sahaja: perubahan data ditolak (503)')
    nb=C(sbase(K)); slogin(nb,K.lower()+'@sekolah.test',kpw); check(nb.req('/?page=dashboard')[0]==200,'mod baca sahaja: log masuk masih dibenarkan')
    check(run("echo registration_open()?'ya':'tidak';")=='tidak','mod kecemasan menutup pendaftaran sekolah')
    p.post('/platform/?p=keselamatan',{'action':'p_sec_emergency','kecemasan':'tutup','kecemasan_mesej':'Ditutup QA','admin_password':password})
    s,b,_=a.req('/?page=dashboard'); check(s==503 and 'Ditutup QA' in b and p.req('/platform/')[0]==200,'mod tutup: laman sekolah 503, panel platform berfungsi')
    subprocess.check_output(['php','bin/security.php','emergency-off'],cwd=ROOT); check(a.req('/?page=dashboard')[0]==200,'CLI emergency-off memulihkan laman sekolah')
    check(verify('PLATFORM')['ok'] and verify(K)['ok'],'rantai audit kekal sah selepas semua ujian')
    print(f'All {passed} security checks passed.',flush=True)
finally:
    srv.shutdown(); unblock_me(); sql('DELETE FROM ip_blocks WHERE ip IN ("203.0.113.0/24","203.0.112.0/20","198.18.0.9","198.51.100.0/24")'); sql('DELETE FROM rate_limits')
    for t in sql('SELECT id FROM schools WHERE code LIKE "QSC%"',fetch=True): run("school_delete(school_by_id("+str(t['id'])+"));")
    sql('DELETE FROM security_reports WHERE email=?',[P+'@lapor.test'])
    sql('DELETE FROM platform_settings WHERE name LIKE "cfg\\_%"')
    for r in saved: sql('INSERT INTO platform_settings(name,value) VALUES (?,?)',[r['name'],r['value']])
    sql('DELETE FROM platform_log WHERE admin_id IN (SELECT id FROM platform_admins WHERE email IN (?,?))',[pemail,p2email]); sql('DELETE FROM platform_admins WHERE email IN (?,?)',[pemail,p2email])
    run('audit_rebuild(0);')
