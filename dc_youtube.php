<?php
/* Drive-Craft 20.10.22: paquetes YouTube como carpetas y archivos reales de files. */
require __DIR__.'/config.php';
require_once __DIR__.'/auth.php';
require_once __DIR__.'/dc_activity_85m_hf2.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: private, no-store');
header('X-Content-Type-Options: nosniff');
$user=dcRequireUser();
if(!empty($user['must_change_password'])) {http_response_code(403);exit(json_encode(['error'=>'Cuenta no disponible']));}
$uid=(int)$user['id'];$admin=($user['role']??'')==='admin';
function dyFail(int $code,string $message): never {http_response_code($code);echo json_encode(['error'=>$message]);exit;}
function dyFolder(PDO $db,int $id,int $uid): array {
 if($id<1)dyFail(404,'Carpeta no encontrada');$seen=[];$first=null;
 while($id>0){if(isset($seen[$id])||count($seen)>128)dyFail(404,'Jerarquía inválida');$seen[$id]=1;
  $q=$db->prepare('SELECT id,parent_id,name,is_dir,is_album,owner_id,deleted_at FROM files WHERE id=?');$q->execute([$id]);$f=$q->fetch(PDO::FETCH_ASSOC);
  if(!$f||(int)$f['owner_id']!==$uid||!(int)$f['is_dir']||$f['deleted_at']!==null)dyFail(404,'Carpeta no disponible');if(!$first)$first=$f;$id=(int)$f['parent_id'];
 }return $first;
}
function dyMode(PDO $db,int $id,int $uid): bool {$q=$db->prepare('SELECT 1 FROM dc_youtube_folders WHERE folder_id=? AND owner_id=?');$q->execute([$id,$uid]);return (bool)$q->fetchColumn();}
function dyYid(string $raw): ?string {
 $raw=trim($raw);if($raw===''||strlen($raw)>2048)return null;
 $u=parse_url($raw);if(!is_array($u)||strtolower((string)($u['scheme']??''))!=='https'||isset($u['user'])||isset($u['pass'])||isset($u['port']))return null;
 $host=strtolower((string)($u['host']??''));$path=(string)($u['path']??'');$id='';
 if(in_array($host,['youtu.be','www.youtu.be'],true))$id=trim($path,'/');
 elseif(in_array($host,['youtube.com','www.youtube.com','m.youtube.com','music.youtube.com'],true)){
  if($path==='/watch'){parse_str((string)($u['query']??''),$params);$id=(string)($params['v']??'');}
  elseif(preg_match('~^/(?:shorts|live|embed)/([A-Za-z0-9_-]{11})/?$~D',$path,$m))$id=$m[1];
 }return preg_match('/^[A-Za-z0-9_-]{11}$/D',$id)?$id:null;
}
function dyRow(PDO $db,int $parent,int $uid,string $name): ?array {
 $q=$db->prepare('SELECT * FROM files WHERE parent_id=? AND owner_id=? AND name=? AND deleted_at IS NULL LIMIT 1');$q->execute([$parent,$uid,$name]);return $q->fetch(PDO::FETCH_ASSOC)?:null;
}
function dyRead(PDO $db,array $row,string $dir,int $max): string {
 $s=(string)($row['storage_name']??'');if(!(int)($row['is_dir']??0)&&preg_match('/^[a-zA-Z0-9_.-]+$/D',$s)&&((int)$row['size']<=$max)){
  $path=rtrim($dir,'/').'/'.$s;if(is_file($path)&&!is_link($path)){$v=file_get_contents($path,false,null,0,$max+1);if($v!==false&&strlen($v)<=$max)return $v;}
 }dyFail(409,'Contenido del paquete inválido');
}
function dyPackage(PDO $db,array $folder,int $uid,string $dir): ?array {
 $id=(int)$folder['id'];$url=dyRow($db,$id,$uid,'video.url');$meta=dyRow($db,$id,$uid,'metadata.json');
 if(!$url||!$meta||(int)$url['is_dir']||(int)$meta['is_dir'])return null;
 $raw=dyRead($db,$url,$dir,4096);if(!preg_match('/^\[InternetShortcut\]\r?\nURL=(https:\/\/[^\r\n]+)\r?$/mi',$raw,$match))return null;
 $youtube=dyYid(trim($match[1]));if(!$youtube)return null;
 $j=json_decode(dyRead($db,$meta,$dir,16384),true);if(!is_array($j)||($j['drivecraft_type']??'')!=='youtube-package-v1')return null;
 $attachment=null;$q=$db->prepare("SELECT id,name,size,mime_type FROM files WHERE parent_id=? AND owner_id=? AND is_dir=0 AND name NOT IN ('video.url','metadata.json') AND deleted_at IS NULL ORDER BY id");$q->execute([$id,$uid]);$extras=$q->fetchAll(PDO::FETCH_ASSOC);
 return ['id'=>$id,'name'=>$folder['name'],'youtube_id'=>$youtube,'title'=>(string)($j['title']??''),'description'=>(string)($j['description']??''),'created_at'=>$folder['created_at']??null,'attachments'=>$extras];
}
$GLOBALS['dyWritten']=[];$GLOBALS['dyOld']=[];
function dyContent(PDO $db,int $parent,int $uid,string $name,string $content,string $mime,string $dir): void {
 $row=dyRow($db,$parent,$uid,$name);$old=$row['storage_name']??null;
 $storage=bin2hex(random_bytes(16)).($name==='video.url'?'.url':'.json');$path=rtrim($dir,'/').'/'.$storage;
 $bytes=file_put_contents($path,$content,LOCK_EX);if($bytes===false||$bytes!==strlen($content)) { @unlink($path);throw new RuntimeException('No fue posible guardar el archivo');}
 $GLOBALS['dyWritten'][]=$path;if($old)$GLOBALS['dyOld'][]=rtrim($dir,'/').'/'.$old;
 if($row){$q=$db->prepare('UPDATE files SET storage_name=?,size=?,mime_type=? WHERE id=? AND owner_id=?');$q->execute([$storage,strlen($content),$mime,$row['id'],$uid]);}
 else {$q=$db->prepare('INSERT INTO files(parent_id,name,is_dir,mime_type,size,storage_name,has_thumb,has_list,owner_id) VALUES (?,?,0,?,?,?,0,0,?)');$q->execute([$parent,$name,$mime,strlen($content),$storage,$uid]);}

}
$action=(string)($_POST['action']??$_GET['action']??'');
if(!in_array($action,['status','list','modes','set_mode','add','edit','attach','remove','delete_attachment'],true))dyFail(400,'Acción inválida');
$write=in_array($action,['set_mode','add','edit','attach','remove','delete_attachment'],true);
if($write){if(($_SERVER['REQUEST_METHOD']??'')!=='POST')dyFail(405,'POST requerido');
 $csrf=(string)($_POST['csrf']??'');if(!$csrf||!hash_equals((string)($_SESSION['dc_csrf']??''),$csrf))dyFail(403,'CSRF inválido');
 if(!$admin&&empty($user['can_upload']))dyFail(403,'Sin permiso de escritura');
}
if($action==='modes'){
 $q=$pdo->prepare('SELECT y.folder_id FROM dc_youtube_folders y JOIN files f ON f.id=y.folder_id WHERE y.owner_id=? AND f.owner_id=? AND f.deleted_at IS NULL AND f.is_dir=1');
 $q->execute([$uid,$uid]);echo json_encode(['success'=>true,'ids'=>array_map('intval',$q->fetchAll(PDO::FETCH_COLUMN))]);exit;
}
$folder=(int)($_POST['folder_id']??$_GET['folder_id']??0);$f=dyFolder($pdo,$folder,$uid);$mode=dyMode($pdo,$folder,$uid);
if($action==='status'){echo json_encode(['success'=>true,'enabled'=>$mode]);exit;}
if($action==='set_mode'){$enabled=(string)($_POST['enabled']??'')==='1';
 if($enabled){require_once __DIR__.'/dc_special_modes_85m.php';if(dc85mConflict($pdo,$uid,$folder,'youtube'))dyFail(409,'Desactiva primero la clasificación actual de la carpeta');if((int)$f['is_album'])dyFail(409,'Desactiva antes el modo álbum');$pdo->prepare('INSERT IGNORE INTO dc_youtube_folders(folder_id,owner_id) VALUES (?,?)')->execute([$folder,$uid]);}
 else {
  $check=$pdo->prepare('SELECT 1 FROM dc_video_collections_65 c JOIN files f ON f.id=c.folder_id AND f.owner_id=c.owner_id WHERE f.parent_id=? AND c.owner_id=? LIMIT 1');
  $check->execute([$folder,$uid]);if($check->fetchColumn())dyFail(409,'Quita primero el modo colección de videos de las carpetas hijas');
  $pdo->prepare('DELETE FROM dc_youtube_folders WHERE folder_id=? AND owner_id=?')->execute([$folder,$uid]);
 }
 dcActivity85m($pdo,$uid,$folder,'special');
 echo json_encode(['success'=>true,'enabled'=>$enabled]);exit;
}
if($action==='list'){
 // Solo consultar 20 candidatos por página: evita leer metadata.json de toda la biblioteca.
 $from=' FROM files p JOIN files u ON u.parent_id=p.id AND u.owner_id=p.owner_id AND u.name=\'video.url\' AND u.is_dir=0 AND u.deleted_at IS NULL JOIN files m ON m.parent_id=p.id AND m.owner_id=p.owner_id AND m.name=\'metadata.json\' AND m.is_dir=0 AND m.deleted_at IS NULL WHERE p.parent_id=? AND p.owner_id=? AND p.is_dir=1 AND p.deleted_at IS NULL';
 $q=$pdo->prepare('SELECT COUNT(*)'.$from);$q->execute([$folder,$uid]);$total=(int)$q->fetchColumn();
 $pageSize=max(1,min(120,(int)($_GET['page_size']??20)));$pages=max(1,(int)ceil($total/$pageSize));$page=max(1,min($pages,(int)($_GET['page']??1)));$offset=($page-1)*$pageSize;
 $q=$pdo->prepare('SELECT p.id,p.name,p.created_at'.$from.' ORDER BY p.created_at DESC,p.id DESC LIMIT '.(int)$pageSize.' OFFSET '.(int)$offset);$q->execute([$folder,$uid]);
 $packs=[];foreach($q->fetchAll(PDO::FETCH_ASSOC) as $candidate){$p=dyPackage($pdo,$candidate,$uid,$STORAGE_DIR);if($p)$packs[]=$p;}
 echo json_encode(['success'=>true,'links'=>$packs,'total'=>$total,'pages'=>$pages,'page'=>$page,'enabled'=>$mode]);exit;
}
if(!$mode&&in_array($action,['add','edit','attach','delete_attachment'],true))dyFail(409,'Esta carpeta no está clasificada como YouTube Links');
if($action==='add'){
 $youtube=dyYid((string)($_POST['url']??''));if(!$youtube)dyFail(422,'URL de YouTube inválida');
 $title=trim((string)($_POST['title']??''));$desc=trim((string)($_POST['description']??''));
 if(mb_strlen($title)>240||mb_strlen($desc)>4000)dyFail(422,'Título o descripción demasiado largos');
 $lock='dc-youtube-'.$uid;$pdo->prepare('SELECT GET_LOCK(?,15)')->execute([$lock]);
 try{
  $q=$pdo->prepare('SELECT id FROM files WHERE parent_id=? AND owner_id=? AND is_dir=1 AND deleted_at IS NULL');$q->execute([$folder,$uid]);
  foreach($q->fetchAll(PDO::FETCH_COLUMN) as $child){$r=$pdo->prepare('SELECT id,name,created_at FROM files WHERE id=?');$r->execute([$child]);$item=$dy=$r->fetch(PDO::FETCH_ASSOC);if($item){$p=dyPackage($pdo,$item,$uid,$STORAGE_DIR);if($p&&$p['youtube_id']===$youtube)dyFail(409,'Este video ya está guardado en la carpeta');}}
  $name='YouTube '.($title!==''?preg_replace('/[^\pL\pN _.-]/u','_',mb_substr($title,0,60)):$youtube);$name=trim($name,'. ');if($name==='')$name='YouTube '.$youtube;
  $original=$name;$s=1;while(dyRow($pdo,$folder,$uid,$name))$name=$original.' ('.(++$s).')';
  $pdo->beginTransaction();
  $q=$pdo->prepare("INSERT INTO files(parent_id,name,is_dir,mime_type,owner_id) VALUES (?,?,1,'directory',?)");$q->execute([$folder,$name,$uid]);$pid=(int)$pdo->lastInsertId();
  dyContent($pdo,$pid,$uid,'video.url',"[InternetShortcut]\r\nURL=https://www.youtube.com/watch?v=$youtube\r\n",'application/internet-shortcut',$STORAGE_DIR);
  dyContent($pdo,$pid,$uid,'metadata.json',json_encode(['drivecraft_type'=>'youtube-package-v1','title'=>$title,'description'=>$desc],JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR),'application/json',$STORAGE_DIR);
  $pdo->commit();echo json_encode(['success'=>true,'id'=>$pid]);exit;
 }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();foreach($GLOBALS['dyWritten'] as $path)@unlink($path);dyFail(409,'No se pudo crear el paquete: '.$e->getMessage());}
 finally{$pdo->prepare('SELECT RELEASE_LOCK(?)')->execute([$lock]);}
}
$id=(int)($_POST['id']??0);$q=$pdo->prepare('SELECT id,name,created_at FROM files WHERE id=? AND parent_id=? AND owner_id=? AND is_dir=1 AND deleted_at IS NULL');$q->execute([$id,$folder,$uid]);$item=$q->fetch(PDO::FETCH_ASSOC);$pack=$item?dyPackage($pdo,$item,$uid,$STORAGE_DIR):null;if(!$pack)dyFail(404,'Paquete no encontrado');
if($action==='edit'){
 $youtube=dyYid((string)($_POST['url']??''));if(!$youtube)dyFail(422,'URL inválida');$title=trim((string)($_POST['title']??''));$desc=trim((string)($_POST['description']??''));
 if(mb_strlen($title)>240||mb_strlen($desc)>4000)dyFail(422,'Título o descripción demasiado largos');
 try{$pdo->beginTransaction();dyContent($pdo,$id,$uid,'video.url',"[InternetShortcut]\r\nURL=https://www.youtube.com/watch?v=$youtube\r\n",'application/internet-shortcut',$STORAGE_DIR);
 dyContent($pdo,$id,$uid,'metadata.json',json_encode(['drivecraft_type'=>'youtube-package-v1','title'=>$title,'description'=>$desc],JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR),'application/json',$STORAGE_DIR);
 $pdo->commit();foreach($GLOBALS['dyOld'] as $path)if(is_file($path)&&!is_link($path))@unlink($path);echo json_encode(['success'=>true]);exit;
 }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();foreach($GLOBALS['dyWritten'] as $path)@unlink($path);dyFail(500,'Error actualizando paquete');}
}
if($action==='attach'){
 if(!isset($_FILES['attachment'])||$_FILES['attachment']['error']!==UPLOAD_ERR_OK||!is_uploaded_file($_FILES['attachment']['tmp_name']))dyFail(422,'No se recibió archivo adjunto');
 $file=$_FILES['attachment'];$name=basename((string)$file['name']);
 if(!$name||strlen($name)>240||in_array(strtolower($name),['video.url','metadata.json'],true)||str_contains($name,'\\')||preg_match('/[\x00-\x1f\x7f]/',$name))dyFail(422,'Nombre inválido');
 $size=(int)$file['size'];$max=(int)($user['max_file_bytes']??0);if(!$admin&&$max>0&&$size>$max)dyFail(413,'Archivo demasiado grande');
 $quota=(int)($user['quota_bytes']??0);if(!$admin&&$quota>0){$q=$pdo->prepare('SELECT COALESCE(SUM(size),0) FROM files WHERE owner_id=? AND is_dir=0');$q->execute([$uid]);if((float)$q->fetchColumn()+$size>$quota)dyFail(413,'Cuota insuficiente');}
 if(disk_free_space($STORAGE_DIR)<$size)dyFail(507,'Sin espacio disponible');
 $physical=bin2hex(random_bytes(20)).'.attachment';$path=rtrim($STORAGE_DIR,'/').'/'.$physical;
 if(!move_uploaded_file($file['tmp_name'],$path))dyFail(507,'No se pudo almacenar adjunto');
 try{$mime=(string)(new finfo(FILEINFO_MIME_TYPE))->file($path);$pdo->beginTransaction();
  // Permitir nombres repetidos sin sobrescribir archivos existentes; conservar los demás.
  $original=$name;$n=1;$q=$pdo->prepare('SELECT 1 FROM files WHERE parent_id=? AND owner_id=? AND name=? AND deleted_at IS NULL LIMIT 1');
  while(true){$q->execute([$id,$uid,$name]);if(!$q->fetchColumn())break;$ext=pathinfo($original,PATHINFO_EXTENSION);$stem=pathinfo($original,PATHINFO_FILENAME);$suffix=' ('.(++$n).')';$name=mb_substr($stem,0,240-strlen($suffix)-strlen($ext)-($ext!==''?1:0)).$suffix.($ext!==''?'.'.$ext:'');if($n>999)throw new RuntimeException('Demasiados archivos con el mismo nombre');}
  $q=$pdo->prepare('INSERT INTO files(parent_id,name,is_dir,mime_type,size,storage_name,has_thumb,has_list,owner_id) VALUES (?,?,0,?,?,?,0,0,?)');$q->execute([$id,$name,$mime,$size,$physical,$uid]);$pdo->commit();echo json_encode(['success'=>true]);exit;
 }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();@unlink($path);dyFail(500,'No se pudo registrar adjunto');}
}
if($action==='delete_attachment'){
 if(!$admin&&empty($user['can_delete']))dyFail(403,'Sin permiso para eliminar');
 $fileId=(int)($_POST['attachment_id']??0);$q=$pdo->prepare("UPDATE files SET deleted_at=NOW() WHERE id=? AND owner_id=? AND parent_id=? AND is_dir=0 AND deleted_at IS NULL AND name NOT IN ('video.url','metadata.json')");
 $q->execute([$fileId,$uid,$id]);if($q->rowCount()!==1)dyFail(404,'Adjunto no encontrado');echo json_encode(['success'=>true]);exit;
}
if($action==='remove'){
 if(!$admin&&empty($user['can_delete']))dyFail(403,'No tienes permiso de eliminación');
 $q=$pdo->prepare('UPDATE files SET deleted_at=NOW() WHERE id=? AND parent_id=? AND owner_id=? AND deleted_at IS NULL');$q->execute([$id,$folder,$uid]);if(!$q->rowCount())dyFail(404,'Paquete no encontrado');echo json_encode(['success'=>true]);exit;
}
