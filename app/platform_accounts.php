<?php
declare(strict_types=1);
/*
 * Akaun pentadbir platform (corak delima-multi):
 *  - ?p=pentadbir  : senarai, tambah (kata laluan sendiri / sementara), gantung/aktif, reset 2FA, padam.
 *                    Setiap perubahan disahkan dengan kata laluan sendiri; sekurang-kurangnya seorang pentadbir aktif kekal;
 *                    akaun sendiri diurus di Profil & 2FA.
 *  - ?p=lupa / ?p=reset : pautan reset ke e-mel akaun (sah 60 minit, sekali guna, mesej seragam, had cubaan).
 *  - ?p=kecemasan  : tanpa e-mel/CLI — cipta storage/reset-platform.txt berisi kod rahsia ≥ 16 aksara melalui File Manager,
 *                    kemudian dalam 30 minit masukkan e-mel akaun, kod yang sama & kata laluan baharu. Fail dituntut secara
 *                    atomik dan dipadam; sesi lama & 2FA akaun dikosongkan. Respons seragam (404) — tiada oracle kewujudan fail.
 */
const PRESET_MINIT=60, KECEMASAN_MINIT=30;

function platform_admins_all(): array { return query('SELECT id,name,email,active,totp_secret IS NOT NULL twofa,last_login_at,created_at,allowed_ips FROM platform_admins ORDER BY active DESC,name')->fetchAll(); }
function platform_active_count(): int { return (int)query('SELECT COUNT(*) FROM platform_admins WHERE active=1')->fetchColumn(); }
function platform_notify(string $email,string $name,string $subject,string $text): void {
    require_once __DIR__.'/mailer.php';
    if(!filter_var($email,FILTER_VALIDATE_EMAIL) || !pset('mail_from')) return;
    $e=null; if(!send_mail($email,$name,$subject,$text,$e)) platform_log('mail_failed',null,$subject.': '.(string)$e);
}

function handle_platform_admins(string $action,array $me): never {
    platform_confirm_password();
    if($action==='pa_create'){
        $name=trim(input('name')); $email=strtolower(trim(input('email'))); $pw=input('password'); $gen=$pw==='';
        if($name==='' || mb_strlen($name)>120) throw new DomainException('Nama tidak sah.');
        if(strlen($email)>190 || !filter_var($email,FILTER_VALIDATE_EMAIL)) throw new DomainException('E-mel tidak sah.');
        if($gen) $pw=platform_temp_password(); elseif(!valid_password($pw)) throw new DomainException('Kata laluan mesti 12 hingga 128 aksara.');
        $id=create_platform_admin($name,$email,$pw);
        platform_log('platform_admin_created',null,$email);
        platform_notify((string)$me['email'],(string)$me['name'],'Pentadbir platform baharu ditambah',"Pentadbir platform baharu ditambah oleh {$me['name']}:\n\nNama: $name\nE-mel: $email");
        if($gen) $_SESSION['pa_temp']=['email'=>$email,'password'=>$pw];
        flash('Pentadbir platform '.$email.' ditambah.'); redirect('/platform/?p=pentadbir');
    }
    $id=(int)input('admin_id'); $a=query('SELECT * FROM platform_admins WHERE id=?',[$id])->fetch(); if(!$a) error_page(404);
    if($id===(int)$me['id']) throw new DomainException('Urus akaun anda sendiri di Profil & 2FA.');
    if($action==='pa_toggle'){
        if((int)$a['active']===1 && platform_active_count()<=1) throw new DomainException('Sekurang-kurangnya seorang pentadbir platform mesti kekal aktif.');
        query('UPDATE platform_admins SET active=1-active,auth_version=auth_version+1 WHERE id=?',[$id]); // gantung: sesi serta-merta tamat
        if(function_exists('imp_revoke')) imp_revoke($id);
        platform_log((int)$a['active']?'platform_admin_suspended':'platform_admin_activated',null,$a['email']); flash((int)$a['active']?'Akaun digantung; sesinya tamat serta-merta.':'Akaun diaktifkan semula.');
    } elseif($action==='pa_reset_2fa'){
        query('UPDATE platform_admins SET totp_secret=NULL,last_totp_step=-1,auth_version=auth_version+1 WHERE id=?',[$id]);
        platform_log('platform_admin_2fa_reset',null,$a['email']); platform_notify($a['email'],$a['name'],'2FA akaun platform anda ditetapkan semula',"2FA akaun pentadbir platform anda ditetapkan semula oleh {$me['name']}. Daftar semula 2FA pada log masuk seterusnya.");
        flash('2FA '.$a['email'].' ditetapkan semula.');
    } elseif($action==='pa_ips'){
        [$list,$err]=ip_list_form(input('allowed_ips')); if($err) throw new DomainException($err);
        query('UPDATE platform_admins SET allowed_ips=?,auth_version=auth_version+1 WHERE id=?',[$list!==''?$list:null,$id]);
        platform_log('platform_admin_ips',null,$a['email'].': '.($list!==''?str_replace("\n",', ',$list):'semua IP')); flash('Had IP '.$a['email'].' disimpan.');
    } elseif($action==='pa_delete'){
        if((int)$a['active']===1 && platform_active_count()<=1) throw new DomainException('Pentadbir aktif terakhir tidak boleh dipadam.');
        query('DELETE FROM platform_admins WHERE id=?',[$id]); platform_log('platform_admin_deleted',null,$a['email']); flash('Pentadbir '.$a['email'].' dipadam.');
    } else error_page(400);
    redirect('/platform/?p=pentadbir');
}
function platform_page_admins(array $me): void {
    $rows=platform_admins_all(); $tmp=$_SESSION['pa_temp']??null; unset($_SESSION['pa_temp']);
    $confirm='<label class="field">Kata laluan anda (pengesahan)<input name="admin_password" type="password" required autocomplete="current-password"></label>'; ?>
<div class="page-heading"><div><span class="eyebrow teal">PLATFORM</span><h1>Pentadbir Platform</h1><p class="muted">Semua pentadbir platform mempunyai akses penuh. Setiap perubahan disahkan dengan kata laluan anda dan dilog.</p></div></div>
<?php if($tmp): ?><div class="notice success" role="status">Kata laluan sementara untuk <code><?= e($tmp['email']) ?></code>: <code class="secret"><?= e($tmp['password']) ?></code> (dipaparkan sekali sahaja; minta pengguna menukarnya di Profil &amp; 2FA).</div><?php endif; ?>
<section class="panel spaced"><div class="table-wrap"><table><thead><tr><th>Nama</th><th>E-mel</th><th>Status</th><th>2FA</th><th>Log masuk terakhir</th><th>Tindakan</th></tr></thead><tbody>
<?php foreach($rows as $a): $self=(int)$a['id']===(int)$me['id']; ?><tr class="<?= $a['active']?'':'row-muted' ?>"><td><strong><?= e($a['name']) ?></strong><?= $self?' <span class="small-pill">Anda</span>':'' ?></td><td><?= e($a['email']) ?></td><td><?= $a['active']?'<span class="badge green">Aktif</span>':'<span class="badge">Digantung</span>' ?></td><td><?= $a['twofa']?'✓':'—' ?></td><td><?= $a['last_login_at']?e(date('d/m/Y H:i',strtotime($a['last_login_at']))):'<span class="muted">Belum pernah</span>' ?></td>
<td><?php if($self): ?><a class="text-link" href="/platform/?p=profil">Profil &amp; 2FA</a><?php else: ?><details class="manual-key"><summary>Urus</summary><form method="post" class="stack"><?= csrf_field() ?><input type="hidden" name="admin_id" value="<?= (int)$a['id'] ?>"><?= $confirm ?><div class="button-group"><button class="btn sm outline" name="action" value="pa_toggle"><?= $a['active']?'Gantung':'Aktifkan' ?></button><?php if($a['twofa']): ?><button class="btn sm outline" name="action" value="pa_reset_2fa">Reset 2FA</button><?php endif; ?><button class="btn sm danger" name="action" value="pa_delete" data-confirm-simple="Padam pentadbir <?= e($a['email']) ?>?">Padam</button></div><label class="field">Had IP akaun (kosong = semua IP)<textarea name="allowed_ips" rows="2" placeholder="203.0.113.10&#10;2001:db8:1::/64"><?= e((string)($a['allowed_ips']??'')) ?></textarea></label><button class="btn sm outline" name="action" value="pa_ips">Simpan had IP</button></form></details><?php endif; ?></td></tr><?php endforeach; ?>
</tbody></table></div></section>
<section class="panel padded spaced"><h3>Tambah pentadbir</h3><form method="post" class="form-grid"><?= csrf_field() ?><input type="hidden" name="action" value="pa_create">
<label class="field">Nama<input name="name" maxlength="120" required></label><label class="field">E-mel<input name="email" type="email" maxlength="190" required></label>
<label class="field">Kata laluan (kosongkan untuk jana kata laluan sementara)<input name="password" type="password" minlength="12" maxlength="128" autocomplete="new-password"></label><?= $confirm ?>
<div class="button-group"><button class="btn primary">Tambah pentadbir</button></div></form></section><?php
}

/* ---------- Lupa / reset kata laluan (tanpa log masuk) ---------- */
function platform_forgot(): string {
    $ip=client_ip(); $email=strtolower(trim(input('email')));
    $k=rate_key('platform-lupa-ip',$ip); $ku=rate_key('platform-lupa-user',$email);
    $msg='Jika e-mel itu milik pentadbir platform, pautan set semula telah dihantar (sah '.PRESET_MINIT.' minit).';
    if(!rate_allowed($k,5) || !rate_allowed($ku,3)) return $msg; // mesej sama: tiada enumerasi / bom e-mel
    rate_hit($k); rate_hit($ku);
    $a=query('SELECT id,name,email FROM platform_admins WHERE email=? AND active=1',[$email])->fetch();
    if($a && pset('mail_from') && platform_domain()!==''){
        query('DELETE FROM platform_settings WHERE name LIKE "preset\\_%" AND updated_at<DATE_SUB(NOW(),INTERVAL 1 DAY)');
        $t=bin2hex(random_bytes(24));
        query('INSERT INTO platform_settings(name,value) VALUES (?,?)',['preset_'.substr(hash('sha256',$t),0,40),json_encode(['pid'=>(int)$a['id'],'exp'=>time()+PRESET_MINIT*60])]);
        platform_notify($a['email'],$a['name'],'Set semula kata laluan platform',"Permintaan set semula kata laluan pentadbir platform diterima. Pautan sah ".PRESET_MINIT." minit:\n\n".tenant_scheme().'://'.platform_domain()."/platform/?p=reset&t=$t\n\nAbaikan e-mel ini jika bukan anda.");
        platform_log('password_reset_requested',null,$a['email']);
    } elseif($a) platform_log('password_reset_not_sent',null,'e-mel/PLATFORM_DOMAIN belum ditetapkan');
    return $msg;
}
function platform_reset_token(string $t): ?array {
    if(!preg_match('/^[a-f0-9]{48}$/',$t)) return null;
    $v=query('SELECT value FROM platform_settings WHERE name=?',['preset_'.substr(hash('sha256',$t),0,40)])->fetchColumn(); if($v===false) return null;
    $j=json_decode((string)$v,true); return is_array($j) && (int)($j['exp']??0)>=time()?$j:null;
}
function platform_reset(string $t): void {
    $j=platform_reset_token($t); if(!$j) throw new DomainException('Pautan tidak sah atau telah tamat tempoh.');
    $pw=input('password'); if(!valid_password($pw) || !hash_equals($pw,input('password_confirm'))) throw new DomainException('Kata laluan mesti 12–128 aksara dan sepadan dengan pengesahan.');
    if(query('DELETE FROM platform_settings WHERE name=?',['preset_'.substr(hash('sha256',$t),0,40)])->rowCount()!==1) throw new DomainException('Pautan telah digunakan.');
    query('UPDATE platform_admins SET password_hash=?,auth_version=auth_version+1 WHERE id=? AND active=1',[hash_password($pw),(int)$j['pid']]);
    platform_log('password_reset_done',null,'',(int)$j['pid']);
}

/* ---------- Reset kecemasan (fail kod rahsia) ---------- */
function platform_emergency_file(): string { return dirname(__DIR__).'/storage/reset-platform.txt'; }
function platform_emergency(): bool {
    $ip=client_ip(); $k=rate_key('platform-kecemasan',$ip); $kg=rate_key('platform-kecemasan','*');
    if(!rate_allowed($k,5) || !rate_allowed($kg,20)) return false;
    rate_hit($k); rate_hit($kg);
    $email=strtolower(trim(input('email'))); $code=trim(input('kod')); $pw=input('password');
    if(!valid_password($pw) || !hash_equals($pw,input('password_confirm'))) return false;
    $f=platform_emergency_file(); clearstatcache();
    $isi=is_file($f) && time()-(int)filemtime($f)<KECEMASAN_MINIT*60?trim((string)@file_get_contents($f,false,null,0,1024)):'';
    if(strlen($isi)<16 || strlen($code)<16 || !hash_equals(hash('sha256',$isi),hash('sha256',$code))){ platform_log('emergency_reset_failed',null,$email); return false; }
    $claim=$f.'.guna-'.bin2hex(random_bytes(6)); if(!@rename($f,$claim)) return false; @unlink($claim); // tuntutan atomik
    $a=query('SELECT id,name,email FROM platform_admins WHERE email=?',[$email])->fetch(); if(!$a){ platform_log('emergency_reset_failed',null,$email); return false; }
    query('UPDATE platform_admins SET password_hash=?,totp_secret=NULL,last_totp_step=-1,active=1,auth_version=auth_version+1 WHERE id=?',[hash_password($pw),$a['id']]);
    if(function_exists('imp_revoke')) imp_revoke((int)$a['id']);
    platform_log('emergency_reset_done',null,$a['email'],(int)$a['id']);
    platform_notify($a['email'],$a['name'],'Reset kecemasan akaun platform',"Kata laluan akaun pentadbir platform anda telah ditetapkan semula melalui reset kecemasan (fail kod rahsia). 2FA dikosongkan — daftar semula pada log masuk. Jika bukan anda, semak akses File Manager pelayan dengan segera.");
    return true;
}
