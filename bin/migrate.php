<?php
// Dikekalkan untuk keserasian. Disyorkan: php bin/install.php (pemasangan lengkap & dikunci).
if(PHP_SAPI!=='cli') exit;
require dirname(__DIR__).'/app/bootstrap.php';
require dirname(__DIR__).'/app/install.php';
key_bytes();
try { install_schema(); write_install_lock('cli-migrate'); }
catch(DomainException $ex) { fwrite(STDERR,'Ralat: '.$ex->getMessage()."\n"); exit(1); }
echo "Skema berjaya dipasang dan pemasangan dikunci. Cipta pentadbir platform: php bin/user.php platform create\n";
