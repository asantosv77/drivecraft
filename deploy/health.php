<?php
if(PHP_SAPI!=='cli')exit(1);
try{if(!is_file('/drive/.worker-heartbeat')||time()-filemtime('/drive/.worker-heartbeat')>45)exit(1);
 $context=stream_context_create(['http'=>['timeout'=>5,'ignore_errors'=>true]]);$body=@file_get_contents('http://127.0.0.1:8080/drive/',false,$context);
 if($body===false||!str_contains($http_response_header[0]??'','200'))exit(1);exit(0);
}catch(Throwable $e){exit(1);}
