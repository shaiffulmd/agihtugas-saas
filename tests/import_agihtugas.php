<?php
// Ujian bin/import-agihtugas.php menggunakan fixture tests/fixtures/agihtugas-sample.sql (kod AgihTugas lama sebenar).
// Pemasangan UJIAN sahaja; sekolah UJI9301–UJI9303 dicipta dan dipadam.   php tests/import_agihtugas.php
declare(strict_types=1);
if(PHP_SAPI!=='cli') exit;
$root=dirname(__DIR__);
require $root.'/app/bootstrap.php';
foreach(['settings','install','integrity','images','users','demo','platform','subscription','enjin','tugas_auto','kelulusan','surat_lantikan'] as $f) require_once $root.'/app/'.$f.'.php';
if(env('APP_ENV')!=='local' && env('DEMO_MODE')!=='1') exit("Pemasangan ujian sahaja.\n");
$ok=0; function check(bool $c,string $l): void { global $ok; if(!$c){ fwrite(STDERR,"GAGAL: $l\n"); exit(1); } $ok++; echo "  ✓ $l\n"; }
$tmp=sys_get_temp_dir().'/urus-import-'.bin2hex(random_bytes(4)); mkdir($tmp,0700);
/** Bina SQLite daripada dump fixture; $patch = SQL tambahan pada salinan itu. */
function sample(string $tmp,string $name,string $patch=''): string {
    $f="$tmp/$name.sqlite"; $pdo=new PDO('sqlite:'.$f); $pdo->exec((string)file_get_contents(dirname(__DIR__).'/tests/fixtures/agihtugas-sample.sql')); if($patch!=='') $pdo->exec($patch); return $f;
}
function imp(array $args): array { $out=[]; exec('php '.escapeshellarg(dirname(__DIR__).'/bin/import-agihtugas.php').' '.implode(' ',array_map('escapeshellarg',$args)).' 2>&1',$out,$rc); return [$rc,implode("\n",$out)]; }
$codes=['UJI9301','UJI9302','UJI9303']; $clean=function() use($codes){ foreach($codes as $c) if($s=school_find($c)) school_delete($s); }; $clean();
try {
    $src=new PDO('sqlite:'.($db=sample($tmp,'asal'))); $src->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE,PDO::FETCH_ASSOC);
    $q=fn(string $sql)=>$src->query($sql)->fetchAll();
    echo "Input tidak sah\n";
    [$rc,$o]=imp(["--db=$db",'--code=XX1']); check($rc===1 && str_contains($o,'kod KPM'),'kod sekolah tidak sah ditolak');
    file_put_contents("$tmp/bukan.sqlite",'bukan sqlite'); [$rc,$o]=imp(["--db=$tmp/bukan.sqlite",'--code=UJI9301']); check($rc===1,'fail bukan SQLite ditolak');
    $empty=new PDO("sqlite:$tmp/kosong.sqlite"); $empty->exec('CREATE TABLE x(id INT)'); [$rc,$o]=imp(["--db=$tmp/kosong.sqlite",'--code=UJI9301']); check($rc===1 && str_contains($o,'bukan pangkalan data AgihTugas'),'SQLite tanpa jadual AgihTugas ditolak');
    echo "Semak sahaja\n";
    [$rc,$o]=imp(["--db=$db",'--code=UJI9301','--dry-run']); check($rc===0 && str_contains($o,'tiada perubahan disimpan') && !school_find('UJI9301'),'--dry-run tidak meninggalkan sekolah');
    echo "Import penuh\n";
    [$rc,$o]=imp(["--db=$db",'--code=UJI9301','--state=Pahang']); check($rc===0 && str_contains($o,'12 guru, 24 jawatan, 19 lantikan, 5 akaun'),'import berjaya: '.strtok($o,"\n"));
    $S=school_find('UJI9301'); check($S['status']==='aktif' && $S['state']==='Pahang' && $S['name']==='SMK Lama Contoh','rekod sekolah (nama daripada tetapan sumber)');
    with_school($S,function() use($q,$src){
        $id=sid(); $one=fn(string $sql,array $p=[])=>query($sql,$p)->fetchColumn();
        check(school_type()==='SMK' && setting('principal')==='Dr. Hjh. Rohani binti Yusof' && setting('letter_reference')==='SMKLC.100-1/5/1' && setting('session_year')==='2026','tetapan sekolah & surat');
        // Pasangan lantikan mengikut NAMA sama dengan sumber (peta ID betul).
        $pairs=fn(array $rows)=>array_map(fn($r)=>$r['p'].' → '.$r['t'],$rows);
        $srcPairs=$pairs($q('SELECT p.name p,t.name t FROM assignments a JOIN positions p ON p.id=a.position_id JOIN teachers t ON t.id=a.teacher_id ORDER BY p.name'));
        $dstPairs=$pairs(query('SELECT p.name p,t.name t FROM assignments a JOIN positions p ON p.id=a.position_id AND p.school_id=a.school_id JOIN teachers t ON t.id=a.teacher_id AND t.school_id=a.school_id WHERE a.school_id='.$id.' ORDER BY p.name')->fetchAll());
        check($srcPairs===$dstPairs,'19 lantikan sepadan mengikut nama jawatan → guru');
        check($one('SELECT external_id FROM teachers WHERE school_id='.$id.' AND name="Siti Nurhaliza Hassan"')==='koku-multi:3:41','external_id dikekalkan');
        $tname=fn(?int $tid)=>$tid?(string)$one('SELECT name FROM teachers WHERE school_id='.$id.' AND id=?',[$tid]):null;
        $rules=allocation_rules(); check($rules['max_tasks']===6 && array_map($tname,$rules['excluded_teachers'])===['Nor Azlina Rahman'],'syarat umum: guru dikecualikan dipeta');
        $pr=allocation_position_rules(); $km=(int)$one('SELECT id FROM positions WHERE school_id='.$id.' AND name="Ketua Panitia Matematik"');
        check($tname($pr[$km]['fixed_teacher_id'])==='Muhammad Hafiz Ahmad' && array_map($tname,$pr[$km]['allowed_teachers'])===['Muhammad Hafiz Ahmad','Chong Wei Ling'],'syarat jawatan: penyandang tetap & guru layak dipeta');
        $u=query('SELECT u.email,u.role,u.password_hash,t.name tn FROM users u LEFT JOIN teachers t ON t.id=u.teacher_id AND t.school_id=u.school_id WHERE u.school_id='.$id.' ORDER BY u.email')->fetchAll();
        check(count($u)===5 && password_verify('Lama-Guru-2026',$u[array_search('guru1@lama.test',array_column($u,'email'))]['password_hash']),'5 akaun; kata laluan lama sah');
        check($u[array_search('guru2@lama.test',array_column($u,'email'))]['tn']==='Muhammad Hafiz Ahmad','akaun guru dipaut ke profil guru yang betul');
        $st=workflow_state(); $pub=workflow_published();
        check($st['status']==='draft' && $pub && $pub['id']===$st['revision_id'],'aliran: draf pindaan dengan versi muktamad diterbitkan');
        $aisyah=(int)$one('SELECT id FROM teachers WHERE school_id='.$id.' AND name="Nur Aisyah Abdullah"');
        $letter=workflow_letter($aisyah); check(array_column($letter['assignments'],'name')===['Setiausaha Pengurusan','Penyelaras Aset','Penyelaras Data Sekolah','Ketua Panitia Bahasa Melayu'],'surat muktamad guru (ID snapshot dipeta)');
        $sukan=(int)$one('SELECT id FROM positions WHERE school_id='.$id.' AND name="Setiausaha Sukan"');
        $snap=array_column($pub['data']['positions'],'teacher_id','id'); check(isset($snap[$sukan]) && $snap[$sukan]!==null && !$one('SELECT 1 FROM assignments WHERE school_id='.$id.' AND position_id=?',[$sukan]),'pindaan draf: dilepaskan dalam draf, kekal dalam versi muktamad');
        $logo=setting('school_logo_id'); $bytes=(string)$one('SELECT bytes FROM school_assets WHERE school_id='.$id.' AND id=?',[$logo]);
        check($logo!=='' && hash('sha256',$bytes)===$logo && ($pub['data']['school']['branding']['school_logo']['id']??'')===$logo,'logo surat dibawa dengan id kandungan yang sama');
        check(!$one('SELECT COUNT(*) FROM api_keys WHERE school_id='.$id),'kunci API lama tidak dibawa');
        check((int)$one('SELECT COUNT(*) FROM audit_logs WHERE school_id='.$id.' AND action="import_history"')===5 && audit_verify($id)['ok'],'sejarah → log audit berantai (sah)');
        check(str_contains((string)$one('SELECT submitted_at FROM workflow_revisions WHERE school_id='.$id),date('Y-m-d')),'masa revisi ditukar UTC → MYT');
    });
    [$rc,$o]=imp(["--db=$db",'--code=UJI9301']); check($rc===1 && str_contains($o,'sudah wujud'),'import berulang kod sama ditolak');
    echo "Tanpa pentadbir aktif\n";
    $na=sample($tmp,'tanpa-admin','UPDATE accounts SET active=0 WHERE role="admin";');
    [$rc,$o]=imp(["--db=$na",'--code=UJI9302']); check($rc===1 && str_contains($o,'--admin-email'),'perlu --admin-email');
    [$rc,$o]=imp(["--db=$na",'--code=UJI9302','--admin-email=utama@uji.test','--name=SMK Nama Baharu','--type=SK']); check($rc===0 && preg_match('/Kata laluan sementara Pentadbir Utama \(utama@uji.test\): (\S+)/',$o,$m)===1,'Pentadbir Utama dicipta dengan kata laluan sementara');
    $S2=school_find('UJI9302'); check($S2['name']==='SMK Nama Baharu' && with_school($S2,fn()=>school_type())==='SK','--name & --type mengatasi sumber');
    check((bool)with_school($S2,fn()=>query('SELECT must_change FROM users WHERE school_id='.sid().' AND email="utama@uji.test"')->fetchColumn()),'Pentadbir Utama wajib tukar kata laluan');
    echo "Semakan sedang berjalan\n";
    $sb=sample($tmp,'semakan','INSERT INTO assignments(teacher_id,position_id) VALUES (4,20); UPDATE workflow_revisions SET status="submitted",approved_by=NULL,approved_at=NULL,finalized_by=NULL,finalized_at=NULL; UPDATE workflow_state SET status="submitted";');
    [$rc,$o]=imp(["--db=$sb",'--code=UJI9303']); check($rc===0 && str_contains($o,'snapshot dibina semula'),'revisi dalam semakan diimport');
    with_school(school_find('UJI9303'),function(){
        $h=(int)query('SELECT id FROM users WHERE school_id='.sid().' AND role="head"')->fetchColumn();
        db()->beginTransaction(); $st=workflow_transition('approve',workflow_state()['version'],['id'=>$h,'role'=>'head','name'=>'Ketua'],''); db()->commit();
        check($st['status']==='approved','Pengetua boleh meluluskan semakan yang diimport (hash snapshot sepadan)');
    });
} finally { $clean(); foreach(glob("$tmp/*")?:[] as $f) @unlink($f); @rmdir($tmp); }
echo "\n$ok semakan import lulus.\n";
