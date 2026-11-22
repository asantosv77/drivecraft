<?php
declare(strict_types=1);
// Maintainer utility. stdout contains DDL only, never INSERTs or table rows.
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require __DIR__.'/../config.php';
$tables=$pdo->query("SHOW FULL TABLES WHERE Table_type='BASE TABLE'")->fetchAll(PDO::FETCH_NUM);$out=[];
foreach($tables as $row){$name=$row[0];if($name==='dc_deploy_meta')continue;if(!preg_match('/^[A-Za-z0-9_]+$/D',$name))throw new RuntimeException('Invalid table name');
 $ddl=$pdo->query('SHOW CREATE TABLE `'.$name.'`')->fetch(PDO::FETCH_NUM)[1];
 $ddl=preg_replace('/ AUTO_INCREMENT=[0-9]+/','',$ddl);
 $ddl=preg_replace('/^CREATE TABLE /','CREATE TABLE IF NOT EXISTS ',$ddl);
 $out[]=['name'=>$name,'ddl'=>$ddl];
}
echo json_encode($out,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)."\n";
