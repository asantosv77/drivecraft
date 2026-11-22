/* Colecciones de videos Drive-Craft v20.10.65 */
(function(){'use strict';
let busy=false;
async function call(action,data={},form=false){
 let url='dc_video_collections_65.php';let opts={credentials:'same-origin',cache:'no-store'};
 if(form){data.append('action',action);data.append('csrf',window.DC_AUTH?.csrf||'');opts={...opts,method:'POST',body:data};}
 else if(['save','delete','convert_folder','disable_folder'].includes(action)){const p=new URLSearchParams({action,csrf:window.DC_AUTH?.csrf||'',...data});opts={...opts,method:'POST',body:p};}
 else url+='?'+new URLSearchParams({action,...data});
 const r=await fetch(url,opts);const j=await r.json();if(!r.ok||j.error)throw Error(j.error||'Error de colecciones');return j;
}
function chooseEpisodes(existing,available){
 const ids=available.map(v=>Number(v.video_id));
 const current=(existing||[]).map(v=>Number(v.video_id));
 const raw=prompt('Orden de episodios (ID separados por comas). Disponible: \n'+available.map(v=>v.video_id+' — '+v.video_name).join('\n'),current.join(','));
 if(raw===null)return null;const chosen=raw.split(',').map(v=>Number(v.trim()));
 if(!chosen.length||chosen.some(v=>!Number.isSafeInteger(v)||!ids.includes(v))||new Set(chosen).size!==chosen.length)throw Error('ID inválidos');return chosen;
}
function playCollection(collection,idx=0){
 const episodes=collection.episodes||[];if(!episodes.length)return;
 const playlist=episodes.map(ep=>({id:Number(ep.video_id),name:String(ep.video_name),mime:ep.mime_type||'video/mp4'}));
 window.dcVideoFiles64=playlist;
 if(typeof window.viewFile==='function')window.viewFile(playlist[idx].id,playlist[idx].mime);
}
async function editor(folder,collection,available){
 try{
  const title=prompt('Nombre de la colección (ej. Temporada 1):',collection?.title||'');if(title===null)return;
  if(!title.trim())return alert('Es necesario indicar un título');
  const description=prompt('Descripción:',collection?.description||'');if(description===null)return;
  const ids=chooseEpisodes(collection?.episodes||[],available);if(ids===null)return;
  await call('save',{id:String(collection?.id||0),folder_id:String(folder),title:title.trim(),description,episodes:JSON.stringify(ids)});
  if(document.getElementById('dcyGrid'))await dcyList(folder,1);else if(typeof loadFiles==='function')await loadFiles();
 }catch(e){alert(e.message)}
}
async function cover(collection,folder){
 const input=document.createElement('input');input.type='file';input.accept='image/jpeg,image/png,image/webp';input.hidden=true;document.body.append(input);
 input.onchange=async()=>{const file=input.files?.[0];input.remove();if(!file)return;
  const form=new FormData();form.append('id',String(collection.id));form.append('cover',file);
  try{await call('upload_cover',form,true);if(document.getElementById('dcyGrid'))await dcyList(folder,1);else await loadFiles()}catch(e){alert(e.message)};
 };input.click();
}
window.dcFolderCollection66=async function(folder){
 try{
  const s=await call('status',{folder_id:String(folder)});
  if(s.collection){if(!confirm('¿Desactivar la colección de videos de esta carpeta? Los archivos no se eliminarán.'))return;await call('disable_folder',{folder_id:String(folder)});}
  else{if(!confirm('¿Convertir esta carpeta en colección de videos? No se moverán ni copiarán archivos.'))return;await call('convert_folder',{folder_id:String(folder)});}
  if(typeof loadFiles==='function')await loadFiles();
  
  alert('Configuración de colección actualizada');
 }catch(e){alert(e.message)}
};
window.dcCollectionsPaint65=async function(folder){
 return; // v67: colección representada como tarjeta en la carpeta padre
 const grid=document.getElementById('dcyGrid')||document.getElementById('fileGrid');if(!grid||Number(currentParentId)!==Number(folder))return;
 let data;try{data=await call('list',{folder_id:String(folder)})}catch(e){console.warn('Colecciones:',e);return;}
 if(Number(currentParentId)!==Number(folder)||(grid!==document.getElementById('dcyGrid')&&grid!==document.getElementById('fileGrid')))return;
 grid.querySelectorAll('.dc-collection-card65').forEach(n=>n.remove());
 document.querySelectorAll('.dc-collection-toolbar65').forEach(n=>n.remove());
 for(const collection of data.items){
  const card=document.createElement('article');card.className='dcy-card dc-collection-card65';
  const poster=document.createElement('div');poster.className='dc-collection-poster65';
  if(collection.has_cover){const img=document.createElement('img');img.src='dc_video_collections_65.php?action=cover&id='+encodeURIComponent(collection.id)+'&v='+Date.now();img.alt='Portada de '+collection.title;img.loading='lazy';poster.append(img);}
  else{const first=collection.episodes?.[0];if(first){const img=document.createElement('img');img.src='dc_local_video.php?action=asset&kind=webp&id='+encodeURIComponent(first.video_id);img.alt='Vista previa de '+collection.title;img.loading='lazy';poster.append(img);}}
  const title=document.createElement('div');title.className='dcy-title';title.textContent=collection.title;
  const desc=document.createElement('div');desc.className='dcy-description';desc.textContent=(collection.episodes?.length||0)+' episodios · '+(collection.description||'Colección de videos');
  const controls=document.createElement('div');controls.className='dc-collection-buttons65';
  const b=(label,fn)=>{const el=document.createElement('button');el.type='button';el.textContent=label;el.onclick=fn;controls.append(el);};
  b('▶ Reproducir',()=>playCollection(collection));b('✎ Editar',()=>editor(folder,collection,data.available));b('▣ Portada',()=>cover(collection,folder));
  b('✕ Desactivar',async()=>{if(!confirm('¿Desactivar esta colección sin eliminar sus videos?'))return;try{await call('disable_folder',{folder_id:String(folder)});if(document.getElementById('dcyGrid'))await dcyList(folder,1);else await loadFiles()}catch(e){alert(e.message)}});
  card.append(poster,title,desc,controls);grid.append(card);
 }
 // Integración con paginación existente: volver a calcular al actualizar.
 window.dcCollectionCount65=(data.items||[]).length;
};
})();
