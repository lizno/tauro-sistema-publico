<?php
require_once 'config/config.php';
require_once 'functions/db.php';
require_once 'functions/helpers.php';
require_once 'functions/auth.php';

requireLogin();
if (!isSupervisor()) { include ROOT_PATH.'/includes/403.php'; exit; }
$pageTitle = 'Editor de Plantilla';

// ── GUARDAR CONFIGURACIÓN ──────────────────────────────────
if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action']??'')==='save_plantilla') {
    verifyCsrf();
    $campos = [
        // Header
        'pl_show_logo','pl_logo_size','pl_show_empresa_rif','pl_show_empresa_dir',
        'pl_show_empresa_tel','pl_show_empresa_email',
        // Documento
        'pl_color_primario','pl_show_tipo_badge','pl_show_fecha_venc','pl_show_tasa',
        // Cliente
        'pl_show_cli_rif','pl_show_cli_dir','pl_show_cli_tel',
        // Tabla
        'pl_show_col_ref','pl_show_col_bs','pl_lbl_cantidad','pl_lbl_precio',
        // Totales
        'pl_show_total_bs','pl_show_flete','pl_show_tasa_usada',
        // Pie
        'pl_footer_texto','pl_nota_validez','pl_show_firma','pl_lbl_firma',
        // Tipografía
        'pl_font_size',
    ];
    foreach ($campos as $c) {
        setConfig($c, trim($_POST[$c] ?? ''));
    }
    auditLog('UPDATE','plantilla',0,'Plantilla de impresión actualizada');
    $_SESSION['flash'] = ['type'=>'success','msg'=>'Plantilla guardada. Los cambios se aplican al imprimir.'];
    header('Location: plantilla_editor.php'); exit;
}

// ── CARGAR CONFIG ACTUAL ───────────────────────────────────
$cfg = [];
$defaults = [
    'pl_show_logo'         => '1',
    'pl_logo_size'         => 'medium',
    'pl_show_empresa_rif'  => '1',
    'pl_show_empresa_dir'  => '1',
    'pl_show_empresa_tel'  => '1',
    'pl_show_empresa_email'=> '1',
    'pl_color_primario'    => '#c0392b',
    'pl_show_tipo_badge'   => '1',
    'pl_show_fecha_venc'   => '1',
    'pl_show_tasa'         => '1',
    'pl_show_cli_rif'      => '1',
    'pl_show_cli_dir'      => '1',
    'pl_show_cli_tel'      => '1',
    'pl_show_col_ref'      => '1',
    'pl_show_col_bs'       => '1',
    'pl_lbl_cantidad'      => 'Cantidad',
    'pl_lbl_precio'        => 'Precio $',
    'pl_show_total_bs'     => '1',
    'pl_show_flete'        => '1',
    'pl_show_tasa_usada'   => '1',
    'pl_footer_texto'      => 'Gracias por su preferencia.',
    'pl_nota_validez'      => 'Este documento tiene validez de 15 días desde la fecha de emisión.',
    'pl_show_firma'        => '1',
    'pl_lbl_firma'         => 'Firma del Receptor',
    'pl_font_size'         => '13',
];
foreach ($defaults as $k => $default) {
    $cfg[$k] = getConfig($k, $default);
}

// Datos empresa para el preview
$empresa = [
    'nombre'   => getConfig('empresa_nombre', APP_NAME),
    'rif'      => getConfig('empresa_rif','J-000000000-0'),
    'dir'      => getConfig('empresa_direccion','Dirección de la empresa'),
    'tel'      => getConfig('empresa_telefono','0000-0000000'),
    'email'    => getConfig('empresa_email','info@empresa.com'),
    'logo'     => getConfig('logo_path',''),
];

// Helper: toggle field
function chk(array $cfg, string $key): string {
    return $cfg[$key]==='1' ? 'checked' : '';
}

include 'includes/header.php';
?>
<style>
.editor-wrap { display:grid; grid-template-columns:380px 1fr; gap:0; height:calc(100vh - 120px); }
.editor-panel { background:white; border:1px solid var(--border); border-radius:var(--radius) 0 0 var(--radius); overflow-y:auto; }
.preview-panel { background:#e8eaed; border:1px solid var(--border); border-radius:0 var(--radius) var(--radius) 0; border-left:none; overflow-y:auto; display:flex; flex-direction:column; align-items:center; padding:24px; }
.ep-header { position:sticky; top:0; background:white; border-bottom:1px solid var(--border); padding:12px 18px; display:flex; align-items:center; gap:8px; z-index:10; }
.ep-header h5 { font-size:14px; font-weight:700; margin:0; }
.ep-section { border-bottom:1px solid var(--border); }
.ep-section-title { padding:12px 18px 10px; font-size:11px; font-weight:800; text-transform:uppercase; letter-spacing:1px; color:var(--text-muted); display:flex; align-items:center; gap:8px; cursor:pointer; user-select:none; }
.ep-section-body { padding:4px 18px 14px; }
.ep-row { display:flex; align-items:center; justify-content:space-between; padding:6px 0; font-size:13px; border-bottom:1px solid #f5f5f5; }
.ep-row label { color:var(--text); font-weight:500; }
.ep-row input[type=color] { width:36px; height:28px; border:1px solid var(--border); border-radius:5px; cursor:pointer; padding:2px; }
.ep-row input[type=range] { width:120px; }
.ep-toggle { position:relative; width:40px; height:22px; flex-shrink:0; }
.ep-toggle input { opacity:0; width:0; height:0; }
.ep-slider { position:absolute; cursor:pointer; top:0; left:0; right:0; bottom:0; background:#ccc; border-radius:22px; transition:.3s; }
.ep-slider:before { position:absolute; content:""; height:16px; width:16px; left:3px; bottom:3px; background:white; border-radius:50%; transition:.3s; }
.ep-toggle input:checked + .ep-slider { background:var(--primary); }
.ep-toggle input:checked + .ep-slider:before { transform:translateX(18px); }

/* Preview del documento */
.doc-preview { background:white; width:700px; min-height:900px; border-radius:8px; box-shadow:0 4px 24px rgba(0,0,0,.15); padding:32px; font-family:'Inter',Arial,sans-serif; font-size:13px; }
.preview-label { background:#1a1a2e; color:white; padding:6px 14px; border-radius:6px 6px 0 0; font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:1px; margin-bottom:0; width:700px; display:flex; justify-content:space-between; align-items:center; }

/* Save bar */
.save-bar { position:sticky; bottom:0; background:white; border-top:1px solid var(--border); padding:12px 18px; display:flex; gap:8px; z-index:10; }
</style>

<div class="d-flex justify-between align-center mb-3">
    <div>
        <a href="documentos.php" class="btn btn-light btn-sm"><i class="fa-solid fa-arrow-left"></i> Volver a Documentos</a>
    </div>
    <div style="font-size:13px;color:var(--text-muted)">
        <i class="fa-solid fa-circle-info"></i>
        Los cambios se aplican a todas las impresiones futuras (imprimir.php).
    </div>
</div>

<form method="POST" id="formPlantilla">
<input type="hidden" name="<?= CSRF_TOKEN_NAME ?>" value="<?= getCsrfToken() ?>">
<input type="hidden" name="action" value="save_plantilla">

<div class="editor-wrap">

    <!-- ── PANEL IZQUIERDO: CONTROLES ── -->
    <div class="editor-panel">
        <div class="ep-header">
            <i class="fa-solid fa-sliders text-primary"></i>
            <h5>Configuración de Plantilla</h5>
        </div>

        <!-- SECCIÓN: ENCABEZADO -->
        <div class="ep-section">
            <div class="ep-section-title" onclick="toggleSection('sec-header')">
                <i class="fa-solid fa-heading" style="color:var(--primary)"></i>
                Encabezado del Documento
                <i class="fa-solid fa-chevron-down" style="margin-left:auto;font-size:10px"></i>
            </div>
            <div class="ep-section-body" id="sec-header">
                <div class="ep-row">
                    <label>Mostrar logo</label>
                    <label class="ep-toggle"><input type="checkbox" name="pl_show_logo" value="1" <?= chk($cfg,'pl_show_logo') ?> onchange="updatePreview()"><span class="ep-slider"></span></label>
                </div>
                <div class="ep-row">
                    <label>Tamaño del logo</label>
                    <select name="pl_logo_size" class="form-select" style="width:120px;font-size:12px" onchange="updatePreview()">
                        <option value="small"  <?= $cfg['pl_logo_size']==='small' ?'selected':'' ?>>Pequeño</option>
                        <option value="medium" <?= $cfg['pl_logo_size']==='medium'?'selected':'' ?>>Mediano</option>
                        <option value="large"  <?= $cfg['pl_logo_size']==='large' ?'selected':'' ?>>Grande</option>
                    </select>
                </div>
                <div class="ep-row">
                    <label>Color principal</label>
                    <div class="d-flex gap-2 align-center">
                        <input type="color" name="pl_color_primario" value="<?= htmlspecialchars($cfg['pl_color_primario']) ?>" onchange="updatePreview()">
                        <span style="font-size:11px;font-family:monospace" id="lblColor"><?= htmlspecialchars($cfg['pl_color_primario']) ?></span>
                    </div>
                </div>
                <div class="ep-row">
                    <label>RIF de empresa</label>
                    <label class="ep-toggle"><input type="checkbox" name="pl_show_empresa_rif" value="1" <?= chk($cfg,'pl_show_empresa_rif') ?> onchange="updatePreview()"><span class="ep-slider"></span></label>
                </div>
                <div class="ep-row">
                    <label>Dirección de empresa</label>
                    <label class="ep-toggle"><input type="checkbox" name="pl_show_empresa_dir" value="1" <?= chk($cfg,'pl_show_empresa_dir') ?> onchange="updatePreview()"><span class="ep-slider"></span></label>
                </div>
                <div class="ep-row">
                    <label>Teléfono de empresa</label>
                    <label class="ep-toggle"><input type="checkbox" name="pl_show_empresa_tel" value="1" <?= chk($cfg,'pl_show_empresa_tel') ?> onchange="updatePreview()"><span class="ep-slider"></span></label>
                </div>
                <div class="ep-row">
                    <label>Email de empresa</label>
                    <label class="ep-toggle"><input type="checkbox" name="pl_show_empresa_email" value="1" <?= chk($cfg,'pl_show_empresa_email') ?> onchange="updatePreview()"><span class="ep-slider"></span></label>
                </div>
                <div class="ep-row">
                    <label>Badge de tipo de documento</label>
                    <label class="ep-toggle"><input type="checkbox" name="pl_show_tipo_badge" value="1" <?= chk($cfg,'pl_show_tipo_badge') ?> onchange="updatePreview()"><span class="ep-slider"></span></label>
                </div>
            </div>
        </div>

        <!-- SECCIÓN: DATOS DEL CLIENTE -->
        <div class="ep-section">
            <div class="ep-section-title" onclick="toggleSection('sec-cliente')">
                <i class="fa-solid fa-user" style="color:var(--info)"></i>
                Datos del Cliente
                <i class="fa-solid fa-chevron-down" style="margin-left:auto;font-size:10px"></i>
            </div>
            <div class="ep-section-body" id="sec-cliente">
                <div class="ep-row">
                    <label>Mostrar RIF/CI del cliente</label>
                    <label class="ep-toggle"><input type="checkbox" name="pl_show_cli_rif" value="1" <?= chk($cfg,'pl_show_cli_rif') ?> onchange="updatePreview()"><span class="ep-slider"></span></label>
                </div>
                <div class="ep-row">
                    <label>Mostrar dirección del cliente</label>
                    <label class="ep-toggle"><input type="checkbox" name="pl_show_cli_dir" value="1" <?= chk($cfg,'pl_show_cli_dir') ?> onchange="updatePreview()"><span class="ep-slider"></span></label>
                </div>
                <div class="ep-row">
                    <label>Mostrar teléfono del cliente</label>
                    <label class="ep-toggle"><input type="checkbox" name="pl_show_cli_tel" value="1" <?= chk($cfg,'pl_show_cli_tel') ?> onchange="updatePreview()"><span class="ep-slider"></span></label>
                </div>
                <div class="ep-row">
                    <label>Mostrar fecha de vencimiento</label>
                    <label class="ep-toggle"><input type="checkbox" name="pl_show_fecha_venc" value="1" <?= chk($cfg,'pl_show_fecha_venc') ?> onchange="updatePreview()"><span class="ep-slider"></span></label>
                </div>
                <div class="ep-row">
                    <label>Mostrar tasa de cambio</label>
                    <label class="ep-toggle"><input type="checkbox" name="pl_show_tasa" value="1" <?= chk($cfg,'pl_show_tasa') ?> onchange="updatePreview()"><span class="ep-slider"></span></label>
                </div>
            </div>
        </div>

        <!-- SECCIÓN: TABLA DE PRODUCTOS -->
        <div class="ep-section">
            <div class="ep-section-title" onclick="toggleSection('sec-tabla')">
                <i class="fa-solid fa-table" style="color:var(--success)"></i>
                Tabla de Productos
                <i class="fa-solid fa-chevron-down" style="margin-left:auto;font-size:10px"></i>
            </div>
            <div class="ep-section-body" id="sec-tabla">
                <div class="ep-row">
                    <label>Columna Código/REF</label>
                    <label class="ep-toggle"><input type="checkbox" name="pl_show_col_ref" value="1" <?= chk($cfg,'pl_show_col_ref') ?> onchange="updatePreview()"><span class="ep-slider"></span></label>
                </div>
                <div class="ep-row">
                    <label>Columna Monto Bs.</label>
                    <label class="ep-toggle"><input type="checkbox" name="pl_show_col_bs" value="1" <?= chk($cfg,'pl_show_col_bs') ?> onchange="updatePreview()"><span class="ep-slider"></span></label>
                </div>
                <div class="ep-row" style="flex-direction:column;align-items:flex-start;gap:4px">
                    <label>Etiqueta columna Cantidad</label>
                    <input type="text" name="pl_lbl_cantidad" class="form-control" value="<?= htmlspecialchars($cfg['pl_lbl_cantidad']) ?>" oninput="updatePreview()" style="font-size:12px">
                </div>
                <div class="ep-row" style="flex-direction:column;align-items:flex-start;gap:4px;margin-top:8px">
                    <label>Etiqueta columna Precio</label>
                    <input type="text" name="pl_lbl_precio" class="form-control" value="<?= htmlspecialchars($cfg['pl_lbl_precio']) ?>" oninput="updatePreview()" style="font-size:12px">
                </div>
            </div>
        </div>

        <!-- SECCIÓN: TOTALES -->
        <div class="ep-section">
            <div class="ep-section-title" onclick="toggleSection('sec-totales')">
                <i class="fa-solid fa-calculator" style="color:var(--warning)"></i>
                Totales
                <i class="fa-solid fa-chevron-down" style="margin-left:auto;font-size:10px"></i>
            </div>
            <div class="ep-section-body" id="sec-totales">
                <div class="ep-row">
                    <label>Mostrar total en Bs.S</label>
                    <label class="ep-toggle"><input type="checkbox" name="pl_show_total_bs" value="1" <?= chk($cfg,'pl_show_total_bs') ?> onchange="updatePreview()"><span class="ep-slider"></span></label>
                </div>
                <div class="ep-row">
                    <label>Mostrar línea de Flete</label>
                    <label class="ep-toggle"><input type="checkbox" name="pl_show_flete" value="1" <?= chk($cfg,'pl_show_flete') ?> onchange="updatePreview()"><span class="ep-slider"></span></label>
                </div>
                <div class="ep-row">
                    <label>Mostrar tasa usada en totales</label>
                    <label class="ep-toggle"><input type="checkbox" name="pl_show_tasa_usada" value="1" <?= chk($cfg,'pl_show_tasa_usada') ?> onchange="updatePreview()"><span class="ep-slider"></span></label>
                </div>
            </div>
        </div>

        <!-- SECCIÓN: PIE DE PÁGINA -->
        <div class="ep-section">
            <div class="ep-section-title" onclick="toggleSection('sec-pie')">
                <i class="fa-solid fa-file-signature" style="color:var(--secondary)"></i>
                Pie de Página y Firma
                <i class="fa-solid fa-chevron-down" style="margin-left:auto;font-size:10px"></i>
            </div>
            <div class="ep-section-body" id="sec-pie">
                <div class="ep-row" style="flex-direction:column;align-items:flex-start;gap:4px">
                    <label>Texto del pie de página</label>
                    <textarea name="pl_footer_texto" class="form-control" rows="2" oninput="updatePreview()" style="font-size:12px"><?= htmlspecialchars($cfg['pl_footer_texto']) ?></textarea>
                </div>
                <div class="ep-row" style="flex-direction:column;align-items:flex-start;gap:4px;margin-top:8px">
                    <label>Nota de validez (despacho)</label>
                    <textarea name="pl_nota_validez" class="form-control" rows="2" oninput="updatePreview()" style="font-size:12px"><?= htmlspecialchars($cfg['pl_nota_validez']) ?></textarea>
                </div>
                <div class="ep-row" style="margin-top:8px">
                    <label>Mostrar línea de firma</label>
                    <label class="ep-toggle"><input type="checkbox" name="pl_show_firma" value="1" <?= chk($cfg,'pl_show_firma') ?> onchange="updatePreview()"><span class="ep-slider"></span></label>
                </div>
                <div class="ep-row" style="flex-direction:column;align-items:flex-start;gap:4px;margin-top:4px">
                    <label>Etiqueta de firma</label>
                    <input type="text" name="pl_lbl_firma" class="form-control" value="<?= htmlspecialchars($cfg['pl_lbl_firma']) ?>" oninput="updatePreview()" style="font-size:12px">
                </div>
            </div>
        </div>

        <!-- SECCIÓN: TIPOGRAFÍA -->
        <div class="ep-section">
            <div class="ep-section-title" onclick="toggleSection('sec-tipo')">
                <i class="fa-solid fa-font" style="color:var(--text-muted)"></i>
                Tipografía
                <i class="fa-solid fa-chevron-down" style="margin-left:auto;font-size:10px"></i>
            </div>
            <div class="ep-section-body" id="sec-tipo">
                <div class="ep-row">
                    <label>Tamaño de fuente (px)</label>
                    <div class="d-flex gap-2 align-center">
                        <input type="range" name="pl_font_size" min="10" max="16" step="1"
                            value="<?= (int)$cfg['pl_font_size'] ?>"
                            oninput="document.getElementById('lblFontSize').textContent=this.value+'px'; updatePreview()">
                        <span id="lblFontSize" style="font-size:12px;font-weight:700;width:32px"><?= $cfg['pl_font_size'] ?>px</span>
                    </div>
                </div>
            </div>
        </div>

        <!-- BOTONES GUARDAR -->
        <div class="save-bar">
            <button type="submit" class="btn btn-primary w-100">
                <i class="fa-solid fa-floppy-disk"></i> Guardar Plantilla
            </button>
            <button type="button" onclick="resetDefaults()" class="btn btn-light">
                <i class="fa-solid fa-rotate-left"></i>
            </button>
        </div>
    </div>

    <!-- ── PANEL DERECHO: PREVIEW ── -->
    <div class="preview-panel">
        <div class="preview-label">
            <span><i class="fa-solid fa-eye"></i> Preview en vivo — Documento de ejemplo</span>
            <span style="font-weight:400;font-size:10px">Actualiza al cambiar cualquier opción</span>
        </div>
        <div class="doc-preview" id="docPreview" style="font-size:<?= $cfg['pl_font_size'] ?>px">
            <!-- Preview generado por JS -->
        </div>
    </div>
</div><!-- /editor-wrap -->
</form>

<?php
// Datos de empresa para el JS
$empresaJS = json_encode($empresa, JSON_UNESCAPED_UNICODE);
$cfgJS     = json_encode($cfg, JSON_UNESCAPED_UNICODE);
$logoUrl   = ($empresa['logo'] && file_exists(ROOT_PATH.'/assets/uploads/'.$empresa['logo']))
    ? UPLOADS_URL.'/'.htmlspecialchars($empresa['logo'])
    : '';

$extraScript = <<<JS
<script>
var EMPRESA  = $empresaJS;
var CFG      = $cfgJS;
var LOGO_URL = "$logoUrl";

// ── Ejemplo de datos del documento ────────────────────────
var DOC = {
    numero:    'DSP00012',
    tipo:      'DESPACHO',
    tipo_doc:  'despacho',
    fecha:     new Date().toLocaleDateString('es-VE',{day:'2-digit',month:'2-digit',year:'numeric'}),
    vence:     new Date(Date.now()+30*864e5).toLocaleDateString('es-VE',{day:'2-digit',month:'2-digit',year:'numeric'}),
    tasa:      'Bs. 748,78 / \$',
    cliente:   'DISTRIBUIDORA EJEMPLO, C.A.',
    cli_rif:   'J-12345678-9',
    cli_tel:   '0414-1234567',
    cli_dir:   'Av. Principal, Sector Industrial, Barquisimeto, Lara',
    vendedor:  'Carlos Sánchez',
    tipo_cli:  'Mayorista',
    pago:      'Contado',
    items: [
        { ref:'MT02-275-18', desc:'Caucho TAURO 275-18 MT02 Whiteline', cant:50, prec:9.80 },
        { ref:'ROD-6204-2RS', desc:'Rodamiento 6204-2RS Sello Negro', cant:200, prec:0.37 },
        { ref:'CAD-428H-120', desc:'Cadena con Kit 428H-120L Dorada', cant:20, prec:3.57 },
    ],
    total_usd: 0, total_bs: 0, flete_pct: 5
};
DOC.total_usd = DOC.items.reduce((s,i)=>s+i.cant*i.prec,0);
DOC.total_bs  = DOC.total_usd * 748.78;
DOC.flete_usd = DOC.total_usd * DOC.flete_pct / 100;

function getVal(name) {
    const el = document.querySelector('[name="'+name+'"]');
    if (!el) return CFG[name] || '';
    if (el.type==='checkbox') return el.checked ? '1' : '0';
    return el.value;
}

function updatePreview() {
    const color    = getVal('pl_color_primario');
    const fontSize = getVal('pl_font_size')+'px';
    const logoSize = { small:'60px', medium:'90px', large:'120px' }[getVal('pl_logo_size')] || '90px';
    document.getElementById('lblColor').textContent = color;

    var showLogo     = getVal('pl_show_logo')==='1';
    var showRif      = getVal('pl_show_empresa_rif')==='1';
    var showDir      = getVal('pl_show_empresa_dir')==='1';
    var showTel      = getVal('pl_show_empresa_tel')==='1';
    var showEmail    = getVal('pl_show_empresa_email')==='1';
    var showBadge    = getVal('pl_show_tipo_badge')==='1';
    var showCliRif   = getVal('pl_show_cli_rif')==='1';
    var showCliDir   = getVal('pl_show_cli_dir')==='1';
    var showCliTel   = getVal('pl_show_cli_tel')==='1';
    var showVenc     = getVal('pl_show_fecha_venc')==='1';
    var showTasa     = getVal('pl_show_tasa')==='1';
    var showRef      = getVal('pl_show_col_ref')==='1';
    var showBs       = getVal('pl_show_col_bs')==='1';
    var lblCant      = getVal('pl_lbl_cantidad') || 'Cantidad';
    var lblPrecio    = getVal('pl_lbl_precio') || 'Precio \$';
    var showTotalBS  = getVal('pl_show_total_bs')==='1';
    var showFlete    = getVal('pl_show_flete')==='1';
    var showTasaT    = getVal('pl_show_tasa_usada')==='1';
    var footerTxt    = getVal('pl_footer_texto');
    var notaValidez  = getVal('pl_nota_validez');
    var showFirma    = getVal('pl_show_firma')==='1';
    var lblFirma     = getVal('pl_lbl_firma') || 'Firma del Receptor';

    // ── Header ─────────────────────────────────────────────
    var logoHtml = (showLogo && LOGO_URL) ? \`<img src="\${LOGO_URL}" style="max-width:\${logoSize};max-height:60px;object-fit:contain;display:block;margin-bottom:6px">\` : '';
    var empresaInfo = '';
    if (showRif)   empresaInfo += \`RIF: \${EMPRESA.rif}<br>\`;
    if (showDir)   empresaInfo += \`\${EMPRESA.dir}<br>\`;
    if (showTel)   empresaInfo += \`Tel: \${EMPRESA.tel}\`;
    if (showEmail) empresaInfo += (showTel?' | ':'') + EMPRESA.email;

    var badgeHtml = showBadge ? \`<span style="background:\${color};color:white;padding:3px 10px;border-radius:20px;font-size:10px;font-weight:800;display:inline-block;margin-bottom:4px">\${DOC.tipo}</span>\` : '';

    // ── Info Cliente ────────────────────────────────────────
    var cliRows = \`<div style="display:flex;justify-content:space-between;padding:2px 0"><span style="color:#718096">Nombre:</span><strong>\${DOC.cliente}</strong></div>\`;
    if (showCliRif) cliRows += \`<div style="display:flex;justify-content:space-between;padding:2px 0"><span style="color:#718096">RIF/CI:</span><strong>\${DOC.cli_rif}</strong></div>\`;
    if (showCliTel) cliRows += \`<div style="display:flex;justify-content:space-between;padding:2px 0"><span style="color:#718096">Teléfono:</span><strong>\${DOC.cli_tel}</strong></div>\`;
    if (showCliDir) cliRows += \`<div style="display:flex;justify-content:space-between;padding:2px 0"><span style="color:#718096">Dirección:</span><strong style="max-width:55%;text-align:right;font-size:11px">\${DOC.cli_dir}</strong></div>\`;

    var docRows = \`<div style="display:flex;justify-content:space-between;padding:2px 0"><span style="color:#718096">Vendedor:</span><strong>\${DOC.vendedor}</strong></div>\`;
    docRows += \`<div style="display:flex;justify-content:space-between;padding:2px 0"><span style="color:#718096">Tipo cliente:</span><strong>\${DOC.tipo_cli}</strong></div>\`;
    docRows += \`<div style="display:flex;justify-content:space-between;padding:2px 0"><span style="color:#718096">Pago:</span><strong>\${DOC.pago}</strong></div>\`;
    docRows += \`<div style="display:flex;justify-content:space-between;padding:2px 0"><span style="color:#718096">Fecha:</span><strong>\${DOC.fecha}</strong></div>\`;
    if (showVenc) docRows += \`<div style="display:flex;justify-content:space-between;padding:2px 0"><span style="color:#718096">Vence:</span><strong>\${DOC.vence}</strong></div>\`;
    if (showTasa) docRows += \`<div style="display:flex;justify-content:space-between;padding:2px 0"><span style="color:#718096">Tasa:</span><strong>\${DOC.tasa}</strong></div>\`;

    // ── Tabla de items ──────────────────────────────────────
    var thStyle = \`background:\${color};color:white;padding:7px 8px;text-align:left;font-size:10px;text-transform:uppercase;font-weight:700\`;
    var thHead = '';
    if (showRef) thHead += \`<th style="\${thStyle};width:12%">Código</th>\`;
    thHead += \`<th style="\${thStyle}">Descripción</th>\`;
    thHead += \`<th style="\${thStyle};text-align:right;width:10%">\${lblPrecio}</th>\`;
    thHead += \`<th style="\${thStyle};text-align:right;width:10%">\${lblCant}</th>\`;
    thHead += \`<th style="\${thStyle};text-align:right;width:12%">Monto \$</th>\`;
    if (showBs) thHead += \`<th style="\${thStyle};text-align:right;width:13%">Monto Bs.</th>\`;

    var tdStyle = 'padding:7px 8px;border-bottom:1px solid #e2e8f0;';
    var rowsHtml = DOC.items.map((item,i) => {
        var monto = item.cant * item.prec;
        var montoBs = monto * 748.78;
        var bg = i%2===0?'white':'#f9fafc';
        var row = \`<tr style="background:\${bg}">\`;
        if (showRef) row += \`<td style="\${tdStyle}font-family:monospace;font-size:10.5px;font-weight:700">\${item.ref}</td>\`;
        row += \`<td style="\${tdStyle}">\${item.desc}</td>\`;
        row += \`<td style="\${tdStyle}text-align:right">\${item.prec.toFixed(2)}</td>\`;
        row += \`<td style="\${tdStyle}text-align:right;font-weight:700">\${item.cant}</td>\`;
        row += \`<td style="\${tdStyle}text-align:right;font-weight:700;color:#27ae60">\$ \${monto.toFixed(2)}</td>\`;
        if (showBs) row += \`<td style="\${tdStyle}text-align:right;color:#2980b9">Bs. \${montoBs.toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g,',')}</td>\`;
        row += '</tr>';
        return row;
    }).join('');

    // ── Totales ─────────────────────────────────────────────
    var totStyle = 'padding:6px 8px;font-weight:700;text-align:right;';
    var totHtml = '';
    var colspanN = showRef ? 3 : 2;
    if (showBs) colspanN++;

    if (showFlete) {
        totHtml += \`<tr><td colspan="\${colspanN+1}" style="padding:6px 8px;text-align:right">Flete (\${DOC.flete_pct}%):</td><td style="\${totStyle};color:#f59e0b">\$ \${DOC.flete_usd.toFixed(2)}</td>\${showBs?\`<td></td>\`:''}</tr>\`;
    }
    totHtml += \`<tr style="background:#f8fafc"><td colspan="\${colspanN+1}" style="padding:8px;text-align:right;font-weight:800">TOTAL USD:</td><td style="\${totStyle};font-size:15px;color:\${color}">\$ \${DOC.total_usd.toFixed(2)}</td>\${showBs?\`<td style="\${totStyle};font-size:13px;color:#2980b9">Bs. \${DOC.total_bs.toFixed(0).replace(/\B(?=(\d{3})+(?!\d))/g,',')}</td>\`:''}</tr>\`;

    // ── Footer ──────────────────────────────────────────────
    var footerHtml = '';
    if (notaValidez) {
        footerHtml += \`<div style="background:#fffbeb;border:1px solid #fde68a;border-radius:5px;padding:8px 12px;margin-top:12px;font-size:11px">
            <i>⚠ \${notaValidez}</i></div>\`;
    }
    var firmaHtml = showFirma ? \`<div style="text-align:center;width:180px;margin-left:auto"><div style="border-top:1px solid #333;padding-top:5px;font-size:11px">\${lblFirma}</div></div>\` : '';
    var tasa2Html = showTasaT ? \`<span style="color:#718096">Tasa: \${DOC.tasa}</span>` : '';

    // ── Render final ────────────────────────────────────────
    document.getElementById('docPreview').style.fontSize = fontSize;
    document.getElementById('docPreview').innerHTML = \`
        <!-- HEADER -->
        <div style="display:flex;justify-content:space-between;align-items:flex-start;padding-bottom:16px;border-bottom:3px solid \${color};margin-bottom:18px">
            <div>
                \${logoHtml}
                <div style="font-size:18px;font-weight:800;color:\${color}">\${EMPRESA.nombre}</div>
                <div style="font-size:11px;color:#718096;margin-top:3px;line-height:1.7">\${empresaInfo}</div>
            </div>
            <div style="text-align:right">
                \${badgeHtml}
                <div style="font-size:11px;color:#718096;font-weight:700;text-transform:uppercase;letter-spacing:1px">\${DOC.tipo}</div>
                <div style="font-size:26px;font-weight:900;color:\${color};line-height:1.1">\${DOC.numero}</div>
                <div style="font-size:11.5px;color:#718096;margin-top:4px">Fecha: <strong>\${DOC.fecha}</strong></div>
            </div>
        </div>

        <!-- DATOS CLIENTE / DOC -->
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:16px;font-size:12px">
            <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:7px;padding:10px">
                <div style="font-size:9.5px;font-weight:700;text-transform:uppercase;letter-spacing:1px;color:#718096;border-bottom:1px solid #e2e8f0;padding-bottom:5px;margin-bottom:7px">DATOS DEL CLIENTE</div>
                \${cliRows}
            </div>
            <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:7px;padding:10px">
                <div style="font-size:9.5px;font-weight:700;text-transform:uppercase;letter-spacing:1px;color:#718096;border-bottom:1px solid #e2e8f0;padding-bottom:5px;margin-bottom:7px">DATOS DEL DOCUMENTO</div>
                \${docRows}
            </div>
        </div>

        <!-- TABLA PRODUCTOS -->
        <table style="width:100%;border-collapse:collapse;margin-bottom:14px;font-size:12px">
            <thead><tr>\${thHead}</tr></thead>
            <tbody>\${rowsHtml}</tbody>
            <tfoot>\${totHtml}</tfoot>
        </table>

        <!-- FOOTER -->
        \${footerHtml}
        <div style="margin-top:24px;border-top:1px solid #e2e8f0;padding-top:12px;display:flex;justify-content:space-between;align-items:flex-end;font-size:11px;color:#718096">
            <div>
                \${EMPRESA.nombre}<br>
                \${footerTxt}
                <div style="margin-top:2px">\${tasa2Html}</div>
            </div>
            \${firmaHtml}
        </div>
    \`;
}

function toggleSection(id) {
    var el = document.getElementById(id);
    if (el) el.style.display = el.style.display==='none' ? '' : 'none';
}

function resetDefaults() {
    if (!confirm('¿Resetear todos los valores a los predeterminados?')) return;
    document.querySelectorAll('[name^="pl_"]').forEach(el => {
        var defaults = {
            pl_show_logo:'1',pl_logo_size:'medium',pl_show_empresa_rif:'1',pl_show_empresa_dir:'1',
            pl_show_empresa_tel:'1',pl_show_empresa_email:'1',pl_color_primario:'#c0392b',
            pl_show_tipo_badge:'1',pl_show_fecha_venc:'1',pl_show_tasa:'1',
            pl_show_cli_rif:'1',pl_show_cli_dir:'1',pl_show_cli_tel:'1',
            pl_show_col_ref:'1',pl_show_col_bs:'1',pl_lbl_cantidad:'Cantidad',pl_lbl_precio:'Precio \$',
            pl_show_total_bs:'1',pl_show_flete:'1',pl_show_tasa_usada:'1',
            pl_footer_texto:'Gracias por su preferencia.',
            pl_nota_validez:'Este documento tiene validez de 15 días desde la fecha de emisión.',
            pl_show_firma:'1',pl_lbl_firma:'Firma del Receptor',pl_font_size:'13'
        };
        if (el.type==='checkbox') el.checked = defaults[el.name]==='1';
        else el.value = defaults[el.name] || '';
    });
    updatePreview();
}

// Render inicial
updatePreview();
// Sincronizar color input
document.querySelector('[name="pl_color_primario"]').addEventListener('input', function() {
    document.getElementById('lblColor').textContent = this.value;
    updatePreview();
});
</script>
JS;

include 'includes/footer.php';
?>
