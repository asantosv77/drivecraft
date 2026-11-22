<?php
// Historial de actividad: no registrar lecturas, miniaturas ni vistas.
function dcActivity85m(PDO $pdo,int $uid,int $fid,string $action):void {
 if($uid<1||$fid<1||!in_array($action,['edit','download','share','move','copy','rename','special'],true))return;
 try{
  $q=$pdo->prepare("INSERT INTO dc_recent_activity_85m_hf2 (owner_id,file_id,action,activity_at) VALUES (?,?,?,NOW(6)) ON DUPLICATE KEY UPDATE action=VALUES(action),activity_at=VALUES(activity_at)");
  $q->execute([$uid,$fid,$action]);
 }catch(Throwable $e){error_log('[DC-HF2] No se registró actividad: '.$e->getMessage());}
}
