<?php
/**
 * imprimir.php — Plantillas de impresión profesionales
 * ?id=X  &template=cotizacion|despacho|venta  &moneda=USD|BS
 */
require_once 'config/config.php';
require_once 'functions/db.php';
require_once 'functions/helpers.php';
require_once 'functions/auth.php';

requireLogin();

$vid      = cleanInt($_GET['id']       ?? 0);
$template = clean($_GET['template']    ?? '');
$moneda   = clean($_GET['moneda']      ?? 'USD');  // USD o BS
if (!in_array($moneda, ['USD','BS'])) $moneda = 'USD';

if (!$vid) { header('Location: documentos.php'); exit; }

$venta = dbQueryOne(
    "SELECT v.*, c.nombre c_nombre, c.rif c_rif, c.telefono c_tel, c.direccion c_dir,
            vd.nombre v_nombre,
            u.nombre u_nombre, u.apellido u_apellido,
            tc.tasa_bs
     FROM ventas v
     LEFT JOIN clientes c   ON v.cliente_id  = c.id
     LEFT JOIN vendedores vd ON v.vendedor_id = vd.id
     LEFT JOIN usuarios u   ON v.usuario_id  = u.id
     LEFT JOIN tasas_cambio tc ON v.tasa_cambio_id = tc.id
     WHERE v.id = ?", [$vid]
);
if (!$venta) { header('Location: documentos.php'); exit; }

$detalles = dbQuery("SELECT vd.*,p.codigo FROM venta_detalles vd LEFT JOIN productos p ON vd.producto_id=p.id WHERE vd.venta_id=? ORDER BY vd.id", [$vid]);
$tipoDoc  = $template ?: $venta['tipo_doc'];

// Empresa
$empresa = [
    'nombre'    => getConfig('empresa_nombre', APP_NAME),
    'rif'       => getConfig('empresa_rif',''),
    'direccion' => getConfig('empresa_direccion',''),
    'telefono'  => getConfig('empresa_telefono',''),
    'email'     => getConfig('empresa_email',''),
    'logo'      => getConfig('logo_path',''),
];

$tipoLabels = ['cotizacion'=>'DESPACHO','despacho'=>'DESPACHO','venta'=>'FACTURA'];
$tipoLabel  = $tipoLabels[$tipoDoc] ?? strtoupper($tipoDoc);
$colores    = ['cotizacion'=>'#2980b9','despacho'=>'#2980b9','venta'=>'#27ae60'];
$colorDoc   = $colores[$tipoDoc] ?? '#c0392b';

$tasaVal    = (float)($venta['tasa_bs'] ?? $venta['tasa_valor'] ?? 1);
$fletePct   = (float)($venta['flete_pct'] ?? 0);
$fleteUSD   = (float)($venta['flete_monto_usd'] ?? 0);
$fleteBS    = $fleteUSD * $tasaVal;

// En BS convertimos todos los montos
$factorConversion = ($moneda === 'BS') ? $tasaVal : 1;
$simbolo          = ($moneda === 'BS') ? '' : '$';
$formatoMonto = fn(float $usd) => ($moneda === 'BS')
    ? number_format($usd * $tasaVal, 2, ',', '.')
    : '$ ' . number_format($usd, 2, '.', ',');
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= clean($empresa['nombre']) ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <style>
        :root { --color-doc: <?= $colorDoc ?>; --font: 'Inter',Arial,sans-serif; --border:#e2e8f0; --text:#2d3748; --muted:#718096; }
        *  { box-sizing:border-box; margin:0; padding:0; }
        body { font-family:var(--font); color:var(--text); background:#f5f5f5; padding:16px; font-size:13px; }

        .toolbar {
            max-width:860px; margin:0 auto 14px;
            background:white; border:1px solid var(--border); border-radius:8px;
            padding:10px 16px; display:flex; gap:8px; align-items:center;
        }
        .toolbar label { font-size:12px; font-weight:600; color:var(--muted); }
        .btn-tool { display:inline-flex;align-items:center;gap:5px;padding:7px 13px;border-radius:6px;border:none;cursor:pointer;font-size:12px;font-weight:600;font-family:var(--font);text-decoration:none; }
        .btn-print { background:var(--color-doc); color:white; }
        .btn-back  { background:#f0f2f5; color:#333; }
        .btn-cur   { padding:6px 12px;border:2px solid var(--border);border-radius:6px;background:white;cursor:pointer;font-family:var(--font);font-size:12px;font-weight:600; }
        .btn-cur.active { border-color:var(--color-doc); background:var(--color-doc); color:white; }

        .doc { max-width:860px; margin:0 auto; background:white; border-radius:10px; box-shadow:0 4px 20px rgba(0,0,0,.1); padding:36px; position:relative; }

        /* Marca de agua tipo doc */
        <?php if (in_array($tipoDoc, ['cotizacion','despacho'])): ?>
        .doc::before { content:'DESPACHO'; position:fixed;top:50%;left:50%;transform:translate(-50%,-50%) rotate(-30deg);font-size:100px;font-weight:900;color:rgba(41,128,185,.05);pointer-events:none;white-space:nowrap;z-index:0; }
        <?php endif; ?>

        .doc-header { display:flex;justify-content:space-between;align-items:flex-start;padding-bottom:18px;border-bottom:3px solid var(--color-doc);margin-bottom:20px; }
        .empresa-logo { max-width:100px;max-height:55px;object-fit:contain;display:block;margin-bottom:8px; }
        .empresa-nombre { font-size:18px;font-weight:800;color:var(--color-doc); }
        .empresa-info   { font-size:11.5px;color:var(--muted);margin-top:3px;line-height:1.7; }
        .doc-num-box    { text-align:right; }
        .doc-tipo-label { font-size:11px;text-transform:uppercase;letter-spacing:2px;color:var(--muted);font-weight:700; }
        .doc-numero     { font-size:28px;font-weight:900;color:var(--color-doc);line-height:1.1; }

        .info-grid { display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-bottom:18px; }
        .info-box  { background:#f8fafc;border:1px solid var(--border);border-radius:8px;padding:12px; }
        .info-box h6 { font-size:9.5px;text-transform:uppercase;letter-spacing:1px;color:var(--muted);font-weight:700;margin-bottom:7px;border-bottom:1px solid var(--border);padding-bottom:5px; }
        .info-row { display:flex;justify-content:space-between;padding:2px 0;font-size:12px; }
        .info-row label { color:var(--muted); }

        table.ptable { width:100%;border-collapse:collapse;margin-bottom:16px;font-size:12px; }
        table.ptable th { background:var(--color-doc);color:white;padding:8px 10px;text-align:left;font-size:10px;text-transform:uppercase;letter-spacing:.7px;font-weight:700; }
        table.ptable th.r, table.ptable td.r { text-align:right; }
        table.ptable td { padding:8px 10px;border-bottom:1px solid var(--border);vertical-align:middle; }
        table.ptable tbody tr:nth-child(even) { background:#f9fafc; }
        table.ptable tfoot td { font-weight:700;background:#f0f2f5;border-top:2px solid var(--border); }

        .totales-box { width:280px;margin-left:auto; }
        .tot-row { display:flex;justify-content:space-between;padding:5px 0;font-size:13px;border-bottom:1px solid var(--border); }
        .tot-final { font-size:16px;font-weight:800;color:var(--color-doc);border-bottom:none;margin-top:4px; }

        .nota-box { background:#fffbeb;border:1px solid #fde68a;border-radius:6px;padding:9px 12px;margin-top:12px;font-size:11.5px; }
        .doc-footer { margin-top:30px;border-top:1px solid var(--border);padding-top:14px;display:flex;justify-content:space-between;font-size:11px;color:var(--muted); }
        .firma-box  { width:180px;text-align:center; }
        .firma-box .linea { border-top:1px solid var(--text);margin-bottom:5px; }

        /* Modo BS: toda la factura en negritas */
        .doc-bs, .doc-bs td, .doc-bs th, .doc-bs span, .doc-bs label,
        .doc-bs div, .doc-bs p, .doc-bs .info-row label { font-weight:700 !important; }

        /* Elimina encabezado/pie del navegador en PDF (fecha, URL, nº página) */
        @page {
            margin: 10mm 10mm 10mm 10mm;
            /* Al definir margen aquí, Chrome/Edge/Firefox suprimen
               sus propios headers y footers automáticamente */
        }
        @media print {
            body { background:white; padding:0; }
            .toolbar { display:none!important; }
            .doc { box-shadow:none; border-radius:0; padding:10mm; }
        }
    </style>
</head>
<body>

<!-- TOOLBAR -->
<div class="toolbar no-print">
    <a href="ver_venta.php?id=<?= $vid ?>" class="btn-tool btn-back">
        <i class="fa-solid fa-arrow-left"></i> Volver
    </a>
    <span style="flex:1;font-weight:700;color:#333;font-size:13px">
        <?= $tipoLabel ?> — <?= clean($venta['numero']) ?>
    </span>

    <!-- Selector de moneda -->
    <label>Moneda de impresión:</label>
    <a href="?id=<?= $vid ?>&template=<?= $tipoDoc ?>&moneda=USD"
       class="btn-cur <?= $moneda==='USD'?'active':'' ?>">
        USD ($)
    </a>
    <a href="?id=<?= $vid ?>&template=<?= $tipoDoc ?>&moneda=BS"
       class="btn-cur <?= $moneda==='BS'?'active':'' ?>">
        Bs.s
    </a>

    <!-- Selector de plantilla -->
    <label style="margin-left:8px">Plantilla:</label>
    <a href="?id=<?= $vid ?>&template=despacho&moneda=<?= $moneda ?>" class="btn-tool btn-back" style="<?= in_array($tipoDoc,['cotizacion','despacho'])?'background:#2980b9;color:white':'' ?>">Despacho</a>
    <a href="?id=<?= $vid ?>&template=venta&moneda=<?= $moneda ?>"   class="btn-tool btn-back" style="<?= $tipoDoc==='venta'?'background:#27ae60;color:white':'' ?>">Factura</a>

    <a href="descargar_word.php?id=<?= $vid ?>&template=<?= $tipoDoc ?>&moneda=<?= $moneda ?>"
       class="btn-tool" style="background:#1D6FA4;color:white;">
        <i class="fa-solid fa-file-word"></i> Descargar Word
    </a>
    <button onclick="window.print()" class="btn-tool btn-print">
        <i class="fa-solid fa-print"></i> Imprimir / PDF
    </button>
</div>

<!-- DOCUMENTO -->
<div class="doc<?= $moneda === 'BS' ? ' doc-bs' : '' ?>">

    <!-- ENCABEZADO -->
    <?php if ($moneda !== 'BS'): ?>
    <div class="doc-header">
        <div>
            <?php if ($empresa['logo'] && file_exists(ROOT_PATH.'/assets/uploads/'.$empresa['logo'])): ?>
            <img src="<?= UPLOADS_URL.'/'.clean($empresa['logo']) ?>" class="empresa-logo" alt="Logo">
            <?php endif; ?>
            <div class="empresa-nombre"><?= clean($empresa['nombre']) ?></div>
            <div class="empresa-info">
                <?php if ($empresa['rif']): ?>RIF: <?= clean($empresa['rif']) ?><br><?php endif; ?>
                <?php if ($empresa['direccion']): ?><?= clean($empresa['direccion']) ?><br><?php endif; ?>
                <?php if ($empresa['telefono']): ?>Tel: <?= clean($empresa['telefono']) ?><?php endif; ?>
                <?php if ($empresa['email']): ?> | <?= clean($empresa['email']) ?><?php endif; ?>
            </div>
        </div>
        <div class="doc-num-box">
            <div class="doc-tipo-label"><?= $tipoLabel ?></div>
            <div class="doc-numero"><?= clean($venta['numero']) ?></div>
            <div style="font-size:11.5px;color:var(--muted);margin-top:6px">
                Fecha: <strong><?= date('d/m/Y',strtotime($venta['fecha'])) ?></strong>
            </div>
            <?php if ($venta['fecha_vencimiento'] && $tipoDoc!=='cotizacion'): ?>
            <div style="font-size:11.5px;color:var(--muted)">
                Vence: <strong><?= date('d/m/Y',strtotime($venta['fecha_vencimiento'])) ?></strong>
            </div>
            <?php endif; ?>
        </div>
    </div>
    <?php else: ?>
    <!-- BS: espacio en blanco para superponer hoja física Tauro -->
    <div style="height:160px; margin-bottom:0px; position:relative;">
        <!-- Fecha de emisión alineada al área de las cajitas de la hoja -->
        <div style="position:absolute; top:12px; right:0; display:flex; gap:0; font-size:11px; font-weight:700; text-align:center;">
            <?php
                $fechaDoc = strtotime($venta['fecha']);
                $dia = date('d', $fechaDoc);
                $mes = date('m', $fechaDoc);
                $anio = date('Y', $fechaDoc);
            ?>
            <div style="border:1px solid #333; width:36px; padding:3px 0; line-height:1.3;"><?= $dia ?></div>
            <div style="border:1px solid #333; border-left:none; width:36px; padding:3px 0; line-height:1.3;"><?= $mes ?></div>
            <div style="border:1px solid #333; border-left:none; width:48px; padding:3px 0; line-height:1.3;"><?= $anio ?></div>
        </div>
    </div>
    <?php endif; ?>

    <!-- INFO CLIENTE / DOC -->
    <div class="info-grid">
        <div class="info-box">
            <h6>Datos del Cliente</h6>
            <div class="info-row"><label>Nombre / Razón Social</label><strong><?= clean($venta['c_nombre']) ?></strong></div>
            <?php if (!empty($venta['c_rif'])): ?>
            <div class="info-row"><label>RIF / CI</label><strong><?= clean($venta['c_rif']) ?></strong></div>
            <?php endif; ?>
            <?php if (!empty($venta['c_tel'])): ?>
            <div class="info-row"><label>Teléfono</label><strong><?= clean($venta['c_tel']) ?></strong></div>
            <?php endif; ?>
            <?php if (!empty($venta['c_dir'])): ?>
            <div class="info-row"><label>Dirección</label><strong style="max-width:60%;text-align:right;font-size:11px"><?= clean($venta['c_dir']) ?></strong></div>
            <?php endif; ?>
        </div>
        <div class="info-box">
            <h6>Datos del Documento</h6>
            <div class="info-row"><label>Vendedor</label><strong><?= clean($venta['v_nombre']??'N/A') ?></strong></div>
            <div class="info-row"><label>Tipo de cliente</label><strong><?= ucfirst($venta['tipo_cliente']) ?></strong></div>
            <div class="info-row"><label>Tipo de pago</label><strong><?= ucfirst($venta['tipo_pago']) ?></strong></div>
        </div>
    </div>

    <!-- TABLA DE PRODUCTOS -->
    <table class="ptable">
        <thead>
            <tr>
                <th style="width:35px">#</th>
                <th style="width:80px">Código</th>
                <th>Descripción</th>
                <th class="r" style="width:80px">Precio <?= $simbolo ?></th>
                <th class="r" style="width:70px">Cant.</th>
                <th class="r" style="width:100px">Monto <?= $simbolo ?></th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($detalles as $k => $d):
            $precioMostrar = ($moneda==='BS') ? $d['precio_usd'] * $tasaVal : $d['precio_usd'];
            $montoMostrar  = ($moneda==='BS') ? $d['monto_usd']  * $tasaVal : $d['monto_usd'];
        ?>
        <tr>
            <td><?= $k+1 ?></td>
            <td class="font-mono" style="font-size:10.5px"><strong><?= clean($d['ref']??$d['codigo']??'') ?></strong></td>
            <td><?= clean($d['descripcion']) ?></td>
            <td class="r">
                <?= $moneda==='BS'
                    ? number_format($precioMostrar,2,',','.')
                    : '$ '.number_format($precioMostrar,2,'.',',' ) ?>
            </td>
            <td class="r"><strong><?= number_format($d['cantidad'],2) ?></strong></td>
            <td class="r"><strong>
                <?= $moneda==='BS'
                    ? number_format($montoMostrar,2,',','.')
                    : '$ '.number_format($montoMostrar,2,'.',',') ?>
            </strong></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
        <tfoot>
            <?php
            $totalMostrar = ($moneda==='BS') ? $venta['total_usd']*$tasaVal : $venta['total_usd'];
            $pagoMostrar  = ($moneda==='BS') ? $venta['monto_pagado_usd']*$tasaVal : $venta['monto_pagado_usd'];
            $saldoMostrar = $totalMostrar - $pagoMostrar;
            ?>
            <tr>
                <td colspan="4" class="r">SUBTOTAL:</td>
                <td class="r"><?= array_sum(array_column($detalles,'cantidad')) ?></td>
                <td class="r">
                    <?= $moneda==='BS'
                        ? number_format($totalMostrar,2,',','.')
                        : '$ '.number_format($totalMostrar,2,'.',',') ?>
                </td>
            </tr>
            <?php if ($fletePct > 0): ?>
            <tr>
                <td colspan="5" class="r">FLETE (<?= number_format($fletePct,2) ?>%):</td>
                <td class="r" style="color:var(--warning)">
                    <?= $moneda==='BS'
                        ? number_format($fleteBS,2,',','.')
                        : '$ '.number_format($fleteUSD,2,'.',',') ?>
                </td>
            </tr>
            <?php endif; ?>
        </tfoot>
    </table>

    <!-- TOTALES -->
    <div class="totales-box">
        <?php
        $ivaRate = 0.16;
        if ($moneda === 'BS'):
            $baseBS        = $totalMostrar;
            $ivaBS         = $baseBS * $ivaRate;
            $totalConIvaBS = $baseBS + $ivaBS;
            $saldoConIvaBS = $totalConIvaBS - $pagoMostrar;
        ?>
        <div class="tot-row">
            <strong>Subtotal:</strong>
            <strong><?= number_format($baseBS,2,',','.') ?></strong>
        </div>
        <div class="tot-row">
            <strong>IVA (16%):</strong>
            <strong><?= number_format($ivaBS,2,',','.') ?></strong>
        </div>
        <div class="tot-row" style="border-top:2px solid var(--color-doc); margin-top:4px; padding-top:4px;">
            <strong>TOTAL:</strong>
            <strong style="color:var(--color-doc)"><?= number_format($totalConIvaBS,2,',','.') ?></strong>
        </div>
        <?php if ($tipoDoc !== 'cotizacion'): ?>
        <div class="tot-row">
            <span>Pagado:</span>
            <strong style="color:#27ae60"><?= number_format($pagoMostrar,2,',','.') ?></strong>
        </div>
        <div class="tot-row tot-final">
            <span>SALDO:</span>
            <strong><?= number_format($saldoConIvaBS,2,',','.') ?></strong>
        </div>
        <?php endif; ?>

        <?php else: // USD — sin IVA ?>
        <div class="tot-row">
            <span>Total USD:</span>
            <strong>$ <?= number_format($totalMostrar,2,'.',',') ?></strong>
        </div>
        <?php if ($tipoDoc !== 'cotizacion'): ?>
        <div class="tot-row">
            <span>Pagado:</span>
            <strong style="color:#27ae60">$ <?= number_format($pagoMostrar,2,'.',',') ?></strong>
        </div>
        <div class="tot-row tot-final">
            <span>SALDO:</span>
            <strong>$ <?= number_format($saldoMostrar,2,'.',',') ?></strong>
        </div>
        <?php else: ?>
        <div class="tot-row tot-final">
            <span>TOTAL USD:</span>
            <strong>$ <?= number_format($totalMostrar,2,'.',',') ?></strong>
        </div>
        <?php endif; ?>
        <?php endif; ?>
    </div>

    <?php if ($venta['notas']): ?>
    <div class="nota-box"><strong>Observaciones:</strong> <?= clean($venta['notas']) ?></div>
    <?php endif; ?>

    <?php if (in_array($tipoDoc, ['cotizacion','despacho'])): ?>
    <div style="margin-top:14px;font-size:11px;color:var(--muted);border:1px dashed var(--border);border-radius:6px;padding:9px 12px">
        <i class="fa-solid fa-circle-info"></i>
        Este despacho tiene validez de 15 días desde la fecha de emisión. Los precios están sujetos a la tasa de cambio vigente al momento de la venta.
    </div>
    <?php endif; ?>

    <!-- PIE DE DOCUMENTO -->
    <?php if ($moneda !== 'BS'): ?>
    <div class="doc-footer">
        <div>
            <div><?= clean($empresa['nombre']) ?></div>
            <div style="margin-top:2px">Emitido: <?= date('d/m/Y H:i') ?></div>
        </div>
        <?php if ($tipoDoc !== 'cotizacion'): ?>
        <div class="firma-box">
            <div style="height:32px"></div>
            <div class="linea"></div>
            <div>Firma del Receptor</div>
        </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>
</div><!-- /doc -->
</body>
</html>
