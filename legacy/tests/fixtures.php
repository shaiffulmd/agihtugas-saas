<?php
// Test-only accounts. Refuse to touch the normal application database.
declare(strict_types=1);
$path=getenv('AGIHTUGAS_DB');
if(PHP_SAPI!=='cli'||!$path||!str_starts_with($path,sys_get_temp_dir().'/agihtugas-')) throw new RuntimeException('An isolated test database is required.');
require dirname(__DIR__).'/app/database.php'; require dirname(__DIR__).'/app/workflow.php';
$cmd=$argv[1]??'seed';
if($cmd==='seed') {
 foreach([['Test Admin','admin@example.test','admin',null],['Test Head','head@example.test','head',null],['Test PK','pk@example.test','pk',null],['Teacher One','teacher@example.test','teacher',1],['Teacher Two','teacher2@example.test','teacher',2]] as [$name,$email,$role,$tid]) q('INSERT OR IGNORE INTO accounts(name,email,role,teacher_id,password_hash) VALUES (?,?,?,?,?)',[$name,$email,$role,$tid,password_hash('Test-only-password-2026',PASSWORD_DEFAULT)]);
} else {
 $admin=q('SELECT * FROM accounts WHERE role="admin" LIMIT 1')->fetch(); $head=q('SELECT * FROM accounts WHERE role="head" LIMIT 1')->fetch();
 $db->beginTransaction();
 if(workflow_state()['status']!=='draft') workflow_transition('return',workflow_state()['version'],$admin,'Test fixture revision');
 if($cmd==='publish'||$cmd==='publish_draft') {
  workflow_transition('submit',workflow_state()['version'],$admin);
  workflow_transition('approve',workflow_state()['version'],$head);
  workflow_transition('finalize',workflow_state()['version'],$head);
  if($cmd==='publish_draft') workflow_transition('return',workflow_state()['version'],$admin,'Test fixture revision');
 }
 $db->commit();
}
