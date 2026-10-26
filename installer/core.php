<?php
declare(strict_types=1);
function install_requirements(string $root,string $webroot): array {
 $checks=['PHP 8.2 atau lebih baharu'=>PHP_VERSION_ID>=80200];
 foreach(['pdo_sqlite','mbstring','gd','fileinfo','session'] as $ext) $checks['PHP '.$ext]=extension_loaded($ext);
 $checks['Aset Jata Negara tersedia']=is_file($root.'/public/assets/shared/jata-negara.png');
 $checks['Storage boleh ditulis']=is_dir($root.'/storage')&&is_writable($root.'/storage');
 $checks['Config boleh ditulis']=is_dir($root.'/config')&&is_writable($root.'/config');
 $private=realpath($root);$public=realpath($webroot);
 $checks['Kod dan data di luar document root']=$private&&$public&&$private!==$public&&!str_starts_with($private,$public.DIRECTORY_SEPARATOR);
 return $checks;
}
function install_run(string $root,string $webroot): string {
 global $db;
 foreach(install_requirements($root,$webroot) as $name=>$ok) if(!$ok) throw new RuntimeException('Semakan gagal: '.$name);
 $lock=fopen($root.'/storage/install.lock','c');
 if(!$lock||!flock($lock,LOCK_EX|LOCK_NB)) throw new RuntimeException('Pemasangan lain sedang berjalan.');
 $path=$root.'/storage/agihtugas.sqlite';$config=$root.'/config/local.php';$created=false;$pending=null;
 try {
  if(is_file($config)||file_exists($path)) throw new RuntimeException('Pemasangan atau pangkalan data sudah wujud. Tiada data ditindih.');
  $file=fopen($path,'x');if(!$file) throw new RuntimeException('Tidak dapat mencipta pangkalan data.');
  fclose($file);chmod($path,0600);$created=true;
  putenv('AGIHTUGAS_DB='.$path);putenv('AGIHTUGAS_SEED_DEMO=0');
  require $root.'/app/database.php';
  $code=bin2hex(random_bytes(16));
  q('INSERT INTO setup_codes VALUES(1,?,?)',[hash('sha256',$code),time()+1800]);
  $content="<?php\nreturn ".var_export(['database'=>$path,'seed_demo'=>false,'installed_at'=>date(DATE_ATOM)],true).";\n";
  $pending=tempnam($root.'/config','install-');
  if(!$pending||file_put_contents($pending,$content,LOCK_EX)!==strlen($content)) throw new RuntimeException('Konfigurasi tidak lengkap.');
  chmod($pending,0600);
  if(is_file($config)||!rename($pending,$config)) throw new RuntimeException('Tidak dapat menyimpan konfigurasi.');
  return $code;
 } catch(Throwable $ex) {
  if($created&&!is_file($config)) {
   $GLOBALS['db']=null;$db=null;
   foreach(['','-wal','-shm','-journal'] as $suffix) if(is_file($path.$suffix)) unlink($path.$suffix);
  }
  throw $ex;
 } finally { if($pending&&is_file($pending))unlink($pending);flock($lock,LOCK_UN);fclose($lock); }
}
