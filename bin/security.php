<?php
// Pemulihan keselamatan melalui CLI (jika terkunci keluar daripada panel platform).
//   php bin/security.php status
//   php bin/security.php unblock IP        buang semua sekatan aktif yang meliputi IP ini (platform & sekolah)
//   php bin/security.php allow-clear       matikan "panel platform hanya dari senarai IP"
//   php bin/security.php ip-off            matikan Perlindungan IP sepenuhnya (suis utama)
//   php bin/security.php emergency-off     matikan mod kecemasan
//   php bin/security.php captcha-off       kembali ke CAPTCHA sendiri
//   php bin/security.php audit-verify      sahkan semua rantai audit (kod keluar 2 jika bermasalah)
if(PHP_SAPI!=='cli') exit;
require dirname(__DIR__).'/app/bootstrap.php';
foreach(['settings','install','platform'] as $f) require_once dirname(__DIR__).'/app/'.$f.'.php';
if(!is_installed()) { fwrite(STDERR,"Sistem belum dipasang.\n"); exit(1); }
$cmd=$argv[1]??'status'; $arg=$argv[2]??'';
try {
    if($cmd==='status'){
        printf("Perlindungan IP : %s\nSekatan aktif  : %d\nPanel ikut IP  : %s (%s)\nMod kecemasan  : %s\nCAPTCHA        : %s\nWajib 2FA semua: %s\n",ip_protect_on()?'hidup':'MATI',
            (int)query('SELECT COUNT(*) FROM ip_blocks WHERE expires_at IS NULL OR expires_at>NOW()')->fetchColumn(),pset('ip_platform_wajib')==='1'?'ya':'tidak',str_replace("\n",', ',pset('ip_platform'))?:'-',
            EMERGENCY_MODES[emergency_mode()],cap_external()?CAP_PROVIDERS[cap_provider()]['name']:'sendiri',twofa_forced_all()?'ya':'tidak');
        exit(0);
    }
    if($cmd==='unblock'){
        if(!filter_var($arg,FILTER_VALIDATE_IP)) throw new DomainException('Guna: php bin/security.php unblock IP');
        $n=0; foreach(query('SELECT * FROM ip_blocks WHERE expires_at IS NULL OR expires_at>NOW()')->fetchAll() as $r) if(ip_in_cidr($arg,$r['ip'])){ ip_block_remove((int)$r['id']); $n++; }
        platform_log('cli_unblock',null,$arg.' ('.$n.' sekatan)'); echo "$n sekatan dibuang untuk $arg\n"; exit(0);
    }
    $set=['allow-clear'=>['ip_platform_wajib'=>'0'],'ip-off'=>['ip_lindung'=>'0'],'emergency-off'=>['kecemasan'=>''],'captcha-off'=>['cap_luar'=>'0']];
    if(isset($set[$cmd])){ pset_set($set[$cmd]); platform_log('cli_'.str_replace('-','_',$cmd)); echo "Selesai: $cmd\n"; exit(0); }
    if($cmd==='audit-verify'){
        $bad=0; foreach(audit_verify_all() as $r){ printf("%-9s %7d baris  %s\n",$r['code'],$r['rows'],$r['ok']?'sah':'BERMASALAH'); foreach($r['problems'] as $m) echo "          - $m\n"; $bad+=$r['ok']?0:1; }
        exit($bad?2:0);
    }
    throw new DomainException('Arahan tidak dikenali. Lihat komen di bahagian atas bin/security.php.');
} catch(DomainException $ex){ fwrite(STDERR,$ex->getMessage()."\n"); exit(1); }
