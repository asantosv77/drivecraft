<?php
/* Drive-Craft 85: estadísticas protegidas, solo administrador. */
require __DIR__.'/config.php';require_once __DIR__.'/auth.php';
header('Content-Type: application/json; charset=utf-8');header('Cache-Control: private, no-store');
$user=dcRequireUser();if(($user['role']??'')!=='admin'){http_response_code(403);echo json_encode(['error'=>'Solo administrador']);exit;}
function dc85read($path){return is_readable($path)?@file_get_contents($path):false;}
function dc85stat(){
 $txt=dc85read('/proc/stat');$cpu=null;
 if($txt!==false && preg_match('/^cpu\s+([0-9 ]+)/m',$txt,$m)){$parts=array_map('intval',preg_split('/\s+/',trim($m[1])));$cpu=['total'=>array_sum($parts),'idle'=>($parts[3]??0)+($parts[4]??0)];}
 $txt=dc85read('/proc/meminfo');$mem=[];
 if($txt!==false && preg_match_all('/^([A-Za-z_()]+):\s+(\d+)/m',$txt,$matches,PREG_SET_ORDER))foreach($matches as $m)$mem[$m[1]]=(int)$m[2];
 $load=function_exists('sys_getloadavg')?sys_getloadavg():[0,0,0];
 return ['cpu'=>$cpu,'ram_total_kib'=>$mem['MemTotal']??0,'ram_available_kib'=>$mem['MemAvailable']??0,'swap_total_kib'=>$mem['SwapTotal']??0,'swap_free_kib'=>$mem['SwapFree']??0,'load'=>$load,'timestamp'=>time()];
}
if(($_GET['action']??'')==='live'){echo json_encode(['success'=>true,'sample'=>dc85stat()]);exit;}
if(($_GET['action']??'')!=='history'){http_response_code(400);echo json_encode(['error'=>'Acción inválida']);exit;}
// Aprovecha únicamente las estadísticas sar ya volcadas por la tarea existente del sitio Fredy.
// Si Fredy no se comparte en el contenedor, el historial estará vacío, nunca se fabricarán datos.
$day=(string)($_GET['day']??date('d'));
if(!preg_match('/^(0[1-9]|[12][0-9]|3[01])$/D',$day)){$day=date('d');}
$base=__DIR__.'/../fredy/logs/sa'.$day;
function dc85rows($path,$kind){$rows=[];$txt=dc85read($path);if($txt===false)return $rows;
 foreach(explode("\n",$txt) as $line){$cols=preg_split('/\s+/',trim($line));if(count($cols)<4)continue;
  $idx=array_search('all',$cols,true);if($kind==='cpu' && $idx!==false){$nums=array_slice($cols,$idx+1);$last=end($nums);if(is_numeric($last) && preg_match('/^\d{1,2}:\d{2}/',$cols[0]))$rows[]=['time'=>$cols[0],'value'=>max(0,min(100,100-(float)$last))];}
  if($kind==='mem' && $idx===false && preg_match('/^\d{1,2}:\d{2}/',$cols[0])){
   // sar -r estándar: kbmemfree kbavail kbmemused %memused ...; se evita inferir columnas sin cabecera.
   if(preg_match('/%memused/i',$txt,$head) && isset($cols[4]) && is_numeric($cols[4]))$rows[]=['time'=>$cols[0],'value'=>max(0,min(100,(float)$cols[4]))];
  }
 }
 return array_slice($rows,-150);
}
$cpu=dc85rows($base.'.txt','cpu');$memory=dc85rows($base.'_mem.txt','mem');
echo json_encode(['success'=>true,'day'=>$day,'cpu'=>$cpu,'memory'=>$memory,'historical_source'=>'Fredy / sysstat (archivo sar exportado)','available'=>count($cpu)>0||count($memory)>0]);
