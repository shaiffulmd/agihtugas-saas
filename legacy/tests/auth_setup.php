<?php
declare(strict_types=1);
$dir=sys_get_temp_dir().'/agihtugas-setup-'.bin2hex(random_bytes(5));mkdir($dir);putenv('AGIHTUGAS_DB='.$dir.'/test.sqlite');
require dirname(__DIR__).'/app/database.php';require dirname(__DIR__).'/app/auth.php';require dirname(__DIR__).'/app/workflow.php';
$_SESSION=[];$checks=0;
function check(bool $ok,string $label):void{global$checks;if(!$ok)throw new RuntimeException('FAIL: '.$label);$checks++;echo"PASS: $label\n";}
function reject(callable $fn,int $status,string $label):void{try{$fn();}catch(AccessError|WorkflowError$e){check($e->status===$status,$label);return;}throw new RuntimeException('Expected rejection: '.$label);}
try {
 $code=bin2hex(random_bytes(16));q('INSERT INTO setup_codes VALUES(1,?,?)',[hash('sha256',$code),time()-1]);
 $input=['name'=>'First Admin','email'=>'first@example.test','password'=>'Test-only-password-2026','setup_code'=>$code,'role'=>'teacher'];
 reject(fn()=>auth_setup($input),403,'expired setup code rejected');
 q('UPDATE setup_codes SET expires_at=?',[time()+1800]);
 reject(fn()=>auth_setup([...$input,'setup_code'=>'wrong']),403,'invalid setup code rejected');
 reject(fn()=>auth_setup([...$input,'password'=>'short']),422,'short setup password rejected');
 check((int)q('SELECT COUNT(*) FROM accounts')->fetchColumn()===0,'failed setup creates no account');
 auth_setup($input);$a=q('SELECT * FROM accounts')->fetch();
 check($a['role']==='admin'&&$a['active']===1,'first account forced to active admin');
 check($a['password_hash']!==$input['password']&&password_verify($input['password'],$a['password_hash']),'only password hash stored');
 check((int)q('SELECT COUNT(*) FROM setup_codes')->fetchColumn()===0,'setup code consumed');
 reject(fn()=>auth_setup($input),409,'first admin setup cannot be replayed');
 reject(fn()=>account_save(['id'=>$a['id'],'name'=>$a['name'],'email'=>$a['email'],'role'=>'pk','active'=>1],$a['id']),422,'cannot demote self');
 reject(fn()=>account_save(['name'=>'Teacher','email'=>'teacher@example.test','role'=>'teacher','active'=>1,'password'=>'Test-only-password-2026'], $a['id']),422,'teacher account requires a valid profile');
 reject(fn()=>account_save(['name'=>'Duplicate','email'=>'first@example.test','role'=>'pk','active'=>1,'password'=>'Test-only-password-2026'],$a['id']),422,'duplicate account email rejected');
 check((int)q('SELECT COUNT(*) FROM accounts')->fetchColumn()===1,'invalid account changes leave first admin intact');
 $db->beginTransaction();workflow_transition('submit',workflow_state()['version'],$a);reject(fn()=>workflow_transition('approve',workflow_state()['version'],[...$a,'role'=>'head']),403,'submitter cannot approve after role change');$db->rollBack();
 echo "\n$checks setup and account checks passed.\n";
}finally{$db=null;unlink($dir.'/test.sqlite');rmdir($dir);}
