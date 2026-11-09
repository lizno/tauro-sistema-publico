<?php
require_once 'config/config.php';
require_once 'functions/db.php';
require_once 'functions/helpers.php';
require_once 'functions/auth.php';

requireLogin();
requirePermission('pagos','ver');
$pageTitle = 'Cobranza por Vendedor';

$comDetal   = (float)getConfig('comision_pct_detalista','10.00');
$comMayor   = (float)getConfig('comision_pct_mayorista','7.00');
$TIPOS_PAGO = ['efectivo','transferencia','punto','zelle','binance','wally','zinli','otro'];

// ID de Jhon — protegido en todas las acciones POST
define('JHON_VID', 4);

// ── CREAR SECUENCIA ───────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action']??'')==='crear_secuencia') {
    verifyCsrf();
    requirePermission('pagos','crear');
    $nombre   = clean($_POST['nombre'] ?? '');
    $fechaIni = $_POST['fecha_ini'] ?? date('Y-m-d');
    $fechaFin = !empty($_POST['fecha_fin']) ? $_POST['fecha_fin'] : null;
    $notas    = clean($_POST['notas'] ?? '');
    if (!$nombre) { $_SESSION['flash']=['type'=>'error','msg'=>'El nombre es requerido.']; header('Location: pagos.php'); exit; }
    dbExecute("INSERT INTO secuencias (nombre,fecha_ini,fecha_fin,notas,usuario_id) VALUES (?,?,?,?,?)",
        [$nombre,$fechaIni,$fechaFin,$notas,$_SESSION['user_id']]);
    auditLog('INSERT','secuencias',(int)dbLastId(),"Secuencia creada: $nombre");
    $_SESSION['flash']=['type'=>'success','msg'=>"Secuencia \"$nombre\" creada."];
    header('Location: pagos.php'); exit;
}

// ── EDITAR SECUENCIA ──────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action']??'')==='editar_secuencia') {
    verifyCsrf();
    requirePermission('pagos','editar');
    $sid      = cleanInt($_POST['sec_id'] ?? 0);
    $nombre   = clean($_POST['nombre'] ?? '');
    $fechaIni = $_POST['fecha_ini'] ?? date('Y-m-d');
    $fechaFin = !empty($_POST['fecha_fin']) ? $_POST['fecha_fin'] : null;
    $notas    = clean($_POST['notas'] ?? '');
    $estado   = in_array($_POST['estado']??'',['activa','cerrada']) ? $_POST['estado'] : 'activa';
    if (!$nombre || !$sid) { $_SESSION['flash']=['type'=>'error','msg'=>'Datos incompletos.']; header("Location: pagos.php?sec=$sid"); exit; }
    dbExecute("UPDATE secuencias SET nombre=?,fecha_ini=?,fecha_fin=?,notas=?,estado=? WHERE id=?",
        [$nombre,$fechaIni,$fechaFin,$notas,$estado,$sid]);
    auditLog('UPDATE','secuencias',$sid,"Secuencia editada: $nombre estado=$estado");
    $_SESSION['flash']=['type'=>'success','msg'=>"Secuencia actualizada."];
    header("Location: pagos.php?sec=$sid"); exit;
}

// ── REGISTRAR PAGO A VENDEDOR ─────────────────────────────────
if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action']??'')==='registrar_pago') {
    verifyCsrf();
    requirePermission('pagos','crear');
    $vendedorId = cleanInt($_POST['vendedor_id'] ?? 0);
    // Bloqueo servidor: Jhon solo se cobra desde jhon_cobranza.php
    if ($vendedorId === JHON_VID) {
        $_SESSION['flash'] = ['type'=>'warning',
            'msg'=>'Los cobros de Jhon Jairo Jiménez se gestionan desde <a href="jhon_cobranza.php">Cobros Jhon ⭐</a>.'];
        header("Location: pagos.php?sec=".cleanInt($_POST['secuencia_id'])."&vendedor=".JHON_VID); exit;
    }
    $secuenciaId = cleanInt($_POST['secuencia_id'] ?? 0);
    $monto       = cleanFloat($_POST['monto'] ?? 0);
    $tipo        = in_array($_POST['tipo']??'',$TIPOS_PAGO) ? $_POST['tipo'] : 'efectivo';
    $referencia  = clean($_POST['referencia'] ?? '');
    $fecha       = $_POST['fecha'] ?? date('Y-m-d');
    $descripcion = clean($_POST['descripcion'] ?? '');
    $moneda      = in_array($_POST['moneda']??'',['USD','BS']) ? $_POST['moneda'] : 'USD';
    $tasaCobro   = $moneda==='BS' ? cleanFloat($_POST['tasa_cobro'] ?? 0) : null;
    $montoBs     = ($moneda==='BS' && $tasaCobro > 0) ? round($monto * $tasaCobro, 2) : null;
    if (!$vendedorId || !$secuenciaId || $monto<=0) {
        $_SESSION['flash']=['type'=>'error','msg'=>'Datos incompletos.'];
        header("Location: pagos.php?sec=$secuenciaId&vendedor=$vendedorId"); exit;
    }
    dbExecute("INSERT INTO pagos_vendedor (vendedor_id,secuencia_id,fecha,monto_usd,tipo,referencia,descripcion,moneda,tasa_cobro,monto_bs,usuario_id) VALUES (?,?,?,?,?,?,?,?,?,?,?)",
        [$vendedorId,$secuenciaId,$fecha,$monto,$tipo,$referencia,$descripcion,$moneda,$tasaCobro,$montoBs,$_SESSION['user_id']]);
    auditLog('INSERT','pagos_vendedor',(int)dbLastId(),"Pago al vendedor #$vendedorId: $monto USD");
    $_SESSION['flash']=['type'=>'success','msg'=>'Pago registrado.'];
    header("Location: pagos.php?sec=$secuenciaId&vendedor=$vendedorId"); exit;
}

// ── PALOMITA: CLIENTE PAGÓ ────────────────────────────────────
if (isset($_GET['toggle_pago_cli']) && hasPermission('pagos','crear')) {
    $vid   = cleanInt($_GET['toggle_pago_cli']);
    $venta = dbQueryOne("SELECT id, cancelado_manual, vendedor_id FROM ventas WHERE id=?", [$vid]);
    // Bloqueo: no marcar facturas de Jhon desde aquí
    if ($venta && (int)$venta['vendedor_id'] === JHON_VID) {
        $_SESSION['flash'] = ['type'=>'warning',
            'msg'=>'Las facturas de Jhon se gestionan desde <a href="jhon_cobranza.php">Cobros Jhon ⭐</a>.'];
        header("Location: pagos.php?sec={$_GET['sec']}&vendedor={$_GET['vendedor']}"); exit;
    }
    if ($venta) {
        $nuevo = $venta['cancelado_manual'] ? 0 : 1;
        dbExecute("UPDATE ventas SET cancelado_manual=? WHERE id=?",[$nuevo,$vid]);
    }
    header("Location: pagos.php?sec={$_GET['sec']}&vendedor={$_GET['vendedor']}"); exit;
}

// ── GUARDAR OBSERVACIÓN ───────────────────────────────────────
if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action']??'')==='save_obs') {
    verifyCsrf();
    $vid = cleanInt($_POST['venta_id'] ?? 0);
    $obs = clean($_POST['obs'] ?? '');
    if ($vid) dbExecute("UPDATE ventas SET obs_cobranza=? WHERE id=?",[$obs,$vid]);
    header("Location: pagos.php?sec={$_POST['sec']}&vendedor={$_POST['vendedor']}"); exit;
}

// ── COMISIÓN AL VENDEDOR ──────────────────────────────────────
if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action']??'')==='pagar_comision') {
    verifyCsrf();
    requirePermission('pagos','crear');
    $vendedorId  = cleanInt($_POST['vendedor_id'] ?? 0);
    // Bloqueo: Jhon usa su módulo
    if ($vendedorId === JHON_VID) {
        $_SESSION['flash'] = ['type'=>'warning',
            'msg'=>'Los cobros de Jhon se gestionan desde <a href="jhon_cobranza.php">Cobros Jhon ⭐</a>.'];
        header("Location: pagos.php?sec=".cleanInt($_POST['secuencia_id'])."&vendedor=".JHON_VID); exit;
    }
    $secuenciaId = cleanInt($_POST['secuencia_id'] ?? 0);
    $monto       = cleanFloat($_POST['monto'] ?? 0);
    $notas       = clean($_POST['notas'] ?? '');
    $fecha       = $_POST['fecha'] ?? date('Y-m-d');
    if ($vendedorId && $secuenciaId && $monto > 0) {
        dbExecute("INSERT INTO comisiones_vendedor (vendedor_id,secuencia_id,fecha,monto_usd,notas,usuario_id) VALUES (?,?,?,?,?,?)",
            [$vendedorId,$secuenciaId,$fecha,$monto,$notas,$_SESSION['user_id']]);
        auditLog('INSERT','comisiones_vendedor',(int)dbLastId(),"Comisión pagada al vendedor #$vendedorId: $monto USD");
        $_SESSION['flash']=['type'=>'success','msg'=>'Comisión registrada.'];
    }
    header("Location: pagos.php?sec=$secuenciaId&vendedor=$vendedorId"); exit;
}

// ── DATOS PRINCIPALES ─────────────────────────────────────────
$secActiva   = cleanInt($_GET['sec'] ?? 0);
$vendedorSel = cleanInt($_GET['vendedor'] ?? 0);
$vistaRep    = isset($_GET['reporte']);
$esJhon      = ($vendedorSel === JHON_VID); // ← flag clave

$secuencias = dbQuery("SELECT * FROM secuencias ORDER BY fecha_ini DESC");
$secData    = $secActiva ? dbQueryOne("SELECT * FROM secuencias WHERE id=?",[$secActiva]) : null;

// ── RESUMEN VENDEDORES ────────────────────────────────────────
$resumenVendedores = [];
if ($secData) {
    $rows = dbQuery(
        "SELECT v.vendedor_id, vd.nombre vendedor_nombre, v.tipo_cliente,
                SUM(v.total_usd) total_ventas
         FROM ventas v
         JOIN vendedores vd ON v.vendedor_id=vd.id
         WHERE v.secuencia_id=? AND v.estado!='anulado' AND v.tipo_doc='venta'
         GROUP BY v.vendedor_id, v.tipo_cliente",
        [$secActiva]
    );
    $vendMap = [];
    foreach ($rows as $r) {
        $vid = $r['vendedor_id'];
        if (!isset($vendMap[$vid]))
            $vendMap[$vid] = ['vendedor_id'=>$vid,'nombre'=>$r['vendedor_nombre'],
                'total_detal'=>0,'total_mayor'=>0,'com_detal'=>0,'com_mayor'=>0];
        if ($r['tipo_cliente']==='detalista') {
            $vendMap[$vid]['total_detal'] += $r['total_ventas'];
            $vendMap[$vid]['com_detal']   += round($r['total_ventas']*$comDetal/100,2);
        } else {
            $vendMap[$vid]['total_mayor'] += $r['total_ventas'];
            $vendMap[$vid]['com_mayor']   += round($r['total_ventas']*$comMayor/100,2);
        }
    }
    $pagosRows = dbQuery("SELECT vendedor_id, SUM(monto_usd) total_pagado FROM pagos_vendedor WHERE secuencia_id=? GROUP BY vendedor_id",[$secActiva]);
    $pagosMap  = [];
    foreach ($pagosRows as $pr) $pagosMap[$pr['vendedor_id']] = $pr['total_pagado'];
    foreach ($vendMap as &$vd) {
        $vd['total_ventas'] = $vd['total_detal'] + $vd['total_mayor'];
        $vd['com_total']    = $vd['com_detal'] + $vd['com_mayor'];
        $vd['pagado']       = $pagosMap[$vd['vendedor_id']] ?? 0;
        // ✅ FIX: el restante es SOLO lo que falta cobrar de las facturas.
        // La comisión es la ganancia del vendedor y NO se resta del restante.
        $vd['restante']     = $vd['total_ventas'] - $vd['pagado'];
        $vd['es_jhon']      = ((int)$vd['vendedor_id'] === JHON_VID);
    }
    unset($vd);
    $resumenVendedores = array_values($vendMap);
}

// ── DETALLE VENDEDOR ──────────────────────────────────────────
$clientesDetalle = ['detalista'=>[],'mayorista'=>[]];
$pagosVendedor   = [];
$vendedorData    = null;
$statsVendedor   = null;
$comisionesPagadas = [];

if ($secData && $vendedorSel) {
    $vendedorData = dbQueryOne("SELECT * FROM vendedores WHERE id=?",[$vendedorSel]);
    foreach (['detalista','mayorista'] as $tipo) {
        $clientesDetalle[$tipo] = dbQuery(
            "SELECT v.id, v.numero, v.fecha, v.total_usd, v.monto_pagado_usd,
                    v.estado, v.cancelado_manual, v.obs_cobranza,
                    c.nombre c_nombre, c.rif c_rif, z.nombre zona_nombre
             FROM ventas v
             JOIN clientes c ON v.cliente_id=c.id
             LEFT JOIN zonas z ON c.zona_id=z.id
             WHERE v.vendedor_id=? AND v.secuencia_id=? AND v.tipo_cliente=?
               AND v.tipo_doc='venta' AND v.estado!='anulado'
             ORDER BY c.nombre ASC",
            [$vendedorSel,$secActiva,$tipo]
        );
    }
    $comisionesPagadas = dbQuery(
        "SELECT cv.*, u.nombre u_nombre FROM comisiones_vendedor cv
         LEFT JOIN usuarios u ON cv.usuario_id=u.id
         WHERE cv.vendedor_id=? AND cv.secuencia_id=? ORDER BY cv.fecha DESC",
        [$vendedorSel,$secActiva]
    );
    $totalComPagada = array_sum(array_column($comisionesPagadas,'monto_usd'));
    $pagosVendedor  = dbQuery(
        "SELECT pv.*, u.nombre u_nombre FROM pagos_vendedor pv
         LEFT JOIN usuarios u ON pv.usuario_id=u.id
         WHERE pv.vendedor_id=? AND pv.secuencia_id=? ORDER BY pv.fecha DESC",
        [$vendedorSel,$secActiva]
    );
    $totalDetal  = array_sum(array_column($clientesDetalle['detalista'],'total_usd'));
    $totalMayor  = array_sum(array_column($clientesDetalle['mayorista'],'total_usd'));
    $comDetalTot = round($totalDetal*$comDetal/100,2);
    $comMayorTot = round($totalMayor*$comMayor/100,2);
    $totalPagado = array_sum(array_column($pagosVendedor,'monto_usd'));
    $statsVendedor = [
        'total_ventas'=>$totalDetal+$totalMayor,
        'com_pagada'  =>$totalComPagada,
        'total_detal' =>$totalDetal,'total_mayor'=>$totalMayor,
        'com_detal'   =>$comDetalTot,'com_mayor'=>$comMayorTot,
        'com_total'   =>$comDetalTot+$comMayorTot,
        'pagado'      =>$totalPagado,
        // ✅ FIX: restante = total facturas - total cobrado (la comisión NO se resta)
        'restante'    =>($totalDetal+$totalMayor) - $totalPagado,
    ];
}

include 'includes/header.php';
?>
<style>
.sec-tabs{display:flex;gap:8px;flex-wrap:wrap;margin-bottom:16px}
.sec-tab{padding:7px 16px;border-radius:20px;border:2px solid var(--border);font-size:12px;font-weight:700;cursor:pointer;text-decoration:none;color:var(--text);transition:.15s}
.sec-tab:hover{border-color:var(--primary);color:var(--primary)}
.sec-tab.active{background:var(--primary);color:white;border-color:var(--primary)}
.sec-tab.cerrada{opacity:.6}
.vendedor-row{display:flex;align-items:center;gap:12px;padding:14px 18px;border-bottom:1px solid var(--border);cursor:pointer;transition:.15s;text-decoration:none}
.vendedor-row:hover{background:#f8fafc}
.vendedor-row.active{background:#fdf3f0;border-left:4px solid var(--primary)}
.vendedor-row.jhon-row{background:linear-gradient(90deg,#fdf6e3,#fff);border-left:4px solid gold}
.vendedor-row.jhon-row.active{background:linear-gradient(90deg,#fef9e7,#fffbeb);border-left:4px solid gold}
.vend-avatar{width:40px;height:40px;border-radius:50%;background:var(--primary);color:white;display:flex;align-items:center;justify-content:center;font-weight:800;font-size:14px;flex-shrink:0}
.vend-avatar.jhon{background:linear-gradient(135deg,#1a1a2e,#e74c3c);color:white}
.resumen-bar{display:grid;grid-template-columns:repeat(4,1fr);gap:10px;margin-bottom:16px}
.resumen-item{background:white;border:1px solid var(--border);border-radius:var(--radius);padding:16px;text-align:center}
.resumen-item.green{border-top:3px solid var(--success)}
.resumen-item.blue{border-top:3px solid var(--info)}
.resumen-item.red{border-top:3px solid var(--danger)}
.resumen-val{font-size:22px;font-weight:800;line-height:1.1}
.resumen-lab{font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:.5px;color:var(--text-muted);margin-top:4px}
.resumen-sub{font-size:11px;margin-top:6px;color:var(--text-muted)}
.seccion-tipo-header{display:flex;align-items:center;gap:10px;padding:10px 16px;font-weight:800;font-size:13px}
.seccion-tipo-header.detal{background:#fff3cd;color:#856404;border:1px solid #ffc107;border-radius:var(--radius) var(--radius) 0 0}
.seccion-tipo-header.mayor{background:#cff4fc;color:#0c5460;border:1px solid #0dcaf0;border-radius:var(--radius) var(--radius) 0 0}
.pago-chip{display:flex;align-items:center;gap:8px;background:#f8fafc;border-radius:8px;padding:8px 12px;margin-bottom:8px;border:1px solid var(--border)}
.split-layout{display:grid;grid-template-columns:300px 1fr;gap:20px}
@media(max-width:900px){.split-layout{grid-template-columns:1fr}}
.rep-table th{background:#1a1a2e;color:white;padding:9px 12px;font-size:11px;font-weight:700;text-transform:uppercase}
.rep-table td{padding:9px 12px;border-bottom:1px solid var(--border)}
/* Banner exclusivo Jhon */
.jhon-banner-detalle{background:linear-gradient(135deg,#1a1a2e,#16213e);color:white;border-radius:var(--radius);padding:20px 24px;margin-bottom:16px;display:flex;align-items:center;gap:16px}
.jhon-banner-detalle h4{margin:0;font-size:16px}
.jhon-banner-detalle p{margin:4px 0 0;font-size:12px;opacity:.75}
.jhon-lock-notice{background:#fffbeb;border:2px solid #ffc107;border-radius:var(--radius);padding:14px 18px;display:flex;align-items:center;gap:12px;margin-bottom:14px;font-size:13px}
</style>

<!-- BARRA DE SECUENCIAS -->
<div class="card mb-4">
    <div class="card-header" style="justify-content:space-between">
        <h5><i class="fa-solid fa-layer-group text-primary"></i> Secuencias de Despacho</h5>
        <div class="d-flex gap-2">
            <?php if($secData && hasPermission('pagos','editar')): ?>
            <button onclick="openModal('modalEditarSec')" class="btn btn-sm btn-light">
                <i class="fa-solid fa-pen"></i> Editar
            </button>
            <a href="pagos.php?sec=<?=$secActiva?>&reporte" class="btn btn-sm btn-info">
                <i class="fa-solid fa-chart-bar"></i> Reporte
            </a>
            <?php endif; ?>
            <?php if(hasPermission('pagos','crear')): ?>
            <button onclick="openModal('modalNuevaSec')" class="btn btn-sm btn-primary">
                <i class="fa-solid fa-plus"></i> Nueva Secuencia
            </button>
            <?php endif; ?>
        </div>
    </div>
    <div class="card-body">
        <?php if(empty($secuencias)): ?>
        <p style="text-align:center;color:var(--text-muted);padding:16px">No hay secuencias. Crea una para comenzar.</p>
        <?php else: ?>
        <div class="sec-tabs">
            <?php foreach($secuencias as $s): ?>
            <a href="pagos.php?sec=<?=$s['id']?>" class="sec-tab <?=$secActiva==$s['id']?'active':''?> <?=$s['estado']==='cerrada'?'cerrada':''?>">
                <?=clean($s['nombre'])?><?=$s['estado']==='cerrada'?' 🔒':''?>
            </a>
            <?php endforeach; ?>
        </div>
        <?php if($secData): ?>
        <div style="font-size:12px;color:var(--text-muted);padding-top:8px;border-top:1px solid var(--border);display:flex;align-items:center;gap:12px">
            <span><i class="fa-solid fa-calendar"></i>
                <?=date('d/m/Y',strtotime($secData['fecha_ini']))?>
                <?=$secData['fecha_fin']?' — '.date('d/m/Y',strtotime($secData['fecha_fin'])):'→ Sin fecha fin'?>
            </span>
            <span class="badge <?=$secData['estado']==='activa'?'badge-success':'badge-secondary'?>"><?=$secData['estado']==='activa'?'Activa':'Cerrada'?></span>
            <?php if($secData['notas']): ?><span><?=clean($secData['notas'])?></span><?php endif; ?>
        </div>
        <?php endif; ?>
        <?php endif; ?>
    </div>
</div>

<?php if($secData && $vistaRep): ?>
<!-- ═══════ REPORTE ═══════ -->
<div class="card mb-4">
    <div class="card-header" style="background:#1a1a2e;color:white;justify-content:space-between">
        <h5 style="color:white"><i class="fa-solid fa-chart-bar"></i> Reporte — <?=clean($secData['nombre'])?></h5>
        <div class="d-flex gap-2">
            <button onclick="window.print()" class="btn btn-sm btn-light"><i class="fa-solid fa-print"></i> Imprimir</button>
            <a href="pagos.php?sec=<?=$secActiva?>" class="btn btn-sm btn-light">✕ Cerrar</a>
        </div>
    </div>
    <div class="card-body">
    <?php if(empty($resumenVendedores)): ?>
    <p style="text-align:center;color:var(--text-muted)">Sin facturas en esta secuencia.</p>
    <?php else:
    $totVentas = array_sum(array_column($resumenVendedores,'total_ventas'));
    $totCom    = array_sum(array_column($resumenVendedores,'com_total'));
    $totPagado = array_sum(array_column($resumenVendedores,'pagado'));
    $totRest   = array_sum(array_column($resumenVendedores,'restante'));
    ?>
    <div style="display:grid;grid-template-columns:repeat(4,1fr);gap:12px;margin-bottom:24px">
        <div class="resumen-item green"><div class="resumen-val" style="color:var(--success)"><?=formatUSD($totVentas)?></div><div class="resumen-lab">Total Ventas</div></div>
        <div class="resumen-item blue"><div class="resumen-val" style="color:var(--info)"><?=formatUSD($totCom)?></div><div class="resumen-lab">Total Comisiones</div></div>
        <div class="resumen-item"><div class="resumen-val" style="color:var(--primary)"><?=formatUSD($totPagado)?></div><div class="resumen-lab">Total Cobrado</div></div>
        <div class="resumen-item <?=$totRest>0.01?'red':''?>"><div class="resumen-val" style="color:<?=$totRest>0.01?'var(--danger)':'var(--success)'?>"><?=formatUSD($totRest)?></div><div class="resumen-lab">Total Restante</div></div>
    </div>
    <table class="rep-table" style="width:100%;border-collapse:collapse">
        <thead>
            <tr>
                <th>Vendedor</th>
                <th class="text-right">Ventas Detal $</th>
                <th class="text-right">Com. Detal (<?=$comDetal?>%)</th>
                <th class="text-right">Ventas Mayor $</th>
                <th class="text-right">Com. Mayor (<?=$comMayor?>%)</th>
                <th class="text-right">Total Ventas</th>
                <th class="text-right">Total Com.</th>
                <th class="text-right">Cobrado $</th>
                <th class="text-right">Restante $</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach($resumenVendedores as $vr): ?>
        <tr style="<?=$vr['restante']>0.01?'background:#fff5f5':''?><?=$vr['es_jhon']?';border-left:4px solid gold':''?>">
            <td style="font-weight:700">
                <a href="pagos.php?sec=<?=$secActiva?>&vendedor=<?=$vr['vendedor_id']?>" style="color:var(--primary)"><?=clean($vr['nombre'])?></a>
                <?php if($vr['es_jhon']): ?><span style="font-size:10px;background:#ffc107;color:#333;padding:1px 6px;border-radius:8px;margin-left:4px">⭐ Módulo propio</span><?php endif; ?>
            </td>
            <td class="text-right"><?=formatUSD($vr['total_detal'])?></td>
            <td class="text-right" style="color:var(--warning)"><?=formatUSD($vr['com_detal'])?></td>
            <td class="text-right"><?=formatUSD($vr['total_mayor'])?></td>
            <td class="text-right" style="color:var(--info)"><?=formatUSD($vr['com_mayor'])?></td>
            <td class="text-right fw-bold"><?=formatUSD($vr['total_ventas'])?></td>
            <td class="text-right fw-bold" style="color:var(--warning)"><?=formatUSD($vr['com_total'])?></td>
            <td class="text-right" style="color:var(--success)"><?=formatUSD($vr['pagado'])?></td>
            <td class="text-right fw-bold" style="color:<?=$vr['restante']>0.01?'var(--danger)':'var(--success)'?>"><?=formatUSD($vr['restante'])?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
        <tfoot>
            <tr style="background:#f0f2f5;font-weight:800">
                <td>TOTALES</td>
                <td class="text-right"><?=formatUSD(array_sum(array_column($resumenVendedores,'total_detal')))?></td>
                <td class="text-right"><?=formatUSD(array_sum(array_column($resumenVendedores,'com_detal')))?></td>
                <td class="text-right"><?=formatUSD(array_sum(array_column($resumenVendedores,'total_mayor')))?></td>
                <td class="text-right"><?=formatUSD(array_sum(array_column($resumenVendedores,'com_mayor')))?></td>
                <td class="text-right"><?=formatUSD($totVentas)?></td>
                <td class="text-right"><?=formatUSD($totCom)?></td>
                <td class="text-right"><?=formatUSD($totPagado)?></td>
                <td class="text-right" style="color:<?=$totRest>0.01?'var(--danger)':'var(--success)'?>"><?=formatUSD($totRest)?></td>
            </tr>
        </tfoot>
    </table>
    <?php endif; ?>
    </div>
</div>

<?php elseif($secData): ?>
<!-- ═══════ VISTA NORMAL ═══════ -->
<div class="split-layout">

<!-- LISTA VENDEDORES -->
<div>
    <div class="card">
        <div class="card-header"><h5><i class="fa-solid fa-handshake text-primary"></i> Vendedores</h5></div>
        <?php if(empty($resumenVendedores)): ?>
        <div class="card-body" style="text-align:center;color:var(--text-muted);padding:32px">
            <i class="fa-solid fa-box-open" style="font-size:32px;opacity:.3"></i>
            <p style="margin-top:8px">Sin facturas en esta secuencia</p>
        </div>
        <?php else: ?>
        <?php foreach($resumenVendedores as $vr):
            $ini = strtoupper(substr($vr['nombre'],0,1));
            $isActive = $vendedorSel == $vr['vendedor_id'];
        ?>
        <a href="pagos.php?sec=<?=$secActiva?>&vendedor=<?=$vr['vendedor_id']?>"
           class="vendedor-row <?=$isActive?'active':''?> <?=$vr['es_jhon']?'jhon-row':''?>">
            <div class="vend-avatar <?=$vr['es_jhon']?'jhon':''?>">
                <?=$vr['es_jhon']?'⭐':$ini?>
            </div>
            <div style="flex:1">
                <div style="font-weight:700;font-size:13px"><?=clean($vr['nombre'])?></div>
                <div style="font-size:11px;color:var(--text-muted);margin-top:2px">
                    <?php if($vr['es_jhon']): ?>
                    <span style="color:#856404;font-weight:700">Módulo exclusivo →</span>
                    <?php else: ?>
                    D: <?=formatUSD($vr['total_detal'])?> · M: <?=formatUSD($vr['total_mayor'])?>
                    <?php endif; ?>
                </div>
            </div>
            <div style="text-align:right">
                <div style="font-weight:800;font-size:13px;color:<?=$vr['restante']>0.01?'var(--danger)':'var(--success)'?>">
                    <?=formatUSD(max(0,$vr['restante']))?>
                </div>
                <div style="font-size:10px;color:var(--text-muted)"><?=$vr['restante']>0.01?'Pendiente':'Saldado'?></div>
            </div>
        </a>
        <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>

<!-- DETALLE VENDEDOR -->
<div>
<?php if($vendedorData && $statsVendedor): ?>

<?php if($esJhon): ?>
<!-- ══ JHON: solo lectura, redirige a su módulo ══ -->
<div class="jhon-banner-detalle">
    <div style="width:52px;height:52px;border-radius:50%;background:#e74c3c;display:flex;align-items:center;justify-content:center;font-size:22px;flex-shrink:0">⭐</div>
    <div style="flex:1">
        <h4><?=clean($vendedorData['nombre'])?></h4>
        <p>Este vendedor tiene un módulo de cobranza exclusivo. Los cobros solo se registran desde allí.</p>
    </div>
    <a href="jhon_cobranza.php" class="btn btn-warning" style="flex-shrink:0">
        <i class="fa-solid fa-star"></i> Ir a Cobros Jhon
    </a>
</div>

<!-- Resumen solo lectura para Jhon -->
<div class="resumen-bar">
    <div class="resumen-item green">
        <div class="resumen-val" style="color:var(--success)"><?=formatUSD($statsVendedor['total_ventas'])?></div>
        <div class="resumen-lab">Total Ventas</div>
        <div class="resumen-sub">D: <?=formatUSD($statsVendedor['total_detal'])?> | M: <?=formatUSD($statsVendedor['total_mayor'])?></div>
    </div>
    <div class="resumen-item blue">
        <div class="resumen-val" style="color:var(--info)"><?=formatUSD($statsVendedor['com_total'])?></div>
        <div class="resumen-lab">Total Comisión</div>
        <div class="resumen-sub">D <?=$comDetal?>% | M <?=$comMayor?>%</div>
    </div>
    <div class="resumen-item" style="border-top:3px solid var(--primary)">
        <div class="resumen-val" style="color:var(--primary)"><?=formatUSD($statsVendedor['pagado'])?></div>
        <div class="resumen-lab">Cobrado (jhon_cobranza)</div>
    </div>
    <div class="resumen-item <?=$statsVendedor['restante']>0.01?'red':''?>">
        <div class="resumen-val" style="color:<?=$statsVendedor['restante']>0.01?'var(--danger)':'var(--success)'?>">
            <?=formatUSD(max(0,$statsVendedor['restante']))?>
        </div>
        <div class="resumen-lab">Pendiente</div>
    </div>
</div>

<!-- Aviso de solo lectura -->
<div class="jhon-lock-notice">
    <i class="fa-solid fa-lock" style="font-size:22px;color:#ffc107;flex-shrink:0"></i>
    <div>
        <strong>Cobranza bloqueada en este módulo.</strong>
        Los pagos, abonos y descuentos de Jhon se gestionan exclusivamente en
        <a href="jhon_cobranza.php"><strong>⭐ Cobros Jhon</strong></a>.
        Aquí solo puedes ver el resumen de ventas.
    </div>
</div>

<!-- Tabla de facturas de Jhon solo lectura (sin checkbox ni obs editable) -->
<?php foreach(['detalista'=>'detal','mayorista'=>'mayor'] as $tipo=>$cls):
    $lista = $clientesDetalle[$tipo];
    if(empty($lista)) continue;
    $total    = array_sum(array_column($lista,'total_usd'));
    $pct      = $tipo==='detalista'?$comDetal:$comMayor;
    $comMonto = round($total*$pct/100,2);
?>
<div style="margin-bottom:20px">
    <div class="seccion-tipo-header <?=$cls?>">
        <i class="fa-solid fa-<?=$tipo==='detalista'?'store':'industry'?>"></i> <?=ucfirst($tipo)?>
        <span style="margin-left:auto;font-size:12px">Total: <?=formatUSD($total)?> · Com <?=$pct?>%: <?=formatUSD($comMonto)?></span>
    </div>
    <div style="border:1px solid var(--border);border-top:0;border-radius:0 0 var(--radius) var(--radius)">
    <table style="width:100%;border-collapse:collapse;font-size:12px">
        <thead>
            <tr style="background:#f8fafc">
                <th style="padding:8px 12px">Cliente</th>
                <th style="padding:8px 12px">Zona</th>
                <th style="padding:8px 12px;text-align:right">Total $</th>
                <th style="padding:8px 12px;text-align:right">Abonado $</th>
                <th style="padding:8px 12px;text-align:right">Saldo $</th>
                <th style="padding:8px 12px;text-align:center">Estado</th>
                <th style="padding:8px 12px">N° Doc</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach($lista as $cl):
            $saldo = (float)$cl['total_usd'] - (float)$cl['monto_pagado_usd'];
            $badge = match($cl['estado']){
                'pagado'  => '<span style="background:#d1e7dd;color:#0f5132;padding:2px 7px;border-radius:10px;font-size:10px;font-weight:700">Pagado</span>',
                'parcial' => '<span style="background:#cff4fc;color:#0c5460;padding:2px 7px;border-radius:10px;font-size:10px;font-weight:700">Parcial</span>',
                default   => '<span style="background:#fff3cd;color:#856404;padding:2px 7px;border-radius:10px;font-size:10px;font-weight:700">Pendiente</span>',
            };
        ?>
        <tr style="border-bottom:1px solid var(--border)">
            <td style="padding:8px 12px;font-weight:600"><?=clean($cl['c_nombre'])?></td>
            <td style="padding:8px 12px;color:var(--text-muted)"><?=clean($cl['zona_nombre']??'—')?></td>
            <td style="padding:8px 12px;text-align:right;font-weight:700"><?=formatUSD($cl['total_usd'])?></td>
            <td style="padding:8px 12px;text-align:right;color:var(--success)"><?=formatUSD($cl['monto_pagado_usd'])?></td>
            <td style="padding:8px 12px;text-align:right;color:<?=$saldo>0?'var(--danger)':'var(--success)'?>;font-weight:700"><?=formatUSD(max(0,$saldo))?></td>
            <td style="padding:8px 12px;text-align:center"><?=$badge?></td>
            <td style="padding:8px 12px;font-family:monospace;font-size:11px">
                <a href="ver_venta.php?id=<?=$cl['id']?>" style="color:var(--primary)"><?=clean($cl['numero'])?></a>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
        <tfoot>
            <tr style="background:#f8fafc;font-weight:700">
                <td colspan="2" style="padding:8px 12px">Subtotal</td>
                <td style="padding:8px 12px;text-align:right"><?=formatUSD($total)?></td>
                <td style="padding:8px 12px;text-align:right;color:var(--success)"><?=formatUSD(array_sum(array_column($lista,'monto_pagado_usd')))?></td>
                <td style="padding:8px 12px;text-align:right;color:var(--danger)"><?=formatUSD(max(0,$total-array_sum(array_column($lista,'monto_pagado_usd'))))?></td>
                <td colspan="2" style="padding:8px 12px;font-size:11px;color:var(--text-muted)">Com (<?=$pct?>%): <?=formatUSD($comMonto)?></td>
            </tr>
        </tfoot>
    </table>
    </div>
</div>
<?php endforeach; ?>

<?php else: ?>
<!-- ══ VENDEDOR NORMAL: flujo completo de cobros ══ -->
<div class="resumen-bar">
    <div class="resumen-item green">
        <div class="resumen-val" style="color:var(--success)"><?=formatUSD($statsVendedor['total_ventas'])?></div>
        <div class="resumen-lab">Total Ventas</div>
        <div class="resumen-sub">D: <?=formatUSD($statsVendedor['total_detal'])?> | M: <?=formatUSD($statsVendedor['total_mayor'])?></div>
    </div>
    <div class="resumen-item blue">
        <div class="resumen-val" style="color:var(--info)"><?=formatUSD($statsVendedor['com_total'])?></div>
        <div class="resumen-lab">Total Comisión</div>
        <div class="resumen-sub">D <?=$comDetal?>%: <?=formatUSD($statsVendedor['com_detal'])?> | M <?=$comMayor?>%: <?=formatUSD($statsVendedor['com_mayor'])?></div>
    </div>
    <div class="resumen-item" style="border-top:3px solid var(--primary)">
        <div class="resumen-val" style="color:var(--primary)"><?=formatUSD($statsVendedor['com_pagada']??0)?></div>
        <div class="resumen-lab">Comisión Pagada</div>
        <div class="resumen-sub">
            <?php if(hasPermission('pagos','crear') && $secData['estado']==='activa'): ?>
            <a href="#" onclick="openModal('modalComision');return false" style="font-size:11px;color:var(--primary)">+ Registrar</a>
            <?php endif; ?>
        </div>
    </div>
    <div class="resumen-item <?=$statsVendedor['restante']>0.01?'red':''?>">
        <div class="resumen-val" style="color:<?=$statsVendedor['restante']>0.01?'var(--danger)':'var(--success)'?>"><?=formatUSD($statsVendedor['restante'])?></div>
        <div class="resumen-lab">Restante a Cobrar</div>
        <div class="resumen-sub">Cobrado: <?=formatUSD($statsVendedor['pagado'])?></div>
    </div>
</div>

<div style="display:grid;grid-template-columns:1fr 320px;gap:16px">
<div>
<?php foreach(['detalista'=>'detal','mayorista'=>'mayor'] as $tipo=>$cls):
    $lista = $clientesDetalle[$tipo];
    if(empty($lista)) continue;
    $total    = array_sum(array_column($lista,'total_usd'));
    $pct      = $tipo==='detalista'?$comDetal:$comMayor;
    $comMonto = round($total*$pct/100,2);
?>
<div style="margin-bottom:20px">
    <div class="seccion-tipo-header <?=$cls?>">
        <i class="fa-solid fa-<?=$tipo==='detalista'?'store':'industry'?>"></i> <?=ucfirst($tipo)?>
        <span style="margin-left:auto;font-size:12px">Total: <?=formatUSD($total)?> · Com <?=$pct?>%: <?=formatUSD($comMonto)?></span>
    </div>
    <div style="border:1px solid var(--border);border-top:0;border-radius:0 0 var(--radius) var(--radius)">
    <table style="width:100%;border-collapse:collapse;font-size:12px">
        <thead>
            <tr style="background:#f8fafc">
                <th style="padding:8px 12px">Cliente</th>
                <th style="padding:8px 12px">Zona</th>
                <th style="padding:8px 12px;text-align:right">Total $</th>
                <th style="padding:8px 12px;text-align:center">Estado</th>
                <th style="padding:8px 12px;text-align:center" title="Confirmado cancelado">✅</th>
                <th style="padding:8px 12px;text-align:center" title="Cliente pagó">✅</th>
                <th style="padding:8px 12px">Obs.</th>
                <th style="padding:8px 12px">N° Doc</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach($lista as $cl):
            $badge = match($cl['estado']){
                'pagado'  => '<span style="background:#d1e7dd;color:#0f5132;padding:2px 7px;border-radius:10px;font-size:10px;font-weight:700">Pagado</span>',
                'parcial' => '<span style="background:#cff4fc;color:#0c5460;padding:2px 7px;border-radius:10px;font-size:10px;font-weight:700">Parcial</span>',
                default   => '<span style="background:#fff3cd;color:#856404;padding:2px 7px;border-radius:10px;font-size:10px;font-weight:700">Pendiente</span>',
            };
        ?>
        <tr style="border-bottom:1px solid var(--border);<?=$cl['cancelado_manual']?'background:#f0fdf4':''?>">
            <td style="padding:8px 12px;font-weight:600"><?=clean($cl['c_nombre'])?></td>
            <td style="padding:8px 12px;color:var(--text-muted)"><?=clean($cl['zona_nombre']??'—')?></td>
            <td style="padding:8px 12px;text-align:right;font-weight:700"><?=formatUSD($cl['total_usd'])?></td>
            <td style="padding:8px 12px;text-align:center"><?=$badge?></td>
            <td style="padding:8px 12px;text-align:center"><?=$cl['cancelado_manual']?'✅':'—'?></td>
            <td style="padding:8px 12px;text-align:center">
                <?php if(hasPermission('pagos','crear')): ?>
                <a href="pagos.php?toggle_pago_cli=<?=$cl['id']?>&sec=<?=$secActiva?>&vendedor=<?=$vendedorSel?>"
                   style="font-size:20px;text-decoration:none;display:block;text-align:center"
                   title="<?=$cl['cancelado_manual']?'Desmarcar':'Marcar como pagado'?>">
                    <?=$cl['cancelado_manual']?'✅':'⬜'?>
                </a>
                <?php else: ?><?=$cl['cancelado_manual']?'✅':'—'?><?php endif; ?>
            </td>
            <td style="padding:8px 12px;max-width:140px">
                <form method="POST" style="display:flex;gap:4px">
                    <input type="hidden" name="<?=CSRF_TOKEN_NAME?>" value="<?=getCsrfToken()?>">
                    <input type="hidden" name="action" value="save_obs">
                    <input type="hidden" name="venta_id" value="<?=$cl['id']?>">
                    <input type="hidden" name="sec" value="<?=$secActiva?>">
                    <input type="hidden" name="vendedor" value="<?=$vendedorSel?>">
                    <input type="text" name="obs" class="form-control" style="font-size:11px;padding:3px 6px"
                           value="<?=clean($cl['obs_cobranza']??'')?>" placeholder="Nota...">
                    <button type="submit" class="btn btn-sm btn-light" style="padding:3px 6px;font-size:11px">💾</button>
                </form>
            </td>
            <td style="padding:8px 12px;font-family:monospace;font-size:11px">
                <a href="ver_venta.php?id=<?=$cl['id']?>" style="color:var(--primary)"><?=clean($cl['numero'])?></a>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
        <tfoot>
            <tr style="background:#f8fafc;font-weight:700">
                <td colspan="2" style="padding:8px 12px">Subtotal</td>
                <td style="padding:8px 12px;text-align:right"><?=formatUSD($total)?></td>
                <td colspan="4" style="padding:8px 12px;font-size:11px;color:var(--text-muted)">Com (<?=$pct?>%): <?=formatUSD($comMonto)?></td>
            </tr>
        </tfoot>
    </table>
    </div>
</div>
<?php endforeach; ?>
</div>

<!-- PANEL COBROS (solo vendedores normales) -->
<div>
    <div class="card">
        <div class="card-header" style="background:#1a1a2e;color:white;justify-content:space-between">
            <h5 style="color:white;font-size:13px"><i class="fa-solid fa-hand-holding-dollar"></i> Cobros — <?=clean($vendedorData['nombre'])?></h5>
            <?php if(hasPermission('pagos','crear') && $secData['estado']==='activa'): ?>
            <button onclick="openModal('modalPago')" class="btn btn-success btn-sm">+ Registrar</button>
            <?php endif; ?>
        </div>
        <div class="card-body">
        <?php if(empty($pagosVendedor)): ?>
        <p style="text-align:center;color:var(--text-muted);padding:16px;font-size:12px">Sin cobros aún</p>
        <?php else: ?>
        <?php foreach($pagosVendedor as $pv):
            $ico = match($pv['tipo']){'efectivo'=>'💵','transferencia'=>'🏦','punto'=>'💳','zelle'=>'⚡','binance'=>'🟡','wally'=>'📱','zinli'=>'💜',default=>'🔵'};
        ?>
        <div class="pago-chip">
            <span style="font-size:18px"><?=$ico?></span>
            <div style="flex:1;min-width:0">
                <div style="font-size:12px;font-weight:700"><?=date('d/m/Y',strtotime($pv['fecha']))?> · <span style="background:var(--primary);color:white;padding:1px 6px;border-radius:8px;font-size:10px"><?=strtoupper($pv['tipo'])?></span></div>
                <?php if($pv['referencia']): ?><div style="font-size:11px;color:var(--text-muted)">Ref: <?=clean($pv['referencia'])?></div><?php endif; ?>
                <?php if($pv['descripcion']): ?><div style="font-size:11px;color:var(--text-muted);overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?=clean($pv['descripcion'])?></div><?php endif; ?>
            </div>
            <div style="text-align:right;flex-shrink:0">
                <div style="font-weight:800;color:var(--success)"><?=formatUSD($pv['monto_usd'])?></div>
                <span style="font-size:10px;color:var(--text-muted)"><i class="fa-solid fa-lock"></i></span>
            </div>
        </div>
        <?php endforeach; ?>
        <div style="border-top:1px solid var(--border);padding-top:10px;margin-top:4px;display:flex;justify-content:space-between;font-weight:800;font-size:14px">
            <span>Total cobrado</span><span style="color:var(--success)"><?=formatUSD($statsVendedor['pagado'])?></span>
        </div>
        <?php if(!empty($comisionesPagadas)): ?>
        <div style="margin-top:12px;border-top:2px dashed var(--border);padding-top:10px">
            <div style="font-size:11px;font-weight:700;text-transform:uppercase;color:var(--primary);margin-bottom:6px">Comisiones pagadas</div>
            <?php foreach($comisionesPagadas as $cp): ?>
            <div style="background:#f0fdf4;border:1px solid #bbf7d0;border-radius:6px;padding:6px 10px;margin-bottom:4px;display:flex;justify-content:space-between;font-size:12px">
                <div><span style="font-weight:700"><?=date('d/m/Y',strtotime($cp['fecha']))?></span><?php if($cp['notas']): ?><br><span style="color:var(--text-muted)"><?=clean($cp['notas'])?></span><?php endif; ?></div>
                <span style="font-weight:800;color:var(--success)"><?=formatUSD($cp['monto_usd'])?></span>
            </div>
            <?php endforeach; ?>
            <div style="font-size:12px;font-weight:800;text-align:right;color:var(--success)">Total: <?=formatUSD($statsVendedor['com_pagada'])?></div>
        </div>
        <?php endif; ?>
        <?php endif; ?>
        </div>
    </div>
</div>
</div><!-- /grid 1fr 320px -->
<?php endif; // fin $esJhon ?>

<?php else: ?>
<div class="card"><div class="card-body" style="text-align:center;padding:48px;color:var(--text-muted)">
    <i class="fa-solid fa-hand-pointer" style="font-size:48px;opacity:.2"></i>
    <h3 style="margin-top:12px">Selecciona un vendedor</h3>
</div></div>
<?php endif; ?>
</div><!-- /detalle -->
</div><!-- /split-layout -->

<?php else: ?>
<div class="card"><div class="card-body" style="text-align:center;padding:64px;color:var(--text-muted)">
    <i class="fa-solid fa-layer-group" style="font-size:64px;opacity:.15"></i>
    <h3 style="margin-top:20px">Selecciona una Secuencia</h3>
    <p>Elige una secuencia arriba o crea una nueva para comenzar.</p>
</div></div>
<?php endif; ?>

<!-- MODALES comunes -->
<div class="modal-overlay" id="modalNuevaSec">
    <div class="modal-box">
        <div class="modal-header">
            <h4><i class="fa-solid fa-layer-group"></i> Nueva Secuencia</h4>
            <button onclick="closeModal('modalNuevaSec')" class="btn btn-light btn-sm btn-icon">✕</button>
        </div>
        <form method="POST">
        <input type="hidden" name="csrf_token" value="<?=getCsrfToken()?>">
        <input type="hidden" name="action" value="crear_secuencia">
        <div class="modal-body" style="display:grid;gap:12px">
            <div class="form-group"><label class="form-label">Nombre *</label><input type="text" name="nombre" class="form-control" placeholder="Ej: Despacho Julio 27/07/2026" required></div>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
                <div class="form-group"><label class="form-label">Fecha Inicio *</label><input type="date" name="fecha_ini" class="form-control" value="<?=date('Y-m-d')?>" required></div>
                <div class="form-group"><label class="form-label">Fecha Fin (opcional)</label><input type="date" name="fecha_fin" class="form-control"></div>
            </div>
            <div class="form-group"><label class="form-label">Notas</label><textarea name="notas" class="form-control" rows="2"></textarea></div>
        </div>
        <div class="modal-footer">
            <button type="button" onclick="closeModal('modalNuevaSec')" class="btn btn-light">Cancelar</button>
            <button type="submit" class="btn btn-primary"><i class="fa-solid fa-check"></i> Crear</button>
        </div>
        </form>
    </div>
</div>

<?php if($secData): ?>
<div class="modal-overlay" id="modalEditarSec">
    <div class="modal-box">
        <div class="modal-header">
            <h4><i class="fa-solid fa-pen"></i> Editar Secuencia</h4>
            <button onclick="closeModal('modalEditarSec')" class="btn btn-light btn-sm btn-icon">✕</button>
        </div>
        <form method="POST">
        <input type="hidden" name="csrf_token" value="<?=getCsrfToken()?>">
        <input type="hidden" name="action" value="editar_secuencia">
        <input type="hidden" name="sec_id" value="<?=$secData['id']?>">
        <div class="modal-body" style="display:grid;gap:12px">
            <div class="form-group"><label class="form-label">Nombre *</label><input type="text" name="nombre" class="form-control" value="<?=clean($secData['nombre'])?>" required></div>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
                <div class="form-group"><label class="form-label">Fecha Inicio *</label><input type="date" name="fecha_ini" class="form-control" value="<?=$secData['fecha_ini']?>" required></div>
                <div class="form-group"><label class="form-label">Fecha Fin</label><input type="date" name="fecha_fin" class="form-control" value="<?=$secData['fecha_fin']??''?>"></div>
            </div>
            <div class="form-group"><label class="form-label">Estado</label>
                <select name="estado" class="form-select">
                    <option value="activa" <?=$secData['estado']==='activa'?'selected':''?>>🟢 Activa</option>
                    <option value="cerrada" <?=$secData['estado']==='cerrada'?'selected':''?>>🔒 Cerrada</option>
                </select>
            </div>
            <div class="form-group"><label class="form-label">Notas</label><textarea name="notas" class="form-control" rows="2"><?=clean($secData['notas']??'')?></textarea></div>
        </div>
        <div class="modal-footer">
            <button type="button" onclick="closeModal('modalEditarSec')" class="btn btn-light">Cancelar</button>
            <button type="submit" class="btn btn-primary"><i class="fa-solid fa-floppy-disk"></i> Guardar</button>
        </div>
        </form>
    </div>
</div>
<?php endif; ?>

<!-- Modal pago y comisión SOLO para vendedores normales (no Jhon) -->
<?php if($vendedorData && $statsVendedor && !$esJhon): ?>
<div class="modal-overlay" id="modalPago">
    <div class="modal-box">
        <div class="modal-header">
            <h4><i class="fa-solid fa-hand-holding-dollar"></i> Cobro — <?=clean($vendedorData['nombre'])?></h4>
            <button onclick="closeModal('modalPago')" class="btn btn-light btn-sm btn-icon">✕</button>
        </div>
        <form method="POST">
        <input type="hidden" name="csrf_token" value="<?=getCsrfToken()?>">
        <input type="hidden" name="action" value="registrar_pago">
        <input type="hidden" name="vendedor_id" value="<?=$vendedorSel?>">
        <input type="hidden" name="secuencia_id" value="<?=$secActiva?>">
        <div class="modal-body" style="display:grid;gap:12px">
            <div style="background:#f8fafc;border-radius:var(--radius);padding:12px;display:grid;grid-template-columns:repeat(3,1fr);gap:8px;text-align:center;font-size:12px">
                <div><div style="font-weight:800;color:var(--success)"><?=formatUSD($statsVendedor['total_ventas'])?></div><div style="color:var(--text-muted)">Total Ventas</div></div>
                <div><div style="font-weight:800;color:var(--info)"><?=formatUSD($statsVendedor['com_total'])?></div><div style="color:var(--text-muted)">Comisión</div></div>
                <div><div style="font-weight:800;color:<?=$statsVendedor['restante']>0?'var(--danger)':'var(--success)'?>"><?=formatUSD($statsVendedor['restante'])?></div><div style="color:var(--text-muted)">Restante</div></div>
            </div>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
                <div class="form-group"><label class="form-label">Monto (USD) *</label><input type="number" name="monto" id="inputMontoPago" class="form-control" step="0.01" min="0.01" placeholder="0.00" required></div>
                <div class="form-group"><label class="form-label">Fecha *</label><input type="date" name="fecha" class="form-control" value="<?=date('Y-m-d')?>" required></div>
            </div>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
                <div class="form-group"><label class="form-label">Moneda</label>
                    <select name="moneda" id="selMonedaPago" class="form-select" onchange="toggleBs()">
                        <option value="USD">USD (Dólares)</option>
                        <option value="BS">Bs. (Bolívares)</option>
                    </select>
                </div>
                <div id="wrapBs" style="display:none;grid-column:1/-1;background:#fff8e1;border:1px solid #ffc107;border-radius:8px;padding:10px">
                    <label class="form-label" style="font-size:12px;color:#856404">Tasa de cobro (Bs por 1 USD)</label>
                    <input type="number" name="tasa_cobro" id="inputTasaCobro" class="form-control" step="0.01" min="0" placeholder="Ej: 49.50" oninput="calcBs()">
                    <div id="lblMontoBs" style="font-size:12px;color:#856404;margin-top:4px"></div>
                </div>
                <div class="form-group"><label class="form-label">Tipo de Pago *</label>
                    <select name="tipo" class="form-select" required>
                        <?php foreach($TIPOS_PAGO as $t): ?><option value="<?=$t?>"><?=ucfirst($t)?></option><?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group"><label class="form-label">Referencia</label><input type="text" name="referencia" class="form-control" placeholder="N° operación..."></div>
            </div>
            <div class="form-group"><label class="form-label">Descripción</label><input type="text" name="descripcion" class="form-control" placeholder="Quién abona, notas..."></div>
        </div>
        <div class="modal-footer">
            <button type="button" onclick="closeModal('modalPago')" class="btn btn-light">Cancelar</button>
            <button type="submit" class="btn btn-success"><i class="fa-solid fa-check"></i> Registrar Cobro</button>
        </div>
        </form>
    </div>
</div>

<div class="modal-overlay" id="modalComision">
    <div class="modal-box" style="max-width:420px">
        <div class="modal-header">
            <h4><i class="fa-solid fa-sack-dollar"></i> Pagar Comisión — <?=clean($vendedorData['nombre'])?></h4>
            <button onclick="closeModal('modalComision')" class="btn btn-light btn-sm btn-icon">✕</button>
        </div>
        <form method="POST">
        <input type="hidden" name="csrf_token" value="<?=getCsrfToken()?>">
        <input type="hidden" name="action" value="pagar_comision">
        <input type="hidden" name="vendedor_id" value="<?=$vendedorSel?>">
        <input type="hidden" name="secuencia_id" value="<?=$secActiva?>">
        <div class="modal-body" style="display:grid;gap:12px">
            <div style="background:#f0fdf4;border:1px solid #bbf7d0;border-radius:8px;padding:10px;font-size:12px">
                <div style="display:flex;justify-content:space-between"><span>Comisión total</span><strong style="color:var(--success)"><?=formatUSD($statsVendedor['com_total'])?></strong></div>
                <div style="display:flex;justify-content:space-between;margin-top:4px"><span>Ya pagado</span><strong style="color:var(--primary)"><?=formatUSD($statsVendedor['com_pagada']??0)?></strong></div>
                <div style="display:flex;justify-content:space-between;margin-top:4px;border-top:1px solid #bbf7d0;padding-top:6px"><span>Pendiente</span><strong style="color:var(--danger)"><?=formatUSD(max(0,$statsVendedor['com_total']-($statsVendedor['com_pagada']??0)))?></strong></div>
            </div>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px">
                <div class="form-group"><label class="form-label">Monto (USD) *</label><input type="number" name="monto" class="form-control" step="0.01" min="0.01" value="<?=number_format(max(0,$statsVendedor['com_total']-($statsVendedor['com_pagada']??0)),2,'.','')?>" required></div>
                <div class="form-group"><label class="form-label">Fecha *</label><input type="date" name="fecha" class="form-control" value="<?=date('Y-m-d')?>" required></div>
            </div>
            <div class="form-group"><label class="form-label">Notas</label><input type="text" name="notas" class="form-control" placeholder="Ej: Transferencia BDV..."></div>
        </div>
        <div class="modal-footer">
            <button type="button" onclick="closeModal('modalComision')" class="btn btn-light">Cancelar</button>
            <button type="submit" class="btn btn-success"><i class="fa-solid fa-check"></i> Registrar Comisión</button>
        </div>
        </form>
    </div>
</div>
<?php endif; // fin !$esJhon modales ?>

<?php
$extraScript = '<script>
function toggleBs() {
    const sel  = document.getElementById("selMonedaPago");
    const wrap = document.getElementById("wrapBs");
    if (sel && wrap) wrap.style.display = sel.value === "BS" ? "block" : "none";
}
function calcBs() {
    const monto = parseFloat(document.getElementById("inputMontoPago")?.value || 0);
    const tasa  = parseFloat(document.getElementById("inputTasaCobro")?.value || 0);
    const lbl   = document.getElementById("lblMontoBs");
    if (lbl && tasa > 0) lbl.textContent = "Equivale a Bs. " + (monto*tasa).toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g,",");
}
</script>';
include 'includes/footer.php';