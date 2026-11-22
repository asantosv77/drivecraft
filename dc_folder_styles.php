<?php
/** Metadatos decorativos de carpetas propias: no altera permisos ni contenidos. */
require __DIR__.'/config.php';
require_once __DIR__.'/auth.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: private, no-store');
$user=dcRequireUser();
if(!empty($user['must_change_password'])){http_response_code(403);exit(json_encode(['error'=>'No autorizado']));}
$uid=(int)$user['id'];
$q=$pdo->prepare('SELECT id,is_album FROM files WHERE owner_id=? AND is_dir=1 AND deleted_at IS NULL');$q->execute([$uid]);
$items=[];
foreach($q->fetchAll(PDO::FETCH_ASSOC) as $f)$items[(string)$f['id']]=['kind'=>(int)$f['is_album']===1?'album':'folder','cover_id'=>null];
$q=$pdo->prepare('SELECT folder_id FROM dc_youtube_folders WHERE owner_id=?');$q->execute([$uid]);
foreach($q->fetchAll(PDO::FETCH_COLUMN) as $id){if(isset($items[(string)$id]))$items[(string)$id]['kind']='youtube';}
$q=$pdo->prepare("SELECT parent_id,id FROM files WHERE owner_id=? AND is_dir=0 AND deleted_at IS NULL AND (LOWER(mime_type) LIKE 'image/%' OR LOWER(name) REGEXP '\\.(jpg|jpeg|png|gif|webp|avif)$') ORDER BY id DESC");$q->execute([$uid]);
foreach($q->fetchAll(PDO::FETCH_ASSOC) as $f){$key=(string)$f['parent_id'];if(isset($items[$key])&&$items[$key]['kind']==='album'&&!$items[$key]['cover_id'])$items[$key]['cover_id']=(int)$f['id'];}
echo json_encode(['success'=>true,'items'=>$items],JSON_UNESCAPED_UNICODE);
