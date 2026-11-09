<?php
require_once 'config/config.php';
require_once 'functions/db.php';
require_once 'functions/helpers.php';
require_once 'functions/auth.php';
requireLogin();
$pageTitle = 'Dashboard';

$tasa = getTasaActual();

// ── KPIs ──────────────────────────────────────────────────────
$statsDoc = dbQueryOne(
    "SELECT
        COUNT(*) total,
        SUM(CASE WHEN tipo_doc='venta'    THEN 1 ELSE 0 END) facturas,
        SUM(CASE WHEN tipo_doc='despacho' THEN 1 ELSE 0 END) despachos,
        SUM(CASE WHEN tipo_doc='cotizacion' THEN 1 ELSE 0 END) cotizaciones,
        SUM(CASE WHEN estado='anulado'    THEN 1 ELSE 0 END) anulados,
        COALESCE(SUM(CASE WHEN tipo_doc='venta' AND estado!='anulado' THEN total_usd END),0) ventas_usd
     FROM ventas WHERE MONTH(fecha)=MONTH(CURDATE()) AND YEAR(fecha)=YEAR(CURDATE())"
);

$secActivas   = dbQueryOne("SELECT COUNT(*) c FROM secuencias WHERE estado='activa'")['c'];
$stockBajo    = dbQueryOne("SELECT COUNT(*) c FROM productos WHERE stock_actual<=stock_minimo AND activo=1")['c'];
$totalClientes= dbQueryOne("SELECT COUNT(*) c FROM clientes WHERE activo=1")['c'];

// Deuda pendiente por vendedores (total ventas - pagos registrados)
$deudaPend = dbQueryOne(
    "SELECT COALESCE(SUM(v.total_usd),0) - COALESCE(
        (SELECT SUM(pv.monto_usd) FROM pagos_vendedor pv
         JOIN secuencias s ON pv.secuencia_id=s.id WHERE s.estado='activa'),0) deuda
     FROM ventas v
     JOIN secuencias s ON v.secuencia_id=s.id
     WHERE s.estado='activa' AND v.tipo_doc='venta' AND v.estado!='anulado'"
)['deuda'] ?? 0;

// Ventas últimos 7 días
$ventasDias = dbQuery(
    "SELECT DATE(fecha) d,
            COUNT(*) docs,
            COALESCE(SUM(total_usd),0) usd
     FROM ventas
     WHERE tipo_doc='venta' AND estado!='anulado'
       AND fecha >= DATE_SUB(CURDATE(), INTERVAL 6 DAY)
     GROUP BY DATE(fecha)
     ORDER BY d ASC"
);

// Llenar días sin ventas
$diasMap = [];
for ($i = 6; $i >= 0; $i--) {
    $k = date('Y-m-d', strtotime("-$i days"));
    $diasMap[$k] = ['d'=>$k,'docs'=>0,'usd'=>0];
}
foreach ($ventasDias as $r) $diasMap[$r['d']] = $r;
$chartDias = array_values($diasMap);

// Top 5 vendedores del mes
$topVendedores = dbQuery(
    "SELECT vd.nombre, SUM(v.total_usd) total, COUNT(v.id) docs
     FROM ventas v
     JOIN vendedores vd ON v.vendedor_id=vd.id
     WHERE v.tipo_doc='venta' AND v.estado!='anulado'
       AND MONTH(v.fecha)=MONTH(CURDATE()) AND YEAR(v.fecha)=YEAR(CURDATE())
     GROUP BY v.vendedor_id ORDER BY total DESC LIMIT 5"
);

// Secuencias activas con resumen
$secResumen = dbQuery(
    "SELECT s.id, s.nombre, s.fecha_ini,
            COUNT(DISTINCT v.id) facturas,
            COALESCE(SUM(v.total_usd),0) total_usd,
            COALESCE((SELECT SUM(pv.monto_usd) FROM pagos_vendedor pv WHERE pv.secuencia_id=s.id),0) cobrado
     FROM secuencias s
     LEFT JOIN ventas v ON v.secuencia_id=s.id AND v.tipo_doc='venta' AND v.estado!='anulado'
     WHERE s.estado='activa'
     GROUP BY s.id ORDER BY s.fecha_ini DESC LIMIT 4"
);

// Últimas 6 facturas
$ultimasDocs = dbQuery(
    "SELECT v.id, v.numero, v.fecha, v.total_usd, v.tipo_doc, v.tipo_cliente,
            c.nombre c_nombre, vd.nombre v_nombre
     FROM ventas v
     LEFT JOIN clientes c   ON v.cliente_id=c.id
     LEFT JOIN vendedores vd ON v.vendedor_id=vd.id
     WHERE v.tipo_doc='venta' AND v.estado!='anulado'
     ORDER BY v.created_at DESC LIMIT 6"
);

// Stock bajo productos
$prodStockBajo = dbQuery(
    "SELECT descripcion, codigo, stock_actual, stock_minimo
     FROM productos WHERE stock_actual<=stock_minimo AND activo=1
     ORDER BY (stock_actual-stock_minimo) ASC LIMIT 5"
);

include 'includes/header.php';

// Chart data
$chartLabels = json_encode(array_map(fn($d)=>date('D d', strtotime($d['d'])), $chartDias));
$chartVentas = json_encode(array_map(fn($d)=>(float)$d['usd'], $chartDias));
$chartDocs   = json_encode(array_map(fn($d)=>(int)$d['docs'], $chartDias));
$maxVend     = $topVendedores ? max(array_column($topVendedores,'total')) : 1;
?>

<style>
.dash-grid     { display:grid; grid-template-columns:repeat(4,1fr); gap:16px; margin-bottom:20px; }
.dash-grid-2   { display:grid; grid-template-columns:2fr 1fr; gap:16px; margin-bottom:16px; }
.dash-grid-3   { display:grid; grid-template-columns:1fr 1fr 1fr; gap:16px; margin-bottom:16px; }
.kpi-card      { background:white; border:1px solid var(--border); border-radius:12px; padding:18px 20px;
                 display:flex; align-items:center; gap:14px; transition:.2s; cursor:default; }
.kpi-card:hover{ box-shadow:0 4px 16px rgba(0,0,0,.1); transform:translateY(-1px); }
.kpi-icon      { width:46px; height:46px; border-radius:12px; display:flex; align-items:center;
                 justify-content:center; font-size:20px; flex-shrink:0; }
.kpi-val       { font-size:24px; font-weight:900; line-height:1; }
.kpi-lab       { font-size:11px; font-weight:600; text-transform:uppercase; letter-spacing:.5px;
                 color:var(--text-muted); margin-top:3px; }
.kpi-sub       { font-size:11px; color:var(--text-muted); margin-top:4px; }
.kpi-badge     { display:inline-block; padding:2px 8px; border-radius:10px; font-size:10px; font-weight:700; margin-top:4px; }

.widget        { background:white; border:1px solid var(--border); border-radius:12px; overflow:hidden; }
.widget-header { padding:14px 18px; border-bottom:1px solid var(--border); display:flex; align-items:center; gap:8px; }
.widget-header h5 { font-size:13px; font-weight:700; margin:0; flex:1; }
.widget-body   { padding:16px 18px; }

.sec-pill      { display:flex; align-items:center; gap:12px; padding:12px 0;
                 border-bottom:1px solid var(--border); }
.sec-pill:last-child { border-bottom:none; }
.sec-progress  { flex:1; }
.sec-prog-bar  { height:6px; border-radius:3px; background:#f0f2f5; overflow:hidden; margin-top:4px; }
.sec-prog-fill { height:100%; border-radius:3px; background:var(--success); transition:.3s; }

.vend-bar      { display:flex; align-items:center; gap:10px; padding:8px 0; border-bottom:1px solid var(--border); }
.vend-bar:last-child { border-bottom:none; }
.vend-bar-fill { height:8px; border-radius:4px; background:linear-gradient(90deg,var(--primary),#5e9ef7); }

.doc-row       { display:flex; align-items:center; gap:10px; padding:9px 0; border-bottom:1px solid var(--border); }
.doc-row:last-child { border-bottom:none; }
.doc-avatar    { width:36px; height:36px; border-radius:8px; background:#f0f2f5;
                 display:flex; align-items:center; justify-content:center; font-size:13px; font-weight:800;
                 flex-shrink:0; color:var(--primary); }

.alert-item    { display:flex; align-items:center; gap:8px; padding:9px 12px;
                 border-radius:8px; margin-bottom:6px; font-size:12px; }
.alert-item.warn { background:#fff3cd; color:#856404; }
.alert-item.danger { background:#fde8e8; color:#c0392b; }
.alert-item.info   { background:#e8f4fd; color:#2980b9; }

@media(max-width:1100px){ .dash-grid{grid-template-columns:repeat(2,1fr)} }
@media(max-width:700px) { .dash-grid{grid-template-columns:1fr} .dash-grid-2,.dash-grid-3{grid-template-columns:1fr} }
</style>

<!-- Bienvenida -->
<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:20px">
    <div>
        <h2 style="margin:0;font-size:20px">👋 Bienvenido, <?= clean($_SESSION['user_name'] ?? 'Usuario') ?></h2>
        <p style="margin:4px 0 0;font-size:12px;color:var(--text-muted)">
            <?= date('l j \d\e F, Y') ?> ·
            <?php if($tasa): ?>
            Tasa BCV: <strong>Bs. <?= number_format($tasa['tasa_bs'],2) ?></strong>
            <?php else: ?><span style="color:var(--danger)">Sin tasa registrada</span><?php endif; ?>
        </p>
    </div>
    <div class="d-flex gap-2">
        <?php if(hasPermission('ventas','crear')): ?>
        <a href="nueva_venta.php" class="btn btn-primary"><i class="fa-solid fa-plus"></i> Nueva Factura</a>
        <?php endif; ?>
        <a href="pagos.php" class="btn btn-light"><i class="fa-solid fa-hand-holding-dollar"></i> Cobranza</a>
    </div>
</div>

<!-- KPIs principales -->
<div class="dash-grid">
    <div class="kpi-card" onclick="location.href='documentos.php'" style="cursor:pointer;border-top:3px solid var(--success)">
        <div class="kpi-icon" style="background:#d1fae5"><span style="color:#059669">💰</span></div>
        <div>
            <div class="kpi-val" style="color:var(--success)"><?= formatUSD($statsDoc['ventas_usd']) ?></div>
            <div class="kpi-lab">Ventas del Mes</div>
            <div class="kpi-sub"><?= $statsDoc['facturas'] ?> facturas</div>
        </div>
    </div>

    <div class="kpi-card" onclick="location.href='pagos.php'" style="cursor:pointer;border-top:3px solid var(--danger)">
        <div class="kpi-icon" style="background:#fde8e8"><span style="color:#c0392b">⏳</span></div>
        <div>
            <div class="kpi-val" style="color:var(--danger)"><?= formatUSD(max(0,$deudaPend)) ?></div>
            <div class="kpi-lab">Por Cobrar</div>
            <div class="kpi-sub"><?= $secActivas ?> secuencia(s) activa(s)</div>
        </div>
    </div>

    <div class="kpi-card" onclick="location.href='inventario.php'" style="cursor:pointer;border-top:3px solid <?= $stockBajo>0?'var(--warning)':'var(--info)' ?>">
        <div class="kpi-icon" style="background:<?= $stockBajo>0?'#fff3cd':'#e8f4fd' ?>"><span><?= $stockBajo>0?'⚠️':'📦' ?></span></div>
        <div>
            <div class="kpi-val" style="color:<?= $stockBajo>0?'var(--warning)':'var(--info)' ?>"><?= $stockBajo ?></div>
            <div class="kpi-lab">Stock Bajo</div>
            <div class="kpi-sub">productos bajo mínimo</div>
        </div>
    </div>

    <div class="kpi-card" onclick="location.href='clientes.php'" style="cursor:pointer;border-top:3px solid var(--primary)">
        <div class="kpi-icon" style="background:#eff6ff"><span style="color:var(--primary)">👥</span></div>
        <div>
            <div class="kpi-val" style="color:var(--primary)"><?= $totalClientes ?></div>
            <div class="kpi-lab">Clientes Activos</div>
            <div class="kpi-sub"><?= $statsDoc['despachos'] ?> despachos este mes</div>
        </div>
    </div>
</div>

<!-- Fila 2: Gráfico + Secuencias activas -->
<div class="dash-grid-2">

    <!-- Gráfico 7 días -->
    <div class="widget">
        <div class="widget-header">
            <span>📈</span>
            <h5>Ventas — últimos 7 días</h5>
            <span style="font-size:11px;color:var(--text-muted)">USD</span>
        </div>
        <div class="widget-body" style="padding:12px 16px">
            <canvas id="chartVentas" height="120"></canvas>
        </div>
    </div>

    <!-- Secuencias activas -->
    <div class="widget">
        <div class="widget-header">
            <span>🔄</span>
            <h5>Secuencias Activas</h5>
            <a href="pagos.php" style="font-size:11px;color:var(--primary)">Ver todo →</a>
        </div>
        <div class="widget-body">
        <?php if(empty($secResumen)): ?>
        <p style="text-align:center;color:var(--text-muted);font-size:12px;padding:16px 0">Sin secuencias activas</p>
        <?php else: ?>
        <?php foreach($secResumen as $s):
            $pct = $s['total_usd'] > 0 ? min(100, round($s['cobrado']/$s['total_usd']*100)) : 0;
            $restante = max(0, $s['total_usd'] - $s['cobrado']);
        ?>
        <div class="sec-pill">
            <div style="width:36px;height:36px;border-radius:8px;background:var(--primary);color:white;display:flex;align-items:center;justify-content:center;font-size:11px;font-weight:800;flex-shrink:0">
                <?= $pct ?>%
            </div>
            <div class="sec-progress">
                <div style="display:flex;justify-content:space-between;font-size:12px">
                    <a href="pagos.php?sec=<?= $s['id'] ?>" style="font-weight:700;color:var(--primary)"><?= clean(mb_strimwidth($s['nombre'],0,28,'...')) ?></a>
                    <span style="color:var(--danger);font-weight:700"><?= formatUSD($restante) ?></span>
                </div>
                <div class="sec-prog-bar"><div class="sec-prog-fill" style="width:<?= $pct ?>%"></div></div>
                <div style="font-size:10px;color:var(--text-muted);margin-top:2px">
                    <?= $s['facturas'] ?> facturas · Cobrado: <?= formatUSD($s['cobrado']) ?> / <?= formatUSD($s['total_usd']) ?>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
        <?php endif; ?>
        </div>
    </div>
</div>

<!-- Fila 3: Top vendedores + Últimas facturas + Alertas -->
<div class="dash-grid-3">

    <!-- Top vendedores -->
    <div class="widget">
        <div class="widget-header">
            <span>🏆</span>
            <h5>Top Vendedores del Mes</h5>
        </div>
        <div class="widget-body">
        <?php if(empty($topVendedores)): ?>
        <p style="text-align:center;color:var(--text-muted);font-size:12px;padding:12px 0">Sin ventas este mes</p>
        <?php else: ?>
        <?php foreach($topVendedores as $k => $vd):
            $pct = $maxVend > 0 ? round($vd['total']/$maxVend*100) : 0;
            $medals = ['🥇','🥈','🥉','4️⃣','5️⃣'];
        ?>
        <div class="vend-bar">
            <span style="font-size:16px;width:20px"><?= $medals[$k] ?? ($k+1) ?></span>
            <div style="flex:1;min-width:0">
                <div style="font-size:12px;font-weight:700;white-space:nowrap;overflow:hidden;text-overflow:ellipsis"><?= clean($vd['nombre']) ?></div>
                <div class="vend-bar-fill" style="width:<?= $pct ?>%;margin-top:4px"></div>
            </div>
            <div style="text-align:right;font-size:12px;flex-shrink:0">
                <div style="font-weight:800;color:var(--success)"><?= formatUSD($vd['total']) ?></div>
                <div style="color:var(--text-muted)"><?= $vd['docs'] ?> docs</div>
            </div>
        </div>
        <?php endforeach; ?>
        <?php endif; ?>
        </div>
    </div>

    <!-- Últimas facturas -->
    <div class="widget">
        <div class="widget-header">
            <span>🧾</span>
            <h5>Últimas Facturas</h5>
            <a href="documentos.php" style="font-size:11px;color:var(--primary)">Ver todas →</a>
        </div>
        <div class="widget-body">
        <?php foreach($ultimasDocs as $doc): ?>
        <div class="doc-row">
            <div class="doc-avatar"><?= strtoupper(substr($doc['c_nombre'],0,2)) ?></div>
            <div style="flex:1;min-width:0">
                <div style="font-size:12px;font-weight:700;white-space:nowrap;overflow:hidden;text-overflow:ellipsis"><?= clean($doc['c_nombre']) ?></div>
                <div style="font-size:10px;color:var(--text-muted)"><?= clean($doc['v_nombre']) ?> · <?= date('d/m',strtotime($doc['fecha'])) ?></div>
            </div>
            <div style="text-align:right;flex-shrink:0">
                <div style="font-size:12px;font-weight:800;color:var(--success)"><?= formatUSD($doc['total_usd']) ?></div>
                <a href="ver_venta.php?id=<?= $doc['id'] ?>" style="font-size:10px;color:var(--primary)"><?= clean($doc['numero']) ?></a>
            </div>
        </div>
        <?php endforeach; ?>
        </div>
    </div>

    <!-- Alertas / Stock bajo -->
    <div class="widget">
        <div class="widget-header">
            <span>🔔</span>
            <h5>Alertas del Sistema</h5>
        </div>
        <div class="widget-body">
        <?php if(!$tasa): ?>
        <div class="alert-item danger">
            <i class="fa-solid fa-triangle-exclamation"></i>
            <div><strong>Sin tasa de cambio</strong><br>Registra una tasa BCV hoy.</div>
            <a href="tasas.php" class="btn btn-sm btn-danger" style="margin-left:auto">Ir</a>
        </div>
        <?php endif; ?>

        <?php if($stockBajo > 0): ?>
        <div class="alert-item warn">
            <i class="fa-solid fa-box-open"></i>
            <div><strong><?= $stockBajo ?> producto(s)</strong> bajo stock mínimo</div>
            <a href="inventario.php" class="btn btn-sm btn-warning" style="margin-left:auto">Ver</a>
        </div>
        <?php endif; ?>

        <?php if($secActivas == 0): ?>
        <div class="alert-item info">
            <i class="fa-solid fa-layer-group"></i>
            <div>Sin secuencias activas. Crea una en Cobranza.</div>
            <a href="pagos.php" class="btn btn-sm btn-light" style="margin-left:auto">Ir</a>
        </div>
        <?php endif; ?>

        <?php foreach($prodStockBajo as $p): ?>
        <div class="alert-item warn" style="padding:7px 10px">
            <span>📦</span>
            <div style="flex:1;min-width:0;font-size:11px">
                <strong><?= clean(mb_strimwidth($p['descripcion'],0,30,'...')) ?></strong><br>
                Stock: <?= $p['stock_actual'] ?> / Mín: <?= $p['stock_minimo'] ?>
            </div>
        </div>
        <?php endforeach; ?>

        <?php if(!$stockBajo && $tasa && $secActivas > 0): ?>
        <div class="alert-item info">
            <i class="fa-solid fa-circle-check"></i>
            <div>Todo en orden. Sin alertas activas.</div>
        </div>
        <?php endif; ?>
        </div>
    </div>
</div>

<?php
$extraScript = "
<script>
const ctx = document.getElementById('chartVentas')?.getContext('2d');
if (ctx) {
    new Chart(ctx, {
        type: 'bar',
        data: {
            labels: $chartLabels,
            datasets: [
                {
                    label: 'Ventas USD',
                    data: $chartVentas,
                    backgroundColor: 'rgba(41,128,185,.75)',
                    borderRadius: 6,
                    yAxisID: 'y'
                },
                {
                    label: 'N° Facturas',
                    data: $chartDocs,
                    type: 'line',
                    borderColor: '#e67e22',
                    backgroundColor: 'rgba(230,126,34,.1)',
                    tension:.4, pointRadius:4, pointBackgroundColor:'#e67e22',
                    yAxisID: 'y2'
                }
            ]
        },
        options: {
            responsive:true,
            interaction:{mode:'index',intersect:false},
            plugins:{legend:{position:'top',labels:{font:{size:11}}}},
            scales:{
                y:  {position:'left', grid:{color:'rgba(0,0,0,.05)'}, ticks:{callback:v=>'\$ '+v.toLocaleString()}},
                y2: {position:'right',grid:{display:false}, ticks:{stepSize:1}}
            }
        }
    });
}
</script>";
include 'includes/footer.php';
?>
