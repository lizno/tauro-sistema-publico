<?php
/**
 * Conexión PDO segura a MySQL
 * Usa prepared statements - protegido contra SQL Injection
 */

function getDB(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET;
        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,  // Prepared statements reales
        ];
        try {
            $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
        } catch (PDOException $e) {
            // No mostrar detalles de conexión en producción
            error_log('DB Error: ' . $e->getMessage());
            die('<div style="font-family:sans-serif;padding:30px;color:red;">
                <h2>Error de conexión a la base de datos</h2>
                <p>Verifica que MySQL esté activo en XAMPP y que la base de datos <strong>' . DB_NAME . '</strong> exista.</p>
                <p>Importa el archivo <code>install/tauro_db.sql</code> en phpMyAdmin.</p>
            </div>');
        }
    }
    return $pdo;
}

/**
 * Ejecuta una consulta y retorna todos los resultados
 */
function dbQuery(string $sql, array $params = []): array {
    $stmt = getDB()->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

/**
 * Ejecuta una consulta y retorna una sola fila
 */
function dbQueryOne(string $sql, array $params = []): ?array {
    $stmt = getDB()->prepare($sql);
    $stmt->execute($params);
    $result = $stmt->fetch();
    return $result ?: null;
}

/**
 * Ejecuta INSERT/UPDATE/DELETE y retorna filas afectadas
 */
function dbExecute(string $sql, array $params = []): int {
    $stmt = getDB()->prepare($sql);
    $stmt->execute($params);
    return $stmt->rowCount();
}

/**
 * Retorna el último ID insertado
 */
function dbLastId(): string {
    return getDB()->lastInsertId();
}
