<?php
require_once 'config/config.php';
require_once 'functions/db.php';
require_once 'functions/helpers.php';
require_once 'functions/auth.php';
requireLogin(); requirePermission('zonas','ver');
$pageTitle = 'Zonas';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $pa = $_POST['action'] ?? '';
    if (in_array($pa,['crear','editar'])) {
        $nombre = trim($_POST['nombre'] ?? '');
        $desc   = trim($_POST['descripcion'] ?? '');
        if (empty($nombre)) { $_SESSION['flash']=['type'=>'error','msg'=>'Nombre requerido.']; }
        else {
            if ($pa==='crear') {
                dbExecute("INSERT INTO zonas (nombre,descripcion,departamento_id) VALUES (?,?,1)",[$nombre,$desc]);
                auditLog('INSERT','zonas',(int)dbLastId(),"Zona creada: $nombre");
            } else {
                $id = cleanInt($_POST['id']??0);
                dbExecute("UPDATE zonas SET nombre=?,descripcion=? WHERE id=?",[$nombre,$desc,$id]);
                auditLog('UPDATE','zonas',$id,"Zona editada: $nombre");
            }
            $_SESSION['flash']=['type'=>'success','msg'=>'Zona guardada.'];
        }
        header('Location: zonas.php'); exit;
    }
    if ($pa==='eliminar') {
        $id = cleanInt($_POST['id']??0);
        $n  = dbQueryOne("SELECT COUNT(*) c FROM clientes WHERE zona_id=? AND activo=1",[$id])['c'];
        if ($n > 0) { $_SESSION['flash']=['type'=>'error','msg'=>"No puedes eliminar una zona con $n cliente(s) activos."]; }
        else {
            dbExecute("UPDATE zonas SET activo=0 WHERE id=?",[$id]);
            $_SESSION['flash']=['type'=>'success','msg'=>'Zona eliminada.'];
        }
        header('Location: zonas.php'); exit;
    }
}

$zonas = dbQuery(
    "SELECT z.*, COUNT(DISTINCT c.id) total_clientes,
            COUNT(DISTINCT v.id) total_vendedores
     FROM zonas z
     LEFT JOIN clientes c  ON c.zona_id=z.id AND c.activo=1
     LEFT JOIN vendedores v ON v.zona_id=z.id AND v.activo=1
     WHERE z.activo=1 AND z.departamento_id=1
     GROUP BY z.id ORDER BY z.nombre"
);

// Clientes por zona para mostrar en las tarjetas
$clientesPorZona = [];
$rows = dbQuery(
    "SELECT c.zona_id, c.nombre, c.tipo_default, c.rif
     FROM clientes c WHERE c.activo=1 AND c.zona_id IS NOT NULL ORDER BY c.nombre"
);
foreach ($rows as $r) $clientesPorZona[$r['zona_id']][] = $r;

// Sin zona
$sinZona = dbQueryOne("SELECT COUNT(*) c FROM clientes WHERE activo=1 AND zona_id IS NULL")['c'];

$editZ = ($_GET['action']??'')==='editar'
    ? dbQueryOne("SELECT * FROM zonas WHERE id=?",[cleanInt($_GET['id']??0)])
    : null;

$paleta = ['#2980b9','#27ae60','#8e44ad','#e67e22','#c0392b','#16a085','#d35400','#2c3e50'];

include 'includes/header.php';
?>
<style>
.zona-kanban { display:grid; grid-template-columns:repeat(auto-fill,minmax(280px,1fr)); gap:16px; }
.zona-card  { background:white; border:1px solid var(--border); border-radius:12px; overflow:hidden;
              box-shadow:0 2px 8px rgba(0,0,0,.06); transition:.2s; }
.zona-card:hover { box-shadow:0 4px 16px rgba(0,0,0,.12); transform:translateY(-1px); }
.zona-header { padding:14px 16px; display:flex; align-items:center; gap:10px; }
.zona-icon  { width:38px; height:38px; border-radius:10px; display:flex; align-items:center;
              justify-content:center; font-size:16px; color:white; flex-shrink:0; }
.zona-title { font-weight:800; font-size:14px; line-height:1.2; }
.zona-desc  { font-size:11px; color:rgba(255,255,255,.8); margin-top:2px; }
.zona-body  { padding:0 14px 10px; }
.zona-stat  { display:flex; align-items:center; gap:6px; font-size:11px; color:var(--text-muted);
              padding:4px 0; border-bottom:1px solid var(--border); }
.zona-stat:last-child { border-bottom:none; }
.zona-stat strong { color:var(--text); }
.cliente-pill { display:inline-flex; align-items:center; gap:4px; background:#f0f2f5;
                border-radius:6px; padding:3px 8px; font-size:11px; margin:2px; }
.cliente-pill.may { background:#e8f4fd; color:#2980b9; }
.cliente-pill.det { background:#fdf3e3; color:#e67e22; }
.zona-acciones { padding:10px 14px; border-top:1px solid var(--border); display:flex; gap:6px; justify-content:flex-end; }
.zona-sin-zona { background:white; border:2px dashed var(--border); border-radius:12px; padding:16px;
                 text-align:center; color:var(--text-muted); font-size:13px; }
</style>

<!-- Header -->
<div class="d-flex justify-between align-items-center mb-4">
    <div>
        <h3 style="margin:0;font-size:18px">🗺️ Zonas de Distribución</h3>
        <p style="margin:4px 0 0;font-size:12px;color:var(--text-muted)"><?= count($zonas) ?> zonas activas · <?= $sinZona ?> cliente(s) sin zona asignada</p>
    </div>
    <div class="d-flex gap-2">
        <?php if(hasPermission('zonas','crear')): ?>
        <button onclick="openModal('modalZona')" class="btn btn-primary">
            <i class="fa-solid fa-plus"></i> Nueva Zona
        </button>
        <?php endif; ?>
    </div>
</div>

<!-- Kanban de zonas -->
<div class="zona-kanban">

<?php foreach($zonas as $i => $z):
    $color = $paleta[$i % count($paleta)];
    $clientes = $clientesPorZona[$z['id']] ?? [];
    $mayoristas = array_filter($clientes, fn($c)=>$c['tipo_default']==='mayorista');
    $detalistas = array_filter($clientes, fn($c)=>$c['tipo_default']==='detalista');
?>
<div class="zona-card">
    <div class="zona-header" style="background:<?= $color ?>">
        <div class="zona-icon" style="background:rgba(255,255,255,.2)">
            <i class="fa-solid fa-map-location-dot"></i>
        </div>
        <div style="flex:1;min-width:0">
            <div class="zona-title" style="color:white"><?= clean($z['nombre']) ?></div>
            <?php if($z['descripcion']): ?>
            <div class="zona-desc"><?= clean(mb_strimwidth($z['descripcion'],0,45,'...')) ?></div>
            <?php endif; ?>
        </div>
        <div style="text-align:right;color:rgba(255,255,255,.9)">
            <div style="font-size:22px;font-weight:900;line-height:1"><?= $z['total_clientes'] ?></div>
            <div style="font-size:10px">clientes</div>
        </div>
    </div>

    <div class="zona-body">
        <div class="zona-stat">
            <i class="fa-solid fa-industry" style="color:#2980b9"></i>
            <span>Mayoristas: <strong><?= count($mayoristas) ?></strong></span>
            <span style="margin-left:auto">
            <i class="fa-solid fa-store" style="color:#e67e22"></i>
            Detalistas: <strong><?= count($detalistas) ?></strong>
            </span>
        </div>

        <?php if(!empty($clientes)): ?>
        <div style="margin-top:8px;max-height:110px;overflow-y:auto">
            <?php foreach($clientes as $cl): ?>
            <span class="cliente-pill <?= $cl['tipo_default']==='mayorista'?'may':'det' ?>">
                <?= $cl['tipo_default']==='mayorista'?'🏭':'🛍️' ?>
                <?= clean(mb_strimwidth($cl['nombre'],0,22,'...')) ?>
            </span>
            <?php endforeach; ?>
        </div>
        <?php else: ?>
        <p style="font-size:12px;color:var(--text-muted);text-align:center;padding:10px 0;margin:0">Sin clientes aún</p>
        <?php endif; ?>
    </div>

    <?php if(hasPermission('zonas','editar')): ?>
    <div class="zona-acciones">
        <a href="clientes.php?zona=<?= $z['id'] ?>" class="btn btn-sm btn-light" title="Ver clientes">
            <i class="fa-solid fa-users"></i> Clientes
        </a>
        <a href="zonas.php?action=editar&id=<?= $z['id'] ?>" class="btn btn-sm btn-warning btn-icon" title="Editar">
            <i class="fa-solid fa-pen"></i>
        </a>
        <?php if($z['total_clientes'] == 0): ?>
        <button onclick="eliminarZona(<?= $z['id'] ?>,'<?= addslashes(clean($z['nombre'])) ?>')"
            class="btn btn-sm btn-danger btn-icon" title="Eliminar">
            <i class="fa-solid fa-trash"></i>
        </button>
        <?php endif; ?>
    </div>
    <?php endif; ?>
</div>
<?php endforeach; ?>

<?php if($sinZona > 0): ?>
<div class="zona-sin-zona">
    <i class="fa-solid fa-circle-question" style="font-size:28px;opacity:.4;margin-bottom:8px"></i>
    <div><strong><?= $sinZona ?></strong> cliente(s) sin zona asignada</div>
    <a href="clientes.php" class="btn btn-sm btn-light" style="margin-top:8px">Ver en clientes</a>
</div>
<?php endif; ?>

<?php if(empty($zonas)): ?>
<div style="grid-column:1/-1;text-align:center;padding:64px;color:var(--text-muted)">
    <i class="fa-solid fa-map" style="font-size:64px;opacity:.2"></i>
    <h3 style="margin-top:16px">Sin zonas creadas</h3>
    <p>Crea zonas para organizar tus clientes por área geográfica.</p>
    <button onclick="openModal('modalZona')" class="btn btn-primary" style="margin-top:8px">
        <i class="fa-solid fa-plus"></i> Crear primera zona
    </button>
</div>
<?php endif; ?>

</div><!-- /kanban -->

<!-- MODAL Crear/Editar Zona -->
<div class="modal-overlay" id="modalZona">
<div class="modal-box" style="max-width:480px">
    <div class="modal-header">
        <h4><i class="fa-solid fa-map-location-dot"></i> <?= $editZ ? 'Editar Zona' : 'Nueva Zona' ?></h4>
        <button onclick="closeModal('modalZona')" class="btn btn-light btn-sm btn-icon">✕</button>
    </div>
    <form method="POST">
    <div class="modal-body" style="display:grid;gap:14px">
        <input type="hidden" name="<?= CSRF_TOKEN_NAME ?>" value="<?= getCsrfToken() ?>">
        <input type="hidden" name="action" value="<?= $editZ ? 'editar' : 'crear' ?>">
        <?php if($editZ): ?><input type="hidden" name="id" value="<?= $editZ['id'] ?>"><?php endif; ?>
        <div class="form-group">
            <label class="form-label">Nombre de la Zona *</label>
            <input type="text" name="nombre" class="form-control"
                value="<?= clean($editZ['nombre']??'') ?>"
                placeholder="Ej: Valencia Norte, Guacara, Zona Industrial..." required>
        </div>
        <div class="form-group">
            <label class="form-label">Descripción</label>
            <textarea name="descripcion" class="form-control" rows="2"
                placeholder="Municipios, sectores o referencia..."><?= clean($editZ['descripcion']??'') ?></textarea>
        </div>
    </div>
    <div class="modal-footer">
        <button type="button" onclick="closeModal('modalZona')" class="btn btn-light">Cancelar</button>
        <button type="submit" class="btn btn-primary">
            <i class="fa-solid fa-floppy-disk"></i> <?= $editZ ? 'Guardar Cambios' : 'Crear Zona' ?>
        </button>
    </div>
    </form>
</div>
</div>

<!-- Form eliminar -->
<form method="POST" id="formDelZona" style="display:none">
    <input type="hidden" name="<?= CSRF_TOKEN_NAME ?>" value="<?= getCsrfToken() ?>">
    <input type="hidden" name="action" value="eliminar">
    <input type="hidden" name="id" id="delZonaId">
</form>

<?php
$extraScript = "
<script>
function eliminarZona(id, nombre) {
    Swal.fire({ title:'¿Eliminar zona?', text:nombre, icon:'warning',
        showCancelButton:true, confirmButtonColor:'#c0392b',
        confirmButtonText:'Sí, eliminar', cancelButtonText:'Cancelar'
    }).then(r => { if(r.isConfirmed){ document.getElementById('delZonaId').value=id; document.getElementById('formDelZona').submit(); }});
}
" . ($editZ ? "openModal('modalZona');" : "") . "
</script>";
include 'includes/footer.php';
?>
