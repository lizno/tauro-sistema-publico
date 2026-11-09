<?php
/**
 * jhon_cobranza.php â€” v3
 * MÃ³dulo exclusivo de cobranza para JHON JAIRO JIMÃ‰NEZ (vendedor_id = 4)
 *
 * Novedades v3:
 *  - Descuento por factura aplicado al guardar el % de referencia
 *  - Auto-aplicaciÃ³n de descuento a facturas nuevas (al abrir el cliente)
 *  - Aviso visible "Descuento X% aplicado" en cada factura
 *  - El pago grupal ya NO aplica descuento (se aplicÃ³ antes) â€” solo cobra sobre neto
 */
require_once 'config/config.php';
require_once 'functions/db.php';
require_once 'functions/helpers.php';
require_once 'functions/auth.php';

requireLogin();
requirePermission('pagos', 'ver');

define('JHON_VID', 4);
$pageTitle  = 'Cobranza â€” Jhon Jairo JimÃ©nez';

// â”€â”€ Secuencia activa (el mÃ³dulo trabaja por secuencia) â”€â”€
$secuencias = dbQuery("SELECT id,nombre,estado,fecha_ini,fecha_fin FROM secuencias ORDER BY estado='activa' DESC, fecha_ini DESC, id DESC");
$secActiva  = 0;
foreach ($secuencias as $s) { if ($s['estado']==='activa') { $secActiva=(int)$s['id']; break; } }
$secFiltro  = isset($_GET['sec']) ? cleanInt($_GET['sec']) : $secActiva;
$secCondV   = $secFiltro ? " AND v.secuencia_id=?" : "";
$secCondVx  = $secFiltro ? " AND vx.secuencia_id=?" : "";
$secPar     = $secFiltro ? [$secFiltro] : [];

// â•â•â• FIX 1048 â•â•â• Garantiza el esquema de cobranza antes de cualquier consulta
// (ver prepararEsquemaCobranza(): crea tablas y deja pago_id aceptando NULL).
prepararEsquemaCobranza();
$TIPOS_PAGO = ['efectivo','transferencia','punto','zelle','binance','wally','zinli','otro'];

/* â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•
   HELPERS LOCALES
   â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â• */
if (!function_exists('dbLastId')) {
    function dbLastId(): int {
        if (function_exists('db'))    { try { return (int)db()->lastInsertId(); }    catch (\Throwable $e) {} }
        if (function_exists('getDB')) { try { return (int)getDB()->lastInsertId(); } catch (\Throwable $e) {} }
        return 0;
    }
}

/** Recalcula monto_pagado_usd y estado de una venta a partir de sus pagos activos. */
function recalcVenta(int $ventaId): void {
    $venta = dbQueryOne("SELECT total_usd FROM ventas WHERE id=?", [$ventaId]);
    if (!$venta) return;
    $sum = dbQueryOne("SELECT COALESCE(SUM(monto_usd),0) s FROM pagos WHERE venta_id=? AND anulado=0", [$ventaId]);
    $pagado = (float)($sum['s'] ?? 0);
    $total  = (float)$venta['total_usd'];
    $estado = $pagado >= $total - 0.001 ? 'pagado' : ($pagado > 0 ? 'parcial' : 'pendiente');
    dbExecute("UPDATE ventas SET monto_pagado_usd=?, estado=? WHERE id=?", [$pagado, $estado, $ventaId]);
}

/**
 * â•â•â• FIX 1048 â•â•â•
 * El descuento se aplica a la factura ANTES de cobrar, por eso todavÃ­a no
 * existe un pago asociado y el INSERT guarda pago_id = NULL a propÃ³sito.
 * En instalaciones viejas la columna cobros_descuentos.pago_id quedÃ³ como
 * NOT NULL y eso producÃ­a:
 *   SQLSTATE[23000] 1048 Column 'pago_id' cannot be null
 *
 * Esta funciÃ³n deja el esquema como el mÃ³dulo lo necesita:
 *   - crea jhon_descuentos / cobros_descuentos si no existen;
 *   - convierte pago_id en nullable conservando su tipo actual;
 *   - agrega pagos.anulado y pagos.grupo_ref si faltan (abonos y pagos grupales);
 *   - crea el Ã­ndice Ãºnico de jhon_descuentos.cliente_id.
 *
 * Es "best effort": si el usuario de MySQL no tiene permisos de CREATE/ALTER
 * se registra en el log y la pÃ¡gina sigue funcionando. Se ejecuta una sola vez
 * por peticiÃ³n.
 *
 * El mismo cambio estÃ¡ en: install/fix_cobros_descuentos.sql
 */
function prepararEsquemaCobranza(): void {
    static $intentado = false;
    if ($intentado) return;
    $intentado = true;

    try {
        dbExecute("CREATE TABLE IF NOT EXISTS jhon_descuentos (
            id            INT(11)      NOT NULL AUTO_INCREMENT,
            cliente_id    INT(11)      NOT NULL,
            descuento_pct DECIMAL(5,2) NOT NULL DEFAULT 0.00,
            notas         VARCHAR(255)          DEFAULT NULL,
            usuario_id    INT(11)               DEFAULT NULL,
            created_at    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uk_cliente (cliente_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        dbExecute("CREATE TABLE IF NOT EXISTS cobros_descuentos (
            id              INT(11)       NOT NULL AUTO_INCREMENT,
            cliente_id      INT(11)       NOT NULL,
            pago_id         INT(11)                DEFAULT NULL,
            venta_id        INT(11)       NOT NULL,
            descuento_pct   DECIMAL(5,2)  NOT NULL DEFAULT 0.00,
            monto_bruto_usd DECIMAL(12,4) NOT NULL DEFAULT 0.0000,
            monto_desc_usd  DECIMAL(12,4) NOT NULL DEFAULT 0.0000,
            monto_neto_usd  DECIMAL(12,4) NOT NULL DEFAULT 0.0000,
            usuario_id      INT(11)                DEFAULT NULL,
            created_at      TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_venta (venta_id),
            KEY idx_cliente (cliente_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $col = dbQueryOne(
            "SELECT IS_NULLABLE, COLUMN_TYPE
               FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME   = 'cobros_descuentos'
                AND COLUMN_NAME  = 'pago_id'"
        );
        if ($col && strtoupper((string)$col['IS_NULLABLE']) === 'NO') {
            $tipo = (string)$col['COLUMN_TYPE'];
            // El tipo viene de information_schema: se valida antes de interpolarlo.
            if (!preg_match('/^[a-z]+(\(\d+(,\d+)?\))?( unsigned)?$/i', $tipo)) {
                $tipo = 'INT(11)';
            }
            dbExecute("ALTER TABLE cobros_descuentos MODIFY COLUMN pago_id {$tipo} NULL DEFAULT NULL");
            error_log('cobros_descuentos.pago_id corregido a NULL (instalaciÃ³n con esquema viejo)');
        }

        // La tabla pagos tambiÃ©n puede venir de una instalaciÃ³n vieja:
        // recalcVenta() filtra por anulado y el pago grupal usa grupo_ref.
        $colsPagos = dbQuery(
            "SELECT COLUMN_NAME FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pagos'"
        );
        $pagos = array_column($colsPagos, 'COLUMN_NAME');
        if (!empty($pagos)) {
            if (!in_array('anulado', $pagos, true)) {
                dbExecute("ALTER TABLE pagos ADD COLUMN anulado TINYINT(1) NOT NULL DEFAULT 0");
                error_log('pagos.anulado agregado (esquema viejo)');
            }
            if (!in_array('grupo_ref', $pagos, true)) {
                dbExecute("ALTER TABLE pagos ADD COLUMN grupo_ref VARCHAR(50) NULL DEFAULT NULL");
                error_log('pagos.grupo_ref agregado (esquema viejo)');
            }
        }
    } catch (\Throwable $e) {
        error_log('prepararEsquemaCobranza: ' . $e->getMessage());
    }
}

/**
 * â•â•â• NUEVO â•â•â•
 * Aplica descuento a UNA factura si aÃºn no lo tiene.
 * Devuelve true si aplicÃ³, false si ya tenÃ­a o no aplica.
 *
 * El UPDATE del total y el INSERT del registro van en la MISMA transacciÃ³n:
 * antes, si el INSERT fallaba, la factura quedaba descontada sin registro y en
 * la siguiente carga se le volvÃ­a a aplicar el % (descuentos acumulados).
 */
function aplicarDescuentoFactura(int $ventaId, int $clienteId, float $pct, int $usuarioId): bool {
    if ($pct <= 0) return false;

    prepararEsquemaCobranza();

    try {
        $ya = dbQueryOne("SELECT id FROM cobros_descuentos WHERE venta_id=? LIMIT 1", [$ventaId]);
        if ($ya) return false;

        $v = dbQueryOne("SELECT total_usd, estado FROM ventas WHERE id=?", [$ventaId]);
        if (!$v) return false;
        // No se descuentan facturas anuladas ni ya pagadas (quedarÃ­an descuadradas)
        if (in_array((string)$v['estado'], ['pagado','anulado'], true)) return false;

        $bruto    = (float)$v['total_usd'];
        $montoDes = round($bruto * $pct / 100, 4);
        $neto     = max(0, $bruto - $montoDes);

        $pdo = getDB();
        $pdo->beginTransaction();
        try {
            dbExecute(
                "UPDATE ventas
                    SET total_usd = ?,
                        total_bs  = CASE WHEN tasa_valor IS NULL OR tasa_valor = 0
                                         THEN total_bs
                                         ELSE ROUND(? * tasa_valor, 2) END
                  WHERE id = ?",
                [$neto, $neto, $ventaId]
            );
            dbExecute(
                "INSERT INTO cobros_descuentos
                 (cliente_id,pago_id,venta_id,descuento_pct,monto_bruto_usd,monto_desc_usd,monto_neto_usd,usuario_id)
                 VALUES (?,NULL,?,?,?,?,?,?)",
                [$clienteId,$ventaId,$pct,$bruto,$montoDes,$neto,$usuarioId]
            );
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }

        try { recalcVenta($ventaId); } catch (\Throwable $e) { error_log('recalcVenta #'.$ventaId.': '.$e->getMessage()); }
        return true;
    } catch (\Throwable $e) {
        error_log('aplicarDescuentoFactura(venta #' . $ventaId . '): ' . $e->getMessage());
        static $avisoMostrado = false;
        if (!$avisoMostrado) {
            $avisoMostrado = true;
            $_SESSION['flash'] = ['type'=>'warning','msg'=>
                'No se pudo guardar el descuento en la factura #' . $ventaId
                . '. Ejecuta install/fix_cobros_descuentos.sql en phpMyAdmin.'];
        }
        return false;
    }
}

/** â•â•â• NUEVO â•â•â• Aplica descuento a TODAS las facturas pendientes del cliente que no lo tengan. */
function aplicarDescuentoCliente(int $clienteId, float $pct, int $usuarioId): array {
    $aplicadas = 0; $totalDesc = 0.0;
    if ($pct <= 0) return ['count'=>0,'total'=>0];
    $facturas = dbQuery(
        "SELECT v.id, v.total_usd FROM ventas v
         WHERE v.cliente_id=? AND v.vendedor_id=? AND v.tipo_doc='venta' AND v.estado!='anulado'",
        [$clienteId, JHON_VID]
    );
    foreach ($facturas as $f) {
        if (aplicarDescuentoFactura((int)$f['id'], $clienteId, $pct, $usuarioId)) {
            $aplicadas++;
            $totalDesc += round((float)$f['total_usd'] * $pct / 100, 4);
        }
    }
    return ['count'=>$aplicadas,'total'=>$totalDesc];
}

/**
 * â•â•â• NUEVO â•â•â• Recalcula el descuento de UNA factura que YA tenÃ­a descuento,
 * usando el monto bruto original guardado en cobros_descuentos.
 * Sirve para cuando se cambia el % de referencia del cliente.
 * Con $pct = 0 la factura vuelve a su total bruto y se elimina el registro
 * (es decir, se le quita el descuento).
 */
function recalcularDescuentoFactura(int $ventaId, float $pct, int $usuarioId): bool {
    $d = dbQueryOne(
        "SELECT id, monto_bruto_usd FROM cobros_descuentos
          WHERE venta_id=? ORDER BY id LIMIT 1",
        [$ventaId]
    );
    if (!$d) return false;

    $bruto = (float)$d['monto_bruto_usd'];
    if ($bruto <= 0) return false;

    $neto = round($bruto * (1 - max(0, $pct) / 100), 4);
    $desc = round($bruto - $neto, 4);

    $pdo = getDB();
    $pdo->beginTransaction();
    try {
        dbExecute(
            "UPDATE ventas
                SET total_usd = ?,
                    total_bs  = CASE WHEN tasa_valor IS NULL OR tasa_valor = 0
                                     THEN total_bs
                                     ELSE ROUND(? * tasa_valor, 2) END
              WHERE id = ?",
            [$neto, $neto, $ventaId]
        );

        if ($pct <= 0) {
            // Sin descuento: se borra el registro para que la factura quede "limpia"
            dbExecute("DELETE FROM cobros_descuentos WHERE id=?", [(int)$d['id']]);
        } else {
            dbExecute(
                "UPDATE cobros_descuentos
                    SET descuento_pct=?, monto_desc_usd=?, monto_neto_usd=?, usuario_id=?
                  WHERE id=?",
                [$pct, $desc, $neto, $usuarioId, (int)$d['id']]
            );
        }
        $pdo->commit();
    } catch (\Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }

    try { recalcVenta($ventaId); } catch (\Throwable $e) { error_log('recalcVenta #'.$ventaId.': '.$e->getMessage()); }
    return true;
}

/** â•â•â• NUEVO â•â•â• Recalcula las facturas del cliente que ya tienen descuento.
 *  No toca las anuladas ni las que ya estÃ¡n pagadas (esas quedan como estÃ¡n). */
function recalcularDescuentoCliente(int $clienteId, float $pct, int $usuarioId): array {
    $facturas = dbQuery(
        "SELECT v.id FROM ventas v
           JOIN cobros_descuentos cd ON cd.venta_id = v.id
          WHERE v.cliente_id=? AND v.vendedor_id=?
            AND v.tipo_doc='venta'
            AND v.estado IN ('pendiente','parcial')
          GROUP BY v.id",
        [$clienteId, JHON_VID]
    );
    $n = 0;
    foreach ($facturas as $f) {
        if (recalcularDescuentoFactura((int)$f['id'], $pct, $usuarioId)) $n++;
    }
    return ['count'=>$n];
}

/* â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•
   ACCIONES POST
   â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â• */

// â”€â”€ 1. Guardar / actualizar descuento por cliente (APLICA a facturas) â”€â”€â”€
if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action']??'')==='guardar_descuento') {
    verifyCsrf();
    requirePermission('pagos','crear');
    $cliId = cleanInt($_POST['cliente_id'] ?? 0);
    $pct   = min(100, max(0, (float)str_replace(',','.', $_POST['descuento_pct'] ?? 0)));
    $notas = clean($_POST['notas'] ?? '');
    $aplicarAhora = !empty($_POST['aplicar_ahora']);
    $recalcular   = !empty($_POST['recalcular_todas']);

    if ($cliId) {
        dbExecute("INSERT INTO jhon_descuentos (cliente_id,descuento_pct,notas,usuario_id)
                   VALUES (?,?,?,?)
                   ON DUPLICATE KEY UPDATE descuento_pct=VALUES(descuento_pct),
                                           notas=VALUES(notas),
                                           usuario_id=VALUES(usuario_id)",
            [$cliId, $pct, $notas, $_SESSION['user_id']]);

        // â•â•â• NUEVO â•â•â• Facturas que YA tienen descuento: se recalculan al % nuevo
        $recalcCount = 0;
        if ($recalcular) {
            $rr = recalcularDescuentoCliente($cliId, $pct, (int)$_SESSION['user_id']);
            $recalcCount = (int)$rr['count'];
        }

        // â•â•â• NUEVO â•â•â• Aplicar el descuento a las facturas existentes
        $res = ['count'=>0,'total'=>0];
        if ($aplicarAhora && $pct > 0) {
            $res = aplicarDescuentoCliente($cliId, $pct, (int)$_SESSION['user_id']);
        }

        auditLog('UPDATE','jhon_descuentos',$cliId,"Descuento ref. cliente #$cliId â†’ {$pct}% (aplicadas {$res['count']} facturas, recalculadas $recalcCount)");
        $msg = "Descuento de referencia {$pct}% guardado.";
        if ($res['count'] > 0) {
            $msg .= " Se aplicÃ³ a {$res['count']} factura(s) por un total de \$".number_format($res['total'],2).".";
        }
        if ($recalcCount > 0) {
            $msg .= " Se recalcularon $recalcCount factura(s) que ya tenÃ­an descuento.";
        }
        $_SESSION['flash']=['type'=>'success','msg'=>$msg];
    }
    header("Location: jhon_cobranza.php?cli=$cliId&sec=$secFiltro"); exit;
}

// â”€â”€ 2. Registrar abono individual â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action']??'')==='registrar_abono') {
    verifyCsrf();
    requirePermission('pagos','crear');
    $ventaId = cleanInt($_POST['venta_id'] ?? 0);
    $monto   = (float)str_replace(',','.', $_POST['monto'] ?? 0);
    $tipo    = in_array($_POST['tipo']??'',$TIPOS_PAGO) ? $_POST['tipo'] : 'efectivo';
    $ref     = clean($_POST['referencia'] ?? '');
    $fecha   = $_POST['fecha'] ?? date('Y-m-d');
    $notas   = clean($_POST['notas'] ?? '');
    $cliId   = cleanInt($_POST['cliente_id'] ?? 0);

    if ($ventaId && $monto > 0) {
        $venta = dbQueryOne("SELECT v.id, v.total_usd, v.monto_pagado_usd
                             FROM ventas v
                             WHERE v.id=? AND v.vendedor_id=?{$secCondV}", array_merge([$ventaId, JHON_VID], $secPar));
        if ($venta) {
            $secEstado = dbQueryOne("SELECT s.estado FROM ventas v JOIN secuencias s ON s.id=v.secuencia_id WHERE v.id=?", [$ventaId]);
            if ($secEstado && $secEstado['estado'] !== 'activa') {
                $_SESSION['flash']=['type'=>'error','msg'=>'Esa factura pertenece a una secuencia cerrada. Reábrela para cobrar.'];
                header("Location: jhon_cobranza.php?cli=$cliId&sec=$secFiltro"); exit;
            }
            $saldoActual = (float)$venta['total_usd'] - (float)$venta['monto_pagado_usd'];
            $montoReal   = min($monto, max(0, $saldoActual));
            if ($montoReal > 0) {
                dbExecute("INSERT INTO pagos (venta_id,monto_usd,moneda,tipo,referencia,fecha,notas,usuario_id)
                           VALUES (?,?,'USD',?,?,?,?,?)",
                    [$ventaId,$montoReal,$tipo,$ref,$fecha,$notas,$_SESSION['user_id']]);
                recalcVenta($ventaId);
                auditLog('INSERT','pagos',(int)dbLastId(),"Abono Jhon factura #$ventaId: \$$montoReal");
                $_SESSION['flash']=['type'=>'success','msg'=>"Abono de \$".number_format($montoReal,2)." registrado."];
            }
        }
    }
    header("Location: jhon_cobranza.php?cli=$cliId&sec=$secFiltro"); exit;
}

// â”€â”€ 3. Pago grupal automÃ¡tico FIFO (SIN descuento â€” ya se aplicÃ³ antes) â”€â”€
if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action']??'')==='pago_grupo') {
    verifyCsrf();
    requirePermission('pagos','crear');
    $cliId   = cleanInt($_POST['cliente_id'] ?? 0);
    $monto   = (float)str_replace(',','.', $_POST['monto'] ?? 0);
    $tipo    = in_array($_POST['tipo']??'',$TIPOS_PAGO) ? $_POST['tipo'] : 'efectivo';
    $ref     = clean($_POST['referencia'] ?? '');
    $fecha   = $_POST['fecha'] ?? date('Y-m-d');
    $notas   = clean($_POST['notas'] ?? '');

    if ($cliId && $monto > 0) {
        if ($secFiltro) {
            $secRow = dbQueryOne("SELECT estado FROM secuencias WHERE id=?", [$secFiltro]);
            if (!$secRow || $secRow['estado'] !== 'activa') {
                $_SESSION['flash']=['type'=>'error','msg'=>'La secuencia esta cerrada. Reabrela para registrar pagos.'];
                header("Location: jhon_cobranza.php?cli=$cliId&sec=$secFiltro"); exit;
            }
        }
        // Facturas pendientes del cliente (FIFO) â€” el total_usd ya viene neto con descuento
        $facturas = dbQuery(
            "SELECT id, numero, total_usd, monto_pagado_usd
             FROM ventas
             WHERE cliente_id=? AND vendedor_id=? AND estado IN ('pendiente','parcial')
               AND tipo_doc='venta'{$secCondV}
             ORDER BY fecha ASC, id ASC",
            array_merge([$cliId, JHON_VID], $secPar)
        );

        if (empty($facturas)) {
            $_SESSION['flash']=['type'=>'warning','msg'=>"No hay facturas pendientes para aplicar el pago."];
            header("Location: jhon_cobranza.php?cli=$cliId&sec=$secFiltro"); exit;
        }

        $grupoRef  = 'GRP-' . date('YmdHis') . '-' . $cliId;
        $restante  = $monto;
        $aplicados = 0;

        foreach ($facturas as $f) {
            if ($restante <= 0.001) break;
            $saldo = (float)$f['total_usd'] - (float)$f['monto_pagado_usd'];
            if ($saldo <= 0.001) continue;

            $aplicar = min($restante, $saldo);
            dbExecute(
                "INSERT INTO pagos (venta_id,monto_usd,moneda,tipo,referencia,fecha,notas,usuario_id,grupo_ref)
                 VALUES (?,?,'USD',?,?,?,?,?,?)",
                [$f['id'],$aplicar,$tipo,$ref,$fecha,"[GRUPO] $notas",$_SESSION['user_id'],$grupoRef]
            );
            recalcVenta($f['id']);
            $restante -= $aplicar;
            $aplicados++;
        }

        auditLog('INSERT','pagos',0,"Pago grupo Jhon cliente #$cliId: \$$monto â†’ $aplicados factura(s)");
        $msg = "Pago de \$".number_format($monto,2)." aplicado a $aplicados factura(s) FIFO.";
        if ($restante > 0.001) $msg .= " Sobrante \$".number_format($restante,2)." no aplicado.";
        $_SESSION['flash']=['type'=>'success','msg'=>$msg];
    }
    header("Location: jhon_cobranza.php?cli=$cliId&sec=$secFiltro"); exit;
}

// â”€â”€ 4. Editar abono â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action']??'')==='editar_abono') {
    verifyCsrf();
    requirePermission('pagos','editar');
    $pagoId = cleanInt($_POST['pago_id'] ?? 0);
    $monto  = (float)str_replace(',','.', $_POST['monto'] ?? 0);
    $tipo   = in_array($_POST['tipo']??'',$TIPOS_PAGO) ? $_POST['tipo'] : 'efectivo';
    $ref    = clean($_POST['referencia'] ?? '');
    $fecha  = $_POST['fecha'] ?? date('Y-m-d');
    $notas  = clean($_POST['notas'] ?? '');
    $cliId  = cleanInt($_POST['cliente_id'] ?? 0);

    if ($pagoId && $monto > 0) {
        $pago = dbQueryOne("SELECT * FROM pagos WHERE id=? AND anulado=0", [$pagoId]);
        if ($pago) {
            $valida = dbQueryOne(
                "SELECT v.id FROM ventas v
                 WHERE v.id=? AND v.vendedor_id=?{$secCondV}",
                array_merge([$pago['venta_id'], JHON_VID], $secPar)
            );
            if ($valida) {
                $venta = dbQueryOne("SELECT total_usd, monto_pagado_usd FROM ventas WHERE id=?", [$pago['venta_id']]);
                $saldoMax = (float)$venta['total_usd'] - ((float)$venta['monto_pagado_usd'] - (float)$pago['monto_usd']);
                $nuevoMonto = min($monto, max(0, $saldoMax));
                if ($nuevoMonto > 0) {
                    dbExecute("UPDATE pagos SET monto_usd=?, tipo=?, referencia=?, fecha=?, notas=? WHERE id=?",
                        [$nuevoMonto,$tipo,$ref,$fecha,$notas,$pagoId]);
                    recalcVenta((int)$pago['venta_id']);
                    auditLog('UPDATE','pagos',$pagoId,"Abono editado #$pagoId â†’ \$$nuevoMonto");
                    $_SESSION['flash']=['type'=>'success','msg'=>"Abono actualizado a \$".number_format($nuevoMonto,2)."."];
                } else {
                    $_SESSION['flash']=['type'=>'danger','msg'=>"El monto debe ser mayor que 0 y no exceder el saldo."];
                }
            }
        }
    }
    header("Location: jhon_cobranza.php?cli=$cliId&sec=$secFiltro"); exit;
}

// â”€â”€ 5. Anular abono (soft-delete + recÃ¡lculo) â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action']??'')==='anular_abono') {
    verifyCsrf();
    requirePermission('pagos','eliminar');
    $pagoId = cleanInt($_POST['pago_id'] ?? 0);
    $cliId  = cleanInt($_POST['cliente_id'] ?? 0);

    if ($pagoId) {
        $pago = dbQueryOne("SELECT * FROM pagos WHERE id=? AND anulado=0", [$pagoId]);
        if ($pago) {
            $valida = dbQueryOne(
                "SELECT v.id FROM ventas v
                 WHERE v.id=? AND v.vendedor_id=?{$secCondV}",
                array_merge([$pago['venta_id'], JHON_VID], $secPar)
            );
            if ($valida) {
                dbExecute("UPDATE pagos SET anulado=1, notas=CONCAT(COALESCE(notas,''),' [ANULADO ',NOW(),']') WHERE id=?", [$pagoId]);
                recalcVenta((int)$pago['venta_id']);
                auditLog('UPDATE','pagos',$pagoId,"Abono anulado #$pagoId (\$".number_format((float)$pago['monto_usd'],2).")");
                $_SESSION['flash']=['type'=>'success','msg'=>"Abono anulado. Saldo recalculado."];
            }
        }
    }
    header("Location: jhon_cobranza.php?cli=$cliId&sec=$secFiltro"); exit;
}

/* â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•
   DATOS
   â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â• */
$cliSel = cleanInt($_GET['cli'] ?? 0);

$clientes = dbQuery(
    "SELECT c.id, c.nombre, c.rif, c.telefono,
            COUNT(v.id) total_facturas,
            COALESCE(SUM(v.total_usd),0) total_usd,
            COALESCE(SUM(v.monto_pagado_usd),0) pagado_usd,
            COALESCE(SUM(v.total_usd)-SUM(v.monto_pagado_usd),0) saldo_usd,
            jd.descuento_pct
     FROM clientes c
     LEFT JOIN ventas v  ON v.cliente_id=c.id AND v.vendedor_id=? AND v.tipo_doc='venta' AND v.estado<>'anulado'{$secCondV}
     LEFT JOIN jhon_descuentos jd ON jd.cliente_id=c.id
     WHERE EXISTS (SELECT 1 FROM ventas vx
                   WHERE vx.cliente_id=c.id AND vx.vendedor_id=?
                     AND vx.tipo_doc='venta' AND vx.estado<>'anulado'{$secCondVx})
     GROUP BY c.id
     ORDER BY saldo_usd DESC, c.nombre ASC",
    array_merge([JHON_VID], $secPar, [JHON_VID], $secPar)
);

$cliData = null; $facturas = []; $pFacts = []; $statsCliente = null; $historialGlobal = [];
$descuentosPorVenta = []; // â•â•â• NUEVO â•â•â• venta_id => [pct, desc, bruto, neto]

if ($cliSel) {
    $cliData = dbQueryOne(
        "SELECT c.*, jd.descuento_pct, jd.notas desc_notas
         FROM clientes c
         LEFT JOIN jhon_descuentos jd ON jd.cliente_id=c.id
         WHERE c.id=? AND EXISTS (SELECT 1 FROM ventas vx
                       WHERE vx.cliente_id=c.id AND vx.vendedor_id=?
                         AND vx.tipo_doc='venta' AND vx.estado<>'anulado'{$secCondVx})",
        array_merge([$cliSel, JHON_VID], $secPar)
    );

    if ($cliData) {
        $descPctCliente = (float)($cliData['descuento_pct'] ?? 0);

        // â•â•â• NUEVO â•â•â• Auto-aplicar descuento a facturas nuevas que no lo tengan
        if ($descPctCliente > 0) {
            $sinDesc = dbQuery(
                "SELECT v.id FROM ventas v
                 LEFT JOIN cobros_descuentos cd ON cd.venta_id=v.id
                 WHERE v.cliente_id=? AND v.vendedor_id=? AND v.tipo_doc='venta'
                   AND v.estado!='anulado' AND cd.id IS NULL{$secCondV}",
                array_merge([$cliSel, JHON_VID], $secPar)
            );
            foreach ($sinDesc as $sd) {
                aplicarDescuentoFactura((int)$sd['id'], $cliSel, $descPctCliente, (int)$_SESSION['user_id']);
            }
        }

        $facturas = dbQuery(
            "SELECT v.id, v.numero, v.fecha, v.fecha_vencimiento,
                    v.total_usd, v.monto_pagado_usd, v.estado, v.tipo_pago,
                    (v.total_usd - v.monto_pagado_usd) saldo_usd
             FROM ventas v
             WHERE v.cliente_id=? AND v.vendedor_id=? AND v.tipo_doc='venta' AND v.estado!='anulado'{$secCondV}
             ORDER BY v.fecha ASC, v.id ASC",
            array_merge([$cliSel, JHON_VID], $secPar)
        );

        // â•â•â• NUEVO â•â•â• Cargar info de descuentos aplicados por venta
        if (!empty($facturas)) {
            $ids = array_column($facturas, 'id');
            if ($ids) {
                $ph = implode(',', array_fill(0, count($ids), '?'));
                $rows = dbQuery(
                    "SELECT venta_id, descuento_pct, monto_bruto_usd, monto_desc_usd, monto_neto_usd
                     FROM cobros_descuentos WHERE venta_id IN ($ph)",
                    $ids
                );
                foreach ($rows as $r) {
                    $descuentosPorVenta[(int)$r['venta_id']] = [
                        'pct'   => (float)$r['descuento_pct'],
                        'bruto' => (float)$r['monto_bruto_usd'],
                        'desc'  => (float)$r['monto_desc_usd'],
                        'neto'  => (float)$r['monto_neto_usd'],
                    ];
                }
            }
        }

        foreach ($facturas as $f) {
            $pFacts[$f['id']] = dbQuery(
                "SELECT * FROM pagos WHERE venta_id=? ORDER BY anulado ASC, fecha DESC, created_at DESC",
                [$f['id']]
            );
        }

        $historialGlobal = dbQuery(
            "SELECT p.*, v.numero factura_numero
             FROM pagos p
             JOIN ventas v ON p.venta_id=v.id
             WHERE v.cliente_id=? AND v.vendedor_id=? AND v.estado<>'anulado'{$secCondV}
             ORDER BY p.fecha DESC, p.created_at DESC
             LIMIT 100",
            array_merge([$cliSel, JHON_VID], $secPar)
        );

        $totalFact   = array_sum(array_column($facturas,'total_usd'));
        $totalPagado = array_sum(array_column($facturas,'monto_pagado_usd'));
        $totalSaldo  = $totalFact - $totalPagado;
        $descPct     = (float)($cliData['descuento_pct'] ?? 0);

        $statsCliente = [
            'total_fact'  => $totalFact,
            'total_pagado'=> $totalPagado,
            'total_saldo' => $totalSaldo,
            'desc_pct'    => $descPct,
            'pendientes'  => count(array_filter($facturas, fn($f)=>$f['estado']!=='pagado')),
        ];
    }
}

// â•â•â• NUEVO â•â•â• Facturas con descuento que el recÃ¡lculo sÃ­ puede tocar
// (no se recalculan las anuladas ni las ya pagadas)
$descRecalc = 0;
foreach ($facturas as $f) {
    if ($f['estado'] !== 'pagado' && isset($descuentosPorVenta[(int)$f['id']])) $descRecalc++;
}

$totGlobal = dbQueryOne(
    "SELECT COUNT(DISTINCT c.id) total_clientes,
            COUNT(v.id) total_facturas,
            COALESCE(SUM(v.total_usd),0) total_ventas,
            COALESCE(SUM(v.monto_pagado_usd),0) total_cobrado,
            COALESCE(SUM(v.total_usd-v.monto_pagado_usd),0) total_saldo
     FROM ventas v
     JOIN clientes c ON v.cliente_id=c.id
     WHERE v.vendedor_id=? AND v.tipo_doc='venta' AND v.estado!='anulado'{$secCondV}",
    array_merge([JHON_VID], $secPar)
);

include 'includes/header.php';
?>
<style>
.jhon-layout{display:grid;grid-template-columns:340px 1fr;gap:20px}
@media(max-width:900px){.jhon-layout{grid-template-columns:1fr}}
.cli-item{display:flex;align-items:center;gap:12px;padding:12px 16px;border-bottom:1px solid var(--border);cursor:pointer;text-decoration:none;transition:.15s;color:inherit}
.cli-item:hover{background:#f8fafc}
.cli-item.active{background:#fdf3f0;border-left:4px solid var(--primary)}
.cli-avatar{width:38px;height:38px;border-radius:50%;background:var(--primary);color:white;display:flex;align-items:center;justify-content:center;font-weight:800;font-size:14px;flex-shrink:0}
.cli-avatar.saldado{background:var(--success)}
.cli-avatar.sin-fact{background:#aaa}
.stats-row{display:grid;grid-template-columns:repeat(4,1fr);gap:12px;margin-bottom:16px}
@media(max-width:700px){.stats-row{grid-template-columns:repeat(2,1fr)}}
.stat-box{background:white;border:1px solid var(--border);border-radius:var(--radius);padding:14px;text-align:center}
.stat-val{font-size:20px;font-weight:800;line-height:1.1}
.stat-lbl{font-size:10px;text-transform:uppercase;letter-spacing:.5px;color:var(--text-muted);margin-top:3px;font-weight:600}
.fact-card{border:1px solid var(--border);border-radius:var(--radius);margin-bottom:12px;overflow:hidden;background:white}
.fact-card.sel{border-color:var(--primary);box-shadow:0 0 0 3px rgba(192,57,43,.15)}
.fact-card.con-desc{border-left:4px solid #8b5cf6}
.fact-header{display:flex;align-items:center;gap:12px;padding:10px 14px;background:#f8fafc;border-bottom:1px solid var(--border)}
.fact-body{padding:10px 14px}
.fact-pagos{background:#f0fdf4;border-top:1px dashed #bbf7d0;padding:8px 14px}
.badge-estado{padding:2px 8px;border-radius:10px;font-size:10px;font-weight:700}
.be-pagado{background:#d1e7dd;color:#0f5132}
.be-parcial{background:#cff4fc;color:#0c5460}
.be-pendiente{background:#fff3cd;color:#856404}
.be-vencido{background:#f8d7da;color:#842029}
.badge-desc{background:#ede9fe;color:#6d28d9;border:1px solid #c4b5fd;padding:2px 8px;border-radius:10px;font-size:10px;font-weight:700}
.desc-badge{background:#fff3cd;border:1px solid #ffc107;color:#856404;padding:3px 9px;border-radius:12px;font-size:11px;font-weight:700}
.desc-info{background:#f5f3ff;border:1px solid #ddd6fe;border-radius:8px;padding:8px 10px;font-size:11px;color:#5b21b6;margin-top:8px;display:flex;align-items:center;gap:6px}
.jhon-banner{background:linear-gradient(135deg,#1a1a2e 0%,#16213e 100%);color:white;border-radius:var(--radius);padding:18px 24px;margin-bottom:20px;display:flex;align-items:center;gap:16px}
.jhon-banner h2{margin:0;font-size:20px}
.jhon-banner p{margin:0;font-size:12px;opacity:.7;margin-top:3px}
.tabs{display:flex;gap:4px;border-bottom:2px solid var(--border);margin-bottom:14px}
.tab-btn{background:none;border:none;padding:10px 16px;font-size:13px;font-weight:700;color:var(--text-muted);cursor:pointer;border-bottom:2px solid transparent;margin-bottom:-2px}
.tab-btn.active{color:var(--primary);border-bottom-color:var(--primary)}
.pago-row{display:flex;align-items:center;gap:8px;padding:7px 10px;border-bottom:1px dashed #bbf7d0;font-size:12px}
.pago-row.anulado{opacity:.5;text-decoration:line-through;background:#fef2f2}
.pago-acciones{margin-left:auto;display:flex;gap:4px}
.btn-icon-sm{background:none;border:1px solid var(--border);border-radius:4px;padding:2px 6px;font-size:11px;cursor:pointer;color:var(--text-muted)}
.btn-icon-sm:hover{background:#f8fafc;color:var(--primary)}
.btn-icon-sm.danger:hover{background:#fef2f2;color:var(--danger);border-color:var(--danger)}
.check-fact{width:18px;height:18px;cursor:pointer}
</style>

<!-- Banner -->
<div class="jhon-banner">
    <div style="width:52px;height:52px;border-radius:50%;background:#e74c3c;display:flex;align-items:center;justify-content:center;font-size:22px;font-weight:900;flex-shrink:0">JJ</div>
    <div style="flex:1">
        <h2><i class="fa-solid fa-star" style="color:gold"></i> Cobranza â€” Jhon Jairo JimÃ©nez</h2>
        <p>Pago grupal FIFO Â· Descuento por factura Â· Editar / anular abonos</p>
    </div>
    <div style="text-align:right;font-size:12px;opacity:.8">
        <div><?=(int)($totGlobal['total_clientes']??0)?> clientes Â· <?=(int)($totGlobal['total_facturas']??0)?> facturas</div>
        <div style="font-size:18px;font-weight:800;color:#2ecc71">$ <?=number_format($totGlobal['total_cobrado']??0,2,'.',',')?> cobrado</div>
        <div style="color:#e74c3c">$ <?=number_format($totGlobal['total_saldo']??0,2,'.',',')?> pendiente</div>
    </div>
</div>

<!-- Selector de secuencia -->
<div class="card" style="margin-bottom:16px">
    <div class="card-body" style="padding:10px 16px;display:flex;gap:10px;align-items:center;flex-wrap:wrap">
        <i class="fa-solid fa-layer-group" style="color:var(--primary)"></i>
        <strong style="font-size:13px">Secuencia:</strong>
        <form method="GET" style="margin:0;display:flex;gap:8px">
            <select name="sec" class="form-select" style="max-width:280px" onchange="this.form.submit()">
                <option value="0">Todas las secuencias</option>
                <?php foreach($secuencias as $s): ?>
                <option value="<?=(int)$s['id']?>" <?=$secFiltro===(int)$s['id']?'selected':''?>>
                    <?=clean($s['nombre'])?><?=$s['estado']==='activa'?' (activa)':''?>
                </option>
                <?php endforeach; ?>
            </select>
            <?php if ($cliSel): ?><input type="hidden" name="cli" value="<?=$cliSel?>"><?php endif; ?>
        </form>
        <?php if ($secFiltro): ?>
        <span style="font-size:11px;background:#eef4fb;color:#2980b9;padding:3px 9px;border-radius:10px;font-weight:700">
            Mostrando solo esta secuencia
        </span>
        <?php endif; ?>
    </div>
</div>

<!-- Stats globales -->
<div class="stats-row" style="margin-bottom:20px">
    <div class="stat-box" style="border-top:3px solid var(--success)">
        <div class="stat-val" style="color:var(--success)">$ <?=number_format($totGlobal['total_ventas']??0,2,'.',',')?></div>
        <div class="stat-lbl">Total Facturado</div>
    </div>
    <div class="stat-box" style="border-top:3px solid var(--info)">
        <div class="stat-val" style="color:var(--info)">$ <?=number_format($totGlobal['total_cobrado']??0,2,'.',',')?></div>
        <div class="stat-lbl">Total Cobrado</div>
    </div>
    <div class="stat-box" style="border-top:3px solid var(--danger)">
        <div class="stat-val" style="color:var(--danger)">$ <?=number_format($totGlobal['total_saldo']??0,2,'.',',')?></div>
        <div class="stat-lbl">Saldo Pendiente</div>
    </div>
    <div class="stat-box" style="border-top:3px solid var(--primary)">
        <?php $pctCobrado = ($totGlobal['total_ventas']??0) > 0 ? round(($totGlobal['total_cobrado']??0)/($totGlobal['total_ventas']??1)*100,1) : 0; ?>
        <div class="stat-val" style="color:var(--primary)"><?=$pctCobrado?>%</div>
        <div class="stat-lbl">% Cobrado</div>
    </div>
</div>

<?php if(empty($clientes)): ?>
<div class="card"><div class="card-body" style="text-align:center;padding:48px;color:var(--text-muted)">
    <i class="fa-solid fa-users-slash" style="font-size:48px;opacity:.2"></i>
    <h3 style="margin-top:16px">Sin clientes con facturas en esta secuencia</h3>
</div></div>

<?php else: ?>
<div class="jhon-layout">

<!-- LISTA CLIENTES -->
<div>
<div class="card">
    <div class="card-header"><h5><i class="fa-solid fa-users text-primary"></i> Clientes de Jhon</h5></div>
    <?php foreach($clientes as $cl):
        $saldo    = (float)$cl['saldo_usd'];
        $saldado  = $saldo <= 0.001;
        $sinFact  = (int)$cl['total_facturas'] === 0;
        $inicial  = strtoupper(substr(trim($cl['nombre']),0,1));
        $isActive = $cliSel == $cl['id'];
    ?>
    <a href="jhon_cobranza.php?cli=<?=$cl['id']?>&sec=<?=$secFiltro?>" class="cli-item <?=$isActive?'active':''?>">
        <div class="cli-avatar <?=$saldado?'saldado':($sinFact?'sin-fact':'')?>"><?=$inicial?></div>
        <div style="flex:1;min-width:0">
            <div style="font-weight:700;font-size:13px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis"><?=clean($cl['nombre'])?></div>
            <div style="font-size:11px;color:var(--text-muted);margin-top:2px">
                <?=(int)$cl['total_facturas']?> factura(s)
                <?php if($cl['descuento_pct'] > 0): ?>
                Â· <span style="color:#6d28d9;font-weight:700">ðŸ·ï¸ Desc <?=$cl['descuento_pct']?>%</span>
                <?php endif; ?>
            </div>
        </div>
        <div style="text-align:right;flex-shrink:0">
            <div style="font-weight:800;font-size:13px;color:<?=$saldado?'var(--success)':'var(--danger)'?>">$ <?=number_format(max(0,$saldo),2,'.',',')?></div>
            <div style="font-size:10px;color:var(--text-muted)"><?=$saldado?'Saldado':'Pendiente'?></div>
        </div>
    </a>
    <?php endforeach; ?>
</div>
</div>

<!-- DETALLE -->
<div>
<?php if($cliData && $statsCliente): ?>

<div class="card mb-3">
    <div class="card-header" style="justify-content:space-between;flex-wrap:wrap;gap:8px">
        <div>
            <h5 style="margin:0"><?=clean($cliData['nombre'])?></h5>
            <?php if(!empty($cliData['rif'])): ?><span style="font-size:12px;color:var(--text-muted)">RIF: <?=clean($cliData['rif'])?></span><?php endif; ?>
            <?php if(!empty($cliData['telefono'])): ?><span style="font-size:12px;color:var(--text-muted);margin-left:12px"><i class="fa-solid fa-phone"></i> <?=clean($cliData['telefono'])?></span><?php endif; ?>
        </div>
        <div class="d-flex gap-2 align-items-center flex-wrap">
            <?php if($statsCliente['desc_pct'] > 0): ?>
            <span class="desc-badge"><i class="fa-solid fa-tag"></i> Desc activo <?=$statsCliente['desc_pct']?>%</span>
            <?php endif; ?>
            <?php if(hasPermission('pagos','crear')): ?>
            <button onclick="openModal('modalDescuento')" class="btn btn-sm btn-warning"><i class="fa-solid fa-tag"></i> Desc. ref.</button>
            <?php if($statsCliente['pendientes'] > 0): ?>
            <button onclick="openModal('modalGrupo')" class="btn btn-sm btn-primary"><i class="fa-solid fa-layer-group"></i> Pago Grupal</button>
            <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>
    <div class="card-body" style="padding:14px">
        <div class="stats-row" style="margin-bottom:0">
            <div class="stat-box" style="border-top:3px solid var(--success)">
                <div class="stat-val" style="color:var(--success)">$ <?=number_format($statsCliente['total_fact'],2,'.',',')?></div>
                <div class="stat-lbl">Total Facturado</div>
            </div>
            <div class="stat-box" style="border-top:3px solid var(--info)">
                <div class="stat-val" style="color:var(--info)">$ <?=number_format($statsCliente['total_pagado'],2,'.',',')?></div>
                <div class="stat-lbl">Total Abonado</div>
            </div>
            <div class="stat-box" style="border-top:3px solid <?=$statsCliente['total_saldo']>0?'var(--danger)':'var(--success)'?>">
                <div class="stat-val" style="color:<?=$statsCliente['total_saldo']>0?'var(--danger)':'var(--success)'?>">$ <?=number_format($statsCliente['total_saldo'],2,'.',',')?></div>
                <div class="stat-lbl">Saldo</div>
            </div>
            <div class="stat-box" style="border-top:3px solid var(--primary)">
                <div class="stat-val" style="color:var(--primary)"><?=(int)$statsCliente['pendientes']?></div>
                <div class="stat-lbl">Facturas Pendientes</div>
            </div>
        </div>
    </div>
</div>

<!-- Tabs -->
<div class="tabs">
    <button class="tab-btn active" data-tab="tab-facturas" onclick="switchTab('tab-facturas')"><i class="fa-solid fa-file-invoice"></i> Facturas</button>
    <button class="tab-btn" data-tab="tab-historial" onclick="switchTab('tab-historial')"><i class="fa-solid fa-history"></i> Historial de Cobros</button>
</div>

<!-- TAB FACTURAS -->
<div id="tab-facturas" class="tab-content">
<?php if(empty($facturas)): ?>
<div class="card"><div class="card-body" style="text-align:center;padding:32px;color:var(--text-muted)"><p>Sin facturas registradas.</p></div></div>
<?php else: ?>
<?php foreach($facturas as $f):
    $saldo   = (float)$f['saldo_usd'];
    $vencido = !empty($f['fecha_vencimiento']) && $f['fecha_vencimiento'] < date('Y-m-d') && $f['estado']!=='pagado';
    $eClass  = $vencido ? 'be-vencido' : match($f['estado']){'pagado'=>'be-pagado','parcial'=>'be-parcial',default=>'be-pendiente'};
    $eLabel  = $vencido ? 'âš  VENCIDA' : match($f['estado']){'pagado'=>'âœ… Pagada','parcial'=>'â³ Parcial',default=>'ðŸ• Pendiente'};
    $historial = $pFacts[$f['id']] ?? [];
    $desc      = $descuentosPorVenta[(int)$f['id']] ?? null; // â•â•â• NUEVO â•â•â•
?>
<div class="fact-card <?=$desc?'con-desc':''?>" data-fact-id="<?=$f['id']?>">
    <div class="fact-header">
        <input type="checkbox" class="check-fact" data-fact-id="<?=$f['id']?>" <?=$f['estado']==='pagado'?'disabled':''?> onchange="toggleFact(this)">
        <div style="flex:1">
            <span style="font-weight:800;font-size:14px;color:var(--primary)">
                <a href="ver_venta.php?id=<?=$f['id']?>" style="color:var(--primary)"><?=clean($f['numero'])?></a>
            </span>
            <span class="badge-estado <?=$eClass?>" style="margin-left:8px"><?=$eLabel?></span>
            <?php if($desc): // â•â•â• NUEVO â•â•â• Aviso de descuento ?>
            <span class="badge-desc" style="margin-left:6px" title="Descuento aplicado: -$<?=number_format($desc['desc'],2)?> (bruto $<?=number_format($desc['bruto'],2)?> â†’ neto $<?=number_format($desc['neto'],2)?>)">
                ðŸ·ï¸ <?=$desc['pct']?>% desc.
            </span>
            <?php endif; ?>
            <?php if($f['tipo_pago']==='credito'): ?><span style="font-size:11px;color:var(--text-muted);margin-left:6px">CrÃ©dito</span><?php endif; ?>
        </div>
        <div style="font-size:12px;color:var(--text-muted)">
            <?=date('d/m/Y',strtotime($f['fecha']))?>
            <?php if($f['fecha_vencimiento']): ?> Â· Vence: <strong style="color:<?=$vencido?'var(--danger)':'inherit'?>"><?=date('d/m/Y',strtotime($f['fecha_vencimiento']))?></strong><?php endif; ?>
        </div>
    </div>
    <div class="fact-body">
        <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:10px;margin-bottom:10px;text-align:center">
            <div><div style="font-size:11px;color:var(--text-muted)">Total</div><div style="font-weight:800;font-size:15px">$ <?=number_format($f['total_usd'],2,'.',',')?></div></div>
            <div><div style="font-size:11px;color:var(--text-muted)">Abonado</div><div style="font-weight:800;font-size:15px;color:var(--success)">$ <?=number_format($f['monto_pagado_usd'],2,'.',',')?></div></div>
            <div><div style="font-size:11px;color:var(--text-muted)">Saldo</div><div style="font-weight:800;font-size:15px;color:<?=$saldo>0?'var(--danger)':'var(--success)'?>">$ <?=number_format(max(0,$saldo),2,'.',',')?></div></div>
        </div>
        <?php if($desc): // â•â•â• NUEVO â•â•â• Aviso detallado del descuento ?>
        <div class="desc-info">
            <i class="fa-solid fa-tag"></i>
            <span>
                <strong>Descuento <?=$desc['pct']?>% aplicado</strong>:
                bruto <strong>$<?=number_format($desc['bruto'],2)?></strong> âˆ’
                <strong style="color:#dc2626">$<?=number_format($desc['desc'],2)?></strong> =
                neto <strong style="color:#16a34a">$<?=number_format($desc['neto'],2)?></strong>
            </span>
        </div>
        <?php endif; ?>
        <?php $pct = $f['total_usd'] > 0 ? min(100,round($f['monto_pagado_usd']/$f['total_usd']*100,1)) : 0; ?>
        <div style="background:#e9ecef;border-radius:10px;height:6px;margin-top:10px;margin-bottom:6px;overflow:hidden">
            <div style="background:<?=$pct>=100?'var(--success)':'var(--primary)'?>;height:100%;width:<?=$pct?>%;transition:.3s"></div>
        </div>
        <div style="font-size:11px;color:var(--text-muted);text-align:right;margin-bottom:10px"><?=$pct?>% cobrado</div>
        <?php if($f['estado']!=='pagado' && hasPermission('pagos','crear')): ?>
        <button onclick="openAbonoModal(<?=$f['id']?>,'<?=addslashes(clean($f['numero']))?>',<?=round($saldo,2)?>)" class="btn btn-success btn-sm w-100">
            <i class="fa-solid fa-plus"></i> Registrar Abono
        </button>
        <?php endif; ?>
    </div>
    <?php if(!empty($historial)): ?>
    <div class="fact-pagos">
        <div style="font-size:11px;font-weight:700;color:var(--success);margin-bottom:6px;text-transform:uppercase;letter-spacing:.5px"><i class="fa-solid fa-history"></i> Abonos</div>
        <?php foreach($historial as $pago):
            $ico = match($pago['tipo']){'efectivo'=>'ðŸ’µ','transferencia'=>'ðŸ¦','punto'=>'ðŸ’³','zelle'=>'âš¡','binance'=>'ðŸŸ¡','wally'=>'ðŸ“±','zinli'=>'ðŸ’œ',default=>'ðŸ”µ'};
            $anulado = (int)($pago['anulado'] ?? 0) === 1;
        ?>
        <div class="pago-row <?=$anulado?'anulado':''?>">
            <span style="font-size:16px"><?=$ico?></span>
            <span><?=date('d/m/Y',strtotime($pago['fecha']))?></span>
            <span style="background:#d1e7dd;color:#0f5132;padding:1px 6px;border-radius:8px;font-size:10px;font-weight:700"><?=strtoupper($pago['tipo'])?></span>
            <?php if(!empty($pago['referencia'])): ?><span style="color:var(--text-muted)">Ref: <?=clean($pago['referencia'])?></span><?php endif; ?>
            <?php if(!empty($pago['grupo_ref'])): ?><span style="background:#e0f2fe;color:#0369a1;padding:1px 6px;border-radius:8px;font-size:10px">GRUPO</span><?php endif; ?>
            <span style="font-weight:800;color:var(--success)">$ <?=number_format($pago['monto_usd'],2,'.',',')?></span>
            <?php if(!$anulado): ?>
            <div class="pago-acciones">
                <button class="btn-icon-sm" title="Editar" onclick='openEditAbono(<?=json_encode([
                    "id"=>(int)$pago['id'],"venta_id"=>(int)$pago['venta_id'],
                    "monto"=>(float)$pago['monto_usd'],"tipo"=>$pago['tipo'],
                    "referencia"=>$pago['referencia'] ?? "","fecha"=>$pago['fecha'],
                    "notas"=>$pago['notas'] ?? "","factura"=>$f['numero'],
                    "cli_id"=>$cliSel
                ], JSON_HEX_APOS|JSON_HEX_QUOT)?>)'><i class="fa-solid fa-pen"></i></button>
                <form method="POST" style="display:inline" onsubmit="return confirm('Â¿Anular este abono de \$<?=number_format((float)$pago['monto_usd'],2)?>? Se recalcularÃ¡ el saldo.')">
                    <input type="hidden" name="csrf_token" value="<?=getCsrfToken()?>">
                    <input type="hidden" name="action" value="anular_abono">
                    <input type="hidden" name="pago_id" value="<?=$pago['id']?>">
                    <input type="hidden" name="cliente_id" value="<?=$cliSel?>">
                    <button type="submit" class="btn-icon-sm danger" title="Anular"><i class="fa-solid fa-trash"></i></button>
                </form>
            </div>
            <?php else: ?>
            <span style="margin-left:auto;font-size:10px;color:var(--danger);font-weight:700">ANULADO</span>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</div>
<?php endforeach; ?>
<?php endif; ?>
</div>

<!-- TAB HISTORIAL GLOBAL -->
<div id="tab-historial" class="tab-content" style="display:none">
<div class="card">
    <div class="card-header"><h5><i class="fa-solid fa-history"></i> Historial de Cobros del Cliente</h5></div>
    <div class="card-body" style="padding:0">
        <?php if(empty($historialGlobal)): ?>
        <div style="padding:32px;text-align:center;color:var(--text-muted)">Sin cobros registrados.</div>
        <?php else: ?>
        <table class="table" style="margin:0">
            <thead><tr><th>Fecha</th><th>Factura</th><th>Tipo</th><th>Grupo</th><th>Referencia</th><th style="text-align:right">Monto</th><th>Estado</th></tr></thead>
            <tbody>
            <?php foreach($historialGlobal as $h): $an = (int)($h['anulado'] ?? 0)===1; ?>
            <tr style="<?=$an?'opacity:.5;text-decoration:line-through':''?>">
                <td><?=date('d/m/Y',strtotime($h['fecha']))?></td>
                <td><strong><?=clean($h['factura_numero'])?></strong></td>
                <td><?=strtoupper($h['tipo'])?></td>
                <td><?php if(!empty($h['grupo_ref'])): ?><span style="background:#e0f2fe;color:#0369a1;padding:1px 6px;border-radius:8px;font-size:10px">GRUPO</span><?php endif; ?></td>
                <td><?=clean($h['referencia'] ?? 'â€”')?></td>
                <td style="text-align:right;font-weight:700;color:var(--success)">$ <?=number_format($h['monto_usd'],2,'.',',')?></td>
                <td><?=$an?'<span style="color:var(--danger);font-weight:700">ANULADO</span>':'<span style="color:var(--success);font-weight:700">VIGENTE</span>'?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>
</div>
</div>

<?php else: ?>
<div class="card"><div class="card-body" style="text-align:center;padding:64px;color:var(--text-muted)">
    <i class="fa-solid fa-hand-pointer" style="font-size:48px;opacity:.2"></i>
    <h3 style="margin-top:16px">Selecciona un cliente</h3>
</div></div>
<?php endif; ?>
</div>
</div>
<?php endif; ?>

<!-- MODAL ABONO -->
<div class="modal-overlay" id="modalAbono">
    <div class="modal-box" style="max-width:460px">
        <div class="modal-header">
            <h4 id="modalAbonoTitle"><i class="fa-solid fa-hand-holding-dollar"></i> Registrar Abono</h4>
            <button onclick="closeModal('modalAbono')" class="btn btn-light btn-sm btn-icon">âœ•</button>
        </div>
        <form method="POST">
        <input type="hidden" name="csrf_token" value="<?=getCsrfToken()?>">
        <input type="hidden" name="action" value="registrar_abono">
        <input type="hidden" name="cliente_id" value="<?=$cliSel?>">
        <input type="hidden" name="venta_id" id="abonoVentaId" value="">
        <div class="modal-body" style="display:grid;gap:12px">
            <div style="background:#f0fdf4;border:1px solid #bbf7d0;border-radius:8px;padding:10px;font-size:12px;display:grid;grid-template-columns:1fr 1fr;gap:8px;text-align:center">
                <div><div id="abonoSaldoLabel" style="font-weight:800;font-size:16px;color:var(--danger)">$0.00</div><div style="color:var(--text-muted)">Saldo</div></div>
                <div><div id="abonoNumLabel" style="font-weight:800;font-size:16px;color:var(--primary)">â€”</div><div style="color:var(--text-muted)">Factura</div></div>
            </div>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
                <div class="form-group"><label class="form-label">Monto (USD) *</label><input type="number" name="monto" id="abonoMonto" class="form-control" step="0.01" min="0.01" required></div>
                <div class="form-group"><label class="form-label">Fecha *</label><input type="date" name="fecha" class="form-control" value="<?=date('Y-m-d')?>" required></div>
            </div>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
                <div class="form-group"><label class="form-label">Tipo *</label><select name="tipo" class="form-select" required><?php foreach($TIPOS_PAGO as $t): ?><option value="<?=$t?>"><?=ucfirst($t)?></option><?php endforeach; ?></select></div>
                <div class="form-group"><label class="form-label">Referencia</label><input type="text" name="referencia" class="form-control" placeholder="NÂ° operaciÃ³n..."></div>
            </div>
            <div class="form-group"><label class="form-label">Notas</label><input type="text" name="notas" class="form-control" placeholder="Observaciones..."></div>
        </div>
        <div class="modal-footer">
            <button type="button" onclick="closeModal('modalAbono')" class="btn btn-light">Cancelar</button>
            <button type="submit" class="btn btn-success"><i class="fa-solid fa-check"></i> Registrar</button>
        </div>
        </form>
    </div>
</div>

<!-- MODAL EDITAR ABONO -->
<div class="modal-overlay" id="modalEditAbono">
    <div class="modal-box" style="max-width:460px">
        <div class="modal-header">
            <h4><i class="fa-solid fa-pen"></i> Editar Abono <span id="editAbonoFactura" style="font-size:12px;color:var(--text-muted)"></span></h4>
            <button onclick="closeModal('modalEditAbono')" class="btn btn-light btn-sm btn-icon">âœ•</button>
        </div>
        <form method="POST">
        <input type="hidden" name="csrf_token" value="<?=getCsrfToken()?>">
        <input type="hidden" name="action" value="editar_abono">
        <input type="hidden" name="cliente_id" id="editAbonoCli" value="">
        <input type="hidden" name="pago_id" id="editAbonoPagoId" value="">
        <div class="modal-body" style="display:grid;gap:12px">
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
                <div class="form-group"><label class="form-label">Monto (USD) *</label><input type="number" name="monto" id="editAbonoMonto" class="form-control" step="0.01" min="0.01" required></div>
                <div class="form-group"><label class="form-label">Fecha *</label><input type="date" name="fecha" id="editAbonoFecha" class="form-control" required></div>
            </div>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
                <div class="form-group"><label class="form-label">Tipo *</label><select name="tipo" id="editAbonoTipo" class="form-select" required><?php foreach($TIPOS_PAGO as $t): ?><option value="<?=$t?>"><?=ucfirst($t)?></option><?php endforeach; ?></select></div>
                <div class="form-group"><label class="form-label">Referencia</label><input type="text" name="referencia" id="editAbonoRef" class="form-control"></div>
            </div>
            <div class="form-group"><label class="form-label">Notas</label><input type="text" name="notas" id="editAbonoNotas" class="form-control"></div>
            <div style="background:#fef3c7;border:1px solid #fcd34d;border-radius:6px;padding:8px;font-size:11px;color:#92400e">
                <i class="fa-solid fa-info-circle"></i> Al guardar se recalcularÃ¡ el saldo y estado de la factura.
            </div>
        </div>
        <div class="modal-footer">
            <button type="button" onclick="closeModal('modalEditAbono')" class="btn btn-light">Cancelar</button>
            <button type="submit" class="btn btn-primary"><i class="fa-solid fa-check"></i> Guardar cambios</button>
        </div>
        </form>
    </div>
</div>

<?php if($cliData && $statsCliente): ?>
<!-- MODAL PAGO GRUPAL -->
<div class="modal-overlay" id="modalGrupo">
    <div class="modal-box" style="max-width:560px">
        <div class="modal-header">
            <h4><i class="fa-solid fa-layer-group"></i> Pago Grupal FIFO â€” <?=clean($cliData['nombre'])?></h4>
            <button onclick="closeModal('modalGrupo')" class="btn btn-light btn-sm btn-icon">âœ•</button>
        </div>
        <form method="POST">
        <input type="hidden" name="csrf_token" value="<?=getCsrfToken()?>">
        <input type="hidden" name="action" value="pago_grupo">
        <input type="hidden" name="cliente_id" value="<?=$cliSel?>">
        <div class="modal-body" style="display:grid;gap:12px">
            <div style="background:#eff6ff;border:1px solid #bfdbfe;border-radius:8px;padding:10px;font-size:12px;color:#1e40af">
                <i class="fa-solid fa-info-circle"></i>
                <strong>Pago FIFO:</strong> el monto se distribuye empezando por la factura mÃ¡s antigua.
                Los saldos ya incluyen el descuento aplicado (no se vuelve a descontar).
            </div>

            <!-- Facturas afectadas -->
            <div style="background:#f8fafc;border:1px solid var(--border);border-radius:8px;padding:10px;max-height:180px;overflow-y:auto">
                <div style="font-size:11px;font-weight:700;text-transform:uppercase;color:var(--text-muted);margin-bottom:8px">Facturas pendientes (FIFO)</div>
                <?php foreach($facturas as $f): if($f['estado']==='pagado') continue; ?>
                <div style="display:flex;justify-content:space-between;padding:4px 0;border-bottom:1px dashed var(--border);font-size:12px">
                    <span>
                        <strong><?=clean($f['numero'])?></strong> Â· <?=date('d/m/Y',strtotime($f['fecha']))?>
                        <?php if(isset($descuentosPorVenta[(int)$f['id']])): ?>
                        <span class="badge-desc" style="margin-left:4px">ðŸ·ï¸ <?=$descuentosPorVenta[(int)$f['id']]['pct']?>%</span>
                        <?php endif; ?>
                    </span>
                    <span style="color:var(--danger);font-weight:700">$ <?=number_format(max(0,$f['saldo_usd']),2,'.',',')?></span>
                </div>
                <?php endforeach; ?>
                <div style="display:flex;justify-content:space-between;margin-top:6px;font-weight:800;font-size:13px">
                    <span>Saldo total (neto)</span>
                    <span style="color:var(--danger)">$ <?=number_format(max(0,$statsCliente['total_saldo']),2,'.',',')?></span>
                </div>
            </div>

            <!-- Monto y demÃ¡s -->
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
                <div class="form-group">
                    <label class="form-label">Monto total a cobrar (USD) *</label>
                    <input type="number" name="monto" class="form-control" step="0.01" min="0.01"
                           value="<?=number_format(max(0,$statsCliente['total_saldo']),2,'.','')?>" required>
                    <small style="color:var(--text-muted)">Saldo neto actual</small>
                </div>
                <div class="form-group">
                    <label class="form-label">Fecha *</label>
                    <input type="date" name="fecha" class="form-control" value="<?=date('Y-m-d')?>" required>
                </div>
            </div>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
                <div class="form-group"><label class="form-label">Tipo *</label><select name="tipo" class="form-select" required><?php foreach($TIPOS_PAGO as $t): ?><option value="<?=$t?>"><?=ucfirst($t)?></option><?php endforeach; ?></select></div>
                <div class="form-group"><label class="form-label">Referencia</label><input type="text" name="referencia" class="form-control"></div>
            </div>
            <div class="form-group"><label class="form-label">Notas</label><input type="text" name="notas" class="form-control" placeholder="Ej: Pago total..."></div>
        </div>
        <div class="modal-footer">
            <button type="button" onclick="closeModal('modalGrupo')" class="btn btn-light">Cancelar</button>
            <button type="submit" class="btn btn-primary"><i class="fa-solid fa-layer-group"></i> Aplicar Pago Grupal</button>
        </div>
        </form>
    </div>
</div>

<!-- MODAL DESCUENTO REFERENCIA -->
<div class="modal-overlay" id="modalDescuento">
    <div class="modal-box" style="max-width:440px">
        <div class="modal-header">
            <h4><i class="fa-solid fa-tag"></i> Descuento â€” <?=clean($cliData['nombre'])?></h4>
            <button onclick="closeModal('modalDescuento')" class="btn btn-light btn-sm btn-icon">âœ•</button>
        </div>
        <form method="POST">
        <input type="hidden" name="csrf_token" value="<?=getCsrfToken()?>">
        <input type="hidden" name="action" value="guardar_descuento">
        <input type="hidden" name="cliente_id" value="<?=$cliSel?>">
        <div class="modal-body" style="display:grid;gap:12px">
            <div style="background:#fffbeb;border:1px solid #fde68a;border-radius:8px;padding:10px;font-size:12px;color:#856404">
                <i class="fa-solid fa-info-circle"></i>
                Al guardar, el % se aplicarÃ¡ <strong>a las facturas que aÃºn no tengan descuento</strong>.
                Las facturas nuevas que entren despuÃ©s tambiÃ©n lo recibirÃ¡n automÃ¡ticamente.
                Para cambiar el % de las que ya lo tienen, marcÃ¡ â€œRecalcularâ€ abajo.
            </div>
            <div class="form-group">
                <label class="form-label">% Descuento *</label>
                <input type="number" name="descuento_pct" id="descPctInput" class="form-control" min="0" max="100" step="0.01"
                       value="<?=number_format($cliData['descuento_pct']??0,2,'.','')?>"
                       oninput="calcDescPreview()" required>
            </div>
            <div id="descPreview" style="background:#f5f3ff;border:1px solid #ddd6fe;border-radius:8px;padding:10px;font-size:12px;color:#5b21b6;line-height:1.6"></div>
            <div class="form-group" style="display:flex;align-items:center;gap:8px">
                <input type="checkbox" name="aplicar_ahora" id="aplicarAhoraChk" value="1" checked style="width:18px;height:18px">
                <label for="aplicarAhoraChk" style="font-weight:600;font-size:13px;cursor:pointer">
                    Aplicar ahora a facturas pendientes
                </label>
            </div>
            <?php if ($descRecalc > 0): ?>
            <div class="form-group" style="display:flex;align-items:flex-start;gap:8px;background:#fef2f2;border:1px solid #fecaca;border-radius:8px;padding:9px 10px">
                <input type="checkbox" name="recalcular_todas" id="recalcularTodasChk" value="1"
                       style="width:18px;height:18px;margin-top:2px" onchange="calcDescPreview()">
                <label for="recalcularTodasChk" style="font-weight:700;font-size:12px;cursor:pointer;color:#991b1b">
                    Recalcular las <?= (int)$descRecalc ?> factura(s) que ya tienen descuento
                    <span style="display:block;font-weight:400;font-size:11px;color:#b91c1c;margin-top:2px;line-height:1.4">
                        Les quita el % anterior y les pone este. Si ponÃ©s 0%, se les quita el descuento.
                        No afecta facturas anuladas ni las que ya estÃ¡n pagadas.
                    </span>
                </label>
            </div>
            <?php endif; ?>
            <div class="form-group">
                <label class="form-label">Notas</label>
                <textarea name="notas" class="form-control" rows="2"><?=clean($cliData['desc_notas']??'')?></textarea>
            </div>
        </div>
        <div class="modal-footer">
            <button type="button" onclick="closeModal('modalDescuento')" class="btn btn-light">Cancelar</button>
            <button type="submit" class="btn btn-warning"><i class="fa-solid fa-check"></i> Guardar y aplicar</button>
        </div>
        </form>
    </div>
</div>
<?php endif; ?>

<?php
// â•â•â• NUEVO â•â•â• Datos JS para preview del descuento
$facturasSinDescJs = [];
$facturasConDescJs = [];
foreach ($facturas as $f) {
    $fid = (int)$f['id'];
    $conDesc = isset($descuentosPorVenta[$fid]);
    if ($conDesc && $f['estado'] !== 'pagado') {
        // Ya tiene descuento: el bruto original estÃ¡ guardado en cobros_descuentos
        $facturasConDescJs[] = ['numero'=>$f['numero'], 'bruto'=>(float)$descuentosPorVenta[$fid]['bruto']];
        continue;
    }
    if ($conDesc || $f['estado'] === 'pagado') continue;
    $facturasSinDescJs[] = ['numero'=>$f['numero'], 'total'=>(float)$f['total_usd']];
}
$extraScript = '<script>
const FACTS_SIN_DESC = ' . json_encode($facturasSinDescJs) . ';
const FACTS_CON_DESC = ' . json_encode($facturasConDescJs) . ';

function openAbonoModal(ventaId, numero, saldo) {
    document.getElementById("abonoVentaId").value = ventaId;
    document.getElementById("abonoNumLabel").textContent  = numero;
    document.getElementById("abonoSaldoLabel").textContent = "$ " + saldo.toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ",");
    document.getElementById("abonoMonto").value = saldo.toFixed(2);
    document.getElementById("abonoMonto").max   = saldo.toFixed(2);
    document.getElementById("modalAbonoTitle").innerHTML =
        \'<i class="fa-solid fa-hand-holding-dollar"></i> Abono â€” \' + numero;
    openModal("modalAbono");
}

function openEditAbono(data) {
    document.getElementById("editAbonoPagoId").value = data.id;
    document.getElementById("editAbonoCli").value    = data.cli_id;
    document.getElementById("editAbonoMonto").value  = data.monto.toFixed(2);
    document.getElementById("editAbonoFecha").value  = data.fecha;
    document.getElementById("editAbonoTipo").value   = data.tipo;
    document.getElementById("editAbonoRef").value    = data.referencia || "";
    document.getElementById("editAbonoNotas").value  = data.notas || "";
    document.getElementById("editAbonoFactura").textContent = "â€” " + data.factura;
    openModal("modalEditAbono");
}

// â•â•â• NUEVO â•â•â• Preview del descuento al escribir el %
function calcDescPreview() {
    const pct    = parseFloat(document.getElementById("descPctInput").value) || 0;
    const chkRec = document.getElementById("recalcularTodasChk");
    const recalc = !!(chkRec && chkRec.checked);
    const prev   = document.getElementById("descPreview");
    let html = "";

    if (pct > 0 && FACTS_SIN_DESC.length) {
        let totalDesc = 0;
        html += "<strong>Se aplicarÃ¡ " + pct + "% a las que no tienen descuento:</strong><br>";
        FACTS_SIN_DESC.forEach(f => {
            const d = f.total * pct / 100;
            const n = f.total - d;
            totalDesc += d;
            html += "â€¢ " + f.numero + ": $" + f.total.toFixed(2) + " â†’ <strong style=\'color:#16a34a\'>$" + n.toFixed(2) + "</strong> <span style=\'color:#dc2626\'>(âˆ’$" + d.toFixed(2) + ")</span><br>";
        });
        html += "<strong style=\'display:block;margin-top:6px;border-top:1px solid #ddd6fe;padding-top:6px\'>Total a descontar: <span style=\'color:#dc2626\'>âˆ’$" + totalDesc.toFixed(2) + "</span></strong>";
    }

    if (recalc && FACTS_CON_DESC.length) {
        html += "<strong style=\'display:block;margin-top:10px\'>" +
                (pct > 0 ? "Se recalcularÃ¡n al " + pct + "% (ya tienen descuento):"
                         : "Se les quitarÃ¡ el descuento (vuelven al bruto):") +
                "</strong><br>";
        FACTS_CON_DESC.forEach(f => {
            const n = f.bruto * (1 - pct / 100);
            const d = f.bruto - n;
            html += "â€¢ " + f.numero + ": bruto $" + f.bruto.toFixed(2) + " â†’ <strong style=\'color:#16a34a\'>$" + n.toFixed(2) + "</strong> <span style=\'color:#dc2626\'>(âˆ’$" + d.toFixed(2) + ")</span><br>";
        });
    }

    if (!html) {
        if (pct <= 0) html = "Sin descuento.";
        else if (!FACTS_SIN_DESC.length) html = "No hay facturas pendientes de descuento (todas ya lo tienen). MarcÃ¡ â€œRecalcularâ€ para cambiarles el %.";
        else html = "";
    }
    prev.innerHTML = html;
}

function toggleFact(cb) {
    const card = cb.closest(".fact-card");
    if (cb.checked) card.classList.add("sel"); else card.classList.remove("sel");
}

function switchTab(id) {
    document.querySelectorAll(".tab-content").forEach(el => el.style.display = "none");
    document.querySelectorAll(".tab-btn").forEach(el => el.classList.remove("active"));
    document.getElementById(id).style.display = "block";
    document.querySelector(\'[data-tab="\' + id + \'"]\').classList.add("active");
}

document.addEventListener("DOMContentLoaded", () => {
    if (document.getElementById("descPctInput")) calcDescPreview();
});
</script>';
include 'includes/footer.php';