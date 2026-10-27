<?php
declare(strict_types=1);
/* Enjin agihan tugas UrusTugas (berbilang sekolah): guru, jawatan, lantikan dan profil sekolah untuk surat.
 * Setiap query menapis school_id=sid(); ID daripada borang/API disahkan dalam sekolah semasa sebelum digunakan.
 * Ralat enjin ialah DomainException (dipaparkan sebagai notis borang) dengan kod status HTTP untuk API. */

const CATEGORIES=['Pentadbiran','Kurikulum','HEM','Kokurikulum'];
/** Tahap & tempoh jawatan: [label, faktor skor tugas]. Lalai sokongan × tahunan = 1.0, jadi skor = pemberat. */
const POSITION_LEVELS=['utama'=>['Utama',1.5],'sokongan'=>['Sokongan',1.0],'sampingan'=>['Sampingan',0.5]];
const POSITION_TERMS=['tahunan'=>['Sepanjang tahun',1.0],'bermusim'=>['Bermusim',0.6],'program'=>['Program',0.3]];
const TEACHER_STATUSES=['aktif'=>'Aktif','cuti'=>'Cuti','bertukar'=>'Bertukar keluar'];

/** Ralat enjin: mesej untuk pengguna + status HTTP + kod ralat API yang stabil (kontrak klien API v1). */
class EngineError extends DomainException {
    public function __construct(string $message,public int $status=422,public string $apiCode='validation_error') { parent::__construct($message); }
}
final class AllocationError extends EngineError {
    public function __construct(string $message,int $status=422) { parent::__construct($message,$status,$status===409?'stale_or_used_plan':($status===404?'not_found':'validation_error')); }
}
final class WorkflowError extends EngineError {
    public function __construct(string $message,int $status=409) { parent::__construct($message,$status,$status===409?'workflow_locked':($status===403?'forbidden':($status===404?'not_found':'validation_error'))); }
}

/* ---------- Peranan & halaman ---------- */
const ENGINE_PAGES=['guru'=>'Pengurusan Guru','jawatan'=>'Jawatan & Pemberat','agihan'=>'Agihan Tugas','analisis'=>'Analisis Beban','auto'=>'Agihan Automatik','syarat'=>'Pusat Syarat','pukal'=>'Tambah Jawatan Pukal',
    'kelulusan'=>'Semakan & Kelulusan','surat'=>'Surat Lantikan','tugas_saya'=>'Tugas Saya','sekolah'=>'Profil Sekolah & Surat','api'=>'API & Integrasi'];
/** Halaman enjin yang dibenarkan bagi peranan. */
function engine_pages(string $role): array {
    return match($role){
        'admin'=>['guru','jawatan','agihan','analisis','auto','syarat','pukal','kelulusan','surat','sekolah','api'],
        'pk'=>['guru','jawatan','agihan','analisis','auto','syarat','pukal','kelulusan','surat'],
        'head'=>['analisis','kelulusan','surat'],
        'teacher'=>['tugas_saya'],
        default=>[]};
}
const PLANNING_PAGES=['guru','jawatan','agihan','auto','syarat','pukal','sekolah'];

/** Pentadbir & PK merancang agihan (data enjin); GB/Pengetua melulus; guru melihat tugas muktamad sendiri. */
function can_plan(?array $u=null): bool { $u??=current_user(); return in_array($u['role']??'',['admin','pk'],true); }
function require_planner(): void { if(!can_plan()) error_page(403,'Hanya Pentadbir atau Penolong Kanan boleh mengurus agihan tugas.'); }

/* ---------- Profil sekolah (surat & snapshot) ---------- */
function school_profile(): array {
    return ['school'=>school_name(),'school_type'=>school_type(),'principal'=>setting('principal'),'principal_title'=>head_title(),
        'year'=>setting('session_year')?:date('Y'),'reference'=>setting('letter_reference'),'branding'=>branding_profile()];
}

/* ---------- Guru ---------- */
/** Guru sekolah semasa dengan beban (jumlah pemberat), bilangan tugas. */
function teachers_with_load(?int $id=null): array {
    return query('SELECT t.*,COALESCE(SUM(p.weight),0) AS `load`,COUNT(a.id) AS task_count FROM teachers t LEFT JOIN assignments a ON a.teacher_id=t.id AND a.school_id=t.school_id LEFT JOIN positions p ON p.id=a.position_id AND p.school_id=a.school_id WHERE t.school_id='.sid().($id!==null?' AND t.id='.(int)$id:'').' GROUP BY t.id ORDER BY t.name')->fetchAll();
}
function teacher_find(int $id): ?array { return query('SELECT * FROM teachers WHERE id=? AND school_id='.sid(),[$id])->fetch()?:null; }
/** Sahkan & normalkan medan guru. $data: name, email, specialty, capacity (int), external_id (pilihan, null untuk kosongkan). */
function teacher_validate(array $data): array {
    $name=trim((string)($data['name']??'')); $email=strtolower(trim((string)($data['email']??''))); $spec=trim((string)($data['specialty']??''));
    if($name==='' || mb_strlen($name)>160) throw new EngineError('Nama guru diperlukan (maksimum 160 aksara).');
    if($email!=='' && (strlen($email)>190 || !filter_var($email,FILTER_VALIDATE_EMAIL))) throw new EngineError('Alamat e-mel guru tidak sah.');
    if(mb_strlen($spec)>160) throw new EngineError('Kepakaran maksimum 160 aksara.');
    $cap=$data['capacity']??null; if(!is_int($cap) || $cap<1 || $cap>100) throw new EngineError('Kapasiti beban mesti integer 1–100 mata.');
    $out=['name'=>$name,'email'=>$email,'specialty'=>$spec,'capacity'=>$cap];
    if(array_key_exists('external_id',$data)){ $x=$data['external_id']; if($x!==null){ $x=trim((string)$x); if($x==='' || mb_strlen($x)>160) throw new EngineError('external_id mesti teks 1–160 aksara.'); } $out['external_id']=$x; }
    // Medan indeks beban: pilihan — yang tidak dihantar dikekalkan (atau lalai DB untuk guru baharu).
    if(array_key_exists('teaching_periods',$data)){ $n=$data['teaching_periods']; if(!is_int($n) || $n<0 || $n>60) throw new EngineError('Masa mengajar mesti integer 0–60 masa seminggu.'); $out['teaching_periods']=$n; }
    foreach(['exam_class'=>'Kelas peperiksaan','index_exempt'=>'Pengecualian indeks'] as $k=>$label) if(array_key_exists($k,$data)){ if(!is_bool($data[$k])) throw new EngineError($label.' mesti benar/palsu.'); $out[$k]=(int)$data[$k]; }
    if(array_key_exists('status',$data)){ if(!isset(TEACHER_STATUSES[$data['status']])) throw new EngineError('Status guru mesti aktif, cuti atau bertukar.'); $out['status']=$data['status']; }
    return $out;
}
/** Cipta (id 0) atau kemas kini guru. Pulangkan ID. */
function teacher_save(int $id,array $data): int {
    $v=teacher_validate($data);
    if($id){ if(!teacher_find($id)) throw new EngineError('Guru tidak ditemui.',404,'not_found'); }
    else free_guard('guru',1);
    if(array_key_exists('external_id',$v) && $v['external_id']!==null && query('SELECT 1 FROM teachers WHERE school_id='.sid().' AND external_id=? AND id<>?',[$v['external_id'],$id])->fetchColumn())
        throw new EngineError('external_id sudah digunakan. Gunakan POST /teachers/sync untuk menyegerakkan guru.',409,'duplicate_external_id');
    $cols=array_keys($v);
    if($id) query('UPDATE teachers SET '.implode(',',array_map(fn($c)=>$c.'=?',$cols)).' WHERE id=? AND school_id='.sid(),[...array_values($v),$id]);
    else { query('INSERT INTO teachers (school_id,'.implode(',',$cols).') VALUES ('.sid().','.implode(',',array_fill(0,count($cols),'?')).')',array_values($v)); $id=(int)db()->lastInsertId(); }
    return $id;
}
function teacher_delete(int $id): void {
    if(!teacher_find($id)) throw new EngineError('Guru tidak ditemui.',404,'not_found');
    if(query('SELECT 1 FROM assignments WHERE school_id='.sid().' AND teacher_id=?',[$id])->fetchColumn()) throw new EngineError('Lepaskan agihan berkaitan dahulu sebelum memadam rekod ini.',409,'record_in_use');
    if(query('SELECT 1 FROM users WHERE school_id='.sid().' AND teacher_id=?',[$id])->fetchColumn()) throw new EngineError('Nyahpaut akaun pengguna daripada profil guru dahulu.',409,'record_in_use');
    if(allocation_teacher_referenced($id)) throw new EngineError('Buang rujukan guru ini dalam Pusat Syarat dahulu.',409,'record_in_use');
    query('DELETE FROM teachers WHERE id=? AND school_id='.sid(),[$id]);
}

/* ---------- Jawatan ---------- */
function positions_with_holder(?int $id=null): array {
    return query('SELECT p.*,a.id AS assignment_id,a.teacher_id,t.name AS teacher_name FROM positions p LEFT JOIN assignments a ON a.position_id=p.id AND a.school_id=p.school_id LEFT JOIN teachers t ON t.id=a.teacher_id AND t.school_id=a.school_id WHERE p.school_id='.sid().($id!==null?' AND p.id='.(int)$id:'').' ORDER BY FIELD(p.category,"Pentadbiran","Kurikulum","HEM","Kokurikulum"),p.name,p.id')->fetchAll();
}
function position_find(int $id): ?array { return query('SELECT * FROM positions WHERE id=? AND school_id='.sid(),[$id])->fetch()?:null; }
function position_validate(array $data): array {
    $name=trim((string)($data['name']??'')); $cat=(string)($data['category']??''); $w=$data['weight']??null;
    if($name==='' || mb_strlen($name)>160) throw new EngineError('Nama jawatan diperlukan (maksimum 160 aksara).');
    if(!in_array($cat,CATEGORIES,true)) throw new EngineError('Bidang mesti Pentadbiran, Kurikulum, HEM atau Kokurikulum.');
    if(!is_int($w) || $w<1 || $w>10) throw new EngineError('Pemberat mesti integer 1–10.');
    $out=['name'=>$name,'category'=>$cat,'weight'=>$w];
    if(array_key_exists('level',$data)){ if(!isset(POSITION_LEVELS[$data['level']])) throw new EngineError('Tahap mesti utama, sokongan atau sampingan.'); $out['level']=$data['level']; }
    if(array_key_exists('term',$data)){ if(!isset(POSITION_TERMS[$data['term']])) throw new EngineError('Tempoh mesti tahunan, bermusim atau program.'); $out['term']=$data['term']; }
    return $out;
}
function position_save(int $id,array $data): int {
    $v=position_validate($data); $cols=array_keys($v);
    if($id){ if(!position_find($id)) throw new EngineError('Jawatan tidak ditemui.',404,'not_found'); query('UPDATE positions SET '.implode(',',array_map(fn($c)=>$c.'=?',$cols)).' WHERE id=? AND school_id='.sid(),[...array_values($v),$id]); return $id; }
    free_guard('jawatan',1);
    query('INSERT INTO positions (school_id,'.implode(',',$cols).') VALUES ('.sid().','.implode(',',array_fill(0,count($cols),'?')).')',array_values($v)); return (int)db()->lastInsertId();
}
/** Skor tugas dalam indeks beban = pemberat × faktor tahap × faktor tempoh. */
function position_score(array $p): float { return round((int)$p['weight']*(POSITION_LEVELS[$p['level']??'sokongan'][1]??1.0)*(POSITION_TERMS[$p['term']??'tahunan'][1]??1.0),2); }
function position_delete(int $id): void {
    if(!position_find($id)) throw new EngineError('Jawatan tidak ditemui.',404,'not_found');
    if(query('SELECT 1 FROM assignments WHERE school_id='.sid().' AND position_id=?',[$id])->fetchColumn()) throw new EngineError('Lepaskan agihan berkaitan dahulu sebelum memadam rekod ini.',409,'record_in_use');
    query('DELETE FROM positions WHERE id=? AND school_id='.sid(),[$id]); // syarat jawatan dipadam bersama (CASCADE)
}

/* ---------- Lantikan ---------- */
/** Tetapkan penyandang jawatan (ganti penyandang sedia ada). Pulangkan ID lantikan. */
function assign_position(int $positionId,int $teacherId): int {
    if(!position_find($positionId) || !teacher_find($teacherId)) throw new EngineError('Pilih guru dan jawatan yang sah.',404,'not_found');
    query('INSERT INTO assignments (school_id,teacher_id,position_id) VALUES ('.sid().',?,?) ON DUPLICATE KEY UPDATE teacher_id=VALUES(teacher_id)',[$teacherId,$positionId]);
    return (int)query('SELECT id FROM assignments WHERE school_id='.sid().' AND position_id=?',[$positionId])->fetchColumn();
}
function unassign(int $assignmentId): array {
    $a=query('SELECT a.*,p.name AS position_name FROM assignments a JOIN positions p ON p.id=a.position_id AND p.school_id=a.school_id WHERE a.id=? AND a.school_id='.sid(),[$assignmentId])->fetch();
    if(!$a) throw new EngineError('Lantikan tidak ditemui.',404,'not_found');
    query('DELETE FROM assignments WHERE id=? AND school_id='.sid(),[$assignmentId]); return $a;
}
function engine_counts(): array {
    $r=query('SELECT (SELECT COUNT(*) FROM teachers WHERE school_id='.sid().') t,(SELECT COUNT(*) FROM positions WHERE school_id='.sid().') p,(SELECT COUNT(*) FROM assignments WHERE school_id='.sid().') a')->fetch();
    return ['teachers'=>(int)$r['t'],'positions'=>(int)$r['p'],'assigned'=>(int)$r['a'],'vacant'=>(int)$r['p']-(int)$r['a']];
}

/* ---------- Guru pukal (CSV: name,email,specialty,capacity[,teaching_periods]) ---------- */
function bulk_teachers_parse(string $text): array {
    if(strlen($text)>131072) throw new EngineError('Fail / teks pukal maksimum 128 KB.');
    $rows=[];
    foreach(csv_rows($text,[['name','email','specialty','capacity','teaching_periods'],['name','email','specialty','capacity']],3,5) as $i=>$r){
        $cap=($r[3]??'')===''?'25':$r[3]; if(!ctype_digit($cap)) throw new EngineError('Baris '.($i+1).': kapasiti mesti nombor bulat.');
        $per=($r[4]??'')===''?'0':$r[4]; if(!ctype_digit($per)) throw new EngineError('Baris '.($i+1).': masa mengajar mesti nombor bulat.');
        $rows[]=['name'=>$r[0],'email'=>$r[1],'specialty'=>$r[2],'capacity'=>(int)$cap,'teaching_periods'=>(int)$per];
    }
    return bulk_teachers_validate($rows);
}
/** Sahkan senarai guru pukal (1–500): medan sah, tiada nama / e-mel berulang dalam senarai atau dengan guru sedia ada. */
function bulk_teachers_validate(array $rows): array {
    if(!array_is_list($rows) || !$rows || count($rows)>500) throw new EngineError('Sediakan antara 1 hingga 500 baris guru.');
    $seen=[];
    foreach(query('SELECT name,email FROM teachers WHERE school_id='.sid())->fetchAll() as $t){ $seen['n:'.mb_strtolower($t['name'])]=true; if($t['email']!=='') $seen['e:'.strtolower($t['email'])]=true; }
    $out=[];
    foreach($rows as $i=>$r){
        $line=$i+1;
        try { $v=teacher_validate(is_array($r)?$r:[]); } catch(EngineError $ex){ throw new EngineError("Baris $line: ".$ex->getMessage()); }
        if(isset($seen['n:'.mb_strtolower($v['name'])])) throw new EngineError("Baris $line: nama '".$v['name']."' sudah wujud atau berulang.");
        if($v['email']!=='' && isset($seen['e:'.$v['email']])) throw new EngineError("Baris $line: e-mel '".$v['email']."' sudah wujud atau berulang.");
        $seen['n:'.mb_strtolower($v['name'])]=true; if($v['email']!=='') $seen['e:'.$v['email']]=true;
        $out[]=$v;
    }
    return $out;
}
/** Baca CSV / lajur bertab. $headers: baris pengepala yang dilangkau. Setiap baris mesti $min–$max lajur. */
function csv_rows(string $text,array $headers,int $min,int $max): array {
    $text=(string)preg_replace('/^\xEF\xBB\xBF/','',$text);
    $first=strtok($text,"\r\n"); $delim=str_contains((string)$first,"\t")?"\t":',';
    $s=fopen('php://temp','r+'); fwrite($s,$text); rewind($s); $rows=[]; $isFirst=true;
    try {
        while(($row=fgetcsv($s,0,$delim,'"',''))!==false){
            if(count($row)===1 && trim((string)($row[0]??''))==='') continue;
            $row=array_map(fn($c)=>trim((string)$c),$row);
            if($isFirst){ $isFirst=false; $lower=array_map('strtolower',$row); foreach($headers as $h) if($lower===$h || $lower===array_slice($h,0,$min)) continue 2; }
            if(count($row)<$min || count($row)>$max) throw new EngineError('Bilangan lajur tidak sah pada baris '.(count($rows)+1).'.');
            $rows[]=$row; if(count($rows)>500) throw new EngineError('Maksimum 500 baris bagi satu import.');
        }
    } finally { fclose($s); }
    return $rows;
}
/** Baca teks tampal atau fail CSV yang dimuat naik (satu sumber sahaja). */
function bulk_source(string $field='csv',string $textField='bulk_text'): string {
    $text=input($textField); $f=$_FILES[$field]??null;
    if(is_array($f) && ($f['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_NO_FILE){
        if(is_array($f['error']) || $f['error']!==UPLOAD_ERR_OK || $f['size']>131072 || !is_uploaded_file((string)$f['tmp_name'])) throw new EngineError('Muat naik CSV gagal atau melebihi 128 KB.');
        if(trim($text)!=='') throw new EngineError('Pilih satu sumber sahaja: tampal teks atau muat naik CSV.');
        $text=(string)file_get_contents((string)$f['tmp_name']);
    }
    return $text;
}

/* ---------- Indeks beban ----------
 * Indeks = bahagian mengajar × (masa mengajar ÷ median sekolah) + baki × (skor tugas ÷ median sekolah); 1.00 = setara guru biasa.
 * Masa guru kelas peperiksaan didarab faktor peperiksaan. Hanya guru aktif yang tidak dikecualikan dikira (dan membentuk median).
 * Median 0 (contohnya kurang separuh guru ada tugas) → purata digunakan supaya indeks tidak melonjak; jika semua masa mengajar 0, indeks ikut tugas sahaja. */
const INDEX_LABELS=['terbeban'=>['Terbeban','warn'],'seimbang'=>['Seimbang','green'],'kurang'=>['Kurang beban','info'],'belum'=>['Belum dikira',''],'dikecualikan'=>['Dikecualikan',''],'tidak_aktif'=>['Tidak aktif','']];
const INDEX_WARNINGS=['terbeban'=>'Terbeban','kurang'=>'Kurang beban','banyak'=>'Banyak jawatan','tidak_aktif'=>'Tidak aktif tetapi memegang lantikan','tiada_masa'=>'Masa mengajar belum diisi'];
/** Tetapan indeks sekolah semasa (nilai rosak dalam settings → lalai). teach_share dalam pecahan 0–1. */
function index_settings(): array {
    $f=fn(string $k,float $min,float $max,float $def)=>is_numeric($v=setting($k)) && (float)$v>=$min && (float)$v<=$max?(float)$v:$def;
    $s=['teach_share'=>$f('index_teach_share',0,100,60)/100,'high'=>$f('index_high',1,3,1.3),'low'=>$f('index_low',0.1,1,0.7),'exam_factor'=>$f('index_exam_factor',1,2,1),'max_tasks'=>(int)$f('index_max_tasks',1,50,8)];
    if($s['low']>=$s['high']){ $s['low']=0.7; $s['high']=1.3; }
    return $s;
}
/** Sahkan & simpan tetapan indeks. $d: teach_share (int %), high, low, exam_factor (nombor), max_tasks (int). */
function index_settings_save(array $d): void {
    $num=function(string $k,float $min,float $max,string $label)use($d): float { $v=$d[$k]??null; if(!is_numeric($v) || (float)$v<$min || (float)$v>$max) throw new EngineError($label.' mesti antara '.$min.' dan '.$max.'.'); return round((float)$v,2); };
    $share=$num('teach_share',0,100,'Bahagian mengajar (%)'); $high=$num('high',1,3,'Ambang tinggi'); $low=$num('low',0.1,1,'Ambang rendah');
    $exam=$num('exam_factor',1,2,'Faktor kelas peperiksaan'); $max=$num('max_tasks',1,50,'Had bilangan jawatan');
    if($low>=$high) throw new EngineError('Ambang rendah mesti lebih kecil daripada ambang tinggi.');
    engine_setting_set(['index_teach_share'=>(string)(int)$share,'index_high'=>number_format($high,2,'.',''),'index_low'=>number_format($low,2,'.',''),'index_exam_factor'=>number_format($exam,2,'.',''),'index_max_tasks'=>(string)(int)$max]);
}
function median(array $xs): float {
    if(!$xs) return 0.0; sort($xs); $n=count($xs); $m=intdiv($n,2);
    return $n%2?(float)$xs[$m]:($xs[$m-1]+$xs[$m])/2;
}
/** Indeks beban semua guru sekolah semasa: ['teachers'=>[id=>guru + score, teaching, teach_ratio, task_ratio, index, label, warnings], 'basis'=>…, 'settings'=>…]. */
function load_index(): array {
    $s=index_settings(); $score=[];
    foreach(query('SELECT a.teacher_id,p.weight,p.level,p.term FROM assignments a JOIN positions p ON p.id=a.position_id AND p.school_id=a.school_id WHERE a.school_id='.sid())->fetchAll() as $r) $score[(int)$r['teacher_id']]=($score[(int)$r['teacher_id']]??0)+position_score($r);
    $rows=[]; $T=[]; $S=[];
    foreach(teachers_with_load() as $t){
        $id=(int)$t['id']; $t['score']=round($score[$id]??0,2); $t['teaching']=round((int)$t['teaching_periods']*((int)$t['exam_class']?$s['exam_factor']:1),2);
        $t['counted']=$t['status']==='aktif' && !(int)$t['index_exempt'];
        if($t['counted']){ $T[]=$t['teaching']; $S[]=$t['score']; }
        $rows[$id]=$t;
    }
    $div=function(array $xs): array { $m=median($xs); return $m>0?[$m,'median']:(($a=$xs?array_sum($xs)/count($xs):0)>0?[$a,'purata']:[0.0,'tiada']); };
    [$dT,$bT]=$div($T); [$dS,$bS]=$div($S);
    $share=$dT>0?($dS>0?$s['teach_share']:1.0):0.0; $ready=$dT>0 || $dS>0;
    foreach($rows as $id=>&$t){
        $tr=$dT>0?$t['teaching']/$dT:0.0; $kr=$dS>0?$t['score']/$dS:0.0;
        $t['teach_ratio']=$dT>0?round($tr,2):null; $t['task_ratio']=$dS>0?round($kr,2):null;
        $t['index']=$t['counted'] && $ready?round($share*$tr+(1-$share)*$kr,2):null;
        $t['label']=!$t['counted']?($t['status']!=='aktif'?'tidak_aktif':'dikecualikan'):($t['index']===null?'belum':($t['index']>$s['high']?'terbeban':($t['index']<$s['low']?'kurang':'seimbang')));
        $w=[]; if(in_array($t['label'],['terbeban','kurang'],true)) $w[]=$t['label'];
        if((int)$t['task_count']>$s['max_tasks']) $w[]='banyak';
        if($t['status']!=='aktif' && (int)$t['task_count']>0) $w[]='tidak_aktif';
        if($t['counted'] && $dT>0 && !(int)$t['teaching_periods']) $w[]='tiada_masa';
        $t['warnings']=$w;
    }
    unset($t);
    return ['teachers'=>$rows,'settings'=>$s,'basis'=>['teaching'=>round($dT,2),'teaching_basis'=>$bT,'score'=>round($dS,2),'score_basis'=>$bS,'share'=>$share,'counted'=>count($T)]];
}

/* ---------- Kunci API ---------- */
/** Hash kunci API: HMAC dengan subkunci tetap (bukan per sekolah) supaya kunci menentukan sekolahnya; disemak semula dengan sekolah dalam URL. */
function api_key_hash(string $token): string { return hash_hmac('sha256',$token,hash_hkdf('sha256',master_key(),32,'urustugas/api-key')); }
/** Cipta kunci untuk sekolah semasa. Pulangkan [id, token penuh] — token tidak disimpan. */
function api_key_create(string $name,string $scope): array {
    $name=trim($name); if($name==='' || mb_strlen($name)>120) throw new EngineError('Nama kunci API diperlukan (maksimum 120 aksara).');
    if(!in_array($scope,['read','write'],true)) throw new EngineError('Skop mesti read atau write.');
    $token='ut_'.bin2hex(random_bytes(32));
    query('INSERT INTO api_keys (school_id,name,token_hash,prefix,scope,created_by) VALUES ('.sid().',?,?,?,?,?)',[$name,api_key_hash($token),substr($token,0,12),$scope,current_user()['id']??null]);
    return [(int)db()->lastInsertId(),$token];
}
