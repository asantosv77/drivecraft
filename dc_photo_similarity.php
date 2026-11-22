<?php
// Comparación conservadora: pHash DCT + dHash + tonalidad RGB + relación de aspecto.
function dcPhotoFingerprint(string $path): ?array {
    if (!extension_loaded('gd') || !is_file($path) || @filesize($path)>8*1048576) return null;
    $info=@getimagesize($path);
    if (!$info || $info[0]<16 || $info[1]<16 || $info[0]*$info[1]>8000000) return null;
    $raw=@file_get_contents($path);if($raw===false)return null;
    $im=@imagecreatefromstring($raw);unset($raw);if(!$im)return null;
    $res=imagecreatetruecolor(32,32);
    if(!imagecopyresampled($res,$im,0,0,0,0,32,32,imagesx($im),imagesy($im))){imagedestroy($res);imagedestroy($im);return null;}
    $gray=[];$rgb=[0,0,0];
    for($y=0;$y<32;$y++){ $line=[];for($x=0;$x<32;$x++){
        $c=imagecolorat($res,$x,$y);$r=($c>>16)&255;$g=($c>>8)&255;$b=$c&255;
        $line[]=0.299*$r+0.587*$g+0.114*$b;$rgb[0]+=$r;$rgb[1]+=$g;$rgb[2]+=$b;
    }$gray[]=$line;}
    imagedestroy($res);imagedestroy($im);
    $dct=[];
    for($v=0;$v<8;$v++)for($u=0;$u<8;$u++){
      $sum=0.0;for($y=0;$y<32;$y++)for($x=0;$x<32;$x++)$sum+=$gray[$y][$x]*cos((2*$x+1)*$u*M_PI/64)*cos((2*$y+1)*$v*M_PI/64);
      $dct[]=$sum;
    }
    $sorted=array_slice($dct,1);sort($sorted,SORT_NUMERIC);$median=($sorted[30]+$sorted[31])/2;
    $bits='';foreach($dct as $z)$bits.=($z>$median?'1':'0');
    $hash='';for($i=0;$i<64;$i+=4)$hash.=dechex(bindec(substr($bits,$i,4)));
    $dh='';for($y=0;$y<8;$y++)for($x=0;$x<8;$x++)$dh.=($gray[$y*4][$x*4]>$gray[$y*4][$x*4+4]?'1':'0');
    $dhash='';for($i=0;$i<64;$i+=4)$dhash.=dechex(bindec(substr($dh,$i,4)));
    return ['phash'=>$hash,'dhash'=>$dhash,'rgb'=>array_map(fn($v)=>(int)round($v/1024),$rgb),'ratio'=>$info[0]/$info[1]];
}
function dcPhotoHamming(string $a,string $b): int {
    if(!preg_match('/^[a-f0-9]{16}$/D',$a)||!preg_match('/^[a-f0-9]{16}$/D',$b))return 65;
    $d=0;for($i=0;$i<16;$i++)$d+=substr_count(decbin(hexdec($a[$i])^hexdec($b[$i])),'1');return $d;
}
function dcPhotoHighConfidence(array $a,array $b): bool {
    if(abs(log($a['ratio']/$b['ratio']))>0.10)return false;
    $ph=dcPhotoHamming($a['phash'],$b['phash']);$dh=dcPhotoHamming($a['dhash'],$b['dhash']);
    $color=sqrt(pow($a['rgb'][0]-$b['rgb'][0],2)+pow($a['rgb'][1]-$b['rgb'][1],2)+pow($a['rgb'][2]-$b['rgb'][2],2));
    return $ph<=6 && $dh<=8 && $color<=24;
}
