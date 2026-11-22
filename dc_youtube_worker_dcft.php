<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require __DIR__.'/config.php';require_once __DIR__.'/dc_media_tools_lib_dcft.php';require_once __DIR__.'/dc_youtube_audio_dcft.php';
$lock=fopen(sys_get_temp_dir().'/dcmt-video-worker.lock','c');if(!$lock||!flock($lock,LOCK_EX|LOCK_NB))exit;
// A prior process no longer owns the lock: safely resume its durable job/partials.
$pdo->exec("UPDATE dc_youtube_jobs_dcft SET state=IF(attempts>=3,'error','retry'),phase=IF(attempts>=3,'Interrumpido','Recuperando descarga'),message='El proceso anterior se interrumpió.',next_attempt=NOW(),updated_at=NOW() WHERE state='running'");
$cleanupRoot=rtrim($STORAGE_DIR,'/').'/.dc-youtube-work';
foreach($pdo->query("SELECT id FROM dc_youtube_jobs_dcft WHERE state IN ('error','done') AND updated_at<DATE_SUB(NOW(),INTERVAL 1 DAY) LIMIT 20")->fetchAll(PDO::FETCH_COLUMN) as $oldId)dcmtWorkClean($cleanupRoot,(string)$oldId);
$q=$pdo->query("SELECT * FROM dc_youtube_jobs_dcft WHERE state IN ('queued','retry') AND (next_attempt IS NULL OR next_attempt<=NOW()) ORDER BY created_at LIMIT 1");$job=$q->fetch(PDO::FETCH_ASSOC);if(!$job)exit;
$id=$job['id'];if(!preg_match('/^[a-f0-9]{32}$/D',$id))exit;
$uid=(int)$job['owner_id'];$folder=(int)$job['folder_id'];$attempt=(int)$job['attempts']+1;
$pdo->prepare("UPDATE dc_youtube_jobs_dcft SET state='running',phase='Consultando YouTube',attempts=?,message='',updated_at=NOW() WHERE id=?")->execute([$attempt,$id]);
$root=rtrim($STORAGE_DIR,'/').'/.dc-youtube-work';$work=$root.'/'.$id;$published=[];
function dcmtUpdate(PDO $db,string $id,string $phase,float $percent,?float $download=null,string $title=''):void {
 $db->prepare("UPDATE dc_youtube_jobs_dcft SET phase=?,progress=?,download_percent=?,title=IF(?='',title,?),updated_at=NOW() WHERE id=? AND state='running'")->execute([$phase,$percent,$download,$title,$title,$id]);
}
function dcmtWorkClean(string $root,string $id):void {
 if(!preg_match('/^[a-f0-9]{32}$/D',$id))return;$path=$root.'/'.$id;if(is_link($path)||!is_dir($path))return;
 foreach(new DirectoryIterator($path) as $file)if(!$file->isDot()&&($file->isFile()||$file->isLink()))@unlink($file->getPathname());@rmdir($path);
}
try{
 if(!dcmtDownloadsEnabled($pdo))throw new RuntimeException('Descargas deshabilitadas por el administrador');
 $q=$pdo->prepare("SELECT * FROM dc_users WHERE id=? AND status='active'");$q->execute([$uid]);$u=$q->fetch(PDO::FETCH_ASSOC);if(!$u||(($u['role']??'')!=='admin'&&empty($u['can_upload'])))throw new RuntimeException('La cuenta ya no tiene permiso para importar');
 dcmtOwned($pdo,$uid,$folder,true);
 $audioJob=($job['media_kind']??'video')==='audio';
 if($audioJob){if(!dcmtAudioFolder($pdo,$uid,$folder))throw new RuntimeException('La carpeta ya no pertenece a una biblioteca musical');}
 else{$q=$pdo->prepare('SELECT 1 FROM dc_youtube_folders WHERE folder_id=? AND owner_id=?');$q->execute([$folder,$uid]);if(!$q->fetchColumn())throw new RuntimeException('La carpeta ya no es de tipo YouTube Links');}
 $limit=dcmtLimit($pdo,$u);$free=@disk_free_space($STORAGE_DIR);if($free!==false)$limit=min($limit,max(0,(int)(($free-67108864)/2)));if($limit<1048576)throw new RuntimeException('No queda espacio suficiente en la cuota');
 if(!is_dir($root)&&!mkdir($root,0700,true))throw new RuntimeException('No se pudo preparar la descarga');if(is_link($root))throw new RuntimeException('Directorio temporal inválido');
 if(!is_dir($work)&&!mkdir($work,0700))throw new RuntimeException('No se pudo preparar la descarga');if(is_link($work))throw new RuntimeException('Directorio temporal inválido');
 $meta=dcmtInspect($job['youtube_id']);if(!empty($meta['is_live'])||($meta['live_status']??'')==='is_upcoming')throw new RuntimeException('Solo se admiten videos terminados, no emisiones en directo');
 if((float)($meta['duration']??0)>7200)throw new RuntimeException('El video supera el límite de dos horas por descarga');
 if($audioJob){dcmtProcessYoutubeAudio($pdo,$job,$u,$meta,$work,$root,$limit,$published,$STORAGE_DIR);return;}
 $title=mb_substr(trim((string)($meta['title']??'')),0,240)?:'Video YouTube';$url='https://www.youtube.com/watch?v='.$job['youtube_id'];$height=(int)$job['height'];
 dcmtUpdate($pdo,$id,'Descargando video',5,null,$title);$buffer='';$last=0.0;$progress=5.0;
 $format=dcmtAdapterFormat($height);if(strlen($format)>600||preg_match('/[\x00-\x20]/',$format))throw new RuntimeException('Selector de formato inválido');
 $args=[dcmtYtBin(),'--ignore-config','--no-playlist','--no-warnings','--continue','--newline','--progress','--progress-template','download:DC_PROGRESS %(progress._percent_str)s','--socket-timeout','15','--retries','2','--fragment-retries','2','--extractor-retries','1','--concurrent-fragments','1','--max-filesize',(string)$limit,'--match-filter','!is_live & duration <= 7200','--merge-output-format','mp4','-f',$format,'-o',$work.'/source.%(ext)s','--',$url];
 $r=dcmtRun($args,1800,4194304,function(string $out,string $err,float $elapsed)use($pdo,$id,$work,$limit,&$buffer,&$last,&$progress){
  $buffer.=$out;if(strlen($buffer)>8192)$buffer=substr($buffer,-8192);
  if($elapsed-$last<1)return;$last=$elapsed;$size=0;foreach(new DirectoryIterator($work) as $f)if(!$f->isDot()&&$f->isFile())$size+=$f->getSize();if($size>$limit*2+1048576)throw new RuntimeException('La descarga supera el límite de almacenamiento temporal');
  $percent=null;if(preg_match_all('/DC_PROGRESS\s+([\d.]+)%/',$buffer,$m)&&$m[1]){$percent=min(100,max(0,(float)end($m[1])));$progress=max($progress,5+$percent*.8);}
  dcmtUpdate($pdo,$id,'Descargando video',$progress,$percent);
 });
 if($r['code']!==0)throw new RuntimeException(dcmtFailure($r['stderr']));$source=$work.'/source.mp4';if(!is_file($source)||is_link($source)||filesize($source)<1024)throw new RuntimeException('No se obtuvo un archivo de video completo');
 dcmtUpdate($pdo,$id,'Validando video y audio',89);
 $probe=dcmtRun(['/usr/bin/ffprobe','-v','error','-show_entries','stream=codec_type,codec_name,height:format=duration','-of','json',$source],20);$media=json_decode($probe['stdout'],true);$video=false;$compatible=true;foreach($media['streams']??[] as $s){if(($s['codec_type']??'')==='video'){$video=true;if(($s['codec_name']??'')!=='h264'||(int)($s['height']??0)>$height)$compatible=false;}elseif(($s['codec_type']??'')==='audio'&&($s['codec_name']??'')!=='aac')$compatible=false;}if(!$compatible)throw new RuntimeException('El formato descargado no cumple H.264/AAC o la resolución máxima');
 if($probe['code']!==0||!$video||(float)($media['format']['duration']??0)<=0||(float)($media['format']['duration']??0)>7200)throw new RuntimeException('No se pudo validar el video descargado');
 // Remux only: move MP4 metadata to the beginning for seeking/fast playback.
 $ready=$work.'/ready.mp4';if(!is_file($ready)){$r=dcmtRun(['/usr/bin/ffmpeg','-nostdin','-hide_banner','-loglevel','error','-y','-threads','1','-i',$source,'-map','0:v:0','-map','0:a:0?','-c','copy','-movflags','+faststart',$ready],180);if($r['code']!==0)throw new RuntimeException('No se pudo preparar el MP4 para reproducción');}
 $size=filesize($ready);if($size>$limit)throw new RuntimeException('El video supera el límite por archivo o la cuota disponible');
 dcmtUpdate($pdo,$id,'Integrando a Drivecraft',95);$stored='dc-yt-'.$id.'.mp4';$dest=rtrim($STORAGE_DIR,'/').'/'.$stored;
 if(!is_file($dest)){if(!link($ready,$dest)&&!copy($ready,$dest))throw new RuntimeException('No se pudo publicar el archivo de video');}$published[]=$dest;
 $payload=json_encode(['drivecraft_type'=>'local-video-package-v1','title'=>$title,'description'=>mb_substr((string)($meta['description']??''),0,4000),'source_service'=>'youtube','source_url'=>$url,'youtube_id'=>$job['youtube_id']],JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR);
 // Native local-video readers cap metadata.json at 16 KiB. Bound encoded bytes too.
 if(strlen($payload)>15000){$bounded=json_decode($payload,true,32,JSON_THROW_ON_ERROR);while(strlen($payload)>15000&&$bounded['description']!==''){$bounded['description']=mb_substr($bounded['description'],0,(int)(mb_strlen($bounded['description'])*.75));$payload=json_encode($bounded,JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR);}}
 $metaStored='dc-yt-'.$id.'.json';$metaDest=rtrim($STORAGE_DIR,'/').'/'.$metaStored;if(file_put_contents($metaDest,$payload,LOCK_EX)!==strlen($payload))throw new RuntimeException('No se pudieron guardar los metadatos');$published[]=$metaDest;
 $pdo->beginTransaction();$q=$pdo->prepare("SELECT * FROM dc_users WHERE id=? AND status='active' FOR UPDATE");$q->execute([$uid]);$fresh=$q->fetch(PDO::FETCH_ASSOC);if(!$fresh||(($fresh['role']??'')!=='admin'&&empty($fresh['can_upload'])))throw new RuntimeException('El permiso de importación fue revocado');
 dcmtOwned($pdo,$uid,$folder,true);if($size+strlen($payload)>dcmtLimit($pdo,$fresh))throw new RuntimeException('No queda espacio suficiente en la cuota');
 $q=$pdo->prepare('SELECT file_id FROM dc_youtube_jobs_dcft WHERE id=? FOR UPDATE');$q->execute([$id]);if($q->fetchColumn())throw new RuntimeException('El trabajo ya fue publicado');
 $base='Video '.mb_substr(preg_replace('/[\x00-\x1f\/\\\\]/u','_',$title),0,100);$name=$base;$n=1;$q=$pdo->prepare('SELECT COUNT(*) FROM files WHERE parent_id=? AND owner_id=? AND name=? AND deleted_at IS NULL');do{$q->execute([$folder,$uid,$name]);if(!(int)$q->fetchColumn())break;$name=$base.' ('.(++$n).')';}while($n<1000);
 $pdo->prepare("INSERT INTO files(parent_id,name,is_dir,mime_type,owner_id) VALUES(?,?,1,'directory',?)")->execute([$folder,$name,$uid]);$package=(int)$pdo->lastInsertId();
 $filename=mb_substr(preg_replace('/[\x00-\x1f\/\\\\]/u','_',$title),0,180).'.mp4';
 $pdo->prepare('INSERT INTO files(parent_id,name,is_dir,mime_type,size,storage_name,has_thumb,has_list,owner_id,content_hash) VALUES(?,?,0,?,?,?,?,0,?,?)')->execute([$package,$filename,'video/mp4',$size,$stored,0,$uid,hash_file('sha256',$dest)]);$file=(int)$pdo->lastInsertId();
 $pdo->prepare("INSERT INTO files(parent_id,name,is_dir,mime_type,size,storage_name,has_thumb,has_list,owner_id) VALUES(?,'metadata.json',0,'application/json',?,?,0,0,?)")->execute([$package,strlen($payload),$metaStored,$uid]);
 $pdo->prepare("INSERT INTO dc_local_video_previews(video_file_id,owner_id,source_storage,state,updated_at) VALUES(?,?,?,'queued',NOW()) ON DUPLICATE KEY UPDATE state='queued',updated_at=NOW()")->execute([$file,$uid,$stored]);
 $pdo->prepare("UPDATE dc_youtube_jobs_dcft SET state='done',phase='Video incorporado',progress=100,download_percent=100,file_id=?,package_id=?,message='Descarga completada y guardada en la carpeta.',updated_at=NOW() WHERE id=?")->execute([$file,$package,$id]);$pdo->commit();$published=[];dcmtWorkClean($root,$id);
 // The existing preview worker handles the new poster/short hover video.
 exec(escapeshellarg(PHP_BINDIR.'/php').' '.escapeshellarg(__DIR__.'/dc_local_video_worker.php').' >/dev/null 2>&1 &');
}catch(Throwable $e){
 if($pdo->inTransaction())$pdo->rollBack();foreach($published as $p)if(is_file($p)&&!is_link($p))@unlink($p);
 $message=mb_substr($e instanceof PDOException?'No se pudo integrar el video en la biblioteca.':$e->getMessage(),0,1000);$permanent=(bool)preg_match('/cuota|límite|supera|permiso|carpeta|privado|retirado|directo|deshabilitadas|formato.*disponible/i',$message);$retry=$attempt<3&&!$permanent;
 $pdo->prepare("UPDATE dc_youtube_jobs_dcft SET state=?,phase=?,message=?,next_attempt=DATE_ADD(NOW(),INTERVAL ? SECOND),updated_at=NOW() WHERE id=? AND state='running'")->execute([$retry?'retry':'error',$retry?'Esperando reintento':'No se pudo completar',$message,$attempt*60,$id]);
 if(!$retry)dcmtWorkClean($root,$id);
 error_log('Drivecraft YouTube job '.$id.' attempt '.$attempt.': '.$message);
}
