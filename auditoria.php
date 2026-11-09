<?php
require_once 'config/config.php';
require_once 'functions/db.php';
require_once 'functions/helpers.php';
require_once 'functions/auth.php';

requireLogin();
requirePermission('auditoria','ver');
$pageTitle = 'Auditoría del Sistema';

$filtroModulo  = clean($_GET['modulo'] ?? '');
$filtroAccion  = clean($_GET['accion'] ?? '');
$filtroUsuario = cleanInt($_GET['usuario'] ?? 0);
$filtroFechaIni= clean($_GET['fecha_ini'] ?? date('Y-m-d'));
$filtroFechaFin= clean($_GET['fecha_fin'] ?? date('Y-m-d'));
$busqueda      = clean($_GET['q'] ?? '');

$sql = "SELECT a.*, u.nombre u_nombre FROM auditoria a
        LEFT JOIN usuarios u ON a.usuario_id = u.id
        WHERE DATE(a.fecha) BETWEEN ? AND ?";
$params = [$filtroFechaIni, $filtroFechaFin];
if ($filtroModulo)  { $sql .= " AND a.modulo=?"; $params[] = $filtroModulo; }
if ($filtroAccion)  { $sql .= " AND a.accion=?"; $params[] = $filtroAccion; }
if ($filtroUsuario) { $sql .= " AND a.usuario_id=?"; $params[] = $filtroUsuario; }
if ($busqueda)      { $sql .= " AND (a.descripcion LIKE ? OR a.username LIKE ?)"; $params[] = "%$busqueda%"; $params[] = "%$busqueda%"; }
$sql .= " ORDER BY a.fecha DESC LIMIT 500";

$registros = dbQuery($sql, $params);
$usuarios  = dbQuery("SELECT id, nombre FROM usuarios ORDER BY nombre");

$modulos = ['sistema','productos','ventas','clientes','vendedores','proveedores','zonas','pagos','devoluciones','tasas_cambio','usuarios','compras'];
$acciones = ['LOGIN','LOGOUT','INSERT','UPDATE','DELETE','VIEW'];

$coloresAccion = [
    'INSERT' => 'bg-success',
    'UPDATE' => 'bg-warning',
    'DELETE' => 'bg-danger',
    'LOGIN'  => 'bg-info',
    'LOGOUT' => 'bg-secondary',
    'VIEW'   => 'bg-primary',
];

include 'includes/header.php';
?>

<!-- FILTROS -->
<div class="card mb-4">
    <div class="card-body" style="padding:14px 16px">
        <form method="GET" class="d-flex gap-2" style="flex-wrap:wrap;align-items:center">
            <input type="date" name="fecha_ini" class="form-control" value="<?= $filtroFechaIni ?>" style="max-width:160px">
            <span style="color:var(--text-muted)">a</span>
            <input type="date" name="fecha_fin" class="form-control" value="<?= $filtroFechaFin ?>" style="max-width:160px">
            <select name="modulo" class="form-select" style="max-width:150px">
                <option value="">Todos los módulos</option>
                <?php foreach ($modulos as $m): ?>
                <option value="<?= $m ?>" <?= $filtroModulo===$m?'selected':'' ?>><?= ucfirst($m) ?></option>
                <?php endforeach; ?>
            </select>
            <select name="accion" class="form-select" style="max-width:130px">
                <option value="">Todas las acciones</option>
                <?php foreach ($acciones as $a): ?>
                <option value="<?= $a ?>" <?= $filtroAccion===$a?'selected':'' ?>><?= $a ?></option>
                <?php endforeach; ?>
            </select>
            <select name="usuario" class="form-select" style="max-width:160px">
                <option value="">Todos los usuarios</option>
                <?php foreach ($usuarios as $u): ?>
                <option value="<?= $u['id'] ?>" <?= $filtroUsuario==$u['id']?'selected':'' ?>><?= clean($u['nombre']) ?></option>
                <?php endforeach; ?>
            </select>
            <input type="text" name="q" class="form-control" placeholder="🔍 Buscar..." value="<?= $busqueda ?>" style="max-width:200px">
            <button type="submit" class="btn btn-primary">Filtrar</button>
            <a href="auditoria.php" class="btn btn-light">✕ Limpiar</a>
        </form>
    </div>
</div>

<div class="card">
    <div class="card-header">
        <h5>📋 Registro de Auditoría — <?= count($registros) ?> registros</h5>
        <button onclick="exportExcel('tablaAuditoria','Auditoria_Tauro')" class="btn btn-sm btn-light" style="margin-left:auto">📊 Excel</button>
    </div>
    <div class="card-body p-0">
        <div class="table-wrapper">
        <table class="table" id="tablaAuditoria">
            <thead>
                <tr>
                    <th>Fecha/Hora</th>
                    <th>Usuario</th>
                    <th>Acción</th>
                    <th>Módulo</th>
                    <th>ID Registro</th>
                    <th>Descripción</th>
                    <th>IP</th>
                    <th>Detalle</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($registros as $r): ?>
            <tr>
                <td style="font-size:12px;white-space:nowrap"><?= date('d/m/Y H:i:s',strtotime($r['fecha'])) ?></td>
                <td>
                    <strong style="font-family:monospace;font-size:12px"><?= clean($r['username'] ?? '—') ?></strong>
                    <?php if ($r['u_nombre']): ?><br><small style="color:var(--text-muted)"><?= clean($r['u_nombre']) ?></small><?php endif; ?>
                </td>
                <td><span class="badge <?= $coloresAccion[$r['accion']] ?? 'bg-secondary' ?>"><?= clean($r['accion']) ?></span></td>
                <td><span class="badge badge-secondary" style="font-size:10px"><?= clean($r['modulo'] ?? '—') ?></span></td>
                <td class="text-center" style="font-family:monospace"><?= $r['registro_id'] ?: '—' ?></td>
                <td style="font-size:12px;max-width:300px"><?= clean(mb_strimwidth($r['descripcion'] ?? '', 0, 100, '...')) ?></td>
                <td style="font-size:11px;font-family:monospace;color:var(--text-muted)"><?= clean($r['ip'] ?? '') ?></td>
                <td>
                    <?php if ($r['datos_anteriores'] || $r['datos_nuevos']): ?>
                    <button onclick="verDetalle('<?= addslashes(htmlspecialchars(json_encode(['antes'=>$r['datos_anteriores'],'despues'=>$r['datos_nuevos']]), ENT_QUOTES)) ?>')"
                        class="btn btn-sm btn-light" title="Ver cambios">🔍</button>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    </div>
</div>

<!-- MODAL: Ver detalle de cambio -->
<div class="modal-overlay" id="modalDetalle">
    <div class="modal-box" style="max-width:700px">
        <div class="modal-header">
            <h4>🔍 Detalle del Cambio</h4>
            <button class="btn-close" onclick="closeModal('modalDetalle')">✕</button>
        </div>
        <div class="modal-body">
            <div class="row">
                <div class="col-6">
                    <strong style="color:var(--danger)">Antes:</strong>
                    <pre id="detalleAntes" style="background:#fef2f2;border-radius:6px;padding:12px;font-size:12px;margin-top:6px;overflow:auto;max-height:300px"></pre>
                </div>
                <div class="col-6">
                    <strong style="color:var(--success)">Después:</strong>
                    <pre id="detalleDespues" style="background:#f0fdf4;border-radius:6px;padding:12px;font-size:12px;margin-top:6px;overflow:auto;max-height:300px"></pre>
                </div>
            </div>
        </div>
    </div>
</div>

<?php
$extraScript = "
<script>
function verDetalle(jsonStr) {
    try {
        const data = JSON.parse(jsonStr);
        document.getElementById('detalleAntes').textContent = data.antes ? JSON.stringify(JSON.parse(data.antes), null, 2) : 'Sin datos';
        document.getElementById('detalleDespues').textContent = data.despues ? JSON.stringify(JSON.parse(data.despues), null, 2) : 'Sin datos';
    } catch(e) {
        document.getElementById('detalleAntes').textContent = 'Error al parsear datos';
        document.getElementById('detalleDespues').textContent = '';
    }
    openModal('modalDetalle');
}
</script>";
$extraScript .= '<script>
$(document).ready(function() {
    if ($.fn.DataTable.isDataTable("#tablaAuditoria")) {
        $("#tablaAuditoria").DataTable().destroy();
    }
    $("#tablaAuditoria").DataTable({
        language: dtLang,
        pageLength: 20,
        order: [],
        columnDefs: [{ targets: "_all", defaultContent: "" }]
    });
});
</script>';
?>
<?php include 'includes/footer.php'; ?>
