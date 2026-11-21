<?php
declare(strict_types=1);
/* Buku Pengurusan Sekolah (Senarai Tugas → Cetak / PDF), ikut format buku pengurusan sekolah:
 *   Kulit → Senarai Kandungan → 1.0 PERKARA AM (pendahuluan boleh disunting + Senarai Guru automatik)
 *   → 2.0 Pentadbiran, 3.0 Kurikulum, 4.0 HEM, 5.0 Kokurikulum, 6.0 PPU, 7.0 PPKI: halaman pemisah, n.1 Carta Organisasi,
 *     n.2 … jadual jawatankuasa (tajuk biru tua, Jawatan | Nama | Catatan), Senarai Ketua Panitia (ikut bidang & GKMP), Senarai Guru Kelas.
 * Pendahuluan disimpan sebagai teks bertanda ringkas dalam tetapan sekolah 'book_intro' (lihat BOOK_INTRO_HELP). */

const BOOK_SECTION_TITLES=['Pentadbiran'=>'PENGURUSAN PENTADBIRAN','Kurikulum'=>'PENGURUSAN KURIKULUM','HEM'=>'PENGURUSAN HAL EHWAL MURID','Kokurikulum'=>'PENGURUSAN KOKURIKULUM',
    't6'=>'PENGURUSAN PROGRAM PRAUNIVERSITI (PPU)','ppki'=>'PENGURUSAN PROGRAM PENDIDIKAN KHAS INTEGRASI (PPKI)'];
const BOOK_CAT_TITLES=['Pentadbiran'=>'JAWATANKUASA PENGURUSAN','Kurikulum'=>'JAWATANKUASA PENGURUSAN KURIKULUM','HEM'=>'JAWATANKUASA PENGURUSAN HAL EHWAL MURID','Kokurikulum'=>'JAWATANKUASA PENGURUSAN KOKURIKULUM'];
const BOOK_INTRO_MAX=200000;
/** Kumpulan mata pelajaran lalai (bidang KPM sekolah menengah) jika katalog Mata Pelajaran tiada kumpulan untuk MP itu. */
const BOOK_SUBJECT_GROUPS=['bahasa melayu'=>'Bahasa','bahasa inggeris'=>'Bahasa','bahasa arab'=>'Bahasa','bahasa cina'=>'Bahasa','bahasa tamil'=>'Bahasa','kesusasteraan melayu'=>'Bahasa','literature in english'=>'Bahasa',
    'sains'=>'Sains & Matematik','matematik'=>'Sains & Matematik','matematik tambahan'=>'Sains & Matematik','fizik'=>'Sains & Matematik','kimia'=>'Sains & Matematik','biologi'=>'Sains & Matematik','sains tambahan'=>'Sains & Matematik',
    'sejarah'=>'Kemanusiaan','geografi'=>'Kemanusiaan','pendidikan islam'=>'Kemanusiaan','pendidikan moral'=>'Kemanusiaan','pendidikan jasmani dan kesihatan'=>'Kemanusiaan','pendidikan seni visual'=>'Kemanusiaan','tasawwur islam'=>'Kemanusiaan','tasawur islam'=>'Kemanusiaan','sains sukan'=>'Kemanusiaan','pendidikan muzik'=>'Kemanusiaan','pendidikan al-quran dan as-sunnah'=>'Kemanusiaan','pendidikan syariah islamiah'=>'Kemanusiaan',
    'asas sains komputer'=>'Teknik & Vokasional','asas sains komputer / sains komputer'=>'Teknik & Vokasional','sains komputer'=>'Teknik & Vokasional','reka bentuk dan teknologi'=>'Teknik & Vokasional','reka bentuk teknologi'=>'Teknik & Vokasional','teknologi dan digital'=>'Teknik & Vokasional',
    'perniagaan'=>'Teknik & Vokasional','prinsip perakaunan'=>'Teknik & Vokasional','ekonomi'=>'Teknik & Vokasional','ekonomi asas'=>'Teknik & Vokasional','sains rumah tangga'=>'Teknik & Vokasional','pertanian'=>'Teknik & Vokasional','grafik komunikasi teknikal'=>'Teknik & Vokasional','lukisan kejuruteraan'=>'Teknik & Vokasional'];
const BOOK_INTRO_HELP='# TAJUK BAHAGIAN (cth. PENGENALAN) · ## Tajuk halaman (cth. Dari Meja Pengetua — setiap ## bermula di halaman baharu) · ### Subtajuk · baris kosong = perenggan baharu · "- " = senarai · "> " = baris di tengah (slogan, lagu) · "Label : nilai" berturutan = jadual (profil) · **tebal**';
/** Templat asas pendahuluan (kandungan umum KPM + ruang maklumat sekolah). Sekolah menyunting & menyimpan versi sendiri. */
const BOOK_INTRO_TEMPLATE=<<<'TXT'
# PENGENALAN

## Dari Meja Pengetua
Assalamualaikum Warahmatullahi Wabarakatuh dan salam sejahtera.

(Tulis kata-kata aluan Pengetua / Guru Besar di sini.)

Sekian, terima kasih.

**(NAMA PENGETUA)**
Pengetua

## Profil Sekolah
Nama sekolah :
Kod Sekolah :
PPD :
Alamat Sekolah :
No Telefon :
E-mel :
Sesi :
Bilangan Guru :
Bilangan AKP :
Tahun Tubuh Sekolah :

## Sejarah Sekolah
(Ringkasan sejarah sekolah.)

# HALA TUJU SEKOLAH

## Falsafah Pendidikan Malaysia
> “Pendidikan di Malaysia adalah suatu usaha berterusan ke arah memperkembangkan lagi potensi individu secara menyeluruh dan bersepadu untuk mewujudkan insan yang seimbang dan harmonis dari segi intelek, rohani, emosi dan jasmani berdasarkan kepercayaan dan kepatuhan kepada Tuhan. Usaha ini adalah bagi melahirkan rakyat Malaysia yang berilmu pengetahuan, berketerampilan, berakhlak mulia, bertanggungjawab dan berkeupayaan mencapai kesejahteraan diri, serta memberi sumbangan terhadap keharmonian dan kemakmuran keluarga, masyarakat dan negara.”

## Visi, Misi dan Slogan KPM
### VISI
> Pendidikan Berkualiti, Insan Terdidik, Negara Sejahtera
### MISI
> Melestarikan Sistem Pendidikan yang Berkualiti untuk membangunkan Potensi Individu bagi Memenuhi Aspirasi Negara
### SLOGAN KPM
> Murid Ceria, Guru Bahagia, Sekolah Bitara & Negara Sejahtera

## 7 Teras KPM
- Komunikasi antara kementerian & sekolah lebih cekap dan telus
- Memberi penekanan kepada aspek karamah insaniah melalui pembentukan adab, akhlak dan integriti
- Kemiskinan berbilang dimensi B40: malnutrisi, peranti & ruang belajar
- Keciciran pendidikan & masalah literasi, rapatkan kesenjangan pendidikan
- Kebajikan guru sebagai teras keberkesanan penyampaian pendidikan
- Ubah suai dan naik taraf sekolah daif terutama Sabah & Sarawak
- Peningkatan keupayaan pendidikan digital di sekolah

## Hala Tuju, Tagline & Moto Sekolah
### HALA TUJU
> (Hala tuju sekolah)
### TAGLINE
> (Tagline sekolah)
### MOTO
> (Moto sekolah)

## Piagam Pelanggan
Kami warga sekolah dengan penuh tekad dan iltizam, berikrar dan berjanji akan menumpukan seluruh usaha dan tenaga kami untuk:
- (Janji 1)
- (Janji 2)

# PANDUAN AM

## Panduan Am & Dasar Sekolah
### PANDUAN AM GURU
- (Panduan)
TXT;

/** Pendahuluan semasa (tetapan sekolah); kosong = tiada bahagian Perkara Am. */
function book_intro(): string { return setting('book_intro'); }
function book_intro_save(string $text): void {
    $text=str_replace(["\r\n","\r"],"\n",$text);
    if(mb_strlen($text)>BOOK_INTRO_MAX) throw new EngineError('Pendahuluan maksimum '.number_format(BOOK_INTRO_MAX).' aksara.');
    engine_setting_set(['book_intro'=>rtrim($text)]);
}
/** Hurai teks bertanda: [{title, items => [{title, blocks => [[jenis, isi]]}]}]. Jenis: p, h, ul, center, dl. */
function book_intro_parse(string $text): array {
    $groups=[]; $g=-1; $i=-1; $lines=[];
    $flush=function()use(&$groups,&$g,&$i,&$lines){ if($g>=0 && $i>=0) $groups[$g]['items'][$i]['blocks']=book_intro_blocks($lines); $lines=[]; };
    foreach(explode("\n",str_replace(["\r\n","\r"],"\n",$text)) as $raw){
        $l=rtrim($raw);
        if(preg_match('/^#(?!#)\s*(.+)$/u',$l,$m)){ $flush(); $groups[]=['title'=>trim($m[1]),'items'=>[]]; $g=count($groups)-1; $i=-1; continue; }
        if(preg_match('/^##(?!#)\s*(.+)$/u',$l,$m)){ $flush(); if($g<0){ $groups[]=['title'=>'PENDAHULUAN','items'=>[]]; $g=0; } $groups[$g]['items'][]=['title'=>trim($m[1]),'blocks'=>[]]; $i=count($groups[$g]['items'])-1; continue; }
        if(($g<0 || $i<0) && trim($l)==='') continue;
        if($g<0){ $groups[]=['title'=>'PENDAHULUAN','items'=>[]]; $g=0; }
        if($i<0){ $groups[$g]['items'][]=['title'=>$groups[$g]['title'],'blocks'=>[]]; $i=count($groups[$g]['items'])-1; }
        $lines[]=$l;
    }
    $flush();
    return array_values(array_filter($groups,fn($x)=>$x['items']));
}
function book_intro_blocks(array $lines): array {
    $out=[]; $para=[];
    $endPara=function()use(&$out,&$para){ if($para){ $out[]=['p',implode("\n",$para)]; $para=[]; } };
    $n=count($lines);
    for($k=0;$k<$n;$k++){
        $l=$lines[$k]; $t=trim($l);
        if($t===''){ $endPara(); continue; }
        if(preg_match('/^###\s*(.+)$/u',$t,$m)){ $endPara(); $out[]=['h',$m[1]]; continue; }
        if(preg_match('/^[-•]\s+(.+)$/u',$t,$m)){ $endPara(); if(($out[count($out)-1][0]??'')==='ul') $out[count($out)-1][1][]=$m[1]; else $out[]=['ul',[$m[1]]]; continue; }
        if(preg_match('/^>\s?(.*)$/u',$t,$m)){ $endPara(); if(($out[count($out)-1][0]??'')==='center') $out[count($out)-1][1][]=$m[1]; else $out[]=['center',[$m[1]]]; continue; }
        $kv=fn(string $x)=>preg_match('/^([^:]{1,40}?)\s+:\s*(.*)$/u',trim($x),$mm)?[trim($mm[1]),trim($mm[2])]:null;
        if(($p=$kv($t)) && ((($out[count($out)-1][0]??'')==='dl' && !$para) || ($k+1<$n && $kv($lines[$k+1])))){
            $endPara(); if(($out[count($out)-1][0]??'')==='dl') $out[count($out)-1][1][]=$p; else $out[]=['dl',[$p]]; continue; }
        $para[]=$t;
    }
    $endPara();
    return $out;
}
function book_inline(string $s): string { return (string)preg_replace('/\*\*(.+?)\*\*/u','<strong>$1</strong>',e($s)); }

/* ---------- Komponen visual ---------- */
function book_pill(string $no,string $title,string $id=''): string {
    return '<table class="bk-pill"'.($id!==''?' id="'.e($id).'"':'').'><tr><td class="bk-pill-no">'.e($no).'</td><td class="bk-pill-t">'.e(mb_strtoupper($title)).'</td></tr></table>';
}
function book_divider(string $no,string $title,bool $pdf): string {
    return ($pdf?'<pagebreak />':'').'<div class="bk-div"><div class="bk-div-no">'.e($no).'</div><div class="bk-div-t">'.e($title).'</div><div class="bk-div-s">'.e(mb_strtoupper(school_name())).'</div></div>';
}
/** Jadual satu jawatankuasa: tajuk biru tua merentas lajur; Jawatan | Nama | Catatan; jawatan sama berturutan digabung (rowspan). */
function book_jk_table(string $title,array $rows,string $id=''): string {
    $h='<table class="book-table book-jk"'.($id!==''?' id="'.e($id).'"':'').'><thead><tr><th colspan="3" class="book-com">'.e(book_title($title)).'</th></tr></thead><tbody>';
    for($i=0,$n=count($rows);$i<$n;$i=$j+1){
        $j=$i; while($j+1<$n && !$rows[$i][3] && !$rows[$j+1][3] && mb_strtolower($rows[$j+1][0])===mb_strtolower($rows[$i][0])) $j++;
        for($k=$i;$k<=$j;$k++){ [$role,$t,$nt,$isLead]=$rows[$k];
            $h.='<tr'.($isLead?' class="book-lead"':'').'>'.($k===$i?'<td class="c-role"'.($j>$i?' rowspan="'.($j-$i+1).'"':'').'>'.e($role).'</td>':'').'<td class="c-name">'.($t!==null?e(mb_strtoupper($t)):'<em class="vacant">— belum diisi —</em>').'</td><td class="c-note">'.e($nt).'</td></tr>'; }
    }
    return $h.'</tbody></table>';
}
/** Baris jadual JK: pimpinan (Pengerusi/Timbalan/Naib dari Carta Organisasi) + ahli; [peranan, guru|null, catatan, pimpinan?]. */
function book_jk_rows(string $com,array $rows,array $lead,bool $withLead): array {
    $out=[];
    if($withLead && preg_match('/^(JK|Jawatankuasa)\b/iu',$com) && !array_filter($rows,fn($r)=>$r['rank']<=3)) foreach($lead as [$role,$name,$label]) $out[]=[$role,$name,$label,true];
    foreach($rows as $r){ [$role,$note]=book_note($r['role']); $out[]=[$role,$r['teacher'],$note,false]; }
    return $out;
}
/** Carta organisasi bahagian: Pengetua → PK bahagian → senarai jawatankuasa (kotak, dua lajur). */
function book_carta(string $title,array $lead,array $coms): string {
    $top=$lead[0]??null; $pk=$lead[1]??null;
    $cell=fn(string $html,string $cls,int $span=2)=>'<tr><td colspan="'.$span.'" class="bk-org-sp"></td></tr><tr><td class="bk-org-gap"></td><td class="bk-box '.$cls.'">'.$html.'</td><td class="bk-org-gap"></td></tr>';
    $who=fn(array $p)=>'<strong>'.e(mb_strtoupper($p[2])).'</strong><br><span>('.e(mb_strtoupper($p[1])).')</span>';
    $h='<div class="bk-carta"><div class="bk-carta-title">CARTA ORGANISASI '.e($title).'</div><table class="bk-org-top">';
    if($top) $h.=$cell($who($top),'bk-box-top',3); if($pk) $h.=$cell($who($pk),'bk-box-pk',3); if($coms) $h.=$cell('<strong>SETIAUSAHA</strong>','bk-box-su',3);
    $h.='</table>';
    if($coms){ $h.='<table class="bk-org">'; foreach(array_chunk($coms,2) as $pair) $h.='<tr>'.implode('',array_map(fn($c)=>'<td class="bk-box bk-box-jk">'.e(book_title($c)).'</td>',$pair)).(count($pair)<2?'<td class="bk-org-gap"></td>':'').'</tr>'; $h.='</table>'; }
    return $h.'</div>';
}
/** Senarai Ketua Panitia ikut bidang (kumpulan mata pelajaran) & GKMP (Ketua Bidang di Carta Organisasi). $pan: [nama panitia => baris]. */
function book_panitia(array $pan): string {
    require_once __DIR__.'/organisasi.php';
    $groups=subject_groups()+BOOK_SUBJECT_GROUPS; $tn=array_column(query('SELECT id,name FROM teachers WHERE school_id='.sid())->fetchAll(),'name','id');
    $norm=fn(string $s)=>mb_strtolower(trim((string)preg_replace(['/\s*&\s*/u','/\s+/u'],[' dan ',' '],$s)));
    $gkmp=[]; foreach(leadership()['bidang'] as [$b,$tid]) $gkmp[$norm($b)]=[$b,$tid!==null?($tn[$tid]??null):null];
    $by=[];
    foreach($pan as $name=>$rows){ $subj=trim((string)preg_replace('/^Panitia\s+/iu','',$name)); $g=$groups[mb_strtolower($subj)]??'Lain-lain';
        foreach($rows as $r) $by[$g][]=[$subj,$r['teacher']]; }
    $ord=array_flip(array_keys($gkmp)); uksort($by,fn($a,$b)=>[$a==='Lain-lain',$ord[$norm($a)]??99,$a]<=>[$b==='Lain-lain',$ord[$norm($b)]??99,$b]);
    $col=['#e8505b','#f0932b','#c039c9','#6c9be8','#27ae60','#16a085','#7f8c8d']; $ci=0;
    $h='<table class="book-table bk-pan"><thead><tr><th colspan="3" class="book-com">JAWATANKUASA PANITIA MATA PELAJARAN</th></tr><tr class="bk-pan-h"><th>BIDANG</th><th>PANITIA</th><th>KETUA PANITIA / PENYELARAS</th></tr></thead><tbody>';
    foreach($by as $g=>$list){ $c=$col[$ci++%count($col)]; $gk=$gkmp[$norm($g)]??null; $span=count($list)+($gk?1:0);
        $h.='<tr><td class="bk-pan-g" rowspan="'.$span.'" style="background:'.$c.'">'.e(mb_strtoupper($g)).'</td>';
        if($gk) $h.='<td colspan="2" class="bk-pan-gkmp">GKMP : '.e(mb_strtoupper($gk[1]??'—')).'</td></tr><tr>';
        foreach($list as $i=>[$s,$t]) $h.=($i?'<tr>':'').'<td>'.e($s).'</td><td>'.($t!==null?e(mb_strtoupper($t)):'<em class="vacant">— belum diisi —</em>').'</td></tr>';
    }
    return $h.'</tbody></table>';
}
/** Senarai Guru Kelas: Kelas | Guru | Catatan; warna berselang ikut nama kelas (perkataan pertama). */
function book_guru_kelas(array $rows,string $year): string {
    $list=[]; foreach($rows as $r){ [$role,$note]=book_note($r['role']); $list[]=[trim((string)preg_replace('/^Guru Kelas\s*/iu','',$role)),$r['teacher'],$note]; }
    usort($list,fn($a,$b)=>strnatcasecmp($a[0],$b[0]));
    $h='<table class="book-table bk-kelas"><thead><tr><th colspan="3" class="book-com">GURU KELAS '.e($year).'</th></tr><tr class="bk-pan-h"><th>KELAS</th><th>GURU</th><th>CATATAN</th></tr></thead><tbody>'; $prev=null; $alt=0;
    foreach($list as [$k,$t,$n]){ $f=mb_strtolower((string)strtok($k,' /')); if($prev!==null && $f!==$prev) $alt^=1; $prev=$f;
        $h.='<tr class="'.($alt?'bk-k2':'bk-k1').'"><td>'.e(mb_strtoupper($k)).'</td><td>'.($t!==null?e(mb_strtoupper($t)):'<em class="vacant">— belum diisi —</em>').'</td><td>'.e(mb_strtoupper($n)).'</td></tr>'; }
    return $h.'</tbody></table>';
}
/** Senarai guru (1.0 Perkara Am): kepimpinan, Ketua Bidang / GKMP, semua guru dengan jawatan utama. */
function book_teachers(): array {
    require_once __DIR__.'/organisasi.php';
    $l=leadership(); $labels=leader_posts(); $tn=array_column(query('SELECT id,name FROM teachers WHERE school_id='.sid().' ORDER BY name')->fetchAll(),'name','id');
    $items=[];
    $rows=[]; foreach($l['posts'] as $k=>$tid) if($tid && isset($tn[$tid])) $rows[]=[$tn[$tid],$labels[$k]];
    if($rows) $items['Kepimpinan Sekolah']=$rows;
    $rows=[]; foreach($l['bidang'] as [$b,$tid]) if($tid && isset($tn[$tid])) $rows[]=[$tn[$tid],'Ketua Bidang '.$b];
    if($rows) $items['Guru Kanan Mata Pelajaran']=$rows;
    $lab=leadership_by_teacher($l); $rows=[]; foreach($tn as $id=>$n) $rows[]=[$n,$lab[$id]??'Guru Akademik'];
    if($rows) $items['Senarai Guru']=$rows;
    $out=[]; foreach($items as $t=>$rows){ $h='<table class="book-table bk-list"><thead><tr class="bk-pan-h"><th class="c-bil">BIL</th><th>NAMA</th><th>JAWATAN</th></tr></thead><tbody>';
        foreach($rows as $i=>[$n,$j]) $h.='<tr><td class="c-bil">'.($i+1).'</td><td>'.e(mb_strtoupper($n)).'</td><td>'.e($j).'</td></tr>'; $out[$t]=$h.'</tbody></table>'; }
    return $out;
}

/** Nombor bahagian tetap: Pentadbiran 2.0, Kurikulum 3.0, HEM 4.0, Kokurikulum 5.0, kemudian unit sekolah (PPU, PPKI). */
function book_section_numbers(): array {
    $keys=array_map(fn($c)=>'sekolah|'.$c,CATEGORIES); $su=function_exists('school_units')?school_units():[]; foreach(['t6','ppki'] as $u) if(in_array($u,$su,true)) $keys[]=$u;
    $n=[]; foreach($keys as $i=>$k) $n[$k]=$i+2; foreach(['t6','ppki'] as $u) $n[$u]??=count($n)+2; return $n;
}
/** Susunan jawatankuasa: JK induk / pengurusan dahulu, kemudian ikut abjad. */
function book_com_sort(array $coms): array {
    uksort($coms,fn($a,$b)=>[!preg_match('/^JK\s+(Induk\b|Pengurusan(\s+(Sekolah|Kurikulum|Kokurikulum|HEM|Hal Ehwal Murid|PPU|PPKI))?$)/iu',$a),$a]<=>[!preg_match('/^JK\s+(Induk\b|Pengurusan(\s+(Sekolah|Kurikulum|Kokurikulum|HEM|Hal Ehwal Murid|PPU|PPKI))?$)/iu',$b),$b]);
    return $coms;
}
/** Bina kandungan buku: [toc => [[tahap, no, tajuk, anchor]], html]. $full: kulit + kandungan + Perkara Am. */
function book_render(array $book,bool $pdf,bool $lead,bool $full): array {
    $toc=[]; $h=''; $year=setting('session_year')?:date('Y'); $secNo=book_section_numbers(); $tocE=function(int $lv,string $no,string $t,string $id)use(&$toc,$pdf){ $toc[]=[$lv,$no,$t,$id]; return $pdf?'<tocentry content="'.e(trim($no.'  '.$t)).'" level="'.$lv.'" />':''; };
    if($full){
        $groups=book_intro_parse(book_intro()); $tch=book_teachers(); if($tch) $groups[]=['title'=>'SENARAI GURU','items'=>array_map(fn($t,$html)=>['title'=>$t,'html'=>$html],array_keys($tch),$tch)];
        if($groups){
            $h.=book_divider('1.0','PERKARA AM',$pdf).$tocE(0,'1.0','PERKARA AM','bk-1');
            foreach($groups as $gi=>$g){ $gno='1.'.($gi+1); $h.=$tocE(1,$gno,mb_strtoupper($g['title']),'bk-'.str_replace('.','-',$gno));
                foreach($g['items'] as $ii=>$it){ $no=$gno.'.'.($ii+1); $id='bk-'.str_replace('.','-',$no);
                    $h.=($pdf?'<pagebreak />':'').$tocE(2,$no,$it['title'],$id).book_pill($no,$it['title'],$id).'<div class="bk-intro">';
                    if(isset($it['html'])) $h.=$it['html'];
                    else foreach($it['blocks'] as [$k,$v]) $h.=match($k){
                        'h'=>'<h3 class="bk-h">'.book_inline($v).'</h3>',
                        'ul'=>'<ul>'.implode('',array_map(fn($x)=>'<li>'.book_inline($x).'</li>',$v)).'</ul>',
                        'center'=>'<div class="bk-center">'.implode('<br>',array_map('book_inline',$v)).'</div>',
                        'dl'=>'<table class="bk-dl">'.implode('',array_map(fn($x)=>'<tr><td class="bk-dl-k">'.book_inline($x[0]).'</td><td class="bk-dl-s">:</td><td>'.book_inline($x[1]).'</td></tr>',$v)).'</table>',
                        default=>'<p>'.str_replace("\n",'<br>',book_inline($v)).'</p>'};
                    $h.='</div>'; } }
        }
    }
    foreach($book as $x){ if(!$x['cats']) continue;
        $key=$x['unit']==='sekolah'?'sekolah|'.array_key_first($x['cats']):$x['unit']; $n=$secNo[$key]??(count($secNo)+2);
        $title=$x['unit']==='sekolah'?BOOK_SECTION_TITLES[array_key_first($x['cats'])]:BOOK_SECTION_TITLES[$x['unit']];
        $h.=book_divider($n.'.0',$title,$pdf).$tocE(0,$n.'.0',$title,'bk-'.$n);
        // n.1 carta organisasi
        $coms=[]; foreach($x['cats'] as $c=>$cs) foreach(array_keys(book_com_sort($cs)) as $cn) if(!preg_match('/^Panitia\b/iu',$cn) && mb_strtolower($cn)!=='guru kelas') $coms[]=$cn;
        $sub=1; $no=$n.'.'.$sub++;
        $h.=($pdf?'<pagebreak />':'').$tocE(1,$no,'CARTA ORGANISASI','bk-'.str_replace('.','-',$no)).book_pill($no,'Carta Organisasi','bk-'.str_replace('.','-',$no)).book_carta(preg_replace('/^PENGURUSAN\s+/u','PENGURUSAN ',$title),$x['lead']??[],$coms);
        foreach($x['cats'] as $c=>$cs){
            $cs=book_com_sort($cs); $pan=[]; $kelas=[]; $jk=[];
            foreach($cs as $cn=>$rows){ if(preg_match('/^Panitia\b/iu',$cn)) $pan[$cn]=$rows; elseif(mb_strtolower($cn)==='guru kelas') $kelas=array_merge($kelas,$rows); else $jk[$cn]=$rows; }
            if($jk){ $no=$n.'.'.$sub++; $t=BOOK_CAT_TITLES[$c]; $h.=($pdf?'<pagebreak />':'').$tocE(1,$no,$t,'bk-'.str_replace('.','-',$no)).book_pill($no,$t,'bk-'.str_replace('.','-',$no));
                foreach($jk as $cn=>$rows) $h.=book_jk_table($cn,book_jk_rows($cn,$rows,$x['lead']??[],$lead)); }
            if($pan){ $no=$n.'.'.$sub++; $h.=($pdf?'<pagebreak />':'').$tocE(1,$no,'SENARAI KETUA PANITIA MATA PELAJARAN','bk-'.str_replace('.','-',$no)).book_pill($no,'Senarai Ketua Panitia Mata Pelajaran','bk-'.str_replace('.','-',$no)).book_panitia($pan); }
            if($kelas){ $no=$n.'.'.$sub++; $h.=($pdf?'<pagebreak />':'').$tocE(1,$no,'SENARAI GURU KELAS','bk-'.str_replace('.','-',$no)).book_pill($no,'Senarai Guru Kelas','bk-'.str_replace('.','-',$no)).book_guru_kelas($kelas,$year); }
        }
    }
    return [$toc,$h];
}
/** Kulit buku. $logo: sumber imej logo sekolah (var:… untuk PDF, URL untuk skrin) atau ''. */
function book_cover(string $logo): string {
    $year=setting('session_year')?:date('Y'); $addr=setting('school_address'); $tel=setting('school_tel');
    return '<div class="bk-cover">'.($logo!==''?'<div class="bk-cover-logo"><img src="'.e($logo).'" alt="" height="110"></div>':'')
        .'<div class="bk-cover-t1">BUKU</div><div class="bk-cover-t2">PENGURUSAN SEKOLAH</div><div class="bk-cover-y">SESI '.e($year).'</div>'
        .'<div class="bk-cover-school">'.e(mb_strtoupper(school_name())).'</div>'.($addr!==''?'<div class="bk-cover-addr">'.nl2br(e($addr)).'</div>':'').($tel!==''?'<div class="bk-cover-addr">Tel: '.e($tel).'</div>':'').'</div>';
}
/** HTML Senarai Tugas / Buku Pengurusan. $pdf: mPDF (kepala & kaki halaman, kandungan bernombor halaman). $full: kulit + kandungan + Perkara Am. */
function task_book_html(array $book,bool $pdf=false,bool $lead=true,bool $full=false,string $logo=''): string {
    [$toc,$body]=book_render($book,$pdf,$lead,$full); if($body==='') return '<p>Tiada jawatan.</p>';
    if($pdf){
        $hf='<htmlpageheader name="bkh"><table class="bk-hdr"><tr><td class="bk-hdr-l">'.e(mb_strtoupper(school_name())).'</td><td class="bk-hdr-r">BUKU<br><b>PENGURUSAN SEKOLAH</b></td></tr></table><div class="bk-hdr-bar"></div></htmlpageheader>'
            .'<htmlpagefooter name="bkf"><div class="bk-ftr-bar"></div><table class="bk-ftr"><tr><td>'.e(school_name()).'</td><td class="bk-ftr-no">{PAGENO}</td></tr></table></htmlpagefooter>';
        $tocAttr=' links="on" toc-odd-header-name="html_bkh" toc-odd-header-value="1" toc-odd-footer-name="html_bkf" toc-odd-footer-value="1" odd-header-name="html_bkh" odd-header-value="1" odd-footer-name="html_bkf" odd-footer-value="1"';
        return $hf.($full?book_cover($logo).'<tocpagebreak toc-preHTML="'.e('<h2 class="toc-title">SENARAI KANDUNGAN</h2>').'"'.$tocAttr.' />'
            :'<sethtmlpageheader name="bkh" value="on" show-this-page="1" /><sethtmlpagefooter name="bkf" value="on" />'.'<tocpagebreak toc-preHTML="'.e('<h2 class="toc-title">SENARAI KANDUNGAN</h2>').'" links="on" toc-odd-header-name="html_bkh" toc-odd-header-value="1" toc-odd-footer-name="html_bkf" toc-odd-footer-value="1" />')
            .preg_replace('/^<pagebreak \/>/','',$body);
    }
    $nav='<nav class="book-toc" aria-label="Kandungan"><h3 class="toc-title">SENARAI KANDUNGAN</h3>';
    foreach($toc as [$lv,$no,$t,$id]) $nav.='<div class="toc-l'.$lv.'"><a href="#'.e($id).'"><span class="toc-no">'.e($no).'</span>'.e($lv===2?$t:mb_strtoupper($t)).'</a></div>';
    return ($full?book_cover($logo):'').$nav.'</nav>'.$body;
}
const TASK_BOOK_CSS='body{color:#1b2240}.toc-title{font-size:14pt;text-align:center;margin:0 0 12px;color:#1f2a5c;letter-spacing:.08em}div.mpdf_toc_level_0{margin-top:9px}a.mpdf_toc_a_level_0,span.mpdf_toc_p_level_0{font-weight:bold;font-size:10pt;color:#1f2a5c}a.mpdf_toc_a_level_1,span.mpdf_toc_p_level_1{font-size:9.5pt;font-weight:bold;color:#333}a.mpdf_toc_a_level_2,span.mpdf_toc_p_level_2{font-size:9pt;font-weight:normal;color:#333}div.mpdf_toc_level_1{padding-left:7mm;margin-top:3px}div.mpdf_toc_level_2{padding-left:14mm}'
    .'.bk-hdr{width:100%;border-collapse:collapse}.bk-hdr td{border:0;padding:0 0 2px}.bk-hdr-l{font-size:7.5pt;color:#1f2a5c;vertical-align:bottom}.bk-hdr-r{text-align:right;font-size:7pt;color:#1f6fb2;line-height:1.05}.bk-hdr-r b{font-size:11pt;color:#1f6fb2}.bk-hdr-bar{height:3px;background:#e7a13a;border-top:3px solid #1f2a5c}'
    .'.bk-ftr-bar{height:2px;background:#1f2a5c;border-top:2px solid #e7a13a}.bk-ftr{width:100%;border-collapse:collapse;font-size:8pt;color:#555}.bk-ftr td{border:0;padding:3px 0 0}.bk-ftr-no{text-align:right;font-size:13pt;font-weight:bold;color:#e7a13a}'
    .'.bk-cover{text-align:center;padding:30mm 0 0}.bk-cover-logo{margin-bottom:12mm}.bk-cover-t1{font-size:20pt;font-weight:bold;color:#1f6fb2;letter-spacing:.3em}.bk-cover-t2{font-size:30pt;font-weight:bold;color:#1f2a5c;line-height:1.1}.bk-cover-y{display:inline-block;margin:8mm auto 30mm;font-size:18pt;font-weight:bold;color:#fff;background:#e7a13a;padding:3mm 10mm}.bk-cover-school{font-size:17pt;font-weight:bold;color:#1f2a5c;border-top:3px solid #1f2a5c;padding-top:5mm;margin:0 20mm}.bk-cover-addr{font-size:10pt;color:#333;margin-top:2mm}'
    .'.bk-div{background:#1f2a5c;color:#fff;text-align:center;padding:85mm 10mm 90mm;border-bottom:10px solid #e7a13a}.bk-div-no{font-size:40pt;font-weight:bold;color:#e7a13a}.bk-div-t{font-size:24pt;font-weight:bold;letter-spacing:.04em;margin-top:6mm}.bk-div-s{font-size:11pt;margin-top:8mm;color:#c9d3f0}'
    .'.bk-pill{border-collapse:collapse;margin:0 0 12px}.bk-pill td{border:0}.bk-pill-no{background:#f7c948;color:#1f2a5c;font-weight:bold;font-size:10pt;padding:5px 10px;text-align:center}.bk-pill-t{background:#1f2a5c;color:#fff;font-weight:bold;font-size:10.5pt;padding:5px 26px 5px 12px;letter-spacing:.04em}'
    .'.bk-intro{font-size:10pt;line-height:1.55;text-align:justify}.bk-intro p{margin:0 0 8px}.bk-intro ul{margin:4px 0 10px 18px;padding-left:10px}.bk-intro li{margin:2px 0}.bk-h{font-size:11pt;color:#1f2a5c;margin:12px 0 5px}.bk-center{text-align:center;font-size:12pt;font-style:italic;font-weight:bold;color:#1f2a5c;margin:8px 15mm 14px;line-height:1.5}.bk-dl{border-collapse:collapse;margin:6px 0}.bk-dl td{border:0;padding:3px 6px;vertical-align:top}.bk-dl-k{font-weight:bold;width:45mm}.bk-dl-s{width:4mm}'
    .'.book-table{width:100%;border-collapse:collapse;margin:0 0 14px;page-break-inside:avoid;font-size:9.5pt}table.book-table th,table.book-table td{border:1px solid #000!important;padding:5px 7px;vertical-align:middle!important;text-align:left}.book-table .book-com{background:#1f2a5c!important;color:#fff;font-weight:bold;text-align:center!important;font-size:10pt;padding:7px}.book-jk .c-role{width:30%}.book-jk .c-name{width:42%}.book-jk .c-note{width:28%}.vacant{color:#999}'
    .'.bk-pan-h th{background:#e86ba7!important;color:#000;text-align:center!important;font-weight:bold}.bk-pan-g{color:#fff;font-weight:bold;text-align:center!important;width:28%}.bk-pan-gkmp{font-weight:bold;text-align:center!important}.bk-kelas .bk-k1 td{background:#fde3a7}.bk-kelas .bk-k2 td{background:#b7cff0}.bk-list .c-bil{width:10mm;text-align:center!important}'
    .'.bk-carta-title{text-align:center;font-weight:bold;font-size:11pt;color:#1f2a5c;background:#f7e600;border:2px solid #000;padding:6px;margin:0 20mm 12px}.bk-org-top{width:100%;border-collapse:separate;border-spacing:0 3px}.bk-org-gap{width:30%;border:0}.bk-org-sp{height:6px;border:0}.bk-org{width:100%;border-collapse:separate;border-spacing:8px 6px;margin-top:6px}td.bk-box{border:1px solid #c0504d;padding:6px 8px;font-size:8.5pt;text-align:center;vertical-align:middle}td.bk-box span{font-size:7.5pt}td.bk-box-top,td.bk-box-pk{background:#fff;border:1.5px solid #1f2a5c!important}td.bk-box-su{background:#fff;border:1px solid #000!important}td.bk-box-jk{background:#f6c6c4;font-weight:bold;width:50%}';

