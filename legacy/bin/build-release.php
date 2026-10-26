<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
umask(0077);
$options=getopt('',['target:','output:']);$target=$options['target']??'all';
if(!in_array($target,['cpanel','vps','all'],true)){fwrite(STDERR,"--target=cpanel|vps|all\n");exit(1);}
if(!class_exists('ZipArchive')){fwrite(STDERR,"Pembina pakej memerlukan PHP zip. Pelayan sasaran tidak memerlukannya.\n");exit(1);}
$root=dirname(__DIR__);$output=$options['output']??$root.'/dist';
if(!is_dir($output)&&!mkdir($output,0700,true))exit(1);
foreach($target==='all'?['cpanel','vps']:[$target] as $type){
 $path=$output.'/agihtugas-'.$type.'.zip';
 if(file_exists($path)){fwrite(STDERR,"Pakej sudah wujud: $path. Gunakan folder output baharu.\n");exit(1);}
 $zip=new ZipArchive();if($zip->open($path,ZipArchive::CREATE|ZipArchive::EXCL)!==true)exit(1);
 $private='agihtugas-private';
 // Explicit source allowlist: never copy runtime data, secrets, .git or tests.
 foreach(['app','installer','public','integrations/koku-multi'] as $folder){
  $it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/'.$folder,FilesystemIterator::SKIP_DOTS));
  foreach($it as $file){
   if(!$file->isFile()||$file->isLink()||$file->getFilename()==='config.local.php')continue;
   $relative=substr($file->getPathname(),strlen($root)+1);
   $zip->addFile($file->getPathname(),$private.'/'.$relative);
  }
 }
 foreach(['install.php','setup-admin.php','api-key.php'] as $file)$zip->addFile($root.'/bin/'.$file,$private.'/bin/'.$file);
 $zip->addEmptyDir($private.'/config');$zip->addEmptyDir($private.'/storage');
 $zip->addFromString($private.'/storage/.gitkeep','');
 $zip->addFile($root.'/docs/PEMASANGAN.md',$private.'/PEMASANGAN.md');
 if($type==='cpanel'){
  $zip->addFromString($private.'/.htaccess',"Require all denied\n");
  $key=bin2hex(random_bytes(32));$zip->addFromString($private.'/config/install-key.php',"<?php return ".var_export($key,true).";\n");
  $zip->setExternalAttributesName($private.'/config/install-key.php',ZipArchive::OPSYS_UNIX,0100600<<16);
  $zip->addFromString('INSTALL.txt',"AGIHTUGAS — CPANEL\nEkstrak di /home/USERNAME (BUKAN public_html).\nPakej ini mengandungi agihtugas-private/ dan public_html/.\nGunakan domain/subdomain khusus pada root public_html, bukan subfolder.\nJangan tindih laman sedia ada. Rujuk agihtugas-private/PEMASANGAN.md.\nAktifkan HTTPS dan buka https://DOMAIN/install.php\nKunci pemasangan: $key\nJangan muat naik INSTALL.txt ke folder awam.\n");
  $zip->addFromString('public_html/install.php',"<?php\n\$webroot=__DIR__;\nrequire dirname(__DIR__).'/agihtugas-private/installer/web.php';\n");
 }else{
  $zip->addFile($root.'/deploy/install-vps.sh',$private.'/deploy/install-vps.sh');
  $zip->setExternalAttributesName($private.'/deploy/install-vps.sh',ZipArchive::OPSYS_UNIX,0100750<<16);
  foreach(['apache','nginx'] as $server)$zip->addFile($root.'/deploy/'.$server.'.conf',$private.'/deploy/'.$server.'.conf');
 }
  $zip->addFromString('public_html/index.php',"<?php\n\$root=dirname(__DIR__).'/agihtugas-private';\nif(!is_file(\$root.'/config/local.php')){http_response_code(503);echo 'AgihTugas belum dipasang. Rujuk panduan pemasangan.';exit;}\nrequire \$root.'/public/index.php';\n");
  $zip->addFile($root.'/deploy/apache.htaccess','public_html/.htaccess');
  foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/public/assets',FilesystemIterator::SKIP_DOTS)) as $f) if($f->isFile()&&!$f->isLink())$zip->addFile($f->getPathname(),'public_html/assets/'.substr($f->getPathname(),strlen($root.'/public/assets/')));
  foreach(['api-docs.html','openapi.json'] as $f)$zip->addFile($root.'/public/'.$f,'public_html/'.$f);
 if(!$zip->close())throw new RuntimeException('Gagal menyiapkan ZIP.');
 chmod($path,0600);
 echo "Pakej siap: $path\n";
}
