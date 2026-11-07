<?php
declare(strict_types=1);
/*
 * Pendaftaran sekolah awam (/daftar) — corak delima-multi:
 *  1. Borang (CAPTCHA wajib + perangkap bot): kod KPM, nama, jenis, negeri, pegawai (nama/e-mel/telefon), kata laluan Pentadbir Utama,
 *     persetujuan terma & polisi (direkod). Had: 5 / jam setiap IP, had harian pendaftaran disahkan (Tetapan Platform), domain e-mel dibenarkan.
 *  2. Status "emel": pautan pengesahan ke e-mel pegawai (48 jam; halaman pautan perlu tekan butang supaya pengimbas e-mel tidak mengesahkan).
 *     Jika e-mel platform belum dikonfigurasi, pengesahan dilangkau.
 *  3. Selepas disahkan: "menunggu" kelulusan platform (lalai) atau terus "aktif" (kelulusan automatik) dengan Pro percubaan.
 *  Laman sekolah 404 sebelum aktif. Pendaftaran tidak disahkan dibuang selepas 48 jam (kod dibebaskan); tempahan tidak disahkan > 1 jam
 *  boleh diambil alih oleh e-mel lain supaya kod KPM tidak boleh "diduduki".
 */
const NEGERI=['Johor','Kedah','Kelantan','Melaka','Negeri Sembilan','Pahang','Perak','Perlis','Pulau Pinang','Sabah','Sarawak','Selangor','Terengganu','W.P. Kuala Lumpur','W.P. Labuan','W.P. Putrajaya'];
const DAFTAR_SAH_JAM=48;

function registration_open(): bool { return pset('daftar_buka')==='1' && emergency_mode()===''; }
function registration_domains(): array { return array_values(array_filter(array_map('trim',explode(',',pset('daftar_domain'))))); }
function registration_domain_ok(string $email): bool {
    $dom=registration_domains(); if(!$dom) return true;
    $d=strtolower((string)substr((string)strrchr($email,'@'),1)); return in_array($d,$dom,true);
}
function registration_mail_ready(): bool { return pset('mail_from')!=='' && platform_domain()!==''; }
function registration_token(int $school): string {
    $t=bin2hex(random_bytes(24));
    query('INSERT INTO platform_settings(name,value) VALUES (?,?) ON DUPLICATE KEY UPDATE value=VALUES(value)',['daftar_'.substr(hash('sha256',$t),0,40),json_encode(['sid'=>$school,'exp'=>time()+DAFTAR_SAH_JAM*3600])]);
    return $t;
}
function registration_send_link(array $s): void {
    require_once __DIR__.'/mailer.php';
    $t=registration_token((int)$s['id']); $url=tenant_scheme().'://'.platform_domain().'/daftar/sah?t='.$t; $e=null;
    if(!send_mail((string)$s['pic_email'],(string)$s['pic_name'],'Sahkan pendaftaran sekolah',"Terima kasih kerana mendaftarkan {$s['name']} ({$s['code']}) di ".platform_name().".\n\nSahkan e-mel anda dalam ".DAFTAR_SAH_JAM." jam:\n$url\n\nAbaikan e-mel ini jika anda tidak membuat pendaftaran.",$e))
        platform_log('mail_failed',(int)$s['id'],'pengesahan pendaftaran: '.(string)$e);
}
/** Buang pendaftaran yang tidak disahkan selepas tempoh (kod dibebaskan). Dipanggil oleh kerja harian. */
function registration_cleanup(): int {
    $n=0; foreach(query('SELECT * FROM schools WHERE status="emel" AND created_at<DATE_SUB(NOW(),INTERVAL '.DAFTAR_SAH_JAM.' HOUR)')->fetchAll() as $s){ school_delete($s); platform_log('registration_expired',null,$s['code']); $n++; }
    query('DELETE FROM platform_settings WHERE name LIKE "daftar\\_%" AND updated_at<DATE_SUB(NOW(),INTERVAL 3 DAY)');
    return $n;
}
/** Sekolah aktif selepas kelulusan (manual atau automatik): Pro percubaan bermula sekarang. */
function registration_activate(array $s,string $by): void {
    require_once __DIR__.'/mailer.php';
    $exp=date('Y-m-d',strtotime('+'.(int)pset('percubaan_hari').' days'));
    query('UPDATE schools SET status="aktif",approved_at=NOW(),expires_on=? WHERE id=?',[$exp,$s['id']]); platform_log('school_approved',(int)$s['id'],$by);
    $e=null; send_mail((string)$s['pic_email'],(string)$s['pic_name'],'Pendaftaran sekolah diluluskan',"Pendaftaran {$s['name']} ({$s['code']}) di ".platform_name()." telah diluluskan.\n\nPro percubaan sah hingga ".date('d/m/Y',strtotime($exp)).". Log masuk sebagai Pentadbir Utama dengan e-mel ini:\n".school_url($s['code'],'/?page=login'),$e);
}

function registration_submit(): array {
    $ip=client_ip();
    form_guard('daftar'); // perangkap bot + CAPTCHA (sentiasa)
    $kIp=rate_key('daftar-ip',$ip); if(!rate_allowed($kIp,5)) throw new DomainException('Terlalu banyak pendaftaran dari rangkaian ini. Cuba lagi kemudian.');
    rate_hit($kIp);
    $verifiedToday=(int)query('SELECT COUNT(*) FROM platform_log WHERE action IN ("registration_verified","registration_received") AND created_at>DATE_SUB(NOW(),INTERVAL 1 DAY)')->fetchColumn();
    $pendingToday=(int)query('SELECT COUNT(*) FROM schools WHERE status="emel" AND created_at>DATE_SUB(NOW(),INTERVAL 1 DAY)')->fetchColumn();
    if($verifiedToday>=(int)pset('daftar_had_harian') || $pendingToday>=200) throw new DomainException('Pendaftaran baharu ditutup sementara (had harian dicapai). Cuba lagi esok.');
    $v=[]; foreach(['kod','nama','negeri','pic_nama','pic_emel','pic_telefon','jenis'] as $k) $v[$k]=trim(preg_replace('/[\x00-\x1F\x7F]/u','',input($k)));
    $v['kod']=strtoupper(preg_replace('/\s+/','',$v['kod'])); $v['pic_emel']=strtolower($v['pic_emel']); $v['pic_telefon']=preg_replace('/[^0-9+]/','',$v['pic_telefon']);
    if(!school_code_valid($v['kod'])) throw new DomainException('Kod sekolah mesti kod KPM: 3 huruf + 4 digit (cth. CEA1234).');
    if($v['nama']==='' || mb_strlen($v['nama'])>150) throw new DomainException('Nama sekolah diperlukan.');
    if(seo_has_link($v['nama'])) throw new DomainException('Nama sekolah tidak boleh mengandungi pautan atau alamat laman web.'); // dipaparkan di direktori awam (SEO poisoning)
    if(!in_array($v['negeri'],NEGERI,true)) throw new DomainException('Sila pilih negeri.');
    if($v['pic_nama']==='' || mb_strlen($v['pic_nama'])>120) throw new DomainException('Nama pegawai bertanggungjawab diperlukan.');
    if(strlen($v['pic_emel'])>190 || !filter_var($v['pic_emel'],FILTER_VALIDATE_EMAIL)) throw new DomainException('E-mel tidak sah.');
    if(!registration_domain_ok($v['pic_emel'])) throw new DomainException('Pendaftaran hanya dibenarkan menggunakan e-mel rasmi berdomain: '.implode(', ',registration_domains()).'.');
    if(strlen($v['pic_telefon'])<9 || strlen($v['pic_telefon'])>15) throw new DomainException('No. telefon tidak sah.');
    $pw=input('password'); if(!valid_password($pw) || !hash_equals($pw,input('password_confirm'))) throw new DomainException('Kata laluan mesti 12–128 aksara dan sepadan dengan pengesahan.');
    if(input('setuju')!=='1') throw new DomainException('Anda perlu bersetuju dengan Terma & Syarat dan Polisi Privasi.');
    $ex=school_find($v['kod']);
    if($ex && $ex['status']==='emel' && (strtotime((string)$ex['created_at'])<time()-DAFTAR_SAH_JAM*3600 || (strtotime((string)$ex['created_at'])<time()-3600 && !hash_equals(strtolower((string)$ex['pic_email']),$v['pic_emel'])))){ school_delete($ex); platform_log('registration_replaced',null,$ex['code']); $ex=null; }
    if($ex) throw new DomainException($ex['status']==='emel'?'Pendaftaran untuk kod sekolah ini sedang menunggu pengesahan e-mel. Semak peti masuk (dan folder Spam), atau hantar semula pautan di bawah.':'Kod sekolah ini sudah didaftarkan. Hubungi pentadbir platform jika ini satu kesilapan.');
    $mail=registration_mail_ready();
    [$s]=school_create($v['kod'],$v['nama'],$v['jenis']==='SK'?'SK':'SMK',$v['pic_nama'],$v['pic_emel'],$pw,['status'=>$mail?'emel':'menunggu','expires_on'=>null,'state'=>$v['negeri'],'pic_name'=>$v['pic_nama'],'pic_email'=>$v['pic_emel'],'pic_phone'=>$v['pic_telefon'],'must_change'=>false]);
    if(function_exists('consent_record')) consent_record((int)$s['id'],$v['pic_nama'],$v['pic_emel'],'pendaftaran');
    platform_log('registration_received',(int)$s['id'],$s['code'].' · '.$v['pic_emel']);
    if($mail){ registration_send_link($s); return ['emel',$s]; }
    registration_notify_platform($s); return ['menunggu',$s];
}
function registration_notify_platform(array $s): void {
    $to=pset('emel_notis'); if(!$to) return; require_once __DIR__.'/mailer.php'; $e=null;
    send_mail($to,'Pentadbir Platform','Pendaftaran sekolah baharu: '.$s['code'],"Pendaftaran baharu menunggu kelulusan:\n\n{$s['code']} · {$s['name']}\nPegawai: {$s['pic_name']} <{$s['pic_email']}>\n\n".tenant_scheme().'://'.platform_domain().'/platform/?p=sekolah&id='.(int)$s['id'],$e);
}
function registration_verify(string $t): string {
    if(!preg_match('/^[a-f0-9]{48}$/',$t)) return 'invalid';
    $k='daftar_'.substr(hash('sha256',$t),0,40); $j=json_decode((string)query('SELECT value FROM platform_settings WHERE name=?',[$k])->fetchColumn(),true);
    if(!is_array($j) || (int)($j['exp']??0)<time()) return 'invalid';
    $s=school_by_id((int)$j['sid']); if(!$s || $s['status']!=='emel') return 'invalid';
    if($_SERVER['REQUEST_METHOD']!=='POST') return 'confirm';
    if(query('DELETE FROM platform_settings WHERE name=?',[$k])->rowCount()!==1) return 'invalid';
    query('UPDATE schools SET status="menunggu" WHERE id=?',[$s['id']]); platform_log('registration_verified',(int)$s['id'],$s['code']);
    if(pset('daftar_kelulusan')==='auto'){ registration_activate(school_by_id((int)$s['id']),'automatik'); return 'aktif'; }
    registration_notify_platform($s); return 'menunggu';
}
function registration_resend(): void {
    $ip=client_ip(); $k=rate_key('daftar-semula',$ip); if(!rate_allowed($k,3)) return; rate_hit($k);
    $s=school_find(input('kod')); if(!$s || $s['status']!=='emel' || !hash_equals(strtolower((string)$s['pic_email']),strtolower(trim(input('emel'))))) return;
    $ks=rate_key('daftar-semula-sekolah',(string)$s['id']); if(!rate_allowed($ks,3)) return; rate_hit($ks);
    if(registration_mail_ready()) registration_send_link($s);
}

/* ---------- Halaman ---------- */
function registration_page(): never {
    // Laman demo dengan DAFTAR_URL: pendaftaran sebenar berlaku di laman utama, bukan pada pangkalan data demo yang direset setiap hari.
    if(($u=demo_daftar_url())!==''){ header('Location: '.$u,true,302); exit; }
    if(!registration_open()) error_page(404);
    $error=''; $done=null; $resent=false;
    if($_SERVER['REQUEST_METHOD']==='POST'){
        verify_csrf();
        try { if(input('act')==='semula'){ registration_resend(); $resent=true; } else $done=registration_submit(); }
        catch(DomainException $ex){ $error=$ex->getMessage(); }
    }
    $trial=(int)pset('percubaan_hari'); $v=fn($k)=>e($done?'':input($k)); $sk=!$done && input('jenis')==='SK';
    $err=fn(string $id,string $msg): string=>'<span class="lx-err" id="'.$id.'-e">'.icon('alert').e($msg).'</span>';
    $okm='<span class="lx-ok" aria-hidden="true">'.icon('check').'</span>';
    $host=(string)preg_replace('~^https?://~','',rtrim(school_url('KODSEKOLAH','/'),'/'));
    lp_head('Daftar Sekolah',false,['path'=>'/daftar','noindex'=>$_SERVER['REQUEST_METHOD']==='POST','desc'=>'Daftar sekolah anda di '.platform_name().': Pro percuma '.$trial.' hari untuk agihan tugas guru, agihan automatik, kelulusan dan surat lantikan.']); ?>
<div class="lx-wrap lx-reg">
<aside class="lx-aside" aria-label="Apa yang sekolah anda dapat"><span class="lx-blob" aria-hidden="true"></span>
<span class="lx-tag lx-c3">Daftar sekolah</span>
<h1 class="lx-h lx-h3"><?= $trial>0?'Mula agih tugas guru dengan Pro percuma '.$trial.' hari':'Mula agih tugas guru di '.e(platform_name()) ?></h1>
<p class="lx-lead lx-b3">Satu pendaftaran bagi setiap sekolah, menggunakan kod KPM.<?= pset('free_aktif')==='1'?' Selepas tempoh percubaan, sekolah kekal di Free tanpa kehilangan data.':'' ?></p>
<ul class="lx-perks lx-b6"><li><span class="lx-ck"><?= icon('check') ?></span><span>Agihan automatik, Pusat Syarat dan surat pukal PDF sepanjang percubaan</span></li><li><span class="lx-ck"><?= icon('check') ?></span><span>Tiada kad atau bayaran diperlukan untuk mendaftar</span></li><li><span class="lx-ck"><?= icon('check') ?></span><span>Data sekolah diasingkan dan disulitkan dengan kunci sendiri</span></li></ul>
<div class="lx-after"><span class="lx-c3">Selepas anda tekan daftar</span><ol class="lx-b6">
<li><span class="lx-n">1</span><div><span class="lx-t4">Sahkan e-mel</span><span class="lx-c5 lx-sub">Tekan pautan dalam e-mel dalam masa <?= DAFTAR_SAH_JAM ?> jam</span></div></li>
<li><span class="lx-n">2</span><div><span class="lx-t4">Kelulusan platform</span><span class="lx-c5 lx-sub"><?= pset('daftar_kelulusan')==='auto'?'Sekolah diaktifkan secara automatik selepas e-mel disahkan':'Pentadbir platform menyemak pendaftaran anda' ?></span></div></li>
<li><span class="lx-n">3</span><div><span class="lx-t4">Log masuk sebagai Pentadbir Utama</span><span class="lx-c5 lx-sub">Tambah guru, jawatan dan pengguna sekolah</span></div></li></ol></div>
<div class="lx-peek" aria-hidden="true"><div class="lx-crop crop-peek"><img src="<?= e(lp_shot('dashboard')) ?>" alt="" loading="lazy" width="1600" height="1000"></div></div>
</aside>

<div>
<section class="lx-card" aria-labelledby="lx-form-title">
<?php if($done): [$st,$s]=$done; ?>
<div class="lx-done" role="status"><span class="lx-mail<?= $st==='emel'?'':' is-wait' ?>"><?= icon('mail') ?></span>
<h2 class="lx-h lx-h4" id="lx-form-title"><?= $st==='emel'?'Semak e-mel anda':'Pendaftaran diterima' ?></h2>
<p class="lx-b3 lx-muted"><?= $st==='emel'?'Pautan pengesahan telah dihantar ke <b>'.e($s['pic_email']).'</b>. Tekan pautan itu dalam masa '.DAFTAR_SAH_JAM.' jam, dan semak juga folder Spam.':'Pendaftaran menunggu kelulusan pentadbir platform. Anda akan dimaklumkan melalui e-mel <b>'.e($s['pic_email']).'</b>.' ?></p>
<ol class="lx-next"><li><span class="lx-n"><?= icon('check') ?></span><span>Pendaftaran <b><?= e($s['code']) ?></b> · <?= e($s['name']) ?> diterima</span></li>
<?php if($st==='emel'): ?><li><span class="lx-n">2</span><span>Sahkan e-mel melalui pautan</span></li><?php endif; ?>
<li><span class="lx-n"><?= $st==='emel'?3:2 ?></span><span>Pentadbir platform meluluskan, dan anda dimaklumkan melalui e-mel</span></li></ol>
<a class="lx-btn lx-btn-ghost" href="/">Kembali ke halaman utama</a></div>
<?php else: ?>
<div class="lx-card-head"><h2 class="lx-h lx-h6" id="lx-form-title">Butiran pendaftaran</h2><p class="lx-b6 lx-muted">Semua medan wajib diisi. Ambil masa kira-kira 3 minit.</p></div>
<nav class="lx-progress" aria-label="Kemajuan borang"><a href="#lx-f1" id="lx-pg1" class="lx-g1"><span class="lx-bar"><i></i></span><span class="lx-lbl"><?= icon('check') ?>Sekolah</span></a><a href="#lx-f2" id="lx-pg2" class="lx-g2"><span class="lx-bar"><i></i></span><span class="lx-lbl"><?= icon('check') ?>Pegawai</span></a><a href="#lx-f3" id="lx-pg3" class="lx-g3"><span class="lx-bar"><i></i></span><span class="lx-lbl"><?= icon('check') ?>Akaun</span></a></nav>
<?php if($error!==''): ?><div class="lx-alert is-error" role="alert"><?= icon('alert') ?><span><?= e($error) ?></span></div><?php endif; ?>
<?php if($resent): ?><div class="lx-alert is-ok" role="status"><?= icon('check') ?><span>Jika pendaftaran itu masih menunggu pengesahan, pautan baharu telah dihantar ke e-mel pegawai.</span></div><?php endif; ?>
<div class="lx-alert is-error" id="lx-form-err" role="alert" hidden><?= icon('alert') ?><span>Ada medan yang perlu dibetulkan. Semak medan bertanda merah.</span></div>
<form method="post" class="lx-form" id="lx-daftar" novalidate><?= csrf_field() ?><input type="hidden" name="act" value="daftar">
<fieldset id="lx-f1" class="lx-g1" data-pg="lx-pg1"><legend><span class="lx-ic"><?= icon('school') ?></span>Maklumat sekolah</legend>
<div class="lx-field"><span class="lx-lbl" id="lx-jenis-lb">Jenis sekolah</span><div class="lx-choice" role="radiogroup" aria-labelledby="lx-jenis-lb">
<label class="lx-g1"><input type="radio" name="jenis" value="SMK"<?= $sk?'':' checked' ?>><span class="lx-em"><?= icon('building') ?></span><span class="lx-t"><b>Sekolah Menengah</b><span class="lx-c5 lx-muted">Pelulus: Pengetua</span></span><span class="lx-dot"></span></label>
<label class="lx-g2"><input type="radio" name="jenis" value="SK"<?= $sk?' checked':'' ?>><span class="lx-em"><?= icon('book') ?></span><span class="lx-t"><b>Sekolah Rendah</b><span class="lx-c5 lx-muted">Pelulus: Guru Besar</span></span><span class="lx-dot"></span></label></div></div>
<div class="lx-grid2">
<div class="lx-field" data-f="kod"><label for="lx-kod">Kod sekolah (KPM)</label><div class="lx-ctrl"><input id="lx-kod" name="kod" value="<?= $v('kod') ?>" maxlength="7" pattern="[A-Za-z]{3}[0-9]{4}" placeholder="CEA1234" autocomplete="off" autocapitalize="characters" spellcheck="false" required aria-describedby="lx-kod-h kod-e"><?= $okm ?></div><span class="lx-hint" id="lx-kod-h">3 huruf diikuti 4 digit</span><?= $err('kod','Kod mesti 3 huruf dan 4 digit, cth. CEA1234.') ?></div>
<div class="lx-field" data-f="negeri"><label for="lx-negeri">Negeri</label><div class="lx-ctrl"><select id="lx-negeri" name="negeri" required aria-describedby="negeri-e"><option value="">Pilih negeri</option><?php foreach(NEGERI as $n): ?><option<?= !$done && input('negeri')===$n?' selected':'' ?>><?= e($n) ?></option><?php endforeach; ?></select><?= icon('chev-r','lx-chev') ?></div><?= $err('negeri','Sila pilih negeri.') ?></div>
</div>
<span class="lx-url" id="lx-url" hidden><?= icon('globe') ?>Alamat sekolah anda: <?= e(preg_replace('~KODSEKOLAH$~','',$host)) ?><b id="lx-url-kod"></b></span>
<div class="lx-field" data-f="nama"><label for="lx-nama">Nama sekolah</label><div class="lx-ctrl"><input id="lx-nama" name="nama" value="<?= $v('nama') ?>" maxlength="150" placeholder="cth. SMK Seri Jaya" autocomplete="organization" required aria-describedby="lx-nama-h nama-e"><?= $okm ?></div><span class="lx-hint" id="lx-nama-h">Dipaparkan di direktori sekolah dan surat lantikan</span><?= $err('nama','Nama sekolah diperlukan, tanpa pautan laman web.') ?></div>
</fieldset>
<hr class="lx-sep">
<fieldset id="lx-f2" class="lx-g2" data-pg="lx-pg2"><legend><span class="lx-ic"><?= icon('user') ?></span>Pegawai bertanggungjawab</legend>
<div class="lx-field" data-f="pic_nama"><label for="lx-pic-nama">Nama pegawai <span class="lx-opt">akan menjadi Pentadbir Utama</span></label><div class="lx-ctrl"><input id="lx-pic-nama" name="pic_nama" value="<?= $v('pic_nama') ?>" maxlength="120" autocomplete="name" required aria-describedby="pic_nama-e"><?= $okm ?></div><?= $err('pic_nama','Nama pegawai diperlukan.') ?></div>
<div class="lx-grid2">
<div class="lx-field" data-f="pic_emel"><label for="lx-pic-emel">E-mel pegawai<?= registration_domains()?' <span class="lx-opt">'.e(implode(', ',registration_domains())).'</span>':'' ?></label><div class="lx-ctrl"><input id="lx-pic-emel" name="pic_emel" type="email" value="<?= $v('pic_emel') ?>" maxlength="190" placeholder="nama@sekolah.edu.my" autocomplete="email" required aria-describedby="lx-emel-h pic_emel-e"><?= $okm ?></div><span class="lx-hint" id="lx-emel-h">Pautan pengesahan dihantar ke sini</span><?= $err('pic_emel','Masukkan e-mel yang sah, cth. nama@sekolah.edu.my.') ?></div>
<div class="lx-field" data-f="pic_telefon"><label for="lx-pic-tel">No. telefon</label><div class="lx-ctrl"><input id="lx-pic-tel" name="pic_telefon" value="<?= $v('pic_telefon') ?>" maxlength="20" inputmode="tel" placeholder="012 345 6789" autocomplete="tel" required aria-describedby="pic_telefon-e"><?= $okm ?></div><?= $err('pic_telefon','No. telefon mesti 9 hingga 15 digit.') ?></div>
</div></fieldset>
<hr class="lx-sep">
<fieldset id="lx-f3" class="lx-g3" data-pg="lx-pg3"><legend><span class="lx-ic"><?= icon('lock') ?></span>Akaun Pentadbir Utama</legend>
<div class="lx-grid2">
<div class="lx-field" data-f="password"><label for="lx-pw">Kata laluan</label><div class="lx-ctrl is-pw"><input id="lx-pw" name="password" type="password" minlength="12" maxlength="128" required autocomplete="new-password" aria-describedby="lx-pw-rules password-e"><button class="lx-tog" type="button" aria-label="Tunjuk kata laluan" aria-pressed="false" data-for="lx-pw"><?= icon('eye') ?></button></div><div class="lx-meter" id="lx-meter" data-s="0" aria-hidden="true"><i></i><i></i><i></i><i></i></div><?= $err('password','Kata laluan mesti 12 hingga 128 aksara.') ?></div>
<div class="lx-field" data-f="password_confirm"><label for="lx-pw2">Sahkan kata laluan</label><div class="lx-ctrl is-pw"><input id="lx-pw2" name="password_confirm" type="password" maxlength="128" required autocomplete="new-password" aria-describedby="password_confirm-e"><button class="lx-tog" type="button" aria-label="Tunjuk kata laluan" aria-pressed="false" data-for="lx-pw2"><?= icon('eye') ?></button></div><?= $err('password_confirm','Kata laluan tidak sepadan.') ?></div>
</div>
<ul class="lx-rules" id="lx-pw-rules" aria-label="Syarat kata laluan"><li data-r="len"><?= icon('check') ?>12 aksara atau lebih</li><li data-r="case"><?= icon('check') ?>Huruf besar dan kecil</li><li data-r="num"><?= icon('check') ?>Nombor</li><li data-r="sym"><?= icon('check') ?>Simbol</li></ul>
<div class="lx-field" data-f="setuju"><label class="lx-agree" for="lx-setuju"><input type="checkbox" id="lx-setuju" name="setuju" value="1" required><span class="lx-box"><?= icon('check') ?></span><span>Saya bersetuju dengan <a href="/terma" target="_blank">Terma &amp; Syarat</a> dan <a href="/polisi" target="_blank">Polisi Privasi</a>, dan mengesahkan saya diberi kuasa mendaftarkan sekolah ini.</span></label><?= $err('setuju','Anda perlu bersetuju sebelum mendaftar.') ?></div>
<?= captcha_field('daftar') ?>
</fieldset>
<div class="lx-submit"><button class="lx-btn lx-btn-primary lx-btn-lg" type="submit" id="lx-go"><span class="lx-spin" aria-hidden="true"></span><span class="lx-go-tx">Daftar sekolah</span><?= icon('arrow','lx-go') ?></button>
<span class="lx-c5 lx-faint">Ruang sekolah diaktifkan selepas diluluskan<?= $trial>0?', bermula dengan Pro percuma '.$trial.' hari':'' ?>.</span></div>
</form>
<?php endif; ?>
</section>
<?php if(!$done): ?><details class="lx-resend"<?= $resent?' open':'' ?>><summary>Tidak menerima e-mel pengesahan?<?= icon('plus') ?></summary>
<form method="post"><?= csrf_field() ?><input type="hidden" name="act" value="semula"><p class="lx-b6 lx-muted">Masukkan kod sekolah dan e-mel pegawai yang digunakan semasa mendaftar. Pautan baharu dihantar jika pendaftaran masih menunggu pengesahan.</p>
<div class="lx-rrow"><label>Kod sekolah<input id="lx-r-kod" name="kod" maxlength="7" placeholder="CEA1234" autocapitalize="characters" required></label><label>E-mel pegawai<input name="emel" type="email" placeholder="nama@sekolah.edu.my" required></label><button class="lx-btn lx-btn-ghost">Hantar semula pautan</button></div></form></details><?php endif; ?>
</div>
</div><?php lp_foot(); exit;
}
function registration_verify_page(): never {
    $error='';
    if($_SERVER['REQUEST_METHOD']==='POST') verify_csrf();
    $r=registration_verify(input('t','get'));
    lp_head('Sahkan Pendaftaran'); ?>
<div class="main-content platform-dir"><section class="panel padded"><h1>Pengesahan pendaftaran</h1>
<?php if($r==='confirm'): ?><p>Tekan butang di bawah untuk mengesahkan e-mel pendaftaran sekolah.</p><form method="post"><?= csrf_field() ?><button class="btn primary">Sahkan e-mel saya</button></form>
<?php elseif($r==='menunggu'): ?><p class="notice success">E-mel disahkan. Pendaftaran kini menunggu kelulusan pentadbir platform; anda akan dimaklumkan melalui e-mel.</p>
<?php elseif($r==='aktif'): ?><p class="notice success">E-mel disahkan dan sekolah anda telah diaktifkan. Butiran log masuk telah dihantar ke e-mel anda.</p>
<?php else: ?><p class="notice error">Pautan tidak sah, telah digunakan atau tamat tempoh. Daftar semula atau hantar semula pautan di halaman pendaftaran.</p><?php endif; ?>
<p><a class="text-link" href="/direktori">← Direktori sekolah</a></p></section></div><?php lp_foot(); exit;
}
