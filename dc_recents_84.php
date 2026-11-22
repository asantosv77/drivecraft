<?php
/** Registra solo aperturas expresas desde el explorador; no se utiliza para miniaturas. */
require __DIR__.'/config.php';
require_once __DIR__.'/auth.php';
header('Content-Type: application/json; charset=utf-8');
if($_SERVER['REQUEST_METHOD']!=='POST')dcFail(405,'POST requerido');
$u=dcRequireUser();dcCsrf();
$id=filter_input(INPUT_POST,'id',FILTER_VALIDATE_INT);
if(!$id || $id<=0)dcFail(422,'Archivo inválido');
$q=$pdo->prepare('SELECT id,parent_id,is_dir,deleted_at,owner_id FROM files WHERE id=?');
$q->execute([$id]);$file=$q->fetch(PDO::FETCH_ASSOC);
if(!$file || (int)$file['owner_id']!==(int)$u['id'] || (int)$file['is_dir']!==0 || $file['deleted_at']!==null)dcFail(404,'Archivo no encontrado');
// Verificar que cada ancestro pertenezca a la misma cuenta y no esté en papelera.
$parent=(int)$file['parent_id'];$visited=[];
while($parent>0){
 if(isset($visited[$parent]))dcFail(404,'Ruta inválida');$visited[$parent]=true;
 $q=$pdo->prepare('SELECT parent_id,owner_id,deleted_at,is_dir FROM files WHERE id=?');$q->execute([$parent]);$r=$q->fetch(PDO::FETCH_ASSOC);
 if(!$r || (int)$r['owner_id']!==(int)$u['id'] || $r['deleted_at']!==null || (int)$r['is_dir']!==1)dcFail(404,'Ruta inválida');
 $parent=(int)$r['parent_id'];
}
// HF2: endpoint heredado, no registra aperturas como actividades.
header('Cache-Control: no-store');
echo json_encode(['success'=>true]);
