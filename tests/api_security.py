#!/usr/bin/env python3
"""Ujian keselamatan API v1 (Fasa 5) pada pemasangan UJIAN (APP_ENV=local; mengubah tetapan kecemasan/sekatan IP sementara).
  php tests/setup_engine_test.php > ujian.json
  TEST_URL=http://127.0.0.1:8092 python3 tests/api_security.py   (mod laluan /KOD/ ; subdomain dilencongkan bagi GET)
Semak: kunci mesti dalam header Bearer, mod kecemasan baca/tutup, sekatan IP sekolah & platform, had cubaan kunci salah,
sekolah digantung, kunci dibatalkan, tiada kuki sesi, pengepala keselamatan."""
import json, os, subprocess, sys, urllib.error, urllib.request
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
BASE = os.environ.get('TEST_URL', 'http://127.0.0.1:8092').rstrip('/')
ok = 0


def run(code):
    return subprocess.check_output(['php', '-r', "require 'app/bootstrap.php'; foreach(['settings','install','integrity','images','users','demo','platform','enjin','security'] as $f) require_once 'app/'.$f.'.php';" + code], cwd=ROOT, text=True).strip()


def check(c, label):
    global ok
    assert c, label
    ok += 1
    print('  ✓', label, flush=True)


def api(path, token=None, method='GET', body=None, headers=None, url=None):
    h = dict(headers or {})
    if token:
        h['Authorization'] = 'Bearer ' + token
    data = json.dumps(body).encode() if body is not None else None
    if body is not None:
        h['Content-Type'] = 'application/json'
    req = urllib.request.Request(url or (BASE + path), data=data, headers=h, method=method)
    try:
        r = urllib.request.urlopen(req, timeout=30)
    except urllib.error.HTTPError as e:
        r = e
    raw = r.read()
    try:
        j = json.loads(raw)
    except Exception:
        j = {}
    return r.status, j, r.headers


CODE = 'UJT0001'
token = run("$s=school_find('UJT0001'); echo with_school($s,function(){ $_SESSION['uid']=null; return api_key_create('Ujian keselamatan','write')[1]; });")
P = '/' + CODE + '/api/v1'
try:
    s, j, h = api(P + '/summary', token)
    check(s == 200 and 'Set-Cookie' not in h and h.get('Content-Type', '').startswith('application/json'), 'API: 200 JSON tanpa kuki sesi')
    check(h.get('X-Content-Type-Options') == 'nosniff' and h.get('Cache-Control') == 'no-store' and len(h.get('X-Request-ID', '')) == 16, 'pengepala keselamatan & X-Request-ID')
    s, j, _ = api(P + '/summary?token=' + token)
    check(s == 401 and j['error']['code'] == 'unauthorized', 'kunci dalam query URL ditolak')
    s, j, _ = api(P + '/summary', headers={'Authorization': 'Basic ' + token})
    check(s == 401, 'skema Authorization selain Bearer ditolak')
    s, j, _ = api('/api/v1/summary', token)
    check(s == 404 and j['error']['code'] == 'not_found', 'API tanpa kod sekolah → 404 JSON')
    s, j, _ = api('/UJT0002/api/v1/summary', token)
    check(s == 401, 'kunci sekolah lain → 401')

    print('Mod kecemasan')
    run("pset_set(['kecemasan'=>'baca']);")
    check(api(P + '/summary', token)[0] == 200, 'baca sahaja: GET dibenarkan')
    s, j, _ = api(P + '/teachers', token, 'POST', {'name': 'X', 'capacity': 10})
    check(s == 503 and j['error']['code'] == 'maintenance', 'baca sahaja: POST → 503 maintenance')
    run("pset_set(['kecemasan'=>'tutup']);")
    s, j, _ = api(P + '/summary', token)
    check(s == 503 and j['error']['code'] == 'maintenance', 'mod tutup: GET → 503')
    run("pset_set(['kecemasan'=>'']);")

    print('Sekatan IP')
    run("ip_block_add((int)school_find('UJT0001')['id'],'127.0.0.1','semua',60,'QA API','qa');")
    s, j, _ = api(P + '/summary', token)
    check(s == 403 and j['error']['code'] == 'ip_blocked', 'IP disekat sekolah → 403 ip_blocked (JSON)')
    run("query('DELETE FROM ip_blocks WHERE ip=\"127.0.0.1\"');")
    check(api(P + '/summary', token)[0] == 200, 'sekatan dibuang → 200')

    print('Had cubaan kunci salah')
    run("query('DELETE FROM rate_limits');")
    codes = [api(P + '/summary', 'ut_' + '1' * 64)[0] for _ in range(121)]
    check(codes[-1] == 429, 'selepas 120 kunci salah dari IP yang sama → 429')
    s, j, _ = api(P + '/summary', token)
    check(s == 429 and j['error']['code'] == 'rate_limited', 'kunci sah juga ditahan sementara (elak teka-teki)')
    run("query('DELETE FROM rate_limits');")
    check(api(P + '/summary', token)[0] == 200, 'had dibuang → 200')

    print('Kunci & status sekolah')
    run("query('UPDATE api_keys SET revoked_at=NOW() WHERE school_id=(SELECT id FROM schools WHERE code=\"UJT0001\") AND name=\"Ujian keselamatan\"');")
    check(api(P + '/summary', token)[0] == 401, 'kunci dibatalkan → 401')
    run("query('UPDATE api_keys SET revoked_at=NULL WHERE name=\"Ujian keselamatan\"'); query('UPDATE schools SET status=\"digantung\" WHERE code=\"UJT0001\"');")
    check(api(P + '/summary', token)[0] == 503, 'sekolah digantung → 503')
    run("query('UPDATE schools SET status=\"aktif\" WHERE code=\"UJT0001\"');")
    check(api(P + '/summary', token)[0] == 200, 'sekolah aktif semula → 200')
finally:
    run("pset_set(['kecemasan'=>'']); query('DELETE FROM ip_blocks WHERE ip=\"127.0.0.1\"'); query('DELETE FROM rate_limits'); query('UPDATE schools SET status=\"aktif\" WHERE code=\"UJT0001\"'); query('DELETE FROM api_keys WHERE name=\"Ujian keselamatan\"');")
print(f'\n{ok} semakan keselamatan API lulus.')
