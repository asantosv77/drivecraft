<?php
/* Recupera cache de conversión cuando la ruta del propietario inicial desapareció.
 * Sólo permite resultados con SHA-256 completo idéntico y una fila vigente en files.
 * No expone identidades ni ubicaciones a los usuarios. */
function dc53ReuseConverted(PDO $pdo,string $root,string $originalHash,string $destination):bool {
 $cache=dc51CacheGet($pdo,$originalHash,'video-h264-aac-v1');
 if(!$cache)return false;
 $expected=(string)$cache['output_sha256'];
 $size=(int)$cache['output_size'];
 if(!preg_match('/^[a-f0-9]{64}$/D',$expected)||$size<100)return false;
 $base=rtrim($root,'/').'/';
 $stored=(string)$cache['output_storage'];
 $query=$pdo->prepare('SELECT COUNT(*) FROM files WHERE storage_name=? AND is_dir=0 AND deleted_at IS NULL');
 if(preg_match('/^[a-zA-Z0-9_.-]+$/D',$stored)){
  $query->execute([$stored]);
  if((int)$query->fetchColumn()>0 && dc51Link($base.$stored,$destination,$expected))return true;
 }
 /* El nombre cacheado ya no existe: buscar únicamente archivos registrados y vigentes
  * que coincidan en contenido, jamás sólo por nombre o metadatos de video. */
 $q=$pdo->prepare("SELECT DISTINCT storage_name FROM files WHERE is_dir=0 AND deleted_at IS NULL AND size=? AND content_hash_algorithm='sha256' AND content_hash=? AND storage_name IS NOT NULL LIMIT 250");
 $q->execute([$size,$expected]);
 while(($candidate=$q->fetchColumn())!==false){
  if(!is_string($candidate)||!preg_match('/^[a-zA-Z0-9_.-]+$/D',$candidate))continue;
  if(!dc51Link($base.$candidate,$destination,$expected))continue;
  // Reparar la referencia única, pero sólo si no fue sustituida por otro worker.
  try{$u=$pdo->prepare("UPDATE dc_reuse_cache_51 SET output_storage=? WHERE original_sha256=? AND profile='video-h264-aac-v1' AND output_sha256=?");$u->execute([$candidate,$originalHash,$expected]);}
  catch(Throwable $e){error_log('DriveCraft dc53 cache repair: '.$e->getMessage());}
  return true;
 }
 return false;
}
