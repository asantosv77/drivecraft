/* Drivecraft UI localization. Stored content and control values are never translated. */
(()=>{'use strict';
 const languages=['es','en'],cookie='dc_language_dcft',sources=new WeakMap(),attributes=new WeakMap();
 let language=document.cookie.split(';').map(x=>x.trim()).find(x=>x.startsWith(cookie+'='))?.split('=')[1];
 if(!languages.includes(language))language='es';
 let dictionary={},reverse={},observer=null,ready=false;
 const pending=new Set();let scheduled=false;
 const protectedContent='.dcmt-result,.dc-music-card81>strong,[data-dc-no-i18n],.file-name,.folder-title,.tree-text,#folderTree,.dc-search-virtual-path,.dcy-title,.dcy-description,.dc-collection-label67,.dc-collection-description71,.dc-music-title81,.dc-music-playlist81,.dc31-attachment-list,.dc-rich-content,.dc-rich-preview,.dc-rich-surface,.tiptap,.ProseMirror,.dc-mermaid-preview,.dc-code-preview,pre,code,textarea,input,[contenteditable="true"],script,style,svg,canvas';
 const ui='button,a,label,option,h1,h2,h3,h4,h5,th,summary,[role="status"],[role="alert"],.context-menu-drive,.dcy-menu,.dc-music-menu81,.dc-settings-group,.dcmt-pane,.dc79-dialog,.dc-save-notice,.dc-sidebar-fab-menu,.dc-readonly-notice,.login-container,.dc-utility-modal,.modal-content,.dc-yt55-dialog,.dc31-no-attachments,.dc-local-preview-state,.dc-local-conversion-state,.dc-local-badge,.dc-local-note,.dc-media-heading73,.dc-collections-heading69,.dc-about84-dialog,#diskSpaceWatermark,#pasteToolbar,#loginError,#dcPasswordError,#dcUtilityTitle,#multiSelectCount';
 function canonical(value){if(Object.hasOwn(reverse,value))return reverse[value];const m=value.trim().match(/^((?:\p{Extended_Pictographic}|[^\p{L}\p{N}])*)(.*?)(\s*[▾↗ⓘ]*)$/u);return m&&Object.hasOwn(reverse,m[2])?value.replace(value.trim(),m[1]+reverse[m[2]]+m[3]):value;}
 function translate(value){
  const raw=String(value??''),trim=raw.trim();if(language==='es')return canonical(raw);
  if(Object.hasOwn(dictionary,trim))return raw.replace(trim,dictionary[trim]);
  const photos=trim.match(/^(\d+) fotografías?( · solo miniaturas en caché)?$/);if(photos)return raw.replace(trim,photos[1]+' '+(photos[1]==='1'?'photo':'photos')+(photos[2]?' · cached thumbnails only':''));
  // Numeric storage summaries are UI templates, not file content.
  const size='([\\d.,]+\\s(?:B|KB|MB|GB|TB|PB|KiB|MiB|GiB|TiB))';let storage;
  if((storage=trim.match(new RegExp('^'+size+' libres de '+size+'$'))))return raw.replace(trim,storage[1]+' free of '+storage[2]);
  if((storage=trim.match(/^(\d+(?:[.,]\d+)?)% utilizado$/)))return raw.replace(trim,storage[1]+'% used');
  if((storage=trim.match(new RegExp('^'+size+' usados · '+size+' disponibles de '+size+'$'))))return raw.replace(trim,storage[1]+' used · '+storage[2]+' available of '+storage[3]);
  if((storage=trim.match(new RegExp('^Mis archivos: '+size+' \\(incluye historial y papelera\\)$'))))return raw.replace(trim,'My files: '+storage[1]+' (includes history and trash)');
  // Preserve decorative symbols and punctuation around a catalogued UI label.
  const m=trim.match(/^((?:\p{Extended_Pictographic}|[^\p{L}\p{N}])*)(.*?)(\s*[▾↗ⓘ]*)$/u);
  if(m&&Object.hasOwn(dictionary,m[2]))return raw.replace(trim,m[1]+dictionary[m[2]]+m[3]);
  const count=trim.match(/^(\d+) (archivos adjuntos|archivo adjunto|episodios|episodio|pistas|pista|archivos|seleccionados)$/);
  if(count){const units={'archivos adjuntos':'attachments','archivo adjunto':'attachment',episodios:'episodes',episodio:'episode',pistas:'tracks',pista:'track',archivos:'files',seleccionados:'selected'};return raw.replace(trim,count[1]+' '+units[count[2]]);}
  return raw;
 }
 // Other modules use source labels for legacy classification, never the displayed language.
 function original(node){
  if(!node)return '';
  if(node.nodeType===Node.TEXT_NODE){const r=sources.get(node);return r&&node.data===r.rendered?r.source:canonical(node.data);}
  return Array.from(node.childNodes||[],original).join('');
 }
 function updateText(node){
  const parent=node.parentElement;if(!parent||(!parent.matches('[data-dc-i18n-root]')&&parent.closest(protectedContent))||(!parent.matches('[data-dc-i18n-root],.dc-collection-meta67,.dc-music-card81>small')&&!parent.closest('#dcAlbumArtBar')&&!parent.closest(ui)))return;
  // The first breadcrumb is application UI; all later breadcrumb names are user data.
  if(parent.closest('#breadcrumb')&&!parent.closest('.context-menu-drive')&&!parent.matches('[data-dc-i18n-root]'))return;
  if(parent.closest('select[aria-label="Seleccionar episodio"],select[aria-label="Select episode"]'))return;
  const old=sources.get(node);const source=old&&node.data===old.rendered?old.source:canonical(node.data);
  const rendered=language==='es'?source:translate(source);sources.set(node,{source,rendered});if(node.data!==rendered)node.data=rendered;
 }
 function updateAttributes(element){
  if(element.closest('.file-name,.folder-title,.tree-text,#folderTree,.dcy-title,.dcy-description,.dc-collection-label67,.dc-rich-surface,.ProseMirror,[data-dc-no-i18n]'))return;
  if(element.matches('option')&&element.closest('#dcmtAiModels'))return;
  let record=attributes.get(element);if(!record){record={};attributes.set(element,record);}
  for(const name of ['title','placeholder','aria-label']){
   if(!element.hasAttribute(name))continue;
   const current=element.getAttribute(name),old=record[name],source=old&&current===old.rendered?old.source:canonical(current);
   const rendered=language==='es'?source:translate(source);record[name]={source,rendered};if(current!==rendered)element.setAttribute(name,rendered);
  }
 }
 function apply(root=document.body){
  if(!ready||!root)return;
  if(root.nodeType===Node.TEXT_NODE){updateText(root);return;}
  if(root.nodeType!==Node.ELEMENT_NODE&&root.nodeType!==Node.DOCUMENT_NODE)return;
  if(root.nodeType===Node.ELEMENT_NODE)updateAttributes(root);
  for(const element of root.querySelectorAll('[title],[placeholder],[aria-label]'))updateAttributes(element);
  const walker=document.createTreeWalker(root,NodeFilter.SHOW_TEXT);let node;while((node=walker.nextNode()))updateText(node);
 }
 function flush(){scheduled=false;const roots=[...pending];pending.clear();for(const root of roots)if(root.isConnected)apply(root);}
 function schedule(root){pending.add(root);if(!scheduled){scheduled=true;queueMicrotask(flush);}}
 function controls(){for(const button of document.querySelectorAll('[data-dc-language]'))button.setAttribute('aria-pressed',String(button.dataset.dcLanguage===language));document.documentElement.lang=language;}
 function persist(){const path=new URL('.',location.href).pathname;document.cookie=cookie+'='+language+'; Max-Age=31536000; Path='+path+'; SameSite=Lax'+(location.protocol==='https:'?'; Secure':'');}
 async function setLanguage(value){if(!languages.includes(value))return;await window.dcI18nReady;language=value;persist();controls();apply();document.dispatchEvent(new CustomEvent('drivecraft:language',{detail:{language,locale:language==='en'?'en-US':'es-MX'}}));}
 window.DCI18n={get language(){return language;},get locale(){return language==='en'?'en-US':'es-MX';},t:translate,original,apply,setLanguage};
 // Translate dialog messages only; user-entered prompt defaults are untouched.
 for(const key of ['alert','confirm','prompt']){const native=window[key].bind(window);window[key]=(message,...args)=>native(translate(message),...args);}
 window.dcI18nReady=fetch('dc_i18n_dcft.php?lang=en&v=20261121-7',{credentials:'same-origin',cache:'default'}).then(r=>{if(!r.ok)throw Error('i18n');return r.json();}).then(data=>{dictionary=data;reverse=Object.fromEntries(Object.entries(data).map(([es,en])=>[en,es]));}).catch(()=>{}).then(()=>{ready=true;controls();if(document.body)apply();});
 document.addEventListener('click',event=>{const button=event.target.closest('[data-dc-language]');if(button)setLanguage(button.dataset.dcLanguage);});
 function start(){controls();window.dcI18nReady.then(()=>apply());observer=new MutationObserver(records=>{for(const r of records){if(r.type==='childList'){for(const n of r.addedNodes)schedule(n);}else schedule(r.target);}});observer.observe(document.body,{subtree:true,childList:true,characterData:true,attributes:true,attributeFilter:['title','placeholder','aria-label']});}
 if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',start,{once:true});else start();
})();
