<?php
declare(strict_types=1);
$temp=sys_get_temp_dir().'/agih-engine-'.bin2hex(random_bytes(6)).'.sqlite'; putenv('AGIHTUGAS_DB='.$temp);
require dirname(__DIR__).'/app/database.php'; require dirname(__DIR__).'/app/allocation.php'; require dirname(__DIR__).'/app/bulk_positions.php';
$checks=0;
function check(bool $condition,string $label): void { global $checks; if(!$condition) throw new RuntimeException('FAIL: '.$label); $checks++; echo "PASS: $label\n"; }
function rejects(callable $fn,string $label,int $status=422): void { try {$fn();} catch(AllocationError $e) {check($e->status===$status,$label);return;} throw new RuntimeException('Expected rejection: '.$label); }
function fixture(array $teachers,array $positions): void {
 global $db;
 foreach(['allocation_plans','position_rules','allocation_config','assignments','positions','teachers'] as $table) q("DELETE FROM $table");
 foreach($teachers as $i=>$t) q('INSERT INTO teachers(id,name,specialty,capacity) VALUES (?,?,?,?)',[$i+1,$t[0],$t[1],$t[2]]);
 foreach($positions as $i=>$p) q('INSERT INTO positions(id,name,category,weight) VALUES (?,?,?,?)',[$i+1,$p[0],$p[1],$p[2]]);
}
function apply_test(string $id): array { global $db; $db->beginTransaction(); try {$r=allocation_apply($id);$db->commit();return $r;} catch(Throwable $e) {$db->rollBack();throw $e;} }
try {
 fixture([['A','Math',10],['B','Science',10]],[['Heavy A','HEM',9],['Heavy B','HEM',9],['Light A','HEM',1],['Light B','HEM',1]]);
 $p=allocation_preview('rebalance'); check($p['summary']['assigned']===4&&array_column($p['teachers'],'load')===[10,10],'balances ratios within capacity');
 check((int)q('SELECT COUNT(*) FROM assignments')->fetchColumn()===0,'preview does not mutate assignments');
 check($p['rows']===allocation_preview('rebalance')['rows'],'deterministic preview');
 $r=apply_test($p['plan_id']); check($r['changed']===4&&(int)q('SELECT COUNT(*) FROM assignments')->fetchColumn()===4,'apply writes exact preview');
 rejects(fn()=>apply_test($p['plan_id']),'one-use plan',409);
 fixture([['A','Matematik',10],['B','Sains',10]],[['General','Kurikulum',5],['Math','Kurikulum',6]]);
 allocation_save_position_rule(2,['required_specialty'=>'matematik']); $p=allocation_preview('rebalance');
 check($p['summary']['assigned']===2&&$p['rows'][1]['teacher_id']===1&&$p['rows'][0]['teacher_id']===2,'scarce specialty handled before general role');
 allocation_save_rules(['excluded_teachers'=>[1]]); $p=allocation_preview('rebalance'); check($p['summary']['unfilled']===1&&$p['rows'][1]['teacher_id']===null,'exclusion and specialty are hard constraints');
 rejects(fn()=>allocation_save_rules(['excluded_teachers'=>[999]]),'invalid excluded ID');
 rejects(fn()=>allocation_save_rules(['respect_capacity'=>'yes']),'boolean validated');
 rejects(fn()=>allocation_save_rules(['category_limits'=>['HEM'=>2]]),'all categories required');
 rejects(fn()=>allocation_save_position_rule(1,['allowed_teachers'=>[1],'fixed_teacher_id'=>2]),'fixed teacher must be allowed');
 fixture([['A','',5]],[['Too heavy','HEM',8]]); allocation_save_position_rule(1,['fixed_teacher_id'=>1]);
 $p=allocation_preview('rebalance'); check($p['summary']['unfilled']===1&&str_contains($p['unfilled'][0]['reason'],'kapasiti'),'fixed teacher cannot bypass capacity');
 allocation_save_rules(['respect_capacity'=>false]); $p=allocation_preview('rebalance'); check($p['summary']['assigned']===1&&$p['teachers'][0]['load']===8,'capacity switch explicitly allows over capacity');
 allocation_save_rules(['excluded_teachers'=>[1]]); $p=allocation_preview('rebalance'); check($p['summary']['assigned']===0,'fixed teacher cannot bypass exclusion');
 fixture([['A','',100]],[['Heavy1','HEM',8],['Heavy2','HEM',9],['Light','HEM',2]]); allocation_save_rules(['max_heavy'=>1]);
 $p=allocation_preview('rebalance'); check($p['summary']['assigned']===2&&$p['teachers'][0]['heavy']===1,'max heavy enforced');
 allocation_save_rules(['max_tasks'=>1]); $p=allocation_preview('rebalance'); check($p['summary']['assigned']===1,'max task count enforced');
 allocation_save_rules(['category_limits'=>array_fill_keys(CATEGORIES,0)]); $p=allocation_preview('rebalance'); check($p['summary']['assigned']===0,'zero category cap prevents new assignments');
 fixture([['A','',5],['B','',20]],[['Existing','HEM',9],['New','HEM',4]]); q('INSERT INTO assignments(teacher_id,position_id) VALUES (1,1)');
 $p=allocation_preview('vacant'); check($p['rows'][0]['teacher_id']===1&&$p['rows'][1]['teacher_id']===2&&count($p['warnings'])===1,'vacant mode preserves old assignment and flags violation');
 allocation_save_position_rule(1,['fixed_teacher_id'=>2]); $p=allocation_preview('rebalance'); check($p['rows'][0]['teacher_id']===2,'rebalance respects fixed teacher');
 allocation_save_rules(['excluded_teachers'=>[1,2]]); $p=allocation_preview('rebalance'); check($p['summary']['released']===1&&$p['summary']['unfilled']===2,'rebalance explains previously occupied role becoming vacant');
 fixture([], [['No candidates','HEM',3]]); $p=allocation_preview('rebalance'); check($p['summary']['unfilled']===1,'empty teacher list handled');
 fixture([['A','',20]], [['Role','HEM',3]]); $p=allocation_preview('vacant'); q('UPDATE positions SET weight=4 WHERE id=1');
 rejects(fn()=>apply_test($p['plan_id']),'changed weight invalidates plan',409); check((int)q('SELECT COUNT(*) FROM assignments')->fetchColumn()===0,'stale plan writes nothing');
 $p=allocation_preview('vacant'); allocation_save_rules(['max_tasks'=>2]); rejects(fn()=>apply_test($p['plan_id']),'changed rules invalidate plan',409);
 $p=allocation_preview('vacant'); q('UPDATE settings SET value="2029" WHERE key="year"'); rejects(fn()=>apply_test($p['plan_id']),'changed session label invalidates plan',409);
 $p=allocation_preview('vacant'); q('UPDATE allocation_plans SET created_at=? WHERE id=?',[time()-1801,$p['plan_id']]); rejects(fn()=>apply_test($p['plan_id']),'expired plan rejected',409);
 $p=allocation_preview('vacant'); q('INSERT INTO assignments(teacher_id,position_id) VALUES(1,1)'); rejects(fn()=>apply_test($p['plan_id']),'changed assignment invalidates plan',409);
 fixture([['A','',20]],[]);
 $rows=bulk_positions_parse("\xEF\xBB\xBFname,category,weight,count\n\"AJK, Khas\",HEM,5,3\nSTEM,Kurikulum,6,1\n");
 check(count($rows)===4&&$rows[0]['name']==='AJK, Khas (1/3)','CSV BOM, quoted commas and quantity expansion');
 $db->beginTransaction(); bulk_positions_insert($rows); $db->commit(); check((int)q('SELECT COUNT(*) FROM positions')->fetchColumn()===4,'bulk inserts expanded seats');
 rejects(fn()=>bulk_positions_validate($rows),'repeated import rejected');
 $before=(int)q('SELECT COUNT(*) FROM positions')->fetchColumn();
 rejects(fn()=>bulk_positions_parse("Valid,HEM,3,1\nInvalid,HEM,99,1"),'invalid batch rejected before insert');
 check((int)q('SELECT COUNT(*) FROM positions')->fetchColumn()===$before,'invalid batch leaves database unchanged');
 $tab=bulk_positions_parse("name\tcategory\tweight\tcount\nTab Role\tPentadbiran\t4\t1"); check($tab[0]['category']==='Pentadbiran','Excel TSV paste supported');
 rejects(fn()=>bulk_positions_parse("Same,HEM,3\nsame,HEM,4"),'case insensitive duplicate batch');
 rejects(fn()=>bulk_positions_validate([['name'=>'A','category'=>'Bad','weight'=>3]]),'invalid category');
 rejects(fn()=>bulk_positions_validate([['name'=>'A','category'=>'HEM','weight'=>3,'count'=>51]]),'quantity maximum');
 rejects(fn()=>bulk_positions_parse(str_repeat('x',131073)),'bulk size limit');
 rejects(fn()=>bulk_positions_validate(array_fill(0,501,['name'=>'A','category'=>'HEM','weight'=>3])),'bulk row limit');
 allocation_save_position_rule(1,['allowed_teachers'=>[1]]); check(allocation_teacher_referenced(1),'teacher in rules cannot be silently deleted and reused');
 echo "\n$checks allocation and bulk checks passed.\n";
} finally { $db=null; @unlink($temp); }
