<?php
function auth_screen(string $page,string $error=''): never { ?>
<!doctype html><html lang="ms"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="referrer" content="no-referrer"><title><?= $page==='setup'?'Persediaan Pentadbir':'Log Masuk' ?> · AgihTugas</title><link rel="stylesheet" href="/assets/base.css"><link rel="stylesheet" href="/assets/agihtugas.css"></head><body><main class="login-shell"><section class="login-story"><a class="side-brand" href="/"><span class="brand-icon">A</span><span>Agih<span class="brand-light">Tugas</span></span></a><span class="hero-pill">PENGURUSAN TUGAS GURU</span><h1>Tugas tersusun.<br>Keputusan jelas.</h1><p>Rancang agihan, semak bersama dan muktamadkan lantikan dalam satu ruang sekolah.</p><div class="login-steps"><span>01 · Draf</span><span>02 · Semakan</span><span>03 · Lulus</span><span>04 · Muktamad</span></div></section><section class="login-card"><span class="eyebrow teal">SELAMAT DATANG KE AGIHTUGAS</span><h2><?= $page==='setup'?'Sediakan pentadbir pertama':'Log masuk ke ruang sekolah' ?></h2><p class="muted"><?= $page==='setup'?'Gunakan kod daripada terminal pelayan dan tetapkan kata laluan anda sendiri.':'Gunakan akaun yang disediakan oleh pentadbir sekolah.' ?></p>
<?php if($error): ?><div class="notice error" role="alert"><?= e($error) ?></div><?php endif ?>
<form method="post" class="login-form"><?= csrf() ?><input type="hidden" name="action" value="<?= $page==='setup'?'setup_account':'login' ?>">
<?php if($page==='setup'): ?><label class="field">Kod persediaan<input name="setup_code" required autocomplete="off" maxlength="32" spellcheck="false"></label><label class="field">Nama pentadbir<input name="name" required maxlength="160" autocomplete="name"></label><?php endif ?>
<label class="field">E-mel<input type="email" name="email" required maxlength="254" autocomplete="username"></label><label class="field">Kata laluan<input type="password" name="password" required <?= $page==='setup'?'minlength="12"':'' ?> maxlength="72" autocomplete="<?= $page==='setup'?'new-password':'current-password' ?>"></label>
<?php if($page==='setup'): ?><p class="muted">Sekurang-kurangnya 12 aksara. Kod sah selama 30 minit dan hanya boleh digunakan sekali.</p><?php endif ?>
<button class="btn primary"><?= $page==='setup'?'Cipta akaun pentadbir':'Log masuk' ?></button></form>
<?php if($page==='setup'): ?><div class="rule-explain"><strong>Dapatkan kod dari terminal projek</strong><p><code>php bin/setup-admin.php</code></p><p>Tiada kata laluan lalai disediakan.</p></div><?php endif ?>
</section></main></body></html>
<?php exit; }
function auth_gate(): array {
 $page=(string)($_GET['page']??'dashboard'); $action=(string)($_POST['action']??''); $account=current_account();
 if($_SERVER['REQUEST_METHOD']==='POST'&&in_array($action,['login','setup_account','logout'],true)) {
  try {
   if(!hash_equals($_SESSION['csrf'],(string)($_POST['csrf']??''))) throw new AccessError('Sesi borang tidak sah. Muat semula halaman.',403);
   if($action==='logout') { $_SESSION=[]; session_regenerate_id(true); header('Location: /?page=login',true,303); exit; }
   if($action==='setup_account') { auth_setup($_POST); header('Location: /?page=login',true,303); exit; }
   auth_login((string)($_POST['email']??''),(string)($_POST['password']??''));
   $a=current_account(); header('Location: /?page='.($a['role']==='teacher'?'my_tasks':($a['role']==='head'?'workflow':'dashboard')),true,303); exit;
  } catch(Throwable $ex) {
   if(isset($GLOBALS['db'])&&$GLOBALS['db']->inTransaction()) $GLOBALS['db']->rollBack();
   http_response_code($ex instanceof AccessError?$ex->status:500);
   auth_screen($action==='setup_account'?'setup':'login',$ex instanceof AccessError?$ex->getMessage():'Permintaan tidak dapat diproses.');
  }
 }
 if(!$account) {
  $exists=(int)q('SELECT COUNT(*) FROM accounts')->fetchColumn()>0;
  if($page==='setup'&&!$exists) auth_screen('setup');
  if(!$exists) { header('Location: /?page=setup',true,303); exit; }
  if($page!=='login') { header('Location: /?page=login',true,303); exit; }
  auth_screen('login');
 }
 if(in_array($page,['login','setup'],true)||($page==='dashboard'&&in_array($account['role'],['teacher','head'],true))) { header('Location: /?page='.($account['role']==='teacher'?'my_tasks':($account['role']==='head'?'workflow':'dashboard')),true,303); exit; }
 if($page==='asset') return $account;
 if(!in_array($page,auth_pages($account),true)) { http_response_code(403); echo '<!doctype html><html lang="ms"><meta charset="utf-8"><title>Akses ditolak</title><h1>Akses ditolak</h1><p>Peranan anda tidak membenarkan halaman ini.</p><a href="/">Kembali</a></html>'; exit; }
 return $account;
}
