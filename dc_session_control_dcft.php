<?php
declare(strict_types=1);

/** Invalidates all generations of this user's sessions; optionally retains this one.
 * The caller must authenticate the user and verify CSRF before invoking this function.
 */
function dcRevokeOwnSessionsDcft(PDO $pdo, array $user, bool $exceptCurrent): array {
    $uid=(int)$user['id'];
    $version=(int)($_SESSION['dc_session_version']??-1);
    if ($uid<1 || (int)($_SESSION['dc_uid']??0)!==$uid) {
        throw new RuntimeException('La sesión cambió. Vuelve a iniciar sesión.',401);
    }
    $pdo->beginTransaction();
    try {
        // A conditional increment prevents two requests from reviving an invalidated session.
        $q=$pdo->prepare("UPDATE dc_users SET session_version=session_version+1 WHERE id=? AND status='active' AND session_version=?");
        $q->execute([$uid,$version]);
        if ($q->rowCount()!==1) throw new RuntimeException('La sesión cambió. Vuelve a iniciar sesión.',401);
        $hash=hash('sha256',session_id());
        $sql='DELETE FROM dc_user_sessions_79 WHERE user_id=?';
        $args=[$uid];
        if ($exceptCurrent) {$sql.=' AND session_hash<>?';$args[]=$hash;}
        $pdo->prepare($sql)->execute($args);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
    if ($exceptCurrent) $_SESSION['dc_session_version']=$version+1;
    else dcSessionClearDcft();
    return ['success'=>true,'signed_out'=>!$exceptCurrent,'message'=>$exceptCurrent
        ? 'Las otras sesiones de tu cuenta quedaron revocadas. Este navegador sigue conectado.'
        : 'Todas las sesiones de tu cuenta quedaron revocadas, incluida esta.'];
}
