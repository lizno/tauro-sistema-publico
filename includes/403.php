<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Acceso Denegado</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <style>
        body{font-family:'Inter',sans-serif;background:#f0f2f5;display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0}
        .box{background:white;border-radius:16px;padding:48px;text-align:center;max-width:420px;box-shadow:0 8px 32px rgba(0,0,0,.08)}
        .icon{font-size:64px;color:#c0392b;margin-bottom:16px}
        h1{font-size:26px;font-weight:800;color:#c0392b;margin:0 0 8px}
        p{color:#718096;font-size:14px;margin:0 0 24px}
        a{background:#c0392b;color:white;text-decoration:none;padding:12px 24px;border-radius:8px;font-weight:700;font-size:14px}
    </style>
</head>
<body>
<div class="box">
    <div class="icon"><i class="fa-solid fa-shield-halved"></i></div>
    <h1>Acceso Denegado</h1>
    <p>No tienes permiso para acceder a esta sección.<br>Contacta al administrador del sistema.</p>
    <a href="<?= defined('APP_URL') ? APP_URL : '' ?>/index.php"><i class="fa-solid fa-house"></i> Ir al Inicio</a>
</div>
</body>
</html>
