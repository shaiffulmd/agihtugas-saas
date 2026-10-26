<?php
declare(strict_types=1);
/* Direktori awam: satu carian, tiga susun atur. Hanya metadata sekolah aktif. */
const DIRECTORY_LAYOUTS = [
    'carian'=>['Carian Utama','Carian besar dengan keputusan yang mudah dibaca.'],
    'kad'=>['Kad Sekolah','Kad sekolah untuk semakan secara visual.'],
    'senarai'=>['Senarai Ringkas','Senarai padat dengan penapis negeri di sisi.'],
];

/** Saiz tajuk halaman direktori (Rupa Halaman Utama). */
const DIRECTORY_TITLE_SIZES=['kecil'=>'Kecil','sederhana'=>'Sederhana (lalai)','besar'=>'Besar'];
function directory_title_size(): string { $s=pset('directory_title_size','sederhana'); return isset(DIRECTORY_TITLE_SIZES[$s])?$s:'sederhana'; }
function directory_default_layout(): string {
    $layout=pset('directory_layout','carian');
    return isset(DIRECTORY_LAYOUTS[$layout])?$layout:'carian';
}
function directory_switch_enabled(): bool { return pset('directory_switch','1')==='1'; }
function directory_layout(): string {
    if(!directory_switch_enabled()) return directory_default_layout();
    $chosen=input('paparan','get');
    if(isset(DIRECTORY_LAYOUTS[$chosen])) $_SESSION['directory_layout']=$chosen;
    $saved=$_SESSION['directory_layout']??'';
    return is_string($saved) && isset(DIRECTORY_LAYOUTS[$saved])?$saved:directory_default_layout();
}
/** LIKE treats %, _ and the escape character as literal search text. */
function directory_search_pattern(string $q): string {
    return '%'.strtr($q,['!'=>'!!','%'=>'!%','_'=>'!_']).'%';
}
function directory_results(string $q,string $state,int $page): array {
    $where=['status = ?']; $params=['aktif'];
    if($q!==''){
        $where[]="(name LIKE ? ESCAPE '!' OR code LIKE ? ESCAPE '!')";
        $pattern=directory_search_pattern($q); $params[]=$pattern; $params[]=$pattern;
    }
    if($state!==''){ $where[]='state = ?'; $params[]=$state; }
    $sql=implode(' AND ',$where);
    $total=(int)query('SELECT COUNT(*) FROM schools WHERE '.$sql,$params)->fetchColumn();
    $size=12; $pages=max(1,(int)ceil($total/$size)); $page=max(1,min($pages,$page));
    $offset=($page-1)*$size;
    $rows=query('SELECT s.code,s.name,s.state,(SELECT LENGTH(g.value)>0 FROM settings g WHERE g.school_id=s.id AND g.name="logo") has_logo,(SELECT g.value FROM settings g WHERE g.school_id=s.id AND g.name="theme_version") logo_v FROM schools s WHERE '.$sql.' ORDER BY s.name,s.code LIMIT '.$size.' OFFSET '.$offset,$params)->fetchAll();
    return compact('rows','total','page','pages','offset','size');
}
function directory_url(string $q,string $state,string $layout,int $page=1): string {
    return '/direktori?'.http_build_query(['q'=>$q,'negeri'=>$state,'paparan'=>$layout,'halaman'=>$page],'','&',PHP_QUERY_RFC3986);
}
function directory_search_form(string $q,string $state,string $layout,array $states,bool $withState=true): void { ?>
<form method="get" action="/direktori" role="search" class="dir-search">
<input type="hidden" name="paparan" value="<?= e($layout) ?>">
<label class="dir-search-input"><span class="sr-only">Nama sekolah atau kod sekolah</span><?= icon('search') ?><input name="q" value="<?= e($q) ?>" maxlength="80" placeholder="Nama sekolah atau kod sekolah" autocomplete="off" type="search"></label>
<?php if($withState): ?><label class="dir-state-select"><span class="sr-only">Negeri</span><select name="negeri"><option value="">Semua negeri</option><?php foreach($states as $s): ?><option value="<?= e($s) ?>"<?= $state===$s?' selected':'' ?>><?= e($s) ?></option><?php endforeach; ?></select></label>
<?php else: ?><input type="hidden" name="negeri" value="<?= e($state) ?>"><?php endif; ?>
<button class="btn primary" type="submit">Cari sekolah <?= icon('arrow') ?></button>
</form>
<?php }
/** Logo sekolah (Tetapan & Tema sekolah) untuk direktori; tiada logo = ikon sekolah. */
function directory_logo_url(array $s): ?string { return !empty($s['has_logo'])?'/?page=sch-logo&kod='.rawurlencode((string)$s['code']).'&v='.rawurlencode((string)($s['logo_v']??'')):null; }
function directory_school_mark(array $s,string $class='dir-school-icon'): string {
    $u=directory_logo_url($s); return '<span class="'.$class.($u?' has-logo':'').'">'.($u?'<img src="'.e($u).'" alt="" loading="lazy" decoding="async">':icon('school')).'</span>';
}
/** /?page=sch-logo&kod=… — logo sekolah aktif sahaja (awam: logo sudah dipaparkan di laman log masuk sekolah). */
function directory_logo_serve(): never {
    $code=strtoupper((string)preg_replace('/\s+/','',input('kod','get')));
    $s=preg_match(SCHOOL_CODE_RE,$code)?school_find($code):null;
    $row=$s && $s['status']==='aktif'?query('SELECT (SELECT value FROM settings WHERE school_id=? AND name="logo") logo,(SELECT value FROM settings WHERE school_id=? AND name="logo_mime") mime',[(int)$s['id'],(int)$s['id']])->fetch():null;
    $bytes=$row && $row['logo']!==null && $row['logo']!==''?base64_decode((string)$row['logo'],true):false;
    if(!$bytes){ http_response_code(404); exit; }
    header_remove('Cache-Control'); header('Content-Type: '.(($row['mime']??'')==='image/png'?'image/png':'image/webp')); header('Content-Length: '.strlen($bytes)); header('X-Content-Type-Options: nosniff');
    header('Cache-Control: public, max-age='.(input('v','get')!==''?'31536000, immutable':'3600')); echo $bytes; exit;
}
function directory_portal_link(array $school,string $class=''): void { ?>
<a class="dir-portal <?= e($class) ?>" href="<?= e(school_url($school['code'],'/')) ?>" aria-label="<?= e('Buka portal '.$school['name']) ?>">Buka portal <?= icon('arrow-ur') ?></a>
<?php }
function platform_search_directory(): never {
    $layout=directory_layout();
    $q=mb_substr(trim(input('q','get')),0,80);
    // Kod sekolah KPM yang tepat (cth. CEA1234) → terus ke portal sekolah itu, seperti carian halaman utama.
    $code=strtoupper((string)preg_replace('/\s+/','',$q));
    if(preg_match(SCHOOL_CODE_RE,$code) && ($hit=school_find($code)) && $hit['status']==='aktif') redirect(school_url($hit['code'],'/'));
    $state=mb_substr(trim(input('negeri','get')),0,40);
    $states=array_column(query('SELECT DISTINCT state FROM schools WHERE status=? AND state<>? ORDER BY state',['aktif',''])->fetchAll(),'state');
    // Preserve an unknown filter as an explicit zero-result state, rather than silently broadening it.
    if($state!=='' && !in_array($state,$states,true)){ $states[]=$state; sort($states); }
    $result=directory_results($q,$state,(int)input('halaman','get'));
    extract($result,EXTR_SKIP);
    lp_head('Direktori sekolah',true); ?>
<div class="directory dir-<?= e($layout) ?> dir-tajuk-<?= e(directory_title_size()) ?>">
<section class="dir-intro"><div class="lp-wrap">
<span class="eyebrow">DIREKTORI SEKOLAH</span>
<h1><?= match($layout){'kad'=>'Temui portal sekolah anda','senarai'=>'Pilih sekolah. Terus ke portal.',default=>'Cari sekolah anda.'} ?></h1>
<p><?= $layout==='carian'?'Akses portal agihan tugas sekolah dengan mudah.':'Cari mengikut nama, kod atau negeri.' ?></p>
<?php directory_search_form($q,$state,$layout,$states,$layout!=='senarai'); ?>
</div></section>
<div class="lp-wrap dir-body">
<?php if(directory_switch_enabled()): ?><nav class="dir-switch" aria-label="Pilihan paparan direktori"><span>Paparan</span><div><?php foreach(DIRECTORY_LAYOUTS as $key=>[$label]): ?><a href="<?= e(directory_url($q,$state,$key,$page)) ?>"<?= $layout===$key?' aria-current="true"':'' ?>><?= e($label) ?></a><?php endforeach; ?></div></nav><?php endif; ?>
<div class="dir-workspace">
<?php if($layout==='senarai'): ?><aside class="dir-filters"><h2>Tapis negeri</h2><nav aria-label="Penapis negeri"><a href="<?= e(directory_url($q,'',$layout)) ?>"<?= $state===''?' aria-current="true"':'' ?>>Semua negeri</a><?php foreach($states as $s): ?><a href="<?= e(directory_url($q,$s,$layout)) ?>"<?= $state===$s?' aria-current="true"':'' ?>><?= e($s) ?></a><?php endforeach; ?></nav><a class="text-link" href="<?= e(directory_url('','',$layout)) ?>">Tetapkan semula</a></aside><?php endif; ?>
<section class="dir-results" aria-labelledby="dir-result-title">
<div class="dir-result-heading"><div><h2 id="dir-result-title"><?= $q!==''?'Hasil carian':($total.' sekolah ditemui') ?></h2><p class="muted"><?php if($q!==''): ?><?= $total ?> sekolah ditemui untuk “<?= e($q) ?>”<?php else: ?>Sekolah berdaftar yang aktif<?php endif; ?><?= $state!==''?' · '.e($state):'' ?></p></div><span class="muted">Nama A–Z</span></div>
<?php if(!$rows): ?><div class="dir-empty"><?= icon('search') ?><h3>Tiada sekolah dijumpai</h3><p>Semak ejaan, cuba kod sekolah atau kosongkan penapis negeri.</p><a class="btn outline" href="<?= e(directory_url('','',$layout)) ?>">Lihat semua sekolah</a></div>
<?php elseif($layout==='senarai'): ?>
<table class="dir-table"><caption class="sr-only">Senarai sekolah aktif</caption><thead><tr><th scope="col">Nama sekolah</th><th scope="col">Kod sekolah</th><th scope="col">Negeri</th><th scope="col"><span class="sr-only">Portal sekolah</span></th></tr></thead><tbody>
<?php foreach($rows as $s): ?><tr><th scope="row"><span class="dir-row-name"><?= directory_school_mark($s,'dir-row-logo') ?><?= e($s['name']) ?></span></th><td data-label="Kod sekolah"><?= e($s['code']) ?></td><td data-label="Negeri"><?= e($s['state']?:'Tidak dinyatakan') ?></td><td><?php directory_portal_link($s); ?></td></tr><?php endforeach; ?>
</tbody></table>
<?php else: ?><div class="dir-school-list">
<?php foreach($rows as $s): ?><article class="dir-school"><?= directory_school_mark($s) ?><div class="dir-school-info"><h3><?= e($s['name']) ?></h3><p><span><?= e($s['code']) ?></span><span><?= e($s['state']?:'Negeri tidak dinyatakan') ?></span></p></div><?php directory_portal_link($s); ?></article><?php endforeach; ?>
</div><?php endif; ?>
<?php if($total): ?><div class="dir-pagination"><span class="muted">Memaparkan <?= $offset+1 ?>–<?= min($offset+$size,$total) ?> daripada <?= $total ?> sekolah</span><?php if($pages>1): ?><nav aria-label="Halaman keputusan"><?php if($page>1): ?><a class="btn outline sm" href="<?= e(directory_url($q,$state,$layout,$page-1)) ?>" rel="prev">Sebelumnya</a><?php endif; ?><span>Halaman <?= $page ?> / <?= $pages ?></span><?php if($page<$pages): ?><a class="btn outline sm" href="<?= e(directory_url($q,$state,$layout,$page+1)) ?>" rel="next">Seterusnya</a><?php endif; ?></nav><?php endif; ?></div><?php endif; ?>
<div class="dir-help" id="bantuan-direktori"><?= icon('help') ?><p>Tidak jumpa sekolah anda? Cuba kod sekolah atau semak penapis.<?php if(registration_open()): ?> <a href="/daftar">Daftar sekolah baharu</a>.<?php endif; ?></p></div>
</section></div></div></div>
<?php lp_foot(); exit;
}
