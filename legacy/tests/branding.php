<?php
declare(strict_types=1);
require __DIR__.'/test_helpers.php';$root=dirname(__DIR__);$dir=sys_get_temp_dir().'/agihtugas-branding-'.bin2hex(random_bytes(5));mkdir($dir);$env=getenv();$env['AGIHTUGAS_DB']=$dir.'/test.sqlite';fixture_run('seed',$root,$env);
$server=proc_open([PHP_BINARY,'-S','127.0.0.1:8103','-t',$root.'/public',$root.'/public/router.php'],[0=>['pipe','r'],1=>['file',$dir.'/server.log','a'],2=>['file',$dir.'/server.log','a']],$pipes,$root,$env);
$db=new PDO('sqlite:'.$dir.'/test.sqlite');$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE,PDO::FETCH_ASSOC);$tokens=[];$checks=0;
function check(bool $ok,string $label):void{global$checks;if(!$ok)throw new RuntimeException('FAIL: '.$label);$checks++;echo"PASS: $label\n";}
function web(string $who,string $page,?array $data=null):array{global$dir,$tokens;$c=curl_init('http://127.0.0.1:8103/?page='.$page);$cookie=$dir.'/'.$who.'.cookie';curl_setopt_array($c,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_COOKIEJAR=>$cookie,CURLOPT_COOKIEFILE=>$cookie,CURLOPT_TIMEOUT=>5]);if($data!==null){$data=['csrf'=>$tokens[$who]??'',...$data];curl_setopt_array($c,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>isset($data['school_logo'])?$data:http_build_query($data)]);}$body=curl_exec($c);$status=(int)curl_getinfo($c,CURLINFO_HTTP_CODE);$type=curl_getinfo($c,CURLINFO_CONTENT_TYPE);unset($c);if(preg_match('/name="csrf" value="([^\"]+)"/',(string)$body,$m))$tokens[$who]=$m[1];return[$status,(string)$body,$type];}
function login(string $who,string $email):void{web($who,'login');web($who,'login',['action'=>'login','email'=>$email,'password'=>'Test-only-password-2026']);web($who,'profile');}
try{
 for($i=0;$i<30;$i++){usleep(100000);[$code]=web('anon','login');if($code===200)break;}
 login('admin','admin@example.test');login('pk','pk@example.test');login('teacher','teacher@example.test');
 [$code,$html]=web('admin','settings');check($code===200&&str_contains($html,'Logo sekolah &amp; Jata Negara')===false&&str_contains($html,'Logo sekolah & Jata Negara'),'branding controls render');
 $crest=$db->query('SELECT value FROM settings WHERE key="national_crest_id"')->fetchColumn();check((bool)preg_match('/^[a-f0-9]{64}$/',$crest),'bundled crest stored as immutable asset');
 [$code,$bytes,$type]=web('anon','asset&id='.$crest);check($code===303,'uploaded asset endpoint requires login');
 [$code,$bytes,$type]=web('teacher','asset&id='.$crest);check($code===200&&$type==='image/png'&&str_starts_with($bytes,"\x89PNG"),'authenticated teacher can load letter images');
 [$code]=web('teacher','asset&id[]=x');check($code===404,'array and invalid asset IDs rejected');
 $logo=$dir.'/logo.png';copy($root.'/public/assets/shared/jata-negara.png',$logo);
 [$code]=web('pk','profile',['action'=>'school_logo_upload','school_logo'=>new CURLFile($logo,'image/png','logo.png')]);check($code===403,'PK cannot upload school identity');
 [$code]=web('admin','settings',['action'=>'school_logo_upload','school_logo'=>new CURLFile($logo,'image/png','logo.png')]);check($code===302,'admin uploads PNG logo');
 $id=$db->query('SELECT value FROM settings WHERE key="school_logo_id"')->fetchColumn();$a=$db->query("SELECT * FROM school_assets WHERE id='$id'")->fetch();check(max($a['width'],$a['height'])<=640&&str_starts_with($a['bytes'],"\x89PNG"),'logo rescaled and reencoded to PNG');
 [$code]=web('admin','settings',['action'=>'letter_branding','letter_header'=>'dual']);check($code===302,'save dual emblem layout');
 fixture_run('publish',$root,$env);[$code,$html]=web('teacher','my_tasks');check($code===200&&str_contains($html,'alt="Jata Negara"')&&str_contains($html,'alt="Logo sekolah"')&&str_contains($html,$id),'finalized own letter includes both assets');
 [$code]=web('admin','settings',['action'=>'school_logo_remove']);check($code===409,'branding changes blocked after submission/finalization');
 fixture_run('reopen',$root,$env);[$code]=web('admin','settings',['action'=>'school_logo_remove']);check($code===302,'remove logo from draft');
 [$code,$html]=web('teacher','my_tasks');check(str_contains($html,$id),'published letter keeps previous logo');
 [$code,$bytes]=web('teacher','asset&id='.$id);check($code===200&&str_starts_with($bytes,"\x89PNG"),'old logo bytes retained after removal');
 $svg=$dir.'/bad.svg';file_put_contents($svg,'<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>');
 [$code]=web('admin','settings',['action'=>'school_logo_upload','school_logo'=>new CURLFile($svg,'image/png','fake.png')]);check($code===422,'SVG disguised as PNG rejected');
 $bad=$dir.'/bad.png';file_put_contents($bad,'not an image');[$code]=web('admin','settings',['action'=>'school_logo_upload','school_logo'=>new CURLFile($bad,'image/png','bad.png')]);check($code===422,'invalid image rejected');
 $large=$dir.'/large.png';file_put_contents($large,str_repeat('x',2097153));[$code]=web('admin','settings',['action'=>'school_logo_upload','school_logo'=>new CURLFile($large,'image/png','large.png')]);check($code===422,'upload over 2 MB rejected');
 [$code]=web('admin','settings',['csrf'=>'invalid','action'=>'letter_branding','letter_header'=>'national']);check($code===403,'branding requires CSRF');
 [$code]=web('admin','settings',['action'=>'letter_branding','letter_header'=>'unknown']);check($code===422,'unknown letter header rejected');
 [$code]=web('admin','settings',['action'=>'letter_branding','letter_header'=>'national']);fixture_run('publish_draft',$root,$env);[$code,$html]=web('teacher','my_tasks');check(str_contains($html,'alt="Jata Negara"')&&!str_contains($html,'alt="Logo sekolah"'),'national-only layout after next publication');
 $key='agt_'.bin2hex(random_bytes(32));$db->prepare('INSERT INTO api_keys(name,token_hash,prefix,scope) VALUES (?,?,?,?)')->execute(['asset test',hash('sha256',$key),substr($key,0,12),'read']);
 $c=curl_init('http://127.0.0.1:8103/api/v1/assets/'.$crest);curl_setopt_array($c,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_HTTPHEADER=>['Authorization: Bearer '.$key]]);$data=curl_exec($c);$code=curl_getinfo($c,CURLINFO_HTTP_CODE);unset($c);check($code===200&&str_starts_with($data,"\x89PNG"),'API key can fetch snapshot image');
 check((int)$db->query('SELECT COUNT(*) FROM school_assets')->fetchColumn()===2,'invalid uploads do not store extra assets');
 require $root.'/integrations/koku-multi/AgihTugasClient.php';$client=new \AgihTugas\Integration\AgihTugasClient('http://127.0.0.1:8103',$key);check(str_starts_with($client->assetBytes($crest),"\x89PNG"),'PHP integration client downloads image bytes');
 $row=$db->query('SELECT id,payload FROM workflow_revisions WHERE status="finalized" ORDER BY id DESC LIMIT 1')->fetch();$old=json_decode($row['payload'],true);unset($old['school']['branding']);$db->prepare('UPDATE workflow_revisions SET payload=? WHERE id=?')->execute([json_encode($old),$row['id']]);[$code,$html]=web('teacher','my_tasks');check(!str_contains($html,'class="letter-emblem"'),'legacy letters do not inherit newly configured images');
 echo"\n$checks branding checks passed.\n";
}finally{proc_terminate($server);proc_close($server);$db=null;foreach(glob($dir.'/*')as$f)unlink($f);rmdir($dir);}
