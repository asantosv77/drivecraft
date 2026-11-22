<?php
// Private settings and crypto; never editable by adapter maintenance.
function dcmtDownloadsEnabled(PDO $db):bool {$q=$db->prepare("SELECT setting_value FROM dc_app_settings WHERE setting_key='dcmt_downloads_enabled'");$q->execute();$v=$q->fetchColumn();return $v===false||$v==='1';}
function dcmtPrivateDir():string {
 global $STORAGE_DIR;$base=realpath($STORAGE_DIR);$public=realpath('/var/www/html');if(!$base||($public&&str_starts_with($base.'/',rtrim($public,'/').'/')))throw new RuntimeException('El almacenamiento privado debe quedar fuera del directorio web');
 $dir=$base.'/.dc-external-maintenance';if(is_link($dir))throw new RuntimeException('Directorio privado inválido');if(!is_dir($dir)&&!mkdir($dir,0700))throw new RuntimeException('No se pudo preparar el almacenamiento privado');return $dir;
}
function dcmtCryptoKey():string {
 $path=dcmtPrivateDir().'/key';if(!is_file($path)){$handle=@fopen($path,'x');if($handle){chmod($path,0600);fwrite($handle,random_bytes(32));fclose($handle);}}
 $key=file_get_contents($path);if(strlen($key)!==32||is_link($path))throw new RuntimeException('Clave de cifrado no disponible');return $key;
}
function dcmtTokenEncrypt(string $token):string {$iv=random_bytes(12);$tag='';$cipher=openssl_encrypt($token,'aes-256-gcm',dcmtCryptoKey(),OPENSSL_RAW_DATA,$iv,$tag);if($cipher===false)throw new RuntimeException('No se pudo cifrar el token');return base64_encode($iv.$tag.$cipher);}
function dcmtTokenDecrypt(string $value):string {if($value==='')return '';$raw=base64_decode($value,true);if($raw===false||strlen($raw)<29)throw new RuntimeException('Token cifrado inválido');$plain=openssl_decrypt(substr($raw,28),'aes-256-gcm',dcmtCryptoKey(),OPENSSL_RAW_DATA,substr($raw,0,12),substr($raw,12,16));if($plain===false)throw new RuntimeException('No se pudo leer el token');return $plain;}
function dcmtExternalSettings(PDO $db,bool $secret=false):array {
 $q=$db->query("SELECT setting_key,setting_value FROM dc_app_settings WHERE setting_key LIKE 'dcmt_%'");$s=$q->fetchAll(PDO::FETCH_KEY_PAIR);
 $r=['downloads_enabled'=>($s['dcmt_downloads_enabled']??'1')==='1','weekly'=>($s['dcmt_ai_weekly']??'0')==='1','auto_apply'=>($s['dcmt_ai_auto_apply']??'1')==='1','model'=>$s['dcmt_ai_model']??'','has_token'=>!empty($s['dcmt_ai_token']),'last_attempt'=>(int)($s['dcmt_ai_last_attempt']??0)];if($secret)$r['token']=dcmtTokenDecrypt($s['dcmt_ai_token']??'');return $r;
}
function dcmtSetting(PDO $db,string $key,string $value):void {$db->prepare('INSERT INTO dc_app_settings(setting_key,setting_value) VALUES(?,?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)')->execute(['dcmt_'.$key,$value]);}
function dcmtPrivateJson(string $path,array $value):void {$tmp=tempnam(dirname($path),'.state-');if(!$tmp||file_put_contents($tmp,json_encode($value,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),LOCK_EX)===false)throw new RuntimeException('No se pudo guardar el estado');chmod($tmp,0600);if(!rename($tmp,$path))throw new RuntimeException('No se pudo guardar el estado');}
