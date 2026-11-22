<?php
/* Reutilización de derivados entre cuentas: exclusivamente por SHA-256 y perfil idéntico.
   No comunica a los usuarios identidades, nombres ni rutas ajenas. */
function dc51Digest(string $path): ?string {
 if(!is_file($path)||is_link($path))return null;
 $h=@hash_file('sha256',$path);
 return is_string($h)&&preg_match('/^[a-f0-9]{64}$/D',$h)?$h:null;
}
function dc51CacheGet(PDO $pdo,string $digest,string $profile): ?array {
 $q=$pdo->prepare('SELECT source_storage,output_storage,output_sha256,output_size,poster_name,clip_name,poster_sha256,clip_sha256 FROM dc_reuse_cache_51 WHERE original_sha256=? AND profile=? LIMIT 1');
 $q->execute([$digest,$profile]);$row=$q->fetch(PDO::FETCH_ASSOC);
 return $row?:null;
}
function dc51Store(PDO $pdo,string $digest,string $profile,string $source,string $output,string $sha,int $size,?string $poster=null,?string $clip=null,?string $posterSha=null,?string $clipSha=null):void {
 $q=$pdo->prepare('INSERT INTO dc_reuse_cache_51 (original_sha256,profile,source_storage,output_storage,output_sha256,output_size,poster_name,clip_name,poster_sha256,clip_sha256) VALUES (?,?,?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE source_storage=VALUES(source_storage),output_storage=VALUES(output_storage),output_sha256=VALUES(output_sha256),output_size=VALUES(output_size),poster_name=VALUES(poster_name),clip_name=VALUES(clip_name),poster_sha256=VALUES(poster_sha256),clip_sha256=VALUES(clip_sha256)');
 $q->execute([$digest,$profile,$source,$output,$sha,$size,$poster,$clip,$posterSha,$clipSha]);
}
function dc51Link(string $origin,string $destination,?string $expectedSha=null):bool {
 if(!is_file($origin)||is_link($origin)||is_link($destination))return false;
 $src=@stat($origin);$dir=@stat(dirname($destination));
 if(!$src||!$dir||$src['dev']!==$dir['dev']||$src['size']<100)return false;
 if($expectedSha!==null && dc51Digest($origin)!==$expectedSha)return false;
 $tmp=$destination.'.dc51.'.bin2hex(random_bytes(6));
 if(!@link($origin,$tmp))return false;
 try{
  clearstatcache(true,$tmp);$a=@stat($tmp);$b=@stat($origin);
  if(!$a||!$b||$a['ino']!==$b['ino']||$a['dev']!==$b['dev']||$b['size']!==$src['size'])return false;
  if($expectedSha!==null && dc51Digest($tmp)!==$expectedSha)return false;
  if(!@rename($tmp,$destination))return false;
  return true;
 }finally{if(is_file($tmp))@unlink($tmp);}
}
function dc51ConvertedReuse(PDO $pdo,string $root,string $sha,string $dest):bool{
 $cache=dc51CacheGet($pdo,$sha,'video-h264-aac-v1');if(!$cache)return false;
 $stored=(string)$cache['output_storage'];
 if(!preg_match('/^[a-zA-Z0-9_.-]+$/D',$stored))return false;
 // Anclar a un archivo lógico aún existente para no reutilizar salidas huérfanas.
 $q=$pdo->prepare('SELECT COUNT(*) FROM files WHERE storage_name=? AND is_dir=0 AND deleted_at IS NULL');
 $q->execute([$stored]);if(!(int)$q->fetchColumn())return false;
 return dc51Link(rtrim($root,'/').'/'.$stored,$dest,(string)$cache['output_sha256']);
}
function dc51PreviewReuse(PDO $pdo,string $root,string $sha,string $profile,string $poster,string $clip):bool{
 $cache=dc51CacheGet($pdo,$sha,$profile);if(!$cache)return false;
 $p=(string)$cache['poster_name'];$c=(string)$cache['clip_name'];
 if(!preg_match('/^dc-local-[0-9]+\.jpg$/D',$p)||!preg_match('/^dc-local-[0-9]+\.mp4$/D',$c))return false;
 $src=rtrim($root,'/').'/';
 if(!is_file($src.$p)||!is_file($src.$c))return false;
 // No usar directamente el par de archivos destino hasta validar estado DB del proceso.
 if(!dc51Link($src.$p,$poster,(string)$cache['poster_sha256']))return false;
 if(!dc51Link($src.$c,$clip,(string)$cache['clip_sha256'])){
  @unlink($poster);return false;
 }
 return true;
}
/* Ahorro real dentro de files: cada segundo nombre físico que comparte inode ahorra tamaño.
   No consulta nombres, hashes ni propietarios fuera del backend. */
function dc51StorageSavings(PDO $pdo,string $root,?string $thumbRoot=null,int $limit=100000):array{
 $count=(int)$pdo->query("SELECT COUNT(*) FROM (SELECT storage_name FROM files WHERE is_dir=0 AND storage_name IS NOT NULL UNION SELECT storage_name FROM file_versions WHERE storage_name IS NOT NULL) AS t")->fetchColumn();
 if($count>$limit)return ['available'=>false,'reason'=>'Demasiados archivos para un cálculo interactivo; programa un informe fuera de horario'];
 $q=$pdo->query('SELECT storage_name FROM files WHERE is_dir=0 AND storage_name IS NOT NULL UNION SELECT storage_name FROM file_versions WHERE storage_name IS NOT NULL');
 $physical=[];$saved=0;$dupes=0;$scanned=0;
 $visit=static function(string $path)use(&$physical,&$saved,&$dupes,&$scanned):void{
  if(is_link($path)||!is_file($path))return;
  $st=@stat($path);if(!$st)return;
  $scanned++;$key=$st['dev'].':'.$st['ino'];
  if(isset($physical[$key])){$saved+=(int)$st['size'];$dupes++;}
  else $physical[$key]=true;
 };
 while(($name=$q->fetchColumn())!==false){
  if(!is_string($name)||!preg_match('/^[a-zA-Z0-9_.-]+$/D',$name))continue;
  $visit(rtrim($root,'/').'/'.$name);
 }
 if($thumbRoot&&is_dir($thumbRoot)){
  // Solo derivados locales cuyos nombres gestiona Drive-Craft.
  foreach((glob(rtrim($thumbRoot,'/').'/dc-local-*')?:[]) as $path){
   if($scanned>=$limit)return ['available'=>false,'reason'=>'Se superó el límite de entradas del cálculo'];
   if(preg_match('/^dc-local-[0-9]+\.(jpg|mp4)$/D',basename($path)))$visit($path);
  }
 }
 return ['available'=>true,'saved_bytes'=>$saved,'linked_files'=>$dupes,'files_scanned'=>$scanned];
}
