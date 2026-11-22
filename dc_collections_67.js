/* Drive-Craft 20.10.67: tarjetas de colección y progreso privado */
(function(){'use strict';
const api='dc_video_collections_65.php';const progress='dc_video_progress_67.php';
let active=null;let lastSaved=0;let generation=0;
async function get(url,params){const r=await fetch(url+'?'+new URLSearchParams(params),{credentials:'same-origin',cache:'no-store'});const j=await r.json();if(!r.ok||j.error)throw Error(j.error||'Error');return j;}
async function post(params){const r=await fetch(progress,{method:'POST',credentials:'same-origin',body:new URLSearchParams({csrf:window.DC_AUTH?.csrf||'',...params}),keepalive:true});const j=await r.json();if(!r.ok||j.error)throw Error(j.error||'Error');return j;}
function stop(e){e.stopPropagation();}
function mk(tag,cl,txt){const e=document.createElement(tag);if(cl)e.className=cl;if(txt!==undefined)e.textContent=txt;return e;}
function coverUrl(id,original=false){return api+'?action=cover&id='+encodeURIComponent(id)+(original?'':'&variant=thumb')+'&v='+Date.now()}
function coverScale(c){const n=Number(c.cover_scale??100);return Number.isFinite(n)&&n>0?n:100}
// El zoom siempre parte de la imagen COMPLETA (contain), nunca de un recorte previo.
// 100 % equivale al cover actual; alejar por debajo recupera contenido oculto.
function paintCover(frame,img,layout,x,y,zoom){
 if(!img.naturalWidth||!img.naturalHeight||!frame.clientWidth||!frame.clientHeight)return;
 const fw=frame.clientWidth,fh=frame.clientHeight,iw=img.naturalWidth,ih=img.naturalHeight;
 const fit=Math.min(fw/iw,fh/ih),cover=Math.max(fw/iw,fh/ih);
 const factor=(cover/fit)*(zoom/100);
 const visibleW=iw*fit*factor,visibleH=ih*fit*factor;
 const dx=(50-x)/50*Math.max(0,(visibleW-fw)/2);
 const dy=(50-y)/50*Math.max(0,(visibleH-fh)/2);
 img.style.objectFit='contain';img.style.objectPosition='center';
 img.style.transform=`translate(${dx.toFixed(2)}px,${dy.toFixed(2)}px) scale(${factor.toFixed(5)})`;
}
function applyPoster(poster,c){
 poster.dataset.layout=c.cover_layout||'16:9';let img=poster.querySelector('img');
 if(!c.has_cover){poster.replaceChildren();poster.textContent='🎬';return}
 if(!img){poster.textContent='';img=document.createElement('img');poster.append(img)}
 img.alt='Portada de '+c.title;
 const paint=()=>paintCover(poster,img,poster.dataset.layout,Number(c.cover_position_x??50),Number(c.cover_position_y??50),coverScale(c));
 img.onload=()=>requestAnimationFrame(paint);
 img.onerror=()=>{poster.textContent='⚠ No se pudo mostrar la portada';console.warn('Drive-Craft: error cargando portada de colección '+c.id)};
 img.src=coverUrl(c.id);
 if(img.complete&&img.naturalWidth)requestAnimationFrame(paint);
}

function format(n){n=Math.floor(Number(n)||0);return `${Math.floor(n/60)}:${String(n%60).padStart(2,'0')}`;}
async function loadProgress(c){try{const d=await get(progress,{action:'get',collection_id:c.id});return d.items||[]}catch(e){console.warn('Progreso:',e);return []}}
async function saveCurrent(force=false){if(!active)return;const v=document.querySelector('#viewerBody video, #dcFloat85 video');if(!v||Number(window.dcCurrentVideo64)!==Number(active.videoId))return;const now=Date.now();if(!force&&now-lastSaved<9000)return;lastSaved=now;const id=active.collectionId;const ep=active.videoId;const pos=Number(v.currentTime)||0;const dur=Number.isFinite(v.duration)?v.duration:0;
 try{await post({action:'save',collection_id:id,video_id:ep,position:String(pos),duration:String(dur)});window.dispatchEvent(new CustomEvent('dc-video-progress85',{detail:{collectionId:id,videoId:ep,position:pos,duration:dur,completed:dur>0&&pos/dur>=.95}}))}catch(e){console.warn('No se guardó el progreso:',e)}
}
function restart(){const video=document.querySelector('#viewerBody video, #dcFloat85 video');if(!video)return;video.currentTime=0;video.play().catch(()=>{});if(active){post({action:'reset',collection_id:active.collectionId,video_id:active.videoId}).catch(console.warn);lastSaved=0}}
window.dcRestartVideo67=restart;
window.dcSaveCollectionProgressDcft=()=>saveCurrent(true);
function bindPlayer(){const body=document.getElementById('viewerBody');if(!body)return;const v=body.querySelector('video');if(!v||v.dataset.progress67)return;v.dataset.progress67='1';const btn=mk('button','dc-v67-restart','↺ Reproducir desde el inicio');btn.type='button';btn.onclick=restart;let bar=body.querySelector(':scope > .dc-video-actions84b');if(!bar){bar=document.createElement('div');bar.className='dc-video-actions84b';bar.setAttribute('role','group');bar.setAttribute('aria-label','Acciones del video');body.append(bar);}bar.prepend(btn);
 v.addEventListener('timeupdate',()=>saveCurrent());v.addEventListener('seeked',()=>{if(!v.seeking)saveCurrent(true)});v.addEventListener('pause',()=>saveCurrent(true));v.addEventListener('ended',()=>saveCurrent(true));
 if(active&&Number(window.dcCurrentVideo64)===Number(active.videoId)){
  const seek=active.seek;active.seek=0;const resume=()=>{if(Number.isFinite(v.duration)&&seek<v.duration-2)v.currentTime=seek;};if(seek>0){if(v.readyState>=1)resume();else v.addEventListener('loadedmetadata',resume,{once:true});}
 }
}
const obs=new MutationObserver(()=>bindPlayer());function startObserver(){const b=document.getElementById('viewerBody');if(b){obs.observe(b,{childList:true});bindPlayer()}else setTimeout(startObserver,500)}
async function play(c,i,seek=0){if(!c.episodes?.length)return;await saveCurrent(true);const episodes=c.episodes;const x=Math.max(0,Math.min(episodes.length-1,i));window.dcVideoFiles64=episodes.map(e=>({id:Number(e.video_id),name:e.video_name,mime:/^video\//i.test(e.mime_type||'')?e.mime_type:'video/mp4'}));active={collectionId:Number(c.id),videoId:Number(episodes[x].video_id),seek};window.viewFile?.(active.videoId,(/^video\//i.test(episodes[x].mime_type||'')?episodes[x].mime_type:'video/mp4'));}
const originalSlide=window.dcVideoSlide64;
function slideOverride(step){if(!active)return originalSlide?.(step);const list=window.dcVideoFiles64||[];const i=list.findIndex(v=>v.id===Number(window.dcCurrentVideo64));const next=list[i+step];if(!next)return;saveCurrent(true);active.videoId=next.id;active.seek=0;window.viewFile?.(next.id,next.mime);}
function setupSlide(){if(typeof window.dcVideoSlide64==='function' && window.dcVideoSlide64!==slideOverride){window.dcVideoSlide64=slideOverride}}
window.dcCollectionsDecorate67=async function(parent){const ticket=++generation;let data;try{data=await get(api,{action:'parent',parent_id:parent})}catch(e){console.warn('Colecciones:',e);return}if(ticket!==generation||(typeof currentParentId!=="undefined" && Number(currentParentId)!==parent))return;
 for(const c of data.items||[]){const root=document.getElementById('fileGrid');const item=root?.querySelector(':scope > [id="file-item-'+Number(c.folder_id)+'"]');if(!item||!c.episodes?.length||Number(c.folder_id)===Number(parent))continue;item.classList.add('dc-collection-folder67');const old=item.querySelector('.dc-version-visual');
 if(!old){
  // Ya decorada: refrescar datos sin insertar una segunda tarjeta ni alterar la carpeta.
  const label=item.querySelector('.dc-collection-label67');if(label){label.textContent=c.title;label.title=c.title}
  const meta=item.querySelector('.dc-collection-meta67');if(meta)meta.textContent=(c.episodes?.length||0)+' episodios';
  const description=item.querySelector('.dc-collection-description71');if(description)description.textContent=c.description||'';
  const poster=item.querySelector('.dc-collection-poster67');if(poster)applyPoster(poster,c);
  const collectionGrid=document.getElementById('dcCollectionsGrid69');
  if(collectionGrid){item.classList.add('dc-collection-media68','dc-collection-section69','dcy-card');collectionGrid.append(item)}
  continue;
 }
 const poster=mk('div','dc-collection-poster67');applyPoster(poster,c);old.replaceWith(poster);
 const region=mk('div','dc-collection-ui67');const name=mk('div','dc-collection-label67',c.title);name.title=c.title;const info=mk('div','dc-collection-meta67',c.episodes.length+' episodios');const description=mk('div','dc-collection-description71',c.description||'');const select=mk('select','');select.setAttribute('aria-label','Seleccionar episodio');c.episodes.forEach((ep,i)=>{const opt=document.createElement('option');opt.value=String(i);opt.textContent=`${i+1}. ${ep.video_name}`;select.append(opt)});
 const bar=mk('div','dc-collection-progress67');const fill=mk('div');bar.append(fill);const status=mk('div','dc-collection-meta67','Sin progreso guardado');const actions=mk('div','dc-collection-actions67');
 const playBtn=mk('button','','▶ Reproducir');const coverBtn=mk('button','','▣ Portada');const manageBtn=mk('button','','✎ Administrar');
 [select,playBtn,coverBtn,manageBtn].forEach(el=>{el.addEventListener('click',stop);el.addEventListener('dblclick',stop)});
 const history=await loadProgress(c);if(ticket!==generation||(typeof currentParentId!=="undefined" && Number(currentParentId)!==parent))return;
 const byId=new Map(history.map(x=>[Number(x.video_id),x]));const last=history[0];const lastIdx=last?c.episodes.findIndex(x=>Number(x.video_id)===Number(last.video_id)):-1;if(lastIdx>=0)select.value=String(lastIdx);
 function update(){const ep=c.episodes[Number(select.value)]||c.episodes[0];const p=byId.get(Number(ep.video_id));const pct=p&&Number(p.duration_seconds)>0?Math.min(100,100*Number(p.position_seconds)/Number(p.duration_seconds)):0;fill.style.width=pct+'%';status.textContent=p?`${format(p.position_seconds)} / ${format(p.duration_seconds)}${Number(p.completed)?' · Completado':''}`:'Sin progreso guardado'}
 select.onchange=update;update();const refresh85=e=>{const p=e.detail;if(Number(p.collectionId)!==Number(c.id)||!item.isConnected)return;byId.set(Number(p.videoId),{video_id:p.videoId,position_seconds:p.position,duration_seconds:p.duration,completed:p.completed});const idx=c.episodes.findIndex(ep=>Number(ep.video_id)===Number(p.videoId));if(idx>=0)select.value=String(idx);update();};window.addEventListener('dc-video-progress85',refresh85);playBtn.onclick=()=>{const ep=c.episodes[Number(select.value)]||c.episodes[0];const p=byId.get(Number(ep.video_id));play(c,Number(select.value),Number(p?.position_seconds)||0)};
 coverBtn.onclick=()=>{const input=document.createElement('input');input.type='file';input.accept='image/jpeg,image/png,image/webp';input.hidden=true;document.body.append(input);input.onchange=async()=>{const file=input.files?.[0];input.remove();if(!file)return;const form=new FormData();form.append('action','upload_cover');form.append('csrf',window.DC_AUTH?.csrf||'');form.append('id',String(c.id));form.append('cover',file);try{const r=await fetch(api,{method:'POST',body:form});const d=await r.json();if(!r.ok||d.error)throw Error(d.error||'Error al guardar portada');await window.loadFiles?.()}catch(e){alert(e.message)}};input.click()};
 const libraryCover=async()=>{
  try{
   const r=await fetch(api+'?action=cover_library&id='+encodeURIComponent(c.id),{credentials:'same-origin'});const d=await r.json();if(!r.ok||d.error)throw Error(d.error||'No se pudieron consultar tus imágenes');
   const all=d.items||[];if(!all.length){alert('No se encontraron imágenes en tu unidad');return;}
   const overlay=mk('div','dc-cover-overlay68');const pane=mk('div','dc-cover-pane68');
   const heading=mk('h3','','Seleccionar imagen de Drive-Craft');const search=mk('input');search.type='search';search.placeholder='Filtrar por nombre…';search.style.width='100%';
   const sel=mk('select');sel.size=12;sel.style.width='100%';sel.style.minWidth='min(80vw,420px)';
   const controls=mk('div','dc-cover-editor-actions68');const ok=mk('button','','Usar como portada');const close=mk('button','','Cancelar');
   const draw=()=>{sel.replaceChildren();for(const x of all.filter(x=>x.name.toLowerCase().includes(search.value.toLowerCase()))){const opt=document.createElement('option');opt.value=String(x.id);opt.textContent=x.name+' · carpeta '+x.parent_id;sel.append(opt);}};
   search.oninput=draw;draw();close.onclick=()=>overlay.remove();overlay.addEventListener('click',e=>{if(e.target===overlay)overlay.remove()});
   ok.onclick=async()=>{if(!sel.value)return;ok.disabled=true;try{const fd=new FormData();fd.set('action','cover_from_library');fd.set('id',String(c.id));fd.set('file_id',sel.value);fd.set('csrf',window.DC_AUTH?.csrf||'');const rr=await fetch(api,{method:'POST',body:fd,credentials:'same-origin'});const dd=await rr.json();if(!rr.ok||dd.error)throw Error(dd.error||'No se pudo asignar la imagen');overlay.remove();await window.loadFiles?.();}catch(e){alert(e.message);ok.disabled=false;}};
   controls.append(ok,close);pane.append(heading,search,sel,controls);overlay.append(pane);document.body.append(overlay);
  }catch(e){alert(e.message)}
 };
 manageBtn.onclick=()=>{window.openFolder?.(Number(c.folder_id))};
 const libraryBtn=mk('button','','▤ Elegir portada desde DC');libraryBtn.onclick=e=>{stop(e);libraryCover()};actions.append(playBtn,libraryBtn);
 // Reutilizar exclusivamente el menú contextual ORIGINAL de la carpeta.
 const layoutBtn=mk('button','','▣ Ajustar encuadre');layoutBtn.onclick=e=>{stop(e);window.dcCollectionLayout68?.(c)};
 const originalMenu=item.querySelector('.context-menu-drive');
 if(originalMenu && !originalMenu.dataset.dcCollectionActions70){
   // Conservar menú original: papelera, compartición, favoritos y permisos existentes.
   const menuItems=Array.from(originalMenu.querySelectorAll('a'));
   for(const link of menuItems){
     const title=DCI18n.original(link).trim();
     if(/Configurar YouTube Links|Convertir \/ desactivar colección|Convertir en álbum|Quitar modo álbum|Recibir archivos aquí/i.test(title))link.remove();
     else if(/Descargar ZIP|Descargar carpeta ZIP/i.test(title))link.textContent='📦 Descargar colección ZIP';
     else if(/Eliminar/i.test(title))link.textContent='🗑️ Enviar a papelera';
   }
   const close=()=>originalMenu.classList.remove('show');
   const addAction=(label,fn)=>{const link=mk('a','',label);link.href='#';link.addEventListener('click',e=>{e.preventDefault();stop(e);close();fn()});originalMenu.prepend(link)};
   // Se trata de una colección existente; no ofrecer otra conversión.
   for(const link of originalMenu.querySelectorAll('a')){
     if(/modo (YT Links|música|Album|álbum)|biblioteca musical|colección de videos|colección de video/i.test(DCI18n.original(link)))link.remove();
   }
   addAction('🎬 Quitar modo colección de video',async()=>{
     if(!confirm('¿Quitar el modo colección de video? Los episodios y demás archivos permanecen intactos.'))return;
     try{
       const body=new URLSearchParams({action:'disable_folder',folder_id:String(c.folder_id),csrf:window.DC_AUTH?.csrf||''});
       const r=await fetch(api,{method:'POST',credentials:'same-origin',cache:'no-store',body});
       const data=await r.json();if(!r.ok||data.error||!data.success)throw Error(data.error||'No se pudo quitar el modo colección');
       await window.loadFiles?.();
     }catch(e){alert(e.message||String(e));}
   });
   addAction('📁 Abrir paquete',()=>window.openFolder?.(Number(c.folder_id)));
   addAction('✎ Editar título y descripción',async()=>{
      const title=prompt('Título de la colección:',c.title);if(title===null)return;
      const description=prompt('Descripción de la colección:',c.description||'');if(description===null)return;
      const body=new URLSearchParams({action:'save',csrf:window.DC_AUTH?.csrf||'',id:String(c.id),folder_id:String(c.folder_id),title:title.trim(),description,episodes:JSON.stringify(c.episodes.map(x=>Number(x.video_id)))});
      try{const r=await fetch(api,{method:'POST',credentials:'same-origin',body});const d=await r.json();if(!r.ok||d.error)throw Error(d.error||'No se pudo editar la colección');await window.loadFiles?.()}
      catch(e){alert(e.message)}
   });
   addAction('📎 Agregar archivos',()=>{
      const input=mk('input');input.type='file';input.multiple=true;input.hidden=true;document.body.append(input);
      input.onchange=async()=>{const files=Array.from(input.files||[]);input.remove();if(!files.length)return;
       if(files.some(f=>!f.name||f.name.includes('/')||f.name.includes('\\'))){alert('Selecciona únicamente archivos, no carpetas');return}
       try{if(typeof handleFiles!=='function')throw Error('No se encontró el cargador de archivos de Drive-Craft');await handleFiles(files,Number(c.folder_id))}catch(e){alert(e.message)}
      };input.click();
   });
   addAction('▣ Ajustar encuadre',()=>window.dcCollectionLayout68?.(c));
   addAction('🖼 Cambiar portada',()=>coverBtn.click());
   addAction('🗂 Elegir portada desde DC',libraryCover);
   originalMenu.dataset.dcCollectionActions70='1';
 }
 region.append(name,info,description,select,bar,status,actions);poster.after(region);
 // Carpetas YouTube Links: las colecciones viven en una sección independiente.
 // Nunca mezclar con el paginador de videos ni con los videos sin empaquetar.
 const collectionGrid=document.getElementById('dcCollectionsGrid69');
 if(collectionGrid && Number(currentParentId)===parent){
   item.dataset.dcCollectionInMedia68='1';
   item.classList.add('dc-collection-media68','dc-collection-section69','dcy-card');
   collectionGrid.append(item);
 }

 }
};
// Cuando se navega fuera de una colección, las flechas de videos normales siguen disponibles.

// Editor: arrastre directo únicamente en marcos 16:9; póster 2:3 sin recorte.
window.dcCollectionLayout68=async function(c){
 const overlay=mk('div','dc-cover-overlay68');const pane=mk('div','dc-cover-pane68');
 const h=mk('h3','','Ajustar portada: '+c.title);const info=mk('p','','En 16:9 arrastra la imagen con el mouse o el dedo. También puedes alejar o acercar la imagen. El archivo original no se modifica.');
 const choice=mk('select','');[['16:9','Horizontal 16:9'],['2:3','Póster vertical 2:3'],['auto','Automático (orientación)']].forEach(([val,title])=>{const op=mk('option','',title);op.value=val;choice.append(op)});
 choice.value=c.cover_layout||'16:9';const frame=mk('div','dc-cover-frame68');const img=mk('img');img.src=coverUrl(c.id,true);img.alt=c.title;frame.append(img);
 const controls=mk('div','dc-cover-controls76');const zoomOut=mk('button','','－');const zoomIn=mk('button','','＋');const zoomRange=mk('input');zoomRange.type='range';zoomRange.min='25';zoomRange.max='250';zoomRange.step='5';const zoomLabel=mk('span','dc-cover-zoom-label76');
 const actions=mk('div','dc-cover-editor-actions68');const center=mk('button','','Centrar imagen');const save=mk('button','','Guardar');const cancel=mk('button','','Cancelar');
 let x=Number(c.cover_position_x??50),y=Number(c.cover_position_y??50),scale=coverScale(c),pointer=null;
 const syncZoom=()=>{zoomRange.value=String(scale);zoomLabel.textContent='Zoom '+Math.round(scale)+'%'};
 const render=()=>{const v=choice.value==='auto'?(img.naturalHeight>img.naturalWidth?'2:3':'16:9'):choice.value;frame.dataset.layout=v;frame.style.cursor=v==='16:9'?'grab':'default';paintCover(frame,img,v,x,y,scale);syncZoom()};
 choice.onchange=render;img.onload=render;
 frame.onpointerdown=e=>{if(frame.dataset.layout!=='16:9')return;pointer={id:e.pointerId,startX:e.clientX,startY:e.clientY,x,y};frame.setPointerCapture(e.pointerId);frame.style.cursor='grabbing'};
 frame.onpointermove=e=>{if(!pointer||pointer.id!==e.pointerId)return;const box=frame.getBoundingClientRect();const fit=Math.min(box.width/img.naturalWidth,box.height/img.naturalHeight),cover=Math.max(box.width/img.naturalWidth,box.height/img.naturalHeight);const factor=cover/fit*scale/100;const overX=Math.max(0,img.naturalWidth*fit*factor-box.width),overY=Math.max(0,img.naturalHeight*fit*factor-box.height);x=overX?Math.max(0,Math.min(100,pointer.x-(e.clientX-pointer.startX)/overX*100)):50;y=overY?Math.max(0,Math.min(100,pointer.y-(e.clientY-pointer.startY)/overY*100)):50;render()};
 frame.onpointerup=frame.onpointercancel=()=>{pointer=null;render()};
 zoomRange.oninput=()=>{scale=Math.max(25,Math.min(250,Number(zoomRange.value)||100));render()};
 zoomOut.onclick=()=>{scale=Math.max(25,scale-5);render()}; zoomIn.onclick=()=>{scale=Math.min(250,scale+5);render()};
 center.onclick=()=>{x=50;y=50;render()};cancel.onclick=()=>overlay.remove();
 save.onclick=async()=>{save.disabled=true;try{const fd=new FormData();for(const [k,v] of Object.entries({action:'set_cover_layout',id:c.id,layout:choice.value,x:Math.round(x),y:Math.round(y),scale:Math.round(scale),csrf:window.DC_AUTH?.csrf||''}))fd.append(k,String(v));const r=await fetch(api,{method:'POST',credentials:'same-origin',body:fd});const d=await r.json();if(!r.ok||d.error)throw Error(d.error||'No se guardó el encuadre');overlay.remove();await window.loadFiles?.()}catch(e){alert(e.message);save.disabled=false}};
 controls.append(zoomOut,zoomRange,zoomIn,zoomLabel);actions.append(center,save,cancel);pane.append(h,info,choice,frame,controls,actions);overlay.append(pane);document.body.append(overlay);render();
};

window.addEventListener('pagehide',()=>{saveCurrent(true)});
startObserver();const timer=setInterval(()=>{setupSlide();if(active){const modal=document.getElementById('viewerModal');if(modal&&getComputedStyle(modal).display==='none'){saveCurrent(true);active=null}}},700);
})();
