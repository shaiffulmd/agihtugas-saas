<?php
declare(strict_types=1);
require __DIR__.'/test_helpers.php';
$root=dirname(__DIR__); $temp=sys_get_temp_dir().'/agihtugas-workflow-'.bin2hex(random_bytes(5)); mkdir($temp);
$env=getenv(); $env['AGIHTUGAS_DB']=$temp.'/test.sqlite'; fixture_run('seed',$root,$env);
$db=new PDO('sqlite:'.$temp.'/test.sqlite'); $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE,PDO::FETCH_ASSOC);
$key='agt_'.bin2hex(random_bytes(32)); $db->prepare('INSERT INTO api_keys(name,token_hash,prefix,scope) VALUES (?,?,?,?)')->execute(['test',hash('sha256',$key),substr($key,0,12),'write']);
$server=proc_open([PHP_BINARY,'-S','127.0.0.1:8100','-t',$root.'/public',$root.'/public/router.php'],[0=>['pipe','r'],1=>['file',$temp.'/server.log','a'],2=>['file',$temp.'/server.log','a']],$pipes,$root,$env);
$tokens=[];$checks=0;
function check(bool $ok,string $label): void { global $checks;if(!$ok)throw new RuntimeException('FAIL: '.$label);$checks++;echo "PASS: $label\n"; }
function web(string $who,string $page,?array $data=null): array {
 global $temp,$tokens;
 $c=curl_init('http://127.0.0.1:8100/?page='.$page);$cookie=$temp.'/'.$who.'.cookie';
 curl_setopt_array($c,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_COOKIEFILE=>$cookie,CURLOPT_COOKIEJAR=>$cookie,CURLOPT_TIMEOUT=>5]);
 if($data!==null)curl_setopt_array($c,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>http_build_query(['csrf'=>$tokens[$who]??'',...$data])]);
 $html=curl_exec($c);$status=(int)curl_getinfo($c,CURLINFO_HTTP_CODE);unset($c);if(str_contains($html,'<b>Deprecated</b>')||str_contains($html,'<b>Warning</b>'))throw new RuntimeException('Unexpected PHP diagnostic in UI response');
 if(preg_match('/name="csrf" value="([^\"]+)"/',$html,$m))$tokens[$who]=$m[1];return[$status,(string)$html];
}
function login(string $who,string $email,string $password='Test-only-password-2026'): int { web($who,'login');[$code]=web($who,'login',['action'=>'login','email'=>$email,'password'=>$password]);web($who,'profile');return $code; }
function version(): int {global $db;return(int)$db->query('SELECT version FROM workflow_state')->fetchColumn();}
function transition(string $who,string $action,string $reason='',?int $v=null): array {return web($who,'workflow',['action'=>'workflow','transition'=>$action,'version'=>$v??version(),'reason'=>$reason]);}
function api_change(): int { global $key;$c=curl_init('http://127.0.0.1:8100/api/v1/teachers');curl_setopt_array($c,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_POST=>true,CURLOPT_HTTPHEADER=>['Authorization: Bearer '.$key,'Content-Type: application/json'],CURLOPT_POSTFIELDS=>'{"name":"API Locked","capacity":25}']);curl_exec($c);$status=(int)curl_getinfo($c,CURLINFO_HTTP_CODE);unset($c);return$status; }
function api_read(string $path): array {global $key;$c=curl_init('http://127.0.0.1:8100/api/v1'.$path);curl_setopt_array($c,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_HTTPHEADER=>['Authorization: Bearer '.$key]]);$raw=curl_exec($c);$code=(int)curl_getinfo($c,CURLINFO_HTTP_CODE);unset($c);return[$code,json_decode($raw,true)];}
try {
 for($i=0;$i<30;$i++){usleep(100000);[$code]=web('anon','login');if($code===200)break;}
 [$code]=web('anon','teachers');check($code===303,'anonymous UI is gated');
 check(login('admin','admin@example.test')===303,'admin login');check(login('pk','pk@example.test')===303,'PK login');check(login('head','head@example.test')===303,'head login');check(login('teacher','teacher@example.test')===303,'teacher login');
 [$code]=web('teacher','teachers');check($code===403,'teacher cannot list other profiles');
 [$code]=web('teacher','letters&teacher=2&all=1');check($code===403,'teacher cannot request another teacher letter');
 [$code]=web('pk','accounts');check($code===403,'PK cannot manage accounts');
 [$code]=web('head','assignments');check($code===403,'head cannot silently edit draft');
 [$code,$html]=web('admin','accounts');check($code===200&&!str_contains($html,'$2y$'),'account list excludes password hashes');
 [$code]=web('teacher','profile',['action'=>'teacher','name'=>'Forged','capacity'=>25]);check($code===403,'forged teacher POST rejected');
 [$code]=web('pk','workflow',['action'=>'settings','school'=>'Wrong','principal'=>'Wrong','year'=>2027,'reference'=>'Wrong']);check($code===403,'PK cannot edit school settings');
 [$code]=web('admin','letters&teacher=1');check($code===200&&!str_contains(web('admin','letters')[1],'<article class="letter">'),'letters unavailable before finalization');
 $old=version();[$code]=transition('pk','submit');check($code===302,'PK submits draft');
 check(api_change()===409,'API write blocked during review');
 [$code]=web('admin','teachers',['action'=>'teacher','name'=>'Locked','capacity'=>25]);check($code===409,'UI write blocked during review');
 [$code]=transition('head','finalize');check($code===409,'cannot finalize before approval');
 [$code]=transition('pk','approve');check($code===403,'PK cannot approve');
 [$code]=transition('head','approve','',$old);check($code===409,'stale workflow version rejected');
 [$code]=transition('head','return');check($code===422,'return requires reason');
 [$code]=transition('head','return','Sila semak beban');check($code===302,'head returns draft with reason');
 check(api_change()===201,'draft reopened for API writes');
 [$code]=transition('admin','submit');check($code===302,'admin resubmits draft');
 [$code]=transition('head','approve');check($code===302,'head approves');
 check(api_change()===409,'approved data still locked');
 [$code]=transition('head','finalize');check($code===302,'head finalizes');
 check(api_change()===409,'finalized data locked from API');
 [$apiCode,$stateJson]=api_read('/workflow');check($apiCode===200&&$stateJson['data']['status']==='finalized','API exposes workflow status');
 [$apiCode,$publishedJson]=api_read('/workflow/published');check($apiCode===200&&isset($publishedJson['data']['snapshot']['positions']),'API exposes published snapshot');
 [$code,$html]=web('admin','letters&teacher=1');check($code===200&&str_contains($html,'PELANTIKAN TUGAS')&&str_contains($html,'VERSI MUKTAMAD'),'finalized letter available');
 $letter=$html;[$code,$own]=web('teacher','my_tasks&teacher=2&all=1');check($code===200&&str_contains($own,'Nur Aisyah Abdullah')&&!str_contains($own,'Muhammad Hafiz Ahmad'),'teacher sees own published tasks only');
 [$code]=transition('admin','return','Pindaan tugas sesi');check($code===302,'finalized version can open a revision draft');
 $db->exec("UPDATE positions SET name='Draft Changed Position' WHERE id=1");
 [$code,$html]=web('admin','letters&teacher=1');check(!str_contains($html,'Draft Changed Position')&&str_contains($html,'Setiausaha Pengurusan'),'published letter immutable during draft changes');
 [$apiCode,$letterJson]=api_read('/letters/1');check($apiCode===200&&$letterJson['data']['assignments'][0]['position_name']==='Setiausaha Pengurusan','API letter also reads frozen snapshot');
 [$code]=transition('admin','submit');check($code===302,'new revision submitted');
 $db->exec("UPDATE positions SET weight=9 WHERE id=1");
 [$code]=transition('head','approve');check($code===409,'out-of-band data change invalidates approval');
 [$code]=transition('head','return','Data berubah');check($code===302,'changed revision returned');
 [$code]=transition('admin','submit');[$code]=transition('head','approve');[$code]=transition('head','finalize');check($code===302,'revised snapshot finalized');
 [$code,$html]=web('teacher','my_tasks');check(str_contains($html,'Draft Changed Position'),'teacher receives new finalized revision');
 [$code]=web('admin','accounts',['action'=>'account_save','id'=>4,'name'=>'Teacher One','email'=>'teacher@example.test','role'=>'teacher','teacher_id'=>1]);check($code===302,'admin deactivates account');
 [$code]=web('teacher','my_tasks');check($code===303,'deactivation revokes existing session');
 [$code]=web('admin','accounts',['action'=>'account_save','id'=>1,'name'=>'Test Admin','email'=>'admin@example.test','role'=>'pk','active'=>'on']);check($code===422,'admin cannot demote own last admin account');
 [$code]=web('admin','accounts',['action'=>'account_save','id'=>4,'name'=>'Teacher One','email'=>'teacher@example.test','role'=>'teacher','teacher_id'=>1,'active'=>'on']);check($code===302,'reactivate account');
 check(login('teacher','teacher@example.test')===303,'reactivated teacher can login');check(login('teacher-other','teacher@example.test')===303,'second session starts');
 [$code]=web('teacher','profile',['action'=>'profile_password','current_password'=>'Wrong','password'=>'Another-test-password','confirm_password'=>'Another-test-password']);check($code===422,'password change verifies current password');
 [$code]=web('teacher','profile',['action'=>'profile_password','current_password'=>'Test-only-password-2026','password'=>'Another-test-password','confirm_password'=>'Another-test-password']);check($code===302,'self password change succeeds');
 [$code]=web('teacher-other','my_tasks');check($code===303,'password change revokes other sessions');
 [$code]=web('teacher','profile',['action'=>'logout']);check($code===303,'logout');[$code]=web('teacher','my_tasks');check($code===303,'logged out session rejected');
 web('brute','login');for($i=0;$i<5;$i++)web('brute','login',['action'=>'login','email'=>'missing@example.test','password'=>'Wrong']);[$code]=web('brute','login',['action'=>'login','email'=>'missing@example.test','password'=>'Wrong']);check($code===429,'login attempts throttled');
 $status=$db->query('SELECT status FROM workflow_state')->fetchColumn();check($status==='finalized','workflow remains finalized after account changes');
 check((int)$db->query("SELECT COUNT(*) FROM history WHERE description LIKE '%→%'")->fetchColumn()>=8,'workflow decisions audited');
 echo "\n$checks authentication and workflow checks passed.\n";
} finally {proc_terminate($server);proc_close($server);$db=null;foreach(glob($temp.'/*')as$f)unlink($f);rmdir($temp);}
