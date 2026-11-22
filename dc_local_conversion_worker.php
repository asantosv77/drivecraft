<?php
require_once __DIR__.'/dc_reuse_51.php';
require_once __DIR__.'/dc_reuse_53.php';
require_once __DIR__.'/dc_reuse_events_52.php';
/* Drive-Craft 20.10.30: convierte solo paquetes de video locales; ejecución CLI desde worker de previews. */
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
function dcLocalConvertOne(PDO $pdo,string $storageDir):void{
 $q=$pdo->query("SELECT * FROM dc_local_video_conversions WHERE state='queued' ORDER BY updated_at,video_file_id LIMIT 1");
 $job=$q->fetch(PDO::FETCH_ASSOC);if(!$job)return;
 $id=(int)$job['video_file_id'];$uid=(int)$job['owner_id'];$oldName=(string)$job['source_storage'];
 $q=$pdo->prepare("UPDATE dc_local_video_conversions SET state='running',message=NULL,updated_at=NOW() WHERE video_file_id=? AND owner_id=? AND state='queued'");$q->execute([$id,$uid]);if(!$q->rowCount())return;
 $base=rtrim($storageDir,'/').'/';$output=null;
 try{
  $q=$pdo->prepare("SELECT f.*,p.deleted_at AS package_deleted,g.deleted_at AS folder_deleted,y.folder_id AS library_id,m.storage_name AS meta_storage,m.size AS meta_size FROM files f JOIN files p ON p.id=f.parent_id AND p.is_dir=1 AND p.owner_id=f.owner_id JOIN files g ON g.id=p.parent_id AND g.is_dir=1 AND g.owner_id=f.owner_id LEFT JOIN dc_youtube_folders y ON y.folder_id=g.id AND y.owner_id=f.owner_id JOIN files m ON m.parent_id=p.id AND m.owner_id=f.owner_id AND m.name='metadata.json' AND m.deleted_at IS NULL WHERE f.id=? AND f.owner_id=? AND f.is_dir=0 AND f.deleted_at IS NULL");
  $q->execute([$id,$uid]);$f=$q->fetch(PDO::FETCH_ASSOC);
  if(!$f){$pdo->prepare("UPDATE dc_local_video_conversions SET state='cancelled',message='Video eliminado o movido',updated_at=NOW() WHERE video_file_id=? AND owner_id=?")->execute([$id,$uid]);return;}
  if($f['package_deleted']!==null||$f['folder_deleted']!==null){$pdo->prepare("UPDATE dc_local_video_conversions SET state='paused',message='Paquete en Papelera',updated_at=NOW() WHERE video_file_id=? AND owner_id=?")->execute([$id,$uid]);return;}
  if(!$f['library_id']||$f['storage_name']!==$oldName||!str_starts_with((string)$f['mime_type'],'video/'))throw new RuntimeException('Video original modificado');
  $meta=(string)$f['meta_storage'];if(!preg_match('/^[a-zA-Z0-9_.-]+$/D',$meta)||(int)$f['meta_size']>16384)throw new RuntimeException('Paquete inválido');
  $metaPath=$base.$meta;if(!is_file($metaPath)||is_link($metaPath))throw new RuntimeException('Metadatos ausentes');
  $j=json_decode((string)file_get_contents($metaPath,false,null,0,16385),true);if(($j['drivecraft_type']??'')!=='local-video-package-v1')throw new RuntimeException('Tipo de paquete incorrecto');
  if(!preg_match('/^[a-zA-Z0-9_.-]+$/D',$oldName))throw new RuntimeException('Nombre físico inválido');
  $source=$base.$oldName;if(!is_file($source)||is_link($source))throw new RuntimeException('Archivo original no disponible');
  if(!is_executable('/usr/bin/ffmpeg')||!is_executable('/usr/bin/ffprobe'))throw new RuntimeException('FFmpeg no disponible');
  // Capturar los datos ANTES de sustituir el archivo físico.
  $probeSummary=static function(string $path):array{
   $cmd='timeout 25s '.implode(' ',array_map('escapeshellarg',['/usr/bin/ffprobe','-v','error','-show_entries','format=format_name,size,duration:stream=codec_type,codec_name,width,height','-of','json',$path]));
   $lines=[];$rc=0;exec($cmd,$lines,$rc);
   if($rc!==0)return [];
   $raw=json_decode(implode("\n",$lines),true);if(!is_array($raw))return [];
   $result=['container'=>substr((string)($raw['format']['format_name']??''),0,60),'size'=>(int)($raw['format']['size']??0),'duration'=>round((float)($raw['format']['duration']??0),1)];
   foreach(($raw['streams']??[]) as $stream){if(!is_array($stream))continue;
    if(($stream['codec_type']??'')==='video'&&!isset($result['video']))$result['video']=['codec'=>substr((string)($stream['codec_name']??''),0,50),'width'=>(int)($stream['width']??0),'height'=>(int)($stream['height']??0)];
    if(($stream['codec_type']??'')==='audio'&&!isset($result['audio']))$result['audio']=['codec'=>substr((string)($stream['codec_name']??''),0,50)];
   }return $result;
  };
  $originalInfo=$probeSummary($source);$originalInfo['size']=(int)$f['size'];
  $dc51OriginalHash=dc51Digest($source);
  // Crear un archivo NUEVO: nunca ffmpeg escribe sobre el inodo origen ni otro hardlink.
  $newStored=bin2hex(random_bytes(20)).'.mp4';$output=$base.$newStored;
  $args=['/usr/bin/ffmpeg','-nostdin','-hide_banner','-loglevel','error','-y','-i',$source,'-map','0:v:0','-map','0:a:0?','-c:v','libx264','-preset','veryfast','-crf','23','-pix_fmt','yuv420p','-c:a','aac','-b:a','128k','-movflags','+faststart',$output];
  $dc51WasReused=$dc51OriginalHash!==null && dc53ReuseConverted($pdo,$base,$dc51OriginalHash,$output);
  $lines=[];$rc=0;
  if(!$dc51WasReused){$cmd='timeout 7200s '.implode(' ',array_map('escapeshellarg',$args)).' 2>&1';exec($cmd,$lines,$rc);}
  if($rc!==0||!is_file($output)||is_link($output)||filesize($output)<100)throw new RuntimeException('FFmpeg falló: '.substr(implode(' ',array_slice($lines,-3)),0,150));
  $cmd='timeout 30s '.implode(' ',array_map('escapeshellarg',['/usr/bin/ffprobe','-v','error','-select_streams','v:0','-show_entries','stream=codec_name','-of','default=noprint_wrappers=1:nokey=1',$output]));
  $probe=[];$code=0;exec($cmd,$probe,$code);if($code!==0||trim(implode('',$probe))!=='h264')throw new RuntimeException('La salida no contiene H.264 válido');
  $newSize=filesize($output);$convertedInfo=$probeSummary($output);$convertedInfo['size']=$newSize;
  $newHash=hash_file('sha256',$output);if(!$newHash)throw new RuntimeException('No se pudo validar el video convertido');
  $newName=preg_replace('/\.[^.]*$/','',(string)$f['name']).'.mp4';if($newName==='.mp4')$newName='video-'.$id.'.mp4';
  $lock='dc-local-convert-'.$uid;$l=$pdo->prepare('SELECT GET_LOCK(?,30)');$l->execute([$lock]);if((int)$l->fetchColumn()!==1)throw new RuntimeException('Almacenamiento ocupado');
  try{
   $pdo->beginTransaction();
   $q=$pdo->prepare("SELECT state FROM dc_local_video_conversions WHERE video_file_id=? AND owner_id=? FOR UPDATE");$q->execute([$id,$uid]);if($q->fetchColumn()!=='running')throw new RuntimeException('Conversión cancelada o pausada');
   $q=$pdo->prepare('SELECT storage_name,name,parent_id,deleted_at FROM files WHERE id=? AND owner_id=? FOR UPDATE');$q->execute([$id,$uid]);$now=$q->fetch(PDO::FETCH_ASSOC);
   if(!$now||$now['deleted_at']!==null||$now['storage_name']!==$oldName||(int)$now['parent_id']!==(int)$f['parent_id'])throw new RuntimeException('El origen cambió durante la conversión');
   $q=$pdo->prepare('SELECT deleted_at,parent_id FROM files WHERE id=? AND owner_id=?');$q->execute([(int)$f['parent_id'],$uid]);$parent=$q->fetch(PDO::FETCH_ASSOC);if(!$parent||$parent['deleted_at']!==null)throw new RuntimeException('Paquete enviado a Papelera');
   $q->execute([(int)$parent['parent_id'],$uid]);$library=$q->fetch(PDO::FETCH_ASSOC);if(!$library||$library['deleted_at']!==null)throw new RuntimeException('Biblioteca en Papelera');
   $q=$pdo->prepare('SELECT id FROM files WHERE parent_id=? AND owner_id=? AND name=? AND deleted_at IS NULL AND id<>? LIMIT 1');$q->execute([(int)$f['parent_id'],$uid,$newName,$id]);if($q->fetchColumn())throw new RuntimeException('Existe otro archivo con el nombre MP4 de destino');
   $q=$pdo->prepare('UPDATE files SET storage_name=?,name=?,size=?,mime_type=?,has_thumb=0,content_hash_algorithm=?,content_hash=? WHERE id=? AND owner_id=? AND storage_name=?');
   $q->execute([$newStored,$newName,$newSize,'video/mp4','sha256',$newHash,$id,$uid,$oldName]);if($q->rowCount()!==1)throw new RuntimeException('No se pudo actualizar el video');
   $q=$pdo->prepare("INSERT INTO dc_local_video_previews(video_file_id,owner_id,source_storage,state,message,updated_at) VALUES (?,?,?,'queued',NULL,NOW()) ON DUPLICATE KEY UPDATE source_storage=VALUES(source_storage),state='queued',message=NULL,updated_at=NOW()");$q->execute([$id,$uid,$newStored]);
   $history=json_encode(['type'=>'MP4 (H.264/AAC)','requested_by'=>'user','processing'=>$dc51WasReused?'reused':'generated','original_sha256'=>$dc51OriginalHash,'original'=>$originalInfo,'result'=>$convertedInfo],JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE);
   if(!$history)throw new RuntimeException('No se pudieron guardar detalles de la conversión');
   $q=$pdo->prepare("UPDATE dc_local_video_conversions SET state='done',message=NULL,conversion_details=?,updated_at=NOW() WHERE video_file_id=? AND owner_id=? AND source_storage=?");$q->execute([$history,$id,$uid,$oldName]);
   $pdo->commit();$output=null;
   dc52Event($pdo,'conversion',$dc51WasReused,$id,$uid,'video-h264-aac-v1',$dc51OriginalHash,(int)$newSize);
   if($dc51OriginalHash!==null && !$dc51WasReused){
    try{dc51Store($pdo,$dc51OriginalHash,'video-h264-aac-v1',$oldName,$newStored,$newHash,(int)$newSize);}catch(Throwable $ignored){error_log('DriveCraft: caché de conversión no disponible');}
   }
  }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
  finally{$pdo->prepare('SELECT RELEASE_LOCK(?)')->execute([$lock]);}
  // Solo borrar el nombre físico anterior si ninguna fila activa, versión ni hardlink lo requiere.
  $q=$pdo->prepare('SELECT COUNT(*) FROM files WHERE storage_name=?');$q->execute([$oldName]);$ref=(int)$q->fetchColumn();
  $q=$pdo->prepare('SELECT COUNT(*) FROM file_versions WHERE storage_name=?');$q->execute([$oldName]);$ref+=(int)$q->fetchColumn();
  clearstatcache(true,$source);$stat=@stat($source);
  if($ref===0&&$stat&&is_file($source)&&!is_link($source))@unlink($source);
 }catch(Throwable $e){
  $pdo->prepare("UPDATE dc_local_video_conversions SET state='error',message=?,updated_at=NOW() WHERE video_file_id=? AND owner_id=? AND state='running'")->execute([substr($e->getMessage(),0,250),$id,$uid]);
  error_log('DriveCraft conversion '.$id.': '.$e->getMessage());
 }finally{if($output&&is_file($output))@unlink($output);}
}
