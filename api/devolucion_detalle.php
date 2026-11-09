<?php
require_once '../config/config.php';
require_once '../functions/db.php';
require_once '../functions/helpers.php';
require_once '../functions/auth.php';
requireLogin();

$id  = cleanInt($_GET['id'] ?? 0);
$dev = dbQueryOne(
    "SELECT d.*, c.nombre c_nombre, v.numero v_numero, u.nombre u_nombre, ap.nombre aprobador
     FROM devoluciones d
     LEFT JOIN clientes c  ON d.cliente_id   = c.id
     LEFT JOIN ventas v    ON d.venta_id     = v.id
     LEFT JOIN usuarios u  ON d.usuario_id   = u.id
     LEFT JOIN usuarios ap ON d.aprobado_por = ap.id
     WHERE d.id=?", [$id]
);
if (!$dev) { echo '<div class="alert alert-danger" style="margin:20px">Devolución no encontrada.</div>'; exit; }

$dets = dbQuery(
    "SELECT dd.*, p.codigo FROM devolucion_detalles dd LEFT JOIN productos p ON dd.producto_id=p.id WHERE dd.devolucion_id=?", [$id]
);
?>
<div style="padding:4px">
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:16px">
        <div style="background:#f8fafc;border-radius:8px;padding:12px;font-size:13px">
            <div style="font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:1px;color:var(--text-muted);margin-bottom:8px">DEVOLUCIÓN</div>
            <div class="d-flex justify-between mb-1"><span class="text-muted">N°:</span><strong class="font-mono"><?= clean($dev['numero']) ?></strong></div>
            <div class="d-flex justify-between mb-1"><span class="text-muted">Fecha:</span><strong><?= date('d/m/Y',strtotime($dev['fecha'])) ?></strong></div>
            <div class="d-flex justify-between mb-1"><span class="text-muted">Estado:</span><?= badgeEstado($dev['estado']) ?></div>
            <div class="d-flex justify-between"><span class="text-muted">Total:</span><strong style="color:var(--danger)"><?= formatUSD($dev['total_usd']) ?></strong></div>
        </div>
        <div style="background:#f8fafc;border-radius:8px;padding:12px;font-size:13px">
            <div style="font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:1px;color:var(--text-muted);margin-bottom:8px">CLIENTE / FACTURA</div>
            <div class="d-flex justify-between mb-1"><span class="text-muted">Cliente:</span><strong><?= clean($dev['c_nombre']) ?></strong></div>
            <div class="d-flex justify-between mb-1"><span class="text-muted">Factura orig.:</span>
                <?php if ($dev['v_numero']): ?>
                <a href="ver_venta.php?id=<?= $dev['venta_id'] ?>" style="color:var(--primary);font-weight:700;font-family:monospace"><?= clean($dev['v_numero']) ?></a>
                <?php else: ?><span class="text-muted">—</span><?php endif; ?>
            </div>
            <div class="d-flex justify-between mb-1"><span class="text-muted">Motivo:</span><strong style="max-width:60%;text-align:right;font-size:11px"><?= clean($dev['motivo']) ?></strong></div>
            <div class="d-flex justify-between"><span class="text-muted">Registrado por:</span><strong><?= clean($dev['u_nombre']) ?></strong></div>
        </div>
    </div>

    <?php if ($dev['estado']==='aprobada'): ?>
    <div style="background:#f0fdf4;border:1px solid #bbf7d0;border-radius:8px;padding:10px 14px;margin-bottom:14px;font-size:12.5px">
        <i class="fa-solid fa-check-circle" style="color:var(--success)"></i>
        Aprobada por <strong><?= clean($dev['aprobador']??'—') ?></strong> — Stock restituido y factura original ajustada.
    </div>
    <?php elseif ($dev['estado']==='pendiente'): ?>
    <div style="background:#fffbeb;border:1px solid #fde68a;border-radius:8px;padding:10px 14px;margin-bottom:14px;font-size:12.5px">
        <i class="fa-solid fa-hourglass-half" style="color:#f59e0b"></i>
        Pendiente de aprobación — el stock aún NO ha sido restituido.
    </div>
    <?php endif; ?>

    <table style="width:100%;border-collapse:collapse;font-size:12.5px">
        <thead><tr style="background:#f8fafc">
            <th style="padding:9px;border-bottom:2px solid var(--border)">Código</th>
            <th style="padding:9px;border-bottom:2px solid var(--border)">Descripción</th>
            <th style="padding:9px;text-align:right;border-bottom:2px solid var(--border)">Cant.</th>
            <th style="padding:9px;text-align:right;border-bottom:2px solid var(--border)">Precio $</th>
            <th style="padding:9px;text-align:right;border-bottom:2px solid var(--border)">Subtotal $</th>
        </tr></thead>
        <tbody>
        <?php foreach ($dets as $d): ?>
        <tr style="border-bottom:1px solid var(--border)">
            <td style="padding:9px;font-family:monospace;font-size:11px;font-weight:700"><?= clean($d['codigo']??'—') ?></td>
            <td style="padding:9px"><?= clean($d['descripcion']) ?></td>
            <td style="padding:9px;text-align:right;font-weight:700"><?= number_format($d['cantidad'],2) ?></td>
            <td style="padding:9px;text-align:right">$ <?= number_format($d['precio_usd'],2) ?></td>
            <td style="padding:9px;text-align:right;font-weight:700;color:var(--danger)">$ <?= number_format($d['monto_usd'],2) ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
        <tfoot><tr style="background:#fff5f5;font-weight:800">
            <td colspan="4" style="padding:10px;text-align:right">TOTAL DEVUELTO:</td>
            <td style="padding:10px;text-align:right;font-size:16px;color:var(--danger)">$ <?= number_format($dev['total_usd'],2) ?></td>
        </tr></tfoot>
    </table>
</div>
