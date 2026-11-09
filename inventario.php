<?php
require_once 'config/config.php';
require_once 'functions/db.php';
require_once 'functions/helpers.php';
require_once 'functions/auth.php';
require_once 'functions/imagenes.php';

requireLogin();
requirePermission('inventario','ver');
$pageTitle = 'Inventario';

// La columna `imagen` se crea automáticamente la primera vez (si hay permiso ALTER).
$columnaImagen = asegurarColumnaImagenProductos();

// ── PROCESAR POST ──────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $pa = $_POST['action'] ?? '';

    if (in_array($pa, ['crear','editar'])) {
        requirePermission('inventario', $pa === 'crear' ? 'crear' : 'editar');
        $data = [
            'codigo'       => strtoupper(trim($_POST['codigo'] ?? '')),
            'descripcion'  => trim($_POST['descripcion'] ?? ''),
            'categoria_id' => cleanInt($_POST['categoria_id'] ?? 0) ?: null,
            'unidad'       => clean($_POST['unidad'] ?? 'PC'),
            'bulto'        => cleanInt($_POST['bulto'] ?? 1),
            'stock_minimo' => cleanFloat($_POST['stock_minimo'] ?? 5),
        ];
        $precioMay = cleanFloat($_POST['precio_mayorista'] ?? 0);
        $precioDet = cleanFloat($_POST['precio_detalista'] ?? 0);

        $pid            = $pa === 'editar' ? cleanInt($_POST['id'] ?? 0) : 0;
        $anterior       = $pid ? dbQueryOne("SELECT * FROM productos WHERE id=?", [$pid]) : null;
        $imagenAnterior = $anterior['imagen'] ?? null;
        $fotoNueva      = null;
        $errorFoto      = null;

        // ¿Viene una foto nueva?
        $hayArchivo = isset($_FILES['imagen'])
            && (int)($_FILES['imagen']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;

        if (empty($data['codigo']) || empty($data['descripcion'])) {
            $_SESSION['flash'] = ['type'=>'error','msg'=>'Código y descripción son requeridos.'];
        } elseif ($pa === 'editar' && !$anterior) {
            $_SESSION['flash'] = ['type'=>'error','msg'=>'Producto no encontrado.'];
        } else {
            if ($hayArchivo) {
                if (!$columnaImagen) {
                    $errorFoto = 'falta la columna "imagen" en la base de datos (ejecuta install/update_producto_imagen.sql).';
                } else {
                    $subida = subirImagenProducto('imagen');
                    if (!empty($subida['ok'])) {
                        $fotoNueva = $subida['ruta'];
                    } else {
                        $errorFoto = $subida['error'] ?? 'no se pudo procesar la imagen.';
                    }
                }
            }

            $quitarFoto  = !empty($_POST['quitar_imagen']);
            $imagenFinal = $fotoNueva ?: ($quitarFoto ? null : $imagenAnterior);

            try {
                if ($pa === 'crear') {
                    if ($columnaImagen) {
                        dbExecute(
                            "INSERT INTO productos (codigo,descripcion,imagen,categoria_id,unidad,bulto,stock_minimo,departamento_id)
                             VALUES (?,?,?,?,?,?,?,1)",
                            [$data['codigo'],$data['descripcion'],$imagenFinal,$data['categoria_id'],
                             $data['unidad'],$data['bulto'],$data['stock_minimo']]
                        );
                    } else {
                        dbExecute(
                            "INSERT INTO productos (codigo,descripcion,categoria_id,unidad,bulto,stock_minimo,departamento_id)
                             VALUES (?,?,?,?,?,?,1)", array_values($data)
                        );
                    }
                    $pid = (int)dbLastId();
                    auditLog('INSERT','productos',$pid,'Producto creado: '.$data['codigo'],[],$data);
                } else {
                    if ($columnaImagen) {
                        dbExecute(
                            "UPDATE productos SET codigo=?,descripcion=?,imagen=?,categoria_id=?,unidad=?,bulto=?,stock_minimo=?
                             WHERE id=?",
                            [$data['codigo'],$data['descripcion'],$imagenFinal,$data['categoria_id'],
                             $data['unidad'],$data['bulto'],$data['stock_minimo'],$pid]
                        );
                    } else {
                        dbExecute(
                            "UPDATE productos SET codigo=?,descripcion=?,categoria_id=?,unidad=?,bulto=?,stock_minimo=? WHERE id=?",
                            [...array_values($data),$pid]
                        );
                    }
                    auditLog(
                        'UPDATE','productos',$pid,
                        $fotoNueva ? 'Producto editado (foto actualizada)' : 'Producto editado',
                        $anterior,$data
                    );
                }

                foreach (['mayorista'=>$precioMay,'detalista'=>$precioDet] as $tipo=>$precio) {
                    if ($precio > 0) {
                        dbExecute(
                            "INSERT INTO precios (producto_id,tipo,precio_usd,usuario_id) VALUES (?,?,?,?)
                             ON DUPLICATE KEY UPDATE precio_usd=?, usuario_id=?",
                            [$pid,$tipo,round($precio,2),$_SESSION['user_id'],round($precio,2),$_SESSION['user_id']]
                        );
                    }
                }

                // Si se reemplazó o quitó la foto, borrar el archivo anterior.
                if ($columnaImagen && $imagenAnterior && $imagenAnterior !== $imagenFinal) {
                    eliminarImagenProducto($imagenAnterior);
                }

                $_SESSION['flash'] = $errorFoto
                    ? ['type'=>'warning','msg'=>'Producto guardado, pero la foto no se subió: '.$errorFoto]
                    : ['type'=>'success','msg'=>'Producto guardado.'];
            } catch (PDOException $e) {
                if ($fotoNueva) eliminarImagenProducto($fotoNueva);
                $msg = str_contains($e->getMessage(),'Duplicate') ? 'El código ya existe.' : 'Error al guardar.';
                $_SESSION['flash'] = ['type'=>'error','msg'=>$msg];
            }
        }
        header('Location: inventario.php'); exit;
    }

    if ($pa === 'eliminar') {
        requirePermission('inventario','eliminar');
        $pid = cleanInt($_POST['id'] ?? 0);
        $ant = dbQueryOne("SELECT * FROM productos WHERE id=?",[$pid]);
        dbExecute("UPDATE productos SET activo=0 WHERE id=?",[$pid]);
        auditLog('DELETE','productos',$pid,'Producto desactivado: '.($ant['codigo']??''),$ant,[]);
        $_SESSION['flash'] = ['type'=>'success','msg'=>'Producto eliminado.'];
        header('Location: inventario.php'); exit;
    }
}

// ── DATOS ──────────────────────────────────────────────────
$categorias   = dbQuery("SELECT * FROM categorias WHERE activo=1 ORDER BY nombre");
$filtroCateg  = cleanInt($_GET['cat'] ?? 0);
$filtroStock  = clean($_GET['stock'] ?? '');
$busqueda     = clean($_GET['q'] ?? '');

// NUEVO: por defecto se ocultan los productos sin existencia.
// ?ver_agotados=1 muestra también los agotados.
$verAgotados  = cleanInt($_GET['ver_agotados'] ?? 0) === 1;

// ── CONTADOR DE AGOTADOS (siempre sobre el total, sin filtros de categoría/búsqueda) ──
$agotadosTotal = (int)(dbQueryOne(
    "SELECT COUNT(*) n FROM productos
     WHERE activo=1 AND departamento_id=1 AND stock_actual <= 0"
)['n'] ?? 0);

$sql = "SELECT p.*, c.nombre cat_nombre,
               pm.precio_usd precio_mayorista,
               pd.precio_usd precio_detalista
        FROM productos p
        LEFT JOIN categorias c ON p.categoria_id = c.id
        LEFT JOIN precios pm ON pm.producto_id=p.id AND pm.tipo='mayorista'
        LEFT JOIN precios pd ON pd.producto_id=p.id AND pd.tipo='detalista'
        WHERE p.activo=1 AND p.departamento_id=1";
$params = [];

// NUEVO: validación de existencia
if (!$verAgotados) {
    $sql .= " AND p.stock_actual > 0";
}

if ($filtroCateg)  { $sql .= " AND p.categoria_id=?"; $params[]=$filtroCateg; }
if ($filtroStock==='bajo') { $sql .= " AND p.stock_actual<=p.stock_minimo"; }
if ($busqueda)     { $sql .= " AND (p.codigo LIKE ? OR p.descripcion LIKE ?)"; $params[]="%$busqueda%"; $params[]="%$busqueda%"; }
$sql .= " ORDER BY c.nombre, p.descripcion";
$productos = dbQuery($sql,$params);

$editProducto = null;
if (($_GET['action']??'')==='editar' && isset($_GET['id'])) {
    $editProducto = dbQueryOne(
        "SELECT p.*, pm.precio_usd precio_mayorista, pd.precio_usd precio_detalista
         FROM productos p
         LEFT JOIN precios pm ON pm.producto_id=p.id AND pm.tipo='mayorista'
         LEFT JOIN precios pd ON pd.producto_id=p.id AND pd.tipo='detalista'
         WHERE p.id=?", [cleanInt($_GET['id'])]
    );
}

// Estadísticas
$totalProductos = count($productos);
$stockBajoCount = count(array_filter($productos, fn($p) => $p['stock_actual'] <= $p['stock_minimo']));
$valorInventario= array_sum(array_map(fn($p) => $p['stock_actual'] * ($p['precio_mayorista'] ?? 0), $productos));
$conFotosCount  = count(array_filter($productos, fn($p) => productoImagenDisponible($p['imagen'] ?? null)));

include 'includes/header.php';
?>

<?php if (!$columnaImagen): ?>
<div class="alert alert-warning">
    <i class="fa-solid fa-triangle-exclamation"></i>
    <div>
        <strong>Falta la columna de fotos en la base de datos.</strong>
        Ejecuta <code>install/update_producto_imagen.sql</code> en phpMyAdmin para poder subir imágenes.
    </div>
</div>
<?php endif; ?>

<!-- STATS -->
<div class="row mb-4">
    <div class="col-4">
        <div class="stat-card">
            <div class="stat-icon"><i class="fa-solid fa-boxes-stacked"></i></div>
            <div>
                <div class="stat-value"><?= $totalProductos ?></div>
                <div class="stat-label">Productos en lista<?= $verAgotados ? ' (incluye agotados)' : ' (con existencia)' ?></div>
            </div>
        </div>
    </div>
    <div class="col-4">
        <div class="stat-card <?= $stockBajoCount > 0 ? 'red' : 'green' ?>">
            <div class="stat-icon"><i class="fa-solid fa-triangle-exclamation"></i></div>
            <div>
                <div class="stat-value"><?= $stockBajoCount ?></div>
                <div class="stat-label">Stock bajo / agotado</div>
            </div>
        </div>
    </div>
    <div class="col-4">
        <div class="stat-card blue">
            <div class="stat-icon"><i class="fa-solid fa-dollar-sign"></i></div>
            <div>
                <div class="stat-value"><?= formatUSD($valorInventario) ?></div>
                <div class="stat-label">Valor inventario (mayorista)</div>
            </div>
        </div>
    </div>
</div>

<!-- TOOLBAR -->
<div class="d-flex justify-between align-center mb-4" style="flex-wrap:wrap;gap:10px">
    <div class="d-flex gap-2" style="flex-wrap:wrap">
        <a href="inventario.php<?= $verAgotados?'?ver_agotados=1':'' ?>"
           class="btn <?= !$filtroStock&&!$filtroCateg&&!$busqueda?'btn-primary':'btn-light' ?> btn-sm">Todos</a>
        <a href="inventario.php?stock=bajo<?= $verAgotados?'&ver_agotados=1':'' ?>"
           class="btn <?= $filtroStock==='bajo'?'btn-danger':'btn-light' ?> btn-sm">
            <i class="fa-solid fa-triangle-exclamation"></i> Stock Bajo
        </a>
        <?php foreach ($categorias as $cat): ?>
        <a href="inventario.php?cat=<?= $cat['id'] ?><?= $verAgotados?'&ver_agotados=1':'' ?>"
           class="btn <?= $filtroCateg==$cat['id']?'btn-primary':'btn-light' ?> btn-sm">
            <?= clean($cat['nombre']) ?>
        </a>
        <?php endforeach; ?>
    </div>
    <div class="d-flex gap-2">

        <!-- NUEVO: Toggle ver agotados -->
        <?php
            // preservar filtros actuales al alternar
            $qsBase = [];
            if ($filtroCateg) $qsBase['cat'] = $filtroCateg;
            if ($filtroStock) $qsBase['stock'] = $filtroStock;
            if ($busqueda)    $qsBase['q'] = $busqueda;

            $qsVer    = $qsBase;  $qsVer['ver_agotados']    = 1;
            $qsOcultar= $qsBase;  // sin ver_agotados = ocultos
        ?>
        <?php if ($verAgotados): ?>
            <a href="inventario.php?<?= http_build_query($qsOcultar) ?>"
               class="btn btn-light btn-sm" title="Ocultar productos sin existencia">
                <i class="fa-solid fa-eye-slash"></i> Ocultar agotados
                <?php if ($agotadosTotal > 0): ?>
                    <span class="badge badge-warning" style="margin-left:4px"><?= $agotadosTotal ?></span>
                <?php endif; ?>
            </a>
        <?php else: ?>
            <a href="inventario.php?<?= http_build_query($qsVer) ?>"
               class="btn btn-light btn-sm" title="Mostrar también productos sin existencia">
                <i class="fa-solid fa-eye"></i> Ver agotados
                <?php if ($agotadosTotal > 0): ?>
                    <span class="badge badge-warning" style="margin-left:4px"><?= $agotadosTotal ?></span>
                <?php endif; ?>
            </a>
        <?php endif; ?>

        <div class="dropdown" style="position:relative">
            <button type="button" class="btn btn-primary btn-sm" onclick="toggleDropdown('menuPdf')">
                <i class="fa-solid fa-file-pdf"></i> Imprimir PDF
            </button>
            <div id="menuPdf" class="print-menu" style="display:none">
                <div class="print-menu-info menu-sel-info">Se incluirá toda la lista.</div>
                <div class="print-menu-title">Lista de precios en PDF</div>
                <button type="button" onclick="imprimirLista('ambas')"     class="btn btn-light btn-sm"><i class="fa-solid fa-list"></i> Mayorista + Detalista</button>
                <button type="button" onclick="imprimirLista('mayorista')" class="btn btn-light btn-sm"><i class="fa-solid fa-industry"></i> Solo Mayorista</button>
                <button type="button" onclick="imprimirLista('detalista')" class="btn btn-light btn-sm"><i class="fa-solid fa-store"></i> Solo Detalista</button>
                <label class="drop-foto-toggle" title="Incluye la foto de cada producto al lado del nombre">
                    <input type="checkbox" id="chkFotos" <?= $conFotosCount > 0 ? 'checked' : '' ?> <?= $conFotosCount > 0 ? '' : 'disabled' ?>>
                    <span><i class="fa-solid fa-image"></i> Incluir fotos en el PDF
                        <small>(<?= $conFotosCount ?>/<?= $totalProductos ?>)</small>
                    </span>
                </label>
            </div>
        </div>

        <div class="dropdown" style="position:relative">
            <button type="button" class="btn btn-success btn-sm" onclick="toggleDropdown('menuExcel')">
                <i class="fa-solid fa-file-excel"></i> Imprimir Excel
            </button>
            <div id="menuExcel" class="print-menu" style="display:none">
                <div class="print-menu-info menu-sel-info">Se incluirá toda la lista.</div>
                <div class="print-menu-title">Lista de precios en Excel</div>
                <button type="button" onclick="exportarListaExcel('ambas')"     class="btn btn-light btn-sm"><i class="fa-solid fa-list"></i> Mayorista + Detalista</button>
                <button type="button" onclick="exportarListaExcel('mayorista')" class="btn btn-light btn-sm"><i class="fa-solid fa-industry"></i> Solo Mayorista</button>
                <button type="button" onclick="exportarListaExcel('detalista')" class="btn btn-light btn-sm"><i class="fa-solid fa-store"></i> Solo Detalista</button>
                <div class="print-menu-hint">
                    Sale con <strong>foto</strong> y los datos: código, descripción, categoría,
                    bulto, precio, una columna <strong>CANTIDADES</strong> para anotar lo que
                    pide el cliente y el <strong>monto total</strong> abajo.
                </div>
            </div>
        </div>

        <button type="button" onclick="exportarExcelInventario()" class="btn btn-light btn-sm"
                title="Descargar todo el inventario en Excel (existencias y precio por bulto)">
            <i class="fa-solid fa-table-cells"></i> Inventario a Excel
        </button>

        <input type="file" id="inputImportar" accept=".xlsx,.xls,.csv" style="display:none"
               onchange="importarExcel(this.files[0]); this.value='';">
        <button type="button" onclick="document.getElementById('inputImportar').click()" class="btn btn-light btn-sm">
            <i class="fa-solid fa-file-import"></i> Importar Excel
        </button>

        <?php if (hasPermission('inventario','crear')): ?>
        <button type="button" onclick="openModal('modalProducto')" class="btn btn-primary btn-sm">
            <i class="fa-solid fa-plus"></i> Nuevo Producto
        </button>
        <?php endif; ?>
    </div>
</div>

<!-- SELECCIÓN DE PRODUCTOS PARA LA LISTA -->
<div id="selBarra" class="sel-barra" style="display:none">
    <div>
        <i class="fa-solid fa-square-check"></i>
        <strong><span id="selCount">0</span> producto(s) seleccionado(s)</strong>
        — la lista PDF y Excel saldrá solo con estos.
    </div>
    <button type="button" class="btn btn-light btn-sm" onclick="limpiarSeleccion()">
        <i class="fa-solid fa-xmark"></i> Quitar selección
    </button>
</div>

<!-- BÚSQUEDA -->
<div class="card mb-4">
    <div class="card-body" style="padding:10px 16px">
        <form method="GET" class="d-flex gap-2 align-center">
            <i class="fa-solid fa-magnifying-glass text-muted"></i>
            <input type="text" name="q" class="form-control" placeholder="Buscar por código o descripción..."
                   value="<?= clean($busqueda) ?>" style="max-width:340px">
            <?php if ($filtroCateg): ?><input type="hidden" name="cat" value="<?= $filtroCateg ?>"><?php endif; ?>
            <?php if ($filtroStock): ?><input type="hidden" name="stock" value="<?= $filtroStock ?>"><?php endif; ?>
            <?php if ($verAgotados): ?><input type="hidden" name="ver_agotados" value="1"><?php endif; ?>
            <button type="submit" class="btn btn-primary btn-sm">Buscar</button>
            <?php if ($busqueda): ?>
                <a href="inventario.php<?= $verAgotados?'?ver_agotados=1':'' ?>" class="btn btn-light btn-sm">
                    <i class="fa-solid fa-xmark"></i>
                </a>
            <?php endif; ?>
        </form>
    </div>
</div>

<!-- TABLA INVENTARIO -->
<div class="card">
    <div class="card-header">
        <i class="fa-solid fa-boxes-stacked text-primary"></i>
        <h5>Inventario Tauro — <?= $totalProductos ?> productos<?= $verAgotados ? '' : ' con existencia' ?></h5>
        <small class="text-muted" style="margin-left:auto"><?= date('d/m/Y H:i') ?></small>
    </div>
    <div class="card-body p-0">
        <div class="table-wrapper">
        <table class="table" id="tablaInventario">
            <thead>
                <tr>
                    <th class="text-center" style="width:38px">
                        <input type="checkbox" id="chkTodos" class="chk-todos"
                               title="Seleccionar todos los productos de la tabla">
                    </th>
                    <th class="text-center" style="width:56px">Foto</th>
                    <th>Código / REF</th>
                    <th>Descripción</th>
                    <th>Categoría</th>
                    <th class="text-center">Bulto</th>
                    <th class="text-right">Stock Actual</th>
                    <th class="text-right">Stock Mín.</th>
                    <th class="text-right">P. Mayor $ </th>
                    <th class="text-right">P. Detail $</th>
                    <th class="text-right">Val. Inv. $</th>
                    <th class="text-center">Estado</th>
                    <th class="text-center">Acciones</th>
                </tr>
            </thead>
            <tbody>
            <?php if (empty($productos)): ?>
                <tr>
                    <td colspan="13" class="text-center text-muted" style="padding:24px">
                        <i class="fa-solid fa-box-open"></i>
                        No hay productos <?= $verAgotados ? '' : 'con existencia ' ?>que coincidan con los filtros.
                        <?php if (!$verAgotados && $agotadosTotal > 0): ?>
                            <br><a href="inventario.php?<?= http_build_query($qsVer) ?>" class="btn btn-light btn-sm" style="margin-top:8px">
                                <i class="fa-solid fa-eye"></i> Ver <?= $agotadosTotal ?> producto(s) agotado(s)
                            </a>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php else: ?>
            <?php foreach ($productos as $p):
                $bajo   = $p['stock_actual'] <= $p['stock_minimo'];
                $agotado= $p['stock_actual'] <= 0;
                $valInv = $p['stock_actual'] * ($p['precio_mayorista'] ?? 0);
            ?>
            <tr style="<?= $bajo ? 'background:#fff5f5' : '' ?>">
                <td class="text-center">
                    <input type="checkbox" class="chk-prod" value="<?= $p['id'] ?>"
                           title="Incluir <?= clean($p['codigo']) ?> en la lista PDF/Excel">
                </td>
                <td class="text-center">
                    <?php if (productoImagenDisponible($p['imagen'] ?? null)): ?>
                        <button type="button" class="foto-mini"
                                data-foto="<?= clean(productoImagenUrl($p['imagen'])) ?>"
                                data-nombre="<?= clean($p['codigo'].' — '.$p['descripcion']) ?>"
                                title="Ver foto en tamaño completo">
                            <img src="<?= clean(productoImagenThumbUrl($p['imagen'])) ?>"
                                 alt="Foto <?= clean($p['codigo']) ?>" loading="lazy">
                        </button>
                    <?php elseif (hasPermission('inventario','editar')): ?>
                        <a href="inventario.php?action=editar&id=<?= $p['id'] ?>"
                           class="foto-mini foto-mini-vacia" title="Agregar foto">
                            <i class="fa-regular fa-image"></i>
                        </a>
                    <?php else: ?>
                        <span class="foto-mini foto-mini-vacia"><i class="fa-regular fa-image"></i></span>
                    <?php endif; ?>
                </td>
                <td><strong class="font-mono fs-12"><?= clean($p['codigo']) ?></strong></td>
                <td><?= clean($p['descripcion']) ?></td>
                <td><span class="badge badge-secondary fs-11"><?= clean($p['cat_nombre']??'') ?></span></td>
                <td class="text-center"><?= $p['bulto'] ?></td>
                <td class="text-right fw-bold <?= $bajo?'text-danger':'text-success' ?>">
                    <?= $bajo?'<i class="fa-solid fa-triangle-exclamation"></i> ':'' ?><?= number_format($p['stock_actual']) ?>
                </td>
                <td class="text-right"><?= number_format($p['stock_minimo']) ?></td>
                <td class="text-right"><?= $p['precio_mayorista'] ? '$ '.formatPrecio($p['precio_mayorista']) : '<span class="text-muted">—</span>' ?></td>
                <td class="text-right"><?= $p['precio_detalista'] ? '$ '.formatPrecio($p['precio_detalista']) : '<span class="text-muted">—</span>' ?></td>
                <td class="text-right fw-bold"><?= $valInv > 0 ? '$ '.number_format($valInv,2) : '—' ?></td>
                <td class="text-center">
                    <?php if ($agotado): ?>
                        <span class="badge badge-danger">Agotado</span>
                    <?php elseif ($bajo): ?>
                        <span class="badge badge-danger">Bajo</span>
                    <?php else: ?>
                        <span class="badge badge-success">OK</span>
                    <?php endif; ?>
                </td>
                <td class="text-center">
                    <div class="d-flex gap-1" style="justify-content:center">
                        <?php if (hasPermission('inventario','editar')): ?>
                        <a href="compras.php?action=nuevo" class="btn btn-sm btn-info btn-icon" title="Registrar entrada de stock">
                            <i class="fa-solid fa-truck-ramp-box"></i>
                        </a>
                        <a href="inventario.php?action=editar&id=<?= $p['id'] ?>"
                            class="btn btn-sm btn-warning btn-icon" title="Editar">
                            <i class="fa-solid fa-pen"></i>
                        </a>
                        <?php endif; ?>
                        <?php if (hasPermission('inventario','eliminar')): ?>
                        <button type="button" onclick="confirmarEliminar(<?= $p['id'] ?>,'<?= addslashes(clean($p['descripcion'])) ?>')"
                            class="btn btn-sm btn-danger btn-icon" title="Eliminar">
                            <i class="fa-solid fa-trash"></i>
                        </button>
                        <?php endif; ?>
                    </div>
                </td>
            </tr>
            <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
            <tfoot>
                <tr style="background:#f8fafc;font-weight:700;font-size:12px">
                    <td colspan="6" class="text-right">TOTALES:</td>
                    <td class="text-right"><?= number_format(array_sum(array_column($productos,'stock_actual'))) ?></td>
                    <td></td><td></td><td></td>
                    <td class="text-right">$ <?= number_format($valorInventario,2) ?></td>
                    <td colspan="2"></td>
                </tr>
            </tfoot>
        </table>
        </div>
    </div>
</div>

<!-- ══ MODAL: Crear/Editar Producto ══════════════════════ -->
<?php
$fotoActual     = $editProducto['imagen'] ?? '';
$fotoActualMini = productoImagenThumbUrl($fotoActual);
?>
<div class="modal-overlay" id="modalProducto">
    <div class="modal-box">
        <div class="modal-header">
            <h4><?= $editProducto ? 'Editar Producto' : 'Nuevo Producto' ?></h4>
            <button type="button" class="btn-close" onclick="closeModal('modalProducto')"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <form method="POST" enctype="multipart/form-data" onsubmit="return guardandoProducto(this)">
            <div class="modal-body">
                <input type="hidden" name="<?= CSRF_TOKEN_NAME ?>" value="<?= getCsrfToken() ?>">
                <input type="hidden" name="action" value="<?= $editProducto ? 'editar' : 'crear' ?>">
                <?php if ($editProducto): ?><input type="hidden" name="id" value="<?= $editProducto['id'] ?>"><?php endif; ?>
                <div class="row">
                    <div class="col-6">
                        <div class="form-group">
                            <label class="form-label">Código / REF *</label>
                            <input type="text" name="codigo" class="form-control font-mono"
                                value="<?= clean($editProducto['codigo']??'') ?>"
                                placeholder="Ej: MT02-275-18" maxlength="50"
                                style="text-transform:uppercase"
                                <?= $editProducto ? 'readonly' : 'required' ?>>
                        </div>
                    </div>
                    <div class="col-6">
                        <div class="form-group">
                            <label class="form-label">Categoría</label>
                            <select name="categoria_id" class="form-select">
                                <option value="">— Sin categoría —</option>
                                <?php foreach ($categorias as $cat): ?>
                                <option value="<?= $cat['id'] ?>" <?= ($editProducto['categoria_id']??0)==$cat['id']?'selected':'' ?>>
                                    <?= clean($cat['nombre']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label">Foto del producto</label>
                    <div class="foto-editor">
                        <div class="foto-preview" id="fotoPreview"
                             title="Haz clic o arrastra una imagen aquí"
                             onclick="document.getElementById('fotoInput').click()">
                            <img id="fotoPreviewImg" <?= $fotoActualMini ? 'src="'.clean($fotoActualMini).'"' : '' ?> alt=""
                                 style="<?= $fotoActualMini ? '' : 'display:none' ?>">
                            <div class="foto-vacia" id="fotoPreviewVacia" style="<?= $fotoActualMini ? 'display:none' : '' ?>">
                                <i class="fa-regular fa-image"></i>Sin foto
                            </div>
                        </div>
                        <div class="foto-editor-info">
                            <input type="file" id="fotoInput" name="imagen" accept="image/jpeg,image/png"
                                   style="display:none" onchange="previewFoto(this)">
                            <button type="button" class="btn btn-light btn-sm"
                                    onclick="document.getElementById('fotoInput').click()">
                                <i class="fa-solid fa-upload"></i>
                                <span id="fotoBtnTxt"><?= $fotoActualMini ? 'Cambiar foto' : 'Subir foto' ?></span>
                            </button>
                            <label class="foto-quitar" id="fotoQuitarWrap"
                                   style="<?= $fotoActualMini ? '' : 'display:none' ?>">
                                <input type="checkbox" name="quitar_imagen" value="1" id="quitarImagen"
                                       onchange="toggleQuitarFoto(this)"> Quitar foto actual
                            </label>
                            <div class="foto-hint">
                                JPG o PNG · hasta 12 MB · se guarda en su calidad original.<br>
                                La foto aparece al lado del nombre en la lista mayorista y detalista.
                            </div>
                        </div>
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label">Descripción *</label>
                    <input type="text" name="descripcion" class="form-control"
                        value="<?= clean($editProducto['descripcion']??'') ?>"
                        placeholder="Descripción completa del producto" maxlength="255" required>
                </div>
                <div class="row">
                    <div class="col-4">
                        <div class="form-group">
                            <label class="form-label">Unidad</label>
                            <select name="unidad" class="form-select">
                                <?php foreach (['PC','SET','PAR','KG','M','L'] as $u): ?>
                                <option value="<?= $u ?>" <?= ($editProducto['unidad']??'PC')===$u?'selected':'' ?>><?= $u ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="col-4">
                        <div class="form-group">
                            <label class="form-label">Bulto (Caja)</label>
                            <input type="number" name="bulto" class="form-control" value="<?= $editProducto['bulto']??1 ?>" min="1" required>
                        </div>
                    </div>
                    <div class="col-4">
                        <div class="form-group">
                            <label class="form-label">Stock Mínimo</label>
                            <input type="number" name="stock_minimo" class="form-control" value="<?= $editProducto['stock_minimo']??5 ?>" min="0" step="0.01">
                        </div>
                    </div>
                </div>
                <hr class="divider">
                <p class="fw-bold fs-12 mb-2">Precios en USD (2 decimales)</p>
                <div class="row">
                    <div class="col-6">
                        <div class="form-group">
                            <label class="form-label">Precio Mayorista $</label>
                            <div class="input-group">
                                <span class="input-group-text">$</span>
                                <input type="number" name="precio_mayorista" class="form-control"
                                    value="<?= $editProducto ? number_format($editProducto['precio_mayorista']??0,2,'.','') : '' ?>"
                                    min="0" step="0.01" placeholder="0.00">
                            </div>
                        </div>
                    </div>
                    <div class="col-6">
                        <div class="form-group">
                            <label class="form-label">Precio Detalista $</label>
                            <div class="input-group">
                                <span class="input-group-text">$</span>
                                <input type="number" name="precio_detalista" class="form-control"
                                    value="<?= $editProducto ? number_format($editProducto['precio_detalista']??0,2,'.','') : '' ?>"
                                    min="0" step="0.01" placeholder="0.00">
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" onclick="closeModal('modalProducto')" class="btn btn-light">Cancelar</button>
                <button type="submit" class="btn btn-primary"><i class="fa-solid fa-floppy-disk"></i> Guardar</button>
            </div>
        </form>
    </div>
</div>

<!-- ══ MODAL: Ver foto en tamaño completo ═════════════════ -->
<div class="modal-overlay" id="modalFoto">
    <div class="modal-box" style="max-width:900px">
        <div class="modal-header">
            <h4 id="modalFotoTitulo">Foto del producto</h4>
            <button type="button" class="btn-close" onclick="closeModal('modalFoto')"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <div class="modal-body" style="text-align:center;background:#f8fafc">
            <img id="modalFotoImg" src="" alt=""
                 style="max-width:100%;max-height:70vh;border-radius:10px;box-shadow:0 10px 30px rgba(0,0,0,.18);background:#fff">
            <div style="margin-top:14px">
                <a id="modalFotoLink" href="#" target="_blank" rel="noopener" class="btn btn-light btn-sm">
                    <i class="fa-solid fa-up-right-from-square"></i> Abrir original (máxima calidad)
                </a>
            </div>
        </div>
    </div>
</div>

<!-- Preview de importación -->
<div id="importarPreview" style="display:none"></div>

<!-- IFRAME de impresión oculto -->
<iframe id="printFrame" style="display:none"></iframe>

<!-- Formularios ocultos -->
<form method="POST" id="formEliminar" style="display:none">
    <input type="hidden" name="<?= CSRF_TOKEN_NAME ?>" value="<?= getCsrfToken() ?>">
    <input type="hidden" name="action" value="eliminar">
    <input type="hidden" name="id" id="eliminarId">
</form>

<?php
$empresa_nombre = function_exists('getConfig') ? getConfig('empresa_nombre', APP_NAME) : APP_NAME;
$empresa_rif    = function_exists('getConfig') ? getConfig('empresa_rif', '') : '';
$empresa_dir    = function_exists('getConfig')
    ? (getConfig('empresa_dir', '') ?: getConfig('empresa_direccion', ''))
    : '';

// Cadenas listas para el JavaScript (exportación a Excel).
$jsEmpresaNombre = json_encode($empresa_nombre, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
$jsEmpresaRif    = json_encode($empresa_rif,    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
$jsEmpresaDir    = json_encode($empresa_dir,    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

$prodsJSON = json_encode(array_map(fn($p) => [
    'id'          => (int)$p['id'],
    'codigo'      => $p['codigo'],
    'descripcion' => $p['descripcion'],
    'cat'         => $p['cat_nombre'] ?? '',
    'unidad'      => $p['unidad'] ?? '',
    'bulto'       => (int)$p['bulto'],
    'stock'       => (float)($p['stock_actual'] ?? 0),
    'stock_min'   => (float)($p['stock_minimo'] ?? 0),
    'mayorista'   => (float)($p['precio_mayorista'] ?? 0),
    'detalista'   => (float)($p['precio_detalista'] ?? 0),
    // Rutas relativas: funcionan igual en local y en el servidor, sin depender de APP_URL.
    'foto'        => productoImagenDisponible($p['imagen'] ?? null)
                        ? 'assets/uploads/' . $p['imagen'] : '',
    'foto_print'  => productoImagenDisponible($p['imagen'] ?? null)
                        ? 'assets/uploads/' . productoRutaImagenPrint($p['imagen']) : '',
    'foto_thumb'  => productoImagenDisponible($p['imagen'] ?? null)
                        ? 'assets/uploads/' . productoRutaImagenThumb($p['imagen']) : '',
], $productos), JSON_UNESCAPED_UNICODE);
?>

<!-- Generador de Excel con fotos (solo se usa en esta página) -->
<script defer src="https://cdn.jsdelivr.net/npm/exceljs@4.4.0/dist/exceljs.min.js"></script>

<script>
// ── Dropdown de impresión ────────────────────────────────
function toggleDropdown(id) {
    const m = document.getElementById(id || 'menuPdf');
    if (!m) return;
    const abrir = m.style.display === 'none';
    document.querySelectorAll('.print-menu').forEach(function(x){ x.style.display = 'none'; });
    if (abrir) m.style.display = 'block';
}
document.addEventListener('click', function(e) {
    const dentro = e.target.closest ? e.target.closest('.dropdown') : null;
    document.querySelectorAll('.print-menu').forEach(function(m) {
        if (!dentro || !dentro.contains(m)) m.style.display = 'none';
    });
});

// ── Datos para JS ────────────────────────────────────────
const PRODS_INV = <?= $prodsJSON ?>;

// ── Selección de productos para la lista (PDF / Excel) ───
function idsSeleccionados() {
    return Array.from(document.querySelectorAll('.chk-prod:checked'))
                .map(function(c){ return String(c.value); });
}

/** Productos que saldrán en la lista: los seleccionados o todos. */
function productosParaLista() {
    const ids = idsSeleccionados();
    if (!ids.length) return PRODS_INV;
    const set = new Set(ids);
    return PRODS_INV.filter(function(p){ return set.has(String(p.id)); });
}

function actualizarSeleccion() {
    const marcados = document.querySelectorAll('.chk-prod:checked').length;
    const total    = document.querySelectorAll('.chk-prod').length;

    const barra = document.getElementById('selBarra');
    if (barra) barra.style.display = marcados ? 'flex' : 'none';
    const cont = document.getElementById('selCount');
    if (cont) cont.textContent = marcados;

    const todos = document.getElementById('chkTodos');
    if (todos) {
        todos.checked = total > 0 && marcados === total;
        todos.indeterminate = marcados > 0 && marcados < total;
    }

    document.querySelectorAll('.menu-sel-info').forEach(function(info) {
        info.textContent = marcados
            ? 'Se incluirán ' + marcados + ' producto(s) seleccionado(s).'
            : 'Se incluirá toda la lista (' + PRODS_INV.length + ' productos).';
    });
}

function marcarTodos(estado) {
    document.querySelectorAll('.chk-prod').forEach(function(c){ c.checked = estado; });
    actualizarSeleccion();
}

function limpiarSeleccion() {
    document.querySelectorAll('.chk-prod').forEach(function(c){ c.checked = false; });
    actualizarSeleccion();
}

document.addEventListener('change', function(e) {
    if (!e.target) return;
    if (e.target.classList.contains('chk-prod')) actualizarSeleccion();
    if (e.target.id === 'chkTodos') marcarTodos(e.target.checked);
});

document.addEventListener('DOMContentLoaded', function() {
    actualizarSeleccion();
});

// ── Fotos en la lista: utilidades ────────────────────────
const FOTO_ORIGINAL = <?= json_encode($fotoActualMini, JSON_UNESCAPED_SLASHES) ?>;

function blobToDataURL(blob) {
    return new Promise(function(resolve, reject) {
        const fr = new FileReader();
        fr.onload  = function(){ resolve(fr.result); };
        fr.onerror = function(){ reject(new Error('No se pudo leer la imagen')); };
        fr.readAsDataURL(blob);
    });
}

/**
 * Reduce una foto a un máximo de `max` px (fondo blanco, JPEG) para
 * incrustarla en el Excel sin inflar el archivo. Devuelve {data,w,h}.
 */
function reducirImagen(dataUrl, max) {
    return new Promise(function(resolve) {
        const img = new Image();
        img.onload = function() {
            const nw = img.naturalWidth  || max;
            const nh = img.naturalHeight || max;
            const k  = Math.min(1, max / Math.max(nw, nh));
            const w  = Math.max(1, Math.round(nw * k));
            const h  = Math.max(1, Math.round(nh * k));

            const cv = document.createElement('canvas');
            cv.width = w;
            cv.height = h;
            const ctx = cv.getContext('2d');
            ctx.fillStyle = '#ffffff';
            ctx.fillRect(0, 0, w, h);
            ctx.drawImage(img, 0, 0, w, h);

            resolve({ data: cv.toDataURL('image/jpeg', 0.82), w: w, h: h });
        };
        img.onerror = function(){ resolve(null); };
        img.src = dataUrl;
    });
}

/**
 * Descarga las fotos en paralelo (máx. 6 a la vez) y las convierte a
 * data URL para incrustarlas en el PDF o en el Excel. Si una falla, devuelve null.
 */
async function precargarFotos(objetivos, onProgress) {
    const salida = new Array(objetivos.length).fill(null);
    let siguiente = 0, listos = 0;

    async function trabajador() {
        while (siguiente < objetivos.length) {
            const i = siguiente++;
            try {
                const resp = await fetch(objetivos[i].url, { cache: 'force-cache', credentials: 'same-origin' });
                if (!resp.ok) throw new Error('HTTP ' + resp.status);
                salida[i] = { data: await blobToDataURL(await resp.blob()) };
            } catch (e) {
                salida[i] = null;
            }
            listos++;
            if (onProgress) onProgress(listos, objetivos.length);
        }
    }

    await Promise.all(Array.from({ length: Math.min(6, objetivos.length) }, trabajador));
    return salida;
}

// ── Imprimir lista de precios (PDF) ──────────────────────
async function imprimirLista(tipo) {
    document.querySelectorAll('.print-menu').forEach(function(m){ m.style.display = 'none'; });

    if (!window.jspdf || !window.jspdf.jsPDF) {
        toast('Librería jsPDF no cargada', 'error');
        return;
    }

    const lista = productosParaLista();
    if (!lista.length) {
        toast('No hay productos para la lista', 'warning');
        return;
    }

    const { jsPDF } = window.jspdf;

    const casillaFotos = document.getElementById('chkFotos');
    const incluirFotos = !!casillaFotos && casillaFotos.checked;
    const objetivos = incluirFotos
        ? lista.map(function(p, i){ return p.foto_print ? { idx: i, url: p.foto_print } : null; })
              .filter(Boolean)
        : [];
    const conFotos = objetivos.length > 0;
    const IMGS = new Map();

    Swal.fire({
        title: 'Generando lista…',
        html: conFotos
            ? '<div id="pdfProgreso" style="font-size:13px;color:#64748b">Cargando 0 de ' + objetivos.length + ' fotos…</div>'
            : '<div style="font-size:13px;color:#64748b">' + lista.length + ' productos</div>',
        allowOutsideClick: false,
        allowEscapeKey: false,
        didOpen: function(){ Swal.showLoading(); }
    });
    await new Promise(function(r){ setTimeout(r, 60); });

    try {
        if (conFotos) {
            const cargadas = await precargarFotos(objetivos, function(hechas, total) {
                const el = document.getElementById('pdfProgreso');
                if (el) el.textContent = 'Cargando fotos ' + hechas + ' de ' + total + '…';
            });
            objetivos.forEach(function(o, k){ IMGS.set(o.idx, cargadas[k]); });
        }

        const doc = new jsPDF('l','mm','a4');
        const empresa = '<?= addslashes($empresa_nombre) ?>';
        const rif     = '<?= addslashes($empresa_rif) ?>';
        const hoy     = new Date().toLocaleDateString('es-VE');
        const fechaArchivo = new Date().toISOString().slice(0,10);
        const tipoTexto = tipo === 'mayorista'
            ? 'LISTA DE PRECIOS MAYORISTA'
            : tipo === 'detalista'
                ? 'LISTA DE PRECIOS DETALISTA'
                : 'LISTA DE PRECIOS MAYORISTA Y DETALISTA';

        // Cabecera de la empresa (se repite en cada página).
        function dibujarCabecera() {
            doc.setFillColor(192,57,43);
            doc.rect(0,0,297,18,'F');
            doc.setTextColor(255,255,255);
            doc.setFontSize(13); doc.setFont(undefined,'bold');
            doc.text(empresa, 8, 12);
            doc.setFontSize(9); doc.setFont(undefined,'normal');
            doc.text(tipoTexto, 289, 12, {align:'right'});

            doc.setTextColor(0,0,0);
            doc.setFontSize(8);
            doc.text('RIF: ' + rif, 8, 24);
            doc.text('Precios en USD por unidad (Dólares Americanos)   -   ' + hoy +
                     '   -   ' + lista.length + ' productos', 289, 24, {align:'right'});
        }

        dibujarCabecera();

        // Columnas (la foto va justo al lado del nombre del producto)
        const cols = ['#'];
        const idx = {};
        if (conFotos) idx.foto = cols.push('Foto') - 1;
        idx.codigo = cols.push('Código') - 1;
        idx.desc   = cols.push('Descripción') - 1;
        idx.cat    = cols.push('Categoría') - 1;
        idx.bulto  = cols.push('Bulto') - 1;
        if (tipo === 'ambas') {
            cols.push('Mayorista $','Detalista $');
        } else {
            cols.push('Precio $');
        }
        idx.cant = cols.push('CANTIDADES') - 1;

        const columnStyles = {};
        columnStyles[0] = { cellWidth: 8, halign: 'center' };
        if (conFotos) columnStyles[idx.foto] = { cellWidth: 27, halign: 'center' };
        columnStyles[idx.codigo] = { cellWidth: 36, fontStyle: 'bold', fontSize: 7.2 };
        columnStyles[idx.desc]   = { cellWidth: 'auto' };
        columnStyles[idx.cat]    = { cellWidth: 24 };
        columnStyles[idx.bulto]  = { cellWidth: 14, halign: 'center' };
        for (let c = idx.bulto + 1; c < idx.cant; c++) {
            columnStyles[c] = { cellWidth: conFotos ? 24 : 26, halign: 'right' };
        }
        columnStyles[idx.cant] = { cellWidth: 22, halign: 'center' };

        const body = lista.map(function(p, i) {
            const row = [i + 1];
            if (conFotos) row.push('');
            row.push(p.codigo, p.descripcion, p.cat, p.bulto);
            if (tipo === 'ambas') {
                row.push(p.mayorista > 0 ? '$ ' + p.mayorista.toFixed(2) : '-');
                row.push(p.detalista > 0 ? '$ ' + p.detalista.toFixed(2) : '-');
            } else if (tipo === 'mayorista') {
                row.push(p.mayorista > 0 ? '$ ' + p.mayorista.toFixed(2) : '-');
            } else {
                row.push(p.detalista > 0 ? '$ ' + p.detalista.toFixed(2) : '-');
            }
            row.push('');   // CANTIDADES: espacio para anotar a mano
            return row;
        });

        const opciones = {
            head: [cols],
            body: body,
            startY: 29,
            margin: { top: 29, left: 8, right: 8, bottom: 14 },
            styles: { fontSize: 8.2, cellPadding: 2.5, valign: 'middle', overflow: 'linebreak' },
            headStyles: { fillColor: [40,40,60], textColor: 255, fontStyle: 'bold', minCellHeight: 9 },
            alternateRowStyles: { fillColor: [248,249,252] },
            columnStyles: columnStyles,
            didDrawPage: function(d) {
                if (d.pageNumber > 1) dibujarCabecera();

                doc.setFontSize(7);
                doc.setTextColor(150,150,150);
                doc.text('Cantidades en unidades  ·  Tauro - Lista generada el ' + hoy, 8, 204);
                doc.text('Pág. ' + d.pageNumber, 289, 204, {align:'right'});
                doc.setTextColor(0,0,0);
            }
        };

        // Filas altas solo cuando hay fotos (la celda de la foto define el alto).
        if (conFotos) opciones.bodyStyles = { minCellHeight: 26 };

        // Incrusta cada foto dentro de su celda, centrada y sin deformar.
        opciones.didDrawCell = function(data) {
            if (!conFotos || data.section !== 'body' || data.column.index !== idx.foto) return;

            const img = IMGS.get(data.row.index);
            if (!img) return;

            const celda = data.cell;
            try {
                if (!img.props) img.props = doc.getImageProperties(img.data);
                const props = img.props;
                const pad   = 2;
                const dispW = celda.width  - pad * 2;
                const dispH = celda.height - pad * 2;
                if (dispW <= 2 || dispH <= 2) return;

                const k = Math.min(dispW / props.width, dispH / props.height);
                const w = props.width  * k;
                const h = props.height * k;
                const x = celda.x + (celda.width  - w) / 2;
                const y = celda.y + (celda.height - h) / 2;

                // 'FAST' evita recomprimir el JPEG: se incrusta tal cual (máxima calidad).
                doc.addImage(img.data, props.fileType || 'JPEG', x, y, w, h, undefined, 'FAST');
                doc.setDrawColor(226, 232, 240);
                doc.setLineWidth(0.1);
                doc.rect(x, y, w, h);
            } catch (e) {
                console.warn('Foto omitida en la lista:', e);
            }
        };

        doc.autoTable(opciones);

        doc.save('Lista_' + tipo + (conFotos ? '_con_fotos' : '') + '_Tauro_' + fechaArchivo + '.pdf');
    } catch (err) {
        console.error(err);
        toast('No se pudo generar la lista: ' + (err && err.message ? err.message : err), 'error');
    } finally {
        Swal.close();
    }
}

// ── Lista de precios en Excel (foto, cantidades y total) ─
// Genera un .xlsx real con la foto de cada producto, una columna CANTIDADES
// para anotar lo que pide el cliente y fórmulas de MONTO/TOTAL que se
// recalculan solas al escribir las cantidades.
async function exportarListaExcel(tipo) {
    document.querySelectorAll('.print-menu').forEach(function(m){ m.style.display = 'none'; });

    if (typeof ExcelJS === 'undefined') {
        toast('No se pudo cargar el generador de Excel. Revisa la conexión.', 'error');
        return;
    }

    const lista = productosParaLista();
    if (!lista.length) { toast('No hay productos para exportar', 'warning'); return; }

    const esAmbas   = tipo === 'ambas';
    const esMayor   = tipo === 'mayorista';
    const etiqueta  = esAmbas ? 'MAYORISTA Y DETALISTA' : (esMayor ? 'MAYORISTA' : 'DETALISTA');
    const hoy       = new Date().toLocaleDateString('es-VE');
    const fechaArch = new Date().toISOString().slice(0, 10);

    const EMP = {
        nombre: <?= $jsEmpresaNombre ?>,
        rif:    <?= $jsEmpresaRif ?>,
        dir:    <?= $jsEmpresaDir ?>
    };

    // ── Columnas ────────────────────────────────────────────
    const nCols = esAmbas ? 10 : 8;
    const headers = ['Foto','Código','Descripción','Categoría','Bulto'];
    if (esAmbas) headers.push('P. Mayor $','P. Detal. $');
    else         headers.push('Precio $');
    headers.push('CANTIDADES');
    if (esAmbas) headers.push('MONTO MAYOR $','MONTO DETAL. $');
    else         headers.push('MONTO $');

    const anchos = esAmbas
        ? [9, 16, 46, 20, 7, 12, 13, 16, 16, 16]
        : [9, 16, 46, 20, 7, 13, 14, 16];

    // ── Fotos ───────────────────────────────────────────────
    const objetivos = lista
        .map(function(p, i){ return p.foto_thumb ? { idx: i, url: p.foto_thumb } : null; })
        .filter(Boolean);

    Swal.fire({
        title: 'Generando Excel…',
        html: objetivos.length
            ? '<div id="xlsProgreso" style="font-size:13px;color:#64748b">Cargando 0 de ' + objetivos.length + ' fotos…</div>'
            : '<div style="font-size:13px;color:#64748b">' + lista.length + ' productos</div>',
        allowOutsideClick: false,
        allowEscapeKey: false,
        didOpen: function(){ Swal.showLoading(); }
    });
    await new Promise(function(r){ setTimeout(r, 60); });

    try {
        const FOTOS = new Map();   // índice del producto → { data, w, h }
        if (objetivos.length) {
            const cargadas = await precargarFotos(objetivos, function(hechas, total) {
                const el = document.getElementById('xlsProgreso');
                if (el) el.textContent = 'Cargando fotos ' + hechas + ' de ' + total + '…';
            });
            for (let k = 0; k < objetivos.length; k++) {
                const f = cargadas[k];
                if (!f) continue;
                const red = await reducirImagen(f.data, 180);
                if (red) FOTOS.set(objetivos[k].idx, red);
            }
        }

        // ── Libro y hoja ────────────────────────────────────
        const wb = new ExcelJS.Workbook();
        wb.creator = EMP.nombre;
        wb.created = new Date();
        if (wb.calcProperties) wb.calcProperties.fullCalcOnLoad = true;

        const ws = wb.addWorksheet(
            'Lista ' + (esAmbas ? 'Mayorista y Detal' : (esMayor ? 'Mayorista' : 'Detalista')),
            {
                views: [{ state: 'frozen', ySplit: 7 }],
                pageSetup: {
                    paperSize: 9,
                    orientation: 'landscape',
                    fitToPage: true,
                    fitToWidth: 1,
                    fitToHeight: 0,
                    horizontalCentered: true,
                    margins: { left: 0.3, right: 0.3, top: 0.45, bottom: 0.45, header: 0.2, footer: 0.2 }
                }
            }
        );

        ws.columns = anchos.map(function(w){ return { width: w }; });

        // ── Estilos base ────────────────────────────────────
        const BRAND  = 'FFC0392B';
        const DARK   = 'FF1F2937';
        const LINEA  = 'FFD9E1EA';
        const INPUT  = 'FFFFF7CC';
        const MONEY  = '"$"#,##0.00';
        const MONEY0 = '"$"#,##0.00;-"$"#,##0.00;';
        const QTY    = '#,##0.00';   // formato Número (2 decimales)

        const borde = function() {
            return {
                top:    { style:'thin', color:{ argb: LINEA } },
                left:   { style:'thin', color:{ argb: LINEA } },
                bottom: { style:'thin', color:{ argb: LINEA } },
                right:  { style:'thin', color:{ argb: LINEA } }
            };
        };
        const fondo = function(cell, argb) {
            cell.fill = { type:'pattern', pattern:'solid', fgColor:{ argb: argb } };
        };

        // ── Encabezado ──────────────────────────────────────
        ws.mergeCells(1, 1, 1, nCols);
        const c1 = ws.getCell(1, 1);
        c1.value = EMP.nombre;
        c1.font = { size: 16, bold: true, color: { argb: BRAND } };
        c1.alignment = { vertical: 'middle' };
        ws.getRow(1).height = 24;

        ws.mergeCells(2, 1, 2, nCols);
        const c2 = ws.getCell(2, 1);
        c2.value = (EMP.rif ? 'RIF: ' + EMP.rif : '') + (EMP.dir ? '   ·   ' + EMP.dir : '');
        c2.font = { size: 9, color: { argb: 'FF64748B' } };
        c2.alignment = { vertical: 'middle' };
        ws.getRow(2).height = 14;

        ws.mergeCells(3, 1, 3, nCols);
        const c3 = ws.getCell(3, 1);
        c3.value = 'LISTA DE PRECIOS ' + etiqueta + '   ·   Precios en USD por unidad';
        c3.font = { size: 12, bold: true, color: { argb: DARK } };
        c3.alignment = { vertical: 'middle' };
        ws.getRow(3).height = 18;

        const cFecha = ws.getCell(4, 1);
        cFecha.value = 'Fecha: ' + hoy;
        cFecha.font = { size: 9, color: { argb: 'FF475569' } };
        cFecha.alignment = { vertical: 'middle' };
        ws.getRow(4).height = 16;

        ws.mergeCells(4, 4, 4, nCols);
        const cCliente = ws.getCell(4, 4);
        cCliente.value = 'Cliente: ';
        cCliente.font = { size: 9, bold: true, color: { argb: DARK } };
        cCliente.alignment = { vertical: 'middle' };
        cCliente.border = { bottom: { style:'thin', color:{ argb: 'FF94A3B8' } } };

        ws.mergeCells(5, 1, 5, nCols);
        const cAyuda = ws.getCell(5, 1);
        cAyuda.value = 'Escriba las CANTIDADES que pide el cliente (en unidades). El MONTO de cada línea y el TOTAL de abajo se calculan solos.';
        cAyuda.font = { size: 8.5, italic: true, color: { argb: 'FFB45309' } };
        cAyuda.alignment = { vertical: 'middle', wrapText: true };
        fondo(cAyuda, 'FFFFFBEB');
        ws.getRow(5).height = 26;
        ws.getRow(6).height = 6;

        // ── Cabecera de la tabla ────────────────────────────
        const filaCab = 7;
        headers.forEach(function(h, i) {
            const cell = ws.getCell(filaCab, i + 1);
            cell.value = h;
            cell.font = { size: 9, bold: true, color: { argb: 'FFFFFFFF' } };
            cell.alignment = { horizontal: 'center', vertical: 'middle', wrapText: true };
            fondo(cell, DARK);
            cell.border = borde();
        });
        ws.getRow(filaCab).height = 32;

        // ── Filas de productos ──────────────────────────────
        const fila0 = 8;

        const dinero = function(cell, valor) {
            cell.value = valor;
            cell.numFmt = MONEY;
            cell.alignment = { horizontal: 'right', vertical: 'middle' };
        };
        const cantidad = function(cell) {
            cell.numFmt = QTY;
            cell.alignment = { horizontal: 'center', vertical: 'middle' };
            cell.font = { name: 'Calibri', size: 10, bold: true, color: { argb: 'FF92400E' } };
            fondo(cell, INPUT);
        };

        // Toda la columna CANTIDADES queda en formato Número.
        const colCant = esAmbas ? 8 : 7;
        ws.getColumn(colCant).numFmt = QTY;

        lista.forEach(function(p, i) {
            const r = fila0 + i;
            const bulto = Math.max(1, parseInt(p.bulto, 10) || 1);
            const pu = Number(p.mayorista) || 0;
            const pd = Number(p.detalista) || 0;
            const base = i % 2 === 0 ? 'FFFFFFFF' : 'FFF8FAFC';

            const row = ws.getRow(r);
            row.height = FOTOS.has(i) ? 46 : 22;

            for (let c = 1; c <= nCols; c++) {
                const cell = ws.getCell(r, c);
                cell.font = { name: 'Calibri', size: 9, color: { argb: 'FF334155' } };
                cell.alignment = { vertical: 'middle' };
                fondo(cell, base);
                cell.border = borde();
            }

            ws.getCell(r, 1).alignment = { horizontal: 'center', vertical: 'middle' };

            const cCod = ws.getCell(r, 2);
            cCod.value = p.codigo;
            cCod.font = { name: 'Consolas', size: 9, bold: true, color: { argb: 'FF0F172A' } };

            const cDesc = ws.getCell(r, 3);
            cDesc.value = p.descripcion;
            cDesc.alignment = { vertical: 'middle', wrapText: true };

            const cCat = ws.getCell(r, 4);
            cCat.value = p.cat;
            cCat.font = { size: 8, color: { argb: 'FF64748B' } };

            const cBulto = ws.getCell(r, 5);
            cBulto.value = bulto;
            cBulto.alignment = { horizontal: 'center', vertical: 'middle' };

            if (esAmbas) {
                dinero(ws.getCell(r, 6), pu);
                dinero(ws.getCell(r, 7), pd);

                cantidad(ws.getCell(r, 8));

                const cM = ws.getCell(r, 9);
                cM.value = { formula: 'H' + r + '*F' + r };
                cM.numFmt = MONEY0;
                cM.font = { name: 'Calibri', size: 9, bold: true, color: { argb: 'FF0F172A' } };
                cM.alignment = { horizontal: 'right', vertical: 'middle' };

                const cD = ws.getCell(r, 10);
                cD.value = { formula: 'H' + r + '*G' + r };
                cD.numFmt = MONEY0;
                cD.font = { name: 'Calibri', size: 9, bold: true, color: { argb: 'FF0F172A' } };
                cD.alignment = { horizontal: 'right', vertical: 'middle' };
            } else {
                const precio = esMayor ? pu : pd;
                dinero(ws.getCell(r, 6), precio);

                cantidad(ws.getCell(r, 7));

                const cM = ws.getCell(r, 8);
                cM.value = { formula: 'G' + r + '*F' + r };
                cM.numFmt = MONEY0;
                cM.font = { name: 'Calibri', size: 9, bold: true, color: { argb: 'FF0F172A' } };
                cM.alignment = { horizontal: 'right', vertical: 'middle' };
            }

            // Foto dentro de la celda A
            const foto = FOTOS.get(i);
            if (foto) {
                const imgId = wb.addImage({ base64: foto.data.split(',')[1], extension: 'jpeg' });
                const k = Math.min(52 / foto.w, 52 / foto.h);
                const w = Math.max(1, Math.round(foto.w * k));
                const h = Math.max(1, Math.round(foto.h * k));
                ws.addImage(imgId, {
                    tl: {
                        col: 0.07 + (52 - w) / 2 / 64,
                        row: (r - 1) + 0.05 + (52 - h) / 2 / 61
                    },
                    ext: { width: w, height: h },
                    editAs: 'oneCell'
                });
            }
        });

        // En CANTIDADES solo se aceptan números.
        ws.dataValidations.add(
            ws.getColumn(colCant).letter + fila0 + ':' +
            ws.getColumn(colCant).letter + (fila0 + lista.length - 1),
            {
                type: 'decimal',
                operator: 'greaterThanOrEqual',
                formulae: [0],
                allowBlank: true,
                showErrorMessage: true,
                errorStyle: 'stop',
                errorTitle: 'Cantidad',
                error: 'Escribe solo números (sin letras).'
            }
        );

        // ── Totales ─────────────────────────────────────────
        const filaTot = fila0 + lista.length;
        const ultima  = filaTot - 1;
        const labelEnd = esAmbas ? 7 : 6;

        ws.mergeCells(filaTot, 1, filaTot, labelEnd);
        const cTot = ws.getCell(filaTot, 1);
        cTot.value = 'TOTALES';
        cTot.font = { size: 10, bold: true, color: { argb: DARK } };
        cTot.alignment = { horizontal: 'right', vertical: 'middle' };
        fondo(cTot, 'FFE2E8F0');
        cTot.border = borde();
        ws.getRow(filaTot).height = 22;

        const total = function(cell, col, fmt) {
            cell.value = { formula: 'SUM(' + col + fila0 + ':' + col + ultima + ')' };
            cell.numFmt = fmt;
            cell.font = { size: 10, bold: true, color: { argb: DARK } };
            cell.alignment = { horizontal: 'right', vertical: 'middle' };
            fondo(cell, 'FFE2E8F0');
            cell.border = borde();
        };

        if (esAmbas) {
            total(ws.getCell(filaTot, 8), 'H', QTY);
            total(ws.getCell(filaTot, 9), 'I', MONEY);
            total(ws.getCell(filaTot, 10), 'J', MONEY);
        } else {
            total(ws.getCell(filaTot, 7), 'G', QTY);
            total(ws.getCell(filaTot, 8), 'H', MONEY);
        }

        // ── Total a pagar (barra resaltada) ─────────────────
        const filaRes = filaTot + 2;

        const barraTotal = function(r, label, valueCol, referencia) {
            for (let c = 1; c <= nCols; c++) {
                const cell = ws.getCell(r, c);
                fondo(cell, BRAND);
                cell.border = borde();
            }
            const lbl = ws.getCell(r, 1);
            lbl.value = label;
            lbl.font = { size: 12, bold: true, color: { argb: 'FFFFFFFF' } };
            lbl.alignment = { horizontal: 'left', vertical: 'middle' };

            const val = ws.getCell(r, valueCol);
            val.value = { formula: referencia };
            val.numFmt = MONEY;
            val.font = { size: 12, bold: true, color: { argb: 'FFFFFFFF' } };
            val.alignment = { horizontal: 'right', vertical: 'middle' };

            ws.getRow(r).height = 28;
        };

        if (esAmbas) {
            barraTotal(filaRes,     'TOTAL MAYORISTA $:', 9, 'I' + filaTot);
            barraTotal(filaRes + 1, 'TOTAL DETALISTA $:', 10, 'J' + filaTot);
        } else {
            barraTotal(filaRes, 'TOTAL A PAGAR $:', 8, 'H' + filaTot);
        }

        // ── Notas al pie ────────────────────────────────────
        const filaNota = filaRes + (esAmbas ? 2 : 1) + 1;

        ws.mergeCells(filaNota, 1, filaNota, nCols);
        const n1 = ws.getCell(filaNota, 1);
        n1.value = 'CANTIDADES en unidades; MONTO = cantidades × precio unitario. Precios en USD.';
        n1.font = { size: 8, italic: true, color: { argb: 'FF64748B' } };

        ws.mergeCells(filaNota + 1, 1, filaNota + 1, nCols);
        const n2 = ws.getCell(filaNota + 1, 1);
        n2.value = 'Documento informativo: precios y disponibilidad sujetos a confirmación.';
        n2.font = { size: 8, italic: true, color: { argb: 'FF64748B' } };

        // ── Impresión ───────────────────────────────────────
        ws.pageSetup.printArea = 'A1:' + ws.getColumn(nCols).letter + (filaNota + 1);
        ws.pageSetup.printTitlesRow = '1:7';
        ws.headerFooter = { oddFooter: '&L' + EMP.nombre + '&R Página &P de &N' };

        // ── Descargar ───────────────────────────────────────
        const buffer = await wb.xlsx.writeBuffer();
        const blob = new Blob([buffer], { type: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' });
        const url = URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = url;
        a.download = 'Lista_Precios_' + tipo + '_Tauro_' + fechaArch + '.xlsx';
        document.body.appendChild(a);
        a.click();
        setTimeout(function(){ URL.revokeObjectURL(url); a.remove(); }, 1500);
    } catch (err) {
        console.error(err);
        toast('No se pudo generar el Excel: ' + (err && err.message ? err.message : err), 'error');
    } finally {
        Swal.close();
    }
}

// ── Foto del producto: preview, quitar y ver ─────────────
function previewFoto(input) {
    const file = input.files && input.files[0];
    if (!file) return;

    if (!/^image\/(jpeg|png)$/.test(file.type)) {
        toast('Usa una imagen JPG o PNG', 'error');
        input.value = '';
        return;
    }
    if (file.size > 12 * 1024 * 1024) {
        toast('La imagen supera los 12 MB', 'error');
        input.value = '';
        return;
    }

    const img   = document.getElementById('fotoPreviewImg');
    const vacia = document.getElementById('fotoPreviewVacia');
    const quitar = document.getElementById('quitarImagen');

    if (img.dataset.url) URL.revokeObjectURL(img.dataset.url);
    const url = URL.createObjectURL(file);
    img.dataset.url = url;
    img.src = url;
    img.style.display = 'block';
    vacia.style.display = 'none';

    if (quitar) quitar.checked = false;
    document.getElementById('fotoBtnTxt').textContent = 'Cambiar foto';
}

function toggleQuitarFoto(cb) {
    const img    = document.getElementById('fotoPreviewImg');
    const vacia  = document.getElementById('fotoPreviewVacia');
    const input  = document.getElementById('fotoInput');
    const btnTxt = document.getElementById('fotoBtnTxt');

    if (cb.checked) {
        if (input) input.value = '';
        if (img.dataset.url) { URL.revokeObjectURL(img.dataset.url); delete img.dataset.url; }
        img.style.display = 'none';
        img.removeAttribute('src');
        vacia.style.display = 'flex';
        btnTxt.textContent = 'Subir foto';
    } else if (FOTO_ORIGINAL) {
        img.src = FOTO_ORIGINAL;
        img.style.display = 'block';
        vacia.style.display = 'none';
        btnTxt.textContent = 'Cambiar foto';
    }
}

function verFoto(url, nombre) {
    if (!url) return;
    document.getElementById('modalFotoTitulo').textContent = nombre || 'Foto del producto';
    document.getElementById('modalFotoImg').src = url;
    document.getElementById('modalFotoLink').href = url;
    openModal('modalFoto');
}

// Abrir la foto desde la miniatura de la tabla (sin onclick inline)
document.addEventListener('click', function(e) {
    const btn = e.target.closest('.foto-mini[data-foto]');
    if (!btn) return;
    e.preventDefault();
    verFoto(btn.getAttribute('data-foto'), btn.getAttribute('data-nombre'));
});

// Arrastrar y soltar sobre el recuadro de la foto
document.addEventListener('DOMContentLoaded', function() {
    const zona  = document.getElementById('fotoPreview');
    const input = document.getElementById('fotoInput');
    if (!zona || !input) return;

    ['dragenter','dragover'].forEach(function(ev) {
        zona.addEventListener(ev, function(e) { e.preventDefault(); zona.classList.add('drag'); });
    });
    ['dragleave','drop'].forEach(function(ev) {
        zona.addEventListener(ev, function(e) { e.preventDefault(); zona.classList.remove('drag'); });
    });
    zona.addEventListener('drop', function(e) {
        const file = e.dataTransfer && e.dataTransfer.files && e.dataTransfer.files[0];
        if (!file) return;
        try {
            const dt = new DataTransfer();
            dt.items.add(file);
            input.files = dt.files;
            previewFoto(input);
        } catch (err) {
            toast('No se pudo leer la imagen arrastrada', 'error');
        }
    });
});

function guardandoProducto(form) {
    const btn = form.querySelector('button[type="submit"]');
    if (btn) {
        btn.disabled = true;
        btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Guardando…';
    }
    return true;
}


// ── Exportar inventario a Excel ──────────────────────────
function exportarExcelInventario() {
    if (typeof XLSX === 'undefined') { toast('Librería XLSX no cargada', 'error'); return; }

    const wb = XLSX.utils.book_new();
    const empNombre = <?= $jsEmpresaNombre ?>;
    const empRif    = <?= $jsEmpresaRif ?>;

    const cols = ['Código','Descripción','Categoría','Uds. por Bulto','Unidad',
                  'Stock Actual','Stock Mín.','Precio Mayorista $','Precio Detalista $',
                  'P. Bulto Mayor $','P. Bulto Detail $','Valor Inv. $'];

    const header_data = [
        [empNombre],
        [empRif ? 'RIF: ' + empRif : ''],
        ['Inventario — <?= $verAgotados ? "incluye agotados" : "solo con existencia" ?> — ' + new Date().toLocaleDateString('es-VE')],
        [],
        cols
    ];

    const filaIni = header_data.length;   // primera fila de datos (0-based)

    PRODS_INV.forEach(function(p) {
        const bulto = Math.max(1, parseInt(p.bulto, 10) || 1);
        const pu    = Number(p.mayorista) || 0;
        const pd    = Number(p.detalista) || 0;
        const stock = Number(p.stock) || 0;
        header_data.push([
            p.codigo,
            p.descripcion,
            p.cat,
            bulto,
            p.unidad,
            stock,
            Number(p.stock_min) || 0,
            pu,
            pd,
            Math.round(bulto * pu * 100) / 100,
            Math.round(bulto * pd * 100) / 100,
            Math.round(stock * pu * 100) / 100
        ]);
    });

    const filaFin = header_data.length - 1;
    const filaTot = header_data.length;
    header_data.push([]);

    const ws = XLSX.utils.aoa_to_sheet(header_data);
    const celda = (r, c) => XLSX.utils.encode_cell({ r: r, c: c });

    // Precios con formato de moneda
    for (let r = filaIni; r <= filaFin; r++) {
        [7, 8, 9, 10, 11].forEach(function(c) {
            const a = celda(r, c);
            if (ws[a]) ws[a].z = '$#,##0.00';
        });
    }

    // Fila de totales
    ws[celda(filaTot, 0)]  = { t:'s', v:'TOTALES' };
    ws[celda(filaTot, 5)]  = { t:'n', f:'SUM(F' + (filaIni + 1) + ':F' + (filaFin + 1) + ')', z:'#,##0.##' };
    ws[celda(filaTot, 11)] = { t:'n', f:'SUM(L' + (filaIni + 1) + ':L' + (filaFin + 1) + ')', z:'$#,##0.00' };

    ws['!ref'] = XLSX.utils.encode_range({ s:{r:0,c:0}, e:{r:filaTot,c:11} });
    ws['!cols'] = [18,50,22,10,8,12,12,18,18,18,18,16].map(function(w){ return {wch: w}; });
    ws['!merges'] = [
        {s:{r:0,c:0},e:{r:0,c:11}},
        {s:{r:1,c:0},e:{r:1,c:11}},
        {s:{r:2,c:0},e:{r:2,c:11}},
        {s:{r:filaTot,c:0},e:{r:filaTot,c:4}}
    ];
    XLSX.utils.book_append_sheet(wb, ws, 'Inventario');
    XLSX.writeFile(wb, 'Inventario_Tauro_' + new Date().toISOString().slice(0,10) + '.xlsx');
}

// ── Importar Excel / CSV ─────────────────────────────────
function importarExcel(file) {
    if (!file) return;

    const reader = new FileReader();
    reader.onload = function(e) {
        let productos = [];
        const ext = file.name.split('.').pop().toLowerCase();

        if (ext === 'csv') {
            const lines = e.target.result.split('\n').filter(function(l){ return l.trim(); });
            if (!lines.length) { toast('El archivo está vacío', 'error'); return; }
            const headers = lines[0].split(/[;,]/).map(function(h){
                return h.trim().replace(/[\xEF\xBB\xBF]/g, '');
            });
            for (let i = 1; i < lines.length; i++) {
                const vals = lines[i].split(/[;,]/);
                if (!vals[0] || !vals[0].trim()) continue;
                const obj = {};
                headers.forEach(function(h, j) {
                    obj[h] = (vals[j] || '').trim().replace(/^"|"$/g, '');
                });
                productos.push(obj);
            }
        } else {
            const wb   = XLSX.read(e.target.result, {type: 'binary'});
            const ws   = wb.Sheets[wb.SheetNames[0]];
            const data = XLSX.utils.sheet_to_json(ws, {header: 1, defval: ''});
            if (data.length < 2) { toast('El archivo está vacío', 'error'); return; }
            const headers = data[0].map(function(h){ return (h || '').toString().trim(); });
            for (let i = 1; i < data.length; i++) {
                if (!data[i][0]) continue;
                const obj = {};
                headers.forEach(function(h, j) {
                    obj[h] = (data[i][j] || '').toString().trim();
                });
                productos.push(obj);
            }
        }

        if (!productos.length) {
            toast('No se encontraron filas con datos', 'warning');
            return;
        }

        const preview = document.getElementById('importarPreview');
        if (preview) {
            preview.innerHTML = '<div class="alert alert-info"><i class="fa-solid fa-spinner fa-spin"></i> Importando ' + productos.length + ' productos...</div>';
            preview.style.display = 'block';
        }

        fetch('api/importar.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({productos: productos})
        })
        .then(function(r){ return r.json(); })
        .then(function(res) {
            if (res.ok) {
                let html = '<div class="alert alert-success"><i class="fa-solid fa-check"></i> ' + res.msg + '</div>';
                if (res.errores && res.errores.length) {
                    html += '<ul style="font-size:12px;color:var(--danger)">' +
                            res.errores.map(function(err){ return '<li>' + err + '</li>'; }).join('') +
                            '</ul>';
                }
                if (preview) preview.innerHTML = html;
                toast('Importación exitosa', 'success');
                setTimeout(function(){ location.reload(); }, 1500);
            } else {
                if (preview) preview.innerHTML = '<div class="alert alert-danger">' + res.msg + '</div>';
                toast(res.msg || 'Error al importar', 'error');
            }
        })
        .catch(function() {
            toast('Error al importar. Revisa el archivo.', 'error');
        });
    };

    if (file.name.toLowerCase().endsWith('.csv')) {
        reader.readAsText(file, 'UTF-8');
    } else {
        reader.readAsBinaryString(file);
    }
}

// ── Eliminar producto ────────────────────────────────────
function confirmarEliminar(id, nombre) {
    Swal.fire({
        title: '¿Eliminar producto?',
        text: nombre,
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#c0392b',
        confirmButtonText: 'Sí, eliminar',
        cancelButtonText: 'Cancelar'
    }).then(function(r) {
        if (r.isConfirmed) {
            document.getElementById('eliminarId').value = id;
            document.getElementById('formEliminar').submit();
        }
    });
}

// ── Autoabrir modal si venimos en modo edición ───────────
<?php if ($editProducto): ?>
document.addEventListener('DOMContentLoaded', function() {
    if (typeof openModal === 'function') openModal('modalProducto');
});
<?php endif; ?>

// ── DataTable (si está disponible) ───────────────────────
document.addEventListener('DOMContentLoaded', function() {
    if (typeof jQuery !== 'undefined' && jQuery.fn && jQuery.fn.DataTable) {
        jQuery('#tablaInventario').DataTable({
            language: (typeof dtLang !== 'undefined') ? dtLang : {},
            pageLength: 50,
            order: [],
            columnDefs: [{ orderable: false, targets: [0, 12] }]
        });
    }
});
</script>

<?php
include 'includes/footer.php';
?>