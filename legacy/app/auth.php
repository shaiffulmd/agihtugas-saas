<?php
declare(strict_types=1);
const ROLE_LABELS=['admin'=>'Pentadbir','pk'=>'Penolong Kanan','head'=>'Ketua Sekolah','teacher'=>'Guru'];
function role_labels(): array { return [...ROLE_LABELS,'head'=>school_head_title()]; }
function role_label(string $role): string { return role_labels()[$role]??$role; }
function current_account(): ?array {
 $id=$_SESSION['account_id']??0;
 if(!$id) return null;
 $a=q('SELECT id,name,email,role,teacher_id,active,auth_version FROM accounts WHERE id=?',[$id])->fetch();
 if(!$a||!$a['active']||$a['auth_version']!==($_SESSION['auth_version']??null)||time()-($_SESSION['last_activity']??0)>7200) { unset($_SESSION['account_id'],$_SESSION['auth_version']); return null; }
 $_SESSION['last_activity']=time(); return $a;
}
function auth_can_plan(?array $a): bool { return $a&&in_array($a['role'],['admin','pk'],true); }
function auth_pages(array $a): array {
 return match($a['role']) {
  'admin'=>['dashboard','teachers','positions','assignments','auto','rules','bulk','letters','settings','workflow','accounts','profile'],
  'pk'=>['dashboard','teachers','positions','assignments','auto','rules','bulk','letters','workflow','profile'],
  'head'=>['workflow','letters','profile'],
  default=>['my_tasks','profile']
 };
}
function auth_require_role(array $account,array $roles): void { if(!in_array($account['role'],$roles,true)) throw new AccessError('Anda tidak mempunyai akses untuk tindakan ini.',403); }
final class AccessError extends RuntimeException { public function __construct(string $message,public int $status=403) { parent::__construct($message); } }
function valid_account_password(mixed $password): string {
 if(!is_string($password)||strlen($password)<12||strlen($password)>72) throw new AccessError('Kata laluan mesti antara 12 hingga 72 aksara.',422); return $password;
}
function account_save(array $input,int $actorId): void {
 global $db;
 $id=(int)($input['id']??0); $old=$id?q('SELECT * FROM accounts WHERE id=?',[$id])->fetch():null;
 if($id&&!$old) throw new AccessError('Akaun tidak ditemui.',404);
 $name=trim((string)($input['name']??'')); $email=mb_strtolower(trim((string)($input['email']??''))); $role=(string)($input['role']??'');
 if($name===''||mb_strlen($name)>160||!filter_var($email,FILTER_VALIDATE_EMAIL)||strlen($email)>254||!isset(ROLE_LABELS[$role])) throw new AccessError('Nama, e-mel atau peranan tidak sah.',422);
 if(q('SELECT 1 FROM accounts WHERE email=? AND id<>?',[$email,$id])->fetchColumn()) throw new AccessError('E-mel akaun telah digunakan.',422);
 $tid=empty($input['teacher_id'])?null:(int)$input['teacher_id']; $active=isset($input['active'])?1:0;
 if($role==='teacher') {
  if(!$tid||!q('SELECT 1 FROM teachers WHERE id=?',[$tid])->fetchColumn()) throw new AccessError('Akaun guru mesti dipautkan kepada profil guru.',422);
  if(q('SELECT 1 FROM accounts WHERE teacher_id=? AND id<>?',[$tid,$id])->fetchColumn()) throw new AccessError('Profil guru telah dipautkan kepada akaun lain.',422);
 } else $tid=null;
 if($id===$actorId&&(!$active||$role!=='admin')) throw new AccessError('Anda tidak boleh menyahaktifkan atau menukar peranan akaun pentadbir sendiri.',422);
 if($old&&$old['role']==='admin'&&$old['active']&&(!$active||$role!=='admin')&&(int)q('SELECT COUNT(*) FROM accounts WHERE role="admin" AND active=1')->fetchColumn()<=1) throw new AccessError('Sekurang-kurangnya satu pentadbir aktif diperlukan.',422);
 $password=$input['password']??'';
 if(!$old) $hash=password_hash(valid_account_password($password),PASSWORD_DEFAULT);
 elseif($password!=='') $hash=password_hash(valid_account_password($password),PASSWORD_DEFAULT);
 else $hash=$old['password_hash'];
 if($old) q('UPDATE accounts SET name=?,email=?,role=?,teacher_id=?,active=?,password_hash=?,auth_version=auth_version+1 WHERE id=?',[$name,$email,$role,$tid,$active,$hash,$id]);
 else { q('INSERT INTO accounts(name,email,role,teacher_id,active,password_hash) VALUES (?,?,?,?,?,?)',[$name,$email,$role,$tid,$active,$hash]); $id=(int)$db->lastInsertId(); }
 if($id===$actorId) $_SESSION['auth_version']=(int)q('SELECT auth_version FROM accounts WHERE id=?',[$id])->fetchColumn();
 q('INSERT INTO history(description) VALUES (?)',["Akaun #$id ($email) dikemas kini oleh akaun #$actorId."]);
}
function auth_login(string $email,string $password): void {
 if(strlen($email)>254||strlen($password)>72) throw new AccessError('E-mel atau kata laluan tidak sah.',401);
 $bucket=hash('sha256',($_SERVER['REMOTE_ADDR']??'cli').'|'.mb_strtolower(trim($email)));
 $r=q('SELECT * FROM login_attempts WHERE bucket=?',[$bucket])->fetch();
 if($r&&time()-$r['window_start']<900&&$r['attempts']>=5) throw new AccessError('Terlalu banyak cubaan. Cuba lagi selepas 15 minit.',429);
 // Take a write before credential check; failed attempts are persisted independently of UI transactions.
 q('INSERT INTO login_attempts VALUES (?,1,?) ON CONFLICT(bucket) DO UPDATE SET attempts=CASE WHEN window_start<? THEN 1 ELSE attempts+1 END,window_start=CASE WHEN window_start<? THEN excluded.window_start ELSE window_start END',[$bucket,time(),time()-900,time()-900]);
 $account=q('SELECT * FROM accounts WHERE email=?',[mb_strtolower(trim($email))])->fetch();
 $hash=$account['password_hash']??'$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi';
 if(!password_verify($password,$hash)||!$account||!$account['active']) throw new AccessError('E-mel atau kata laluan tidak sah.',401);
 q('DELETE FROM login_attempts WHERE bucket=?',[$bucket]);
 session_regenerate_id(true); $_SESSION=['account_id'=>$account['id'],'auth_version'=>$account['auth_version'],'last_activity'=>time(),'csrf'=>bin2hex(random_bytes(24))];
 q('INSERT INTO history(description) VALUES (?)',["Akaun #{$account['id']} log masuk."]);
}
function auth_setup(array $input): void {
 global $db;
 $db->beginTransaction();
 try {
  // A write lock serializes concurrent first-account creation.
  q('UPDATE setup_codes SET expires_at=expires_at WHERE id=1');
  if(q('SELECT COUNT(*) FROM accounts')->fetchColumn()) throw new AccessError('Pentadbir telah disediakan.',409);
  $code=q('SELECT * FROM setup_codes WHERE id=1')->fetch();
  if(!$code||$code['expires_at']<time()||!hash_equals($code['code_hash'],hash('sha256',(string)($input['setup_code']??'')))) throw new AccessError('Kod persediaan tidak sah atau tamat tempoh.',403);
  $input['role']='admin'; $input['active']=1; $input['id']=0;
  account_save($input,0); q('DELETE FROM setup_codes'); $db->commit();
 } catch(Throwable $e) { if($db->inTransaction())$db->rollBack(); throw $e; }
}
