<?php
// v20.10.85M: una sola clasificación especial por carpeta y usuario.
function dc85mModes(PDO $pdo,int $uid,int $folder):array {
    $q=$pdo->prepare('SELECT is_album FROM files WHERE id=? AND owner_id=? AND is_dir=1 AND deleted_at IS NULL');
    $q->execute([$folder,$uid]);$album=$q->fetchColumn();
    if($album===false)return ['album'=>false,'music'=>false,'youtube'=>false,'video'=>false];
    $m=['album'=>(bool)$album,'music'=>false,'youtube'=>false,'video'=>false];
    foreach(['music'=>'dc_music_libraries_81','youtube'=>'dc_youtube_folders','video'=>'dc_video_collections_65'] as $key=>$table) {
        // Tablas presentes en 85L-HF1; no aceptamos nombres desde peticiones externas.
        $st=$pdo->prepare('SELECT 1 FROM `'.$table.'` WHERE folder_id=? AND owner_id=? LIMIT 1');
        $st->execute([$folder,$uid]);$m[$key]=(bool)$st->fetchColumn();
    }
    return $m;
}
function dc85mConflict(PDO $pdo,int $uid,int $folder,string $target):bool {
    foreach(dc85mModes($pdo,$uid,$folder) as $key=>$enabled)if($key!==$target&&$enabled)return true;
    return false;
}
