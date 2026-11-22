<?php
declare(strict_types=1);
/** Drive-Craft: indexador CLI de checksums para archivos actuales. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
ini_set('display_errors', 'stderr');
error_reporting(E_ALL);
$opts = getopt('', ['apply', 'limit:', 'owner:', 'algorithm:', 'help']);
if (isset($opts['help'])) {
    echo "Uso: php dc_checksum_index.php [--apply] [--limit=100] [--owner=ID] [--algorithm=sha256|md5]\n";
    echo "Sin --apply, solo simula. Reanuda ejecutando de nuevo.\n";
    exit(0);
}
$apply = array_key_exists('apply', $opts);
$limit = filter_var($opts['limit'] ?? '100', FILTER_VALIDATE_INT, ['options'=>['min_range'=>1,'max_range'=>10000]]);
if ($limit === false) { fwrite(STDERR,"Límite inválido (1..10000)\n");exit(2); }
$ownerId = null;
if (isset($opts['owner'])) {
    $ownerId = filter_var($opts['owner'], FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]);
    if ($ownerId === false) {fwrite(STDERR,"Owner inválido\n");exit(2);}
}
$algoOption = $opts['algorithm'] ?? null;
if ($algoOption !== null && !in_array($algoOption,['sha256','md5'],true)) {fwrite(STDERR,"Algoritmo inválido\n");exit(2);}
require __DIR__ . '/config.php';
if (!isset($pdo) || !$pdo instanceof PDO || !isset($STORAGE_DIR)) { fwrite(STDERR,"Faltan PDO o STORAGE_DIR en config.php\n");exit(3); }
if (!is_dir($STORAGE_DIR)) {fwrite(STDERR,"Directorio de almacenamiento inexistente\n");exit(3);}
$lock = fopen(sys_get_temp_dir().'/dc_checksum_index.lock', 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {fwrite(STDERR,"Ya hay otro indexador en ejecución\n");exit(4);}
try {
    $col = $pdo->query("SHOW COLUMNS FROM files LIKE 'content_hash'")->fetch(PDO::FETCH_ASSOC);
    $colAlgo = $pdo->query("SHOW COLUMNS FROM files LIKE 'content_hash_algorithm'")->fetch(PDO::FETCH_ASSOC);
    if (!$col || !$colAlgo) throw new RuntimeException('Falta aplicar MIGRACION-CHECKSUMS.sql');
    $algorithm = $algoOption;
    if ($algorithm === null) {
        $st = $pdo->prepare("SELECT setting_value FROM dc_app_settings WHERE setting_key='dedup_algorithm' LIMIT 1");
        $st->execute();
        $algorithm = $st->fetchColumn() ?: 'sha256';
    }
    if (!in_array($algorithm,['sha256','md5'],true)) throw new RuntimeException('Algoritmo configurado desconocido');
    $base = rtrim($STORAGE_DIR, '/').'/';
    $sql = "SELECT id,owner_id,storage_name,size FROM files WHERE is_dir=0 AND owner_id IS NOT NULL AND storage_name IS NOT NULL AND storage_name<>'' AND (content_hash IS NULL OR content_hash_algorithm IS NULL OR content_hash_algorithm<>:algo)";
    if ($ownerId !== null) $sql .= ' AND owner_id=:owner';
    $sql .= ' ORDER BY id LIMIT '.(int)$limit;
    $st=$pdo->prepare($sql);$st->bindValue(':algo',$algorithm);
    if ($ownerId !== null) $st->bindValue(':owner',$ownerId,PDO::PARAM_INT);
    $st->execute();$rows=$st->fetchAll(PDO::FETCH_ASSOC);
    $update = $pdo->prepare("UPDATE files SET content_hash_algorithm=?,content_hash=? WHERE id=? AND owner_id=? AND storage_name=? AND size=? AND is_dir=0 AND (content_hash IS NULL OR content_hash_algorithm IS NULL OR content_hash_algorithm<>?)");
    $ok=0;$skip=0;$failed=0;
    printf("Modo: %s | algoritmo: %s | candidatos: %d | límite: %d\n",$apply?'APLICAR':'SIMULACIÓN',$algorithm,count($rows),$limit);
    foreach($rows as $r) {
        $id=(int)$r['id'];$stored=(string)$r['storage_name'];$size=(int)$r['size'];
        // No admitir subrutas, traversal, symlinks o valores fuera del volumen.
        if ($stored==='' || $stored==='.' || $stored==='..' || basename($stored)!==$stored || strpos($stored,"\\")!==false || str_contains($stored,"\0")) {
            printf("SKIP id=%d nombre físico inseguro\n",$id);$skip++;continue;
        }
        $path=$base.$stored;
        clearstatcache(true,$path);
        if (!is_file($path) || is_link($path) || !is_readable($path)) {printf("SKIP id=%d archivo ausente/no legible\n",$id);$skip++;continue;}
        $before=@stat($path);
        if (!$before || (int)$before['size']!==$size) {printf("SKIP id=%d tamaño distinto en disco\n",$id);$skip++;continue;}
        $digest=@hash_file($algorithm,$path); // procesamiento incremental de memoria
        clearstatcache(true,$path);
        $after=@stat($path);
        if ($digest===false || !$after || $before['size']!==$after['size'] || $before['mtime']!==$after['mtime'] || $before['ino']!==$after['ino'] || $before['dev']!==$after['dev']) {
            printf("SKIP id=%d archivo cambió durante hash o lectura falló\n",$id);$failed++;continue;
        }
        if ($apply) {
            $update->execute([$algorithm,$digest,$id,(int)$r['owner_id'],$stored,$size,$algorithm]);
            if ($update->rowCount()!==1) {printf("SKIP id=%d metadatos cambiaron\n",$id);$skip++;continue;}
        }
        $ok++;
        printf("%s id=%d bytes=%d %s=%s\n",$apply?'OK':'SIM',$id,$size,$algorithm,substr($digest,0,16));
    }
    printf("Resumen: %d %s, %d omitidos, %d fallos.\n",$ok,$apply?'indexados':'simulados',$skip,$failed);
    if (count($rows)===$limit) echo "Puede haber más pendientes: repite el comando.\n";
    if (!$apply) echo "No se modificó MariaDB. Agrega --apply para escribir.\n";
    exit($failed?1:0);
} catch (Throwable $e) {fwrite(STDERR,'ERROR: '.$e->getMessage()."\n");exit(3);}
