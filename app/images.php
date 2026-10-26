<?php
declare(strict_types=1);
/* Pemprosesan gambar yang dimuat naik (logo sekolah, gambar profil, QR bayaran). Diambil daripada koku-multi app/unit_logos.php. */

/** Proses semula gambar dengan GD: buang metadata/kandungan tersembunyi, kecilkan, simpan sebagai WebP jika disokong, jika tidak PNG. */
function image_reencode(string $bytes,int $maxSide=512,int $maxBytes=2097152,int $maxPixels=10000000,int $quality=88): array {
    if(!function_exists('imagecreatefromstring') || !function_exists('imagepng')) throw new DomainException('Pelayan memerlukan sambungan PHP GD untuk memproses gambar.');
    if(strlen($bytes)>$maxBytes) throw new DomainException('Saiz gambar maksimum ialah '.round($maxBytes/1048576).' MB.');
    $info=@getimagesizefromstring($bytes);
    $types=[IMAGETYPE_PNG,IMAGETYPE_JPEG]; if(defined('IMAGETYPE_WEBP') && function_exists('imagecreatefromwebp')) $types[]=IMAGETYPE_WEBP;
    if(!$info || !in_array($info[2],$types,true) || $info[0]<1 || $info[1]<1 || $info[0]*$info[1]>$maxPixels) throw new DomainException('Gambar mesti PNG, JPEG'.(in_array(IMAGETYPE_WEBP,$types,true)?' atau WebP':'').' yang sah (maksimum '.round($maxPixels/1000000).' megapiksel).');
    $src=@imagecreatefromstring($bytes); if(!$src) throw new DomainException('Gambar rosak atau tidak dapat diproses.');
    // Betulkan orientasi gambar telefon (EXIF). Metadata asal (termasuk GPS) tidak disalin ke fail baharu.
    if($info[2]===IMAGETYPE_JPEG && function_exists('exif_read_data')){
        $exif=@exif_read_data('data://image/jpeg;base64,'.base64_encode($bytes));
        $rot=[3=>180,6=>-90,8=>90][(int)($exif['Orientation']??1)]??0;
        if($rot){ $r=imagerotate($src,$rot,0); if($r){ $src=$r; } }
    }
    $sw=imagesx($src); $sh=imagesy($src);
    $scale=min(1,$maxSide/max($sw,$sh)); $w=max(1,(int)round($sw*$scale)); $h=max(1,(int)round($sh*$scale));
    $dst=imagecreatetruecolor($w,$h); imagealphablending($dst,false); imagesavealpha($dst,true);
    imagefill($dst,0,0,imagecolorallocatealpha($dst,0,0,0,127));
    imagecopyresampled($dst,$src,0,0,0,0,$w,$h,$sw,$sh);
    $webp=function_exists('imagewebp');
    ob_start(); try { $ok=$webp?imagewebp($dst,null,$quality):imagepng($dst,null,9); $out=(string)ob_get_contents(); } finally { ob_end_clean(); }
    if(!$ok || $out==='') throw new DomainException('Penukaran gambar gagal.');
    return ['bytes'=>$out,'mime'=>$webp?'image/webp':'image/png','width'=>$w,'height'=>$h];
}
