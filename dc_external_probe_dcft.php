<?php
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require $argv[1];
function dcmtProbeCheck($v,string $message):void{if(!$v)throw new RuntimeException($message);}
$rows=[];for($i=0;$i<10;$i++)$rows[]=['id'=>'abcdefghij'.$i,'title'=>' Video '.$i.' ','uploader'=>'Canal','duration'=>42];
$result=dcmtAdapterSearch(['entries'=>$rows]);dcmtProbeCheck(count($result)===4,'four results');dcmtProbeCheck($result[0]['url']==='https://www.youtube.com/watch?v=abcdefghij0','canonical URL');
dcmtProbeCheck(dcmtAdapterSearch(['entries'=>[['id'=>'evil','title'=>'X']]])===[],'invalid ID');dcmtProbeCheck(dcmtAdapterSearch([])===[],'empty response');dcmtProbeCheck(count(dcmtAdapterSearch(['entries'=>[$rows[0],$rows[0]]]))===1,'dedupe');
$meta=dcmtAdapterInspect(['id'=>'abcdefghij0','title'=>'Video','is_live'=>true,'duration'=>99],'abcdefghij0');dcmtProbeCheck($meta['is_live']===true&&$meta['duration']==99,'live and duration preserved');dcmtProbeCheck(dcmtAdapterInspect(['id'=>'abcdefghij1','title'=>'X'],'abcdefghij0')===[],'identity preserved');
foreach([360,720,1080] as $height){$format=dcmtAdapterFormat($height);dcmtProbeCheck(strlen($format)<600&&!preg_match('/[\x00-\x20]/',$format)&&str_contains($format,(string)$height),'bounded format');dcmtProbeCheck(str_contains($format,'avc1')&&str_contains($format,'mp4'),'browser-compatible MP4');}
if(isset($argv[2])){$fixture=json_decode(file_get_contents($argv[2]),true);dcmtProbeCheck(count(dcmtAdapterSearch($fixture))>0,'live schema regression');}
$audio=dcmtAdapterAudioFormat();dcmtProbeCheck(strlen($audio)<600&&!preg_match('/[\x00-\x20]/',$audio)&&str_contains($audio,'bestaudio')&&!preg_match('~https?:|ytsearch|playlist|bestvideo|[+;]~i',$audio),'audio-only selector');

echo "Adapter contracts passed.\n";
