"""Audit statik: cari pernyataan SQL yang menyentuh jadual sekolah tanpa sebutan school_id pada baris yang sama.
   python3 tests/tenant_sql_audit.py        → senarai calon untuk semakan manual (kod keluar 1 jika ada calon baharu)
Setiap query baharu pada jadual sekolah MESTI menapis school_id (lihat docs/NOTA_KOD.md & handover koku-multi §3).
Calon yang sudah disemak dan selamat disenaraikan dalam REVIEWED (tapisan datang daripada pemboleh ubah / subkueri berkorelasi).
"""
import re,sys
from pathlib import Path
ROOT=Path(__file__).resolve().parents[1]
# Jadual data sekolah (school_id NOT NULL) — selaras dengan SCHOOL_TABLES dalam app/tenant.php.
TABLES='teachers|subjects|positions|assignments|position_rules|allocation_config|allocation_plans|workflow_revisions|workflow_state|school_assets|api_keys|users|audit_logs|recovery_codes|settings|user_avatars|auth_tokens|staff_notices|staff_notice_targets|staff_notice_reads|support_tickets|support_messages'
SQL=re.compile(r"(SELECT|INSERT|UPDATE|DELETE)\b[^;]*?\b(FROM|INTO|UPDATE|JOIN)\s+`?("+TABLES+r")\b",re.I)
# (fail, petikan unik) yang telah disemak: tapisan school_id ditambah melalui $where/$w/$from atau subkueri berkorelasi pada baris induk yang ditapis.
REVIEWED=[
 ('app/admin_views.php',"FROM audit_logs a'.$w"),('app/admin_views.php',"LEFT JOIN users u ON u.id=a.user_id'.$w"),
 ('app/hebahan.php',"WHERE '.hebahan_visible_sql()"),('app/hebahan.php',"n.id=? AND '.hebahan_visible_sql()"),('app/hebahan.php',"LEFT JOIN staff_notice_reads r ON r.notice_id=n.id AND r.user_id=?"),
 ('app/bootstrap.php',"INSERT INTO audit_logs"),('app/bootstrap.php',"FROM rate_limits"),('app/install.php',"information_schema"),
 ('app/tenant.php',"DELETE FROM `'.$t.'` WHERE school_id"),('app/backup.php',""),('app/integrity.php',"health_log"),('app/admin_views.php',"health_log"),
 ('public_html/install.php',"information_schema"),
('bin/user.php',"'.$table.'"),('app/support.php',"function support_unread_platform"),  # lencana platform: semua sekolah
    ('app/support.php',"DELETE FROM support_messages WHERE ticket_id=?',[$t['id']]"),  # platform padam satu tiket (tiket dicari dahulu tanpa tapisan sekolah: panel platform)
    ('app/subscription.php',"DELETE FROM allocation_plans WHERE created_at < NOW() - INTERVAL 1 DAY"),  # kerja harian platform: pembersihan pratonton lama semua sekolah
    ('bin/import-agihtugas.php',"$src->query('SELECT * FROM workflow_state WHERE id=1')"),  # SQLite sumber satu sekolah (dibaca sahaja), bukan MariaDB
    ('app/api.php',"FROM api_keys WHERE token_hash=? AND revoked_at IS NULL"),  # kunci menentukan sekolah (hash unik global), kemudian school_id kunci MESTI = sid() URL
    ('app/support.php',"SELECT t.id FROM support_tickets t WHERE COALESCE"),('app/support.php',"DELETE FROM support_messages WHERE ticket_id IN"),  # simpanan tiket: pembersihan merentas sekolah oleh platform/kerja harian (ID integer)

]
found=[]
for f in sorted([*ROOT.glob('app/*.php'),*ROOT.glob('public_html/*.php'),*ROOT.glob('bin/*.php')]):
    rel=f.relative_to(ROOT).as_posix()
    for n,line in enumerate(f.read_text(encoding='utf-8').splitlines(),1):
        if not SQL.search(line) or 'school_id' in line: continue
        if any(rel==rf and snip in line for rf,snip in REVIEWED): continue
        found.append(f'{rel}:{n}: {line.strip()[:160]}')
for x in found: print(x)
print(f'{len(found)} calon tanpa school_id' + (' — semak setiap satu' if found else ' — bersih'))
sys.exit(1 if found else 0)
