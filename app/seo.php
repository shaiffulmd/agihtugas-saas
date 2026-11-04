<?php
declare(strict_types=1);
/* SEO halaman awam platform (lihat docs/SEO.md): meta kanonik, robots, Open Graph, data berstruktur (JSON-LD), robots.txt & sitemap.xml.
   Disesuaikan daripada koku-multi (app/seo.php, commit ea94ff1). UrusTugas tiada laman awam sekolah: halaman sekolah (log masuk,
   sistem dalaman) sentiasa noindex dan tidak dimasukkan ke sitemap — hanya halaman awam platform diindeks.
   URL mutlak hanya dibina daripada domain induk (Panel Platform → Subdomain Sekolah / PLATFORM_DOMAIN), BUKAN pengepala Host —
   pengepala Host palsu tidak boleh meracun kanonik/sitemap. Tanpa domain induk: tiada kanonik/og:url dan sitemap 404. */

const SEO_LOCALE='ms_MY';
/** URL mutlak platform; '' jika domain induk belum ditetapkan. */
function seo_platform_abs(string $path='/'): string { $d=platform_domain(); return $d!==''?tenant_scheme().'://'.$d.'/'.ltrim($path,'/'):''; }
/** Teks biasa ringkas untuk meta description (tanpa markup, ruang dipadatkan). */
function seo_desc(string $s,int $max=160): string { return mb_strimwidth(trim((string)preg_replace('/\s+/u',' ',strip_tags($s))),0,$max,'…'); }

/** Tag <head>: description, canonical, robots, Open Graph & Twitter. $o: title, desc, canonical, image, type, site, noindex.
    Halaman noindex tidak diberi kanonik/og:url (isyarat bercanggah). */
function seo_meta(array $o): string {
    $noindex=!empty($o['noindex']); $desc=seo_desc((string)($o['desc']??'')); $can=$noindex?'':(string)($o['canonical']??'');
    if($noindex && !headers_sent()) header('X-Robots-Tag: noindex, follow');
    $img=(string)($o['image']??''); $h='';
    if($desc!=='') $h.='<meta name="description" content="'.e($desc).'">';
    $h.=$noindex?'<meta name="robots" content="noindex, follow">':'<meta name="robots" content="index, follow, max-image-preview:large">';
    if($can!=='') $h.='<link rel="canonical" href="'.e($can).'">';
    $h.='<meta property="og:type" content="'.e($o['type']??'website').'"><meta property="og:locale" content="'.SEO_LOCALE.'">';
    if(($o['site']??'')!=='') $h.='<meta property="og:site_name" content="'.e($o['site']).'">';
    $h.='<meta property="og:title" content="'.e($o['title']??'').'">'.($desc!==''?'<meta property="og:description" content="'.e($desc).'">':'');
    if($can!=='') $h.='<meta property="og:url" content="'.e($can).'">';
    if($img!=='') $h.='<meta property="og:image" content="'.e($img).'">';
    return $h.'<meta name="twitter:card" content="'.($img!==''?'summary_large_image':'summary').'">';
}
/** JSON-LD (blok data, bukan skrip boleh laksana — tidak tertakluk pada CSP script-src). </script> & & di-escape. */
function seo_json_ld(array $data): string {
    return '<script type="application/ld+json">'.json_encode(['@context'=>'https://schema.org']+$data,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_HEX_TAG|JSON_HEX_AMP|JSON_INVALID_UTF8_SUBSTITUTE).'</script>';
}
/** Kandungan pengguna dengan terlalu banyak pautan (tanda spam pautan / SEO poisoning jika akaun sekolah dicerobohi). */
const SEO_MAX_LINKS=10;
function seo_too_many_links(string $s): bool { return preg_match_all('~(https?://|www\.)~i',$s)>SEO_MAX_LINKS; }
/** Teks yang dipaparkan di halaman awam yang diindeks (cth. nama sekolah dalam direktori) tidak boleh mengandungi URL. */
function seo_has_link(string $s): bool { return (bool)preg_match('~(https?:|www\.|\.(com|net|org|xyz|top|info|biz|ru|cn)\b)~i',$s); }
/** Buang nilai kosong (schema.org tidak perlukan medan kosong). */
function seo_clean(array $a): array { return array_filter($a,fn($v)=>$v!==null && $v!=='' && $v!==[]); }

/* ===================== robots.txt & sitemap.xml ===================== */
function seo_text(string $type,string $body): never {
    while(ob_get_level()>0) @ob_end_clean();
    header_remove('Set-Cookie'); header('Content-Type: '.$type.'; charset=utf-8'); header('Cache-Control: public, max-age=3600'); header('X-Robots-Tag: noindex');
    echo $body; exit;
}
/** /robots.txt domain induk. Halaman yang tidak mahu diindeks menggunakan meta noindex (bukan Disallow) supaya enjin carian dapat membacanya;
    Disallow hanya untuk titik akhir mesin (pemasang, webhook, callback bayaran, semakan hos, sihat, API sekolah). */
function seo_platform_robots(): never {
    $b="User-agent: *\nDisallow: /install.php\nDisallow: /kemaskini-hook\nDisallow: /bayar-callback\nDisallow: /hos-semak\nDisallow: /sihat.php\nDisallow: /*/api/\n";
    if(platform_domain()!=='') $b.="\nSitemap: ".seo_platform_abs('/sitemap.xml')."\n";
    seo_text('text/plain',$b);
}
function seo_urlset(array $urls): string {
    $x='<?xml version="1.0" encoding="UTF-8"?>'."\n".'<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'."\n";
    foreach($urls as [$loc,$mod]) $x.='<url><loc>'.htmlspecialchars($loc,ENT_XML1|ENT_QUOTES,'UTF-8').'</loc>'.($mod?'<lastmod>'.htmlspecialchars(substr((string)$mod,0,10),ENT_XML1).'</lastmod>':'').'</url>'."\n";
    return $x.'</urlset>'."\n";
}
/** /sitemap.xml domain induk: halaman awam platform sahaja. Halaman sekolah (log masuk) noindex → tidak disenaraikan. */
function seo_platform_sitemap(): never {
    if(platform_domain()==='') error_page(404);
    $u=[[seo_platform_abs('/'),null],[seo_platform_abs('/direktori'),null],[seo_platform_abs('/terma'),null],[seo_platform_abs('/polisi'),null]];
    if(faq_items(true)) $u[]=[seo_platform_abs('/faq'),null];
    if(registration_open()) $u[]=[seo_platform_abs('/daftar'),null];
    seo_text('application/xml',seo_urlset($u));
}
/** /robots.txt & /sitemap.xml dalam konteks sekolah. robots.txt hanya sah di akar hos (mod subdomain); tiada sitemap sekolah. */
function seo_school_file(string $path): never {
    if($path==='/robots.txt' && tenant_mode()==='subdomain') seo_text('text/plain',"User-agent: *\nDisallow: /api/\n");
    error_page(404);
}
