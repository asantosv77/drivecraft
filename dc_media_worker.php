<?php
/** Ejecutar solo CLI: php dc_media_worker.php; cron cada minuto. */
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require __DIR__.'/config.php';require_once __DIR__.'/dc_media_lib.php';
$guard=fopen(sys_get_temp_dir().'/drive-craft-media-worker.lock','c');
if(!$guard||!flock($guard,LOCK_EX|LOCK_NB))exit(0);
$base=rtrim($STORAGE_DIR,'/').'/';$thumbRoot=rtrim($THUMBS_DIR,'/').'/';
function dcMediaRun(array $args,int $timeout=600,bool $fullOutput=false): array {
 $command='timeout '.(int)$timeout.'s '.implode(' ',array_map('escapeshellarg',$args)).' 2>&1';
 $output=[];$code=0;exec($command,$output,$code);
 return [$code,implode("\n",$fullOutput ? $output : array_slice($output,-8))];
}

/** v20.10.85a: instala el resultado en el ID del video original; conserva el original en Papelera (_ORI).
 * El archivo físico fuente NO se renombra ni se borra: la Papelera referencia los bytes originales.
 * Toda mutación de catálogo se realiza dentro de una transacción y bloqueo por usuario.
 */
function dc85aReplaceVideo(PDO $pdo,array $file,array $job,string $newStored,int $bytes,string $hash,string $mime,string $newName): int {
 $owner=(int)$job['owner_id'];$fileId=(int)$file['id'];$parent=$file['parent_id'];
 $lock='dc-upload-'.$owner;
 $q=$pdo->prepare('SELECT GET_LOCK(?,15)');$q->execute([$lock]);
 if((int)$q->fetchColumn()!==1)throw new RuntimeException('Almacenamiento ocupado');
 try {
  $pdo->beginTransaction();
  $q=$pdo->prepare('SELECT id,name,storage_name,size,parent_id,deleted_at FROM files WHERE id=? AND owner_id=? FOR UPDATE');
  $q->execute([$fileId,$owner]);$current=$q->fetch(PDO::FETCH_ASSOC);
  if(!$current||$current['deleted_at']!==null||(string)$current['storage_name']!==(string)$file['storage_name']||
     (string)$current['name']!==(string)$file['name'])throw new RuntimeException('El video original cambió mientras se procesaba');
  if($newName!==(string)$current['name']){
   $q=$pdo->prepare('SELECT id FROM files WHERE owner_id=? AND parent_id <=> ? AND name=? AND deleted_at IS NULL LIMIT 1');
   $q->execute([$owner,$parent,$newName]);if($q->fetchColumn())throw new RuntimeException('Ya existe un archivo con el nombre de salida: '.$newName);
  }
  $baseName=preg_replace('/\.[^.]+$/','',(string)$file['name']);
  $extension=pathinfo((string)$file['name'],PATHINFO_EXTENSION);
  $ori=substr($baseName,0,170).'_ORI'.($extension!==''?'.'.$extension:'');
  // Un nombre de archivo distinto puede coincidir con un elemento restaurado: elegir sufijo.
  $q=$pdo->prepare('SELECT COUNT(*) FROM files WHERE owner_id=? AND parent_id <=> ? AND name=? AND deleted_at IS NULL');
  $n=2;$candidate=$ori;
  while(true){$q->execute([$owner,$parent,$candidate]);if(!(int)$q->fetchColumn())break;
   if($n>1000)throw new RuntimeException('No fue posible reservar nombre _ORI');
   $candidate=substr($baseName,0,165).'_ORI_'.$n++.($extension!==''?'.'.$extension:'');
  }
  // El original permanece físicamente en disco, recuperable mediante Papelera.
  $insert=$pdo->prepare('INSERT INTO files(parent_id,name,is_dir,mime_type,size,storage_name,has_thumb,has_list,owner_id,content_hash_algorithm,content_hash,deleted_at) VALUES(?,?,0,?,?,?,?,?,?,?,?,NOW())');
  $insert->execute([$parent,$candidate,(string)$file['mime_type'],(int)$file['size'],(string)$file['storage_name'],(int)$file['has_thumb'],(int)$file['has_list'],$owner,$file['content_hash_algorithm'],$file['content_hash']]);
  $q=$pdo->prepare('UPDATE files SET name=?,mime_type=?,size=?,storage_name=?,has_thumb=0,has_list=0,content_hash_algorithm=?,content_hash=?,last_opened=NULL WHERE id=? AND owner_id=? AND storage_name=? AND deleted_at IS NULL');
  $q->execute([$newName,$mime,$bytes,$newStored,'sha256',$hash,$fileId,$owner,(string)$file['storage_name']]);
  if($q->rowCount()!==1)throw new RuntimeException('No se pudo sustituir el archivo original');
  $pdo->commit();return $fileId;
 }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
 finally{$pdo->prepare('SELECT RELEASE_LOCK(?)')->execute([$lock]);}
}

while(true){
 $pdo->beginTransaction();
 $job=$pdo->query("SELECT * FROM dc_media_jobs WHERE status='queued' ORDER BY id LIMIT 1 FOR UPDATE")->fetch(PDO::FETCH_ASSOC);
 if(!$job){$pdo->commit();break;}
 $pdo->prepare("UPDATE dc_media_jobs SET status='running',started_at=NOW() WHERE id=?")->execute([$job['id']]);$pdo->commit();
 usleep(200000);
 $outputPath=null;$mediaDedupLock=null;$dc85aInstalled=false;
 try {
  $cfg=dcMediaSettings($pdo);$kind=$job['kind'];
  if(in_array($kind,['convert','faststart'],true)&&!$cfg['conversion_enabled']||$kind==='thumbnail'&&!$cfg['thumbnails_enabled'])throw new RuntimeException('Función deshabilitada');
  $q=$pdo->prepare('SELECT * FROM files WHERE id=? AND owner_id=? AND deleted_at IS NULL AND is_dir=0');$q->execute([$job['file_id'],$job['owner_id']]);$file=$q->fetch(PDO::FETCH_ASSOC);
  if(!$file || !dcMediaIsVideo($file) || (string)$file['storage_name']!==(string)$job['source_storage'])throw new RuntimeException('El video original fue eliminado o reemplazado');
  if((int)$file['size']>$cfg['max_mib']*1048576)throw new RuntimeException('Video fuera del límite configurado');
  $stored=(string)$file['storage_name'];if($stored===''||basename($stored)!==$stored||!preg_match('/^[A-Za-z0-9_.-]+$/D',$stored))throw new RuntimeException('Ruta inválida');
  $source=$base.$stored;if(!is_file($source)||is_link($source))throw new RuntimeException('Archivo original ausente');
  if(!is_file('/usr/bin/ffmpeg')||!is_executable('/usr/bin/ffmpeg')||!is_executable('/usr/bin/ffprobe'))throw new RuntimeException('Instalar ffmpeg y ffprobe en el contenedor');
  if(in_array($kind,['convert','faststart'],true)){
   $state=dcMediaAnalyze($source,(string)$file['name']);
   if(!$state['known'])throw new RuntimeException('No se pudo verificar el estado del video');
   if(($kind==='convert'&&!$state['can_convert'])||($kind==='faststart'&&!$state['can_faststart'])){
    $pdo->prepare("UPDATE dc_media_jobs SET status='done',message=?,finished_at=NOW() WHERE id=?")->execute(['Omitido: '.$state['message'],$job['id']]);continue;
   }
  }
  if($kind==='thumbnail'){
   $outputPath=$thumbRoot.$stored.'.webp';$tmp=$outputPath.'.'.bin2hex(random_bytes(6)).'.tmp.webp';
   [$status,$log]=dcMediaRun(['/usr/bin/ffmpeg','-nostdin','-hide_banner','-loglevel','error','-threads','1','-y','-ss','00:00:01','-i',$source,'-frames:v','1','-vf','scale=320:-2',$tmp],90);
   if($status!==0||!is_file($tmp)||filesize($tmp)<100)throw new RuntimeException('No se pudo crear miniatura: '.substr($log,0,150));
   if(!rename($tmp,$outputPath))throw new RuntimeException('No se pudo instalar miniatura');
   $pdo->prepare('UPDATE files SET has_thumb=1 WHERE id=? AND owner_id=? AND storage_name=?')->execute([$file['id'],$job['owner_id'],$stored]);
   $resultId=null;$message='Miniatura lista';
  }elseif($kind==='faststart'){
   if(!preg_match('/\.mp4$/iD',(string)$file['name']))throw new RuntimeException('Faststart solo admite MP4');
   $newStored=bin2hex(random_bytes(20)).'_faststart.mp4';$outputPath=$base.$newStored;
   [$status,$log]=dcMediaRun(['/usr/bin/ffmpeg','-nostdin','-hide_banner','-loglevel','error','-threads','1','-y','-i',$source,'-map','0','-c','copy','-movflags','+faststart',$outputPath],1800);
   if($status!==0||!is_file($outputPath)||filesize($outputPath)<100)throw new RuntimeException('Faststart falló: '.substr($log,0,220));
   $bytes=(int)filesize($outputPath);$hash=hash_file('sha256',$outputPath);
   $resultId=dc85aReplaceVideo($pdo,$file,$job,$newStored,$bytes,$hash,'video/mp4',(string)$file['name']);
   $dc85aInstalled=true;$message='Faststart instalado. Original _ORI enviado a Papelera';
  }else{
   $probeCmd=['/usr/bin/ffprobe','-v','error','-show_entries','stream=codec_type,codec_name','-of','json',$source];
   [$probeStatus,$probeLog]=dcMediaRun($probeCmd,25,true);
   $info=$probeStatus===0?json_decode($probeLog,true):null;
   if(!is_array($info))throw new RuntimeException('No se pudo analizar el códec: '.substr($probeLog,0,160));
   $vcodec='';$acodec='';foreach($info['streams']??[] as $stream){if($stream['codec_type']==='video'&&!$vcodec)$vcodec=$stream['codec_name'];if($stream['codec_type']==='audio'&&!$acodec)$acodec=$stream['codec_name'];}
   if(!$vcodec)throw new RuntimeException('El archivo no contiene video');
   if($job['mode']==='auto'&&$vcodec==='h264'&&in_array($acodec,['aac','mp3',''],true)&&preg_match('/\.mp4$/iD',$file['name'])){
    $pdo->prepare("UPDATE dc_media_jobs SET status='done',message='Ya compatible: no fue necesario convertir',finished_at=NOW() WHERE id=?")->execute([$job['id']]);continue;
   }
   // Bloqueo global por contenido original y perfil. Impide dos conversiones simultáneas idénticas.
   // Perfil 1 = parámetros FFmpeg actuales de Drive-Craft (v20.10.8 a v20.10.11).
   $sourceHash=hash_file('sha256',$source);
   if(!is_string($sourceHash)||!preg_match('/^[a-f0-9]{64}$/D',$sourceHash))throw new RuntimeException('No se pudo calcular SHA-256 del video original');
   $profile='dc-compatible-mp4-aac128-v1-video-'.($vcodec==='h264'?'copy':'libx264-veryfast-crf23');
   $mediaDedupLock='dc-media-'.substr(hash('sha256',$sourceHash.'|'.$profile),0,36);
   $lockQuery=$pdo->prepare('SELECT GET_LOCK(?,30)');$lockQuery->execute([$mediaDedupLock]);
   if((int)$lockQuery->fetchColumn()!==1)throw new RuntimeException('Otra conversión idéntica está en curso; reintentar más tarde');
   $newStored=bin2hex(random_bytes(20)).'_compatible.mp4';$outputPath=$base.$newStored;
   $dedupUsed=false;
   // Consultamos solamente trabajos completados. No exponemos información de usuarios ajenos.
   // Para la versión actual no hay columna de perfil en la BD: el perfil v1 se define
   // de forma conservadora mediante el tipo de conversión y los archivos de salida.
   $cand=$pdo->prepare("SELECT orig.storage_name AS source_path, orig.content_hash_algorithm AS src_alg, orig.content_hash AS src_hash,
                                  outp.storage_name AS out_path, outp.size AS out_size,
                                  outp.content_hash_algorithm AS out_alg, outp.content_hash AS out_hash
                           FROM dc_media_jobs j
                           JOIN files orig ON orig.id=j.file_id AND orig.owner_id=j.owner_id
                           JOIN files outp ON outp.id=j.output_file_id AND outp.owner_id=j.owner_id
                           WHERE j.kind='convert' AND j.status='done' AND j.output_file_id IS NOT NULL
                             AND outp.is_dir=0 AND outp.deleted_at IS NULL AND outp.mime_type='video/mp4'
                             AND orig.is_dir=0 AND orig.size=?
                             AND orig.content_hash_algorithm='sha256' AND orig.content_hash=?
                           ORDER BY j.id DESC LIMIT 25");
   $cand->execute([(int)$file['size'],$sourceHash]);
   foreach($cand->fetchAll(PDO::FETCH_ASSOC) as $candidate){
    $srcStored=(string)$candidate['source_path'];$outStored=(string)$candidate['out_path'];
    if($srcStored===''||basename($srcStored)!==$srcStored||!preg_match('/^[A-Za-z0-9_.-]+$/D',$srcStored))continue;
    if($outStored===''||basename($outStored)!==$outStored||!preg_match('/^[A-Za-z0-9_.-]+$/D',$outStored))continue;
    $srcCandidate=$base.$srcStored;$outCandidate=$base.$outStored;
    if(!is_file($srcCandidate)||is_link($srcCandidate)||!is_file($outCandidate)||is_link($outCandidate))continue;
    if((int)filesize($srcCandidate)!==(int)$file['size'])continue;
    // No basarse exclusivamente en el hash de la BD: validar los bytes originales.
    if(!hash_equals($sourceHash,(string)hash_file('sha256',$srcCandidate)))continue;
    if((int)filesize($outCandidate)!==(int)$candidate['out_size']||(int)$candidate['out_size']<100)continue;
    if($candidate['out_alg']!=='sha256'||!preg_match('/^[a-f0-9]{64}$/D',(string)$candidate['out_hash']))continue;
    if(!hash_equals((string)$candidate['out_hash'],(string)hash_file('sha256',$outCandidate)))continue;
    // Solo permite hardlinks dentro del mismo dispositivo; no copiar si falla.
    if(!@link($outCandidate,$outputPath))continue;
    $a=@stat($outCandidate);$b=@stat($outputPath);
    if(!$a||!$b||$a['dev']!==$b['dev']||$a['ino']!==$b['ino']){@unlink($outputPath);continue;}
    $dedupUsed=true;break;
   }
   if(!$dedupUsed){
   $args=['/usr/bin/ffmpeg','-nostdin','-hide_banner','-loglevel','error','-threads','1','-y','-i',$source,'-map','0:v:0','-map','0:a:0?','-c:v',$vcodec==='h264'?'copy':'libx264'];
   if($vcodec!=='h264')array_push($args,'-preset','veryfast','-crf','23');
   array_push($args,'-c:a','aac','-b:a','128k','-movflags','+faststart',$outputPath);
   [$status,$log]=dcMediaRun($args,1800);
   if($status!==0||!is_file($outputPath)||filesize($outputPath)<100)throw new RuntimeException('Error FFmpeg: '.substr($log,0,220));
   }
   $bytes=(int)filesize($outputPath);$hash=hash_file('sha256',$outputPath);
   $name=preg_replace('/\.[^.]+$/','',(string)$file['name']).'.mp4';
   $resultId=dc85aReplaceVideo($pdo,$file,$job,$newStored,$bytes,$hash,'video/mp4',$name);
   $dc85aInstalled=true;
   $message=($dedupUsed?'Video compatible reutilizado':'Video convertido').'. Original _ORI enviado a Papelera';
  }
  $pdo->prepare("UPDATE dc_media_jobs SET status='done',message=?,output_file_id=?,finished_at=NOW() WHERE id=?")->execute([$message,$resultId,$job['id']]);
  if($mediaDedupLock!==null)$pdo->prepare('SELECT RELEASE_LOCK(?)')->execute([$mediaDedupLock]);
 }catch(Throwable $e){
  if($pdo->inTransaction())$pdo->rollBack();
  if($mediaDedupLock!==null)try{$pdo->prepare('SELECT RELEASE_LOCK(?)')->execute([$mediaDedupLock]);}catch(Throwable $ignored){}
  if(!$dc85aInstalled && $outputPath && is_file($outputPath) && in_array($job['kind'],['convert','faststart'],true))@unlink($outputPath);
  $pdo->prepare("UPDATE dc_media_jobs SET status='error',message=?,finished_at=NOW() WHERE id=?")->execute([substr($e->getMessage(),0,240),$job['id']]);
  error_log('DC media job '.$job['id'].': '.$e->getMessage());
 usleep(300000);
 }
}
