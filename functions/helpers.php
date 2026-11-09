<?php
// ── Auditoría ────────────────────────────────────────────────
function auditLog(string $accion, string $modulo, int $registroId = 0, string $descripcion = '', array $datosAnt = [], array $datosNuevos = []): void {
    try {
        dbExecute(
            "INSERT INTO auditoria (usuario_id,username,accion,modulo,registro_id,descripcion,datos_anteriores,datos_nuevos,ip,user_agent)
             VALUES (?,?,?,?,?,?,?,?,?,?)",
            [
                $_SESSION['user_id'] ?? null,
                $_SESSION['username'] ?? 'sistema',
                $accion, $modulo, $registroId, $descripcion,
                $datosAnt    ? json_encode($datosAnt,    JSON_UNESCAPED_UNICODE) : null,
                $datosNuevos ? json_encode($datosNuevos, JSON_UNESCAPED_UNICODE) : null,
                $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0',
                $_SERVER['HTTP_USER_AGENT'] ?? '',
            ]
        );
    } catch (Exception $e) { error_log('Audit: ' . $e->getMessage()); }
}

// ── Moneda ───────────────────────────────────────────────────
function formatUSD(float $v): string { return '$ ' . number_format($v, 2, '.', ','); }
function formatBS(float $v):  string { return 'Bs. ' . number_format($v, 2, ',', '.'); }
function formatPrecio(float $v): string { return number_format($v, 2, '.', ','); }
function usdToBS(float $usd, float $tasa): float { return round($usd * $tasa, 2); }

// ── Tasa ─────────────────────────────────────────────────────
function getTasaActual(): ?array {
    return dbQueryOne("SELECT * FROM tasas_cambio ORDER BY fecha DESC, id DESC LIMIT 1");
}

// ── Numeración ────────────────────────────────────────────────
/**
 * Genera el siguiente número para un tipo de documento.
 *
 * IMPORTANTE: el correlativo se calcula SIEMPRE desde el número más alto
 * existente en `ventas` para ese prefijo, NO por `id DESC`. Esto evita
 * colisiones cuando se convierten despachos (que cambian de tipo_doc y
 * numero en el mismo registro, sin insertar fila nueva) ni cuando el
 * correlativo se "reinicia" por falta de registros previos del tipo.
 *
 * @param string $tipo  'cotizacion' | 'despacho' | 'venta'
 * @param bool   $lock  true si se llama dentro de una transacción (usa FOR UPDATE)
 */
function generarNumeroDoc(string $tipo, bool $lock = false): string {
    $prefijos = ['cotizacion'=>'DSP','despacho'=>'DSP','venta'=>'FAC'];
    try { $prefijo = getConfig('prefijo_'.$tipo, $prefijos[$tipo] ?? 'DOC'); }
    catch (Exception $e) { $prefijo = $prefijos[$tipo] ?? 'DOC'; }

    // Buscamos el número MÁS ALTO (no el último insertado) cuyo prefijo coincida.
    // CAST(SUBSTRING(...)) compara numéricamente, no alfabéticamente (FAC00010 > FAC00009).
    $sql = "SELECT numero FROM ventas
            WHERE tipo_doc = ?
              AND numero LIKE ?
            ORDER BY CAST(SUBSTRING(numero, ?) AS UNSIGNED) DESC
            LIMIT 1";
    if ($lock) $sql .= " FOR UPDATE";

    $params = [$tipo, $prefijo.'%', strlen($prefijo)+1];
    $ultimo = dbQueryOne($sql, $params);

    $num = 1;
    if ($ultimo && preg_match('/(\d+)$/', $ultimo['numero'], $m)) {
        $num = (int)$m[1] + 1;
    }

    return $prefijo . str_pad((string)$num, 5, '0', STR_PAD_LEFT);
}

function generarNumeroCompra(): string {
    $ultimo = dbQueryOne(
        "SELECT numero FROM compras
         WHERE numero LIKE 'COM%'
         ORDER BY CAST(SUBSTRING(numero, 4) AS UNSIGNED) DESC
         LIMIT 1"
    );
    $num = ($ultimo && preg_match('/(\d+)$/', $ultimo['numero']??'', $m)) ? (int)$m[1]+1 : 1;
    return 'COM' . str_pad((string)$num, 5, '0', STR_PAD_LEFT);
}

function generarNumeroDevolucion(): string {
    $ultimo = dbQueryOne(
        "SELECT numero FROM devoluciones
         WHERE numero LIKE 'DEV%'
         ORDER BY CAST(SUBSTRING(numero, 4) AS UNSIGNED) DESC
         LIMIT 1"
    );
    $num = ($ultimo && preg_match('/(\d+)$/', $ultimo['numero']??'', $m)) ? (int)$m[1]+1 : 1;
    return 'DEV' . str_pad((string)$num, 5, '0', STR_PAD_LEFT);
}

// ── Badges ────────────────────────────────────────────────────
function badgeEstado(string $estado): string {
    $map = ['pendiente'=>'warning','parcial'=>'info','pagado'=>'success','anulado'=>'danger',
            'recibido'=>'success','aprobada'=>'success','rechazada'=>'danger'];
    $color = $map[$estado] ?? 'secondary';
    return "<span class=\"badge badge-{$color}\">" . ucfirst($estado) . "</span>";
}

function badgeTipo(string $tipo): string {
    $color = $tipo === 'mayorista' ? 'primary' : 'warning';
    return "<span class=\"badge badge-{$color}\">" . ucfirst($tipo) . "</span>";
}

function badgeDoc(string $tipo): string {
    $map   = ['cotizacion'=>'info','despacho'=>'info','venta'=>'success'];
    $label = ['cotizacion'=>'Despacho','despacho'=>'Despacho','venta'=>'Factura'];
    $color = $map[$tipo] ?? 'secondary';
    return "<span class=\"badge badge-{$color}\">" . ($label[$tipo] ?? ucfirst($tipo)) . "</span>";
}

// ── Sanitización ──────────────────────────────────────────────
function clean(string $value): string { return htmlspecialchars(trim($value), ENT_QUOTES, 'UTF-8'); }
function cleanInt($v): int   { return (int)  filter_var($v, FILTER_SANITIZE_NUMBER_INT); }
function cleanFloat($v): float { return (float)filter_var($v, FILTER_SANITIZE_NUMBER_FLOAT, FILTER_FLAG_ALLOW_FRACTION); }

// ── JSON API ─────────────────────────────────────────────────
function jsonResponse(array $data, int $code = 200): never {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

// ── Config ────────────────────────────────────────────────────
function getConfig(string $clave, string $default = ''): string {
    static $cache = [];
    if (isset($cache[$clave])) return $cache[$clave];
    try {
        $row = dbQueryOne("SELECT valor FROM configuracion WHERE clave = ?", [$clave]);
        $cache[$clave] = $row['valor'] ?? $default;
    } catch (Exception $e) { $cache[$clave] = $default; }
    return $cache[$clave];
}

function setConfig(string $clave, string $valor): void {
    dbExecute(
        "INSERT INTO configuracion (clave,valor,usuario_id) VALUES (?,?,?)
         ON DUPLICATE KEY UPDATE valor=?, usuario_id=?",
        [$clave,$valor,$_SESSION['user_id']??1,$valor,$_SESSION['user_id']??1]
    );
}

// ── Stock ─────────────────────────────────────────────────────
function actualizarStock(int $productoId, float $cantidad, string $op = 'restar'): void {
    $s = $op === 'sumar' ? '+' : '-';
    dbExecute("UPDATE productos SET stock_actual = stock_actual {$s} ? WHERE id = ?", [$cantidad, $productoId]);
}

function actualizarSaldoCliente(int $clienteId): void {
    dbExecute(
        "UPDATE clientes SET saldo_pendiente = (
            SELECT COALESCE(SUM(total_usd - monto_pagado_usd),0)
            FROM ventas
            WHERE cliente_id=? AND tipo_doc='venta' AND estado NOT IN('anulado','pagado')
         ) WHERE id=?",
        [$clienteId, $clienteId]
    );
}

// ── Upload ────────────────────────────────────────────────────
function subirArchivo(string $inputName, string $subDir, array $tipos = ['jpg','jpeg','png','webp']): ?string {
    if (!isset($_FILES[$inputName]) || $_FILES[$inputName]['error'] !== UPLOAD_ERR_OK) return null;
    $ext = strtolower(pathinfo($_FILES[$inputName]['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, $tipos)) return null;
    if ($_FILES[$inputName]['size'] > 3*1024*1024) return null;
    $dir = UPLOADS_PATH . '/' . $subDir;
    if (!is_dir($dir)) mkdir($dir, 0755, true);
    $nombre = uniqid('', true) . '.' . $ext;
    if (move_uploaded_file($_FILES[$inputName]['tmp_name'], $dir.'/'.$nombre)) return $subDir.'/'.$nombre;
    return null;
}

// ── CONVERTIR DOCUMENTO ───────────────────────────────────────
// Flujo:  Despacho → Factura (descuenta stock)
//
// NI despacho NI cotizacion (registros viejos) afectan el inventario.
// SOLO la conversión a Factura descuenta el stock.
//
// Toda la operación va dentro de una transacción. El número nuevo se
// calcula con FOR UPDATE sobre `ventas` para que dos conversiones
// simultáneas no generen el mismo correlativo.
function convertirDocumento(int $ventaId, string $nuevoTipo, int $usuarioId): array {
    $tiposOrigen  = ['cotizacion','despacho'];
    $tiposDestino = ['despacho','venta'];

    // getDB() es singleton en db.php: garantiza misma conexión para FOR UPDATE
    $pdo = getDB();

    $pdo->beginTransaction();
    try {
        // 1) Bloquear el registro para evitar doble conversión concurrente
        $venta = dbQueryOne("SELECT * FROM ventas WHERE id=? FOR UPDATE", [$ventaId]);
        if (!$venta) {
            $pdo->rollBack();
            return ['ok'=>false,'msg'=>'Documento no encontrado.'];
        }

        if (!in_array($venta['tipo_doc'], $tiposOrigen)) {
            $pdo->rollBack();
            return ['ok'=>false,'msg'=>'Solo se pueden convertir despachos.'];
        }
        if (!in_array($nuevoTipo, $tiposDestino)) {
            $pdo->rollBack();
            return ['ok'=>false,'msg'=>'Tipo destino inválido.'];
        }
        if ($venta['tipo_doc']==='despacho' && $nuevoTipo==='despacho') {
            $pdo->rollBack();
            return ['ok'=>false,'msg'=>'Ya es un despacho.'];
        }
        if ($venta['estado']==='anulado') {
            $pdo->rollBack();
            return ['ok'=>false,'msg'=>'No se puede convertir un documento anulado.'];
        }

        // 2) Generar número (FOR UPDATE, dentro de la transacción)
        $nuevoNumero = generarNumeroDoc($nuevoTipo, true);
        $nuevoEstado = 'pendiente';

        // 3) Actualizar el documento
        dbExecute(
            "UPDATE ventas SET tipo_doc=?, numero=?, estado=? WHERE id=?",
            [$nuevoTipo, $nuevoNumero, $nuevoEstado, $ventaId]
        );

        // 4) Solo descontar stock al convertir a Factura (venta)
        $stockAfectado = 0;
        if ($nuevoTipo === 'venta') {
            $detalles = dbQuery("SELECT * FROM venta_detalles WHERE venta_id=?", [$ventaId]);
            foreach ($detalles as $d) {
                if ($d['producto_id'] && $d['cantidad'] > 0) {
                    actualizarStock($d['producto_id'], $d['cantidad'], 'restar');
                }
            }
            $stockAfectado = 1;
            actualizarSaldoCliente($venta['cliente_id']);
        }

        // 5) Historial
        try {
            dbExecute(
                "INSERT INTO documento_historial (venta_id,estado_desde,estado_hasta,tipo_desde,tipo_hasta,stock_afectado,nota,usuario_id)
                 VALUES (?,?,?,?,?,?,?,?)",
                [$ventaId, $venta['estado'], $nuevoEstado,
                 $venta['tipo_doc'], $nuevoTipo, $stockAfectado,
                 "Convertido de {$venta['tipo_doc']} a {$nuevoTipo}" .
                 ($stockAfectado ? " — stock descontado" : " — sin afectar stock"),
                 $usuarioId]
            );
        } catch (Exception $e) { /* columna stock_afectado puede no existir en instancias viejas */ }

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('convertirDocumento: ' . $e->getMessage());
        return ['ok'=>false,'msg'=>'Error al convertir: '.$e->getMessage()];
    }

    // Auditoría fuera de la transacción (no es crítica para la integridad)
    auditLog('UPDATE','ventas',$ventaId,
        "Doc {$venta['numero']} convertido → {$nuevoTipo} ({$nuevoNumero})" .
        ($stockAfectado ? " | Stock descontado" : "")
    );

    $label = ['cotizacion'=>'Despacho','despacho'=>'Despacho','venta'=>'Factura'];
    return [
        'ok'     => true,
        'msg'    => ($label[$nuevoTipo]??ucfirst($nuevoTipo)) . " {$nuevoNumero} creada exitosamente." .
                    ($stockAfectado ? " El inventario fue descontado." : ""),
        'numero' => $nuevoNumero,
    ];
}

// ── Flete ─────────────────────────────────────────────────────
function calcularFlete(float $monto, float $pct): float {
    return round($monto * $pct / 100, 4);
}

function calcularComision(float $monto, string $tipoCli): float {
    $pct = $tipoCli === 'mayorista'
        ? (float)getConfig('comision_pct_mayorista', '7.00')
        : (float)getConfig('comision_pct_detalista', '10.00');
    return round($monto * $pct / 100, 4);
}

// ── Utilidades ────────────────────────────────────────────────
function isApiRequest(): bool {
    return isset($_SERVER['HTTP_X_REQUESTED_WITH']) &&
           strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
}