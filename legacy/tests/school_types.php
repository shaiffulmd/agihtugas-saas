<?php
declare(strict_types=1);
$dir=sys_get_temp_dir().'/agihtugas-types-'.bin2hex(random_bytes(4));mkdir($dir);putenv('AGIHTUGAS_DB='.$dir.'/test.sqlite');
require dirname(__DIR__).'/app/database.php';require dirname(__DIR__).'/app/auth.php';require dirname(__DIR__).'/app/workflow.php';require dirname(__DIR__).'/app/workflow_views.php';
function e(mixed $v):string{return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');}
$checks=0;function check(bool $ok,string $label):void{global$checks;if(!$ok)throw new RuntimeException('FAIL: '.$label);$checks++;echo"PASS: $label\n";}
function publish(array $admin,array $head):void{global$db;$db->beginTransaction();if(workflow_state()['status']!=='draft')workflow_transition('return',workflow_state()['version'],$admin,'Ujian perubahan profil');workflow_transition('submit',workflow_state()['version'],$admin);workflow_transition('approve',workflow_state()['version'],$head);workflow_transition('finalize',workflow_state()['version'],$head);$db->commit();}
function letter():string{ob_start();finalized_letter_html(workflow_letter(1));return ob_get_clean();}
try{
 foreach(['admin','head']as$role)q('INSERT INTO accounts(name,email,role,password_hash) VALUES (?,?,?,?)',[$role,$role.'@example.test',$role,password_hash('Test-only-password-2026',PASSWORD_DEFAULT)]);
 $admin=q('SELECT * FROM accounts WHERE role="admin"')->fetch();$head=q('SELECT * FROM accounts WHERE role="head"')->fetch();
 check(school_type()==='SK'&&school_head_title()==='Guru Besar','existing installations default to SK');
 check(role_label('head')==='Guru Besar','SK head role label');publish($admin,$head);check(str_contains(letter(),'PEJABAT GURU BESAR'),'SK letter heading');
 $r=workflow_published();$legacy=$r['data'];unset($legacy['school']['school_type'],$legacy['school']['principal_title']);q('UPDATE workflow_revisions SET payload=? WHERE id=?',[json_encode($legacy),$r['id']]);
 q('UPDATE settings SET value="SMK" WHERE key="school_type"');q('UPDATE settings SET value="SMK Contoh" WHERE key="school"');q('UPDATE settings SET value="Pengetua Contoh" WHERE key="principal"');
 check(school_profile()['school_type']==='SMK'&&school_profile()['principal_title']==='Pengetua','API school profile includes type and title');
 check(role_label('head')==='Pengetua','SMK uses same head permission with Pengetua label');
 check(str_contains(letter(),'PEJABAT GURU BESAR'),'legacy SK snapshot stays SK after current school changes');
 publish($admin,$head);check(str_contains(letter(),'PEJABAT PENGETUA')&&str_contains(letter(),'<br>Pengetua<br>'),'SMK letter heading and signature');
 check(workflow_letter(1)['school']['school_type']==='SMK','published API letter carries frozen type');
 q('UPDATE settings SET value="SK" WHERE key="school_type"');check(str_contains(letter(),'PEJABAT PENGETUA'),'published SMK letter unaffected by later type change');
 check(count(q('SELECT id FROM teachers')->fetchAll())===12,'school-type selection preserves teachers');
 echo"\n$checks SK/SMK checks passed.\n";
}finally{$db=null;unlink($dir.'/test.sqlite');rmdir($dir);}
