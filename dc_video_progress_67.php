<?php
/* Drive-Craft 20.10.67: progreso privado de episodios */
require __DIR__.'/config.php';require_once __DIR__.'/auth.php';
header('Cache-Control: private, no-store');header('Content-Type: application/json; charset=utf-8');
function vpFail($status,$msg){http_response_code($status);echo json_encode(['error'=>$msg]);exit;}
$u=dcRequireUser();$uid=(int)$u['id'];$act=(string)($_REQUEST['action']??'get');
$cid=(int)($_REQUEST['collection_id']??0);
if($cid<1)vpFail(400,'Colección inválida');
$q=$pdo->prepare('SELECT c.id FROM dc_video_collections_65 c JOIN files f ON f.id=c.folder_id AND f.owner_id=c.owner_id AND f.deleted_at IS NULL AND f.is_dir=1 WHERE c.id=? AND c.owner_id=?');
$q->execute([$cid,$uid]);if(!$q->fetchColumn())vpFail(404,'Colección no disponible');
if($act==='get'){
 $q=$pdo->prepare('SELECT video_id,position_seconds,duration_seconds,completed,updated_at FROM dc_video_progress_67 WHERE collection_id=? AND owner_id=? ORDER BY updated_at DESC');$q->execute([$cid,$uid]);
 echo json_encode(['success'=>true,'items'=>$q->fetchAll(PDO::FETCH_ASSOC)]);exit;
}
if($_SERVER['REQUEST_METHOD']!=='POST')vpFail(405,'POST requerido');
$csrf=(string)($_POST['csrf']??'');if(!$csrf||!hash_equals((string)($_SESSION['dc_csrf']??''),$csrf))vpFail(403,'CSRF inválido');
if($act==='save'){
 $video=(int)($_POST['video_id']??0);
 $q=$pdo->prepare('SELECT 1 FROM dc_video_collection_items_65 i JOIN files f ON f.id=i.video_id AND f.owner_id=? AND f.deleted_at IS NULL AND f.is_dir=0 WHERE i.collection_id=? AND i.video_id=?');$q->execute([$uid,$cid,$video]);if(!$q->fetchColumn())vpFail(403,'Episodio no autorizado');
 $position=filter_var($_POST['position']??null,FILTER_VALIDATE_FLOAT);$duration=filter_var($_POST['duration']??null,FILTER_VALIDATE_FLOAT);
 if($position===false||$duration===false||!is_finite($position)||!is_finite($duration)||$position<0||$duration<0||$duration>864000||$position>864000)vpFail(422,'Posición inválida');
 $position=min($position,$duration>0?$duration:$position);$done=$duration>0 && $position/$duration>=.95?1:0;
 $q=$pdo->prepare('INSERT INTO dc_video_progress_67(owner_id,collection_id,video_id,position_seconds,duration_seconds,completed) VALUES(?,?,?,?,?,?) ON DUPLICATE KEY UPDATE position_seconds=VALUES(position_seconds),duration_seconds=VALUES(duration_seconds),completed=VALUES(completed),updated_at=CURRENT_TIMESTAMP');
 $q->execute([$uid,$cid,$video,$position,$duration,$done]);echo json_encode(['success'=>true]);exit;
}
if($act==='reset'){
 $video=(int)($_POST['video_id']??0);
 if($video>0){$q=$pdo->prepare('DELETE FROM dc_video_progress_67 WHERE owner_id=? AND collection_id=? AND video_id=?');$q->execute([$uid,$cid,$video]);}
 else{$q=$pdo->prepare('DELETE FROM dc_video_progress_67 WHERE owner_id=? AND collection_id=?');$q->execute([$uid,$cid]);}
 echo json_encode(['success'=>true]);exit;
}
vpFail(400,'Acción no permitida');
