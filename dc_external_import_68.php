<?php
/** Drive-Craft v20.10.68: importación externa restringida (invocado desde dc_storage_actions.php). */
function dc68PublicIp(string $ip): bool {
    return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)!==false;
}
function dc68UrlParts(string $url): array {
    if(strlen($url)<9 || strlen($url)>2048 || preg_match('/[\x00-\x20\x7f]/', $url)) dcsaFail(422,'La URL contiene caracteres inválidos');
    $u=parse_url($url);
    if(!is_array($u) || strtolower((string)($u['scheme']??''))!=='https' || empty($u['host']) || isset($u['user']) || isset($u['pass']) || isset($u['fragment'])) dcsaFail(422,'Utiliza una URL HTTPS pública y directa al archivo');
    if(isset($u['port']) && (int)$u['port']!==443) dcsaFail(422,'Por seguridad solo se permiten enlaces HTTPS en el puerto 443');
    $host=strtolower(rtrim((string)$u['host'],'.'));
    if(!preg_match('/^(?=.{1,253}$)[a-z0-9](?:[a-z0-9.-]*[a-z0-9])?$/D',$host) || strpos($host,'.')===false || filter_var($host,FILTER_VALIDATE_IP)) dcsaFail(422,'Dominio de descarga no válido');
    return [$u,$host];
}
function dc68Resolve(string $host): array {
    $records=@dns_get_record($host,DNS_A|DNS_AAAA);
    if(!$records)dcsaFail(422,'No se puede resolver el dominio');
    $ips=[];
    foreach($records as $record){$ip=$record['ip']??$record['ipv6']??null;if(!$ip || !dc68PublicIp($ip))dcsaFail(403,'El enlace apunta a una dirección privada o reservada');$ips[]=$ip;}
    return array_values(array_unique($ips));
}
function dc68ImportExternal(PDO $pdo, array $user, int $uid, bool $admin, int $parent, string $url, string $storageDir,string $mode=''): never {
    if(!extension_loaded('curl'))dcsaFail(503,'El servidor necesita la extensión PHP cURL para importar archivos externos');
    $maxFile=(int)($user['max_file_bytes']??0);
    $limit=100*1024*1024; // techo de seguridad por solicitud: 100 MiB
    if(!$admin && $maxFile>0)$limit=min($limit,$maxFile);
    $quota=(int)($user['quota_bytes']??0);
    if(!$admin && $quota>0){
        $st=$pdo->prepare('SELECT COALESCE(SUM(size),0) FROM files WHERE owner_id=? AND is_dir=0');$st->execute([$uid]);$used=(float)$st->fetchColumn();
        $st=$pdo->prepare('SELECT COALESCE(SUM(v.size),0) FROM file_versions v JOIN files f ON f.id=v.file_id WHERE f.owner_id=?');$st->execute([$uid]);$used+=(float)$st->fetchColumn();
        $limit=min($limit,max(0,(int)floor($quota-$used)));
    }
    if($limit<1)dcsaFail(413,'Cuota o tamaño máximo insuficiente');
    $temp=@tempnam($storageDir,'dc-url68-');if(!$temp)dcsaFail(507,'No se pudo crear archivo temporal en el almacenamiento');
    $dest=null;
    try{
        $current=$url;$resultMime='';$total=0;$finalPath='';
        for($redirects=0;$redirects<4;$redirects++){
            [$parts,$host]=dc68UrlParts($current);$ips=dc68Resolve($host);
            $out=@fopen($temp,'wb');if(!$out)dcsaFail(507,'No se pudo escribir la descarga');
            $count=0;$contentType='';$location='';$code=0;$tooLarge=false;
            $ch=curl_init($current);
            curl_setopt_array($ch,[
                CURLOPT_RETURNTRANSFER=>false,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_PROXY=>'',CURLOPT_NOPROXY=>'*',CURLOPT_CONNECTTIMEOUT=>7,CURLOPT_TIMEOUT=>45,
                CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_REDIR_PROTOCOLS=>CURLPROTO_HTTPS,
                CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_USERAGENT=>'DriveCraftFileImporter/1.0',
                CURLOPT_HTTPHEADER=>['Accept: application/octet-stream,image/*,video/*,audio/*,application/pdf,*/*;q=0.5'],
                CURLOPT_RESOLVE=>[$host.':443:'.(str_contains($ips[0],':')?'['.$ips[0].']':$ips[0])],
                CURLOPT_HEADERFUNCTION=>function($ch,$line)use(&$contentType,&$location,&$code,$limit,&$tooLarge){
                    if(preg_match('/^HTTP\/\S+\s+(\d+)/i',$line,$m)){$code=(int)$m[1];$contentType='';$location='';}
                    if(stripos($line,'Content-Type:')===0)$contentType=trim(substr($line,13));
                    if(stripos($line,'Location:')===0)$location=trim(substr($line,9));
                    if(stripos($line,'Content-Length:')===0 && (float)trim(substr($line,15))>$limit){$tooLarge=true;return 0;}
                    return strlen($line);
                },
                CURLOPT_WRITEFUNCTION=>function($ch,$bytes)use($out,&$count,$limit,&$tooLarge){
                    $n=strlen($bytes);if($count+$n>$limit){$tooLarge=true;return 0;}if(fwrite($out,$bytes)!==$n)return 0;$count+=$n;return $n;
                },
            ]);
            $ok=curl_exec($ch);$err=curl_error($ch);$status=curl_getinfo($ch,CURLINFO_RESPONSE_CODE);curl_close($ch);fclose($out);
            if($tooLarge)dcsaFail(413,'El archivo supera el límite de descarga o tu cuota');
            if(!$ok)dcsaFail(502,'No se pudo descargar el archivo: '.substr($err,0,120));
            if($status>=300 && $status<400){
                if(!$location)dcsaFail(422,'Redirección sin destino');
                // Solo ubicaciones absolutas HTTPS o rutas relativas de la misma URL.
                if(str_starts_with($location,'/'))$current='https://'.$host.$location;
                else $current=$location;
                continue;
            }
            if($status!==200)dcsaFail(422,'El sitio respondió HTTP '.$status.' en vez del archivo');
            if($count===0)dcsaFail(422,'El enlace no contiene un archivo');
            $mime=strtolower(trim(explode(';',$contentType)[0]));
            $detected=(new finfo(FILEINFO_MIME_TYPE))->file($temp) ?: 'application/octet-stream';
            if($mime==='text/html' || $mime==='application/xhtml+xml' || $detected==='text/html')dcsaFail(422,'La dirección apunta a una página HTML, no a un archivo directo');
            $resultMime=$detected;$total=$count;$finalPath=(string)($parts['path']??'');break;
        }
        if(!$total)dcsaFail(422,'Demasiadas redirecciones');
        $name=rawurldecode(basename($finalPath));
        $name=str_replace(['/',chr(92)],'_',$name);
        $name=preg_replace('/[\x00-\x1f\x7f]/u','_',$name) ?: 'archivo';
        if($name==='.'||$name==='..'||$name==='')$name='archivo';
        if(strlen($name)>230)$name=substr($name,-230);
        if(!str_contains($name,'.')){
            $extensions=['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp','application/pdf'=>'pdf','text/plain'=>'txt','video/mp4'=>'mp4'];
            if(isset($extensions[$resultMime]))$name.='.'.$extensions[$resultMime];
        }
        [$old,$versioned]=dc80Existing($pdo,$uid,$parent,$name,$mode);
        // La cuota inicial es conservadora; permitir reemplazo sin historial al descontar el anterior.
        if(!$admin&&$quota>0&&$old&&!$versioned&&$mode==='replace'){$st=$pdo->prepare('SELECT COALESCE(SUM(size),0) FROM files WHERE owner_id=?');$st->execute([$uid]);$used=(float)$st->fetchColumn();$st=$pdo->prepare('SELECT COALESCE(SUM(v.size),0) FROM file_versions v JOIN files f ON f.id=v.file_id WHERE f.owner_id=?');$st->execute([$uid]);$used+=(float)$st->fetchColumn();if($used-(float)$old['size']+$total>$quota)dcsaFail(413,'Cuota insuficiente');}
        $hash=hash_file('sha256',$temp);$stored=bin2hex(random_bytes(20)).'_url';$dest=rtrim($storageDir,'/').'/'.$stored;
        if(!@rename($temp,$dest))dcsaFail(507,'No se pudo finalizar la descarga');
        $pdo->beginTransaction();
        try{
            [$now,$currentVersioned]=dc80Existing($pdo,$uid,$parent,$name,$mode);
            if(($now['id']??null)!==($old['id']??null)||$currentVersioned!==$versioned)throw new RuntimeException('El destino cambió durante la descarga');
            $id=dc80CommitExisting($pdo,$uid,$parent,$name,$old,$versioned,$mode,$stored,$total,$resultMime,$hash);$pdo->commit();
        }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
        if($old&&!$versioned&&$mode==='replace')@unlink(rtrim($storageDir,'/').'/'.$old['storage_name']);
        echo json_encode(['success'=>true,'id'=>$id,'message'=>'Archivo externo guardado: '.$name]);exit;
    }catch(Throwable $e){error_log('DC external import 68: '.$e->getMessage());dcsaFail(500,'No se pudo completar la importación');}
    finally {if(is_file($temp))@unlink($temp);if($dest && is_file($dest) && !$pdo->inTransaction()){
        // El archivo final solo se conserva si hay registro lógico en la BD.
        $st=$pdo->prepare('SELECT 1 FROM files WHERE storage_name=? LIMIT 1');$st->execute([basename($dest)]);if(!$st->fetchColumn())@unlink($dest);
    }}
}
