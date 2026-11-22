(()=>{'use strict';
const button=document.getElementById('dc79SessionsCount');if(!button)return;
let modal=null,layer=null,expired=false;
async function request(action,values=null){
 const options={credentials:'same-origin',cache:'no-store',signal:AbortSignal.timeout(15000)};
 if(values){options.method='POST';options.body=new URLSearchParams({action,...values});options.headers={'X-DC-CSRF':window.DC_AUTH?.csrf||''};}
 const r=await fetch('dc_sessions_79.php'+(values?'':'?action='+encodeURIComponent(action)),options);
 let j;try{j=await r.json();}catch(_){throw Error('El servidor devolvió una respuesta inesperada (HTTP '+r.status+').');}
 if(!r.ok||j.error){const e=Error(j.error||'Error HTTP '+r.status);e.status=r.status;throw e;}return j;
}
async function refresh(){
 if(expired||document.hidden)return;
 try{const d=await request('count');button.textContent=String(d.count);button.hidden=false;}
 catch(e){button.hidden=true;if(e.status===401){expired=true;const n=mk('aside','dc-session-expired-notice');n.setAttribute('role','alert');n.append(mk('span','','Tu sesión venció o fue revocada. Vuelve a iniciar sesión para continuar. '));const a=mk('a','','Iniciar sesión');a.href='index.php';n.append(a);document.body.append(n);}}
}
function mk(tag,cls,txt){const n=document.createElement(tag);if(cls)n.className=cls;if(txt!==undefined)n.textContent=txt;return n;}
function close(){if(layer){layer.remove();layer=null;modal=null;}}
function privateIP(ip){if(!ip)return true;if(ip.includes(':'))return /^(::1|fc|fd|fe80|::ffff:127\.)/i.test(ip);const p=ip.split('.').map(Number);return p.length!==4||p[0]===10||p[0]===127||p[0]===0||p[0]===192&&p[1]===168||p[0]===172&&p[1]>=16&&p[1]<=31||p[0]===169&&p[1]===254||p[0]===100&&p[1]>=64&&p[1]<=127||p[0]>=224;}
async function location(ip){if(privateIP(ip))return {local:true};try{const c=new AbortController();const t=setTimeout(()=>c.abort(),5500);const r=await fetch('https://ipwho.is/'+encodeURIComponent(ip),{signal:c.signal});clearTimeout(t);if(!r.ok)throw Error('Geolocalización no disponible');const j=await r.json();if(!j.success||!Number.isFinite(+j.latitude)||!Number.isFinite(+j.longitude))throw Error('Ubicación desconocida');return {lat:+j.latitude,lon:+j.longitude,city:j.city||'',region:j.region||'',country:j.country||''};}catch(e){return {error:true};}}
function frame(loc){const lat=loc.lat,lon=loc.lon,delta=0.12;const bbox=[lon-delta,lat-delta,lon+delta,lat+delta].join(',');const f=mk('iframe','dc79-map');f.title='Mapa de ubicación aproximada de la IP';f.loading='lazy';f.referrerPolicy='no-referrer';f.src='https://www.openstreetmap.org/export/embed.html?bbox='+encodeURIComponent(bbox)+'&layer=mapnik&marker='+encodeURIComponent(lat+','+lon);return f;}
async function open(){close();layer=mk('div','dc79-overlay');modal=mk('section','dc79-dialog');modal.setAttribute('role','dialog');modal.setAttribute('aria-modal','true');modal.setAttribute('aria-label','Sesiones activas');const h=mk('header','dc79-head');h.append(mk('strong','', 'Mis sesiones activas'));const x=mk('button','dc79-close','×');x.type='button';x.setAttribute('aria-label','Cerrar');x.onclick=close;h.append(x);modal.append(h);const content=mk('div','dc79-content','Cargando sesiones…');modal.append(content);
 const controls=mk('footer','dc79-revoke-controls'),label=mk('label','dc79-except'),except=mk('input');except.type='checkbox';except.checked=true;except.id='dc79ExceptCurrent';label.append(except,document.createTextNode(' Excepto este equipo'));
 const description=mk('p','dc79-note','Revoca todas las sesiones de tu cuenta, incluso las que no aparecen en esta lista. «Este equipo» conserva únicamente la sesión de este navegador.');
 const revoke=mk('button','dc79-revoke','Revocar sesiones');revoke.type='button';const status=mk('p','dc79-revoke-status');status.setAttribute('role','status');status.setAttribute('aria-live','polite');
 controls.append(description,label,revoke,status);modal.append(controls);
 revoke.onclick=async()=>{
  const keep=except.checked;
  if(!confirm(keep?'¿Revocar todas las otras sesiones de tu cuenta y conservar este navegador?':'¿Revocar TODAS las sesiones, incluida esta? Tendrás que iniciar sesión de nuevo.'))return;
  revoke.disabled=true;except.disabled=true;controls.setAttribute('aria-busy','true');revoke.classList.add('is-busy');status.textContent='Revocando sesiones…';
  try{const result=await request('revoke',{except_current:keep?'1':'0'});status.textContent=result.message;if(result.signed_out){location.assign('index.php');return;}content.replaceChildren(mk('p','','Este navegador sigue conectado. Las otras sesiones deberán iniciar sesión de nuevo.'));await refresh();revoke.textContent='✓ Sesiones revocadas';}
  catch(e){status.textContent=e.message||'No se pudo confirmar la revocación. Inténtalo de nuevo.';revoke.textContent='Reintentar revocación';}
  finally{revoke.disabled=false;except.disabled=false;controls.removeAttribute('aria-busy');revoke.classList.remove('is-busy');}
 };
 layer.append(modal);layer.addEventListener('click',e=>{if(e.target===layer)close();});document.body.append(layer);x.focus();
 try{const d=await request('list');button.textContent=String(d.count);content.replaceChildren();content.append(mk('p','dc79-note','Ubicación aproximada por IP; no es GPS. Sesiones con actividad en los últimos 15 minutos. La consulta del mapa usa servicios externos.'));if(!d.sessions.length)content.append(mk('p','', 'No hay sesiones activas.'));
 for(const [i,s] of d.sessions.entries()){const item=mk('article','dc79-item');item.append(mk('div','dc79-session-title',(s.current?'● Esta sesión':'● Sesión '+(i+1))+' · '+(s.ip_address||'IP no disponible')));item.append(mk('div','dc79-muted','Última actividad (UTC): '+s.last_seen));const detail=mk('div','dc79-detail','Localizando IP…');item.append(detail);content.append(item);const loc=await location(s.ip_address);if(!item.isConnected)continue;detail.replaceChildren();if(loc.local){detail.append(mk('span','dc79-muted','IP privada de la red local: no admite geolocalización pública.'));
  if(s.current){const b=mk('button','dc79-egress-button','🌐 Ver ubicación de salida a Internet');b.type='button';detail.append(b);
   b.onclick=async()=>{b.disabled=true;b.textContent='Consultando salida pública…';try{
    const c=new AbortController(),t=setTimeout(()=>c.abort(),6000);let r;
    try{r=await fetch('https://ipwho.is/',{signal:c.signal,cache:'no-store'});}finally{clearTimeout(t);}
    const j=await r.json();if(!r.ok||!j.success||!Number.isFinite(+j.latitude)||!Number.isFinite(+j.longitude))throw Error('Consulta no disponible');
    if(!item.isConnected)return;b.remove();detail.append(mk('div','dc79-place','IP pública de salida: '+(j.ip||'')+' · '+[j.city,j.region,j.country].filter(Boolean).join(', ')));
    detail.append(mk('p','dc79-muted','Esta ubicación aproximada corresponde a la salida a Internet de este navegador, no a la IP privada de la sesión ni a su ubicación GPS. Puede representar un VPN, proxy o la red del proveedor.'));
    detail.append(frame({lat:+j.latitude,lon:+j.longitude}));
   }catch(e){b.disabled=false;b.textContent='No disponible; reintentar ubicación de salida';}};
  }
 }else if(loc.error)detail.append(mk('span','dc79-muted','No se pudo determinar la ubicación de esta IP.'));else {detail.append(mk('div','dc79-place',[loc.city,loc.region,loc.country].filter(Boolean).join(', ')||'Ubicación aproximada'));detail.append(frame(loc));}}
 }catch(e){content.textContent=e.message||'No fue posible cargar las sesiones';}}
window.dcOpenOwnSessionsDcft=open;button.addEventListener('click',open);document.addEventListener('keydown',e=>{if(e.key==='Escape'&&layer)close();});refresh();setInterval(refresh,60000);
})();
