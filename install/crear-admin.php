<?php
/**
 * Crea el primer usuario administrador.
 * Uso:  php install/crear-admin.php
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Este script solo se ejecuta por consola.\n");
}

require __DIR__ . '/../config/config.php';
require __DIR__ . '/../functions/db.php';

function preguntar(string $texto, bool $obligatorio = true): string
{
    do {
        fwrite(STDOUT, $texto . ': ');
        $valor = trim((string) fgets(STDIN));
        if ($obligatorio && $valor === '') {
            fwrite(STDOUT, "  (obligatorio)\n");
        }
    } while ($obligatorio && $valor === '');
    return $valor;
}

fwrite(STDOUT, "TAURO - crear usuario administrador\n\n");

$usuario  = preguntar('Usuario');
$nombre   = preguntar('Nombre');
$apellido = preguntar('Apellido', false);
$email    = preguntar('Correo', false);
$clave    = preguntar('Contraseña (mínimo 8 caracteres)');

$largo = function_exists('mb_strlen') ? mb_strlen($clave) : strlen($clave);
if ($largo < 8) {
    fwrite(STDERR, "\nLa contraseña debe tener al menos 8 caracteres.\n");
    exit(1);
}

$rol = dbQueryOne("SELECT id FROM roles WHERE nombre = 'administrador' LIMIT 1");
if (!$rol) {
    fwrite(STDERR, "\nNo existe el rol 'administrador'. Importa primero install/seed.sql.\n");
    exit(1);
}

if (dbQueryOne("SELECT id FROM usuarios WHERE username = ?", [$usuario])) {
    fwrite(STDERR, "\nEse usuario ya existe.\n");
    exit(1);
}

dbExecute(
    "INSERT INTO usuarios (nombre, apellido, username, password, email, rol_id, departamento_id, activo)
     VALUES (?, ?, ?, ?, ?, ?, 1, 1)",
    [
        $nombre,
        $apellido !== '' ? $apellido : null,
        $usuario,
        password_hash($clave, PASSWORD_DEFAULT),
        $email !== '' ? $email : null,
        $rol['id'],
    ]
);

fwrite(STDOUT, "\nUsuario creado. Ya puedes entrar en login.php\n");
