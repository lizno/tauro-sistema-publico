<?php
// proveedores.php
require_once 'config/config.php';
require_once 'functions/db.php';
require_once 'functions/helpers.php';
require_once 'functions/auth.php';
requireLogin(); requirePermission('proveedores','ver');
$pageTitle = 'Proveedores';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $pa = $_POST['action'] ?? '';
    if (in_array($pa,['crear','editar'])) {
        $data = ['nombre'=>trim($_POST['nombre']??''),'rif'=>trim($_POST['rif']??''),'contacto'=>trim($_POST['contacto']??''),
                 'telefono'=>trim($_POST['telefono']??''),'email'=>trim($_POST['email']??''),'pais'=>trim($_POST['pais']??'Venezuela'),'direccion'=>trim($_POST['direccion']??'')];
        if (empty($data['nombre'])) { $_SESSION['flash']=['type'=>'error','msg'=>'Nombre requerido.']; }
        else {
            if ($pa==='crear') { dbExecute("INSERT INTO proveedores (nombre,rif,contacto,telefono,email,pais,direccion) VALUES (?,?,?,?,?,?,?)",array_values($data)); auditLog('INSERT','proveedores',(int)dbLastId(),'Proveedor creado: '.$data['nombre']); }
            else { $id=cleanInt($_POST['id']??0); $ant=dbQueryOne("SELECT * FROM proveedores WHERE id=?",[$id]); dbExecute("UPDATE proveedores SET nombre=?,rif=?,contacto=?,telefono=?,email=?,pais=?,direccion=? WHERE id=?",[...array_values($data),$id]); auditLog('UPDATE','proveedores',$id,'Proveedor editado',$ant,$data); }
            $_SESSION['flash']=['type'=>'success','msg'=>'Proveedor guardado.'];
        }
        header('Location: proveedores.php'); exit;
    }
    if ($pa==='eliminar') { $id=cleanInt($_POST['id']??0); dbExecute("UPDATE proveedores SET activo=0 WHERE id=?",[$id]); auditLog('DELETE','proveedores',$id,'Proveedor desactivado'); $_SESSION['flash']=['type'=>'success','msg'=>'Proveedor eliminado.']; header('Location: proveedores.php'); exit; }
}
$proveedores=dbQuery("SELECT * FROM proveedores WHERE activo=1 ORDER BY nombre");
$editP = ($_GET['action']??'')==='editar' ? dbQueryOne("SELECT * FROM proveedores WHERE id=?",[cleanInt($_GET['id']??0)]) : null;
include 'includes/header.php';
?>
<div class="d-flex justify-between align-items-center mb-4">
    <h2 style="font-size:15px;color:var(--text-muted)">Gestión de proveedores</h2>
    <div class="d-flex gap-2">
        <button onclick="exportExcel('tablaProveedores','Proveedores_Tauro')" class="btn btn-light">📊 Excel</button>
        <?php if(hasPermission('proveedores','crear')): ?><button onclick="openModal('modalProveedor')" class="btn btn-primary">+ Nuevo Proveedor</button><?php endif; ?>
    </div>
</div>
<div class="card">
    <div class="card-header"><h5>🏭 Proveedores — <?= count($proveedores) ?></h5></div>
    <div class="card-body p-0">
        <table class="table" id="tablaProveedores">
            <thead><tr><th>Nombre</th><th>RIF</th><th>Contacto</th><th>Teléfono</th><th>Email</th><th>País</th><th>Acciones</th></tr></thead>
            <tbody>
            <?php foreach($proveedores as $p): ?>
            <tr>
                <td><strong><?=clean($p['nombre'])?></strong></td>
                <td><?=clean($p['rif']?:'—')?></td>
                <td><?=clean($p['contacto']?:'—')?></td>
                <td><?=clean($p['telefono']?:'—')?></td>
                <td><?=clean($p['email']?:'—')?></td>
                <td><?=clean($p['pais'])?></td>
                <td><div class="d-flex gap-2">
                    <?php if(hasPermission('proveedores','editar')): ?><a href="proveedores.php?action=editar&id=<?=$p['id']?>" class="btn btn-sm btn-warning">✏️</a><?php endif; ?>
                </div></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<div class="modal-overlay" id="modalProveedor">
    <div class="modal-box">
        <div class="modal-header"><h4><?=$editP?'✏️ Editar Proveedor':'+ Nuevo Proveedor'?></h4><button class="btn-close" onclick="closeModal('modalProveedor')">✕</button></div>
        <form method="POST"><div class="modal-body">
            <input type="hidden" name="<?=CSRF_TOKEN_NAME?>" value="<?=getCsrfToken()?>">
            <input type="hidden" name="action" value="<?=$editP?'editar':'crear'?>">
            <?php if($editP): ?><input type="hidden" name="id" value="<?=$editP['id']?>"><?php endif; ?>
            <div class="form-group"><label class="form-label">Nombre / Empresa *</label><input type="text" name="nombre" class="form-control" value="<?=clean($editP['nombre']??'')?>" required></div>
            <div class="row">
                <div class="col-6"><div class="form-group"><label class="form-label">RIF</label><input type="text" name="rif" class="form-control" value="<?=clean($editP['rif']??'')?>"></div></div>
                <div class="col-6"><div class="form-group"><label class="form-label">Contacto</label><input type="text" name="contacto" class="form-control" value="<?=clean($editP['contacto']??'')?>"></div></div>
            </div>
            <div class="row">
                <div class="col-6"><div class="form-group"><label class="form-label">Teléfono</label><input type="text" name="telefono" class="form-control" value="<?=clean($editP['telefono']??'')?>"></div></div>
                <div class="col-6"><div class="form-group"><label class="form-label">Email</label><input type="email" name="email" class="form-control" value="<?=clean($editP['email']??'')?>"></div></div>
            </div>
            <div class="row">
                <div class="col-4"><div class="form-group"><label class="form-label">País</label><input type="text" name="pais" class="form-control" value="<?=clean($editP['pais']??'Venezuela')?>"></div></div>
                <div class="col-8"><div class="form-group"><label class="form-label">Dirección</label><input type="text" name="direccion" class="form-control" value="<?=clean($editP['direccion']??'')?>"></div></div>
            </div>
        </div>
        <div class="modal-footer"><button type="button" onclick="closeModal('modalProveedor')" class="btn btn-light">Cancelar</button><button type="submit" class="btn btn-primary">💾 Guardar</button></div>
        </form>
    </div>
</div>
<?php $extraScript=$editP?"<script>openModal('modalProveedor');</script>":"";
$extraScript .= '<script>
$(document).ready(function() {
    if ($.fn.DataTable.isDataTable("#tablaProveedores")) {
        $("#tablaProveedores").DataTable().destroy();
    }
    $("#tablaProveedores").DataTable({
        language: dtLang,
        pageLength: 20,
        order: [],
        columnDefs: [{ targets: "_all", defaultContent: "" }]
    });
});
</script>';
?>
<?php include 'includes/footer.php'; ?>
