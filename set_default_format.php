<?php
// Instalación puntual: ejecutar SOLO por CLI.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/config.php';
$pdo->exec("INSERT IGNORE INTO dc_app_settings (setting_key, setting_value) VALUES ('local_preview_format', 'webm')");
echo "done\n";
