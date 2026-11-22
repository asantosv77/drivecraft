/* Decoración sin reconstruir tarjetas ni árbol, ni interferir con reproductores. */
(()=>{
 let data={},timer=null;
 const ICON_MODE85='css'; // Alterna CSS e imágenes con aplicar.py
 const youtubeSvg='<svg viewBox="0 0 32 23" xmlns="http://www.w3.org/2000/svg" aria-hidden="true"><rect x="0" y="0" width="32" height="23" rx="5" fill="#e62117"/><path d="M13 6 L23 11.5 L13 17Z" fill="white"/></svg>';
 // Iconos compactos construidos con CSS local, sin imágenes ni Tailwind.
 function compactIcon(kind,folderId=null){
  const root=document.createElement('span');
  root.className='dc-compact85e dc-compact-'+kind+'85e';
  root.setAttribute('aria-hidden','true');
  const tab=document.createElement('span');tab.className='dc-compact-tab85e';
  const folder=document.createElement('span');folder.className='dc-compact-folder85e';
  root.append(tab,folder);
  if(kind==='music'){
   const band=document.createElement('span');band.className='dc-compact-band85e';
   const left=document.createElement('span');left.className='dc-compact-ear85e dc-ear-left85e';
   const right=document.createElement('span');right.className='dc-compact-ear85e dc-ear-right85e';
   const face=document.createElement('span');face.className='dc-compact-face85e';
   folder.append(face);root.append(band,left,right);
  }else{
   for(let i=0;i<3;i++){
    const photo=document.createElement('span');photo.className='dc-compact-photo85e dc-photo-'+i+'85e';
    folder.append(photo);
   }
  }
  if(kind==='photo'&&Number(folderId)>0)queueMicrotask(()=>window.DCAlbumCovers?.observe(root,folderId));
  return root;
 }
 document.documentElement.dataset.dcFolderIconMode85=ICON_MODE85;
 if(!document.getElementById('dcIconsToggle85Style')){const style=document.createElement('style');style.id='dcIconsToggle85Style';style.textContent='html[data-dc-folder-icon-mode85="css"] .file-view-grid .dc-folder-music .dc-compact85e,html[data-dc-folder-icon-mode85="css"] .file-view-grid .dc-folder-photo .dc-compact85e{width:76px;height:76px}html[data-dc-folder-icon-mode85="css"] .dc-folder-music>.dc-compact85e,html[data-dc-folder-icon-mode85="css"] .dc-folder-photo>.dc-compact85e{width:76px;height:76px}html[data-dc-folder-icon-mode85="images"] .dc-tree-music img,html[data-dc-folder-icon-mode85="images"] .dc-tree-photo img{width:24px;height:22px;object-fit:contain}';document.head.append(style);}

 function decorate(){
  const grid=document.getElementById('fileGrid');
  if(grid)for(const [id,d] of Object.entries(data)){
   const card=document.getElementById('file-item-'+id);if(!card||!card.isConnected||card.dataset.dciShortcut)continue;
   // El explorador reconstruye tarjetas; no duplicar iconos ni alterar el árbol.
   card.querySelectorAll('.dc-folder-type').forEach(n=>n.remove());
   card.classList.remove('dc-special-folder-list');
   if(d.kind==='folder' && !window.dcMusicLibraryIds82?.has(Number(id)))continue;
   const visual=card.querySelector('.dc-version-visual')||card.querySelector('.file-icon')||card.firstElementChild;
   const name=card.querySelector('.file-name');
   if(!visual)continue;
   const el=document.createElement('span');
   const kind=window.dcMusicLibraryIds82?.has(Number(id))?'music':d.kind;
   el.className='dc-folder-type '+(kind==='youtube'?'dc-folder-youtube':kind==='music'?'dc-folder-music':'dc-folder-photo');
   if(kind==='youtube')el.innerHTML=youtubeSvg;
   else if(kind==='music'||kind==='album'){if(ICON_MODE85==='css'||card.closest('.file-view-list')&&ICON_MODE85==='css')el.append(compactIcon(kind==='music'?'music':'photo',id));else {const img=document.createElement('img');img.src=kind==='music'?'dc_music_folder85d.png':'dc_photo_folder85d.png';img.alt='';img.className='dc-folder-art85d';el.append(img);}}
   else{
    if(d.cover_id){const img=document.createElement('img');img.loading='lazy';img.alt='Portada de álbum';img.src='api.php?action=download&id='+encodeURIComponent(d.cover_id);el.append(img);}
    else el.textContent='🖼️';
    const mark=document.createElement('span');mark.className='dc-folder-mark';mark.textContent='📁';el.append(mark);
   }
   if(card.closest('.file-view-list')&&name){
    // Colocar el icono en el espacio normal, antes del nombre (no al final de la fila).
    card.classList.add('dc-special-folder-list');
    name.before(el);
   }else{
    // En cuadrícula, conservar el icono grande superpuesto.
    card.append(el);
   }
  }
  const tree=document.getElementById('folderTree');if(!tree)return;
  for(const label of tree.querySelectorAll('.tree-label[onclick]')){
   const match=label.getAttribute('onclick')?.match(/clickTreeFolder\((\d+)\)/);if(!match)continue;
   const d=data[match[1]];const el=label.querySelector('.tree-icon');if(!el)continue;
   el.querySelector('.dc-tree-type')?.remove();
   if(!d||(d.kind==='folder'&&!window.dcMusicLibraryIds82?.has(Number(match[1]))))continue;
   // Sustituir el emoji de carpeta original (📁🎧), no superponer otra carpeta.
   // Los badges de versión y favoritos permanecen intactos.
   for(const node of [...el.childNodes])if(node.nodeType===Node.TEXT_NODE)node.remove();
   el.querySelector('.dc-album-tree-mark')?.remove();
   const mark=document.createElement('span');const kind=window.dcMusicLibraryIds82?.has(Number(match[1]))?'music':d.kind;
   mark.className='dc-tree-type '+(kind==='youtube'?'dc-tree-youtube':kind==='music'?'dc-tree-music':'dc-tree-photo');
   if(kind==='youtube')mark.innerHTML=youtubeSvg;
   else if(kind==='music'||kind==='album'){if(ICON_MODE85==='css')mark.append(compactIcon(kind==='music'?'music':'photo',match[1]));else{const img=document.createElement('img');img.src=kind==='music'?'dc_music_folder85d.png':'dc_photo_folder85d.png';img.alt='';img.className='dc-folder-art85d';mark.append(img);}}
   else {if(d.cover_id){const img=document.createElement('img');img.loading='lazy';img.alt='';img.src='api.php?action=download&id='+d.cover_id;mark.append(img);}else mark.textContent='🖼️';const badge=document.createElement('span');badge.className='dc-tree-folder-mark';badge.textContent='📁';mark.append(badge);}
   el.append(mark);
  }
 }
 async function refresh(){try{const r=await fetch('dc_folder_styles.php',{credentials:'same-origin',cache:'no-store'});if(!r.ok)return;const json=await r.json();if(!json.success)return;data=json.items||{};decorate();}catch(e){console.warn('Tipos de carpeta:',e.message);}}
 // Los renderizadores originales mantienen el control del árbol y del explorador.
 if(typeof renderFiles==='function'){const prev=renderFiles;renderFiles=function(...args){const ret=prev.apply(this,args);queueMicrotask(decorate);return ret;};}
 if(typeof renderTree==='function'){const prev=renderTree;renderTree=function(...args){const ret=prev.apply(this,args);queueMicrotask(decorate);return ret;};}
 if(typeof dcyConfigure==='function'){const prev=dcyConfigure;dcyConfigure=async function(...args){const ret=await prev.apply(this,args);await refresh();return ret;};}
 if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',refresh,{once:true});else refresh();
 setInterval(refresh,60000);
 window.dcRefreshFolderStyles=refresh;
})();
