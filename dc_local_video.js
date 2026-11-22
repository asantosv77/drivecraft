/* Indicadores transitorios por vista: nunca se guardan en servidor ni sessionStorage. */
const dcConversionRecent=new Set();
let dcConversionFolder=null;
setInterval(()=>{if(typeof currentParentId!=='undefined'&&dcConversionFolder!==null&&String(currentParentId)!==dcConversionFolder)dcConversionView(currentParentId);},500);
function dcConversionView(folder){if(dcConversionFolder!==String(folder)){dcConversionRecent.clear();dcConversionFolder=String(folder);}}
function dcConversionLabel(item,folder){
 const c=item?.conversion;if(!c)return {label:'',title:''};
 const names={queued:'⏳ Conversión en cola',running:'⚙️ Convirtiendo a MP4',error:'⚠ Conversión fallida',paused:'⏸ Conversión pausada',cancelled:'✕ Conversión cancelada'};
 if(c.state!=='done')return {label:names[c.state]||'',title:c.message||''};
 const key=String(folder)+':'+String(item.video_id);
 const info=c.details||{};
 const fmt=p=>{if(!p||typeof p!=='object')return 'Datos no disponibles';const v=p.video||{},a=p.audio||{};
  return [p.container||'',v.codec||'',v.width&&v.height?`${v.width}×${v.height}`:'',a.codec?'audio '+a.codec:'',p.size?`${(p.size/1048576).toFixed(1)} MiB`:''].filter(Boolean).join(' · ')||'Datos no disponibles';};
 return {label:dcConversionRecent.has(key)?'✓ Conversión terminada':'Recodificado',title:
  ['Recodificación: '+(info.type||'Compatibilidad de video'), 'Origen: solicitada por el usuario',
   'Original: '+fmt(info.original),'Actual: '+fmt(info.result)].join('\n')};
}
/* Drive-Craft v20.10.26: videos locales en bibliotecas YouTube Links. */
async function dlRequest(action, params={}, write=false){const run=()=>dlRequestRawDcft(action,params,write);return window.DCSaveFeedback?window.DCSaveFeedback.observe(action,run):run();}
async function dlRequestRawDcft(action, params={}, write=false){
 const data=new URLSearchParams({action,...params});if(write)data.set('csrf',window.DC_AUTH?.csrf||'');
 const r=await fetch('dc_local_video.php'+(write?'':'?'+data.toString()),write?{method:'POST',body:data,credentials:'same-origin',cache:'no-store'}:{credentials:'same-origin',cache:'no-store'});
 const j=await r.json();if(!r.ok||j.error)throw Error(j.error||'Error de video local');return j;
}
async function dlPaint(folder){
 dcConversionView(folder);
 const grid=document.getElementById('dcyGrid');if(!grid||Number(currentParentId)!==Number(folder))return;
 let data;try{data=await dlRequest('list',{folder_id:String(folder)});}catch(e){console.warn(e);return;}
 if(Number(currentParentId)!==Number(folder)||!document.getElementById('dcyGrid'))return;
 for(const v of data.items||[]){
  const card=document.createElement('article');card.className='dcy-card dc-local-video-card';
  const poster=document.createElement('div');poster.className='dc-local-poster skel';
  const img=document.createElement('img');img.alt='Miniatura de video local';img.loading='lazy';
  const posterUrl='dc_local_video.php?action=asset&kind=webp&id='+v.video_id;
  img.onload=()=>poster.classList.remove('skel');
  img.onerror=()=>{poster.classList.remove('skel');img.style.visibility='hidden';card.classList.add('dc-local-no-thumb');};
  img.src=DC_TRANSPARENT_PIXEL;
  poster.appendChild(img);
  const clip=document.createElement('video');clip.className='dc-local-hover';clip.muted=!data.preview_audio;clip.loop=true;clip.playsInline=true;clip.preload='none';
  clip.src='dc_local_video.php?action=asset&kind='+encodeURIComponent(v.preview_format||'mp4')+'&id='+v.video_id;
  card.dataset.dcVideoId=String(v.video_id);card.dataset.dcPreviewState=v.preview_state||'';card.dataset.dcConversionState=v.conversion?.state||'';
  card.addEventListener('mouseenter',()=>{if(card.dataset.dcPreviewState!=='done')return;clip.currentTime=0;clip.play().catch(()=>{if(card.matches(':hover')){clip.muted=true;clip.play().catch(()=>{});}});});
  card.addEventListener('mouseleave',()=>{clip.pause();clip.currentTime=0;});
  const name=document.createElement('div');name.className='dcy-title';name.textContent=v.title||v.video_name;
  const description=document.createElement('div');description.className='dcy-description';description.textContent=v.description||'Video almacenado en Drive-Craft';
  const badge=document.createElement('span');badge.className='dc-local-badge';badge.textContent='Video local';
  const states={queued:'⏳ Vista previa pendiente',running:'⚙️ Procesando vista previa',done:'',error:'⚠ Error de vista previa',paused:'⏸ Paquete en Papelera'};
  const state=document.createElement('div');state.className='dc-local-preview-state';state.textContent=states[v.preview_state]??'Vista previa pendiente';
  state.style.cssText='font-size:11px;opacity:.84;padding:3px 8px;';

  const play=document.createElement('button');play.type='button';play.className='dcy-play';play.textContent='▶';play.title='Reproducir archivo';play.onclick=()=>{if(typeof viewFile==='function')viewFile(v.video_id,v.mime_type||'video/mp4');};
  const dots=document.createElement('button');dots.className='dcy-dots';dots.type='button';dots.textContent='⋮';
  const menu=document.createElement('div');menu.className='dcy-menu';menu.hidden=true;
  const open=document.createElement('button');open.textContent='📁 Abrir paquete';open.onclick=()=>dcyOpenPackage(v.package_id);
  const download=document.createElement('button');download.textContent='⬇️ Descargar video';download.onclick=()=>location.assign('api.php?action=download&id='+v.video_id+'&download=1');
  const trash=document.createElement('button');trash.textContent='🗑️ Enviar a papelera';
  trash.onclick=async e=>{e.stopPropagation();menu.hidden=true;card.classList.remove('dc-local-menu-open');
   if(!confirm('¿Enviar a la Papelera el paquete completo «'+(v.title||v.video_name)+'» y sus adjuntos?'))return;
   try{await dlRequest('trash',{package_id:String(v.package_id),folder_id:String(folder)},true);await loadFiles();await dcyList(folder,1);}catch(err){alert(err.message);}
  };
  const regenerate=document.createElement('button');regenerate.type='button';regenerate.textContent='🔄 Regenerar vista previa';
  regenerate.onclick=async e=>{e.stopPropagation();menu.hidden=true;card.classList.remove('dc-local-menu-open');
   if(!confirm('¿Volver a generar la miniatura y el video de vista previa?'))return;
   regenerate.disabled=true;
   try{await dlRequest('regenerate',{folder_id:String(folder),package_id:String(v.package_id),video_id:String(v.video_id)},true);await dcyList(folder,1);}catch(err){alert(err.message);regenerate.disabled=false;}
  };
  const edit=document.createElement('button');edit.type='button';edit.textContent='✏️ Editar título y descripción';
  edit.onclick=async e=>{e.stopPropagation();menu.hidden=true;const title=prompt('Título:',v.title||v.video_name||'');if(title===null)return;
   const description=prompt('Descripción:',v.description||'');if(description===null)return;
   try{await dlRequest('edit',{folder_id:String(folder),package_id:String(v.package_id),video_id:String(v.video_id),title,description},true);await dcyList(folder,1);}catch(err){alert(err.message);}
  };
  const attach=document.createElement('button');attach.type='button';attach.textContent='📎 Agregar archivos';
  attach.onclick=e=>{e.stopPropagation();menu.hidden=true;const input=document.createElement('input');input.type='file';input.multiple=true;input.hidden=true;document.body.append(input);
   input.onchange=async()=>{const files=Array.from(input.files||[]);input.remove();let completed=0;
    for(const file of files){const form=new FormData();form.append('action','attach');form.append('csrf',window.DC_AUTH?.csrf||'');form.append('folder_id',String(folder));form.append('package_id',String(v.package_id));form.append('video_id',String(v.video_id));form.append('attachment',file);
     try{const response=await fetch('dc_local_video.php',{method:'POST',credentials:'same-origin',body:form});const json=await response.json();if(!response.ok||json.error)throw Error(json.error||'Error al adjuntar');completed++;}
     catch(err){alert('Se cargaron '+completed+' de '+files.length+' adjuntos. Falló «'+file.name+'»: '+err.message);break;}
    }await dcyList(folder,1);
   };input.click();
  };
  const convert=document.createElement('button');convert.type='button';convert.textContent='🎞️ Convertir a formato compatible';
  const busy=v.conversion&&['queued','running'].includes(v.conversion.state);convert.disabled=!!busy;
  convert.onclick=async e=>{e.stopPropagation();menu.hidden=true;
   if(!confirm('¿Convertir este video a MP4 H.264/AAC? Se conservará el original hasta validar la salida. Otros hardlinks no se modificarán.'))return;
   try{await dlRequest('convert_compatible',{folder_id:String(folder),package_id:String(v.package_id),video_id:String(v.video_id)},true);dcConversionRecent.add(String(folder)+':'+String(v.video_id));await dcyList(folder,1);}catch(err){alert(err.message);}
  };
  const statusConvert=document.createElement('div');statusConvert.className='dc-local-conversion-state';
  const convertNames={queued:'⏳ Conversión en cola',running:'⚙️ Convirtiendo a MP4',done:'✓ Conversión terminada',error:'⚠ Conversión fallida',paused:'⏸ Conversión pausada',cancelled:'✕ Conversión cancelada'};
  const conversionDisplay=dcConversionLabel(v,folder);statusConvert.textContent=conversionDisplay.label;statusConvert.title=conversionDisplay.title;
  menu.append(open,download,edit,attach,regenerate,convert,trash);

  dots.onclick=e=>{e.stopPropagation();const next=menu.hidden;document.querySelectorAll('.dc-local-video-card.dc-local-menu-open').forEach(n=>n.classList.remove('dc-local-menu-open'));document.querySelectorAll('.dcy-menu').forEach(n=>n.hidden=true);menu.hidden=!next;card.classList.toggle('dc-local-menu-open',next);};
  const attachmentLabel=dc31AttachmentUI(v.attachments,async f=>{await dlRequest('delete_attachment',{folder_id:String(folder),package_id:String(v.package_id),video_id:String(v.video_id),attachment_id:String(f.id)},true);await dcyList(folder,1);});
  card.append(poster,clip,badge,play,dots,menu,name,description,attachmentLabel,state,statusConvert);grid.append(card);
  if(typeof dcLoadImageSoon==='function')dcLoadImageSoon(img,posterUrl);else img.src=posterUrl;
  const ordinary=document.getElementById('file-item-'+v.package_id);if(ordinary){ordinary.dataset.dcyPackageHidden=ordinary.style.display||' ';ordinary.style.display='none';}
 }
 const unpacked=data.unpacked||[];
 if(unpacked.length){
  const note=document.createElement('div');note.className='dc-local-note';note.textContent='Videos locales sin empaquetar';grid.append(note);
  for(const v of unpacked){
   const card=document.createElement('article');card.className='dcy-card dc-local-video-card';
   const title=document.createElement('div');title.className='dcy-title';title.textContent=v.name;
   const button=document.createElement('button');button.className='dc-local-package-btn';button.textContent='📦 Convertir en tarjeta de video';button.onclick=async()=>{
    const name=prompt('Título (opcional)',v.name);if(name===null)return;const description=prompt('Descripción (opcional)','');if(description===null)return;
    button.disabled=true;try{await dlRequest('convert',{folder_id:String(folder),file_id:String(v.id),title:name,description},true);await loadFiles();await dcyList(folder,1);}catch(err){alert(err.message);button.disabled=false;}
   };
   // Mantener TODAS las operaciones del explorador y sus permisos de origen.
   // La tarjeta normal permanece en el DOM (oculta), y su menu se replica sin inventar acciones.
   const ordinary=document.getElementById('file-item-'+v.id);
   const nativeMenu=ordinary?.querySelector('.context-menu-drive');
   const dots=document.createElement('button');dots.type='button';dots.className='dcy-dots';dots.textContent='⋮';dots.title='Acciones del archivo';
   const menu=document.createElement('div');menu.className='dcy-menu';menu.hidden=true;
   if(nativeMenu){
    for(const entry of nativeMenu.children){
     const copy=entry.cloneNode(true);
     if(copy.matches('a')){
      // Igualar el menú de YouTube / video local: mismos estilos en enlaces clonados.
      copy.style.cssText+=';display:block;width:100%;box-sizing:border-box;text-align:left;padding:9px;font:inherit;font-family:inherit;font-size:13px;font-weight:400;line-height:normal;color:inherit;text-decoration:none;border:0;background:transparent;border-radius:5px;white-space:normal;cursor:pointer';
      copy.onmouseenter=()=>copy.style.background='#ffffff25';
      copy.onmouseleave=()=>copy.style.background='transparent';
      // Cambiar solo la etiqueta; conservar iconos y la acción original de papelera.
      if(/\bEliminar\b/i.test(DCI18n.original(copy))){
       const rename=n=>{if(n.nodeType===3)n.textContent=n.textContent.replace(/\bEliminar\b/gi,'Enviar a papelera');else for(const child of n.childNodes)rename(child);};
       rename(copy);
      }
      // Los atributos onclick clonados no son una integración fiable con el explorador.
      // Para Papelera, invocar expresamente la operación nativa con el ID real.
      if(/\b(?:Eliminar|Enviar a papelera)\b/i.test(DCI18n.original(copy))){
       copy.removeAttribute('onclick');
       copy.onclick=e=>{
        e.preventDefault();e.stopPropagation();
        menu.hidden=true;card.classList.remove('dc-local-menu-open');
        if(typeof actionBatch==='function')actionBatch('delete',[Number(v.id)]);
        else alert('La acción de Papelera no está disponible. Recarga Drive-Craft.');
        return false;
       };
      }
      copy.addEventListener('click',()=>{menu.hidden=true;card.classList.remove('dc-local-menu-open');});
     }
     menu.append(copy);
    }
   }else{
    // Si falta el menú original, no inventar permisos: conservar solo la conversión.
    dots.title='Menú del archivo no disponible; recarga el explorador';
    dots.disabled=true;
   }
   dots.onclick=e=>{e.stopPropagation();const show=menu.hidden;
    document.querySelectorAll('.dcy-menu').forEach(m=>m.hidden=true);
    document.querySelectorAll('.dc-local-menu-open').forEach(n=>n.classList.remove('dc-local-menu-open'));
    menu.hidden=!show;card.classList.toggle('dc-local-menu-open',show);
   };
   card.append(title,button,dots,menu);grid.append(card);
   if(ordinary){ordinary.dataset.dcyPackageHidden=ordinary.style.display||' ';ordinary.style.display='none';}
  }
 }
}
async function dlSettingsLoad(){const controls=document.getElementById('dcLocalPreviewSettings');if(!controls)return;
 try{const r=await dlRequest('settings');controls.querySelector('#dcLocalPreviewSeconds').value=r.config.seconds;controls.querySelector('#dcLocalPreviewAudio').checked=r.config.audio;}catch(e){console.warn(e);}
}

/* Refrescar estados de previews al mantener abierta la biblioteca. */
setInterval(()=>{
 try{
  const grid=document.getElementById('dcyGrid');
  if(!grid||!grid.querySelector('.dc-local-preview-state'))return;
  const folder=Number(currentParentId);dcConversionView(folder);
  if(!Number.isInteger(folder)||folder<=0||document.hidden)return;
  dlRequest('list',{folder_id:String(folder)}).then(r=>{
   if(Number(currentParentId)!==folder||!grid.isConnected)return;
   const cards=[...grid.querySelectorAll('.dc-local-video-card')];
   const states={queued:'⏳ Vista previa pendiente',running:'⚙️ Procesando vista previa',done:'',error:'⚠ Error de vista previa',paused:'⏸ Paquete en Papelera'};
   for(const item of r.items||[]){
    const card=cards.find(c=>c.dataset.dcVideoId===String(item.video_id));
    if(!card)continue;
    const convert=card.querySelector('.dc-local-conversion-state');
    const convertNames={queued:'⏳ Conversión en cola',running:'⚙️ Convirtiendo a MP4',done:'✓ Conversión terminada',error:'⚠ Conversión fallida',paused:'⏸ Conversión pausada'};
    const conv=item.conversion?.state||'';
    if(convert){const view=dcConversionLabel(item,folder);convert.textContent=view.label;convert.title=view.title;}
    if(card.dataset.dcConversionState!==conv){
     const old=card.dataset.dcConversionState;card.dataset.dcConversionState=conv;
     if(old&&['done','error'].includes(conv)&&old!==conv){dcyList(folder,dcyPage).catch(()=>{});return;}
    }
    const state=card.querySelector('.dc-local-preview-state');
    if(state)state.textContent=states[item.preview_state]??'Vista previa pendiente';
    if(card.dataset.dcPreviewState!==item.preview_state){
     card.dataset.dcPreviewStateBefore=card.dataset.dcPreviewState;card.dataset.dcPreviewState=item.preview_state||'';
     if(item.preview_state==='done'){
      if(card.dataset.dcPreviewStateBefore==='running'||card.dataset.dcPreviewStateBefore==='queued'){
       const toast=document.createElement('div');toast.textContent='✓ Vista previa generada: '+(item.title||item.video_name||'video');
       toast.setAttribute('role','status');toast.style.cssText='position:fixed;right:16px;bottom:18px;z-index:99999;background:#23364b;color:white;padding:11px 16px;border-radius:9px;box-shadow:0 6px 20px #0005;max-width:min(85vw,420px)';
       document.body.appendChild(toast);setTimeout(()=>toast.remove(),4500);
      }
      const img=card.querySelector('img');if(img){const poster=img.parentElement;if(poster&&poster.classList.contains('dc-local-poster'))poster.classList.add('skel');img.style.visibility='visible';const freshUrl='dc_local_video.php?action=asset&kind=webp&id='+item.video_id+'&v='+Date.now();if(typeof dcLoadImageSoon==='function')dcLoadImageSoon(img,freshUrl);else img.src=freshUrl;}
      const clip=card.querySelector('video');if(clip)clip.src='dc_local_video.php?action=asset&kind='+encodeURIComponent(item.preview_format||'mp4')+'&id='+item.video_id+'&v='+Date.now();
     }
    }
   }
  }).catch(()=>{});
 }catch(e){}
},15000);

// Opening another card/collection menu releases the previous local menu layer.
document.addEventListener('click',event=>{
 if(event.target.closest('.dcy-dots,.dcy-menu'))return;
 document.querySelectorAll('.dcy-menu').forEach(menu=>menu.hidden=true);
 document.querySelectorAll('.dc-local-menu-open').forEach(card=>card.classList.remove('dc-local-menu-open'));
},true);
