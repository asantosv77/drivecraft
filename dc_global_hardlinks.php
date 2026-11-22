<?php
/** Hardlinks globales Drive-Craft. Solo archivos recibidos nuevos, nunca escritura in-place.
 * El nombre, rutas o datos de otros usuarios NO se devuelven a los clientes.
 */
function dcGlobalHardlinkSettings(PDO $pdo): array {
    $keys=['global_hardlinks_enabled','global_hardlinks_min_mib','global_hardlinks_groups','global_hardlinks_min_by_group'];
    $q=$pdo->prepare('SELECT setting_key,setting_value FROM dc_app_settings WHERE setting_key IN (?,?,?,?)');
    $q->execute($keys);$v=$q->fetchAll(PDO::FETCH_KEY_PAIR);
    $allowed=['audio','video','images','disk','installers','archives','pdf','documents','textcode'];
    $groups=explode(',',(string)($v['global_hardlinks_groups']??'audio,video,disk,installers,archives'));
    $defaults=['audio'=>1,'video'=>100,'images'=>0,'disk'=>100,'installers'=>10,'archives'=>10,'pdf'=>1,'documents'=>1,'textcode'=>0];
    $saved=json_decode((string)($v['global_hardlinks_min_by_group']??''),true);
    $minimums=[];
    foreach ($defaults as $id=>$default) {
      $legacy=(int)($v['global_hardlinks_min_mib']??100);
      $value=is_array($saved)&&isset($saved[$id]) ? $saved[$id] : ($id==='images' ? 0 : $legacy);
      $minimums[$id]=is_numeric($value)?max(0,min(10240,(int)$value)):$default;
    }
    return ['min_by_group'=>$minimums,'enabled'=>($v['global_hardlinks_enabled']??'0')==='1',
        'min_mib'=>max(1,min(10240,(int)($v['global_hardlinks_min_mib']??100))),
        'groups'=>array_values(array_intersect($allowed,$groups))];
}
function dcGlobalHardlinkGroup(string $name): ?string {
    $ext=strtolower(pathinfo($name,PATHINFO_EXTENSION));
    foreach([
        'audio'=>['mp3','flac','wav','m4a','aac','ogg'],
        'video'=>['mp4','mkv','avi','mov','webm','mpeg','mpg'],
        'images'=>['jpg','jpeg','png','gif','webp','avif','bmp','tif','tiff'],
        'disk'=>['iso','img','bin'],
        'installers'=>['exe','msi','dmg','appimage'],
        'archives'=>['zip','7z','rar','tar','gz','tgz','zst','xz','bz2'],
        'pdf'=>['pdf'],
        'documents'=>['docx','xlsx','pptx','odt'],
        'textcode'=>['txt','log','php','js','mjs','cjs','ts','tsx','jsx','yaml','yml','html','htm','java','md','markdown','css','json','xml','sql','py','sh','bash','zsh','c','h','cpp','hpp','cs','go','rs','rb','pl','ini','conf','cfg','toml','properties','csv','tsv']
    ] as $group=>$exts)if(in_array($ext,$exts,true))return $group;
    return null;
}
/**
 * Consolidacion fisica posterior a la subida. El digest se obtiene de bytes
 * recibidos y verificados en el servidor, jamas de una declaracion del cliente.
 * No habilita adquisicion de archivos ajenos por conocer su hash.
 *
 * Los enlaces duros no impiden escrituras in-place: cualquier nueva ruta que
 * edite ficheros debe usar reemplazo atomico (copy-on-write).
 *
 * @param string|null $digest      content_hash (sha256 lineal o merkle root)
 * @param string      $algorithm   'sha256' | 'merkle'
 * @param string|null $headHash    SHA-256 de los primeros 2 MiB (prefiltro opcional)
 */
function dcGlobalHardlinkTry(PDO $pdo,string $root,string $path,string $name,int $size,?string $digest=null,string $algorithm='sha256',?string $headHash=null): bool {
    $settings=dcGlobalHardlinkSettings($pdo);
    $group=dcGlobalHardlinkGroup($name);
    if (!$settings['enabled'] || !$group || !in_array($group,$settings['groups'],true)
        || $size<($settings['min_by_group'][$group]??$settings['min_mib'])*1048576 || !is_file($path) || is_link($path)) return false;
    if (!is_string($digest) || !preg_match('/^[a-f0-9]{64}$/D',$digest)) {
        $digest=@hash_file('sha256',$path);
        $algorithm='sha256';
    }
    if (!is_string($digest) || !preg_match('/^[a-f0-9]{64}$/D',$digest)) return false;
    if ($algorithm!=='merkle') $algorithm='sha256';
    if (is_string($headHash) && !preg_match('/^[a-f0-9]{64}$/D',$headHash)) $headHash=null;

    $lock='dc-global-hardlink-'.substr($digest,0,32);
    $lk=$pdo->prepare('SELECT GET_LOCK(?,10)');$lk->execute([$lock]);
    if ((int)$lk->fetchColumn()!==1) return false;
    try {
        if ($headHash!==null) {
            $q=$pdo->prepare('SELECT storage_name,content_hash_algorithm FROM files WHERE is_dir=0 AND size=? AND content_hash_algorithm=? AND content_hash=? AND head_hash=? LIMIT 30');
            $q->execute([$size,$algorithm,$digest,$headHash]);
        } else {
            $q=$pdo->prepare('SELECT storage_name,content_hash_algorithm FROM files WHERE is_dir=0 AND size=? AND content_hash_algorithm=? AND content_hash=? LIMIT 30');
            $q->execute([$size,$algorithm,$digest]);
        }
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $stored=(string)($row['storage_name']??'');
            $candAlgo=(string)($row['content_hash_algorithm']??'sha256');
            if ($stored==='' || basename($stored)!==$stored
                || !preg_match('/^[a-zA-Z0-9_.-]+$/D',$stored)) continue;
            $candidate=$root.$stored;
            if ($candidate===$path || !is_file($candidate) || is_link($candidate)) continue;
            clearstatcache(true,$candidate);clearstatcache(true,$path);
            $before=@stat($candidate);$new=@stat($path);
            if (!$before || !$new || $before['size']!==$size || $new['size']!==$size
                || $before['dev']!==$new['dev']) continue;

            if ($candAlgo==='merkle') {
                // Digest calculado en servidor durante la subida; confiar en índice + size + head.
            } else {
                if (@hash_file('sha256',$candidate)!==$digest) continue;
            }

            clearstatcache(true,$candidate);
            $after=@stat($candidate);
            if (!$after || $after['dev']!==$before['dev'] || $after['ino']!==$before['ino']
                || $after['size']!==$before['size'] || $after['mtime']!==$before['mtime']
                || $after['ctime']!==$before['ctime']) continue;
            $shadow=$path.'.hlink.'.bin2hex(random_bytes(8));
            if (!@link($candidate,$shadow)) continue;
            try {
                clearstatcache(true,$shadow);clearstatcache(true,$candidate);
                $linked=@stat($shadow);$still=@stat($candidate);
                if (!$linked || !$still || $linked['dev']!==$after['dev']
                    || $linked['ino']!==$after['ino'] || $linked['size']!==$size
                    || $still['dev']!==$after['dev'] || $still['ino']!==$after['ino']) continue;
                if (!@rename($shadow,$path)) continue;
                return true;
            } finally {
                if (is_file($shadow)) @unlink($shadow);
            }
        }
        return false;
    } catch (Throwable $e) {
        error_log('Drive-Craft hardlinks: '.$e->getMessage());
        return false;
    } finally {
        try {$pdo->prepare('SELECT RELEASE_LOCK(?)')->execute([$lock]);} catch (Throwable $ignored) {}
    }
}
