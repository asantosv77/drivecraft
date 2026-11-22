<?php
/** Drive-Craft v20.10.15: permisos internos revocables (archivos individuales, solo lectura). */
require __DIR__.'/config.php';
require_once __DIR__.'/auth.php';
header('Cache-Control: private, no-store');
header('X-Content-Type-Options: nosniff');
$user=dcRequireUser();
if (!empty($user['must_change_password'])) { http_response_code(403);exit; }
$uid=(int)$user['id'];
function dciFail(int $status,string $message): never { http_response_code($status);header('Content-Type: application/json; charset=utf-8');echo json_encode(['success'=>false,'error'=>$message]);exit; }
function dciAncestorsActive(PDO $pdo,array $file): bool {
 $parent=(int)$file['parent_id'];$seen=[];
 while($parent>0){if(isset($seen[$parent]))return false;$seen[$parent]=1;
  $s=$pdo->prepare('SELECT id,parent_id,owner_id,is_dir,deleted_at FROM files WHERE id=?');$s->execute([$parent]);$p=$s->fetch(PDO::FETCH_ASSOC);
  if(!$p || (int)$p['owner_id']!==(int)$file['owner_id'] || !(int)$p['is_dir'] || $p['deleted_at']!==null)return false;
  $parent=(int)$p['parent_id'];
 }
 return true;
}
function dciLoadActive(PDO $pdo,int $fid): ?array {
 $q=$pdo->prepare('SELECT * FROM files WHERE id=? AND is_dir=0 AND deleted_at IS NULL');$q->execute([$fid]);$f=$q->fetch(PDO::FETCH_ASSOC);
 return $f&&dciAncestorsActive($pdo,$f)?$f:null;
}
if (in_array((string)($_GET['action']??''),['download','view'],true)) {
 $sid=filter_input(INPUT_GET,'id',FILTER_VALIDATE_INT);
 if(!$sid||$sid<1)dciFail(404,'Acceso no disponible');
 $q=$pdo->prepare('SELECT file_id FROM dc_internal_shares WHERE id=? AND recipient_id=?');$q->execute([$sid,$uid]);$fid=$q->fetchColumn();
 if(!$fid)dciFail(404,'Acceso revocado o no disponible');
 $file=dciLoadActive($pdo,(int)$fid);
 if(!$file)dciFail(404,'Archivo no disponible');
 $stored=(string)$file['storage_name'];
 if(!$stored||basename($stored)!==$stored||!preg_match('/^[A-Za-z0-9_.-]+$/D',$stored))dciFail(404,'Archivo no disponible');
 $path=rtrim($STORAGE_DIR,'/').'/'.$stored;
 if(!is_file($path)||is_link($path)||!is_readable($path))dciFail(404,'Archivo no disponible');
 $name=str_replace(["\r","\n",'"','\\'], '_', (string)$file['name']);
 $view=(string)($_GET['action']??'')==='view';
 $extension=strtolower(pathinfo((string)$file['name'],PATHINFO_EXTENSION));
 $mime=strtolower(trim((string)($file['mime_type']??'')));
 $textExtensions=['txt','log','php','js','mjs','cjs','ts','tsx','jsx','yaml','yml','html','htm','java','md','markdown','css','json','xml','sql','py','sh','bash','zsh','c','h','cpp','hpp','cs','go','rs','rb','pl','ini','conf','cfg','toml','properties','csv','tsv'];
 $imageMimes=['jpg'=>'image/jpeg','jpeg'=>'image/jpeg','png'=>'image/png','gif'=>'image/gif','webp'=>'image/webp','avif'=>'image/avif','bmp'=>'image/bmp'];
 $audioMimes=['mp3'=>'audio/mpeg','wav'=>'audio/wav','ogg'=>'audio/ogg','m4a'=>'audio/mp4','aac'=>'audio/aac','flac'=>'audio/flac'];
 $videoMimes=['mp4'=>'video/mp4','webm'=>'video/webm','ogv'=>'video/ogg','mov'=>'video/quicktime'];
 $safeMime=null;
 if(in_array($extension,$textExtensions,true))$safeMime='text/plain; charset=utf-8';
 elseif(isset($imageMimes[$extension]))$safeMime=$imageMimes[$extension];
 elseif(isset($audioMimes[$extension]))$safeMime=$audioMimes[$extension];
 elseif(isset($videoMimes[$extension]))$safeMime=$videoMimes[$extension];
 elseif($extension==='pdf')$safeMime='application/pdf';
 if($view && $safeMime===null)dciFail(415,'Este tipo de archivo solo admite descarga');
 // Impedir HTML ejecutable en el origen de Drive-Craft, aunque su extensión sea HTML/PHP/SVG.
 header('Content-Type: '.($view?$safeMime:'application/octet-stream'));
 header('Content-Disposition: '.($view?'inline':'attachment').'; filename="'.substr($name,0,190).'"; filename*=UTF-8\'\''.rawurlencode((string)$file['name']));
 header('X-Content-Type-Options: nosniff');
 header('Content-Security-Policy: sandbox');
 header('Content-Length: '.filesize($path));
 header('Referrer-Policy: no-referrer');
 readfile($path);exit;
}
header('Content-Type: application/json; charset=utf-8');
$action=(string)($_POST['action']??'');
if (($_SERVER['REQUEST_METHOD']??'')!=='POST')dciFail(405,'Método no permitido');
if (!isset($_POST['csrf'])||!hash_equals((string)($_SESSION['dc_csrf']??''),(string)$_POST['csrf']))dciFail(403,'CSRF inválido');
if($action==='received'){
 $q=$pdo->prepare('SELECT s.id,s.file_id,s.created_at,f.name,f.size,f.mime_type,u.username AS owner FROM dc_internal_shares s JOIN files f ON f.id=s.file_id JOIN dc_users u ON u.id=f.owner_id WHERE s.recipient_id=? AND f.is_dir=0 AND f.deleted_at IS NULL ORDER BY s.id DESC LIMIT 250');
 $q->execute([$uid]);$rows=array_values(array_filter($q->fetchAll(PDO::FETCH_ASSOC),function($row)use($pdo){return dciLoadActive($pdo,(int)$row['file_id'])!==null;}));
 echo json_encode(['success'=>true,'items'=>$rows]);exit;
}
if($action==='shortcuts'){
 $pid=filter_var($_POST['parent_id']??null,FILTER_VALIDATE_INT);
 if($pid===false||$pid===null||$pid<0)dciFail(422,'Carpeta inválida');
 if($pid>0){$q=$pdo->prepare('SELECT * FROM files WHERE id=? AND owner_id=? AND is_dir=1 AND deleted_at IS NULL');$q->execute([$pid,$uid]);$folder=$q->fetch(PDO::FETCH_ASSOC);if(!$folder||!dciAncestorsActive($pdo,$folder))dciFail(404,'Carpeta no disponible');}
 $q=$pdo->prepare('SELECT c.id,c.share_id,s.file_id,f.name,f.size,f.mime_type,u.username AS owner FROM dc_internal_shortcuts c JOIN dc_internal_shares s ON s.id=c.share_id AND s.recipient_id=c.recipient_id JOIN files f ON f.id=s.file_id AND f.deleted_at IS NULL AND f.is_dir=0 AND f.owner_id=s.owner_id JOIN dc_users u ON u.id=s.owner_id WHERE c.recipient_id=? AND c.parent_id=? ORDER BY c.id DESC LIMIT 250');
 $q->execute([$uid,$pid]);$rows=array_values(array_filter($q->fetchAll(PDO::FETCH_ASSOC),fn($r)=>dciLoadActive($pdo,(int)$r['file_id'])!==null));
 echo json_encode(['success'=>true,'items'=>$rows]);exit;
}
if($action==='pin'){
 if(($user['role']??'')!=='admin'&&empty($user['can_upload']))dciFail(403,'No tienes permiso para agregar elementos');
 $sid=filter_var($_POST['share_id']??null,FILTER_VALIDATE_INT);
 $pid=filter_var($_POST['parent_id']??null,FILTER_VALIDATE_INT);
 if(!$sid||$sid<1||$pid===false||$pid===null||$pid<0)dciFail(422,'Solicitud inválida');
 $q=$pdo->prepare('SELECT s.file_id FROM dc_internal_shares s WHERE s.id=? AND s.recipient_id=?');$q->execute([$sid,$uid]);$fid=$q->fetchColumn();
 if(!$fid||!dciLoadActive($pdo,(int)$fid))dciFail(404,'Acceso no disponible');
 if($pid>0){$q=$pdo->prepare('SELECT * FROM files WHERE id=? AND owner_id=? AND is_dir=1 AND deleted_at IS NULL');$q->execute([$pid,$uid]);$f=$q->fetch(PDO::FETCH_ASSOC);if(!$f||!dciAncestorsActive($pdo,$f))dciFail(404,'Carpeta de destino no disponible');if(!empty($f['is_album']))dciFail(422,'No se admiten accesos directos en álbumes');}
 $q=$pdo->prepare('INSERT IGNORE INTO dc_internal_shortcuts(share_id,recipient_id,parent_id) VALUES(?,?,?)');$q->execute([$sid,$uid,$pid]);
 echo json_encode(['success'=>true,'message'=>'Acceso directo agregado']);exit;
}
if($action==='unpin'){
 $id=filter_var($_POST['id']??null,FILTER_VALIDATE_INT);if(!$id||$id<1)dciFail(422,'Identificador inválido');
 $q=$pdo->prepare('DELETE FROM dc_internal_shortcuts WHERE id=? AND recipient_id=?');$q->execute([$id,$uid]);
 if(!$q->rowCount())dciFail(404,'Acceso directo no disponible');
 echo json_encode(['success'=>true]);exit;
}
if($action==='granted'){
 $fid=filter_var($_POST['file_id']??null,FILTER_VALIDATE_INT);$f=$fid?dciLoadActive($pdo,$fid):null;
 if(!$f||(int)$f['owner_id']!==$uid)dciFail(404,'Archivo no disponible');
 $q=$pdo->prepare('SELECT s.id,s.created_at,u.username FROM dc_internal_shares s JOIN dc_users u ON u.id=s.recipient_id WHERE s.file_id=? ORDER BY s.id DESC');$q->execute([$fid]);
 echo json_encode(['success'=>true,'items'=>$q->fetchAll(PDO::FETCH_ASSOC)]);exit;
}
if($action==='grant'){
 if(($user['role']??'')!=='admin'&&empty($user['can_share']))dciFail(403,'No tienes permiso para compartir');
 $fid=filter_var($_POST['file_id']??null,FILTER_VALIDATE_INT);$f=$fid?dciLoadActive($pdo,$fid):null;
 if(!$f||(int)$f['owner_id']!==$uid)dciFail(404,'Archivo no disponible');
 $username=trim((string)($_POST['username']??''));
 if($username===''||strlen($username)>100)dciFail(422,'Nombre de usuario inválido');
 $q=$pdo->prepare('SELECT id FROM dc_users WHERE username=? AND status=? LIMIT 1');$q->execute([$username,'active']);$recipient=(int)$q->fetchColumn();
 if(!$recipient||$recipient===$uid)dciFail(404,'Destinatario no disponible');
 $q=$pdo->prepare('INSERT IGNORE INTO dc_internal_shares (file_id,owner_id,recipient_id) VALUES (?,?,?)');$q->execute([$fid,$uid,$recipient]);
 echo json_encode(['success'=>true,'message'=>'Acceso concedido']);exit;
}
if($action==='revoke'){
 $sid=filter_var($_POST['id']??null,FILTER_VALIDATE_INT);if(!$sid||$sid<1)dciFail(422,'Identificador inválido');
 $q=$pdo->prepare('SELECT s.id FROM dc_internal_shares s JOIN files f ON f.id=s.file_id WHERE s.id=? AND s.owner_id=? AND f.owner_id=?');$q->execute([$sid,$uid,$uid]);if(!$q->fetchColumn())dciFail(404,'Permiso no disponible');
 $pdo->beginTransaction();
 try { $pdo->prepare('DELETE FROM dc_internal_shortcuts WHERE share_id=?')->execute([$sid]); $q=$pdo->prepare('DELETE FROM dc_internal_shares WHERE id=? AND owner_id=?');$q->execute([$sid,$uid]);$pdo->commit(); }catch(Throwable $e){$pdo->rollBack();throw $e;}
 if(!$q->rowCount())dciFail(404,'Permiso no disponible');
 echo json_encode(['success'=>true,'message'=>'Acceso revocado']);exit;
}
dciFail(400,'Acción desconocida');
