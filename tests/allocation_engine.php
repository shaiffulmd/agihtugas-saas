<?php
// Ujian enjin auto-agihan & jawatan pukal (dipindah daripada legacy/tests/allocation.php) pada pemasangan sedia ada (.env).
// Berjalan dalam sekolah sementara UJA9101 (dipadam selepas ujian), tambah semakan pengasingan dengan sekolah UJB9102.
//   php tests/allocation_engine.php
declare(strict_types=1);
if(PHP_SAPI!=='cli') exit;
require dirname(__DIR__).'/app/bootstrap.php';
foreach(['settings','install','integrity','images','users','demo','platform','enjin','tugas_auto','kelulusan','surat_lantikan'] as $f) require_once dirname(__DIR__).'/app/'.$f.'.php';
if(app_status()!=='ready') exit("Pemasangan belum sedia.\n");
$checks=0;
function check(bool $c,string $label): void { global $checks; if(!$c) throw new RuntimeException('GAGAL: '.$label); $checks++; echo "  ✓ $label\n"; }
function rejects(callable $fn,string $label,int $status=422): void { try { $fn(); } catch(AllocationError $e){ check($e->status===$status,$label); return; } throw new RuntimeException('Sepatutnya ditolak: '.$label); }
/** Kosongkan data enjin sekolah semasa dan isi guru/jawatan. Pulangkan [ID guru…], [ID jawatan…] mengikut susunan. */
function fixture(array $teachers,array $positions): array {
    foreach(['allocation_plans','position_rules','allocation_config','assignments','positions','teachers'] as $t) query("DELETE FROM $t WHERE school_id=".sid());
    $T=[]; foreach($teachers as $t){ query('INSERT INTO teachers(school_id,name,specialty,capacity) VALUES ('.sid().',?,?,?)',$t); $T[]=(int)db()->lastInsertId(); }
    $P=[]; foreach($positions as $p){ query('INSERT INTO positions(school_id,name,category,weight) VALUES ('.sid().',?,?,?)',$p); $P[]=(int)db()->lastInsertId(); }
    return [$T,$P];
}
function apply_test(string $id): array { db()->beginTransaction(); try { $r=allocation_apply($id); db()->commit(); return $r; } catch(Throwable $e){ db()->rollBack(); throw $e; } }
function assigned(): int { return (int)query('SELECT COUNT(*) FROM assignments WHERE school_id='.sid())->fetchColumn(); }

foreach(['UJA9101','UJB9102'] as $c) if($s=school_find($c)) school_delete($s);
[$A]=school_create('UJA9101','SMK Ujian Agihan','SMK','Pentadbir','admin@uja9101.test');
[$B]=school_create('UJB9102','SK Ujian Agihan','SK','Pentadbir','admin@ujb9102.test');
try {
with_school($A,function(){
    [$T,$P]=fixture([['A','Math',10],['B','Science',10]],[['Heavy A','HEM',9],['Heavy B','HEM',9],['Light A','HEM',1],['Light B','HEM',1]]);
    $p=allocation_preview('rebalance'); check($p['summary']['assigned']===4 && array_column($p['teachers'],'load')===[10,10],'mengimbang nisbah dalam kapasiti');
    check(assigned()===0,'pratonton tidak mengubah lantikan');
    check($p['rows']===allocation_preview('rebalance')['rows'],'pratonton deterministik');
    $r=apply_test($p['plan_id']); check($r['changed']===4 && assigned()===4,'apply menulis pratonton tepat');
    rejects(fn()=>apply_test($p['plan_id']),'pratonton sekali guna',409);

    [$T,$P]=fixture([['A','Matematik',10],['B','Sains',10]],[['General','Kurikulum',5],['Math','Kurikulum',6]]);
    allocation_save_position_rule($P[1],['required_specialty'=>'matematik']); $p=allocation_preview('rebalance');
    check($p['summary']['assigned']===2 && $p['rows'][1]['teacher_id']===$T[0] && $p['rows'][0]['teacher_id']===$T[1],'kepakaran terhad didahulukan');
    allocation_save_rules(['excluded_teachers'=>[$T[0]]]); $p=allocation_preview('rebalance'); check($p['summary']['unfilled']===1 && $p['rows'][1]['teacher_id']===null,'pengecualian & kepakaran ialah syarat wajib');
    rejects(fn()=>allocation_save_rules(['excluded_teachers'=>[999999999]]),'ID dikecualikan tidak sah');
    rejects(fn()=>allocation_save_rules(['respect_capacity'=>'yes']),'boolean disahkan');
    rejects(fn()=>allocation_save_rules(['category_limits'=>['HEM'=>2]]),'semua bidang diperlukan');
    rejects(fn()=>allocation_save_position_rule($P[0],['allowed_teachers'=>[$T[0]],'fixed_teacher_id'=>$T[1]]),'penyandang tetap mesti dalam senarai layak');

    [$T,$P]=fixture([['A','',5]],[['Too heavy','HEM',8]]); allocation_save_position_rule($P[0],['fixed_teacher_id'=>$T[0]]);
    $p=allocation_preview('rebalance'); check($p['summary']['unfilled']===1 && str_contains($p['unfilled'][0]['reason'],'kapasiti'),'penyandang tetap tidak memintas kapasiti');
    allocation_save_rules(['respect_capacity'=>false]); $p=allocation_preview('rebalance'); check($p['summary']['assigned']===1 && $p['teachers'][0]['load']===8,'suis kapasiti membenarkan melebihi had');
    allocation_save_rules(['excluded_teachers'=>[$T[0]]]); $p=allocation_preview('rebalance'); check($p['summary']['assigned']===0,'penyandang tetap tidak memintas pengecualian');

    [$T,$P]=fixture([['A','',100]],[['Heavy1','HEM',8],['Heavy2','HEM',9],['Light','HEM',2]]); allocation_save_rules(['max_heavy'=>1]);
    $p=allocation_preview('rebalance'); check($p['summary']['assigned']===2 && $p['teachers'][0]['heavy']===1,'had tugas berat dikuatkuasa');
    allocation_save_rules(['max_tasks'=>1]); $p=allocation_preview('rebalance'); check($p['summary']['assigned']===1,'had bilangan tugas dikuatkuasa');
    allocation_save_rules(['category_limits'=>array_fill_keys(CATEGORIES,0)]); $p=allocation_preview('rebalance'); check($p['summary']['assigned']===0,'had bidang 0 menghalang lantikan baharu');

    [$T,$P]=fixture([['A','',5],['B','',20]],[['Existing','HEM',9],['New','HEM',4]]); query('INSERT INTO assignments(school_id,teacher_id,position_id) VALUES ('.sid().',?,?)',[$T[0],$P[0]]);
    $p=allocation_preview('vacant'); check($p['rows'][0]['teacher_id']===$T[0] && $p['rows'][1]['teacher_id']===$T[1] && count($p['warnings'])===1,'mod kosong kekal lantikan lama & beri amaran');
    allocation_save_position_rule($P[0],['fixed_teacher_id'=>$T[1]]); $p=allocation_preview('rebalance'); check($p['rows'][0]['teacher_id']===$T[1],'susun semula patuh penyandang tetap');
    allocation_save_rules(['excluded_teachers'=>[$T[0],$T[1]]]); $p=allocation_preview('rebalance'); check($p['summary']['released']===1 && $p['summary']['unfilled']===2,'susun semula menerangkan jawatan yang dikosongkan');

    fixture([],[['No candidates','HEM',3]]); $p=allocation_preview('rebalance'); check($p['summary']['unfilled']===1,'tiada guru dikendalikan');
    [$T,$P]=fixture([['A','',20]],[['Role','HEM',3]]); $p=allocation_preview('vacant'); query('UPDATE positions SET weight=4 WHERE school_id='.sid().' AND id=?',[$P[0]]);
    rejects(fn()=>apply_test($p['plan_id']),'pemberat berubah membatalkan pratonton',409); check(assigned()===0,'pratonton lapuk tidak menulis apa-apa');
    $p=allocation_preview('vacant'); allocation_save_rules(['max_tasks'=>2]); rejects(fn()=>apply_test($p['plan_id']),'syarat berubah membatalkan pratonton',409);
    $p=allocation_preview('vacant'); engine_setting_set(['session_year'=>'2029']); rejects(fn()=>apply_test($p['plan_id']),'label sesi berubah membatalkan pratonton',409);
    $p=allocation_preview('vacant'); query('UPDATE allocation_plans SET created_at=NOW() - INTERVAL 1801 SECOND WHERE id=?',[$p['plan_id']]); rejects(fn()=>apply_test($p['plan_id']),'pratonton tamat tempoh ditolak',409);
    $p=allocation_preview('vacant'); query('INSERT INTO assignments(school_id,teacher_id,position_id) VALUES ('.sid().',?,?)',[$T[0],$P[0]]); rejects(fn()=>apply_test($p['plan_id']),'lantikan berubah membatalkan pratonton',409);

    fixture([['A','',20]],[]);
    $rows=bulk_positions_parse("\xEF\xBB\xBFname,category,weight,count\n\"AJK, Khas\",HEM,5,3\nSTEM,Kurikulum,6,1\n");
    check(count($rows)===4 && $rows[0]['name']==='AJK, Khas (1/3)','CSV BOM, koma dalam petikan & bilangan dikembangkan');
    db()->beginTransaction(); bulk_positions_insert($rows); db()->commit(); check(free_count('jawatan')===4,'pukal menambah tempat yang dikembangkan');
    rejects(fn()=>bulk_positions_validate($rows),'import berulang ditolak');
    $before=free_count('jawatan');
    rejects(fn()=>bulk_positions_parse("Valid,HEM,3,1\nInvalid,HEM,99,1"),'kelompok tidak sah ditolak sebelum disimpan');
    check(free_count('jawatan')===$before,'kelompok tidak sah tidak mengubah pangkalan data');
    $tab=bulk_positions_parse("name\tcategory\tweight\tcount\nTab Role\tPentadbiran\t4\t1"); check($tab[0]['category']==='Pentadbiran','tampal TSV Excel disokong');
    rejects(fn()=>bulk_positions_parse("Same,HEM,3\nsame,HEM,4"),'pendua tanpa beza huruf');
    rejects(fn()=>bulk_positions_validate([['name'=>'A','category'=>'Bad','weight'=>3]]),'bidang tidak sah');
    rejects(fn()=>bulk_positions_validate([['name'=>'A','category'=>'HEM','weight'=>3,'count'=>51]]),'had bilangan');
    rejects(fn()=>bulk_positions_parse(str_repeat('x',131073)),'had saiz pukal');
    rejects(fn()=>bulk_positions_validate(array_fill(0,501,['name'=>'A','category'=>'HEM','weight'=>3])),'had baris pukal');
    $P=array_map('intval',query('SELECT id FROM positions WHERE school_id='.sid().' ORDER BY id')->fetchAll(PDO::FETCH_COLUMN)); $T=[(int)query('SELECT id FROM teachers WHERE school_id='.sid())->fetchColumn()];
    allocation_save_position_rule($P[0],['allowed_teachers'=>[$T[0]]]); check(allocation_teacher_referenced($T[0]),'guru dalam syarat tidak boleh dipadam senyap');
    $GLOBALS['A_TEACHER']=$T[0]; $GLOBALS['A_POSITION']=$P[0];
});
echo "Pengasingan sekolah\n";
with_school($B,function(){
    $tA=$GLOBALS['A_TEACHER']; $pA=$GLOBALS['A_POSITION'];
    rejects(fn()=>allocation_save_rules(['excluded_teachers'=>[$tA]]),'sekolah B tidak boleh merujuk guru sekolah A dalam syarat');
    rejects(fn()=>allocation_save_position_rule($pA,['required_specialty'=>'x']),'sekolah B tidak boleh menyunting syarat jawatan sekolah A',404);
    [$T,$P]=fixture([['B1','',20]],[['Role B','HEM',3]]);
    check(count(allocation_state()['teachers'])===1 && allocation_rules()===allocation_defaults(),'keadaan & syarat sekolah B berasingan');
    $p=allocation_preview('vacant'); with_school(school_find('UJA9101'),function()use($p){ rejects(fn()=>apply_test($p['plan_id']),'pratonton sekolah B tidak boleh digunakan di sekolah A',404); });
    $r=apply_test($p['plan_id']); check($r['assigned']===1,'pratonton sekolah B digunakan di sekolah B');
    try { assign_position($pA,$T[0]); check(false,'lantikan silang sekolah'); } catch(EngineError $e){ check($e->status===404,'lantikan dengan jawatan sekolah A ditolak'); }
});
} finally { foreach(['UJA9101','UJB9102'] as $c) if($s=school_find($c)) school_delete($s); }
echo "\n$checks semakan auto-agihan, pukal & pengasingan lulus.\n";
