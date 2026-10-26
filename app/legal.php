<?php
declare(strict_types=1);
/* Terma & Syarat, Polisi Perkhidmatan & Privasi (/terma, /polisi) dan rekod persetujuan (PDPA).
 *  - Teks lalai dijana daripada tetapan semasa (percubaan, pakej Free, tempoh simpanan); boleh disunting di Tetapan Platform
 *    (format ringkas: "## " = tajuk, "- " = senarai).
 *  - Persetujuan direkod (nama, e-mel, konteks, cap versi teks, hash IP) — Panel Platform → Persetujuan (tapis, eksport CSV). */

function legal_default(string $which): string {
    $n=platform_name(); $trial=(int)pset('percubaan_hari'); $del=(int)pset('padam_hari'); $freeOn=pset('free_aktif')==='1';
    if($which==='terma') return "## Perkhidmatan\n$n menyediakan sistem agihan tugas guru dalam talian untuk sekolah. Setiap sekolah mempunyai ruang data tersendiri yang diasingkan daripada sekolah lain.\n"
        ."## Akaun & tanggungjawab\n- Pegawai yang mendaftar mengesahkan beliau diberi kuasa oleh pihak sekolah.\n- Pentadbir sekolah bertanggungjawab ke atas akaun pengguna sekolah dan ketepatan data yang dimasukkan.\n- Kata laluan dan 2FA tidak boleh dikongsi.\n"
        ."## Langganan\n- Sekolah baharu mendapat pakej Pro percuma selama $trial hari selepas diluluskan.\n"
        .($freeOn?"- Selepas Pro tamat, sekolah beralih ke pakej Free dengan had ".(int)pset('free_guru')." guru, ".(int)pset('free_jawatan')." jawatan dan ".(int)pset('free_pengguna')." pengguna aktif.\n":"- Selepas langganan tamat, laman sekolah ditutup sehingga langganan diperbaharui.\n")
        ."- Bayaran langganan tidak dikembalikan selepas tempoh bermula, kecuali dipersetujui secara bertulis.\n"
        ."## Penggunaan yang dilarang\n- Memuat naik kandungan yang menyalahi undang-undang atau data yang tidak berkaitan dengan pengurusan tugas guru.\n- Cubaan mengakses data sekolah lain atau mengganggu sistem.\n"
        ."## Penamatan\nPlatform boleh menggantung akaun yang melanggar terma ini. Sekolah boleh memohon pemadaman data pada bila-bila masa.\n";
    return "## Data yang dikumpul\n- Data guru (nama, e-mel, opsyen/bidang, kapasiti), senarai jawatan, lantikan dan rekod kelulusan agihan tugas.\n- Data pengguna sekolah (nama, e-mel, log aktiviti).\n"
        ."## Tujuan\nData digunakan semata-mata untuk pengurusan agihan tugas di sekolah berkenaan. Data tidak dijual atau dikongsi dengan pihak ketiga.\n"
        ."## Keselamatan\n- Setiap sekolah mempunyai kunci penyulitan tersendiri; data sensitif disulitkan.\n- Akses dilindungi kata laluan kuat dan 2FA; semua tindakan pentadbir direkod.\n"
        ."## Tempoh simpanan\n".($del>0?"- Data sekolah yang langganannya tamat".($freeOn?" (atau pakej Free tanpa log masuk pentadbir selama ".(int)pset('free_tidak_aktif_bulan')." bulan)":'')." dipadam $del hari kemudian, selepas amaran e-mel 30 dan 7 hari sebelum.\n":"- Data disimpan selagi akaun sekolah wujud.\n")
        ."- Sekolah boleh mengeksport atau memohon pemadaman data pada bila-bila masa.\n"
        ."## Hubungi\nPertanyaan berkaitan data peribadi: ".(pset('emel_notis')?:'pentadbir platform').".\n";
}
function legal_text(string $which): string { $t=pset($which==='terma'?'terma_teks':'polisi_teks'); return trim($t)!==''?$t:legal_default($which); }
function legal_version(): string { return substr(hash('sha256',legal_text('terma')."\n".legal_text('polisi')),0,16); }
/** "## Tajuk" & "- senarai" → HTML (semua teks di-escape). */
function legal_html(string $t): string {
    $h=''; $list=false;
    foreach(preg_split('/\R/',$t) as $l){ $l=rtrim($l);
        if(str_starts_with($l,'- ')){ if(!$list){ $h.='<ul>'; $list=true; } $h.='<li>'.e(substr($l,2)).'</li>'; continue; }
        if($list){ $h.='</ul>'; $list=false; }
        if(str_starts_with($l,'## ')) $h.='<h3>'.e(substr($l,3)).'</h3>'; elseif(trim($l)!=='') $h.='<p>'.e($l).'</p>';
    }
    return $h.($list?'</ul>':'');
}
function legal_page(string $which): never {
    lp_head($which==='terma'?'Terma & Syarat':'Polisi Privasi'); ?>
<div class="main-content platform-dir"><div class="page-heading"><div><span class="eyebrow teal"><?= e(mb_strtoupper(platform_name())) ?></span><h1><?= $which==='terma'?'Terma &amp; Syarat':'Polisi Perkhidmatan &amp; Privasi' ?></h1><p class="muted">Versi <?= e(legal_version()) ?></p></div><div class="button-group"><a class="btn outline" href="/">← Direktori</a></div></div>
<section class="panel padded legal-text"><?= legal_html(legal_text($which)) ?></section>
<p class="muted"><a class="text-link" href="/terma">Terma &amp; Syarat</a> · <a class="text-link" href="/polisi">Polisi Privasi</a></p></div><?php lp_foot(); exit;
}
function consent_record(?int $school,string $name,string $email,string $context): void {
    query('INSERT INTO consents(school_id,name,email,context,version,ip_hash) VALUES (?,?,?,?,?,?)',[$school,mb_substr($name,0,120),mb_substr(strtolower($email),0,190),mb_substr($context,0,40),legal_version(),hash_hmac('sha256',$_SERVER['REMOTE_ADDR']??'cli',platform_key())]);
}
function consents_query(string $q): array {
    $w=''; $p=[]; if($q!==''){ $w=' WHERE c.email LIKE ? OR c.name LIKE ? OR s.code LIKE ?'; $l='%'.addcslashes($q,'%_\\').'%'; $p=[$l,$l,$l]; }
    return query('SELECT c.*,s.code,s.name school FROM consents c LEFT JOIN schools s ON s.id=c.school_id'.$w.' ORDER BY c.id DESC LIMIT 1000',$p)->fetchAll();
}
function consents_csv(): never {
    $rows=consents_query(mb_substr(trim(input('q')),0,80)); platform_log('consents_export',null,count($rows).' rekod');
    while(ob_get_level()>0) @ob_end_clean();
    header('Content-Type: text/csv; charset=utf-8'); header('Content-Disposition: attachment; filename="persetujuan-'.date('Y-m-d').'.csv"');
    $f=fopen('php://output','w'); fwrite($f,"\xEF\xBB\xBF"); fputcsv($f,['Tarikh','Sekolah','Nama','E-mel','Konteks','Versi teks'],',','"','');
    foreach($rows as $r) fputcsv($f,array_map(fn($v)=>preg_match('/^[=+\-@]/',(string)$v)?"'".$v:$v,[$r['created_at'],$r['code']?:'(dipadam)',$r['name'],$r['email'],$r['context'],$r['version']]),',','"','');
    fclose($f); exit;
}
function platform_page_consents(): void {
    $q=mb_substr(trim(input('q','get')),0,80); $rows=consents_query($q); ?>
<div class="page-heading"><div><span class="eyebrow teal">PLATFORM</span><h1>Rekod persetujuan</h1><p class="muted">Persetujuan Terma &amp; Polisi semasa pendaftaran sekolah (versi semasa <?= e(legal_version()) ?>).</p></div>
<form method="post" class="button-group"><?= csrf_field() ?><input type="hidden" name="q" value="<?= e($q) ?>"><button class="btn outline" name="action" value="p_consents_csv">Eksport CSV</button></form></div>
<form method="get" class="form-grid flush"><input type="hidden" name="p" value="persetujuan"><label class="field full">Cari (e-mel, nama, kod sekolah)<input name="q" value="<?= e($q) ?>"></label></form>
<section class="panel spaced"><div class="table-wrap"><table><thead><tr><th>Tarikh</th><th>Sekolah</th><th>Nama</th><th>E-mel</th><th>Konteks</th><th>Versi</th></tr></thead><tbody>
<?php foreach($rows as $r): ?><tr><td><?= e(date('d/m/Y H:i',strtotime($r['created_at']))) ?></td><td><?= $r['code']?e($r['code'].' · '.$r['school']):'<span class="muted">(dipadam)</span>' ?></td><td><?= e($r['name']) ?></td><td><?= e($r['email']) ?></td><td><?= e($r['context']) ?></td><td><code><?= e($r['version']) ?></code><?= $r['version']!==legal_version()?' <span class="badge">lama</span>':'' ?></td></tr><?php endforeach; ?>
<?php if(!$rows): ?><tr><td colspan="6" class="muted">Tiada rekod.</td></tr><?php endif; ?></tbody></table></div></section><?php
}
