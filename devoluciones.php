<?php
require_once 'config/config.php';
require_once 'functions/db.php';
require_once 'functions/helpers.php';
require_once 'functions/auth.php';

requireLogin();
requirePermission('devoluciones','ver');
$pageTitle = 'Devoluciones';

// ── CREAR ───────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action']??'')==='crear') {
    verifyCsrf();
    requirePermission('devoluciones','crear');
    $ventaId   = cleanInt($_POST['venta_id']   ?? 0) ?: null;
    $clienteId = cleanInt($_POST['cliente_id'] ?? 0);
    $fecha     = $_POST['fecha']  ?? date('Y-m-d');
    $motivo    = clean($_POST['motivo'] ?? '');
    $prods     = $_POST['producto_id'] ?? [];
    $descs     = $_POST['descripcion'] ?? [];
    $cants     = $_POST['cantidad']    ?? [];
    $precs     = $_POST['precio']      ?? [];

    if (!$clienteId) { $_SESSION['flash']=['type'=>'error','msg'=>'Selecciona un cliente.']; header('Location: devoluciones.php'); exit; }

    $lineas = array_filter(array_keys($prods), fn($i) => cleanFloat($cants[$i]??0) > 0 && !empty(trim($descs[$i]??'')));
    if (empty($lineas)) { $_SESSION['flash']=['type'=>'error','msg'=>'Agrega al menos un producto con cantidad.']; header('Location: devoluciones.php'); exit; }

    try {
        $pdo = getDB(); $pdo->beginTransaction();
        $numero   = generarNumeroDevolucion();
        $totalUSD = 0;
        foreach ($lineas as $i) $totalUSD += cleanFloat($cants[$i]) * cleanFloat($precs[$i]??0);
        dbExecute("INSERT INTO devoluciones (numero,venta_id,cliente_id,fecha,motivo,total_usd,estado,usuario_id) VALUES (?,?,?,?,?,?,'pendiente',?)",
            [$numero,$ventaId,$clienteId,$fecha,$motivo,$totalUSD,$_SESSION['user_id']]);
        $devId = (int)dbLastId();
        foreach ($lineas as $i) {
            $cant = cleanFloat($cants[$i]); $prec = cleanFloat($precs[$i]??0);
            dbExecute("INSERT INTO devolucion_detalles (devolucion_id,producto_id,descripcion,cantidad,precio_usd,monto_usd) VALUES (?,?,?,?,?,?)",
                [$devId, cleanInt($prods[$i])?:null, clean($descs[$i]), $cant, $prec, round($cant*$prec,4)]);
        }
        $pdo->commit();
        auditLog('INSERT','devoluciones',$devId,"Devolución creada: $numero — \${$totalUSD}");
        $_SESSION['flash']=['type'=>'success','msg'=>"Devolución <strong>$numero</strong> registrada. Pendiente de aprobación."];
    } catch (Exception $e) {
        if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
        $_SESSION['flash']=['type'=>'error','msg'=>'Error: '.$e->getMessage()];
    }
    header('Location: devoluciones.php'); exit;
}

// ── APROBAR / RECHAZAR ──────────────────────────────────────
if ($_SERVER['REQUEST_METHOD']==='POST' && in_array($_POST['action']??'',['aprobar','rechazar']) && hasPermission('devoluciones','aprobar')) {
    verifyCsrf();
    $devId  = cleanInt($_POST['id'] ?? 0);
    $estado = $_POST['action']==='aprobar' ? 'aprobada' : 'rechazada';
    $dev    = dbQueryOne("SELECT * FROM devoluciones WHERE id=?",[$devId]);

    if ($dev && $dev['estado']==='pendiente') {
        dbExecute("UPDATE devoluciones SET estado=?, aprobado_por=?, fecha_aprobacion=NOW() WHERE id=?",[$estado,$_SESSION['user_id'],$devId]);

        if ($estado==='aprobada') {
            $dets = dbQuery("SELECT * FROM devolucion_detalles WHERE devolucion_id=?",[$devId]);
            foreach ($dets as $dd) if ($dd['producto_id']) actualizarStock($dd['producto_id'],$dd['cantidad'],'sumar');

            if ($dev['venta_id']) {
                $venta = dbQueryOne("SELECT * FROM ventas WHERE id=?",[$dev['venta_id']]);
                if ($venta) {
                    $nuevoTotal = max(0, $venta['total_usd'] - $dev['total_usd']);
                    $saldo      = $nuevoTotal - $venta['monto_pagado_usd'];
                    $nuevoEst   = $saldo <= 0.01 ? 'pagado' : ($venta['monto_pagado_usd'] > 0 ? 'parcial' : 'pendiente');
                    dbExecute("UPDATE ventas SET total_usd=?, estado=?, notas=CONCAT(COALESCE(notas,''),' | DEV-$nuevoTotal$: devolución {$dev['numero']} aplicada') WHERE id=?",
                        [$nuevoTotal,$nuevoEst,$dev['venta_id']]);
                    actualizarSaldoCliente($venta['cliente_id']);
                }
            }
            auditLog('UPDATE','devoluciones',$devId,"Aprobada: {$dev['numero']} — stock restaurado");
        } else {
            auditLog('UPDATE','devoluciones',$devId,"Rechazada: {$dev['numero']}");
        }
        $_SESSION['flash']=['type'=>'success','msg'=>"Devolución ".($estado==='aprobada'?'aprobada — stock restaurado':'rechazada')."."];
    }
    header('Location: devoluciones.php'); exit;
}

// ── DATOS ───────────────────────────────────────────────────
$devoluciones = dbQuery(
    "SELECT d.*, c.nombre c_nombre, v.numero v_numero, v.total_usd v_total,
            u.nombre u_nombre, ap.nombre aprobador_nombre
     FROM devoluciones d
     LEFT JOIN clientes c  ON d.cliente_id   = c.id
     LEFT JOIN ventas v    ON d.venta_id     = v.id
     LEFT JOIN usuarios u  ON d.usuario_id   = u.id
     LEFT JOIN usuarios ap ON d.aprobado_por = ap.id
     ORDER BY d.created_at DESC"
);
$clientes = dbQuery("SELECT id,nombre,rif FROM clientes WHERE activo=1 ORDER BY nombre");
$ventas   = dbQuery(
    "SELECT v.id, v.numero, v.total_usd, v.monto_pagado_usd, v.fecha, c.nombre cliente_nombre, c.id cliente_id
     FROM ventas v JOIN clientes c ON v.cliente_id=c.id
     WHERE v.tipo_doc='venta' AND v.estado NOT IN('anulado')
     ORDER BY v.fecha DESC LIMIT 300"
);
$productos = dbQuery(
    "SELECT p.id, p.codigo, p.descripcion, p.unidad, p.stock_actual,
            ROUND(COALESCE(pm.precio_usd,pd.precio_usd,0),2) precio_ref
     FROM productos p
     LEFT JOIN precios pm ON pm.producto_id=p.id AND pm.tipo='mayorista'
     LEFT JOIN precios pd ON pd.producto_id=p.id AND pd.tipo='detalista'
     WHERE p.activo=1 AND p.departamento_id=1 ORDER BY p.descripcion"
);

$pendientes  = count(array_filter($devoluciones, fn($d)=>$d['estado']==='pendiente'));
$totalDevUSD = array_sum(array_column(array_filter($devoluciones,fn($d)=>$d['estado']==='aprobada'),'total_usd'));

$prodsJS = json_encode(array_map(fn($p)=>[
    'id'=>(int)$p['id'],'cod'=>$p['codigo'],'desc'=>$p['descripcion'],
    'unid'=>$p['unidad'],'stock'=>(float)$p['stock_actual'],'prec'=>(float)$p['precio_ref'],
], $productos), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT);

include 'includes/header.php';
?>
<style>
.dev-pendiente { border-left:4px solid var(--warning)!important; }
.dev-aprobada  { border-left:4px solid var(--success)!important; }
.dev-rechazada { border-left:4px solid var(--danger)!important;opacity:.65; }
.prod-wrap { position:relative; }
.prod-input { width:100%;padding:5px 8px;border:1.5px solid var(--border);border-radius:5px;font-size:12px; }
.prod-input:focus { border-color:var(--primary);outline:none; }
.prod-dd { position:absolute;top:100%;left:0;right:0;background:white;border:1.5px solid var(--primary);
           border-top:none;border-radius:0 0 6px 6px;max-height:180px;overflow-y:auto;z-index:600;display:none;
           box-shadow:0 4px 12px rgba(0,0,0,.1);font-size:12px; }
.prod-dd .opt { padding:7px 10px;cursor:pointer;border-bottom:1px solid #f0f2f5;display:flex;justify-content:space-between; }
.prod-dd .opt:hover { background:#f0f7ff; }
.num-inp { width:100%;padding:5px 7px;border:1.5px solid var(--border);border-radius:5px;font-size:12px;text-align:right; }
.num-inp:focus { border-color:var(--primary);outline:none; }
#modalDev .select2-container { width:100% !important; }
#modalDev .select2-container--open { z-index:9999; }
</style>

<!-- STATS -->
<div class="row mb-4">
    <div class="col-3"><div class="stat-card orange">
        <div class="stat-icon"><i class="fa-solid fa-hourglass-half"></i></div>
        <div><div class="stat-value"><?= $pendientes ?></div><div class="stat-label">Pendientes aprobación</div></div>
    </div></div>
    <div class="col-3"><div class="stat-card green">
        <div class="stat-icon"><i class="fa-solid fa-check-double"></i></div>
        <div><div class="stat-value"><?= count(array_filter($devoluciones,fn($d)=>$d['estado']==='aprobada')) ?></div><div class="stat-label">Aprobadas</div></div>
    </div></div>
    <div class="col-3"><div class="stat-card">
        <div class="stat-icon"><i class="fa-solid fa-rotate-left"></i></div>
        <div><div class="stat-value"><?= formatUSD($totalDevUSD) ?></div><div class="stat-label">Total devuelto</div></div>
    </div></div>
    <div class="col-3"><div class="stat-card blue">
        <div class="stat-icon"><i class="fa-solid fa-list"></i></div>
        <div><div class="stat-value"><?= count($devoluciones) ?></div><div class="stat-label">Total registros</div></div>
    </div></div>
</div>

<div class="d-flex justify-between align-center mb-4">
    <h4 class="fw-bold" style="margin:0">Devoluciones</h4>
    <?php if (hasPermission('devoluciones','crear')): ?>
    <button type="button" onclick="abrirDevModal()" class="btn btn-primary" id="btnNuevaDev">
        <i class="fa-solid fa-rotate-left"></i> Nueva Devolución
    </button>
    <?php endif; ?>
</div>

<!-- TABLA -->
<div class="card">
<div class="card-header">
    <i class="fa-solid fa-rotate-left text-primary"></i>
    <h5>Historial — <?= count($devoluciones) ?></h5>
</div>
<div class="card-body p-0">
<div class="table-wrapper">
<table id="tablaDev" style="width:100%;border-collapse:collapse;font-size:13px">
    <thead><tr style="background:#f8fafc">
        <th style="padding:10px;font-size:10.5px;font-weight:700;text-transform:uppercase;color:var(--text-muted);border-bottom:2px solid var(--border)">N° Dev.</th>
        <th style="padding:10px;font-size:10.5px;font-weight:700;text-transform:uppercase;color:var(--text-muted);border-bottom:2px solid var(--border)">Fecha</th>
        <th style="padding:10px;font-size:10.5px;font-weight:700;text-transform:uppercase;color:var(--text-muted);border-bottom:2px solid var(--border)">Cliente</th>
        <th style="padding:10px;font-size:10.5px;font-weight:700;text-transform:uppercase;color:var(--text-muted);border-bottom:2px solid var(--border)">Factura orig.</th>
        <th style="padding:10px;font-size:10.5px;font-weight:700;text-transform:uppercase;color:var(--text-muted);border-bottom:2px solid var(--border)">Motivo</th>
        <th style="padding:10px;font-size:10.5px;font-weight:700;text-transform:uppercase;color:var(--text-muted);border-bottom:2px solid var(--border);text-align:right">Total $</th>
        <th style="padding:10px;font-size:10.5px;font-weight:700;text-transform:uppercase;color:var(--text-muted);border-bottom:2px solid var(--border)">Estado</th>
        <th style="padding:10px;font-size:10.5px;font-weight:700;text-transform:uppercase;color:var(--text-muted);border-bottom:2px solid var(--border)">Acciones</th>
    </tr></thead>
    <tbody>
    <?php foreach ($devoluciones as $dev): ?>
    <tr class="dev-<?= $dev['estado'] ?>" style="border-bottom:1px solid var(--border)">
        <td style="padding:10px"><strong class="font-mono fs-12"><?= clean($dev['numero']) ?></strong></td>
        <td style="padding:10px;font-size:12px"><?= date('d/m/Y',strtotime($dev['fecha'])) ?></td>
        <td style="padding:10px"><strong><?= clean($dev['c_nombre']) ?></strong></td>
        <td style="padding:10px">
            <?php if ($dev['v_numero']): ?>
            <a href="ver_venta.php?id=<?= $dev['venta_id'] ?>" class="font-mono fs-12" style="color:var(--primary)"><?= clean($dev['v_numero']) ?></a>
            <br><small class="text-muted"><?= formatUSD($dev['v_total']) ?></small>
            <?php else: ?>
            <span class="text-muted fs-12">Sin factura vinculada</span>
            <?php endif; ?>
        </td>
        <td style="padding:10px;font-size:12px;color:var(--text-muted)"><?= clean(mb_strimwidth($dev['motivo']??'',0,40,'...')) ?></td>
        <td style="padding:10px;text-align:right;font-weight:700;color:var(--danger)"><?= formatUSD($dev['total_usd']) ?></td>
        <td style="padding:10px"><?= badgeEstado($dev['estado']) ?></td>
        <td style="padding:10px">
            <div class="d-flex gap-1">
                <button type="button" onclick="verDevDetalle(<?= $dev['id'] ?>)" class="btn btn-sm btn-light btn-icon" title="Ver detalle">
                    <i class="fa-solid fa-eye"></i>
                </button>
                <?php if ($dev['estado']==='pendiente' && hasPermission('devoluciones','aprobar')): ?>
                <form method="POST" style="display:inline" onsubmit="return confirm('¿Aprobar esta devolución? Se restaurará el stock.')">
                    <input type="hidden" name="<?= CSRF_TOKEN_NAME ?>" value="<?= getCsrfToken() ?>">
                    <input type="hidden" name="action" value="aprobar">
                    <input type="hidden" name="id" value="<?= $dev['id'] ?>">
                    <button type="submit" class="btn btn-sm btn-success btn-icon" title="Aprobar">
                        <i class="fa-solid fa-check"></i>
                    </button>
                </form>
                <form method="POST" style="display:inline" onsubmit="return confirm('¿Rechazar esta devolución?')">
                    <input type="hidden" name="<?= CSRF_TOKEN_NAME ?>" value="<?= getCsrfToken() ?>">
                    <input type="hidden" name="action" value="rechazar">
                    <input type="hidden" name="id" value="<?= $dev['id'] ?>">
                    <button type="submit" class="btn btn-sm btn-danger btn-icon" title="Rechazar">
                        <i class="fa-solid fa-xmark"></i>
                    </button>
                </form>
                <?php elseif ($dev['estado']==='aprobada'): ?>
                <span class="text-muted fs-11"><i class="fa-solid fa-lock"></i> Aprobada por <?= clean($dev['aprobador_nombre']??'—') ?></span>
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

<!-- MODAL: Nueva Devolución -->
<div class="modal-overlay" id="modalDev">
<div class="modal-box modal-lg" style="max-width:860px">
    <div class="modal-header">
        <i class="fa-solid fa-rotate-left text-primary"></i>
        <h4>Nueva Devolución</h4>
        <button type="button" class="btn-close" onclick="closeModal('modalDev')"><i class="fa-solid fa-xmark"></i></button>
    </div>
    <form method="POST" id="formDev">
    <div class="modal-body">
        <input type="hidden" name="<?= CSRF_TOKEN_NAME ?>" value="<?= getCsrfToken() ?>">
        <input type="hidden" name="action" value="crear">

        <div class="row mb-3">
            <div class="col-5">
                <div class="form-group">
                    <label class="form-label">Factura original <small class="text-muted">(opcional)</small></label>
                    <select name="venta_id" id="selVenta" class="form-select" onchange="cargarDatosFactura()"
                            data-placeholder="— Buscar por N° de factura —" data-allow-clear="true">
                        <option value="">— Buscar por N° de factura —</option>
                        <?php foreach ($ventas as $v): ?>
                        <option value="<?= $v['id'] ?>"
                            data-cliente="<?= $v['cliente_id'] ?>"
                            data-nombre="<?= clean($v['cliente_nombre']) ?>"
                            data-total="<?= $v['total_usd'] ?>">
                            <?= clean($v['numero']) ?> — <?= clean($v['cliente_nombre']) ?> (<?= formatUSD($v['total_usd']) ?>)
                        </option>
                        <?php endforeach; ?>
                    </select>
                    <small class="text-muted">Si seleccionas la factura, el cliente se carga automáticamente.</small>
                </div>
            </div>
            <div class="col-4">
                <div class="form-group">
                    <label class="form-label">Cliente *</label>
                    <select name="cliente_id" id="selClienteDev" class="form-select" required
                            data-placeholder="— Seleccionar —">
                        <option value="">— Seleccionar —</option>
                        <?php foreach ($clientes as $cl): ?>
                        <option value="<?= $cl['id'] ?>"><?= clean($cl['nombre']) ?><?= $cl['rif']?' ('.clean($cl['rif']).')':'' ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="col-3">
                <div class="form-group">
                    <label class="form-label">Fecha</label>
                    <input type="date" name="fecha" class="form-control" value="<?= date('Y-m-d') ?>" required>
                </div>
            </div>
        </div>
        <div class="form-group mb-3">
            <label class="form-label">Motivo de la devolución *</label>
            <textarea name="motivo" class="form-control" rows="2"
                placeholder="Ej: Producto defectuoso, error en pedido, exceso de stock..." required></textarea>
        </div>

        <div id="infoFactura" style="display:none;background:#f0f7ff;border:1px solid #bfdbfe;border-radius:8px;padding:10px 14px;margin-bottom:14px;font-size:12.5px;align-items:center;gap:8px">
            <i class="fa-solid fa-file-invoice" style="color:var(--info)"></i>
            <strong id="infoFacturaNumero"></strong> —
            Factura por <strong id="infoFacturaMonto"></strong>
        </div>

        <hr class="divider">

        <div class="d-flex justify-between align-center mb-2">
            <strong class="fs-13">Productos a devolver</strong>
            <button type="button" onclick="addDevRow()" class="btn btn-sm btn-primary">
                <i class="fa-solid fa-plus"></i> Agregar producto
            </button>
        </div>

        <div class="table-wrapper">
        <table style="width:100%;border-collapse:collapse;font-size:12.5px">
            <thead><tr style="background:#f8fafc">
                <th style="padding:8px;border-bottom:2px solid var(--border);width:38%">Producto</th>
                <th style="padding:8px;border-bottom:2px solid var(--border);width:11%">Código</th>
                <th style="padding:8px;border-bottom:2px solid var(--border);width:8%">Stock</th>
                <th style="padding:8px;border-bottom:2px solid var(--border);width:10%">Cantidad</th>
                <th style="padding:8px;border-bottom:2px solid var(--border);width:12%">Precio $ c/u</th>
                <th style="padding:8px;border-bottom:2px solid var(--border);width:11%">Subtotal $</th>
                <th style="padding:8px;border-bottom:2px solid var(--border);width:5%"></th>
            </tr></thead>
            <tbody id="tbodyDev"></tbody>
            <tfoot><tr style="background:#fff5f5">
                <td colspan="4" style="padding:9px 8px;text-align:right;font-weight:700;font-size:12px;color:var(--danger)">TOTAL A DEVOLVER:</td>
                <td colspan="2" style="padding:9px 8px;font-weight:800;font-size:15px;color:var(--danger)" id="spanDevTotal">$ 0.00</td>
                <td></td>
            </tr></tfoot>
        </table>
        </div>

        <div style="margin-top:12px;background:#fff8e1;border:1px solid #fde68a;border-radius:8px;padding:10px 14px;font-size:12px">
            <i class="fa-solid fa-triangle-exclamation" style="color:#f59e0b"></i>
            Al ser <strong>aprobada</strong>, el stock de cada producto se restituirá automáticamente y la factura original será ajustada.
        </div>
    </div>
    <div class="modal-footer">
        <button type="button" onclick="closeModal('modalDev')" class="btn btn-light">Cancelar</button>
        <button type="submit" class="btn btn-primary">
            <i class="fa-solid fa-rotate-left"></i> Registrar Devolución
        </button>
    </div>
    </form>
</div>
</div>

<!-- MODAL: Detalle devolución -->
<div class="modal-overlay" id="modalDevDetalle">
<div class="modal-box" style="max-width:680px">
    <div class="modal-header">
        <h4><i class="fa-solid fa-clipboard-list"></i> Detalle de Devolución</h4>
        <button type="button" class="btn-close" onclick="closeModal('modalDevDetalle')"><i class="fa-solid fa-xmark"></i></button>
    </div>
    <div class="modal-body" id="contenidoDevDetalle">
        <div style="text-align:center;padding:36px;color:var(--text-muted)">
            <i class="fa-solid fa-spinner fa-spin" style="font-size:28px;display:block;margin-bottom:10px"></i>Cargando...
        </div>
    </div>
</div>
</div>

<?php ob_start(); ?>
<script>
const PRODS_DEV = <?= $prodsJS ?>;
let devIdx = 0;

// ── DataTable ────────────────────────────────────────────
document.addEventListener('DOMContentLoaded', function() {
    if (typeof jQuery !== 'undefined' && jQuery.fn && jQuery.fn.DataTable) {
        if (jQuery.fn.DataTable.isDataTable('#tablaDev')) {
            jQuery('#tablaDev').DataTable().destroy();
        }
        jQuery('#tablaDev').DataTable({
            language: (typeof dtLang !== 'undefined') ? dtLang : {},
            pageLength: 20,
            order: [[1,'desc']],
            columnDefs: [{ targets: 7, orderable: false }]
        });
    }
});

// ── Abrir modal + inicializar ────────────────────────────
function abrirDevModal() {
    if (typeof openModal === 'function') openModal('modalDev');
    setTimeout(function() {
        // Inicializar select2 en los selects del modal
        if (typeof initSelect2 === 'function' && typeof jQuery !== 'undefined') {
            initSelect2('#selVenta', jQuery('#modalDev'));
            initSelect2('#selClienteDev', jQuery('#modalDev'));
        }
        // Agregar filas si el tbody está vacío
        const tbody = document.getElementById('tbodyDev');
        if (tbody && tbody.children.length === 0) {
            addDevRow();
            addDevRow();
        }
    }, 150);
}

// ── Cargar datos de la factura seleccionada ──────────────
function cargarDatosFactura() {
    const sel = document.getElementById('selVenta');
    if (!sel) return;
    const opt = sel.options[sel.selectedIndex];
    const info = document.getElementById('infoFactura');

    if (!opt || !opt.value) {
        if (info) info.style.display = 'none';
        return;
    }
    const cliId = opt.dataset.cliente;

    const selCli = document.getElementById('selClienteDev');
    if (selCli && cliId) {
        selCli.value = cliId;
        if (typeof jQuery !== 'undefined') jQuery(selCli).trigger('change');
    }

    const numEl = document.getElementById('infoFacturaNumero');
    const montoEl = document.getElementById('infoFacturaMonto');
    if (numEl) numEl.textContent = opt.text.split('—')[0].trim();
    if (montoEl) montoEl.textContent = '$ ' + parseFloat(opt.dataset.total || 0).toFixed(2);
    if (info) info.style.display = 'flex';
}

// ── Agregar fila ─────────────────────────────────────────
function addDevRow() {
    const i = devIdx++;
    const tbody = document.getElementById('tbodyDev');
    if (!tbody) return;

    const tr = document.createElement('tr');
    tr.id = 'dr' + i;
    tr.style.borderBottom = '1px solid var(--border)';
    tr.innerHTML =
        '<td style="padding:5px 6px">' +
            '<div class="prod-wrap">' +
                '<input type="text" class="prod-input" id="dsrch_' + i + '" placeholder="Buscar producto..." autocomplete="off" ' +
                    'oninput="devFiltrar(' + i + ')" onfocus="devFiltrar(' + i + ')" onblur="setTimeout(function(){devCerrar(' + i + ');},200)">' +
                '<div class="prod-dd" id="ddd_' + i + '"></div>' +
            '</div>' +
            '<input type="hidden" name="producto_id[]" id="dpid_' + i + '" value="">' +
            '<input type="hidden" name="descripcion[]" id="ddesc_' + i + '" value="">' +
        '</td>' +
        '<td style="padding:5px 6px"><input type="text" id="dcod_' + i + '" class="form-control font-mono" readonly style="font-size:11px;background:#f8fafc;padding:4px 6px"></td>' +
        '<td style="padding:5px 6px;text-align:center;font-size:12px;color:var(--text-muted)" id="dstk_' + i + '">—</td>' +
        '<td style="padding:5px 6px"><input type="number" name="cantidad[]" id="dcant_' + i + '" class="num-inp" min="0.01" step="0.01" placeholder="0" oninput="devCalc(' + i + ')"></td>' +
        '<td style="padding:5px 6px"><input type="number" name="precio[]" id="dprec_' + i + '" class="num-inp" min="0" step="0.01" placeholder="0.00" oninput="devCalc(' + i + ')"></td>' +
        '<td style="padding:5px 6px;font-weight:700;color:var(--danger)" id="dsub_' + i + '">$ 0.00</td>' +
        '<td style="padding:5px 6px"><button type="button" onclick="document.getElementById(\'dr' + i + '\').remove();devTotal()" class="btn btn-sm btn-danger btn-icon"><i class="fa-solid fa-xmark"></i></button></td>';
    tbody.appendChild(tr);
}

// ── Filtro del buscador ──────────────────────────────────
function devFiltrar(i) {
    const el = document.getElementById('dsrch_' + i);
    const dd = document.getElementById('ddd_' + i);
    if (!el || !dd) return;

    const q = (el.value || '').toLowerCase().trim();
    const lista = q.length < 1
        ? PRODS_DEV.slice(0, 30)
        : PRODS_DEV.filter(function(p) {
            return p.desc.toLowerCase().includes(q) || p.cod.toLowerCase().includes(q);
        });

    if (!lista.length) { dd.style.display = 'none'; return; }

    dd.innerHTML = lista.map(function(p) {
        return '<div class="opt" onmousedown="devSel(' + i + ',' + p.id + ')">' +
            '<span><b class="font-mono" style="color:var(--primary);font-size:11px">' + p.cod + '</b> ' + p.desc + '</span>' +
            '<span style="text-align:right;font-size:11px;color:var(--text-muted)">Stock: ' + p.stock + ' ' + p.unid + '</span>' +
        '</div>';
    }).join('');
    dd.style.display = 'block';
}

// ── Seleccionar producto ─────────────────────────────────
function devSel(i, pid) {
    const p = PRODS_DEV.find(function(x) { return x.id === pid; });
    if (!p) return;
    const setVal = function(id, val) {
        const el = document.getElementById(id);
        if (el) el.value = val;
    };
    setVal('dpid_' + i, p.id);
    setVal('ddesc_' + i, p.desc);
    setVal('dsrch_' + i, p.desc);
    setVal('dcod_' + i, p.cod);
    setVal('dprec_' + i, p.prec.toFixed(2));
    const stkEl = document.getElementById('dstk_' + i);
    if (stkEl) stkEl.textContent = p.stock + ' ' + p.unid;
    devCerrar(i);
    const cantEl = document.getElementById('dcant_' + i);
    if (cantEl) cantEl.focus();
    devCalc(i);
}

function devCerrar(i) {
    const dd = document.getElementById('ddd_' + i);
    if (dd) dd.style.display = 'none';
}

// ── Cálculos ─────────────────────────────────────────────
function devCalc(i) {
    const cantEl = document.getElementById('dcant_' + i);
    const precEl = document.getElementById('dprec_' + i);
    const subEl = document.getElementById('dsub_' + i);
    const c = cantEl ? parseFloat(cantEl.value || 0) : 0;
    const p = precEl ? parseFloat(precEl.value || 0) : 0;
    if (subEl) subEl.textContent = '$ ' + (c * p).toFixed(2);
    devTotal();
}

function devTotal() {
    let tot = 0;
    document.querySelectorAll('#tbodyDev tr').forEach(function(r) {
        const idx = r.id.replace('dr', '');
        const cantEl = document.getElementById('dcant_' + idx);
        const precEl = document.getElementById('dprec_' + idx);
        tot += (cantEl ? parseFloat(cantEl.value || 0) : 0) * (precEl ? parseFloat(precEl.value || 0) : 0);
    });
    const span = document.getElementById('spanDevTotal');
    if (span) span.textContent = '$ ' + tot.toFixed(2);
}

// ── Ver detalle ──────────────────────────────────────────
function verDevDetalle(id) {
    const cont = document.getElementById('contenidoDevDetalle');
    if (cont) cont.innerHTML = '<div style="text-align:center;padding:36px"><i class="fa-solid fa-spinner fa-spin" style="font-size:28px"></i></div>';
    if (typeof openModal === 'function') openModal('modalDevDetalle');
    fetch('api/devolucion_detalle.php?id=' + id)
        .then(function(r) { return r.text(); })
        .then(function(html) { if (cont) cont.innerHTML = html; })
        .catch(function() {
            if (cont) cont.innerHTML = '<div class="alert alert-danger" style="margin:20px">Error al cargar.</div>';
        });
}

// ── Validar antes de enviar ──────────────────────────────
document.addEventListener('DOMContentLoaded', function() {
    const form = document.getElementById('formDev');
    if (!form) return;
    form.addEventListener('submit', function(e) {
        // Sincronizar select2
        if (typeof jQuery !== 'undefined' && jQuery.fn && jQuery.fn.select2) {
            ['#selVenta', '#selClienteDev'].forEach(function(sel) {
                const $el = jQuery(sel);
                if ($el.hasClass('select2-hidden-accessible')) $el.trigger('change');
            });
        }
        let ok = false;
        document.querySelectorAll('#tbodyDev tr').forEach(function(r) {
            const idx = r.id.replace('dr', '');
            const cantEl = document.getElementById('dcant_' + idx);
            if (cantEl && parseFloat(cantEl.value || 0) > 0) ok = true;
        });
        if (!ok) {
            e.preventDefault();
            if (typeof Swal !== 'undefined') {
                Swal.fire('Sin productos', 'Agrega al menos un producto con cantidad.', 'warning');
            } else {
                alert('Agrega al menos un producto con cantidad.');
            }
        }
    });
});
</script>
<?php $extraScript = ob_get_clean(); ?>

<?php include 'includes/footer.php'; ?>