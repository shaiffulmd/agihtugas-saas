<?php
declare(strict_types=1);
/* Halaman ralat seragam (401/403/404/419/422/429/500/503) mengikut tema & jenama sekolah.
   Selamat dipanggil walaupun pangkalan data/tetapan tidak tersedia (fallback tanpa tema).
   Tiada gaya/skrip sebaris (CSP): semua gaya dalam app.css, butang "Kembali" melalui app.js. */

const ERROR_TEXT=[
 400=>['Permintaan tidak sah','Pelayar menghantar permintaan yang tidak dapat difahami oleh sistem.','alert'],
 401=>['Sila log masuk dahulu','Halaman ini hanya untuk guru dan pentadbir yang telah log masuk.','lock'],
 403=>['Akses tidak dibenarkan','Akaun anda tidak mempunyai kebenaran untuk membuka halaman ini.','shield'],
 404=>['Halaman tidak dijumpai','Pautan mungkin salah, telah dipindahkan, atau rekod telah dipadam.','search'],
 419=>['Sesi borang telah tamat','Demi keselamatan, borang tamat tempoh selepas dibiarkan terlalu lama. Muat semula halaman dan cuba sekali lagi.','clock'],
 422=>['Tidak dapat diproses','Maklumat yang diperlukan belum lengkap.','clipboard'],
 429=>['Terlalu banyak cubaan','Sila tunggu sebentar sebelum mencuba lagi.','clock'],
 500=>['Ralat sistem','Sesuatu tidak kena di pihak kami. Ralat telah direkodkan untuk semakan pentadbir.','alert'],
 503=>['Sistem sedang diselenggara','Sistem tidak tersedia buat seketika. Sila cuba lagi sebentar lagi.','tool'],
];

function error_svg(string $name): string {
    $p=['alert'=>'<path d="M12 3 2.5 20h19L12 3Z"/><path d="M12 10v4.5M12 17.5v.01"/>','lock'=>'<rect x="4.5" y="10.5" width="15" height="10" rx="2.5"/><path d="M8 10.5V7.5a4 4 0 0 1 8 0v3"/>',
        'shield'=>'<path d="M12 3 4.5 6v5.5c0 4.6 3.2 8.2 7.5 9.5 4.3-1.3 7.5-4.9 7.5-9.5V6L12 3Z"/><path d="m9.5 9.5 5 5M14.5 9.5l-5 5"/>','search'=>'<circle cx="10.5" cy="10.5" r="6.5"/><path d="m20 20-4.8-4.8M8.5 10.5h4"/>',
        'clock'=>'<circle cx="12" cy="12" r="8.5"/><path d="M12 7.5V12l3 2"/>','clipboard'=>'<rect x="5.5" y="4.5" width="13" height="16" rx="2.5"/><path d="M9 4.5h6v3H9zM9 12h6M9 15.5h4"/>',
        'tool'=>'<path d="M14.5 6.5a4 4 0 0 0 5 5L12 19a2.1 2.1 0 0 1-3-3l7.5-7.5"/><path d="M14.5 6.5 17 4l3 3-2.5 2.5"/>'];
    return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'.($p[$name]??$p['alert']).'</svg>';
}
/** Pelanggan mahukan teks/JSON (cth. fetch) — elakkan HTML penuh. */
function error_wants_plain(): bool { $a=(string)($_SERVER['HTTP_ACCEPT']??''); return str_contains($a,'application/json') && !str_contains($a,'text/html'); }

/** Paparkan halaman ralat dan hentikan permintaan. $detail = mesej khusus (pilihan). */
function error_page(int $code,string $detail='',array $opt=[]): never {
    if(!isset(ERROR_TEXT[$code])) $code=$code>=500?500:404;
    while(ob_get_level()>0) @ob_end_clean(); // buang separuh halaman yang mungkin telah dijana
    if(PHP_SAPI!=='cli' && function_exists('tenant_rewrite_html')) ob_start('tenant_rewrite_html'); // pautan /KOD dalam mod laluan
    if(!headers_sent()){ http_response_code($code); header('Cache-Control: no-store'); if($code===503 || $code===429) header('Retry-After: '.($code===503?300:60)); }
    [$title,$text,$icon]=ERROR_TEXT[$code]; $title=($opt['title']??null)?:$title; $text=$opt['text']??$text;
    if(error_wants_plain()){ if(!headers_sent()) header('Content-Type: application/json; charset=utf-8'); echo json_encode(['ralat'=>$code,'mesej'=>$detail!==''?$detail:$title],JSON_UNESCAPED_UNICODE); exit; }
    if(!headers_sent()) header('Content-Type: text/html; charset=utf-8');
    // Maklumat sekolah & tema: semuanya pilihan (mungkin DB tidak tersedia).
    $user=null; $school=''; $app='UrusTugas'; $theme=null; $brand='';
    try { if(function_exists('setting')){ $school=(string)setting('school_name'); $app=(string)setting('app_name')?:$app; } } catch(Throwable) {}
    try { if(function_exists('theme_css_url') && empty($opt['no_db'])) $theme=theme_css_url(); } catch(Throwable) {}
    try { if(function_exists('brand_html') && empty($opt['no_db'])) $brand=brand_html('brand','/'); } catch(Throwable) {}
    try { if(function_exists('current_user') && empty($opt['no_db'])) $user=current_user(); } catch(Throwable) {}
    if($brand==='') $brand='<a class="brand" href="/"><span class="brand-icon">u.</span><span class="brand-name">'.e($app).'</span></a>';
    $siteOn=false; // UrusTugas tiada laman awam sekolah
    $here=e((string)($_SERVER['REQUEST_URI']??'/'));
    // Tindakan utama mengikut konteks.
    $actions=[];
    if($code===419) $actions[]=['Muat semula halaman',$here,'primary'];
    if($code===401 || ($code===403 && !$user)) $actions[]=['Log masuk','/?page=login','primary'];
    if($user) $actions[]=['Ke Dashboard','/?page=dashboard',$actions?'outline':'primary'];
    elseif($code!==401) $actions[]=[$siteOn?'Laman utama':'Log masuk',$siteOn?'/':'/?page=login',$actions?'outline':'primary'];
    if($code===503 || $code===500) $actions=[['Cuba lagi',$here,'primary']];
    ?><!doctype html><html lang="ms"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex">
<title><?= e($title) ?> · <?= e($app) ?></title><link rel="stylesheet" href="<?= e(asset('app.css')) ?>"><?php if($theme): ?><link rel="stylesheet" href="<?= e($theme) ?>"><?php endif; ?><script src="<?= e(asset('app.js')) ?>" defer></script></head>
<body class="error-body">
<main class="error-shell">
 <header class="error-top"><?= $brand ?></header>
 <section class="error-card" aria-labelledby="error-title">
  <div class="error-visual" aria-hidden="true"><span class="error-code"><?= $code ?></span><span class="error-icon"><?= error_svg($icon) ?></span></div>
  <span class="eyebrow teal">RALAT <?= $code ?></span>
  <h1 id="error-title"><?= e($title) ?></h1>
  <p class="error-text"><?= e($text) ?></p>
  <?php if($detail!=='' && $detail!==$title): ?><p class="error-detail"><?= e($detail) ?></p><?php endif; ?>
  <?php if(!empty($opt['extra'])): ?><div class="error-extra"><?= $opt['extra'] ?></div><?php endif; ?>
  <div class="error-actions"><?php foreach($actions as [$label,$href,$cls]): ?><a class="btn <?= $cls ?>" href="<?= e($href) ?>"><?= e($label) ?></a><?php endforeach; ?><button class="btn outline" type="button" data-back="<?= $user?'/?page=dashboard':'/' ?>">← Kembali</button></div>
  <?php if(!empty($opt['ref'])): ?><p class="error-ref">Rujukan ralat: <code><?= e($opt['ref']) ?></code> — berikan kod ini kepada pentadbir jika masalah berterusan.</p><?php endif; ?>
 </section>
 <footer class="error-foot"><?= e($school!==''?$school.' · ':'') ?><?= e($app) ?></footer>
</main>
</body></html><?php
    exit;
}
