<?php
/** Multimedia: configuración y cola, sin ejecución desde la petición web. */
function dcMediaSettings(PDO $pdo): array {
 $keys=['dc_media_conversion_enabled','dc_media_auto_conversion','dc_media_thumbnails_enabled','dc_media_max_mib'];
 $q=$pdo->prepare('SELECT setting_key,setting_value FROM dc_app_settings WHERE setting_key IN (?,?,?,?)');$q->execute($keys);$s=$q->fetchAll(PDO::FETCH_KEY_PAIR);
 return ['conversion_enabled'=>($s['dc_media_conversion_enabled']??'0')==='1','auto_conversion'=>($s['dc_media_auto_conversion']??'0')==='1','thumbnails_enabled'=>($s['dc_media_thumbnails_enabled']??'0')==='1','max_mib'=>max(1,min(10240,(int)($s['dc_media_max_mib']??500)))];
}
function dcMediaIsVideo(array $f): bool {
 return (int)$f['is_dir']===0 && (bool)preg_match('/\.(mp4|mov|mkv|avi|webm|m4v)$/iD',(string)$f['name']);
}
function dcMediaQueue(PDO $pdo,array $f,string $kind,string $mode): int {
 $q=$pdo->prepare("SELECT id FROM dc_media_jobs WHERE file_id=? AND kind=? AND status IN ('queued','running') LIMIT 1");
 $q->execute([(int)$f['id'],$kind]);$found=$q->fetchColumn();if($found)return (int)$found;
 $q=$pdo->prepare("INSERT INTO dc_media_jobs(file_id,owner_id,kind,mode,status,source_storage,created_at) VALUES(?,?,?,?, 'queued', ?,NOW())");
 $q->execute([(int)$f['id'],(int)$f['owner_id'],$kind,$mode,(string)$f['storage_name']]);return (int)$pdo->lastInsertId();
}

/** v84d: leer solo encabezados de cajas MP4, saltando el payload sin cargarlo en RAM. */
function dcMp4FaststartStatus(string $path): ?bool {
 $h=@fopen($path,'rb');if(!$h)return null;
 try {
  $size=@filesize($path);if(!is_int($size)||$size<16)return null;
  $offset=0;$moov=null;$mdat=null;$count=0;
  while($offset+8<=$size && ++$count<=100000){
   if(fseek($h,$offset,SEEK_SET)!==0)return null;
   $head=fread($h,8);if(strlen($head)!==8)return null;
   $a=unpack('Nsize/a4type',$head);$length=(float)$a['size'];$type=$a['type'];$header=8;
   if($length===1.0){$extended=fread($h,8);if(strlen($extended)!==8)return null;$w=unpack('Nhi/Nlo',$extended);$length=(float)$w['hi']*4294967296.0+(float)$w['lo'];$header=16;}
   elseif($length===0.0)$length=$size-$offset;
   if($length<$header || $length>$size-$offset)return null;
   if($type==='moov' && $moov===null)$moov=$offset;
   if($type==='mdat' && $mdat===null)$mdat=$offset;
   if($moov!==null && $mdat!==null)return $moov<$mdat;
   $offset+=(int)$length;
  }
  return null;
 } finally {fclose($h);}
}
/** v84d: revisión ligera, sin transcodificar; orientación conservadora para reproducción HTML5. */
function dcMediaAnalyze(string $path,string $name): array {
 $result=['known'=>false,'compatible'=>false,'faststart'=>null,'can_convert'=>false,'can_faststart'=>false,'video_codec'=>'','audio_codec'=>'','message'=>'No se pudo analizar el archivo'];
 if(!is_file($path)||is_link($path)||!is_executable('/usr/bin/ffprobe'))return $result;
 $cmd='timeout 20s /usr/bin/ffprobe -v error -show_entries '.escapeshellarg('stream=codec_type,codec_name,pix_fmt,profile'). ' -of json '.escapeshellarg($path).' 2>/dev/null';
 $lines=[];$status=0;exec($cmd,$lines,$status);if($status!==0)return $result;
 $data=json_decode(implode("\n",$lines),true);if(!is_array($data))return $result;
 $video=null;$audios=[];foreach($data['streams']??[] as $s){if(($s['codec_type']??'')==='video' && $video===null)$video=$s;elseif(($s['codec_type']??'')==='audio')$audios[]=$s;}
 if(!$video)return $result;
 $vc=strtolower((string)($video['codec_name']??''));$ac=$audios?strtolower((string)($audios[0]['codec_name']??'')):'';
 $mp4=(bool)preg_match('/\.mp4$/iD',$name);
 $codecOk=$vc==='h264' && in_array(strtolower((string)($video['pix_fmt']??'')),['yuv420p','yuvj420p'],true);
 $audioOk=true;foreach($audios as $a){if(strtolower((string)($a['codec_name']??''))!=='aac'){$audioOk=false;break;}}
 $compatible=$mp4&&$codecOk&&$audioOk;
 $fast=$mp4?dcMp4FaststartStatus($path):null;
 $result['known']=true;$result['compatible']=$compatible;$result['faststart']=$fast;
 $result['can_convert']=!$compatible;$result['can_faststart']=$compatible && $fast===false;
 $result['video_codec']=$vc;$result['audio_codec']=$ac;
 $result['message']=!$compatible?'Es recomendable crear una versión compatible':($fast===true?'El MP4 ya es compatible y tiene Faststart':($fast===false?'El MP4 es compatible; falta Faststart':'MP4 compatible; no se pudo determinar Faststart'));
 return $result;
}
