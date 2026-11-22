<?php
// Incluido desde api.php antes de exigir sesión. No publicar como script independiente.
if (!isset($pdo,$STORAGE_DIR,$link) || !function_exists('publicFail')) { http_response_code(404); exit; }
require_once __DIR__.'/dc_throttle.php';
function dcrLimit(PDO $pdo, array $user): int {
    $q=$pdo->prepare('SELECT setting_value FROM dc_app_settings WHERE setting_key=?');
    $q->execute(['receive_global_limit_bytes']);
    $global=(int)($q->fetchColumn() ?: 734003200);
    $limits=[$global];
    foreach (['receive_admin_limit_bytes','receive_limit_bytes','max_file_bytes'] as $key) {
        if (isset($user[$key]) && (int)$user[$key]>0) $limits[]=(int)$user[$key];
    }
    return min($limits);
}
function dcrFail(int $code,string $error): void {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    exit(json_encode(['error'=>$error],JSON_UNESCAPED_UNICODE));
}
function dcrValidName(string $name): bool {
    return $name!=='' && strlen($name)<=220 && !in_array($name,['.','..'],true)
       && !preg_match('~[\\/\x00-\x1f\x7f]~',$name);
}
function dcrFolderOwned(PDO $pdo,int $folder,int $owner): bool {
    $visited=[];
    while ($folder>0) {
        if (isset($visited[$folder])) return false;
        $visited[$folder]=1;
        $q=$pdo->prepare('SELECT parent_id,owner_id,deleted_at,is_dir FROM files WHERE id=?');
        $q->execute([$folder]);$f=$q->fetch(PDO::FETCH_ASSOC);
        if (!$f || (int)$f['owner_id']!==$owner || $f['deleted_at']!==null || (int)$f['is_dir']!==1) return false;
        $folder=(int)$f['parent_id'];
    }
    return true;
}
function dcrUsage(PDO $pdo,int $owner): int {
    $a=$pdo->prepare('SELECT COALESCE(SUM(size),0) FROM files WHERE owner_id=? AND is_dir=0');$a->execute([$owner]);
    $b=$pdo->prepare('SELECT COALESCE(SUM(v.size),0) FROM file_versions v JOIN files f ON f.id=v.file_id WHERE f.owner_id=?');$b->execute([$owner]);
    return (int)$a->fetchColumn()+(int)$b->fetchColumn();
}
require_once __DIR__.'/dc_receive_maintenance.php';
function dcrCleanupExpired(string $root): int {return dcRxSweep($root,true)['removed'];}
function dcrCleanup(string $dir): void {
    foreach (glob($dir.'/*') ?: [] as $file) {
        if (is_file($file)) @unlink($file);
    }
    @rmdir($dir);
}
$ownerId=(int)$link['owner_id'];
$u=$pdo->prepare('SELECT * FROM dc_users WHERE id=?');$u->execute([$ownerId]);$owner=$u->fetch(PDO::FETCH_ASSOC);
if (!$owner || $owner['status']!=='active' || !empty($owner['must_change_password']) || (empty($owner['can_upload']) && $owner['role']!=='admin') || !dcrFolderOwned($pdo,(int)$link['folder_id'],$ownerId)) publicFail(404);
// La política se decide en el servidor a partir de la carpeta destino.
// Si se desactiva el modo álbum, el enlace vuelve a permitir archivos normales.
$dcrAlbumStmt=$pdo->prepare('SELECT is_album FROM files WHERE id=? AND owner_id=? AND is_dir=1 AND deleted_at IS NULL');
$dcrAlbumStmt->execute([(int)$link['folder_id'],$ownerId]);
$dcrImagesOnly=((int)$dcrAlbumStmt->fetchColumn()===1);
// Tipos seguros para previsualización fotográfica; SVG queda excluido (puede contener código activo).
function dcrPhotoExtension(string $name): bool {
    return (bool)preg_match('/\.(?:jpe?g|png|gif|webp|avif|bmp)$/iD', $name);
}
function dcrPhotoContent(string $path, string $name): bool {
    if (!dcrPhotoExtension($name)) return false;
    $mime=(new finfo(FILEINFO_MIME_TYPE))->file($path);
    $allowed=['image/jpeg'=>['jpg','jpeg'],'image/png'=>['png'],'image/gif'=>['gif'],'image/webp'=>['webp'],'image/avif'=>['avif'],'image/bmp'=>['bmp']];
    $ext=strtolower(pathinfo($name,PATHINFO_EXTENSION));
    if (!isset($allowed[$mime]) || !in_array($ext,$allowed[$mime],true)) return false;
    // getimagesize inspecciona la cabecera sin decodificar toda la imagen.
    $info=@getimagesize($path);
    return $info!==false && isset($info[0],$info[1]) && $info[0]>0 && $info[1]>0;
}
// Huella perceptual dHash (64 bits). Solo fotos razonablemente pequeñas;
// las fotos enormes no se decodifican para proteger la memoria de PHP.
function dcrImageHash(string $path): ?string {
    if (!extension_loaded('gd') || !function_exists('imagecreatefromstring')) return null;
    if (!is_file($path) || filesize($path)>6*1048576) return null;
    $info=@getimagesize($path);
    if (!$info || ($info[0]*$info[1])>6000000 || $info[0]<2 || $info[1]<2) return null;
    $raw=@file_get_contents($path);if ($raw===false) return null;
    $im=@imagecreatefromstring($raw);unset($raw);if (!$im) return null;
    $small=imagecreatetruecolor(9,8);
    if (!imagecopyresampled($small,$im,0,0,0,0,9,8,imagesx($im),imagesy($im))) {imagedestroy($small);imagedestroy($im);return null;}
    imagedestroy($im);$bits='';
    for($y=0;$y<8;$y++) for($x=0;$x<8;$x++) {
        $a=imagecolorat($small,$x,$y);$b=imagecolorat($small,$x+1,$y);
        $lum=function($v){return (int)(0.299*(($v>>16)&255)+0.587*(($v>>8)&255)+0.114*($v&255));};
        $bits.=($lum($a)>$lum($b))?'1':'0';
    }
    imagedestroy($small);$hex='';for($i=0;$i<64;$i+=4)$hex.=dechex(bindec(substr($bits,$i,4)));
    return $hex;
}
function dcrHashDistance(string $a,string $b): int {
    if (!preg_match('/^[0-9a-f]{16}$/D',$a) || !preg_match('/^[0-9a-f]{16}$/D',$b)) return 65;
    $count=0;for($i=0;$i<16;$i++)$count+=substr_count(decbin(hexdec($a[$i])^hexdec($b[$i])),'1');
    return $count;
}
function dcrUniquePhotoName(PDO $pdo,int $parent,string $name): string {
    $stem=pathinfo($name,PATHINFO_FILENAME);$ext=pathinfo($name,PATHINFO_EXTENSION);
    $stem=mb_substr($stem,0,140);$suffix=$ext!==''?'.'.$ext:'';
    for($i=1;$i<=100;$i++) {
        $candidate=$stem.' (recibido '.($i).')'.$suffix;
        $q=$pdo->prepare('SELECT 1 FROM files WHERE parent_id=? AND name=? AND deleted_at IS NULL LIMIT 1');
        $q->execute([$parent,$candidate]);if(!$q->fetchColumn())return $candidate;
    }
    throw new RuntimeException('No fue posible reservar un nombre único');
}
require_once __DIR__.'/dc_photo_similarity.php';
$limit=dcrLimit($pdo,$owner);
$chunkCap=dcChunkMaxMiB($pdo)*1048576;
$phpUpload=dcIniBytes((string)ini_get('upload_max_filesize'));
$phpPost=dcIniBytes((string)ini_get('post_max_size'));
if ($phpUpload>0) $chunkCap=min($chunkCap,$phpUpload);
if ($phpPost>0) $chunkCap=min($chunkCap,max(0,$phpPost-1048576));
$chunkCap=(int)floor($chunkCap/1048576)*1048576;
if ($chunkCap<1048576) dcrFail(503,'El servidor no permite fragmentos de 1 MiB');
$action=(string)($_POST['receive_action']??'');
if ($_SERVER['REQUEST_METHOD']==='POST') {
    if ($link['password_hash']) {
    $dcReceiveThrottleKey = dcThrottleKey('receive', (string)($token ?? ''));
    dcThrottleCheck($pdo, $dcReceiveThrottleKey);
    if (!password_verify((string)($_POST['password']??''),$link['password_hash'])) {
        dcThrottleFail($pdo, $dcReceiveThrottleKey);
        dcrFail(403,'Contraseña incorrecta');
    }
    dcThrottleClear($pdo, $dcReceiveThrottleKey);
}
    if (!in_array($action,['init','chunk','finish','cancel'],true)) dcrFail(400,'Operación inválida');
    $id=(string)($_POST['upload_id']??'');
    if (!preg_match('/^[a-f0-9]{32}$/D',$id)) dcrFail(400,'ID de subida inválido');
    $tempRoot=rtrim($STORAGE_DIR,'/').'/dc_receive_parts';
    if (!is_dir($tempRoot) && !@mkdir($tempRoot,0700,true)) dcrFail(503,'No se pudo preparar el almacenamiento temporal');
    $dir=$tempRoot.'/'.hash('sha256',$token.'|'.$id);
    // Cancelar exclusivamente la sesión de este enlace, no las demás.
    if ($action==='cancel') {
        if (is_file($dir.'/state.json')) {
            $lock=@fopen($dir.'/lock','c');
            if (!$lock || !@flock($lock,LOCK_EX|LOCK_NB)) {
                if ($lock) fclose($lock);
                dcrFail(409,'La subida todavía está ocupada');
            }
            $state=json_decode((string)@file_get_contents($dir.'/state.json'),true);
            if ($state && (int)($state['link']??0)===(int)$link['id'] &&
                (int)($state['owner']??0)===$ownerId &&
                (int)($state['folder']??0)===(int)$link['folder_id']) {
                dcrCleanup($dir);
            }
            @flock($lock,LOCK_UN);fclose($lock);
        }
        header('Content-Type: application/json; charset=utf-8');
        exit(json_encode(['success'=>true]));
    }
    $maxParts=2048;
    if ($action==='init') {
        $name=(string)($_POST['file_name']??'');
        $size=filter_var($_POST['file_size']??'',FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
        $parts=filter_var($_POST['parts']??'',FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
        $requested=filter_var($_POST['chunk_size']??'',FILTER_VALIDATE_INT);
        $expectedChunk=min($chunkCap,$size<=100*1048576?1048576:($size<=1073741824?10*1048576:dcChunkMaxMiB($pdo)*1048576));
        if ($dcrImagesOnly && !dcrPhotoExtension($name)) dcrFail(415,'Este álbum solamente acepta fotografías');
        // Un nombre repetido se evalúa DESPUÉS del checksum al finalizar.
        // No bloquear el resto del lote durante init.
        if (!dcrValidName($name) || $size===false || $parts===false || $parts>$maxParts || $size>$limit || $requested!==$expectedChunk || $parts!== (int)ceil($size/$expectedChunk)) dcrFail(413,'Archivo o fragmentos no permitidos');
        dcrCleanupExpired($tempRoot);
        $pending=0;
        foreach(glob($tempRoot.'/*/state.json')?:[] as $oldFile){
            $old=json_decode((string)@file_get_contents($oldFile),true);
            if ($old && (int)($old['owner']??0)===$ownerId && time()-dcRxStamp(dirname($oldFile))<DC_RX_IDLE_SECONDS) $pending++;
        }
        if ($pending>=3) dcrFail(429,'Hay demasiadas subidas incompletas. Espera o solicita limpieza de temporales');
        $quota=(int)($owner['quota_bytes']??0);
        if ($quota>0 && dcrUsage($pdo,$ownerId)+$size>$quota) dcrFail(413,'Cuota insuficiente');
        if (disk_free_space($STORAGE_DIR)<$size*2+16777216) dcrFail(507,'Espacio insuficiente');
        if (!@mkdir($dir,0700)) dcrFail(409,'Identificador en uso');
        $state=['link'=>(int)$link['id'],'owner'=>$ownerId,'folder'=>(int)$link['folder_id'],'name'=>$name,'size'=>$size,'parts'=>$parts,'chunk'=>$expectedChunk,'created'=>time(),'last_activity'=>time()];
        if (file_put_contents($dir.'/state.json',json_encode($state))===false) {dcrCleanup($dir);dcrFail(500,'Error al iniciar');}
        header('Content-Type: application/json');exit(json_encode(['success'=>true,'chunk_size'=>$expectedChunk]));
    }
    $stateFile=$dir.'/state.json';
    if (!is_file($stateFile) || time()-dcRxStamp($dir)>DC_RX_IDLE_SECONDS) dcrFail(404,'Subida no encontrada o vencida');
    $state=json_decode((string)file_get_contents($stateFile),true);
    if (!$state || $state['link']!==(int)$link['id'] || $state['owner']!==$ownerId || $state['folder']!==(int)$link['folder_id']) dcrFail(403,'Subida no autorizada');
    $lock=fopen($dir.'/lock','c');if (!$lock || !flock($lock,LOCK_EX)) dcrFail(503,'Subida ocupada');
    if ($action==='chunk') {
        $n=filter_var($_POST['chunk_index']??'',FILTER_VALIDATE_INT,['options'=>['min_range'=>0]]);
        if ($n===false || $n>=$state['parts'] || !isset($_FILES['chunk']) || $_FILES['chunk']['error']!==UPLOAD_ERR_OK || !is_uploaded_file($_FILES['chunk']['tmp_name'])) dcrFail(400,'Fragmento inválido');
        $size=(int)$_FILES['chunk']['size'];
        $expected=($n===$state['parts']-1)?($state['size']-$n*$state['chunk']):$state['chunk'];
        if ($size!==$expected || $size<=0 || $size>$chunkCap) dcrFail(413,'Tamaño del fragmento incorrecto');
        $part=$dir.'/part_'.str_pad((string)$n,5,'0',STR_PAD_LEFT);
        if (is_file($part)) {
            if (hash_file('sha256',$part)!==hash_file('sha256',$_FILES['chunk']['tmp_name'])) dcrFail(409,'Fragmento previamente recibido con datos distintos');
        } elseif (!move_uploaded_file($_FILES['chunk']['tmp_name'],$part)) dcrFail(500,'No se pudo almacenar fragmento');
        @touch($stateFile); // Cada fragmento confirmado renueva la actividad.
        header('Content-Type: application/json');exit(json_encode(['success'=>true,'index'=>$n]));
    }
    // Finalización serializada con el resto de escrituras del usuario.
    $named='dc-upload-'.$ownerId;$l=$pdo->prepare('SELECT GET_LOCK(?,15)');$l->execute([$named]);
    if ((int)$l->fetchColumn()!==1) dcrFail(503,'Almacenamiento ocupado, reintenta');
    $target=null;
    try {
        $u->execute([$ownerId]);$fresh=$u->fetch(PDO::FETCH_ASSOC);
        if (!$fresh || $fresh['status']!=='active' || (empty($fresh['can_upload']) && $fresh['role']!=='admin') || !dcrFolderOwned($pdo,(int)$link['folder_id'],$ownerId)) throw new RuntimeException('Permiso revocado');
        if ($dcrImagesOnly && !dcrPhotoExtension((string)$state['name'])) throw new RuntimeException('El álbum solamente acepta imágenes');
        if ($state['size']>dcrLimit($pdo,$fresh)) throw new RuntimeException('Se redujo el límite permitido');
        $quota=(int)($fresh['quota_bytes']??0);
        if ($quota>0 && dcrUsage($pdo,$ownerId)+$state['size']>$quota) throw new RuntimeException('Cuota insuficiente');
        if (disk_free_space($STORAGE_DIR)<$state['size']+16777216) throw new RuntimeException('Espacio insuficiente');
        $storage=bin2hex(random_bytes(20)).'_received';$target=$STORAGE_DIR.$storage;
        $out=fopen($target,'x+b');if(!$out) throw new RuntimeException('No se pudo crear archivo');
        $total=0;
        try {
            for ($i=0;$i<$state['parts'];$i++) {
                $part=$dir.'/part_'.str_pad((string)$i,5,'0',STR_PAD_LEFT);
                if (!is_file($part)) throw new RuntimeException('Falta un fragmento');
                $input=fopen($part,'rb');if (!$input) throw new RuntimeException('No se pudo leer fragmento');
                $written=stream_copy_to_stream($input,$out);fclose($input);
                if ($written===false) throw new RuntimeException('Error al unir fragmentos');
                $total+=$written;
            }
        } finally {fclose($out);}
        if ($total!==$state['size'] || filesize($target)!==$state['size']) throw new RuntimeException('Tamaño final incorrecto');
        if ($dcrImagesOnly && !dcrPhotoContent($target,(string)$state['name'])) throw new RuntimeException('No es una imagen válida o el formato no coincide con su extensión');
        [$dedupEnabled,$dedupAlgo]=dcDedupConfig($pdo);
        $digest=$dedupEnabled ? hash_file($dedupAlgo,$target) : null;
        if ($dedupEnabled && $digest===false) throw new RuntimeException('No se pudo comprobar el archivo');
        if ($dedupEnabled && dcDuplicateMatch($pdo,$ownerId,(int)$total,$dedupAlgo,$digest)) {
            // Omitir con mensaje genérico, sin exponer nombres, rutas o imágenes ajenas.
            @unlink($target);$target=null;dcrCleanup($dir);
            header('Content-Type: application/json');exit(json_encode(['success'=>true,'status'=>'omitted']));
        }
        $mime=(new finfo(FILEINFO_MIME_TYPE))->file($target)?:'application/octet-stream';
        $photo=(bool)preg_match('~^image/(?:jpeg|png|webp|gif|avif|bmp)$~',$mime);
        $fingerprint=$photo ? dcPhotoFingerprint($target) : null;
        $visualHash=$fingerprint['dhash']??null;
        $visualPhash=$fingerprint['phash']??null;
        $same=$pdo->prepare('SELECT id,is_dir,mime_type,storage_name,image_dhash FROM files WHERE owner_id=? AND parent_id=? AND name=? AND deleted_at IS NULL LIMIT 1');
        $same->execute([$ownerId,(int)$link['folder_id'],$state['name']]);$conflict=$same->fetch(PDO::FETCH_ASSOC);
        if ($conflict && (int)$conflict['is_dir']===1) throw new RuntimeException('El nombre está reservado por una carpeta');
        if ($conflict && !$photo) throw new RuntimeException('Ya existe un archivo con ese nombre');
        $reviewTarget=$conflict?(int)$conflict['id']:null;
        if (!$reviewTarget && $fingerprint!==null) {
            // Evitar falsos positivos: exigir acuerdo entre pHash, dHash, color y proporción.
            // Solo comparar imágenes de la carpeta destino, con límites de lectura/decodificación.
            $q=$pdo->prepare("SELECT id,storage_name,size,mime_type FROM files WHERE owner_id=? AND parent_id=? AND deleted_at IS NULL AND is_dir=0 AND mime_type LIKE 'image/%' ORDER BY id DESC LIMIT 40");
            $q->execute([$ownerId,(int)$link['folder_id']]);
            foreach($q->fetchAll(PDO::FETCH_ASSOC) as $row) {
                if(empty($row['storage_name']) || !preg_match('/^[a-zA-Z0-9_.-]+$/D',$row['storage_name']) || (int)$row['size']>8*1048576)continue;
                $other=dcPhotoFingerprint($STORAGE_DIR.$row['storage_name']);
                if($other!==null && dcPhotoHighConfidence($fingerprint,$other)){$reviewTarget=(int)$row['id'];break;}
            }
        }
        $name=$reviewTarget ? dcrUniquePhotoName($pdo,(int)$link['folder_id'],(string)$state['name']) : (string)$state['name'];
        $ins=$pdo->prepare('INSERT INTO files(parent_id,name,is_dir,mime_type,size,storage_name,has_thumb,has_list,owner_id,received_via_link_id,received_highlight,content_hash_algorithm,content_hash,image_dhash,image_phash,photo_review_status,photo_review_target_id) VALUES(?,?,0,?,?,?,0,0,?,?,1,?,?,?,?,?,?)');
        $ins->execute([(int)$link['folder_id'],$name,$mime,$total,$storage,$ownerId,(int)$link['id'],$digest!==null?$dedupAlgo:null,$digest,$visualHash,$visualPhash,$reviewTarget?'pending':null,$reviewTarget]);
        $newId=(int)$pdo->lastInsertId();
        // Miniatura ligera para el explorador del álbum (si GD/convert disponibles).
        if ($newId > 0 && $photo && function_exists('ensureImageThumbnail') && isset($THUMBS_DIR)) {
            try {
                ensureImageThumbnail([
                    'id'=>$newId,'is_dir'=>0,'mime_type'=>$mime,'name'=>$name,
                    'storage_name'=>$storage,'has_thumb'=>0
                ], $pdo, $STORAGE_DIR, $THUMBS_DIR);
            } catch (Throwable $ignore) {}
        }
        dcrCleanup($dir);
        header('Content-Type: application/json');exit(json_encode(['success'=>true,'status'=>$reviewTarget?'review':'uploaded']));
    } catch(Throwable $e) {
        if($target && is_file($target)) @unlink($target);
        // El fallo definitivo no debe secuestrar uno de los tres cupos del usuario.
        dcrCleanup($dir);
        error_log('Drive-Craft recepción: finalización fallida: '.$e->getMessage());
        dcrFail(409,$e->getMessage());
    }
    finally {$pdo->prepare('SELECT RELEASE_LOCK(?)')->execute([$named]);}
}
// Página pública: solo progreso y nombres de archivos cargados durante esta visita.
header('Content-Type: text/html; charset=utf-8');
$maxMiB=round($limit/1048576,2);
$needsPassword=!empty($link['password_hash']);
?>
<!doctype html><html lang="es" data-theme="dark"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="referrer" content="no-referrer"><title>Recibir archivos — Drive-Craft</title><link rel="icon" href="favicon-32.png"><link rel="stylesheet" href="public.css"><style>
.dc-rx-item{padding:12px;margin:8px 0;border:1px solid #65738a;border-radius:9px}.dc-rx-item progress{width:100%;height:12px}.dc-rx-error{color:#ff9898}.dc-rx-success{color:#8eebbc}#rxResults{list-style:none;padding:0}
</style></head><body class="public-page"><main class="public-login"><section class="public-panel"><h1>Enviar archivos</h1><p>Máximo <?=publicEscape($maxMiB)?> MiB por archivo. <?= $dcrImagesOnly ? 'Este álbum solo acepta fotografías JPG, PNG, GIF, WebP, AVIF y BMP. ' : '' ?>La lista mostrará únicamente tus envíos de esta visita.</p><input id="rxFile" type="file" <?= $dcrImagesOnly ? 'accept="image/jpeg,image/png,image/gif,image/webp,image/avif,image/bmp"' : '' ?> multiple><?php if($needsPassword):?><input id="rxPass" type="password" placeholder="Contraseña del enlace" required><?php endif;?><button class="public-btn" id="rxSend" type="button">Enviar archivos</button><ul id="rxResults" aria-live="polite"></ul></section></main>
<script>
const maxBytes=<?=json_encode($limit)?>, maxCap=<?=json_encode($chunkCap)?>, imagesOnly=<?=json_encode($dcrImagesOnly)?>;
const input=document.getElementById('rxFile'),list=document.getElementById('rxResults'),button=document.getElementById('rxSend');
function request(body,onProgress){return new Promise((resolve,reject)=>{const xhr=new XMLHttpRequest();xhr.open('POST',location.href);xhr.responseType='json';xhr.upload.onprogress=e=>{if(e.lengthComputable&&onProgress)onProgress(e.loaded/e.total)};xhr.onload=()=>{const r=xhr.response||{};if(xhr.status>=200&&xhr.status<300&&r.success)resolve(r);else reject(Error(r.error||'Error HTTP '+xhr.status))};xhr.onerror=()=>reject(Error('Error de red'));xhr.send(body)})}
function form(action,id){const d=new FormData();d.append('receive_action',action);d.append('upload_id',id);const pass=document.getElementById('rxPass');if(pass)d.append('password',pass.value);return d}
async function upload(file){
 const li=document.createElement('li');li.className='dc-rx-item';const title=document.createElement('strong');title.textContent=file.name;li.append(title);const progress=document.createElement('progress');progress.max=100;progress.value=0;li.append(progress);const status=document.createElement('div');status.textContent='En espera';li.append(status);list.append(li);
 let id=null,initialized=false;
 try{if(file.size<1||file.size>maxBytes)throw Error('El archivo excede el límite o está vacío');
 if(imagesOnly&&!/\.(?:jpe?g|png|gif|webp|avif|bmp)$/i.test(file.name))throw Error('Solo se permiten fotografías en este álbum');
 id=Array.from(crypto.getRandomValues(new Uint8Array(16)),x=>x.toString(16).padStart(2,'0')).join('');const MiB=1048576;const cap=Math.min(maxCap,file.size<=100*MiB?MiB:file.size<=1024*MiB?10*MiB:maxCap);const total=Math.ceil(file.size/cap);
 const init=form('init',id);init.append('file_name',file.name);init.append('file_size',String(file.size));init.append('parts',String(total));init.append('chunk_size',String(cap));await request(init);initialized=true;
 for(let n=0;n<total;n++){const fd=form('chunk',id);fd.append('chunk_index',String(n));fd.append('chunk',file.slice(n*cap,Math.min((n+1)*cap,file.size)),file.name);status.textContent='Subiendo '+(n+1)+' de '+total;await request(fd,p=>progress.value=Math.min(99,Math.round((n+p)/total*100)));}
 status.textContent='Comprobando contenido...';const result=await request(form('finish',id));progress.value=100;status.textContent=result.status==='omitted'?'— Omitido por política de duplicados':result.status==='review'?'✓ Recibido para revisión del propietario':'✓ Recibido correctamente';status.className='dc-rx-success';
 }catch(e){
  if (id && initialized) {
   try { await request(form('cancel',id)); } catch (_) { /* limpieza por expiración como respaldo */ }
  }
  status.textContent='Error: '+e.message;status.className='dc-rx-error';
 }
}
button.addEventListener('click',async()=>{const files=[...input.files];if(!files.length)return;button.disabled=true;try{for(const f of files)await upload(f);input.value='';}finally{button.disabled=false}});
</script></body></html>
