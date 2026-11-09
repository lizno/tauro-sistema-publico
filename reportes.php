<?php
require_once 'config/config.php';
require_once 'functions/db.php';
require_once 'functions/helpers.php';
require_once 'functions/auth.php';

requireLogin();
$pageTitle = 'Reportes';

$tipoRep    = clean($_GET['tipo']      ?? '');
$fechaIni   = clean($_GET['fecha_ini'] ?? date('Y-m-01'));
$fechaFin   = clean($_GET['fecha_fin'] ?? date('Y-m-d'));
$clienteId  = cleanInt($_GET['cliente']  ?? 0);
$vendedorId = cleanInt($_GET['vendedor'] ?? 0);
$tipoCli    = clean($_GET['tipo_cli']    ?? '');

$clientes   = dbQuery("SELECT id,nombre FROM clientes WHERE activo=1 ORDER BY nombre");
$vendedores = dbQuery("SELECT id,nombre FROM vendedores WHERE activo=1 ORDER BY nombre");
$comMayor   = (float)getConfig('comision_pct_mayorista','7.00');
$comDetal   = (float)getConfig('comision_pct_detalista','10.00');

// ── CATÁLOGO DE REPORTES ────────────────────────────────────
$catalogo = [
    'documentos_periodo'  => ['Documentos por Período',       'fa-calendar-days',    'Todos los despachos y facturas del período'],
    'ventas_vendedor'     => ['Ventas por Vendedor',           'fa-handshake',        'Totales y comisión estimada por vendedor'],
    'ventas_cliente'      => ['Ventas por Cliente',            'fa-users',            'Historial de compras por cliente'],
    'top_productos'       => ['Productos más vendidos',        'fa-trophy',           'Ranking por unidades y monto facturado'],
    'cobros'              => ['Cobros y Pagos',                'fa-money-bill-wave',  'Historial de pagos registrados'],
    'creditos_pendientes' => ['Créditos Pendientes',           'fa-hourglass-half',   'Facturas con saldo por cobrar'],
    'inventario_valorado' => ['Inventario Valorado',           'fa-boxes-stacked',    'Stock actual con valor de referencia'],
];

// ── EJECUTAR REPORTE ────────────────────────────────────────
$datos = []; $cols = []; $titulo = ''; $subtotales = [];

if ($tipoRep && isset($catalogo[$tipoRep])) {
    $titulo = $catalogo[$tipoRep][0];

    switch ($tipoRep) {

        // ── 1. DOCUMENTOS POR PERÍODO ─────────────────────
        case 'documentos_periodo':
            $cols = ['N° Doc','Tipo','Fecha','Cliente','RIF','Vendedor','Cli. Tipo','Pago','Total $','Pagado $','Saldo $','Estado'];
            $sql  = "SELECT v.numero, v.tipo_doc, v.fecha, c.nombre cliente, c.rif,
                            vd.nombre vendedor, v.tipo_cliente, v.tipo_pago,
                            v.total_usd, v.monto_pagado_usd,
                            (v.total_usd-v.monto_pagado_usd) saldo, v.estado
                     FROM ventas v
                     LEFT JOIN clientes c ON v.cliente_id=c.id
                     LEFT JOIN vendedores vd ON v.vendedor_id=vd.id
                     WHERE v.tipo_doc IN('despacho','venta') AND v.fecha BETWEEN ? AND ?";
            $p = [$fechaIni,$fechaFin];
            if ($tipoCli)    { $sql.=" AND v.tipo_cliente=?"; $p[]=$tipoCli; }
            if ($clienteId)  { $sql.=" AND v.cliente_id=?";  $p[]=$clienteId; }
            if ($vendedorId) { $sql.=" AND v.vendedor_id=?"; $p[]=$vendedorId; }
            $sql .= " ORDER BY v.fecha DESC,v.id DESC";
            $raw = dbQuery($sql,$p);
            $sumTotal = 0; $sumPagado = 0;
            foreach ($raw as $r) {
                $datos[] = [
                    clean($r['numero']),
                    ['cotizacion'=>'Despacho','despacho'=>'Despacho','venta'=>'Factura'][$r['tipo_doc']] ?? ucfirst($r['tipo_doc']),
                    date('d/m/Y',strtotime($r['fecha'])),
                    clean($r['cliente']),
                    clean($r['rif']??'—'),
                    clean($r['vendedor']??'—'),
                    ucfirst($r['tipo_cliente']),
                    ucfirst($r['tipo_pago']),
                    '$ '.number_format($r['total_usd'],2),
                    '$ '.number_format($r['monto_pagado_usd'],2),
                    '$ '.number_format($r['saldo'],2),
                    ucfirst($r['estado']),
                ];
                if ($r['estado']!=='anulado') { $sumTotal += $r['total_usd']; $sumPagado += $r['monto_pagado_usd']; }
            }
            $subtotales = ['Total facturado'=>'$ '.number_format($sumTotal,2), 'Total cobrado'=>'$ '.number_format($sumPagado,2), 'Saldo pendiente'=>'$ '.number_format($sumTotal-$sumPagado,2)];
            break;

        // ── 2. VENTAS POR VENDEDOR ────────────────────────
        case 'ventas_vendedor':
            $cols = ['Vendedor','Zona','Tipo Pref.','N° Facturas','Ventas Detal $','Com. Detal $','Ventas Mayor $','Com. Mayor $','Total $','Comisión Total $'];
            $sql  = "SELECT vd.id,vd.nombre vendedor,z.nombre zona,vd.tipo_preferencia,
                            COUNT(CASE WHEN v.tipo_doc='venta' AND v.estado!='anulado' THEN 1 END) nfacturas,
                            COALESCE(SUM(CASE WHEN v.tipo_doc='venta' AND v.tipo_cliente='detalista' AND v.estado!='anulado' THEN v.total_usd ELSE 0 END),0) tot_detal,
                            COALESCE(SUM(CASE WHEN v.tipo_doc='venta' AND v.tipo_cliente='mayorista' AND v.estado!='anulado' THEN v.total_usd ELSE 0 END),0) tot_mayor
                     FROM vendedores vd
                     LEFT JOIN zonas z ON vd.zona_id=z.id
                     LEFT JOIN ventas v ON v.vendedor_id=vd.id AND v.fecha BETWEEN ? AND ?
                     WHERE vd.activo=1 AND vd.departamento_id=1";
            $p = [$fechaIni,$fechaFin];
            if ($vendedorId) { $sql.=" AND vd.id=?"; $p[]=$vendedorId; }
            $sql.=" GROUP BY vd.id ORDER BY (tot_detal+tot_mayor) DESC";
            $raw = dbQuery($sql,$p);
            $sumTot = $sumCom = 0;
            foreach ($raw as $r) {
                $total   = $r['tot_detal']+$r['tot_mayor'];
                $comDet  = round($r['tot_detal']*$comDetal/100,2);
                $comMay  = round($r['tot_mayor']*$comMayor/100,2);
                $comTot  = $comDet+$comMay;
                $datos[] = [
                    clean($r['vendedor']),
                    clean($r['zona']??'—'),
                    ucfirst($r['tipo_preferencia']??'Ambos'),
                    $r['nfacturas'],
                    '$ '.number_format($r['tot_detal'],2),
                    '$ '.number_format($comDet,2).' ('.$comDetal.'%)',
                    '$ '.number_format($r['tot_mayor'],2),
                    '$ '.number_format($comMay,2).' ('.$comMayor.'%)',
                    '$ '.number_format($total,2),
                    '$ '.number_format($comTot,2),
                ];
                $sumTot += $total; $sumCom += $comTot;
            }
            $subtotales = ['Total general'=>'$ '.number_format($sumTot,2),'Comisión total'=>'$ '.number_format($sumCom,2)];
            break;

        // ── 3. VENTAS POR CLIENTE ─────────────────────────
        case 'ventas_cliente':
            $cols = ['Cliente','RIF','Zona','Tipo','Vendedor','N° Docs','Total $','Pagado $','Saldo $'];
            $sql  = "SELECT c.nombre cliente,c.rif,z.nombre zona,c.tipo_default,vd.nombre vendedor,
                            COUNT(v.id) docs,
                            COALESCE(SUM(CASE WHEN v.estado!='anulado' THEN v.total_usd ELSE 0 END),0) total,
                            COALESCE(SUM(CASE WHEN v.estado!='anulado' THEN v.monto_pagado_usd ELSE 0 END),0) pagado
                     FROM clientes c
                     LEFT JOIN zonas z ON c.zona_id=z.id
                     LEFT JOIN vendedores vd ON c.vendedor_id=vd.id
                     LEFT JOIN ventas v ON v.cliente_id=c.id AND v.tipo_doc='venta' AND v.fecha BETWEEN ? AND ?
                     WHERE c.activo=1";
            $p = [$fechaIni,$fechaFin];
            if ($clienteId)  { $sql.=" AND c.id=?";          $p[]=$clienteId; }
            if ($vendedorId) { $sql.=" AND c.vendedor_id=?";  $p[]=$vendedorId; }
            if ($tipoCli)    { $sql.=" AND c.tipo_default=?";  $p[]=$tipoCli; }
            $sql.=" GROUP BY c.id ORDER BY total DESC";
            $raw = dbQuery($sql,$p);
            $sumTot = $sumSal = 0;
            foreach ($raw as $r) {
                $sal = $r['total']-$r['pagado'];
                $datos[] = [
                    clean($r['cliente']),clean($r['rif']??'—'),clean($r['zona']??'—'),
                    ucfirst($r['tipo_default']??'—'),clean($r['vendedor']??'—'),
                    $r['docs'],
                    '$ '.number_format($r['total'],2),
                    '$ '.number_format($r['pagado'],2),
                    '$ '.number_format($sal,2),
                ];
                $sumTot += $r['total']; $sumSal += $sal;
            }
            $subtotales = ['Total facturado'=>'$ '.number_format($sumTot,2),'Saldo total'=>'$ '.number_format($sumSal,2)];
            break;

        // ── 4. PRODUCTOS MÁS VENDIDOS ─────────────────────
        case 'top_productos':
            $cols = ['#','Código','Producto','Categoría','Unid.','Cant. Vendida','Monto Total $','% del Total','Stock Actual'];
            $sql  = "SELECT p.codigo,p.descripcion,cat.nombre categoria,p.unidad,p.stock_actual,
                            COALESCE(SUM(vd.cantidad),0) cant_vendida,
                            COALESCE(SUM(vd.monto_usd),0) monto_total
                     FROM productos p
                     LEFT JOIN categorias cat ON p.categoria_id=cat.id
                     LEFT JOIN venta_detalles vd ON vd.producto_id=p.id
                     LEFT JOIN ventas v ON vd.venta_id=v.id AND v.tipo_doc='venta' AND v.estado!='anulado' AND v.fecha BETWEEN ? AND ?
                     WHERE p.activo=1 AND p.departamento_id=1";
            $p = [$fechaIni,$fechaFin];
            $sql.=" GROUP BY p.id ORDER BY cant_vendida DESC";
            $raw = dbQuery($sql,$p);
            $granTotal = array_sum(array_column($raw,'monto_total')) ?: 1;
            foreach ($raw as $k => $r) {
                $pct = round($r['monto_total']/$granTotal*100,1);
                $datos[] = [
                    $k+1,
                    clean($r['codigo']),
                    clean($r['descripcion']),
                    clean($r['categoria']??'—'),
                    $r['unidad'],
                    number_format($r['cant_vendida'],2),
                    '$ '.number_format($r['monto_total'],2),
                    $pct.'%',
                    number_format($r['stock_actual'],2),
                ];
            }
            $subtotales = ['Total facturado'=>'$ '.number_format($granTotal,2),'Productos con ventas'=>count(array_filter($raw,fn($r)=>$r['cant_vendida']>0))];
            break;

        // ── 5. COBROS ─────────────────────────────────────
        case 'cobros':
            $cols = ['Fecha','Tipo','Referencia','Moneda','Monto $','Monto Bs.','Factura','Cliente','Registrado por'];
            $sql  = "SELECT p.fecha,p.tipo,p.referencia,p.moneda,p.monto_usd,p.monto_bs,
                            v.numero factura,c.nombre cliente,u.nombre usuario
                     FROM pagos p
                     LEFT JOIN ventas v ON p.venta_id=v.id
                     LEFT JOIN clientes c ON v.cliente_id=c.id
                     LEFT JOIN usuarios u ON p.usuario_id=u.id
                     WHERE p.anulado=0 AND (v.id IS NULL OR v.estado<>'anulado')
                       AND p.fecha BETWEEN ? AND ?";
            $p = [$fechaIni,$fechaFin];
            if ($clienteId) { $sql.=" AND c.id=?"; $p[]=$clienteId; }
            $sql.=" ORDER BY p.fecha DESC,p.id DESC";
            $raw = dbQuery($sql,$p);
            $sumUSD = 0;
            foreach ($raw as $r) {
                $datos[] = [
                    date('d/m/Y',strtotime($r['fecha'])),
                    ucfirst($r['tipo']),
                    clean($r['referencia']??'—'),
                    $r['moneda'],
                    '$ '.number_format($r['monto_usd'],2),
                    $r['monto_bs']?'Bs. '.number_format($r['monto_bs'],2,',','.'):'—',
                    clean($r['factura']??'—'),
                    clean($r['cliente']??'—'),
                    clean($r['usuario']??'—'),
                ];
                $sumUSD += $r['monto_usd'];
            }
            $subtotales = ['Total cobrado USD'=>'$ '.number_format($sumUSD,2),'N° transacciones'=>count($datos)];
            break;

        // ── 6. CRÉDITOS PENDIENTES ────────────────────────
        case 'creditos_pendientes':
            $cols = ['N° Factura','Fecha','Vence','Días venc.','Cliente','Vendedor','Total $','Pagado $','Saldo $','Estado'];
            $sql  = "SELECT v.numero,v.fecha,v.fecha_vencimiento,v.total_usd,v.monto_pagado_usd,
                            (v.total_usd-v.monto_pagado_usd) saldo,v.estado,
                            c.nombre cliente,vd.nombre vendedor
                     FROM ventas v
                     LEFT JOIN clientes c ON v.cliente_id=c.id
                     LEFT JOIN vendedores vd ON v.vendedor_id=vd.id
                     WHERE v.tipo_pago='credito' AND v.tipo_doc='venta'
                       AND v.estado IN('pendiente','parcial')
                       AND (v.total_usd-v.monto_pagado_usd)>0.01";
            $p = [];
            if ($clienteId)  { $sql.=" AND v.cliente_id=?";  $p[]=$clienteId; }
            if ($vendedorId) { $sql.=" AND v.vendedor_id=?"; $p[]=$vendedorId; }
            $sql.=" ORDER BY v.fecha_vencimiento ASC,v.id ASC";
            $raw = dbQuery($sql,$p);
            $sumSaldo = 0;
            foreach ($raw as $r) {
                $dias = $r['fecha_vencimiento']
                    ? (int)((time()-strtotime($r['fecha_vencimiento']))/86400)
                    : null;
                $diasStr = $dias !== null
                    ? ($dias>0 ? '<span style="color:var(--danger);font-weight:700">'.$dias.' días</span>' : ($dias===0?'Hoy':'—'))
                    : '—';
                $datos[] = [
                    clean($r['numero']),
                    date('d/m/Y',strtotime($r['fecha'])),
                    $r['fecha_vencimiento'] ? date('d/m/Y',strtotime($r['fecha_vencimiento'])) : '—',
                    $diasStr,
                    clean($r['cliente']),
                    clean($r['vendedor']??'—'),
                    '$ '.number_format($r['total_usd'],2),
                    '$ '.number_format($r['monto_pagado_usd'],2),
                    '$ '.number_format($r['saldo'],2),
                    ucfirst($r['estado']),
                ];
                $sumSaldo += $r['saldo'];
            }
            $vencidos = count(array_filter($raw,fn($r)=>$r['fecha_vencimiento']&&strtotime($r['fecha_vencimiento'])<time()));
            $subtotales = ['Saldo total pendiente'=>'$ '.number_format($sumSaldo,2),'Facturas vencidas'=>$vencidos,'Total créditos'=>count($datos)];
            break;

        // ── 7. INVENTARIO VALORADO ────────────────────────
        case 'inventario_valorado':
            $cols = ['Código','Producto','Categoría','Unidad','Stock','Stock Mín.','Precio May $','Precio Det $','Valor Mayor $','Valor Detal $','Estado'];
            $raw = dbQuery(
                "SELECT p.codigo,p.descripcion,cat.nombre categoria,p.unidad,p.stock_actual,p.stock_minimo,
                        ROUND(COALESCE(pm.precio_usd,0),2) precio_may,
                        ROUND(COALESCE(pd.precio_usd,0),2) precio_det
                 FROM productos p
                 LEFT JOIN categorias cat ON p.categoria_id=cat.id
                 LEFT JOIN precios pm ON pm.producto_id=p.id AND pm.tipo='mayorista'
                 LEFT JOIN precios pd ON pd.producto_id=p.id AND pd.tipo='detalista'
                 WHERE p.activo=1 AND p.departamento_id=1 ORDER BY p.descripcion"
            );
            $valMay = $valDet = 0;
            foreach ($raw as $r) {
                $vMay = round($r['stock_actual']*$r['precio_may'],2);
                $vDet = round($r['stock_actual']*$r['precio_det'],2);
                $est  = $r['stock_actual'] <= 0 ? '⛔ Sin stock'
                      : ($r['stock_actual'] <= $r['stock_minimo'] ? '⚠ Stock bajo' : '✓ OK');
                $datos[] = [
                    clean($r['codigo']),
                    clean($r['descripcion']),
                    clean($r['categoria']??'—'),
                    $r['unidad'],
                    number_format($r['stock_actual'],2),
                    number_format($r['stock_minimo'],2),
                    '$ '.number_format($r['precio_may'],2),
                    '$ '.number_format($r['precio_det'],2),
                    '$ '.number_format($vMay,2),
                    '$ '.number_format($vDet,2),
                    $est,
                ];
                $valMay += $vMay; $valDet += $vDet;
            }
            $sinStock = count(array_filter($raw,fn($r)=>$r['stock_actual']<=0));
            $stockBaj = count(array_filter($raw,fn($r)=>$r['stock_actual']>0&&$r['stock_actual']<=$r['stock_minimo']));
            $subtotales = ['Valor a precios Mayor'=>'$ '.number_format($valMay,2),'Valor a precios Detal'=>'$ '.number_format($valDet,2),'Sin stock'=>$sinStock,'Stock bajo'=>$stockBaj];
            break;
    }
}

include 'includes/header.php';
?>
<style>
.rep-layout { display:grid; grid-template-columns:260px 1fr; gap:20px; align-items:start; }
.rep-nav     { position:sticky; top:80px; }
.rep-nav-item{
    display:flex; align-items:center; gap:12px; padding:12px 16px;
    border-radius:var(--radius); text-decoration:none; color:var(--text);
    font-size:13px; font-weight:600; transition:.15s; margin-bottom:4px;
    border:1px solid transparent;
}
.rep-nav-item:hover { background:#f0f7ff; border-color:var(--border); }
.rep-nav-item.active { background:var(--primary); color:white; }
.rep-nav-item .rep-icon { width:36px;height:36px;border-radius:8px;display:flex;align-items:center;justify-content:center;font-size:15px;flex-shrink:0;background:rgba(255,255,255,.15); }
.rep-nav-item:not(.active) .rep-icon { background:#f0f2f5;color:var(--primary); }
.rep-nav-item.active .rep-icon { background:rgba(255,255,255,.2);color:white; }
.rep-info { font-size:10.5px;opacity:.8;margin-top:2px;font-weight:400; }
.filter-bar { background:white;border:1px solid var(--border);border-radius:var(--radius);padding:14px 18px;margin-bottom:16px; }
.sub-box { display:flex;gap:10px;flex-wrap:wrap;margin-bottom:14px; }
.sub-chip { background:#f8fafc;border:1px solid var(--border);border-radius:20px;padding:5px 14px;font-size:12px;font-weight:600; }
.sub-chip strong { color:var(--primary); }
.col-num { text-align:right; }
.table-rep th { white-space:nowrap; }
.sin-reporte { text-align:center; padding:80px 40px; color:var(--text-muted); }
</style>

<div class="rep-layout">

<!-- NAVEGACIÓN IZQUIERDA -->
<div class="rep-nav">
    <div class="card">
        <div class="card-header" style="padding:14px 16px">
            <i class="fa-solid fa-chart-bar text-primary"></i>
            <h5>Reportes</h5>
        </div>
        <div style="padding:8px">
        <?php foreach($catalogo as $key=>[$label,$icon,$desc]): ?>
        <a href="reportes.php?tipo=<?=$key?>&fecha_ini=<?=$fechaIni?>&fecha_fin=<?=$fechaFin?>"
           class="rep-nav-item <?=$tipoRep===$key?'active':''?>">
            <div class="rep-icon"><i class="fa-solid <?=$icon?>"></i></div>
            <div>
                <?=$label?>
                <div class="rep-info"><?=$desc?></div>
            </div>
        </a>
        <?php endforeach; ?>
        </div>
    </div>
</div>

<!-- CONTENIDO DERECHO -->
<div>

<?php if(!$tipoRep): ?>
<!-- PANTALLA DE BIENVENIDA -->
<div class="card">
<div class="card-body sin-reporte">
    <i class="fa-solid fa-chart-pie" style="font-size:64px;opacity:.15;display:block;margin-bottom:18px"></i>
    <h3>Selecciona un reporte</h3>
    <p style="margin-top:8px;max-width:380px;margin-left:auto;margin-right:auto">
        Escoge uno de los reportes del panel izquierdo para ver los datos con filtros de fecha, cliente y vendedor.
    </p>
</div>
</div>

<?php else: ?>

<!-- FILTROS -->
<div class="filter-bar">
<form method="GET" class="d-flex gap-2 align-center" style="flex-wrap:wrap">
    <input type="hidden" name="tipo" value="<?=$tipoRep?>">
    <span class="fw-bold fs-12 text-muted"><?=clean($catalogo[$tipoRep][0]??'')?></span>
    <input type="date" name="fecha_ini" class="form-control" value="<?=$fechaIni?>" style="max-width:148px">
    <span class="text-muted">a</span>
    <input type="date" name="fecha_fin" class="form-control" value="<?=$fechaFin?>" style="max-width:148px">
    <?php if(in_array($tipoRep,['documentos_periodo','ventas_cliente','creditos_pendientes','cobros'])): ?>
    <select name="cliente" class="form-select select2" style="max-width:180px">
        <option value="">Todos los clientes</option>
        <?php foreach($clientes as $cl): ?>
        <option value="<?=$cl['id']?>" <?=$clienteId==$cl['id']?'selected':''?>><?=clean($cl['nombre'])?></option>
        <?php endforeach; ?>
    </select>
    <?php endif; ?>
    <?php if(in_array($tipoRep,['documentos_periodo','ventas_vendedor','ventas_cliente','creditos_pendientes'])): ?>
    <select name="vendedor" class="form-select select2" style="max-width:180px">
        <option value="">Todos los vendedores</option>
        <?php foreach($vendedores as $vd): ?>
        <option value="<?=$vd['id']?>" <?=$vendedorId==$vd['id']?'selected':''?>><?=clean($vd['nombre'])?></option>
        <?php endforeach; ?>
    </select>
    <?php endif; ?>
    <?php if(in_array($tipoRep,['documentos_periodo','ventas_cliente'])): ?>
    <select name="tipo_cli" class="form-select" style="max-width:130px">
        <option value="">Ambos</option>
        <option value="mayorista" <?=$tipoCli==='mayorista'?'selected':''?>>Mayorista</option>
        <option value="detalista" <?=$tipoCli==='detalista'?'selected':''?>>Detalista</option>
    </select>
    <?php endif; ?>
    <button type="submit" class="btn btn-primary btn-sm"><i class="fa-solid fa-filter"></i> Filtrar</button>
</form>
</div>

<!-- SUBTOTALES -->
<?php if(!empty($subtotales)): ?>
<div class="sub-box">
    <?php foreach($subtotales as $lab=>$val): ?>
    <div class="sub-chip"><?=$lab?>: <strong><?=$val?></strong></div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<!-- TABLA DE RESULTADOS -->
<?php if(empty($datos)): ?>
<div class="card"><div class="card-body sin-reporte">
    <i class="fa-solid fa-inbox" style="font-size:48px;opacity:.2;display:block;margin-bottom:14px"></i>
    <h4>Sin resultados</h4>
    <p style="margin-top:6px">No hay datos para los filtros seleccionados. Prueba con otro período o quita los filtros.</p>
</div></div>
<?php else: ?>

<div class="card">
<div class="card-header">
    <i class="fa-solid <?=$catalogo[$tipoRep][1]?> text-primary"></i>
    <h5><?=$titulo?> — <?=count($datos)?> registros</h5>
    <div class="d-flex gap-2" style="margin-left:auto">
        <button onclick="exportExcel('tablaRep','<?=addslashes($titulo)?>_Tauro')" class="btn btn-sm btn-light">
            <i class="fa-solid fa-table-cells"></i> Excel
        </button>
        <button onclick="window.print()" class="btn btn-sm btn-light">
            <i class="fa-solid fa-print"></i> Imprimir
        </button>
    </div>
</div>
<div class="card-body p-0">
<div class="table-wrapper">
<table class="table table-rep" id="tablaRep" style="font-size:12.5px">
    <thead>
        <tr>
        <?php foreach($cols as $col): ?>
        <th><?=clean($col)?></th>
        <?php endforeach; ?>
        </tr>
    </thead>
    <tbody>
    <?php foreach($datos as $fila): ?>
    <tr>
        <?php foreach($fila as $celda): ?>
        <td class="<?=str_starts_with(ltrim($celda??''),'$')?'col-num':''?>"><?=$celda?></td>
        <?php endforeach; ?>
    </tr>
    <?php endforeach; ?>
    </tbody>
</table>
</div>
</div>
</div>
<?php endif; ?>
<?php endif; ?>

</div><!-- /contenido -->
</div><!-- /layout -->

<?php
$extraScript = "
<script>
\$(document).ready(function() {
    if (\$.fn.DataTable.isDataTable('#tablaRep')) \$('#tablaRep').DataTable().destroy();
    if (\$('#tablaRep').length) {
        \$('#tablaRep').DataTable({
            language: dtLang,
            pageLength: 30,
            order: [],
            columnDefs: [{ targets: '_all', defaultContent: '' }]
        });
    }
});
</script>";
include 'includes/footer.php';
?>
