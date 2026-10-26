<?php
declare(strict_types=1);
date_default_timezone_set('Asia/Kuala_Lumpur');
require_once __DIR__.'/config.php';
$sessionDatabase=app_database_path();
session_name('AGIHTUGAS_'.substr(hash('sha256',$sessionDatabase),0,16));
session_start(['gc_maxlifetime'=>7200,'cookie_httponly'=>true,'cookie_samesite'=>'Strict','cookie_secure'=>(!empty($_SERVER['HTTPS'])&&$_SERVER['HTTPS']!=='off'),'use_strict_mode'=>true]);
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');
require __DIR__.'/icons.php';
require_once __DIR__.'/database.php';
require_once __DIR__.'/allocation.php';
require_once __DIR__.'/bulk_positions.php';
require_once __DIR__.'/auth.php';
require_once __DIR__.'/workflow.php';
require_once __DIR__.'/auth_gate.php';
function e(mixed $v): string { return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8'); }
$settings=q('SELECT * FROM settings')->fetchAll(PDO::FETCH_KEY_PAIR);
$_SESSION['csrf'] ??= bin2hex(random_bytes(24));
function csrf(): string { return '<input type="hidden" name="csrf" value="'.e($_SESSION['csrf']).'">'; }
$account=auth_gate();
function text_input(string $key,int $max=160): string { $v=trim((string)($_POST[$key]??'')); if($v==='' || mb_strlen($v)>$max) throw new RuntimeException('Sila lengkapkan medan dengan nilai yang sah.'); return $v; }
function number_input(string $key,int $min,int $max): int { $n=filter_var($_POST[$key]??null,FILTER_VALIDATE_INT); if($n===false||$n<$min||$n>$max) throw new RuntimeException('Nilai nombor di luar julat yang dibenarkan.'); return $n; }
$pages=['dashboard'=>'Dashboard','teachers'=>'Pengurusan Guru','positions'=>'Jawatan & Pemberat','assignments'=>'Agihan Tugas','auto'=>'Agihan Automatik','rules'=>'Pusat Syarat','bulk'=>'Tambah Jawatan Pukal','letters'=>'Surat Lantikan','asset'=>'Imej Sekolah','settings'=>'Tetapan Sekolah','workflow'=>'Semakan & Kelulusan','accounts'=>'Akaun Pengguna','profile'=>'Profil & Kata Laluan','my_tasks'=>'Tugas Saya'];
$page=(string)($_GET['page']??'dashboard'); if(!isset($pages[$page])) { http_response_code(404); $page='dashboard'; }
$error='';
if($_SERVER['REQUEST_METHOD']==='POST') {
 try {
  if(!hash_equals($_SESSION['csrf'],(string)($_POST['csrf']??''))) { http_response_code(403); throw new RuntimeException('Sesi borang tidak sah. Muat semula halaman.'); }
  $action=(string)($_POST['action']??''); $id=(int)($_POST['id']??0);
  $db->beginTransaction();
  if($action==='account_save') auth_require_role($account,['admin']);
  elseif($action==='workflow') { /* Transition service checks role, order and expected version. */ }
  elseif($action==='profile_password') { /* Current password verified below. */ }
  else {
   auth_require_role($account,in_array($action,['settings','school_logo_upload','school_logo_remove','letter_branding'],true)?['admin']:['admin','pk']);
   workflow_require_draft();
  }
  switch($action) {
   case 'account_save': account_save($_POST,$account['id']); $message='Akaun pengguna disimpan.'; break;
   case 'profile_password':
    $old=q('SELECT password_hash FROM accounts WHERE id=?',[$account['id']])->fetchColumn();
    if(!password_verify((string)($_POST['current_password']??''),$old)) throw new AccessError('Kata laluan semasa tidak tepat.',422);
    $password=valid_account_password($_POST['password']??null);
    if($password!==($_POST['confirm_password']??null)) throw new AccessError('Pengesahan kata laluan tidak sepadan.',422);
    q('UPDATE accounts SET password_hash=?,auth_version=auth_version+1 WHERE id=?',[password_hash($password,PASSWORD_DEFAULT),$account['id']]);
    $_SESSION['auth_version']=(int)q('SELECT auth_version FROM accounts WHERE id=?',[$account['id']])->fetchColumn(); session_regenerate_id(true);
    $message='Kata laluan ditukar; sesi lain ditamatkan.'; break;
   case 'workflow':
    workflow_transition((string)($_POST['transition']??''),number_input('version',1,PHP_INT_MAX),$account,trim((string)($_POST['reason']??'')));
    $message='Status semakan dan kelulusan dikemas kini.'; break;
   case 'teacher':
    $name=text_input('name'); $email=trim((string)($_POST['email']??''));
    if($email!=='' && (!filter_var($email,FILTER_VALIDATE_EMAIL)||strlen($email)>254)) throw new RuntimeException('Alamat e-mel tidak sah.');
    $data=[$name,$email,mb_substr(trim((string)($_POST['specialty']??'')),0,160),number_input('capacity',1,100)];
    if($id) { if(!q('SELECT id FROM teachers WHERE id=?',[$id])->fetch()) throw new RuntimeException('Guru tidak ditemui.'); q('UPDATE teachers SET name=?,email=?,specialty=?,capacity=? WHERE id=?',[...$data,$id]); }
    else q('INSERT INTO teachers(name,email,specialty,capacity) VALUES (?,?,?,?)',$data);
    $message='Maklumat guru disimpan.'; break;
   case 'position':
    $name=text_input('name'); $category=text_input('category'); if(!in_array($category,CATEGORIES,true)) throw new RuntimeException('Bidang tidak sah.');
    $data=[$name,$category,number_input('weight',1,10)];
    if($id) { if(!q('SELECT id FROM positions WHERE id=?',[$id])->fetch()) throw new RuntimeException('Jawatan tidak ditemui.'); q('UPDATE positions SET name=?,category=?,weight=? WHERE id=?',[...$data,$id]); }
    else q('INSERT INTO positions(name,category,weight) VALUES (?,?,?)',$data);
    $message='Jawatan dan pemberat disimpan.'; break;
   case 'assign':
    $tid=number_input('teacher_id',1,PHP_INT_MAX); $pid=number_input('position_id',1,PHP_INT_MAX);
    if(!q('SELECT id FROM teachers WHERE id=?',[$tid])->fetch()||!q('SELECT id FROM positions WHERE id=?',[$pid])->fetch()) throw new RuntimeException('Pilih guru dan jawatan yang sah.');
    q('INSERT INTO assignments(teacher_id,position_id) VALUES (?,?) ON CONFLICT(position_id) DO UPDATE SET teacher_id=excluded.teacher_id',[$tid,$pid]);
    $message='Agihan tugas dikemas kini.'; break;
   case 'unassign': q('DELETE FROM assignments WHERE id=?',[$id]); $message='Lantikan dilepaskan. Jawatan kini kosong.'; break;
   case 'delete_teacher': case 'delete_position':
    $table=$action==='delete_teacher'?'teachers':'positions'; $column=$table==='teachers'?'teacher_id':'position_id';
    if(q("SELECT COUNT(*) FROM assignments WHERE $column=?",[$id])->fetchColumn()) throw new RuntimeException('Lepaskan agihan berkaitan dahulu sebelum memadam rekod ini.');
    if($table==='teachers'&&q('SELECT 1 FROM accounts WHERE teacher_id=?',[$id])->fetchColumn()) throw new AccessError('Nyahpaut akaun pengguna daripada profil guru dahulu.',409);
    if($table==='teachers'&&allocation_teacher_referenced($id)) throw new AllocationError('Buang rujukan guru ini dalam Pusat Syarat dahulu.');
    q("DELETE FROM $table WHERE id=?",[$id]); $message='Rekod dipadam.'; break;
   case 'allocation_rules':
    $limits=[]; foreach(CATEGORIES as $i=>$cat) $limits[$cat]=number_input('category_'.$i,0,50);
    $excluded=$_POST['excluded_teachers']??[]; if(!is_array($excluded)) throw new AllocationError('Senarai guru tidak sah.');
    allocation_save_rules(['respect_capacity'=>isset($_POST['respect_capacity']),'max_tasks'=>number_input('max_tasks',1,50),'max_heavy'=>number_input('max_heavy',0,50),'heavy_threshold'=>number_input('heavy_threshold',1,10),'category_limits'=>$limits,'excluded_teachers'=>array_map('intval',$excluded)]);
    $message='Syarat agihan automatik disimpan.'; break;
   case 'position_rule':
    $allowed=$_POST['allowed_teachers']??[]; if(!is_array($allowed)) throw new AllocationError('Senarai guru tidak sah.');
    allocation_save_position_rule(number_input('position_id',1,PHP_INT_MAX),['allowed_teachers'=>array_map('intval',$allowed),'fixed_teacher_id'=>empty($_POST['fixed_teacher_id'])?null:number_input('fixed_teacher_id',1,PHP_INT_MAX),'required_specialty'=>(string)($_POST['required_specialty']??'')]);
    $message='Syarat khusus jawatan disimpan.'; break;
   case 'auto_preview':
    $plan=allocation_preview((string)($_POST['mode']??'vacant')); $db->commit();
    $_SESSION['allocation_plan']=$plan; header('Location: /?page=auto#auto-result'); exit;
   case 'auto_apply':
    $result=allocation_apply((string)($_POST['plan_id']??''));
    unset($_SESSION['allocation_plan']); $message='Agihan automatik disimpan: '.$result['changed'].' perubahan, '.$result['unfilled'].' jawatan belum diisi.'; break;
   case 'bulk_preview':
    $text=(string)($_POST['bulk_text']??'');
    if(isset($_FILES['csv'])&&$_FILES['csv']['error']!==UPLOAD_ERR_NO_FILE) {
     if($_FILES['csv']['error']!==UPLOAD_ERR_OK||$_FILES['csv']['size']>131072||!is_uploaded_file($_FILES['csv']['tmp_name'])) throw new AllocationError('Muat naik CSV gagal atau melebihi 128 KB.');
     if(trim($text)!=='') throw new AllocationError('Pilih satu sumber sahaja: tampal teks atau muat naik CSV.');
     $text=file_get_contents($_FILES['csv']['tmp_name']);
    }
    $rows=bulk_positions_parse($text); $db->commit();
    $_SESSION['bulk_preview']=['token'=>bin2hex(random_bytes(24)),'rows'=>$rows]; header('Location: /?page=bulk#bulk-result'); exit;
   case 'bulk_apply':
    $draft=$_SESSION['bulk_preview']??null;
    if(!$draft||!hash_equals($draft['token'],(string)($_POST['bulk_token']??''))) throw new AllocationError('Pratonton pukal tidak sah. Jana semula.',409);
    $validated=bulk_positions_validate($draft['rows']); $ids=bulk_positions_insert($validated); unset($_SESSION['bulk_preview']);
    $message=count($ids).' jawatan ditambah secara pukal.'; break;
   case 'school_logo_upload':
    $logo=branding_upload($_FILES['school_logo']??[]);q('UPDATE settings SET value=? WHERE key="school_logo_id"',[$logo]);$message='Logo sekolah disimpan untuk draf.';break;
   case 'school_logo_remove': q('UPDATE settings SET value="" WHERE key="school_logo_id"');$message='Logo sekolah dibuang daripada draf. Surat muktamad terdahulu tidak berubah.';break;
   case 'letter_branding':
    $layout=(string)($_POST['letter_header']??'');if(!isset(LETTER_HEADERS[$layout]))throw new BrandingError('Gaya kepala surat tidak sah.');
    q('UPDATE settings SET value=? WHERE key="letter_header"',[$layout]);$message='Gaya kepala surat disimpan.';break;
   case 'settings':
    $type=$_POST['school_type']??school_type(); if(!is_string($type)||!isset(SCHOOL_TYPES[$type])) throw new AccessError('Jenis sekolah mesti SK atau SMK.',422);
    q('UPDATE settings SET value=? WHERE key="school_type"',[$type]);
    foreach(['school','principal','year','reference'] as $key) { $value=$key==='year'?(string)number_input('year',2020,2100):text_input($key); q('UPDATE settings SET value=? WHERE key=?',[$value,$key]); }
    $message='Tetapan sekolah disimpan.'; break;
   default: throw new RuntimeException('Tindakan tidak dikenali.');
  }
  q('INSERT INTO history(description) VALUES (?)',[$account['name'].' (#'.$account['id'].'): '.$message]); $db->commit();
  $_SESSION['flash']=$message; header('Location: /?page='.$page); exit;
 } catch(Throwable $ex) { if($db->inTransaction()) $db->rollBack(); $known=$ex instanceof AccessError||$ex instanceof WorkflowError||$ex instanceof AllocationError||$ex instanceof BrandingError; if($known) http_response_code($ex->status); $error=$ex instanceof PDOException?'Rekod tidak dapat disimpan. Semak input dan cuba semula.':$ex->getMessage(); }
}
$teachers=$account['role']==='teacher'?[]:q('SELECT t.*,COALESCE(SUM(p.weight),0) AS load,COUNT(a.id) AS task_count FROM teachers t LEFT JOIN assignments a ON a.teacher_id=t.id LEFT JOIN positions p ON p.id=a.position_id GROUP BY t.id ORDER BY t.name')->fetchAll();
$positions=$account['role']==='teacher'?[]:q('SELECT p.*,a.id AS assignment_id,a.teacher_id,t.name AS teacher_name FROM positions p LEFT JOIN assignments a ON a.position_id=p.id LEFT JOIN teachers t ON t.id=a.teacher_id ORDER BY p.category,p.name')->fetchAll();
$assigned=count(array_filter($positions,fn($r)=>$r['teacher_id']!==null));
$over=array_filter($teachers,fn($r)=>$r['load']>$r['capacity']);
$totalLoad=array_sum(array_column($teachers,'load'));
function badge(array $t): string { return $t['load']>$t['capacity']?'<span class="pill red">Melebihi had</span>':($t['load']==0?'<span class="pill gray">Belum diagih</span>':'<span class="pill green">Dalam kapasiti</span>'); }
function field(string $label,string $name,mixed $value='',string $type='text',string $attrs=''): void { echo '<label class="field">'.e($label).'<input name="'.e($name).'" type="'.e($type).'" value="'.e($value).'" '.$attrs.'></label>'; }
