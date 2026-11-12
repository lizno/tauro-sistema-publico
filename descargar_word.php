<?php
/**
 * descargar_word.php — DOCX real, compacto, todo en 1 hoja cuando sea posible
 * Encabezado de tabla se repite en pág 2+. Totales/firma siempre al final.
 * SIEMPRE: TODO el texto en NEGRO y NEGRITA (USD y BS).
 */
require_once 'config/config.php';
require_once 'functions/db.php';
require_once 'functions/helpers.php';
require_once 'functions/auth.php';

requireLogin();

// ── Chequeo defensivo: ZipArchive ─────────────────────────
if (!class_exists('ZipArchive')) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    die('ERROR: La extensión ZipArchive de PHP no está habilitada en el servidor. Habilita php_zip en php.ini.');
}

$vid      = cleanInt($_GET['id']    ?? 0);
$template = clean($_GET['template'] ?? '');
$moneda   = clean($_GET['moneda']   ?? 'USD');
if (!in_array($moneda, ['USD','BS'])) $moneda = 'USD';
if (!$vid) { header('Location: documentos.php'); exit; }

$venta = dbQueryOne(
    "SELECT v.*, c.nombre c_nombre, c.rif c_rif, c.telefono c_tel, c.direccion c_dir,
            vd.nombre v_nombre, tc.tasa_bs
     FROM ventas v
     LEFT JOIN clientes c    ON v.cliente_id  = c.id
     LEFT JOIN vendedores vd ON v.vendedor_id = vd.id
     LEFT JOIN usuarios u    ON v.usuario_id  = u.id
     LEFT JOIN tasas_cambio tc ON v.tasa_cambio_id = tc.id
     WHERE v.id = ?", [$vid]
);
if (!$venta) { header('Location: documentos.php'); exit; }

$detalles = dbQuery(
    "SELECT vd.*, p.codigo FROM venta_detalles vd
     LEFT JOIN productos p ON vd.producto_id = p.id
     WHERE vd.venta_id = ? ORDER BY vd.id", [$vid]
);

$tipoDoc    = $template ?: $venta['tipo_doc'];
$tipoLabels = ['cotizacion'=>'DESPACHO','despacho'=>'DESPACHO','venta'=>'FACTURA'];
$tipoLabel  = $tipoLabels[$tipoDoc] ?? strtoupper($tipoDoc);
$coloresMap = ['cotizacion'=>'2980B9','despacho'=>'2980B9','venta'=>'27AE60'];
// Fondo de la cabecera de la tabla (se mantiene el color de marca)
$COLOR_BG   = $coloresMap[$tipoDoc] ?? 'C0392B';
// TODO EL TEXTO EN NEGRO
$COLOR      = '000000';

$empresa = [
    'nombre'    => getConfig('empresa_nombre', APP_NAME),
    'rif'       => getConfig('empresa_rif',''),
    'direccion' => getConfig('empresa_direccion',''),
    'telefono'  => getConfig('empresa_telefono',''),
    'logo'      => getConfig('logo_path',''),
];

$tasaVal  = (float)($venta['tasa_bs'] ?? 1);
$simbolo  = ($moneda === 'BS') ? '' : '$';
$fletePct = (float)($venta['flete_pct'] ?? 0);
$fleteUSD = (float)($venta['flete_monto_usd'] ?? 0);
$totalUSD = (float)($venta['total_usd'] ?? 0);
$pagUSD   = (float)($venta['monto_pagado_usd'] ?? 0);
$saldoUSD = $totalUSD - $pagUSD;

$fmt = function(float $v) use ($moneda, $tasaVal): string {
    return $moneda === 'BS'
        ? number_format($v*$tasaVal,2,',','.')
        : '$ '.number_format($v,2,'.',',');
};

// Logo
$logoData = null; $logoExt = 'png'; $logoMime = 'image/png';
if (!empty($empresa['logo'])) {
    $lp = ROOT_PATH.'/assets/uploads/'.$empresa['logo'];
    if (file_exists($lp)) {
        $logoData = file_get_contents($lp);
        $logoExt  = strtolower(pathinfo($lp, PATHINFO_EXTENSION));
        $logoMime = match($logoExt) {
            'jpg','jpeg'=>'image/jpeg','gif'=>'image/gif','webp'=>'image/webp',default=>'image/png'
        };
    }
}

/* ═══════════════════════════════════════════════════════════
   HELPERS
   ═══════════════════════════════════════════════════════════ */
function wx(string $s): string { return htmlspecialchars($s, ENT_XML1, 'UTF-8'); }

// Párrafo compacto — SIEMPRE negrita y NEGRO (USD y BS)
// $indRight: si > 0 añade <w:ind w:right="..."/> (mueve el texto a la izquierda)
function p(array $runs, string $align='left', int $before=0, int $after=0, int $indRight=0): string {
    $forceB = true;   // SIEMPRE negrita (USD y BS)
    $forceC = true;   // SIEMPRE color negro (USD y BS)
    $ind    = $indRight > 0 ? "<w:ind w:right=\"{$indRight}\"/>" : '';
    $xml = '<w:p><w:pPr>'
         . "<w:jc w:val=\"{$align}\"/>"
         . $ind
         . "<w:spacing w:before=\"{$before}\" w:after=\"{$after}\" w:line=\"240\" w:lineRule=\"auto\"/>"
         . '</w:pPr>';
    foreach ($runs as $r) {
        if (!isset($r['text']) || $r['text'] === '') continue;
        $sz   = ($r['sz'] ?? 20);
        $bold = $forceB || !empty($r['b']);
        // SIEMPRE negro (ignora cualquier 'col' que venga)
        $col  = $forceC ? '000000' : ($r['col'] ?? '');
        $rpr = '<w:rPr>'
             . ($bold              ? '<w:b/><w:bCs/>' : '')
             . (!empty($r['i'])   ? '<w:i/>' : '')
             . "<w:sz w:val=\"{$sz}\"/><w:szCs w:val=\"{$sz}\"/>"
             . ($col !== ''        ? "<w:color w:val=\"{$col}\"/>" : '')
             . '</w:rPr>';
        $xml .= "<w:r>{$rpr}<w:t xml:space=\"preserve\">".wx($r['text'])."</w:t></w:r>";
    }
    return $xml.'</w:p>';
}

function pEmpty(int $h=60): string {
    return "<w:p><w:pPr><w:spacing w:before=\"0\" w:after=\"0\" w:line=\"{$h}\" w:lineRule=\"exact\"/></w:pPr></w:p>";
}

function hRule(string $col, int $sz=12, int $before=0, int $after=60): string {
    return "<w:p><w:pPr>"
          ."<w:pBdr><w:bottom w:val=\"single\" w:sz=\"{$sz}\" w:space=\"1\" w:color=\"{$col}\"/></w:pBdr>"
          ."<w:spacing w:before=\"{$before}\" w:after=\"{$after}\"/>"
          ."</w:pPr></w:p>";
}

// Celda con padding simétrico en twips
function tc(string $content, int $w, string $fill='', int $pad=80,
            string $va='top', array $brd=[]): string {
    $shd = $fill ? "<w:shd w:val=\"clear\" w:color=\"auto\" w:fill=\"{$fill}\"/>" : '';
    $b   = '';
    if ($brd) {
        $b = '<w:tcBorders>';
        foreach ($brd as $side => $d)
            $b .= "<w:{$side} w:val=\"{$d['v']}\" w:sz=\"{$d['sz']}\" w:space=\"0\" w:color=\"{$d['c']}\"/>";
        $b .= '</w:tcBorders>';
    }
    $mar = "<w:tcMar>"
          ."<w:top w:w=\"{$pad}\" w:type=\"dxa\"/><w:left w:w=\"{$pad}\" w:type=\"dxa\"/>"
          ."<w:bottom w:w=\"{$pad}\" w:type=\"dxa\"/><w:right w:w=\"{$pad}\" w:type=\"dxa\"/>"
          ."</w:tcMar>";
    return "<w:tc><w:tcPr>"
          ."<w:tcW w:w=\"{$w}\" w:type=\"dxa\"/>{$shd}{$b}<w:vAlign w:val=\"{$va}\"/>{$mar}"
          ."</w:tcPr>{$content}</w:tc>";
}

function tr(string $cells, bool $hdr=false, bool $noSplit=true): string {
    $tp  = $hdr     ? '<w:tblHeader/>' : '';
    $ns  = $noSplit ? '<w:cantSplit/>'  : '';
    return "<w:tr><w:trPr>{$tp}{$ns}</w:trPr>{$cells}</w:tr>";
}

function tbl(string $rows, int $w, bool $borders=false): string {
    $b = $borders
       ? '<w:tblBorders>'
         .'<w:top w:val="single" w:sz="4" w:color="E2E8F0"/>'
         .'<w:left w:val="single" w:sz="4" w:color="E2E8F0"/>'
         .'<w:bottom w:val="single" w:sz="4" w:color="E2E8F0"/>'
         .'<w:right w:val="single" w:sz="4" w:color="E2E8F0"/>'
         .'<w:insideH w:val="single" w:sz="4" w:color="E2E8F0"/>'
         .'<w:insideV w:val="none"/>'
         .'</w:tblBorders>'
       : '';
    return "<w:tbl><w:tblPr>"
          ."<w:tblW w:w=\"{$w}\" w:type=\"dxa\"/>"
          ."<w:tblLayout w:type=\"fixed\"/>"
          ."<w:tblCellMar><w:top w:w=\"0\" w:type=\"dxa\"/><w:bottom w:w=\"0\" w:type=\"dxa\"/></w:tblCellMar>"
          ."{$b}"
          ."</w:tblPr>{$rows}</w:tbl>";
}

function img(string $rId, int $emu): string {
    return "<w:r><w:drawing>"
          ."<wp:inline distT=\"0\" distB=\"0\" distL=\"0\" distR=\"0\" "
          ."xmlns:wp=\"http://schemas.openxmlformats.org/drawingml/2006/wordprocessingDrawing\">"
          ."<wp:extent cx=\"{$emu}\" cy=\"{$emu}\"/>"
          ."<wp:effectExtent l=\"0\" t=\"0\" r=\"0\" b=\"0\"/>"
          ."<wp:docPr id=\"1\" name=\"Logo\"/>"
          ."<wp:cNvGraphicFramePr>"
          ."<a:graphicFrameLocks xmlns:a=\"http://schemas.openxmlformats.org/drawingml/2006/main\" noChangeAspect=\"1\"/>"
          ."</wp:cNvGraphicFramePr>"
          ."<a:graphic xmlns:a=\"http://schemas.openxmlformats.org/drawingml/2006/main\">"
          ."<a:graphicData uri=\"http://schemas.openxmlformats.org/drawingml/2006/picture\">"
          ."<pic:pic xmlns:pic=\"http://schemas.openxmlformats.org/drawingml/2006/picture\">"
          ."<pic:nvPicPr><pic:cNvPr id=\"1\" name=\"Logo\"/>"
          ."<pic:cNvPicPr><a:picLocks noChangeAspect=\"1\"/></pic:cNvPicPr></pic:nvPicPr>"
          ."<pic:blipFill><a:blip r:embed=\"{$rId}\"/>"
          ."<a:stretch><a:fillRect/></a:stretch></pic:blipFill>"
          ."<pic:spPr><a:xfrm><a:off x=\"0\" y=\"0\"/><a:ext cx=\"{$emu}\" cy=\"{$emu}\"/></a:xfrm>"
          ."<a:prstGeom prst=\"rect\"><a:avLst/></a:prstGeom></pic:spPr>"
          ."</pic:pic></a:graphicData></a:graphic>"
          ."</wp:inline></w:drawing></w:r>";
}

/* ═══════════════════════════════════════════════════════════
   DIMENSIONES
   Carta: 12240×15840 twips. Márgenes 720 (0.5in) → contenido 10800
   ═══════════════════════════════════════════════════════════ */
$CW      = 11106;
$LOGO_EMU = 635000; // 50pt

/* ═══════════════════════════════════════════════════════════
   CONSTRUCCIÓN
   ═══════════════════════════════════════════════════════════ */
$body = '';

/* ── ENCABEZADO ─────────────────────────────────────────── */
if ($moneda !== 'BS') {
    $colL = 6600; $colR = $CW - $colL;

    // Izquierda: logo + empresa
    $left = '';
    if ($logoData) {
        $left .= "<w:p><w:pPr><w:spacing w:before=\"0\" w:after=\"30\"/></w:pPr>"
               . img('rId3', $LOGO_EMU)
               . "</w:p>";
    }
    $left .= p([['text'=>$empresa['nombre'],'b'=>true,'sz'=>28,'col'=>'000000']],'left',0,10);
    if ($empresa['rif'])       $left .= p([['text'=>'RIF: '.$empresa['rif'],'sz'=>16,'col'=>'000000']],'left',0,6);
    if ($empresa['direccion']) $left .= p([['text'=>$empresa['direccion'],'sz'=>16,'col'=>'000000']],'left',0,6);
    if ($empresa['telefono'])  $left .= p([['text'=>'Tel: '.$empresa['telefono'],'sz'=>16,'col'=>'000000']],'left',0,0);

    // Derecha: tipo + número + fecha
    $right  = p([['text'=>$tipoLabel,'sz'=>14,'col'=>'000000','b'=>true]],'right',0,10);
    $right .= p([['text'=>$venta['numero'],'b'=>true,'sz'=>40,'col'=>'000000']],'right',0,10);
    $right .= p([['text'=>'Fecha: '],['text'=>date('d/m/Y',strtotime($venta['fecha'])),'b'=>true,'sz'=>18,'col'=>'000000']],'right',10,6);
    if (!empty($venta['fecha_vencimiento']) && $tipoDoc!=='cotizacion')
        $right .= p([['text'=>'Vence: '],['text'=>date('d/m/Y',strtotime($venta['fecha_vencimiento'])),'b'=>true,'sz'=>18,'col'=>'000000']],'right',0,0);

    $bBot = ['bottom'=>['v'=>'single','sz'=>18,'c'=>'000000']];
    $body .= tbl(tr(tc($left,$colL,'',0,'bottom',$bBot).tc($right,$colR,'',0,'bottom',$bBot)), $CW);

} else {
    // Espacio en blanco para respetar el encabezado de la hoja física (encabezado Tauro ~160px)
    // Fecha para casillas físicas: dd mm aa  → "22 10 26" (fecha de IMPRESIÓN)
    $fechaDoc = date('d    m    y');
    // SOLO ESTA LÍNEA: sz=38 (19pt), before=30, indRight=200, color NEGRO PURO
    $body .= p([['text'=>$fechaDoc,'sz'=>38,'b'=>true,'col'=>'000000']],'right',30,0,200);
    // Espacio restante hasta completar la altura del encabezado físico (~140px adicionales)
    for ($i=0;$i<5;$i++) $body .= pEmpty(360);
}

$body .= pEmpty(50);

/* ── INFO CLIENTE + DATOS DOC ───────────────────────────── */
$bInfo = [
    'top'   =>['v'=>'single','sz'=>4,'c'=>'E2E8F0'],
    'left'  =>['v'=>'single','sz'=>4,'c'=>'E2E8F0'],
    'bottom'=>['v'=>'single','sz'=>4,'c'=>'E2E8F0'],
    'right' =>['v'=>'single','sz'=>4,'c'=>'E2E8F0'],
];
$cliW = 5200; $gap = 200; $docW = $CW-$cliW-$gap;
$PAD  = 80;

// Cliente
$cli  = p([['text'=>'DATOS DEL CLIENTE','sz'=>14,'col'=>'000000','b'=>true]],'left',0,40);
$cli .= hRule('000000',4,0,30);
$cliRows = [['Nombre / Razón Social',$venta['c_nombre']??'']];
if (!empty($venta['c_rif'])) $cliRows[]=[ 'RIF / CI',   $venta['c_rif']];
if (!empty($venta['c_tel'])) $cliRows[]=['Teléfono',    $venta['c_tel']];
if (!empty($venta['c_dir'])) $cliRows[]=['Dirección',   $venta['c_dir']];
foreach ($cliRows as $cr) {
    $iw = $cliW - $PAD*2;
    $cli .= tbl(tr(
        tc(p([['text'=>$cr[0],'sz'=>17,'col'=>'000000']],'left',0,0), (int)($iw*0.42),'',0).
        tc(p([['text'=>$cr[1],'sz'=>17,'b'=>true,'col'=>'000000']],'right',0,0), (int)($iw*0.58),'',0)
    ), $iw);
}

// Documento
$doc  = p([['text'=>'DATOS DEL DOCUMENTO','sz'=>14,'col'=>'000000','b'=>true]],'left',0,40);
$doc .= hRule('000000',4,0,30);
$docRows = [
    ['Vendedor',        $venta['v_nombre']??'N/A'],
    ['Tipo de cliente', ucfirst($venta['tipo_cliente']??'')],
    ['Tipo de pago',    ucfirst($venta['tipo_pago']??'')],
];
foreach ($docRows as $dr) {
    $iw = $docW - $PAD*2;
    $doc .= tbl(tr(
        tc(p([['text'=>$dr[0],'sz'=>17,'col'=>'000000']],'left',0,0), (int)($iw*0.45),'',0).
        tc(p([['text'=>$dr[1],'sz'=>17,'b'=>true,'col'=>'000000']],'right',0,0), (int)($iw*0.55),'',0)
    ), $iw);
}

$body .= tbl(tr(
    tc($cli,$cliW,'F8FAFC',$PAD,'top',$bInfo).
    tc(pEmpty(60),$gap,'',0).
    tc($doc,$docW,'F8FAFC',$PAD,'top',$bInfo)
), $CW);

$body .= pEmpty(50);

/* ── TABLA DE PRODUCTOS ─────────────────────────────────── */
// Anchos columnas: # cod desc precio cant monto = 11106 (margenes 1 cm)
$cols = [360, 1950, 4500, 1300, 700, 2296];
$RPAD = 40; // padding fila producto (muy compacto)

// Cabecera: fondo color de marca, letra forzada a negro por p()
$hBrd = ['bottom'=>['v'=>'single','sz'=>4,'c'=>'FFFFFF']];
$hdrRow = tr(
    tc(p([['text'=>'#',         'sz'=>16,'col'=>'FFFFFF','b'=>true]],'center',0,0),$cols[0],$COLOR_BG,$RPAD,'top',$hBrd).
    tc(p([['text'=>'CÓDIGO',    'sz'=>16,'col'=>'FFFFFF','b'=>true]],'left',0,0),  $cols[1],$COLOR_BG,$RPAD,'top',$hBrd).
    tc(p([['text'=>'DESCRIPCIÓN','sz'=>16,'col'=>'FFFFFF','b'=>true]],'left',0,0), $cols[2],$COLOR_BG,$RPAD,'top',$hBrd).
    tc(p([['text'=>'PRECIO '.$simbolo,'sz'=>16,'col'=>'FFFFFF','b'=>true]],'right',0,0),$cols[3],$COLOR_BG,$RPAD,'top',$hBrd).
    tc(p([['text'=>'CANT.','sz'=>16,'col'=>'FFFFFF','b'=>true]],'right',0,0),      $cols[4],$COLOR_BG,$RPAD,'top',$hBrd).
    tc(p([['text'=>'MONTO '.$simbolo,'sz'=>16,'col'=>'FFFFFF','b'=>true]],'right',0,0),$cols[5],$COLOR_BG,$RPAD,'top',$hBrd),
    true  // isHeader → se repite en cada página nueva automáticamente
);

$rBrd = ['bottom'=>['v'=>'single','sz'=>4,'c'=>'E2E8F0']];
$prodRows = $hdrRow;
$totalCant = 0;

foreach ($detalles as $k => $d) {
    $precio = ($moneda==='BS') ? $d['precio_usd']*$tasaVal : $d['precio_usd'];
    $monto  = ($moneda==='BS') ? $d['monto_usd']*$tasaVal  : $d['monto_usd'];
    $totalCant += (float)$d['cantidad'];
    $bg   = ($k%2===0) ? 'FFFFFF' : 'F9FAFC';
    $pFmt = $moneda==='BS' ? number_format($precio,2,',','.') : '$ '.number_format($precio,2,'.',',');
    $mFmt = $moneda==='BS' ? number_format($monto,2,',','.')  : '$ '.number_format($monto,2,'.',',');

    $prodRows .= tr(
        tc(p([['text'=>(string)($k+1),'sz'=>17,'col'=>'000000']],'center',0,0),$cols[0],$bg,$RPAD,'top',$rBrd).
        tc(p([['text'=>$d['ref']??$d['codigo']??'','sz'=>16,'b'=>true,'col'=>'000000']],'left',0,0),$cols[1],$bg,$RPAD,'top',$rBrd).
        tc(p([['text'=>$d['descripcion'],'sz'=>17,'col'=>'000000']],'left',0,0),$cols[2],$bg,$RPAD,'top',$rBrd).
        tc(p([['text'=>$pFmt,'sz'=>17,'col'=>'000000']],'right',0,0),$cols[3],$bg,$RPAD,'top',$rBrd).
        tc(p([['text'=>number_format($d['cantidad'],2),'sz'=>17,'b'=>true,'col'=>'000000']],'right',0,0),$cols[4],$bg,$RPAD,'top',$rBrd).
        tc(p([['text'=>$mFmt,'sz'=>17,'b'=>true,'col'=>'000000']],'right',0,0),$cols[5],$bg,$RPAD,'top',$rBrd)
    );
}

// Subtotal
$sBrd = ['top'=>['v'=>'single','sz'=>12,'c'=>'000000']];
$sw1  = $cols[0]+$cols[1]+$cols[2]+$cols[3];
$prodRows .= tr(
    tc(p([['text'=>'SUBTOTAL:','sz'=>18,'b'=>true,'col'=>'000000']],'right',0,0),$sw1,'F0F2F5',60,'top',$sBrd).
    tc(p([['text'=>number_format($totalCant,2),'sz'=>18,'b'=>true,'col'=>'000000']],'right',0,0),$cols[4],'F0F2F5',60,'top',$sBrd).
    tc(p([['text'=>$fmt($totalUSD),'sz'=>18,'b'=>true,'col'=>'000000']],'right',0,0),$cols[5],'F0F2F5',60,'top',$sBrd)
);

if ($fletePct > 0) {
    $prodRows .= tr(
        tc(p([['text'=>'FLETE ('.number_format($fletePct,2).'%):','sz'=>17,'b'=>true,'col'=>'000000']],'right',0,0),($CW-$cols[5]),'F0F2F5',60).
        tc(p([['text'=>$fmt($fleteUSD),'sz'=>17,'b'=>true,'col'=>'000000']],'right',0,0),$cols[5],'F0F2F5',60)
    );
}

$body .= tbl($prodRows, $CW);
$body .= pEmpty(60);

/* ── TOTALES ────────────────────────────────────────────── */
// IVA 16% solo aplica en BS (obligatorio en facturación venezolana)
$ivaRate   = 0.16;
$baseBS    = $totalUSD * $tasaVal;          // base imponible en Bs.
$ivaBS     = $baseBS * $ivaRate;             // IVA 16% en Bs.
$totalConIvaBS = $baseBS + $ivaBS;           // total final en Bs.
$pagBS     = $pagUSD  * $tasaVal;
$saldoConIvaBS = $totalConIvaBS - $pagBS;

$fmtBS = fn(float $v) => number_format($v,2,',','.');

$tBrd   = ['bottom'=>['v'=>'single','sz'=>4,'c'=>'E2E8F0']];
$tW1    = 2600; $tW2 = 2200; $tEmpty = $CW - $tW1 - $tW2;

// ── Fila helper (CLOSURE REGULAR, no arrow fn) ────────────
$totFila = function(string $label, string $valor, bool $muted=false, bool $grande=false)
           use ($tEmpty,$tW1,$tW2,$tBrd): string {
    $sz    = $grande ? 24 : 19;
    $runL  = [['text'=>$label,'sz'=>$sz,'col'=>'000000']];
    $runV  = [['text'=>$valor,'sz'=>$sz,'b'=>true,'col'=>'000000']];
    $brd   = $grande ? [] : $tBrd;
    return tr(
        tc(pEmpty(20),$tEmpty,'',0).
        tc(p($runL,'left',0,0),$tW1,'',60,'top',$brd).
        tc(p($runV,'right',0,0),$tW2,'',60,'top',$brd)
    );
};

if ($moneda === 'BS') {
    // Versión BS: Subtotal + IVA 16% + Total + Pagado + Saldo
    $totRows  = $totFila('Subtotal:',         $fmtBS($baseBS),         true);
    $totRows .= $totFila('IVA (16%):',        $fmtBS($ivaBS),          true);
    // línea separadora antes del total
    $totRows .= tr(
        tc(pEmpty(20),$tEmpty,'',0).
        tc(hRule('000000',8,0,0),($tW1+$tW2),'',0)
    );
    $totRows .= $totFila('TOTAL:',            $fmtBS($totalConIvaBS),  false, true);
    if ($tipoDoc !== 'cotizacion') {
        $totRows .= $totFila('Pagado:',        $fmtBS($pagBS),          true);
        $totRows .= tr(
            tc(pEmpty(20),$tEmpty,'',0).
            tc(p([['text'=>'SALDO:','sz'=>24,'b'=>true,'col'=>'000000']],'left',0,0),$tW1,'',60).
            tc(p([['text'=>$fmtBS($saldoConIvaBS),'sz'=>24,'b'=>true,'col'=>'000000']],'right',0,0),$tW2,'',60)
        );
    }
} else {
    // Versión USD: sin IVA
    $totRows  = $totFila('Total USD:',         $fmt($totalUSD),         true);
    if ($tipoDoc !== 'cotizacion') {
        $totRows .= $totFila('Pagado:',         $fmt($pagUSD),           true);
        $totRows .= $totFila('SALDO:',          $fmt($saldoUSD),         false, true);
    } else {
        $totRows .= $totFila('TOTAL:',          $fmt($totalUSD),         false, true);
    }
}

$body .= tbl($totRows, $CW);

/* ── NOTAS ──────────────────────────────────────────────── */
if (!empty($venta['notas'])) {
    $body .= pEmpty(40);
    $nBrd = ['top'=>['v'=>'single','sz'=>4,'c'=>'FDE68A'],'left'=>['v'=>'single','sz'=>4,'c'=>'FDE68A'],
             'bottom'=>['v'=>'single','sz'=>4,'c'=>'FDE68A'],'right'=>['v'=>'single','sz'=>4,'c'=>'FDE68A']];
    $body .= tbl(tr(tc(p([['text'=>'Observaciones: ','b'=>true,'sz'=>17,'col'=>'000000'],['text'=>$venta['notas'],'sz'=>17,'col'=>'000000']]),$CW,'FFFBEB',60,'top',$nBrd)),$CW);
}

/* ── AVISO LEGAL ────────────────────────────────────────── */
if (in_array($tipoDoc,['cotizacion','despacho'])) {
    $body .= pEmpty(50);
    $lBrd = ['top'=>['v'=>'dashed','sz'=>6,'c'=>'E2E8F0'],'bottom'=>['v'=>'dashed','sz'=>6,'c'=>'E2E8F0']];
    $body .= tbl(tr(tc(
        p([['text'=>'Este despacho tiene validez de 15 días desde la fecha de emisión. Los precios están sujetos a la tasa de cambio vigente al momento de la venta.','sz'=>16,'col'=>'000000']])
        ,$CW,'',60,'top',$lBrd)),$CW);
}

/* ── PIE ────────────────────────────────────────────────── */
if ($moneda !== 'BS') {
    $body .= pEmpty(60);
    $body .= hRule('000000',4,0,0);
    $pieL  = p([['text'=>$empresa['nombre'],'sz'=>17,'col'=>'000000']],'left',20,0);
    $pieL .= p([['text'=>'Emitido: '.date('d/m/Y H:i'),'sz'=>17,'col'=>'000000']],'left',0,0);

    if ($tipoDoc !== 'cotizacion') {
        $fW   = 2400;
        $firC = pEmpty(360);
        $firC .= p([['text'=>'Firma del Receptor','sz'=>17,'col'=>'000000']],'center',0,0);
        $fBrd = ['top'=>['v'=>'single','sz'=>6,'c'=>'000000']];
        $body .= tbl(tr(
            tc($pieL,($CW-$fW),'',0,'bottom').
            tc($firC,$fW,'',60,'bottom',$fBrd)
        ),$CW);
    } else {
        $body .= $pieL;
    }
}

/* ═══════════════════════════════════════════════════════════
   XML OOXML
   ═══════════════════════════════════════════════════════════ */
$hasLogo = ($logoData !== null);
$logoRel = $hasLogo
    ? '<Relationship Id="rId3" '
     .'Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/image" '
     .'Target="media/logo.'.$logoExt.'"/>'
    : '';

$CT = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">
  <Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>
  <Default Extension="xml"  ContentType="application/xml"/>
  <Default Extension="png"  ContentType="image/png"/>
  <Default Extension="jpg"  ContentType="image/jpeg"/>
  <Default Extension="jpeg" ContentType="image/jpeg"/>
  <Default Extension="gif"  ContentType="image/gif"/>
  <Default Extension="webp" ContentType="image/webp"/>
  <Override PartName="/word/document.xml"
    ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/>
  <Override PartName="/word/styles.xml"
    ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.styles+xml"/>
  <Override PartName="/word/settings.xml"
    ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.settings+xml"/>
</Types>';

$RELS = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
  <Relationship Id="rId1"
    Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument"
    Target="word/document.xml"/>
</Relationships>';

$DRELS = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
  <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>
  <Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/settings" Target="settings.xml"/>
  '.$logoRel.'
</Relationships>';

$STYLES = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<w:styles xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">
  <w:docDefaults><w:rPrDefault><w:rPr>
    <w:rFonts w:ascii="Calibri" w:hAnsi="Calibri" w:cs="Calibri"/>
    <w:b/><w:bCs/>
    <w:sz w:val="20"/><w:szCs w:val="20"/><w:color w:val="000000"/>
  </w:rPr></w:rPrDefault></w:docDefaults>
</w:styles>';

$SETTINGS = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<w:settings xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">
  <w:defaultTabStop w:val="720"/>
  <w:compat><w:compatSetting w:name="compatibilityMode"
    w:uri="http://schemas.microsoft.com/office/word" w:val="15"/></w:compat>
</w:settings>';

$DOC = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<w:document
  xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"
  xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"
  xmlns:wp="http://schemas.openxmlformats.org/drawingml/2006/wordprocessingDrawing"
  xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main"
  xmlns:pic="http://schemas.openxmlformats.org/drawingml/2006/picture">
<w:body>'
.$body.
'<w:sectPr>
  <w:pgSz w:w="12240" w:h="15840" w:orient="portrait"/>
  <w:pgMar w:top="567" w:right="567" w:bottom="567" w:left="567"
           w:header="0" w:footer="0" w:gutter="0"/>
</w:sectPr>
</w:body></w:document>';

/* ═══════════════════════════════════════════════════════════
   EMPAQUETAR Y ENVIAR
   ═══════════════════════════════════════════════════════════ */
$tmp = tempnam(sys_get_temp_dir(),'tauro_').'.docx';
$zip = new ZipArchive();
if ($zip->open($tmp, ZipArchive::CREATE|ZipArchive::OVERWRITE) !== true) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    die('ERROR: No se pudo crear el archivo temporal en '.sys_get_temp_dir());
}
$zip->addFromString('[Content_Types].xml',          $CT);
$zip->addFromString('_rels/.rels',                  $RELS);
$zip->addFromString('word/document.xml',            $DOC);
$zip->addFromString('word/_rels/document.xml.rels', $DRELS);
$zip->addFromString('word/styles.xml',              $STYLES);
$zip->addFromString('word/settings.xml',            $SETTINGS);
if ($hasLogo) $zip->addFromString('word/media/logo.'.$logoExt, $logoData);
$zip->close();

$nombre = preg_replace('/[^a-z0-9_\-\.]/i','_',
    strtolower($tipoLabel).'_'.$venta['numero'].'_'.$moneda.'.docx');
header('Content-Type: application/vnd.openxmlformats-officedocument.wordprocessingml.document');
header('Content-Disposition: attachment; filename="'.$nombre.'"');
header('Content-Length: '.filesize($tmp));
header('Cache-Control: no-cache, no-store, must-revalidate');
readfile($tmp);
unlink($tmp);
exit;