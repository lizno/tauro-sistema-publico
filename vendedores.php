<?php
require_once 'config/config.php';
require_once 'functions/db.php';
require_once 'functions/helpers.php';
require_once 'functions/auth.php';

requireLogin();
requirePermission('vendedores','ver');
$pageTitle = 'Vendedores';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $pa = $_POST['action'] ?? '';

    if (in_array($pa, ['crear','editar'])) {
        requirePermission('vendedores', $pa === 'crear' ? 'crear' : 'editar');
        $data = [
            'nombre'           => trim($_POST['nombre'] ?? ''),
            'cedula'           => trim($_POST['cedula'] ?? ''),
            'telefono'         => trim($_POST['telefono'] ?? ''),
            'zona_id'          => cleanInt($_POST['zona_id'] ?? 0) ?: null,
            'comision_pct'     => cleanFloat($_POST['comision_pct'] ?? 0),
            'tipo_preferencia' => in_array($_POST['tipo_preferencia']??'',['mayorista','detalista','ambos']) ? $_POST['tipo_preferencia'] : 'ambos',
        ];
        if (empty($data['nombre'])) {
            $_SESSION['flash'] = ['type'=>'error','msg'=>'El nombre es requerido.'];
        } else {
            if ($pa === 'crear') {
                dbExecute(
                    "INSERT INTO vendedores (nombre,cedula,telefono,zona_id,comision_pct,tipo_preferencia,departamento_id)
                     VALUES (?,?,?,?,?,?,1)",
                    array_values($data)
                );
                $id = (int)dbLastId();
                auditLog('INSERT','vendedores',$id,'Vendedor creado: '.$data['nombre'],[],$data);
            } else {
                $id  = cleanInt($_POST['id'] ?? 0);
                $ant = dbQueryOne("SELECT * FROM vendedores WHERE id=?",[$id]);
                dbExecute(
                    "UPDATE vendedores SET nombre=?,cedula=?,telefono=?,zona_id=?,comision_pct=?,tipo_preferencia=? WHERE id=?",
                    [...array_values($data), $id]
                );
                auditLog('UPDATE','vendedores',$id,'Vendedor editado',$ant,$data);
            }
            $_SESSION['flash'] = ['type'=>'success','msg'=>'Vendedor guardado correctamente.'];
        }
        header('Location: vendedores.php'); exit;
    }

    if ($pa === 'eliminar') {
        $id = cleanInt($_POST['id'] ?? 0);
        dbExecute("UPDATE vendedores SET activo=0 WHERE id=?",[$id]);
        auditLog('DELETE','vendedores',$id,'Vendedor desactivado');
        $_SESSION['flash'] = ['type'=>'success','msg'=>'Vendedor eliminado.'];
        header('Location: vendedores.php'); exit;
    }
}

// ── DATOS ──────────────────────────────────────────────────
$zonas = dbQuery("SELECT * FROM zonas WHERE activo=1 ORDER BY nombre");

$vendedores = dbQuery(
    "SELECT v.*, z.nombre z_nombre,
            COUNT(DISTINCT vt.id) total_docs,
            COUNT(DISTINCT CASE WHEN vt.tipo_doc NOT IN('cotizacion','despacho') AND vt.estado!='anulado' THEN vt.id END) total_ventas,
            COALESCE(SUM(CASE WHEN vt.tipo_doc NOT IN('cotizacion','despacho') AND vt.estado!='anulado' THEN vt.total_usd ELSE 0 END),0) total_usd,
            COALESCE(SUM(CASE WHEN vt.tipo_doc NOT IN('cotizacion','despacho') AND vt.estado!='anulado' AND vt.tipo_cliente='mayorista' THEN vt.total_usd ELSE 0 END),0) total_mayor,
            COALESCE(SUM(CASE WHEN vt.tipo_doc NOT IN('cotizacion','despacho') AND vt.estado!='anulado' AND vt.tipo_cliente='detalista' THEN vt.total_usd ELSE 0 END),0) total_detal,
            COUNT(DISTINCT c.id) total_clientes
     FROM vendedores v
     LEFT JOIN zonas z ON v.zona_id = z.id
     LEFT JOIN ventas vt ON vt.vendedor_id = v.id
     LEFT JOIN clientes c ON c.vendedor_id = v.id AND c.activo=1
     WHERE v.activo=1 AND v.departamento_id=1
     GROUP BY v.id ORDER BY v.nombre"
);

$editVendedor = null;
if (($_GET['action']??'')==='editar' && isset($_GET['id'])) {
    $editVendedor = dbQueryOne("SELECT * FROM vendedores WHERE id=?",[cleanInt($_GET['id'])]);
}

// Vendedor seleccionado para ver detalle
$verVendedorId = cleanInt($_GET['ver'] ?? 0);
$verVendedor   = null;
$ventasVendedor= [];
if ($verVendedorId) {
    $verVendedor = dbQueryOne("SELECT v.*,z.nombre z_nombre FROM vendedores v LEFT JOIN zonas z ON v.zona_id=z.id WHERE v.id=?",[$verVendedorId]);
    $ventasVendedor = dbQuery(
        "SELECT vt.numero, vt.tipo_doc, vt.fecha, vt.tipo_cliente, vt.total_usd, vt.estado, c.nombre cliente_nombre
         FROM ventas vt
         LEFT JOIN clientes c ON vt.cliente_id=c.id
         WHERE vt.vendedor_id=? AND vt.tipo_doc NOT IN('cotizacion','despacho')
         ORDER BY vt.fecha DESC LIMIT 20",
        [$verVendedorId]
    );
}

include 'includes/header.php';
?>

<!-- STATS VENDEDORES -->
<div class="row mb-4">
    <?php
    $totalVendedores = count($vendedores);
    $totalVentasGral = array_sum(array_column($vendedores,'total_usd'));
    $totalMayor      = array_sum(array_column($vendedores,'total_mayor'));
    $totalDetal      = array_sum(array_column($vendedores,'total_detal'));
    ?>
    <div class="col-3">
        <div class="stat-card">
            <div class="stat-icon"><i class="fa-solid fa-handshake"></i></div>
            <div><div class="stat-value"><?= $totalVendedores ?></div><div class="stat-label">Vendedores activos</div></div>
        </div>
    </div>

</div>

<div class="row">
    <!-- TABLA VENDEDORES -->
    <div class="col-<?= $verVendedor ? '7' : '12' ?>">
        <div class="card">
            <div class="card-header">
                <i class="fa-solid fa-handshake text-primary"></i>
                <h5>Vendedores Tauro — <?= $totalVendedores ?></h5>
                <div class="d-flex gap-2" style="margin-left:auto">
                    <button onclick="exportExcel('tablaVendedores','Vendedores_Tauro')" class="btn btn-sm btn-light">
                        <i class="fa-solid fa-table-cells"></i> Excel
                    </button>
                    <?php if (hasPermission('vendedores','crear')): ?>
                    <button onclick="openModal('modalVendedor')" class="btn btn-sm btn-primary">
                        <i class="fa-solid fa-plus"></i> Nuevo Vendedor
                    </button>
                    <?php endif; ?>
                </div>
            </div>
            <div class="card-body p-0">
                <table class="table" id="tablaVendedores">
                    <thead>
                        <tr>
                            <th>Nombre</th>
                            <th>Cédula</th>
                            <th>Teléfono</th>
                            <th>Zona</th>
                            <th>Tipo Pref.</th>
                            <th>Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($vendedores as $v): ?>
                    <tr class="<?= $verVendedorId==$v['id'] ? 'selected-row' : '' ?>"
                        style="<?= $verVendedorId==$v['id'] ? 'background:#eff6ff' : '' ?>">
                        <td><strong><?= clean($v['nombre']) ?></strong></td>
                        <td class="fs-12"><?= clean($v['cedula'] ?: '—') ?></td>
                        <td class="fs-12"><?= clean($v['telefono'] ?: '—') ?></td>
                        <td><span class="badge badge-secondary fs-11"><?= clean($v['z_nombre'] ?? '—') ?></span></td>
                        <td>
                            <?php
                            $tipoPref = $v['tipo_preferencia'] ?? 'ambos';
                            $tipoBadge = ['mayorista'=>'badge-primary','detalista'=>'badge-warning','ambos'=>'badge-secondary'];
                            echo '<span class="badge '.$tipoBadge[$tipoPref].'">'.ucfirst($tipoPref).'</span>';
                            ?>
                        </td>

                        <td>
                            <div class="d-flex gap-1">
                                <a href="vendedores.php?ver=<?= $v['id'] ?>" class="btn btn-sm btn-light btn-icon" title="Ver detalle">
                                    <i class="fa-solid fa-chart-bar"></i>
                                </a>
                                <a href="documentos.php?vendedor=<?= $v['id'] ?>" class="btn btn-sm btn-light btn-icon" title="Ver documentos">
                                    <i class="fa-solid fa-file-invoice"></i>
                                </a>
                                <?php if (hasPermission('vendedores','editar')): ?>
                                <a href="vendedores.php?action=editar&id=<?= $v['id'] ?>" class="btn btn-sm btn-warning btn-icon" title="Editar">
                                    <i class="fa-solid fa-pen"></i>
                                </a>
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

    <!-- PANEL DETALLE VENDEDOR -->
    <?php if ($verVendedor): ?>
    <div class="col-5">
        <div class="card">
            <div class="card-header">
                <i class="fa-solid fa-user text-primary"></i>
                <h5><?= clean($verVendedor['nombre']) ?></h5>
                <a href="vendedores.php" class="btn btn-sm btn-light" style="margin-left:auto">
                    <i class="fa-solid fa-xmark"></i>
                </a>
            </div>
            <div class="card-body">
                <!-- Info -->
                <div style="background:#f8fafc;border-radius:8px;padding:12px;margin-bottom:14px;font-size:13px">
                    <div class="d-flex justify-between mb-1"><span class="text-muted">Cédula:</span><strong><?= clean($verVendedor['cedula']??'—') ?></strong></div>
                    <div class="d-flex justify-between mb-1"><span class="text-muted">Teléfono:</span><strong><?= clean($verVendedor['telefono']??'—') ?></strong></div>
                    <div class="d-flex justify-between mb-1"><span class="text-muted">Zona:</span><strong><?= clean($verVendedor['z_nombre']??'—') ?></strong></div>
                    <div class="d-flex justify-between mb-1"><span class="text-muted">Tipo preferencia:</span><?= badgeTipo($verVendedor['tipo_preferencia']??'ambos') ?></div>
                    <div class="d-flex justify-between"><span class="text-muted">Comisión:</span><strong><?= $verVendedor['comision_pct'] ?>%</strong></div>
                </div>

                <!-- Stats -->
                <?php
                $statsV = dbQueryOne(
                    "SELECT COUNT(*) total, COALESCE(SUM(CASE WHEN tipo_cliente='mayorista' THEN total_usd ELSE 0 END),0) mayor,
                            COALESCE(SUM(CASE WHEN tipo_cliente='detalista' THEN total_usd ELSE 0 END),0) detal,
                            COALESCE(SUM(total_usd),0) total_usd
                     FROM ventas WHERE vendedor_id=? AND tipo_doc!='cotizacion' AND estado!='anulado'",
                    [$verVendedorId]
                );
                $comisionTotal = ($statsV['total_usd'] ?? 0) * ($verVendedor['comision_pct'] / 100);
                ?>
                <div class="row mb-3">
                    <div class="col-6">
                        <div style="background:rgba(41,128,185,.08);border-radius:8px;padding:10px;text-align:center">
                            <div class="fw-800" style="font-size:16px;color:var(--info)"><?= formatUSD($statsV['mayor']??0) ?></div>
                            <div class="fs-11 text-muted">Mayorista</div>
                        </div>
                    </div>
                    <div class="col-6">
                        <div style="background:rgba(230,126,34,.08);border-radius:8px;padding:10px;text-align:center">
                            <div class="fw-800" style="font-size:16px;color:var(--warning)"><?= formatUSD($statsV['detal']??0) ?></div>
                            <div class="fs-11 text-muted">Detalista</div>
                        </div>
                    </div>
                </div>
                <div style="background:rgba(39,174,96,.08);border-radius:8px;padding:10px;text-align:center;margin-bottom:14px">
                    <div style="font-size:11px;color:var(--text-muted)">COMISIÓN ESTIMADA</div>
                    <div class="fw-800" style="font-size:20px;color:var(--success)"><?= formatUSD($comisionTotal) ?></div>
                    <div class="fs-11 text-muted">sobre <?= formatUSD($statsV['total_usd']??0) ?> facturado</div>
                </div>

                <!-- Últimas ventas -->
                <h6 class="fw-bold fs-12 mb-2">Últimas ventas</h6>
                <div style="max-height:260px;overflow-y:auto">
                    <table style="width:100%;border-collapse:collapse;font-size:12px">
                        <thead><tr style="background:#f8fafc">
                            <th style="padding:6px 8px">Doc.</th>
                            <th style="padding:6px 8px">Tipo</th>
                            <th style="padding:6px 8px">Cliente</th>
                            <th style="padding:6px 8px;text-align:right">Total $</th>
                        </tr></thead>
                        <tbody>
                        <?php foreach ($ventasVendedor as $vv): ?>
                        <tr style="border-bottom:1px solid var(--border)">
                            <td style="padding:5px 8px"><a href="ver_venta.php?id=<?= $vv['id']??'' ?>" class="font-mono" style="color:var(--primary);font-size:11px"><?= clean($vv['numero']) ?></a></td>
                            <td style="padding:5px 8px"><?= badgeTipo($vv['tipo_cliente']) ?></td>
                            <td style="padding:5px 8px"><?= clean(mb_strimwidth($vv['cliente_nombre']??'',0,18,'...')) ?></td>
                            <td style="padding:5px 8px;text-align:right;font-weight:700;color:var(--success)"><?= formatUSD($vv['total_usd']) ?></td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if (empty($ventasVendedor)): ?>
                        <tr><td colspan="4" style="padding:16px;text-align:center;color:var(--text-muted)">Sin ventas registradas</td></tr>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <div class="card-footer">
                <a href="documentos.php?vendedor=<?= $verVendedorId ?>" class="btn btn-primary btn-sm">
                    <i class="fa-solid fa-file-invoice"></i> Ver todos los documentos
                </a>
                <a href="reportes.php?tipo=ventas_vendedor&vendedor=<?= $verVendedorId ?>" class="btn btn-light btn-sm">
                    <i class="fa-solid fa-chart-bar"></i> Reporte completo
                </a>
            </div>
        </div>
    </div>
    <?php endif; ?>
</div>

<!-- MODAL: Crear/Editar Vendedor -->
<div class="modal-overlay" id="modalVendedor">
    <div class="modal-box" style="max-width:520px">
        <div class="modal-header">
            <h4><?= $editVendedor ? 'Editar Vendedor' : 'Nuevo Vendedor' ?></h4>
            <button class="btn-close" onclick="closeModal('modalVendedor')"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <form method="POST">
            <div class="modal-body">
                <input type="hidden" name="<?= CSRF_TOKEN_NAME ?>" value="<?= getCsrfToken() ?>">
                <input type="hidden" name="action" value="<?= $editVendedor ? 'editar' : 'crear' ?>">
                <?php if ($editVendedor): ?><input type="hidden" name="id" value="<?= $editVendedor['id'] ?>"><?php endif; ?>

                <div class="form-group">
                    <label class="form-label">Nombre completo *</label>
                    <input type="text" name="nombre" class="form-control"
                        value="<?= clean($editVendedor['nombre']??'') ?>" required maxlength="150">
                </div>
                <div class="row">
                    <div class="col-6">
                        <div class="form-group">
                            <label class="form-label">Cédula</label>
                            <input type="text" name="cedula" class="form-control"
                                value="<?= clean($editVendedor['cedula']??'') ?>" placeholder="V-12345678">
                        </div>
                    </div>
                    <div class="col-6">
                        <div class="form-group">
                            <label class="form-label">Teléfono</label>
                            <input type="text" name="telefono" class="form-control"
                                value="<?= clean($editVendedor['telefono']??'') ?>">
                        </div>
                    </div>
                </div>
                <div class="row">
                    <div class="col-6">
                        <div class="form-group">
                            <label class="form-label">Zona asignada</label>
                            <select name="zona_id" class="form-select">
                                <option value="">— Sin zona —</option>
                                <?php foreach ($zonas as $z): ?>
                                <option value="<?= $z['id'] ?>" <?= ($editVendedor['zona_id']??0)==$z['id']?'selected':'' ?>><?= clean($z['nombre']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="col-6">
                        <div class="form-group">
                            <label class="form-label">Comisión %</label>
                            <input type="number" name="comision_pct" class="form-control"
                                value="<?= $editVendedor['comision_pct']??0 ?>" min="0" max="100" step="0.01">
                        </div>
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label">Tipo de cliente preferido</label>
                    <div class="d-flex gap-3">
                        <?php foreach (['mayorista'=>'Mayorista','detalista'=>'Detalista','ambos'=>'Ambos'] as $val=>$lbl): ?>
                        <label style="display:flex;align-items:center;gap:6px;cursor:pointer;padding:8px 14px;border:2px solid var(--border);border-radius:7px;flex:1;justify-content:center;font-size:13px;font-weight:600">
                            <input type="radio" name="tipo_preferencia" value="<?= $val ?>"
                                <?= ($editVendedor['tipo_preferencia']??'ambos')===$val?'checked':'' ?>>
                            <?= $lbl ?>
                        </label>
                        <?php endforeach; ?>
                    </div>
                    <small class="text-muted" style="display:block;margin-top:6px">Define el tipo de cliente con el que trabaja principalmente este vendedor. Aparece en los reportes y documentos.</small>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" onclick="closeModal('modalVendedor')" class="btn btn-light">Cancelar</button>
                <button type="submit" class="btn btn-primary">
                    <i class="fa-solid fa-floppy-disk"></i> Guardar Vendedor
                </button>
            </div>
        </form>
    </div>
</div>

<?php
$extraScript = "
<script>
\$('#tablaVendedores').DataTable({ language:dtLang, pageLength:20, order:[[0,'asc']], columnDefs:[{orderable:false,targets:5},{targets:'_all',defaultContent:''}] });
" . ($editVendedor ? "openModal('modalVendedor');" : "") . "
</script>";
include 'includes/footer.php';
?>
