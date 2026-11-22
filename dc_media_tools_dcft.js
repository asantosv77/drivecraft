/* Drivecraft multimedia tools: scoped search, durable downloads, availability and resume. */
(() => {
 'use strict';
 const canImport=window.DC_AUTH?.role==='admin'||!!window.DC_AUTH?.canUpload;
 const api='dc_media_tools_dcft.php',el=(tag,cls,text)=>{const n=document.createElement(tag);if(cls)n.className=cls;if(text!==undefined)n.textContent=text;return n;};
 let jobs=[],selected=null,progressView=null,mini=null,polling=false,globalSearch=false,scopeFolder=-1,scopeEligible=false,downloadsEnabled=true;
 async function request(action,values={},read=false){
  const body=new URLSearchParams({action,...values});if(!read)body.set('csrf',window.DC_AUTH?.csrf||'');
  const r=await fetch(api+(read?'?'+body:''),read?{credentials:'same-origin',cache:'no-store'}:{method:'POST',body,credentials:'same-origin',cache:'no-store',signal:AbortSignal.timeout(45000)});
  let data;try{data=await r.json();}catch(_){throw Error('El servidor devolvió una respuesta inesperada (HTTP '+r.status+').');}if(!r.ok||data.error)throw Error(data.error||'Error HTTP '+r.status);return data;
 }
 function button(text,run){const b=el('button','',text);b.type='button';b.onclick=run;return b;}
 function modal(title,{minimize=null,back=null}={}){
  const overlay=el('div','dcmt-overlay'),pane=el('section','dcmt-pane'),head=el('div','dcmt-head'),controls=el('div','dcmt-actions'),body=el('div');
  overlay.setAttribute('role','dialog');overlay.setAttribute('aria-modal','true');overlay.setAttribute('aria-label',title);head.append(el('h3','',title));
  let state;const close=()=>{overlay.remove();if(progressView?.overlay===overlay)progressView=null;state?.onClose?.();};
  if(back)controls.append(button('← Volver',back));if(minimize)controls.append(button('— Minimizar',()=>{close();minimize();}));controls.append(button('Cerrar',close));head.append(controls);pane.append(head,body);overlay.append(pane);document.body.append(overlay);
  overlay.addEventListener('keydown',e=>{if(e.key==='Escape'){e.preventDefault();close();if(minimize)minimize();}});state={overlay,pane,body,close,onClose:null};return state;
 }
 function notice(title,text){const m=modal(title);const p=el('p','dcmt-status',text);p.setAttribute('role','status');m.body.append(p);return{...m,status:p};}
 async function validateYoutube(id,title){const m=notice('Disponibilidad de YouTube','Consultando YouTube…');m.status.classList.add('dcmt-loading');try{const r=await request('inspect',{url:'https://www.youtube.com/watch?v='+id});m.status.textContent=r.message;}catch(e){m.status.textContent=e.message;}finally{m.status.classList.remove('dcmt-loading');}}
 async function validateLocal(id,title){const m=notice('Verificar video local','Comprobando el archivo…');m.status.classList.add('dcmt-loading');try{const r=await request('inspect_local',{file_id:id});m.status.textContent=r.message;}catch(e){m.status.textContent=e.message;}finally{m.status.classList.remove('dcmt-loading');}}
 function showMini(){if(!selected)return;mini?.remove();mini=button('◉ Ver progreso de descarga',()=>{mini.remove();mini=null;showProgress(selected);});mini.className='dcmt-mini';document.body.append(mini);}
 function drawProgress(){
  if(!progressView)return;const j=jobs.find(j=>j.id===selected);if(!j){progressView.status.textContent='Consultando la tarea…';return;}
  const p=Math.max(0,Math.min(100,Number(j.progress)||0));progressView.total.style.strokeDashoffset=String(502.65*(1-p/100));progressView.download.style.strokeDashoffset=String(402.12*(1-(Number(j.download_percent)||0)/100));progressView.percent.textContent=Math.round(p)+'%';progressView.title.textContent=j.title||'Video de YouTube';progressView.status.textContent=j.phase+(j.state==='retry'?' · '+j.message:j.state==='error'?' · '+j.message:'');progressView.retry.hidden=j.state!=='error';progressView.open.hidden=j.state!=='done';progressView.open.textContent=j.media_kind==='audio'?'Abrir biblioteca':'Abrir paquete';
  progressView.list.replaceChildren();for(const task of jobs)progressView.list.append(button((task.title||'Video de YouTube')+' — '+task.phase,()=>{selected=task.id;drawProgress();}));
 }
 function showProgress(id){selected=id;progressView?.close();mini?.remove();mini=null;const m=modal('Descarga de YouTube',{minimize:showMini});const title=el('p'),ring=el('div','dcmt-ring');
  ring.innerHTML='<svg viewBox="0 0 190 190" aria-hidden="true"><circle cx="95" cy="95" r="80"/><circle class="dcmt-total" cx="95" cy="95" r="80" stroke-dasharray="502.65" stroke-dashoffset="502.65"/><circle cx="95" cy="95" r="64"/><circle class="dcmt-download" cx="95" cy="95" r="64" stroke-dasharray="402.12" stroke-dashoffset="402.12"/></svg>';
  const percent=el('strong','','0%');ring.append(percent);const status=el('p','dcmt-status','Consultando la tarea…');status.setAttribute('role','status');const hint=el('p','dcmt-status','El servidor continúa aunque minimices o cierres este panel. Puedes reabrirlo con Ver progreso.');
  const retry=button('Reintentar',async()=>{retry.disabled=true;try{await request('retry',{id:selected});await poll();}catch(e){status.textContent=e.message;}finally{retry.disabled=false;}}),open=button('Abrir paquete',()=>{const j=jobs.find(j=>j.id===selected);if(j){m.close();openFolder(Number(j.package_id||j.folder_id));}});retry.hidden=open.hidden=true;
  const details=el('details'),summary=el('summary','','Tareas recientes'),list=el('div','dcmt-job-list');details.append(summary,list);m.body.append(title,ring,status,hint,retry,open,details);progressView={...m,title,percent,status,retry,open,list,total:ring.querySelector('.dcmt-total'),download:ring.querySelector('.dcmt-download')};drawProgress();poll();
 }
 async function poll(){if(polling)return;polling=true;try{const r=await request('jobs',{},true);const before=new Map(jobs.map(j=>[j.id,j.state]));jobs=r.jobs||[];drawProgress();const finished=jobs.some(j=>j.state==='done'&&before.has(j.id)&&before.get(j.id)!=='done'&&Number(j.folder_id)===Number(currentParentId));if(finished&&!isSearching)await loadFiles();}catch(e){if(progressView)progressView.status.textContent=e.message+' Se volverá a consultar.';}finally{polling=false;}}
 function openDownload(initial={}){
  if(!downloadsEnabled){notice('Descargas deshabilitadas','El administrador deshabilitó las descargas. Puedes seguir guardando enlaces de YouTube.');return;}
  const folder=Number(currentParentId),m=modal('Descargar un video de YouTube',{back:initial.back?()=>{m.close();initial.back();}:null}),url=el('input'),query=el('input'),quality=el('select'),status=el('p','dcmt-status'),results=el('div'),label=el('label','','Enlace de YouTube');
  url.type='url';url.placeholder='https://www.youtube.com/watch?v=…';url.value=initial.url||'';url.setAttribute('aria-label','Enlace de YouTube');label.append(url);const searchLabel=el('label','','O busca un video');query.type='search';query.placeholder='Título o canal';query.setAttribute('aria-label','Buscar video en YouTube');searchLabel.append(query);
  for(const [value,text] of [['360','Hasta 360p'],['720','Hasta 720p (recomendado)'],['1080','Hasta 1080p']]){const option=el('option','',text);option.value=value;quality.append(option);}quality.value='720';quality.setAttribute('aria-label','Calidad del video');
  let chosenTitle=initial.title||'';const start=button('Descargar video',async()=>{
   if(!url.value.trim()){status.textContent='Pega un enlace o elige un resultado.';return;}start.disabled=true;status.textContent='Preparando la tarea…';status.classList.add('dcmt-loading');
   try{const r=await request('enqueue',{folder_id:folder,url:url.value.trim(),height:quality.value,title:chosenTitle});m.close();selected=r.id;await poll();showProgress(r.id);}catch(e){status.textContent=e.message;}finally{start.disabled=false;status.classList.remove('dcmt-loading');}
  });
  const search=button('Buscar (primeras 4 opciones)',async()=>{
   if(!query.value.trim()){status.textContent='Escribe qué video buscas.';return;}search.disabled=true;status.textContent='Buscando en YouTube…';status.classList.add('dcmt-loading');results.replaceChildren();
   try{const r=await request('search',{query:query.value.trim()});status.textContent=r.items?.length?'Elige un video; después pulsa Descargar video.':'No se encontraron videos. Puedes pegar el enlace.';
    for(const item of r.items||[]){const b=button('',()=>{url.value=item.url;chosenTitle=item.title;status.textContent='Seleccionado: '+item.title;url.focus();});b.className='dcmt-result';const img=el('img');img.src='https://i.ytimg.com/vi/'+item.id+'/mqdefault.jpg';img.alt='';img.loading='lazy';const text=el('span','',item.title);text.append(el('small','',item.channel));b.append(img,text);results.append(b);}
   }catch(e){status.textContent=e.message;}finally{search.disabled=false;status.classList.remove('dcmt-loading');}
  });query.onkeydown=e=>{if(e.key==='Enter'){e.preventDefault();search.click();}};
  const qLabel=el('label','','Calidad máxima');qLabel.append(quality);m.body.append(el('p','','Se guardará un MP4 completo en esta carpeta. Se respeta tu cuota; no se admiten listas ni emisiones en directo. Máximo dos horas por video.'),label,searchLabel,search,results,qLabel,status,start);url.focus();
 }

 function openYouTubeOptions(){
  const m=modal('Agregar video desde YouTube');m.body.append(el('p','','Elige cómo incorporarlo a esta carpeta.'));
  m.body.append(button('Guardar enlace de YouTube',()=>{m.close();openLink();}));
  if(downloadsEnabled)m.body.append(button('Descargar video completo',()=>{m.close();openDownload({back:openYouTubeOptions});}));
  else m.body.append(el('p','dcmt-status','La descarga de videos está deshabilitada por el administrador.'));
 }
 function openLink(){
  const folder=Number(currentParentId),m=modal('Guardar enlace de YouTube',{back:()=>{m.close();openYouTubeOptions();}}),url=el('input'),title=el('input'),description=el('textarea'),status=el('p','dcmt-status');
  url.type='url';url.placeholder='https://www.youtube.com/watch?v=…';url.setAttribute('aria-label','Enlace de YouTube');title.setAttribute('aria-label','Título del enlace');description.setAttribute('aria-label','Descripción del enlace');description.style.cssText='display:block;width:100%;box-sizing:border-box;min-height:90px';
  for(const [text,input] of [['Enlace de YouTube',url],['Título (opcional)',title],['Descripción (opcional)',description]]){const label=el('label','',text);label.append(input);m.body.append(label);}
  const save=button('Guardar enlace',async()=>{const h=window.DCSaveFeedback?.begin(save);try{await dcyRequest('add',{folder_id:folder,url:url.value.trim(),title:title.value.trim(),description:description.value.trim()},true);h?.success('Enlace de YouTube guardado.');m.close();if(Number(currentParentId)===folder)await loadFiles();}catch(e){if(h)h.error(e);status.textContent=e.message;}});m.body.append(status,save);url.focus();
 }
 async function refreshCapabilities(){try{const d=await request('capabilities',{},true);downloadsEnabled=!!d.downloads_enabled;}catch(_){}refreshScope();}
 async function refreshScope(){
  const folder=Number(currentParentId),seen=new Set();let id=folder,eligible=!!dcyState?.enabled;
  while(id>0&&!seen.has(id)&&seen.size<128){seen.add(id);const f=allFoldersData.find(f=>Number(f.id)===id);if(Number(f?.is_album)||window.dcMusicLibraryIds82?.has(id))eligible=true;id=Number(f?.parent_id)||0;}
  // Inherited YouTube/video modes can also cover a child folder.
  if(!eligible&&folder>0){try{const d=await request('scope',{folder_id:folder},true);if(Number(currentParentId)!==folder)return;eligible=!!d.eligible;}catch(_){}}
  let label=document.getElementById('dcmtScope');if(!label){label=el('label','dcmt-scope');label.id='dcmtScope';const input=el('input');input.type='checkbox';input.id='dcmtScopeInput';label.append(input,document.createTextNode('Buscar desde esta carpeta'));document.querySelector('.search-box')?.append(label);}
  const input=label.querySelector('input');input.disabled=!eligible;
  label.title=eligible?'Buscar en esta carpeta y sus subcarpetas.':'La búsqueda acotada solo está disponible en álbumes y bibliotecas multimedia.';
  if(scopeFolder!==folder){input.checked=eligible;scopeFolder=folder;}scopeEligible=eligible;
  const bar=document.getElementById('dcyToolbar')?.querySelector('.dcy-toolbar-actions');if(canImport&&bar&&!bar.querySelector('[data-dcmt-download]')){const b=bar.querySelector('#dcyAddButton');if(b){b.textContent='+ Agregar video desde YT';b.onclick=()=>openYouTubeOptions();}const progress=button('◉ Ver progreso',()=>{poll().then(()=>{const j=jobs.find(j=>Number(j.folder_id)===folder);if(j)showProgress(j.id);else notice('Descargas','Todavía no hay descargas recientes para esta carpeta.');});});progress.dataset.dcmtDownload='1';bar.append(progress);}
 }
 const searchScope=()=>scopeEligible&&document.getElementById('dcmtScopeInput')?.checked?Number(currentParentId):null;
 function beforeSearch(){document.querySelectorAll('.dcy-pagination').forEach(n=>n.remove());for(const id of ['dcyGrid','dcyToolbar','dcCollectionsSection69','dcMusicSection81','dcOtherFilesTitle73'])document.getElementById(id)?.remove();}
 // Private per-account progress for standalone local videos and YouTube links.
 function saveProgress(kind,key,position,duration,beacon=false){
  if(!Number.isFinite(position)||!Number.isFinite(duration)||duration<=0)return Promise.resolve();const values={kind,key,position:Math.max(0,position),duration};
  if(beacon&&navigator.sendBeacon){const body=new URLSearchParams({action:'progress_save',csrf:window.DC_AUTH?.csrf||'',...values});navigator.sendBeacon(api,body);return Promise.resolve();}
  return request('progress_save',values).catch(()=>{});
 }
 const localPlayers=new Set();
 function bindLocal(){for(const video of document.querySelectorAll('#viewerBody video,#dcFloat85 video')){
  if(video.dataset.dcmtProgress||video.dataset.progress67)continue;const key=String(window.dcCurrentVideo64||'');if(!/^\d+$/.test(key)||key==='0')continue;video.dataset.dcmtProgress='1';let ready=false,last=0;const record={video,save:beacon=>{if(ready)saveProgress('local',key,video.currentTime,video.duration,beacon);}};localPlayers.add(record);
  request('progress_get',{kind:'local',key},true).then(r=>{const resume=()=>{if(!video.isConnected)return;const p=r.progress;const pos=Number(p?.position_seconds)||0;const oldDuration=Number(p?.duration_seconds)||0;if(pos>5&&(!oldDuration||pos/oldDuration<.95)&&pos<video.duration-2)video.currentTime=pos;ready=true;};if(video.readyState>=1)resume();else video.addEventListener('loadedmetadata',resume,{once:true});}).catch(()=>{ready=true;});
  video.addEventListener('timeupdate',()=>{if(Date.now()-last>8000){last=Date.now();record.save(false);}});for(const ev of ['pause','ended','seeked'])video.addEventListener(ev,()=>record.save(false));
 }}
 const localObserver=new MutationObserver(()=>{for(const r of localPlayers)if(!r.video.isConnected){r.save(true);localPlayers.delete(r);}bindLocal();});localObserver.observe(document.body,{childList:true,subtree:true});
 let ytApiPromise=null,ytClose=null;
 function youtubeApi(){if(window.YT?.Player)return Promise.resolve(window.YT);if(ytApiPromise)return ytApiPromise;ytApiPromise=new Promise((resolve,reject)=>{const previous=window.onYouTubeIframeAPIReady;window.onYouTubeIframeAPIReady=()=>{previous?.();clearTimeout(timer);resolve(window.YT);};const script=el('script');script.src='https://www.youtube.com/iframe_api';script.onerror=()=>{clearTimeout(timer);ytApiPromise=null;reject(Error('No se pudo cargar el reproductor de YouTube.'));};const timer=setTimeout(()=>{ytApiPromise=null;reject(Error('YouTube tardó demasiado en responder.'));},20000);document.head.append(script);});return ytApiPromise;}
 window.dcyPlay=async function(id,title){
  if(!/^[A-Za-z0-9_-]{11}$/.test(id))return;ytClose?.();document.getElementById('dcyPlayer')?.remove();const m=modal(title||'Video de YouTube'),host=el('div'),hint=el('p','dcmt-playback-hint','Preparando reproductor…');host.style.aspectRatio='16 / 9';m.body.append(host,hint);let player=null,timer=null,ready=false,closed=false;
  const save=beacon=>{if(ready&&player?.getCurrentTime)saveProgress('youtube',id,player.getCurrentTime(),player.getDuration(),beacon);};const close=m.close;m.close=()=>{if(closed)return;closed=true;save(true);clearInterval(timer);player?.destroy?.();close();ytClose=null;};m.overlay.querySelector('.dcmt-head button').onclick=m.close;
  ytClose=m.close;m.onClose=()=>{if(!closed)m.close();};
  try{const [YT,history]=await Promise.all([youtubeApi(),request('progress_get',{kind:'youtube',key:id},true)]);if(closed)return;
   const pos=Number(history.progress?.position_seconds)||0,dur=Number(history.progress?.duration_seconds)||0,start=pos>5&&(!dur||pos/dur<.95)?Math.floor(pos):0;
   player=new YT.Player(host,{host:'https://www.youtube-nocookie.com',videoId:id,width:'100%',height:'100%',playerVars:{autoplay:1,playsinline:1,start,origin:location.origin},events:{onReady:()=>{ready=true;hint.textContent=start?'Reanudando desde '+Math.floor(start/60)+':'+String(start%60).padStart(2,'0'):'El avance se guarda en tu cuenta.';timer=setInterval(()=>save(false),8000);},onStateChange:e=>{if([0,2].includes(e.data))save(false);},onError:e=>{hint.textContent='YouTube no permite reproducir este video aquí (código '+e.data+'). Puedes comprobar su disponibilidad desde el menú.';}}});
   m.body.append(button('↺ Reproducir desde el inicio',()=>{player.seekTo(0,true);saveProgress('youtube',id,0,player.getDuration());player.playVideo();}));
  }catch(e){hint.textContent=e.message;}
 };
 window.addEventListener('pagehide',()=>{for(const r of localPlayers)r.save(true);ytClose?.();});
 document.addEventListener('visibilitychange',()=>{if(document.hidden)for(const r of localPlayers)r.save(true);});
 window.addEventListener('dc-folder-loaded-dcft',refreshScope);
 window.DCMediaTools={audioUI:{el,button,modal,request,canImport,poll,showProgress,showFolderProgress:(folder,kind)=>{const job=jobs.find(j=>Number(j.folder_id)===folder&&j.media_kind===kind);if(job)showProgress(job.id);else notice('Descargas','Todavía no hay descargas recientes para esta carpeta.');}},searchScope,beforeSearch,openDownload,openYouTubeOptions,validateYoutube,validateLocal,refreshScope,refreshCapabilities,downloadsEnabled:()=>downloadsEnabled,setGlobalSearch:value=>{globalSearch=!!value;refreshScope();},flush:()=>{for(const r of localPlayers)r.save(true);window.dcSaveCollectionProgressDcft?.();}};
 fetch('api.php?action=settings',{cache:'no-store'}).then(r=>r.json()).then(s=>{globalSearch=!!s.global_search;refreshScope();}).catch(()=>{});
 setInterval(()=>{if(!document.hidden&&(progressView||mini||jobs.some(j=>['queued','running','retry'].includes(j.state))))poll();},2500);
 refreshCapabilities();
 poll().then(()=>{if(jobs.some(j=>['queued','running','retry'].includes(j.state))){selected=jobs.find(j=>['queued','running','retry'].includes(j.state)).id;showMini();}});
})();
