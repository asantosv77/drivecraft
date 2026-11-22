(()=>{'use strict';
let serial=0;
const offsetKey='dc_music_lyrics_offset_83b';
function getOffset(){try{const n=Number(localStorage.getItem(offsetKey));return Number.isFinite(n)&&n>=-2&&n<=2?n:0.5;}catch(e){return 0.5;}}
function saveOffset(n){try{localStorage.setItem(offsetKey,String(n));}catch(e){}}

function lrcParse(s){return (s||'').split(/\r?\n/).flatMap(line=>{const times=[...line.matchAll(/\[(\d{1,3}):(\d{2})(?:[.:](\d{1,3}))?\]/g)];const text=line.replace(/\[(?:\d{1,3}):(\d{2})(?:[.:](?:\d{1,3}))?\]/g,'').trim();return times.length&&text?times.map(m=>({time:Number(m[1])*60+Number(m[2])+Number('0.'+(m[3]||0)),text})):[];}).sort((a,b)=>a.time-b.time);}
window.dcMusicRenderTrack83=async(track,album,audio,ext)=>{const id=++serial;const art=ext.querySelector('img');const body=ext.querySelector('.dc-music-lyrics83');if(!body)return;body.textContent='Buscando letra…';art.hidden=true;
const existing=ext.querySelector('.dc-lyrics-offset83b');if(existing)existing.remove();
const bar=document.createElement('div');bar.className='dc-lyrics-offset83b';
const label=document.createElement('label');label.textContent='Desfase letras';
const minus=document.createElement('button');minus.type='button';minus.textContent='−';minus.title='Retrasar letras 0.05 segundos';minus.setAttribute('aria-label','Retrasar letras 0.05 segundos');
const plus=document.createElement('button');plus.type='button';plus.textContent='+';plus.title='Adelantar letras 0.05 segundos';plus.setAttribute('aria-label','Adelantar letras 0.05 segundos');
let selectedOffset=Math.round(getOffset()*20)/20;
const amount=document.createElement('output');amount.setAttribute('aria-live','polite');amount.setAttribute('aria-label','Desfase actual de letras');
const showOffset=()=>{amount.textContent=(selectedOffset>=0?'+':'')+selectedOffset.toFixed(2)+' s';};showOffset();
const setOffset=n=>{selectedOffset=Math.max(-2,Math.min(2,Math.round(n*20)/20));saveOffset(selectedOffset);showOffset();body.dispatchEvent(new Event('dc-lyrics-offset-change'));};
const reset=document.createElement('button');reset.type='button';reset.textContent='Restablecer';reset.title='Restablecer desfase de letras a cero';
minus.addEventListener('click',()=>setOffset(selectedOffset-0.05));
plus.addEventListener('click',()=>setOffset(selectedOffset+0.05));
reset.addEventListener('click',()=>setOffset(0));
label.append(minus,amount,plus);bar.append(label,reset);ext.append(bar);
const url='dc_music_assets_83.php?track_id='+encodeURIComponent(track.id);art.onload=()=>{if(id===serial)art.hidden=false;};art.onerror=()=>{art.hidden=true;};art.src=url+'&action=art';
try{const r=await fetch(url+'&action=lyrics',{credentials:'same-origin',cache:'no-store'});const j=await r.json();if(id!==serial||!ext.isConnected)return;if(!r.ok||j.error)throw Error(j.error||'No disponible');const lines=lrcParse(j.synced);body.replaceChildren();if(!lines.length){body.textContent=j.plain||'No se encontraron letras para esta canción.';return;}
const nodes=lines.map(x=>{const p=document.createElement('p');p.textContent=x.text;body.append(p);return p;});let last=-1,manualUntil=0,autoScrolling=false;
/* DC85M STABLE KARAOKE FOLLOW HF */
// Dejar que el usuario desplace libremente las letras y recuperar el seguimiento
// 1.6 s después de la última interacción, aunque no haya cambiado la frase.
let followTimer=null;
const suspend=()=>{
 manualUntil=Date.now()+1600;
 if(followTimer!==null)clearTimeout(followTimer);
 followTimer=setTimeout(()=>{
   followTimer=null;
   if(id!==serial || !body.isConnected)return;
   manualUntil=0;
   const node=body.querySelector('p.active83');
   if(node)centerActive(node,true);
 },1650);
};
body.addEventListener('wheel',suspend,{passive:true});
body.addEventListener('touchstart',suspend,{passive:true});
body.addEventListener('touchmove',suspend,{passive:true});
body.addEventListener('pointerdown',suspend,{passive:true});
// Scroll por teclado y barra de desplazamiento.
body.addEventListener('keydown',e=>{
 if(['ArrowUp','ArrowDown','PageUp','PageDown','Home','End',' '].includes(e.key))suspend();
});

/* DC85M UNIFIED LYRICS FOLLOW */
/* DC85M KARAOKE CENTER SPACERS V2 */
// El espacio inferior permite centrar incluso la última línea de la canción.
// Se crea SOLO mientras está abierto karaoke; el reproductor normal no cambia.
const topGap=document.createElement('div'),bottomGap=document.createElement('div');
for(const gap of [topGap,bottomGap]){
 gap.className='dc-lyrics-center-gap85m';
 gap.setAttribute('aria-hidden','true');
 gap.style.cssText='height:0px;min-height:0;flex:none;pointer-events:none;';
}
const syncKaraokeGaps=()=>{
 const karaoke=!!body.closest('.dc-karaoke85m');
 if(karaoke){
   if(topGap.parentNode!==body)body.prepend(topGap);
   if(bottomGap.parentNode!==body)body.append(bottomGap);
   // El espacio NO depende de la frase activa: el marco no se reacomoda
   // cuando cambian el tamaño y la altura de la línea resaltada.
   const height=body.clientHeight;
   const gap=Math.max(0,Math.round(height/2 - 36));
   const desired=gap+'px';
   if(topGap.style.height!==desired)topGap.style.height=desired;
   if(bottomGap.style.height!==desired)bottomGap.style.height=desired;
 }else{
   topGap.remove();bottomGap.remove();
 }
};
const centerActive=(node,instant=false)=>{
 if(!node||!body.isConnected||Date.now()<manualUntil)return;
 if(document.querySelector('.dc-music-mini82') && !body.closest('.dc-karaoke85m'))return;
 syncKaraokeGaps();
 const rect=body.getBoundingClientRect(), line=node.getBoundingClientRect();
 if(rect.height<=0)return;
 const lineCenter=line.top-rect.top+body.scrollTop+line.height/2;
 const maxTop=Math.max(0,body.scrollHeight-body.clientHeight);
 const target=Math.max(0,Math.min(maxTop,lineCenter-body.clientHeight/2));
 if(Math.abs(body.scrollTop-target)<2)return;
 autoScrolling=true;
 body.scrollTo({top:target,behavior:instant?'instant':'smooth'});
 queueMicrotask(()=>{autoScrolling=false;});
};
// Recalcular al maximizar/restaurar: cambiar el tamaño no cambia la línea activa.
body.addEventListener('dc-lyrics-recenter85m',()=>{
 manualUntil=0;
 requestAnimationFrame(()=>requestAnimationFrame(()=>{
   if(id===serial){const active=body.querySelector('.active83');centerActive(active,true);}
 }));
});
const lyricsResizeObserver=new ResizeObserver(()=>{if(id===serial&&body.isConnected)centerActive(body.querySelector('.active83'),true);else lyricsResizeObserver.disconnect();});
lyricsResizeObserver.observe(body);
const update=()=>{if(id!==serial)return;let i=-1;for(let k=0;k<lines.length;k++){if(lines[k].time<=audio.currentTime+getOffset())i=k;else break;}if(i===last)return;if(last>=0)nodes[last].classList.remove('active83');if(i>=0){nodes[i].classList.add('active83');centerActive(nodes[i]);}last=i;};audio.addEventListener('timeupdate',update);audio.addEventListener('seeked',update);body.addEventListener('dc-lyrics-offset-change',()=>{last=-1;nodes.forEach(p=>p.classList.remove('active83'));update();});update();
}catch(e){if(id===serial&&ext.isConnected)body.textContent='Letra no disponible';}
};
})();
