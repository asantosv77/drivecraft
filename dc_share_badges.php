<?php
/** Drive-Craft: estados de compartición visibles únicamente para sus propietarios. */
require __DIR__.'/config.php';
require_once __DIR__.'/auth.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: private, no-store');
header('X-Content-Type-Options: nosniff');
function dcsbFail(int $code, string $text): never { http_response_code($code); echo json_encode(['success'=>false,'error'=>$text]); exit; }
if (($_SERVER['REQUEST_METHOD']??'') !== 'POST') dcsbFail(405,'Método no permitido');
$user=dcRequireUser();
if (!empty($user['must_change_password'])) dcsbFail(403,'Acceso no permitido');
if (!isset($_POST['csrf']) || !hash_equals((string)($_SESSION['dc_csrf']??''),(string)$_POST['csrf'])) dcsbFail(403,'CSRF inválido');
$ids=json_decode((string)($_POST['ids']??''),true);
if (!is_array($ids) || count($ids)>250) dcsbFail(422,'Lista inválida');
$ids=array_values(array_unique(array_map('intval',$ids)));
$ids=array_values(array_filter($ids,static fn($v)=>$v>0));
if (!$ids) {echo json_encode(['success'=>true,'items'=>new stdClass()]);exit;}
$marks=implode(',',array_fill(0,count($ids),'?'));
$uid=(int)$user['id'];
$owned=$pdo->prepare("SELECT id FROM files WHERE id IN ($marks) AND owner_id=? AND deleted_at IS NULL");
$owned->execute([...$ids,$uid]);
$ownedIds=array_map('intval',$owned->fetchAll(PDO::FETCH_COLUMN));
if (!$ownedIds) {echo json_encode(['success'=>true,'items'=>new stdClass()]);exit;}
$marks=implode(',',array_fill(0,count($ownedIds),'?'));
$items=[];foreach($ownedIds as $id)$items[(string)$id]=['internal'=>0,'public'=>0];
$q=$pdo->prepare("SELECT s.file_id,COUNT(*) AS n FROM dc_internal_shares s JOIN dc_users u ON u.id=s.recipient_id AND u.status='active' JOIN files f ON f.id=s.file_id AND f.owner_id=s.owner_id AND f.deleted_at IS NULL WHERE s.owner_id=? AND s.file_id IN ($marks) GROUP BY s.file_id");
$q->execute([$uid,...$ownedIds]);
foreach($q->fetchAll(PDO::FETCH_ASSOC) as $row)$items[(string)$row['file_id']]['internal']=(int)$row['n'];
// shares contiene permisos por URL; no confundir con enlaces de recepción.
$q=$pdo->prepare("SELECT s.file_id,COUNT(*) AS n FROM shares s JOIN files f ON f.id=s.file_id WHERE f.owner_id=? AND s.file_id IN ($marks) AND (s.expires_at IS NULL OR s.expires_at>NOW()) GROUP BY s.file_id");
$q->execute([$uid,...$ownedIds]);
foreach($q->fetchAll(PDO::FETCH_ASSOC) as $row)$items[(string)$row['file_id']]['public']=(int)$row['n'];
echo json_encode(['success'=>true,'items'=>$items],JSON_UNESCAPED_UNICODE);
