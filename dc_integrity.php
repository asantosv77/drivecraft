<?php
// Auditoría de integridad: solo lectura, accesible exclusivamente a administradores.
require __DIR__ . '/config.php';
require __DIR__ . '/auth.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
$user = dcRequireUser();
if (($user['role'] ?? '') !== 'admin' || !empty($user['must_change_password'])) dcFail(403, 'Acceso restringido');
$method=($_SERVER['REQUEST_METHOD']??'GET');
if($method==='POST'){
 dcCsrf();
 if(($_POST['action']??'')!=='clean_orphan_internal_shares'||($_POST['confirmation']??'')!=='CORREGIR')dcFail(422,'Confirmación inválida');
 try{
  $pdo->beginTransaction();
  // Nunca eliminar archivos físicos; solo permisos cuyos usuarios o archivos ya no existen.
  $sql="DELETE s FROM dc_internal_shares s LEFT JOIN dc_users u ON u.id=s.recipient_id LEFT JOIN dc_users o ON o.id=s.owner_id LEFT JOIN files f ON f.id=s.file_id AND f.owner_id=s.owner_id WHERE u.id IS NULL OR o.id IS NULL OR f.id IS NULL";
  // Quitar primero los accesos directos dependientes, para respetar posibles claves foráneas RESTRICT.
  $m=$pdo->exec("DELETE c FROM dc_internal_shortcuts c LEFT JOIN dc_internal_shares s ON s.id=c.share_id LEFT JOIN dc_users u ON u.id=s.recipient_id LEFT JOIN dc_users o ON o.id=s.owner_id LEFT JOIN files f ON f.id=s.file_id AND f.owner_id=s.owner_id WHERE s.id IS NULL OR u.id IS NULL OR o.id IS NULL OR f.id IS NULL");
  $n=$pdo->exec($sql);
  $pdo->commit();
  echo json_encode(['success'=>true,'removed_shares'=>$n,'removed_shortcuts'=>$m]);exit;
 }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();error_log('Drive-Craft orphan share repair: '.$e->getMessage());dcFail(500,'No se pudo corregir la integridad de comparticiones');}
}
if($method!=='GET')dcFail(405,'Método no permitido');
set_time_limit(90);
$limit = 10000;
$examplesLimit = 60;
$issues = [];
$counts = [];
function dcRecordIssue(string $kind, array $row): void {
    global $issues, $counts, $examplesLimit;
    $counts[$kind] = ($counts[$kind] ?? 0) + 1;
    if (count($issues) < $examplesLimit) $issues[] = ['type' => $kind] + $row;
}
try {
    $rows = $pdo->query('SELECT id,parent_id,is_dir,owner_id,storage_name,size FROM files ORDER BY id LIMIT '.($limit+1))->fetchAll(PDO::FETCH_ASSOC);
    $truncated = count($rows) > $limit;
    if ($truncated) array_pop($rows);
    $ids = []; $physicalRefs = [];
    foreach ($rows as $r) $ids[(int)$r['id']] = $r;
    $owners = array_fill_keys(array_map('intval', $pdo->query('SELECT id FROM dc_users')->fetchAll(PDO::FETCH_COLUMN)), true);
    foreach ($rows as $r) {
        $id = (int)$r['id'];
        $parent = (int)$r['parent_id'];
        if ($parent !== 0 && !isset($ids[$parent]) && !$truncated) dcRecordIssue('parent_missing', ['id'=>$id,'parent_id'=>$parent]);
        if ($r['owner_id'] === null || !isset($owners[(int)$r['owner_id']])) dcRecordIssue('owner_missing', ['id'=>$id]);
        if ($parent !== 0 && isset($ids[$parent])) {
            if (!(int)$ids[$parent]['is_dir']) dcRecordIssue('parent_is_file', ['id'=>$id,'parent_id'=>$parent]);
            if ($r['owner_id'] !== $ids[$parent]['owner_id']) dcRecordIssue('mixed_ownership', ['id'=>$id,'parent_id'=>$parent]);
        }
        if ((int)$r['is_dir'] !== 0) continue;
        $stored = (string)$r['storage_name'];
        if ($stored === '' || basename($stored) !== $stored || $stored === '.' || $stored === '..') {
            dcRecordIssue('invalid_storage_name', ['id'=>$id]); continue;
        }
        $physicalRefs[$stored] = true;
        $path = $STORAGE_DIR . $stored;
        if (!is_file($path) || is_link($path)) dcRecordIssue('file_missing', ['id'=>$id]);
        elseif ((int)$r['size'] !== filesize($path)) dcRecordIssue('size_mismatch', ['id'=>$id,'database_size'=>(int)$r['size'],'disk_size'=>filesize($path)]);
    }
    $versions = $pdo->query('SELECT v.id,v.file_id,v.storage_name,v.size FROM file_versions v ORDER BY v.id LIMIT '.($limit+1))->fetchAll(PDO::FETCH_ASSOC);
    if (count($versions)>$limit) {$truncated=true;array_pop($versions);}
    foreach ($versions as $v) {
        $id=(int)$v['id']; $stored=(string)$v['storage_name'];
        if (!isset($ids[(int)$v['file_id']]) && !$truncated) dcRecordIssue('version_owner_file_missing',['version_id'=>$id,'file_id'=>(int)$v['file_id']]);
        if ($stored === '' || basename($stored)!==$stored || $stored==='.' || $stored==='..') {dcRecordIssue('invalid_version_storage',['version_id'=>$id]);continue;}
        $physicalRefs[$stored]=true;
        $path=$STORAGE_DIR.$stored;
        if (!is_file($path) || is_link($path)) dcRecordIssue('version_missing',['version_id'=>$id]);
        elseif ((int)$v['size']!==filesize($path)) dcRecordIssue('version_size_mismatch',['version_id'=>$id]);
    }
    // Comparticiones internas huérfanas: reportar sin afectar datos de archivos.
    $invalidShares=(int)$pdo->query("SELECT COUNT(*) FROM dc_internal_shares s LEFT JOIN dc_users u ON u.id=s.recipient_id LEFT JOIN dc_users o ON o.id=s.owner_id LEFT JOIN files f ON f.id=s.file_id AND f.owner_id=s.owner_id WHERE u.id IS NULL OR o.id IS NULL OR f.id IS NULL")->fetchColumn();
    if($invalidShares>0)dcRecordIssue('orphan_internal_shares',['count'=>$invalidShares]);
    $invalidShortcuts=(int)$pdo->query('SELECT COUNT(*) FROM dc_internal_shortcuts c LEFT JOIN dc_internal_shares s ON s.id=c.share_id WHERE s.id IS NULL')->fetchColumn();
    if($invalidShortcuts>0)dcRecordIssue('orphan_internal_shortcuts',['count'=>$invalidShortcuts]);

    // --- Activos auxiliares de módulos (fuera de files / file_versions) ---
    // Colecciones de vídeo (v65): portadas y sus .thumb.webp referenciadas en cover_storage.
    // Importación por URL (v68): dc-url68-* son solo temporales de descarga; si quedan en disco, son restos.
    $auxRefs = []; // storage_name => true (referenciados por un módulo y no deben listarse como basura)
    $hasVc65 = (bool)$pdo->query("SHOW TABLES LIKE 'dc_video_collections_65'")->fetchColumn();
    if ($hasVc65) {
        $covers = $pdo->query(
            "SELECT id, owner_id, cover_storage FROM dc_video_collections_65 WHERE cover_storage IS NOT NULL AND cover_storage <> ''"
        )->fetchAll(PDO::FETCH_ASSOC);
        foreach ($covers as $c) {
            $stored = (string)$c['cover_storage'];
            $cid = (int)$c['id'];
            // Nombre esperado: dc-vc65-{40 hex}.{jpg|jpeg|png|webp}
            if (!preg_match('/^dc-vc65-[a-f0-9]{40}\.(?:jpg|jpeg|png|webp)$/D', $stored)
                || basename($stored) !== $stored) {
                dcRecordIssue('invalid_video_collection_cover', [
                    'collection_id' => $cid,
                    'storage_name' => $stored,
                    'status' => 'review_required',
                ]);
                continue;
            }
            $auxRefs[$stored] = true;
            // Miniatura derivada generada junto a la portada (mismo prefijo, sufijo .thumb.webp).
            $thumb = preg_replace('/\.(?:jpg|jpeg|png|webp)$/D', '.thumb.webp', $stored);
            if (is_string($thumb) && $thumb !== '') $auxRefs[$thumb] = true;
            $path = $STORAGE_DIR . $stored;
            if (!is_file($path) || is_link($path)) {
                dcRecordIssue('video_collection_cover_missing', [
                    'collection_id' => $cid,
                    'storage_name' => $stored,
                    'status' => 'review_required',
                ]);
            }
        }
    }

    // Solo se examina el directorio de datos principal; no se modifica ni se intenta recuperar nada.
    $scanCount=0;
    if (is_dir($STORAGE_DIR) && ($dir=opendir($STORAGE_DIR))) {
        try {while (($name=readdir($dir))!==false) {
            if ($name==='.'||$name==='..') continue;
            if (++$scanCount>$limit) {$truncated=true;break;}
            if (!is_file($STORAGE_DIR.$name) || is_link($STORAGE_DIR.$name)) continue;
            // Temporales de subida / ensamblado no se diagnostican como huérfanos.
            if (preg_match('/^dc_user_.*\.(?:part|json)$/', $name)) continue;
            if (isset($physicalRefs[$name]) || isset($auxRefs[$name])) continue;

            // Portadas/miniaturas de colecciones sin fila en cover_storage → sí reportar.
            if (preg_match('/^dc-vc65-/', $name)) {
                dcRecordIssue('orphan_video_collection_asset', [
                    'storage_name' => $name,
                    'status' => 'review_required',
                    'reason' => 'Portada o miniatura de colección de vídeo sin referencia activa',
                ]);
                continue;
            }
            // Restos de importación externa (tempnam con prefijo dc-url68-).
            if (preg_match('/^dc-url68-/', $name)) {
                dcRecordIssue('orphan_import_temp', [
                    'storage_name' => $name,
                    'status' => 'review_required',
                    'reason' => 'Temporal de importación por URL no finalizado o no limpiado',
                ]);
                continue;
            }
            dcRecordIssue('unreferenced_physical_file', ['storage_name'=>$name,'status'=>'review_required']);
        }} finally {closedir($dir);}
    }
    echo json_encode(['success'=>true,'read_only'=>true,'truncated'=>$truncated,'checked_files'=>count($rows),'checked_versions'=>count($versions),'scanned_storage_entries'=>$scanCount,'issue_counts'=>$counts,'examples'=>$issues],JSON_INVALID_UTF8_SUBSTITUTE);
} catch (Throwable $e) {error_log('Drive-Craft integrity scan: '.$e->getMessage());dcFail(500,'No se pudo completar el informe de integridad');}
