<?php
declare(strict_types=1);
/* Aliran kelulusan agihan tugas: Draf → Semakan → Lulus → Muktamad (satu keadaan setiap sekolah, workflow_state).
 *  - Hantar: Pentadbir/PK, draf, ≥1 lantikan → revisi dengan snapshot JSON tetap + hash. Data perancangan dikunci.
 *  - Lulus / Muktamad: GB/Pengetua sahaja, bukan penghantar, hash snapshot mesti sama dengan data semasa.
 *  - Kembalikan: Pentadbir/GB/Pengetua dengan sebab; dari Muktamad → draf pindaan (revisi muktamad kekal diterbitkan).
 *  - Kunci optimistik: setiap peralihan memerlukan version semasa. Surat & "Tugas Saya" membaca revisi muktamad terkini. */

const WORKFLOW_LABELS=['draft'=>'Draf','submitted'=>'Semakan','approved'=>'Lulus','finalized'=>'Muktamad'];

function workflow_state(): array {
    $s=query('SELECT status,revision_id,version FROM workflow_state WHERE school_id='.sid())->fetch();
    if(!$s){ school_engine_init(); $s=['status'=>'draft','revision_id'=>null,'version'=>1]; }
    return ['status'=>$s['status'],'revision_id'=>$s['revision_id']!==null?(int)$s['revision_id']:null,'version'=>(int)$s['version']];
}
function workflow_require_draft(): void {
    if(workflow_state()['status']!=='draft') throw new WorkflowError('Agihan dikunci untuk semakan / kelulusan. Kembalikan kepada draf sebelum membuat pindaan.');
}
/** Salinan tetap data agihan untuk revisi (dan semakan "data berubah sejak dihantar"). */
function workflow_source(): array {
    $int=fn(array $rows,array $cols)=>array_map(function($r)use($cols){ foreach($cols as $c) if($r[$c]!==null) $r[$c]=(int)$r[$c]; return $r; },$rows);
    return ['school'=>school_profile(),
        'teachers'=>$int(query('SELECT id,name,email,specialty,capacity,external_id FROM teachers WHERE school_id='.sid().' ORDER BY id')->fetchAll(),['id','capacity']),
        'positions'=>$int(query('SELECT p.id,p.name,p.category,p.weight,a.teacher_id FROM positions p LEFT JOIN assignments a ON a.position_id=p.id AND a.school_id=p.school_id WHERE p.school_id='.sid().' ORDER BY p.id')->fetchAll(),['id','weight','teacher_id']),
        'rules'=>allocation_rules(),'position_rules'=>allocation_position_rules()];
}
function workflow_hash(array $source): string { return hash('sha256',json_encode($source,JSON_THROW_ON_ERROR)); }
/** Peralihan status. $actor = pengguna semasa (id, name, role). Pemanggil memiliki transaksi. */
function workflow_transition(string $action,int $expectedVersion,array $actor,string $reason=''): array {
    query('SELECT school_id FROM workflow_state WHERE school_id='.sid().' FOR UPDATE');
    $state=workflow_state();
    if($state['version']!==$expectedVersion) throw new WorkflowError('Status telah berubah. Muat semula halaman sebelum meneruskan.');
    $status=$state['status']; $rid=$state['revision_id']; $target=''; $reason=trim($reason); $uid=(int)$actor['id'];
    if($action==='submit'){
        if(!in_array($actor['role'],['admin','pk'],true)) throw new WorkflowError('Hanya Pentadbir atau PK boleh menghantar draf.',403);
        if($status!=='draft') throw new WorkflowError('Hanya draf boleh dihantar untuk semakan.');
        if(!(int)query('SELECT COUNT(*) FROM assignments WHERE school_id='.sid())->fetchColumn()) throw new WorkflowError('Sediakan sekurang-kurangnya satu lantikan sebelum menghantar.',422);
        $source=workflow_source();
        query('INSERT INTO workflow_revisions (school_id,status,payload,source_hash,submitted_by) VALUES ('.sid().',"submitted",?,?,?)',[json_encode($source,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE),workflow_hash($source),$uid]);
        $rid=(int)db()->lastInsertId(); $target='submitted';
    } elseif(in_array($action,['approve','finalize'],true)){
        if($actor['role']!=='head') throw new WorkflowError('Hanya '.head_title().' boleh meluluskan atau memuktamadkan.',403);
        if($status!==($action==='approve'?'submitted':'approved')) throw new WorkflowError('Urutan status tidak sah.');
        $r=query('SELECT submitted_by,source_hash FROM workflow_revisions WHERE id=? AND school_id='.sid(),[$rid])->fetch();
        if(!$r) throw new WorkflowError('Revisi tidak ditemui.',404);
        if($uid===(int)$r['submitted_by']) throw new WorkflowError('Penghantar tidak boleh meluluskan agihan sendiri.',403);
        if(!hash_equals((string)$r['source_hash'],workflow_hash(workflow_source()))) throw new WorkflowError('Data telah berubah sejak dihantar. Kembalikan kepada draf dan hantar semula.');
        $target=$action==='approve'?'approved':'finalized'; $col=$action==='approve'?'approved':'finalized';
        query('UPDATE workflow_revisions SET status=?,'.$col.'_by=?,'.$col.'_at=NOW() WHERE id=? AND school_id='.sid(),[$target,$uid,$rid]);
    } elseif($action==='return'){
        if(!in_array($actor['role'],['admin','head'],true)) throw new WorkflowError('Hanya Pentadbir atau '.head_title().' boleh membuka semula draf.',403);
        if($status==='draft') throw new WorkflowError('Agihan sudah dalam draf.');
        if($reason==='' || mb_strlen($reason)>500) throw new WorkflowError('Nyatakan sebab pindaan (maksimum 500 aksara).',422);
        if($status!=='finalized') query('UPDATE workflow_revisions SET status="returned",returned_by=?,returned_at=NOW(),return_reason=? WHERE id=? AND school_id='.sid(),[$uid,$reason,$rid]);
        $target='draft';
    } else throw new WorkflowError('Tindakan tidak sah.',422);
    $ok=query('UPDATE workflow_state SET status=?,revision_id=?,version=version+1 WHERE school_id='.sid().' AND version=?',[$target,$rid,$expectedVersion])->rowCount();
    if($ok!==1) throw new WorkflowError('Status telah berubah. Cuba semula.');
    audit('workflow_'.$action,'workflow_revisions',$rid,WORKFLOW_LABELS[$status].' → '.WORKFLOW_LABELS[$target].($reason!==''?' · '.mb_substr($reason,0,180):''));
    return workflow_state();
}
/** Revisi muktamad terkini (salinan tetap) atau null. */
function workflow_published(): ?array {
    $r=query('SELECT * FROM workflow_revisions WHERE school_id='.sid().' AND status="finalized" ORDER BY id DESC LIMIT 1')->fetch();
    return $r?[...$r,'id'=>(int)$r['id'],'no'=>workflow_no((int)$r['id']),'data'=>json_decode((string)$r['payload'],true,64,JSON_THROW_ON_ERROR)]:null;
}
/** Nombor versi mengikut sekolah (1, 2, …) untuk paparan; ID revisi adalah global. */
function workflow_no(int $rid): int { return $rid>0?(int)query('SELECT COUNT(*) FROM workflow_revisions WHERE school_id='.sid().' AND id<=?',[$rid])->fetchColumn():0; }
/** Kandungan surat lantikan seorang guru daripada revisi muktamad. */
function workflow_letter(int $tid,?array $published=null): array {
    $r=$published??workflow_published(); if(!$r) throw new WorkflowError('Surat hanya tersedia selepas agihan dimuktamadkan.');
    $teacher=null; foreach($r['data']['teachers'] as $t) if($t['id']===$tid) $teacher=$t;
    if(!$teacher) throw new WorkflowError('Guru tiada dalam agihan muktamad.',404);
    $tasks=array_values(array_filter($r['data']['positions'],fn($p)=>$p['teacher_id']===$tid));
    return ['revision_id'=>$r['id'],'revision_no'=>$r['no']??workflow_no($r['id']),'school'=>$r['data']['school'],'teacher'=>$teacher,'assignments'=>$tasks,'finalized_at'=>$r['finalized_at']];
}
function workflow_time(?string $dt): string { return $dt?date('d/m/Y H:i',strtotime($dt)):'—'; }
