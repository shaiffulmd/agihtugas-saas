"""Ujian panel platform UrusTugas (rangka koku-multi Fasa A: urus penyewa). Tempatan sahaja: APP_ENV=local, DEMO_MODE=0.
   TEST_URL=http://dem0001.localhost:8091 (domain induk diambil daripadanya). Rekod & tetapan QA dipulihkan."""
import http.cookiejar, json, os, re, secrets, subprocess, urllib.error, urllib.parse, urllib.request, glob, base64
from pathlib import Path
ROOT=Path(__file__).resolve().parents[1]
BASE=os.environ.get('TEST_URL','http://dem0001.localhost:8091')
PBASE=os.environ.get('PLATFORM_URL',re.sub(r'//[a-z]{3}[0-9]{4}\.','//',BASE))
P='qapl'+secrets.token_hex(3); password=secrets.token_hex(18); secret='JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP'; passed=0
MAILDIR=ROOT/'storage'/'mail'
def run(code): return subprocess.check_output(['php','-r',"require 'app/bootstrap.php'; require_once 'app/settings.php'; require_once 'app/install.php'; require_once 'app/platform.php';"+code],cwd=ROOT,text=True).strip()
def sql(q,p=None,fetch=False): return json.loads(run('echo json_encode(query('+json.dumps(q)+','+json.dumps(p or [])+')->'+('fetchAll()' if fetch else 'rowCount()')+');'))
def check(ok,label):
    global passed
    assert ok,label
    passed+=1; print('PASS:',label,flush=True)
class C:
    def __init__(s,base): s.base=base; s.op=urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
    def req(s,path,data=None):
        if isinstance(data,dict): data=urllib.parse.urlencode(data,doseq=True).encode()
        try: r=s.op.open(s.base+path,data)
        except urllib.error.HTTPError as e: r=e
        return r.code,r.read().decode('utf-8','replace'),r.headers
    def post(s,path,data):
        _,b,_=s.req(path); m=re.search(r'name="csrf" value="([a-f0-9]+)"',b); return s.req(path,{'csrf':m[1] if m else '',**data})
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
    """Sekolah ujian + Pentadbir Utama (2FA disediakan, tidak perlu tukar kata laluan)."""
    run("[$s]=school_create("+json.dumps(code)+",'SK Ujian Langganan','SK','Admin Ujian',"+json.dumps(code.lower()+'@sekolah.test')+","+json.dumps(pw)+"); tenant_use($s,'path'); query('UPDATE users SET must_change=0,totp_secret=? WHERE school_id=?',[encrypt_value("+json.dumps(secret)+"),sid()]);")
    return int(sql('SELECT id FROM schools WHERE code=?',[code],True)[0]['id'])
def sbase(sc): return re.sub(r'//',r'//'+sc.lower()+'.',PBASE,1)   # pemasangan ujian: TENANT_PRIMARY=subdomain
def slogin(c,sc,pw):
    email=sc.lower()+'@sekolah.test'; c.post('/?page=login',{'action':'login','email':email,'password':pw,'website':''})
    sql('UPDATE users SET last_totp_step=-1 WHERE email=?',[email]); return c.post('/?page=verify',{'action':'verify','code':code()})
def set_exp(sid,days): sql('UPDATE schools SET expires_on=DATE_ADD(CURDATE(),INTERVAL '+str(int(days))+' DAY),notices=NULL WHERE id=?',[sid])
SESS=os.environ.get('SESSION_DIR','/var/lib/php/sessions')
def cap_answer(c,cap_id):
    sid=None
    for h in c.op.handlers:
        if hasattr(h,'cookiejar'): sid=next((x.value for x in h.cookiejar if x.name.endswith('urustugas_platform')),None)
    raw=Path(SESS,'sess_'+sid).read_text(errors='ignore'); return re.search(r's:16:"'+cap_id+r'";a:4:\{i:0;s:5:"([A-Z0-9]{5})"',raw)[1]
def daftar(c,**kw):
    _,b,_=c.req('/daftar'); cid=re.search(r'name="cap_id" value="([a-f0-9]{16})"',b)
    import time; time.sleep(2.1)
    f={'act':'daftar','kod':'','nama':'SK Daftar Ujian','jenis':'SK','negeri':'Pahang','pic_nama':'Cikgu Daftar','pic_emel':'','pic_telefon':'0123456789','password':'Daftar-Ujian-2026!','password_confirm':'Daftar-Ujian-2026!','setuju':'1','website':''}
    f.update(kw)
    if cid: f['cap_id']=cid[1]; f['captcha']=cap_answer(c,cid[1]).lower()
    m=re.search(r'name="csrf" value="([a-f0-9]+)"',b); f['csrf']=m[1] if m else ''
    return c.req('/daftar',f)
def settings_form(b):
    f=b[b.find('id="platform-settings"'):]; f=f[:f.find('</form>')]; out={}
    for tag in re.findall(r'<input\b[^>]*>',f):
        n=re.search(r'name="([^"]+)"',tag); v=re.search(r'value="([^"]*)"',tag); t=(re.search(r'type="([^"]+)"',tag) or [None,'text'])[1]
        if not n or t in ('submit','button') or (t in ('radio','checkbox') and ' checked' not in tag): continue
        out[n[1]]=v[1] if v else ''
    for n,body in re.findall(r'<select\b[^>]*name="([^"]+)"[^>]*>(.*?)</select>',f,re.S):
        m=re.search(r'<option[^>]*value="([^"]*)"[^>]*selected',body) or re.search(r'<option[^>]*value="([^"]*)"',body); out[n]=m[1] if m else ''
    return out

check(run("echo env('APP_ENV');")=='local' and run("echo env('DEMO_MODE');")!='1','local environment, bukan demo')
pemail=P+'@platform.test'; saved=sql('SELECT name,value FROM platform_settings WHERE name LIKE "cfg\\_%"',fetch=True); sql('DELETE FROM rate_limits')
run('query("INSERT INTO platform_admins(name,email,password_hash,totp_secret) VALUES (?,?,?,?)",['+json.dumps('QA Platform')+','+json.dumps(pemail)+',hash_password('+json.dumps(password)+'),encrypt_value('+json.dumps(secret)+')]);')
try:
    p=C(PBASE); plogin(p,pemail); check(p.req('/platform/')[0]==200 and 'Tetapan Platform' in p.req('/platform/')[1],'log masuk platform (2FA) & menu Tetapan Platform')
    q=C(PBASE); q.req('/platform/?p=tetapan'); sql('UPDATE platform_admins SET last_totp_step=-1 WHERE email=?',[pemail]); q.post('/platform/',{'action':'p_login','email':pemail,'password':password}); s_,b_,_=q.post('/platform/?p=verify',{'action':'p_verify','code':code()})
    check('Tetapan Platform' in b_ and 'id="platform-settings"' in b_,'selepas log masuk (2FA), kembali ke halaman platform yang diminta')
    # ---- A1: Tetapan Platform ----
    s,b,_=p.req('/platform/?p=tetapan'); f=settings_form(b)
    check(s==200 and f.get('harga_pro')=='120.00' and f.get('percubaan_hari')=='30' and f.get('free_aktif')=='1','tetapan lalai dipaparkan (RM120, 30 hari, Free hidup)')
    s,b,_=p.post('/platform/?p=tetapan',{**f,'action':'p_settings_save','harga_pro':'0.50'}); check('minimum RM1.00' in b,'harga di bawah RM1 ditolak')
    s,b,_=p.post('/platform/?p=tetapan',{**f,'action':'p_settings_save','daftar_domain':'bukan domain'}); check('Domain e-mel tidak sah' in b,'domain pendaftaran tidak sah ditolak')
    s,b,_=p.post('/platform/?p=tetapan',{**f,'action':'p_settings_save','nama_platform':'Nama Ditaip QA','harga_pro':'abc'})
    check('Tidak disimpan' in b and 'value="Nama Ditaip QA"' in b and run("echo pset('nama_platform');")!='Nama Ditaip QA','simpan gagal: ralat di sebelah butang & nilai yang ditaip dikekalkan')
    p.post('/platform/?p=tetapan',{**f,'action':'p_settings_save','mail_method':'mail','smtp_host':'smtp-relay.brevo.com','smtp_port':'587','smtp_secure':'tls','smtp_user':'qa@contoh.my'})
    check(run("echo pset('mail_method'),'|',pset('smtp_host'),'|',pset('smtp_user');")=='mail|smtp-relay.brevo.com|qa@contoh.my','medan SMTP disimpan walaupun kaedah mail() (tidak hilang)')
    f2={**f,'action':'p_settings_save','nama_platform':'UrusTugas QA','harga_pro':'150','percubaan_hari':'14','free_guru':'50','daftar_buka':'1','daftar_kelulusan':'auto','daftar_domain':'@MOE-DL.edu.my, contoh.my','mail_method':'log','mail_from':'noreply@'+P+'.test','mail_from_name':'UrusTugas QA'}
    s,b,_=p.post('/platform/?p=tetapan',f2)
    check('Tetapan platform disimpan' in b and run("echo pset('harga_pro_sen'),'|',pset('percubaan_hari'),'|',pset('daftar_domain'),'|',platform_name();")=='15000|14|moe-dl.edu.my, contoh.my|UrusTugas QA','tetapan disimpan & dinormalkan (harga sen, domain huruf kecil tanpa @)')
    f3={**settings_form(p.req('/platform/?p=tetapan')[1]),'action':'p_settings_save','mail_method':'smtp','smtp_host':'smtp.contoh.test','smtp_port':'587','smtp_secure':'tls','smtp_user':'u','smtp_pass':'rahsia-smtp-'+P,'smtp_preset':'icloud'}
    p.post('/platform/?p=tetapan',f3); raw=run("echo pset('smtp_pass');")
    b3=p.req('/platform/?p=tetapan')[1]; check(run("echo pset('smtp_preset');")=='icloud' and 'smtp.mail.me.com' in b3 and 'smtp-relay.brevo.com' in b3 and 'value="mail"' in b3,'platform: mail() & SMTP dengan pratetap pembekal (iCloud, Brevo …)')
    check('rahsia-smtp' not in raw and run("echo pdecrypt(pset('smtp_pass'));")=='rahsia-smtp-'+P,'kata laluan SMTP platform disulit (kunci platform)')
    f4={**settings_form(p.req('/platform/?p=tetapan')[1]),'action':'p_settings_save','smtp_pass':''}; p.post('/platform/?p=tetapan',f4)
    check(run("echo pdecrypt(pset('smtp_pass'));")=='rahsia-smtp-'+P,'kata laluan SMTP kekal apabila medan dikosongkan')
    # ---- A2: E-mel platform ----
    p.post('/platform/?p=tetapan',{**settings_form(p.req('/platform/?p=tetapan')[1]),'action':'p_settings_save','mail_method':'log'})
    s,b,_=p.post('/platform/?p=tetapan',{'action':'p_mail_test'}); m=mails(pemail)
    check('E-mel ujian dihantar' in b and m and 'UrusTugas QA' in m[-1],'e-mel ujian platform dihantar (kaedah log)')
    sch=run("$s=school_find('DEM0001'); tenant_use($s,'subdomain'); echo setting('mail_from')===''?'kosong':'ada';")
    if sch=='kosong':
        out=run("$s=school_find('DEM0001'); tenant_use($s,'subdomain'); require_once 'app/mailer.php'; $e=null; echo send_mail("+json.dumps(P+'guru@sekolah.test')+",'Guru','Ujian fallback','Teks ujian',$e)?'ok':'gagal:'.$e;")
        hdr=open(sorted(glob.glob(str(MAILDIR/'*.eml')),key=os.path.getmtime)[-1],errors='ignore').read().split('\r\n\r\n')[0]
        check(out=='ok' and 'noreply@'+P+'.test' in hdr,'sekolah tanpa tetapan e-mel menggunakan e-mel platform')
    # ---- A3: Pentadbir platform, lupa & reset kecemasan ----
    s,b,_=p.req('/platform/?p=pentadbir'); check(s==200 and pemail in b and 'Anda' in b,'senarai pentadbir platform')
    e2=P+'b@platform.test'
    s,b,_=p.post('/platform/?p=pentadbir',{'action':'pa_create','name':'QA Dua','email':e2,'password':'','admin_password':'salah-salah'}); check('tidak sah' in b and not sql('SELECT id FROM platform_admins WHERE email=?',[e2],True),'tambah pentadbir memerlukan kata laluan sendiri')
    s,b,_=p.post('/platform/?p=pentadbir',{'action':'pa_create','name':'QA Dua','email':e2,'password':'','admin_password':password}); m=re.search(r'Kata laluan sementara untuk <code>[^<]+</code>: <code class="secret">([^<]+)',b)
    check(m is not None,'pentadbir baharu dengan kata laluan sementara (dipaparkan sekali)')
    pw2=m[1]; id2=sql('SELECT id FROM platform_admins WHERE email=?',[e2],True)[0]['id']
    q=C(PBASE); q.post('/platform/',{'action':'p_login','email':e2,'password':pw2}); check('Profil' in q.req('/platform/')[1],'pentadbir baharu boleh log masuk (dipaksa daftar 2FA)')
    p.post('/platform/?p=pentadbir',{'action':'pa_toggle','admin_id':id2,'admin_password':password})
    check(sql('SELECT active FROM platform_admins WHERE id=?',[id2],True)[0]['active']==0 and 'Log masuk platform' in q.req('/platform/')[1],'gantung pentadbir: sesi serta-merta tamat')
    s,b,_=p.post('/platform/?p=pentadbir',{'action':'pa_toggle','admin_id':sql('SELECT id FROM platform_admins WHERE email=?',[pemail],True)[0]['id'],'admin_password':password}); check('Urus akaun anda sendiri' in b,'tidak boleh mengurus akaun sendiri di sini')
    p.post('/platform/?p=pentadbir',{'action':'pa_delete','admin_id':id2,'admin_password':password}); check(not sql('SELECT id FROM platform_admins WHERE email=?',[e2],True),'padam pentadbir')
    p.post('/platform/?p=tetapan',{**settings_form(p.req('/platform/?p=tetapan')[1]),'action':'p_settings_save','mail_method':'log'})
    n0=len(mails(pemail)); a=C(PBASE); s,b,_=a.post('/platform/?p=lupa',{'email':P+'tiada@platform.test'}); check('Jika e-mel itu milik' in b and len(mails(pemail))==n0,'lupa: e-mel tidak wujud → mesej sama, tiada e-mel')
    s,b,_=a.post('/platform/?p=lupa',{'email':pemail}); m=mails(pemail); link=re.search(r'(/platform/\?p=reset&t=[a-f0-9]{48})',m[-1]) if len(m)>n0 else None
    check('Jika e-mel itu milik' in b and link,'lupa: pautan reset dihantar ke e-mel akaun')
    newpw=secrets.token_hex(16); s,b,_=a.post(link[1],{'password':newpw,'password_confirm':newpw}); check('Kata laluan dikemas kini' in b,'reset kata laluan melalui pautan')
    check('Log masuk platform' in p.req('/platform/')[1],'reset menamatkan sesi lama')
    check('Pautan tidak sah' in a.req(link[1])[1],'pautan reset sekali guna')
    password=newpw; plogin(p,pemail); check(p.req('/platform/')[0]==200 and 'Sekolah' in p.req('/platform/')[1],'log masuk dengan kata laluan baharu')
    kf=ROOT/'storage'/'reset-platform.txt'; kod='KOD-'+secrets.token_hex(12); np2=secrets.token_hex(16); k=C(PBASE)
    check(k.req('/platform/?p=kecemasan')[0]==404,'kecemasan: GET sentiasa 404 (tiada oracle)')
    s,b,_=k.post('/platform/?p=kecemasan',{'email':pemail,'kod':kod,'password':np2,'password_confirm':np2}); check(s==404 and 'tidak dapat dilakukan' in b,'kecemasan tanpa fail ditolak (respons seragam)')
    kf.write_text(kod); os.utime(kf,(0,os.path.getmtime(kf)-31*60)); s,b,_=k.post('/platform/?p=kecemasan',{'email':pemail,'kod':kod,'password':np2,'password_confirm':np2}); check(s==404 and kf.exists(),'kecemasan: fail lebih 30 minit ditolak')
    kf.write_text(kod); s,b,_=k.post('/platform/?p=kecemasan',{'email':pemail,'kod':kod+'x','password':np2,'password_confirm':np2}); check(s==404 and kf.exists(),'kecemasan: kod salah ditolak')
    s,b,_=k.post('/platform/?p=kecemasan',{'email':pemail,'kod':kod,'password':np2,'password_confirm':np2})
    check(s==200 and 'berjaya' in b and not kf.exists() and sql('SELECT totp_secret IS NULL n FROM platform_admins WHERE email=?',[pemail],True)[0]['n']==1,'kecemasan berjaya: fail dipadam, 2FA dikosongkan')
    # ---- A4: Langganan Free / Pro ----
    password=np2; run('query("UPDATE platform_admins SET totp_secret=? WHERE email=?",[encrypt_value('+json.dumps(secret)+'),'+json.dumps(pemail)+']);'); plogin(p,pemail); sql('DELETE FROM rate_limits')
    T='TSA'+str(secrets.randbelow(9000)+1000); spw=secrets.token_hex(12); tid=school_new(T,spw)
    check(run("echo school_plan(school_by_id("+str(tid)+")),'|',school_days_left(school_by_id("+str(tid)+"));")=='pro|'+run("echo pset('percubaan_hari');"),'sekolah baharu: Pro percubaan ikut Tetapan Platform')
    s=C(sbase(T)); slogin(s,T,spw)
    set_exp(tid,10); b=s.req('/?page=dashboard')[1]; check('Langganan Pro tamat dalam <strong>10 hari</strong>' in b,'jalur amaran Pro hampir tamat (pentadbir)')
    b=s.req('/?page=langganan')[1]; check('Pro sah hingga' in b and 'RM' in b,'halaman Langganan sekolah')
    set_exp(tid,-1); run("pset_set(['free_aktif'=>'1','free_guru'=>'2','free_pengguna'=>'1']);")
    check(run("echo school_plan(school_by_id("+str(tid)+"));")=='free','Pro tamat + Free dihidupkan → pakej Free')
    b=s.req('/?page=dashboard')[1]; check('Pakej Free: 0/2 guru' in b,'jalur pakej Free dengan penggunaan')
    def add_student(no): return s.post('/?page=guru',{'action':'guru_save','id':'0','name':'Guru '+no,'email':'','specialty':'','capacity':'20'})
    add_student('F1'); add_student('F2'); st,b,_=add_student('F3')
    check('Had pakej Free' in b and run("echo free_count('guru',"+str(tid)+");")=='2','Free: guru melebihi had ditolak')
    st,b,_=s.post('/?page=users',{'action':'user_create','name':'Guru F','email':T.lower()+'g@sekolah.test','password':secrets.token_hex(8),'role':'pk'})
    check('Had pakej Free' in b,'Free: pengguna melebihi had ditolak')
    run("pset_set(['free_aktif'=>'0']);"); check(run("echo school_plan(school_by_id("+str(tid)+"));")=='tamat','Pro tamat + Free dimatikan → tamat')
    st,b,_=s.req('/?page=guru'); check('Pro tamat pada' in b,'tamat: pentadbir dilencongkan ke Langganan')
    st,b,_=s.post('/?page=guru',{'action':'guru_save','id':'0','name':'Guru F9','email':'','specialty':'','capacity':'20'})
    check(not sql('SELECT id FROM teachers WHERE school_id=? AND name="Guru F9"',[tid],True),'tamat: tindakan POST disekat')
    check(C(sbase(T)).req('/')[0]==503,'tamat: laman awam sekolah ditutup (503)')
    st,b,_=p.req('/platform/?tapis=tamat'); check(T in b,'platform: penapis Tamat')
    st,b,_=p.post('/platform/?p=sekolah&id='+str(tid),{'action':'p_expiry','school_id':tid,'mode':'tarikh','expires_on':'2026-02-30'}); check('tidak sah' in b,'tarikh tamat tidak sah ditolak')
    p.post('/platform/?p=sekolah&id='+str(tid),{'action':'p_expiry','school_id':tid,'mode':'tahun'})
    check(run("echo school_plan(school_by_id("+str(tid)+")),'|',school_days_left(school_by_id("+str(tid)+"))>=364?'ok':'x';")=='pro|ok','platform +1 tahun → Pro semula')
    st,b,_=p.post('/platform/?p=sekolah&id='+str(tid),{'action':'p_trial_letter','school_id':tid,'mode':'sekolah','pdf':'1','tanda':'1','had':'2'})
    v=sql('SELECT value FROM settings WHERE school_id=? AND name="sys_percubaan_surat"',[tid],True)
    check('Tetapan surat percubaan khusus disimpan' in b and 'id="surat-percubaan"' in b and v and '"cetak":false' in v[0]['value'] and '"had":2' in v[0]['value'] and '"tanda":true' in v[0]['value'],'platform: surat percubaan khusus sekolah (cetak dimatikan, had PDF 2, tanda air)')
    st,b,_=p.post('/platform/?p=sekolah&id='+str(tid),{'action':'p_trial_letter','school_id':tid,'mode':'sekolah','pdf':'1','had':'abc'}); check('mesti nombor' in b,'platform: had PDF bukan nombor ditolak')
    p.post('/platform/?p=sekolah&id='+str(tid),{'action':'p_trial_letter','school_id':tid,'mode':'global','had':'0'})
    check(sql('SELECT value FROM settings WHERE school_id=? AND name="sys_percubaan_surat"',[tid],True)[0]['value']=='','platform: kembali ikut tetapan platform')
    check(s.req('/?page=guru')[0]==200,'Pro semula: akses penuh dipulihkan')
    # Kerja harian: amaran e-mel & arkib
    run("pset_set(['mail_method'=>'log','mail_from'=>'noreply@"+P+".test','padam_hari'=>'30']);")
    set_exp(tid,5); n0=len(mails(T.lower()+'@sekolah.test')); run("subscription_daily(true);"); run("subscription_daily(true);")
    m=mails(T.lower()+'@sekolah.test'); check(len(m)==n0+1 and 'akan tamat dalam 5 hari' in m[-1],'cron: satu e-mel amaran 7 hari (tiada pendua)')
    set_exp(tid,-25); run("subscription_daily(true);"); m=mails(T.lower()+'@sekolah.test')
    check(any('akan dipadam' in x for x in m[-2:]),'cron: amaran pemadaman data (Free dimatikan, padam_hari=30)')
    set_exp(tid,-31); run("subscription_daily(true);")
    check(sql('SELECT status FROM schools WHERE id=?',[tid],True)[0]['status']=='diarkib' and not sql('SELECT id FROM teachers WHERE school_id=?',[tid],True) and C(sbase(T)).req('/')[0]==404,'cron: tempoh tamat → data dipadam, sekolah diarkib (404)')
    sql('UPDATE schools SET status="menunggu" WHERE id=?',[tid]); check(C(sbase(T)).req('/')[0]==404,'status menunggu → 404')
    # ---- A5: Pendaftaran sekolah awam ----
    run("pset_set(['daftar_buka'=>'0']);"); check(C(PBASE).req('/daftar')[0]==404,'pendaftaran ditutup → /daftar 404')
    run("pset_set(['daftar_buka'=>'1','daftar_kelulusan'=>'manual','daftar_domain'=>'sekolah.test','daftar_had_harian'=>'1000','mail_method'=>'log','mail_from'=>'noreply@"+P+".test','percubaan_hari'=>'30']);")
    check('Daftar sekolah baharu' in C(PBASE).req('/')[1],'direktori memaparkan pautan pendaftaran')
    nd=urllib.request.build_opener(type('NR',(urllib.request.HTTPRedirectHandler,),{'redirect_request':lambda *a,**k:None})())
    try: r=nd.open(PBASE+'/direktori?q=dem0001'); code_,loc_=r.status,''
    except urllib.error.HTTPError as e: code_,loc_=e.code,e.headers.get('Location','')
    check(code_==302 and 'dem0001.' in loc_.lower(),'direktori: kod sekolah tepat terus ke portal sekolah')
    check('>DEM0001<' in C(PBASE).req('/direktori?q=DEM')[1],'direktori: carian separa memaparkan senarai')
    R='TSA'+str(secrets.randbelow(9000)+1000); pe=R.lower()+'.pic@sekolah.test'; d=C(PBASE)
    s,b,_=daftar(d,kod=R,pic_emel=R.lower()+'@lain.test'); check('berdomain' in b,'domain e-mel tidak dibenarkan ditolak')
    s,b,_=daftar(d,kod=R,pic_emel=pe,setuju=''); check('perlu bersetuju' in b,'persetujuan terma wajib')
    s,b,_=daftar(d,kod=R,pic_emel=pe); sch=sql('SELECT * FROM schools WHERE code=?',[R],True)
    check('Pendaftaran diterima' in b and sch and sch[0]['status']=='emel' and sch[0]['state']=='Pahang','pendaftaran diterima → status e-mel belum disahkan')
    check(C(sbase(R)).req('/')[0]==404,'sekolah belum disahkan → 404')
    s,b,_=daftar(C(PBASE),kod=R,pic_emel=pe); check('menunggu pengesahan e-mel' in b,'kod sama semasa menunggu pengesahan ditolak')
    m=mails(pe); link=re.search(r'(/daftar/sah\?t=[a-f0-9]{48})',m[-1]) if m else None; check(link is not None,'e-mel pautan pengesahan dihantar')
    v=C(PBASE); s,b,_=v.req(link[1]); check('Sahkan e-mel saya' in b and sql('SELECT status FROM schools WHERE code=?',[R],True)[0]['status']=='emel','pautan memerlukan tekan butang (pengimbas e-mel tidak mengesahkan)')
    s,b,_=v.post(link[1],{}); check('menunggu kelulusan' in b and sql('SELECT status FROM schools WHERE code=?',[R],True)[0]['status']=='menunggu','e-mel disahkan → menunggu kelulusan')
    check('Pautan tidak sah' in C(PBASE).req(link[1])[1],'pautan pengesahan sekali guna')
    rid=sql('SELECT id FROM schools WHERE code=?',[R],True)[0]['id']; b=p.req('/platform/?tapis=menunggu')[1]; check(R in b,'platform: penapis Menunggu kelulusan')
    p.post('/platform/?p=sekolah&id='+str(rid),{'action':'p_approve','school_id':rid})
    check(run("echo school_plan(school_by_id("+str(rid)+")),'|',school_days_left(school_by_id("+str(rid)+"));")=='pro|30' and any('diluluskan' in x for x in mails(pe)),'platform luluskan → Pro percubaan 30 hari + e-mel')
    t=C(sbase(R)); _,lb,_=t.post('/?page=login',{'action':'login','email':pe,'password':'Daftar-Ujian-2026!','website':''}); check('Aktifkan keselamatan 2FA' in lb,'Pentadbir Utama log masuk dengan kata laluan pendaftaran (daftar 2FA)')
    R2='TSA'+str(secrets.randbelow(9000)+1000); pe2=R2.lower()+'.pic@sekolah.test'; run("pset_set(['daftar_kelulusan'=>'auto']);")
    daftar(C(PBASE),kod=R2,pic_emel=pe2); l2=re.search(r'(/daftar/sah\?t=[a-f0-9]{48})',mails(pe2)[-1])[1]; v2=C(PBASE); v2.req(l2); s,b,_=v2.post(l2,{})
    check('telah diaktifkan' in b and run("echo school_plan(school_find("+json.dumps(R2)+"));")=='pro','kelulusan automatik selepas e-mel disahkan')
    R3='TSA'+str(secrets.randbelow(9000)+1000); s,b,_=daftar(C(PBASE),kod=R3,pic_emel=R3.lower()+'@sekolah.test'); check('Terlalu banyak pendaftaran' in b,'had 5 pendaftaran sejam setiap IP')
    sql('DELETE FROM rate_limits'); daftar(C(PBASE),kod=R3,pic_emel=R3.lower()+'@sekolah.test'); r3=sql('SELECT id FROM schools WHERE code=?',[R3],True)[0]['id']
    sql('UPDATE schools SET created_at=DATE_SUB(NOW(),INTERVAL 49 HOUR) WHERE id=?',[r3]); run("registration_cleanup();")
    check(not sql('SELECT id FROM schools WHERE id=?',[r3],True),'pendaftaran tidak disahkan > 48 jam dibuang (kod dibebaskan)')
    R4='TSA'+str(secrets.randbelow(9000)+1000); daftar(C(PBASE),kod=R4,pic_emel=R4.lower()+'@sekolah.test'); r4=sql('SELECT id FROM schools WHERE code=?',[R4],True)[0]['id']
    sql('UPDATE schools SET status="menunggu" WHERE id=?',[r4]); s,b,_=p.post('/platform/?p=sekolah&id='+str(r4),{'action':'p_reject','school_id':r4,'reason':'Kod salah'})
    check(not sql('SELECT id FROM schools WHERE id=?',[r4],True) and any('tidak diluluskan' in x for x in mails(R4.lower()+'@sekolah.test')),'platform tolak → dipadam + e-mel sebab')
    # ---- A6: Terma, polisi & persetujuan ----
    s,b,_=C(PBASE).req('/terma'); check(s==200 and 'Pro percuma selama 30 hari' in b,'Terma lalai dijana daripada tetapan semasa')
    check(C(PBASE).req('/polisi')[0]==200,'Polisi Privasi dipaparkan')
    v1=run("echo legal_version();"); f=settings_form(p.req('/platform/?p=tetapan')[1]); f.update({'action':'p_settings_save','terma_teks':'## Terma QA\n- Butiran <b>satu</b>\nPerenggan.'}); p.post('/platform/?p=tetapan',f)
    s,b,_=C(PBASE).req('/terma'); check('<h3>Terma QA</h3>' in b and '<li>Butiran &lt;b&gt;satu&lt;/b&gt;</li>' in b and run("echo legal_version();")!=v1,'Terma disunting: format ## / - , teks di-escape, versi berubah')
    check(sql('SELECT COUNT(*) n FROM consents WHERE email=?',[pe],True)[0]['n']>=1,'persetujuan direkod semasa pendaftaran')
    s,b,_=p.req('/platform/?p=persetujuan&q='+pe); check(pe in b,'platform: senarai persetujuan (cari)')
    s,b,h=p.post('/platform/?p=persetujuan',{'action':'p_consents_csv','q':pe}); check('text/csv' in h.get('Content-Type','') and pe in b,'eksport CSV persetujuan (POST + CSRF)')
    # ---- A7: Sokongan & Maklumat Sistem ----
    K='TSA'+str(secrets.randbelow(9000)+1000); kpw=secrets.token_hex(12); kid=school_new(K,kpw); a=C(sbase(K)); slogin(a,K,kpw)
    s,b,_=a.post('/?page=sokongan',{'action':'sk_new','subject':'Tidak dapat import guru','category':'data','body':'Ralat semasa menampal senarai guru pukal.'})
    tk=sql('SELECT id,status FROM support_tickets WHERE school_id=?',[kid],True); check(tk and tk[0]['status']=='baru' and 'Tiket sokongan dihantar' in b,'sekolah menghantar tiket sokongan')
    tkid=tk[0]['id']; b=p.req('/platform/?p=sokongan')[1]; check('Tidak dapat import guru' in b and 'nav-badge' in b,'platform: tiket baharu dengan lencana belum dibaca')
    n0=len(mails(K.lower()+'@sekolah.test')); p.post('/platform/?p=sokongan&id='+str(tkid),{'action':'p_sk_reply','ticket_id':tkid,'body':'Sila cuba templat terkini.'})
    check(sql('SELECT status FROM support_tickets WHERE id=?',[tkid],True)[0]['status']=='dibalas' and len(mails(K.lower()+'@sekolah.test'))==n0+1,'platform membalas → status dibalas + e-mel kepada penghantar')
    b=a.req('/?page=sokongan')[1]; check('Balasan baharu' in b,'sekolah melihat tanda balasan baharu')
    b=a.req('/?page=sokongan&id='+str(tkid))[1]; check('Sila cuba templat terkini.' in b,'perbualan dipaparkan kepada sekolah')
    a.post('/?page=sokongan&id='+str(tkid),{'action':'sk_reply','ticket_id':tkid,'body':'Berjaya, terima kasih.'}); a.post('/?page=sokongan&id='+str(tkid),{'action':'sk_close','ticket_id':tkid})
    check(sql('SELECT status FROM support_tickets WHERE id=?',[tkid],True)[0]['status']=='selesai' and sql('SELECT COUNT(*) n FROM support_messages WHERE ticket_id=?',[tkid],True)[0]['n']==3,'sekolah membalas & menutup tiket')
    K2='TSA'+str(secrets.randbelow(9000)+1000); k2pw=secrets.token_hex(12); school_new(K2,k2pw); a2=C(sbase(K2)); slogin(a2,K2,k2pw)
    check(a2.req('/?page=sokongan&id='+str(tkid))[0]==404 and 'Tidak dapat import' not in a2.req('/?page=sokongan')[1],'tiket sekolah lain tidak boleh dilihat')
    for i in range(5): a2.post('/?page=sokongan',{'action':'sk_new','subject':'Ujian had '+str(i),'category':'lain','body':'Ujian had tiket sejam.'})
    s,b,_=a2.post('/?page=sokongan',{'action':'sk_new','subject':'Ujian had 6','category':'lain','body':'Ujian had tiket sejam.'}); check('Had 5 tiket' in b,'had 5 tiket baharu sejam setiap sekolah')
    # Simpanan & pemadaman tiket
    s,b,_=p.req('/platform/?p=sokongan'); check('Simpanan tiket' in b and '90 hari' in b,'sokongan: simpanan tiket lalai 90 hari dipaparkan')
    s,b,_=p.post('/platform/?p=sokongan',{'action':'p_sk_keep','hari':'abc'}); check('0–3650' in b,'tempoh simpanan tidak sah ditolak')
    sql('UPDATE support_messages SET created_at=DATE_SUB(NOW(),INTERVAL 100 DAY) WHERE ticket_id=?',[tkid]); sql('UPDATE support_tickets SET created_at=DATE_SUB(NOW(),INTERVAL 100 DAY) WHERE id=?',[tkid])
    r=json.loads(run("echo json_encode(support_daily());")); check(r['sokongan_dipadam']>=1 and not sql('SELECT id FROM support_tickets WHERE id=?',[tkid],True) and not sql('SELECT id FROM support_messages WHERE ticket_id=?',[tkid],True),'kerja harian memadam tiket tanpa mesej baharu > 90 hari (bersama mesej)')
    a.post('/?page=sokongan',{'action':'sk_new','subject':'Tiket untuk dipadam platform','category':'lain','body':'Ujian pemadaman manual platform.'}); t2=sql('SELECT id FROM support_tickets WHERE school_id=? ORDER BY id DESC LIMIT 1',[kid],True)[0]['id']
    check(run("echo json_encode(support_purge(90));")=='0' and sql('SELECT id FROM support_tickets WHERE id=?',[t2],True),'tiket baharu tidak dipadam oleh pembersihan')
    p.post('/platform/?p=sokongan&id='+str(t2),{'action':'p_sk_delete','ticket_id':t2,'school_id':kid}); check(not sql('SELECT id FROM support_tickets WHERE id=?',[t2],True),'platform memadam satu tiket secara manual')
    a.post('/?page=sokongan',{'action':'sk_new','subject':'Tiket untuk dipadam sekolah','category':'lain','body':'Ujian pemadaman oleh sekolah.'}); t3=sql('SELECT id FROM support_tickets WHERE school_id=? ORDER BY id DESC LIMIT 1',[kid],True)[0]['id']
    check(a2.post('/?page=sokongan&id='+str(t3),{'action':'sk_delete','ticket_id':t3})[0]==404 and sql('SELECT id FROM support_tickets WHERE id=?',[t3],True),'sekolah lain tidak boleh memadam tiket ini')
    a.post('/?page=sokongan&id='+str(t3),{'action':'sk_delete','ticket_id':t3}); check(not sql('SELECT id FROM support_tickets WHERE id=?',[t3],True),'pentadbir sekolah memadam tiket sendiri')
    a.post('/?page=sokongan',{'action':'sk_new','subject':'Tiket lama untuk padam sekarang','category':'lain','body':'Ujian padam sekarang.'}); t4=sql('SELECT id FROM support_tickets WHERE school_id=? ORDER BY id DESC LIMIT 1',[kid],True)[0]['id']
    sql('UPDATE support_messages SET created_at=DATE_SUB(NOW(),INTERVAL 40 DAY) WHERE ticket_id=?',[t4]); sql('UPDATE support_tickets SET created_at=DATE_SUB(NOW(),INTERVAL 40 DAY) WHERE id=?',[t4])
    p.post('/platform/?p=sokongan',{'action':'p_sk_keep','hari':'30'}); s,b,_=p.post('/platform/?p=sokongan',{'action':'p_sk_purge','hari':'30'})
    check(run("echo pset('sokongan_simpan_hari');")=='30' and not sql('SELECT id FROM support_tickets WHERE id=?',[t4],True) and 'dipadam' in b,'tetapan 30 hari & "Padam sekarang" memadam tiket lama')
    s,b,_=p.req('/platform/?p=maklumat'); check(s==200 and 'Maklumat Sistem' in b and 'Kerja harian' in b,'halaman Maklumat Sistem')
    s,b,_=p.post('/platform/?p=maklumat',{'action':'p_opcache_reset'}); check('Cache OPcache' in b and ('Cache OPcache dikosongkan' in b or 'OPcache tidak aktif' in b),'Maklumat Sistem: kosongkan cache OPcache (atau mesej jika OPcache tidak aktif)')
    check(C(PBASE).post('/platform/?p=maklumat',{'action':'p_opcache_reset'})[0]==200 and 'Log masuk platform' in C(PBASE).req('/platform/?p=maklumat')[1],'kosongkan OPcache memerlukan log masuk platform')
    print(f'All {passed} platform checks passed.',flush=True)
finally:
    for t in sql('SELECT id,code FROM schools WHERE code LIKE "TSA%"',fetch=True): run("school_delete(school_by_id("+str(t['id'])+"));")
    (ROOT/'storage'/'reset-platform.txt').unlink(missing_ok=True); sql('DELETE FROM platform_admins WHERE email LIKE ? AND email<>?',[P+'%',pemail])
    sql('DELETE FROM platform_settings WHERE name LIKE "cfg\\_%"')
    for r in saved: sql('INSERT INTO platform_settings(name,value) VALUES (?,?)',[r['name'],r['value']])
    sql('DELETE FROM platform_log WHERE admin_id IN (SELECT id FROM platform_admins WHERE email=?)',[pemail]); sql('DELETE FROM platform_admins WHERE email=?',[pemail])
