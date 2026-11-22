<?php
declare(strict_types=1);

/** Read only the cached preview's stat/header, never the full-resolution source. */
function dcThumbnailMetadataDcft(array $file, string $directory): array {
    $storage=(string)($file['storage_name']??'');
    if(!empty($file['is_dir'])||$storage===''||basename($storage)!==$storage)return ['thumb_cached'=>false,'thumb_version'=>'0'];
    $path=rtrim($directory,'/').'/'.$storage.'.webp';
    $stat=@stat($path);
    $cached=$stat!==false&&$stat['size']>0;
    $fingerprint=implode('|',[$storage,(string)($file['content_hash']??''),$cached?$stat['mtime']:0,$cached?$stat['size']:0,$cached?$stat['ino']:0]);
    $result=['thumb_cached'=>$cached,'thumb_version'=>substr(hash('sha256',$fingerprint),0,24)];
    if($cached){$size=@getimagesize($path);if($size&&$size[0]>0&&$size[1]>0){$result['thumb_width']=$size[0];$result['thumb_height']=$size[1];}}
    return $result;
}
