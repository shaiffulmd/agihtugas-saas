<?php
declare(strict_types=1);
/* Avatar / gambar profil pengguna. Dipotong segi empat (tengah), 256 px, WebP (PNG jika tiada sokongan).
   Hanya dihidangkan kepada pengguna log masuk. Metadata/GPS dibuang melalui pengekodan semula. */

const AVATAR_MAX_BYTES=6*1024*1024;
const AVATAR_SIZE=256;

function avatar_square(string $bytes): array {
    $norm=image_reencode($bytes,1024,AVATAR_MAX_BYTES,26000000,92); // sahkan jenis, betulkan orientasi EXIF
    $src=@imagecreatefromstring($norm['bytes']); if(!$src) throw new DomainException('Gambar rosak atau tidak dapat diproses.');
    $w=imagesx($src); $h=imagesy($src); $side=min($w,$h);
    $dst=imagecreatetruecolor(AVATAR_SIZE,AVATAR_SIZE); imagealphablending($dst,false); imagesavealpha($dst,true);
    imagefill($dst,0,0,imagecolorallocatealpha($dst,0,0,0,127));
    imagecopyresampled($dst,$src,0,0,(int)(($w-$side)/2),(int)(($h-$side)/2),AVATAR_SIZE,AVATAR_SIZE,$side,$side);
    $webp=function_exists('imagewebp');
    ob_start(); try { $ok=$webp?imagewebp($dst,null,86):imagepng($dst,null,9); $out=(string)ob_get_contents(); } finally { ob_end_clean(); }
    if(!$ok || $out==='') throw new DomainException('Penukaran gambar gagal.');
    return ['bytes'=>$out,'mime'=>$webp?'image/webp':'image/png'];
}
function avatar_version(int $uid): ?int {
    static $cache=[]; if(!array_key_exists($uid,$cache)) $cache[$uid]=($v=query('SELECT UNIX_TIMESTAMP(updated_at) FROM user_avatars WHERE user_id=? AND school_id='.sid(),[$uid])->fetchColumn())===false?null:(int)$v;
    return $cache[$uid];
}
/** Avatar sebagai <img>, atau huruf awal nama jika tiada gambar. */
function avatar_html(int $uid,string $name,string $class='avatar'): string {
    $v=avatar_version($uid);
    if($v) return '<img class="'.e($class).' has-img" src="/?page=avatar&amp;id='.$uid.'&amp;v='.$v.'" alt="" width="40" height="40">';
    return '<span class="'.e($class).'" aria-hidden="true">'.e(mb_strtoupper(mb_substr(trim($name),0,1))).'</span>';
}
function serve_avatar(int $uid): never {
    $r=query('SELECT mime,data FROM user_avatars WHERE user_id=? AND school_id='.sid(),[$uid])->fetch();
    if(!$r){ http_response_code(404); exit; }
    header_remove('Pragma'); header('Content-Type: '.($r['mime']==='image/png'?'image/png':'image/webp')); header('Content-Length: '.strlen($r['data']));
    header('Cache-Control: private, max-age=604800'); header('X-Content-Type-Options: nosniff'); echo $r['data']; exit;
}
function handle_avatar(string $action): never {
    $me=current_user();
    if($action==='avatar_save'){
        $f=$_FILES['avatar']??null;
        if(!$f || !is_array($f) || is_array($f['error']) || $f['error']!==UPLOAD_ERR_OK || !is_uploaded_file((string)$f['tmp_name'])) throw new DomainException('Pilih gambar (JPEG, PNG atau WebP, maksimum 6 MB).');
        if((int)$f['size']>AVATAR_MAX_BYTES) throw new DomainException('Saiz gambar maksimum ialah 6 MB.');
        $img=avatar_square((string)file_get_contents($f['tmp_name']));
        query('INSERT INTO user_avatars(school_id,user_id,mime,data) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE mime=VALUES(mime),data=VALUES(data),updated_at=CURRENT_TIMESTAMP',[sid(),$me['id'],$img['mime'],$img['bytes']]);
        audit('avatar_saved','users',(int)$me['id']); flash('Gambar profil dikemas kini.'); redirect('/?page=security');
    }
    if($action==='avatar_remove'){
        query('DELETE FROM user_avatars WHERE user_id=? AND school_id='.sid(),[$me['id']]); audit('avatar_removed','users',(int)$me['id']); flash('Gambar profil dibuang.'); redirect('/?page=security');
    }
    if($action==='avatar_admin_remove'){
        require_admin(); $uid=(int)input('user_id');
        if(!query('DELETE FROM user_avatars WHERE user_id=? AND school_id='.sid(),[$uid])->rowCount()) throw new DomainException('Pengguna ini tiada gambar profil.');
        audit('avatar_admin_removed','users',$uid); flash('Gambar profil pengguna dibuang.'); redirect('/?page=users&user='.$uid.'#urus');
    }
    throw new DomainException('Tindakan tidak sah.');
}
/** Panel profil pada halaman Keselamatan Akaun. */
function avatar_panel(array $user): void {
    $has=avatar_version((int)$user['id'])!==null; ?>
<section class="panel padded profile-panel"><div class="profile-row"><?= avatar_html((int)$user['id'],(string)$user['name'],'avatar avatar-xl') ?><div><h3>Gambar profil</h3><p class="muted">Dipaparkan di bar atas dan senarai pengguna (hanya kepada staf yang log masuk). JPEG, PNG atau WebP, maks. 6 MB. Gambar dipotong segi empat di bahagian tengah.</p>
<form method="post" enctype="multipart/form-data" class="profile-form"><?= csrf_field() ?><input type="hidden" name="action" value="avatar_save"><input type="hidden" name="MAX_FILE_SIZE" value="<?= AVATAR_MAX_BYTES ?>"><input type="file" name="avatar" accept="image/jpeg,image/png,image/webp" required aria-label="Pilih gambar profil"><button class="btn primary"><?= icon('upload') ?><?= $has?'Tukar gambar':'Muat naik' ?></button></form>
<?php if($has): ?><form method="post" class="delete-form" data-confirm="Buang gambar profil anda?"><?= csrf_field() ?><input type="hidden" name="action" value="avatar_remove"><button class="delete-btn">Buang gambar</button></form><?php endif; ?></div></div></section><?php
}
