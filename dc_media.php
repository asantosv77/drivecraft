<?php
/** Se incluye desde api.php DESPUÉS de autenticación y CSRF. */
require_once __DIR__.'/dc_media_lib.php';
header('Content-Type: application/json; charset=utf-8');
if($action==='media_settings'){echo json_encode(dcMediaSettings($pdo));return;}
if($action==='media_analyze'){
 $id=filter_var($_GET['id']??null,FILTER_VALIDATE_INT);
 if(!$id||!dcOwnedTree($id,$pdo,$dcOwnerId))dcFail(404,'Archivo no encontrado');
 $q=$pdo->prepare('SELECT * FROM files WHERE id=? AND owner_id=? AND is_dir=0 AND deleted_at IS NULL LIMIT 1');$q->execute([$id,$dcOwnerId]);$f=$q->fetch(PDO::FETCH_ASSOC);
 if(!$f||!dcMediaIsVideo($f))dcFail(422,'No es un video elegible');
 $stored=(string)$f['storage_name'];if($stored===''||basename($stored)!==$stored||!preg_match('/^[A-Za-z0-9_.-]+$/D',$stored))dcFail(422,'Ruta inválida');
 $state=dcMediaAnalyze(rtrim($STORAGE_DIR,'/').'/'.$stored,(string)$f['name']);
 echo json_encode($state);return;
}
if($action==='media_jobs'){
 $q=$pdo->prepare('SELECT j.id,j.kind,j.status,j.message,j.created_at,j.finished_at,j.output_file_id,f.name FROM dc_media_jobs j LEFT JOIN files f ON f.id=j.file_id WHERE j.owner_id=? ORDER BY j.id DESC LIMIT 30');
 $q->execute([$dcOwnerId]);echo json_encode(['jobs'=>$q->fetchAll(PDO::FETCH_ASSOC)]);return;
}
if($action!=='media_enqueue' || $_SERVER['REQUEST_METHOD']!=='POST')dcFail(405,'Se requiere POST');
if(empty($dcLogged['can_upload']) && !$dcIsAdmin)dcFail(403,'Permiso para subir requerido');
$id=filter_var($_POST['id']??null,FILTER_VALIDATE_INT);$kind=(string)($_POST['kind']??'convert');$mode=(string)($_POST['mode']??'manual');
if(!$id||!in_array($kind,['convert','thumbnail','faststart'],true)||!in_array($mode,['manual','auto'],true))dcFail(422,'Solicitud incorrecta');
if(!dcOwnedTree($id,$pdo,$dcOwnerId))dcFail(404,'Archivo no encontrado');
$q=$pdo->prepare('SELECT * FROM files WHERE id=? AND owner_id=? AND is_dir=0 AND deleted_at IS NULL LIMIT 1');$q->execute([$id,$dcOwnerId]);$f=$q->fetch(PDO::FETCH_ASSOC);
if(!$f||!dcMediaIsVideo($f))dcFail(422,'No es un video elegible');
if($kind==='faststart'&&!preg_match('/\.mp4$/iD',(string)$f['name']))dcFail(422,'Faststart solo admite MP4');
$s=dcMediaSettings($pdo);
if(in_array($kind,['convert','faststart'],true)&&!$s['conversion_enabled'])dcFail(403,'Conversión deshabilitada por el administrador');
if($kind==='thumbnail'&&!$s['thumbnails_enabled'])dcFail(403,'Miniaturas deshabilitadas');
if($mode==='auto'&&!$s['auto_conversion']&&$kind==='convert')dcFail(403,'Conversión automática deshabilitada');
if((int)$f['size']>$s['max_mib']*1048576)dcFail(413,'El archivo supera el límite multimedia');
if(in_array($kind,['convert','faststart'],true)){
 $stored=(string)$f['storage_name'];if($stored===''||basename($stored)!==$stored||!preg_match('/^[A-Za-z0-9_.-]+$/D',$stored))dcFail(422,'Ruta inválida');
 $state=dcMediaAnalyze(rtrim($STORAGE_DIR,'/').'/'.$stored,(string)$f['name']);
 if(!$state['known'])dcFail(422,'No se pudo verificar el video; no se agregó ningún trabajo');
 if($kind==='convert'&&!$state['can_convert']){echo json_encode(['success'=>true,'status'=>'already','message'=>'Este video ya es compatible; no necesita conversión']);return;}
 if($kind==='faststart'&&!$state['can_faststart']){echo json_encode(['success'=>true,'status'=>'already','message'=>$state['message']]);return;}
}
$jobId=dcMediaQueue($pdo,$f,$kind,$mode);
echo json_encode(['success'=>true,'job_id'=>$jobId,'status'=>'queued']);
