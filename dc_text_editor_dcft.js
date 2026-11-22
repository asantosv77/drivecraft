/* Drive-Craft text/Markdown editing. Existing UTF-8 + SHA-256 save API remains authoritative. */
(() => {
 'use strict';
 let active=null,enginePromise=null,openSerial=0,opening=null;
 const node=(tag,className,text)=>{const el=document.createElement(tag);if(className)el.className=className;if(text!==undefined)el.textContent=text;return el;};
 function loadEngine(){
  if(window.DCRichMarkdown)return Promise.resolve(window.DCRichMarkdown);
  if(enginePromise)return enginePromise;
  enginePromise=new Promise((resolve,reject)=>{
   const script=document.createElement('script');script.src='vendor/dc-markdown-engine-2.js';
   const timer=setTimeout(()=>{script.remove();enginePromise=null;reject(Error('El editor visual tardó demasiado en cargar. Puedes usar el modo Texto.'));},20000);
   script.onload=()=>{clearTimeout(timer);if(window.DCRichMarkdown)resolve(window.DCRichMarkdown);else{enginePromise=null;reject(Error('No se pudo iniciar el editor visual.'));}};
   script.onerror=()=>{clearTimeout(timer);script.remove();enginePromise=null;reject(Error('No se pudo cargar el editor visual. Puedes usar el modo Texto.'));};
   document.head.append(script);
  });return enginePromise;
 }
 function canClose(){
  if(!active)return true;
  if(active.saving){active.message.textContent='Espera a que termine el guardado.';return false;}
  return !active.dirty()||confirm('Hay cambios sin guardar. ¿Quieres descartarlos y cerrar el editor?');
 }
 function destroy(){openSerial++;opening?.abort();opening=null;if(!active){document.getElementById('viewerModal')?.classList.remove('dc-text-editor-open');return;}active.destroy();active=null;document.getElementById('viewerModal')?.classList.remove('dc-text-editor-open');}
 async function open(id){
  if(active?.id===Number(id)){document.getElementById('viewerModal').style.display='block';return;}
  if(!canClose())return;destroy();const serial=++openSerial;
  const modal=document.getElementById('viewerModal'),body=document.getElementById('viewerBody');
  document.getElementById('viewerToolbar').style.display='none';document.getElementById('viewerTitle').textContent='Abriendo documento…';
  body.replaceChildren(node('p','dc-editor-loading','Cargando texto…'));modal.classList.add('dc-text-editor-open');modal.style.display='block';
  let record;const controller=new AbortController();opening=controller;const timeout=setTimeout(()=>controller.abort(),20000);
  try{const response=await fetch('api.php?action=text_open_57&id='+encodeURIComponent(id),{cache:'no-store',credentials:'same-origin',signal:controller.signal});record=await response.json();if(!response.ok||record.error)throw Error(record.error||'No se pudo abrir el documento (HTTP '+response.status+').');}
  catch(error){if(serial!==openSerial)return;body.replaceChildren(node('p','dc-editor-message',error.message||'No se pudo abrir el documento.'));return;}finally{clearTimeout(timeout);if(opening===controller)opening=null;}
  if(serial!==openSerial)return;
  const isMarkdown=/\.(md|markdown)$/i.test(record.name);
  let current=record.content,draft=current,hash=record.sha256,editing=false,wrap=false,rich=null,renderSerial=0,initialRich='',entryDraft='',previewTimer=null;
  const state={id:Number(id),saving:false,dirty:()=>draft!==current||!!rich?.storage.codeBlock?.hasUnsavedBlock?.(),message:null,destroy:()=>{renderSerial++;clearTimeout(previewTimer);rich?.destroy();rich=null;window.removeEventListener('beforeunload',beforeUnload);}};
  active=state;
  const beforeUnload=event=>{if(state.dirty()||state.saving){event.preventDefault();event.returnValue='';}};
  window.addEventListener('beforeunload',beforeUnload);
  document.getElementById('viewerTitle').textContent='Texto · '+record.name;
  const top=node('div','dc-text57-top dc-editor-top'),left=node('div','dc-editor-options'),actions=node('div','dc-text57-buttons');
  const mode=node('select');mode.setAttribute('aria-label','Modo del editor');
  for(const [value,label] of isMarkdown?[['visual','Texto enriquecido'],['md','Código Markdown + vista'],['text','Texto']]:[['text','Texto']]){const option=node('option','',label);option.value=value;mode.append(option);}
  mode.value=isMarkdown?'visual':'text';if(isMarkdown)left.append(mode);
  const wrapLabel=node('label','dc-editor-wrap'),wrapInput=node('input');wrapInput.type='checkbox';wrapInput.checked=false;wrapInput.id='dcEditorWrapDcft';wrapLabel.title='Solo ajusta las líneas a la ventana; no añade saltos al archivo.';wrapLabel.append(wrapInput,document.createTextNode(' Ajustar líneas (Wrap text)'));left.append(wrapLabel);
  const save=node('button','btn-primary','Editar'),cancel=node('button','','Cancelar');save.type=cancel.type='button';save.id='dcEditorSaveDcft';actions.append(save,cancel);top.append(left,actions);
  const message=node('p','dc-editor-message');message.setAttribute('role','status');message.setAttribute('aria-live','polite');state.message=message;
  const panel=node('div','dc-editor-panel');body.replaceChildren(top,message,panel);
  function readRich(){if(rich&&editing&&mode.value==='visual'){const value=rich.getMarkdown();draft=value===initialRich?entryDraft:value;}}
  function toolbar(editor,host){
   const bar=node('div','dc-rich-toolbar');bar.setAttribute('role','toolbar');bar.setAttribute('aria-label','Formato del texto');
   const commands=[
    ['↶','Deshacer',e=>e.chain().focus().undo().run()],['↷','Rehacer',e=>e.chain().focus().redo().run()],
    ['B','Negrita (Ctrl+B)',e=>e.chain().focus().toggleBold().run(),'bold'],['I','Cursiva (Ctrl+I)',e=>e.chain().focus().toggleItalic().run(),'italic'],['S̶','Tachado',e=>e.chain().focus().toggleStrike().run(),'strike'],
    ['≡','Lista con viñetas',e=>e.chain().focus().toggleBulletList().run(),'bulletList'],['1.','Lista numerada',e=>e.chain().focus().toggleOrderedList().run(),'orderedList'],['☑','Lista de tareas',e=>e.chain().focus().toggleTaskList().run(),'taskList'],
    ['❝','Cita',e=>e.chain().focus().toggleBlockquote().run(),'blockquote'],['<>','Código en línea',e=>e.chain().focus().toggleCode().run(),'code'],['{ }','Insertar o editar bloque de código',e=>{if(!e.isActive('codeBlock'))e.chain().focus().setCodeBlock().run();const sel=e.state.selection;const pos=sel.node?.type.name==='codeBlock'?sel.from:sel.$from.before();window.DCRichMarkdown.editBlock(e,pos);},'codeBlock'],
    ['—','Línea horizontal',e=>e.chain().focus().setHorizontalRule().run()],
    ['↗','Insertar o editar enlace',e=>{const url=prompt('Dirección del enlace (http, https o mailto). Vacío para quitarlo:',e.getAttributes('link').href||'');if(url===null)return;if(!url.trim()){e.chain().focus().extendMarkRange('link').unsetLink().run();return;}if(!window.DCRichMarkdown.safeLink(url.trim())){message.textContent='El enlace debe usar http, https o mailto.';return;}e.chain().focus().extendMarkRange('link').setLink({href:url.trim()}).run();},'link'],
    ['▦','Insertar tabla de 3 × 3',e=>e.chain().focus().insertTable({rows:3,cols:3,withHeaderRow:true}).run()],
    ['+ fila','Añadir fila a la tabla',e=>e.chain().focus().addRowAfter().run(),'table'],['+ col.','Añadir columna a la tabla',e=>e.chain().focus().addColumnAfter().run(),'table'],['− tabla','Eliminar tabla',e=>e.chain().focus().deleteTable().run(),'table']
   ];
   const blocks=node('select');blocks.setAttribute('aria-label','Tipo de párrafo');for(const [value,label] of [['0','Párrafo'],['1','Título 1'],['2','Título 2'],['3','Título 3'],['4','Título 4']]){const option=node('option','',label);option.value=value;blocks.append(option);}blocks.onchange=()=>{const level=Number(blocks.value);if(level)editor.chain().focus().toggleHeading({level}).run();else editor.chain().focus().setParagraph().run();};bar.append(blocks);
   const buttons=[];
   for(const [label,title,command,mark] of commands){const b=node('button','',label);b.type='button';b.title=title;b.setAttribute('aria-label',title);b.onmousedown=event=>event.preventDefault();b.onclick=()=>{command(editor);update();};bar.append(b);buttons.push({b,mark,label});}
   function update(){for(const {b,mark,label} of buttons){if(mark)b.setAttribute('aria-pressed',String(editor.isActive(mark)));if(['+ fila','+ col.','− tabla'].includes(label))b.disabled=!editor.isActive('table');}blocks.value=String(editor.getAttributes('heading').level||0);}
   editor.on('selectionUpdate',update);editor.on('transaction',update);update();host.prepend(bar);
  }
  async function render(){
   const renderId=++renderSerial;clearTimeout(previewTimer);rich?.destroy();rich=null;panel.replaceChildren();
   save.textContent=editing?'Guardar':'Editar';cancel.hidden=!editing;wrapLabel.hidden=!editing||mode.value==='visual';
   if(isMarkdown&&mode.value!=='text'){
    panel.append(node('p','dc-editor-loading','Preparando editor Markdown…'));
    try{await loadEngine();}catch(error){if(renderId!==renderSerial)return;message.textContent=error.message;mode.value='text';render();return;}
    if(renderId!==renderSerial||active!==state)return;
    const reason=window.DCRichMarkdown.visualLimit(draft);
    mode.querySelector('[value="visual"]').disabled=!!reason;
    if(reason&&mode.value==='visual'){mode.value='md';message.textContent=reason;}
    panel.replaceChildren();
    if(mode.value==='visual'){
     const host=node('div','dc-rich-host'),content=node('div','dc-rich-content');host.append(content);panel.append(host);entryDraft=draft;let initializing=true;
     rich=window.DCRichMarkdown.create(content,draft,editing,editor=>{if(initializing)return;const value=editor.getMarkdown();draft=value===initialRich?entryDraft:value;},()=>{},async pos=>{if(active!==state)return;editing=true;draft=current;await render();if(active===state&&rich)window.DCRichMarkdown.editBlock(rich,pos);});initialRich=rich.getMarkdown();initializing=false;
     if(editing)toolbar(rich,host);return;
    }
   }
   if(!editing){const pre=node('pre','dc-code-preview dc-text57-code');pre.textContent=draft;panel.append(pre);return;}
   const markdown=isMarkdown&&mode.value==='md',container=node('div','dc-edit85-wrap'+(markdown?' dc-edit85-markdown':'')),source=node('div','dc-edit85-source'+(wrap?' is-wrap':'')),pre=node('pre','dc-edit85-highlight'),code=node('code'),area=node('textarea','dc-text57-area dc-edit85-input');
   pre.setAttribute('aria-hidden','true');pre.append(code);area.value=draft;area.spellcheck=false;area.wrap=wrap?'soft':'off';area.setAttribute('aria-label','Contenido de '+record.name);
   const language=typeof dcSyntaxLanguage==='function'?dcSyntaxLanguage(record.name,'text/plain'):'';
   const paint=()=>{if(language&&typeof dcHighlightText==='function')code.innerHTML=dcHighlightText(draft+'\n',language);else code.textContent=draft+'\n';};
   area.oninput=()=>{draft=area.value;paint();if(previewTimer)clearTimeout(previewTimer);if(markdown)previewTimer=setTimeout(()=>{if(renderId===renderSerial&&rich)rich.commands.setContent(draft,{contentType:'markdown',emitUpdate:false});},180);};
   area.onscroll=()=>{pre.scrollTop=area.scrollTop;pre.scrollLeft=area.scrollLeft;};source.append(pre,area);container.append(source);panel.append(container);paint();
   if(markdown){const preview=node('div','dc-rich-preview');container.append(preview);rich=window.DCRichMarkdown.create(preview,draft,false);}
   wrapInput.onchange=()=>{wrap=wrapInput.checked;source.classList.toggle('is-wrap',wrap);area.wrap=wrap?'soft':'off';pre.scrollTop=area.scrollTop;pre.scrollLeft=area.scrollLeft;};
  }
  let previousMode=mode.value;
  mode.onchange=async()=>{
   const next=mode.value;mode.value=previousMode;readRich();mode.value=next;await render();previousMode=mode.value;
  };
  save.onclick=async()=>{
   if(state.saving)return;
   if(!editing){editing=true;draft=current;message.textContent='';render();return;}
   readRich();if(new TextEncoder().encode(draft).byteLength>2*1024*1024){message.textContent='El contenido supera el límite de 2 MiB.';return;}
   state.saving=true;save.disabled=cancel.disabled=mode.disabled=true;save.classList.add('dc-editor-saving');save.textContent='Guardando…';message.textContent='Guardando cambios…';
   try{
    const fd=new FormData();fd.set('action','text_save_57');fd.set('id',id);fd.set('sha256',hash);fd.set('content',draft);fd.set('csrf',window.DC_AUTH?.csrf||'');
    const response=await fetch('api.php',{method:'POST',body:fd,credentials:'same-origin',headers:{'X-DC-CSRF':window.DC_AUTH?.csrf||''},signal:AbortSignal.timeout(30000)});
    const data=await response.json();if(!response.ok||data.error)throw Error(data.error||'No se pudo guardar (HTTP '+response.status+').');
    hash=data.sha256;current=draft;editing=false;await render();save.textContent='✓ Guardado';message.textContent=data.unchanged?'El archivo ya estaba actualizado.':'Cambios guardados correctamente.';window.DCSaveFeedback?.notice('Nota guardada correctamente.');
    if(typeof loadFiles==='function')loadFiles();
   }catch(error){message.textContent=(error.name==='AbortError'?'No se pudo confirmar el guardado a tiempo.':error.message)+' Tus cambios siguen en el editor.';save.textContent='Reintentar guardado';}
   finally{state.saving=false;save.disabled=cancel.disabled=mode.disabled=false;save.classList.remove('dc-editor-saving');}
  };
  cancel.onclick=()=>{readRich();if(draft!==current&&!confirm('¿Descartar los cambios y cancelar la edición?'))return;draft=current;editing=false;message.textContent='Edición cancelada.';render();};
  await render();previousMode=mode.value;
 }
 window.DCTextEditor={open,canClose,destroy};
})();
