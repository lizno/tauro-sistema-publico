<?php
require_once 'config/config.php';
require_once 'functions/db.php';
require_once 'functions/helpers.php';
require_once 'functions/auth.php';

requireLogin();
requirePermission('moneda','ver');
$pageTitle = 'Tasa de Cambio';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    requirePermission('moneda','crear');
    $tasa  = cleanFloat($_POST['tasa_bs'] ?? 0);
    $fecha = $_POST['fecha'] ?? date('Y-m-d');
    $nota  = clean($_POST['nota'] ?? '');
    if ($tasa <= 0) {
        $_SESSION['flash'] = ['type'=>'error','msg'=>'La tasa debe ser mayor a 0.'];
    } else {
        dbExecute(
            "INSERT INTO tasas_cambio (tasa_bs,fecha,nota,usuario_id) VALUES (?,?,?,?)",
            [$tasa, $fecha, $nota, $_SESSION['user_id']]
        );
        auditLog('INSERT','tasas_cambio',(int)dbLastId(),"Nueva tasa Bs. $tasa registrada");
        $_SESSION['flash'] = ['type'=>'success','msg'=>"Tasa Bs. $tasa guardada correctamente."];
    }
    header('Location: moneda.php'); exit;
}

$historial = dbQuery(
    "SELECT t.*, u.nombre u_nombre FROM tasas_cambio t
     LEFT JOIN usuarios u ON t.usuario_id = u.id
     ORDER BY t.fecha DESC, t.id DESC"
);
$tasaActual = $historial[0] ?? null;

include 'includes/header.php';
?>
<div class="row mb-4">
    <div class="col-4">
        <div class="card">
            <div class="card-header"><h5>💱 Tasa Actual</h5></div>
            <div class="card-body" style="text-align:center;padding:30px">
                <?php if ($tasaActual): ?>
                <div style="font-size:52px;font-weight:800;color:var(--primary)">
                    Bs. <?= number_format($tasaActual['tasa_bs'],2,',','.') ?>
                </div>
                <div style="font-size:16px;color:var(--text-muted);margin:8px 0">por 1 USD</div>
                <div style="font-size:13px;color:var(--text-muted)">
                    Actualizado el <?= date('d/m/Y', strtotime($tasaActual['fecha'])) ?>
                    <?php if ($tasaActual['u_nombre']): ?>
                    por <strong><?= clean($tasaActual['u_nombre']) ?></strong>
                    <?php endif; ?>
                </div>
                <?php if ($tasaActual['nota']): ?>
                <div style="margin-top:12px;font-size:12px;color:var(--text-muted);font-style:italic">
                    "<?= clean($tasaActual['nota']) ?>"
                </div>
                <?php endif; ?>
                <?php else: ?>
                <p style="color:var(--text-muted)">Sin tasa configurada</p>
                <?php endif; ?>
            </div>
        </div>

        <!-- Calcular equivalencias rápidas -->
        <?php if ($tasaActual): ?>
        <div class="card mt-3">
            <div class="card-header"><h5>🧮 Conversor Rápido</h5></div>
            <div class="card-body">
                <div class="form-group">
                    <label class="form-label">USD → Bs.</label>
                    <div class="input-group">
                        <span class="input-group-text">$</span>
                        <input type="number" id="convUSD" class="form-control" placeholder="0.00" oninput="convertir('usd')">
                    </div>
                    <div id="resUSD" style="margin-top:6px;font-weight:700;color:var(--info);font-size:14px"></div>
                </div>
                <hr class="divider">
                <div class="form-group">
                    <label class="form-label">Bs. → USD</label>
                    <div class="input-group">
                        <span class="input-group-text">Bs.</span>
                        <input type="number" id="convBS" class="form-control" placeholder="0.00" oninput="convertir('bs')">
                    </div>
                    <div id="resBS" style="margin-top:6px;font-weight:700;color:var(--success);font-size:14px"></div>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <div class="col-8">
        <?php if (hasPermission('moneda','crear')): ?>
        <div class="card mb-4">
            <div class="card-header"><h5>➕ Registrar Nueva Tasa</h5></div>
            <div class="card-body">
                <form method="POST">
                    <input type="hidden" name="<?= CSRF_TOKEN_NAME ?>" value="<?= getCsrfToken() ?>">
                    <div class="row">
                        <div class="col-4">
                            <div class="form-group">
                                <label class="form-label">Tasa Bs. / USD *</label>
                                <div class="input-group">
                                    <span class="input-group-text">Bs.</span>
                                    <input type="number" name="tasa_bs" class="form-control" step="0.01" min="0.01" placeholder="90.50" required>
                                </div>
                            </div>
                        </div>
                        <div class="col-4">
                            <div class="form-group">
                                <label class="form-label">Fecha</label>
                                <input type="date" name="fecha" class="form-control" value="<?= date('Y-m-d') ?>">
                            </div>
                        </div>
                        <div class="col-4">
                            <div class="form-group">
                                <label class="form-label">Nota (opcional)</label>
                                <input type="text" name="nota" class="form-control" placeholder="Ej: BCV oficial" maxlength="100">
                            </div>
                        </div>
                    </div>
                    <button type="submit" class="btn btn-primary">💾 Registrar Tasa</button>
                </form>
            </div>
        </div>
        <?php endif; ?>

        <div class="card">
            <div class="card-header">
                <h5>📋 Historial de Tasas</h5>
                <button onclick="exportExcel('tablaHistorialTasas','Historial_Tasas')" class="btn btn-sm btn-light" style="margin-left:auto">📊 Excel</button>
            </div>
            <div class="card-body p-0">
                <table class="table" id="tablaHistorialTasas">
                    <thead><tr><th>Fecha</th><th>Tasa Bs./USD</th><th>1 USD =</th><th>100 USD =</th><th>Nota</th><th>Registrado por</th></tr></thead>
                    <tbody>
                    <?php foreach ($historial as $i => $t): ?>
                    <tr <?= $i===0 ? 'style="background:#f0fdf4;font-weight:700"' : '' ?>>
                        <td><?= date('d/m/Y', strtotime($t['fecha'])) ?></td>
                        <td style="font-size:16px;font-weight:800;color:var(--primary)">Bs. <?= number_format($t['tasa_bs'],2,',','.') ?></td>
                        <td>Bs. <?= number_format($t['tasa_bs'],2,',','.') ?></td>
                        <td>Bs. <?= number_format($t['tasa_bs']*100,2,',','.') ?></td>
                        <td><?= clean($t['nota'] ?: '—') ?></td>
                        <td><?= clean($t['u_nombre'] ?? 'Sistema') ?></td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<?php
$tasa = $tasaActual ? (float)$tasaActual['tasa_bs'] : 1;
$extraScript = "
<script>
const TASA_ACTUAL = $tasa;
function convertir(desde) {
    if (desde === 'usd') {
        const usd = parseFloat(document.getElementById('convUSD').value) || 0;
        document.getElementById('resUSD').textContent = usd > 0 ? 'Bs. ' + (usd * TASA_ACTUAL).toFixed(2).replace(/\d(?=(\d{3})+\.)/g, '\$&,') : '';
    } else {
        const bs = parseFloat(document.getElementById('convBS').value) || 0;
        document.getElementById('resBS').textContent = bs > 0 ? '$ ' + (bs / TASA_ACTUAL).toFixed(4) : '';
    }
}
</script>";
$extraScript .= '<script>
$(document).ready(function() {
    if ($.fn.DataTable.isDataTable("#tablaHistorialTasas")) {
        $("#tablaHistorialTasas").DataTable().destroy();
    }
    $("#tablaHistorialTasas").DataTable({
        language: dtLang,
        pageLength: 20,
        order: [],
        columnDefs: [{ targets: "_all", defaultContent: "" }]
    });
});
</script>';
?>
<?php include 'includes/footer.php'; ?>
