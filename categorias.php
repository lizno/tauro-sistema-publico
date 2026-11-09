<?php
require_once 'config/config.php';
require_once 'functions/db.php';
require_once 'functions/helpers.php';
require_once 'functions/auth.php';

requireLogin();
requirePermission('inventario','ver');
$pageTitle = 'Categorías de Productos';

if ($_SERVER['REQUEST_METHOD']==='POST') {
    verifyCsrf();
    $pa = $_POST['action'] ?? '';

    if ($pa==='crear') {
        requirePermission('inventario','crear');
        $nombre = trim($_POST['nombre'] ?? '');
        if (!$nombre) { $_SESSION['flash']=['type'=>'error','msg'=>'El nombre es requerido.']; header('Location: categorias.php'); exit; }
        dbExecute("INSERT INTO categorias (nombre,departamento_id,activo) VALUES (?,1,1)",[$nombre]);
        auditLog('INSERT','categorias',(int)dbLastId(),"Categoría creada: $nombre");
        $_SESSION['flash']=['type'=>'success','msg'=>"Categoría \"$nombre\" creada."];
        header('Location: categorias.php'); exit;
    }
    if ($pa==='editar') {
        requirePermission('inventario','editar');
        $id     = cleanInt($_POST['id'] ?? 0);
        $nombre = trim($_POST['nombre'] ?? '');
        if (!$nombre || !$id) { $_SESSION['flash']=['type'=>'error','msg'=>'Datos incompletos.']; header('Location: categorias.php'); exit; }
        dbExecute("UPDATE categorias SET nombre=? WHERE id=?",[$nombre,$id]);
        auditLog('UPDATE','categorias',$id,"Categoría editada: $nombre");
        $_SESSION['flash']=['type'=>'success','msg'=>'Categoría actualizada.'];
        header('Location: categorias.php'); exit;
    }
    if ($pa==='eliminar') {
        requirePermission('inventario','eliminar');
        $id  = cleanInt($_POST['id'] ?? 0);
        $uso = dbQueryOne("SELECT COUNT(*) c FROM productos WHERE categoria_id=? AND activo=1",[$id]);
        if ($uso && $uso['c'] > 0) {
            $_SESSION['flash']=['type'=>'error','msg'=>"No se puede eliminar: tiene {$uso['c']} producto(s) asignado(s)."];
        } else {
            dbExecute("UPDATE categorias SET activo=0 WHERE id=?",[$id]);
            auditLog('UPDATE','categorias',$id,'Categoría desactivada');
            $_SESSION['flash']=['type'=>'success','msg'=>'Categoría eliminada.'];
        }
        header('Location: categorias.php'); exit;
    }
}

$categorias = dbQuery(
    "SELECT c.*, COUNT(p.id) total_productos
     FROM categorias c
     LEFT JOIN productos p ON p.categoria_id=c.id AND p.activo=1
     WHERE c.activo=1 AND c.departamento_id=1
     GROUP BY c.id ORDER BY c.nombre"
);
$editCat = null;
if (($_GET['edit'] ?? '') && is_numeric($_GET['edit']))
    $editCat = dbQueryOne("SELECT * FROM categorias WHERE id=?",[(int)$_GET['edit']]);

include 'includes/header.php';
?>
<div class="d-flex justify-between align-center mb-4">
    <div>
        <a href="inventario.php" class="btn btn-light btn-sm">
            <i class="fa-solid fa-arrow-left"></i> Volver a Inventario
        </a>
    </div>
    <?php if (hasPermission('inventario','crear')): ?>
    <button onclick="openModal('modalCat')" class="btn btn-primary">
        <i class="fa-solid fa-plus"></i> Nueva Categoría
    </button>
    <?php endif; ?>
</div>

<div class="row">
    <div class="col-6">
        <div class="card">
            <div class="card-header">
                <i class="fa-solid fa-tags text-primary"></i>
                <h5>Categorías — <?= count($categorias) ?></h5>
            </div>
            <div class="card-body p-0">
            <table class="table" id="tablaCats">
                <thead><tr>
                    <th>Nombre</th>
                    <th class="text-center">Productos</th>
                    <th>Acciones</th>
                </tr></thead>
                <tbody>
                <?php foreach ($categorias as $cat): ?>
                <tr>
                    <td><strong><?= clean($cat['nombre']) ?></strong></td>
                    <td class="text-center">
                        <span class="badge badge-<?= $cat['total_productos']>0?'primary':'secondary' ?>">
                            <?= $cat['total_productos'] ?>
                        </span>
                    </td>
                    <td>
                        <div class="d-flex gap-1">
                            <?php if (hasPermission('inventario','editar')): ?>
                            <a href="categorias.php?edit=<?= $cat['id'] ?>" class="btn btn-sm btn-warning btn-icon" title="Editar">
                                <i class="fa-solid fa-pen"></i>
                            </a>
                            <?php endif; ?>
                            <?php if (hasPermission('inventario','eliminar') && $cat['total_productos']==0): ?>
                            <form method="POST" style="display:inline" onsubmit="return confirm('¿Eliminar categoría <?= addslashes(clean($cat['nombre'])) ?>?')">
                                <input type="hidden" name="<?= CSRF_TOKEN_NAME ?>" value="<?= getCsrfToken() ?>">
                                <input type="hidden" name="action" value="eliminar">
                                <input type="hidden" name="id" value="<?= $cat['id'] ?>">
                                <button type="submit" class="btn btn-sm btn-danger btn-icon" title="Eliminar">
                                    <i class="fa-solid fa-trash"></i>
                                </button>
                            </form>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            </div>
        </div>
    </div>

    <div class="col-6">
        <div class="card">
            <div class="card-header">
                <i class="fa-solid fa-<?= $editCat ? 'pen' : 'plus' ?> text-primary"></i>
                <h5><?= $editCat ? 'Editar Categoría' : 'Nueva Categoría' ?></h5>
            </div>
            <div class="card-body">
                <form method="POST">
                    <input type="hidden" name="<?= CSRF_TOKEN_NAME ?>" value="<?= getCsrfToken() ?>">
                    <input type="hidden" name="action" value="<?= $editCat ? 'editar' : 'crear' ?>">
                    <?php if ($editCat): ?><input type="hidden" name="id" value="<?= $editCat['id'] ?>"><?php endif; ?>
                    <div class="form-group">
                        <label class="form-label">Nombre de la categoría *</label>
                        <input type="text" name="nombre" class="form-control"
                            value="<?= clean($editCat['nombre'] ?? '') ?>"
                            placeholder="Ej: Cauchos, Rodamientos, Cadenas..."
                            required maxlength="100" autofocus>
                    </div>
                    <div class="d-flex gap-2">
                        <?php if ($editCat): ?>
                        <a href="categorias.php" class="btn btn-light">Cancelar</a>
                        <?php endif; ?>
                        <button type="submit" class="btn btn-primary">
                            <i class="fa-solid fa-floppy-disk"></i>
                            <?= $editCat ? 'Guardar Cambios' : 'Crear Categoría' ?>
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- MODAL rápido -->
<div class="modal-overlay" id="modalCat">
<div class="modal-box" style="max-width:400px">
    <div class="modal-header">
        <h4><i class="fa-solid fa-tags"></i> Nueva Categoría</h4>
        <button class="btn-close" onclick="closeModal('modalCat')"><i class="fa-solid fa-xmark"></i></button>
    </div>
    <form method="POST">
    <div class="modal-body">
        <input type="hidden" name="<?= CSRF_TOKEN_NAME ?>" value="<?= getCsrfToken() ?>">
        <input type="hidden" name="action" value="crear">
        <div class="form-group">
            <label class="form-label">Nombre</label>
            <input type="text" name="nombre" class="form-control" required autofocus
                placeholder="Ej: Amortiguadores, Piñones...">
        </div>
    </div>
    <div class="modal-footer">
        <button type="button" onclick="closeModal('modalCat')" class="btn btn-light">Cancelar</button>
        <button type="submit" class="btn btn-primary"><i class="fa-solid fa-plus"></i> Crear</button>
    </div>
    </form>
</div>
</div>

<?php
$extraScript = "
<script>
\$('#tablaCats').DataTable({ language:dtLang, pageLength:20, order:[[0,'asc']], columnDefs:[{targets:2,orderable:false}] });
</script>";
include 'includes/footer.php';
?>
