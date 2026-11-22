/* Drive-Craft v20.10.18 — contenido virtual de carpetas YouTube */
let dcyState={folder:0,enabled:false};
async function dcyRequest(action,params={},write=false){const run=()=>dcyRequestRawDcft(action,params,write);return window.DCSaveFeedback?window.DCSaveFeedback.observe(action,run):run();}
async function dcyRequestRawDcft(action,params={},write=false){
 const body=new URLSearchParams({action,...params});
 if(write)body.set('csrf',window.DC_AUTH?.csrf||'');
 const r=await fetch('dc_youtube.php'+(write?'':'?'+body),write?{method:'POST',credentials:'same-origin',body,cache:'no-store'}:{credentials:'same-origin',cache:'no-store'});
 const j=await r.json();if(!r.ok||j.error)throw Error(j.error||'Error de YouTube');return j;
}
async function dcyConfigure(id){
 const node=allFoldersData.find(x=>Number(x.id)===Number(id));
 if(!node||!Number(node.is_dir)){alert('Seleccione una carpeta');return;}
 try{
  const status=await dcyRequest('status',{folder_id:String(id)});
  const enabled=!status.enabled;
  if(!confirm(enabled?'¿Clasificar esta carpeta como YouTube Links?':'¿Quitar la clasificación YouTube Links? (los enlaces se conservarán)'))return;
  await dcyRequest('set_mode',{folder_id:String(id),enabled:enabled?'1':'0'},true);
  if(typeof loadFiles==='function')await loadFiles();
  alert(enabled?'Carpeta configurada como YouTube Links':'Clasificación retirada');
 }catch(e){alert(e.message);}
}
async function dcyRefresh(){
 const id=Number(currentParentId||0);
 dcyState={folder:id,enabled:false};
 document.querySelectorAll('[data-dcy-package-hidden]').forEach(card=>{card.style.display=card.dataset.dcyPackageHidden===' '?'':card.dataset.dcyPackageHidden;delete card.dataset.dcyPackageHidden;});
 document.getElementById('dcyToolbar')?.remove();
 document.getElementById('dcOtherFilesTitle73')?.remove();
 // v75: los nodos previos pertenecen a la vista anterior: no devolverlos a fileGrid.
 document.getElementById('dcCollectionsSection69')?.remove();
 document.getElementById('dcyGrid')?.remove();document.querySelectorAll('.dcy-pagination').forEach(n=>n.remove());
 await dcyRestrictFolderMenus();
 if(id<1||isSearching)return;
 let status;try{status=await dcyRequest('status',{folder_id:String(id)});}catch(e){return;}
 if(id!==Number(currentParentId)||!status.enabled)return;
 dcyState={folder:id,enabled:true};
 const grid=document.getElementById('fileGrid')||document.querySelector('.file-grid');
 if(!grid)return;
 const bar=document.createElement('div');bar.id='dcyToolbar';bar.className='dcy-toolbar';
 bar.innerHTML='<strong>▶ YouTube Links</strong><div class="dcy-toolbar-actions"><button type="button" id="dcyAddButton">+ Agregar enlace</button><button type="button" id="dcyFolderButton">+ Crear subcarpeta</button><button type="button" id="dcyUploadButton">+ Subir archivo</button></div>';
 grid.parentNode.insertBefore(bar,grid);
 bar.querySelector('#dcyAddButton').onclick=()=>dcyAdd(id);
 bar.querySelector('#dcyFolderButton').onclick=()=>{const create=document.getElementById('createFolderBtn')||document.querySelector('[onclick*="createFolder"]');if(create)create.click();else if(typeof createFolder==='function')createFolder();else alert('Usa Crear carpeta desde el explorador');};

 bar.querySelector('#dcyUploadButton').onclick=()=>{const input=document.getElementById('fileInput');if(input)input.click();};
 const panel=document.createElement('div');panel.id='dcyGrid';panel.className='dcy-grid';grid.after(panel);
 await dcyList(id);
}
let dcyPage=1;
let dcyListGeneration=0;
async function dcyList(folder,page=dcyPage){
 const generation=++dcyListGeneration;
 // Recuperamos todos los enlaces de la carpeta para paginar conjuntamente
 // los enlaces YouTube y los videos locales, sin dejar tarjetas fuera del paginador.
 let data, links=[];
 try{
  let current=1,totalPages=1;
  do{
   data=await dcyRequest('list',{folder_id:String(folder),page:String(current),page_size:'120'});
   if(generation!==dcyListGeneration||folder!==Number(currentParentId))return;
   links.push(...(data.links||[]));
   totalPages=Math.max(1,Number(data.pages)||1);
   current++;
  }while(current<=totalPages && current<=30);
  if(current<=totalPages)console.warn('Drive-Craft: límite de 3600 enlaces alcanzado; se muestran los primeros.');
 }catch(e){console.warn('No se pudo listar YouTube Links',e);return;}
 dcyPage=Math.max(1,Number(page)||1);
 if(folder!==Number(currentParentId))return;
 const grid=document.getElementById('dcyGrid');if(!grid)return;
 // Same-folder refresh: preserve collection nodes and their native menus/events.
 const previousCollections=document.getElementById('dcCollectionsSection69');
 const nativeGrid=document.getElementById('fileGrid');
 if(previousCollections&&nativeGrid){
  for(const card of previousCollections.querySelectorAll('#dcCollectionsGrid69 > .file-item'))nativeGrid.append(card);
 }
 previousCollections?.remove();
 grid.replaceChildren();
 for(const item of links){
  const card=document.createElement('article');card.className='dcy-card';
  const img=document.createElement('img');img.loading='lazy';img.alt='Miniatura de YouTube';img.src='https://i.ytimg.com/vi/'+item.youtube_id+'/hqdefault.jpg';
  const title=document.createElement('div');title.className='dcy-title';title.textContent=item.title||item.name||('youtube_'+item.youtube_id);
  const desc=document.createElement('div');desc.className='dcy-description';desc.textContent=item.description||'';
  const dots=document.createElement('button');dots.type='button';dots.className='dcy-dots';dots.textContent='⋮';dots.title='Acciones del video';
  const menu=document.createElement('div');menu.className='dcy-menu';
  function action(label,run){const b=document.createElement('button');b.type='button';b.textContent=label;b.onclick=e=>{e.stopPropagation();menu.hidden=true;run();};menu.append(b);}
  action('▶ Reproducir',()=>dcyPlay(item.youtube_id,item.title));
  if(window.DC_AUTH?.role==='admin'||window.DC_AUTH?.canUpload)action('Comprobar disponibilidad',()=>window.DCMediaTools?.validateYoutube(item.youtube_id,item.title));
  if(window.DC_AUTH?.role==='admin'||window.DC_AUTH?.canUpload)action('Descargar video completo',()=>window.DCMediaTools?.openDownload({url:'https://www.youtube.com/watch?v='+item.youtube_id,title:item.title}));
  action('✏️ Editar título y descripción',()=>dcyEdit(folder,item));
  action('📁 Abrir paquete',()=>dcyOpenPackage(item.id));
  action('📎 Agregar archivos',()=>dcyAttach(folder,item));
  action('🗑️ Enviar a papelera',()=>dcyRemove(folder,item.id,item.title));
  menu.hidden=true;dots.onclick=e=>{e.stopPropagation();document.querySelectorAll('.dcy-menu').forEach(m=>{if(m!==menu)m.hidden=true;});menu.hidden=!menu.hidden;};
  const play=document.createElement('button');play.type='button';play.className='dcy-play';play.textContent='▶';play.title='Reproducir';play.onclick=()=>dcyPlay(item.youtube_id,item.title);
  const att=dc31AttachmentUI(item.attachments,async f=>{await dcyRequest('delete_attachment',{folder_id:String(folder),id:String(item.id),attachment_id:String(f.id)},true);await dcyList(folder);});
  card.append(img,play,dots,menu,title,desc,att);grid.append(card);
 }

 // Los videos locales se agregan antes de calcular las páginas.
 const normal=document.getElementById('fileGrid');
 if(normal){
  for(const card of normal.querySelectorAll('.file-item')){
   if(card.dataset.dcyPackageHidden){card.style.display=card.dataset.dcyPackageHidden===' '?'':card.dataset.dcyPackageHidden;delete card.dataset.dcyPackageHidden;}
  }
  for(const item of links){const card=document.getElementById('file-item-'+item.id);if(card){card.dataset.dcyPackageHidden=card.style.display||' ';card.style.display='none';}}
 }
 await dlPaint(folder);
 document.querySelectorAll(".dc-collection-toolbar65").forEach(n=>n.remove());
 if(typeof window.dcCollectionsPaint65==="function")await window.dcCollectionsPaint65(folder);
 if(generation!==dcyListGeneration||folder!==Number(currentParentId))return;
 // Las colecciones se presentan en su propia sección, sin ocupar una página de videos.
 const section=document.createElement('section');section.id='dcCollectionsSection69';section.className='dc-collections-section69';
 const heading=document.createElement('h3');heading.textContent='Colecciones';heading.className='dc-collections-heading69';
 const collectionGrid=document.createElement('div');collectionGrid.id='dcCollectionsGrid69';collectionGrid.className='dcy-grid dc-collections-grid69';
 section.append(heading,collectionGrid);
 // Colocar el bloque ANTES de la cuadrícula principal y de «Videos locales sin empaquetar».
 grid.before(section);
 if(typeof window.dcCollectionsDecorate67==='function'){
  await window.dcCollectionsDecorate67(folder);
  if(!collectionGrid.children.length)section.remove();
  if(generation!==dcyListGeneration||folder!==Number(currentParentId))return;
 }
 // Mantener la paginación compartida, pero dividir visualmente por tipo.
 const cards=Array.from(grid.querySelectorAll('.dcy-card'));
 const youtubeCards=cards.filter(c=>!c.classList.contains('dc-local-video-card'));
 const localCards=cards.filter(c=>c.classList.contains('dc-local-video-card') && !!c.dataset.dcVideoId);
 const unpackedCards=cards.filter(c=>c.classList.contains('dc-local-video-card') && !c.dataset.dcVideoId);
 const oldNote=grid.querySelector('.dc-local-note');oldNote?.remove();
 grid.classList.add('dc-sections73');
 const buildGroup=(label,items)=>{
  if(!items.length)return null;
  const section=document.createElement('section');section.className='dc-media-section73';
  const h=document.createElement('h3');h.className='dc-media-heading73';h.textContent=label;
  const inner=document.createElement('div');inner.className='dcy-grid dc-media-items73';
  for(const card of items)inner.append(card);
  section.append(h,inner);return section;
 };
 const youtubeSection=buildGroup('YouTube Links',youtubeCards);
 const localSection=buildGroup('Videos locales',localCards);
 const unpackedSection=buildGroup('Videos locales sin empaquetar',unpackedCards);
 // El renderizador de colecciones ya creó su sección. No mover ni duplicar archivos.
 const collections=document.getElementById('dcCollectionsSection69');
 grid.replaceChildren();
 for(const section of [youtubeSection,localSection,collections,unpackedSection])if(section)grid.append(section);
 // Los demás archivos permanecen dentro de fileGrid con sus manejadores originales.
 const fileGrid=document.getElementById('fileGrid');
 document.getElementById('dcOtherFilesTitle73')?.remove();
 if(fileGrid){
  fileGrid.before(grid);
  const other=Array.from(fileGrid.querySelectorAll(':scope > .file-item')).filter(card=>
   card.style.display!=='none' && !card.hidden && !card.classList.contains('dc-collection-folder67'));
  if(other.length){const h=document.createElement('h3');h.id='dcOtherFilesTitle73';h.className='dc-media-heading73 dc-others-heading73';h.textContent='Otros archivos';fileGrid.before(h);}
 }

 const pageSize=Math.max(1,Number(window.dcYoutubeDisplay54?.pageSize?.()||20));
 const totalPages=Math.max(1,Math.ceil(cards.length/pageSize));
 dcyPage=Math.min(totalPages,dcyPage);
 const from=(dcyPage-1)*pageSize;
 cards.forEach((card,i)=>{card.hidden=i<from||i>=from+pageSize;card.style.display=card.hidden?'none':'';});
 for(const section of grid.querySelectorAll('.dc-media-section73'))section.hidden=!section.querySelector('.dcy-card:not([hidden])');
 const pages=document.createElement('nav');pages.className='dcy-pagination';
 const b=(label,target,disabled)=>{const button=document.createElement('button');button.type='button';button.textContent=label;button.disabled=disabled;button.onclick=()=>dcyList(folder,target);return button;};
 pages.append(b('‹ Anterior',dcyPage-1,dcyPage<=1));
 const info=document.createElement('span');info.textContent='Página '+dcyPage+' de '+totalPages+' · '+cards.length+' tarjetas';
 pages.append(info,b('Siguiente ›',dcyPage+1,dcyPage>=totalPages));
 document.querySelectorAll('.dcy-pagination').forEach(n=>n.remove());grid.after(pages);

}
async function dcyAdd(folder){
 const url=prompt('Pega un enlace de YouTube (https://...)');if(url===null)return;
 const title=prompt('Título (opcional):','');if(title===null)return;
 const description=prompt('Descripción (opcional):','');if(description===null)return;
 try{await dcyRequest('add',{folder_id:String(folder),url,title,description},true);await loadFiles();await dcyList(folder,1);}catch(e){alert(e.message);}
}
async function dcyEdit(folder,item){
 const url=prompt('URL de YouTube:', 'https://www.youtube.com/watch?v='+item.youtube_id);if(url===null)return;
 const title=prompt('Título (opcional):',item.title||'');if(title===null)return;
 const description=prompt('Descripción del video:',item.description||'');if(description===null)return;
 try{await dcyRequest('edit',{folder_id:String(folder),id:String(item.id),url,title,description},true);await dcyList(folder);}catch(e){alert(e.message);}
}
async function dcyRemove(folder,id,title){
 if(!confirm('¿Enviar a la Papelera el paquete completo de "'+title+'" (video.url, metadata.json y su adjunto si existe)? Se restaurará como una carpeta.'))return;
 try{await dcyRequest('remove',{folder_id:String(folder),id:String(id)},true);await loadFiles();await dcyList(folder);}catch(e){alert(e.message);}
}
function dcyPlay(id,title){
 const old=document.getElementById('dcyPlayer');if(old)old.remove();
 const overlay=document.createElement('div');overlay.id='dcyPlayer';overlay.className='dcy-overlay';
 const inner=document.createElement('div');inner.className='dcy-player';
 const head=document.createElement('div');head.className='dcy-player-head';
 const name=document.createElement('strong');name.textContent=title;
 const close=document.createElement('button');close.textContent='✕ Cerrar';close.onclick=()=>overlay.remove();
 head.append(name,close);
 const iframe=document.createElement('iframe');iframe.src='https://www.youtube-nocookie.com/embed/'+id+'?autoplay=1';iframe.title=title;iframe.allow='accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share';iframe.allowFullscreen=true;iframe.referrerPolicy='strict-origin-when-cross-origin';
 inner.append(head,iframe);overlay.append(inner);overlay.addEventListener('click',e=>{if(e.target===overlay)overlay.remove();});document.body.append(overlay);
}

// Cerrar los menús cuando se hace clic fuera de las tarjetas.
document.addEventListener('click',e=>{if(!e.target.closest?.('.dcy-dots,.dcy-menu'))document.querySelectorAll('.dcy-menu').forEach(m=>m.hidden=true);});

async function dcyAttach(folder,item){
 const input=document.createElement('input');input.type='file';input.multiple=true;input.hidden=true;document.body.append(input);
 input.onchange=async()=>{const files=Array.from(input.files||[]);input.remove();if(!files.length)return;
  let completed=0;for(const file of files){const body=new FormData();body.append('action','attach');body.append('csrf',window.DC_AUTH?.csrf||'');body.append('folder_id',String(folder));body.append('id',String(item.id));body.append('attachment',file);
   try{const r=await fetch('dc_youtube.php',{method:'POST',credentials:'same-origin',body});const j=await r.json();if(!r.ok||j.error)throw Error(j.error||'Error al subir');completed++;}
   catch(e){alert('Se cargaron '+completed+' de '+files.length+' adjuntos. Falló «'+file.name+'»: '+e.message);break;}
  }await dcyList(folder);
 };input.click();
}

/* v20.10.23: ocultar opciones incompatibles en carpetas YouTube Links. */
async function dcyRestrictFolderMenus(){
 let response;
 try{response=await dcyRequest('modes');}catch(e){console.warn('Modos de carpetas:',e.message);return;}
 const ids=new Set((response.ids||[]).map(Number));
 for(const card of document.querySelectorAll('#fileGrid .file-item[id^="file-item-"]')){
  const id=Number(card.id.slice('file-item-'.length));
  if(!ids.has(id))continue;
  for(const entry of card.querySelectorAll('.context-menu-drive a')){
   if(/(?:Convertir en álbum de fotos|Quitar modo álbum|Recibir archivos aquí)/i.test(DCI18n.original(entry).trim()))entry.remove();
  }
 }
}

/* v20.10.25: sincronizar el árbol antes de abrir un paquete creado dinámicamente. */
async function dcyOpenPackage(id){
 const target=Number(id);
 try{
  if(!Number.isSafeInteger(target)||target<1)throw Error('Identificador inválido');
  // openFolder requiere la ruta completa en allFoldersData; el árbol puede estar obsoleto.
  if(typeof loadTree!=='function'||typeof openFolder!=='function')throw Error('Navegación no disponible');
  await loadTree();
  if(!Array.isArray(allFoldersData)||!allFoldersData.some(f=>Number(f.id)===target))
   throw Error('No se encontró la carpeta-paquete. Recarga la página e inténtalo nuevamente.');
  openFolder(target);
 }catch(err){console.error('Abrir paquete YouTube:',err);alert('No se pudo abrir el paquete: '+err.message);}
}
