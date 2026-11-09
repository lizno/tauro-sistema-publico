<?php
/**
 * Funciones de autenticación y seguridad
 * Anti SQL Injection, CSRF, Brute Force, XSS
 *
 * NOTA: clean(), cleanInt(), cleanFloat() están en helpers.php
 * Asegúrate de cargar helpers.php ANTES que auth.php
 */

// ── Verificar sesión activa ──────────────────────────────────
function requireLogin(): void {
    if (!isset($_SESSION['user_id'])) {
        header('Location: ' . APP_URL . '/login.php');
        exit;
    }
    if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity']) > SESSION_TIMEOUT) {
        session_unset();
        session_destroy();
        header('Location: ' . APP_URL . '/login.php?timeout=1');
        exit;
    }
    $_SESSION['last_activity'] = time();
}

// ── Permisos ─────────────────────────────────────────────────
function hasPermission(string $modulo, string $accion = 'ver'): bool {
    if (!isset($_SESSION['permisos'])) return false;
    $permisos = $_SESSION['permisos'];
    if (!is_array($permisos)) {
        $permisos = json_decode($permisos, true) ?? [];
    }
    return isset($permisos[$modulo]) && in_array($accion, $permisos[$modulo]);
}

function requirePermission(string $modulo, string $accion = 'ver'): void {
    requireLogin();
    if (!hasPermission($modulo, $accion)) {
        http_response_code(403);
        include ROOT_PATH . '/includes/403.php';
        exit;
    }
}

function isAdmin(): bool {
    return ($_SESSION['rol_id'] ?? 0) == 1;
}

function isSupervisor(): bool {
    return in_array($_SESSION['rol_id'] ?? 0, [1, 2]);
}

// ── Login ────────────────────────────────────────────────────
function loginUser(string $username, string $password): array {
    $username = trim($username);

    if (empty($username) || empty($password)) {
        return ['success' => false, 'message' => 'Ingresa usuario y contraseña.'];
    }

    if (strlen($username) > 50 || strlen($password) > 200) {
        return ['success' => false, 'message' => 'Datos inválidos.'];
    }

    $user = dbQueryOne(
        "SELECT u.*, r.nombre as rol_nombre, r.permisos
         FROM usuarios u
         INNER JOIN roles r ON u.rol_id = r.id
         WHERE u.username = ? AND u.activo = 1",
        [$username]
    );

    if (!$user) {
        return ['success' => false, 'message' => 'Usuario o contraseña incorrectos.'];
    }

    if ($user['locked_until'] && strtotime($user['locked_until']) > time()) {
        $minutos = ceil((strtotime($user['locked_until']) - time()) / 60);
        return ['success' => false, 'message' => "Cuenta bloqueada. Intenta en {$minutos} minuto(s)."];
    }

    if (!password_verify($password, $user['password'])) {
        $attempts = $user['failed_attempts'] + 1;
        $locked   = null;
        if ($attempts >= MAX_LOGIN_ATTEMPTS) {
            $locked = date('Y-m-d H:i:s', time() + LOCKOUT_TIME);
        }
        dbExecute(
            "UPDATE usuarios SET failed_attempts = ?, locked_until = ? WHERE id = ?",
            [$attempts, $locked, $user['id']]
        );
        $restantes = MAX_LOGIN_ATTEMPTS - $attempts;
        if ($restantes > 0) {
            return ['success' => false, 'message' => "Contraseña incorrecta. Te quedan {$restantes} intento(s)."];
        }
        return ['success' => false, 'message' => 'Cuenta bloqueada por ' . (LOCKOUT_TIME / 60) . ' minutos.'];
    }

    dbExecute(
        "UPDATE usuarios SET failed_attempts = 0, locked_until = NULL, ultimo_acceso = NOW() WHERE id = ?",
        [$user['id']]
    );

    session_regenerate_id(true);

    $_SESSION['user_id']       = $user['id'];
    $_SESSION['username']      = $user['username'];
    $_SESSION['nombre']        = $user['nombre'] . ' ' . $user['apellido'];
    $_SESSION['rol_id']        = $user['rol_id'];
    $_SESSION['rol_nombre']    = $user['rol_nombre'];
    $_SESSION['depto_id']      = $user['departamento_id'];
    $_SESSION['permisos']      = json_decode($user['permisos'], true) ?? [];
    $_SESSION['last_activity'] = time();
    $_SESSION['csrf_token']    = bin2hex(random_bytes(32));

    auditLog('LOGIN', 'usuarios', $user['id'], 'Inicio de sesión exitoso');

    return ['success' => true, 'user' => $user];
}

// ── CSRF ─────────────────────────────────────────────────────
function getCsrfToken(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function verifyCsrf(): void {
    $token = $_POST[CSRF_TOKEN_NAME] ?? ($_GET[CSRF_TOKEN_NAME] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''));
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $token)) {
        // Si es petición AJAX/API devolver JSON, si no redirigir con mensaje
        $isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) || 
                  (($_SERVER['HTTP_ACCEPT'] ?? '') === 'application/json');
        if ($isAjax) {
            http_response_code(403);
            die(json_encode(['error' => 'Token CSRF inválido. Recarga la página.']));
        }
        $_SESSION['flash'] = ['type' => 'error', 'msg' => 'Token inválido. Por favor recarga la página e intenta de nuevo.'];
        $ref = $_SERVER['HTTP_REFERER'] ?? (APP_URL . '/index.php');
        header('Location: ' . $ref); exit;
    }
}

// ── Logout ───────────────────────────────────────────────────
function logoutUser(): void {
    auditLog('LOGOUT', 'usuarios', $_SESSION['user_id'] ?? 0, 'Cierre de sesión');
    session_unset();
    session_destroy();
    header('Location: ' . APP_URL . '/login.php?logout=1');
    exit;
}
