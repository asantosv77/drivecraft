<?php
// Metadatos de álbum: endpoint privado, sin enlaces públicos.
require __DIR__.'/config.php';
require_once __DIR__.'/auth.php';
header('Content-Type: application/json; charset=utf-8');
$user=dcRequireUser();
if (!empty($user['must_change_password'])) dcFail(403,'Primero cambia tu contraseña');
$owner=(int)$user['id'];
$action=(string)($_POST['action']??$_GET['action']??'');
try {
    if ($action==='list') {
        if ($_SERVER['REQUEST_METHOD']!=='GET') dcFail(405,'Método incorrecto');
        $folder=filter_input(INPUT_GET,'folder',FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
        if (!$folder) dcFail(400,'Álbum inválido');
        $f=$pdo->prepare('SELECT id,parent_id,owner_id,is_album,is_dir,deleted_at FROM files WHERE id=?');
        $seen=[];$id=$folder;$first=true;
        while ($id>0) {
            if (isset($seen[$id])) dcFail(404,'Álbum no encontrado');$seen[$id]=1;
            $f->execute([$id]);$r=$f->fetch(PDO::FETCH_ASSOC);
            if (!$r || $r['deleted_at']!==null || (int)$r['owner_id']!==$owner || (int)$r['is_dir']!==1 || ($first && (int)$r['is_album']!==1)) dcFail(404,'Álbum no encontrado');
            $first=false;$id=(int)$r['parent_id'];
        }
        $q=$pdo->prepare('SELECT n.file_id,n.description FROM dc_album_photo_notes n JOIN files f ON f.id=n.file_id WHERE f.parent_id=? AND f.owner_id=? AND f.is_dir=0 AND f.deleted_at IS NULL');
        $q->execute([$folder,$owner]);
        echo json_encode(['success'=>true,'notes'=>$q->fetchAll(PDO::FETCH_ASSOC)],JSON_UNESCAPED_UNICODE);exit;
    }
    if ($action==='save') {
        if ($_SERVER['REQUEST_METHOD']!=='POST') dcFail(405,'Método incorrecto');
        dcCsrf();
        $id=filter_input(INPUT_POST,'file_id',FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
        $description=trim((string)($_POST['description']??''));
        if (!$id || mb_strlen($description,'UTF-8')>2000) dcFail(400,'Descripción demasiado extensa (máximo 2000 caracteres)');
        $q=$pdo->prepare('SELECT f.parent_id,f.owner_id,f.deleted_at,f.is_dir,f.mime_type,p.is_album,p.deleted_at AS folder_deleted,p.owner_id AS folder_owner FROM files f JOIN files p ON p.id=f.parent_id WHERE f.id=?');
        $q->execute([$id]);$f=$q->fetch(PDO::FETCH_ASSOC);
        if (!$f || (int)$f['owner_id']!==$owner || (int)$f['folder_owner']!==$owner || $f['deleted_at']!==null || $f['folder_deleted']!==null || (int)$f['is_dir']!==0 || (int)$f['is_album']!==1 || !str_starts_with((string)$f['mime_type'],'image/')) dcFail(404,'Fotografía no encontrada en tu álbum');
        // Verificar ancestros del álbum, para no operar sobre una jerarquía inaccesible.
        $p=$pdo->prepare('SELECT parent_id,owner_id,is_dir,deleted_at FROM files WHERE id=?');$cur=(int)$f['parent_id'];$seen=[];
        while($cur>0){if(isset($seen[$cur]))dcFail(404,'Álbum no disponible');$seen[$cur]=1;$p->execute([$cur]);$r=$p->fetch(PDO::FETCH_ASSOC);if(!$r||(int)$r['owner_id']!==$owner||(int)$r['is_dir']!==1||$r['deleted_at']!==null)dcFail(404,'Álbum no disponible');$cur=(int)$r['parent_id'];}
        if ($description==='') $pdo->prepare('DELETE FROM dc_album_photo_notes WHERE file_id=?')->execute([$id]);
        else $pdo->prepare('INSERT INTO dc_album_photo_notes(file_id,description,updated_at) VALUES(?,?,NOW()) ON DUPLICATE KEY UPDATE description=VALUES(description),updated_at=NOW()')->execute([$id,$description]);
        echo json_encode(['success'=>true]);exit;
    }
    dcFail(400,'Acción no admitida');
} catch(PDOException $e) { dcFail(500,'No se pudieron consultar las descripciones. Comprueba la migración SQL.'); }
