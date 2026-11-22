<?php
require __DIR__.'/config.php';require_once __DIR__.'/auth.php';require_once __DIR__.'/dc_media_tools_lib_dcft.php';require_once __DIR__.'/dc_external_ai_lib_dcft.php';
header('Content-Type: application/json; charset=utf-8');header('Cache-Control: private, no-store');header('X-Content-Type-Options: nosniff');
function dceReply(array $data,int $status=200):never{http_response_code($status);echo json_encode($data,JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE);exit;}
$user=dcRequireUser(false);if(($user['role']??'')!=='admin')dceReply(['error'=>'Solo administradores'],403);if(!empty($user['must_change_password']))dceReply(['error'=>'Actualiza tu contraseña'],403);
$action=(string)($_POST['action']??$_GET['action']??'');if($action!=='status'){
 if(($_SERVER['REQUEST_METHOD']??'')!=='POST')dceReply(['error'=>'POST requerido'],405);$csrf=(string)($_POST['csrf']??'');if($csrf===''||!hash_equals((string)($_SESSION['dc_csrf']??''),$csrf))dceReply(['error'=>'Recarga la página para renovar la sesión'],403);
}
try{
 if($action==='status'){$dir=dcmtPrivateDir();$state=is_file($dir.'/state.json')?json_decode(file_get_contents($dir.'/state.json'),true):null;$history=is_file($dir.'/history.json')?json_decode(file_get_contents($dir.'/history.json'),true):[];$versions=[];foreach(glob($dir.'/versions/*.php')?:[] as $f)$versions[]=basename($f,'.php');rsort($versions);dceReply(['success'=>true,'settings'=>dcmtExternalSettings($pdo),'state'=>$state,'history'=>$history,'versions'=>array_slice($versions,0,30),'skill'=>file_get_contents(__DIR__.'/skills/drivecraft-external-maintenance/SKILL.md')]);}
 if($action==='save'){
  $model=trim((string)($_POST['model']??''));if($model!==''&&!preg_match('/^[A-Za-z0-9._:-]{1,120}$/D',$model))throw new RuntimeException('Identificador de modelo inválido');
  $token=trim((string)($_POST['token']??''));if($token!==''&&(strlen($token)<20||strlen($token)>512||preg_match('/\s/',$token)))throw new RuntimeException('Token inválido');
  $cipher=$token!==''?dcmtTokenEncrypt($token):null;$pdo->beginTransaction();
  foreach(['downloads_enabled','ai_weekly','ai_auto_apply'] as $key)dcmtSetting($pdo,$key,($_POST[$key]??'0')==='1'?'1':'0');dcmtSetting($pdo,'ai_model',$model);
  if($cipher!==null)dcmtSetting($pdo,'ai_token',$cipher);if(($_POST['delete_token']??'0')==='1')dcmtSetting($pdo,'ai_token','');$pdo->commit();dceReply(['success'=>true]);
 }
 if($action==='models'){$s=dcmtExternalSettings($pdo,true);if($s['token']==='')throw new RuntimeException('Guarda primero el token de OpenAI');session_write_close();$r=dcmtOpenAI('models',$s['token']);$models=[];foreach($r['data']??[] as $row)if(is_string($row['id']??null)&&preg_match('/^(?:gpt-|o[134])/', $row['id'])&&!preg_match('/audio|realtime|image|transcribe|tts|search|embedding|codex|pro(?:-|$)/',$row['id']))$models[]=$row['id'];sort($models);dceReply(['success'=>true,'models'=>$models]);}
 if($action==='queue'){
  $s=dcmtExternalSettings($pdo);if(!$s['has_token']||$s['model']==='')throw new RuntimeException('Configura token y modelo antes de ejecutar la revisión');$dir=dcmtPrivateDir();$guard=fopen($dir.'/queue.lock','c');if(!$guard||!flock($guard,LOCK_EX|LOCK_NB))throw new RuntimeException('La cola está ocupada');
  $old=is_file($dir.'/state.json')?json_decode(file_get_contents($dir.'/state.json'),true):[];if(in_array($old['state']??'',['queued','running'],true))throw new RuntimeException('Ya hay una revisión pendiente');
  dcmtPrivateJson($dir.'/state.json',['state'=>'queued','trigger'=>'manual','created_at'=>time()]);
  if(function_exists('exec'))exec(escapeshellarg(PHP_BINDIR.'/php').' '.escapeshellarg(__DIR__.'/dc_external_worker_dcft.php').' >/dev/null 2>&1 &');dceReply(['success'=>true]);
 }
 if($action==='restore'){$id=(string)($_POST['version']??'');if(!preg_match('/^\d{8}-\d{6}-[a-f0-9]{8}$/D',$id))throw new RuntimeException('Versión inválida');$file=dcmtPrivateDir().'/versions/'.$id.'.php';if(!is_file($file)||is_link($file))throw new RuntimeException('Respaldo no disponible');$current=__DIR__.'/dc_youtube_adapter_dcft.php';$backup=dcmtPublishAdapter(file_get_contents($file),hash_file('sha256',$current));dceReply(['success'=>true,'backup'=>$backup]);}
 dceReply(['error'=>'Acción no disponible'],400);
}catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();dceReply(['error'=>$e instanceof PDOException?'No se pudieron guardar las preferencias de servicios externos':$e->getMessage()],422);}
