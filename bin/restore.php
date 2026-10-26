<?php
// Pulihkan sandaran UrusTugas (.kbak).
//   php bin/restore.php FAIL.kbak               → pulihkan ke pangkalan data KOSONG dalam .env
//   php bin/restore.php FAIL.kbak --sql OUT.sql → nyahsulit sahaja kepada fail SQL (import melalui phpMyAdmin)
// Kata laluan sandaran diperlukan. APP_KEY dalam .env mesti SAMA dengan pelayan asal untuk membaca medan tersulit.
if(PHP_SAPI!=='cli') exit;
require dirname(__DIR__).'/app/bootstrap.php';
require_once dirname(__DIR__).'/app/install.php';
require_once dirname(__DIR__).'/app/integrity.php';
require_once dirname(__DIR__).'/app/backup.php';
$file=$argv[1]??''; $sqlOut=($argv[2]??'')==='--sql'?($argv[3]??''):null;
if($file==='' || !is_file($file) || ($sqlOut!==null && $sqlOut==='')) { fwrite(STDERR,"Guna: php bin/restore.php FAIL.kbak [--sql OUT.sql]\n"); exit(1); }
if($sqlOut===null){
    try { if(db_table_count()>0) { fwrite(STDERR,"Pangkalan data ".env('DB_NAME')." tidak kosong. Pulihkan ke pangkalan data KOSONG sahaja (cipta yang baharu dan kemas kini .env).\n"); exit(1); } }
    catch(Throwable $ex) { fwrite(STDERR,"Tidak dapat menyambung ke pangkalan data: ".$ex->getMessage()."\n"); exit(1); }
}
$pass=cli_secret('Kata laluan sandaran: ');
$tmp=tempnam(sys_get_temp_dir(),'urustugas'); @chmod($tmp,0600);
try {
    echo "Menyahsulit…\n"; bk_decrypt_file($file,$tmp,$pass); sodium_memzero($pass);
    $gz=gzopen($tmp,'rb'); if(!$gz) throw new RuntimeException('Kandungan sandaran rosak.');
    if($sqlOut!==null){
        $out=fopen($sqlOut,'wb'); if(!$out) throw new RuntimeException('Tidak dapat menulis '.$sqlOut);
        while(!gzeof($gz)) fwrite($out,(string)gzread($gz,1048576)); fclose($out); gzclose($gz); @chmod($sqlOut,0600);
        echo "Fail SQL ditulis: $sqlOut\nImport ke pangkalan data KOSONG (phpMyAdmin → Import). Padam fail ini selepas selesai — ia mengandungi data murid.\n"; exit(0);
    }
    if(cli_ask('Pulihkan ke pangkalan data '.env('DB_NAME').'? Taip PULIH untuk teruskan: ')!=='PULIH') exit("Dibatalkan.\n");
    $n=0; $complete=false;
    while(($line=gzgets($gz))!==false){
        $line=rtrim($line,"\r\n"); if($line==='-- SELESAI') $complete=true;
        if($line==='' || str_starts_with($line,'--')) continue;
        db()->exec($line); if(++$n%200===0) echo "  $n pernyataan…\n";
    }
    gzclose($gz);
    if(!$complete) throw new RuntimeException('Sandaran tidak lengkap (penanda akhir tiada).');
    write_install_lock('restore');
    echo "Selesai: $n pernyataan dipulihkan.\nBuka laman dan log masuk. Jika versi kod lebih baharu, sistem akan meminta naik taraf secara automatik.\n";
} catch(Throwable $ex) { fwrite(STDERR,'Ralat: '.$ex->getMessage()."\n"); exit(1); }
finally { @unlink($tmp); }
