<?php
// Regeneración masiva: ejecutar SOLO por CLI para evitar saturar el servidor web.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
ini_set('memory_limit', '512M');
require __DIR__ . '/config.php';
function ensurePdfThumbnail($file, $pdo, $STORAGE_DIR, $THUMBS_DIR) {
    if (!$file || $file['is_dir'] || stripos((string)$file['mime_type'], 'pdf') === false) return false;
    $original = $STORAGE_DIR . $file['storage_name'];
    $target = $THUMBS_DIR . $file['storage_name'] . '.webp';
    if (!is_file($original)) return false;
    if (is_file($target) && filesize($target) > 0) return true;
    $tmpBase = $THUMBS_DIR . 'pdf-' . bin2hex(random_bytes(10));
    $tmp = $tmpBase . '.webp';
    if (trim((string)shell_exec('command -v pdftoppm 2>/dev/null')) !== '') {
        $cmd = 'timeout 18s pdftoppm -f 1 -l 1 -singlefile -scale-to 360 -png ' .
            escapeshellarg($original) . ' ' . escapeshellarg($tmpBase) . ' 2>/dev/null';
        exec($cmd, $output, $status);
    }
    if (!is_file($tmp) && trim((string)shell_exec('command -v convert 2>/dev/null')) !== '') {
        // Puede fallar si la política de ImageMagick bloquea PDF; no se modifica la política.
        $cmd = 'timeout 18s convert -limit memory 64MiB -limit map 128MiB -density 90 ' .
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

/**
 * Miniatura ligera para fotografías (modo explorador de álbumes y vista grid).
 * Máx. 512 px en el lado largo, JPEG calidad 78. Ocupa poco y admite iconos grandes.
 * Se genera al subir o bajo demanda en action=thumb. Si se elimina la foto, deleteItem
 * ya borra $THUMBS_DIR.$storage_name.'.jpg' cuando has_thumb=1.
 */
function dcIsImageFile(array $file): bool {
    if (!$file || !empty($file['is_dir'])) return false;
    $mime = strtolower((string)($file['mime_type'] ?? ''));
    if (preg_match('#^image/(jpeg|pjpeg|png|gif|webp|bmp|x-ms-bmp|avif)$#', $mime)) return true;
    $ext = strtolower(pathinfo((string)($file['name'] ?? ''), PATHINFO_EXTENSION));
    return in_array($ext, ['jpg','jpeg','jpe','png','gif','webp','bmp','avif'], true);
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
    $info = @getimagesize($original);
    if ($info === false || empty($info[0]) || empty($info[1])) return false;
    $w = (int)$info[0]; $h = (int)$info[1];
    if ($w < 1 || $h < 1) return false;
    // Evitar decodificar imágenes enormes en RAM (protección Raspberry / memoria limitada).
    if ($w * $h > 40000000) return false; // ~40 Mpx
    $tmp = $target.'.'.bin2hex(random_bytes(6)).'.tmp.webp';
    $ok = false;
    // Preferir ImageMagick: -auto-orient corrige EXIF de forma fiable.
    if (trim((string)shell_exec('command -v convert 2>/dev/null')) !== '') {
        $cmd = 'timeout 25s convert -limit memory 96MiB -limit map 192MiB '
            . escapeshellarg($original)
            . ' -auto-orient -thumbnail ' . (int)$maxSide . 'x' . (int)$maxSide
            . ' -background white -alpha remove -quality 78 '
            . escapeshellarg($tmp) . ' 2>/dev/null';
        exec($cmd, $out, $status);
        $ok = is_file($tmp) && filesize($tmp) > 0;
    }
    if (!$ok && trim((string)shell_exec('command -v magick 2>/dev/null')) !== '') {
        $cmd = 'timeout 25s magick -limit memory 96MiB -limit map 192MiB '
            . escapeshellarg($original)
            . ' -auto-orient -thumbnail ' . (int)$maxSide . 'x' . (int)$maxSide
            . ' -background white -alpha remove -quality 78 '
            . escapeshellarg($tmp) . ' 2>/dev/null';
        exec($cmd, $out, $status);
        $ok = is_file($tmp) && filesize($tmp) > 0;
    }
    if (!$ok && extension_loaded('gd') && function_exists('imagecreatefromstring')) {
        $raw = @file_get_contents($original);
        if ($raw !== false) {
            $src = @imagecreatefromstring($raw);
            unset($raw);
            if ($src) {
                $src = dcGdApplyExifOrientation($src, $original);
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
$st = $pdo->query("SELECT id, name, storage_name, mime_type, is_dir FROM files WHERE deleted_at IS NULL AND is_dir = 0");
$rows = $st->fetchAll(PDO::FETCH_ASSOC);
$total = count($rows);
$ok = 0; $skip = 0; $fail = 0;
echo "Total archivos: $total\n";
foreach ($rows as $i => $f) {
    $n = $i + 1;
    $mime = strtolower((string)$f['mime_type']);
    $isPdf = (stripos($mime, 'pdf') !== false) || (strtolower(pathinfo($f['name'], PATHINFO_EXTENSION)) === 'pdf');
    $isImg = preg_match('#^image/(jpeg|pjpeg|png|gif|webp|bmp|x-ms-bmp|avif)$#', $mime);
    if (!$isPdf && !$isImg) { $skip++; echo "[$n/$total] SKIP (no imagen/pdf): {$f['name']}\n"; continue; }
    $target = $THUMBS_DIR . $f['storage_name'] . '.webp';
    if (is_file($target) && filesize($target) > 0) { $skip++; echo "[$n/$total] OK (ya existe): {$f['name']}\n"; continue; }
    $res = false;
    if ($isPdf) {
        $res = ensurePdfThumbnail($f, $pdo, $STORAGE_DIR, $THUMBS_DIR);
    } else {
        $res = ensureImageThumbnail($f, $pdo, $STORAGE_DIR, $THUMBS_DIR);
    }
    if ($res) { $ok++; echo "[$n/$total] GENERADO: {$f['name']}\n"; }
    else { $fail++; echo "[$n/$total] FALLO: {$f['name']}\n"; }
    if ($n % 50 === 0) { echo "--- Progreso: $n/$total (ok=$ok fail=$fail skip=$skip) ---\n"; gc_collect_cycles(); }
}
echo "\n=== RESUMEN ===\nGenerados: $ok\nYa exist?an: $skip\nFallidos: $fail\nTotal: $total\n";
