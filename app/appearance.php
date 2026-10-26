<?php
declare(strict_types=1);
/* Panel Platform → Rupa Halaman Utama (corak inc/rupa.php delima-multi): warna tema, fon, gambar latar & teks hero
 * untuk halaman awam platform (/, /daftar, /terma, /polisi, /lapor-keselamatan). Panel sekolah tidak terjejas.
 * Tetapan platform: lp_preset, lp_primary/lp_dark/lp_accent, lp_fon, lp_hero_data (base64), lp_hero_ver, lp_hero_gelap,
 * lp_lencana, lp_t1, lp_t2, lp_teks. CSS dihidang di /?page=lp-css&v=… dan gambar di /?page=lp-hero&v=… (tiada gaya sebaris). */

const LP_FONT_FILES=[
    'Montserrat'=>[700=>'montserrat-latin-700-normal',800=>'montserrat-latin-800-normal'],
    'Playfair Display'=>[800=>'playfair-display-latin-800-normal'],
    'Nunito'=>[700=>'nunito-latin-700-normal',800=>'nunito-latin-800-normal'],
    'Plus Jakarta Sans'=>[700=>'plus-jakarta-sans-latin-700-normal',800=>'plus-jakarta-sans-latin-800-normal'],
    'Lora'=>[700=>'lora-latin-700-normal'],
    'Poppins'=>[700=>'poppins-latin-700-normal',800=>'poppins-latin-800-normal'],
];
/** kunci => [label, fon hero (tajuk besar), fon tajuk, keterangan] */
const LP_FONTS=[
    'klasik'=>['Klasik','Playfair Display','Montserrat','Serif berwibawa + tajuk moden (lalai)'],
    'moden'=>['Moden','Montserrat','Montserrat','Tegas & bersih'],
    'mesra'=>['Mesra','Nunito','Nunito','Bulat & mesra — sesuai sekolah rendah'],
    'korporat'=>['Korporat','Plus Jakarta Sans','Plus Jakarta Sans','Profesional & kemas'],
    'elegan'=>['Elegan','Lora','Lora','Serif klasik yang anggun'],
    'kontemporari'=>['Kontemporari','Poppins','Poppins','Geometri moden'],
    'sistem'=>['Sistem','','','Fon peranti — paling pantas'],
];
const LP_SYSTEM_STACK='system-ui,-apple-system,"Segoe UI",Roboto,sans-serif';
const LP_TEXT_DEFAULT=['lencana'=>'Agihan tugas guru','t1'=>'Agih tugas guru','t2'=>'adil & telus.','teks'=>'Senarai guru, jawatan dan lantikan, auto-agihan beban tugas, aliran kelulusan PK → GB/Pengetua dan surat lantikan — dalam satu sistem untuk pentadbir sekolah.'];

require_once __DIR__.'/links.php';
require_once __DIR__.'/directory.php';
function lp_links_parse(string $text,?string &$error=null): array { return footer_links_parse($text,$error); }
function lp_links(): array { return lp_links_parse(pset('lp_pautan')); }
function lp_links_title(): string { return trim(pset('lp_pautan_tajuk'))?:'Pautan'; }
/* ---------- Logo & ikon platform (Tetapan Platform → Logo & ikon) ---------- */
function plat_logo_has(): bool { return pset('lp_logo_ver')!=='' && pset('lp_logo_data')!==''; }
function plat_logo_url(): string { return '/?page=lp-logo&v='.rawurlencode(pset('lp_logo_ver')); }
function plat_tagline(): string { return trim(pset('lp_tagline'))?:'Agihan tugas guru'; }
/** Tanda jenama: logo yang dimuat naik, atau kotak "u." lalai. */
function plat_brand_mark(): string { return plat_logo_has()?'<img class="brand-logo" src="'.e(plat_logo_url()).'" alt="">':'<span class="brand-icon">u.</span>'; }
/** Nama jenama: gaya "urustugas" lalai, atau Nama platform jika telah ditukar. $hl = kelas bahagian kedua (warna aksen). */
function plat_brand_name(string $wrap='b',string $hl=''): string {
    $n=platform_name(); if($n==='UrusTugas' && !plat_logo_has()) return '<'.$wrap.'>urus<span'.($hl!==''?' class="'.$hl.'"':'').'>tugas</span></'.$wrap.'>';
    return '<'.$wrap.'>'.e($n).'</'.$wrap.'>';
}
function lp_logo_serve(): never {
    $raw=base64_decode(pset('lp_logo_data'),true); if(!$raw) error_page(404);
    while(ob_get_level()>0) @ob_end_clean();
    header_remove('Cache-Control'); header('Content-Type: image/png'); header('Content-Length: '.strlen($raw)); header('Cache-Control: public, max-age=31536000, immutable'); echo $raw; exit;
}
/** Logo: PNG/JPG/WEBP ≤ 2 MB, dikecilkan dalam 512×512, ketelusan dikekalkan, dikod semula PNG (metadata dibuang). */
function plat_logo_process(array $f): string {
    if(($f['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK || !is_uploaded_file((string)($f['tmp_name']??''))) throw new DomainException('Muat naik logo gagal. Cuba lagi.');
    if((int)$f['size']>2*1048576) throw new DomainException('Logo terlalu besar (maksimum 2 MB).');
    if(!in_array((new finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name']),['image/png','image/jpeg','image/webp'],true)) throw new DomainException('Logo mesti PNG, JPG atau WEBP (PNG lut sinar disyorkan).');
    $info=@getimagesize($f['tmp_name']); if(!$info || min($info[0],$info[1])<32 || max($info[0],$info[1])>4000) throw new DomainException('Saiz logo tidak sesuai (minimum 32 piksel, maksimum 4000).');
    $src=@imagecreatefromstring((string)file_get_contents($f['tmp_name'])); if(!$src) throw new DomainException('Imej logo rosak atau tidak dapat dibaca.');
    [$w,$h]=[imagesx($src),imagesy($src)]; $s=min(1,512/max($w,$h)); $nw=max(1,(int)round($w*$s)); $nh=max(1,(int)round($h*$s));
    $dst=imagecreatetruecolor($nw,$nh); imagealphablending($dst,false); imagesavealpha($dst,true); imagefill($dst,0,0,imagecolorallocatealpha($dst,0,0,0,127));
    imagecopyresampled($dst,$src,0,0,0,0,$nw,$nh,$w,$h);
    ob_start(); imagepng($dst,null,9); $out=(string)ob_get_clean();
    if($out==='') throw new DomainException('Logo tidak dapat diproses.');
    return $out;
}
function lp_font(): string { $f=pset('lp_fon','klasik'); return isset(LP_FONTS[$f])?$f:'klasik'; }
/** Warna halaman awam: pratetap tema (settings.php) atau tersuai. */
function lp_colors(): array {
    $p=pset('lp_preset','delima');
    if($p!=='custom' && isset(THEME_PRESETS[$p])) return ['primary'=>THEME_PRESETS[$p]['primary'],'dark'=>THEME_PRESETS[$p]['dark'],'accent'=>THEME_PRESETS[$p]['accent']];
    $d=THEME_PRESETS['delima']; $g=fn(string $k,string $def)=>valid_hex(pset('lp_'.$k))?strtolower(pset('lp_'.$k)):$def;
    return ['primary'=>$g('primary',$d['primary']),'dark'=>$g('dark',$d['dark']),'accent'=>$g('accent',$d['accent'])];
}
function lp_dim(): int { return max(20,min(95,(int)pset('lp_hero_gelap','70'))); }
function lp_has_hero(): bool { return pset('lp_hero_ver')!=='' && pset('lp_hero_data')!==''; }
function lp_text(string $k): string { $v=trim(pset('lp_'.$k)); return $v!==''?$v:LP_TEXT_DEFAULT[$k]; }
function lp_css_ver(): string { return substr(hash('sha256',json_encode(lp_colors()).'|'.lp_font().'|'.pset('lp_hero_ver').'|'.lp_dim().'|1'),0,12); }

function lp_font_face(string $fam): string {
    $css=''; foreach(LP_FONT_FILES[$fam]??[] as $w=>$f) $css.='@font-face{font-family:"'.$fam.'";font-style:normal;font-weight:'.$w.';font-display:swap;src:url(/assets/fonts/'.$f.'.woff2) format("woff2")}'."\n";
    return $css;
}
function lp_font_stack(string $fam,string $fallback): string { return $fam===''?LP_SYSTEM_STACK:'"'.$fam.'",'.$fallback; }
/** CSS penuh rupa halaman awam (dimuat selepas app.css & theme-css). */
function lp_css(): string {
    [,$hero,$title]=LP_FONTS[lp_font()]; $c=lp_colors(); $css='';
    foreach(array_unique(array_filter([$hero,$title])) as $fam) if(!in_array($fam,['Montserrat','Playfair Display'],true)) $css.=lp_font_face($fam); // dua ini sudah dalam fonts.css
    $css.=':root{--primary:'.$c['primary'].';--btn:'.$c['primary'].';--dark:'.$c['dark'].';--fg-dark:'.$c['dark'].';--accent:'.$c['accent'].';';
    $css.='--fon-tajuk:'.lp_font_stack($title,'"Inter",system-ui,sans-serif').';--fon-hero:'.lp_font_stack($hero,'Georgia,serif').';';
    if(lp_font()==='sistem') $css.='--fon-teks:'.LP_SYSTEM_STACK.';';
    $css.="}\n";
    if(lp_has_hero()){
        $u='/?page=lp-hero&v='.rawurlencode(pset('lp_hero_ver')); $g=lp_dim()/100; [$r,$gg,$b]=sscanf($c['dark'],'#%02x%02x%02x');
        $o=fn(float $n): string=>number_format(max(.15,min(.98,$n)),2,'.','');
        $rgb="$r,$gg,$b";
        $css.='.lp-hero{background:linear-gradient(90deg,rgba('.$rgb.','.$o($g+.25).') 0%,rgba('.$rgb.','.$o($g+.16).') 38%,rgba('.$rgb.','.$o($g-.15).') 72%,rgba('.$rgb.','.$o($g-.31).') 100%),url("'.$u.'") center/cover no-repeat,var(--dark)}'
            .'@media (max-width:900px){.lp-hero{background:linear-gradient(180deg,rgba('.$rgb.','.$o($g-.05).') 0%,rgba('.$rgb.','.$o($g+.10).') 55%,rgba('.$rgb.','.$o($g+.25).') 100%),url("'.$u.'") center/cover no-repeat,var(--dark)}}'."\n";
    }
    return $css;
}
function lp_css_serve(): never {
    while(ob_get_level()>0) @ob_end_clean();
    header_remove('Cache-Control'); header('Content-Type: text/css; charset=utf-8'); header('Cache-Control: public, max-age=31536000, immutable'); echo lp_css(); exit;
}
function lp_hero_serve(): never {
    $raw=base64_decode(pset('lp_hero_data'),true); if(!$raw) error_page(404);
    while(ob_get_level()>0) @ob_end_clean();
    header_remove('Cache-Control'); header('Content-Type: '.(str_starts_with($raw,'RIFF')?'image/webp':'image/jpeg')); header('Content-Length: '.strlen($raw)); header('Cache-Control: public, max-age=31536000, immutable'); echo $raw; exit;
}
/** Gambar latar: kecilkan ke lebar 1920 px, simpan WebP (atau JPEG); metadata dibuang kerana imej dikod semula. */
function lp_hero_process(array $f): string {
    if(($f['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK || !is_uploaded_file((string)($f['tmp_name']??''))) throw new DomainException('Muat naik gagal. Cuba lagi.');
    if((int)$f['size']>6*1048576) throw new DomainException('Fail terlalu besar (maksimum 6 MB).');
    if(!in_array((new finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name']),['image/png','image/jpeg','image/webp'],true)) throw new DomainException('Format mesti PNG, JPG atau WEBP.');
    $info=@getimagesize($f['tmp_name']); if(!$info || min($info[0],$info[1])<600 || max($info[0],$info[1])>8000) throw new DomainException('Saiz imej tidak sesuai (minimum 600 piksel, maksimum 8000).');
    $src=@imagecreatefromstring((string)file_get_contents($f['tmp_name'])); if(!$src) throw new DomainException('Imej rosak atau tidak dapat dibaca.');
    [$w,$h]=[imagesx($src),imagesy($src)]; $s=min(1,1920/$w); $nw=(int)round($w*$s); $nh=(int)round($h*$s);
    $dst=imagecreatetruecolor($nw,$nh); imagefill($dst,0,0,imagecolorallocate($dst,11,26,43)); imagecopyresampled($dst,$src,0,0,0,0,$nw,$nh,$w,$h);
    ob_start(); if(function_exists('imagewebp')) imagewebp($dst,null,80); else { imageinterlace($dst,true); imagejpeg($dst,null,80); } $out=(string)ob_get_clean();
    if($out==='') throw new DomainException('Imej tidak dapat diproses.');
    return $out;
}

function handle_appearance_platform(string $action): never {
    if($action==='p_rupa_logo_save'){
        $kv=['lp_tagline'=>mb_substr(trim((string)preg_replace('/\s+/u',' ',input('lp_tagline'))),0,60)];
        if(!empty($_FILES['lp_logo']['name'])){ $img=plat_logo_process($_FILES['lp_logo']); $kv+=['lp_logo_data'=>base64_encode($img),'lp_logo_ver'=>substr(hash('sha256',$img),0,16)]; }
        pset_set($kv); platform_log('platform_logo_saved',null,isset($kv['lp_logo_ver'])?'logo baharu':'tagline'); flash('Logo & ikon platform disimpan.'); redirect('/platform/?p=tetapan#logo');
    }
    if($action==='p_rupa_logo_remove'){ pset_set(['lp_logo_data'=>'','lp_logo_ver'=>'']); platform_log('platform_logo_removed'); flash('Logo dibuang; ikon lalai "k." digunakan semula.'); redirect('/platform/?p=tetapan#logo'); }
    if($action==='p_rupa_directory'){
        $layout=input('directory_layout');
        if(!isset(DIRECTORY_LAYOUTS[$layout])) throw new DomainException('Pilihan paparan direktori tidak sah.');
        $switch=input('directory_switch')==='1'?'1':'0';
        $size=input('directory_title_size')?:directory_title_size(); if(!isset(DIRECTORY_TITLE_SIZES[$size])) throw new DomainException('Saiz tajuk direktori tidak sah.');
        pset_set(['directory_layout'=>$layout,'directory_switch'=>$switch,'directory_title_size'=>$size]);
        platform_log('directory_appearance_saved',null,'paparan '.$layout.', pilihan pengguna '.$switch);
        flash('Tetapan direktori disimpan.'); redirect('/platform/?p=rupa');
    }
    if($action==='p_rupa_hero_remove'){ pset_set(['lp_hero_data'=>'','lp_hero_ver'=>'']); platform_log('appearance_hero_removed'); flash('Gambar latar dibuang; hero kembali ke latar warna tema.'); redirect('/platform/?p=rupa'); }
    if($action==='p_rupa_reset'){ pset_set(['lp_preset'=>'delima','lp_fon'=>'klasik','lp_hero_gelap'=>'70','lp_lencana'=>'','lp_t1'=>'','lp_t2'=>'','lp_teks'=>'']); platform_log('appearance_reset'); flash('Rupa halaman utama dikembalikan ke lalai (gambar latar dikekalkan).'); redirect('/platform/?p=rupa'); }
    if($action!=='p_rupa_save') error_page(400);
    $preset=input('lp_preset'); if($preset!=='custom' && !isset(THEME_PRESETS[$preset])) throw new DomainException('Pratetap warna tidak sah.');
    $kv=['lp_preset'=>$preset];
    if($preset==='custom') foreach(['primary','dark','accent'] as $k){ $v=strtolower(trim(input('lp_'.$k))); if(!valid_hex($v)) throw new DomainException('Warna tidak sah (format #RRGGBB).'); $kv['lp_'.$k]=$v; }
    $font=input('lp_fon'); if(!isset(LP_FONTS[$font])) throw new DomainException('Pilihan fon tidak sah.'); $kv['lp_fon']=$font;
    $dim=(int)input('lp_hero_gelap'); if($dim<20 || $dim>95) throw new DomainException('Kegelapan lapisan mesti 20–95.'); $kv['lp_hero_gelap']=(string)$dim;
    foreach(['lencana'=>60,'t1'=>40,'t2'=>40,'teks'=>320] as $k=>$max){ $v=trim((string)preg_replace('/\s+/u',' ',input('lp_'.$k))); if(mb_strlen($v)>$max) throw new DomainException('Teks hero terlalu panjang (maksimum '.$max.' aksara).'); $kv['lp_'.$k]=$v; }
    $err=null; lp_links_parse(input('lp_pautan'),$err); if($err) throw new DomainException('Pautan footer — '.$err);
    $kv['lp_pautan']=mb_substr(str_replace("\r\n","\n",trim(input('lp_pautan'))),0,4000); $kv['lp_pautan_tajuk']=mb_substr(trim(input('lp_pautan_tajuk')),0,40);
    if(!empty($_FILES['lp_hero']['name'])){ $img=lp_hero_process($_FILES['lp_hero']); $kv+=['lp_hero_data'=>base64_encode($img),'lp_hero_ver'=>substr(hash('sha256',$img),0,16)]; }
    pset_set($kv); platform_log('appearance_saved',null,'warna '.$preset.', fon '.$font.(isset($kv['lp_hero_ver'])?', gambar latar baharu':''));
    flash('Rupa halaman utama disimpan.'); redirect('/platform/?p=rupa');
}

function platform_page_appearance(): void {
    $c=lp_colors(); $preset=pset('lp_preset','delima'); $font=lp_font(); ?>
<div class="page-heading"><div><span class="eyebrow teal">PLATFORM</span><h1>Rupa Halaman Utama</h1><p class="muted">Warna tema, fon, gambar latar dan teks hero bagi halaman awam platform (halaman utama, daftar, terma, polisi, lapor keselamatan). Tema setiap sekolah diurus oleh sekolah sendiri.</p></div><div class="button-group"><a class="btn outline" href="/" target="_blank" rel="noopener">Lihat halaman utama ↗</a></div></div>
<section class="panel padded spaced"><div class="page-heading"><div><h2>Halaman carian / direktori</h2><p class="muted">Tiga reka bentuk menggunakan carian dan data sekolah yang sama.</p></div><a class="btn outline" href="/direktori" target="_blank" rel="noopener">Lihat direktori ↗</a></div>
<form method="post" class="stack"><?= csrf_field() ?><input type="hidden" name="action" value="p_rupa_directory">
<fieldset class="pay-gw"><legend>Paparan lalai</legend><?php foreach(DIRECTORY_LAYOUTS as $key=>[$label,$desc]): ?><label class="check"><input type="radio" name="directory_layout" value="<?= e($key) ?>"<?= directory_default_layout()===$key?' checked':'' ?>> <span><strong><?= e($label) ?></strong> <small class="muted"><?= e($desc) ?></small></span></label><?php endforeach; ?></fieldset>
<fieldset class="pay-gw"><legend>Saiz tajuk ("Cari sekolah anda.", "Temui portal sekolah anda" …)</legend><?php foreach(DIRECTORY_TITLE_SIZES as $k=>$l): ?><label class="check"><input type="radio" name="directory_title_size" value="<?= e($k) ?>"<?= directory_title_size()===$k?' checked':'' ?>> <?= e($l) ?></label><?php endforeach; ?></fieldset>
<label class="check"><input type="checkbox" name="directory_switch" value="1"<?= directory_switch_enabled()?' checked':'' ?>> Benarkan pengguna menukar paparan</label>
<p class="muted">Pelawat baharu melihat paparan lalai. Jika dibenarkan, pilihan pengguna diingati sepanjang sesi mereka. Matikan pilihan ini untuk menggunakan paparan lalai bagi semua pengguna.</p>
<div class="button-group"><button class="btn primary">Simpan tetapan direktori</button></div></form></section>
<form method="post" enctype="multipart/form-data" autocomplete="off"><?= csrf_field() ?><input type="hidden" name="action" value="p_rupa_save">
<div class="dashboard-grid pay-grid">
<section class="panel padded"><h3>Warna tema</h3><div class="form-grid">
<label class="field full">Pratetap<select name="lp_preset"><?php foreach(THEME_PRESETS as $k=>$t): ?><option value="<?= e($k) ?>"<?= $preset===$k?' selected':'' ?>><?= e($t['label']) ?></option><?php endforeach; ?><option value="custom"<?= $preset==='custom'?' selected':'' ?>>Warna tersuai (di bawah)</option></select></label>
<label class="field">Warna utama<input type="color" name="lp_primary" value="<?= e($c['primary']) ?>"></label>
<label class="field">Warna gelap (nav, hero, footer)<input type="color" name="lp_dark" value="<?= e($c['dark']) ?>"></label>
<label class="field">Warna aksen<input type="color" name="lp_accent" value="<?= e($c['accent']) ?>"></label></div>
<p class="muted">Warna tersuai digunakan hanya apabila pratetap "Warna tersuai" dipilih.</p></section>
<section class="panel padded"><h3>Fon</h3><fieldset class="pay-gw"><legend class="sr-only">Pilihan fon</legend><?php foreach(LP_FONTS as $k=>[$label,$hero,$title,$desc]): ?>
<label class="check"><input type="radio" name="lp_fon" value="<?= e($k) ?>"<?= $font===$k?' checked':'' ?>> <span><strong><?= e($label) ?></strong> <small class="muted"><?= e($hero!==''?$hero.($title!==$hero?' + '.$title:''):'Fon peranti') ?> · <?= e($desc) ?></small></span></label><?php endforeach; ?></fieldset></section>
<section class="panel padded"><h3>Gambar latar hero</h3>
<p class="muted"><?= lp_has_hero()?'Gambar latar aktif (versi <code>'.e(pset('lp_hero_ver')).'</code>).':'Tiada gambar — hero menggunakan latar warna gelap tema.' ?> PNG/JPG/WEBP, maks 6 MB, minimum 600 piksel; dikecilkan ke lebar 1920 px dan metadata dibuang.</p>
<div class="form-grid"><label class="field full">Gambar baharu<input type="file" name="lp_hero" accept="image/png,image/jpeg,image/webp"></label>
<label class="field full">Kegelapan lapisan di atas gambar (20–95)<input type="range" name="lp_hero_gelap" min="20" max="95" step="5" value="<?= lp_dim() ?>"><small class="muted">Lebih tinggi = teks lebih mudah dibaca. Semasa: <?= lp_dim() ?>.</small></label></div></section>
<section class="panel padded"><h3>Teks hero</h3><div class="form-grid">
<label class="field full">Lencana kecil<input name="lp_lencana" maxlength="60" value="<?= e(pset('lp_lencana')) ?>" placeholder="<?= e(LP_TEXT_DEFAULT['lencana']) ?>"></label>
<label class="field">Tajuk baris 1<input name="lp_t1" maxlength="40" value="<?= e(pset('lp_t1')) ?>" placeholder="<?= e(LP_TEXT_DEFAULT['t1']) ?>"></label>
<label class="field">Tajuk baris 2 (warna aksen)<input name="lp_t2" maxlength="40" value="<?= e(pset('lp_t2')) ?>" placeholder="<?= e(LP_TEXT_DEFAULT['t2']) ?>"></label>
<label class="field full">Perenggan<textarea name="lp_teks" rows="3" maxlength="320" placeholder="<?= e(LP_TEXT_DEFAULT['teks']) ?>"><?= e(pset('lp_teks')) ?></textarea></label></div>
<p class="muted">Kosongkan untuk teks lalai. Teks biasa sahaja (tiada HTML).</p></section>
<section class="panel padded"><h3>Pautan footer</h3><div class="form-grid">
<label class="field full">Tajuk lajur<input name="lp_pautan_tajuk" maxlength="40" value="<?= e(pset('lp_pautan_tajuk')) ?>" placeholder="Pautan"></label>
<label class="field full">Pautan (satu setiap baris: Label | URL)<textarea name="lp_pautan" rows="5" maxlength="4000" placeholder="Portal KPM | https://www.moe.gov.my&#10;Manual pengguna | https://contoh.my/manual.pdf&#10;WhatsApp sokongan | https://wa.me/60123456789"><?= e(pset('lp_pautan')) ?></textarea><small class="muted">Maksimum 12. URL https://, mailto:, tel: atau laluan dalaman (/terma). Pautan luar dibuka di tab baharu.</small></label></div></section>
</div>
<div class="button-group spaced"><button class="btn primary">Simpan rupa</button></div></form>
<div class="button-group spaced"><?php if(lp_has_hero()): ?><form method="post" data-confirm="Buang gambar latar hero?"><?= csrf_field() ?><button class="btn outline" name="action" value="p_rupa_hero_remove">Buang gambar latar</button></form><?php endif; ?>
<form method="post" data-confirm="Kembalikan warna, fon dan teks hero ke lalai?"><?= csrf_field() ?><button class="btn outline" name="action" value="p_rupa_reset">Kembalikan lalai</button></form></div><?php
}
