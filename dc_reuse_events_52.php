<?php
function dc52Event(PDO $pdo,string $kind,bool $reused,int $fileId,int $ownerId,string $profile,?string $sha,int $bytes):void{
 if(!$sha || !preg_match('/^[a-f0-9]{64}$/D',$sha))return;
 try{$q=$pdo->prepare('INSERT INTO dc_reuse_events_52(kind,method,file_id,owner_id,profile,original_sha256,output_bytes) VALUES (?,?,?,?,?,?,?)');
 $q->execute([$kind,$reused?'reused':'generated',$fileId,$ownerId,$profile,$sha,max(0,$bytes)]);
 }catch(Throwable $e){error_log('DriveCraft dc52Event: '.$e->getMessage());}
}
