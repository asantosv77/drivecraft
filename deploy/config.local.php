<?php
declare(strict_types=1);
// Standalone image only: no host-specific paths or embedded credentials.
if(PHP_SAPI!=='cli' && session_status()===PHP_SESSION_NONE){
 ini_set('session.gc_maxlifetime',(string)(360*86400));
 $cookie=getenv('DC_SESSION_COOKIE')?:'DRIVECRAFT_LOCAL_SESSION';
 if(!preg_match('/^[A-Za-z][A-Za-z0-9_]{2,63}$/D',$cookie))throw new RuntimeException('Invalid session name');
 session_name($cookie);
 session_set_cookie_params(['lifetime'=>360*86400,'path'=>'/','httponly'=>true,'samesite'=>'Lax','secure'=>getenv('DC_COOKIE_SECURE')==='1'||(!empty($_SERVER['HTTPS'])&&$_SERVER['HTTPS']!=='off')]);
 session_start();
}
$DB_HOST=getenv('DB_HOST')?:'db';$DB_NAME=getenv('DB_NAME')?:'drivecraft';$DB_USER=getenv('DB_USER')?:'drivecraft';$DB_PASS=getenv('DB_PASSWORD');
if(!preg_match('/^[A-Za-z0-9_]+$/D',$DB_NAME)||$DB_PASS===false||$DB_PASS==='')throw new RuntimeException('Missing database configuration');
$STORAGE_DIR='/drive/files/';$THUMBS_DIR='/drive/thumbs/';$LISTS_DIR='/drive/lists/';
try{$pdo=new PDO('mysql:host='.$DB_HOST.';dbname='.$DB_NAME.';charset=utf8mb4',$DB_USER,$DB_PASS,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]);}
catch(PDOException $e){error_log('Drivecraft database connection unavailable');if(PHP_SAPI==='cli')throw new RuntimeException('Database connection unavailable');http_response_code(503);exit('Drive-Craft no está disponible temporalmente.');}
function checkAuth(){if(empty($_SESSION['logged_in'])){http_response_code(401);header('Content-Type: application/json');echo json_encode(['error'=>'No autorizado']);exit;}}
