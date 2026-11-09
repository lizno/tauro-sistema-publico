<?php
if (!defined('APP_NAME')) {
    require_once dirname(__DIR__) . '/config/config.php';
    require_once dirname(__DIR__) . '/functions/db.php';
    require_once dirname(__DIR__) . '/functions/helpers.php';
    require_once dirname(__DIR__) . '/functions/auth.php';
}
requireLogin();

$tasa        = getTasaActual();
$currentPage = basename($_SERVER['PHP_SELF'], '.php');
$logoPath    = '';
$fotoUser    = '';

try {
    $logoPath = getConfig('logo_path','');
    $usuarioDB = dbQueryOne("SELECT foto_perfil FROM usuarios WHERE id=?",[$_SESSION['user_id']]);
    $fotoUser  = $usuarioDB['foto_perfil'] ?? '';
} catch (Exception $e) {}

$iniciales = 'U';
$nombre = $_SESSION['nombre'] ?? '';
if ($nombre) {
    $p = explode(' ', $nombre);
    $iniciales = strtoupper(substr($p[0],0,1) . (isset($p[1]) ? substr($p[1],0,1) : ''));
}

$menu = [
    'PRINCIPAL' => [
        ['page'=>'index',       'icon'=>'fa-gauge-high',       'label'=>'Dashboard',    'mod'=>null],
    ],
    'OPERACIONES' => [
        ['page'=>'documentos',  'icon'=>'fa-file-invoice',      'label'=>'Documentos',  'mod'=>'ventas'],
        ['page'=>'inventario',  'icon'=>'fa-boxes-stacked',     'label'=>'Inventario',  'mod'=>'inventario'],
        ['page'=>'categorias',  'icon'=>'fa-tags',              'label'=>'Categorias',  'mod'=>'inventario'],
        ['page'=>'compras',     'icon'=>'fa-truck-ramp-box',    'label'=>'Compras',     'mod'=>'compras'],
        ['page'=>'transporte',  'icon'=>'fa-truck-fast',        'label'=>'Transporte',  'mod'=>null],
    ],
    'COBRANZAS' => [
        ['page'=>'pagos',         'icon'=>'fa-money-bill-wave', 'label'=>'Cobros',        'mod'=>'pagos'],
        ['page'=>'jhon_cobranza', 'icon'=>'fa-star',            'label'=>'Cobros Jhon',   'mod'=>'pagos'],
        ['page'=>'tesoreria',     'icon'=>'fa-vault',           'label'=>'Tesorería',     'mod'=>'pagos'],
        ['page'=>'secuencias',    'icon'=>'fa-layer-group',     'label'=>'Secuencias',    'mod'=>'pagos'],
        ['page'=>'devoluciones',  'icon'=>'fa-rotate-left',     'label'=>'Devoluciones',  'mod'=>'devoluciones'],
    ],
    'DIRECTORIO' => [
        ['page'=>'clientes',    'icon'=>'fa-users',            'label'=>'Clientes',     'mod'=>'clientes'],
        ['page'=>'vendedores',  'icon'=>'fa-handshake',        'label'=>'Vendedores',   'mod'=>'vendedores'],
        ['page'=>'proveedores', 'icon'=>'fa-industry',         'label'=>'Proveedores',  'mod'=>'proveedores'],
        ['page'=>'zonas',       'icon'=>'fa-map-location-dot', 'label'=>'Zonas',        'mod'=>'zonas'],
    ],
    'REPORTES Y CONFIG' => [
        ['page'=>'reportes',        'icon'=>'fa-chart-bar',  'label'=>'Reportes',        'mod'=>null],
        ['page'=>'estado_vendedores','icon'=>'fa-chart-line','label'=>'Estado de cuenta','mod'=>'vendedores'],
        ['page'=>'moneda',          'icon'=>'fa-coins',      'label'=>'Tasa de Cambio',  'mod'=>'moneda'],
        ['page'=>'configuracion',   'icon'=>'fa-gear',       'label'=>'Configuración',   'mod'=>null],
        ['page'=>'usuarios',        'icon'=>'fa-lock',       'label'=>'Usuarios',        'mod'=>'usuarios'],
        ['page'=>'auditoria',       'icon'=>'fa-clipboard-list','label'=>'Auditoría',    'mod'=>'auditoria'],
    ],
];
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $pageTitle ?? 'Panel' ?> — <?= APP_NAME ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <!-- Font Awesome 6 -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <!-- DataTables -->
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.7/css/jquery.dataTables.min.css">
    <!-- Select2 -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css">
    <link rel="stylesheet" href="<?= APP_URL ?>/assets/css/style.css">
    <style>
        .dataTables_wrapper .dataTables_filter input { border:1.5px solid var(--border); border-radius:7px; padding:5px 10px; font-family:var(--font); }
        .dataTables_wrapper .dataTables_length select { border:1.5px solid var(--border); border-radius:7px; padding:4px; }
        .dataTables_wrapper .dataTables_info { font-size:12px; color:var(--text-muted); }
        .dataTables_wrapper .dataTables_paginate .paginate_button { border-radius:5px !important; font-size:12px; }
        .dataTables_wrapper .dataTables_paginate .paginate_button.current { background:var(--primary) !important; color:white !important; border-color:var(--primary) !important; }
    </style>
    <?= $extraHead ?? '' ?>
</head>
<body>
<div class="wrapper">

<!-- ══ SIDEBAR ══════════════════════════════════════════════ -->
<aside class="sidebar">
    <a href="<?= APP_URL ?>/index.php" class="sidebar-brand">
        <div class="brand-logo">
            <?php if ($logoPath && file_exists(ROOT_PATH . '/assets/uploads/' . $logoPath)): ?>
                <img src="<?= UPLOADS_URL . '/' . clean($logoPath) ?>" alt="Logo">
            <?php else: ?>
                <i class="fa-solid fa-bull fa-default"></i>
            <?php endif; ?>
        </div>
        <div class="brand-text">
            <strong>TAURO</strong>
            <small>Import Motor's C.A.</small>
        </div>
    </a>

    <nav class="sidebar-nav">
    <?php foreach ($menu as $seccion => $items): ?>
        <div class="sidebar-section"><?= $seccion ?></div>
        <?php foreach ($items as $item):
            if ($item['mod'] && !hasPermission($item['mod'],'ver')) continue;
            if ($item['page']==='tesoreria' && !hasPermission('pagos','crear') && !isSupervisor()) continue;
            if ($item['page']==='usuarios' && !isAdmin()) continue;
            if ($item['page']==='configuracion' && !isSupervisor()) continue;
            $isActive = $currentPage === $item['page'] ||
                        ($currentPage === 'index' && $item['page'] === 'index') ||
                        ($currentPage === 'nueva_venta' && $item['page'] === 'documentos') ||
                        ($currentPage === 'ver_venta'   && $item['page'] === 'documentos');
            $url = APP_URL . '/' . ($item['page'] === 'index' ? 'index.php' : $item['page'] . '.php');
        ?>
        <div class="nav-item">
            <a href="<?= $url ?>" class="nav-link <?= $isActive ? 'active' : '' ?>">
                <i class="fa-solid <?= $item['icon'] ?> nav-icon"></i>
                <span><?= $item['label'] ?></span>
            </a>
        </div>
        <?php endforeach; ?>
    <?php endforeach; ?>
    </nav>

    <div class="sidebar-user">
        <div class="user-avatar">
            <?php if ($fotoUser && file_exists(ROOT_PATH . '/assets/uploads/' . $fotoUser)): ?>
                <img src="<?= UPLOADS_URL . '/' . clean($fotoUser) ?>" alt="Foto">
            <?php else: ?>
                <?= $iniciales ?>
            <?php endif; ?>
        </div>
        <div class="user-info">
            <strong><?= clean(mb_strimwidth($nombre, 0, 20, '...')) ?></strong>
            <small><?= ucfirst($_SESSION['rol_nombre'] ?? '') ?></small>
        </div>
    </div>
</aside>

<!-- ══ MAIN ══════════════════════════════════════════════ -->
<div class="main-content">
    <header class="topbar">
        <h1 class="topbar-title"><?= $pageTitle ?? 'Panel' ?></h1>

        <?php if ($tasa): ?>
        <a href="<?= APP_URL ?>/moneda.php" class="topbar-tasa">
            <i class="fa-solid fa-coins"></i>
            Bs. <?= number_format($tasa['tasa_bs'], 2) ?> / $
            <small>(<?= date('d/m', strtotime($tasa['fecha'])) ?>)</small>
        </a>
        <?php endif; ?>

        <div class="topbar-user">
            <div class="avatar-sm">
                <?php if ($fotoUser && file_exists(ROOT_PATH . '/assets/uploads/' . $fotoUser)): ?>
                    <img src="<?= UPLOADS_URL . '/' . clean($fotoUser) ?>" alt="">
                <?php else: ?>
                    <?= $iniciales ?>
                <?php endif; ?>
            </div>
            <span class="d-none d-md-inline"><?= clean(explode(' ',$nombre)[0] ?? '') ?></span>
            <a href="<?= APP_URL ?>/logout.php" class="btn btn-sm btn-light"
               onclick="return confirm('¿Cerrar sesión?')" title="Cerrar sesión">
                <i class="fa-solid fa-right-from-bracket"></i>
            </a>
        </div>
    </header>

    <main class="page-content">
