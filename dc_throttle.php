<?php
/**
 * Drive-Craft v20.10.86 — limitación de intentos fallidos.
 * Requiere config.php (PDO) y la tabla dc_login_throttle_86 creada allí.
 */

function dcThrottleClientIp(): string {
    $peer = (string)($_SERVER['REMOTE_ADDR'] ?? '');
    $ip = filter_var($peer, FILTER_VALIDATE_IP) ? $peer : '';
    $trusted = array_filter(array_map('trim', explode(',', (string)(getenv('DC_TRUSTED_PROXY_IPS') ?: '172.18.0.5'))));
    if ($peer !== '' && in_array($peer, $trusted, true)) {
        $real = (string)($_SERVER['HTTP_X_REAL_IP'] ?? '');
        if (filter_var($real, FILTER_VALIDATE_IP)) return $real;
        $forwarded = trim(explode(',', (string)($_SERVER['HTTP_X_FORWARDED_FOR'] ?? ''))[0]);
        if (filter_var($forwarded, FILTER_VALIDATE_IP)) return $forwarded;
    }
    return $ip;
}

function dcThrottleKey(string $scope, string $identity): string {
    return substr($scope, 0, 24).':'.hash('sha256', $identity.'|'.dcThrottleClientIp());
}

function dcThrottleDeny(int $seconds): void {
    $seconds = max(1, $seconds);
    http_response_code(429);
    header('Retry-After: '.$seconds);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode(['error' => 'Demasiados intentos fallidos. Inténtalo de nuevo en '.$seconds.' segundos.'], JSON_UNESCAPED_UNICODE);
    exit;
}

/** Se llama ANTES de verificar la credencial. */
function dcThrottleCheck(PDO $pdo, string $key, int $max = 5, int $window = 900, int $block = 900): void {
    $now = time();
    $st = $pdo->prepare('SELECT failures,first_failure,blocked_until FROM dc_login_throttle_86 WHERE throttle_key=?');
    $st->execute([$key]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    if (!$r) return;
    if ((int)$r['blocked_until'] > $now) dcThrottleDeny((int)$r['blocked_until'] - $now);
    if ($now - (int)$r['first_failure'] > $window) {
        $pdo->prepare('DELETE FROM dc_login_throttle_86 WHERE throttle_key=?')->execute([$key]);
        return;
    }
    if ((int)$r['failures'] >= $max) {
        $pdo->prepare('UPDATE dc_login_throttle_86 SET blocked_until=? WHERE throttle_key=?')->execute([$now + $block, $key]);
        dcThrottleDeny($block);
    }
}

/** Se llama DESPUÉS de una credencial incorrecta. */
function dcThrottleFail(PDO $pdo, string $key, int $max = 5, int $window = 900, int $block = 900): void {
    $now = time();
    $pdo->prepare('INSERT INTO dc_login_throttle_86 (throttle_key,failures,first_failure,last_failure,blocked_until)
                   VALUES (?,1,?,?,0)
                   ON DUPLICATE KEY UPDATE
                     failures=IF(first_failure<?,1,failures+1),
                     first_failure=IF(first_failure<?,VALUES(first_failure),first_failure),
                     last_failure=VALUES(last_failure)')
        ->execute([$key, $now, $now, $now, $now]);
    $st = $pdo->prepare('SELECT failures FROM dc_login_throttle_86 WHERE throttle_key=?');
    $st->execute([$key]);
    if ((int)$st->fetchColumn() >= $max) {
        $pdo->prepare('UPDATE dc_login_throttle_86 SET blocked_until=? WHERE throttle_key=?')->execute([$now + $block, $key]);
    }
}

/** Credencial correcta: reinicia el contador. */
function dcThrottleClear(PDO $pdo, string $key): void {
    $pdo->prepare('DELETE FROM dc_login_throttle_86 WHERE throttle_key=?')->execute([$key]);
}
