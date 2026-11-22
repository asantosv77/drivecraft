/* Drive-Craft 20.10.31: listado compacto y accesible de adjuntos. */
function dc31AttachmentUI(attachments, onDelete) {
 const files=Array.isArray(attachments)?attachments:[];
 const wrapper=document.createElement('div');wrapper.className='dc31-attachment-wrap';
 if(!files.length){const label=document.createElement('span');label.className='dc31-no-attachments';label.textContent='Sin adjuntos';wrapper.append(label);return wrapper;}
 const trigger=document.createElement('button');trigger.type='button';trigger.className='dc31-attachment-toggle';trigger.textContent='📎 '+files.length+' '+(files.length===1?'archivo adjunto':'archivos adjuntos')+' ▾';trigger.setAttribute('aria-expanded','false');
 const pop=document.createElement('div');pop.className='dc31-attachment-list';pop.hidden=true;pop.setAttribute('role','group');pop.setAttribute('aria-label','Archivos adjuntos');
 const close=()=>{pop.hidden=true;trigger.setAttribute('aria-expanded','false');};
 trigger.addEventListener('click',e=>{e.preventDefault();e.stopPropagation();const opening=pop.hidden;document.querySelectorAll('.dc31-attachment-list:not([hidden])').forEach(n=>{n.hidden=true;n.parentNode.querySelector('.dc31-attachment-toggle')?.setAttribute('aria-expanded','false');});pop.hidden=!opening;trigger.setAttribute('aria-expanded',String(opening));});
 for(const f of files){const line=document.createElement('div');line.className='dc31-attachment-line';const link=document.createElement('a');link.textContent='⬇ '+f.name;link.title='Descargar '+f.name;link.href='api.php?action=download&id='+encodeURIComponent(f.id)+'&download=1';link.setAttribute('download','');const size=document.createElement('small');const n=Number(f.size)||0;size.textContent=n<1024?n+' B':n<1048576?(n/1024).toFixed(1)+' KiB':n<1073741824?(n/1048576).toFixed(1)+' MiB':(n/1073741824).toFixed(1)+' GiB';line.append(link,size);
 if(onDelete&&window.DC_AUTH?.canDelete){const del=document.createElement('button');del.type='button';del.className='dc31-attachment-delete';del.title='Enviar adjunto a la Papelera';del.textContent='🗑';del.onclick=async e=>{e.stopPropagation();if(!confirm('¿Enviar a la Papelera el archivo «'+f.name+'»?'))return;del.disabled=true;try{await onDelete(f);}catch(err){alert(err.message);del.disabled=false;}};line.append(del);}pop.append(line);}
 wrapper.append(trigger,pop);
 // Un único listener global se instala fuera de las tarjetas.
 return wrapper;
}

if(!window.dc31AttachmentOutsideHandler){
 window.dc31AttachmentOutsideHandler=e=>{
  for(const item of document.querySelectorAll('.dc31-attachment-wrap')){
   if(item.contains(e.target))continue;
   const list=item.querySelector('.dc31-attachment-list');if(list)list.hidden=true;
   item.querySelector('.dc31-attachment-toggle')?.setAttribute('aria-expanded','false');
  }
 };
 document.addEventListener('pointerdown',window.dc31AttachmentOutsideHandler);
}
