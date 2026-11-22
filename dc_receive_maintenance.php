<?php
// Drive-Craft: supervisión segura de fragmentos de recepción pública.
// Ninguna operación de mantenimiento toca la cuarentena manual.
const DC_RX_IDLE_SECONDS = 7200;
function dcRxRoot(string $storage): string {return rtrim($storage,'/').'/dc_receive_parts';}
function dcRxDirs(string $root): array {return glob($root.'/*',GLOB_ONLYDIR) ?: [];}
function dcRxStamp(string $dir): int {
    $state=$dir.'/state.json';
    $st=@filemtime($state) ?: @filemtime($dir) ?: 0;
    // Compatibilidad con sesiones anteriores que no registraban actividad.
    foreach (glob($dir.'/part_*') ?: [] as $f) {if(is_file($f) && !is_link($f)) $st=max($st,(int)@filemtime($f));}
    return $st;
}
function dcRxRemoveLocked(string $dir): bool {
    foreach (glob($dir.'/*') ?: [] as $path) {
        if (!is_file($path) || is_link($path) || !@unlink($path)) return false;
    }
    return @rmdir($dir);
}
function dcRxSweep(string $root, bool $cleanup=false): array {
    $result=['total'=>0,'active'=>0,'expired'=>0,'removed'=>0,'busy'=>0,'bytes'=>0,'expired_bytes'=>0,'quarantine'=>0];
    foreach(dcRxDirs($root) as $dir) {
        if(!preg_match('/^[a-f0-9]{64}$/D',basename($dir)) || is_link($dir)) continue;
        $result['total']++;
        $bytes=0;
        foreach(glob($dir.'/*')?:[] as $item) if(is_file($item) && !is_link($item))$bytes+=(int)@filesize($item);
        $result['bytes']+=$bytes;
        $lock=@fopen($dir.'/lock','c');
        if(!$lock || !@flock($lock,LOCK_EX|LOCK_NB)) {$result['busy']++;if($lock)fclose($lock);continue;}
        try {
            clearstatcache(true);
            $expired=time()-dcRxStamp($dir)>=DC_RX_IDLE_SECONDS;
            if($expired) {
                $result['expired']++;$result['expired_bytes']+=$bytes;
                if($cleanup && dcRxRemoveLocked($dir)) $result['removed']++;
            } else $result['active']++;
        } finally {@flock($lock,LOCK_UN);fclose($lock);}
    }
    $quarantine=dirname($root).'/dc_receive_parts_cuarentena';
    foreach(dcRxDirs($quarantine) as $dir)if(preg_match('/^[a-f0-9]{64}$/D',basename($dir)))$result['quarantine']++;
    return $result;
}
