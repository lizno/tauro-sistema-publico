</main>
</div><!-- /main-content -->
</div><!-- /wrapper -->

<!-- ══ SCRIPTS ══════════════════════════════════════════════ -->
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.datatables.net/1.13.7/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf-autotable/3.8.2/jspdf.plugin.autotable.min.js"></script>
<script src="https://cdn.sheetjs.com/xlsx-0.20.1/package/dist/xlsx.full.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>

<script>
const APP_URL = '<?= APP_URL ?>';
const CSRF    = '<?= getCsrfToken() ?>';
const EMPRESA = {
    nombre:    '<?= addslashes(clean(function_exists('getConfig') ? getConfig('empresa_nombre', APP_NAME) : APP_NAME)) ?>',
    rif:       '<?= addslashes(clean(function_exists('getConfig') ? getConfig('empresa_rif','') : '')) ?>',
    direccion: '<?= addslashes(clean(function_exists('getConfig') ? getConfig('empresa_direccion','') : '')) ?>',
};

// ── DataTables español ─────────────────────────────────────
const dtLang = {
    search:'Buscar:', lengthMenu:'Mostrar _MENU_ registros',
    info:'Mostrando _START_ a _END_ de _TOTAL_',
    infoEmpty:'Sin registros', zeroRecords:'Sin resultados',
    paginate:{ first:'«', last:'»', next:'›', previous:'‹' }
};

// ── Helper Select2 (para inicializar dentro de modales al abrirlos) ──
function initSelect2(selector, $parent) {
    if (typeof jQuery === 'undefined' || !jQuery.fn || !jQuery.fn.select2) return;
    const $el = jQuery(selector);
    if (!$el.length) return;
    if ($el.hasClass('select2-hidden-accessible')) return;
    $el.select2({
        width: '100%',
        placeholder: $el.data('placeholder') || 'Seleccione...',
        allowClear: $el.data('allow-clear') === true || $el.data('allow-clear') === 'true',
        dropdownParent: $parent || jQuery('body')
    });
}

// ── Inicialización al cargar la página ─────────────────────
$(document).ready(function() {
    // 1) DataTables
    document.querySelectorAll('.datatable').forEach(function(el) {
        const theadCols = el.querySelectorAll('thead tr th').length;
        const tbodyCols = el.querySelector('tbody tr') ?
                          el.querySelector('tbody tr').querySelectorAll('td').length : theadCols;
        if (theadCols === tbodyCols || el.querySelector('tbody tr') === null) {
            $(el).DataTable({
                language: dtLang,
                pageLength: 20,
                order: [],
                responsive: false,
                columnDefs: [{ targets: '_all', defaultContent: '' }]
            });
        }
    });

    // 2) Select2 — inicializar los VISIBLES al cargar con la config ORIGINAL
    //    (la que ya funcionaba antes). Los de modales cerrados se saltan.
    $('.select2').each(function() {
        const $el    = $(this);
        const $modal = $el.closest('.modal-overlay');

        // Si está dentro de un modal cerrado, no inicializar (la página lo hará al abrir)
        if ($modal.length && !$modal.hasClass('show') && !$modal.is(':visible')) {
            return;
        }

        // Ya inicializado → skip
        if ($el.hasClass('select2-hidden-accessible')) return;

        // Config ORIGINAL que ya funcionaba
        $el.select2({
            language: 'es',
            width: '100%',
            dropdownParent: $modal.length ? $modal : $('body')
        });
    });
});

// ── Modales ────────────────────────────────────────────────
function openModal(id)  { document.getElementById(id).classList.add('show');    document.body.style.overflow='hidden'; }
function closeModal(id) { document.getElementById(id).classList.remove('show'); document.body.style.overflow=''; }

document.querySelectorAll('.modal-overlay').forEach(m => {
    m.addEventListener('click', e => { if (e.target === m) closeModal(m.id); });
});

// ── Toast ──────────────────────────────────────────────────
function toast(msg, type = 'success') {
    Swal.fire({ toast:true, position:'top-end', showConfirmButton:false,
                timer:3200, timerProgressBar:true, icon:type, title:msg });
}

// ── Confirm delete ─────────────────────────────────────────
$(document).on('click', '.btn-delete', function(e) {
    e.preventDefault();
    const url  = $(this).data('url') || $(this).attr('href');
    const name = $(this).data('name') || 'este registro';
    Swal.fire({ title:'¿Eliminar ' + name + '?', text:'Esta acción no se puede deshacer.',
        icon:'warning', showCancelButton:true, confirmButtonColor:'#c0392b',
        cancelButtonColor:'#718096', confirmButtonText:'Sí, eliminar', cancelButtonText:'Cancelar'
    }).then(r => { if (r.isConfirmed) window.location = url; });
});

// ── Excel con encabezado de empresa ───────────────────────
function exportExcel(tableId, filename) {
    const table = document.getElementById(tableId);
    if (!table) { toast('Tabla no encontrada','error'); return; }

    const wb = XLSX.utils.book_new();

    const header = [
        [EMPRESA.nombre],
        ['RIF: ' + EMPRESA.rif],
        [EMPRESA.direccion],
        ['Exportado: ' + new Date().toLocaleString('es-VE')],
        ['']
    ];

    let ws_data = [...header];
    const ths = table.querySelectorAll('thead tr th');
    const headRow = [];
    ths.forEach(th => headRow.push(th.innerText.trim()));
    ws_data.push(headRow);

    table.querySelectorAll('tbody tr').forEach(tr => {
        const rowData = [];
        tr.querySelectorAll('td').forEach(td => {
            const clone = td.cloneNode(true);
            clone.querySelectorAll('a,button,form').forEach(el => el.remove());
            rowData.push(clone.innerText.trim().replace(/\s+/g,' '));
        });
        if (rowData.some(c => c)) ws_data.push(rowData);
    });

    const ws = XLSX.utils.aoa_to_sheet(ws_data);

    ws['!cols'] = headRow.map(() => ({ wch: 20 }));
    if (ws['!cols'][0]) ws['!cols'][0].wch = 35;

    ws['!merges'] = [
        { s:{r:0,c:0}, e:{r:0,c:Math.max(headRow.length-1,3)} },
        { s:{r:1,c:0}, e:{r:1,c:Math.max(headRow.length-1,3)} },
        { s:{r:2,c:0}, e:{r:2,c:Math.max(headRow.length-1,3)} },
        { s:{r:3,c:0}, e:{r:3,c:Math.max(headRow.length-1,3)} },
    ];

    XLSX.utils.book_append_sheet(wb, ws, 'Datos');
    XLSX.writeFile(wb, filename + '_' + new Date().toISOString().slice(0,10) + '.xlsx');
}

// ── API POST con CSRF ──────────────────────────────────────
function apiPost(url, data) {
    return fetch(APP_URL + url, {
        method:'POST',
        headers:{'Content-Type':'application/json','X-CSRF-TOKEN':CSRF},
        body: JSON.stringify(data)
    }).then(r => r.json());
}

// ── Flash desde PHP ────────────────────────────────────────
<?php if (!empty($_SESSION['flash'])): ?>
toast('<?= addslashes($_SESSION['flash']['msg']) ?>','<?= in_array($_SESSION['flash']['type'],['error','warning','info']) ? $_SESSION['flash']['type'] : 'success' ?>');
<?php unset($_SESSION['flash']); endif; ?>
</script>
<?= $extraScript ?? '' ?>
</body>
</html>