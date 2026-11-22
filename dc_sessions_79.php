<?php
// Own-session activity and revocation. Never accepts a target account from the client.
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');
header('X-Content-Type-Options: nosniff');
function dc79Json($data){echo json_encode($data,JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE);exit;}
try {
    require __DIR__.'/config.php';
    require_once __DIR__.'/auth.php';
    require_once __DIR__.'/dc_throttle.php';
    require_once __DIR__.'/dc_session_control_dcft.php';
    $method=$_SERVER['REQUEST_METHOD'];
    $action=(string)($method==='POST'?($_POST['action']??''):($_GET['action']??'count'));
    if(!in_array($method,['GET','POST'],true)){header('Allow: GET, POST');http_response_code(405);dc79Json(['error'=>'Método no permitido']);}
    // A passive heartbeat must not extend an otherwise idle login.
    $user=dcRequireUser($action!=='count');
    $uid=(int)$user['id'];
    $token=hash('sha256',session_id());
    if($action==='revoke'){
        if($method!=='POST'){http_response_code(405);header('Allow: POST');dc79Json(['error'=>'La revocación requiere POST']);}
        dcCsrf();
        $except=(string)($_POST['except_current']??'1');
        if(!in_array($except,['0','1'],true)){http_response_code(422);dc79Json(['error'=>'Opción de conservación inválida']);}
        dc79Json(dcRevokeOwnSessionsDcft($pdo,$user,$except==='1'));
    }
    if($method!=='GET'||!in_array($action,['count','list'],true)){http_response_code(400);dc79Json(['error'=>'Acción no válida']);}
    $cutoff=gmdate('Y-m-d H:i:s',time()-900);
    $ip=dcThrottleClientIp();
    $pdo->prepare('INSERT INTO dc_user_sessions_79 (session_hash,user_id,ip_address,first_seen,last_seen) VALUES (?,?,?,UTC_TIMESTAMP(),UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE user_id=VALUES(user_id),ip_address=VALUES(ip_address),last_seen=UTC_TIMESTAMP()')->execute([$token,$uid,$ip]);
    $q=$pdo->prepare('SELECT COUNT(*) FROM dc_user_sessions_79 WHERE user_id=? AND last_seen>=?');$q->execute([$uid,$cutoff]);$count=(int)$q->fetchColumn();
    if($action==='count')dc79Json(['count'=>$count]);
    $q=$pdo->prepare('SELECT session_hash,ip_address,first_seen,last_seen FROM dc_user_sessions_79 WHERE user_id=? AND last_seen>=? ORDER BY last_seen DESC LIMIT 30');$q->execute([$uid,$cutoff]);
    $rows=$q->fetchAll(PDO::FETCH_ASSOC);
    foreach($rows as &$row){$row['current']=hash_equals($token,(string)$row['session_hash']);unset($row['session_hash']);}unset($row);
    dc79Json(['count'=>$count,'sessions'=>$rows]);
} catch(Throwable $e) {
    error_log('Drivecraft session control: '.$e->getMessage());
    $changed=$e->getCode()===401;
    http_response_code($changed?401:503);
    dc79Json(['error'=>$changed?'La sesión cambió. Vuelve a iniciar sesión.':'No se pudo completar la consulta o revocación de sesiones. Inténtalo de nuevo.']);
}
