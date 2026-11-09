<?php
require_once 'config/config.php';
require_once 'functions/db.php';
require_once 'functions/helpers.php';
require_once 'functions/auth.php';

requireLogin();
$pageTitle = 'Transporte — Orden de Carga';

// ── GUARDAR CAMBIOS (flete, bulto, zona custom, orden) ─────
if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action']??'')==='guardar') {
    verifyCsrf();
    $ids     = $_POST['vid']        ?? [];
    $fletes  = $_POST['flete_pct']  ?? [];
    $bultos  = $_POST['bulto_carg'] ?? [];
    $zonas   = $_POST['zona_carg']  ?? [];
    $ordenes = $_POST['orden']      ?? [];
    $sinT    = $_POST['sin_transp'] ?? [];

    try {
        foreach ($ids as $k => $vid) {
            $vid = cleanInt($vid);
            if (!$vid) continue;
            $fp   = cleanFloat($fletes[$k]  ?? 0);
            $bc   = cleanInt($bultos[$k]    ?? 0);
            $zc   = clean($zonas[$k]        ?? '');
            $ord  = cleanInt($ordenes[$k]   ?? 0) ?: null;
            $sinTr= in_array((string)$vid, $sinT) ? 1 : 0;
            $vt   = dbQueryOne("SELECT total_usd FROM ventas WHERE id=?",[$vid]);
            $fleteUSD = $sinTr ? 0 : round(($vt['total_usd']??0)*$fp/100,4);
            dbExecute(
                "UPDATE ventas SET flete_pct=?,flete_monto_usd=?,bulto_carga=?,zona_carga=?,orden_carga=?,sin_transporte=? WHERE id=?",
                [$sinTr?0:$fp,$fleteUSD,$bc,$zc,$ord,$sinTr,$vid]
            );
        }
        auditLog('UPDATE','transporte',0,'Orden de carga guardada');
        $_SESSION['flash']=['type'=>'success','msg'=>'Cambios guardados correctamente.'];
    } catch (PDOException $e) {
        error_log('transporte guardar: ' . $e->getMessage());
        $_SESSION['flash']=['type'=>'error','msg'=>'Error al guardar: '.$e->getMessage()];
    }
    $qs = http_build_query(array_diff_key($_GET,['action'=>'']));
    header("Location: transporte.php?$qs"); exit;
}

// ── FILTROS ────────────────────────────────────────────────
$secuenciaId = cleanInt($_GET['secuencia'] ?? 0);
$vendedorId  = cleanInt($_GET['vendedor']  ?? 0);
$comMayor    = (float)getConfig('comision_pct_mayorista','7.00');
$comDetal    = (float)getConfig('comision_pct_detalista','10.00');
$fleteDef    = (float)getConfig('flete_pct_default','5.00');

$vendedores = dbQuery("SELECT id,nombre FROM vendedores WHERE activo=1 AND departamento_id=1 ORDER BY nombre");
$secuencias = dbQuery("SELECT * FROM secuencias ORDER BY fecha_ini DESC");
$secData    = $secuenciaId ? dbQueryOne("SELECT * FROM secuencias WHERE id=?",[$secuenciaId]) : null;
$vData      = $vendedorId  ? dbQueryOne("SELECT nombre FROM vendedores WHERE id=?",[$vendedorId]) : null;

// ── DOCUMENTOS ────────────────────────────────────────────
$docs = [];
if ($secuenciaId && $vendedorId) {
    try {
        $docs = dbQuery(
            "SELECT v.id,v.numero,v.fecha,v.tipo_cliente,v.total_usd,
                    v.flete_pct,v.flete_monto_usd,v.orden_carga,v.sin_transporte,
                    COALESCE(v.bulto_carga,0) bulto_carga,
                    COALESCE(v.zona_carga,'')  zona_carga,
                    c.nombre c_nombre,c.rif c_rif,
                    z.nombre zona_default
             FROM ventas v
             LEFT JOIN clientes c ON v.cliente_id=c.id
             LEFT JOIN zonas z    ON c.zona_id=z.id
             WHERE v.vendedor_id=? AND v.secuencia_id=?
               AND v.tipo_doc='venta' AND v.estado!='anulado'
             ORDER BY ISNULL(v.orden_carga),v.orden_carga,c.nombre",
            [$vendedorId,$secuenciaId]
        );
        foreach ($docs as &$d) {
            if (!$d['flete_pct'] && !$d['sin_transporte']) $d['flete_pct'] = $fleteDef;
            $d['flete_monto_usd'] = $d['sin_transporte'] ? 0 : round($d['total_usd']*$d['flete_pct']/100,2);
            $d['comision_pct']    = $d['tipo_cliente']==='mayorista' ? $comMayor : $comDetal;
            $d['comision_monto']  = round($d['total_usd']*$d['comision_pct']/100,2);
            $d['zona_mostrar']    = $d['zona_carga'] ?: ($d['zona_default'] ?: '—');
        }
        unset($d);
    } catch (PDOException $e) {
        error_log('transporte.php SELECT: ' . $e->getMessage());
        $_SESSION['flash'] = [
            'type'=>'error',
            'msg'=>'Falta una columna en la tabla ventas. Ejecuta: ALTER TABLE ventas ADD COLUMN bulto_carga INT NOT NULL DEFAULT 0, ADD COLUMN zona_carga VARCHAR(150) NULL;'
        ];
        $docs = [];
    }
}

$conTransp = array_values(array_filter($docs,fn($d)=>!$d['sin_transporte']));
$sinTransp = array_values(array_filter($docs,fn($d)=> $d['sin_transporte']));
$totalMayor= array_sum(array_column(array_filter($docs,fn($d)=>$d['tipo_cliente']==='mayorista'),'total_usd'));
$totalDetal= array_sum(array_column(array_filter($docs,fn($d)=>$d['tipo_cliente']==='detalista'),'total_usd'));
$comTotMay = round($totalMayor*$comMayor/100,2);
$comTotDet = round($totalDetal*$comDetal/100,2);
$fleteTot  = array_sum(array_column($conTransp,'flete_monto_usd'));
$totalGen  = $totalMayor+$totalDetal;
$comTotGen = $comTotMay+$comTotDet;

$empresa = getConfig('empresa_nombre',APP_NAME);

include 'includes/header.php';
?>
<style>
.trp-card{background:white;border:1px solid var(--border);border-radius:var(--radius);overflow:hidden;margin-bottom:18px}
.trp-hdr{padding:13px 18px;background:#1a1a2e;color:white;display:flex;align-items:center;gap:10px}
.trp-hdr h5{margin:0;font-size:14px;font-weight:700;flex:1}
.res-box{text-align:center;padding:14px;border-radius:var(--radius);border:1px solid var(--border);background:white}
.res-val{font-size:19px;font-weight:800;line-height:1.1}
.res-lab{font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:var(--text-muted);margin-top:3px}
.res-sub{font-size:11px;color:var(--text-muted);margin-top:2px}
.trp-inp{padding:5px 7px;border:1.5px solid var(--border);border-radius:5px;font-size:12px}
.trp-inp:focus{border-color:var(--primary);outline:none}
.trp-row td{padding:9px 10px;vertical-align:middle;border-bottom:1px solid var(--border)}
.sinT-row{opacity:.45;background:repeating-linear-gradient(135deg,#f8f8f8,#f8f8f8 4px,white 4px,white 10px)!important}
.orden-badge{display:inline-flex;align-items:center;justify-content:center;width:26px;height:26px;border-radius:50%;background:var(--primary);color:white;font-weight:800;font-size:12px}
@media print{
    .no-print{display:none!important}
    .trp-card{box-shadow:none;break-inside:avoid}
    body{background:white}
}
</style>

<!-- FILTROS -->
<div class="card mb-4 no-print">
<div class="card-header">
    <i class="fa-solid fa-sliders text-primary"></i>
    <h5>Filtros — Transporte</h5>
</div>
<div class="card-body">
<form method="GET" class="row">
    <div class="col-4">
        <div class="form-group">
            <label class="form-label">Secuencia *</label>
            <select name="secuencia" id="selSecuencia" class="form-select select2" required>
                <option value="">— Seleccionar secuencia —</option>
                <?php foreach($secuencias as $s): ?>
                <option value="<?=$s['id']?>" <?=$secuenciaId==$s['id']?'selected':''?>>
                    <?=clean($s['nombre'])?> <?=$s['estado']==='activa'?'(activa)':''?>
                </option>
                <?php endforeach; ?>
            </select>
        </div>
    </div>
    <div class="col-4">
        <div class="form-group">
            <label class="form-label">Vendedor *</label>
            <select name="vendedor" id="selVendedor" class="form-select select2" required>
                <option value="">— Seleccionar vendedor —</option>
                <?php foreach($vendedores as $v): ?>
                <option value="<?=$v['id']?>" <?=$vendedorId==$v['id']?'selected':''?>><?=clean($v['nombre'])?></option>
                <?php endforeach; ?>
            </select>
        </div>
    </div>
    <div class="col-4" style="padding-top:22px">
        <button type="submit" class="btn btn-primary">
            <i class="fa-solid fa-magnifying-glass"></i> Ver carga
        </button>
        <?php if($secuenciaId && $vendedorId): ?>
        <button type="button" onclick="window.print()" class="btn btn-light">
            <i class="fa-solid fa-print"></i> Imprimir
        </button>
        <button type="button" onclick="exportarExcelTransporte()" class="btn btn-light">
            <i class="fa-solid fa-table-cells"></i> Excel
        </button>
        <?php endif; ?>
    </div>
</form>
</div>
</div>

<?php if($secuenciaId && $vendedorId && !empty($docs)): ?>

<!-- HEADER IMPRESIÓN -->
<div style="display:none" class="print-header">
    <div style="border-bottom:3px solid #1a1a2e;padding-bottom:12px;margin-bottom:16px;display:flex;justify-content:space-between">
        <div>
            <div style="font-size:18px;font-weight:800"><?=clean($empresa)?></div>
            <div style="font-size:13px;color:#718096">Orden de Carga — Transporte</div>
        </div>
        <div style="text-align:right">
            <div style="font-weight:700"><?=clean($vData['nombre']??'')?></div>
            <div style="font-size:12px;color:#718096">Secuencia: <?=clean($secData['nombre']??'')?></div>
            <div style="font-size:11px;color:#718096">Impreso: <?=date('d/m/Y H:i')?></div>
        </div>
    </div>
</div>
<style>@media print{.print-header{display:block!important}}</style>

<!-- RESUMEN -->
<div class="row mb-4">
    <div class="col-2"><div class="res-box">
        <div class="res-val"><?=count($conTransp)?></div>
        <div class="res-lab">Con transporte</div>
        <div class="res-sub"><?=count($sinTransp)?> sin transp.</div>
    </div></div>
    <div class="col-2"><div class="res-box" style="border-color:var(--info)">
        <div class="res-val" style="color:var(--info)"><?=formatUSD($totalMayor)?></div>
        <div class="res-lab">Mayorista</div>
        <div class="res-sub">Com. <?=formatUSD($comTotMay)?></div>
    </div></div>
    <div class="col-2"><div class="res-box" style="border-color:var(--warning)">
        <div class="res-val" style="color:var(--warning)"><?=formatUSD($totalDetal)?></div>
        <div class="res-lab">Detalista</div>
        <div class="res-sub">Com. <?=formatUSD($comTotDet)?></div>
    </div></div>
    <div class="col-2"><div class="res-box" style="border-color:var(--success)">
        <div class="res-val" style="color:var(--success)"><?=formatUSD($totalGen)?></div>
        <div class="res-lab">Total General</div>
        <div class="res-sub">Com. tot. <?=formatUSD($comTotGen)?></div>
    </div></div>
    <div class="col-2"><div class="res-box" style="border-color:var(--primary)">
        <div class="res-val" style="color:var(--primary)"><?=formatUSD($fleteTot)?></div>
        <div class="res-lab">Flete Total</div>
        <div class="res-sub">Independiente</div>
    </div></div>
    <div class="col-2 no-print"><div class="res-box" style="background:#1a1a2e;color:white;border-color:#1a1a2e">
        <div class="res-val" style="font-size:15px"><?=clean($secData['nombre']??'')?></div>
        <div class="res-lab" style="color:rgba(255,255,255,.6)">Secuencia</div>
        <div style="font-size:11px;color:rgba(255,255,255,.5);margin-top:2px"><?=clean($vData['nombre']??'')?></div>
    </div></div>
</div>

<form method="POST" id="formTransporte">
<input type="hidden" name="<?=CSRF_TOKEN_NAME?>" value="<?=getCsrfToken()?>">
<input type="hidden" name="action" value="guardar">

<!-- TABLA PRINCIPAL -->
<div class="trp-card">
    <div class="trp-hdr">
        <i class="fa-solid fa-truck-fast"></i>
        <h5>Orden de Carga del Camión — <?=clean($vData['nombre']??'')?></h5>
        <div class="d-flex gap-2 no-print">
            <button type="submit" class="btn btn-sm btn-warning">
                <i class="fa-solid fa-floppy-disk"></i> Guardar cambios
            </button>
        </div>
    </div>
    <div style="padding:10px 14px;background:#f8fafc;border-bottom:1px solid var(--border);font-size:12px;color:var(--text-muted)">
        <i class="fa-solid fa-circle-info"></i>
        <strong>Zona:</strong> edita sobre el campo para poner la ubicación exacta del cliente.
        <strong style="margin-left:12px">Bulto:</strong> cantidad de bultos que lleva ese cliente.
        <strong style="margin-left:12px">Flete:</strong> % del monto de la factura (no se suma al sistema, es solo para este reporte).
        <strong style="margin-left:12px">Orden:</strong> secuencia de descarga en el camión.
    </div>
    <div class="table-wrapper">
    <table id="tablaTransporte" style="width:100%;border-collapse:collapse;font-size:12.5px">
        <thead>
            <tr style="background:#1a1a2e;color:white">
                <th style="padding:9px 10px;font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;width:32px">Ord.</th>
                <th style="padding:9px 10px;font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.5px">N° Factura</th>
                <th style="padding:9px 10px;font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.5px">Cliente</th>
                <th style="padding:9px 10px;font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.5px">Zona / Ubicación</th>
                <th style="padding:9px 10px;font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;text-align:right">Monto $</th>
                <th style="padding:9px 10px;font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;text-align:center">Bultos</th>
                <th style="padding:9px 10px;font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;text-align:center">Flete %</th>
                <th style="padding:9px 10px;font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;text-align:right">Flete $</th>
                <th style="padding:9px 10px;font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;text-align:center">Com. %</th>
                <th style="padding:9px 10px;font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;text-align:right">Com. $</th>
                <th style="padding:9px 10px;font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;text-align:center;width:70px" class="no-print">Sin transp.</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach($docs as $k => $d):
            $isSin = $d['sin_transporte'];
            $bgRow = $isSin ? '' : ($d['tipo_cliente']==='mayorista' ? 'rgba(41,128,185,.04)' : 'rgba(230,126,34,.04)');
        ?>
        <tr class="trp-row <?=$isSin?'sinT-row':''?>" style="background:<?=$bgRow?>" data-total="<?=$d['total_usd']?>">
            <input type="hidden" name="vid[]" value="<?=$d['id']?>">
            <td style="padding:9px 10px">
                <?php if($d['orden_carga']): ?>
                <span class="orden-badge"><?=$d['orden_carga']?></span>
                <?php endif; ?>
                <input type="number" name="orden[]" class="trp-inp no-print" value="<?=$d['orden_carga']??''?>"
                    placeholder="#" min="1" style="width:44px;margin-top:<?=$d['orden_carga']?'4':'0'?>px">
            </td>
            <td style="padding:9px 10px">
                <a href="ver_venta.php?id=<?=$d['id']?>" class="font-mono fw-bold fs-12" style="color:var(--primary)"><?=clean($d['numero'])?></a>
                <br><small class="text-muted"><?=date('d/m',strtotime($d['fecha']))?></small>
            </td>
            <td style="padding:9px 10px;font-weight:600"><?=clean($d['c_nombre'])?>
                <?php if($d['c_rif']): ?><br><small class="text-muted font-mono fs-11"><?=clean($d['c_rif'])?></small><?php endif; ?>
            </td>
            <td style="padding:9px 10px">
                <input type="text" name="zona_carg[]" class="trp-inp" style="width:100%"
                    value="<?=clean($d['zona_carga']??$d['zona_default']??'')?>"
                    placeholder="<?=clean($d['zona_default']??'Zona / Dirección')?>">
            </td>
            <td style="padding:9px 10px;text-align:right;font-weight:800;color:var(--success)"><?=formatUSD($d['total_usd'])?></td>
            <td style="padding:9px 10px;text-align:center">
                <input type="text" name="bulto_carg[]" class="trp-inp" style="width:60px;text-align:center"
                    value="<?=clean($d['bulto_carga']??'')?>" placeholder="0">
            </td>
            <td style="padding:9px 10px;text-align:center">
                <div class="d-flex align-center gap-1 justify-center">
                    <input type="number" name="flete_pct[]" class="trp-inp" style="width:60px;text-align:center"
                        id="fp_<?=$k?>" value="<?=number_format($d['flete_pct'],2,'.','')?>"
                        min="0" max="100" step="0.01" <?=$isSin?'disabled':''?>
                        oninput="calcFlete(<?=$k?>)">
                    <span>%</span>
                </div>
            </td>
            <td style="padding:9px 10px;text-align:right;font-weight:700;color:var(--warning)" id="fmonto_<?=$k?>">
                <?=$isSin?'—':formatUSD($d['flete_monto_usd'])?>
            </td>
            <td style="padding:9px 10px;text-align:center;font-weight:600"><?=number_format($d['comision_pct'],1)?>%</td>
            <td style="padding:9px 10px;text-align:right;font-weight:700;color:var(--primary)"><?=formatUSD($d['comision_monto'])?></td>
            <td style="padding:9px 10px;text-align:center" class="no-print">
                <label style="display:flex;align-items:center;justify-content:center;gap:4px;cursor:pointer;font-size:11px;color:var(--text-muted)">
                    <input type="checkbox" name="sin_transp[]" value="<?=$d['id']?>"
                        <?=$isSin?'checked':''?> onchange="toggleSinT(this,<?=$k?>)">
                    Sin transp.
                </label>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
        <tfoot>
            <tr style="background:#f0f2f5;font-weight:700">
                <td colspan="4" style="padding:11px 10px;text-align:right;font-size:12px;color:var(--text-muted)">TOTALES:</td>
                <td style="padding:11px 10px;text-align:right;font-size:14px;color:var(--success)"><?=formatUSD($totalGen)?></td>
                <td></td>
                <td></td>
                <td style="padding:11px 10px;text-align:right;color:var(--warning)" id="flete_total_td"><?=formatUSD($fleteTot)?></td>
                <td></td>
                <td style="padding:11px 10px;text-align:right;color:var(--primary)"><?=formatUSD($comTotGen)?></td>
                <td class="no-print"></td>
            </tr>
            <tr style="background:#1a1a2e;color:white">
                <td colspan="4" style="padding:10px;font-size:12px;font-weight:700">
                    MAYOR: <?=formatUSD($totalMayor)?> | Com. Mayor: <?=formatUSD($comTotMay)?>
                </td>
                <td colspan="3" style="padding:10px;font-size:12px;font-weight:700">
                    DETAL: <?=formatUSD($totalDetal)?> | Com. Detal: <?=formatUSD($comTotDet)?>
                </td>
                <td colspan="3" style="padding:10px;font-size:12px;font-weight:700;text-align:right">
                    Com. Total: <?=formatUSD($comTotGen)?> | Flete: <?=formatUSD($fleteTot)?>
                </td>
                <td class="no-print"></td>
            </tr>
        </tfoot>
    </table>
    </div>
</div>
</form>

<?php elseif($secuenciaId && $vendedorId && empty($docs)): ?>
<div class="card"><div class="card-body" style="text-align:center;padding:48px;color:var(--text-muted)">
    <i class="fa-solid fa-truck" style="font-size:48px;opacity:.2;display:block;margin-bottom:14px"></i>
    Sin facturas para este vendedor en la secuencia seleccionada.
</div></div>
<?php else: ?>
<div class="card"><div class="card-body" style="text-align:center;padding:60px;color:var(--text-muted)">
    <i class="fa-solid fa-truck-fast" style="font-size:64px;opacity:.15;display:block;margin-bottom:16px"></i>
    <h3>Orden de Carga del Transporte</h3>
    <p style="margin-top:8px;max-width:420px;margin-left:auto;margin-right:auto">
        Selecciona una secuencia y un vendedor para ver y editar la carga del camión NPR.<br>
        El flete es independiente — no afecta los totales del sistema.
    </p>
</div></div>
<?php endif; ?>

<?php
$empresaJS    = addslashes($empresa);
$vendedorNom  = addslashes($vData['nombre'] ?? '');
$secuenciaNom = addslashes($secData['nombre'] ?? '');
$fleteDefJs   = number_format($fleteDef,2,'.','');

ob_start();
?>
<script>
function calcFlete(k) {
    const fpEl = document.getElementById('fp_' + k);
    const row  = fpEl ? fpEl.closest('tr') : null;
    const total = row ? parseFloat(row.dataset.total || 0) : 0;
    const pct = parseFloat(fpEl ? fpEl.value : 0);
    const el  = document.getElementById('fmonto_' + k);
    if (el) el.textContent = '$ ' + (total * pct / 100).toFixed(2);
    recalcFleteTotal();
}

function recalcFleteTotal() {
    let tot = 0;
    document.querySelectorAll('[id^="fmonto_"]').forEach(function(el) {
        const txt = el.textContent.replace(/[^\d.\-]/g, '');
        const v = parseFloat(txt);
        if (!isNaN(v)) tot += v;
    });
    const ft = document.getElementById('flete_total_td');
    if (ft) ft.textContent = '$ ' + tot.toFixed(2);
}

function toggleSinT(chk, k) {
    const fp  = document.getElementById('fp_' + k);
    const mon = document.getElementById('fmonto_' + k);
    const row = chk.closest('tr');
    if (chk.checked) {
        if (fp)  { fp.disabled = true; fp.value = '0'; }
        if (mon) mon.textContent = '—';
        if (row) row.classList.add('sinT-row');
    } else {
        if (fp)  { fp.disabled = false; fp.value = '<?= $fleteDefJs ?>'; }
        if (row) row.classList.remove('sinT-row');
        calcFlete(k);
    }
    recalcFleteTotal();
}

function exportarExcelTransporte() {
    if (typeof XLSX === 'undefined') {
        if (typeof toast === 'function') toast('Librería XLSX no cargada', 'error');
        return;
    }
    const wb = XLSX.utils.book_new();
    const data = [
        ['<?=$empresaJS?> — Orden de Carga NPR'],
        ['Vendedor: <?=$vendedorNom?>  |  Secuencia: <?=$secuenciaNom?>  |  Fecha: ' + new Date().toLocaleDateString()],
        [''],
        ['Ord.','N° Factura','Fecha','Cliente','RIF','Zona/Ubicación','Monto $','Bultos','Flete %','Flete $','Com. %','Com. $']
    ];
    document.querySelectorAll('#tablaTransporte tbody tr').forEach(function(tr, i) {
        const tds = tr.querySelectorAll('td');
        const ord   = tds[0]?.querySelector('input')?.value || tds[0]?.querySelector('.orden-badge')?.textContent || '';
        const num   = tds[1]?.querySelector('a')?.textContent?.trim() || '';
        const fec   = tds[1]?.querySelector('small')?.textContent?.trim() || '';
        const cli   = (tds[2]?.childNodes[0]?.textContent || '').trim();
        const rif   = tds[2]?.querySelector('small')?.textContent?.trim() || '';
        const zona  = tds[3]?.querySelector('input')?.value || '';
        const monto = tds[4]?.textContent?.trim() || '';
        const bult  = tds[5]?.querySelector('input')?.value || '';
        const fpct  = tds[6]?.querySelector('input')?.value || '';
        const fmto  = document.getElementById('fmonto_' + i)?.textContent || '';
        const cpct  = tds[8]?.textContent?.trim() || '';
        const cmto  = tds[9]?.textContent?.trim() || '';
        data.push([ord, num, fec, cli, rif, zona, monto, bult, fpct, fmto, cpct, cmto]);
    });
    data.push(['']);
    data.push(['','','','','','TOTALES','<?=formatUSD($totalGen)?>','','','<?=formatUSD($fleteTot)?>','','<?=formatUSD($comTotGen)?>']);
    data.push(['Mayor: <?=formatUSD($totalMayor)?> + Com.Mayor: <?=formatUSD($comTotMay)?>','','','','Detal: <?=formatUSD($totalDetal)?> + Com.Detal: <?=formatUSD($comTotDet)?>']);
    const ws = XLSX.utils.aoa_to_sheet(data);
    ws['!cols'] = [5,15,8,28,14,22,12,8,8,10,8,10].map(function(w){ return {wch: w}; });
    XLSX.utils.book_append_sheet(wb, ws, 'Carga');
    XLSX.writeFile(wb, 'Transporte_<?=$vendedorNom?>_' + new Date().toISOString().slice(0,10) + '.xlsx');
}
</script>
<?php
$extraScript = ob_get_clean();
include 'includes/footer.php';
?>