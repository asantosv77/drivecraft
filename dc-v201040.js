/* Drive-Craft v20.10.40: mejora no invasiva de ayudas y administración. */
(()=>{
 const root=document.getElementById('dcUtilityModal');
 if(!root)return;
 const help={
  'Guardar ajustes':'Guarda los permisos y límites de esta cuenta.',
  'Crear':'Da de alta una cuenta y genera una contraseña temporal.',
  'Multimedia':'Ajustes globales de multimedia. Se aplican a todos los usuarios; el permiso de conversión se controla por usuario.',
  'Hardlinks globales':'Configura la deduplicación física entre archivos idénticos, según los tipos permitidos.',
  'Consumo por usuario':'Consulta la ocupación de archivos, versiones y cuotas.',
  'Guardar multimedia':'Guarda el máximo GLOBAL de procesamiento multimedia y sus interruptores.',
  'Restablecer':'Genera una contraseña temporal y revoca otras sesiones cuando lo permite el servidor.'
 };
 function update(){
  const title=document.getElementById('dcUtilityTitle')?.textContent||'';
  root.classList.toggle('dc-users-wide',/administraci[oó]n de usuarios|^usuarios$/i.test(title));
  root.querySelectorAll('button').forEach(b=>{
   if(!b.title){const t=(b.textContent||'').trim();const k=Object.keys(help).find(x=>t.includes(x));if(k)b.title=help[k];}
  });
  root.querySelectorAll('#dcEditUser label').forEach(el=>{
   if(el.title)return;const t=(el.textContent||'').trim();
   if(/cuota/i.test(t))el.title='Cantidad total asignada al usuario. Vacío significa sin límite.';
   else if(/tamaño máximo/i.test(t))el.title='Tamaño máximo permitido para cada archivo que suba este usuario.';
   else if(/rol/i.test(t))el.title='Administrador tiene facultades globales; Usuario depende de sus permisos.';
   else if(/estado/i.test(t))el.title='Una cuenta suspendida no puede iniciar sesión.';
  });
 }
 new MutationObserver(update).observe(root,{childList:true,subtree:true});update();
})();
