<?php
require_once 'config/config.php';
require_once 'functions/db.php';
require_once 'functions/helpers.php';
require_once 'functions/auth.php';

requireLogin();
requirePermission('clientes','ver');
$pageTitle = 'Clientes';

// ── ACCIONES ───────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $pa = $_POST['action'] ?? '';

    if (in_array($pa, ['crear','editar'])) {
        requirePermission('clientes', $pa==='crear' ? 'crear' : 'editar');
        $data = [
            'nombre'         => trim($_POST['nombre']         ?? ''),
            'rif'            => strtoupper(trim($_POST['rif'] ?? '')),
            'telefono'       => trim($_POST['telefono']       ?? ''),
            'email'          => trim($_POST['email']          ?? ''),
            'direccion'      => trim($_POST['direccion']      ?? ''),
            'zona_id'        => cleanInt($_POST['zona_id']    ?? 0) ?: null,
            'vendedor_id'    => cleanInt($_POST['vendedor_id']?? 0) ?: null,
            'tipo_default'   => in_array($_POST['tipo_default']??'',['mayorista','detalista']) ? $_POST['tipo_default'] : 'detalista',
            'limite_credito' => cleanFloat($_POST['limite_credito'] ?? 0),
        ];
        if (empty($data['nombre'])) {
            $_SESSION['flash'] = ['type'=>'error','msg'=>'El nombre es requerido.'];
        } else {
            if ($pa === 'crear') {
                dbExecute(
                    "INSERT INTO clientes (nombre,rif,telefono,email,direccion,zona_id,vendedor_id,tipo_default,limite_credito)
                     VALUES (?,?,?,?,?,?,?,?,?)",
                    array_values($data)
                );
                $id = (int)dbLastId();
                auditLog('INSERT','clientes',$id,'Cliente creado: '.$data['nombre'],[],$data);
            } else {
                $id  = cleanInt($_POST['id'] ?? 0);
                $ant = dbQueryOne("SELECT * FROM clientes WHERE id=?",[$id]);
                dbExecute(
                    "UPDATE clientes SET nombre=?,rif=?,telefono=?,email=?,direccion=?,zona_id=?,vendedor_id=?,tipo_default=?,limite_credito=? WHERE id=?",
                    [...array_values($data), $id]
                );
                auditLog('UPDATE','clientes',$id,'Cliente editado',$ant,$data);
            }
            $_SESSION['flash'] = ['type'=>'success','msg'=>'Cliente guardado correctamente.'];
        }
        header('Location: clientes.php'); exit;
    }

    if ($pa === 'eliminar') {
        requirePermission('clientes','eliminar');
        $id  = cleanInt($_POST['id'] ?? 0);
        $ant = dbQueryOne("SELECT * FROM clientes WHERE id=?",[$id]);
        dbExecute("UPDATE clientes SET activo=0 WHERE id=?",[$id]);
        auditLog('DELETE','clientes',$id,'Cliente desactivado: '.($ant['nombre']??''),$ant,[]);
        $_SESSION['flash'] = ['type'=>'success','msg'=>'Cliente eliminado.'];
        header('Location: clientes.php'); exit;
    }
}

// ── DATOS ──────────────────────────────────────────────────
$zonas      = dbQuery("SELECT * FROM zonas WHERE activo=1 ORDER BY nombre");
$vendedores = dbQuery("SELECT * FROM vendedores WHERE activo=1 AND departamento_id=1 ORDER BY nombre");
$busqueda   = clean($_GET['q']    ?? '');
$filtroZona = cleanInt($_GET['zona'] ?? 0);
$filtroTipo = clean($_GET['tipo'] ?? '');

$sql = "SELECT c.*, z.nombre z_nombre, v.nombre v_nombre,
               COALESCE(c.saldo_pendiente,0) saldo
        FROM clientes c
        LEFT JOIN zonas z     ON c.zona_id    = z.id
        LEFT JOIN vendedores v ON c.vendedor_id = v.id
        WHERE c.activo=1";
$params = [];
if ($busqueda) {
    $sql .= " AND (c.nombre LIKE ? OR c.rif LIKE ? OR c.telefono LIKE ? OR c.direccion LIKE ?)";
    $params = array_merge($params,["%$busqueda%","%$busqueda%","%$busqueda%","%$busqueda%"]);
}
if ($filtroZona) { $sql .= " AND c.zona_id=?";    $params[]=$filtroZona; }
if ($filtroTipo) { $sql .= " AND c.tipo_default=?";$params[]=$filtroTipo; }
$sql .= " ORDER BY c.nombre";
$clientes = dbQuery($sql,$params);

$editCliente = null;
if (($_GET['action']??'')==='editar' && isset($_GET['id'])) {
    $editCliente = dbQueryOne("SELECT * FROM clientes WHERE id=?",[cleanInt($_GET['id'])]);
}

include 'includes/header.php';
?>

<!-- STATS RÁPIDOS -->
<div class="row mb-4">
    <?php
    $totalCli   = count($clientes);
    $mayoristas = count(array_filter($clientes, fn($c) => $c['tipo_default']==='mayorista'));
    $detalistas = $totalCli - $mayoristas;
    ?>
    <div class="col-4"><div class="stat-card"><div class="stat-icon"><i class="fa-solid fa-users"></i></div>
        <div><div class="stat-value"><?= $totalCli ?></div><div class="stat-label">Clientes activos</div></div>
    </div></div>
    <div class="col-4"><div class="stat-card blue"><div class="stat-icon"><i class="fa-solid fa-industry"></i></div>
        <div><div class="stat-value"><?= $mayoristas ?></div><div class="stat-label">Mayoristas</div></div>
    </div></div>
    <div class="col-4"><div class="stat-card orange"><div class="stat-icon"><i class="fa-solid fa-store"></i></div>
        <div><div class="stat-value"><?= $detalistas ?></div><div class="stat-label">Detalistas</div></div>
    </div></div>
</div>

<!-- TOOLBAR -->
<div class="d-flex justify-between align-center mb-4">
    <div class="d-flex gap-2">
        <a href="clientes.php" class="btn btn-sm <?= !$filtroTipo?'btn-primary':'btn-light' ?>">Todos</a>
        <a href="clientes.php?tipo=mayorista" class="btn btn-sm <?= $filtroTipo==='mayorista'?'btn-primary':'btn-light' ?>">Mayoristas</a>
        <a href="clientes.php?tipo=detalista" class="btn btn-sm <?= $filtroTipo==='detalista'?'btn-warning':'btn-light' ?>">Detalistas</a>
    </div>
    <div class="d-flex gap-2">
        <button onclick="exportExcel('tablaClientes','Clientes_Tauro')" class="btn btn-sm btn-light">
            <i class="fa-solid fa-table-cells"></i> Excel
        </button>
        <?php if (hasPermission('clientes','crear')): ?>
        <button onclick="openModal('modalCliente')" class="btn btn-primary btn-sm">
            <i class="fa-solid fa-plus"></i> Nuevo Cliente
        </button>
        <?php endif; ?>
    </div>
</div>

<!-- BÚSQUEDA -->
<div class="card mb-4">
<div class="card-body" style="padding:10px 16px">
<form method="GET" class="d-flex gap-2 align-center">
    <i class="fa-solid fa-magnifying-glass text-muted"></i>
    <input type="text" name="q" class="form-control" placeholder="Nombre, RIF, teléfono o dirección..."
           value="<?= $busqueda ?>" style="max-width:340px">
    <select name="zona" class="form-select" style="max-width:180px">
        <option value="">Todas las zonas</option>
        <?php foreach ($zonas as $z): ?>
        <option value="<?= $z['id'] ?>" <?= $filtroZona==$z['id']?'selected':'' ?>><?= clean($z['nombre']) ?></option>
        <?php endforeach; ?>
    </select>
    <?php if ($filtroTipo): ?><input type="hidden" name="tipo" value="<?= $filtroTipo ?>"><?php endif; ?>
    <button type="submit" class="btn btn-primary btn-sm">Buscar</button>
    <?php if ($busqueda||$filtroZona): ?><a href="clientes.php<?= $filtroTipo?"?tipo=$filtroTipo":'' ?>" class="btn btn-light btn-sm"><i class="fa-solid fa-xmark"></i></a><?php endif; ?>
</form>
</div>
</div>

<!-- TABLA -->
<div class="card">
<div class="card-header">
    <i class="fa-solid fa-users text-primary"></i>
    <h5>Clientes — <?= count($clientes) ?> registros</h5>

</div>
<div class="card-body p-0">
<div class="table-wrapper">
<table id="tablaClientes" style="width:100%;border-collapse:collapse;font-size:13px">
    <thead>
        <tr style="background:#f8fafc">
            <th style="padding:10px 12px;font-size:11px;font-weight:700;color:var(--text-muted);text-transform:uppercase;border-bottom:2px solid var(--border)">Nombre / Razón Social</th>
            <th style="padding:10px 12px;font-size:11px;font-weight:700;color:var(--text-muted);text-transform:uppercase;border-bottom:2px solid var(--border)">RIF / CI</th>
            <th style="padding:10px 12px;font-size:11px;font-weight:700;color:var(--text-muted);text-transform:uppercase;border-bottom:2px solid var(--border)">Teléfono</th>
            <th style="padding:10px 12px;font-size:11px;font-weight:700;color:var(--text-muted);text-transform:uppercase;border-bottom:2px solid var(--border)">Dirección</th>
            <th style="padding:10px 12px;font-size:11px;font-weight:700;color:var(--text-muted);text-transform:uppercase;border-bottom:2px solid var(--border)">Zona</th>
            <th style="padding:10px 12px;font-size:11px;font-weight:700;color:var(--text-muted);text-transform:uppercase;border-bottom:2px solid var(--border)">Vendedor</th>
            <th style="padding:10px 12px;font-size:11px;font-weight:700;color:var(--text-muted);text-transform:uppercase;border-bottom:2px solid var(--border)">Tipo</th>
            <th style="padding:10px 12px;font-size:11px;font-weight:700;color:var(--text-muted);text-transform:uppercase;border-bottom:2px solid var(--border)">Acciones</th>
        </tr>
    </thead>
    <tbody>
    <?php foreach ($clientes as $c): ?>
    <tr style="border-bottom:1px solid var(--border)">
        <td style="padding:10px 12px">
            <strong><?= clean($c['nombre']) ?></strong>
            <?php if ($c['email']): ?><br><small class="text-muted"><?= clean($c['email']) ?></small><?php endif; ?>
        </td>
        <td style="padding:10px 12px;font-family:monospace;font-size:12px;font-weight:600"><?= clean($c['rif']??'—') ?></td>
        <td style="padding:10px 12px;font-size:12px"><?= clean($c['telefono']??'—') ?></td>
        <td style="padding:10px 12px;font-size:11px;color:var(--text-muted);max-width:180px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">
            <?= clean(mb_strimwidth($c['direccion']??'—',0,50,'...')) ?>
        </td>
        <td style="padding:10px 12px"><span class="badge badge-secondary fs-11"><?= clean($c['z_nombre']??'—') ?></span></td>
        <td style="padding:10px 12px;font-size:12px"><?= clean($c['v_nombre']??'—') ?></td>
        <td style="padding:10px 12px"><?= badgeTipo($c['tipo_default']) ?></td>

        <!-- Siempre 1 <td> — mantiene el column count exacto en 9 -->
        <td style="padding:10px 12px">
            <div class="d-flex gap-1">
                <a href="documentos.php?q=<?= urlencode($c['nombre']) ?>" class="btn btn-sm btn-light btn-icon" title="Ver documentos">
                    <i class="fa-solid fa-file-invoice"></i>
                </a>
                <a href="transporte.php" class="btn btn-sm btn-light btn-icon" title="Reporte transporte">
                    <i class="fa-solid fa-truck"></i>
                </a>
                <?php if (hasPermission('clientes','editar')): ?>
                <a href="clientes.php?action=editar&id=<?= $c['id'] ?>" class="btn btn-sm btn-warning btn-icon" title="Editar">
                    <i class="fa-solid fa-pen"></i>
                </a>
                <?php endif; ?>
                <?php if (hasPermission('clientes','eliminar')): ?>
                <button onclick="confirmarEliminar(<?= $c['id'] ?>,'<?= addslashes(clean($c['nombre'])) ?>')"
                    class="btn btn-sm btn-danger btn-icon" title="Eliminar">
                    <i class="fa-solid fa-trash"></i>
                </button>
                <?php endif; ?>
            </div>
        </td>
    </tr>
    <?php endforeach; ?>
    </tbody>
</table>
</div>
</div>
</div>

<!-- MODAL: Crear/Editar Cliente -->
<div class="modal-overlay" id="modalCliente">
<div class="modal-box">
    <div class="modal-header">
        <h4><?= $editCliente ? 'Editar Cliente' : 'Nuevo Cliente' ?></h4>
        <button class="btn-close" onclick="closeModal('modalCliente')"><i class="fa-solid fa-xmark"></i></button>
    </div>
    <form method="POST">
    <div class="modal-body">
        <input type="hidden" name="<?= CSRF_TOKEN_NAME ?>" value="<?= getCsrfToken() ?>">
        <input type="hidden" name="action" value="<?= $editCliente ? 'editar' : 'crear' ?>">
        <?php if ($editCliente): ?><input type="hidden" name="id" value="<?= $editCliente['id'] ?>"><?php endif; ?>

        <div class="row">
            <div class="col-8">
                <div class="form-group">
                    <label class="form-label">Nombre / Razón Social *</label>
                    <input type="text" name="nombre" class="form-control"
                        value="<?= clean($editCliente['nombre']??'') ?>" required maxlength="150">
                </div>
            </div>
            <div class="col-4">
                <div class="form-group">
                    <label class="form-label">RIF / CI</label>
                    <input type="text" name="rif" class="form-control"
                        value="<?= clean($editCliente['rif']??'') ?>"
                        placeholder="J-12345678-9 / V-12345678"
                        maxlength="20" style="text-transform:uppercase">
                </div>
            </div>
        </div>

        <div class="row">
            <div class="col-6">
                <div class="form-group">
                    <label class="form-label">Teléfono</label>
                    <input type="text" name="telefono" class="form-control"
                        value="<?= clean($editCliente['telefono']??'') ?>"
                        placeholder="0414-0000000" maxlength="20">
                </div>
            </div>
            <div class="col-6">
                <div class="form-group">
                    <label class="form-label">Email</label>
                    <input type="email" name="email" class="form-control"
                        value="<?= clean($editCliente['email']??'') ?>" maxlength="100">
                </div>
            </div>
        </div>

        <div class="form-group">
            <label class="form-label">Dirección completa</label>
            <textarea name="direccion" class="form-control" rows="2"
                placeholder="Calle, sector, municipio, estado..."><?= clean($editCliente['direccion']??'') ?></textarea>
        </div>

        <div class="row">
            <div class="col-4">
                <div class="form-group">
                    <label class="form-label">Zona</label>
                    <select name="zona_id" class="form-select">
                        <option value="">— Sin zona —</option>
                        <?php foreach ($zonas as $z): ?>
                        <option value="<?= $z['id'] ?>" <?= ($editCliente['zona_id']??0)==$z['id']?'selected':'' ?>><?= clean($z['nombre']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="col-4">
                <div class="form-group">
                    <label class="form-label">Vendedor Asignado</label>
                    <select name="vendedor_id" class="form-select">
                        <option value="">— Sin asignar —</option>
                        <?php foreach ($vendedores as $v): ?>
                        <option value="<?= $v['id'] ?>" <?= ($editCliente['vendedor_id']??0)==$v['id']?'selected':'' ?>><?= clean($v['nombre']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="col-4">
                <div class="form-group">
                    <label class="form-label">Tipo de Cliente</label>
                    <select name="tipo_default" class="form-select">
                        <option value="detalista" <?= ($editCliente['tipo_default']??'detalista')==='detalista'?'selected':'' ?>>Detalista</option>
                        <option value="mayorista" <?= ($editCliente['tipo_default']??'')==='mayorista'?'selected':'' ?>>Mayorista</option>
                    </select>
                </div>
            </div>
        </div>


    </div>
    <div class="modal-footer">
        <button type="button" onclick="closeModal('modalCliente')" class="btn btn-light">Cancelar</button>
        <button type="submit" class="btn btn-primary">
            <i class="fa-solid fa-floppy-disk"></i> Guardar Cliente
        </button>
    </div>
    </form>
</div>
</div>

<!-- Form eliminar -->
<form method="POST" id="formEliminar" style="display:none">
    <input type="hidden" name="<?= CSRF_TOKEN_NAME ?>" value="<?= getCsrfToken() ?>">
    <input type="hidden" name="action" value="eliminar">
    <input type="hidden" name="id" id="eliminarId">
</form>

<?php
$extraScript = "
<script>
function confirmarEliminar(id, nombre) {
    Swal.fire({ title:'¿Eliminar cliente?', text:nombre, icon:'warning',
        showCancelButton:true, confirmButtonColor:'#c0392b',
        confirmButtonText:'Sí, eliminar', cancelButtonText:'Cancelar'
    }).then(r => { if(r.isConfirmed){ document.getElementById('eliminarId').value=id; document.getElementById('formEliminar').submit(); }});
}

\$('#tablaClientes').DataTable({
    language: dtLang, pageLength:20, order:[],
    columnDefs:[{targets:[7],orderable:false}]
});
" . ($editCliente ? "openModal('modalCliente');" : "") . "
" . (isset($_GET['action']) && $_GET['action']==='nuevo' ? "openModal('modalCliente');" : "") . "
</script>";

include 'includes/footer.php';
?>
