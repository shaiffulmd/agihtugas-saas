<?php
// Naik taraf pangkalan data sedia ada. Selamat dijalankan berulang kali; tiada jadual atau data dipadam.
if(PHP_SAPI!=='cli') exit;
require dirname(__DIR__).'/app/bootstrap.php';
require dirname(__DIR__).'/app/install.php';
if(!table_exists('schools')) { fwrite(STDERR,"Sistem belum dipasang. Jalankan: php bin/install.php\n"); exit(1); }
foreach(run_upgrade() as $line) echo "- $line\n";
echo "Pangkalan data dinaik taraf ke versi ".APP_VERSION.".\n";
