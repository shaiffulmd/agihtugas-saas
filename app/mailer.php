<?php
declare(strict_types=1);
/* Penghantar e-mel tanpa pustaka luar: PHP mail() atau SMTP (STARTTLS / SSL, AUTH LOGIN).
   Mod "log" (APP_ENV=local sahaja) menyimpan e-mel ke storage/mail untuk ujian. */

/** Kaedah e-mel PLATFORM (Tetapan Platform → E-mel platform). */
const MAIL_METHODS=['mail'=>'PHP mail() (pelayan hosting)','smtp'=>'SMTP (Brevo, Gmail, iCloud, Microsoft 365, e-mel hosting …)'];
/** Kaedah e-mel SEKOLAH: e-mel platform atau SMTP luar sendiri sahaja — mail() pelayan dikongsi tidak dibenarkan untuk sekolah. */
const SCHOOL_MAIL_METHODS=['platform'=>'E-mel platform (lalai)','smtp'=>'SMTP luar sekolah sendiri (Brevo, Gmail, iCloud …)'];
/** Pratetap pembekal SMTP: [label, hos, port, penyulitan, panduan pengguna/kata laluan]. Mengisi medan secara automatik (app.js). */
const SMTP_PRESETS=[
    'brevo'=>['Brevo (Sendinblue)','smtp-relay.brevo.com',587,'tls','Pengguna: e-mel log masuk Brevo (SMTP login) · Kata laluan: SMTP key (Brevo → SMTP & API). Sahkan domain/alamat pengirim di Brevo.'],
    'gmail'=>['Gmail / Google Workspace','smtp.gmail.com',587,'tls','Pengguna: alamat Gmail penuh · Kata laluan: App Password (perlu 2-Step Verification). Alamat pengirim mesti alamat akaun itu.'],
    'icloud'=>['Apple iCloud Mail','smtp.mail.me.com',587,'tls','Pengguna: alamat iCloud penuh (@icloud.com) · Kata laluan: app-specific password (account.apple.com → Sign-In and Security).'],
    'office365'=>['Microsoft 365 / Outlook','smtp.office365.com',587,'tls','Pengguna: alamat penuh · Kata laluan akaun / App Password. SMTP AUTH mesti dibenarkan untuk peti mel itu.'],
    'zoho'=>['Zoho Mail','smtp.zoho.com',587,'tls','Pengguna: alamat Zoho penuh · Kata laluan: App Password (akaun dengan 2FA).'],
    'ses'=>['Amazon SES (Singapura)','email-smtp.ap-southeast-1.amazonaws.com',587,'tls','Pengguna & kata laluan: SMTP credentials SES (bukan kunci IAM biasa). Sahkan domain pengirim di SES.'],
    'sendgrid'=>['SendGrid','smtp.sendgrid.net',587,'tls','Pengguna: apikey (perkataan itu sahaja) · Kata laluan: API key SendGrid.'],
    'mailgun'=>['Mailgun','smtp.mailgun.org',587,'tls','Pengguna & kata laluan: SMTP credentials domain Mailgun.'],
    'cpanel'=>['E-mel hosting (cPanel / DirectAdmin)','',465,'ssl','Hos: mail.domain-anda · Pengguna: alamat e-mel penuh · Kata laluan: kata laluan peti mel.'],
    'custom'=>['Lain-lain (isi sendiri)','',587,'tls',''],
];
function smtp_preset_valid(string $p): string { return isset(SMTP_PRESETS[$p])?$p:'custom'; }
/** Medan pilih pratetap (atribut data dibaca oleh app.js untuk mengisi hos/port/penyulitan; tiada skrip sebaris). */
function smtp_preset_field(string $current,string $class='field full'): string {
    $current=smtp_preset_valid($current); $o='';
    foreach(SMTP_PRESETS as $k=>[$l,$h,$p,$sec,$hint]) $o.='<option value="'.e($k).'" data-host="'.e($h).'" data-port="'.$p.'" data-secure="'.e($sec).'" data-hint="'.e($hint).'"'.($k===$current?' selected':'').'>'.e($l).'</option>';
    return '<label class="'.e($class).'">Pembekal SMTP<select name="smtp_preset" data-smtp-preset>'.$o.'</select><small class="muted" data-smtp-hint>'.e(SMTP_PRESETS[$current][4]).'</small></label>';
}

/** Sekolah menggunakan SMTP luar sendiri? Jika tidak, e-mel dihantar melalui e-mel platform (Tetapan Platform → E-mel). */
function mail_from_school(): bool {
    if(sid()<=0 || filter_var(setting('mail_from'),FILTER_VALIDATE_EMAIL)===false) return false;
    $m=setting('mail_method'); return ($m==='smtp' && setting('smtp_host')!=='') || ($m==='log' && env('APP_ENV')==='local');
}
function mcfg(string $k): string { return mail_from_school()?setting($k):pset($k); }
function mail_smtp_pass(): string { $v=mcfg('smtp_pass'); if($v==='') return ''; return mail_from_school()?decrypt_value($v):pdecrypt($v); }
function mail_method(): string { $m=mcfg('mail_method'); if($m==='log' && env('APP_ENV')==='local') return 'log'; return isset(MAIL_METHODS[$m])?$m:'mail'; }
/** URL asas untuk pautan dalam e-mel. Diambil daripada tetapan (bukan header Host) supaya tidak boleh dipalsukan. */
/** URL laman untuk pautan e-mel: tetapan site_url sekolah, atau URL sekolah daripada PLATFORM_DOMAIN (bukan pengepala Host pelawat). */
function mail_base_url(): ?string { $u=sid()>0?rtrim(setting('site_url'),'/'):''; return $u!==''?$u:(platform_domain()!==''?tenant_base_url():null); }
function mail_ready(): bool { return mail_base_url()!==null && filter_var(mcfg('mail_from'),FILTER_VALIDATE_EMAIL)!==false; }
function mail_clean_header(string $s): string { return trim(preg_replace('/[\r\n\t]+/',' ',$s)); }
function mail_encode_header(string $s): string { $s=mail_clean_header($s); return preg_match('/[^\x20-\x7e]/',$s)?'=?UTF-8?B?'.base64_encode($s).'?=':$s; }

/** Hantar e-mel (teks + HTML ringkas). Pulangkan true jika diterima oleh pelayan mel. */
/** Templat HTML e-mel: kepala berwarna tema + logo, salam, butang tindakan (pautan bersendirian),
 *  jadual butiran (baris "Label: nilai"), nota keselamatan dan kaki. Jadual + gaya sebaris untuk Gmail/Outlook. */
function mail_html(string $subject,string $text): string {
    ['primary'=>$p,'dark'=>$d]=theme_colors(); $soft=mix_hex($p,'#ffffff',0.08); $line='#e7e9ee'; $ink='#1f2430'; $mut='#6b7280';
    $base=mail_base_url(); $logo=(sid()>0 && $base && logo_url())?$base.logo_url():null; $school=sid()>0?school_name():platform_name(); $app=sid()>0?app_name():platform_name();
    $sec=preg_match('/kata laluan|percubaan|keselamatan/i',$subject)===1;
    $label=function(string $url) use($subject): string {
        foreach(['sahkan'=>'Sahkan e-mel saya','page=reset'=>'Tetapkan kata laluan baharu','page=lupa'=>'Set semula kata laluan','page=login'=>'Log masuk sekarang','page=users'=>'Semak permohonan'] as $k=>$v) if(stripos($url,$k)!==false) return $v;
        return 'Buka pautan'; };
    $lines=preg_split('/\R/',trim($text)); $blocks=[]; $para=[]; $kv=[];
    $flush=function() use(&$para,&$kv,&$blocks){ if($para){ $blocks[]=['p',implode("\n",$para)]; $para=[]; } if($kv){ $blocks[]=['kv',$kv]; $kv=[]; } };
    foreach($lines as $ln){ $t=trim($ln);
        if($t===''){ $flush(); continue; }
        if(preg_match('~^https?://\S+$~',$t)){ $flush(); $blocks[]=['btn',$t]; continue; }
        if(preg_match('~^(.*?)(https?://\S+)$~u',$t,$m) && mb_strlen($m[1])<90){ $flush(); $blocks[]=['p',rtrim($m[1],' :')]; $blocks[]=['btn',$m[2]]; continue; }
        if(preg_match('/^([A-Za-zÀ-ÿ .\-]{2,24}):\s+(.+)$/u',$t,$m) && !str_contains($m[2],'://')){ if($para){ $blocks[]=['p',implode("\n",$para)]; $para=[]; } $kv[]=[$m[1],$m[2]]; continue; }
        if($kv){ $blocks[]=['kv',$kv]; $kv=[]; } $para[]=$t; }
    $flush();
    $pre=''; foreach($blocks as $b) if($b[0]==='p' && !preg_match('/^assalamualaikum/i',$b[1])){ $pre=mb_substr($b[1],0,140); break; }
    $body=''; $n=count($blocks);
    foreach($blocks as $k=>[$type,$v]){
        if($type==='p'){ $isGreet=$k===0 && preg_match('/^(assalamualaikum|salam|hai|kepada)/i',$v);
            $isNote=$k===$n-1 && $n>1 && preg_match('/^(jika|sekiranya|semak|pentadbir akan)/i',$v);
            if($isGreet) $body.='<p style="margin:0 0 16px;font-size:16px;font-weight:600;color:'.$ink.'">'.nl2br(e($v)).'</p>';
            elseif($isNote) $body.='<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:22px 0 0"><tr><td style="background:'.($sec?'#fff7ed':'#f6f7f9').';border-left:3px solid '.($sec?'#ea580c':'#cbd0d8').';border-radius:6px;padding:12px 14px;font-size:13px;line-height:1.55;color:'.($sec?'#9a3412':$mut).'">'.nl2br(e($v)).'</td></tr></table>';
            else $body.='<p style="margin:0 0 14px;font-size:15px;line-height:1.65;color:'.$ink.'">'.nl2br(e($v)).'</p>'; }
        elseif($type==='kv'){ $body.='<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:4px 0 18px;border:1px solid '.$line.';border-radius:10px;border-collapse:separate;overflow:hidden">';
            foreach($v as $r=>[$a,$bv]) $body.='<tr><td style="padding:10px 14px;font-size:13px;color:'.$mut.';width:34%;'.($r?'border-top:1px solid '.$line.';':'').'background:#fafbfc">'.e($a).'</td><td style="padding:10px 14px;font-size:14px;font-weight:600;color:'.$ink.';'.($r?'border-top:1px solid '.$line.';':'').'">'.e($bv).'</td></tr>';
            $body.='</table>'; }
        else { $u=e($v); $body.='<table role="presentation" cellpadding="0" cellspacing="0" style="margin:8px 0 10px"><tr><td style="border-radius:10px;background:'.$p.'"><a href="'.$u.'" style="display:inline-block;padding:13px 26px;font-size:15px;font-weight:700;color:#ffffff;text-decoration:none;border-radius:10px">'.e($label($v)).' &rarr;</a></td></tr></table>'
            .'<p style="margin:0 0 18px;font-size:12px;line-height:1.5;color:'.$mut.'">Butang tidak berfungsi? Salin pautan ini ke pelayar:<br><a href="'.$u.'" style="color:'.$p.';word-break:break-all">'.$u.'</a></p>'; }
    }
    $badge=$sec?'<span style="display:inline-block;margin:0 0 14px;padding:4px 10px;border-radius:999px;background:#fff7ed;color:#c2410c;font-size:11px;font-weight:700;letter-spacing:.06em">&#128274; KESELAMATAN AKAUN</span><br>':'';
    return '<!doctype html><html lang="ms"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="color-scheme" content="light"><title>'.e($subject).'</title></head>'
    .'<body style="margin:0;padding:0;background:#eef0f4;font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,Helvetica,Arial,sans-serif;-webkit-font-smoothing:antialiased">'
    
    .'<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#eef0f4"><tr><td align="center" style="padding:28px 14px">'
    .'<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:580px">'
    // kepala
    .'<tr><td style="background:'.$d.';border-radius:16px 16px 0 0;padding:22px 26px"><table role="presentation" cellpadding="0" cellspacing="0"><tr>'
    .($logo?'<td style="padding-right:14px;vertical-align:middle"><img src="'.e($logo).'" width="44" height="44" alt="" style="display:block;width:44px;height:44px;border-radius:10px;background:#ffffff"></td>':'')
    .'<td style="vertical-align:middle"><div style="font-size:16px;font-weight:700;color:#ffffff;letter-spacing:.01em">'.e($school).'</div><div style="font-size:12px;color:rgba(255,255,255,.72);margin-top:2px">'.e($app).' &middot; Agihan tugas guru</div></td></tr></table></td></tr>'
    .'<tr><td style="height:4px;background:'.$p.';line-height:4px;font-size:0">&nbsp;</td></tr>'
    // kandungan
    .'<tr><td style="background:#ffffff;padding:30px 28px 28px;border-left:1px solid '.$line.';border-right:1px solid '.$line.'">'.$badge
    .'<h1 style="margin:0 0 18px;font-size:21px;line-height:1.3;color:'.$ink.';font-weight:700">'.e($subject).'</h1>'.$body.'</td></tr>'
    // kaki
    .'<tr><td style="background:'.$soft.';border:1px solid '.$line.';border-top:0;border-radius:0 0 16px 16px;padding:18px 28px;font-size:12px;line-height:1.6;color:'.$mut.'">'
    .'E-mel automatik daripada <strong style="color:'.$ink.'">'.e($app).'</strong> &middot; '.e($school).'.<br>Sila jangan balas e-mel ini.'.($base?' <a href="'.e($base).'" style="color:'.$p.';text-decoration:none">'.e(preg_replace('~^https?://~','',$base)).'</a>':'').'</td></tr>'
    .'</table><p style="margin:16px 0 0;font-size:11px;color:#9aa1ad">Anda menerima e-mel ini kerana akaun anda berdaftar di '.e($school).'.</p></td></tr></table></body></html>';
}
function send_mail(string $to,string $toName,string $subject,string $text,?string &$error=null): bool {
    if(function_exists('demo_mode') && demo_mode()){ $error='E-mel dimatikan dalam mod demo.'; return false; }
    $error=null;
    if(!filter_var($to,FILTER_VALIDATE_EMAIL)){ $error='Alamat penerima tidak sah.'; return false; }
    $from=mcfg('mail_from'); if(!filter_var($from,FILTER_VALIDATE_EMAIL)){ $error='Alamat pengirim belum ditetapkan (e-mel sekolah atau e-mel platform).'; return false; }
    $fromName=mail_from_school()&&setting('mail_from_name')!==''?setting('mail_from_name'):(sid()>0?school_name():(pset('mail_from_name')?:platform_name()));
    $boundary='=_'.bin2hex(random_bytes(12)); $domain=substr(strrchr($from,'@'),1);
    $html=mail_html($subject,$text);
    $headers=['Date: '.date('r'),'From: '.mail_encode_header($fromName).' <'.$from.'>','To: '.($toName!==''?mail_encode_header($toName).' ':'').'<'.$to.'>','Subject: '.mail_encode_header($subject),
        'Message-ID: <'.bin2hex(random_bytes(16)).'@'.$domain.'>','MIME-Version: 1.0','Content-Type: multipart/alternative; boundary="'.$boundary.'"','Auto-Submitted: auto-generated','X-Auto-Response-Suppress: All'];
    $body="This is a multi-part message in MIME format.\r\n\r\n--$boundary\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n".chunk_split(base64_encode($text))
        ."--$boundary\r\nContent-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n".chunk_split(base64_encode($html))."--$boundary--\r\n";
    try {
        $m=mail_method();
        if($m==='log'){
            $dir=dirname(__DIR__).'/storage/mail'; if(!is_dir($dir)) mkdir($dir,0700,true);
            file_put_contents($dir.'/'.date('Ymd-His').'-'.bin2hex(random_bytes(4)).'.eml',implode("\r\n",$headers)."\r\nX-Text: ".base64_encode($text)."\r\n\r\n".$body); return true;
        }
        if($m==='smtp'){
            if(smtp_send($from,$to,implode("\r\n",$headers)."\r\n\r\n".$body,$error)) return true;
            // Sesetengah penapis (cth. Gmail 552 5.7.0) menolak versi HTML: cuba sekali lagi sebagai teks biasa.
            if(!preg_match('/\b552\b|5\.7\.0/',(string)$error)) return false;
            $h2=array_map(fn($x)=>str_starts_with($x,'Content-Type:')?'Content-Type: text/plain; charset=UTF-8':$x,$headers); $h2[]='Content-Transfer-Encoding: base64';
            $first=$error; if(smtp_send($from,$to,implode("\r\n",$h2)."\r\n\r\n".chunk_split(base64_encode($text)),$error)){ error_log('Sekolah mail: HTML ditolak, dihantar sebagai teks biasa. '.$first); $error=null; return true; }
            return false;
        }
        // mail(): To & Subject dihantar sebagai parameter; buang daripada header.
        $h=array_values(array_filter($headers,fn($x)=>!str_starts_with($x,'To:') && !str_starts_with($x,'Subject:')));
        $ok=@mail($to,mail_encode_header($subject),$body,implode("\r\n",$h),'-f'.$from);
        if(!$ok) $error='Fungsi mail() pelayan gagal. Cuba tetapan SMTP.'; return $ok;
    } catch(Throwable $ex){ $error=$ex->getMessage(); error_log('Sekolah mail: '.$ex->getMessage()); return false; }
}

/** Klien SMTP minimum (RFC 5321) dengan STARTTLS/SSL dan AUTH LOGIN. Sijil TLS disahkan. */
function smtp_send(string $from,string $to,string $data,?string &$error=null): bool {
    $host=mcfg('smtp_host'); $port=(int)mcfg('smtp_port'); $secure=mcfg('smtp_secure');
    if($host==='' || $port<1){ $error='Tetapan SMTP belum lengkap.'; return false; }
    $ctx=stream_context_create(['ssl'=>['verify_peer'=>true,'verify_peer_name'=>true,'peer_name'=>$host,'SNI_enabled'=>true]]);
    $fp=@stream_socket_client(($secure==='ssl'?'ssl://':'tcp://').$host.':'.$port,$errno,$errstr,15,STREAM_CLIENT_CONNECT,$ctx);
    if(!$fp){ $error='Tidak dapat menyambung ke '.$host.':'.$port.' ('.$errstr.')'; return false; }
    stream_set_timeout($fp,20);
    $read=function() use($fp): array { $code=0; $msg=''; while(($line=fgets($fp,1024))!==false){ $code=(int)substr($line,0,3); $msg.=$line; if(strlen($line)<4 || $line[3]!=='-') break; } return [$code,trim($msg)]; };
    $cmd=function(string $c,array $ok) use($fp,$read): array { fwrite($fp,$c."\r\n"); [$code,$msg]=$read(); if(!in_array($code,$ok,true)) throw new RuntimeException('SMTP: '.preg_replace('/\s+/',' ',$msg)); return [$code,$msg]; };
    try {
        [$code,$msg]=$read(); if($code!==220) throw new RuntimeException('SMTP: '.$msg);
        $ehlo=preg_replace('/[^A-Za-z0-9.-]/','',parse_url((string)mail_base_url(),PHP_URL_HOST)?:'localhost');
        [,$caps]=$cmd('EHLO '.$ehlo,[250]);
        if($secure==='tls'){
            $cmd('STARTTLS',[220]);
            if(!stream_socket_enable_crypto($fp,true,STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT|STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT)) throw new RuntimeException('STARTTLS gagal (sijil tidak sah?).');
            [,$caps]=$cmd('EHLO '.$ehlo,[250]);
        }
        $user=mcfg('smtp_user'); $pass=mail_smtp_pass();
        if($user!==''){
            if($secure==='none' && !str_contains($host,'localhost') && $host!=='127.0.0.1') throw new RuntimeException('Enggan menghantar kata laluan SMTP tanpa penyulitan. Pilih TLS atau SSL.');
            $cmd('AUTH LOGIN',[334]); $cmd(base64_encode($user),[334]); $cmd(base64_encode($pass),[235]);
        }
        $cmd('MAIL FROM:<'.$from.'>',[250]); $cmd('RCPT TO:<'.$to.'>',[250,251]); $cmd('DATA',[354]);
        $data=preg_replace('/^\./m','..',str_replace(["\r\n","\r"],"\n",$data)); $data=str_replace("\n","\r\n",$data);
        $cmd($data."\r\n.",[250]); @fwrite($fp,"QUIT\r\n"); fclose($fp); return true;
    } catch(Throwable $ex){ $error=$ex->getMessage(); @fclose($fp); return false; }
}
