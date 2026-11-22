<?php
declare(strict_types=1);
// Public UI labels only. No application config, session or user data is loaded.
$language=$_GET['lang']??'en';
if(!is_string($language)||!in_array($language,['es','en'],true)){http_response_code(400);exit;}
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: public, max-age=3600');
if($language==='es'){echo '{}';exit;}
$catalog=__DIR__.'/dc_i18n_en_dcft.json';
if(!is_file($catalog)||!is_readable($catalog)){http_response_code(503);header('Cache-Control: no-store');echo '{}';exit;}
readfile($catalog);
