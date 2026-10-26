"""Ujian bayaran UrusTugas (rangka koku-multi Fasa B): gerbang ToyyibPay/Billplz/CHIP/SecurePay (pelayan olok-olok tempatan), callback bertandatangan,
   mod ujian, bayaran manual dengan bukti, tunai, kod diskaun, tajaan, resit & kerja harian. Tempatan sahaja: APP_ENV=local.
   Pemasangan ujian perlu PAYMENT_TEST_BASE=http://127.0.0.1:8095 dalam .env (gw_base() hanya menerima hos tempatan).
   TEST_URL=http://dem0001.localhost:8091. Rekod & tetapan QA dipulihkan."""
import base64, hashlib, hmac, http.cookiejar, http.server, json, os, re, secrets, subprocess, tempfile, threading, time, urllib.error, urllib.parse, urllib.request, glob
from pathlib import Path
ROOT=Path(__file__).resolve().parents[1]
BASE=os.environ.get('TEST_URL','http://dem0001.localhost:8091')
PBASE=os.environ.get('PLATFORM_URL',re.sub(r'//[a-z]{3}[0-9]{4}\.','//',BASE))
GW_PORT=8095
P='qapy'+secrets.token_hex(3); password=secrets.token_hex(18); secret='JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP'; passed=0
MAILDIR=ROOT/'storage'/'mail'
def run(code): return subprocess.check_output(['php','-r',"require 'app/bootstrap.php'; require_once 'app/settings.php'; require_once 'app/install.php'; require_once 'app/platform.php';"+code],cwd=ROOT,text=True).strip()
def sql(q,p=None,fetch=False): return json.loads(run('echo json_encode(query('+json.dumps(q)+','+json.dumps(p or [])+')->'+('fetchAll()' if fetch else 'rowCount()')+');'))
def one(q,p=None): r=sql(q,p,True); return r[0] if r else None
def check(ok,label):
    global passed
    assert ok,label
    passed+=1; print('PASS:',label,flush=True)
class C:
    def __init__(s,base): s.base=base; s.op=urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
    def req(s,path,data=None,headers=None):
        if isinstance(data,dict): data=urllib.parse.urlencode(data,doseq=True).encode()
        r=urllib.request.Request(path if path.startswith('http') else s.base+path,data,headers or {})
        try: r=s.op.open(r)
        except urllib.error.HTTPError as e: r=e
        return r.code,r.read().decode('utf-8','replace'),r.headers
    def csrf(s,path): _,b,_=s.req(path); m=re.search(r'name="csrf" value="([a-f0-9]+)"',b); return m[1] if m else ''
    def post(s,path,data): return s.req(path,{'csrf':s.csrf(path),**data})
    def multipart(s,path,fields,files):
        bd='----kk'+secrets.token_hex(8); out=b''
        for k,v in {'csrf':s.csrf(path),**fields}.items(): out+=f'--{bd}\r\nContent-Disposition: form-data; name="{k}"\r\n\r\n{v}\r\n'.encode()
        for k,(fn,ct,data) in files.items(): out+=f'--{bd}\r\nContent-Disposition: form-data; name="{k}"; filename="{fn}"\r\nContent-Type: {ct}\r\n\r\n'.encode()+data+b'\r\n'
        return s.req(path,out+f'--{bd}--\r\n'.encode(),{'Content-Type':'multipart/form-data; boundary='+bd})
def raw_post(url,data,headers=None):
    body=data if isinstance(data,bytes) else urllib.parse.urlencode(data).encode()
    try: r=urllib.request.urlopen(urllib.request.Request(url,body,headers or {}))
    except urllib.error.HTTPError as e: r=e
    return r.code,r.read().decode()
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
    run("[$s]=school_create("+json.dumps(code)+",'SK Ujian Bayaran','SK','Admin Bayaran',"+json.dumps(code.lower()+'@sekolah.test')+","+json.dumps(pw)+"); tenant_use($s,'path'); query('UPDATE users SET must_change=0,totp_secret=? WHERE school_id=?',[encrypt_value("+json.dumps(secret)+"),sid()]);")
    return int(one('SELECT id FROM schools WHERE code=?',[code])['id'])
def sbase(sc): return re.sub(r'//',r'//'+sc.lower()+'.',PBASE,count=1)
def slogin(c,sc,pw):
    email=sc.lower()+'@sekolah.test'; c.post('/?page=login',{'action':'login','email':email,'password':pw,'website':''})
    sql('UPDATE users SET last_totp_step=-1 WHERE email=?',[email]); return c.post('/?page=verify',{'action':'verify','code':code()})
def exp(sid): return one('SELECT expires_on FROM schools WHERE id=?',[sid])['expires_on']
def plus_year(d,n=1): return run(f"echo date('Y-m-d',strtotime('{d} +{n} year'));")
def last_pay(sid,method=None): return one('SELECT * FROM payments WHERE school_id=?'+(' AND method=?' if method else '')+' ORDER BY id DESC LIMIT 1',[sid]+([method] if method else []))

# ---------- Pelayan gerbang olok-olok (127.0.0.1:8095) ----------
KEYDIR=Path(tempfile.mkdtemp()); subprocess.run(['openssl','genpkey','-algorithm','RSA','-out',str(KEYDIR/'k.pem'),'-pkeyopt','rsa_keygen_bits:2048'],check=True,capture_output=True)
PUB=subprocess.check_output(['openssl','pkey','-in',str(KEYDIR/'k.pem'),'-pubout'],text=True)
subprocess.run(['openssl','genpkey','-algorithm','RSA','-out',str(KEYDIR/'bad.pem'),'-pkeyopt','rsa_keygen_bits:2048'],check=True,capture_output=True)
def chip_sign(raw,key='k.pem'):
    (KEYDIR/'body').write_bytes(raw); return base64.b64encode(subprocess.check_output(['openssl','dgst','-sha256','-sign',str(KEYDIR/key),str(KEYDIR/'body')])).decode()
BILLS={}; SEEN=[]
class GW(http.server.BaseHTTPRequestHandler):
    def log_message(self,*a): pass
    def send(self,obj,code=200):
        b=(obj if isinstance(obj,str) else json.dumps(obj)).encode(); self.send_response(code); self.send_header('Content-Type','application/json' if not isinstance(obj,str) else 'text/html'); self.send_header('Content-Length',str(len(b))); self.end_headers(); self.wfile.write(b)
    def body(self):
        n=int(self.headers.get('Content-Length') or 0); raw=self.rfile.read(n).decode(); SEEN.append((self.path,raw,dict(self.headers)))
        return json.loads(raw) if self.headers.get('Content-Type','').startswith('application/json') else dict(urllib.parse.parse_qsl(raw))
    def do_GET(self):
        p=self.path.split('?')[0]
        if p.startswith('/api/v3/bills/'): b=BILLS.get(p.rsplit('/',1)[1]); return self.send({'id':b['id'],'paid':b['paid'],'paid_amount':b['paid_amount'] if b['paid'] else 0,'amount':b['amount']} if b else {'error':{'message':'not found'}},200 if b else 404)
        if p=='/api/v1/public_key/': return self.send(PUB)
        if p.startswith('/api/v1/purchases/'): b=BILLS.get(p.strip('/').rsplit('/',1)[1]); return self.send({'id':b['id'],'status':'paid' if b['paid'] else 'created','purchase':{'total':b['paid_amount']}} if b else {},200 if b else 404)
        return self.send('<html>MOCKPAY '+p+'</html>')
    def do_POST(self):
        p=self.path.split('?')[0]; f=self.body()
        if p=='/index.php/api/createBill':
            i='TP'+secrets.token_hex(4); BILLS[i]={'id':i,'amount':int(f['billAmount']),'paid':False,'paid_amount':int(f['billAmount']),'ref':f['billExternalReferenceNo'],'f':f}; return self.send([{'BillCode':i}])
        if p=='/index.php/api/getBillTransactions':
            b=BILLS.get(f.get('billCode','')); return self.send([{'billpaymentStatus':'1','billpaymentAmount':'%.2f'%(b['paid_amount']/100)}] if b and b['paid'] else [])
        if p=='/api/v3/bills':
            i='bp'+secrets.token_hex(4); BILLS[i]={'id':i,'amount':int(f['amount']),'paid':False,'paid_amount':int(f['amount']),'ref':f['reference_1'],'f':f}; return self.send({'id':i,'url':f'http://127.0.0.1:{GW_PORT}/bp/pay/{i}'})
        if p=='/api/v1/purchases/':
            i='ch-'+secrets.token_hex(6); amt=f['purchase']['products'][0]['price']; BILLS[i]={'id':i,'amount':amt,'paid':False,'paid_amount':amt,'ref':f['reference'],'f':f}; return self.send({'id':i,'checkout_url':f'http://127.0.0.1:{GW_PORT}/chip/pay/{i}'})
        return self.send({'error':'?'},404)
srv=http.server.ThreadingHTTPServer(('127.0.0.1',GW_PORT),GW); threading.Thread(target=srv.serve_forever,daemon=True).start()

PNG=base64.b64decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==')
TP='tp-secret-'+secrets.token_hex(6); BPK='bp-key-'+secrets.token_hex(6); BPX='bp-xsig-'+secrets.token_hex(6); CHK='chip-key-'+secrets.token_hex(6); SPT='sp-tok-'+secrets.token_hex(6); SPC='sp-cs-'+secrets.token_hex(6)
CB=PBASE+'/bayar-callback?g='
def tp_cb(b,status='1'):
    f={'refno':'TPREF'+secrets.token_hex(3),'status':status,'reason':'','billcode':b['id'],'order_id':b['ref'],'amount':'%.2f'%(b['amount']/100)}
    f['hash']=hashlib.md5((TP+f['status']+f['order_id']+f['refno']+'ok').encode()).hexdigest(); return f
def bp_cb(b):
    f={'id':b['id'],'collection_id':'col1','paid':'true','state':'paid','amount':str(b['amount']),'paid_amount':str(b['amount']),'paid_at':'2026-10-21 10:00:00 +0800'}
    f['x_signature']=hmac.new(BPX.encode(),'|'.join(sorted([k+v for k,v in f.items()],key=str.lower)).encode(),hashlib.sha256).hexdigest(); return f
def sp_cb(ref,amount_sen,status='true',key=None):
    f={'order_number':ref,'payment_status':status,'transaction_amount':'%.2f'%(amount_sen/100),'merchant_reference_number':'SP'+secrets.token_hex(3),'buyer_name':'Cikgu'}
    f['checksum']=hmac.new((key or SPC).encode(),'|'.join(f[k] for k in sorted(f)).encode(),hashlib.sha256).hexdigest(); return f

check(run("echo env('APP_ENV');")=='local' and run("echo gw_base('https://x');")==f'http://127.0.0.1:{GW_PORT}','persekitaran tempatan & PAYMENT_TEST_BASE menghala ke gerbang olok-olok')
check(run("putenv('PAYMENT_TEST_BASE=https://evil.example'); $_ENV['PAYMENT_TEST_BASE']='https://evil.example'; echo gw_base('https://toyyibpay.com');")=='https://toyyibpay.com','PAYMENT_TEST_BASE bukan hos tempatan diabaikan')
pemail=P+'@platform.test'; saved=sql('SELECT name,value FROM platform_settings WHERE name LIKE "cfg\\_%"',fetch=True); sql('DELETE FROM rate_limits')
run('query("INSERT INTO platform_admins(name,email,password_hash,totp_secret) VALUES (?,?,?,?)",['+json.dumps('QA Bayaran')+','+json.dumps(pemail)+',hash_password('+json.dumps(password)+'),encrypt_value('+json.dumps(secret)+')]);')
run("pset_set(['harga_pro_sen'=>'12000','bayaran_aktif'=>'1','mail_method'=>'log','mail_from'=>'noreply@"+P+".test','emel_notis'=>'"+P+"@notis.test']);")
DC='QA'+secrets.token_hex(3).upper(); SPN='Penaja QA '+P
try:
    p=C(PBASE); plogin(p,pemail)
    s,b,_=p.req('/platform/?p=kaedah'); check(s==200 and 'Kaedah Bayaran' in b and 'ToyyibPay' in b and '/bayar-callback?g=chip' in b,'platform: halaman Kaedah Bayaran (gerbang & URL callback)')
    s,b,_=p.post('/platform/?p=kaedah',{'action':'p_pay_methods','bayaran_aktif':'1','gw_billplz':'1'}); check('Billplz: lengkapkan semua medan' in b and run("echo pset('gw_billplz','0');")=='0','mengaktifkan gerbang tanpa kunci ditolak')
    form={'action':'p_pay_methods','bayaran_aktif':'1','gw_toyyibpay':'1','tp_secret':TP,'tp_category':'cat1','gw_billplz':'1','bp_key':BPK,'bp_collection':'col1','bp_xsig':BPX,
          'gw_chip':'1','chip_key':CHK,'chip_brand':'brand1','gw_securepay':'1','sp_uid':'uid1','sp_token':SPT,'sp_checksum':SPC,'m_bank':'1','info_bank':'Maybank 1234 5678 9012\nKoku QA Sdn Bhd','m_qr':'1','qr_info':'Imbas & simpan resit','m_tunai':'1','tunai_info':'Bayar di pejabat PPD'}
    s,b,_=p.multipart('/platform/?p=kaedah',form,{'qr':('qr.png','image/png',PNG)})
    check('Kaedah bayaran disimpan' in b and run("echo implode(',',gw_list()),'|',implode(',',manual_list());")=='toyyibpay,billplz,chip,securepay|bank,qr,tunai','simpan kaedah: 4 gerbang + bank/QR/tunai aktif')
    raw=one('SELECT value FROM platform_settings WHERE name="cfg_tp_secret"')['value']
    check(TP not in raw and run("echo gw_secret('tp_secret');")==TP and run("echo pset('qr_ver')!==''?'ya':'tidak';")=='ya','kunci rahsia disimpan bersulit; imej QR dikod semula & disimpan')
    s,b,_=p.post('/platform/?p=kaedah',{**{k:v for k,v in form.items() if k not in('tp_secret','bp_key','bp_xsig','chip_key','sp_token','sp_checksum')}}); check('Kaedah bayaran disimpan' in b and run("echo gw_secret('tp_secret');")==TP,'medan rahsia kosong mengekalkan nilai tersimpan')
    # ---- Sekolah ----
    K='QBY'+str(secrets.randbelow(9000)+1000); kpw=secrets.token_hex(12); kid=school_new(K,kpw); a=C(sbase(K)); slogin(a,K,kpw)
    s,b,h=a.req('/?page=langganan')
    check(s==200 and 'Bayar langganan Pro' in b and 'ToyyibPay' in b and 'SecurePay' in b and 'Maybank 1234' in b and 'page=bayar_qr' in b,'sekolah: Langganan memaparkan gerbang, bank & QR')
    check('https://toyyibpay.com' in h.get('Content-Security-Policy','') and 'form-action' in h.get('Content-Security-Policy',''),'CSP halaman Langganan membenarkan hos gerbang (form-action)')
    s,b,h=a.req('/?page=bayar_qr&v=x'); check(s==200 and h.get('Content-Type')=='image/png','imej QR dihidang kepada pentadbir sekolah')
    # Kod diskaun
    p.post('/platform/?p=diskaun',{'action':'p_disc_add','kod':DC.lower(),'jenis':'peratus','nilai':'20','tamat':'','had':'','catatan':'QA'})
    check(one('SELECT kind,value FROM discounts WHERE code=?',[DC])=={'kind':'peratus','value':20},'platform mencipta kod diskaun (dinormalkan huruf besar)')
    s,b,_=a.post('/?page=langganan',{'action':'pay_kod','kod':'TIADA123'}); check('tidak sah' in b,'kod diskaun tidak wujud ditolak')
    s,b,_=a.post('/?page=langganan',{'action':'pay_kod','kod':DC}); check('RM96.00' in b and DC in b,'kod diskaun 20%: RM120 → RM96')
    # ---- ToyyibPay ----
    e0=exp(kid); s,b,_=a.post('/?page=langganan',{'action':'pay_online','g':'toyyibpay','nama':'Cikgu Bayar','emel':'bayar@'+K.lower()+'.test','telefon':'0123456789'})
    pay=last_pay(kid,'toyyibpay'); bill=BILLS[pay['ext_id']]
    check('MOCKPAY /'+pay['ext_id'] in b and pay['status']=='menunggu' and pay['amount_sen']==9600 and pay['discount_code']==DC and bill['amount']==9600,'ToyyibPay: bil dicipta (RM96 dengan kod) & dialih ke gerbang')
    check(bill['f']['billCallbackUrl']==CB+'toyyibpay' and 'page=bayar_kembali' in bill['f']['billReturnUrl'] and bill['f']['billReturnUrl'].startswith('http'),'URL callback & kembali mutlak dihantar ke gerbang')
    ret=bill['f']['billReturnUrl']; anon=C('')
    s,b,_=anon.req(ret); check(s==200 and 'belum disahkan' in b and last_pay(kid,'toyyibpay')['status']=='menunggu','halaman kembali sebelum dibayar: belum disahkan (status disemak melalui API)')
    s,b,_=anon.req(re.sub(r't=[a-f0-9]+','t='+'0'*32,ret)); check(s==404,'halaman kembali dengan token palsu → 404')
    f=tp_cb(bill); f['hash']='0'*32; s,b=raw_post(CB+'toyyibpay',f); check(s==403,'callback ToyyibPay dengan hash palsu → 403')
    s,b=raw_post(CB+'toyyibpay',tp_cb(bill)); check(s==200 and b=='BELUM' and last_pay(kid,'toyyibpay')['status']=='menunggu','callback sah tetapi API kata belum dibayar → BELUM (tidak percaya callback sahaja)')
    f=tp_cb(bill); f['order_id']='UTLAIN-1'; f['hash']=hashlib.md5((TP+f['status']+f['order_id']+f['refno']+'ok').encode()).hexdigest(); s,b=raw_post(CB+'toyyibpay',f); check(s==403,'callback ToyyibPay: order_id tidak sepadan dengan bil → 403')
    bill['paid']=True; n0=len(mails('bayar@'+K.lower()+'.test'))
    s,b=raw_post(CB+'toyyibpay',tp_cb(bill)); pay=last_pay(kid,'toyyibpay')
    check(s==200 and b=='OK' and pay['status']=='berjaya' and exp(kid)==plus_year(e0) and pay['expires_from']==e0 and re.fullmatch(r'UT\d{4}-\d{6}',pay['receipt_no'] or ''),'callback + API dibayar → berjaya, Pro +1 tahun dari tarikh tamat semasa, no. resit')
    s,b=raw_post(CB+'toyyibpay',tp_cb(bill)); check(b=='OK' and exp(kid)==plus_year(e0),'callback berulang idempoten (tarikh tamat tidak dilanjutkan dua kali)')
    s,b,_=anon.req(ret); check('Terima kasih' in b and pay['receipt_no'] in b,'halaman kembali: bayaran diterima & no. resit')
    m=mails('bayar@'+K.lower()+'.test'); check(len(m)==n0+1 and pay['receipt_no'] in m[-1] and len(mails(K.lower()+'@sekolah.test'))>=1,'e-mel resit kepada pembayar & pentadbir sekolah')
    s,b,_=a.req('/?page=langganan&resit='+str(pay['id'])); check(s==200 and pay['receipt_no'] in b and 'RM96.00' in b and 'Diskaun ('+DC+')' in b and 'print-btn' in b,'resit boleh cetak (jumlah, diskaun)')
    s,b,_=a.req('/?page=langganan'); check('resit='+str(pay['id']) in b and pay['ref'] in b,'sejarah bayaran sekolah dengan pautan resit')
    s,b,_=a.post('/?page=langganan',{'action':'pay_kod','kod':DC}); check('telah digunakan' in b,'kod diskaun yang sama tidak boleh digunakan semula oleh sekolah')
    # ---- Billplz ----
    e1=exp(kid); a.post('/?page=langganan',{'action':'pay_online','g':'billplz','nama':'Cikgu Bayar','emel':'bayar@'+K.lower()+'.test','telefon':'0123456789'})
    pay=last_pay(kid,'billplz'); bill=BILLS[pay['ext_id']]
    check(pay['amount_sen']==12000 and bill['f']['callback_url']==CB+'billplz' and any(x[2].get('Authorization','').startswith('Basic ') for x in SEEN if x[0]=='/api/v3/bills'),'Billplz: bil RM120 dicipta (Basic auth)')
    f=bp_cb(bill); f['x_signature']='0'*64; s,b=raw_post(CB+'billplz',f); check(s==403,'callback Billplz X-Signature palsu → 403')
    bill['paid']=True; bill['paid_amount']=5000; s,b=raw_post(CB+'billplz',bp_cb(bill)); check(b=='BELUM' and exp(kid)==e1,'Billplz: jumlah dibayar kurang daripada bil → tidak disahkan')
    bill['paid_amount']=12000; s,b=raw_post(CB+'billplz',bp_cb(bill)); check(b=='OK' and exp(kid)==plus_year(e1) and last_pay(kid,'billplz')['status']=='berjaya','Billplz: tandatangan + API → berjaya')
    # ---- CHIP ----
    e2=exp(kid); a.post('/?page=langganan',{'action':'pay_online','g':'chip','nama':'Cikgu Bayar','emel':'bayar@'+K.lower()+'.test','telefon':'0123456789'})
    pay=last_pay(kid,'chip'); bill=BILLS[pay['ext_id']]; bill['paid']=True; body=json.dumps({'id':bill['id'],'status':'paid','event_type':'purchase.paid'}).encode()
    s,b=raw_post(CB+'chip',body,{'Content-Type':'application/json','X-Signature':chip_sign(body,'bad.pem')}); check(s==403 and exp(kid)==e2,'callback CHIP ditandatangani kunci lain → 403')
    s,b=raw_post(CB+'chip',body,{'Content-Type':'application/json','X-Signature':chip_sign(body)})
    check(b=='OK' and exp(kid)==plus_year(e2) and 'BEGIN PUBLIC KEY' in run("echo pset('chip_pubkey');"),'CHIP: RSA X-Signature (kunci awam dimuat turun) + API → berjaya')
    # ---- SecurePay ----
    e3=exp(kid); s,b,_=a.post('/?page=langganan',{'action':'pay_online','g':'securepay','nama':'Cikgu Bayar','emel':'bayar@'+K.lower()+'.test','telefon':'0123456789'})
    pay=last_pay(kid,'securepay')
    check('Teruskan ke SecurePay' in b and f'http://127.0.0.1:{GW_PORT}/api/v1/payments' in b and 'name="checksum"' in b and pay['ref'] in b,'SecurePay: borang POST bertandatangan ke gerbang')
    s,b=raw_post(CB+'securepay',sp_cb(pay['ref'],12000,key='salah')); check(s==403,'callback SecurePay checksum palsu → 403')
    s,b=raw_post(CB+'securepay',sp_cb(pay['ref'],12000,status='false')); check(b=='BELUM' and exp(kid)==e3,'SecurePay: status gagal → tidak disahkan')
    s,b=raw_post(CB+'securepay',sp_cb(pay['ref'],12000)); check(b=='OK' and exp(kid)==plus_year(e3),'SecurePay: checksum HMAC sah & jumlah cukup → berjaya')
    # ---- Callback umum ----
    try: urllib.request.urlopen(CB+'toyyibpay'); g405=200
    except urllib.error.HTTPError as e: g405=e.code
    s,_=raw_post(PBASE+'/bayar-callback?g=paypal',{'x':'1'}); s2,_=raw_post(CB+'toyyibpay',tp_cb({'id':'TPTIADA99','ref':'UTX-1','amount':100}))
    check(g405==405 and s==400 and s2==404,'callback: GET → 405, gerbang tidak dikenali → 400, bil tidak wujud → 404')
    # ---- Mod ujian (sandbox) ----
    p.post('/platform/?p=kaedah',{**{k:v for k,v in form.items() if k not in('tp_secret','bp_key','bp_xsig','chip_key','sp_token','sp_checksum')},'tp_sandbox':'1'})
    check(run("echo implode(',',gw_sandbox_active());")=='toyyibpay' and 'MOD UJIAN' in a.req('/?page=langganan')[1] and 'MOD UJIAN' in p.req('/platform/?p=bayaran')[1],'mod ujian ToyyibPay: sepanduk di sekolah & platform')
    e4=exp(kid); a.post('/?page=langganan',{'action':'pay_online','g':'toyyibpay','nama':'Cikgu Bayar','emel':'bayar@'+K.lower()+'.test','telefon':'0123456789'})
    pay=last_pay(kid,'toyyibpay'); bill=BILLS[pay['ext_id']]; bill['paid']=True; s,b=raw_post(CB+'toyyibpay',tp_cb(bill))
    pay=last_pay(kid,'toyyibpay'); check(b=='BELUM' and pay['status']=='semak' and exp(kid)==e4,'bayaran mod ujian ditahan "Perlu semak" — tidak melanjutkan langganan')
    s,b,_=p.req('/platform/?p=bayaran&f=semak'); check(pay['ref'] in b and 'MOD UJIAN' in b,'platform: senarai Perlu semak')
    s,b,_=p.post('/platform/?p=bayaran&f=semak',{'action':'p_pay_approve','id':pay['id']}); check('disahkan' in b and exp(kid)==plus_year(e4) and last_pay(kid,'toyyibpay')['status']=='berjaya','platform meluluskan bayaran ditahan → Pro dilanjutkan')
    e4b=exp(kid); a.post('/?page=langganan',{'action':'pay_online','g':'toyyibpay','nama':'Cikgu Bayar','emel':'bayar@'+K.lower()+'.test','telefon':'0123456789'})
    sbp=last_pay(kid,'toyyibpay'); check(sbp['sandbox']==1,'bil dicipta dalam mod ujian ditanda sandbox')
    p.post('/platform/?p=kaedah',{k:v for k,v in form.items() if k not in('tp_secret','bp_key','bp_xsig','chip_key','sp_token','sp_checksum')})
    BILLS[sbp['ext_id']]['paid']=True; s,b=raw_post(CB+'toyyibpay',tp_cb(BILLS[sbp['ext_id']]))
    check(b=='BELUM' and last_pay(kid,'toyyibpay')['status']=='semak' and exp(kid)==e4b,'bil mod ujian kekal ditahan walaupun mod ujian dimatikan selepas bil dicipta')
    sql('UPDATE payments SET status="dibatal" WHERE id=?',[sbp['id']])
    # ---- Bukti manual (bank) ----
    s,b,_=a.multipart('/?page=langganan',{'action':'pay_bukti','m':'bank','rujukan':'PO-QA-1'},{'bukti':('slip.txt','text/plain',b'bukan imej')}); check('Format bukti tidak disokong' in b,'bukti bukan imej/PDF ditolak')
    n0=len(mails(P+'@notis.test')); s,b,_=a.multipart('/?page=langganan',{'action':'pay_bukti','m':'bank','rujukan':'PO-QA-1'},{'bukti':('slip.png','image/png',PNG)})
    pay=last_pay(kid,'bank'); pf=ROOT/'storage'/'bukti'/pay['proof']
    check('Bukti bayaran dihantar' in b and pay['status']=='menunggu' and pf.is_file() and len(mails(P+'@notis.test'))==n0+1,'sekolah menghantar bukti pindahan bank (fail di luar public_html, notis platform)')
    s,b,_=p.req('/platform/?p=bayaran&f=menunggu'); check('PO-QA-1' in b and 'nav-badge' in b,'platform: bukti menunggu dengan lencana')
    s,b,h=p.req('/platform/?p=bayaran&bukti='+str(pay['id'])); check(s==200 and h.get('Content-Type')=='image/png' and 'sandbox' in h.get('Content-Security-Policy',''),'platform melihat bukti (CSP sandbox)')
    check(a.req('/?page=bayaran&bukti='+str(pay['id']))[0]==404,'bukti tidak boleh diakses dari laman sekolah')
    e5=exp(kid); p.post('/platform/?p=bayaran&f=menunggu',{'action':'p_pay_reject','id':pay['id'],'sebab':'Slip kabur'})
    check(last_pay(kid,'bank')['status']=='ditolak' and exp(kid)==e5 and any('Slip kabur' in x for x in mails(K.lower()+'@sekolah.test')),'platform menolak bukti → ditolak + e-mel sebab')
    a.multipart('/?page=langganan',{'action':'pay_bukti','m':'qr','rujukan':'QR-QA-2'},{'bukti':('slip.png','image/png',PNG)}); pay=last_pay(kid,'qr')
    s,b,_=p.post('/platform/?p=bayaran&f=menunggu',{'action':'p_pay_approve','id':pay['id']}); check(last_pay(kid,'qr')['status']=='berjaya' and exp(kid)==plus_year(e5),'platform mengesahkan bukti DuitNow QR → Pro +1 tahun')
    gw=last_pay(kid,'billplz'); s,b,_=p.post('/platform/?p=bayaran',{'action':'p_pay_approve','id':gw['id']}); check('berubah status' in b,'bayaran gerbang yang sudah berjaya tidak boleh "disahkan" semula')
    # ---- Tunai oleh platform ----
    e6=exp(kid); s,b,_=p.post('/platform/?p=sekolah&id='+str(kid),{'action':'p_pay_manual','school_id':kid,'kaedah':'tunai','jumlah':'240.00','tahun':'2','rujukan':'RESIT-PPD-77'})
    pay=last_pay(kid,'tunai'); check('Bayaran direkod' in b and pay['amount_sen']==24000 and exp(kid)==plus_year(e6,2) and pay['receipt_no'],'platform merekod bayaran tunai 2 tahun (resit)')
    s,b,_=p.req('/platform/?p=bayaran&resit='+str(pay['id'])); check(s==200 and 'RESIT-PPD-77' in b and '2 tahun' in b,'platform membuka resit')
    s,b,_=p.req('/platform/?p=sekolah&id='+str(kid)); check('Rekod bayaran manual' in b and pay['receipt_no'] in b,'panel bayaran di halaman sekolah (platform)')
    # ---- Pengasingan sekolah ----
    K2='QBY'+str(secrets.randbelow(9000)+1000); k2pw=secrets.token_hex(12); kid2=school_new(K2,k2pw); a2=C(sbase(K2)); slogin(a2,K2,k2pw)
    check(a2.req('/?page=langganan&resit='+str(pay['id']))[0]==404 and 'RESIT-PPD-77' not in a2.req('/?page=langganan')[1],'resit & sejarah bayaran sekolah lain tidak boleh dilihat')
    other=last_pay(kid,'toyyibpay'); s,b,_=C('').req(sbase(K2)+'/?page=bayar_kembali&byr='+str(other['id'])+'&t='+run("echo pay_return_token("+str(other['id'])+");"))
    check(s==404,'halaman kembali bayaran sekolah lain (melalui hos sekolah B) → 404')
    # ---- Tajaan ----
    p.post('/platform/?p=tajaan',{'action':'p_tj_sponsor','nama':SPN,'emel':P+'@penaja.test','telefon':'','catatan':''}); spid=one('SELECT id FROM sponsors WHERE name=?',[SPN])['id']
    e7=exp(kid2); s,b,_=p.post('/platform/?p=tajaan',{'action':'p_tj_give','penaja':spid,'kod_sekolah':K2+', XXX9999','mod':'lanjut','lanjut':'1','tahun':'2026','tarikh':'','nilai':'120','rujukan':'SURAT-QA','emel_sekolah':'1'})
    check('1 sekolah ditaja' in b and 'XXX9999' in b and exp(kid2)==plus_year(e7) and last_pay(kid2,'tajaan')['status']=='berjaya','tajaan: sekolah dilanjutkan 1 tahun; kod tidak wujud dilaporkan')
    s,b,_=a2.req('/?page=langganan'); check('ditaja oleh' in b and SPN in b and 'Bayar langganan Pro' not in b,'sekolah ditaja: tiada borang bayaran (lebih 30 hari)')
    s,b,_=p.post('/platform/?p=tajaan',{'action':'p_tj_give','penaja':spid,'kod_sekolah':K2,'mod':'tarikh','tarikh':'2020-01-01','lanjut':'1','tahun':'2026','nilai':'0','rujukan':''}); check('Tempoh tidak sah' in b,'tajaan tidak boleh memendekkan tarikh tamat')
    tj=one('SELECT id FROM sponsorships WHERE school_id=? AND status="aktif"',[kid2]); p.post('/platform/?p=tajaan',{'action':'p_tj_cancel','id':tj['id']})
    check(exp(kid2)==e7 and last_pay(kid2,'tajaan')['status']=='dibatal','batal tajaan → tarikh tamat dipulihkan, rekod dibatalkan')
    # ---- Kerja harian ----
    sql('INSERT INTO payments(school_id,school_code,method,amount_sen,status,ref,created_at) VALUES (?,?,"toyyibpay",12000,"menunggu","UTLAMA-1",DATE_SUB(NOW(),INTERVAL 4 DAY))',[kid2,K2])
    p.post('/platform/?p=tajaan',{'action':'p_tj_give','penaja':spid,'kod_sekolah':K2,'mod':'lanjut','lanjut':'1','tahun':'2026','tarikh':'','nilai':'0','rujukan':''})
    sql('UPDATE sponsorships SET end_on=DATE_ADD(CURDATE(),INTERVAL 10 DAY) WHERE school_id=? AND status="aktif"',[kid2]); sql('UPDATE schools SET expires_on=DATE_ADD(CURDATE(),INTERVAL 10 DAY) WHERE id=?',[kid2])
    n0=len(mails(P+'@penaja.test')); r=json.loads(run("echo json_encode(payments_daily());"))
    check(one('SELECT status FROM payments WHERE ref="UTLAMA-1"')['status']=='gagal' and r['luput']>=1,'kerja harian: bil gerbang > 3 hari diluputkan')
    check(len(mails(P+'@penaja.test'))==n0+1 and K2 in mails(P+'@penaja.test')[-1] and json.loads(run("echo json_encode(payments_daily());"))['peringatan_tajaan']==0,'peringatan tajaan 30 hari kepada penaja (sekali sahaja)')
    s,b,_=a2.req('/?page=langganan'); check('Bayar langganan Pro' in b,'tajaan ≤ 30 hari lagi: pilihan pembaharuan dipaparkan')
    # ---- Halaman platform & suis utama ----
    s,b,_=p.req('/platform/?p=diskaun'); check(DC in b and '>1<' in b.replace(' ',''),'platform: statistik penggunaan kod diskaun')
    s,b,_=p.post('/platform/?p=diskaun',{'action':'p_disc_delete','id':one('SELECT id FROM discounts WHERE code=?',[DC])['id']}); check('telah digunakan' in b,'kod yang telah digunakan tidak boleh dipadam')
    s,b,_=p.req('/platform/?p=bayaran&f=berjaya'); check('Kutipan' in b and K in b,'platform: senarai bayaran berjaya & kutipan tahun semasa')
    run("pset_set(['bayaran_aktif'=>'0']);")
    s,b,_=a.req('/?page=langganan'); check('tidak digunakan' in b and 'Bayar langganan Pro' not in b,'suis bayaran dimatikan: sekolah tidak nampak pilihan bayaran')
    s,b,_=a.post('/?page=langganan',{'action':'pay_online','g':'toyyibpay','nama':'X','emel':'x@x.test','telefon':'0123456789'}); check('tidak tersedia' in b,'POST bayaran ditolak apabila suis dimatikan')
    print(f'All {passed} payment checks passed.',flush=True)
finally:
    srv.shutdown()
    for t in sql('SELECT id,code FROM schools WHERE code LIKE "QBY%"',fetch=True):
        for r in sql('SELECT proof FROM payments WHERE school_id=? AND proof IS NOT NULL',[t['id']],True): (ROOT/'storage'/'bukti'/r['proof']).unlink(missing_ok=True)
        sql('DELETE FROM sponsorships WHERE school_id=?',[t['id']]); sql('DELETE FROM payments WHERE school_id=?',[t['id']]); run("school_delete(school_by_id("+str(t['id'])+"));")
    sql('DELETE FROM payments WHERE school_code LIKE "QBY%"'); sql('DELETE FROM sponsors WHERE name=?',[SPN]); sql('DELETE FROM discounts WHERE code=?',[DC])
    sql('DELETE FROM platform_settings WHERE name LIKE "cfg\\_%"')
    for r in saved: sql('INSERT INTO platform_settings(name,value) VALUES (?,?)',[r['name'],r['value']])
    sql('DELETE FROM platform_log WHERE admin_id IN (SELECT id FROM platform_admins WHERE email=?)',[pemail]); sql('DELETE FROM platform_admins WHERE email=?',[pemail])
