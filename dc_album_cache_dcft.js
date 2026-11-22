/* Bounded, tab-local preview cache. Originals never enter this cache. */
(() => {
 'use strict';
 const account=String(window.DC_AUTH?.accountId||'');
 const entries=new Map(),pending=new Map(),queue=[],metadata=new Map();
 const MAX_BYTES=24*1024*1024,MAX_ENTRIES=240,MAX_PREVIEW=4*1024*1024;
 let bytes=0,active=0,generation=0;
 const aborted=()=>new DOMException('Carga cancelada','AbortError');
 function remember(files){
  for(const file of files||[])if(!Number(file.is_dir)){
   const id=String(file.id),old=metadata.get(id);
   if(!file.thumb_version&&old&&(!file.content_hash||old.sourceHash===file.content_hash))continue;
   const version=String(file.thumb_version||file.content_hash||'0');
   if(old&&old.version!==version)invalidate(id);
   metadata.delete(id);metadata.set(id,{version,sourceHash:file.content_hash||'',width:Number(file.thumb_width)||0,height:Number(file.thumb_height)||0});
  }
  while(metadata.size>4000)metadata.delete(metadata.keys().next().value);
 }
 function url(id,cachedOnly=false){
  const v=metadata.get(String(id))?.version||'0';
  return 'api.php?'+new URLSearchParams({action:'thumb',id:String(id),account,v,...(cachedOnly?{cached:'1'}:{})});
 }
 function key(value){
  const u=new URL(value,location.href);
  if(u.origin!==location.origin||!u.pathname.endsWith('/api.php')||u.searchParams.get('action')!=='thumb')throw Error('Solo se permiten miniaturas de Drive-Craft.');
  const id=u.searchParams.get('id'),version=u.searchParams.get('v')||metadata.get(id)?.version||'0';
  // cached=1 controls generation; a successful preview can be shared by either mode.
  return account+'|'+id+'|'+version;
 }
 function supports(value){try{key(value);return true;}catch(_){return false;}}
 function touch(k,value){entries.delete(k);entries.set(k,value);return value;}
 function getCached(value){try{const k=key(value),item=entries.get(k);return item?touch(k,item):null;}catch(_){return null;}}
 function put(k,value){
  const old=entries.get(k);if(old)bytes-=old.cost;
  entries.delete(k);entries.set(k,value);bytes+=value.cost;
  while(bytes>MAX_BYTES||entries.size>MAX_ENTRIES){const first=entries.keys().next().value;bytes-=entries.get(first).cost;entries.delete(first);}
 }
 function invalidate(id){window.DCAlbumCovers?.invalidatePhoto(id);for(const [k,item] of entries)if(k.split('|')[1]===String(id)){entries.delete(k);bytes-=item.cost;}metadata.delete(String(id));}
 function base64(buffer){
  const data=new Uint8Array(buffer);let result='';
  for(let offset=0;offset<data.length;offset+=16384)result+=String.fromCharCode(...data.subarray(offset,offset+16384));
  return btoa(result);
 }
 function pump(){
  while(active<2&&queue.length){
   const job=queue.shift();if(job.generation!==generation){job.reject(aborted());continue;}
   active++;job.started=true;
   const timeout=setTimeout(()=>job.controller.abort(),20000);
   (async()=>{
    try{
     const response=await fetch(job.url,{credentials:'same-origin',cache:'default',signal:job.controller.signal});
     if(job.generation!==generation)throw aborted();
     if(response.status===401){entries.clear();bytes=0;}
     if(!response.ok||response.headers.get('X-DC-Thumb-Pending')==='1'){job.resolve(response);return;}
     const mime=(response.headers.get('Content-Type')||'').split(';')[0].trim();
     if(!/^image\/(webp|png|jpeg|gif|avif)$/.test(mime))throw Error('Respuesta de miniatura inválida.');
     if(Number(response.headers.get('Content-Length'))>MAX_PREVIEW)throw Error('La miniatura supera el límite permitido.');
     // arrayBuffer avoids the Response.blob path that fails in some Edge versions.
     const buffer=await response.arrayBuffer();
     if(job.generation!==generation||job.controller.signal.aborted)throw aborted();
     if(!buffer.byteLength||buffer.byteLength>MAX_PREVIEW)throw Error('Tamaño de miniatura inválido.');
     const dataUrl='data:'+mime+';base64,'+base64(buffer);
     const result={ok:true,status:200,headers:response.headers,dataUrl,cost:dataUrl.length*2};
     put(job.key,result);job.resolve(result);
    }catch(error){job.reject(error);}
    finally{clearTimeout(timeout);if(pending.get(job.pendingKey)===job)pending.delete(job.pendingKey);active--;pump();}
   })();
  }
 }
 function request(value,lowPriority=false){
  const k=key(value),cached=getCached(value);if(cached)return Promise.resolve(cached);
  const pendingKey=k+'|'+(new URL(value,location.href).searchParams.get('cached')==='1'?'cached':'generate');
  if(pending.has(pendingKey))return pending.get(pendingKey).promise;
  const job={key:k,pendingKey,url:value,generation,controller:new AbortController(),started:false};
  job.promise=new Promise((resolve,reject)=>{job.resolve=resolve;job.reject=reject;});pending.set(pendingKey,job);
  if(lowPriority)queue.push(job);else queue.unshift(job);
  pump();return job.promise;
 }
 function cancelPending(){
  generation++;
  for(const job of pending.values()){job.controller.abort();if(!job.started)job.reject(aborted());}
  pending.clear();queue.length=0;
 }
 function clear(){cancelPending();entries.clear();metadata.clear();bytes=0;}
 function decorate(img,value){
  try{const id=new URL(value,location.href).searchParams.get('id'),info=metadata.get(id);
   if(info?.width>0&&info?.height>0){img.width=info.width;img.height=info.height;img.style.setProperty('--dc-thumb-ratio',info.width+'/'+info.height);}
  }catch(_){}
 }
 function preload(value){
  if(navigator.connection?.saveData||/^(slow-2g|2g)$/.test(navigator.connection?.effectiveType||''))return Promise.resolve();
  return request(value,true).catch(()=>null);
 }
 window.DCThumbCache={supports,remember,url,request,getCached,invalidate,cancelPending,clear,decorate,preload};

 // Only navigation preferences persist in this tab; no image/credential data is stored.
 const storageKey='dc-album-view-v1:'+account,views=new Map();let signedOut=false;
 try{const saved=JSON.parse(sessionStorage.getItem(storageKey)||'[]');if(Array.isArray(saved))for(const [id,v] of saved.slice(-60))if(v&&/^\d+$/.test(String(id)))views.set(String(id),v);}catch(_){}
 function getView(id){const v=views.get(String(id));if(!v)return null;return {
  page:Math.max(1,Math.floor(Number(v.page)||1)),pageSize:[50,75,100].includes(Number(v.pageSize))?Number(v.pageSize):50,
  mode:['explorer','collage','book'].includes(v.mode)?v.mode:'explorer',bookPage:Math.max(0,Math.floor(Number(v.bookPage)||0)),
  windowY:Math.max(0,Number(v.windowY)||0),dropY:Math.max(0,Number(v.dropY)||0)
 };}
 function saveView(id,value){
  if(signedOut)return;
  views.delete(String(id));views.set(String(id),value);while(views.size>60)views.delete(views.keys().next().value);
  try{sessionStorage.setItem(storageKey,JSON.stringify([...views]));}catch(_){}
 }
 function clearViews(){signedOut=true;views.clear();try{sessionStorage.removeItem(storageKey);}catch(_){}}
 window.DCAlbumViews={get:getView,save:saveView,clear:clearViews};
 document.addEventListener('submit',event=>{const form=event.target;if(form?.querySelector?.('[name="action"][value="logout"]')){clear();clearViews();}},true);
 window.addEventListener('pagehide',clear);
})();
