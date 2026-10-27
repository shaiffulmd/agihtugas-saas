<?php
declare(strict_types=1);
/* Tetapan sekolah & tema. Nilai disimpan dalam jadual settings dan disahkan di pelayan. */

const THEME_PRESETS = [
    'delima' => ['label'=>'Oren DELIMa (asal)','primary'=>'#c2410c','dark'=>'#0b1a2b','accent'=>'#fb923c'],
    'hijau'  => ['label'=>'Hijau Teal',        'primary'=>'#176c5b','dark'=>'#0d2b25','accent'=>'#e0b25a'],
    'biru'   => ['label'=>'Biru Laut',         'primary'=>'#1d5fb8','dark'=>'#0b1a2b','accent'=>'#60a5fa'],
    'ungu'   => ['label'=>'Ungu Diraja',       'primary'=>'#6a4bb0','dark'=>'#2c1d58','accent'=>'#e3b04b'],
    'marun'  => ['label'=>'Merah Marun',       'primary'=>'#9a2d3c','dark'=>'#47141d','accent'=>'#d6a24a'],
    'oren'   => ['label'=>'Oren Terakota',     'primary'=>'#b4532a','dark'=>'#4a2213','accent'=>'#3c9a8c'],
    'grafit' => ['label'=>'Grafit Moden',      'primary'=>'#38536b','dark'=>'#18222d','accent'=>'#d9a441'],
];
const THEME_MODES = ['light'=>'Cerah','dark'=>'Gelap','auto'=>'Ikut peranti pengguna'];

function setting_defaults(): array {
    return [
        'app_name'       => env('APP_NAME','UrusTugas'),
        'school_name'    => env('SCHOOL_NAME','Sekolah anda'),
        'school_tagline' => 'Agihan tugas guru',
        'login_headline' => "Tugas yang adil.\nSekolah yang\nteratur.",
        'login_text'     => 'Satu ruang untuk merancang jawatan, mengagih beban tugas guru dan meluluskan senarai tugas sekolah.',
        'banner_title'   => "Setiap guru,\ntugas yang seimbang.",
        'banner_text'    => 'Rancang lantikan, semak beban tugas dan muktamadkan senarai tugas sekolah — semuanya di satu tempat.',
        'theme_preset'   => 'delima',
        'color_primary'  => THEME_PRESETS['delima']['primary'],
        'color_dark'     => THEME_PRESETS['delima']['dark'],
        'color_accent'   => THEME_PRESETS['delima']['accent'],
        'theme_mode'     => 'light',
        'logo'           => '',
        'logo_mime'      => 'image/webp',
        'theme_version'  => '1',
        'twofa_policy'   => 'admin', 'school_type' => 'SMK',
        // Indeks beban (Analisis Beban): bahagian mengajar %, ambang tinggi/rendah, faktor kelas peperiksaan, had bilangan jawatan.
        'index_teach_share' => '60', 'index_high' => '1.30', 'index_low' => '0.70', 'index_exam_factor' => '1.0', 'index_max_tasks' => '8',
        // Surat lantikan (Profil Sekolah & Surat). Logo surat & Jata Negara = id aset dalam school_assets.
        'principal' => '', 'session_year' => '', 'letter_reference' => 'SEK.100-1/5/1', 'letter_header' => 'dual', 'school_logo_id' => '', 'national_crest_id' => '',
        'reg_mode' => 'off', 'reg_domains' => '', 'login_captcha' => 'failures', 'ip_admin' => '', 'site_url' => '', 'mail_method' => 'platform', 'smtp_preset' => 'custom', 'mail_from' => '', 'mail_from_name' => '',
        'smtp_host' => '', 'smtp_port' => '587', 'smtp_secure' => 'tls', 'smtp_user' => '', 'smtp_pass' => '',
    ];
}
function settings_all(bool $refresh=false): array {
    static $cache=null;
    if($cache!==null && !$refresh) return $cache;
    $cache=setting_defaults();
    try { foreach(query('SELECT name,value FROM settings WHERE school_id=?',[sid()])->fetchAll() as $r) if(array_key_exists($r['name'],$cache)) $cache[$r['name']]=(string)$r['value']; }
    catch(PDOException $ex) { if((string)$ex->getCode()!=='42S02') throw $ex; } // Jadual belum dinaik taraf: guna nilai lalai.
    return $cache;
}
function setting(string $key): string { return settings_all()[$key] ?? ''; }
/** Jenis sekolah: SK (sekolah rendah, ketua = Guru Besar) atau SMK (sekolah menengah, ketua = Pengetua). */
const SCHOOL_TYPES=['SK'=>['Sekolah Kebangsaan / Rendah','Guru Besar'],'SMK'=>['Sekolah Menengah','Pengetua']];
function school_type(): string { return setting('school_type')==='SK'?'SK':'SMK'; }
function head_title(): string { return SCHOOL_TYPES[school_type()][1]; }
/** 2FA wajib untuk peranan ini? Pentadbir sentiasa wajib; peranan lain ikut tetapan. REQUIRE_2FA=0 dalam .env hanya untuk pembangunan. */
function twofa_required(string $role): bool { if(env('REQUIRE_2FA','1')==='0') return false; return $role==='admin' || setting('twofa_policy')==='all' || twofa_forced_all(); } // twofa_forced_all: Panel Platform → Keselamatan
function school_name(): string { return setting('school_name'); }
function app_name(): string { return setting('app_name'); }
function lines_html(string $text): string { return nl2br(e($text),false); }

/* ---------- Warna ---------- */
function hex_rgb(string $hex): array { $h=ltrim($hex,'#'); return [hexdec(substr($h,0,2)),hexdec(substr($h,2,2)),hexdec(substr($h,4,2))]; }
function rgb_hex(array $c): string { return sprintf('#%02x%02x%02x',...array_map(fn($v)=>max(0,min(255,(int)round($v))),$c)); }
function mix_hex(string $a,string $b,float $weightA): string { $x=hex_rgb($a); $y=hex_rgb($b); return rgb_hex([0=>$x[0]*$weightA+$y[0]*(1-$weightA),1=>$x[1]*$weightA+$y[1]*(1-$weightA),2=>$x[2]*$weightA+$y[2]*(1-$weightA)]); }
function luminance(string $hex): float {
    $c=array_map(function($v){ $v/=255; return $v<=0.03928?$v/12.92:(($v+0.055)/1.055)**2.4; },hex_rgb($hex));
    return 0.2126*$c[0]+0.7152*$c[1]+0.0722*$c[2];
}
function contrast(string $a,string $b): float { $l=[luminance($a),luminance($b)]; rsort($l); return ($l[0]+0.05)/($l[1]+0.05); }
function valid_hex(string $v): bool { return (bool)preg_match('/^#[0-9a-f]{6}$/D',$v); }

function theme_colors(): array {
    $s=settings_all();
    $p=valid_hex($s['color_primary'])?$s['color_primary']:THEME_PRESETS['hijau']['primary'];
    $d=valid_hex($s['color_dark'])?$s['color_dark']:THEME_PRESETS['hijau']['dark'];
    $a=valid_hex($s['color_accent'])?$s['color_accent']:THEME_PRESETS['hijau']['accent'];
    return ['primary'=>$p,'dark'=>$d,'accent'=>$a];
}
function theme_css(): string {
    ['primary'=>$p,'dark'=>$d,'accent'=>$a]=theme_colors();
    $light=':root{--primary:'.$p.';--dark:'.$d.';--accent:'.$a.'}';
    // Mod gelap: permukaan gelap bertona warna tema; warna utama dicerahkan supaya teks mudah dibaca.
    $surface=mix_hex($d,'#0d1214',0.30); $bg=mix_hex($d,'#080b0c',0.22);
    $primaryDark=contrast($p,$surface)<4.5?mix_hex($p,'#ffffff',0.62):$p;
    $dark=':root{color-scheme:dark;--surface:'.$surface.';--bg:'.$bg.';--ink:#e6ecea;--fg-dark:#eef3f1;--primary:'.$primaryDark.';--btn:'.$p.';--dark:'.mix_hex($d,'#000000',0.75).';--accent:'.$a.';--line:'.mix_hex('#ffffff',$surface,0.10).';--pastel-lilac:#6d5a90;--pastel-sky:#4f7f96;--pastel-warm:#8a6a3c;--danger:#e08a6d}';
    $sw=''; foreach(THEME_PRESETS+['custom'=>['primary'=>$p,'dark'=>$d,'accent'=>$a]] as $k=>$c) $sw.='.swatch-'.$k.' .sw1{background:'.$c['primary'].'}.swatch-'.$k.' .sw2{background:'.$c['dark'].'}.swatch-'.$k.' .sw3{background:'.$c['accent'].'}';
    return $sw.match(setting('theme_mode')) {
        'dark' => $light.$dark,
        'auto' => $light.'@media(prefers-color-scheme:dark){'.$dark.'}',
        default => $light,
    };
}
function serve_theme_css(): never {
    $css=theme_css();
    header_remove('Cache-Control'); header('Content-Type: text/css; charset=utf-8'); header('Cache-Control: public, max-age=31536000, immutable');
    echo $css; exit;
}
function theme_css_url(): string { return '/?page=theme-css&v='.rawurlencode(setting('theme_version')); }
function serve_logo(): never {
    $logo=setting('logo'); $bytes=$logo!==''?base64_decode($logo,true):false;
    if(!$bytes){ http_response_code(404); exit; }
    header_remove('Cache-Control'); header('Content-Type: '.(setting('logo_mime')==='image/png'?'image/png':'image/webp')); header('Content-Length: '.strlen($bytes)); header('Cache-Control: public, max-age=31536000, immutable'); echo $bytes; exit;
}
function logo_url(): ?string { return setting('logo')!==''?'/?page=logo&v='.rawurlencode(setting('theme_version')):null; }
function brand_html(string $class='brand',string $href='/'): string {
    $logo=logo_url(); $name=app_name();
    $icon=$logo?'<img class="brand-logo" src="'.e($logo).'" alt="">':'<span class="brand-icon">'.e(mb_strtolower(mb_substr((string)preg_replace('/^.*\s/u','',trim($name)),0,1))).'.</span>';
    // Nama dua perkataan dipaparkan dalam gaya jenama (perkataan kedua lebih ringan); nama lain seperti ditulis.
    $words=preg_split('/\s+/u',trim($name),2);
    $text=count($words)===2?e(mb_strtolower($words[0])).'<span class="brand-light">'.e(mb_strtolower($words[1])).'</span>':e($name);
    return '<a class="'.e($class).'" href="'.e($href).'">'.$icon.' <span class="brand-text">'.$text.'</span></a>';
}

/* ---------- Simpan tetapan ---------- */
function save_settings(array $values): void {
    db()->beginTransaction();
    foreach($values as $k=>$v) query('INSERT INTO settings(school_id,name,value) VALUES (?,?,?) ON DUPLICATE KEY UPDATE value=VALUES(value)',[sid(),$k,$v]);
    query('INSERT INTO settings(school_id,name,value) VALUES (?,"theme_version",?) ON DUPLICATE KEY UPDATE value=VALUES(value)',[sid(),bin2hex(random_bytes(4))]);
    db()->commit(); settings_all(true);
}
function handle_settings(string $action): never {
    require_admin();
    if($action==='settings_reset_theme'){
        $p=THEME_PRESETS['delima']; save_settings(['theme_preset'=>'delima','color_primary'=>$p['primary'],'color_dark'=>$p['dark'],'color_accent'=>$p['accent'],'theme_mode'=>'light']);
        audit('theme_reset','settings'); flash('Tema dikembalikan kepada tetapan asal.'); redirect('/?page=settings');
    }
    if($action==='settings_remove_logo'){ save_settings(['logo'=>'']); audit('logo_removed','settings'); flash('Logo dibuang.'); redirect('/?page=settings'); }
    $text=['app_name'=>[60,true],'school_name'=>[150,true],'school_tagline'=>[80,false],'login_headline'=>[90,true],'login_text'=>[240,false],'banner_title'=>[90,true],'banner_text'=>[240,false]];
    $out=[];
    foreach($text as $k=>[$max,$required]){
        $v=str_replace("\r\n","\n",trim(input($k)));
        if($required && $v==='') throw new DomainException('Medan "'.setting_label($k).'" perlu diisi.');
        if(mb_strlen($v)>$max) throw new DomainException('Medan "'.setting_label($k).'" melebihi '.$max.' aksara.');
        if(preg_match('/[\x00-\x09\x0B-\x1F\x7F]/u',$v)) throw new DomainException('Aksara kawalan tidak dibenarkan.');
        if(substr_count($v,"\n")>3) throw new DomainException('Maksimum 4 baris bagi "'.setting_label($k).'".');
        $out[$k]=$v;
    }
    $preset=input('theme_preset');
    if($preset!=='custom' && !isset(THEME_PRESETS[$preset])) throw new DomainException('Pilihan tema tidak sah.');
    $colors=$preset==='custom'
        ? ['primary'=>strtolower(input('color_primary')),'dark'=>strtolower(input('color_dark')),'accent'=>strtolower(input('color_accent'))]
        : THEME_PRESETS[$preset];
    foreach(['primary','dark','accent'] as $c) if(!valid_hex($colors[$c])) throw new DomainException('Kod warna mesti dalam format #rrggbb.');
    if(contrast($colors['primary'],'#ffffff')<4.5) throw new DomainException('Warna utama terlalu cerah: teks putih pada butang sukar dibaca (kontras '.number_format(contrast($colors['primary'],'#ffffff'),1).':1, minimum 4.5:1). Pilih warna lebih gelap.');
    if(contrast($colors['dark'],'#ffffff')<7) throw new DomainException('Warna gelap (banner & halaman log masuk) perlu lebih gelap untuk teks putih (minimum 7:1).');
    $mode=input('theme_mode'); if(!isset(THEME_MODES[$mode])) throw new DomainException('Mod paparan tidak sah.');
    $tp=input('twofa_policy'); if(!in_array($tp,['admin','all'],true)) throw new DomainException('Pilihan 2FA tidak sah.');
    $out['twofa_policy']=$tp;
    $out['school_type']=input('school_type')==='SK'?'SK':'SMK';
    $out+=['theme_preset'=>$preset,'color_primary'=>$colors['primary'],'color_dark'=>$colors['dark'],'color_accent'=>$colors['accent'],'theme_mode'=>$mode];
    $f=$_FILES['logo']??null;
    if($f && is_array($f) && ($f['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_NO_FILE){
        if(is_array($f['error']) || $f['error']!==UPLOAD_ERR_OK || !is_uploaded_file((string)$f['tmp_name'])) throw new DomainException('Muat naik logo gagal. Had saiz 2 MB.');
        $img=image_reencode((string)file_get_contents($f['tmp_name']),512);
        $out['logo']=base64_encode($img['bytes']); $out['logo_mime']=$img['mime'];
    }
    // Nama & jenis sekolah masuk ke snapshot revisi: hanya boleh diubah ketika agihan dalam Draf.
    if(($out['school_name']!==school_name() || $out['school_type']!==school_type()) && workflow_state()['status']!=='draft') throw new DomainException('Nama dan jenis sekolah dikunci semasa semakan / kelulusan. Kembalikan agihan kepada draf dahulu.');
    save_settings($out); audit('settings_saved','settings');
    flash('Tetapan & tema berjaya disimpan.'); redirect('/?page=settings');
}
function setting_label(string $k): string {
    return ['app_name'=>'Nama aplikasi','school_name'=>'Nama sekolah','school_tagline'=>'Slogan sekolah','login_headline'=>'Tajuk halaman log masuk','login_text'=>'Teks halaman log masuk','banner_title'=>'Tajuk banner dashboard','banner_text'=>'Teks banner dashboard'][$k]??$k;
}

/* ---------- Kod QR (dijana di pelayan, SVG sebaris tanpa gaya inline) ---------- */
function qr_svg(string $text): string {
    require_once dirname(__DIR__).'/vendor/autoload.php';
    $options=new \chillerlan\QRCode\QROptions(['eccLevel'=>\chillerlan\QRCode\Common\EccLevel::M]);
    $matrix=(new \chillerlan\QRCode\QRCode($options))->addByteSegment($text)->getQRMatrix()->getMatrix(true);
    $n=count($matrix); $q=4; $size=$n+2*$q; $path='';
    foreach($matrix as $y=>$row) foreach($row as $x=>$dark) if($dark) $path.='M'.($x+$q).' '.($y+$q).'h1v1h-1z';
    return '<svg class="qr" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 '.$size.' '.$size.'" width="200" height="200" role="img" aria-label="Kod QR persediaan 2FA" shape-rendering="crispEdges"><rect width="'.$size.'" height="'.$size.'" fill="#ffffff"/><path d="'.$path.'" fill="#000000"/></svg>';
}
function totp_uri(string $secret,string $email): string {
    $issuer=app_name();
    return 'otpauth://totp/'.rawurlencode($issuer.':'.$email).'?secret='.$secret.'&issuer='.rawurlencode($issuer).'&algorithm=SHA1&digits=6&period=30';
}
function login_headline_html(): string {
    $lines=array_values(array_filter(array_map('trim',explode("\n",setting('login_headline'))),'strlen'));
    if(!$lines) return '';
    $last=array_pop($lines);
    return ($lines?implode('<br>',array_map('e',$lines)).'<br>':'').'<em>'.e($last).'</em>';
}
