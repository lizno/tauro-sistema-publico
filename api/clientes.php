<?php
// api/clientes.php — AJAX: buscar clientes
require_once '../config/config.php';
require_once '../functions/db.php';
require_once '../functions/helpers.php';
require_once '../functions/auth.php';

requireLogin();
header('Content-Type: application/json; charset=utf-8');

$q = clean($_GET['q'] ?? '');
if (strlen($q) < 1) { echo json_encode([]); exit; }

$rows = dbQuery(
    "SELECT c.id, c.nombre, c.rif, c.telefono, c.tipo_default, c.saldo_pendiente,
            z.nombre zona_nombre, v.nombre vendedor_nombre
     FROM clientes c
     LEFT JOIN zonas z ON c.zona_id = z.id
     LEFT JOIN vendedores v ON c.vendedor_id = v.id
     WHERE c.activo=1 AND (c.nombre LIKE ? OR c.rif LIKE ? OR c.telefono LIKE ?)
     ORDER BY c.nombre LIMIT 20",
    ["%$q%", "%$q%", "%$q%"]
);

echo json_encode($rows, JSON_UNESCAPED_UNICODE);
