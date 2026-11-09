<?php
require_once 'config/config.php';
require_once 'functions/db.php';
require_once 'functions/helpers.php';
require_once 'functions/auth.php';

requireLogin();
if (!isSupervisor()) { include ROOT_PATH.'/includes/403.php'; exit; }
$pageTitle = 'Configuración del Sistema';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $pa = $_POST['action'] ?? '';

    if ($pa === 'empresa') {
        $campos = [
            'empresa_nombre','empresa_rif','empresa_direccion',
            'empresa_telefono','empresa_email','empresa_web',
            'prefijo_cotizacion','prefijo_despacho','prefijo_venta'
        ];
        foreach ($campos as $campo) {
            // Solo guardar si el campo existe en el POST (evita borrar con vacío)
            if (!array_key_exists($campo, $_POST)) continue;
            setConfig($campo, trim($_POST[$campo]));
        }

        $logoPath = subirArchivo('logo','logo');
        if ($logoPath) {
            $ant = getConfig('logo_path','');
            if ($ant && file_exists(ROOT_PATH.'/assets/uploads/'.$ant)) @unlink(ROOT_PATH.'/assets/uploads/'.$ant);
            setConfig('logo_path', $logoPath);
            $_SESSION['flash'] = ['type'=>'success','msg'=>'Configuración y logo guardados.'];
        } else {
            // Si el usuario subió un archivo pero no se guardó → avisar
            if (!empty($_FILES['logo']['name'])) {
                $_SESSION['flash'] = ['type'=>'warning',
                    'msg'=>'El logo no se pudo subir (revisa formato JPG/PNG/WEBP y tamaño máx. 3MB). El resto se guardó.'];
            } else {
                $_SESSION['flash'] = ['type'=>'success','msg'=>'Configuración guardada.'];
            }
        }

        auditLog('UPDATE','configuracion',0,'Configuración empresa actualizada');
        header('Location: configuracion.php'); exit;
    }

    if ($pa === 'porcentajes') {
        $comMayor = cleanFloat($_POST['comision_pct_mayorista'] ?? 7);
        $comDetal = cleanFloat($_POST['comision_pct_detalista'] ?? 10);
        $comMayor = max(0, min(100, $comMayor));
        $comDetal = max(0, min(100, $comDetal));
        setConfig('comision_pct_mayorista', number_format($comMayor,2,'.',''));
        setConfig('comision_pct_detalista', number_format($comDetal,2,'.',''));
        auditLog('UPDATE','configuracion',0,"Comisiones actualizadas: Mayor={$comMayor}% Detal={$comDetal}%");
        $_SESSION['flash'] = ['type'=>'success','msg'=>'Porcentajes guardados.'];
        header('Location: configuracion.php'); exit;
    }

    if ($pa === 'eliminar_logo') {
        $ant = getConfig('logo_path','');
        if ($ant && file_exists(ROOT_PATH.'/assets/uploads/'.$ant)) @unlink(ROOT_PATH.'/assets/uploads/'.$ant);
        setConfig('logo_path','');
        $_SESSION['flash'] = ['type'=>'success','msg'=>'Logo eliminado.'];
        header('Location: configuracion.php'); exit;
    }
}

// Cargar configuración
$cfg = [];
$keys = ['empresa_nombre','empresa_rif','empresa_direccion','empresa_telefono',
         'empresa_email','empresa_web','logo_path',
         'prefijo_cotizacion','prefijo_despacho','prefijo_venta',
         'comision_pct_mayorista','comision_pct_detalista'];
foreach ($keys as $k) $cfg[$k] = getConfig($k,'');

include 'includes/header.php';
?>

<div class="row">
    <!-- IZQUIERDA: Empresa y numeración -->
    <div class="col-8">

        <!-- DATOS DE EMPRESA -->
        <div class="card mb-4">
            <div class="card-header">
                <i class="fa-solid fa-building text-primary"></i>
                <h5>Datos de la Empresa</h5>
            </div>
            <div class="card-body">
                <form method="POST" enctype="multipart/form-data">
                    <input type="hidden" name="<?= CSRF_TOKEN_NAME ?>" value="<?= getCsrfToken() ?>">
                    <input type="hidden" name="action" value="empresa">
                    <div class="row">
                        <div class="col-8">
                            <div class="form-group">
                                <label class="form-label">Nombre / Razón Social</label>
                                <input type="text" name="empresa_nombre" class="form-control" value="<?= clean($cfg['empresa_nombre']) ?>" maxlength="150">
                            </div>
                        </div>
                        <div class="col-4">
                            <div class="form-group">
                                <label class="form-label">RIF</label>
                                <input type="text" name="empresa_rif" class="form-control" value="<?= clean($cfg['empresa_rif']) ?>" placeholder="J-XXXXXXXXX-X">
                            </div>
                        </div>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Dirección</label>
                        <textarea name="empresa_direccion" class="form-control" rows="2"><?= clean($cfg['empresa_direccion']) ?></textarea>
                    </div>
                    <div class="row">
                        <div class="col-4"><div class="form-group">
                            <label class="form-label">Teléfono</label>
                            <input type="text" name="empresa_telefono" class="form-control" value="<?= clean($cfg['empresa_telefono']) ?>">
                        </div></div>
                        <div class="col-4"><div class="form-group">
                            <label class="form-label">Email</label>
                            <input type="email" name="empresa_email" class="form-control" value="<?= clean($cfg['empresa_email']) ?>">
                        </div></div>
                        <div class="col-4"><div class="form-group">
                            <label class="form-label">Sitio Web</label>
                            <input type="text" name="empresa_web" class="form-control" value="<?= clean($cfg['empresa_web']) ?>">
                        </div></div>
                    </div>

                    <hr class="divider">
                    <p class="fw-bold fs-12 mb-3"><i class="fa-solid fa-hashtag"></i> Prefijos de Numeración</p>
                    <div class="row">
                        <div class="col-4"><div class="form-group">
                            <label class="form-label">Prefijo Cotización</label>
                            <input type="text" name="prefijo_cotizacion" class="form-control font-mono"
                                   value="<?= clean($cfg['prefijo_cotizacion']?:'COT') ?>" maxlength="5">
                            <small class="text-muted">Ej: COT → COT00001</small>
                        </div></div>
                        <div class="col-4"><div class="form-group">
                            <label class="form-label">Prefijo Despacho</label>
                            <input type="text" name="prefijo_despacho" class="form-control font-mono"
                                   value="<?= clean($cfg['prefijo_despacho']?:'DSP') ?>" maxlength="5">
                            <small class="text-muted">Ej: DSP → DSP00001</small>
                        </div></div>
                        <div class="col-4"><div class="form-group">
                            <label class="form-label">Prefijo Factura</label>
                            <input type="text" name="prefijo_venta" class="form-control font-mono"
                                   value="<?= clean($cfg['prefijo_venta']?:'FAC') ?>" maxlength="5">
                            <small class="text-muted">Ej: FAC → FAC00001</small>
                        </div></div>
                    </div>

                    <hr class="divider">
                    <p class="fw-bold fs-12 mb-3"><i class="fa-solid fa-image"></i> Logo de la Empresa</p>
                    <?php if ($cfg['logo_path'] && file_exists(ROOT_PATH.'/assets/uploads/'.$cfg['logo_path'])): ?>
                    <div style="display:flex;align-items:center;gap:16px;background:#f8fafc;border-radius:8px;padding:12px;margin-bottom:12px">
                        <img src="<?= UPLOADS_URL.'/'.clean($cfg['logo_path']) ?>" style="max-width:120px;max-height:55px;object-fit:contain">
                        <div>
                            <div class="fw-bold fs-12">Logo actual</div>
                            <form method="POST" style="display:inline;margin-top:6px" onsubmit="return confirm('¿Eliminar logo?')">
                                <input type="hidden" name="<?= CSRF_TOKEN_NAME ?>" value="<?= getCsrfToken() ?>">
                                <input type="hidden" name="action" value="eliminar_logo">
                                <button type="submit" class="btn btn-sm btn-danger">
                                    <i class="fa-solid fa-trash"></i> Eliminar logo
                                </button>
                            </form>
                        </div>
                    </div>
                    <?php endif; ?>
                    <div class="form-group">
                        <label class="form-label"><?= $cfg['logo_path'] ? 'Cambiar' : 'Subir' ?> logo <small class="text-muted">(JPG/PNG/WEBP · máx. 3MB · recomendado 300×80px)</small></label>
                        <input type="file" name="logo" class="form-control" accept=".jpg,.jpeg,.png,.webp" onchange="previewLogo(this)">
                    </div>
                    <div id="logoPreview" style="display:none;margin-top:8px">
                        <img id="logoPreviewImg" style="max-width:200px;max-height:70px;object-fit:contain;border:1px solid var(--border);border-radius:6px;padding:6px">
                    </div>
                    <button type="submit" class="btn btn-primary mt-3">
                        <i class="fa-solid fa-floppy-disk"></i> Guardar Configuración
                    </button>
                </form>
            </div>
        </div>

        <!-- PORCENTAJES -->
        <div class="card">
            <div class="card-header">
                <i class="fa-solid fa-percent text-primary"></i>
                <h5>Porcentajes de Comisión</h5>
            </div>
            <div class="card-body">
                <div class="alert alert-info">
                    <i class="fa-solid fa-circle-info"></i>
                    Estos porcentajes se usan en Cobranza y Transporte para calcular comisiones automáticamente.
                </div>
                <form method="POST">
                    <input type="hidden" name="<?= CSRF_TOKEN_NAME ?>" value="<?= getCsrfToken() ?>">
                    <input type="hidden" name="action" value="porcentajes">
                    <div class="row">
                        <div class="col-4">
                            <div class="form-group">
                                <label class="form-label">
                                    <i class="fa-solid fa-industry" style="color:var(--info)"></i>
                                    Comisión Mayorista %
                                </label>
                                <div class="input-group">
                                    <input type="number" name="comision_pct_mayorista" class="form-control"
                                        value="<?= clean($cfg['comision_pct_mayorista']?:'7.00') ?>"
                                        min="0" max="100" step="0.01">
                                    <span class="input-group-text">%</span>
                                </div>
                                <small class="text-muted">Default: 7.00%</small>
                            </div>
                        </div>
                        <div class="col-4">
                            <div class="form-group">
                                <label class="form-label">
                                    <i class="fa-solid fa-store" style="color:var(--warning)"></i>
                                    Comisión Detalista %
                                </label>
                                <div class="input-group">
                                    <input type="number" name="comision_pct_detalista" class="form-control"
                                        value="<?= clean($cfg['comision_pct_detalista']?:'10.00') ?>"
                                        min="0" max="100" step="0.01">
                                    <span class="input-group-text">%</span>
                                </div>
                                <small class="text-muted">Default: 10.00%</small>
                            </div>
                        </div>
                    </div>

                    <!-- Simulador -->
                    <div style="background:#f8fafc;border:1px solid var(--border);border-radius:8px;padding:14px;margin-top:12px">
                        <p class="fw-bold fs-12 mb-2"><i class="fa-solid fa-calculator"></i> Simulador con monto ejemplo</p>
                        <div class="d-flex gap-3 align-center">
                            <div>
                                <label class="form-label fs-11">Monto ejemplo $</label>
                                <input type="number" id="simMonto" class="form-control" value="1000" step="0.01" oninput="simular()" style="width:120px">
                            </div>
                            <div style="font-size:13px" id="simResultado">
                                <!-- Se llena con JS -->
                            </div>
                        </div>
                    </div>

                    <button type="submit" class="btn btn-primary mt-3">
                        <i class="fa-solid fa-floppy-disk"></i> Guardar Porcentajes
                    </button>
                </form>
            </div>
        </div>
    </div>

    <!-- DERECHA: Vista previa e info -->
    <div class="col-4">
        <div class="card mb-3">
            <div class="card-header"><i class="fa-solid fa-eye text-primary"></i><h5>Vista Previa Encabezado</h5></div>
            <div class="card-body" style="background:#f8fafc">
                <div style="border:2px solid var(--primary);border-radius:8px;padding:14px;background:white">
                    <?php if ($cfg['logo_path'] && file_exists(ROOT_PATH.'/assets/uploads/'.$cfg['logo_path'])): ?>
                    <img src="<?= UPLOADS_URL.'/'.clean($cfg['logo_path']) ?>" style="max-width:90px;max-height:45px;object-fit:contain;display:block;margin-bottom:6px">
                    <?php endif; ?>
                    <div style="font-size:14px;font-weight:800;color:var(--primary)"><?= clean($cfg['empresa_nombre']?:APP_NAME) ?></div>
                    <div style="font-size:11px;color:var(--text-muted);margin-top:3px">
                        <?php if ($cfg['empresa_rif']): ?>RIF: <?= clean($cfg['empresa_rif']) ?><br><?php endif; ?>
                        <?= clean(mb_strimwidth($cfg['empresa_direccion']??'',0,55,'...')) ?>
                        <?php if ($cfg['empresa_telefono']): ?><br>Tel: <?= clean($cfg['empresa_telefono']) ?><?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <div class="card mb-3">
            <div class="card-header"><i class="fa-solid fa-link text-primary"></i><h5>Accesos Rápidos</h5></div>
            <div class="card-body">
                <div class="d-flex gap-2" style="flex-direction:column">
                    <a href="transporte.php" class="btn btn-light btn-sm"><i class="fa-solid fa-truck"></i> Reporte de Transporte</a>
                    <a href="usuarios.php"   class="btn btn-light btn-sm"><i class="fa-solid fa-users-gear"></i> Gestionar Usuarios</a>
                    <a href="auditoria.php"  class="btn btn-light btn-sm"><i class="fa-solid fa-clipboard-list"></i> Ver Auditoría</a>
                    <a href="moneda.php"     class="btn btn-light btn-sm"><i class="fa-solid fa-coins"></i> Actualizar Tasa</a>
                    <a href="reportes.php"   class="btn btn-light btn-sm"><i class="fa-solid fa-chart-bar"></i> Ver Reportes</a>
                </div>
            </div>
        </div>

        <div class="card">
            <div class="card-header"><i class="fa-solid fa-circle-info text-primary"></i><h5>Sistema</h5></div>
            <div class="card-body fs-13">
                <div class="d-flex justify-between mb-2"><span class="text-muted">Versión:</span><strong><?= APP_VERSION ?></strong></div>
                <div class="d-flex justify-between mb-2"><span class="text-muted">PHP:</span><strong><?= PHP_VERSION ?></strong></div>
                <div class="d-flex justify-between mb-2"><span class="text-muted">Fecha servidor:</span><strong><?= date('d/m/Y H:i') ?></strong></div>
                <div class="d-flex justify-between"><span class="text-muted">Base de datos:</span><strong><?= DB_NAME ?></strong></div>
            </div>
        </div>
    </div>
</div>

<?php
$comMayor = (float)($cfg['comision_pct_mayorista'] ?: 7);
$comDetal = (float)($cfg['comision_pct_detalista'] ?: 10);

ob_start();
?>
<script>
function previewLogo(input) {
    if (!input.files[0]) return;
    const reader = new FileReader();
    reader.onload = function(e) {
        const img = document.getElementById('logoPreviewImg');
        const box = document.getElementById('logoPreview');
        if (img) img.src = e.target.result;
        if (box) box.style.display = 'block';
    };
    reader.readAsDataURL(input.files[0]);
}

function simular() {
    const montoEl = document.getElementById('simMonto');
    const mayEl   = document.querySelector('[name="comision_pct_mayorista"]');
    const detEl   = document.querySelector('[name="comision_pct_detalista"]');
    const r       = document.getElementById('simResultado');
    if (!r) return;

    const monto  = parseFloat(montoEl ? montoEl.value : 0) || 0;
    const comMay = parseFloat(mayEl ? mayEl.value : <?= $comMayor ?>) || 0;
    const comDet = parseFloat(detEl ? detEl.value : <?= $comDetal ?>) || 0;

    const valMay = (monto * comMay / 100).toFixed(2);
    const valDet = (monto * comDet / 100).toFixed(2);

    r.innerHTML =
        '<div style="display:grid;grid-template-columns:repeat(2,1fr);gap:8px">' +
            '<div style="background:rgba(41,128,185,.08);border-radius:6px;padding:8px;text-align:center">' +
                '<div style="font-size:14px;font-weight:800;color:var(--info)">$ ' + valMay + '</div>' +
                '<div style="font-size:10px;color:var(--text-muted)">Com. Mayor ' + comMay + '%</div>' +
            '</div>' +
            '<div style="background:rgba(230,126,34,.08);border-radius:6px;padding:8px;text-align:center">' +
                '<div style="font-size:14px;font-weight:800;color:var(--warning)">$ ' + valDet + '</div>' +
                '<div style="font-size:10px;color:var(--text-muted)">Com. Detal ' + comDet + '%</div>' +
            '</div>' +
        '</div>';
}

document.addEventListener('DOMContentLoaded', function() {
    simular();
    const mayEl = document.querySelector('[name="comision_pct_mayorista"]');
    const detEl = document.querySelector('[name="comision_pct_detalista"]');
    if (mayEl) mayEl.addEventListener('input', simular);
    if (detEl) detEl.addEventListener('input', simular);
});
</script>
<?php
$extraScript = ob_get_clean();
include 'includes/footer.php';
?>