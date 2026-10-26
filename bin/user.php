<?php
// Pemulihan akaun melalui CLI pelayan (edisi multi).
//   php bin/user.php KOD create|password|reset-2fa       → akaun pentadbir/pengguna sekolah KOD
//   php bin/user.php platform create|password|reset-2fa  → akaun Pentadbir Platform
if(PHP_SAPI!=='cli') exit;
require dirname(__DIR__).'/app/bootstrap.php';
require dirname(__DIR__).'/app/install.php';
$scope=$argv[1]??''; $mode=$argv[2]??'create';
if($scope==='' || !in_array($mode,['create','password','reset-2fa'],true)) exit("Guna: php bin/user.php KOD|platform [create|password|reset-2fa]\n");
if(!table_exists('schools')) exit("Sistem belum dipasang. Jalankan: php bin/install.php\n");
$platform=$scope==='platform';
if(!$platform){ $s=school_find($scope); if(!$s) exit("Sekolah $scope tidak dijumpai.\n"); tenant_use($s,'path'); }
$table=$platform?'platform_admins':'users'; $where=$platform?'':' AND school_id='.sid();
try {
 $email=strtolower(cli_ask('E-mel: '));
 if(!filter_var($email,FILTER_VALIDATE_EMAIL)) throw new DomainException('E-mel tidak sah.');
 if($mode==='reset-2fa'){
  $u=query('SELECT id FROM '.$table.' WHERE email=?'.$where,[$email])->fetch(); if(!$u) throw new DomainException('Pengguna tidak dijumpai.');
  if(cli_ask('Taip RESET untuk menetapkan semula 2FA: ')!=='RESET') exit("Dibatalkan.\n");
  query('UPDATE '.$table.' SET totp_secret=NULL,last_totp_step=-1,auth_version=auth_version+1 WHERE id=?'.$where,[$u['id']]);
  if(!$platform){ query('DELETE FROM recovery_codes WHERE school_id='.sid().' AND user_id=?',[$u['id']]); audit('cli_2fa_reset','users',(int)$u['id']); }
  exit("2FA ditetapkan semula. Wajib daftar semula pada log masuk seterusnya.\n");
 }
 if($mode==='create'){
  $n=(int)query('SELECT COUNT(*) FROM '.$table.($platform?'':' WHERE role="admin"'.$where))->fetchColumn();
  if($n>0 && cli_ask("Sudah ada $n akaun pentadbir. Taip TAMBAH ADMIN untuk mencipta pentadbir lain: ")!=='TAMBAH ADMIN') exit("Dibatalkan.\n");
  $name=cli_ask('Nama: ');
 } else {
  $u=query('SELECT id,name FROM '.$table.' WHERE email=?'.$where,[$email])->fetch(); if(!$u) throw new DomainException('Pengguna tidak dijumpai.');
  $name=$u['name'];
 }
 $password=cli_secret('Kata laluan (12–128 aksara): '); $confirm=cli_secret('Ulang kata laluan: ');
 [$name,$email]=validate_admin($name,$email,$password,$confirm);
 if($mode==='create') $id=$platform?create_platform_admin($name,$email,$password):create_admin($name,$email,$password);
 else { $id=(int)$u['id']; query('UPDATE '.$table.' SET password_hash=?,'.($platform?'':'must_change=0,').'auth_version=auth_version+1 WHERE id=?'.$where,[hash_password($password),$id]); }
 if(!$platform) audit('cli_'.$mode,'users',$id);
 sodium_memzero($password); sodium_memzero($confirm);
 echo "Akaun berjaya dikemas kini.\n";
} catch(DomainException $ex) { fwrite(STDERR,'Ralat: '.$ex->getMessage()."\n"); exit(1); }
