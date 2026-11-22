<?php
require __DIR__.'/config.php';
require __DIR__.'/auth.php';
require_once __DIR__.'/dc_global_hardlinks.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
$action=(string)($_POST['action'] ?? $_GET['action'] ?? '');
$me=dcRequireUser();
if ($action==='me') {echo json_encode(['id'=>$me['id'],'username'=>$me['username'],'role'=>$me['role'],'must_change_password'=>(bool)$me['must_change_password'],'csrf'=>$_SESSION['dc_csrf']??'']);exit;}
if ($_SERVER['REQUEST_METHOD']!=='POST')dcFail(405,'Se requiere POST');
dcCsrf();
if ($action==='change_password') {
 $old=(string)($_POST['old_password']??'');$new=(string)($_POST['new_password']??'');
 if(!password_verify($old,$me['password_hash']) || !dcPasswordPolicy($new))dcFail(422,'Contraseña actual inválida o nueva contraseña de menos de 12 caracteres');
 $pdo->prepare('UPDATE dc_users SET password_hash=?,must_change_password=0,session_version=session_version+1 WHERE id=?')->execute([password_hash($new,PASSWORD_DEFAULT),$me['id']]);
 session_regenerate_id(true);$_SESSION['dc_session_version']++;echo json_encode(['success'=>true]);exit;
}
if ($me['must_change_password'])dcFail(403,'Primero cambia tu contraseña');
if ($me['role']!=='admin')dcFail();
// Capacidad del volumen que aloja los archivos. Solo administradores.
if ($action==='storage_capacity') {
 $root=$STORAGE_DIR ?? (__DIR__.'/storage/');
 $total=@disk_total_space($root);
 $free=@disk_free_space($root);
 if ($total===false || $free===false)dcFail(503,'No se puede consultar el volumen de almacenamiento');
 echo json_encode(['total'=>(int)$total,'free'=>(int)$free]);exit;
}
if ($action==='media_config_get') {
 require_once __DIR__.'/dc_media_lib.php';echo json_encode(dcMediaSettings($pdo));exit;
}
if ($action==='media_config_save') {
 require_once __DIR__.'/dc_media_lib.php';
 $maximum=filter_var($_POST['max_mib']??null,FILTER_VALIDATE_INT);
 if($maximum===false||$maximum<1||$maximum>10240)dcFail(422,'Límite entre 1 y 10240 MiB');
 $enabled=($_POST['conversion_enabled']??'0')==='1';
 $automatic=($_POST['auto_conversion']??'0')==='1';
 $thumbs=($_POST['thumbnails_enabled']??'0')==='1';
 $q=$pdo->prepare('INSERT INTO dc_app_settings(setting_key,setting_value) VALUES(?,?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)');
 foreach(['dc_media_conversion_enabled'=>$enabled?'1':'0','dc_media_auto_conversion'=>$automatic?'1':'0','dc_media_thumbnails_enabled'=>$thumbs?'1':'0','dc_media_max_mib'=>(string)$maximum,'dc_local_video_max_mib'=>(string)$maximum] as $key=>$value)$q->execute([$key,$value]);
 echo json_encode(['success'=>true]);exit;
}
if ($action==='local_video_policy_get') {
 $q=$pdo->prepare("SELECT setting_value FROM dc_app_settings WHERE setting_key='dc_local_video_max_mib'");$q->execute();
 $local=(int)($q->fetchColumn()?:100);
 $q=$pdo->prepare("SELECT setting_value FROM dc_app_settings WHERE setting_key='dc_media_max_mib'");$q->execute();
 echo json_encode(['max_mib'=>max(1,min(10240,max($local,(int)($q->fetchColumn()?:0))))]);exit;
}
if ($action==='local_video_policy_save') {
 $max=filter_var($_POST['max_mib']??null,FILTER_VALIDATE_INT);
 if($max===false||$max<1||$max>10240)dcFail(422,'Límite permitido: 1 a 10240 MiB');
 $save=$pdo->prepare("INSERT INTO dc_app_settings(setting_key,setting_value) VALUES(?,?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)");
 foreach(['dc_local_video_max_mib','dc_media_max_mib'] as $key)$save->execute([$key,(string)$max]);
 echo json_encode(['success'=>true]);exit;
}
if ($action==='global_hardlinks_savings') {
 require_once __DIR__.'/dc_reuse_51.php';
 echo json_encode(dc51StorageSavings($pdo,$STORAGE_DIR,$THUMBS_DIR??null),JSON_THROW_ON_ERROR);exit;
}
if ($action==='hash_backfill_status') {
 try {
  $pending=(int)$pdo->query("SELECT COUNT(*) FROM files WHERE is_dir=0 AND deleted_at IS NULL AND storage_name IS NOT NULL AND storage_name<>'' AND (content_hash IS NULL OR head_hash IS NULL)")->fetchColumn();
  $indexed=(int)$pdo->query("SELECT COUNT(*) FROM files WHERE is_dir=0 AND deleted_at IS NULL AND content_hash IS NOT NULL AND head_hash IS NOT NULL")->fetchColumn();
  echo json_encode(['success'=>true,'pending'=>$pending,'indexed'=>$indexed]);
 } catch (Throwable $e) {
  error_log('Drive-Craft hash_backfill_status: '.$e->getMessage());
  dcFail(500,'No se pudo consultar el índice de hashes');
 }
 exit;
}
if ($action==='hash_backfill_run') {
 try {
  require_once __DIR__.'/dc_hash_backfill.php';
  if (!function_exists('dcHashBackfillBatch')) dcFail(500,'Rutina de indexado no disponible');
  $limit=filter_var($_POST['limit']??25,FILTER_VALIDATE_INT);
  if($limit===false||$limit<1)$limit=25;
  $limit=min(50,$limit);
  @set_time_limit(180);
  $root=isset($STORAGE_DIR)?(string)$STORAGE_DIR:(__DIR__.'/storage/');
  $result=dcHashBackfillBatch($pdo,$root,$limit);
  echo json_encode($result);
 } catch (Throwable $e) {
  error_log('Drive-Craft hash_backfill_run: '.$e->getMessage());
  dcFail(500,'Indexado falló: '.$e->getMessage());
 }
 exit;
}
if ($action==='global_hardlinks_get') {
 echo json_encode(dcGlobalHardlinkSettings($pdo));exit;
}
if ($action==='global_hardlinks_save') {
 $enabled=($_POST['enabled']??'0')==='1'?'1':'0';
 $min=filter_var($_POST['min_mib']??null,FILTER_VALIDATE_INT);
 if($min===false||$min<1||$min>10240)dcFail(422,'Umbral fuera del rango permitido (1-10240 MiB)');
 $allowed=['audio','video','images','disk','installers','archives','pdf','documents','textcode'];
 $raw=explode(',',(string)($_POST['groups']??''));
 foreach($raw as $group)if($group!==''&&!in_array($group,$allowed,true))dcFail(422,'Grupo de archivo no autorizado');
 $groups=implode(',',array_unique(array_intersect($allowed,$raw)));
 $defaults=['audio'=>1,'video'=>100,'images'=>0,'disk'=>100,'installers'=>10,'archives'=>10,'pdf'=>1,'documents'=>1,'textcode'=>0];
 $minimums=[];
 foreach($defaults as $group=>$default){
   $rawMin=$_POST['min_'.$group]??$default;
   $v=filter_var($rawMin,FILTER_VALIDATE_INT);
   if($v===false||$v<0||$v>10240)dcFail(422,'Mínimo inválido para '.$group.' (0 a 10240 MiB)');
   $minimums[$group]=$v;
 }
 $st=$pdo->prepare('INSERT INTO dc_app_settings(setting_key,setting_value) VALUES (?,?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)');
 foreach(['global_hardlinks_enabled'=>$enabled,'global_hardlinks_min_mib'=>(string)$min,'global_hardlinks_groups'=>$groups,'global_hardlinks_min_by_group'=>json_encode($minimums,JSON_THROW_ON_ERROR)] as $key=>$value)$st->execute([$key,$value]);
 echo json_encode(['success'=>true]);exit;
}
if ($action==='list_users') {
 $users=$pdo->query('SELECT id,username,role,status,must_change_password,quota_bytes,max_file_bytes,can_share,can_upload,can_delete,receive_admin_limit_bytes,receive_limit_bytes,can_convert_video,created_at FROM dc_users ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
 echo json_encode(['users'=>$users,'multiuser_file_access_enabled'=>false]);exit;
}
// Estadísticas: suma lógica de ficheros, versiones y papelera por propietario.
if ($action==='storage_users') {
 $sql="SELECT u.id,u.username,u.status,u.role,u.quota_bytes,
   COALESCE(f.file_count,0) AS file_count,COALESCE(f.current_bytes,0) AS current_bytes,
   COALESCE(f.trashed_bytes,0) AS trashed_bytes,
   COALESCE(v.version_count,0) AS version_count,COALESCE(v.version_bytes,0) AS version_bytes
   FROM dc_users u
   LEFT JOIN (SELECT owner_id,COUNT(*) AS file_count,
     COALESCE(SUM(CASE WHEN is_dir=0 THEN size ELSE 0 END),0) AS current_bytes,
     COALESCE(SUM(CASE WHEN is_dir=0 AND deleted_at IS NOT NULL THEN size ELSE 0 END),0) AS trashed_bytes
     FROM files GROUP BY owner_id) f ON f.owner_id=u.id
   LEFT JOIN (SELECT f.owner_id,COUNT(*) AS version_count,COALESCE(SUM(v.size),0) AS version_bytes
     FROM file_versions v JOIN files f ON f.id=v.file_id GROUP BY f.owner_id) v ON v.owner_id=u.id
   ORDER BY (COALESCE(f.current_bytes,0)+COALESCE(v.version_bytes,0)) DESC,u.username";
 echo json_encode(['users'=>$pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC)]);exit;
}
if ($action==='create_user') {
 $name=trim((string)($_POST['username']??''));
 if(!preg_match('/^[a-zA-Z0-9_.-]{3,64}$/D',$name))dcFail(422,'Usuario inválido');
 $password=bin2hex(random_bytes(16));
 try {$pdo->prepare('INSERT INTO dc_users(username,password_hash) VALUES(?,?)')->execute([$name,password_hash($password,PASSWORD_DEFAULT)]);}
 catch(PDOException $e){dcFail(409,'El usuario ya existe');}
 echo json_encode(['success'=>true,'temporary_password'=>$password]);exit;
}

// Eliminación administrativa: el usuario objetivo queda bloqueado mediante FOR UPDATE
// mientras se transfieren sus referencias. Se exige auditoría instalada antes de operar.
if ($action === 'delete_user') {
    $targetId = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
    $filesMode = (string)($_POST['files_mode'] ?? '');
    $linksMode = (string)($_POST['links_mode'] ?? '');
    $typed = (string)($_POST['confirmation'] ?? '');
    if (!$targetId || $targetId === (int)$me['id'] ||
        !in_array($filesMode, ['transfer','delete'], true) ||
        !in_array($linksMode, ['transfer','revoke'], true) ||
        ($filesMode === 'delete' && $linksMode === 'transfer')) {
        dcFail(422, 'Opciones incompatibles o cuenta inválida');
    }
    $quarantined = [];
    $committed = false;
    try {
        $pdo->beginTransaction();
        $st = $pdo->prepare('SELECT id,username,role FROM dc_users WHERE id=? FOR UPDATE');
        $st->execute([$targetId]); $victim = $st->fetch(PDO::FETCH_ASSOC);
        if (!$victim) throw new RuntimeException('Usuario inexistente');
        if ($typed !== 'ELIMINAR ' . $victim['username']) throw new RuntimeException('Confirmación incorrecta');
        if ($victim['role'] === 'admin') {
            $cnt = (int)$pdo->query("SELECT COUNT(*) FROM dc_users WHERE role='admin' AND status='active'")->fetchColumn();
            if ($cnt <= 1) throw new RuntimeException('No se puede eliminar el último administrador');
        }
        // Falla si se detectan jerarquías mezcladas o archivos sin propietario.
        $foreign = $pdo->prepare('SELECT COUNT(*) FROM files f JOIN files p ON f.parent_id=p.id WHERE f.owner_id=? AND (p.owner_id IS NULL OR p.owner_id<>?)');
        $foreign->execute([$targetId,$targetId]);
        if ((int)$foreign->fetchColumn() > 0) throw new RuntimeException('Jerarquía con propietarios distintos; revisión manual necesaria');
        $rootsStmt = $pdo->prepare('SELECT id FROM files WHERE owner_id=? AND parent_id=0 ORDER BY id FOR UPDATE');
        $rootsStmt->execute([$targetId]); $roots = array_map('intval',$rootsStmt->fetchAll(PDO::FETCH_COLUMN));
        $countStmt = $pdo->prepare('SELECT COUNT(*) FROM files WHERE owner_id=?'); $countStmt->execute([$targetId]);
        $fileCount = (int)$countStmt->fetchColumn();
        // Validar que toda jerarquía del usuario llegue a su raíz, sin ciclos.
        $allRows=$pdo->prepare('SELECT id,parent_id,is_dir,storage_name FROM files WHERE owner_id=? FOR UPDATE');
        $allRows->execute([$targetId]);$items=$allRows->fetchAll(PDO::FETCH_ASSOC);
        $ids=[];
        foreach ($items as $it) $ids[(int)$it['id']]=$it;
        foreach ($items as $it) {
            $seen=[];$cursor=(int)$it['id'];
            while ($cursor!==0) {
                if (isset($seen[$cursor]) || !isset($ids[$cursor])) throw new RuntimeException('Árbol inválido; no se eliminó la cuenta');
                $seen[$cursor]=true;$cursor=(int)$ids[$cursor]['parent_id'];
            }
        }
        $destinationName = null;
        if ($filesMode === 'transfer' && $fileCount) {
            $base = mb_substr($victim['username'],0,65);
            $destinationName=$base;
            $check=$pdo->prepare('SELECT COUNT(*) FROM files WHERE owner_id=? AND parent_id=0 AND name=? AND deleted_at IS NULL');
            for($i=0;$i<1000;$i++) {
                $candidate=$i===0?$base:($base.' (recuperado '.$targetId.'-'.$i.')');
                $check->execute([$me['id'],$candidate]);
                if (!(int)$check->fetchColumn()) { $destinationName=$candidate;break; }
                if ($i===999)throw new RuntimeException('No se encontró nombre disponible');
            }
            $make=$pdo->prepare("INSERT INTO files(parent_id,name,is_dir,mime_type,size,owner_id,deleted_at) VALUES(0,?,1,'directory',0,?,NULL)");
            $make->execute([$destinationName,$me['id']]);$newRoot=(int)$pdo->lastInsertId();
            // Reubicar raíces primero y transferir todos los elementos, también los de papelera.
            if ($roots) {
                $upRoot=$pdo->prepare('UPDATE files SET parent_id=? WHERE id=? AND owner_id=?');
                foreach($roots as $rid)$upRoot->execute([$newRoot,$rid,$targetId]);
            }
            $pdo->prepare('UPDATE files SET owner_id=? WHERE owner_id=?')->execute([$me['id'],$targetId]);
        } elseif ($filesMode === 'delete') {
            // Primero aislar archivos físicos bajo nombres privados; si falla, revertir.
            // Se usa el mismo volumen para cada archivo. Tras COMMIT se purga la cuarentena.
            $physical=[];
            foreach($items as $it) {
                if ((int)$it['is_dir']) continue;
                $storage=(string)$it['storage_name'];
                if ($storage==='' || basename($storage)!==$storage || $storage==='.' || $storage==='..') throw new RuntimeException('Nombre físico inválido');
                $physical[] = [$STORAGE_DIR.$storage];
                $physical[] = [$THUMBS_DIR.$storage.'.jpg'];
                $physical[] = [$LISTS_DIR.$storage.'.list.gz'];
            }
            $v=$pdo->prepare('SELECT v.storage_name FROM file_versions v JOIN files f ON f.id=v.file_id WHERE f.owner_id=?');
            $v->execute([$targetId]);
            foreach($v->fetchAll(PDO::FETCH_COLUMN) as $name) {
                if (!$name || basename($name)!==$name) throw new RuntimeException('Versión con nombre inválido');
                $physical[]=[$STORAGE_DIR.$name];
            }
            $unique=[];
            foreach($physical as $entry) {
                $path=$entry[0]; if(isset($unique[$path]))continue;$unique[$path]=true;
                if (!file_exists($path))continue;
                if (!is_file($path))throw new RuntimeException('Ruta física inesperada');
                $staged=$path.'.dc-delete-'.bin2hex(random_bytes(12));
                if (!rename($path,$staged))throw new RuntimeException('No fue posible aislar archivos; sin cambios en MariaDB');
                $quarantined[]=[$path,$staged];
            }
            $pdo->prepare('DELETE v FROM file_versions v JOIN files f ON f.id=v.file_id WHERE f.owner_id=?')->execute([$targetId]);
            // Rechazar enlaces de terceros apuntando a elementos que se van a eliminar.
            $external=$pdo->prepare('SELECT COUNT(*) FROM shares s JOIN files f ON s.file_id=f.id WHERE f.owner_id=? AND s.owner_id<>?');
            $external->execute([$targetId,$targetId]);
            if ((int)$external->fetchColumn())throw new RuntimeException('Hay enlaces de otra cuenta hacia estos archivos');
            $otherReceives=$pdo->prepare('SELECT COUNT(*) FROM receive_links r JOIN files f ON r.folder_id=f.id WHERE f.owner_id=? AND r.owner_id<>?');
            $otherReceives->execute([$targetId,$targetId]);
            if ((int)$otherReceives->fetchColumn())throw new RuntimeException('Hay enlaces de recepción ajenos hacia estos archivos');
            $pdo->prepare('DELETE FROM files WHERE owner_id=?')->execute([$targetId]);
        }
        // Enlaces propios y métricas: transferir o revocar según la elección.
        if ($linksMode === 'transfer') {
            $pdo->prepare('UPDATE shares SET owner_id=? WHERE owner_id=?')->execute([$me['id'],$targetId]);
            $pdo->prepare('UPDATE receive_links SET owner_id=? WHERE owner_id=?')->execute([$me['id'],$targetId]);
        } else {
            $pdo->prepare('DELETE e FROM share_events e JOIN shares s ON e.share_id=s.id WHERE s.owner_id=?')->execute([$targetId]);
            $pdo->prepare('DELETE FROM shares WHERE owner_id=?')->execute([$targetId]);
            $pdo->prepare('DELETE FROM receive_links WHERE owner_id=?')->execute([$targetId]);
        }
        // Revocar vínculos residuales asociados a archivos eliminados.
        if ($filesMode === 'delete') {
            // No ejecutar limpiezas globales: los recursos de otras cuentas no se tocan.
        }
        $pdo->prepare('INSERT INTO dc_user_deletion_audit(actor_id,deleted_user_id,deleted_username,files_action,links_action,files_count,destination_folder,created_at) VALUES(?,?,?,?,?,?,?,NOW())')
            ->execute([$me['id'],$targetId,$victim['username'],$filesMode,$linksMode,$fileCount,$destinationName]);
        $pdo->prepare('DELETE FROM dc_users WHERE id=?')->execute([$targetId]);
        $pdo->commit();$committed=true;
        $cleanupErrors=0;
        foreach($quarantined as $pair)if(file_exists($pair[1]) && !@unlink($pair[1]))$cleanupErrors++;
        error_log('Drive-Craft: deleted user '.$targetId.', physical cleanup failures: '.$cleanupErrors);
        echo json_encode(['success'=>true,'files_count'=>$fileCount,'destination_folder'=>$destinationName,'cleanup_pending'=>$cleanupErrors]);exit;
    } catch(Throwable $e) {
        if (!$committed) {
            if($pdo->inTransaction())$pdo->rollBack();
            foreach(array_reverse($quarantined) as $pair)if(is_file($pair[1]))@rename($pair[1],$pair[0]);
        }
        error_log('Drive-Craft deletion error: '.$e->getMessage());
        dcFail(409,'No se completó la eliminación. Revisa el log del contenedor y la integridad del almacenamiento.');
    }
}
$id=(int)($_POST['id']??0);
$pdo->beginTransaction();
try {
 $st=$pdo->prepare('SELECT * FROM dc_users WHERE id=? FOR UPDATE');$st->execute([$id]);$target=$st->fetch(PDO::FETCH_ASSOC);
 if(!$target) { $pdo->rollBack();dcFail(404,'Usuario inexistente'); }
 $self=$id===(int)$me['id'];
 if($action==='save_user') {
  $name=trim((string)($_POST['username']??''));$role=(string)($_POST['role']??'user');$status=(string)($_POST['status']??'active');
  if(!preg_match('/^[a-zA-Z0-9_.-]{3,64}$/D',$name)||!in_array($role,['user','admin'],true)||!in_array($status,['active','suspended'],true)) { $pdo->rollBack();dcFail(422,'Datos inválidos'); }
  if($self && ($role!=='admin'||$status!=='active')) { $pdo->rollBack();dcFail(422,'No puedes desactivarte o quitarte el rol'); }
  if($target['role']==='admin' && ($role!=='admin'||$status!=='active')) {
   $n=(int)$pdo->query("SELECT COUNT(*) FROM dc_users WHERE role='admin' AND status='active'")->fetchColumn();
   if($n<=1){$pdo->rollBack();dcFail(422,'Debe existir al menos un administrador activo');}
  }
  $quota=($_POST['quota_bytes']??'')===''?null:filter_var($_POST['quota_bytes'],FILTER_VALIDATE_INT,['options'=>['min_range'=>0]]);
  $max=($_POST['max_file_bytes']??'')===''?null:filter_var($_POST['max_file_bytes'],FILTER_VALIDATE_INT,['options'=>['min_range'=>0]]);
  if($quota===false||$max===false){$pdo->rollBack();dcFail(422,'Límites inválidos');}
  // Proteger también el endpoint frente a POST alterados, sin depender del selector JS.
  $volumeTotal=@disk_total_space($STORAGE_DIR ?? (__DIR__.'/storage/'));
  if($volumeTotal===false){$pdo->rollBack();dcFail(503,'Capacidad de almacenamiento no disponible');}
  if(($quota!==null && $quota>$volumeTotal) || ($max!==null && $max>$volumeTotal)){
   $pdo->rollBack();dcFail(422,'El límite supera la capacidad total del almacenamiento');
  }
  if($quota!==null && $quota>0 && $max!==null && $max>$quota){
   $pdo->rollBack();dcFail(422,'El tamaño máximo por archivo no puede superar la cuota asignada');
  }
  $recvAdmin=($_POST['receive_admin_limit_bytes']??'')===''?null:filter_var($_POST['receive_admin_limit_bytes'],FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
  if($recvAdmin===false || ($recvAdmin!==null && ($recvAdmin>$volumeTotal || $recvAdmin>10737418240))){$pdo->rollBack();dcFail(422,'Máximo de recepción inválido');}
  if($recvAdmin!==null && $max!==null && $max>0) $recvAdmin=min($recvAdmin,$max);
  $permissions=[];foreach(['can_share','can_upload','can_delete','can_convert_video'] as $field)$permissions[]=($_POST[$field]??'0')==='1'?1:0;
  $st=$pdo->prepare('UPDATE dc_users SET username=?,role=?,status=?,quota_bytes=?,max_file_bytes=?,can_share=?,can_upload=?,can_delete=?,can_convert_video=?,receive_admin_limit_bytes=?,session_version=session_version+1 WHERE id=?');
  $st->execute([$name,$role,$status,$quota,$max,...$permissions,$recvAdmin,$id]);
  if($self){$_SESSION['dc_session_version']++;}
  $result=['success'=>true];
 } elseif($action==='reset_password') {
  $password=bin2hex(random_bytes(16));
  $pdo->prepare('UPDATE dc_users SET password_hash=?,must_change_password=1,session_version=session_version+1 WHERE id=?')->execute([password_hash($password,PASSWORD_DEFAULT),$id]);
  $result=['success'=>true,'temporary_password'=>$password];

 } else {$pdo->rollBack();dcFail(400,'Acción desconocida');}
 $pdo->commit();echo json_encode($result);exit;
} catch(Throwable $e) {if($pdo->inTransaction())$pdo->rollBack();dcFail(500,'No se pudo completar la operación');}
