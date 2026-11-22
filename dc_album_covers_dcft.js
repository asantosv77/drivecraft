/* Three existing previews inside the photo-folder frames; never generates previews. */
(() => {
 'use strict';
 const cache=new Map(),pending=new Map(),queue=[];
 let active=0,generation=0,observer=null;
 const abortError=()=>new DOMException('Vista cancelada','AbortError');
 // Hover enlargement scales the existing frames in place: no clones, no new requests.
 let hoverRoot=null,hoverTimer=null;
 const HOVER_DELAY=1000;
 function clearHover(){
  clearTimeout(hoverTimer);hoverTimer=null;
  if(!hoverRoot)return;
  hoverRoot.classList.remove('dc-album-hover');hoverRoot=null;
 }
 function attachHover(root){
  if(root.dataset.dcHoverBound)return;root.dataset.dcHoverBound='1';
  // The icon has pointer-events:none, so the card is the real hover target.
  // Tree icons are skipped: the sidebar clips any overflowing enlargement.
  const card=root.closest('.file-item');if(!card)return;
  card.addEventListener('pointerenter',event=>{
   if(event.pointerType==='touch')return;
   clearHover();hoverRoot=root;
   hoverTimer=setTimeout(()=>{
    hoverTimer=null;
    if(hoverRoot===root&&root.isConnected)root.classList.add('dc-album-hover');
   },HOVER_DELAY);
  });
  card.addEventListener('pointerleave',()=>{if(hoverRoot===root)clearHover();});
  card.addEventListener('pointercancel',()=>{if(hoverRoot===root)clearHover();});
 }
 document.addEventListener('keydown',event=>{if(event.key==='Escape')clearHover();});
 document.addEventListener('dragstart',clearHover,true);
 document.addEventListener('scroll',clearHover,true);
 document.addEventListener('visibilitychange',()=>{if(document.hidden)clearHover();});
 window.addEventListener('blur',clearHover);
 window.addEventListener('resize',clearHover);

 function pump(){
  while(active<2&&queue.length){
   const job=queue.shift();if(job.generation!==generation){job.reject(abortError());continue;}
   active++;job.started=true;
   const timeout=setTimeout(()=>job.controller.abort(),15000);
   (async()=>{
    try{
     const response=await fetch('api.php?action=album_previews_dcft&id='+encodeURIComponent(job.id),{credentials:'same-origin',cache:'no-store',signal:job.controller.signal});
     if(!response.ok)throw Error('Portada no disponible');
     const data=await response.json();if(job.generation!==generation)throw abortError();
     const photos=Array.isArray(data.photos)?data.photos.slice(0,3):[];
     cache.delete(job.id);cache.set(job.id,{photos,expires:Date.now()+300000});
     while(cache.size>150)cache.delete(cache.keys().next().value);
     job.resolve(photos);
    }catch(error){job.reject(error);}
    finally{clearTimeout(timeout);if(pending.get(job.id)===job)pending.delete(job.id);active--;pump();}
   })();
  }
 }
 function selection(id){
  id=String(id);const saved=cache.get(id);if(saved&&saved.expires>Date.now())return Promise.resolve(saved.photos);
  if(pending.has(id))return pending.get(id).promise;
  const job={id,generation,controller:new AbortController(),started:false};
  job.promise=new Promise((resolve,reject)=>{job.resolve=resolve;job.reject=reject;});pending.set(id,job);queue.push(job);pump();return job.promise;
 }
 async function paint(root){
  if(!root.isConnected)return;
  const view=generation;
  try{
   const photos=await selection(root.dataset.dcCoverFolder);
   if(!root.isConnected||view!==generation)return;
   window.DCThumbCache.remember(photos);
   const frames=root.querySelectorAll('.dc-compact-photo85e');
   await Promise.all(photos.map(async(photo,index)=>{
    const result=await window.DCThumbCache.request(window.DCThumbCache.url(photo.id,true),true);
    if(!result.ok||!result.dataUrl||!root.isConnected||view!==generation||!frames[index])return;
    const img=document.createElement('img');img.alt='';img.setAttribute('aria-hidden','true');img.className='dc-album-frame-photo';img.decoding='async';img.src=result.dataUrl;
    if(img.decode)await img.decode().catch(()=>{});
    if(!root.isConnected||view!==generation)return;
    frames[index].replaceChildren(img);
   }));
  }catch(_){/* Keep the original gradient frames when previews are absent/unavailable. */}
 }
 function observe(root,id){
  if(!root.isConnected)return;
  root.dataset.dcCoverFolder=String(id);
  attachHover(root);
  if(!('IntersectionObserver' in window)){paint(root);return;}
  if(!observer)observer=new IntersectionObserver(entries=>{for(const entry of entries)if(entry.isIntersecting){observer.unobserve(entry.target);paint(entry.target);}},{rootMargin:'120px'});
  observer.observe(root);
 }
 function reset(){
  clearHover();
  generation++;observer?.disconnect();observer=null;
  for(const job of pending.values()){job.controller.abort();if(!job.started)job.reject(abortError());}
  pending.clear();queue.length=0;
 }
 function invalidatePhoto(id){for(const [folder,value] of cache)if(value.photos.some(photo=>Number(photo.id)===Number(id)))cache.delete(folder);}
 window.DCAlbumCovers={observe,reset,invalidatePhoto};
 window.addEventListener('pagehide',()=>{reset();cache.clear();});
})();
