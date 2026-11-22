
/* Viewport-bound menus; restore the original DOM node and handlers on close. */
(()=>{'use strict';
 const floated=new Map();let scheduled=false;
 const selector='.context-menu-drive.show,.dcy-menu:not([hidden]),.dc-music-menu81:not([hidden])';
 function visible(menu){return menu.matches(selector)&&menu.isConnected;}
 function restore(menu,record){menu.classList.remove('dc-context-floating','dc-context-columns');if(record.marker.isConnected){record.marker.replaceWith(menu);if(record.style===null)menu.removeAttribute('style');else menu.setAttribute('style',record.style);}else menu.remove();floated.delete(menu);}
 function fit(menu,anchor){
  const pad=8,vw=document.documentElement.clientWidth,vh=window.innerHeight;
  menu.classList.remove('dc-context-columns');
  menu.style.setProperty('width',Math.min(260,vw-2*pad)+'px','important');
  menu.style.setProperty('left','8px','important');menu.style.setProperty('top','8px','important');
  let rect=menu.getBoundingClientRect();
  if(rect.height>vh-2*pad&&vw>=480){menu.classList.add('dc-context-columns');menu.style.setProperty('width',Math.min(520,vw-2*pad)+'px','important');rect=menu.getBoundingClientRect();}
  // Very short viewports: scale the entire menu, keeping every action accessible.
  const scale=Math.min(1,(vh-2*pad)/Math.max(1,rect.height));
  menu.style.setProperty('transform',scale<1?'scale('+scale+')':'none','important');menu.style.transformOrigin='top left';
  const width=rect.width*scale,height=rect.height*scale;
  const a=anchor.getBoundingClientRect();
  const x=Math.max(pad,Math.min(a.right-width,vw-width-pad));
  let y=a.bottom+4;if(y+height>vh-pad)y=a.top-height-4;
  y=Math.max(pad,Math.min(y,vh-height-pad));
  menu.style.setProperty('left',Math.round(x)+'px','important');menu.style.setProperty('top',Math.round(y)+'px','important');
 }
 window.dcFloatContextMenu=(menu,anchor)=>{
  if(!anchor||floated.has(menu))return;
  const marker=document.createComment('context-menu-home');const style=menu.getAttribute('style');menu.before(marker);
  floated.set(menu,{marker,style,anchor});menu.classList.add('dc-context-floating');document.body.append(menu);fit(menu,anchor);
 };
 function sync(){scheduled=false;
  for(const [menu,record] of floated){if(!visible(menu)||!record.marker.isConnected)restore(menu,record);}
  for(const menu of document.querySelectorAll(selector)){
   if(floated.has(menu)||menu.closest('.modal,.dcmt-overlay,.dcy-overlay'))continue;
   const card=menu.closest('.file-item,.dcy-card,.dc-music-card81');
   const anchor=card?.querySelector('.menu-dots,.dcy-dots,.dc-music-dots81')||menu.previousElementSibling;
   if(anchor)window.dcFloatContextMenu(menu,anchor);
  }
 }
 const observer=new MutationObserver(()=>{if(!scheduled){scheduled=true;queueMicrotask(sync);}});
 observer.observe(document.body,{subtree:true,childList:true,attributes:true,attributeFilter:['class','hidden']});
 function close(){for(const [menu,record] of [...floated]){menu.classList.remove('show');if(menu.matches('.dcy-menu,.dc-music-menu81'))menu.hidden=true;restore(menu,record);}}
 document.addEventListener('keydown',e=>{if(e.key==='Escape')close();});
 document.addEventListener('click',e=>{if(e.target.closest('.dc-context-floating,.menu-dots,.dcy-dots'))return;close();},true);
 window.addEventListener('resize',close);document.addEventListener('scroll',close,true);
})();
