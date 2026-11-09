<?php
// api/tasa.php — AJAX: obtener tasa actual
require_once '../config/config.php';
require_once '../functions/db.php';
require_once '../functions/helpers.php';
require_once '../functions/auth.php';

requireLogin();
header('Content-Type: application/json; charset=utf-8');

$tasa = getTasaActual();
echo json_encode($tasa ? [
    'id'      => (int)$tasa['id'],
    'tasa_bs' => (float)$tasa['tasa_bs'],
    'fecha'   => $tasa['fecha'],
    'nota'    => $tasa['nota'] ?? '',
] : null);
