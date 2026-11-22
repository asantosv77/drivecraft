<?php
// Pure provider adapter. No I/O, credentials, persistence or process execution here.
function dcmtAdapterSearch(array $data):array {
 $items=[];$seen=[];
 foreach($data['entries']??[] as $e){if(!is_array($e))continue;$id=$e['id']??'';$title=$e['title']??'';
  if(!is_string($id)||!is_string($title)||!preg_match('/^[A-Za-z0-9_-]{11}$/D',$id)||trim($title)===''||isset($seen[$id]))continue;$seen[$id]=true;
  $items[]=['id'=>$id,'title'=>mb_substr(trim($title),0,300),'channel'=>mb_substr((string)($e['channel']??$e['uploader']??''),0,200),'duration'=>max(0,(int)($e['duration']??0)),'url'=>'https://www.youtube.com/watch?v='.$id];if(count($items)>=4)break;
 }return $items;
}
function dcmtAdapterInspect(array $data,string $id):array {
 if(($data['id']??'')!==$id||!is_string($data['title']??null)||trim($data['title'])==='')return [];
 return ['id'=>$id,'title'=>mb_substr(trim($data['title']),0,240),'description'=>mb_substr((string)($data['description']??''),0,4000),'duration'=>max(0,(float)($data['duration']??0)),'is_live'=>!empty($data['is_live']),'live_status'=>(string)($data['live_status']??'')];
}
function dcmtAdapterFormat(int $height):string {
 return 'bv[ext=mp4][vcodec^=avc1][height<='.$height.']+ba[ext=m4a]/b[ext=mp4][vcodec^=avc1][height<='.$height.']';
}

function dcmtAdapterAudioFormat():string {
 return 'bestaudio';
}
