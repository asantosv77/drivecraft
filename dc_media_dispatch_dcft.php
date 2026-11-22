<?php
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
// One pi-cron entry; each component owns its independent nonblocking lock.
foreach(['dc_youtube_worker_dcft.php','dc_external_worker_dcft.php'] as $worker)exec(escapeshellarg(PHP_BINDIR.'/php').' '.escapeshellarg(__DIR__.'/'.$worker).' >/dev/null 2>&1 &');
