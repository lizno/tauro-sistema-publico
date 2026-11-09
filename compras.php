<?php
require_once 'config/config.php';
require_once 'functions/db.php';
require_once 'functions/helpers.php';
require_once 'functions/auth.php';

requireLogin();
requirePermission('compras','ver');
$pageTitle = 'Entradas de Inventario';

// ── CREAR ENTRADA ──────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'crear') {
    verifyCsrf();
    requirePermission('compras','crear');

    $provId  = cleanInt($_POST['proveedor_id'] ?? 0) ?: null;
    $fecha   = $_POST['fecha'] ?? date('Y-m-d');
    $numero  = clean($_POST['numero'] ?? '') ?: generarNumeroCompra();
    $notas   = clean($_POST['notas'] ?? '');
    $prods   = $_POST['producto_id'] ?? [];
    $cants   = $_POST['cantidad']    ?? [];
    $precs   = $_POST['precio']      ?? [];

    $lineas = array_filter(array_keys($prods), fn($i) =>
        cleanInt($prods[$i]) > 0 && cleanFloat($cants[$i] ?? 0) > 0
    );

    if (empty($lineas)) {
        $_SESSION['flash'] = ['type'=>'error','msg'=>'Agrega al menos un producto con cantidad válida.'];
        $_SESSION['show_compra_form'] = true;
        header('Location: compras.php?action=nuevo'); exit;
    }

    try {
        $pdo = getDB();
        $pdo->beginTransaction();

        $totalUSD = 0;
        foreach ($lineas as $i) {
            $totalUSD += cleanFloat($cants[$i]) * cleanFloat($precs[$i] ?? 0);
        }

        dbExecute(
            "INSERT INTO compras (numero,proveedor_id,fecha,total_usd,notas,estado,usuario_id)
             VALUES (?,?,?,?,?,'recibido',?)",
            [$numero, $provId, $fecha, $totalUSD, $notas, $_SESSION['user_id']]
        );
        $compraId = (int)dbLastId();

        foreach ($lineas as $i) {
            $pid  = cleanInt($prods[$i]);
            $cant = cleanFloat($cants[$i]);
            $prec = round(cleanFloat($precs[$i] ?? 0), 4);
            dbExecute(
                "INSERT INTO compra_detalles (compra_id,producto_id,cantidad,precio_usd,monto_usd)
                 VALUES (?,?,?,?,?)",
                [$compraId, $pid, $cant, $prec, round($cant * $prec, 4)]
            );
            actualizarStock($pid, $cant, 'sumar');
        }

        $pdo->commit();
        auditLog('INSERT','compras',$compraId,"Entrada creada: {$numero} · Total: \${$totalUSD}");
        $_SESSION['flash'] = ['type'=>'success',
            'msg' => "Entrada <strong>{$numero}</strong> registrada correctamente. Stock actualizado en " . count($lineas) . " producto(s)."
        ];
    } catch (Exception $e) {
        if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
        $_SESSION['flash'] = ['type'=>'error','msg'=>'Error al guardar: ' . $e->getMessage()];
        $_SESSION['show_compra_form'] = true;
    }
    header('Location: compras.php'); exit;
}

// ── DATOS ──────────────────────────────────────────────────
$compras = dbQuery(
    "SELECT c.*, p.nombre proveedor_nombre, u.nombre u_nombre
     FROM compras c
     LEFT JOIN proveedores p ON c.proveedor_id = p.id
     LEFT JOIN usuarios u    ON c.usuario_id   = u.id
     ORDER BY c.fecha DESC, c.id DESC"
);
$proveedores = dbQuery("SELECT * FROM proveedores WHERE activo=1 ORDER BY nombre");
$productos   = dbQuery(
    "SELECT p.id, p.codigo, p.descripcion, p.stock_actual, p.unidad,
            ROUND(COALESCE(pm.precio_usd,0),4) costo_referencia
     FROM productos p
     LEFT JOIN precios pm ON pm.producto_id=p.id AND pm.tipo='mayorista'
     WHERE p.activo=1 AND p.departamento_id=1
     ORDER BY p.descripcion"
);

$totalEntradas = array_sum(array_column($compras,'total_usd'));
$totalMes      = array_sum(array_map(
    fn($c) => date('Y-m', strtotime($c['fecha'])) === date('Y-m') ? (float)$c['total_usd'] : 0,
    $compras
));
$showForm = (($_GET['action'] ?? '') === 'nuevo') || !empty($_SESSION['show_compra_form']);
unset($_SESSION['show_compra_form']);

$prodsJS = json_encode(array_map(fn($p) => [
    'id'    => (int)$p['id'],
    'cod'   => $p['codigo'],
    'desc'  => $p['descripcion'],
    'unid'  => $p['unidad'],
    'stock' => (float)$p['stock_actual'],
    'costo' => (float)$p['costo_referencia'],
], $productos), JSON_UNESCAPED_UNICODE);

include 'includes/header.php';
?>
<style>
/* ── Buscador de producto en filas dinámicas ── */
.prod-wrap   { position:relative; }
.prod-input  { width:100%;padding:6px 8px;border:1.5px solid var(--border);border-radius:6px;font-size:12.5px;font-family:var(--font); }
.prod-input:focus { border-color:var(--primary);outline:none; }
.prod-input.error { border-color:var(--danger); background:#fff5f5; }
.prod-dd     { position:absolute;top:100%;left:0;right:0;background:white;border:1.5px solid var(--primary);
               border-top:none;border-radius:0 0 7px 7px;max-height:190px;overflow-y:auto;
               z-index:600;display:none;box-shadow:0 6px 16px rgba(0,0,0,.12); }
.prod-dd .opt{ padding:8px 10px;cursor:pointer;font-size:12px;border-bottom:1px solid #f0f2f5;
               display:flex;justify-content:space-between;align-items:center; }
.prod-dd .opt:hover { background:#f0f7ff; }
.opt-left .cod { font-family:monospace;font-weight:700;color:var(--primary);font-size:11px; }
.opt-left .desc{ font-size:12px;margin-top:1px; }
.opt-right .stock{ font-size:11px;color:var(--text-muted); }
.opt-right .precio{ font-size:12px;font-weight:700;color:var(--success); }
/* ── Tabla de filas ── */
.comp-row td { padding:5px 6px;vertical-align:middle;border-bottom:1px solid var(--border); }
.num-inp { width:100%;padding:5px 7px;border:1.5px solid var(--border);border-radius:6px;
           font-size:12.5px;text-align:right;font-family:var(--font); }
.num-inp:focus { border-color:var(--primary);outline:none; }
/* Fix select2 dentro de modal */
#modalCompra .select2-container { width:100% !important; }
#modalCompra .select2-container--open { z-index: 9999; }
</style>

<!-- STATS -->
<div class="row mb-4">
    <div class="col-3">
        <div class="stat-card blue">
            <div class="stat-icon"><i class="fa-solid fa-truck-ramp-box"></i></div>
            <div><div class="stat-value"><?= count($compras) ?></div><div class="stat-label">Total entradas</div></div>
        </div>
    </div>
    <div class="col-3">
        <div class="stat-card green">
            <div class="stat-icon"><i class="fa-solid fa-dollar-sign"></i></div>
            <div><div class="stat-value"><?= formatUSD($totalMes) ?></div><div class="stat-label">Invertido este mes</div></div>
        </div>
    </div>
    <div class="col-3">
        <div class="stat-card">
            <div class="stat-icon"><i class="fa-solid fa-boxes-stacked"></i></div>
            <div><div class="stat-value"><?= formatUSD($totalEntradas) ?></div><div class="stat-label">Total histórico</div></div>
        </div>
    </div>
    <div class="col-3">
        <div class="stat-card orange">
            <div class="stat-icon"><i class="fa-solid fa-industry"></i></div>
            <div><div class="stat-value"><?= count($proveedores) ?></div><div class="stat-label">Proveedores activos</div></div>
        </div>
    </div>
</div>

<!-- CABECERA -->
<div class="d-flex justify-between align-center mb-4">
    <div>
        <h4 class="fw-bold" style="margin:0">Entradas de Inventario</h4>
        <p class="text-muted fs-12" style="margin:2px 0 0">Registra mercancía recibida para actualizar el stock automáticamente.</p>
    </div>
    <div class="d-flex gap-2">
        <button type="button" onclick="exportExcel('tablaCompras','Entradas_Tauro')" class="btn btn-light">
            <i class="fa-solid fa-table-cells"></i> Excel
        </button>
        <?php if (hasPermission('compras','crear')): ?>
        <button type="button" onclick="abrirModalCompra()" class="btn btn-primary">
            <i class="fa-solid fa-plus"></i> Nueva Entrada
        </button>
        <?php endif; ?>
    </div>
</div>

<!-- TABLA DE ENTRADAS -->
<div class="card">
    <div class="card-header">
        <i class="fa-solid fa-truck-ramp-box text-primary"></i>
        <h5>Historial de Entradas — <?= count($compras) ?></h5>
    </div>
    <div class="card-body p-0">
    <div class="table-wrapper">
    <table class="table" id="tablaCompras">
        <thead>
            <tr>
                <th>N° Guía / Factura</th>
                <th>Fecha</th>
                <th>Proveedor</th>
                <th class="text-right">Total $</th>
                <th>Estado</th>
                <th>Registrado por</th>
                <th>Acciones</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($compras as $c): ?>
        <tr>
            <td><strong class="font-mono fs-12"><?= clean($c['numero'] ?? '—') ?></strong></td>
            <td><?= date('d/m/Y', strtotime($c['fecha'])) ?></td>
            <td><?= clean($c['proveedor_nombre'] ?? 'Sin proveedor') ?></td>
            <td class="text-right fw-bold"><?= formatUSD($c['total_usd']) ?></td>
            <td><?= badgeEstado($c['estado'] ?? 'recibido') ?></td>
            <td class="fs-12 text-muted"><?= clean($c['u_nombre'] ?? '—') ?></td>
            <td>
                <button type="button" onclick="verDetalle(<?= $c['id'] ?>)" class="btn btn-sm btn-light btn-icon" title="Ver detalle">
                    <i class="fa-solid fa-eye"></i>
                </button>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    </div>
</div>

<!-- ═══ MODAL: Nueva Entrada ═══════════════════════════════ -->
<div class="modal-overlay" id="modalCompra">
<div class="modal-box modal-lg" style="max-width:900px">
    <div class="modal-header">
        <i class="fa-solid fa-truck-ramp-box text-primary"></i>
        <h4>Nueva Entrada de Inventario</h4>
        <button type="button" class="btn-close" onclick="closeModal('modalCompra')"><i class="fa-solid fa-xmark"></i></button>
    </div>
    <form method="POST" id="formCompra">
    <div class="modal-body">
        <input type="hidden" name="<?= CSRF_TOKEN_NAME ?>" value="<?= getCsrfToken() ?>">
        <input type="hidden" name="action" value="crear">

        <!-- Datos de la entrada -->
        <div class="row mb-3">
            <div class="col-4">
                <div class="form-group">
                    <label class="form-label">N° de Guía / Factura del proveedor</label>
                    <input type="text" name="numero" class="form-control"
                        placeholder="Ej: 26YANG0310 (opcional)">
                </div>
            </div>
            <div class="col-4">
                <div class="form-group">
                    <label class="form-label">Proveedor</label>
                    <select name="proveedor_id" class="form-select" id="selProveedor"
                            data-placeholder="— Sin proveedor —" data-allow-clear="true">
                        <option value="">— Sin proveedor —</option>
                        <?php foreach ($proveedores as $p): ?>
                            <option value="<?= (int)$p['id'] ?>"><?= clean($p['nombre']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <?php if (empty($proveedores)): ?>
                        <small class="text-danger" style="display:block;margin-top:4px">
                            ⚠ No se cargaron proveedores. Verifica que existan con activo=1.
                        </small>
                    <?php endif; ?>
                </div>
            </div>
            <div class="col-4">
                <div class="form-group">
                    <label class="form-label">Fecha de recepción</label>
                    <input type="date" name="fecha" class="form-control" value="<?= date('Y-m-d') ?>" required>
                </div>
            </div>
        </div>
        <div class="form-group mb-3">
            <label class="form-label">Notas internas</label>
            <input type="text" name="notas" class="form-control" placeholder="Ej: Contenedor 2, importación China...">
        </div>

        <hr class="divider">

        <!-- Tabla de productos -->
        <div class="d-flex justify-between align-center mb-2">
            <div>
                <strong class="fs-13">Productos recibidos</strong>
                <small class="text-muted" style="margin-left:8px">
                    <i class="fa-solid fa-lightbulb" style="color:var(--warning)"></i>
                    Escribe para buscar y haz clic en el producto. El costo es de referencia, puedes editarlo.
                </small>
            </div>
            <button type="button" onclick="addRow()" class="btn btn-sm btn-primary">
                <i class="fa-solid fa-plus"></i> Agregar producto
            </button>
        </div>

        <div class="table-wrapper">
        <table style="width:100%;border-collapse:collapse;font-size:13px">
            <thead>
                <tr style="background:#f8fafc">
                    <th style="padding:8px 6px;font-size:10.5px;font-weight:700;text-transform:uppercase;color:var(--text-muted);border-bottom:2px solid var(--border);width:40%">Producto</th>
                    <th style="padding:8px 6px;font-size:10.5px;font-weight:700;text-transform:uppercase;color:var(--text-muted);border-bottom:2px solid var(--border);width:11%">Código</th>
                    <th style="padding:8px 6px;font-size:10.5px;font-weight:700;text-transform:uppercase;color:var(--text-muted);border-bottom:2px solid var(--border);width:8%">Unidad</th>
                    <th style="padding:8px 6px;font-size:10.5px;font-weight:700;text-transform:uppercase;color:var(--text-muted);border-bottom:2px solid var(--border);width:10%">Stock actual</th>
                    <th style="padding:8px 6px;font-size:10.5px;font-weight:700;text-transform:uppercase;color:var(--text-muted);border-bottom:2px solid var(--border);width:10%">Cantidad</th>
                    <th style="padding:8px 6px;font-size:10.5px;font-weight:700;text-transform:uppercase;color:var(--text-muted);border-bottom:2px solid var(--border);width:11%">Costo $ c/u</th>
                    <th style="padding:8px 6px;font-size:10.5px;font-weight:700;text-transform:uppercase;color:var(--text-muted);border-bottom:2px solid var(--border);width:10%">Subtotal $</th>
                    <th style="padding:8px 6px;border-bottom:2px solid var(--border);width:4%"></th>
                </tr>
            </thead>
            <tbody id="tbodyCompra"></tbody>
            <tfoot>
                <tr style="background:#f8fafc">
                    <td colspan="5" style="padding:10px 6px;text-align:right;font-weight:700;font-size:12px;color:var(--text-muted)">TOTAL ENTRADA:</td>
                    <td colspan="2" style="padding:10px 6px;font-weight:800;font-size:16px;color:var(--success)" id="spanTotal">$ 0.00</td>
                    <td></td>
                </tr>
            </tfoot>
        </table>
        </div>

        <div style="margin-top:12px;background:#f0fdf4;border:1px solid #bbf7d0;border-radius:8px;padding:10px 14px;font-size:12px">
            <i class="fa-solid fa-circle-info" style="color:var(--success)"></i>
            Al guardar, el stock de cada producto se incrementará automáticamente con la cantidad ingresada.
        </div>
    </div>
    <div class="modal-footer">
        <button type="button" onclick="closeModal('modalCompra')" class="btn btn-light">Cancelar</button>
        <button type="submit" class="btn btn-primary" style="min-width:160px">
            <i class="fa-solid fa-truck-ramp-box"></i> Registrar Entrada
        </button>
    </div>
    </form>
</div>
</div>

<!-- ═══ MODAL: Ver detalle de entrada ═══════════════════════ -->
<div class="modal-overlay" id="modalDetalle">
<div class="modal-box" style="max-width:700px">
    <div class="modal-header">
        <i class="fa-solid fa-clipboard-list text-primary"></i>
        <h4>Detalle de Entrada</h4>
        <button type="button" class="btn-close" onclick="closeModal('modalDetalle')"><i class="fa-solid fa-xmark"></i></button>
    </div>
    <div class="modal-body" id="contenidoDetalle">
        <div style="text-align:center;padding:40px;color:var(--text-muted)">
            <i class="fa-solid fa-spinner fa-spin" style="font-size:28px;margin-bottom:12px"></i><br>
            Cargando...
        </div>
    </div>
</div>
</div>

<script>
// ── Datos inyectados por PHP ────────────────────────────────
const PRODS   = <?= $prodsJS ?>;
const APPURL  = '<?= rtrim(APP_URL, '/') ?>';
let rowIdx    = 0;

// ── DataTable ───────────────────────────────────────────────
document.addEventListener('DOMContentLoaded', function() {
    if (typeof jQuery !== 'undefined' && jQuery.fn && jQuery.fn.DataTable) {
        if (jQuery.fn.DataTable.isDataTable('#tablaCompras')) {
            jQuery('#tablaCompras').DataTable().destroy();
        }
        jQuery('#tablaCompras').DataTable({
            language: (typeof dtLang !== 'undefined') ? dtLang : {},
            pageLength: 20,
            order: [[1,'desc']],
            columnDefs: [{ targets: 6, orderable: false }]
        });
    }
});

// ── Abrir modal con filas listas ────────────────────────────
function abrirModalCompra() {
    if (typeof openModal === 'function') openModal('modalCompra');

    setTimeout(function() {
        // ✅ Inicializar select2 DEL PROVEEDOR aquí, ya con el modal visible.
        // dropdownParent es CLAVE: hace que el dropdown se dibuje dentro del modal
        // (si no, queda detrás del overlay y no se ve).
        if (typeof initSelect2 === 'function') {
            initSelect2('#selProveedor', jQuery('#modalCompra'));
        }

        if (document.getElementById('tbodyCompra').children.length === 0) {
            addRow(); addRow(); addRow();
        }
    }, 150);
}

// ── Agregar fila ────────────────────────────────────────────
function addRow(init) {
    const i = rowIdx++;
    const tr = document.createElement('tr');
    tr.className = 'comp-row';
    tr.id = 'cr' + i;
    tr.innerHTML = `
        <td>
            <div class="prod-wrap">
                <input type="text" class="prod-input" id="srch_${i}"
                    placeholder="Escribe nombre o código..."
                    autocomplete="off"
                    oninput="filtrar(${i})"
                    onfocus="filtrar(${i})"
                    onblur="setTimeout(()=>validarProducto(${i}),200)">
                <div class="prod-dd" id="dd_${i}"></div>
            </div>
            <input type="hidden" name="producto_id[]" id="pid_${i}" value="">
        </td>
        <td><input type="text" id="cod_${i}" class="form-control font-mono" readonly style="font-size:11px;background:#f8fafc;padding:5px 7px"></td>
        <td style="text-align:center;font-size:12px;color:var(--text-muted)" id="unid_${i}">—</td>
        <td style="text-align:center;font-size:12px" id="stk_${i}">—</td>
        <td><input type="number" name="cantidad[]" id="cant_${i}" class="num-inp" min="0.01" step="0.01" placeholder="0" oninput="calcRow(${i})"></td>
        <td><input type="number" name="precio[]"   id="prec_${i}" class="num-inp" min="0" step="0.0001" placeholder="0.0000" oninput="calcRow(${i})"></td>
        <td style="font-weight:700;color:var(--success);font-size:13px" id="sub_${i}">$ 0.00</td>
        <td><button type="button" onclick="document.getElementById('cr${i}').remove();calcTotal()" class="btn btn-sm btn-danger btn-icon"><i class="fa-solid fa-xmark"></i></button></td>
    `;
    document.getElementById('tbodyCompra').appendChild(tr);

    if (init) {
        document.getElementById('pid_'+i).value  = init.pid  || '';
        document.getElementById('srch_'+i).value = init.desc || '';
        document.getElementById('cod_'+i).value  = init.cod  || '';
        document.getElementById('cant_'+i).value = init.cant || '';
        document.getElementById('prec_'+i).value = init.prec || '';
        calcRow(i);
    }
}

// ── Filtro del buscador ─────────────────────────────────────
function filtrar(i) {
    const el = document.getElementById('srch_'+i);
    if (!el) return;
    const q  = el.value.toLowerCase().trim();
    const dd = document.getElementById('dd_'+i);
    if (!dd) return;

    const lista = q.length < 1
        ? PRODS.slice(0, 40)
        : PRODS.filter(p => p.desc.toLowerCase().includes(q) || p.cod.toLowerCase().includes(q));

    if (!lista.length) { dd.style.display = 'none'; return; }

    dd.innerHTML = lista.map(function(p) {
        return `
        <div class="opt" onmousedown="selProd(${i},${p.id})">
            <div class="opt-left">
                <div class="cod">${p.cod}</div>
                <div class="desc">${p.desc}</div>
            </div>
            <div class="opt-right" style="text-align:right">
                <div class="stock">Stock: ${p.stock} ${p.unid}</div>
                <div class="precio">Costo ref: $ ${p.costo.toFixed(4)}</div>
            </div>
        </div>`;
    }).join('');
    dd.style.display = 'block';
}

// ── Seleccionar producto del dropdown ───────────────────────
function selProd(i, pid) {
    const p = PRODS.find(x => x.id === pid);
    if (!p) return;
    document.getElementById('pid_'+i).value    = p.id;
    document.getElementById('srch_'+i).value   = p.desc;
    document.getElementById('cod_'+i).value    = p.cod;
    document.getElementById('unid_'+i).textContent = p.unid;
    document.getElementById('stk_'+i).innerHTML = `<span style="font-weight:700;${p.stock<=0?'color:var(--danger)':''}">${p.stock}</span>`;
    document.getElementById('prec_'+i).value   = p.costo > 0 ? p.costo.toFixed(4) : '';
    document.getElementById('srch_'+i).classList.remove('error');
    cerrarDD(i);
    document.getElementById('cant_'+i).focus();
    calcRow(i);
}

function cerrarDD(i) {
    const dd = document.getElementById('dd_'+i);
    if (dd) dd.style.display = 'none';
}

function validarProducto(i) {
    const hidden = document.getElementById('pid_'+i);
    const srch   = document.getElementById('srch_'+i);
    if (!hidden || !srch) return;
    if (!hidden.value && srch.value.trim() !== '') {
        srch.classList.add('error');
    } else {
        srch.classList.remove('error');
    }
}

// ── Cálculos ────────────────────────────────────────────────
function calcRow(i) {
    const c = parseFloat(document.getElementById('cant_'+i)?.value || 0);
    const p = parseFloat(document.getElementById('prec_'+i)?.value || 0);
    const s = c * p;
    const el = document.getElementById('sub_'+i);
    if (el) el.textContent = '$ ' + s.toFixed(2);
    calcTotal();
}

function calcTotal() {
    let tot = 0;
    document.querySelectorAll('#tbodyCompra tr').forEach(function(r) {
        const idx = r.id.replace('cr','');
        const c = parseFloat(document.getElementById('cant_'+idx)?.value || 0);
        const p = parseFloat(document.getElementById('prec_'+idx)?.value || 0);
        tot += c * p;
    });
    const span = document.getElementById('spanTotal');
    if (span) span.textContent = '$ ' + tot.toFixed(2);
}

// ── Ver detalle de entrada ──────────────────────────────────
function verDetalle(id) {
    document.getElementById('contenidoDetalle').innerHTML =
        '<div style="text-align:center;padding:40px;color:var(--text-muted)"><i class="fa-solid fa-spinner fa-spin" style="font-size:28px;display:block;margin-bottom:12px"></i>Cargando...</div>';
    if (typeof openModal === 'function') openModal('modalDetalle');
    fetch(APPURL + '/api/compra_detalle.php?id=' + id)
        .then(function(r) { return r.text(); })
        .then(function(html) { document.getElementById('contenidoDetalle').innerHTML = html; })
        .catch(function() {
            document.getElementById('contenidoDetalle').innerHTML =
                '<div class="alert alert-danger" style="margin:20px"><i class="fa-solid fa-circle-exclamation"></i> Error al cargar el detalle. Reintenta.</div>';
        });
}

// ── Validar antes de enviar ─────────────────────────────────
document.addEventListener('DOMContentLoaded', function() {
    const form = document.getElementById('formCompra');
    if (!form) return;

    form.addEventListener('submit', function(e) {
        // ✅ Sincronizar select2 con el <select> real antes de enviar
        if (typeof jQuery !== 'undefined' && jQuery.fn && jQuery.fn.select2) {
            const $sel = jQuery('#selProveedor');
            if ($sel.hasClass('select2-hidden-accessible')) {
                $sel.trigger('change');
            }
        }

        let validas = 0;
        let hayErrorProducto = false;

        document.querySelectorAll('#tbodyCompra tr').forEach(function(r) {
            const idx  = r.id.replace('cr','');
            const pid  = document.getElementById('pid_'+idx)?.value;
            const cant = parseFloat(document.getElementById('cant_'+idx)?.value || 0);
            const srch = document.getElementById('srch_'+idx)?.value || '';

            if (srch.trim() !== '' && !pid) {
                hayErrorProducto = true;
                document.getElementById('srch_'+idx).classList.add('error');
            }
            if (pid && cant > 0) validas++;
        });

        if (hayErrorProducto) {
            e.preventDefault();
            Swal.fire('Producto no seleccionado',
                'En algunas filas escribiste un producto pero no lo seleccionaste de la lista. Haz clic sobre el producto correcto en el desplegable.',
                'warning');
            return;
        }

        if (validas === 0) {
            e.preventDefault();
            Swal.fire('Sin productos', 'Agrega al menos un producto con cantidad válida.', 'warning');
            return;
        }

        const btn = this.querySelector('[type=submit]');
        if (btn) {
            btn.disabled = true;
            btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Guardando...';
        }
    });
});
</script>

<?php if ($showForm): ?>
<script>
document.addEventListener('DOMContentLoaded', function() {
    setTimeout(abrirModalCompra, 200);
});
</script>
<?php endif; ?>

<?php
include 'includes/footer.php';
?>