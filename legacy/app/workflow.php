<?php
declare(strict_types=1);
const WORKFLOW_LABELS=['draft'=>'Draf','submitted'=>'Semakan','approved'=>'Lulus','finalized'=>'Muktamad'];
final class WorkflowError extends RuntimeException { public function __construct(string $message,public int $status=409) { parent::__construct($message); } }
function workflow_state(): array { return q('SELECT * FROM workflow_state WHERE id=1')->fetch(); }
function workflow_require_draft(): void {
 if(workflow_state()['status']!=='draft') throw new WorkflowError('Agihan dikunci untuk semakan / kelulusan. Kembalikan kepada draf sebelum membuat pindaan.');
}
function workflow_source(): array {
 return ['school'=>school_profile(),'teachers'=>q('SELECT * FROM teachers ORDER BY id')->fetchAll(),'positions'=>q('SELECT p.*,a.teacher_id FROM positions p LEFT JOIN assignments a ON a.position_id=p.id ORDER BY p.id')->fetchAll(),'rules'=>q('SELECT * FROM allocation_config ORDER BY id')->fetchAll(),'position_rules'=>q('SELECT * FROM position_rules ORDER BY position_id')->fetchAll()];
}
function workflow_hash(array $source): string { return hash('sha256',json_encode($source,JSON_THROW_ON_ERROR)); }
function workflow_transition(string $action,int $expectedVersion,array $actor,string $reason=''): array {
 global $db;
 $state=workflow_state();
 if($state['version']!==$expectedVersion) throw new WorkflowError('Status telah berubah. Muat semula halaman sebelum meneruskan.');
 $status=$state['status']; $rid=$state['revision_id']; $target='';
 if($action==='submit') {
  if(!in_array($actor['role'],['admin','pk'],true)) throw new WorkflowError('Hanya Pentadbir atau PK boleh menghantar draf.',403);
  if($status!=='draft') throw new WorkflowError('Hanya draf boleh dihantar untuk semakan.');
  if(!(int)q('SELECT COUNT(*) FROM assignments')->fetchColumn()) throw new WorkflowError('Sediakan sekurang-kurangnya satu lantikan sebelum menghantar.');
  $source=workflow_source();
  q('INSERT INTO workflow_revisions(status,payload,source_hash,submitted_by) VALUES ("submitted",?,?,?)',[json_encode($source,JSON_THROW_ON_ERROR),workflow_hash($source),$actor['id']]); $rid=(int)$db->lastInsertId(); $target='submitted';
 } elseif(in_array($action,['approve','finalize'],true)) {
  if($actor['role']!=='head') throw new WorkflowError('Hanya '.school_head_title().' boleh meluluskan atau memuktamadkan.',403);
  if($status!==($action==='approve'?'submitted':'approved')) throw new WorkflowError('Urutan status tidak sah.');
  $r=q('SELECT * FROM workflow_revisions WHERE id=?',[$rid])->fetch();
  if($actor['id']===$r['submitted_by']) throw new WorkflowError('Penghantar tidak boleh meluluskan agihan sendiri.',403);
  if(!hash_equals($r['source_hash'],workflow_hash(workflow_source()))) throw new WorkflowError('Data telah berubah sejak dihantar. Kembalikan kepada draf dan hantar semula.');
  $target=$action==='approve'?'approved':'finalized'; $column=$action==='approve'?'approved':'finalized';
  q("UPDATE workflow_revisions SET status=?,{$column}_by=?,{$column}_at=CURRENT_TIMESTAMP WHERE id=?",[$target,$actor['id'],$rid]);
 } elseif($action==='return') {
  if(!in_array($actor['role'],['admin','head'],true)) throw new WorkflowError('Hanya Pentadbir atau '.school_head_title().' boleh membuka semula draf.',403);
  if($status==='draft') throw new WorkflowError('Agihan sudah dalam draf.');
  if(trim($reason)===''||mb_strlen($reason)>500) throw new WorkflowError('Nyatakan sebab pindaan (maksimum 500 aksara).',422);
  if($status!=='finalized') q('UPDATE workflow_revisions SET status="returned",returned_by=?,returned_at=CURRENT_TIMESTAMP,return_reason=? WHERE id=?',[$actor['id'],trim($reason),$rid]);
  $target='draft';
 } else throw new WorkflowError('Tindakan tidak sah.',422);
 $updated=q('UPDATE workflow_state SET status=?,revision_id=?,version=version+1 WHERE id=1 AND version=?',[$target,$rid,$expectedVersion]);
 if($updated->rowCount()!==1) throw new WorkflowError('Status telah berubah. Cuba semula.');
 q('INSERT INTO history(description) VALUES (?)',["Agihan #$rid: ".WORKFLOW_LABELS[$status].' → '.WORKFLOW_LABELS[$target]." oleh {$actor['name']} ({$actor['role']})".($reason!==''?' · '.trim($reason):'')]);
 return workflow_state();
}
function workflow_published(): ?array {
 $r=q('SELECT * FROM workflow_revisions WHERE status="finalized" ORDER BY id DESC LIMIT 1')->fetch();
 if(!$r)return null;
 return [...$r,'data'=>json_decode($r['payload'],true,64,JSON_THROW_ON_ERROR)];
}
function workflow_letter(int $tid): array {
 $r=workflow_published(); if(!$r) throw new WorkflowError('Surat hanya tersedia selepas agihan dimuktamadkan.');
 $teacher=null; foreach($r['data']['teachers'] as $t) if($t['id']===$tid) $teacher=$t;
 if(!$teacher) throw new WorkflowError('Guru tiada dalam agihan muktamad.',404);
 $tasks=array_values(array_filter($r['data']['positions'],fn($p)=>$p['teacher_id']===$tid));
 $school=$r['data']['school']; $school['school_type']=school_type($school); $school['principal_title']=school_head_title($school);
 return ['revision_id'=>$r['id'],'school'=>$school,'teacher'=>$teacher,'assignments'=>$tasks,'finalized_at'=>$r['finalized_at']];
}

function workflow_local_time(string $utc): string { return (new DateTimeImmutable($utc,new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('Asia/Kuala_Lumpur'))->format('d/m/Y H:i'); }
