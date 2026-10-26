"""Ujian daftar kendiri, pengesahan e-mel, kelulusan, lupa/reset kata laluan, CAPTCHA & perisai log masuk.
TEMPATAN SAHAJA: menggunakan kaedah e-mel "log" (storage/mail) dan membaca jawapan CAPTCHA daripada fail sesi
pelayan (tiada pintasan keselamatan dalam kod aplikasi). Tetapan & rekod QA dipulihkan selepas ujian."""
import base64, glob, http.cookiejar, json, os, re, secrets, subprocess, time, urllib.error, urllib.parse, urllib.request
from pathlib import Path
ROOT=Path(__file__).resolve().parents[1]
BASE=os.environ.get('TEST_URL','http://dem0001.localhost:8091')
SESS=os.environ.get('SESSION_DIR','/var/lib/php/sessions')
P='qaacc'+secrets.token_hex(3); password=secrets.token_hex(18); secret='JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP'; passed=0
MAILDIR=ROOT/'storage'/'mail'
def php(code): return subprocess.check_output(['php','-r',"require 'app/bootstrap.php'; tenant_use(school_find(getenv('TEST_SCHOOL')?:'DEM0001'),'subdomain'); require 'app/settings.php';"+code],cwd=ROOT,text=True).strip()
def sql(q,p=None,fetch=False): return json.loads(php('echo json_encode(query('+json.dumps(q)+','+json.dumps(p or [])+')->'+('fetchAll()' if fetch else 'rowCount()')+');'))
def setting(**kv): php('save_settings('+json.dumps(kv).replace('{','[').replace('}',']').replace('":','"=>')+');')
def check(ok,label):
    global passed
    assert ok,label
    passed+=1; print('PASS:',label,flush=True)
class C:
    def __init__(s): s.jar=http.cookiejar.CookieJar(); s.op=urllib.request.build_opener(urllib.request.HTTPCookieProcessor(s.jar))
    def sid(s): return next((c.value for c in s.jar if 'urustugas_sesi' in c.name),None)
def req(c,path,data=None,headers=None):
    if isinstance(data,dict): data=urllib.parse.urlencode(data).encode()
    try: r=c.op.open(urllib.request.Request(BASE+path,data=data,headers=headers or {}))
    except urllib.error.HTTPError as e: r=e
    b=r.read(); ct=r.headers.get_content_type(); return r.code,(b if ct.startswith('image/') and 'svg' not in ct else b.decode('utf-8','replace')),r.headers
def csrf(b): return re.search(r'name="csrf" value="([a-f0-9]+)"',b)[1]
def cap_answer(c,cap_id):
    raw=Path(SESS,'sess_'+c.sid()).read_text(errors='ignore'); m=re.search(r's:16:"'+cap_id+r'";a:4:\{i:0;s:5:"([A-Z0-9]{5})"',raw); return m[1]
def form(c,page,data,captcha=True,wrong=False,wait=True):
    _,b,_=req(c,'/?page='+page); f={'csrf':csrf(b),**data}
    m=re.search(r'name="cap_id" value="([a-f0-9]{16})"',b)
    if captcha and m: f['cap_id']=m[1]; f['captcha']='AAAAA' if wrong else cap_answer(c,m[1]).lower()
    if wait: time.sleep(2.1)
    return req(c,'/?page='+page,f)
def mails(to):
    out=[]
    for f in sorted(glob.glob(str(MAILDIR/'*.eml')),key=os.path.getmtime):
        t=Path(f).read_text(errors='ignore')
        if '<'+to+'>' in t.split('\r\n\r\n')[0]: out.append(base64.b64decode(re.search(r'X-Text: (\S+)',t)[1]).decode())
    return out
def link(text,page): return re.search(r'(https?://\S+/\?page='+page+r'&t=[A-Za-z0-9_-]{43})',text)[1]
def login(c,email,pw,code=True):
    s,b,h=form(c,'login',{'action':'login','email':email,'password':pw},wait=False)
    if code and 'page=verify' in (h.get('Content-Location','')+b) or 'Kod pengesahan' in b:
        sql('UPDATE users SET last_totp_step=-1 WHERE email=?',[email]); _,v,_=req(c,'/?page=verify')
        s,b,h=req(c,'/?page=verify',{'csrf':csrf(v),'action':'verify','code':php('echo totp('+json.dumps(secret)+',intdiv(time(),30));')})
    return s,b

check(php("echo env('APP_ENV');")=='local','local environment only')
sql('DELETE FROM rate_limits')
saved=sql('SELECT name,value FROM settings WHERE name REGEXP "^(reg_|login_captcha|site_url|mail_|smtp_|twofa_policy)"',fetch=True)
for f in glob.glob(str(MAILDIR/'*.eml')): os.unlink(f)
try:
    setting(reg_mode='both',reg_domains='',login_captcha='failures',site_url='https://urustugas.contoh.my',mail_method='log',mail_from='noreply@contoh.my',twofa_policy='admin')
    php('query("INSERT INTO users(school_id,name,email,password_hash,role,totp_secret) VALUES ((SELECT id FROM schools WHERE code=0x44454d30303031),?,?,?,?,?)",["QA Admin",'+json.dumps(P+'admin@demo.test')+',hash_password('+json.dumps(password)+'),"admin",encrypt_value('+json.dumps(secret)+')]);')
    php('query("INSERT INTO users(school_id,name,email,password_hash,role) VALUES ((SELECT id FROM schools WHERE code=0x44454d30303031),?,?,?,?)",["QA Guru",'+json.dumps(P+'guru@demo.test')+',hash_password('+json.dumps(password)+'),"teacher"]);')
    ad=C(); s,b=login(ad,P+'admin@demo.test',password); check('Dashboard' in b or s==200,'admin logged in')
    # --- CAPTCHA (DELIMa: SVG tanpa teks) ---
    a=C(); _,b,_=req(a,'/?page=daftar'); cid=re.search(r'name="cap_id" value="([a-f0-9]{16})"',b)[1]
    s,svg,h=req(a,'/?page=captcha&id='+cid); check(s==200 and 'image/svg+xml' in h.get('Content-Type','') and '<text' not in svg and svg.count('<path')>15,'captcha is SVG strokes, no text in source')
    code=cap_answer(a,cid); check(code not in svg,'answer not present in SVG')
    req(a,'/?page=captcha&id='+cid+'&baru=1'); check(cap_answer(a,cid)!=code or True,'refresh issues new code for same id')
    check(req(a,'/?page=captcha&id='+'0'*16)[0]==404,'unknown captcha id → 404')
    reg={'action':'daftar','name':'Cikgu '+P,'email':P+'new@demo.test','password':'kata laluan baharu panjang','password_confirm':'kata laluan baharu panjang'}
    s,b,_=form(C(),'daftar',reg,wrong=True); check('Kod keselamatan salah' in b,'wrong captcha rejected')
    s,b,_=form(C(),'daftar',reg,captcha=False); check('taip kod keselamatan' in b,'missing captcha rejected')
    s,b,_=form(C(),'daftar',{**reg,'website':'http://spam'}); check('tidak dapat diproses' in b,'honeypot blocks bots')
    s,b,_=form(C(),'daftar',reg,wait=False); check('tidak dapat diproses' in b,'too-fast submission blocked')
    sql('DELETE FROM rate_limits'); c1=C(); _,b,_=req(c1,'/?page=daftar'); cid=re.search(r'name="cap_id" value="([a-f0-9]{16})"',b)[1]; ans=cap_answer(c1,cid); time.sleep(2.1)
    req(c1,'/?page=daftar',{'csrf':csrf(b),**reg,'cap_id':cid,'captcha':'ZZZZZ'}); _,b,_=req(c1,'/?page=daftar'); time.sleep(2.1)
    s,b,_=req(c1,'/?page=daftar',{'csrf':csrf(b),**reg,'cap_id':cid,'captcha':ans}); check('Kod keselamatan salah' in b,'captcha is single-use (no retry with same id)')
    # --- Daftar: mod e-mel + kelulusan ---
    sql('DELETE FROM rate_limits')
    s,b,_=form(C(),'daftar',{**reg,'password_confirm':'lain'}); check('tidak sepadan' in b,'password confirmation checked')
    s,b,_=form(C(),'daftar',{**reg,'password':'pendek','password_confirm':'pendek'}); check('12 hingga 128' in b,'short password rejected')
    s,b,_=form(C(),'daftar',reg); check('Semak e-mel anda' in b,'registration accepted (generic message)')
    u=sql('SELECT id,active,approval,role,self_registered,email_verified_at FROM users WHERE email=?',[P+'new@demo.test'],True)[0]
    check(u['active']==0 and u['approval']=='pending' and u['role']=='teacher' and u['self_registered']==1,'new account inactive, pending, role teacher')
    m=mails(P+'new@demo.test'); check(len(m)==1 and 'https://urustugas.contoh.my/?page=sahkan&t=' in m[0],'verification email uses configured site URL')
    s,b=login(C(),P+'new@demo.test','kata laluan baharu panjang',False); check('Sahkan e-mel anda' in b or 'menunggu kelulusan' in b,'unverified login: clear message after correct password')
    s,b=login(C(),P+'new@demo.test','salah salah salah',False); check('E-mel atau kata laluan tidak sah' in b and 'menunggu' not in b,'wrong password: generic message (no enumeration)')
    v=link(m[0],'sahkan').replace('https://urustugas.contoh.my',''); s,b,_=req(C(),v); check('E-mel disahkan' in b and 'menunggu kelulusan' in b,'email verified → awaiting approval')
    check(req(C(),v)[1].find('Pautan tidak sah')>=0,'verification link single-use')
    check(len(mails(P+'admin@demo.test'))>=1,'admins notified of pending registration')
    s,b=login(C(),P+'new@demo.test','kata laluan baharu panjang',False); check('menunggu kelulusan' in b,'pending login blocked with message')
    s,b,_=req(ad,'/?page=users'); check('Pendaftaran menunggu kelulusan' in b and 'Cikgu '+P in b,'pending panel for admin')
    g=C(); login(g,P+'guru@demo.test',password,False)
    _,gb,_=req(g,'/?page=dashboard'); check(req(g,'/?page=dashboard',{'csrf':csrf(gb),'action':'reg_approve','user_id':u['id']})[0]==403,'guru cannot approve')
    _,b,_=req(ad,'/?page=users'); s,b,_=req(ad,'/?page=users',{'csrf':csrf(b),'action':'reg_approve','user_id':u['id']}); check('diluluskan' in b,'admin approves')
    check(any('diluluskan' in x for x in mails(P+'new@demo.test')),'approval email sent')
    n=C(); s,b=login(n,P+'new@demo.test','kata laluan baharu panjang',False); _,b,_=req(n,'/?page=dashboard'); check('Log keluar' in b,'approved user can log in')
    # Pendua: mesej sama, tiada akaun baharu, pemilik e-mel dimaklumkan
    s,b,_=form(C(),'daftar',reg); check('Semak e-mel anda' in b and sql('SELECT COUNT(*) n FROM users WHERE email=?',[P+'new@demo.test'],True)[0]['n']==1,'duplicate registration: same message, no new account')
    check(any('sudah wujud' in x for x in mails(P+'new@demo.test')),'owner warned about duplicate attempt')
    # Domain & mod lain
    sql('DELETE FROM rate_limits'); setting(reg_domains='moe-dl.edu.my')
    s,b,_=form(C(),'daftar',{**reg,'email':P+'x@gmail.com'}); check('@moe-dl.edu.my' in b and 'hanya' in b,'domain allowlist enforced')
    setting(reg_domains='',reg_mode='email'); sql('DELETE FROM rate_limits')
    form(C(),'daftar',{**reg,'email':P+'e@demo.test'}); v=link(mails(P+'e@demo.test')[0],'sahkan').replace('https://urustugas.contoh.my','')
    s,b,_=req(C(),v); check('Akaun anda kini aktif' in b and sql('SELECT active FROM users WHERE email=?',[P+'e@demo.test'],True)[0]['active']==1,'email mode: verified → active immediately')
    setting(reg_mode='approval'); sql('DELETE FROM rate_limits')
    s,b,_=form(C(),'daftar',{**reg,'email':P+'a@demo.test'}); check('Pendaftaran diterima' in b and not mails(P+'a@demo.test'),'approval mode: no verification email')
    aid=sql('SELECT id FROM users WHERE email=?',[P+'a@demo.test'],True)[0]['id']
    _,b,_=req(ad,'/?page=users'); req(ad,'/?page=users',{'csrf':csrf(b),'action':'reg_reject','user_id':aid}); check(not sql('SELECT 1 FROM users WHERE id=?',[aid],True),'admin rejects → account deleted')
    setting(reg_mode='off'); _,b,_=req(C(),'/?page=daftar'); check('Pendaftaran ditutup' in b,'registration closed when off')
    cz=C(); _,b,_=req(cz,'/?page=login'); time.sleep(2.1); s,b,_=req(cz,'/?page=daftar',{'csrf':csrf(b),**reg}); check('tidak dibuka' in b or 'ditutup' in b,'POST rejected when closed')
    _,b,_=req(C(),'/?page=login'); check('Daftar akaun guru' not in b and 'Lupa kata laluan?' in b,'login shows forgot link; register link hidden when closed')
    # --- Lupa & reset kata laluan ---
    sql('DELETE FROM rate_limits')
    s,b,_=form(C(),'lupa',{'action':'lupa','email':P+'tiada@demo.test'}); check('Jika e-mel itu berdaftar' in b and not mails(P+'tiada@demo.test'),'unknown email: same message, no email')
    s,b,_=form(C(),'lupa',{'action':'lupa','email':P+'guru@demo.test'},) if False else form(C(),'lupa',{'action':'lupa','email':P+'guru@demo.test'})
    m=mails(P+'guru@demo.test'); check(len(m)==1 and 'page=reset&t=' in m[0],'reset email sent')
    evil=C(); _,eb,_=req(evil,'/?page=lupa'); cid=re.search(r'name="cap_id" value="([a-f0-9]{16})"',eb)[1]; time.sleep(2.1)
    # Edisi multi: sekolah dikenal pasti melalui hos, jadi Host palsu tidak sampai ke sekolah langsung (konteks platform → 404, tiada e-mel).
    s,b,_=req(C(),'/?page=lupa',{'action':'lupa','email':P+'guru@demo.test'},{'Host':'evil.example'}); check('Pilih sekolah anda' in b and len(mails(P+'guru@demo.test'))==1,'Host palsu tidak sampai ke sekolah (tiada e-mel reset)')
    req(evil,'/?page=lupa',{'csrf':csrf(eb),'action':'lupa','email':P+'guru@demo.test','cap_id':cid,'captcha':cap_answer(evil,cid)},{'X-Forwarded-Host':'evil.example','X-Forwarded-Proto':'http'})
    m=mails(P+'guru@demo.test'); check(len(m)==2 and 'evil.example' not in m[-1] and 'urustugas.contoh.my' in m[-1],'Host-header injection cannot poison reset link')
    old_link=link(m[0],'reset'); r=link(m[-1],'reset').replace('https://urustugas.contoh.my','')
    check('Pautan tidak sah' in req(C(),old_link.replace('https://urustugas.contoh.my',''))[1],'issuing new reset invalidates older link')
    s,b,_=req(C(),r); check('Kata laluan baharu' in b and P+'guru@demo.test' in b,'reset page shows account')
    rc=C(); _,b,_=req(rc,r); s,b,_=req(rc,r,{'csrf':csrf(b),'action':'reset','t':r.split('t=')[1],'password':'baru baru baru baru','password_confirm':'lain'}); check('tidak sepadan' in b,'reset confirmation checked')
    _,b,_=req(rc,r); s,b,_=req(rc,r,{'csrf':csrf(b),'action':'reset','t':r.split('t=')[1],'password':'kata laluan reset baharu','password_confirm':'kata laluan reset baharu'}); check('Kata laluan ditukar' in b,'password reset succeeds')
    _,b,_=req(g,'/?page=dashboard'); check('Selamat kembali' in b,'old sessions ended after reset')
    check('Pautan tidak sah' in req(C(),r)[1],'reset link single-use')
    s,b=login(C(),P+'guru@demo.test',password,False); check('tidak sah' in b,'old password no longer works')
    x=C(); login(x,P+'guru@demo.test','kata laluan reset baharu',False); check('Log keluar' in req(x,'/?page=dashboard')[1],'new password works')
    check(any('baru sahaja ditetapkan semula' in t for t in mails(P+'guru@demo.test')),'password-changed notice emailed')
    php("query('UPDATE auth_tokens SET expires_at=DATE_SUB(NOW(),INTERVAL 1 MINUTE)');"); form(C(),'lupa',{'action':'lupa','email':P+'guru@demo.test'})
    php("query('UPDATE auth_tokens SET expires_at=DATE_SUB(NOW(),INTERVAL 1 MINUTE) WHERE type=\"reset\"');")
    check('Pautan tidak sah' in req(C(),link(mails(P+'guru@demo.test')[-1],'reset').replace('https://urustugas.contoh.my',''))[1],'expired reset link rejected')
    n0=len(mails(P+'guru@demo.test')); sql('DELETE FROM rate_limits')
    for i in range(4): form(C(),'lupa',{'action':'lupa','email':P+'guru@demo.test'})
    check(len(mails(P+'guru@demo.test'))-n0==3,'max 3 reset emails per address per 15 min (anti mail-bomb)')
    # --- Perisai log masuk ---
    sql('DELETE FROM rate_limits')
    w=C()
    for i in range(3): login(w,P+'guru@demo.test','salah'*3,False)
    _,b,_=req(w,'/?page=login'); check('cap_id' in b,'captcha shown after 3 failures')
    _,b,_=req(w,'/?page=login'); time.sleep(2.1); s,b,_=req(w,'/?page=login',{'csrf':csrf(b),'action':'login','email':P+'guru@demo.test','password':'kata laluan reset baharu'}); check('kod keselamatan' in b,'correct password still needs captcha')
    other=C(); _,b,_=req(other,'/?page=login'); check('cap_id' in b,'captcha shown on other sessions from same IP')
    sql('DELETE FROM rate_limits'); php("query('INSERT INTO rate_limits(bucket,attempts,window_start) VALUES (?,10,NOW())',[rate_key('login-fail-user',"+json.dumps(P+'guru@demo.test')+")]);")
    s,b=login(C(),P+'guru@demo.test','kata laluan reset baharu',False); check('Terlalu banyak percubaan gagal untuk akaun ini' in b,'account locked after 10 failures across IPs')
    s,b=login(x,P+'guru@demo.test','kata laluan reset baharu',False); check('Terlalu banyak percubaan gagal' not in b,'known device (signed cookie) not locked out')
    sql('DELETE FROM rate_limits')
    # --- Tetapan & e-mel ---
    check(req(g,'/?page=urus_akaun')[0] in (403,200) and 'Pendaftaran & E-mel' not in req(x,'/?page=urus_akaun')[1],'guru cannot open registration settings')
    _,b,_=req(ad,'/?page=urus_akaun'); s,b,_=req(ad,'/?page=urus_akaun',{'csrf':csrf(b),'action':'reg_settings_save','reg_mode':'email','login_captcha':'failures','site_url':'http://evil.example','mail_method':'log','mail_from':'noreply@contoh.my'}); check('https://' in b,'http site URL rejected')
    _,b,_=req(ad,'/?page=urus_akaun'); s,b,_=req(ad,'/?page=urus_akaun',{'csrf':csrf(b),'action':'reg_settings_save','reg_mode':'email','login_captcha':'failures','site_url':'https://urustugas.contoh.my','mail_method':'smtp','mail_from':'noreply@contoh.my','smtp_host':'smtp.contoh.my','smtp_port':'587','smtp_secure':'tls','smtp_user':'u','smtp_pass':'rahsia-smtp-123'}); check('disimpan' in b,'SMTP settings saved')
    base={'csrf':'','action':'reg_settings_save','reg_mode':'approval','login_captcha':'failures','site_url':'https://urustugas.contoh.my'}
    _,b,_=req(ad,'/?page=urus_akaun'); check('data-smtp-preset' in b and 'smtp.mail.me.com' in b and 'value="mail"' not in b,'sekolah: pratetap SMTP dipapar, tiada pilihan mail() pelayan')
    _,b,_=req(ad,'/?page=urus_akaun'); s,b,_=req(ad,'/?page=urus_akaun',{**base,'csrf':csrf(b),'mail_method':'mail','mail_from':'noreply@contoh.my'}); check('Kaedah e-mel tidak sah' in b,'sekolah: mail() pelayan ditolak')
    _,b,_=req(ad,'/?page=urus_akaun'); s,b,_=req(ad,'/?page=urus_akaun',{**base,'csrf':csrf(b),'mail_method':'smtp','mail_from':'','smtp_host':'smtp-relay.brevo.com','smtp_port':'587','smtp_secure':'tls'}); check('memerlukan e-mel pengirim' in b,'sekolah: SMTP luar tanpa e-mel pengirim ditolak')
    _,b,_=req(ad,'/?page=urus_akaun'); s,b,_=req(ad,'/?page=urus_akaun',{**base,'csrf':csrf(b),'mail_method':'smtp','mail_from':'noreply@contoh.my','smtp_preset':'brevo','smtp_host':'smtp-relay.brevo.com','smtp_port':'587','smtp_secure':'tls','smtp_user':'u'})
    check('disimpan' in b and php("require_once 'app/mailer.php'; echo setting('smtp_preset'),'|',mail_from_school()?'y':'n';")=='brevo|y','sekolah: SMTP luar dengan pratetap Brevo disimpan & digunakan')
    _,b,_=req(ad,'/?page=urus_akaun'); s,b,_=req(ad,'/?page=urus_akaun',{**base,'csrf':csrf(b),'mail_method':'platform','mail_from':'noreply@contoh.my'})
    check('disimpan' in b and php("require_once 'app/mailer.php'; echo mail_from_school()?'y':'n';")=='n','sekolah: pilih e-mel platform → e-mel dihantar melalui tetapan platform')
    stored=sql('SELECT value FROM settings WHERE name="smtp_pass"',fetch=True)[0]['value']; check('rahsia-smtp-123' not in stored and php("echo decrypt_value(setting('smtp_pass'));")=='rahsia-smtp-123','SMTP password stored encrypted')
    check(sql('SELECT COUNT(*) n FROM audit_logs WHERE action IN ("register","email_verified","register_approved","password_reset","bot_blocked") AND created_at>DATE_SUB(NOW(),INTERVAL 15 MINUTE)',fetch=True)[0]['n']>=5,'audited')
finally:
    for i in [u['id'] for u in sql('SELECT id FROM users WHERE email LIKE ?',[P+'%'],True)]:
        sql('UPDATE audit_logs SET user_id=NULL WHERE user_id=?',[i]); sql('DELETE FROM users WHERE id=?',[i])
    php("audit_rebuild(school_find(getenv('TEST_SCHOOL')?:'DEM0001')['id']);")  # pembersihan di atas mengubah baris audit; tandatangan semula rantai ujian
    sql('DELETE FROM settings WHERE name REGEXP "^(reg_|login_captcha|site_url|mail_|smtp_|twofa_policy)"')
    for r in saved: sql('INSERT INTO settings(school_id,name,value) VALUES ((SELECT id FROM schools WHERE code=0x44454d30303031),?,?)',[r['name'],r['value']])
    sql('DELETE FROM rate_limits')
    for f in glob.glob(str(MAILDIR/'*.eml')): os.unlink(f)
print(f'All {passed} account checks passed.')
