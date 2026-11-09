<?php
require_once 'config/config.php';
require_once 'functions/db.php';
require_once 'functions/helpers.php';
require_once 'functions/auth.php';

requireLogin();
requirePermission('ventas','crear');

$tipoDoc = clean($_GET['tipo'] ?? 'despacho');
if (!in_array($tipoDoc, ['despacho','venta'])) $tipoDoc = 'despacho';

$editId   = cleanInt($_GET['edit'] ?? 0);
$editData = null;
$editDets = [];
if ($editId) {
    $editData = dbQueryOne(
        "SELECT * FROM ventas WHERE id=? AND tipo_doc IN('cotizacion','despacho') AND estado!='anulado'",
        [$editId]
    );
    if ($editData) {
        $tipoDoc  = $editData['tipo_doc'];
        $editDets = dbQuery("SELECT * FROM venta_detalles WHERE venta_id=? ORDER BY id", [$editId]);
    }
}

$tipoLabels = ['despacho'=>'Despacho','venta'=>'Factura'];
$pageTitle  = ($editData ? 'Editar ' : 'Nueva ') . ($tipoLabels[$tipoDoc] ?? ucfirst($tipoDoc));
$tasa       = getTasaActual();
// ── SECUENCIAS ACTIVAS (para despachos) ───────────────────
$secuencias = dbQuery("SELECT id,nombre,fecha_ini FROM secuencias WHERE estado='activa' ORDER BY fecha_ini DESC");
$secSelId   = (int)($editData['secuencia_id'] ?? (count($secuencias)===1 ? $secuencias[0]['id'] : 0));

// ── GUARDAR ────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();

    $tipoDocPost = clean($_POST['tipo_doc'] ?? 'despacho');
    if (!in_array($tipoDocPost, ['despacho','venta'])) $tipoDocPost = 'despacho';

    $clienteId  = cleanInt($_POST['cliente_id']  ?? 0);
    $vendedorId = cleanInt($_POST['vendedor_id']  ?? 0) ?: null;
    $tipoCli    = in_array($_POST['tipo_cliente']??'',['mayorista','detalista']) ? $_POST['tipo_cliente'] : 'detalista';
    $tipoPago   = in_array($_POST['tipo_pago']??'',['contado','credito'])        ? $_POST['tipo_pago']   : 'contado';
    $moneda     = in_array($_POST['moneda']??'',['USD','BS'])                    ? $_POST['moneda']      : 'USD';
    $fecha      = $_POST['fecha']      ?? date('Y-m-d');
    $fechaVenc  = !empty($_POST['fecha_vencimiento']) ? $_POST['fecha_vencimiento'] : null;
    $notas      = clean($_POST['notas']     ?? '');
    $secuenciaId = cleanInt($_POST['secuencia_id'] ?? 0) ?: null;
    $tasaId     = $tasa['id']   ?? null;
    $tasaValor  = $tasa ? (float)$tasa['tasa_bs'] : 1;

    $prods = $_POST['producto_id'] ?? [];
    $descs = $_POST['descripcion'] ?? [];
    $refs  = $_POST['ref']         ?? [];
    $precs = $_POST['precio']      ?? [];
    $cants = $_POST['cantidad']    ?? [];
    $bults = $_POST['bulto']       ?? [];

    if (!$clienteId) {
        $_SESSION['flash'] = ['type'=>'error','msg'=>'Selecciona un cliente.'];
        header('Location: '.$_SERVER['REQUEST_URI']); exit;
    }

    $lineas = array_filter(array_keys($prods), fn($i) =>
        cleanFloat($cants[$i]??0) > 0 && !empty(trim($descs[$i]??''))
    );
    if (empty($lineas)) {
        $_SESSION['flash'] = ['type'=>'error','msg'=>'Agrega al menos un producto con cantidad.'];
        header('Location: '.$_SERVER['REQUEST_URI']); exit;
    }

    // Para facturas: verificar que TODOS los productos estén vinculados al inventario
    if ($tipoDocPost === 'venta') {
        $sinVincular = 0;
        foreach ($lineas as $i) {
            if (!cleanInt($prods[$i] ?? 0)) $sinVincular++;
        }
        if ($sinVincular > 0) {
            $_SESSION['flash'] = ['type'=>'error',
                'msg'=>"Error: {$sinVincular} línea(s) sin producto del inventario. Selecciona cada producto desde el buscador para poder crear la Factura."];
            header('Location: '.$_SERVER['REQUEST_URI']); exit;
        }
    }

    try {
        $pdo = getDB();
        $pdo->beginTransaction();

        $totalUSD = 0;
        foreach ($lineas as $i) {
            $totalUSD += cleanFloat($cants[$i]) * round(cleanFloat($precs[$i]),2);
        }
        $totalBS       = round($totalUSD * $tasaValor, 2);

        // Despacho: estado pendiente, NO descuenta stock
        // Factura (venta): descuenta stock al guardar
        $estado = 'pendiente';

        if ($editData) {
            // Editar despacho existente
            dbExecute(
                "UPDATE ventas SET cliente_id=?,vendedor_id=?,tipo_cliente=?,tasa_cambio_id=?,tasa_valor=?,
                 moneda=?,fecha=?,fecha_vencimiento=?,tipo_pago=?,total_usd=?,total_bs=?,
                 notas=?,flete_pct=0,flete_monto_usd=0,secuencia_id=? WHERE id=?",
                [$clienteId,$vendedorId,$tipoCli,$tasaId,$tasaValor,
                 $moneda,$fecha,$fechaVenc,$tipoPago,$totalUSD,$totalBS,
                 $notas,$secuenciaId,$editData['id']]
            );
            $ventaId = $editData['id'];
            dbExecute("DELETE FROM venta_detalles WHERE venta_id=?", [$ventaId]);
            auditLog('UPDATE','ventas',$ventaId,"Doc editado: {$editData['numero']}",$editData,[]);
        } else {
            $numero = generarNumeroDoc($tipoDocPost);
            dbExecute(
                "INSERT INTO ventas (numero,tipo_doc,cliente_id,vendedor_id,tipo_cliente,tasa_cambio_id,
                 tasa_valor,moneda,fecha,fecha_vencimiento,tipo_pago,estado,total_usd,total_bs,
                 notas,flete_pct,flete_monto_usd,monto_pagado_usd,secuencia_id,usuario_id)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,0,0,0,?,?)",
                [$numero,$tipoDocPost,$clienteId,$vendedorId,$tipoCli,$tasaId,
                 $tasaValor,$moneda,$fecha,$fechaVenc,$tipoPago,$estado,
                 $totalUSD,$totalBS,$notas,$secuenciaId,$_SESSION['user_id']]
            );
            $ventaId = (int)dbLastId();
            auditLog('INSERT','ventas',$ventaId,
                "Doc creado: {$numero} Tipo:{$tipoDocPost} Total:\${$totalUSD}" .
                ($tipoDocPost !== 'venta' ? " [SIN AFECTAR STOCK]" : " [STOCK DESCONTADO]")
            );
        }

        // Insertar detalles
        foreach ($lineas as $i) {
            $cant  = cleanFloat($cants[$i]);
            $prec  = round(cleanFloat($precs[$i]), 2);
            $desc  = clean($descs[$i]);
            $ref   = clean($refs[$i] ?? '');
            $pid   = cleanInt($prods[$i]) ?: null;
            $bulto = max(1, cleanInt($bults[$i] ?? 1));
            $monto = round($cant * $prec, 2);
            dbExecute(
                "INSERT INTO venta_detalles (venta_id,producto_id,descripcion,ref,bulto,precio_usd,cantidad,monto_usd)
                 VALUES (?,?,?,?,?,?,?,?)",
                [$ventaId,$pid,$desc,$ref,$bulto,$prec,$cant,$monto]
            );
            // Solo descontar stock si es Factura (venta) directa
            if ($tipoDocPost === 'venta') {
                if ($pid) {
                    actualizarStock($pid, $cant, 'restar');
                }
                // Si no hay producto_id, el stock no se puede descontar (producto manual)
            }
        }

        if ($tipoDocPost === 'venta') actualizarSaldoCliente($clienteId);

        $pdo->commit();
        $_SESSION['flash'] = ['type'=>'success',
            'msg'=> ($tipoLabels[$tipoDocPost]??ucfirst($tipoDocPost)) . " guardada." .
                    ($tipoDocPost !== 'venta' ? " El inventario NO fue afectado." : " Inventario descontado.")
        ];
        header("Location: ver_venta.php?id={$ventaId}"); exit;

    } catch (Exception $e) {
        if (isset($pdo)) $pdo->rollBack();
        error_log($e->getMessage());
        $_SESSION['flash'] = ['type'=>'error','msg'=>'Error al guardar: '.$e->getMessage()];
        header('Location: '.$_SERVER['REQUEST_URI']); exit;
    }
}

// ── DATOS ──────────────────────────────────────────────────
$clientes   = dbQuery("SELECT id,nombre,rif,tipo_default FROM clientes WHERE activo=1 ORDER BY nombre");
$vendedores = dbQuery("SELECT id,nombre,tipo_preferencia FROM vendedores WHERE activo=1 AND departamento_id=1 ORDER BY nombre");
$productos  = dbQuery(
    "SELECT p.id,p.codigo,p.descripcion,p.unidad,p.bulto,p.stock_actual,
            ROUND(pm.precio_usd,2) precio_mayorista,
            ROUND(pd.precio_usd,2) precio_detalista
     FROM productos p
     LEFT JOIN precios pm ON pm.producto_id=p.id AND pm.tipo='mayorista'
     LEFT JOIN precios pd ON pd.producto_id=p.id AND pd.tipo='detalista'
     WHERE p.activo=1 AND p.departamento_id=1 ORDER BY p.descripcion"
);

// Colores e íconos por tipo
$colores = ['despacho'=>'#2980b9','venta'=>'#27ae60'];
$iconos  = ['despacho'=>'fa-truck','venta'=>'fa-file-invoice-dollar'];
$color   = $colores[$tipoDoc];
$icono   = $iconos[$tipoDoc];

include 'includes/header.php';
?>

<!-- SELECTOR DE TIPO (solo si es nuevo) -->
<?php if (!$editData): ?>
<div class="d-flex gap-2 mb-4 align-center">
    <span class="text-muted fw-bold fs-12">Crear:</span>
    <?php foreach (['despacho'=>['Despacho','fa-truck','btn-info'],'venta'=>['Factura','fa-file-invoice-dollar','btn-success']] as $t=>[$lbl,$ic,$cls]): ?>
    <a href="nueva_venta.php?tipo=<?= $t ?>"
       class="btn <?= $tipoDoc===$t ? $cls : 'btn-light' ?>">
        <i class="fa-solid <?= $ic ?>"></i> <?= $lbl ?>
    </a>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<!-- BADGE DEL TIPO ACTUAL -->
<div style="display:inline-flex;align-items:center;gap:8px;background:<?= $color ?>;color:white;padding:6px 14px;border-radius:8px;font-size:13px;font-weight:700;margin-bottom:16px">
    <i class="fa-solid <?= $icono ?>"></i>
    <?= $tipoLabels[$tipoDoc] ?>
    <?php if ($tipoDoc === 'cotizacion'): ?>
    <span style="font-size:11px;opacity:.85;font-weight:400">— Sin afectar stock · Editable</span>
    <?php elseif ($tipoDoc === 'despacho'): ?>
    <span style="font-size:11px;opacity:.85;font-weight:400">— Sin afectar stock · Editable · Para carga del camión</span>
    <?php else: ?>
    <span style="font-size:11px;opacity:.85;font-weight:400">— Descuenta inventario al guardar</span>
    <?php endif; ?>
</div>

<form method="POST" id="formDoc">
<input type="hidden" name="<?= CSRF_TOKEN_NAME ?>" value="<?= getCsrfToken() ?>">
<input type="hidden" name="tipo_doc" value="<?= $tipoDoc ?>">

<div class="row mb-4">
    <!-- DATOS GENERALES -->
    <div class="col-8">
        <div class="card">
            <div class="card-header">
                <i class="fa-solid fa-clipboard-list text-primary"></i>
                <h5>Datos del Documento</h5>
            </div>
            <div class="card-body">
                <div class="row">
                    <div class="col-6">
                        <div class="form-group">
                            <label class="form-label">Cliente *</label>
                            <select name="cliente_id" id="selCliente" class="form-select select2" required>
                                <option value="">— Seleccionar cliente —</option>
                                <?php foreach ($clientes as $c): ?>
                                <option value="<?= $c['id'] ?>" data-tipo="<?= $c['tipo_default'] ?>"
                                    <?= $editData && $editData['cliente_id']==$c['id'] ? 'selected' : '' ?>>
                                    <?= clean($c['nombre']) ?><?= $c['rif'] ? ' ('.clean($c['rif']).')' : '' ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="col-6">
                        <div class="form-group">
                            <label class="form-label">Vendedor</label>
                            <select name="vendedor_id" class="form-select select2">
                                <option value="">— Sin asignar —</option>
                                <?php foreach ($vendedores as $v): ?>
                                <option value="<?= $v['id'] ?>"
                                    <?= $editData && $editData['vendedor_id']==$v['id'] ? 'selected' : '' ?>>
                                    <?= clean($v['nombre']) ?>
                                    <?php if ($v['tipo_preferencia'] && $v['tipo_preferencia']!=='ambos'): ?>
                                    (<?= ucfirst($v['tipo_preferencia']) ?>)
                                    <?php endif; ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                </div>
                <div class="row">
                    <div class="col-3">
                        <div class="form-group">
                            <label class="form-label">Tipo de Cliente *</label>
                            <select name="tipo_cliente" id="selTipoCli" class="form-select" onchange="actualizarPrecios()" required>
                                <option value="detalista" <?= ($editData['tipo_cliente']??'detalista')==='detalista'?'selected':'' ?>>Detalista</option>
                                <option value="mayorista" <?= ($editData['tipo_cliente']??'')==='mayorista'?'selected':'' ?>>Mayorista</option>
                            </select>
                        </div>
                    </div>
                    <div class="col-3">
                        <div class="form-group">
                            <label class="form-label">Tipo de Pago</label>
                            <select name="tipo_pago" id="selTipoPago" class="form-select" onchange="toggleVenc()">
                                <option value="contado" <?= ($editData['tipo_pago']??'')==='contado'?'selected':'' ?>>Contado</option>
                                <option value="credito" <?= ($editData['tipo_pago']??'')==='credito'?'selected':'' ?>>Crédito</option>
                            </select>
                        </div>
                    </div>
                    <div class="col-3">
                        <div class="form-group">
                            <label class="form-label">Moneda</label>
                            <select name="moneda" class="form-select">
                                <option value="USD" <?= ($editData['moneda']??'USD')==='USD'?'selected':'' ?>>USD</option>
                                <option value="BS"  <?= ($editData['moneda']??'')==='BS' ?'selected':'' ?>>Bs.s</option>
                            </select>
                        </div>
                    </div>
                    <div class="col-3">
                        <div class="form-group">
                            <label class="form-label">Fecha</label>
                            <input type="date" name="fecha" class="form-control" value="<?= $editData['fecha']??date('Y-m-d') ?>" required>
                        </div>
                    </div>
                </div>
                <div class="row">
                    <div class="col-4" id="wrapVenc" style="display:none">
                        <div class="form-group">
                            <label class="form-label">Fecha Vencimiento</label>
                            <input type="date" name="fecha_vencimiento" class="form-control"
                                value="<?= $editData['fecha_vencimiento']??date('Y-m-d',strtotime('+30 days')) ?>">
                        </div>
                    </div>
                    <div class="col-8">
                        <div class="form-group">
                            <label class="form-label">Notas / Observaciones</label>
                            <input type="text" name="notas" class="form-control"
                                value="<?= clean($editData['notas']??'') ?>" placeholder="Opcional...">
                        </div>
                    </div>
                </div>

                <?php if ($tipoDoc === 'despacho' || $tipoDoc === 'venta'): ?>
                <div class="row" style="margin-top:4px">
                    <div class="col-12">
                        <div class="form-group">
                            <label class="form-label">
                                <i class="fa-solid fa-layer-group" style="color:var(--primary)"></i>
                                Secuencia de Despacho
                                <span style="font-size:10px;color:var(--text-muted);font-weight:400">
                                    — vincula este documento a una secuencia para cobranza y transporte
                                </span>
                            </label>
                            <?php if (empty($secuencias)): ?>
                            <div style="padding:10px;background:#fff3cd;border-radius:6px;font-size:12px;color:#856404">
                                <i class="fa-solid fa-triangle-exclamation"></i>
                                No hay secuencias activas.
                                <a href="pagos.php" target="_blank" style="color:#856404;font-weight:700">Crea una en Cobranza →</a>
                            </div>
                            <input type="hidden" name="secuencia_id" value="">
                            <?php else: ?>
                            <select name="secuencia_id" class="form-select">
                                <option value="">— Sin secuencia —</option>
                                <?php foreach ($secuencias as $s): ?>
                                <option value="<?= $s['id'] ?>" <?= $secSelId==$s['id']?'selected':'' ?>>
                                    <?= clean($s['nombre']) ?> · <?= date('d/m/Y',strtotime($s['fecha_ini'])) ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <?php else: ?>
                <input type="hidden" name="secuencia_id" value="">
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- TASA Y TOTALES -->
    <div class="col-4">
        <div class="card">
            <div class="card-header"><i class="fa-solid fa-coins text-primary"></i><h5>Tasa y Totales</h5></div>
            <div class="card-body">
                <?php if ($tasa): ?>
                <div style="background:#f0fdf4;border:1px solid #bbf7d0;border-radius:8px;padding:12px;text-align:center;margin-bottom:16px">
                    <div style="font-size:11px;color:var(--text-muted);text-transform:uppercase;letter-spacing:1px">
                        Tasa — <?= date('d/m',strtotime($tasa['fecha'])) ?>
                    </div>
                    <div style="font-size:26px;font-weight:800;color:var(--success)">
                        Bs. <?= number_format($tasa['tasa_bs'],2,',','.') ?>
                    </div>
                    <div style="font-size:11px;color:var(--text-muted)">por 1 USD</div>
                </div>
                <?php else: ?>
                <div class="alert alert-warning"><i class="fa-solid fa-triangle-exclamation"></i> Sin tasa.</div>
                <?php endif; ?>

                <div style="font-size:13px;border-top:1px solid var(--border);padding-top:12px">
                    <div class="d-flex justify-between mb-2">
                        <span class="text-muted">Subtotal USD:</span>
                        <strong id="lblSubtotal">$ 0.00</strong>
                    </div>
                    <div class="d-flex justify-between mb-2">
                        <span class="text-muted">Total bultos:</span>
                        <strong id="lblBultos" style="color:var(--primary)">0.00</strong>
                    </div>
                    <div class="d-flex justify-between mb-2">
                        <span class="text-muted">Total Bs.:</span>
                        <strong id="lblTotalBS" style="color:var(--info)">Bs. 0,00</strong>
                    </div>
                    <div class="d-flex justify-between" style="border-top:2px solid var(--border);padding-top:10px;margin-top:6px">
                        <span style="font-weight:800;font-size:15px">TOTAL USD:</span>
                        <strong id="lblTotal" style="font-size:20px;color:var(--primary)">$ 0.00</strong>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- TABLA DE PRODUCTOS -->
<div class="card mb-4">
    <div class="card-header">
        <i class="fa-solid fa-boxes-stacked text-primary"></i>
        <h5>Productos — Cantidad en unidades <small class="text-muted fw-normal fs-12">(la columna Bulto muestra el equivalente en bultos)</small></h5>
        <button type="button" onclick="agregarFila()" class="btn btn-primary btn-sm" style="margin-left:auto">
            <i class="fa-solid fa-plus"></i> Agregar fila
        </button>
    </div>
    <div class="card-body p-0">
    <div class="table-wrapper">
    <table style="width:100%;border-collapse:collapse;font-size:14px">
        <thead>
            <tr style="background:#f8fafc">
                <th style="padding:10px 8px;font-size:12px;font-weight:700;color:var(--text-muted);text-transform:uppercase;border-bottom:2px solid var(--border);width:28%">Producto</th>
                <th style="padding:10px 8px;font-size:12px;font-weight:700;color:var(--text-muted);text-transform:uppercase;border-bottom:2px solid var(--border);width:10%">Código/REF</th>
                <th style="padding:10px 8px;font-size:12px;font-weight:700;color:var(--text-muted);text-transform:uppercase;border-bottom:2px solid var(--border);width:9%">Precio $ c/u</th>
                <th style="padding:10px 8px;font-size:12px;font-weight:700;color:var(--text-muted);text-transform:uppercase;border-bottom:2px solid var(--border);width:9%">Cantidad</th>
                <th style="padding:10px 8px;font-size:12px;font-weight:700;color:var(--text-muted);text-transform:uppercase;border-bottom:2px solid var(--border);width:11%;text-align:center">Bulto</th>
                <th style="padding:10px 8px;font-size:12px;font-weight:700;color:var(--text-muted);text-transform:uppercase;border-bottom:2px solid var(--border);width:11%">Subtotal $</th>
                <th style="padding:10px 8px;font-size:12px;font-weight:700;color:var(--text-muted);text-transform:uppercase;border-bottom:2px solid var(--border);width:11%">Subtotal Bs.</th>
                <th style="padding:10px 8px;border-bottom:2px solid var(--border);width:4%"></th>
            </tr>
        </thead>
        <tbody id="tbodyDoc"></tbody>
        <tfoot>
            <tr style="background:#f8fafc">
                <td colspan="3" style="padding:10px 8px;font-weight:700;font-size:13px;text-align:right">TOTALES:</td>
                <td id="tTotalCant" style="padding:10px 8px;font-weight:700;text-align:center">0</td>
                <td style="padding:10px 8px;text-align:center">
                    <div id="tTotalBultos" style="font-weight:800;color:var(--primary);font-size:14px">0.00</div>
                    <small class="text-muted" style="font-size:11px">bultos</small>
                </td>
                <td id="tTotalUSD"  style="padding:10px 8px;font-weight:700;color:var(--success)">$ 0.00</td>
                <td id="tTotalBS"   style="padding:10px 8px;font-weight:700;color:var(--info)">Bs. 0,00</td>
                <td></td>
            </tr>
        </tfoot>
    </table>
    </div>
    </div>
    <div class="card-footer">
        <button type="button" onclick="agregarFila()" class="btn btn-light btn-sm">
            <i class="fa-solid fa-plus"></i> Agregar producto
        </button>
    </div>
</div>

<div class="d-flex justify-between align-center">
    <a href="documentos.php" class="btn btn-light btn-lg">
        <i class="fa-solid fa-arrow-left"></i> Cancelar
    </a>
    <button type="submit" class="btn btn-lg" style="background:<?= $color ?>;color:white;min-width:200px">
        <i class="fa-solid <?= $icono ?>"></i>
        <?= $editData ? 'Guardar Cambios' : 'Crear '.$tipoLabels[$tipoDoc] ?>
    </button>
</div>
</form>

<?php
$prodsJS   = json_encode(array_map(fn($p) => [
    'id'        => $p['id'],
    'codigo'    => $p['codigo'],
    'desc'      => $p['descripcion'],
    'unidad'    => $p['unidad'],
    'bulto'     => max(1, (int)($p['bulto'] ?? 1)),
    'stock'     => $p['stock_actual'],
    'mayorista' => (float)($p['precio_mayorista']??0),
    'detalista' => (float)($p['precio_detalista']??0),
], $productos), JSON_UNESCAPED_UNICODE);

$tasaJS    = $tasa ? (float)$tasa['tasa_bs'] : 1;
$editDetsJS = $editData ? json_encode(array_map(fn($d) => [
    'producto_id'=>$d['producto_id'],'descripcion'=>$d['descripcion'],
    'ref'=>$d['ref'],'precio'=>$d['precio_usd'],'cantidad'=>$d['cantidad'],
    'bulto'=>max(1, (int)($d['bulto'] ?? 1))
], $editDets), JSON_UNESCAPED_UNICODE) : '[]';

$extraScript = <<<JS
<script>
const PRODS = $prodsJS;
const TASA  = $tasaJS;
const EDITS = $editDetsJS;
let fila = 0;

const prodOpts = '<option value="">— Buscar producto —</option>' +
    PRODS.map(p => `<option value="\${p.id}" data-desc="\${p.desc}" data-ref="\${p.codigo}" data-stock="\${p.stock}" data-may="\${p.mayorista}" data-det="\${p.detalista}" data-unidad="\${p.unidad}" data-bulto="\${p.bulto}">\${p.codigo} — \${p.desc} (Stock: \${p.stock} \${p.unidad})</option>`).join('');

function getTipo() { return document.getElementById('selTipoCli').value; }

function agregarFila(init) {
    const i = fila++;
    const tr = document.createElement('tr');
    tr.id = 'row_'+i;
    tr.style.borderBottom = '1px solid var(--border)';
    tr.innerHTML = `
        <td style="padding:5px 8px">
            <select style="width:100%;padding:6px;border:1.5px solid var(--border);border-radius:5px;font-size:13.5px">
                \${prodOpts}
            </select>
            <input type="hidden" name="producto_id[]" id="pid_\${i}">
            <input type="hidden" name="descripcion[]" id="desc_\${i}">
        </td>
        <td style="padding:5px 8px"><input type="text" name="ref[]" id="ref_\${i}" class="form-control font-mono" readonly></td>
        <td style="padding:5px 8px"><input type="number" name="precio[]" id="pre_\${i}" class="form-control" value="0" min="0" step="0.01" oninput="calcFila(\${i})"></td>
        <td style="padding:5px 8px">
            <div class="input-group">
                <input type="number" name="cantidad[]" id="can_\${i}" class="form-control" value="" min="0.01" step="0.01" oninput="calcFila(\${i})" placeholder="0">
                <span class="input-group-text" id="unidad_\${i}" style="font-size:12.5px">u.</span>
            </div>
        </td>
        <td style="padding:5px 8px;text-align:center">
            <div id="bultos_\${i}" style="font-weight:800;color:var(--primary);font-size:15px;line-height:1.15">—</div>
            <small id="bultou_\${i}" class="text-muted" style="font-size:11.5px"></small>
            <input type="hidden" name="bulto[]" id="bul_\${i}" value="1">
        </td>
        <td style="padding:5px 8px;font-weight:700;color:var(--success)" id="sub_\${i}">$ 0.00</td>
        <td style="padding:5px 8px;font-weight:700;color:var(--info)" id="subbs_\${i}">Bs. 0,00</td>
        <td style="padding:5px 8px"><button type="button" onclick="elimFila(\${i})" class="btn btn-sm btn-danger btn-icon"><i class="fa-solid fa-xmark"></i></button></td>
    `;
    document.getElementById('tbodyDoc').appendChild(tr);
    const sel = \$('#row_'+i+' select');
    sel.select2({width:'100%'}).on('change', function(){ onSel(this, i); });

    if (init) {
        document.getElementById('pid_'+i).value  = init.producto_id||'';
        document.getElementById('desc_'+i).value = init.descripcion||'';
        document.getElementById('ref_'+i).value  = init.ref||'';
        document.getElementById('pre_'+i).value  = parseFloat(init.precio||0).toFixed(2);
        document.getElementById('can_'+i).value  = init.cantidad||'';
        document.getElementById('bul_'+i).value  = Math.max(1, parseInt(init.bulto||1,10) || 1);
        // Set select value
        if (init.producto_id) {
            sel.val(init.producto_id);
            // Trigger change so onSel() populates fields correctly
            setTimeout(() => sel.trigger('change'), 50);
        }
        calcFila(i);
    }
}

function onSel(sel, i) {
    // Obtener la opción seleccionada de forma robusta (compatible con select2)
    const val = sel.value;
    if (!val) return;
    // Buscar la opción por value (más confiable que selectedIndex con select2)
    const opt = Array.from(sel.options).find(o => o.value === val);
    if (!opt) return;
    const tipo = getTipo();
    const may  = parseFloat(opt.dataset.may||0);
    const det  = parseFloat(opt.dataset.det||0);
    const precio = tipo==='mayorista' ? may : (det > 0 ? det : may);
    document.getElementById('pid_'+i).value    = val;
    document.getElementById('desc_'+i).value   = opt.dataset.desc||opt.text.split(' — ')[1]||'';
    document.getElementById('ref_'+i).value    = opt.dataset.ref||'';
    document.getElementById('pre_'+i).value    = precio.toFixed(2);
    document.getElementById('bul_'+i).value    = Math.max(1, parseInt(opt.dataset.bulto||1, 10) || 1);
    document.getElementById('unidad_'+i).textContent = opt.dataset.unidad||'u.';
    // Resaltar stock bajo
    const stock = parseFloat(opt.dataset.stock||0);
    const row = document.getElementById('row_'+i);
    if (row) row.style.background = stock <= 0 ? '#fff5f5' : '';
    calcFila(i);
}

function calcFila(i) {
    const p = parseFloat(document.getElementById('pre_'+i)?.value||0);
    const c = parseFloat(document.getElementById('can_'+i)?.value||0);
    const s = p*c;
    if(document.getElementById('sub_'+i))   document.getElementById('sub_'+i).textContent   = '$ '+s.toFixed(2);
    if(document.getElementById('subbs_'+i)) document.getElementById('subbs_'+i).textContent = 'Bs. '+(s*TASA).toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g,',');
    actualizarBulto(i);
    calcTotal();
}

/** Muestra los bultos equivalentes a la cantidad (cantidad ÷ unidades por bulto). */
function actualizarBulto(i) {
    const b = Math.max(1, parseInt(document.getElementById('bul_'+i)?.value || 1, 10) || 1);
    const c = parseFloat(document.getElementById('can_'+i)?.value || 0);
    const elN = document.getElementById('bultos_'+i);
    const elU = document.getElementById('bultou_'+i);
    if (elN) elN.textContent = c > 0 ? (c / b).toFixed(2) : '—';
    if (elU) elU.textContent = b > 1 ? (b + ' uds/bulto') : '';
}

function calcTotal() {
    let tot=0, totalCant=0, totalBultos=0;
    document.querySelectorAll('#tbodyDoc tr').forEach(r => {
        const idx = r.id.replace('row_','');
        const p = parseFloat(document.getElementById('pre_'+idx)?.value||0);
        const c = parseFloat(document.getElementById('can_'+idx)?.value||0);
        const b = Math.max(1, parseInt(document.getElementById('bul_'+idx)?.value||1, 10) || 1);
        tot += p*c; totalCant += c;
        if (c > 0) totalBultos += c/b;
    });
    document.getElementById('lblSubtotal').textContent = '$ '+tot.toFixed(2);
    document.getElementById('lblTotal').textContent    = '$ '+tot.toFixed(2);
    document.getElementById('lblTotalBS').textContent  = 'Bs. '+(tot*TASA).toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g,',');
    if(document.getElementById('lblBultos'))     document.getElementById('lblBultos').textContent     = totalBultos.toFixed(2);
    if(document.getElementById('tTotalCant'))    document.getElementById('tTotalCant').textContent    = totalCant.toFixed(2);
    if(document.getElementById('tTotalBultos'))  document.getElementById('tTotalBultos').textContent  = totalBultos.toFixed(2);
    if(document.getElementById('tTotalUSD'))     document.getElementById('tTotalUSD').textContent     = '$ '+tot.toFixed(2);
    if(document.getElementById('tTotalBS'))      document.getElementById('tTotalBS').textContent      = 'Bs. '+(tot*TASA).toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g,',');
}

function elimFila(i) { document.getElementById('row_'+i)?.remove(); calcTotal(); }

function actualizarPrecios() {
    const tipo = getTipo();
    document.querySelectorAll('#tbodyDoc tr').forEach(r => {
        const idx = r.id.replace('row_','');
        const sel = r.querySelector('select');
        if (!sel||!sel.value) return;
        const opt = sel.options[sel.selectedIndex];
        const p = tipo==='mayorista' ? parseFloat(opt.dataset.may||0) : parseFloat(opt.dataset.det||0);
        if(document.getElementById('pre_'+idx)) { document.getElementById('pre_'+idx).value = p.toFixed(2); calcFila(idx); }
    });
}

function toggleVenc() {
    document.getElementById('wrapVenc').style.display =
        document.getElementById('selTipoPago').value==='credito' ? '' : 'none';
}

document.getElementById('selCliente')?.addEventListener('change', function() {
    const opt = this.options[this.selectedIndex];
    if (opt.dataset.tipo) { document.getElementById('selTipoCli').value = opt.dataset.tipo; actualizarPrecios(); }
});

// Cargar datos de edición o iniciar vacío
if (EDITS.length > 0) { EDITS.forEach(d => agregarFila(d)); }
else { agregarFila(); } // Empieza con 1 fila vacía — el usuario agrega más con el botón

// Validar
document.getElementById('formDoc').addEventListener('submit', function(e) {
    let ok=false, sinInventario=0;
    const esTipoVenta = document.querySelector('input[name="tipo_doc"]')?.value === 'venta';
    document.querySelectorAll('#tbodyDoc tr').forEach(r=>{
        const idx=r.id.replace('row_','');
        const cant = parseFloat(document.getElementById('can_'+idx)?.value||0);
        const pid  = document.getElementById('pid_'+idx)?.value||'';
        if(cant>0){ ok=true; if(!pid) sinInventario++; }
    });
    if(!ok){
        e.preventDefault();
        Swal.fire('Sin productos','Agrega al menos un producto con cantidad.','warning');
        return;
    }
    if(esTipoVenta && sinInventario>0){
        e.preventDefault();
        Swal.fire({
            title:'Productos sin vincular',
            html: sinInventario+' línea(s) no tienen producto seleccionado del inventario.<br><br><b>Selecciona el producto desde el buscador</b> para que el stock se descuente correctamente.',
            icon:'warning',
            confirmButtonText:'Entendido, corregir'
        });
        return;
    }
});
</script>
JS;

include 'includes/footer.php';
?>
