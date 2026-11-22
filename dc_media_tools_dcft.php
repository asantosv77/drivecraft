<?php
declare(strict_types=1);
require __DIR__.'/config.php';require_once __DIR__.'/auth.php';require_once __DIR__.'/dc_media_tools_lib_dcft.php';require_once __DIR__.'/dc_youtube_audio_dcft.php';
header('Content-Type: application/json; charset=utf-8');header('Cache-Control: private, no-store');header('X-Content-Type-Options: nosniff');
function dcmtReply(array $a,int $status=200):never{http_response_code($status);echo json_encode($a,JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE);exit;}
$u=dcRequireUser(false);$uid=(int)$u['id'];if(!empty($u['must_change_password']))dcmtReply(['error'=>'Actualiza tu contraseña antes de continuar'],403);
$act=(string)($_POST['action']??$_GET['action']??'');
if(!in_array($act,['jobs','progress_get','scope','capabilities'],true)){
 if(($_SERVER['REQUEST_METHOD']??'')!=='POST')dcmtReply(['error'=>'POST requerido'],405);
 if(!hash_equals((string)($_SESSION['dc_csrf']??''),(string)($_POST['csrf']??''))||empty($_POST['csrf']))dcmtReply(['error'=>'Sesión desactualizada; recarga la página'],403);
}
try{
 if($act==='capabilities')dcmtReply(['success'=>true,'downloads_enabled'=>dcmtDownloadsEnabled($pdo)]);
 if($act==='scope'){$folder=(int)($_GET['folder_id']??0);dcmtReply(['success'=>true,'eligible'=>$folder>0&&dcmtIsScope($pdo,$uid,$folder)]);}
 if($act==='jobs'){
  $q=$pdo->prepare("SELECT id,folder_id,media_kind,audio_format,audio_bitrate,state,phase,progress,download_percent,title,message,file_id,package_id,attempts,updated_at FROM dc_youtube_jobs_dcft WHERE owner_id=? AND (state IN ('queued','running','retry') OR created_at>DATE_SUB(NOW(),INTERVAL 1 DAY)) ORDER BY created_at DESC LIMIT 20");$q->execute([$uid]);dcmtReply(['success'=>true,'jobs'=>$q->fetchAll(PDO::FETCH_ASSOC)]);
 }
 if($act==='progress_get'||$act==='progress_save'){
  $kind=(string)($_REQUEST['kind']??'');$key=(string)($_REQUEST['key']??'');
  if($kind==='local'){if(!ctype_digit($key))throw new RuntimeException('Archivo inválido');$f=dcmtOwned($pdo,$uid,(int)$key);if((int)$f['is_dir']||!str_starts_with($f['mime_type']??'','video/'))throw new RuntimeException('Video no disponible');}
  elseif($kind==='youtube'){if(!preg_match('/^[A-Za-z0-9_-]{11}$/D',$key))throw new RuntimeException('Video inválido');}else throw new RuntimeException('Tipo de progreso inválido');
  if($act==='progress_get'){$q=$pdo->prepare('SELECT position_seconds,duration_seconds FROM dc_media_progress_dcft WHERE owner_id=? AND kind=? AND item_key=?');$q->execute([$uid,$kind,$key]);dcmtReply(['success'=>true,'progress'=>$q->fetch(PDO::FETCH_ASSOC)?:null]);}
  $p=filter_var($_POST['position']??'',FILTER_VALIDATE_FLOAT);$d=filter_var($_POST['duration']??'',FILTER_VALIDATE_FLOAT);if($p===false||$d===false||!is_finite($p)||!is_finite($d)||$p<0||$d<0||$p>864000||$d>864000)dcmtReply(['error'=>'Posición inválida'],422);$p=min($p,$d?:$p);
  $pdo->prepare('INSERT INTO dc_media_progress_dcft(owner_id,kind,item_key,position_seconds,duration_seconds) VALUES(?,?,?,?,?) ON DUPLICATE KEY UPDATE position_seconds=VALUES(position_seconds),duration_seconds=VALUES(duration_seconds),updated_at=NOW()')->execute([$uid,$kind,$key,$p,$d]);dcmtReply(['success'=>true]);
 }
 // These operations require an upload-capable account; playback/progress do not.
 if(($u['role']??'')!=='admin'&&empty($u['can_upload']))dcmtReply(['error'=>'Tu cuenta no tiene permiso para importar o consultar fuentes externas'],403);
 if(in_array($act,['search','inspect','inspect_local'],true)){
  $rate='dcmt_last_'.$act;if(time()-(int)($_SESSION[$rate]??0)<3)dcmtReply(['error'=>'Espera unos segundos antes de repetir la consulta'],429);$_SESSION[$rate]=time();
  if($act==='inspect_local'){$f=dcmtOwned($pdo,$uid,(int)($_POST['file_id']??0));$s=$f['storage_name']??'';if((int)$f['is_dir']||!preg_match('/^[A-Za-z0-9_.-]+$/D',$s)||!str_starts_with($f['mime_type']??'','video/'))throw new RuntimeException('Video inválido');$path=rtrim($STORAGE_DIR,'/').'/'.$s;dcmtReply(['success'=>true,'message'=>is_file($path)&&!is_link($path)&&filesize($path)>0?'El video local existe y tiene contenido.':'No se encontró el archivo físico del video.']);}
  if(session_status()===PHP_SESSION_ACTIVE)session_write_close();
  $lock=fopen(sys_get_temp_dir().'/dcmt-youtube-inspect.lock','c');if(!$lock||!flock($lock,LOCK_EX|LOCK_NB))dcmtReply(['error'=>'Hay otra consulta de YouTube en curso; reintenta en unos segundos'],429);
  if($act==='search'){
   $query=trim((string)($_POST['query']??''));if($query===''||mb_strlen($query)>180||preg_match('/[\x00-\x1f]/',$query))dcmtReply(['error'=>'Escribe una búsqueda válida'],422);
   $r=dcmtRun([dcmtYtBin(),'--ignore-config','--flat-playlist','--dump-single-json','--skip-download','--no-warnings','--socket-timeout','8','--retries','0','--extractor-retries','0','--','ytsearch4:'.$query],35);
   if($r['code']!==0)throw new RuntimeException(dcmtFailure($r['stderr']));$data=json_decode($r['stdout'],true);$items=[];
   require_once __DIR__.'/dc_youtube_adapter_dcft.php';$items=is_array($data)?dcmtAdapterSearch($data):[];$safe=[];
   foreach(array_slice($items,0,4) as $item){if(!is_array($item)||!is_string($item['id']??null)||!preg_match('/^[A-Za-z0-9_-]{11}$/D',$item['id']))continue;$safe[]=['id'=>$item['id'],'title'=>mb_substr((string)($item['title']??''),0,300),'channel'=>mb_substr((string)($item['channel']??''),0,200),'duration'=>max(0,(int)($item['duration']??0)),'url'=>'https://www.youtube.com/watch?v='.$item['id']];}
   dcmtReply(['success'=>true,'items'=>$safe]);
  }
  $id=dcmtYoutubeId((string)($_POST['url']??''));if(!$id)dcmtReply(['error'=>'Pega un enlace HTTPS de un video de YouTube'],422);$meta=dcmtInspect($id);dcmtReply(['success'=>true,'message'=>'YouTube devolvió el video: '.mb_substr((string)$meta['title'],0,240).'. La reproducción integrada puede tener restricciones distintas.']);
 }
 if($act==='enqueue'){
  if(!dcmtDownloadsEnabled($pdo))dcmtReply(['error'=>'Las descargas de YouTube están deshabilitadas por el administrador. Puedes guardar el enlace.'],403);
  $folder=(int)($_POST['folder_id']??0);dcmtOwned($pdo,$uid,$folder,true);
  $kind=(string)($_POST['media_kind']??'video');if(!in_array($kind,['video','audio'],true))dcmtReply(['error'=>'Tipo de descarga inválido'],422);
  $audioFormat=(string)($_POST['audio_format']??'opus');$audioBitrate=(int)($_POST['audio_bitrate']??128);
  if($kind==='audio'){dcmtAudioChoices($audioFormat,$audioBitrate);if(!dcmtAudioFolder($pdo,$uid,$folder))throw new RuntimeException('La carpeta debe pertenecer a una biblioteca musical');}
  else{$q=$pdo->prepare('SELECT 1 FROM dc_youtube_folders WHERE folder_id=? AND owner_id=?');$q->execute([$folder,$uid]);if(!$q->fetchColumn())throw new RuntimeException('La carpeta debe ser de tipo YouTube Links');}
  $id=dcmtYoutubeId((string)($_POST['url']??''));if(!$id)dcmtReply(['error'=>'Enlace de YouTube inválido'],422);$height=(int)($_POST['height']??720);if(!in_array($height,[360,720,1080],true))dcmtReply(['error'=>'Calidad inválida'],422);
  if(dcmtLimit($pdo,$u)<1048576)dcmtReply(['error'=>'No queda espacio suficiente en tu cuota'],413);
  $q=$pdo->prepare("SELECT GET_LOCK(?,5)");$q->execute(['dcmt-enqueue-'.$uid]);if((int)$q->fetchColumn()!==1)dcmtReply(['error'=>'Cola ocupada'],409);
  try{
   $q=$pdo->prepare("SELECT * FROM dc_youtube_jobs_dcft WHERE owner_id=? AND folder_id=? AND youtube_id=? AND media_kind=? AND (media_kind='video' OR (audio_format=? AND audio_bitrate=?)) AND state IN ('queued','running','retry','done') ORDER BY created_at DESC LIMIT 1");$q->execute([$uid,$folder,$id,$kind,$audioFormat,$audioBitrate]);$existing=$q->fetch(PDO::FETCH_ASSOC);
   if($existing){$valid=$existing['state']!=='done';if(!$valid){try{dcmtOwned($pdo,$uid,(int)$existing['file_id']);$valid=true;}catch(Throwable $ignored){}}if($valid)dcmtReply(['success'=>true,'id'=>$existing['id'],'existing'=>true,'message'=>$existing['state']==='done'?'Este video ya está descargado en la carpeta.':'Este video ya está en la cola.']);}
   $q=$pdo->prepare("SELECT COUNT(*) FROM dc_youtube_jobs_dcft WHERE owner_id=? AND state IN ('queued','running','retry')");$q->execute([$uid]);if((int)$q->fetchColumn()>=3)dcmtReply(['error'=>'Ya tienes tres descargas pendientes; espera a que terminen'],429);
   $job=bin2hex(random_bytes(16));$pdo->prepare("INSERT INTO dc_youtube_jobs_dcft(id,owner_id,folder_id,youtube_id,height,title,media_kind,audio_format,audio_bitrate) VALUES(?,?,?,?,?,?,?,?,?)")->execute([$job,$uid,$folder,$id,$height,mb_substr(trim((string)($_POST['title']??'')),0,300),$kind,$audioFormat,$audioBitrate]);
  }finally{$pdo->prepare('SELECT RELEASE_LOCK(?)')->execute(['dcmt-enqueue-'.$uid]);}
  dcmtStartWorker();dcmtReply(['success'=>true,'id'=>$job]);
 }
 if($act==='retry'){
  $id=(string)($_POST['id']??'');$q=$pdo->prepare("SELECT * FROM dc_youtube_jobs_dcft WHERE id=? AND owner_id=? AND state='error'");$q->execute([$id,$uid]);$j=$q->fetch(PDO::FETCH_ASSOC);if(!$j)throw new RuntimeException('Tarea no disponible para reintentar');dcmtOwned($pdo,$uid,(int)$j['folder_id'],true);
  $pdo->prepare("UPDATE dc_youtube_jobs_dcft SET state='queued',phase='En cola',attempts=0,message='',next_attempt=NULL,updated_at=NOW() WHERE id=? AND owner_id=? AND state='error'")->execute([$id,$uid]);dcmtStartWorker();dcmtReply(['success'=>true,'id'=>$id]);
 }
 dcmtReply(['error'=>'Acción no disponible'],400);
}catch(Throwable $e){dcmtReply(['error'=>$e instanceof PDOException?'No se pudo consultar la cola. Revisa la instalación del módulo.':$e->getMessage()],$e instanceof PDOException?503:422);}
