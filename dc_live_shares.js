/* Actualiza distintivos y retira accesos revocados sin destruir el explorador. */
(()=>{
 let pending=false;
 async function check(){
  if(document.hidden||pending)return;pending=true;
  try{
   if(typeof dcRefreshShareBadges==='function')await dcRefreshShareBadges();
   const grid=document.getElementById('fileGrid');if(!grid||typeof dciRequest!=='function'||typeof currentParentId==='undefined')return;
   const parent=Number(currentParentId);
   const result=await dciRequest('shortcuts',{parent_id:parent});
   if(parent!==Number(currentParentId)||!grid.isConnected)return;
   const allowed=new Set((result.items||[]).map(item=>String(item.id)));
   const existing=[...grid.querySelectorAll('[data-dci-shortcut]')];
   for(const card of existing){
    if(!allowed.has(card.dataset.dciShortcut)){
     // Nunca cerrar el visor ni interrumpir una descarga; revocación siempre del lado servidor.
     card.remove();
    }
   }
   if((result.items||[]).some(f=>!existing.some(card=>card.dataset.dciShortcut===String(f.id)))){
    // Solo reconstruye las tarjetas de accesos si hay nuevas, sin tocar las tarjetas propias.
    if(typeof dciShowShortcuts==='function')await dciShowShortcuts(parent);
   }
  }catch(e){console.warn('Sincronización de compartidos:',e.message);}finally{pending=false;}
 }
 setInterval(check,15000);
 document.addEventListener('visibilitychange',()=>{if(!document.hidden)check();});
 window.dcRefreshSharedState=check;
})();
