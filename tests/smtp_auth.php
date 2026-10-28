<?php
// Ujian pengesahan SMTP: petunjuk ralat 535 ikut pembekal & kata laluan dipangkas (pelayan SMTP olok-olok setempat, tanpa rangkaian luar).
//   php tests/smtp_auth.php
declare(strict_types=1);
if(PHP_SAPI!=='cli') exit;
require dirname(__DIR__).'/app/bootstrap.php';
foreach(['settings','integrity','platform','mailer'] as $f) require_once dirname(__DIR__).'/app/'.$f.'.php';
$checks=0;
function check(bool $c,string $label): void { global $checks; if(!$c) throw new RuntimeException('GAGAL: '.$label); $checks++; echo "  ✓ $label\n"; }

echo "Petunjuk ralat 535\n";
check(str_contains(smtp_auth_hint('smtp.mail.me.com','noreply@apasaja.my'),'mesti alamat @icloud.com') && str_contains(smtp_auth_hint('smtp.mail.me.com','noreply@apasaja.my'),'noreply@apasaja.my'),'iCloud + alamat domain sendiri → minta alamat @icloud.com');
check(str_contains(smtp_auth_hint('smtp.mail.me.com',' Nama@iCloud.com '),'app-specific password'),'iCloud + alamat @icloud.com → semak app-specific password');
check(str_contains(smtp_auth_hint('smtp.mail.me.com','nama@me.com'),'app-specific password'),'alamat @me.com diterima sebagai Apple ID');
check(str_contains(smtp_auth_hint('smtp.gmail.com','a@gmail.com'),'App Password') && str_contains(smtp_auth_hint('smtp.office365.com','a@b.my'),'SMTP AUTH') && str_contains(smtp_auth_hint('mail.sekolah.my','a@b.my'),'Semak nama pengguna'),'Gmail, Microsoft 365 & lain-lain');

echo "Pelayan SMTP olok-olok\n";
// Pelayan: terima AUTH LOGIN; lulus hanya jika pengguna "nama@icloud.com" & kata laluan tepat "abcd-efgh-ijkl-mnop" (tanpa ruang).
$srv=stream_socket_server('tcp://127.0.0.1:0',$en,$es); $port=(int)substr(strrchr(stream_socket_get_name($srv,false),':'),1);
$pid=pcntl_fork();
if($pid===0){
    for($n=0;$n<3;$n++){ $c=@stream_socket_accept($srv,10); if(!$c) break; $w=fn($s)=>fwrite($c,$s."\r\n"); $w('220 olok-olok');
        $u=''; $p=''; $st='';
        while(($l=fgets($c))!==false){ $l=rtrim($l,"\r\n");
            if($st==='u'){ $u=base64_decode($l); $st='p'; $w('334 UGFzc3dvcmQ6'); continue; }
            if($st==='p'){ $p=base64_decode($l); $st=''; $w($u==='nama@icloud.com' && $p==='abcd-efgh-ijkl-mnop'?'235 2.7.0 OK':'535 5.7.8 Error: authentication failed'); continue; }
            if($st==='d'){ if($l==='.'){ $st=''; $w('250 OK'); } continue; }
            if(str_starts_with($l,'EHLO')) $w("250-olok\r\n250 AUTH LOGIN"); elseif($l==='AUTH LOGIN'){ $st='u'; $w('334 VXNlcm5hbWU6'); }
            elseif($l==='DATA'){ $st='d'; $w('354 go'); } elseif($l==='QUIT'){ $w('221 bye'); break; } else $w('250 OK'); }
        fclose($c); }
    exit(0);
}
$old=pset_all(); $keys=['mail_method','mail_from','smtp_host','smtp_port','smtp_secure','smtp_user','smtp_pass'];
try {
    $send=function(string $user,string $pass)use($port): ?string { pset_set(['mail_method'=>'smtp','mail_from'=>'noreply@apasaja.my','smtp_host'=>'127.0.0.1','smtp_port'=>(string)$port,'smtp_secure'=>'none','smtp_user'=>$user,'smtp_pass'=>pencrypt($pass)]);
        $err=null; return smtp_send('noreply@apasaja.my','ujian@contoh.test',"Subject: x\r\n\r\nx",$err)?null:$err; };
    $e=$send('noreply@apasaja.my','abcd-efgh-ijkl-mnop'); check($e!==null && str_starts_with($e,'SMTP: 535'),'domain sendiri sebagai pengguna → 535');
    check(str_contains((string)$e,'Semak nama pengguna'),'ralat 535 disertakan petunjuk');
    check($send('nama@icloud.com',"abcd-efgh-ijkl-mnop \n")===null,'kata laluan dengan ruang / baris baharu di hujung (tertampal) → berjaya kerana dipangkas');
    check($send('nama@icloud.com','abcd-efgh-ijkl-mnop')===null,'pengguna @icloud.com + app password → berjaya');
} finally { pset_set(array_intersect_key($old,array_flip($keys))); pcntl_waitpid($pid,$st); }
echo "\n$checks semakan SMTP lulus.\n";
