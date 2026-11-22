<?php
/* Drive-Craft v20.10.81 — Biblioteca musical. */
require __DIR__.'/config.php'; require_once __DIR__.'/auth.php'; require_once __DIR__.'/dc_activity_85m_hf2.php';
header('Cache-Control: private, no-store');header('X-Content-Type-Options: nosniff');
$u=dcRequireUser();$uid=(int)$u['id'];$action=(string)($_REQUEST['action']??'');
function muFail(int $status,string $text):never {http_response_code($status);header('Content-Type: application/json; charset=utf-8');echo json_encode(['error'=>$text],JSON_UNESCAPED_UNICODE);exit;}
function muFolder(PDO $pdo,int $uid,int $id):array{
 if($id<1)muFail(400,'Carpeta inválida');
 $q=$pdo->prepare('SELECT id,parent_id,name FROM files WHERE id=? AND owner_id=? AND is_dir=1 AND deleted_at IS NULL');$q->execute([$id,$uid]);$f=$q->fetch(PDO::FETCH_ASSOC);if(!$f)muFail(404,'Carpeta inexistente');return $f;
}
function muLibrary(PDO $pdo,int $uid,int $id):bool{
 $q=$pdo->prepare('SELECT 1 FROM dc_music_libraries_81 WHERE folder_id=? AND owner_id=?');$q->execute([$id,$uid]);return (bool)$q->fetchColumn();
}
function muCollectionEnabled85mHF3(PDO $pdo,int $uid,int $folder):bool{
 $q=$pdo->prepare('SELECT enabled FROM dc_music_collection_modes_hf3 WHERE owner_id=? AND folder_id=? LIMIT 1');
 $q->execute([$uid,$folder]);$v=$q->fetchColumn();return $v===false || (int)$v===1;
}
function muAudioCondition():string{return "(mime_type LIKE 'audio/%' OR LOWER(SUBSTRING_INDEX(name,'.',-1)) IN ('mp3','m4a','aac','ogg','oga','opus','wav','flac','weba'))";}
function muAlbums(PDO $pdo,int $uid,int $library):array{
 $q=$pdo->prepare('SELECT id,name FROM files WHERE parent_id=? AND owner_id=? AND deleted_at IS NULL AND is_dir=1 ORDER BY name,id');$q->execute([$library,$uid]);$albums=[];
 $tracks=$pdo->prepare('SELECT id,name,mime_type FROM files WHERE parent_id=? AND owner_id=? AND deleted_at IS NULL AND is_dir=0 AND '.muAudioCondition().' ORDER BY name,id LIMIT 500');
 $covers=$pdo->prepare("SELECT id,name FROM files WHERE parent_id=? AND owner_id=? AND deleted_at IS NULL AND is_dir=0 AND (mime_type IN ('image/jpeg','image/png','image/webp') OR LOWER(SUBSTRING_INDEX(name,'.',-1)) IN ('jpg','jpeg','png','webp')) ORDER BY CASE WHEN LOWER(name) REGEXP '^(cover|folder|front|portada)' THEN 0 ELSE 1 END,name LIMIT 1");
 $custom=$pdo->prepare('SELECT image_id FROM dc_music_covers_82 WHERE owner_id=? AND album_folder_id=?');
 $progress=$pdo->prepare('SELECT track_id,position_seconds FROM dc_music_progress_81 WHERE user_id=? AND album_folder_id=?');
 foreach($q->fetchAll(PDO::FETCH_ASSOC) as $f){
  $id=(int)$f['id'];$tracks->execute([$id,$uid]);$audio=$tracks->fetchAll(PDO::FETCH_ASSOC);if(!$audio || !muCollectionEnabled85mHF3($pdo,$uid,$id))continue;
  $covers->execute([$id,$uid]);$cover=$covers->fetch(PDO::FETCH_ASSOC)?:null;$custom->execute([$uid,$id]);$customId=(int)($custom->fetchColumn()?:0);if($customId){$valid=$pdo->prepare("SELECT id FROM files WHERE id=? AND owner_id=? AND deleted_at IS NULL AND is_dir=0 AND (mime_type IN ('image/jpeg','image/png','image/webp') OR LOWER(SUBSTRING_INDEX(name,'.',-1)) IN ('jpg','jpeg','png','webp'))");$valid->execute([$customId,$uid]);if($valid->fetchColumn())$cover=['id'=>$customId];}
  $progress->execute([$uid,$id]);$p=$progress->fetch(PDO::FETCH_ASSOC)?:null;
  $albums[]=['folder_id'=>$id,'title'=>$f['name'],'tracks'=>$audio,'cover_id'=>$cover?(int)$cover['id']:null,'progress'=>$p];
 }
 // Only real child folders form album cards. Loose audio stays in the file browser.
 return $albums;
}
header('Content-Type: application/json; charset=utf-8');
if($action==='library_ids'){
 $q=$pdo->prepare('SELECT folder_id FROM dc_music_libraries_81 WHERE owner_id=?');$q->execute([$uid]);echo json_encode(['success'=>true,'ids'=>array_map('intval',$q->fetchAll(PDO::FETCH_COLUMN))]);exit;
}
if($action==='status'){
 $id=(int)($_GET['folder_id']??0);muFolder($pdo,$uid,$id);echo json_encode(['success'=>true,'enabled'=>muLibrary($pdo,$uid,$id)]);exit;
}
if($action==='list'){
 $id=(int)($_GET['folder_id']??0);muFolder($pdo,$uid,$id);if(!muLibrary($pdo,$uid,$id))muFail(409,'No es una biblioteca musical');
 echo json_encode(['success'=>true,'albums'=>muAlbums($pdo,$uid,$id)],JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE);exit;
}
if($action==='collection_status_hf3'){
 $id=(int)($_GET['folder_id']??0);$f=muFolder($pdo,$uid,$id);$parent=(int)$f['parent_id'];
 if($parent<1||!muLibrary($pdo,$uid,$parent))muFail(409,'Solo disponible dentro de una biblioteca musical');
 echo json_encode(['success'=>true,'enabled'=>muCollectionEnabled85mHF3($pdo,$uid,$id)]);exit;
}
if($action==='images'){
 $q=$pdo->prepare("SELECT id,name,parent_id FROM files WHERE owner_id=? AND is_dir=0 AND deleted_at IS NULL AND (mime_type IN ('image/jpeg','image/png','image/webp') OR LOWER(SUBSTRING_INDEX(name,'.',-1)) IN ('jpg','jpeg','png','webp')) ORDER BY id DESC LIMIT 300");
 $q->execute([$uid]);echo json_encode(['success'=>true,'items'=>$q->fetchAll(PDO::FETCH_ASSOC)],JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE);exit;
}
if($action==='progress'){
 $folder=(int)($_GET['album_folder_id']??0);$f=muFolder($pdo,$uid,$folder);
 $parent=(int)$f['parent_id'];if(!muLibrary($pdo,$uid,$folder)&&!muLibrary($pdo,$uid,$parent))muFail(403,'Álbum fuera de biblioteca');
 $q=$pdo->prepare('SELECT p.track_id,p.position_seconds FROM dc_music_progress_81 p JOIN files t ON t.id=p.track_id AND t.owner_id=? AND t.parent_id=? AND t.deleted_at IS NULL AND t.is_dir=0 WHERE p.user_id=? AND p.album_folder_id=? LIMIT 1');
 $q->execute([$uid,$folder,$uid,$folder]);$progress=$q->fetch(PDO::FETCH_ASSOC)?:null;
 echo json_encode(['success'=>true,'progress'=>$progress],JSON_UNESCAPED_UNICODE);exit;
}
if($_SERVER['REQUEST_METHOD']!=='POST')muFail(405,'POST requerido');
$csrf=(string)($_POST['csrf']??'');if(!$csrf||!hash_equals((string)($_SESSION['dc_csrf']??''),$csrf))muFail(403,'CSRF inválido');
if($action==='set_collection_hf3'){
 if(empty($u['can_upload'])&&($u['role']??'')!=='admin')muFail(403,'Sin permiso para configurar colecciones');
 $id=(int)($_POST['folder_id']??0);$f=muFolder($pdo,$uid,$id);$parent=(int)$f['parent_id'];
 if($parent<1||!muLibrary($pdo,$uid,$parent))muFail(409,'Solo disponible dentro de una biblioteca musical');
 $enabled=(string)($_POST['enabled']??'');if(!in_array($enabled,['0','1'],true))muFail(422,'Estado inválido');
 require_once __DIR__.'/dc_special_modes_85m.php';
 if($enabled==='1' && dc85mConflict($pdo,$uid,$id,'music'))muFail(409,'Carpeta con otro modo especial');
 $q=$pdo->prepare('INSERT INTO dc_music_collection_modes_hf3(owner_id,folder_id,enabled) VALUES (?,?,?) ON DUPLICATE KEY UPDATE enabled=VALUES(enabled)');
 $q->execute([$uid,$id,(int)$enabled]);dcActivity85m($pdo,$uid,$id,'special');
 echo json_encode(['success'=>true,'enabled'=>$enabled==='1']);exit;
}
if($action==='set_mode'){
 if(empty($u['can_upload'])&&($u['role']??'')!=='admin')muFail(403,'No tienes permiso para configurar bibliotecas');
 $id=(int)($_POST['folder_id']??0);muFolder($pdo,$uid,$id);$on=(string)($_POST['enabled']??'');if(!in_array($on,['0','1'],true))muFail(422,'Estado inválido');
 if($on==='1'){require_once __DIR__.'/dc_special_modes_85m.php';if(dc85mConflict($pdo,$uid,$id,'music'))muFail(409,'Desactiva primero la clasificación actual de la carpeta');$q=$pdo->prepare('INSERT IGNORE INTO dc_music_libraries_81(owner_id,folder_id) VALUES (?,?)');$q->execute([$uid,$id]);}
 else{$q=$pdo->prepare('DELETE FROM dc_music_libraries_81 WHERE owner_id=? AND folder_id=?');$q->execute([$uid,$id]);}
 dcActivity85m($pdo,$uid,$id,'special');
 echo json_encode(['success'=>true,'enabled'=>$on==='1']);exit;
}
if($action==='set_cover'){
 if(empty($u['can_upload'])&&($u['role']??'')!=='admin')muFail(403,'No autorizado para cambiar portadas');
 $folder=(int)($_POST['album_folder_id']??0);$image=(int)($_POST['image_id']??0);
 $f=muFolder($pdo,$uid,$folder);if(!muLibrary($pdo,$uid,$folder)&&!muLibrary($pdo,$uid,(int)$f['parent_id']))muFail(403,'No pertenece a biblioteca musical');
 if($image>0){$q=$pdo->prepare("SELECT 1 FROM files WHERE id=? AND owner_id=? AND deleted_at IS NULL AND is_dir=0 AND (mime_type IN ('image/jpeg','image/png','image/webp') OR LOWER(SUBSTRING_INDEX(name,'.',-1)) IN ('jpg','jpeg','png','webp'))");$q->execute([$image,$uid]);if(!$q->fetchColumn())muFail(404,'Imagen no disponible');}
 if($image===0){$q=$pdo->prepare('DELETE FROM dc_music_covers_82 WHERE owner_id=? AND album_folder_id=?');$q->execute([$uid,$folder]);}
 else{$q=$pdo->prepare('INSERT INTO dc_music_covers_82(owner_id,album_folder_id,image_id) VALUES (?,?,?) ON DUPLICATE KEY UPDATE image_id=VALUES(image_id)');$q->execute([$uid,$folder,$image]);}
 echo json_encode(['success'=>true]);exit;
}
if($action==='save_progress'){
 $folder=(int)($_POST['album_folder_id']??0);$track=(int)($_POST['track_id']??0);$seconds=filter_var($_POST['seconds']??null,FILTER_VALIDATE_FLOAT);
 if($seconds===false||!is_finite($seconds)||$seconds<0||$seconds>86400)muFail(422,'Posición inválida');
 $f=muFolder($pdo,$uid,$folder);$parent=(int)$f['parent_id'];if(!muLibrary($pdo,$uid,$parent)&&!muLibrary($pdo,$uid,$folder))muFail(403,'Álbum fuera de biblioteca');
 $q=$pdo->prepare('SELECT 1 FROM files WHERE id=? AND owner_id=? AND parent_id=? AND is_dir=0 AND deleted_at IS NULL AND '.muAudioCondition());$q->execute([$track,$uid,$folder]);if(!$q->fetchColumn())muFail(404,'Pista inexistente');
 $q=$pdo->prepare('INSERT INTO dc_music_progress_81(user_id,album_folder_id,track_id,position_seconds,updated_at) VALUES (?,?,?,?,NOW()) ON DUPLICATE KEY UPDATE track_id=VALUES(track_id),position_seconds=VALUES(position_seconds),updated_at=NOW()');$q->execute([$uid,$folder,$track,round((float)$seconds,2)]);
 echo json_encode(['success'=>true]);exit;
}
muFail(400,'Acción no reconocida');
