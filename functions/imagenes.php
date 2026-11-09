<?php
/**
 * Imágenes de productos — Tauro
 * ---------------------------------------------------------------
 * Política de calidad:
 *  - El archivo ORIGINAL se guarda tal cual (sin recomprimir) → máxima
 *    calidad para verlo/ampliarlo en pantalla.
 *  - Se genera una copia optimizada para la lista de precios PDF
 *    (900 px de lado mayor, JPEG 90). En una celda de 27 mm eso equivale
 *    a ~850 ppp: más que suficiente para impresión y con un peso razonable
 *    para enviar el PDF por WhatsApp o correo.
 *
 * Depende de: config/config.php (UPLOADS_PATH, UPLOADS_URL) y functions/db.php.
 */

const PRODUCTO_IMG_MAX_BYTES     = 12582912; // 12 MB
const PRODUCTO_IMG_MAX_LADO      = 9000;     // px por lado
const PRODUCTO_IMG_MAX_PIXELES   = 40000000; // 40 MP
const PRODUCTO_IMG_PRINT_LADO    = 900;      // px lado mayor (copia para PDF)
const PRODUCTO_IMG_PRINT_CALIDAD = 90;       // calidad JPEG 0-100
const PRODUCTO_IMG_THUMB_LADO    = 240;      // px lado mayor (miniatura de tabla)
const PRODUCTO_IMG_THUMB_CALIDAD = 82;
const PRODUCTO_IMG_DIR           = 'productos';
const PRODUCTO_IMG_PRINT_DIR     = 'productos/min';
const PRODUCTO_IMG_THUMB_DIR     = 'productos/thumb';

/**
 * Agrega la columna `imagen` a `productos` si todavía no existe.
 * Permite desplegar solo los archivos sin ejecutar SQL a mano.
 * Si el usuario de BD no tiene permiso de ALTER devuelve false.
 */
function asegurarColumnaImagenProductos(): bool
{
    static $estado = null;
    if ($estado !== null) return $estado;

    try {
        $col = dbQueryOne(
            "SELECT 1
               FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME   = 'productos'
                AND COLUMN_NAME  = 'imagen'
              LIMIT 1"
        );
        if (!$col) {
            // ALTER no se ejecuta con prepared statements: se envía tal cual.
            getDB()->exec(
                "ALTER TABLE productos
                 ADD COLUMN imagen VARCHAR(255) NULL DEFAULT NULL AFTER descripcion"
            );
        }
        $estado = true;
    } catch (Throwable $e) {
        // Puede fallar el ALTER por permisos o por una ejecución simultánea:
        // si la columna ya existe, el sistema funciona igual.
        try {
            $estado = (bool)dbQueryOne(
                "SELECT 1
                   FROM information_schema.COLUMNS
                  WHERE TABLE_SCHEMA = DATABASE()
                    AND TABLE_NAME   = 'productos'
                    AND COLUMN_NAME  = 'imagen'
                  LIMIT 1"
            );
        } catch (Throwable $e2) {
            $estado = false;
        }
        if (!$estado) error_log('Tauro · productos.imagen: ' . $e->getMessage());
    }
    return $estado;
}

/**
 * Procesa la subida de una foto de producto.
 * Devuelve: ['ok'=>bool, 'ruta'=>string, 'print'=>string, 'error'=>string, ...]
 */
function subirImagenProducto(string $input = 'imagen'): array
{
    $f = $_FILES[$input] ?? null;

    if (!$f || !is_array($f) || (int)($f['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return ['ok' => false, 'error' => 'No se seleccionó ninguna imagen.'];
    }

    $err = (int)($f['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($err !== UPLOAD_ERR_OK) {
        $msg = match ($err) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE =>
                'La imagen supera el límite del servidor (' . ini_get('upload_max_filesize') . ').',
            UPLOAD_ERR_PARTIAL    => 'La carga se interrumpió. Intenta de nuevo.',
            UPLOAD_ERR_NO_TMP_DIR => 'El servidor no tiene carpeta temporal configurada.',
            UPLOAD_ERR_CANT_WRITE => 'El servidor no pudo escribir la imagen en disco.',
            UPLOAD_ERR_EXTENSION  => 'Una extensión del servidor bloqueó la carga.',
            default               => 'Error desconocido al subir la imagen (código ' . $err . ').',
        };
        return ['ok' => false, 'error' => $msg];
    }

    $peso = (int)($f['size'] ?? 0);
    if ($peso <= 0) {
        return ['ok' => false, 'error' => 'El archivo está vacío.'];
    }
    if ($peso > PRODUCTO_IMG_MAX_BYTES) {
        return ['ok' => false, 'error' => 'La imagen supera el máximo de 12 MB.'];
    }
    if (!is_uploaded_file($f['tmp_name'])) {
        return ['ok' => false, 'error' => 'Archivo inválido.'];
    }

    $info = @getimagesize($f['tmp_name']);
    if (!$info) {
        return ['ok' => false, 'error' => 'El archivo no es una imagen válida.'];
    }

    $w    = (int)$info[0];
    $h    = (int)$info[1];
    $mime = $info['mime'] ?? '';
    $exts = ['image/jpeg' => 'jpg', 'image/png' => 'png'];

    if (!isset($exts[$mime])) {
        return ['ok' => false, 'error' => 'Formato no permitido. Usa JPG o PNG.'];
    }
    if ($w < 200 || $h < 200) {
        return ['ok' => false, 'error' => 'La imagen es muy pequeña (mínimo 200×200 px).'];
    }
    if ($w > PRODUCTO_IMG_MAX_LADO || $h > PRODUCTO_IMG_MAX_LADO || ($w * $h) > PRODUCTO_IMG_MAX_PIXELES) {
        return ['ok' => false, 'error' => 'La imagen es demasiado grande (máximo 40 megapíxeles).'];
    }

    @ini_set('memory_limit', '512M');

    $ext = $exts[$mime];
    $dir = UPLOADS_PATH . '/' . PRODUCTO_IMG_DIR;

    if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
        return ['ok' => false, 'error' => 'No se pudo crear la carpeta de imágenes.'];
    }

    // Comprobación real de escritura (is_writable() falla en algunos hostings Windows).
    $prueba = @tempnam($dir, '.wtest');
    if ($prueba === false) {
        return ['ok' => false, 'error' => 'La carpeta de imágenes no tiene permisos de escritura.'];
    }
    @unlink($prueba);

    $base = 'prd_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4));
    $arch = $base . '.' . $ext;

    if (!move_uploaded_file($f['tmp_name'], $dir . '/' . $arch)) {
        return ['ok' => false, 'error' => 'No se pudo guardar la imagen en el servidor.'];
    }
    @chmod($dir . '/' . $arch, 0644);

    $rutaAbs  = $dir . '/' . $arch;
    $printAbs = UPLOADS_PATH . '/' . PRODUCTO_IMG_PRINT_DIR . '/' . $base . '.jpg';
    $thumbAbs = UPLOADS_PATH . '/' . PRODUCTO_IMG_THUMB_DIR . '/' . $base . '.jpg';

    $printOk = generarImagenImpresion($rutaAbs, $printAbs);
    // La miniatura se saca de la copia ya reducida (más rápido) o del original.
    $thumbOk = generarImagenImpresion(
        $printOk ? $printAbs : $rutaAbs,
        $thumbAbs,
        PRODUCTO_IMG_THUMB_LADO,
        PRODUCTO_IMG_THUMB_CALIDAD
    );

    if ($printOk) @chmod($printAbs, 0644);
    if ($thumbOk) @chmod($thumbAbs, 0644);

    return [
        'ok'    => true,
        'ruta'  => PRODUCTO_IMG_DIR . '/' . $arch,
        'print' => $printOk ? PRODUCTO_IMG_PRINT_DIR . '/' . $base . '.jpg' : PRODUCTO_IMG_DIR . '/' . $arch,
        'thumb' => $thumbOk ? PRODUCTO_IMG_THUMB_DIR . '/' . $base . '.jpg' : PRODUCTO_IMG_DIR . '/' . $arch,
        'ancho' => $w,
        'alto'  => $h,
        'peso'  => $peso,
        'mime'  => $mime,
    ];
}

/**
 * Copia optimizada (redimensionada + rotada según EXIF) para incrustar en el PDF.
 * Usa GD. Si GD no está disponible devuelve false y se usará el original.
 */
function generarImagenImpresion(
    string $origen,
    string $destino,
    int $maxLado = PRODUCTO_IMG_PRINT_LADO,
    int $calidad = PRODUCTO_IMG_PRINT_CALIDAD
): bool {
    if (!function_exists('imagecreatetruecolor') || !function_exists('imagecopyresampled')) {
        return false;
    }

    $info = @getimagesize($origen);
    if (!$info) return false;

    $mime = $info['mime'] ?? '';
    $src  = match ($mime) {
        'image/jpeg' => @imagecreatefromjpeg($origen),
        'image/png'  => @imagecreatefrompng($origen),
        default      => false,
    };
    if (!$src) return false;

    // Fotos de celular: aplicar orientación EXIF antes de reducir.
    if ($mime === 'image/jpeg' && function_exists('exif_read_data')) {
        $exif = @exif_read_data($origen);
        $ori  = (int)($exif['Orientation'] ?? 1);
        if (in_array($ori, [3, 6, 8], true)) {
            $grados = [3 => 180, 6 => -90, 8 => 90][$ori];
            $girado = @imagerotate($src, $grados, 0);
            if ($girado) {
                imagedestroy($src);
                $src = $girado;
            }
        }
    }

    $w = imagesx($src);
    $h = imagesy($src);
    if ($w < 1 || $h < 1) {
        imagedestroy($src);
        return false;
    }

    $escala = min(1, $maxLado / max($w, $h));
    $nw     = max(1, (int)round($w * $escala));
    $nh     = max(1, (int)round($h * $escala));

    $dst = imagecreatetruecolor($nw, $nh);
    if (!$dst) {
        imagedestroy($src);
        return false;
    }

    // Fondo blanco (los PNG con transparencia no quedan negros en el PDF).
    imagefilledrectangle($dst, 0, 0, $nw, $nh, imagecolorallocate($dst, 255, 255, 255));
    imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);

    $dir = dirname($destino);
    if (!is_dir($dir)) @mkdir($dir, 0755, true);

    $ok = @imagejpeg($dst, $destino, $calidad);

    imagedestroy($dst);
    imagedestroy($src);

    return (bool)$ok;
}

/** Ruta relativa de la copia para imprimir; si no existe, el original. */
function productoRutaImagenPrint(?string $ruta): ?string
{
    if (!$ruta) return null;

    $ruta = str_replace('\\', '/', ltrim($ruta, '/'));
    if (!str_starts_with($ruta, PRODUCTO_IMG_DIR . '/')) return $ruta;

    $base = pathinfo($ruta, PATHINFO_FILENAME);
    $rel  = PRODUCTO_IMG_PRINT_DIR . '/' . $base . '.jpg';

    return is_file(UPLOADS_PATH . '/' . $rel) ? $rel : $ruta;
}

/** Ruta relativa de la miniatura (tabla); si no existe, la de imprimir. */
function productoRutaImagenThumb(?string $ruta): ?string
{
    if (!$ruta) return null;

    $ruta = str_replace('\\', '/', ltrim($ruta, '/'));
    if (!str_starts_with($ruta, PRODUCTO_IMG_DIR . '/')) return $ruta;

    $base = pathinfo($ruta, PATHINFO_FILENAME);
    $rel  = PRODUCTO_IMG_THUMB_DIR . '/' . $base . '.jpg';

    if (is_file(UPLOADS_PATH . '/' . $rel)) return $rel;

    return productoRutaImagenPrint($ruta);
}

/** URL pública de la foto (original o copia para imprimir). */
function productoImagenUrl(?string $ruta, bool $print = false): string
{
    if (!$ruta) return '';

    $rel = $print ? productoRutaImagenPrint($ruta) : $ruta;
    if (!$rel) return '';

    return UPLOADS_URL . '/' . implode('/', array_map('rawurlencode', explode('/', $rel)));
}

/** URL pública de la miniatura para la tabla / previews pequeños. */
function productoImagenThumbUrl(?string $ruta): string
{
    if (!$ruta) return '';

    $rel = productoRutaImagenThumb($ruta);
    if (!$rel) return '';

    return UPLOADS_URL . '/' . implode('/', array_map('rawurlencode', explode('/', $rel)));
}

/** ¿El archivo de la foto existe realmente en disco? */
function productoImagenDisponible(?string $ruta): bool
{
    if (!$ruta) return false;
    $rel = str_replace('\\', '/', ltrim($ruta, '/'));
    if (str_contains($rel, '..')) return false;
    return is_file(UPLOADS_PATH . '/' . $rel);
}

/** Borra el original y la copia para imprimir (solo dentro de uploads/productos). */
function eliminarImagenProducto(?string $ruta): void
{
    if (!$ruta) return;

    $base = realpath(UPLOADS_PATH . '/' . PRODUCTO_IMG_DIR);
    if (!$base) return;

    $rutas = [$ruta, productoRutaImagenPrint($ruta), productoRutaImagenThumb($ruta)];

    foreach (array_unique(array_filter($rutas)) as $rel) {
        $abs = realpath(UPLOADS_PATH . '/' . str_replace('\\', '/', ltrim($rel, '/')));
        if ($abs && str_starts_with($abs, $base . DIRECTORY_SEPARATOR) && is_file($abs)) {
            @unlink($abs);
        }
    }
}
