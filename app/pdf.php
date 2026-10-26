<?php
declare(strict_types=1);
/* Enjin PDF (mPDF) — tiada HTML/URL/laluan fail daripada pengguna dihantar kepada mPDF; semua medan di-escape. */
function pdf_table(array $columns,array $rows): string {
    $html='<table class="data"><thead><tr>'; foreach($columns as $label) $html.='<th>'.e($label).'</th>'; $html.='</tr></thead><tbody>';
    foreach($rows as $row) { $html.='<tr>'; foreach($columns as $key=>$label) $html.='<td>'.e($row[$key]??'').'</td>'; $html.='</tr>'; }
    if(!$rows) $html.='<tr><td colspan="'.count($columns).'">Tiada rekod.</td></tr>';
    return $html.'</tbody></table>';
}
/** Kandungan PDF setiap halaman: [tajuk, html, imej, css tambahan, tanpa kepala?, format A4|A4-L, nama fail].
 * Modul enjin (cth. surat lantikan) menambah cabang di sini. */
function pdf_content(string $page): array {
    error_page(404,'Dokumen PDF tidak disokong.');
}
function download_document_pdf(string $page): never {
    require_once dirname(__DIR__).'/vendor/autoload.php';
    // No uploaded HTML, URL, or filesystem path is passed to mPDF. All fields are escaped.
    [$title,$html,$images,$extraCss,$bare,$format,$fname]=pdf_content($page)+[3=>'',4=>false,5=>'A4',6=>''];
    $base=dirname(__DIR__).'/storage/pdf'; if(!is_dir($base) && !mkdir($base,0700,true) && !is_dir($base)) throw new RuntimeException('Folder sementara PDF tidak dapat disediakan.');
    $tmp=$base.'/'.bin2hex(random_bytes(16)); if(!mkdir($tmp,0700)) throw new RuntimeException('Folder sementara PDF tidak dapat disediakan.');
    try {
        $pdf=new \Mpdf\Mpdf(['tempDir'=>$tmp,'format'=>$format==='A4-L'?'A4-L':'A4','margin_left'=>15,'margin_right'=>15,'margin_top'=>16,'margin_bottom'=>18,'default_font'=>'dejavusans','default_font_size'=>10]);
        $pdf->SetTitle($title); $pdf->SetAuthor(school_name()); $pdf->imageVars=$images; $pdf->showImageErrors=true;
        $pdf->SetHTMLFooter('<table width="100%" style="font-size:8pt;color:#666;border-top:1px solid #ddd"><tr><td>'.e(school_name()).' · '.e(current_user()['name']).' · '.date('d/m/Y').'</td><td align="right">Halaman {PAGENO} / {nbpg}</td></tr></table>');
        $css='body{font-family:dejavusans;color:#203e33;line-height:1.55}h1{font-size:18pt;text-align:center;margin:0 0 14px}h2{font-size:13pt}h3{font-size:11pt}p{margin:9px 0}.school{text-align:center;font-size:10pt;color:#617e6b;margin-bottom:10px}.center{text-align:center}.section{border-left:3px solid #588767;padding-left:8px;margin-top:20px}table.data{width:100%;border-collapse:collapse;margin-top:12px}table.data th{background:#edf3e9;text-align:left;font-weight:bold}table.data td,table.data th{border-bottom:1px solid #dfe7dc;padding:8px 7px;font-size:9pt}table.org{margin:8px auto 18px;border-spacing:8px;page-break-inside:avoid}table.org td{text-align:center;vertical-align:middle}.card{border:1px solid #bfceba;border-top:3px solid #638c67;padding:13px 8px;background:#f8faf5}.role{font-size:9pt;color:#557e5e;margin:0}.card h3{margin:8px 0;font-size:10pt}.detail{font-size:8pt;margin:0}.advisor{padding:14px;border:1px solid #bacfb7;margin:18px 0}.advisor h2{margin:8px 0}.signature{width:100%;margin-top:25px;page-break-inside:avoid}.signature td{width:50%;vertical-align:top;font-size:9pt}.photo{text-align:center;page-break-inside:avoid;margin:10px 0 13px}.photo p{font-size:8pt;margin:4px 0}.rich ul,.rich ol{margin:6px 0 6px 16px;padding-left:12px}.rich li{margin:2px 0}.rich blockquote{border-left:3px solid #bfceba;margin:8px 0;padding:2px 10px;color:#444;font-style:italic}.rich h3{font-size:10.5pt;margin:12px 0 4px}.rich h4{font-size:10pt;margin:10px 0 4px}.rich a{color:#203e33}';
        // Warna dokumen mengikut tema sekolah (mPDF tidak menyokong pemboleh ubah CSS).
        ['primary'=>$tp,'dark'=>$td]=theme_colors();
        $css=strtr($css,['#203e33'=>mix_hex($td,'#000000',0.8),'#617e6b'=>mix_hex($tp,'#808080',0.45),'#588767'=>$tp,'#638c67'=>$tp,'#557e5e'=>mix_hex($tp,'#000000',0.85),'#edf3e9'=>mix_hex($tp,'#ffffff',0.10),'#dfe7dc'=>mix_hex($tp,'#ffffff',0.16),'#bfceba'=>mix_hex($tp,'#ffffff',0.32),'#bacfb7'=>mix_hex($tp,'#ffffff',0.32),'#f8faf5'=>mix_hex($tp,'#ffffff',0.04)]);
        $pdf->WriteHTML($css.$extraCss,\Mpdf\HTMLParserMode::HEADER_CSS);
        $pdf->WriteHTML(($bare?'':'<div class="school">'.e(school_name()).'</div><h1>'.e($title).'</h1>').$html,\Mpdf\HTMLParserMode::HTML_BODY);
        $bytes=$pdf->Output('',\Mpdf\Output\Destination::STRING_RETURN);
    } finally {
        $files=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($tmp,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
        foreach($files as $file) { if($file->isDir() && !$file->isLink()) rmdir($file->getPathname()); else unlink($file->getPathname()); } rmdir($tmp);
    }
    audit('download_pdf',$page,isset($_GET['id'])?(int)$_GET['id']:null);
    header('Content-Type: application/pdf'); header('Content-Disposition: attachment; filename="'.($fname!==''?$fname:str_replace('_','-',$page).'-'.date('Y-m-d').'.pdf').'"'); header('Content-Length: '.strlen($bytes)); echo $bytes; exit;
}
