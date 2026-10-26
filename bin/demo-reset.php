<?php
// Reset data demo SEMUA sekolah (DEMO_MODE=1 sahaja). Untuk Cron Job cPanel, cth. setiap hari 3:00 pagi:
//   0 3 * * *  php /home/AKAUN/urustugas/bin/demo-reset.php >/dev/null 2>&1
// Tanpa cron, setiap sekolah demo tetap direset secara automatik pada lawatan pertama setiap hari.
// Satu sekolah sahaja: php bin/school.php demo-reset KOD
if(PHP_SAPI!=='cli') exit;
$argv=[$argv[0],'demo-reset','--all'];
require __DIR__.'/school.php';
