<?php
// api/productos.php — AJAX: buscar productos con precios
require_once '../config/config.php';
require_once '../functions/db.php';
require_once '../functions/helpers.php';
require_once '../functions/auth.php';

requireLogin();
header('Content-Type: application/json; charset=utf-8');

$q    = clean($_GET['q'] ?? '');
$tipo = in_array($_GET['tipo'] ?? '', ['mayorista','detalista']) ? $_GET['tipo'] : 'detalista';

if (strlen($q) < 1) { echo json_encode([]); exit; }

$rows = dbQuery(
    "SELECT p.id, p.codigo, p.descripcion, p.bulto, p.unidad, p.stock_actual,
            pm.precio_usd precio_mayorista, pd.precio_usd precio_detalista
     FROM productos p
     LEFT JOIN precios pm ON pm.producto_id = p.id AND pm.tipo='mayorista'
     LEFT JOIN precios pd ON pd.producto_id = p.id AND pd.tipo='detalista'
     WHERE p.activo=1 AND p.departamento_id=1
       AND (p.codigo LIKE ? OR p.descripcion LIKE ?)
     ORDER BY p.descripcion LIMIT 30",
    ["%$q%", "%$q%"]
);

$result = array_map(fn($p) => [
    'id'               => (int)$p['id'],
    'codigo'           => $p['codigo'],
    'descripcion'      => $p['descripcion'],
    'bulto'            => (int)$p['bulto'],
    'unidad'           => $p['unidad'],
    'stock_actual'     => (float)$p['stock_actual'],
    'precio_mayorista' => (float)($p['precio_mayorista'] ?? 0),
    'precio_detalista' => (float)($p['precio_detalista'] ?? 0),
    'precio'           => (float)($tipo === 'mayorista' ? ($p['precio_mayorista'] ?? 0) : ($p['precio_detalista'] ?? 0)),
], $rows);

echo json_encode($result, JSON_UNESCAPED_UNICODE);
