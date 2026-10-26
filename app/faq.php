<?php
declare(strict_types=1);
/* Soalan lazim (FAQ) platform: diurus di Panel Platform → Soalan Lazim (FAQ), dipaparkan di /faq dan di halaman utama.
 * Disimpan sebagai JSON dalam platform_settings (cfg_faq_items). Jawapan teks biasa: baris kosong = perenggan,
 * "- " = senarai, URL https:// dijadikan pautan. Patuh CSP: tiada style= atau skrip sebaris. */

const FAQ_MAX=50;
const FAQ_SAMPLE=[
    ['Apakah %s?','%s ialah sistem agihan tugas guru dalam talian untuk sekolah rendah (SK) dan menengah (SMK): senarai guru dan jawatan, lantikan, agihan automatik yang seimbang, aliran kelulusan Guru Besar / Pengetua dan surat lantikan — di satu tempat.'],
    ['Siapa yang menggunakan sistem ini di sekolah?',"Empat peranan:\n- Pentadbir: akaun, tetapan sekolah, logo surat dan langganan\n- Penolong Kanan (PK): merancang guru, jawatan dan agihan\n- Guru Besar / Pengetua: menyemak, meluluskan dan memuktamadkan\n- Guru: melihat tugas sendiri dan mencetak surat lantikan"],
    ['Bagaimana agihan automatik memastikan beban adil?','Enjin mendahulukan jawatan yang calonnya terhad, kemudian memilih guru dengan nisbah beban selepas lantikan paling rendah. Had kapasiti mata, bilangan tugas, tugas berat, had setiap bidang dan syarat jawatan (penyandang tetap, guru layak, kepakaran) dipatuhi. Cadangan dipaparkan sebagai pratonton dahulu bersama sebab setiap pilihan — tiada yang berubah sehingga anda menyimpannya.'],
    ['Apa berlaku selepas senarai tugas dihantar untuk semakan?','Data agihan dikunci supaya Guru Besar / Pengetua meluluskan versi yang sama seperti yang dihantar. Selepas dimuktamadkan, surat lantikan dan paparan Tugas Saya menggunakan salinan tetap versi itu. Jika perlu pindaan, pentadbir atau Guru Besar / Pengetua membuka draf pindaan; surat muktamad terdahulu kekal sehingga versi baharu dimuktamadkan.'],
    ['Bagaimana sekolah saya boleh mendaftar?','Klik "Daftar sekolah" di halaman utama dan isi borang menggunakan e-mel rasmi sekolah. Selepas e-mel disahkan dan pendaftaran diluluskan, Pentadbir Utama boleh log masuk di laman sekolah dan mula menambah guru serta jawatan.'],
    ['Saya guru. Bagaimana nak log masuk?','Guru log masuk melalui laman sekolah masing-masing, bukan di halaman utama ini. Cari sekolah anda di Direktori menggunakan kod sekolah KPM atau nama sekolah. Akaun guru dicipta oleh pentadbir sekolah dan dipautkan kepada profil guru anda.'],
    ['Bolehkah kami menambah ramai guru atau jawatan sekaligus?','Boleh. Tampal senarai daripada Excel atau muat naik CSV di Pengurusan Guru dan Tambah Jawatan Pukal. Senarai disemak dahulu (nama berulang, bidang dan pemberat) sebelum disimpan.'],
    ['Bolehkah sistem lain membaca data agihan?','Boleh. Pentadbir sekolah mencipta kunci API di halaman API & Integrasi untuk sistem lain (contohnya Sekolah Koku) menyegerak guru dan membaca lantikan muktamad. Kunci hanya sah untuk sekolah itu dan API tidak boleh meluluskan agihan.'],
    ['Adakah data sekolah kami selamat?','Ya. Data setiap sekolah diasingkan dan maklumat sulit disulitkan dengan kunci sekolah sendiri. Pentadbir wajib menggunakan pengesahan dua langkah (2FA), setiap tindakan direkod dalam log audit kalis usik, dan sandaran disulitkan.'],
    ['Apakah perbezaan pakej Free dan Pro?','Pakej Free mempunyai had bilangan guru, jawatan dan pengguna aktif, dan beberapa ciri lanjutan dikhaskan untuk Pro (lihat bahagian Pakej di halaman utama). Pakej Pro membuka semua ciri tanpa had. Sekolah baharu mendapat Pro percuma untuk tempoh percubaan; data kekal jika kembali ke Free.'],
    ['Bagaimana cara membayar langganan Pro?',"Pentadbir sekolah boleh membayar di menu Langganan melalui:\n- FPX / perbankan dalam talian\n- pindahan bank atau pesanan belian (PO)\n- tajaan PPD"],
];

function faq_items(bool $visibleOnly=false): array {
    $rows=json_decode(pset('faq_items'),true); if(!is_array($rows)) return [];
    $out=[]; foreach($rows as $r) if(is_array($r) && isset($r['id'],$r['q'],$r['a'])) $out[]=['id'=>(int)$r['id'],'q'=>(string)$r['q'],'a'=>(string)$r['a'],'on'=>!empty($r['on'])];
    return $visibleOnly?array_values(array_filter($out,fn($r)=>$r['on'])):$out;
}
function faq_save(array $rows): void { pset_set(['faq_items'=>json_encode(array_values($rows),JSON_UNESCAPED_UNICODE)]); }
function faq_title(): string { return trim(pset('faq_tajuk'))?:'Soalan lazim'; }
function faq_on_landing(): bool { return pset('faq_utama','1')==='1'; }
function faq_landing_limit(): int { return 6; }

/** Jawapan → HTML selamat: perenggan, senarai "- " dan pautan https:// automatik. */
function faq_html(string $a): string {
    $h=''; $list=false; $para=[];
    $link=fn(string $t)=>preg_replace_callback('~https?://[^\s<>"\']+~i',fn($m)=>'<a class="text-link" href="'.$m[0].'" target="_blank" rel="noopener noreferrer">'.$m[0].'</a>',e($t));
    $flush=function() use(&$para,&$h,$link){ if($para){ $h.='<p>'.implode('<br>',array_map($link,$para)).'</p>'; $para=[]; } };
    foreach(preg_split('/\R/',$a) as $l){
        if(str_starts_with(ltrim($l),'- ')){ $flush(); if(!$list){ $h.='<ul>'; $list=true; } $h.='<li>'.$link(substr(ltrim($l),2)).'</li>'; continue; }
        if($list){ $h.='</ul>'; $list=false; }
        if(trim($l)===''){ $flush(); continue; }
        $para[]=$l;
    }
    $flush(); return $h.($list?'</ul>':'');
}

function faq_validate(string $q,string $a): array {
    $q=trim(preg_replace('/\s+/u',' ',$q)??''); $a=trim(str_replace("\r",'',$a));
    if(mb_strlen($q)<3 || mb_strlen($q)>200) throw new DomainException('Soalan mesti 3–200 aksara.');
    if($a==='' || mb_strlen($a)>4000) throw new DomainException('Jawapan mesti 1–4000 aksara.');
    return [$q,$a];
}

function handle_faq_platform(string $action): never {
    $rows=faq_items(); $id=(int)input('faq_id'); $idx=null; foreach($rows as $i=>$r) if($r['id']===$id) $idx=$i;
    if($action==='p_faq_add'){
        if(count($rows)>=FAQ_MAX) throw new DomainException('Had '.FAQ_MAX.' soalan dicapai. Padam soalan lama dahulu.');
        [$q,$a]=faq_validate(input('q'),input('a'));
        $rows[]=['id'=>max(array_merge([0],array_column($rows,'id')))+1,'q'=>$q,'a'=>$a,'on'=>true]; faq_save($rows);
        platform_log('faq_added',null,mb_substr($q,0,120)); flash('Soalan ditambah.'); redirect('/platform/?p=faq');
    }
    if($action==='p_faq_sample'){
        if($rows) redirect('/platform/?p=faq');
        $n=platform_name(); $i=0; foreach(FAQ_SAMPLE as [$q,$a]) $rows[]=['id'=>++$i,'q'=>sprintf($q,$n),'a'=>sprintf($a,$n),'on'=>true];
        faq_save($rows); platform_log('faq_sample'); flash('Contoh soalan dimuatkan. Sunting mengikut keperluan anda.'); redirect('/platform/?p=faq');
    }
    if($action==='p_faq_settings'){
        $t=trim(input('faq_tajuk')); if(mb_strlen($t)>60) throw new DomainException('Tajuk maksimum 60 aksara.');
        pset_set(['faq_utama'=>input('faq_utama')==='1'?'1':'0','faq_tajuk'=>$t]); platform_log('faq_settings'); flash('Tetapan paparan FAQ disimpan.'); redirect('/platform/?p=faq');
    }
    if($idx===null) throw new DomainException('Soalan tidak dijumpai.');
    if($action==='p_faq_edit'){
        [$q,$a]=faq_validate(input('q'),input('a')); $rows[$idx]=['id'=>$id,'q'=>$q,'a'=>$a,'on'=>input('on')==='1']; faq_save($rows);
        platform_log('faq_edited',null,'#'.$id); flash('Soalan dikemas kini.'); redirect('/platform/?p=faq#faq-'.$id);
    }
    if($action==='p_faq_toggle'){ $rows[$idx]['on']=!$rows[$idx]['on']; faq_save($rows); platform_log('faq_toggled',null,'#'.$id); flash($rows[$idx]['on']?'Soalan dipaparkan.':'Soalan disembunyikan.'); redirect('/platform/?p=faq#faq-'.$id); }
    if($action==='p_faq_move'){
        $to=$idx+(input('dir')==='up'?-1:1);
        if(isset($rows[$to])){ [$rows[$idx],$rows[$to]]=[$rows[$to],$rows[$idx]]; faq_save($rows); }
        redirect('/platform/?p=faq#faq-'.$id);
    }
    if($action==='p_faq_delete'){ $q=$rows[$idx]['q']; array_splice($rows,$idx,1); faq_save($rows); platform_log('faq_deleted',null,mb_substr($q,0,120)); flash('Soalan dipadam.'); redirect('/platform/?p=faq'); }
    error_page(400);
}

function platform_page_faq(): void {
    $rows=faq_items(); $vis=count(array_filter($rows,fn($r)=>$r['on'])); $err=(string)($GLOBALS['P_ERROR']??''); $act=input('action');
    $keep=fn(string $k)=>$err!=='' && $act==='p_faq_add'?input($k):''; ?>
<div class="page-heading"><div><span class="eyebrow teal">PLATFORM</span><h1>Soalan Lazim (FAQ)</h1><p class="muted">Soalan dan jawapan untuk pelawat halaman utama. Dipaparkan di <a class="text-link" href="/faq" target="_blank" rel="noopener">/faq</a><?= faq_on_landing()?' dan di halaman utama':'' ?>.</p></div><div class="button-group"><a class="btn outline" href="/faq" target="_blank" rel="noopener">Lihat halaman FAQ ↗</a></div></div>
<div class="faq-adm-grid">
<div class="faq-adm-col">
<section class="panel">
<div class="panel-heading"><div><h3>Senarai soalan</h3><p class="muted"><?= count($rows) ?> soalan · <?= $vis ?> dipaparkan · maks <?= FAQ_MAX ?>. Susunan di sini ialah susunan di halaman awam.</p></div></div>
<?php if(!$rows): ?><div class="faq-empty"><?= icon('help') ?><p><strong>Belum ada soalan.</strong><br><span class="muted">Tambah soalan pertama di sebelah, atau mulakan dengan contoh soalan lazim yang boleh disunting.</span></p><form method="post"><?= csrf_field() ?><button class="btn outline" name="action" value="p_faq_sample">Muatkan contoh soalan</button></form></div>
<?php else: ?><ol class="faq-adm"><?php foreach($rows as $i=>$r): ?><li id="faq-<?= $r['id'] ?>" class="<?= $r['on']?'':'is-off' ?>">
<div class="faq-adm-row"><span class="faq-no"><?= $i+1 ?></span><div class="faq-adm-q"><strong><?= e($r['q']) ?></strong><?php if(!$r['on']): ?> <span class="badge">Tersembunyi</span><?php endif; ?><small class="muted"><?= e(mb_strimwidth(preg_replace('/\s+/u',' ',$r['a'])??'',0,120,'…')) ?></small></div>
<div class="faq-adm-act"><form method="post"><?= csrf_field() ?><input type="hidden" name="faq_id" value="<?= $r['id'] ?>"><input type="hidden" name="action" value="p_faq_move"><button class="btn sm outline" name="dir" value="up" title="Naik" aria-label="Alih ke atas"<?= $i===0?' disabled':'' ?>>↑</button><button class="btn sm outline" name="dir" value="down" title="Turun" aria-label="Alih ke bawah"<?= $i===count($rows)-1?' disabled':'' ?>>↓</button></form>
<form method="post"><?= csrf_field() ?><input type="hidden" name="faq_id" value="<?= $r['id'] ?>"><button class="btn sm outline" name="action" value="p_faq_toggle"><?= $r['on']?'Sembunyi':'Papar' ?></button></form></div></div>
<details class="faq-edit"><summary>Sunting jawapan</summary><form method="post" class="stack"><?= csrf_field() ?><input type="hidden" name="faq_id" value="<?= $r['id'] ?>"><input type="hidden" name="action" value="p_faq_edit">
<label class="field">Soalan<input name="q" value="<?= e($r['q']) ?>" maxlength="200" required></label>
<label class="field">Jawapan<textarea name="a" rows="5" maxlength="4000" required><?= e($r['a']) ?></textarea></label>
<label class="check"><input type="checkbox" name="on" value="1"<?= $r['on']?' checked':'' ?>> Paparkan kepada pelawat</label>
<div class="faq-edit-foot"><button class="btn primary">Simpan</button><button class="btn outline danger-text" name="action" value="p_faq_delete" formnovalidate data-confirm-simple="Padam soalan ini?">Padam</button></div></form></details>
</li><?php endforeach; ?></ol><?php endif; ?>
</section>
</div>
<div class="faq-adm-col">
<section class="panel padded" id="tambah"><h3>Tambah soalan</h3><form method="post" class="stack"><?= csrf_field() ?><input type="hidden" name="action" value="p_faq_add">
<label class="field">Soalan<input name="q" value="<?= e($keep('q')) ?>" maxlength="200" required placeholder="cth. Bagaimana guru log masuk?"></label>
<label class="field">Jawapan<textarea name="a" rows="6" maxlength="4000" required placeholder="Tulis jawapan yang ringkas dan jelas."><?= e($keep('a')) ?></textarea><small class="muted">Baris kosong = perenggan baharu · mulakan baris dengan <code>- </code> untuk senarai · pautan <code>https://</code> boleh diklik.</small></label>
<div><button class="btn primary"><?= icon('plus') ?> Tambah soalan</button></div></form></section>
<section class="panel padded"><h3>Paparan</h3><form method="post" class="stack"><?= csrf_field() ?><input type="hidden" name="action" value="p_faq_settings">
<label class="field">Tajuk bahagian<input name="faq_tajuk" value="<?= e(trim(pset('faq_tajuk'))) ?>" maxlength="60" placeholder="Soalan lazim"></label>
<label class="check"><input type="checkbox" name="faq_utama" value="1"<?= faq_on_landing()?' checked':'' ?>> Paparkan <?= faq_landing_limit() ?> soalan pertama di halaman utama</label>
<p class="muted">Pautan "Soalan lazim" dipaparkan di menu dan footer halaman awam apabila ada sekurang-kurangnya satu soalan dipaparkan.</p>
<div><button class="btn primary">Simpan paparan</button></div></form></section>
</div>
</div><?php
}

/** Senarai FAQ awam (accordion <details>, tanpa JS). */
function faq_list_html(array $rows): string {
    $h='<div class="faq-list">'; foreach($rows as $r) $h.='<details class="faq-q" id="soalan-'.$r['id'].'"><summary><span>'.e($r['q']).'</span>'.icon('chev-r').'</summary><div class="faq-a">'.faq_html($r['a']).'</div></details>';
    return $h.'</div>';
}
/** Bahagian FAQ di halaman utama. */
function faq_landing_section(): void {
    if(!faq_on_landing() || !($rows=faq_items(true))) return; $more=count($rows)>faq_landing_limit(); ?>
<section class="lp-seksyen lp-alt" id="faq"><div class="lp-wrap lp-faq">
<div class="lp-kepala"><span class="eyebrow teal">Bantuan</span><h2><?= e(faq_title()) ?></h2><p>Jawapan ringkas kepada soalan yang kerap ditanya oleh sekolah dan guru.</p></div>
<?= faq_list_html(array_slice($rows,0,faq_landing_limit())) ?>
<?php if($more): ?><p class="lp-tengah"><a class="btn outline" href="/faq">Lihat semua <?= count($rows) ?> soalan →</a></p><?php endif; ?>
</div></section><?php
}
/** /faq — halaman awam penuh. */
function faq_page(): never {
    $rows=faq_items(true); $email=pset('emel_notis');
    lp_head(faq_title()); ?>
<section class="lp-seksyen"><div class="lp-wrap lp-faq">
<div class="lp-kepala"><span class="eyebrow teal">Bantuan</span><h1 class="faq-h1"><?= e(faq_title()) ?></h1><p>Jawapan kepada soalan yang kerap ditanya tentang <?= e(platform_name()) ?>.</p></div>
<?php if(!$rows): ?><p class="lp-tengah muted">Belum ada soalan lazim.</p><?php else: ?><?= faq_list_html($rows) ?><?php endif; ?>
<div class="panel padded faq-cta"><div><h2>Tidak jumpa jawapan?</h2><p class="muted">Guru dan pentadbir sekolah boleh menghantar tiket melalui menu Sokongan di laman sekolah masing-masing.<?= $email!==''?' Pertanyaan umum: <a class="text-link" href="mailto:'.e($email).'">'.e($email).'</a>':'' ?></p></div><a class="btn primary" href="/direktori">Cari sekolah anda <?= icon('arrow') ?></a></div>
</div></section>
<?php lp_foot(); exit;
}
