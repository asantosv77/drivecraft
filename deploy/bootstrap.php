<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli')exit(1);
require '/var/www/html/drive/config.php';
umask(0007);
foreach(['/drive/files','/drive/thumbs','/drive/lists','/drive/sessions','/drive/tmp','/drive/tmp/uploads','/drive/home','/drive/cache'] as $dir){if(is_link($dir))throw new RuntimeException('Unexpected storage symlink');if(!is_dir($dir)&&!mkdir($dir,0770,true))throw new RuntimeException('Cannot create storage directory');if(!is_writable($dir))throw new RuntimeException('Storage is not writable: '.$dir);}
$lock=fopen('/drive/.bootstrap.lock','c');if(!$lock||!flock($lock,LOCK_EX))throw new RuntimeException('Cannot lock initialization');
// Probe actual hardlinks; a filesystem label alone is not sufficient.
$source='/drive/files/.hardlink-probe-'.bin2hex(random_bytes(8));$link=$source.'.link';$hardlinks=false;
try{file_put_contents($source,'Drivecraft storage probe',LOCK_EX);if(@link($source,$link)){$a=stat($source);$b=stat($link);$hardlinks=$a['ino']===$b['ino']&&$a['dev']===$b['dev']&&$a['nlink']>=2;}}
finally{if(is_file($link))unlink($link);if(is_file($source))unlink($source);}
if(!$hardlinks&&getenv('DC_REQUIRE_HARDLINKS')!=='0')throw new RuntimeException('Deduplication requires Linux storage with working hardlinks (ext3/ext4/XFS/Btrfs, same volume). Use Docker named volumes instead of Windows/SMB/exFAT bind mounts.');
$raw=file_get_contents('/opt/drivecraft/schema.json');$tables=json_decode($raw,true,512,JSON_THROW_ON_ERROR);if(!is_array($tables)||count($tables)<20)throw new RuntimeException('Incomplete clean schema');
$hash=hash('sha256',$raw);
$pdo->exec('CREATE TABLE IF NOT EXISTS dc_deploy_meta (meta_key VARCHAR(80) PRIMARY KEY, meta_value VARCHAR(255) NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
$previous=$pdo->query("SELECT meta_value FROM dc_deploy_meta WHERE meta_key='schema_hash'")->fetchColumn();
if($previous!==false&&$previous!==$hash)throw new RuntimeException('This volume belongs to a different schema release. Back up and apply reviewed migrations before updating.');
$pdo->exec('SET FOREIGN_KEY_CHECKS=0');
try{foreach($tables as $table){if(!is_string($table['ddl']??null)||!str_starts_with($table['ddl'],'CREATE TABLE IF NOT EXISTS `'))throw new RuntimeException('Invalid schema entry');$pdo->exec($table['ddl']);}}
finally{$pdo->exec('SET FOREIGN_KEY_CHECKS=1');}
$pdo->beginTransaction();
try{
 $count=(int)$pdo->query('SELECT COUNT(*) FROM dc_users')->fetchColumn();
 if($count===0){
  $username=getenv('DC_ADMIN_USER')?:'admin';$password=getenv('DC_ADMIN_PASSWORD')?:'';
  if(!preg_match('/^[A-Za-z0-9_.-]{3,64}$/D',$username))throw new RuntimeException('Admin username must contain 3–64 letters, digits, dots, hyphens or underscores.');
  if(strlen($password)<12||strlen($password)>256||str_starts_with($password,'CHANGE_ME'))throw new RuntimeException('Choose an initial admin password of 12–256 characters in .env.');
  $pdo->prepare("INSERT INTO dc_users(username,password_hash,role,status,must_change_password,can_share,can_upload,can_delete,can_convert_video) VALUES(?,?,'admin','active',1,1,1,1,1)")->execute([$username,password_hash($password,PASSWORD_DEFAULT)]);
  fwrite(STDOUT,'Initial administrator created: '.$username.". Password change required at first sign-in.\n");
 }else fwrite(STDOUT,"Existing accounts preserved; .env never resets their passwords.\n");
 $pdo->prepare('INSERT INTO dc_deploy_meta(meta_key,meta_value) VALUES(?,?) ON DUPLICATE KEY UPDATE meta_value=VALUES(meta_value)')->execute(['schema_hash',$hash]);
 $pdo->commit();
}catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
fwrite(STDOUT,'Clean schema ready. Hardlinks: '.($hardlinks?'supported':'unavailable').".\n");
