<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require __DIR__.'/config.php';require_once __DIR__.'/dc_media_tools_lib_dcft.php';require_once __DIR__.'/dc_external_ai_lib_dcft.php';
$dir=dcmtPrivateDir();$lock=fopen($dir.'/worker.lock','c');if(!$lock||!flock($lock,LOCK_EX|LOCK_NB))exit;
$stateFile=$dir.'/state.json';$state=is_file($stateFile)?json_decode(file_get_contents($stateFile),true):[];$s=dcmtExternalSettings($pdo,true);
if(($state['state']??'')==='running'){$state['state']='error';$state['message']='La revisión anterior se interrumpió. No se repitió la solicitud de pago.';$state['finished_at']=time();dcmtPrivateJson($stateFile,$state);exit;}
$due=$s['weekly']&&$s['token']!==''&&$s['model']!==''&&time()-$s['last_attempt']>=7*86400;
if(($state['state']??'')!=='queued'&&!$due)exit;
$state=['state'=>'running','trigger'=>($state['state']??'')==='queued'?'manual':'weekly','started_at'=>time(),'phase'=>'Comprobando adaptador'];dcmtPrivateJson($stateFile,$state);dcmtSetting($pdo,'ai_last_attempt',(string)time());
try{
 if($s['token']===''||$s['model']==='')throw new RuntimeException('Configura token y modelo');$file=__DIR__.'/dc_youtube_adapter_dcft.php';$source=file_get_contents($file);$hash=hash('sha256',$source);$diagnostics=['contracts'=>'passed','live_search'=>'not_checked','coverage'=>['search','video_metadata','bounded_mp4','audio_only_selector'],'audio_download'=>'not_live_tested','audio_conversion'=>'protected_ffmpeg_pipeline'];$evidence=false;$liveFixture=null;
 try{dcmtValidateAdapter($source,$source);dcmtProbeAdapter($file);require_once $file;}catch(Throwable $e){$diagnostics['contracts']='failed';$evidence=true;}
 try{$r=dcmtRun([dcmtYtBin(),'--ignore-config','--flat-playlist','--dump-single-json','--skip-download','--no-warnings','--socket-timeout','8','--retries','0','--extractor-retries','0','--','ytsearch4:yt-dlp test video'],30);$raw=json_decode($r['stdout'],true);
  if($r['code']===0&&is_array($raw)){$diagnostics['live_search']='responded';$diagnostics['provider_count']=count($raw['entries']??[]);$diagnostics['normalized_count']=count(dcmtAdapterSearch($raw));if($diagnostics['provider_count']>0&&$diagnostics['normalized_count']===0){$evidence=true;$liveFixture=tempnam($dir,'fixture-');file_put_contents($liveFixture,json_encode($raw,JSON_THROW_ON_ERROR));chmod($liveFixture,0600);$first=$raw['entries'][0]??[];$diagnostics['provider_keys']=array_slice(array_keys($first),0,40);$diagnostics['sample']=array_intersect_key($first,array_flip(['id','title','channel','uploader','duration','video_id','video_title']));}}else $diagnostics['live_search']='provider_denial_or_network_failure';
 }catch(Throwable $ignored){$diagnostics['live_search']='provider_denial_or_network_failure';}
 // Recent audio failures are bounded diagnostics, never proof of a provider schema change by themselves.
 try{$audioErrors=$pdo->query("SELECT phase,message FROM dc_youtube_jobs_dcft WHERE media_kind='audio' AND state='error' ORDER BY updated_at DESC LIMIT 3")->fetchAll(PDO::FETCH_ASSOC);$diagnostics['recent_audio_failures']=$audioErrors;}catch(Throwable $ignored){$diagnostics['recent_audio_failures']='unavailable';}
 $diagnostics['repair_evidence']=$evidence;$version=dcmtRun([dcmtYtBin(),'--version'],10,4096);$diagnostics['yt_dlp_version']=trim($version['stdout']);
 $skill=file_get_contents(__DIR__.'/skills/drivecraft-external-maintenance/SKILL.md');$prompt=json_encode(['editable_files'=>['dc_youtube_adapter_dcft.php'=>$source],'diagnostics'=>$diagnostics,'instruction'=>'No change without demonstrated adapter evidence. Fixed transport, CLI invocations, quota and persistence are protected.'],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
 $state['phase']='Consultando OpenAI';$state['api_requested']=true;dcmtPrivateJson($stateFile,$state);
 $result=dcmtOpenAI('chat/completions',$s['token'],['model'=>$s['model'],'messages'=>[['role'=>'system','content'=>$skill],['role'=>'user','content'=>$prompt]],'response_format'=>['type'=>'json_object'],'max_completion_tokens'=>4500]);
 $state['usage']=$result['usage']??null;$state['model']=$s['model'];dcmtPrivateJson($stateFile,$state);
 if(($result['choices'][0]['finish_reason']??'')!=='stop')throw new RuntimeException('Respuesta de IA incompleta; no se aplicaron cambios');
 $proposal=json_decode($result['choices'][0]['message']['content']??'',true,32,JSON_THROW_ON_ERROR);if(!is_array($proposal)||!is_string($proposal['summary']??null)||!is_array($proposal['files']??null))throw new RuntimeException('La IA no respetó el contrato de mantenimiento');
 foreach($proposal['files'] as $name=>$code)if($name!=='dc_youtube_adapter_dcft.php'||!is_string($code))throw new RuntimeException('Propuesta fuera de la lista permitida');
 $state['message']=mb_substr($proposal['summary'],0,3000);$state['diagnostics']=$diagnostics;
 if($proposal['files']){
  $candidate=$proposal['files']['dc_youtube_adapter_dcft.php'];dcmtValidateAdapter($source,$candidate);$tmp=tempnam($dir,'proposal-');file_put_contents($tmp,$candidate,LOCK_EX);chmod($tmp,0600);try{dcmtProbeAdapter($tmp,$liveFixture);}finally{@unlink($tmp);}
  if($s['auto_apply']&&$evidence){$state['backup']=dcmtPublishAdapter($candidate,$hash);$state['phase']='Reparación aplicada automáticamente';}
  else{$state['phase']=$evidence?'Propuesta validada; aplicación automática desactivada':'Sin evidencia suficiente para aplicar una reparación';}
 }else $state['phase']='Revisión terminada; sin cambios';$state['state']='done';
}catch(Throwable $e){$state['state']='error';$state['message']=$e->getMessage();$state['phase']='Revisión no completada';}
if($liveFixture!==null)@unlink($liveFixture);$state['finished_at']=time();dcmtPrivateJson($stateFile,$state);$historyFile=$dir.'/history.json';$history=is_file($historyFile)?json_decode(file_get_contents($historyFile),true):[];if(!is_array($history))$history=[];array_unshift($history,$state);dcmtPrivateJson($historyFile,array_slice($history,0,20));
