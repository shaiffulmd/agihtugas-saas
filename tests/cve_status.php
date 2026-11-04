<?php
declare(strict_types=1);
/* Ujian terpencil semakan CVE PHP & MariaDB (tanpa pangkalan data atau pelayan). Run: php tests/cve_status.php */
require dirname(__DIR__).'/app/integrity.php';
$passed=0;
function check(bool $ok,string $message): void { global $passed; if(!$ok) throw new RuntimeException('FAIL: '.$message); ++$passed; echo "PASS: $message\n"; }

// Perbandingan versi dpkg
check(deb_version_compare('8.5.4-0ubuntu1.5','8.5.4-0ubuntu1.5')===0,'dpkg: versi sama');
check(deb_version_compare('8.5.4-0ubuntu1.4','8.5.4-0ubuntu1.5')<0,'dpkg: revisi lebih rendah');
check(deb_version_compare('8.5.4-0ubuntu1.10','8.5.4-0ubuntu1.5')>0,'dpkg: 1.10 > 1.5 (bukan perbandingan rentetan)');
check(deb_version_compare('8.5.4-0ubuntu1','8.5.4-0ubuntu1.1')<0,'dpkg: tanpa akhiran lebih rendah');
check(deb_version_compare('1:1.0-1','2.0-1')>0,'dpkg: epoch diutamakan');
check(deb_version_compare('1.0~rc1-1','1.0-1')<0,'dpkg: tilde lebih rendah');
check(deb_version_compare('5ubuntu0.2','5ubuntu0.1')>0,'dpkg: revisi MariaDB');

// Pakej dpkg
$tmp=tempnam(sys_get_temp_dir(),'dpkg');
file_put_contents($tmp,"Package: php8.5-cli\nStatus: install ok installed\nVersion: 8.5.4-0ubuntu1.3\n\nPackage: php8.5-fpm\nStatus: install ok installed\nArchitecture: arm64\nDescription: x\n multi baris\nVersion: 8.5.4-0ubuntu1.5\n\nPackage: php8.5-cgi\nStatus: deinstall ok config-files\nVersion: 8.5.4-0ubuntu1\n\nPackage: akhir\nStatus: install ok installed\nVersion: 1.0-1");
check(dpkg_package_version('php8.5-fpm',$tmp)==='8.5.4-0ubuntu1.5','dpkg: pakej dijumpai');
check(dpkg_package_version('php8.5-cgi',$tmp)===null,'dpkg: pakej dibuang dikira tiada');
check(dpkg_package_version('tiada',$tmp)===null,'dpkg: pakej tiada');
check(dpkg_package_version('akhir',$tmp)==='1.0-1','dpkg: stanza akhir tanpa baris kosong');
check(dpkg_package_version('php8.5-fpm',$tmp.'.tiada')===false,'dpkg: fail tidak boleh dibaca');
unlink($tmp);
check(php_package_name('fpm-fcgi','8.5.4')==='php8.5-fpm','nama pakej: fpm');
check(php_package_name('apache2handler','8.3.6')==='libapache2-mod-php8.3','nama pakej: mod_php');

// PHP: pelayan pengguna (Ubuntu 26.04)
check(php_cve_evaluate('8.5.4','8.5.4-0ubuntu1.5')[0]==='ok','PHP: pakej 26.04 dibaiki = ok');
check(php_cve_evaluate('8.5.4','8.5.4-0ubuntu1.6')[0]==='ok','PHP: pakej lebih baharu = ok');
check(php_cve_evaluate('8.5.4','8.5.4-0ubuntu1.4')[0]==='bad','PHP: pakej sebelum pembaikan = bad');
check(php_cve_evaluate('8.5.4','8.5.4-0ubuntu1.5',true)[0]==='bad','PHP: dibaiki tetapi belum mula semula = bad');
check(str_contains(php_cve_evaluate('8.5.4','8.5.4-0ubuntu1.5',true)[2],'mulakan semula'),'PHP: butiran menyuruh mula semula');
check(php_cve_evaluate('8.5.4',false)[0]==='unverified','PHP: dpkg tak boleh dibaca = perlu semakan');
check(php_cve_evaluate('8.5.4',null)[0]==='bad','PHP: bukan dari pakej = bad');
check(php_cve_evaluate('8.5.4','8.5.9-0ubuntu2.1')[0]==='bad','PHP: proses lama, pakej lain = bad');
check(php_cve_evaluate('8.5.4','8.5.4-1+ubuntu26.04.1+deb.sury.org+1')[0]==='bad','PHP: PPA bukan senarai Ubuntu = bad');
check(php_cve_evaluate('8.5.6',false)[0]==='bad','PHP: versi tiada backport diketahui = bad walaupun dpkg tak dibaca');
// Keluaran Ubuntu lain
check(php_cve_evaluate('8.3.6','8.3.6-0ubuntu0.24.04.13')[0]==='ok','PHP: 24.04 dibaiki');
check(php_cve_evaluate('8.3.6','8.3.6-0ubuntu0.24.04.12')[0]==='bad','PHP: 24.04 belum');
check(php_cve_evaluate('8.1.2-1ubuntu2.28','8.1.2-1ubuntu2.28')[0]==='ok','PHP: 22.04 dibaiki (PHP_VERSION dengan akhiran)');
check(php_cve_evaluate('8.1.2-1ubuntu2.27','8.1.2-1ubuntu2.27')[0]==='bad','PHP: 22.04 belum');
check(php_cve_evaluate('8.5.9','8.5.9-0ubuntu2.1')[0]==='ok','PHP: 26.10 dibaiki');
// Upstream
check(php_cve_evaluate('8.5.12',null)[0]==='ok','PHP: upstream dibaiki');
check(php_cve_evaluate('8.4.26',null)[0]==='bad','PHP: upstream belum');
check(php_cve_evaluate('8.6.0',null)[0]==='ok','PHP: siri lebih baharu');
check(php_cve_evaluate('8.0.30',null)[0]==='bad','PHP: siri lama');

// MariaDB
$ubuntu=db_server_info('11.8.6-MariaDB-5ubuntu0.1 from Ubuntu-log');
check($ubuntu[1]==='11.8.6' && $ubuntu[3]==='5ubuntu0.1','DB: revisi Ubuntu dibaca dari VERSION()');
check(db_server_info('11.8.6-MariaDB-log')[3]==='','DB: akhiran log bukan revisi');
check(db_server_info('10.11.6-MariaDB-0+deb12u1')[3]==='','DB: pakej Debian bukan revisi Ubuntu');
check(db_cve_status('MariaDB','11.8.6','5ubuntu0.1')[0]==='bad','DB: pakej 26.04 semasa kekal bad');
check(str_contains(db_cve_status('MariaDB','11.8.6','5ubuntu0.1')[2],'belum mengandungi'),'DB: butiran menyatakan belum ada pembaikan');
check(db_cve_status('MariaDB','11.8.6','5ubuntu0.2')[0]==='unverified','DB: pakej lebih baharu = perlu semakan');
check(db_cve_status('MariaDB','11.8.6','')[0]==='bad','DB: tanpa revisi = bad');
check(db_cve_status('MariaDB','11.8.5','5ubuntu0.9')[0]==='bad','DB: upstream tidak disenaraikan = bad');
check(db_cve_status('MariaDB','11.8.9','')[0]==='ok','DB: upstream dibaiki');
check(db_cve_status('MySQL','8.0.39')[0]==='ok','DB: MySQL tidak terjejas');
check(db_cve_status('MariaDB','10.5.1','')[0]==='bad','DB: siri tamat sokongan');

// Lencana
check(str_contains(cve_row_badge('unverified'),'Perlu semakan'),'lencana: perlu semakan');
check(str_contains(cve_row_badge('warn'),'Disyorkan'),'lencana: disyorkan');
check(str_contains(cve_row_badge('bad'),'Terdedah'),'lencana: terdedah');

echo "\nSEMUA LULUS: $passed semakan\n";
