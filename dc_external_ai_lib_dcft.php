<?php
require_once __DIR__.'/dc_external_settings_dcft.php';
require_once __DIR__.'/dc_external_validation_dcft.php';
function dcmtOpenAI(string $path,string $token,?array $body=null):array {
 if(!in_array($path,['models','chat/completions'],true))throw new RuntimeException('Ruta de IA inválida');
 $ch=curl_init('https://api.openai.com/v1/'.$path);$response='';
 curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>false,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_CONNECTTIMEOUT=>10,CURLOPT_TIMEOUT=>120,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_HTTPHEADER=>['Authorization: Bearer '.$token,'Content-Type: application/json'],CURLOPT_WRITEFUNCTION=>function($ch,$bytes)use(&$response){if(strlen($response)+strlen($bytes)>524288)return 0;$response.=$bytes;return strlen($bytes);}]);
 if($body!==null){curl_setopt($ch,CURLOPT_POST,true);curl_setopt($ch,CURLOPT_POSTFIELDS,json_encode($body,JSON_THROW_ON_ERROR));}
 $ok=curl_exec($ch);$status=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE);curl_close($ch);
 if($ok===false)throw new RuntimeException('No se pudo confirmar la respuesta de OpenAI. No se repetirá automáticamente esta solicitud.');
 if($status<200||$status>=300)throw new RuntimeException('OpenAI respondió HTTP '.$status.'. Revisa el token, modelo, saldo y límites.');
 $data=json_decode($response,true,64,JSON_THROW_ON_ERROR);if(!is_array($data))throw new RuntimeException('Respuesta de OpenAI inválida');return $data;
}
function dcmtProbeAdapter(string $path,?string $fixture=null):void {
 $args=[PHP_BINDIR.'/php','-d','memory_limit=64M',__DIR__.'/dc_external_probe_dcft.php',$path];if($fixture!==null)$args[]=$fixture;$r=dcmtRun($args,8,32768);if($r['code']!==0)throw new RuntimeException('El adaptador no pasó las pruebas de contrato');
}
function dcmtPublishAdapter(string $source,string $expected):string {
 $file=__DIR__.'/dc_youtube_adapter_dcft.php';$current=file_get_contents($file);if(!hash_equals($expected,hash('sha256',$current)))throw new RuntimeException('El adaptador cambió durante la revisión; no se sobrescribió');
 dcmtValidateAdapter($current,$source);$candidate=tempnam(dcmtPrivateDir(),'candidate-');file_put_contents($candidate,$source,LOCK_EX);chmod($candidate,0600);
 try{dcmtProbeAdapter($candidate);$versions=dcmtPrivateDir().'/versions';if(!is_dir($versions))mkdir($versions,0700);$version=gmdate('Ymd-His').'-'.bin2hex(random_bytes(4));
  if(file_put_contents($versions.'/'.$version.'.php',$current,LOCK_EX)!==strlen($current))throw new RuntimeException('No se pudo crear respaldo');chmod($versions.'/'.$version.'.php',0600);
  // Compare again after validation and before atomic publication.
  if(!hash_equals($expected,hash_file('sha256',$file)))throw new RuntimeException('Cambio concurrente del adaptador');
  $tmp=tempnam(__DIR__,'.adapter-');if(!$tmp||file_put_contents($tmp,$source,LOCK_EX)!==strlen($source))throw new RuntimeException('No se pudo publicar');chmod($tmp,fileperms($file)&0777);if(!rename($tmp,$file))throw new RuntimeException('No se pudo publicar');if(function_exists('opcache_invalidate'))opcache_invalidate($file,true);return $version;
 }finally{@unlink($candidate);}
}
