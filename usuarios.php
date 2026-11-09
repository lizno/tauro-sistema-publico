<?php
require_once 'config/config.php';
require_once 'functions/db.php';
require_once 'functions/helpers.php';
require_once 'functions/auth.php';

requireLogin();
if (!isAdmin()) { include ROOT_PATH.'/includes/403.php'; exit; }
$pageTitle = 'Usuarios del Sistema';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $pa = $_POST['action'] ?? '';

    if ($pa === 'crear') {
        $username = trim($_POST['username'] ?? '');
        $nombre   = trim($_POST['nombre'] ?? '');
        $apellido = trim($_POST['apellido'] ?? '');
        $email    = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';
        $rolId    = cleanInt($_POST['rol_id'] ?? 0);
        $deptoId  = cleanInt($_POST['departamento_id'] ?? 1);

        if (!preg_match('/^[a-zA-Z0-9_.\-]{3,50}$/', $username)) {
            $_SESSION['flash'] = ['type'=>'error','msg'=>'Usuario inválido.'];
        } elseif (strlen($password) < 8) {
            $_SESSION['flash'] = ['type'=>'error','msg'=>'Contraseña debe tener al menos 8 caracteres.'];
        } elseif (!preg_match('/[A-Z]/', $password) || !preg_match('/[0-9]/', $password)) {
            $_SESSION['flash'] = ['type'=>'error','msg'=>'Contraseña debe tener al menos una mayúscula y un número.'];
        } else {
            try {
                $hash  = password_hash($password, PASSWORD_BCRYPT, ['cost'=>12]);
                $foto  = subirArchivo('foto_perfil', 'perfiles');
                dbExecute(
                    "INSERT INTO usuarios (nombre,apellido,username,password,email,foto_perfil,rol_id,departamento_id)
                     VALUES (?,?,?,?,?,?,?,?)",
                    [$nombre,$apellido,$username,$hash,$email,$foto,$rolId,$deptoId]
                );
                auditLog('INSERT','usuarios',(int)dbLastId(),"Usuario creado: $username (Rol: $rolId)");
                $_SESSION['flash'] = ['type'=>'success','msg'=>"Usuario '$username' creado."];
            } catch (PDOException $e) {
                $msg = str_contains($e->getMessage(),'Duplicate') ? "El usuario '$username' ya existe." : 'Error al crear.';
                $_SESSION['flash'] = ['type'=>'error','msg'=>$msg];
            }
        }
        header('Location: usuarios.php'); exit;
    }

    if ($pa === 'cambiar_password') {
        $uid = cleanInt($_POST['id'] ?? 0);
        $pwd = $_POST['new_password'] ?? '';
        if (strlen($pwd) < 8) { $_SESSION['flash']=['type'=>'error','msg'=>'Mínimo 8 caracteres.']; }
        else {
            $hash = password_hash($pwd, PASSWORD_BCRYPT, ['cost'=>12]);
            dbExecute("UPDATE usuarios SET password=?,failed_attempts=0,locked_until=NULL WHERE id=?",[$hash,$uid]);
            auditLog('UPDATE','usuarios',$uid,"Contraseña cambiada");
            $_SESSION['flash'] = ['type'=>'success','msg'=>'Contraseña actualizada.'];
        }
        header('Location: usuarios.php'); exit;
    }

    if ($pa === 'editar_foto') {
        $uid  = cleanInt($_POST['id'] ?? 0);
        $foto = subirArchivo('foto_perfil', 'perfiles');
        if ($foto) {
            $ant = dbQueryOne("SELECT foto_perfil FROM usuarios WHERE id=?",[$uid]);
            if ($ant['foto_perfil'] && file_exists(ROOT_PATH.'/assets/uploads/'.$ant['foto_perfil'])) {
                @unlink(ROOT_PATH.'/assets/uploads/'.$ant['foto_perfil']);
            }
            dbExecute("UPDATE usuarios SET foto_perfil=? WHERE id=?",[$foto,$uid]);
            if ($uid === $_SESSION['user_id']) {
                // Actualizar propia foto en sesión no es necesario, se lee de BD
            }
            $_SESSION['flash'] = ['type'=>'success','msg'=>'Foto actualizada.'];
        } else {
            $_SESSION['flash'] = ['type'=>'error','msg'=>'Error al subir la foto. (JPG/PNG, máx. 3MB)'];
        }
        header('Location: usuarios.php'); exit;
    }

    if ($pa === 'toggle_activo') {
        $uid = cleanInt($_POST['id'] ?? 0);
        if ($uid === $_SESSION['user_id']) { $_SESSION['flash']=['type'=>'error','msg'=>'No puedes desactivarte.']; header('Location: usuarios.php'); exit; }
        $u   = dbQueryOne("SELECT activo FROM usuarios WHERE id=?",[$uid]);
        $nuevo = $u['activo'] ? 0 : 1;
        dbExecute("UPDATE usuarios SET activo=? WHERE id=?",[$nuevo,$uid]);
        auditLog('UPDATE','usuarios',$uid,'Usuario '.($nuevo?'activado':'desactivado'));
        $_SESSION['flash'] = ['type'=>'success','msg'=>'Estado actualizado.'];
        header('Location: usuarios.php'); exit;
    }

    if ($pa === 'editar_rol') {
        $uid     = cleanInt($_POST['id'] ?? 0);
        $rolId   = cleanInt($_POST['rol_id'] ?? 0);
        $deptoId = cleanInt($_POST['departamento_id'] ?? 1);
        if ($uid === $_SESSION['user_id']) { $_SESSION['flash']=['type'=>'error','msg'=>'No puedes cambiar tu propio rol.']; header('Location: usuarios.php'); exit; }
        dbExecute("UPDATE usuarios SET rol_id=?,departamento_id=? WHERE id=?",[$rolId,$deptoId,$uid]);
        auditLog('UPDATE','usuarios',$uid,"Rol cambiado a ID: $rolId, Depto: $deptoId");
        $_SESSION['flash'] = ['type'=>'success','msg'=>'Rol actualizado.'];
        header('Location: usuarios.php'); exit;
    }

    if ($pa === 'desbloquear') {
        $uid = cleanInt($_POST['id'] ?? 0);
        dbExecute("UPDATE usuarios SET failed_attempts=0,locked_until=NULL WHERE id=?",[$uid]);
        auditLog('UPDATE','usuarios',$uid,"Cuenta desbloqueada manualmente");
        $_SESSION['flash'] = ['type'=>'success','msg'=>'Cuenta desbloqueada.'];
        header('Location: usuarios.php'); exit;
    }
}

$usuarios      = dbQuery("SELECT u.*,r.nombre rol_nombre,d.nombre depto_nombre FROM usuarios u JOIN roles r ON u.rol_id=r.id LEFT JOIN departamentos d ON u.departamento_id=d.id ORDER BY u.activo DESC,u.nombre");
$roles         = dbQuery("SELECT * FROM roles ORDER BY id");
$departamentos = dbQuery("SELECT * FROM departamentos ORDER BY nombre");

include 'includes/header.php';
?>

<div class="alert alert-info mb-4">
    <i class="fa-solid fa-shield-halved"></i>
    Solo el Administrador puede gestionar usuarios. Todos los cambios quedan registrados en la auditoría del sistema.
</div>

<div class="d-flex justify-between align-center mb-4">
    <div></div>
    <button onclick="openModal('modalNuevoUsuario')" class="btn btn-primary">
        <i class="fa-solid fa-plus"></i> Nuevo Usuario
    </button>
</div>

<div class="card">
    <div class="card-header">
        <i class="fa-solid fa-lock text-primary"></i>
        <h5>Usuarios del Sistema — <?= count($usuarios) ?></h5>
    </div>
    <div class="card-body p-0">
        <table class="table" id="tablaUsuarios">
            <thead>
                <tr>
                    <th>Foto</th>
                    <th>Usuario</th>
                    <th>Nombre Completo</th>
                    <th>Email</th>
                    <th>Rol</th>
                    <th>Departamento</th>
                    <th>Último Acceso</th>
                    <th>Estado</th>
                    <th>Acciones</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($usuarios as $u): $esYo = ($u['id'] === $_SESSION['user_id']); ?>
            <tr style="<?= !$u['activo'] ? 'opacity:.55' : '' ?>">
                <td>
                    <div style="width:36px;height:36px;border-radius:50%;background:var(--primary);overflow:hidden;display:flex;align-items:center;justify-content:center;color:white;font-weight:700;font-size:13px;flex-shrink:0">
                        <?php if ($u['foto_perfil'] && file_exists(ROOT_PATH.'/assets/uploads/'.$u['foto_perfil'])): ?>
                            <img src="<?= UPLOADS_URL.'/'.clean($u['foto_perfil']) ?>" style="width:100%;height:100%;object-fit:cover" alt="">
                        <?php else: ?>
                            <?= strtoupper(substr($u['nombre'],0,1).substr($u['apellido']??'',0,1)) ?>
                        <?php endif; ?>
                    </div>
                </td>
                <td>
                    <strong class="font-mono"><?= clean($u['username']) ?></strong>
                    <?php if ($esYo): ?> <span class="badge badge-primary" style="font-size:10px">Tú</span><?php endif; ?>
                    <?php if ($u['locked_until'] && strtotime($u['locked_until'])>time()): ?>
                    <br><span class="badge badge-danger" style="font-size:10px"><i class="fa-solid fa-lock"></i> Bloqueado</span>
                    <?php endif; ?>
                </td>
                <td><?= clean($u['nombre'].' '.$u['apellido']) ?></td>
                <td class="fs-12 text-muted"><?= clean($u['email']??'—') ?></td>
                <td><span class="badge badge-primary fs-11"><?= clean($u['rol_nombre']) ?></span></td>
                <td class="fs-12"><?= clean($u['depto_nombre']??'—') ?></td>
                <td class="fs-12 text-muted"><?= $u['ultimo_acceso'] ? date('d/m/Y H:i',strtotime($u['ultimo_acceso'])) : 'Nunca' ?></td>
                <td><?= $u['activo'] ? '<span class="badge badge-success">Activo</span>' : '<span class="badge badge-secondary">Inactivo</span>' ?></td>
                <td>
                    <div class="d-flex gap-1">
                        <!-- Subir foto -->
                        <button onclick="openFoto(<?= $u['id'] ?>,'<?= addslashes(clean($u['nombre'])) ?>')"
                            class="btn btn-sm btn-light btn-icon" title="Cambiar foto">
                            <i class="fa-solid fa-camera"></i>
                        </button>
                        <?php if (!$esYo): ?>
                        <!-- Cambiar rol -->
                        <button onclick="openRol(<?= $u['id'] ?>,<?= $u['rol_id'] ?>,<?= $u['departamento_id']??1 ?>)"
                            class="btn btn-sm btn-warning btn-icon" title="Cambiar rol">
                            <i class="fa-solid fa-user-gear"></i>
                        </button>
                        <!-- Cambiar contraseña -->
                        <button onclick="openPass(<?= $u['id'] ?>,'<?= addslashes(clean($u['username'])) ?>')"
                            class="btn btn-sm btn-info btn-icon" title="Cambiar contraseña">
                            <i class="fa-solid fa-key"></i>
                        </button>
                        <!-- Desbloquear -->
                        <?php if ($u['locked_until'] && strtotime($u['locked_until'])>time()): ?>
                        <form method="POST" style="display:inline">
                            <input type="hidden" name="<?= CSRF_TOKEN_NAME ?>" value="<?= getCsrfToken() ?>">
                            <input type="hidden" name="action" value="desbloquear">
                            <input type="hidden" name="id" value="<?= $u['id'] ?>">
                            <button type="submit" class="btn btn-sm btn-success btn-icon" title="Desbloquear">
                                <i class="fa-solid fa-lock-open"></i>
                            </button>
                        </form>
                        <?php endif; ?>
                        <!-- Activar/Desactivar -->
                        <form method="POST" style="display:inline"
                              onsubmit="return confirm('¿Cambiar estado de este usuario?')">
                            <input type="hidden" name="<?= CSRF_TOKEN_NAME ?>" value="<?= getCsrfToken() ?>">
                            <input type="hidden" name="action" value="toggle_activo">
                            <input type="hidden" name="id" value="<?= $u['id'] ?>">
                            <button type="submit" class="btn btn-sm <?= $u['activo']?'btn-danger':'btn-success' ?> btn-icon"
                                title="<?= $u['activo']?'Desactivar':'Activar' ?>">
                                <i class="fa-solid <?= $u['activo']?'fa-ban':'fa-check' ?>"></i>
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

<!-- MODAL: Nuevo Usuario -->
<div class="modal-overlay" id="modalNuevoUsuario">
    <div class="modal-box">
        <div class="modal-header">
            <h4><i class="fa-solid fa-user-plus"></i> Nuevo Usuario</h4>
            <button class="btn-close" onclick="closeModal('modalNuevoUsuario')"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <form method="POST" enctype="multipart/form-data">
            <div class="modal-body">
                <input type="hidden" name="<?= CSRF_TOKEN_NAME ?>" value="<?= getCsrfToken() ?>">
                <input type="hidden" name="action" value="crear">

                <!-- Foto de perfil -->
                <div style="text-align:center;margin-bottom:16px">
                    <div id="fotoNuevoPreview" style="width:72px;height:72px;border-radius:50%;background:var(--primary);margin:0 auto 8px;overflow:hidden;display:flex;align-items:center;justify-content:center;color:white;font-size:24px;font-weight:700">
                        <i class="fa-solid fa-user"></i>
                    </div>
                    <label style="cursor:pointer;font-size:12px;color:var(--primary)">
                        <i class="fa-solid fa-camera"></i> Subir foto de perfil
                        <input type="file" name="foto_perfil" accept=".jpg,.jpeg,.png" style="display:none"
                               onchange="previewFoto(this,'fotoNuevoPreview')">
                    </label>
                </div>

                <div class="row">
                    <div class="col-6"><div class="form-group"><label class="form-label">Nombre *</label>
                        <input type="text" name="nombre" class="form-control" required maxlength="100"></div></div>
                    <div class="col-6"><div class="form-group"><label class="form-label">Apellido</label>
                        <input type="text" name="apellido" class="form-control" maxlength="100"></div></div>
                </div>
                <div class="row">
                    <div class="col-6"><div class="form-group"><label class="form-label">Usuario (login) *</label>
                        <input type="text" name="username" class="form-control font-mono" required
                               maxlength="50" pattern="[a-zA-Z0-9_.\-]+" placeholder="sin espacios"></div></div>
                    <div class="col-6"><div class="form-group"><label class="form-label">Email</label>
                        <input type="email" name="email" class="form-control" maxlength="100"></div></div>
                </div>
                <div class="form-group">
                    <label class="form-label">Contraseña * <small class="text-muted">(mín. 8 chars, 1 mayúscula, 1 número)</small></label>
                    <input type="password" name="password" id="newPassInput" class="form-control" required minlength="8">
                    <div style="height:3px;border-radius:2px;background:#e2e8f0;margin-top:5px;overflow:hidden">
                        <div id="passStrengthBar" style="height:100%;width:0;transition:all .3s"></div>
                    </div>
                </div>
                <div class="row">
                    <div class="col-6"><div class="form-group"><label class="form-label">Rol *</label>
                        <select name="rol_id" class="form-select" required>
                            <?php foreach ($roles as $r): ?>
                            <option value="<?= $r['id'] ?>"><?= clean($r['nombre']) ?></option>
                            <?php endforeach; ?>
                        </select></div></div>
                    <div class="col-6"><div class="form-group"><label class="form-label">Departamento</label>
                        <select name="departamento_id" class="form-select">
                            <?php foreach ($departamentos as $d): ?>
                            <option value="<?= $d['id'] ?>"><?= clean($d['nombre']) ?></option>
                            <?php endforeach; ?>
                        </select></div></div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" onclick="closeModal('modalNuevoUsuario')" class="btn btn-light">Cancelar</button>
                <button type="submit" class="btn btn-primary"><i class="fa-solid fa-floppy-disk"></i> Crear Usuario</button>
            </div>
        </form>
    </div>
</div>

<!-- MODAL: Cambiar Foto -->
<div class="modal-overlay" id="modalFoto">
    <div class="modal-box" style="max-width:380px">
        <div class="modal-header">
            <h4><i class="fa-solid fa-camera"></i> Cambiar Foto de Perfil</h4>
            <button class="btn-close" onclick="closeModal('modalFoto')"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <form method="POST" enctype="multipart/form-data">
            <div class="modal-body" style="text-align:center">
                <input type="hidden" name="<?= CSRF_TOKEN_NAME ?>" value="<?= getCsrfToken() ?>">
                <input type="hidden" name="action" value="editar_foto">
                <input type="hidden" name="id" id="fotoUserId">
                <div id="fotoModalPreview" style="width:80px;height:80px;border-radius:50%;background:var(--primary);margin:0 auto 12px;overflow:hidden;display:flex;align-items:center;justify-content:center;color:white;font-size:28px;font-weight:700">
                    <i class="fa-solid fa-user"></i>
                </div>
                <p id="fotoUsuarioNombre" class="fw-bold mb-3"></p>
                <input type="file" name="foto_perfil" class="form-control" accept=".jpg,.jpeg,.png"
                       onchange="previewFoto(this,'fotoModalPreview')" required>
                <small class="text-muted">JPG o PNG, máximo 3MB</small>
            </div>
            <div class="modal-footer">
                <button type="button" onclick="closeModal('modalFoto')" class="btn btn-light">Cancelar</button>
                <button type="submit" class="btn btn-primary"><i class="fa-solid fa-upload"></i> Subir Foto</button>
            </div>
        </form>
    </div>
</div>

<!-- MODAL: Cambiar Contraseña -->
<div class="modal-overlay" id="modalPass">
    <div class="modal-box" style="max-width:380px">
        <div class="modal-header">
            <h4><i class="fa-solid fa-key"></i> Cambiar Contraseña</h4>
            <button class="btn-close" onclick="closeModal('modalPass')"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <form method="POST">
            <div class="modal-body">
                <input type="hidden" name="<?= CSRF_TOKEN_NAME ?>" value="<?= getCsrfToken() ?>">
                <input type="hidden" name="action" value="cambiar_password">
                <input type="hidden" name="id" id="passUserId">
                <p>Usuario: <strong id="passUsernameLabel"></strong></p>
                <div class="form-group mt-3">
                    <label class="form-label">Nueva contraseña *</label>
                    <input type="password" name="new_password" class="form-control" required minlength="8">
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" onclick="closeModal('modalPass')" class="btn btn-light">Cancelar</button>
                <button type="submit" class="btn btn-primary"><i class="fa-solid fa-key"></i> Cambiar</button>
            </div>
        </form>
    </div>
</div>

<!-- MODAL: Cambiar Rol -->
<div class="modal-overlay" id="modalRol">
    <div class="modal-box" style="max-width:380px">
        <div class="modal-header">
            <h4><i class="fa-solid fa-user-gear"></i> Cambiar Rol</h4>
            <button class="btn-close" onclick="closeModal('modalRol')"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <form method="POST">
            <div class="modal-body">
                <input type="hidden" name="<?= CSRF_TOKEN_NAME ?>" value="<?= getCsrfToken() ?>">
                <input type="hidden" name="action" value="editar_rol">
                <input type="hidden" name="id" id="rolUserId">
                <div class="form-group"><label class="form-label">Rol</label>
                    <select name="rol_id" id="rolSelect" class="form-select">
                        <?php foreach ($roles as $r): ?>
                        <option value="<?= $r['id'] ?>"><?= clean($r['nombre']) ?> — <?= clean($r['descripcion']??'') ?></option>
                        <?php endforeach; ?>
                    </select></div>
                <div class="form-group"><label class="form-label">Departamento</label>
                    <select name="departamento_id" id="deptoSelect" class="form-select">
                        <?php foreach ($departamentos as $d): ?>
                        <option value="<?= $d['id'] ?>"><?= clean($d['nombre']) ?></option>
                        <?php endforeach; ?>
                    </select></div>
            </div>
            <div class="modal-footer">
                <button type="button" onclick="closeModal('modalRol')" class="btn btn-light">Cancelar</button>
                <button type="submit" class="btn btn-primary"><i class="fa-solid fa-floppy-disk"></i> Guardar</button>
            </div>
        </form>
    </div>
</div>

<?php
$extraScript = "
<script>
function openFoto(id, nombre) {
    document.getElementById('fotoUserId').value = id;
    document.getElementById('fotoUsuarioNombre').textContent = nombre;
    openModal('modalFoto');
}
function openPass(id, username) {
    document.getElementById('passUserId').value = id;
    document.getElementById('passUsernameLabel').textContent = username;
    openModal('modalPass');
}
function openRol(id, rolId, deptoId) {
    document.getElementById('rolUserId').value = id;
    document.getElementById('rolSelect').value = rolId;
    document.getElementById('deptoSelect').value = deptoId;
    openModal('modalRol');
}
function previewFoto(input, previewId) {
    if (!input.files[0]) return;
    const reader = new FileReader();
    reader.onload = e => {
        const div = document.getElementById(previewId);
        div.innerHTML = '<img src=\"'+e.target.result+'\" style=\"width:100%;height:100%;object-fit:cover\">';
    };
    reader.readAsDataURL(input.files[0]);
}
document.getElementById('newPassInput')?.addEventListener('input', function() {
    const p = this.value;
    let s = 0;
    if (p.length>=8) s++; if (/[A-Z]/.test(p)) s++; if (/[0-9]/.test(p)) s++; if (/[^a-zA-Z0-9]/.test(p)) s++;
    const colors=['#e74c3c','#e67e22','#f1c40f','#27ae60'];
    const bar = document.getElementById('passStrengthBar');
    bar.style.width = (s*25)+'%';
    bar.style.background = colors[s-1]||'#e2e8f0';
});

\$('#tablaUsuarios').DataTable({ language:dtLang, pageLength:20, order:[] });
</script>";
include 'includes/footer.php';
?>
