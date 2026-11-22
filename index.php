<?php
$dcIsLogin = $_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action']??'')==='login';
try {
    require __DIR__.'/config.php';
    require_once __DIR__.'/auth.php';
    require_once __DIR__.'/dc_throttle.php';
} catch (Throwable $e) {
    error_log('Drivecraft bootstrap: '.$e->getMessage());
    http_response_code(503);
    header('Content-Type: '.($dcIsLogin?'application/json':'text/plain').'; charset=utf-8');
    header('Cache-Control: no-store');
    echo $dcIsLogin ? json_encode(['success'=>false,'error'=>'El servidor de acceso no está disponible. Inténtalo de nuevo más tarde.','code'=>'service_unavailable'],JSON_UNESCAPED_UNICODE) : 'Drive-Craft no está disponible temporalmente. Inténtalo de nuevo más tarde.';
    exit;
}
if ($dcIsLogin) {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    try {
        $dcLoginUser = trim((string)($_POST['username']??''));
        $dcLoginPass = (string)($_POST['password']??'');
        if ($dcLoginUser==='' || $dcLoginPass==='' || strlen($dcLoginUser)>255 || strlen($dcLoginPass)>256) {
            http_response_code(422);
            echo json_encode(['success'=>false,'error'=>'Indica un usuario y una contraseña válidos.','code'=>'invalid_input'],JSON_UNESCAPED_UNICODE);exit;
        }
        $dcThrottleKey = dcThrottleKey('login', $dcLoginUser);
        $dcIpKey = dcThrottleKey('login-ip-dcft','all');
        dcThrottleCheck($pdo,$dcIpKey,20);
        dcThrottleCheck($pdo,$dcThrottleKey);
        $dcReason = null;
        if (!dcLogin($dcLoginUser,$dcLoginPass,$dcReason)) {
            dcThrottleFail($pdo,$dcIpKey,20);
            dcThrottleFail($pdo,$dcThrottleKey);
            $messages=['username_unknown'=>'Ese nombre de usuario no existe. Revisa cómo lo escribiste.','password_invalid'=>'La contraseña es incorrecta. Inténtalo de nuevo.','account_inactive'=>'Tu cuenta no está activa. Contacta al administrador.'];
            http_response_code($dcReason==='account_inactive'?403:401);
            echo json_encode(['success'=>false,'code'=>$dcReason,'error'=>$messages[$dcReason]??'No se pudo iniciar sesión.'],JSON_UNESCAPED_UNICODE);exit;
        }
        dcThrottleClear($pdo,$dcThrottleKey);
        echo json_encode(['success'=>true]);exit;
    } catch (Throwable $e) {
        error_log('Drivecraft login: '.$e->getMessage());
        http_response_code(503);
        echo json_encode(['success'=>false,'code'=>'service_unavailable','error'=>'El servidor no pudo completar el inicio de sesión. Inténtalo de nuevo más tarde.'],JSON_UNESCAPED_UNICODE);exit;
    }
}
// Cierre de sesión solo por POST con CSRF (evita logout por CSRF vía GET/imagen).
if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action']??'')==='logout') {
    $token=(string)($_POST['csrf']??'');
    if ($token==='' || !hash_equals((string)($_SESSION['dc_csrf']??''),$token)) {
        http_response_code(403);
        echo json_encode(['error'=>'Token CSRF inválido']);
        exit;
    }
    try {$pdo->prepare('DELETE FROM dc_user_sessions_79 WHERE user_id=? AND session_hash=?')->execute([(int)($_SESSION['dc_uid']??0),hash('sha256',session_id())]);} catch (Throwable $e) {error_log('Drivecraft logout session tracking unavailable');}
    session_regenerate_id(true);
    $_SESSION=[];
    session_destroy();
    header('Location: index.php');
    exit;
}
$dcCurrent=dcUser();
?>
<!DOCTYPE html>
<html lang="<?= (($_COOKIE['dc_language_dcft']??'es')==='en')?'en':'es' ?>" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Drive-Craft</title>
    <link rel="stylesheet" href="dc_i18n_dcft.css?v=20261121-1">
    <script src="dc_i18n_dcft.js?v=20261121-7"></script>
    <link rel="icon" type="image/png" sizes="32x32" href="favicon-32.png?v=20.1">
    <link rel="icon" type="image/png" sizes="16x16" href="favicon-16.png?v=20.1">
    <link rel="apple-touch-icon" href="favicon-180.png?v=20.1">
    <link rel="stylesheet" href="style.css?v=20.10.86side2">
    <link rel="stylesheet" href="history-badges.css?v=1">
<link rel="stylesheet" href="storage-indicator.css?v=20.5.storage1">
<script>try{const t=localStorage.getItem('dc_theme');document.documentElement.dataset.theme=t==='dark'?'dark':'light';const c=localStorage.getItem('dc_color');if(/^#[0-9a-f]{6}$/i.test(c||''))document.documentElement.style.setProperty('--primary-color',c)}catch(e){}</script>
<link rel="stylesheet" href="dc-audit-ui.css?v=1">
<link rel="stylesheet" href="dc-upload-trash.css?v=1">
<link rel="stylesheet" href="dc-sort-date.css?v=1">
<link rel="stylesheet" href="dc-mobile-fixes.css?v=1">
<link rel="stylesheet" href="dc-gallery.css?v=20.10.86raw1">
<style>.dc-received-visual{position:relative;display:inline-block}.dc-received-badge{position:absolute;right:-5px;bottom:0;border-radius:50%;background:#1972ab;color:#fff;font-size:13px;padding:3px;box-shadow:0 1px 5px #0005}.file-item:has(.dc-received-badge){border-left:3px solid #2986bf}</style><style id="dc-shortcut-card-style">
.dc-shortcut-item{position:relative;cursor:default}
.dc-shortcut-item .dc-shortcut-visual{position:relative;display:flex;align-items:center;justify-content:center;width:100%;min-width:0}
.dc-shortcut-item .dc-shortcut-link-badge{position:absolute;top:0;left:4px;font-size:15px;line-height:1;background:var(--card-bg,#1e293b);border-radius:5px;padding:3px;box-shadow:0 1px 5px #0003;pointer-events:none}
.dc-shortcut-item .file-name{overflow-wrap:anywhere}
</style>
<style id="dc-settings-layout-v201027">
#dcUtilityModal.dc-settings-open .dc-utility-modal{width:min(1200px,95vw)!important;max-width:1200px!important;max-height:94vh;overflow:auto;padding:20px 24px}
#dcUtilityModal.dc-settings-open #dcUtilityTitle{font-size:1.3rem;margin:0 0 14px}
.dc-settings-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px;align-items:start}
.dc-settings-group{min-width:0;border:1px solid var(--border-color,#d3d9e4);border-radius:12px;padding:12px 14px;margin:0!important;background:rgba(125,137,154,.055);display:flex;flex-direction:column;gap:7px}
.dc-settings-group h3{margin:0 0 4px!important;font-size:1rem}
.dc-settings-group label,.dc-settings-group p{margin:0!important;font-size:.89rem;line-height:1.3}
.dc-settings-group input[type=number]{width:95px;max-width:100%}
.dc-settings-group input[type=password]{max-width:100%;box-sizing:border-box}
.dc-settings-group input[type=range]{width:100%}
.dc-help{font-size:.85rem;cursor:help;opacity:.8;border-bottom:1px dotted;display:inline-block}
.dc-settings-actions{display:flex;justify-content:flex-end;padding:12px 0 0;position:sticky;bottom:0;background:var(--card-bg,var(--background-color,#fff))}
@media(max-width:1000px){.dc-settings-grid{grid-template-columns:repeat(2,minmax(0,1fr))}}
@media(max-width:670px){.dc-settings-grid{grid-template-columns:minmax(0,1fr)}#dcUtilityModal.dc-settings-open .dc-utility-modal{width:96vw!important;padding:14px}}
</style>
    <link rel="stylesheet" href="dc-admin-layout-43.css?v=20.10.43">
    <link rel="stylesheet" href="dc-admin-layout-44.css?v=20.10.44">
    <link rel="stylesheet" href="dc-admin-layout-45.css?v=20.10.45">
    <link rel="stylesheet" href="dc-admin-layout-46.css?v=20.10.46">
    <link rel="stylesheet" href="dc-upload-stack-47.css?v=20.10.47">
    <link rel="stylesheet" href="dc-upload-notifications-48.css?v=20.10.48">
    <link rel="stylesheet" href="dc-markdown-49.css?v=20.10.49">
    <style>.dc-hl-row49{display:grid;grid-template-columns:minmax(0,1fr) 145px;gap:12px;align-items:center}.dc-hl-min49{display:flex!important;align-items:center;gap:6px;white-space:nowrap}.dc-hl-min49 input{width:65px!important;min-width:0!important;padding:5px!important}@media(max-width:650px){.dc-hl-row49{grid-template-columns:1fr}.dc-hl-min49{justify-content:flex-end}}</style>
    <link rel="stylesheet" href="dc-fixes-50.css?v=20.10.50">
<link rel="stylesheet" href="dc-hardlinks-51.css?v=20.10.51">
<link rel="stylesheet" href="dc-hardlinks-52.css?v=20.10.52">
<link rel="stylesheet" href="dc-hardlinks-53.css?v=20.10.53">
<link rel="stylesheet" href="dc-layout-54.css?v=20.10.54">
<link rel="stylesheet" href="dc-youtube-modal-55.css?v=20.10.55">
<link rel="stylesheet" href="dc-colores-59.css?v=20.10.59">
<link rel="stylesheet" href="dc_sessions_79.css?v=20261120-session1">
    <?php if (is_array($dcCurrent) && ($dcCurrent['role'] ?? '') === 'admin'): ?><link rel="stylesheet" href="dc_metrics_85.css?v=85"><script defer src="dc_metrics_85.js?v=85"></script><?php endif; ?>
<link rel="stylesheet" href="dc_settings_admin_85b.css?v=85b">
<link rel="stylesheet" href="dc_light_polish_85f.css?v=85f">
<link rel="stylesheet" href="dc_hotfix_85m.css?v=85m1">
<link rel="stylesheet" href="dc_settings_compact_dcft.css?v=20261121-layout3">
<script defer src="dc_login_dcft.js?v=20261120-session1"></script>
<link rel="stylesheet" href="dc_links_polish_dcft.css?v=20261121-1">
</head>
<body class="<?php echo ($dcCurrent && $dcCurrent['role']!=='admin') ? 'dc-limited-user' : ''; ?>">

<?php if (!$dcCurrent || !empty($dcCurrent['must_change_password'])): ?><div class="dc-language-login"><div class="dc-language-picker" role="group" aria-label="Idioma / Language"><button type="button" data-dc-language="es" title="Español — México" aria-label="Español — México" aria-pressed="false"><img src="dc_flag_mx.svg" width="24" height="17" alt=""></button><button type="button" data-dc-language="en" title="English — United States" aria-label="English — United States" aria-pressed="false"><img src="dc_flag_us.svg" width="24" height="17" alt=""></button></div></div><?php endif; ?>
<?php if (!$dcCurrent): ?>
    <div class="login-container">
        <form id="loginForm" class="card">
            <h2>Drive-Craft</h2>
            <input type="text" name="username" placeholder="Usuario" autocomplete="username" required>
            <input type="password" name="password" placeholder="Contraseña" autocomplete="current-password" required>
            <button type="submit" class="btn-primary">Ingresar</button>
            <p id="loginError" role="alert" aria-live="polite" style="color:red; display:none;">Credenciales incorrectas</p>
        </form>
    </div>
<?php else: ?>
    <?php if (!empty($dcCurrent['must_change_password'])): ?>
    <div class="login-container"><form id="dcChangePassword" class="card">
    <h2>Cambio obligatorio de contraseña</h2><p>Antes de continuar, crea una contraseña de al menos 12 caracteres.</p>
    <input type="password" name="old_password" placeholder="Contraseña temporal" autocomplete="current-password" required>
    <input type="password" name="new_password" placeholder="Nueva contraseña (mínimo 12 caracteres)" minlength="12" autocomplete="new-password" required>
    <button class="btn-primary" type="submit">Guardar nueva contraseña</button><p id="dcPasswordError" role="alert"></p>
    </form></div>
    <?php else: ?>
    <?php // Cuentas estándar: explorador privado en modo lectura (v20.3). ?>
    <!-- BACKDROP PARA MÓVILES -->
    <div class="sidebar-backdrop" id="sidebarBackdrop" onclick="toggleSidebar()"></div>

    <div class="app-layout">
        <aside class="sidebar" id="sidebar">
            <div class="brand" id="dcBrand84" role="button" tabindex="0" title="Acerca de Drive-Craft" onclick="dcAbout84()" onkeydown="if(event.key==='Enter'||event.key===' '){event.preventDefault();dcAbout84()}">☁️ Drive-Craft</div>
            <div class="dc-session-user" title="Cuenta conectada">👤 <?php echo htmlspecialchars((string)($dcCurrent['username']??''), ENT_QUOTES|ENT_SUBSTITUTE, 'UTF-8'); ?> <button type="button" id="dc79SessionsCount" class="dc79-session-count" title="Ver mis sesiones e IP aproximadas" aria-label="Ver mis sesiones" hidden>1</button></div>
            <?php if ($dcCurrent['role'] !== 'admin'): ?><div class="dc-readonly-notice" role="status">Mi unidad · Espacio personal</div><?php endif; ?>
            <div class="tree-container" id="folderTree">
                <!-- Arbol de directorios aqui -->
            </div>
            <div class="dc-sidebar-fab-wrap">
              <button type="button" class="dc-sidebar-fab" id="dcSidebarFab" onclick="toggleSidebarFabMenu()" aria-label="Accesos rápidos">☰</button>
              <div class="dc-sidebar-fab-menu" id="dcSidebarFabMenu" aria-hidden="true">
                <button onclick="showSpecial('favorites');toggleSidebarFabMenu(true)">⭐ Favoritos</button>
                <button onclick="showSpecial('recents');toggleSidebarFabMenu(true)">🕘 Recientes</button>
                <button onclick="openTrash();toggleSidebarFabMenu(true)">🗑️ Papelera</button>
                <button type="button" onclick="dciReceived();toggleSidebarFabMenu(true)">👥 Compartido conmigo</button>
                <button onclick="openSettings();toggleSidebarFabMenu(true)">⚙️ Configuración</button>
                <button type="button" onclick="openShareManager();toggleSidebarFabMenu(true)">🔗 Administrar links</button>
                <div style="border-top:1px solid var(--border-color);margin-top:6px;padding-top:6px">
                  <label style="font-size:11px;opacity:.7;display:block;margin-bottom:4px">Estilo visual:</label>
                  <select id="dcThemeSelect" onchange="setDcTheme(this.value)" style="width:100%;padding:6px;border-radius:6px;border:1px solid var(--border-color);background:var(--card-bg);color:var(--text-color);font-size:12px">
                    <option value="classic">Clásico (actual)</option>
                    <option value="glass">Glassmorphism (blur + transparencia)</option>
                    <option value="minimal">Minimal (Notion/Linear)</option>
                  </select>
                </div>
              </div>
            </div>
        </aside>

        <main class="main-content">
            <header class="toolbar">
                <div class="toolbar-inner">
                    <button class="mobile-menu-btn" onclick="toggleSidebar()">☰</button>
                    
                    <div class="search-box">
                        <input type="text" id="searchInput" placeholder="Buscar en Drive-Craft..." onkeyup="handleSearch(event)">
                        <label title="Sensible a mayúsculas"><input type="checkbox" id="searchCaseSensitive"> Aa</label>
                        <label class="dcmt-scope" id="dcmtScope" title="Buscar en esta carpeta y sus subcarpetas"><input type="checkbox" id="dcmtScopeInput"> 📁 aquí</label>
                        <button onclick="performSearch()">🔍</button>
                    </div>


                    <button type="button" id="dcUndo85l" class="icon-btn dc-undo85l" onclick="dcUndoAction85l()" title="No hay acciones para deshacer" aria-label="Deshacer" disabled>↶</button>

                    <div class="actions">
                        <div id="selectionActions" style="display:none; gap:5px; margin-right: 10px; padding-right: 10px; border-right: 1px solid var(--border-color);">
                            <span id="multiSelectCount" style="font-size: 0.9em; margin-right: 5px; color: var(--primary-color); font-weight: bold;"></span>
                            <button class="icon-btn action-btn" onclick="actionBatch('move')" title="Cortar">✂️</button>
                            <button class="icon-btn action-btn" onclick="actionBatch('copy')" title="Copiar">📋</button>
                            <button class="icon-btn action-btn" onclick="event.preventDefault();event.stopPropagation();dcRotateSelection('left')" title="Girar 90° izquierda">↺</button>
                            <button class="icon-btn action-btn" onclick="event.preventDefault();event.stopPropagation();dcRotateSelection('right')" title="Girar 90° derecha">↻</button>
                            <button class="icon-btn action-btn" onclick="actionBatch('share')" title="Compartir">🔗</button>
                            <button class="icon-btn action-btn" onclick="actionBatch('delete')" title="Eliminar" style="color: #dc3545;">🗑️</button>
                        </div>

<div class="dc-language-picker" role="group" aria-label="Idioma / Language"><button type="button" data-dc-language="es" title="Español — México" aria-label="Español — México" aria-pressed="false"><img src="dc_flag_mx.svg" width="24" height="17" alt=""></button><button type="button" data-dc-language="en" title="English — United States" aria-label="English — United States" aria-pressed="false"><img src="dc_flag_us.svg" width="24" height="17" alt=""></button></div>
                        <!-- TOGGLE TEMA -->
                        <div class="view-toggle" title="Tema">
                            <input type="radio" name="themeMode" id="themeLight" value="light">
                            <label for="themeLight">
                                <svg viewBox="0 0 24 24"><path d="M12 7c-2.76 0-5 2.24-5 5s2.24 5 5 5 5-2.24 5-5-2.24-5-5-5zM2 13h2c.55 0 1-.45 1-1s-.45-1-1-1H2c-.55 0-1 .45-1 1s.45 1 1 1zm18 0h2c.55 0 1-.45 1-1s-.45-1-1-1h-2c-.55 0-1 .45-1 1s.45 1 1 1zM11 2v2c0 .55.45 1 1 1s1-.45 1-1V2c0-.55-.45-1-1-1s-1 .45-1 1zm0 18v2c0 .55.45 1 1 1s1-.45 1-1v-2c0-.55-.45-1-1-1s-1 .45-1 1z"/></svg>
                            </label>
                            <input type="radio" name="themeMode" id="themeDark" value="dark">
                            <label for="themeDark">
                                <svg viewBox="0 0 24 24"><path d="M12 3c-4.97 0-9 4.03-9 9s4.03 9 9 9 9-4.03 9-9c0-.46-.04-.92-.1-1.36-.98 1.37-2.58 2.26-4.4 2.26-3.03 0-5.5-2.47-5.5-5.5 0-1.82.89-3.42 2.26-4.4-.44-.06-.9-.1-1.36-.1z"/></svg>
                            </label>
                        </div>

                        <!-- TOGGLE VISTA -->
                        <div class="view-toggle" title="Vista">
                            <input type="radio" name="viewMode" id="viewList" value="list" onchange="toggleViewMode('list')">
                            <label for="viewList">
                                <svg viewBox="0 0 24 24"><path d="M3 13h2v-2H3v2zm0 4h2v-2H3v2zm0-8h2V7H3v2zm4 4h14v-2H7v2zm0 4h14v-2H7v2zM7 7v2h14V7H7z"/></svg>
                            </label>
                            <input type="radio" name="viewMode" id="viewGrid" value="grid" onchange="toggleViewMode('grid')">
                            <label for="viewGrid">
                                <svg viewBox="0 0 24 24"><path d="M4 11h5V5H4v6zm0 7h5v-6H4v6zm6 0h5v-6h-5v6zm6 0h5v-6h-5v6zm-6-7h5V5h-5v6zm6-6v6h5V5h-5z"/></svg>
                            </label>
                        </div>
                    </div>
                </div>
            </header>
            
            <!-- Barra Pegar -->
            <div id="pasteToolbar" class="paste-toolbar" style="display:none;">
                <span>Portapapeles activo.</span>
                <div>
                    <button onclick="pasteItem()" style="background: #28a745; border:none; padding:5px 10px; border-radius:4px; color:white; font-weight:bold; cursor:pointer;">Pegar aquí</button>
                    <button onclick="cancelPaste()" style="background: transparent; border: 1px solid white; padding:5px 10px; border-radius:4px; color:white; cursor:pointer; margin-left:5px;">Cancelar</button>
                </div>
            </div>

            <!-- Cabecera de Navegación Drive-Style y Botones Primarios -->
            <div class="drive-header-actions">
                <button type="button" class="nav-up" id="navUpButton" onclick="goUp()" title="Subir al directorio superior" aria-label="Subir al directorio superior"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M20 18H10a5 5 0 0 1-5-5V5m0 0 5 5M5 5 0 10"/></svg></button>
                <div id="breadcrumb" class="breadcrumb-drive">
                    <!-- Se llenará vía JS -->
                </div>
                
                <div class="main-buttons">
                    <input type="file" id="fileInput" multiple style="display:none;">
                    <button class="btn-primary btn-outline" onclick="createFolder()">+ Carpeta</button>
                    <button class="btn-primary btn-outline" type="button" onclick="dcNewTextNote()">📝 Nota</button>
                    <button class="btn-primary" onclick="document.getElementById('fileInput').click()">+ Subir</button>
                    <button type="button" class="btn-primary btn-outline" onclick="dcImportShareLink()" title="Guardar un archivo de enlace compartido de Drive-Craft">🔗 Guardar enlace</button>
                    
                    <!-- Boton de Logout convertido a icono circular limpio en la esquina derecha -->
                    <form method="post" action="index.php" style="display:inline;margin:0">
                        <input type="hidden" name="action" value="logout">
                        <input type="hidden" name="csrf" value="<?= htmlspecialchars((string)($_SESSION['dc_csrf']??''), ENT_QUOTES, 'UTF-8') ?>">
                        <button type="submit" class="avatar-logout" title="Cerrar Sesión" style="border:0;padding:0;cursor:pointer">
                            <svg viewBox="0 0 24 24" fill="currentColor"><path d="M17 7l-1.41 1.41L18.17 11H8v2h10.17l-2.58 2.58L17 17l5-5zM4 5h8V3H4c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h8v-2H4V5z"/></svg>
                        </button>
                    </form>
                </div>
            </div>

            <div id="dropZone" class="drop-zone" onclick="clearSelection()">
                
                <div class="list-header dc-sort-header" id="listHeader" style="display:none;">
                    <button type="button" class="dc-sort-button dc-sort-name" data-dc-sort="name" onclick="dcSetSort('name')" title="Ordenar por nombre">Nombre <span class="dc-sort-arrow"></span></button>
                    <button type="button" class="dc-sort-button dc-sort-type" data-dc-sort="type" onclick="dcSetSort('type')" title="Ordenar por tipo">Tipo <span class="dc-sort-arrow"></span></button>
                    <button type="button" class="dc-sort-button dc-sort-size" data-dc-sort="size" onclick="dcSetSort('size')" title="Ordenar por tamaño">Tamaño <span class="dc-sort-arrow"></span></button>
                    <button type="button" class="dc-sort-button dc-sort-date" data-dc-sort="date" onclick="dcSetSort('date')" title="Ordenar por fecha de incorporación">Agregado <span class="dc-sort-arrow"></span></button>
                </div>

                <div id="fileGrid" class="file-view-grid">
                    <!-- Archivos renderizados aqui -->
                </div>
            </div>

            <div id="uploadProgress" class="upload-progress" style="display:none;">
                <span id="uploadStatusText">Subiendo...</span>
                <progress id="progressBar" value="0" max="100"></progress>
            </div>
            
            <div id="diskSpaceWatermark" class="disk-watermark"></div>
            <button type="button" id="dcStorageRestore" class="dc-storage-restore" onclick="dcShowDiskIndicator()" title="Mostrar indicador de almacenamiento" aria-label="Mostrar indicador de almacenamiento" hidden>◉ Espacio</button>

        </main>
    </div>

    <div id="dcUtilityModal" class="modal"><div class="modal-content dc-utility-modal">
        <span class="close" onclick="closeModal('dcUtilityModal')">&times;</span>
        <h3 id="dcUtilityTitle">Drive-Craft</h3>
        <div id="dcUtilityBody"></div>
    </div>
    </div>
    <!-- Modales -->
    <div id="viewerModal" class="modal">
        <div class="modal-content" style="max-width: 900px; width: 90%;" id="viewerModalContent">
            <span class="close" onclick="closeModal('viewerModal')">&times;</span>
            <h3 id="viewerTitle">Visor</h3>
            
            <div id="viewerToolbar" style="display:none; margin-bottom: 15px; background: var(--hover-bg); padding: 10px; border-radius: 6px; align-items:center; gap:10px;">
                <input type="text" id="viewerSearchInput" placeholder="Filtrar contenido..." onkeyup="filterViewer()" style="flex:1;">
                <label style="font-size:14px; cursor: pointer; display:flex; align-items:center; gap:5px;">
                    <input type="checkbox" id="viewerCaseSensitive" onchange="filterViewer()"> Aa
                </label>
            </div>

            <div id="viewerBody"></div>
        </div>
    </div>
    
    <div id="shareManagerModal" class="modal">
        <div class="modal-content share-manager-modal">
            <span class="close" onclick="closeModal('shareManagerModal')">&times;</span>
            <h3>🔗 Todos los enlaces compartidos</h3>
            <p class="share-help">Consulta enlaces y estadísticas de visualización y descarga. También administra enlaces para recibir archivos.</p>
            <div class="dc-share-columns60">
                <section class="dc-share-column60" aria-label="Enlaces generados">
                    <div class="dc-share-toolbar60"><h4>Enlaces generados</h4><button id="dcRevokeAll60" type="button" onclick="dcRevokeAllShares60()">Revocar todos</button></div>
                    <input type="search" id="shareManagerSearch" placeholder="Buscar archivo o carpeta..." oninput="renderShareManager()" class="share-search">
                    <div id="shareManagerList" class="share-manager-list"></div>
                </section>
                <section class="dc-share-column60" aria-label="Enlaces de recepción">
                    <div class="dc-share-toolbar60"><h4>Enlaces de recepción</h4><button class="btn-primary" type="button" onclick="refreshReceiveLinks()">Actualizar</button></div>
                    <div id="receiveLinksList" class="share-manager-list"></div>
                </section>
            </div>
        </div>
    </div>

    <div id="shareModal" class="modal">
        <div class="modal-content">
            <span class="close" onclick="closeModal('shareModal')">&times;</span>
            <h3>Gestionar Enlaces Compartidos</h3>
            
            <div id="activeSharesContainer" style="background: var(--bg-color); padding: 15px; border-radius: 8px; margin-bottom: 20px;">
                <h4 style="margin-top:0;">Enlaces Activos:</h4>
                <ul id="activeSharesList" style="list-style: none; padding: 0; margin: 0; max-height: 150px; overflow-y: auto;"></ul>
            </div>

            <hr style="border: 0; border-top: 1px solid var(--border-color); margin: 20px 0;">
            
            <h4 style="margin-top:0;">Crear Nuevo Enlace:</h4>
            <div class="share-duration"><label>Duración desde ahora (todo en cero = sin caducidad)</label><div class="share-duration-fields"><label>Días<input type="number" id="shareDays" min="0" max="3650" value="0"></label><label>Horas<input type="number" id="shareHours" min="0" max="23" value="0"></label><label>Minutos<input type="number" id="shareMinutes" min="0" max="59" value="0"></label></div></div>
            <p>Contraseña (opcional): <input type="text" id="sharePassword"></p>
            <button class="btn-primary" onclick="generateShareLink()">Generar Link</button>
            <div class="dc-share-copy-result"><input type="text" id="shareResult" readonly style="width:100%; margin-top:10px; display:none; padding:10px; border: 1px dashed var(--primary-color); background: var(--hover-bg);"><button type="button" id="dcCopyShareGenerated" class="btn-primary" style="display:none" onclick="dcCopyGeneratedShare()">📋 Copiar enlace</button><span id="dcCopyShareStatus" role="status" aria-live="polite"></span></div>
        </div>
    </div>
<?php endif; ?>
<?php endif; ?>
<script>window.DC_AUTH={accountId:<?php echo json_encode((int)($dcCurrent['id']??0)); ?>,csrf:<?php echo json_encode($_SESSION['dc_csrf']??''); ?>,role:<?php echo json_encode($dcCurrent['role']??''); ?>,canShare:<?php echo json_encode(!empty($dcCurrent['can_share'])); ?>,canUpload:<?php echo json_encode(!empty($dcCurrent['can_upload'])); ?>,canDelete:<?php echo json_encode(!empty($dcCurrent['can_delete'])); ?>};</script>
<link rel="stylesheet" href="dc_youtube.css?v=20261121-previewfix1">
<link rel="stylesheet" href="dc_attachments.css?v=20.10.31">
<script src="dc_attachments.js?v=20.10.31"></script>
<script src="dc_local_video.js?v=20261121-i18n1"></script>
<link rel="stylesheet" href="dc_video_collections_65.css?v=20.10.65">
<script src="dc_video_collections_65.js?v=20.10.67"></script>
<link rel="stylesheet" href="dc_collections_67.css?v=20.10.68">
<script defer src="dc_collections_67.js?v=20261121-i18n1"></script>
<script src="dc_youtube.js?v=20261121-i18n1"></script>
<link rel="stylesheet" href="dc_music_81.css?v=20.10.82">
<link rel="stylesheet" href="dc_music_assets_83.css?v=20.10.83b">
<script src="dc_music_assets_83.js?v=20.10.83b"></script>
<script src="dc_music_81.js?v=20261121-fileplay1"></script>
<link rel="stylesheet" href="dc_album_cache_dcft.css?v=20261120-albumcache1">
<script src="dc_album_cache_dcft.js?v=20261121-media-routing1"></script>
<script src="dc_album_covers_dcft.js?v=20261121-hover6"></script>
<link rel="stylesheet" href="dc_text_editor_dcft.css?v=20261120-mermaid1">
<script src="dc_text_editor_dcft.js?v=20261121-mediatools1"></script>
<link rel="stylesheet" href="dc_save_feedback_dcft.css?v=20261121-1">
<link rel="stylesheet" href="dc_media_tools_dcft.css?v=20261121-layout2">
<script src="dc_save_feedback_dcft.js?v=20261121-i18n1"></script>
<script src="script.js?v=20261121-fileplay1"></script>
<script src="dc_internal_shares.js?v=20.10.61"></script>
<script src="admin_ui.js?v=20261121-i18n1"></script>
<link rel="stylesheet" href="dc_share_badges.css?v=20.10.19">
<script src="dc_share_badges.js?v=20.10.19"></script>
<link rel="stylesheet" href="dc_folder_styles.css?v=20261121-hover5">
<script src="dc_folder_styles.js?v=20261120-coverframes1"></script>
<script src="dc_live_shares.js?v=20.10.20"></script>
<script src="dc-markdown-49.js?v=20.10.49"></script>
<script src="dc-layout-54.js?v=20261121-i18n1"></script>
<link rel="stylesheet" href="dc_theme85g.css?v=20.10.85g">
<link rel="stylesheet" href="dc-share-panel-60.css?v=201060">
<script src="dc-share-panel-60.js?v=201060"></script>
<link rel="stylesheet" href="dc-internal-preview-61.css?v=201061">
<script defer src="dc_sessions_79.js?v=20261120-session1"></script>
<link rel="stylesheet" href="dc_about_84.css?v=1">
<script src="dc_about_84.js?v=1" defer></script>
<link rel="stylesheet" href="dc_folder_titles_dcft.css?v=20261120-foldertitle1">
<script src="dc_external_panel_dcft.js?v=20261121-audio1"></script>
<script src="dc_media_tools_dcft.js?v=20261121-audio1"></script>
<link rel="stylesheet" href="dc_context_viewport_dcft.css?v=20261121-1">
<script src="dc_context_viewport_dcft.js?v=20261121-1"></script>
<script src="dc_youtube_audio_dcft.js?v=20261121-1"></script>
</body>
</html>
