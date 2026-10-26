<?php
declare(strict_types=1);
require __DIR__.'/test_helpers.php';$root=dirname(__DIR__);$dir=sys_get_temp_dir().'/agihtugas-sessions-'.bin2hex(random_bytes(5));mkdir($dir);$servers=[];$checks=0;$cookie=$dir.'/cookies';
function check(bool $ok,string $label):void{global$checks;if(!$ok)throw new RuntimeException('FAIL: '.$label);$checks++;echo"PASS: $label\n";}
function request(int $port,string $page,?array $data=null):array{global$cookie;$c=curl_init("http://127.0.0.1:$port/?page=$page");curl_setopt_array($c,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_COOKIEFILE=>$cookie,CURLOPT_COOKIEJAR=>$cookie,CURLOPT_TIMEOUT=>3]);if($data!==null)curl_setopt_array($c,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>http_build_query($data)]);$body=curl_exec($c);$status=(int)curl_getinfo($c,CURLINFO_HTTP_CODE);unset($c);return[$status,(string)$body];}
try{
 foreach([8101,8102]as$port){$env=getenv();$env['AGIHTUGAS_DB']="$dir/$port.sqlite";fixture_run('seed',$root,$env);$servers[]=proc_open([PHP_BINARY,'-S',"127.0.0.1:$port",'-t',$root.'/public',$root.'/public/router.php'],[0=>['pipe','r'],1=>['file',$dir.'/server.log','a'],2=>['file',$dir.'/server.log','a']],$pipes,$root,$env);}
 for($i=0;$i<30;$i++){usleep(100000);[$code,$html]=request(8101,'login');if($code===200)break;}
 preg_match('/name="csrf" value="([^\"]+)"/',$html,$m);[$code]=request(8101,'login',['csrf'=>$m[1],'action'=>'login','email'=>'admin@example.test','password'=>'Test-only-password-2026']);check($code===303,'first installation login succeeds');
 [$code]=request(8101,'accounts');check($code===200,'session authorizes original installation');
 [$code]=request(8102,'accounts');check($code===303,'same cookie cannot authorize another installation with matching account IDs');
 [$code]=request(8101,'accounts');check($code===200,'other installation does not invalidate original session');
 check(str_contains(file_get_contents($cookie),'AGIHTUGAS_'),'installation-specific session cookie name');
 echo"\n$checks session isolation checks passed.\n";
}finally{foreach($servers as$s){proc_terminate($s);proc_close($s);}foreach(glob($dir.'/*')as$f)unlink($f);rmdir($dir);}
