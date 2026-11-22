function setDcTheme(theme){
  if (!['classic','glass','minimal'].includes(theme)) theme = 'classic';
  document.documentElement.dataset.dcTheme = theme;
  localStorage.setItem('dc_visual_theme', theme);
  const sel = document.getElementById('dcThemeSelect');
  if (sel) sel.value = theme;
}
(function initDcTheme(){ const t = localStorage.getItem('dc_visual_theme') || 'classic'; setDcTheme(t); })();
// API private requests include CSRF. Public links and login remain unchanged.
const dcOriginalFetch = window.fetch.bind(window);
window.fetch = function(resource, options={}) {
 const url=typeof resource==='string'?resource:(resource?.url||'');
 if (/^(?:\.\/)?api\.php(?:[?#]|$)/.test(url) && String(options.method||'GET').toUpperCase()==='POST') {
  options={...options,headers:{...(options.headers||{}),'X-DC-CSRF':window.DC_AUTH?.csrf||''}};
 }
 return dcOriginalFetch(resource,options);
};

const DEBUG = false;
const dcReadOnlyAccount = window.DC_AUTH?.role === 'user';
const dcCanShare = window.DC_AUTH?.role === 'admin' || window.DC_AUTH?.canShare === true;
const dcCanUpload = window.DC_AUTH?.role === 'admin' || window.DC_AUTH?.canUpload === true;
const dcCanDelete = window.DC_AUTH?.role === 'admin' || window.DC_AUTH?.canDelete === true;
let currentParentId = 0;
let folderStack = [0];
let expandedTreeFolders = new Set([0]);
let allFoldersData = [];
let dcVisiblePhotos = [];
let dcPhotoIndex = -1;
let dcAlbumMode = false;
let dcAlbumPageSize = Number(localStorage.getItem('dc_album_page_size') || 50);
let dcAlbumPage = 1;
let dcAlbumLastParent = 0;
// Cola cooperativa de miniaturas (declarada antes de loadFiles() para evitar TDZ)
const dcImgQueue = [];
let dcImgActive = 0;
let dcImgEpoch = 0;
let dcImgObserver = null;
const dcImgRetryTimers = new Set();
let dcFolderLoadSequence=0,dcFolderLoadController=null,dcRenderedFolderDcft=null,dcFolderLoadingDcft=false;
function dcCaptureFolderViewDcft(){
 if(dcRenderedFolderDcft===null||dcFolderLoadingDcft)return;
 window.DCAlbumViews.save(dcRenderedFolderDcft,{page:dcAlbumPage,pageSize:dcAlbumPageSize,mode:dcAlbumArtMode,bookPage:dcAlbumBookPage,windowY:window.scrollY,dropY:document.getElementById('dropZone')?.scrollTop||0});
}
window.addEventListener('pagehide',dcCaptureFolderViewDcft);

let fileIdToShare = null;
const MiB = 1024 * 1024;
const GiB = 1024 * MiB;
let dcUploadChunkCapMiB = 50;
let dcUploadChunkEffectiveMiB = 50;
function dcAdaptiveChunkSize(bytes) {
    // Alineado con dcMerkleChunkSize() del servidor:
    // <= 20 MiB → 512 KiB (audio / archivos pequeños)
    // <= 100 MiB → 1 MiB
    // <= 1 GiB → 10 MiB
    // > 1 GiB → tope del admin (50 o 100 MiB)
    const KiB512 = 524288;
    if (bytes <= 20 * MiB) {
        return Math.min(KiB512, Math.max(1, dcUploadChunkEffectiveMiB) * MiB);
    }
    const suggested = bytes <= 100 * MiB ? 1 : bytes <= GiB ? 10 : dcUploadChunkCapMiB;
    return Math.max(1, Math.min(suggested, dcUploadChunkEffectiveMiB)) * MiB;
}
async function dcLoadUploadLimits() {
    try {
        const response=await fetch('api.php?action=settings',{cache:'no-store'});
        if (!response.ok) return;
        const settings=await response.json();
        dcUploadChunkCapMiB=Number(settings.upload_chunk_max_mib)===100?100:50;
        dcUploadChunkEffectiveMiB=Math.max(1,Number(settings.upload_chunk_effective_mib)||1);
    } catch(e) { dcUploadChunkCapMiB=1;dcUploadChunkEffectiveMiB=1; }
}

function dcBufToHex(buf) {
    return Array.from(new Uint8Array(buf)).map(b=>b.toString(16).padStart(2,'0')).join('');
}
async function dcSha256Raw(arrayBuffer) {
    return dcBufToHex(await crypto.subtle.digest('SHA-256', arrayBuffer));
}
/** head_hash: SHA-256 de los primeros 2 MiB (alineado con DC_MERKLE_HEAD_BYTES). */
async function dcHeadHashFile(file) {
    const headBytes = Math.min(file.size, 2 * MiB);
    const buf = await file.slice(0, headBytes).arrayBuffer();
    return dcSha256Raw(buf);
}
/** Hoja Merkle: SHA-256(0x00 || chunk) — igual que dcMerkleLeafFile en PHP. */
async function dcMerkleLeafBuffer(arrayBuffer) {
    const src = new Uint8Array(arrayBuffer);
    const out = new Uint8Array(1 + src.length);
    out[0] = 0;
    out.set(src, 1);
    return new Uint8Array(await crypto.subtle.digest('SHA-256', out));
}
/** Merkle root binario a partir de hojas binarias (misma lógica que PHP). */
async function dcMerkleRootFromLeafBytes(leaves) {
    if (!leaves.length) return dcBufToHex(await crypto.subtle.digest('SHA-256', new Uint8Array([0])));
    let level = leaves.slice();
    while (level.length > 1) {
        const next = [];
        for (let i = 0; i < level.length; i += 2) {
            if (i + 1 < level.length) {
                const a = level[i], b = level[i + 1];
                const cat = new Uint8Array(1 + a.length + b.length);
                cat[0] = 1;
                cat.set(a, 1);
                cat.set(b, 1 + a.length);
                next.push(new Uint8Array(await crypto.subtle.digest('SHA-256', cat)));
            } else {
                next.push(level[i]);
            }
        }
        level = next;
    }
    return dcBufToHex(level[0]);
}
/** Calcula Merkle root del archivo con el mismo tamaño de chunk que el servidor. */
async function dcMerkleRootFile(file, onProgress) {
    const chunkSize = dcAdaptiveChunkSize(file.size);
    const total = Math.max(1, Math.ceil(file.size / chunkSize));
    const leaves = [];
    for (let i = 0; i < total; i++) {
        const start = i * chunkSize;
        const end = Math.min(start + chunkSize, file.size);
        const buf = await file.slice(start, end).arrayBuffer();
        leaves.push(await dcMerkleLeafBuffer(buf));
        if (onProgress) onProgress(Math.round(((i + 1) / total) * 100));
    }
    return dcMerkleRootFromLeafBytes(leaves);
}

 

let clipBoard = { action: null, ids: [] };
let isSearching = false;
let currentViewerLines = [];
let dcViewerLanguage = null;
let dcViewerIsArchive = false;

let viewMode = localStorage.getItem('dc_viewmode') || 'grid';
// Orden compartido entre cuadrícula y lista; preferencia local del navegador.
const dcSortFields = new Set(['name','type','size','date']);
let dcSortField = dcSortFields.has(localStorage.getItem('dc_sort_field')) ? localStorage.getItem('dc_sort_field') : 'date';
let dcSortAscending = localStorage.getItem('dc_sort_ascending') === '1'; // Inicial: más recientes primero.
function dcSetSort(field) {
    if (!dcSortFields.has(field)) return;
    if (dcSortField === field) dcSortAscending = !dcSortAscending;
    else { dcSortField = field; dcSortAscending = field !== 'date'; }
    localStorage.setItem('dc_sort_field', dcSortField);
    localStorage.setItem('dc_sort_ascending', dcSortAscending ? '1' : '0');
    if (isSearching && document.getElementById('searchInput')?.value.trim()) performSearch();
    else if (isSearching) showSpecial(window.dcCurrentSpecial || 'favorites');
    else loadFiles();
}
function dcSortFiles(files) {
    const collator = new Intl.Collator('es', {numeric:true, sensitivity:'base'});
    return [...(Array.isArray(files) ? files : [])].sort((a,b) => {
        // Ordenar por nombre o tipo: siempre carpetas primero, alfabéticas entre sí;
        // en tipo, después agrupar archivos por tipo y alfabetizarlos dentro de cada tipo.
        // La dirección elegida invierte el orden dentro de cada grupo,
        // sin desplazar las carpetas debajo de los archivos.
        if (dcSortField === 'type' || dcSortField === 'name') {
            const aDir = Number(a.is_dir) === 1;
            const bDir = Number(b.is_dir) === 1;
            if (aDir !== bDir) return aDir ? -1 : 1;
            const direction = dcSortAscending ? 1 : -1;
            if (!aDir && dcSortField === 'type') {
                const typeCmp = collator.compare(dcCompactFileType(a), dcCompactFileType(b));
                if (typeCmp) return direction * typeCmp;
            }
            const nameCmp = collator.compare(String(a.name || ''), String(b.name || ''));
            return nameCmp ? direction * nameCmp : Number(a.id) - Number(b.id);
        }
        let cmp=0;
        if(dcSortField === 'size') cmp=Number(a.size || 0)-Number(b.size || 0);
        else if(dcSortField === 'date') cmp=dcDateEpoch(a.created_at)-dcDateEpoch(b.created_at);
        else cmp=collator.compare(String(a.name||''), String(b.name||''));
        if (cmp) return dcSortAscending ? cmp : -cmp;
        return collator.compare(String(a.name||''),String(b.name||'')) || Number(a.id)-Number(b.id);
    });
}
// MariaDB devuelve la fecha en hora local del servidor, sin zona horaria.
// Evitamos convertirla con UTC para no alterar día/hora en la interfaz.
function dcDateEpoch(value) {
    const text=String(value||'');
    const m=text.match(/^(\d{4})-(\d\d)-(\d\d)[ T](\d\d):(\d\d)(?::(\d\d))?/);
    return m ? new Date(+m[1],+m[2]-1,+m[3],+m[4],+m[5],+(m[6]||0)).getTime() || 0 : 0;
}
function dcDateLabel(value) {
    const d=dcDateEpoch(value);
    if(!d) return '—';
    const dt=new Date(d);
    return new Intl.DateTimeFormat('es-MX',{day:'2-digit',month:'2-digit',year:'numeric',hour:'2-digit',minute:'2-digit',hour12:false}).format(dt);
}
function dcUpdateSortHeaders() {
    document.querySelectorAll('[data-dc-sort]').forEach(el=>{
        const active = el.dataset.dcSort === dcSortField;
        el.setAttribute('aria-sort',active ? (dcSortAscending?'ascending':'descending') : 'none');
        const arrow = el.querySelector('.dc-sort-arrow');
        if(arrow) arrow.textContent = active ? (dcSortAscending?'▲':'▼') : '';
    });
}

// Preferencia por navegador; el valor no necesita cambios en MariaDB.
const DEFAULT_ICON_SIZE = 28;
function getSavedIconSize() {
    const value = Number(localStorage.getItem('dc_icon_size'));
    return Number.isFinite(value) && value >= 20 && value <= 72 ? Math.round(value / 2) * 2 : DEFAULT_ICON_SIZE;
}
function setIconSize(size, persist = true) {
    const px = Math.max(20, Math.min(72, Math.round(Number(size) / 2) * 2));
    const grid = document.getElementById('fileGrid');
    if (grid) grid.style.setProperty('--dc-icon-size', `${px}px`);
    const slider = document.getElementById('iconSizeRange');
    const output = document.getElementById('iconSizeValue');
    if (slider) slider.value = String(px);
    if (output) output.textContent = `${px} px`;
    if (persist) localStorage.setItem('dc_icon_size', String(px));
}

let selectedFiles = new Set();
let lastClickedId = null;

if (document.getElementById('fileGrid')) {
    if(viewMode === 'grid') document.getElementById('viewGrid').checked = true;
    else document.getElementById('viewList').checked = true;
    applyViewMode();
    setIconSize(getSavedIconSize(), false);

    initTheming();
    loadTree();
    loadFiles();
    
    const dropZone = document.getElementById('dropZone');
    dropZone.addEventListener('dragover', (e) => { e.preventDefault(); e.stopPropagation(); });
    dropZone.addEventListener('drop', (e) => {
        e.preventDefault(); e.stopPropagation();
        if (e.dataTransfer.items?.length) dcHandleDroppedItems(e.dataTransfer.items,currentParentId);
        else if (e.dataTransfer.files?.length) handleFiles(e.dataTransfer.files,currentParentId);
    });

    document.getElementById('fileInput').addEventListener('change', (e) => {
        if(e.target.files.length > 0) handleFiles(e.target.files, currentParentId);
    });
    
    // En pantallas táctiles: doble toque sobre la MISMA tarjeta equivale
    // al dblclick de escritorio. Un toque sigue seleccionando el elemento.
    // No interceptar botones, enlaces, formularios ni menús contextuales.
    const fileGrid = document.getElementById('fileGrid');
    let lastTouch = null;
    fileGrid.addEventListener('touchend', (event) => {
        if (event.changedTouches.length !== 1 || event.touches.length !== 0) {
            lastTouch = null;
            return;
        }
        const target = event.target;
        if (!(target instanceof Element) || target.closest('button, a, input, label, .context-menu-drive')) {
            lastTouch = null;
            return;
        }
        const item = target.closest('.file-item');
        if (!item || !fileGrid.contains(item)) { lastTouch = null; return; }
        const touch = event.changedTouches[0];
        const now = Date.now();
        if (lastTouch && lastTouch.item === item && now - lastTouch.time <= 420 &&
            Math.hypot(touch.clientX - lastTouch.x, touch.clientY - lastTouch.y) < 30) {
            event.preventDefault(); // Evita zoom y segundo clic de selección.
            lastTouch = null;
            item.dispatchEvent(new MouseEvent('dblclick', { bubbles: true, cancelable: true }));
        } else {
            lastTouch = { item, time: now, x: touch.clientX, y: touch.clientY };
        }
    }, { passive: false });
    fileGrid.addEventListener('touchmove', () => { lastTouch = null; }, { passive: true });

    // Clic fuera del context menu
    document.addEventListener('click', () => {
        dcCloseContextMenus();
    });
    // Al hacer scroll no debe quedar el menú flotando ni alterar el layout.
    document.addEventListener('scroll', () => { dcCloseContextMenus(); }, true);
}

/** Cierra menús contextuales y limpia estilos de posición fija. */
function dcCloseContextMenus() {
    document.querySelectorAll('.context-menu-drive').forEach(m => {
        m.classList.remove('show');
        m.style.position = '';
        m.style.left = '';
        m.style.right = '';
        m.style.top = '';
        m.style.bottom = '';
        m.style.maxHeight = '';
        m.style.overflowY = '';
    });
    document.querySelectorAll('.dc-menu-layer75').forEach(el => el.classList.remove('dc-menu-layer75'));
}

function formatBytes(bytes, decimals = 2) {
    if (!+bytes) return '0 B';
    const k = 1024;
    const dm = decimals < 0 ? 0 : decimals;
    const sizes = ['B', 'KB', 'MB', 'GB', 'TB', 'PB', 'EB', 'ZB', 'YB'];
    const i = Math.floor(Math.log(bytes) / Math.log(k));
    return `${parseFloat((bytes / Math.pow(k, i)).toFixed(dm))} ${sizes[i]}`;
}

// --- TREE VIEW & SIDEBAR ---
function toggleSidebar() {
    document.getElementById('sidebar').classList.toggle('open');
    document.getElementById('sidebarBackdrop').classList.toggle('show');
}

async function loadTree() {
    try {
        const res = await fetch(`api.php?action=tree`);
        const data = await res.json();
        allFoldersData = data.folders;
        renderTree();
        renderBreadcrumb(); // Auto-update breadcrumb after loading folder names
    } catch(e) { console.error(e); }
}

function dcVersionTitle(f) {
    if (f.is_dir) {
        if (Number(f.version_enabled)) return 'Historial activado en esta carpeta';
        if (Number(f.version_inherited)) return 'Historial heredado de una carpeta superior';
        return 'Carpeta sin historial';
    }
    const count = Number(f.versions_count) || 0;
    return count ? `${count} versión${count === 1 ? '' : 'es'} anterior${count === 1 ? '' : 'es'}` : 'Sin versiones anteriores';
}
function dcVersionBadge(f) {
    const state = f.is_dir ? (Number(f.version_enabled) ? 'direct' : (Number(f.version_inherited) ? 'inherited' : '')) : (Number(f.has_versions) ? 'file' : '');
    return state ? `<span class="dc-version-clock dc-version-${state}" aria-hidden="true">◷</span>` : '';
}

function renderTree() {
    const treeContainer = document.getElementById('folderTree');
    if(!treeContainer) return;
    
    function buildNode(parentId) {
        const children = allFoldersData.filter(f => f.parent_id == parentId);
        if (children.length === 0) return '';
        let html = '<ul class="tree-list">';
        children.forEach(c => {
            // Los ANCESTROS del directorio actual deben mostrarse para mantenerlo visible.
            // El directorio actual, en cambio, puede colapsar sus propios hijos.
            const isRequiredAncestor = folderStack.slice(1, -1).includes(Number(c.id));
            const isExpanded = isRequiredAncestor || expandedTreeFolders.has(Number(c.id));
            
            const isActive = currentParentId == c.id;
            const hasChildren = allFoldersData.some(f => f.parent_id == c.id);
            
            let folderDropAttrs = `ondragover="dragOverFolder(event)" ondragleave="this.classList.remove('drag-over')" ondrop="dropOnFolder(event, ${c.id})"`;

            html += `<li>
                <div class="tree-label ${isActive ? 'active' : ''}" onclick="clickTreeFolder(${c.id}); if(window.innerWidth<=768) toggleSidebar();" ${folderDropAttrs}>
                    <span class="tree-toggle" title="${isRequiredAncestor ? 'En la ruta actual' : (isExpanded ? 'Contraer carpeta' : 'Expandir carpeta')}" onclick="toggleTreeExpand(event, ${c.id})">
                        ${hasChildren ? (isExpanded ? '🔽' : '▶️') : '&nbsp;&nbsp;'}
                    </span>
                    <span class="tree-icon dc-tree-icon-wrap ${Number(c.version_enabled) ? 'dc-version-direct' : (Number(c.version_inherited) ? 'dc-version-inherited' : '')}" title="${dcVersionTitle(c)}">${window.dcMusicLibraryIds82?.has(Number(c.id)) ? (isExpanded?'📂🎧':'📁🎧') : (isExpanded?'📂':'📁')}${Number(c.is_album)?'<span class="dc-album-tree-mark">🖼️</span>':''}${dcVersionBadge(c)}${Number(c.favorite)?'<span class="dc-favorite-mark57">⭐</span>':''}</span>
                    <span class="tree-text" title="${escapeHtml(c.name)}">${escapeHtml(c.name)}</span>
                </div>
                ${isExpanded ? buildNode(c.id) : ''}
            </li>`;
        });
        html += '</ul>';
        return html;
    }

    const isRootActive = currentParentId == 0;
    let folderDropAttrs = `ondragover="dragOverFolder(event)" ondragleave="this.classList.remove('drag-over')" ondrop="dropOnFolder(event, 0)"`;
    let html = `
        <div class="tree-label ${isRootActive ? 'active' : ''}" onclick="openFolder(0); if(window.innerWidth<=768) toggleSidebar();" ${folderDropAttrs}>
            <span class="tree-toggle">&nbsp;&nbsp;</span>
            <span class="tree-icon">🏠</span>
            <span data-dc-i18n-root class="tree-text">Mi unidad</span>
        </div>
        ${buildNode(0)}
    `;
    treeContainer.innerHTML = html;
}

// Al pulsar el nombre de una carpeta, navegar hasta ella y contraer sus hijos.
// Así se puede pulsar «comprimidos» incluso desde una carpeta descendiente:
// la ubicación pasa a «comprimidos» y la rama deja de mostrarse expandida.
function clickTreeFolder(id) {
    id = Number(id);
    expandedTreeFolders.delete(id);
    openFolder(id);
}

function toggleTreeExpand(e, id) {
    e.stopPropagation();
    id = Number(id);
    // No ocultar el directorio seleccionado colapsando uno de sus ancestros.
    if (folderStack.slice(1, -1).includes(id)) return;
    if (expandedTreeFolders.has(id)) expandedTreeFolders.delete(id);
    else expandedTreeFolders.add(id);
    renderTree();
}

// --- BREADCRUMB DRIVE STYLE ---
function renderBreadcrumb() {
    const bc = document.getElementById('breadcrumb');
    if(!bc) return;
    
    if (isSearching) {
        const q = document.getElementById('searchInput').value.trim();
        bc.innerHTML = `<span>Búsqueda: "${escapeHtml(q)}"</span>`;
        return;
    }

    let html = `<span data-dc-i18n-root class="crumb-item" onclick="openFolder(0)">Mi unidad</span>`;
    
    for (let i = 1; i < folderStack.length; i++) {
        let fId = folderStack[i];
        let folder = allFoldersData.find(f => f.id == fId);
        let fname = folder ? escapeHtml(folder.name) : `ID:${fId}`;
        
        html += ` <span class="crumb-sep">›</span> `;
        
        // El último elemento tiene el drop-down menu
        if (i === folderStack.length - 1) {
            html += `
                <span class="crumb-item active" onclick="toggleBreadcrumbMenu(event)">
                    ${fname} ▾
                </span>
                <div id="breadcrumbMenu" class="context-menu-drive">
                    <a href="api.php?action=download_folder&id=${fId}" onclick="event.stopPropagation()">📦 Descargar carpeta ZIP</a>
                    <a href="#" onclick="openShareModal(${fId}, event)">🔗 Compartir Carpeta</a>
                    ${dcCanShare && dcCanUpload ? `<a href="#" onclick="dcAction(event,()=>createReceive(${fId}))">📥 Crear enlace para subir ${Number(folder?.is_album)?'fotografías':'archivos'}</a>` : ''}
                    <a href="#" onclick="actionDelete(${fId}, event)" style="color:red;">🗑️ Eliminar Carpeta</a>
                </div>
            `;
        } else {
            html += `<span class="crumb-item" onclick="openFolder(${fId})">${fname}</span>`;
        }
    }
    bc.innerHTML = html;
}

function toggleBreadcrumbMenu(e) {
    e.stopPropagation();
    const menu = document.getElementById('breadcrumbMenu');
    if (menu) {
        document.querySelectorAll('.context-menu-drive').forEach(m => { if(m!==menu) m.classList.remove('show'); });
        menu.classList.toggle('show');
    }
}


// --- VIEW & SELECT TOGGLES ---
function toggleViewMode(mode) {
    viewMode = mode;
    localStorage.setItem('dc_viewmode', viewMode);
    applyViewMode();
    if (!isSearching) loadFiles();
}

function applyViewMode() {
    document.getElementById('fileGrid').className = `file-view-${viewMode}`;
    document.getElementById('listHeader').style.display = viewMode === 'list' ? 'flex' : 'none';
}

function clearSelection() {
    selectedFiles.clear();
    lastClickedId = null;
    updateSelectionUI();
}

function updateSelectionUI() {
    document.querySelectorAll('.file-item').forEach(el => el.classList.remove('selected'));
    selectedFiles.forEach(id => {
        const el = document.getElementById(`file-item-${id}`);
        if(el) el.classList.add('selected');
    });

    const actDiv = document.getElementById('selectionActions');
    if (selectedFiles.size > 0) {
        actDiv.style.display = 'flex';
        document.getElementById('multiSelectCount').innerText = `${selectedFiles.size} sel`;
    } else {
        actDiv.style.display = 'none';
    }
}

function toggleSelection(e, id) {
    e.stopPropagation(); 

    if (e.shiftKey && lastClickedId !== null) {
        const items = Array.from(document.querySelectorAll('.file-item:not(.back-btn)'));
        let start = items.findIndex(el => el.id === `file-item-${lastClickedId}`);
        let end = items.findIndex(el => el.id === `file-item-${id}`);
        
        if(start > end) [start, end] = [end, start];
        
        for(let i = start; i <= end; i++) {
            const currentId = parseInt(items[i].id.replace('file-item-', ''));
            selectedFiles.add(currentId);
        }
    } else if (e.ctrlKey || e.metaKey) {
        if (selectedFiles.has(id)) selectedFiles.delete(id);
        else selectedFiles.add(id);
    } else {
        selectedFiles.clear();
        selectedFiles.add(id);
    }
    
    lastClickedId = id;
    updateSelectionUI();
}

// --- DISK SPACE WATERMARK ---
async function loadDiskSpace() {
    const el = document.getElementById('diskSpaceWatermark');
    if (!el) return;
    try {
        const res = await fetch('api.php?action=disk_space', {cache:'no-store'});
        const data = await res.json();
        if (!res.ok || data.error) throw Error(data.error || 'No se pudo consultar el almacenamiento');
        const total = Number(data.total);
        const free = Number(data.free);
        const used = Number(data.used);
        if (!Number.isFinite(total) || total <= 0 || !Number.isFinite(free)) throw Error('Datos de almacenamiento no disponibles');
        const quota = data.mode === 'quota';
        const unlimited = data.mode === 'unlimited';
        const physicalUsed = Math.max(0, total - free);
        const calculatedUsed = quota ? Math.max(0, used) : physicalUsed;
        const percent = Math.min(100, Math.max(0, calculatedUsed / total * 100));
        const heading = quota ? 'Mi cuota de almacenamiento' : (unlimited ? 'Almacenamiento disponible · Sin cuota' : 'Espacio del servidor');
        const details = quota
            ? `${formatBytes(Math.max(0,used),1)} usados · ${formatBytes(Math.max(0,free),1)} disponibles de ${formatBytes(total,1)}`
            : `${formatBytes(Math.max(0,free),1)} libres de ${formatBytes(total,1)}`;
        const extra = unlimited && Number.isFinite(used) ? `Mis archivos: ${formatBytes(Math.max(0,used),1)} (incluye historial y papelera)` : '';
        el.innerHTML = `<button type="button" class="dc-storage-close" onclick="dcHideDiskIndicator()" aria-label="Ocultar indicador de almacenamiento" title="Ocultar indicador">×</button><div class="dc-storage-head"><span>${escapeHtml(heading)}</span><span>${percent.toFixed(1)}% utilizado</span></div>
            <div class="dc-storage-details">${escapeHtml(details)}</div>
            <div class="dc-storage-progress" role="progressbar" aria-label="Uso de almacenamiento" aria-valuemin="0" aria-valuemax="100" aria-valuenow="${percent.toFixed(1)}"><div class="dc-storage-fill" style="width:${percent}%"></div></div>
            ${extra ? `<div class="dc-storage-extra">${escapeHtml(extra)}</div>` : ''}`;
        el.hidden = localStorage.getItem('dc_hide_disk_indicator')==='1';
        const restore=document.getElementById('dcStorageRestore');
        if(restore)restore.hidden=!el.hidden;
    } catch (err) {
        el.hidden = true;
        if (DEBUG) console.warn(err);
    }
}


function dcHideDiskIndicator(){
 const el=document.getElementById('diskSpaceWatermark');
 if(el)el.hidden=true;
 dcUploadAvoidDiskOverlap();
 localStorage.setItem('dc_hide_disk_indicator','1');
 const restore=document.getElementById('dcStorageRestore');
 if(restore)restore.hidden=false;
}
function dcShowDiskIndicator(){
 localStorage.removeItem('dc_hide_disk_indicator');
 const el=document.getElementById('diskSpaceWatermark');if(el)el.hidden=false;
 dcUploadAvoidDiskOverlap();
 const restore=document.getElementById('dcStorageRestore');if(restore)restore.hidden=true;
 loadDiskSpace();
}
// --- BUSCADOR GLOBAL ---
function handleSearch(e) { if (e.key === 'Enter') performSearch(); }

async function performSearch() {
    const q = document.getElementById('searchInput').value.trim();
    const cs = document.getElementById('searchCaseSensitive').checked ? 1 : 0;
    if (!q) { clearSearch(); return; }
    
    const scope=window.DCMediaTools?.searchScope?.();
    isSearching = true;
    window.DCMediaTools?.beforeSearch?.();
    renderBreadcrumb();
    
    try {
        const type=document.getElementById('dcSearchType')?.value||'';
        const min=Math.max(0,Number(document.getElementById('dcSearchMin')?.value||0))*1048576;
        const maxInput=document.getElementById('dcSearchMax')?.value||'';
        const max=maxInput ? Math.max(0,Number(maxInput))*1048576 : '';
        const res = await fetch(`api.php?action=search&q=${encodeURIComponent(q)}&cs=${cs}&type=${encodeURIComponent(type)}&min_size=${min}&max_size=${max}${scope?`&scope_root=${scope}`:''}`);
        const data = await res.json();
        if (!res.ok || data.error) throw Error(data.error||'Error de búsqueda');
        dcImgQueueReset();
        renderFiles(data.files, true);
    } catch(e) { alert(e.message); }
}

function clearSearch() { document.getElementById('searchInput').value = ''; isSearching = false; dcImgQueueReset(); loadFiles(); }

function showSkeletons(n) {
  const grid = document.getElementById('fileGrid');
  if (!grid) return;
  grid.innerHTML = '';
  for (let i = 0; i < n; i++) {
    grid.innerHTML += `<div class="skel-card"><div class="skel skel-thumb"></div><div class="skel skel-line" style="width:70%"></div><div class="skel skel-line skel-line-sm"></div></div>`;
  }
}
/* Skeletons por objeto + cola cooperativa de miniaturas.
   Objetivo: nunca lanzar decenas de conversiones ImageMagick a la vez.
   Al salir de la carpeta se incrementa la época y las cargas pendientes se ignoran. */
const DC_TRANSPARENT_PIXEL = 'data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7';
const DC_IMG_CONCURRENCY = 2;
function dcImgQueueReset() {
  // Las peticiones ya lanzadas siguen contando hasta que terminan: no tocar dcImgActive.
  dcImgEpoch++;
  window.DCThumbCache.cancelPending();
  window.DCAlbumCovers?.reset();
  for(const timer of dcImgRetryTimers)clearTimeout(timer);dcImgRetryTimers.clear();
  dcImgQueue.length = 0;
  if (dcImgObserver) { dcImgObserver.disconnect(); dcImgObserver = null; }
}
function dcClearImgSkeleton(img) {
  if (img?.dataset?.dcQueued) return;
  const box = img?.closest?.('.skel') || img?.parentElement;
  if (box) box.classList.remove('skel', 'skel-thumb');
  if (img) img.removeAttribute('data-dc-skel');
}
function dcFailImgSkeleton(img) {
  if (img?.dataset?.dcQueued) return;
  dcClearImgSkeleton(img);
  if (img) img.style.display = 'none';
  const box = img?.parentElement;
  if (box) {
    if(img?.dataset.dcThumbUrl&&!box.querySelector('.dc-thumb-retry')){
      const retry=document.createElement('button');retry.type='button';retry.className='dc-thumb-retry';retry.textContent='Reintentar miniatura';
      retry.onclick=event=>{event.preventDefault();event.stopPropagation();retry.remove();box.classList.remove('dc-photo-failed','thumb-failed');box.classList.add('skel','skel-thumb');img.style.display='';dcLoadImageSoon(img,img.dataset.dcThumbUrl);};
      box.append(retry);
    }
    box.classList.add('dc-photo-failed');
    if (box.classList.contains('thumb-container')) box.classList.add('thumb-failed');
  }
}
function dcCancelImgLoad(img) {
  if (!img) return;
  img.dataset.dcCanceled = '1';
  img.dcThumbSequence=(img.dcThumbSequence||0)+1;
  delete img.dataset.dcQueued;
}
/** Cubre imágenes que ya estaban en caché y encola las que todavía no se han pedido. */
function dcSweepSkeletons(root) {
  const scope = root || document;
  scope.querySelectorAll?.('img[data-dc-skel]').forEach(img => {
    if (!img.dataset.dcQueued && !img.dataset.dcThumb && img.complete && img.naturalWidth > 0) dcClearImgSkeleton(img);
  });
  dcQueuePendingImages(scope);
}
/** Sólo se piden imágenes cerca del viewport; el resto espera sin consumir CPU. */
function dcQueuePendingImages(root) {
  const pending = Array.from((root || document).querySelectorAll?.('img[data-dc-thumb]:not([data-dc-scheduled])') || []);
  if (!pending.length) return;
  pending.forEach(img => { img.dataset.dcScheduled = '1';window.DCThumbCache.decorate(img,img.dataset.dcThumb); });
  const start = img => {
    if (img.dataset.dcQueued || img.dataset.dcCanceled) return;
    delete img.dataset.dcScheduled;
    dcLoadImageSoon(img, img.dataset.dcThumb);
  };
  if (!('IntersectionObserver' in window)) { pending.forEach(start); return; }
  if (!dcImgObserver) {
    dcImgObserver = new IntersectionObserver((entries, obs) => {
      for (const entry of entries) {
        if (!entry.isIntersecting) continue;
        obs.unobserve(entry.target);
        start(entry.target);
      }
    }, {rootMargin:'400px 0px', threshold:0.01});
  }
  pending.forEach(img => dcImgObserver.observe(img));
}
function dcPumpImgQueue() {
  while (dcImgActive < DC_IMG_CONCURRENCY && dcImgQueue.length) {
    const job = dcImgQueue.shift();
    if (job.epoch !== dcImgEpoch || job.img.dataset.dcCanceled || job.sequence!==job.img.dcThumbSequence) continue;
    dcImgActive++;
    Promise.resolve().then(()=>window.DCThumbCache.request(job.url))
      .then(res => {
        if (job.epoch !== dcImgEpoch || job.img.dataset.dcCanceled || job.sequence!==job.img.dcThumbSequence) return;
        const pending = res.headers.get('X-DC-Thumb-Pending') === '1';
        if (pending) {
          if (job.attempt < 8) {
            const timer=setTimeout(() => {
              dcImgRetryTimers.delete(timer);
              if (job.epoch !== dcImgEpoch || job.img.dataset.dcCanceled || job.sequence!==job.img.dcThumbSequence) return;
              dcImgQueue.unshift(Object.assign({}, job, {attempt: job.attempt + 1}));
              dcPumpImgQueue();
            }, 1200);
            dcImgRetryTimers.add(timer);
            return;
          }
          throw new Error('Miniatura pendiente');
        }
        if (!res.ok) throw new Error('No se pudo cargar la miniatura');
        delete job.img.dataset.dcQueued;
        job.img.removeAttribute('data-dc-thumb');
        job.img.src = res.dataUrl;
      })
      .catch(error => { if (job.epoch === dcImgEpoch && job.sequence===job.img.dcThumbSequence && !job.img.dataset.dcCanceled){delete job.img.dataset.dcQueued;dcFailImgSkeleton(job.img);} })
      .finally(() => { dcImgActive--; dcPumpImgQueue(); });
  }
}
/** Encola una imagen; el <img> sigue mostrando su skeleton hasta que la fetch termine. */
function dcLoadImageSoon(img, url) {
  if (!img || !url) return;
  delete img.dataset.dcCanceled;
  img.dcThumbSequence=(img.dcThumbSequence||0)+1;
  img.dataset.dcThumbUrl=url;
  // Only api.php?action=thumb belongs to the photo cache. Local video posters
  // and other media keep their native image loading and their own handlers.
  if(!window.DCThumbCache.supports(url)){
    delete img.dataset.dcQueued;
    delete img.dataset.dcScheduled;
    img.removeAttribute('data-dc-thumb');
    img.src=url;
    return;
  }
  window.DCThumbCache.decorate(img,url);
  const cached=window.DCThumbCache.getCached(url);
  if(cached){delete img.dataset.dcQueued;img.removeAttribute('data-dc-thumb');img.src=cached.dataUrl;return;}
  const job = {img, url, epoch: dcImgEpoch, attempt: 0,sequence:img.dcThumbSequence};
  img.dataset.dcQueued = '1';
  img.src = DC_TRANSPARENT_PIXEL;
  dcImgQueue.push(job);
  dcPumpImgQueue();
}
function dcClearMediaSkeleton(media) {
  const box = media?.closest?.('.dc-media-viewer-skel');
  if (box) box.classList.remove('skel');
}
function dcFailMediaSkeleton(media) { dcClearMediaSkeleton(media); }
async function loadFiles({resetScroll=false}={}) {
    if(isSearching)return;
    const target=Number(currentParentId),changing=dcRenderedFolderDcft!==target;
    dcCaptureFolderViewDcft();
    let restore=window.DCAlbumViews.get(target);
    if(changing){dcAlbumPage=restore?.page||1;dcAlbumPageSize=restore?.pageSize||dcAlbumPageSize;}
    if(resetScroll)restore={...restore,windowY:0,dropY:0};
    else if(!changing)restore=window.DCAlbumViews.get(target);
    dcAlbumLastParent=target;
    const sequence=++dcFolderLoadSequence;
    dcFolderLoadController?.abort();dcFolderLoadController=new AbortController();
    const controller=dcFolderLoadController;
    dcFolderLoadingDcft=true;
    dcImgQueueReset();dcRecentsToolbar85m(false);showSkeletons(8);
    const stale=()=>sequence!==dcFolderLoadSequence||target!==Number(currentParentId);
    try {
        const res=await fetch(`api.php?action=list&parent_id=${target}`,{credentials:'same-origin',cache:'no-store',signal:controller.signal});
        const data=await res.json();if(stale())return;
        if(!res.ok||data.error){if(res.status===401){window.DCThumbCache.clear();window.DCAlbumViews.clear();}throw Error(data.error||'No se pudo abrir la carpeta (HTTP '+res.status+').');}
        if(!Array.isArray(data.files))throw Error('El servidor no devolvió una lista de archivos válida.');
        window.DCThumbCache.remember(data.files);
        renderBreadcrumb();clearSelection();
        dcAlbumMode=!!allFoldersData.find(f=>Number(f.id)===target&&Number(f.is_album)===1);
        if(typeof window.dcMusicLoadLibraryIds82==='function')await window.dcMusicLoadLibraryIds82();
        if(stale())return;
        renderFiles(data.files,false);dcRenderedFolderDcft=target;
        if(dcAlbumMode){dcAlbumUpdatePageInfo();dcAlbumRenderPaginationFooter();}
        else document.getElementById('dcAlbumPaginationFooter')?.remove();
        const drop=document.getElementById('dropZone');
        const applyScroll=()=>{if(stale())return;if(drop)drop.scrollTop=restore?.dropY||0;window.scrollTo({top:restore?.windowY||0,behavior:'instant'});};
        applyScroll();dcFolderLoadingDcft=false;requestAnimationFrame(()=>requestAnimationFrame(applyScroll));
        if(typeof dcyRefresh==='function')await dcyRefresh();if(stale())return;
        if(!document.getElementById('dcyGrid')&&typeof window.dcCollectionsDecorate67==='function')await window.dcCollectionsDecorate67(target);if(stale())return;
        if(!document.getElementById('dcyGrid')&&typeof window.dcCollectionsPaint65==='function'&&target>0)await window.dcCollectionsPaint65(target);if(stale())return;
        if(typeof window.dcMusicRefresh81==='function')await window.dcMusicRefresh81();if(stale())return;
        renderTree();loadDiskSpace();
        window.dispatchEvent(new CustomEvent('dc-folder-loaded-dcft',{detail:{id:target}}));

    } catch(error) {
        if(stale()||error.name==='AbortError')return;
        dcFolderLoadingDcft=false;
        const grid=document.getElementById('fileGrid');grid.replaceChildren();
        const message=document.createElement('div');message.className='dc-file-load-error';message.setAttribute('role','alert');message.textContent=error.message||'No se pudo abrir la carpeta.';
        const retry=document.createElement('button');retry.type='button';retry.textContent='Reintentar';retry.onclick=()=>loadFiles();message.append(document.createElement('br'),retry);grid.append(message);
    }
}

// Etiqueta compacta de tipo para la vista de lista. El MIME original se conserva en title.
function dcCompactFileType(file) {
    if (file.is_dir) return 'Carpeta';
    const ext = String(file.name || '').split('.').pop().toLowerCase();
    const known = new Set(['pdf','doc','docx','xls','xlsx','ppt','pptx','odt','ods','odp','txt','md','csv','tsv','json','xml','html','css','js','ts','jsx','tsx','php','py','sh','sql','yaml','yml','zip','rar','7z','tar','gz','bz2','xz','jpg','jpeg','png','gif','webp','svg','heic','mp3','wav','ogg','mp4','mkv','webm','avi','mov','epub','rtf']);
    if (known.has(ext)) return ext.toUpperCase();
    const mime = String(file.mime_type || '');
    const common = {'application/octet-stream':'Archivo','text/plain':'Texto','application/pdf':'PDF','image/jpeg':'JPEG','image/png':'PNG','application/zip':'ZIP','application/x-rar-compressed':'RAR'};
    if (common[mime.toLowerCase()]) return common[mime.toLowerCase()];
    const category = mime.split('/')[0].toLowerCase();
    return {'image':'Imagen','video':'Video','audio':'Audio','text':'Texto','application':'Archivo'}[category] || 'Archivo';
}

const DC_RAW_EXT = /\.(cr2|cr3|nef|nrw|arw|srf|sr2|dng|raf|orf|rw2|rwl|pef|srw|x3f|iiq|3fr|fff|mrw)$/i;
/** RAW de cámara: se muestran como foto, pero no se descargan a resolución completa. */
function dcIsRawFile(f) {
    if (!f || Number(f.is_dir)) return false;
    const mime = String(f.mime_type||'').toLowerCase();
    return DC_RAW_EXT.test(String(f.name||'')) ||
           mime.includes('raw') ||
           /^image\/x-(canon|nikon|sony|olympus|pentax|panasonic|fujifilm|samsung|adobe|leica)-/.test(mime);
}
function dcIsPhoto(f) {
    if (!f || Number(f.is_dir)) return false;
    const ext = String(f.name||'').split('.').pop().toLowerCase();
    return dcIsRawFile(f) || ['jpg','jpeg','jpe','png','gif','webp','avif','bmp'].includes(ext) ||
           /^image\/(jpeg|png|gif|webp|avif|bmp)$/i.test(String(f.mime_type||''));
}
function dcPhotoUrl(id) { return `api.php?action=download&id=${encodeURIComponent(id)}`; }
/** Evita miniaturas en caché tras girar o regenerar. */

/** Miniatura ligera para explorador y collage (genera bajo demanda si falta). */
function dcPhotoThumbUrl(id,cachedOnly=false){return window.DCThumbCache.url(id,cachedOnly);}

/** Cola de giros: permite encolar mientras hay otro lote en curso. */
let dcRotateBusy = false;
const dcRotateQueue = [];

/** Gira las fotos de la selección múltiple (ignora carpetas y no-fotos). */
function dcRotateSelection(direction) {
    const ids = [...new Set(Array.from(selectedFiles).map(Number).filter(n => n > 0))];
    if (!ids.length) return;
    const photos = ids.filter(id => {
        const el = document.getElementById('file-item-' + id);
        if (el?.querySelector('.dc-raw-badge')) return false;
        if (el?.classList.contains('dc-image-item')) return true;
        const inViewer = (typeof dcVisiblePhotos !== 'undefined') ? dcVisiblePhotos.find(p => Number(p.id) === Number(id)) : null;
        if (inViewer) return !dcIsRawFile(inViewer);
        const name = el?.querySelector('.file-name')?.textContent || '';
        return /\.(jpe?g|png|gif|webp|bmp|avif)$/i.test(name);
    });
    if (!photos.length) {
        alert('Selecciona al menos una fotografía para girar.');
        return;
    }
    dcRotatePhoto(photos, direction);
}

/** Nombre legible para el panel de transferencias. */
function dcRotateJobName(list) {
    if (list.length === 1) {
        return document.querySelector(`#file-item-${list[0]} .file-name`)?.textContent?.trim() || `Foto ${list[0]}`;
    }
    return `${list.length} fotografías`;
}

/**
 * Encola (o inicia) un giro de 90°. direction: left|right
 * Si ya hay un giro en curso, queda en cola y se ve en Transferencias.
 */
function dcRotatePhoto(ids, direction) {
    const list = [...new Set((Array.isArray(ids) ? ids : [ids]).map(Number).filter(n => n > 0))];
    if (!list.length) return;
    const dir = direction === 'left' ? 'left' : 'right';
    const label = dir === 'left' ? '↺ Girar 90° izq.' : '↻ Girar 90° der.';
    const name = dcRotateJobName(list);
    const row = typeof dcTransferAdd === 'function' ? dcTransferAdd(name, 'rotate') : null;
    if (row && typeof dcTransferStatus === 'function') {
        if (dcRotateBusy || dcRotateQueue.length) {
            dcTransferStatus(row, `⏳ En cola · ${label}`, 0);
        } else {
            dcTransferStatus(row, `${label} · preparando`, 5);
        }
    }
    dcCloseContextMenus?.();
    dcRotateQueue.push({ list, dir, label, name, row });
    dcRotatePump();
}

/** Procesa la cola de giros uno a uno. */
async function dcRotatePump() {
    if (dcRotateBusy) return;
    const job = dcRotateQueue.shift();
    if (!job) return;
    dcRotateBusy = true;
    const { list, dir, label, row } = job;
    const total = list.length;
    if (row && typeof dcTransferStatus === 'function') {
        dcTransferStatus(row, `${label} · preparando`, 5);
    }
    let tick = 5;
    const timer = row ? setInterval(() => {
        tick = Math.min(90, tick + Math.max(2, Math.floor(40 / Math.max(1, total))));
        if (typeof dcTransferStatus === 'function' && row.isConnected) {
            dcTransferStatus(row, `${label} · ${total} foto${total > 1 ? 's' : ''}`, tick);
        }
    }, 400) : null;
    try {
        const fd = new FormData();
        fd.set('action', 'rotate_image');
        fd.set('direction', dir);
        if (list.length === 1) fd.set('id', String(list[0]));
        else fd.set('ids', JSON.stringify(list));
        const r = await fetch('api.php', {
            method: 'POST',
            body: fd,
            credentials: 'same-origin',
            headers: { 'X-DC-CSRF': window.DC_AUTH?.csrf || '' }
        });
        const j = await r.json().catch(() => ({}));
        if (timer) clearInterval(timer);
        if (!r.ok || j.error || !j.success) throw new Error(j.error || 'No se pudo girar');
        list.forEach(id=>window.DCThumbCache.invalidate(id));
        dcAlbumCollageCache.clear();
        dcAlbumDecoded.clear();
        // Solo refrescar el grid si no hay más trabajos pendientes (evita parpadeos).
        if (!dcRotateQueue.length) {
            await loadFiles();
        }
        if (row && typeof dcTransferStatus === 'function') {
            const done = Number(j.rotated || list.length);
            const failed = Number(j.failed || 0);
            if (failed > 0) {
                dcTransferStatus(row, `↻ ${done} ok · ${failed} error`, 100);
                row.classList.add('dc-upload-error');
            } else {
                dcTransferStatus(row, total === 1 ? '↻ Giro completado' : `↻ ${done} fotos giradas`, 100);
            }
        }
    } catch (err) {
        if (timer) clearInterval(timer);
        if (row && typeof dcTransferStatus === 'function') {
            dcTransferStatus(row, '↻ Error: ' + (err.message || 'fallo'), null);
            row.classList.add('dc-upload-error');
        } else {
            alert(err.message || String(err));
        }
    } finally {
        dcRotateBusy = false;
        // Siguiente de la cola
        if (dcRotateQueue.length) {
            // Actualizar etiquetas "en cola" del siguiente
            const next = dcRotateQueue[0];
            if (next?.row && typeof dcTransferStatus === 'function' && next.row.isConnected) {
                dcTransferStatus(next.row, `${next.label} · preparando`, 5);
            }
            dcRotatePump();
        } else {
            // Refresco final por si se aplazó durante la cola
            try { await loadFiles(); } catch (_) {}
        }
    }
}

function dcOpenPhoto(id) {
    dcPhotoIndex = dcVisiblePhotos.findIndex(f=>Number(f.id)===Number(id));
    if (dcPhotoIndex < 0) { dcVisiblePhotos=[{id:Number(id),name:'Imagen'}]; dcPhotoIndex=0; }
    let modal=document.getElementById('dcPhotoViewer');
    if (!modal) {
        modal=document.createElement('div');modal.id='dcPhotoViewer';modal.className='dc-photo-viewer';
        modal.setAttribute('role','dialog');modal.setAttribute('aria-modal','true');
        modal.innerHTML=`<button class="dc-photo-close" type="button" onclick="dcClosePhoto()" aria-label="Cerrar visor">×</button>
           <button class="dc-photo-prev" type="button" onclick="dcPhotoStep(-1)" aria-label="Imagen anterior">❮</button>
           <div id="dcPhotoLargeWrap" class="dc-photo-large-wrap"><img id="dcPhotoLarge" alt="Foto ampliada" /></div>
           <button class="dc-photo-next" type="button" onclick="dcPhotoStep(1)" aria-label="Imagen siguiente">❯</button>
           <div class="dc-photo-caption"><span id="dcPhotoName"></span><span id="dcPhotoCount"></span></div>`;
        modal.addEventListener('click',e=>{if(e.target===modal)dcClosePhoto();});
        let x=0; modal.addEventListener('touchstart',e=>{x=e.changedTouches[0]?.clientX||0},{passive:true});
        modal.addEventListener('touchend',e=>{let delta=(e.changedTouches[0]?.clientX||0)-x;if(Math.abs(delta)>65)dcPhotoStep(delta<0?1:-1)},{passive:true});
        document.body.append(modal);
    }
    modal.classList.add('show');document.body.classList.add('dc-photo-open');dcPhotoRender();
}
let dcPhotoRenderSeq = 0;
function dcPhotoRender() {
    const f=dcVisiblePhotos[dcPhotoIndex];if(!f)return;
    const seq=++dcPhotoRenderSeq;
    const img=document.getElementById('dcPhotoLarge');
    const wrap=document.getElementById('dcPhotoLargeWrap');
    // 1) miniatura inmediata con skeleton; 2) full-res en segundo plano.
    if(wrap)wrap.classList.add('skel');
    img.onload=()=>{if(seq===dcPhotoRenderSeq&&!img.dataset.dcQueued&&wrap)wrap.classList.remove('skel');};
    img.onerror=()=>{if(seq===dcPhotoRenderSeq&&!img.dataset.dcQueued&&wrap)wrap.classList.remove('skel');};
    img.alt=f.name;
    delete img.dataset.dcCanceled;
    dcLoadImageSoon(img, dcPhotoThumbUrl(f.id));
    const raw = dcIsRawFile(f);
    document.getElementById('dcPhotoName').textContent=f.name;
    document.getElementById('dcPhotoCount').textContent=`${dcPhotoIndex+1} / ${dcVisiblePhotos.length}${raw?' · RAW (vista previa)':''}`;
    document.querySelector('.dc-photo-prev').disabled=dcPhotoIndex===0;
    document.querySelector('.dc-photo-next').disabled=dcPhotoIndex===dcVisiblePhotos.length-1;
    // Un RAW no es decodificable por el navegador: nos quedamos con la miniatura
    // generada desde su JPG/vista previa en vez de descargar 20-30 MB inútiles.
    if (!raw) {
        const fullSrc=dcPhotoUrl(f.id);
        const apply=()=>{if(seq!==dcPhotoRenderSeq)return;dcCancelImgLoad(img);img.src=fullSrc;if(wrap)wrap.classList.remove('skel');};
        const afterLoad=()=>{if(typeof full.decode==='function')full.decode().catch(()=>{}).then(apply);else apply();};
        const full=new Image();
        full.onload=afterLoad;
        full.onerror=()=>{}; // la miniatura ya tiene su propio onload/onerror
        full.src=fullSrc;
        if(full.complete&&full.naturalWidth>0)afterLoad();
    }
}
function dcPhotoStep(delta) {const next=dcPhotoIndex+delta;if(next>=0&&next<dcVisiblePhotos.length){dcPhotoIndex=next;dcPhotoRender();}}
function dcClosePhoto(){document.getElementById('dcPhotoViewer')?.classList.remove('show');document.body.classList.remove('dc-photo-open');}
document.addEventListener('keydown', e=>{
    if(!document.getElementById('dcPhotoViewer')?.classList.contains('show'))return;
    if(e.key==='Escape'){dcClosePhoto();e.preventDefault();}
    if(e.key==='ArrowLeft'){dcPhotoStep(-1);e.preventDefault();}
    if(e.key==='ArrowRight'){dcPhotoStep(1);e.preventDefault();}
});
async function dcSetAlbum(id, enabled){
    try {
        await dcPost('set_album',{id,enabled:String(enabled)});
        await loadTree();await loadFiles();
    } catch(e){alert('No se pudo cambiar el modo álbum: '+e.message);}
}

// Metadatos de los elementos visibles para el arrastre externo.
const dcDragFileMeta = new Map();
const dcAlbumCollageCache = new Map();
const dcAlbumCollageObserver = new IntersectionObserver((entries) => {
  for (const e of entries) {
    if (!e.isIntersecting) continue;
    const card = e.target;
    const albumId = Number(card.dataset.albumId);
    if (!albumId || card.dataset.collageLoaded) continue;
    card.dataset.collageLoaded = '1';
    loadAlbumCollage(albumId, card);
  }
}, { rootMargin: '100px' });

async function loadAlbumCollage(albumId, card) {
  if (!albumId || albumId <= 0) { console.log('SKIP collage: invalid albumId', albumId); return; }

  try {
    const cached=dcAlbumCollageCache.get(albumId);
    if (cached&&cached.expires>Date.now()) {
      window.DCThumbCache.remember(cached.photos);
      renderAlbumCollage(card, cached.photos);
      return;
    }
    const res = await fetch('api.php?action=list&parent_id=' + encodeURIComponent(albumId), {credentials:'same-origin',cache:'no-store',signal:dcFolderLoadController?.signal});
    const data = await res.json();
    if(!res.ok||!card.isConnected)return;
    const fileList = data.files || data.items || data.data || [];
    window.DCThumbCache.remember(fileList);

    const photos = fileList.filter(f => !Number(f.is_dir) && dcIsPhoto(f)).slice(0, 4);
    dcAlbumCollageCache.set(albumId,{photos,expires:Date.now()+60000});
    while(dcAlbumCollageCache.size>100)dcAlbumCollageCache.delete(dcAlbumCollageCache.keys().next().value);
    renderAlbumCollage(card, photos);
  } catch (e) {
    // fallback: keep icon
  }
}

function renderAlbumCollage(card, photos) {
  if (!photos || photos.length === 0) return; // keep representative icon
  // remove existing collage
  const existing = card.querySelector('.dc-album-collage');
  if (existing) existing.remove();
  const collage = document.createElement('div');
  collage.className = 'dc-album-collage';
  for (const p of photos) {
    const cell = document.createElement('div');
    cell.className = 'dc-album-collage-cell skel';
    const img = document.createElement('img');
    img.dataset.dcThumb = dcPhotoThumbUrl(p.id,true);
    img.loading = 'lazy';
    img.alt = '';
    img.addEventListener('load', () => dcClearImgSkeleton(img));
    img.addEventListener('error', () => dcClearImgSkeleton(img));
    img.src = DC_TRANSPARENT_PIXEL;
    cell.appendChild(img);
    collage.appendChild(cell);
  }
  card.prepend(collage);
  card.classList.add('dc-album-has-collage');
  dcSweepSkeletons(collage);
}
function renderFiles(files, isSearchResult = false) {
    dcImgQueueReset();
    window.DCThumbCache.remember(files);
    files=dcSortFiles(files);
    dcDragFileMeta.clear();
    for (const f of files) dcDragFileMeta.set(Number(f.id), {name:String(f.name||''), isDir:Number(f.is_dir)===1});
    if(!isSearchResult)window.dcVideoFiles64=files.filter(f=>!Number(f.is_dir) && (/^video\//i.test(String(f.mime_type||''))||/\.(mp4|webm|mov|mkv|avi|m4v|mpeg)$/i.test(String(f.name||'')))).map(f=>({id:Number(f.id),name:String(f.name||''),mime:String(f.mime_type||'video/mp4')}));
    const albumCachedOnly = dcAlbumMode && !isSearchResult;
    // En album solo se muestran fotos con miniatura WebP ya cacheada (sin generar bajo demanda).
    dcVisiblePhotos=files.filter(f => dcIsPhoto(f) && (!albumCachedOnly || f.thumb_cached === true));
    // Paginacion para albumes de fotos
    if (albumCachedOnly) {
      const totalPhotos = dcVisiblePhotos.length;
      const totalPages = Math.max(1, Math.ceil(totalPhotos / dcAlbumPageSize));
      if (dcAlbumPage > totalPages) dcAlbumPage = totalPages;
      if (dcAlbumPage < 1) dcAlbumPage = 1;
      const start = (dcAlbumPage - 1) * dcAlbumPageSize;
      const end = start + dcAlbumPageSize;
      const pagePhotos = dcVisiblePhotos.slice(start, end);
      const nonPhotos = files.filter(f => !dcIsPhoto(f));
      files = nonPhotos.concat(pagePhotos);
    }
    const album=dcAlbumMode && !isSearchResult && viewMode==='grid';
    dcUpdateSortHeaders();
    const grid = document.getElementById('fileGrid');
    grid.innerHTML = '';
    grid.classList.toggle('dc-gallery-grid',album);
    // Observe album cards for collage loading

    
    if (isSearchResult) {
        grid.innerHTML += `<div class="file-item back-btn" ondblclick="clearSearch()">
            <div class="file-icon back-arrow">↰</div>
            <div class="file-name" style="font-weight:bold;">Volver</div>
            <div class="file-meta"></div><div class="file-size"></div><div class="file-date"></div>
        </div>`;
    }

    const upButton = document.getElementById('navUpButton');
    if (upButton) upButton.disabled = isSearchResult || folderStack.length <= 1;

    files.forEach(f => {
        let icon = f.is_dir ? (window.dcMusicLibraryIds82?.has(Number(f.id)) ? '📁🎧' : '📁') : getIconForMime(f.mime_type, f.name);
        const photo = dcIsPhoto(f);
        const raw = dcIsRawFile(f);
        const isPdf = !f.is_dir && (f.mime_type || '').toLowerCase().includes('pdf');
        const formatIssue = !f.is_dir ? dcFormatCheck(f.name,f.mime_type) : '';
        const showPreview = !f.is_dir && !formatIssue && (Boolean(Number(f.has_thumb)) || isPdf);
        const genericThumbUrl = dcPhotoThumbUrl(f.id,albumCachedOnly);
        let visual = showPreview
            ? `<div class="thumb-container skel skel-thumb"><img data-dc-skel="1" data-dc-thumb="${genericThumbUrl}" loading="lazy" onload="dcClearImgSkeleton(this)" onerror="dcFailImgSkeleton(this)" src="${DC_TRANSPARENT_PIXEL}" class="file-thumb" alt="Vista previa"><span class="thumb-fallback">${icon}</span>${isPdf ? '<span class="thumb-badge pdf-badge">PDF</span>' : ''}</div>`
            : `<div class="file-icon ${f.is_dir ? 'folder-icon' : ''}"${formatIssue?' title="'+(formatIssue==='unverified'?'Formato sin verificar':'Extensión y MIME posiblemente incompatibles')+'"':''}>${icon}</div>`;
        if (photo && !formatIssue) {
            // Explorador: miniatura ligera con skeleton por objeto mientras se genera/carga.
            // Collage también usa thumb; la calidad completa solo al abrir/descargar.
            visual = `<div class="dc-photo-tile skel skel-thumb"><img data-dc-skel="1" data-dc-thumb="${dcPhotoThumbUrl(f.id, albumCachedOnly)}" loading="lazy" onload="dcClearImgSkeleton(this)" onerror="dcFailImgSkeleton(this)" src="${DC_TRANSPARENT_PIXEL}" alt="${escapeHtml(f.name)}"><span class="dc-photo-fallback">🖼️</span></div>`;
        }
        if (!f.is_dir && Number(f.received_highlight)) {
            visual = `<div class="dc-received-visual" title="Recibido mediante enlace de subida">${visual}<span class="dc-received-badge">📥</span></div>`;
        }
        const badge = dcVersionBadge(f);
        const badgeClass = Number(f.version_enabled) ? 'dc-version-direct' : (Number(f.version_inherited) ? 'dc-version-inherited' : '');
        visual = `<div class="dc-version-visual ${badgeClass}" title="${escapeHtml(dcVersionTitle(f))}">${visual}${badge}${raw?'<span class="dc-raw-badge" title="Archivo RAW sin procesar: conserva el original y usa el JPG para editar">RAW</span>':''}${Number(f.favorite)?'<span class="dc-favorite-mark57" title="Favorito">⭐</span>':''}</div>`;
        
        let onDblClickAction = f.is_dir ? `openFolder(${f.id})` : photo ? `dcOpenPhoto(${f.id})` : `viewFile(${f.id}, '${f.mime_type}')`;
        let dragDropAttrs = `draggable="true" ondragstart="dragStart(event, ${f.id})"`;
        let folderDropAttrs = f.is_dir ? `ondragover="dragOverFolder(event)" ondragleave="this.classList.remove('drag-over')" ondrop="dropOnFolder(event, ${f.id})"` : '';
        
        let sizeText = f.is_dir ? '-' : formatBytes(f.size);
        let typeText = dcCompactFileType(f);
        let fullType = f.is_dir ? 'Carpeta' : ((formatIssue==='unverified'?'Tipo no identificado; formato sin verificar. ':formatIssue==='mismatch'?'Extensión y MIME posiblemente incompatibles. ':'')+(f.mime_type || 'Desconocido'));

        grid.innerHTML += `
            <div class="file-item ${photo ? 'dc-image-item' : ''}" id="file-item-${f.id}" onclick="toggleSelection(event, ${f.id})" ondblclick="${onDblClickAction}" ${dragDropAttrs} ${folderDropAttrs}>
                ${visual}
                <div class="file-name${f.is_dir ? ' folder-title' : ' dc-ext59-'+dcExtensionColor59(f.name)}" title="${escapeHtml(isSearchResult && f.ruta_virtual ? f.ruta_virtual : f.name)}">${escapeHtml(f.name)}${isSearchResult && f.ruta_virtual ? `<div class="dc-search-virtual-path" title="${escapeHtml(f.ruta_virtual)}" style="font-size:11px;opacity:.72;overflow-wrap:anywhere;white-space:normal;font-weight:normal">📍 ${escapeHtml(f.ruta_virtual)}</div>` : ''}</div>
                <div class="file-meta" title="${escapeHtml(fullType)}">${escapeHtml(typeText)}</div>
                <div class="file-size">${sizeText}</div>
                <div class="file-date" title="Agregado: ${escapeHtml(dcDateLabel(f.created_at))}">${escapeHtml(dcDateLabel(f.created_at))}</div>
                <button class="menu-dots" onclick="openFileMenu(event, ${f.id})">⋮</button>
                <div id="fileMenu-${f.id}" class="context-menu-drive">
                    ${!Number(f.is_dir) && (/^audio\//i.test(String(f.mime_type||'')) || /\.(mp3|m4a|aac|ogg|oga|opus|wav|flac|weba)$/i.test(String(f.name||''))) ? `<a href="#" onclick="dcAction(event,()=>dcPlayLooseAudioDcft(${Number(f.id)}))">▶ Reproducir</a>` : ''}
                    ${!f.is_dir ? `<a href="#" onclick="dcAction(event,()=>dciManage(${f.id}))">👥 Compartir con usuario</a>` : ''}
                    ${!f.is_dir ? `<a href="api.php?action=download&id=${f.id}&download=1" onclick="event.preventDefault();event.stopPropagation();dcDownloadWithProgress(${f.id}, ${JSON.stringify(f.name)})">⬇️ Descargar</a>` : `<a href="api.php?action=download_folder&id=${f.id}" onclick="event.stopPropagation()">📦 Descargar ZIP</a>`}
                    ${photo && !raw && !dcReadOnlyAccount ? `<a href="#" onclick="dcAction(event,()=>dcRotatePhoto(${f.id},'left'))">↺ Girar 90° izquierda</a><a href="#" onclick="dcAction(event,()=>dcRotatePhoto(${f.id},'right'))">↻ Girar 90° derecha</a>` : ''}
                    ${f.is_dir ? `<a href="#" onclick="dcAction(event,()=>dcyConfigure(${f.id}))">▶ Configurar YouTube Links</a>` : ''}
                    ${f.is_dir ? `<a href="#" onclick="dcAction(event,()=>dcMusicConfigure81(${f.id}))">♫ Configurar modo biblioteca musical</a>` : ''}
                    ${f.is_dir ? `<a href="#" onclick="dcAction(event,()=>dcFolderCollection66(${f.id}))">🎬 Convertir / desactivar colección de videos</a>` : ''}
                    ${f.is_dir ? `<a href="#" data-dc-music-collection-hf3="1" hidden>♫ Configurar modo colección musical</a>` : ''}
                    ${f.is_dir ? `<a href="#" onclick="dcAction(event,()=>dcSetAlbum(${f.id},${Number(f.is_album)?0:1}))">🖼️ ${Number(f.is_album)?'Quitar modo álbum':'Convertir en álbum de fotos'}</a>` : ''}
                    ${f.is_dir && Number(f.received_children_count || 0) > 0 ? `<a href="#" onclick="dcAction(event,()=>dcClearReceivedMarksFolder(${f.id}))">📥 Quitar distintivo</a>` : ''}
                    <a href="#" onclick="dcAction(event,()=>dcRenameFile(${f.id},document.querySelector('#file-item-${f.id} .file-name').textContent))">✏️ Renombrar</a>
                    ${dcReadOnlyAccount ? `
                    ${dcCanShare ? `<a href="#" onclick="openShareModal(${f.id},event)">🔗 Compartir</a>` : ''}
                    ${f.is_dir && dcCanShare && dcCanUpload ? `<a href="#" onclick="dcAction(event,()=>createReceive(${f.id}))">📥 Recibir archivos aquí</a>` : ''}
                    <a href="#" onclick="dcAction(event,()=>toggleFavorite(${f.id}))">⭐ ${Number(f.favorite)?'Quitar favorito':'Favorito'}</a>
                    <a href="#" onclick="dcAction(event,()=>showProperties(${f.id}))">ℹ️ Propiedades</a>
                    ${Number(f.received_highlight)?`<a href="#" onclick="dcAction(event,()=>dcClearReceivedMark(${f.id}))">📥 Quitar distintivo de recepción</a>`:''}
                    ${f.is_dir
                        ? `<a href="#" onclick="dcAction(event,()=>toggleVersions(${f.id},${Number(f.version_enabled)?0:1}))">🕘 ${Number(f.version_enabled)?'Desactivar':'Activar'} historial</a>`
                        : `<a href="#" onclick="dcAction(event,()=>showVersions(${f.id}))">🕘 Historial de versiones</a>`}
                    <hr style="margin:4px 0; border:0; border-top:1px solid var(--border-color);">
                    <a href="#" onclick="actionBatch('copy',[${f.id}]);event.stopPropagation();return false;">📋 Copiar</a>
                    <a href="#" onclick="actionBatch('move',[${f.id}]);event.stopPropagation();return false;">✂️ Cortar</a>
                    ${dcCanDelete ? `<a href="#" onclick="actionBatch('delete',[${f.id}]);event.stopPropagation();return false;" style="color:red">🗑️ Eliminar</a>` : ''}` : `
                    <a href="#" onclick="openShareModal(${f.id}, event)">🔗 Compartir</a>
                    <a href="#" onclick="dcAction(event,()=>toggleFavorite(${f.id}))">⭐ ${Number(f.favorite)?'Quitar favorito':'Favorito'}</a>
                    <a href="#" onclick="dcAction(event,()=>showProperties(${f.id}))">ℹ️ Propiedades</a>
                    ${Number(f.received_highlight)?`<a href="#" onclick="dcAction(event,()=>dcClearReceivedMark(${f.id}))">📥 Quitar distintivo de recepción</a>`:''}
                    ${f.is_dir ? `<a href="#" onclick="dcAction(event,()=>toggleVersions(${f.id},${Number(f.version_enabled)?0:1}))">🕘 ${Number(f.version_enabled)?'Desactivar':'Activar'} historial</a><a href="#" onclick="dcAction(event,()=>createReceive(${f.id}))">📥 Recibir archivos aquí</a>` : `<a href="#" onclick="dcAction(event,()=>showVersions(${f.id}))">🕘 Historial de versiones</a>`} 
                    <hr style="margin:4px 0; border:0; border-top:1px solid var(--border-color);">
                    <a href="#" onclick="actionBatch('move', [${f.id}]); event.stopPropagation();">✂️ Cortar</a>
                    <a href="#" onclick="actionBatch('copy', [${f.id}]); event.stopPropagation();">📋 Copiar</a>
                    <a href="#" onclick="actionBatch('delete', [${f.id}]); event.stopPropagation();" style="color:red;">🗑️ Eliminar</a>` }
                </div>
            </div>`;
    });
    dcSweepSkeletons(grid);
    dcAlbumArtSync(files,isSearchResult);
    // v75: la decoración se coordina al terminar dcyRefresh(), no durante renderFiles().
}

async function openFileMenu(e, id) {
    // Clasificaciones consultadas en backend antes de mostrar el menú.
    // Evita ofrecer conversiones no permitidas en una carpeta especial.
    const thisCard=document.getElementById(`file-item-${id}`);
    const thisMenu=document.getElementById(`fileMenu-${id}`);
    if(thisCard && thisMenu && DCI18n.original(thisCard.querySelector('.file-meta')).includes('Carpeta')) {
      try {
        const r=await fetch('api.php?action=folder_modes_85m&id='+encodeURIComponent(id),{credentials:'same-origin',cache:'no-store'});
        const j=await r.json();if(!r.ok||!j.success)throw Error('Estado no disponible');
        const m=j.modes||{};
        const parentYT=!!j.parent_youtube;
        const parentMusic=!!j.parent_music;
        const musicCollection=!!j.music_collection;
        const mc=thisMenu.querySelector('[data-dc-music-collection-hf3]');
        if(mc){
          mc.hidden=!parentMusic;
          mc.textContent=musicCollection?'♫ Quitar modo colección musical':'♫ Configurar modo colección musical';
          mc.onclick=async ev=>{
            ev.preventDefault();ev.stopPropagation();thisMenu.classList.remove('show');
            if(!confirm(musicCollection?'¿Quitar modo colección musical? Las canciones permanecerán intactas.':'¿Configurar modo colección musical?'))return;
            try{
              const body=new URLSearchParams({action:'set_collection_hf3',folder_id:String(id),enabled:musicCollection?'0':'1',csrf:window.DC_AUTH?.csrf||''});
              const r=await fetch('dc_music_81.php',{method:'POST',credentials:'same-origin',cache:'no-store',body});
              const d=await r.json();if(!r.ok||d.error||!d.success)throw Error(d.error||'No se pudo cambiar el modo');
              await loadFiles();
            }catch(err){alert(err.message||String(err));}
          };
        }
        for(const a of thisMenu.querySelectorAll('a')){
          const t=DCI18n.original(a);
          if(a.hasAttribute('data-dc-music-collection-hf3'))continue;
          const kind=/YouTube Links|modo YT Links/.test(t)?'youtube':/biblioteca musical|modo música/.test(t)?'music':/modo álbum|modo Album|álbum de fotos/.test(t)?'album':/colección de videos/.test(t)?'video':null;
          if(!kind)continue;
          const active=!!m[kind];
          const conflict=Object.entries(m).some(([k,on])=>on&&k!==kind);
          a.hidden=kind==='video'?(!active&&!parentYT)||conflict:conflict;
          if(kind==='youtube')a.textContent=active?'▶ Quitar modo YT Links':'▶ Configurar modo YT Links';
          if(kind==='music')a.textContent=active?'♫ Quitar modo biblioteca musical':'♫ Configurar modo biblioteca musical';
          if(kind==='album')a.textContent=active?'🖼️ Quitar modo Album':'🖼️ Configurar modo Album';
          if(kind==='video')a.textContent=active?'🎬 Quitar modo colección de videos':'🎬 Configurar modo colección de videos';
        }
      }catch(err){
        // Fallar cerrado: sin estado fiable, no presentar acciones de conversión.
        for(const a of thisMenu.querySelectorAll('a'))if(/YouTube Links|biblioteca musical|modo música|modo álbum|modo Album|álbum de fotos|colección de videos/.test(DCI18n.original(a)))a.hidden=true;
        console.warn('No se pudo verificar clasificación de carpeta:',err);
      }
    }
    e.stopPropagation();
    dcCloseContextMenus();
    const menu = document.getElementById(`fileMenu-${id}`);
    if (!menu) return;
    menu.classList.add('show');
    menu.closest('.file-item')?.classList.add('dc-menu-layer75');
    menu.closest('.dc-media-section73, .dc-collections-section69')?.classList.add('dc-menu-layer75');
    // position:fixed respecto al viewport: el menú no entra en el flujo del grid
    // y no provoca barra de scroll / reflujo de anchos en la última fila.
    const item = menu.closest('.file-item');
    const button = item?.querySelector('.menu-dots');
    if (!item || !button) return;
    window.dcFloatContextMenu(menu,button);
}

// DC85L: historial propio de carpetas, independiente del historial del navegador.
const dcFolderHistory85l=[0];let dcFolderIndex85l=0;let dcFolderNavigating85l=false;
function dcRememberFolder85l(id){
 id=Number(id);if(dcFolderNavigating85l||dcFolderHistory85l[dcFolderIndex85l]===id)return;
 dcFolderHistory85l.splice(dcFolderIndex85l+1);
 dcFolderHistory85l.push(id);
 if(dcFolderHistory85l.length>100)dcFolderHistory85l.shift();
 dcFolderIndex85l=dcFolderHistory85l.length-1;
}
function dcNavigateHistory85l(direction){
 const next=dcFolderIndex85l+direction;
 if(next<0||next>=dcFolderHistory85l.length)return;
 const target=dcFolderHistory85l[next];
 if(target!==0&&!allFoldersData.some(f=>Number(f.id)===target))return;
 dcFolderNavigating85l=true;
 try{openFolder(target);dcFolderIndex85l=next;}finally{dcFolderNavigating85l=false;}
}
function dcGoParent85l(){
 const node=allFoldersData.find(f=>Number(f.id)===Number(currentParentId));
 if(Number(currentParentId)>0)openFolder(Number(node?.parent_id)||0);
}
function dcIsTyping85l(el){
 return !!el?.closest?.('input,textarea,select,[contenteditable=""],[contenteditable="true"],[role="textbox"],.monaco-editor,.CodeMirror,.cm-editor');
}
function dcHasOpenDialog85l(){
 return [...document.querySelectorAll('dialog[open],[role="dialog"],.modal')].some(el=>{
  if(el.hidden)return false;
  const cs=getComputedStyle(el);
  return cs.display!=='none'&&cs.visibility!=='hidden'&&el.getBoundingClientRect().width>0;
 });
}
function openFolder(id) {
    if (isSearching) { isSearching = false; document.getElementById('searchInput').value = ''; }

    id = Number(id);
    // folderStack siempre debe estar ordenado desde la raíz hasta la ubicación actual.
    // Antes se utilizaba unshift() sobre [0], dejando la ruta invertida.
    const path = [];
    const visited = new Set();
    let cursor = id;
    while (cursor !== 0) {
        if (visited.has(cursor)) {
            console.error('Ciclo detectado en el árbol de directorios:', cursor);
            return;
        }
        visited.add(cursor);
        const node = allFoldersData.find(f => Number(f.id) === cursor);
        if (!node) {
            console.error('No se encontró la carpeta en el árbol:', cursor);
            return;
        }
        path.unshift(cursor);
        cursor = Number(node.parent_id);
        if (!Number.isFinite(cursor)) return;
    }
    folderStack = [0, ...path];
    currentParentId = id;
    dcRememberFolder85l(id);
    loadFiles();
}

// Función mantenida por si la ocupas, aunque quitamos el botón visual "Subir nivel" en modo Drive
function goUp() { dcGoParent85l(); }

// --- PORTAPAPELES Y BATCH ---
function updatePasteButton() {
    const pt = document.getElementById('pasteToolbar');
    if (pt) pt.style.display = clipBoard.ids.length > 0 ? 'flex' : 'none';
}

function cancelPaste() {
    clipBoard = { action: null, ids: [] };
    updatePasteButton();
}

function actionBatch(actionStr, specificIds = null) {
    dcCloseContextMenus();
    let idsToProcess = specificIds || Array.from(selectedFiles);
    if (idsToProcess.length === 0) return;
    
    if (actionStr === 'delete') {
        if (!confirm(`¿Enviar ${idsToProcess.length} elemento(s) a la papelera?`)) return;
        executeBatch('delete', idsToProcess);
    } else if (actionStr === 'share') {
        openShareModal(idsToProcess[0], window.event);
    } else {
        clipBoard = { action: actionStr, ids: idsToProcess };
        clearSelection();
        updatePasteButton();
    }
}

async function executeBatch(actionStr, idsArray, destId = null) {
    document.getElementById('uploadProgress').style.display = 'block';
    document.getElementById('uploadStatusText').innerText = "Procesando...";
    const fd = new FormData();
    fd.append('action', actionStr);
    fd.append('ids', JSON.stringify(idsArray));
    if (destId !== null) fd.append('new_parent_id', destId);
    
    const res = await fetch('api.php', { method:'POST', body:fd });
    const json = await res.json();
    document.getElementById('uploadProgress').style.display = 'none';
    if(!res.ok || json.error) { alert(json.error || 'Operación no permitida'); return false; }
    clearSelection();
    loadTree();
    loadFiles();
    window.dcUndoRefresh85l?.();
    return true;
}

async function actionDelete(id, e) {
    if(e) e.stopPropagation();
    if (!confirm("¿Eliminar este elemento permanentemente?")) return;
    
    const fd = new FormData();
    fd.append('action', 'delete');
    fd.append('ids', JSON.stringify([id]));
    const res = await fetch('api.php', { method:'POST', body: fd});
    const json = await res.json();
    if(json.error) alert(json.error);else window.dcUndoRefresh85l?.();
    
    // Si borramos la carpeta actual desde el breadcrumb, subimos de nivel
    if(id == currentParentId) goUp();
    else {
        loadTree();
        loadFiles();
    }
}


async function pasteItem() {
    if (!clipBoard.ids.length) return;
    const completed = await executeBatch(clipBoard.action, clipBoard.ids, currentParentId);
    if (completed && clipBoard.action === 'move') { clipBoard = { action: null, ids: [] }; updatePasteButton(); }
}


// --- DRAG DROP INTERNO ---
let dcTransferSequence = 0;
function dcTransferPanel() {
    let panel=document.getElementById('dcUploadQueue');
    if (!panel) {
        panel=document.createElement('aside');panel.id='dcUploadQueue';panel.className='dc-upload-queue';
        panel.innerHTML='<div class="dc-upload-head">⇅ Transferencias <button type="button" id="dcUploadCollapse" title="Minimizar">−</button></div><div class="dc-upload-items" id="dcUploadItems"></div>';
        document.body.appendChild(panel);
        if (typeof dcUploadAvoidDiskOverlap==='function') dcUploadAvoidDiskOverlap();
        document.getElementById('dcUploadCollapse').addEventListener('click',()=>panel.classList.toggle('dc-upload-minimized'));
    } else {
        const head=panel.querySelector('.dc-upload-head');
        if (head && !DCI18n.original(head).includes('Transferencias') && !DCI18n.original(head).includes('subidas')) {
            /* keep existing title */
        } else if (head && head.childNodes[0]) {
            head.childNodes[0].textContent='⇅ Transferencias ';
        }
    }
    return panel;
}
function dcTransferAdd(name, kind) {
    dcTransferPanel();
    const id='dc-xfer-'+(++dcTransferSequence);
    const row=document.createElement('div');row.className='dc-upload-row';row.id=id;
    const icon=kind==='download'?'⬇️':(kind==='rotate'?'↻':'⬆️');
    row.innerHTML=`<div class="dc-upload-line"><span class="dc-upload-name"></span><button type="button" class="dc-upload-dismiss" title="Cerrar">×</button></div><div class="dc-upload-state">${icon} En espera</div><progress max="100" value="0"></progress>`;
    row.querySelector('.dc-upload-name').textContent=name;
    row.querySelector('.dc-upload-dismiss').addEventListener('click',()=>{
        row.remove();
        if (typeof dcUploadArrangeNotifications==='function') dcUploadArrangeNotifications();
        if(!document.querySelector('#dcUploadItems .dc-upload-row')) document.getElementById('dcUploadQueue')?.remove();
    });
    document.getElementById('dcUploadItems').appendChild(row);
    if (typeof dcUploadArrangeNotifications==='function') dcUploadArrangeNotifications();
    return row;
}
function dcTransferStatus(row,state,progress=null) {
    if (!row) return;
    row.querySelector('.dc-upload-state').textContent=state;
    const bar=row.querySelector('progress');
    if (progress!==null && bar) bar.value=progress;
    if (progress===100) {
        row.classList.add('dc-upload-completed');
        setTimeout(()=>{if(row.isConnected){row.classList.add('dc-upload-fade');setTimeout(()=>{row.remove();if(!document.querySelector('#dcUploadItems .dc-upload-row'))document.getElementById('dcUploadQueue')?.remove()},550)}},2500);
    }
}
/** Descarga con barra de progreso (clic en Descargar). */
async function dcDownloadWithProgress(fileId, fileName) {
    const row=dcTransferAdd(fileName||('archivo '+fileId),'download');
    dcTransferStatus(row,'⬇️ Preparando',0);
    try {
        const url=`api.php?action=download&id=${encodeURIComponent(fileId)}&download=1`;
        const res=await fetch(url,{credentials:'same-origin'});
        if (!res.ok) throw Error('HTTP '+res.status);
        const total=Number(res.headers.get('Content-Length'))||0;
        let blob;
        if (res.body && total>0 && typeof res.body.getReader==='function') {
            const reader=res.body.getReader();
            const chunks=[];
            let received=0;
            while (true) {
                const {done,value}=await reader.read();
                if (done) break;
                chunks.push(value);
                received+=value.length;
                const pct=Math.min(99, Math.round(received/total*100));
                dcTransferStatus(row,`⬇️ Descargando · ${pct}%`,pct);
            }
            blob=new Blob(chunks);
        } else {
            dcTransferStatus(row,'⬇️ Descargando…',30);
            blob=await res.blob();
        }
        const a=document.createElement('a');
        const obj=URL.createObjectURL(blob);
        a.href=obj;
        a.download=fileName||'download';
        document.body.appendChild(a);
        a.click();
        a.remove();
        setTimeout(()=>URL.revokeObjectURL(obj), 60_000);
        dcTransferStatus(row,'⬇️ Completado',100);
    } catch (e) {
        dcTransferStatus(row,'⬇️ Error: '+(e.message||'fallo'),null);
        row.classList.add('dc-upload-error');
    }
}
function dragStart(e, id) {
    if (!selectedFiles.has(id)) {
        selectedFiles.clear();
        selectedFiles.add(id);
        updateSelectionUI();
    }
    const dt=e.dataTransfer;
    if (!dt) return;
    // Conservar la operacion interna de mover archivos y carpetas.
    const ids=Array.from(selectedFiles);
    const payload=JSON.stringify(ids);
    dt.setData('text/plain',payload);
    dt.setData('application/x-drivecraft-ids',payload);
    dt.effectAllowed='copyMove';

    // Compatibilidad experimental de Chromium/Edge: al soltar un UNICO
    // archivo sobre el explorador del SO, el navegador puede descargarlo.
    // No es una API web estandar; no garantiza compatibilidad en todos los SO.
    const meta=dcDragFileMeta.get(Number(id));
    if (ids.length===1 && meta && !meta.isDir) {
        const safeName=meta.name.replace(/[\\/:*?"<>|\x00-\x1f]/g,'_').trim().slice(0,220) || 'archivo';
        const url=new URL(`api.php?action=download&id=${encodeURIComponent(id)}`,window.location.href).href;
        dt.setData('DownloadURL', `application/octet-stream:${safeName}:${url}`);
        // Aviso en Drive: el SO gestiona la descarga real; aquí solo informamos.
        const row=dcTransferAdd(meta.name,'download');
        dcTransferStatus(row,'⬇️ Arrastre al sistema · el navegador gestiona la copia',10);
        setTimeout(()=>{ if(row.isConnected) dcTransferStatus(row,'⬇️ Solicitada al sistema (progreso en el explorador de archivos)',30); },400);
        setTimeout(()=>{ if(row.isConnected && !row.classList.contains('dc-upload-completed')) { dcTransferStatus(row,'⬇️ Entregada al sistema',100); } },8000);
    }
    e.stopPropagation();
}

function dragOverFolder(e) { e.preventDefault(); e.stopPropagation(); e.currentTarget.classList.add('drag-over'); if (e.dataTransfer) e.dataTransfer.dropEffect = e.dataTransfer.types.includes('Files') ? 'copy' : 'move'; }
async function dropOnFolder(e, targetFolderId) {
    e.preventDefault(); e.stopPropagation(); e.currentTarget.classList.remove('drag-over');
    
    // Los archivos externos tienen prioridad frente al texto que algunos exploradores también envían.
    if (e.dataTransfer.files && e.dataTransfer.files.length) {
        if(e.dataTransfer.items?.length) await dcHandleDroppedItems(e.dataTransfer.items,Number(targetFolderId));
        else await handleFiles(Array.from(e.dataTransfer.files), Number(targetFolderId));
        return;
    }
    // Nunca interpretar como IDs el texto arrastrado desde otras aplicaciones.
    if (!Array.from(e.dataTransfer.types||[]).includes('application/x-drivecraft-ids')) return;
    const dataStr = e.dataTransfer.getData('application/x-drivecraft-ids');
    if (!dataStr) return;

    let draggedIds = [];
    try { draggedIds = JSON.parse(dataStr); } catch(e) { draggedIds = [dataStr]; }
    
    if (draggedIds.length > 0 && !draggedIds.includes(targetFolderId.toString()) && !draggedIds.includes(targetFolderId)) {
        await executeBatch('move', draggedIds, targetFolderId);
    }
}

// Iconos locales por extensión: no requiere librerías, red ni fuentes externas.
// El icono se basa en la extensión del NOMBRE, no solo en el MIME (a veces octet-stream).
function dcDocumentIcon(code, color, kind='document') {
    const glyph=String(code).replace(/[^A-Z0-9+#]/g,'').slice(0,5);
    const colors={
        word:'#2563eb', excel:'#168347',powerpoint:'#d45735',
        code:'#64748b',javascript:'#eab308',typescript:'#2878c7',
        python:'#3775a9',php:'#777bb4',html:'#e5532d',css:'#2678bf',
        yaml:'#9b57b6',exe:'#5c667b',shell:'#1c897b',data:'#8b5bc8',
        binary:'#58657e',document:'#64748b'
    };
    const fill=colors[color]||colors.document;
    const inner=kind==='app'
        ? `<rect x="4" y="6" width="28" height="25" rx="6" fill="${fill}"/><rect x="8" y="10" width="20" height="17" rx="2" fill="#ffffff" opacity=".12"/><text x="18" y="21.9" text-anchor="middle" fill="white" font-size="${glyph.length>3?7.5:9.5}" font-weight="800" font-family="Arial, sans-serif">${glyph}</text>`
        : `<path d="M8 2h15l7 7v23a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2z" fill="#f7fafc" stroke="${fill}" stroke-width="1.4"/><path d="M23 2v7h7" fill="${fill}" opacity=".28"/><rect x="4" y="15" width="28" height="15" rx="3" fill="${fill}"/><text x="18" y="25.8" text-anchor="middle" fill="white" font-size="${glyph.length>3?7.5:10.5}" font-weight="800" font-family="Arial, sans-serif">${glyph}</text>`;
    return `<svg class="dc-doc-type-icon" style="width:var(--dc-icon-size,36px);height:var(--dc-icon-size,36px);max-width:100%;vertical-align:middle" viewBox="0 0 36 36" width="36" height="36" aria-hidden="true" focusable="false" xmlns="http://www.w3.org/2000/svg">${inner}</svg>`;
}
// Identificación orientativa. Este indicador NO valida el contenido ni declara corrupción.
// 'octet-stream' significa MIME desconocido; otros pares son incompatibilidades claras.
function dcFormatCheck(filename,mime) {
    const name=String(filename||'').toLowerCase();
    const ext=name.includes('.') ? name.split('.').pop() : '';
    const type=String(mime||'').toLowerCase().split(';')[0].trim();
    const families={
      pdf:'pdf', doc:'word',docx:'word',docm:'word',dotx:'word',rtf:'word',
      xls:'excel',xlsx:'excel',xlsm:'excel',xlsb:'excel',ods:'excel',csv:'text',tsv:'text',
      ppt:'powerpoint',pptx:'powerpoint',pptm:'powerpoint',pps:'powerpoint',ppsx:'powerpoint',odp:'powerpoint',
      png:'image',jpg:'image',jpeg:'image',gif:'image',webp:'image',avif:'image',bmp:'image',tiff:'image',tif:'image',svg:'image',heic:'image',heif:'image',
      js:'text',mjs:'text',cjs:'text',jsx:'text',ts:'text',tsx:'text',php:'text',phtml:'text',yaml:'text',yml:'text',py:'text',pyw:'text',
      html:'text',htm:'text',css:'text',scss:'text',less:'text',json:'text',xml:'text',sql:'text',toml:'text',md:'text',txt:'text',log:'text',
      zip:'archive',rar:'archive','7z':'archive',tar:'archive',gz:'archive',gzip:'archive',
      mp3:'audio',wav:'audio',flac:'audio',ogg:'audio',mp4:'video',webm:'video',mkv:'video',mov:'video',
      exe:'executable',msi:'executable',dll:'executable',bat:'text',cmd:'text',sh:'text',ps1:'text'
    };
    const family=families[ext];
    if(!family)return '';
    if(!type || type==='application/octet-stream' || type==='binary/octet-stream' || type==='application/x-download')return 'unverified';
    let compatible=false;
    switch(family){
      case 'pdf': compatible=type==='application/pdf'||type==='application/x-pdf';break;
      case 'image': compatible=type.startsWith('image/');break;
      case 'audio': compatible=type.startsWith('audio/');break;
      case 'video': compatible=type.startsWith('video/');break;
      case 'word': compatible=/msword|wordprocessingml|rtf|opendocument\.text/.test(type);break;
      case 'excel': compatible=/excel|spreadsheet|opendocument\.spreadsheet/.test(type);break;
      case 'powerpoint': compatible=/powerpoint|presentation|opendocument\.presentation/.test(type);break;
      case 'archive': compatible=/zip|rar|7z|tar|gzip|x-gzip|compressed|x-7z/.test(type);break;
      case 'executable': compatible=/x-msdownload|x-dosexec|x-msi|x-ms-installer|vnd\.microsoft\.portable-executable|x-executable/.test(type);break;
      case 'text': compatible=type.startsWith('text/') || /json|javascript|xml|yaml|typescript|x-php|x-python|x-sh|x-shellscript|x-sql/.test(type);break;
    }
    // Algunos navegadores declaran Office Open XML como ZIP por ser contenedores.
    if(['docx','xlsx','pptx','docm','xlsm','pptm'].includes(ext)&&/application\/zip|x-zip-compressed/.test(type))compatible=true;
    // Un ZIP también se utiliza para varios formatos ODF.
    if(['ods','odp'].includes(ext)&&/application\/zip/.test(type))compatible=true;
    return compatible?'':'mismatch';
}
function dcQuestionFileIcon() {
 return `<svg class="dc-doc-type-icon" style="width:var(--dc-icon-size,36px);height:var(--dc-icon-size,36px);max-width:100%;vertical-align:middle" viewBox="0 0 36 36" width="36" height="36" aria-hidden="true" xmlns="http://www.w3.org/2000/svg"><path d="M8 2h15l7 7v23a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2z" fill="#f8fafc" stroke="#64748b" stroke-width="1.4"/><path d="M23 2v7h7" fill="#cbd5e1"/><rect x="4" y="15" width="28" height="16" rx="4" fill="#c08429"/><text x="18" y="27" text-anchor="middle" fill="white" font-size="17" font-weight="bold" font-family="Arial,sans-serif">?</text></svg>`;
}
function getIconForMime(mime, filename='') {
    if(dcFormatCheck(filename,mime))return dcQuestionFileIcon();
    const ext=String(filename||'').toLowerCase().split('.').pop();
    const formats={
        doc:['W','word'],docx:['W','word'],docm:['W','word'],dotx:['W','word'],rtf:['RTF','word'],
        xls:['X','excel'],xlsx:['X','excel'],xlsm:['X','excel'],xlsb:['X','excel'],ods:['ODS','excel'],
        ppt:['P','powerpoint'],pptx:['P','powerpoint'],pptm:['P','powerpoint'],pps:['P','powerpoint'],ppsx:['P','powerpoint'],odp:['ODP','powerpoint'],
        exe:['EXE','exe','app'],msi:['MSI','exe','app'],bat:['BAT','exe','app'],cmd:['CMD','exe','app'],dll:['DLL','binary'],
        yaml:['YML','yaml'],yml:['YML','yaml'],js:['JS','javascript'],mjs:['JS','javascript'],cjs:['JS','javascript'],jsx:['JSX','javascript'],
        ts:['TS','typescript'],tsx:['TSX','typescript'],php:['PHP','php'],phtml:['PHP','php'],
        htm:['HTML','html'],html:['HTML','html'],css:['CSS','css'],scss:['SCSS','css'],less:['LESS','css'],
        py:['PY','python'],pyw:['PY','python'],sh:['SH','shell'],bash:['SH','shell'],ps1:['PS1','shell'],
        sql:['SQL','data'],json:['JSON','data'],xml:['XML','code'],toml:['TOML','code'],
        java:['JAVA','code'],go:['GO','code'],rs:['RS','code'],rb:['RB','code'],c:['C','code'],cpp:['C++','code'],
        cs:['C#','code'],swift:['SWIFT','code'],kt:['KT','code'],vue:['VUE','code'],svelte:['SV','code'],
        md:['MD','document'],txt:['TXT','document'],log:['LOG','document'],csv:['CSV','excel'],tsv:['TSV','excel']
    };
    if(Object.prototype.hasOwnProperty.call(formats,ext))return dcDocumentIcon(...formats[ext]);
    const type=String(mime||'').toLowerCase();
    if (type.includes('pdf')) return '📕';
    if (type.includes('spreadsheet')||type.includes('excel')) return dcDocumentIcon('X','excel');
    if (type.includes('wordprocessing')||type.includes('msword')) return dcDocumentIcon('W','word');
    if (type.includes('presentation')||type.includes('powerpoint')) return dcDocumentIcon('P','powerpoint');
    if (type.startsWith('video/')) return '🎬';
    if (type.startsWith('audio/')) return '🎵';
    if (type.startsWith('image/')) return '🖼️';
    if (['zip','rar','tar','7z','gzip','xz','bzip2'].some(x=>type.includes(x))) return '🗜️';
    if (type.startsWith('text/')) return '📄';
    return '📝';
}

// Nota de texto rápida: nunca se genera HTML con el texto escrito por el usuario.
function dcNewTextNote() {
    dcUtility('📝 Nueva nota de texto', `
      <label for="dcNoteName">Nombre del archivo</label>
      <input id="dcNoteName" type="text" maxlength="240" value="nota.txt" style="width:100%;box-sizing:border-box;margin:6px 0 12px">
      <label for="dcNoteContent">Contenido</label>
      <label class="dc-editor-wrap"><input type="checkbox" id="dcNewNoteWrap"> Ajustar líneas (Wrap text)</label>
      <textarea id="dcNoteContent" wrap="off" rows="10" style="display:block;width:100%;box-sizing:border-box;margin:6px 0 12px;resize:vertical;white-space:pre;overflow-wrap:normal" placeholder="Escribe una nota..."></textarea>
      <button class="btn-primary" type="button" id="dcNoteSave" onclick="dcSaveTextNote()">Guardar nota</button>
      <p id="dcNoteFeedback" role="status" style="margin-top:8px"></p>`);
    const area=document.getElementById('dcNoteContent');
    document.getElementById('dcNewNoteWrap').onchange=event=>{const wrap=event.target.checked;area.wrap=wrap?'soft':'off';area.style.whiteSpace=wrap?'pre-wrap':'pre';area.style.overflowWrap=wrap?'anywhere':'normal';};
    area.focus();
}
async function dcSaveTextNote() {
    const button=document.getElementById('dcNoteSave');
    const message=document.getElementById('dcNoteFeedback');
    const name=document.getElementById('dcNoteName').value.trim();
    const content=document.getElementById('dcNoteContent').value;
    if (!name.toLowerCase().endsWith('.txt')) {message.textContent='El nombre debe terminar en .txt';return;}
    button.disabled=true;
    try {
        await dcPost('create_text',{parent_id:currentParentId,name,content});
        closeModal('dcUtilityModal');
        await loadFiles();
        await loadTree();
    } catch(e) {message.textContent='No se pudo guardar: '+e.message;button.disabled=false;}
}

async function createFolder() {
    const name = prompt("Nombre de la carpeta:");
    if (!name) return;
    const fd = new FormData();
    fd.append('action', 'create_folder');
    fd.append('name', name);
    fd.append('parent_id', currentParentId);
    try {
        const res=await fetch('api.php',{method:'POST',body:fd});
        const data=await res.json();
        if(!res.ok || data.error) throw new Error(data.error || 'No se pudo crear la carpeta');
        await loadTree();
        await loadFiles();
    } catch(error) { alert('Error al crear carpeta: '+error.message); }
}

function dcExtensionColor59(name){
 const ext=String(name||'').toLowerCase().split('.').pop();
 if(['txt','log','md','markdown','rst'].includes(ext))return 'text';
 if(['php','js','mjs','cjs','ts','tsx','jsx','py','java','c','cpp','h','hpp','go','rs','rb','sh','bash','zsh','sql'].includes(ext))return 'code';
 if(['html','htm','css','scss','json','xml','yaml','yml','toml','ini','conf'].includes(ext))return 'config';
 if(['pdf'].includes(ext))return 'pdf';
 if(['zip','7z','rar','tar','gz','bz2','xz','zst'].includes(ext))return 'archive';
 if(['jpg','jpeg','png','webp','gif','avif','bmp','tiff','svg'].includes(ext))return 'image';
 if(['mp4','mkv','mov','avi','webm','mpeg'].includes(ext))return 'video';
 if(['mp3','flac','wav','aac','ogg','m4a'].includes(ext))return 'audio';
 return 'other';
}

async function viewFile(id, mime) {
    const oldFloat85=document.querySelector('#dcFloat85 [data-close]');if(oldFloat85)oldFloat85.click();
    // HF2: las aperturas y visualizaciones no forman parte de Recientes.
    document.getElementById('viewerToolbar').style.display = 'none';
    const dcItem57=document.getElementById('file-item-'+id);
    const dcName57=dcItem57?.querySelector('.file-name')?.getAttribute('title')||'';
    if (/\.(?:txt|log|php|js|mjs|cjs|ts|tsx|jsx|yaml|yml|html|htm|java|md|markdown|css|json|xml|sql|py|sh|bash|zsh|c|h|cpp|hpp|cs|go|rs|rb|pl|ini|conf|cfg|toml|properties|csv|tsv)$/i.test(dcName57)) {await dcOpenTextEditor57(id);return;}

    if (mime.startsWith('video/')) {
        const dcVideo64=(window.dcVideoFiles64||[]).find(v=>v.id===Number(id));
        const videoName=dcVideo64?.name||dcName57||'Video';
        const vTitle=document.getElementById('viewerTitle');
        vTitle.textContent=videoName;
        vTitle.title=videoName;
        vTitle.style.cssText='font-size:15px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;max-width:calc(100% - 40px)';
        const playlist=window.dcVideoFiles64||[];
        const index=playlist.findIndex(v=>v.id===Number(id));
        const nav=index<0?'':`<div class="dc-video-nav64"><button type="button" aria-label="Video anterior" onclick="dcVideoSlide64(-1)" ${index===0?'disabled':''}>❮</button><button type="button" class="dc-seek85" title="Retroceder 10 segundos" aria-label="Retroceder 10 segundos" onclick="dcSeekVideo85(-10)">↶ 10 s</button><span>${index+1} / ${playlist.length}</span><button type="button" class="dc-seek85" title="Adelantar 10 segundos" aria-label="Adelantar 10 segundos" onclick="dcSeekVideo85(10)">10 s ↷</button><button type="button" aria-label="Siguiente video" onclick="dcVideoSlide64(1)" ${index===playlist.length-1?'disabled':''}>❯</button></div>`;
        document.getElementById('viewerBody').innerHTML = `<div class="dc-media-viewer-skel skel"><video controls autoplay onloadeddata="dcClearMediaSkeleton(this)" oncanplay="dcClearMediaSkeleton(this)" onerror="dcFailMediaSkeleton(this)" style="max-width: 100%; max-height: 60vh; display: block; margin: 0 auto;"><source src="api.php?action=download&id=${id}" type="${mime}"></video></div>${nav}`;
        window.dcCurrentVideo64=Number(id);
        document.getElementById('viewerModal').style.display = 'block';
        dcMediaVideoToolbar(id);
        const dcFloatBar85=document.getElementById('viewerBody');const dcFloatActions85=document.createElement('div');dcFloatActions85.className='dc-video-actions84b';dcFloatActions85.setAttribute('role','group');dcFloatActions85.setAttribute('aria-label','Acciones del video');const dcFloatButton85=document.createElement('button');dcFloatButton85.type='button';dcFloatButton85.className='dc-v84-action';dcFloatButton85.textContent='▣ Video flotante';dcFloatButton85.onclick=()=>dcFloatVideo85();dcFloatActions85.append(dcFloatButton85);dcFloatBar85.append(dcFloatActions85);
        return;
    }
    if (mime.startsWith('audio/')) {
        document.getElementById('viewerTitle').innerText = 'Reproductor de Audio';
        document.getElementById('viewerBody').innerHTML = `<div class="dc-media-viewer-skel skel"><audio controls autoplay onloadeddata="dcClearMediaSkeleton(this)" oncanplay="dcClearMediaSkeleton(this)" onerror="dcFailMediaSkeleton(this)" style="width: 100%;"><source src="api.php?action=download&id=${id}" type="${mime}"></audio></div>`;
        document.getElementById('viewerModal').style.display = 'block';
        return;
    }
    
    // VISUALIZADOR PDF (iframe)
    if ((mime||'').toLowerCase().includes('pdf') || /\.pdf$/i.test(dcName57)) {
        document.getElementById('viewerTitle').innerText = 'Visor PDF';
        document.getElementById('viewerBody').innerHTML = `<iframe src="api.php?action=download&id=${id}" style="width: 100%; height: 75vh; border: none;"></iframe>`;
        document.getElementById('viewerModal').style.display = 'block';
        return;
    }

    const dcName = document.querySelector(`#file-item-${id} .file-name`)?.textContent || '';
    const dcTextSource = Boolean(dcSyntaxLanguage(dcName, mime));
    if (!dcTextSource && !mime.startsWith('text/') && !mime.includes('json') && !mime.includes('zip') && !mime.includes('rar') && !mime.includes('7z') && !mime.includes('tar') && !mime.includes('gzip') && !mime.includes('xz') && !mime.includes('bzip2')) {
        window.open(`api.php?action=download&id=${id}`);
        return;
    }
    
    let data;
    try {
        const res = await fetch(`api.php?action=view&id=${id}`);
        data = await res.json();
        if (!res.ok || data.error) throw new Error(data.error || `HTTP ${res.status}`);
    } catch (error) {
        alert('No se pudo abrir el contenido: ' + error.message);
        return;
    }
    
    // v20.10.49: vista Markdown segura y opción para volver al texto original.
    if (/\.(?:md|markdown)$/i.test(dcName) && data.type !== 'archive' && typeof data.content === 'string') {
        const original = data.content;
        const body=document.getElementById('viewerBody');
        const toolbar=document.getElementById('viewerToolbar');
        document.getElementById('viewerTitle').textContent='Markdown · '+dcName;
        toolbar.style.display='none';
        const button=document.createElement('button');
        button.className='dc-markdown49-switch';button.type='button';
        let raw=false;
        const draw=()=>{
            button.textContent=raw?'Vista Markdown':'Ver texto original';
            body.innerHTML='';body.appendChild(button);
            const panel=document.createElement('div');
            if(raw){const pre=document.createElement('pre');pre.className='dc-code-preview';pre.textContent=original;panel.appendChild(pre);}
            else {panel.className='dc-markdown49';panel.innerHTML=window.dcMarkdown49Render(original);}
            body.appendChild(panel);
        };
        button.onclick=()=>{raw=!raw;draw();};draw();
        document.getElementById('viewerModal').style.display='block';
        return;
    }
    dcViewerIsArchive = data.type === 'archive';
    const filename = document.querySelector(`#file-item-${id} .file-name`)?.textContent || '';
    dcViewerLanguage = dcViewerIsArchive ? null : dcSyntaxLanguage(filename, mime);
    document.getElementById('viewerTitle').innerText = dcViewerIsArchive ? 'Contenido del comprimido' : ('Archivo de texto' + (dcViewerLanguage ? ' · ' + dcViewerLanguage.toUpperCase() : ''));
    currentViewerLines = data.content.split('\n');
    
    document.getElementById('viewerToolbar').style.display = 'flex';
    document.getElementById('viewerSearchInput').value = '';
    document.getElementById('viewerCaseSensitive').checked = false;
    
    filterViewer();
    document.getElementById('viewerModal').style.display = 'block';
}

// Resaltado ligero local, sin dependencias ni procesamiento en la Raspberry.
function dcSyntaxLanguage(filename, mime) {
    const extension = (filename.toLowerCase().match(/\.([a-z0-9]+)$/) || [])[1] || '';
    const lookup = {js:'javascript',mjs:'javascript',cjs:'javascript',ts:'javascript',tsx:'javascript',jsx:'javascript',php:'php',py:'python',sh:'shell',bash:'shell',zsh:'shell',sql:'sql',json:'json',jsonl:'json',css:'css',scss:'css',html:'html',htm:'html',xml:'html',svg:'html',yml:'yaml',yaml:'yaml',ini:'ini',conf:'ini',toml:'ini',md:'markdown',markdown:'markdown',c:'c',h:'c',cpp:'c',hpp:'c',java:'java',go:'go',rs:'rust',rb:'ruby',dockerfile:'shell'};
    if (lookup[extension]) return lookup[extension];
    const basename = filename.toLowerCase().split('/').pop();
    if (basename === 'dockerfile' || basename === '.bashrc' || basename === '.profile') return 'shell';
    if (mime.includes('json')) return 'json';
    if (mime.includes('javascript')) return 'javascript';
    if (mime.includes('xml') || mime.includes('html')) return 'html';
    return null;
}
function dcHighlightText(source, lang) {
    // Un solo paso: escapar cada fragmento antes de insertarlo en el DOM.
    // Limitar el coste en archivos de texto grandes y búsquedas muy amplias.
    if (source.length > 450000) return escapeHtml(source);
    const keywords = {
        javascript:'const let var function return async await if else for while switch case break continue class new throw try catch finally import export from default true false null undefined this typeof instanceof',
        php:'function return if else elseif foreach for while class public private protected static new namespace use echo null true false try catch throw extends implements',
        python:'def class return import from as if elif else for while in not and or is lambda yield with try except finally raise True False None async await self pass',
        shell:'if then else elif fi for in do done while case esac function export local readonly return',
        sql:'SELECT FROM WHERE JOIN LEFT RIGHT INNER OUTER ON INSERT INTO UPDATE DELETE CREATE TABLE ALTER DROP ORDER BY GROUP HAVING LIMIT AND OR NOT NULL AS DISTINCT VALUES SET PRIMARY KEY',
        json:'true false null',css:'important',html:'',yaml:'true false null yes no on off',ini:'true false on off',
        c:'int char void float double return if else for while switch case struct typedef const static unsigned long short sizeof',
        java:'public private protected static final class interface new return if else for while void int boolean String extends implements',
        go:'func package import var const type struct interface return if else for range go defer select chan',
        rust:'fn let mut pub impl struct enum trait use mod return if else for while match self Self',
        ruby:'def class module end if elsif else unless do while return require true false nil',markdown:''
    };
    const words = new Set((keywords[lang] || '').split(' ').filter(Boolean));
    const tokens = /(\/\*[\s\S]*?\*\/|\/\/[^\n]*|#[^\n]*|--[^\n]*|\"(?:\\.|[^\"\\])*\"|'(?:\\.|[^'\\])*'|`(?:\\.|[^`\\])*`|\b(?:\d+(?:\.\d+)?)\b|\b[A-Za-z_$][\w$]*\b|<\/?[A-Za-z][\w:-]*|\b[A-Za-z_-]+(?=\s*:))/g;
    let out = '', pos = 0, match;
    while ((match = tokens.exec(source)) !== null) {
        out += escapeHtml(source.slice(pos, match.index));
        const t = match[0]; let cls = '';
        if ((t.startsWith('//') && ['javascript','php','c','java','go','rust'].includes(lang)) ||
            (t.startsWith('#') && ['python','shell','ruby','yaml','ini'].includes(lang)) ||
            (t.startsWith('--') && lang === 'sql') || (t.startsWith('/*') && ['javascript','php','css','c','java','go','sql'].includes(lang))) cls = 'comment';
        else if (/^[\"'`]/.test(t)) cls = 'string';
        else if (/^\d/.test(t)) cls = 'number';
        else if (words.has(lang==='sql' ? t.toUpperCase() : t)) cls = 'keyword';
        else if (lang === 'html' && t.startsWith('<')) cls = 'keyword';
        else if (['css','yaml','ini','json'].includes(lang) && /^[A-Za-z_-]+$/.test(t) && source.slice(tokens.lastIndex).match(/^\s*:/)) cls = 'property';
        out += cls ? `<span class="dc-syn-${cls}">${escapeHtml(t)}</span>` : escapeHtml(t);
        pos = tokens.lastIndex;
    }
    return out + escapeHtml(source.slice(pos));
}

function filterViewer() {
    const q = document.getElementById('viewerSearchInput').value;
    const cs = document.getElementById('viewerCaseSensitive').checked;
    
    let filtered = currentViewerLines;
    if (q) {
        filtered = currentViewerLines.filter(line => {
            if (cs) return line.includes(q);
            return line.toLowerCase().includes(q.toLowerCase());
        });
    }
    const text = filtered.join('\n');
    // Resaltar solamente texto, nunca listados de comprimidos.
    const html = !dcViewerIsArchive && dcViewerLanguage ? dcHighlightText(text, dcViewerLanguage) : escapeHtml(text);
    document.getElementById('viewerBody').innerHTML = `<pre class="dc-code-preview"><code>${html}</code></pre>`;
}

function escapeHtml(unsafe) { return String(unsafe).replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;").replace(/"/g, "&quot;").replace(/'/g,"&#39;"); }

function closeModal(id) { 
 if(id==='viewerModal')window.DCMediaTools?.flush?.();
    if(id==='viewerModal'){if(window.DCTextEditor&&!window.DCTextEditor.canClose())return;window.DCTextEditor?.destroy();}
    document.getElementById(id).style.display = 'none'; 
    if(id === 'viewerModal') {
        document.getElementById('viewerBody').innerHTML = '';
        document.getElementById('viewerToolbar').style.display = 'none';
        currentViewerLines = [];
    }
}

// --- GESTION DE ENLACES COMPARTIDOS ---
async function openShareModal(id, e) {
    if(e) e.stopPropagation();
    dcCloseContextMenus();
    
    fileIdToShare = id;
    document.getElementById('shareResult').style.display = 'none';
    const copy=document.getElementById('dcCopyShareGenerated');if(copy)copy.style.display='none';
    document.getElementById('shareModal').style.display = 'block';
    await loadShares(id);
}

// Construir enlaces desde la URL externa que el navegador está utilizando,
// no desde el esquema HTTP interno entre Nginx y PHP.
function publicShareUrl(hashOrRelativeUrl) {
    const value = String(hashOrRelativeUrl);
    const relative = value.startsWith('api.php?public_hash=')
        ? value
        : `api.php?public_hash=${encodeURIComponent(value)}`;
    const url = new URL(relative, new URL('./', window.location.href));
    // La aplicación debe publicarse por HTTPS. No degradar enlaces compartidos.
    if (url.protocol === 'http:') url.protocol = 'https:';
    return url.href;
}

let allManagedShares = [];
function safeShareText(value) {
    return String(value ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
}
function durationMarkup(prefix) {
    return `<div class="share-duration-fields"><label>Días<input id="${prefix}Days" type="number" min="0" max="3650" value="0"></label><label>Horas<input id="${prefix}Hours" type="number" min="0" max="23" value="0"></label><label>Minutos<input id="${prefix}Minutes" type="number" min="0" max="59" value="0"></label></div>`;
}
function shareDuration(fd, prefix) {
    for (const [key, max] of [['Days',3650], ['Hours',23], ['Minutes',59]]) {
        const elem = document.getElementById(prefix + key);
        const value = Number(elem?.value ?? 0);
        if (!Number.isInteger(value) || value < 0 || value > max) throw Error(`Valor inválido en ${key}`);
        fd.append(key.toLowerCase(), value);
    }
}
async function shareRequest(action, data={}) {
    const fd = new FormData(); fd.append('action',action);
    for (const [k,v] of Object.entries(data)) fd.append(k,v);
    const response = await fetch('api.php', {method:'POST', body:fd});
    const result = await response.json();
    if (!response.ok || result.error) throw Error(result.error || 'Error de servidor');
    return result;
}
async function openShareManager() {
    document.getElementById('shareManagerModal').style.display='block';
    await refreshShareManager();
    await refreshReceiveLinks();
}
async function refreshShareManager() {
    const el = document.getElementById('shareManagerList');
    el.textContent = 'Cargando enlaces...';
    try {
        const response = await fetch('api.php?action=all_shares', {cache:'no-store'});
        const data = await response.json();
        if (!response.ok || data.error) throw Error(data.error || 'No se pudieron cargar los enlaces');
        allManagedShares = data.shares || [];
        renderShareManager();
    } catch (err) { el.textContent = err.message; }
}
function renderShareManager() {
    const list = document.getElementById('shareManagerList');
    const filter = document.getElementById('shareManagerSearch').value.toLocaleLowerCase();
    const rows = allManagedShares.filter(s => String(s.name).toLocaleLowerCase().includes(filter));
    if (!rows.length) { list.innerHTML='<p class="share-help">No se encontraron enlaces.</p>'; return; }
    list.innerHTML = rows.map(s => {
        const expiry = s.expires_at ? new Date(s.expires_at.replace(' ','T')) : null;
        const expired = expiry && expiry.getTime() <= Date.now();
        const url = publicShareUrl(s.hash);
        return `<div class="managed-share">
          <div class="managed-share-heading"><strong>${s.is_dir == 1 ? '📁' : '📄'} ${safeShareText(s.name)}</strong><span class="share-status ${expired?'expired':''}">${expired?'Vencido':'Vigente'}</span></div>
          <div class="share-help">${s.protected == 1 ? '🔒 Con contraseña · ' : ''}👁️ ${Number(s.views)||0} vistas · ⬇️ ${Number(s.downloads)||0} descargas · Creado: ${safeShareText(s.created_at)} · ${s.expires_at ? 'Vence: '+safeShareText(s.expires_at) : 'Sin vencimiento'}</div>
          <div class="managed-share-url"><a href="${safeShareText(url)}" target="_blank" rel="noopener noreferrer">${safeShareText(url)}</a></div>
          <div class="managed-share-actions"><button type="button" onclick="copyManagedShare(${Number(s.id)})">Copiar</button><span class="dc-copy-feedback" id="dc-copy-managed-${Number(s.id)}" role="status" aria-live="polite"></span><button type="button" onclick="editManagedShare(${Number(s.id)})">Cambiar duración</button><button type="button" class="share-danger" onclick="deleteShare(${Number(s.id)})">Revocar</button></div>
          <div id="shareEditor${Number(s.id)}" class="share-editor" hidden>${durationMarkup('edit'+Number(s.id))}<p class="share-help">Nueva duración contada desde ahora. Todo en cero = sin caducidad.</p><button type="button" onclick="saveManagedShare(${Number(s.id)})">Guardar vencimiento</button></div>
        </div>`;
    }).join('');
}
async function copyManagedShare(id) {
    const s=allManagedShares.find(item=>Number(item.id)===id);
    if (!s) return;
    const target=document.getElementById('dc-copy-managed-'+id);
    const ok=await dc41CopyText(publicShareUrl(s.hash));
    dc41Feedback(target,ok?'Copiado':'Selecciona el enlace');
}
function editManagedShare(id) {
    const e=document.getElementById('shareEditor'+id);
    e.hidden=!e.hidden;
}
async function saveManagedShare(id) {
    try {
        const fd=new FormData(); shareDuration(fd,'edit'+id);
        const data=Object.fromEntries(fd.entries()); data.share_id=id;
        await shareRequest('update_share',data);
        await refreshShareManager();
        if (fileIdToShare != null && document.getElementById('shareModal').style.display==='block') await loadShares(fileIdToShare);
    } catch(e) { alert(e.message); }
}
async function loadShares(fileId) {
    const list=document.getElementById('activeSharesList');
    try {
        const res=await fetch(`api.php?action=get_shares&id=${encodeURIComponent(fileId)}`,{cache:'no-store'});
        const json=await res.json();
        if (!res.ok || json.error) throw Error(json.error || 'Error al leer enlaces');
        list.innerHTML=(json.shares||[]).map(s=>{
          const url=publicShareUrl(s.hash);
          return `<li class="managed-share"><a href="${safeShareText(url)}" target="_blank" rel="noopener noreferrer">${safeShareText(url)}</a><div class="share-help">${s.expires_at?'Expira: '+safeShareText(s.expires_at):'Sin caducidad'}</div><button type="button" class="share-danger" onclick="deleteShare(${Number(s.id)})">Revocar</button></li>`;
        }).join('') || '<li>No hay enlaces para este elemento.</li>';
    } catch(e) { list.textContent=e.message; }
}
async function deleteShare(shareId) {
    if (!confirm('¿Revocar este enlace? Dejará de funcionar inmediatamente.')) return;
    try {
        await shareRequest('delete_share',{share_id:shareId});
        if (document.getElementById('shareManagerModal').style.display==='block') await refreshShareManager();
        if (fileIdToShare != null && document.getElementById('shareModal').style.display==='block') await loadShares(fileIdToShare);
    } catch(e) {alert(e.message);}
}
async function generateShareLink() {
    try {
        const fd=new FormData(); shareDuration(fd,'share');
        const params=Object.fromEntries(fd.entries());
        params.id=fileIdToShare;
        params.password=document.getElementById('sharePassword').value;
        const json=await shareRequest('share',params);
        const input=document.getElementById('shareResult');
        input.value=publicShareUrl(json.link);
        input.style.display='block'; input.select();
        const copy=document.getElementById('dcCopyShareGenerated');if(copy)copy.style.display='inline-block';
        const status=document.getElementById('dcCopyShareStatus');if(status)status.textContent='';
        await loadShares(fileIdToShare);
    } catch(e) {alert(e.message);}
}

// Cola persistente en pantalla: las subidas se serializan para evitar carreras de cuota.
let dcUploadTail = Promise.resolve();
let dcUploadSequence = 0;
function dcUploadPanel() {
    let panel=document.getElementById('dcUploadQueue');
    if (!panel) {
        panel=document.createElement('aside');panel.id='dcUploadQueue';panel.className='dc-upload-queue';
        panel.setAttribute('aria-label','Cola de subidas');
        panel.innerHTML='<div class="dc-upload-head">⬆️ Cola de subidas <button type="button" id="dcUploadCollapse" title="Minimizar">−</button></div><div class="dc-upload-items" id="dcUploadItems"></div>';
        document.body.appendChild(panel);
        dcUploadAvoidDiskOverlap();
        document.getElementById('dcUploadCollapse').addEventListener('click',()=>panel.classList.toggle('dc-upload-minimized'));
    }
    return panel;
}
function dcUploadAdd(file) {
    dcUploadPanel();
    const id='dc-upload-'+(++dcUploadSequence);
    const row=document.createElement('div');row.className='dc-upload-row';row.id=id;
    row.innerHTML='<div class="dc-upload-line"><span class="dc-upload-name"></span><button type="button" class="dc-upload-dismiss" title="Cerrar">×</button></div><div class="dc-upload-state">En espera</div><progress max="100" value="0"></progress>';
    row.querySelector('.dc-upload-name').textContent=file.name;
    row.querySelector('.dc-upload-dismiss').addEventListener('click',()=>{
        row.remove(); dcUploadArrangeNotifications();
        if(!document.querySelector('#dcUploadItems .dc-upload-row')) document.getElementById('dcUploadQueue')?.remove();
    });
    document.getElementById('dcUploadItems').appendChild(row);
    dcUploadArrangeNotifications();
    return row;
}
function dcUploadStatus(row,state,progress=null) {
    if (!row.isConnected) return;
    row.dataset.state=state;
    row.querySelector('.dc-upload-state').textContent=state;
    if (progress!==null) row.querySelector('progress').value=progress;
    // Completado, deduplicado instantáneo u otros finales al 100% (nunca errores).
    const done = progress===100 || state==='Completado' || /^Instante/i.test(state);
    if (done && !String(state).startsWith('Error') && !row.classList.contains('dc-upload-completed')) {
        row.classList.add('dc-upload-completed');
        setTimeout(()=>{if(row.isConnected){row.classList.add('dc-upload-fade');setTimeout(()=>{row.remove();if(!document.querySelector('#dcUploadItems .dc-upload-row'))document.getElementById('dcUploadQueue')?.remove()},550)}},2500);
    }
}
async function dcHandleDroppedItems(items,destination) {
 const entries=Array.from(items).filter(i=>i.kind==='file').map(i=>i.webkitGetAsEntry?.()).filter(Boolean);
 if(!entries.length){alert('Este navegador no permite identificar carpetas arrastradas. Usa Chrome o Edge actualizado.');return;}
 const entryFile=e=>new Promise((resolve,reject)=>e.file(resolve,reject));
 const readChildren=e=>new Promise((resolve,reject)=>{
   const reader=e.createReader(),all=[];
   const next=()=>reader.readEntries(batch=>{if(!batch.length)resolve(all);else{all.push(...batch);next();}},reject);
   next();
 });
 const mkdir=async(name,parent)=>{
   const fd=new FormData();fd.set('action','create_folder');fd.set('parent_id',String(parent));fd.set('name',name);
   const r=await fetch('api.php',{method:'POST',body:fd,headers:{'X-DC-CSRF':window.DC_AUTH?.csrf||''}});
   const j=await r.json();if(!r.ok||j.error||!j.id)throw Error(j.error||'No se pudo crear la carpeta '+name);
   return Number(j.id);
 };
 let uploaded=0;
 async function visit(e,parent,depth){
   if(depth>32)throw Error('Profundidad de carpetas superior a 32');
   if(e.isDirectory){const id=await mkdir(e.name,parent);for(const child of await readChildren(e))await visit(child,id,depth+1);}
   else if(e.isFile){const file=await entryFile(e);await handleFiles([file],parent);uploaded++;}
 }
 try{for(const e of entries)await visit(e,Number(destination),0);await loadTree();await loadFiles();}
 catch(e){alert('Subida parcial: '+e.message+'; revisa los archivos incorporados.');}
}
// v20.10.48: tarjetas sin contenedor desplazable, ajustar solapamiento solo cuando haga falta.
function dcUploadArrangeNotifications() {
    const panel=document.getElementById('dcUploadQueue');
    if(!panel) return;
    const list=panel.querySelector('#dcUploadItems');
    if(!list) return;
    const rows=Array.from(list.querySelectorAll('.dc-upload-row'));
    if(!rows.length) return;
    const available=Math.max(220,window.innerHeight*0.79);
    const heights=rows.map(r=>r.getBoundingClientRect().height||80);
    const total=heights.reduce((a,b)=>a+b,0);
    const gap=rows.length>1?Math.min(7,(available-total)/(rows.length-1)):0;
    list.style.setProperty('--dc-stack-gap',gap+'px');
}
if(!window.__dcUploadArrange48){
 window.__dcUploadArrange48=true;
 window.addEventListener('resize',dcUploadArrangeNotifications);
 const observer=new MutationObserver(()=>dcUploadArrangeNotifications());
 const hook=()=>{const list=document.getElementById('dcUploadItems');if(list&&!list.dataset.dcArranged){list.dataset.dcArranged='1';observer.observe(list,{childList:true});dcUploadArrangeNotifications();}};
 new MutationObserver(hook).observe(document.body,{childList:true});
 hook();
}

async function handleFiles(files, destinationFolderId = currentParentId) {
    const destination=Number(destinationFolderId);
    const batch=Array.from(files);
    if (!batch.length) return;
    await dcLoadUploadLimits();
    const tasks=batch.map(file=>{
        const row=dcUploadAdd(file);
        const task=dcUploadTail.catch(()=>{}).then(async()=>{
            dcUploadStatus(row,'Subiendo',0);
            try {
                const result=await uploadFileChunked(file,p=>dcUploadStatus(row,`Subiendo · ${p}%`,p),destination);
                if(result==='cancelled') dcUploadStatus(row,'Cancelado');
                else if(result==='dedup') dcUploadStatus(row,'Instante · deduplicado',100);
                else dcUploadStatus(row,'Completado',100);
            } catch(error) { dcUploadStatus(row,'Error: '+(error.message||'Error desconocido'));row.classList.add('dc-upload-error'); }
        });
        dcUploadTail=task;
        return task;
    });
    const picker=document.getElementById('fileInput');if(picker)picker.value='';
    await Promise.all(tasks);
    await loadTree();await loadFiles();
}
async function uploadFileChunked(file, onProgress, destinationFolderId = currentParentId) {
    let conflictMode='new';
    const url=`api.php?action=upload_conflict&parent_id=${encodeURIComponent(destinationFolderId)}&name=${encodeURIComponent(file.name)}`;
    const response=await fetch(url,{cache:'no-store'});
    const info=await response.json();
    if (!response.ok||info.error) throw Error(info.error||'No se pudo comprobar el destino');
    if (info.exists) {
        if (info.is_dir) throw Error('Ya existe una carpeta con ese nombre');
        if(info.versioned) conflictMode='version';
        else if(confirm(`Ya existe "${file.name}".\n\nAceptar: reemplazar el archivo anterior.\nCancelar: ver otras opciones.`)) conflictMode='replace';
        else if(confirm(`¿Conservar el archivo anterior renombrándolo automáticamente y subir "${file.name}"?\n\nAceptar: conservar ambos.\nCancelar: cancelar subida.`)) conflictMode='rename_old';
        else return 'cancelled';
    }
    // Fast upload: huella local (Merkle y/o SHA-256) → hardlink sin reenviar bytes.
    try {
        onProgress(0);
        const headHash = await dcHeadHashFile(file);
        const tryDedup = async (algorithm, contentHash) => {
            const fd = new FormData();
            fd.append('action','upload_dedup');
            fd.append('parentId', String(destinationFolderId));
            fd.append('fileName', file.name);
            fd.append('size', String(file.size));
            fd.append('algorithm', algorithm);
            fd.append('content_hash', contentHash);
            fd.append('head_hash', headHash);
            fd.append('conflict_mode', conflictMode);
            const res = await fetch('api.php', {method:'POST', body:fd});
            const body = await res.text();
            let json;
            try { json = JSON.parse(body); } catch(e) { json = null; }
            return (res.ok && json && json.dedup && json.file_id) ? json : null;
        };
        // 1) Merkle (subidas nuevas tras el parche)
        const merkleHash = await dcMerkleRootFile(file, p => onProgress(Math.min(35, Math.round(p * 0.35))));
        let hit = await tryDedup('merkle', merkleHash);
        // 2) SHA-256 lineal (archivos del backfill / legado)
        if (!hit && file.size <= 512 * MiB && crypto?.subtle) {
            onProgress(40);
            const linear = await dcSha256Raw(await file.arrayBuffer());
            onProgress(55);
            hit = await tryDedup('sha256', linear);
        }
        if (hit) {
            onProgress(100);
            dcMediaAfterUpload(Number(hit.file_id), file.name);
            return 'dedup';
        }
    } catch (e) {
        console.warn('Fast upload no disponible:', e);
    }
    const chunkSize=dcAdaptiveChunkSize(file.size);
    const totalChunks=Math.max(1,Math.ceil(file.size/chunkSize));
    const uploadId=Array.from(crypto.getRandomValues(new Uint8Array(16)),b=>b.toString(16).padStart(2,'0')).join('');
    for(let i=0;i<totalChunks;i++) {
        const fd=new FormData();
        fd.append('action','upload_chunk');fd.append('uploadId',uploadId);
        fd.append('chunk',file.slice(i*chunkSize,Math.min((i+1)*chunkSize,file.size)),file.name);
        fd.append('fileName',file.name);fd.append('chunkIndex',i);
        fd.append('totalChunks',totalChunks);fd.append('parentId',destinationFolderId);
        fd.append('conflict_mode',conflictMode);
        const res=await fetch('api.php',{method:'POST',body:fd});
        const body=await res.text();let json;
        try{json=JSON.parse(body)}catch(e){throw Error('Respuesta inválida de PHP: '+body.substring(0,160))}
        if(!res.ok||json.error) throw Error(json.error||`HTTP ${res.status}`);
        if(json.complete && json.file_id) dcMediaAfterUpload(Number(json.file_id),file.name);
        onProgress(Math.round((i+1)/totalChunks*100));
    }
    return 'success';
}

// --- THEMING CON PERSISTENCIA ---
function initTheming() {
    const hue = document.getElementById('hueSelect');
    
    let savedTheme = localStorage.getItem('dc_theme') || 'light';
    document.documentElement.setAttribute('data-theme', savedTheme);
    if(savedTheme === 'dark') document.getElementById('themeDark').checked = true;
    else document.getElementById('themeLight').checked = true;

    let savedColor = localStorage.getItem('dc_color') || '#5367d9';
    document.documentElement.style.setProperty('--primary-color', savedColor);
    if(hue) hue.value = savedColor;

    document.querySelectorAll('input[name="themeMode"]').forEach(radio => {
        radio.addEventListener('change', (e) => {
            document.documentElement.setAttribute('data-theme', e.target.value);
            localStorage.setItem('dc_theme', e.target.value);
        });
    });

    if(hue) {
        hue.addEventListener('input', (e) => { 
            document.documentElement.style.setProperty('--primary-color', e.target.value); 
            localStorage.setItem('dc_color', e.target.value);
        });
    }
}

function setAccentColor(value) {
 if (!/^#[0-9a-f]{6}$/i.test(value)) return;
 document.documentElement.style.setProperty('--primary-color', value);
 localStorage.setItem('dc_color', value);
}

// Funciones v20: UI ligera, sin observadores ni indexación en segundo plano.
function dcAction(e,callback){e.preventDefault();e.stopPropagation();callback();}
let dcAdminSubview85i=false;
function dcLaunchAdminSubview85i(fn){
 const title=document.getElementById('dcUtilityTitle');
 if(!DCI18n.original(title).includes('Panel de configuración')){fn();return;}
 dcAdminSubview85i=true;
 try{
  fn();
  if(fn===window.dcOpenMetrics85){
   const metrics=document.getElementById('dcMetrics85');
   const header=metrics?.querySelector('header');
   if(header){
    const back=document.createElement('button');back.type='button';back.textContent='← Volver a Configuración';
    back.onclick=()=>{metrics.remove();dcAdminSubview85i=false;};
    header.prepend(back);
   }
  }
 }catch(e){dcAdminSubview85i=false;throw e;}
}
function dcAdminBack85i(){
 document.querySelector('#dcUtilityModal .dc85i-admin-subview')?.remove();
 dcAdminSubview85i=false;
}
function dcUtility(title,html){
 document.querySelector('#dcUtilityModal .dc-utility-modal > .dc-settings-actions')?.remove();
 window.dcMusicCloseMenus81?.();
 const content=document.getElementById('dcUtilityBody');
 if(dcAdminSubview85i && title!=='⚙️ Panel de configuración'){
  let overlay=document.querySelector('#dcUtilityModal .dc85i-admin-subview');
  if(!overlay){
   overlay=document.createElement('div');overlay.className='dc85i-admin-subview';
   overlay.innerHTML='<section class="dc85i-admin-subpanel"><div class="dc85i-admin-subhead"><button type="button" onclick="dcAdminBack85i()">← Volver a Configuración</button><strong class="dc85i-admin-subtitle"></strong></div><div class="dc85i-admin-subbody"></div></section>';
   document.querySelector('#dcUtilityModal .dc-utility-modal').append(overlay);
  }
  overlay.querySelector('.dc85i-admin-subtitle').textContent=title;
  overlay.querySelector('.dc85i-admin-subbody').innerHTML=html;
  return;
 }
 dcAdminSubview85i=false;
 document.getElementById('dcUtilityTitle').textContent=title;
 document.getElementById('dcUtilityBody').innerHTML=html;
 document.getElementById('dcUtilityModal').style.display='block';
}
async function dcPost(action,values={}){const run=()=>shareRequest(action,values);const result=await (action!=='settings'&&window.DCSaveFeedback?window.DCSaveFeedback.observe(action,run):run());if(['rename','move','delete'].includes(action))window.dcUndoRefresh85l?.();return result;}
async function dcClearReceivedMark(id){try{await dcPost('clear_received_mark',{id});await loadFiles();}catch(e){alert(e.message)}}
async function dcClearReceivedMarksFolder(folderId) {
    if (!confirm('¿quitar distintivo de recepcion a todos los archivos dentro de esta carpeta?')) return;
    try {
        const result = await dcPost('clear_received_marks_folder', {folder_id:folderId});
        alert(`Distintivos retirados: ${Number(result.updated || 0)}.`);
        await loadFiles();
    } catch (e) { alert(e.message); }
}

async function openSettings(){
 document.querySelector('#dcUtilityModal .dc85i-admin-subview')?.remove(); dcAdminSubview85i=false;
 dcUtility('⚙️ Panel de configuración','<p role="status" class="dc-config-loading">Cargando preferencias…</p>');
 let v;try{const response=await fetch('api.php?action=settings',{cache:'no-store'});v=await response.json();if(!response.ok||v.error)throw Error(v.error||'Error HTTP '+response.status);}catch(error){dcUtility('⚙️ Panel de configuración','<p role="alert">'+safeShareText(error.message||'No se pudo cargar la configuración.')+'</p>');return;}
 const dcSessionDays=Number(v.session_days_dcft)||30;
 dcUtility('⚙️ Panel de configuración',`<div class="dc-settings-grid"><section class="dc-settings-group"><h3>🔎 Búsqueda <span class="dc-help" title="Permite consultas globales de archivos hasta 300 resultados. Desactivada por defecto para reducir consultas en MariaDB; se restablece al cerrar sesión.">ⓘ</span></h3><label><input type="checkbox" id="dcGlobalSearch" ${v.global_search?'checked':''}> Habilitar búsqueda global (consulta directa en MariaDB; máximo 300 resultados)</label>

 </section><section class="dc-settings-group"><h3>📥 Recepción pública <span class="dc-help" title="Límites por archivo para recepción pública. También se respeta la cuota y el máximo permitido por PHP.">ⓘ</span></h3>
 ${v.can_manage_receive_global?`<label>Máximo global (MiB): <input id="dcReceiveGlobalMiB" type="number" min="1" max="10240" step="1" value="${Number(v.receive_global_limit_mib)}"></label>`:''}
 <label>Mi máximo de recepción por archivo (MiB): <input id="dcReceivePersonalMiB" type="number" min="1" step="1" max="${Math.min(Number(v.receive_global_limit_mib),Number(v.receive_admin_limit_mib)||Infinity)}" value="${Number(v.receive_limit_mib)||Math.min(Number(v.receive_global_limit_mib),Number(v.receive_admin_limit_mib)||Infinity)}"></label>

 </section>
 ${v.can_manage_dedup ? `<section class="dc-settings-group"><h3>🔍 Duplicados <span class="dc-help" title="Compara contenidos con SHA-256. Subida privada: advierte. Recepción pública: rechaza duplicados del mismo propietario. No se recalculan MD5 antiguos.">ⓘ</span></h3>
 <label><input type="checkbox" id="dcDedupEnabled" ${v.dedup_enabled?'checked':''}> Comprobar contenido al terminar una subida</label></section>

` : ''}
 ${v.can_manage_upload_chunks ? `<section class="dc-settings-group"><h3>📤 Subidas <span class="dc-help" title="Hasta 100 MiB: fragmentos de 1 MiB; hasta 1 GiB: 10 MiB. Para archivos mayores se utiliza el valor elegido; PHP debe aceptar cada fragmento.">ⓘ</span></h3>

 <label>Máximo para archivos mayores de 1 GiB: <select id="dcUploadChunkMaxMiB"><option value="50" ${Number(v.upload_chunk_max_mib)!==100?'selected':''}>50 MiB (recomendado)</option><option value="100" ${Number(v.upload_chunk_max_mib)===100?'selected':''}>100 MiB</option></select></label></section>
` : ''}
 ${v.can_manage_tar_gz_preview ? `<section class="dc-settings-group"><h3>🗜️ Índice TAR.GZ <span class="dc-help" title="TAR.GZ exige descompresión secuencial, lo que consume CPU aunque el listado sea pequeño. Se mantiene el límite de tamaño comprimido, 8 segundos de proceso y 2 MiB de índice. Valor 0 desactiva.">ⓘ</span></h3>

 <label>Máximo por archivo (MiB) <span title="A diferencia de ZIP, TAR.GZ debe descomprimirse secuencialmente para listar sus archivos. Un archivo grande puede consumir mucha CPU, incluso con pocos resultados. Este límite protege la Raspberry Pi; el listado también tiene un límite independiente de 2 MiB y 8 segundos." style="cursor:help;border-bottom:1px dotted currentColor">ⓘ</span>: <input id="dcTarGzMaxMiB" type="number" inputmode="numeric" min="0" max="100" step="1" value="${Number(v.tar_gz_preview_max_mib ?? 5)}" style="max-width:110px"></label></section>
` : ''}
 ${v.can_manage_tar_gz_preview ? `<section class="dc-settings-group"><h3>🎞️ Videos locales <span class="dc-help" title="FFmpeg genera miniaturas y avances en segundo plano. Duración 3–30 segundos; incluir sonido puede impedir autoplay hasta interactuar con la página.">ⓘ</span></h3>
 <div id="dcLocalPreviewSettings"><label>Duración (3 a 30 segundos): <input id="dcLocalPreviewSeconds" type="number" min="3" max="30" value="10" style="max-width:90px"></label>
 <label style="margin-left:12px"><input type="checkbox" id="dcLocalPreviewAudio"> Incluir sonido</label>
 <div style="margin-top:8px"><label>Formato de preview:</label>
 <select id="dcLocalPreviewFormat" style="margin-left:8px">
 <option value="webm" selected>WebM (VP9) - mas pequeno y agil</option>
 <option value="mp4">MP4 (H.264) - maxima compatibilidad</option>
 </select>
 <small style="display:block;margin-top:4px;opacity:.7">WebM reduce ~40% el tamano. MP4 funciona en todos los navegadores incluyendo Safari antiguo.</small>
 </div></div></section>
` : ''}
 <section class="dc-settings-group"><h3>🎨 Presentación <span class="dc-help" title="Estos cambios visuales se aplican en este navegador: tamaño de iconos, color de acento y tema.">ⓘ</span></h3>
 <p><label>Tamaño de iconos: <output id="dcCurrentIcon">${getSavedIconSize()} px</output><input type="range" min="20" max="72" step="2" value="${getSavedIconSize()}" oninput="setIconSize(this.value);document.getElementById('dcCurrentIcon').textContent=this.value+' px'"></label></p>
 <p><label>Paleta de acento: <input type="color" id="dcAccentSetting" value="${localStorage.getItem('dc_color')||'#5367d9'}" oninput="setAccentColor(this.value)"></label></p>
 <p>Tema: <select id="dcThemeSetting" onchange="document.getElementById(this.value==='dark'?'themeDark':'themeLight').click()"><option value="light" ${localStorage.getItem('dc_theme')==='dark'?'':'selected'}>Claro</option><option value="dark" ${localStorage.getItem('dc_theme')==='dark'?'selected':''}>Oscuro</option></select></p>
  </section>
  <section class="dc-settings-group dc-settings-session"><h3>🕒 Sesión <span class="dc-help" title="Inactividad permitida antes de volver a pedir credenciales. Cada vez que usas Drive-Craft la cuenta atrás se reinicia.">ⓘ</span></h3>
   <label>Caducar tras: <select id="dcSessionDaysDcft">
    <option value="30" ${dcSessionDays===30?'selected':''}>30 días sin actividad</option>
    <option value="60" ${dcSessionDays===60?'selected':''}>60 días sin actividad</option>
    <option value="90" ${dcSessionDays===90?'selected':''}>90 días sin actividad</option>
    <option value="180" ${dcSessionDays===180?'selected':''}>180 días sin actividad</option>
    <option value="360" ${dcSessionDays===360?'selected':''}>360 días sin actividad</option>
   </select></label>
   <p style="opacity:.72;font-size:.85rem;margin:0">Caduca si no hay actividad antes de: <strong id="dcSessionExpiresDcft">${v.session_expires_at_dcft?new Date(Number(v.session_expires_at_dcft)*1000).toLocaleString():'—'}</strong></p>
   <button type="button" class="dc-sessions-open" onclick="window.dcOpenOwnSessionsDcft?.()">Ver y revocar mis sesiones</button>
   <small>Incluye sesiones en otras computadoras o navegadores.</small>
  </section>
  <section aria-labelledby="dcPasswordHeading" class="dc-password-settings dc-settings-group">
    <h3 id="dcPasswordHeading" style="margin:0 0 7px">🔐 Seguridad <span class="dc-help" title="Cambiar contraseña exige la actual; mínimo 12 caracteres. Las otras sesiones quedarán invalidadas.">ⓘ</span></h3>

    <form id="dcSettingsPasswordForm" autocomplete="on" onsubmit="return dcChangeOwnPassword(event)">
      <label for="dcCurrentPassword">Contraseña actual</label>
      <input id="dcCurrentPassword" name="old_password" type="password" autocomplete="current-password" required>
      <label for="dcNewPassword">Nueva contraseña (mínimo 12 caracteres)</label>
      <input id="dcNewPassword" name="new_password" type="password" autocomplete="new-password" minlength="12" required>
      <label for="dcConfirmPassword">Confirmar nueva contraseña</label>
      <input id="dcConfirmPassword" name="confirm_password" type="password" autocomplete="new-password" minlength="12" required>
      <button id="dcPasswordSaveButton" type="submit" class="btn-primary">Actualizar contraseña</button>
      <p id="dcSettingsPasswordMessage" role="status" aria-live="polite" style="min-height:1.3em;margin:8px 0 0"></p>
    </form>
  </section>
  ${window.DC_AUTH?.role === 'admin' ? `<section class="dc-settings-group" aria-labelledby="dcAdminToolsHeading">
    <h3 id="dcAdminToolsHeading">🛠️ Administración <span class="dc-help" title="Accesos administrativos de Drive-Craft. Las funciones siguen verificando los permisos del lado del servidor.">ⓘ</span></h3>
    <div class="dc85b-admin-tools">
      <button type="button" onclick="dcLaunchAdminSubview85i(dcOpenUsers)">👥 Usuarios</button>
      <button type="button" onclick="dcLaunchAdminSubview85i(dcOpenUnifiedIntegrity)">🔎 Integridad de datos</button>
      <button type="button" onclick="dcLaunchAdminSubview85i(dcOpenMetrics85)">📈 Recursos del servidor</button>
      <button type="button" onclick="dcLaunchAdminSubview85i(dcOpenGlobalHardlinks)">🔗 Hardlinks globales</button>
    </div>
  </section>` : ''}
  </div>${window.DC_AUTH?.role==='admin'?'<section class="dc-settings-group" id="dcSettingsExternalCard"><h3>Servicios externos</h3><p>Descargas de YouTube y mantenimiento semanal del adaptador con IA.</p><button type="button" onclick="DCExternal.open()">Configurar servicios externos</button></section>':''}<div class="dc-settings-actions"><span id="dcSettingsSaveStatus" role="status" aria-live="polite"></span><button id="dcSettingsSaveButton" class="btn-primary" onclick="saveDcSettings()">Guardar preferencias</button></div>`);
 const settingsModal=document.getElementById('dcUtilityModal');
 settingsModal.classList.add('dc-settings-open');
 const settingsGrid=settingsModal.querySelector('.dc-settings-grid');
 const externalCard=settingsModal.querySelector('#dcSettingsExternalCard');
 if(externalCard)settingsGrid.append(externalCard);
 const settingsColumns=Array.from({length:3},()=>{const column=document.createElement('div');column.className='dc-settings-column';return column;});
 for(const group of Array.from(settingsGrid.children)){
  const right=group.id==='dcSettingsExternalCard'||group.querySelector('#dcSettingsPasswordForm,#dcAdminToolsHeading');
  const middle=group.querySelector('#dcLocalPreviewSeconds,#dcThemeSetting,#dcSessionDaysDcft');
  settingsColumns[right?2:middle?1:0].append(group);
 }
 settingsGrid.replaceChildren(...settingsColumns);

 const footer=settingsModal.querySelector('.dc-settings-actions');
 if(footer)settingsModal.querySelector('.dc-utility-modal').append(footer);
}
async function dcChangeOwnPassword(event){
 event.preventDefault();
 const form=event.currentTarget;
 const msg=document.getElementById('dcSettingsPasswordMessage');
 const button=document.getElementById('dcPasswordSaveButton');
 const oldPassword=form.elements.namedItem('old_password').value;
 const newPassword=form.elements.namedItem('new_password').value;
 const confirmPassword=form.elements.namedItem('confirm_password').value;
 msg.style.color='var(--text-color)';
 if(newPassword.length<12){msg.textContent='La contraseña nueva debe tener al menos 12 caracteres.';return false;}
 if(newPassword!==confirmPassword){msg.textContent='La confirmación no coincide con la nueva contraseña.';return false;}
 if(oldPassword===newPassword){msg.textContent='La nueva contraseña debe ser distinta a la actual.';return false;}
 button.disabled=true;
 msg.textContent='Actualizando contraseña…';
 try {
   const fd=new FormData();
   fd.append('action','change_password');
   fd.append('old_password',oldPassword);
   fd.append('new_password',newPassword);
   const response=await fetch('admin_api.php',{method:'POST',body:fd,headers:{'X-DC-CSRF':window.DC_AUTH?.csrf||''},cache:'no-store'});
   const data=await response.json();
   if(!response.ok||!data.success)throw new Error(data.error||'No se pudo actualizar la contraseña.');
   form.reset();
   msg.style.color='#298354';
   msg.textContent='Contraseña actualizada correctamente. Las otras sesiones de tu cuenta quedaron invalidadas.';
 }catch(error){
   msg.style.color='#c43e40';
   msg.textContent=error.message||'No se pudo actualizar la contraseña.';
 }finally{button.disabled=false;}
 return false;
}
async function saveDcSettings(){
 const button=document.getElementById('dcSettingsSaveButton'),status=document.getElementById('dcSettingsSaveStatus');
 if(button?.disabled)return;
 const feedback=window.DCSaveFeedback?.begin(button);
 if(button){button.disabled=true;button.classList.add('dc-config-saving');button.textContent='Guardando…';}
 if(status)status.textContent='';
 try {
  const values={global_search:document.getElementById('dcGlobalSearch').checked?'1':'0'};
  const dedupField=document.getElementById('dcDedupEnabled');
  if(dedupField) values.dedup_enabled=dedupField.checked?'1':'0';
  const chunkField=document.getElementById('dcUploadChunkMaxMiB');
  if(chunkField) values.upload_chunk_max_mib=chunkField.value;
  const limitField=document.getElementById('dcTarGzMaxMiB');
  if(limitField){
    const limit=Number(limitField.value);
    if(!Number.isInteger(limit)||limit<0||limit>100||limitField.value.trim()==='') throw Error('Indica un límite TAR.GZ entre 0 y 100 MiB');
    values.tar_gz_preview_max_mib=String(limit);
  }
  const pvSeconds=document.getElementById('dcLocalPreviewSeconds');
  if(pvSeconds){const n=Number(pvSeconds.value);if(!Number.isInteger(n)||n<3||n>30)throw Error('La duración del preview debe estar entre 3 y 30 segundos');}
  const globalField=document.getElementById('dcReceiveGlobalMiB');
  const personalField=document.getElementById('dcReceivePersonalMiB');
  if(globalField)values.receive_global_limit_mib=globalField.value;
  if(personalField)values.receive_limit_mib=personalField.value;
  const sessionDaysField=document.getElementById('dcSessionDaysDcft');
  if(sessionDaysField) values.session_days_dcft=sessionDaysField.value;
  await dcPost('settings',values);
  if(sessionDaysField){const exp=document.getElementById('dcSessionExpiresDcft');if(exp)exp.textContent=new Date(Date.now()+Number(sessionDaysField.value)*86400000).toLocaleString();}
  if(pvSeconds)await dlRequest('settings',{seconds:String(pvSeconds.value),audio:document.getElementById('dcLocalPreviewAudio').checked?'1':'0',format:document.getElementById('dcLocalPreviewFormat').value},true);
  const filters=document.getElementById('dcSearchFilters');
  if(filters)filters.hidden=!document.getElementById('dcGlobalSearch').checked;
  if(button)button.textContent='✓ Preferencias guardadas';
  if(status)status.textContent='Los cambios se guardaron correctamente.';
  feedback?.success('Preferencias guardadas correctamente.');
  window.DCMediaTools?.setGlobalSearch?.(document.getElementById('dcGlobalSearch').checked);
 }catch(error){feedback?.error(error);if(status)status.textContent='No se pudo guardar: '+error.message;if(button)button.textContent='Reintentar guardado';}
 finally{if(button){button.disabled=false;button.classList.remove('dc-config-saving');}}
}
async function initializeSearchSettings(){try{const v=await fetch('api.php?action=settings').then(r=>r.json());const el=document.getElementById('dcSearchFilters');if(el)el.hidden=!v.global_search;}catch(e){}}
if(document.getElementById('fileGrid')) initializeSearchSettings();
// HF1: Acciones de creación no corresponden a una vista virtual de Recientes.
function dcRecentsToolbar85m(active){
  const bar=document.querySelector('.drive-header-actions .main-buttons');
  if(!bar)return;
  for(const el of bar.querySelectorAll(':scope > button')){
    if(el.id==='dcClearRecents85m')continue;
    el.hidden=!!active;
    el.style.display=active?'none':'';
  }
  const clear=document.getElementById('dcClearRecents85m');
  if(clear)clear.hidden=!active;
}
async function showSpecial(name){
  window.dcCurrentSpecial=name;
 try{const response=await fetch(`api.php?action=${name}`);const result=await response.json();if(result.error)throw Error(result.error);
    isSearching=true;renderFiles(result.files,true);
    dcRecentsToolbar85m(name==='recents');
    const bc=document.getElementById('breadcrumb');
    bc.textContent=name==='favorites'?'⭐ Favoritos':'🕘 Recientes';
    document.getElementById('dcClearRecents85m')?.remove();
    if(name==='recents'){
      const btn=document.createElement('button');btn.type='button';btn.id='dcClearRecents85m';
      btn.textContent='🧹 Limpiar Recientes';btn.className='btn-primary';btn.title='No elimina archivos del almacenamiento';
      btn.onclick=async()=>{if(!confirm('¿Vaciar Recientes? No se eliminarán archivos.'))return;
       try{await dcPost('clear_recents_85m',{});await showSpecial('recents');}catch(err){alert(err.message)}};
      document.querySelector('.drive-header-actions .main-buttons')?.prepend(btn);
    }
 }catch(e){alert(e.message);}
}
async function openTrash(){
 try {
  const response=await fetch('api.php?action=trash_list',{cache:'no-store'});
  const d=await response.json();if(!response.ok||d.error)throw Error(d.error||'No se pudo abrir papelera');
  const html=d.files.length?`<div id="dcTrashTree85h"></div><div class="dc-trash-toolbar"><button type="button" class="dc-empty-trash" onclick="emptyDcTrash()">🗑️ Vaciar papelera completamente</button><small>Elimina definitivamente todos los elementos de la papelera y sus descendientes.</small></div><div class="dc-trash-list">`+
   d.files.map(f=>`<div class="dc-trash-list-row"><div class="dc-trash-list-main"><strong>${Number(f.is_dir)?'📁':'📄'} ${safeShareText(f.name)}</strong><small title="${safeShareText(f.original_path||'Ruta no disponible')}">📍 ${safeShareText(f.original_path||'Ruta no disponible')}</small><small>Eliminado: ${safeShareText(f.deleted_at)}</small></div><div class="dc-trash-list-actions">${Number(f.is_dir)?`<button onclick="dcTrashBrowse85h(${Number(f.id)})">Explorar</button>`:""}<button onclick="trashOp('trash_restore',${Number(f.id)})">Restaurar</button><button onclick="trashOp('trash_purge',${Number(f.id)})">Eliminar</button></div></div>`).join('')+'</div>':'La papelera está vacía.';
  dcUtility('🗑️ Papelera de reciclaje',html);
 }catch(e){alert('Papelera: '+e.message)}
}
// Navegador de papelera: árbol reconstruido en el servidor bajo cada carpeta eliminada.
async function dcTrashBrowse85h(rootId){
 try{
  const r=await fetch('api.php?action=trash_tree_85h&root_id='+encodeURIComponent(rootId),{cache:'no-store'});
  const j=await r.json();if(!r.ok||j.error)throw Error(j.error||'No se pudo explorar');
  const box=document.getElementById('dcTrashTree85h');if(!box)return;
  box.replaceChildren();
  const top=document.createElement('div');top.className='dc-trash-tree-top85h';
  const back=document.createElement('button');back.type='button';back.textContent='← Papelera';back.onclick=openTrash;
  const heading=document.createElement('strong');heading.textContent=j.root.name;
  top.append(back,heading);box.append(top);
  if(j.limited){const p=document.createElement('p');p.textContent='Se muestran como máximo 1500 elementos. Para carpetas muy grandes, restaura la carpeta completa.';box.append(p);}
  const ul=document.createElement('div');ul.className='dc-trash-tree-list85h';box.append(ul);
  const nodes=new Map();nodes.set(Number(j.root.id),ul);
  const folderRoot=document.createElement('div');folderRoot.className='dc-trash-tree-root85h';folderRoot.textContent='📁 '+j.root.name;ul.append(folderRoot);
  for(const item of j.items){
   const line=document.createElement('div');line.className='dc-trash-tree-item85h';line.style.paddingLeft=Math.min(40,Number(item.depth))*14+'px';
   const name=document.createElement('span');name.textContent=(Number(item.is_dir)?'📁 ':'📄 ')+item.name;
   const restore=document.createElement('button');restore.type='button';restore.textContent='Restaurar solo esto';
   restore.onclick=async()=>{if(!confirm('¿Restaurar «'+item.name+'» dentro de Mi unidad/undeleted, manteniendo el resto en la papelera?'))return;try{const res=await dcPost('trash_restore_nested_85h',{id:item.id,root_id:rootId});if(res.error)throw Error(res.error);alert('Restaurado en '+res.destination);await dcTrashBrowse85h(rootId);await loadTree();await loadFiles();}catch(e){alert(e.message)}};
   line.append(name,restore);ul.append(line);
  }
 }
 catch(e){alert('No se pudo explorar la papelera: '+e.message)}
}
async function emptyDcTrash(){
 if(!confirm('¿Vaciar COMPLETAMENTE la papelera? Esta acción eliminará definitivamente archivos, carpetas y versiones. No se puede deshacer.'))return;
 const typed=prompt('Para confirmar, escribe VACIAR:');
 if(typed!=='VACIAR')return;
 try {
   const result=await dcPost('trash_empty',{confirm:'EMPTY_TRASH'});
   if(result.error)throw Error(result.error);
   await openTrash();await loadTree();await loadFiles();
 }catch(error){alert('No se pudo vaciar la papelera: '+error.message);}
}
async function trashOp(action,id){if(action==='trash_purge'&&!confirm('Eliminar definitivamente? No se puede deshacer.'))return;await dcPost(action,{id});await openTrash();loadTree();loadFiles();}
async function toggleFavorite(id){await dcPost('toggle_favorite',{id});if(isSearching&&['favorites','recents'].includes(window.dcCurrentSpecial))await showSpecial(window.dcCurrentSpecial);else await loadFiles();await loadTree();}
async function toggleVersions(id, enabled) {
    try {
        const result = await dcPost('set_versions', { id, enabled: String(enabled) });
        if (result.success !== true) {
            throw new Error(result.error || 'El servidor no confirmó el cambio');
        }
        await loadFiles();
        await loadTree();
    } catch (error) {
        alert('Error al configurar historial: ' + error.message);
    }
}
async function showProperties(id){try{
 const d=await fetch(`api.php?action=properties&id=${id}`).then(r=>r.json());if(d.error)throw Error(d.error);
 const f=d.file;dcUtility('ℹ️ Propiedades',Object.entries(f).map(([k,v])=>{if(k==='size'&&!Number(f.is_dir)&&Number.isFinite(Number(v))){const n=Number(v);const units=['B','KiB','MiB','GiB','TiB'];let value=n,i=0;while(value>=1024&&i<units.length-1){value/=1024;i++;}v=(i===0?String(n):value.toLocaleString('es-MX',{maximumFractionDigits:2})+' '+units[i])+(i===0?' B':' ('+n.toLocaleString('es-MX')+' bytes)');}else if(k==='size'&&Number(f.is_dir))v='— (carpeta)';return `<div class="dc-utility-row"><strong>${safeShareText(k)}</strong><span style="overflow-wrap:anywhere">${safeShareText(v??'—')}</span></div>`;}).join(''));
}catch(e){alert(e.message)}}
async function showVersions(id){
 const d=await fetch(`api.php?action=file_versions&id=${id}`).then(r=>r.json());
 dcUtility('🕘 Versiones anteriores',d.versions.length?d.versions.map(v=>`<div class="dc-utility-row"><span>${safeShareText(v.created_at)} · ${formatBytes(v.size)}</span><button onclick="restoreDcVersion(${v.id},${id})">Restaurar</button></div>`).join(''):'No existen versiones anteriores para este archivo. Habilita esta función en el menú contextual de la carpeta.');
}
async function restoreDcVersion(version,id){if(!confirm('Restaurar esta versión? Se conservará también la versión actual.'))return;await dcPost('restore_version',{version_id:version});await showVersions(id);loadFiles();}
async function createReceive(folderId){
 const album=!!allFoldersData.find(f=>Number(f.id)===Number(folderId)&&Number(f.is_album)===1);
 dcUtility(album?'📷 Enlace para recibir fotografías':'📥 Enlace para recibir archivos',`<p>${album?'Este álbum solo acepta imágenes (JPG, PNG, GIF, WebP, AVIF y BMP).':'Solo permite subir archivos a esta carpeta.'} No permite consultar su contenido. Se aplicará el límite de recepción configurado.</p>
 <div class="share-duration-fields"><label>Días<input id="recvDays" type="number" min="0" max="3650" value="1"></label><label>Horas<input id="recvHours" type="number" min="0" max="23" value="0"></label><label>Minutos<input id="recvMinutes" type="number" min="0" max="59" value="0"></label></div>
 <p>Contraseña (opcional): <input id="recvPass" type="password"></p><button class="btn-primary" onclick="saveReceive(${folderId})">Crear enlace</button><p id="recvResult"></p>`);
}
async function dcCopyReceiveUrl(button) {
 const input=document.getElementById('dcReceiveGeneratedUrl');
 if(!input) return;
 const url=input.value;
 try {
  if(!navigator.clipboard || !window.isSecureContext) throw Error('Clipboard API no disponible');
  await navigator.clipboard.writeText(url);
  dc41Feedback(button.nextElementSibling,'Copiado');
 } catch(error) {
  input.focus();input.select();input.setSelectionRange(0,url.length);
  // Compatibilidad con navegadores móviles que requieren copiar manualmente.
  let ok=false;
  try {ok=document.execCommand('copy');} catch(e) {}
  dc41Feedback(button.nextElementSibling,ok?'Copiado':'Selecciona el enlace');
 }
}
async function saveReceive(folderId){
 const result=document.getElementById('recvResult');
 try {
  const fd=new FormData();shareDuration(fd,'recv');
  const data=Object.fromEntries(fd.entries());
  data.folder_id=folderId;data.password=document.getElementById('recvPass').value;
  const r=await dcPost('create_receive',data);
  if(!r.link) throw Error('El servidor no devolvió el enlace');
  const url=new URL(r.link,new URL('./',location.href));
  if(url.protocol==='http:' && location.protocol==='https:')url.protocol='https:';
  result.replaceChildren();
  const label=document.createElement('label');label.textContent='Enlace creado correctamente:';
  label.htmlFor='dcReceiveGeneratedUrl';
  const row=document.createElement('div');row.className='dc-receive-link-row';
  const input=document.createElement('input');
  input.id='dcReceiveGeneratedUrl';input.type='text';input.readOnly=true;input.value=url.href;
  input.setAttribute('aria-label','Enlace para recibir archivos');
  input.addEventListener('click',()=>input.select());
  const button=document.createElement('button');button.type='button';
  button.className='btn-primary';button.textContent='📋 Copiar enlace';
  button.addEventListener('click',()=>dcCopyReceiveUrl(button));
  const feedback=document.createElement('span'); feedback.className='dc-copy-feedback'; feedback.setAttribute('role','status'); feedback.setAttribute('aria-live','polite');
  row.append(input,button,feedback);
  const open=document.createElement('a');open.href=url.href;open.target='_blank';open.rel='noopener noreferrer';
  open.textContent='Abrir enlace para comprobarlo';
  result.append(label,row,open);
 } catch(error) {
  result.textContent='Error al crear enlace: '+error.message;
 }
}
async function refreshReceiveLinks(){
 const el=document.getElementById('receiveLinksList');if(!el)return;
 try{const d=await fetch('api.php?action=list_receive').then(r=>r.json());el.innerHTML=d.links.map(x=>{
 const url=new URL(`api.php?receive_hash=${x.hash}`,new URL('./',location.href));if(url.protocol==='http:')url.protocol='https:';
 return `<div class="dc-utility-row"><span>📥 ${safeShareText(x.name)} · ${safeShareText(x.expires_at||'Sin caducidad')}<br><a target="_blank" href="${safeShareText(url.href)}">${safeShareText(url.href)}</a></span><button onclick="revokeReceive(${x.id})">Revocar</button></div>`;
 }).join('')||'No hay enlaces de recepción.';}catch(e){el.textContent=e.message;}
}
async function revokeReceive(id){if(!confirm('Revocar este enlace de recepción?'))return;await dcPost('revoke_receive',{id});await refreshReceiveLinks();}
// Atajos: no interferir con campos de texto, diálogos, ni accesibilidad del navegador.
document.addEventListener('keydown', e => {
 if (!document.getElementById('fileGrid')) return;
 const active = document.activeElement;
 if (active && (['INPUT','TEXTAREA','SELECT'].includes(active.tagName) || active.isContentEditable)) return;
 if(dcIsTyping85l(active)||dcHasOpenDialog85l()||e.target?.closest?.('input,textarea,select,[contenteditable],[role="textbox"],.monaco-editor,.CodeMirror,.cm-editor'))return;
 // Mientras un editor o visor esté abierto, no navegar por el explorador.
 if([...document.querySelectorAll('[id*="editor" i],[class*="editor" i],.dc-music-overlay81,.dc-utility-modal')].some(el=>{
   const cs=getComputedStyle(el);return !el.hidden && cs.display!=='none' && cs.visibility!=='hidden' && el.getBoundingClientRect().width>0 && (el.matches('.dc-music-overlay81,.dc-utility-modal') || el.querySelector('textarea,[contenteditable],.CodeMirror,.cm-editor,input:not([type="hidden"])'));
 }))return;
 if(!e.ctrlKey&&!e.metaKey&&!e.shiftKey&&e.key==='Backspace'){e.preventDefault();dcNavigateHistory85l(-1);return;}
 if(e.altKey&&!e.ctrlKey&&!e.metaKey){if(e.key==='ArrowLeft'){e.preventDefault();dcNavigateHistory85l(-1);return;}if(e.key==='ArrowRight'){e.preventDefault();dcNavigateHistory85l(1);return;}if(e.key==='ArrowUp'){e.preventDefault();dcGoParent85l();return;}}
 // En cualquier visor o diálogo, los atajos del explorador no deben
 // interceptar Ctrl+C, Ctrl+X, Ctrl+V ni la búsqueda del navegador.
 const visibleModal = [...document.querySelectorAll('.modal')].some(el =>
   getComputedStyle(el).display !== 'none' && getComputedStyle(el).visibility !== 'hidden'
 );
 if (visibleModal) {
   if (e.key === 'Escape') {
     const viewer = document.getElementById('viewerModal');
     if (viewer && getComputedStyle(viewer).display !== 'none') closeModal('viewerModal');
   }
   return;
 }
 // Incluso fuera del visor, respetar la selección de texto hecha con el ratón.
 const selectedText = window.getSelection()?.toString() || '';
 if (selectedText.length > 0 && (e.ctrlKey || e.metaKey) && ['c','x'].includes(e.key.toLowerCase())) return;
 if (e.key === 'Escape') { clearSelection(); return; }
 if (e.key === 'Delete' && selectedFiles.size) { e.preventDefault(); actionBatch('delete'); return; }
 if (e.key === 'F2' && selectedFiles.size === 1) { e.preventDefault(); renameFile([...selectedFiles][0]); return; }
 if ((e.ctrlKey || e.metaKey) && ['c','x'].includes(e.key.toLowerCase()) && selectedFiles.size) {
   e.preventDefault(); actionBatch(e.key.toLowerCase() === 'c' ? 'copy' : 'move'); return;
 }
 if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'v' && clipBoard.ids.length) {
   e.preventDefault(); pasteItem(); return;
 }
 if (e.key === '/' && !e.ctrlKey && !e.metaKey) {e.preventDefault();document.getElementById('searchInput')?.focus();}
});

async function renameFile(id){const name=prompt('Nuevo nombre:');if(!name)return;try{await dcPost('rename',{id,name});loadFiles();loadTree();}catch(e){alert(e.message);}}

// Renombrado: el servidor valida propiedad, duplicados y nombre seguro.
async function dcRenameFile(id, oldName) {
    const name=prompt('Nuevo nombre:',oldName);
    if (name===null || name.trim()===oldName) return;
    try {
        await dcPost('rename',{id,name:name.trim()});
        await loadTree();
        await loadFiles();
    } catch(e) { alert('No se pudo renombrar: '+e.message); }
}

// Informe administrativo de integridad: lectura exclusivamente; nunca repara automáticamente.
async function dcOpenIntegrityReport() {
  dcUtility('🔎 Integridad de datos', '<p>Analizando referencias y archivos físicos (solo lectura)...</p>');
  try {
    const response = await fetch('dc_integrity.php', {credentials:'same-origin',cache:'no-store'});
    const data = await response.json();
    if (!response.ok || !data.success) throw new Error(data.error || 'Error al comprobar el almacenamiento');
    const counts=Object.entries(data.issue_counts||{});
    document.getElementById('dcUtilityBody').innerHTML =
      `<p><strong>Archivos:</strong> ${Number(data.checked_files)} · <strong>Versiones:</strong> ${Number(data.checked_versions)}</p>` +
      `<p>${data.truncated ? '⚠️ Resultado parcial: límite de análisis alcanzado.' : 'Análisis finalizado.'} No se ha modificado ningún archivo.</p>`+
      (counts.length ? counts.map(([type,count])=>`<div class="dc-utility-row"><strong>${safeShareText(type)}</strong><span>${Number(count)}</span></div>`).join('') : '<p>Sin inconsistencias detectadas en el alcance comprobado.</p>') +
      (data.examples?.length ? '<h4>Hallazgos del análisis actual (no resueltos)</h4><p>Los archivos físicos sin referencia detectada pueden pertenecer a módulos auxiliares. Analiza su origen antes de eliminarlos; este informe no modifica datos.</p>'+data.examples.map(x=>`<div class="dc-utility-row"><span>${safeShareText(x.type)}</span><small>${safeShareText(JSON.stringify(x))}</small></div>`).join('') : '');
  } catch(e) { document.getElementById('dcUtilityBody').textContent='No se pudo completar la revisión: '+e.message; }
}

// Drive-Craft v20.8.7 — Álbum artístico: collage + libro clásico + descripciones.
let dcAlbumArtMode = 'explorer';
let dcAlbumArtPhotos = [];
let dcAlbumNotes = {};
let dcAlbumArtFolder = 0;
let dcAlbumBookPage = 0;
let dcAlbumBookBusy = false;
const dcAlbumEsc = s => escapeHtml(String(s??''));
function dcAlbumArtStyle() {
 if (document.getElementById('dcAlbumArtCSS')) return;
 const style=document.createElement('style');style.id='dcAlbumArtCSS';style.textContent=`
 .dc-art-toolbar{display:flex;align-items:center;flex-wrap:wrap;gap:9px;padding:12px 14px;margin:12px 0;border:1px solid var(--border-color,#64748b55);border-radius:16px;background:linear-gradient(120deg,#213047 0%,#182438 55%,#3b3140 100%);color:#f8fafc;box-shadow:0 9px 28px #0002}
 .dc-art-toolbar strong{margin-right:auto;letter-spacing:.02em}.dc-art-toolbar button{border:1px solid #ffffff50;background:#ffffff17;color:#fff;border-radius:12px;padding:9px 12px;cursor:pointer}.dc-art-toolbar button[aria-pressed="true"]{background:#eee7d6;color:#1c2938;font-weight:700}.dc-art-toolbar small{opacity:.85}
 .dc-art-collage{column-count:4;column-gap:12px;margin:12px 0 36px}.dc-art-tile{break-inside:avoid;position:relative;display:block;margin:0 0 12px;overflow:hidden;border-radius:13px;box-shadow:0 5px 18px #0004;background:#1c2636;cursor:pointer}.dc-art-tile img{display:block;width:100%;height:auto;max-height:600px;object-fit:cover;transition:transform .45s,filter .4s}.dc-art-tile:hover img,.dc-art-tile:focus-visible img{transform:scale(1.035);filter:brightness(.74)}.dc-art-tile figcaption{padding:10px 12px;color:#f9fafb;background:linear-gradient(0deg,#0b1320eb,#0b1320b2);font-size:.85rem}.dc-art-tile figcaption strong{display:block;overflow-wrap:anywhere}.dc-art-tile .dc-art-desc{display:block;white-space:pre-wrap;margin-top:5px;line-height:1.35}.dc-art-edit{position:absolute;top:8px;right:8px;z-index:3;background:#101a2fcc;color:white;border:1px solid #ffffff80;border-radius:9px;padding:7px 9px;cursor:pointer}
 .dc-art-book-shell{position:fixed;inset:0;z-index:20000;background:#0c111eea;display:flex;flex-direction:column;align-items:center;justify-content:center;padding:15px;gap:12px;color:#f6ebd5}.dc-art-book-top,.dc-art-book-footer{display:flex;align-items:center;gap:12px;flex-wrap:wrap;justify-content:center}.dc-art-book-top button,.dc-art-book-footer button,.dc-art-caption-action{border:1px solid #cfbea2;background:#efe4d1;color:#332b21;border-radius:9px;padding:9px 14px;cursor:pointer}
 .dc-art-book{position:relative;display:grid;grid-template-columns:1fr 1fr;width:min(1160px,96vw);height:min(690px,76vh);perspective:1800px;box-shadow:0 18px 50px #0009;border-radius:8px;background:#e6d7bf;overflow:hidden}.dc-art-book:before{content:'';position:absolute;left:50%;top:0;bottom:0;width:18px;transform:translateX(-50%);background:linear-gradient(90deg,#56412c88,#fffbef88,#56412c66);z-index:4;pointer-events:none}
 .dc-art-page{min-width:0;overflow:auto;display:flex;flex-direction:column;align-items:center;justify-content:center;padding:20px 20px 15px;background:repeating-linear-gradient(0deg,#f5ecdc,#f5ecdc 8px,#f3e9d9 9px);color:#463829;text-align:center}.dc-art-page:nth-child(2){border-left:1px solid #c8b89f}.dc-art-page img{max-width:100%;max-height:calc(min(690px,76vh) - 135px);width:auto;height:auto;object-fit:contain;box-shadow:0 4px 20px #0003;border:7px solid #fffdf7}.dc-art-page p{margin:8px 0 4px;white-space:pre-wrap;overflow-wrap:anywhere;font-family:Georgia,serif;font-size:.93rem}.dc-art-page .dc-art-page-title{font-weight:700;font-size:.88rem}.dc-art-page button{font-size:.8rem;padding:6px 9px;margin-top:5px}.dc-art-page-empty{font-family:Georgia,serif;font-size:1.35rem;opacity:.5}
 /* Hoja 3D real: frente y reverso independientes, sin fotografia espejada. */
 .dc-art-turn-sheet{position:absolute;top:0;bottom:0;width:50%;z-index:7;transform-style:preserve-3d;pointer-events:none;will-change:transform}
 .dc-art-turn-sheet.forward{left:50%;transform-origin:left center;animation:dcTurnForward .92s linear both}
 .dc-art-turn-sheet.backward{left:0;transform-origin:right center;animation:dcTurnBackward .92s linear both}
 .dc-art-turn-face{position:absolute;inset:0;display:flex;overflow:hidden;backface-visibility:hidden;-webkit-backface-visibility:hidden;background:#f5ecdc}
 .dc-art-turn-face .dc-art-page{width:100%;height:100%;box-sizing:border-box;overflow:hidden}
 .dc-art-turn-face.back{transform:rotateY(180deg)}
 @keyframes dcTurnForward{
  0%{transform:rotateY(0deg);animation-timing-function:cubic-bezier(.55,.02,.72,.42)}
  34%{transform:rotateY(-30deg);animation-timing-function:cubic-bezier(.34,.18,.26,1)}
  100%{transform:rotateY(-180deg)}
 }
 @keyframes dcTurnBackward{
  0%{transform:rotateY(0deg);animation-timing-function:cubic-bezier(.55,.02,.72,.42)}
  34%{transform:rotateY(30deg);animation-timing-function:cubic-bezier(.34,.18,.26,1)}
  100%{transform:rotateY(180deg)}
 }
 @media(prefers-reduced-motion:reduce){.dc-art-turn-sheet.forward,.dc-art-turn-sheet.backward{animation-duration:.01s}}
 /* v20.9.1: encuadernacion artesanal, hojas perforadas y curvatura optica.
    Se mantiene una sola superficie por cara para evitar romper la fotografia. */
 .dc-art-book{
  overflow:visible;
  border:9px solid #6b4635;
  border-bottom-width:13px;
  border-radius:13px 13px 19px 19px;
  box-shadow:0 4px 0 #f3ead9,0 7px 0 #bea990,0 11px 0 #e4d8c6,0 15px 0 #ad997d,0 21px 30px #0008,0 30px 65px #0008;
  background:linear-gradient(90deg,#e1d1b7,#fff6e5 48%,#f7ecda 52%,#dfccb0)
 }
 .dc-art-book:before{z-index:11;width:28px;background:linear-gradient(90deg,#4833226b,#bda47f44 23%,#faf1e0bd 49%,#a68d674d 72%,#48332266);box-shadow:0 0 19px #4f392d77;pointer-events:none}
 .dc-art-book>.dc-art-page{border-radius:3px;box-shadow:inset 0 0 34px #7b57321d}
 .dc-art-book>.dc-art-page:first-child{padding-right:36px}
 .dc-art-book>.dc-art-page:nth-child(2){padding-left:36px}
 .dc-art-spiral{position:absolute;left:50%;top:1.3%;bottom:1.3%;width:54px;transform:translateX(-50%);z-index:13;pointer-events:none;display:flex;flex-direction:column;justify-content:space-around;align-items:center}
 .dc-art-ring{display:block;position:relative;width:49px;height:18px;border:4px solid #a9aeb0;border-top-color:#f8fbfa;border-right-color:#5c6265;border-bottom-color:#737a7b;border-radius:50%;transform:rotate(-12deg);box-shadow:0 2px 2px #0006,inset 1px 2px 2px #fff8;background:radial-gradient(ellipse,#a48b64a1 0 28%,#302117c4 30% 35%,transparent 39%) center / 48px 9px no-repeat}
 .dc-art-ring:before,.dc-art-ring:after{content:'';position:absolute;top:5px;width:8px;height:8px;border-radius:50%;background:radial-gradient(circle at 55% 50%,#342518 0 45%,#dbc8a7 47% 80%);box-shadow:inset 0 1px 2px #0009}
 .dc-art-ring:before{left:3px}.dc-art-ring:after{right:3px}
 /* v20.9.3: la hoja conserva el lomo recto y concentra la curvatura
    en el borde inferior, como una página real al pasar. El contenido
    se precarga y la cara frontal pierde protagonismo desde ~80% del giro
    para que el relevo entre fotos se perciba más natural. */
 .dc-art-turn-sheet{filter:drop-shadow(0 11px 14px #21160f72)}
 .dc-art-turn-face{overflow:hidden;box-shadow:inset 0 0 27px #63472b44;will-change:clip-path,transform}
 .dc-art-turn-face.front{animation:dcPaperBendForward .92s ease-in-out both}
 .dc-art-turn-sheet.backward .dc-art-turn-face.front{animation-name:dcPaperBendBackward}
 .dc-art-turn-face.back{animation:dcPaperBendBackForward .92s ease-in-out both}
 .dc-art-turn-sheet.backward .dc-art-turn-face.back{animation-name:dcPaperBendBackBackward}
 .dc-art-turn-face:before{content:'';position:absolute;left:4%;right:4%;bottom:-1.4%;height:22%;pointer-events:none;opacity:0;filter:blur(6px);background:radial-gradient(ellipse at center bottom,#2a1a1160 0%,#2a1a112e 38%,#2a1a1100 74%);animation:dcPageBottomShadow .92s ease-in-out both}
 .dc-art-turn-face:after{content:'';position:absolute;inset:0;pointer-events:none;opacity:0;background:linear-gradient(90deg,#140b0400 0%,#170e0630 24%,#fffdf4b8 47%,#fffefa10 61%,#180c0969 100%);animation:dcPageBowLight .92s ease-in-out both}
 .dc-art-turn-sheet.backward .dc-art-turn-face:after{background:linear-gradient(270deg,#140b0400 0%,#170e0630 24%,#fffdf4b8 47%,#fffefa10 61%,#180c0969 100%)}
 .dc-art-turn-face.front .dc-art-page{animation:dcPageInkFront .92s ease-out both}
 .dc-art-turn-face.back .dc-art-page{animation:dcPageInkBack .92s ease-in-out both}
 /* Borde superior casi recto; el arqueo ocurre sobre todo abajo. */
 @keyframes dcPaperBendForward{
  0%,100%{clip-path:polygon(0 0,100% 0,100% 100%,0 100%);transform:translateY(0) skewY(0deg) scaleX(1)}
  18%{clip-path:polygon(0 0,100% 0,100% 94%,96% 98%,86% 100%,0 100%);transform:translateY(0) skewY(-.2deg) scaleX(.998)}
  42%{clip-path:polygon(0 0,100% 0,100% 84%,96% 90%,85% 97%,63% 100%,0 100%);transform:translateY(-.1%) skewY(-.45deg) scaleX(.989)}
  62%{clip-path:polygon(0 0,100% 0,100% 73%,95% 82%,83% 91%,58% 98%,0 100%);transform:translateY(-.3%) skewY(-.75deg) scaleX(.975)}
  80%{clip-path:polygon(0 0,100% 0,100% 83%,97% 89%,88% 96%,69% 99%,0 100%);transform:translateY(-.12%) skewY(-.3deg) scaleX(.992)}
 }
 @keyframes dcPaperBendBackward{
  0%,100%{clip-path:polygon(0 0,100% 0,100% 100%,0 100%);transform:translateY(0) skewY(0deg) scaleX(1)}
  18%{clip-path:polygon(0 0,100% 0,100% 100%,14% 100%,4% 98%,0 94%);transform:translateY(0) skewY(.2deg) scaleX(.998)}
  42%{clip-path:polygon(0 0,100% 0,100% 100%,37% 100%,15% 97%,4% 90%,0 84%);transform:translateY(-.1%) skewY(.45deg) scaleX(.989)}
  62%{clip-path:polygon(0 0,100% 0,100% 100%,42% 98%,17% 91%,5% 82%,0 73%);transform:translateY(-.3%) skewY(.75deg) scaleX(.975)}
  80%{clip-path:polygon(0 0,100% 0,100% 100%,31% 99%,12% 96%,3% 89%,0 83%);transform:translateY(-.12%) skewY(.3deg) scaleX(.992)}
 }
 @keyframes dcPaperBendBackForward{
  0%,100%{clip-path:polygon(0 0,100% 0,100% 100%,0 100%)}
  18%{clip-path:polygon(0 0,100% 0,100% 100%,14% 100%,4% 98%,0 94%)}
  42%{clip-path:polygon(0 0,100% 0,100% 100%,37% 100%,15% 97%,4% 90%,0 84%)}
  62%{clip-path:polygon(0 0,100% 0,100% 100%,42% 98%,17% 91%,5% 82%,0 73%)}
  80%{clip-path:polygon(0 0,100% 0,100% 100%,31% 99%,12% 96%,3% 89%,0 83%)}
 }
 @keyframes dcPaperBendBackBackward{
  0%,100%{clip-path:polygon(0 0,100% 0,100% 100%,0 100%)}
  18%{clip-path:polygon(0 0,100% 0,100% 94%,96% 98%,86% 100%,0 100%)}
  42%{clip-path:polygon(0 0,100% 0,100% 84%,96% 90%,85% 97%,63% 100%,0 100%)}
  62%{clip-path:polygon(0 0,100% 0,100% 73%,95% 82%,83% 91%,58% 98%,0 100%)}
  80%{clip-path:polygon(0 0,100% 0,100% 83%,97% 89%,88% 96%,69% 99%,0 100%)}
 }
 @keyframes dcPageBowLight{0%,100%{opacity:.06}20%{opacity:.18}48%{opacity:.56}70%{opacity:.82}86%{opacity:.22}}
 @keyframes dcPageBottomShadow{0%,100%{opacity:.08;transform:scaleX(1) translateY(0)}36%{opacity:.32;transform:scaleX(.96) translateY(1px)}62%{opacity:.55;transform:scaleX(.92) translateY(3px)}80%{opacity:.23;transform:scaleX(.97) translateY(1px)}}
 /* v20.9.7: fundido de salida desde ~10 grados y entrada veloz
    desde ~70 grados de giro (porcentaje estimado sobre la animacion).
    La cara posterior se vuelve visible geometricamente al cruzar 90 grados. */
 .dc-art-turn-face.front .dc-art-page{animation:dcPageInkFront .92s linear both}
 .dc-art-turn-face.back .dc-art-page{animation:dcPageInkBack .92s linear both}
 @keyframes dcPageInkFront{
  0%,15%{opacity:1}
  28%{opacity:.87}
  43%{opacity:.51}
  55%{opacity:.19}
  66%,100%{opacity:0}
 }
 @keyframes dcPageInkBack{
  0%,49%{opacity:0}
  53%{opacity:.25}
  59%{opacity:.75}
  67%,100%{opacity:1}
 }
 @media(prefers-reduced-motion:reduce){.dc-art-turn-face,.dc-art-turn-face:before,.dc-art-turn-face:after,.dc-art-turn-face .dc-art-page{animation-duration:.01s!important}}
 @media(max-width:700px){.dc-art-book{border-width:5px 5px 9px}.dc-art-book>.dc-art-page:first-child{padding-right:17px}.dc-art-book>.dc-art-page:nth-child(2){padding-left:17px}.dc-art-spiral{width:31px}.dc-art-ring{width:29px;height:12px;border-width:3px}.dc-art-ring:before,.dc-art-ring:after{width:5px;height:5px;top:3px}}
 .dc-art-edit-form{display:flex;flex-direction:column;gap:12px;max-width:660px;width:calc(100vw - 32px);padding:24px;background:#1e293b;border-radius:16px;border:1px solid #94a3b866;box-shadow:0 24px 60px #0009;color:#fff}.dc-art-edit-form textarea{width:100%;box-sizing:border-box;min-height:130px;padding:12px;border-radius:8px;color:#0f172a;background:white}.dc-art-edit-form .dc-art-form-buttons{display:flex;justify-content:flex-end;gap:8px}.dc-art-edit-backdrop{position:fixed;inset:0;z-index:21000;background:#000a;display:flex;justify-content:center;align-items:center;padding:10px}
 @media(max-width:1050px){.dc-art-collage{column-count:3}}@media(max-width:700px){.dc-art-collage{column-count:2;column-gap:8px}.dc-art-tile{margin-bottom:8px}.dc-art-book{height:72vh}.dc-art-page{padding:10px 6px}.dc-art-page img{border-width:3px;max-height:calc(72vh - 130px)}.dc-art-page p{font-size:.75rem}.dc-art-toolbar strong{width:100%}}
 @media(max-width:420px){.dc-art-collage{column-count:1}}
 `;document.head.appendChild(style);
}
async function dcAlbumArtNotes(folder) {
 try {const r=await fetch(`dc_album_notes.php?action=list&folder=${encodeURIComponent(folder)}`,{credentials:'same-origin',cache:'no-store'});const j=await r.json();if(!r.ok||j.error)throw Error(j.error||'Error');if(folder!==currentParentId)return;dcAlbumNotes=Object.fromEntries((j.notes||[]).map(n=>[Number(n.file_id),n.description]));dcAlbumArtPaint();if(document.getElementById('dcAlbumBook'))dcAlbumArtBookRender();}
 catch(e){console.warn('Descripciones de álbum:',e.message);}
}
function dcAlbumArtSync(files,isSearch) {
 dcAlbumArtStyle();
 document.getElementById('dcAlbumArtBar')?.remove();document.getElementById('dcAlbumArtCollage')?.remove();
 const grid=document.getElementById('fileGrid');if(!grid)return;
 grid.style.display='';
 if(!dcAlbumMode||isSearch){dcAlbumArtFolder=0;return;}
 const cachedOnly=dcAlbumMode&&!isSearch;
 dcAlbumArtPhotos=files.filter(f=>dcIsPhoto(f)&&(!cachedOnly||f.thumb_cached===true));const folder=currentParentId;
 const bar=document.createElement('div');bar.id='dcAlbumArtBar';bar.className='dc-art-toolbar';
 bar.innerHTML=`<strong>✦ Tu álbum fotográfico</strong><small>${dcAlbumArtPhotos.length} fotografía${dcAlbumArtPhotos.length===1?'':'s'}${cachedOnly?' · solo miniaturas en caché':''}</small>
 <button type="button" data-mode="explorer" aria-pressed="${dcAlbumArtMode==='explorer'}">▦ Explorador</button><button type="button" data-mode="collage" aria-pressed="${dcAlbumArtMode==='collage'}">✺ Collage</button><button type="button" data-mode="book" aria-pressed="${dcAlbumArtMode==='book'}">📖 Álbum clásico</button>
 `;
 grid.before(bar);bar.querySelectorAll('[data-mode]').forEach(b=>b.onclick=()=>dcAlbumArtChoose(b.dataset.mode));
 if(dcAlbumArtFolder!==folder){const saved=window.DCAlbumViews.get(folder);dcAlbumArtMode=saved?.mode||'explorer';dcAlbumBookPage=saved?.bookPage||0;bar.querySelectorAll('[data-mode]').forEach(b=>b.setAttribute('aria-pressed',String(b.dataset.mode===dcAlbumArtMode)));dcAlbumArtFolder=folder;dcAlbumNotes={};dcAlbumArtNotes(folder);}
 dcAlbumArtPaint();
}
function dcAlbumSetPageSize(v){
  dcAlbumPageSize = Number(v) || 50;
  localStorage.setItem('dc_album_page_size', String(dcAlbumPageSize));
  dcAlbumPage = 1;
  loadFiles({resetScroll:true});
}
function dcAlbumPrevPage(){ if(dcAlbumPage>1){dcAlbumPage--;loadFiles({resetScroll:true});} }
function dcAlbumNextPage(){ const total=dcVisiblePhotos.length; const tp=Math.max(1,Math.ceil(total/dcAlbumPageSize)); if(dcAlbumPage<tp){dcAlbumPage++;loadFiles({resetScroll:true});} }
function dcAlbumUpdatePageInfo(){
  const el=document.getElementById('dcAlbumPageInfo'); if(!el) return;
  const total=dcVisiblePhotos.length; const tp=Math.max(1,Math.ceil(total/dcAlbumPageSize));
  el.textContent = 'Página '+dcAlbumPage+' de '+tp+' ('+total+' fotos)';
}
// Call update after render
const _origRenderFiles = renderFiles;
// We'll update page info in dcAlbumArtSync instead
function dcAlbumRenderPaginationFooter() {
  let footer = document.getElementById('dcAlbumPaginationFooter');
  if (!footer) {
    footer = document.createElement('div');
    footer.id = 'dcAlbumPaginationFooter';
    footer.style.cssText = 'display:flex;align-items:center;justify-content:center;gap:8px;padding:12px;margin-top:8px;flex-wrap:wrap;';
    const grid = document.getElementById('fileGrid');
    if (grid && grid.parentNode) grid.parentNode.insertBefore(footer, grid.nextSibling);
  }
  const total = dcVisiblePhotos.length;
  const tp = Math.max(1, Math.ceil(total / dcAlbumPageSize));
  footer.innerHTML = `
    <button type="button" onclick="dcAlbumPrevPage()" ${dcAlbumPage<=1?'disabled':''} style="padding:6px 12px;border-radius:6px;border:1px solid var(--border-color);background:var(--card-bg);color:var(--text-color);cursor:pointer;${dcAlbumPage<=1?'opacity:.4;cursor:default;':''}">◀ Anterior</button>
    <span style="font-size:13px;opacity:.8">Página ${dcAlbumPage} de ${tp} (${total} fotos)</span>
    <button type="button" onclick="dcAlbumNextPage()" ${dcAlbumPage>=tp?'disabled':''} style="padding:6px 12px;border-radius:6px;border:1px solid var(--border-color);background:var(--card-bg);color:var(--text-color);cursor:pointer;${dcAlbumPage>=tp?'opacity:.4;cursor:default;':''}">Siguiente ▶</button>
    <select onchange="dcAlbumSetPageSize(this.value)" style="padding:4px 8px;border-radius:6px;border:1px solid var(--border-color);background:var(--card-bg);color:var(--text-color)">
      <option value="50" ${dcAlbumPageSize===50?'selected':''}>50 por página</option>
      <option value="75" ${dcAlbumPageSize===75?'selected':''}>75 por página</option>
      <option value="100" ${dcAlbumPageSize===100?'selected':''}>100 por página</option>
    </select>
  `;
}
function dcAlbumArtChoose(mode){dcAlbumArtMode=mode;localStorage.setItem('dc_album_art_mode',mode);document.querySelectorAll('#dcAlbumArtBar [data-mode]').forEach(b=>b.setAttribute('aria-pressed',String(b.dataset.mode===mode)));dcAlbumArtPaint();if(mode==='book')dcAlbumArtOpenBook();dcCaptureFolderViewDcft();}
function dcAlbumArtPaint(){
 const grid=document.getElementById('fileGrid'),bar=document.getElementById('dcAlbumArtBar');if(!grid||!bar)return;
 grid.style.display=dcAlbumArtMode==='collage'?'none':'';
 document.getElementById('dcAlbumArtCollage')?.remove();
 if(dcAlbumArtMode!=='collage')return;
 const gallery=document.createElement('section');gallery.id='dcAlbumArtCollage';gallery.className='dc-art-collage';gallery.setAttribute('aria-label','Collage de fotografías');
 for(const f of dcAlbumArtPhotos){const figure=document.createElement('figure');figure.className='dc-art-tile skel skel-thumb';figure.tabIndex=0;
 const note=dcAlbumNotes[f.id]||'';
 // Collage: miniatura rápida; al hacer clic (dcOpenPhoto) se carga la foto a máxima calidad.
 figure.innerHTML=`<img data-dc-thumb="${dcPhotoThumbUrl(f.id, true)}" loading="lazy" onload="dcClearImgSkeleton(this)" onerror="dcClearImgSkeleton(this)" src="${DC_TRANSPARENT_PIXEL}" alt="${dcAlbumEsc(f.name)}">${note?`<figcaption><span class="dc-art-desc">${dcAlbumEsc(note)}</span></figcaption>`:''}<button class="dc-art-edit" type="button" aria-label="Editar descripción" title="Editar descripción">✎</button>`;
 figure.onclick=()=>dcOpenPhoto(f.id);figure.onkeydown=e=>{if(e.key==='Enter')dcOpenPhoto(f.id)};figure.querySelector('button').onclick=e=>{e.stopPropagation();dcAlbumArtEdit(f.id)};gallery.appendChild(figure);
 }bar.after(gallery);dcSweepSkeletons(gallery);
}
// Precarga real: la descarga y decodificacion terminan antes de empezar el giro.
// Se utiliza una cache acotada para no mantener el album entero en memoria.
const dcAlbumDecoded=new Map();
function dcAlbumPreload(index){
 const file=dcAlbumArtPhotos[index];if(!file)return Promise.resolve();
 // Album: siempre miniatura WebP cacheada, nunca el original completo.
 const src=dcPhotoThumbUrl(file.id, true);
 if(dcAlbumDecoded.has(src))return dcAlbumDecoded.get(src);
 const promise=window.DCThumbCache.request(src,true).then(result=>{
  if(!result.ok||!result.dataUrl)throw Error('Miniatura no disponible');
  const img=new Image();img.src=result.dataUrl;
  return img.decode?img.decode().catch(()=>{}):Promise.resolve();
 }).catch(()=>{if(dcAlbumDecoded.get(src)===promise)dcAlbumDecoded.delete(src);});
 dcAlbumDecoded.set(src,promise);
 if(dcAlbumDecoded.size>18)dcAlbumDecoded.delete(dcAlbumDecoded.keys().next().value);
 return promise;
}
function dcAlbumPreloadAround(page){
 if(navigator.connection?.saveData||/^(slow-2g|2g)$/.test(navigator.connection?.effectiveType||''))return;
 for(let p=Math.max(0,page-1);p<=Math.min(Math.ceil(dcAlbumArtPhotos.length/2)-1,page+2);p++){
  dcAlbumPreload(p*2);dcAlbumPreload(p*2+1);
 }
}
function dcAlbumArtOpenBook(){if(!dcAlbumArtPhotos.length){alert('El álbum todavía no tiene fotografías.');return;}dcAlbumBookPage=Math.min(dcAlbumBookPage,Math.max(0,Math.ceil(dcAlbumArtPhotos.length/2)-1));dcAlbumPreloadAround(dcAlbumBookPage);dcAlbumArtBookRender();}
function dcAlbumArtMakePage(index,interactive=true){
 const f=dcAlbumArtPhotos[index], page=document.createElement('div');page.className='dc-art-page';
 if(f){
  const note=dcAlbumNotes[f.id]||'';
  const thumbUrl=dcPhotoThumbUrl(f.id, true);
  page.innerHTML=`<div class="dc-art-page-photo skel"><img onload="dcClearImgSkeleton(this)" onerror="dcFailImgSkeleton(this)" src="${DC_TRANSPARENT_PIXEL}" alt="${dcAlbumEsc(f.name)}"></div>${note?`<p>${dcAlbumEsc(note)}</p>`:''}${interactive?`<button type="button" class="dc-art-caption-action" aria-label="Editar descripción">✎ ${note?'Editar descripción':'Añadir descripción'}</button>`:''}`;
  dcLoadImageSoon(page.querySelector('.dc-art-page-photo img'), thumbUrl);
  if(interactive)page.querySelector('button').onclick=()=>dcAlbumArtEdit(f.id);
 }else page.innerHTML='<span class="dc-art-page-empty">Fin del álbum ✦</span>';
 return page;
}
function dcAlbumArtBookRender(){
 if(dcAlbumBookBusy)return;
 let shell=document.getElementById('dcAlbumBook');if(!shell){shell=document.createElement('div');shell.id='dcAlbumBook';shell.className='dc-art-book-shell';shell.setAttribute('role','dialog');shell.setAttribute('aria-modal','true');document.body.appendChild(shell);}
 const total=Math.ceil(dcAlbumArtPhotos.length/2);
 shell.innerHTML=`<div class="dc-art-book-top"><strong>📖 Álbum fotográfico</strong><span>Página ${dcAlbumBookPage+1} de ${total}</span><button type="button" data-close>✕ Cerrar libro</button></div><div class="dc-art-book" id="dcAlbumBookPages"></div><div class="dc-art-book-footer"><button type="button" data-prev ${dcAlbumBookPage===0?'disabled':''}>❮ Anterior</button><button type="button" data-next ${dcAlbumBookPage>=total-1?'disabled':''}>Siguiente ❯</button></div>`;
 const pages=shell.querySelector('#dcAlbumBookPages');
 pages.append(dcAlbumArtMakePage(dcAlbumBookPage*2),dcAlbumArtMakePage(dcAlbumBookPage*2+1));
 dcAlbumPreloadAround(dcAlbumBookPage);
 const binding=document.createElement('div');binding.className='dc-art-spiral';binding.setAttribute('aria-hidden','true');
 for(let i=0;i<12;i++){const ring=document.createElement('span');ring.className='dc-art-ring';binding.appendChild(ring)}
 pages.appendChild(binding);
 shell.querySelector('[data-close]').onclick=()=>{if(dcAlbumBookBusy)return;dcCaptureFolderViewDcft();shell.remove()};
 shell.querySelector('[data-prev]').onclick=()=>dcAlbumArtTurn(-1);
 shell.querySelector('[data-next]').onclick=()=>dcAlbumArtTurn(1);
}
async function dcAlbumArtTurn(direction){
 const total=Math.ceil(dcAlbumArtPhotos.length/2);
 if(dcAlbumBookBusy||!document.getElementById('dcAlbumBook')||dcAlbumBookPage+direction<0||dcAlbumBookPage+direction>=total)return;
 const book=document.getElementById('dcAlbumBookPages');if(!book)return;
 dcAlbumBookBusy=true;
 const curr=dcAlbumBookPage*2,next=(dcAlbumBookPage+direction)*2;
 // Preparar las fotos antes del giro; no desvanecer la página durante la espera.
 book.closest('#dcAlbumBook')?.querySelectorAll('[data-prev],[data-next]').forEach(b=>b.disabled=true);
 await Promise.race([
  Promise.all([dcAlbumPreload(next),dcAlbumPreload(next+1),dcAlbumPreload(curr),dcAlbumPreload(curr+1)]),
  new Promise(resolve=>setTimeout(resolve,2400))
 ]);
 if(!document.getElementById('dcAlbumBook')||!book.isConnected){dcAlbumBookBusy=false;return;}
 dcAlbumPreloadAround(dcAlbumBookPage+direction);
 // Derecha hacia izquierda: anverso = derecha actual; reverso = izquierda siguiente.
 // Izquierda hacia derecha: anverso = izquierda actual; reverso = derecha anterior.
 const front=direction>0?curr+1:curr;
 const back=direction>0?next:next+1;
 const sheet=document.createElement('div');sheet.className='dc-art-turn-sheet '+(direction>0?'forward':'backward');
 for(const [side,idx] of [['front',front],['back',back]]){
  const face=document.createElement('div');face.className='dc-art-turn-face '+side;
  face.append(dcAlbumArtMakePage(idx,true));face.querySelectorAll('.dc-art-caption-action').forEach(button=>{button.tabIndex=-1;button.setAttribute('aria-hidden','true');button.style.pointerEvents='none';});sheet.append(face);
 }
 // Bajo la hoja se descubre la nueva página derecha (al avanzar) o izquierda (al retroceder).
 if(direction>0) book.replaceChild(dcAlbumArtMakePage(next+1),book.children[1]);
 else book.replaceChild(dcAlbumArtMakePage(next),book.children[0]);
 book.append(sheet);
 book.closest('#dcAlbumBook')?.querySelectorAll('[data-prev],[data-next]').forEach(b=>b.disabled=true);
 let settled=false;const finish=()=>{
  if(settled)return;settled=true;dcAlbumBookPage+=direction;dcAlbumBookBusy=false;
  if(document.getElementById('dcAlbumBook'))dcAlbumArtBookRender();
 };
 sheet.addEventListener('animationend',e=>{if(e.target===sheet)finish()});
 setTimeout(finish,1150); // Respaldo si el navegador no emite animationend.
}
function dcAlbumArtEdit(id){const f=dcAlbumArtPhotos.find(p=>Number(p.id)===Number(id));if(!f)return;document.getElementById('dcAlbumArtEdit')?.remove();const wrap=document.createElement('div');wrap.id='dcAlbumArtEdit';wrap.className='dc-art-edit-backdrop';wrap.innerHTML=`<form class="dc-art-edit-form"><strong>✎ Descripción de la fotografía</strong><small></small><textarea maxlength="2000" placeholder="Lugar, fecha, personas, recuerdos o detalles especiales…"></textarea><div class="dc-art-form-buttons"><button type="button" data-cancel>Cancelar</button><button type="submit">Guardar</button></div></form>`;wrap.querySelector('small').textContent=f.name;wrap.querySelector('textarea').value=dcAlbumNotes[id]||'';wrap.querySelector('[data-cancel]').onclick=()=>wrap.remove();wrap.onclick=e=>{if(e.target===wrap)wrap.remove()};wrap.querySelector('form').onsubmit=async e=>{e.preventDefault();const desc=wrap.querySelector('textarea').value.trim();const b=wrap.querySelector('[type=submit]');b.disabled=true;try{const fd=new FormData();fd.set('action','save');fd.set('file_id',id);fd.set('description',desc);const r=await fetch('dc_album_notes.php',{method:'POST',body:fd,headers:{'X-DC-CSRF':window.DC_AUTH?.csrf||''}});const j=await r.json();if(!r.ok||j.error)throw Error(j.error||'Error al guardar');if(desc)dcAlbumNotes[id]=desc;else delete dcAlbumNotes[id];wrap.remove();dcAlbumArtPaint();if(document.getElementById('dcAlbumBook'))dcAlbumArtBookRender();}catch(err){alert(err.message)}finally{b.disabled=false}};document.body.appendChild(wrap);wrap.querySelector('textarea').focus();}

// Multimedia: cola persistente y notificaciones descartables.
// dc-media-toast-v20109: avisos por trabajo, sin repeticion y con marca de agua.
const dcMediaSeen=new Map();
const dcMediaToasts=new Map();
let dcMediaFirstPoll=true;
function dcMediaLayoutStyle(){
 if(document.getElementById('dcMediaUIStyle209'))return;
 const st=document.createElement('style');st.id='dcMediaUIStyle209';st.textContent=`
 #dcMediaToasts{position:fixed;top:85px;right:18px;z-index:99999;display:grid;gap:8px;width:min(365px,calc(100vw - 36px));pointer-events:none}
 .dc-media-toast{position:relative;isolation:isolate;overflow:hidden;background:rgba(30,41,59,.78);backdrop-filter:blur(12px);-webkit-backdrop-filter:blur(12px);color:#fff;border:1px solid #64748b;border-radius:11px;box-shadow:0 8px 24px #0005;padding:13px 12px;display:flex;gap:12px;align-items:flex-start;pointer-events:auto}
 .dc-media-toast>*{position:relative;z-index:1}
 .dc-media-toast button{color:inherit;background:transparent;border:0;cursor:pointer;font-size:22px;line-height:1;padding:0 3px}
 #dcUploadQueue{transition:bottom .18s ease;isolation:isolate}
 #dcUploadQueue>*{position:relative;z-index:1}
 .dc-session-user{display:block;font-size:.81rem;opacity:.75;margin:0 0 8px 13px;overflow-wrap:anywhere}
 `;document.head.appendChild(st);
}
function dcMediaToast(message,jobId=null,phase='notice'){
 dcMediaLayoutStyle();let area=document.getElementById('dcMediaToasts');
 if(!area){area=document.createElement('div');area.id='dcMediaToasts';area.setAttribute('aria-live','polite');document.body.appendChild(area)}
 const key=jobId===null?null:String(jobId);
 let card=key===null?null:dcMediaToasts.get(key);
 if(!card || !card.isConnected){
  card=document.createElement('div');card.className='dc-media-toast';card.setAttribute('role','status');
  const msg=document.createElement('div');msg.className='dc-media-toast-message';msg.style.cssText='flex:1;white-space:pre-wrap;overflow-wrap:anywhere';
  const close=document.createElement('button');close.type='button';close.textContent='×';close.title='Cerrar aviso';close.setAttribute('aria-label','Cerrar notificación');
  close.onclick=()=>{card.remove();if(key!==null)dcMediaToasts.delete(key)};
  card.append(msg,close);area.appendChild(card);
  if(key!==null)dcMediaToasts.set(key,card);
 }
 card.dataset.phase=phase;card.querySelector('.dc-media-toast-message').textContent=message;
}
async function dcMediaRequest(action,values={}){
 const fd=new FormData();fd.set('action',action);Object.entries(values).forEach(([k,v])=>fd.set(k,v));
 const r=await fetch('api.php',{method:'POST',body:fd,headers:{'X-DC-CSRF':window.DC_AUTH?.csrf||''}});const j=await r.json();if(!r.ok||j.error)throw Error(j.error||'Error multimedia');return j;
}
async function dcMediaEnqueue(id,kind='convert',mode='manual'){
 try{
  const j=await dcMediaRequest('media_enqueue',{id,kind,mode});if(j.status==='already'){dcMediaToast('✅ '+(j.message||'Este video no necesita procesamiento'));return;}const key=Number(j.job_id);
  dcMediaSeen.set(key,'queued');
  dcMediaToast('⏳ El video se agregó a la cola. Puedes continuar usando Drive-Craft; recibirás otro aviso cuando termine.',key,'queued');
 }catch(e){if(mode==='manual')dcMediaToast('No se pudo agregar el video: '+e.message)}
}
async function dcMediaVideoToolbar(id){
 try{
  const r=await fetch('api.php?action=media_settings',{cache:'no-store'});const v=await r.json();
  if(!v.conversion_enabled)return;
  const body=document.getElementById('viewerBody');if(!body?.querySelector('video'))return;
  const bar=body.querySelector(':scope > .dc-video-actions84b')||(()=>{const el=document.createElement('div');el.className='dc-video-actions84b';el.setAttribute('role','group');el.setAttribute('aria-label','Acciones del video');body.append(el);return el;})();
  const button=document.createElement('button');button.type='button';button.textContent='🎞️ Comprobando códecs…';button.className='dc-v84-action';button.disabled=true;bar.append(button);
  const fast=document.createElement('button');fast.type='button';fast.textContent='⚡ Comprobando Faststart…';fast.className='dc-v84-action';fast.disabled=true;bar.append(fast);
  const s=await fetch('api.php?action=media_analyze&id='+encodeURIComponent(id),{cache:'no-store'});
  const state=await s.json();if(!s.ok||state.error)throw Error(state.error||'Análisis no disponible');
  if(!body.isConnected || Number(window.dcCurrentVideo64)!==Number(id))return;
  button.textContent=state.can_convert?'🎞️ Crear versión compatible':'✓ Video compatible';
  button.disabled=!state.can_convert;button.title=state.message||'';
  button.onclick=()=>dcMediaEnqueue(id,'convert','manual');
  fast.textContent=state.can_faststart?'⚡ Optimizar inicio (Faststart)':state.faststart===true?'✓ Faststart aplicado':state.compatible?'⚡ Faststart no verificable':'⚡ Requiere versión compatible';
  fast.disabled=!state.can_faststart;fast.title=state.message||'';
  fast.onclick=()=>dcMediaEnqueue(id,'faststart','manual');
 }catch(e){const body=document.getElementById('viewerBody');const bar=body?.querySelector('.dc-video-actions84b');if(bar){for(const b of bar.querySelectorAll('button.dc-v84-action')){b.disabled=true;b.title='No se pudo comprobar el estado del video';b.textContent='⚠ Análisis no disponible';}}}
}
async function dcMediaAfterUpload(id,name){
 try{const cfg=await(await fetch('api.php?action=media_settings',{cache:'no-store'})).json();if(!/\.(mp4|mov|mkv|avi|m4v|webm)$/i.test(name))return;
 if(cfg.thumbnails_enabled)await dcMediaEnqueue(id,'thumbnail','auto');
 if(cfg.conversion_enabled&&cfg.auto_conversion)await dcMediaEnqueue(id,'convert','auto');
 }catch(e){}
}
async function dcMediaPoll(){
 try{const r=await fetch('api.php?action=media_jobs',{cache:'no-store'});if(!r.ok)return;const d=await r.json();
 for(const j of d.jobs||[]){
  const id=Number(j.id),status=String(j.status||'');if(!Number.isSafeInteger(id))continue;
  const prior=dcMediaSeen.get(id);
  if(prior===undefined){
   dcMediaSeen.set(id,status);
   // No revivir errores históricos ni trabajos que ya terminaron antes de abrir la pagina.
   if(dcMediaFirstPoll && (status==='queued'||status==='running'))dcMediaToast('⏳ Procesamiento multimedia pendiente: '+(j.name||'Video'),id,status);
   continue;
  }
  if(prior===status)continue;
  dcMediaSeen.set(id,status);
  if(status==='running')dcMediaToast('⏳ Procesando video: '+(j.name||''),id,status);
  else if(status==='queued')dcMediaToast('⏳ Trabajo multimedia en cola: '+(j.name||''),id,status);
  else if(status==='done'||status==='error'){
   dcMediaToast((status==='done'?'✅ ':'⚠️ ')+(j.message||status)+' · '+(j.name||''),id,status);
   if(status==='done' && typeof loadFiles==='function')loadFiles().catch(()=>{});
  }
 }
 dcMediaFirstPoll=false;
 }catch(e){}
}
function dcUploadAvoidDiskOverlap(){
 const upload=document.getElementById('dcUploadQueue');if(!upload)return;
 const disk=document.getElementById('diskSpaceWatermark');let bottom=16;
 if(disk && !disk.hidden && getComputedStyle(disk).display!=='none'){
  const r=disk.getBoundingClientRect();
  if(r.width>0&&r.height>0&&r.right>innerWidth-420&&r.bottom>innerHeight-300)bottom=Math.max(16,innerHeight-r.top+12);
 }
 upload.style.setProperty('bottom',bottom+'px','important');
}
const dcUploadLayoutObserver=new MutationObserver(()=>dcUploadAvoidDiskOverlap());
function dcInitUploadLayout(){
 dcMediaLayoutStyle();const disk=document.getElementById('diskSpaceWatermark');
 if(disk)dcUploadLayoutObserver.observe(disk,{attributes:true,attributeFilter:['hidden','style','class'],childList:true,subtree:true});
 window.addEventListener('resize',dcUploadAvoidDiskOverlap,{passive:true});
 dcUploadAvoidDiskOverlap();
}
if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',dcInitUploadLayout,{once:true});else dcInitUploadLayout();
setInterval(()=>{if(!document.hidden)dcMediaPoll()},15000);
setTimeout(dcMediaPoll,2500);


// DC_INTEGRITY_UNIFIED_V201013 — un solo panel administrativo, dos comprobaciones independientes.
function dcOpenUnifiedIntegrity() {
  dcUtility('🔎 Integridad de datos',
    '<p>Selecciona la comprobación que deseas ejecutar. Ninguna modifica documentos ni permisos existentes.</p>'+
    '<div class="dc-utility-row" style="gap:10px;flex-wrap:wrap">'+
    '<button type="button" id="dcUnifiedCheckFiles">🔎 Analizar integridad de archivos</button>'+
    '<button type="button" id="dcUnifiedCheckPermissions">🛡️ Validar permisos de almacenamiento</button></div>'+
    '<div id="dcUnifiedResult" role="status" aria-live="polite" style="margin-top:14px"></div>');
  const modal=document.querySelector('#dcUtilityModal .dc85i-admin-subview')||document.getElementById('dcUtilityBody');
  modal.querySelector('#dcUnifiedCheckFiles').addEventListener('click',()=>dcUnifiedCheck('files'));
  modal.querySelector('#dcUnifiedCheckPermissions').addEventListener('click',()=>dcUnifiedCheck('permissions'));
}
async function dcUnifiedCheck(type) {
 const container=document.querySelector('#dcUtilityModal .dc85i-admin-subview')||document.getElementById('dcUtilityBody');
 const result=container?.querySelector('#dcUnifiedResult');
 if(!result)return;
 const buttons=container.querySelectorAll('button');
 buttons.forEach(b=>b.disabled=true);
 result.textContent=type==='files'?'Analizando referencias y archivos físicos…':'Validando lectura, creación y eliminación de archivos temporales…';
 try {
   let r;
   if(type==='files'){
     r=await fetch('dc_integrity.php',{credentials:'same-origin',cache:'no-store'});
   }else{
     const fd=new FormData();fd.set('csrf',window.DC_AUTH?.csrf||'');
     r=await fetch('dc_storage_diagnostic.php?dc_json=1',{method:'POST',body:fd,credentials:'same-origin',cache:'no-store'});
   }
   const data=await r.json();
   if(!r.ok||!data.success)throw Error(data.error||'La comprobación no pudo completarse');
   if(type==='files'){
     const counts=Object.entries(data.issue_counts||{});
     result.innerHTML=`<h4>Integridad de archivos</h4>`+
       `<p><strong>Archivos:</strong> ${Number(data.checked_files)} · <strong>Versiones:</strong> ${Number(data.checked_versions)}</p>`+
       `<p>${data.truncated?'⚠️ Resultado parcial: límite alcanzado.':'Análisis finalizado.'} No se modificó ningún archivo.</p>`+
       (counts.length?counts.map(([key,val])=>`<div class="dc-utility-row"><strong>${safeShareText(key)}</strong><span>${Number(val)} · pendiente de revisión</span></div>`).join(''):'<p>Sin incidencias detectadas.</p>')+
       (counts.some(([key])=>key==='orphan_internal_shares'||key==='orphan_internal_shortcuts')?'<button type="button" id="dcRepairOrphanShares63">Corregir comparticiones internas huérfanas</button>':'')+
       (data.examples?.length?'<h4>Hallazgos del análisis actual (no resueltos)</h4><p>Los archivos físicos sin referencia detectada pueden pertenecer a módulos auxiliares. Analiza su origen antes de eliminarlos; este informe no modifica datos.</p>'+data.examples.map(e=>`<div class="dc-utility-row"><span>${safeShareText(e.type)}</span><small>${safeShareText(JSON.stringify(e))}</small>${e.type==='unreferenced_physical_file'&&/^[a-f0-9]{32}(?:[a-f0-9]{8})?(?:_[A-Za-z0-9][A-Za-z0-9._-]{0,180})?_compatible\.mp4$/.test(e.storage_name||'')?`<button type="button" data-dc-cleanup="${safeShareText(e.storage_name)}">Revisar y eliminar residuo</button>`:''}</div>`).join(''):'');
     const fixButton=result.querySelector('#dcRepairOrphanShares63');
     if(fixButton)fixButton.addEventListener('click',async()=>{
       if(!confirm('¿Corregir permisos de compartición interna que apuntan a usuarios o archivos eliminados? No se borrarán archivos.'))return;
       fixButton.disabled=true;
       try{
         const fd=new FormData();fd.set('csrf',window.DC_AUTH?.csrf||'');fd.set('action','clean_orphan_internal_shares');fd.set('confirmation','CORREGIR');
         const r=await fetch('dc_integrity.php',{method:'POST',body:fd,credentials:'same-origin'});
         const data=await r.json();if(!r.ok||!data.success)throw Error(data.error||'Error de integridad');
         alert('Permisos huérfanos eliminados: '+data.removed_shares+' · Accesos directos: '+data.removed_shortcuts);
         await dcUnifiedCheck('files');if(typeof dcRefreshShareBadges==='function')dcRefreshShareBadges();
       }catch(e){alert(e.message);fixButton.disabled=false;}
     });
   } else {
     const rows=Array.isArray(data.results)?data.results:[];
     result.innerHTML='<h4>Permisos del almacenamiento</h4>'+
       rows.map(x=>{
         const ok=x.exists&&x.read&&x.write&&x.delete;
         return `<div class="dc-utility-row" style="display:block"><strong>${safeShareText(x.label)}</strong> — ${ok?'✅ Correcto':'⚠️ Revisar permisos'}`+
           `<div><small>${safeShareText(x.directory)}</small></div>`+
           `<div><small>Existe: ${x.exists?'Sí':'No'} · Lectura: ${x.read?'Sí':'No'} · Escritura: ${x.write?'Sí':'No'} · Borrado: ${x.delete?'Sí':'No'}</small></div>`+
           (x.details?`<div><small>${safeShareText(x.details)}</small></div>`:'')+'</div>';
       }).join('')+
       '<p>Si alguna comprobación falla, revisa grupo y ACL de la ruta afectada. '+
       '<a href="dc_storage_diagnostic.php" target="_blank" rel="noopener noreferrer">Ver diagnóstico detallado y comandos recomendados</a>.</p>';
   }
 }catch(e){result.textContent='No se pudo completar la comprobación: '+e.message;}
 finally{buttons.forEach(b=>b.disabled=false);}
}

// DC_STORAGE_ACTIONS_V201014
async function dcStoragePost(values){
 const fd=new FormData();for(const [k,v] of Object.entries(values))fd.set(k,String(v));
 fd.set('csrf',window.DC_AUTH?.csrf||'');
 const r=await fetch('dc_storage_actions.php',{method:'POST',body:fd,credentials:'same-origin',cache:'no-store'});
 const j=await r.json();if(!r.ok||!j.success){const err=new Error(j.error||'No se pudo completar');err.details=j;throw err;}return j;
}
function dcImportShareLink(){
 if(!dcCanUpload){alert('Tu cuenta no tiene permiso de subida');return;}
 if(typeof isSearching!=='undefined'&&isSearching){alert('Abre primero la carpeta de destino');return;}
 const folder=Number(currentParentId||0);
 const node=allFoldersData.find(f=>Number(f.id)===folder);
 if(folder!==0&&(node?.is_album==1||String(node?.mime_type||'directory')!=='directory'||/^(youtube|links de youtube)$/i.test(String(node?.name||'')))){
  alert('Esta función no está disponible en álbumes ni carpetas especiales de YouTube');return;
 }
 dcUtility('🔗 Guardar desde enlace','<p>Pega un enlace compartido de este Drive-Craft o la URL directa de un archivo público. Drive-Craft distinguirá el origen automáticamente. Las descargas externas se realizan desde el servidor.</p><p>Carpeta de destino: la carpeta abierta actualmente.</p><input id="dcSharedInput" type="url" placeholder="https://ejemplo.com/archivo.webp" style="width:100%;max-width:100%" autocomplete="off"><div style="margin-top:10px"><button type="button" id="dcSharedSave">Guardar archivo</button></div><p id="dcSharedStatus" role="status"></p>');
 const body=document.getElementById('dcUtilityBody');
 body.querySelector('#dcSharedSave').addEventListener('click',async()=>{
  const btn=body.querySelector('#dcSharedSave');const status=body.querySelector('#dcSharedStatus');btn.disabled=true;status.textContent='Validando enlace y guardando archivo…';
  try{
   const input={action:'import_share',link:body.querySelector('#dcSharedInput').value,parent_id:folder};let result;
   try{result=await dcStoragePost(input)}catch(e){
    if(!e.details?.conflict)throw e;
    const choice=confirm('Ya existe «'+(e.details.filename||'archivo')+'».\n\nAceptar: reemplazar el archivo existente.\nCancelar: elegir renombrarlo.');
    const mode=choice?'replace':'rename_old';
    if(!choice&&!confirm('¿Renombrar el archivo anterior y conservar ambos?\n\nAceptar: renombrar anterior.\nCancelar: no importar.')){status.textContent='Importación cancelada';return;}
    status.textContent='Resolviendo archivo duplicado…';result=await dcStoragePost({...input,conflict_mode:mode});
   }
   status.textContent=result.message||'Archivo guardado';if(Number(currentParentId||0)===folder)await loadFiles();
  }
  catch(e){status.textContent='No se pudo guardar: '+e.message;}finally{btn.disabled=false;}
 });
}
// Delegación de eventos: únicamente botones renderizados por la comprobación de integridad.
document.addEventListener('click',async function(event){
 const btn=event.target.closest?.('[data-dc-cleanup]');if(!btn||window.DC_AUTH?.role!=='admin')return;
 const name=btn.getAttribute('data-dc-cleanup');if(!/^[a-f0-9]{32}(?:[a-f0-9]{8})?(?:_[A-Za-z0-9][A-Za-z0-9._-]{0,180})?_compatible\.mp4$/.test(name))return;
 if(!confirm('¿Revisar y eliminar físicamente este residuo?\n\n'+name+'\n\nNo se recuperará el nombre eliminado.'))return;
 const answer=prompt('Escribe ELIMINAR para confirmar la operación:');if(answer!=='ELIMINAR')return;
 btn.disabled=true;
 try{const result=await dcStoragePost({action:'delete_orphan',storage_name:name,confirm:'ELIMINAR'});alert(result.message+'. Enlaces físicos restantes: '+result.remaining_links+'. Vuelve a analizar la integridad.');btn.closest('.dc-utility-row')?.remove();}
 catch(e){alert('No se eliminó: '+e.message);btn.disabled=false;}
});

/* v20.10.26: sincronizar controles administrativos al abrir configuración. */
const dcPreviewObserver=new MutationObserver(()=>{const el=document.getElementById('dcLocalPreviewSettings');if(el&&!el.dataset.loaded){el.dataset.loaded='1';dlSettingsLoad();}});dcPreviewObserver.observe(document.body,{childList:true,subtree:true});

/* v20.10.27: ajustar ancho únicamente en el panel de configuración. */
const dcSettingsPanelObserver=new MutationObserver(()=>{const m=document.getElementById('dcUtilityModal'),t=document.getElementById('dcUtilityTitle');if(m&&t)m.classList.toggle('dc-settings-open',DCI18n.original(t).includes('Panel de configuración')&&m.style.display!=='none');});dcSettingsPanelObserver.observe(document.body,{childList:true,subtree:true,attributes:true,attributeFilter:['style']});

// v20.10.40 — revocar enlaces vencidos, confirmando antes de modificar.
async function dcRevokeExpiredShares(){
 try{
  await refreshShareManager();
  const expired=allManagedShares.filter(s=>{
   if(!s.expires_at)return false;
   const dt=new Date(String(s.expires_at).replace(' ','T'));
   return !Number.isNaN(dt.getTime()) && dt.getTime()<=Date.now();
  });
  if(!expired.length){alert('No hay enlaces vencidos.');return;}
  if(!confirm(`¿Revocar ${expired.length} enlace(s) vencido(s)?`))return;
  let done=0,errors=0;
  for(const s of expired){try{await shareRequest('delete_share',{share_id:Number(s.id)});done++;}catch(e){errors++;console.warn('Revocación fallida',e);}}
  await refreshShareManager();alert(`Revocados: ${done}. Errores: ${errors}.`);
 }catch(e){alert('Error: '+e.message);}
}

// v20.10.41 — copiar con feedback sin mover botones ni usar diálogos de debug.
const dc41CopyTimers=new WeakMap();
function dc41Feedback(node,msg){
 if(!node)return;
 const previous=dc41CopyTimers.get(node);if(previous)clearTimeout(previous);
 node.textContent=msg;
 dc41CopyTimers.set(node,setTimeout(()=>{if(node.isConnected)node.textContent='';},2300));
}
async function dc41CopyText(value){
 try{if(navigator.clipboard&&window.isSecureContext){await navigator.clipboard.writeText(value);return true;}}catch(_e){}
 const box=document.createElement('textarea');box.value=value;box.readOnly=true;
 box.style.cssText='position:fixed;left:-9999px;top:0';document.body.appendChild(box);box.select();
 let ok=false;try{ok=document.execCommand('copy');}catch(_e){}box.remove();return ok;
}


// v20.10.57 editor de texto, modo lectura inicial y previsualización Markdown.
async function dcOpenTextEditor57(id){return window.DCTextEditor.open(id);}

(()=>{if(document.querySelector('link[data-dc57]'))return;const l=document.createElement('link');l.rel='stylesheet';l.href='dc-editor-57.css?v=20.10.85';l.dataset.dc57='1';document.head.append(l)})();

async function dcCopyGeneratedShare(){
 const input=document.getElementById('shareResult');if(!input||!input.value)return;
 const ok=await dc41CopyText(input.value);
 const status=document.getElementById('dcCopyShareStatus');
 if(status)status.textContent=ok?' Copiado':' Selecciona y copia el enlace';
 if(!ok){input.focus();input.select()}
}

// Navegacion entre videos de la carpeta visible; solo usa IDs recibidos del backend.
// DC85: ventana de video flotante dentro de Drive-Craft (conserva el elemento y sus eventos).
window.dcFloatVideo85=function(){
 const v=document.querySelector('#viewerBody video');if(!v)return;
 const body=document.getElementById('viewerBody');const modal=document.getElementById('viewerModal');
 const float=document.createElement('div');float.id='dcFloat85';float.className='dc-floating85';
 float.innerHTML='<div class="dc-floating85-bar"><strong>Video flotante</strong><span class="dc-floating85-spacer"></span><button type="button" data-expand title="Volver al visor">⛶</button><button type="button" data-close title="Cerrar video">✕</button></div><div class="dc-floating85-content"></div><div class="dc-floating85-controls"><button type="button" data-rewind>↶ 10 s</button><button type="button" data-forward>10 s ↷</button></div>';
 const anchor=document.createComment('ubicación original del video');v.before(anchor);float.querySelector('.dc-floating85-content').append(v);document.body.append(float);modal.style.display='none';
 const restore=()=>{if(anchor.isConnected)anchor.replaceWith(v);float.remove();modal.style.display='block'};
 float.querySelector('[data-expand]').onclick=restore;
 float.querySelector('[data-close]').onclick=()=>{v.pause();float.remove();anchor.remove();closeModal('viewerModal')};
 float.querySelector('[data-rewind]').onclick=()=>{v.currentTime=Math.max(0,v.currentTime-10)};
 float.querySelector('[data-forward]').onclick=()=>{v.currentTime=Math.min(Number.isFinite(v.duration)?v.duration:1e10,v.currentTime+10)};
 const bar=float.querySelector('.dc-floating85-bar');let drag=null;
 bar.addEventListener('pointerdown',e=>{if(e.target.closest('button'))return;drag={x:e.clientX,y:e.clientY,l:float.getBoundingClientRect().left,t:float.getBoundingClientRect().top};bar.setPointerCapture(e.pointerId)});
 bar.addEventListener('pointermove',e=>{if(!drag)return;float.style.left=Math.max(0,Math.min(innerWidth-float.offsetWidth,drag.l+e.clientX-drag.x))+'px';float.style.top=Math.max(0,Math.min(innerHeight-float.offsetHeight,drag.t+e.clientY-drag.y))+'px';float.style.right='auto';float.style.bottom='auto'});
 bar.addEventListener('pointerup',()=>drag=null);bar.addEventListener('pointercancel',()=>drag=null);
};
window.dcSeekVideo85=function(seconds){const v=document.querySelector('#viewerBody video');if(!v||!Number.isFinite(v.duration))return;v.currentTime=Math.max(0,Math.min(Math.max(0,v.duration-.2),v.currentTime+seconds));v.dispatchEvent(new Event('timeupdate'));};
window.dcVideoSlide64=function(step){
 const list=window.dcVideoFiles64||[];
 const i=list.findIndex(v=>v.id===window.dcCurrentVideo64);
 const next=list[i+step];
 if(!next)return;
 const old=document.querySelector('#viewerBody video');if(old){old.pause();old.removeAttribute('src');old.load();}
 viewFile(next.id,next.mime);
};

(function(){if(!document.getElementById('dcVideoStyle64')){const s=document.createElement('link');s.id='dcVideoStyle64';s.rel='stylesheet';s.href='dc-video-nav-64.css?v=20.10.64';document.head.append(s);}})();

// DC85L: el botón consulta la última operación real almacenada en la sesión PHP.
window.dcUndoRefresh85l=async function(){
 const b=document.getElementById('dcUndo85l');if(!b)return;
 try{const r=await fetch('api.php?action=undo_status_85l',{credentials:'same-origin',cache:'no-store'});const data=await r.json();if(!r.ok)throw Error();b.disabled=!data.count;b.title=data.count?'Deshacer: '+data.label:'No hay acciones para deshacer';b.setAttribute('aria-label',b.title);}catch(e){b.disabled=true;b.title='Historial de deshacer no disponible';}
};
window.dcUndoAction85l=async function(){
 const b=document.getElementById('dcUndo85l');if(!b||b.disabled)return;
 b.disabled=true;
 try{const result=await dcPost('undo_85l');if(result.error)throw Error(result.error);await loadTree();await loadFiles();}catch(e){alert(e.message||'No se pudo deshacer');}
 finally{window.dcUndoRefresh85l();}
};
if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',()=>window.dcUndoRefresh85l(),{once:true});else window.dcUndoRefresh85l();

function toggleSidebarFabMenu(forceClose) {
  const m = document.getElementById('dcSidebarFabMenu');
  if (!m) return;
  if (forceClose === true) { m.classList.remove('show'); m.setAttribute('aria-hidden','true'); return; }
  const open = m.classList.toggle('show');
  m.setAttribute('aria-hidden', open ? 'false' : 'true');
}
document.addEventListener('click', (e) => {
  const wrap = document.querySelector('.dc-sidebar-fab-wrap');
  if (wrap && !wrap.contains(e.target)) {
    const m = document.getElementById('dcSidebarFabMenu');
    if (m) { m.classList.remove('show'); m.setAttribute('aria-hidden','true'); }
  }
});

// Play a file directly; no virtual album card or stored collection is created.
function dcPlayLooseAudioDcft(id){
 const meta=dcDragFileMeta.get(Number(id));if(!meta||meta.isDir)return;
 dcCloseContextMenus();window.dcMusicPlayFile81?.(Number(id),meta.name,Number(currentParentId));
}
