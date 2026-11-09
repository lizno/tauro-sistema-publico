<?php
require_once 'config/config.php';
require_once 'functions/db.php';
require_once 'functions/helpers.php';
require_once 'functions/auth.php';

if (isset($_SESSION['user_id'])) { header('Location: '.APP_URL.'/index.php'); exit; }

$error = $success = '';
if (isset($_GET['timeout'])) $error   = 'Tu sesión expiró por inactividad.';
if (isset($_GET['logout']))  $success = 'Sesión cerrada correctamente.';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST[CSRF_TOKEN_NAME] ?? '';
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $token)) {
        $error = 'Error de seguridad. Recarga la página.';
    } else {
        $username = $_POST['username'] ?? '';
        $password = $_POST['password'] ?? '';
        if (!preg_match('/^[a-zA-Z0-9_.\-@]{1,50}$/', $username)) {
            $error = 'Usuario inválido.';
        } else {
            $result = loginUser($username, $password);
            if ($result['success']) { header('Location: '.APP_URL.'/index.php'); exit; }
            else $error = $result['message'];
        }
    }
}
$csrfToken = getCsrfToken();
$logoPath  = '';
$empresa   = APP_NAME;
try { $logoPath = getConfig('logo_path',''); $empresa = getConfig('empresa_nombre', APP_NAME); } catch (Exception $e) {}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Acceso — <?= clean($empresa) ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="<?= APP_URL ?>/assets/css/style.css">
    <style>
        .pass-wrap{position:relative}.pass-wrap input{padding-right:42px}
        .toggle-pass{position:absolute;right:12px;top:50%;transform:translateY(-50%);background:none;border:none;cursor:pointer;color:var(--text-muted);font-size:14px}
    </style>
</head>
<body>
<div class="login-page">
    <div class="login-card">
        <div class="login-logo">
            <div class="logo-wrap">
                <?php if ($logoPath && file_exists(ROOT_PATH.'/assets/uploads/'.$logoPath)): ?>
                <img src="<?= UPLOADS_URL.'/'.clean($logoPath) ?>" alt="Logo">
                <?php else: ?>
                <i class="fa-solid fa-gears"></i>
                <?php endif; ?>
            </div>
            <h1><?= clean($empresa) ?></h1>
            <p>Sistema de Gestión v<?= APP_VERSION ?></p>
        </div>
        <?php if ($error):   ?><div class="alert alert-danger" ><i class="fa-solid fa-triangle-exclamation"></i> <?= clean($error)   ?></div><?php endif; ?>
        <?php if ($success): ?><div class="alert alert-success"><i class="fa-solid fa-check-circle"></i> <?= clean($success) ?></div><?php endif; ?>
        <form method="POST" autocomplete="off" id="loginForm">
            <input type="hidden" name="<?= CSRF_TOKEN_NAME ?>" value="<?= $csrfToken ?>">
            <div class="form-group">
                <label class="form-label"><i class="fa-solid fa-user"></i> Usuario</label>
                <input type="text" name="username" class="form-control" placeholder="Ingresa tu usuario"
                       value="<?= clean($_POST['username'] ?? '') ?>" maxlength="50" autocomplete="username"
                       required pattern="[a-zA-Z0-9_.\-@]+">
            </div>
            <div class="form-group">
                <label class="form-label"><i class="fa-solid fa-lock"></i> Contraseña</label>
                <div class="pass-wrap">
                    <input type="password" name="password" id="passInput" class="form-control"
                           placeholder="Ingresa tu contraseña" maxlength="200" autocomplete="current-password" required>
                    <button type="button" class="toggle-pass" onclick="togglePass()">
                        <i class="fa-solid fa-eye" id="eyeIcon"></i>
                    </button>
                </div>
            </div>
            <button type="submit" class="btn btn-primary w-100 btn-lg" id="btnLogin" style="margin-top:8px">
                <i class="fa-solid fa-right-to-bracket"></i> Iniciar Sesión
            </button>
        </form>
        <p style="text-align:center;margin-top:18px;font-size:11px;color:var(--text-muted)">
            <i class="fa-solid fa-shield-halved"></i> Acceso restringido — todos los accesos son registrados.
        </p>
    </div>
</div>
<script>
function togglePass(){const i=document.getElementById('passInput'),e=document.getElementById('eyeIcon');i.type=i.type==='password'?'text':'password';e.className=i.type==='text'?'fa-solid fa-eye-slash':'fa-solid fa-eye';}
document.getElementById('loginForm').addEventListener('submit',function(){const b=document.getElementById('btnLogin');b.disabled=true;b.innerHTML='<i class="fa-solid fa-spinner fa-spin"></i> Verificando...';setTimeout(()=>{b.disabled=false;b.innerHTML='<i class="fa-solid fa-right-to-bracket"></i> Iniciar Sesión';},5000);});
document.querySelector('input[name="username"]').focus();
</script>
</body>
</html>
