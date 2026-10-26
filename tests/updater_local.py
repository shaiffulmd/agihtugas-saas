"""Ujian setempat pengemas kini GitHub (app/updater.php) tanpa rangkaian: pelayan GitHub API tiruan + salinan pemasangan
daripada zip keluaran. Semak: semak versi, kemas kini (fail diubah/baharu, .env & storage tidak disentuh, sandaran),
SHA-256 salah ditolak, laluan berbahaya dalam zip diabaikan, rollback, webhook HMAC (sah/tidak sah).
Guna pangkalan data .env projek; salinan tapak ujian ditetapkan APP_ENV=local. Jalankan: python3 tests/updater_local.py"""
import hashlib, hmac, http.server, json, os, shutil, socket, subprocess, sys, tempfile, threading, time, urllib.request, zipfile
from pathlib import Path
ROOT=Path(__file__).resolve().parents[1]; passed=0
def check(ok,label):
    global passed
    assert ok,label
    passed+=1; print('PASS:',label,flush=True)
def free_port():
    s=socket.socket(); s.bind(('127.0.0.1',0)); p=s.getsockname()[1]; s.close(); return p

tmp=Path(tempfile.mkdtemp(prefix='urustugasupd-')); site=tmp/'site'; pub=tmp/'gh'; pub.mkdir()
try:
    env_text=Path(os.environ.get('UPD_TEST_ENV',ROOT/'.env')).read_text()  # UPD_TEST_ENV: .env ujian alternatif (pangkalan data berasingan)
    out=subprocess.check_output(['sh','release/build-zip.sh'],cwd=ROOT,text=True).strip().splitlines()[-1]
    base_zip=ROOT/out.split(':')[0]
    with zipfile.ZipFile(base_zip) as z: z.extractall(site)
    (site/'storage').mkdir(exist_ok=True); shutil.copy(os.environ.get('UPD_TEST_LOCK',ROOT/'storage/installed.lock'),site/'storage/installed.lock')
    api_port=free_port(); secret='rahsia-ujian-'+os.urandom(4).hex()
    (site/'.env').write_text('\n'.join(l for l in env_text.splitlines() if not l.startswith('APP_ENV='))+f'\nAPP_ENV=local\nUPDATE_REPO=uji/koku\nUPDATE_TOKEN=token-uji\nUPDATE_SECRET={secret}\nUPDATE_API=http://127.0.0.1:{api_port}\n')
    (site/'storage/penanda.txt').write_text('data pengguna')
    # Versi "baharu": ubah satu fail, tambah satu fail, sertakan laluan berbahaya yang mesti diabaikan.
    new_zip=pub/'urustugas.zip'
    with zipfile.ZipFile(base_zip) as src, zipfile.ZipFile(new_zip,'w',zipfile.ZIP_DEFLATED) as dst:
        for i in src.infolist():
            data=src.read(i.filename)
            if i.filename=='app/updater.php': data+=b'\n// versi-baharu-ujian\n'
            dst.writestr(i,data)
        dst.writestr('app/upd_probe.php','<?php // fail baharu ujian\n')
        dst.writestr('.env','APP_KEY=dirampas'); dst.writestr('storage/penanda.txt','ditindih'); dst.writestr('../luar.txt','x')
    sha='a'*40
    def manifest(zsha): return {'sha':sha,'version':'0.3','built_at':'2026-10-25T00:00:00Z','zip':'urustugas.zip','zip_sha256':zsha,'message':'Ujian kemas kini'}
    state={'manifest':manifest(hashlib.sha256(new_zip.read_bytes()).hexdigest()),'auth':[]}
    class H(http.server.BaseHTTPRequestHandler):
        def log_message(self,*a): pass
        def do_GET(self):
            state['auth'].append(self.headers.get('Authorization',''))
            if self.path=='/repos/uji/koku/releases/tags/terkini':
                body=json.dumps({'assets':[{'name':'manifest.json','url':f'http://127.0.0.1:{api_port}/a/manifest'},{'name':'urustugas.zip','url':f'http://127.0.0.1:{api_port}/a/zip'}]}).encode()
            elif self.path=='/a/manifest': body=json.dumps(state['manifest']).encode()
            elif self.path=='/a/zip': body=new_zip.read_bytes()
            else: self.send_response(404); self.end_headers(); return
            self.send_response(200); self.send_header('Content-Length',str(len(body))); self.end_headers(); self.wfile.write(body)
    srv=http.server.ThreadingHTTPServer(('127.0.0.1',api_port),H); threading.Thread(target=srv.serve_forever,daemon=True).start()
    def cli(*a): r=subprocess.run(['php','bin/update.php',*a],cwd=site,capture_output=True,text=True); return r.returncode,r.stdout+r.stderr
    old_upd=(site/'app/updater.php').read_bytes()

    code,o=cli('semak'); check(code==0 and 'aaaaaaaa' in o and 'Kemas kini tersedia' in o,'semak: versi terkini dikesan')
    check(all(a=='Bearer token-uji' for a in state['auth']),'token dihantar sebagai Authorization: Bearer')
    state['manifest']=manifest('0'*64); code,o=cli('kemaskini'); check(code==1 and 'SHA-256' in o and (site/'app/updater.php').read_bytes()==old_upd,'SHA-256 salah: ditolak, tiada fail diubah')
    state['manifest']=manifest(hashlib.sha256(new_zip.read_bytes()).hexdigest())
    code,o=cli('kemaskini'); check(code==0 and 'fail dikemas kini' in o,'kemas kini berjaya: '+o.strip()[:80])
    check(b'versi-baharu-ujian' in (site/'app/updater.php').read_bytes() and (site/'app/upd_probe.php').exists(),'fail diubah & fail baharu dipasang')
    check('APP_KEY=dirampas' not in (site/'.env').read_text() and (site/'storage/penanda.txt').read_text()=='data pengguna' and not (tmp/'luar.txt').exists(),'.env, storage/ & laluan ../ tidak disentuh')
    dep=json.loads((site/'storage/update/deployed.json').read_text()); check(dep['sha']==sha and dep['backup'].startswith('sandaran-') and (site/'storage/update'/dep['backup']).exists(),'deployed.json & zip sandaran dicipta')
    code,o=cli('kemaskini'); check(code==0 and 'sudah versi terkini' in o,'kemas kini kedua: tiada perubahan (sha sama)')
    code,o=cli('rollback'); check(code==0 and (site/'app/updater.php').read_bytes()==old_upd and not (site/'app/upd_probe.php').exists(),'rollback: fail lama dipulihkan & fail baharu dibuang')
    # Webhook melalui pelayan PHP sebenar
    wp=free_port(); php=subprocess.Popen(['php','-S',f'127.0.0.1:{wp}','-t','public_html','public_html/router.php'],cwd=site,stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL); time.sleep(1.5)
    try:
        body=json.dumps({'event':'release','sha':sha}).encode()
        def hook(sig):
            req=urllib.request.Request(f'http://127.0.0.1:{wp}/kemaskini-hook',body,{'Content-Type':'application/json','X-UrusTugas-Signature':sig})
            try: return urllib.request.urlopen(req,timeout=60).status
            except urllib.error.HTTPError as e: return e.code
        check(hook('sha256=salah')==401,'webhook: tandatangan salah ditolak (401)')
        good='sha256='+hmac.new(secret.encode(),body,hashlib.sha256).hexdigest()
        check(hook(good)==202,'webhook: tandatangan sah diterima (202)')
        for _ in range(60):
            if b'versi-baharu-ujian' in (site/'app/updater.php').read_bytes(): break
            time.sleep(1)
        check(b'versi-baharu-ujian' in (site/'app/updater.php').read_bytes(),'webhook: kemas kini dipasang selepas respons')
        log=(site/'storage/update/log.jsonl').read_text(); check('"by":"webhook"' in log and '"event":"gagal"' in log and '"event":"rollback"' in log,'log kemas kini merekod semua peristiwa')
    finally: php.terminate()
    srv.shutdown()
    print(f'All {passed} updater checks passed.',flush=True)
finally:
    shutil.rmtree(tmp,ignore_errors=True)
