<?php
// Pemasang CLI satu kali (edisi multi): semak keperluan, pasang skema, cipta Pentadbir Platform pertama, kunci pemasangan.
// Sekolah ditambah kemudian melalui /platform/ atau: php bin/school.php create KOD "Nama sekolah" admin@emel
if(PHP_SAPI!=='cli') exit;
require dirname(__DIR__).'/app/bootstrap.php';
require dirname(__DIR__).'/app/install.php';
echo "Pemasang UrusTugas ".APP_VERSION."\n\nSemakan keperluan:\n";
$req=install_requirements(false); cli_print_requirements($req);
if(!requirements_ok($req)) { fwrite(STDERR,"\nBetulkan item [GAGAL] dahulu.\n"); exit(1); }
if(is_installed()) { fwrite(STDERR,"\nSistem telah dipasang (storage/installed.lock wujud atau jadual sudah ada). Pemasang dikunci.\nUntuk pemasangan sedia ada, jalankan: php bin/upgrade.php\n"); exit(1); }
if(db_table_count()>0) { fwrite(STDERR,"\nPangkalan data ".env('DB_NAME')." tidak kosong. Gunakan pangkalan data baharu yang kosong.\n"); exit(1); }
echo "\nPentadbir Platform pertama\n";
try {
    $name=cli_ask('Nama: '); $email=cli_ask('E-mel: ');
    $password=cli_secret('Kata laluan (12–128 aksara): '); $confirm=cli_secret('Ulang kata laluan: ');
    [$name,$email]=validate_admin($name,$email,$password,$confirm);
    if(cli_ask("\nPasang skema pada pangkalan data ".env('DB_NAME')."? Taip PASANG untuk teruskan: ")!=='PASANG') exit("Dibatalkan.\n");
    install_schema();
    create_platform_admin($name,$email,$password);
    write_install_lock('cli');
    sodium_memzero($password); sodium_memzero($confirm);
} catch(DomainException $ex) { fwrite(STDERR,'Ralat: '.$ex->getMessage()."\n"); exit(1); }
echo "\nPemasangan selesai dan dikunci (storage/installed.lock).\nLog masuk di /platform/ untuk menambah sekolah; 2FA perlu disediakan pada log masuk pertama.\n";
