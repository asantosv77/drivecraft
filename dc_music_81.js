/* Drive-Craft v20.10.81a: música; reanudación y guardado serializado (Optimizado con carga de letras opcional y control de karaoke vinculado). */
(()=>{'use strict';
const endpoint='dc_music_81.php';
let player=null,active=null,lastSaved=0,pending=Promise.resolve(),token=0;const selectors=new Map();let miniMode=false,miniXY=null;

function req(action,params={},post=false){const run=()=>reqRawDcft(action,params,post);return post&&window.DCSaveFeedback?window.DCSaveFeedback.observe(action,run):run();}
function reqRawDcft(action,params={},post=false){const p=new URLSearchParams({action,...params});if(post)p.set('csrf',window.DC_AUTH?.csrf||'');return fetch(endpoint+(post?'':'?'+p),post?{method:'POST',body:p,credentials:'same-origin',cache:'no-store'}:{credentials:'same-origin',cache:'no-store'}).then(async r=>{const raw=await r.text();let j;try{j=JSON.parse(raw);}catch{throw Error('Respuesta inválida de biblioteca musical (HTTP '+r.status+')');}if(!r.ok||j.error)throw Error(j.error||'Error de biblioteca');return j;});}
function node(tag,cls,txt){const e=document.createElement(tag);if(cls)e.className=cls;if(txt!==undefined)e.textContent=txt;return e;}

window.dcMusicLibraryIds82=new Set();
window.dcMusicLoadLibraryIds82=async()=>{try{const r=await req('library_ids');window.dcMusicLibraryIds82=new Set((r.ids||[]).map(Number));}catch(e){console.warn('Indicadores de bibliotecas',e);}};
window.dcMusicConfigure81=async id=>{try{const s=await req('status',{folder_id:String(id)});if(!confirm(s.enabled?'¿Desactivar biblioteca musical? Los archivos no se eliminarán.':'¿Configurar esta carpeta como biblioteca musical? Sus subcarpetas con audio aparecerán como álbumes.'))return;await req('set_mode',{folder_id:String(id),enabled:s.enabled?'0':'1'},true);await window.dcMusicLoadLibraryIds82();await loadFiles();}catch(e){alert(e.message);}};

function fmt(s){s=Math.max(0,Math.floor(Number(s)||0));return Math.floor(s/60)+':'+String(s%60).padStart(2,'0');}
function enqueue(snapshot){if(!snapshot)return pending;pending=pending.catch(()=>{}).then(()=>req('save_progress',snapshot,true)).catch(e=>{console.warn('No se guardó progreso musical',e);throw e;});return pending;}
function remember(force=false){if(!player||!active||!player.src||!active.ready||active.album.standalone)return pending;const now=Date.now();if(!force&&now-lastSaved<8000)return pending;lastSaved=now;return enqueue({album_folder_id:String(active.album.folder_id),track_id:String(active.track.id),seconds:String(Math.max(0,Number(player.currentTime)||0))});}

function close(save){
    document.querySelector('.dc-karaoke85m')?.remove();
    if(save)remember(true);
    const old=player;
    player=null;
    active=null;
    token++;
    if(old){old.onpause=null;old.pause();old.removeAttribute('src');old.load();}
    document.querySelector('.dc-music-overlay81')?.remove();
    syncSelection(null);
}

function syncSelection(album,index){
  for(const [id,el] of selectors){
    if(!el.isConnected){selectors.delete(id);continue;}
    if(!(album&&Number(id)===Number(album.folder_id))) continue;
    if(el.tagName==='SELECT'){el.value=String(index);continue;}
    if(el.classList?.contains('dc-music-playlist81')){
      el.querySelectorAll('[data-track-i]').forEach(li=>{
        const i=Number(li.dataset.trackI);
        li.classList.toggle('is-playing',i===Number(index));
        if(i===Number(index)) li.classList.add('is-selected');
      });
    }
  }
}
function applyMiniXY(overlay){if(!overlay)return;if(miniMode&&miniXY){overlay.style.left=miniXY.x+'px';overlay.style.top=miniXY.y+'px';overlay.style.right='auto';overlay.style.bottom='auto';}else{overlay.style.left='';overlay.style.top='';overlay.style.right='';overlay.style.bottom='';}}

function enableDrag(head,overlay,miniButton,closeButton){let drag=null;head.classList.add('dc-music-drag-handle82a');head.addEventListener('pointerdown',e=>{if(e.target.closest('button')||!overlay.classList.contains('dc-music-mini82')||e.button!==0)return;const r=overlay.getBoundingClientRect();drag={id:e.pointerId,dx:e.clientX-r.left,dy:e.clientY-r.top};head.setPointerCapture(e.pointerId);e.preventDefault();});head.addEventListener('pointermove',e=>{if(!drag||e.pointerId!==drag.id)return;const width=overlay.offsetWidth,height=overlay.offsetHeight;const x=Math.min(Math.max(0,e.clientX-drag.dx),Math.max(0,innerWidth-width));const y=Math.min(Math.max(0,e.clientY-drag.dy),Math.max(0,innerHeight-height));miniXY={x,y};applyMiniXY(overlay);});const end=e=>{if(drag&&e.pointerId===drag.id)drag=null;};head.addEventListener('pointerup',end);head.addEventListener('pointercancel',end);}

function play(album,index,seconds=0,forceMini=false){
    if(!album.tracks[index])return;
    const priorOverlay=document.querySelector('.dc-music-overlay81');
    if(priorOverlay)miniMode=priorOverlay.classList.contains('dc-music-mini82');
    if(forceMini)miniMode=true;
    close(true);
    const ticket=token;
    const track=album.tracks[index];
    active={album,index,track,ready:false};
    syncSelection(album,index);
    window.dispatchEvent(new CustomEvent('dc-music-track83',{detail:{track,album}}));

    const overlay=node('div','dc-music-overlay81'),
          panel=node('div','dc-music-player81'),
          title=node('h3','',album.standalone?track.name:album.title+' - '+track.name),
          subtitle=node('p','',track.name),
          closeB=node('button','','✕');

    const miniB=node('button','','▁');
    miniB.title='Minimizar reproductor';
    miniB.onclick=()=>{
        miniMode=!overlay.classList.contains('dc-music-mini82');
        overlay.classList.toggle('dc-music-mini82',miniMode);
        applyMiniXY(overlay);
        miniB.textContent=miniMode?'▣':'▁';
        miniB.title=miniMode?'Ampliar reproductor':'Minimizar reproductor';
    };

    closeB.title='Cerrar reproductor';
    closeB.onclick=()=>close(true);

    // Configuración del audio (Inicia inmediatamente sin bloquear con letras)
    const audio=node('audio','');
    audio.controls=true;
    audio.preload='metadata';
    audio.src='api.php?action=download&id='+encodeURIComponent(track.id);
    player=audio;

    const requested=Math.max(0,Number(seconds)||0);
    let seeked=requested===0;

    function ready(){
        if(ticket!==token||player!==audio)return;
        if(!seeked){
            const max=Number.isFinite(audio.duration)?Math.max(0,audio.duration-0.75):requested;
            try{audio.currentTime=Math.min(requested,max);}catch(e){console.warn('No se pudo recuperar la posición',e);}
        }
        active.ready=true;
        lastSaved=Date.now();
        audio.play().catch(()=>{});
    }

    audio.addEventListener('loadedmetadata',()=>{
        if(requested>0&&audio.seekable?.length===0){
            audio.addEventListener('canplay',ready,{once:true});
        }else ready();
    },{once:true});

    audio.addEventListener('error',()=>{
        if(ticket!==token)return;
        const p=document.querySelector('#dcMusicProgress81');
        if(p)p.textContent='No se pudo cargar el audio. Comprueba formato y acceso.';
    });

    audio.addEventListener('timeupdate',()=>{
        if(ticket!==token||!active?.ready)return;
        remember();
    });

    audio.addEventListener('ended',()=>{
        if(ticket!==token)return;
        const next=index+1;
        if(next<album.tracks.length)play(album,next,0);
        else{remember(true);}
    });

    audio.addEventListener('pause',()=>{if(ticket===token)remember(true);});

    const previous=node('button','','⏮');
    previous.title='Anterior';
    previous.setAttribute('aria-label','Anterior');
    previous.onclick=()=>play(album,Math.max(0,index-1),0);

    const nextB=node('button','','⏭');
    nextB.title='Siguiente';
    nextB.setAttribute('aria-label','Siguiente');
    nextB.onclick=()=>{if(index+1<album.tracks.length)play(album,index+1,0);};
    nextB.disabled=index+1>=album.tracks.length;

    const progress=node('span','','');
    progress.id='';

    // Lista bajo el reproductor: 1 toque selecciona, doble toque reproduce.
    let pendingIndex = index;
    const isMobileUi = () => window.matchMedia('(max-width: 640px)').matches;
    const playlist=node('div','dc-music-playlist81');
    playlist.setAttribute('role','listbox');
    playlist.setAttribute('aria-label','Lista de la colección');
    const list=node('ul','dc-music-playlist-ul81');
    album.tracks.forEach((t,i)=>{
        const li=node('li','dc-music-playlist-item81',`${i+1}. ${t.name}`);
        li.dataset.trackI=String(i);
        li.setAttribute('role','option');
        li.tabIndex=0;
        if(i===index){li.classList.add('is-selected','is-playing');}
        const pick=()=>{
            pendingIndex=i;
            list.querySelectorAll('.dc-music-playlist-item81').forEach(n=>n.classList.toggle('is-selected',Number(n.dataset.trackI)===i));
            updateListToggleLabel();
        };
        const startTrack=()=>{
            pendingIndex=i;
            pick();
            if(i===index && player){player.play().catch(()=>{});return;}
            play(album,i,0);
        };
        li.addEventListener('click',pick);
        li.addEventListener('dblclick',e=>{e.preventDefault();startTrack();});
        let lastTap=0;
        li.addEventListener('touchend',e=>{
            const now=Date.now();
            if(now-lastTap<320 && pendingIndex===i){
                e.preventDefault();
                lastTap=0;
                startTrack();
            }else{
                lastTap=now;
                pick();
            }
        },{passive:false});
        li.addEventListener('keydown',e=>{
            if(e.key==='Enter'){e.preventDefault();startTrack();}
            if(e.key===' '){e.preventDefault();pick();}
        });
        list.append(li);
    });
    playlist.append(list);
    selectors.set('player-'+Number(album.folder_id), playlist);
    const scrollPlayingIntoView=()=>{
        const item=list.querySelector('.dc-music-playlist-item81.is-playing')
            || list.querySelector('.dc-music-playlist-item81.is-selected');
        if(!item || playlist.hidden || playlist.clientHeight<8) return;
        const box=playlist.getBoundingClientRect();
        const row=item.getBoundingClientRect();
        const offset=(row.top-box.top)+playlist.scrollTop-(playlist.clientHeight/2)+(item.offsetHeight/2);
        const max=Math.max(0, playlist.scrollHeight-playlist.clientHeight);
        playlist.scrollTop=Math.max(0, Math.min(max, offset));
    };

    // ⏮ ⏭: no existen en el control nativo <audio>; son de la colección.
    const controls=node('div','dc-music-controls81');
    controls.append(previous,nextB,progress);

    let showLyricsEnabled = localStorage.getItem('dc_show_lyrics') === 'true';
    const checkboxContainer = node('div', 'dc-music-lyrics-toggle-container');
    checkboxContainer.style.cssText = 'display: flex; align-items: center; gap: 8px; margin: 6px 0; font-size: 12px; color: #aebed0;';
    const lrclibCheckbox = document.createElement('input');
    lrclibCheckbox.type = 'checkbox';
    lrclibCheckbox.id = 'toggle-lrclib-lyrics';
    lrclibCheckbox.checked = showLyricsEnabled;
    const lrclibLabel = document.createElement('label');
    lrclibLabel.htmlFor = 'toggle-lrclib-lyrics';
    lrclibLabel.textContent = 'Mostrar letra desde LRCLIB';
    lrclibLabel.style.cursor = 'pointer';
    checkboxContainer.append(lrclibCheckbox, lrclibLabel);
    controls.append(checkboxContainer);

    // Escritorio: pestaña inferior para mostrar/ocultar lista (preferencia localStorage).
    // Móvil: lista siempre desplegada (salvo letra activa).
    const listToggle=node('button','dc-music-list-toggle81','');
    listToggle.type='button';
    const updateListToggleLabel=()=>{
        const t=album.tracks[pendingIndex]||album.tracks[index];
        const name=t?`${(pendingIndex>=0?pendingIndex:index)+1}. ${t.name}`: 'Lista';
        const open=panel.classList.contains('dc-music-list-open81');
        listToggle.innerHTML='<span class="dc-music-list-toggle-label81"></span><span class="dc-music-list-toggle-chevron81"></span>';
        listToggle.querySelector('.dc-music-list-toggle-label81').textContent=name;
        listToggle.querySelector('.dc-music-list-toggle-chevron81').textContent=open?'▲':'▼';
        listToggle.title=open?'Ocultar lista de la colección':'Mostrar lista de la colección';
        listToggle.setAttribute('aria-expanded', open?'true':'false');
    };
    const applyListOpenState=()=>{
        const mobile=isMobileUi();
        let open;
        if(showLyricsEnabled){
            open=false;
        }else if(mobile){
            open=true;
        }else{
            open=localStorage.getItem('dc_music_playlist_open')!=='0';
        }
        panel.classList.toggle('dc-music-list-open81', open);
        playlist.hidden=!open;
        playlist.setAttribute('aria-hidden', open?'false':'true');
        listToggle.hidden=showLyricsEnabled;
        if(mobile) listToggle.classList.add('dc-music-list-toggle-mobile-hidden81');
        else listToggle.classList.remove('dc-music-list-toggle-mobile-hidden81');
        updateListToggleLabel();
    };
    listToggle.addEventListener('click',()=>{
        if(isMobileUi()||showLyricsEnabled) return;
        const next=!panel.classList.contains('dc-music-list-open81');
        localStorage.setItem('dc_music_playlist_open', next?'1':'0');
        applyListOpenState();
        if(next) requestAnimationFrame(()=>{requestAnimationFrame(scrollPlayingIntoView);});
    });

    const head=node('div','dc-music-head81');
    head.append(title,miniB,closeB);

    // Orden: cabecera → título pista → audio → controles → pestaña lista → lista → letras
    panel.append(head,subtitle,audio,controls,listToggle,playlist);

    const ext=node('section','dc-music-extras83');
    ext.id='dcMusicExtras83';
    const art=node('img','dc-music-trackart83');
    art.alt='Carátula de la canción';
    art.hidden=true;
    const lyrics=node('div','dc-music-lyrics83','');
    ext.append(art,lyrics);
    panel.append(ext);

    const applyPlaylistLyricsVisibility=()=>applyListOpenState();
    if(!showLyricsEnabled){
        ext.style.display = 'none';
    }
    applyListOpenState();

    dcKaraokeSetup85m(panel,ext,album,track,overlay,lrclibCheckbox);

    // Lógica al cambiar el estado del checkbox
    lrclibCheckbox.onchange = (e) => {
        showLyricsEnabled = e.target.checked;
        localStorage.setItem('dc_show_lyrics', showLyricsEnabled);
        
        applyPlaylistLyricsVisibility();
        if(showLyricsEnabled){
            ext.style.display = 'flex';
            window.dcMusicRenderTrack83?.(track, album, audio, ext);
        }else{
            ext.style.display = 'none';
            lyrics.innerHTML = '';
        }
    };

    // Renderizar letras de forma asíncrona solo si el usuario tiene activo el checkbox
    if(showLyricsEnabled){
        window.dcMusicRenderTrack83?.(track, album, audio, ext);
    }

    overlay.append(panel);
    overlay.addEventListener('click',e=>{
        if(e.target===overlay&&!overlay.classList.contains('dc-music-mini82'))overlay.classList.add('dc-music-mini82');
    });
    document.body.append(overlay);
    requestAnimationFrame(()=>{requestAnimationFrame(scrollPlayingIntoView);});
    overlay.classList.toggle('dc-music-mini82',miniMode);
    miniB.textContent=miniMode?'▣':'▁';
    miniB.title=miniMode?'Ampliar reproductor':'Minimizar reproductor';
    applyMiniXY(overlay);
    enableDrag(head,overlay,miniB,closeB);
    audio.load();
}

window.dcMusicPlayFile81=(id,name,folder)=>{
 if(!Number.isInteger(id)||id<1)return;
 play({folder_id:Number(folder)||0,title:String(name),tracks:[{id,name:String(name)}],standalone:true},0,0,true);
};

async function chooseCover(album){try{const r=await req('images');const items=r.items||[];const modal=node('div','dc-music-choose82'),box=node('div','dc-music-choose-box82'),top=node('div','dc-music-choose-top82'),h=node('strong','','Elegir portada desde DC'),x=node('button','','✕'),filter=node('input'),list=node('div','dc-music-choose-list82');filter.placeholder='Filtrar imágenes de mi unidad';x.onclick=()=>modal.remove();top.append(h,x);box.append(top,filter,list);modal.append(box);document.body.append(modal);const render=()=>{list.replaceChildren();for(const f of items.filter(f=>f.name.toLocaleLowerCase().includes(filter.value.toLocaleLowerCase())).slice(0,300)){const b=node('button','dc-music-image-option82'),img=node('img'),label=node('span','',f.name);img.src='api.php?action=download&id='+encodeURIComponent(f.id);img.loading='lazy';b.append(img,label);b.onclick=async()=>{try{await req('set_cover',{album_folder_id:String(album.folder_id),image_id:String(f.id)},true);modal.remove();await window.dcMusicRefresh81();}catch(e){alert(e.message);}};list.append(b);}};filter.oninput=render;render();}catch(e){alert(e.message);}}

const coverKey85m=id=>'dc85m_music_cover_layout_'+String(id);
function coverSettings85m(id){
  try{const v=JSON.parse(localStorage.getItem(coverKey85m(id))||'null');return v&&typeof v==='object'?{x:Math.max(0,Math.min(100,Number(v.x)||50)),y:Math.max(0,Math.min(100,Number(v.y)||50)),zoom:Math.max(50,Math.min(200,Number(v.zoom)||50))}:{x:50,y:50,zoom:50};}
  catch{return {x:50,y:50,zoom:50};}
}
function applyCover85m(img,id){const p=coverSettings85m(id);img.style.setProperty('object-fit','contain','important');img.style.setProperty('object-position','center center','important');img.style.setProperty('transform','translate('+(50-p.x)+'%,'+(50-p.y)+'%) scale('+(p.zoom/100)+')','important');img.style.transformOrigin='center center';}
function editCover85m(album){
  if(!album.cover_id){alert('Primero elige una portada para esta colección musical.');return;}
  document.querySelector('.dc-music-cover-overlay85m')?.remove();
  const wrap=node('div','dc-music-cover-overlay85m'),panel=node('div','dc-music-cover-panel85m'),title=node('h3','','Ajustar encuadre: '+album.title),hint=node('p','','Arrastra para centrar la portada y utiliza el zoom. La imagen original no se modifica.'),frame=node('div','dc-music-cover-frame85m'),img=node('img');
  img.src='api.php?action=download&id='+encodeURIComponent(album.cover_id);img.alt='Portada de '+album.title;img.draggable=false;frame.append(img);
  const controls=node('div','dc-music-cover-controls85m'),zoom=node('input'),zoomText=node('span'),buttons=node('div','dc-music-cover-buttons85m');zoom.type='range';zoom.min='50';zoom.max='200';zoom.step='5';
  const center=node('button','','Centrar imagen'),save=node('button','','Guardar'),cancel=node('button','','Cancelar');
  const p=coverSettings85m(album.folder_id);let x=p.x,y=p.y,z=p.zoom,drag=null;
  const clamp=v=>Math.max(0,Math.min(100,v));
  const render=()=>{img.style.objectFit='contain';img.style.objectPosition='center center';img.style.transform='translate('+(50-x)+'%,'+(50-y)+'%) scale('+(z/100)+')';img.style.transformOrigin='center center';zoom.value=String(z);zoomText.textContent='Zoom '+Math.round(z)+'%';};
  frame.addEventListener('pointerdown',e=>{if(e.button!==0||!img.naturalWidth)return;drag={id:e.pointerId,cx:e.clientX,cy:e.clientY,x,y};frame.setPointerCapture(e.pointerId);e.preventDefault();});
  frame.addEventListener('pointermove',e=>{if(!drag||e.pointerId!==drag.id)return;const r=frame.getBoundingClientRect();if(!r.width||!r.height)return;x=clamp(drag.x-(e.clientX-drag.cx)*100/r.width);y=clamp(drag.y-(e.clientY-drag.cy)*100/r.height);render();});
  const end=e=>{if(drag&&drag.id===e.pointerId)drag=null;};frame.addEventListener('pointerup',end);frame.addEventListener('pointercancel',end);
  zoom.oninput=()=>{z=Number(zoom.value)||100;render();};center.onclick=()=>{x=50;y=50;z=50;render();};
  const dismiss=()=>wrap.remove();cancel.onclick=dismiss;wrap.addEventListener('click',e=>{if(e.target===wrap)dismiss();});
  save.onclick=()=>{try{localStorage.setItem(coverKey85m(album.folder_id),JSON.stringify({x:Math.round(x),y:Math.round(y),zoom:z}));document.querySelectorAll('.dc-music-card81[data-album-folder-id="'+Number(album.folder_id)+'"] .dc-music-image-frame81>img').forEach(image=>applyCover85m(image,album.folder_id));dismiss();}catch(err){alert('No se pudo guardar el encuadre en este navegador: '+err.message);}};
  controls.append(zoom,zoomText);buttons.append(center,save,cancel);panel.append(title,hint,frame,controls,buttons);wrap.append(panel);document.body.append(wrap);render();
}

async function resume(album){try{await pending.catch(()=>{});const r=await req('progress',{album_folder_id:String(album.folder_id)});const p=r.progress;const i=album.tracks.findIndex(t=>Number(t.id)===Number(p?.track_id));play(album,i>=0?i:0,i>=0?Number(p?.position_seconds)||0:0);}catch(e){alert('No se pudo continuar: '+e.message);}}

window.dcMusicCloseMenus81=()=>document.querySelectorAll('.dc-music-menu81').forEach(m=>m.hidden=true);
document.addEventListener('pointerdown',e=>{if(!e.target.closest('.dc-music-menu81,.dc-music-poster81>button'))window.dcMusicCloseMenus81();},true);

window.dcMusicRefresh81=async()=>{document.querySelector('#dcMusicSection81')?.remove();const folder=Number(window.currentParentId??currentParentId??0);if(folder<1||window.isSearching)return;let data;try{const status=await req('status',{folder_id:String(folder)});if(!status.enabled)return;data=await req('list',{folder_id:String(folder)});}catch(e){console.warn('Biblioteca musical',e);return;}
if(folder!==Number(window.currentParentId??currentParentId))return;const grid=document.getElementById('fileGrid');if(!grid)return;data.albums=(data.albums||[]).filter(album=>Number(album.folder_id)!==folder);if(!data.albums.length)return;const section=node('section','dc-music-section81');section.id='dcMusicSection81';section.append(node('h3','','♫ Álbumes · Biblioteca musical'));const cards=node('div','dc-music-cards81');section.append(cards);
for(const album of data.albums){const card=node('article','dc-music-card81'),poster=node('div','dc-music-poster81');card.dataset.albumFolderId=String(album.folder_id);if(album.cover_id){const img=node('img');img.loading='lazy';img.alt='Portada de '+album.title;img.src='api.php?action=download&id='+encodeURIComponent(album.cover_id);const imageFrame=node('div','dc-music-image-frame81');imageFrame.append(img);poster.append(imageFrame);applyCover85m(img,album.folder_id);}else poster.append(node('span','','♫'));const title=node('strong','',album.title),count=node('small','',album.tracks.length+' pistas'),chooser=node('select');album.tracks.forEach((t,i)=>{const o=node('option','',`${i+1}. ${t.name}`);o.value=i;chooser.append(o);});const previousTrack=album.progress?.track_id;const savedI=album.tracks.findIndex(t=>Number(t.id)===Number(previousTrack));if(savedI>=0)chooser.value=String(savedI);if(active&&Number(active.album.folder_id)===Number(album.folder_id))chooser.value=String(active.index);selectors.set(Number(album.folder_id),chooser);const resumeB=node('button','','▶ Continuar');resumeB.onclick=()=>resume(album);const startB=node('button','','▶ Reproducir');startB.onclick=()=>play(album,Number(chooser.value),0);const dots=node('button','','⋮');dots.title='Opciones del álbum';const menu=node('div','dc-music-menu81');menu.hidden=true;const menuOpen=node('button','','📁 Abrir carpeta');menuOpen.onclick=()=>{menu.hidden=true;if(typeof openFolder==='function')openFolder(album.folder_id);};const menuResume=node('button','','▶ Continuar reproducción');menuResume.onclick=()=>{menu.hidden=true;resumeB.click();};const menuStart=node('button','','⏮ Desde el inicio');menuStart.onclick=()=>{menu.hidden=true;play(album,0,0);};const menuCover=node('button','','🖼 Elegir portada desde DC');menuCover.onclick=()=>{menu.hidden=true;chooseCover(album);};const menuAuto=node('button','','↺ Portada automática');menuAuto.onclick=async()=>{menu.hidden=true;try{await req('set_cover',{album_folder_id:String(album.folder_id),image_id:'0'},true);await window.dcMusicRefresh81();}catch(e){alert(e.message);}};menu.append(menuOpen,menuResume,menuStart,menuCover,menuAuto);const menuLayout=node('button','','▣ Ajustar encuadre');menuLayout.onclick=()=>{menu.hidden=true;editCover85m(album);};menu.append(menuLayout);dots.onclick=e=>{e.stopPropagation();const opening=menu.hidden;window.dcMusicCloseMenus81();menu.hidden=!opening;};const addMenu=(label,callback)=>{const b=node('button','',label);b.type='button';b.onclick=()=>{menu.hidden=true;Promise.resolve(callback()).catch(err=>alert(err.message||String(err)));};menu.append(b);};
addMenu('📂 Agregar archivos',()=>{if(typeof openFolder==='function')openFolder(album.folder_id);});
if(Number(album.folder_id)!==Number(folder))addMenu('♫ Quitar modo colección musical',async()=>{
 if(!confirm('¿Quitar el modo colección musical de «'+album.title+'»? Las canciones no se eliminarán.'))return;
 const r=await req('set_collection_hf3',{folder_id:String(album.folder_id),enabled:'0'},true);
 if(!r.success)throw Error('No se pudo quitar el modo colección');
 await window.dcMusicRefresh81();if(typeof loadFiles==='function')await loadFiles();
});
addMenu('📋 Propiedades',()=>{if(typeof showProperties==='function')showProperties(album.folder_id);else if(typeof showFileProperties==='function')showFileProperties(album.folder_id);else alert('Abre la carpeta para consultar sus propiedades.');});
addMenu('🗑 Enviar álbum a papelera',async()=>{
 if(!confirm('¿Enviar el álbum «'+album.title+'» completo a la papelera, incluyendo todas sus canciones y subcarpetas? Podrás restaurarlo desde la Papelera.'))return;
 const r=await fetch('api.php?action=delete',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:new URLSearchParams({id:String(album.folder_id),csrf:window.DC_AUTH?.csrf||''})});
 const j=await r.json();if(!r.ok||j.error||!j.success)throw Error(j.error||'No se pudo enviar a papelera');
 await window.dcMusicRefresh81();if(typeof loadTree==='function')await loadTree();if(typeof loadFiles==='function')await loadFiles();
});
const actions=node('div','dc-music-actions81');actions.append(resumeB,startB);poster.append(dots,menu);card.append(poster,title,count,chooser,actions);cards.append(card);}
if(!data.albums.length)section.append(node('p','','Esta biblioteca todavía no contiene subcarpetas con audio. Coloca pistas MP3, M4A, OGG, WAV o FLAC en una subcarpeta para crear un álbum.'));const anchor=document.getElementById('dcyToolbar')||grid;anchor.parentNode.insertBefore(section,anchor);

for(const album of data.albums){const item=document.getElementById('file-item-'+Number(album.folder_id));if(item&&item.parentElement===grid){item.hidden=true;item.style.display='none';}}
if(active)syncSelection(active.album,active.index);
};

/* DC85M KARAOKE85M */
function dcKaraokeSetup85m(panel,ext,album,track,overlay,lrclibCheckbox){
  const head=panel.querySelector('.dc-music-head81');
  if(!head)return;
  const toggle=document.createElement('button');toggle.type='button';toggle.textContent='⛶';
  toggle.title='Maximizar letras (modo karaoke)';toggle.setAttribute('aria-label','Maximizar letras');
  
  // Sincronizar estado del botón de karaoke con el checkbox de letras
  const updateKaraokeState = () => {
    const isChecked = lrclibCheckbox ? lrclibCheckbox.checked : true;
    toggle.disabled = !isChecked;
    toggle.style.opacity = isChecked ? '1' : '0.35';
    toggle.style.cursor = isChecked ? 'pointer' : 'not-allowed';
    toggle.title = isChecked ? 'Maximizar letras (modo karaoke)' : 'Activa "Mostrar letra desde LRCLIB" para usar el modo karaoke';
  };

  if(lrclibCheckbox){
    lrclibCheckbox.addEventListener('change', updateKaraokeState);
  }
  updateKaraokeState();

  head.insertBefore(toggle,head.lastElementChild);
  if(!document.getElementById('dc-karaoke-css85m')){
    const css=document.createElement('style');css.id='dc-karaoke-css85m';
    css.textContent=`
      .dc-karaoke85m{position:fixed;inset:0;z-index:160000;background:rgba(5,12,26,.97);color:#f5f8ff;display:flex;align-items:center;justify-content:center;padding:clamp(10px,2vw,28px)}
      .dc-karaoke-panel85m{width:min(1450px,100%);height:min(94dvh,900px);display:flex;flex-direction:column;min-height:0;background:#142237;border:1px solid #607896;border-radius:16px;padding:clamp(12px,2.3vw,28px);box-shadow:0 24px 70px #000a;gap:18px}
      .dc-karaoke-header85m{display:flex;gap:18px;align-items:center;min-width:0}
      .dc-karaoke-heading85m{flex:1;min-width:0}
      .dc-karaoke-heading85m h2{font-size:clamp(24px,3.2vw,46px);line-height:1.15;overflow-wrap:anywhere;margin:0 0 8px}
      .dc-karaoke-heading85m p{font-size:clamp(15px,1.5vw,22px);opacity:.85;margin:0}
      .dc-karaoke-close85m{background:#304562;color:white;border:1px solid #7f91a7;border-radius:10px;padding:9px 14px;cursor:pointer;align-self:flex-start}
      .dc-karaoke85m .dc-music-extras83{display:flex!important;flex:1;min-height:0;align-items:stretch;gap:clamp(12px,2vw,26px);overflow:hidden}
      .dc-karaoke85m .dc-music-trackart83{width:clamp(100px,21vw,310px)!important;height:clamp(100px,21vw,310px)!important;object-fit:contain!important;flex:none;align-self:center;border-radius:12px}
      .dc-karaoke85m .dc-music-trackart83[hidden]{display:none!important}
      .dc-karaoke85m .dc-music-lyrics83{scroll-behavior:smooth!important;overscroll-behavior:contain;flex:1 1 auto;min-width:0;min-height:0;max-height:min(68dvh,680px)!important;height:min(68dvh,680px)!important;overflow-y:auto!important;overflow-x:hidden!important;overflow-anchor:none!important;scrollbar-gutter:stable;font-size:clamp(20px,2.25vw,32px)!important;line-height:1.65!important;padding:14px!important}
      .dc-karaoke85m .dc-music-lyrics83 p{font-size:inherit!important;line-height:inherit!important;padding:5px 10px!important;white-space:normal!important}
      .dc-karaoke85m .dc-music-lyrics83 p.active83{font-size:clamp(29px,3.35vw,48px)!important;font-weight:750!important;line-height:1.35!important}
      .dc-karaoke85m .dc-lyrics-offset83b{display:none!important}
      .dc-karaoke85m .dc-karaoke-header85m{display:flex;align-items:center;gap:clamp(12px,1.8vw,22px);flex:0 0 auto;min-height:0}
      .dc-karaoke85m .dc-karaoke-header85m>.dc-music-trackart83{display:block;width:clamp(64px,7vw,94px)!important;height:clamp(64px,7vw,94px)!important;max-width:94px;max-height:94px;object-fit:contain!important;flex:0 0 auto;align-self:center;border-radius:9px}
      .dc-karaoke85m .dc-karaoke-header85m>.dc-music-trackart83[hidden]{display:none!important}
      .dc-karaoke85m .dc-karaoke-heading85m{flex:1 1 auto;min-width:0}
      .dc-karaoke85m .dc-karaoke-close85m{flex:0 0 auto}
      .dc-karaoke85m .dc-music-extras83{min-width:0;width:100%;justify-content:stretch;gap:0}
      .dc-karaoke85m .dc-music-lyrics83{width:100%;min-width:0}
      @media(max-width:560px){.dc-karaoke85m .dc-karaoke-header85m>.dc-music-trackart83{width:60px!important;height:60px!important}.dc-karaoke85m .dc-karaoke-heading85m h2{font-size:clamp(19px,5vw,30px)}}
      @media(max-width:760px){.dc-karaoke-header85m{align-items:flex-start}.dc-karaoke85m .dc-music-extras83{flex-direction:column;align-items:center}.dc-karaoke85m .dc-music-trackart83{width:min(25vh,160px)!important;height:min(25vh,160px)!important}.dc-karaoke85m .dc-music-lyrics83{width:100%}}
    `;
    document.head.append(css);
  }
  toggle.onclick=()=>{
    if(toggle.disabled)return;
    const old=document.querySelector('.dc-karaoke85m');if(old)return;
    const priorMini=overlay.classList.contains('dc-music-mini82');
    if(priorMini)overlay.classList.remove('dc-music-mini82');
    const modal=document.createElement('div');modal.className='dc-karaoke85m';modal.setAttribute('role','dialog');modal.setAttribute('aria-modal','true');modal.setAttribute('aria-label','Modo karaoke');
    const box=document.createElement('div');box.className='dc-karaoke-panel85m';
    const top=document.createElement('header');top.className='dc-karaoke-header85m';
    const titles=document.createElement('div');titles.className='dc-karaoke-heading85m';
    const song=document.createElement('h2');song.textContent=track.name||'Canción';
    const albumTitle=document.createElement('p');albumTitle.textContent=album.title||'';
    titles.append(song,albumTitle);
    const closeB=document.createElement('button');closeB.type='button';closeB.className='dc-karaoke-close85m';closeB.textContent='✕ Cerrar';
    const art=ext.querySelector('.dc-music-trackart83');
    const artPlaceholder=art?document.createComment('karaoke-art-return'):null;
    if(art&&art.parentNode)art.parentNode.insertBefore(artPlaceholder,art);
    if(art)top.append(art);
    top.append(titles,closeB);box.append(top,ext);modal.append(box);document.body.append(modal);
    const prevFocus=document.activeElement;
    let fitCleanup=null;
    const restore=()=>{
      fitCleanup?.();
      if(art&&artPlaceholder?.parentNode){artPlaceholder.parentNode.replaceChild(art,artPlaceholder);}
      if(modal.isConnected)modal.remove();if(panel.isConnected)panel.append(ext);ext.querySelector('.dc-music-lyrics83')?.dispatchEvent(new Event('dc-lyrics-recenter85m'));if(priorMini&&overlay.isConnected)overlay.classList.add('dc-music-mini82');document.removeEventListener('keydown',key);if(prevFocus?.isConnected)prevFocus.focus();};
    const key=e=>{if(e.key==='Escape'){e.preventDefault();restore();}};
    document.addEventListener('keydown',key);
    closeB.onclick=restore;
    modal.addEventListener('click',e=>{if(e.target===modal)restore();});
    closeB.focus();
    fitCleanup=(()=>{
      const lyrics=ext.querySelector('.dc-music-lyrics83');
      if(!lyrics)return ()=>{};
      let last=null,raf=0;
      const canvas=document.createElement('canvas');
      const ctx=canvas.getContext('2d');
      const fit=()=>{
        raf=0;
        if(!modal.isConnected)return;
        const current=lyrics.querySelector('p.active83');
        if(last&&last!==current)last.style.removeProperty('font-size');
        last=current;
        if(!current)return;
        current.style.removeProperty('font-size');
        current.style.setProperty('overflow-wrap','anywhere','important');
        current.style.setProperty('white-space','normal','important');
        const cs=getComputedStyle(current),ls=getComputedStyle(lyrics);
        const available=lyrics.clientWidth-parseFloat(ls.paddingLeft||0)-parseFloat(ls.paddingRight||0)
          -parseFloat(cs.paddingLeft||0)-parseFloat(cs.paddingRight||0)-8;
        if(available<=0)return;
        const wanted=Math.min(48,Math.max(22,parseFloat(cs.fontSize)||48));
        if(!ctx)return;
        ctx.font=`${cs.fontWeight} ${wanted}px ${cs.fontFamily}`;
        const text=current.textContent||'';
        const spacing=parseFloat(cs.letterSpacing)||0;
        const measured=ctx.measureText(text).width + Math.max(0,text.length-1)*spacing;
        const target=measured>available ? Math.max(22,Math.floor(wanted*available/measured)) : wanted;
        current.style.setProperty('font-size',target+'px','important');
      };
      const request=()=>{if(!raf)raf=requestAnimationFrame(fit);};
      const observer=new MutationObserver(records=>{
        if(records.some(r=>r.type==='attributes'&&r.attributeName==='class'))request();
      });
      observer.observe(lyrics,{subtree:true,attributes:true,attributeFilter:['class']});
      const ro=typeof ResizeObserver==='function'?new ResizeObserver(request):null;
      ro?.observe(lyrics);
      request();
      return ()=>{observer.disconnect();ro?.disconnect();if(raf)cancelAnimationFrame(raf);last?.style.removeProperty('font-size');};
    })();
    const activeLine=ext.querySelector('.dc-music-lyrics83 .active83');
    ext.querySelector('.dc-music-lyrics83')?.dispatchEvent(new Event('dc-lyrics-recenter85m'));
  };
}

})();
