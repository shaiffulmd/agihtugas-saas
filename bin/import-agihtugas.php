<?php
// Import satu pemasangan AgihTugas / UrusTugas satu sekolah (SQLite) ke dalam satu sekolah BAHARU platform ini.
//   php bin/import-agihtugas.php --db=/laluan/agihtugas.sqlite --code=KOD [--name="Nama sekolah"] [--type=SK|SMK]
//                                [--state=Pahang] [--admin-email=e-mel] [--dry-run]
// - Fail SQLite dibaca sahaja. Semua ID dipetakan semula (guru, jawatan, lantikan, akaun, revisi, syarat & snapshot JSON).
// - Akaun dibawa bersama hash kata laluan asal (bcrypt — ditukar ke Argon2id secara automatik semasa log masuk); 2FA perlu didaftar.
// - Logo & Jata Negara dibawa dengan id kandungan yang sama (surat muktamad lama kekal). Sejarah → log audit sekolah.
// - TIDAK dibawa: kunci API (hanya hash lama — cipta semula di API & Integrasi), pratonton auto-agihan, cubaan log masuk, kod persediaan.
// - Satu transaksi; bilangan baris sumber vs sasaran disemak sebelum commit. --dry-run = semak & batalkan.
declare(strict_types=1);
if(PHP_SAPI!=='cli') exit;
require dirname(__DIR__).'/app/bootstrap.php';
foreach(['settings','install','integrity','images','users','demo','platform','subscription','enjin','tugas_auto','kelulusan','surat_lantikan'] as $f) require_once dirname(__DIR__).'/app/'.$f.'.php';
$opt=[]; foreach(array_slice($argv,1) as $a) if(preg_match('/^--([a-z-]+)(?:=(.*))?$/s',$a,$m)) $opt[$m[1]]=$m[2]??'1';
$fail=function(string $m): never { fwrite(STDERR,"Ralat: $m\n"); exit(1); };
if(app_status()!=='ready') $fail('Pemasangan belum sedia (jalankan pemasang / php bin/upgrade.php).');
$file=(string)($opt['db']??''); $code=strtoupper(trim((string)($opt['code']??''))); $dry=isset($opt['dry-run']);
if($file==='' || !is_file($file) || !is_readable($file)) $fail('Guna --db=/laluan/fail.sqlite (fail mesti wujud).');
if(!school_code_valid($code)) $fail('Kod sekolah mesti kod KPM: 3 huruf + 4 digit (cth. CEA1234).');
if(school_find($code)) $fail("Kod $code sudah wujud. Import hanya ke sekolah baharu.");

// ---------- Baca sumber (baca sahaja) ----------
try {
    $ro=class_exists('Pdo\\Sqlite')?[\Pdo\Sqlite::ATTR_OPEN_FLAGS=>\Pdo\Sqlite::OPEN_READONLY]:[constant('PDO::SQLITE_ATTR_OPEN_FLAGS')=>constant('PDO::SQLITE_OPEN_READONLY')]; // PHP 8.4+ / 8.2–8.3
    $src=new PDO('sqlite:'.realpath($file),null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,]+$ro);
    $src->query('SELECT COUNT(*) FROM sqlite_master')->fetchColumn(); // fail bukan SQLite gagal di sini
} catch(Throwable) { $fail('Fail bukan pangkalan data SQLite yang sah.'); }
$has=fn(string $t)=>(bool)$src->query("SELECT 1 FROM sqlite_master WHERE type='table' AND name=".$src->quote($t))->fetchColumn();
foreach(['settings','teachers','positions','assignments'] as $t) if(!$has($t)) $fail("Jadual $t tiada — bukan pangkalan data AgihTugas.");
$rows=fn(string $t,string $order='id')=>$has($t)?$src->query("SELECT * FROM $t ORDER BY $order")->fetchAll():[];
$S=array_column($rows('settings','key'),'value','key');
$teachers=$rows('teachers'); $positions=$rows('positions'); $assign=$rows('assignments'); $accounts=$rows('accounts');
$config=$rows('allocation_config'); $prules=$rows('position_rules','position_id'); $revs=$rows('workflow_revisions'); $state=$has('workflow_state')?($src->query('SELECT * FROM workflow_state WHERE id=1')->fetch()?:null):null;
$assets=$rows('school_assets'); $history=$rows('history'); $apiKeys=$rows('api_keys');
$name=trim((string)($opt['name']??($S['school']??''))); if($name==='' || mb_strlen($name)>150) $fail('Nama sekolah tidak sah — gunakan --name="…".');
$type=strtoupper((string)($opt['type']??($S['school_type']??'SK'))); if(!in_array($type,['SK','SMK'],true)) $fail('--type mesti SK atau SMK.');
$admins=array_values(array_filter($accounts,fn($a)=>$a['role']==='admin' && (int)$a['active']===1));
$adminEmail=strtolower(trim((string)($opt['admin-email']??'')));
if(!$admins && !filter_var($adminEmail,FILTER_VALIDATE_EMAIL)) $fail('Sumber tiada akaun pentadbir aktif — beri --admin-email=… untuk mencipta Pentadbir Utama (kata laluan sementara dipaparkan).');

/** Masa sumber (SQLite CURRENT_TIMESTAMP = UTC) → waktu Malaysia (sesi DB +08:00). */
$myt=fn(?string $utc)=>$utc?(new DateTimeImmutable($utc,new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('Asia/Kuala_Lumpur'))->format('Y-m-d H:i:s'):null;
$T=[]; $P=[]; $U=[]; $R=[]; $notes=[]; $tempPw=null;

db()->beginTransaction();
try {
    // ---------- Sekolah (tanpa Pentadbir Utama automatik: akaun dibawa daripada sumber) ----------
    $first=$admins[0]??null;
    query('INSERT INTO schools(code,name,status,expires_on,approved_at,state,pic_name,pic_email) VALUES (?,?,"aktif",?,NOW(),?,?,?)',
        [$code,$name,date('Y-m-d',strtotime('+'.(int)pset('percubaan_hari').' days')),mb_substr((string)($opt['state']??''),0,40),mb_substr((string)($first['name']??'Pentadbir Utama'),0,120),$first?strtolower($first['email']):$adminEmail]);
    $school=school_by_id((int)db()->lastInsertId()); tenant_use($school,'path');
    school_engine_init();
    // ---------- Aset (logo & Jata) — id = sha256 kandungan, disahkan ----------
    $assetIds=[];
    foreach($assets as $a){
        $bytes=(string)$a['bytes'];
        if(!hash_equals((string)$a['id'],hash('sha256',$bytes))){ $notes[]='Aset '.substr((string)$a['id'],0,12).'… dilangkau (kandungan tidak sepadan dengan id).'; continue; }
        query('INSERT INTO school_assets(school_id,id,mime,width,height,bytes) VALUES ('.sid().',?,?,?,?,?)',[$a['id'],'image/png',(int)$a['width'],(int)$a['height'],$bytes]); $assetIds[$a['id']]=true;
    }
    $asset=fn(?string $id)=>$id && isset($assetIds[$id])?$id:'';
    $hdr=(string)($S['letter_header']??'dual');
    engine_setting_set(['school_name'=>$name,'school_type'=>$type,'principal'=>mb_substr((string)($S['principal']??''),0,160),'session_year'=>preg_match('/^\d{4}$/',(string)($S['year']??''))?$S['year']:date('Y'),
        'letter_reference'=>mb_substr((string)($S['reference']??'SEK.100-1/5/1'),0,160)?:'SEK.100-1/5/1','letter_header'=>isset(LETTER_HEADERS[$hdr])?$hdr:'dual',
        'school_logo_id'=>$asset($S['school_logo_id']??null),'national_crest_id'=>$asset($S['national_crest_id']??null)]);
    // ---------- Guru, jawatan, lantikan ----------
    foreach($teachers as $t){
        $x=$t['external_id']??null; $x=($x===null || trim((string)$x)==='')?null:mb_substr(trim((string)$x),0,160);
        query('INSERT INTO teachers(school_id,name,email,specialty,capacity,external_id) VALUES ('.sid().',?,?,?,?,?)',[mb_substr(trim((string)$t['name']),0,160),mb_substr(strtolower(trim((string)$t['email'])),0,190),mb_substr((string)$t['specialty'],0,160),max(1,min(100,(int)$t['capacity'])),$x]);
        $T[(int)$t['id']]=(int)db()->lastInsertId();
    }
    foreach($positions as $p){
        if(!in_array($p['category'],CATEGORIES,true)) throw new RuntimeException('Jawatan #'.$p['id'].': bidang tidak dikenali ('.$p['category'].').');
        query('INSERT INTO positions(school_id,name,category,weight) VALUES ('.sid().',?,?,?)',[mb_substr(trim((string)$p['name']),0,160),$p['category'],max(1,min(10,(int)$p['weight']))]);
        $P[(int)$p['id']]=(int)db()->lastInsertId();
    }
    foreach($assign as $a) query('INSERT INTO assignments(school_id,teacher_id,position_id) VALUES ('.sid().',?,?)',[$T[(int)$a['teacher_id']],$P[(int)$a['position_id']]]);
    // ---------- Akaun → pengguna (hash kata laluan asal; 2FA didaftar semasa log masuk) ----------
    foreach($accounts as $a){
        $email=strtolower(trim((string)$a['email'])); $tid=$a['teacher_id']!==null?($T[(int)$a['teacher_id']]??null):null;
        if($a['role']==='teacher' && $tid===null){ $notes[]="Akaun guru $email tidak dipaut (profil guru tiada) — pautkan di Pengguna & Audit."; }
        query('INSERT INTO users(school_id,name,email,password_hash,role,active,teacher_id) VALUES ('.sid().',?,?,?,?,?,?)',[mb_substr(trim((string)$a['name']),0,120),$email,(string)$a['password_hash'],$a['role'],(int)$a['active']?1:0,$tid]);
        $U[(int)$a['id']]=(int)db()->lastInsertId();
    }
    if(!$admins){ $tempPw=platform_temp_password(); create_admin('Pentadbir Utama',$adminEmail,$tempPw,true); $notes[]="Pentadbir Utama $adminEmail dicipta (kata laluan sementara di bawah)."; }
    $anyUser=fn()=>(int)query('SELECT id FROM users WHERE school_id='.sid().' AND role="admin" ORDER BY id LIMIT 1')->fetchColumn();
    // ---------- Syarat auto-agihan (ID guru/jawatan dalam JSON dipetakan semula) ----------
    $mapT=fn(array $ids)=>array_values(array_filter(array_map(fn($i)=>$T[(int)$i]??null,$ids)));
    foreach($config as $c){ $j=json_decode((string)$c['payload'],true); if(!is_array($j)) continue; $j['excluded_teachers']=$mapT($j['excluded_teachers']??[]);
        query('INSERT INTO allocation_config(school_id,payload) VALUES ('.sid().',?)',[json_encode($j,JSON_THROW_ON_ERROR)]); }
    foreach($prules as $r){ if(!isset($P[(int)$r['position_id']])) continue; $j=json_decode((string)$r['payload'],true); if(!is_array($j)) continue;
        $j['allowed_teachers']=$mapT($j['allowed_teachers']??[]); $j['fixed_teacher_id']=isset($j['fixed_teacher_id'])&&$j['fixed_teacher_id']!==null?($T[(int)$j['fixed_teacher_id']]??null):null;
        query('INSERT INTO position_rules(school_id,position_id,payload) VALUES ('.sid().',?,?)',[$P[(int)$r['position_id']],json_encode($j,JSON_THROW_ON_ERROR)]); }
    // ---------- Revisi aliran (snapshot JSON dipetakan; guru/jawatan yang telah dipadam → ID negatif, tidak berlanggar) ----------
    foreach($revs as $r){
        $j=json_decode((string)$r['payload'],true); if(!is_array($j)) throw new RuntimeException('Revisi #'.$r['id'].': snapshot rosak.');
        foreach($j['teachers']??[] as $k=>$t) $j['teachers'][$k]['id']=$T[(int)$t['id']]??-(int)$t['id'];
        foreach($j['positions']??[] as $k=>$p){ $j['positions'][$k]['id']=$P[(int)$p['id']]??-(int)$p['id']; if($p['teacher_id']!==null) $j['positions'][$k]['teacher_id']=$T[(int)$p['teacher_id']]??-(int)$p['teacher_id']; }
        $u=fn($id)=>$id===null?null:($U[(int)$id]??$anyUser());
        query('INSERT INTO workflow_revisions(school_id,status,payload,source_hash,submitted_by,submitted_at,approved_by,approved_at,finalized_by,finalized_at,returned_by,returned_at,return_reason) VALUES ('.sid().',?,?,?,?,?,?,?,?,?,?,?,?)',
            [$r['status'],json_encode($j,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE),(string)$r['source_hash'],$u($r['submitted_by']),$myt($r['submitted_at']),$u($r['approved_by']),$myt($r['approved_at']),$u($r['finalized_by']),$myt($r['finalized_at']),$u($r['returned_by']),$myt($r['returned_at']),$r['return_reason']!==null?mb_substr((string)$r['return_reason'],0,500):null]);
        $R[(int)$r['id']]=(int)db()->lastInsertId();
    }
    if($state){
        $rid=$state['revision_id']!==null?($R[(int)$state['revision_id']]??null):null;
        query('UPDATE workflow_state SET status=?,revision_id=?,version=? WHERE school_id='.sid(),[$state['status'],$rid,max(1,(int)$state['version'])]);
        // Semakan/kelulusan yang sedang berjalan: data dikunci sejak dihantar, jadi snapshot dibina semula dalam format baharu supaya boleh diluluskan.
        if($rid && in_array($state['status'],['submitted','approved'],true)){ $srcNow=workflow_source(); query('UPDATE workflow_revisions SET payload=?,source_hash=? WHERE id=? AND school_id='.sid(),[json_encode($srcNow,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE),workflow_hash($srcNow),$rid]); $notes[]='Revisi #'.$rid.' sedang '.WORKFLOW_LABELS[$state['status']].' — snapshot dibina semula supaya boleh diteruskan.'; }
    }
    // ---------- Sejarah → log audit (berantai) ----------
    foreach($history as $h) audit('import_history','history',null,mb_substr('['.date('d/m/Y H:i',strtotime((string)$myt($h['created_at']))).'] '.(string)$h['description'],0,255));
    audit('import_agihtugas','settings',null,basename($file).': '.count($T).' guru, '.count($P).' jawatan, '.count($assign).' lantikan, '.count($U).' akaun');
    if($apiKeys) $notes[]=count($apiKeys).' kunci API lama TIDAK dibawa (hanya hash disimpan) — cipta kunci baharu di API & Integrasi dan kemas kini sistem pemanggil (URL baharu: '.school_url($code,'/api/v1').').';
    // ---------- Semak bilangan ----------
    $cnt=fn(string $t)=>(int)query("SELECT COUNT(*) FROM $t WHERE school_id=".sid())->fetchColumn();
    $expect=['teachers'=>count($teachers),'positions'=>count($positions),'assignments'=>count($assign),'users'=>count($accounts)+($admins?0:1),'position_rules'=>count(array_filter($prules,fn($r)=>isset($P[(int)$r['position_id']]))),'workflow_revisions'=>count($revs),'school_assets'=>count($assetIds)];
    foreach($expect as $t=>$n) if($cnt($t)!==$n) throw new RuntimeException("Bilangan $t tidak sepadan (sumber $n, sasaran ".$cnt($t).').');
    if($dry){ db()->rollBack(); echo "SEMAKAN SAHAJA (--dry-run): tiada perubahan disimpan.\n"; }
    else { db()->commit(); tenant_use(null); platform_log('school_imported',(int)$school['id'],$code.' daripada '.basename($file)); }
} catch(Throwable $ex){ if(db()->inTransaction()) db()->rollBack(); $fail('Import dibatalkan: '.$ex->getMessage()); }

printf("%s %s (%s): %d guru, %d jawatan, %d lantikan, %d akaun, %d syarat jawatan, %d revisi, %d aset, %d sejarah\n",$dry?'Boleh diimport →':'Diimport →',$code,$name,count($T),count($P),count($assign),count($U),count($prules),count($R),count($assetIds),count($history));
foreach($notes as $n) echo " - $n\n";
if(!$dry){
    echo "Laman sekolah: ".school_url($code,'/')."\nAkaun log masuk dengan kata laluan lama; pentadbir mendaftar 2FA pada log masuk pertama.\n";
    if($tempPw) echo "Kata laluan sementara Pentadbir Utama ($adminEmail): $tempPw\n";
}
