<?php
/* Drive-Craft v20.10.26: biblioteca local, paquetes y previews. */
require __DIR__.'/config.php';
require_once __DIR__.'/auth.php';
header('Cache-Control: private, no-store');
header('X-Content-Type-Options: nosniff');
$u=dcRequireUser();$uid=(int)$u['id'];$isAdmin=($u['role']??'')==='admin';
function dlFail($status,$msg){http_response_code($status);header('Content-Type: application/json; charset=utf-8');echo json_encode(['error'=>$msg]);exit;}
function dlConfig(PDO $db):array{
 $q=$db->prepare("SELECT setting_key,setting_value FROM dc_app_settings WHERE setting_key IN ('local_preview_seconds','local_preview_audio','local_preview_format')");
 $q->execute();
 $a=$q->fetchAll(PDO::FETCH_KEY_PAIR);
 $seconds=(int)($a['local_preview_seconds']??10);
 $format=(string)($a['local_preview_format']??'webm');
 if(!in_array($format,['webm','mp4'],true))$format='webm';
 return ['seconds'=>min(30,max(3,$seconds)),'audio'=>(string)($a['local_preview_audio']??'0')==='1','format'=>$format];
}
function dlFolder(PDO $db,int $folder,int $uid):array{
 $visited=[];$current=$folder;$first=null;
 while($current>0){if(isset($visited[$current])||count($visited)>128)dlFail(404,'Jerarquía inválida');$visited[$current]=1;
  $q=$db->prepare('SELECT id,parent_id,name,is_dir,deleted_at,owner_id FROM files WHERE id=?');$q->execute([$current]);$r=$q->fetch(PDO::FETCH_ASSOC);
  if(!$r||(int)$r['owner_id']!==$uid||!(int)$r['is_dir']||$r['deleted_at']!==null)dlFail(404,'Carpeta inexistente');$first??=$r;$current=(int)$r['parent_id'];
 }if(!$first)dlFail(404,'Carpeta no válida');return $first;
}
function dlValidStorage($s):bool{return is_string($s)&&preg_match('/^[A-Za-z0-9_.-]+$/D',$s)===1;}
function dlFile(PDO $db,int $id,int $uid):array{$q=$db->prepare('SELECT * FROM files WHERE id=? AND owner_id=? AND deleted_at IS NULL AND is_dir=0');$q->execute([$id,$uid]);$f=$q->fetch(PDO::FETCH_ASSOC);if(!$f)dlFail(404,'Archivo no encontrado');return $f;}
function dlVideo(array $f):bool{
 if(preg_match('/\.(mp4|mov|mkv|webm|avi|m4v|mpeg|mpg|3gp)$/iD',(string)$f['name'])!==1)return false;
 $mime=strtolower(trim((string)($f['mime_type']??'')));
 if(str_starts_with($mime,'video/'))return true;
 // Algunas copias deduplicadas conservan un MIME genérico, pero el archivo debe verificarse en disco.
 if(!in_array($mime,['','application/octet-stream','binary/octet-stream','application/x-octet-stream'],true))return false;
 global $STORAGE_DIR;
 $stored=(string)($f['storage_name']??'');if(!dlValidStorage($stored))return false;
 $path=rtrim($STORAGE_DIR,'/').'/'.$stored;
 if(!is_file($path)||is_link($path))return false;
 $detected=strtolower((string)@mime_content_type($path));
 return str_starts_with($detected,'video/') || in_array($detected,['application/mp4','application/x-matroska','application/ogg'],true);
}
function dlPackage(PDO $db,int $uid,int $folder,int $package,int $video,string $store):array{
 dlFolder($db,$folder,$uid);
 $m=$db->prepare('SELECT 1 FROM dc_youtube_folders WHERE folder_id=? AND owner_id=?');$m->execute([$folder,$uid]);if(!$m->fetchColumn())dlFail(409,'Carpeta no clasificada como YouTube Links');
 $q=$db->prepare('SELECT id FROM files WHERE id=? AND owner_id=? AND parent_id=? AND is_dir=1 AND deleted_at IS NULL');$q->execute([$package,$uid,$folder]);if(!$q->fetchColumn())dlFail(404,'Paquete no encontrado');
 $f=dlFile($db,$video,$uid);if((int)$f['parent_id']!==$package||!dlVideo($f)||!dlValidStorage($f['storage_name']))dlFail(409,'Video no válido en paquete');
 $q=$db->prepare("SELECT storage_name,size,id FROM files WHERE parent_id=? AND owner_id=? AND name='metadata.json' AND is_dir=0 AND deleted_at IS NULL");$q->execute([$package,$uid]);$m=$q->fetch(PDO::FETCH_ASSOC);
 if(!$m||!dlValidStorage($m['storage_name'])||(int)$m['size']>16384)dlFail(409,'Metadatos inválidos');
 $path=rtrim($store,'/').'/'.$m['storage_name'];if(!is_file($path)||is_link($path))dlFail(409,'No se encontraron metadatos');
 $j=json_decode((string)file_get_contents($path,false,null,0,16385),true);
 if(!is_array($j)||($j['drivecraft_type']??'')!=='local-video-package-v1')dlFail(409,'Paquete local inválido');
 return [$f,$m,$j];
}
function dlExtras(PDO $db,int $uid,int $package,int $video):array{
 $q=$db->prepare("SELECT id,name,storage_name,size FROM files WHERE owner_id=? AND parent_id=? AND is_dir=0 AND deleted_at IS NULL AND id<>? AND name<>'metadata.json' ORDER BY id");$q->execute([$uid,$package,$video]);return $q->fetchAll(PDO::FETCH_ASSOC);
}
$action=(string)($_POST['action']??$_GET['action']??'');
if(!in_array($action,['list','convert','settings','asset','trash','regenerate','edit','attach','convert_compatible','delete_attachment'],true))dlFail(400,'Acción no reconocida');
if($action==='asset'){
 $id=(int)($_GET['id']??0);$kind=(string)($_GET['kind']??'');if(!in_array($kind,['jpg','mp4','webm','webp'],true))dlFail(400,'Tipo inválido');
 $video=dlFile($pdo,$id,$uid);dlFolder($pdo,(int)$video['parent_id'],$uid);if(!dlVideo($video))dlFail(404,'No es video');
 // El propietario únicamente puede recuperar derivados de su propio archivo.
 $q=$pdo->prepare('SELECT state,source_storage FROM dc_local_video_previews WHERE video_file_id=? AND owner_id=?');$q->execute([$id,$uid]);$r=$q->fetch(PDO::FETCH_ASSOC);
 if(!$r||$r['state']!=='done'||$r['source_storage']!==$video['storage_name'])dlFail(404,'Vista previa no disponible');
 $path=rtrim($THUMBS_DIR,'/').'/dc-local-'.$id.'.'.$kind;
 if(!is_file($path)||is_link($path))dlFail(404,'Archivo derivado no encontrado');
 $mime = $kind==='jpg' ? 'image/jpeg' : ($kind==='webp' ? 'image/webp' : ($kind==='webm' ? 'video/webm' : 'video/mp4'));
  header('Content-Type: '.$mime);header('Content-Length: '.filesize($path));header('Accept-Ranges: none');readfile($path);exit;
}
header('Content-Type: application/json; charset=utf-8');
if($action==='settings'){
 if(!$isAdmin)dlFail(403,'Solo administradores');
 if($_SERVER['REQUEST_METHOD']==='POST'){
  $csrf=(string)($_POST['csrf']??'');if($csrf===''||!hash_equals((string)($_SESSION['dc_csrf']??''),$csrf))dlFail(403,'CSRF inválido');
  $seconds=(string)($_POST['seconds']??'');$audio=(string)($_POST['audio']??'');$format=(string)($_POST['format']??'webm');
  if(!preg_match('/^(?:[3-9]|[12][0-9]|30)$/D',$seconds)||!in_array($audio,['0','1'],true)||!in_array($format,['webm','mp4'],true))dlFail(422,'Duración, audio o formato inválidos');
  $current=dlConfig($pdo);
  $q=$pdo->prepare('INSERT INTO dc_app_settings(setting_key,setting_value) VALUES (?,?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)');
  $q->execute(['local_preview_seconds',$seconds]);$q->execute(['local_preview_audio',$audio]);$q->execute(['local_preview_format',$format]);
  $changed=((int)$current['seconds']!==(int)$seconds)||((((string)$current['audio']==='1')?'1':'0')!==$audio)||($current['format']!==$format);
  if($changed){
   // Regenere progresivamente, sin operaciones FFmpeg en la petición web.
   $pdo->exec("UPDATE dc_local_video_previews SET state='queued',updated_at=NOW() WHERE state='done'");
  }
 }
 echo json_encode(['success'=>true,'config'=>dlConfig($pdo)]);exit;
}
if($action==='list'){
 $folder=(int)($_GET['folder_id']??0);dlFolder($pdo,$folder,$uid);
 $q=$pdo->prepare("SELECT v.id AS video_id,v.name AS video_name,v.mime_type,v.parent_id,p.id AS package_id,p.name AS package_name,pr.state AS preview_state FROM files p JOIN files m ON m.parent_id=p.id AND m.owner_id=p.owner_id AND m.name='metadata.json' AND m.deleted_at IS NULL JOIN files v ON v.parent_id=p.id AND v.owner_id=p.owner_id AND v.is_dir=0 AND v.deleted_at IS NULL AND v.mime_type LIKE 'video/%' LEFT JOIN dc_local_video_previews pr ON pr.video_file_id=v.id WHERE p.parent_id=? AND p.owner_id=? AND p.is_dir=1 AND p.deleted_at IS NULL AND v.name NOT IN ('preview.mp4') ORDER BY p.id DESC LIMIT 200");
 $q->execute([$folder,$uid]);$items=[];
 foreach($q->fetchAll(PDO::FETCH_ASSOC) as $p){// Validar marcador del metadata.json, para no incluir carpetas ordinarias.
  $m=$pdo->prepare("SELECT storage_name,size FROM files WHERE parent_id=? AND owner_id=? AND name='metadata.json' AND deleted_at IS NULL");$m->execute([$p['package_id'],$uid]);$meta=$m->fetch(PDO::FETCH_ASSOC);
  if(!$meta||!dlValidStorage($meta['storage_name'])||(int)$meta['size']>16384)continue;
  $path=rtrim($STORAGE_DIR,'/').'/'.$meta['storage_name'];if(!is_file($path)||is_link($path))continue;
  $j=json_decode((string)file_get_contents($path,false,null,0,16385),true);if(($j['drivecraft_type']??'')!=='local-video-package-v1')continue;
  $p['title']=(string)($j['title']??'');$p['description']=(string)($j['description']??'');
  $extra=$pdo->prepare("SELECT id,name,size FROM files WHERE parent_id=? AND owner_id=? AND is_dir=0 AND deleted_at IS NULL AND id<>? AND name<>'metadata.json' ORDER BY id");
  $extra->execute([(int)$p['package_id'],$uid,(int)$p['video_id']]);$extras=$extra->fetchAll(PDO::FETCH_ASSOC);
  $p['attachments']=$extras;
  $previewBase=rtrim($THUMBS_DIR,'/').'/dc-local-'.(int)$p['video_id'];
  $availablePreviews=[];
  foreach(['webm','mp4'] as $ext){$candidate=$previewBase.'.'.$ext;if(is_file($candidate)&&!is_link($candidate))$availablePreviews[$ext]=filemtime($candidate);}
  arsort($availablePreviews);$p['preview_format']=array_key_first($availablePreviews);
  try{$cv=$pdo->prepare('SELECT state,message,conversion_details FROM dc_local_video_conversions WHERE video_file_id=? AND owner_id=?');$cv->execute([(int)$p['video_id'],$uid]);$p['conversion']=$cv->fetch(PDO::FETCH_ASSOC)?:null;if($p['conversion']){$details=json_decode((string)($p['conversion']['conversion_details']??''),true);$p['conversion']['details']=is_array($details)?$details:null;unset($p['conversion']['conversion_details']);}}catch(PDOException $ignored){$p['conversion']=null;}
  $items[]=$p;
 }
 // Archivos subidos normalmente en la carpeta y todavía sin empaquetar.
 $q=$pdo->prepare("SELECT id,name FROM files WHERE owner_id=? AND parent_id=? AND is_dir=0 AND mime_type LIKE 'video/%' AND deleted_at IS NULL ORDER BY id DESC LIMIT 100");$q->execute([$uid,$folder]);
 echo json_encode(['success'=>true,'items'=>$items,'unpacked'=>$q->fetchAll(PDO::FETCH_ASSOC),'preview_audio'=>dlConfig($pdo)['audio']]);exit;
}
if($_SERVER['REQUEST_METHOD']!=='POST')dlFail(405,'POST requerido');$csrf=(string)($_POST['csrf']??'');if(!$csrf||!hash_equals((string)($_SESSION['dc_csrf']??''),$csrf))dlFail(403,'CSRF inválido');
if($action==='regenerate'){
 if(!$isAdmin&&empty($u['can_upload']))dlFail(403,'No tienes permiso para organizar videos');
 $folder=(int)($_POST['folder_id']??0);$package=(int)($_POST['package_id']??0);$id=(int)($_POST['video_id']??0);
 dlFolder($pdo,$folder,$uid);
 $q=$pdo->prepare('SELECT id FROM files WHERE id=? AND owner_id=? AND parent_id=? AND is_dir=1 AND deleted_at IS NULL');
 $q->execute([$package,$uid,$folder]);if(!$q->fetchColumn())dlFail(404,'Paquete inexistente');
 $video=dlFile($pdo,$id,$uid);
 if((int)$video['parent_id']!==$package||!dlVideo($video)||!dlValidStorage($video['storage_name']))dlFail(409,'Video no pertenece al paquete');
 $q=$pdo->prepare("SELECT storage_name,size FROM files WHERE owner_id=? AND parent_id=? AND name='metadata.json' AND is_dir=0 AND deleted_at IS NULL LIMIT 1");
 $q->execute([$uid,$package]);$meta=$q->fetch(PDO::FETCH_ASSOC);
 if(!$meta||!dlValidStorage($meta['storage_name'])||(int)$meta['size']>16384)dlFail(409,'Metadatos del paquete inválidos');
 $path=rtrim($STORAGE_DIR,'/').'/'.$meta['storage_name'];
 if(!is_file($path)||is_link($path))dlFail(409,'Metadatos no disponibles');
 $info=json_decode((string)file_get_contents($path,false,null,0,16385),true);
 if(($info['drivecraft_type']??'')!=='local-video-package-v1')dlFail(409,'No es un paquete de video local');
 $source=rtrim($STORAGE_DIR,'/').'/'.$video['storage_name'];
 if(!is_file($source)||is_link($source))dlFail(409,'El video original no está disponible');
 $q=$pdo->prepare("INSERT INTO dc_local_video_previews(video_file_id,owner_id,source_storage,state,message,updated_at) VALUES (?,?,?,'queued',NULL,NOW()) ON DUPLICATE KEY UPDATE source_storage=IF(state='running',source_storage,VALUES(source_storage)),state=IF(state='running',state,'queued'),message=IF(state='running',message,NULL),updated_at=IF(state='running',updated_at,NOW())");
 $q->execute([$id,$uid,$video['storage_name']]);
 $q=$pdo->prepare('SELECT state FROM dc_local_video_previews WHERE video_file_id=? AND owner_id=?');$q->execute([$id,$uid]);
 $state=$q->fetchColumn();if($state==='running')dlFail(409,'La vista previa ya se está procesando');
 echo json_encode(['success'=>true,'state'=>$state]);exit;
}
if(in_array($action,['edit','attach','convert_compatible','delete_attachment'],true)){
 if(!$isAdmin&&empty($u['can_upload']))dlFail(403,'Sin permiso para modificar paquetes');
 $folder=(int)($_POST['folder_id']??0);$package=(int)($_POST['package_id']??0);$id=(int)($_POST['video_id']??0);
 [$video,$meta,$details]=dlPackage($pdo,$uid,$folder,$package,$id,$STORAGE_DIR);
 if($action==='edit'){
  $title=trim((string)($_POST['title']??''));$description=trim((string)($_POST['description']??''));
  if(mb_strlen($title)>240||mb_strlen($description)>4000)dlFail(422,'Título o descripción demasiado largos');
  $payload=json_encode(['drivecraft_type'=>'local-video-package-v1','title'=>$title,'description'=>$description],JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR);
  $storage=bin2hex(random_bytes(16)).'.json';$new=rtrim($STORAGE_DIR,'/').'/'.$storage;
  if(file_put_contents($new,$payload,LOCK_EX)!==strlen($payload)){@unlink($new);dlFail(507,'No se pudieron guardar metadatos');}
  try{$pdo->beginTransaction();$q=$pdo->prepare('UPDATE files SET storage_name=?,size=? WHERE id=? AND owner_id=? AND storage_name=?');$q->execute([$storage,strlen($payload),$meta['id'],$uid,$meta['storage_name']]);if($q->rowCount()!==1)throw new RuntimeException('Paquete cambiado');$pdo->commit();echo json_encode(['success'=>true]);exit;}
  catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();@unlink($new);dlFail(409,'No fue posible editar el paquete');}
 }
 if($action==='attach'){
  if(!isset($_FILES['attachment'])||$_FILES['attachment']['error']!==UPLOAD_ERR_OK||!is_uploaded_file($_FILES['attachment']['tmp_name']))dlFail(422,'No se recibió el adjunto');
  $upload=$_FILES['attachment'];$name=basename((string)$upload['name']);
  if(!$name||strlen($name)>240||in_array(strtolower($name),['metadata.json','video.url'],true)||str_contains($name,'\\')||preg_match('/[\x00-\x1f\x7f]/',$name))dlFail(422,'Nombre del archivo inválido');
  $size=(int)$upload['size'];$max=(int)($u['max_file_bytes']??0);if(!$isAdmin&&$max>0&&$size>$max)dlFail(413,'Archivo mayor que el límite');
  $quota=(int)($u['quota_bytes']??0);if(!$isAdmin&&$quota>0){$q=$pdo->prepare('SELECT COALESCE(SUM(size),0) FROM files WHERE owner_id=? AND is_dir=0');$q->execute([$uid]);if((float)$q->fetchColumn()+$size>$quota)dlFail(413,'Cuota insuficiente');}
  if((float)disk_free_space($STORAGE_DIR)<$size)dlFail(507,'Sin espacio disponible');
  $stored=bin2hex(random_bytes(20)).'.attachment';$dest=rtrim($STORAGE_DIR,'/').'/'.$stored;
  if(!move_uploaded_file($upload['tmp_name'],$dest))dlFail(507,'No fue posible guardar el adjunto');
  try{$mime=(string)(new finfo(FILEINFO_MIME_TYPE))->file($dest);$pdo->beginTransaction();
   $original=$name;$n=1;$q=$pdo->prepare('SELECT 1 FROM files WHERE parent_id=? AND owner_id=? AND name=? AND deleted_at IS NULL LIMIT 1');
   while(true){$q->execute([$package,$uid,$name]);if(!$q->fetchColumn())break;$ext=pathinfo($original,PATHINFO_EXTENSION);$stem=pathinfo($original,PATHINFO_FILENAME);$suffix=' ('.(++$n).')';$name=mb_substr($stem,0,240-strlen($suffix)-strlen($ext)-($ext!==''?1:0)).$suffix.($ext!==''?'.'.$ext:'');if($n>999)throw new RuntimeException('Demasiados adjuntos del mismo nombre');}
   $q=$pdo->prepare('INSERT INTO files(parent_id,name,is_dir,mime_type,size,storage_name,has_thumb,has_list,owner_id) VALUES (?,?,0,?,?,?,0,0,?)');$q->execute([$package,$name,$mime,$size,$stored,$uid]);$pdo->commit();echo json_encode(['success'=>true]);exit;
  }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();@unlink($dest);dlFail(409,'No fue posible guardar el adjunto');}
 }
 if($action==='delete_attachment'){
  if(!$isAdmin&&empty($u['can_delete']))dlFail(403,'Sin permiso para eliminar');
  $attachmentId=(int)($_POST['attachment_id']??0);$q=$pdo->prepare("UPDATE files SET deleted_at=NOW() WHERE id=? AND parent_id=? AND owner_id=? AND deleted_at IS NULL AND is_dir=0 AND id<>? AND name<>'metadata.json'");
  $q->execute([$attachmentId,$package,$uid,$id]);if($q->rowCount()!==1)dlFail(404,'Adjunto no encontrado');echo json_encode(['success'=>true]);exit;
 }
 if($action==='convert_compatible'){
  if(!$isAdmin && empty($u['can_convert_video']))dlFail(403,'Tu cuenta no tiene permiso para convertir videos');
  $setting=$pdo->prepare("SELECT setting_value FROM dc_app_settings WHERE setting_key='dc_local_video_max_mib'");$setting->execute();
  $localValue=(int)($setting->fetchColumn()?:100);
  $general=$pdo->prepare("SELECT setting_value FROM dc_app_settings WHERE setting_key='dc_media_max_mib'");$general->execute();
  $generalValue=(int)($general->fetchColumn()?:0);
  // Compatibilidad con versiones anteriores: el panel general podía guardar 600 y el local quedar en 100.
  // Una vez se edite cualquier panel, ambos registros se mantienen sincronizados.
  $maxMiB=max(1,min(10240,max($localValue,$generalValue)));
  $q=$pdo->prepare('SELECT size FROM files WHERE id=? AND owner_id=? AND deleted_at IS NULL AND is_dir=0');$q->execute([$id,$uid]);$bytes=$q->fetchColumn();
  if($bytes===false)dlFail(404,'Archivo no encontrado');
  if((int)$bytes>$maxMiB*1048576)dlFail(413,'El video supera el límite de conversión de '.$maxMiB.' MiB');
  $q=$pdo->prepare('SELECT storage_name FROM files WHERE id=? AND owner_id=?');$q->execute([$id,$uid]);$source=$q->fetchColumn();
  if(!dlValidStorage($source)||!is_file(rtrim($STORAGE_DIR,'/').'/'.$source))dlFail(404,'Video original ausente');
  // La tabla se prepara mediante el instalador CLI. No conceder permisos CREATE a una solicitud web.
  try{$pdo->beginTransaction();$q=$pdo->prepare("INSERT INTO dc_local_video_conversions(video_file_id,owner_id,source_storage,state,message,updated_at) VALUES (?,?,?,'queued',NULL,NOW()) ON DUPLICATE KEY UPDATE source_storage=IF(state='running',source_storage,VALUES(source_storage)),state=IF(state='running',state,'queued'),message=IF(state='running',message,NULL),updated_at=IF(state='running',updated_at,NOW())");$q->execute([$id,$uid,$source]);$pdo->commit();echo json_encode(['success'=>true]);exit;}
  catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();dlFail(503,'No existe la cola de conversiones. Revisa la instalación');}
 }
}
if($action==='trash'){
 if(!$isAdmin&&empty($u['can_delete']))dlFail(403,'No tienes permiso para eliminar');
 $folder=(int)($_POST['folder_id']??0);$package=(int)($_POST['package_id']??0);
 dlFolder($pdo,$folder,$uid);
 $q=$pdo->prepare('SELECT id FROM files WHERE id=? AND owner_id=? AND parent_id=? AND is_dir=1 AND deleted_at IS NULL');
 $q->execute([$package,$uid,$folder]);if(!$q->fetchColumn())dlFail(404,'Paquete inexistente');
 $q=$pdo->prepare("SELECT storage_name,size FROM files WHERE parent_id=? AND owner_id=? AND name='metadata.json' AND deleted_at IS NULL");
 $q->execute([$package,$uid]);$meta=$q->fetch(PDO::FETCH_ASSOC);
 if(!$meta||!dlValidStorage($meta['storage_name'])||(int)$meta['size']>16384)dlFail(409,'No es un paquete de video local');
 $path=rtrim($STORAGE_DIR,'/').'/'.$meta['storage_name'];
 if(!is_file($path)||is_link($path))dlFail(409,'Metadatos no disponibles');
 $info=json_decode((string)file_get_contents($path,false,null,0,16385),true);
 if(($info['drivecraft_type']??'')!=='local-video-package-v1')dlFail(409,'Paquete de video inválido');
 $q=$pdo->prepare('UPDATE files SET deleted_at=NOW() WHERE id=? AND owner_id=? AND parent_id=? AND deleted_at IS NULL');
 $q->execute([$package,$uid,$folder]);if(!$q->rowCount())dlFail(409,'No se pudo enviar a papelera');
 // Pausar tareas relacionadas; el worker valida de nuevo antes del commit.
 $q=$pdo->prepare("UPDATE dc_local_video_conversions c JOIN files v ON v.id=c.video_file_id AND v.owner_id=c.owner_id SET c.state='paused',c.message='Paquete enviado a Papelera',c.updated_at=NOW() WHERE v.parent_id=? AND c.owner_id=? AND c.state IN ('queued','running')");$q->execute([$package,$uid]);
 echo json_encode(['success'=>true]);exit;
}
if(!$isAdmin&&empty($u['can_upload']))dlFail(403,'No puedes organizar videos');
$id=(int)($_POST['file_id']??0);$folder=(int)($_POST['folder_id']??0);dlFolder($pdo,$folder,$uid);
$mode=$pdo->prepare('SELECT 1 FROM dc_youtube_folders WHERE folder_id=? AND owner_id=?');$mode->execute([$folder,$uid]);if(!$mode->fetchColumn())dlFail(409,'La carpeta debe estar configurada como YouTube Links');
$f=dlFile($pdo,$id,$uid);
if((int)$f['parent_id']!==$folder)dlFail(409,'El video ya no está en esta carpeta');
if(!dlVideo($f)||!dlValidStorage($f['storage_name']))dlFail(409,'El archivo copiado no fue reconocido como video válido. Revisa su tipo MIME y archivo físico');
$source=rtrim($STORAGE_DIR,'/').'/'.$f['storage_name'];if(!is_file($source)||is_link($source))dlFail(404,'No se encontró el video físico');
$title=trim((string)($_POST['title']??''));$description=trim((string)($_POST['description']??''));if(mb_strlen($title)>240||mb_strlen($description)>4000)dlFail(422,'Metadatos demasiado largos');
$name='Video '.mb_substr($title?:pathinfo($f['name'],PATHINFO_FILENAME),0,70);$name=str_replace(['/', chr(92)], '_', $name);$name=preg_replace('/[\x00-\x1F]/', '_', $name);$name=trim($name,' .')?:'Video local';
$lock='dc-local-pack-'.$uid;$ls=$pdo->prepare('SELECT GET_LOCK(?,15)');$ls->execute([$lock]);if((int)$ls->fetchColumn()!==1)dlFail(409,'Biblioteca ocupada');
$physical=null;
try{
 $pdo->beginTransaction();$q=$pdo->prepare('SELECT id,parent_id FROM files WHERE id=? AND owner_id=? FOR UPDATE');$q->execute([$id,$uid]);$check=$q->fetch(PDO::FETCH_ASSOC);if(!$check||(int)$check['parent_id']!==$folder)throw new RuntimeException('El video cambió de carpeta');
 $base=$name;$n=1;$q=$pdo->prepare('SELECT COUNT(*) FROM files WHERE owner_id=? AND parent_id=? AND name=? AND deleted_at IS NULL');
 do{$q->execute([$uid,$folder,$name]);if(!(int)$q->fetchColumn())break;$name=$base.' ('.(++$n).')';}while($n<100);
 $q=$pdo->prepare("INSERT INTO files(parent_id,name,is_dir,mime_type,owner_id) VALUES (?,?,1,'directory',?)");$q->execute([$folder,$name,$uid]);$pid=(int)$pdo->lastInsertId();
 $pdo->prepare('UPDATE files SET parent_id=? WHERE id=? AND owner_id=?')->execute([$pid,$id,$uid]);
 $payload=json_encode(['drivecraft_type'=>'local-video-package-v1','title'=>$title,'description'=>$description],JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR);
 $storage=bin2hex(random_bytes(16)).'.json';$physical=rtrim($STORAGE_DIR,'/').'/'.$storage;
 if(file_put_contents($physical,$payload,LOCK_EX)!==strlen($payload))throw new RuntimeException('No se pudo guardar metadata.json');
 $q=$pdo->prepare("INSERT INTO files(parent_id,name,is_dir,mime_type,size,storage_name,has_thumb,has_list,owner_id) VALUES (?,'metadata.json',0,'application/json',?,?,0,0,?)");$q->execute([$pid,strlen($payload),$storage,$uid]);
 $q=$pdo->prepare("INSERT INTO dc_local_video_previews(video_file_id,owner_id,source_storage,state,updated_at) VALUES (?,?,?,'queued',NOW()) ON DUPLICATE KEY UPDATE source_storage=VALUES(source_storage),state='queued',updated_at=NOW()");$q->execute([$id,$uid,$f['storage_name']]);
 $pdo->commit();echo json_encode(['success'=>true,'package_id'=>$pid]);
}catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();if($physical&&is_file($physical))@unlink($physical);dlFail(500,'No se pudo crear paquete: '.$e->getMessage());}
finally{$pdo->prepare('SELECT RELEASE_LOCK(?)')->execute([$lock]);}
