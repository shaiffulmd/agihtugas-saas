<?php
declare(strict_types=1);
/* Auto-agihan tugas (lihat docs/AGIHAN_AUTO.md) — logik sama seperti UrusTugas satu sekolah, kini setiap sekolah:
 *  - Syarat umum (allocation_config, satu baris setiap sekolah) & syarat jawatan (position_rules).
 *  - Mod "vacant" (kekal lantikan sedia ada, amaran pelanggaran) dan "rebalance" (kira semula dari kosong).
 *  - Pratonton disimpan dengan hash keadaan; apply menolak jika keadaan berubah, sudah digunakan atau > 30 minit.
 *  - Jawatan pukal: CSV / Excel — nama, bidang, pemberat, bilangan (tajuk Inggeris lama name,category,weight,count turut diterima). */

function allocation_defaults(): array { return ['respect_capacity'=>true,'max_tasks'=>13,'max_heavy'=>2,'heavy_threshold'=>8,'category_limits'=>array_fill_keys(CATEGORIES,4),'excluded_teachers'=>[]]; }
function allocation_rules(): array {
    $stored=query('SELECT payload FROM allocation_config WHERE school_id='.sid())->fetchColumn();
    return $stored?json_decode((string)$stored,true,32,JSON_THROW_ON_ERROR):allocation_defaults();
}
function allocation_int(mixed $v,string $label,int $min,int $max): int {
    if(!is_int($v) || $v<$min || $v>$max) throw new AllocationError("$label mesti integer antara $min dan $max."); return $v;
}
/** Senarai ID guru dalam sekolah semasa (ID sekolah lain ditolak seperti tidak wujud). */
function allocation_teacher_ids(mixed $values,string $label): array {
    if(!is_array($values) || !array_is_list($values)) throw new AllocationError("$label mesti senarai ID.");
    $ids=[]; foreach($values as $v){ $id=allocation_int($v,$label,1,PHP_INT_MAX); if(!teacher_find($id)) throw new AllocationError("$label merujuk rekod yang tidak ditemui."); $ids[]=$id; }
    return array_values(array_unique($ids));
}
function allocation_save_rules(array $input): array {
    $defaults=allocation_defaults(); if(array_diff(array_keys($input),array_keys($defaults))) throw new AllocationError('Medan syarat tidak dikenali.');
    $rules=array_merge(allocation_rules(),$input);
    if(!is_bool($rules['respect_capacity'])) throw new AllocationError('respect_capacity mesti boolean.');
    foreach(['max_tasks'=>[1,50],'max_heavy'=>[0,50],'heavy_threshold'=>[1,10]] as $k=>[$min,$max]) allocation_int($rules[$k],$k,$min,$max);
    if(!is_array($rules['category_limits']) || array_diff(array_keys($rules['category_limits']),CATEGORIES) || array_diff(CATEGORIES,array_keys($rules['category_limits']))) throw new AllocationError('Had mesti lengkap untuk empat bidang.');
    foreach($rules['category_limits'] as $limit) allocation_int($limit,'Had bidang',0,50);
    $rules['excluded_teachers']=allocation_teacher_ids($rules['excluded_teachers'],'Guru dikecualikan');
    query('INSERT INTO allocation_config (school_id,payload) VALUES ('.sid().',?) ON DUPLICATE KEY UPDATE payload=VALUES(payload)',[json_encode($rules,JSON_THROW_ON_ERROR)]);
    return $rules;
}
function allocation_position_rules(): array {
    $rules=[]; foreach(query('SELECT position_id,payload FROM position_rules WHERE school_id='.sid().' ORDER BY position_id')->fetchAll() as $r) $rules[(int)$r['position_id']]=json_decode((string)$r['payload'],true,32,JSON_THROW_ON_ERROR);
    return $rules;
}
function allocation_save_position_rule(int $id,array $input): array {
    if(!position_find($id)) throw new AllocationError('Jawatan tidak ditemui.',404);
    if(array_diff(array_keys($input),['allowed_teachers','fixed_teacher_id','required_specialty'])) throw new AllocationError('Medan syarat jawatan tidak dikenali.');
    $rule=array_merge(['allowed_teachers'=>[],'fixed_teacher_id'=>null,'required_specialty'=>''],$input);
    $rule['allowed_teachers']=allocation_teacher_ids($rule['allowed_teachers'],'Guru layak');
    if($rule['fixed_teacher_id']!==null) allocation_teacher_ids([$rule['fixed_teacher_id']],'Penyandang tetap');
    if(!is_string($rule['required_specialty']) || mb_strlen($rule['required_specialty'])>160) throw new AllocationError('Kepakaran diperlukan mesti teks maksimum 160 aksara.');
    $rule['required_specialty']=trim($rule['required_specialty']);
    if($rule['fixed_teacher_id']!==null && $rule['allowed_teachers'] && !in_array($rule['fixed_teacher_id'],$rule['allowed_teachers'],true)) throw new AllocationError('Penyandang tetap mesti berada dalam senarai guru layak.');
    query('INSERT INTO position_rules (school_id,position_id,payload) VALUES ('.sid().',?,?) ON DUPLICATE KEY UPDATE payload=VALUES(payload)',[$id,json_encode($rule,JSON_THROW_ON_ERROR)]);
    return $rule;
}
/** Keadaan yang mempengaruhi pratonton. Integer dinormalkan supaya hash stabil. */
function allocation_state(): array {
    $int=fn(array $rows,array $cols)=>array_map(function($r)use($cols){ foreach($cols as $c) if(isset($r[$c])) $r[$c]=(int)$r[$c]; return $r; },$rows);
    $wf=query('SELECT status,revision_id,version FROM workflow_state WHERE school_id='.sid())->fetch()?:[];
    return ['workflow'=>$wf,'school'=>school_profile(),
        'teachers'=>$int(query('SELECT id,name,email,specialty,capacity,external_id,status,teaching_periods,exam_class,index_exempt FROM teachers WHERE school_id='.sid().' ORDER BY id')->fetchAll(),['id','capacity','teaching_periods','exam_class','index_exempt']),
        'positions'=>$int(query('SELECT id,name,category,weight,level,term FROM positions WHERE school_id='.sid().' ORDER BY id')->fetchAll(),['id','weight']),
        'index'=>index_settings(),
        'assignments'=>$int(query('SELECT position_id,teacher_id FROM assignments WHERE school_id='.sid().' ORDER BY position_id')->fetchAll(),['position_id','teacher_id']),
        'rules'=>allocation_rules(),'position_rules'=>allocation_position_rules()];
}
function allocation_snapshot(array $state): string { return hash('sha256',json_encode($state,JSON_THROW_ON_ERROR)); }
function allocation_eligible(array $t,array $p,array $rule,array $rules): ?string {
    if(in_array($t['id'],$rules['excluded_teachers'],true)) return 'Guru dikecualikan';
    if(($t['status']??'aktif')!=='aktif') return 'Guru bercuti / bertukar keluar';
    if(($rule['fixed_teacher_id']??null)!==null && $t['id']!==$rule['fixed_teacher_id']) return 'Jawatan mempunyai penyandang tetap';
    if(!empty($rule['allowed_teachers']) && !in_array($t['id'],$rule['allowed_teachers'],true)) return 'Di luar senarai guru layak';
    if(($rule['required_specialty']??'')!=='' && mb_stripos($t['specialty'],$rule['required_specialty'])===false) return 'Kepakaran tidak sepadan';
    return null;
}
function allocation_limits(array $t,array $p,array $rules): ?string {
    if($rules['respect_capacity'] && $t['load']+$p['weight']>$t['capacity']) return 'Had kapasiti mata';
    if($t['task_count']+1>$rules['max_tasks']) return 'Had bilangan tugas';
    if($p['weight']>=$rules['heavy_threshold'] && $t['heavy']+1>$rules['max_heavy']) return 'Had tugas berat';
    if(($t['categories'][$p['category']]??0)+1>$rules['category_limits'][$p['category']]) return 'Had bidang '.$p['category'];
    return null;
}
function allocation_add(array &$t,array $p,int $threshold): void {
    $t['load']+=$p['weight']; $t['score']+=$p['score']; $t['task_count']++; if($p['weight']>=$threshold) $t['heavy']++; $t['categories'][$p['category']]++;
}
/** Skala indeks untuk agihan: [bahagian mengajar, pembahagi masa mengajar, pembahagi skor tugas].
 *  Masa mengajar ÷ median guru dikira (sama seperti load_index()). Median skor tugas belum diketahui semasa agihan berjalan,
 *  jadi skor dibahagi dengan purata skor JANGKAAN selepas semua jawatan diisi (jumlah skor jawatan ÷ guru dikira). */
function allocation_index_scale(array $teachers,array $positions,array $s): array {
    $T=[]; foreach($teachers as $t) if($t['counted']) $T[]=$t['teaching'];
    $m=median($T); $dT=$m>0?$m:($T?array_sum($T)/count($T):0.0);
    $dS=$T?array_sum(array_column($positions,'score'))/count($T):0.0;
    return [$dT>0?($dS>0?$s['teach_share']:1.0):0.0,$dT,$dS];
}
/** Anggaran indeks beban guru jika jawatan $p ditambah (null bagi guru yang tidak dikira dalam indeks). */
function allocation_index_after(array $t,array $p,array $scale): ?float {
    [$share,$dT,$dS]=$scale; if(!$t['counted'] || ($dT<=0 && $dS<=0)) return null;
    return $share*($dT>0?$t['teaching']/$dT:0.0)+(1-$share)*($dS>0?($t['score']+$p['score'])/$dS:0.0);
}
/** Jana pratonton (belum mengubah lantikan) dan simpan untuk apply. */
function allocation_preview(string $mode): array {
    if(!in_array($mode,['vacant','rebalance'],true)) throw new AllocationError('Mod agihan tidak sah.');
    $state=allocation_state(); $rules=$state['rules'];
    $S=$state['index'];
    $teachers=[]; foreach($state['teachers'] as $t) $teachers[$t['id']]=[...$t,'load'=>0,'score'=>0.0,'task_count'=>0,'heavy'=>0,'categories'=>array_fill_keys(CATEGORIES,0),
        'teaching'=>$t['teaching_periods']*($t['exam_class']?$S['exam_factor']:1),'counted'=>$t['status']==='aktif' && !$t['index_exempt']];
    $positions=[]; foreach($state['positions'] as $p) $positions[$p['id']]=[...$p,'score'=>position_score($p)];
    $scale=allocation_index_scale($teachers,$positions,$S); $existing=array_column($state['assignments'],'teacher_id','position_id');
    $mapping=[]; $reasons=[]; $warnings=[]; $pending=[]; $snames=null;
    if($mode==='vacant') foreach($existing as $pid=>$tid){
        $p=$positions[$pid]; $rule=$state['position_rules'][$pid]??[];
        $issue=allocation_eligible($teachers[$tid],$p,$rule,$rules)??allocation_limits($teachers[$tid],$p,$rules);
        if($issue) $warnings[]=$teachers[$tid]['name'].' · '.$p['name'].': '.$issue.' (lantikan sedia ada dikekalkan).';
        allocation_add($teachers[$tid],$p,$rules['heavy_threshold']); $mapping[$pid]=$tid; $reasons[$pid]='Lantikan sedia ada dikekalkan';
    }
    foreach($positions as $pid=>$p){
        if(isset($mapping[$pid])) continue;
        $rule=$state['position_rules'][$pid]??[]; $eligible=[];
        foreach($teachers as $tid=>$t) if(allocation_eligible($t,$p,$rule,$rules)===null) $eligible[]=$tid;
        // MP yang disebut dalam nama jawatan (cth. Ketua Panitia Matematik) → guru yang mengajarnya diutamakan.
        $subj=position_subject((string)$p['name'],$snames??=subject_names());
        $pending[]=['position'=>$p,'eligible'=>$eligible,'fixed'=>($rule['fixed_teacher_id']??null)!==null,'subject'=>$subj,'teaches'=>$subj===null?[]:array_flip(array_filter($eligible,fn($tid)=>teacher_teaches($teachers[$tid],$subj)))];
    }
    // Penyandang tetap dahulu, kemudian jawatan MP yang ada guru sepadan (supaya guru MP tidak habis digunakan jawatan lain), jawatan bercalon terhad, jawatan lebih berat.
    usort($pending,fn($a,$b)=>[(int)!$a['fixed'],(int)!$a['teaches'],count($a['eligible']),-$a['position']['weight'],$a['position']['id']]<=>[(int)!$b['fixed'],(int)!$b['teaches'],count($b['eligible']),-$b['position']['weight'],$b['position']['id']]);
    $unfilled=[];
    foreach($pending as $item){
        $p=$item['position']; $candidates=[]; $blocked=[];
        // Calon terbaik = kunci terkecil [dikecualikan daripada indeks, tidak mengajar MP jawatan, indeks beban selepas lantikan
        // (nisbah kapasiti bagi guru dikecualikan), nisbah kapasiti, bil. tugas, tugas bidang ini, ID] — carian minimum O(n), bukan isihan penuh.
        $bestKey=null; $bestIdx=null;
        foreach($item['eligible'] as $tid){ $issue=allocation_limits($teachers[$tid],$p,$rules); if($issue){ $blocked[$issue]=true; continue; }
            $t=$teachers[$tid]; $ratio=($t['load']+$p['weight'])/$t['capacity']; $idx=allocation_index_after($t,$p,$scale);
            $k=[(int)($idx===null),(int)!isset($item['teaches'][$tid]),round($idx??$ratio,4),$ratio,$t['task_count'],$t['categories'][$p['category']],$tid];
            if($bestKey===null || $k<$bestKey){ $bestKey=$k; $bestIdx=$idx; $candidates=[$tid]; } }
        if(!$candidates){ $reason=$item['eligible']?implode(', ',array_keys($blocked)):'Tiada guru yang memenuhi kelayakan / pengecualian'; $reasons[$p['id']]=$reason; $unfilled[]=['position_id'=>$p['id'],'position_name'=>$p['name'],'reason'=>$reason]; continue; }
        $tid=$candidates[0]; $mapping[$p['id']]=$tid; allocation_add($teachers[$tid],$p,$rules['heavy_threshold']);
        $how=$bestIdx===null?'nisbah kapasiti terendah (guru dikecualikan daripada indeks)':'indeks beban selepas lantikan terendah ('.number_format($bestIdx,2).')';
        $reasons[$p['id']]=$item['fixed']?'Penyandang tetap yang memenuhi syarat':(isset($item['teaches'][$tid])?'Mengajar '.$item['subject'].' · '.$how.' antara guru '.$item['subject']:ucfirst($how).' antara calon yang layak');
    }
    $rows=[]; $changes=0; $released=0;
    foreach($positions as $pid=>$p){
        $before=$existing[$pid]??null; $after=$mapping[$pid]??null;
        if($before!==$after) $changes++; if($before!==null && $after===null) $released++;
        $rows[]=['position_id'=>$pid,'position_name'=>$p['name'],'category'=>$p['category'],'weight'=>$p['weight'],'before_teacher_id'=>$before,'before_teacher_name'=>$before?$teachers[$before]['name']:null,'teacher_id'=>$after,'teacher_name'=>$after?$teachers[$after]['name']:null,'reason'=>$reasons[$pid],'changed'=>$before!==$after];
    }
    foreach($teachers as &$t){ $t['index']=($i=allocation_index_after($t,['score'=>0.0],$scale))===null?null:round($i,2); } unset($t);
    $plan=['mode'=>$mode,'rows'=>$rows,'unfilled'=>$unfilled,'warnings'=>$warnings,'teachers'=>array_values($teachers),'summary'=>['assigned'=>count($mapping),'unfilled'=>count($unfilled),'changed'=>$changes,'released'=>$released],'rules'=>$rules];
    $id=bin2hex(random_bytes(24));
    query('DELETE FROM allocation_plans WHERE school_id='.sid().' AND created_at < NOW() - INTERVAL 1 DAY');
    query('INSERT INTO allocation_plans (id,school_id,snapshot,payload,created_by) VALUES (?,'.sid().',?,?,?)',[$id,allocation_snapshot($state),json_encode($plan,JSON_THROW_ON_ERROR),current_user()['id']??null]);
    return ['plan_id'=>$id,'expires_at'=>date(DATE_ATOM,time()+1800),...$plan];
}
/** Simpan pratonton. Pemanggil memiliki transaksi. */
function allocation_apply(string $id): array {
    if(!preg_match('/^[a-f0-9]{48}$/D',$id)) throw new AllocationError('Pratonton tidak ditemui. Jana semula pratonton.',404);
    $row=query('SELECT *,created_at < NOW() - INTERVAL 30 MINUTE AS expired FROM allocation_plans WHERE id=? AND school_id='.sid().' FOR UPDATE',[$id])->fetch();
    if(!$row) throw new AllocationError('Pratonton tidak ditemui. Jana semula pratonton.',404);
    if($row['applied_at']!==null) throw new AllocationError('Pratonton ini sudah digunakan. Jana pratonton baharu.',409);
    if((int)$row['expired']) throw new AllocationError('Pratonton telah tamat tempoh. Jana semula.',409);
    query('SELECT school_id FROM workflow_state WHERE school_id='.sid().' FOR UPDATE'); // sirikan apply serentak bagi sekolah ini
    if(!hash_equals((string)$row['snapshot'],allocation_snapshot(allocation_state()))) throw new AllocationError('Data atau syarat telah berubah selepas pratonton. Jana semula sebelum menyimpan.',409);
    query('UPDATE allocation_plans SET applied_at=NOW() WHERE id=? AND school_id='.sid().' AND applied_at IS NULL',[$id]);
    $plan=json_decode((string)$row['payload'],true,64,JSON_THROW_ON_ERROR);
    foreach($plan['rows'] as $r){
        if(!$r['changed']) continue;
        if($r['teacher_id']===null) query('DELETE FROM assignments WHERE school_id='.sid().' AND position_id=?',[$r['position_id']]);
        else query('INSERT INTO assignments (school_id,teacher_id,position_id) VALUES ('.sid().',?,?) ON DUPLICATE KEY UPDATE teacher_id=VALUES(teacher_id)',[$r['teacher_id'],$r['position_id']]);
    }
    return $plan['summary'];
}
function allocation_teacher_referenced(int $id): bool {
    if(in_array($id,allocation_rules()['excluded_teachers'],true)) return true;
    foreach(allocation_position_rules() as $r) if(($r['fixed_teacher_id']??null)===$id || in_array($id,$r['allowed_teachers']??[],true)) return true;
    return false;
}

/* ---------- Jawatan pukal ---------- */
function bulk_positions_validate(array $rows): array {
    if(!array_is_list($rows) || !$rows || count($rows)>500) throw new AllocationError('Sediakan antara 1 hingga 500 baris jawatan.');
    $seen=[]; foreach(query('SELECT name,category FROM positions WHERE school_id='.sid())->fetchAll() as $p) $seen[mb_strtolower(trim($p['name'])).'|'.$p['category']]=true;
    $result=[];
    foreach($rows as $i=>$row){
        $line=$i+1;
        if(!is_array($row) || array_diff(array_keys($row),['name','category','weight','count'])) throw new AllocationError("Baris $line: medan tidak sah.");
        $name=$row['name']??null; $category=$row['category']??null;
        if(!is_string($name) || trim($name)==='' || mb_strlen(trim($name))>145) throw new AllocationError("Baris $line: nama jawatan diperlukan (maksimum 145 aksara).");
        if(!in_array($category,CATEGORIES,true)) throw new AllocationError("Baris $line: bidang mesti Pentadbiran, Kurikulum, HEM atau Kokurikulum.");
        $weight=allocation_int($row['weight']??null,"Baris $line: pemberat",1,10);
        $count=allocation_int($row['count']??1,"Baris $line: bilangan",1,50);
        for($n=1;$n<=$count;$n++){
            $title=trim($name).($count>1?" ($n/$count)":''); $key=mb_strtolower($title).'|'.$category;
            if(isset($seen[$key])) throw new AllocationError("Baris $line: jawatan '$title' sudah wujud atau berulang dalam senarai.");
            $seen[$key]=true; $result[]=['name'=>$title,'category'=>$category,'weight'=>$weight];
            if(count($result)>1000) throw new AllocationError('Maksimum 1,000 tempat lantikan bagi satu import.');
        }
    }
    return $result;
}
/** Tajuk lajur jawatan pukal yang diterima (lihat bulk_header_key()). */
const BULK_POSITION_COLS=[['nama','nama_jawatan','jawatan','name'],['bidang','kategori','category'],['pemberat','mata','weight'],['bilangan','bil','count']];
function bulk_positions_parse(string $text): array {
    if(strlen($text)>131072) throw new AllocationError('Fail / teks pukal maksimum 128 KB.');
    try { $raw=csv_rows($text,BULK_POSITION_COLS,3,4); } catch(EngineError $ex){ throw new AllocationError($ex->getMessage().' Gunakan lajur nama, bidang, pemberat dan bilangan (pilihan).'); }
    $rows=[]; $cats=array_combine(array_map('mb_strtolower',CATEGORIES),CATEGORIES);
    foreach($raw as $r){
        if(!ctype_digit($r[2]) || (isset($r[3]) && $r[3]!=='' && !ctype_digit($r[3]))) throw new AllocationError('Pemberat dan bilangan mesti nombor bulat.');
        $rows[]=['name'=>$r[0],'category'=>$cats[mb_strtolower($r[1])]??$r[1],'weight'=>(int)$r[2],'count'=>isset($r[3]) && $r[3]!==''?(int)$r[3]:1]; // bidang tanpa beza huruf (hem → HEM)
    }
    return bulk_positions_validate($rows);
}
function bulk_positions_insert(array $validated): array {
    free_guard('jawatan',count($validated));
    $ids=[]; foreach($validated as $p){ query('INSERT INTO positions (school_id,name,category,weight) VALUES ('.sid().',?,?,?)',[$p['name'],$p['category'],$p['weight']]); $ids[]=(int)db()->lastInsertId(); }
    return $ids;
}
