<?php
declare(strict_types=1);
// Invoked only by the generated public_html/install.php wrapper.
$root=dirname(__DIR__);
if(is_file($root.'/config/local.php')) {http_response_code(404);exit;}
header('Cache-Control: no-store');header('X-Content-Type-Options: nosniff');header('X-Frame-Options: DENY');header('Referrer-Policy: no-referrer');
header("Content-Security-Policy: default-src 'none'; style-src 'self'; form-action 'self'; frame-ancestors 'none'; base-uri 'none'");
require __DIR__.'/core.php';
$checks=install_requirements($root,$webroot);
$https=!empty($_SERVER['HTTPS'])&&$_SERVER['HTTPS']!=='off';
$checks['HTTPS diaktifkan']=$https;
$error='';$code=null;
if($_SERVER['REQUEST_METHOD']==='POST') {
 try {
  if(!$https) throw new RuntimeException('Aktifkan HTTPS sebelum pemasangan.');
  $keyFile=$root.'/config/install-key.php';$key=is_file($keyFile)?require $keyFile:'';
  $submitted=$_POST['install_key']??'';
  if(!is_string($submitted)||!is_string($key)||strlen($key)!==64||!hash_equals($key,$submitted)) {http_response_code(403);throw new RuntimeException('Kunci pemasangan tidak sah. Rujuk INSTALL.txt dalam pakej peribadi.');}
  $code=install_run($root,$webroot);
  if(is_file($keyFile)) unlink($keyFile);
 }catch(Throwable $ex){if(http_response_code()===200)http_response_code(400);$error=$ex->getMessage();error_log('AgihTugas installer: '.$error);}
} elseif($_SERVER['REQUEST_METHOD']!=='GET'){http_response_code(405);exit;}
function install_e(string $text): string{return htmlspecialchars($text,ENT_QUOTES,'UTF-8');}
?><!doctype html><html lang="ms"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Pemasang AgihTugas</title><link rel="stylesheet" href="/assets/install.css"></head><body><main><span class="badge">AGIHTUGAS · PEMASANG</span><h1><?= $code?'Pemasangan siap':'Sediakan ruang sekolah anda' ?></h1><p>Satu pemasangan untuk satu sekolah SK atau SMK.</p>
<?php if($error):?><p class="error" role="alert"><?= install_e($error) ?></p><?php endif ?>
<?php if($code):?><section><h2>Cipta pentadbir pertama</h2><p>Kod sekali guna ini sah selama 30 minit. Simpan sementara sebelum meninggalkan halaman ini.</p><code><?= install_e($code) ?></code><p><a class="button" href="/?page=setup">Teruskan ke persediaan akaun</a></p><p>Tiada kata laluan lalai. Pemasang dikunci secara automatik. Padam fail awam install.php selepas selesai.</p><p>Jika kod luput, jalankan bin/setup-admin.php melalui Terminal cPanel atau minta pentadbir hosting menjalankannya.</p></section>
<?php else:?><section><h2>Semakan pelayan</h2><ul><?php foreach($checks as $label=>$ok):?><li><strong class="<?= $ok?'ok':'error' ?>"><?= $ok?'Lulus':'Perlu tindakan' ?></strong> <?= install_e($label) ?></li><?php endforeach ?></ul></section><section><h2>Mulakan pemasangan</h2><p>Pangkalan data SQLite baharu akan disediakan tanpa guru atau lantikan contoh. Data sedia ada tidak ditindih.</p><form method="post" action="/install.php"><label for="install-key">Kunci pemasangan daripada INSTALL.txt</label><input id="install-key" name="install_key" type="password" required autocomplete="off" minlength="64" maxlength="64"><button <?= in_array(false,$checks,true)?'disabled':'' ?>>Pasang AgihTugas</button></form><p>Kunci ini unik bagi setiap pakej. Simpan fail INSTALL.txt di luar public_html.</p></section><?php endif ?></main></body></html>
