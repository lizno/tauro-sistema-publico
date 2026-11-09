<?php
require_once 'config/config.php';
require_once 'functions/db.php';
require_once 'functions/helpers.php';
require_once 'functions/auth.php';

requireLogin();
requirePermission('vendedores','ver');
$pageTitle = 'Estado de cuenta · Vendedores';

/* ── Porcentajes de comisión ──────────────────────────────── */
$comDetal = (float)getConfig('comision_pct_detalista','10.00');
$comMayor = (float)getConfig('comision_pct_mayorista','7.00');

/* ── Filtros ───────────────────────────────────────────────── */
$secuencias = dbQuery("SELECT id,nombre,estado,fecha_ini,fecha_fin FROM secuencias ORDER BY estado='activa' DESC, fecha_ini DESC, id DESC");
$secActiva  = 0;
foreach ($secuencias as $s) { if ($s['estado']==='activa') { $secActiva=(int)$s['id']; break; } }

$secFiltro  = isset($_GET['sec']) ? cleanInt($_GET['sec']) : $secActiva;
$vendFiltro = cleanInt($_GET['vendedor'] ?? 0);

/* ── Resumen general por vendedor ──────────────────────────── */
$fFiltro = '';
$fParams = [];
if ($secFiltro) { $fFiltro = ' AND secuencia_id=?'; $fParams[] = $secFiltro; }

$fFactura = "SELECT vendedor_id,
                    SUM(total_usd) facturado,
                    COUNT(*) facturas,
                    SUM(CASE WHEN tipo_cliente='mayorista' THEN total_usd ELSE 0 END) mayor,
                    SUM(CASE WHEN tipo_cliente='detalista' THEN total_usd ELSE 0 END) detal
             FROM ventas WHERE tipo_doc='venta' AND estado<>'anulado'" . $fFiltro . " GROUP BY vendedor_id";
$fReporte  = 'SELECT vendedor_id, SUM(monto_usd) reportado FROM pagos_vendedor WHERE 1' . $fFiltro . ' GROUP BY vendedor_id';
$fComision = 'SELECT vendedor_id, SUM(monto_usd) pagada FROM comisiones_vendedor WHERE 1' . $fFiltro . ' GROUP BY vendedor_id';

$raw = dbQuery(
    "SELECT vd.id, vd.nombre,
            COALESCE(f.facturado,0) facturado,
            COALESCE(f.facturas,0)  facturas,
            COALESCE(f.mayor,0)     mayor,
            COALESCE(f.detal,0)     detal,
            COALESCE(r.reportado,0) reportado,
            COALESCE(c.pagada,0)    com_pagada
     FROM vendedores vd
     LEFT JOIN ({$fFactura})  f ON f.vendedor_id = vd.id
     LEFT JOIN ({$fReporte})  r ON r.vendedor_id = vd.id
     LEFT JOIN ({$fComision}) c ON c.vendedor_id = vd.id
     WHERE vd.activo = 1",
    array_merge($fParams, $fParams, $fParams)
);

$resumen = [];
foreach ($raw as $r) {
    $mayor = (float)$r['mayor'];
    $detal = (float)$r['detal'];
    $comMay = round($mayor * $comMayor / 100, 2);
    $comDet = round($detal * $comDetal / 100, 2);
    $comTot = $comMay + $comDet;
    $pagada = (float)$r['com_pagada'];

    $r['com_mayor']    = $comMay;
    $r['com_detal']    = $comDet;
    $r['com_total']    = $comTot;
    $r['com_pagada']   = $pagada;
    $r['com_pendiente']= $comTot - $pagada;
    $r['deuda']        = (float)$r['facturado'] - (float)$r['reportado'];
    $resumen[] = $r;
}

usort($resumen, fn($a,$b) => $b['deuda'] <=> $a['deuda']);

/* ── Cascada del vendedor seleccionado ─────────────────────── */
$detalle = [];
$selVendedor = null;
if ($vendFiltro) {
    foreach ($resumen as $r) { if ((int)$r['id'] === $vendFiltro) { $selVendedor = $r; break; } }

    $mov = [];
    $p = $secFiltro ? [$vendFiltro, $secFiltro] : [$vendFiltro];

    foreach (dbQuery(
        "SELECT numero, fecha, total_usd FROM ventas
         WHERE vendedor_id=? AND tipo_doc='venta' AND estado<>'anulado'" .
        ($secFiltro ? ' AND secuencia_id=?' : '') .
        " ORDER BY fecha, id", $p) as $v) {
        $mov[] = ['fecha'=>$v['fecha'], 'concepto'=>'Factura '.$v['numero'], 'cargo'=>(float)$v['total_usd'], 'abono'=>0.0];
    }

    foreach (dbQuery(
        "SELECT fecha, monto_usd, tipo, referencia FROM pagos_vendedor
         WHERE vendedor_id=?" . ($secFiltro ? ' AND secuencia_id=?' : '') .
        " ORDER BY fecha, id", $p) as $v) {
        $mov[] = [
            'fecha'=>$v['fecha'],
            'concepto'=>'Reporte · '.ucfirst($v['tipo']) . ($v['referencia'] ? ' · '.$v['referencia'] : ''),
            'cargo'=>0.0, 'abono'=>(float)$v['monto_usd'],
        ];
    }

    usort($mov, fn($a,$b) => strcmp($a['fecha'], $b['fecha']));
    $saldo = 0.0;
    foreach ($mov as $m) {
        $saldo += $m['cargo'] - $m['abono'];
        $m['saldo'] = $saldo;
        $detalle[] = $m;
    }
}

$totFacturado = array_sum(array_column($resumen,'facturado'));
$totReportado = array_sum(array_column($resumen,'reportado'));
$totDeuda     = array_sum(array_column($resumen,'deuda'));
$totComision  = array_sum(array_column($resumen,'com_total'));
$totComPend   = array_sum(array_column($resumen,'com_pendiente'));

include 'includes/header.php';
?>
<style>
.ev-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:14px;margin-bottom:18px}
@media(max-width:900px){.ev-grid{grid-template-columns:repeat(2,1fr)}}
.ev-card{background:white;border:1px solid var(--border);border-radius:12px;padding:16px 18px}
.ev-card .lbl{font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:var(--text-muted)}
.ev-card .val{font-size:20px;font-weight:900;margin-top:4px}
.ev-row{cursor:pointer}
.ev-row:hover{background:#f0f7ff}
.ev-selected{background:#eef4fb !important;border-left:4px solid var(--primary)}
.neg{color:#c0392b}.pos{color:#059669}
.pill{display:inline-block;padding:2px 8px;border-radius:10px;font-size:10px;font-weight:700}
.pill-ok{background:#d1fae5;color:#065f46}.pill-pend{background:#fff3cd;color:#856404}
</style>

<h4 style="margin-bottom:16px"><i class="fa-solid fa-chart-line" style="color:var(--primary)"></i> Estado de cuenta · Vendedores</h4>

<div class="ev-grid">
    <div class="ev-card"><div class="lbl">Facturado</div><div class="val"><?= formatUSD($totFacturado) ?></div></div>
    <div class="ev-card"><div class="lbl">Comisiones (<?= number_format($comMayor,0) ?>% may · <?= number_format($comDetal,0) ?>% det)</div><div class="val"><?= formatUSD($totComision) ?></div></div>
    <div class="ev-card"><div class="lbl">Comisiones pendientes</div><div class="val" style="color:<?= $totComPend>0?'#c0392b':'#059669' ?>"><?= formatUSD($totComPend) ?></div></div>
    <div class="ev-card"><div class="lbl">Deuda total de vendedores</div><div class="val neg"><?= formatUSD($totDeuda) ?></div></div>
</div>

<!-- Filtros -->
<div class="card mb-3">
    <div class="card-body" style="padding:10px 16px">
        <form method="GET" class="d-flex gap-2 align-center" style="flex-wrap:wrap">
            <select name="sec" class="form-select" style="max-width:240px">
                <option value="0">Todas las secuencias</option>
                <?php foreach($secuencias as $s): ?>
                <option value="<?= (int)$s['id'] ?>" <?= $secFiltro===(int)$s['id']?'selected':'' ?>>
                    <?= clean($s['nombre']) ?><?= $s['estado']==='activa'?' (activa)':'' ?>
                </option>
                <?php endforeach; ?>
            </select>
            <button class="btn btn-primary btn-sm"><i class="fa-solid fa-filter"></i> Filtrar</button>
            <a href="estado_vendedores.php" class="btn btn-light btn-sm"><i class="fa-solid fa-xmark"></i></a>
        </form>
    </div>
</div>

<!-- General -->
<div class="card mb-3">
    <div class="card-header"><i class="fa-solid fa-users"></i><h5>Todos los vendedores</h5></div>
    <div class="card-body p-0">
        <div class="table-wrapper">
            <table class="table" style="font-size:12px">
                <thead>
                    <tr>
                        <th>Vendedor</th>
                        <th class="text-center">Facturas</th>
                        <th class="text-end">Facturado</th>
                        <th class="text-end">Mayorista</th>
                        <th class="text-end">Detalista</th>
                        <th class="text-end">Comisión</th>
                        <th class="text-end">Pagada</th>
                        <th class="text-end">Pendiente</th>
                        <th class="text-end">Reportado</th>
                        <th class="text-end">Deuda</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach($resumen as $r): ?>
                    <tr class="ev-row <?= $vendFiltro===(int)$r['id']?'ev-selected':'' ?>"
                        onclick="location.href='estado_vendedores.php?sec=<?= $secFiltro ?>&vendedor=<?= (int)$r['id'] ?>'">
                        <td><strong><?= clean($r['nombre']) ?></strong></td>
                        <td class="text-center"><?= (int)$r['facturas'] ?></td>
                        <td class="text-end"><?= formatUSD((float)$r['facturado']) ?></td>
                        <td class="text-end"><?= formatUSD((float)$r['mayor']) ?></td>
                        <td class="text-end"><?= formatUSD((float)$r['detal']) ?></td>
                        <td class="text-end"><?= formatUSD($r['com_total']) ?></td>
                        <td class="text-end pos"><?= formatUSD($r['com_pagada']) ?></td>
                        <td class="text-end">
                            <?php if ($r['com_pendiente'] <= 0.001): ?>
                                <span class="pill pill-ok">Pagada</span>
                            <?php else: ?>
                                <span class="pill pill-pend"><?= formatUSD($r['com_pendiente']) ?></span>
                            <?php endif; ?>
                        </td>
                        <td class="text-end pos"><?= formatUSD((float)$r['reportado']) ?></td>
                        <td class="text-end" style="font-weight:800;color:<?= (float)$r['deuda']>0?'#c0392b':'#059669' ?>">
                            <?= formatUSD((float)$r['deuda']) ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr style="background:#f8fafc;font-weight:800">
                        <td>Total</td><td></td>
                        <td class="text-end"><?= formatUSD($totFacturado) ?></td>
                        <td></td><td></td>
                        <td class="text-end"><?= formatUSD($totComision) ?></td>
                        <td></td>
                        <td class="text-end"><?= formatUSD($totComPend) ?></td>
                        <td class="text-end"><?= formatUSD($totReportado) ?></td>
                        <td class="text-end"><?= formatUSD($totDeuda) ?></td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>
</div>

<?php if ($selVendedor): ?>
<!-- Detalle del vendedor -->
<div class="card mb-3">
    <div class="card-header">
        <i class="fa-solid fa-handshake"></i>
        <h5>Resumen de <?= clean($selVendedor['nombre']) ?></h5>
    </div>
    <div class="card-body" style="display:grid;grid-template-columns:repeat(6,1fr);gap:12px">
        <div><div class="text-muted" style="font-size:10px;text-transform:uppercase;font-weight:700">Mayorista</div>
            <div style="font-weight:800"><?= formatUSD((float)$selVendedor['mayor']) ?></div></div>
        <div><div class="text-muted" style="font-size:10px;text-transform:uppercase;font-weight:700">Detalista</div>
            <div style="font-weight:800"><?= formatUSD((float)$selVendedor['detal']) ?></div></div>
        <div><div class="text-muted" style="font-size:10px;text-transform:uppercase;font-weight:700">Comisión total</div>
            <div style="font-weight:800;color:#6d28d9"><?= formatUSD($selVendedor['com_total']) ?></div></div>
        <div><div class="text-muted" style="font-size:10px;text-transform:uppercase;font-weight:700">Comisión pagada</div>
            <div style="font-weight:800;color:#059669"><?= formatUSD($selVendedor['com_pagada']) ?></div></div>
        <div><div class="text-muted" style="font-size:10px;text-transform:uppercase;font-weight:700">Comisión pendiente</div>
            <div style="font-weight:800;color:<?= $selVendedor['com_pendiente']>0?'#c0392b':'#059669' ?>">
                <?= $selVendedor['com_pendiente']<=0.001 ? 'Pagada' : formatUSD($selVendedor['com_pendiente']) ?>
            </div></div>
        <div><div class="text-muted" style="font-size:10px;text-transform:uppercase;font-weight:700">Deuda</div>
            <div style="font-weight:800;color:#c0392b"><?= formatUSD((float)$selVendedor['deuda']) ?></div></div>
    </div>
</div>

<!-- Cascada -->
<div class="card">
    <div class="card-header">
        <i class="fa-solid fa-water"></i>
        <h5>Cómo se va reduciendo la deuda · <?= clean($selVendedor['nombre']) ?></h5>
        <span class="badge" style="margin-left:auto;background:#eef4fb;color:#2980b9">
            Deuda actual: <?= formatUSD((float)$selVendedor['deuda']) ?>
        </span>
    </div>
    <div class="card-body p-0">
        <div class="table-wrapper">
            <table class="table" style="font-size:13px">
                <thead>
                    <tr>
                        <th>Fecha</th><th>Movimiento</th>
                        <th class="text-end">Cargo (factura)</th>
                        <th class="text-end">Abono (reporte)</th>
                        <th class="text-end">Saldo</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($detalle)): ?>
                    <tr><td colspan="5" class="text-center text-muted" style="padding:24px">Este vendedor no tiene movimientos en la secuencia.</td></tr>
                <?php else: foreach($detalle as $d): ?>
                    <tr>
                        <td><?= date('d/m/Y', strtotime($d['fecha'])) ?></td>
                        <td><?= clean($d['concepto']) ?></td>
                        <td class="text-end"><?= $d['cargo'] ? formatUSD($d['cargo']) : '—' ?></td>
                        <td class="text-end pos"><?= $d['abono'] ? formatUSD($d['abono']) : '—' ?></td>
                        <td class="text-end" style="font-weight:800;color:<?= $d['saldo']>0?'#c0392b':'#059669' ?>">
                            <?= formatUSD($d['saldo']) ?>
                        </td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php endif; ?>

<?php include 'includes/footer.php'; ?>
