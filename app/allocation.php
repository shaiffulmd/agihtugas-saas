<?php
declare(strict_types=1);
final class AllocationError extends RuntimeException {
 public function __construct(string $message,public int $status=422) { parent::__construct($message); }
}
function allocation_defaults(): array { return ['respect_capacity'=>true,'max_tasks'=>13,'max_heavy'=>2,'heavy_threshold'=>8,'category_limits'=>array_fill_keys(CATEGORIES,4),'excluded_teachers'=>[]]; }
function allocation_rules(): array {
 $stored=q('SELECT payload FROM allocation_config WHERE id=1')->fetchColumn();
 return $stored?json_decode($stored,true,32,JSON_THROW_ON_ERROR):allocation_defaults();
}
function allocation_int(mixed $v,string $label,int $min,int $max): int {
 if(!is_int($v)||$v<$min||$v>$max) throw new AllocationError("$label mesti integer antara $min dan $max."); return $v;
}
function allocation_ids(mixed $values,string $table,string $label): array {
 if(!is_array($values)||!array_is_list($values)) throw new AllocationError("$label mesti senarai ID.");
 $ids=[]; foreach($values as $v) { $id=allocation_int($v,$label,1,PHP_INT_MAX); if(!q("SELECT 1 FROM $table WHERE id=?",[$id])->fetchColumn()) throw new AllocationError("$label merujuk rekod yang tidak ditemui."); $ids[]=$id; }
 return array_values(array_unique($ids));
}
function allocation_save_rules(array $input): array {
 $defaults=allocation_defaults(); if(array_diff(array_keys($input),array_keys($defaults))) throw new AllocationError('Medan syarat tidak dikenali.');
 $rules=array_merge(allocation_rules(),$input);
 if(!is_bool($rules['respect_capacity'])) throw new AllocationError('respect_capacity mesti boolean.');
 foreach(['max_tasks'=>[1,50],'max_heavy'=>[0,50],'heavy_threshold'=>[1,10]] as $k=>[$min,$max]) allocation_int($rules[$k],$k,$min,$max);
 if(!is_array($rules['category_limits'])||array_diff(array_keys($rules['category_limits']),CATEGORIES)||array_diff(CATEGORIES,array_keys($rules['category_limits']))) throw new AllocationError('Had mesti lengkap untuk empat bidang.');
 foreach($rules['category_limits'] as $limit) allocation_int($limit,'Had bidang',0,50);
 $rules['excluded_teachers']=allocation_ids($rules['excluded_teachers'],'teachers','Guru dikecualikan');
 q('INSERT INTO allocation_config VALUES (1,?) ON CONFLICT(id) DO UPDATE SET payload=excluded.payload',[json_encode($rules,JSON_THROW_ON_ERROR)]);
 return $rules;
}
function allocation_position_rules(): array {
 $rules=[]; foreach(q('SELECT * FROM position_rules ORDER BY position_id')->fetchAll() as $row) $rules[$row['position_id']]=json_decode($row['payload'],true,32,JSON_THROW_ON_ERROR); return $rules;
}
function allocation_save_position_rule(int $id,array $input): array {
 if(!q('SELECT id FROM positions WHERE id=?',[$id])->fetchColumn()) throw new AllocationError('Jawatan tidak ditemui.',404);
 if(array_diff(array_keys($input),['allowed_teachers','fixed_teacher_id','required_specialty'])) throw new AllocationError('Medan syarat jawatan tidak dikenali.');
 $rule=array_merge(['allowed_teachers'=>[],'fixed_teacher_id'=>null,'required_specialty'=>''],$input);
 $rule['allowed_teachers']=allocation_ids($rule['allowed_teachers'],'teachers','Guru layak');
 if($rule['fixed_teacher_id']!==null) allocation_ids([$rule['fixed_teacher_id']],'teachers','Penyandang tetap');
 if(!is_string($rule['required_specialty'])||mb_strlen($rule['required_specialty'])>160) throw new AllocationError('Kepakaran diperlukan mesti teks maksimum 160 aksara.');
 $rule['required_specialty']=trim($rule['required_specialty']);
 if($rule['fixed_teacher_id']!==null&&$rule['allowed_teachers']&&!in_array($rule['fixed_teacher_id'],$rule['allowed_teachers'],true)) throw new AllocationError('Penyandang tetap mesti berada dalam senarai guru layak.');
 q('INSERT INTO position_rules VALUES (?,?) ON CONFLICT(position_id) DO UPDATE SET payload=excluded.payload',[$id,json_encode($rule,JSON_THROW_ON_ERROR)]); return $rule;
}
function allocation_state(): array {
 return ['workflow'=>q('SELECT status,revision_id,version FROM workflow_state WHERE id=1')->fetch(),'settings'=>q('SELECT key,value FROM settings ORDER BY key')->fetchAll(),'teachers'=>q('SELECT * FROM teachers ORDER BY id')->fetchAll(),'positions'=>q('SELECT * FROM positions ORDER BY id')->fetchAll(),'assignments'=>q('SELECT position_id,teacher_id FROM assignments ORDER BY position_id')->fetchAll(),'rules'=>allocation_rules(),'position_rules'=>allocation_position_rules()];
}
function allocation_snapshot(array $state): string { return hash('sha256',json_encode($state,JSON_THROW_ON_ERROR)); }
function allocation_eligible(array $t,array $p,array $rule,array $rules): ?string {
 if(in_array($t['id'],$rules['excluded_teachers'],true)) return 'Guru dikecualikan';
 if(($rule['fixed_teacher_id']??null)!==null&&$t['id']!==$rule['fixed_teacher_id']) return 'Jawatan mempunyai penyandang tetap';
 if(!empty($rule['allowed_teachers'])&&!in_array($t['id'],$rule['allowed_teachers'],true)) return 'Di luar senarai guru layak';
 if(($rule['required_specialty']??'')!==''&&mb_stripos($t['specialty'],$rule['required_specialty'])===false) return 'Kepakaran tidak sepadan';
 return null;
}
function allocation_limits(array $t,array $p,array $rules): ?string {
 if($rules['respect_capacity']&&$t['load']+$p['weight']>$t['capacity']) return 'Had kapasiti mata';
 if($t['task_count']+1>$rules['max_tasks']) return 'Had bilangan tugas';
 if($p['weight']>=$rules['heavy_threshold']&&$t['heavy']+1>$rules['max_heavy']) return 'Had tugas berat';
 if(($t['categories'][$p['category']]??0)+1>$rules['category_limits'][$p['category']]) return 'Had bidang '.$p['category'];
 return null;
}
function allocation_add(array &$t,array $p,int $threshold): void {
 $t['load']+=$p['weight']; $t['task_count']++; if($p['weight']>= $threshold) $t['heavy']++; $t['categories'][$p['category']]++;
}
function allocation_preview(string $mode): array {
 if(!in_array($mode,['vacant','rebalance'],true)) throw new AllocationError('Mod agihan tidak sah.');
 $state=allocation_state(); $rules=$state['rules'];
 $teachers=[]; foreach($state['teachers'] as $t) $teachers[$t['id']]=[...$t,'load'=>0,'task_count'=>0,'heavy'=>0,'categories'=>array_fill_keys(CATEGORIES,0)];
 $positions=array_column($state['positions'],null,'id'); $existing=array_column($state['assignments'],'teacher_id','position_id');
 $mapping=[]; $reasons=[]; $warnings=[]; $pending=[];
 if($mode==='vacant') foreach($existing as $pid=>$tid) {
  $p=$positions[$pid]; $rule=$state['position_rules'][$pid]??[];
  $issue=allocation_eligible($teachers[$tid],$p,$rule,$rules)??allocation_limits($teachers[$tid],$p,$rules);
  if($issue) $warnings[]=$teachers[$tid]['name'].' · '.$p['name'].': '.$issue.' (lantikan sedia ada dikekalkan).';
  allocation_add($teachers[$tid],$p,$rules['heavy_threshold']); $mapping[$pid]=$tid; $reasons[$pid]='Lantikan sedia ada dikekalkan';
 }
 foreach($positions as $pid=>$p) {
  if(isset($mapping[$pid])) continue;
  $rule=$state['position_rules'][$pid]??[]; $eligible=[];
  foreach($teachers as $tid=>$t) if(allocation_eligible($t,$p,$rule,$rules)===null) $eligible[]=$tid;
  $pending[]=['position'=>$p,'eligible'=>$eligible,'fixed'=>($rule['fixed_teacher_id']??null)!==null];
 }
 // Restrictive positions first; heavier positions first within the same eligibility width.
 usort($pending,fn($a,$b)=>[(int)!$a['fixed'],count($a['eligible']),-$a['position']['weight'],$a['position']['id']]<=>[(int)!$b['fixed'],count($b['eligible']),-$b['position']['weight'],$b['position']['id']]);
 $unfilled=[];
 foreach($pending as $item) {
  $p=$item['position']; $candidates=[]; $blocked=[];
  foreach($item['eligible'] as $tid) {
   $t=$teachers[$tid]; $issue=allocation_limits($t,$p,$rules);
   if($issue) { $blocked[$issue]=true; continue; }
   $candidates[]=$tid;
  }
  usort($candidates,fn($a,$b)=>[($teachers[$a]['load']+$p['weight'])/$teachers[$a]['capacity'],$teachers[$a]['task_count'],$teachers[$a]['categories'][$p['category']],$a]<=>[($teachers[$b]['load']+$p['weight'])/$teachers[$b]['capacity'],$teachers[$b]['task_count'],$teachers[$b]['categories'][$p['category']],$b]);
  if(!$candidates) { $reason=$item['eligible']?implode(', ',array_keys($blocked)):'Tiada guru yang memenuhi kelayakan / pengecualian'; $reasons[$p['id']]=$reason; $unfilled[]=['position_id'=>$p['id'],'position_name'=>$p['name'],'reason'=>$reason]; continue; }
  $tid=$candidates[0]; $mapping[$p['id']]=$tid; allocation_add($teachers[$tid],$p,$rules['heavy_threshold']);
  $reasons[$p['id']]=$item['fixed']?'Penyandang tetap yang memenuhi syarat':'Nisbah beban selepas lantikan terendah antara calon yang layak';
 }
 $rows=[]; $changes=0; $released=0;
 foreach($positions as $pid=>$p) {
  $before=$existing[$pid]??null; $after=$mapping[$pid]??null;
  if($before!==$after) $changes++; if($before!==null&&$after===null) $released++;
  $rows[]=['position_id'=>$pid,'position_name'=>$p['name'],'category'=>$p['category'],'weight'=>$p['weight'],'before_teacher_id'=>$before,'before_teacher_name'=>$before?$teachers[$before]['name']:null,'teacher_id'=>$after,'teacher_name'=>$after?$teachers[$after]['name']:null,'reason'=>$reasons[$pid],'changed'=>$before!==$after];
 }
 $plan=['mode'=>$mode,'rows'=>$rows,'unfilled'=>$unfilled,'warnings'=>$warnings,'teachers'=>array_values($teachers),'summary'=>['assigned'=>count($mapping),'unfilled'=>count($unfilled),'changed'=>$changes,'released'=>$released],'rules'=>$rules];
 $id=bin2hex(random_bytes(24)); $now=time();
 q('DELETE FROM allocation_plans WHERE created_at < ?',[$now-86400]);
 q('INSERT INTO allocation_plans(id,snapshot,payload,created_at) VALUES (?,?,?,?)',[$id,allocation_snapshot($state),json_encode($plan,JSON_THROW_ON_ERROR),$now]);
 return ['plan_id'=>$id,'expires_at'=>date(DATE_ATOM,$now+1800),...$plan];
}
function allocation_apply(string $id): array {
 $row=q('SELECT * FROM allocation_plans WHERE id=?',[$id])->fetch();
 if(!$row) throw new AllocationError('Pratonton tidak ditemui. Jana semula pratonton.',404);
 if($row['applied_at']!==null) throw new AllocationError('Pratonton ini sudah digunakan. Jana pratonton baharu.',409);
 if($row['created_at']<time()-1800) throw new AllocationError('Pratonton telah tamat tempoh. Jana semula.',409);
 // Obtain SQLite write lock before checking the current state; caller owns the transaction.
 q('UPDATE allocation_plans SET applied_at=? WHERE id=? AND applied_at IS NULL',[time(),$id]);
 if(!hash_equals($row['snapshot'],allocation_snapshot(allocation_state()))) throw new AllocationError('Data atau syarat telah berubah selepas pratonton. Jana semula sebelum menyimpan.',409);
 $plan=json_decode($row['payload'],true,64,JSON_THROW_ON_ERROR);
 foreach($plan['rows'] as $r) {
  if(!$r['changed']) continue;
  if($r['teacher_id']===null) q('DELETE FROM assignments WHERE position_id=?',[$r['position_id']]);
  else q('INSERT INTO assignments(teacher_id,position_id) VALUES (?,?) ON CONFLICT(position_id) DO UPDATE SET teacher_id=excluded.teacher_id',[$r['teacher_id'],$r['position_id']]);
 }
 return $plan['summary'];
}

function allocation_teacher_referenced(int $id): bool {
 if(in_array($id,allocation_rules()['excluded_teachers'],true)) return true;
 foreach(allocation_position_rules() as $r) if(($r['fixed_teacher_id']??null)===$id||in_array($id,$r['allowed_teachers']??[],true)) return true;
 return false;
}
