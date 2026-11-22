<?php
/** Drive-Craft 20.10.14 — acciones autenticadas de almacenamiento. */
require __DIR__.'/config.php';
require __DIR__.'/auth.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: private, no-store');
header('X-Content-Type-Options: nosniff');
function dcsaFail(int $status,string $reason): never { http_response_code($status); echo json_encode(['success'=>false,'error'=>$reason]); exit; }
if (($_SERVER['REQUEST_METHOD']??'')!=='POST') dcsaFail(405,'Solo POST');
$user=dcRequireUser();
if (!empty($user['must_change_password'])) dcsaFail(403,'Debes cambiar la contraseña primero');
if (!isset($_POST['csrf']) || !hash_equals((string)($_SESSION['dc_csrf']??''),(string)$_POST['csrf'])) dcsaFail(403,'CSRF inválido');
$uid=(int)$user['id'];$admin=($user['role']??'')==='admin';
$action=(string)($_POST['action']??'');
function dcsaName(string $str): bool {return $str!==''&&$str!=='.'&&$str!=='..'&&strlen($str)<=255&&basename($str)===$str&&preg_match('/^[A-Za-z0-9_.-]+$/D',$str)===1;}
function dcsaOwnedParent(PDO $pdo,int $parent,int $uid): bool {
 if($parent===0)return true;
 $seen=[];
 while($parent>0){if(isset($seen[$parent]))return false;$seen[$parent]=true;
  $st=$pdo->prepare('SELECT parent_id,owner_id,is_dir,deleted_at,is_album,mime_type FROM files WHERE id=?');$st->execute([$parent]);$f=$st->fetch(PDO::FETCH_ASSOC);
  if(!$f||(int)$f['owner_id']!==$uid||(int)$f['is_dir']!==1||$f['deleted_at']!==null||!empty($f['is_album'])||$f['mime_type']!=='directory')return false;
  $parent=(int)$f['parent_id'];
 }
 return true;
}
/** v80: misma política de conflictos de la subida normal; exige propiedad y distingue historial heredado. */
function dc80Versioned(PDO $pdo,int $folder,int $uid):bool{
 $visited=[];while($folder>0){if(isset($visited[$folder]))dcsaFail(409,'Ciclo de carpetas');$visited[$folder]=1;
  $q=$pdo->prepare('SELECT parent_id,version_enabled FROM files WHERE id=? AND owner_id=? AND is_dir=1 AND deleted_at IS NULL');$q->execute([$folder,$uid]);$r=$q->fetch(PDO::FETCH_ASSOC);
  if(!$r)dcsaFail(403,'Carpeta inaccesible');if(!empty($r['version_enabled']))return true;$folder=(int)$r['parent_id'];
 }return false;
}
function dc80Existing(PDO $pdo,int $uid,int $parent,string $name,string $mode):array{
 $q=$pdo->prepare('SELECT id,is_dir,storage_name,size FROM files WHERE owner_id=? AND parent_id=? AND name=? AND deleted_at IS NULL LIMIT 1');$q->execute([$uid,$parent,$name]);$old=$q->fetch(PDO::FETCH_ASSOC)?:null;
 $versioned=dc80Versioned($pdo,$parent,$uid);
 if($old){
  if((int)$old['is_dir']!==0)dcsaFail(409,'Existe una carpeta con ese nombre');
  if(!$versioned&&!in_array($mode,['replace','rename_old'],true)){
   http_response_code(409);echo json_encode(['success'=>false,'conflict'=>true,'filename'=>$name,'versioned'=>false,'error'=>'El archivo ya existe. Elige reemplazar o renombrar el existente.']);exit;
  }
 }
 return [$old,$versioned];
}
function dc80Previous(PDO $pdo,int $uid,int $parent,string $name):string{
 $dot=strrpos($name,'.');$base=$dot===false?$name:substr($name,0,$dot);$ext=$dot===false?'':substr($name,$dot);
 for($i=1;$i<10000;$i++){$suffix=' (anterior '.$i.')';$candidate=substr($base,0,max(1,240-strlen($ext)-strlen($suffix))).$suffix.$ext;
  $q=$pdo->prepare('SELECT 1 FROM files WHERE owner_id=? AND parent_id=? AND name=? AND deleted_at IS NULL LIMIT 1');$q->execute([$uid,$parent,$candidate]);if(!$q->fetchColumn())return $candidate;
 }dcsaFail(409,'No se encontró un nombre libre');
}
function dc80CommitExisting(PDO $pdo,int $uid,int $parent,string $name,?array $old,bool $versioned,string $mode,string $stored,int $bytes,string $mime,string $hash):int{
 if(!$old){$q=$pdo->prepare('INSERT INTO files(parent_id,name,is_dir,mime_type,size,storage_name,has_thumb,has_list,owner_id,content_hash_algorithm,content_hash) VALUES(?,?,0,?,?,?,0,0,?,?,?)');$q->execute([$parent,$name,$mime,$bytes,$stored,$uid,'sha256',$hash]);return (int)$pdo->lastInsertId();}
 $id=(int)$old['id'];
 if($versioned){$q=$pdo->prepare('INSERT INTO file_versions(file_id,storage_name,size,created_at) VALUES (?,?,?,NOW())');$q->execute([$id,$old['storage_name'],$old['size']]);}
 if(!$versioned&&$mode==='rename_old'){$renamed=dc80Previous($pdo,$uid,$parent,$name);$pdo->prepare('UPDATE files SET name=? WHERE id=? AND owner_id=?')->execute([$renamed,$id,$uid]);
  $q=$pdo->prepare('INSERT INTO files(parent_id,name,is_dir,mime_type,size,storage_name,has_thumb,has_list,owner_id,content_hash_algorithm,content_hash) VALUES(?,?,0,?,?,?,0,0,?,?,?)');$q->execute([$parent,$name,$mime,$bytes,$stored,$uid,'sha256',$hash]);return (int)$pdo->lastInsertId();}
 $q=$pdo->prepare('UPDATE files SET mime_type=?,size=?,storage_name=?,has_thumb=0,has_list=0,content_hash_algorithm=?,content_hash=? WHERE id=? AND owner_id=?');$q->execute([$mime,$bytes,$stored,'sha256',$hash,$id,$uid]);return $id;
}
if($action==='import_share'){
 if(!$admin&&empty($user['can_upload']))dcsaFail(403,'No tienes permiso para guardar archivos');
 $parent=filter_var($_POST['parent_id']??null,FILTER_VALIDATE_INT);
 if($parent===false||$parent<0||!dcsaOwnedParent($pdo,$parent,$uid))dcsaFail(403,'Carpeta de destino no autorizada');
 $mode=(string)($_POST['conflict_mode']??'');
 $input=trim((string)($_POST['link']??''));
 if(strlen($input)>2048)dcsaFail(422,'Enlace demasiado largo');
 // Identificar la instancia propia usando VIRTUAL_HOST, nunca HTTP_HOST aportado por el cliente.
 $urlHost=parse_url($input,PHP_URL_HOST);
 $hosts=array_filter(array_map('trim',explode(',',strtolower((string)(getenv('VIRTUAL_HOST')?:'')))));
 $isOwn=is_string($urlHost) && in_array(strtolower($urlHost),$hosts,true);
 $route=parse_url($input,PHP_URL_PATH);
 $isPublicRoute=($route==='/drive/api.php'||$route==='api.php'||$route==='/api.php') && str_contains($input,'public_hash=');
 if($isOwn && !$isPublicRoute)dcsaFail(422,'El enlace de esta instancia no corresponde a un archivo compartido');
 if(!$isOwn){
  require_once __DIR__.'/dc_external_import_68.php';
  dc68ImportExternal($pdo,$user,$uid,$admin,$parent,$input,$STORAGE_DIR,$mode);
 }
 // El enlace propio se valida localmente y no requiere una llamada HTTP.
 // No se hace una solicitud HTTP: el token se valida localmente, evitando SSRF.
 $url=parse_url($input);if($url===false)dcsaFail(422,'Enlace inválido');
 $query=[];parse_str((string)($url['query']??''),$query);
 $token=(string)($query['public_hash']??'');
 if(!preg_match('/^[a-f0-9]{32}$/D',$token))dcsaFail(422,'Se requiere un enlace de archivo de Drive-Craft');
 if(isset($url['host'])){
  $host=strtolower((string)$url['host']);$expected=strtolower(trim(explode(',',(string)(getenv('VIRTUAL_HOST')?:''))[0]));
  if($host!==$expected)dcsaFail(422,'El enlace debe pertenecer a este Drive-Craft');
 }
 $lk='dc-upload-'.$uid;$lock=$pdo->prepare('SELECT GET_LOCK(?,15)');$lock->execute([$lk]);
 if((int)$lock->fetchColumn()!==1)dcsaFail(503,'Almacenamiento ocupado');
 $newPath=null;
 try{
  $q=$pdo->prepare('SELECT s.password_hash,s.expires_at,(s.expires_at IS NOT NULL AND s.expires_at <= NOW()) AS dc_is_expired,f.* FROM shares s JOIN files f ON f.id=s.file_id WHERE s.hash=? LIMIT 1');$q->execute([$token]);$src=$q->fetch(PDO::FETCH_ASSOC);
  if(!$src)dcsaFail(404,'Enlace revocado o inexistente');
  if((int)$src['dc_is_expired']===1)dcsaFail(410,'El enlace ha caducado');
  if(!empty($src['is_dir']))dcsaFail(422,'Este enlace corresponde a una carpeta, no a un archivo');
  if($src['deleted_at']!==null)dcsaFail(404,'El archivo compartido fue eliminado');
  $sessionKey='drivecraft_public_'.hash('sha256',$token);
  if(!empty($src['password_hash'])&&empty($_SESSION[$sessionKey]))dcsaFail(403,'Abre antes el enlace e introduce su contraseña');
  // El enlace no autoriza referencias a un archivo situado en una carpeta eliminada.
  $srcFolder=(int)$src['parent_id'];$seen=[];
  while($srcFolder>0){if(isset($seen[$srcFolder]))dcsaFail(404,'Enlace no disponible');$seen[$srcFolder]=1;
   $p=$pdo->prepare('SELECT parent_id,deleted_at FROM files WHERE id=? AND is_dir=1');$p->execute([$srcFolder]);$r=$p->fetch(PDO::FETCH_ASSOC);
   if(!$r||$r['deleted_at']!==null)dcsaFail(404,'Enlace no disponible');$srcFolder=(int)$r['parent_id'];
  }
  $stored=(string)$src['storage_name'];if(!dcsaName($stored))dcsaFail(404,'Archivo fuente no disponible');
  $srcPath=rtrim($STORAGE_DIR,'/').'/'.$stored;
  if(!is_file($srcPath)||is_link($srcPath))dcsaFail(404,'Archivo fuente no disponible');
  $name=(string)$src['name'];if($name===''||strlen($name)>240)dcsaFail(422,'Nombre de archivo inválido');
  $name=str_replace(["/","\\","\r","\n"],'_',$name);
  [$old,$versioned]=dc80Existing($pdo,$uid,$parent,$name,$mode);
  $size=(int)$src['size'];if($size<0||(int)filesize($srcPath)!==$size)dcsaFail(409,'El archivo fuente cambió; vuelve a intentarlo');
  $max=(int)($user['max_file_bytes']??0);if(!$admin&&$max>0&&$size>$max)dcsaFail(413,'Supera el tamaño máximo por archivo');
  $quota=(int)($user['quota_bytes']??0);
  if(!$admin&&$quota>0){
    $t=$pdo->prepare('SELECT COALESCE(SUM(size),0) FROM files WHERE owner_id=? AND is_dir=0');$t->execute([$uid]);$used=(float)$t->fetchColumn();
    $t=$pdo->prepare('SELECT COALESCE(SUM(v.size),0) FROM file_versions v JOIN files f ON f.id=v.file_id WHERE f.owner_id=?');$t->execute([$uid]);$used+=(float)$t->fetchColumn();
    if($old&&!$versioned&&$mode==='replace')$used-=(float)$old['size'];
   if($used+$size>$quota)dcsaFail(413,'Cuota insuficiente');
  }
  $storage=bin2hex(random_bytes(20)).'_shared';$newPath=rtrim($STORAGE_DIR,'/').'/'.$storage;
  if(!@link($srcPath,$newPath))dcsaFail(507,'No se pudo crear el hardlink; comprueba permisos y volumen');
  $pdo->beginTransaction();
  try{
   // Confirmar de nuevo el registro dentro de la transacción; no sobrescribir carpetas.
   [$now,$currentlyVersioned]=dc80Existing($pdo,$uid,$parent,$name,$mode);
   if(($now['id']??null)!==($old['id']??null)||$currentlyVersioned!==$versioned)throw new RuntimeException('Cambió el destino durante la importación');
   $digest=hash_file('sha256',$srcPath);
   $newId=dc80CommitExisting($pdo,$uid,$parent,$name,$old,$versioned,$mode,$storage,$size,(string)$src['mime_type'],$digest);
   $pdo->commit();
  }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
  if($old&&!$versioned&&$mode==='replace')@unlink(rtrim($STORAGE_DIR,'/').'/'.$old['storage_name']);
  echo json_encode(['success'=>true,'id'=>$newId,'message'=>'Archivo guardado en tu unidad mediante hardlink']);exit;
 }catch(Throwable $e){if($newPath&&is_file($newPath))@unlink($newPath);error_log('DC import_share: '.$e->getMessage());dcsaFail(500,'No se pudo guardar el enlace');}
 finally{$q=$pdo->prepare('SELECT RELEASE_LOCK(?)');$q->execute([$lk]);}
}
if($action==='delete_orphan'){
 if(!$admin)dcsaFail(403,'Solo administradores');
 if(($_POST['confirm']??'')!=='ELIMINAR')dcsaFail(422,'Confirmación incorrecta');
 $name=(string)($_POST['storage_name']??'');
 // Primera etapa, únicamente residuos de conversiones completadas; no toca otros tipos.
 if(!dcsaName($name)||!preg_match('/^[a-f0-9]{32}(?:[a-f0-9]{8})?(?:_[A-Za-z0-9][A-Za-z0-9._-]{0,180})?_compatible\.mp4$/D',$name))dcsaFail(422,'Este tipo de archivo no admite limpieza automática');
 $base=rtrim($STORAGE_DIR,'/').'/';$path=$base.$name;
 clearstatcache(true,$path);
 if(!is_file($path)||is_link($path))dcsaFail(404,'El residuo ya no existe');
 $before=lstat($path);if(!$before||($before['mtime']??time())>time()-3600)dcsaFail(409,'Archivo demasiado reciente para una eliminación segura');
 $a=$pdo->prepare('SELECT 1 FROM files WHERE storage_name=? LIMIT 1');$a->execute([$name]);if($a->fetchColumn())dcsaFail(409,'El archivo vuelve a estar referenciado');
 $a=$pdo->prepare('SELECT 1 FROM file_versions WHERE storage_name=? LIMIT 1');$a->execute([$name]);if($a->fetchColumn())dcsaFail(409,'Existe referencia de versión');
 $a=$pdo->prepare("SELECT 1 FROM dc_media_jobs WHERE status IN ('queued','processing','running') LIMIT 1");$a->execute();if($a->fetchColumn())dcsaFail(409,'Hay trabajos multimedia pendientes; reintenta después');
 clearstatcache(true,$path);$after=lstat($path);
 if(!$after||$after['dev']!==$before['dev']||$after['ino']!==$before['ino']||$after['size']!==$before['size'])dcsaFail(409,'El archivo cambió durante la comprobación');
 if(!@unlink($path)) {error_log('DC integrity: unlink failed for orphan '.$name);dcsaFail(500,'No se pudo eliminar el residuo; revisa permisos');}
 error_log('DC integrity: admin '.$uid.' removed verified orphan '.$name.' inode='.$before['ino'].' links='.$before['nlink']);
 echo json_encode(['success'=>true,'message'=>'Se retiró únicamente el nombre físico huérfano','remaining_links'=>max(0,(int)$before['nlink']-1)]);exit;
}
dcsaFail(400,'Acción desconocida');
