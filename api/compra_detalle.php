<?php
// api/compra_detalle.php - Devuelve HTML del detalle de una compra
require_once '../config/config.php';
require_once '../functions/db.php';
require_once '../functions/helpers.php';
require_once '../functions/auth.php';
requireLogin();

$id = cleanInt($_GET['id'] ?? 0);
if (!$id) { echo '<p style="color:red;padding:20px">ID inválido</p>'; exit; }

$compra = dbQueryOne(
    "SELECT c.*, p.nombre proveedor_nombre, u.nombre u_nombre
     FROM compras c LEFT JOIN proveedores p ON c.proveedor_id=p.id LEFT JOIN usuarios u ON c.usuario_id=u.id
     WHERE c.id=?", [$id]
);
if (!$compra) { echo '<p style="color:red;padding:20px">Entrada no encontrada</p>'; exit; }

$detalles = dbQuery(
    "SELECT cd.*, pr.codigo, pr.descripcion FROM compra_detalles cd JOIN productos pr ON cd.producto_id=pr.id WHERE cd.compra_id=?",
    [$id]
);
?>
<div style="padding:4px">
    <div style="background:#f8fafc;border-radius:8px;padding:12px;margin-bottom:16px;font-size:13px">
        <div style="display:flex;gap:24px;flex-wrap:wrap">
            <div><label style="color:var(--text-muted)">N°:</label> <strong><?=clean($compra['numero']??'—')?></strong></div>
            <div><label style="color:var(--text-muted)">Fecha:</label> <strong><?=date('d/m/Y',strtotime($compra['fecha']))?></strong></div>
            <div><label style="color:var(--text-muted)">Proveedor:</label> <strong><?=clean($compra['proveedor_nombre']??'—')?></strong></div>
            <div><label style="color:var(--text-muted)">Registrado por:</label> <strong><?=clean($compra['u_nombre']??'—')?></strong></div>
        </div>
        <?php if($compra['notas']): ?><div style="margin-top:8px;color:var(--text-muted);font-style:italic"><?=clean($compra['notas'])?></div><?php endif; ?>
    </div>
    <table style="width:100%;border-collapse:collapse;font-size:13px">
        <thead><tr style="background:#f8fafc">
            <th style="padding:10px;border-bottom:2px solid var(--border)">Código</th>
            <th style="padding:10px;border-bottom:2px solid var(--border)">Descripción</th>
            <th style="padding:10px;text-align:right;border-bottom:2px solid var(--border)">Cantidad</th>
            <th style="padding:10px;text-align:right;border-bottom:2px solid var(--border)">Costo $</th>
            <th style="padding:10px;text-align:right;border-bottom:2px solid var(--border)">Subtotal $</th>
        </tr></thead>
        <tbody>
        <?php foreach($detalles as $d): ?>
        <tr style="border-bottom:1px solid var(--border)">
            <td style="padding:9px 10px;font-family:monospace;font-size:11px"><strong><?=clean($d['codigo'])?></strong></td>
            <td style="padding:9px 10px"><?=clean($d['descripcion'])?></td>
            <td style="padding:9px 10px;text-align:right;font-weight:700"><?=number_format($d['cantidad'],2)?></td>
            <td style="padding:9px 10px;text-align:right">$<?=number_format($d['precio_usd'],4)?></td>
            <td style="padding:9px 10px;text-align:right;font-weight:700;color:var(--success)">$<?=number_format($d['monto_usd'],2)?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
        <tfoot><tr style="background:#f0fdf4;font-weight:700">
            <td colspan="4" style="padding:10px;text-align:right">TOTAL:</td>
            <td style="padding:10px;text-align:right;font-size:16px;color:var(--success)">$<?=number_format($compra['total_usd'],2)?></td>
        </tr></tfoot>
    </table>
</div>
