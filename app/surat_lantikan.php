<?php
declare(strict_types=1);
/* Surat lantikan tugas & identiti kepala surat (logo sekolah + Jata Negara) setiap sekolah.
 *  - Imej disimpan dalam school_assets (school_id, sha256 kandungan): logo baharu tidak mengubah surat muktamad lama
 *    kerana snapshot revisi merujuk id asal. Tiada perkongsian imej antara sekolah.
 *  - Surat dijana daripada revisi muktamad sahaja (salinan tetap), sebagai HTML (cetak) atau PDF (mPDF). */

const LETTER_HEADERS=['dual'=>'Jata Negara + logo sekolah','school'=>'Logo sekolah sahaja','national'=>'Jata Negara sahaja'];

function branding_store(string $bytes,int $w,int $h): string {
    $id=hash('sha256',$bytes);
    query('INSERT IGNORE INTO school_assets (school_id,id,mime,width,height,bytes) VALUES ('.sid().',?,"image/png",?,?,?)',[$id,$w,$h,$bytes]);
    return $id;
}
/** Jata Negara disalin ke aset sekolah sekali (aset bersama public_html/assets/shared). */
function branding_install(): void {
    if(setting('national_crest_id')!=='') return;
    $file=dirname(__DIR__).'/public_html/assets/shared/jata-negara.png'; $info=@getimagesize($file); if(!$info) return;
    // Tulis terus (bukan save_settings): boleh dipanggil di dalam transaksi pemanggil, cth. semasa menghantar revisi.
    query('INSERT INTO settings (school_id,name,value) VALUES ('.sid().',"national_crest_id",?) ON DUPLICATE KEY UPDATE value=VALUES(value)',[branding_store((string)file_get_contents($file),$info[0],$info[1])]);
    settings_all(true);
}
/** Simpan tetapan surat tanpa membuka transaksi baharu (tindakan enjin berjalan dalam transaksi). */
function engine_setting_set(array $kv): void {
    foreach($kv as $k=>$v) query('INSERT INTO settings (school_id,name,value) VALUES ('.sid().',?,?) ON DUPLICATE KEY UPDATE value=VALUES(value)',[$k,(string)$v]);
    settings_all(true);
}
function branding_asset(?string $id): ?array {
    if(!$id || !preg_match('/^[a-f0-9]{64}$/D',$id)) return null;
    $a=query('SELECT id,mime,width,height FROM school_assets WHERE school_id='.sid().' AND id=?',[$id])->fetch();
    return $a?[...$a,'width'=>(int)$a['width'],'height'=>(int)$a['height'],'url'=>'/api/v1/assets/'.$a['id']]:null;
}
function branding_profile(): array {
    branding_install();
    $layout=setting('letter_header'); if(!isset(LETTER_HEADERS[$layout])) $layout='dual';
    return ['layout'=>$layout,'school_logo'=>branding_asset(setting('school_logo_id')),'national_crest'=>branding_asset(setting('national_crest_id')),'shared_crest_url'=>'/assets/shared/jata-negara.png'];
}
function branding_image_url(?array $asset): string { return $asset?'/?page=aset&id='.rawurlencode($asset['id']):''; }
/** Logo surat: PNG/JPG/WebP ≤2 MB, ≤4096 px & 4 MP, diproses semula GD → PNG ≤640 px (lutsinar dikekalkan). */
function branding_normalize(string $bytes): string {
    if($bytes==='' || strlen($bytes)>2097152) throw new EngineError('Logo sekolah maksimum 2 MB.');
    $mime=(new finfo(FILEINFO_MIME_TYPE))->buffer($bytes);
    if(!in_array($mime,['image/png','image/jpeg','image/webp'],true)) throw new EngineError('Logo mesti PNG, JPG atau WebP. SVG dan fail lain tidak diterima.');
    $info=@getimagesizefromstring($bytes);
    if(!$info || $info[0]<1 || $info[1]<1 || $info[0]>4096 || $info[1]>4096 || $info[0]*$info[1]>4194304) throw new EngineError('Dimensi logo maksimum 4096 piksel dan 4 megapiksel.');
    $src=@imagecreatefromstring($bytes); if(!$src) throw new EngineError('Imej logo rosak atau tidak dapat dibaca.');
    $scale=min(1,640/max($info[0],$info[1])); $w=max(1,(int)round($info[0]*$scale)); $h=max(1,(int)round($info[1]*$scale));
    $dst=imagecreatetruecolor($w,$h); imagealphablending($dst,false); imagesavealpha($dst,true); imagefill($dst,0,0,imagecolorallocatealpha($dst,0,0,0,127));
    imagecopyresampled($dst,$src,0,0,0,0,$w,$h,$info[0],$info[1]);
    ob_start(); imagepng($dst,null,7); $png=(string)ob_get_clean(); unset($src,$dst);
    if(strlen($png)>2097152) throw new EngineError('Imej selepas diproses melebihi 2 MB.');
    return branding_store($png,$w,$h);
}
function branding_upload(mixed $f): string {
    if(!is_array($f) || !isset($f['error'],$f['tmp_name']) || is_array($f['error']) || $f['error']!==UPLOAD_ERR_OK || !is_uploaded_file((string)$f['tmp_name'])) throw new EngineError('Pilih fail logo sekolah yang sah.');
    return branding_normalize((string)file_get_contents((string)$f['tmp_name']));
}
/** Hantar imej aset sekolah semasa (log masuk diperlukan; API menggunakan laluan sendiri). */
function branding_serve(string $id): never {
    $a=preg_match('/^[a-f0-9]{64}$/D',$id)?query('SELECT mime,bytes FROM school_assets WHERE school_id='.sid().' AND id=?',[$id])->fetch():null;
    if(!$a){ http_response_code(404); exit; }
    while(ob_get_level()>0) @ob_end_clean();
    header_remove('Cache-Control'); header('Content-Type: image/png'); header('Cache-Control: private, max-age=86400, immutable'); header('Content-Disposition: inline; filename="'.$id.'.png"'); header('Content-Length: '.strlen((string)$a['bytes']));
    if(($_SERVER['REQUEST_METHOD']??'GET')!=='HEAD') echo $a['bytes']; exit;
}

/* ---------- Surat ---------- */
function letter_date(?string $dt): string { return $dt?date('d/m/Y',strtotime($dt)):date('d/m/Y'); }
/** Imej kepala surat [kiri, kanan] mengikut gaya dalam snapshot. */
function letter_emblems(array $school): array {
    $b=$school['branding']??null; if(!$b) return [null,null];
    $left=in_array($b['layout'],['dual','national'],true)?($b['national_crest']??null):null;
    $right=in_array($b['layout'],['dual','school'],true)?($b['school_logo']??null):null;
    return [$left,$right];
}
/** HTML surat. $img: fn(array $asset): string src (skrin → URL aset; PDF → var:nama). */
function letter_html(array $doc,callable $img): string {
    $t=$doc['teacher']; $s=$doc['school']; [$left,$right]=letter_emblems($s); $title=(string)($s['principal_title']??head_title());
    $h='<article class="letter"><header class="letter-head"><div class="letter-identities">';
    foreach([[$left,'Jata Negara'],[$right,'Logo sekolah']] as [$a,$alt]) if($a) $h.='<img src="'.e($img($a)).'" alt="'.$alt.'" class="letter-emblem">';
    $h.='</div><h2>'.e($s['school']).'</h2><p>PEJABAT '.e(mb_strtoupper($title)).' · VERSI MUKTAMAD #'.(int)$doc['revision_no'].'</p></header>';
    $h.='<div class="letter-meta">Ruj. Kami: '.e($s['reference']).' ('.(int)$t['id'].' / V'.(int)$doc['revision_no'].')<br>Tarikh: '.e(letter_date($doc['finalized_at'])).'</div>';
    $h.='<p><strong>'.e($t['name']).'</strong><br>'.e($s['school']).'</p><p>Tuan/Puan,</p><h3>PELANTIKAN TUGAS DAN TANGGUNGJAWAB BAGI SESI '.e($s['year']).'</h3>';
    $h.='<p>Dengan hormatnya perkara di atas dirujuk.</p><p>2. Sukacita dimaklumkan bahawa tuan/puan dilantik untuk menjalankan tugas berikut bagi sesi '.e($s['year']).':</p>';
    $h.='<table class="letter-table"><thead><tr><th>Bil.</th><th>Jawatan</th><th>Bidang</th></tr></thead><tbody>';
    foreach($doc['assignments'] as $i=>$p) $h.='<tr><td>'.($i+1).'</td><td>'.e($p['name']).'</td><td>'.e($p['category']).'</td></tr>';
    $h.='</tbody></table><p>3. Kerjasama dan komitmen tuan/puan dalam melaksanakan tanggungjawab ini amat dihargai. Sebarang perubahan tugas akan dimaklumkan oleh pihak pentadbiran sekolah.</p><p>Sekian, terima kasih.</p><p>“BERKHIDMAT UNTUK NEGARA”</p>';
    $h.='<div class="letter-sign">........................................................<br><strong>'.e($s['principal']).'</strong><br>'.e($title).'<br>'.e($s['school']).'</div></article>';
    return $h;
}
/** Surat bagi guru dalam revisi muktamad yang mempunyai lantikan. $only: hadkan kepada ID guru ini. */
function letter_docs(array $published,?int $only=null): array {
    $docs=[]; foreach($published['data']['teachers'] as $t){ if($only!==null && $t['id']!==$only) continue; $d=workflow_letter($t['id'],$published); if($d['assignments']) $docs[]=$d; }
    return $docs;
}
/** PDF surat (satu guru atau semua) — mPDF, imej daripada aset sekolah sahaja. */
function letters_pdf(array $docs,string $fname): never {
    require_once dirname(__DIR__).'/vendor/autoload.php';
    $images=[]; $html='';
    foreach($docs as $i=>$d){
        $html.=($i?'<pagebreak />':'').letter_html($d,function(array $a)use(&$images){ $k='a'.$a['id']; if(!isset($images[$k])) $images[$k]=(string)query('SELECT bytes FROM school_assets WHERE school_id='.sid().' AND id=?',[$a['id']])->fetchColumn(); return 'var:'.$k; });
    }
    $base=dirname(__DIR__).'/storage/pdf'; if(!is_dir($base) && !mkdir($base,0700,true) && !is_dir($base)) throw new RuntimeException('Folder sementara PDF tidak dapat disediakan.');
    $tmp=$base.'/'.bin2hex(random_bytes(16)); mkdir($tmp,0700);
    try {
        $pdf=new \Mpdf\Mpdf(['tempDir'=>$tmp,'format'=>'A4','margin_left'=>20,'margin_right'=>20,'margin_top'=>16,'margin_bottom'=>18,'default_font'=>'dejavusans','default_font_size'=>10.5]);
        $pdf->SetTitle('Surat lantikan'); $pdf->SetAuthor(school_name()); $pdf->imageVars=$images;
        $pdf->WriteHTML('body{font-family:dejavusans;color:#111;line-height:1.55}.letter-head{text-align:center;border-bottom:1.5px solid #222;padding-bottom:6px;margin-bottom:12px}.letter-identities{text-align:center}.letter-emblem{height:22mm;margin:0 6mm}h2{font-size:14pt;margin:4px 0}h3{font-size:11pt;margin:14px 0 6px}.letter-head p{font-size:8.5pt;margin:2px 0;color:#444}.letter-meta{text-align:right;font-size:9.5pt;margin-bottom:10px}table.letter-table{width:100%;border-collapse:collapse;margin:8px 0}table.letter-table th,table.letter-table td{border:1px solid #888;padding:5px 7px;font-size:9.5pt;text-align:left}table.letter-table th{background:#eee}.letter-sign{margin-top:28px}',\Mpdf\HTMLParserMode::HEADER_CSS);
        $pdf->WriteHTML($html,\Mpdf\HTMLParserMode::HTML_BODY);
        $bytes=$pdf->Output('',\Mpdf\Output\Destination::STRING_RETURN);
    } finally {
        foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($tmp,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST) as $f){ if($f->isDir() && !$f->isLink()) rmdir($f->getPathname()); else unlink($f->getPathname()); } rmdir($tmp);
    }
    audit('download_pdf','workflow_revisions',(int)($docs[0]['revision_id']??0),count($docs).' surat');
    while(ob_get_level()>0) @ob_end_clean();
    header('Content-Type: application/pdf'); header('Content-Disposition: attachment; filename="'.$fname.'"'); header('Content-Length: '.strlen($bytes)); echo $bytes; exit;
}
