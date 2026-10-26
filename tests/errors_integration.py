"""Local-only checks for the unified error pages (app/errors.php)."""
import os, urllib.request, urllib.error, json
BASE=os.environ.get('TEST_URL','http://dem0001.localhost:8091'); passed=0
def check(ok,label):
    global passed
    assert ok,label
    passed+=1; print('PASS:',label,flush=True)
def get(path,headers=None,data=None):
    try: r=urllib.request.urlopen(urllib.request.Request(BASE+path,data=data,headers=headers or {}))
    except urllib.error.HTTPError as e: r=e
    return r.code,r.read().decode('utf-8','replace'),r.headers
c,b,h=get('/tiada-halaman-ini'); check(c==404 and 'error-card' in b and 'Halaman tidak dijumpai' in b,'unknown path gives themed 404')
check('theme-css' in b and 'app.css' in b and 'no-store' in h.get('Cache-Control',''),'error page loads theme and is not cached')
check('style=' not in b.split('<body')[1] and '<script>' not in b,'no inline style/script (CSP-safe)')
c,b,h=get('/aset-hilang.png'); check(c==404 and 'error-card' in b,'missing file path gives 404 page, not landing')
c,b,h=get('/?page=ralat&kod=403'); check(c==403 and 'Akses tidak dibenarkan' in b and 'Log masuk' in b,'403 page offers login when anonymous')
c,b,h=get('/?page=ralat&kod=999'); check(c==404,'unknown error code falls back to 404')
c,b,h=get('/?page=login',data=b'action=login&csrf=salah'); check(c==419 and 'Sesi borang telah tamat' in b and 'Muat semula' in b,'CSRF failure gives 419 page with reload')
c,b,h=get('/tiada',headers={'Accept':'application/json'}); j=json.loads(b); check(c==404 and j['ralat']==404,'JSON clients receive JSON error')
c,b,h=get('/?page=urus_laman'); check(c==200 and 'Selamat kembali' in b,'protected pages still redirect anonymous users to login')
c,b,h=get('/'); check(c==200 and 'error-card' not in b,'home page unaffected')
print(f'All {passed} error page checks passed.',flush=True)
