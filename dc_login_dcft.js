/* Login errors are independent of the file browser's initialization. */
(() => {
  'use strict';
  const form=document.getElementById('loginForm');
  if(!form)return;
  function httpMessage(status){
    if(status===404)return 'No se encontró el servicio de acceso (HTTP 404). Revisa la dirección o contacta al administrador.';
    if(status===429)return 'Se alcanzó el límite de intentos. Espera unos minutos antes de volver a intentarlo.';
    if(status===502||status===503||status===504)return `El servidor de acceso no está disponible temporalmente (HTTP ${status}). Inténtalo más tarde.`;
    if(status===401)return 'No se pudo validar el usuario o la contraseña.';
    if(status===403)return 'El servidor no permite iniciar sesión con esta cuenta (HTTP 403).';
    return `El servidor no pudo completar el acceso (HTTP ${status}).`;
  }
  let busy=false;
  form.addEventListener('submit',async event=>{
    event.preventDefault();if(busy)return;
    const button=form.querySelector('[type=submit]'),error=document.getElementById('loginError');
    const label=button.textContent;
    busy=true;button.disabled=true;button.textContent='Ingresando…';form.setAttribute('aria-busy','true');
    error.style.display='none';
    const controller=new AbortController(),timeout=setTimeout(()=>controller.abort(),15000);
    try{
      const body=new FormData(form);body.set('action','login');
      const response=await fetch('index.php',{method:'POST',body,credentials:'same-origin',cache:'no-store',signal:controller.signal});
      let data=null;
      try{data=await response.json();}catch(e){if(e.name==='AbortError')throw e;}
      if(!response.ok)throw Error(data?.error||httpMessage(response.status));
      if(!data||data.success!==true)throw Error(data?.error||'El servidor devolvió una respuesta de acceso inválida. Inténtalo de nuevo.');
      location.reload();
    }catch(e){
      error.textContent=e.name==='AbortError'
        ? 'El servidor tardó demasiado en responder. Comprueba tu conexión e inténtalo de nuevo.'
        : e instanceof TypeError
          ? 'No se pudo establecer la conexión con el servidor. Comprueba Internet y vuelve a intentarlo.'
          : e.message;
      error.style.display='block';
    }finally{clearTimeout(timeout);busy=false;button.disabled=false;button.textContent=label;form.removeAttribute('aria-busy');}
  });
})();
