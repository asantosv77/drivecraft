<?php
declare(strict_types=1);
// Adjust the image copy only; never changes the live project on the host.
$app='/var/www/html/drive';
foreach(['check_ini.php','check_albums.php','fix.php','recover.php','test-php-limits.php','setup_admin.php'] as $name){$f=$app.'/'.$name;if(is_file($f))unlink($f);}
$file=$app.'/script.js';$source=file_get_contents($file);
$source=str_replace("if (url.protocol === 'http:') url.protocol = 'https:';", "if (url.protocol === 'http:' && location.protocol === 'https:') url.protocol = 'https:';",$source,$count1);
$source=str_replace("if(url.protocol==='http:')url.protocol='https:';", "if(url.protocol==='http:' && location.protocol==='https:')url.protocol='https:';",$source,$count2);
if($count1!==1||$count2!==1)throw new RuntimeException('Review the local link compatibility patch before building this source version.');
file_put_contents($file,$source);
file_put_contents('/var/www/html/index.php',"<?php header('Location: /drive/'); exit;\n");
foreach(glob('/opt/drivecraft/*.sh') as $f){$s=file_get_contents($f);file_put_contents($f,str_replace("\r\n","\n",$s));}
