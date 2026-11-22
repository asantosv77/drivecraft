/* Drive-Craft 20.10.15 — acceso interno revocable. */
async function dciRequest(action,values={}){
 const data=new FormData();data.set('action',action);data.set('csrf',window.DC_AUTH?.csrf||'');
 for(const [k,v] of Object.entries(values))data.set(k,String(v));
 const r=await fetch('dc_internal_shares.php',{method:'POST',body:data,credentials:'same-origin',cache:'no-store'});
 const obj=await r.json();if(!r.ok||!obj.success)throw new Error(obj.error||'No se pudo completar la solicitud');return obj;
}
async function dciManage(fileId){
 dcUtility('👥 Compartir con usuario de Drive-Craft',`<p>Concede acceso de lectura y descarga a otro usuario. Puedes revocarlo en cualquier momento. No se crea una copia.</p>
 <label>Nombre de usuario <input id="dciUsername" type="text" maxlength="100" autocomplete="off" placeholder="Usuario exacto" style="width:100%"></label>
 <p><button type="button" id="dciGrant">Compartir archivo</button></p><div id="dciStatus" role="status"></div>
 <h4>Personas con acceso</h4><div id="dciGranted">Cargando…</div>`);
 const root=document.getElementById('dcUtilityBody');
 const stat=root.querySelector('#dciStatus'), listing=root.querySelector('#dciGranted');
 async function load(){try{const x=await dciRequest('granted',{file_id:fileId});listing.replaceChildren();
 if(!x.items.length){listing.textContent='Este archivo no está compartido internamente.';return;}
 for(const item of x.items){const row=document.createElement('div');row.style.cssText='display:flex;align-items:center;gap:12px;margin:8px 0';
 const label=document.createElement('span');label.textContent=item.username;label.style.flex='1';
 const btn=document.createElement('button');btn.type='button';btn.textContent='Revocar acceso';
 btn.onclick=async()=>{if(!confirm('¿Revocar acceso a '+item.username+'?'))return;try{await dciRequest('revoke',{id:item.id});await load();}catch(e){stat.textContent=e.message;}};
 row.append(label,btn);listing.append(row);}
 }catch(e){listing.textContent=e.message;}}
 root.querySelector('#dciGrant').onclick=async()=>{const btn=root.querySelector('#dciGrant');btn.disabled=true;
 try{await dciRequest('grant',{file_id:fileId,username:root.querySelector('#dciUsername').value});stat.textContent='Acceso concedido.';root.querySelector('#dciUsername').value='';await load();}
 catch(e){stat.textContent=e.message;}finally{btn.disabled=false;}};
 await load();
}
async function dciReceived(){
 dcUtility('👥 Compartido conmigo','<p>Archivos compartidos por otros usuarios. El propietario conserva el control del acceso.</p><div id="dciReceivedItems">Cargando…</div>');
 const node=document.getElementById('dcUtilityBody').querySelector('#dciReceivedItems');
 try{const x=await dciRequest('received');node.replaceChildren();if(!x.items.length){node.textContent='Todavía no tienes archivos compartidos.';return;}
 for(const f of x.items){const row=document.createElement('div');row.style.cssText='padding:12px 4px;border-bottom:1px solid var(--border-color);display:flex;align-items:center;gap:10px;flex-wrap:wrap';
 const detail=document.createElement('div');detail.style.flex='1';detail.style.minWidth='200px';
 const title=document.createElement('div');title.textContent=f.name;title.style.fontWeight='600';title.style.overflowWrap='anywhere';
 const sub=document.createElement('small');sub.textContent='Compartido por '+f.owner+' · '+(typeof formatBytes==='function'?formatBytes(f.size):f.size+' bytes');
 const a=document.createElement('a');a.textContent='Descargar';a.className='btn-primary';a.href='dc_internal_shares.php?action=download&id='+encodeURIComponent(f.id);
 detail.append(title,sub);const preview=document.createElement('button');preview.type='button';preview.textContent='👁 Ver';preview.onclick=()=>dciPreviewShared61(f.id,f.name,f.mime_type);const pin=document.createElement('button');pin.type='button';pin.textContent='📌 Agregar a carpeta actual';pin.onclick=async()=>{pin.disabled=true;try{await dciRequest('pin',{share_id:f.id,parent_id:currentParentId});pin.textContent='Agregado ✓';if(!isSearching)await loadFiles();}catch(e){alert(e.message);pin.disabled=false;}};row.append(detail,preview,a,pin);node.append(row);}
 }catch(e){node.textContent='No se pudo cargar: '+e.message;}
}

/* v20.10.16: referencias virtuales; no se insertan en la tabla files. */
async function dciShowShortcuts(parentId){
 const grid=document.getElementById('fileGrid');
 if(!grid||Number(currentParentId)!==Number(parentId)||isSearching)return;
 try{
  const {items}=await dciRequest('shortcuts',{parent_id:parentId});
  if(Number(currentParentId)!==Number(parentId)||isSearching)return;
  grid.querySelectorAll('[data-dci-shortcut]').forEach(e=>e.remove());
  for(const f of items){
   const key='shortcut-'+String(f.id);
   const card=document.createElement('div');card.className='file-item dc-shortcut-item';
   card.id='file-item-'+key;card.dataset.dciShortcut=String(f.id);
   const fileIcon=document.createElement('div');fileIcon.className='file-icon';
   fileIcon.innerHTML=typeof getIconForMime==='function'?getIconForMime(f.mime_type||'',f.name||''):'📄';
   const visual=document.createElement('div');visual.className='dc-shortcut-visual';
   const badge=document.createElement('span');badge.className='dc-shortcut-link-badge';badge.textContent='🔗';
   badge.title='Acceso directo compartido';visual.append(fileIcon,badge);
   const name=document.createElement('div');name.className='file-name';name.textContent=f.name;name.title=f.name;
   const meta=document.createElement('div');meta.className='file-meta';
   meta.textContent=typeof dcCompactFileType==='function'?dcCompactFileType({is_dir:0,name:f.name,mime_type:f.mime_type}):(f.mime_type||'Archivo');
   const size=document.createElement('div');size.className='file-size';size.textContent=typeof formatBytes==='function'?formatBytes(Number(f.size)||0):String(f.size||0)+' bytes';
   const date=document.createElement('div');date.className='file-date';date.textContent='Acceso compartido';
   const downloadUrl='dc_internal_shares.php?action=download&id='+encodeURIComponent(f.share_id);
   const download=()=>{window.location.assign(downloadUrl);};
   card.ondblclick=(event)=>{event.stopPropagation();dciPreviewShared61(f.share_id,f.name,f.mime_type);};
   const dots=document.createElement('button');dots.type='button';dots.className='menu-dots';dots.textContent='⋮';
   dots.onclick=e=>{e.stopPropagation();openFileMenu(e,key);};
   const menu=document.createElement('div');menu.id='fileMenu-'+key;menu.className='context-menu-drive';
   const action=(caption,run)=>{const a=document.createElement('a');a.href='#';a.textContent=caption;
    a.onclick=e=>{e.preventDefault();e.stopPropagation();menu.classList.remove('show');run();};menu.append(a);};
   action('👁 Ver archivo',()=>dciPreviewShared61(f.share_id,f.name,f.mime_type));
   action('⬇️ Descargar original',download);
   action('ℹ️ Propiedades',()=>{
    const content=document.createElement('div');
    for(const [label,value] of [
      ['Nombre',f.name],['Tipo','Acceso directo compartido'],['Compartido por',f.owner],
      ['Tipo de archivo',f.mime_type||'Desconocido'],['Tamaño',size.textContent],
      ['ID del recurso compartido',String(f.share_id)]
    ]){const row=document.createElement('p');const strong=document.createElement('strong');strong.textContent=label+': ';
      const span=document.createElement('span');span.textContent=String(value??'');row.append(strong,span);content.append(row);}
    dcUtility('ℹ️ Propiedades del acceso directo','<div id="dciShortcutProperties"></div>');
    const mount=document.getElementById('dciShortcutProperties');if(mount)mount.replaceWith(content);
   });
   action('🗑️ Quitar acceso directo',async()=>{
    if(!confirm('¿Quitar este acceso directo de tu carpeta? El archivo original no se eliminará.'))return;
    try{await dciRequest('unpin',{id:f.id});card.remove();}catch(e){alert(e.message);}
   });
   card.append(visual,name,meta,size,date,dots,menu);grid.append(card);
  }
 }catch(e){console.warn('Accesos directos:',e.message);}
}
/* Sólo avisos para nuevas comparticiones; nunca crear copias físicas. */
let dciNoticeBaseline=null;
async function dciCheckNotices(){
 if(!document.getElementById('fileGrid')||document.hidden)return;
 try{
  const {items}=await dciRequest('received');
  const ids=new Set(items.map(i=>String(i.id)));
  if(dciNoticeBaseline===null){
   try{dciNoticeBaseline=new Set(JSON.parse(sessionStorage.getItem('dci-notified-shares')||'[]'));}catch(_){dciNoticeBaseline=new Set();}
  }
  const fresh=items.filter(i=>!dciNoticeBaseline.has(String(i.id)));
  if(fresh.length){for(const file of fresh.slice(0,4))dciNotice(file);
   for(const item of items)dciNoticeBaseline.add(String(item.id));
   sessionStorage.setItem('dci-notified-shares',JSON.stringify([...dciNoticeBaseline].slice(-500)));
  }
 }catch(e){console.warn('Avisos de archivos compartidos:',e.message);}
}
function dciNotice(file){
 let stack=document.getElementById('dciNoticeStack');if(!stack){stack=document.createElement('div');stack.id='dciNoticeStack';stack.style.cssText='position:fixed;right:16px;top:72px;z-index:10050;display:grid;gap:8px;max-width:min(370px,calc(100vw - 32px))';document.body.append(stack);}
 const notice=document.createElement('div');notice.setAttribute('role','status');notice.style.cssText='padding:12px 16px;border:1px solid var(--border-color,#9996);border-radius:12px;background:color-mix(in srgb,var(--bg-color,#182132) 85%,transparent);backdrop-filter:blur(14px);box-shadow:0 8px 24px #0003;color:var(--text-color,inherit)';
 const heading=document.createElement('strong');heading.textContent='👥 Nuevo archivo compartido';
 const content=document.createElement('div');content.textContent=file.owner+' compartió '+file.name;
 const controls=document.createElement('div');controls.style.cssText='display:flex;gap:10px;margin-top:8px';
 const view=document.createElement('button');view.textContent='Ver archivo';view.onclick=()=>{notice.remove();dciReceived();};
 const close=document.createElement('button');close.textContent='Descartar';close.onclick=()=>notice.remove();
 controls.append(view,close);notice.append(heading,content,controls);stack.prepend(notice);
}
if(document.getElementById('fileGrid')){
 // Hook no invasivo: mantener carga normal y añadir los accesos tras renderizar.
 const dciOriginalLoadFiles=loadFiles;
 loadFiles=async function(...args){const requested=currentParentId;const result=await dciOriginalLoadFiles(...args);await dciShowShortcuts(requested);return result;};
 // Primera carga ocurre antes de este script; completar los accesos aquí.
 dciShowShortcuts(currentParentId);
 dciCheckNotices();setInterval(dciCheckNotices,30000);
}
/* v20.10.61: visor seguro de acceso interno, sin copia ni hardlink nuevo. */
async function dciPreviewShared61(shareId,name,mime){
 const ext=String(name||'').split('.').pop().toLowerCase();
 const texts=new Set('txt log php js mjs cjs ts tsx jsx yaml yml html htm java md markdown css json xml sql py sh bash zsh c h cpp hpp cs go rs rb pl ini conf cfg toml properties csv tsv'.split(' '));
 const pictures=new Set('jpg jpeg png gif webp avif bmp'.split(' '));
 const audios=new Set('mp3 wav ogg m4a aac flac'.split(' '));
 const videos=new Set('mp4 webm ogv mov'.split(' '));
 const url='dc_internal_shares.php?action=view&id='+encodeURIComponent(shareId);
 const downloadUrl='dc_internal_shares.php?action=download&id='+encodeURIComponent(shareId);
 const viewer=document.getElementById('viewerModal'),body=document.getElementById('viewerBody'),title=document.getElementById('viewerTitle');
 if(!viewer||!body||!title){window.location.assign(downloadUrl);return;}
 body.replaceChildren();title.textContent='Compartido conmigo · '+name;
 const toolbar=document.getElementById('viewerToolbar');if(toolbar)toolbar.style.display='none';
 const wrapper=document.createElement('div');wrapper.className='dci-preview61';
 const bar=document.createElement('div');bar.className='dci-preview61-bar';
 const info=document.createElement('span');info.textContent='Solo lectura · Acceso revocable';
 const get=document.createElement('a');get.href=downloadUrl;get.textContent='⬇ Descargar';get.className='dci-preview61-download';bar.append(info,get);wrapper.append(bar);
 const content=document.createElement('div');content.className='dci-preview61-content';wrapper.append(content);body.append(wrapper);viewer.style.display='block';
 const element=(tag,attrs={})=>{const el=document.createElement(tag);Object.assign(el,attrs);content.append(el);return el;};
 try{
  if(texts.has(ext)){
   const response=await fetch(url,{credentials:'same-origin',cache:'no-store'});
   if(!response.ok)throw Error('HTTP '+response.status+' (permiso revocado o archivo no disponible)');
   if(Number(response.headers.get('Content-Length')||0)>2097152)throw Error('El archivo supera 2 MiB; utiliza Descargar');
   const raw=await response.text();if(raw.length>2097152)throw Error('El archivo supera 2 MiB; utiliza Descargar');
   if(!viewer.isConnected||!wrapper.isConnected)return;
   if((ext==='md'||ext==='markdown')&&typeof window.dcMarkdown49Render==='function'){
    const toggle=document.createElement('button');toggle.type='button';let rendered=false;
    const pre=element('pre');pre.className='dci-preview61-code';pre.textContent=raw;
    toggle.onclick=()=>{rendered=!rendered;toggle.textContent=rendered?'Ver texto':'Vista Markdown';pre.className=rendered?'dc-markdown49':'dci-preview61-code';if(rendered)pre.innerHTML=window.dcMarkdown49Render(raw);else pre.textContent=raw;};
    toggle.textContent='Vista Markdown';content.prepend(toggle);
   }else{const pre=element('pre');pre.className='dci-preview61-code';pre.textContent=raw;}
  }else if(pictures.has(ext))element('img',{src:url,alt:name});
  else if(audios.has(ext))element('audio',{src:url,controls:true,autoplay:true});
  else if(videos.has(ext))element('video',{src:url,controls:true,autoplay:true,playsInline:true});
  else if(ext==='pdf'){const frame=element('iframe',{src:url,title:'Visor PDF'});frame.setAttribute('sandbox','allow-scripts');}
  else{const p=element('p');p.textContent='No hay visor integrado para este tipo de archivo. Puedes descargarlo.';}
 }catch(err){content.replaceChildren();const p=element('p');p.textContent='No se pudo abrir: '+err.message;}
}
