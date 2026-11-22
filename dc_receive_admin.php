<?php
require __DIR__.'/config.php';
require __DIR__.'/auth.php';
require __DIR__.'/dc_receive_maintenance.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
$user=dcRequireAdmin();
if(!empty($user['must_change_password']))dcFail(403,'Acceso restringido');
$method=$_SERVER['REQUEST_METHOD']??'GET';
if(!in_array($method,['GET','POST'],true))dcFail(405,'Método no permitido');
if($method==='POST')dcCsrf();
try {
  $r=dcRxSweep(dcRxRoot($STORAGE_DIR),$method==='POST');
  echo json_encode(['success'=>true,'maintenance'=>$r],JSON_UNESCAPED_UNICODE);
} catch(Throwable $e){error_log('Drive-Craft recepción mantenimiento: '.$e->getMessage());dcFail(500,'Error en supervisión temporal');}
