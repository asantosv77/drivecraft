<?php
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require __DIR__.'/config.php';
$columns=$pdo->query('SHOW COLUMNS FROM dc_youtube_jobs_dcft')->fetchAll(PDO::FETCH_COLUMN);
foreach(['media_kind'=>"VARCHAR(8) NOT NULL DEFAULT 'video'",'audio_format'=>"VARCHAR(8) NOT NULL DEFAULT 'opus'",'audio_bitrate'=>'SMALLINT NOT NULL DEFAULT 128'] as $name=>$definition)if(!in_array($name,$columns,true))$pdo->exec('ALTER TABLE dc_youtube_jobs_dcft ADD COLUMN '.$name.' '.$definition);
$pdo->exec("CREATE TABLE IF NOT EXISTS dc_audio_sources_dcft(file_id BIGINT UNSIGNED PRIMARY KEY,owner_id BIGINT UNSIGNED NOT NULL,source_url VARCHAR(200) NOT NULL,format VARCHAR(8) NOT NULL,bitrate_kbps SMALLINT NOT NULL,created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,INDEX owner_audio(owner_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
echo "Audio queue schema ready. Existing jobs remain video jobs.\n";
