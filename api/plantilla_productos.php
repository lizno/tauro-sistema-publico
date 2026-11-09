<?php
/**
 * api/plantilla_productos.php
 * Genera y descarga una plantilla Excel para importar productos
 */
require_once '../config/config.php';
require_once '../functions/db.php';
require_once '../functions/helpers.php';
require_once '../functions/auth.php';

requireLogin();

// Generar CSV simple que Excel puede abrir
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="Plantilla_Productos_Tauro.csv"');
header('Cache-Control: no-cache');

$out = fopen('php://output', 'w');

// BOM para UTF-8 en Excel
fputs($out, "\xEF\xBB\xBF");

// Encabezado
fputcsv($out, ['codigo','descripcion','categoria','unidad','bulto','stock_minimo','precio_mayorista','precio_detalista'], ';');

// Filas de ejemplo
$ejemplos = [
    ['MT02-275-18','TAURO 275-18 MT02 (whiteline)','Cauchos (Llantas)','PC',25,25,9.80,10.60],
    ['ROD-6204-2RS','Rodamiento 6204-2RS (sello negro)','Rodamientos','PC',200,200,0.37,0.39],
    ['CAD-428H-120L','Cadena c/kit 428H-120L (dorada)','Cadenas','PC',20,20,3.57,3.85],
    ['NUEVO-CODIGO','Descripción del producto nuevo','Categoría','PC',1,5,0.00,0.00],
];
foreach ($ejemplos as $fila) {
    fputcsv($out, $fila, ';');
}

// Notas al pie
fputcsv($out, [], ';');
fputcsv($out, ['INSTRUCCIONES:'], ';');
fputcsv($out, ['1. No modifiques los nombres de las columnas (primera fila)'], ';');
fputcsv($out, ['2. El campo "codigo" es único — no uses códigos que ya existan en el sistema'], ';');
fputcsv($out, ['3. categoría debe coincidir exactamente: Cauchos (Llantas), Rodamientos, Cadenas, Kit Cadena/Piñón, Piñones, Amortiguadores'], ';');
fputcsv($out, ['4. unidad puede ser: PC, SET, PAR, KG, M, L'], ';');
fputcsv($out, ['5. Los precios van en USD con 2 decimales (usar punto como separador decimal)'], ';');
fputcsv($out, ['6. Si el separador de tu Excel es coma en vez de punto y coma, ajústalo al abrir'], ';');

fclose($out);
exit;
