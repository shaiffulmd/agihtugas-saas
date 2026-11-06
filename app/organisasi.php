<?php
declare(strict_types=1);
/* Jawatan utama sekolah (Pengetua / Guru Besar, Penolong Kanan, Ketua Bidang) & carta organisasi ikut bidang.
 * Jawatan utama BUKAN jawatan dalam katalog (tiada pemberat, tidak diagih): disimpan sebagai JSON dalam tetapan sekolah 'leadership':
 *   {"posts":{"pengetua":12,"pk_pentadbiran":34,…},"bidang":[["Bahasa",56],["Sains & Matematik",null],…]}
 * Guru yang ditanda ditetapkan "Pentadbir" (index_exempt) supaya tidak dikira dalam indeks beban. Nama Pengetua mengisi nama pada surat.
 * Disunting semasa Draf sahaja (index_exempt & nama Pengetua turut dinilai dalam semakan / surat). */

/** Jawatan utama: kunci => [label penuh (carta), bidang tugas yang dipimpin dalam carta, SMK sahaja?, label pendek (borang & lencana)].
 *  Label 'pengetua' ikut jenis sekolah. */
const LEADER_POSTS=[
    'pengetua'=>['',[],false,''],
    'pk_pentadbiran'=>['Penolong Kanan Pentadbiran',['Pentadbiran','Kurikulum'],false,'PK Pentadbiran'],
    'pk_hem'=>['Penolong Kanan Hal Ehwal Murid',['HEM'],false,'PK HEM'],
    'pk_koku'=>['Penolong Kanan Kokurikulum',['Kokurikulum'],false,'PK Kokurikulum'],
    'pk_t6'=>['Penolong Kanan Tingkatan 6',[],true,'PK Tingkatan 6'],
    'pk_ppki'=>['Penolong Kanan PPKI',[],false,'PK PPKI'],
];
/** Ketua Bidang lalai (4 bidang KPM sekolah menengah). SK bermula kosong. */
const KETUA_BIDANG_DEFAULT=['Bahasa','Sains & Matematik','Kemanusiaan','Teknik & Vokasional'];

/** Jawatan utama bagi jenis sekolah semasa: kunci => label. */
function leader_posts(bool $short=false): array {
    $out=[]; foreach(LEADER_POSTS as $k=>[$label,,$smk,$abbr]) if(!$smk || school_type()==='SMK') $out[$k]=$k==='pengetua'?head_title():($short?$abbr:$label);
    return $out;
}
/** Jawatan utama semasa. ID guru yang tidak lagi wujud (dipadam) dikosongkan. */
function leadership(): array {
    $raw=json_decode(setting('leadership')?:'null',true); $have=array_flip(array_map('intval',query('SELECT id FROM teachers WHERE school_id='.sid())->fetchAll(PDO::FETCH_COLUMN)));
    $tid=fn($v)=>is_int($v) && isset($have[$v])?$v:null;
    $posts=[]; foreach(leader_posts() as $k=>$l) $posts[$k]=$tid($raw['posts'][$k]??null);
    $bidang=[]; if(is_array($raw['bidang']??null)) foreach($raw['bidang'] as $b){ if(is_array($b) && is_string($b[0]??null)) $bidang[]=[$b[0],$tid($b[1]??null)]; }
    elseif(school_type()==='SMK') $bidang=array_map(fn($n)=>[$n,null],KETUA_BIDANG_DEFAULT);
    return ['posts'=>$posts,'bidang'=>$bidang];
}
/** Simpan jawatan utama. $posts: kunci => ID guru|null; $bidang: [[nama, ID guru|null], …] (nama kosong dibuang).
 *  Seorang guru hanya satu jawatan utama. Pulangkan bilangan guru yang baru ditanda Pentadbir. */
function leadership_save(array $posts,array $bidang): int {
    $valid=leader_posts(); $used=[]; $outPosts=[];
    $teacher=function(mixed $v,string $label)use(&$used): ?int {
        if($v===null || $v==='' || $v===0) return null;
        if(!is_int($v)) $v=filter_var($v,FILTER_VALIDATE_INT); $t=$v?teacher_find($v):null;
        if(!$t) throw new EngineError($label.': guru tidak ditemui.',404,'not_found');
        if(isset($used[$v])) throw new EngineError($t['name'].' sudah dipilih sebagai '.$used[$v].'. Seorang guru hanya satu jawatan utama.');
        $used[$v]=$label; return (int)$v;
    };
    foreach($posts as $k=>$v) if(!isset($valid[$k])) throw new EngineError('Jawatan utama tidak dikenali.');
    foreach($valid as $k=>$label) $outPosts[$k]=$teacher($posts[$k]??null,$label);
    $outB=[]; $seen=[];
    foreach($bidang as $b){
        $name=trim(preg_replace('/\s+/u',' ',(string)($b[0]??''))); if($name==='') continue;
        if(mb_strlen($name)>60) throw new EngineError('Nama bidang maksimum 60 aksara.');
        if(isset($seen[mb_strtolower($name)])) throw new EngineError('Bidang "'.$name.'" berulang.'); $seen[mb_strtolower($name)]=true;
        $outB[]=[$name,$teacher($b[1]??null,'Ketua Bidang '.$name)];
    }
    if(count($outB)>12) throw new EngineError('Maksimum 12 bidang.');
    engine_setting_set(['leadership'=>json_encode(['posts'=>$outPosts,'bidang'=>$outB],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE)]);
    if($outPosts['pengetua']) engine_setting_set(['principal'=>(string)teacher_find($outPosts['pengetua'])['name']]); // nama pada surat lantikan
    $n=0; foreach(array_keys($used) as $id) $n+=query('UPDATE teachers SET index_exempt=1 WHERE school_id='.sid().' AND id=? AND index_exempt=0',[$id])->rowCount();
    return $n;
}
/** Jawatan utama setiap guru: ID guru => label (cth. "Penolong Kanan HEM", "Ketua Bidang Bahasa"). */
function leadership_by_teacher(?array $l=null): array {
    $l??=leadership(); $labels=leader_posts(true); $out=[];
    foreach($l['posts'] as $k=>$id) if($id) $out[$id]=$labels[$k];
    foreach($l['bidang'] as [$n,$id]) if($id) $out[$id]='Ketua Bidang '.$n;
    return $out;
}
/** Data carta organisasi: jawatan utama + jawatan katalog (penyandang semasa) ikut bidang tugas.
 *  'groups': setiap PK yang memimpin bidang => [label, guru, bidang => [jawatan…]]; bidang tanpa PK diisi di bawah 'tanpa_pk'. */
function org_chart_data(): array {
    $l=leadership(); $names=array_column(query('SELECT id,name FROM teachers WHERE school_id='.sid())->fetchAll(),'name','id'); $labels=leader_posts();
    $who=fn(?int $id)=>$id?($names[$id]??null):null;
    $byCat=array_fill_keys(CATEGORIES,[]); foreach(positions_with_holder() as $p) $byCat[$p['category']][]=['name'=>$p['name'],'teacher'=>$p['teacher_name']];
    $groups=[]; $placed=[];
    foreach(LEADER_POSTS as $k=>[,$cats]) if($cats && isset($labels[$k])){ $g=['key'=>$k,'label'=>$labels[$k],'teacher'=>$who($l['posts'][$k]),'cats'=>[]]; foreach($cats as $c){ $g['cats'][$c]=$byCat[$c]; $placed[$c]=true; } $groups[]=$g; }
    return ['head'=>['label'=>$labels['pengetua'],'teacher'=>$who($l['posts']['pengetua'])],
        'pk'=>array_values(array_filter(array_map(fn($k)=>['key'=>$k,'label'=>$labels[$k],'teacher'=>$who($l['posts'][$k])],array_keys(array_diff_key($labels,['pengetua'=>1]))),fn($p)=>$p['teacher'] || in_array($p['key'],['pk_pentadbiran','pk_hem','pk_koku'],true))),
        'bidang'=>array_map(fn($b)=>['label'=>'Ketua Bidang '.$b[0],'teacher'=>$who($b[1])],$l['bidang']),
        'groups'=>$groups,'total'=>array_sum(array_map('count',$byCat)),'filled'=>count(array_filter(array_merge(...array_values($byCat)),fn($p)=>$p['teacher']!==null))];
}
/** Tetapkan kapasiti mata ikut had atas Panduan Julat Mata Sasaran: Pengetua & PK → had PK, Ketua Bidang → had GKMP, guru lain → had GAB.
 *  Guru berstatus bertukar keluar tidak diubah. Pulangkan bilangan guru yang kapasitinya berubah. */
function capacity_apply_guide(string $key): int {
    $g=POSITION_TEMPLATE_GUIDE[$key]??null; if(!$g || (POSITION_TEMPLATES[$key][2]??'')!==school_type()) throw new EngineError('Panduan kapasiti tidak sah bagi sekolah ini.');
    [[,,$gab],[,,$gkmp],[,,$pk]]=$g['capacity']; $l=leadership(); $cap=[];
    foreach($l['bidang'] as [,$id]) if($id) $cap[$id]=$gkmp;
    foreach($l['posts'] as $id) if($id) $cap[$id]=$pk;
    $n=0; foreach(query('SELECT id,capacity FROM teachers WHERE school_id='.sid().' AND status<>"bertukar"')->fetchAll() as $t){
        $want=$cap[(int)$t['id']]??$gab; if((int)$t['capacity']!==$want) $n+=query('UPDATE teachers SET capacity=? WHERE school_id='.sid().' AND id=?',[$want,(int)$t['id']])->rowCount();
    }
    return $n;
}
