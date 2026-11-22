<?php
/* Instalación v20.10.30; ejecutar únicamente por CLI dentro del contenedor www. */
if(PHP_SAPI!=='cli'){http_response_code(404);exit(1);}
require __DIR__.'/config.php';
$pdo->exec("CREATE TABLE IF NOT EXISTS dc_local_video_conversions (
 video_file_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
 owner_id BIGINT UNSIGNED NOT NULL,
 source_storage VARCHAR(255) NOT NULL,
 state VARCHAR(20) NOT NULL DEFAULT 'queued',
 message VARCHAR(255) NULL,
 updated_at DATETIME NOT NULL,
 INDEX idx_dc_lvc_queue(state,updated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
echo "Cola de conversiones v20.10.30 disponible.\n";
