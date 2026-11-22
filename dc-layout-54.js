/* Drive-Craft v20.10.54: preferencias locales de presentación. */
(function(){
'use strict';
const items=[['card','Ancho de tarjetas YouTube (px)',220,500,350],['icon','Tamaño del botón de reproducción (px)',30,76,50],['rows','Filas de tarjetas antes del paginador',1,12,3]];
function value(key){const cfg=items.find(x=>x[0]===key);let x=Number(localStorage.getItem('dc_yt_'+key));if(!Number.isFinite(x)||x<cfg[2]||x>cfg[3])return cfg[4];return Math.round(x);}
function apply(){document.documentElement.style.setProperty('--dc-youtube-card-width',value('card')+'px');document.documentElement.style.setProperty('--dc-youtube-icon-size',value('icon')+'px');}
function columns(){const g=document.getElementById('dcyGrid');const width=g?.clientWidth||document.getElementById('fileGrid')?.clientWidth||1000;return Math.max(1,Math.floor((width+12)/(value('card')+12)));}
function pageSize(){return Math.max(1,Math.min(120,columns()*value('rows')));}
window.dcYoutubeDisplay54={apply,pageSize,value};apply();
window.dc85gApply=function(){const root=document.documentElement;const bounded=(key,min,max,def)=>{const v=Number(localStorage.getItem('dc85g_'+key));return v>=min&&v<=max?v:def};root.style.setProperty('--dc85g-music-width',bounded('musicWidth',190,480,255)+'px');root.style.setProperty('--dc85g-collection-width',bounded('collectionWidth',220,550,260)+'px');root.style.setProperty('--dc85g-music-fit',localStorage.getItem('dc85g_musicFit')==='cover'?'cover':'contain');};window.dc85gApply();
// dcyRequest declara una función global en dc_youtube.js; anexamos page_size a la consulta list.
if(typeof dcyRequest==='function'){
 const original=dcyRequest;
 dcyRequest=async function(action,params={},write=false){
  if(action==='list'&&!write)return original(action,{...params,page_size:String(pageSize())},write);
  return original(action,params,write);
 };
}
function openVideoSettings(){
 const shade=document.createElement('div');shade.className='dc-yt55-shade';
 const dialog=document.createElement('section');dialog.className='dc-yt55-dialog';dialog.setAttribute('role','dialog');dialog.setAttribute('aria-modal','true');dialog.setAttribute('aria-label','Personalizar tarjetas');
 const head=document.createElement('div');head.className='dc-yt55-head';const heading=document.createElement('h3');heading.textContent='Personalizar tarjetas';const cross=document.createElement('button');cross.type='button';cross.textContent='×';cross.title='Cerrar';head.append(heading,cross);dialog.append(head);
 const note=document.createElement('p');note.textContent='Preferencias visuales independientes por tipo de tarjeta, guardadas en este navegador.';dialog.append(note);
 const edited={};
 const groups=document.createElement('div');groups.className='dc85g-tabs';const tabs=[['youtube','Videos YouTube'],['music','Música'],['collection','Colecciones']];
 const panels=document.createElement('div');dialog.append(groups,panels);
 const sections={};
 for(const [key,label] of tabs){const btn=document.createElement('button');btn.type='button';btn.textContent=label;btn.className='dc85g-tab';btn.onclick=()=>{groups.querySelectorAll('button').forEach(b=>b.classList.toggle('active',b===btn));Object.entries(sections).forEach(([n,el])=>el.hidden=n!==key)};groups.append(btn);const panel=document.createElement('div');panel.className='dc85g-tabpanel';panel.hidden=key!=='youtube';panels.append(panel);sections[key]=panel;if(key==='youtube')btn.classList.add('active');}
 for(const [key,label,min,max] of items){
  const row=document.createElement('div');row.className='dc-yt55-row';const top=document.createElement('div');top.className='dc-yt55-top';const tag=document.createElement('label');const range=document.createElement('input');range.type='range';range.id='dc-yt55-'+key;range.min=String(min);range.max=String(max);range.step=key==='card'?'10':key==='icon'?'2':'1';range.value=String(value(key));tag.htmlFor=range.id;tag.textContent=label;const current=document.createElement('output');current.textContent=range.value+(key==='rows'?'':' px');edited[key]=Number(range.value);range.oninput=()=>{edited[key]=Number(range.value);current.textContent=range.value+(key==='rows'?'':' px')};top.append(tag,current);row.append(top,range);sections.youtube.append(row);
 }
 const specs=[['music','musicWidth','Ancho de álbum musical (px)',190,480,255,10],['music','musicRows','Filas antes de paginar (referencia visual)',1,8,2,1],['collection','collectionWidth','Ancho de colección de video (px)',220,550,260,10],['collection','collectionRows','Filas antes de paginar (referencia visual)',1,8,2,1]];
 for(const [tab,key,label,min,max,def,step] of specs){const stored=Number(localStorage.getItem('dc85g_'+key));const val=Number.isFinite(stored)&&stored>=min&&stored<=max?stored:def;edited[key]=val;const row=document.createElement('div');row.className='dc-yt55-row';const top=document.createElement('div');top.className='dc-yt55-top';const title=document.createElement('label');title.textContent=label;const output=document.createElement('output');output.textContent=val+(key.includes('Width')?' px':'');const input=document.createElement('input');input.type='range';input.min=min;input.max=max;input.step=step;input.value=val;input.oninput=()=>{edited[key]=Number(input.value);output.textContent=input.value+(key.includes('Width')?' px':'')};top.append(title,output);row.append(top,input);sections[tab].append(row)}
 const coverRow=document.createElement('div');coverRow.className='dc-yt55-row';const coverLabel=document.createElement('label');coverLabel.textContent='Encuadre de portadas';const coverSelect=document.createElement('select');for(const [v,t] of [['contain','Mostrar portada completa'],['cover','Rellenar marco con recorte']]){const o=document.createElement('option');o.value=v;o.textContent=t;coverSelect.append(o)}coverSelect.value=localStorage.getItem('dc85g_musicFit')||'contain';coverRow.append(coverLabel,coverSelect);sections.music.append(coverRow);
 const foot=document.createElement('div');foot.className='dc-yt55-foot';const cancel=document.createElement('button');cancel.type='button';cancel.textContent='Cancelar';const save=document.createElement('button');save.type='button';save.textContent='Guardar';const close=()=>shade.remove();cancel.onclick=close;cross.onclick=close;save.onclick=()=>{for(const [key,v] of Object.entries(edited))localStorage.setItem((key==='card'||key==='icon'||key==='rows'?'dc_yt_':'dc85g_')+key,String(v));localStorage.setItem('dc85g_musicFit',coverSelect.value);apply();window.dc85gApply?.();close();/* No llamar dcyList: reconstruía el contenedor y ocultaba Colecciones hasta la siguiente visita. */};foot.append(cancel,save);dialog.append(foot);shade.append(dialog);shade.onclick=e=>{if(e.target===shade)close()};shade.onkeydown=e=>{if(e.key==='Escape')close()};document.body.append(shade);cross.focus();
}
function settings(){
 const form=document.getElementById('dcHardlinksForm');
 if(form && !form.querySelector('.dc-hl-column-title54')){
  const title=Array.from(form.querySelectorAll('h5')).find(x=>DCI18n.original(x).includes('Tipos'));
  if(title){const label=document.createElement('span');label.className='dc-hl-column-title54';label.textContent='Mínimo (MiB)';title.append(label);}
 }
 const title=document.getElementById('dcUtilityTitle');const body=document.getElementById('dcUtilityBody');
 if(!body||!DCI18n.original(title).includes('Panel de configuración')||body.querySelector('#dcYouTubeSettings54'))return;
 const presentation=Array.from(body.querySelectorAll('.dc-settings-group')).find(s=>DCI18n.original(s.querySelector('h3')).includes('Presentación'));
 if(!presentation)return;
 const section=document.createElement('div');section.id='dcYouTubeSettings54';section.className='dc-youtube-settings54';
 const button=document.createElement('button');button.type='button';button.className='dc-yt55-trigger';button.textContent='Personalizar tarjetas';button.addEventListener('click',openVideoSettings);section.append(button);
 presentation.append(section);
}
new MutationObserver(settings).observe(document.body,{childList:true,subtree:true});
// Tras cambios de anchura de ventana, al volver a navegar la paginación usa el ancho actual.
})();
