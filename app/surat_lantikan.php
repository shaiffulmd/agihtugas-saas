<?php
declare(strict_types=1);
/* Surat lantikan tugas & identiti kepala surat (logo sekolah + Jata Negara) setiap sekolah.
 *  - Imej disimpan dalam school_assets (school_id, sha256 kandungan): logo baharu tidak mengubah surat muktamad lama
 *    kerana snapshot revisi merujuk id asal. Tiada perkongsian imej antara sekolah.
 *  - Surat dijana daripada revisi muktamad sahaja (salinan tetap), sebagai HTML (cetak) atau PDF (mPDF). */

/** Gaya kepala surat: [label, keterangan]. Kunci lama (dual/school/national) dikekalkan untuk snapshot terdahulu. */
const LETTER_HEADERS=['dual'=>['Jata Negara + logo sekolah','Jata di kiri, nama & alamat, logo sekolah di kanan'],'dual_tel'=>['Jata Negara + no. telefon','Jata di kiri, nama & alamat, telefon/faks di kanan'],
    'school'=>['Logo sekolah di tengah','Logo sekolah, nama & alamat di tengah'],'national'=>['Jata Negara di tengah','Jata Negara, nama & alamat di tengah']];
/** Bilangan surat bagi setiap guru: [label, keterangan]. */
const LETTER_MODES=['satu'=>['Satu surat','Semua jawatan guru dalam satu surat'],'jawatan'=>['Satu surat bagi setiap jawatan','Guru menerima surat berasingan bagi setiap jawatan']];
/** Format surat untuk snapshot terdahulu (sebelum 1.3) yang tiada school.letter. */
const LETTER_LEGACY=['mode'=>'satu','effective'=>'','para'=>'Kerjasama dan komitmen tuan/puan dalam melaksanakan tanggungjawab ini amat dihargai. Sebarang perubahan tugas akan dimaklumkan oleh pihak pentadbiran sekolah.','slogan'=>'“BERKHIDMAT UNTUK NEGARA”','address'=>'','tel'=>'','fax'=>''];

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
/** Format surat semasa (masuk ke snapshot revisi melalui school_profile(); turut digunakan serta-merta pada surat muktamad — lihat letter_live()). */
function letter_profile(): array {
    $eff=setting('letter_effective');
    return ['mode'=>isset(LETTER_MODES[setting('letter_mode')])?setting('letter_mode'):'satu','effective'=>preg_match('/^\d{4}-\d{2}-\d{2}$/D',$eff)?$eff:'',
        'para'=>setting('letter_para'),'slogan'=>setting('letter_slogan'),'address'=>setting('school_address'),'tel'=>setting('school_tel'),'fax'=>setting('school_fax')];
}
/** Gunakan format surat semasa (kepala, bilangan surat, tarikh berkuat kuasa, perenggan, slogan, alamat, telefon/faks) pada surat muktamad.
 *  Senarai tugas, nama guru, nama sekolah, Pengetua, rujukan & logo kekal daripada snapshot versi muktamad. */
function letter_live(array $doc): array {
    $doc['school']['letter']=letter_profile();
    if(function_exists('trial_letter') && ($t=trial_letter()) && $t['tanda']) $doc['watermark']=true; // sekolah percubaan: tanda air "PERCUBAAN"
    $layout=setting('letter_header'); if(isset(LETTER_HEADERS[$layout])) $doc['school']['branding']['layout']=$layout;
    return $doc;
}
/** Sahkan & simpan format surat. $d: letter_header, letter_mode, letter_effective (Y-m-d atau kosong), letter_para, letter_slogan, [school_tel, school_fax]. */
function letter_settings_save(array $d): void {
    $hd=(string)($d['letter_header']??''); $mode=(string)($d['letter_mode']??''); $eff=trim((string)($d['letter_effective']??''));
    $para=trim((string)($d['letter_para']??'')); $slogan=trim((string)($d['letter_slogan']??''));
    if(!isset(LETTER_HEADERS[$hd])) throw new EngineError('Gaya kepala surat tidak sah.');
    if(!isset(LETTER_MODES[$mode])) throw new EngineError('Pilihan bilangan surat tidak sah.');
    if($eff!=='' && (!preg_match('/^\d{4}-\d{2}-\d{2}$/D',$eff) || !checkdate((int)substr($eff,5,2),(int)substr($eff,8,2),(int)substr($eff,0,4)))) throw new EngineError('Tarikh berkuat kuasa tidak sah.');
    if(mb_strlen($para)>1500) throw new EngineError('Perenggan tanggungjawab maksimum 1,500 aksara.');
    if(mb_strlen($slogan)>200) throw new EngineError('Slogan maksimum 200 aksara.');
    $kv=['letter_header'=>$hd,'letter_mode'=>$mode,'letter_effective'=>$eff,'letter_para'=>$para,'letter_slogan'=>$slogan];
    foreach(['school_tel'=>'Telefon','school_fax'=>'Faks'] as $k=>$l) if(array_key_exists($k,$d)){ $v=trim((string)$d[$k]); if(mb_strlen($v)>40) throw new EngineError("$l maksimum 40 aksara."); $kv[$k]=$v; }
    engine_setting_set($kv);
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
/** Imej kepala surat [kiri, kanan] mengikut gaya dalam snapshot. Gaya tengah: imej di kiri sahaja (dipaparkan di atas nama). */
function letter_emblems(array $school): array {
    $b=$school['branding']??null; if(!$b) return [null,null];
    return match($b['layout']){
        'school'=>[$b['school_logo']??null,null],
        'national','dual_tel'=>[$b['national_crest']??null,null],
        default=>[$b['national_crest']??null,$b['school_logo']??null]};
}
function letter_date_long(string $ymd): string {
    $m=['Januari','Februari','Mac','April','Mei','Jun','Julai','Ogos','September','Oktober','November','Disember'];
    $t=strtotime($ymd); return $t?(int)date('j',$t).' '.$m[(int)date('n',$t)-1].' '.date('Y',$t):$ymd;
}
/** Kepala surat: nama & alamat sekolah dengan Jata/logo atau telefon/faks mengikut gaya. */
function letter_head_html(array $s,array $L,callable $img): string {
    [$left,$right]=letter_emblems($s); $layout=$s['branding']['layout']??'dual'; $title=(string)($s['principal_title']??head_title());
    $tag=fn(?array $a,string $alt)=>$a?'<img src="'.e($img($a)).'" alt="'.$alt.'" class="letter-emblem">':'';
    $name='<strong class="letter-school">'.e(mb_strtoupper((string)$s['school'])).'</strong>'.(trim($L['address'])!==''?'<div class="letter-addr">'.nl2br(e(mb_strtoupper(trim($L['address'])))).'</div>':'');
    if(!isset($s['letter'])) $name.='<p class="letter-office">PEJABAT '.e(mb_strtoupper($title)).'</p>'; // snapshot lama: kepala asal
    if(in_array($layout,['school','national'],true)) return '<header class="letter-head is-center">'.$tag($left,$layout==='school'?'Logo sekolah':'Jata Negara').'<div>'.$name.'</div></header>';
    $r=$layout==='dual_tel'?'<div class="letter-tel">'.(trim($L['tel'])!==''?'NO. TEL : '.e($L['tel']):'').(trim($L['fax'])!==''?'<br>NO. FAKS : '.e($L['fax']):'').'</div>':$tag($right,'Logo sekolah');
    return '<table class="letter-head is-row"><tr><td class="lh-l">'.$tag($left,'Jata Negara').'</td><td class="lh-m">'.$name.'</td><td class="lh-r">'.$r.'</td></tr></table>';
}
/** HTML surat. $img: fn(array $asset): string src (skrin → URL aset; PDF → var:nama). $doc['part'] = no. surat bagi mod satu surat setiap jawatan. */
function letter_html(array $doc,callable $img): string {
    $t=$doc['teacher']; $s=$doc['school']; $title=(string)($s['principal_title']??head_title()); $L=($s['letter']??[])+LETTER_LEGACY; $new=isset($s['letter']);
    $one=count($doc['assignments'])===1; $part=isset($doc['part'])?' / '.(int)$doc['part']:'';
    $h='<article class="letter'.(!empty($doc['watermark'])?' is-trial':'').'">'.letter_head_html($s,$L,$img);
    $h.='<div class="letter-meta">Ruj. Kami: '.e($s['reference']).' ('.(int)$t['id'].' / V'.(int)$doc['revision_no'].$part.')<br>Tarikh: '.e(letter_date($doc['finalized_at'])).'</div>';
    $h.='<p>'.($new?'Kepada,<br>':'').'<strong>'.e($t['name']).'</strong><br>'.e($s['school']).'</p><p>Tuan/Puan,</p>';
    $h.='<h3>'.($new && $one?'PELANTIKAN SEBAGAI '.e(mb_strtoupper((string)$doc['assignments'][0]['name'])).' BAGI SESI '.e($s['year']):'PELANTIKAN TUGAS DAN TANGGUNGJAWAB BAGI SESI '.e($s['year'])).'</h3>';
    $h.='<p>Dengan hormatnya perkara di atas dirujuk.</p><p>2. Sukacita dimaklumkan bahawa tuan/puan dilantik untuk menjalankan tugas berikut bagi sesi '.e($s['year']).($L['effective']!==''?', berkuat kuasa mulai <strong>'.e(letter_date_long($L['effective'])).'</strong>':'').':</p>';
    $h.='<table class="letter-table"><thead><tr><th>Bil.</th><th>Jawatan</th><th>Bidang</th></tr></thead><tbody>';
    foreach($doc['assignments'] as $i=>$p) $h.='<tr><td>'.($i+1).'</td><td>'.e($p['name']).'</td><td>'.e($p['category']).'</td></tr>';
    $h.='</tbody></table>';
    $n=3; if(trim($L['para'])!=='') $h.='<p>'.($n++).'. '.nl2br(e(trim($L['para']))).'</p>';
    $h.='<p>Sekian, terima kasih.</p>'.(trim($L['slogan'])!==''?'<p class="letter-slogan"><strong>'.nl2br(e(trim($L['slogan']))).'</strong></p>':'');
    $h.='<div class="letter-sign">'.($new?'Saya yang menjalankan amanah,<br><br><br>':'').'........................................................<br><strong>'.e($s['principal']).'</strong><br>'.e($title).'<br>'.e($s['school']).'</div>';
    if($new) $h.='<p class="letter-note">Surat ini dijana oleh sistem '.e(app_name()).($doc['revision_no']?' daripada versi muktamad #'.(int)$doc['revision_no']:'').'.</p>';
    return $h.'</article>';
}
/** Mod "satu surat bagi setiap jawatan": pecahkan surat guru kepada satu surat setiap jawatan (bernombor). */
function letter_split(array $doc): array {
    if((($doc['school']['letter']['mode']??'satu')!=='jawatan') || count($doc['assignments'])<2) return [$doc];
    $out=[]; foreach($doc['assignments'] as $i=>$p) $out[]=['assignments'=>[$p],'part'=>$i+1]+$doc;
    return $out;
}
/** Surat bagi guru dalam revisi muktamad yang mempunyai lantikan (dipecah mengikut mod). $only: hadkan kepada ID guru ini. */
function letter_docs(array $published,?int $only=null): array {
    $docs=[]; foreach($published['data']['teachers'] as $t){ if($only!==null && $t['id']!==$only) continue; $d=workflow_letter($t['id'],$published); if($d['assignments']) array_push($docs,...letter_split($d)); }
    return $docs;
}
/** Contoh surat dengan format & profil semasa (draf) — untuk pratonton sebelum dimuktamadkan. */
function letter_sample(): array {
    $ps=query('SELECT name,category FROM positions WHERE school_id='.sid().' ORDER BY FIELD(category,"Pentadbiran","Kurikulum","HEM","Kokurikulum"),name LIMIT 3')->fetchAll();
    if(!$ps) $ps=[['name'=>'Setiausaha Peperiksaan','category'=>'Kurikulum'],['name'=>'Penyelaras Disiplin','category'=>'HEM']];
    return letter_split(['revision_id'=>0,'revision_no'=>0,'school'=>school_profile(),'teacher'=>['id'=>0,'name'=>'NAMA GURU (CONTOH)'],'assignments'=>$ps,'finalized_at'=>null]);
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
        if(array_filter($docs,fn($d)=>!empty($d['watermark']))){ $pdf->SetWatermarkText('PERCUBAAN',0.09); $pdf->showWatermarkText=true; $pdf->watermark_font='dejavusans'; }
        $pdf->WriteHTML('body{font-family:dejavusans;color:#111;line-height:1.55}.letter-head{text-align:center;border-bottom:1.5px solid #222;padding-bottom:6px;margin-bottom:12px}table.letter-head{width:100%;border-collapse:collapse;text-align:left}.lh-l{width:28mm;vertical-align:middle}.lh-m{vertical-align:middle;text-align:center}.lh-r{width:44mm;text-align:right;vertical-align:middle;font-size:8.5pt;white-space:nowrap}.letter-emblem{height:22mm}.letter-school{font-size:13pt}.is-row .letter-school{font-size:11.5pt}.letter-addr{font-size:9pt}.letter-office{font-size:8.5pt;margin:2px 0;color:#444}h3{font-size:11pt;margin:14px 0 6px;text-decoration:underline}.letter-slogan{margin:12px 0}.letter-note{margin-top:20px;font-size:8pt;color:#666;font-style:italic}p{text-align:justify}.letter-meta{text-align:right;font-size:9.5pt;margin-bottom:10px}table.letter-table{width:100%;border-collapse:collapse;margin:8px 0}table.letter-table th,table.letter-table td{border:1px solid #888;padding:5px 7px;font-size:9.5pt;text-align:left}table.letter-table th{background:#eee}.letter-sign{margin-top:28px}',\Mpdf\HTMLParserMode::HEADER_CSS);
        $pdf->WriteHTML($html,\Mpdf\HTMLParserMode::HTML_BODY);
        $bytes=$pdf->Output('',\Mpdf\Output\Destination::STRING_RETURN);
    } finally {
        foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($tmp,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST) as $f){ if($f->isDir() && !$f->isLink()) rmdir($f->getPathname()); else unlink($f->getPathname()); } rmdir($tmp);
    }
    audit('download_pdf','workflow_revisions',(int)($docs[0]['revision_id']??0),count($docs).' surat');
    while(ob_get_level()>0) @ob_end_clean();
    header('Content-Type: application/pdf'); header('Content-Disposition: attachment; filename="'.$fname.'"'); header('Content-Length: '.strlen($bytes)); echo $bytes; exit;
}
