<?php
/**
 * api/importar.php
 * Importa productos desde datos JSON enviados por el frontend
 */
require_once '../config/config.php';
require_once '../functions/db.php';
require_once '../functions/helpers.php';
require_once '../functions/auth.php';

requireLogin();
requirePermission('inventario','crear');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['ok'=>false,'msg'=>'Método no permitido.'], 405);
}

$data = json_decode(file_get_contents('php://input'), true);
if (!$data || empty($data['productos'])) {
    jsonResponse(['ok'=>false,'msg'=>'Sin datos para importar.'], 400);
}

$insertados = 0; $actualizados = 0; $errores = [];

foreach ($data['productos'] as $i => $row) {
    $codigo = strtoupper(trim($row['codigo'] ?? ''));
    $desc   = trim($row['descripcion'] ?? '');
    if (!$codigo || !$desc) {
        $errores[] = "Fila ".($i+2).": código o descripción vacíos.";
        continue;
    }
    $catNombre = trim($row['categoria'] ?? '');
    $unidad    = strtoupper(trim($row['unidad'] ?? 'PC'));
    $bulto     = max(1, (int)($row['bulto'] ?? 1));
    $stockMin  = max(0, (float)($row['stock_minimo'] ?? 5));
    $precMay   = max(0, (float)($row['precio_mayorista'] ?? 0));
    $precDet   = max(0, (float)($row['precio_detalista'] ?? 0));

    // Buscar o crear categoría
    $catId = null;
    if ($catNombre) {
        $cat = dbQueryOne("SELECT id FROM categorias WHERE nombre=? LIMIT 1", [$catNombre]);
        if ($cat) {
            $catId = $cat['id'];
        } else {
            dbExecute("INSERT INTO categorias (nombre) VALUES (?)", [$catNombre]);
            $catId = (int)dbLastId();
        }
    }

    $existe = dbQueryOne("SELECT id FROM productos WHERE codigo=?", [$codigo]);
    if ($existe) {
        dbExecute(
            "UPDATE productos SET descripcion=?,categoria_id=?,unidad=?,bulto=?,stock_minimo=? WHERE id=?",
            [$desc, $catId, $unidad, $bulto, $stockMin, $existe['id']]
        );
        $pid = $existe['id'];
        $actualizados++;
    } else {
        dbExecute(
            "INSERT INTO productos (codigo,descripcion,categoria_id,unidad,bulto,stock_minimo,departamento_id) VALUES (?,?,?,?,?,?,1)",
            [$codigo, $desc, $catId, $unidad, $bulto, $stockMin]
        );
        $pid = (int)dbLastId();
        $insertados++;
    }

    foreach (['mayorista'=>$precMay, 'detalista'=>$precDet] as $tipo=>$precio) {
        if ($precio > 0) {
            dbExecute(
                "INSERT INTO precios (producto_id,tipo,precio_usd) VALUES (?,?,?)
                 ON DUPLICATE KEY UPDATE precio_usd=?",
                [$pid, $tipo, $precio, $precio]
            );
        }
    }
}

auditLog('INSERT','productos',0,"Importación masiva: {$insertados} nuevos, {$actualizados} actualizados");
jsonResponse([
    'ok'         => true,
    'insertados' => $insertados,
    'actualizados'=> $actualizados,
    'errores'    => $errores,
    'msg'        => "{$insertados} productos nuevos y {$actualizados} actualizados." .
                    (count($errores) ? " Errores: ".count($errores) : ""),
]);
