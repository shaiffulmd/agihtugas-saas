"""Ujian Rupa Halaman Utama (Panel Platform → Rupa Halaman Utama): warna pratetap/tersuai, fon, gambar latar hero
   (diproses semula), kegelapan lapisan, teks hero, CSS berversi tanpa gaya sebaris. Tetapan dipulihkan selepas ujian."""
import http.cookiejar, json, os, re, secrets, subprocess, urllib.error, urllib.parse, urllib.request, io
from pathlib import Path
ROOT=Path(__file__).resolve().parents[1]
BASE=os.environ.get('TEST_URL','http://dem0001.localhost:8091')
PBASE=os.environ.get('PLATFORM_URL',re.sub(r'//[a-z]{3}[0-9]{4}\.','//',BASE))
P='qarp'+secrets.token_hex(3); password=secrets.token_hex(18); secret='JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP'; passed=0
def run(code): return subprocess.check_output(['php','-r',"require 'app/bootstrap.php'; require_once 'app/settings.php'; require_once 'app/install.php'; require_once 'app/platform.php';"+code],cwd=ROOT,text=True).strip()
def sql(q,p=None,fetch=False): return json.loads(run('echo json_encode(query('+json.dumps(q)+','+json.dumps(p or [])+')->'+('fetchAll()' if fetch else 'rowCount()')+');'))
def check(ok,label):
    global passed
    assert ok,label
    passed+=1; print('PASS:',label,flush=True)
class C:
    def __init__(s,base): s.base=base; s.op=urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
    def req(s,path,data=None,headers=None):
        if isinstance(data,dict): data=urllib.parse.urlencode(data,doseq=True).encode()
        try: r=s.op.open(urllib.request.Request(path if path.startswith('http') else s.base+path,data,headers or {}))
        except urllib.error.HTTPError as e: r=e
        return r.code,r.read(),r.headers
    def text(s,path,data=None): c,b,h=s.req(path,data); return c,b.decode('utf-8','replace'),h
    def csrf(s,path): m=re.search(r'name="csrf" value="([a-f0-9]+)"',s.text(path)[1]); return m[1] if m else ''
    def post(s,path,data): return s.text(path,{'csrf':s.csrf(path),**data})
    def multipart(s,path,fields,files):
        bd='----kk'+secrets.token_hex(8); out=b''
        for k,v in {'csrf':s.csrf(path),**fields}.items(): out+=f'--{bd}\r\nContent-Disposition: form-data; name="{k}"\r\n\r\n{v}\r\n'.encode()
        for k,(fn,ct,data) in files.items(): out+=f'--{bd}\r\nContent-Disposition: form-data; name="{k}"; filename="{fn}"\r\nContent-Type: {ct}\r\n\r\n'.encode()+data+b'\r\n'
        c,b,h=s.req(path,out+f'--{bd}--\r\n'.encode(),{'Content-Type':'multipart/form-data; boundary='+bd}); return c,b.decode('utf-8','replace'),h
def code(): return run('echo totp('+json.dumps(secret)+',intdiv(time(),30));')
def img(w,h,fmt='jpeg'):
    return subprocess.check_output(['php','-r','$im=imagecreatetruecolor('+str(w)+','+str(h)+'); imagefilledrectangle($im,0,0,'+str(w)+','+str(h)+',imagecolorallocate($im,30,120,90)); ob_start(); image'+fmt+'($im); echo ob_get_clean();'])
pemail=P+'@platform.test'; saved=sql('SELECT name,value FROM platform_settings WHERE name LIKE "cfg\\_lp\\_%"',fetch=True); saved_faq=sql('SELECT name,value FROM platform_settings WHERE name LIKE "cfg\\_faq\\_%"',fetch=True)
run('query("INSERT INTO platform_admins(name,email,password_hash,totp_secret) VALUES (?,?,?,?)",["QA Rupa",'+json.dumps(pemail)+',hash_password('+json.dumps(password)+'),encrypt_value('+json.dumps(secret)+')]);')
BASEF={'action':'p_rupa_save','lp_preset':'delima','lp_primary':'#c2410c','lp_dark':'#0b1a2b','lp_accent':'#fb923c','lp_fon':'klasik','lp_hero_gelap':'70','lp_lencana':'','lp_t1':'','lp_t2':'','lp_teks':''}
try:
    sql('DELETE FROM platform_settings WHERE name LIKE "cfg\\_lp\\_%"')
    p=C(PBASE); sql('UPDATE platform_admins SET last_totp_step=-1 WHERE email=?',[pemail]); p.post('/platform/',{'action':'p_login','email':pemail,'password':password}); p.post('/platform/?p=verify',{'action':'p_verify','code':code()})
    s,b,_=p.text('/platform/?p=rupa'); check(s==200 and 'Rupa Halaman Utama' in b and 'Warna tersuai' in b and 'Mesra' in b,'halaman Rupa Halaman Utama (warna, fon, gambar, teks)')
    s,b,_=C(PBASE).text('/'); v=re.search(r'href="/\?page=lp-css&amp;v=([a-f0-9]{12})"',b); check(v and 'Agih tugas guru' in b and 'style=' not in b,'halaman utama memuat CSS rupa berversi, teks lalai, tiada gaya sebaris')
    s,css,h=C(PBASE).text('/?page=lp-css&v='+v[1]); check('--primary:#c2410c' in css and 'Playfair Display' in css and 'immutable' in h.get('Cache-Control',''),'CSS lalai: oren DELIMa + Playfair, di-cache kekal')
    p.post('/platform/?p=rupa',{**BASEF,'lp_preset':'hijau','lp_fon':'mesra','lp_lencana':'Platform QA','lp_t1':'Kokurikulum','lp_t2':'lebih mudah.','lp_teks':'Teks <b>hero</b> QA.'})
    s,b,_=C(PBASE).text('/'); v2=re.search(r'lp-css&amp;v=([a-f0-9]{12})',b)[1]; css=C(PBASE).text('/?page=lp-css&v='+v2)[1]
    check(v2!=v[1] and '--primary:#176c5b' in css and 'nunito-latin-800-normal.woff2' in css and '"Nunito"' in css,'pratetap Hijau + fon Mesra: CSS & versi berubah, @font-face Nunito')
    check('Platform QA' in b and 'Kokurikulum<br><em>lebih mudah.</em>' in b and 'Teks &lt;b&gt;hero&lt;/b&gt; QA.' in b,'teks hero disimpan & dipapar sebagai teks biasa (HTML di-escape)')
    check(C(PBASE).text('/lapor-keselamatan')[1].count('lp-css&amp;v='+v2)==1 and C(PBASE).text('/terma')[1].count('lp-css&amp;v='+v2)==1,'rupa sama pada /terma & /lapor-keselamatan')
    s,b,_=p.post('/platform/?p=rupa',{**BASEF,'lp_preset':'custom','lp_primary':'merah'}); check('Warna tidak sah' in b,'warna tersuai tidak sah ditolak')
    p.post('/platform/?p=rupa',{**BASEF,'lp_preset':'custom','lp_primary':'#123ABC','lp_dark':'#101010','lp_accent':'#ff00aa','lp_fon':'sistem'}); css=C(PBASE).text('/?page=lp-css&v=x')[1]
    check('--primary:#123abc' in css and '--accent:#ff00aa' in css and '--fon-teks:system-ui' in css,'warna tersuai + fon Sistem')
    s,b,_=p.post('/platform/?p=rupa',{**BASEF,'lp_fon':'comic'}); check('Pilihan fon tidak sah' in b,'fon tidak dikenali ditolak')
    s,b,_=p.multipart('/platform/?p=rupa',BASEF,{'lp_hero':('kecil.jpg','image/jpeg',img(300,200))}); check('Saiz imej tidak sesuai' in b,'gambar terlalu kecil ditolak')
    s,b,_=p.multipart('/platform/?p=rupa',BASEF,{'lp_hero':('x.jpg','image/jpeg',b'bukan imej')}); check('Format mesti' in b,'fail bukan imej ditolak')
    s,b,_=p.multipart('/platform/?p=rupa',{**BASEF,'lp_hero_gelap':'50'},{'lp_hero':('latar.jpg','image/jpeg',img(2400,1200))})
    ver=run("echo pset('lp_hero_ver');"); css=C(PBASE).text('/?page=lp-css&v=y')[1]
    check('disimpan' in b and ver!='' and f'/?page=lp-hero&v={ver}' in css and 'rgba(11,26,43,0.75)' in css,'gambar latar dimuat naik: CSS hero dengan lapisan kegelapan 50')
    c2,raw,h=C(PBASE).req('/?page=lp-hero&v='+ver); dims=json.loads(subprocess.check_output(['php','-r','$i=getimagesizefromstring(stream_get_contents(STDIN)); echo json_encode([$i[0],$i[1],$i["mime"]]);'],input=raw))
    check(c2==200 and dims[0]==1920 and h.get('Content-Type') in ('image/webp','image/jpeg') and 'immutable' in h.get('Cache-Control',''),'gambar dikecilkan ke 1920 px, dikod semula & di-cache')
    s,b,_=p.post('/platform/?p=rupa',{'action':'p_rupa_hero_remove'}); check(run("echo pset('lp_hero_ver');")=='' and 'lp-hero' not in C(PBASE).text('/?page=lp-css&v=z')[1],'buang gambar latar → hero kembali ke warna tema')
    p.post('/platform/?p=rupa',{'action':'p_rupa_reset'}); css=C(PBASE).text('/?page=lp-css&v=w')[1]; check('--primary:#c2410c' in css and 'Agih tugas guru' in C(PBASE).text('/')[1],'kembalikan lalai')
    s,b,_=C(PBASE).post('/platform/?p=rupa',{**BASEF,'lp_preset':'ungu'}); check(run("echo pset('lp_preset');")=='delima','tanpa log masuk platform: tetapan tidak berubah')
    s,b,_=p.post('/platform/?p=rupa',{**BASEF,'lp_pautan':'Tanpa url'}); check('Label | URL' in b and 'Pautan footer' in b,'pautan footer tanpa URL ditolak')
    s,b,_=p.post('/platform/?p=rupa',{**BASEF,'lp_pautan':'Jahat | javascript:alert(1)'}); check('URL tidak sah' in b and run("echo pset('lp_pautan');")=='','URL javascript: ditolak')
    s,b,_=p.post('/platform/?p=rupa',{**BASEF,'lp_pautan_tajuk':'Pautan berguna','lp_pautan':'Portal KPM | https://www.moe.gov.my\nTerma | /terma\nE-mel | mailto:qa@contoh.my'})
    s,b,_=C(PBASE).text('/'); check('Pautan berguna' in b and 'href="https://www.moe.gov.my" target="_blank" rel="noopener noreferrer"' in b and 'href="/terma">Terma<' in b and 'lp-f5' in b,'pautan footer dipapar (luar → tab baharu, dalaman biasa)')
    # Logo & ikon (Tetapan Platform)
    s,b,_=p.text('/platform/?p=tetapan'); check('Logo &amp; ikon' in b and 'name="lp_logo"' in b,'Tetapan Platform: borang Logo & ikon')
    s,b,_=p.multipart('/platform/?p=tetapan',{'action':'p_rupa_logo_save','lp_tagline':''},{'lp_logo':('x.png','image/png',b'bukan imej')}); check('Logo mesti PNG' in b,'logo bukan imej ditolak')
    s,b,_=p.multipart('/platform/?p=tetapan',{'action':'p_rupa_logo_save','lp_tagline':'Portal QA'},{'lp_logo':('logo.png','image/png',img(800,800,'png'))})
    lv=run("echo pset('lp_logo_ver');"); c2,raw,h=C(PBASE).req('/?page=lp-logo&v='+lv); dims=json.loads(subprocess.check_output(['php','-r','$i=getimagesizefromstring(stream_get_contents(STDIN)); echo json_encode([$i[0],$i[1],$i["mime"]]);'],input=raw))
    check(lv!='' and c2==200 and dims==[512,512,'image/png'],'logo dimuat naik: dikecilkan ke 512 px & dikod semula PNG')
    s,b,_=C(PBASE).text('/'); check(f'src="/?page=lp-logo&amp;v={lv}"' in b and 'rel="icon"' in b and 'Portal QA' in b and '>u.<' not in b,'logo & tagline di header/footer halaman utama + favicon')
    s,b,_=p.text('/platform/'); check(f'lp-logo&amp;v={lv}' in b and 'side-brand' in b,'logo di bar sisi panel platform')
    p.post('/platform/?p=tetapan',{'action':'p_rupa_logo_remove'}); check(run("echo pset('lp_logo_ver');")=='' and '>u.<' in C(PBASE).text('/')[1],'buang logo → ikon lalai "u."')
    # Soalan lazim (FAQ)
    sql('DELETE FROM platform_settings WHERE name LIKE "cfg\\_faq\\_%"')
    s,b,_=C(PBASE).text('/'); check('href="/faq"' not in b and 'id="faq"' not in b,'tiada FAQ: tiada pautan / bahagian FAQ di halaman utama')
    s,b,_=p.text('/platform/?p=faq'); check(s==200 and 'Belum ada soalan' in b and 'Muatkan contoh soalan' in b,'halaman Soalan Lazim (FAQ): keadaan kosong')
    s,b,_=p.post('/platform/?p=faq',{'action':'p_faq_add','q':'ab','a':'x'}); check('Soalan mesti 3' in b,'soalan terlalu pendek ditolak')
    s,b,_=p.post('/platform/?p=faq',{'action':'p_faq_add','q':'Soalan <b>QA</b> satu?','a':'Baris satu\n\n- item A\n- item B\nLihat https://contoh.test/a <script>x</script>'}); check('Soalan ditambah' in b,'soalan ditambah')
    p.post('/platform/?p=faq',{'action':'p_faq_add','q':'Soalan QA dua?','a':'Jawapan dua.'})
    items=json.loads(run("echo pset('faq_items');")); check(len(items)==2 and items[0]['on'],'2 soalan disimpan (JSON)')
    s,b,_=C(PBASE).text('/faq'); check(s==200 and 'Soalan &lt;b&gt;QA&lt;/b&gt; satu?' in b and '<li>item A</li>' in b and 'href="https://contoh.test/a" target="_blank"' in b and '&lt;script&gt;' in b and '<script>x' not in b,'/faq: soalan di-escape, senarai & pautan dijana')
    s,b,_=C(PBASE).text('/'); check('id="faq"' in b and 'Soalan QA dua?' in b and 'href="/faq"' in b,'halaman utama: bahagian FAQ + pautan menu/footer')
    i2=items[1]['id']; p.post('/platform/?p=faq',{'action':'p_faq_move','faq_id':str(i2),'dir':'up'}); items=json.loads(run("echo pset('faq_items');")); check(items[0]['id']==i2,'susunan: alih ke atas')
    p.post('/platform/?p=faq',{'action':'p_faq_toggle','faq_id':str(i2)}); check('Soalan QA dua?' not in C(PBASE).text('/faq')[1],'soalan disembunyikan tidak dipapar')
    p.post('/platform/?p=faq',{'action':'p_faq_edit','faq_id':str(i2),'q':'Soalan QA dua (sunting)?','a':'Baharu.','on':'1'}); check('Soalan QA dua (sunting)?' in C(PBASE).text('/faq')[1],'sunting + papar semula')
    p.post('/platform/?p=faq',{'action':'p_faq_settings','faq_tajuk':'Bantuan QA','faq_utama':'0'}); b=C(PBASE).text('/')[1]; check('id="faq"' not in b and 'Bantuan QA' in C(PBASE).text('/faq')[1],'tetapan: sembunyi dari halaman utama, tajuk tersuai')
    p.post('/platform/?p=faq',{'action':'p_faq_delete','faq_id':str(i2)}); check(len(json.loads(run("echo pset('faq_items');")))==1,'soalan dipadam')
    C(PBASE).post('/platform/?p=faq',{'action':'p_faq_add','q':'Penceroboh?','a':'x'}); check(len(json.loads(run("echo pset('faq_items');")))==1,'tanpa log masuk platform: FAQ tidak berubah')
    s,b,_=C(BASE).text('/?page=dashboard'); check('lp-css' not in b,'laman sekolah tidak memuat rupa halaman utama platform')
    print(f'All {passed} appearance checks passed.',flush=True)
finally:
    sql('DELETE FROM platform_settings WHERE name LIKE "cfg\\_lp\\_%"')
    for r in saved: sql('INSERT INTO platform_settings(name,value) VALUES (?,?)',[r['name'],r['value']])
    sql('DELETE FROM platform_settings WHERE name LIKE "cfg\\_faq\\_%"')
    for r in saved_faq: sql('INSERT INTO platform_settings(name,value) VALUES (?,?)',[r['name'],r['value']])
    sql('DELETE FROM platform_log WHERE admin_id IN (SELECT id FROM platform_admins WHERE email=?)',[pemail]); sql('DELETE FROM platform_admins WHERE email=?',[pemail]); run('audit_rebuild(0);')
