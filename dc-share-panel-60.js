/* v20.10.60: gestión en dos columnas y revocación masiva de enlaces propios. */
(function(){
  window.dcRevokeAllShares60=async function(){
    const items=Array.isArray(window.allManagedShares)?window.allManagedShares:null;
    // allManagedShares is a top-level lexical binding in script.js; use the displayed rows if needed.
    const count=typeof allManagedShares!=='undefined'&&Array.isArray(allManagedShares)?allManagedShares.length:0;
    if(!count){alert('No hay enlaces generados para revocar.');return;}
    if(!confirm('¿Revocar definitivamente los '+count+' enlaces generados de tu cuenta? Los enlaces dejarán de funcionar. Los enlaces de recepción no serán afectados.'))return;
    const button=document.getElementById('dcRevokeAll60');
    if(button)button.disabled=true;
    try{
      const result=await shareRequest('revoke_all_shares_60',{confirm:'REVOKCAR_TODOS_60'});
      await refreshShareManager();
      alert('Se revocaron '+Number(result.revoked||0)+' enlaces.');
    }catch(e){alert('No se pudieron revocar todos los enlaces: '+e.message);}
    finally{if(button)button.disabled=false;}
  };
  const base=window.renderShareManager;
  if(typeof base==='function')window.renderShareManager=function(){
    const out=base.apply(this,arguments);
    const button=document.getElementById('dcRevokeAll60');
    if(button)button.disabled=!(typeof allManagedShares!=='undefined'&&allManagedShares.length);
    return out;
  };
})();
