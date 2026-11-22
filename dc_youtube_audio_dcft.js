/* One-track YouTube audio import; uses Drivecraft's durable queue and shared progress rings. */
(()=>{'use strict';
 const tools=()=>window.DCMediaTools?.audioUI;
 function open(){
  const ui=tools();if(!ui?.canImport)return;
  const {el,button,modal,request}=ui,folder=Number(currentParentId);
  const m=modal('Descargar audio desde YouTube'),url=el('input'),query=el('input'),format=el('select'),quality=el('select'),status=el('p','dcmt-status'),results=el('div');
  if(!window.DCMediaTools.downloadsEnabled()){m.body.append(el('p','','El administrador deshabilitó las descargas de YouTube.'));return;}
  const field=(name,input)=>{const label=el('label','',name);label.append(input);m.body.append(label);};
  m.body.append(el('p','','Descarga una canción o un audio por tarea. No se importan álbumes ni listas. La calidad final depende de la fuente; convertir no recupera calidad perdida.'));
  url.type='url';url.placeholder='https://www.youtube.com/watch?v=…';url.setAttribute('aria-label','Enlace de YouTube');field('Enlace de YouTube',url);
  query.type='search';query.placeholder='Artista y canción';query.setAttribute('aria-label','Buscar música en YouTube');field('O busca una canción',query);
  let title='';url.addEventListener('input',()=>{title='';});
  const search=button('Buscar (primeras 4 opciones)',async()=>{
   if(!query.value.trim()){status.textContent='Escribe el artista o la canción.';return;}
   search.disabled=true;results.replaceChildren();status.textContent='Buscando en YouTube…';status.classList.add('dcmt-loading');
   try{const data=await request('search',{query:query.value.trim()});status.textContent=data.items?.length?'Elige una canción y pulsa Descargar audio.':'No se encontraron resultados. Puedes pegar un enlace.';
    for(const item of data.items||[]){const b=button('',()=>{url.value=item.url;title=item.title;status.textContent='Seleccionado: '+item.title;});b.className='dcmt-result';const img=el('img');img.alt='';img.loading='lazy';img.src='https://i.ytimg.com/vi/'+item.id+'/mqdefault.jpg';const text=el('span','',item.title);text.dataset.dcNoI18n='';const channel=el('small','',item.channel);text.append(channel);b.append(img,text);results.append(b);}
   }catch(e){status.textContent=e.message;}finally{search.disabled=false;status.classList.remove('dcmt-loading');}
  });query.onkeydown=e=>{if(e.key==='Enter'){e.preventDefault();search.click();}};m.body.append(search,results);
  for(const [value,label] of [['opus','Opus (recomendado)'],['mp3','MP3'],['m4a','AAC / M4A']]){const o=el('option','',label);o.value=value;format.append(o);}format.value='opus';format.setAttribute('aria-label','Formato de audio');field('Formato de audio',format);
  for(const value of [64,96,128,160,192,256]){const o=el('option','',value+' kbps');o.value=String(value);quality.append(o);}quality.value='128';quality.setAttribute('aria-label','Calidad de audio');field('Calidad de audio',quality);
  const start=button('Descargar audio',async()=>{
   if(!url.value.trim()){status.textContent='Pega un enlace o elige un resultado.';return;}
   start.disabled=true;status.classList.add('dcmt-loading');status.textContent='Preparando la tarea…';
   try{const data=await request('enqueue',{media_kind:'audio',folder_id:folder,url:url.value.trim(),audio_format:format.value,audio_bitrate:quality.value,title});m.close();await ui.poll();ui.showProgress(data.id);}
   catch(e){status.textContent=e.message;}finally{start.disabled=false;status.classList.remove('dcmt-loading');}
  });m.body.append(el('p','dcmt-status','Se guardará un archivo de audio normal en esta carpeta, sin HLS. La descarga continúa aunque cierres el panel. Máximo dos horas por audio.'),status,start);url.focus();
 }
 function inLibrary(){let id=Number(currentParentId),seen=new Set();while(id>0&&!seen.has(id)&&seen.size<128){seen.add(id);if(window.dcMusicLibraryIds82?.has(id))return true;const f=allFoldersData.find(f=>Number(f.id)===id);id=Number(f?.parent_id)||0;}return false;}
 function refresh(){
  const ui=tools();if(!ui?.canImport)return;
  const host=document.querySelector('.drive-header-actions .main-buttons');if(!host)return;
  const eligible=!isSearching&&inLibrary();let group=document.getElementById('dcAudioImportTools');
  if(!group){group=document.createElement('span');group.id='dcAudioImportTools';group.style.cssText='display:inline-flex;gap:6px;flex-wrap:wrap';const add=ui.button('+ Audio desde YouTube',open);add.className='btn-primary btn-outline';
   const progress=ui.button('◉ Ver progreso',async()=>{await ui.poll();ui.showFolderProgress(Number(currentParentId),'audio');});progress.className='btn-primary btn-outline';group.append(add,progress);host.append(group);}
  group.hidden=!eligible;group.style.display=eligible?'inline-flex':'none';
 }
 window.DCYouTubeAudio={open,refresh};let queued=false;
 const observer=new MutationObserver(records=>{if(queued||!records.some(r=>r.addedNodes.length||r.removedNodes.length))return;queued=true;queueMicrotask(()=>{queued=false;refresh();});});observer.observe(document.body,{childList:true,subtree:true});
 window.addEventListener('dc-folder-loaded-dcft',refresh);refresh();
})();
