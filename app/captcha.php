<?php
declare(strict_types=1);
/* CAPTCHA sendiri (diambil daripada DELIMa Login): kod 5 aksara dilukis sebagai laluan SVG dengan fon garisan
   — TIADA teks dalam sumber imej. Setiap garisan aksara ialah <path> tersendiri dengan putaran + ricih (affine),
   segmen bergoyang, serpihan umpan setebal aksara, garis pemotong dan susunan elemen dikocok.
   Kod disimpan dalam sesi (pelayan), sekali guna, sah 10 minit, maks 8 kod tertangguh per sesi,
   5 kali "kod lain" per id. Ditambah perangkap bot (honeypot + masa minimum) dan perisai log masuk. */

const CAPD_HURUF='ACEFHKMNPRTWXY234679';
const CAPTCHA_CONTEXTS=['login','daftar','lupa'];

function capd_kod(): string { $k=''; for($i=0;$i<5;$i++) $k.=CAPD_HURUF[random_int(0,strlen(CAPD_HURUF)-1)]; return $k; }
function capd_baru(string $ctx): string {
    $id=bin2hex(random_bytes(8)); $s=(array)($_SESSION['capd']??[]);
    foreach($s as $k=>$v) if($v[1]<time()-600) unset($s[$k]);
    while(count($s)>=8) array_shift($s);                       // had kod tertangguh setiap sesi
    $s[$id]=[capd_kod(),time(),0,$ctx]; $_SESSION['capd']=$s; return $id;
}
function capd_url(string $id): string { return '/?page=captcha&id='.rawurlencode($id); }
/** Sahkan & buang (sekali guna). Kod mesti untuk borang yang sama. */
function captcha_check(string $ctx,string $id,string $answer): bool {
    $s=(array)($_SESSION['capd']??[]); if(!preg_match('/^[a-f0-9]{16}$/D',$id) || !isset($s[$id])) return false;
    [$kod,$masa,,$c]=$s[$id]; unset($s[$id]); $_SESSION['capd']=$s;
    if($masa<time()-600 || $c!==$ctx) return false;
    $j=strtoupper(preg_replace('/[\s\-]+/','',$answer));
    return strlen($j)===5 && hash_equals($kod,$j);
}
/** Lukis SVG. $baru = jana kod lain untuk id yang sama (butang ↻, maks 5 kali). */
function capd_svg(string $id,bool $baru=false): ?string {
    $s=(array)($_SESSION['capd']??[]);
    if(!isset($s[$id]) || $s[$id][1]<time()-600) return null;
    if($baru){ if($s[$id][2]>=5) return null; $s[$id]=[capd_kod(),time(),$s[$id][2]+1,$s[$id][3]]; $_SESSION['capd']=$s; }
    $kod=$s[$id][0];
    $G=[ // fon garisan pada grid 10 × 14
        'A'=>[[[0,14],[5,0],[10,14]],[[2,9],[8,9]]], 'C'=>[[[10,2],[7,0],[3,0],[0,3],[0,11],[3,14],[7,14],[10,12]]],
        'E'=>[[[10,0],[0,0],[0,14],[10,14]],[[0,7],[7,7]]], 'F'=>[[[10,0],[0,0],[0,14]],[[0,7],[7,7]]],
        'H'=>[[[0,0],[0,14]],[[10,0],[10,14]],[[0,7],[10,7]]], 'K'=>[[[0,0],[0,14]],[[10,0],[0,8]],[[3,6],[10,14]]],
        'M'=>[[[0,14],[0,0],[5,8],[10,0],[10,14]]], 'N'=>[[[0,14],[0,0],[10,14],[10,0]]],
        'P'=>[[[0,14],[0,0],[7,0],[10,3],[7,7],[0,7]]], 'R'=>[[[0,14],[0,0],[7,0],[10,3],[7,7],[0,7]],[[5,7],[10,14]]],
        'T'=>[[[0,0],[10,0]],[[5,0],[5,14]]], 'W'=>[[[0,0],[2,14],[5,6],[8,14],[10,0]]],
        'X'=>[[[0,0],[10,14]],[[10,0],[0,14]]], 'Y'=>[[[0,0],[5,7],[10,0]],[[5,7],[5,14]]],
        '2'=>[[[0,3],[3,0],[7,0],[10,3],[10,5],[0,14],[10,14]]], '3'=>[[[0,1],[3,0],[8,0],[10,3],[7,7],[10,10],[8,14],[3,14],[0,12]],[[4,7],[7,7]]],
        '4'=>[[[8,14],[8,0],[0,10],[10,10]]], '6'=>[[[9,1],[6,0],[3,0],[0,4],[0,11],[3,14],[7,14],[10,11],[10,9],[7,6],[3,6],[0,9]]],
        '7'=>[[[0,0],[10,0],[4,14]]], '9'=>[[[1,13],[4,14],[7,14],[10,10],[10,3],[7,0],[3,0],[0,3],[0,5],[3,8],[7,8],[10,5]]],
    ];
    $W=200; $H=64; $r=fn(float $a,float $b)=>$a+(random_int(0,10000)/10000)*($b-$a);
    $warna=['#1e3a8a','#7c2d12','#14532d','#581c87','#0f172a','#9a3412']; $el=[];
    for($i=0;$i<6;$i++) $el[]=sprintf('<path d="M%.1f %.1f Q%.1f %.1f %.1f %.1f" stroke="%s" stroke-width="%.1f" fill="none" opacity=".55"/>',$r(0,30),$r(5,$H-5),$r(50,150),$r(-20,$H+20),$r(170,$W),$r(5,$H-5),$warna[random_int(0,5)],$r(1,2.2));
    for($i=0;$i<40;$i++) $el[]=sprintf('<circle cx="%.1f" cy="%.1f" r="%.1f" fill="%s" opacity=".5"/>',$r(0,$W),$r(0,$H),$r(.6,1.6),$warna[random_int(0,5)]);
    $x0=$r(10,22);
    foreach(str_split($kod) as $n=>$c){
        $sk=$r(2.6,3.2); $sy=$sk*$r(.9,1.15); $ricih=$r(-.35,.35); $sudut=deg2rad($r(-22,22)); $cos=cos($sudut); $sin=sin($sudut);
        $cx=$x0+$n*$r(33,37)+15; $cy=$H/2+$r(-5,5);
        $titik=function(float $gx,float $gy) use($r,$sk,$sy,$ricih,$cos,$sin,$cx,$cy): array { $px=($gx-5+$r(-.5,.5))*$sk; $py=($gy-7+$r(-.5,.5))*$sy; $px+=$ricih*$py; return [$cx+$px*$cos-$py*$sin,$cy+$px*$sin+$py*$cos]; };
        $lebar=$r(2.6,3.6); $wn=$warna[random_int(0,5)];
        foreach($G[$c] as $garis){
            $d='';
            foreach($garis as $i=>[$gx,$gy]){
                if($i){ [$ax,$ay]=$garis[$i-1]; [$mx,$my]=$titik(($ax+$gx)/2+$r(-.6,.6),($ay+$gy)/2+$r(-.6,.6)); $d.=sprintf('L%.1f %.1f',$mx,$my); }
                [$tx,$ty]=$titik($gx,$gy); $d.=sprintf('%s%.1f %.1f',$i?'L':'M',$tx,$ty);
            }
            $el[]=sprintf('<path d="%s" stroke="%s" stroke-width="%.1f" fill="none" stroke-linecap="round" stroke-linejoin="round"/>',$d,$wn,$lebar+$r(-.3,.3));
        }
    }
    for($i=0;$i<5;$i++){ $ux=$r(8,$W-20); $uy=$r(12,$H-12); $ud=deg2rad($r(0,360)); $ul=$r(5,9); $el[]=sprintf('<path d="M%.1f %.1fL%.1f %.1f" stroke="%s" stroke-width="%.1f" fill="none" stroke-linecap="round"/>',$ux,$uy,$ux+cos($ud)*$ul,$uy+sin($ud)*$ul,$warna[random_int(0,5)],$r(2.4,3.4)); }
    for($i=0;$i<2;$i++) $el[]=sprintf('<path d="M0 %.1f C%.1f %.1f %.1f %.1f %d %.1f" stroke="%s" stroke-width="%.1f" fill="none"/>',$r(15,$H-15),$r(40,90),$r(0,$H),$r(110,160),$r(0,$H),$W,$r(15,$H-15),$warna[random_int(0,5)],$r(1.8,2.6));
    shuffle($el); // susunan elemen tidak mendedahkan susunan aksara
    return '<svg xmlns="http://www.w3.org/2000/svg" width="'.$W.'" height="'.$H.'" viewBox="0 0 '.$W.' '.$H.'"><rect width="100%" height="100%" fill="#f1f5f9"/>'.implode('',$el).'</svg>';
}
function serve_captcha(string $id,bool $baru): never {
    $svg=preg_match('/^[a-f0-9]{16}$/D',$id)?capd_svg($id,$baru):null;
    header_remove('Pragma'); header('Cache-Control: no-store, max-age=0'); header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'"); header('Content-Type: image/svg+xml');
    if($svg===null){ http_response_code(404); exit('<svg xmlns="http://www.w3.org/2000/svg" width="200" height="64"><rect width="100%" height="100%" fill="#fee2e2"/><path d="M60 22 L140 42 M60 42 L140 22" stroke="#b91c1c" stroke-width="3"/></svg>'); }
    echo $svg; exit;
}
/** Medan captcha + perangkap bot untuk borang. */
function captcha_field(string $ctx): string {
    $_SESSION['form_t'][$ctx]=time();
    if(cap_external()) return cap_widget(cap_provider()).'<div class="hp-field" aria-hidden="true"><label>Laman web<input name="website" tabindex="-1" autocomplete="off"></label></div>'; // CAPTCHA pihak ketiga (Panel Platform → Keselamatan)
    $id=capd_baru($ctx); $img=e(capd_url($id));
    return '<div class="captcha-box" data-cap><label class="field">Kod keselamatan<span class="captcha-img"><img src="'.$img.'" data-src="'.$img.'" alt="Kod pengesahan — taip huruf dan nombor dalam gambar" width="200" height="64"><button type="button" class="btn sm outline captcha-refresh" aria-label="Kod lain" title="Kod lain">↻</button></span>'
        .'<input type="hidden" name="cap_id" value="'.e($id).'"><input name="captcha" class="cap-jawapan" autocomplete="off" autocapitalize="characters" spellcheck="false" maxlength="8" placeholder="Taip 5 aksara di atas" required></label></div>'
        .'<div class="hp-field" aria-hidden="true"><label>Laman web<input name="website" tabindex="-1" autocomplete="off"></label></div>';
}
/** Semak perangkap bot + captcha. Melontar ralat mesra jika gagal. */
function form_guard(string $ctx,bool $needCaptcha=true): void {
    $t=$_SESSION['form_t'][$ctx]??0;
    if(input('website')!=='' || ($t && time()-$t<2)){ audit('bot_blocked','users'); throw new DomainException('Permintaan tidak dapat diproses. Cuba semula.'); }
    if($needCaptcha && cap_external()){ $p=cap_provider(); if(!cap_verify($p,input(CAP_PROVIDERS[$p]['field']))) throw new DomainException('Pengesahan CAPTCHA gagal. Cuba semula.'); return; }
    if($needCaptcha){
        if(trim(input('captcha'))==='') throw new DomainException('Sila taip kod keselamatan yang dipaparkan.');
        if(!captcha_check($ctx,input('cap_id'),input('captcha'))) throw new DomainException('Kod keselamatan salah atau tamat tempoh. Cuba kod baharu.');
    }
}

/* ---------------------------------------------------------------------
   Perisai log masuk (DELIMa): dikira mengikut IP, mengikut E-MEL (merentas IP) dan JUMLAH keseluruhan,
   supaya penyerang yang menukar IP tetap dipaksa CAPTCHA / disekat. Peranti yang pernah berjaya log masuk
   (kuki bertandatangan) tidak dikunci oleh serangan dari IP lain.
   --------------------------------------------------------------------- */
function rate_count(string $key,int $window=900): int { $r=query('SELECT attempts,UNIX_TIMESTAMP(window_start) s FROM rate_limits WHERE bucket=?',[$key])->fetch(); return ($r && time()-(int)$r['s']<=$window)?(int)$r['attempts']:0; }
function shield_keys(string $email): array { $ip=client_ip(); return ['ip'=>rate_key('login-fail-ip',$ip),'user'=>rate_key('login-fail-user',strtolower($email)),'all'=>rate_key('login-fail-all','*')]; }
/** Perlu CAPTCHA pada halaman log masuk? (paparan: IP & keseluruhan; hantar: + e-mel) */
function login_captcha_needed(string $email=''): bool {
    $m=setting('login_captcha'); if($m==='always') return true; if($m==='off') return false;
    $k=shield_keys($email);
    return rate_count($k['ip'])>=3 || rate_count($k['all'])>=15 || ($email!=='' && rate_count($k['user'])>=3);
}
/** Sekatan akaun selepas 10 gagal (merentas IP) — kecuali peranti dikenali. */
function shield_locked(string $email): bool { if(function_exists('demo_mode') && demo_mode() && demo_is_account($email)) return false; return rate_count(shield_keys($email)['user'])>=10 && !shield_device_known($email); }
function shield_fail(string $email): void { foreach(shield_keys($email) as $k) rate_hit($k); }
function shield_success(string $email): void { $k=shield_keys($email); query('DELETE FROM rate_limits WHERE bucket IN (?,?)',[$k['ip'],$k['user']]); shield_device_remember($email); }
function shield_device_cookie(): string { return env('SESSION_SECURE',request_is_https()?'1':'0')==='1'?'__Host-urustugas_pk':'urustugas_pk'; }
function shield_device_tag(string $email): string { return substr(hash_hmac('sha256',strtolower($email),hash_hkdf('sha256',key_bytes(),32,'urustugas/peranti-dikenali')),0,32); }
function shield_device_known(string $email): bool { $t=shield_device_tag($email); foreach(explode('.',(string)($_COOKIE[shield_device_cookie()]??'')) as $x) if($x!=='' && hash_equals($t,$x)) return true; return false; }
function shield_device_remember(string $email): void {
    if(PHP_SAPI==='cli' || headers_sent()) return;
    $t=shield_device_tag($email); $ada=array_filter(explode('.',(string)($_COOKIE[shield_device_cookie()]??'')),fn($x)=>preg_match('/^[a-f0-9]{32}$/D',$x) && $x!==$t);
    $secure=env('SESSION_SECURE',request_is_https()?'1':'0')==='1';
    setcookie(shield_device_cookie(),implode('.',array_slice(array_merge([$t],array_values($ada)),0,5)),['expires'=>time()+90*86400,'path'=>'/','secure'=>$secure,'httponly'=>true,'samesite'=>'Lax']);
}
