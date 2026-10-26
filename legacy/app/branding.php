<?php
declare(strict_types=1);
const LETTER_HEADERS=['dual'=>'Jata Negara + logo sekolah','school'=>'Logo sekolah sahaja','national'=>'Jata Negara sahaja'];
final class BrandingError extends RuntimeException {public function __construct(string $message,public int $status=422){parent::__construct($message);}}
function branding_store(string $bytes,int $width,int $height): string {
 global $db;$id=hash('sha256',$bytes);$s=$db->prepare('INSERT OR IGNORE INTO school_assets(id,mime,width,height,bytes) VALUES (?,"image/png",?,?,?)');$s->bindValue(1,$id);$s->bindValue(2,$width,PDO::PARAM_INT);$s->bindValue(3,$height,PDO::PARAM_INT);$s->bindValue(4,$bytes,PDO::PARAM_LOB);$s->execute();return$id;
}
function branding_install():void{
 if(!q('SELECT value FROM settings WHERE key="national_crest_id"')->fetchColumn()){
  $file=dirname(__DIR__).'/public/assets/shared/jata-negara.png';$info=getimagesize($file);$id=branding_store(file_get_contents($file),$info[0],$info[1]);q('INSERT OR IGNORE INTO settings VALUES("national_crest_id",?)',[$id]);
 }
 q('INSERT OR IGNORE INTO settings VALUES("school_logo_id","")');q('INSERT OR IGNORE INTO settings VALUES("letter_header","dual")');
}
function branding_asset(?string $id):?array{
 if(!$id)return null;$a=q('SELECT id,mime,width,height FROM school_assets WHERE id=?',[$id])->fetch();if(!$a)return null;
 return [...$a,'url'=>'/api/v1/assets/'.$a['id']];
}
function branding_profile():array{
 $values=q('SELECT key,value FROM settings WHERE key IN ("school_logo_id","national_crest_id","letter_header")')->fetchAll(PDO::FETCH_KEY_PAIR);
 return ['layout'=>$values['letter_header']??'dual','school_logo'=>branding_asset($values['school_logo_id']??null),'national_crest'=>branding_asset($values['national_crest_id']??null),'shared_crest_url'=>'/assets/shared/jata-negara.png'];
}
function branding_image_url(?array $asset):string{return $asset?'/?page=asset&id='.rawurlencode($asset['id']):'';}
function branding_upload(array $file):string{
 if(!isset($file['error'],$file['size'],$file['tmp_name'])||!is_int($file['error'])||$file['error']!==UPLOAD_ERR_OK||!is_string($file['tmp_name'])||!is_uploaded_file($file['tmp_name']))throw new BrandingError('Pilih fail logo sekolah yang sah.');
 return branding_normalize(file_get_contents($file['tmp_name']));
}
function branding_normalize(string $bytes):string{
 if(strlen($bytes)>2097152||$bytes==='')throw new BrandingError('Logo sekolah maksimum 2 MB.');
 $mime=(new finfo(FILEINFO_MIME_TYPE))->buffer($bytes);
 if(!in_array($mime,['image/png','image/jpeg','image/webp'],true))throw new BrandingError('Logo mesti PNG, JPG atau WebP. SVG dan fail lain tidak diterima.');
 $info=@getimagesizefromstring($bytes);
 if(!$info||$info[0]<1||$info[1]<1||$info[0]>4096||$info[1]>4096||$info[0]*$info[1]>4194304)throw new BrandingError('Dimensi logo maksimum 4096 piksel dan 4 megapiksel.');
 if(!function_exists('imagecreatefromstring'))throw new BrandingError('Pelayan memerlukan sambungan PHP GD untuk memproses logo.',503);
 $source=@imagecreatefromstring($bytes);if(!$source)throw new BrandingError('Imej logo rosak atau tidak dapat dibaca.');
 $scale=min(1,640/max($info[0],$info[1]));$w=max(1,(int)round($info[0]*$scale));$h=max(1,(int)round($info[1]*$scale));
 $target=imagecreatetruecolor($w,$h);imagealphablending($target,false);imagesavealpha($target,true);imagefill($target,0,0,imagecolorallocatealpha($target,0,0,0,127));imagecopyresampled($target,$source,0,0,0,0,$w,$h,$info[0],$info[1]);
 ob_start();imagepng($target,null,7);$png=ob_get_clean();unset($source,$target);
 if(strlen($png)>2097152)throw new BrandingError('Imej selepas diproses melebihi 2 MB.');return branding_store($png,$w,$h);
}
function branding_serve(string $id):never{
 if(!preg_match('/^[a-f0-9]{64}$/D',$id))throw new BrandingError('Imej tidak ditemui.',404);
 $a=q('SELECT mime,bytes FROM school_assets WHERE id=?',[$id])->fetch();if(!$a)throw new BrandingError('Imej tidak ditemui.',404);
 header('Content-Type: image/png');header('X-Content-Type-Options: nosniff');header('Cache-Control: private, max-age=86400, immutable');header('Content-Disposition: inline; filename="'.$id.'.png"');header('Content-Length: '.strlen($a['bytes']));
 if(($_SERVER['REQUEST_METHOD']??'GET')!=='HEAD')echo$a['bytes'];exit;
}
function branding_settings_view():void{$b=branding_profile(); ?>
<section class="panel" id="school-branding"><div class="panel-heading"><div><h3>Logo sekolah & Jata Negara</h3><p class="muted">Identiti sekolah untuk kepala surat SK dan SMK.</p></div></div><div class="branding-grid"><div><div class="branding-preview"><?php if($b['school_logo']): ?><img src="<?= e(branding_image_url($b['school_logo'])) ?>" alt="Logo sekolah semasa"><?php else: ?><span class="muted">Belum ada logo sekolah</span><?php endif ?></div><form method="post" enctype="multipart/form-data" class="branding-form"><?= csrf() ?><input type="hidden" name="action" value="school_logo_upload"><label class="field">Muat naik logo sekolah<input name="school_logo" type="file" accept="image/png,image/jpeg,image/webp" required></label><p class="muted">PNG/JPG/WebP, maksimum 2 MB. PNG lutsinar disarankan. Imej diproses semula dan dikecilkan tanpa dipotong.</p><button class="btn primary">Simpan logo sekolah</button></form><?php if($b['school_logo']): ?><form method="post" class="branding-remove"><?= csrf() ?><input type="hidden" name="action" value="school_logo_remove"><button class="delete-btn">Buang logo daripada draf</button></form><?php endif ?></div><div><div class="branding-preview"><img src="/assets/shared/jata-negara.png" alt="Jata Negara Malaysia"></div><h3>Jata Negara tersedia bersama sistem</h3><p class="muted">Aset bersama untuk semua pengguna dan pemasangan AgihTugas. Tidak perlu muat naik berulang kali.</p><a class="btn outline sm" href="/assets/shared/jata-negara.png" download="jata-negara.png">Muat turun Jata Negara</a><p class="muted">Sumber: <a href="https://www.akm.gov.my/index.php/logo-jata-negara" target="_blank" rel="noopener noreferrer">Akademi Kenegaraan Malaysia</a>.</p></div></div><form method="post" class="branding-layout"><?= csrf() ?><input type="hidden" name="action" value="letter_branding"><label class="field">Gaya kepala surat<select name="letter_header"><?php foreach(LETTER_HEADERS as $key=>$label): ?><option value="<?= $key ?>" <?= $b['layout']===$key?'selected':'' ?>><?= e($label) ?></option><?php endforeach ?></select></label><button class="btn outline">Simpan gaya kepala surat</button><p class="muted">Perubahan untuk draf dan versi muktamad baharu. Logo pada surat muktamad terdahulu kekal menggunakan imej asal.</p></form></section>
<?php }
