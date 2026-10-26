<?php
// Sediakan pemasangan UJIAN untuk suite ujian lama (tests/*_integration.py) — APP_ENV=local & pangkalan data KOSONG sahaja.
//   .env: APP_ENV=local, DEMO_MODE=0, REQUIRE_2FA=1, PLATFORM_DOMAIN=localhost:PORT, TENANT_PRIMARY=subdomain
//   php tests/setup_test_install.php   → satu sekolah DEM0001 (data demo, admin@demo.test / Demo-Admin-2026)
// Mod subdomain digunakan supaya URL & HTML sama seperti edisi satu sekolah (TEST_URL=http://dem0001.localhost:PORT).
if(PHP_SAPI!=='cli') exit;
require dirname(__DIR__).'/app/bootstrap.php';
foreach(['settings','install','integrity','images','users','demo','platform'] as $f) require_once dirname(__DIR__).'/app/'.$f.'.php';
if(env('APP_ENV')!=='local') exit("Hanya untuk APP_ENV=local.\n");
if(env('TENANT_PRIMARY')!=='subdomain' || platform_domain()==='') exit("Tetapkan PLATFORM_DOMAIN=localhost:PORT dan TENANT_PRIMARY=subdomain dalam .env.\n");
install_schema();
create_platform_admin('Platform Ujian','platform@test.test','Platform-Ujian-2026');
[$s]=school_create('DEM0001','SMK Ujian','SMK',DEMO_ACCOUNTS[0][1],DEMO_ACCOUNTS[0][2]);
with_school($s,function(){ school_wipe(); demo_seed('SMK'); });   // data demo tanpa tanda "sekolah demo" (tiada reset harian/sekatan demo)
write_install_lock('test');
echo "Pemasangan ujian sedia: ".school_url('DEM0001','/')."\n";
