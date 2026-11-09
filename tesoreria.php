<?php
require_once 'config/config.php';
require_once 'functions/db.php';
require_once 'functions/helpers.php';
require_once 'functions/auth.php';

requireLogin();
requirePermission('pagos','ver');
if (!hasPermission('pagos','crear') && !isSupervisor()) {
    http_response_code(403);
    include ROOT_PATH . '/includes/403.php';
    exit;
}
$pageTitle = 'Tesorería';

/* ── Registrar movimiento manual ───────────────────────────── */
if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action']??'')==='movimiento') {
    verifyCsrf();
    requirePermission('pagos','crear');

    $fecha = $_POST['fecha'] ?? date('Y-m-d');
    $tipo  = in_array($_POST['tipo']??'', ['ingreso','egreso']) ? $_POST['tipo'] : 'egreso';
    $cat   = in_array($_POST['categoria']??'', ['entrega_vendedor','gasto','ingreso_manual','otro']) ? $_POST['categoria'] : 'otro';
    $vid   = cleanInt($_POST['vendedor_id'] ?? 0);
    $monto = cleanFloat($_POST['monto'] ?? 0);
    $ref   = clean($_POST['referencia'] ?? '');
    $notas = clean($_POST['notas'] ?? '');

    if ($monto <= 0) {
        $_SESSION['flash'] = ['type'=>'error','msg'=>'El monto debe ser mayor que cero.'];
    } else {
        dbExecute(
            "INSERT INTO tesoreria_movimientos (fecha,tipo,categoria,vendedor_id,monto_usd,referencia,notas,usuario_id)
             VALUES (?,?,?,?,?,?,?,?)",
            [$fecha, $tipo, $cat, $vid ?: null, $monto, $ref, $notas, $_SESSION['user_id']]
        );
        $etiqueta = $tipo === 'ingreso' ? 'Ingreso' : 'Egreso';
        auditLog('INSERT', 'tesoreria_movimientos', (int)dbLastId(), "{$etiqueta} de tesorería: \${$monto}");
        $_SESSION['flash'] = ['type'=>'success','msg'=>"{$etiqueta} registrado correctamente."];
    }
    header('Location: tesoreria.php'); exit;
}

/* ── Filtros ───────────────────────────────────────────────── */
$secuencias = dbQuery("SELECT id,nombre,estado,fecha_ini,fecha_fin FROM secuencias ORDER BY estado='activa' DESC, fecha_ini DESC, id DESC");
$secActiva  = 0;
foreach ($secuencias as $s) { if ($s['estado']==='activa') { $secActiva=(int)$s['id']; break; } }

$secFiltro = isset($_GET['sec']) ? cleanInt($_GET['sec']) : $secActiva;
$desde     = clean($_GET['desde'] ?? '');
$hasta     = clean($_GET['hasta'] ?? '');
$vendedores = dbQuery("SELECT id,nombre FROM vendedores WHERE activo=1 ORDER BY nombre");

$filtros = [];
$params  = [];
if ($secFiltro) { $filtros[] = "v.secuencia_id=?"; $params[] = $secFiltro; }
if ($desde)     { $filtros[] = "p.fecha>=?";       $params[] = $desde; }
if ($hasta)     { $filtros[] = "p.fecha<=?";       $params[] = $hasta; }
$wherePagos = $filtros ? (' AND ' . implode(' AND ', $filtros)) : '';

$filtrosPV = [];
$paramsPV  = [];
if ($secFiltro) { $filtrosPV[] = "secuencia_id=?"; $paramsPV[] = $secFiltro; }
if ($desde)     { $filtrosPV[] = "fecha>=?";       $paramsPV[] = $desde; }
if ($hasta)     { $filtrosPV[] = "fecha<=?";       $paramsPV[] = $hasta; }
$wherePV = $filtrosPV ? (' AND ' . implode(' AND ', $filtrosPV)) : '';

$filtrosTM = [];
$paramsTM  = [];
if ($desde) { $filtrosTM[] = "fecha>=?"; $paramsTM[] = $desde; }
if ($hasta) { $filtrosTM[] = "fecha<=?"; $paramsTM[] = $hasta; }
$whereTM = $filtrosTM ? (' WHERE ' . implode(' AND ', $filtrosTM)) : '';

/* ── Totales ───────────────────────────────────────────────── */
$totCobros = (float)(dbQueryOne(
    "SELECT COALESCE(SUM(p.monto_usd),0) t
     FROM pagos p JOIN ventas v ON v.id=p.venta_id
     WHERE p.anulado=0 {$wherePagos}", $params)['t'] ?? 0);

$totVendedores = (float)(dbQueryOne(
    "SELECT COALESCE(SUM(monto_usd),0) t FROM pagos_vendedor WHERE 1 {$wherePV}", $paramsPV)['t'] ?? 0);

$totMov = dbQueryOne(
    "SELECT COALESCE(SUM(CASE WHEN tipo='ingreso' THEN monto_usd ELSE 0 END),0) ing,
            COALESCE(SUM(CASE WHEN tipo='egreso'  THEN monto_usd ELSE 0 END),0) egr
     FROM tesoreria_movimientos {$whereTM}", $paramsTM);

$ingresosManuales = (float)($totMov['ing'] ?? 0);
$egresosManuales  = (float)($totMov['egr'] ?? 0);
$totalIngresos    = $totCobros + $totVendedores + $ingresosManuales;
$totalEgresos     = $egresosManuales;
$saldo            = $totalIngresos - $totalEgresos;

/* ── Movimientos recientes (mezcla de las tres fuentes) ────── */
$movs = [];

foreach (dbQuery(
    "SELECT p.id, p.fecha, p.monto_usd, p.tipo, p.referencia, p.notas,
            v.numero, c.nombre cliente, vd.nombre vendedor
     FROM pagos p
     JOIN ventas v   ON v.id=p.venta_id
     LEFT JOIN clientes c   ON c.id=v.cliente_id
     LEFT JOIN vendedores vd ON vd.id=v.vendedor_id
     WHERE p.anulado=0 {$wherePagos}
     ORDER BY p.fecha DESC, p.id DESC LIMIT 200", $params) as $r) {
    $movs[] = [
        'fecha'=>$r['fecha'], 'tipo'=>'ingreso', 'categoria'=>'cobro_cliente',
        'concepto'=>'Cobro '.$r['numero'].($r['cliente'] ? ' · '.$r['cliente'] : ''),
        'vendedor'=>$r['vendedor'], 'monto'=>(float)$r['monto_usd'],
        'referencia'=>$r['referencia'], 'notas'=>$r['notas'],
    ];
}

foreach (dbQuery(
    "SELECT pv.id, pv.fecha, pv.monto_usd, pv.tipo, pv.referencia, pv.descripcion, vd.nombre vendedor
     FROM pagos_vendedor pv
     LEFT JOIN vendedores vd ON vd.id=pv.vendedor_id
     WHERE 1 {$wherePV}
     ORDER BY pv.fecha DESC, pv.id DESC LIMIT 200", $paramsPV) as $r) {
    $movs[] = [
        'fecha'=>$r['fecha'], 'tipo'=>'ingreso', 'categoria'=>'reporte_vendedor',
        'concepto'=>'Reporte de '.($r['vendedor'] ?? 'vendedor'),
        'vendedor'=>$r['vendedor'], 'monto'=>(float)$r['monto_usd'],
        'referencia'=>$r['referencia'], 'notas'=>$r['descripcion'],
    ];
}

foreach (dbQuery(
    "SELECT tm.id, tm.fecha, tm.tipo, tm.categoria, tm.monto_usd, tm.referencia, tm.notas,
            vd.nombre vendedor
     FROM tesoreria_movimientos tm
     LEFT JOIN vendedores vd ON vd.id=tm.vendedor_id
     {$whereTM}
     ORDER BY tm.fecha DESC, tm.id DESC LIMIT 200", $paramsTM) as $r) {
    $movs[] = [
        'fecha'=>$r['fecha'], 'tipo'=>$r['tipo'], 'categoria'=>$r['categoria'],
        'concepto'=>ucfirst(str_replace('_',' ', $r['categoria'])) . ($r['vendedor'] ? ' · '.$r['vendedor'] : ''),
        'vendedor'=>$r['vendedor'], 'monto'=>(float)$r['monto_usd'],
        'referencia'=>$r['referencia'], 'notas'=>$r['notas'],
    ];
}

usort($movs, fn($a,$b) => strcmp($b['fecha'], $a['fecha']));
$movs = array_slice($movs, 0, 250);

include 'includes/header.php';
?>
<style>
.tes-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:14px;margin-bottom:18px}
@media(max-width:900px){.tes-grid{grid-template-columns:repeat(2,1fr)}}
.tes-card{background:white;border:1px solid var(--border);border-radius:12px;padding:16px 18px}
.tes-card .lbl{font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:var(--text-muted)}
.tes-card .val{font-size:22px;font-weight:900;margin-top:4px}
.tes-in{color:#059669}.tes-out{color:#c0392b}.tes-bal{color:#2980b9}
.tes-pill{display:inline-block;padding:2px 8px;border-radius:10px;font-size:11px;font-weight:700}
.pill-in{background:#d1fae5;color:#065f46}.pill-out{background:#fde8e8;color:#991b1b}
</style>

<h4 style="margin-bottom:16px"><i class="fa-solid fa-vault" style="color:var(--primary)"></i> Tesorería</h4>

<div class="tes-grid">
    <div class="tes-card">
        <div class="lbl">Cobrado a clientes</div>
        <div class="val tes-in"><?= formatUSD($totCobros) ?></div>
    </div>
    <div class="tes-card">
        <div class="lbl">Reportado por vendedores</div>
        <div class="val tes-in"><?= formatUSD($totVendedores) ?></div>
    </div>
    <div class="tes-card">
        <div class="lbl">Entregas y gastos</div>
        <div class="val tes-out"><?= formatUSD($totalEgresos) ?></div>
    </div>
    <div class="tes-card">
        <div class="lbl">Saldo del período</div>
        <div class="val tes-bal"><?= formatUSD($saldo) ?></div>
    </div>
</div>

<!-- ── Registrar movimiento ── -->
<div class="card mb-3">
    <div class="card-header"><i class="fa-solid fa-plus"></i><h5>Registrar movimiento</h5></div>
    <div class="card-body">
        <form method="POST" class="d-flex gap-2 align-end" style="flex-wrap:wrap">
            <input type="hidden" name="<?= CSRF_TOKEN_NAME ?>" value="<?= getCsrfToken() ?>">
            <input type="hidden" name="action" value="movimiento">
            <div>
                <label class="form-label">Fecha</label>
                <input type="date" name="fecha" class="form-control" value="<?= date('Y-m-d') ?>" required>
            </div>
            <div>
                <label class="form-label">Tipo</label>
                <select name="tipo" class="form-select">
                    <option value="egreso">Egreso (sale dinero)</option>
                    <option value="ingreso">Ingreso (entra dinero)</option>
                </select>
            </div>
            <div>
                <label class="form-label">Concepto</label>
                <select name="categoria" class="form-select">
                    <option value="entrega_vendedor">Entrega a vendedor</option>
                    <option value="gasto">Gasto</option>
                    <option value="ingreso_manual">Ingreso manual</option>
                    <option value="otro">Otro</option>
                </select>
            </div>
            <div>
                <label class="form-label">Vendedor (opcional)</label>
                <select name="vendedor_id" class="form-select">
                    <option value="0">—</option>
                    <?php foreach($vendedores as $vd): ?>
                    <option value="<?= (int)$vd['id'] ?>"><?= clean($vd['nombre']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label class="form-label">Monto USD</label>
                <input type="number" step="0.01" min="0.01" name="monto" class="form-control" required style="max-width:140px">
            </div>
            <div>
                <label class="form-label">Referencia</label>
                <input type="text" name="referencia" class="form-control" style="max-width:160px">
            </div>
            <div style="flex:1;min-width:180px">
                <label class="form-label">Notas</label>
                <input type="text" name="notas" class="form-control">
            </div>
            <button class="btn btn-primary"><i class="fa-solid fa-check"></i> Guardar</button>
        </form>
    </div>
</div>

<!-- ── Filtros ── -->
<div class="card mb-3">
    <div class="card-body" style="padding:10px 16px">
        <form method="GET" class="d-flex gap-2 align-center" style="flex-wrap:wrap">
            <select name="sec" class="form-select" style="max-width:230px">
                <option value="0">Todas las secuencias</option>
                <?php foreach($secuencias as $s): ?>
                <option value="<?= (int)$s['id'] ?>" <?= $secFiltro===(int)$s['id']?'selected':'' ?>>
                    <?= clean($s['nombre']) ?><?= $s['estado']==='activa'?' (activa)':'' ?>
                </option>
                <?php endforeach; ?>
            </select>
            <input type="date" name="desde" class="form-control" value="<?= $desde ?>" style="max-width:150px">
            <span class="text-muted">a</span>
            <input type="date" name="hasta" class="form-control" value="<?= $hasta ?>" style="max-width:150px">
            <button class="btn btn-primary btn-sm"><i class="fa-solid fa-filter"></i> Filtrar</button>
            <a href="tesoreria.php" class="btn btn-light btn-sm"><i class="fa-solid fa-xmark"></i></a>
        </form>
    </div>
</div>

<!-- ── Movimientos ── -->
<div class="card">
    <div class="card-header"><i class="fa-solid fa-list"></i><h5>Movimientos (<?= count($movs) ?>)</h5></div>
    <div class="card-body p-0">
        <div class="table-wrapper">
            <table class="table" style="font-size:13px">
                <thead>
                    <tr>
                        <th>Fecha</th><th>Movimiento</th><th>Vendedor</th>
                        <th class="text-end">Monto</th><th>Referencia</th><th>Notas</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($movs)): ?>
                    <tr><td colspan="6" class="text-center text-muted" style="padding:24px">Sin movimientos en el período.</td></tr>
                <?php else: foreach($movs as $m): ?>
                    <tr>
                        <td><?= date('d/m/Y', strtotime($m['fecha'])) ?></td>
                        <td>
                            <span class="tes-pill <?= $m['tipo']==='ingreso'?'pill-in':'pill-out' ?>">
                                <?= $m['tipo']==='ingreso'?'Ingreso':'Egreso' ?>
                            </span>
                            <?= clean($m['concepto']) ?>
                        </td>
                        <td><?= clean($m['vendedor'] ?? '—') ?></td>
                        <td class="text-end" style="font-weight:700;color:<?= $m['tipo']==='ingreso'?'#059669':'#c0392b' ?>">
                            <?= $m['tipo']==='ingreso'?'+':'-' ?><?= formatUSD($m['monto']) ?>
                        </td>
                        <td><?= clean($m['referencia'] ?? '') ?></td>
                        <td class="text-muted"><?= clean($m['notas'] ?? '') ?></td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php include 'includes/footer.php'; ?>
