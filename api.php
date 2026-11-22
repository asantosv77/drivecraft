<?php
$DEBUG_MODE = false; // No mostrar rutas ni errores internos al cliente en producción.
if ($DEBUG_MODE) { ini_set('display_errors', 1); ini_set('display_startup_errors', 1); error_reporting(E_ALL); }

require_once __DIR__ . '/config.php';
require_once __DIR__.'/dc_thumbnail_cache_dcft.php';

// --- ENLACES PÚBLICOS: acceso restringido a la carpeta compartida ---
function publicIsInside($id, $root, $pdo) {
    $id = (int)$id; $root = (int)$root;
    $visited = [];
    while ($id > 0 && !isset($visited[$id])) {
        if ($id === $root) return true;
        $visited[$id] = true;
        $st = $pdo->prepare('SELECT parent_id FROM files WHERE id = ? AND is_dir = 1');
        $st->execute([$id]);
        $parent = $st->fetchColumn();
        if ($parent === false) return false;
        $id = (int)$parent;
    }
    return false;
}
function publicEscape($value) { return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function publicFail($status = 403) {
    http_response_code($status);
    header('Content-Type: text/plain; charset=utf-8');
    exit('Enlace inválido o acceso denegado.');
}
function publicDownload($file, $storageDir) {
    if (!$file || !empty($file['is_dir']) || empty($file['storage_name'])) publicFail(404);
    // storage_name debe ser un identificador de archivo, nunca una ruta arbitraria.
    $storageName = (string)$file['storage_name'];
    if (basename($storageName) !== $storageName || $storageName === '.' || $storageName === '..') publicFail(404);
    $path = $storageDir . $storageName;
    if (!is_file($path) || !is_readable($path)) publicFail(404);
    $name = str_replace(["\r", "\n", '"', '\\'], '_', basename((string)$file['name']));
    header('X-Content-Type-Options: nosniff');
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="' . $name . '"; filename*=UTF-8\'\'' . rawurlencode((string)$file['name']));
    header('Content-Length: ' . filesize($path));
    header('Cache-Control: private, no-store');
    readfile($path);
    exit;
}

function driveIsTrashed($id, $pdo) {
    $seen=[];
    while ((int)$id > 0 && !isset($seen[(int)$id])) {
        $seen[(int)$id]=true;
        $st=$pdo->prepare('SELECT parent_id, deleted_at FROM files WHERE id=?'); $st->execute([(int)$id]);
        $r=$st->fetch(PDO::FETCH_ASSOC);
        if (!$r || $r['deleted_at'] !== null) return true;
        $id=(int)$r['parent_id'];
    }
    return false;
}
function driveTreeFlag($id, $column, $pdo) {
    $seen=[];
    while ((int)$id>0 && !isset($seen[(int)$id])) {
        $seen[(int)$id]=true;
        $st=$pdo->prepare('SELECT parent_id, version_enabled FROM files WHERE id=? AND is_dir=1'); $st->execute([(int)$id]);
        $r=$st->fetch(PDO::FETCH_ASSOC);
        if (!$r) return false;
        if (!empty($r['version_enabled'])) return true;
        $id=(int)$r['parent_id'];
    }
    return false;
}
function driveValidateId($id, $pdo, $directory=false) {
    if ($id===0 && $directory) return true;
    if ($id<1 || driveIsTrashed($id,$pdo)) return false;
    if (!$directory) return true;
    $st=$pdo->prepare('SELECT is_dir FROM files WHERE id=?'); $st->execute([$id]);
    return (int)$st->fetchColumn()===1;
}


// Recepción pública aislada: no revela listados y rechaza escritura fuera de la carpeta vinculada.
if (isset($_GET['receive_hash'])) {
    $token=(string)$_GET['receive_hash'];
    if (!preg_match('/^[a-f0-9]{32}$/D',$token)) publicFail(404);
    $st=$pdo->prepare('SELECT * FROM receive_links WHERE hash=? AND (expires_at IS NULL OR expires_at>NOW())');
    $st->execute([$token]); $link=$st->fetch(PDO::FETCH_ASSOC);
    if (!$link || !driveValidateId((int)$link['folder_id'],$pdo,true) || (int)$link['folder_id']===0) publicFail(404);
    header('Cache-Control: no-store');header('Referrer-Policy: no-referrer');header('X-Content-Type-Options: nosniff');
    require __DIR__.'/dc_receive.php';exit;
}

if (isset($_GET['public_hash'])) {
    require_once __DIR__.'/dc_throttle.php';
    $hash = (string)$_GET['public_hash'];
    if (!preg_match('/^[a-f0-9]{32}$/D', $hash)) publicFail(404);
    $st = $pdo->prepare('SELECT s.*, (s.expires_at IS NOT NULL AND s.expires_at <= NOW()) AS dc_is_expired, f.name, f.mime_type, f.storage_name, f.is_dir FROM shares s JOIN files f ON s.file_id = f.id WHERE s.hash = ?');
    $st->execute([$hash]);
    $share = $st->fetch(PDO::FETCH_ASSOC);
    if (!$share || (int)$share['dc_is_expired'] === 1) publicFail(404);

    header('Referrer-Policy: no-referrer');
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Cache-Control: no-store');
    $sessionKey = 'drivecraft_public_' . hash('sha256', $hash);
    if ($share['password_hash'] && empty($_SESSION[$sessionKey])) {
        $dcShareThrottleKey = dcThrottleKey('share', $hash);
        dcThrottleCheck($pdo, $dcShareThrottleKey);
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['password'])) {
            if (password_verify((string)$_POST['password'], $share['password_hash'])) {
                dcThrottleClear($pdo, $dcShareThrottleKey);
                session_regenerate_id(true);
                $_SESSION[$sessionKey] = true;
                header('Location: ?public_hash=' . rawurlencode($hash), true, 303);
                exit;
            }
            dcThrottleFail($pdo, $dcShareThrottleKey);
        }
        header('Content-Type: text/html; charset=utf-8');
        echo '<!doctype html><html lang="es" data-theme="dark"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="color-scheme" content="dark"><title>Enlace protegido — Drive-Craft</title><link rel="stylesheet" href="public.css?v=19.6"></head><body class="public-page"><main class="public-login"><div class="public-brand">☁️ Drive-Craft</div><form method="post" class="public-panel"><h1>🔒 Enlace protegido</h1><p>Introduce la contraseña que te compartieron.</p><input name="password" type="password" required autofocus autocomplete="current-password" placeholder="Contraseña"><button class="public-btn" type="submit">Acceder</button></form></main></body></html>';
        exit;
    }
    // Las métricas distinguen apertura humana de navegación y descargas; las miniaturas no cuentan.
    $rootId = (int)$share['file_id'];

    if (driveIsTrashed($rootId, $pdo)) publicFail(404);
    if (empty($share['is_dir'])) {
        $pdo->prepare('INSERT INTO share_events(share_id,event_type,occurred_at) VALUES (?,\'download\',NOW())')->execute([$share['id']]);
        publicDownload($share,$STORAGE_DIR);
    }

    // El directorio solicitado debe ser la raíz compartida o descendiente suyo.
    $folderId = isset($_GET['folder']) ? filter_var($_GET['folder'], FILTER_VALIDATE_INT) : $rootId;
    if ($folderId === false || driveIsTrashed($folderId, $pdo) || !publicIsInside($folderId, $rootId, $pdo)) publicFail();
    if (isset($_GET['dl'])) {
        $fileId = filter_var($_GET['dl'], FILTER_VALIDATE_INT);
        if ($fileId === false) publicFail(404);
        $st = $pdo->prepare('SELECT * FROM files WHERE id = ? AND is_dir = 0');
        $st->execute([$fileId]);
        $file = $st->fetch(PDO::FETCH_ASSOC);
        // Un archivo se descarga solo si su carpeta padre está dentro de la rama autorizada.
        if (!$file || driveIsTrashed($fileId, $pdo) || !publicIsInside((int)$file['parent_id'], $rootId, $pdo)) publicFail();
        $pdo->prepare("INSERT INTO share_events(share_id,event_type,occurred_at) VALUES (?,'download',NOW())")->execute([$share['id']]);
        publicDownload($file, $STORAGE_DIR);
    }
    // Miniaturas públicas: sólo archivos pertenecientes a la rama de este enlace.
    // No reutilizar el endpoint privado `action=thumb`, que exige sesión.
    if (isset($_GET['preview'])) {
        $previewId = filter_var($_GET['preview'], FILTER_VALIDATE_INT);
        if ($previewId === false || (int)$previewId <= 0) publicFail(404);
        $st = $pdo->prepare('SELECT id, parent_id, name, storage_name, mime_type, is_dir, has_thumb FROM files WHERE id = ? AND is_dir = 0');
        $st->execute([$previewId]);
        $preview = $st->fetch(PDO::FETCH_ASSOC);
        if (!$preview || driveIsTrashed($previewId, $pdo) || !publicIsInside((int)$preview['parent_id'], $rootId, $pdo)) publicFail();
        $storageName = (string)$preview['storage_name'];
        if (!$storageName || basename($storageName) !== $storageName) publicFail(404);
        $thumbPath = $THUMBS_DIR . $storageName . '.webp';
        if (!is_file($thumbPath) || filesize($thumbPath) === 0) {
            if (stripos((string)$preview['mime_type'], 'pdf') !== false) {
                ensurePdfThumbnail($preview, $pdo, $STORAGE_DIR, $THUMBS_DIR);
            } elseif (dcIsImageFile($preview)) {
                ensureImageThumbnail($preview, $pdo, $STORAGE_DIR, $THUMBS_DIR);
            }
        }
        if (!is_file($thumbPath) || !is_readable($thumbPath)) publicFail(404);
        header('Content-Type: image/webp');
        header('Cache-Control: private, no-store');
        header('X-Content-Type-Options: nosniff');
        readfile($thumbPath);
        exit;
    }
    $pdo->prepare("INSERT INTO share_events(share_id,event_type,occurred_at) VALUES (?,'view',NOW())")->execute([$share['id']]);
    $st = $pdo->prepare('SELECT id, parent_id, name, is_dir, mime_type, has_thumb FROM files WHERE parent_id = ? AND deleted_at IS NULL ORDER BY is_dir DESC, name ASC');
    $st->execute([$folderId]);
    $items = $st->fetchAll(PDO::FETCH_ASSOC);
    $st = $pdo->prepare('SELECT name, parent_id FROM files WHERE id = ? AND is_dir = 1');
    $st->execute([$folderId]);
    $current = $st->fetch(PDO::FETCH_ASSOC);
    if (!$current) publicFail(404);
    $base = '?public_hash=' . rawurlencode($hash);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><html lang="es" data-theme="dark"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="color-scheme" content="dark"><title>Carpeta compartida — Drive-Craft</title><link rel="icon" href="favicon-32.png" type="image/png"><link rel="stylesheet" href="public.css?v=19.6"></head><body class="public-page">';
    echo '<div class="public-layout"><header class="public-topbar"><span class="public-brand">☁️ Drive-Craft</span><span class="public-label">Carpeta compartida</span></header><main class="public-panel"><div class="public-heading">';
    echo '<div><span class="public-kicker">Enlace público · Solo lectura</span><h1>📁 ' . publicEscape($current['name']) . '</h1></div>';
    if ($folderId !== $rootId) {
        $parent = (int)$current['parent_id'];
        if (!publicIsInside($parent, $rootId, $pdo)) $parent = $rootId;
        echo '<a class="public-up" href="' . publicEscape($base . '&folder=' . $parent) . '" title="Subir una carpeta" aria-label="Subir una carpeta"><svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linejoin="round" stroke-linecap="round"><path d="M9 14 4 9l5-5"/><path d="M4 9h10a6 6 0 0 1 6 6v4"/></svg> Volver</a>';
    }
    echo '</div><div class="public-grid file-view-grid">';
    if (!$items) echo '<p class="public-empty">Esta carpeta está vacía.</p>';
    foreach ($items as $item) {
        $isDir = !empty($item['is_dir']);
        $isPdf = !$isDir && stripos((string)$item['mime_type'], 'pdf') !== false;
        $url = $base . ($isDir ? '&folder=' : '&dl=') . (int)$item['id'];
        $thumbUrl = $base . '&preview=' . (int)$item['id'];
        $name = publicEscape($item['name']);
        $icon = $isDir ? '📁' : ($isPdf ? '📕' : '📄');
        $hasPreview = !$isDir && ($isPdf || !empty($item['has_thumb']));
        echo '<article class="file-item public-file">';
        if ($hasPreview) {
            echo '<a class="thumb-container" href="' . publicEscape($url) . '" aria-label="Descargar ' . $name . '"><img class="file-thumb" loading="lazy" src="' . publicEscape($thumbUrl) . '" alt="Vista previa" onerror="this.hidden=true;this.nextElementSibling.hidden=false"><span class="thumb-fallback" hidden>' . $icon . '</span>' . ($isPdf ? '<span class="thumb-badge pdf-badge">PDF</span>' : '') . '</a>';
        } else {
            echo '<a class="file-icon ' . ($isDir ? 'folder-icon' : '') . '" href="' . publicEscape($url) . '" aria-label="' . ($isDir ? 'Abrir ' : 'Descargar ') . $name . '">' . $icon . '</a>';
        }
        echo '<a class="file-name public-name" href="' . publicEscape($url) . '" title="' . $name . '">' . $name . '</a>';
        echo '</article>';
    }
    echo '</div></main><footer class="public-footer">Enlace compartido mediante Drive-Craft · Acceso limitado a esta carpeta</footer></div></body></html>';
    exit;
}

require_once __DIR__.'/auth.php';
require_once __DIR__.'/dc_global_hardlinks.php';
require_once __DIR__.'/dc_merkle.php';
require_once __DIR__.'/dc_activity_85m_hf2.php';
$dcLogged=dcRequireUser();
if ($dcLogged['must_change_password']) dcFail(403,'Cambia la contraseña inicial antes de continuar');
// Etapa 20.4: escritura limitada, validada por usuario y ancestros.
// Las rutas no auditadas continúan denegadas para cuentas estándar.
$dcIsAdmin = $dcLogged['role'] === 'admin';
$dcOwnerId = (int)$dcLogged['id'];
$dcReadActions = ['album_previews_dcft','list','tree','view','thumb','download','properties','favorites','recents','file_versions','settings','text_open_57','disk_space','search','upload_conflict','download_folder','get_shares','all_shares','trash_list','trash_tree_85h','list_receive','photo_review_list','media_jobs','media_settings','media_analyze','undo_status_85l','folder_modes_85m'];
$dcWriteActions = ['create_folder','create_text','text_save_57','rename','toggle_favorite','set_versions','upload_chunk','upload_dedup','delete','share','delete_share','revoke_all_shares_60','update_share','trash_restore','trash_restore_nested_85h','trash_purge','trash_empty','move','copy','clear_received_mark','clear_received_marks_folder','set_album','create_receive','revoke_receive','photo_review_resolve','media_enqueue','settings','undo_85l','clear_recents_85m','rotate_image'];
$dcRequestedAction = (string)($_GET['action'] ?? $_POST['action'] ?? '');
if (!$dcIsAdmin && !(($_SERVER['REQUEST_METHOD'] === 'GET' && in_array($dcRequestedAction,$dcReadActions,true)) || ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($dcRequestedAction,$dcWriteActions,true)))) {
    dcFail(403, 'Operación todavía reservada al administrador durante la fase de aislamiento');
}
// Comprobar el propietario de TODOS los ancestros, no solo el del elemento.
// Fail-closed ante owner_id NULL, ciclos, carpetas borradas o jerarquías mezcladas.
function dcOwnedTree(int $id, PDO $pdo, int $ownerId): bool {
    if ($id <= 0) return false;
    $seen=[];
    while ($id > 0) {
        if (isset($seen[$id])) return false;
        $seen[$id]=true;
        $st=$pdo->prepare('SELECT parent_id,owner_id,deleted_at FROM files WHERE id=? LIMIT 1');
        $st->execute([$id]); $r=$st->fetch(PDO::FETCH_ASSOC);
        if (!$r || $r['deleted_at'] !== null || $r['owner_id'] === null || (int)$r['owner_id'] !== $ownerId) return false;
        $id=(int)$r['parent_id'];
    }
    return true;
}
// Valida propiedad incluso en elementos eliminados (dcOwnedTree exige deleted_at=NULL).
function dcOwnedTreeIncludingTrash(int $id, PDO $pdo, int $ownerId): bool {
    if ($id <= 0) return false;
    $seen = [];
    while ($id > 0) {
        if (isset($seen[$id])) return false;
        $seen[$id] = true;
        $st = $pdo->prepare('SELECT parent_id,owner_id FROM files WHERE id=? LIMIT 1');
        $st->execute([$id]); $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row || $row['owner_id'] === null || (int)$row['owner_id'] !== $ownerId) return false;
        $id = (int)$row['parent_id'];
    }
    return true;
}
function dcRequirePolicy(array $user, bool $isAdmin, string $permission): void {
    if (!$isAdmin && empty($user[$permission])) dcFail(403, 'Permiso no concedido: ' . $permission);
}
function dcGuardShare(int $id, PDO $pdo, bool $isAdmin, int $ownerId): void {
    $st=$pdo->prepare('SELECT s.owner_id,s.file_id FROM shares s WHERE s.id=? LIMIT 1');
    $st->execute([$id]);$share=$st->fetch(PDO::FETCH_ASSOC);
    if (!$share) dcFail(404,'Enlace inexistente');
    if (!$isAdmin && ((int)$share['owner_id'] !== $ownerId || !dcOwnedTree((int)$share['file_id'],$pdo,$ownerId))) dcFail(404,'Enlace inexistente');
}
function dcOwnsTrashSubtree(int $root, PDO $pdo, int $ownerId): bool {
    $pending=[$root];$seen=[];
    $st=$pdo->prepare('SELECT id,owner_id FROM files WHERE parent_id=?');
    while ($pending) {
        $id=array_pop($pending);
        if(isset($seen[$id])) return false;
        $seen[$id]=true;
        $row=$pdo->prepare('SELECT owner_id FROM files WHERE id=?');$row->execute([$id]);
        $owner=$row->fetchColumn();
        if($owner===false || (int)$owner!==$ownerId) return false;
        $st->execute([$id]);
        foreach($st->fetchAll(PDO::FETCH_ASSOC) as $child) $pending[]=(int)$child['id'];
    }
    return true;
}
function dcReadFileGuard(int $id, PDO $pdo, bool $isAdmin, int $owner): void {
    if (!$isAdmin && !dcOwnedTree($id,$pdo,$owner)) dcFail(404,'Archivo no encontrado');
}

if ($_SERVER['REQUEST_METHOD']==='POST') dcCsrf();

$action = $_GET['action'] ?? $_POST['action'] ?? '';
if (in_array($action,['media_jobs','media_settings','media_analyze','media_enqueue'],true)) { require __DIR__.'/dc_media.php'; exit; }
if ($action==='photo_review_list' || $action==='photo_review_resolve') {
    function dcPhotoDistance(string $a,string $b): int {
        if (!preg_match('/^[0-9a-f]{16}$/D',$a)||!preg_match('/^[0-9a-f]{16}$/D',$b))return 65;
        $sum=0;for($i=0;$i<16;$i++)$sum+=substr_count(decbin(hexdec($a[$i])^hexdec($b[$i])),'1');return $sum;
    }
    require __DIR__.'/dc_photo_review.php';exit;
}


// v20: papelera, favoritos, recientes, versiones opcionales y configuración ligera.
// Límite global persistente de inspección TAR.GZ (en bytes).
// 0 desactiva la vista interna; valor por defecto: 5 MiB.
function dcTarGzPreviewLimit(PDO $pdo): int {
    $st=$pdo->prepare('SELECT setting_value FROM dc_app_settings WHERE setting_key=? LIMIT 1');
    $st->execute(['tar_gz_preview_max_bytes']);
    $value=$st->fetchColumn();
    if ($value===false) return 5*1024*1024;
    $bytes=filter_var($value,FILTER_VALIDATE_INT);
    return ($bytes!==false && $bytes>=0 && $bytes<=100*1024*1024) ? (int)$bytes : 5*1024*1024;
}
// Tamaño máximo global aceptado por fragmento; el administrador puede optar por 50 o 100 MiB.
// Cada petición debe cumplir también upload_max_filesize/post_max_size del servidor web.
function dcChunkMaxMiB(PDO $pdo): int {
    try {
        $q=$pdo->prepare('SELECT setting_value FROM dc_app_settings WHERE setting_key=? LIMIT 1');
        $q->execute(['upload_chunk_max_mib']);
        return (int)$q->fetchColumn()===100 ? 100 : 50;
    } catch (Throwable $e) { return 50; }
}
function dcIniBytes(string $raw): int {
    $raw=trim($raw);
    if (!preg_match('/^([0-9]+)\s*([KMG]?)$/i', $raw, $m)) return 0;
    $size=(int)$m[1];
    return $size * ([''=>1,'K'=>1024,'M'=>1048576,'G'=>1073741824][strtoupper($m[2])]??1);
}
function dcChunkEffectiveMiB(PDO $pdo): int {
    $max=dcChunkMaxMiB($pdo);
    $upload=dcIniBytes((string)ini_get('upload_max_filesize'));
    $post=dcIniBytes((string)ini_get('post_max_size'));
    if ($upload>0) $max=min($max, (int)floor($upload/1048576));
    // Reservar 1 MiB para metadatos multipart (aproximación conservadora).
    if ($post>0) $max=min($max, (int)floor(($post-1048576)/1048576));
    return max(0,$max);
}
function dcChunkCheck(array $file, PDO $pdo): int {
    if (($file['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK || !is_uploaded_file((string)($file['tmp_name']??''))) dcFail(400,'Fragmento ausente o rechazado por PHP');
    $cap=dcChunkEffectiveMiB($pdo)*1048576;
    $actual=(int)($file['size']??-1);
    if ($actual<0 || $actual>$cap) dcFail(413,'El fragmento supera el máximo autorizado');
    return $actual;
}

// Hash de contenido, calculado por PHP mediante lectura incremental desde disco.
// No confiar en hashes suministrados por el navegador.
function dcDedupConfig(PDO $pdo): array {
    $q=$pdo->prepare("SELECT setting_key,setting_value FROM dc_app_settings WHERE setting_key IN ('dedup_enabled','dedup_algorithm')");
    $q->execute(); $settings=$q->fetchAll(PDO::FETCH_KEY_PAIR);
    return [($settings['dedup_enabled']??'1')==='1','sha256'];
}
function dcDuplicateMatch(PDO $pdo,int $owner,int $size,string $algorithm,string $digest,?int $exclude=null): bool {
    $sql='SELECT id FROM files WHERE owner_id=? AND is_dir=0 AND size=? AND content_hash_algorithm=? AND content_hash=?';
    $params=[$owner,$size,$algorithm,$digest];
    if ($exclude!==null) { $sql.=' AND id<>?'; $params[]=$exclude; }
    $sql.=' LIMIT 1';
    $q=$pdo->prepare($sql);$q->execute($params);
    return (bool)$q->fetchColumn();
}

if ($action === 'settings') {
    if ($_SERVER['REQUEST_METHOD']==='POST') {
        $_SESSION['dc_global_search']=($_POST['global_search']??'0')==='1';
        if (array_key_exists('session_days_dcft',$_POST)) {
            $dcSessionDays=filter_var($_POST['session_days_dcft'],FILTER_VALIDATE_INT);
            if ($dcSessionDays===false || !in_array($dcSessionDays,dcSessionDaysAllowedDcft(),true)) dcFail(422,'Duración de sesión inválida');
            $pdo->prepare('UPDATE dc_users SET session_days_dcft=? WHERE id=?')->execute([$dcSessionDays,$dcOwnerId]);
            $_SESSION['dc_expires_at_dcft']=time()+$dcSessionDays*86400;
        }
        if (array_key_exists('receive_global_limit_mib',$_POST)) {
            if (!$dcIsAdmin) dcFail(403,'Solo administradores');
            $n=filter_var($_POST['receive_global_limit_mib'],FILTER_VALIDATE_INT);
            $disk=(int)disk_total_space($STORAGE_DIR);
            if ($n===false || $n<1 || $n>10240 || $n*1048576>$disk) dcFail(422,'Límite global inválido');
            $q=$pdo->prepare('INSERT INTO dc_app_settings(setting_key,setting_value) VALUES (?,?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)');
            $q->execute(['receive_global_limit_bytes',(string)($n*1048576)]);
        }
        if (array_key_exists('receive_limit_mib',$_POST)) {
            $n=filter_var($_POST['receive_limit_mib'],FILTER_VALIDATE_INT);
            if ($n===false || $n<1 || $n>10240) dcFail(422,'Límite personal inválido');
            $q=$pdo->prepare('SELECT setting_value FROM dc_app_settings WHERE setting_key=?');$q->execute(['receive_global_limit_bytes']);
            $global=(int)($q->fetchColumn() ?: 734003200);
            $cap=$global;
            if (!empty($dcLogged['receive_admin_limit_bytes'])) $cap=min($cap,(int)$dcLogged['receive_admin_limit_bytes']);
            if ($n*1048576>$cap) dcFail(422,'Supera el máximo asignado');
            $pdo->prepare('UPDATE dc_users SET receive_limit_bytes=? WHERE id=?')->execute([$n*1048576,$dcOwnerId]);
        }
        if (array_key_exists('dedup_enabled',$_POST) || array_key_exists('dedup_algorithm',$_POST)) {
            if (!$dcIsAdmin) dcFail(403,'Solo administradores pueden modificar la detección de duplicados');
            if (array_key_exists('dedup_enabled',$_POST)) {
                $enabled=(string)$_POST['dedup_enabled'];
                if (!in_array($enabled,['0','1'],true)) dcFail(422,'Valor inválido de detección');
                $s=$pdo->prepare('INSERT INTO dc_app_settings(setting_key,setting_value) VALUES (?,?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)');
                $s->execute(['dedup_enabled',$enabled]);
                $s->execute(['dedup_algorithm','sha256']);
            }
            if (array_key_exists('dedup_algorithm',$_POST)) {
                $algorithm=(string)$_POST['dedup_algorithm'];
                if ($algorithm !== 'sha256') dcFail(422,'Solo se permite SHA-256');
                $s=$pdo->prepare('INSERT INTO dc_app_settings(setting_key,setting_value) VALUES (?,?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)');
                $s->execute(['dedup_algorithm',$algorithm]);
            }
        }
        if (array_key_exists('upload_chunk_max_mib',$_POST)) {
            if (!$dcIsAdmin) dcFail(403,'Solo administradores pueden cambiar el límite de fragmentos');
            $chunkInput=(string)$_POST['upload_chunk_max_mib'];
            if (!in_array($chunkInput,['50','100'],true)) dcFail(422,'Solo se permiten 50 o 100 MiB');
            $q=$pdo->prepare('INSERT INTO dc_app_settings(setting_key,setting_value) VALUES (?,?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)');
            $q->execute(['upload_chunk_max_mib',$chunkInput]);
        }
        if (array_key_exists('tar_gz_preview_max_mib',$_POST)) {
            if (!$dcIsAdmin) dcFail(403,'Solo el administrador puede cambiar el límite TAR.GZ');
            $input=(string)$_POST['tar_gz_preview_max_mib'];
            if (!preg_match('/^(?:0|[1-9][0-9]{0,2})$/D',$input) || (int)$input>100)
                dcFail(400,'El límite TAR.GZ debe ser un entero entre 0 y 100 MiB');
            $bytes=(int)$input*1024*1024;
            $st=$pdo->prepare('INSERT INTO dc_app_settings (setting_key,setting_value) VALUES (?,?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)');
            $st->execute(['tar_gz_preview_max_bytes',(string)$bytes]);
        }
    }
    echo json_encode([
      'receive_global_limit_mib'=>(function()use($pdo){$q=$pdo->prepare('SELECT setting_value FROM dc_app_settings WHERE setting_key=?');$q->execute(['receive_global_limit_bytes']);return (int)(($q->fetchColumn() ?: 734003200)/1048576);})(),
      'receive_admin_limit_mib'=>(int)(($dcLogged['receive_admin_limit_bytes']??0)/1048576),
      'receive_limit_mib'=>(int)(($dcLogged['receive_limit_bytes']??0)/1048576),
      'can_manage_receive_global'=>$dcIsAdmin,
      'global_search'=>!empty($_SESSION['dc_global_search']),
      'tar_gz_preview_max_mib'=>(int)(dcTarGzPreviewLimit($pdo)/1048576),
      'can_manage_tar_gz_preview'=>$dcIsAdmin,
      'dedup_enabled'=>dcDedupConfig($pdo)[0],
      'dedup_algorithm'=>dcDedupConfig($pdo)[1],
      'can_manage_dedup'=>$dcIsAdmin,
      'upload_chunk_max_mib'=>dcChunkMaxMiB($pdo),
      'upload_chunk_effective_mib'=>dcChunkEffectiveMiB($pdo),
      'can_manage_upload_chunks'=>$dcIsAdmin,
      'session_days_dcft'=>dcSessionDaysDcft($dcLogged),
      'session_expires_at_dcft'=>(int)($_SESSION['dc_expires_at_dcft']??0)
    ]); exit;
}
// Reconstruir ruta de ubicación previa a la eliminación (solo nombres y ancestros visibles).
function dcTrashLocation(int $parentId, PDO $pdo, int $ownerId, bool $isAdmin): string {
    $parts=[]; $seen=[]; $depth=0;
    $query=$pdo->prepare('SELECT id,name,parent_id,owner_id FROM files WHERE id=?');
    while ($parentId>0 && $depth++<128) {
        if (isset($seen[$parentId])) return 'Ruta no disponible';
        $seen[$parentId]=true;
        $query->execute([$parentId]); $folder=$query->fetch(PDO::FETCH_ASSOC);
        if (!$folder) return 'Ruta no disponible';
        if (!$isAdmin && (int)$folder['owner_id']!==$ownerId) return 'Ruta no disponible';
        array_unshift($parts,(string)$folder['name']);
        $parentId=(int)$folder['parent_id'];
    }
    return $depth>=128?'Ruta no disponible':('Mi unidad'.(count($parts)?' / '.implode(' / ',$parts):''));
}

if ($action === 'trash_list') {
    $sql='SELECT id,name,is_dir,deleted_at,parent_id FROM files WHERE deleted_at IS NOT NULL';
    $sql .= ' AND owner_id=?';
    $sql .= ' ORDER BY deleted_at DESC LIMIT 250';
    $st=$pdo->prepare($sql);$st->execute([$dcOwnerId]);
    $files=$st->fetchAll(PDO::FETCH_ASSOC);
    $files=array_values(array_filter($files,fn($f)=>dcOwnedTreeIncludingTrash((int)$f['id'],$pdo,$dcOwnerId)));
    foreach ($files as &$trashedFile) {
        $trashedFile['original_path']=dcTrashLocation((int)$trashedFile['parent_id'],$pdo,$dcOwnerId,false);
    }
    unset($trashedFile);
    echo json_encode(['files'=>$files]);exit;
}
// v20.10.85h: navegar por los descendientes de una carpeta eliminada.
// Los hijos pueden conservar deleted_at=NULL; la pertenencia se verifica por ancestros.
if ($action === 'trash_tree_85h') {
    $root=(int)($_GET['root_id']??0);
    if ($root<=0 || !dcOwnedTreeIncludingTrash($root,$pdo,$dcOwnerId)) dcFail(404,'Carpeta no encontrada');
    $q=$pdo->prepare('SELECT id,name,is_dir,parent_id,deleted_at FROM files WHERE id=? AND owner_id=?');
    $q->execute([$root,$dcOwnerId]);$folder=$q->fetch(PDO::FETCH_ASSOC);
    if (!$folder || !(int)$folder['is_dir'] || $folder['deleted_at']===null) dcFail(404,'No es una carpeta raíz eliminada');
    $children=$pdo->prepare('SELECT id,name,is_dir,parent_id,deleted_at FROM files WHERE parent_id=? AND owner_id=? ORDER BY is_dir DESC,name,id');
    $items=[];$stack=[[$root,0]];
    while ($stack && count($items)<1500) {
        [$parentId,$depth]=array_pop($stack);
        if ($depth>=64) continue;
        $children->execute([$parentId,$dcOwnerId]);$rows=$children->fetchAll(PDO::FETCH_ASSOC);
        foreach (array_reverse($rows) as $r) {
            $r['depth']=$depth+1;$items[]=$r;
            if ((int)$r['is_dir']) $stack[]=[(int)$r['id'],$depth+1];
        }
    }
    echo json_encode(['root'=>$folder,'items'=>$items,'limited'=>count($items)>=1500]);exit;
}
// Recuperación individual sin activar la carpeta eliminada ni a sus hermanos.
// Reconstruye la ruta original bajo /undeleted, sin sobrescribir elementos activos.
if ($action === 'trash_restore_nested_85h') {
    if (($_SERVER['REQUEST_METHOD']??'')!=='POST') dcFail(405,'Se requiere POST');
    $id=(int)($_POST['id']??0);$root=(int)($_POST['root_id']??0);
    if (!$id || !$root || $id===$root) dcFail(422,'Selección inválida');
    $q=$pdo->prepare('SELECT id,parent_id,name,is_dir,deleted_at,owner_id FROM files WHERE id=? AND owner_id=?');
    $q->execute([$root,$dcOwnerId]);$rootFile=$q->fetch(PDO::FETCH_ASSOC);
    if (!$rootFile || !(int)$rootFile['is_dir'] || $rootFile['deleted_at']===null) dcFail(404,'Raíz no eliminada');
    $q->execute([$id,$dcOwnerId]);$target=$q->fetch(PDO::FETCH_ASSOC);
    if (!$target || !dcOwnedTreeIncludingTrash($id,$pdo,$dcOwnerId)) dcFail(404,'Archivo no encontrado');
    $segments=[];$cursor=(int)$target['parent_id'];$seen=[];$isChild=false;
    for($n=0;$cursor>0 && $n<100;$n++) {
        if(isset($seen[$cursor])) dcFail(409,'Ciclo en jerarquía');$seen[$cursor]=true;
        $q->execute([$cursor,$dcOwnerId]);$ancestor=$q->fetch(PDO::FETCH_ASSOC);
        if(!$ancestor)dcFail(409,'Jerarquía no disponible');
        array_unshift($segments,$ancestor['name']);
        if($cursor===$root){$isChild=true;break;}
        $cursor=(int)$ancestor['parent_id'];
    }
    if(!$isChild)dcFail(403,'El elemento no pertenece a esta carpeta eliminada');
    // Comprobar permisos para el subárbol antes de mover una carpeta.
    if ((int)$target['is_dir'] && !dcOwnsTrashSubtree($id,$pdo,$dcOwnerId)) dcFail(403,'Propiedad inconsistente');
    $find=$pdo->prepare('SELECT id,is_dir FROM files WHERE parent_id=? AND name=? AND owner_id=? AND deleted_at IS NULL LIMIT 1');
    $make=$pdo->prepare("INSERT INTO files(parent_id,name,is_dir,mime_type,owner_id) VALUES(?,?,1,'directory',?)");
    $pdo->beginTransaction();
    try {
        // La raíz de mi unidad es parent_id=0 en este esquema.
        $path=array_merge(['undeleted'],$segments);$parent=0;
        foreach($path as $piece) {
            $find->execute([$parent,$piece,$dcOwnerId]);$found=$find->fetch(PDO::FETCH_ASSOC);
            if($found && !(int)$found['is_dir'])throw new RuntimeException('Un archivo ocupa la ruta de restauración: '.$piece);
            if($found)$parent=(int)$found['id'];
            else {$make->execute([$parent,$piece,$dcOwnerId]);$parent=(int)$pdo->lastInsertId();}
        }
        $find->execute([$parent,$target['name'],$dcOwnerId]);
        if($find->fetch())throw new RuntimeException('Ya existe un elemento con ese nombre en undeleted');
        $pdo->prepare('UPDATE files SET parent_id=?,deleted_at=NULL WHERE id=? AND owner_id=?')->execute([$parent,$id,$dcOwnerId]);
        $pdo->commit();
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();dcFail(409,'No se pudo restaurar: '.$e->getMessage());}
    echo json_encode(['success'=>true,'destination'=>'Mi unidad / '.implode(' / ',$path)]);exit;
}
// Operación administrativa destructiva: vacía exclusivamente raíces de la papelera.
// Los descendientes se eliminan mediante deleteItem(), evitando recorrerlos dos veces.
if ($action === 'trash_empty') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') dcFail(405, 'Se requiere POST');
    dcRequirePolicy($dcLogged,$dcIsAdmin,'can_delete');
    if (($_POST['confirm'] ?? '') !== 'EMPTY_TRASH') dcFail(422, 'Confirmación incorrecta');
    $sql='SELECT f.id FROM files f LEFT JOIN files p ON p.id=f.parent_id WHERE f.deleted_at IS NOT NULL AND (p.id IS NULL OR p.deleted_at IS NULL)';
    $sql.=' AND f.owner_id=?';
    $sql.=' ORDER BY f.id';
    $st=$pdo->prepare($sql);$st->execute([$dcOwnerId]);
    $roots=$st->fetchAll(PDO::FETCH_COLUMN);
    // Prevalidar todo antes de borrar el primer elemento, sin eliminación parcial por permisos.
    foreach ($roots as $id) {
        if (!dcOwnedTreeIncludingTrash((int)$id,$pdo,$dcOwnerId) || !dcOwnsTrashSubtree((int)$id,$pdo,$dcOwnerId)) dcFail(403,'Papelera con propiedad inconsistente');
    }
    $purged=0;
    foreach ($roots as $id) { deleteItem((int)$id,$pdo,$STORAGE_DIR,$THUMBS_DIR,$LISTS_DIR);$purged++; }
    echo json_encode(['success'=>true,'purged_roots'=>$purged]);exit;
}
if ($action === 'trash_restore' || $action === 'trash_purge') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') dcFail(405);
    $id=(int)($_POST['id']??0);
    $st=$pdo->prepare('SELECT parent_id FROM files WHERE id=? AND deleted_at IS NOT NULL');$st->execute([$id]);
    $parent=$st->fetchColumn();
    if ($parent===false) dcFail(404,'Elemento no encontrado en papelera');
    if (!dcOwnedTreeIncludingTrash($id,$pdo,$dcOwnerId)) dcFail(404,'Elemento no encontrado');
    if ($action==='trash_restore') {
        $target=driveValidateId((int)$parent,$pdo,true)?(int)$parent:0;
        if ($target>0 && !dcOwnedTree($target,$pdo,$dcOwnerId)) $target=0;
        if ($target===0 && (int)$parent!==0) {
            // No restaurar a la raíz cuando la carpeta de origen fue borrada.
            // Reconstruir la cadena de ancestros conocida bajo /undeleted, sin reactivar hermanos.
            $walk=$pdo->prepare('SELECT id,name,parent_id,is_dir FROM files WHERE id=? AND owner_id=?');
            $parts=[];$cursor=(int)$parent;$seen=[];
            for($depth=0;$cursor>0 && $depth<100;$depth++) {
                if(isset($seen[$cursor])) dcFail(409,'Ciclo en la ruta original');
                $seen[$cursor]=true;$walk->execute([$cursor,$dcOwnerId]);$ancestor=$walk->fetch(PDO::FETCH_ASSOC);
                if(!$ancestor || !(int)$ancestor['is_dir'])break;
                array_unshift($parts,(string)$ancestor['name']);$cursor=(int)$ancestor['parent_id'];
            }
            // Si falta parte del árbol, al menos preservar lo que queda, dentro de undeleted.
            $findRestore=$pdo->prepare('SELECT id,is_dir FROM files WHERE parent_id=? AND name=? AND owner_id=? AND deleted_at IS NULL LIMIT 1');
            $makeRestore=$pdo->prepare("INSERT INTO files(parent_id,name,is_dir,mime_type,owner_id) VALUES(?,?,1,'directory',?)");
            $pdo->beginTransaction();
            try {
                $dest=0;
                foreach(array_merge(['undeleted'],$parts) as $piece){
                    $findRestore->execute([$dest,$piece,$dcOwnerId]);$found=$findRestore->fetch(PDO::FETCH_ASSOC);
                    if($found && !(int)$found['is_dir'])throw new RuntimeException('La ruta de restauración está ocupada: '.$piece);
                    if($found)$dest=(int)$found['id'];
                    else{$makeRestore->execute([$dest,$piece,$dcOwnerId]);$dest=(int)$pdo->lastInsertId();}
                }
                $self=$pdo->prepare('SELECT name FROM files WHERE id=? AND owner_id=?');$self->execute([$id,$dcOwnerId]);
                $name=(string)$self->fetchColumn();
                $findRestore->execute([$dest,$name,$dcOwnerId]);
                if($findRestore->fetch())throw new RuntimeException('Ya existe un archivo con ese nombre en undeleted');
                $pdo->prepare('UPDATE files SET deleted_at=NULL,parent_id=? WHERE id=? AND owner_id=?')->execute([$dest,$id,$dcOwnerId]);
                $pdo->commit();
            }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();dcFail(409,'No se pudo restaurar: '.$e->getMessage());}
        } else {
            $pdo->prepare('UPDATE files SET deleted_at=NULL,parent_id=? WHERE id=? AND owner_id=?')->execute([$target,$id,$dcOwnerId]);
        }
    } else {
        dcRequirePolicy($dcLogged,$dcIsAdmin,'can_delete');
        if (!dcOwnsTrashSubtree($id,$pdo,$dcOwnerId)) dcFail(403,'Propiedad inconsistente');
        deleteItem($id,$pdo,$STORAGE_DIR,$THUMBS_DIR,$LISTS_DIR);
    }
    echo json_encode(['success'=>true]);exit;
}
// DC85L: Historial de operaciones reversible por sesión autenticada.
// El servidor NO confía en nombres, rutas ni IDs enviados por el navegador para deshacer.
function dcUndoPush85l(array $entry): void {
    $stack=$_SESSION['dc_undo85l']??[];
    if (!is_array($stack)) $stack=[];
    $entry['owner']=(int)($_SESSION['dc_undo_owner85l']??0);
    $stack[]=$entry;
    $_SESSION['dc_undo85l']=array_slice($stack,-20);
}
if (($_SESSION['dc_undo_owner85l']??null)!==$dcOwnerId) {
    $_SESSION['dc_undo_owner85l']=$dcOwnerId;
    $_SESSION['dc_undo85l']=[];
}
if ($action==='undo_status_85l') {
    if (($_SERVER['REQUEST_METHOD']??'')!=='GET') dcFail(405,'Solo GET');
    $stack=$_SESSION['dc_undo85l']??[];
    $top=$stack?end($stack):null;
    $stack = $_SESSION['dc_undo85l'] ?? [];

    if (!is_array($stack)) {
        $stack = [];
    }

    $top = $stack ? end($stack) : null;
    $label = is_array($top) ? ($top['label'] ?? '') : '';

    if (!is_string($label)) {
        $label = '';
    }

    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');

    try {
        echo json_encode([
            'success' => true,
            'count' => count($stack),
            'label' => $label
        ], JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR);
    } catch (JsonException $e) {
        error_log('[DC85L] Error al generar JSON: ' . $e->getMessage());
        http_response_code(500);
        echo '{"success":false,"error":"Error al consultar historial"}';
    }

    exit;
}
if ($action==='undo_85l') {
    if (($_SERVER['REQUEST_METHOD']??'')!=='POST') dcFail(405,'Solo POST');
    $stack=$_SESSION['dc_undo85l']??[];
    if (!$stack) dcFail(409,'No hay operaciones para deshacer');
    $op=end($stack);$type=$op['type']??'';$items=$op['items']??[];
    if (!is_array($items)||!$items||count($items)>200) dcFail(409,'Registro inválido');
    try {
        $pdo->beginTransaction();
        $get=$pdo->prepare('SELECT id,name,parent_id,deleted_at FROM files WHERE id=? AND owner_id=? FOR UPDATE');
        $collision=$pdo->prepare('SELECT id FROM files WHERE parent_id=? AND name=? AND owner_id=? AND deleted_at IS NULL AND id<>? LIMIT 1');
        $updateName=$pdo->prepare('UPDATE files SET name=? WHERE id=? AND owner_id=? AND deleted_at IS NULL');
        $updateParent=$pdo->prepare('UPDATE files SET parent_id=? WHERE id=? AND owner_id=? AND deleted_at IS NULL');
        $restore=$pdo->prepare('UPDATE files SET deleted_at=NULL WHERE id=? AND owner_id=? AND deleted_at IS NOT NULL');
        foreach ($items as $item) {
            $id=(int)($item['id']??0);if($id<1)throw new RuntimeException('Identificador inválido');
            $get->execute([$id,$dcOwnerId]);$current=$get->fetch(PDO::FETCH_ASSOC);
            if(!$current)throw new RuntimeException('El elemento ya no existe');
            if($type==='rename') {
                if($current['deleted_at']!==null||$current['name']!==$item['after'])throw new RuntimeException('El nombre cambió desde la operación');
                $parent=(int)$current['parent_id'];
                if($parent>0&&!dcOwnedTree($parent,$pdo,$dcOwnerId))throw new RuntimeException('Carpeta original no disponible');
                $collision->execute([$parent,$item['before'],$dcOwnerId,$id]);
                if($collision->fetchColumn()!==false)throw new RuntimeException('El nombre original ya está ocupado');
                $updateName->execute([$item['before'],$id,$dcOwnerId]);
            } elseif($type==='move') {
                if($current['deleted_at']!==null||(int)$current['parent_id']!==(int)$item['after'])throw new RuntimeException('El elemento fue modificado después de moverlo');
                $parent=(int)$item['before'];
                if($parent>0&&!dcOwnedTree($parent,$pdo,$dcOwnerId))throw new RuntimeException('La carpeta de origen ya no existe');
                $collision->execute([$parent,$current['name'],$dcOwnerId,$id]);
                if($collision->fetchColumn()!==false)throw new RuntimeException('Ya existe un elemento con ese nombre en el origen');
                $updateParent->execute([$parent,$id,$dcOwnerId]);
            } elseif($type==='delete') {
                if($current['deleted_at']===null)throw new RuntimeException('El elemento ya fue restaurado');
                if(!dcOwnedTreeIncludingTrash($id,$pdo,$dcOwnerId))throw new RuntimeException('Sin autorización');
                $parent=(int)$current['parent_id'];
                if($parent>0&&!dcOwnedTree($parent,$pdo,$dcOwnerId))throw new RuntimeException('La carpeta de origen está eliminada; usa Papelera para restaurar a undeleted');
                $collision->execute([$parent,$current['name'],$dcOwnerId,$id]);
                if($collision->fetchColumn()!==false)throw new RuntimeException('Nombre en uso; restaura desde Papelera');
                $restore->execute([$id,$dcOwnerId]);
            } else throw new RuntimeException('Operación no reversible');
        }
        $pdo->commit();array_pop($stack);$_SESSION['dc_undo85l']=$stack;
        echo json_encode(['success'=>true,'count'=>count($stack)]);exit;
    } catch(Throwable $e) {
        if($pdo->inTransaction())$pdo->rollBack();
        dcFail(409,'No se pudo deshacer: '.$e->getMessage());
    }
}
if ($action==='rename') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') dcFail(405,'Se requiere POST');
    $id=(int)($_POST['id']??0);
    $name=trim((string)($_POST['name']??''));
    if ($id<=0 || !driveValidateId($id,$pdo)) dcFail(404,'Archivo no encontrado');
    dcReadFileGuard($id,$pdo,false,$dcOwnerId);
    if ($name==='' || strlen($name)>240 || $name==='.' || $name==='..' ||
        str_contains($name,'/') || str_contains($name,'\\') || preg_match('/[\x00-\x1F\x7F]/',$name)) dcFail(422,'Nombre inválido');
    $st=$pdo->prepare('SELECT parent_id,is_dir,name FROM files WHERE id=? AND deleted_at IS NULL');
    $st->execute([$id]);$item=$st->fetch(PDO::FETCH_ASSOC);
    if (!$item) dcFail(404,'No encontrado');
    if (!dcOwnsTrashSubtree($id,$pdo,$dcOwnerId)) dcFail(403,'Carpeta con propiedad inconsistente');
    // Evita colisiones entre archivos y carpetas en el mismo directorio.
    $check=$pdo->prepare('SELECT id FROM files WHERE parent_id=? AND name=? AND id<>? AND owner_id=? AND deleted_at IS NULL LIMIT 1');
    $check->execute([(int)$item['parent_id'],$name,$id,$dcOwnerId]);
    if ($check->fetchColumn()!==false) dcFail(409,'Ya existe un elemento con ese nombre');
    $pdo->prepare('UPDATE files SET name=? WHERE id=? AND deleted_at IS NULL')->execute([$name,$id]);
    if ((string)$item['name']!==$name) dcActivity85m($pdo,$dcOwnerId,$id,'rename');
    if ((string)$item['name']!==$name) dcUndoPush85l(['type'=>'rename','label'=>'Renombrar '.(string)$item['name'],'items'=>[['id'=>$id,'before'=>(string)$item['name'],'after'=>$name]]]);
    echo json_encode(['success'=>true]);exit;
}
// Modo álbum: solo cambia cómo se presenta la carpeta; no mueve ni publica archivos.
if ($action==='clear_recents_85m') {
    if(($_SERVER['REQUEST_METHOD']??'')!=='POST')dcFail(405,'Solo POST');
    // dcCsrf() is enforced globally before dispatch.
    $pdo->prepare('DELETE FROM dc_recent_activity_85m_hf2 WHERE owner_id=?')->execute([$dcOwnerId]);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success'=>true]);exit;
}
if ($action==='folder_modes_85m') {
    if (($_SERVER['REQUEST_METHOD']??'')!=='GET')dcFail(405,'Solo GET');
    $id=(int)($_GET['id']??0);
    if($id<1 || !dcOwnedTree($id,$pdo,$dcOwnerId))dcFail(404,'Carpeta no disponible');
    require_once __DIR__.'/dc_special_modes_85m.php';
    header('Content-Type: application/json; charset=utf-8');
    $st=$pdo->prepare('SELECT parent_id FROM files WHERE id=? AND owner_id=? AND is_dir=1 AND deleted_at IS NULL');
    $st->execute([$id,$dcOwnerId]);$parent=$st->fetchColumn();
    if($parent===false)dcFail(404,'Carpeta no disponible');
    $parentYT=false;
    if((int)$parent>0 && dcOwnedTree((int)$parent,$pdo,$dcOwnerId)){
        $q=$pdo->prepare('SELECT 1 FROM dc_youtube_folders WHERE folder_id=? AND owner_id=?');
        $q->execute([(int)$parent,$dcOwnerId]);$parentYT=(bool)$q->fetchColumn();
    }
    $parentMusic=false;$musicCollection=false;
    if((int)$parent>0 && dcOwnedTree((int)$parent,$pdo,$dcOwnerId)){
        $q=$pdo->prepare('SELECT 1 FROM dc_music_libraries_81 WHERE folder_id=? AND owner_id=? LIMIT 1');
        $q->execute([(int)$parent,$dcOwnerId]);$parentMusic=(bool)$q->fetchColumn();
        if($parentMusic){
            $q=$pdo->prepare('SELECT enabled FROM dc_music_collection_modes_hf3 WHERE folder_id=? AND owner_id=? LIMIT 1');
            $q->execute([$id,$dcOwnerId]);$v=$q->fetchColumn();$musicCollection=($v===false || (int)$v===1);
        }
    }
    echo json_encode(['success'=>true,'modes'=>dc85mModes($pdo,$dcOwnerId,$id),'parent_youtube'=>$parentYT,'parent_music'=>$parentMusic,'music_collection'=>$musicCollection]);exit;
}
if ($action==='set_album') {
    if (($_SERVER['REQUEST_METHOD']??'') !== 'POST') dcFail(405,'Se requiere POST');
    $id=(int)($_POST['id']??0);
    if ($id<=0) dcFail(400,'Carpeta inválida');
    dcReadFileGuard($id,$pdo,false,$dcOwnerId);
    $st=$pdo->prepare('SELECT is_dir FROM files WHERE id=? AND deleted_at IS NULL');
    $st->execute([$id]);
    if ((int)$st->fetchColumn()!==1) dcFail(422,'Solo se puede convertir una carpeta en álbum');
    $enabled=(($_POST['enabled']??'0')==='1')?1:0;
    if ($enabled && $pdo->query("SHOW TABLES LIKE 'dc_youtube_folders'")->fetchColumn()) {
        $q=$pdo->prepare('SELECT 1 FROM dc_youtube_folders WHERE folder_id=? AND owner_id=?');
        $q->execute([$id,$dcOwnerId]);
        if ($q->fetchColumn()) dcFail(409,'Primero desactiva el modo YouTube Links');
    }
    if ($enabled) {require_once __DIR__.'/dc_special_modes_85m.php';if(dc85mConflict($pdo,$dcOwnerId,$id,'album'))dcFail(409,'Desactiva primero la clasificación actual de la carpeta');}
    $pdo->prepare('UPDATE files SET is_album=? WHERE id=? AND deleted_at IS NULL')->execute([$enabled,$id]);
    dcActivity85m($pdo,$dcOwnerId,$id,'special');
    echo json_encode(['success'=>true,'is_album'=>$enabled]);exit;
}

/** Girar foto 90° en el archivo original y regenerar miniatura. direction: left|right */
if ($action === 'rotate_image') {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') dcFail(405, 'Se requiere POST');
    if (!$dcIsAdmin && empty($dcLogged['can_upload'])) dcFail(403, 'Sin permiso para modificar archivos');
    $dir = strtolower((string)($_POST['direction'] ?? ''));
    if (!in_array($dir, ['left', 'right'], true)) dcFail(422, 'Solicitud incorrecta');
    // Acepta id único o ids[] (JSON) para selección múltiple.
    $ids = [];
    if (!empty($_POST['ids'])) {
        $raw = json_decode((string)$_POST['ids'], true);
        if (!is_array($raw)) dcFail(422, 'Lista de ids inválida');
        foreach ($raw as $v) {
            $n = (int)$v;
            if ($n > 0) $ids[] = $n;
        }
    } else {
        $n = (int)($_POST['id'] ?? 0);
        if ($n > 0) $ids[] = $n;
    }
    $ids = array_values(array_unique($ids));
    if (!$ids) dcFail(422, 'Ninguna foto indicada');
    if (count($ids) > 100) dcFail(422, 'Máximo 100 fotos por operación');

    // ImageMagick: positivo = horario. GD: positivo = antihorario.
    $imAngle = $dir === 'right' ? 90 : -90;
    $gdAngle = -$imAngle;
    $rotated = 0;
    $failed = 0;
    $skipped = 0;
    $seenStorage = []; // mismo storage/hardlink: girar solo una vez

    foreach ($ids as $id) {
        try {
            dcReadFileGuard($id, $pdo, false, $dcOwnerId);
            $st = $pdo->prepare('SELECT * FROM files WHERE id=? AND owner_id=? AND is_dir=0 AND deleted_at IS NULL LIMIT 1');
            $st->execute([$id, $dcOwnerId]);
            $f = $st->fetch(PDO::FETCH_ASSOC);
            if (!$f || !dcIsImageFile($f)) { $skipped++; continue; }
            $storage = (string)$f['storage_name'];
            if ($storage === '' || basename($storage) !== $storage) { $failed++; continue; }
            $path = rtrim($STORAGE_DIR, '/') . '/' . $storage;
            if (!is_file($path) || !is_readable($path) || !is_writable($path)) { $failed++; continue; }

            if (isset($seenStorage[$storage])) {
                // Ya girado en este lote (otra fila con el mismo storage o hardlink).
                $skipped++;
                continue;
            }

            $tmp = $path . '.' . bin2hex(random_bytes(6)) . '.rot';
            $ok = false;
            if (trim((string)shell_exec('command -v convert 2>/dev/null')) !== '') {
                $cmd = 'timeout 40s convert -limit memory 128MiB -limit map 256MiB '
                    . escapeshellarg($path)
                    . ' -auto-orient -rotate ' . (int)$imAngle
                    . ' -orient Top-Left -strip '
                    . escapeshellarg($tmp) . ' 2>/dev/null';
                exec($cmd, $out, $status);
                $ok = is_file($tmp) && filesize($tmp) > 0;
            }
            if (!$ok && trim((string)shell_exec('command -v magick 2>/dev/null')) !== '') {
                $cmd = 'timeout 40s magick -limit memory 128MiB -limit map 256MiB '
                    . escapeshellarg($path)
                    . ' -auto-orient -rotate ' . (int)$imAngle
                    . ' -orient Top-Left -strip '
                    . escapeshellarg($tmp) . ' 2>/dev/null';
                exec($cmd, $out, $status);
                $ok = is_file($tmp) && filesize($tmp) > 0;
            }
            if (!$ok && extension_loaded('gd') && function_exists('imagecreatefromstring') && function_exists('imagerotate')) {
                $raw = @file_get_contents($path);
                if ($raw !== false) {
                    $src = @imagecreatefromstring($raw);
                    unset($raw);
                    if ($src) {
                        $src = dcGdApplyExifOrientation($src, $path);
                        $rotImg = imagerotate($src, $gdAngle, 0);
                        imagedestroy($src);
                        if ($rotImg) {
                            $mime = strtolower((string)$f['mime_type']);
                            $ext = strtolower(pathinfo((string)$f['name'], PATHINFO_EXTENSION));
                            if (str_contains($mime, 'png') || $ext === 'png') {
                                $ok = @imagepng($rotImg, $tmp, 6);
                            } elseif (str_contains($mime, 'webp') || $ext === 'webp') {
                                $ok = function_exists('imagewebp') ? @imagewebp($rotImg, $tmp, 85) : @imagejpeg($rotImg, $tmp, 90);
                            } else {
                                $ok = @imagejpeg($rotImg, $tmp, 92);
                            }
                            imagedestroy($rotImg);
                            $ok = $ok && is_file($tmp) && filesize($tmp) > 0;
                        }
                    }
                }
            }
            if (!$ok) {
                @unlink($tmp);
                $failed++;
                continue;
            }
            if (!@rename($tmp, $path)) {
                @unlink($tmp);
                $failed++;
                continue;
            }
            clearstatcache(true, $path);
            $newSize = (int)filesize($path);
            $digest = @hash_file('sha256', $path) ?: null;
            $headHash = null;
            if (function_exists('dcMerkleHeadFile')) {
                try { $headHash = dcMerkleHeadFile($path); } catch (Throwable $e) { $headHash = null; }
            }
            // Actualizar todas las filas que apuntan a este storage (copias / hardlinks lógicos).
            if ($digest) {
                $pdo->prepare('UPDATE files SET size=?, has_thumb=0, content_hash_algorithm=?, content_hash=?, head_hash=? WHERE storage_name=? AND owner_id=? AND deleted_at IS NULL')
                    ->execute([$newSize, 'sha256', $digest, $headHash, $storage, $dcOwnerId]);
            } else {
                $pdo->prepare('UPDATE files SET size=?, has_thumb=0 WHERE storage_name=? AND owner_id=? AND deleted_at IS NULL')
                    ->execute([$newSize, $storage, $dcOwnerId]);
            }
            @unlink(rtrim($THUMBS_DIR, '/') . '/' . $storage . '.webp');
            $f['size'] = $newSize;
            $f['has_thumb'] = 0;
            try {
                ensureImageThumbnail($f, $pdo, $STORAGE_DIR, $THUMBS_DIR, 960, true);
            } catch (Throwable $ignore) {}
            $seenStorage[$storage] = true;
            $rotated++;
        } catch (Throwable $e) {
            $failed++;
        }
    }

    if ($rotated === 0 && $failed > 0) {
        dcFail(503, 'No se pudo girar ninguna imagen');
    }
    echo json_encode([
        'success' => true,
        'rotated' => $rotated,
        'skipped' => $skipped,
        'failed' => $failed,
    ]);
    exit;
}

if ($action==='toggle_favorite') {
    $id=(int)($_POST['id']??0);
    dcReadFileGuard($id,$pdo,false,$dcOwnerId);
    if (!driveValidateId($id,$pdo)) {http_response_code(404);exit(json_encode(['error'=>'No encontrado']));}
    $pdo->prepare('UPDATE files SET favorite=1-favorite WHERE id=?')->execute([$id]);
    echo json_encode(['success'=>true]);exit;
}

// Indicadores del historial. Un único SELECT agregado para las versiones visibles.
function dcVersionBadges(array $rows, PDO $pdo): array {
    if (!$rows) return $rows;
    $ids = array_map(static fn($r) => (int)$r['id'], $rows);
    $counts = [];
    foreach (array_chunk($ids, 300) as $batch) {
        $placeholders = implode(',', array_fill(0, count($batch), '?'));
        $stmt = $pdo->prepare("SELECT file_id, COUNT(*) AS total FROM file_versions WHERE file_id IN ($placeholders) GROUP BY file_id");
        $stmt->execute($batch);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $rec) $counts[(int)$rec['file_id']] = (int)$rec['total'];
    }
    // v20.10.24: indicadores de recepcion en hijos directos de carpetas visibles.
    global $dcOwnerId;
    $receivedFolderCounts = [];
    $folderIds = array_values(array_map('intval', array_column(array_filter($rows, static fn($r) => (int)$r['is_dir'] === 1), 'id')));
    foreach (array_chunk($folderIds, 300) as $batch) {
        $placeholders = implode(',', array_fill(0, count($batch), '?'));
        $st = $pdo->prepare("SELECT parent_id, COUNT(*) AS total FROM files WHERE parent_id IN ($placeholders) AND owner_id=? AND is_dir=0 AND deleted_at IS NULL AND received_highlight=1 GROUP BY parent_id");
        $st->execute(array_merge($batch, [(int)$dcOwnerId]));
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $rec) $receivedFolderCounts[(int)$rec['parent_id']] = (int)$rec['total'];
    }
    $folderCache = [];
    $effective = static function(int $id) use ($pdo, &$folderCache): bool {
        if ($id <= 0) return false;
        if (array_key_exists($id, $folderCache)) return $folderCache[$id];
        return $folderCache[$id] = driveTreeFlag($id, 'version_enabled', $pdo);
    };
    foreach ($rows as &$row) {
        $id=(int)$row['id'];
        $isDir = (int)$row['is_dir'] === 1;
        $direct = $isDir && !empty($row['version_enabled']);
        $parent = (int)$row['parent_id'];
        $inherited = $isDir && !$direct && $effective($parent);
        $row['version_enabled_effective'] = $isDir ? ($direct || $inherited ? 1 : 0) : 0;
        $row['version_inherited'] = $inherited ? 1 : 0;
        $row['versions_count'] = $isDir ? 0 : ($counts[$id] ?? 0);
        $row['has_versions'] = !$isDir && ($counts[$id] ?? 0) > 0 ? 1 : 0;
        $row['received_children_count'] = $isDir ? ($receivedFolderCounts[$id] ?? 0) : 0;
    }
    unset($row);
    return $rows;
}

if ($action==='favorites' || $action==='recents') {
    $sql=$action==='favorites' ? 'SELECT * FROM files WHERE owner_id=? AND favorite=1 AND deleted_at IS NULL ORDER BY name LIMIT 150' : 'SELECT f.* FROM dc_recent_activity_85m_hf2 a JOIN files f ON f.id=a.file_id AND f.owner_id=a.owner_id WHERE a.owner_id=? AND f.deleted_at IS NULL ORDER BY a.activity_at DESC LIMIT 40';
    $st=$pdo->prepare($sql);$st->execute([$dcOwnerId]);
    $items=$st->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode(['files'=>dcVersionBadges(array_values(array_filter($items,fn($f)=>!driveIsTrashed((int)$f['id'],$pdo) && (dcOwnedTree((int)$f['id'],$pdo,$dcOwnerId)))),$pdo)]);exit;
}
function dcVirtualPath(int $id, PDO $pdo, int $owner): string {
    $segments=[];$seen=[];$depth=0;
    $q=$pdo->prepare('SELECT name,parent_id,owner_id,deleted_at FROM files WHERE id=?');
    while ($id>0 && $depth++<128) {
        if (isset($seen[$id])) return 'Ruta no disponible';
        $seen[$id]=true;$q->execute([$id]);$r=$q->fetch(PDO::FETCH_ASSOC);
        if (!$r || (int)$r['owner_id']!==$owner || $r['deleted_at']!==null) return 'Ruta no disponible';
        array_unshift($segments,(string)$r['name']);$id=(int)$r['parent_id'];
    }
    return $id>0 ? 'Ruta no disponible' : '/'.implode('/',$segments);
}
if ($action==='properties') {
    $id=(int)($_GET['id']??0);
    dcReadFileGuard($id,$pdo,false,$dcOwnerId);
    if (!driveValidateId($id,$pdo)) {http_response_code(404);exit(json_encode(['error'=>'No encontrado']));}
    $st=$pdo->prepare('SELECT id,parent_id,name,is_dir,mime_type,size,created_at,storage_name,favorite,version_enabled,last_opened FROM files WHERE id=?'); $st->execute([$id]);
    $f=$st->fetch(PDO::FETCH_ASSOC);
    $f['ruta_virtual']=dcVirtualPath($id,$pdo,$dcOwnerId);
    $f['sha256']=(!$f['is_dir'] && basename((string)$f['storage_name'])===$f['storage_name'] && is_file($STORAGE_DIR.$f['storage_name'])) ? hash_file('sha256',$STORAGE_DIR.$f['storage_name']):null;
    unset($f['storage_name']);
    echo json_encode(['file'=>$f]);exit;
}
if ($action==='set_versions') {
    $id=(int)($_POST['id']??0);
    dcReadFileGuard($id,$pdo,false,$dcOwnerId);
    $st=$pdo->prepare('SELECT is_dir FROM files WHERE id=? AND deleted_at IS NULL');$st->execute([$id]);
    if ((int)$st->fetchColumn()!==1) {http_response_code(400);exit(json_encode(['error'=>'Solo carpetas']));}
    $enabled=($_POST['enabled']??'0')==='1'?1:0;
    $pdo->prepare('UPDATE files SET version_enabled=? WHERE id=?')->execute([$enabled,$id]);
    echo json_encode(['success'=>true]);exit;
}
if ($action==='file_versions') {
    $id=(int)($_GET['id']??0);
    dcReadFileGuard($id,$pdo,false,$dcOwnerId);
    if (!driveValidateId($id,$pdo)) dcFail(404,'Archivo no encontrado');
    $st=$pdo->prepare('SELECT id,size,created_at FROM file_versions WHERE file_id=? ORDER BY id DESC LIMIT 30');$st->execute([$id]);
    echo json_encode(['versions'=>$st->fetchAll(PDO::FETCH_ASSOC)]);exit;
}
if ($action==='restore_version') {
    $id=(int)($_POST['version_id']??0);
    $st=$pdo->prepare('SELECT v.*,f.storage_name AS current_storage,f.id AS active_id FROM file_versions v JOIN files f ON f.id=v.file_id WHERE v.id=? AND f.deleted_at IS NULL'); $st->execute([$id]);
    $v=$st->fetch(PDO::FETCH_ASSOC);
    if (!$v) dcFail(404,'Versión inexistente');
    dcReadFileGuard((int)$v['active_id'],$pdo,false,$dcOwnerId);
    if (!driveValidateId((int)$v['active_id'],$pdo)) dcFail(404,'Versión inexistente');
    $current=$STORAGE_DIR.$v['current_storage'];$archived=$STORAGE_DIR.$v['storage_name'];
    if (!is_file($current)||!is_file($archived)) {http_response_code(404);exit(json_encode(['error'=>'Archivo físico no encontrado']));}
    $temp=$STORAGE_DIR.bin2hex(random_bytes(16)).'.swap';
    if (!copy($archived,$temp)) {http_response_code(500);exit(json_encode(['error'=>'No se pudo copiar versión']));}
    $newArchived=bin2hex(random_bytes(16)).'.version';
    if (!rename($current,$STORAGE_DIR.$newArchived)||!rename($temp,$current)) {http_response_code(500);exit(json_encode(['error'=>'Error al restaurar']));}
    $pdo->prepare('INSERT INTO file_versions(file_id,storage_name,size,created_at) VALUES (?,?,?,NOW())')->execute([$v['file_id'],$newArchived,filesize($STORAGE_DIR.$newArchived)]);
    @unlink($THUMBS_DIR.$v['current_storage'].'.webp');
    $pdo->prepare('UPDATE files SET size=?,has_thumb=0 WHERE id=?')->execute([filesize($current),$v['file_id']]);
    echo json_encode(['success'=>true]);exit;
}

if ($action==='create_receive') {
    if ((empty($dcLogged['can_share']) || empty($dcLogged['can_upload']))) dcFail(403,'Permiso no concedido');
    $id=(int)($_POST['folder_id']??0);
    if ($id<=0 || !driveValidateId($id,$pdo,true)) dcFail(400,'Carpeta inválida');
    if (!dcOwnedTree($id,$pdo,$dcOwnerId)) dcFail(404,'Carpeta no encontrada');
    $owner=$pdo->prepare('SELECT owner_id FROM files WHERE id=?');$owner->execute([$id]);
    if ((int)$owner->fetchColumn()!==$dcOwnerId) dcFail(403,'Solo se reciben archivos en carpetas propias');
    // 20.10.23: prohibir enlace de recepción en carpeta YouTube.
    $modeCheck=$pdo->prepare('SELECT 1 FROM dc_youtube_folders WHERE folder_id=? AND owner_id=? LIMIT 1');
    $modeCheck->execute([$id,$dcOwnerId]);
    if ($modeCheck->fetchColumn()) dcFail(409,'Desactiva YouTube Links para crear un enlace de recepción');
    $seconds=shareDurationSeconds($_POST);
    if ($seconds===false) dcFail(400,'Duración inválida');
    $hash=bin2hex(random_bytes(16));$pass=trim((string)($_POST['password']??''));
    $pdo->prepare('INSERT INTO receive_links(folder_id,hash,password_hash,expires_at,owner_id) VALUES(?,?,?,?,?)')->execute([$id,$hash,$pass!==''?password_hash($pass,PASSWORD_DEFAULT):null,shareExpiry($seconds),$dcOwnerId]);
    echo json_encode(['link'=>'api.php?receive_hash='.$hash]);exit;
}
if ($action==='list_receive') {
    $q=$pdo->prepare('SELECT r.id,r.hash,r.folder_id,r.expires_at,f.name FROM receive_links r JOIN files f ON r.folder_id=f.id WHERE r.owner_id=? AND f.owner_id=? ORDER BY r.id DESC LIMIT 100');
    $q->execute([$dcOwnerId,$dcOwnerId]);echo json_encode(['links'=>$q->fetchAll(PDO::FETCH_ASSOC)]);exit;
}
if ($action==='revoke_receive') {
    $q=$pdo->prepare('DELETE FROM receive_links WHERE id=? AND owner_id=?');$q->execute([(int)($_POST['id']??0),$dcOwnerId]);
    if (!$q->rowCount()) dcFail(404,'Enlace no encontrado');
    echo json_encode(['success'=>true]);exit;
}
// v20.9.9: quitar marcas de recepción de archivos de una carpeta, NO subcarpetas.
if ($action==='clear_received_marks_folder') {
    $folderId=filter_var($_POST['folder_id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
    if ($folderId===false || $folderId===null) dcFail(400,'Carpeta inválida');
    // Validar carpeta viva, propia y sus antecesores (también para administradores).
    $q=$pdo->prepare('SELECT id FROM files WHERE id=? AND owner_id=? AND is_dir=1 AND deleted_at IS NULL LIMIT 1');
    $q->execute([$folderId,$dcOwnerId]);
    if (!$q->fetchColumn() || !dcOwnedTree((int)$folderId,$pdo,$dcOwnerId)) dcFail(404,'Carpeta no encontrada');
    $q=$pdo->prepare('UPDATE files SET received_highlight=0 WHERE parent_id=? AND owner_id=? AND is_dir=0 AND deleted_at IS NULL AND received_highlight=1');
    $q->execute([$folderId,$dcOwnerId]);
    echo json_encode(['success'=>true,'updated'=>$q->rowCount()]);exit;
}
if ($action==='clear_received_mark') {
    $id=(int)($_POST['id']??0);
    dcReadFileGuard($id,$pdo,false,$dcOwnerId);
    $q=$pdo->prepare('UPDATE files SET received_highlight=0 WHERE id=? AND owner_id=? AND deleted_at IS NULL');
    $q->execute([$id,$dcOwnerId]);if(!$q->rowCount())dcFail(404,'Archivo no encontrado o indicador ya retirado');
    echo json_encode(['success'=>true]);exit;
}

// --- NUEVO: OBTENER ESPACIO EN DISCO ---
if ($action === 'disk_space') {
    // Administradores: disco físico. Usuarios: consumo propio, incluidas versiones
    // históricas y elementos en papelera que aún ocupan espacio.
    $free = disk_free_space($STORAGE_DIR);
    $total = disk_total_space($STORAGE_DIR);
    if ($dcIsAdmin) {
        echo json_encode(['mode'=>'host', 'free'=>$free, 'total'=>$total]);
        exit;
    }
    $usedFiles=$pdo->prepare('SELECT COALESCE(SUM(size),0) FROM files WHERE owner_id=? AND is_dir=0');
    $usedFiles->execute([$dcOwnerId]);
    $used=(float)$usedFiles->fetchColumn();
    $usedVersions=$pdo->prepare('SELECT COALESCE(SUM(v.size),0) FROM file_versions v JOIN files f ON f.id=v.file_id WHERE f.owner_id=?');
    $usedVersions->execute([$dcOwnerId]);
    $used+=(float)$usedVersions->fetchColumn();
    $quota=(float)($dcLogged['quota_bytes']??0);
    if ($quota>0) {
        // No devolver capacidad del host a cuentas con cuota.
        echo json_encode(['mode'=>'quota','used'=>$used,'total'=>$quota,'free'=>max(0,$quota-$used)]);
    } else {
        echo json_encode(['mode'=>'unlimited','used'=>$used,'free'=>$free,'total'=>$total]);
    }
    exit;
}

function isChildOf($childId, $parentId, $pdo) {
    while ($childId != 0) {
        if ($childId == $parentId) return true;
        $stmt = $pdo->prepare("SELECT parent_id FROM files WHERE id = ?");
        $stmt->execute([$childId]);
        $res = $stmt->fetchColumn();
        if ($res === false) break;
        $childId = $res;
    }
    return false;
}

function dcSafePurgePath($dir, $name, $optional=false) {
    // Solo nombres físicos locales, nunca rutas ni symlinks.
    $name=(string)$name;
    if ($name==='' || $name==='.' || $name==='..' || basename($name)!==$name ||
        !preg_match('/^[A-Za-z0-9_.-]+$/D', $name)) {
        throw new RuntimeException('Nombre físico inválido durante eliminación');
    }
    $path=rtrim((string)$dir,'/').'/'.$name;
    clearstatcache(true,$path);
    if (is_link($path)) throw new RuntimeException('Enlace simbólico inesperado al eliminar archivo');
    // Una eliminación física ya completada puede reintentarse si la operación SQL falló.
    if (!file_exists($path)) {
        if (!$optional) error_log('[Drive-Craft purge] Archivo físico ya ausente: '.hash('sha256',$name));
        return;
    }
    if (!is_file($path) || !unlink($path)) {
        $code='DC-PURGE-'.substr(hash('sha256',$name),0,12);
        error_log('[Drive-Craft purge] Falló unlink de '.$code.' en '.rtrim((string)$dir,'/'));
        throw new RuntimeException('No fue posible retirar un archivo del almacenamiento ('.$code.'). Verificar permisos del volumen.');
    }
}

function deleteItem($id, $pdo, $STORAGE_DIR, $THUMBS_DIR, $LISTS_DIR) {
    $stmt=$pdo->prepare('SELECT * FROM files WHERE id=?');
    $stmt->execute([$id]);$item=$stmt->fetch(PDO::FETCH_ASSOC);
    if (!$item) return;
    if ($item['is_dir']) {
        $ch=$pdo->prepare('SELECT id FROM files WHERE parent_id=?');$ch->execute([$id]);
        foreach ($ch->fetchAll(PDO::FETCH_COLUMN) as $childId)
            deleteItem((int)$childId,$pdo,$STORAGE_DIR,$THUMBS_DIR,$LISTS_DIR);
    } else {
        $v=$pdo->prepare('SELECT storage_name FROM file_versions WHERE file_id=?');$v->execute([$id]);
        $versions=$v->fetchAll(PDO::FETCH_COLUMN);
        // Fallos en eliminaciones físicas DETIENEN el borrado de las referencias SQL.
        // Un unlink sobre un hardlink solo retira ese nombre, sin afectar los demás.
        dcSafePurgePath($STORAGE_DIR,$item['storage_name']);
        if (!empty($item['has_thumb'])) dcSafePurgePath($THUMBS_DIR,$item['storage_name'].'.webp',true);
        if (!empty($item['has_list'])) dcSafePurgePath($LISTS_DIR,$item['storage_name'].'.list.gz',true);
        foreach ($versions as $vn) dcSafePurgePath($STORAGE_DIR,$vn);
        $pdo->prepare('DELETE FROM file_versions WHERE file_id=?')->execute([$id]);
    }
    $pdo->prepare('DELETE FROM shares WHERE file_id=?')->execute([$id]);
    $pdo->prepare('DELETE FROM files WHERE id=?')->execute([$id]);
}
function copyItem($id, $newParentId, $pdo, $STORAGE_DIR, $THUMBS_DIR, $LISTS_DIR) {
    $stmt = $pdo->prepare("SELECT * FROM files WHERE id = ?");
    $stmt->execute([$id]);
    $item = $stmt->fetch();
    if (!$item) return;

    if ($item['is_dir']) {
        $ins = $pdo->prepare("INSERT INTO files (parent_id, name, is_dir, mime_type, owner_id) VALUES (?, ?, 1, 'directory', ?)");
        $ins->execute([$newParentId, $item['name'] . ' (Copia)', $item['owner_id']]);
        $newId = $pdo->lastInsertId();
        
        $ch = $pdo->prepare("SELECT id FROM files WHERE parent_id = ?");
        $ch->execute([$id]);
        foreach ($ch->fetchAll() as $c) copyItem($c['id'], $newId, $pdo, $STORAGE_DIR, $THUMBS_DIR, $LISTS_DIR);
    } else {
        $newName = uniqid() . '_copy_' . preg_replace('/[^a-zA-Z0-9_.-]/', '_', $item['name']);
        @copy($STORAGE_DIR . $item['storage_name'], $STORAGE_DIR . $newName);
        if ($item['has_thumb']) @copy($THUMBS_DIR . $item['storage_name'] . '.webp', $THUMBS_DIR . $newName . '.webp');
        if ($item['has_list']) @copy($LISTS_DIR . $item['storage_name'] . '.list.gz', $LISTS_DIR . $newName . '.list.gz');

        $ins = $pdo->prepare("INSERT INTO files (parent_id, name, is_dir, mime_type, size, storage_name, has_thumb, has_list, owner_id) VALUES (?, ?, 0, ?, ?, ?, ?, ?, ?)");
        $ins->execute([$newParentId, $item['name'] . ' (Copia)', $item['mime_type'], $item['size'], $newName, $item['has_thumb'], $item['has_list'], $item['owner_id']]);
    }
}

// BATCH OPERATIONS
$ids = isset($_POST['ids']) ? json_decode($_POST['ids'], true) : [];
if (empty($ids) && isset($_POST['id'])) $ids = [$_POST['id']]; // fallback

if ($action === 'delete') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') dcFail(405);
    dcRequirePolicy($dcLogged,$dcIsAdmin,'can_delete');
    if (!is_array($ids) || !$ids || count($ids)>200) dcFail(400,'Selección inválida');
    foreach ($ids as $id) {
        if (!is_numeric($id) || (int)$id<=0 || !driveValidateId((int)$id,$pdo)) dcFail(404,'Archivo inexistente');
        if ((!dcOwnedTree((int)$id,$pdo,$dcOwnerId) || !dcOwnsTrashSubtree((int)$id,$pdo,$dcOwnerId))) dcFail(404,'Archivo inexistente');
    }
    $st=$pdo->prepare('UPDATE files SET deleted_at=NOW() WHERE id=? AND deleted_at IS NULL');
    foreach ($ids as $id) $st->execute([(int)$id]);
    dcUndoPush85l(['type'=>'delete','label'=>'Enviar a papelera (' . count($ids) . ')','items'=>array_map(static fn($id)=>['id'=>(int)$id],$ids)]);
    echo json_encode(['success'=>true]);exit;
}

if ($action === 'copy') {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') dcFail(405, 'Se requiere POST');
    dcRequirePolicy($dcLogged, $dcIsAdmin, 'can_upload');
    if (!is_array($ids) || !$ids || count($ids) > 100) dcFail(400, 'Selección inválida');
    $destRaw=(string)($_POST['new_parent_id'] ?? '');
    if (!ctype_digit($destRaw)) dcFail(400, 'Destino inválido');
    $dest=(int)$destRaw;
    if (!driveValidateId($dest,$pdo,true) || ($dest>0 && !dcOwnedTree($dest,$pdo,$dcOwnerId))) dcFail(403,'Destino no autorizado');
    $lock='dc-upload-'.$dcOwnerId;
    $lk=$pdo->prepare('SELECT GET_LOCK(?,15)');$lk->execute([$lock]);
    if ((int)$lk->fetchColumn()!==1) dcFail(503,'Almacenamiento ocupado; inténtalo nuevamente');
    $created=[];
    try {
        // Prevalidación: propiedad de cada descendiente, nombres, archivo físico y límites.
        $roots=[];$todo=[];$seen=[];$totalBytes=0;$count=0;
        $get=$pdo->prepare('SELECT * FROM files WHERE id=? AND deleted_at IS NULL');
        $children=$pdo->prepare('SELECT id FROM files WHERE parent_id=? AND deleted_at IS NULL ORDER BY id');
        foreach ($ids as $raw) {
            if (!is_scalar($raw) || !ctype_digit((string)$raw) || (int)$raw<1) throw new RuntimeException('ID inválido');
            $id=(int)$raw;
            if (isset($roots[$id])) continue;
            $roots[$id]=true;
            if (!dcOwnedTree($id,$pdo,$dcOwnerId)) throw new RuntimeException('Archivo sin autorización');
            if (isChildOf($dest,$id,$pdo)) throw new RuntimeException('No se permite copiar una carpeta dentro de sí misma');
            $todo[]=[$id,null];
        }
        // Bloquear selecciones solapadas: no duplicar subárboles accidentalmente.
        foreach (array_keys($roots) as $a) foreach (array_keys($roots) as $b) {
            if ($a!==$b && isChildOf($a,$b,$pdo)) throw new RuntimeException('Selecciona solo la carpeta padre');
        }
        $nodes=[];
        while ($todo) {
            [$id,$parentSource]=array_shift($todo);
            if (isset($seen[$id])) throw new RuntimeException('Ciclo en el árbol');
            $seen[$id]=true;
            if (++$count>2000) throw new RuntimeException('Máximo 2000 elementos por copia');
            $get->execute([$id]);$r=$get->fetch(PDO::FETCH_ASSOC);
            if (!$r) throw new RuntimeException('Elemento inexistente o eliminado');
            if (((int)$r['owner_id']!==$dcOwnerId || !dcOwnedTree($id,$pdo,$dcOwnerId))) throw new RuntimeException('Árbol con propietarios ajenos');
            $name=(string)$r['name'];
            if ($name==='' || $name==='.' || $name==='..' || strpbrk($name,"/\\\r\n\0")!==false) throw new RuntimeException('Nombre inseguro');
            if (!(int)$r['is_dir']) {
                $stored=(string)$r['storage_name'];
                if ($stored==='' || $stored==='.' || $stored==='..' || basename($stored)!==$stored || !is_file($STORAGE_DIR.$stored) || is_link($STORAGE_DIR.$stored) || !is_readable($STORAGE_DIR.$stored)) throw new RuntimeException('Archivo original no disponible');
                $sz=filesize($STORAGE_DIR.$stored);
                if ($sz===false || ($totalBytes+=$sz)>1024*1024*1024) throw new RuntimeException('Máximo 1 GiB por copia');
            }
            $nodes[$id]=['record'=>$r,'parent'=>$parentSource];
            if ((int)$r['is_dir']) {
                $children->execute([$id]);
                foreach ($children->fetchAll(PDO::FETCH_COLUMN) as $child) $todo[]=[(int)$child,$id];
            }
        }
        $quota=(int)($dcLogged['quota_bytes']??0);
        if (!$dcIsAdmin && $quota>0) {
            $st=$pdo->prepare('SELECT COALESCE(SUM(size),0) FROM files WHERE owner_id=? AND is_dir=0');$st->execute([$dcOwnerId]);$used=(float)$st->fetchColumn();
            $st=$pdo->prepare('SELECT COALESCE(SUM(v.size),0) FROM file_versions v JOIN files f ON f.id=v.file_id WHERE f.owner_id=?');$st->execute([$dcOwnerId]);$used+=(float)$st->fetchColumn();
            if ($used+$totalBytes>$quota) throw new RuntimeException('Cuota insuficiente para copiar la selección');
        }
        if (disk_free_space($STORAGE_DIR)<$totalBytes) throw new RuntimeException('Espacio físico insuficiente');
        $pdo->beginTransaction();
        $mapped=[];
        $conflict=$pdo->prepare('SELECT 1 FROM files WHERE parent_id=? AND name=? AND owner_id=? AND deleted_at IS NULL LIMIT 1');
        $insert=$pdo->prepare('INSERT INTO files (parent_id,name,is_dir,mime_type,size,storage_name,has_thumb,has_list,owner_id,version_enabled) VALUES (?,?,?,?,?,?,0,0,?,?)');
        foreach ($nodes as $sourceId=>$node) {
            $r=$node['record'];$parentSource=$node['parent'];
            $targetParent=$parentSource===null ? $dest : ($mapped[$parentSource] ?? null);
            if ($targetParent===null) throw new RuntimeException('Jerarquía de copia inválida');
            $name=(string)$r['name'];
            if ($parentSource===null) {
                $base=$name.' (Copia)';$name=$base;$suffix=2;
                do {$conflict->execute([$targetParent,$name,$dcOwnerId]);$exists=$conflict->fetchColumn();if ($exists!==false) $name=$base.' '.$suffix++;}
                while ($exists!==false && $suffix<1001);
                if ($exists!==false || strlen($name)>255) throw new RuntimeException('No se pudo crear un nombre de copia');
            }
            $stored=null;
            if (!(int)$r['is_dir']) {
                $stored=bin2hex(random_bytes(16)).'_copy';
                $source=$STORAGE_DIR.$r['storage_name'];$target=$STORAGE_DIR.$stored;
                if (!copy($source,$target)) throw new RuntimeException('Error al duplicar el archivo físico');
                $created[]=$target;
                if (filesize($target)!==filesize($source)) throw new RuntimeException('Copia física incompleta');
            }
            $insert->execute([$targetParent,$name,(int)$r['is_dir'],(string)$r['mime_type'],(int)$r['size'],$stored,$dcOwnerId,(int)$r['version_enabled']]);
            $mapped[$sourceId]=(int)$pdo->lastInsertId();
        }
        $pdo->commit();
        foreach($mapped as $newId)dcActivity85m($pdo,$dcOwnerId,(int)$newId,'copy');
        echo json_encode(['success'=>true,'copied_roots'=>count($roots),'copied_items'=>count($nodes)]);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        foreach ($created as $path) @unlink($path);
        dcFail(409,$e->getMessage());
    } finally {
        $release=$pdo->prepare('SELECT RELEASE_LOCK(?)');$release->execute([$lock]);
    }
    exit;
}

if ($action === 'move') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') dcFail(405);
    if (!is_array($ids) || !$ids || count($ids) > 200) dcFail(400,'Selección inválida');
    $destRaw = $_POST['new_parent_id'] ?? null;
    if ($destRaw === null || !ctype_digit((string)$destRaw)) dcFail(400,'Destino inválido');
    $dest = (int)$destRaw;
    if (!driveValidateId($dest,$pdo,true)) dcFail(404,'Carpeta de destino inexistente');
    if ($dest !== 0 && !dcOwnedTree($dest,$pdo,$dcOwnerId)) dcFail(403,'Destino sin autorización');
    $items=[];
    $check=$pdo->prepare('SELECT id,parent_id,name,is_dir FROM files WHERE id=? AND deleted_at IS NULL LIMIT 1');
    $conflict=$pdo->prepare('SELECT id FROM files WHERE parent_id=? AND name=? AND owner_id=? AND deleted_at IS NULL LIMIT 1');
    foreach ($ids as $rawId) {
        if (!is_scalar($rawId) || !ctype_digit((string)$rawId) || (int)$rawId < 1) dcFail(400,'Elemento inválido');
        $id=(int)$rawId;
        if (isset($items[$id])) continue;
        $check->execute([$id]);$item=$check->fetch(PDO::FETCH_ASSOC);
        if (!$item || !driveValidateId($id,$pdo)) dcFail(404,'Elemento inexistente');
        if ((!dcOwnedTree($id,$pdo,$dcOwnerId) || !dcOwnsTrashSubtree($id,$pdo,$dcOwnerId))) dcFail(403,'Elemento sin autorización');
        if ($dest !== 0 && isChildOf($dest,$id,$pdo)) dcFail(409,'No puedes mover una carpeta dentro de sí misma');
        if ((int)$item['parent_id'] === $dest) continue;
        $conflict->execute([$dest,$item['name'],$dcOwnerId]);
        if ($conflict->fetchColumn() !== false) dcFail(409,'Ya existe un elemento con ese nombre en el destino');
        $items[$id]=$item;
    }
    // Evitar mover simultáneamente una carpeta y su descendiente.
    foreach ($items as $id=>$item) {
        foreach ($items as $otherId=>$unused) {
            if ($id !== $otherId && isChildOf($id,$otherId,$pdo)) dcFail(409,'Selecciona solo la carpeta padre, no sus descendientes');
        }
    }
    try {
        $pdo->beginTransaction();
        $move=$pdo->prepare('UPDATE files SET parent_id=? WHERE id=? AND deleted_at IS NULL');
        foreach ($items as $id=>$item) $move->execute([$dest,$id]);
        $pdo->commit();
        foreach(array_keys($items) as $movedId)dcActivity85m($pdo,$dcOwnerId,(int)$movedId,'move');
        if ($items) dcUndoPush85l(['type'=>'move','label'=>'Mover (' . count($items) . ')','items'=>array_values(array_map(static fn($item)=>['id'=>(int)$item['id'],'before'=>(int)$item['parent_id'],'after'=>$dest],$items))]);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        dcFail(500,'No se pudo completar el movimiento');
    }
    echo json_encode(['success'=>true,'moved'=>count($items)]);exit;
}

if ($action === 'search') {
    if(isset($_GET['scope_root'])) {
        require_once __DIR__.'/dc_scoped_search_dcft.php';
        dcScopedSearchDcft($pdo,$dcOwnerId);
    }
    if (($_SESSION['dc_global_search'] ?? false) !== true) { http_response_code(403); exit(json_encode(['error'=>'Activa la búsqueda global en Configuración.'])); }
    $q = $_GET['q'] ?? '';
    $cs = isset($_GET['cs']) && $_GET['cs'] == '1';
    if (empty(trim($q))) { echo json_encode(['files' => []]); exit; }
    
    if ($cs) {
        $stmt = $pdo->prepare("SELECT * FROM files WHERE deleted_at IS NULL AND name LIKE BINARY ?" . ' AND owner_id=?' . " ORDER BY is_dir DESC, name ASC LIMIT 400");
        $stmt->execute(['%' . $q . '%',$dcOwnerId]);
    } else {
        $stmt = $pdo->prepare("SELECT * FROM files WHERE deleted_at IS NULL AND name LIKE ?" . ' AND owner_id=?' . " ORDER BY is_dir DESC, name ASC LIMIT 400");
        $stmt->execute(['%' . $q . '%',$dcOwnerId]);
    }
    $items=$stmt->fetchAll(PDO::FETCH_ASSOC);
    $items=array_values(array_filter($items,fn($f)=>dcOwnedTree((int)$f['id'],$pdo,$dcOwnerId)));
    $mimeFilter=(string)($_GET['type']??'');
    $minSize=isset($_GET['min_size']) ? max(0,(int)$_GET['min_size']) : 0;
    $maxSize=isset($_GET['max_size']) && $_GET['max_size']!=='' ? max(0,(int)$_GET['max_size']) : PHP_INT_MAX;
    $items=array_values(array_filter($items,function($f)use($mimeFilter,$minSize,$maxSize){
        if ($mimeFilter!=='' && !str_starts_with((string)$f['mime_type'],$mimeFilter)) return false;
        return !empty($f['is_dir']) ? $mimeFilter==='' : ((int)$f['size'] >= $minSize && (int)$f['size'] <= $maxSize);
    }));
    foreach ($items as &$searchItem) $searchItem['ruta_virtual']=dcVirtualPath((int)$searchItem['id'],$pdo,$dcOwnerId); unset($searchItem);
    echo json_encode(['files'=>dcVersionBadges(array_slice($items,0,300),$pdo),'limited'=>count($items)>300]);
    exit;
}

if ($action === 'tree') {
    $stmt = $pdo->prepare('SELECT id,parent_id,name,is_dir,version_enabled,is_album FROM files WHERE is_dir=1 AND deleted_at IS NULL' . ' AND owner_id=?' . ' ORDER BY name ASC');
    $stmt->execute([$dcOwnerId]);
    $folders=$stmt->fetchAll(PDO::FETCH_ASSOC);
    $folders=array_values(array_filter($folders,fn($f)=>dcOwnedTree((int)$f['id'],$pdo,$dcOwnerId)));
    echo json_encode(['folders'=>dcVersionBadges($folders,$pdo)]);
    exit;
}

// Duración flexible: todos los componentes son enteros no negativos.
function shareDurationSeconds($input) {
    $units = ['days' => 86400, 'hours' => 3600, 'minutes' => 60];
    $seconds = 0;
    foreach ($units as $key => $multiplier) {
        $value = $input[$key] ?? 0;
        if (!is_scalar($value) || !preg_match('/^\d{1,6}$/D', (string)$value)) return false;
        $number = (int)$value;
        if ($number > 3650) return false;
        $seconds += $number * $multiplier;
    }
    return $seconds <= 315360000 ? $seconds : false; // máximo 10 años
}
function shareExpiry($seconds) {
    if ($seconds <= 0) return null;
    global $pdo;
    $q=$pdo->prepare('SELECT DATE_ADD(NOW(), INTERVAL ? SECOND)');
    $q->execute([(int)$seconds]);
    return $q->fetchColumn() ?: null;
}

if ($action === 'all_shares') {
    $sql='SELECT s.id, s.hash, s.expires_at, s.created_at, s.password_hash IS NOT NULL AS protected,
        (SELECT COUNT(*) FROM share_events e WHERE e.share_id=s.id AND e.event_type=\'view\') AS views,
        (SELECT COUNT(*) FROM share_events e WHERE e.share_id=s.id AND e.event_type=\'download\') AS downloads,
        f.id AS file_id, f.name, f.is_dir FROM shares s JOIN files f ON f.id=s.file_id';
    $sql.=' WHERE s.owner_id=? AND f.owner_id=?';
    $sql.=' ORDER BY s.created_at DESC, s.id DESC';
    $st=$pdo->prepare($sql);$st->execute([$dcOwnerId,$dcOwnerId]);
    $rows=$st->fetchAll(PDO::FETCH_ASSOC);
    $rows=array_values(array_filter($rows,fn($r)=>dcOwnedTree((int)$r['file_id'],$pdo,$dcOwnerId)));
    echo json_encode(['shares'=>$rows]);exit;
}
if ($action === 'update_share') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') dcFail(405);
    $id=(int)($_POST['share_id']??0);$seconds=shareDurationSeconds($_POST);
    if ($id<=0 || $seconds===false) dcFail(400,'Duración o enlace inválido');
    dcGuardShare($id,$pdo,false,$dcOwnerId);
    $st=$pdo->prepare('UPDATE shares SET expires_at=? WHERE id=?');
    $st->execute([shareExpiry($seconds),$id]);
    $item=$pdo->prepare('SELECT file_id FROM shares WHERE id=? AND owner_id=?');$item->execute([$id,$dcOwnerId]);
    $affected=(int)$item->fetchColumn();if($affected>0)dcActivity85m($pdo,$dcOwnerId,$affected,'share');
    echo json_encode(['success'=>true]);exit;
}
if ($action === 'get_shares') {
    $id=(int)($_GET['id']??0);
    dcReadFileGuard($id,$pdo,false,$dcOwnerId);
    $sql='SELECT id,hash,expires_at,created_at FROM shares WHERE file_id=?';
    $sql.=' AND owner_id=?';
    $sql.=' ORDER BY created_at DESC';
    $st=$pdo->prepare($sql);$st->execute([$id,$dcOwnerId]);
    echo json_encode(['shares'=>$st->fetchAll(PDO::FETCH_ASSOC)]);exit;
}
if ($action === 'revoke_all_shares_60') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') dcFail(405);
    if ((string)($_POST['confirm']??'') !== 'REVOKCAR_TODOS_60') dcFail(400,'Confirmación obligatoria');
    // Eliminar exclusivamente enlaces propios, no enlaces de recepción ni los de otros usuarios.
    $st=$pdo->prepare('DELETE FROM shares WHERE owner_id=?');
    $st->execute([$dcOwnerId]);
    echo json_encode(['success'=>true,'revoked'=>$st->rowCount()]);exit;
}
if ($action === 'delete_share') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') dcFail(405);
    $id=(int)($_POST['share_id']??0);
    dcGuardShare($id,$pdo,false,$dcOwnerId);
    $st=$pdo->prepare('DELETE FROM shares WHERE id=?');$st->execute([$id]);
    echo json_encode(['success'=>true]);exit;
}

// Miniatura PDF: se crea solo al solicitarla y se conserva en /drive/thumbs/.
// Primero Poppler (pdftoppm), que evita las restricciones habituales de
// ImageMagick sobre PDF. Si no está instalado, intentar ImageMagick.
// El timeout evita conversiones largas en una Raspberry Pi.
function ensurePdfThumbnail($file, $pdo, $STORAGE_DIR, $THUMBS_DIR) {
    if (!$file || $file['is_dir'] || stripos((string)$file['mime_type'], 'pdf') === false) return false;
    $original = $STORAGE_DIR . $file['storage_name'];
    $target = $THUMBS_DIR . $file['storage_name'] . '.webp';
    if (!is_file($original)) return false;
    if (is_file($target) && filesize($target) > 0) return true;
    $tmpBase = $THUMBS_DIR . 'pdf-' . bin2hex(random_bytes(10));
    $tmp = $tmpBase . '.webp';
    if (trim((string)shell_exec('command -v pdftoppm 2>/dev/null')) !== '') {
        $cmd = 'timeout 12s nice -n 10 pdftoppm -f 1 -l 1 -singlefile -scale-to 360 -png ' .
            escapeshellarg($original) . ' ' . escapeshellarg($tmpBase) . ' 2>/dev/null';
        exec($cmd, $output, $status);
    }
    if (!is_file($tmp) && trim((string)shell_exec('command -v convert 2>/dev/null')) !== '') {
        // Puede fallar si la política de ImageMagick bloquea PDF; no se modifica la política.
        $cmd = 'timeout 12s nice -n 10 convert -limit memory 64MiB -limit map 128MiB -density 90 ' .
            escapeshellarg($original . '[0]') . ' -thumbnail 360x360 -background white -alpha remove -quality 76 ' .
            escapeshellarg($tmp) . ' 2>/dev/null';
        exec($cmd, $output, $status);
    }
    if (is_file($tmp) && filesize($tmp) > 0 && @rename($tmp, $target)) {
        $pdo->prepare('UPDATE files SET has_thumb = 1 WHERE id = ?')->execute([$file['id']]);
        return true;
    }
    @unlink($tmp);
    return false;
}

/** RAW de cámara: se tratan como fotos, pero nunca se decodifican directamente. */
function dcIsRawPhoto(array $file): bool {
    if (!$file || !empty($file['is_dir'])) return false;
    $mime = strtolower((string)($file['mime_type'] ?? ''));
    if (strpos($mime, 'raw') !== false || preg_match('#^image/x-(canon|nikon|sony|olympus|pentax|panasonic|fujifilm|samsung|adobe|leica|leaf|phaseone)-#', $mime)) return true;
    $ext = strtolower(pathinfo((string)($file['name'] ?? ''), PATHINFO_EXTENSION));
    return in_array($ext, ['cr2','cr3','nef','nrw','arw','srf','sr2','dng','raf','orf','rw2','rwl','pef','srw','x3f','iiq','3fr','fff','mrw'], true);
}

/**
 * Miniatura ligera para fotografías (modo explorador de álbumes y vista grid).
 * Máx. 512 px en el lado largo, JPEG calidad 78. Ocupa poco y admite iconos grandes.
 * Se genera al subir o bajo demanda en action=thumb. Si se elimina la foto, deleteItem
 * ya borra $THUMBS_DIR.$storage_name.'.jpg' cuando has_thumb=1.
 */
function dcIsImageFile(array $file): bool {
    if (!$file || !empty($file['is_dir'])) return false;
    if (dcIsRawPhoto($file)) return true;
    $mime = strtolower((string)($file['mime_type'] ?? ''));
    if (preg_match('#^image/(jpeg|pjpeg|png|gif|webp|bmp|x-ms-bmp|avif)$#', $mime)) return true;
    $ext = strtolower(pathinfo((string)($file['name'] ?? ''), PATHINFO_EXTENSION));
    return in_array($ext, ['jpg','jpeg','jpe','png','gif','webp','bmp','avif'], true);
}

/**
 * JPEG usable para generar la miniatura de un RAW, sin tocar los datos del sensor.
 * 1) El JPG hermano (mismo nombre base, carpeta y propietario), que es lo que la
 *    cámara grabó junto al RAW. 2) La vista previa JPEG incrustada en el propio RAW.
 * Devuelve [path, temporary]; si temporary=true el llamador debe borrar el archivo.
 */
function dcRawPreviewJpeg(array $file, PDO $pdo, string $STORAGE_DIR): ?array {
    $name = (string)($file['name'] ?? '');
    $base = pathinfo($name, PATHINFO_FILENAME);
    $parent = (int)($file['parent_id'] ?? 0);
    $owner = (int)($file['owner_id'] ?? 0);
    $root = rtrim($STORAGE_DIR, '/');

    if ($base !== '') {
        $candidates = [];
        foreach (['jpg', 'jpeg', 'jpe'] as $ext) $candidates[] = strtolower($base . '.' . $ext);
        // Algunas importaciones separaron el RAW y su JPG en carpetas distintas.
        // Se busca en toda la unidad, priorizando la misma carpeta y propietario.
        $sql = 'SELECT storage_name, parent_id, owner_id FROM files WHERE is_dir=0 AND deleted_at IS NULL AND LOWER(name) IN (' . implode(',', array_fill(0, count($candidates), '?')) . ')';
        $order = [];
        if ($parent > 0) $order[] = '(parent_id = ' . $parent . ') DESC';
        if ($owner > 0) $order[] = '(owner_id = ' . $owner . ') DESC';
        $sql .= ' ORDER BY ' . ($order ? implode(', ', $order) . ', ' : '') . 'id ASC LIMIT 10';
        $st = $pdo->prepare($sql);
        $st->execute($candidates);
        foreach ($st as $row) {
            $p = $root . '/' . basename((string)$row['storage_name']);
            if (is_file($p) && is_readable($p) && @getimagesize($p)) return ['path' => $p, 'temporary' => false];
        }
    }

    // Sin JPG hermano: tomar la vista previa JPEG incrustada (en CR2 suele estar
    // a partir del byte ~72k). Leemos sólo los primeros 4 MiB para no cargar el RAW.
    $raw = $root . '/' . basename((string)($file['storage_name'] ?? ''));
    if (!is_file($raw) || !is_readable($raw)) return null;
    $fh = @fopen($raw, 'rb');
    if (!$fh) return null;
    $head = @fread($fh, 4 * 1024 * 1024);
    fclose($fh);
    if (!is_string($head) || strlen($head) < 4) return null;
    $best = null;
    $offset = 0;
    while (($soi = strpos($head, "\xFF\xD8\xFF", $offset)) !== false) {
        $eoi = strpos($head, "\xFF\xD9", $soi + 3);
        if ($eoi !== false) {
            $len = $eoi - $soi + 2;
            if ($len > 8192 && $len < 6 * 1024 * 1024 && (!$best || $len > $best['len'])) {
                $bytes = substr($head, $soi, $len);
                $dim = @getimagesizefromstring($bytes);
                if ($dim && $dim[0] >= 160 && $dim[1] >= 120 && $dim[0] <= 12000 && $dim[1] <= 12000 && ($dim[0] * $dim[1]) <= 40000000) {
                    $best = ['bytes' => $bytes, 'len' => $len];
                }
            }
        }
        $offset = $soi + 3;
    }
    if (!$best) return null;
    $tmp = @tempnam(sys_get_temp_dir(), 'dcraw');
    if (!$tmp) return null;
    if (file_put_contents($tmp, $best['bytes']) === false) { @unlink($tmp); return null; }
    return ['path' => $tmp, 'temporary' => true];
}

/** Orientación EXIF 1–8 → recurso GD ya orientado (o el original si no aplica). */
function dcGdApplyExifOrientation($src, string $path) {
    if (!$src || !function_exists('exif_read_data') || !function_exists('imagerotate')) return $src;
    $exif = @exif_read_data($path);
    if (!$exif || empty($exif['Orientation'])) return $src;
    $orientation = (int)$exif['Orientation'];
    if ($orientation === 1) return $src;
    $rotated = $src;
    switch ($orientation) {
        case 2: // espejo horizontal
            if (function_exists('imageflip')) imageflip($rotated, IMG_FLIP_HORIZONTAL);
            break;
        case 3:
            $rotated = imagerotate($src, 180, 0);
            break;
        case 4:
            if (function_exists('imageflip')) imageflip($rotated, IMG_FLIP_VERTICAL);
            break;
        case 5:
            if (function_exists('imageflip')) imageflip($rotated, IMG_FLIP_VERTICAL);
            $tmp = imagerotate($rotated, -90, 0);
            if ($tmp && $tmp !== $rotated) { if ($rotated !== $src) imagedestroy($rotated); $rotated = $tmp; }
            break;
        case 6: // 90° horario (típico de móvil en vertical)
            $rotated = imagerotate($src, -90, 0);
            break;
        case 7:
            if (function_exists('imageflip')) imageflip($rotated, IMG_FLIP_HORIZONTAL);
            $tmp = imagerotate($rotated, -90, 0);
            if ($tmp && $tmp !== $rotated) { if ($rotated !== $src) imagedestroy($rotated); $rotated = $tmp; }
            break;
        case 8: // 90° antihorario
            $rotated = imagerotate($src, 90, 0);
            break;
    }
    if ($rotated && $rotated !== $src) imagedestroy($src);
    return $rotated ?: $src;
}

function ensureImageThumbnail($file, $pdo, $STORAGE_DIR, $THUMBS_DIR, int $maxSide = 960, bool $force = false): bool {
    if (!dcIsImageFile($file)) return false;
    $storage = (string)($file['storage_name'] ?? '');
    if ($storage === '' || basename($storage) !== $storage) return false;
    $original = rtrim($STORAGE_DIR, '/').'/'.$storage;
    $target = rtrim($THUMBS_DIR, '/').'/'.$storage.'.webp';
    if (!is_file($original) || !is_readable($original)) return false;
    if (!$force && is_file($target) && filesize($target) > 0) {
        if (empty($file['has_thumb'])) {
            $pdo->prepare('UPDATE files SET has_thumb = 1 WHERE id = ?')->execute([(int)$file['id']]);
        }
        return true;
    }
    if ($force) @unlink($target);

    // Los RAW no se decodifican: se usa el JPG hermano o la vista previa incrustada.
    $source = $original;
    $tempSource = null;
    if (dcIsRawPhoto($file)) {
        $preview = dcRawPreviewJpeg($file, $pdo, $STORAGE_DIR);
        if (!$preview) return false;
        $source = (string)$preview['path'];
        $tempSource = !empty($preview['temporary']) ? $source : null;
        if (!is_file($source) || !is_readable($source)) {
            if ($tempSource) @unlink($tempSource);
            return false;
        }
    }
    // En RAW usamos GD directamente: ImageMagick tarda ~7 s por archivo en esta Pi
    // y la vista previa incrustada de algunos modelos llega a colgarlo.
    $preferGd = dcIsRawPhoto($file) || ($tempSource !== null);
    try {
        $info = @getimagesize($source);
        if ($info === false || empty($info[0]) || empty($info[1])) return false;
        $w = (int)$info[0]; $h = (int)$info[1];
        if ($w < 1 || $h < 1) return false;
        // Evitar decodificar imágenes enormes en RAM (protección Raspberry / memoria limitada).
        if ($w * $h > 40000000) return false; // ~40 Mpx
        $tmp = $target.'.'.bin2hex(random_bytes(6)).'.tmp.webp';
        $ok = false;
        // Preferir ImageMagick: -auto-orient corrige EXIF de forma fiable.
        if (!$preferGd && trim((string)shell_exec('command -v convert 2>/dev/null')) !== '') {
            $cmd = 'timeout -k 5s 15s nice -n 10 convert -limit memory 96MiB -limit map 192MiB '
                . escapeshellarg($source)
                . ' -auto-orient -thumbnail ' . (int)$maxSide . 'x' . (int)$maxSide
                . ' -background white -alpha remove -quality 78 '
                . escapeshellarg($tmp) . ' 2>/dev/null';
            exec($cmd, $out, $status);
            $ok = is_file($tmp) && filesize($tmp) > 0;
        }
        if (!$ok && !$preferGd && trim((string)shell_exec('command -v magick 2>/dev/null')) !== '') {
            $cmd = 'timeout -k 5s 15s nice -n 10 magick -limit memory 96MiB -limit map 192MiB '
                . escapeshellarg($source)
                . ' -auto-orient -thumbnail ' . (int)$maxSide . 'x' . (int)$maxSide
                . ' -background white -alpha remove -quality 78 '
                . escapeshellarg($tmp) . ' 2>/dev/null';
            exec($cmd, $out, $status);
            $ok = is_file($tmp) && filesize($tmp) > 0;
        }
        if (!$ok && extension_loaded('gd') && function_exists('imagecreatefromstring')) {
            $raw = @file_get_contents($source);
            if ($raw !== false) {
                $src = @imagecreatefromstring($raw);
                unset($raw);
                if ($src) {
                    $src = dcGdApplyExifOrientation($src, $source);
                    $w = imagesx($src); $h = imagesy($src);
                    $scale = min(1.0, $maxSide / max($w, $h));
                    $nw = max(1, (int)round($w * $scale));
                    $nh = max(1, (int)round($h * $scale));
                    $dst = imagecreatetruecolor($nw, $nh);
                    if ($dst) {
                        imagealphablending($dst, true);
                        $white = imagecolorallocate($dst, 255, 255, 255);
                        imagefilledrectangle($dst, 0, 0, $nw, $nh, $white);
                        if (@imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h)) {
                            if (@imagewebp($dst, $tmp, 78) && is_file($tmp) && filesize($tmp) > 0) {
                                $ok = true;
                            }
                        }
                        imagedestroy($dst);
                    }
                    imagedestroy($src);
                }
            }
        }
        if ($ok && @rename($tmp, $target)) {
            $pdo->prepare('UPDATE files SET has_thumb = 1 WHERE id = ?')->execute([(int)$file['id']]);
            return true;
        }
        @unlink($tmp);
        return false;
    } finally {
        if ($tempSource) @unlink($tempSource);
    }
}

/**
 * v20.10.86sk3 — generación cooperativa de miniaturas.
 * Una sola conversión ImageMagick a la vez (configurable con DC_THUMB_CONCURRENCY)
 * y un bloqueo en MariaDB para no repetir la misma foto en varias peticiones.
 * flock() no es fiable en el montaje NTFS, por eso se usa GET_LOCK() de la BD.
 * Si no hay hueco libre se devuelve un pixel transparente con X-DC-Thumb-Pending=1;
 * el frontend lo reencola sin dejar el skeleton fijo ni bloquear la navegación.
 */
function dcThumbPendingResponse(): void {
    header('Content-Type: image/gif');
    header('Cache-Control: no-store, max-age=0');
    header('X-DC-Thumb-Pending: 1');
    header('X-DC-Thumb-Retry: 1200');
    header('Retry-After: 1');
    echo base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7', true);
    exit;
}
function dcThumbLock(PDO $pdo, string $name): bool {
    $st = $pdo->prepare('SELECT GET_LOCK(?, 0)');
    $st->execute([$name]);
    return (int)$st->fetchColumn() === 1;
}
function dcThumbUnlock(PDO $pdo, string $name): void {
    $st = $pdo->prepare('SELECT RELEASE_LOCK(?)');
    $st->execute([$name]);
}
function dcThumbAcquire(PDO $pdo, int $id) {
    $fileLock = 'dc_thumb_file_' . $id;
    if (!dcThumbLock($pdo, $fileLock)) return null;
    $slots = (int)(getenv('DC_THUMB_CONCURRENCY') ?: 1);
    if ($slots < 1) $slots = 1;
    if ($slots > 2) $slots = 2;
    for ($i = 0; $i < $slots; $i++) {
        $slotLock = 'dc_thumb_slot_' . $i;
        if (dcThumbLock($pdo, $slotLock)) {
            return ['pdo' => $pdo, 'file' => $fileLock, 'slot' => $slotLock];
        }
    }
    dcThumbUnlock($pdo, $fileLock);
    return null;
}
function dcThumbRelease($locks): void {
    if (!is_array($locks) || empty($locks['pdo']) || !($locks['pdo'] instanceof PDO)) return;
    if (!empty($locks['slot'])) dcThumbUnlock($locks['pdo'], $locks['slot']);
    if (!empty($locks['file'])) dcThumbUnlock($locks['pdo'], $locks['file']);
}

// Comprobar duplicados antes de enviar fragmentos; el servidor vuelve a comprobar al finalizar.
if ($action === 'upload_conflict') {
    $parentId=(int)($_GET['parent_id']??-1);
    $name=(string)($_GET['name']??'');
    if (!driveValidateId($parentId,$pdo,true) || ($parentId>0 && !dcOwnedTree($parentId,$pdo,$dcOwnerId))) dcFail(403,'Carpeta no autorizada');
    if ($name==='' || strlen($name)>240 || basename($name)!==$name || str_contains($name,'\\') || preg_match('/[\x00-\x1f]/',$name)) dcFail(422,'Nombre inválido');
    $st=$pdo->prepare('SELECT id,is_dir,owner_id FROM files WHERE parent_id=? AND name=? AND owner_id=? AND deleted_at IS NULL LIMIT 1');
    $st->execute([$parentId,$name,$dcOwnerId]);$existing=$st->fetch(PDO::FETCH_ASSOC);
    if ($existing && ((int)$existing['owner_id']!==$dcOwnerId || !dcOwnedTree((int)$existing['id'],$pdo,$dcOwnerId))) dcFail(409,'Nombre ocupado por otro elemento');
    echo json_encode(['exists'=>(bool)$existing,'is_dir'=>$existing?(bool)$existing['is_dir']:false,'versioned'=>driveTreeFlag($parentId,'version_enabled',$pdo)]);exit;
}
// Nombre de preservación para un archivo anterior; se mantiene la extensión.
function dcPreviousName(PDO $pdo, int $parent, string $name, int $ownerId): string {
    $info=pathinfo($name);$ext=isset($info['extension'])?'.'.$info['extension']:'';
    $stem=$ext!==''?substr($name,0,-strlen($ext)):$name;
    $suffix=' (anterior '.date('Ymd-His').')';
    for ($i=0;$i<100;$i++) {
        $end=$suffix.($i?'-'.$i:'').$ext;
        $candidate=substr($stem,0,max(1,240-strlen($end))).$end;
        $st=$pdo->prepare('SELECT 1 FROM files WHERE parent_id=? AND name=? AND owner_id=? AND deleted_at IS NULL LIMIT 1');
        $st->execute([$parent,$candidate,$ownerId]);
        if (!$st->fetchColumn()) return $candidate;
    }
    throw new RuntimeException('No se pudo reservar un nombre para el archivo anterior');
}
// Fast upload: reutiliza inode existente por content_hash + size (sin reenviar bytes).
// El cliente calcula la huella localmente; el servidor nunca confía en rutas ajenas expuestas.
if ($action === 'upload_dedup') {
    if (!$dcIsAdmin && empty($dcLogged['can_upload'])) dcFail(403,'Tu cuenta no tiene permiso para subir archivos');
    if (($_SERVER['REQUEST_METHOD']??'') !== 'POST') dcFail(405,'Se requiere POST');
    $parentId=(int)($_POST['parentId']??-1);
    if (!driveValidateId($parentId,$pdo,true) || ($parentId>0 && !dcOwnedTree($parentId,$pdo,$dcOwnerId))) dcFail(403,'Carpeta de destino no autorizada');
    $name=(string)($_POST['fileName']??'');
    if ($name==='' || strlen($name)>240 || basename($name)!==$name || str_contains($name,'/') || str_contains($name,'\\') || preg_match('/[\x00-\x1f]/',$name)) dcFail(422,'Nombre de archivo inválido');
    $size=filter_var($_POST['size']??null,FILTER_VALIDATE_INT);
    if ($size===false || $size<0) dcFail(422,'Tamaño inválido');
    $algorithm=(string)($_POST['algorithm']??'');
    if (!in_array($algorithm,['sha256','merkle'],true)) dcFail(422,'Algoritmo inválido');
    $digest=(string)($_POST['content_hash']??'');
    if (!preg_match('/^[a-f0-9]{64}$/D',$digest)) dcFail(422,'Hash inválido');
    $headHash=(string)($_POST['head_hash']??'');
    if ($headHash!=='' && !preg_match('/^[a-f0-9]{64}$/D',$headHash)) dcFail(422,'head_hash inválido');
    $max=(int)($dcLogged['max_file_bytes']??0);
    $quota=(int)($dcLogged['quota_bytes']??0);
    if ($max>0 && $size>$max) dcFail(413,'Supera el tamaño máximo por archivo');

    $lockName='dc-upload-'.$dcOwnerId;
    $lk=$pdo->prepare('SELECT GET_LOCK(?,15)');$lk->execute([$lockName]);
    if ((int)$lk->fetchColumn()!==1) dcFail(503,'Subida ocupada, vuelve a intentarlo');
    try {
        $st=$pdo->prepare('SELECT * FROM files WHERE parent_id=? AND name=? AND owner_id=? AND deleted_at IS NULL LIMIT 1');
        $st->execute([$parentId,$name,$dcOwnerId]);$old=$st->fetch(PDO::FETCH_ASSOC);
        $versioned=driveTreeFlag($parentId,'version_enabled',$pdo);
        $mode=(string)($_POST['conflict_mode']??'');
        if ($old && ((int)$old['is_dir']!==0 || (int)$old['owner_id']!==$dcOwnerId || !dcOwnedTree((int)$old['id'],$pdo,$dcOwnerId))) throw new RuntimeException('Nombre ocupado o elemento no autorizado');
        if ($old && !$versioned && !in_array($mode,['replace','rename_old'],true)) throw new RuntimeException('Conflicto: elige cómo tratar el archivo existente');
        if ($quota>0) {
            $st=$pdo->prepare('SELECT COALESCE(SUM(size),0) FROM files WHERE owner_id=?');$st->execute([$dcOwnerId]);
            $used=(float)$st->fetchColumn();
            $st=$pdo->prepare('SELECT COALESCE(SUM(v.size),0) FROM file_versions v JOIN files f ON f.id=v.file_id WHERE f.owner_id=?');
            $st->execute([$dcOwnerId]);$used+=(float)$st->fetchColumn();
            if ($old && !$versioned && $mode==='replace') $used-=(float)$old['size'];
            if ($used+$size>$quota) throw new RuntimeException('Cuota de almacenamiento excedida (incluye historial)');
        }
        // Buscar candidato físico global con el mismo digest (no se revela la ruta al cliente).
        $sql='SELECT storage_name,mime_type,head_hash FROM files WHERE is_dir=0 AND size=? AND content_hash_algorithm=? AND content_hash=?';
        $params=[$size,$algorithm,$digest];
        if ($headHash!=='') { $sql.=' AND (head_hash IS NULL OR head_hash=?)'; $params[]=$headHash; }
        $sql.=' LIMIT 30';
        $q=$pdo->prepare($sql);$q->execute($params);
        $sourcePath=null;$mime='application/octet-stream';$resolvedHead=$headHash!==''?$headHash:null;
        $merkleChunk=dcMerkleChunkSize($size, dcChunkEffectiveMiB($pdo));
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $cand) {
            $stored=(string)($cand['storage_name']??'');
            if ($stored==='' || basename($stored)!==$stored || !preg_match('/^[a-zA-Z0-9_.-]+$/D',$stored)) continue;
            $candidate=$STORAGE_DIR.$stored;
            if (!is_file($candidate) || is_link($candidate)) continue;
            clearstatcache(true,$candidate);
            if ((int)filesize($candidate)!==$size) continue;
            // El hash lo envía el cliente: se vuelve a calcular siempre en el servidor.
            try {
                if ($algorithm==='sha256') {
                    if (@hash_file('sha256',$candidate)!==$digest) continue;
                } else {
                    if (@dcMerkleRootFromFile($candidate, $merkleChunk)!==$digest) continue;
                }
                if ($headHash!=='' && @dcMerkleHeadFile($candidate)!==$headHash) continue;
            } catch (Throwable $e) {
                continue;
            }
            $sourcePath=$candidate;
            $mime=(string)($cand['mime_type']?:'application/octet-stream');
            if ($headHash==='') {
                try { $resolvedHead=dcMerkleHeadFile($candidate); } catch (Throwable $e) { $resolvedHead=null; }
            }
            if ($resolvedHead===null && !empty($cand['head_hash']) && preg_match('/^[a-f0-9]{64}$/D',$cand['head_hash'])) {
                $resolvedHead=$cand['head_hash'];
            }
            break;
        }
        if ($sourcePath===null) {
            echo json_encode(['success'=>true,'dedup'=>false]);
            exit;
        }
        if ($resolvedHead===null) {
            try { $resolvedHead=dcMerkleHeadFile($sourcePath); } catch (Throwable $e) { $resolvedHead=null; }
        }
        $storageName=bin2hex(random_bytes(16)).'_'.preg_replace('/[^a-zA-Z0-9_.-]/','_',$name);
        $path=$STORAGE_DIR.$storageName;
        if (!@link($sourcePath,$path)) {
            // Fallback: sin hardlink posible (otro FS); el cliente debe subir por chunks.
            echo json_encode(['success'=>true,'dedup'=>false,'reason'=>'link_failed']);
            exit;
        }
        try {
            $pdo->beginTransaction();
            if (!$old) {
                $st=$pdo->prepare('INSERT INTO files(parent_id,name,is_dir,mime_type,size,storage_name,has_thumb,has_list,owner_id,content_hash_algorithm,content_hash,head_hash) VALUES(?,?,0,?,?,?,0,0,?,?,?,?)');
                $st->execute([$parentId,$name,$mime,$size,$storageName,$dcOwnerId,$algorithm,$digest,$resolvedHead]);
                $fileId=(int)$pdo->lastInsertId();
            } else {
                if ($versioned) {
                    $st=$pdo->prepare('INSERT INTO file_versions(file_id,storage_name,size,created_at) VALUES (?,?,?,NOW())');
                    $st->execute([$old['id'],$old['storage_name'],$old['size']]);
                } elseif ($mode==='rename_old') {
                    $other=dcPreviousName($pdo,$parentId,$name,$dcOwnerId);
                    $st=$pdo->prepare('UPDATE files SET name=? WHERE id=?');$st->execute([$other,$old['id']]);
                }
                if ($versioned || $mode==='replace') {
                    $st=$pdo->prepare('UPDATE files SET mime_type=?,size=?,storage_name=?,has_thumb=0,has_list=0,content_hash_algorithm=?,content_hash=?,head_hash=? WHERE id=?');
                    $st->execute([$mime,$size,$storageName,$algorithm,$digest,$resolvedHead,$old['id']]);
                    $fileId=(int)$old['id'];
                } else {
                    $st=$pdo->prepare('INSERT INTO files(parent_id,name,is_dir,mime_type,size,storage_name,has_thumb,has_list,owner_id,content_hash_algorithm,content_hash,head_hash) VALUES(?,?,0,?,?,?,0,0,?,?,?,?)');
                    $st->execute([$parentId,$name,$mime,$size,$storageName,$dcOwnerId,$algorithm,$digest,$resolvedHead]);
                    $fileId=(int)$pdo->lastInsertId();
                }
            }
            $pdo->commit();
            if ($old && !$versioned && $mode==='replace') @unlink($STORAGE_DIR.$old['storage_name']);
            // Miniatura para fotos deduplicadas (mismo contenido, storage nuevo).
            if ($fileId > 0 && dcIsImageFile(['is_dir'=>0,'mime_type'=>$mime,'name'=>$name,'storage_name'=>$storageName])) {
                try {
                    ensureImageThumbnail([
                        'id'=>$fileId,'is_dir'=>0,'mime_type'=>$mime,'name'=>$name,
                        'storage_name'=>$storageName,'has_thumb'=>0,
                        'parent_id'=>$parentId,'owner_id'=>$dcOwnerId
                    ], $pdo, $STORAGE_DIR, $THUMBS_DIR);
                } catch (Throwable $ignore) {}
            }
            echo json_encode(['success'=>true,'dedup'=>true,'file_id'=>$fileId,'complete'=>true]);
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            @unlink($path);
            throw $e;
        }
    } catch (Throwable $e) {
        dcFail(409,$e->getMessage());
    } finally {
        $pdo->prepare('SELECT RELEASE_LOCK(?)')->execute([$lockName]);
    }
    exit;
}

// Subida aislada para cuentas normales: sin sobrescribir elementos ajenos.
// Se serializa la confirmación por usuario para impedir rebasar cuota con
// dos subidas concurrentes. Los fragmentos temporales no son accesibles por URL.
if ($action === 'upload_chunk') {
    if (!$dcIsAdmin && empty($dcLogged['can_upload'])) dcFail(403,'Tu cuenta no tiene permiso para subir archivos');
    $parentId=(int)($_POST['parentId']??-1);
    if (!driveValidateId($parentId,$pdo,true) || ($parentId>0 && !dcOwnedTree($parentId,$pdo,$dcOwnerId))) dcFail(403,'Carpeta de destino no autorizada');
    $name=(string)($_POST['fileName']??'');
    if ($name==='' || strlen($name)>240 || basename($name)!==$name || str_contains($name,'/') || str_contains($name,'\\') || preg_match('/[\x00-\x1f]/',$name)) dcFail(422,'Nombre de archivo inválido');
    $uploadId=(string)($_POST['uploadId']??'');
    if (!preg_match('/^[a-f0-9]{32}$/D',$uploadId)) dcFail(422,'ID de subida inválido');
    $index=filter_var($_POST['chunkIndex']??null,FILTER_VALIDATE_INT);
    $total=filter_var($_POST['totalChunks']??null,FILTER_VALIDATE_INT);
    if ($index===false || $total===false || $index<0 || $total<1 || $index >= $total || $total>10000) dcFail(422,'Fragmentos inválidos');
    if (!isset($_FILES['chunk']) || $_FILES['chunk']['error']!==UPLOAD_ERR_OK || !is_uploaded_file($_FILES['chunk']['tmp_name'])) dcFail(400,'Fragmento ausente');
    $chunkSize=(int)$_FILES['chunk']['size'];
    if ($chunkSize<0 || $chunkSize>dcChunkEffectiveMiB($pdo)*1048576) dcFail(413,'Fragmento demasiado grande');
    $max=(int)($dcLogged['max_file_bytes']??0);
    $quota=(int)($dcLogged['quota_bytes']??0);
    $base=$STORAGE_DIR.'dc_user_'.$dcOwnerId.'_'.$uploadId;
    $tmp=$base.'.part';$meta=$base.'.json';
    $lockName='dc-upload-'.$dcOwnerId;
    $lk=$pdo->prepare('SELECT GET_LOCK(?,15)');$lk->execute([$lockName]);
    if ((int)$lk->fetchColumn()!==1) dcFail(503,'Subida ocupada, vuelve a intentarlo');
    try {
        $state=$index===0 ? null : (is_file($meta)?json_decode((string)file_get_contents($meta),true):null);
        if ($index===0) {
            if (is_file($tmp)||is_file($meta)) throw new RuntimeException('Identificador de subida ya usado');
            $state=['owner'=>$dcOwnerId,'parent'=>$parentId,'name'=>$name,'total'=>$total,'next'=>0,'bytes'=>0,'leaves'=>[]];
        }
        if (!is_array($state)||($state['owner']??null)!==$dcOwnerId||($state['parent']??null)!==$parentId||($state['name']??null)!==$name||($state['total']??null)!==$total||($state['next']??null)!==$index) throw new RuntimeException('Secuencia de subida inválida');
        if (!isset($state['leaves']) || !is_array($state['leaves'])) $state['leaves']=[];
        // Hoja Merkle del fragmento en el tmp de PHP (sin releer el .part final).
        $leaf=dcMerkleLeafFile($_FILES['chunk']['tmp_name']);
        $bytes=(int)$state['bytes']+$chunkSize;
        if ($max>0 && $bytes>$max) throw new RuntimeException('Supera el tamaño máximo por archivo');
        // Una cuota igual a cero se interpreta como sin límite, tal como NULL.
        if ($quota>0 && $bytes>$quota) throw new RuntimeException('El archivo supera la cuota');
        $out=fopen($tmp,$index===0?'xb':'ab');
        if (!$out) throw new RuntimeException('No se pudo abrir el archivo temporal');
        $in=fopen($_FILES['chunk']['tmp_name'],'rb');
        if (!$in) {fclose($out);throw new RuntimeException('Fragmento ilegible');}
        $copied=stream_copy_to_stream($in,$out);fclose($in);fclose($out);
        if ($copied!==$chunkSize) throw new RuntimeException('Fragmento incompleto');
        $state['leaves'][]=$leaf;
        $state['next']=$index+1;$state['bytes']=$bytes;
        if (file_put_contents($meta,json_encode($state),LOCK_EX)===false) throw new RuntimeException('No se guardó el estado');
        if ($index===$total-1) {
            clearstatcache(true,$tmp);
            if (!is_file($tmp) || filesize($tmp)!==$bytes) throw new RuntimeException('Tamaño final incorrecto');
            $st=$pdo->prepare('SELECT * FROM files WHERE parent_id=? AND name=? AND owner_id=? AND deleted_at IS NULL LIMIT 1');
            $st->execute([$parentId,$name,$dcOwnerId]);$old=$st->fetch(PDO::FETCH_ASSOC);
            $versioned=driveTreeFlag($parentId,'version_enabled',$pdo);
            $mode=(string)($_POST['conflict_mode']??'');
            if ($old && ((int)$old['is_dir']!==0 || (int)$old['owner_id']!==$dcOwnerId || !dcOwnedTree((int)$old['id'],$pdo,$dcOwnerId))) throw new RuntimeException('Nombre ocupado o elemento no autorizado');
            if ($old && !$versioned && !in_array($mode,['replace','rename_old'],true)) throw new RuntimeException('Conflicto: elige cómo tratar el archivo existente');
            // Cuota contabiliza versiones anteriores y descuenta lo que se elimina al reemplazar.
            if ($quota>0) {
                $st=$pdo->prepare('SELECT COALESCE(SUM(size),0) FROM files WHERE owner_id=?');$st->execute([$dcOwnerId]);
                $used=(float)$st->fetchColumn();
                $st=$pdo->prepare('SELECT COALESCE(SUM(v.size),0) FROM file_versions v JOIN files f ON f.id=v.file_id WHERE f.owner_id=?');
                $st->execute([$dcOwnerId]);$used+=(float)$st->fetchColumn();
                if ($old && !$versioned && $mode==='replace') $used-=(float)$old['size'];
                if ($used+$bytes>$quota) throw new RuntimeException('Cuota de almacenamiento excedida (incluye historial)');
            }
            $storageName=bin2hex(random_bytes(16)).'_'.preg_replace('/[^a-zA-Z0-9_.-]/','_',$name);
            $path=$STORAGE_DIR.$storageName;
            if (!rename($tmp,$path)) throw new RuntimeException('Error al finalizar archivo');
            try {
                $mime=(new finfo(FILEINFO_MIME_TYPE))->file($path)?:'application/octet-stream';
                [$dedupEnabled,$dedupAlgo]=dcDedupConfig($pdo);
                // Merkle root a partir de las hojas calculadas en caliente por fragmento.
                // Evita releer el archivo completo desde disco en la Raspberry Pi.
                $leaves=$state['leaves']??[];
                if (!is_array($leaves) || count($leaves)!==$total) {
                    throw new RuntimeException('Hojas Merkle incompletas');
                }
                $digest=dcMerkleRootFromLeaves($leaves);
                $hashAlgo='merkle';
                // head_hash: SHA-256 de los primeros 2 MiB (prefiltro de hardlinks).
                $headHash=dcMerkleHeadFile($path);
                $duplicate=$dedupEnabled && dcDuplicateMatch($pdo,$dcOwnerId,$bytes,$hashAlgo,$digest,$old? (int)$old['id']:null);
                // No se concede acceso a archivos ajenos mediante un hash facilitado por el cliente.
                dcGlobalHardlinkTry($pdo,$STORAGE_DIR,$path,$name,$bytes,$digest,$hashAlgo,$headHash);
                $pdo->beginTransaction();
                if (!$old) {
                    $st=$pdo->prepare('INSERT INTO files(parent_id,name,is_dir,mime_type,size,storage_name,has_thumb,has_list,owner_id,content_hash_algorithm,content_hash,head_hash) VALUES(?,?,0,?,?,?,0,0,?,?,?,?)');
                    $st->execute([$parentId,$name,$mime,$bytes,$storageName,$dcOwnerId,$hashAlgo,$digest,$headHash]);
                } else {
                    if ($versioned) {
                        $st=$pdo->prepare('INSERT INTO file_versions(file_id,storage_name,size,created_at) VALUES (?,?,?,NOW())');
                        $st->execute([$old['id'],$old['storage_name'],$old['size']]);
                    } elseif ($mode==='rename_old') {
                        $other=dcPreviousName($pdo,$parentId,$name,$dcOwnerId);
                        $st=$pdo->prepare('UPDATE files SET name=? WHERE id=?');$st->execute([$other,$old['id']]);
                    }
                    if ($versioned || $mode==='replace') {
                        $st=$pdo->prepare('UPDATE files SET mime_type=?,size=?,storage_name=?,has_thumb=0,has_list=0,content_hash_algorithm=?,content_hash=?,head_hash=? WHERE id=?');
                        $st->execute([$mime,$bytes,$storageName,$hashAlgo,$digest,$headHash,$old['id']]);
                    } else {
                        $st=$pdo->prepare('INSERT INTO files(parent_id,name,is_dir,mime_type,size,storage_name,has_thumb,has_list,owner_id,content_hash_algorithm,content_hash,head_hash) VALUES(?,?,0,?,?,?,0,0,?,?,?,?)');
                        $st->execute([$parentId,$name,$mime,$bytes,$storageName,$dcOwnerId,$hashAlgo,$digest,$headHash]);
                    }
                }
                $pdo->commit();
                if ($old && !$versioned && $mode==='replace') @unlink($STORAGE_DIR.$old['storage_name']);
                if ($old && ($versioned || $mode==='replace')) {
                    @unlink($THUMBS_DIR.$old['storage_name'].'.webp');
                    // En modo historial conservamos el índice anterior para la versión archivada.
                    if (!$versioned) @unlink($LISTS_DIR.$old['storage_name'].'.list.gz');
                }
                // Miniatura ligera para fotos (explorador de álbumes). No bloquea si falla.
                $dcFinalFileIdTmp = (int)($old['id'] ?? $pdo->lastInsertId());
                if ($dcFinalFileIdTmp > 0 && dcIsImageFile(['is_dir'=>0,'mime_type'=>$mime,'name'=>$name,'storage_name'=>$storageName])) {
                    try {
                        ensureImageThumbnail([
                            'id'=>$dcFinalFileIdTmp,'is_dir'=>0,'mime_type'=>$mime,'name'=>$name,
                            'storage_name'=>$storageName,'has_thumb'=>0,
                            'parent_id'=>$parentId,'owner_id'=>$dcOwnerId
                        ], $pdo, $STORAGE_DIR, $THUMBS_DIR);
                    } catch (Throwable $ignore) {}
                }
            } catch(Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack();@unlink($path);throw $e; }
            @unlink($meta);
        }
        $dcFinalFileId = ($index === $total-1) ? (int)($old['id'] ?? $pdo->lastInsertId()) : 0;
        echo json_encode(['success'=>true,'complete'=>$index===$total-1,'file_id'=>$dcFinalFileId,'duplicate'=>($index===$total-1 ? (bool)($duplicate??false):false)]);
    } catch(Throwable $e) {
        @unlink($tmp);@unlink($meta);
        dcFail(409,$e->getMessage());
    } finally {
        $pdo->prepare('SELECT RELEASE_LOCK(?)')->execute([$lockName]);
    }
    exit;
}

// La subida heredada para administradores fue retirada.
// Todas las cuentas pasan por la ruta moderna de upload_chunk anterior.

if ($action === 'album_previews_dcft') {
    header('Cache-Control: private, no-store');
    $folder=filter_var($_GET['id']??'',FILTER_VALIDATE_INT);
    if(!$folder||$folder<1)dcFail(422,'Álbum inválido');
    dcReadFileGuard($folder,$pdo,false,$dcOwnerId);
    $q=$pdo->prepare('SELECT id FROM files WHERE id=? AND owner_id=? AND is_dir=1 AND is_album=1 AND deleted_at IS NULL');$q->execute([$folder,$dcOwnerId]);
    if(!$q->fetchColumn())dcFail(404,'Álbum inexistente');
    // Pseudo-random but stable for this login; no source image is opened or converted.
    $seed=hash_hmac('sha256',(string)$folder,(string)($_SESSION['dc_csrf']??''));
    $q=$pdo->prepare('SELECT id,name,mime_type,storage_name,content_hash,is_dir FROM files WHERE parent_id=? AND owner_id=? AND is_dir=0 AND deleted_at IS NULL AND has_thumb=1 ORDER BY CRC32(CONCAT(id,?)),id LIMIT 18');
    $q->execute([$folder,$dcOwnerId,$seed]);$photos=[];
    foreach($q->fetchAll(PDO::FETCH_ASSOC) as $photo){
        if(!dcIsImageFile($photo))continue;
        $info=dcThumbnailMetadataDcft($photo,$THUMBS_DIR);if(!$info['thumb_cached'])continue;
        $photos[]=array_merge(['id'=>(int)$photo['id'],'content_hash'=>$photo['content_hash']??''],$info);
        if(count($photos)===3)break;
    }
    echo json_encode(['photos'=>$photos],JSON_UNESCAPED_UNICODE);exit;
}

if ($action === 'list') {
    header('Cache-Control: private, no-store');
    $parentId = (int)($_GET['parent_id'] ?? 0);
    if (!driveValidateId($parentId,$pdo,true)) {http_response_code(404);exit(json_encode(['error'=>'Carpeta inexistente']));}
    if ($parentId !== 0) dcReadFileGuard($parentId,$pdo,false,$dcOwnerId);
    $stmt = $pdo->prepare('SELECT * FROM files WHERE parent_id=? AND deleted_at IS NULL' . ' AND owner_id=?' . ' ORDER BY is_dir DESC,name ASC');
    $stmt->execute([$parentId,$dcOwnerId]);
    $listedFiles = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($listedFiles as &$lf) {
        $lf = array_merge($lf,dcThumbnailMetadataDcft($lf,$THUMBS_DIR));
    }
    unset($lf);
    echo json_encode(['files' => dcVersionBadges($listedFiles,$pdo)]);
    exit;
}

// Crear notas .txt sin pasar por los temporales de subida; no sobrescribe registros.
if ($action === 'create_text') {
    if (($_SERVER['REQUEST_METHOD']??'') !== 'POST') dcFail(405,'Se requiere POST');
    if (!$dcIsAdmin && empty($dcLogged['can_upload'])) dcFail(403,'No tienes permiso para crear archivos');
    $parent=(int)($_POST['parent_id']??-1);
    if (!driveValidateId($parent,$pdo,true) || ($parent!==0 && !dcOwnedTree($parent,$pdo,$dcOwnerId))) dcFail(403,'Destino no autorizado');
    $name=trim((string)($_POST['name']??''));
    if ($name==='' || strlen($name)>240 || !preg_match('/\.txt$/iD',$name) || $name==='.' || $name==='..' || str_contains($name,'/') || str_contains($name,'\\') || preg_match('/[\x00-\x1f\x7f]/',$name)) dcFail(422,'Nombre .txt inválido');
    $content=(string)($_POST['content']??'');
    $size=strlen($content);
    if ($size>1024*1024) dcFail(413,'La nota supera 1 MiB');
    $max=(int)($dcLogged['max_file_bytes']??0);
    if (!$dcIsAdmin && $max>0 && $size>$max) dcFail(413,'Supera el tamaño máximo por archivo');
    $lock='dc-upload-'.$dcOwnerId;
    $lk=$pdo->prepare('SELECT GET_LOCK(?,15)');$lk->execute([$lock]);
    if ((int)$lk->fetchColumn()!==1) dcFail(503,'Almacenamiento ocupado, vuelve a intentarlo');
    $path=null;
    try {
        $check=$pdo->prepare('SELECT 1 FROM files WHERE parent_id=? AND name=? AND owner_id=? AND deleted_at IS NULL LIMIT 1');
        $check->execute([$parent,$name,$dcOwnerId]);
        if ($check->fetchColumn()) dcFail(409,'Ya existe un archivo o carpeta con ese nombre');
        $quota=(int)($dcLogged['quota_bytes']??0);
        if (!$dcIsAdmin && $quota>0) {
            $st=$pdo->prepare('SELECT COALESCE(SUM(size),0) FROM files WHERE owner_id=? AND is_dir=0');$st->execute([$dcOwnerId]);$used=(float)$st->fetchColumn();
            $st=$pdo->prepare('SELECT COALESCE(SUM(v.size),0) FROM file_versions v JOIN files f ON f.id=v.file_id WHERE f.owner_id=?');$st->execute([$dcOwnerId]);$used+=(float)$st->fetchColumn();
            if ($used+$size>$quota) dcFail(413,'Cuota insuficiente');
        }
        if (disk_free_space($STORAGE_DIR)<$size) dcFail(507,'No hay espacio físico disponible');
        $storage=bin2hex(random_bytes(16)).'.txt';
        $path=$STORAGE_DIR.$storage;
        $fh=fopen($path,'xb');
        if (!$fh) throw new RuntimeException('No se pudo reservar archivo');
        $written=0;
        while ($written<$size) {
            $n=fwrite($fh,substr($content,$written,65536));
            if ($n===false || $n===0) {fclose($fh);throw new RuntimeException('Escritura incompleta');}
            $written+=$n;
        }
        fclose($fh);
        $pdo->beginTransaction();
        try {
            $check->execute([$parent,$name,$dcOwnerId]);
            if ($check->fetchColumn()) throw new RuntimeException('El nombre ya está ocupado');
            $insert=$pdo->prepare("INSERT INTO files(parent_id,name,is_dir,mime_type,size,storage_name,has_thumb,has_list,owner_id) VALUES (?,?,0,'text/plain',?,?,0,0,?)");
            $insert->execute([$parent,$name,$size,$storage,$dcOwnerId]);
            $pdo->commit();
        } catch(Throwable $e) {if ($pdo->inTransaction()) $pdo->rollBack();throw $e;}
        echo json_encode(['success'=>true,'id'=>(int)$pdo->lastInsertId()]);exit;
    } catch(Throwable $e) {
        if ($path && is_file($path)) @unlink($path);
        dcFail(409,'No se pudo crear la nota: '.$e->getMessage());
    } finally {
        $pdo->prepare('SELECT RELEASE_LOCK(?)')->execute([$lock]);
    }
}

if ($action === 'create_folder') {
    $parentId = (int)$_POST['parent_id'];
    if (!driveValidateId($parentId,$pdo,true)) { http_response_code(400); exit(json_encode(['error'=>'Carpeta inválida'])); }
    if ($parentId !== 0) dcReadFileGuard($parentId,$pdo,false,$dcOwnerId);
    $name = trim((string)($_POST['name']??''));
    if ($name==='' || strlen($name)>240 || $name==='.' || $name==='..' || str_contains($name,'/') || str_contains($name,'\\') || preg_match('/[\x00-\x1F]/',$name)) dcFail(422,'Nombre de carpeta inválido');
    $existing=$pdo->prepare('SELECT id,is_dir FROM files WHERE parent_id=? AND name=? AND owner_id=? AND deleted_at IS NULL LIMIT 1');
    $existing->execute([$parentId,$name,$dcOwnerId]);$found=$existing->fetch(PDO::FETCH_ASSOC);
    if ($found) {
        if (!(int)$found['is_dir']) dcFail(409,'Ya existe un archivo con ese nombre');
        echo json_encode(['success'=>true,'id'=>(int)$found['id'],'existing'=>true]);exit;
    }
    $stmt = $pdo->prepare("INSERT INTO files (parent_id, name, is_dir, mime_type, owner_id) VALUES (?, ?, 1, 'directory', ?)");
    $stmt->execute([$parentId, $name, $dcLogged['id']]);
    echo json_encode(['success' => true,'id'=>(int)$pdo->lastInsertId()]);
    exit;
}


// v20.10.57: lectura y edición de texto con sustitución atómica (no escritura in-place).
if ($action==='text_open_57' || $action==='text_save_57') {
    if ($action==='text_open_57' && $_SERVER['REQUEST_METHOD']!=='GET') dcFail(405,'Solo GET');
    if ($action==='text_save_57' && $_SERVER['REQUEST_METHOD']!=='POST') dcFail(405,'Solo POST');
    $id=(int)($_GET['id']??$_POST['id']??0);
    if ($id<1 || !dcOwnedTree($id,$pdo,$dcOwnerId)) dcFail(404,'Archivo no encontrado');
    $q=$pdo->prepare('SELECT id,parent_id,name,storage_name,size,is_dir,mime_type,owner_id FROM files WHERE id=? AND deleted_at IS NULL');
    $q->execute([$id]);$f=$q->fetch(PDO::FETCH_ASSOC);
    if (!$f || (int)$f['is_dir']!==0) dcFail(404,'Archivo no encontrado');
    $ext=strtolower((string)pathinfo((string)$f['name'],PATHINFO_EXTENSION));
    $allowed=['txt','log','php','js','mjs','cjs','ts','tsx','jsx','yaml','yml','html','htm','java','md','markdown','css','json','xml','sql','py','sh','bash','zsh','c','h','cpp','hpp','cs','go','rs','rb','pl','ini','conf','cfg','toml','properties','csv','tsv'];
    if (!in_array($ext,$allowed,true)) dcFail(415,'Tipo de archivo no editable');
    $stored=(string)$f['storage_name'];
    if ($stored==='' || basename($stored)!==$stored || !preg_match('/^[a-zA-Z0-9_.-]+$/D',$stored)) dcFail(404,'Almacenamiento no disponible');
    $root=rtrim($STORAGE_DIR,'/').'/';$path=$root.$stored;
    if (!is_file($path) || is_link($path)) dcFail(404,'Archivo no disponible');
    clearstatcache(true,$path);
    if (filesize($path)>2*1024*1024) dcFail(413,'Editor limitado a 2 MiB');
    $original=file_get_contents($path);
    if ($original===false || !preg_match('//u',$original) || strpos($original,"\0")!==false) dcFail(415,'El archivo debe contener texto UTF-8');
    $oldHash=hash('sha256',$original);
    if ($action==='text_open_57') {echo json_encode(['success'=>true,'id'=>$id,'name'=>$f['name'],'content'=>$original,'sha256'=>$oldHash],JSON_INVALID_UTF8_SUBSTITUTE);exit;}
    if (!$dcIsAdmin && empty($dcLogged['can_upload'])) dcFail(403,'No tienes permiso para guardar cambios');
    $content=(string)($_POST['content']??'');$expected=strtolower((string)($_POST['sha256']??''));
    if (!preg_match('/^[a-f0-9]{64}$/D',$expected)) dcFail(422,'Versión de origen inválida');
    if (!preg_match('//u',$content) || strpos($content,"\0")!==false) dcFail(422,'El editor admite solamente UTF-8');
    $size=strlen($content);
    if ($size>2*1024*1024) dcFail(413,'El contenido supera 2 MiB');
    $max=(int)($dcLogged['max_file_bytes']??0);if(!$dcIsAdmin && $max>0 && $size>$max)dcFail(413,'Supera el máximo por archivo');
    $lk='dc-edit-file-'.$id;$lock=$pdo->prepare('SELECT GET_LOCK(?,15)');$lock->execute([$lk]);
    if ((int)$lock->fetchColumn()!==1)dcFail(503,'El archivo está ocupado');
    $temp=null;
    try {
        clearstatcache(true,$path);
        if (!is_file($path)||is_link($path)) dcFail(409,'El archivo dejó de estar disponible');
        $now=@hash_file('sha256',$path);
        if ($now===false || !hash_equals($expected,$now)) dcFail(409,'El archivo cambió desde que lo abriste. Vuelve a abrirlo antes de guardar');
        if (hash_equals($now,hash('sha256',$content))) {echo json_encode(['success'=>true,'sha256'=>$now,'unchanged'=>true]);exit;}
        $quota=(int)($dcLogged['quota_bytes']??0);
        if (!$dcIsAdmin && $quota>0) {
            $s=$pdo->prepare('SELECT COALESCE(SUM(size),0) FROM files WHERE owner_id=? AND is_dir=0');$s->execute([$dcOwnerId]);$used=(float)$s->fetchColumn();
            $s=$pdo->prepare('SELECT COALESCE(SUM(v.size),0) FROM file_versions v JOIN files f ON f.id=v.file_id WHERE f.owner_id=?');$s->execute([$dcOwnerId]);$used+=(float)$s->fetchColumn();
            if ($used-(driveTreeFlag((int)($f['parent_id']??0),'version_enabled',$pdo)?0:(int)$f['size'])+$size>$quota) dcFail(413,'Cuota insuficiente');
        }
        $before=stat($path);if(!$before)dcFail(409,'Archivo no disponible');
        $temp=$root.'.dc-edit-'.$id.'-'.bin2hex(random_bytes(12));
        $handle=@fopen($temp,'xb');if(!$handle)dcFail(507,'No se pudo reservar el archivo temporal');
        $w=0;while($w<$size){$n=fwrite($handle,substr($content,$w,65536));if(!$n){fclose($handle);dcFail(507,'Escritura incompleta');}$w+=$n;}
        fflush($handle);fclose($handle);
        @chmod($temp,$before['mode'] & 0777);
        clearstatcache(true,$path);$check=stat($path);
        if(!$check || $check['dev']!==$before['dev'] || $check['ino']!==$before['ino'] || @hash_file('sha256',$path)!==$now) dcFail(409,'El archivo cambió durante la edición');
        // Versionado del editor: el historial sigue el ID logico, no el inodo.
        // link() conserva el contenido antiguo incluso cuando otros usuarios comparten su inodo.
        $historyEnabled=driveTreeFlag((int)($f['parent_id']??0),'version_enabled',$pdo);
        $historyName=null;
        if($historyEnabled){
            $historyName=bin2hex(random_bytes(16)).'.version';
            if(!@link($path,$root.$historyName))dcFail(507,'No se pudo conservar la version anterior');
        }
        // Reemplazar solamente la ruta de ESTE usuario.
        if(!@rename($temp,$path)){
            if($historyName!==null)@unlink($root.$historyName);
            dcFail(507,'No se pudo sustituir el archivo');
        }
        $temp=null;
        if($historyName!==null){
            try {
                $pdo->prepare('INSERT INTO file_versions(file_id,storage_name,size,created_at) VALUES (?,?,?,NOW())')
                    ->execute([$id,$historyName,(int)$f['size']]);
            }catch(Throwable $e){
                // La version anterior sigue accesible y puede restaurarse fisicamente.
                @rename($root.$historyName,$path);
                dcFail(500,'No se pudo registrar la version anterior');
            }
        }
        $digest=hash('sha256',$content);
        $pdo->prepare("UPDATE files SET size=?,content_hash_algorithm='sha256',content_hash=? WHERE id=? AND owner_id=?")
            ->execute([$size,$digest,$id,$dcOwnerId]);
        dcActivity85m($pdo,$dcOwnerId,$id,'edit');
        echo json_encode(['success'=>true,'sha256'=>$digest]);exit;
    } finally {
        if($temp!==null && is_file($temp))@unlink($temp);
        $release=$pdo->prepare('SELECT RELEASE_LOCK(?)');$release->execute([$lk]);
    }
}

if ($action === 'view') {
    $id = (int)$_GET['id'];
    dcReadFileGuard($id,$pdo,false,$dcOwnerId);
    $stmt = $pdo->prepare("SELECT * FROM files WHERE id = ?");
    $stmt->execute([$id]);
    $file = $stmt->fetch();
    if (!$file || driveIsTrashed($id,$pdo)) {http_response_code(404);exit(json_encode(['error'=>'Archivo inexistente']));}
    // HF2: consultar/previsualizar no cuenta como actividad reciente.
    
    // .tar.gz: listar el TAR interior; 7z l sobre gzip muestra solamente la capa .tar.
    // No extraer al disco. Límite comprimido configurable, salida 2 MiB y 8 s.
    $dcTarLimit=dcTarGzPreviewLimit($pdo);
    if (preg_match('/\.(?:tar\.gz|tgz)$/i',(string)$file['name']) && $dcTarLimit>0 && (int)$file['size']<=$dcTarLimit) {
        $physical=$STORAGE_DIR.$file['storage_name'];
        if (!is_file($physical)) dcFail(404,'Archivo físico no encontrado');
        if (!function_exists('proc_open')) dcFail(503,'La inspección TAR requiere proc_open');
        $cmd=['timeout','8','tar','-tzf',$physical];
        $process=@proc_open($cmd,[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
        if (!is_resource($process)) dcFail(503,'No se pudo iniciar el listado TAR');
        fclose($pipes[0]);
        // Máximo 2 MiB de nombres; detener el proceso si sobrepasa el límite.
        $listing=stream_get_contents($pipes[1],2*1024*1024+1);
        $tooLong=strlen((string)$listing)>2*1024*1024;
        if ($tooLong) @proc_terminate($process);
        fclose($pipes[1]);
        $error=stream_get_contents($pipes[2],4096);fclose($pipes[2]);
        $exit=proc_close($process);
        if (!$tooLong && $exit!==0) dcFail(422,'No se pudo leer el contenido TAR: '.substr(trim((string)$error),0,150));
        if ($tooLong) $listing=substr($listing,0,2*1024*1024).'\n[Lista truncada a 2 MiB]';
        echo json_encode(['type'=>'archive','content'=>$listing]);exit;
    }
    // El tamaño del archivo comprimido NO es el tamaño de su índice de nombres.
    // Restringimos el contenido descomprimido del índice, no el ZIP/RAR original.
    $archiveIndex = !empty($file['has_list']) || preg_match('/\.(zip|rar|7z|tar|tgz|gz|xz|bz2)$/i', (string)$file['name']);
    if (!$archiveIndex && (int)$file['size'] > 2*1024*1024) {http_response_code(413);exit(json_encode(['error'=>'Vista de texto limitada a 2 MB para ahorrar RAM']));}
    // Permitir vista de código por extensión conocida aunque el servidor reporte
    // application/octet-stream; nunca interpretar/ejecutar el contenido.
    $dcTextExtension = strtolower((string)pathinfo((string)$file['name'], PATHINFO_EXTENSION));
    $dcTextAllowed = in_array($dcTextExtension, ['php','js','mjs','cjs','ts','tsx','jsx','py','sh','bash','zsh','sql','json','jsonl','css','scss','html','htm','xml','svg','yaml','yml','ini','conf','toml','md','markdown','c','h','cpp','hpp','java','go','rs','rb','txt','log'], true);
    $dcTextAllowed = $dcTextAllowed || in_array(strtolower((string)$file['name']), ['dockerfile','.bashrc','.profile'], true);
    if (!$archiveIndex && ($dcTextAllowed || strpos($file['mime_type'], 'text/') === 0 || in_array($file['mime_type'], ['application/json', 'application/xml', 'application/javascript'], true))) {
        echo json_encode(['type' => 'text', 'content' => file_get_contents($STORAGE_DIR . $file['storage_name'])]);
    } elseif ($file['has_list']) {
        $listFile = $LISTS_DIR . $file['storage_name'] . '.list.gz';
        if (file_exists($listFile) && filesize($listFile) > 25) {
            // Leer máximo 2 MiB del listado para evitar cargar índices enormes en RAM.
            $fh = gzopen($listFile, 'rb');
            if (!$fh) { http_response_code(500);exit(json_encode(['error'=>'No se pudo leer el índice'])); }
            $content = gzread($fh, 2*1024*1024 + 1);
            gzclose($fh);
            if (strlen($content) > 2*1024*1024) $content = substr($content, 0, 2*1024*1024) . "\n[Índice truncado a 2 MiB para proteger la Raspberry]";
            echo json_encode(['type' => 'archive', 'content' => $content]);
        } else echo json_encode(['type' => 'text', 'content' => "Lista vacía."]);
    } else { 
        if (strpos($file['mime_type'], 'zip') !== false && class_exists('ZipArchive')) {
            $zip = new ZipArchive;
            if ($zip->open($STORAGE_DIR . $file['storage_name']) === TRUE) {
                $out = "";
                for ($i = 0; $i < $zip->numFiles; $i++) { $out .= $zip->statIndex($i)['name'] . "\n"; }
                $zip->close();
                echo json_encode(['type' => 'archive', 'content' => $out]);
                exit;
            }
        }
        echo json_encode(['error' => 'No previsualizable']); 
    }
    exit;
}

if ($action === 'thumb') {
    $id = (int)$_GET['id'];
    dcReadFileGuard($id,$pdo,false,$dcOwnerId);
    $stmt = $pdo->prepare("SELECT id, name, storage_name, mime_type, is_dir, has_thumb, parent_id, owner_id, content_hash FROM files WHERE id = ?");
    $stmt->execute([$id]);
    $f = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($f && !$f['is_dir'] && !driveIsTrashed($id,$pdo)) {
        $thumbPath = $THUMBS_DIR . $f['storage_name'] . '.webp';
        if (!is_file($thumbPath) || filesize($thumbPath) === 0) {
            // Modo album (cached=1): responder 404 rapido, sin conversiones ImageMagick.
            if (!empty($_GET['cached'])) { http_response_code(404); exit; }
            // Liberar la sesión ANTES de convertir: si no, la carpeta queda "bloqueada"
            // mientras ImageMagick trabaja y no se puede abrir otra carpeta.
            if (session_status() === PHP_SESSION_ACTIVE) @session_write_close();
            $locks = dcThumbAcquire($pdo, (int)$f['id']);
            if ($locks === null) dcThumbPendingResponse();
            try {
                if (stripos((string)$f['mime_type'], 'pdf') !== false) {
                    ensurePdfThumbnail($f, $pdo, $STORAGE_DIR, $THUMBS_DIR);
                } elseif (dcIsImageFile($f)) {
                    ensureImageThumbnail($f, $pdo, $STORAGE_DIR, $THUMBS_DIR);
                }
            } finally {
                dcThumbRelease($locks);
            }
        }
        if (is_file($thumbPath) && is_readable($thumbPath)) {
            $thumbInfo=dcThumbnailMetadataDcft($f,$THUMBS_DIR);
            $etag='"'.$thumbInfo['thumb_version'].'"';
            header('Content-Type: image/webp');
            header('Cache-Control: private, max-age=86400');
            header('Vary: Cookie');
            header('ETag: '.$etag);
            header('Last-Modified: '.gmdate('D, d M Y H:i:s',filemtime($thumbPath)).' GMT');
            if(trim((string)($_SERVER['HTTP_IF_NONE_MATCH']??''))===$etag){http_response_code(304);exit;}
            header('Content-Length: '.filesize($thumbPath));
            readfile($thumbPath);
            exit;
        }
    }
    http_response_code(404);
    exit;
}


// Descarga ZIP autenticada, limitada para no saturar la Raspberry.
if ($action === 'download_folder') {
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') dcFail(405,'Se requiere GET');
    $id=filter_input(INPUT_GET,'id',FILTER_VALIDATE_INT);
    if (!$id || !driveValidateId($id,$pdo,true)) dcFail(404,'Carpeta inexistente');
    dcReadFileGuard($id,$pdo,false,$dcOwnerId);
    if (!class_exists('ZipArchive')) dcFail(503,'Extensión PHP zip no disponible');
    $st=$pdo->prepare('SELECT id,name,is_dir FROM files WHERE id=? AND deleted_at IS NULL');
    $st->execute([$id]);$folder=$st->fetch(PDO::FETCH_ASSOC);
    if (!$folder || !(int)$folder['is_dir']) dcFail(404,'Carpeta inexistente');
    $maxFiles=2000;$maxBytes=1024*1024*1024;$count=0;$bytes=0;
    $queue=[[$id,'']];$seen=[$id=>true];$entries=[];
    $children=$pdo->prepare('SELECT id,name,is_dir,storage_name,size,owner_id FROM files WHERE parent_id=? AND deleted_at IS NULL ORDER BY is_dir DESC,name ASC');
    while ($queue) {
        [$parent,$prefix]=array_shift($queue);
        $children->execute([$parent]);
        foreach ($children->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $child=(int)$row['id'];
            if (isset($seen[$child])) dcFail(409,'Estructura cíclica');
            $seen[$child]=true;
            if (((int)$row['owner_id']!==$dcOwnerId || !dcOwnedTree($child,$pdo,$dcOwnerId))) dcFail(403,'Carpeta con propietarios mezclados');
            // No permitir barras, rutas relativas o caracteres peligrosos dentro del ZIP.
            $name=(string)$row['name'];
            if ($name==='' || $name==='.' || $name==='..' || str_contains($name,'/') || str_contains($name,'\\') || preg_match('/[\x00-\x1F]/',$name)) dcFail(409,'Nombre inseguro en la carpeta');
            $entry=$prefix.$name;
            if (strlen($entry)>900) dcFail(413,'Ruta demasiado larga para exportar');
            if (++$count>$maxFiles) dcFail(413,'Máximo 2000 elementos por descarga');
            if ((int)$row['is_dir']===1) {
                $entries[]=['dir',$entry.'/',''];
                $queue[]=[$child,$entry.'/'];
            } else {
                $stored=(string)$row['storage_name'];
                if ($stored==='' || basename($stored)!==$stored || $stored==='.' || $stored==='..') dcFail(409,'Archivo con identificador inválido');
                $path=$STORAGE_DIR.$stored;
                if (!is_file($path) || is_link($path) || !is_readable($path)) dcFail(409,'Archivo físico no disponible');
                $size=filesize($path);
                if ($size===false || ($bytes+=$size)>$maxBytes) dcFail(413,'Máximo 1 GiB por descarga');
                $entries[]=['file',$entry,$path];
            }
        }
    }
    $tmp=tempnam(sys_get_temp_dir(),'dc_zip_');
    if ($tmp===false) dcFail(500,'No se pudo crear el archivo temporal');
    register_shutdown_function(static function()use($tmp){@unlink($tmp);});
    $zip=new ZipArchive();
    if ($zip->open($tmp,ZipArchive::CREATE|ZipArchive::OVERWRITE)!==true) dcFail(500,'No se pudo crear ZIP');
    foreach ($entries as [$type,$entry,$path]) {
        $ok=$type==='dir' ? $zip->addEmptyDir($entry) : $zip->addFile($path,$entry);
        if (!$ok) {$zip->close();dcFail(500,'No se pudo agregar un elemento al ZIP');}
    }
    if (!$zip->close()) dcFail(500,'No se pudo terminar el ZIP');
    $filename = str_replace(["\r", "\n", '"', '\\'], '_', basename((string)$folder['name'])) . '.zip';
    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="'.str_replace(['"','\\'],'_',$filename).'"; filename*=UTF-8\'\''.rawurlencode((string)$folder['name'].'.zip'));
    header('Cache-Control: private, no-store');
    header('X-Content-Type-Options: nosniff');
    header('Content-Length: '.filesize($tmp));
    readfile($tmp);
    exit;
}

if ($action === 'download') {
    $id = (int)$_GET['id'];
    dcReadFileGuard($id,$pdo,false,$dcOwnerId);
    $stmt = $pdo->prepare("SELECT * FROM files WHERE id = ?");
    $stmt->execute([$id]);
    $file = $stmt->fetch();
    if (!$file || driveIsTrashed($id,$pdo)) {http_response_code(404);exit;}
    if (isset($_GET['dc_interaction']) && $_GET['dc_interaction']==='1') {
        // HF2: las aperturas inline no cuentan como descarga.
    }
    $path = $STORAGE_DIR . $file['storage_name'];
    if (is_file($path)) {
        if (isset($_GET['download']) && (string)$_GET['download']==='1') dcActivity85m($pdo,$dcOwnerId,$id,'download');
        // v20.10.10: descargar desde menú; visor multimedia/PDF sigue siendo inline.
        $forceDownload = isset($_GET['download']) && (string)$_GET['download'] === '1';
        $downloadName = basename((string)$file['name']);
        $mime = preg_match('/\.pdf$/i', $downloadName) ? 'application/pdf' : (string)$file['mime_type'];
        // Los tipos activos (HTML/SVG/XML) nunca se sirven inline en el origen de la app:
        // permitirían XSS same-origin con la cookie de sesión y el token CSRF.
        $activeMime = preg_match('~^(text/html|application/xhtml\+xml|image/svg\+xml|text/xml|application/xml|application/x-php)~i', $mime)
            || preg_match('~\.(html?|xhtml|svg|xml|php[0-9]?|phtml)$~iD', $downloadName);
        if ($activeMime) {
            $mime = 'application/octet-stream';
            header("Content-Security-Policy: default-src 'none'; sandbox");
        }
        $fallbackName = str_replace(["\r", "\n", '"', "\\"], '_', $downloadName);
        header('Content-Description: File Transfer');
        header('Content-Type: '.$mime);
        header('X-Content-Type-Options: nosniff');
        header('Content-Disposition: '.($forceDownload || $activeMime ? 'attachment' : 'inline').'; filename="'.$fallbackName.'"; filename*=UTF-8\'\''.rawurlencode($downloadName));
        header('Expires: 0');
        header('Cache-Control: must-revalidate');
        header('Pragma: public');
        header('Accept-Ranges: bytes');
        header('Content-Length: ' . filesize($path));
        readfile($path);
        exit;
    }
}

if ($action === 'share') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') dcFail(405);
    dcRequirePolicy($dcLogged,$dcIsAdmin,'can_share');
    $id=(int)($_POST['id']??0);
    $seconds=shareDurationSeconds($_POST);
    if ($id<=0 || $seconds===false) dcFail(400,'Datos inválidos');
    if (!driveValidateId($id,$pdo)) dcFail(404,'Archivo inexistente');
    dcReadFileGuard($id,$pdo,false,$dcOwnerId);
    $pass=!empty($_POST['password'])?password_hash((string)$_POST['password'],PASSWORD_DEFAULT):null;
    $hash=bin2hex(random_bytes(16));
    $st=$pdo->prepare('INSERT INTO shares (file_id,hash,password_hash,expires_at,owner_id) VALUES (?,?,?,?,?)');
    $st->execute([$id,$hash,$pass,shareExpiry($seconds),$dcOwnerId]);
    dcActivity85m($pdo,$dcOwnerId,$id,'share');
    echo json_encode(['link'=>'api.php?public_hash='.$hash]);exit;
}
?>