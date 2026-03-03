<?php
// personas.php — GestionPrestamo | Gestión de Personas
require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/../config/db.php';

$sesion = verificarSesion();

$rolesPermitidos = ['superadmin','admin','gerente','supervisor','cajero'];
if (!in_array($sesion['rol'], $rolesPermitidos, true)) {
    header('Location: /GestionPrestamo/'); exit;
}

$db           = getDB();
$esSuperadmin = $sesion['rol'] === 'superadmin';
// superadmin opera sobre centro 1; los demás usan su propio centro
$id_empresa    = (int)($sesion['id_empresa'] ?? 0);

// Cargar centros (solo superadmin)
$centros = [];
if ($esSuperadmin) {
    $centros = $db->query("SELECT id, nombre FROM empresas ORDER BY nombre")->fetchAll();
}

$nombreRol = match($sesion['rol']) {
    'superadmin' => 'Superadministrador', 'admin' => 'Administrador',
    'gerente'   => 'Director', 'supervisor' => 'Coordinador', 'cajero' => 'Secretaría',
    default      => ucfirst($sesion['rol'])
};
$iniciales = implode('', array_map(
    fn($w) => strtoupper($w[0]),
    array_slice(explode(' ', trim($sesion['nombre'])), 0, 2)
));
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <?php $pageTitle = 'Personas'; require_once __DIR__ . '/../php/partials/head.php'; ?>
    <style>
        /* ── Tabs de tipo ── */
        .tipo-tabs { display:flex; gap:6px; flex-wrap:wrap; margin-bottom:20px; }
        .tipo-tab  { display:flex; align-items:center; gap:7px; padding:9px 16px; border-radius:10px;
                     border:2px solid var(--border); background:var(--surface); cursor:pointer;
                     font-family:inherit; font-size:.82rem; font-weight:700; color:var(--muted);
                     transition:all .13s; box-shadow:var(--shadow); }
        .tipo-tab:hover { border-color:var(--primary); color:var(--primary); }
        .tipo-tab.active { border-color:var(--primary); background:var(--primary); color:#fff;
                           box-shadow:0 2px 10px rgba(29,78,216,.3); }
        .tipo-tab .tab-count { background:rgba(255,255,255,.25); border-radius:99px;
                               padding:1px 7px; font-size:.7rem; min-width:20px; text-align:center; }
        .tipo-tab:not(.active) .tab-count { background:var(--bg); color:var(--muted); }

        /* ── Toolbar ── */
        .toolbar { display:flex; align-items:center; justify-content:space-between;
                   flex-wrap:wrap; gap:12px; margin-bottom:16px; }
        .toolbar-left { display:flex; gap:10px; align-items:center; flex-wrap:wrap; }
        .search-box { position:relative; }
        .search-box input { padding:8px 12px 8px 34px; border:1.5px solid var(--border);
                            border-radius:9px; font-family:inherit; font-size:.83rem; width:260px;
                            outline:none; transition:border-color .12s; }
        .search-box input:focus { border-color:var(--primary); }
        .search-box .sico { position:absolute; left:10px; top:50%; transform:translateY(-50%);
                            font-size:.9rem; pointer-events:none; }
        .flt { padding:8px 12px; border:1.5px solid var(--border); border-radius:9px;
               font-family:inherit; font-size:.83rem; background:#fff; outline:none; }
        .flt:focus { border-color:var(--primary); }

        /* ── Tabla ── */
        .table-card { background:var(--surface); border:1.5px solid var(--border);
                      border-radius:12px; box-shadow:var(--shadow); overflow:hidden; }
        .table-wrap { overflow-x:auto; }
        table { width:100%; border-collapse:collapse; font-size:.83rem; }
        thead th { background:var(--bg); padding:10px 14px; text-align:left; font-size:.71rem;
                   font-weight:800; color:var(--muted); text-transform:uppercase; letter-spacing:.04em;
                   white-space:nowrap; border-bottom:1px solid var(--border); }
        tbody tr { border-bottom:1px solid var(--border); transition:background .08s; }
        tbody tr:last-child { border-bottom:none; }
        tbody tr:hover { background:var(--bg); }
        tbody td { padding:10px 14px; vertical-align:middle; }

        .td-persona { display:flex; align-items:center; gap:10px; }
        .td-avatar  { width:34px; height:34px; border-radius:50%; display:flex; align-items:center;
                      justify-content:center; font-size:.75rem; font-weight:800; color:#fff;
                      flex-shrink:0; }
        .td-name    { font-weight:700; font-size:.84rem; }
        .td-sub     { font-size:.73rem; color:var(--muted); }
        .badge { display:inline-flex; align-items:center; padding:3px 9px; border-radius:99px;
                 font-size:.71rem; font-weight:700; }
        .badge-est  { background:#dbeafe; color:#1e40af; }
        .badge-doc  { background:#d1fae5; color:#065f46; }
        .badge-emp  { background:#fef3c7; color:#92400e; }
        .badge-tut  { background:#ede9fe; color:#5b21b6; }
        .badge-ori  { background:#fce7f3; color:#9d174d; }
        .badge-psi  { background:#f0fdf4; color:#166534; }
        .badge-act  { background:#dcfce7; color:#166534; }
        .badge-ina  { background:#fee2e2; color:#991b1b; }

        .td-actions { display:flex; gap:5px; }
        .btn-icon { width:30px; height:30px; border-radius:7px; border:1.5px solid var(--border);
                    background:var(--surface); cursor:pointer; font-size:.84rem; display:flex;
                    align-items:center; justify-content:center; transition:all .12s; }
        .btn-icon:hover { border-color:var(--primary); background:#eff6ff; }
        .btn-icon.danger:hover { border-color:#fca5a5; background:#fff5f5; }

        /* ── Paginación ── */
        .pagination { display:flex; align-items:center; justify-content:space-between;
                      padding:12px 16px; border-top:1px solid var(--border); flex-wrap:wrap; gap:8px; }
        .pagination-info { font-size:.75rem; color:var(--muted); }
        .pagination-btns { display:flex; gap:4px; }
        .page-btn { padding:5px 10px; border-radius:7px; border:1.5px solid var(--border);
                    background:var(--surface); font-family:inherit; font-size:.77rem; font-weight:600;
                    color:var(--text); cursor:pointer; transition:all .1s; }
        .page-btn:hover    { border-color:var(--primary); color:var(--primary); }
        .page-btn.active   { background:var(--primary); color:#fff; border-color:var(--primary); }
        .page-btn.disabled { opacity:.4; pointer-events:none; }

        /* ── Modal ── */
        .modal-backdrop { position:fixed; inset:0; background:rgba(0,0,0,.45); z-index:200;
                          display:none; align-items:center; justify-content:center; padding:20px; }
        .modal-backdrop.open { display:flex; animation:fadeIn .15s ease; }
        @keyframes fadeIn { from{opacity:0} to{opacity:1} }
        .modal { background:var(--surface); border-radius:16px; width:100%; max-width:640px;
                 max-height:92vh; overflow-y:auto; box-shadow:0 24px 64px rgba(0,0,0,.2); }
        .modal-header { display:flex; align-items:center; justify-content:space-between;
                        padding:20px 24px 14px; border-bottom:1px solid var(--border);
                        position:sticky; top:0; background:var(--surface); z-index:1; }
        .modal-title { font-size:.95rem; font-weight:800; display:flex; align-items:center; gap:8px; }
        .modal-close { width:30px; height:30px; border-radius:8px; border:1.5px solid var(--border);
                       background:none; cursor:pointer; font-size:1rem; display:flex; align-items:center;
                       justify-content:center; transition:all .12s; }
        .modal-close:hover { background:#fee2e2; border-color:#fca5a5; }
        .modal-body   { padding:20px 24px; }
        .modal-footer { padding:14px 24px; border-top:1px solid var(--border); display:flex;
                        justify-content:flex-end; gap:10px; position:sticky; bottom:0;
                        background:var(--surface); }

        /* ── Formulario ── */
        .section-label { font-size:.72rem; font-weight:800; color:var(--muted); text-transform:uppercase;
                         letter-spacing:.06em; margin:18px 0 10px; padding-bottom:5px;
                         border-bottom:1px solid var(--border); }
        .section-label:first-child { margin-top:0; }
        .form-grid { display:grid; grid-template-columns:1fr 1fr; gap:12px; }
        .form-full  { grid-column:1/-1; }
        .field { display:flex; flex-direction:column; gap:4px; }
        .field label { font-size:.76rem; font-weight:700; color:var(--text); }
        .field input, .field select, .field textarea {
            padding:9px 12px; border:1.5px solid var(--border); border-radius:9px;
            font-family:inherit; font-size:.84rem; color:var(--text); background:#fff;
            outline:none; transition:border-color .12s; width:100%; }
        .field input:focus, .field select:focus, .field textarea:focus {
            border-color:var(--primary); box-shadow:0 0 0 3px rgba(29,78,216,.07); }
        .field-hint { font-size:.7rem; color:var(--muted); }
        .req { color:#dc2626; }

        /* ── Contactos dinámicos ── */
        .contactos-list { display:flex; flex-direction:column; gap:7px; }
        .contacto-row { display:flex; gap:7px; align-items:center; }
        .contacto-row select { width:140px; flex-shrink:0; padding:8px 10px; border:1.5px solid var(--border);
                               border-radius:8px; font-family:inherit; font-size:.82rem; outline:none; }
        .contacto-row input  { flex:1; padding:8px 10px; border:1.5px solid var(--border);
                               border-radius:8px; font-family:inherit; font-size:.82rem; outline:none; }
        .contacto-row select:focus, .contacto-row input:focus { border-color:var(--primary); }
        .btn-rm { width:28px; height:28px; border-radius:7px; border:1.5px solid #fca5a5;
                  background:#fff5f5; cursor:pointer; font-size:.85rem; flex-shrink:0; }
        .btn-add-contact { padding:7px 14px; border-radius:8px; border:1.5px dashed var(--border);
                           background:none; cursor:pointer; font-family:inherit; font-size:.78rem;
                           font-weight:700; color:var(--muted); transition:all .12s; margin-top:4px; }
        .btn-add-contact:hover { border-color:var(--primary); color:var(--primary); background:#eff6ff; }

        /* ── Campos por tipo ── */
        .tipo-fields { display:none; }
        .tipo-fields.show { display:contents; }

        /* ── Confirm modal ── */
        .modal-sm { max-width:400px; }

        /* ── Empty ── */
        .empty-state { text-align:center; padding:50px 20px; color:var(--muted); }
        .empty-icon  { font-size:2.8rem; margin-bottom:8px; }

        /* ── Foto persona ── */
        .foto-uploader { display:flex; flex-direction:column; align-items:center; gap:12px; padding:14px;
                          border:2px dashed var(--border); border-radius:12px; background:var(--bg);
                          transition:border-color .15s; cursor:pointer; }
        .foto-uploader:hover { border-color:var(--primary); }
        .foto-preview { width:80px; height:80px; border-radius:50%; object-fit:cover;
                        border:3px solid var(--primary); display:none; }
        .foto-placeholder { width:80px; height:80px; border-radius:50%; background:var(--border);
                            display:flex; align-items:center; justify-content:center;
                            font-size:2rem; color:var(--muted); }
        .foto-btns { display:flex; gap:8px; flex-wrap:wrap; justify-content:center; }
        .foto-btn { padding:6px 14px; border-radius:8px; border:1.5px solid var(--border);
                    background:#fff; cursor:pointer; font-family:inherit; font-size:.78rem;
                    font-weight:700; color:var(--muted); transition:all .12s; }
        .foto-btn:hover { border-color:var(--primary); color:var(--primary); background:#eff6ff; }
        .foto-btn.danger { border-color:#fca5a5; color:#dc2626; }
        .foto-btn.danger:hover { background:#fee2e2; }
        .foto-subiendo { font-size:.75rem; color:var(--primary); font-weight:700; display:none; }
        /* Avatar con foto en tabla */
        .td-avatar img { width:100%; height:100%; object-fit:cover; border-radius:50%; }

        /* ── Cámara modal ── */
        #camera-stream { width:100%; border-radius:10px; max-height:300px; object-fit:cover; }
        .camera-snap-btn { padding:10px 28px; border-radius:10px; border:none; background:var(--primary);
                           color:#fff; font-family:inherit; font-size:.9rem; font-weight:700;
                           cursor:pointer; transition:background .12s; }
        .camera-snap-btn:hover { background:#1e40af; }

        /* RESPONSIVE */
        @media(max-width:1024px) {
            .form-grid { grid-template-columns:1fr 1fr; }
            .modal-box { max-width:95vw; }
        }
        @media(max-width:768px) {
            .form-grid { grid-template-columns:1fr; }
            .tipo-tabs { gap:4px; flex-wrap:wrap; }
            .tipo-tab  { padding:7px 11px; font-size:.78rem; }
            .toolbar   { flex-direction:column; align-items:stretch; gap:8px; }
            .toolbar .search-box, .toolbar input { width:100%; }
            .header-actions { gap:8px; }
            .table-wrap table { min-width:520px; }
            .modal-box { padding:20px 16px; }
            .modal-footer { flex-wrap:wrap; }
        }
        @media(max-width:480px) {
            .tipo-tab  { padding:6px 9px; font-size:.74rem; }
            .btn       { min-height:44px; }
            .modal-box { padding:16px 12px; border-radius:12px; }
            .field input, .field select, .field textarea { width:100%; box-sizing:border-box; }
        }
    </style>
</head>
<body>
<div class="app">
<?php $activePage = 'personas'; require_once __DIR__ . '/../php/partials/sidebar.php'; ?>
<main class="main">
    <header class="header">
        <button class="hamburger" onclick="toggleSidebar()">☰</button>
        <div class="header-title">
            <h1>👥 Personas</h1>
            <p>Registro y gestión de todas las personas del centro</p>
        </div>
        <div class="header-actions">
            <a href="index.php" style="text-decoration:none">
                <button class="btn btn-ghost" style="padding:8px 14px;font-size:.8rem">← Dashboard</button>
            </a>
            <span class="badge-role"><?= htmlspecialchars($nombreRol) ?></span>
        </div>
    </header>

    <div class="page">

        <!-- Tabs por tipo -->
        <div class="tipo-tabs" id="tipo-tabs">
            <button class="tipo-tab active" data-tipo="" onclick="setTipo('', this)">
                🧑‍🤝‍🧑 Todos <span class="tab-count" id="cnt-todos">—</span>
            </button>
            <button class="tipo-tab" data-tipo="Cliente" onclick="setTipo('Cliente', this)">
                🎒 Clientes <span class="tab-count" id="cnt-Cliente">—</span>
            </button>
            <button class="tipo-tab" data-tipo="Asesor" onclick="setTipo('Asesor', this)">
                👨‍🏫 Asesors <span class="tab-count" id="cnt-Asesor">—</span>
            </button>
            <button class="tipo-tab" data-tipo="Empleado" onclick="setTipo('Empleado', this)">
                💼 Empleados <span class="tab-count" id="cnt-Empleado">—</span>
            </button>
            <button class="tipo-tab" data-tipo="Garante" onclick="setTipo('Garante', this)">
                👨‍👧 Garantees <span class="tab-count" id="cnt-Garante">—</span>
            </button>
            <button class="tipo-tab" data-tipo="Supervisor" onclick="setTipo('Supervisor', this)">
                🧠 Supervisores <span class="tab-count" id="cnt-Supervisor">—</span>
            </button>
            <button class="tipo-tab" data-tipo="Psicólogo" onclick="setTipo('Psicólogo', this)">
                🩺 Psicólogos <span class="tab-count" id="cnt-Psicólogo">—</span>
            </button>
        </div>

        <!-- Toolbar -->
        <div class="toolbar">
            <div class="toolbar-left">
                <div class="search-box">
                    <span class="sico">🔍</span>
                    <input type="text" id="buscar" placeholder="Buscar por nombre o cédula..."
                           oninput="filtrar()">
                </div>
                <select class="flt" id="flt-genero" data-no-search onchange="filtrar()">
                    <option value="">— Género —</option>
                    <option value="Masculino">Masculino</option>
                    <option value="Femenino">Femenino</option>
                    <option value="Otro">Otro</option>
                </select>
                <?php if ($esSuperadmin): ?>
                <select class="flt" id="flt-centro" data-no-search onchange="filtrar()">
                    <option value="">— Todos los centros —</option>
                    <?php foreach ($centros as $c): ?>
                    <option value="<?= $c['id'] ?>"><?= htmlspecialchars($c['nombre']) ?></option>
                    <?php endforeach; ?>
                </select>
                <?php endif; ?>
                <select class="flt" id="flt-per-page" data-no-search onchange="cambiarPorPagina()" title="Registros por página">
                    <option value="5" selected>5 / pág</option>
                    <option value="10">10 / pág</option>
                    <option value="25">25 / pág</option>
                    <option value="50">50 / pág</option>
                    <option value="100">100 / pág</option>
                </select>
            </div>
            <button class="btn btn-primary" id="btn-nueva" onclick="abrirNueva()">
                ➕ <span id="btn-nueva-label">Nueva Persona</span>
            </button>
        </div>

        <!-- Tabla -->
        <div class="table-card">
            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>Persona</th>
                            <th>Cédula</th>
                            <th>Tipo</th>
                            <th id="col-extra-label">Cargo / Rol</th>
                            <?php if ($esSuperadmin): ?><th>Centro</th><?php endif; ?>
                            <th>Contacto</th>
                            <th>Acciones</th>
                        </tr>
                    </thead>
                    <tbody id="tbody">
                        <tr><td colspan="<?= $esSuperadmin ? 7 : 6 ?>" style="text-align:center;padding:30px;color:var(--muted)">Cargando...</td></tr>
                    </tbody>
                </table>
            </div>
            <div class="pagination" id="paginacion"></div>
        </div>
    </div>
</main>
</div>

<!-- ═══════════════════════════════════════════════════════ -->
<!-- MODAL PERSONA                                          -->
<!-- ═══════════════════════════════════════════════════════ -->
<div class="modal-backdrop" id="modal-persona">
    <div class="modal">
        <div class="modal-header">
            <span class="modal-title" id="modal-title">➕ Nueva Persona</span>
            <button class="modal-close" onclick="cerrarModal()">✕</button>
        </div>
        <div class="modal-body">
            <input type="hidden" id="p-id">

            <!-- Selector de tipo (visible solo al crear) -->
            <div id="tipo-selector" style="margin-bottom:18px">
                <p style="font-size:.76rem;font-weight:700;margin-bottom:8px">
                    Tipo de persona <span class="req">*</span>
                </p>
                <div style="display:flex;gap:8px;flex-wrap:wrap">
                    <?php
                    $tipos = [
                        'Cliente' => ['🎒','#3b82f6'],
                        'Asesor'    => ['👨‍🏫','#10b981'],
                        'Empleado'   => ['💼','#f59e0b'],
                        'Garante'      => ['👨‍👧','#8b5cf6'],
                        'Supervisor' => ['🧠','#ec4899'],
                        'Psicólogo'  => ['🩺','#06b6d4'],
                    ];
                    foreach ($tipos as $t => [$ico, $col]):
                    ?>
                    <button type="button" class="tipo-sel-btn" data-tipo="<?= $t ?>"
                            onclick="seleccionarTipo('<?= $t ?>')"
                            style="padding:8px 14px;border-radius:9px;border:2px solid var(--border);
                                   background:var(--surface);cursor:pointer;font-family:inherit;
                                   font-size:.8rem;font-weight:700;color:var(--muted);transition:all .13s">
                        <?= $ico ?> <?= $t ?>
                    </button>
                    <?php endforeach; ?>
                </div>
                <input type="hidden" id="p-tipo">
            </div>

            <!-- ── Foto de la persona ── -->
            <p class="section-label">📷 Foto</p>
            <div class="form-full">
                <input type="hidden" id="p-foto-path">
                <div class="foto-uploader" onclick="document.getElementById('p-foto-file').click()">
                    <img id="foto-preview-img" class="foto-preview" src="" alt="Foto">
                    <div class="foto-placeholder" id="foto-placeholder">👤</div>
                    <div class="foto-btns" onclick="event.stopPropagation()">
                        <button type="button" class="foto-btn" onclick="document.getElementById('p-foto-file').click()">
                            📁 Cargar imagen
                        </button>
                        <button type="button" class="foto-btn" onclick="abrirCamara()">
                            📷 Tomar foto
                        </button>
                        <button type="button" class="foto-btn danger" id="btn-quitar-foto" style="display:none" onclick="quitarFoto()">
                            🗑️ Quitar
                        </button>
                    </div>
                    <span class="foto-subiendo" id="foto-subiendo">⏳ Subiendo...</span>
                    <span style="font-size:.72rem;color:var(--muted)" id="foto-hint">Haz clic para cargar o toma una foto</span>
                </div>
                <input type="file" id="p-foto-file" accept="image/*" style="display:none" onchange="onFotoFileSelected(this)">
            </div>

            <!-- ── Datos generales ── -->
            <p class="section-label">📋 Datos personales</p>
            <div class="form-grid">
                <div class="field">
                    <label>Nombre <span class="req">*</span></label>
                    <input type="text" id="p-nombre" placeholder="Nombre(s)">
                </div>
                <div class="field">
                    <label>Apellido <span class="req">*</span></label>
                    <input type="text" id="p-apellido" placeholder="Apellido(s)">
                </div>
                <div class="field">
                    <label>Cédula / Documento <span class="req">*</span></label>
                    <input type="text" id="p-cedula" placeholder="000-0000000-0">
                </div>
                <div class="field">
                    <label>Fecha de nacimiento <span class="req">*</span></label>
                    <input type="date" id="p-fecha-nac">
                </div>
                <div class="field">
                    <label>Género <span class="req">*</span></label>
                    <select id="p-genero" data-no-search>
                        <option value="">— Selecciona —</option>
                        <option>Masculino</option>
                        <option>Femenino</option>
                        <option>Otro</option>
                    </select>
                </div>
                <div class="field">
                    <label>Nacionalidad</label>
                    <select id="p-nacionalidad">
                        <option value="Dominicana">Dominicana</option>
                        <option value="Haitiana">Haitiana</option>
                        <option value="Americana">Americana</option>
                        <option value="Venezolana">Venezolana</option>
                        <option value="Colombiana">Colombiana</option>
                        <option value="Cubana">Cubana</option>
                        <option value="Puertorriqueña">Puertorriqueña</option>
                        <option value="Española">Española</option>
                        <option value="Italiana">Italiana</option>
                        <option value="Francesa">Francesa</option>
                        <option value="Alemana">Alemana</option>
                        <option value="Inglesa">Inglesa</option>
                        <option value="Mexicana">Mexicana</option>
                        <option value="Guatemalteca">Guatemalteca</option>
                        <option value="Hondureña">Hondureña</option>
                        <option value="Salvadoreña">Salvadoreña</option>
                        <option value="Nicaragüense">Nicaragüense</option>
                        <option value="Costarricense">Costarricense</option>
                        <option value="Panameña">Panameña</option>
                        <option value="Jamaicana">Jamaicana</option>
                        <option value="Brasileña">Brasileña</option>
                        <option value="Argentina">Argentina</option>
                        <option value="Chilena">Chilena</option>
                        <option value="Peruana">Peruana</option>
                        <option value="Ecuatoriana">Ecuatoriana</option>
                        <option value="China">China</option>
                        <option value="Otra">Otra</option>
                    </select>
                </div>
                <div class="field">
                    <label>Estado civil</label>
                    <select id="p-estado-civil" data-no-search>
                        <option value="">— Opcional —</option>
                        <option>Soltero/a</option>
                        <option>Casado/a</option>
                        <option>Divorciado/a</option>
                        <option>Viudo/a</option>
                        <option value="Union Libre">Unión Libre</option>
                    </select>
                </div>
                <?php if ($esSuperadmin): ?>
                <div class="field">
                    <label>Empresa</label>
                    <select id="p-centro">
                        <option value="">— Sin centro —</option>
                        <?php foreach ($centros as $c): ?>
                        <option value="<?= $c['id'] ?>"><?= htmlspecialchars($c['nombre']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php endif; ?>
            </div>

            <!-- ── Campos por tipo de persona ── -->

            <!-- ESTUDIANTE -->
            <div class="tipo-fields form-grid" id="fields-Cliente">
                <p class="section-label form-full">🎒 Datos del consultor</p>
                <div class="field">
                    <label>Nº Contrato <span id="cli-contrato-hint" style="font-size:.75rem;font-weight:400;color:var(--muted)"></span></label>
                    <input type="text" id="cli-contrato" placeholder="Generando…" readonly
                           style="background:var(--bg);color:var(--muted);cursor:default;border-style:dashed">
                </div>
                <div class="field">
                    <label>Fecha de ingreso</label>
                    <input type="date" id="est-ingreso">
                </div>
                <div class="field form-full">
                    <label style="display:flex;align-items:center;gap:8px;cursor:pointer;font-weight:600">
                        <input type="checkbox" id="est-nee" style="width:15px;height:15px;accent-color:var(--primary)">
                        Observaciones especiales
                    </label>
                </div>
            </div>

            <!-- DOCENTE -->
            <div class="tipo-fields form-grid" id="fields-Asesor">
                <p class="section-label form-full">👨‍🏫 Datos del asesor</p>
                <div class="field form-full">
                    <label>Especialidades / Áreas
                        <button type="button" onclick="abrirModalEspecialidad('asesor')"
                            style="margin-left:8px;padding:2px 10px;border-radius:7px;border:1.5px solid var(--primary);
                                   background:var(--primary);color:#fff;cursor:pointer;font-size:.75rem;font-weight:700">
                            ＋ Agregar
                        </button>
                    </label>
                    <div id="doc-especialidades-tags" style="display:flex;flex-wrap:wrap;gap:6px;min-height:36px;
                        padding:6px 8px;border:1.5px solid var(--border);border-radius:9px;background:#fff">
                        <span style="color:var(--muted);font-size:.78rem;align-self:center" id="doc-esp-placeholder">
                            Sin especialidades aún — haz clic en ＋ Agregar
                        </span>
                    </div>
                    <input type="hidden" id="doc-especialidades-json" value="[]">
                </div>
                <div class="field">
                    <label>Fecha de ingreso</label>
                    <input type="date" id="doc-ingreso">
                </div>
            </div>

            <!-- EMPLEADO -->
            <div class="tipo-fields form-grid" id="fields-Empleado">
                <p class="section-label form-full">💼 Datos del empleado</p>
                <div class="field">
                    <label>Cargo <span class="req">*</span></label>
                    <select id="emp-cargo">
                        <option value="">— Selecciona —</option>
                        <option>Director</option>
                        <option>Subgerente</option>
                        <option>Coordinador</option>
                        <option>Secretaria</option>
                        <option>Digitador</option>
                        <option>Conserje</option>
                        <option>Otro</option>
                    </select>
                </div>
                <div class="field">
                    <label>Fecha de ingreso</label>
                    <input type="date" id="emp-ingreso">
                </div>
            </div>

            <!-- TUTOR -->
            <div class="tipo-fields form-grid" id="fields-Garante">
                <p class="section-label form-full">👨‍👧 Datos del tutor</p>
                <div class="field">
                    <label>Tipo de tutor <span class="req">*</span></label>
                    <select id="tut-tipo" data-no-search>
                        <option value="">— Selecciona —</option>
                        <option>Madre</option>
                        <option>Padre</option>
                        <option>Encargado</option>
                        <option>Otro</option>
                    </select>
                </div>
                <div class="field">
                    <label>Ocupación</label>
                    <input type="text" id="tut-ocupacion" placeholder="ej: Maestro, Comerciante">
                </div>
                <!-- Enlace con consultors -->
                <div class="field form-full">
                    <label>Clientes a cargo
                        <button type="button" onclick="abrirModalVincular()"
                            style="margin-left:8px;padding:2px 10px;border-radius:7px;border:1.5px solid #8b5cf6;
                                   background:#8b5cf6;color:#fff;cursor:pointer;font-size:.75rem;font-weight:700">
                            ＋ Vincular consultor
                        </button>
                    </label>
                    <div id="tut-consultors-tags" style="display:flex;flex-wrap:wrap;gap:6px;min-height:36px;
                        padding:6px 8px;border:1.5px solid var(--border);border-radius:9px;background:#fff">
                        <span style="color:var(--muted);font-size:.78rem;align-self:center" id="tut-est-placeholder">
                            Sin consultors vinculados
                        </span>
                    </div>
                    <input type="hidden" id="tut-consultors-json" value="[]">
                </div>
            </div>

            <!-- ORIENTADOR -->
            <div class="tipo-fields form-grid" id="fields-Supervisor">
                <p class="section-label form-full">🧠 Datos del auditor</p>
                <div class="field form-full">
                    <label>Especialidades
                        <button type="button" onclick="abrirModalEspecialidad('auditor')"
                            style="margin-left:8px;padding:2px 10px;border-radius:7px;border:1.5px solid #ec4899;
                                   background:#ec4899;color:#fff;cursor:pointer;font-size:.75rem;font-weight:700">
                            ＋ Agregar
                        </button>
                    </label>
                    <div id="ori-especialidades-tags" style="display:flex;flex-wrap:wrap;gap:6px;min-height:36px;
                        padding:6px 8px;border:1.5px solid var(--border);border-radius:9px;background:#fff">
                        <span style="color:var(--muted);font-size:.78rem;align-self:center" id="ori-esp-placeholder">
                            Sin especialidades aún — haz clic en ＋ Agregar
                        </span>
                    </div>
                    <input type="hidden" id="ori-especialidades-json" value="[]">
                </div>
            </div>

            <!-- PSICÓLOGO -->
            <div class="tipo-fields form-grid" id="fields-Psicólogo">
                <p class="section-label form-full">🩺 Datos del psicólogo</p>
                <div class="field form-full">
                    <label>Especialidades
                        <button type="button" onclick="abrirModalEspecialidad('psicologo')"
                            style="margin-left:8px;padding:2px 10px;border-radius:7px;border:1.5px solid #06b6d4;
                                   background:#06b6d4;color:#fff;cursor:pointer;font-size:.75rem;font-weight:700">
                            ＋ Agregar
                        </button>
                    </label>
                    <div id="psi-especialidades-tags" style="display:flex;flex-wrap:wrap;gap:6px;min-height:36px;
                        padding:6px 8px;border:1.5px solid var(--border);border-radius:9px;background:#fff">
                        <span style="color:var(--muted);font-size:.78rem;align-self:center" id="psi-esp-placeholder">
                            Sin especialidades aún — haz clic en ＋ Agregar
                        </span>
                    </div>
                    <input type="hidden" id="psi-especialidades-json" value="[]">
                </div>
            </div>

            <!-- ── Contactos ── -->
            <p class="section-label">📞 Contactos</p>
            <div class="contactos-list" id="contactos-list"></div>
            <button type="button" class="btn-add-contact" onclick="addContacto()">
                ➕ Agregar contacto
            </button>

            <!-- ── Roles adicionales (multi-rol) ── -->
            <div id="multiroles-wrap" style="display:none;margin-top:18px;border:2px solid #818cf8;border-radius:12px;padding:14px 16px;background:#f5f3ff">
                <p class="section-label" style="color:#4f46e5;margin-bottom:6px">🎭 Roles adicionales</p>
                <p id="multiroles-desc" style="font-size:.74rem;color:#6b7280;margin-bottom:12px;line-height:1.5">
                    Esta persona puede tener <strong>más de un rol</strong>. El rol principal se edita arriba.<br>
                    Los roles secundarios aparecen en la tabla y activan funcionalidades adicionales.
                </p>
                <div id="multiroles-lista" style="display:flex;flex-wrap:wrap;gap:6px;margin-bottom:12px;min-height:32px"></div>
                <div style="display:grid;grid-template-columns:1fr auto;gap:8px;align-items:center">
                    <select id="multiroles-select" data-no-search style="font-size:.82rem;padding:8px 10px;border:1.5px solid #c7d2fe;border-radius:8px;background:#fff;color:var(--text);outline:none">
                        <option value="">— Seleccionar rol a agregar —</option>
                        <option value="Cliente">🎒 Cliente</option>
                        <option value="Asesor">📚 Asesor</option>
                        <option value="Empleado">💼 Empleado</option>
                        <option value="Garante">👨‍👩‍👧 Garante</option>
                        <option value="Supervisor">🧭 Supervisor</option>
                        <option value="Consultor">🧠 Psicólogo</option>
                    </select>
                    <button type="button" style="padding:8px 14px;font-size:.8rem;border-radius:8px;border:none;background:#4f46e5;color:#fff;cursor:pointer;font-weight:700;white-space:nowrap" onclick="agregarRolAdicional()">
                        ➕ Agregar
                    </button>
                </div>
                <div id="multiroles-msg" style="display:none;margin-top:8px;font-size:.75rem;padding:6px 10px;border-radius:7px"></div>
            </div>

        </div>
        <div class="modal-footer">
            <button class="btn btn-ghost" onclick="cerrarModal()">Cancelar</button>
            <button class="btn btn-primary" onclick="guardar()">💾 Guardar</button>
        </div>
    </div>
</div>

<!-- MODAL CONFIRMAR ELIMINAR -->
<div class="modal-backdrop" id="modal-confirm">
    <div class="modal modal-sm">
        <div class="modal-header">
            <span class="modal-title">🗑️ Confirmar eliminación</span>
            <button class="modal-close" onclick="document.getElementById('modal-confirm').classList.remove('open')">✕</button>
        </div>
        <div class="modal-body">
            <p style="font-size:.85rem;color:var(--muted)" id="confirm-msg"></p>
        </div>
        <div class="modal-footer">
            <button class="btn btn-ghost"
                    onclick="document.getElementById('modal-confirm').classList.remove('open')">Cancelar</button>
            <button class="btn" id="confirm-ok"
                    style="background:#dc2626;color:#fff;box-shadow:0 2px 8px rgba(220,38,38,.25)">
                Eliminar
            </button>
        </div>
    </div>
</div>

<!-- ═══════════════════════════════════════════════════════ -->
<!-- MODAL ROLES — Gestión rápida de roles sin abrir edición -->
<!-- ═══════════════════════════════════════════════════════ -->
<div class="modal-backdrop" id="modal-roles">
    <div class="modal" style="max-width:480px">
        <div class="modal-header">
            <span class="modal-title">🎭 Roles de la persona</span>
            <button class="modal-close" onclick="cerrarModalRoles()">✕</button>
        </div>
        <div class="modal-body">
            <p style="font-size:.8rem;color:var(--muted);margin:0 0 14px">
                El <strong>rol principal</strong> determina el tipo de la persona.<br>
                Los <strong>roles adicionales</strong> amplían sus funcionalidades en el sistema.
            </p>
            <!-- Nombre de persona -->
            <p id="mr-nombre" style="font-size:.9rem;font-weight:800;color:var(--text);margin:0 0 14px;padding:10px 14px;background:var(--bg);border-radius:9px;border:1.5px solid var(--border)"></p>

            <!-- Lista de roles actuales -->
            <p style="font-size:.72rem;font-weight:800;color:var(--muted);text-transform:uppercase;letter-spacing:.05em;margin:0 0 8px">Roles asignados</p>
            <div id="mr-lista" style="display:flex;flex-wrap:wrap;gap:6px;min-height:38px;padding:10px;background:var(--bg);border-radius:9px;border:1.5px solid var(--border);margin-bottom:16px">
                <span style="color:var(--muted);font-size:.8rem;align-self:center" id="mr-lista-empty">Cargando…</span>
            </div>

            <!-- Agregar rol -->
            <p style="font-size:.72rem;font-weight:800;color:var(--muted);text-transform:uppercase;letter-spacing:.05em;margin:0 0 8px">Agregar rol adicional</p>
            <div style="display:grid;grid-template-columns:1fr auto;gap:8px;align-items:center">
                <select id="mr-select" data-no-search style="font-size:.83rem;padding:9px 12px;border:1.5px solid var(--border);border-radius:9px;background:var(--surface);color:var(--text);outline:none">
                    <option value="">— Seleccionar rol —</option>
                    <option value="Cliente">🎒 Cliente</option>
                    <option value="Asesor">📚 Asesor</option>
                    <option value="Empleado">💼 Empleado</option>
                    <option value="Garante">👨‍👩‍👧 Garante</option>
                    <option value="Supervisor">🧭 Supervisor</option>
                    <option value="Consultor">🧠 Psicólogo</option>
                </select>
                <button class="btn btn-primary" style="padding:9px 16px;font-size:.83rem;white-space:nowrap" onclick="mrAgregar()">
                    ➕ Agregar
                </button>
            </div>
            <div id="mr-msg" style="display:none;margin-top:10px;font-size:.78rem;padding:7px 12px;border-radius:8px"></div>
        </div>
        <div class="modal-footer">
            <button class="btn btn-ghost" onclick="cerrarModalRoles()">Cerrar</button>
        </div>
    </div>
</div>

<!-- ═══════════════════════════════════════════════════════ -->
<!-- MODAL ESPECIALIDADES — Multi-select + Registrar nueva  -->
<!-- ═══════════════════════════════════════════════════════ -->
<style>
    .esp-check-list { max-height:220px; overflow-y:auto; border:1.5px solid var(--border);
                      border-radius:9px; margin-top:6px; }
    .esp-check-item { display:flex; align-items:center; gap:10px; padding:9px 12px;
                      border-bottom:1px solid var(--border); cursor:pointer;
                      transition:background .08s; }
    .esp-check-item:last-child { border-bottom:none; }
    .esp-check-item:hover { background:var(--bg); }
    .esp-check-item input[type=checkbox] { width:16px; height:16px;
                      accent-color:var(--primary); flex-shrink:0; cursor:pointer; }
    .esp-check-item label { font-size:.83rem; font-weight:600; cursor:pointer; flex:1; }
    .esp-check-item.checked { background:#eff6ff; }
    .esp-search-input { width:100%; padding:8px 12px; border:1.5px solid var(--border);
                        border-radius:9px; font-family:inherit; font-size:.83rem; outline:none;
                        transition:border-color .12s; margin-bottom:4px; }
    .esp-search-input:focus { border-color:var(--primary); }
    .esp-nueva-row { display:flex; gap:8px; align-items:center; margin-top:12px;
                     padding-top:12px; border-top:1px dashed var(--border); }
    .esp-nueva-row input { flex:1; padding:9px 12px; border:1.5px solid var(--border);
                           border-radius:9px; font-family:inherit; font-size:.84rem;
                           outline:none; transition:border-color .12s; }
    .esp-nueva-row input:focus { border-color:var(--primary); }
    .btn-esp-save { padding:8px 14px; border-radius:9px; border:none; background:#10b981;
                    color:#fff; cursor:pointer; font-family:inherit; font-size:.8rem;
                    font-weight:700; flex-shrink:0; transition:background .12s; white-space:nowrap; }
    .btn-esp-save:hover { background:#059669; }
    .btn-esp-save:disabled { background:#94a3b8; cursor:not-allowed; }
    .esp-selected-count { font-size:.75rem; font-weight:700; color:var(--primary); margin-top:6px; }
    #esp-nueva-hint { font-size:.71rem; color:var(--muted); margin-top:4px; }
</style>
<div class="modal-backdrop" id="modal-especialidad">
    <div class="modal" style="max-width:460px">
        <div class="modal-header">
            <span class="modal-title" id="modal-esp-titulo">📚 Especialidades</span>
            <button class="modal-close" onclick="cerrarModalEsp()">✕</button>
        </div>
        <div class="modal-body">

            <!-- Buscador dentro del listado -->
            <div class="field">
                <label>Selecciona una o más especialidades</label>
                <input type="text" class="esp-search-input" id="esp-filtro"
                       placeholder="🔍 Filtrar especialidades..."
                       oninput="filtrarEspCheckList(this.value)">
                <div class="esp-check-list" id="esp-check-list">
                    <div style="padding:20px;text-align:center;color:var(--muted);font-size:.82rem">Cargando...</div>
                </div>
                <div class="esp-selected-count" id="esp-selected-count"></div>
            </div>

            <!-- Registrar nueva especialidad -->
            <p style="font-size:.72rem;font-weight:800;color:var(--muted);text-transform:uppercase;
                      letter-spacing:.06em;margin:14px 0 0;text-align:center">— ó crear nueva —</p>
            <div class="field" style="margin-top:8px">
                <div class="esp-nueva-row">
                    <input type="text" id="esp-nueva-input"
                           placeholder="ej: Educación Especial..."
                           onkeydown="if(event.key==='Enter'){event.preventDefault();registrarNuevaEsp();}">
                    <button type="button" class="btn-esp-save" id="btn-registrar-esp"
                            onclick="registrarNuevaEsp()">💾 Crear y agregar</button>
                </div>
                <span id="esp-nueva-hint">Se guardará en el catálogo y quedará disponible para futuros registros.</span>
            </div>

        </div>
        <div class="modal-footer">
            <button class="btn btn-ghost" onclick="cerrarModalEsp()">Cancelar</button>
            <button class="btn btn-primary" onclick="confirmarEspSeleccion()">✅ Agregar seleccionadas</button>
        </div>
    </div>
</div>

<!-- ═══════════════════════════════════════════════════════ -->
<!-- MODAL VINCULAR ESTUDIANTES                            -->
<!-- ═══════════════════════════════════════════════════════ -->
<!-- ═══════════════════════════════════════════════════════ -->
<!-- MODAL VINCULAR ESTUDIANTES                            -->
<!-- ═══════════════════════════════════════════════════════ -->
<div class="modal-backdrop" id="modal-vincular">
    <div class="modal" style="max-width:500px">
        <div class="modal-header">
            <span class="modal-title">👨‍👧 Vincular Cliente al Garante</span>
            <button class="modal-close" onclick="cerrarModalVincular()">✕</button>
        </div>
        <div class="modal-body">
            <!-- Buscador -->
            <div class="field">
                <label>Buscar consultor por nombre o cédula</label>
                <input type="text" class="esp-search-input" id="vincular-buscar"
                       placeholder="🔍 Buscar nombre o cédula..."
                       oninput="buscarClientes(this.value)">
            </div>

            <!-- Lista de resultados tipo check-list -->
            <div class="esp-check-list" id="vincular-resultados" style="max-height:260px">
                <div style="padding:24px;text-align:center;color:var(--muted);font-size:.82rem">
                    <div style="font-size:1.6rem;margin-bottom:6px">🔎</div>
                    Escribe para buscar consultors...
                </div>
            </div>

            <!-- Contador de seleccionados -->
            <div class="esp-selected-count" id="vincular-count"></div>
        </div>
        <div class="modal-footer">
            <button class="btn btn-ghost" onclick="cerrarModalVincular()">Cancelar</button>
            <button class="btn btn-primary" onclick="confirmarVincular()">✅ Confirmar vínculos</button>
        </div>
    </div>
</div>

<!-- ═══════════════════════════════════════════════════════ -->
<!-- MODAL CÁMARA                                          -->
<!-- ═══════════════════════════════════════════════════════ -->
<div class="modal-backdrop" id="modal-camara">
    <div class="modal" style="max-width:500px">
        <div class="modal-header">
            <span class="modal-title">📷 Tomar Foto</span>
            <button class="modal-close" onclick="cerrarCamara()">✕</button>
        </div>
        <div class="modal-body" style="text-align:center">
            <video id="camera-stream" autoplay playsinline></video>
            <canvas id="camera-canvas" style="display:none"></canvas>
            <p style="font-size:.78rem;color:var(--muted);margin-top:8px" id="cam-hint">Posiciona la persona frente a la cámara</p>
        </div>
        <div class="modal-footer" style="justify-content:center">
            <button class="btn btn-ghost" onclick="cerrarCamara()">Cancelar</button>
            <button class="camera-snap-btn" onclick="tomarFoto()">📸 Capturar</button>
        </div>
    </div>
</div>

<div class="toast" id="toast"></div>
<script src="/GestionPrestamo/js/dashboard.js"></script>
<script>
const ES_SUPERADMIN    = <?= $esSuperadmin ? 'true' : 'false' ?>;
const ID_CENTRO_SESION = <?= $id_empresa ?? 'null' ?>;
const COLS             = <?= $esSuperadmin ? 7 : 6 ?>;

// ── Estado ──────────────────────────────────────────────────────────────────
let allData      = [];
let filtrada     = [];
let pagina       = 1;
let POR_PAGINA   = 5;
let tipoActivo   = '';

function cambiarPorPagina() {
    POR_PAGINA = parseInt(document.getElementById('flt-per-page').value) || 5;
    pagina = 1;
    renderTabla();
}

const TIPO_COLOR = {
    'Cliente':'#3b82f6','Asesor':'#10b981','Empleado':'#f59e0b',
    'Garante':'#8b5cf6','Supervisor':'#ec4899','Psicólogo':'#06b6d4'
};
const TIPO_BADGE = {
    'Cliente':'badge-est','Asesor':'badge-doc','Empleado':'badge-emp',
    'Garante':'badge-tut','Supervisor':'badge-ori','Psicólogo':'badge-psi'
};
const TIPO_ICO = {
    'Cliente':'🎒','Asesor':'👨‍🏫','Empleado':'💼',
    'Garante':'👨‍👧','Supervisor':'🧠','Psicólogo':'🩺'
};

// ── Cargar datos ─────────────────────────────────────────────────────────────
async function cargar() {
    try {
        const r    = await fetch('/GestionPrestamo/api/personas.php?action=listar');
        const data = await r.json();
        if (data.error) { showToast('❌ ' + data.error, 'error'); return; }
        allData = data.personas.map(p => {
            if (p.tipo_persona === 'Consultor') p.tipo_persona = 'Psicólogo';
            // Normalizar roles secundarios del API
            if (!Array.isArray(p.roles_secundarios)) p.roles_secundarios = [];
            p.roles_secundarios = p.roles_secundarios.map(r => r === 'Consultor' ? 'Psicólogo' : r);
            return p;
        });
        actualizarContadores();
        filtrar();
    } catch(e) { showToast('❌ Error al cargar personas', 'error'); }
}

function actualizarContadores() {
    const grupos = {};
    allData.forEach(p => {
        // Contar tipo primario
        grupos[p.tipo_persona] = (grupos[p.tipo_persona]||0)+1;
        // Contar roles secundarios (una persona puede aparecer en varios contadores)
        (p.roles_secundarios||[]).forEach(r => { grupos[r] = (grupos[r]||0)+1; });
    });
    document.getElementById('cnt-todos').textContent = allData.length;
    ['Cliente','Asesor','Empleado','Garante','Supervisor','Psicólogo'].forEach(t => {
        const el = document.getElementById('cnt-'+t);
        if (el) el.textContent = grupos[t] || 0;
    });
}

function setTipo(tipo, btn) {
    tipoActivo = tipo;
    document.querySelectorAll('.tipo-tab').forEach(t => t.classList.remove('active'));
    btn.classList.add('active');
    const labels = {
        'Cliente':'Nº Contrato','Asesor':'Especialidades','Empleado':'Cargo',
        'Garante':'Tipo','Supervisor':'Especialidades','Psicólogo':'Especialidades','':`Cargo / Tipo`
    };
    document.getElementById('col-extra-label').textContent = labels[tipo] || 'Cargo / Tipo';
    const btnLabels = {
        'Cliente':'Nuevo Cliente','Asesor':'Nuevo Asesor','Empleado':'Nuevo Empleado',
        'Garante':'Nuevo Garante','Supervisor':'Nuevo Supervisor','Psicólogo':'Nuevo Psicólogo',
        '':'Nueva Persona'
    };
    const lblEl = document.getElementById('btn-nueva-label');
    if (lblEl) lblEl.textContent = btnLabels[tipo] ?? 'Nueva Persona';
    const newUrl = window.location.pathname + (tipo ? '?tipo=' + tipo.toLowerCase() : '');
    history.replaceState(null, '', newUrl);
    pagina = 1;
    filtrar();
}

function filtrar() {
    const q      = document.getElementById('buscar').value.toLowerCase();
    const genero = document.getElementById('flt-genero').value;
    const centro = ES_SUPERADMIN ? document.getElementById('flt-centro').value : '';
    filtrada = allData.filter(p => {
        const nombre = (p.nombre + ' ' + p.apellido).toLowerCase();
        // El filtro de tipo incluye el tipo primario Y los roles secundarios
        const todosRoles = [p.tipo_persona, ...(p.roles_secundarios || [])];
        return (!tipoActivo || todosRoles.includes(tipoActivo))
            && (!q       || nombre.includes(q) || (p.cedula||'').includes(q))
            && (!genero  || p.genero === genero)
            && (!centro  || String(p.id_empresa) === centro);
    });
    pagina = 1;
    renderTabla();
}

function renderTabla() {
    const total = filtrada.length;
    const tPags = Math.max(1, Math.ceil(total / POR_PAGINA));
    pagina      = Math.min(pagina, tPags);
    const slice = filtrada.slice((pagina-1)*POR_PAGINA, pagina*POR_PAGINA);
    const tbody = document.getElementById('tbody');

    if (!slice.length) {
        tbody.innerHTML = `<tr><td colspan="${COLS}" class="empty-state">
            <div class="empty-icon">👥</div><div>Sin resultados</div></td></tr>`;
    } else {
        tbody.innerHTML = slice.map(p => {
            const ini   = (p.nombre[0]||'').toUpperCase() + (p.apellido[0]||'').toUpperCase();
            const color = TIPO_COLOR[p.tipo_persona] || '#64748b';
            const bCls  = TIPO_BADGE[p.tipo_persona] || '';
            const ico   = TIPO_ICO[p.tipo_persona] || '👤';
            const extra = extraCol(p);
            const contactoPrincipal = p.contacto_principal || '—';
            const centroCol = ES_SUPERADMIN
                ? `<td style="font-size:.76rem;color:var(--muted)">${p.centro_nombre||'—'}</td>` : '';
            const avatarContent = p.foto_path
                ? `<img src="/GestionPrestamo/uploads/fotos/${p.foto_path}" alt="${p.nombre}" loading="lazy">`
                : ini;
            return `<tr>
                <td><div class="td-persona">
                    <div class="td-avatar" style="background:${p.foto_path?'transparent':color}">${avatarContent}</div>
                    <div>
                        <div class="td-name">${p.nombre} ${p.apellido}</div>
                        <div class="td-sub">${p.cedula||'Sin cédula'}</div>
                    </div>
                </div></td>
                <td style="font-size:.8rem">${p.cedula||'—'}</td>
                <td>
                    <span class="badge ${bCls}">${ico} ${p.tipo_persona}</span>
                    ${(p.roles_secundarios||[]).map(r => {
                        const rCls = TIPO_BADGE[r] || '';
                        const rIco = TIPO_ICO[r] || '👤';
                        return `<span class="badge ${rCls}" style="font-size:.65rem;padding:2px 6px;margin-left:3px;opacity:.85" title="Rol adicional">${rIco} ${r}</span>`;
                    }).join('')}
                </td>
                <td style="font-size:.78rem;color:var(--muted)">${extra}</td>
                ${centroCol}
                <td style="font-size:.78rem">${contactoPrincipal}</td>
                <td><div class="td-actions">
                    <button class="btn-icon" title="Editar" onclick="editar(${p.id})">✏️</button>
                    <button class="btn-icon" title="Gestionar roles" onclick="abrirModalRoles(${p.id},'${p.nombre} ${p.apellido}')" style="font-size:.85rem">🎭</button>
                    <button class="btn-icon danger" title="Eliminar" onclick="confirmarEliminar(${p.id},'${p.nombre} ${p.apellido}')">🗑️</button>
                </div></td>
            </tr>`;
        }).join('');
    }

    // Paginación
    const pg = document.getElementById('paginacion');
    if (tPags <= 1) { pg.innerHTML = ''; return; }
    const ini = Math.max(1, pagina-2), fin = Math.min(tPags, pagina+2);
    let btns = '';
    if (ini > 1) btns += `<button class="page-btn" onclick="irPag(1)">1</button><span class="page-btn disabled">…</span>`;
    for (let i=ini; i<=fin; i++) btns += `<button class="page-btn${i===pagina?' active':''}" onclick="irPag(${i})">${i}</button>`;
    if (fin < tPags) btns += `<span class="page-btn disabled">…</span><button class="page-btn" onclick="irPag(${tPags})">${tPags}</button>`;
    pg.innerHTML = `
        <span class="pagination-info">${total} persona${total!==1?'s':''} · Pág ${pagina} de ${tPags}</span>
        <div class="pagination-btns">
            <button class="page-btn${pagina<=1?' disabled':''}" onclick="irPag(${pagina-1})">‹</button>
            ${btns}
            <button class="page-btn${pagina>=tPags?' disabled':''}" onclick="irPag(${pagina+1})">›</button>
        </div>`;
}
function irPag(p) { pagina = p; renderTabla(); }

function extraCol(p) {
    if (p.tipo_persona==='Cliente') return p.contrato_no || '—';
    if (p.tipo_persona==='Asesor')    return p.especialidad || '—';
    if (p.tipo_persona==='Empleado')   return p.cargo || '—';
    if (p.tipo_persona==='Garante')      return p.tipo_tutor || '—';
    if (p.tipo_persona==='Supervisor') return p.especialidad_prof || '—';
    if (p.tipo_persona==='Psicólogo')  return p.especialidad_prof || '—';
    return '—';
}

// ════════════════════════════════════════════════════════════════════════════
// ESPECIALIDADES MÚLTIPLES — Asesor, Supervisor, Psicólogo
// Multi-select con checkboxes + crear nueva en la BD
// ════════════════════════════════════════════════════════════════════════════
const espData = { asesor: [], auditor: [], psicologo: [] };

const ESP_CFG = {
    asesor:    { pfx:'doc', bg:'#dbeafe', col:'#1e40af', titulo:'📚 Especialidades del Asesor' },
    auditor: { pfx:'ori', bg:'#fce7f3', col:'#9d174d', titulo:'🧠 Especialidades del Supervisor' },
    psicologo:  { pfx:'psi', bg:'#cffafe', col:'#155e75', titulo:'🩺 Especialidades del Psicólogo' },
};

let espCtx = 'asesor';
let catalogoCache = {};
let espCheckSeleccion = []; // seleccionadas temporalmente en el modal

async function cargarCatalogo(tipo) {
    if (catalogoCache[tipo]) return catalogoCache[tipo];
    try {
        const r    = await fetch(`/GestionPrestamo/api/especialidades.php?action=listar&tipo=${tipo}`);
        const data = await r.json();
        if (data.error) return [];
        catalogoCache[tipo] = data.especialidades;
        return data.especialidades;
    } catch { return []; }
}

async function abrirModalEspecialidad(ctx) {
    espCtx = ctx;
    const cfg = ESP_CFG[ctx];
    document.getElementById('modal-esp-titulo').textContent = cfg.titulo;
    document.getElementById('esp-nueva-input').value = '';
    document.getElementById('esp-nueva-hint').style.color   = 'var(--muted)';
    document.getElementById('esp-nueva-hint').textContent   = 'Se guardará en el catálogo y quedará disponible para futuros registros.';
    document.getElementById('esp-filtro').value = '';

    // Iniciar selección temporal con lo que ya hay agregado
    espCheckSeleccion = [...espData[ctx]];

    document.getElementById('esp-check-list').innerHTML =
        '<div style="padding:20px;text-align:center;color:var(--muted);font-size:.82rem">Cargando...</div>';
    document.getElementById('modal-especialidad').classList.add('open');

    const lista = await cargarCatalogo(ctx);
    renderCheckList(lista, '');
    actualizarContadorEsp();
    setTimeout(() => document.getElementById('esp-filtro').focus(), 100);
}

function renderCheckList(lista, filtro) {
    const cfg = ESP_CFG[espCtx];
    const container = document.getElementById('esp-check-list');
    const q = filtro.toLowerCase();
    const filtradas = q ? lista.filter(e => e.nombre.toLowerCase().includes(q)) : lista;

    if (!filtradas.length) {
        container.innerHTML = '<div style="padding:16px;text-align:center;color:var(--muted);font-size:.82rem">Sin resultados</div>';
        return;
    }

    container.innerHTML = filtradas.map(esp => {
        const marcada = espCheckSeleccion.includes(esp.nombre);
        return `<div class="esp-check-item${marcada?' checked':''}" id="esp-item-${CSS.escape(esp.nombre)}"
                     onclick="toggleEspCheck('${esp.nombre.replace(/'/g,"\\'")}')">
            <input type="checkbox" id="chk-${CSS.escape(esp.nombre)}"
                   ${marcada ? 'checked' : ''}
                   onclick="event.stopPropagation();toggleEspCheck('${esp.nombre.replace(/'/g,"\\'")}')">
            <label for="chk-${CSS.escape(esp.nombre)}" style="color:${marcada?cfg.col:'var(--text)'}">${esp.nombre}</label>
        </div>`;
    }).join('');
    actualizarContadorEsp();
}

function filtrarEspCheckList(q) {
    const lista = catalogoCache[espCtx] || [];
    renderCheckList(lista, q);
}

function toggleEspCheck(nombre) {
    const idx = espCheckSeleccion.indexOf(nombre);
    if (idx === -1) {
        espCheckSeleccion.push(nombre);
    } else {
        espCheckSeleccion.splice(idx, 1);
    }
    // Actualizar visual del item
    const cfg = ESP_CFG[espCtx];
    const lista = catalogoCache[espCtx] || [];
    renderCheckList(lista, document.getElementById('esp-filtro').value);
}

function actualizarContadorEsp() {
    const n = espCheckSeleccion.length;
    const el = document.getElementById('esp-selected-count');
    el.textContent = n > 0 ? `✅ ${n} especialidad${n>1?'es':''} seleccionada${n>1?'s':''}` : '';
}

function confirmarEspSeleccion() {
    // Aplicar selección al contexto
    espData[espCtx] = [...espCheckSeleccion];
    renderEspTags(espCtx);
    cerrarModalEsp();
}

async function registrarNuevaEsp() {
    const input  = document.getElementById('esp-nueva-input');
    const btn    = document.getElementById('btn-registrar-esp');
    const hint   = document.getElementById('esp-nueva-hint');
    const nombre = input.value.trim();
    if (!nombre) { input.focus(); return; }

    btn.disabled    = true;
    btn.textContent = '⏳ Guardando...';

    try {
        const res  = await fetch('/GestionPrestamo/api/especialidades.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'crear', tipo: espCtx, nombre }),
        });
        const data = await res.json();

        if (data.success) {
            // Agregar al caché y a la selección temporal
            if (!catalogoCache[espCtx]) catalogoCache[espCtx] = [];
            if (!catalogoCache[espCtx].find(e => e.nombre === nombre)) {
                catalogoCache[espCtx].push(data.especialidad);
                catalogoCache[espCtx].sort((a,b) => a.nombre.localeCompare(b.nombre));
            }
            if (!espCheckSeleccion.includes(nombre)) espCheckSeleccion.push(nombre);
            input.value = '';
            hint.style.color   = '#10b981';
            hint.textContent   = `✅ "${nombre}" creada y seleccionada.`;
            renderCheckList(catalogoCache[espCtx], document.getElementById('esp-filtro').value);
            showToast('✅ ' + data.mensaje, 'success');
        } else {
            hint.style.color = '#dc2626';
            hint.textContent = '❌ ' + (data.error || 'Error al registrar');
        }
    } catch {
        hint.style.color = '#dc2626';
        hint.textContent = '❌ Error de conexión';
    } finally {
        btn.disabled    = false;
        btn.textContent = '💾 Crear y agregar';
    }
}

function cerrarModalEsp() {
    document.getElementById('modal-especialidad').classList.remove('open');
}

function eliminarEsp(ctx, idx) {
    espData[ctx].splice(idx, 1);
    renderEspTags(ctx);
}

function renderEspTags(ctx) {
    const cfg   = ESP_CFG[ctx];
    const lista = espData[ctx];
    const wrap  = document.getElementById(cfg.pfx + '-especialidades-tags');
    const ph    = document.getElementById(cfg.pfx + '-esp-placeholder');
    const json  = document.getElementById(cfg.pfx + '-especialidades-json');
    if (!wrap) return;
    if (json) json.value = JSON.stringify(lista);
    wrap.querySelectorAll('.esp-tag').forEach(t => t.remove());
    if (lista.length === 0) {
        if (ph) ph.style.display = '';
    } else {
        if (ph) ph.style.display = 'none';
        lista.forEach((esp, i) => {
            const tag = document.createElement('span');
            tag.className = 'esp-tag';
            tag.style.cssText = `display:inline-flex;align-items:center;gap:5px;padding:4px 10px;
                border-radius:99px;background:${cfg.bg};color:${cfg.col};font-size:.76rem;font-weight:700`;
            tag.innerHTML = `${esp}
                <button type="button" onclick="eliminarEsp('${ctx}',${i})"
                    style="border:none;background:none;cursor:pointer;color:${cfg.col};font-size:.9rem;padding:0;line-height:1">✕</button>`;
            wrap.appendChild(tag);
        });
    }
}

// ════════════════════════════════════════════════════════════════════════════
// VINCULAR ESTUDIANTES — Solo Garante
// ════════════════════════════════════════════════════════════════════════════
let tutClientes = []; // [{id, nombre}]

function abrirModalVincular() {
    document.getElementById('vincular-buscar').value = '';
    document.getElementById('vincular-resultados').innerHTML =
        '<div style="padding:24px;text-align:center;color:var(--muted);font-size:.82rem"><div style="font-size:1.6rem;margin-bottom:6px">🔎</div>Escribe para buscar consultors...</div>';
    actualizarContadorVincular();
    document.getElementById('modal-vincular').classList.add('open');
    setTimeout(() => document.getElementById('vincular-buscar').focus(), 100);
}

function cerrarModalVincular() {
    document.getElementById('modal-vincular').classList.remove('open');
}

function confirmarVincular() {
    cerrarModalVincular();
}

function actualizarContadorVincular() {
    const el = document.getElementById('vincular-count');
    if (!el) return;
    const n = tutClientes.length;
    el.textContent = n > 0 ? `${n} consultor${n>1?'s':''} vinculado${n>1?'s':''}` : '';
}

let buscarTimer = null;
function buscarClientes(q) {
    clearTimeout(buscarTimer);
    const res = document.getElementById('vincular-resultados');
    const qTrim = q.trim();
    if (qTrim.length < 2) {
        res.innerHTML = '<div style="padding:24px;text-align:center;color:var(--muted);font-size:.82rem"><div style="font-size:1.6rem;margin-bottom:6px">🔎</div>Escribe al menos 2 caracteres...</div>';
        return;
    }
    // Mostrar spinner mientras busca
    res.innerHTML = '<div style="padding:20px;text-align:center;color:var(--muted);font-size:.82rem">⏳ Buscando...</div>';
    buscarTimer = setTimeout(() => {
        const qLow = qTrim.toLowerCase();
        const encontrados = allData.filter(p =>
            p.tipo_persona === 'Cliente' &&
            ((p.nombre+' '+p.apellido).toLowerCase().includes(qLow) || (p.cedula||'').includes(qLow))
        ).slice(0, 12);

        if (!encontrados.length) {
            res.innerHTML = `<div style="padding:28px;text-align:center;color:var(--muted)">
                <div style="font-size:2rem;margin-bottom:8px">🔍</div>
                <div style="font-size:.84rem;font-weight:700">Sin resultados</div>
                <div style="font-size:.75rem;margin-top:4px">No se encontró ningún consultor con "<strong>${qTrim}</strong>"</div>
            </div>`;
            return;
        }

        const yaIds = tutClientes.map(e => e.id);

        res.innerHTML = encontrados.map(p => {
            const vinculado = yaIds.includes(p.id);
            const iniciales = ((p.nombre||'')[0]||'') + ((p.apellido||'')[0]||'');
            const colores = ['#8b5cf6','#3b82f6','#10b981','#f59e0b','#ef4444','#06b6d4'];
            const color   = colores[p.id % colores.length];
            return `<div class="esp-check-item${vinculado?' checked':''}" id="vtag-row-${p.id}"
                        onclick="toggleVincular(${p.id},'${(p.nombre+' '+p.apellido).replace(/'/g,"\\'")}', this)"
                        style="cursor:pointer;gap:12px">
                <!-- Avatar -->
                <div style="width:36px;height:36px;border-radius:50%;background:${p.foto_path?'transparent':color};
                            flex-shrink:0;display:flex;align-items:center;justify-content:center;
                            font-size:.72rem;font-weight:800;color:#fff;overflow:hidden">
                    ${p.foto_path
                        ? `<img src="/GestionPrestamo/uploads/fotos/${p.foto_path}" style="width:100%;height:100%;object-fit:cover">`
                        : iniciales.toUpperCase()}
                </div>
                <!-- Info -->
                <div style="flex:1;min-width:0">
                    <div style="font-weight:700;font-size:.83rem;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">
                        ${p.nombre} ${p.apellido}
                    </div>
                    <div style="font-size:.72rem;color:var(--muted)">
                        ${p.cedula||'Sin cédula'} · Contrato: ${p.contrato_no||'—'}
                    </div>
                </div>
                <!-- Checkbox visual -->
                <div style="width:22px;height:22px;border-radius:6px;flex-shrink:0;
                            border:2px solid ${vinculado?'#8b5cf6':'var(--border)'};
                            background:${vinculado?'#8b5cf6':'transparent'};
                            display:flex;align-items:center;justify-content:center;
                            transition:all .15s" id="vtag-chk-${p.id}">
                    ${vinculado?'<span style="color:#fff;font-size:.8rem">✓</span>':''}
                </div>
            </div>`;
        }).join('');
    }, 250);
}

function toggleVincular(id, nombre, rowEl) {
    const yaEsta = tutClientes.find(e => e.id === id);
    if (yaEsta) {
        tutClientes = tutClientes.filter(e => e.id !== id);
        rowEl.classList.remove('checked');
        const chk = document.getElementById('vtag-chk-'+id);
        if (chk) { chk.style.background='transparent'; chk.style.borderColor='var(--border)'; chk.innerHTML=''; }
    } else {
        tutClientes.push({ id, nombre });
        rowEl.classList.add('checked');
        const chk = document.getElementById('vtag-chk-'+id);
        if (chk) { chk.style.background='#8b5cf6'; chk.style.borderColor='#8b5cf6'; chk.innerHTML='<span style="color:#fff;font-size:.8rem">✓</span>'; }
    }
    renderTutTags();
    actualizarContadorVincular();
}

function vincularEst(id, nombre) {
    if (tutClientes.find(e => e.id === id)) return;
    tutClientes.push({ id, nombre });
    renderTutTags();
    actualizarContadorVincular();
    buscarClientes(document.getElementById('vincular-buscar').value);
}

function desvincularEst(id) {
    tutClientes = tutClientes.filter(e => e.id !== id);
    renderTutTags();
    actualizarContadorVincular();
}

function renderTutTags() {
    const wrap = document.getElementById('tut-consultors-tags');
    const ph   = document.getElementById('tut-est-placeholder');
    const json = document.getElementById('tut-consultors-json');
    if (!wrap) return;
    if (json) json.value = JSON.stringify(tutClientes.map(e => e.id));
    wrap.querySelectorAll('.est-tag').forEach(t => t.remove());
    if (tutClientes.length === 0) {
        if (ph) ph.style.display = '';
    } else {
        if (ph) ph.style.display = 'none';
        tutClientes.forEach(est => {
            const tag = document.createElement('span');
            tag.className = 'est-tag';
            tag.style.cssText = 'display:inline-flex;align-items:center;gap:5px;padding:4px 10px;' +
                'border-radius:99px;background:#8b5cf6;color:#fff;font-size:.76rem;font-weight:700';
            tag.innerHTML = `${est.nombre}
                <button type="button" onclick="desvincularEst(${est.id})"
                    style="border:none;background:none;cursor:pointer;color:#fff;font-size:.9rem;padding:0;line-height:1">✕</button>`;
            wrap.appendChild(tag);
        });
    }
}

// ── Modal Persona: abrir/cerrar/limpiar ──────────────────────────────────────
let tipoSeleccionado = '';

// ── Carga la próxima contrato sugerida (solo lectura) ──────────────────────
async function cargarProximaMatricula() {
    const el    = document.getElementById('cli-contrato');
    const hint  = document.getElementById('cli-contrato-hint');
    if (!el) return;
    el.value = '';
    el.placeholder = 'Generando…';
    if (hint) hint.textContent = '';
    try {
        const centroId = ES_SUPERADMIN
            ? (document.getElementById('p-centro')?.value || ID_CENTRO_SESION)
            : ID_CENTRO_SESION;
        const url = `/GestionPrestamo/api/personas.php?action=proximo_contrato&id_empresa=${centroId}`;
        const r   = await fetch(url);
        const d   = await r.json();
        if (d.contrato_no) {
            el.value = d.contrato_no;
            if (hint) hint.textContent = '(asignada al guardar)';
        }
    } catch(e) {
        if (hint) hint.textContent = '(se generará al guardar)';
    }
}

function abrirNueva() {
    limpiarModal();
    tipoSeleccionado = tipoActivo || '';
    if (tipoSeleccionado) seleccionarTipo(tipoSeleccionado, false);
    document.getElementById('tipo-selector').style.display = tipoActivo ? 'none' : 'block';
    const tituloMap = {
        'Cliente':'➕ Nuevo Cliente','Asesor':'➕ Nuevo Asesor','Empleado':'➕ Nuevo Empleado',
        'Garante':'➕ Nuevo Garante','Supervisor':'➕ Nuevo Supervisor','Psicólogo':'➕ Nuevo Psicólogo'
    };
    document.getElementById('modal-title').textContent = tituloMap[tipoActivo] || '➕ Nueva Persona';
    // Mostrar sección de roles en modo "pendientes" (se aplican tras crear)
    _rolesNuevos = [];
    const mrWrap = document.getElementById('multiroles-wrap');
    if (mrWrap) {
        mrWrap.style.display = 'block';
        mrWrap.dataset.modo = 'nuevo';
        const desc = document.getElementById('multiroles-desc');
        if (desc) desc.innerHTML = 'Agrega los <strong>roles adicionales</strong> que tendrá esta persona desde el inicio.<br>Se aplicarán automáticamente al guardar. El rol principal es el tipo seleccionado arriba.';
        renderRolesNuevos();
    }
    if (tipoActivo === 'Cliente') cargarProximaMatricula();
    document.getElementById('modal-persona').classList.add('open');
}

async function editar(id) {
    try {
        const r    = await fetch('/GestionPrestamo/api/personas.php?action=obtener&id=' + id);
        const data = await r.json();
        if (data.error) { showToast('❌ ' + data.error, 'error'); return; }
        const p = data.persona;
        if (p.tipo_persona === 'Consultor') p.tipo_persona = 'Psicólogo';
        limpiarModal();

        document.getElementById('p-id').value           = p.id;
        document.getElementById('p-nombre').value       = p.nombre;
        document.getElementById('p-apellido').value     = p.apellido;
        document.getElementById('p-cedula').value       = p.cedula || '';
        document.getElementById('p-fecha-nac').value    = p.fecha_nacimiento;
        document.getElementById('p-genero').value       = p.genero;
        document.getElementById('p-nacionalidad').value = p.nacionalidad || 'Dominicana';
        document.getElementById('p-estado-civil').value = p.estado_civil || '';
        if (ES_SUPERADMIN) document.getElementById('p-centro').value = p.id_empresa || '';
        if (window.ssRefreshAll) ssRefreshAll(document.getElementById('modal-persona'));

        // Cargar foto existente
        if (p.foto_path) {
            document.getElementById('p-foto-path').value = p.foto_path;
            const img = document.getElementById('foto-preview-img');
            img.src = '/GestionPrestamo/uploads/fotos/' + p.foto_path;
            img.style.display = 'block';
            document.getElementById('foto-placeholder').style.display = 'none';
            document.getElementById('btn-quitar-foto').style.display = '';
        }

        seleccionarTipo(p.tipo_persona, false);
        document.getElementById('tipo-selector').style.display = 'none';

        if (p.tipo_persona === 'Cliente') {
            document.getElementById('cli-contrato').value = p.contrato_no || '';
            const hintEl = document.getElementById('cli-contrato-hint');
            if (hintEl) hintEl.textContent = p.contrato_no ? '(no editable)' : '';
            document.getElementById('est-ingreso').value   = p.fecha_ingreso || '';
            document.getElementById('est-nee').checked     = p.necesidades_esp == 1;
        } else if (p.tipo_persona === 'Asesor') {
            // Cargar especialidades múltiples si vienen, si no usar el campo legacy
            const espsArr = Array.isArray(p.especialidades) && p.especialidades.length
                ? p.especialidades
                : (p.especialidad ? [p.especialidad] : []);
            espData.asesor = espsArr;
            renderEspTags('asesor');
            document.getElementById('doc-ingreso').value = p.fecha_ingreso || '';
        } else if (p.tipo_persona === 'Empleado') {
            document.getElementById('emp-cargo').value   = p.cargo || '';
            document.getElementById('emp-ingreso').value = p.fecha_ingreso || '';
        } else if (p.tipo_persona === 'Garante') {
            document.getElementById('tut-tipo').value      = p.tipo_tutor || '';
            document.getElementById('tut-ocupacion').value = p.ocupacion || '';
            // Cargar consultors vinculados (puede venir vacío o undefined)
            tutClientes = Array.isArray(p.consultors_vinculados) ? p.consultors_vinculados : [];
            renderTutTags();
        } else if (p.tipo_persona === 'Supervisor') {
            const espsArr = Array.isArray(p.especialidades) && p.especialidades.length
                ? p.especialidades : (p.especialidad_prof ? [p.especialidad_prof] : []);
            espData.auditor = espsArr;
            renderEspTags('auditor');
        } else if (p.tipo_persona === 'Psicólogo') {
            const espsArr = Array.isArray(p.especialidades) && p.especialidades.length
                ? p.especialidades : (p.especialidad_prof ? [p.especialidad_prof] : []);
            espData.psicologo = espsArr;
            renderEspTags('psicologo');
        }

        (p.contactos || []).forEach(c => addContacto(c.tipo_contacto, c.valor));

        // ── Roles adicionales (multi-rol) ─────────────────────────────────
        // Restaurar texto descriptor a modo edición
        const desc = document.getElementById('multiroles-desc');
        if (desc) desc.innerHTML = 'Esta persona puede tener <strong>más de un rol</strong>. El rol principal se edita arriba.<br>Los roles secundarios aparecen en la tabla y activan funcionalidades adicionales.';
        const mrWrap = document.getElementById('multiroles-wrap');
        if (mrWrap) mrWrap.dataset.modo = 'editar';
        cargarRolesAdicionales(p.id, p.tipos_adicionales || []);

        document.getElementById('modal-title').textContent = '✏️ Editar Persona';
        document.getElementById('modal-persona').classList.add('open');
    } catch(e) { showToast('❌ Error al cargar persona', 'error'); console.error(e); }
}

function seleccionarTipo(tipo, fromClick = true) {
    tipoSeleccionado = tipo;
    document.getElementById('p-tipo').value = tipo;
    document.querySelectorAll('.tipo-sel-btn').forEach(btn => {
        const activo = btn.dataset.tipo === tipo;
        btn.style.borderColor = activo ? 'var(--primary)' : 'var(--border)';
        btn.style.background  = activo ? 'var(--primary)' : 'var(--surface)';
        btn.style.color       = activo ? '#fff' : 'var(--muted)';
    });
    document.querySelectorAll('.tipo-fields').forEach(f => f.classList.remove('show'));
    const flds = document.getElementById('fields-' + tipo);
    if (flds) flds.classList.add('show');
}

function limpiarModal() {
    // Limpiar foto
    document.getElementById('p-foto-path').value = '';
    const img = document.getElementById('foto-preview-img');
    img.src = ''; img.style.display = 'none';
    document.getElementById('foto-placeholder').style.display = '';
    document.getElementById('btn-quitar-foto').style.display = 'none';
    document.getElementById('p-foto-file').value = '';
    document.getElementById('foto-hint').textContent = 'Haz clic para cargar o toma una foto';

    document.getElementById('p-id').value = '';
    ['p-nombre','p-apellido','p-cedula','p-nacionalidad'].forEach(id => {
        const el = document.getElementById(id); if(el) el.value = '';
    });
    document.getElementById('p-nacionalidad').value = 'Dominicana';
    document.getElementById('p-fecha-nac').value    = '';
    document.getElementById('p-genero').value        = '';
    document.getElementById('p-estado-civil').value  = '';
    document.getElementById('p-tipo').value          = '';
    if (ES_SUPERADMIN) document.getElementById('p-centro').value = '';

    ['cli-contrato','est-ingreso','doc-ingreso','emp-ingreso',
     'tut-ocupacion'].forEach(id => {
        const el = document.getElementById(id); if(el) el.value = '';
    });
    // Limpiar hint de contrato
    const hintMatr = document.getElementById('cli-contrato-hint');
    if (hintMatr) hintMatr.textContent = '';
    const cc = document.getElementById('emp-cargo'); if(cc) cc.value = '';
    const tt = document.getElementById('tut-tipo');  if(tt) tt.value = '';
    const nee = document.getElementById('est-nee'); if(nee) nee.checked = false;

    if (window.ssRefreshAll) ssRefreshAll(document.getElementById('modal-persona'));

    document.querySelectorAll('.tipo-fields').forEach(f => f.classList.remove('show'));
    document.querySelectorAll('.tipo-sel-btn').forEach(b => {
        b.style.borderColor = 'var(--border)';
        b.style.background  = 'var(--surface)';
        b.style.color       = 'var(--muted)';
    });
    tipoSeleccionado = '';
    document.getElementById('contactos-list').innerHTML = '';
    document.getElementById('tipo-selector').style.display = 'block';

    // Limpiar especialidades
    espData.asesor = []; espData.auditor = []; espData.psicologo = [];
    espCheckSeleccion = [];
    catalogoCache = {};
    ['asesor','auditor','psicologo'].forEach(ctx => {
        const cfg = ESP_CFG[ctx];
        const wrap = document.getElementById(cfg.pfx + '-especialidades-tags');
        if (wrap) wrap.querySelectorAll('.esp-tag').forEach(t => t.remove());
        const ph = document.getElementById(cfg.pfx + '-esp-placeholder');
        if (ph) ph.style.display = '';
        const json = document.getElementById(cfg.pfx + '-especialidades-json');
        if (json) json.value = '[]';
    });

    // Limpiar vínculos tutor
    tutClientes = [];
    const tutWrap = document.getElementById('tut-consultors-tags');
    if (tutWrap) tutWrap.querySelectorAll('.est-tag').forEach(t => t.remove());
    const tutPh = document.getElementById('tut-est-placeholder');
    if (tutPh) tutPh.style.display = '';
    const tutJson = document.getElementById('tut-consultors-json');
    if (tutJson) tutJson.value = '[]';

    // Ocultar sección multi-rol (solo visible al editar)
    _rolesNuevos = [];
    const mrWrap = document.getElementById('multiroles-wrap');
    if (mrWrap) {
        mrWrap.style.display = 'none';
        mrWrap.dataset.modo = '';
        document.getElementById('multiroles-lista').innerHTML = '';
    }
}

function cerrarModal() {
    document.getElementById('modal-persona').classList.remove('open');
}

// ── Contactos dinámicos ──────────────────────────────────────────────────────
let cntContacto = 0;
function addContacto(tipo='', valor='') {
    const id  = ++cntContacto;
    const div = document.createElement('div');
    div.className = 'contacto-row';
    div.id = 'ctr-' + id;
    div.innerHTML = `
        <select data-no-search>
            <option value="Telefono" ${tipo==='Telefono'||tipo==='Teléfono'?'selected':''}>📞 Teléfono</option>
            <option value="WhatsApp" ${tipo==='WhatsApp'?'selected':''}>💬 WhatsApp</option>
            <option value="Email"    ${tipo==='Email'?'selected':''}>📧 Email</option>
            <option value="Direccion" ${tipo==='Direccion'||tipo==='Dirección'?'selected':''}>📍 Dirección</option>
        </select>
        <input type="text" placeholder="Valor..." value="${valor.replace(/"/g,'&quot;')}">
        <button type="button" class="btn-rm" onclick="document.getElementById('ctr-${id}').remove()">✕</button>`;
    document.getElementById('contactos-list').appendChild(div);
}

function getContactos() {
    return [...document.querySelectorAll('#contactos-list .contacto-row')].map(row => ({
        tipo_contacto: row.querySelector('select').value,
        valor:         row.querySelector('input').value.trim()
    })).filter(c => c.valor);
}

// ── Multi-rol ─────────────────────────────────────────────────────────────────
let _mrPersonaId = 0;

function cargarRolesAdicionales(personaId, tiposData) {
    _mrPersonaId = personaId;
    const wrap = document.getElementById('multiroles-wrap');
    const lista = document.getElementById('multiroles-lista');
    if (!wrap || !lista) return;
    wrap.style.display = 'block';
    renderRolesLista(tiposData);
}

function renderRolesLista(tiposData) {
    const lista = document.getElementById('multiroles-lista');
    const sel   = document.getElementById('multiroles-select');
    if (!lista) return;

    const TIPO_ICO = {Cliente:'🎒',Asesor:'📚',Empleado:'💼',Garante:'👨‍👩‍👧',Supervisor:'🧭',Consultor:'🧠'};
    const tiposPrimarios = tiposData.filter(t => parseInt(t.es_primario) === 1).map(t => t.tipo);
    const tiposSecundarios = tiposData.filter(t => parseInt(t.es_primario) === 0);

    // Chips para cada tipo
    lista.innerHTML = tiposData.map(t => {
        const esPrimario = parseInt(t.es_primario) === 1;
        const ico = TIPO_ICO[t.tipo] || '👤';
        const label = t.tipo === 'Consultor' ? 'Psicólogo' : t.tipo;
        return `<span style="display:inline-flex;align-items:center;gap:5px;padding:4px 10px;border-radius:20px;font-size:.74rem;font-weight:700;
                    background:${esPrimario?'#dbeafe':'#f0fdf4'};color:${esPrimario?'#1e40af':'#166534'};
                    border:1.5px solid ${esPrimario?'#93c5fd':'#86efac'}">
            ${ico} ${label}
            ${esPrimario ? '<span style="font-size:.65rem;opacity:.6">(principal)</span>' :
              `<button type="button" onclick="quitarRol('${t.tipo}')"
                style="background:none;border:none;cursor:pointer;color:#ef4444;font-size:.9rem;line-height:1;padding:0 0 0 3px"
                title="Quitar rol">×</button>`}
        </span>`;
    }).join('');

    // Actualizar select — quitar tipos ya asignados
    const asignados = tiposData.map(t => t.tipo);
    Array.from(sel.options).forEach(opt => {
        opt.disabled = asignados.includes(opt.value) && opt.value !== '';
    });
}

async function agregarRolAdicional() {
    const sel  = document.getElementById('multiroles-select');
    const tipo = sel.value;
    if (!tipo) return;

    const mrWrap = document.getElementById('multiroles-wrap');
    const esNuevo = mrWrap && mrWrap.dataset.modo === 'nuevo';

    if (esNuevo) {
        // Modo creación: guardar en memoria, no llamar API todavía
        if (_rolesNuevos.includes(tipo)) {
            mrMostrarMsg('Ese rol ya está en la lista', 'error'); return;
        }
        _rolesNuevos.push(tipo);
        sel.value = '';
        renderRolesNuevos();
        mrMostrarMsg('Rol añadido — se guardará al crear la persona', 'success');
        return;
    }

    // Modo edición: llamar API inmediatamente
    if (!_mrPersonaId) return;
    const r = await fetch('/GestionPrestamo/api/personas.php', {
        method: 'POST',
        headers: {'Content-Type':'application/json'},
        body: JSON.stringify({action:'tipos_agregar', id_persona:_mrPersonaId, tipo})
    });
    const d = await r.json();
    if (d.success) {
        mrMostrarMsg(d.mensaje, 'success');
        sel.value = '';
        const r2 = await fetch('/GestionPrestamo/api/personas.php?action=tipos_listar&id=' + _mrPersonaId + '&id_persona=' + _mrPersonaId);
        const d2 = await r2.json();
        renderRolesLista(d2.tipos || []);
    } else {
        mrMostrarMsg(d.error || 'Error al agregar rol', 'error');
    }
}

async function quitarRol(tipo) {
    if (!confirm(`¿Quitar el rol "${tipo}" de esta persona?`)) return;
    const r = await fetch('/GestionPrestamo/api/personas.php', {
        method: 'POST',
        headers: {'Content-Type':'application/json'},
        body: JSON.stringify({action:'tipos_quitar', id_persona:_mrPersonaId, tipo})
    });
    const d = await r.json();
    if (d.success) {
        mrMostrarMsg(d.mensaje, 'success');
        const r2 = await fetch('/GestionPrestamo/api/personas.php?action=tipos_listar&id=' + _mrPersonaId + '&id_persona=' + _mrPersonaId);
        const d2 = await r2.json();
        renderRolesLista(d2.tipos || []);
    } else {
        mrMostrarMsg(d.error || 'Error al quitar rol', 'error');
    }
}

function mrMostrarMsg(text, tipo) {
    // Mensaje en el modal de edición (sección multi-rol del formulario)
    const el = document.getElementById('multiroles-msg');
    if (!el) return;
    el.textContent = text;
    el.style.display = 'block';
    el.style.background = tipo === 'success' ? '#dcfce7' : '#fee2e2';
    el.style.color = tipo === 'success' ? '#166534' : '#991b1b';
    setTimeout(() => el.style.display = 'none', 3500);
}

// ── Roles en modo creación (pendientes hasta guardar) ────────────────────────
let _rolesNuevos = [];

function renderRolesNuevos() {
    const lista = document.getElementById('multiroles-lista');
    const sel   = document.getElementById('multiroles-select');
    if (!lista) return;
    const TIPO_ICO = {Cliente:'🎒',Asesor:'📚',Empleado:'💼',Garante:'👨‍👩‍👧',Supervisor:'🧭',Consultor:'🧠'};

    if (!_rolesNuevos.length) {
        lista.innerHTML = '<span style="color:#6b7280;font-size:.78rem;font-style:italic">Ningún rol adicional — se guardará solo el tipo principal</span>';
    } else {
        lista.innerHTML = _rolesNuevos.map(t => {
            const ico   = TIPO_ICO[t] || '👤';
            const label = t === 'Consultor' ? 'Psicólogo' : t;
            return `<span style="display:inline-flex;align-items:center;gap:5px;padding:5px 11px;
                        border-radius:20px;font-size:.78rem;font-weight:700;
                        background:#f0fdf4;color:#166534;border:1.5px solid #86efac">
                ${ico} ${label}
                <button type="button" onclick="quitarRolNuevo('${t}')"
                    style="background:none;border:none;cursor:pointer;color:#ef4444;
                           font-size:1rem;line-height:1;padding:0 0 0 4px;font-weight:900"
                    title="Quitar">×</button>
            </span>`;
        }).join('');
    }

    // Deshabilitar en el select los roles ya en la lista
    Array.from(sel.options).forEach(opt => {
        opt.disabled = opt.value !== '' && _rolesNuevos.includes(opt.value);
    });
}

function quitarRolNuevo(tipo) {
    _rolesNuevos = _rolesNuevos.filter(r => r !== tipo);
    renderRolesNuevos();
}

// ── Modal rápido de roles (acceso desde la tabla) ─────────────────────────────
let _mrModalId = 0;

async function abrirModalRoles(id, nombre) {
    _mrModalId = id;
    // Resetear estado
    document.getElementById('mr-nombre').textContent = nombre;
    document.getElementById('mr-lista').innerHTML = '<span style="color:var(--muted);font-size:.8rem">Cargando…</span>';
    document.getElementById('mr-select').value = '';
    document.getElementById('mr-msg').style.display = 'none';
    document.getElementById('modal-roles').classList.add('open');
    // Cargar roles actuales
    await mrRecargarLista();
}

function cerrarModalRoles() {
    document.getElementById('modal-roles').classList.remove('open');
    // Si estaba en la tabla, refrescar los chips de esa fila
    if (_mrModalId) {
        // Recargar datos completos para actualizar la tabla
        cargar();
    }
    _mrModalId = 0;
}

async function mrRecargarLista() {
    const r = await fetch('/GestionPrestamo/api/personas.php?action=tipos_listar&id_persona=' + _mrModalId);
    const d = await r.json();
    mrRenderLista(d.tipos || []);
}

function mrRenderLista(tiposData) {
    const lista = document.getElementById('mr-lista');
    const sel   = document.getElementById('mr-select');
    const TIPO_ICO = {Cliente:'🎒',Asesor:'📚',Empleado:'💼',Garante:'👨‍👩‍👧',Supervisor:'🧭',Consultor:'🧠'};

    if (!tiposData.length) {
        lista.innerHTML = '<span style="color:var(--muted);font-size:.8rem">Sin roles asignados</span>';
        return;
    }

    lista.innerHTML = tiposData.map(t => {
        const esPrimario = parseInt(t.es_primario) === 1;
        const ico   = TIPO_ICO[t.tipo] || '👤';
        const label = t.tipo === 'Consultor' ? 'Psicólogo' : t.tipo;
        const bgColor  = esPrimario ? '#dbeafe' : '#f0fdf4';
        const txtColor = esPrimario ? '#1e40af' : '#166534';
        const border   = esPrimario ? '#93c5fd'  : '#86efac';
        return `<span style="display:inline-flex;align-items:center;gap:5px;padding:5px 11px;
                    border-radius:20px;font-size:.78rem;font-weight:700;
                    background:${bgColor};color:${txtColor};border:1.5px solid ${border}">
            ${ico} ${label}
            ${esPrimario
                ? '<span style="font-size:.65rem;opacity:.65;margin-left:2px">(principal)</span>'
                : `<button type="button"
                        onclick="mrQuitar('${t.tipo}')"
                        style="background:none;border:none;cursor:pointer;color:#ef4444;
                               font-size:1rem;line-height:1;padding:0 0 0 4px;font-weight:900"
                        title="Quitar este rol">×</button>`
            }
        </span>`;
    }).join('');

    // Deshabilitar en el select los tipos ya asignados
    const asignados = tiposData.map(t => t.tipo);
    Array.from(sel.options).forEach(opt => {
        opt.disabled = opt.value !== '' && asignados.includes(opt.value);
    });
}

async function mrAgregar() {
    const tipo = document.getElementById('mr-select').value;
    if (!tipo || !_mrModalId) return;
    const r = await fetch('/GestionPrestamo/api/personas.php', {
        method: 'POST',
        headers: {'Content-Type':'application/json'},
        body: JSON.stringify({action:'tipos_agregar', id_persona: _mrModalId, tipo})
    });
    const d = await r.json();
    mrMostrarMsgModal(d.success ? d.mensaje : (d.error || 'Error'), d.success ? 'success' : 'error');
    if (d.success) {
        document.getElementById('mr-select').value = '';
        await mrRecargarLista();
    }
}

async function mrQuitar(tipo) {
    if (!confirm(`¿Quitar el rol "${tipo === 'Consultor' ? 'Psicólogo' : tipo}"?`)) return;
    const r = await fetch('/GestionPrestamo/api/personas.php', {
        method: 'POST',
        headers: {'Content-Type':'application/json'},
        body: JSON.stringify({action:'tipos_quitar', id_persona: _mrModalId, tipo})
    });
    const d = await r.json();
    mrMostrarMsgModal(d.success ? d.mensaje : (d.error || 'Error'), d.success ? 'success' : 'error');
    if (d.success) await mrRecargarLista();
}

function mrMostrarMsgModal(text, tipo) {
    const el = document.getElementById('mr-msg');
    if (!el) return;
    el.textContent = text;
    el.style.display = 'block';
    el.style.background = tipo === 'success' ? '#dcfce7' : '#fee2e2';
    el.style.color      = tipo === 'success' ? '#166534' : '#991b1b';
    setTimeout(() => { if(el) el.style.display = 'none'; }, 3500);
}

// ── Guardar ──────────────────────────────────────────────────────────────────
async function guardar() {
    const id       = document.getElementById('p-id').value;
    const tipo     = document.getElementById('p-tipo').value;
    const nombre   = document.getElementById('p-nombre').value.trim();
    const apellido = document.getElementById('p-apellido').value.trim();
    const fechaNac = document.getElementById('p-fecha-nac').value;
    const genero   = document.getElementById('p-genero').value;

    if (!tipo)    { showToast('❌ Selecciona el tipo de persona', 'error'); return; }
    if (!nombre)  { showToast('❌ El nombre es obligatorio', 'error'); return; }
    if (!apellido){ showToast('❌ El apellido es obligatorio', 'error'); return; }
    if (!fechaNac){ showToast('❌ La fecha de nacimiento es obligatoria', 'error'); return; }
    if (!genero)  { showToast('❌ El género es obligatorio', 'error'); return; }

    const cedula = document.getElementById('p-cedula').value.trim();
    if (!cedula)  { showToast('❌ La cédula / documento es obligatoria', 'error'); return; }

    const payload = {
        action:           id ? 'editar' : 'crear',
        id,
        tipo_persona:     tipo === 'Psicólogo' ? 'Consultor' : tipo,
        nombre, apellido,
        cedula:           cedula,
        fecha_nacimiento: fechaNac,
        genero,
        nacionalidad:     document.getElementById('p-nacionalidad').value.trim() || 'Dominicana',
        estado_civil:     document.getElementById('p-estado-civil').value || null,
        id_empresa:        ES_SUPERADMIN ? (document.getElementById('p-centro').value || null) : ID_CENTRO_SESION,
        foto_path:        document.getElementById('p-foto-path').value || null,
        contactos:        getContactos(),
    };

    if (tipo === 'Cliente') {
        payload.contrato_no    = document.getElementById('cli-contrato').value.trim() || null;
        payload.fecha_ingreso   = document.getElementById('est-ingreso').value || null;
        payload.necesidades_esp = document.getElementById('est-nee').checked ? 1 : 0;

    } else if (tipo === 'Asesor') {
        payload.especialidades  = espData.asesor;
        payload.especialidad    = espData.asesor[0] || null;
        payload.fecha_ingreso   = document.getElementById('doc-ingreso').value || null;
        payload.cargo           = 'Asesor';

    } else if (tipo === 'Empleado') {
        payload.cargo         = document.getElementById('emp-cargo').value;
        payload.fecha_ingreso = document.getElementById('emp-ingreso').value || null;
        if (!payload.cargo) { showToast('❌ Selecciona el cargo del empleado', 'error'); return; }

    } else if (tipo === 'Garante') {
        payload.tipo_tutor             = document.getElementById('tut-tipo').value;
        payload.ocupacion              = document.getElementById('tut-ocupacion').value.trim() || null;
        payload.consultors_vinculados = tutClientes.map(e => e.id);
        if (!payload.tipo_tutor) { showToast('❌ Selecciona el tipo de tutor', 'error'); return; }

    } else if (tipo === 'Supervisor') {
        payload.especialidades    = espData.auditor;
        payload.especialidad_prof = espData.auditor[0] || null;

    } else if (tipo === 'Psicólogo') {
        payload.especialidades    = espData.psicologo;
        payload.especialidad_prof = espData.psicologo[0] || null;
    }

    try {
        const res  = await fetch('/GestionPrestamo/api/personas.php', {
            method:'POST', headers:{'Content-Type':'application/json'},
            body: JSON.stringify(payload)
        });
        const data = await res.json();
        if (data.success) {
            // Si es creación y hay roles adicionales pendientes, aplicarlos ahora
            const esCreacion = !id;
            if (esCreacion && _rolesNuevos.length && data.id) {
                const nuevoId = data.id;
                const errores = [];
                for (const rol of _rolesNuevos) {
                    try {
                        const rr = await fetch('/GestionPrestamo/api/personas.php', {
                            method: 'POST',
                            headers: {'Content-Type':'application/json'},
                            body: JSON.stringify({action:'tipos_agregar', id_persona: nuevoId, tipo: rol})
                        });
                        const dd = await rr.json();
                        if (!dd.success) errores.push(rol);
                    } catch { errores.push(rol); }
                }
                _rolesNuevos = [];
                if (errores.length) {
                    showToast(`✅ Persona creada, pero no se pudo asignar el rol: ${errores.join(', ')}`, 'error');
                } else {
                    showToast('✅ ' + data.mensaje, 'success');
                }
            } else {
                showToast('✅ ' + data.mensaje, 'success');
            }
            cerrarModal();
            cargar();
        } else { showToast('❌ ' + data.error, 'error'); }
    } catch { showToast('❌ Error de conexión', 'error'); }
}

// ── Eliminar ─────────────────────────────────────────────────────────────────
function confirmarEliminar(id, nombre) {
    document.getElementById('confirm-msg').innerHTML =
        `¿Eliminar a <strong>${nombre}</strong>? Se eliminarán también sus contactos. Esta acción no se puede deshacer.`;
    document.getElementById('confirm-ok').onclick = async () => {
        document.getElementById('modal-confirm').classList.remove('open');
        const res  = await fetch('/GestionPrestamo/api/personas.php', {
            method:'POST', headers:{'Content-Type':'application/json'},
            body: JSON.stringify({ action:'eliminar', id })
        });
        const data = await res.json();
        data.success
            ? (showToast('✅ Persona eliminada', 'success'), cargar())
            : showToast('❌ ' + data.error, 'error');
    };
    document.getElementById('modal-confirm').classList.add('open');
}

// Cerrar modales al click fuera
document.querySelectorAll('.modal-backdrop').forEach(b => {
    b.addEventListener('click', e => { if(e.target===b) b.classList.remove('open'); });
});
['modal-especialidad','modal-vincular'].forEach(id => {
    const el = document.getElementById(id);
    if (el) el.addEventListener('click', e => { if(e.target===el) el.classList.remove('open'); });
});

// ── Init desde hash (#consultor, #asesor, etc.) o query param (?tipo=) ──────
function initDesdeHash() {
    const urlParams = new URLSearchParams(window.location.search);
    const qTipo = (urlParams.get('tipo') || '').toLowerCase();
    const hash = qTipo || window.location.hash.replace('#','').toLowerCase();
    const mapa = { 'consultor':'Cliente','asesor':'Asesor','empleado':'Empleado',
                   'tutor':'Garante','auditor':'Supervisor','psicologo':'Psicólogo' };
    const tipo = mapa[hash] || '';
    if (tipo) {
        const tabBtn = document.querySelector(`.tipo-tab[data-tipo="${tipo}"]`);
        if (tabBtn) {
            tipoActivo = tipo;
            document.querySelectorAll('.tipo-tab').forEach(t => t.classList.remove('active'));
            tabBtn.classList.add('active');
            const btnLabels = {
                'Cliente':'Nuevo Cliente','Asesor':'Nuevo Asesor','Empleado':'Nuevo Empleado',
                'Garante':'Nuevo Garante','Supervisor':'Nuevo Supervisor','Psicólogo':'Nuevo Psicólogo'
            };
            const lblEl = document.getElementById('btn-nueva-label');
            if (lblEl) lblEl.textContent = btnLabels[tipo] ?? 'Nueva Persona';
        }
    }
}

// ════════════════════════════════════════════════════════════════════════════
// FOTO — Cargar, Cámara, Subir
// ════════════════════════════════════════════════════════════════════════════
let cameraStream = null;

function mostrarFotoPreview(src) {
    const img = document.getElementById('foto-preview-img');
    img.src = src; img.style.display = 'block';
    document.getElementById('foto-placeholder').style.display = 'none';
    document.getElementById('btn-quitar-foto').style.display = '';
    document.getElementById('foto-hint').textContent = '✅ Foto lista';
}

function quitarFoto() {
    document.getElementById('p-foto-path').value = '';
    const img = document.getElementById('foto-preview-img');
    img.src = ''; img.style.display = 'none';
    document.getElementById('foto-placeholder').style.display = '';
    document.getElementById('btn-quitar-foto').style.display = 'none';
    document.getElementById('p-foto-file').value = '';
    document.getElementById('foto-hint').textContent = 'Haz clic para cargar o toma una foto';
}

async function subirFotoBlob(blob, filename) {
    document.getElementById('foto-subiendo').style.display = 'block';
    document.getElementById('foto-hint').textContent = '⏳ Subiendo imagen...';
    const formData = new FormData();
    formData.append('foto', blob, filename);
    const personaId = document.getElementById('p-id').value;
    if (personaId) formData.append('persona_id', personaId);

    try {
        const res  = await fetch('/GestionPrestamo/api/personas.php?action=subir_foto', {
            method: 'POST', body: formData
        });
        const data = await res.json();
        if (data.success) {
            document.getElementById('p-foto-path').value = data.foto_path;
            showToast('✅ Foto subida correctamente', 'success');
        } else {
            showToast('❌ ' + (data.error || 'Error al subir foto'), 'error');
            document.getElementById('foto-hint').textContent = 'Error al subir. Intenta de nuevo.';
        }
    } catch {
        showToast('❌ Error de conexión al subir foto', 'error');
    } finally {
        document.getElementById('foto-subiendo').style.display = 'none';
    }
}

function onFotoFileSelected(input) {
    const file = input.files[0];
    if (!file) return;
    if (file.size > 5 * 1024 * 1024) { showToast('❌ La imagen no puede superar 5 MB', 'error'); return; }

    // Mostrar preview inmediato
    const reader = new FileReader();
    reader.onload = e => mostrarFotoPreview(e.target.result);
    reader.readAsDataURL(file);

    // Subir al servidor
    subirFotoBlob(file, file.name);
}

async function abrirCamara() {
    document.getElementById('modal-camara').classList.add('open');
    try {
        cameraStream = await navigator.mediaDevices.getUserMedia({ video: { facingMode:'user' }, audio: false });
        document.getElementById('camera-stream').srcObject = cameraStream;
    } catch(e) {
        document.getElementById('cam-hint').textContent = '❌ No se pudo acceder a la cámara: ' + e.message;
    }
}

function cerrarCamara() {
    if (cameraStream) { cameraStream.getTracks().forEach(t => t.stop()); cameraStream = null; }
    document.getElementById('modal-camara').classList.remove('open');
    document.getElementById('camera-stream').srcObject = null;
}

function tomarFoto() {
    const video  = document.getElementById('camera-stream');
    const canvas = document.getElementById('camera-canvas');
    canvas.width  = video.videoWidth  || 640;
    canvas.height = video.videoHeight || 480;
    const ctx = canvas.getContext('2d');
    ctx.drawImage(video, 0, 0, canvas.width, canvas.height);

    canvas.toBlob(blob => {
        const dataURL = canvas.toDataURL('image/jpeg', 0.85);
        mostrarFotoPreview(dataURL);
        cerrarCamara();
        subirFotoBlob(blob, 'foto_camara_' + Date.now() + '.jpg');
    }, 'image/jpeg', 0.85);
}

initDesdeHash();
cargar();
</script>
</body>
</html>