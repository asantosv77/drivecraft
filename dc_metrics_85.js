/* Drive-Craft 85: panel de recursos para administradores (con métrica de memoria del cliente integrada). */
(()=>{'use strict';
let clock=null,prior=null;const api='dc_metrics_85.php';
function chart(canvas,points,label){const ctx=canvas.getContext('2d'),w=canvas.width,h=canvas.height;ctx.clearRect(0,0,w,h);ctx.strokeStyle='#52607a';ctx.beginPath();ctx.moveTo(28,5);ctx.lineTo(28,h-20);ctx.lineTo(w-8,h-20);ctx.stroke();ctx.font='11px sans-serif';ctx.fillStyle='#a7b8d3';ctx.fillText('100%',0,14);ctx.fillText('0%',7,h-22);ctx.fillText(label,32,16);if(!points.length)return;ctx.strokeStyle='#69b7ee';ctx.lineWidth=2;ctx.beginPath();points.forEach((p,i)=>{const x=29+i*(w-42)/Math.max(1,points.length-1),y=(h-21)-Number(p.value)*(h-34)/100;i?ctx.lineTo(x,y):ctx.moveTo(x,y)});ctx.stroke()}

function updateClientMemory(box){
 const cMemBox=box.querySelector('[data-client-memory]');
 if(!cMemBox)return;
 if(performance && performance.memory){
  const usedMB=performance.memory.usedJSHeapSize/(1024*1024);
  const totalMB=performance.memory.totalJSHeapSize/(1024*1024);
  const limitMB=performance.memory.jsHeapSizeLimit/(1024*1024);
  const pct=Math.min(Math.round((usedMB/totalMB)*100),100);
  
  cMemBox.querySelector('[data-cm-used]').textContent=usedMB.toFixed(2)+' MB';
  cMemBox.querySelector('[data-cm-total]').textContent=totalMB.toFixed(2)+' MB';
  cMemBox.querySelector('[data-cm-limit]').textContent=limitMB.toFixed(2)+' MB';
  cMemBox.querySelector('[data-cm-text]').textContent=`Heap Usado: ${usedMB.toFixed(1)} MB / ${totalMB.toFixed(1)} MB (${pct}%)`;
  const bar=cMemBox.querySelector('[data-cm-bar]');
  bar.style.width=pct+'%';
  bar.style.background=pct>85?'#f28b82':(pct>60?'#fdd663':'#8ab4f8');
  cMemBox.querySelector('[data-cm-active]').style.display='block';
  cMemBox.querySelector('[data-cm-unsupported]').style.display='none';
 }else{
  cMemBox.querySelector('[data-cm-active]').style.display='none';
  cMemBox.querySelector('[data-cm-unsupported]').style.display='block';
 }
}

window.dcOpenMetrics85=()=>{document.getElementById('dcMetrics85')?.remove();const d=document.createElement('div');d.id='dcMetrics85';d.className='dc-metrics85-backdrop';d.innerHTML='<div class="dc-metrics85-box" role="dialog" aria-modal="true" aria-label="Recursos del servidor"><header><strong>📈 Recursos del servidor y cliente</strong><button type="button" data-close>✕</button></header><p>CPU, memoria, swap y carga del entorno Linux expuesto al contenedor PHP, junto con el consumo de memoria en el navegador cliente. Historial del host: exportaciones SAR del sitio Fredy.</p><div class="dc-metrics85-stats"><div data-cpu>CPU: calculando…</div><div data-ram>RAM: calculando…</div><div data-swap>Swap: calculando…</div><div data-load>Carga: calculando…</div></div><div class="dc-metrics85-client-mem" data-client-memory><h5>💻 Memoria del Navegador (Cliente)</h5><div data-cm-active><div style="font-size:11px;display:flex;justify-content:space-between;margin-bottom:4px"><span data-cm-text>Calculando...</span></div><div class="dc-metrics85-progress"><div class="dc-metrics85-progress-bar" data-cm-bar></div></div><div class="dc-metrics85-client-grid"><div>Heap Usado<span data-cm-used>-</span></div><div>Heap Total<span data-cm-total>-</span></div><div>Límite Máximo<span data-cm-limit>-</span></div></div></div><div data-cm-unsupported style="display:none;font-size:11px;color:#fdd663">⚠️ La API de memoria del cliente no está soportada en este navegador (se recomienda Chrome o Edge).</div></div><h4>CPU en vivo (últimas muestras)</h4><canvas data-live width="1050" height="110"></canvas><div class="dc-metrics85-day"><label>Histórico SAR, día del mes <input data-day type="number" min="1" max="31" value="'+new Date().getDate()+'"></label><button data-history>Consultar histórico</button></div><div data-info></div><canvas data-history-chart width="1050" height="110"></canvas><canvas data-memory-chart width="1050" height="110"></canvas></div>';document.body.append(d);const samples=[];let latest=null;const tick=async()=>{if(!d.isConnected)return;try{const r=await fetch(api+'?action=live',{cache:'no-store'});const j=await r.json();if(!r.ok||j.error)throw Error(j.error||'Datos no disponibles');const s=j.sample;if(prior&&s.cpu&&s.cpu.total>prior.total){const delta=s.cpu.total-prior.total,used=100*(1-(s.cpu.idle-prior.idle)/delta);samples.push({value:Math.min(100,Math.max(0,used))});if(samples.length>55)samples.shift();d.querySelector('[data-cpu]').textContent='CPU: '+used.toFixed(1)+' %'}prior=s.cpu;const ram=s.ram_total_kib?100*(1-s.ram_available_kib/s.ram_total_kib):null;d.querySelector('[data-ram]').textContent='RAM: '+(ram===null?'No disponible':ram.toFixed(1)+' % ('+Math.round((s.ram_total_kib-s.ram_available_kib)/1024)+' MiB usados)');d.querySelector('[data-swap]').textContent='Swap: '+(s.swap_total_kib?((100*(s.swap_total_kib-s.swap_free_kib)/s.swap_total_kib).toFixed(1)+' %'):'Sin swap');d.querySelector('[data-load]').textContent='Carga: '+s.load.map(n=>Number(n).toFixed(2)).join(' / ');chart(d.querySelector('[data-live]'),samples,'CPU en vivo');updateClientMemory(d);}catch(e){d.querySelector('[data-cpu]').textContent='CPU: '+e.message}};
const hist=async()=>{try{const day=String(d.querySelector('[data-day]').value).padStart(2,'0');const r=await fetch(api+'?action=history&day='+encodeURIComponent(day),{cache:'no-store'});const j=await r.json();if(!r.ok||j.error)throw Error(j.error||'Error');d.querySelector('[data-info]').textContent=j.available?'Datos archivados por sysstat · '+day:'Sin datos SAR exportados para ese día.';chart(d.querySelector('[data-history-chart]'),j.cpu,'CPU histórico · '+day);chart(d.querySelector('[data-memory-chart]'),j.memory,'RAM histórica · '+day);}catch(e){d.querySelector('[data-info]').textContent=e.message}};
d.querySelector('[data-history]').onclick=hist;d.querySelector('[data-close]').onclick=()=>{clearInterval(clock);clock=null;prior=null;d.remove()};d.onclick=e=>{if(e.target===d)d.querySelector('[data-close]').click()};tick();clock=setInterval(tick,3000);hist();};
})();