(function(){
const escapeHtml = value=>String(value??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
const ADMIN_TITLES=['Administración de usuarios','Hardlinks globales','Multimedia y conversión local','Consumo por usuario'];
function syncAdminModalClass(){
 const modal=document.getElementById('dcUtilityModal');
 const title=document.getElementById('dcUtilityTitle');
 if(!modal||!title) return;
 const txt=DCI18n.original(title);
 const open=modal.style.display!=='none' && ADMIN_TITLES.some(t=>txt.includes(t));
 modal.classList.toggle('dc-admin-open',open);
 modal.classList.toggle('dc-admin-narrow',open && /Hardlinks globales|Multimedia y conversión local/.test(txt));
}
function adminUtility(title,html){
 dcUtility(title,html);
 syncAdminModalClass();
}
new MutationObserver(syncAdminModalClass).observe(document.body,{childList:true,subtree:true,attributes:true,attributeFilter:['style']});
async function request(action, values={}){const run=()=>requestRawDcft(action,values);return window.DCSaveFeedback?window.DCSaveFeedback.observe(action,run):run();}
async function requestRawDcft(action, values={}) {
 const body=new FormData();body.append('action',action);
 Object.entries(values).forEach(([k,v])=>body.append(k,v));
 const r=await fetch('admin_api.php',{method:'POST',body,headers:{'X-DC-CSRF':window.DC_AUTH?.csrf||''}});
 const raw=await r.text();
 let data;
 try{data=JSON.parse(raw)}catch(e){
  const snippet=(raw||'').replace(/\s+/g,' ').trim().slice(0,180);
  throw Error(snippet?('Respuesta no JSON: '+snippet):('HTTP '+r.status+' sin cuerpo'));
 }
 if(!r.ok||data.error)throw Error(data.error||('Error del servidor ('+r.status+')'));
 return data;
}
const changeForm=document.getElementById('dcChangePassword');
if(changeForm)changeForm.addEventListener('submit',async e=>{
 e.preventDefault();const f=new FormData(changeForm);
 try {await request('change_password',Object.fromEntries(f));location.reload();}
 catch(ex){document.getElementById('dcPasswordError').textContent=ex.message;}
});
function infoTip(text){ return `<span class="dc-help" title="${escapeHtml(text)}">ⓘ</span>`; }
function checkRow(inputHtml,label,help=''){ return `<label class="dc-check-row"><span>${label}${help?` ${infoTip(help)}`:''}</span>${inputHtml}</label>`; }
function actionButtons(saveText,backJs,saveId=''){ return `<div class="dc-admin-actions"><button type="submit"${saveId?` id="${saveId}"`:''}>${saveText}</button><button type="button" onclick="${backJs}">Volver</button></div>`; }
function fieldLabel(label,help=''){return `<span class="dc-field-label">${label}${help?` ${infoTip(help)}`:''}</span>`;}
window.dcShowStorageUsers=async function(){
 try {
  const data=await request('storage_users');
  const size=n=>{let b=Number(n)||0;for(const unit of ['B','KB','MB','GB','TB']){if(b<1024||unit==='TB')return b.toFixed(unit==='B'?0:2)+' '+unit;b/=1024;}};
  const rows=data.users.map(u=>{const total=Number(u.current_bytes)+Number(u.version_bytes);const q=Number(u.quota_bytes)||0;const pct=q?Math.round(total/q*100):null;return `<tr><td>${escapeHtml(u.username)}</td><td>${size(u.current_bytes)}</td><td>${size(u.version_bytes)}</td><td>${size(u.trashed_bytes)}</td><td><strong>${size(total)}</strong></td><td>${q?size(q)+' ('+pct+'%)':'Sin límite'}</td></tr>`;}).join('');
  adminUtility('📊 Consumo por usuario',`<p>Los archivos en papelera se incluyen en el total de archivos. El historial se suma por separado.</p><div class="dc-table-wrap"><table class="dc-admin-table"><thead><tr><th>Usuario</th><th>Archivos</th><th>Historial</th><th>En papelera (incluido)</th><th>Total</th><th>Cuota</th></tr></thead><tbody>${rows}</tbody></table></div><div class="dc-admin-actions"><button type="button" onclick="dcOpenUsers()">Volver a usuarios</button></div>`);
 } catch(e) {alert('No se pudo mostrar el consumo: '+e.message);}
};
window.dcOpenGlobalHardlinks=async function(){
 try{
  const v=await request('global_hardlinks_get');
  const groups=[["audio", "Audio (MP3, FLAC, WAV, M4A, AAC, OGG)", 1], ["video", "Video (MP4, MKV, AVI, MOV, WEBM)", 100], ["images", "Imágenes (JPG, JPEG, PNG, GIF, WEBP, AVIF, BMP, TIFF)", 0], ["disk", "Imágenes de disco (ISO, IMG, BIN)", 100], ["installers", "Instaladores (EXE, MSI, DMG, APPIMAGE)", 10], ["archives", "Comprimidos (ZIP, 7Z, RAR, TAR, GZ, ZST)", 10], ["pdf", "PDF", 1], ["documents", "Documentos editables (DOCX, XLSX, PPTX, ODT)", 1], ["textcode", "Texto y código (TXT, LOG, PHP, JS, YAML, YML, HTML, JAVA, MD, CSS, JSON, XML, SQL, PY, SH)", 0]];
  const mins=v.min_by_group||{};
  const items=groups.map(([id,label,defaultMiB])=>`<div class="dc-hl-row49"><label class="dc-check-row"><span>${escapeHtml(label)}</span><input type="checkbox" name="group" value="${id}" ${v.groups.includes(id)?'checked':''}></label><input type="number" name="min_${id}" min="0" max="10240" step="1" value="${Number(mins[id]??defaultMiB)}" title="0 permite archivos pequeños de esta categoría"></label></div>`).join('');
  adminUtility('Hardlinks globales',`<p>Los archivos idénticos pueden compartir inode en el mismo volumen. Cada tipo tiene su propio mínimo en MiB; 0 permite archivos pequeños. Se conservan las cuotas lógicas por usuario.</p><form id="dcHardlinksForm" class="dc-admin-editor dc-admin-compact-form"><section class="dc-admin-card">${checkRow(`<input type="checkbox" name="enabled" ${v.enabled?'checked':''}>`,'Activar deduplicación física automática')}</section><section class="dc-admin-card"><h5>Tipos y mínimos individuales</h5><div class="dc-check-list">${items}</div></section><small>Las subidas nuevas usan Merkle (hojas por fragmento) y head_hash de 2 MiB. El indexado retroactivo rellena SHA-256 + head_hash en archivos ya existentes. Requiere el mismo sistema de archivos.</small>${actionButtons('Guardar configuración','openSettings()')}</form>`);
 const savings=document.createElement('p');savings.className='dc-hl-savings51';savings.textContent='Calculando espacio físico ahorrado…';
 const indexBox=document.createElement('div');indexBox.className='dc-admin-card';indexBox.style.marginTop='12px';
 indexBox.innerHTML=`
  <h5 style="margin:0 0 8px">Índice de hashes (archivos existentes)</h5>
  <p id="dcHashIndexStatus" style="margin:0 0 8px">Consultando índice de hashes…</p>
  <div id="dcHashProgressWrap" style="display:none;margin:8px 0 4px">
   <div style="display:flex;justify-content:space-between;font-size:12px;opacity:.85;margin-bottom:4px">
    <span id="dcHashProgressLabel">0%</span>
    <span id="dcHashProgressCounts"></span>
   </div>
   <progress id="dcHashProgressBar" max="100" value="0" style="width:100%;height:14px"></progress>
  </div>
  <p style="display:flex;gap:8px;flex-wrap:wrap;margin:8px 0 0">
   <button type="button" id="dcHashBackfillBtn" class="dc-admin-btn">Indexar todos los pendientes</button>
   <button type="button" id="dcHashBackfillStop" class="dc-admin-btn" style="display:none">Detener</button>
  </p>
  <p class="dc-admin-note" style="margin:8px 0 0;font-size:12px;opacity:.8">El servidor procesa lotes pequeños para no saturar la Raspberry Pi; el navegador encadena lotes hasta terminar y muestra el avance.</p>`;
 const form=document.getElementById('dcHardlinksForm');if(form){form.append(savings);form.append(indexBox);}
 try{const stat=await request('global_hardlinks_savings');if(savings.isConnected){savings.textContent=stat.available?'Ahorro físico comprobado: '+(Number(stat.saved_bytes)/1048576).toLocaleString('es-MX',{maximumFractionDigits:2})+' MiB · '+Number(stat.linked_files)+' rutas deduplicadas.':'Ahorro no disponible: '+(stat.reason||'Reintenta más tarde');}}catch(e){if(savings.isConnected)savings.textContent='No se pudo calcular el ahorro: '+e.message;}

 const setProgress=(done,total)=>{
  const wrap=document.getElementById('dcHashProgressWrap');
  const bar=document.getElementById('dcHashProgressBar');
  const lab=document.getElementById('dcHashProgressLabel');
  const counts=document.getElementById('dcHashProgressCounts');
  if(!wrap||!bar)return;
  wrap.style.display='block';
  const t=Math.max(0,Number(total)||0);
  const d=Math.max(0,Math.min(t,Number(done)||0));
  const pct=t>0?Math.round(d/t*100):(d>0?100:0);
  bar.max=100;bar.value=pct;
  if(lab)lab.textContent=pct+'%';
  if(counts)counts.textContent=d.toLocaleString('es-MX')+' / '+t.toLocaleString('es-MX');
 };

 const refreshIndex=async()=>{
  const el=document.getElementById('dcHashIndexStatus');if(!el)return null;
  try{
   const s=await request('hash_backfill_status');
   const indexed=Number(s.indexed)||0, pending=Number(s.pending)||0;
   el.textContent=pending>0
     ?('Indexados: '+indexed.toLocaleString('es-MX')+' · Pendientes: '+pending.toLocaleString('es-MX'))
     :('Índice completo: '+indexed.toLocaleString('es-MX')+' archivos con hash');
   if(pending===0) setProgress(indexed,indexed);
   return s;
  }catch(e){el.textContent='No se pudo consultar el índice: '+e.message;return null;}
 };
 await refreshIndex();

 let dcHashBackfillAbort=false;
 document.getElementById('dcHashBackfillStop')?.addEventListener('click',()=>{dcHashBackfillAbort=true;});

 document.getElementById('dcHashBackfillBtn')?.addEventListener('click',async()=>{
  const btn=document.getElementById('dcHashBackfillBtn');
  const stop=document.getElementById('dcHashBackfillStop');
  const el=document.getElementById('dcHashIndexStatus');
  if(btn)btn.disabled=true;
  if(stop)stop.style.display='';
  dcHashBackfillAbort=false;
  let totalUpdated=0, totalSkipped=0;
  try{
   let status=await refreshIndex();
   if(!status)throw Error('Sin estado de índice');
   let pending=Number(status.pending)||0;
   let indexed=Number(status.indexed)||0;
   const baseline=indexed+pending;
   if(pending===0){
    if(el)el.textContent='No hay pendientes: el índice ya está completo ('+indexed.toLocaleString('es-MX')+' archivos).';
    setProgress(indexed,indexed);
    return;
   }
   setProgress(indexed,baseline);
   if(el)el.textContent='Indexando… no cierres esta ventana.';
   while(pending>0 && !dcHashBackfillAbort){
    const r=await request('hash_backfill_run',{limit:25});
    totalUpdated+=Number(r.updated)||0;
    totalSkipped+=Number(r.skipped)||0;
    pending=Number(r.pending);
    if(Number.isNaN(pending)) pending=0;
    indexed=baseline-pending;
    if(indexed<0) indexed=0;
    setProgress(indexed,baseline);
    if(el){
     el.textContent=dcHashBackfillAbort
      ?'Deteniendo…'
      :('Indexando… actualizados en esta sesión: '+totalUpdated.toLocaleString('es-MX')
        +(totalSkipped?(' · omitidos: '+totalSkipped.toLocaleString('es-MX')):'')
        +' · quedan: '+pending.toLocaleString('es-MX'));
    }
    if((Number(r.processed)||0)===0 && pending>0){
     // Evitar bucle infinito si todos los restantes fallan/omitidos sin bajar pending
     break;
    }
    await new Promise(res=>setTimeout(res,120));
   }
   status=await refreshIndex();
   if(el){
    if(dcHashBackfillAbort) el.textContent='Indexado detenido. Actualizados en esta sesión: '+totalUpdated.toLocaleString('es-MX')+' · pendientes: '+(status?status.pending:'?');
    else if(status && Number(status.pending)===0) el.textContent='Indexado terminado. Total con hash: '+(Number(status.indexed)||0).toLocaleString('es-MX')+' · actualizados en esta sesión: '+totalUpdated.toLocaleString('es-MX');
    else el.textContent='Indexado interrumpido o con omisiones. Actualizados: '+totalUpdated+' · omitidos: '+totalSkipped+' · pendientes: '+(status?status.pending:'?');
   }
  }catch(e){
   if(el)el.textContent='Error al indexar: '+e.message;
  }finally{
   if(btn)btn.disabled=false;
   if(stop)stop.style.display='none';
   dcHashBackfillAbort=false;
  }
 });
  document.getElementById('dcHardlinksForm').onsubmit=async e=>{
   e.preventDefault();const form=e.target;
   const data={enabled:form.elements.enabled.checked?'1':'0',min_mib:v.min_mib||100,groups:Array.from(form.querySelectorAll('[name=group]:checked'),el=>el.value).join(',')};
   for(const [id] of groups)data['min_'+id]=form.elements['min_'+id].value;
   try{await request('global_hardlinks_save',data);dcOpenGlobalHardlinks();}catch(ex){alert(ex.message);}
  };
 }catch(ex){alert('No se pudo abrir la configuración: '+ex.message);}
};
window.dcOpenMediaAdmin=async function(){
 try{
  const v=await request('media_config_get');
  const content=`<p>Configuración global de multimedia: controla las miniaturas y la conversión automática posterior a la subida. La conversión local de compatibilidad utiliza este mismo límite global sincronizado. El permiso para convertir sigue siendo individual por usuario.</p><form id="dcMediaForm" class="dc-admin-editor dc-admin-compact-form"><section class="dc-admin-card dc-user-permissions-card"><div class="dc-check-list">
  ${checkRow(`<input type="checkbox" name="thumbnails_enabled" ${v.thumbnails_enabled?'checked':''}>`,'Generar miniaturas de video con FFmpeg','Crea miniaturas y apoyos visuales sin modificar el archivo original.')}
  ${checkRow(`<input type="checkbox" name="conversion_enabled" ${v.conversion_enabled?'checked':''}>`,'Permitir conversión de videos incompatibles','Conserva el video original y genera una versión compatible adicional.')}
  ${checkRow(`<input type="checkbox" name="auto_conversion" ${v.auto_conversion?'checked':''}>`,'Detectar incompatibilidad y agregar a la cola automáticamente después de la subida','Envía tareas al worker cuando detecta un video incompatible.')}
  </div></section><section class="dc-admin-card"><label class="dc-admin-stack-field">Máximo global por video (MiB)<input type="number" name="max_mib" min="1" max="10240" required value="${Number(v.max_mib)||500}"></label><p class="dc-admin-note">Límite global de tareas multimedia y de conversión local. Las excepciones individuales de tamaño aún no están disponibles.</p></section><p><small>Un proceso FFmpeg a la vez; el worker requiere cron. Las tareas que excedan el límite no se admiten. No altera el original.</small></p>${actionButtons('Guardar multimedia','dcOpenUsers()')}</form>`;
  adminUtility('🎞️ Multimedia y conversión local',content);
  document.getElementById('dcMediaForm').onsubmit=async e=>{e.preventDefault();const f=e.target;try{await request('media_config_save',{thumbnails_enabled:f.elements.thumbnails_enabled.checked?'1':'0',conversion_enabled:f.elements.conversion_enabled.checked?'1':'0',auto_conversion:f.elements.auto_conversion.checked?'1':'0',max_mib:f.elements.max_mib.value});dcOpenMediaAdmin();}catch(err){alert(err.message)}};
 }catch(e){alert('Multimedia: '+e.message)}
};
window.dcOpenUsers=async function(){
 try {
 const [data,capacity]=await Promise.all([request('list_users'),request('storage_capacity')]);
 window.dcStorageCapacity=capacity.total;
 const rows=data.users.map(u=>`<tr><td>${escapeHtml(u.username)}</td><td>${escapeHtml(u.role)}</td><td>${escapeHtml(u.status)}</td><td class="dc-admin-actions-inline"><button type="button" data-edit="${u.id}" title="Editar cuenta y permisos">Editar</button><button type="button" data-reset="${u.id}" title="Generar nueva contraseña temporal">Restablecer</button><button type="button" data-delete="${u.id}" title="Eliminar la cuenta" style="color:#c43e40">Eliminar</button></td></tr>`).join('');
 adminUtility('Administración de usuarios',`<p>Gestiona usuarios, permisos y transferencia de recursos. La eliminación es irreversible cuando eliges borrar archivos.</p><div class="dc-admin-toolbar"><button type="button" onclick="dcShowStorageUsers()" title="Muestra uso de espacio por cuenta">📊 Consumo por usuario</button><button type="button" onclick="dcOpenMediaAdmin()" title="Configura multimedia, FFmpeg y conversión local">🎞️ Multimedia y conversión local</button></div><form id="dcNewUser" class="dc-admin-inline-form"><label class="dc-admin-stack-field" style="margin:0">Nuevo usuario<input name="username" required pattern="[A-Za-z0-9_.-]{3,64}"></label><button type="submit">Crear</button></form><div class="dc-table-wrap"><table class="dc-admin-table"><thead><tr><th>Usuario</th><th>Rol</th><th>Estado</th><th>Acciones</th></tr></thead><tbody>${rows}</tbody></table></div><div id="dcUserEditor"></div>`);
 document.getElementById('dcNewUser').onsubmit=async e=>{e.preventDefault();try{const x=await request('create_user',{username:e.target.username.value});alert('Contraseña temporal (se muestra una sola vez): '+x.temporary_password);dcOpenUsers();}catch(ex){alert(ex.message);}};
 const byId=new Map(data.users.map(x=>[String(x.id),x]));
 document.querySelectorAll('[data-edit]').forEach(el=>el.onclick=()=>editUser(byId.get(el.dataset.edit),capacity.total));
 document.querySelectorAll('[data-delete]').forEach(el=>el.onclick=()=>deleteUserDialog(byId.get(el.dataset.delete)));
 document.querySelectorAll('[data-reset]').forEach(el=>el.onclick=async()=>{if(!confirm('¿Restablecer la contraseña e invalidar las sesiones?'))return;try{const x=await request('reset_password',{id:el.dataset.reset});alert('Nueva contraseña temporal (una sola vez): '+x.temporary_password);}catch(ex){alert(ex.message);}});
 }catch(ex){alert(ex.message);}
};
const DC_SIZE_UNITS=[['KB',1024],['MB',1048576],['GB',1073741824],['TB',1099511627776]];
function dcStorageSizeField(name,label,raw,total,help=''){
 const bytes=Number(raw)||0;
 const allowed=DC_SIZE_UNITS.filter(([,factor])=>factor<=total);
 if(!allowed.length||(bytes && bytes%1024!==0))allowed.unshift(['B',1]);
 let selected=allowed[0];
 if(bytes){for(const u of allowed)if(bytes>=u[1]&&bytes%u[1]===0)selected=u;}
 const shown=bytes?String(Number((bytes/selected[1]).toFixed(6))):'';
 const options=allowed.map(([unit,factor])=>`<option value="${factor}" ${factor===selected[1]?'selected':''}>${unit}</option>`).join('');
 return `<label class="dc-admin-stack-field">${fieldLabel(label,help)}<span style="display:flex;gap:8px;align-items:center;flex-wrap:nowrap"><input type="number" name="${name}_amount" min="0" step="any" inputmode="decimal" value="${shown}" placeholder="Sin límite" style="min-width:120px;flex:1"><select name="${name}_unit" aria-label="Unidad de ${label}" style="width:90px">${options}</select></span></label>`;
}
function dcStorageInputBytes(form,name,total){
 const amount=String(form.elements[name+'_amount'].value).trim();
 if(!amount)return '';
 const number=Number(amount);
 const factor=Number(form.elements[name+'_unit'].value);
 const value=number*factor;
 if(!Number.isFinite(number)||number<0||!Number.isSafeInteger(value))throw Error('Valor inválido para '+name+'. Usa cantidades que equivalgan a bytes enteros.');
 if(value>total)throw Error('El valor de '+name+' supera la capacidad del disco.');
 return String(value);
}
function editUser(u,total){
 const editor=document.getElementById('dcUserEditor');
 editor.innerHTML=`<form id="dcEditUser" class="dc-admin-editor dc-admin-compact-form"><div class="dc-admin-sections"><section class="dc-admin-card dc-user-core-card"><div class="dc-admin-two-col"><label class="dc-admin-stack-field">${fieldLabel('Nombre','Nombre visible de la cuenta; debe ser único.')}<input name="username" value="${escapeHtml(u.username)}" pattern="[A-Za-z0-9_.-]{3,64}" required></label><label class="dc-admin-stack-field">${fieldLabel('Rol','Administrador: acceso total. Usuario: sujeto a permisos específicos.')}<select name="role"><option value="user" ${u.role==='user'?'selected':''}>Usuario</option><option value="admin" ${u.role==='admin'?'selected':''}>Administrador</option></select></label><label class="dc-admin-stack-field">${fieldLabel('Estado','Suspendido impide iniciar sesión hasta reactivarlo.')}<select name="status"><option value="active" ${u.status==='active'?'selected':''}>Activo</option><option value="suspended" ${u.status==='suspended'?'selected':''}>Suspendido</option></select></label></div></section><section class="dc-admin-card dc-user-core-card"><div class="dc-admin-two-col">${dcStorageSizeField('quota_bytes','Cuota de almacenamiento',u.quota_bytes,total,'Límite lógico de la cuenta. Déjalo vacío para no aplicar límite.')}${dcStorageSizeField('max_file_bytes','Tamaño máximo por archivo',u.max_file_bytes,total,'Bloquea subidas mayores para esta cuenta. Déjalo vacío para no aplicar límite.')}${dcStorageSizeField('receive_admin_limit_bytes','Máximo de recepción pública',u.receive_admin_limit_bytes,total,'Límite específico para recepción pública por usuario. Déjalo vacío para heredar el valor global.')}</div><p class="dc-admin-note">Capacidad total del volumen: ${formatStorageCapacity(total)}. Las unidades que exceden su capacidad están ocultas.</p><p class="dc-admin-note">Los límites de archivos y multimedia son independientes. El límite individual de recodificación aún no está disponible.</p></section></div><section class="dc-admin-card dc-user-permissions-card"><div class="dc-check-list">${checkRow(`<input type="checkbox" name="can_share" ${Number(u.can_share)?'checked':''}>`,'Permitir compartir y recibir enlaces de Drive-Craft')}${checkRow(`<input type="checkbox" name="can_upload" ${Number(u.can_upload)?'checked':''}>`,'Permitir subir')}${checkRow(`<input type="checkbox" name="can_delete" ${Number(u.can_delete)?'checked':''}>`,'Permitir eliminar')}${checkRow(`<input type="checkbox" name="can_convert_video" ${Number(u.can_convert_video)?'checked':''}>`,'Permitir convertir videos','Permite generar una versión compatible desde la biblioteca de videos.')}</div></section>${actionButtons('Guardar ajustes','dcOpenUsers()')}</form>`;
 document.getElementById('dcEditUser').onsubmit=async e=>{
 e.preventDefault();const f=new FormData(e.target);const v=Object.fromEntries(f);v.id=u.id;
 try {
 v.quota_bytes=dcStorageInputBytes(e.target,'quota_bytes',total);
 v.max_file_bytes=dcStorageInputBytes(e.target,'max_file_bytes',total);
 v.receive_admin_limit_bytes=dcStorageInputBytes(e.target,'receive_admin_limit_bytes',total);
 for(const p of ['can_share','can_upload','can_delete','can_convert_video'])v[p]=f.has(p)?'1':'0';
 await request('save_user',v);dcOpenUsers();
 }catch(ex){alert(ex.message);}
 };
}
function formatStorageCapacity(n){
 let value=Number(n);let unit='B';
 for(const next of ['KB','MB','GB','TB']){if(value<1024)break;value/=1024;unit=next;}
 return `${value.toFixed(2)} ${unit}`;
}
function deleteUserDialog(u){
 const editor=document.getElementById('dcUserEditor');
 editor.innerHTML=`<section class="dc-admin-card"><h4>Eliminar usuario: ${escapeHtml(u.username)}</h4>
 <p>El administrador que realiza esta operación recibirá los recursos conservados.</p>
 <form class="dc-admin-editor dc-admin-compact-form" id="dcDeleteUserForm">
 <label class="dc-admin-stack-field">Archivos y carpetas <select id="dcDeletionFiles"><option value="transfer">Conservar y transferir a mi cuenta</option><option value="delete">Eliminar permanentemente</option></select></label>
 <label class="dc-admin-stack-field">Enlaces compartidos y de recepción <select id="dcDeletionLinks"><option value="transfer">Conservar y transferir a mi cuenta</option><option value="revoke">Revocar todos los enlaces</option></select></label>
 <p id="dcDeletionNote">Los archivos transferidos se encontrarán en una nueva carpeta raíz con el nombre del usuario eliminado.</p>
 <label class="dc-admin-stack-field">Escribe <strong>${escapeHtml('ELIMINAR '+u.username)}</strong> para confirmar<input type="text" id="dcDeleteConfirmation" autocomplete="off"></label>
 <div class="dc-admin-actions"><button type="button" id="dcConfirmDeletion" style="background:#aa353c;color:white">Eliminar usuario definitivamente</button><button type="button" onclick="dcOpenUsers()">Cancelar</button></div></form></section>`;
 const files=document.getElementById('dcDeletionFiles');
 const links=document.getElementById('dcDeletionLinks');
 const sync=()=>{if(files.value==='delete'){links.value='revoke';links.disabled=true;}else links.disabled=false;};
 files.addEventListener('change',sync);sync();
 document.getElementById('dcConfirmDeletion').onclick=async()=>{
   if(document.getElementById('dcDeleteConfirmation').value!=='ELIMINAR '+u.username){alert('Confirmación incorrecta');return;}
   if(!confirm('Se eliminará la cuenta '+u.username+'. La eliminación física, si la elegiste, es irreversible. ¿Continuar?'))return;
   const btn=document.getElementById('dcConfirmDeletion');btn.disabled=true;
   try {
     const result=await request('delete_user',{id:u.id,files_mode:files.value,links_mode:links.value,confirmation:'ELIMINAR '+u.username});
     alert('Cuenta eliminada. Recursos procesados: '+result.files_count+(result.destination_folder?' · Transferidos a '+result.destination_folder:'')+(result.cleanup_pending?' · ATENCIÓN: limpieza física pendiente, revisa logs':'') );
     dcOpenUsers();
   }catch(ex){alert(ex.message);btn.disabled=false;}
 };
}
})();
