<?php
/* Drive-Craft v20.10.65 — Colecciones de videos existentes, sin copiar bytes. */
require __DIR__.'/config.php'; require_once __DIR__.'/auth.php'; require_once __DIR__.'/dc_activity_85m_hf2.php';
header('Cache-Control: private, no-store');
header('X-Content-Type-Options: nosniff');
function vcFail(int $status,string $message):never {http_response_code($status);header('Content-Type: application/json; charset=utf-8');echo json_encode(['error'=>$message]);exit;}
$u=dcRequireUser();$uid=(int)$u['id'];$action=(string)($_REQUEST['action']??'list');
function vcFolder(PDO $pdo,int $uid,int $folder):void{
 if($folder<1)vcFail(400,'Carpeta inválida');
 $q=$pdo->prepare('SELECT 1 FROM files WHERE id=? AND owner_id=? AND is_dir=1 AND deleted_at IS NULL');$q->execute([$folder,$uid]);if(!$q->fetchColumn())vcFail(404,'Carpeta inaccesible');
}
function vcAvailable(PDO $pdo,int $uid,int $folder):array{
 $q=$pdo->prepare("SELECT id AS video_id,name AS video_name,mime_type FROM files WHERE parent_id=? AND owner_id=? AND is_dir=0 AND deleted_at IS NULL AND (mime_type LIKE 'video/%' OR LOWER(SUBSTRING_INDEX(name,'.',-1)) IN ('mp4','mkv','webm','mov','avi','m4v','mpeg')) ORDER BY name,id");
 $q->execute([$folder,$uid]);$direct=$q->fetchAll(PDO::FETCH_ASSOC);
 $q=$pdo->prepare("SELECT p.id AS package_id,v.id AS video_id,v.name AS video_name,v.mime_type FROM files p JOIN files m ON m.parent_id=p.id AND m.owner_id=p.owner_id AND m.name='metadata.json' AND m.deleted_at IS NULL JOIN files v ON v.parent_id=p.id AND v.owner_id=p.owner_id AND v.is_dir=0 AND v.deleted_at IS NULL AND (v.mime_type LIKE 'video/%' OR LOWER(SUBSTRING_INDEX(v.name,'.',-1)) IN ('mp4','mkv','webm','mov','avi','m4v','mpeg')) WHERE p.parent_id=? AND p.owner_id=? AND p.is_dir=1 AND p.deleted_at IS NULL ORDER BY p.name,v.name");
 $q->execute([$folder,$uid]);$packed=$q->fetchAll(PDO::FETCH_ASSOC);
 return array_merge($direct,$packed);
}
function vcValidateChildren(PDO $pdo,int $uid,int $folder):void{
 $q=$pdo->prepare("SELECT p.id FROM files p WHERE p.parent_id=? AND p.owner_id=? AND p.is_dir=1 AND p.deleted_at IS NULL AND NOT EXISTS (SELECT 1 FROM files m WHERE m.parent_id=p.id AND m.owner_id=p.owner_id AND m.name='metadata.json' AND m.deleted_at IS NULL) LIMIT 1");
 $q->execute([$folder,$uid]);if($q->fetchColumn())vcFail(409,'La carpeta contiene subcarpetas. Retíralas antes de convertirla en colección.');
}
function vcCoverName(string $s):bool{return (bool)preg_match('/^dc-vc65-[a-f0-9]{40}\.(?:jpg|jpeg|png|webp)$/D',$s);}
function vcCoverDelete(string $dir,string $stored):void{
 if(!vcCoverName($stored))return;
 $base=rtrim($dir,'/').'/'.$stored;
 if(is_file($base)&&!is_link($base))@unlink($base);
 $thumb=preg_replace('/\.(?:jpg|jpeg|png|webp)$/D','.thumb.webp',$base);
 if($thumb!==null&&is_file($thumb)&&!is_link($thumb))@unlink($thumb);
}
function vcCollection(PDO $pdo,int $uid,int $id):array{
 $q=$pdo->prepare('SELECT * FROM dc_video_collections_65 WHERE id=? AND owner_id=?');$q->execute([$id,$uid]);$r=$q->fetch(PDO::FETCH_ASSOC);if(!$r)vcFail(404,'Colección inexistente');return $r;
}
/* DC85c: reconciliar colecciones con sus videos activos sin copiar ni borrar archivos. */
function vcSyncEpisodes(PDO $pdo,int $uid,array $collection):void{
 $cid=(int)$collection['id'];$folder=(int)$collection['folder_id'];
 $available=vcAvailable($pdo,$uid,$folder);
 // La colección de carpeta directa usa sus propios videos; no mezclar hijos de otros paquetes.
 $available=array_values(array_filter($available,fn($v)=>!isset($v['package_id'])));
 $byId=[];$byName=[];
 foreach($available as $v){$id=(int)$v['video_id'];$byId[$id]=$v;$key=mb_strtolower((string)$v['video_name'],'UTF-8');$byName[$key][]=$id;}
 $q=$pdo->prepare('SELECT i.video_id,f.name FROM dc_video_collection_items_65 i LEFT JOIN files f ON f.id=i.video_id AND f.owner_id=? WHERE i.collection_id=? ORDER BY i.sort_order,i.id');
 $q->execute([$uid,$cid]);$prior=$q->fetchAll(PDO::FETCH_ASSOC);
 $ids=[];$used=[];$unresolved=false;
 foreach($prior as $r){$old=(int)$r['video_id'];$new=0;
   if(isset($byId[$old]))$new=$old;
   elseif($r['name']!==null){$key=mb_strtolower((string)$r['name'],'UTF-8');if(count($byName[$key]??[])===1)$new=$byName[$key][0];}
   if($new&&!isset($used[$new])){$ids[]=$new;$used[$new]=true;}
   elseif(!$new)$unresolved=true;
 }
 // Evitar duplicar cualquier entrada y ordenar naturalmente los episodios descubiertos.
 $extra=[];foreach($available as $v){$id=(int)$v['video_id'];if(!isset($used[$id]))$extra[]=$v;}
 usort($extra,fn($a,$b)=>strnatcasecmp((string)$a['video_name'],(string)$b['video_name']));
 foreach($extra as $v){$id=(int)$v['video_id'];$ids[]=$id;$used[$id]=true;}
 $oldIds=array_map(fn($r)=>(int)$r['video_id'],$prior);
 if($ids===$oldIds || !$ids)return;
 // Si hay una referencia irresoluble, no la borraríamos del catálogo: permitir únicamente
 // sustituciones inequívocas y altas, dejando referencias antiguas hasta revisión manual.
 if($unresolved)return;
 try{$pdo->beginTransaction();
  $pdo->prepare('DELETE FROM dc_video_collection_items_65 WHERE collection_id=?')->execute([$cid]);
  $insert=$pdo->prepare('INSERT INTO dc_video_collection_items_65(collection_id,video_id,sort_order) VALUES (?,?,?)');
  foreach($ids as $n=>$videoId)$insert->execute([$cid,$videoId,$n]);
  $pdo->commit();
 }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();error_log('DC85c vcSyncEpisodes: '.$e->getMessage());}
}
if($action==='cover'){
 $id=(int)($_GET['id']??0);$c=vcCollection($pdo,$uid,$id);vcFolder($pdo,$uid,(int)$c['folder_id']);
 $stored=(string)($c['cover_storage']??'');if(!vcCoverName($stored))vcFail(404,'Sin portada');
 $path=rtrim($STORAGE_DIR,'/').'/'.$stored;
 $thumb=preg_replace('/\.(?:jpg|jpeg|png|webp)$/D','.thumb.webp',$path);
 if(($_GET['variant']??'')==='thumb'&&$thumb!==null&&is_file($thumb)&&!is_link($thumb))$path=$thumb;
 if(!is_file($path)||is_link($path))vcFail(404,'Portada no disponible');
 $ext=strtolower(pathinfo($path,PATHINFO_EXTENSION));
 $mime=['jpg'=>'image/jpeg','jpeg'=>'image/jpeg','png'=>'image/png','webp'=>'image/webp'][$ext]??'application/octet-stream';
 header('Content-Type: '.$mime);header('Content-Length: '.filesize($path));readfile($path);exit;
}
header('Content-Type: application/json; charset=utf-8');
if($action==='parent'){
 $parent=(int)($_GET['parent_id']??0);
 if($parent<0)vcFail(400,'Carpeta inválida');
 if($parent>0)vcFolder($pdo,$uid,$parent);
 $q=$pdo->prepare('SELECT c.id,c.folder_id,c.title,c.description,c.cover_storage,c.cover_layout,c.cover_position_x,c.cover_position_y,c.cover_scale FROM dc_video_collections_65 c JOIN files f ON f.id=c.folder_id AND f.owner_id=c.owner_id AND f.is_dir=1 AND f.deleted_at IS NULL WHERE c.owner_id=? AND f.parent_id=? ORDER BY f.name,c.id');
 $q->execute([$uid,$parent]);$items=$q->fetchAll(PDO::FETCH_ASSOC);
 $ep=$pdo->prepare("SELECT v.id AS video_id,v.name AS video_name,v.mime_type FROM dc_video_collection_items_65 i JOIN files v ON v.id=i.video_id AND v.owner_id=? AND v.deleted_at IS NULL AND v.is_dir=0 WHERE i.collection_id=? ORDER BY i.sort_order,i.id");
 foreach($items as &$c){vcSyncEpisodes($pdo,$uid,$c);$ep->execute([$uid,$c['id']]);$c['episodes']=$ep->fetchAll(PDO::FETCH_ASSOC);$c['has_cover']=!empty($c['cover_storage']);unset($c['cover_storage']);}unset($c);
 echo json_encode(['success'=>true,'items'=>$items]);exit;
}
if($action==='status'){
 $folder=(int)($_GET['folder_id']??0);vcFolder($pdo,$uid,$folder);
 $q=$pdo->prepare('SELECT id,title,description,cover_storage,cover_layout,cover_position_x,cover_position_y,cover_scale FROM dc_video_collections_65 WHERE owner_id=? AND folder_id=? ORDER BY id LIMIT 1');$q->execute([$uid,$folder]);$c=$q->fetch(PDO::FETCH_ASSOC);
 echo json_encode(['success'=>true,'collection'=>$c?:null]);exit;
}
if($action==='list'){
 $folder=(int)($_GET['folder_id']??0);vcFolder($pdo,$uid,$folder);
 $available=vcAvailable($pdo,$uid,$folder);$byId=[];foreach($available as $a)$byId[(int)$a['video_id']]=$a;
 $q=$pdo->prepare('SELECT id,folder_id,title,description,cover_storage,cover_layout,cover_position_x,cover_position_y,cover_scale FROM dc_video_collections_65 WHERE owner_id=? AND folder_id=? ORDER BY id DESC');$q->execute([$uid,$folder]);$list=$q->fetchAll(PDO::FETCH_ASSOC);
 $s=$pdo->prepare('SELECT video_id FROM dc_video_collection_items_65 WHERE collection_id=? ORDER BY sort_order,id');
 foreach($list as &$c){vcSyncEpisodes($pdo,$uid,$c);$s->execute([$c['id']]);$c['episodes']=[];foreach($s->fetchAll(PDO::FETCH_COLUMN) as $id){$id=(int)$id;if(isset($byId[$id]))$c['episodes'][]=$byId[$id];}$c['has_cover']=!empty($c['cover_storage']);unset($c['cover_storage']);}unset($c);
 echo json_encode(['success'=>true,'items'=>$list,'available'=>array_values($available)]);exit;
}
if($action==='cover_library'){
 $id=(int)($_GET['id']??0);$c=vcCollection($pdo,$uid,$id);vcFolder($pdo,$uid,(int)$c['folder_id']);
 $q=$pdo->prepare("SELECT id,name,parent_id,mime_type FROM files WHERE owner_id=? AND is_dir=0 AND deleted_at IS NULL AND (mime_type IN ('image/jpeg','image/png','image/webp') OR LOWER(SUBSTRING_INDEX(name,'.',-1)) IN ('jpg','jpeg','png','webp')) ORDER BY id DESC LIMIT 300");
 $q->execute([$uid]);echo json_encode(['success'=>true,'items'=>$q->fetchAll(PDO::FETCH_ASSOC)]);exit;
}
if($_SERVER['REQUEST_METHOD']!=='POST')vcFail(405,'POST requerido');
$csrf=(string)($_POST['csrf']??'');if(!$csrf||!hash_equals((string)($_SESSION['dc_csrf']??''),$csrf))vcFail(403,'CSRF inválido');
if(empty($u['can_upload']) && ($u['role']??'')!=='admin')vcFail(403,'No tienes permiso para organizar archivos');
if($action==='convert_folder'){
 $folder=(int)($_POST['folder_id']??0);vcFolder($pdo,$uid,$folder);
 $p=$pdo->prepare('SELECT parent_id FROM files WHERE id=? AND owner_id=? AND is_dir=1 AND deleted_at IS NULL');$p->execute([$folder,$uid]);$parent=(int)$p->fetchColumn();
 $p=$pdo->prepare('SELECT 1 FROM dc_youtube_folders WHERE folder_id=? AND owner_id=? LIMIT 1');$p->execute([$parent,$uid]);
 if($parent<1 || !$p->fetchColumn())vcFail(409,'Solo se permiten colecciones en carpetas hijas directas de YouTube Links');
 require_once __DIR__.'/dc_special_modes_85m.php';if(dc85mConflict($pdo,$uid,$folder,'video'))vcFail(409,'Desactiva primero la clasificación actual de la carpeta');vcValidateChildren($pdo,$uid,$folder);
 $available=vcAvailable($pdo,$uid,$folder);if(!$available)vcFail(409,'Esta carpeta no contiene videos locales');
 $q=$pdo->prepare('SELECT id FROM dc_video_collections_65 WHERE owner_id=? AND folder_id=? LIMIT 1');$q->execute([$uid,$folder]);if($q->fetchColumn())vcFail(409,'Esta carpeta ya es una colección');
 $q=$pdo->prepare('SELECT name FROM files WHERE id=? AND owner_id=?');$q->execute([$folder,$uid]);$title=(string)$q->fetchColumn();
 try{$pdo->beginTransaction();$q=$pdo->prepare('INSERT INTO dc_video_collections_65(owner_id,folder_id,title,description) VALUES (?,?,?,?)');$q->execute([$uid,$folder,mb_substr($title,0,180),'']);$id=(int)$pdo->lastInsertId();$q=$pdo->prepare('INSERT INTO dc_video_collection_items_65(collection_id,video_id,sort_order) VALUES (?,?,?)');foreach($available as $i=>$v)$q->execute([$id,(int)$v['video_id'],$i]);$pdo->commit();dcActivity85m($pdo,$uid,$folder,'special');echo json_encode(['success'=>true,'id'=>$id]);exit;}catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();vcFail(500,'No se pudo convertir la carpeta');}
}
if($action==='disable_folder'){
 $folder=(int)($_POST['folder_id']??0);vcFolder($pdo,$uid,$folder);
 $q=$pdo->prepare('SELECT id,cover_storage FROM dc_video_collections_65 WHERE owner_id=? AND folder_id=?');$q->execute([$uid,$folder]);$c=$q->fetch(PDO::FETCH_ASSOC);if(!$c)vcFail(404,'La carpeta no es una colección');
 $pdo->prepare('DELETE FROM dc_video_collections_65 WHERE id=? AND owner_id=?')->execute([$c['id'],$uid]);
 $old=(string)($c['cover_storage']??'');vcCoverDelete($STORAGE_DIR,$old);
 dcActivity85m($pdo,$uid,$folder,'special');
 echo json_encode(['success'=>true]);exit;
}
if($action==='save'){
 $id=(int)($_POST['id']??0);$folder=(int)($_POST['folder_id']??0);vcFolder($pdo,$uid,$folder);
 $title=trim((string)($_POST['title']??''));$description=trim((string)($_POST['description']??''));
 if($title===''||mb_strlen($title)>180||mb_strlen($description)>2000)vcFail(422,'Título o descripción inválidos');
 vcValidateChildren($pdo,$uid,$folder);
 $raw=json_decode((string)($_POST['episodes']??'[]'),true);
 if(!is_array($raw)||count($raw)<1||count($raw)>150)vcFail(422,'Selecciona entre 1 y 150 episodios');
 $ids=[];foreach($raw as $n){if(!is_int($n)||$n<1||in_array($n,$ids,true))vcFail(422,'Episodios inválidos o repetidos');$ids[]=$n;}
 $available=vcAvailable($pdo,$uid,$folder);$allowed=array_fill_keys(array_map(fn($r)=>(int)$r['video_id'],$available),true);
 foreach($ids as $n)if(!isset($allowed[$n]))vcFail(409,'Un episodio no pertenece a esta carpeta');
 try{$pdo->beginTransaction();if($id){$c=vcCollection($pdo,$uid,$id);if((int)$c['folder_id']!==$folder)vcFail(409,'Colección movida');$q=$pdo->prepare('UPDATE dc_video_collections_65 SET title=?,description=? WHERE id=? AND owner_id=?');$q->execute([$title,$description,$id,$uid]);$pdo->prepare('DELETE FROM dc_video_collection_items_65 WHERE collection_id=?')->execute([$id]);}
 else{$q=$pdo->prepare('INSERT INTO dc_video_collections_65(owner_id,folder_id,title,description) VALUES (?,?,?,?)');$q->execute([$uid,$folder,$title,$description]);$id=(int)$pdo->lastInsertId();}
 $q=$pdo->prepare('INSERT INTO dc_video_collection_items_65(collection_id,video_id,sort_order) VALUES (?,?,?)');foreach($ids as $i=>$n)$q->execute([$id,$n,$i]);$pdo->commit();echo json_encode(['success'=>true,'id'=>$id]);exit;}
 catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();vcFail(500,'No se pudo guardar colección');}
}
if($action==='set_cover_layout'){
 $id=(int)($_POST['id']??0);$c=vcCollection($pdo,$uid,$id);vcFolder($pdo,$uid,(int)$c['folder_id']);
 $layout=(string)($_POST['layout']??'16:9');
 if(!in_array($layout,['16:9','2:3','auto'],true))vcFail(422,'Formato de portada no válido');
 $x=filter_var($_POST['x']??null,FILTER_VALIDATE_FLOAT);$y=filter_var($_POST['y']??null,FILTER_VALIDATE_FLOAT);
 $scale=filter_var($_POST['scale']??'100',FILTER_VALIDATE_FLOAT);
 if($x===false||$y===false||$x<0||$x>100||$y<0||$y>100)vcFail(422,'Encuadre fuera de rango');
 if($scale===false||$scale<25||$scale>250)vcFail(422,'Zoom fuera de rango');
 $q=$pdo->prepare('UPDATE dc_video_collections_65 SET cover_layout=?,cover_position_x=?,cover_position_y=?,cover_scale=? WHERE id=? AND owner_id=?');
 $q->execute([$layout,(int)round($x),(int)round($y),(int)round($scale),$id,$uid]);
 echo json_encode(['success'=>true]);exit;
}
if($action==='cover_from_library'){
 $id=(int)($_POST['id']??0);$fileId=(int)($_POST['file_id']??0);$c=vcCollection($pdo,$uid,$id);vcFolder($pdo,$uid,(int)$c['folder_id']);
 $q=$pdo->prepare('SELECT id,parent_id,mime_type,storage_name,size FROM files WHERE id=? AND owner_id=? AND is_dir=0 AND deleted_at IS NULL');$q->execute([$fileId,$uid]);$f=$q->fetch(PDO::FETCH_ASSOC);if(!$f)vcFail(404,'Imagen inexistente o sin autorización');
 // No confiar en el MIME de la base de datos; verificar los bytes reales.
 $storage=(string)$f['storage_name'];if(!preg_match('/^[A-Za-z0-9_.-]{1,255}$/D',$storage))vcFail(422,'Archivo inválido');
 $original=rtrim($STORAGE_DIR,'/').'/'.$storage;if(!is_file($original)||is_link($original))vcFail(404,'Imagen no disponible');
 $info=@getimagesize($original);if(!$info||!in_array($info[2],[IMAGETYPE_JPEG,IMAGETYPE_PNG,IMAGETYPE_WEBP],true)||$info[0]>8000||$info[1]>8000||filesize($original)>20*1024*1024)vcFail(422,'Imagen no admitida (máximo 20 MiB y 8000 px)');
 $ext=[IMAGETYPE_JPEG=>'jpg',IMAGETYPE_PNG=>'png',IMAGETYPE_WEBP=>'webp'][$info[2]];
 $name='dc-vc65-'.bin2hex(random_bytes(20)).'.'.$ext;$path=rtrim($STORAGE_DIR,'/').'/'.$name;
 // Hardlink preserva bytes y evita duplicados; copia como alternativa entre dispositivos.
 if(!@link($original,$path)&&!@copy($original,$path))vcFail(507,'No se pudo preparar la portada');
 @chmod($path,0664);
 try{$q=$pdo->prepare('UPDATE dc_video_collections_65 SET cover_storage=?,cover_position_x=50,cover_position_y=50,cover_scale=100 WHERE id=? AND owner_id=?');$q->execute([$name,$id,$uid]);}
 catch(Throwable $e){vcCoverDelete($STORAGE_DIR,$name);vcFail(500,'No se pudo asignar la portada');}
 vcCoverDelete($STORAGE_DIR,(string)($c['cover_storage']??''));echo json_encode(['success'=>true]);exit;
}
if($action==='upload_cover'){
 $id=(int)($_POST['id']??0);$c=vcCollection($pdo,$uid,$id);vcFolder($pdo,$uid,(int)$c['folder_id']);
 $file=$_FILES['cover']??null;if(!$file||$file['error']!==UPLOAD_ERR_OK||$file['size']>5242880||$file['size']<1)vcFail(422,'Portada inválida (máximo 5 MiB)');
 $src=$file['tmp_name'];if(!is_uploaded_file($src))vcFail(422,'Subida inválida');
 $info=@getimagesize($src);if(!$info||!in_array($info[2],[IMAGETYPE_JPEG,IMAGETYPE_PNG,IMAGETYPE_WEBP],true)||$info[0]>8000||$info[1]>8000)vcFail(422,'Imagen no admitida');
 // Conservar bytes originales; nunca recomprimir ni cambiar el encuadre al guardar.
 $ext=[IMAGETYPE_JPEG=>'jpg',IMAGETYPE_PNG=>'png',IMAGETYPE_WEBP=>'webp'][$info[2]];
 $name='dc-vc65-'.bin2hex(random_bytes(20)).'.'.$ext;
 $path=rtrim($STORAGE_DIR,'/').'/'.$name;
 if(!move_uploaded_file($src,$path))vcFail(500,'No se pudo conservar la portada original');
 @chmod($path,0664);
 // Miniatura WebP persistente; nunca sustituye el original. Fallar aqui no bloquea la subida.
 $thumb=preg_replace('/\.(?:jpg|png|webp)$/D','.thumb.webp',$path);
 if($thumb!==null&&is_executable('/usr/bin/convert')){
  $cmd='/usr/bin/convert '.escapeshellarg($path).'[0] -auto-orient -thumbnail '.escapeshellarg('1200x1200>').' -strip -quality 88 '.escapeshellarg('webp:'.$thumb).' 2>/dev/null';
  // No ejecutar el shell bajo nombres proporcionados por el usuario.
  @exec($cmd,$ignored,$status);
  if($status!==0||!is_file($thumb)||filesize($thumb)<1){if(is_file($thumb))@unlink($thumb);}else @chmod($thumb,0664);
 }
 try{
  $q=$pdo->prepare('UPDATE dc_video_collections_65 SET cover_storage=?,cover_position_x=50,cover_position_y=50,cover_scale=100 WHERE id=? AND owner_id=?');
  $q->execute([$name,$id,$uid]);
 }catch(Throwable $e){vcCoverDelete($STORAGE_DIR,$name);vcFail(500,'No se pudo registrar la portada');}
 $old=(string)($c['cover_storage']??'');vcCoverDelete($STORAGE_DIR,$old);
 echo json_encode(['success'=>true]);exit;
}
if($action==='delete'){
 $id=(int)($_POST['id']??0);$c=vcCollection($pdo,$uid,$id);vcFolder($pdo,$uid,(int)$c['folder_id']);
 $pdo->prepare('DELETE FROM dc_video_collections_65 WHERE id=? AND owner_id=?')->execute([$id,$uid]);
 $old=(string)($c['cover_storage']??'');vcCoverDelete($STORAGE_DIR,$old);
 echo json_encode(['success'=>true]);exit;
}
vcFail(400,'Acción desconocida');
