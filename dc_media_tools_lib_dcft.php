<?php
declare(strict_types=1);
require_once __DIR__.'/dc_external_settings_dcft.php';
// Shared, configuration-free helpers. Web requests and CLI workers use the same rules.
function dcmtYoutubeId(string $raw): ?string {
 $u=parse_url(trim($raw));if(!is_array($u)||strtolower($u['scheme']??'')!=='https'||isset($u['user'])||isset($u['pass'])||isset($u['port']))return null;
 $host=strtolower($u['host']??'');$path=$u['path']??'';$id='';
 if(in_array($host,['youtu.be','www.youtu.be'],true))$id=trim($path,'/');
 elseif(in_array($host,['youtube.com','www.youtube.com','m.youtube.com','music.youtube.com'],true)){
  if($path==='/watch'){parse_str($u['query']??'',$q);$id=is_string($q['v']??null)?$q['v']:'';}
  elseif(preg_match('~^/(?:shorts|live|embed)/([A-Za-z0-9_-]{11})/?$~D',$path,$m))$id=$m[1];
 }
 return preg_match('/^[A-Za-z0-9_-]{11}$/D',$id)?$id:null;
}
function dcmtOwned(PDO $db,int $uid,int $id,bool $folder=false): array {
 $seen=[];$first=null;while($id>0){if(isset($seen[$id])||count($seen)>=128)throw new RuntimeException('Jerarquía no disponible');$seen[$id]=true;
  $q=$db->prepare('SELECT * FROM files WHERE id=? AND owner_id=? AND deleted_at IS NULL');$q->execute([$id,$uid]);$f=$q->fetch(PDO::FETCH_ASSOC);
  if(!$f||($first===null&&$folder&&!(int)$f['is_dir'])||($first!==null&&!(int)$f['is_dir']))throw new RuntimeException('Archivo o carpeta no disponible');
  if($first===null)$first=$f;$id=(int)$f['parent_id'];
 }if(!$first)throw new RuntimeException('Archivo o carpeta no disponible');return $first;
}
function dcmtIsScope(PDO $db,int $uid,int $id):bool {
 require_once __DIR__.'/dc_special_modes_85m.php';$seen=[];while($id>0&&!isset($seen[$id])&&count($seen)<128){$seen[$id]=true;$f=dcmtOwned($db,$uid,$id,true);$m=dc85mModes($db,$uid,$id);if($m['album']||$m['music']||$m['youtube']||$m['video'])return true;$id=(int)$f['parent_id'];}return false;
}
function dcmtYtBin():string {foreach(['/usr/local/bin/yt-dlp','/usr/bin/yt-dlp','/opt/yt-dlp/yt-dlp_linux_aarch64'] as $p)if(is_executable($p))return $p;throw new RuntimeException('Falta yt-dlp en el servidor');}
function dcmtRun(array $args,int $seconds,int $maxBytes=2097152,?callable $tick=null):array {
 // GNU timeout owns the child process group, including any ffmpeg subprocess.
 $args=array_merge(['/usr/bin/timeout','--signal=TERM','--kill-after=5s',(string)$seconds.'s'],$args);
 $process=proc_open($args,[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,null,null,['bypass_shell'=>true]);if(!is_resource($process))throw new RuntimeException('No se pudo iniciar el proceso');
 fclose($pipes[0]);stream_set_blocking($pipes[1],false);stream_set_blocking($pipes[2],false);$out='';$err='';$start=microtime(true);$code=-1;$problem=null;
 try{do{
  $a=stream_get_contents($pipes[1]);$b=stream_get_contents($pipes[2]);$out.=$a;$err.=$b;
  if(strlen($out)+strlen($err)>$maxBytes)throw new RuntimeException('Respuesta demasiado grande');
  if($tick)$tick($a,$b,microtime(true)-$start);
  $status=proc_get_status($process);if(!$status['running']){$code=(int)$status['exitcode'];break;}
  if(microtime(true)-$start>$seconds)throw new RuntimeException('Se agotó el tiempo de espera');usleep(150000);
 }while(true);}catch(Throwable $e){$problem=$e;proc_terminate($process,15);usleep(200000);$s=proc_get_status($process);if($s['running'])proc_terminate($process,9);}
 finally{$out.=stream_get_contents($pipes[1]);$err.=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$closed=proc_close($process);if($code<0)$code=$closed;}
 if($problem)throw $problem;return ['code'=>$code,'stdout'=>$out,'stderr'=>$err];
}
function dcmtFailure(string $raw):string {
 if(preg_match('/private video|video unavailable|removed|not available|copyright|members.only/i',$raw))return 'YouTube indica que el video es privado, fue retirado o no está disponible para esta conexión.';
 if(preg_match('/sign in|bot|429|confirm your age|PO Token/i',$raw))return 'YouTube está solicitando verificación o limitando las consultas. Puedes reintentar más tarde; si persiste, revisa la versión de yt-dlp.';
 if(preg_match('/format.*not available|no video formats/i',$raw))return 'No hay un formato de video compatible disponible con la calidad seleccionada.';
 return 'No se pudo completar la consulta o descarga. Revisa la conexión y la versión de yt-dlp si el problema persiste.';
}
function dcmtInspect(string $id):array {
 require_once __DIR__.'/dc_youtube_adapter_dcft.php';
 $r=dcmtRun([dcmtYtBin(),'--ignore-config','--no-playlist','--skip-download','--dump-single-json','--no-warnings','--socket-timeout','8','--retries','0','--extractor-retries','0','--','https://www.youtube.com/watch?v='.$id],35);
 if($r['code']!==0)throw new RuntimeException(dcmtFailure($r['stderr']));$d=json_decode($r['stdout'],true);$meta=is_array($d)?dcmtAdapterInspect($d,$id):[];if(!$meta||($meta['id']??'')!==$id)throw new RuntimeException('YouTube devolvió una respuesta incompleta');return $meta;
}
function dcmtLimit(PDO $db,array $u):int {
 $q=$db->prepare("SELECT setting_value FROM dc_app_settings WHERE setting_key='dc_media_max_mib'");$q->execute();$limit=max(1,min(2048,(int)($q->fetchColumn()?:500)))*1048576;
 if(($u['role']??'')!=='admin'&&(int)($u['max_file_bytes']??0)>0)$limit=min($limit,(int)$u['max_file_bytes']);
 if(($u['role']??'')!=='admin'&&(int)($u['quota_bytes']??0)>0){$q=$db->prepare('SELECT COALESCE(SUM(size),0) FROM files WHERE owner_id=? AND is_dir=0');$q->execute([$u['id']]);$used=(int)$q->fetchColumn();$q=$db->prepare('SELECT COALESCE(SUM(v.size),0) FROM file_versions v JOIN files f ON f.id=v.file_id WHERE f.owner_id=?');$q->execute([$u['id']]);$limit=min($limit,max(0,(int)$u['quota_bytes']-$used-(int)$q->fetchColumn()));}return $limit;
}
function dcmtStartWorker():void {
 $php=PHP_BINDIR.'/php';if(is_executable($php)&&function_exists('exec'))exec(escapeshellarg($php).' '.escapeshellarg(__DIR__.'/dc_youtube_worker_dcft.php').' >/dev/null 2>&1 &');
}
