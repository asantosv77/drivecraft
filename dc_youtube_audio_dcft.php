<?php
declare(strict_types=1);
/* Audio-only provider workflow. Authentication, queue and limits remain in protected modules. */
function dcmtAudioFolder(PDO $db,int $uid,int $folder):bool {
 $f=dcmtOwned($db,$uid,$folder,true);$seen=[];
 while((int)$f['id']>0&&count($seen)<128){
  $id=(int)$f['id'];if(isset($seen[$id]))return false;$seen[$id]=true;
  $q=$db->prepare('SELECT 1 FROM dc_music_libraries_81 WHERE owner_id=? AND folder_id=?');$q->execute([$uid,$id]);if($q->fetchColumn())return true;
  $parent=(int)$f['parent_id'];if($parent<1)return false;$f=dcmtOwned($db,$uid,$parent,true);
 }return false;
}
function dcmtAudioChoices(string $format,int $bitrate):array {
 if(!in_array($bitrate,[64,96,128,160,192,256],true))throw new RuntimeException('Calidad de audio inválida');
 $choices=['opus'=>['ext'=>'opus','mime'=>'audio/ogg','encoder'=>'libopus','codec'=>'opus'],'mp3'=>['ext'=>'mp3','mime'=>'audio/mpeg','encoder'=>'libmp3lame','codec'=>'mp3'],'m4a'=>['ext'=>'m4a','mime'=>'audio/mp4','encoder'=>'aac','codec'=>'aac']];
 if(!isset($choices[$format]))throw new RuntimeException('Formato de audio inválido');return $choices[$format];
}
function dcmtProcessYoutubeAudio(PDO $db,array $job,array $user,array $meta,string $work,string $root,int $limit,array &$published,string $storage):void {
 $id=$job['id'];$uid=(int)$job['owner_id'];$folder=(int)$job['folder_id'];$bitrate=(int)$job['audio_bitrate'];$format=(string)$job['audio_format'];$choice=dcmtAudioChoices($format,$bitrate);
 if(!dcmtAudioFolder($db,$uid,$folder))throw new RuntimeException('La carpeta ya no pertenece a una biblioteca musical');
 $title=mb_substr(trim((string)($meta['title']??'')),0,240)?:'Audio YouTube';$url='https://www.youtube.com/watch?v='.$job['youtube_id'];
 $selector=dcmtAdapterAudioFormat();if(strlen($selector)>600||preg_match('/[\x00-\x20]/',$selector))throw new RuntimeException('Selector de audio inválido');
 dcmtUpdate($db,$id,'Descargando audio',5,null,$title);$buffer='';$last=0.0;$progress=5.0;
 $args=[dcmtYtBin(),'--ignore-config','--no-playlist','--no-warnings','--continue','--newline','--progress','--progress-template','download:DC_PROGRESS %(progress._percent_str)s','--socket-timeout','15','--retries','2','--fragment-retries','2','--extractor-retries','1','--concurrent-fragments','1','--max-filesize',(string)$limit,'--match-filter','!is_live & duration <= 7200','-f',$selector,'-o',$work.'/audio-source.%(ext)s','--',$url];
 $result=dcmtRun($args,1800,4194304,function(string $out,string $err,float $elapsed)use($db,$id,$work,$limit,&$buffer,&$last,&$progress){
  $buffer=substr($buffer.$out,-8192);if($elapsed-$last<1)return;$last=$elapsed;$size=0;foreach(new DirectoryIterator($work) as $f)if(!$f->isDot()&&$f->isFile())$size+=$f->getSize();if($size>$limit*2+1048576)throw new RuntimeException('La descarga supera el límite temporal');
  $percent=null;if(preg_match_all('/DC_PROGRESS\s+([\d.]+)%/',$buffer,$m)&&$m[1]){$percent=max(0,min(100,(float)end($m[1])));$progress=max($progress,5+$percent*.65);}dcmtUpdate($db,$id,'Descargando audio',$progress,$percent);
 });
 if($result['code']!==0)throw new RuntimeException(dcmtFailure($result['stderr']));
 $sources=[];foreach(new DirectoryIterator($work) as $f)if($f->isFile()&&!$f->isLink()&&preg_match('/^audio-source\.(webm|m4a|mp4|ogg|opus|mp3|aac)$/D',$f->getFilename()))$sources[]=$f->getPathname();
 if(count($sources)!==1)throw new RuntimeException('No se obtuvo una fuente de audio completa');$source=$sources[0];
 $probe=dcmtRun(['/usr/bin/ffprobe','-v','error','-show_entries','stream=codec_type,bit_rate:format=duration','-of','json',$source],20);$info=json_decode($probe['stdout'],true);$duration=(float)($info['format']['duration']??0);$audio=false;$sourceRate=0;
 foreach($info['streams']??[] as $stream)if(($stream['codec_type']??'')==='audio'){$audio=true;$sourceRate=(int)($stream['bit_rate']??0);}
 if($probe['code']!==0||!$audio||$duration<=0||$duration>7200)throw new RuntimeException('No se pudo validar la fuente de audio');
 // Requested bitrate is a ceiling, not a promise of improved source quality.
 $effective=$sourceRate>0?max(32,min($bitrate,(int)ceil($sourceRate/1000))):$bitrate;
 $ready=$work.'/ready.'.$choice['ext'];dcmtUpdate($db,$id,'Convirtiendo audio',72,100,$title);$last=0.0;$buffer='';
 $args=['/usr/bin/ffmpeg','-nostdin','-hide_banner','-loglevel','error','-y','-threads','1','-i',$source,'-map','0:a:0','-vn','-sn','-dn','-map_metadata','-1','-metadata','title='.$title,'-metadata','comment='.$url,'-c:a',$choice['encoder'],'-b:a',$effective.'k','-threads','1'];
 if($format==='opus')array_push($args,'-vbr','on');if($format==='m4a')array_push($args,'-movflags','+faststart');array_push($args,'-progress','pipe:1','-nostats',$ready);
 $result=dcmtRun($args,1200,2097152,function(string $out,string $err,float $elapsed)use($db,$id,$duration,$work,$limit,&$last,&$buffer){
  $buffer=substr($buffer.$out,-4096);if($elapsed-$last<1)return;$last=$elapsed;
  $size=0;foreach(new DirectoryIterator($work) as $f)if(!$f->isDot()&&$f->isFile())$size+=$f->getSize();if($size>$limit*2+1048576)throw new RuntimeException('La conversión supera el límite temporal');
  $percent=72.0;if(preg_match_all('/out_time_us=(\d+)/',$buffer,$m)&&$m[1])$percent=min(94,72+22*(float)end($m[1])/1000000/$duration);dcmtUpdate($db,$id,'Convirtiendo audio',$percent,100);
 });
 if($result['code']!==0||!is_file($ready)||is_link($ready)||filesize($ready)<100)throw new RuntimeException('No se pudo convertir el audio al formato solicitado');
 $probe=dcmtRun(['/usr/bin/ffprobe','-v','error','-show_entries','stream=codec_type,codec_name:format=duration','-of','json',$ready],20);$output=json_decode($probe['stdout'],true);$valid=false;
 foreach($output['streams']??[] as $stream){if(($stream['codec_type']??'')==='video')throw new RuntimeException('La salida de audio contiene video');if(($stream['codec_type']??'')==='audio'&&($stream['codec_name']??'')===$choice['codec'])$valid=true;}
 if($probe['code']!==0||!$valid||abs((float)($output['format']['duration']??0)-$duration)>max(2,$duration*.01))throw new RuntimeException('El audio convertido no superó la validación');
 $size=filesize($ready);if($size>$limit)throw new RuntimeException('El audio supera la cuota o el límite por archivo');
 dcmtUpdate($db,$id,'Integrando audio a Drivecraft',96,100);$stored='dc-yt-audio-'.$id.'.'.$choice['ext'];$dest=rtrim($storage,'/').'/'.$stored;
 if(is_link($dest))throw new RuntimeException('Destino de audio inválido');$published[]=$dest;if(!copy($ready,$dest))throw new RuntimeException('No se pudo publicar el audio');
 $db->beginTransaction();$q=$db->prepare("SELECT * FROM dc_users WHERE id=? AND status='active' FOR UPDATE");$q->execute([$uid]);$fresh=$q->fetch(PDO::FETCH_ASSOC);
 if(!$fresh||(($fresh['role']??'')!=='admin'&&empty($fresh['can_upload'])))throw new RuntimeException('El permiso de importación fue revocado');
 if(!dcmtAudioFolder($db,$uid,$folder))throw new RuntimeException('La carpeta ya no pertenece a biblioteca musical');
 if($size>dcmtLimit($db,$fresh))throw new RuntimeException('No queda espacio suficiente en la cuota');
 $q=$db->prepare('SELECT file_id FROM dc_youtube_jobs_dcft WHERE id=? FOR UPDATE');$q->execute([$id]);if($q->fetchColumn())throw new RuntimeException('La tarea ya fue publicada');
 $base=mb_substr(preg_replace('~[\x00-\x1f/\\\\]~u','_',$title),0,170);$name=$base.'.'.$choice['ext'];$n=1;$q=$db->prepare('SELECT COUNT(*) FROM files WHERE parent_id=? AND owner_id=? AND name=? AND deleted_at IS NULL');
 do{$q->execute([$folder,$uid,$name]);if(!(int)$q->fetchColumn())break;$name=$base.' ('.(++$n).').'.$choice['ext'];}while($n<1000);
 $db->prepare('INSERT INTO files(parent_id,name,is_dir,mime_type,size,storage_name,has_thumb,has_list,owner_id,content_hash) VALUES(?,?,0,?,?,?,0,0,?,?)')->execute([$folder,$name,$choice['mime'],$size,$stored,$uid,hash_file('sha256',$dest)]);$file=(int)$db->lastInsertId();
 $db->prepare('INSERT INTO dc_audio_sources_dcft(file_id,owner_id,source_url,format,bitrate_kbps) VALUES(?,?,?,?,?)')->execute([$file,$uid,$url,$format,$effective]);
 $db->prepare("UPDATE dc_youtube_jobs_dcft SET state='done',phase='Audio incorporado',progress=100,download_percent=100,file_id=?,package_id=NULL,message='Audio guardado en la biblioteca musical.',updated_at=NOW() WHERE id=?")->execute([$file,$id]);$db->commit();$published=[];dcmtWorkClean($root,$id);
}
