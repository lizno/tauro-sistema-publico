<?php
// ─────────────────────────────────────────────────────────────
// Copia este archivo como config/config.php y ajusta los datos.
// config/config.php NO se sube al repositorio.
// ─────────────────────────────────────────────────────────────

// ── Base de datos ────────────────────────────────────────────
define('DB_HOST',    'localhost');
define('DB_NAME',    'taurosystem');
define('DB_USER',    'tu_usuario');
define('DB_PASS',    'tu_contraseña');
define('DB_CHARSET', 'utf8mb4');

// ── Aplicación ───────────────────────────────────────────────
define('APP_NAME',    'TAURO Import Motor\'s C.A.');
define('APP_VERSION', '2.0.0');
// Sin barra final. En local suele ser http://localhost/tauro
define('APP_URL', 'http://localhost/tauro');

// ── Seguridad ────────────────────────────────────────────────
define('SESSION_TIMEOUT',    1800);   // 30 minutos
define('MAX_LOGIN_ATTEMPTS', 5);      // Intentos antes de bloqueo
define('LOCKOUT_TIME',       900);    // 15 minutos bloqueado
define('CSRF_TOKEN_NAME',    'csrf_token');

// ── Rutas ────────────────────────────────────────────────────
define('ROOT_PATH',    dirname(__DIR__));
define('UPLOADS_PATH', ROOT_PATH . '/assets/uploads');
define('UPLOADS_URL',  APP_URL . '/assets/uploads');

// ── Zona horaria ─────────────────────────────────────────────
date_default_timezone_set('America/Caracas');

// ── Errores (en producción: display_errors en 0) ─────────────
ini_set('display_errors', 1);
error_reporting(E_ALL);

// ── Sesión segura ─────────────────────────────────────────────
if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => false,  // true cuando el sitio use HTTPS
        'httponly' => true,
        'samesite' => 'Strict',
    ]);
    session_start();
}

// NOTA: getConfig() y setConfig() están en functions/helpers.php
// NO declarar aquí para evitar "Cannot redeclare" errors
