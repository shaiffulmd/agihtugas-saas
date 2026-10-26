<?php
declare(strict_types=1);
require __DIR__.'/test_helpers.php';
// End-to-end HTTP checks against an isolated database, never the user's workspace data.
$root=dirname(__DIR__); $temp=sys_get_temp_dir().'/agihtugas-test-'.bin2hex(random_bytes(5)); mkdir($temp);
$env=getenv(); $env['AGIHTUGAS_DB']=$temp.'/test.sqlite';
fixture_run('seed',$root,$env);
$server=proc_open([PHP_BINARY,'-S','127.0.0.1:8098','-t',$root.'/public'],[0=>['pipe','r'],1=>['file',$temp.'/server.log','a'],2=>['file',$temp.'/server.log','a']],$pipes,$root,$env);
if(!is_resource($server)) throw new RuntimeException('Cannot start isolated test server.');
$cookie=$temp.'/cookies'; $token=''; $checks=0;
function check(bool $condition,string $label): void { global $checks; if(!$condition) throw new RuntimeException('FAIL: '.$label); $checks++; echo "PASS: $label\n"; }
function request(string $page='dashboard',?array $data=null): array {
 global $cookie,$token;
 $c=curl_init('http://127.0.0.1:8098/?page='.$page);
 curl_setopt_array($c,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_COOKIEJAR=>$cookie,CURLOPT_COOKIEFILE=>$cookie,CURLOPT_TIMEOUT=>5]);
 if($data!==null) curl_setopt_array($c,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>http_build_query(['csrf'=>$token,...$data])]);
 $body=curl_exec($c); $status=curl_getinfo($c,CURLINFO_HTTP_CODE); unset($c); return [$status,(string)$body];
}
try {
 for($i=0;$i<30;$i++) { usleep(100000); [$status,$body]=request('login'); if($status===200) break; }
 preg_match('/name="csrf" value="([^"]+)"/',$body,$loginToken); $token=$loginToken[1];
 [$status]=request('login',['action'=>'login','email'=>'admin@example.test','password'=>'Test-only-password-2026']);
 check($status===303,'administrator login');
 [$status,$body]=request(); check($status===200 && str_contains($body,'Tugas tersusun.'),'dashboard responds');
 foreach(['teachers','positions','assignments','letters','settings','auto','rules','bulk'] as $page) { [$code,$html]=request($page); check($code===200&&!str_contains($html,'Fatal error')&&!str_contains($html,'Deprecated')&&!str_contains($html,'Warning'),'render '.$page); }
 [$code,$html]=request('teachers&new=1'); preg_match('/name="csrf" value="([^"]+)"/',$html,$m); $token=$m[1];
 $db=new PDO('sqlite:'.$temp.'/test.sqlite'); $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE,PDO::FETCH_ASSOC);
 check((int)$db->query('SELECT COUNT(*) FROM teachers')->fetchColumn()===12,'seed 12 sample teachers');
 [$code]=request('teachers',['action'=>'teacher','name'=>'Guru Ujian <script>','email'=>'ujian@example.test','specialty'=>'Sains','capacity'=>5]); check($code===302,'create teacher');
 $tid=(int)$db->query('SELECT MAX(id) FROM teachers')->fetchColumn();
 [$code,$html]=request('teachers'); check(str_contains($html,'Guru Ujian &lt;script&gt;')&&!str_contains($html,'Guru Ujian <script>'),'escape user content');
 [$code]=request('positions',['action'=>'position','name'=>'Jawatan Ujian','category'=>'HEM','weight'=>8]); check($code===302,'create position'); $pid=(int)$db->query('SELECT MAX(id) FROM positions')->fetchColumn();
 [$code]=request('assignments',['action'=>'assign','teacher_id'=>$tid,'position_id'=>$pid]); check($code===302,'assign teacher');
 [$code,$html]=request('teachers&over=1'); check(str_contains($html,'Guru Ujian &lt;script&gt;')&&str_contains($html,'Melebihi had'),'capacity warning and filtered teacher');
 fixture_run('publish_draft',$root,$env);
 [$code,$html]=request('letters&teacher='.$tid); check(str_contains($html,'Jawatan Ujian')&&str_contains($html,'PELANTIKAN TUGAS'),'individual letter includes assignment');
 [$code,$html]=request('letters&all=1'); check(substr_count($html,'<article class="letter">')===13,'batch letters');
 [$code,$html]=request('teachers',['action'=>'delete_teacher','id'=>$tid]); check($code===200&&str_contains($html,'Lepaskan agihan berkaitan dahulu'),'prevent deleting assigned teacher');
 [$code]=request('positions',['action'=>'position','id'=>$pid,'name'=>'Jawatan Ujian','category'=>'HEM','weight'=>3]); check($code===302,'edit weight');
 [$code,$html]=request('teachers&over=1'); check(!str_contains($html,'Guru Ujian'),'recalculate load after weight change');
 [$code]=request('assignments',['action'=>'assign','teacher_id'=>2,'position_id'=>$pid]); check($code===302 && (int)$db->query("SELECT COUNT(*) FROM assignments WHERE position_id=$pid")->fetchColumn()===1,'transfer without duplicate assignment');
 [$code,$html]=request('letters&teacher='.$tid); check(str_contains($html,'Jawatan Ujian'),'published letter survives draft transfer');
 [$code,$html]=request('positions',['action'=>'position','name'=>'Invalid','category'=>'HEM','weight'=>11]); check($code===200&&str_contains($html,'di luar julat'),'reject out of range weight');
 [$code,$html]=request('teachers',['csrf'=>'invalid','action'=>'teacher','name'=>'Rejected','capacity'=>20]); check($code===403,'reject invalid CSRF');
 [$code]=request('assignments',['action'=>'assign','teacher_id'=>99999,'position_id'=>$pid]); check($code===200&& (int)$db->query("SELECT teacher_id FROM assignments WHERE position_id=$pid")->fetchColumn()===2,'reject invalid teacher without changing assignment');
 $aid=$db->query("SELECT id FROM assignments WHERE position_id=$pid")->fetchColumn();
 [$code]=request('assignments',['action'=>'unassign','id'=>$aid]); check($code===302,'release assignment');
 [$code]=request('teachers',['action'=>'delete_teacher','id'=>$tid]); check($code===302,'delete unassigned teacher');
 [$code]=request('positions',['action'=>'delete_position','id'=>$pid]); check($code===302,'delete unassigned position');
 [$code]=request('settings',['action'=>'settings','school'=>'SK Ujian','principal'=>'Guru Besar Ujian','reference'=>'SKU/1','year'=>2028]); check($code===302,'save school settings');
 fixture_run('publish_draft',$root,$env);
 [$code,$html]=request('letters&teacher=1'); check(str_contains($html,'SK Ujian')&&str_contains($html,'Guru Besar Ujian')&&str_contains($html,'2028'),'letter uses saved settings');
 [$code,$html]=request('bulk',['action'=>'bulk_preview','bulk_text'=>"name,category,weight,count\nUI Bulk,HEM,4,2"]);
 check($code===302,'UI bulk preview created');
 [$code,$html]=request('bulk'); preg_match('/name="bulk_token" value="([^"]+)"/',$html,$bm);
 check(isset($bm[1])&&str_contains($html,'UI Bulk (2/2)'),'UI bulk preview displays expanded seats');
 [$code]=request('bulk',['action'=>'bulk_apply','bulk_token'=>$bm[1]]); check($code===302,'UI bulk apply');
 [$code]=request('bulk',['action'=>'bulk_apply','bulk_token'=>$bm[1]]); check($code===409,'UI bulk replay rejected');
 [$code]=request('auto',['action'=>'auto_preview','mode'=>'vacant']); check($code===302,'UI auto preview created');
 [$code,$html]=request('auto'); preg_match('/name="plan_id" value="([^"]+)"/',$html,$pm);
 check(isset($pm[1])&&str_contains($html,'Simpan agihan ini'),'UI auto preview has save action');
 $db->exec('UPDATE positions SET weight=weight%10+1 WHERE id=21');
 [$code,$html]=request('auto',['action'=>'auto_apply','plan_id'=>$pm[1]]); check($code===409&&str_contains($html,'telah berubah selepas pratonton'),'UI stale auto plan rejected');
 [$code]=request('rules',['action'=>'allocation_rules','respect_capacity'=>'on','max_tasks'=>10,'max_heavy'=>1,'heavy_threshold'=>8,'category_0'=>3,'category_1'=>3,'category_2'=>3,'category_3'=>4,'excluded_teachers'=>[2]]);
 check($code===302,'UI global rules save');
 $saved=json_decode($db->query('SELECT payload FROM allocation_config WHERE id=1')->fetchColumn(),true);
 check($saved['max_tasks']===10&&$saved['excluded_teachers']===[2],'UI rules persist exact limits');
 [$code]=request('rules&position=21',['action'=>'position_rule','position_id'=>21,'allowed_teachers'=>[1],'fixed_teacher_id'=>1,'required_specialty'=>'Bahasa']);
 check($code===302,'UI position rule save');
 $upload=$temp.'/positions.csv'; file_put_contents($upload,"name,category,weight,count\nUploaded Role,HEM,3,2");
 $ch=curl_init('http://127.0.0.1:8098/?page=bulk');
 curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_COOKIEJAR=>$cookie,CURLOPT_COOKIEFILE=>$cookie,CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>['csrf'=>$token,'action'=>'bulk_preview','csv'=>new CURLFile($upload,'text/csv','positions.csv')]]);
 curl_exec($ch); $code=curl_getinfo($ch,CURLINFO_HTTP_CODE); unset($ch); check($code===302,'CSV file upload preview');
 [$code,$html]=request('bulk'); check(str_contains($html,'Uploaded Role (2/2)'),'uploaded CSV expands seats in preview');
 check((int)$db->query("SELECT COUNT(*) FROM positions WHERE name LIKE 'Uploaded Role%'")->fetchColumn()===0,'upload preview does not create positions');
 [$code]=request('settings',['action'=>'settings','school_type'=>'INVALID','school'=>'Invalid','principal'=>'Invalid','reference'=>'INVALID','year'=>2028]);check($code===422,'reject unknown school type');
 [$code]=request('settings',['action'=>'settings','school_type'=>'SMK','school'=>'SMK Ujian','principal'=>'Pengetua Ujian','reference'=>'SMKU/1','year'=>2028]);check($code===302,'save SMK school profile');
 [$code,$html]=request('settings');check(str_contains($html,'Nama Pengetua')&&str_contains($html,'value="SMK" selected'),'SMK settings use principal title');
 [$code,$html]=request('accounts&new=1');check(str_contains($html,'value="head"')&&str_contains($html,'Pengetua'),'SMK head account label');
 fixture_run('publish_draft',$root,$env);[$code,$html]=request('letters&teacher=1');check(str_contains($html,'PEJABAT PENGETUA')&&str_contains($html,'Pengetua Ujian'),'SMK finalized letter uses Pengetua');
 [$code]=request('settings',['action'=>'settings','school_type'=>'SK','school'=>'SK Baharu','principal'=>'Guru Besar Baharu','reference'=>'SKB/1','year'=>2029]);
 [$code,$html]=request('letters&teacher=1');check(str_contains($html,'PEJABAT PENGETUA')&&str_contains($html,'SMK Ujian'),'school-type changes do not alter published letters');
 echo "\n$checks checks passed.\n";
} finally { proc_terminate($server); proc_close($server); $db=null; foreach(glob($temp.'/*') as $file) unlink($file); rmdir($temp); }
