<?php
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require __DIR__.'/config.php';
$pdo->exec("CREATE TABLE IF NOT EXISTS dc_youtube_jobs_dcft (
 id CHAR(32) PRIMARY KEY,owner_id BIGINT UNSIGNED NOT NULL,folder_id BIGINT UNSIGNED NOT NULL,youtube_id CHAR(11) NOT NULL,height SMALLINT NOT NULL DEFAULT 720,
 state VARCHAR(16) NOT NULL DEFAULT 'queued',phase VARCHAR(80) NOT NULL DEFAULT 'En cola',progress FLOAT NOT NULL DEFAULT 0,download_percent FLOAT NULL,
 title VARCHAR(300) NOT NULL DEFAULT '',message VARCHAR(1000) NOT NULL DEFAULT '',attempts TINYINT NOT NULL DEFAULT 0,file_id BIGINT UNSIGNED NULL,package_id BIGINT UNSIGNED NULL,
 next_attempt DATETIME NULL,created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 INDEX queue_state(state,next_attempt,created_at),INDEX owner_jobs(owner_id,created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
$pdo->exec("CREATE TABLE IF NOT EXISTS dc_media_progress_dcft (
 owner_id BIGINT UNSIGNED NOT NULL,kind VARCHAR(8) NOT NULL,item_key VARCHAR(32) NOT NULL,position_seconds DOUBLE NOT NULL DEFAULT 0,duration_seconds DOUBLE NOT NULL DEFAULT 0,
 updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,PRIMARY KEY(owner_id,kind,item_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
echo "Media tools tables ready.\n";
