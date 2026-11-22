<?php
/* CLI únicamente. Ejecutar una vez por minuto desde cron Docker. */
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require __DIR__.'/config.php';
require_once __DIR__.'/dc_reuse_51.php';
require_once __DIR__.'/dc_reuse_events_52.php';
$lock=fopen(sys_get_temp_dir().'/dc-local-video-worker.lock','c');if(!$lock||!flock($lock,LOCK_EX|LOCK_NB))exit;
require_once __DIR__.'/dc_local_conversion_worker.php';
try{dcLocalConvertOne($pdo,$STORAGE_DIR);}catch(Throwable $e){error_log('Conversión local: '.$e->getMessage());}
$q=$pdo->query("SELECT * FROM dc_local_video_previews WHERE state='queued' ORDER BY updated_at,video_file_id LIMIT 1");
$job=$q->fetch(PDO::FETCH_ASSOC);if(!$job)exit;
$pdo->prepare("UPDATE dc_local_video_previews SET state='running',updated_at=NOW() WHERE video_file_id=? AND state='queued'")->execute([$job['video_file_id']]);
$tmp=[];
try{
 $fileQ=$pdo->prepare('SELECT f.*, p.deleted_at AS package_deleted FROM files f JOIN files p ON p.id=f.parent_id WHERE f.id=? AND f.owner_id=? AND f.deleted_at IS NULL AND f.is_dir=0');
 $fileQ->execute([$job['video_file_id'],$job['owner_id']]);$file=$fileQ->fetch(PDO::FETCH_ASSOC);
 if(!$file)throw new RuntimeException('Archivo ausente o modificado');
 // Paquetes eliminados: detener el trabajo sin generar un falso error.
 if($file['package_deleted']!==null){
  $pdo->prepare("UPDATE dc_local_video_previews SET state='paused',message='Paquete en Papelera',updated_at=NOW() WHERE video_file_id=? AND state='running'")->execute([$job['video_file_id']]);
  exit(0);
 }
 if($file['storage_name']!==$job['source_storage']||!str_starts_with((string)$file['mime_type'],'video/'))throw new RuntimeException('Archivo ausente o modificado');

 $storage=$file['storage_name'];if(!preg_match('/^[a-zA-Z0-9_.-]+$/D',$storage))throw new RuntimeException('Nombre físico inválido');
 $source=rtrim($STORAGE_DIR,'/').'/'.$storage;
 if(!is_file($source)||is_link($source))throw new RuntimeException('Archivo físico no disponible');
 if(!is_executable('/usr/bin/ffmpeg')||!is_executable('/usr/bin/ffprobe'))throw new RuntimeException('Falta FFmpeg en el contenedor');
 $q=$pdo->prepare("SELECT setting_key,setting_value FROM dc_app_settings WHERE setting_key IN ('local_preview_seconds','local_preview_audio','local_preview_format')");$q->execute();$conf=$q->fetchAll(PDO::FETCH_KEY_PAIR);
 $seconds=max(3,min(30,(int)($conf['local_preview_seconds']??10)));$audio=(string)($conf['local_preview_audio']??'0')==='1';$format=(string)($conf['local_preview_format']??'webm');if(!in_array($format,['webm','mp4'],true))$format='webm';
 $root=rtrim($THUMBS_DIR,'/').'/';if(!is_dir($root)||!is_writable($root))throw new RuntimeException('Directorio de miniaturas sin escritura');
 $id=(int)$file['id'];$poster=$root.'dc-local-'.$id.'.webp';$clip=$root.'dc-local-'.$id.'.'.($format==='webm'?'webm':'mp4');
 $dc51VideoHash=dc51Digest($source);$dc51Profile='preview-v1-'.$seconds.'-'.($audio?'audio':'silent');
 if($dc51VideoHash && dc51PreviewReuse($pdo,$root,$dc51VideoHash,$dc51Profile,$poster,$clip)){
  $chk=$pdo->prepare('SELECT f.storage_name,f.deleted_at,p.deleted_at AS package_deleted FROM files f JOIN files p ON p.id=f.parent_id WHERE f.id=? AND f.owner_id=?');
  $chk->execute([$id,$job['owner_id']]);$verified=$chk->fetch(PDO::FETCH_ASSOC);
  if($verified && $verified['deleted_at']===null && $verified['package_deleted']===null && $verified['storage_name']===$storage){
   $pdo->prepare("UPDATE dc_local_video_previews SET state='done',message=NULL,updated_at=NOW() WHERE video_file_id=? AND source_storage=?")->execute([$id,$storage]);
   dc52Event($pdo,'preview',true,$id,(int)$job['owner_id'],$dc51Profile,$dc51VideoHash,(int)(filesize($poster)+filesize($clip)));
   exit(0);
  }
  @unlink($poster);@unlink($clip);throw new RuntimeException('Archivo cambió durante la reutilización');
 }

 $tmpPoster=$root.'dc-local-'.$id.'.'.bin2hex(random_bytes(5)).'.tmp.webp';$tmpClip=$root.'dc-local-'.$id.'.'.bin2hex(random_bytes(5)).'.tmp.'.($format==='webm'?'webm':'mp4');$tmp=[$tmpPoster,$tmpClip];
 function dlRun(array $args,int $timeout):void{
  $cmd='timeout '.(int)$timeout.'s '.implode(' ',array_map('escapeshellarg',$args)).' >/dev/null 2>&1';
  exec($cmd,$ignored,$status);if($status!==0)throw new RuntimeException('FFmpeg terminó con código '.$status);
 }
 dlRun(['/usr/bin/ffmpeg','-nostdin','-hide_banner','-loglevel','error','-threads','1','-y','-ss','1','-i',$source,'-frames:v','1','-vf','scale=640:-2','-c:v','webp','-quality','80',$tmpPoster],100);
 if(!is_file($tmpPoster)||filesize($tmpPoster)<100)throw new RuntimeException('Miniatura vacía');
 $args=['/usr/bin/ffmpeg','-nostdin','-hide_banner','-loglevel','error','-threads','1','-y','-ss','1','-i',$source,'-t',(string)$seconds,'-map','0:v:0'];
 if($audio)array_push($args,'-map','0:a:0?');
 if($format==='webm'){array_push($args,'-vf','scale=480:-2,fps=12','-c:v','libvpx-vp9','-crf','35','-b:v','0');}else{array_push($args,'-vf','scale=480:-2,fps=12','-c:v','libx264','-preset','ultrafast','-crf','32');}
 if($audio){if($format==='webm')array_push($args,'-c:a','libopus','-b:a','48k');else array_push($args,'-c:a','aac','-b:a','64k');}else array_push($args,'-an');
 if($format==='mp4')array_push($args,'-movflags','+faststart');array_push($args,$tmpClip);
 dlRun($args,180);
 if(!is_file($tmpClip)||filesize($tmpClip)<100)throw new RuntimeException('Vista previa vacía');
 // Verificar otra vez el origen antes de publicar derivados.
 $q=$pdo->prepare('SELECT f.storage_name,f.deleted_at,p.deleted_at AS package_deleted FROM files f JOIN files p ON p.id=f.parent_id WHERE f.id=? AND f.owner_id=?');$q->execute([$id,$job['owner_id']]);$verify=$q->fetch(PDO::FETCH_ASSOC);
 if(!$verify||$verify['deleted_at']!==null||$verify['package_deleted']!==null||$verify['storage_name']!==$storage)throw new RuntimeException('Archivo cambió durante la generación');
 if(!rename($tmpPoster,$poster)||!rename($tmpClip,$clip))throw new RuntimeException('No se pudieron publicar los derivados');
 if($dc51VideoHash){
  try{dc51Store($pdo,$dc51VideoHash,$dc51Profile,$storage,$storage,(string)dc51Digest($source),(int)filesize($source),basename($poster),basename($clip),dc51Digest($poster),dc51Digest($clip));}catch(Throwable $ignored){error_log('DriveCraft: caché de vistas previas no disponible');}
 }
 $pdo->prepare("UPDATE dc_local_video_previews SET state='done',message=NULL,updated_at=NOW() WHERE video_file_id=? AND source_storage=?")->execute([$id,$storage]);
 dc52Event($pdo,'preview',false,$id,(int)$job['owner_id'],$dc51Profile,$dc51VideoHash,(int)(filesize($poster)+filesize($clip)));
}catch(Throwable $e){$pdo->prepare("UPDATE dc_local_video_previews SET state='error',message=?,updated_at=NOW() WHERE video_file_id=?")->execute([substr($e->getMessage(),0,255),$job['video_file_id']]);error_log('DriveCraft preview '.$job['video_file_id'].': '.$e->getMessage());}
finally{foreach($tmp as $f)if(is_file($f))@unlink($f);}

