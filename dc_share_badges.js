/* Drive-Craft: indicadores de enlaces públicos y permisos internos de archivos PROPIOS. */
(()=>{
 const userSvg='<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.1" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="18" cy="5" r="2.5"/><circle cx="6" cy="12" r="2.5"/><circle cx="18" cy="19" r="2.5"/><path d="m8.3 10.9 7.4-4.8M8.3 13.1l7.4 4.8"/></svg>';
 const linkSvg='<span class="dc-sb-url-text" aria-hidden="true">URL</span>';
 let generation=0;
 async function refresh(){
  const nonce=++generation;
  const grid=document.getElementById('fileGrid');if(!grid)return;
  const cards=[...grid.querySelectorAll('.file-item[id^="file-item-"]')].filter(el=>/^file-item-\d+$/.test(el.id));
  const ids=cards.map(el=>Number(el.id.slice(10)));
  if(!ids.length)return;
  const payload=new FormData();payload.set('csrf',window.DC_AUTH?.csrf||'');payload.set('ids',JSON.stringify(ids.slice(0,250)));
  try{
   const response=await fetch('dc_share_badges.php',{method:'POST',credentials:'same-origin',cache:'no-store',body:payload});
   const json=await response.json();if(!response.ok||!json.success)throw Error(json.error||'No se pudo cargar');
   if(nonce!==generation)return;
   for(const card of cards){
    if(!card.isConnected)continue;
    card.querySelector('.dc-sb-corner')?.remove();
    const id=card.id.slice(10), info=json.items?.[id];if(!info)continue;
    const badges=document.createElement('div');badges.className='dc-sb-corner';
    if(info.internal>0){const el=document.createElement('span');el.className='dc-sb-badge';el.title='Compartido con '+info.internal+' usuario(s)';el.setAttribute('aria-label',el.title);el.innerHTML=userSvg;badges.append(el);}
    if(info.public>0){const el=document.createElement('span');el.className='dc-sb-badge';el.title=info.public+' enlace(s) público(s) activo(s)';el.setAttribute('aria-label',el.title);el.innerHTML=linkSvg;badges.append(el);}
    if(badges.childElementCount)card.append(badges);
   }
  }catch(error){console.warn('[Drive-Craft] Distintivos de compartición:',error.message);}
 }
 if(typeof renderFiles==='function'){
  const original=renderFiles;
  renderFiles=function(...args){const result=original.apply(this,args);queueMicrotask(refresh);return result;};
 }
 // Cuando el propietario concede o revoca acceso, refrescar sin recargar página.
 if(typeof dciRequest==='function'){
  const originalRequest=dciRequest;
  dciRequest=async function(action,...rest){const result=await originalRequest.call(this,action,...rest);if(action==='grant'||action==='revoke')refresh();return result;};
 }
 if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',refresh,{once:true});else refresh();
 window.dcRefreshShareBadges=refresh;
})();
