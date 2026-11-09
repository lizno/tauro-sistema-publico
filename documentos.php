<?php
require_once 'config/config.php';
require_once 'functions/db.php';
require_once 'functions/helpers.php';
require_once 'functions/auth.php';

requireLogin();
requirePermission('ventas','ver');
$pageTitle = 'Documentos';

// ── CONVERTIR ──────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD']==='POST' && !empty($_POST['convertir'])) {
    $token = $_POST[CSRF_TOKEN_NAME] ?? '';
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $token)) {
        $_SESSION['flash'] = ['type'=>'error','msg'=>'Token inválido. Recarga la página.'];
        header('Location: documentos.php'); exit;
    }
    $vid       = cleanInt($_POST['venta_id'] ?? 0);
    $nuevoTipo = clean($_POST['nuevo_tipo']  ?? '');
    $result    = convertirDocumento($vid, $nuevoTipo, $_SESSION['user_id']);
    $_SESSION['flash'] = ['type'=>$result['ok']?'success':'error','msg'=>$result['msg']];
    header('Location: documentos.php'); exit;
}

// ── ANULAR ─────────────────────────────────────────────────
if (isset($_GET['anular']) && hasPermission('ventas','anular')) {
    $token = $_GET[CSRF_TOKEN_NAME] ?? '';
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $token)) {
        $_SESSION['flash'] = ['type'=>'error','msg'=>'Token inválido.'];
        header('Location: documentos.php'); exit;
    }
    $vid = cleanInt($_GET['anular']);
    $pdo = getDB();
    $pdo->beginTransaction();
    try {
        $v = dbQueryOne("SELECT * FROM ventas WHERE id=? FOR UPDATE", [$vid]);
        if ($v && $v['estado'] !== 'anulado') {
            $stockAfectado = 0;
            $notaStock     = 'sin afectar stock';

            if ($v['tipo_doc'] === 'venta') {
                $devoluciones = (int)(dbQueryOne("SELECT COUNT(*) c FROM devoluciones WHERE venta_id=?", [$vid])['c'] ?? 0);
                if ($devoluciones > 0) {
                    // El stock ya se reintegró al registrar la devolución: no duplicar
                    $notaStock = 'stock ya reintegrado por devoluciones';
                } else {
                    foreach (dbQuery("SELECT * FROM venta_detalles WHERE venta_id=?", [$vid]) as $d) {
                        if ($d['producto_id'] && (float)$d['cantidad'] > 0) {
                            actualizarStock((int)$d['producto_id'], (float)$d['cantidad'], 'sumar');
                            $stockAfectado = 1;
                        }
                    }
                    if ($stockAfectado) $notaStock = 'stock reintegrado al inventario';
                }
            }

            dbExecute("UPDATE ventas SET estado='anulado' WHERE id=?", [$vid]);
            actualizarSaldoCliente((int)$v['cliente_id']);

            try {
                dbExecute(
                    "INSERT INTO documento_historial (venta_id,estado_desde,estado_hasta,tipo_desde,tipo_hasta,stock_afectado,nota,usuario_id)
                     VALUES (?,?,?,?,?,?,?,?)",
                    [$vid, $v['estado'], 'anulado', $v['tipo_doc'], $v['tipo_doc'], $stockAfectado,
                     "Anulado — {$notaStock}", $_SESSION['user_id'] ?? null]
                );
            } catch (Exception $e) { /* columna stock_afectado puede no existir en instancias viejas */ }

            $pdo->commit();

            auditLog('UPDATE','ventas',$vid,"Anulado: {$v['numero']}",$v,['estado'=>'anulado']);
            $_SESSION['flash'] = [
                'type'=>'success',
                'msg'=>"Documento {$v['numero']} anulado." . ($stockAfectado ? ' Productos reintegrados al inventario.' : ''),
            ];
        } else {
            $pdo->rollBack();
        }
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('anular venta: ' . $e->getMessage());
        $_SESSION['flash'] = ['type'=>'error','msg'=>'No se pudo anular el documento: '.$e->getMessage()];
    }
    header('Location: documentos.php'); exit;
}

// ── FILTROS ────────────────────────────────────────────────
$tab            = clean($_GET['tab'] ?? 'despacho');
if (!in_array($tab,['despacho','venta'])) $tab = 'despacho';
$filtroEstado   = clean($_GET['estado']    ?? '');
$filtroTipoCli  = clean($_GET['tipo_cli']  ?? '');
$filtroVendedor = cleanInt($_GET['vendedor'] ?? 0);
$fechaIni       = clean($_GET['fecha_ini'] ?? '');
$fechaFin       = clean($_GET['fecha_fin'] ?? '');
$busqueda       = clean($_GET['q'] ?? '');
$esVendedor     = ($_SESSION['rol_id']??0) == 3;

// ── Secuencia: por defecto la que está activa; 0 = todas ──
$secuencias = dbQuery("SELECT id,nombre,estado,fecha_ini,fecha_fin
                       FROM secuencias
                       ORDER BY estado='activa' DESC, fecha_ini DESC, id DESC");
$secActiva = 0;
foreach ($secuencias as $s) {
    if ($s['estado'] === 'activa') { $secActiva = (int)$s['id']; break; }
}
$filtroSec = isset($_GET['secuencia']) ? cleanInt($_GET['secuencia']) : $secActiva;

// Despacho tab = cotizacion + despacho juntos
$tiposTab = $tab === 'despacho'
    ? ["'cotizacion','despacho'", ['cotizacion','despacho']]
    : ["'venta'", ['venta']];

$sql = "SELECT v.id, v.numero, v.tipo_doc, v.fecha, v.tipo_cliente, v.tipo_pago,
               v.estado, v.total_usd, v.monto_pagado_usd,
               (v.total_usd - v.monto_pagado_usd) saldo_usd,
               v.notas, v.fecha_vencimiento, v.tasa_valor, v.secuencia_id, v.vendedor_id,
               c.nombre cliente_nombre, c.rif cliente_rif,
               vd.nombre vendedor_nombre,
               z.nombre zona_nombre,
               s.nombre secuencia_nombre
        FROM ventas v
        LEFT JOIN clientes c    ON v.cliente_id  = c.id
        LEFT JOIN vendedores vd ON v.vendedor_id = vd.id
        LEFT JOIN zonas z       ON vd.zona_id    = z.id
        LEFT JOIN secuencias s  ON v.secuencia_id = s.id
        WHERE v.tipo_doc IN ({$tiposTab[0]})";
$params = [];

if ($filtroSec)      { $sql.=" AND v.secuencia_id=?";  $params[]=$filtroSec; }
if ($fechaIni)       { $sql.=" AND v.fecha>=?";        $params[]=$fechaIni; }
if ($fechaFin)       { $sql.=" AND v.fecha<=?";        $params[]=$fechaFin; }

if ($filtroEstado)   { $sql.=" AND v.estado=?";       $params[]=$filtroEstado; }
if ($filtroTipoCli)  { $sql.=" AND v.tipo_cliente=?";  $params[]=$filtroTipoCli; }
if ($filtroVendedor) { $sql.=" AND v.vendedor_id=?";   $params[]=$filtroVendedor; }
if ($busqueda)       { $sql.=" AND (v.numero LIKE ? OR c.nombre LIKE ? OR c.rif LIKE ?)";
                       $params[]="%$busqueda%"; $params[]="%$busqueda%"; $params[]="%$busqueda%"; }
if ($esVendedor && isset($_SESSION['vendedor_id'])) { $sql.=" AND v.vendedor_id=?"; $params[]=$_SESSION['vendedor_id']; }
$sql .= " ORDER BY v.fecha DESC, v.id DESC";

$ventas     = dbQuery($sql, $params);
$vendedores = dbQuery("SELECT id,nombre FROM vendedores WHERE activo=1 ORDER BY nombre");

// Conteos para badges de tabs
// Conteos para badges de tabs (misma secuencia y fechas)
$cWhere  = "1=1";
$cParams = [];
if ($filtroSec) { $cWhere .= " AND secuencia_id=?"; $cParams[] = $filtroSec; }
if ($fechaIni)  { $cWhere .= " AND fecha>=?";       $cParams[] = $fechaIni; }
if ($fechaFin)  { $cWhere .= " AND fecha<=?";       $cParams[] = $fechaFin; }
$conteos = dbQueryOne(
    "SELECT
        SUM(tipo_doc IN ('cotizacion','despacho') AND estado!='anulado' AND {$cWhere}) AS des,
        SUM(tipo_doc='venta' AND estado!='anulado' AND {$cWhere}) AS fac
     FROM ventas",
    array_merge($cParams, $cParams)
);
$cntDes = (int)($conteos['des'] ?? 0);
$cntFac = (int)($conteos['fac'] ?? 0);

// Totales del tab actual
$totalTab   = array_sum(array_column(array_filter($ventas,fn($v)=>$v['estado']!=='anulado'),'total_usd'));
$pendienteTab = array_sum(array_map(fn($v)=>in_array($v['estado'],['pendiente','parcial'])?$v['saldo_usd']:0,$ventas));

include 'includes/header.php';
?>
<style>
/* ── CTA Buttons ───────────────────────────────────── */
.cta-wrap { display:flex; gap:14px; margin-bottom:24px; }
.cta-btn {
    display:flex; align-items:center; gap:12px; padding:16px 28px;
    border-radius:12px; text-decoration:none; font-weight:800; font-size:15px;
    transition:all .2s; border:none; cursor:pointer; flex:1; justify-content:center;
    box-shadow:0 4px 14px rgba(0,0,0,.13);
}
.cta-btn:hover { transform:translateY(-2px); box-shadow:0 6px 20px rgba(0,0,0,.18); }
.cta-btn-des { background:linear-gradient(135deg,#2980b9,#1a5276); color:white; }
.cta-btn-fac { background:linear-gradient(135deg,#27ae60,#1a7a42); color:white; }
.cta-btn i   { font-size:22px; opacity:.9; }
.cta-btn small { font-size:11px; font-weight:500; opacity:.82; display:block; margin-top:2px; }

/* ── Tabs ──────────────────────────────────────────── */
.doc-tabs { display:flex; gap:0; border-bottom:3px solid var(--border); margin-bottom:20px; }
.doc-tab  { display:flex; align-items:center; gap:8px; padding:12px 26px; text-decoration:none;
            font-weight:700; font-size:14px; border-bottom:3px solid transparent;
            margin-bottom:-3px; color:var(--text-muted); transition:all .2s; background:transparent;
            border-top:none; border-left:none; border-right:none; cursor:pointer; }
.doc-tab:hover { color:var(--text); }
.doc-tab.active-des { color:#2980b9; border-bottom-color:#2980b9; background:rgba(41,128,185,.04); }
.doc-tab.active-fac { color:#27ae60; border-bottom-color:#27ae60; background:rgba(39,174,96,.04); }
.tab-badge { border-radius:20px; padding:2px 9px; font-size:11px; font-weight:800; color:white; }

/* ── Tabla ─────────────────────────────────────────── */
.doc-row { cursor:pointer; transition:background .15s; }
.doc-row:hover { background:#f0f7ff !important; }
.doc-row.expanded { background:#f0f7ff !important; }
.expand-icon { color:var(--text-muted); transition:transform .2s; font-size:12px; margin-right:4px; }
.expanded .expand-icon { transform:rotate(90deg); color:var(--primary); }

/* ── Fila expandida ────────────────────────────────── */
.detail-row td { padding:0 !important; border-bottom:2px solid var(--primary) !important; }
.detail-inner {
    background:linear-gradient(135deg,#f8fafc,#eef4fb);
    border-left:4px solid var(--primary);
    padding:16px 20px 14px;
    display:grid; grid-template-columns:repeat(4,1fr) auto;
    gap:14px; align-items:center;
}
.detail-kv { text-align:center; }
.detail-kv .label { font-size:10px; font-weight:700; text-transform:uppercase; letter-spacing:.5px; color:var(--text-muted); }
.detail-kv .value { font-size:15px; font-weight:800; margin-top:3px; }
.detail-kv .sub   { font-size:10.5px; color:var(--text-muted); margin-top:1px; }
.detail-actions   { display:flex; flex-direction:column; gap:6px; }
</style>

<?php if (hasPermission('ventas','crear')): ?>
<!-- ── BOTONES CTA GRANDES ── -->
<div class="cta-wrap">
    <a href="nueva_venta.php?tipo=despacho" class="cta-btn cta-btn-des">
        <i class="fa-solid fa-truck-fast"></i>
        <div>
            <div>Nuevo Despacho</div>
            <small>Sin afectar inventario · Editable</small>
        </div>
    </a>
    <a href="nueva_venta.php?tipo=venta" class="cta-btn cta-btn-fac">
        <i class="fa-solid fa-file-invoice-dollar"></i>
        <div>
            <div>Nueva Factura</div>
            <small>Descuenta inventario · Documento final</small>
        </div>
    </a>
</div>
<?php endif; ?>

<!-- ── STATS ── -->
<div class="row mb-4">
    <div class="col-3">
        <div class="stat-card blue">
            <div class="stat-icon"><i class="fa-solid fa-truck-fast"></i></div>
            <div><div class="stat-value"><?= $cntDes ?></div><div class="stat-label">Despachos activos</div></div>
        </div>
    </div>
    <div class="col-3">
        <div class="stat-card green">
            <div class="stat-icon"><i class="fa-solid fa-file-invoice-dollar"></i></div>
            <div><div class="stat-value"><?= $cntFac ?></div><div class="stat-label">Facturas emitidas</div></div>
        </div>
    </div>
    <div class="col-3">
        <div class="stat-card">
            <div class="stat-icon"><i class="fa-solid fa-dollar-sign"></i></div>
            <div><div class="stat-value"><?= formatUSD($totalTab) ?></div><div class="stat-label">Total tab actual</div></div>
        </div>
    </div>
    <div class="col-3">
        <div class="stat-card orange">
            <div class="stat-icon"><i class="fa-solid fa-hourglass-half"></i></div>
            <div><div class="stat-value"><?= formatUSD($pendienteTab) ?></div><div class="stat-label">Por cobrar</div></div>
        </div>
    </div>
</div>

<!-- ── TABS ── -->
<div class="doc-tabs">
    <a href="documentos.php?tab=despacho&secuencia=<?= $filtroSec ?>&fecha_ini=<?= urlencode($fechaIni) ?>&fecha_fin=<?= urlencode($fechaFin) ?>"
       class="doc-tab <?= $tab==='despacho'?'active-des':'' ?>">
        <i class="fa-solid fa-truck-fast"></i>
        Despacho
        <span class="tab-badge" style="background:#2980b9"><?= $cntDes ?></span>
    </a>
    <a href="documentos.php?tab=venta&secuencia=<?= $filtroSec ?>&fecha_ini=<?= urlencode($fechaIni) ?>&fecha_fin=<?= urlencode($fechaFin) ?>"
       class="doc-tab <?= $tab==='venta'?'active-fac':'' ?>">
        <i class="fa-solid fa-file-invoice-dollar"></i>
        Factura
        <span class="tab-badge" style="background:#27ae60"><?= $cntFac ?></span>
    </a>
    <div style="flex:1"></div>
    <?php if ($filtroSec): foreach ($secuencias as $s): if ((int)$s['id'] !== $filtroSec) continue; ?>
        <span class="badge" style="background:#eef4fb;color:#2980b9;align-self:center;font-size:12px">
            <i class="fa-solid fa-layer-group"></i> <?= clean($s['nombre']) ?>
            <?= $s['estado']==='activa' ? ' · activa' : '' ?>
        </span>
    <?php endforeach; endif; ?>
</div>

<!-- ── FILTROS ── -->
<div class="card mb-3">
<div class="card-body" style="padding:10px 16px">
<form method="GET" class="d-flex gap-2 align-center" style="flex-wrap:wrap">
    <input type="hidden" name="tab" value="<?= $tab ?>">
    <i class="fa-solid fa-magnifying-glass text-muted"></i>
    <input type="text" name="q" class="form-control" placeholder="N° doc, cliente, RIF..." value="<?= $busqueda ?>" style="max-width:200px">
    <select name="secuencia" class="form-select" style="max-width:230px" title="Secuencia">
        <option value="0">Todas las secuencias</option>
        <?php foreach($secuencias as $s): ?>
        <option value="<?= (int)$s['id'] ?>" <?= $filtroSec===(int)$s['id']?'selected':'' ?>>
            <?= clean($s['nombre']) ?><?= $s['estado']==='activa' ? ' (activa)' : '' ?>
        </option>
        <?php endforeach; ?>
    </select>
    <input type="date" name="fecha_ini" class="form-control" value="<?= $fechaIni ?>" style="max-width:148px" title="Desde (opcional)">
    <span class="text-muted">a</span>
    <input type="date" name="fecha_fin" class="form-control" value="<?= $fechaFin ?>" style="max-width:148px" title="Hasta (opcional)">
    <?php if ($tab==='venta'): ?>
    <select name="estado" class="form-select" style="max-width:130px">
        <option value="">Todos</option>
        <?php foreach(['pendiente','parcial','pagado','anulado'] as $e): ?>
        <option value="<?= $e ?>" <?= $filtroEstado===$e?'selected':'' ?>><?= ucfirst($e) ?></option>
        <?php endforeach; ?>
    </select>
    <?php endif; ?>
    <select name="tipo_cli" class="form-select" style="max-width:130px">
        <option value="">Ambos</option>
        <option value="mayorista" <?= $filtroTipoCli==='mayorista'?'selected':'' ?>>Mayorista</option>
        <option value="detalista" <?= $filtroTipoCli==='detalista'?'selected':'' ?>>Detalista</option>
    </select>
    <?php if (!$esVendedor): ?>
    <select name="vendedor" class="form-select" style="max-width:150px">
        <option value="">Todos vendedores</option>
        <?php foreach($vendedores as $vd): ?>
        <option value="<?= $vd['id'] ?>" <?= $filtroVendedor==$vd['id']?'selected':'' ?>><?= clean($vd['nombre']) ?></option>
        <?php endforeach; ?>
    </select>
    <?php endif; ?>
    <button type="submit" class="btn btn-primary btn-sm"><i class="fa-solid fa-filter"></i> Filtrar</button>
    <a href="documentos.php?tab=<?= $tab ?>" class="btn btn-light btn-sm"><i class="fa-solid fa-xmark"></i></a>
</form>
</div>
</div>

<!-- ── TABLA ── -->
<div class="card">
<div class="card-header">
    <i class="fa-solid <?= $tab==='despacho'?'fa-truck-fast':'fa-file-invoice-dollar' ?>"
       style="color:<?= $tab==='despacho'?'#2980b9':'#27ae60' ?>"></i>
    <h5><?= $tab==='despacho'?'Despachos':'Facturas' ?> — <?= count($ventas) ?> registros</h5>
    <div class="d-flex gap-2" style="margin-left:auto">
        <span class="text-muted fs-12" style="align-self:center">
            <i class="fa-solid fa-hand-pointer" style="color:var(--warning)"></i>
            Clic en fila para ver detalle
        </span>
        <button onclick="exportExcel('tablaDoc','<?= $tab==='despacho'?'Despachos':'Facturas' ?>_Tauro')" class="btn btn-sm btn-light">
            <i class="fa-solid fa-table-cells"></i> Excel
        </button>
        <?php if (hasPermission('ventas','crear')): ?>
        <a href="nueva_venta.php?tipo=<?= $tab==='despacho'?'despacho':'venta' ?>"
           class="btn btn-sm" style="background:<?= $tab==='despacho'?'#2980b9':'#27ae60' ?>;color:white">
            <i class="fa-solid fa-plus"></i>
            <?= $tab==='despacho'?'Nuevo Despacho':'Nueva Factura' ?>
        </a>
        <?php endif; ?>
    </div>
</div>
<div class="card-body p-0">
<div class="table-wrapper">
<table id="tablaDoc" style="width:100%;border-collapse:collapse;font-size:13px">
    <thead>
        <tr style="background:#f8fafc">
            <th style="padding:10px 10px;font-size:10px;font-weight:700;text-transform:uppercase;color:var(--text-muted);border-bottom:2px solid var(--border);width:28px"></th>
            <th style="padding:10px 10px;font-size:10px;font-weight:700;text-transform:uppercase;color:var(--text-muted);border-bottom:2px solid var(--border)">N° Doc.</th>
            <th style="padding:10px 10px;font-size:10px;font-weight:700;text-transform:uppercase;color:var(--text-muted);border-bottom:2px solid var(--border)">Tipo</th>
            <th style="padding:10px 10px;font-size:10px;font-weight:700;text-transform:uppercase;color:var(--text-muted);border-bottom:2px solid var(--border)">Fecha</th>
            <th style="padding:10px 10px;font-size:10px;font-weight:700;text-transform:uppercase;color:var(--text-muted);border-bottom:2px solid var(--border)">Cliente</th>
            <th style="padding:10px 10px;font-size:10px;font-weight:700;text-transform:uppercase;color:var(--text-muted);border-bottom:2px solid var(--border)">Vendedor</th>
            <th style="padding:10px 10px;font-size:10px;font-weight:700;text-transform:uppercase;color:var(--text-muted);border-bottom:2px solid var(--border)">Cli. Tipo</th>
            <th style="padding:10px 10px;font-size:10px;font-weight:700;text-transform:uppercase;color:var(--text-muted);border-bottom:2px solid var(--border)">Pago</th>
            <th style="padding:10px 10px;font-size:10px;font-weight:700;text-transform:uppercase;color:var(--text-muted);border-bottom:2px solid var(--border);text-align:right">Total $</th>
            <th style="padding:10px 10px;font-size:10px;font-weight:700;text-transform:uppercase;color:var(--text-muted);border-bottom:2px solid var(--border)">Acciones</th>
        </tr>
    </thead>
    <tbody>
    <?php if(empty($ventas)): ?>
    <tr><td colspan="10" style="text-align:center;padding:48px;color:var(--text-muted)">
        <i class="fa-solid <?= $tab==='despacho'?'fa-truck-fast':'fa-file-invoice-dollar' ?>" style="font-size:40px;opacity:.2;display:block;margin-bottom:12px"></i>
        Sin <?= $tab==='despacho'?'despachos':'facturas' ?> en el período.<br>
        <a href="nueva_venta.php?tipo=<?= $tab==='despacho'?'despacho':'venta' ?>"
           style="color:<?= $tab==='despacho'?'#2980b9':'#27ae60' ?>;font-weight:700;margin-top:8px;display:inline-block">
            + Crear <?= $tab==='despacho'?'Despacho':'Factura' ?>
        </a>
    </td></tr>
    <?php else: $paletaVend = ['#c0392b','#2980b9','#27ae60','#8e44ad','#d35400','#16a085','#2c3e50','#e67e22']; foreach($ventas as $v):
        $colVend = $paletaVend[((int)($v['vendedor_id'] ?? 0)) % count($paletaVend)];
        // Datos para la fila expandible (data attributes)
        $pagadoFmt  = formatUSD((float)$v['monto_pagado_usd']);
        $saldoFmt   = formatUSD((float)$v['saldo_usd']);
        $tsBs       = number_format((float)$v['tasa_valor'],2,',','.');
        $fVenc      = $v['fecha_vencimiento'] ? date('d/m/Y',strtotime($v['fecha_vencimiento'])) : '—';
        $notas      = clean($v['notas'] ?? '');
        $seqNombre  = clean($v['secuencia_nombre'] ?? '—');
        $esAnulado  = $v['estado']==='anulado';
    ?>
    <tr class="doc-row <?= $esAnulado?'':'hoverable' ?>"
        style="border-bottom:1px solid var(--border);border-left:4px solid <?= $colVend ?>;<?= $esAnulado?'opacity:.45':''; ?>"
        data-id="<?= $v['id'] ?>"
        data-pagado="<?= $pagadoFmt ?>"
        data-saldo="<?= $saldoFmt ?>"
        data-estado="<?= $v['estado'] ?>"
        data-tipo="<?= $v['tipo_doc'] ?>"
        data-tasa="Bs. <?= $tsBs ?> / $"
        data-venc="<?= $fVenc ?>"
        data-notas="<?= htmlspecialchars($notas) ?>"
        data-seq="<?= $seqNombre ?>">

        <td style="padding:10px 8px 10px 12px">
            <span class="expand-icon"><i class="fa-solid fa-chevron-right"></i></span>
        </td>
        <td style="padding:10px 10px"><strong class="font-mono fs-12"><?= clean($v['numero']) ?></strong></td>
        <td style="padding:10px 10px"><?= badgeDoc($v['tipo_doc']) ?></td>
        <td style="padding:10px 10px;font-size:12px"><?= date('d/m/Y',strtotime($v['fecha'])) ?></td>
        <td style="padding:10px 10px">
            <strong class="fs-13"><?= clean($v['cliente_nombre']) ?></strong>
            <?php if($v['cliente_rif']): ?><br><small class="text-muted font-mono"><?= clean($v['cliente_rif']) ?></small><?php endif; ?>
        </td>
        <td style="padding:10px 10px;font-size:12px">
            <?php if (!empty($v['vendedor_nombre'])): ?>
            <span style="display:inline-block;padding:2px 8px;border-radius:10px;font-weight:700;
                         background:<?= $colVend ?>1f;color:<?= $colVend ?>">
                <?= clean($v['vendedor_nombre']) ?><?= !empty($v['zona_nombre']) ? ' ('.clean($v['zona_nombre']).')' : '' ?>
            </span>
            <?php else: ?>—<?php endif; ?>
        </td>
        <td style="padding:10px 10px"><?= badgeTipo($v['tipo_cliente']) ?></td>
        <td style="padding:10px 10px">
            <span class="badge <?= $v['tipo_pago']==='credito'?'badge-info':'badge-secondary' ?>">
                <?= ucfirst($v['tipo_pago']) ?>
            </span>
        </td>
        <td style="padding:10px 10px;text-align:right;font-weight:800;font-size:14px">
            <?= formatUSD($v['total_usd']) ?>
        </td>
        <!-- SIEMPRE 1 <td> de acciones → column count exacto = 10 -->
        <td style="padding:10px 10px" onclick="event.stopPropagation()">
            <div class="d-flex gap-1">
                <a href="ver_venta.php?id=<?= $v['id'] ?>" class="btn btn-sm btn-light btn-icon" title="Ver documento">
                    <i class="fa-solid fa-eye"></i>
                </a>
                <?php if(in_array($v['tipo_doc'],['cotizacion','despacho']) && !$esAnulado && hasPermission('ventas','crear')): ?>
                <button onclick="openConv(<?= $v['id'] ?>,'<?= clean($v['numero']) ?>','<?= $v['tipo_doc'] ?>')"
                    class="btn btn-sm btn-warning btn-icon" title="Convertir a Factura">
                    <i class="fa-solid fa-file-invoice-dollar"></i>
                </button>
                <?php endif; ?>
                <?php if(in_array($v['estado'],['pendiente','parcial']) && $v['tipo_doc']==='venta' && hasPermission('pagos','crear')): ?>
                <a href="pagos.php?venta_id=<?= $v['id'] ?>" class="btn btn-sm btn-success btn-icon" title="Registrar cobro">
                    <i class="fa-solid fa-money-bill-wave"></i>
                </a>
                <?php endif; ?>
                <?php if(!$esAnulado && hasPermission('ventas','anular')): ?>
                <a href="documentos.php?tab=<?= $tab ?>&anular=<?= $v['id'] ?>&<?= CSRF_TOKEN_NAME ?>=<?= getCsrfToken() ?>"
                   onclick="return confirm('¿Anular <?= clean($v['numero']) ?>? No se puede deshacer.')"
                   class="btn btn-sm btn-danger btn-icon" title="Anular">
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

<!-- MODAL: Convertir a Factura -->
<div class="modal-overlay" id="modalConv">
<div class="modal-box" style="max-width:420px">
    <div class="modal-header">
        <h4><i class="fa-solid fa-file-invoice-dollar"></i> Convertir a Factura</h4>
        <button class="btn-close" onclick="closeModal('modalConv')"><i class="fa-solid fa-xmark"></i></button>
    </div>
    <form method="POST">
    <div class="modal-body">
        <input type="hidden" name="<?= CSRF_TOKEN_NAME ?>" value="<?= getCsrfToken() ?>">
        <input type="hidden" name="convertir" value="1">
        <input type="hidden" name="venta_id" id="convVentaId">
        <input type="hidden" name="nuevo_tipo" value="venta">

        <p style="margin-bottom:14px;font-size:13px">
            Documento: <strong id="convNumero" class="font-mono"></strong>
            <span id="convBadge" style="margin-left:6px"></span>
        </p>

        <div style="background:#fff8e1;border:1px solid #fde68a;border-radius:8px;padding:12px 14px;margin-bottom:16px;font-size:13px">
            <i class="fa-solid fa-triangle-exclamation" style="color:#f59e0b"></i>
            Al convertir a <strong>Factura</strong> el inventario será descontado automáticamente. Esta acción no se puede revertir.
        </div>

        <div style="text-align:center;padding:18px;border:2px solid #27ae60;border-radius:10px;background:#f0fdf4">
            <i class="fa-solid fa-file-invoice-dollar" style="font-size:36px;color:#27ae60"></i>
            <div style="font-weight:800;font-size:16px;margin-top:8px;color:#27ae60">Convertir a Factura</div>
            <div style="font-size:12px;color:var(--text-muted);margin-top:4px">El stock será descontado del inventario</div>
        </div>
    </div>
    <div class="modal-footer">
        <button type="button" onclick="closeModal('modalConv')" class="btn btn-light">Cancelar</button>
        <button type="submit" class="btn btn-success" onclick="return confirm('¿Confirmas la conversión a Factura?')">
            <i class="fa-solid fa-check"></i> Confirmar
        </button>
    </div>
    </form>
</div>
</div>

<?php
$tabColor = $tab==='despacho' ? '#2980b9' : '#27ae60';

$extraScript = <<<JS
<script>
// ── DataTable ────────────────────────────────────────
var table;
\$(document).ready(function() {
    if (\$.fn.DataTable.isDataTable('#tablaDoc')) \$('#tablaDoc').DataTable().destroy();
    table = \$('#tablaDoc').DataTable({
        language: dtLang,
        pageLength: 25,
        order: [[3,'desc']],
        columnDefs: [
            { targets: 0,  orderable: false, searchable: false },
            { targets: 9,  orderable: false, searchable: false },
            { targets: '_all', defaultContent: '' }
        ]
    });

    // ── Fila expandible ───────────────────────────────
    \$('#tablaDoc tbody').on('click', 'tr.doc-row', function(e) {
        if (\$(e.target).closest('td:last-child').length) return; // ignorar clic en acciones

        var tr  = \$(this);
        var row = table.row(tr);

        if (row.child.isShown()) {
            row.child.hide();
            tr.removeClass('expanded');
        } else {
            // Colapsar otros
            \$('#tablaDoc tbody tr.expanded').each(function() {
                table.row(\$(this)).child.hide();
                \$(this).removeClass('expanded');
            });
            // Construir contenido del detalle
            var d = {
                pagado: tr.data('pagado'),
                saldo:  tr.data('saldo'),
                estado: tr.data('estado'),
                tipo:   tr.data('tipo'),
                tasa:   tr.data('tasa'),
                venc:   tr.data('venc'),
                notas:  tr.data('notas'),
                seq:    tr.data('seq'),
                id:     tr.data('id'),
            };
            var estadoColor = { pagado:'var(--success)', parcial:'var(--info)', pendiente:'var(--warning)', anulado:'var(--danger)' };
            var html = \`
                <div class="detail-inner">
                    <div class="detail-kv">
                        <div class="label">Estado</div>
                        <div class="value" style="color:\${estadoColor[d.estado]||'var(--text)'}">\${d.estado.charAt(0).toUpperCase()+d.estado.slice(1)}</div>
                    </div>
                    <div class="detail-kv">
                        <div class="label">Monto Pagado</div>
                        <div class="value" style="color:var(--success)">\${d.pagado}</div>
                    </div>
                    <div class="detail-kv">
                        <div class="label">Saldo Pendiente</div>
                        <div class="value" style="color:\${parseFloat(d.saldo.replace(/[^0-9.]/g,''))>0?'var(--danger)':'var(--success)'}">\${d.saldo}</div>
                    </div>
                    <div class="detail-kv">
                        <div class="label">Tasa de cambio</div>
                        <div class="value" style="font-size:12px">\${d.tasa}</div>
                        <div class="sub">Vence: \${d.venc}</div>
                    </div>
                    <div class="detail-actions">
                        <a href="ver_venta.php?id=\${d.id}" class="btn btn-primary btn-sm" style="white-space:nowrap">
                            <i class="fa-solid fa-eye"></i> Ver documento
                        </a>
                        <a href="imprimir.php?id=\${d.id}&template=\${d.tipo}&moneda=USD" target="_blank" class="btn btn-light btn-sm" style="white-space:nowrap">
                            <i class="fa-solid fa-print"></i> Imprimir USD
                        </a>
                        <a href="imprimir.php?id=\${d.id}&template=\${d.tipo}&moneda=BS" target="_blank" class="btn btn-light btn-sm" style="white-space:nowrap">
                            <i class="fa-solid fa-print"></i> Imprimir Bs.
                        </a>
                    </div>
                </div>
                \${d.notas ? \`<div style="background:#fffbeb;border-left:4px solid #fde68a;padding:8px 16px;font-size:12px"><i class="fa-solid fa-note-sticky" style="color:#f59e0b"></i> <b>Nota:</b> \${d.notas}</div>\` : ''}
            \`;
            row.child(html).show();
            tr.addClass('expanded');
        }
    });
});

// ── Modal convertir ───────────────────────────────────
function openConv(id, numero, tipo) {
    document.getElementById('convVentaId').value = id;
    document.getElementById('convNumero').textContent = numero;
    const badges = { cotizacion:'<span class="badge badge-info">Despacho</span>', despacho:'<span class="badge badge-info">Despacho</span>' };
    document.getElementById('convBadge').innerHTML = badges[tipo] || '';
    openModal('modalConv');
}
</script>
JS;

include 'includes/footer.php';
?>
