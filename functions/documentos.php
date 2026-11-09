<?php
require_once 'config/config.php';
require_once 'functions/db.php';
require_once 'functions/helpers.php';
require_once 'functions/auth.php';

requireLogin();
requirePermission('ventas','ver');
$pageTitle = 'Documentos';

// ── CONVERTIR DOCUMENTO ────────────────────────────────────
if ($_SERVER['REQUEST_METHOD']==='POST' && !empty($_POST['convertir'])) {
    $token = $_POST[CSRF_TOKEN_NAME] ?? '';
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $token)) {
        $_SESSION['flash'] = ['type'=>'error','msg'=>'Token inválido. Recarga la página e intenta de nuevo.'];
        header('Location: documentos.php'); exit;
    }
    $vid       = cleanInt($_POST['venta_id'] ?? 0);
    $nuevoTipo = clean($_POST['nuevo_tipo'] ?? '');
    $result    = convertirDocumento($vid, $nuevoTipo, $_SESSION['user_id']);
    $_SESSION['flash'] = ['type' => $result['ok'] ? 'success' : 'error', 'msg' => $result['msg']];
    header('Location: documentos.php'); exit;
}

// ── ANULAR ─────────────────────────────────────────────────
if (isset($_GET['anular']) && hasPermission('ventas','anular')) {
    $token = $_GET[CSRF_TOKEN_NAME] ?? '';
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $token)) {
        $_SESSION['flash'] = ['type'=>'error','msg'=>'Token inválido. Recarga la página e intenta de nuevo.'];
        header('Location: documentos.php'); exit;
    }
    $vid = cleanInt($_GET['anular']);
    $v   = dbQueryOne("SELECT * FROM ventas WHERE id=?", [$vid]);
    if ($v && $v['estado'] !== 'anulado') {
        // Solo restaurar stock si era una factura (venta)
        if ($v['tipo_doc'] === 'venta') {
            $dets = dbQuery("SELECT * FROM venta_detalles WHERE venta_id=?", [$vid]);
            foreach ($dets as $d) {
                if ($d['producto_id']) actualizarStock($d['producto_id'], $d['cantidad'], 'sumar');
            }
        }
        dbExecute("UPDATE ventas SET estado='anulado' WHERE id=?", [$vid]);
        actualizarSaldoCliente($v['cliente_id']);
        auditLog('UPDATE','ventas',$vid,"Documento anulado: {$v['numero']}",$v,['estado'=>'anulado']);
        $_SESSION['flash'] = ['type'=>'success','msg'=>"Documento {$v['numero']} anulado."];
    }
    header('Location: documentos.php'); exit;
}

// ── FILTROS ────────────────────────────────────────────────
$filtroTipoDoc  = clean($_GET['tipo_doc']  ?? '');
$filtroEstado   = clean($_GET['estado']    ?? '');
$filtroTipoCli  = clean($_GET['tipo_cli']  ?? '');
$filtroVendedor = cleanInt($_GET['vendedor'] ?? 0);
$filtroFechaIni = clean($_GET['fecha_ini']  ?? date('Y-m-01'));
$filtroFechaFin = clean($_GET['fecha_fin']  ?? date('Y-m-d'));
$busqueda       = clean($_GET['q']          ?? '');
$esVendedor     = ($_SESSION['rol_id'] ?? 0) == 3;

$sql = "SELECT v.id, v.numero, v.tipo_doc, v.fecha, v.tipo_cliente, v.tipo_pago,
               v.estado, v.total_usd, v.monto_pagado_usd,
               (v.total_usd - v.monto_pagado_usd) saldo_usd,
               c.nombre cliente_nombre, c.rif cliente_rif,
               vd.nombre vendedor_nombre
        FROM ventas v
        LEFT JOIN clientes c   ON v.cliente_id  = c.id
        LEFT JOIN vendedores vd ON v.vendedor_id = vd.id
        WHERE v.fecha BETWEEN ? AND ?";
$params = [$filtroFechaIni, $filtroFechaFin];

if ($filtroTipoDoc)  { $sql .= " AND v.tipo_doc=?";      $params[] = $filtroTipoDoc; }
if ($filtroEstado)   { $sql .= " AND v.estado=?";         $params[] = $filtroEstado; }
if ($filtroTipoCli)  { $sql .= " AND v.tipo_cliente=?";   $params[] = $filtroTipoCli; }
if ($filtroVendedor) { $sql .= " AND v.vendedor_id=?";    $params[] = $filtroVendedor; }
if ($busqueda) {
    $sql .= " AND (v.numero LIKE ? OR c.nombre LIKE ?)";
    $params[] = "%$busqueda%"; $params[] = "%$busqueda%";
}
if ($esVendedor && isset($_SESSION['vendedor_id'])) {
    $sql .= " AND v.vendedor_id=?"; $params[] = $_SESSION['vendedor_id'];
}
$sql .= " ORDER BY v.fecha DESC, v.id DESC";

$ventas     = dbQuery($sql, $params);
$vendedores = dbQuery("SELECT id,nombre FROM vendedores WHERE activo=1 ORDER BY nombre");

// Totales por tipo
$tot = ['cotizacion'=>['c'=>0,'t'=>0],'despacho'=>['c'=>0,'t'=>0],'venta'=>['c'=>0,'t'=>0]];
foreach ($ventas as $v) {
    if ($v['estado']==='anulado') continue;
    $t = $v['tipo_doc'];
    if (isset($tot[$t])) { $tot[$t]['c']++; $tot[$t]['t'] += $v['total_usd']; }
}
$pendUSD = array_sum(array_map(
    fn($v) => in_array($v['estado'],['pendiente','parcial']) ? $v['saldo_usd'] : 0,
    $ventas
));

include 'includes/header.php';
?>

<!-- STATS -->
<div class="row mb-4">
<?php
$cards = [
    'cotizacion'=>['Cotizaciones','fa-file-pen',''],
    'despacho'  =>['Despachos',  'fa-truck',   'blue'],
    'venta'     =>['Facturas',   'fa-file-invoice-dollar','green'],
];
foreach ($cards as $tipo=>[$label,$icon,$cls]):
?>
<div class="col-3">
    <div class="stat-card <?= $cls ?>">
        <div class="stat-icon"><i class="fa-solid <?= $icon ?>"></i></div>
        <div>
            <div class="stat-value"><?= $tot[$tipo]['c'] ?></div>
            <div class="stat-label"><?= $label ?></div>
            <small class="text-muted"><?= formatUSD($tot[$tipo]['t']) ?></small>
        </div>
    </div>
</div>
<?php endforeach; ?>
<div class="col-3">
    <div class="stat-card orange">
        <div class="stat-icon"><i class="fa-solid fa-hourglass-half"></i></div>
        <div>
            <div class="stat-value"><?= formatUSD($pendUSD) ?></div>
            <div class="stat-label">Por Cobrar</div>
        </div>
    </div>
</div>
</div>

<!-- FILTROS -->
<div class="card mb-4">
<div class="card-body" style="padding:12px 16px">
<form method="GET" class="d-flex gap-2 align-center" style="flex-wrap:wrap">
    <input type="text" name="q" class="form-control" placeholder="Buscar N° o cliente..."
           value="<?= $busqueda ?>" style="max-width:200px">
    <input type="date" name="fecha_ini" class="form-control" value="<?= $filtroFechaIni ?>" style="max-width:150px">
    <span class="text-muted" style="align-self:center">a</span>
    <input type="date" name="fecha_fin" class="form-control" value="<?= $filtroFechaFin ?>" style="max-width:150px">
    <select name="tipo_doc" class="form-select" style="max-width:150px">
        <option value="">Todos los tipos</option>
        <option value="cotizacion" <?= $filtroTipoDoc==='cotizacion'?'selected':'' ?>>Cotizaciones</option>
        <option value="despacho"   <?= $filtroTipoDoc==='despacho'  ?'selected':'' ?>>Despachos</option>
        <option value="venta"      <?= $filtroTipoDoc==='venta'     ?'selected':'' ?>>Facturas</option>
    </select>
    <select name="estado" class="form-select" style="max-width:130px">
        <option value="">Todos</option>
        <?php foreach (['pendiente','parcial','pagado','anulado'] as $e): ?>
        <option value="<?= $e ?>" <?= $filtroEstado===$e?'selected':'' ?>><?= ucfirst($e) ?></option>
        <?php endforeach; ?>
    </select>
    <select name="tipo_cli" class="form-select" style="max-width:130px">
        <option value="">Ambos</option>
        <option value="mayorista" <?= $filtroTipoCli==='mayorista'?'selected':'' ?>>Mayorista</option>
        <option value="detalista" <?= $filtroTipoCli==='detalista'?'selected':'' ?>>Detalista</option>
    </select>
    <?php if (!$esVendedor): ?>
    <select name="vendedor" class="form-select" style="max-width:150px">
        <option value="">Todos vendedores</option>
        <?php foreach ($vendedores as $vd): ?>
        <option value="<?= $vd['id'] ?>" <?= $filtroVendedor==$vd['id']?'selected':'' ?>><?= clean($vd['nombre']) ?></option>
        <?php endforeach; ?>
    </select>
    <?php endif; ?>
    <button type="submit" class="btn btn-primary">
        <i class="fa-solid fa-filter"></i> Filtrar
    </button>
    <a href="documentos.php" class="btn btn-light"><i class="fa-solid fa-xmark"></i></a>
</form>
</div>
</div>

<!-- LEYENDA DEL FLUJO + TABS -->
<div style="background:#f8fafc;border:1px solid var(--border);border-radius:8px;padding:10px 16px;margin-bottom:12px;font-size:12px;display:flex;align-items:center;gap:16px;flex-wrap:wrap">
    <strong style="color:var(--text-muted)">Flujo de documentos:</strong>
    <span><span class="badge badge-secondary">Cotización</span> <i class="fa-solid fa-arrow-right" style="color:var(--text-muted);font-size:10px;margin:0 4px"></i> Sin stock · Editable</span>
    <i class="fa-solid fa-arrow-right" style="color:var(--text-muted)"></i>
    <span><span class="badge badge-info">Despacho</span> <i class="fa-solid fa-arrow-right" style="color:var(--text-muted);font-size:10px;margin:0 4px"></i> Sin stock · Editable · Para carga del camión</span>
    <i class="fa-solid fa-arrow-right" style="color:var(--text-muted)"></i>
    <span><span class="badge badge-success">Factura</span> <i class="fa-solid fa-check" style="color:var(--success);font-size:10px;margin:0 4px"></i> Descuenta inventario · Final</span>
</div>

<!-- TABS POR TIPO DE DOCUMENTO -->
<div style="display:flex;gap:0;margin-bottom:0;border-bottom:2px solid var(--border)">
    <button onclick="filtrarTab('')" id="tab-all" class="doc-tab doc-tab-active"
        style="padding:10px 22px;border:none;background:none;cursor:pointer;font-weight:700;font-size:13px;border-bottom:3px solid var(--primary);color:var(--primary)">
        <i class="fa-solid fa-list"></i> Todos <span id="cnt-all" class="badge badge-secondary" style="margin-left:4px"><?= count($ventas) ?></span>
    </button>
    <button onclick="filtrarTab('cotizacion')" id="tab-cotizacion" class="doc-tab"
        style="padding:10px 22px;border:none;background:none;cursor:pointer;font-weight:600;font-size:13px;color:var(--text-muted)">
        <i class="fa-solid fa-file-pen"></i> Cotizaciones <span id="cnt-cotizacion" class="badge badge-secondary" style="margin-left:4px"><?= $tot['cotizacion']['c'] ?></span>
    </button>
    <button onclick="filtrarTab('despacho')" id="tab-despacho" class="doc-tab"
        style="padding:10px 22px;border:none;background:none;cursor:pointer;font-weight:600;font-size:13px;color:var(--text-muted)">
        <i class="fa-solid fa-truck"></i> Despachos <span id="cnt-despacho" class="badge badge-info" style="margin-left:4px"><?= $tot['despacho']['c'] ?></span>
    </button>
    <button onclick="filtrarTab('venta')" id="tab-venta" class="doc-tab"
        style="padding:10px 22px;border:none;background:none;cursor:pointer;font-weight:600;font-size:13px;color:var(--text-muted)">
        <i class="fa-solid fa-file-invoice-dollar"></i> Facturas <span id="cnt-venta" class="badge badge-success" style="margin-left:4px"><?= $tot['venta']['c'] ?></span>
    </button>
</div>

<!-- TABLA -->
<div class="card">
<div class="card-header">
    <i class="fa-solid fa-file-invoice text-primary"></i>
    <h5>Documentos — <?= count($ventas) ?> registros</h5>
    <div class="d-flex gap-2" style="margin-left:auto">
        <button onclick="exportExcel('tablaDocumentos','Documentos_Tauro')" class="btn btn-sm btn-light">
            <i class="fa-solid fa-table-cells"></i> Excel
        </button>
        <?php if (hasPermission('ventas','crear')): ?>
        <a href="nueva_venta.php?tipo=cotizacion" class="btn btn-sm btn-secondary">
            <i class="fa-solid fa-file-pen"></i> Cotización
        </a>
        <a href="nueva_venta.php?tipo=despacho" class="btn btn-sm btn-info">
            <i class="fa-solid fa-truck"></i> Despacho
        </a>
        <a href="nueva_venta.php?tipo=venta" class="btn btn-sm btn-success">
            <i class="fa-solid fa-file-invoice-dollar"></i> Factura
        </a>
        <?php endif; ?>
    </div>
</div>
<div class="card-body p-0">
<div class="table-wrapper">
<!-- IMPORTANTE: todas las filas tienen EXACTAMENTE 12 <td> siempre -->
<table id="tablaDocumentos" style="width:100%;border-collapse:collapse;font-size:13px">
    <thead>
        <tr style="background:#f8fafc">
            <th style="padding:10px 12px;font-size:11px;font-weight:700;color:var(--text-muted);text-transform:uppercase;border-bottom:2px solid var(--border)">N° Doc.</th>
            <th style="padding:10px 12px;font-size:11px;font-weight:700;color:var(--text-muted);text-transform:uppercase;border-bottom:2px solid var(--border)">Tipo</th>
            <th style="padding:10px 12px;font-size:11px;font-weight:700;color:var(--text-muted);text-transform:uppercase;border-bottom:2px solid var(--border)">Fecha</th>
            <th style="padding:10px 12px;font-size:11px;font-weight:700;color:var(--text-muted);text-transform:uppercase;border-bottom:2px solid var(--border)">Cliente</th>
            <th style="padding:10px 12px;font-size:11px;font-weight:700;color:var(--text-muted);text-transform:uppercase;border-bottom:2px solid var(--border)">Vendedor</th>
            <th style="padding:10px 12px;font-size:11px;font-weight:700;color:var(--text-muted);text-transform:uppercase;border-bottom:2px solid var(--border)">Cli. Tipo</th>
            <th style="padding:10px 12px;font-size:11px;font-weight:700;color:var(--text-muted);text-transform:uppercase;border-bottom:2px solid var(--border)">Pago</th>
            <th style="padding:10px 12px;font-size:11px;font-weight:700;color:var(--text-muted);text-transform:uppercase;border-bottom:2px solid var(--border);text-align:right">Total $</th>
            <th style="padding:10px 12px;font-size:11px;font-weight:700;color:var(--text-muted);text-transform:uppercase;border-bottom:2px solid var(--border);text-align:right">Pagado $</th>
            <th style="padding:10px 12px;font-size:11px;font-weight:700;color:var(--text-muted);text-transform:uppercase;border-bottom:2px solid var(--border);text-align:right">Saldo $</th>
            <th style="padding:10px 12px;font-size:11px;font-weight:700;color:var(--text-muted);text-transform:uppercase;border-bottom:2px solid var(--border)">Estado</th>
            <th style="padding:10px 12px;font-size:11px;font-weight:700;color:var(--text-muted);text-transform:uppercase;border-bottom:2px solid var(--border)">Acciones</th>
        </tr>
    </thead>
    <tbody>
    <?php if (empty($ventas)): ?>
    <tr>
        <td colspan="12" style="text-align:center;padding:36px;color:var(--text-muted)">
            Sin documentos en el período seleccionado
        </td>
    </tr>
    <?php else: foreach ($ventas as $v): ?>
    <tr style="border-bottom:1px solid var(--border);<?= $v['estado']==='anulado'?'opacity:.5':''; ?>">
        <td style="padding:10px 12px"><strong class="font-mono fs-12"><?= clean($v['numero']) ?></strong></td>
        <td style="padding:10px 12px"><?= badgeDoc($v['tipo_doc']) ?></td>
        <td style="padding:10px 12px;font-size:12px"><?= date('d/m/Y', strtotime($v['fecha'])) ?></td>
        <td style="padding:10px 12px">
            <strong class="fs-13"><?= clean($v['cliente_nombre']) ?></strong>
            <?php if ($v['cliente_rif']): ?><br><small class="text-muted"><?= clean($v['cliente_rif']) ?></small><?php endif; ?>
        </td>
        <td style="padding:10px 12px;font-size:12px"><?= clean($v['vendedor_nombre'] ?? '—') ?></td>
        <td style="padding:10px 12px"><?= badgeTipo($v['tipo_cliente']) ?></td>
        <td style="padding:10px 12px">
            <span class="badge <?= $v['tipo_pago']==='credito'?'badge-info':'badge-secondary' ?>">
                <?= ucfirst($v['tipo_pago']) ?>
            </span>
        </td>
        <td style="padding:10px 12px;text-align:right;font-weight:700"><?= formatUSD($v['total_usd']) ?></td>
        <td style="padding:10px 12px;text-align:right;color:var(--success)"><?= formatUSD($v['monto_pagado_usd']) ?></td>
        <td style="padding:10px 12px;text-align:right;font-weight:700;<?= $v['saldo_usd']>0.01?'color:var(--danger)':'color:var(--success)' ?>">
            <?= formatUSD($v['saldo_usd']) ?>
        </td>
        <td style="padding:10px 12px"><?= badgeEstado($v['estado']) ?></td>
        <td style="padding:10px 12px">
            <!-- Siempre 1 <td> con todos los botones dentro — esto garantiza el column count -->
            <div class="d-flex gap-1">
                <a href="ver_venta.php?id=<?= $v['id'] ?>" class="btn btn-sm btn-light btn-icon" title="Ver">
                    <i class="fa-solid fa-eye"></i>
                </a>
                <?php if (in_array($v['tipo_doc'],['cotizacion','despacho']) && $v['estado']!=='anulado' && hasPermission('ventas','crear')): ?>
                <button onclick="abrirConvertir(<?= $v['id'] ?>,'<?= clean($v['numero']) ?>','<?= $v['tipo_doc'] ?>')"
                    class="btn btn-sm btn-warning btn-icon" title="Convertir">
                    <i class="fa-solid fa-arrow-right-arrow-left"></i>
                </button>
                <?php endif; ?>
                <?php if (in_array($v['estado'],['pendiente','parcial']) && $v['tipo_doc']==='venta' && hasPermission('pagos','crear')): ?>
                <a href="pagos.php?venta_id=<?= $v['id'] ?>" class="btn btn-sm btn-success btn-icon" title="Cobrar">
                    <i class="fa-solid fa-money-bill-wave"></i>
                </a>
                <?php endif; ?>
                <?php if ($v['estado']!=='anulado' && hasPermission('ventas','anular')): ?>
                <a href="documentos.php?anular=<?= $v['id'] ?>&<?= CSRF_TOKEN_NAME ?>=<?= getCsrfToken() ?>"
                   class="btn btn-sm btn-danger btn-icon btn-delete" data-name="<?= clean($v['numero']) ?>" title="Anular">
                    <i class="fa-solid fa-ban"></i>
                </a>
                <?php endif; ?>
            </div>
        </td>
    </tr>
    <?php endforeach; endif; ?>
    </tbody>
</table>
</div>
</div>
</div>

<!-- MODAL: Convertir -->
<div class="modal-overlay" id="modalConvertir">
<div class="modal-box" style="max-width:450px">
    <div class="modal-header">
        <h4><i class="fa-solid fa-arrow-right-arrow-left"></i> Convertir Documento</h4>
        <button class="btn-close" onclick="closeModal('modalConvertir')"><i class="fa-solid fa-xmark"></i></button>
    </div>
    <form method="POST">
    <div class="modal-body">
        <input type="hidden" name="<?= CSRF_TOKEN_NAME ?>" value="<?= getCsrfToken() ?>">
        <input type="hidden" name="convertir" value="1">
        <input type="hidden" name="venta_id" id="convId">

        <div style="margin-bottom:16px">
            Documento: <strong id="convNumero"></strong>
            <span id="convBadgeActual" style="margin-left:6px"></span>
        </div>

        <div class="alert alert-warning">
            <i class="fa-solid fa-triangle-exclamation"></i>
            <span id="convMensaje"></span>
        </div>

        <div class="form-group">
            <label class="form-label">Convertir a:</label>
            <div class="d-flex gap-2 mt-2" id="convOpciones">
                <!-- Se llena con JS según el tipo actual -->
            </div>
        </div>
    </div>
    <div class="modal-footer">
        <button type="button" onclick="closeModal('modalConvertir')" class="btn btn-light">Cancelar</button>
        <button type="submit" class="btn btn-primary">
            <i class="fa-solid fa-check"></i> Confirmar
        </button>
    </div>
    </form>
</div>
</div>

<?php
$extraScript = "
<script>
// ── Tabs por tipo de documento ───────────────────────────
var tabActual = '';
function filtrarTab(tipo) {
    tabActual = tipo;
    // Update tab styles
    document.querySelectorAll('.doc-tab').forEach(function(t) {
        t.style.color = 'var(--text-muted)';
        t.style.borderBottom = 'none';
    });
    var activeTab = document.getElementById('tab-' + (tipo || 'all'));
    if (activeTab) {
        activeTab.style.color = tipo === 'venta' ? 'var(--success)' : tipo === 'despacho' ? 'var(--info)' : tipo === 'cotizacion' ? 'var(--secondary)' : 'var(--primary)';
        activeTab.style.borderBottom = '3px solid ' + (tipo === 'venta' ? 'var(--success)' : tipo === 'despacho' ? 'var(--info)' : tipo === 'cotizacion' ? '#7f8c8d' : 'var(--primary)');
    }
    // Filter table rows
    var dt = \$('#tablaDocumentos').DataTable();
    if (tipo) {
        dt.column(1).search(tipo === 'venta' ? 'Factura' : tipo === 'despacho' ? 'Despacho' : 'Cotización').draw();
    } else {
        dt.column(1).search('').draw();
    }
}

// ── Inicialización DataTables MANUAL y SEGURA ─────────────
// NO usamos class='datatable' para evitar doble init
\$(document).ready(function() {
    if (\$.fn.DataTable.isDataTable('#tablaDocumentos')) {
        \$('#tablaDocumentos').DataTable().destroy();
    }
    \$('#tablaDocumentos').DataTable({
        language: dtLang,
        pageLength: 25,
        order: [],
        // Definir TODAS las columnas explícitamente
        columnDefs: [
            { targets: 0,  type: 'string'  },
            { targets: 1,  orderable: false },
            { targets: 2,  type: 'date'    },
            { targets: 7,  type: 'num'     },
            { targets: 8,  type: 'num'     },
            { targets: 9,  type: 'num'     },
            { targets: 10, orderable: false },
            { targets: 11, orderable: false }
        ]
    });
});

// ── Modal convertir ───────────────────────────────────────
function abrirConvertir(id, numero, tipoActual) {
    document.getElementById('convId').value = id;
    document.getElementById('convNumero').textContent = numero;

    const badges = {
        cotizacion: '<span class=\"badge badge-secondary\">Cotización</span>',
        despacho:   '<span class=\"badge badge-info\">Despacho</span>',
        venta:      '<span class=\"badge badge-success\">Factura</span>',
    };
    document.getElementById('convBadgeActual').innerHTML = badges[tipoActual] || '';

    const mensajes = {
        cotizacion: 'Puedes convertir a Despacho (sin afectar stock) o directamente a Factura (descuenta inventario).',
        despacho:   'Al convertir a Factura se descontará el inventario. Esta acción no se puede revertir.',
    };
    document.getElementById('convMensaje').textContent = mensajes[tipoActual] || '';

    // Opciones según tipo actual
    const opcionesCot = \`
        <label style=\"cursor:pointer;padding:12px 16px;border:2px solid var(--border);border-radius:8px;flex:1;text-align:center\">
            <input type=\"radio\" name=\"nuevo_tipo\" value=\"despacho\" required>
            <div style=\"margin-top:6px\"><i class=\"fa-solid fa-truck\" style=\"font-size:22px;color:var(--info)\"></i></div>
            <div class=\"fw-bold\" style=\"margin-top:4px;font-size:13px\">Despacho</div>
            <div style=\"font-size:11px;color:var(--text-muted)\">Sin afectar stock</div>
        </label>
        <label style=\"cursor:pointer;padding:12px 16px;border:2px solid var(--border);border-radius:8px;flex:1;text-align:center\">
            <input type=\"radio\" name=\"nuevo_tipo\" value=\"venta\">
            <div style=\"margin-top:6px\"><i class=\"fa-solid fa-file-invoice-dollar\" style=\"font-size:22px;color:var(--success)\"></i></div>
            <div class=\"fw-bold\" style=\"margin-top:4px;font-size:13px\">Factura</div>
            <div style=\"font-size:11px;color:var(--text-muted)\">Descuenta inventario</div>
        </label>
    \`;
    const opcionesDes = \`
        <label style=\"cursor:pointer;padding:12px 16px;border:2px solid var(--border);border-radius:8px;flex:1;text-align:center\">
            <input type=\"radio\" name=\"nuevo_tipo\" value=\"venta\" required>
            <div style=\"margin-top:6px\"><i class=\"fa-solid fa-file-invoice-dollar\" style=\"font-size:22px;color:var(--success)\"></i></div>
            <div class=\"fw-bold\" style=\"margin-top:4px;font-size:13px\">Factura / Venta</div>
            <div style=\"font-size:11px;color:var(--text-muted)\">Descuenta inventario definitivamente</div>
        </label>
    \`;
    document.getElementById('convOpciones').innerHTML =
        tipoActual === 'cotizacion' ? opcionesCot : opcionesDes;

    openModal('modalConvertir');
}
</script>";

include 'includes/footer.php';
?>
