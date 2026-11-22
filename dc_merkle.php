<?php
/**
 * Drive-Craft — Motor de Árboles de Merkle (Chunks adaptativos y RAM).
 *
 * content_hash_algorithm:
 *   - 'sha256'  → hash lineal del archivo completo (backfill / legado)
 *   - 'merkle'  → Merkle root sobre hojas SHA-256 de cada fragmento de subida
 *
 * head_hash: SHA-256 de los primeros DC_MERKLE_HEAD_BYTES (filtro rápido).
 */

define('DC_MERKLE_HEAD_BYTES', 2 * 1048576); // 2 MiB para Fast Upload / prefiltro

/**
 * Tamaño de hoja recomendado según tamaño de archivo y tope efectivo del admin.
 * Debe mantenerse alineado con dcAdaptiveChunkSize() del cliente.
 */
function dcMerkleChunkSize(int $fileSizeBytes, int $effectiveCapMiB = 50): int {
    $kib512 = 524288; // 512 KiB para archivos <= 20 MiB (ideal para MP3 y audio)
    $mib = 1048576;
    $gib = 1073741824;
    $capBytes = max(1, $effectiveCapMiB) * $mib;

    if ($fileSizeBytes <= 20 * $mib) {
        return min($kib512, $capBytes);
    }
    if ($fileSizeBytes <= 100 * $mib) {
        return min($mib, $capBytes);
    }
    if ($fileSizeBytes <= $gib) {
        return min(10 * $mib, $capBytes);
    }
    return min(max(1, min(100, $effectiveCapMiB)) * $mib, $capBytes);
}

/**
 * Hoja Merkle de un fragmento: SHA-256(0x00 || bytes del chunk).
 * Se calcula sobre el tmp de PHP del fragmento recibido (sin releer el .part final).
 */
function dcMerkleLeafFile(string $filePath): string {
    $ctx = hash_init('sha256');
    hash_update($ctx, "\x00");
    if (!@hash_update_file($ctx, $filePath)) {
        throw new RuntimeException('No se pudo calcular el hash del fragmento');
    }
    return hash_final($ctx, false);
}

/**
 * Hoja Merkle sobre un buffer en memoria (mismo prefijo 0x00).
 */
function dcMerkleLeafBytes(string $bytes): string {
    return hash('sha256', "\x00" . $bytes, false);
}

/**
 * Merkle root a partir de hojas en hex (64 chars). Nodo interno: SHA-256(0x01 || L || R).
 * Si el nivel tiene un número impar de nodos, el último se promueve sin rehashear.
 */
function dcMerkleRootFromLeaves(array $leafHexHashes): string {
    $count = count($leafHexHashes);
    if ($count === 0) {
        return hash('sha256', "\x00");
    }
    $level = [];
    foreach ($leafHexHashes as $hex) {
        if (!is_string($hex) || !preg_match('/^[a-f0-9]{64}$/iD', $hex)) {
            throw new InvalidArgumentException('Hoja Merkle inválida');
        }
        $level[] = hex2bin(strtolower($hex));
    }
    while (count($level) > 1) {
        $next = [];
        $n = count($level);
        for ($i = 0; $i < $n; $i += 2) {
            if ($i + 1 < $n) {
                $next[] = hash('sha256', "\x01" . $level[$i] . $level[$i + 1], true);
            } else {
                $next[] = $level[$i];
            }
        }
        $level = $next;
    }
    return bin2hex($level[0]);
}

/**
 * SHA-256 de los primeros $headBytes del archivo (o del archivo entero si es menor).
 */
function dcMerkleHeadFile(string $filePath, int $headBytes = DC_MERKLE_HEAD_BYTES): string {
    if ($headBytes < 1) {
        $headBytes = DC_MERKLE_HEAD_BYTES;
    }
    $fh = @fopen($filePath, 'rb');
    if ($fh === false) {
        throw new RuntimeException('No se pudo abrir el archivo para head_hash');
    }
    try {
        $data = fread($fh, $headBytes);
        if ($data === false) {
            throw new RuntimeException('No se pudo leer el encabezado del archivo');
        }
        return hash('sha256', $data);
    } finally {
        fclose($fh);
    }
}

/**
 * Recalcula el Merkle root de un archivo ya consolidado partiendo por $chunkSize.
 * Útil en backfill o verificación defensiva de candidatos hardlink.
 */
function dcMerkleRootFromFile(string $filePath, int $chunkSize): string {
    if ($chunkSize < 1) {
        throw new InvalidArgumentException('chunkSize inválido');
    }
    $fh = @fopen($filePath, 'rb');
    if ($fh === false) {
        throw new RuntimeException('No se pudo abrir el archivo para Merkle');
    }
    $leaves = [];
    try {
        while (!feof($fh)) {
            $buf = fread($fh, $chunkSize);
            if ($buf === false) {
                throw new RuntimeException('Lectura incompleta al calcular Merkle');
            }
            if ($buf === '') {
                break;
            }
            $leaves[] = dcMerkleLeafBytes($buf);
        }
    } finally {
        fclose($fh);
    }
    return dcMerkleRootFromLeaves($leaves);
}
