<?php
require_once 'config/config.php';
require_once 'functions/db.php';
require_once 'functions/helpers.php';
require_once 'functions/auth.php';

requireLogin();
requirePermission('ventas','ver');

$vid = cleanInt($_GET['id'] ?? 0);
if (!$vid) { header('Location: documentos.php'); exit; }

$venta = dbQueryOne(
    "SELECT v.*, c.nombre c_nombre, c.rif c_rif, c.telefono c_tel, c.direccion c_dir,
            vd.nombre v_nombre, u.nombre u_nombre, u.apellido u_apellido,
            tc.tasa_bs
     FROM ventas v
     LEFT JOIN clientes c  ON v.cliente_id  = c.id
     LEFT JOIN vendedores vd ON v.vendedor_id = vd.id
     LEFT JOIN usuarios u  ON v.usuario_id  = u.id
     LEFT JOIN tasas_cambio tc ON v.tasa_cambio_id = tc.id
     WHERE v.id = ?", [$vid]
);
if (!$venta) { header('Location: documentos.php'); exit; }

$detalles = dbQuery(
    "SELECT vd.*, p.codigo FROM venta_detalles vd
     LEFT JOIN productos p ON vd.producto_id=p.id
     WHERE vd.venta_id=? ORDER BY vd.id", [$vid]
);
$pagos = dbQuery(
    "SELECT p.*, u.nombre u_nombre FROM pagos p
     LEFT JOIN usuarios u ON p.usuario_id=u.id
     WHERE p.venta_id=? ORDER BY p.fecha DESC", [$vid]
);
$historial = dbQuery(
    "SELECT dh.*, u.nombre u_nombre FROM documento_historial dh
     LEFT JOIN usuarios u ON dh.usuario_id=u.id
     WHERE dh.venta_id=? ORDER BY dh.fecha DESC", [$vid]
);

// ── CONVERTIR DOCUMENTO ──────────────────────────────────────
if ($_SERVER['REQUEST_METHOD']==='POST' && !empty($_POST['convertir_doc'])) {
    $token = $_POST[CSRF_TOKEN_NAME] ?? '';
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $token)) {
        $_SESSION['flash'] = ['type'=>'error','msg'=>'Token inválido. Recarga la página e intenta de nuevo.'];
        header('Location: ver_venta.php?id='.$vid); exit;
    }
    $nuevoTipo = clean($_POST['nuevo_tipo'] ?? '');
    $result    = convertirDocumento($vid, $nuevoTipo, $_SESSION['user_id']);
    $_SESSION['flash'] = ['type' => $result['ok'] ? 'success' : 'error', 'msg' => $result['msg']];
    header('Location: ver_venta.php?id='.$vid); exit;
}

// Registrar pago (viene de modal en esta misma página)
if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action']??'')==='registrar_pago') {
    $token = $_POST[CSRF_TOKEN_NAME] ?? '';
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $token)) {
        $_SESSION['flash'] = ['type'=>'error','msg'=>'Token inválido. Recarga la página e intenta de nuevo.'];
        header('Location: ver_venta.php?id='.$vid); exit;
    }
    requirePermission('pagos','crear');
    $moneda   = in_array($_POST['moneda']??'',['USD','BS']) ? $_POST['moneda'] : 'USD';
    $tipo     = clean($_POST['tipo'] ?? 'efectivo');
    $ref      = clean($_POST['referencia'] ?? '');
    $fechaPago= $_POST['fecha_pago'] ?? date('Y-m-d');
    $notas    = clean($_POST['notas'] ?? '');
    $montoUSD = cleanFloat($_POST['monto_usd'] ?? 0);
    $montoBS  = cleanFloat($_POST['monto_bs'] ?? 0);

    if ($moneda==='BS' && $montoBS > 0) {
        $tasaVal  = $venta['tasa_valor'] ?? 1;
        $montoUSD = $tasaVal > 0 ? round($montoBS / $tasaVal, 4) : 0;
    }
    if ($montoUSD > 0.001) {
        $saldo = $venta['total_usd'] - $venta['monto_pagado_usd'];
        $montoUSD = min($montoUSD, $saldo);
        dbExecute(
            "INSERT INTO pagos (venta_id,monto_usd,monto_bs,moneda,tipo,referencia,fecha,notas,usuario_id)
             VALUES (?,?,?,?,?,?,?,?,?)",
            [$vid,$montoUSD,$montoBS,$moneda,$tipo,$ref,$fechaPago,$notas,$_SESSION['user_id']]
        );
        $nuevoPagado = $venta['monto_pagado_usd'] + $montoUSD;
        $nuevoEstado = ($nuevoPagado >= $venta['total_usd'] - 0.01) ? 'pagado' : 'parcial';
        dbExecute("UPDATE ventas SET monto_pagado_usd=?,estado=? WHERE id=?",[$nuevoPagado,$nuevoEstado,$vid]);
        actualizarSaldoCliente($venta['cliente_id']);
        auditLog('INSERT','pagos',(int)dbLastId(),"Pago registrado: {$tipo} \${$montoUSD} → Doc {$venta['numero']}");
        $_SESSION['flash'] = ['type'=>'success','msg'=>'Pago de '.formatUSD($montoUSD).' registrado.'];
    }
    header("Location: ver_venta.php?id={$vid}"); exit;
}

$pageTitle = $venta['numero'];
$tipoLabels= ['cotizacion'=>'Cotización','despacho'=>'Despacho','venta'=>'Venta / Factura'];
$tipoLabel = $tipoLabels[$venta['tipo_doc']] ?? ucfirst($venta['tipo_doc']);
$saldo     = $venta['total_usd'] - $venta['monto_pagado_usd'];

include 'includes/header.php';
?>

<!-- TOOLBAR -->
<div class="d-flex justify-between align-center mb-4 no-print">
    <a href="documentos.php" class="btn btn-light">
        <i class="fa-solid fa-arrow-left"></i> Volver
    </a>
    <div class="d-flex gap-2">
        <!-- Si es cotización, permitir editar -->
        <?php if ($venta['tipo_doc']==='cotizacion' && $venta['estado']!=='anulado' && hasPermission('ventas','crear')): ?>
        <a href="nueva_venta.php?edit=<?= $vid ?>" class="btn btn-warning">
            <i class="fa-solid fa-pen"></i> Editar Cotización
        </a>
        <?php endif; ?>
        <!-- Convertir documento: cotizacion->despacho/factura, despacho->factura -->
        <?php if (in_array($venta['tipo_doc'],['cotizacion','despacho']) && $venta['estado']!=='anulado' && hasPermission('ventas','crear')): ?>
        <button onclick="openModal('modalConvertirVenta')" class="btn btn-primary">
            <i class="fa-solid fa-arrow-right-arrow-left"></i>
            <?= $venta['tipo_doc']==='cotizacion' ? 'Convertir' : 'Convertir a Factura' ?>
        </button>
        <?php endif; ?>
        <!-- Convertir documento -->
        <?php if (in_array($venta['tipo_doc'],['cotizacion','despacho']) && $venta['estado']!=='anulado' && hasPermission('ventas','crear')): ?>
        <button onclick="openModal('modalConvertir')" class="btn btn-primary">
            <i class="fa-solid fa-arrow-right-arrow-left"></i>
            <?= $venta['tipo_doc']==='cotizacion' ? 'Convertir a Despacho / Factura' : 'Convertir a Factura' ?>
        </button>
        <?php endif; ?>

        <!-- Imprimir en USD o BS -->
        <div style="position:relative">
            <button class="btn btn-light" onclick="toggleDD()" id="btnPrintMenu">
                <i class="fa-solid fa-print"></i> Imprimir
            </button>
            <div id="ddPrint" style="display:none;position:absolute;right:0;top:100%;background:white;border:1px solid var(--border);border-radius:8px;padding:10px;z-index:200;min-width:240px;box-shadow:0 4px 16px rgba(0,0,0,.12)">
                <div style="font-size:10px;font-weight:700;color:var(--text-muted);text-transform:uppercase;padding:2px 6px 8px;border-bottom:1px solid var(--border);margin-bottom:8px">
                    Seleccionar moneda y plantilla
                </div>
                <!-- En USD -->
                <div style="font-size:11px;font-weight:700;color:var(--info);margin-bottom:4px;padding:0 4px">
                    <i class="fa-solid fa-dollar-sign"></i> En Dólares (USD)
                </div>
                <a href="imprimir.php?id=<?= $vid ?>&template=<?= $venta['tipo_doc'] ?>&moneda=USD" target="_blank" class="btn btn-light btn-sm w-100" style="margin-bottom:3px;justify-content:flex-start"><i class="fa-solid fa-file-invoice-dollar" style="color:var(--info)"></i> Imprimir en USD</a>
                <!-- En BS -->
                <div style="font-size:11px;font-weight:700;color:var(--success);margin:8px 0 4px;padding:0 4px">
                    <i class="fa-solid fa-b"></i>s. En Bolívares
                </div>
                <a href="imprimir.php?id=<?= $vid ?>&template=<?= $venta['tipo_doc'] ?>&moneda=BS" target="_blank" class="btn btn-light btn-sm w-100" style="margin-bottom:8px;justify-content:flex-start"><i class="fa-solid fa-file-invoice-dollar" style="color:var(--success)"></i> Imprimir en Bs.S</a>
                <div style="border-top:1px solid var(--border);padding-top:8px;font-size:10px;font-weight:700;color:var(--text-muted);text-transform:uppercase;margin-bottom:5px">Cambiar plantilla</div>
                <div class="d-flex gap-1">
                    <a href="imprimir.php?id=<?= $vid ?>&template=cotizacion&moneda=USD" target="_blank" class="btn btn-light btn-sm" style="flex:1;font-size:11px"><i class="fa-solid fa-file-pen"></i> Cotiz.</a>
                    <a href="imprimir.php?id=<?= $vid ?>&template=despacho&moneda=USD"   target="_blank" class="btn btn-light btn-sm" style="flex:1;font-size:11px"><i class="fa-solid fa-truck"></i> Desp.</a>
                    <a href="imprimir.php?id=<?= $vid ?>&template=venta&moneda=USD"       target="_blank" class="btn btn-light btn-sm" style="flex:1;font-size:11px"><i class="fa-solid fa-file-invoice-dollar"></i> Fact.</a>
                </div>
            </div>
        </div>

        <?php if (in_array($venta['estado'],['pendiente','parcial']) && $venta['tipo_doc']!=='cotizacion' && hasPermission('pagos','crear')): ?>
        <button onclick="openModal('modalPago')" class="btn btn-success">
            <i class="fa-solid fa-money-bill-wave"></i> Registrar Pago
        </button>
        <?php endif; ?>
    </div>
</div>

<div class="row">
    <div class="col-8">
        <!-- CABECERA DEL DOCUMENTO -->
        <div class="card mb-4">
            <div class="card-body" style="padding:24px">
                <div style="display:flex;justify-content:space-between;align-items:flex-start;padding-bottom:18px;border-bottom:2px solid var(--primary);margin-bottom:20px">
                    <div>
                        <div style="font-size:20px;font-weight:800;color:var(--primary)"><?= clean(getConfig('empresa_nombre', APP_NAME)) ?></div>
                        <div style="font-size:12px;color:var(--text-muted);margin-top:2px">
                            <?php if ($rif = getConfig('empresa_rif','')): ?>RIF: <?= clean($rif) ?><br><?php endif; ?>
                            <?= clean(getConfig('empresa_direccion','')) ?>
                        </div>
                    </div>
                    <div style="text-align:right">
                        <div style="font-size:11px;color:var(--text-muted);font-weight:700;text-transform:uppercase;letter-spacing:1px"><?= $tipoLabel ?></div>
                        <div style="font-size:28px;font-weight:900;color:var(--primary)"><?= clean($venta['numero']) ?></div>
                        <div style="margin-top:6px"><?= badgeDoc($venta['tipo_doc']) ?> <?= badgeEstado($venta['estado']) ?></div>
                    </div>
                </div>

                <!-- INFO GRID -->
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:20px">
                    <div style="background:#f8fafc;border:1px solid var(--border);border-radius:8px;padding:12px">
                        <div style="font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:1px;color:var(--text-muted);border-bottom:1px solid var(--border);padding-bottom:6px;margin-bottom:8px">Datos del Cliente</div>
                        <div style="font-size:13px">
                            <div class="d-flex justify-between mb-1"><span class="text-muted">Nombre:</span><strong><?= clean($venta['c_nombre']) ?></strong></div>
                            <?php if ($venta['c_rif']): ?>
                            <div class="d-flex justify-between mb-1"><span class="text-muted">RIF/CI:</span><strong><?= clean($venta['c_rif']) ?></strong></div>
                            <?php endif; ?>
                            <?php if ($venta['c_tel']): ?>
                            <div class="d-flex justify-between mb-1"><span class="text-muted">Teléfono:</span><strong><?= clean($venta['c_tel']) ?></strong></div>
                            <?php endif; ?>
                            <?php if ($venta['c_dir']): ?>
                            <div class="d-flex justify-between"><span class="text-muted">Dirección:</span><strong style="max-width:60%;text-align:right"><?= clean($venta['c_dir']) ?></strong></div>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div style="background:#f8fafc;border:1px solid var(--border);border-radius:8px;padding:12px">
                        <div style="font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:1px;color:var(--text-muted);border-bottom:1px solid var(--border);padding-bottom:6px;margin-bottom:8px">Datos del Documento</div>
                        <div style="font-size:13px">
                            <div class="d-flex justify-between mb-1"><span class="text-muted">Vendedor:</span><strong><?= clean($venta['v_nombre']??'N/A') ?></strong></div>
                            <div class="d-flex justify-between mb-1"><span class="text-muted">Tipo cliente:</span><?= badgeTipo($venta['tipo_cliente']) ?></div>
                            <div class="d-flex justify-between mb-1"><span class="text-muted">Pago:</span><strong><?= ucfirst($venta['tipo_pago']) ?></strong></div>
                            <div class="d-flex justify-between mb-1"><span class="text-muted">Fecha:</span><strong><?= date('d/m/Y',strtotime($venta['fecha'])) ?></strong></div>
                            <?php if ($venta['fecha_vencimiento']): ?>
                            <div class="d-flex justify-between mb-1"><span class="text-muted">Vence:</span><strong><?= date('d/m/Y',strtotime($venta['fecha_vencimiento'])) ?></strong></div>
                            <?php endif; ?>
                            <div class="d-flex justify-between"><span class="text-muted">Tasa:</span><strong>Bs. <?= number_format($venta['tasa_bs']??$venta['tasa_valor']??0,2) ?> / $</strong></div>
                        </div>
                    </div>
                </div>

                <!-- TABLA DE PRODUCTOS -->
                <table class="table" id="tablaDetalle">
                    <thead>
                        <tr>
                            <th>#</th><th>REF</th><th>Descripción</th>
                            <th class="text-center">Bulto</th>
                            <th class="text-right">Precio $</th>
                            <th class="text-right">Cantidad</th>
                            <th class="text-right">Monto $</th>
                            <th class="text-right">Monto Bs.</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($detalles as $i => $d):
                        $tasaVal = $venta['tasa_bs'] ?? $venta['tasa_valor'] ?? 1;
                        $montoBs = $d['monto_usd'] * $tasaVal;
                    ?>
                    <tr>
                        <td><?= $i+1 ?></td>
                        <td><strong class="font-mono fs-11"><?= clean($d['ref']??$d['codigo']??'') ?></strong></td>
                        <td><?= clean($d['descripcion']) ?></td>
                        <td class="text-center"><?= $d['bulto'] ?></td>
                        <td class="text-right">$ <?= formatPrecio($d['precio_usd']) ?></td>
                        <td class="text-right fw-bold"><?= number_format($d['cantidad'],2) ?></td>
                        <td class="text-right fw-bold text-success">$ <?= formatPrecio($d['monto_usd']) ?></td>
                        <td class="text-right" style="color:var(--info)">Bs. <?= number_format($montoBs,2,',','.') ?></td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <tr style="background:#f8fafc">
                            <td colspan="5"></td>
                            <td class="text-right fw-bold fs-12">TOTAL:</td>
                            <td class="text-right fw-bold" style="font-size:16px;color:var(--primary)">$ <?= formatPrecio($venta['total_usd']) ?></td>
                            <td class="text-right fw-bold" style="color:var(--info)">Bs. <?= number_format($venta['total_bs'],2,',','.') ?></td>
                        </tr>
                        <?php if ($venta['tipo_doc']!=='cotizacion'): ?>
                        <tr>
                            <td colspan="5"></td>
                            <td class="text-right fs-12 text-muted">Pagado:</td>
                            <td class="text-right fw-bold text-success">$ <?= formatPrecio($venta['monto_pagado_usd']) ?></td>
                            <td></td>
                        </tr>
                        <tr>
                            <td colspan="5"></td>
                            <td class="text-right fw-bold fs-12">SALDO:</td>
                            <td class="text-right fw-bold <?= $saldo>0.01?'text-danger':'text-success' ?>" style="font-size:15px">
                                $ <?= formatPrecio($saldo) ?>
                            </td>
                            <td></td>
                        </tr>
                        <?php endif; ?>
                    </tfoot>
                </table>

                <?php if ($venta['notas']): ?>
                <div style="background:#fffbeb;border:1px solid #fde68a;border-radius:6px;padding:10px 14px;margin-top:12px;font-size:12px">
                    <i class="fa-solid fa-note-sticky" style="color:var(--warning)"></i>
                    <strong>Observaciones:</strong> <?= clean($venta['notas']) ?>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- HISTORIAL DE PAGOS -->
        <?php if (!empty($pagos)): ?>
        <div class="card mb-4">
            <div class="card-header">
                <i class="fa-solid fa-receipt text-primary"></i>
                <h5>Historial de Pagos</h5>
            </div>
            <div class="card-body p-0">
                <table class="table" style="font-size:12.5px">
                    <thead><tr>
                        <th>Fecha</th><th>Tipo</th><th>Moneda</th>
                        <th class="text-right">Monto USD</th><th class="text-right">Monto Bs.</th>
                        <th>Referencia</th><th>Registrado por</th>
                    </tr></thead>
                    <tbody>
                    <?php foreach ($pagos as $p): ?>
                    <tr>
                        <td><?= date('d/m/Y',strtotime($p['fecha'])) ?></td>
                        <td><span class="badge badge-success" style="font-size:10px"><?= ucfirst($p['tipo']) ?></span></td>
                        <td><?= $p['moneda'] ?></td>
                        <td class="text-right fw-bold text-success"><?= $p['monto_usd'] ? '$ '.formatPrecio($p['monto_usd']) : '—' ?></td>
                        <td class="text-right" style="color:var(--info)"><?= $p['monto_bs'] ? 'Bs. '.number_format($p['monto_bs'],2,',','.') : '—' ?></td>
                        <td class="fs-12 text-muted"><?= clean($p['referencia']??'—') ?></td>
                        <td class="fs-12 text-muted"><?= clean($p['u_nombre']??'—') ?></td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>

        <!-- HISTORIAL DE ESTADOS -->
        <?php if (!empty($historial)): ?>
        <div class="card">
            <div class="card-header">
                <i class="fa-solid fa-timeline text-primary"></i>
                <h5>Historial del Documento</h5>
            </div>
            <div class="card-body" style="font-size:12.5px">
                <?php foreach ($historial as $h): ?>
                <div style="display:flex;gap:12px;align-items:flex-start;padding:8px 0;border-bottom:1px solid var(--border)">
                    <div style="width:32px;height:32px;border-radius:50%;background:var(--primary);display:flex;align-items:center;justify-content:center;flex-shrink:0">
                        <i class="fa-solid fa-arrow-right" style="color:white;font-size:12px"></i>
                    </div>
                    <div>
                        <div class="fw-bold"><?= clean($h['nota']??'Cambio de estado') ?></div>
                        <div class="text-muted">
                            <?= ucfirst($h['tipo_desde']??'') ?> → <?= ucfirst($h['tipo_hasta']??'') ?>
                            · <?= clean($h['u_nombre']??'Sistema') ?>
                            · <?= date('d/m/Y H:i',strtotime($h['fecha'])) ?>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <!-- PANEL LATERAL -->
    <div class="col-4">
        <!-- Resumen financiero -->
        <div class="card mb-3">
            <div class="card-header"><i class="fa-solid fa-calculator text-primary"></i><h5>Resumen Financiero</h5></div>
            <div class="card-body">
                <div style="font-size:13px">
                    <div class="d-flex justify-between mb-2"><span class="text-muted">Total USD:</span><strong>$ <?= formatPrecio($venta['total_usd']) ?></strong></div>
                    <div class="d-flex justify-between mb-2"><span class="text-muted">Total Bs.:</span><strong style="color:var(--info)">Bs. <?= number_format($venta['total_bs'],2,',','.') ?></strong></div>
                    <?php if ($venta['tipo_doc']!=='cotizacion'): ?>
                    <div class="d-flex justify-between mb-2"><span class="text-muted">Pagado:</span><strong class="text-success">$ <?= formatPrecio($venta['monto_pagado_usd']) ?></strong></div>
                    <div style="border-top:2px solid var(--border);padding-top:10px;margin-top:6px" class="d-flex justify-between">
                        <span class="fw-bold" style="font-size:15px">Saldo:</span>
                        <strong class="<?= $saldo>0.01?'text-danger':'text-success' ?>" style="font-size:17px">$ <?= formatPrecio($saldo) ?></strong>
                    </div>
                    <?php endif; ?>
                </div>
                <?php if ($saldo > 0.01 && $venta['tipo_doc']!=='cotizacion'): ?>
                <div style="margin-top:14px">
                    <div style="height:6px;background:var(--border);border-radius:3px;overflow:hidden">
                        <?php $pct = min(100, ($venta['monto_pagado_usd']/$venta['total_usd'])*100); ?>
                        <div style="width:<?= $pct ?>%;height:100%;background:var(--success);border-radius:3px;transition:width .5s"></div>
                    </div>
                    <div class="d-flex justify-between mt-1 fs-11 text-muted">
                        <span><?= number_format($pct,1) ?>% pagado</span>
                        <span><?= number_format(100-$pct,1) ?>% pendiente</span>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Info del documento -->
        <div class="card mb-3">
            <div class="card-header"><i class="fa-solid fa-circle-info text-primary"></i><h5>Información</h5></div>
            <div class="card-body" style="font-size:12.5px">
                <div class="d-flex justify-between mb-2"><span class="text-muted">N° Documento:</span><strong class="font-mono"><?= clean($venta['numero']) ?></strong></div>
                <div class="d-flex justify-between mb-2"><span class="text-muted">Tipo:</span><?= badgeDoc($venta['tipo_doc']) ?></div>
                <div class="d-flex justify-between mb-2"><span class="text-muted">Estado:</span><?= badgeEstado($venta['estado']) ?></div>
                <div class="d-flex justify-between mb-2"><span class="text-muted">Tipo cliente:</span><?= badgeTipo($venta['tipo_cliente']) ?></div>
                <div class="d-flex justify-between mb-2"><span class="text-muted">Fecha:</span><strong><?= date('d/m/Y',strtotime($venta['fecha'])) ?></strong></div>
                <div class="d-flex justify-between mb-2"><span class="text-muted">Creado por:</span><strong><?= clean($venta['u_nombre'].' '.$venta['u_apellido']) ?></strong></div>
                <div class="d-flex justify-between"><span class="text-muted">Tasa cambio:</span><strong>Bs. <?= number_format($venta['tasa_bs']??$venta['tasa_valor']??0,2) ?> / $</strong></div>
            </div>
        </div>

        <!-- Acciones del documento -->
        <div class="card">
            <div class="card-header"><i class="fa-solid fa-gears text-primary"></i><h5>Acciones</h5></div>
            <div class="card-body">
                <div class="d-flex gap-2" style="flex-direction:column">
                    <?php foreach (['cotizacion'=>['fa-file-pen','Imprimir como Cotización'],'despacho'=>['fa-truck','Imprimir como Despacho'],'venta'=>['fa-file-invoice-dollar','Imprimir como Factura']] as $t=>[$icon,$label]): ?>
                    <a href="imprimir.php?id=<?= $vid ?>&template=<?= $t ?>" target="_blank" class="btn btn-light btn-sm">
                        <i class="fa-solid <?= $icon ?>"></i> <?= $label ?>
                    </a>
                    <?php endforeach; ?>

                    <?php if ($venta['tipo_doc']==='cotizacion' && $venta['estado']!=='anulado' && hasPermission('ventas','crear')): ?>
                    <hr class="divider">
                    <a href="nueva_venta.php?edit=<?= $vid ?>" class="btn btn-warning btn-sm">
                        <i class="fa-solid fa-pen"></i> Editar Cotización
                    </a>
                    <?php endif; ?>

                    <?php if ($venta['estado']!=='anulado' && hasPermission('ventas','anular')): ?>
                    <a href="documentos.php?anular=<?= $vid ?>&<?= CSRF_TOKEN_NAME ?>=<?= getCsrfToken() ?>"
                       class="btn btn-danger btn-sm btn-delete"
                       data-name="<?= clean($venta['numero']) ?>">
                        <i class="fa-solid fa-ban"></i> Anular Documento
                    </a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- MODAL: Registrar Pago -->
<div class="modal-overlay" id="modalPago">
    <div class="modal-box" style="max-width:480px">
        <div class="modal-header">
            <h4><i class="fa-solid fa-money-bill-wave"></i> Registrar Pago — <?= clean($venta['numero']) ?></h4>
            <button class="btn-close" onclick="closeModal('modalPago')"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <form method="POST">
            <div class="modal-body">
                <input type="hidden" name="<?= CSRF_TOKEN_NAME ?>" value="<?= getCsrfToken() ?>">
                <input type="hidden" name="action" value="registrar_pago">

                <div style="background:#f8fafc;border-radius:8px;padding:12px;margin-bottom:16px;font-size:13px">
                    <div class="d-flex justify-between mb-1"><span>Total:</span><strong>$ <?= formatPrecio($venta['total_usd']) ?></strong></div>
                    <div class="d-flex justify-between mb-1"><span>Pagado:</span><strong class="text-success">$ <?= formatPrecio($venta['monto_pagado_usd']) ?></strong></div>
                    <div class="d-flex justify-between" style="border-top:1px solid var(--border);padding-top:8px;margin-top:6px">
                        <span class="fw-bold">Saldo pendiente:</span>
                        <strong class="text-danger" style="font-size:15px">$ <?= formatPrecio($saldo) ?></strong>
                    </div>
                </div>

                <div class="row">
                    <div class="col-6">
                        <div class="form-group">
                            <label class="form-label">Tipo de Pago *</label>
                            <select name="tipo" class="form-select" required>
                                <option value="efectivo">Efectivo</option>
                                <option value="transferencia">Transferencia</option>
                                <option value="punto">Punto</option>
                                <option value="zelle">Zelle</option>
                                <option value="otro">Otro</option>
                            </select>
                        </div>
                    </div>
                    <div class="col-6">
                        <div class="form-group">
                            <label class="form-label">Moneda *</label>
                            <select name="moneda" id="selMonedaPago" class="form-select" onchange="toggleMoneda()">
                                <option value="USD">USD</option>
                                <option value="BS">Bs.s</option>
                            </select>
                        </div>
                    </div>
                </div>
                <div class="row">
                    <div class="col-6" id="wrapUSD">
                        <div class="form-group">
                            <label class="form-label">Monto USD</label>
                            <div class="input-group">
                                <span class="input-group-text">$</span>
                                <input type="number" name="monto_usd" class="form-control" step="0.01" min="0" max="<?= round($saldo,2) ?>" placeholder="0.00">
                            </div>
                        </div>
                    </div>
                    <div class="col-6" id="wrapBS" style="display:none">
                        <div class="form-group">
                            <label class="form-label">Monto Bs.s</label>
                            <div class="input-group">
                                <span class="input-group-text">Bs.</span>
                                <input type="number" name="monto_bs" class="form-control" step="0.01" min="0" placeholder="0.00">
                            </div>
                        </div>
                    </div>
                </div>
                <div class="row">
                    <div class="col-6">
                        <div class="form-group">
                            <label class="form-label">Referencia</label>
                            <input type="text" name="referencia" class="form-control" placeholder="Opcional">
                        </div>
                    </div>
                    <div class="col-6">
                        <div class="form-group">
                            <label class="form-label">Fecha del pago</label>
                            <input type="date" name="fecha_pago" class="form-control" value="<?= date('Y-m-d') ?>" required>
                        </div>
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label">Notas</label>
                    <textarea name="notas" class="form-control" rows="2" placeholder="Opcional..."></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" onclick="closeModal('modalPago')" class="btn btn-light">Cancelar</button>
                <button type="submit" class="btn btn-success">
                    <i class="fa-solid fa-check"></i> Registrar Pago
                </button>
            </div>
        </form>
    </div>
</div>

<?php
$extraScript = "
<script>
function toggleMoneda() {
    const m = document.getElementById('selMonedaPago').value;
    document.getElementById('wrapUSD').style.display = m==='USD' ? '' : 'none';
    document.getElementById('wrapBS').style.display  = m==='BS'  ? '' : 'none';
}
// Cerrar dropdown print al hacer clic fuera
document.addEventListener('click', e => {
    if (!e.target.closest('#btnPrintMenu') && !e.target.closest('#ddPrint')) {
        const dd = document.getElementById('ddPrint');
        if (dd) dd.classList.remove('show');
        if (dd) dd.style.display='none';
    }
});
function toggleDD() {
    const dd = document.getElementById('ddPrint');
    dd.style.display = dd.style.display==='none'||dd.style.display==='' ? 'block' : 'none';
}
</script>";
?>
<?php if (in_array($venta['tipo_doc'],['cotizacion','despacho']) && $venta['estado']!=='anulado'): ?>
<!-- Modal Convertir Documento -->
<div class="modal-overlay" id="modalConvertirVenta">
<div class="modal-box" style="max-width:440px">
    <div class="modal-header">
        <h4><i class="fa-solid fa-arrow-right-arrow-left"></i> Convertir Documento</h4>
        <button class="btn-close" onclick="closeModal('modalConvertirVenta')"><i class="fa-solid fa-xmark"></i></button>
    </div>
    <form method="POST">
    <div class="modal-body">
        <input type="hidden" name="<?= CSRF_TOKEN_NAME ?>" value="<?= getCsrfToken() ?>">
        <input type="hidden" name="convertir_doc" value="1">

        <div style="margin-bottom:14px">
            Documento: <strong><?= clean($venta['numero']) ?></strong>
            <?= badgeDoc($venta['tipo_doc']) ?>
        </div>

        <?php if ($venta['tipo_doc']==='cotizacion'): ?>
        <div class="alert alert-warning" style="margin-bottom:14px">
            <i class="fa-solid fa-triangle-exclamation"></i>
            Puedes convertir a <b>Despacho</b> (sin afectar stock) o directamente a <b>Factura</b> (descuenta inventario).
        </div>
        <div class="d-flex gap-2">
            <label style="cursor:pointer;padding:14px;border:2px solid var(--border);border-radius:8px;flex:1;text-align:center;transition:.15s"
                   onclick="this.style.borderColor='var(--info)';document.querySelectorAll('.conv-opt').forEach(e=>e.style.borderColor='var(--border)');this.style.borderColor='var(--info)'">
                <input type="radio" name="nuevo_tipo" value="despacho" required style="display:none" class="conv-radio">
                <i class="fa-solid fa-truck" style="font-size:26px;color:var(--info)"></i>
                <div class="fw-bold" style="margin-top:6px">Despacho</div>
                <div style="font-size:11px;color:var(--text-muted)">Sin afectar stock</div>
            </label>
            <label style="cursor:pointer;padding:14px;border:2px solid var(--border);border-radius:8px;flex:1;text-align:center;transition:.15s"
                   onclick="this.style.borderColor='var(--success)';document.querySelectorAll('.conv-opt').forEach(e=>e.style.borderColor='var(--border)');this.style.borderColor='var(--success)'">
                <input type="radio" name="nuevo_tipo" value="venta" style="display:none" class="conv-radio">
                <i class="fa-solid fa-file-invoice-dollar" style="font-size:26px;color:var(--success)"></i>
                <div class="fw-bold" style="margin-top:6px">Factura</div>
                <div style="font-size:11px;color:var(--text-muted)">Descuenta inventario</div>
            </label>
        </div>
        <?php else: /* despacho */ ?>
        <div class="alert alert-warning" style="margin-bottom:14px">
            <i class="fa-solid fa-triangle-exclamation"></i>
            Al convertir a <b>Factura</b> se descontará el inventario. Esta acción no se puede revertir.
        </div>
        <input type="hidden" name="nuevo_tipo" value="venta">
        <div style="text-align:center;padding:16px;border:2px solid var(--success);border-radius:8px;background:#f0fdf4">
            <i class="fa-solid fa-file-invoice-dollar" style="font-size:30px;color:var(--success)"></i>
            <div class="fw-bold" style="margin-top:6px;font-size:15px">Convertir a Factura</div>
            <div style="font-size:12px;color:var(--text-muted)">El inventario será descontado automáticamente</div>
        </div>
        <?php endif; ?>
    </div>
    <div class="modal-footer">
        <button type="button" onclick="closeModal('modalConvertirVenta')" class="btn btn-light">Cancelar</button>
        <button type="submit" class="btn btn-primary" onclick="return confirmarConversion(this)">
            <i class="fa-solid fa-check"></i> Confirmar Conversión
        </button>
    </div>
    </form>
</div>
</div>
<script>
function confirmarConversion(btn) {
    var radios = document.querySelectorAll('.conv-radio');
    if (radios.length > 0) {
        var checked = Array.from(radios).some(r => r.checked);
        if (!checked) { alert('Selecciona el tipo de documento destino.'); return false; }
    }
    return confirm('¿Confirmas la conversión del documento? Esta acción no se puede revertir.');
}
// Highlight selected option
document.querySelectorAll('.conv-radio').forEach(function(radio) {
    radio.addEventListener('change', function() {
        document.querySelectorAll('.conv-radio').forEach(r => {
            r.closest('label').style.borderColor = 'var(--border)';
        });
        this.closest('label').style.borderColor = this.value === 'despacho' ? 'var(--info)' : 'var(--success)';
    });
});
</script>
<?php endif; ?>

<?php include 'includes/footer.php'; ?>
