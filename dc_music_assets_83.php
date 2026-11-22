<?php
// Drive-Craft 20.10.83: arte y letras por pista; solo sesiones autenticadas.
require __DIR__.'/config.php'; require_once __DIR__.'/auth.php';
$u=dcRequireUser();$uid=(int)$u['id'];
header('X-Content-Type-Options: nosniff');header('Cache-Control: private, no-store');
function a83fail(int $code,string $msg):never {http_response_code($code);header('Content-Type: application/json; charset=utf-8');echo json_encode(['error'=>$msg],JSON_UNESCAPED_UNICODE);exit;}
$id=filter_var($_GET['track_id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);if(!$id)a83fail(400,'Pista inválida');
$q=$pdo->prepare("SELECT id,name,storage_name,parent_id FROM files WHERE id=? AND owner_id=? AND is_dir=0 AND deleted_at IS NULL AND (mime_type LIKE 'audio/%' OR LOWER(SUBSTRING_INDEX(name,'.',-1)) IN ('mp3','m4a','aac','ogg','oga','opus','wav','flac','weba')) LIMIT 1");$q->execute([$id,$uid]);$t=$q->fetch(PDO::FETCH_ASSOC);if(!$t)a83fail(404,'Pista no disponible');
// Solo las canciones pertenecientes a una biblioteca musical configurada.
$p=$pdo->prepare('SELECT parent_id FROM files WHERE id=? AND owner_id=? AND is_dir=1 AND deleted_at IS NULL');$p->execute([(int)$t['parent_id'],$uid]);$parent=$p->fetchColumn();
$c=$pdo->prepare('SELECT 1 FROM dc_music_libraries_81 WHERE owner_id=? AND folder_id IN (?,?) LIMIT 1');$c->execute([$uid,(int)$t['parent_id'],(int)$parent]);if(!$c->fetchColumn())a83fail(403,'No pertenece a biblioteca musical');
$storage=(string)$t['storage_name'];if($storage===''||basename($storage)!==$storage||preg_match('/[^a-zA-Z0-9_.-]/',$storage))a83fail(404,'Almacenamiento no válido');
$source=rtrim($STORAGE_DIR,'/').'/'.$storage;if(!is_file($source)||is_link($source)||!is_readable($source))a83fail(404,'Audio no disponible');
$cache=rtrim(sys_get_temp_dir(),'/').'/drivecraft-audio83';if(!is_dir($cache))@mkdir($cache,0700,true);if(!is_dir($cache)||!is_writable($cache))a83fail(503,'Caché no disponible');
$key=hash('sha256',$uid.'|'.$id.'|'.filesize($source).'|'.filemtime($source));
function a83cmd(array $args):array { $spec=[1=>['pipe','w'],2=>['pipe','w']];$p=@proc_open($args,$spec,$pipes,null,null,['bypass_shell'=>true]);if(!is_resource($p))return [1,''];$stdout=stream_get_contents($pipes[1],262144);fclose($pipes[1]);$stderr=stream_get_contents($pipes[2],8192);fclose($pipes[2]);return [proc_close($p),$stdout];}
function a83meta(string $source):array {
 [$rc,$raw]=a83cmd(['/usr/bin/ffprobe','-v','error','-show_entries','format=duration:format_tags=artist,title,album:stream=index,codec_type:stream_disposition=attached_pic','-of','json',$source]);if($rc!==0)return [];$j=json_decode($raw,true);return is_array($j)?$j:[];
}
$action=(string)($_GET['action']??'lyrics');
if($action==='art'){
 $dest=$cache.'/'.$key.'.jpg';
 if(!is_file($dest)){
  $tmp=$dest.'.'.bin2hex(random_bytes(4)).'.tmp';
  // -map 0:v:0 selecciona solo el flujo de carátula incrustada si existe.
  $spec=[1=>['file','/dev/null','w'],2=>['file','/dev/null','w']];
  $cmd=['/usr/bin/ffmpeg','-nostdin','-v','error','-y','-i',$source,'-map','0:v:0','-frames:v','1','-vf','scale=400:400:force_original_aspect_ratio=decrease','-q:v','4','-f','image2',$tmp];
  $proc=@proc_open($cmd,$spec,$pp,null,null,['bypass_shell'=>true]);$rc=is_resource($proc)?proc_close($proc):1;
  if($rc!==0||!is_file($tmp)||filesize($tmp)<50||filesize($tmp)>2097152){@unlink($tmp);a83fail(404,'Sin carátula incrustada');}
  @rename($tmp,$dest);
 }
 header('Content-Type: image/jpeg');header('Content-Length: '.filesize($dest));header('Cache-Control: private, max-age=86400');readfile($dest);exit;
}
if($action!=='lyrics')a83fail(400,'Acción inválida');
header('Content-Type: application/json; charset=utf-8');
$file=$cache.'/'.$key.'-83a.json';if(is_file($file)&&filemtime($file)>time()-86400){readfile($file);exit;}
$meta=a83meta($source);$tags=$meta['format']['tags']??[];
$stem=pathinfo((string)$t['name'],PATHINFO_FILENAME);
// Primero las etiquetas de audio; si faltan, detectar «Artista - Canción».
$title=trim((string)($tags['title']??''));$artist=trim((string)($tags['artist']??''));$album=trim((string)($tags['album']??''));
if(preg_match('/^(.+?)\s+[-–—]\s+(.+)$/u',$stem,$pieces)){
  $fileArtist=trim($pieces[1]);$fileTitle=trim($pieces[2]);
  $fileTitle=preg_replace('/^\d{1,3}[.\s_-]+/u','',$fileTitle);
  if($artist==='')$artist=$fileArtist;
  if($title===''||strcasecmp($title,$stem)===0)$title=$fileTitle;
}
if($title==='')$title=preg_replace('/^\d{1,3}[.\s_-]+/u','',$stem);
$duration=(int)round((float)($meta['format']['duration']??0));
// Preferencia a letras locales: mismo nombre sin extensión, en la carpeta de la pista.
$stem=pathinfo((string)$t['name'],PATHINFO_FILENAME);$f=$pdo->prepare('SELECT storage_name,name FROM files WHERE parent_id=? AND owner_id=? AND is_dir=0 AND deleted_at IS NULL AND name IN (?,?) ORDER BY CASE WHEN LOWER(name) LIKE ? THEN 0 ELSE 1 END LIMIT 1');$f->execute([(int)$t['parent_id'],$uid,$stem.'.lrc',$stem.'.txt','%.lrc']);$local=$f->fetch(PDO::FETCH_ASSOC);
$sync=null;$plain=null;$origin='none';
if($local){$s=(string)$local['storage_name'];if($s!==''&&basename($s)===$s){$path=rtrim($STORAGE_DIR,'/').'/'.$s;if(is_file($path)&&!is_link($path)&&filesize($path)<262144){$body=file_get_contents($path);if(is_string($body)){if(str_ends_with(strtolower((string)$local['name']),'.lrc'))$sync=$body;else $plain=$body;$origin='local';}}}}
if(!$plain&&!$sync&&$artist!==''&&$title!==''&&function_exists('curl_init')){
  // La API /get exige coincidencia de artista/título/álbum/duración. La búsqueda
  // /search tolera metadatos incompletos o imperfectos y puede incluir LRC.
  function a83lookup(string $url):array {
    $ch=curl_init($url);
    curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>12,CURLOPT_CONNECTTIMEOUT=>4,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_USERAGENT=>'DriveCraft/20.10.83a (personal music library)',CURLOPT_HTTPHEADER=>['Accept: application/json'],CURLOPT_MAXREDIRS=>0]);
    $raw=curl_exec($ch);$code=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);curl_close($ch);
    if($code!==200||!is_string($raw)||strlen($raw)>524288)return [];
    $data=json_decode($raw,true);return is_array($data)?$data:[];
  }
  // Primero /search para no exigir álbum ni duración exacta.
  $results=a83lookup('https://lrclib.net/api/search?'.http_build_query(['artist_name'=>$artist,'track_name'=>$title]));
  $norm=static function(string $text):string { $text=strtolower(trim($text));return preg_replace('/[^\p{L}\p{N}]+/u','',$text)??$text; };
  $wantArtist=$norm($artist);$wantTitle=$norm($title);
  foreach(array_slice($results,0,20) as $entry){
    if(!is_array($entry)||!empty($entry['instrumental']))continue;
    if($norm((string)($entry['artistName']??''))!==$wantArtist)continue;
    if($norm((string)($entry['trackName']??($entry['name']??'')))!==$wantTitle)continue;
    $candidateSync=(string)($entry['syncedLyrics']??'');$candidatePlain=(string)($entry['plainLyrics']??'');
    if($candidateSync===''&&$candidatePlain==='')continue;
    // Preferir sincronización y proximidad de duración; nunca exigir igualdad exacta.
    $score=($candidateSync!==''?1000:0)-abs((int)($entry['duration']??$duration)-$duration);
    if(!isset($best)||$score>$best['score'])$best=['score'=>$score,'synced'=>$candidateSync,'plain'=>$candidatePlain];
  }
  if(isset($best)){$sync=$best['synced']?:null;$plain=$best['plain']?:null;$origin='lrclib';}
}
$result=['title'=>$title,'artist'=>$artist,'album'=>$album,'synced'=>$sync,'plain'=>$plain,'source'=>$origin];$json=json_encode($result,JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE);if(is_string($json)){@file_put_contents($file,$json,LOCK_EX);echo $json;}else echo '{}';
