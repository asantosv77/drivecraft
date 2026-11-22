<?php
require_once __DIR__.'/dc_media_tools_lib_dcft.php';
function dcScopedSearchDcft(PDO $db,int $uid):never {
 $root=filter_var($_GET['scope_root']??'',FILTER_VALIDATE_INT);if(!$root||$root<1)dcFail(422,'Carpeta de búsqueda inválida');
 try{dcmtOwned($db,$uid,$root,true);if(!dcmtIsScope($db,$uid,$root))dcFail(422,'La búsqueda acotada está disponible en bibliotecas multimedia');}catch(Throwable $e){dcFail(404,'Carpeta no disponible');}
 $text=trim((string)($_GET['q']??''));if($text===''||mb_strlen($text)>200){echo json_encode(['files'=>[]]);exit;}
 $case=($_GET['cs']??'')==='1';$type=(string)($_GET['type']??'');if(!in_array($type,['','application/pdf','image/','video/','audio/','text/'],true))dcFail(422,'Tipo inválido');
 $min=max(0,(int)($_GET['min_size']??0));$max=isset($_GET['max_size'])&&$_GET['max_size']!==''?max(0,(int)$_GET['max_size']):PHP_INT_MAX;
 $sql="WITH RECURSIVE dc_scope AS (
 SELECT id,is_dir,0 AS depth FROM files WHERE id=? AND owner_id=? AND is_dir=1 AND deleted_at IS NULL
 UNION ALL SELECT f.id,f.is_dir,s.depth+1 FROM files f JOIN dc_scope s ON f.parent_id=s.id AND s.is_dir=1 WHERE f.owner_id=? AND f.deleted_at IS NULL AND s.depth<128
 ) SELECT f.* FROM files f JOIN dc_scope s ON s.id=f.id WHERE f.id<>? AND f.owner_id=? AND f.deleted_at IS NULL AND f.name LIKE ".($case?'BINARY ':'')."?";
 $params=[$root,$uid,$uid,$root,$uid,'%'.$text.'%'];
 if($type!==''){$sql.=' AND f.is_dir=0 AND f.mime_type LIKE ?';$params[]=$type.'%';}
 $sql.=' AND (f.is_dir=1 OR (f.size>=? AND f.size<=?)) ORDER BY f.is_dir DESC,f.name ASC LIMIT 301';$params[]=$min;$params[]=$max;
 $q=$db->prepare($sql);$q->execute($params);$items=$q->fetchAll(PDO::FETCH_ASSOC);$limited=count($items)>300;$items=array_slice($items,0,300);
 foreach($items as &$f)$f['ruta_virtual']=dcVirtualPath((int)$f['id'],$db,$uid);unset($f);
 echo json_encode(['files'=>dcVersionBadges($items,$db),'limited'=>$limited,'scope_root'=>$root]);exit;
}
