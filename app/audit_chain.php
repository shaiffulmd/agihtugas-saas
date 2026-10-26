<?php
declare(strict_types=1);
/*
 * Log audit berantai (tamper-evident), corak delima-multi.
 *  Setiap baris audit_logs (setiap sekolah) dan platform_log (school_id 0 dalam audit_heads) mendapat
 *  seq 1, 2, 3 … dan chain = HMAC-SHA256(kunci audit, chain_sebelum | data baris).
 *   - baris diubah            → HMAC tidak sepadan
 *   - baris dipadam di tengah → seq melompat
 *   - baris dibuang di hujung → tidak sepadan dengan audit_heads
 *  Kunci diterbitkan daripada APP_KEY (di luar pangkalan data): akses DB sahaja tidak cukup untuk mengira semula rantai.
 *  Lajur rujukan (via_ref, admin_ref, school_ref) ialah salinan tanpa FK — FK ON DELETE SET NULL tidak memecahkan rantai.
 */
const AUDIT_GENESIS='0000000000000000000000000000000000000000000000000000000000000000';

function audit_key(): string { return hash_hkdf('sha256',master_key(),32,'urustugas/audit'); }
function audit_fields(int $sid,array $r): array {
    return $sid>0?[$sid,$r['seq'],$r['user_id'],$r['via_ref'],$r['action'],$r['entity'],$r['entity_id'],$r['detail']??'',$r['ip_hash'],$r['created_at']]
                 :[0,$r['seq'],$r['admin_ref'],$r['school_ref'],$r['action'],$r['detail'],$r['ip_hash'],$r['created_at']];
}
function audit_hash(string $prev,array $f): string { return hash_hmac('sha256',$prev.'|'.implode("\x1f",array_map(fn($v)=>$v===null?"\0":(string)$v,$f)),audit_key()); }
function audit_ip_hash(): string { return hash_hmac('sha256',$_SERVER['REMOTE_ADDR']??'cli',sid()>0?key_bytes():platform_key()); }

/** Tambah satu baris berantai. $sid 0 = platform_log. Baris & kepala rantai ditulis dalam transaksi yang sama. */
function audit_append(int $sid,array $row): void {
    $own=!db()->inTransaction(); if($own) db()->beginTransaction();
    try {
        query('INSERT IGNORE INTO audit_heads(school_id,seq,chain) VALUES (?,0,?)',[$sid,AUDIT_GENESIS]);
        $h=query('SELECT seq,chain FROM audit_heads WHERE school_id=? FOR UPDATE',[$sid])->fetch();
        $row['seq']=(int)$h['seq']+1; $row['created_at']=(string)query('SELECT NOW()')->fetchColumn(); // masa DB, sama seperti lalai CURRENT_TIMESTAMP
        if($sid>0){ $row['school_id']=$sid; $row['detail']=mb_substr((string)($row['detail']??''),0,255); }
        $row['chain']=audit_hash((string)$h['chain'],audit_fields($sid,$row));
        $cols=array_keys($row);
        query('INSERT INTO '.($sid>0?'audit_logs':'platform_log').' ('.implode(',',$cols).') VALUES ('.implode(',',array_fill(0,count($cols),'?')).')',array_values($row));
        query('UPDATE audit_heads SET seq=?,chain=? WHERE school_id=?',[$row['seq'],$row['chain'],$sid]);
        if($own) db()->commit();
    } catch(Throwable $ex){ if($own && db()->inTransaction()) db()->rollBack(); throw $ex; }
}

/** Sahkan rantai. Pulangkan ['ok','rows','problems'=>[…]]. */
function audit_verify(int $sid,int $max=50): array {
    $t=$sid>0?'audit_logs':'platform_log'; $w=$sid>0?'school_id='.$sid.' AND ':'';
    $prev=AUDIT_GENESIS; $exp=1; $n=0; $last=0; $p=[];
    $add=function(string $m) use(&$p,$max){ if(count($p)<$max) $p[]=$m; };
    for(;;){
        $rows=query('SELECT * FROM '.$t.' WHERE '.$w.'seq>? ORDER BY seq LIMIT 2000',[$last])->fetchAll(); if(!$rows) break;
        foreach($rows as $r){
            $seq=(int)$r['seq']; $n++;
            if($seq!==$exp) $add('Baris hilang: seq '.$exp.($seq-1>$exp?'–'.($seq-1):'').' (sebelum log #'.$r['id'].', '.$r['created_at'].')');
            if(!hash_equals(audit_hash($prev,audit_fields($sid,$r)),(string)$r['chain'])) $add('Baris diubah atau rantai terputus: log #'.$r['id'].' (seq '.$seq.', '.$r['created_at'].', '.$r['action'].')');
            $prev=(string)$r['chain']; $exp=$seq+1; $last=$seq;
        }
    }
    $nul=(int)query('SELECT COUNT(*) FROM '.$t.' WHERE '.$w.'seq IS NULL')->fetchColumn();
    if($nul) $add($nul.' baris tanpa tandatangan rantai (ditambah terus ke pangkalan data?)');
    $h=query('SELECT seq,chain FROM audit_heads WHERE school_id=?',[$sid])->fetch();
    if($h && ((int)$h['seq']!==$last || !hash_equals((string)$h['chain'],$prev))) $add('Baris terakhir dipadam: kepala rantai seq '.(int)$h['seq'].', baris terakhir seq '.$last);
    if(!$h && $n>0) $add('Kepala rantai tiada');
    return ['ok'=>!$p,'rows'=>$n+$nul,'problems'=>$p];
}

/** Tandatangan semula semua baris (ikut id). Untuk naik taraf/import, atau selepas masalah disiasat (dilog oleh pemanggil). */
function audit_rebuild(int $sid): int {
    $t=$sid>0?'audit_logs':'platform_log'; $w=$sid>0?' WHERE school_id='.$sid:'';
    $own=!db()->inTransaction(); if($own) db()->beginTransaction();
    try {
        query('INSERT IGNORE INTO audit_heads(school_id,seq,chain) VALUES (?,0,?)',[$sid,AUDIT_GENESIS]);
        query('SELECT seq FROM audit_heads WHERE school_id=? FOR UPDATE',[$sid]);
        query('UPDATE '.$t.' SET seq=NULL'.$w);
        $prev=AUDIT_GENESIS; $seq=0; $last=0;
        for(;;){
            $rows=query('SELECT * FROM '.$t.($w!==''?$w.' AND':' WHERE').' id>? ORDER BY id LIMIT 2000',[$last])->fetchAll(); if(!$rows) break;
            foreach($rows as $r){
                $seq++; $r['seq']=$seq; $r['created_at']=(string)$r['created_at'];
                $prev=audit_hash($prev,audit_fields($sid,$r)); query('UPDATE '.$t.' SET seq=?,chain=? WHERE id=?',[$seq,$prev,$r['id']]); $last=(int)$r['id'];
            }
        }
        query('UPDATE audit_heads SET seq=?,chain=? WHERE school_id=?',[$seq,$prev,$sid]);
        if($own) db()->commit();
    } catch(Throwable $ex){ if($own && db()->inTransaction()) db()->rollBack(); throw $ex; }
    return $seq;
}
function audit_rebuild_all(): int {
    $n=audit_rebuild(0);
    foreach(query('SELECT DISTINCT school_id FROM audit_logs')->fetchAll() as $r) $n+=audit_rebuild((int)$r['school_id']);
    return $n;
}
/** Semua rantai: platform + setiap sekolah. */
function audit_verify_all(): array {
    $out=[0=>audit_verify(0)+['code'=>'PLATFORM','name'=>'Log platform']];
    foreach(query('SELECT id,code,name FROM schools ORDER BY code')->fetchAll() as $s) $out[(int)$s['id']]=audit_verify((int)$s['id'])+['code'=>$s['code'],'name'=>$s['name']];
    return $out;
}
