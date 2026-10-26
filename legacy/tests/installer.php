<?php
declare(strict_types=1);
$root=dirname(__DIR__);$temp=sys_get_temp_dir().'/agihtugas-installer-'.bin2hex(random_bytes(5));mkdir($temp,0700);$temp=realpath($temp);
$checks=0;
function check(bool $ok,string $label):void {global $checks;$checks++;if(!$ok)throw new RuntimeException('FAIL: '.$label);echo "OK $label\n";}
function run_script(array $args,?string $cwd=null):array {
 $env=getenv();unset($env['AGIHTUGAS_DB'],$env['AGIHTUGAS_SEED_DEMO']);
 $proc=proc_open($args,[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,$cwd,$env);fclose($pipes[0]);$out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);return [proc_close($proc),$out,$err];
}
function web_request(string $private,string $public,array $post=[],bool $https=true):array {
 global $temp;
 $script=$temp.'/request.php';
 file_put_contents($script,"<?php \$_SERVER['REQUEST_METHOD']=".var_export($post?'POST':'GET',true)."; \$_SERVER['HTTPS']=".var_export($https?'on':'off',true)."; \$_POST=".var_export($post,true)."; \$webroot=".var_export($public,true)."; register_shutdown_function(function(){echo '\nSTATUS='.http_response_code();}); require ".var_export($private.'/installer/web.php',true).";");
 return run_script([PHP_BINARY,$script]);
}
try {
 [$exit,$out,$err]=run_script([PHP_BINARY,$root.'/bin/build-release.php','--output='.$temp.'/packages']);
 check($exit===0&&$err==='','build both packages without warnings');
 foreach(['cpanel','vps'] as $type){
  $zip=new ZipArchive();check($zip->open($temp.'/packages/agihtugas-'.$type.'.zip')===true,$type.' zip opens');
  $names=[];for($i=0;$i<$zip->numFiles;$i++)$names[]=$zip->getNameIndex($i);
  check(!array_filter($names,fn($n)=>preg_match('~(^|/)(\.git|\.env|tests)(/|$)|\.(sqlite|db)$|config/local\.php$|branding-preview~',$n)),$type.' no live data or config');
  check((bool)array_filter($names,fn($n)=>str_ends_with($n,'jata-negara.png')),$type.' shared crest included');
  mkdir($temp.'/'.$type);$zip->extractTo($temp.'/'.$type);$zip->close();
 }
 $private=$temp.'/cpanel/agihtugas-private';$public=$temp.'/cpanel/public_html';
 check(is_file($public.'/.htaccess')&&is_file($public.'/install.php'),'cpanel rewrite and installer included');
 check(!is_dir($public.'/app')&&!is_dir($public.'/storage')&&!is_dir($public.'/config'),'private directories outside webroot');
 $key=require $private.'/config/install-key.php';check(strlen($key)===64,'unique install key present');
 [$exit,$out]=run_script([PHP_BINARY,$private.'/bin/install.php','--check','--webroot='.$public]);
 check($exit===0&&!file_exists($private.'/storage/agihtugas.sqlite'),'preflight does not create DB');
 [$exit,$out]=web_request($private,$public,['install_key'=>'invalid']);check(str_contains($out,'STATUS=403')&&!file_exists($private.'/config/local.php'),'invalid key rejected');
 [$exit,$out]=web_request($private,$public,['install_key'=>$key],false);check(str_contains($out,'HTTPS sebelum')&&!file_exists($private.'/storage/agihtugas.sqlite'),'HTTP install rejected');
 [$exit,$out,$err]=web_request($private,$public,['install_key'=>$key]);
 check($exit===0&&$err===''&&str_contains($out,'Pemasangan siap'),'web installation completes');
 check(!is_file($private.'/config/install-key.php'),'one-time install key removed');
 $config=require $private.'/config/local.php';check($config['seed_demo']===false&&$config['database']===$private.'/storage/agihtugas.sqlite','private config stores production DB');
 $db=new PDO('sqlite:'.$config['database']);
 check((int)$db->query('SELECT COUNT(*) FROM teachers')->fetchColumn()===0&&(int)$db->query('SELECT COUNT(*) FROM assignments')->fetchColumn()===0,'production has no demo teachers or assignments');
 check((int)$db->query('SELECT COUNT(*) FROM accounts')->fetchColumn()===0,'no default account or password');
 preg_match('~<code>([a-f0-9]{32})</code>~',$out,$match);$setup=$db->query('SELECT * FROM setup_codes')->fetch(PDO::FETCH_ASSOC);
 check(isset($match[1])&&hash_equals($setup['code_hash'],hash('sha256',$match[1]))&&(int)$setup['expires_at']>time(),'setup code hashed and expiring');
 $requestCode='$_SERVER["REQUEST_METHOD"]="GET";$_SERVER["REQUEST_URI"]="/?page=setup";$_SERVER["HTTPS"]="on";$_GET["page"]="setup";require '.var_export($public.'/index.php',true).';';
 [$exit,$page,$err]=run_script([PHP_BINARY,'-r',$requestCode]);
 check($exit===0&&$err===''&&str_contains($page,'name="setup_code"'),'packaged cPanel frontend reads installed config and renders setup');
 $before=hash_file('sha256',$config['database']);
 [$exit,$out]=web_request($private,$public,['install_key'=>$key]);check(str_contains($out,'STATUS=404'),'web installer locked after installation');
 [$exit,$out,$err]=run_script([PHP_BINARY,$private.'/bin/install.php','--webroot='.$public]);
 check($exit===1&&str_contains($err,'Tiada data ditindih')&&hash_file('sha256',$config['database'])===$before,'CLI refuses reinstall and preserves DB');
 $vps=$temp.'/vps/agihtugas-private';
 check(is_file($vps.'/deploy/apache.conf')&&is_file($vps.'/deploy/nginx.conf'),'VPS includes Apache and Nginx templates');
 $apache=file_get_contents($vps.'/deploy/apache.conf');
 check(str_contains($apache,'proxy:unix:__SOCKET__|fcgi://localhost/')&&str_contains($apache,'RewriteRule ^ index.php [END]'),'Apache template routes UI/API to FPM front controller');
 check(str_contains($apache,'AllowOverride None')&&str_contains($apache,'Require all denied'),'Apache protects private file types and ignores per-directory overrides');
 check(str_contains($apache,'DocumentRoot "__WEBROOT__"')&&!str_contains($apache,'__ROOT__/public'),'Apache document root uses packaged public_html');
 check(is_file($temp.'/vps/public_html/index.php')&&is_dir($temp.'/vps/public_html/assets')&&!is_dir($temp.'/vps/public_html/app'),'VPS public_html contains frontend only');
 $shell=file_get_contents($vps.'/deploy/install-vps.sh');
 check(str_contains($shell,'--webroot="$vps_webroot"')&&str_contains($shell,'/public_html'),'VPS installer validates actual public_html');
 check(str_contains($shell,'vps_server=${4:-apache}')&&str_contains($shell,'apachectl configtest'),'VPS defaults to Apache and provides syntax check command');

 [$exit,$out,$err]=run_script([PHP_BINARY,$vps.'/bin/install.php']);check($exit===0&&$err===''&&str_contains($out,'Pemasangan siap'),'VPS CLI installation succeeds');
 [$exit]=run_script(['bash','-n',$vps.'/deploy/install-vps.sh']);check($exit===0,'VPS shell syntax valid');
 [$exit]=run_script([PHP_BINARY,$root.'/bin/build-release.php','--output='.$temp.'/packages']);check($exit===1,'builder refuses to overwrite packages');
 // Distinct packages must not share the installer secret.
 [$exit]=run_script([PHP_BINARY,$root.'/bin/build-release.php','--target=cpanel','--output='.$temp.'/second']);
 $zip=new ZipArchive();$zip->open($temp.'/second/agihtugas-cpanel.zip');$other=$zip->getFromName('agihtugas-private/config/install-key.php');$zip->close();check(!str_contains($other,$key),'fresh package has a fresh key');
 // Existing DB, even without config, is never claimed or erased by installation.
 unlink($vps.'/config/local.php');$existing=hash_file('sha256',$vps.'/storage/agihtugas.sqlite');
 [$exit]=run_script([PHP_BINARY,$vps.'/bin/install.php']);check($exit===1&&hash_file('sha256',$vps.'/storage/agihtugas.sqlite')===$existing,'orphan existing DB protected');
 // Refuse an exposed private directory.
 [$exit,$out]=run_script([PHP_BINARY,$vps.'/bin/install.php','--check','--webroot='.$temp.'/vps']);check($exit===1&&str_contains($out,'[GAGAL] Kod dan data'),'unsafe document root rejected');
 echo "$checks installer checks passed.\n";
} finally {
 $db=null;
 $it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($temp,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
 foreach($it as $f){if($f->isDir())rmdir($f->getPathname());else unlink($f->getPathname());}rmdir($temp);
}
