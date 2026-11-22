/* Server-confirmed save feedback with pulse and confirmation states. */
(() => {
 'use strict';const active=new WeakMap();let recentButton=null,recentAt=0;
 document.addEventListener('click',e=>{const b=e.target.closest?.('button,input[type=submit]');if(b&&/guardar|aplicar|aceptar|confirmar|usar como portada/i.test(DCI18n.original(b)||b.value||'')){recentButton=b;recentAt=Date.now();}},true);
 function notice(message='Cambios guardados correctamente.',error=false){
  const old=document.getElementById('dcSaveToastDcft');if(old){clearTimeout(old.dismissTimer);old.remove();}
  const n=document.createElement('div');n.id='dcSaveToastDcft';n.className='dc-save-toast'+(error?' is-error':'');n.setAttribute('role',error?'alert':'status');
  const icon=document.createElement('span');icon.textContent=error?'!':'✓';icon.className='dc-save-check';const text=document.createElement('span');text.textContent=message;
  const close=document.createElement('button');close.type='button';close.textContent='×';close.setAttribute('aria-label','Cerrar aviso');close.onclick=()=>n.remove();n.append(icon,text,close);document.body.append(n);
  n.dismissTimer=setTimeout(()=>{n.classList.add('is-fading');setTimeout(()=>n.remove(),600);},error?8000:3800);
 }
 function begin(button){
  if(!button)return{success:message=>notice(message),error:e=>notice(e?.message||String(e||'No se pudo guardar.'),true)};
  active.get(button)?.reset();const label=button.textContent,disabled=button.disabled;let timer=null;
  button.classList.add('dc-save-busy');button.setAttribute('aria-busy','true');button.disabled=true;
  const h={reset(){clearTimeout(timer);button.classList.remove('dc-save-busy','dc-save-done');button.removeAttribute('aria-busy');button.disabled=disabled;button.textContent=label;if(active.get(button)===h)active.delete(button);},success(message='Cambios guardados correctamente.'){
   button.classList.remove('dc-save-busy');button.classList.add('dc-save-done');button.removeAttribute('aria-busy');button.disabled=disabled;button.textContent='✓ Guardado';notice(message);timer=setTimeout(h.reset,3500);
  },error(e){h.reset();notice(e?.message||String(e||'No se pudo guardar.'),true);}};active.set(button,h);return h;
 }
 const accepted=action=>!/progress|position|playback/i.test(action)&&/^(?:save|save_.+|.+_save|update_user|set_cover|cover_from_library|set_collection_hf3|set_mode|rename|set_album|set_cover_layout|edit|upload_cover)$/.test(action);
 async function observe(action,operation){
  if(!accepted(action))return operation();const b=Date.now()-recentAt<2500&&recentButton?.isConnected?recentButton:null,h=begin(b);
  try{const data=await operation();if(data?.success===false||data?.error)throw Error(data.error||'No se pudo guardar.');h.success();return data;}catch(e){h.error(e);throw e;}
 }
 window.DCSaveFeedback={begin,notice,observe};
})();
