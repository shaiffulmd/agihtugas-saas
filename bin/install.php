<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
$root=dirname(__DIR__);
require $root.'/installer/core.php';
$options=getopt('',['webroot:','check']);
$webroot=$options['webroot']??(basename($root)==='agihtugas-private'?dirname($root).'/public_html':$root.'/public');
foreach(install_requirements($root,$webroot) as $label=>$ok) echo ($ok?'[OK] ':'[GAGAL] ').$label.PHP_EOL;
if(isset($options['check'])) exit(in_array(false,install_requirements($root,$webroot),true)?1:0);
try { $code=install_run($root,$webroot);echo "\nPemasangan siap. Ruang kerja kosong untuk SK/SMK.\nKod pentadbir sekali guna (30 minit): $code\nBuka https://DOMAIN/?page=setup untuk cipta akaun sendiri.\n"; }
catch(Throwable $ex){fwrite(STDERR,$ex->getMessage().PHP_EOL);exit(1);}
