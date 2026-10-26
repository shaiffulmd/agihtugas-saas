<?php
// Urus sekolah melalui CLI (edisi multi).
//   php bin/school.php list
//   php bin/school.php create KOD "Nama sekolah" admin@emel [--nama-admin="Nama"] [--sk] [--demo]
//                                   → kata laluan sementara Pentadbir Utama dipaparkan sekali
//   php bin/school.php demo-reset KOD|--all   (DEMO_MODE=1 sahaja: jana semula data demo sekolah itu)
//   php bin/school.php suspend|activate KOD
if(PHP_SAPI!=='cli') exit;
require dirname(__DIR__).'/app/bootstrap.php';
foreach(['settings','install','integrity','images','users','demo','platform'] as $f) require_once dirname(__DIR__).'/app/'.$f.'.php';
if(!is_installed()) { fwrite(STDERR,"Sistem belum dipasang. Jalankan: php bin/install.php\n"); exit(1); }
$cmd=$argv[1]??'list'; $args=array_values(array_filter(array_slice($argv,2),fn($a)=>!str_starts_with($a,'--')));
$opt=[]; foreach(array_slice($argv,2) as $a) if(preg_match('/^--([a-z-]+)(?:=(.*))?$/',$a,$m)) $opt[$m[1]]=$m[2]??'1';
try {
    if($cmd==='list'){
        foreach(platform_schools() as $s) printf("%-8s %-10s %4d pengguna %5d guru  %s\n",$s['code'],$s['status'],$s['users'],$s['teachers'],$s['name']);
        exit(0);
    }
    if($cmd==='create'){
        [$code,$name,$email]=$args+['','',''];
        [$s,$pw]=school_create($code,$name,isset($opt['sk'])?'SK':'SMK',$opt['nama-admin']??'Pentadbir Utama',$email);
        platform_log('school_created',(int)$s['id'],$s['code'].' (cli)');
        echo "Sekolah {$s['code']} dicipta: ".school_url($s['code'],'/')."\nPentadbir Utama: $email\nKata laluan sementara: $pw\n";
        if(isset($opt['demo'])){ if(!demo_env()) throw new DomainException('--demo memerlukan DEMO_MODE=1.'); with_school($s,'demo_reset'); echo "Data demo dijana.\n"; }
        exit(0);
    }
    if($cmd==='demo-reset'){
        if(!demo_env()) throw new DomainException('Ditolak: DEMO_MODE=1 tiada dalam .env. Arahan ini MEMADAM data sekolah.');
        // --all: sekolah bertanda demo sahaja (sekolah sebenar dalam pemasangan demo tidak disentuh)
        $list=isset($opt['all'])?array_values(array_filter(query('SELECT * FROM schools ORDER BY code')->fetchAll(),fn($s)=>with_school($s,'demo_mode'))):array_filter([school_find($args[0]??'')]);
        if(!$list) throw new DomainException('Sekolah tidak dijumpai.');
        foreach($list as $s){ with_school($s,'demo_reset'); echo "Demo {$s['code']} direset pada ".date('Y-m-d H:i:s')."\n"; }
        exit(0);
    }
    if($cmd==='suspend' || $cmd==='activate'){
        $s=school_find($args[0]??''); if(!$s) throw new DomainException('Sekolah tidak dijumpai.');
        query('UPDATE schools SET status=? WHERE id=?',[$cmd==='suspend'?'digantung':'aktif',$s['id']]); platform_log($cmd==='suspend'?'school_suspended':'school_activated',(int)$s['id'],'cli');
        echo "{$s['code']}: ".($cmd==='suspend'?'digantung':'aktif')."\n"; exit(0);
    }
    fwrite(STDERR,"Guna: php bin/school.php list|create|demo-reset|suspend|activate (lihat komen dalam fail)\n"); exit(1);
} catch(DomainException $ex) { fwrite(STDERR,'Ralat: '.$ex->getMessage()."\n"); exit(1); }
