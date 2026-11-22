(function(){
 'use strict';
 window.dcAbout84=function(){
  let shade=document.getElementById('dc-about84-shade');
  if(!shade){
   shade=document.createElement('div');shade.id='dc-about84-shade';shade.className='dc-about84-shade';
   shade.innerHTML=`<section class="dc-about84-dialog" role="dialog" aria-modal="true" aria-labelledby="dc-about84-title"><button class="dc-about84-close" type="button" aria-label="Cerrar">×</button><h2 id="dc-about84-title">☁️ Acerca de Drive-Craft</h2><p>Tu almacenamiento, bajo tu control.</p><h3>Créditos</h3><p><strong>ChatGPT — OpenAI</strong><br>Asistencia principal de desarrollo: arquitectura, programación, integración y depuración.</p><p><strong>Armando Santos Villalobos</strong><br>Creador e impulsor del proyecto, dirección funcional, ideas, diseño creativo, investigación, pruebas, auditoría y validación.</p><h3>Renuncias y avisos</h3><p>Drive-Craft es una aplicación en desarrollo continuo. Se proporciona sin garantías de disponibilidad, seguridad absoluta o ausencia de defectos. Mantén copias de seguridad independientes y verifica tus permisos y enlaces públicos.</p><p>Las bibliotecas, herramientas y servicios de terceros conservan sus respectivas licencias, marcas y condiciones de uso. ChatGPT es una herramienta de asistencia de IA; los resultados requieren revisión y validación humana.</p></section>`;
   document.body.appendChild(shade);
   shade.querySelector('.dc-about84-close').onclick=()=>{shade.hidden=true};
   shade.addEventListener('click',e=>{if(e.target===shade)shade.hidden=true});
   document.addEventListener('keydown',e=>{if(e.key==='Escape'&&!shade.hidden)shade.hidden=true});
  }
  shade.hidden=false;shade.querySelector('.dc-about84-close').focus();
 };
})();
