<?php
// CLI: ejecutar periódicamente. Nunca elimina transferencias con lock activo.
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require __DIR__.'/config.php';
require __DIR__.'/dc_receive_maintenance.php';
$report=dcRxSweep(dcRxRoot($STORAGE_DIR),true);
printf("Temporales retirados: %d; activos: %d; ocupados: %d; vencidos restantes: %d\n",$report['removed'],$report['active'],$report['busy'],$report['expired']-$report['removed']);
