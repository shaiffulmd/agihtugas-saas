<?php
declare(strict_types=1);
/* Paparan & tindakan keselamatan: Panel Platform (Keselamatan, Perlindungan IP, Rantai Audit, Laporan Keselamatan)
 * dan sekolah (?page=ipsekat: sekatan IP sekolah & IP pentadbir; had IP setiap pengguna di Pengguna & Audit). */

/* ======================= Platform ======================= */
function handle_security_platform(string $action,array $me): never {
    $by=(string)$me['name'];
    if($action==='p_sec_cap_save'){
        $p=input('cap_penyedia'); if(!isset(CAP_PROVIDERS[$p])) throw new DomainException('Penyedia CAPTCHA tidak sah.');
        $kv=['cap_penyedia'=>$p,'cap_gagal'=>input('cap_gagal')==='buka'?'buka':'tutup'];
        if(($k=CAP_PROVIDERS[$p]['key'])!==''){
            $site=trim(input($k.'_site')); $sec=trim(input($k.'_secret'));
            if($site!=='' && !preg_match('/^[0-9A-Za-z_\-.]{10,100}$/',$site)) throw new DomainException('Site key tidak sah.');
            if($sec!=='' && !preg_match('/^[0-9A-Za-z_\-.]{10,100}$/',$sec)) throw new DomainException('Secret key tidak sah.');
            $kv[$k.'_site']=$site; if($sec!=='') $kv[$k.'_secret']=pencrypt($sec);
        }
        $was=cap_external(); pset_set($kv);
        $want=input('cap_luar')==='1';
        if($want && $p!=='sendiri' && !hash_equals(cap_fingerprint($p),pset('cap_lulus'))){ pset_set(['cap_luar'=>'0']); platform_log('captcha_saved',null,CAP_PROVIDERS[$p]['name'].' (belum diuji)'); flash('Tetapan disimpan. Lulus ujian CAPTCHA di bawah dahulu, kemudian aktifkan (elak terkunci keluar).'); redirect('/platform/?p=keselamatan#captcha'); }
        pset_set(['cap_luar'=>$want && $p!=='sendiri'?'1':'0']);
        platform_log('captcha_saved',null,CAP_PROVIDERS[$p]['name'].', '.(cap_external()?'AKTIF':'tidak aktif').($was!==cap_external()?' (berubah)':''));
        flash('Tetapan CAPTCHA disimpan.'.(cap_external()?' '.CAP_PROVIDERS[$p]['name'].' kini digunakan pada semua borang.':'')); redirect('/platform/?p=keselamatan#captcha');
    }
    if($action==='p_sec_cap_test'){
        $p=cap_provider(); if($p==='sendiri' || !cap_ready($p)) throw new DomainException('Pilih penyedia dan isi Site key & Secret key dahulu.');
        if(!cap_verify($p,input(CAP_PROVIDERS[$p]['field']))){ platform_log('captcha_test_failed',null,CAP_PROVIDERS[$p]['name']); throw new DomainException('Ujian CAPTCHA gagal — semak Site key / Secret key dan domain yang didaftarkan pada penyedia.'); }
        pset_set(['cap_lulus'=>cap_fingerprint($p)]); platform_log('captcha_test_ok',null,CAP_PROVIDERS[$p]['name']);
        flash('Ujian '.CAP_PROVIDERS[$p]['name'].' lulus. Anda kini boleh mengaktifkannya.'); redirect('/platform/?p=keselamatan#captcha');
    }
    if($action==='p_sec_2fa'){
        $on=input('wajib_2fa')==='1'; if($on && !$me['totp_secret']) throw new DomainException('Aktifkan 2FA untuk akaun anda sendiri dahulu.');
        if((pset('wajib_2fa')==='1')!==$on) platform_log('twofa_forced_all',null,$on?'HIDUP':'mati');
        pset_set(['wajib_2fa'=>$on?'1':'0']); flash($on?'2FA kini wajib untuk semua pengguna semua sekolah (didaftarkan pada log masuk seterusnya).':'2FA kembali mengikut dasar setiap sekolah.'); redirect('/platform/?p=keselamatan#twofa');
    }
    if($action==='p_sec_emergency'){
        platform_confirm_password();
        $m=input('kecemasan'); if(!isset(EMERGENCY_MODES[$m])) throw new DomainException('Mod tidak sah.');
        $msg=mb_substr(trim(input('kecemasan_mesej')),0,300); pset_set(['kecemasan'=>$m,'kecemasan_mesej'=>$msg]);
        platform_log('emergency_mode',null,($m?:'normal').($msg!==''?': '.$msg:''));
        flash($m===''?'Mod kecemasan dimatikan; semua laman sekolah kembali normal.':'Mod kecemasan: '.EMERGENCY_MODES[$m].'. Matikan di sini, atau melalui CLI: php bin/security.php emergency-off'); redirect('/platform/?p=keselamatan#kecemasan');
    }
    if($action==='p_sec_contact'){
        $em=strtolower(trim(input('emel_keselamatan'))); if($em!=='' && !filter_var($em,FILTER_VALIDATE_EMAIL)) throw new DomainException('E-mel keselamatan tidak sah.');
        pset_set(['emel_keselamatan'=>$em]); platform_log('security_contact',null,$em?:'(emel notis)'); flash('E-mel keselamatan disimpan.'); redirect('/platform/?p=keselamatan#lapor');
    }
    if($action==='p_sec_ip_settings'){
        [$list,$err]=ip_list_form(input('ip_platform')); if($err) throw new DomainException($err);
        $req=input('ip_platform_wajib')==='1';
        if($req && ($list==='' || !ip_in_list(client_ip(),ip_list($list)))) throw new DomainException('Untuk mewajibkan senarai IP panel platform, senarai mesti mengandungi IP anda sekarang ('.client_ip().') — elak terkunci keluar.');
        $min=(int)input('ip_auto_minit'); if($min<5 || $min>43200) throw new DomainException('Tempoh sekatan automatik 5–43200 minit.');
        pset_set(['ip_lindung'=>input('ip_lindung')==='1'?'1':'0','ip_auto'=>input('ip_auto')==='1'?'1':'0','ip_auto_minit'=>(string)$min,'ip_auto_emel'=>input('ip_auto_emel')==='1'?'1':'0','ip_platform'=>$list,'ip_platform_wajib'=>$req?'1':'0']);
        platform_log('ip_settings',null,'lindung '.pset('ip_lindung').', auto '.pset('ip_auto').', panel wajib senarai '.pset('ip_platform_wajib')); flash('Tetapan Perlindungan IP disimpan.'); redirect('/platform/?p=ipsekat');
    }
    if($action==='p_sec_ip_add'){
        $ip=input('ip'); if(!ip_canon($ip)) throw new DomainException('IP atau CIDR tidak sah (julat lebih luas daripada /8 IPv4 atau /16 IPv6 tidak dibenarkan).');
        if(ip_in_cidr(client_ip(),(string)ip_canon($ip)) && input('saya')!=='1') throw new DomainException('IP ini meliputi alamat anda sendiri ('.client_ip().'). Tandakan pengesahan jika benar-benar mahu menyekatnya.');
        $school=null; $code=strtoupper(trim(input('kod')));
        if($code!==''){ $s=school_find($code); if(!$s) throw new DomainException('Sekolah '.$code.' tidak dijumpai.'); $school=(int)$s['id']; }
        $d=input('tempoh'); if(!isset(IP_DURATIONS[$d])) throw new DomainException('Tempoh tidak sah.');
        $id=ip_block_add($school,$ip,input('skop'),(int)$d,mb_substr(trim(input('sebab')),0,200),$by); if(!$id) throw new DomainException('Sekatan tidak sah.');
        platform_log('ip_block',$school,ip_canon($ip).' ('.input('skop').', '.IP_DURATIONS[$d].')');
        $r=query('SELECT cf_id,cf_error FROM ip_blocks WHERE id=?',[$id])->fetch();
        flash('IP '.ip_canon($ip).' disekat.'.($r['cf_id']?' Disegerak ke Cloudflare.':($r['cf_error']!==''?' Cloudflare: '.$r['cf_error']:''))); redirect('/platform/?p=ipsekat');
    }
    if($action==='p_sec_ip_remove'){ $r=query('SELECT ip,school_id FROM ip_blocks WHERE id=?',[(int)input('id')])->fetch(); if($r && ip_block_remove((int)input('id'))) platform_log('ip_unblock',$r['school_id']!==null?(int)$r['school_id']:null,$r['ip']); flash('Sekatan dibuang.'); redirect('/platform/?p=ipsekat'); }
    if($action==='p_sec_cf_save'){
        $zone=trim(input('cf_zone')); if($zone!=='' && !preg_match('/^[a-f0-9]{32}$/',$zone)) throw new DomainException('Zone ID Cloudflare mesti 32 aksara heks.');
        $kv=['cf_aktif'=>input('cf_aktif')==='1'?'1':'0','cf_zone'=>$zone,'cf_auto'=>input('cf_auto')==='1'?'1':'0']; if(trim(input('cf_token'))!=='') $kv['cf_token']=pencrypt(trim(input('cf_token')));
        if($kv['cf_aktif']==='1' && ($zone==='' || (($kv['cf_token']??pset('cf_token'))===''))) throw new DomainException('Isi Zone ID dan token API untuk mengaktifkan Cloudflare.');
        pset_set($kv); platform_log('cloudflare_saved',null,'aktif '.$kv['cf_aktif']); flash('Tetapan Cloudflare disimpan.'); redirect('/platform/?p=ipsekat#cloudflare');
    }
    if($action==='p_sec_cf_test'){ [$ok,,$err]=cf_test(); platform_log($ok?'cloudflare_test_ok':'cloudflare_test_failed',null,$err); if(!$ok) throw new DomainException('Sambungan Cloudflare gagal: '.$err); flash('Sambungan Cloudflare berjaya.'); redirect('/platform/?p=ipsekat#cloudflare'); }
    if($action==='p_sec_cf_sync'){
        if(!cf_on()) throw new DomainException('Cloudflare tidak aktif.');
        $n=0; foreach(query('SELECT * FROM ip_blocks WHERE school_id IS NULL AND cf_id IS NULL AND (expires_at IS NULL OR expires_at>NOW()) ORDER BY id LIMIT 40')->fetchAll() as $r){ cf_sync_add($r); $n++; }
        flash($n.' sekatan dihantar ke Cloudflare.'); redirect('/platform/?p=ipsekat#cloudflare');
    }
    if($action==='p_sec_audit_csv'){
        $code=strtoupper(trim(input('kod'))); $sid=0; if($code!=='' && $code!=='PLATFORM'){ $s=school_find($code); if(!$s) throw new DomainException('Sekolah tidak dijumpai.'); $sid=(int)$s['id']; }
        $from=input('dari')?:'2000-01-01'; $to=input('hingga')?:date('Y-m-d');
        foreach([$from,$to] as $d) if(!($x=DateTimeImmutable::createFromFormat('!Y-m-d',$d)) || $x->format('Y-m-d')!==$d) throw new DomainException('Tarikh tidak sah.');
        platform_log('audit_export',$sid?:null,($sid?$code:'platform')." $from–$to"); audit_csv($sid,$from,$to,$sid?$code:'platform');
    }
    if($action==='p_sec_audit_rebuild'){
        platform_confirm_password(); $code=strtoupper(trim(input('kod')));
        $sid=$code==='PLATFORM'?0:(int)(school_find($code)['id']??-1); if($sid<0) throw new DomainException('Sekolah tidak dijumpai.');
        $before=audit_verify($sid); $n=audit_rebuild($sid);
        platform_log('audit_rebuilt',$sid?:null,($sid?$code:'platform').': '.$n.' baris ditandatangani semula; masalah sebelum: '.count($before['problems']));
        flash('Rantai '.($sid?$code:'platform').' ditandatangani semula ('.$n.' baris). Tindakan ini dilog.'); redirect('/platform/?p=audit');
    }
    if($action==='p_sec_report'){
        $st=input('status'); if(!in_array($st,['baru','disemak','selesai','ditolak'],true)) throw new DomainException('Status tidak sah.');
        query('UPDATE security_reports SET status=?,note=? WHERE id=?',[$st,mb_substr(trim(input('catatan')),0,500),(int)input('id')]); platform_log('security_report_status',null,'#'.(int)input('id').' → '.$st);
        flash('Laporan #'.(int)input('id').' dikemas kini.'); redirect('/platform/?p=laporan&id='.(int)input('id'));
    }
    error_page(400);
}

/** Eksport CSV log (berantai) dengan lajur seq & chain untuk semakan luar. */
function audit_csv(int $sid,string $from,string $to,string $name): never {
    $rows=$sid>0?query('SELECT a.seq,a.created_at,u.email who,a.via_ref,a.action,a.entity,a.entity_id,a.ip_hash,a.chain FROM audit_logs a LEFT JOIN users u ON u.id=a.user_id WHERE a.school_id=? AND a.created_at>=? AND a.created_at<DATE_ADD(?,INTERVAL 1 DAY) ORDER BY a.seq',[$sid,$from,$to])->fetchAll()
                :query('SELECT l.seq,l.created_at,COALESCE(p.email,CONCAT("#",l.admin_ref)) who,s.code school,l.action,l.detail,l.ip_hash,l.chain FROM platform_log l LEFT JOIN platform_admins p ON p.id=l.admin_ref LEFT JOIN schools s ON s.id=l.school_ref WHERE l.created_at>=? AND l.created_at<DATE_ADD(?,INTERVAL 1 DAY) ORDER BY l.seq',[$from,$to])->fetchAll();
    while(ob_get_level()>0) @ob_end_clean();
    header('Content-Type: text/csv; charset=utf-8'); header('Content-Disposition: attachment; filename="audit-'.preg_replace('/[^A-Za-z0-9]/','',$name).'-'.$from.'-'.$to.'.csv"');
    $f=fopen('php://output','w'); fwrite($f,"\xEF\xBB\xBF"); fputcsv($f,$sid>0?['Seq','Masa','Pengguna','Melalui platform (#)','Tindakan','Entiti','ID','Hash IP','Rantai']:['Seq','Masa','Pentadbir','Sekolah','Tindakan','Butiran','Hash IP','Rantai'],',','"','');
    foreach($rows as $r) fputcsv($f,array_map(fn($v)=>preg_match('/^[=+\-@]/',(string)$v)?"'".$v:$v,array_values($r)),',','"','');
    fclose($f); exit;
}

function platform_page_security(array $me): void {
    $p=cap_provider(); $chk=fn(bool $b)=>$b?' checked':'';
    $no2fa=query('SELECT s.code,s.name,COUNT(*) n FROM users u JOIN schools s ON s.id=u.school_id WHERE u.active=1 AND u.totp_secret IS NULL AND s.status="aktif" GROUP BY s.id ORDER BY n DESC LIMIT 20')->fetchAll(); ?>
<div class="page-heading"><div><span class="eyebrow teal">PLATFORM</span><h1>Keselamatan</h1><p class="muted">CAPTCHA, dasar 2FA, mod kecemasan dan saluran laporan keselamatan. Lihat juga <a class="text-link" href="/platform/?p=ipsekat">Perlindungan IP</a> dan <a class="text-link" href="/platform/?p=audit">Rantai Audit</a>.</p></div></div>
<section class="panel padded spaced" id="captcha"><h3>CAPTCHA</h3><p class="muted">Digunakan pada log masuk (selepas cubaan gagal), daftar, lupa kata laluan, pendaftaran sekolah dan laporan keselamatan. Lalai: CAPTCHA sendiri (tiada pihak ketiga). Penyedia luar diaktifkan hanya selepas lulus ujian dengan kunci semasa. Status: <strong><?= cap_external()?e(CAP_PROVIDERS[$p]['name']).' AKTIF':'CAPTCHA sendiri' ?></strong></p>
<form method="post" class="form-grid" autocomplete="off"><?= csrf_field() ?><input type="hidden" name="action" value="p_sec_cap_save">
<label class="field">Penyedia<select name="cap_penyedia"><?php foreach(CAP_PROVIDERS as $k=>$c): ?><option value="<?= $k ?>"<?= $k===$p?' selected':'' ?>><?= e($c['name']) ?></option><?php endforeach; ?></select></label>
<label class="field">Jika penyedia tidak dapat dihubungi<select name="cap_gagal"><option value="tutup">Tolak (lebih selamat)</option><option value="buka"<?= pset('cap_gagal')==='buka'?' selected':'' ?>>Benarkan</option></select></label>
<?php foreach(CAP_PROVIDERS as $k=>$c) if($c['key']!==''): ?><label class="field"><?= e($c['name']) ?> — Site key<input name="<?= $c['key'] ?>_site" maxlength="100" value="<?= e(pset($c['key'].'_site')) ?>"></label><label class="field"><?= e($c['name']) ?> — Secret key<input name="<?= $c['key'] ?>_secret" type="password" maxlength="100" autocomplete="new-password" placeholder="<?= pset($c['key'].'_secret')!==''?'•••••••• (disimpan)':'' ?>"></label><?php endif; ?>
<label class="check full"><input type="checkbox" name="cap_luar" value="1"<?= $chk(pset('cap_luar')==='1') ?>> Gunakan penyedia luar ini (perlu lulus ujian di bawah)</label>
<div class="button-group"><button class="btn primary">Simpan CAPTCHA</button></div></form>
<?php if($p!=='sendiri' && cap_ready($p)): ?><form method="post" class="stack spaced"><?= csrf_field() ?><input type="hidden" name="action" value="p_sec_cap_test"><p class="muted">Ujian <?= e(CAP_PROVIDERS[$p]['name']) ?><?= hash_equals(cap_fingerprint($p),pset('cap_lulus'))?' — <span class="badge green">lulus</span>':'' ?>:</p><?= cap_widget($p) ?><div class="button-group"><button class="btn outline">Sahkan ujian</button></div></form><?php endif; ?></section>
<section class="panel padded spaced" id="twofa"><h3>Wajib 2FA</h3><p class="muted">Pentadbir sekolah & pentadbir platform sentiasa wajib 2FA. Hidupkan untuk mewajibkan 2FA bagi <strong>semua</strong> pengguna (guru & pembaca) di semua sekolah, mengatasi dasar sekolah.</p>
<form method="post" class="form-grid"><?= csrf_field() ?><input type="hidden" name="action" value="p_sec_2fa"><label class="check full"><input type="checkbox" name="wajib_2fa" value="1"<?= $chk(twofa_forced_all()) ?>> Wajibkan 2FA untuk semua pengguna semua sekolah</label><div class="button-group"><button class="btn primary">Simpan</button></div></form>
<?php if($no2fa): ?><details class="spaced"><summary>Pengguna aktif tanpa 2FA mengikut sekolah</summary><div class="table-wrap"><table><thead><tr><th>Sekolah</th><th>Tanpa 2FA</th></tr></thead><tbody><?php foreach($no2fa as $r): ?><tr><td><?= e($r['code'].' · '.$r['name']) ?></td><td><?= (int)$r['n'] ?></td></tr><?php endforeach; ?></tbody></table></div></details><?php endif; ?></section>
<section class="panel padded spaced danger-zone" id="kecemasan"><h3>Mod kecemasan</h3><p class="muted">Untuk insiden keselamatan: <strong>baca sahaja</strong> menghalang semua perubahan data di semua sekolah (log masuk masih boleh); <strong>tutup</strong> menutup semua laman sekolah. Panel platform, callback bayaran dan halaman awam platform tidak terjejas. Pendaftaran sekolah ditutup. Status: <strong><?= e(EMERGENCY_MODES[emergency_mode()]) ?></strong>.</p>
<form method="post" class="form-grid"><?= csrf_field() ?><input type="hidden" name="action" value="p_sec_emergency"><label class="field">Mod<select name="kecemasan"><?php foreach(EMERGENCY_MODES as $k=>$l): ?><option value="<?= $k ?>"<?= $k===emergency_mode()?' selected':'' ?>><?= e($l) ?></option><?php endforeach; ?></select></label>
<label class="field">Mesej kepada pengguna<input name="kecemasan_mesej" maxlength="300" value="<?= e(pset('kecemasan_mesej')) ?>"></label><label class="field">Kata laluan anda<input name="admin_password" type="password" required autocomplete="current-password"></label><div class="button-group"><button class="btn danger">Tetapkan mod</button></div></form></section>
<section class="panel padded spaced" id="lapor"><h3>Laporan keselamatan</h3><p class="muted">Penyelidik keselamatan melapor melalui <a class="text-link" href="/lapor-keselamatan">/lapor-keselamatan</a>; <a class="text-link" href="/.well-known/security.txt">security.txt</a> dijana automatik (luput setahun ke hadapan). Notis dihantar ke e-mel ini (kosong = e-mel notis platform).</p>
<form method="post" class="form-grid"><?= csrf_field() ?><input type="hidden" name="action" value="p_sec_contact"><label class="field">E-mel keselamatan<input name="emel_keselamatan" type="email" maxlength="190" value="<?= e(pset('emel_keselamatan')) ?>"></label><div class="button-group"><button class="btn outline">Simpan</button></div></form></section><?php
}

function platform_page_ipblocks(): void {
    $f=input('f','get'); $chk=fn(string $k,string $d='0')=>pset($k,$d)==='1'?' checked':'';
    $w=$f==='platform'?' AND b.school_id IS NULL':($f==='auto'?' AND b.auto=1':($f==='sekolah'?' AND b.school_id IS NOT NULL':''));
    $rows=query('SELECT b.*,s.code FROM ip_blocks b LEFT JOIN schools s ON s.id=b.school_id WHERE (b.expires_at IS NULL OR b.expires_at>NOW())'.$w.' ORDER BY b.id DESC LIMIT 300')->fetchAll(); ?>
<div class="page-heading"><div><span class="eyebrow teal">PLATFORM</span><h1>Perlindungan IP</h1><p class="muted">IP anda: <code><?= e(client_ip()) ?></code>. Sekatan platform terpakai pada semua sekolah; sekolah juga boleh menyekat IP untuk laman mereka sendiri (Sistem → Sekatan IP). Pemulihan: <code>php bin/security.php unblock IP</code>.</p></div></div>
<?php if(!ip_protect_on()): ?><div class="notice error" role="status">Perlindungan IP DIMATIKAN — tiada sekatan, senarai IP atau had IP akaun berkuat kuasa.</div><?php endif; ?>
<section class="panel padded spaced"><h3>Tetapan</h3><form method="post" class="form-grid"><?= csrf_field() ?><input type="hidden" name="action" value="p_sec_ip_settings">
<label class="check full"><input type="checkbox" name="ip_lindung" value="1"<?= $chk('ip_lindung','1') ?>> <strong>Perlindungan IP diaktifkan</strong> (suis utama)</label>
<label class="check full"><input type="checkbox" name="ip_auto" value="1"<?= $chk('ip_auto','1') ?>> Sekat automatik IP yang melepasi had cubaan log masuk (panel platform: panel & halaman awam platform sahaja; sekolah: sekolah itu sahaja; skop log masuk)</label>
<label class="field">Tempoh sekatan automatik (minit)<input name="ip_auto_minit" inputmode="numeric" value="<?= e(pset('ip_auto_minit','60')) ?>"></label>
<label class="check"><input type="checkbox" name="ip_auto_emel" value="1"<?= $chk('ip_auto_emel','1') ?>> E-mel notis platform bagi setiap sekatan automatik</label>
<label class="field full">IP pentadbir platform (tidak pernah disekat secara automatik)<textarea name="ip_platform" rows="3" placeholder="203.0.113.10&#10;2001:db8:1::/64"><?= e(pset('ip_platform')) ?></textarea></label>
<label class="check full"><input type="checkbox" name="ip_platform_wajib" value="1"<?= $chk('ip_platform_wajib') ?>> Panel platform (/platform/) hanya boleh diakses dari senarai di atas</label>
<div class="button-group"><button class="btn primary">Simpan tetapan</button></div></form></section>
<section class="panel padded spaced"><h3>Sekat IP</h3><form method="post" class="form-grid"><?= csrf_field() ?><input type="hidden" name="action" value="p_sec_ip_add">
<label class="field">IP atau CIDR<input name="ip" required placeholder="198.51.100.7 / 198.51.100.0/24"></label>
<label class="field">Skop<select name="skop"><?php foreach(IP_SCOPES as $k=>$l): ?><option value="<?= $k ?>"><?= e($l) ?></option><?php endforeach; ?></select></label>
<label class="field">Tempoh<select name="tempoh"><?php foreach(IP_DURATIONS as $k=>$l): ?><option value="<?= $k ?>"<?= $k==='1440'?' selected':'' ?>><?= e($l) ?></option><?php endforeach; ?></select></label>
<label class="field">Kod sekolah (kosong = seluruh platform)<input name="kod" maxlength="7"></label>
<label class="field full">Sebab<input name="sebab" maxlength="200"></label><label class="check full"><input type="checkbox" name="saya" value="1"> Saya faham sekatan ini mungkin meliputi IP saya sendiri</label>
<div class="button-group"><button class="btn danger">Sekat</button></div></form></section>
<nav class="takwim-filter" aria-label="Tapis sekatan"><?php foreach([''=>'Semua aktif','platform'=>'Platform','sekolah'=>'Sekolah','auto'=>'Automatik'] as $k=>$l): ?><a class="chip<?= $f===$k?' on':'' ?>" href="/platform/?p=ipsekat<?= $k!==''?'&amp;f='.$k:'' ?>"><?= e($l) ?></a><?php endforeach; ?></nav>
<section class="panel spaced"><div class="table-wrap"><table><thead><tr><th>IP</th><th>Peringkat</th><th>Skop</th><th>Tamat</th><th>Sebab</th><th>Cloudflare</th><th></th></tr></thead><tbody>
<?php foreach($rows as $r): ?><tr><td><code><?= e($r['ip']) ?></code><?= $r['auto']?' <span class="badge warn">auto</span>':'' ?></td><td><?= $r['code']?e($r['code']):'Platform' ?></td><td><?= e(IP_SCOPES[$r['scope']]??$r['scope']) ?></td><td><?= $r['expires_at']?e(date('d/m/Y H:i',strtotime($r['expires_at']))):'Kekal' ?></td><td><?= e($r['reason']) ?><br><small class="muted"><?= e($r['by_name']) ?> · <?= e(date('d/m/Y H:i',strtotime((string)$r['created_at']))) ?></small></td>
<td><?= $r['cf_id']?'<span class="badge green">Disegerak</span>':($r['cf_error']!==''?'<small class="muted">'.e($r['cf_error']).'</small>':'—') ?></td><td><form method="post"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><button class="btn sm outline" name="action" value="p_sec_ip_remove">Buang</button></form></td></tr><?php endforeach; ?>
<?php if(!$rows): ?><tr><td colspan="7" class="muted">Tiada sekatan aktif.</td></tr><?php endif; ?></tbody></table></div></section>
<section class="panel padded spaced" id="cloudflare"><h3>Cloudflare</h3><p class="muted">Segerak sekatan <strong>peringkat platform</strong> ke Cloudflare IP Access Rules (zon) supaya disekat di tepi rangkaian. Token API: Zone → Firewall Services → Edit, untuk zon ini sahaja. Julat disokong: IP tunggal, /16 /24 (IPv4), /32 /48 /64 (IPv6). Untuk IP klien sebenar di belakang Cloudflare, tetapkan <code>TRUSTED_PROXIES</code> dalam .env.</p>
<form method="post" class="form-grid" autocomplete="off"><?= csrf_field() ?><input type="hidden" name="action" value="p_sec_cf_save">
<label class="check full"><input type="checkbox" name="cf_aktif" value="1"<?= $chk('cf_aktif') ?>> Segerak ke Cloudflare</label>
<label class="field">Zone ID<input name="cf_zone" maxlength="32" value="<?= e(pset('cf_zone')) ?>"></label><label class="field">Token API<input name="cf_token" type="password" autocomplete="new-password" placeholder="<?= pset('cf_token')!==''?'•••••••• (disimpan)':'' ?>"></label>
<label class="check full"><input type="checkbox" name="cf_auto" value="1"<?= $chk('cf_auto') ?>> Segerak juga sekatan automatik</label>
<div class="button-group"><button class="btn primary">Simpan</button><button class="btn outline" name="action" value="p_sec_cf_test">Uji sambungan</button><button class="btn outline" name="action" value="p_sec_cf_sync">Segerak yang belum</button></div></form></section><?php
}

function platform_page_audit(): void {
    $res=input('semak','get')==='1'?audit_verify_all():null; ?>
<div class="page-heading"><div><span class="eyebrow teal">PLATFORM</span><h1>Rantai Audit</h1><p class="muted">Setiap baris log audit sekolah dan log platform ditandatangani HMAC berantai (kunci diterbitkan daripada APP_KEY, di luar pangkalan data). Baris yang diubah, dipadam atau dibuang di hujung dapat dikesan.</p></div><div class="button-group"><a class="btn primary" href="/platform/?p=audit&amp;semak=1">Sahkan semua rantai</a></div></div>
<?php if($res): $bad=array_filter($res,fn($r)=>!$r['ok']); ?><div class="notice <?= $bad?'error':'success' ?>" role="status"><?= $bad?count($bad).' rantai bermasalah — siasat sebelum menandatangani semula.':'Semua '.count($res).' rantai sah ('.array_sum(array_column($res,'rows')).' baris).' ?></div>
<section class="panel spaced"><div class="table-wrap"><table><thead><tr><th>Rantai</th><th>Baris</th><th>Status</th></tr></thead><tbody><?php foreach($res as $r): ?><tr><td><strong><?= e($r['code']) ?></strong> <small class="muted"><?= e($r['name']) ?></small></td><td><?= (int)$r['rows'] ?></td><td><?php if($r['ok']): ?><span class="badge green">Sah</span><?php else: ?><span class="badge warn">Bermasalah</span><ul class="plain-list"><?php foreach($r['problems'] as $m): ?><li><small><?= e($m) ?></small></li><?php endforeach; ?></ul><?php endif; ?></td></tr><?php endforeach; ?></tbody></table></div></section><?php endif; ?>
<div class="dashboard-grid pay-grid"><section class="panel padded"><h3>Eksport CSV</h3><form method="post" class="form-grid"><?= csrf_field() ?><input type="hidden" name="action" value="p_sec_audit_csv">
<label class="field full">Kod sekolah (atau PLATFORM)<input name="kod" maxlength="8" required value="PLATFORM"></label><label class="field">Dari<input type="date" name="dari" value="<?= e(date('Y-m-01')) ?>"></label><label class="field">Hingga<input type="date" name="hingga" value="<?= e(date('Y-m-d')) ?>"></label><div class="button-group"><button class="btn outline">Muat turun CSV</button></div></form></section>
<section class="panel padded danger-zone"><h3>Tandatangan semula</h3><p class="muted">Hanya selepas masalah disiasat (cth. pemulihan sandaran separa). Rantai lama diganti; tindakan ini dilog di log platform.</p><form method="post" class="form-grid"><?= csrf_field() ?><input type="hidden" name="action" value="p_sec_audit_rebuild">
<label class="field">Kod sekolah (atau PLATFORM)<input name="kod" maxlength="8" required></label><label class="field">Kata laluan anda<input name="admin_password" type="password" required autocomplete="current-password"></label><div class="button-group"><button class="btn danger">Tandatangan semula</button></div></form></section></div><?php
}

function platform_page_reports(): void {
    $id=(int)input('id','get'); $r=$id?query('SELECT * FROM security_reports WHERE id=?',[$id])->fetch():null;
    $rows=query('SELECT id,email,title,status,created_at FROM security_reports ORDER BY status="baru" DESC,id DESC LIMIT 300')->fetchAll(); ?>
<div class="page-heading"><div><span class="eyebrow teal">PLATFORM</span><h1>Laporan Keselamatan</h1><p class="muted">Laporan daripada <a class="text-link" href="/lapor-keselamatan">/lapor-keselamatan</a>. Kandungan dipaparkan sebagai teks biasa.</p></div></div>
<?php if($r): ?><section class="panel padded spaced"><h3>#<?= (int)$r['id'] ?> · <?= e($r['title']) ?></h3><p class="muted"><?= e($r['name']?:'(tanpa nama)') ?> · <?= e($r['email']) ?> · <?= e(date('d/m/Y H:i',strtotime((string)$r['created_at']))) ?></p><div class="prewrap legal-text"><?= e($r['body']) ?></div>
<form method="post" class="form-grid spaced"><?= csrf_field() ?><input type="hidden" name="action" value="p_sec_report"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><label class="field">Status<select name="status"><?php foreach(['baru'=>'Baru','disemak'=>'Sedang disemak','selesai'=>'Selesai','ditolak'=>'Ditolak'] as $k=>$l): ?><option value="<?= $k ?>"<?= $r['status']===$k?' selected':'' ?>><?= $l ?></option><?php endforeach; ?></select></label><label class="field">Catatan dalaman<input name="catatan" maxlength="500" value="<?= e($r['note']) ?>"></label><div class="button-group"><button class="btn primary">Simpan</button></div></form></section><?php endif; ?>
<section class="panel spaced"><div class="table-wrap"><table><thead><tr><th>#</th><th>Tajuk</th><th>Pelapor</th><th>Tarikh</th><th>Status</th></tr></thead><tbody><?php foreach($rows as $x): ?><tr><td><?= (int)$x['id'] ?></td><td><a class="text-link" href="/platform/?p=laporan&amp;id=<?= (int)$x['id'] ?>"><?= e($x['title']) ?></a></td><td><?= e($x['email']) ?></td><td><?= e(date('d/m/Y',strtotime((string)$x['created_at']))) ?></td><td><span class="badge<?= $x['status']==='baru'?' warn':'' ?>"><?= e($x['status']) ?></span></td></tr><?php endforeach; ?>
<?php if(!$rows): ?><tr><td colspan="5" class="muted">Tiada laporan.</td></tr><?php endif; ?></tbody></table></div></section><?php
}
function security_reports_new(): int { try { return (int)query('SELECT COUNT(*) FROM security_reports WHERE status="baru"')->fetchColumn(); } catch(PDOException) { return 0; } }

/* ======================= Sekolah ======================= */
function handle_security_school(string $action): never {
    require_admin(); $u=current_user();
    if($action==='sec_ip_add'){
        $ip=input('ip'); if(!ip_canon($ip)) throw new DomainException('IP atau CIDR tidak sah.');
        if(ip_in_cidr(client_ip(),(string)ip_canon($ip))) throw new DomainException('IP ini meliputi alamat anda sendiri ('.client_ip().').');
        $d=input('tempoh'); if(!isset(IP_DURATIONS[$d])) throw new DomainException('Tempoh tidak sah.');
        if((int)query('SELECT COUNT(*) FROM ip_blocks WHERE school_id='.sid().' AND (expires_at IS NULL OR expires_at>NOW())')->fetchColumn()>=200) throw new DomainException('Had 200 sekatan aktif setiap sekolah.');
        $id=ip_block_add(sid(),$ip,input('skop'),(int)$d,mb_substr(trim(input('sebab')),0,200),(string)$u['name']); if(!$id) throw new DomainException('Sekatan tidak sah.');
        audit('ip_block','ip_blocks',$id); flash('IP '.ip_canon($ip).' disekat untuk laman sekolah ini.'); redirect('/?page=ipsekat');
    }
    if($action==='sec_ip_remove'){ $id=(int)input('id'); if(ip_block_remove($id,sid())) audit('ip_unblock','ip_blocks',$id); flash('Sekatan dibuang.'); redirect('/?page=ipsekat'); }
    if($action==='sec_ip_admin'){
        confirm_admin_password();
        [$list,$err]=ip_list_form(input('ip_admin')); if($err) throw new DomainException($err);
        if($list!=='' && !ip_in_list(client_ip(),ip_list($list))) throw new DomainException('Senarai mesti mengandungi IP anda sekarang ('.client_ip().') — elak terkunci keluar.');
        save_settings(['ip_admin'=>$list]); audit('ip_admin_list','settings'); flash($list!==''?'Pentadbir sekolah kini hanya boleh log masuk dari IP yang disenaraikan.':'Had IP pentadbir dibuang.'); redirect('/?page=ipsekat');
    }
    error_page(400);
}
function school_page_ipblocks(): void {
    require_admin();
    $rows=query('SELECT * FROM ip_blocks WHERE school_id='.sid().' AND (expires_at IS NULL OR expires_at>NOW()) ORDER BY id DESC LIMIT 200')->fetchAll(); ?>
<div class="page-heading"><div><span class="eyebrow teal">SISTEM</span><h1>Sekatan IP</h1><p class="muted">IP anda: <code><?= e(client_ip()) ?></code>. Sekatan di sini hanya untuk laman sekolah ini. Sekatan seluruh platform diurus oleh pentadbir platform.</p></div></div>
<?php if(!ip_protect_on()): ?><p class="notice info">Perlindungan IP dimatikan oleh pentadbir platform — tetapan di sini tidak berkuat kuasa buat masa ini.</p><?php endif; ?>
<section class="panel padded spaced"><h3>Sekat IP</h3><form method="post" class="form-grid"><?= csrf_field() ?><input type="hidden" name="action" value="sec_ip_add">
<label class="field">IP atau CIDR<input name="ip" required></label><label class="field">Skop<select name="skop"><?php foreach(IP_SCOPES as $k=>$l): ?><option value="<?= $k ?>"><?= e($l) ?></option><?php endforeach; ?></select></label>
<label class="field">Tempoh<select name="tempoh"><?php foreach(IP_DURATIONS as $k=>$l): ?><option value="<?= $k ?>"<?= $k==='1440'?' selected':'' ?>><?= e($l) ?></option><?php endforeach; ?></select></label>
<label class="field">Sebab<input name="sebab" maxlength="200"></label><div class="button-group"><button class="btn danger">Sekat</button></div></form></section>
<section class="panel spaced"><div class="table-wrap"><table><thead><tr><th>IP</th><th>Skop</th><th>Tamat</th><th>Sebab</th><th></th></tr></thead><tbody>
<?php foreach($rows as $r): ?><tr><td><code><?= e($r['ip']) ?></code><?= $r['auto']?' <span class="badge warn">auto</span>':'' ?></td><td><?= e(IP_SCOPES[$r['scope']]??$r['scope']) ?></td><td><?= $r['expires_at']?e(date('d/m/Y H:i',strtotime($r['expires_at']))):'Kekal' ?></td><td><?= e($r['reason']) ?></td><td><form method="post"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><button class="btn sm outline" name="action" value="sec_ip_remove">Buang</button></form></td></tr><?php endforeach; ?>
<?php if(!$rows): ?><tr><td colspan="5" class="muted">Tiada sekatan aktif.</td></tr><?php endif; ?></tbody></table></div></section>
<section class="panel padded spaced"><h3>IP pentadbir sekolah</h3><p class="muted">Jika diisi, akaun berperanan Pentadbir hanya boleh log masuk dan menggunakan sistem dari IP/rangkaian ini (cth. rangkaian pejabat sekolah). Had IP setiap pengguna ditetapkan di Pengguna &amp; Audit → Urus akaun.</p>
<form method="post" class="form-grid"><?= csrf_field() ?><input type="hidden" name="action" value="sec_ip_admin"><label class="field full">Senarai IP / CIDR (kosong = semua IP)<textarea name="ip_admin" rows="3"><?= e(setting('ip_admin')) ?></textarea></label><?= admin_confirm_field() ?><div class="button-group"><button class="btn primary">Simpan</button></div></form></section><?php
}
