<?php
declare(strict_types=1);
date_default_timezone_set('Asia/Kuala_Lumpur');
require_once __DIR__.'/config.php';
$db = new PDO('sqlite:'.app_database_path());
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$db->exec('PRAGMA foreign_keys=ON; PRAGMA busy_timeout=5000;');
$db->exec('CREATE TABLE IF NOT EXISTS settings (key TEXT PRIMARY KEY,value TEXT NOT NULL);
CREATE TABLE IF NOT EXISTS teachers (id INTEGER PRIMARY KEY,name TEXT NOT NULL,email TEXT NOT NULL DEFAULT "",specialty TEXT NOT NULL DEFAULT "",capacity INTEGER NOT NULL CHECK(capacity BETWEEN 1 AND 100));
CREATE TABLE IF NOT EXISTS positions (id INTEGER PRIMARY KEY,name TEXT NOT NULL,category TEXT NOT NULL,weight INTEGER NOT NULL CHECK(weight BETWEEN 1 AND 10));
CREATE TABLE IF NOT EXISTS assignments (id INTEGER PRIMARY KEY,teacher_id INTEGER NOT NULL REFERENCES teachers(id) ON DELETE RESTRICT,position_id INTEGER NOT NULL UNIQUE REFERENCES positions(id) ON DELETE RESTRICT);
CREATE TABLE IF NOT EXISTS history (id INTEGER PRIMARY KEY,description TEXT NOT NULL,created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP);');
function q(string $sql, array $params=[]): PDOStatement { global $db; $s=$db->prepare($sql); $s->execute($params); return $s; }
const CATEGORIES=['Pentadbiran','Kurikulum','HEM','Kokurikulum'];
if (!q("SELECT 1 FROM settings WHERE key='school'")->fetchColumn()) {
 $db->beginTransaction();
 foreach(['school'=>app_seed_demo()?'Sekolah Kebangsaan Contoh':'Sekolah Saya','principal'=>app_seed_demo()?'Nama Guru Besar':'','year'=>app_seed_demo()?'2027':date('Y'),'reference'=>app_seed_demo()?'SKC.100-1/5/1':'SEK.100-1/5/1','demo'=>app_seed_demo()?'1':'0'] as $k=>$v) q('INSERT INTO settings VALUES (?,?)',[$k,$v]);
 if(app_seed_demo()) {
 $names=[['Nur Aisyah Abdullah','Bahasa Melayu',25],['Muhammad Hafiz Ahmad','Matematik',25],['Siti Nurhaliza Hassan','Sains',20],['Ahmad Firdaus Ismail','Pendidikan Jasmani',25],['Nurul Huda Osman','Bahasa Inggeris',25],['Chong Wei Ling','Matematik',20],['Ravindran Kumar','Reka Bentuk & Teknologi',25],['Farah Nadia Zainal','Pendidikan Islam',20],['Mohd Faizal Yusof','Sejarah',25],['Siti Aminah Ali','Pendidikan Seni',25],['Lee Mei Yee','Bahasa Inggeris',20],['Nor Azlina Rahman','Pemulihan',20]];
 foreach($names as $i=>$n) q('INSERT INTO teachers(name,email,specialty,capacity) VALUES (?,?,?,?)',[$n[0],'guru'.($i+1).'@sekolah.example',$n[1],$n[2]]);
 $roles=[['Setiausaha Pengurusan','Pentadbiran',8],['Penyelaras Aset','Pentadbiran',6],['Setiausaha Mesyuarat Guru','Pentadbiran',5],['Penyelaras Data Sekolah','Pentadbiran',8],['Penyelaras Dokumentasi','Pentadbiran',4],['AJK Pengurusan Pejabat','Pentadbiran',3],['Ketua Panitia Bahasa Melayu','Kurikulum',8],['Ketua Panitia Matematik','Kurikulum',8],['Ketua Panitia Sains','Kurikulum',8],['Setiausaha Peperiksaan','Kurikulum',9],['Penyelaras Pusat Sumber','Kurikulum',7],['Penyelaras Pemulihan','Kurikulum',7],['Setiausaha HEM','HEM',9],['Penyelaras Disiplin','HEM',9],['Penyelaras Bantuan Murid','HEM',7],['Penyelaras Kantin','HEM',5],['Penyelaras Kesihatan','HEM',5],['Penyelaras Keselamatan','HEM',6],['Setiausaha Kokurikulum','Kokurikulum',9],['Setiausaha Sukan','Kokurikulum',9],['Penyelaras Unit Beruniform','Kokurikulum',7],['Penyelaras Kelab & Persatuan','Kokurikulum',6],['Penyelaras Sukan & Permainan','Kokurikulum',7],['Penyelaras PAJSK','Kokurikulum',8]];
 foreach($roles as $r) q('INSERT INTO positions(name,category,weight) VALUES (?,?,?)',$r);
 foreach([1=>1,2=>1,3=>2,4=>1,5=>5,6=>6,7=>1,8=>2,9=>3,10=>5,11=>10,12=>12,13=>8,14=>9,15=>6,16=>7,17=>10,18=>11,19=>4,20=>4] as $p=>$t) q('INSERT INTO assignments(teacher_id,position_id) VALUES (?,?)',[$t,$p]);
 q('INSERT INTO history(description) VALUES (?)',['Ruang kerja dimulakan dengan data contoh.']);
 } else {
 q('INSERT INTO history(description) VALUES (?)',['Ruang kerja kosong disediakan oleh pemasang.']);
 }
 $db->commit();
}

// Additive migration: existing school data is preserved.
$db->exec('CREATE TABLE IF NOT EXISTS api_keys (
 id INTEGER PRIMARY KEY, name TEXT NOT NULL, token_hash TEXT NOT NULL UNIQUE,
 prefix TEXT NOT NULL, scope TEXT NOT NULL CHECK(scope IN ("read","write")),
 created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP, last_used_at TEXT, revoked_at TEXT
);');

if(!in_array('external_id',q('PRAGMA table_info(teachers)')->fetchAll(PDO::FETCH_COLUMN,1),true)) {
 $db->exec('ALTER TABLE teachers ADD COLUMN external_id TEXT');
}
$db->exec('CREATE UNIQUE INDEX IF NOT EXISTS teachers_external_id ON teachers(external_id) WHERE external_id IS NOT NULL');

$db->exec('CREATE TABLE IF NOT EXISTS allocation_config (id INTEGER PRIMARY KEY CHECK(id=1), payload TEXT NOT NULL);
CREATE TABLE IF NOT EXISTS position_rules (position_id INTEGER PRIMARY KEY REFERENCES positions(id) ON DELETE CASCADE, payload TEXT NOT NULL);
CREATE TABLE IF NOT EXISTS allocation_plans (id TEXT PRIMARY KEY, snapshot TEXT NOT NULL, payload TEXT NOT NULL, created_at INTEGER NOT NULL, applied_at INTEGER);');

$db->exec('CREATE TABLE IF NOT EXISTS accounts (
 id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL, email TEXT NOT NULL COLLATE NOCASE UNIQUE,
 password_hash TEXT NOT NULL, role TEXT NOT NULL CHECK(role IN ("admin","pk","head","teacher")),
 teacher_id INTEGER UNIQUE REFERENCES teachers(id) ON DELETE RESTRICT, active INTEGER NOT NULL DEFAULT 1,
 auth_version INTEGER NOT NULL DEFAULT 1, created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE TABLE IF NOT EXISTS login_attempts (bucket TEXT PRIMARY KEY, attempts INTEGER NOT NULL, window_start INTEGER NOT NULL);
CREATE TABLE IF NOT EXISTS setup_codes (id INTEGER PRIMARY KEY CHECK(id=1), code_hash TEXT NOT NULL, expires_at INTEGER NOT NULL);
CREATE TABLE IF NOT EXISTS workflow_revisions (
 id INTEGER PRIMARY KEY AUTOINCREMENT, status TEXT NOT NULL, payload TEXT NOT NULL, source_hash TEXT NOT NULL,
 submitted_by INTEGER NOT NULL REFERENCES accounts(id), submitted_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
 approved_by INTEGER REFERENCES accounts(id), approved_at TEXT, finalized_by INTEGER REFERENCES accounts(id), finalized_at TEXT,
 returned_by INTEGER REFERENCES accounts(id), returned_at TEXT, return_reason TEXT
);
CREATE TABLE IF NOT EXISTS workflow_state (id INTEGER PRIMARY KEY CHECK(id=1), status TEXT NOT NULL DEFAULT "draft", revision_id INTEGER REFERENCES workflow_revisions(id), version INTEGER NOT NULL DEFAULT 1);
INSERT OR IGNORE INTO workflow_state(id,status) VALUES (1,"draft");');

// Existing installations retain SK behavior until their school type is selected.
q('INSERT OR IGNORE INTO settings(key,value) VALUES ("school_type","SK")');
require_once __DIR__.'/school.php';

$db->exec('CREATE TABLE IF NOT EXISTS school_assets (id TEXT PRIMARY KEY,mime TEXT NOT NULL,width INTEGER NOT NULL,height INTEGER NOT NULL,bytes BLOB NOT NULL)');
require_once __DIR__.'/branding.php';
branding_install();
