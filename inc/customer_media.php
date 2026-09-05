<?php
declare(strict_types=1);

function customer_media_root(): string{return dirname(__DIR__).'/customer/uploads/products';}
function customer_media_public_prefix(): string{return 'uploads/products/';}
function customer_media_ensure_dir(): void
{
    $dir=customer_media_root();if(!is_dir($dir)&&!mkdir($dir,0775,true)&&!is_dir($dir))throw new RuntimeException('Не удалось создать папку для фотографий PWA.');if(!is_writable($dir))throw new RuntimeException('Папка customer/uploads/products недоступна для записи.');
}
function customer_media_source(string $tmp,string $mime)
{
    return match($mime){'image/jpeg'=>function_exists('imagecreatefromjpeg')?@imagecreatefromjpeg($tmp):false,'image/png'=>function_exists('imagecreatefrompng')?@imagecreatefrompng($tmp):false,'image/webp'=>function_exists('imagecreatefromwebp')?@imagecreatefromwebp($tmp):false,default=>false};
}
function customer_media_orient_jpeg($img,string $tmp)
{
    if(!function_exists('exif_read_data'))return $img;$exif=@exif_read_data($tmp);$orientation=(int)($exif['Orientation']??1);if($orientation===3)return imagerotate($img,180,0)?:$img;if($orientation===6)return imagerotate($img,-90,0)?:$img;if($orientation===8)return imagerotate($img,90,0)?:$img;return $img;
}
function customer_media_delete(?string $path): void
{
    $path=trim((string)$path);$prefix=customer_media_public_prefix();
    if($path===''||!preg_match('#^'.preg_quote($prefix,'#').'[A-Za-z0-9._-]+$#',$path))return;
    $name=basename(substr($path,strlen($prefix)));if($name===''||$name==='.'||$name==='..')return;
    $file=customer_media_root().'/'.$name;
    if(is_file($file))@unlink($file);
}
function customer_media_save_upload(array $file,string $prefix,?string $oldPath=null): string
{
    if(($file['error']??UPLOAD_ERR_NO_FILE)===UPLOAD_ERR_NO_FILE)throw new RuntimeException('Выберите фотографию.');if(($file['error']??UPLOAD_ERR_OK)!==UPLOAD_ERR_OK)throw new RuntimeException('Не удалось загрузить фотографию. Код: '.(int)$file['error']);if((int)($file['size']??0)<=0||(int)$file['size']>12*1024*1024)throw new RuntimeException('Фото должно быть не больше 12 МБ.');
    $tmp=(string)($file['tmp_name']??'');if($tmp===''||!is_uploaded_file($tmp))throw new RuntimeException('Некорректный файл загрузки.');$info=@getimagesize($tmp);if(!$info)throw new RuntimeException('Файл не является изображением.');$iw=(int)($info[0]??0);$ih=(int)($info[1]??0);if($iw<1||$ih<1||$iw*$ih>24000000)throw new RuntimeException('Слишком большое разрешение фото. Максимум около 24 мегапикселей.');
    $mime=(string)($info['mime']??'');if(!in_array($mime,['image/jpeg','image/png','image/webp'],true))throw new RuntimeException('Поддерживаются JPG, PNG и WebP.');if(!function_exists('imagecreatetruecolor'))throw new RuntimeException('На сервере не включена библиотека GD для обработки изображений.');$src=customer_media_source($tmp,$mime);if(!$src)throw new RuntimeException('Не удалось прочитать изображение.');if($mime==='image/jpeg')$src=customer_media_orient_jpeg($src,$tmp);
    $sw=imagesx($src);$sh=imagesy($src);if($sw<1||$sh<1){imagedestroy($src);throw new RuntimeException('Некорректный размер изображения.');}$size=1000;$ratio=max($size/$sw,$size/$sh);$rw=(int)ceil($sw*$ratio);$rh=(int)ceil($sh*$ratio);$scaled=imagecreatetruecolor($rw,$rh);$canvas=imagecreatetruecolor($size,$size);if(!$scaled||!$canvas){imagedestroy($src);throw new RuntimeException('Недостаточно памяти для обработки изображения.');}$bg=imagecolorallocate($canvas,20,18,16);imagefill($canvas,0,0,$bg);imagecopyresampled($scaled,$src,0,0,0,0,$rw,$rh,$sw,$sh);$sx=max(0,(int)floor(($rw-$size)/2));$sy=max(0,(int)floor(($rh-$size)/2));imagecopy($canvas,$scaled,0,0,$sx,$sy,$size,$size);
    customer_media_ensure_dir();$safe=preg_replace('/[^a-z0-9_-]+/i','-',trim($prefix))?:'image';$token=substr(bin2hex(random_bytes(8)),0,12);$ext=function_exists('imagewebp')?'webp':'jpg';$name=$safe.'-'.$token.'.'.$ext;$target=customer_media_root().'/'.$name;$ok=$ext==='webp'?imagewebp($canvas,$target,86):imagejpeg($canvas,$target,88);imagedestroy($canvas);imagedestroy($scaled);imagedestroy($src);if(!$ok||!is_file($target))throw new RuntimeException('Не удалось сохранить обработанное фото.');customer_media_delete($oldPath);return customer_media_public_prefix().$name;
}
