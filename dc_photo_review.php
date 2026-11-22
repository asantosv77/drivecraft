<?php
// Incluido por api.php DESPUÉS de autenticación y CSRF.
if (!isset($pdo,$dcOwnerId,$STORAGE_DIR) || !function_exists('dcReadFileGuard')) {http_response_code(404);exit;}
if ($action==='photo_review_list') {
    $filterFolder=filter_var($_GET['folder_id']??0,FILTER_VALIDATE_INT);
    $filterFolder=$filterFolder===false?0:(int)$filterFolder;
    $q=$pdo->prepare("SELECT n.id,n.name,n.size,n.mime_type,n.photo_review_target_id AS target_id,t.name AS target_name,t.size AS target_size,t.mime_type AS target_mime,n.image_dhash,t.image_dhash AS target_hash FROM files n LEFT JOIN files t ON t.id=n.photo_review_target_id AND t.owner_id=n.owner_id AND t.deleted_at IS NULL WHERE n.owner_id=? AND n.photo_review_status='pending' AND n.deleted_at IS NULL AND (?=0 OR n.parent_id=?) ORDER BY n.id DESC LIMIT 100");
    $q->execute([$dcOwnerId,$filterFolder,$filterFolder]);
    $items=[];
    foreach($q->fetchAll(PDO::FETCH_ASSOC) as $row) {
        if (!dcOwnedTree((int)$row['id'],$pdo,$dcOwnerId)) continue;
        $distance=($row['image_dhash'] && $row['target_hash']) ? dcPhotoDistance((string)$row['image_dhash'],(string)$row['target_hash']) : null;
        $in=$pdo->prepare('SELECT storage_name FROM files WHERE id=? AND owner_id=?');$in->execute([(int)$row['id'],$dcOwnerId]);
        $inStorage=$in->fetchColumn();$inSize=$inStorage?@getimagesize($STORAGE_DIR.$inStorage):false;
        $tgSize=false;
        if ($row['target_id']) {$tg=$pdo->prepare('SELECT storage_name FROM files WHERE id=? AND owner_id=?');$tg->execute([(int)$row['target_id'],$dcOwnerId]);$tgStorage=$tg->fetchColumn();if($tgStorage)$tgSize=@getimagesize($STORAGE_DIR.$tgStorage);}
        $row['reason']=$distance===null?'Conflicto por nombre o revisión manual':'Revisión por nombre o posible similitud visual';
        $row['new_dimensions']=$inSize?[$inSize[0],$inSize[1]]:null;
        $row['old_dimensions']=$tgSize?[$tgSize[0],$tgSize[1]]:null;
        unset($row['image_dhash'],$row['target_hash']);$row['distance']=$distance;
        $items[]=$row;
    }
    echo json_encode(['success'=>true,'items'=>$items]);exit;
}
if ($action==='photo_review_resolve') {
    $id=(int)($_POST['id']??0);$decision=(string)($_POST['decision']??'');
    if (!in_array($decision,['omit','keep','replace'],true)) dcFail(400,'Decisión inválida');
    dcReadFileGuard($id,$pdo,false,$dcOwnerId);
    $named='dc-upload-'.$dcOwnerId;
    $lock=$pdo->prepare('SELECT GET_LOCK(?,15)');$lock->execute([$named]);
    if ((int)$lock->fetchColumn()!==1) dcFail(503,'Almacenamiento ocupado');
    $toDelete=null;
    try {
        $pdo->beginTransaction();
        $q=$pdo->prepare("SELECT * FROM files WHERE id=? AND owner_id=? AND photo_review_status='pending' AND deleted_at IS NULL FOR UPDATE");
        $q->execute([$id,$dcOwnerId]);$incoming=$q->fetch(PDO::FETCH_ASSOC);
        if (!$incoming) throw new RuntimeException('Revisión inexistente');
        if ($decision==='keep') {
            $pdo->prepare('UPDATE files SET photo_review_status=NULL,photo_review_target_id=NULL WHERE id=?')->execute([$id]);
        } elseif ($decision==='omit') {
            $share=$pdo->prepare('SELECT COUNT(*) FROM shares WHERE file_id=?');$share->execute([$id]);
            if ((int)$share->fetchColumn()>0) throw new RuntimeException('No se puede omitir un archivo ya compartido');
            $versions=$pdo->prepare('SELECT COUNT(*) FROM file_versions WHERE file_id=?');$versions->execute([$id]);
            if ((int)$versions->fetchColumn()>0) throw new RuntimeException('No se puede omitir un archivo con versiones');
            $toDelete=$incoming['storage_name'];
            $pdo->prepare('DELETE FROM files WHERE id=? AND owner_id=?')->execute([$id,$dcOwnerId]);
        } else {
            $targetId=(int)$incoming['photo_review_target_id'];
            if ($targetId<=0 || $targetId===$id) throw new RuntimeException('Destino no disponible');
            $q=$pdo->prepare('SELECT * FROM files WHERE id=? AND owner_id=? AND deleted_at IS NULL AND is_dir=0 FOR UPDATE');
            $q->execute([$targetId,$dcOwnerId]);$target=$q->fetch(PDO::FETCH_ASSOC);
            if (!$target || (int)$target['parent_id']!==(int)$incoming['parent_id'] || !dcOwnedTree($targetId,$pdo,$dcOwnerId)) throw new RuntimeException('Destino no autorizado');
            $oldPath=$STORAGE_DIR.$target['storage_name'];$newPath=$STORAGE_DIR.$incoming['storage_name'];
            if (!is_file($oldPath)||!is_file($newPath)) throw new RuntimeException('Archivo físico no encontrado');
            // Mantener siempre la imagen anterior como versión recuperable.
            $pdo->prepare('INSERT INTO file_versions(file_id,storage_name,size,created_at) VALUES(?,?,?,NOW())')->execute([$targetId,$target['storage_name'],$target['size']]);
            $pdo->prepare('UPDATE files SET mime_type=?,size=?,storage_name=?,has_thumb=0,has_list=0,content_hash_algorithm=?,content_hash=?,image_dhash=?,image_phash=?,received_highlight=1 WHERE id=? AND owner_id=?')->execute([$incoming['mime_type'],$incoming['size'],$incoming['storage_name'],$incoming['content_hash_algorithm'],$incoming['content_hash'],$incoming['image_dhash'],$incoming['image_phash'],$targetId,$dcOwnerId]);
            $pdo->prepare('DELETE FROM files WHERE id=? AND owner_id=?')->execute([$id,$dcOwnerId]);
        }
        $pdo->commit();
        if ($toDelete && basename((string)$toDelete)===(string)$toDelete) @unlink($STORAGE_DIR.$toDelete);
        echo json_encode(['success'=>true]);exit;
    } catch(Throwable $e) {
        if ($pdo->inTransaction())$pdo->rollBack();
        dcFail(409,$e->getMessage());
    } finally {
        $pdo->prepare('SELECT RELEASE_LOCK(?)')->execute([$named]);
    }
}
