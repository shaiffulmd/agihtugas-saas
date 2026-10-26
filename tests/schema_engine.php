<?php
// Ujian integriti skema enjin (Fasa 2) pada pemasangan sedia ada (.env). Mencipta 2 sekolah sementara (UJA9001, UJB9002),
// menguji kunci asing komposit, kekangan & school_wipe(), kemudian memadam kedua-duanya. Data sekolah lain tidak disentuh.
//   php tests/schema_engine.php
if(PHP_SAPI!=='cli') exit;
require dirname(__DIR__).'/app/bootstrap.php';
foreach(['settings','install','integrity','images','users','demo','platform'] as $f) require_once dirname(__DIR__).'/app/'.$f.'.php';
if(app_status()!=='ready') exit("Pemasangan belum sedia (jalankan php bin/upgrade.php).\n");

$ok=0; $fail=0;
function check(bool $c,string $label): void { global $ok,$fail; if($c){ $ok++; echo "  ✓ $label\n"; } else { $fail++; echo "  ✗ $label\n"; } }
/** Pernyataan mesti ditolak oleh pangkalan data (FK / unik / CHECK). */
function rejects(string $label,string $sql,array $p=[]): void {
    try { query($sql,$p); check(false,$label.' (diterima!)'); } catch(PDOException $ex) { check(true,$label.' → '.substr($ex->errorInfo[1]??$ex->getCode(),0,6)); }
}
function accepts(string $label,string $sql,array $p=[]): int {
    try { query($sql,$p); check(true,$label); return (int)db()->lastInsertId(); } catch(PDOException $ex) { check(false,$label.' — '.$ex->getMessage()); return 0; }
}

foreach(['UJA9001','UJB9002'] as $c) if($s=school_find($c)) school_delete($s); // sisa larian lalu
[$A]=school_create('UJA9001','SMK Ujian Skema A','SMK','Pentadbir A','admin@uja.test');
[$B]=school_create('UJB9002','SK Ujian Skema B','SK','Pentadbir B','admin@ujb.test');
$a=(int)$A['id']; $b=(int)$B['id'];
$admA=(int)query('SELECT id FROM users WHERE school_id=?',[$a])->fetchColumn(); $admB=(int)query('SELECT id FROM users WHERE school_id=?',[$b])->fetchColumn();

try {
echo "1. Baris tunggal setiap sekolah\n";
check((bool)query('SELECT 1 FROM workflow_state WHERE school_id=? AND status="draft" AND version=1',[$a])->fetchColumn(),'workflow_state draf dicipta bersama sekolah');
rejects('workflow_state kedua bagi sekolah sama','INSERT INTO workflow_state(school_id) VALUES (?)',[$a]);
accepts('allocation_config satu baris','INSERT INTO allocation_config(school_id,payload) VALUES (?,?)',[$a,'{"max_tasks":13}']);
rejects('allocation_config kedua bagi sekolah sama','INSERT INTO allocation_config(school_id,payload) VALUES (?,?)',[$a,'{}']);
rejects('allocation_config JSON tidak sah','INSERT INTO allocation_config(school_id,payload) VALUES (?,?)',[$b,'bukan json']);

echo "2. Guru & jawatan\n";
$tA=accepts('guru sekolah A','INSERT INTO teachers(school_id,name,capacity,external_id) VALUES (?,?,?,?)',[$a,'Guru A',25,'koku-multi:1:7']);
$tB=accepts('guru sekolah B dengan external_id SAMA (unik dalam sekolah sahaja)','INSERT INTO teachers(school_id,name,capacity,external_id) VALUES (?,?,?,?)',[$b,'Guru B',25,'koku-multi:1:7']);
rejects('external_id berganda dalam sekolah sama','INSERT INTO teachers(school_id,name,capacity,external_id) VALUES (?,?,?,?)',[$a,'Guru A2',25,'koku-multi:1:7']);
accepts('beberapa guru tanpa external_id (NULL)','INSERT INTO teachers(school_id,name,capacity) VALUES (?,?,20),(?,?,20)',[$a,'Guru A3',$a,'Guru A4']);
rejects('kapasiti 0','INSERT INTO teachers(school_id,name,capacity) VALUES (?,?,0)',[$a,'X']);
rejects('kapasiti 101','INSERT INTO teachers(school_id,name,capacity) VALUES (?,?,101)',[$a,'X']);
rejects('guru tanpa school_id','INSERT INTO teachers(name,capacity) VALUES (?,10)',['X']);
$pA=accepts('jawatan sekolah A','INSERT INTO positions(school_id,name,category,weight) VALUES (?,?,?,?)',[$a,'Setiausaha Peperiksaan','Kurikulum',9]);
$pA2=accepts('jawatan kedua sekolah A','INSERT INTO positions(school_id,name,category,weight) VALUES (?,?,?,?)',[$a,'Penyelaras Disiplin','HEM',9]);
$pB=accepts('jawatan sekolah B','INSERT INTO positions(school_id,name,category,weight) VALUES (?,?,?,?)',[$b,'Setiausaha Sukan','Kokurikulum',9]);
rejects('bidang tidak sah','INSERT INTO positions(school_id,name,category,weight) VALUES (?,?,?,?)',[$a,'X','Sukan',5]);
rejects('pemberat 11','INSERT INTO positions(school_id,name,category,weight) VALUES (?,?,?,?)',[$a,'X','HEM',11]);
rejects('pemberat 0','INSERT INTO positions(school_id,name,category,weight) VALUES (?,?,?,?)',[$a,'X','HEM',0]);

echo "3. Lantikan & syarat — silang sekolah ditolak\n";
accepts('lantik guru A ke jawatan A','INSERT INTO assignments(school_id,teacher_id,position_id) VALUES (?,?,?)',[$a,$tA,$pA]);
rejects('jawatan sama dilantik dua kali','INSERT INTO assignments(school_id,teacher_id,position_id) VALUES (?,?,?)',[$a,$tA,$pA]);
rejects('guru sekolah B ke jawatan sekolah A','INSERT INTO assignments(school_id,teacher_id,position_id) VALUES (?,?,?)',[$a,$tB,$pA2]);
rejects('baris sekolah B merujuk jawatan sekolah A','INSERT INTO assignments(school_id,teacher_id,position_id) VALUES (?,?,?)',[$b,$tB,$pA2]);
rejects('pindah lantikan ke sekolah lain (UPDATE school_id)','UPDATE assignments SET school_id=? WHERE school_id=? AND position_id=?',[$b,$a,$pA]);
rejects('padam guru yang dilantik (RESTRICT)','DELETE FROM teachers WHERE school_id=? AND id=?',[$a,$tA]);
rejects('padam jawatan yang dilantik (RESTRICT)','DELETE FROM positions WHERE school_id=? AND id=?',[$a,$pA]);
accepts('syarat jawatan A','INSERT INTO position_rules(school_id,position_id,payload) VALUES (?,?,?)',[$a,$pA2,'{"fixed_teacher_id":null,"allowed_teachers":[],"required_specialty":""}']);
rejects('syarat jawatan sekolah B dalam sekolah A','INSERT INTO position_rules(school_id,position_id,payload) VALUES (?,?,?)',[$a,$pB,'{}']);
accepts('padam jawatan tanpa lantikan → syarat ikut dipadam (CASCADE)','DELETE FROM positions WHERE school_id=? AND id=?',[$a,$pA2]);
check(!query('SELECT 1 FROM position_rules WHERE school_id=? AND position_id=?',[$a,$pA2])->fetchColumn(),'syarat jawatan dipadam bersama jawatan');

echo "4. Akaun ↔ profil guru\n";
accepts('paut akaun A kepada guru A','UPDATE users SET teacher_id=? WHERE school_id=? AND id=?',[$tA,$a,$admA]);
rejects('paut akaun B kepada guru sekolah A','UPDATE users SET teacher_id=? WHERE school_id=? AND id=?',[$tA,$b,$admB]);
$u2=accepts('pengguna kedua sekolah A','INSERT INTO users(school_id,name,email,password_hash,role) VALUES (?,?,?,?,"teacher")',[$a,'Guru Dua','guru2@uja.test','x']);
rejects('dua akaun dipaut kepada guru yang sama','UPDATE users SET teacher_id=? WHERE school_id=? AND id=?',[$tA,$a,$u2]);
rejects('peranan koku lama "guru"','INSERT INTO users(school_id,name,email,password_hash,role) VALUES (?,?,?,?,"guru")',[$a,'X','x@uja.test','x']);

echo "5. Aliran kelulusan\n";
$rA=accepts('revisi sekolah A','INSERT INTO workflow_revisions(school_id,payload,source_hash,submitted_by) VALUES (?,?,?,?)',[$a,'{"teachers":[]}',str_repeat('a',64),$admA]);
rejects('revisi dihantar oleh pengguna sekolah lain','INSERT INTO workflow_revisions(school_id,payload,source_hash,submitted_by) VALUES (?,?,?,?)',[$a,'{}',str_repeat('b',64),$admB]);
rejects('revisi diluluskan oleh pengguna sekolah lain','UPDATE workflow_revisions SET approved_by=? WHERE school_id=? AND id=?',[$admB,$a,$rA]);
rejects('status revisi tidak sah','UPDATE workflow_revisions SET status="draft" WHERE school_id=? AND id=?',[$a,$rA]);
accepts('keadaan A menunjuk revisi A','UPDATE workflow_state SET status="submitted",revision_id=?,version=version+1 WHERE school_id=? AND version=1',[$rA,$a]);
rejects('keadaan B menunjuk revisi sekolah A','UPDATE workflow_state SET revision_id=? WHERE school_id=?',[$rA,$b]);
check(query('UPDATE workflow_state SET status="draft",version=version+1 WHERE school_id=? AND version=1',[$a])->rowCount()===0,'kunci optimistik: version lama tidak mengemas kini');

echo "6. Aset, kunci API, pratonton\n";
$h=hash('sha256','logo');
accepts('aset sekolah A','INSERT INTO school_assets(school_id,id,mime,width,height,bytes) VALUES (?,?,"image/png",1,1,?)',[$a,$h,'x']);
accepts('aset hash sama di sekolah B (tidak dikongsi)','INSERT INTO school_assets(school_id,id,mime,width,height,bytes) VALUES (?,?,"image/png",1,1,?)',[$b,$h,'y']);
$tok=hash('sha256','tok');
accepts('kunci API sekolah A','INSERT INTO api_keys(school_id,name,token_hash,prefix,scope) VALUES (?,?,?,?,"read")',[$a,'koku',$tok,'ut_abc']);
rejects('token_hash sama di sekolah B (unik global)','INSERT INTO api_keys(school_id,name,token_hash,prefix,scope) VALUES (?,?,?,?,"read")',[$b,'koku',$tok,'ut_abc']);
rejects('skop API tidak sah','INSERT INTO api_keys(school_id,name,token_hash,prefix,scope) VALUES (?,?,?,?,"admin")',[$a,'x',hash('sha256','z'),'ut_z']);
accepts('pratonton auto-agihan','INSERT INTO allocation_plans(id,school_id,snapshot,payload,created_by) VALUES (?,?,?,?,?)',[bin2hex(random_bytes(24)),$a,str_repeat('c',64),'[]',$admA]);

echo "7. Had Free & school_wipe()\n";
check(free_count('guru',$a)===3 && free_count('jawatan',$a)===1 && free_count('guru',$b)===1,'free_count mengira guru/jawatan setiap sekolah');
$countB=fn()=>array_sum(array_map(fn($t)=>(int)query('SELECT COUNT(*) FROM `'.$t.'` WHERE school_id=?',[$b])->fetchColumn(),SCHOOL_TABLES));
$beforeB=$countB();
with_school($A,'school_wipe');
$leftA=array_filter(SCHOOL_TABLES,fn($t)=>(int)query('SELECT COUNT(*) FROM `'.$t.'` WHERE school_id=?',[$a])->fetchColumn()>0);
check(!$leftA,'school_wipe memadam semua baris sekolah A'.($leftA?' (tinggal: '.implode(',',$leftA).')':''));
check($countB()===$beforeB,'data sekolah B tidak terjejas ('.$beforeB.' baris)');
with_school($A,'school_engine_init');
check((bool)query('SELECT 1 FROM workflow_state WHERE school_id=?',[$a])->fetchColumn(),'school_engine_init mencipta semula keadaan draf');
$miss=array_values(array_diff(ENGINE_TABLES,SCHOOL_TABLES));
check(!$miss,'semua jadual enjin dalam SCHOOL_TABLES'.($miss?' (tiada: '.implode(',',$miss).')':''));
} finally {
    foreach(['UJA9001','UJB9002'] as $c) if($s=school_find($c)) school_delete($s);
}
check(!school_find('UJB9002'),'school_delete memadam sekolah berdata enjin');

echo "\n$ok lulus, $fail gagal\n";
exit($fail?1:0);
