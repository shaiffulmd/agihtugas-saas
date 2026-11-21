<?php
declare(strict_types=1);
/* Enjin agihan tugas UrusTugas (berbilang sekolah): guru, jawatan, lantikan dan profil sekolah untuk surat.
 * Setiap query menapis school_id=sid(); ID daripada borang/API disahkan dalam sekolah semasa sebelum digunakan.
 * Ralat enjin ialah DomainException (dipaparkan sebagai notis borang) dengan kod status HTTP untuk API. */

const CATEGORIES=['Pentadbiran','Kurikulum','HEM','Kokurikulum'];
/** Tahap & tempoh jawatan: [label, faktor skor tugas]. Lalai sokongan × tahunan = 1.0, jadi skor = pemberat. */
const POSITION_LEVELS=['utama'=>['Utama',1.5],'sokongan'=>['Sokongan',1.0],'sampingan'=>['Sampingan',0.5]];
const POSITION_TERMS=['tahunan'=>['Sepanjang tahun',1.0],'bermusim'=>['Bermusim',0.6],'program'=>['Program',0.3]];
/** Unit jawatan: label sahaja (bidang kekal empat). PPKI / Tingkatan 6 dipaparkan apabila diaktifkan di Profil Sekolah atau sudah digunakan. */
const POSITION_UNITS=['sekolah'=>'Sekolah','ppki'=>'PPKI','t6'=>'Tingkatan 6'];
function school_units(bool $refresh=false): array {
    static $cache=[]; $sid=sid(); if(!$refresh && isset($cache[$sid])) return $cache[$sid];
    $on=['sekolah'=>true]; foreach(['ppki','t6'] as $u) if(setting('unit_'.$u)==='1') $on[$u]=true;
    foreach(query('SELECT DISTINCT unit FROM positions WHERE school_id='.sid().' AND unit<>"sekolah"')->fetchAll(PDO::FETCH_COLUMN) as $u) $on[$u]=true;
    return $cache[$sid]=array_values(array_filter(array_keys(POSITION_UNITS),fn($u)=>isset($on[$u])));
}
function units_on(): bool { return count(school_units())>1; }
/** Unit guru (SET teachers.units) → senarai kunci, cth. ['t6']. */
function teacher_units(array|string|null $t): array { $v=is_array($t)?(string)($t['units']??''):(string)$t; return array_values(array_filter(explode(',',$v),fn($u)=>isset(POSITION_UNITS[$u]) && $u!=='sekolah')); }
/** Jawatan berunit PPKI / Tingkatan 6 hanya untuk guru unit itu; jawatan Sekolah untuk semua guru. */
/** Nilai SET units yang sah daripada senarai kunci atau teks "ppki,t6". */
function teacher_units_value(array|string $u): string {
    $u=is_array($u)?$u:explode(',',$u); $u=array_values(array_unique(array_filter(array_map('trim',$u),fn($x)=>$x!=='')));
    foreach($u as $x) if(!in_array($x,['ppki','t6'],true)) throw new EngineError('Unit guru mesti ppki atau t6.');
    sort($u); return implode(',',$u);
}
function unit_allows(array $teacher,array $position): bool { $u=$position['unit']??'sekolah'; return $u==='sekolah' || in_array($u,teacher_units($teacher),true); }
function unit_denied_message(array $position): string { $l=POSITION_UNITS[$position['unit']??'sekolah']??''; return 'Jawatan '.$l.' hanya untuk guru '.$l.'. Tandakan guru sebagai guru '.$l.' di Pengurusan Guru dahulu.'; }
function teacher_units_badges(array $t): string { return implode('',array_map(fn($u)=>' <span class="badge unit-badge">'.e(POSITION_UNITS[$u]).'</span>',teacher_units($t))); }
function unit_badge(?string $u): string { return $u && $u!=='sekolah' && isset(POSITION_UNITS[$u])?' <span class="badge unit-badge">'.e(POSITION_UNITS[$u]).'</span>':''; }
/** Teks unit daripada import → kunci (null jika kosong). Diterima: Sekolah/Utama, PPKI/Pendidikan Khas, T6/Tingkatan 6/F6/Form 6/PPU/STPM. */
function unit_from_text(string $v,string $where=''): ?string {
    $k=mb_strtolower(trim((string)preg_replace('/[\s\-_]+/u',' ',$v))); if($k==='') return null;
    foreach(['sekolah'=>['sekolah','utama','arus perdana','perdana'],'ppki'=>['ppki','pendidikan khas','pk','khas'],'t6'=>['t6','tingkatan 6','ting 6','ting. 6','f6','form 6','ppu','prauniversiti','pra universiti','stpm','tingkatan enam']] as $u=>$alias) if(in_array($k,$alias,true)) return $u;
    throw new EngineError($where.'unit mesti Sekolah, PPKI atau Tingkatan 6 (atau kosong).');
}
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
const ENGINE_PAGES=['guru'=>'Pengurusan Guru','subjek'=>'Mata Pelajaran','jawatan'=>'Jawatan & Pemberat','agihan'=>'Agihan Tugas','analisis'=>'Analisis Beban','auto'=>'Agihan Automatik','syarat'=>'Pusat Syarat','pukal'=>'Tambah Jawatan Pukal',
    'organisasi'=>'Carta Organisasi','buku'=>'Senarai Tugas','kelulusan'=>'Semakan & Kelulusan','surat'=>'Surat Lantikan','tugas_saya'=>'Tugas Saya','sekolah'=>'Profil Sekolah & Surat','api'=>'API & Integrasi'];
/** Halaman enjin yang dibenarkan bagi peranan. */
function engine_pages(string $role): array {
    return match($role){
        'admin'=>['guru','subjek','jawatan','agihan','analisis','auto','syarat','pukal','organisasi','buku','kelulusan','surat','sekolah','api'],
        'pk'=>['guru','subjek','jawatan','agihan','analisis','auto','syarat','pukal','organisasi','buku','kelulusan','surat'],
        'head'=>['analisis','organisasi','buku','kelulusan','surat'],
        'teacher'=>['tugas_saya'],
        default=>[]};
}
const PLANNING_PAGES=['guru','subjek','jawatan','agihan','auto','syarat','pukal','sekolah'];

/** Pentadbir & PK merancang agihan (data enjin); GB/Pengetua melulus; guru melihat tugas muktamad sendiri. */
function can_plan(?array $u=null): bool { $u??=current_user(); return in_array($u['role']??'',['admin','pk'],true); }
function require_planner(): void { if(!can_plan()) error_page(403,'Hanya Pentadbir atau Penolong Kanan boleh mengurus agihan tugas.'); }

/* ---------- Profil sekolah (surat & snapshot) ---------- */
function school_profile(): array {
    return ['school'=>school_name(),'school_type'=>school_type(),'principal'=>setting('principal'),'principal_title'=>head_title(),
        'year'=>setting('session_year')?:date('Y'),'reference'=>setting('letter_reference'),'branding'=>branding_profile(),'letter'=>letter_profile()];
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
    if(array_key_exists('units',$data)) $out['units']=teacher_units_value($data['units']);
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
    return query('SELECT p.*,a.id AS assignment_id,a.teacher_id,t.name AS teacher_name,r.name AS role_name FROM positions p LEFT JOIN assignments a ON a.position_id=p.id AND a.school_id=p.school_id LEFT JOIN teachers t ON t.id=a.teacher_id AND t.school_id=a.school_id LEFT JOIN roles r ON r.id=p.role_id AND r.school_id=p.school_id WHERE p.school_id='.sid().($id!==null?' AND p.id='.(int)$id:'').' ORDER BY FIELD(p.category,"Pentadbiran","Kurikulum","HEM","Kokurikulum"),p.name,p.id')->fetchAll();
}
function position_find(int $id): ?array { return query('SELECT * FROM positions WHERE id=? AND school_id='.sid(),[$id])->fetch()?:null; }
function position_validate(array $data): array {
    $name=trim((string)($data['name']??'')); $cat=(string)($data['category']??''); $w=$data['weight']??null;
    if($name==='' || mb_strlen($name)>160) throw new EngineError('Nama jawatan diperlukan (maksimum 160 aksara).');
    if(!in_array($cat,CATEGORIES,true)) throw new EngineError('Bidang mesti Pentadbiran, Kurikulum, HEM atau Kokurikulum.');
    // Peranan dipilih → pemberat ikut skor peranan (input pemberat diabaikan).
    $role=null; if(array_key_exists('role_id',$data) && $data['role_id']!==null){ $role=is_int($data['role_id'])?role_find($data['role_id']):null; if(!$role) throw new EngineError('Peranan tidak ditemui.',404,'not_found'); $w=(int)$role['score']; }
    if(!is_int($w) || $w<1 || $w>10) throw new EngineError('Pemberat mesti integer 1–10.');
    $out=['name'=>$name,'category'=>$cat,'weight'=>$w];
    if(array_key_exists('role_id',$data)) $out['role_id']=$role?(int)$role['id']:null;
    if(array_key_exists('level',$data)){ if(!isset(POSITION_LEVELS[$data['level']])) throw new EngineError('Tahap mesti utama, sokongan atau sampingan.'); $out['level']=$data['level']; }
    if(array_key_exists('term',$data)){ if(!isset(POSITION_TERMS[$data['term']])) throw new EngineError('Tempoh mesti tahunan, bermusim atau program.'); $out['term']=$data['term']; }
    if(array_key_exists('unit',$data)){ if(!is_string($data['unit']) || !isset(POSITION_UNITS[$data['unit']])) throw new EngineError('Unit mesti sekolah, ppki atau t6.'); $out['unit']=$data['unit']; }
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
    $p=position_find($positionId); $t=teacher_find($teacherId); if(!$p || !$t) throw new EngineError('Pilih guru dan jawatan yang sah.',404,'not_found');
    if(!unit_allows($t,$p)) throw new EngineError(unit_denied_message($p),422,'unit_mismatch');
    query('INSERT INTO assignments (school_id,teacher_id,position_id) VALUES ('.sid().',?,?) ON DUPLICATE KEY UPDATE teacher_id=VALUES(teacher_id)',[$teacherId,$positionId]);
    return (int)query('SELECT id FROM assignments WHERE school_id='.sid().' AND position_id=?',[$positionId])->fetchColumn();
}
function unassign(int $assignmentId): array {
    $a=query('SELECT a.*,p.name AS position_name FROM assignments a JOIN positions p ON p.id=a.position_id AND p.school_id=a.school_id WHERE a.id=? AND a.school_id='.sid(),[$assignmentId])->fetch();
    if(!$a) throw new EngineError('Lantikan tidak ditemui.',404,'not_found');
    query('DELETE FROM assignments WHERE id=? AND school_id='.sid(),[$assignmentId]); return $a;
}
/** Reset jawatan sekolah (Pentadbir, semasa Draf). $scope: 'pemegang' = kosongkan semua lantikan, katalog jawatan dikekalkan;
 *  'semua' = padam semua jawatan bersama lantikan & syarat jawatan (CASCADE). $leaders: turut kosongkan pemegang jawatan utama
 *  (Carta Organisasi; nama bidang Ketua Bidang dikekalkan). Pratonton agihan automatik yang belum digunakan dibuang.
 *  Revisi muktamad terdahulu (surat & Tugas Saya) tidak terjejas. Pemanggil memiliki transaksi. */
function positions_reset(string $scope,bool $leaders=false): array {
    if(!in_array($scope,['pemegang','semua'],true)) throw new EngineError('Pilih apa yang hendak direset.');
    $r=['assignments'=>query('DELETE FROM assignments WHERE school_id='.sid())->rowCount(),'positions'=>0,'leaders'=>0];
    if($scope==='semua') $r['positions']=query('DELETE FROM positions WHERE school_id='.sid())->rowCount();
    if($leaders){
        $l=leadership(); $r['leaders']=count(array_filter($l['posts']))+count(array_filter(array_column($l['bidang'],1)));
        engine_setting_set(['leadership'=>json_encode(['posts'=>array_map(fn($v)=>null,$l['posts']),'bidang'=>array_map(fn($b)=>[$b[0],null],$l['bidang'])],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE)]);
    }
    query('DELETE FROM allocation_plans WHERE school_id='.sid().' AND applied_at IS NULL');
    return $r;
}
function engine_counts(): array {
    $r=query('SELECT (SELECT COUNT(*) FROM teachers WHERE school_id='.sid().') t,(SELECT COUNT(*) FROM positions WHERE school_id='.sid().') p,(SELECT COUNT(*) FROM assignments WHERE school_id='.sid().') a')->fetch();
    return ['teachers'=>(int)$r['t'],'positions'=>(int)$r['p'],'assigned'=>(int)$r['a'],'vacant'=>(int)$r['p']-(int)$r['a']];
}

/* ---------- Guru pukal (lajur: nama, emel, kepakaran, kapasiti[, masa_mengajar]) ---------- */
/** Tajuk lajur yang diterima (dinormalkan: huruf kecil, ruang/sengkang → _). Tajuk Inggeris lama kekal diterima. */
const BULK_TEACHER_COLS=[['nama','nama_guru','name'],['emel','e_mel','e_mail','email'],['kepakaran','mata_pelajaran','mp','opsyen','specialty'],['kapasiti','kapasiti_mata','capacity'],['masa_mengajar','waktu_mengajar','masa','teaching_periods']];
function bulk_teachers_parse(string $text): array {
    if(strlen($text)>131072) throw new EngineError('Fail / teks pukal maksimum 128 KB.');
    $rows=[];
    foreach(csv_rows($text,BULK_TEACHER_COLS,3,5) as $i=>$r){
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
/* ---------- Import agihan (lajur: nama_guru, jawatan, bidang[, pemberat]) ----------
 * Satu fail → guru baharu, jawatan baharu & lantikan sekali gus (cth. salinan analisis jawatankuasa sekolah).
 * - Guru dipadan ikut nama (tanpa beza huruf / ruang berganda); nama baharu dicipta dengan kapasiti 25.
 * - Baris dengan jawatan + bidang yang sama dikumpul: seorang guru → "Nama", beberapa guru → "Nama (1/3)", "Nama (2/3)"…
 * - Jawatan yang sudah wujud dalam katalog (nama asal atau "Nama (k/n)", bidang sama) diguna semula: guru yang sudah
 *   memegangnya dilangkau, tempat kosong diisi mengikut susunan. Tempat kosong tidak cukup → ditolak (katalog tidak diubah).
 * - Pemberat kosong → skor peranan yang sepadan dengan awalan nama jawatan (cth. "AJK …" = 1), jika tiada → 1.
 * - Nama guru kosong → tempat lantikan dicipta tanpa penyandang (cth. guru yang telah bertukar); diabaikan jika jawatan sudah wujud.
 * - Unit (pilihan: Sekolah / PPKI / Tingkatan 6) → unit jawatan baharu; jawatan sedia ada yang sepadan ditanda dengan unit itu. */
const BULK_AGIHAN_COLS=[['nama_guru','nama','guru','teacher'],['jawatan','nama_jawatan','position'],['bidang','kategori','category'],['pemberat','mata','weight'],['unit','unit_jawatan']];
const BULK_AGIHAN_MAX=2000;
function bulk_name_key(string $s): string { return mb_strtolower(trim((string)preg_replace('/\s+/u',' ',$s))); }
function bulk_agihan_parse(string $text): array {
    if(strlen($text)>262144) throw new EngineError('Fail / teks import agihan maksimum 256 KB.');
    $cats=array_combine(array_map('mb_strtolower',CATEGORIES),CATEGORIES); $rows=[];
    $clean=fn(string $v)=>trim((string)preg_replace('/\s+/u',' ',$v));
    foreach(csv_rows($text,BULK_AGIHAN_COLS,3,5,BULK_AGIHAN_MAX) as $i=>$r){
        $line=$i+1; $t=$clean($r[0]); $p=$clean($r[1]); $c=$cats[mb_strtolower($clean($r[2]))]??null; $w=$clean($r[3]??'');
        if(mb_strlen($t)>160) throw new EngineError("Baris $line: nama guru maksimum 160 aksara.");
        if($p==='' || mb_strlen($p)>145) throw new EngineError("Baris $line: nama jawatan diperlukan (maksimum 145 aksara).");
        if($c===null) throw new EngineError("Baris $line: bidang mesti Pentadbiran, Kurikulum, HEM atau Kokurikulum.");
        if($w!=='' && (!ctype_digit($w) || (int)$w<1 || (int)$w>10)) throw new EngineError("Baris $line: pemberat mesti nombor bulat 1–10 (atau kosong).");
        $rows[]=['teacher'=>$t,'position'=>$p,'category'=>$c,'weight'=>$w===''?null:(int)$w,'unit'=>unit_from_text($r[4]??'',"Baris $line: ")];
    }
    if(!$rows) throw new EngineError('Tiada baris untuk diimport.');
    return $rows;
}
/** Rancang import agihan tanpa mengubah data. Pulangkan:
 *  items: [teacher, tkey, tid|null, position (nama akhir), category, weight, pid|null (tempat sedia ada), pnew|null (indeks positions_new), status baharu|sedia|kosong]
 *  teachers_new: [tkey=>nama], positions_new: [[name,category,weight,role_id]], reused (tempat sedia ada diisi), duplicates (baris berulang dilangkau). */
function bulk_agihan_plan(array $rows,bool $replace=false): array {
    $T=[]; foreach(query('SELECT id,name FROM teachers WHERE school_id='.sid())->fetchAll() as $t) $T[bulk_name_key($t['name'])]=(int)$t['id'];
    $groups=[]; $dup=0;
    foreach($rows as $r){
        $g=bulk_name_key($r['position']).'|'.$r['category'];
        $groups[$g]??=['name'=>$r['position'],'category'=>$r['category'],'weight'=>null,'unit'=>null,'teachers'=>[],'vacant'=>0];
        if(($r['unit']??null)!==null){
            if($groups[$g]['unit']!==null && $groups[$g]['unit']!==$r['unit']) throw new EngineError('Jawatan "'.$r['position'].'" ('.$r['category'].'): unit berbeza dalam fail.');
            $groups[$g]['unit']=$r['unit'];
        }
        if($r['weight']!==null){
            if($groups[$g]['weight']!==null && $groups[$g]['weight']!==$r['weight']) throw new EngineError('Jawatan "'.$r['position'].'" ('.$r['category'].'): pemberat berbeza dalam fail ('.$groups[$g]['weight'].' dan '.$r['weight'].').');
            $groups[$g]['weight']=$r['weight'];
        }
        if($r['teacher']===''){ $groups[$g]['vacant']++; continue; }
        $k=bulk_name_key($r['teacher']); if(isset($groups[$g]['teachers'][$k])){ $dup++; continue; }
        $groups[$g]['teachers'][$k]=$r['teacher'];
    }
    $P=[];
    foreach(query('SELECT p.id,p.name,p.category,p.weight,p.unit,a.teacher_id FROM positions p LEFT JOIN assignments a ON a.position_id=p.id AND a.school_id=p.school_id WHERE p.school_id='.sid().' ORDER BY p.id')->fetchAll() as $p)
        $P[bulk_name_key((string)preg_replace('/\s*\(\d+\/\d+\)$/u','',$p['name'])).'|'.$p['category']][]=$p;
    $roles=roles_all(); $match=$roles?:array_map(fn($x)=>['id'=>null,'name'=>$x[0],'score'=>$x[1]],ROLE_STANDARD);
    $items=[]; $newT=[]; $newP=[]; $reused=0; $unitSet=[]; $replaceIds=[]; $released=0;
    $tref=function(string $k,string $name)use(&$T,&$newT): ?int { if(isset($T[$k])) return $T[$k]; $newT[$k]??=$name; return null; };
    foreach($groups as $grp){
        $existing=$P[bulk_name_key($grp['name']).'|'.$grp['category']]??[];
        // Ganti: jawatan sedia ada yang sama nama + bidang dipadam (bersama lantikan & syaratnya) dan dicipta semula ikut fail.
        if($existing && $replace){
            $grp['weight']??=(int)$existing[0]['weight']; $grp['unit']??=$existing[0]['unit'];
            foreach($existing as $p){ $replaceIds[]=(int)$p['id']; if($p['teacher_id']!==null) $released++; }
            $existing=[];
        }
        if($existing){
            if($grp['unit']!==null) foreach($existing as $p) if($p['unit']!==$grp['unit']) $unitSet[(int)$p['id']]=$grp['unit'];
            $held=[]; $vacant=[];
            foreach($existing as $p){ if($p['teacher_id']!==null) $held[(int)$p['teacher_id']]=$p; else $vacant[]=$p; }
            $need=[];
            foreach($grp['teachers'] as $k=>$name){
                $tid=$tref($k,$name);
                if($tid!==null && isset($held[$tid])){ $p=$held[$tid]; $items[]=['teacher'=>$name,'tkey'=>$k,'tid'=>$tid,'position'=>$p['name'],'category'=>$grp['category'],'unit'=>$grp['unit']??$p['unit'],'weight'=>(int)$p['weight'],'pid'=>(int)$p['id'],'pnew'=>null,'status'=>'sedia']; }
                else $need[]=[$k,$name,$tid];
            }
            if(count($need)>count($vacant)) throw new EngineError('Jawatan "'.$grp['name'].'" ('.$grp['category'].') sudah ada dalam katalog dengan '.count($existing).' tempat, '.count($vacant).' kosong — fail memerlukan '.count($need).' lagi. Tanda "Ganti jawatan sedia ada" untuk mencipta semula jawatan ikut fail, tambah tempat lantikan di Jawatan & Pemberat, atau buang baris berkenaan.');
            foreach($need as $i=>[$k,$name,$tid]){ $p=$vacant[$i]; $reused++; $items[]=['teacher'=>$name,'tkey'=>$k,'tid'=>$tid,'position'=>$p['name'],'category'=>$grp['category'],'unit'=>$grp['unit']??$p['unit'],'weight'=>(int)$p['weight'],'pid'=>(int)$p['id'],'pnew'=>null,'status'=>'baharu']; }
            continue;
        }
        $role=$grp['weight']===null?role_match($grp['name'],$match):null;
        $weight=$grp['weight']??($role?(int)$role['score']:1); $n=count($grp['teachers'])+$grp['vacant']; $i=0;
        foreach([...array_map(null,array_keys($grp['teachers']),array_values($grp['teachers'])),...array_fill(0,$grp['vacant'],['',''])] as [$k,$name]){
            $i++; $title=$grp['name'].($n>1?" ($i/$n)":'');
            $newP[]=['name'=>$title,'category'=>$grp['category'],'weight'=>$weight,'role_id'=>$role && $role['id']!==null?(int)$role['id']:null,'unit'=>$grp['unit']??'sekolah'];
            $items[]=['teacher'=>$name,'tkey'=>$k,'tid'=>$name===''?null:$tref($k,$name),'position'=>$title,'category'=>$grp['category'],'unit'=>$grp['unit']??'sekolah','weight'=>$weight,'pid'=>null,'pnew'=>count($newP)-1,'status'=>$name===''?'kosong':'baharu'];
        }
    }
    return ['items'=>$items,'teachers_new'=>$newT,'positions_new'=>$newP,'reused'=>$reused,'duplicates'=>$dup,'unit_updates'=>$unitSet,'replace_ids'=>$replaceIds,'released'=>$released];
}
/** Laksana rancangan bulk_agihan_plan() (dalam transaksi pemanggil). Pulangkan [guru baharu, jawatan baharu, lantikan baharu, jawatan ditanda unit]. */
function bulk_agihan_apply(array $plan): array {
    if($ids=$plan['replace_ids']??[]){ // ganti: buang lantikan & jawatan lama dahulu (syarat jawatan dipadam bersama — CASCADE)
        $in=implode(',',array_fill(0,count($ids),'?'));
        query('DELETE FROM assignments WHERE school_id='.sid().' AND position_id IN ('.$in.')',$ids);
        query('DELETE FROM positions WHERE school_id='.sid().' AND id IN ('.$in.')',$ids);
    }
    free_guard('guru',count($plan['teachers_new'])); free_guard('jawatan',count($plan['positions_new'])); // jawatan lama sudah dipadam, jadi kiraan semasa tepat
    $tid=[]; foreach($plan['teachers_new'] as $k=>$name){ query('INSERT INTO teachers (school_id,name,email,specialty,capacity) VALUES ('.sid().',?,"","",25)',[$name]); $tid[$k]=(int)db()->lastInsertId(); }
    $pid=[]; foreach($plan['positions_new'] as $i=>$p){ query('INSERT INTO positions (school_id,name,category,weight,role_id,unit) VALUES ('.sid().',?,?,?,?,?)',[$p['name'],$p['category'],$p['weight'],$p['role_id'],$p['unit']??'sekolah']); $pid[$i]=(int)db()->lastInsertId(); }
    foreach($plan['unit_updates']??[] as $id=>$u) query('UPDATE positions SET unit=? WHERE id=? AND school_id='.sid(),[$u,(int)$id]);
    $n=0;
    foreach($plan['items'] as $it){
        $teacher=$it['tid']??($tid[$it['tkey']]??null);
        // Guru yang dilantik ke jawatan PPKI / Tingkatan 6 dalam fail ditanda sebagai guru unit itu.
        if($teacher && in_array($it['unit']??'sekolah',['ppki','t6'],true)) query("UPDATE teachers SET units=CONCAT_WS(',',NULLIF(units,''),?) WHERE id=? AND school_id=".sid()." AND NOT FIND_IN_SET(?,units)",[$it['unit'],$teacher,$it['unit']]);
        if($it['status']!=='baharu') continue;
        query('INSERT INTO assignments (school_id,teacher_id,position_id) VALUES ('.sid().',?,?)',[$teacher,$it['pid']??$pid[$it['pnew']]]); $n++;
    }
    return [count($tid),count($pid),$n,count($plan['unit_updates']??[])];
}
/* ---------- Kemas kini maklumat guru pukal (guru sedia ada) ----------
 * Tampal / Excel dengan baris tajuk: Nama Guru + mana-mana lajur Emel, Kepakaran, Kapasiti, Masa mengajar, Kelas peperiksaan.
 * Tanpa baris tajuk: Nama, Masa mengajar[, Kelas peperiksaan]. Guru dipadan ikut nama (bulk_name_key); nama tidak dijumpai
 * disenaraikan, tidak dicipta. Sel kosong = kekal. Grid masa mengajar di Pengurusan Guru guna teachers_periods_update(). */
const BULK_GURU_FIELDS=['name'=>['nama_guru','nama','guru','name'],'email'=>['emel','e_mel','e_mail','email','alamat_emel','alamat_e_mel'],
    'specialty'=>['kepakaran','mata_pelajaran','mp','opsyen','specialty'],'capacity'=>['kapasiti','kapasiti_mata','capacity'],
    'teaching_periods'=>['masa_mengajar','masa','waktu_mengajar','waktu','masa_seminggu','teaching_periods'],'exam_class'=>['kelas_peperiksaan','peperiksaan','exam_class'],
    'units'=>['unit','unit_guru','units']];
const BULK_GURU_LABELS=['email'=>'E-mel','specialty'=>'Kepakaran','capacity'=>'Kapasiti','teaching_periods'=>'Masa mengajar','exam_class'=>'Kelas peperiksaan','units'=>'Unit guru'];
function bulk_yes_no(string $v,int $line): ?bool {
    $v=mb_strtolower(trim($v)); if($v==='') return null;
    if(in_array($v,['1','ya','y','yes','true','benar','✓','/'],true)) return true;
    if(in_array($v,['0','tidak','t','no','n','false','palsu','-','x'],true)) return false;
    throw new EngineError("Baris $line: kelas peperiksaan mesti Ya / Tidak (atau kosong untuk kekal).");
}
function bulk_guru_update_parse(string $text): array {
    if(strlen($text)>262144) throw new EngineError('Fail / teks maksimum 256 KB.');
    $raw=csv_rows($text,[],2,6,1001); if(!$raw) throw new EngineError('Tiada baris untuk dikemas kini.');
    $map=[]; foreach($raw[0] as $i=>$h){ $k=bulk_header_key($h); foreach(BULK_GURU_FIELDS as $f=>$alias) if(in_array($k,$alias,true)){ if(in_array($f,$map,true)) throw new EngineError("Lajur \"$h\" berulang."); $map[$i]=$f; } }
    if(($map[0]??null)==='name'){ array_shift($raw); foreach($raw[0]??[] as $i=>$c) if(!isset($map[$i]) && trim($c)!=='') throw new EngineError('Lajur ke-'.($i+1).' tiada tajuk yang dikenali.'); }
    else $map=bulk_guru_guess_columns($raw);
    if(count($map)<2) throw new EngineError('Tiada lajur untuk dikemas kini. Tajuk diterima: Emel, Kepakaran, Kapasiti, Masa mengajar, Kelas peperiksaan.');
    if(count($raw)>1000) throw new EngineError('Maksimum 1,000 baris bagi satu import.');
    $rows=[]; $seen=[]; $emails=[];
    foreach($raw as $i=>$r){
        $line=$i+1; $set=[]; $name='';
        foreach($map as $c=>$f){ $v=trim((string)preg_replace('/\s+/u',' ',(string)($r[$c]??'')));
            if($f==='name'){ $name=$v; continue; } if($v==='') continue;
            switch($f){
                case 'email': $v=strtolower($v); if(strlen($v)>190 || !filter_var($v,FILTER_VALIDATE_EMAIL)) throw new EngineError("Baris $line: e-mel '$v' tidak sah.");
                    if(isset($emails[$v])) throw new EngineError("Baris $line: e-mel '$v' berulang dalam senarai."); $emails[$v]=true; break;
                case 'specialty': if(mb_strlen($v)>160) throw new EngineError("Baris $line: kepakaran maksimum 160 aksara."); break;
                case 'capacity': if(!ctype_digit($v) || (int)$v<1 || (int)$v>100) throw new EngineError("Baris $line: kapasiti mesti nombor bulat 1–100."); $v=(int)$v; break;
                case 'teaching_periods': if(!ctype_digit($v) || (int)$v>60) throw new EngineError("Baris $line: masa mengajar mesti nombor bulat 0–60."); $v=(int)$v; break;
                case 'exam_class': $v=(int)bulk_yes_no($v,$line); break;
                case 'units': $us=[]; if(!in_array(mb_strtolower($v),['-','tiada','sekolah','none'],true)) foreach(preg_split('/[,;\/]+/u',$v) as $part){ $u=unit_from_text($part,"Baris $line: "); if($u && $u!=='sekolah') $us[]=$u; }
                    $us=array_values(array_unique($us)); sort($us); $v=implode(',',$us); break;
            }
            $set[$f]=$v;
        }
        if($name==='') throw new EngineError("Baris $line: nama guru diperlukan.");
        $k=bulk_name_key($name); if(isset($seen[$k])) throw new EngineError("Baris $line: nama '$name' berulang dalam senarai.");
        $seen[$k]=true; $rows[]=['name'=>$name,'set'=>$set];
    }
    return $rows;
}
/** Tanpa baris tajuk: lajur pertama = nama; lajur lain diteka daripada isinya (e-mel / nombor = masa mengajar / Ya-Tidak = kelas peperiksaan). */
function bulk_guru_guess_columns(array $raw): array {
    $n=max(array_map('count',$raw)); $map=[0=>'name'];
    for($c=1;$c<$n;$c++){
        $vals=array_values(array_filter(array_map(fn($r)=>trim((string)($r[$c]??'')),$raw),fn($v)=>$v!=='')); if(!$vals) continue;
        $all=fn(callable $f)=>count(array_filter($vals,$f))===count($vals);
        $f=match(true){ $all(fn($v)=>str_contains($v,'@'))=>'email', $all('ctype_digit')=>'teaching_periods',
            $all(fn($v)=>in_array(mb_strtolower($v),['1','0','ya','y','yes','tidak','t','no','n','true','false'],true))=>'exam_class', default=>null };
        if($f===null || in_array($f,$map,true)) throw new EngineError('Lajur ke-'.($c+1).' tidak dapat dikenal pasti. Sertakan baris tajuk, cth. Nama Guru, Emel, Kepakaran, Kapasiti, Masa mengajar.');
        $map[$c]=$f;
    }
    return $map;
}
/** Kunci padat nama untuk padanan longgar: tanpa BIN/BINTI/BT/A/P, tanda baca & ruang; ABD → ABDUL. Nama dengan "@ …" turut dikunci tanpa bahagian itu. */
function teacher_name_keys(string $name): array {
    $u=mb_strtoupper($name); $out=[];
    foreach(array_unique([$u,(string)preg_replace('/\s*@.*$/u','',$u)]) as $v){
        $v=(string)preg_replace('#\bA\s*/\s*[PL]\b#u',' ',$v); $v=(string)preg_replace("/[@'`’.,]/u",' ',$v);
        $t=array_filter(preg_split('/\s+/u',trim($v))?:[],fn($w)=>!in_array($w,['BIN','BINTI','BT','BTE','BINTE','B'],true));
        $k=(string)preg_replace('/[^\p{L}\p{N}]/u','',implode('',array_map(fn($w)=>$w==='ABD'?'ABDUL':$w,$t)));
        if($k!=='') $out[]=$k;
    }
    return array_values(array_unique($out));
}
/** Pemadan nama guru: tepat (bulk_name_key) → kunci padat sama → hampir sama (≥ 90%, unik). Pulangkan fn(nama) => [guru|null, 'tepat'|'anggaran'|null]. */
function teacher_matcher(array $teachers): callable {
    $exact=[]; $compact=[]; $prim=[];
    foreach($teachers as $t){ $exact[bulk_name_key($t['name'])]=$t; $ks=teacher_name_keys($t['name']); $prim[]=[$ks[0]??'',$t]; foreach($ks as $k) $compact[$k][(int)$t['id']]=$t; }
    return function(string $name)use($exact,$compact,$prim): array {
        if($t=$exact[bulk_name_key($name)]??null) return [$t,'tepat'];
        $hit=[]; foreach(teacher_name_keys($name) as $k) foreach($compact[$k]??[] as $id=>$t) $hit[$id]=$t;
        if(count($hit)===1) return [reset($hit),'anggaran']; if(count($hit)>1) return [null,null];
        $k=teacher_name_keys($name)[0]??''; if(strlen($k)<10) return [null,null];
        $best=0.0; $second=0.0; $bt=null;
        foreach($prim as [$pk,$t]){ if($pk==='') continue; $r=1-levenshtein($k,$pk)/max(strlen($k),strlen($pk)); if($r>$best){ $second=$best; $best=$r; $bt=$t; } elseif($r>$second) $second=$r; }
        return $best>=0.9 && $best-$second>=0.05?[$bt,'anggaran']:[null,null];
    };
}
/** Padankan baris dengan guru sedia ada. Pulangkan changes [{id,name,old=>[medan=>nilai],new=>[medan=>nilai]}], same, missing, fields (lajur yang ada). */
function bulk_guru_update_plan(array $rows): array {
    $all=query('SELECT id,name,email,specialty,capacity,teaching_periods,exam_class,units FROM teachers WHERE school_id='.sid())->fetchAll(); $byEmail=[];
    foreach($all as $t) if($t['email']!=='') $byEmail[strtolower($t['email'])]=(int)$t['id'];
    $match=teacher_matcher($all); $changes=[]; $same=0; $missing=[]; $fuzzy=[]; $fields=[]; $newEmail=[]; $used=[];
    foreach($rows as $r){
        foreach(array_keys($r['set']) as $f) $fields[$f]=true;
        [$t,$how]=$match($r['name']); if(!$t || isset($used[(int)$t['id']])){ $missing[]=$r['name']; continue; } $used[(int)$t['id']]=true;
        if($how==='anggaran') $fuzzy[]=[$r['name'],$t['name']];
        $old=[]; $new=[];
        foreach($r['set'] as $f=>$v){ $cur=in_array($f,['capacity','teaching_periods','exam_class'],true)?(int)$t[$f]:(string)$t[$f];
            if($f==='units'){ $c=teacher_units($cur); sort($c); $cur=implode(',',$c); }
            if($cur!==$v){ $old[$f]=$cur; $new[$f]=$v; } }
        if(isset($new['email'])) $newEmail[$new['email']]=(int)$t['id'];
        if(!$new){ $same++; continue; }
        $changes[]=['id'=>(int)$t['id'],'name'=>$t['name'],'file_name'=>$how==='anggaran'?$r['name']:null,'old'=>$old,'new'=>$new];
    }
    // E-mel tidak boleh dikongsi dua guru (selepas kemas kini).
    $leaving=[]; foreach($changes as $c) if(isset($c['new']['email'])) $leaving[$c['id']]=true;
    foreach($newEmail as $e=>$id){ $owner=$byEmail[$e]??null; if($owner!==null && $owner!==$id && !isset($leaving[$owner])) throw new EngineError("E-mel '$e' sudah digunakan oleh guru lain.",409); }
    return ['changes'=>$changes,'same'=>$same,'missing'=>$missing,'fuzzy'=>$fuzzy,'fields'=>array_values(array_intersect(array_keys(BULK_GURU_LABELS),array_keys($fields)))];
}
/** Laksana kemas kini maklumat guru (dalam transaksi pemanggil). Pulangkan bilangan guru dikemas kini. */
function bulk_guru_update_apply(array $plan): int {
    $n=0; $cols=array_keys(BULK_GURU_LABELS);
    foreach($plan['changes'] as $c){ $set=array_intersect_key($c['new'],array_flip($cols)); if(!$set) continue;
        query('UPDATE teachers SET '.implode(',',array_map(fn($k)=>"$k=?",array_keys($set))).' WHERE id=? AND school_id='.sid(),[...array_values($set),$c['id']]); $n++; }
    return $n;
}
/** Kemas kini masa mengajar & kelas peperiksaan: $set = [teacher_id => [periods, exam(0|1)]]. Hanya baris berubah. Pulangkan bilangan guru dikemas kini. */
function teachers_periods_update(array $set): int {
    $n=0;
    foreach($set as $id=>[$p,$x]){
        if(!is_int($p) || $p<0 || $p>60) throw new EngineError('Masa mengajar mesti integer 0–60 masa seminggu.');
        $n+=query('UPDATE teachers SET teaching_periods=?,exam_class=? WHERE id=? AND school_id='.sid().' AND (teaching_periods<>? OR exam_class<>?)',[$p,$x?1:0,(int)$id,$p,$x?1:0])->rowCount();
    }
    return $n;
}
/** Normalkan tajuk lajur untuk padanan: "Masa Mengajar" / "masa-mengajar" → masa_mengajar. */
function bulk_header_key(string $h): string { return trim((string)preg_replace('/[\s\-]+/u','_',mb_strtolower(trim($h))),'_'); }
/** Baca CSV / lajur bertab. $cols: alias tajuk bagi setiap lajur (ikut kedudukan) — baris pertama dilangkau jika ia baris tajuk. Setiap baris mesti $min–$max lajur; maksimum $maxRows baris. */
function csv_rows(string $text,array $cols,int $min,int $max,int $maxRows=500): array {
    $text=(string)preg_replace('/^\xEF\xBB\xBF/','',$text);
    $first=strtok($text,"\r\n"); $delim=str_contains((string)$first,"\t")?"\t":',';
    $s=fopen('php://temp','r+'); fwrite($s,$text); rewind($s); $rows=[]; $isFirst=true;
    try {
        while(($row=fgetcsv($s,0,$delim,'"',''))!==false){
            if(count($row)===1 && trim((string)($row[0]??''))==='') continue;
            $row=array_map(fn($c)=>trim((string)$c),$row);
            if($isFirst){ $isFirst=false; $hit=count($row)>=$min && count($row)<=count($cols); foreach($row as $i=>$c) $hit=$hit && in_array(bulk_header_key($c),$cols[$i]??[],true); if($hit) continue; }
            if(count($row)<$min || count($row)>$max) throw new EngineError('Bilangan lajur tidak sah pada baris '.(count($rows)+1).'.');
            $rows[]=$row; if(count($rows)>$maxRows) throw new EngineError('Maksimum '.number_format($maxRows).' baris bagi satu import.');
        }
    } finally { fclose($s); }
    return $rows;
}
/** Baca teks tampal atau fail CSV / Excel (.xlsx) yang dimuat naik (satu sumber sahaja). */
function bulk_source(string $field='csv',string $textField='bulk_text',int $maxRows=500,int $csvBytes=131072): string {
    $text=input($textField); $f=$_FILES[$field]??null;
    if(is_array($f) && ($f['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_NO_FILE){
        if(is_array($f['error']) || $f['error']!==UPLOAD_ERR_OK || !is_uploaded_file((string)$f['tmp_name'])) throw new EngineError('Muat naik fail gagal. Cuba sekali lagi.');
        if(trim($text)!=='') throw new EngineError('Pilih satu sumber sahaja: tampal teks atau muat naik fail.');
        $tmp=(string)$f['tmp_name']; $ext=strtolower(pathinfo((string)($f['name']??''),PATHINFO_EXTENSION)); $magic=(string)file_get_contents($tmp,false,null,0,8);
        if($ext==='xls' || str_starts_with($magic,"\xD0\xCF\x11\xE0")) throw new EngineError('Format Excel lama (.xls) tidak disokong. Dalam Excel: Fail → Simpan Sebagai → Buku Kerja Excel (.xlsx) atau CSV.');
        if($ext==='xlsx' || str_starts_with($magic,"PK\x03\x04")){
            if($f['size']>2097152) throw new EngineError('Fail Excel maksimum 2 MB.');
            return xlsx_text($tmp,$maxRows);
        }
        if($f['size']>$csvBytes) throw new EngineError('Fail CSV maksimum '.intdiv($csvBytes,1024).' KB.');
        $text=(string)file_get_contents($tmp);
    }
    return $text;
}
/** Helaian pertama fail .xlsx → teks bertab (setiap sel dipetik) untuk csv_rows(). Tanpa pustaka luar: ZipArchive + SimpleXML. */
function xlsx_text(string $path,int $maxRows=500): string {
    if(!class_exists('ZipArchive')) throw new EngineError('Pelayan tidak menyokong fail Excel (sambungan PHP zip tiada). Simpan sebagai CSV atau tampal senarai.');
    $z=new ZipArchive(); if($z->open($path,ZipArchive::RDONLY)!==true) throw new EngineError('Fail Excel tidak dapat dibaca. Simpan semula sebagai .xlsx atau CSV.');
    $bad=fn()=>new EngineError('Fail Excel tidak sah atau rosak. Simpan semula sebagai .xlsx atau CSV.');
    try {
        // Had saiz XML selepas nyahmampat (elak bom zip); entiti luaran tidak dimuat (LIBXML_NONET).
        $read=function(string $n)use($z): ?string { $st=$z->statName($n); if($st===false) return null; if($st['size']>8388608) throw new EngineError('Fail Excel terlalu besar (maksimum '.number_format($maxRows).' baris).'); $x=$z->getFromName($n); return $x===false?null:$x; };
        $xml=function(?string $x)use($bad): ?SimpleXMLElement { if($x===null) return null; $e=simplexml_load_string($x,'SimpleXMLElement',LIBXML_NONET); if($e===false) throw $bad(); return $e; };
        $R='http://schemas.openxmlformats.org/officeDocument/2006/relationships';
        $sheet='xl/worksheets/sheet1.xml';
        if(($wb=$xml($read('xl/workbook.xml'))) && isset($wb->sheets->sheet[0]) && ($rels=$xml($read('xl/_rels/workbook.xml.rels')))){
            $rid=(string)$wb->sheets->sheet[0]->attributes($R)['id'];
            foreach($rels->Relationship as $r) if((string)$r['Id']===$rid){ $t=(string)$r['Target']; $sheet=str_starts_with($t,'/')?ltrim($t,'/'):'xl/'.$t; }
        }
        $ss=[]; if($sx=$xml($read('xl/sharedStrings.xml'))) foreach($sx->si as $si) $ss[]=implode('',array_map('strval',$si->xpath('.//*[local-name()="t"][not(ancestor::*[local-name()="rPh"])]')?:[]));
        $ws=$xml($read($sheet)); if(!$ws) throw $bad();
        $out=[];
        foreach($ws->sheetData->row??[] as $row){
            $cells=[]; $next=0;
            foreach($row->c as $c){
                $col=preg_match('/^([A-Z]+)/',(string)$c['r'],$m)?array_reduce(str_split($m[1]),fn($n,$ch)=>$n*26+ord($ch)-64,0)-1:$next; $next=$col+1;
                if($col>25) continue;
                $t=(string)$c['t'];
                $v=match($t){ 's'=>$ss[(int)$c->v]??'', 'inlineStr'=>implode('',array_map('strval',$c->xpath('.//*[local-name()="t"]')?:[])), 'b'=>(string)$c->v==='1'?'TRUE':'FALSE', default=>(string)$c->v };
                if(!in_array($t,['s','inlineStr','str'],true) && is_numeric($v) && (float)$v==floor((float)$v) && abs((float)$v)<1e15) $v=(string)(int)$v; // 25 / 25.0 / 2.5E1 → 25
                $cells[$col]=trim(preg_replace('/[\t\r\n]+/',' ',$v));
            }
            if(!$cells) continue;
            $line=[]; for($i=0;$i<=max(array_keys($cells));$i++) $line[]=$cells[$i]??'';
            while($line && end($line)==='') array_pop($line); // lajur kosong di hujung (format sel) diabaikan
            if(!$line) continue;
            $out[]='"'.implode("\"\t\"",array_map(fn($x)=>str_replace('"','""',$x),$line)).'"';
            if(count($out)>$maxRows+1) throw new EngineError('Maksimum '.number_format($maxRows).' baris bagi satu import.');
        }
        if(!$out) throw new EngineError('Helaian pertama fail Excel kosong.');
        return implode("\n",$out)."\n";
    } finally { $z->close(); }
}

/* ---------- Peranan & skor ----------
 * Jadual skor peranan setiap sekolah. Jawatan yang memilih peranan mengambil pemberat = skor peranan; menukar skor mengemas kini semua jawatan peranan itu.
 * Senarai standard daripada sistem asal (Sistem Agihan Tugas v4). */
const ROLE_STANDARD=[['Pengerusi',4],['Timbalan Pengerusi',3],['Naib Pengerusi',3],['Penyelaras',3],['Timbalan Ketua Warden',4],['Setiausaha',3],['Guru Kelas',4],['Setiausaha Warden',4],
    ['Ketua Panitia',5],['Bendahari',3],['Ketua Guru Penasihat',3],['Warden',3],['Ketua Guru Pengawas',3],['Penolong Setiausaha',2],['Penolong Bendahari',2],['Penolong Ketua Guru Penasihat',2],
    ['AJK Jadual Waktu',2],['AJK',1],['Juruaudit',1],['Guru Penasihat',1]];
function roles_all(): array {
    return query('SELECT r.id,r.name,r.score,r.sort,(SELECT COUNT(*) FROM positions p WHERE p.school_id=r.school_id AND p.role_id=r.id) AS positions FROM roles r WHERE r.school_id='.sid().' ORDER BY r.sort,r.name')->fetchAll();
}
function role_find(int $id): ?array { return query('SELECT * FROM roles WHERE id=? AND school_id='.sid(),[$id])->fetch()?:null; }
/** Cipta (id 0) atau kemas kini peranan. Skor berubah → pemberat jawatan peranan itu dikemas kini. Pulangkan [id, bilangan jawatan dikemas kini]. */
function role_save(int $id,array $d): array {
    $name=trim(preg_replace('/\s+/u',' ',(string)($d['name']??''))); $score=$d['score']??null;
    if($name==='' || mb_strlen($name)>60) throw new EngineError('Nama peranan diperlukan (maksimum 60 aksara).');
    if(!is_int($score) || $score<1 || $score>10) throw new EngineError('Skor peranan mesti integer 1–10.');
    if($id && !role_find($id)) throw new EngineError('Peranan tidak ditemui.',404,'not_found');
    if(query('SELECT 1 FROM roles WHERE school_id='.sid().' AND name=? AND id<>?',[$name,$id])->fetchColumn()) throw new EngineError('Peranan "'.$name.'" sudah ada.',409);
    if(!$id){ query('INSERT INTO roles (school_id,name,score,sort) VALUES ('.sid().',?,?,(SELECT n FROM (SELECT COALESCE(MAX(sort),0)+1 AS n FROM roles WHERE school_id='.sid().') x))',[$name,$score]); return [(int)db()->lastInsertId(),0]; }
    query('UPDATE roles SET name=?,score=? WHERE id=? AND school_id='.sid(),[$name,$score,$id]);
    return [$id,query('UPDATE positions SET weight=? WHERE school_id='.sid().' AND role_id=? AND weight<>?',[$score,$id,$score])->rowCount()];
}
/** Padam peranan; jawatan berkaitan kekal dengan pemberat semasa (tanpa peranan). */
function role_delete(int $id): array {
    $r=role_find($id); if(!$r) throw new EngineError('Peranan tidak ditemui.',404,'not_found');
    query('UPDATE positions SET role_id=NULL WHERE school_id='.sid().' AND role_id=?',[$id]); query('DELETE FROM roles WHERE id=? AND school_id='.sid(),[$id]); return $r;
}
/** Tambah peranan standard yang belum ada (nama tanpa beza huruf). Pulangkan bilangan ditambah. */
function roles_install_standard(): int {
    $have=array_flip(array_map('mb_strtolower',array_column(roles_all(),'name'))); $n=0;
    foreach(ROLE_STANDARD as $i=>[$name,$score]) if(!isset($have[mb_strtolower($name)])){ query('INSERT INTO roles (school_id,name,score,sort) VALUES ('.sid().',?,?,?)',[$name,$score,$i+1]); $n++; }
    return $n;
}
/** Peranan yang sepadan dengan nama jawatan: nama jawatan bermula dengan nama peranan (padanan terpanjang). */
function role_match(string $position,array $roles): ?array {
    $p=mb_strtolower(trim($position)); $best=null;
    foreach($roles as $r){ $n=mb_strtolower($r['name']); if(($p===$n || str_starts_with($p,$n.' ')) && (!$best || mb_strlen($n)>mb_strlen($best['name']))) $best=$r; }
    return $best;
}
/** Cadangan peranan bagi jawatan yang belum berperanan: [{position, role, weight_before, weight_after, standard}].
 *  standard = mata jadual jawatan standard jika nama jawatan sepadan (paparan tidak menandanya secara lalai supaya mata standard tidak ditimpa). */
function roles_match_preview(): array {
    $roles=roles_all(); $out=[]; if(!$roles) return [];
    foreach(query('SELECT id,name,category,weight FROM positions WHERE school_id='.sid().' AND role_id IS NULL ORDER BY name')->fetchAll() as $p)
        if($r=role_match($p['name'],$roles)) $out[]=['position'=>$p,'role'=>$r,'weight_before'=>(int)$p['weight'],'weight_after'=>(int)$r['score'],'standard'=>position_template_points($p['name'],$p['category'])];
    return $out;
}
/** Guna cadangan bagi jawatan terpilih (dikira semula di pelayan). Pulangkan bilangan jawatan dikemas kini. */
function roles_match_apply(array $ids): int {
    $want=array_flip(array_map('intval',$ids)); $n=0;
    foreach(roles_match_preview() as $m) if(isset($want[(int)$m['position']['id']])){ query('UPDATE positions SET role_id=?,weight=? WHERE id=? AND school_id='.sid(),[(int)$m['role']['id'],$m['weight_after'],(int)$m['position']['id']]); $n++; }
    if(!$n) throw new EngineError('Tiada jawatan dipilih untuk dipadankan.');
    return $n;
}

/* ---------- Jawatan standard ----------
 * Jadual jawatan standard (Panduan Mata Sasaran SMK) untuk diimport secara terpilih ke katalog jawatan. Wajaran 1–5 mata:
 * 4–5 Berat, 3 Sederhana, 1–2 Ringan. Jawatan berganda dalam jadual asal (cth. "SU Kurikulum / HEM / Kokurikulum / PIBG") dipecahkan
 * kepada satu rekod setiap jawatan kerana setiap rekod ialah satu tempat lantikan.
 * Item: [nama, mata, kembang, bilangan lalai]. kembang: '' = satu rekod, atau berbilang jika bilangan lalai diberi (1 → nama asal, >1 → "Nama (1/3)"),
 * 'tingkatan' = Tingkatan 1–5, 'subjek' = satu bagi setiap MP dalam katalog Mata Pelajaran sekolah. */
const POSITION_TEMPLATES=[
    'smk'=>['Jawatan standard SMK','Berdasarkan Panduan Julat Mata Sasaran SMK. Mata (wajaran) dimasukkan sebagai pemberat jawatan; semua boleh disunting selepas ditambah.','SMK',[
        'Pentadbiran'=>[
            ['SU Kurikulum',5],['SU Hal Ehwal Murid',5],['SU Kokurikulum',5],['SU PIBG',5],['Penyelaras Jadual Waktu',5],['Guru Data (EMIS / e-OPERASI)',5],
            ['SU SKPM Kualiti@Sekolah',4],['SU TS25',4],['SU Ladap (SPLKPM)',4],
            ['Penyelaras Bilik Khas',3,'',1],['Penyelaras Makmal',3,'',1],['Penyelaras Bengkel',3,'',1],['Penyelaras PSS',3],
            ['AJK Siaraya',1],['AJK Dokumentasi',1],['AJK Kebajikan Staf',1],
        ],
        'Kurikulum'=>[
            ['SU Peperiksaan Awam (SPM)',5],['SU Peperiksaan Awam (STPM)',5],['SU Peperiksaan Dalaman',5],['SU PBD (idMe)',5],
            ['Ketua Panitia',4,'subjek'],['Penyelaras Tingkatan',3,'tingkatan'],['Setiausaha Panitia',2,'subjek'],['Penyelaras Program Akademik',2,'',1],
        ],
        'HEM'=>[
            ['Guru Tingkatan',5,'',1],['Ketua Guru Disiplin',4],['Penyelaras SPBT',4],['Penyelaras Biasiswa',4],
            ['Penyelaras 3K',3],['Penyelaras Kantin',3],['Penyelaras Kebajikan Murid',3],
            ['Guru Bertugas Mingguan',2,'',1],['AJK Ziarah Cakna',1],['AJK PPDa',1],['AJK KPJ',1],
        ],
        'Kokurikulum'=>[
            ['Setiausaha Sukan',5],['Penyelaras PAJSK',5],['Ketua Guru Penasihat Unit Beruniform',3,'',1],['Ketua Guru Penasihat Kelab Sukan & Permainan',3,'',1],
            ['Jurulatih Pasukan Sekolah (MSSD / MSSN / Koakademik)',3,'',1],['Ketua Guru Rumah Sukan',2,'',4],['Guru Penasihat Kelab / Persatuan / Uniform',1,'',1],
        ],
    ]],
];
/** Panduan julat mata sasaran (kapasiti) setiap gred dan had jawatan berat bagi templat. */
const POSITION_TEMPLATE_GUIDE=['smk'=>['capacity'=>[['Guru Akademik Biasa (GAB)',16,20],['Guru Kanan Mata Pelajaran (GKMP)',12,15],['Penolong Kanan / Pengetua',8,12]],'heavy_threshold'=>5,'max_heavy'=>1]];
/** Kategori beban bagi mata 1–5 (jadual standard). */
function template_load_label(int $w): string { return $w>=4?'Berat':($w===3?'Sederhana':'Ringan'); }
/** Nama rekod yang dijana bagi satu item templat. $count dipakai untuk item tanpa kembang; $subjects = nama MP katalog. */
function position_template_names(array $item,int $count,array $subjects): array {
    [$name,,$exp]=$item+[2=>''];
    if($exp==='tingkatan') return array_map(fn($n)=>"$name $n",range(1,5)); // "Penyelaras Tingkatan" → "Penyelaras Tingkatan 1"
    if($exp==='subjek') return array_map(fn($s)=>"$name $s",$subjects);
    if($count<1 || $count>50) throw new EngineError('Bilangan bagi "'.$name.'" mesti 1–50.');
    return $count===1?[$name]:array_map(fn($n)=>"$name ($n/$count)",range(1,$count));
}
/** Mata jadual standard bagi jawatan (nama + bidang) dalam templat jenis sekolah semasa, atau null.
 *  Mengenali bentuk jana: "Nama", "Nama (2/3)", "Penyelaras Tingkatan 4", "Ketua Panitia <MP>". */
function position_template_points(string $name,string $category): ?int {
    $n=mb_strtolower(trim($name)); $base=(string)preg_replace('/ \(\d+\/\d+\)$/','',$n);
    foreach(POSITION_TEMPLATES as [,,$type,$groups]){
        if($type!==school_type()) continue;
        foreach($groups[$category]??[] as $item){
            [$t,$w,$exp]=$item+[2=>'']; $t=mb_strtolower($t);
            if(match($exp){'tingkatan'=>(bool)preg_match('/^'.preg_quote($t,'/').' [1-5]$/u',$n),'subjek'=>str_starts_with($n,$t.' ') && mb_strlen($n)>mb_strlen($t)+1,default=>$base===$t}) return (int)$w;
        }
    }
    return null;
}
/** Import item terpilih daripada templat jawatan. $pick: senarai "Bidang|indeks"; $counts: ["Bidang|indeks"=>bilangan].
 *  Rekod yang sudah ada (nama + bidang, tanpa beza huruf) dilangkau. Pulangkan [ditambah, dilangkau]. */
function positions_import_template(string $key,array $pick,array $counts=[]): array {
    $tpl=POSITION_TEMPLATES[$key]??null; if(!$tpl) throw new EngineError('Senarai jawatan standard tidak sah.');
    if($tpl[2]!==school_type()) throw new EngineError('Senarai "'.$tpl[0].'" hanya untuk sekolah '.$tpl[2].'.');
    $want=array_flip(array_map('strval',$pick)); $subjects=subject_names(); $rows=[]; $skipped=0;
    $have=[]; foreach(query('SELECT name,category FROM positions WHERE school_id='.sid())->fetchAll() as $p) $have[mb_strtolower($p['name']).'|'.$p['category']]=true;
    foreach($tpl[3] as $cat=>$items) foreach($items as $i=>$item){
        $id=$cat.'|'.$i; if(!isset($want[$id])) continue;
        $c=isset($item[3])?($counts[$id]??$item[3]):1; // bilangan hanya bagi jawatan berbilang if(!is_int($c)) $c=filter_var($c,FILTER_VALIDATE_INT); if($c===false) throw new EngineError('Bilangan bagi "'.$item[0].'" mesti nombor bulat.');
        foreach(position_template_names($item,(int)$c,$subjects) as $name){
            $k=mb_strtolower($name).'|'.$cat; if(isset($have[$k])){ $skipped++; continue; }
            if(mb_strlen($name)>160) continue; // nama MP sangat panjang
            $have[$k]=true; $rows[]=[$name,$cat,(int)$item[1]];
        }
    }
    if(!$rows) throw new EngineError($skipped?'Semua jawatan yang dipilih sudah ada dalam katalog.':'Tiada jawatan dipilih.'.(!$subjects?' (Ketua / Setiausaha Panitia memerlukan senarai Mata Pelajaran.)':''));
    if(count($rows)>1000) throw new EngineError('Maksimum 1,000 jawatan bagi satu import.');
    free_guard('jawatan',count($rows));
    foreach($rows as [$n,$cat,$w]) query('INSERT INTO positions (school_id,name,category,weight) VALUES ('.sid().',?,?,?)',[$n,$cat,$w]);
    return [count($rows),$skipped];
}

/* ---------- Mata pelajaran ----------
 * Katalog per sekolah untuk memilih kepakaran guru. Kepakaran guru disimpan sebagai teks "MP1, MP2" (serasi API, CSV & syarat auto-agihan).
 * Templat di bawah ialah senarai rujukan KPM/BPK untuk diimport secara terpilih — sekolah boleh menambah, menyunting dan memadam sendiri. */
const SUBJECT_TEMPLATES=[
    'kssr'=>['KSSR (Semakan 2017) — sekolah rendah','Tahun 2–6 pada 2027; Tahun 1–6 sebelum 2027.','SK',[
        'Bahasa'=>['Bahasa Melayu','Bahasa Inggeris','Bahasa Cina','Bahasa Tamil','Bahasa Arab','Bahasa Iban','Bahasa Kadazandusun','Bahasa Semai'],
        'Teras'=>['Matematik','Sains','Sejarah','Reka Bentuk dan Teknologi','Teknologi Maklumat dan Komunikasi'],
        'Agama & Moral'=>['Pendidikan Islam','Pendidikan Moral'],
        'Kesenian & Kesihatan'=>['Pendidikan Jasmani','Pendidikan Kesihatan','Pendidikan Seni Visual','Pendidikan Muzik'],
        'Program khas'=>['Pemulihan Khas','Pendidikan Khas']]],
    'kp2027_sr'=>['Kurikulum Persekolahan 2027 — sekolah rendah','Bermula Tahun 1 pada 2027 (senarai BPK, 30 Ogos 2025).','SK',[
        'Bahasa'=>['Bahasa Melayu','Bahasa Inggeris','Bahasa Cina','Bahasa Tamil','Bahasa Isyarat Malaysia'],
        'Teras'=>['Matematik','Sains','Sejarah'],
        'Pembelajaran bersepadu'=>['Alam dan Manusia: Pembelajaran Bersepadu','Eksplorasi Seni dan Dunia: Pembelajaran Bersepadu','Eksplorasi Sains dan Teknologi: Pembelajaran Bersepadu'],
        'Agama & Moral'=>['Pendidikan Islam','Pendidikan Moral'],
        'Kesenian & Kesihatan'=>['Pendidikan Jasmani']]],
    'kssm'=>['KSSM — sekolah menengah','Tingkatan 2–5 pada 2027; Tingkatan 1–5 sebelum 2027. Termasuk elektif menengah atas.','SMK',[
        'Bahasa'=>['Bahasa Melayu','Bahasa Inggeris','Bahasa Arab','Bahasa Cina','Bahasa Tamil','Bahasa Iban','Bahasa Kadazandusun','Bahasa Semai','Kesusasteraan Melayu Komunikatif','Literature in English','Kesusasteraan Cina','Kesusasteraan Tamil'],
        'Teras'=>['Matematik','Sains','Sejarah','Geografi','Reka Bentuk dan Teknologi','Asas Sains Komputer'],
        'Agama & Moral'=>['Pendidikan Islam','Pendidikan Moral'],
        'Kesenian & Kesihatan'=>['Pendidikan Jasmani dan Kesihatan','Pendidikan Seni Visual','Pendidikan Muzik'],
        'STEM (elektif)'=>['Matematik Tambahan','Fizik','Kimia','Biologi','Sains Tambahan','Sains Komputer','Reka Cipta','Grafik Komunikasi Teknikal','Sains Rumah Tangga','Sains Sukan','Pertanian'],
        'Kemanusiaan & ikhtisas (elektif)'=>['Ekonomi','Perniagaan','Prinsip Perakaunan'],
        'Pengajian Islam (elektif)'=>['Tasawwur Islam','Pendidikan Al-Quran dan Al-Sunnah','Pendidikan Syariah Islamiah','Usul Al-Din','Al-Syariah','Manahij Al-Ulum Al-Islamiah','Al-Adab Wa Al-Balaghah',"Al-Lughah Al-Arabiah Al-Mu'asirah",'Hifz Al-Quran','Maharat Al-Quran'],
        'Program khas'=>['Pendidikan Khas']]],
    'kp2027_sm'=>['Kurikulum Persekolahan 2027 — sekolah menengah (awal)','Bermula Tingkatan 1 pada 2027. Senarai awal: senarai penuh BPK belum diterbitkan — semak DSKP.','SMK',[
        'Bahasa'=>['Bahasa Melayu','Bahasa Inggeris','Bahasa Isyarat Malaysia','Bahasa Arab Komunikasi'],
        'Teras'=>['Matematik','Sains','Sejarah','Teknologi dan Digital'],
        'Agama & Moral'=>['Pendidikan Islam','Pendidikan Moral'],
        'Kesenian & Kesihatan'=>['Pendidikan Jasmani','Pendidikan Seni Visual','Pendidikan Muzik']]],
];
function subjects_all(): array { return query('SELECT id,name,grp FROM subjects WHERE school_id='.sid().' ORDER BY grp,name')->fetchAll(); }
function subject_find(int $id): ?array { return query('SELECT * FROM subjects WHERE id=? AND school_id='.sid(),[$id])->fetch()?:null; }
/** Kepakaran guru (teks) → senarai nama MP. */
function specialty_tokens(string $s): array { return array_values(array_filter(array_map('trim',explode(',',$s)),fn($x)=>$x!=='')); }
/** Bilangan guru yang memegang setiap MP: [nama huruf kecil => n]. */
/** Guru mengikut MP (nama MP huruf kecil => [[id, name], …], tersusun ikut nama) — padanan tepat token kepakaran. */
function subject_teachers(): array {
    $n=[]; foreach(query('SELECT id,name,specialty FROM teachers WHERE school_id='.sid().' AND specialty<>"" ORDER BY name,id')->fetchAll() as $t)
        foreach(array_unique(array_map('mb_strtolower',specialty_tokens((string)$t['specialty']))) as $k) $n[$k][]=['id'=>(int)$t['id'],'name'=>(string)$t['name']];
    return $n;
}
/** Bilangan guru bagi setiap MP (nama huruf kecil => bilangan). */
function subject_usage(): array { return array_map('count',subject_teachers()); }
function subject_validate(array $d): array {
    $name=trim(preg_replace('/\s+/u',' ',(string)($d['name']??''))); $grp=trim((string)($d['grp']??''));
    if($name==='' || mb_strlen($name)>100) throw new EngineError('Nama mata pelajaran diperlukan (maksimum 100 aksara).');
    if(str_contains($name,',')) throw new EngineError('Nama mata pelajaran tidak boleh mengandungi koma (koma memisahkan kepakaran guru).');
    if(mb_strlen($grp)>60) throw new EngineError('Kumpulan maksimum 60 aksara.');
    return ['name'=>$name,'grp'=>$grp];
}
/** Cipta (id 0) atau kemas kini MP. Menukar nama turut menukar kepakaran guru yang memegang nama lama. Pulangkan [id, bilangan guru dikemas kini]. */
function subject_save(int $id,array $d): array {
    $v=subject_validate($d); $old=null;
    if($id && !($old=subject_find($id))) throw new EngineError('Mata pelajaran tidak ditemui.',404,'not_found');
    if(query('SELECT 1 FROM subjects WHERE school_id='.sid().' AND name=? AND id<>?',[$v['name'],$id])->fetchColumn()) throw new EngineError('Mata pelajaran "'.$v['name'].'" sudah ada.',409);
    if(!$id){ query('INSERT INTO subjects (school_id,name,grp) VALUES ('.sid().',?,?)',[$v['name'],$v['grp']]); return [(int)db()->lastInsertId(),0]; }
    query('UPDATE subjects SET name=?,grp=? WHERE id=? AND school_id='.sid(),[$v['name'],$v['grp'],$id]); $n=0;
    if($old['name']!==$v['name']) foreach(query('SELECT id,specialty FROM teachers WHERE school_id='.sid().' AND specialty LIKE ?',['%'.addcslashes($old['name'],'%_\\').'%'])->fetchAll() as $t){
        $tok=specialty_tokens((string)$t['specialty']); $new=array_map(fn($x)=>mb_strtolower($x)===mb_strtolower($old['name'])?$v['name']:$x,$tok);
        if($new===$tok) continue; $sp=implode(', ',array_unique($new));
        if(mb_strlen($sp)>160) throw new EngineError('Kepakaran guru melebihi 160 aksara selepas nama ditukar. Pendekkan nama.');
        query('UPDATE teachers SET specialty=? WHERE id=? AND school_id='.sid(),[$sp,(int)$t['id']]); $n++;
    }
    return [$id,$n];
}
/* ---------- Senarai tugas mengikut bidang & jawatankuasa (Buku Panduan Pengurusan) ----------
 * Nama jawatan dipecah kepada peranan + jawatankuasa: "Setiausaha JK Disiplin (1/2)" → [Setiausaha, JK Disiplin]. */
const BOOK_ROLES=['Pengerusi'=>1,'Timbalan Pengerusi'=>2,'Naib Pengerusi'=>3,'Ketua Panitia'=>4,'Ketua Unit'=>4,'Ketua Bidang'=>4,'Ketua Warden'=>4,'Ketua Guru Penasihat'=>4,'Ketua'=>4,
    'Timbalan Ketua'=>5,'Naib Ketua'=>5,'Penyelaras'=>6,'Penolong Ketua'=>7,'Setiausaha'=>8,'Penolong Setiausaha'=>9,'Bendahari'=>10,'Penolong Bendahari'=>11,'Juruaudit'=>12,
    'Penolong Ketua Guru Penasihat'=>13,'Guru Penasihat'=>14,'Penasihat'=>14,'Jurulatih'=>15,'Warden'=>15,'Pegawai'=>16,'Guru Kelas'=>17,'AJK'=>30,'Ahli'=>30];
/** Pisah "peranan jawatankuasa" pada kata kunci jawatankuasa pertama di luar kurungan: "AJK (Ketua Unit X) JK Y" → ["AJK (Ketua Unit X)", "JK Y"].
 *  Pulangkan null jika tiada kata kunci (atau teks bermula dengan kata kunci — keseluruhannya jawatankuasa). */
function book_split(string $s,bool $rumah=true): ?array {
    $kw='/^(?:JK|Jawatankuasa|Panitia|Unit|Kelab|Persatuan|Sekretariat|Pasukan|Badan|Majlis'.($rumah?'|Rumah':'').')\b/u'; $d=0;
    if(preg_match($kw,$s)) return null;
    for($i=0,$len=strlen($s);$i<$len;$i++){ $c=$s[$i];
        if($c==='(') $d++; elseif($c===')') $d=max(0,$d-1);
        elseif($c===' ' && $d===0 && $i>0 && preg_match($kw,substr($s,$i+1))) return mb_strlen($p=trim(substr($s,0,$i)))<=90?[$p,trim(substr($s,$i+1))]:null;
    }
    return null;
}
/** [peranan, jawatankuasa, kedudukan] bagi nama jawatan. Jawatan tanpa peranan dikenali → jawatankuasa = nama jawatan.
 *  Catatan dalam kurungan kekal bersama peranan: "AJK (SU Disiplin) JK Induk HEM" → [AJK (SU Disiplin), JK Induk HEM]. */
function position_committee(string $name): array {
    $n=trim((string)preg_replace('/\s*\(\d+\/\d+\)$/u','',$name)); $best=null;
    foreach(BOOK_ROLES as $r=>$rank) if((mb_strtolower($n)===mb_strtolower($r) || str_starts_with(mb_strtolower($n),mb_strtolower($r).' ')) && (!$best || mb_strlen($r)>mb_strlen($best))) $best=$r;
    if(!$best){ $x=book_split($n,false); return $x?[$x[0],$x[1],20]:[$n,$n,20]; }
    $role=$best; $rest=trim(mb_substr($n,mb_strlen($best)));
    if($best==='Ketua Panitia') $rest='Panitia '.$rest; elseif($best==='Guru Kelas') { $role='Guru Kelas '.$rest; $rest='Guru Kelas'; }
    // "AJK (BOSS) JK Penyelenggaraan …" → peranan "AJK (BOSS)", jawatankuasa "JK Penyelenggaraan …".
    elseif($x=book_split($rest)){ $role.=' '.$x[0]; $rest=$x[1]; }
    return [$role,$rest!==''?$rest:$n,BOOK_ROLES[$best]];
}
/* ---------- Jawatan bertindan ----------
 * Jawatan yang sebenarnya sama tetapi wujud sebagai rekod berasingan, cth. baki import lama "AJK JK Kewangan dan audit" bersama
 * "AJK JK Kewangan dan Audit (JPKA)", atau guru yang sama memegang dua tempat bagi jawatan yang sama ("AJK JK Disiplin (2/11)" & "(5/11)").
 * Kunci = peranan (termasuk catatan dalam kurungan) + jawatankuasa (tanpa singkatan dalam kurungan di hujung, tanpa JK/Jawatankuasa/
 * Pengurusan/Unit) + unit; jawatankuasa yang hampir sama (token subset ≥ 2 perkataan, atau ≥ 90% serupa) bagi peranan yang sama digabung. */
function overlap_norm(string $s): string {
    $s=mb_strtolower(trim((string)preg_replace('/\s*\(\d+\/\d+\)$/u','',$s)));
    $s=str_replace(['&','@'],[' dan ',' '],$s);
    return trim((string)preg_replace(['/[^\p{L}\p{N}]+/u','/\s+/u'],' ',$s));
}
function overlap_role(string $role): string {
    $r=' '.overlap_norm($role).' ';
    foreach([' pen ketua '=>' penolong ketua ',' pen setiausaha '=>' penolong setiausaha ',' pen su '=>' penolong setiausaha ',' psu '=>' penolong setiausaha ',' su '=>' setiausaha ',' pen bendahari '=>' penolong bendahari ',' peg '=>' pegawai '] as $a=>$b) $r=str_replace($a,$b,$r);
    return trim($r);
}
function overlap_committee(string $com): string {
    $c=trim((string)preg_replace('/\s+(PPU|PPKI|T6|Tingkatan 6)$/iu','',$com));          // unit sudah dalam kunci
    $c=trim((string)preg_replace('/\s*\([^()]*\)\s*$/u','',$c)); if($c==='') $c=$com;    // "(JPKA)", "(SPBT)" di hujung
    $w=array_filter(explode(' ',overlap_norm($c)),fn($x)=>!in_array($x,['jk','jawatankuasa','pengurusan','unit','dan','program','panitia','kelab','persatuan'],true));
    // Jenis (JK / kelab & persatuan / panitia / lain) dibandingkan dahulu: "JK Bahasa Melayu Komunikasi" ≠ "Persatuan Bahasa Melayu".
    $kind=preg_match('/^(JK|Jawatankuasa)\b/iu',$c)?'jk':(preg_match('/^(Kelab|Persatuan)\b/iu',$c)?'kelab':(preg_match('/^Panitia\b/iu',$c)?'panitia':'lain'));
    return $kind.':'.implode(' ',$w);
}
function overlap_similar(string $a,string $b): bool {
    if($a===$b) return true;
    [$ka,$a]=explode(':',$a,2)+[1=>'']; [$kb,$b]=explode(':',$b,2)+[1=>'']; if($ka!==$kb || $a==='' || $b==='') return $ka===$kb && $a===$b;
    $ta=array_unique(explode(' ',$a)); $tb=array_unique(explode(' ',$b)); [$s,$l]=count($ta)<=count($tb)?[$ta,$tb]:[$tb,$ta];
    if(count($s)>=2 && !array_diff($s,$l) && preg_replace('/\D+/','',$a)===preg_replace('/\D+/','',$b)) return true;
    if(preg_replace('/\D+/','',$a)!==preg_replace('/\D+/','',$b)) return false;   // "Ting 2" ≠ "Ting 3", "Makmal Sains 1" ≠ "2"
    similar_text($a,$b,$pct); return $pct>=90;
}
/** Kumpulan jawatan bertindan: [{reason, positions => [baris positions_with_holder()]}]. */
function positions_overlap(?array $positions=null): array {
    $positions??=positions_with_holder(); $by=[];
    foreach($positions as $p){ [$role,$com]=position_committee((string)$p['name']); $by[overlap_role($role).'|'.($p['unit']??'sekolah')][overlap_committee($com)][]=$p; }
    $out=[];
    foreach($by as $rk=>$coms){
        // gabung jawatankuasa yang hampir sama (union-find ringkas)
        $keys=array_keys($coms); $root=array_combine($keys,$keys); $find=function($k)use(&$root,&$find){ return $root[$k]===$k?$k:($root[$k]=$find($root[$k])); };
        foreach($keys as $i=>$a) foreach(array_slice($keys,$i+1) as $b) if(overlap_similar((string)$a,(string)$b)) $root[$find($b)]=$find($a);
        $cl=[]; foreach($keys as $k) $cl[$find($k)]=array_merge($cl[$find($k)]??[],$coms[$k]);
        foreach($cl as $ps){
            if(count($ps)<2) continue;
            $names=array_unique(array_map(fn($p)=>overlap_norm((string)$p['name']),$ps));
            $held=array_count_values(array_filter(array_map(fn($p)=>$p['teacher_id']!==null?(int)$p['teacher_id']:null,$ps),fn($x)=>$x!==null));
            $twice=array_keys(array_filter($held,fn($n)=>$n>1));
            // Cadangan padam: nama dengan jawatan paling baharu (ID terbesar, biasanya import terkini) dikekalkan; tempat dalam nama lain
            // dicadangkan hanya jika kosong atau gurunya sudah ada dalam nama yang dikekalkan. Guru sama dua kali: tempat pertama dikekalkan.
            $suggest=[]; $keepName=null;
            if(count($names)>1){
                $byName=[]; foreach($ps as $p) $byName[overlap_norm((string)$p['name'])][]=$p;
                uasort($byName,fn($x,$y)=>max(array_column($y,'id'))<=>max(array_column($x,'id'))); $keepName=array_key_first($byName);
                $kept=array_flip(array_filter(array_map(fn($p)=>$p['teacher_id'],$byName[$keepName]),fn($x)=>$x!==null));
                foreach($byName as $k=>$list) if($k!==$keepName) foreach($list as $p) if($p['teacher_id']===null || isset($kept[$p['teacher_id']])) $suggest[]=(int)$p['id'];
            }
            $seen=[]; usort($ps,fn($x,$y)=>[$keepName!==null && overlap_norm((string)$y['name'])===$keepName,-(int)$y['id']]<=>[$keepName!==null && overlap_norm((string)$x['name'])===$keepName,-(int)$x['id']]);
            foreach($ps as $p) if($p['teacher_id']!==null && in_array((int)$p['teacher_id'],$twice,true)){ if(isset($seen[$p['teacher_id']])) $suggest[]=(int)$p['id']; $seen[$p['teacher_id']]=true; }
            $suggest=array_values(array_unique($suggest));
            if(count($names)>1) $out[]=['reason'=>'Nama hampir sama ('.count($names).' variasi)','dup_teachers'=>$twice,'positions'=>$ps,'suggest'=>$suggest];
            elseif($twice) $out[]=['reason'=>'Guru yang sama memegang lebih daripada satu tempat','dup_teachers'=>$twice,'suggest'=>$suggest,'positions'=>array_values(array_filter($ps,fn($p)=>$p['teacher_id']!==null && in_array((int)$p['teacher_id'],$twice,true)))];
        }
    }
    return $out;
}
/** Padam jawatan dipilih bersama lantikannya (syarat jawatan dipadam bersama — CASCADE). Pulangkan [jawatan, lantikan]. Pemanggil memiliki transaksi. */
function positions_delete_many(array $ids): array {
    $ids=array_values(array_unique(array_filter(array_map('intval',$ids)))); if(!$ids) throw new EngineError('Pilih sekurang-kurangnya satu jawatan.');
    $in=implode(',',array_fill(0,count($ids),'?'));
    $a=query('DELETE FROM assignments WHERE school_id='.sid().' AND position_id IN ('.$in.')',$ids)->rowCount();
    $p=query('DELETE FROM positions WHERE school_id='.sid().' AND id IN ('.$in.')',$ids)->rowCount();
    if($p!==count($ids)) throw new EngineError('Sebahagian jawatan tidak ditemui. Muat semula halaman.',404,'not_found');
    return [$p,$a];
}
/** Senarai tugas mengikut bahagian: setiap bidang (jawatan unit Sekolah, di bawah PK bidang itu), kemudian Tingkatan 6 (PPU) di bawah
 *  PK Tingkatan 6 dan PPKI di bawah PK PPKI. Pulangkan [{title, pk_label, pk, unit, cats => [bidang => [jawatankuasa => [{role,name,teacher,rank}]]]}].
 *  $cat / $unit = penapis (null = semua; unit 'sekolah' = bidang sahaja). */
function task_book(?string $cat=null,?string $unit=null): array {
    require_once __DIR__.'/organisasi.php'; $lead=leadership()['posts']; $labels=leader_posts();
    $tn=array_column(query('SELECT id,name FROM teachers WHERE school_id='.sid())->fetchAll(),'name','id');
    $pkFor=function(string $k)use($lead,$labels,$tn): array { return [$labels[$k]??'',isset($lead[$k])&&$lead[$k]?($tn[$lead[$k]]??null):null]; };
    $catPk=[]; foreach(LEADER_POSTS as $k=>[,$cats]) foreach($cats as $c) $catPk[$c]??=$k;
    // Pimpinan jawatankuasa (jadual seperti fail JK sekolah): Pengerusi = Pengetua, Timbalan = PK bidang / unit, Naib Pengerusi = PK lain (bidang sahaja).
    $leadRows=function(string $tk,bool $naib)use($lead,$labels,$tn): array {
        $who=fn(string $k)=>isset($lead[$k])&&$lead[$k]&&isset($tn[$lead[$k]])?$tn[$lead[$k]]:null; $r=[];
        if($n=$who('pengetua')) $r[]=['Pengerusi',$n,$labels['pengetua']];
        if($tk!=='' && ($n=$who($tk))) $r[]=['Timbalan Pengerusi',$n,$labels[$tk]];
        if($naib){ $o=[]; foreach($labels as $k=>$l) if($k!=='pengetua' && $k!==$tk && ($n=$who($k))) $o[]=[$n,$l];
            $rn=['I','II','III','IV','V','VI','VII']; foreach($o as $i=>[$n,$l]) $r[]=['Naib Pengerusi'.(count($o)>1?' '.($rn[$i]??$i+1):''),$n,$l]; }
        return $r;
    };
    $sec=[]; foreach(CATEGORIES as $c){ [$l,$n]=$pkFor($catPk[$c]??''); $sec['sekolah|'.$c]=['title'=>'BIDANG '.mb_strtoupper($c),'pk_label'=>$l,'pk'=>$n,'unit'=>'sekolah','lead'=>$leadRows($catPk[$c]??'',true),'cats'=>[]]; }
    foreach(['t6'=>['pk_t6','TINGKATAN 6 (PPU)'],'ppki'=>['pk_ppki','PENDIDIKAN KHAS (PPKI)']] as $u=>[$k,$t]){ [$l,$n]=$pkFor($k); $sec[$u]=['title'=>$t,'pk_label'=>$l,'pk'=>$n,'unit'=>$u,'lead'=>$leadRows($k,false),'cats'=>[]]; }
    $names=[];
    foreach(positions_with_holder() as $p){
        $u=$p['unit']??'sekolah'; if($cat && $p['category']!==$cat) continue; if($unit && $u!==$unit) continue;
        [$role,$com,$rank]=position_committee($p['name']);
        if($u!=='sekolah'){ $c2=trim((string)preg_replace('/\s+(PPU|PPKI|T6|Tingkatan 6)$/iu','',$com)); if($c2!=='') $com=$c2; } // bahagian unit: akhiran unit tidak perlu
        $k=$u.'|'.mb_strtolower(trim((string)preg_replace(['/\s*&\s*/u','/\s+/u'],[' dan ',' '],$com))); $names[$k]??=$com; // "&" = "dan", huruf besar/kecil diabaikan
        $sec[$u==='sekolah'?'sekolah|'.$p['category']:$u]['cats'][$p['category']][$names[$k]][]=['role'=>$role,'name'=>$p['name'],'teacher'=>$p['teacher_name'],'rank'=>$rank];
    }
    $out=[];
    foreach($sec as $x){ if(!$x['cats']) continue; $cats=[];
        foreach(CATEGORIES as $c) if(!empty($x['cats'][$c])){ $coms=$x['cats'][$c]; ksort($coms,SORT_NATURAL|SORT_FLAG_CASE);
            foreach($coms as &$rows) usort($rows,fn($a,$b)=>$a['rank']<=>$b['rank'] ?: strnatcasecmp($a['name'],$b['name'])); unset($rows); $cats[$c]=$coms; }
        $x['cats']=$cats; $out[]=$x; }
    return $out;
}
/** Nombor & susunan buku: bahagian A, B, … (1, 2, …); jawatankuasa 1.1, 1.2 …; panitia mata pelajaran dikumpul di hujung bidang.
 *  Pulangkan [{letter,no,title,pk_label,pk,groups => [{sub (tajuk kecil|null), items => [{no,name,rows}]}]}]. */
function task_book_outline(array $book): array {
    $out=[]; $L=range('A','Z');
    foreach(array_values($book) as $si=>$x){ $n=0; $groups=[];
        foreach($x['cats'] as $cat=>$coms){
            $main=[]; $pan=[]; foreach($coms as $name=>$rows) if(preg_match('/^Panitia\b/iu',$name)) $pan[$name]=$rows; else $main[$name]=$rows;
            $mk=function(array $list)use(&$n,$si): array { $r=[]; foreach($list as $name=>$rows){ $n++; $r[]=['no'=>($si+1).'.'.$n,'name'=>$name,'rows'=>$rows]; } return $r; };
            if($main) $groups[]=['sub'=>$x['unit']!=='sekolah'?$cat:null,'items'=>$mk($main)];
            if($pan) $groups[]=['sub'=>($x['unit']!=='sekolah'?$cat.' — ':'').'Panitia Mata Pelajaran','items'=>$mk($pan)];
        }
        $title=preg_replace('/^BIDANG\s+/u','',$x['title']);
        $out[]=['letter'=>$L[$si]??(string)($si+1),'no'=>$si+1,'title'=>$title,'pk_label'=>$x['pk_label'],'pk'=>$x['pk'],'lead'=>$x['lead']??[],'groups'=>$groups];
    }
    return $out;
}
/** Peranan "AJK (SU Disiplin)" → ["AJK", "SU Disiplin"] untuk lajur Catatan. Hanya apabila bahagian sebelum kurungan ialah peranan dikenali
 *  (label seperti "Bengkel KVS (Asas Roti)" kekal utuh). */
function book_note(string $role): array {
    if(preg_match('/^(.+?)\s*\(((?:[^()]|\([^()]*\))*)\)$/u',$role,$m)) foreach(array_keys(BOOK_ROLES) as $k)
        if(mb_strtolower($m[1])===mb_strtolower($k) || str_starts_with(mb_strtolower($m[1]),mb_strtolower($k).' ')) return [$m[1],$m[2]];
    return [$role,''];
}
/** HTML senarai tugas: kandungan (senarai jawatankuasa) diikuti ahli setiap jawatankuasa. $pdf: kandungan mPDF dengan nombor halaman.
 *  $lead: setiap JK dimulakan dengan Pengerusi (Pengetua), Timbalan (PK bidang / unit) & Naib Pengerusi (PK lain) daripada Carta Organisasi. */
function task_book_html(array $book,bool $pdf=false,bool $lead=true): string {
    $o=task_book_outline($book); if(!$o) return '<p>Tiada jawatan.</p>';
    $h='';
    if($pdf) $h.='<tocpagebreak toc-preHTML="'.e('<h2 class="toc-title">KANDUNGAN</h2>').'" links="on" toc-margin-top="16mm" toc-margin-bottom="18mm" />';
    else {
        $h.='<nav class="book-toc" aria-label="Kandungan"><h3 class="toc-title">KANDUNGAN</h3>';
        foreach($o as $s){ $h.='<div class="toc-sec"><a href="#bhg-'.$s['no'].'">BAHAGIAN '.$s['letter'].': '.e($s['title']).'</a></div>';
            foreach($s['groups'] as $g){ if($g['sub']) $h.='<div class="toc-sub">'.e($g['sub']).'</div>';
                foreach($g['items'] as $it) $h.='<div class="toc-item"><a href="#jk-'.str_replace('.','-',$it['no']).'"><span class="toc-no">'.$it['no'].'</span>'.e($it['name']).'</a></div>'; } }
        $h.='</nav>';
    }
    foreach($o as $si=>$s){
        $head='BAHAGIAN '.$s['letter'].': '.$s['title'];
        $h.=($pdf && $si?'<pagebreak />':'').($pdf?'<tocentry content="'.e($head).'" level="0" />':'').'<div class="book-cat" id="bhg-'.$s['no'].'"><div class="book-cat-title">'.e($head).'</div>'.($s['pk_label']!==''?'<div class="book-pk">'.($s['pk']!==null?'<strong>'.e(mb_strtoupper($s['pk'])).'</strong><br>':'').e($s['pk_label']).'</div>':'').'</div>';
        foreach($s['groups'] as $g){
            if($g['sub']){ $h.=($pdf?'<tocentry content="'.e($g['sub']).'" level="1" />':'').'<h3 class="book-sub">'.e($g['sub']).'</h3>'; }
            foreach($g['items'] as $it){
                $rows=[];
                // Pimpinan hanya untuk jawatankuasa (JK …) yang belum ada Pengerusi / Timbalan / Naib Pengerusi sendiri.
                if($lead && preg_match('/^(JK|Jawatankuasa)\b/iu',$it['name']) && !array_filter($it['rows'],fn($r)=>$r['rank']<=3))
                    foreach($s['lead'] as [$role,$name,$label]) $rows[]=[$role,$name,$label,true];
                foreach($it['rows'] as $r){ [$role,$note]=book_note($r['role']); $rows[]=[$role,$r['teacher'],$note,false]; }
                $note=(bool)array_filter($rows,fn($r)=>$r[2]!==''); $cols=$note?4:3;
                $h.=($pdf?'<tocentry content="'.e($it['no'].'  '.$it['name']).'" level="2" />':'').'<table class="book-table" id="jk-'.str_replace('.','-',$it['no']).'"><thead><tr><th colspan="'.$cols.'" class="book-com">'.$it['no'].'&nbsp;&nbsp;'.e($it['name']).'</th></tr><tr><th class="c-bil">Bil.</th><th class="c-role">Jawatan</th><th>Nama guru</th>'.($note?'<th class="c-note">Catatan</th>':'').'</tr></thead><tbody>';
                foreach($rows as $i=>[$role,$t,$n,$isLead]) $h.='<tr'.($isLead?' class="book-lead"':'').'><td class="c-bil">'.($i+1).'</td><td class="c-role">'.e($role).'</td><td>'.($t!==null?e(mb_strtoupper($t)):'<em class="vacant">— belum diisi —</em>').'</td>'.($note?'<td class="c-note">'.e($n).'</td>':'').'</tr>';
                $h.='</tbody></table>';
            }
        }
    }
    return $h;
}
const TASK_BOOK_CSS='.toc-title{font-size:13pt;text-align:center;margin:0 0 10px}div.mpdf_toc_level_0{margin-top:8px}a.mpdf_toc_a_level_0,span.mpdf_toc_p_level_0{font-weight:bold;font-size:10pt}a.mpdf_toc_a_level_1,span.mpdf_toc_p_level_1{font-size:9.5pt;font-weight:normal;font-style:italic}a.mpdf_toc_a_level_2,span.mpdf_toc_p_level_2{font-size:9.5pt;font-weight:normal;font-style:normal}div.mpdf_toc_level_1{padding-left:6mm;margin-top:3px}div.mpdf_toc_level_2{padding-left:10mm}.book-cat{margin:18px 0 8px;padding:7px 10px;background:#edf3e9;border-left:4px solid #588767;page-break-after:avoid}.book-cat-title{font-size:12.5pt;font-weight:bold;letter-spacing:.02em}.book-pk{font-size:9.5pt;margin-top:3px;line-height:1.35}.book-sub{font-size:10.5pt;margin:10px 0 4px;color:#557e5e}.book-table{width:100%;border-collapse:collapse;margin:0 0 12px;page-break-inside:avoid;font-size:9.5pt}.book-table th,.book-table td{border:1px solid #bfceba;padding:4px 6px;text-align:left;vertical-align:top}.book-table thead th{background:#f8faf5}.book-com{font-size:10pt;background:#dfe7dc!important;text-transform:none}.c-bil{width:30px;text-align:center!important}.c-role{width:30%}.c-note{width:26%;color:#555}.book-lead td{background:#fafbf8}.vacant{color:#999}';
/** Senarai muktamad = agihan berstatus Muktamad; selain itu draf (tera air). */
function task_book_final(): bool { return workflow_state()['status']==='finalized'; }
/** Tetapkan guru yang mengajar MP: guru dalam $ids ditambah MP pada kepakaran, guru lain yang memegang MP itu dibuang. Pulangkan [ditambah, dibuang]. */
function subject_set_teachers(int $subjectId,array $ids): array {
    $s=subject_find($subjectId); if(!$s) throw new EngineError('Mata pelajaran tidak ditemui.',404,'not_found');
    $want=array_flip(array_map('intval',$ids)); $key=mb_strtolower($s['name']); $add=0; $del=0;
    foreach(query('SELECT id,name,specialty FROM teachers WHERE school_id='.sid())->fetchAll() as $t){
        $tok=specialty_tokens((string)$t['specialty']); $has=in_array($key,array_map('mb_strtolower',$tok),true); $on=isset($want[(int)$t['id']]);
        if($on===$has) continue;
        $new=$on?[...$tok,$s['name']]:array_values(array_filter($tok,fn($x)=>mb_strtolower($x)!==$key)); $sp=implode(', ',$new);
        if(mb_strlen($sp)>160) throw new EngineError('Kepakaran '.$t['name'].' melebihi 160 aksara jika '.$s['name'].' ditambah. Kemas kini kepakaran guru itu di Pengurusan Guru.');
        query('UPDATE teachers SET specialty=? WHERE id=? AND school_id='.sid(),[$sp,(int)$t['id']]); $on?$add++:$del++;
    }
    return [$add,$del];
}
/** Jawatan dengan perkataan berulang berturut-turut (cth. "Ketua Panitia Panitia Kimia"): [{id,old,new}]. */
function positions_repeated_words(): array {
    $out=[]; foreach(query('SELECT id,name FROM positions WHERE school_id='.sid().' ORDER BY name')->fetchAll() as $p){
        $new=trim((string)preg_replace('/\b(\p{L}[\p{L}\p{N}\.]*)(\s+\1\b)+/iu','$1',$p['name']));
        if($new!==$p['name'] && $new!=='') $out[]=['id'=>(int)$p['id'],'old'=>$p['name'],'new'=>$new];
    }
    return $out;
}
function positions_fix_repeated_words(): int { $n=0; foreach(positions_repeated_words() as $r) $n+=query('UPDATE positions SET name=? WHERE id=? AND school_id='.sid(),[$r['new'],$r['id']])->rowCount(); return $n; }
/** Padam MP daripada katalog. Kepakaran guru tidak diubah (dipaparkan sebagai teks bebas). */
function subject_delete(int $id): array {
    $s=subject_find($id); if(!$s) throw new EngineError('Mata pelajaran tidak ditemui.',404,'not_found');
    query('DELETE FROM subjects WHERE id=? AND school_id='.sid(),[$id]); return $s;
}
/** Import nama terpilih daripada templat. Nama yang sudah ada dilangkau. Pulangkan bilangan ditambah. */
function subjects_import(string $key,array $names): int {
    $tpl=SUBJECT_TEMPLATES[$key]??null; if(!$tpl) throw new EngineError('Senarai standard tidak sah.');
    $want=array_flip(array_map('strval',$names)); $have=array_flip(array_map('mb_strtolower',array_column(subjects_all(),'name'))); $n=0;
    foreach($tpl[3] as $grp=>$list) foreach($list as $name) if(isset($want[$name]) && !isset($have[mb_strtolower($name)])){
        query('INSERT INTO subjects (school_id,name,grp) VALUES ('.sid().',?,?)',[$name,$grp]); $have[mb_strtolower($name)]=true; $n++;
    }
    if(!$n) throw new EngineError('Tiada mata pelajaran baharu dipilih (yang sudah ada dilangkau).');
    return $n;
}

/** Mata pelajaran yang disebut dalam nama jawatan (padanan terpanjang, sempadan perkataan), cth. "Ketua Panitia Matematik Tambahan" → Matematik Tambahan. $subjects: senarai nama. */
function position_subject(string $position,array $subjects): ?string {
    $best=null;
    foreach($subjects as $s) if(mb_strlen($s)>mb_strlen((string)$best) && preg_match('/(?<![\p{L}\p{N}])'.preg_quote($s,'/').'(?![\p{L}\p{N}])/iu',$position)) $best=$s;
    return $best;
}
/** Guru mengajar MP ini? Padanan tepat salah satu kepakaran (tanpa beza huruf), bukan sebahagian teks. */
function teacher_teaches(array $t,string $subject): bool {
    $s=mb_strtolower($subject); foreach(specialty_tokens((string)$t['specialty']) as $x) if(mb_strtolower($x)===$s) return true; return false;
}
/** Kumpulan MP katalog sekolah: nama MP (huruf kecil) => kumpulan, cth. "bahasa melayu" => "Bahasa". */
function subject_groups(): array { $g=[]; foreach(subjects_all() as $r) if((string)$r['grp']!=='') $g[mb_strtolower((string)$r['name'])]=(string)$r['grp']; return $g; }
/** Kesesuaian guru dengan MP jawatan (cth. Ketua Panitia Bahasa Melayu): [2, MP] mengajar MP itu;
 *  [1, MP] mengajar MP lain dalam kumpulan katalog yang sama (cth. Bahasa Inggeris untuk Bahasa Melayu); [0, ''] tiada padanan. */
function subject_fit(array $t,string $subject,array $groups): array {
    $s=mb_strtolower($subject); $g=$groups[$s]??''; $near='';
    foreach(specialty_tokens((string)$t['specialty']) as $x){ $lx=mb_strtolower($x); if($lx===$s) return [2,$x]; if($g!=='' && $near==='' && ($groups[$lx]??'')===$g) $near=$x; }
    return $near!==''?[1,$near]:[0,''];
}
/** Nama MP untuk pengesanan: katalog sekolah; jika kosong, kepakaran semua guru (supaya cadangan tetap berfungsi tanpa katalog). */
function subject_names(): array {
    $n=array_column(subjects_all(),'name'); if($n) return $n;
    $u=[]; foreach(query('SELECT specialty FROM teachers WHERE school_id='.sid().' AND specialty<>""')->fetchAll(PDO::FETCH_COLUMN) as $sp) foreach(specialty_tokens((string)$sp) as $x) $u[mb_strtolower($x)]=$x;
    return array_values($u);
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
    $s=['teach_share'=>$f('index_teach_share',0,100,60)/100,'high'=>$f('index_high',1,3,1.3),'low'=>$f('index_low',0.1,1,0.7),'exam_factor'=>$f('index_exam_factor',1,2,1),'max_tasks'=>(int)$f('index_max_tasks',1,50,8),'over_cap'=>setting('index_over_cap')!=='0'];
    if($s['low']>=$s['high']){ $s['low']=0.7; $s['high']=1.3; }
    return $s;
}
/** Sahkan & simpan tetapan indeks. $d: teach_share (int %), high, low, exam_factor (nombor), max_tasks (int). */
function index_settings_save(array $d): void {
    $num=function(string $k,float $min,float $max,string $label)use($d): float { $v=$d[$k]??null; if(!is_numeric($v) || (float)$v<$min || (float)$v>$max) throw new EngineError($label.' mesti antara '.$min.' dan '.$max.'.'); return round((float)$v,2); };
    $share=$num('teach_share',0,100,'Bahagian mengajar (%)'); $high=$num('high',1,3,'Ambang tinggi'); $low=$num('low',0.1,1,'Ambang rendah');
    $exam=$num('exam_factor',1,2,'Faktor kelas peperiksaan'); $max=$num('max_tasks',1,50,'Had bilangan jawatan');
    if($low>=$high) throw new EngineError('Ambang rendah mesti lebih kecil daripada ambang tinggi.');
    engine_setting_set((array_key_exists('over_cap',$d)?['index_over_cap'=>$d['over_cap']?'1':'0']:[])+['index_teach_share'=>(string)(int)$share,'index_high'=>number_format($high,2,'.',''),'index_low'=>number_format($low,2,'.',''),'index_exam_factor'=>number_format($exam,2,'.',''),'index_max_tasks'=>(string)(int)$max]);
}
/** Anggaran indeks guru jika skor tugasnya berubah $delta (median sekolah dianggap tetap). null jika guru tidak dikira. */
function index_after(array $t,float $delta,array $idx): ?float {
    if(!$t['counted']) return null; $B=$idx['basis']; $share=$B['share'];
    if($B['teaching']<=0 && $B['score']<=0) return null;
    return round($share*($B['teaching']>0?$t['teaching']/$B['teaching']:0)+(1-$share)*($B['score']>0?max(0,$t['score']+$delta)/$B['score']:0),2);
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
        // Asas masa mengajar hanya daripada guru yang masanya sudah diisi (0 = belum diisi, bukan beban sifar).
        if($t['counted']){ if($t['teaching']>0) $T[]=$t['teaching']; $S[]=$t['score']; }
        $rows[$id]=$t;
    }
    $div=function(array $xs): array { $m=median($xs); return $m>0?[$m,'median']:(($a=$xs?array_sum($xs)/count($xs):0)>0?[$a,'purata']:[0.0,'tiada']); };
    [$dT,$bT]=$div($T); [$dS,$bS]=$div($S);
    $share=$dT>0?($dS>0?$s['teach_share']:1.0):0.0; $ready=$dT>0 || $dS>0;
    foreach($rows as $id=>&$t){
        $tr=$dT>0?$t['teaching']/$dT:0.0; $kr=$dS>0?$t['score']/$dS:0.0;
        $t['teach_ratio']=$dT>0?round($tr,2):null; $t['task_ratio']=$dS>0?round($kr,2):null;
        // Masa mengajar belum diisi → indeks ikut tugas sahaja (tidak dihukum sebagai "kurang beban" mengajar).
        $sh=$t['teaching']>0?$share:($dS>0?0.0:$share);
        $t['index']=$t['counted'] && $ready?round($sh*$tr+(1-$sh)*$kr,2):null;
        $t['label']=!$t['counted']?($t['status']!=='aktif'?'tidak_aktif':'dikecualikan'):($t['index']===null?'belum':($t['index']>$s['high']?'terbeban':($t['index']<$s['low']?'kurang':'seimbang')));
        // Melebihi kapasiti mata (had yang ditetapkan sekolah bagi guru itu) → Terbeban walaupun indeks relatif masih rendah.
        $t['over_capacity']=$t['counted'] && (int)$t['load']>(int)$t['capacity'];
        if($s['over_cap'] && $t['over_capacity'] && in_array($t['label'],['seimbang','kurang','belum'],true)) $t['label']='terbeban';
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
