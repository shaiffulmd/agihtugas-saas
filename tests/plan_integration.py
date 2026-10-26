#!/usr/bin/env python3
"""Ujian pakej Free/Pro (Fasa 4): ciri Pro dikunci, had Free, API, tetapan platform.

Pemasangan UJIAN sahaja (mengubah tetapan platform, kemudian dipulihkan dengan `plan_helper.php reset`):
  php tests/setup_engine_test.php > ujian.json
  TEST_URL=http://127.0.0.1:8091 SETUP_JSON=ujian.json python3 tests/plan_integration.py
"""
import json, os, re, subprocess, sys

sys.path.insert(0, os.path.dirname(__file__))
os.environ.setdefault('SETUP_JSON', 'ujian.json')
import engine_integration as ei  # Client, api(), err(), flash(), check()

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))


def helper(*args):
    return subprocess.run(['php', os.path.join(ROOT, 'tests', 'plan_helper.php'), *map(str, args)], capture_output=True, text=True, check=True).stdout.strip()


def main():
    check = ei.check
    P, F = 'UJT0001', 'UJT0002'
    helper('reset')
    try:
        print('1. Kunci API dicipta semasa Pro')
        admF = ei.Client(F); admF.login('admin')
        s, p, _ = admF.post('api', {'action': 'api_cipta', 'name': 'Ujian', 'scope': 'write'})
        tokF = re.search(r'(ut_[0-9a-f]{64})', p).group(1)
        st, j = ei.api(F, 'GET', '/summary', tokF)
        check(st == 200, 'API berfungsi semasa Pro (percubaan)')

        print('2. Sekolah jatuh ke Free — ciri Pro dikunci')
        helper('free', F)
        s, p, _ = admF.req('/?page=dashboard')
        check('Pakej Free:' in p, 'jalur pakej Free dengan kiraan had')
        nav = re.findall(r'class="nav-item[^"]*"[^>]*>.*?<span>([^<]+)</span>', p)
        check('Agihan Automatik · Pro' in nav and 'Pusat Syarat · Pro' in nav and 'API &amp; Integrasi · Pro' in nav, 'menu menandakan ciri Pro')
        for pg in ['auto', 'syarat', 'api']:
            s, p, _ = admF.req('/?page=' + pg)
            check(s == 200 and 'pro-locked' in p and 'Naik taraf ke Pro' in p, f'?page={pg} → panel naik taraf')
        s, p, _ = admF.post('auto', {'action': 'auto_preview', 'mode': 'vacant'})
        check('pakej Pro' in ei.err(p), 'POST auto_preview ditolak')
        s, p, _ = admF.post('syarat', {'action': 'syarat_umum', 'max_tasks': 3, 'max_heavy': 1, 'heavy_threshold': 8, 'category_0': 4, 'category_1': 4, 'category_2': 4, 'category_3': 4})
        check('pakej Pro' in ei.err(p), 'POST syarat_umum ditolak')
        s, p, _ = admF.post('api', {'action': 'api_cipta', 'name': 'X', 'scope': 'read'})
        check('pakej Pro' in ei.err(p), 'cipta kunci API ditolak')
        st, j = ei.api(F, 'GET', '/summary', tokF)
        check(st == 403 and j['error']['code'] == 'pro_required', 'API → 403 pro_required')
        s, p, _ = admF.req('/?page=guru')
        check('page=guru&amp;pukal=1' in p, 'pukal guru dibuka (bukan ciri Pro lalai)')
        s, p, _ = admF.req('/?page=langganan')
        check('Ciri Pro' in p and p.count('Dikunci') == 4, 'Langganan menyenaraikan 4 ciri dikunci')

        print('3. Surat & sejarah versi (Free)')
        pk = ei.Client(F); pk.login('pk')
        pk.post('guru', {'action': 'guru_save', 'id': 0, 'name': 'Guru Free', 'email': '', 'specialty': '', 'capacity': 20})
        s, p, _ = pk.req('/?page=jawatan')
        pk.post('jawatan', {'action': 'jawatan_save', 'id': 0, 'name': 'Jawatan Free', 'category': 'HEM', 'weight': 5})
        s, p, _ = pk.req('/?page=agihan')
        pid = re.search(r'<option value="(\d+)" data-weight', p).group(1)
        pk.post('agihan', {'action': 'agih', 'position_id': pid, 'teacher_id': ei.CFG[F]['teacher_id']})
        s, p, _ = pk.req('/?page=kelulusan')
        v = re.search(r'value="submit"><input type="hidden" name="version" value="(\d+)"', p).group(1)
        pk.post('kelulusan', {'action': 'kelulusan', 'transition': 'submit', 'version': v})
        head = ei.Client(F); head.login('head')
        for t in ['approve', 'finalize']:
            s, p, _ = head.req('/?page=kelulusan')
            v = re.search(r'value="%s"><input type="hidden" name="version" value="(\d+)"' % t, p).group(1)
            s, p, _ = head.post('kelulusan', {'action': 'kelulusan', 'transition': t, 'version': v})
        check('Muktamad' in p, 'aliran kelulusan berfungsi dalam Free')
        check('Sejarah versi semakan</h3>' not in p and 'tersedia untuk pakej Pro' in p, 'sejarah versi disembunyikan')
        tid = ei.CFG[F]['teacher_id']
        s, p, _ = head.req('/?page=surat&guru=%d' % tid)
        check('<article class="letter">' in p and 'Surat pukal &amp; PDF · Pro' in p, 'surat individu boleh dicetak; PDF/pukal bertanda Pro')
        s, p, _ = head.req('/?page=surat&semua=1')
        check('pro-locked' in p and '<article class="letter">' not in p, 'semua surat dikunci')
        s, _, _ = head.req('/?page=surat&pdf=1')
        check(s == 403, 'PDF surat → 403')
        tch = ei.Client(F); tch.login('teacher')
        s, p, _ = tch.req('/?page=tugas_saya')
        check('<article class="letter">' in p and 'tugas_saya&amp;pdf=1' not in p, 'Tugas Saya: cetak ada, PDF disembunyikan')

        print('4. Had pakej Free')
        s, p, _ = admF.req('/?page=kelulusan')
        v = re.search(r'value="return"><input type="hidden" name="version" value="(\d+)"', p).group(1)
        admF.post('kelulusan', {'action': 'kelulusan', 'transition': 'return', 'version': v, 'reason': 'Ujian had'})
        n = json.loads(helper('count', F))
        helper('limit', 'guru', n['guru'] + 1)
        s, p, _ = pk.post('guru', {'action': 'guru_save', 'id': 0, 'name': 'Dalam Had', 'email': '', 'specialty': '', 'capacity': 20})
        check('Maklumat guru disimpan' in ei.flash(p), 'tambah guru dalam had')
        s, p, _ = pk.post('guru', {'action': 'guru_save', 'id': 0, 'name': 'Lebih Had', 'email': '', 'specialty': '', 'capacity': 20})
        check('Had pakej Free' in ei.err(p), 'guru melebihi had ditolak')
        s, p, _ = pk.post('guru', {'action': 'guru_bulk_preview', 'bulk_text': 'Pukal A,,,20\nPukal B,,,20'})
        tok = re.search(r'name="token" value="([0-9a-f]+)"', p)
        s, p, _ = pk.post('guru', {'action': 'guru_bulk_apply', 'token': tok.group(1) if tok else ''})
        check('Had pakej Free' in ei.err(p), 'guru pukal melebihi had ditolak')
        helper('limit', 'jawatan', n['jawatan'] + 1)
        s, p, _ = pk.post('jawatan', {'action': 'jawatan_save', 'id': 0, 'name': 'Jawatan Had', 'category': 'HEM', 'weight': 2})
        check('Maklumat' in ei.flash(p) or 'Jawatan dan pemberat disimpan' in ei.flash(p), 'tambah jawatan dalam had')
        s, p, _ = pk.post('jawatan', {'action': 'jawatan_save', 'id': 0, 'name': 'Jawatan Lebih', 'category': 'HEM', 'weight': 2})
        check('Had pakej Free' in ei.err(p), 'jawatan melebihi had ditolak')
        st, j = ei.api(F, 'POST', '/positions', tokF, {'name': 'Jawatan API', 'category': 'HEM', 'weight': 2})
        check(st == 403, 'API (Free) ditolak sebelum had')
        helper('limit', 'pengguna', n['pengguna'])
        s, p, _ = admF.req('/?page=users')
        s, p, _ = admF.post('users', {'action': 'user_create', 'name': 'Pengguna Lebih', 'email': 'lebih@ujt0002.test', 'password': 'Sementara-Ujian-2026', 'role': 'pk'})
        check('Had pakej Free' in ei.err(p), 'pengguna melebihi had ditolak')

        print('5. Tetapan platform: ciri Pro boleh dipilih')
        helper('features', 'api')
        s, p, _ = admF.req('/?page=auto')
        check('pro-locked' not in p and 'Jana pratonton' in p, 'auto-agihan dibuka apabila tidak lagi ciri Pro')
        st, j = ei.api(F, 'GET', '/summary', tokF)
        check(st == 403, 'API masih Pro sahaja')
        helper('features', 'auto,surat_pukal,sejarah')
        st, j = ei.api(F, 'GET', '/summary', tokF)
        check(st == 200, 'API dibuka untuk Free apabila dinyahpilih')
        st, j = ei.api(F, 'GET', '/allocation/rules', tokF)
        check(st == 403 and j['error']['code'] == 'pro_required', 'endpoint auto-agihan API kekal Pro')
        helper('features', '')

        print('6. Naik taraf semula ke Pro')
        helper('reset'); helper('pro', F)
        s, p, _ = admF.req('/?page=auto')
        check('pro-locked' not in p, 'auto-agihan dibuka semula')
        st, j = ei.api(F, 'GET', '/summary', tokF)
        check(st == 200, 'kunci API lama sah semula')
        s, p, _ = head.req('/?page=surat&semua=1')
        check('<article class="letter">' in p, 'surat pukal dibuka semula')
        admP = ei.Client(P); admP.login('admin')
        s, p, _ = admP.req('/?page=dashboard')
        check('Pakej Free' not in p and '· Pro' not in p, 'sekolah Pro tidak terjejas')
    finally:
        helper('reset'); helper('pro', F)
    print('\n%d lulus, %d gagal' % (ei.ok, ei.fail))
    sys.exit(1 if ei.fail else 0)


if __name__ == '__main__':
    main()
