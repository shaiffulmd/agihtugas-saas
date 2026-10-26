<?php
// Sediakan 2 sekolah ujian enjin (UJT0001 SMK, UJT0002 SK) pada pemasangan sedia ada (.env) dan cetak butiran log masuk (JSON).
// Sekolah lama dengan kod sama dipadam dahulu. Akaun: admin (2FA, rahsia TOTP dicetak), pk, head, teacher (dipaut ke guru pertama).
//   php tests/setup_engine_test.php > /tmp/ujian.json
if(PHP_SAPI!=='cli') exit;
require dirname(__DIR__).'/app/bootstrap.php';
foreach(['settings','install','integrity','images','users','demo','platform','enjin','tugas_auto','kelulusan','surat_lantikan'] as $f) require_once dirname(__DIR__).'/app/'.$f.'.php';
if(app_status()!=='ready') { fwrite(STDERR,"Pemasangan belum sedia.\n"); exit(1); }
$out=[];
foreach([['UJT0001','SMK Ujian Enjin','SMK'],['UJT0002','SK Ujian Enjin','SK']] as [$code,$name,$type]){
    if($s=school_find($code)) school_delete($s);
    $pw='Ujian-Enjin-'.$code.'-2026'; $secret=base32_encode(random_bytes(20)); $dom=strtolower($code).'.test';
    [$s]=school_create($code,$name,$type,'Pentadbir '.$code,'admin@'.$dom,$pw,['must_change'=>false]);
    with_school($s,function() use($pw,$secret,$dom,&$out,$code){
        query('UPDATE users SET totp_secret=? WHERE school_id='.sid(),[encrypt_value($secret)]);
        query('INSERT INTO teachers (school_id,name,email,specialty,capacity) VALUES ('.sid().',?,?,?,?)',['Guru Pertama '.$code,'guru1@'.$dom,'Matematik',20]);
        $t=(int)db()->lastInsertId();
        foreach([['pk','PK '.$code],['head','Ketua '.$code],['teacher','Guru '.$code]] as [$role,$n])
            query('INSERT INTO users (school_id,name,email,password_hash,role,teacher_id) VALUES ('.sid().',?,?,?,?,?)',[$n,$role.'@'.$dom,hash_password($pw),$role,$role==='teacher'?$t:null]);
        engine_setting_set(['principal'=>'Ketua '.$code,'session_year'=>'2027','letter_reference'=>'UJI.100-1/5/1']);
        $out[$code]=['school_id'=>sid(),'password'=>$pw,'totp'=>$secret,'teacher_id'=>$t,'emails'=>['admin'=>'admin@'.$dom,'pk'=>'pk@'.$dom,'head'=>'head@'.$dom,'teacher'=>'teacher@'.$dom]];
    });
}
echo json_encode($out,JSON_PRETTY_PRINT),"\n";
