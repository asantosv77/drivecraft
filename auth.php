<?php
// Depende de config.php (PDO + session_start). Inclúyelo únicamente después de config.php.
/** Duraciones de sesión permitidas (días). Rutina aislada con sufijo _dcft. */
function dcSessionDaysAllowedDcft(): array { return [30,60,90,180,360]; }
function dcSessionDaysDcft(array $user): int {
    $days = (int)($user['session_days_dcft'] ?? 30);
    return in_array($days, dcSessionDaysAllowedDcft(), true) ? $days : 30;
}
/** Borra los datos de autenticación de Drive-Craft sin destruir el ID de sesión. */
function dcSessionClearDcft(): void {
    unset(
        $_SESSION['dc_uid'],
        $_SESSION['dc_session_version'],
        $_SESSION['logged_in'],
        $_SESSION['dc_csrf'],
        $_SESSION['dc_expires_at_dcft'],
        $_SESSION['dc_renewed_at_dcft']
    );
}
function dcUser(bool $activity = true): ?array {
    global $pdo;
    $id = (int)($_SESSION['dc_uid'] ?? 0);
    $version = (int)($_SESSION['dc_session_version'] ?? 0);
    if ($id < 1) return null;
    $st = $pdo->prepare('SELECT * FROM dc_users WHERE id=? AND status=? AND session_version=?');
    $st->execute([$id,'active',$version]);
    $u = $st->fetch(PDO::FETCH_ASSOC);
    if (!$u) { dcSessionClearDcft(); return null; }
    // Vencimiento configurable por usuario (_dcft), deslizante por actividad.
    $days = dcSessionDaysDcft($u);
    $now = time();
    $ttl = $days * 86400;
    $expires = (int)($_SESSION['dc_expires_at_dcft'] ?? 0);
    if ($expires > 0 && $now > $expires) {
        // Caducó por inactividad: exigir credenciales de nuevo.
        dcSessionClearDcft();
        return null;
    }
    // Renovar como máximo cada 5 minutos para no escribir la sesión en cada petición.
    $renewed = (int)($_SESSION['dc_renewed_at_dcft'] ?? 0);
    if ($activity && ($expires <= 0 || $renewed <= 0 || ($now - $renewed) >= 300)) {
        $_SESSION['dc_expires_at_dcft'] = $now + $ttl;
        $_SESSION['dc_renewed_at_dcft'] = $now;
    } elseif ($expires > $now + $ttl) {
        // El usuario redujo la duración: ajustar de inmediato.
        $_SESSION['dc_expires_at_dcft'] = $now + $ttl;
        $_SESSION['dc_renewed_at_dcft'] = $now;
    }
    return $u;
}
function dcLogin(string $username, string $password, ?string &$reason = null): bool {
    global $pdo;
    $st=$pdo->prepare('SELECT * FROM dc_users WHERE username=?');
    $st->execute([$username]); $u=$st->fetch(PDO::FETCH_ASSOC);
    // Verify a dummy hash too, so unknown usernames do not bypass password work.
    $verified = password_verify($password, $u['password_hash'] ?? '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi');
    if (!$u) { $reason = 'username_unknown'; return false; }
    if (!$verified) { $reason = 'password_invalid'; return false; }
    if ($u['status'] !== 'active') { $reason = 'account_inactive'; return false; }
    $reason = null;
    $days = dcSessionDaysDcft($u);
    if (!session_regenerate_id(true)) throw new RuntimeException('No se pudo iniciar la sesión.');
    $_SESSION['dc_uid']=(int)$u['id'];
    $_SESSION['dc_session_version']=(int)$u['session_version'];
    $_SESSION['logged_in']=true;
    $_SESSION['dc_csrf']=bin2hex(random_bytes(32));
    $_SESSION['dc_expires_at_dcft']=time()+$days*86400;
    $_SESSION['dc_renewed_at_dcft']=time();
    if (password_needs_rehash($u['password_hash'], PASSWORD_DEFAULT)) {
        $pdo->prepare('UPDATE dc_users SET password_hash=? WHERE id=?')->execute([password_hash($password,PASSWORD_DEFAULT),$u['id']]);
    }
    return true;
}
function dcFail(int $status=403, string $message='Acceso denegado'): void {
    http_response_code($status);header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error'=>$message]);exit;
}
function dcCsrf(): void {
    $token=(string)($_SERVER['HTTP_X_DC_CSRF'] ?? $_POST['csrf'] ?? '');
    if (!hash_equals((string)($_SESSION['dc_csrf']??''),$token) || $token==='') dcFail(403,'Token CSRF inválido');
}
function dcRequireUser(bool $activity = true): array {
    $u=dcUser($activity);if (!$u) dcFail(401,'Sesión no válida');return $u;
}
function dcRequireAdmin(): array {
    $u=dcRequireUser();if($u['role']!=='admin')dcFail(403,'Requiere administrador');return $u;
}
function dcPasswordPolicy(string $p): bool {return strlen($p)>=12 && strlen($p)<=256;}
