<?php
declare(strict_types=1);
/*
 * Langganan sekolah — pakej Free & Pro (corak delima-multi).
 *  - Pro  : status aktif & expires_on >= hari ini (berbayar, percubaan atau tajaan) — semua ciri.
 *  - Free : Pro tamat & pakej Free dihidupkan platform — data kekal, had guru, jawatan & pengguna aktif (Tetapan Platform).
 *  - Tamat: Pro tamat & Free dimatikan — laman sekolah ditutup; pentadbir hanya boleh membuka Langganan & Profil.
 *  Status sekolah: emel (menunggu pengesahan e-mel pendaftaran) · menunggu (kelulusan platform) · aktif · digantung · diarkib.
 *  Kerja harian (subscription_daily): e-mel amaran tamat 30/7/0 hari, amaran pemadaman 30/7 hari, arkib data selepas tempoh.
 */
require_once __DIR__.'/payments.php';

function school_plan(?array $s=null): string {
    $s??=school(); if(!$s) return 'tiada';
    if(($s['status']??'')!=='aktif') return (string)$s['status'];
    if(!empty($s['expires_on']) && $s['expires_on']>=date('Y-m-d')) return 'pro';
    return pset('free_aktif')==='1'?'free':'tamat';
}
const PLAN_LABELS=['pro'=>'Pro','free'=>'Free','tamat'=>'Tamat','emel'=>'E-mel belum disahkan','menunggu'=>'Menunggu kelulusan','digantung'=>'Digantung','diarkib'=>'Diarkib','tiada'=>'—'];
function plan_badge(string $p): string { return '<span class="badge '.(['pro'=>'green','free'=>'','tamat'=>'warn','digantung'=>'warn'][$p]??'').'">'.e(PLAN_LABELS[$p]??$p).'</span>'; }
function school_days_left(array $s): ?int { if(empty($s['expires_on'])) return null; return (int)floor((strtotime($s['expires_on'].' 00:00:00')-strtotime(date('Y-m-d').' 00:00:00'))/86400); }

/* ---------- Had pakej Free ---------- */
/** Had pakej Free: kunci => [tetapan platform, label]. */
const FREE_LIMITS=['guru'=>['free_guru','guru'],'jawatan'=>['free_jawatan','jawatan'],'pengguna'=>['free_pengguna','pengguna aktif']];
function free_limit(string $what): int { return max(1,(int)pset(FREE_LIMITS[$what][0]??'free_pengguna')); }
function free_count(string $what,?int $school=null): int {
    $sid=$school??sid();
    if($what==='pengguna') return (int)query('SELECT COUNT(*) FROM users WHERE school_id=? AND active=1',[$sid])->fetchColumn();
    return (int)query('SELECT COUNT(*) FROM '.($what==='guru'?'teachers':'positions').' WHERE school_id=?',[$sid])->fetchColumn();
}
/** Halang penambahan guru/jawatan/pengguna aktif yang melebihi had pakej Free. $adding = bilangan yang akan ditambah. */
function free_guard(string $what,int $adding=1): void {
    if($adding<=0 || school_plan()!=='free') return;
    $limit=free_limit($what); $now=free_count($what);
    if($now+$adding>$limit) throw new DomainException('Had pakej Free: maksimum '.$limit.' '.(FREE_LIMITS[$what][1]??$what).' (kini '.$now.'). Naik taraf ke Pro di halaman Langganan.');
}
/** Ringkasan had Free, cth. "3/30 guru · 5/60 jawatan · 2/3 pengguna aktif". */
function free_summary(): array {
    $parts=[]; $over=false;
    foreach(FREE_LIMITS as $k=>[,$label]){ $n=free_count($k); $l=free_limit($k); $over=$over || $n>$l; $parts[]=$n.'/'.$l.' '.$label; }
    return [implode(' · ',$parts),$over];
}

/* ---------- Ciri Pro ---------- */
/** Ciri yang boleh dihadkan kepada Pro (Tetapan Platform → Pakej Free, cfg_pro_ciri). [label, keterangan, halaman yang dikunci] */
const PRO_FEATURES=[
    'auto'=>['Agihan automatik & Pusat Syarat','Cadangan agihan mengikut kapasiti, had & syarat jawatan',['auto','syarat']],
    'surat_pukal'=>['Surat pukal & PDF','Semua surat serentak dan muat turun PDF (surat individu masih boleh dicetak)',[]],
    'api'=>['API & integrasi','Kunci API dan sambungan sistem lain',['api']],
    'sejarah'=>['Sejarah versi semakan','Senarai revisi terdahulu di Semakan & Kelulusan',[]],
    'pukal'=>['Import pukal guru & jawatan','Tampal / CSV untuk banyak rekod serentak',['pukal']],
];
const PRO_FEATURES_DEFAULT='auto,surat_pukal,api,sejarah';
/** Ciri yang dikunci bagi pakej Free (pilihan platform). */
function pro_locked_features(): array { return array_values(array_intersect(array_filter(explode(',',pset('pro_ciri',PRO_FEATURES_DEFAULT))),array_keys(PRO_FEATURES))); }
/** Ciri tersedia untuk sekolah? Pro, percubaan & tajaan: semua ciri. Free: kecuali ciri yang dikunci platform. */
function feature_on(string $f,?array $s=null): bool { return school_plan($s)!=='free' || !in_array($f,pro_locked_features(),true); }
/** Ciri yang mengunci halaman enjin ini (atau null). */
function page_pro_feature(string $page): ?string { foreach(PRO_FEATURES as $k=>[,,$pages]) if(in_array($page,$pages,true) && !feature_on($k)) return $k; return null; }
/** Tolak tindakan ciri Pro bagi sekolah Free (EngineError → notis borang / kod API pro_required). */
function require_feature(string $f): void {
    if(!feature_on($f)) throw new EngineError(PRO_FEATURES[$f][0].' tersedia untuk pakej Pro sahaja. Naik taraf di halaman Langganan.',403,'pro_required');
}
/** Panel naik taraf (halaman yang dikunci untuk pakej Free). */
function pro_locked_panel(string $f): void {
    $admin=(current_user()['role']??'')==='admin';
    echo '<section class="panel padded empty pro-locked"><span class="badge warn">Pro</span><h3>'.e(PRO_FEATURES[$f][0]).'</h3><p>'.e(PRO_FEATURES[$f][1]).' tersedia untuk pakej Pro. Sekolah anda kini menggunakan pakej Free — data sedia ada kekal.</p>'
        .($admin?'<a class="btn primary" href="/?page=langganan">Naik taraf ke Pro →</a>':'<p class="muted">Hubungi pentadbir sekolah untuk naik taraf.</p>').'</section>';
}

/* ---------- Gerbang akses (index.php) ---------- */
/** Laman sekolah ditutup apabila langganan tamat tanpa pakej Free. Pentadbir masih boleh mengurus langganan. */
function subscription_gate(?array $user,string $page): void {
    if(school_plan()!=='tamat') return;
    $open=['login','verify','enroll','recovery','lupa','reset','sahkan','theme-css','logo','captcha','ralat'];
    if(in_array($page,$open,true) && !$user) return;
    if(!$user) error_page(503,'Langganan sekolah ini telah tamat. Hubungi pentadbir sekolah.',['title'=>'Langganan tamat','no_db'=>true]);
    if(($user['role']??'')!=='admin') error_page(403,'Langganan sekolah telah tamat. Pentadbir sekolah perlu memperbaharui langganan.');
    if(!in_array($page,['langganan','security','enroll','recovery','avatar'],true)) redirect('/?page=langganan');
}
/** Jalur amaran untuk pentadbir (setiap halaman). */
function subscription_banner(): string {
    if((current_user()['role']??'')!=='admin') return '';
    $s=school(); $p=school_plan($s); $link=' <a class="text-link" href="/?page=langganan">Langganan →</a>';
    if($p==='pro'){ $d=school_days_left($s); return $d!==null && $d<=30?'<div class="notice info" role="status">Langganan Pro tamat dalam <strong>'.$d.' hari</strong> ('.e(date('d/m/Y',strtotime($s['expires_on']))).').'.$link.'</div>':''; }
    if($p==='free'){ [$sum,$over]=free_summary();
        return '<div class="notice '.($over?'error':'info').'" role="status">Pakej Free: '.e($sum).'.'.$link.'</div>'; }
    if($p==='tamat') return '<div class="notice error" role="status">Langganan sekolah telah tamat. Laman sekolah ditutup sehingga langganan diperbaharui.'.$link.'</div>';
    return '';
}

/* ---------- Halaman Langganan (pentadbir sekolah) ---------- */
function langganan_page(): void {
    require_admin(); $s=school(); $p=school_plan($s); $d=school_days_left($s);
    ?><div class="page-heading"><div><span class="eyebrow teal">SEKOLAH</span><h1>Langganan</h1><p class="muted"><?= e($s['name']) ?> · <?= e($s['code']) ?></p></div></div>
    <section class="stats-grid"><article class="stat-card"><div class="stat-top"><small>Pakej</small></div><strong><?= e(PLAN_LABELS[$p]??$p) ?></strong></article>
    <article class="stat-card<?= $p!=='pro'?' alert':'' ?>"><div class="stat-top"><small><?= $p==='pro'?'Pro sah hingga':'Pro tamat pada' ?></small></div><strong><?= $s['expires_on']?e(date('d/m/Y',strtotime($s['expires_on']))):'—' ?></strong><small><?= $d!==null?($d>=0?$d.' hari lagi':abs($d).' hari lalu'):'' ?></small></article>
    <?php foreach(FREE_LIMITS as $k=>[,$label]): ?><article class="stat-card"><div class="stat-top"><small><?= e(ucfirst($label)) ?></small></div><strong><?= free_count($k) ?></strong><?php if($p==='free'): ?><small>Had Free: <?= free_limit($k) ?></small><?php endif; ?></article><?php endforeach; ?></section>
    <?php $locked=pro_locked_features(); if($locked): ?><section class="panel padded spaced"><h3>Ciri Pro</h3><ul class="pro-list"><?php foreach($locked as $k): ?><li><?= feature_on($k)?'<span class="badge green">Aktif</span>':'<span class="badge warn">Dikunci</span>' ?> <strong><?= e(PRO_FEATURES[$k][0]) ?></strong><small class="cell-sub"><?= e(PRO_FEATURES[$k][1]) ?></small></li><?php endforeach; ?></ul><?php if($p==='free'): ?><p class="muted">Ciri di atas dibuka semula serta-merta selepas naik taraf ke Pro.</p><?php endif; ?></section><?php endif; ?>
    <section class="panel padded spaced"><h3>Pakej</h3>
    <div class="dashboard-grid"><div><h4>Pro — RM<?= e(number_format((int)pset('harga_pro_sen')/100,2)) ?> setahun</h4><p class="muted">Semua ciri tanpa had guru, jawatan atau pengguna.</p></div>
    <?php if(pset('free_aktif')==='1'): ?><div><h4>Free — percuma</h4><p class="muted">Apabila Pro tamat: data kekal; had <?= e(implode(', ',array_map(fn($k)=>free_limit($k).' '.FREE_LIMITS[$k][1],array_keys(FREE_LIMITS)))) ?>. Data dipadam hanya jika tiada log masuk pentadbir selama <?= (int)pset('free_tidak_aktif_bulan') ?> bulan (dengan amaran e-mel).</p></div><?php else: ?><div><h4>Selepas tamat</h4><p class="muted">Laman sekolah ditutup.<?= (int)pset('padam_hari')>0?' Data dipadam '.(int)pset('padam_hari').' hari selepas tamat (amaran e-mel 30 &amp; 7 hari sebelum).':'' ?></p></div><?php endif; ?></div>
    <?php if(function_exists('payment_panel')): payment_panel(); else: ?><p class="notice info">Untuk memperbaharui atau naik taraf, hubungi pentadbir platform<?= pset('emel_notis')?' di <a class="text-link" href="mailto:'.e(pset('emel_notis')).'">'.e(pset('emel_notis')).'</a>':'' ?>.</p><?php endif; ?></section><?php
}

/* ---------- Kerja harian ---------- */
function school_notices(array $s): array { $j=json_decode((string)($s['notices']??''),true); return is_array($j)?$j:[]; }
function school_notice_mark(array $s,string $key,string $val): void { $n=school_notices($s); $n[$key]=$val; query('UPDATE schools SET notices=? WHERE id=?',[json_encode($n),$s['id']]); }
/** Penerima e-mel sekolah: pegawai (pendaftaran) + pentadbir aktif. */
function school_contacts(array $s): array {
    $out=[]; if(filter_var((string)($s['pic_email']??''),FILTER_VALIDATE_EMAIL)) $out[strtolower($s['pic_email'])]=(string)$s['pic_name'];
    foreach(query('SELECT name,email FROM users WHERE school_id=? AND role="admin" AND active=1',[$s['id']])->fetchAll() as $u) $out[strtolower($u['email'])]=$u['name'];
    return $out;
}
function school_mail(array $s,string $subject,string $text): int {
    require_once __DIR__.'/settings.php'; require_once __DIR__.'/mailer.php'; // kerja harian mungkin dijalankan tanpa modul ini dimuatkan
    $n=0; with_school($s,function() use($s,$subject,$text,&$n){ foreach(school_contacts($s) as $email=>$name){ $e=null; if(send_mail($email,$name,$subject,$text,$e)) $n++; else platform_log('mail_failed',(int)$s['id'],$subject.': '.(string)$e); } });
    return $n;
}
/** Tarikh data akan dipadam (null = tidak dijadualkan). */
function school_delete_on(array $s): ?string {
    $days=(int)pset('padam_hari'); if($days<=0 || ($s['status']??'')!=='aktif') return null;
    $p=school_plan($s);
    if($p==='tamat') return date('Y-m-d',strtotime($s['expires_on'].' +'.$days.' days'));
    if($p==='free'){
        $last=(string)query('SELECT MAX(last_login_at) FROM users WHERE school_id=? AND role="admin"',[$s['id']])->fetchColumn();
        $base=max(substr($last,0,10)?:'0000-00-00',(string)$s['expires_on']);
        return date('Y-m-d',strtotime($base.' +'.(int)pset('free_tidak_aktif_bulan').' months +'.$days.' days'));
    }
    return null;
}
/** Arkib: padam semua data sekolah, kekalkan rekod sekolah (& sejarah bayaran) dengan status diarkib. */
function school_archive(array $s,string $why): void {
    with_school($s,'school_wipe');
    query('UPDATE schools SET status="diarkib",archived_at=NOW() WHERE id=?',[$s['id']]); platform_log('school_archived',(int)$s['id'],$why);
}
function subscription_daily(bool $force=false): array {
    $today=date('Y-m-d'); $done=['emel'=>0,'arkib'=>0];
    if(!$force){ $last=(string)query('SELECT value FROM platform_settings WHERE name="run_subscription"')->fetchColumn(); if($last===$today) return $done; }
    query('INSERT INTO platform_settings(name,value) VALUES ("run_subscription",?) ON DUPLICATE KEY UPDATE value=VALUES(value)',[$today]);
    $base=fn(array $s)=>school_url($s['code'],'/?page=langganan');
    foreach(query('SELECT * FROM schools WHERE status="aktif" AND expires_on IS NOT NULL')->fetchAll() as $s){
        $d=school_days_left($s); $n=school_notices($s);
        // Satu e-mel bagi setiap peringkat (30 / 7 / tamat) untuk setiap tarikh tamat; tamat dimaklumkan hingga 7 hari selepasnya sahaja.
        $key=$d===null || $d<-7?null:($d<=0?'exp0':($d<=7?'exp7':($d<=30?'exp30':null)));
        if($key!==null && ($n[$key]??'')!==$s['expires_on']){
            $free=pset('free_aktif')==='1';
            $msg=$d>0?"Langganan Pro {$s['name']} akan tamat dalam $d hari (".date('d/m/Y',strtotime($s['expires_on'])).").":"Langganan Pro {$s['name']} telah tamat pada ".date('d/m/Y',strtotime($s['expires_on'])).'.';
            $msg.=$free?"\n\nSelepas tamat, sekolah beralih ke pakej Free: data kekal, had ".implode(', ',array_map(fn($k)=>free_limit($k).' '.FREE_LIMITS[$k][1],array_keys(FREE_LIMITS))).".":"\n\nSelepas tamat, laman sekolah ditutup sehingga langganan diperbaharui.";
            $done['emel']+=school_mail($s,$d>0?'Langganan Pro hampir tamat':'Langganan Pro telah tamat',$msg."\n\nPerbaharui di halaman Langganan:\n".$base($s));
            school_notice_mark($s,$key,(string)$s['expires_on']); $s=school_by_id((int)$s['id']); $n=school_notices($s);
        }
        $del=school_delete_on($s); if(!$del) continue;
        $left=(int)floor((strtotime($del)-strtotime($today))/86400);
        if($left<=0){ school_archive($s,'tempoh tamat/tidak aktif ('.$del.')'); $done['arkib']++; continue; }
        $key=$left<=7?'del7':($left<=30?'del30':null);
        if($key!==null && ($n[$key]??'')!==$del){
            $done['emel']+=school_mail($s,'Amaran: data sekolah akan dipadam',"Data {$s['name']} dalam ".platform_name()." akan dipadam pada ".date('d/m/Y',strtotime($del))." ($left hari lagi) kerana ".(school_plan($s)==='free'?'tiada log masuk pentadbir dalam tempoh yang ditetapkan':'langganan telah tamat').".\n\nLog masuk atau perbaharui langganan untuk mengelakkan pemadaman:\n".$base($s));
            school_notice_mark($s,$key,$del);
        }
    }
    // Enjin: pratonton auto-agihan lama (semua sekolah; sah 30 minit, disimpan 24 jam untuk jejak).
    $done['pratonton']=db()->exec('DELETE FROM allocation_plans WHERE created_at < NOW() - INTERVAL 1 DAY');
    $done+=payments_daily(); $done+=security_daily(); if(function_exists('support_daily')) $done+=support_daily();
    if(function_exists('registration_cleanup')) registration_cleanup();
    return $done;
}
