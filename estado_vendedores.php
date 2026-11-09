<?php
require_once 'config/config.php';
require_once 'functions/db.php';
require_once 'functions/helpers.php';
require_once 'functions/auth.php';

requireLogin();
requirePermission('vendedores','ver');
$pageTitle = 'Estado de cuenta Â· Vendedores';

/* â”€â”€ Filtros â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€ */
$secuencias = dbQuery("SELECT id,nombre,estado,fecha_ini,fecha_fin FROM secuencias ORDER BY estado='activa' DESC, fecha_ini DESC, id DESC");
$secActiva  = 0;
foreach ($secuencias as $s) { if ($s['estado']==='activa') { $secActiva=(int)$s['id']; break; } }

$secFiltro = isset($_GET['sec']) ? cleanInt($_GET['sec']) : $secActiva;
$vendFiltro = cleanInt($_GET['vendedor'] ?? 0);

/* â”€â”€ Resumen general por vendedor â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€ */
$fFiltro = '';
$fParams = [];
if ($secFiltro) { $fFiltro = ' AND secuencia_id=?'; $fParams[] = $secFiltro; }

$fFactura = "SELECT vendedor_id, SUM(total_usd) facturado, COUNT(*) facturas
             FROM ventas WHERE tipo_doc='venta' AND estado<>'anulado'" . $fFiltro . " GROUP BY vendedor_id";
$fReporte = 'SELECT vendedor_id, SUM(monto_usd) reportado FROM pagos_vendedor WHERE 1' . $fFiltro . ' GROUP BY vendedor_id';
$fComision = 'SELECT vendedor_id, SUM(monto_usd) comision FROM comisiones_vendedor WHERE 1' . $fFiltro . ' GROUP BY vendedor_id';

$resumen = dbQuery(
    "SELECT vd.id, vd.nombre,
            COALESCE(f.facturado,0) facturado,
            COALESCE(f.facturas,0)  facturas,
            COALESCE(r.reportado,0) reportado,
            COALESCE(c.comision,0)  comision,
            COALESCE(f.facturado,0) - COALESCE(r.reportado,0) deuda
     FROM vendedores vd
     LEFT JOIN ({$fFactura})  f ON f.vendedor_id = vd.id
     LEFT JOIN ({$fReporte})  r ON r.vendedor_id = vd.id
     LEFT JOIN ({$fComision}) c ON c.vendedor_id = vd.id
     WHERE vd.activo = 1
     ORDER BY deuda DESC, vd.nombre",
    array_merge($fParams, $fParams, $fParams)
);

/* â”€â”€ Cascada del vendedor seleccionado â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€ */
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
            'concepto'=>'Reporte Â· '.ucfirst($v['tipo']) . ($v['referencia'] ? ' Â· '.$v['referencia'] : ''),
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

include 'includes/header.php';
?>
<style>
.ev-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:14px;margin-bottom:18px}
@media(max-width:800px){.ev-grid{grid-template-columns:1fr}}
.ev-card{background:white;border:1px solid var(--border);border-radius:12px;padding:16px 18px}
.ev-card .lbl{font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:var(--text-muted)}
.ev-card .val{font-size:22px;font-weight:900;margin-top:4px}
.ev-row{cursor:pointer}
.ev-row:hover{background:#f0f7ff}
.ev-selected{background:#eef4fb !important;border-left:4px solid var(--primary)}
.neg{color:#c0392b}.pos{color:#059669}
</style>

<h4 style="margin-bottom:16px"><i class="fa-solid fa-chart-line" style="color:var(--primary)"></i> Estado de cuenta Â· Vendedores</h4>

<div class="ev-grid">
    <div class="ev-card"><div class="lbl">Facturado</div><div class="val"><?= formatUSD($totFacturado) ?></div></div>
    <div class="ev-card"><div class="lbl">Reportado</div><div class="val pos"><?= formatUSD($totReportado) ?></div></div>
    <div class="ev-card"><div class="lbl">Deuda total</div><div class="val neg"><?= formatUSD($totDeuda) ?></div></div>
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
            <table class="table" style="font-size:13px">
                <thead>
                    <tr>
                        <th>Vendedor</th><th class="text-center">Facturas</th>
                        <th class="text-end">Facturado</th><th class="text-end">Reportado</th>
                        <th class="text-end">ComisiÃ³n</th><th class="text-end">Deuda</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach($resumen as $r): ?>
                    <tr class="ev-row <?= $vendFiltro===(int)$r['id']?'ev-selected':'' ?>"
                        onclick="location.href='estado_vendedores.php?sec=<?= $secFiltro ?>&vendedor=<?= (int)$r['id'] ?>'">
                        <td><strong><?= clean($r['nombre']) ?></strong></td>
                        <td class="text-center"><?= (int)$r['facturas'] ?></td>
                        <td class="text-end"><?= formatUSD((float)$r['facturado']) ?></td>
                        <td class="text-end pos"><?= formatUSD((float)$r['reportado']) ?></td>
                        <td class="text-end"><?= formatUSD((float)$r['comision']) ?></td>
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
                        <td class="text-end"><?= formatUSD($totReportado) ?></td>
                        <td></td>
                        <td class="text-end"><?= formatUSD($totDeuda) ?></td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>
</div>

<?php if ($selVendedor): ?>
<!-- Cascada -->
<div class="card">
    <div class="card-header">
        <i class="fa-solid fa-water"></i>
        <h5>CÃ³mo se va reduciendo la deuda Â· <?= clean($selVendedor['nombre']) ?></h5>
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
                        <td class="text-end"><?= $d['cargo'] ? formatUSD($d['cargo']) : 'â€”' ?></td>
                        <td class="text-end pos"><?= $d['abono'] ? formatUSD($d['abono']) : 'â€”' ?></td>
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
