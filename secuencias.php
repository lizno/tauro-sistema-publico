<?php
require_once 'config/config.php';
require_once 'functions/db.php';
require_once 'functions/helpers.php';
require_once 'functions/auth.php';

requireLogin();
requirePermission('pagos','ver');
$pageTitle = 'Secuencias';

/* ── Totales de una secuencia ─────────────────────────────── */
function totalesSecuencia(int $sid): array {
    $f = dbQueryOne(
        "SELECT COUNT(*) n, COALESCE(SUM(total_usd),0) t
         FROM ventas WHERE secuencia_id=? AND tipo_doc='venta' AND estado<>'anulado'", [$sid]);
    $c = dbQueryOne(
        "SELECT COALESCE(SUM(p.monto_usd),0) t
         FROM pagos p JOIN ventas v ON v.id=p.venta_id
         WHERE v.secuencia_id=? AND p.anulado=0 AND v.estado<>'anulado'", [$sid]);
    $r = dbQueryOne(
        "SELECT COALESCE(SUM(monto_usd),0) t FROM pagos_vendedor WHERE secuencia_id=?", [$sid]);

    $facturado = (float)($f['t'] ?? 0);
    $cobrado   = (float)($c['t'] ?? 0);
    return [
        'facturas'  => (int)($f['n'] ?? 0),
        'facturado' => $facturado,
        'cobrado'   => $cobrado,
        'reportado' => (float)($r['t'] ?? 0),
        'pendiente' => $facturado - $cobrado,
    ];
}

/* ── Cerrar secuencia (guarda foto de los totales) ────────── */
if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action']??'')==='cerrar') {
    verifyCsrf();
    requirePermission('pagos','editar');
    $sid = cleanInt($_POST['sec_id'] ?? 0);
    $sec = dbQueryOne("SELECT * FROM secuencias WHERE id=?", [$sid]);
    if ($sec && $sec['estado'] !== 'cerrada') {
        $t = totalesSecuencia($sid);
        dbExecute(
            "INSERT INTO secuencias_cierre
                (secuencia_id,total_facturas,total_facturado,total_cobrado,total_reportado,total_pendiente,notas,usuario_id)
             VALUES (?,?,?,?,?,?,?,?)
             ON DUPLICATE KEY UPDATE
                fecha_cierre=NOW(), total_facturas=VALUES(total_facturas), total_facturado=VALUES(total_facturado),
                total_cobrado=VALUES(total_cobrado), total_reportado=VALUES(total_reportado),
                total_pendiente=VALUES(total_pendiente), notas=VALUES(notas), usuario_id=VALUES(usuario_id)",
            [$sid, $t['facturas'], $t['facturado'], $t['cobrado'], $t['reportado'], $t['pendiente'],
             clean($_POST['notas'] ?? ''), $_SESSION['user_id']]
        );
        dbExecute("UPDATE secuencias SET estado='cerrada', fecha_fin=COALESCE(fecha_fin,CURDATE()) WHERE id=?", [$sid]);
        auditLog('UPDATE','secuencias',$sid,"Secuencia cerrada: {$sec['nombre']} — facturado \${$t['facturado']}");
        $_SESSION['flash'] = ['type'=>'success','msg'=>"Secuencia \"{$sec['nombre']}\" cerrada. Totales guardados."];
    }
    header('Location: secuencias.php'); exit;
}

/* ── Reabrir ──────────────────────────────────────────────── */
if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action']??'')==='reabrir') {
    verifyCsrf();
    requirePermission('pagos','editar');
    $sid = cleanInt($_POST['sec_id'] ?? 0);
    $sec = dbQueryOne("SELECT * FROM secuencias WHERE id=?", [$sid]);
    if ($sec) {
        dbExecute("UPDATE secuencias SET estado='activa' WHERE id=?", [$sid]);
        dbExecute("DELETE FROM secuencias_cierre WHERE secuencia_id=?", [$sid]);
        auditLog('UPDATE','secuencias',$sid,"Secuencia reabierta: {$sec['nombre']}");
        $_SESSION['flash'] = ['type'=>'success','msg'=>"Secuencia \"{$sec['nombre']}\" reabierta."];
    }
    header('Location: secuencias.php'); exit;
}

/* ── Datos ────────────────────────────────────────────────── */
$secuencias = dbQuery("SELECT * FROM secuencias ORDER BY fecha_ini DESC, id DESC");
$filas = [];
$tot = ['facturas'=>0,'facturado'=>0.0,'cobrado'=>0.0,'reportado'=>0.0,'pendiente'=>0.0];

foreach ($secuencias as $s) {
    $cierre = dbQueryOne("SELECT * FROM secuencias_cierre WHERE secuencia_id=?", [$s['id']]);
    if ($s['estado'] === 'cerrada' && $cierre) {
        $t = [
            'facturas'  => (int)$cierre['total_facturas'],
            'facturado' => (float)$cierre['total_facturado'],
            'cobrado'   => (float)$cierre['total_cobrado'],
            'reportado' => (float)$cierre['total_reportado'],
            'pendiente' => (float)$cierre['total_pendiente'],
        ];
    } else {
        $t = totalesSecuencia((int)$s['id']);
    }
    foreach ($tot as $k => $v) { $tot[$k] = $v + $t[$k]; }
    $filas[] = ['sec'=>$s, 'tot'=>$t, 'cerrada'=>$s['estado']==='cerrada' && $cierre, 'cierre'=>$cierre];
}

include 'includes/header.php';
?>
<style>
.sec-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:14px;margin-bottom:18px}
@media(max-width:900px){.sec-grid{grid-template-columns:repeat(2,1fr)}}
.sec-card{background:white;border:1px solid var(--border);border-radius:12px;padding:16px 18px}
.sec-card .lbl{font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:var(--text-muted)}
.sec-card .val{font-size:22px;font-weight:900;margin-top:4px}
.pill{display:inline-block;padding:2px 9px;border-radius:10px;font-size:11px;font-weight:700}
.pill-on{background:#d1fae5;color:#065f46}.pill-off{background:#e5e7eb;color:#374151}
</style>

<h4 style="margin-bottom:16px"><i class="fa-solid fa-layer-group" style="color:var(--primary)"></i> Control de secuencias</h4>

<div class="sec-grid">
    <div class="sec-card"><div class="lbl">Facturado histórico</div><div class="val"><?= formatUSD($tot['facturado']) ?></div></div>
    <div class="sec-card"><div class="lbl">Cobrado histórico</div><div class="val" style="color:#059669"><?= formatUSD($tot['cobrado']) ?></div></div>
    <div class="sec-card"><div class="lbl">Reportado por vendedores</div><div class="val" style="color:#2980b9"><?= formatUSD($tot['reportado']) ?></div></div>
    <div class="sec-card"><div class="lbl">Pendiente de cobro</div><div class="val" style="color:#c0392b"><?= formatUSD($tot['pendiente']) ?></div></div>
</div>

<div class="card">
    <div class="card-header"><i class="fa-solid fa-list"></i><h5>Secuencias</h5></div>
    <div class="card-body p-0">
        <div class="table-wrapper">
            <table class="table" style="font-size:13px">
                <thead>
                    <tr>
                        <th>Secuencia</th><th>Estado</th><th>Desde</th>
                        <th class="text-center">Facturas</th>
                        <th class="text-end">Facturado</th>
                        <th class="text-end">Cobrado</th>
                        <th class="text-end">Reportado</th>
                        <th class="text-end">Pendiente</th>
                        <th class="text-end">Acción</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach($filas as $f): $s=$f['sec']; $t=$f['tot']; ?>
                    <tr>
                        <td>
                            <strong><?= clean($s['nombre']) ?></strong>
                            <?php if ($f['cerrada']): ?>
                            <div style="font-size:10px;color:var(--text-muted)">
                                Cerrada <?= date('d/m/Y H:i', strtotime($f['cierre']['fecha_cierre'])) ?>
                            </div>
                            <?php endif; ?>
                        </td>
                        <td><span class="pill <?= $s['estado']==='activa'?'pill-on':'pill-off' ?>">
                            <?= $s['estado']==='activa'?'Activa':'Cerrada' ?></span></td>
                        <td><?= date('d/m/Y', strtotime($s['fecha_ini'])) ?></td>
                        <td class="text-center"><?= (int)$t['facturas'] ?></td>
                        <td class="text-end"><?= formatUSD($t['facturado']) ?></td>
                        <td class="text-end" style="color:#059669"><?= formatUSD($t['cobrado']) ?></td>
                        <td class="text-end" style="color:#2980b9"><?= formatUSD($t['reportado']) ?></td>
                        <td class="text-end" style="color:#c0392b;font-weight:700"><?= formatUSD($t['pendiente']) ?></td>
                        <td class="text-end">
                            <?php if ($s['estado']==='activa'): ?>
                                <?php if (hasPermission('pagos','editar')): ?>
                                <form method="POST" style="display:inline" onsubmit="return confirm('¿Cerrar esta secuencia? Se guardará una foto de los totales.')">
                                    <input type="hidden" name="<?= CSRF_TOKEN_NAME ?>" value="<?= getCsrfToken() ?>">
                                    <input type="hidden" name="action" value="cerrar">
                                    <input type="hidden" name="sec_id" value="<?= (int)$s['id'] ?>">
                                    <button class="btn btn-sm btn-primary"><i class="fa-solid fa-lock"></i> Cerrar</button>
                                </form>
                                <?php endif; ?>
                            <?php else: ?>
                                <?php if (hasPermission('pagos','editar')): ?>
                                <form method="POST" style="display:inline" onsubmit="return confirm('¿Reabrir esta secuencia?')">
                                    <input type="hidden" name="<?= CSRF_TOKEN_NAME ?>" value="<?= getCsrfToken() ?>">
                                    <input type="hidden" name="action" value="reabrir">
                                    <input type="hidden" name="sec_id" value="<?= (int)$s['id'] ?>">
                                    <button class="btn btn-sm btn-light"><i class="fa-solid fa-lock-open"></i> Reabrir</button>
                                </form>
                                <?php endif; ?>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr style="background:#f8fafc;font-weight:800">
                        <td colspan="3">Total</td>
                        <td class="text-center"><?= $tot['facturas'] ?></td>
                        <td class="text-end"><?= formatUSD($tot['facturado']) ?></td>
                        <td class="text-end"><?= formatUSD($tot['cobrado']) ?></td>
                        <td class="text-end"><?= formatUSD($tot['reportado']) ?></td>
                        <td class="text-end"><?= formatUSD($tot['pendiente']) ?></td>
                        <td></td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>
</div>

<?php include 'includes/footer.php'; ?>
