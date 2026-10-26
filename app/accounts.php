<?php
declare(strict_types=1);
/* Akaun kendiri: daftar guru (pengesahan e-mel / kelulusan pentadbir), lupa & reset kata laluan, captcha log masuk.
   Prinsip keselamatan: tiada pendedahan sama ada e-mel berdaftar; token rawak 256-bit disimpan sebagai cincang,
   sekali guna & tamat tempoh; pautan dibina daripada URL laman yang ditetapkan pentadbir (bukan header Host). */

const ACCOUNT_PAGES=['daftar','lupa','reset','sahkan'];
const REG_MODES=['off'=>'Ditutup — hanya pentadbir menambah pengguna','email'=>'Pengesahan e-mel — akaun aktif selepas guru mengesahkan e-mel','approval'=>'Kelulusan pentadbir — akaun aktif selepas diluluskan','both'=>'E-mel + kelulusan — sahkan e-mel, kemudian pentadbir meluluskan'];
const LOGIN_CAPTCHA=['failures'=>'Selepas 3 cubaan gagal (disyorkan)','always'=>'Sentiasa','off'=>'Tidak'];
const TOKEN_TTL=['verify'=>172800,'reset'=>3600];

function reg_mode(): string { $m=setting('reg_mode'); return isset(REG_MODES[$m])?$m:'off'; }
function reg_open(): bool { return reg_mode()!=='off' && (reg_mode()==='approval' || mail_ready()); }
function reg_domains(): array { return array_values(array_filter(array_map(fn($d)=>strtolower(trim($d," \t@")),explode(',',setting('reg_domains'))))); }

/* ---------- Token ---------- */
function token_issue(int $uid,string $type): string {
    $raw=rtrim(strtr(base64_encode(random_bytes(32)),'+/','-_'),'=');
    query('DELETE FROM auth_tokens WHERE school_id='.sid().' AND user_id=? AND type=?',[$uid,$type]);
    query('INSERT INTO auth_tokens(school_id,user_id,type,token_hash,expires_at) VALUES (?,?,?,?,FROM_UNIXTIME(?))',[sid(),$uid,$type,hash('sha256',$raw),time()+TOKEN_TTL[$type]]);
    return $raw;
}
/** Cari token sah (belum guna, belum tamat). Tidak menandakannya digunakan. */
function token_find(string $raw,string $type): ?array {
    if(!preg_match('/^[A-Za-z0-9_-]{43}$/D',$raw)) return null;
    $r=query('SELECT t.id,t.user_id,u.email,u.name,u.active,u.approval,u.email_verified_at FROM auth_tokens t JOIN users u ON u.id=t.user_id WHERE t.school_id='.sid().' AND t.token_hash=? AND t.type=? AND t.used_at IS NULL AND t.expires_at>NOW()',[hash('sha256',$raw),$type])->fetch();
    return $r?:null;
}
function token_use(int $id): void { query('UPDATE auth_tokens SET used_at=NOW() WHERE id=? AND school_id='.sid(),[$id]); }
function link_to(string $page,string $token): string { return mail_base_url().'/?page='.$page.'&t='.$token; }
function notify_admins_pending(array $u): void {
    foreach(query('SELECT email,name FROM users WHERE school_id='.sid().' AND role="admin" AND active=1')->fetchAll() as $a)
        send_mail($a['email'],$a['name'],'Pendaftaran guru menunggu kelulusan',"Assalamualaikum dan salam sejahtera {$a['name']},\n\nPendaftaran akaun baharu menunggu kelulusan anda:\n\nNama: {$u['name']}\nE-mel: {$u['email']}\n\nLuluskan atau tolak di: ".mail_base_url()."/?page=users\n\nSemak identiti pemohon sebelum meluluskan.");
}

/* ---------- Tindakan awam (tanpa log masuk) ---------- */
function handle_account(string $action): never {
    $ip=client_ip();
    if($action==='daftar'){
        if(!reg_open()) throw new DomainException('Pendaftaran tidak dibuka.');
        $rk=rate_key('register-ip',$ip); if(!rate_allowed($rk,5)) throw new DomainException('Terlalu banyak cubaan. Cuba semula selepas 15 minit.'); rate_hit($rk);
        form_guard('daftar');
        $name=trim(preg_replace('/\s+/u',' ',input('name'))); $email=strtolower(trim(input('email'))); $pw=input('password');
        if(mb_strlen($name)<3 || mb_strlen($name)>120 || preg_match('/[<>\x00-\x1F]/u',$name)) throw new DomainException('Nama penuh tidak sah (3 hingga 120 aksara).');
        if(strlen($email)>190 || !filter_var($email,FILTER_VALIDATE_EMAIL)) throw new DomainException('Alamat e-mel tidak sah.');
        $doms=reg_domains(); if($doms && !in_array(substr(strrchr($email,'@'),1),$doms,true)) throw new DomainException('Pendaftaran hanya untuk e-mel '.implode(', ',array_map(fn($d)=>'@'.$d,$doms)).'.');
        if(!valid_password($pw)) throw new DomainException('Kata laluan mesti 12 hingga 128 aksara.');
        if($pw!==input('password_confirm')) throw new DomainException('Pengesahan kata laluan tidak sepadan.');
        if(stripos($pw,strtok($email,'@'))!==false) throw new DomainException('Kata laluan tidak boleh mengandungi alamat e-mel anda.');
        $mode=reg_mode(); $needVerify=in_array($mode,['email','both'],true);
        $existing=query('SELECT id,name,active,approval,email_verified_at FROM users WHERE school_id='.sid().' AND email=?',[$email])->fetch();
        if($existing){
            // Jangan dedahkan bahawa e-mel sudah berdaftar: maklumkan pemilik e-mel sahaja.
            if(mail_ready()) send_mail($email,$existing['name'],'Percubaan pendaftaran akaun',"Seseorang (mungkin anda) cuba mendaftar akaun baharu menggunakan e-mel ini, tetapi akaun dengan e-mel ini sudah wujud.\n\nJika anda terlupa kata laluan, gunakan pautan berikut: ".mail_base_url()."/?page=lupa\n\nJika bukan anda, abaikan e-mel ini.");
            audit('register_duplicate','users',(int)$existing['id']);
        } else {
            db()->beginTransaction();
            query('INSERT INTO users(school_id,name,email,password_hash,role,active,approval,email_verified_at,self_registered) VALUES (?,?,?,?,"teacher",0,?,NULL,1)',[sid(),$name,$email,hash_password($pw),$mode==='email'?'approved':'pending']);
            $uid=(int)db()->lastInsertId(); audit('register','users',$uid); db()->commit();
            if($needVerify) send_mail($email,$name,'Sahkan e-mel anda',"Assalamualaikum dan salam sejahtera $name,\n\nTerima kasih kerana mendaftar di ".app_name()." (".school_name().").\n\nSahkan alamat e-mel anda dengan membuka pautan ini (sah 48 jam):\n".link_to('sahkan',token_issue($uid,'verify'))."\n\nJika anda tidak mendaftar, abaikan e-mel ini.");
            else notify_admins_pending(['name'=>$name,'email'=>$email]);
        }
        $_SESSION['account_done']=$needVerify?'daftar_emel':'daftar_lulus'; redirect('/?page=daftar');
    }
    if($action==='lupa'){
        if(!mail_ready()) throw new DomainException('Fungsi lupa kata laluan belum disediakan. Hubungi pentadbir.');
        $rk=rate_key('forgot-ip',$ip); if(!rate_allowed($rk,6)) throw new DomainException('Terlalu banyak cubaan. Cuba semula selepas 15 minit.'); rate_hit($rk);
        form_guard('lupa');
        $email=strtolower(trim(input('email'))); if(!filter_var($email,FILTER_VALIDATE_EMAIL)) throw new DomainException('Alamat e-mel tidak sah.');
        $ek=rate_key('forgot-email',$email);
        if(rate_allowed($ek,3)){ rate_hit($ek);
            $u=query('SELECT id,name FROM users WHERE school_id='.sid().' AND email=? AND active=1',[$email])->fetch();
            if($u){ send_mail($email,$u['name'],'Tetapkan semula kata laluan',"Assalamualaikum dan salam sejahtera {$u['name']},\n\nKami menerima permintaan untuk menetapkan semula kata laluan akaun anda.\n\nBuka pautan ini dalam masa 1 jam (sekali guna sahaja):\n".link_to('reset',token_issue((int)$u['id'],'reset'))."\n\nJika anda tidak membuat permintaan ini, abaikan e-mel ini. Kata laluan anda tidak berubah."); audit('password_reset_requested','users',(int)$u['id']); }
        }
        $_SESSION['account_done']='lupa'; redirect('/?page=lupa');
    }
    if($action==='reset'){
        $raw=input('t'); $rk=rate_key('reset-ip',$ip); if(!rate_allowed($rk,10)) throw new DomainException('Terlalu banyak cubaan. Cuba semula selepas 15 minit.'); rate_hit($rk);
        $t=token_find($raw,'reset'); if(!$t || !(int)$t['active']) throw new DomainException('Pautan tidak sah atau telah tamat tempoh. Minta pautan baharu.');
        $pw=input('password'); if(!valid_password($pw)) throw new DomainException('Kata laluan mesti 12 hingga 128 aksara.');
        if($pw!==input('password_confirm')) throw new DomainException('Pengesahan kata laluan tidak sepadan.');
        db()->beginTransaction();
        query('UPDATE users SET password_hash=?,must_change=0,auth_version=auth_version+1 WHERE id=? AND school_id='.sid(),[hash_password($pw),$t['user_id']]); // tamatkan semua sesi
        token_use((int)$t['id']); query('DELETE FROM auth_tokens WHERE school_id='.sid().' AND user_id=? AND type="reset"',[$t['user_id']]);
        query('DELETE FROM rate_limits WHERE bucket=?',[rate_key('login-fail-user',strtolower($t['email']))]);
        audit('password_reset','users',(int)$t['user_id']); db()->commit();
        send_mail($t['email'],$t['name'],'Kata laluan anda telah ditukar',"Kata laluan akaun ".app_name()." anda baru sahaja ditetapkan semula (".date('d/m/Y H:i').").\n\nJika bukan anda yang melakukannya, hubungi pentadbir sistem dengan segera.");
        $_SESSION['account_done']='reset'; redirect('/?page=reset');
    }
    throw new DomainException('Tindakan tidak sah.');
}
/** Pautan pengesahan e-mel (GET). Tidak memerlukan log masuk. */
function account_verify_email(): string {
    $t=token_find(input('t','get'),'verify');
    if(!$t) return 'invalid';
    if($t['approval']==='approved') free_guard('pengguna',1);
    db()->beginTransaction();
    token_use((int)$t['id']);
    $approved=$t['approval']==='approved';
    query('UPDATE users SET email_verified_at=NOW(),active=? WHERE id=? AND school_id='.sid(),[$approved?1:0,$t['user_id']]);
    audit('email_verified','users',(int)$t['user_id']); db()->commit();
    if(!$approved) notify_admins_pending($t);
    return $approved?'active':'pending';
}

/* ---------- Tindakan pentadbir ---------- */
function handle_registration_admin(string $action): never {
    require_admin();
    if($action==='reg_approve' || $action==='reg_reject'){
        $id=(int)input('user_id'); $u=query('SELECT * FROM users WHERE id=? AND school_id='.sid().' AND approval="pending"',[$id])->fetch();
        if(!$u) throw new DomainException('Permohonan tidak dijumpai atau telah diproses.');
        if($action==='reg_approve'){
            // Mod "e-mel + kelulusan": akaun aktif hanya selepas e-mel juga disahkan.
            $verified=$u['email_verified_at']!==null || reg_mode()!=='both';
            if($verified) free_guard('pengguna',1);
            query('UPDATE users SET approval="approved",active=? WHERE id=? AND school_id='.sid(),[$verified?1:0,$id]);
            audit('register_approved','users',$id);
            if($verified && mail_ready()) send_mail($u['email'],$u['name'],'Akaun anda telah diluluskan',"Assalamualaikum dan salam sejahtera {$u['name']},\n\nAkaun anda di ".app_name()." telah diluluskan. Anda kini boleh log masuk:\n".mail_base_url()."/?page=login\n\nPentadbir akan memautkan akaun anda kepada profil guru.");
            flash($verified?'Pendaftaran diluluskan. Pengguna kini boleh log masuk. Tugaskan unit melalui borang Penugasan di bawah.':'Diluluskan. Akaun akan aktif sebaik pengguna mengesahkan e-mel.');
        } else { query('DELETE FROM users WHERE id=? AND school_id='.sid(),[$id]); audit('register_rejected','users',$id); flash('Pendaftaran ditolak dan dipadam.'); }
        redirect('/?page=users');
    }
    if($action==='reg_settings_save'){
        $mode=input('reg_mode'); if(!isset(REG_MODES[$mode])) throw new DomainException('Mod pendaftaran tidak sah.');
        $lc=input('login_captcha'); if(!isset(LOGIN_CAPTCHA[$lc])) throw new DomainException('Pilihan captcha tidak sah.');
        $doms=[]; foreach(array_filter(array_map('trim',explode(',',strtolower(input('reg_domains'))))) as $d){ $d=ltrim($d,'@'); if(!preg_match('/^(?=.{3,190}$)([a-z0-9-]+\.)+[a-z]{2,}$/D',$d)) throw new DomainException('Domain "'.$d.'" tidak sah. Contoh: moe-dl.edu.my'); $doms[]=$d; }
        $url=rtrim(trim(input('site_url')),'/'); if($url!=='' && (!filter_var($url,FILTER_VALIDATE_URL) || !preg_match('~^https?://[^/?#]+$~i',$url))) throw new DomainException('URL laman mesti seperti https://koku.sekolah.my (tanpa laluan).');
        if($url!=='' && str_starts_with($url,'http://') && !preg_match('~^http://(localhost|127\.0\.0\.1)~',$url)) throw new DomainException('Gunakan https:// supaya pautan dalam e-mel selamat.');
        $method=input('mail_method'); if(!isset(SCHOOL_MAIL_METHODS[$method]) && !($method==='log' && env('APP_ENV')==='local')) throw new DomainException('Kaedah e-mel tidak sah. Sekolah hanya boleh menggunakan e-mel platform atau SMTP luar sendiri.');
        $from=trim(input('mail_from')); if($from!=='' && !filter_var($from,FILTER_VALIDATE_EMAIL)) throw new DomainException('E-mel pengirim tidak sah.');
        $out=['reg_mode'=>$mode,'reg_domains'=>implode(', ',array_unique($doms)),'login_captcha'=>$lc,'site_url'=>$url,'mail_method'=>$method,'mail_from'=>$from,'mail_from_name'=>mb_substr(mail_clean_header(input('mail_from_name')),0,80)];
        if($method==='smtp' && $from==='') throw new DomainException('SMTP luar memerlukan e-mel pengirim (alamat akaun SMTP sekolah).');
        if($method==='smtp'){
            $out['smtp_preset']=smtp_preset_valid(input('smtp_preset'));
            $host=trim(input('smtp_host')); $port=(int)input('smtp_port'); $sec=input('smtp_secure');
            if(!preg_match('/^[A-Za-z0-9.-]{3,190}$/D',$host)) throw new DomainException('Hos SMTP tidak sah.');
            if($port<1 || $port>65535) throw new DomainException('Port SMTP tidak sah.');
            if(!in_array($sec,['tls','ssl','none'],true)) throw new DomainException('Penyulitan SMTP tidak sah.');
            $out+=['smtp_host'=>$host,'smtp_port'=>(string)$port,'smtp_secure'=>$sec,'smtp_user'=>mb_substr(trim(input('smtp_user')),0,190)];
            if(input('smtp_pass')!=='') $out['smtp_pass']=encrypt_value(input('smtp_pass'));
        }
        $fromOk=$method==='platform'?filter_var(pset('mail_from'),FILTER_VALIDATE_EMAIL)!==false:$from!=='';
        if($mode!=='off' && $mode!=='approval' && ($url==='' || !$fromOk)) throw new DomainException($method==='platform'?'Mod pengesahan e-mel memerlukan URL laman, dan e-mel platform mesti disediakan oleh pentadbir platform (atau pilih SMTP luar sendiri).':'Mod pengesahan e-mel memerlukan URL laman dan e-mel pengirim.');
        save_settings($out); audit('reg_settings','settings'); flash('Tetapan pendaftaran & e-mel disimpan.'); redirect('/?page=urus_akaun');
    }
    if($action==='reg_test_mail'){
        $me=current_user(); $err=null;
        $ok=send_mail($me['email'],$me['name'],'Ujian e-mel '.app_name(),"Ini e-mel ujian daripada ".app_name().".\n\nJika anda menerima e-mel ini, tetapan e-mel berfungsi.\nURL laman: ".(mail_base_url()??'(belum ditetapkan)'),$err);
        audit('mail_test','settings');
        if(!$ok) throw new DomainException('E-mel ujian gagal: '.$err);
        flash('E-mel ujian dihantar ke '.$me['email'].'. Semak peti masuk (dan folder spam).'); redirect('/?page=urus_akaun');
    }
    throw new DomainException('Tindakan tidak sah.');
}

/* ---------- Paparan borang awam (dalam kerangka halaman log masuk) ---------- */
function account_heading(string $page): array {
    return match($page){
        'daftar'=>['Daftar akaun guru','Isi maklumat anda. '.(reg_mode()==='email'?'Akaun aktif selepas e-mel disahkan.':(reg_mode()==='approval'?'Akaun aktif selepas diluluskan pentadbir.':'Sahkan e-mel, kemudian tunggu kelulusan pentadbir.'))],
        'lupa'=>['Lupa kata laluan?','Masukkan e-mel akaun anda. Kami akan menghantar pautan untuk menetapkan semula kata laluan.'],
        'reset'=>['Kata laluan baharu','Pilih kata laluan baharu untuk akaun anda.'],
        'sahkan'=>['Pengesahan e-mel',''],
        default=>['','']};
}
function account_form(string $page): void {
    $done=$_SESSION['account_done']??null; unset($_SESSION['account_done']);
    $msg=['daftar_emel'=>['Semak e-mel anda','Jika maklumat sah, kami telah menghantar pautan pengesahan ke e-mel anda. Pautan sah selama 48 jam. Semak juga folder spam.'],
        'daftar_lulus'=>['Pendaftaran diterima','Permohonan anda akan disemak oleh pentadbir. Anda boleh log masuk selepas akaun diluluskan.'],
        'lupa'=>['Semak e-mel anda','Jika e-mel itu berdaftar, pautan untuk menetapkan semula kata laluan telah dihantar. Pautan sah selama 1 jam. Semak juga folder spam.'],
        'reset'=>['Kata laluan ditukar','Kata laluan anda telah dikemas kini dan semua sesi lama ditamatkan. Sila log masuk dengan kata laluan baharu.']];
    if($done && isset($msg[$done])){ echo '<div class="account-done">'.icon('check').'<h3>'.e($msg[$done][0]).'</h3><p>'.e($msg[$done][1]).'</p><a class="btn primary" href="/?page=login">Ke halaman log masuk</a></div>'; return; }
    if($page==='sahkan'){
        $r=account_verify_email();
        [$t,$d,$ok]=match($r){ 'active'=>['E-mel disahkan','Akaun anda kini aktif. Sila log masuk.',true], 'pending'=>['E-mel disahkan','Terima kasih. Akaun anda kini menunggu kelulusan pentadbir. Anda akan dimaklumkan melalui e-mel.',true], default=>['Pautan tidak sah','Pautan pengesahan tidak sah, telah digunakan atau tamat tempoh. Daftar semula atau hubungi pentadbir.',false] };
        echo '<div class="account-done'.($ok?'':' bad').'">'.icon($ok?'check':'alert').'<h3>'.e($t).'</h3><p>'.e($d).'</p><a class="btn primary" href="/?page=login">Ke halaman log masuk</a></div>'; return;
    }
    if($page==='daftar' && !reg_open()){ echo '<div class="account-done bad">'.icon('lock').'<h3>Pendaftaran ditutup</h3><p>Akaun guru dicipta oleh pentadbir sistem. Hubungi pentadbir sekolah anda.</p><a class="btn outline" href="/?page=login">← Log masuk</a></div>'; return; }
    if($page==='lupa' && !mail_ready()){ echo '<div class="account-done bad">'.icon('info').'<h3>Hubungi pentadbir</h3><p>Penetapan semula kata laluan melalui e-mel belum diaktifkan. Pentadbir boleh menetapkan kata laluan sementara untuk anda.</p><a class="btn outline" href="/?page=login">← Log masuk</a></div>'; return; }
    if($page==='reset'){ $t=token_find(input('t','get'),'reset'); if(!$t){ echo '<div class="account-done bad">'.icon('alert').'<h3>Pautan tidak sah</h3><p>Pautan telah digunakan atau tamat tempoh (1 jam). Minta pautan baharu.</p><a class="btn primary" href="/?page=lupa">Minta pautan baharu</a></div>'; return; } }
    echo '<form method="post" class="stack" novalidate>'.csrf_field().'<input type="hidden" name="action" value="'.e($page).'">';
    if($page==='daftar'){
        $doms=reg_domains();
        echo '<label class="field">Nama penuh<input name="name" maxlength="120" autocomplete="name" required value="'.e(input('name')).'"></label>';
        echo '<label class="field">E-mel'.($doms?' <small class="muted">('.e(implode(', ',array_map(fn($d)=>'@'.$d,$doms))).')</small>':'').'<input name="email" type="email" maxlength="190" autocomplete="email" required value="'.e(input('email')).'"></label>';
        echo '<label class="field">Kata laluan<input name="password" type="password" minlength="12" maxlength="128" autocomplete="new-password" required><small class="muted">Sekurang-kurangnya 12 aksara. Frasa panjang lebih selamat.</small></label><label class="field">Sahkan kata laluan<input name="password_confirm" type="password" maxlength="128" autocomplete="new-password" required></label>';
        echo captcha_field('daftar').'<button class="btn primary" type="submit">Daftar →</button>';
    } elseif($page==='lupa'){
        echo '<label class="field">Alamat e-mel<input name="email" type="email" maxlength="190" autocomplete="email" required></label>'.captcha_field('lupa').'<button class="btn primary" type="submit">Hantar pautan →</button>';
    } elseif($page==='reset'){
        echo '<input type="hidden" name="t" value="'.e(input('t','get')).'"><p class="muted reset-for">Akaun: <strong>'.e($t['email']).'</strong></p><label class="field">Kata laluan baharu<input name="password" type="password" minlength="12" maxlength="128" autocomplete="new-password" required><small class="muted">12 hingga 128 aksara.</small></label><label class="field">Sahkan kata laluan baharu<input name="password_confirm" type="password" maxlength="128" autocomplete="new-password" required></label><button class="btn primary" type="submit">Simpan kata laluan →</button>';
    }
    echo '</form><p class="account-links"><a href="/?page=login">← Kembali ke log masuk</a></p>';
}
function login_extras(): void {
    if(function_exists('demo_mode') && demo_mode()){ demo_login_panel(); return; }
    echo '<p class="account-links"><a href="/?page=lupa">Lupa kata laluan?</a>'.(reg_open()?'<span>·</span><a href="/?page=daftar">Daftar akaun guru</a>':'').'</p>';
}

/* ---------- Halaman pentadbir ---------- */
function pending_registrations(): array { return query('SELECT id,name,email,email_verified_at,created_at FROM users WHERE school_id='.sid().' AND approval="pending" ORDER BY created_at')->fetchAll(); }
function pending_panel(): void {
    $rows=pending_registrations(); if(!$rows) return; ?>
<section class="panel spaced pending-panel" id="permohonan"><div class="panel-heading"><div><h3>Pendaftaran menunggu kelulusan <span class="count"><?= count($rows) ?></span></h3><p class="muted">Semak identiti pemohon (nama & e-mel rasmi) sebelum meluluskan. Pengguna yang diluluskan mendapat peranan Guru tanpa unit.</p></div></div><div class="table-wrap"><table><thead><tr><th>Nama</th><th>E-mel</th><th>E-mel disahkan</th><th>Didaftar</th><th>Tindakan</th></tr></thead><tbody><?php foreach($rows as $r): ?><tr><td><strong><?= e($r['name']) ?></strong></td><td><?= e($r['email']) ?></td><td><?= $r['email_verified_at']?'<span class="badge green">Ya</span>':(reg_mode()==='approval'?'<span class="badge">Tidak diperlukan</span>':'<span class="badge warn">Belum</span>') ?></td><td><?= e(date('d/m/Y H:i',strtotime($r['created_at']))) ?></td><td><div class="row-actions"><form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="reg_approve"><input type="hidden" name="user_id" value="<?= (int)$r['id'] ?>"><button class="btn sm primary">Luluskan</button></form><form method="post" class="delete-form" data-confirm="Tolak dan padam pendaftaran <?= e($r['name']) ?>?"><?= csrf_field() ?><input type="hidden" name="action" value="reg_reject"><input type="hidden" name="user_id" value="<?= (int)$r['id'] ?>"><button class="delete-btn">Tolak</button></form></div></td></tr><?php endforeach; ?></tbody></table></div></section><?php
}
function urus_akaun_page(): void {
    $s=fn($k)=>setting($k); $method=mail_from_school()?(setting('mail_method')==='log'?'log':'smtp'):'platform'; $suggest=(request_is_https()?'https':'http').'://'.preg_replace('/[^A-Za-z0-9.:-]/','',(string)($_SERVER['HTTP_HOST']??''));
    ?><div class="page-heading"><div><span class="eyebrow teal">SISTEM</span><h1>Pendaftaran & E-mel</h1><p class="muted">Daftar kendiri guru, lupa kata laluan, captcha dan penghantaran e-mel.</p></div></div>
    <?php if(!mail_ready()) notice($method==='platform'?'E-mel platform belum disediakan oleh pentadbir platform, atau URL laman belum diisi. Pilih SMTP luar sekolah sendiri, atau hubungi pentadbir platform. Tanpanya, "Lupa kata laluan" dan pengesahan e-mel tidak berfungsi.':'E-mel belum disediakan: isi URL laman dan tetapan SMTP, kemudian hantar e-mel ujian. Tanpanya, "Lupa kata laluan" dan pengesahan e-mel tidak berfungsi.','error'); ?>
    <form method="post" class="settings-form"><?= csrf_field() ?><input type="hidden" name="action" value="reg_settings_save">
    <section class="panel padded"><h3>Pendaftaran guru</h3><div class="radio-list"><?php foreach(REG_MODES as $k=>$l): ?><label class="check"><input type="radio" name="reg_mode" value="<?= $k ?>"<?= reg_mode()===$k?' checked':'' ?>> <?= e($l) ?></label><?php endforeach; ?></div>
    <label class="field">Hadkan kepada domain e-mel (pilihan)<input name="reg_domains" maxlength="400" placeholder="moe-dl.edu.my, moe.gov.my" value="<?= e($s('reg_domains')) ?>"><small class="muted">Asingkan dengan koma. Kosongkan untuk membenarkan semua domain.</small></label>
    <p class="muted">Pengguna berdaftar mendapat peranan <strong>Guru</strong> tanpa unit. Pentadbir menugaskan unit di Pengguna & Audit. Dasar 2FA tetap terpakai.</p></section>
    <section class="panel padded spaced"><h3>Captcha log masuk</h3><div class="radio-list"><?php foreach(LOGIN_CAPTCHA as $k=>$l): ?><label class="check"><input type="radio" name="login_captcha" value="<?= $k ?>"<?= (setting('login_captcha')?:'failures')===$k?' checked':'' ?>> <?= e($l) ?></label><?php endforeach; ?></div><p class="muted">Borang daftar dan lupa kata laluan sentiasa menggunakan captcha. Captcha dijana oleh pelayan sendiri (tiada servis luar / penjejakan).</p></section>
    <section class="panel padded spaced"><h3>E-mel</h3><div class="form-grid">
    <label class="field full">URL laman<input name="site_url" maxlength="190" placeholder="<?= e($suggest) ?>" value="<?= e($s('site_url')) ?>"><small class="muted">Digunakan untuk pautan dalam e-mel (cth. <?= e($suggest) ?>). Ditetapkan di sini supaya pautan tidak boleh dipalsukan.</small></label>
    <label class="field full">Kaedah penghantaran<select name="mail_method" data-mail-method><?php foreach(SCHOOL_MAIL_METHODS+(env('APP_ENV')==='local'?['log'=>'Log fail (ujian tempatan)']:[]) as $k=>$l): ?><option value="<?= $k ?>"<?= $method===$k?' selected':'' ?>><?= e($l) ?></option><?php endforeach; ?></select><small class="muted">E-mel platform: dihantar menggunakan tetapan pentadbir platform atas nama sekolah. SMTP luar: akaun e-mel sekolah sendiri (pelayan mail() tidak digunakan untuk sekolah).</small></label>
    <div class="full smtp-fields form-grid"<?= in_array($method,['smtp','log'],true)?'':' hidden' ?>>
    <label class="field">E-mel pengirim<input type="email" name="mail_from" maxlength="190" placeholder="noreply@<?= e(preg_replace('/:\d+$/','',parse_url($suggest,PHP_URL_HOST)?:'sekolah.my')) ?>" value="<?= e($s('mail_from')) ?>"></label>
    <label class="field">Nama pengirim<input name="mail_from_name" maxlength="80" placeholder="<?= e(app_name()) ?>" value="<?= e($s('mail_from_name')) ?>"></label>
     <?= smtp_preset_field($s('smtp_preset')?:'custom') ?>
     <label class="field">Hos SMTP<input name="smtp_host" maxlength="190" placeholder="mail.domain.my / smtp.gmail.com" value="<?= e($s('smtp_host')) ?>"></label>
     <label class="field">Port<input name="smtp_port" inputmode="numeric" maxlength="5" placeholder="587" value="<?= e($s('smtp_port')) ?>"></label>
     <label class="field">Penyulitan<select name="smtp_secure"><?php foreach(['tls'=>'STARTTLS (port 587)','ssl'=>'SSL/TLS (port 465)','none'=>'Tiada (localhost sahaja)'] as $k=>$l): ?><option value="<?= $k ?>"<?= ($s('smtp_secure')?:'tls')===$k?' selected':'' ?>><?= e($l) ?></option><?php endforeach; ?></select></label>
     <label class="field">Nama pengguna SMTP<input name="smtp_user" maxlength="190" autocomplete="off" value="<?= e($s('smtp_user')) ?>"></label>
     <label class="field full">Kata laluan SMTP<input type="password" name="smtp_pass" maxlength="200" autocomplete="new-password" placeholder="<?= $s('smtp_pass')!==''?'•••••••• (disimpan & disulitkan; kosongkan untuk kekal)':'' ?>"><small class="muted">Untuk Gmail/Microsoft 365, gunakan <em>App Password</em>. Disimpan secara tersulit.</small></label>
    </div></div>
    <div class="form-actions"><button class="btn primary"><?= icon('check') ?>Simpan tetapan</button></div></section></form>
    <section class="panel padded spaced"><h3>Uji e-mel</h3><p class="muted">Hantar e-mel ujian ke <?= e(current_user()['email']) ?> menggunakan tetapan yang telah disimpan.</p><form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="reg_test_mail"><button class="btn outline"><?= icon('arrow') ?>Hantar e-mel ujian</button></form></section><?php
}
