<?php
function login_user(array $u): never { session_regenerate_id(true); $_SESSION=['uid'=>(int)$u['id'],'auth_version'=>(int)$u['auth_version'],'csrf'=>bin2hex(random_bytes(32)),'started'=>time(),'last'=>time()]; query('UPDATE users SET last_login_at=NOW() WHERE id=? AND school_id='.sid(),[(int)$u['id']]); audit('login','users',(int)$u['id']); redirect(!empty($u['must_change'])?'/?page=security':'/?page=dashboard'); }
function handle_auth(string $action): void {
 if($action==='logout'){ $imp=function_exists('impersonating')?impersonating():null; if(function_exists('imp_end_log')) imp_end_log(); if(isset($_SESSION['uid'])) audit('logout','users',(int)$_SESSION['uid']); $_SESSION=[]; session_regenerate_id(true); if($imp) imp_exit_page(sid()); redirect('/'); } // sesi sokongan: kembali ke panel platform
 if($action==='login'){
  $email=mb_substr(strtolower(trim(input('email'))),0,190); $ip=rate_key('login-ip',client_ip());
  if(!rate_allowed($ip,30)){ audit('login_blocked','users'); ip_block_auto(client_ip(),sid(),'Terlalu banyak cubaan log masuk sekolah'); throw new DomainException('Terlalu banyak cubaan. Cuba semula selepas 15 minit.'); }
  // Perisai (DELIMa): akaun dikunci 15 minit selepas 10 gagal merentas IP — kecuali peranti yang pernah berjaya log masuk.
  if(shield_locked($email)){ audit('login_blocked','users'); http_response_code(429); throw new DomainException('Terlalu banyak percubaan gagal untuk akaun ini. Cuba lagi dalam 15 minit.'); }
  rate_hit($ip);
  // CAPTCHA selepas 3 gagal (IP atau e-mel) / 15 gagal keseluruhan, atau sentiasa — ikut tetapan. + perangkap bot.
  if(login_captcha_needed($email) || !empty($_SESSION['login_cap'])){ $_SESSION['login_cap']=true; form_guard('login'); } elseif(input('website')!=='') throw new DomainException('Permintaan tidak dapat diproses. Cuba semula.');
  $u=query('SELECT * FROM users WHERE school_id='.sid().' AND email=?',[$email])->fetch();
  // Dummy Argon2id hash reduces account enumeration timing differences.
  $hash=$u['password_hash']??'$argon2id$v=19$m=65536,t=3,p=1$YWJjZGVmZ2hpamtsbW5vcA$X61Nhx+pvNQyLdlNsgmbY3DfiY1vYD+L0G5QOIoVr/0';
  if(!password_verify(input('password'),$hash) || !$u){ shield_fail($email); if(login_captcha_needed($email)) $_SESSION['login_cap']=true; audit('login_failed','users',$u?(int)$u['id']:null); throw new DomainException('E-mel atau kata laluan tidak sah.'); }
  // Mesej status akaun hanya selepas kata laluan betul (tiada pendedahan akaun).
  if(!(int)$u['active']){
   audit('login_inactive','users',(int)$u['id']);
   if(($u['approval']??'approved')==='pending') throw new DomainException('Akaun anda sedang menunggu kelulusan pentadbir.'.(!empty($u['self_registered']) && $u['email_verified_at']===null && reg_mode()==='both'?' Sahkan juga e-mel anda melalui pautan yang dihantar.':''));
   if(!empty($u['self_registered']) && $u['email_verified_at']===null) throw new DomainException('Sahkan e-mel anda dahulu melalui pautan yang dihantar semasa pendaftaran.');
   throw new DomainException('Akaun ini tidak aktif. Hubungi pentadbir sistem.');
  }
  if(!user_ip_ok($u)){ audit('login_ip_denied','users',(int)$u['id']); throw new DomainException('Log masuk akaun ini tidak dibenarkan dari alamat IP anda ('.client_ip().').'); }
  shield_success($email); unset($_SESSION['login_cap']);
  if(password_needs_rehash($u['password_hash'],PASSWORD_ARGON2ID,['memory_cost'=>65536,'time_cost'=>3,'threads'=>1])) query('UPDATE users SET password_hash=? WHERE id=? AND school_id='.sid(),[hash_password(input('password')),$u['id']]);
  session_regenerate_id(true); $_SESSION=['csrf'=>bin2hex(random_bytes(32)),'pending_uid'=>(int)$u['id'],'pending_version'=>(int)$u['auth_version'],'pending_at'=>time()];
  if($u['totp_secret']) redirect('/?page=verify');
  if(twofa_required((string)$u['role'])) redirect('/?page=enroll'); login_user($u);
 }
 if(in_array($action,['verify','enroll'],true)){
  $uid=(int)($_SESSION['pending_uid']??$_SESSION['uid']??0);
  if(!$uid || (isset($_SESSION['pending_uid']) && time()-($_SESSION['pending_at']??0)>300)){ $_SESSION=[]; throw new DomainException('Pengesahan tamat. Log masuk semula.'); }
  $rk=rate_key('totp',(string)$uid); if(!rate_allowed($rk)) throw new DomainException('Terlalu banyak cubaan 2FA. Cuba selepas 15 minit.'); rate_hit($rk);
  $u=query('SELECT * FROM users WHERE id=? AND school_id='.sid().' AND active=1',[$uid])->fetch(); if(!$u) throw new DomainException('Akaun tidak tersedia.');
  if(isset($_SESSION['pending_uid']) && (int)($_SESSION['pending_version']??0)!==(int)$u['auth_version']){ $_SESSION=[]; throw new DomainException('Akses berubah. Sila log masuk semula.'); }
  if($action==='enroll'){
   if($u['totp_secret']) throw new DomainException('2FA telah diaktifkan.');
   $secret=$_SESSION['enroll_secret']??''; $step=$secret?totp_step($secret,trim(input('code'))):null;
   if($step===null){ audit('2fa_failed','users',$uid); throw new DomainException('Kod TOTP tidak sah.'); }
   db()->beginTransaction();
   $changed=query('UPDATE users SET totp_secret=?,last_totp_step=? WHERE id=? AND school_id='.sid().' AND totp_secret IS NULL',[encrypt_value($secret),$step,$uid])->rowCount();
   if(!$changed){ db()->rollBack(); throw new DomainException('2FA telah diaktifkan.'); }
   query('DELETE FROM recovery_codes WHERE school_id='.sid().' AND user_id=?',[$uid]); $codes=[];
   for($i=0;$i<8;$i++){ $c=bin2hex(random_bytes(8)); $codes[]=$c; query('INSERT INTO recovery_codes (school_id,user_id,code_hash) VALUES (?,?,?)',[sid(),$uid,hash_hmac('sha256',$c,key_bytes())]); }
   db()->commit(); session_regenerate_id(true); $_SESSION=['uid'=>$uid,'auth_version'=>(int)$u['auth_version'],'csrf'=>bin2hex(random_bytes(32)),'recovery'=>$codes,'started'=>time(),'last'=>time()]; audit('2fa_enabled','users',$uid); redirect('/?page=recovery');
  }
  $code=strtolower(trim(input('code'))); $ok=false;
  if(preg_match('/^[a-f0-9]{16}$/D',$code)) $ok=query('UPDATE recovery_codes SET used_at=NOW() WHERE school_id='.sid().' AND user_id=? AND code_hash=? AND used_at IS NULL',[$uid,hash_hmac('sha256',$code,key_bytes())])->rowCount()===1;
  elseif($u['totp_secret']){ $step=totp_step(decrypt_value($u['totp_secret']),$code); if($step!==null) $ok=query('UPDATE users SET last_totp_step=? WHERE id=? AND school_id='.sid().' AND last_totp_step < ?',[$step,$uid,$step])->rowCount()===1; }
  if(!$ok){ audit('2fa_failed','users',$uid); throw new DomainException('Kod tidak sah atau telah digunakan.'); } login_user($u);
 }
}
function handle_action(string $page,string $action): void {
 if(in_array($action,['login','logout','verify','enroll'])){ handle_auth($action); return; }
 if(in_array($action,['daftar','lupa','reset'],true)) handle_account($action);
 if(!current_user()) { error_page(401,'Sila log masuk.'); }
 if($action==='password'){
  $u=query('SELECT * FROM users WHERE id=? AND school_id='.sid(),[current_user()['id']])->fetch();
  $rk=rate_key('password',(string)$u['id']); if(!rate_allowed($rk)) throw new DomainException('Terlalu banyak cubaan. Cuba selepas 15 minit.'); rate_hit($rk);
  if(!password_verify(input('old_password'),$u['password_hash'])) throw new DomainException('Kata laluan semasa tidak sah.');
  $new=input('new_password'); if(!valid_password($new)) throw new DomainException('Gunakan 12 hingga 128 aksara.');
  if($new!==input('new_password_confirm')) throw new DomainException('Pengesahan kata laluan baharu tidak sepadan.');
  if(password_verify($new,$u['password_hash'])) throw new DomainException('Kata laluan baharu mesti berbeza daripada kata laluan semasa.');
  query('UPDATE users SET password_hash=?,must_change=0,auth_version=auth_version+1 WHERE id=? AND school_id='.sid(),[hash_password($new),$u['id']]); $_SESSION['auth_version']=(int)$u['auth_version']+1; session_regenerate_id(true); audit('password_changed','users',(int)$u['id']); flash('Kata laluan berjaya dikemas kini.'); redirect('/?page=security');
 }
 if($action==='twofa_disable'){
  $u=query('SELECT * FROM users WHERE id=? AND school_id='.sid(),[current_user()['id']])->fetch();
  if(twofa_required((string)$u['role'])) throw new DomainException('2FA diwajibkan untuk peranan anda dan tidak boleh dimatikan.');
  $rk=rate_key('password',(string)$u['id']); if(!rate_allowed($rk)) throw new DomainException('Terlalu banyak cubaan. Cuba selepas 15 minit.');
  if(!password_verify(input('current_password'),$u['password_hash'])){ rate_hit($rk); throw new DomainException('Kata laluan semasa tidak sah.'); }
  db()->beginTransaction(); query('UPDATE users SET totp_secret=NULL,last_totp_step=-1 WHERE id=? AND school_id='.sid(),[$u['id']]); query('DELETE FROM recovery_codes WHERE school_id='.sid().' AND user_id=?',[$u['id']]); audit('2fa_disabled','users',(int)$u['id']); db()->commit();
  flash('2FA dimatikan. Anda boleh mengaktifkannya semula pada bila-bila masa.'); redirect('/?page=security');
 }
 if(!empty(current_user()['must_change'])) throw new DomainException('Tukar kata laluan sementara anda dahulu.');
 if($action==='demo_reset_now') handle_demo_reset_now();
 if(in_array($action,ENGINE_ACTIONS,true)) handle_engine_action($action);
 if(str_starts_with($action,'settings_')) handle_settings($action);
 if(str_starts_with($action,'system_') || str_starts_with($action,'backup_')) error_page(403,'Integriti fail dan sandaran diurus oleh pentadbir platform.'); // edisi multi
 if(str_starts_with($action,'hebahan_')) handle_hebahan($action);
 if(str_starts_with($action,'sk_')) handle_support($action);
 if(str_starts_with($action,'pay_')) handle_payment($action);
 if(str_starts_with($action,'sec_')) handle_security_school($action);
 if(str_starts_with($action,'avatar_')) handle_avatar($action);
 if(str_starts_with($action,'reg_')) handle_registration_admin($action);
 if(str_starts_with($action,'user_') && $action!=='user_create') handle_user_admin($action);
 if($action==='user_create'){
  require_admin(); free_guard('pengguna',1); $name=trim(input('name')); $email=strtolower(trim(input('email'))); $password=input('password'); $role=input('role'); $tid=user_teacher_link($role,0);
  if($name==='' || mb_strlen($name)>120 || strlen($email)>190 || !filter_var($email,FILTER_VALIDATE_EMAIL) || !valid_password($password) || !isset(ROLE_LABELS[$role])) throw new DomainException('Maklumat pengguna tidak sah. Kata laluan minimum 12 aksara.');
  if(query('SELECT id FROM users WHERE school_id='.sid().' AND email=?',[$email])->fetch()) throw new DomainException('E-mel ini sudah didaftarkan.');
  query('INSERT INTO users (school_id,name,email,password_hash,role,must_change,teacher_id) VALUES (?,?,?,?,?,1,?)',[sid(),$name,$email,hash_password($password),$role,$tid]); audit('create','users',(int)db()->lastInsertId(),role_label($role)); flash('Pengguna ditambah. Pengguna perlu menukar kata laluan sementara dan menyediakan 2FA pada log masuk pertama.'); redirect('/?page=users');
 }
 throw new DomainException('Tindakan tidak sah.');
}
