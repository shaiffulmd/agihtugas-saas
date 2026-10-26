<?php
require dirname(__DIR__).'/app/bootstrap.php';
if(env('APP_ENV')!=='local') exit("Ujian hanya untuk APP_ENV=local.\n");
function check(bool $ok,string $label): void { if(!$ok){ fwrite(STDERR,"FAIL: $label\n"); exit(1); } echo "PASS: $label\n"; }
// RFC 6238 Appendix B, SHA-1 vectors, 8 digits.
$s=base32_encode('12345678901234567890');
foreach([59=>'94287082',1111111109=>'07081804',1111111111=>'14050471',1234567890=>'89005924',2000000000=>'69279037',20000000000=>'65353130'] as $time=>$expected) check(totp($s,intdiv($time,30),8)===$expected,'RFC6238 '.$time);
$raw=random_bytes(20); check(base32_decode(base32_encode($raw))===$raw,'Base32 roundtrip');
$plain='Data guru sulit'; $cipher=encrypt_value($plain); check(decrypt_value($cipher)===$plain,'Secretbox roundtrip');
$bytes=base64_decode($cipher); $bytes[30]=chr(ord($bytes[30])^1); $caught=false; try { decrypt_value(base64_encode($bytes)); } catch(RuntimeException){ $caught=true; } check($caught,'Ciphertext tampering rejected');
$h=hash_password('Password ujian selamat'); check(password_verify('Password ujian selamat',$h) && !password_verify('salah',$h) && str_starts_with($h,'$argon2id$'),'Argon2id hash');
check(e('<script>"')==='&lt;script&gt;&quot;','HTML escaping');
check(totp_step($s,'abc')===null,'Invalid TOTP rejected');
echo "Semua ujian kriptografi lulus.\n";
