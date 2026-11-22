<?php
require __DIR__.'/config.php';
require __DIR__.'/auth.php';
$me=dcRequireUser();
if (($me['role']??'')!=='admin' || !empty($me['must_change_password'])) {http_response_code(403);exit('Acceso denegado');}
header('Cache-Control: private, no-store');
header('X-Content-Type-Options: nosniff');
header('Content-Security-Policy: default-src \'none\'; style-src \'unsafe-inline\'; form-action \'self\'; base-uri \'none\'; frame-ancestors \'none\'');
function dcEscape($s){return htmlspecialchars((string)$s,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
$results=[];$tested=($_SERVER['REQUEST_METHOD']==='POST');
if ($tested) {
 if (!isset($_POST['csrf']) || !hash_equals((string)($_SESSION['dc_csrf']??''),(string)$_POST['csrf'])) {http_response_code(403);exit('Token CSRF inválido');}
 foreach(['Archivos'=>$STORAGE_DIR,'Miniaturas'=>$THUMBS_DIR,'Listas'=>$LISTS_DIR] as $label=>$dir){
  $dir=rtrim((string)$dir,'/').'/';
  $r=['label'=>$label,'directory'=>$dir,'exists'=>is_dir($dir),'read'=>false,'write'=>false,'delete'=>false,'details'=>''];
  if ($r['exists']){
   $r['read']=is_readable($dir);
   $tmp=$dir.'.dc-permission-check-'.bin2hex(random_bytes(12));
   // Creación exclusiva: nunca toca documentos existentes.
   $handle=@fopen($tmp,'x+b');
   if ($handle!==false){
    $r['write']=(fwrite($handle,'dc-permission-check')===19 && fflush($handle));
    fclose($handle);
    $r['delete']=@unlink($tmp);
    if (!$r['delete']) $r['details']='Fallo al retirar archivo temporal: revisar ACL de directorio';
   } else {$r['details']='No fue posible crear archivo temporal: revisar ACL de directorio';}
  } else {$r['details']='El directorio no existe';}
  $results[]=$r;
 }
}
// DC_DIAG_JSON_V201013: reutiliza el diagnóstico existente y su CSRF para la interfaz unificada.
if (isset($_GET['dc_json']) && $_GET['dc_json']==='1') {
 header('Content-Type: application/json; charset=utf-8');
 header('X-Content-Type-Options: nosniff');
 if (!$tested) { http_response_code(405); echo json_encode(['success'=>false,'error'=>'Se requiere POST']); exit; }
 echo json_encode(['success'=>true,'results'=>$results],JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE); exit;
}
?><!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Diagnóstico de almacenamiento — Drive-Craft</title><style>
body{font-family:system-ui,sans-serif;background:#101827;color:#e7edf7;max-width:860px;margin:3rem auto;padding:0 1rem;line-height:1.55}a{color:#8dbaff}section{background:#1c293b;border:1px solid #3b4b63;border-radius:14px;padding:1.2rem;margin:1rem 0}button{background:#54a4f9;color:#091827;border:0;border-radius:10px;padding:.8rem 1.1rem;font-weight:700;cursor:pointer}code,pre{background:#0c1420;padding:.2rem .4rem;border-radius:5px;overflow-wrap:anywhere}pre{padding:1rem;overflow:auto} .ok{color:#7cdeb1}.bad{color:#ffadad}small{color:#b8c6d7}
</style></head><body><a href="index.php">← Volver a Drive-Craft</a><h1>Diagnóstico de permisos del almacenamiento</h1><p>Las pruebas se ejecutan desde PHP bajo el usuario efectivo del servidor web; no desde el cron ni como root.</p><section><form method="post"><input type="hidden" name="csrf" value="<?=dcEscape($_SESSION['dc_csrf']??'')?>"><button type="submit">Validar permisos de directorios</button></form><p><small>Solo crea y elimina archivos temporales de prueba de nombre aleatorio. No cambia propietarios, ACL ni permisos.</small></p></section>
<?php if($tested): ?>
<?php foreach($results as $r):?><section><h2><?=dcEscape($r['label'])?></h2><p><code><?=dcEscape($r['directory'])?></code></p><p class="<?=($r['exists']&&$r['read']&&$r['write']&&$r['delete'])?'ok':'bad'?>">Existencia: <?=$r['exists']?'Sí':'No'?> · Lectura: <?=$r['read']?'Sí':'No'?> · Creación/escritura: <?=$r['write']?'Sí':'No'?> · Borrado: <?=$r['delete']?'Sí':'No'?></p><?php if($r['details']):?><p><?=dcEscape($r['details'])?></p><?php endif;?></section><?php endforeach; ?>
<section><h2>Recomendación</h2><p>Si falla la escritura o el borrado, revisa primero el grupo y las ACL del directorio afectado en el host. En esta instalación el volumen está montado desde <code>/mnt/ntfs1/shared/drive-craft</code> a <code>/drive</code>.</p><pre>sudo getfacl /mnt/ntfs1/shared/drive-craft/files
sudo setfacl -m g:www-data:rwx,m::rwx /mnt/ntfs1/shared/drive-craft/files
sudo setfacl -m d:g:www-data:rwx,d:m::rwx /mnt/ntfs1/shared/drive-craft/files</pre><small>Comandos sugeridos; no se ejecutan automáticamente. Ajusta la ruta si cambian tus volúmenes.</small></section>
<?php endif;?></body></html>
