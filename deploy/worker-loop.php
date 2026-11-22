<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli')exit(1);
$base='/var/www/html/drive';$children=[];$lastCleanup=0;
$workers=['dc_youtube_worker_dcft.php','dc_local_video_worker.php','dc_media_worker.php','dc_external_worker_dcft.php'];
while(true){
 foreach($children as $name=>$process){$status=proc_get_status($process);if(!$status['running']){if($status['exitcode']!==0)fwrite(STDERR,'Worker exited with error: '.$name."\n");proc_close($process);unset($children[$name]);}}
 $due=$workers;if(time()-$lastCleanup>=3600){$due[]='dc_receive_cleanup.php';$lastCleanup=time();}
 foreach($due as $name){if(isset($children[$name]))continue;
  $process=proc_open([PHP_BINARY,$base.'/'.$name],[0=>['file','/dev/null','r'],1=>STDOUT,2=>STDERR],$pipes,$base);
  if(is_resource($process))$children[$name]=$process;
 }
 touch('/drive/.worker-heartbeat');sleep(10);
}
