<?php
/**
 * Drive-Craft — Indexado retroactivo de content_hash / head_hash.
 *
 * Uso CLI:
 *   php dc_hash_backfill.php           # procesa un lote (50)
 *   php dc_hash_backfill.php --all      # hasta agotar pendientes
 *   php dc_hash_backfill.php --limit=20
 *
 * Desde admin_api.php (action hash_backfill_run) solo se carga la función;
 * no se vuelve a incluir config.php para evitar redeclaraciones.
 *
 * Política de backfill:
 *   - content_hash = SHA-256 lineal del archivo completo (compatible con legado)
 *   - content_hash_algorithm = 'sha256'
 *   - head_hash = SHA-256 de los primeros 2 MiB
 */
declare(strict_types=1);

// Solo bootstrap propio en CLI. En web admin_api.php ya cargó config/auth/merkle.
if (PHP_SAPI === 'cli') {
    require __DIR__ . '/config.php';
    require_once __DIR__ . '/dc_merkle.php';
} elseif (!function_exists('dcMerkleHeadFile')) {
    require_once __DIR__ . '/dc_merkle.php';
}

function dcHashBackfillBatch(PDO $pdo, string $storageDir, int $limit = 25): array {
    $limit = max(1, min(100, $limit));
    if ($storageDir !== '' && substr($storageDir, -1) !== '/' && substr($storageDir, -1) !== DIRECTORY_SEPARATOR) {
        $storageDir .= '/';
    }
    $sql = 'SELECT id, storage_name, size FROM files
            WHERE is_dir=0 AND deleted_at IS NULL
              AND storage_name IS NOT NULL AND storage_name <> \'\'
              AND (content_hash IS NULL OR head_hash IS NULL)
            ORDER BY id ASC LIMIT ' . (int)$limit;
    $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    $updated = 0;
    $skipped = 0;
    $errors = [];
    $st = $pdo->prepare(
        'UPDATE files SET content_hash_algorithm=COALESCE(content_hash_algorithm,?), content_hash=COALESCE(content_hash,?), head_hash=COALESCE(head_hash,?) WHERE id=? AND (content_hash IS NULL OR head_hash IS NULL)'
    );
    foreach ($rows as $row) {
        $id = (int)$row['id'];
        $stored = (string)$row['storage_name'];
        if (basename($stored) !== $stored || !preg_match('/^[a-zA-Z0-9_.-]+$/D', $stored)) {
            $skipped++;
            $errors[] = ['id' => $id, 'error' => 'storage_name inválido'];
            continue;
        }
        $path = $storageDir . $stored;
        if (!is_file($path) || is_link($path)) {
            $skipped++;
            $errors[] = ['id' => $id, 'error' => 'archivo ausente'];
            continue;
        }
        clearstatcache(true, $path);
        try {
            $head = dcMerkleHeadFile($path);
            $digest = @hash_file('sha256', $path);
            if (!is_string($digest) || !preg_match('/^[a-f0-9]{64}$/D', $digest)) {
                throw new RuntimeException('SHA-256 falló');
            }
            $st->execute(['sha256', $digest, $head, $id]);
            $updated += $st->rowCount() > 0 ? 1 : 0;
        } catch (Throwable $e) {
            $skipped++;
            $errors[] = ['id' => $id, 'error' => $e->getMessage()];
            error_log('Drive-Craft hash backfill #' . $id . ': ' . $e->getMessage());
        }
    }
    $pending = (int)$pdo->query(
        'SELECT COUNT(*) FROM files WHERE is_dir=0 AND deleted_at IS NULL
         AND storage_name IS NOT NULL AND storage_name <> \'\'
         AND (content_hash IS NULL OR head_hash IS NULL)'
    )->fetchColumn();
    return [
        'success' => true,
        'processed' => count($rows),
        'updated' => $updated,
        'skipped' => $skipped,
        'pending' => $pending,
        'errors' => array_slice($errors, 0, 20),
    ];
}

// CLI only
if (PHP_SAPI === 'cli' && isset($argv[0]) && realpath((string)$argv[0]) === realpath(__FILE__)) {
    $limit = 25;
    $all = false;
    foreach (array_slice($argv, 1) as $arg) {
        if ($arg === '--all') $all = true;
        elseif (preg_match('/^--limit=(\d+)$/', $arg, $m)) $limit = (int)$m[1];
    }
    $totalUpdated = 0;
    do {
        $result = dcHashBackfillBatch($pdo, $STORAGE_DIR, $limit);
        $totalUpdated += (int)$result['updated'];
        fwrite(STDOUT, json_encode($result, JSON_UNESCAPED_UNICODE) . PHP_EOL);
        if (!$all || (int)$result['pending'] === 0 || (int)$result['processed'] === 0) {
            break;
        }
        usleep(100000);
    } while (true);
    fwrite(STDOUT, "Total actualizados: {$totalUpdated}\n");
    exit(0);
}
