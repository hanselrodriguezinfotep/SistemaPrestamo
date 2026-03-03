<?php
// usuarios.php — GestionPrestamo | Gestión de Usuarios & Roles
require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/../config/db.php';

$sesion = verificarSesion();

if (!in_array($sesion['rol'], ['superadmin', 'admin'], true)) {
    header('Location: /GestionPrestamo/index.php?error=noaccess');
    exit;
}

$db           = getDB();
$esSuperadmin = $sesion['rol'] === 'superadmin';
// superadmin opera sobre centro 1; los demás usan su propio centro
$id_empresa    = (int)($sesion['id_empresa'] ?? 0);

// ── Cargar centros (solo superadmin ve todos) ─────────────────────────────────
$centros = [];
if ($esSuperadmin) {
    $centros = $db->query("SELECT id, nombre FROM empresas ORDER BY nombre")->fetchAll();
}

// ── Cargar roles disponibles (excluye superadmin para admin) ──────────────────
$rolesQuery = $esSuperadmin
    ? "SELECT id, nombre, descripcion FROM roles ORDER BY id"
    : "SELECT id, nombre, descripcion FROM roles WHERE nombre NOT IN ('superadmin','admin') ORDER BY id";
$roles = $db->query($rolesQuery)->fetchAll();

// ── Cargar todos los permisos agrupados ───────────────────────────────────────
$permisos = $db->query("SELECT id, nombre, descripcion FROM permisos ORDER BY nombre")->fetchAll();

// Agrupar permisos por módulo (prefijo antes del _)
$permisosGrupos = [];
foreach ($permisos as $p) {
    $grupo = explode('_', $p['nombre'])[0];
    $permisosGrupos[$grupo][] = $p;
}

$nombreRol = $esSuperadmin ? 'Superadministrador' : 'Administrador';
$iniciales  = implode('', array_map(
    fn($w) => strtoupper($w[0]),
    array_slice(explode(' ', trim($sesion['nombre'])), 0, 2)
));
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <?php $pageTitle = 'Usuarios & Roles'; require_once __DIR__ . '/../php/partials/head.php'; ?>
    <style>
        .page { max-width: 1200px; }

        /* ── Tabs ── */
        .tabs { display:flex; gap:4px; background:var(--surface); border:1px solid var(--border);
                border-radius:12px; padding:5px; margin-bottom:22px; box-shadow:var(--shadow); width:fit-content; }
        .tab  { padding:8px 20px; border-radius:8px; border:none; background:none; cursor:pointer;
                font-family:inherit; font-size:.82rem; font-weight:700; color:var(--muted); transition:all .12s; }
        .tab.active { background:var(--primary); color:#fff; box-shadow:0 2px 8px rgba(29,78,216,.3); }
        .tab:hover:not(.active) { background:var(--bg); color:var(--text); }
        .section { display:none; }
        .section.active { display:block; }

        /* ── Toolbar ── */
        .toolbar { display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap;
                   gap:12px; margin-bottom:18px; }
        .toolbar-left  { display:flex; gap:10px; align-items:center; flex-wrap:wrap; }
        .search-box    { position:relative; }
        .search-box input { padding:8px 12px 8px 34px; border:1.5px solid var(--border); border-radius:9px;
                            font-family:inherit; font-size:.83rem; width:240px; outline:none;
                            transition:border-color .12s; }
        .search-box input:focus { border-color:var(--primary); }
        .search-box .icon { position:absolute; left:10px; top:50%; transform:translateY(-50%);
                            color:var(--muted); font-size:.9rem; pointer-events:none; }
        .filter-select { padding:8px 12px; border:1.5px solid var(--border); border-radius:9px;
                         font-family:inherit; font-size:.83rem; color:var(--text); background:#fff; outline:none; }
        .filter-select:focus { border-color:var(--primary); }

        /* ── Tabla ── */
        .table-card { background:var(--surface); border:1.5px solid var(--border); border-radius:12px;
                      box-shadow:var(--shadow); overflow:hidden; }
        .table-wrap { overflow-x:auto; }
        table { width:100%; border-collapse:collapse; font-size:.83rem; }
        thead th { background:var(--bg); padding:10px 14px; text-align:left; font-size:.71rem;
                   font-weight:800; color:var(--muted); text-transform:uppercase; letter-spacing:.04em;
                   white-space:nowrap; border-bottom:1px solid var(--border); }
        tbody tr { border-bottom:1px solid var(--border); transition:background .08s; }
        tbody tr:last-child { border-bottom:none; }
        tbody tr:hover { background:var(--bg); }
        tbody td { padding:11px 14px; vertical-align:middle; }
        .td-user   { display:flex; align-items:center; gap:10px; }
        .td-avatar { width:34px; height:34px; border-radius:50%; background:linear-gradient(135deg,var(--primary),#2563eb);
                     color:#fff; font-size:.75rem; font-weight:800; display:flex; align-items:center;
                     justify-content:center; flex-shrink:0; }
        .td-name   { font-weight:700; font-size:.83rem; }
        .td-username { font-size:.74rem; color:var(--muted); }
        .td-actions { display:flex; gap:6px; }
        .btn-icon { width:30px; height:30px; border-radius:7px; border:1.5px solid var(--border);
                    background:var(--surface); cursor:pointer; font-size:.85rem; display:flex;
                    align-items:center; justify-content:center; transition:all .12s; }
        .btn-icon:hover { border-color:var(--primary); background:#eff6ff; }
        .btn-icon.danger:hover { border-color:#fca5a5; background:#fff5f5; }

        /* ── Badges ── */
        .badge { display:inline-flex; align-items:center; gap:3px; padding:3px 9px; border-radius:99px;
                 font-size:.71rem; font-weight:700; white-space:nowrap; }
        .badge-active   { background:#dcfce7; color:#166534; }
        .badge-inactive { background:#fee2e2; color:#991b1b; }
        .badge-rol      { background:#dbeafe; color:#1e40af; }
        .badge-superadmin { background:#fef3c7; color:#92400e; }
        .badge-admin    { background:#ede9fe; color:#5b21b6; }

        /* ── Paginación ── */
        .pagination { display:flex; align-items:center; justify-content:space-between;
                      padding:12px 16px; border-top:1px solid var(--border); flex-wrap:wrap; gap:8px; }
        .pagination-info { font-size:.75rem; color:var(--muted); }
        .pagination-btns { display:flex; gap:4px; }
        .page-btn { padding:5px 10px; border-radius:7px; border:1.5px solid var(--border);
                    background:var(--surface); font-family:inherit; font-size:.77rem; font-weight:600;
                    color:var(--text); cursor:pointer; text-decoration:none; transition:all .1s; }
        .page-btn:hover   { border-color:var(--primary); color:var(--primary); }
        .page-btn.active  { background:var(--primary); color:#fff; border-color:var(--primary); }
        .page-btn.disabled{ opacity:.4; pointer-events:none; }

        /* ── Roles grid ── */
        .roles-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(340px,1fr)); gap:16px; }
        .role-card  { background:var(--surface); border:1.5px solid var(--border); border-radius:14px;
                      padding:20px; box-shadow:var(--shadow); transition:all .15s; position:relative; }
        .role-card:hover { box-shadow:0 6px 24px rgba(29,78,216,.1); border-color:#c7d7fe; transform:translateY(-1px); }
        .role-card-header { display:flex; align-items:flex-start; justify-content:space-between; margin-bottom:6px; }
        .role-badge-sys  { font-size:.67rem; font-weight:700; color:var(--muted); padding:2px 8px;
                            border-radius:99px; background:var(--bg); border:1px solid var(--border); white-space:nowrap; }
        .role-name   { font-size:1rem; font-weight:800; display:flex; align-items:center; gap:6px; }
        .role-name .role-ico { width:28px; height:28px; border-radius:7px; background:var(--bg);
                               display:inline-flex; align-items:center; justify-content:center; font-size:.9rem; flex-shrink:0; }
        .role-desc   { font-size:.76rem; color:var(--muted); margin-bottom:14px; margin-top:2px; }
        .role-perms  { display:flex; flex-wrap:wrap; gap:5px; }
        .perm-tag    { padding:3px 9px; border-radius:99px; font-size:.7rem; font-weight:600;
                       background:var(--bg); color:var(--text); border:1px solid var(--border); }
        .perm-tag-more { padding:3px 9px; border-radius:99px; font-size:.7rem; font-weight:700;
                         background:#eff6ff; color:var(--primary); border:1px solid #bfdbfe; cursor:pointer; }
        .role-actions { display:flex; gap:6px; flex-shrink:0; }

        /* ── Modal ── */
        .modal-backdrop { position:fixed; inset:0; background:rgba(0,0,0,.45); z-index:200;
                          display:none; align-items:center; justify-content:center; padding:20px; }
        .modal-backdrop.open { display:flex; animation:fadeIn .15s ease; }
        @keyframes fadeIn { from{opacity:0} to{opacity:1} }
        .modal { background:var(--surface); border-radius:16px; width:100%; max-width:560px;
                 max-height:90vh; overflow-y:auto; box-shadow:0 24px 64px rgba(0,0,0,.2); }
        .modal-lg { max-width:720px; }
        .modal-header { display:flex; align-items:center; justify-content:space-between;
                        padding:20px 24px 16px; border-bottom:1px solid var(--border); position:sticky; top:0;
                        background:var(--surface); z-index:1; }
        .modal-title  { font-size:.95rem; font-weight:800; }
        .modal-close  { width:30px; height:30px; border-radius:8px; border:1.5px solid var(--border);
                        background:none; cursor:pointer; font-size:1rem; display:flex; align-items:center;
                        justify-content:center; transition:all .12s; }
        .modal-close:hover { background:#fee2e2; border-color:#fca5a5; }
        .modal-body   { padding:20px 24px; }
        .modal-footer { padding:16px 24px; border-top:1px solid var(--border); display:flex;
                        justify-content:flex-end; gap:10px; position:sticky; bottom:0;
                        background:var(--surface); }

        /* ── Formularios ── */
        .form-grid { display:grid; grid-template-columns:1fr 1fr; gap:14px; }
        .form-full { grid-column:1/-1; }
        .field { display:flex; flex-direction:column; gap:5px; }
        .field label { font-size:.76rem; font-weight:700; }
        .field input, .field select, .field textarea {
            padding:9px 12px; border:1.5px solid var(--border); border-radius:9px;
            font-family:inherit; font-size:.84rem; color:var(--text); background:#fff;
            outline:none; transition:border-color .12s; width:100%; }
        .field input:focus, .field select:focus { border-color:var(--primary);
            box-shadow:0 0 0 3px rgba(29,78,216,.08); }
        .field-hint { font-size:.7rem; color:var(--muted); }
        .field-required { color:#dc2626; }

        /* ── Permisos checkbox grid ── */
        .perms-section { margin-bottom:16px; }
        .perms-section-title { font-size:.72rem; font-weight:800; color:var(--muted);
                               text-transform:uppercase; letter-spacing:.05em; margin-bottom:8px;
                               padding-bottom:5px; border-bottom:1px solid var(--border); }
        .perms-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(200px,1fr)); gap:6px; }
        .perm-check { display:flex; align-items:flex-start; gap:8px; padding:7px 10px; border-radius:8px;
                      border:1.5px solid var(--border); cursor:pointer; transition:all .1s; }
        .perm-check:has(input:checked) { border-color:var(--primary); background:#eff6ff; }
        .perm-check input { width:15px; height:15px; accent-color:var(--primary); flex-shrink:0; margin-top:1px; }
        .perm-check-label { font-size:.76rem; font-weight:600; }
        .perm-check-desc  { font-size:.68rem; color:var(--muted); margin-top:1px; }

        /* ── Search persona ── */
        .persona-search-results { border:1.5px solid var(--border); border-radius:9px; max-height:200px;
                                  overflow-y:auto; display:none; margin-top:4px; }
        .persona-result { padding:9px 12px; cursor:pointer; border-bottom:1px solid var(--border);
                          font-size:.82rem; transition:background .08s; }
        .persona-result:last-child { border-bottom:none; }
        .persona-result:hover { background:var(--bg); }
        .persona-result-name { font-weight:700; }
        .persona-result-info { font-size:.72rem; color:var(--muted); }
        .persona-selected { background:#eff6ff; border:1.5px solid var(--primary); border-radius:9px;
                            padding:10px 12px; display:none; align-items:center; justify-content:space-between; }
        .persona-selected.show { display:flex; }

        /* ── Empty state ── */
        .empty-state { text-align:center; padding:50px 20px; color:var(--muted); }
        .empty-icon  { font-size:2.5rem; margin-bottom:10px; }

        /* RESPONSIVE */
        @media(max-width:1024px) {
            .form-grid { grid-template-columns:1fr 1fr; }
            .modal-box { max-width:95vw; }
        }
        @media(max-width:768px) {
            .form-grid { grid-template-columns:1fr; }
            .toolbar { flex-direction:column; align-items:stretch; gap:8px; }
            .search-box input { width:100%; }
            .table-wrap table { min-width:480px; }
            .filter-row { flex-wrap:wrap; }
            .modal-box { padding:20px 16px; }
            .header-actions { gap:8px; }
        }
        @media(max-width:480px) {
            .btn       { min-height:44px; }
            .modal-box { padding:16px 12px; border-radius:12px; }
            .field input, .field select { width:100%; box-sizing:border-box; }
        }
    </style>
</head>
<body>
<div class="app">
<?php $activePage = 'usuarios'; require_once __DIR__ . '/../php/partials/sidebar.php'; ?>
<main class="main">
    <header class="header">
        <button class="hamburger" onclick="toggleSidebar()">☰</button>
        <div class="header-title">
            <h1>🔐 Usuarios & Roles</h1>
            <p>Gestión de accesos y permisos del sistema</p>
        </div>
        <div class="header-actions">
            <a href="index.php" style="text-decoration:none">
                <button class="btn btn-ghost" style="padding:8px 14px;font-size:.8rem">← Dashboard</button>
            </a>
            <span class="badge-role"><?= htmlspecialchars($nombreRol) ?></span>
        </div>
    </header>

    <div class="page">

        <!-- Tabs -->
        <div class="tabs">
            <button class="tab active" onclick="switchTab('usuarios', this)">👤 Usuarios</button>
            <button class="tab" onclick="switchTab('roles', this)">🎭 Roles</button>
        </div>

        <!-- ═══════════════════════════════════════════════ -->
        <!-- SECCIÓN USUARIOS                               -->
        <!-- ═══════════════════════════════════════════════ -->
        <div class="section active" id="sec-usuarios">

            <div class="toolbar">
                <div class="toolbar-left">
                    <div class="search-box">
                        <span class="icon">🔍</span>
                        <input type="text" id="buscar-usuario" placeholder="Buscar por nombre o usuario..."
                               oninput="filtrarUsuarios()">
                    </div>
                    <select class="filter-select" id="filtro-rol" data-no-search onchange="filtrarUsuarios()">
                        <option value="">— Todos los roles —</option>
                        <?php foreach ($roles as $r): ?>
                        <option value="<?= htmlspecialchars($r['nombre']) ?>">
                            <?= htmlspecialchars(ucfirst($r['nombre'])) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                    <select class="filter-select" id="filtro-estado" data-no-search onchange="filtrarUsuarios()">
                        <option value="">— Todos —</option>
                        <option value="1">✅ Activos</option>
                        <option value="0">⛔ Inactivos</option>
                    </select>
                    <?php if ($esSuperadmin): ?>
                    <select class="filter-select" id="filtro-centro" data-no-search onchange="filtrarUsuarios()">
                        <option value="">— Todos los centros —</option>
                        <?php foreach ($centros as $c): ?>
                        <option value="<?= $c['id'] ?>"><?= htmlspecialchars($c['nombre']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <?php endif; ?>
                    <select class="filter-select" id="flt-per-page-usuarios" data-no-search onchange="cambiarPorPaginaUsuarios()" title="Registros por página">
                        <option value="5" selected>5 / pág</option>
                        <option value="10">10 / pág</option>
                        <option value="25">25 / pág</option>
                        <option value="50">50 / pág</option>
                    </select>
                </div>
                <button class="btn btn-primary" onclick="abrirModalCrearUsuario()">
                    ➕ Nuevo Usuario
                </button>
            </div>

            <div class="table-card">
                <div class="table-wrap">
                    <table id="tabla-usuarios">
                        <thead>
                            <tr>
                                <th>Usuario</th>
                                <th>Rol</th>
                                <?php if ($esSuperadmin): ?><th>Centro</th><?php endif; ?>
                                <th>Último acceso</th>
                                <th>Estado</th>
                                <th>Acciones</th>
                            </tr>
                        </thead>
                        <tbody id="tbody-usuarios">
                            <tr><td colspan="<?= $esSuperadmin ? 6 : 5 ?>" style="text-align:center;padding:30px;color:var(--muted)">Cargando...</td></tr>
                        </tbody>
                    </table>
                </div>
                <div class="pagination" id="paginacion-usuarios"></div>
            </div>
        </div>

        <!-- ═══════════════════════════════════════════════ -->
        <!-- SECCIÓN ROLES                                  -->
        <!-- ═══════════════════════════════════════════════ -->
        <div class="section" id="sec-roles">

            <div class="toolbar">
                <div class="toolbar-left">
                    <span style="font-size:.83rem;color:var(--muted)">
                        Gestiona los roles y sus permisos
                    </span>
                </div>
                <button class="btn btn-primary" onclick="abrirModalCrearRol()">
                    ➕ Nuevo Rol
                </button>
            </div>

            <div class="roles-grid" id="roles-grid">
                <div style="text-align:center;padding:40px;color:var(--muted)">Cargando roles...</div>
            </div>
        </div>

    </div><!-- /page -->
</main>
</div>

<!-- ═══════════════════════════════════════════ -->
<!-- MODAL: CREAR / EDITAR USUARIO             -->
<!-- ═══════════════════════════════════════════ -->
<div class="modal-backdrop" id="modal-usuario">
    <div class="modal">
        <div class="modal-header">
            <span class="modal-title" id="modal-usuario-title">➕ Nuevo Usuario</span>
            <button class="modal-close" onclick="cerrarModal('modal-usuario')">✕</button>
        </div>
        <div class="modal-body">
            <input type="hidden" id="u-id">
            <input type="hidden" id="u-persona-id">

            <!-- Búsqueda de persona -->
            <div id="persona-search-wrapper" style="margin-bottom:18px">
                <p style="font-size:.78rem;font-weight:700;margin-bottom:8px">👤 Persona vinculada <span class="field-required">*</span></p>
                <input type="text" id="persona-buscar"
                       placeholder="🔍 Buscar por nombre o cédula..."
                       style="width:100%;padding:9px 12px;border:1.5px solid var(--border);border-radius:9px;
                              font-family:inherit;font-size:.84rem;outline:none;transition:border-color .12s"
                       oninput="buscarPersona(this.value)"
                       onfocus="this.style.borderColor='var(--primary)'"
                       onblur="this.style.borderColor='var(--border)'">
                <div class="persona-search-results" id="persona-resultados"></div>
            </div>

            <!-- Datos de la persona seleccionada (solo lectura) -->
            <div id="persona-info-box" style="display:none;margin-bottom:18px;
                 background:#f0f7ff;border:1.5px solid var(--primary);border-radius:10px;padding:12px 16px">
                <div style="display:flex;justify-content:space-between;align-items:center">
                    <div>
                        <div id="persona-info-nombre" style="font-weight:800;font-size:.9rem;color:var(--text)"></div>
                        <div id="persona-info-detalle" style="font-size:.75rem;color:var(--muted);margin-top:2px"></div>
                    </div>
                    <button type="button" id="btn-cambiar-persona" onclick="deseleccionarPersona()"
                            style="background:none;border:1.5px solid var(--border);border-radius:7px;
                                   cursor:pointer;font-size:.75rem;font-weight:700;color:var(--muted);
                                   padding:4px 10px;transition:all .12s"
                            onmouseover="this.style.borderColor='var(--primary)';this.style.color='var(--primary)'"
                            onmouseout="this.style.borderColor='var(--border)';this.style.color='var(--muted)'">
                        ✕ Cambiar
                    </button>
                </div>
            </div>

            <div class="form-grid">
                <div class="field">
                    <label>Nombre completo</label>
                    <input type="text" id="u-nombre-display" placeholder="Se cargará al seleccionar persona"
                           readonly style="background:#f8fafc;cursor:not-allowed;color:var(--muted)">
                </div>
                <div class="field">
                    <label>Rol <span class="field-required">*</span>
                        <span id="rol-auto-badge" style="display:none;margin-left:6px;padding:2px 7px;
                              border-radius:99px;background:#dbeafe;color:#1e40af;font-size:.68rem;font-weight:700">
                            Auto-asignado
                        </span>
                    </label>
                    <select id="u-rol">
                        <option value="">— Selecciona rol —</option>
                        <?php foreach ($roles as $r): ?>
                        <option value="<?= $r['id'] ?>"><?= htmlspecialchars(ucfirst($r['nombre'])) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php if ($esSuperadmin): ?>
                <div class="field">
                    <label>Empresa</label>
                    <select id="u-centro">
                        <option value="">— Global (superadmin) —</option>
                        <?php foreach ($centros as $c): ?>
                        <option value="<?= $c['id'] ?>"><?= htmlspecialchars($c['nombre']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php endif; ?>
                <div class="field">
                    <label>Nombre de usuario <span class="field-required">*</span></label>
                    <input type="text" id="u-username" placeholder="ej: jperez" autocomplete="off">
                    <span class="field-hint">Solo letras, números y punto. Sin espacios.</span>
                </div>
                <div class="field" id="campo-password">
                    <label>Contraseña <span class="field-required" id="pass-required">*</span></label>
                    <input type="password" id="u-password" placeholder="Mínimo 8 caracteres" autocomplete="new-password">
                    <span class="field-hint" id="pass-hint">Se enviará al usuario si el SMTP está configurado</span>
                </div>
                <div class="field form-full">
                    <label style="display:flex;align-items:center;gap:8px;cursor:pointer">
                        <input type="checkbox" id="u-cambiar-pass" style="width:16px;height:16px;accent-color:var(--primary)" checked>
                        <span>Obligar cambio de contraseña en el próximo inicio de sesión</span>
                    </label>
                </div>
            </div>
        </div>
        <div class="modal-footer">
            <button class="btn btn-ghost" onclick="cerrarModal('modal-usuario')">Cancelar</button>
            <button class="btn btn-primary" onclick="guardarUsuario()">💾 Guardar</button>
        </div>
    </div>
</div>

<!-- ═══════════════════════════════════════════ -->
<!-- MODAL: CREAR / EDITAR ROL                 -->
<!-- ═══════════════════════════════════════════ -->
<div class="modal-backdrop" id="modal-rol">
    <div class="modal modal-lg">
        <div class="modal-header">
            <span class="modal-title" id="modal-rol-title">➕ Nuevo Rol</span>
            <button class="modal-close" onclick="cerrarModal('modal-rol')">✕</button>
        </div>
        <div class="modal-body">
            <input type="hidden" id="r-id">
            <div class="form-grid" style="margin-bottom:20px">
                <div class="field">
                    <label>Nombre del rol <span class="field-required">*</span></label>
                    <input type="text" id="r-nombre" placeholder="ej: supervisor_academico">
                    <span class="field-hint">Sin espacios, en minúsculas</span>
                </div>
                <div class="field">
                    <label>Descripción <span class="field-required">*</span></label>
                    <input type="text" id="r-descripcion" placeholder="ej: Coordinador del área académica">
                </div>
            </div>
            <p style="font-size:.78rem;font-weight:800;margin-bottom:14px;color:var(--text)">
                🔑 Permisos del rol
                <button type="button" onclick="toggleTodosPermisos()"
                        style="margin-left:10px;padding:3px 10px;border-radius:6px;border:1.5px solid var(--border);
                               background:var(--bg);font-family:inherit;font-size:.7rem;font-weight:700;cursor:pointer">
                    Marcar / Desmarcar todos
                </button>
            </p>
            <div id="permisos-container">
                <?php foreach ($permisosGrupos as $grupo => $items): ?>
                <div class="perms-section">
                    <div class="perms-section-title">
                        <?= htmlspecialchars(ucfirst($grupo)) ?>
                    </div>
                    <div class="perms-grid">
                        <?php foreach ($items as $p): ?>
                        <label class="perm-check">
                            <input type="checkbox" name="permiso" value="<?= $p['id'] ?>">
                            <div>
                                <div class="perm-check-label"><?= htmlspecialchars($p['nombre']) ?></div>
                                <div class="perm-check-desc"><?= htmlspecialchars($p['descripcion']) ?></div>
                            </div>
                        </label>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <div class="modal-footer">
            <button class="btn btn-ghost" onclick="cerrarModal('modal-rol')">Cancelar</button>
            <button class="btn btn-primary" onclick="guardarRol()">💾 Guardar Rol</button>
        </div>
    </div>
</div>

<!-- ═══════════════════════════════════════════ -->
<!-- MODAL: RESETEAR CONTRASEÑA                -->
<!-- ═══════════════════════════════════════════ -->
<div class="modal-backdrop" id="modal-reset">
    <div class="modal" style="max-width:420px">
        <div class="modal-header">
            <span class="modal-title">🔑 Resetear Contraseña</span>
            <button class="modal-close" onclick="cerrarModal('modal-reset')">✕</button>
        </div>
        <div class="modal-body">
            <p style="font-size:.83rem;margin-bottom:16px;color:var(--muted)">
                Ingresa la nueva contraseña para <strong id="reset-nombre-usuario"></strong>
            </p>
            <input type="hidden" id="reset-id">
            <div class="field">
                <label>Nueva contraseña <span class="field-required">*</span></label>
                <input type="password" id="reset-password" placeholder="Mínimo 8 caracteres">
            </div>
            <label style="display:flex;align-items:center;gap:8px;cursor:pointer;margin-top:12px;font-size:.82rem">
                <input type="checkbox" id="reset-cambiar" checked style="width:15px;height:15px;accent-color:var(--primary)">
                Obligar cambio en el próximo inicio de sesión
            </label>
        </div>
        <div class="modal-footer">
            <button class="btn btn-ghost" onclick="cerrarModal('modal-reset')">Cancelar</button>
            <button class="btn btn-primary" onclick="confirmarReset()">🔑 Cambiar Contraseña</button>
        </div>
    </div>
</div>

<!-- ═══════════════════════════════════════════ -->
<!-- MODAL: CONFIRMAR ELIMINAR                 -->
<!-- ═══════════════════════════════════════════ -->
<div class="modal-backdrop" id="modal-confirm">
    <div class="modal" style="max-width:400px">
        <div class="modal-header">
            <span class="modal-title" id="confirm-title">⚠️ Confirmar</span>
            <button class="modal-close" onclick="cerrarModal('modal-confirm')">✕</button>
        </div>
        <div class="modal-body">
            <p style="font-size:.85rem;color:var(--muted)" id="confirm-msg"></p>
        </div>
        <div class="modal-footer">
            <button class="btn btn-ghost" onclick="cerrarModal('modal-confirm')">Cancelar</button>
            <button class="btn" id="confirm-btn"
                    style="background:#dc2626;color:#fff;box-shadow:0 2px 8px rgba(220,38,38,.25)">
                Confirmar
            </button>
        </div>
    </div>
</div>

<div class="toast" id="toast"></div>
<script src="/GestionPrestamo/js/dashboard.js"></script>
<script>
const ES_SUPERADMIN = <?= $esSuperadmin ? 'true' : 'false' ?>;
const ID_CENTRO_SESION = <?= $id_empresa ? $id_empresa : 'null' ?>;

// ── Estado ─────────────────────────────────────────────────────────────────────
let usuariosData   = [];
let paginaActual   = 1;
let POR_PAGINA     = 5;
let personaSelId   = null;
let buscarTimeout  = null;

function cambiarPorPaginaUsuarios() {
    POR_PAGINA = parseInt(document.getElementById('flt-per-page-usuarios').value) || 5;
    paginaActual = 1;
    filtrarUsuarios();
}

// ── Tabs ───────────────────────────────────────────────────────────────────────
function switchTab(id, btn) {
    document.querySelectorAll('.section').forEach(s => s.classList.remove('active'));
    document.querySelectorAll('.tab').forEach(t => t.classList.remove('active'));
    document.getElementById('sec-' + id).classList.add('active');
    btn.classList.add('active');
    if (id === 'roles') cargarRoles();
}

// ── Cargar usuarios ────────────────────────────────────────────────────────────
async function cargarUsuarios() {
    try {
        const res  = await fetch('/GestionPrestamo/api/usuarios.php?action=listar');
        const data = await res.json();
        if (data.error) { showToast('❌ ' + data.error, 'error'); return; }
        usuariosData = data.usuarios;
        filtrarUsuarios();
    } catch { showToast('❌ Error al cargar usuarios', 'error'); }
}

function filtrarUsuarios() {
    const buscar  = document.getElementById('buscar-usuario').value.toLowerCase();
    const rol     = document.getElementById('filtro-rol').value;
    const estado  = document.getElementById('filtro-estado').value;
    const centro  = ES_SUPERADMIN ? document.getElementById('filtro-centro').value : '';

    let filtrados = usuariosData.filter(u => {
        const matchBuscar  = !buscar  || u.nombre_completo.toLowerCase().includes(buscar) || u.username.toLowerCase().includes(buscar);
        const matchRol     = !rol     || u.rol === rol;
        const matchEstado  = estado === '' || String(u.activo) === estado;
        const matchCentro  = !centro  || String(u.id_empresa) === centro;
        return matchBuscar && matchRol && matchEstado && matchCentro;
    });

    paginaActual = 1;
    renderTablaUsuarios(filtrados);
}

function renderTablaUsuarios(lista) {
    const total     = lista.length;
    const totalPags = Math.max(1, Math.ceil(total / POR_PAGINA));
    paginaActual    = Math.min(paginaActual, totalPags);
    const desde     = (paginaActual - 1) * POR_PAGINA;
    const pagina    = lista.slice(desde, desde + POR_PAGINA);
    const cols      = ES_SUPERADMIN ? 6 : 5;

    const tbody = document.getElementById('tbody-usuarios');

    if (pagina.length === 0) {
        tbody.innerHTML = `<tr><td colspan="${cols}" class="empty-state">
            <div class="empty-icon">👤</div>
            <div>No se encontraron usuarios</div></td></tr>`;
    } else {
        tbody.innerHTML = pagina.map(u => {
            const iniciales = u.nombre_completo.split(' ').slice(0,2).map(w=>w[0]||'').join('').toUpperCase();
            const estadoBadge = u.activo == 1
                ? '<span class="badge badge-active">✅ Activo</span>'
                : '<span class="badge badge-inactive">⛔ Inactivo</span>';
            const rolBadge = u.rol === 'superadmin'
                ? `<span class="badge badge-superadmin">⭐ ${u.rol}</span>`
                : u.rol === 'admin'
                ? `<span class="badge badge-admin">🛡️ ${u.rol}</span>`
                : `<span class="badge badge-rol">🎭 ${u.rol}</span>`;
            const ultimoLogin = u.ultimo_login
                ? new Date(u.ultimo_login).toLocaleDateString('es-DO', {day:'2-digit',month:'2-digit',year:'numeric',hour:'2-digit',minute:'2-digit'})
                : '<span style="color:var(--muted);font-size:.74rem">Nunca</span>';
            const centroCel = ES_SUPERADMIN
                ? `<td style="font-size:.78rem;color:var(--muted)">${u.centro_nombre || '— Global —'}</td>`
                : '';

            return `<tr>
                <td><div class="td-user">
                    <div class="td-avatar">${iniciales}</div>
                    <div>
                        <div class="td-name">${u.nombre_completo}</div>
                        <div class="td-username">@${u.username}</div>
                    </div>
                </div></td>
                <td>${rolBadge}</td>
                ${centroCel}
                <td style="font-size:.78rem">${ultimoLogin}</td>
                <td>${estadoBadge}</td>
                <td><div class="td-actions">
                    <button class="btn-icon" title="Editar" onclick='editarUsuario(${JSON.stringify(u)})'>✏️</button>
                    <button class="btn-icon" title="${u.activo ? 'Desactivar' : 'Activar'}"
                            onclick="toggleEstado(${u.id}, ${u.activo}, '${u.nombre_completo}')">
                        ${u.activo ? '⛔' : '✅'}
                    </button>
                    <button class="btn-icon" title="Resetear contraseña"
                            onclick="abrirModalReset(${u.id}, '${u.nombre_completo}')">🔑</button>
                    <button class="btn-icon danger" title="Eliminar"
                            onclick="confirmarEliminar('usuario', ${u.id}, '${u.nombre_completo}')">🗑️</button>
                </div></td>
            </tr>`;
        }).join('');
    }

    // Paginación
    const pag = document.getElementById('paginacion-usuarios');
    if (totalPags <= 1) { pag.innerHTML = ''; return; }

    let btns = '';
    const ini = Math.max(1, paginaActual - 2);
    const fin = Math.min(totalPags, paginaActual + 2);
    if (ini > 1) btns += `<button class="page-btn" onclick="irPagina(1, ${JSON.stringify(lista).length > 0 ? 'lista' : '[]'})">1</button><span class="page-btn disabled">…</span>`;
    for (let i = ini; i <= fin; i++) {
        btns += `<button class="page-btn ${i === paginaActual ? 'active' : ''}" onclick="irPaginaUsuarios(${i})">${i}</button>`;
    }
    if (fin < totalPags) btns += `<span class="page-btn disabled">…</span><button class="page-btn" onclick="irPaginaUsuarios(${totalPags})">${totalPags}</button>`;

    pag.innerHTML = `
        <span class="pagination-info">${total} usuario${total!==1?'s':''} · Página ${paginaActual} de ${totalPags}</span>
        <div class="pagination-btns">
            <button class="page-btn ${paginaActual<=1?'disabled':''}" onclick="irPaginaUsuarios(${paginaActual-1})">‹</button>
            ${btns}
            <button class="page-btn ${paginaActual>=totalPags?'disabled':''}" onclick="irPaginaUsuarios(${paginaActual+1})">›</button>
        </div>`;
}

// guardamos la lista filtrada para paginación
let _listaFiltrada = [];
const _origFiltrar = filtrarUsuarios;
filtrarUsuarios = function() {
    const buscar  = document.getElementById('buscar-usuario').value.toLowerCase();
    const rol     = document.getElementById('filtro-rol').value;
    const estado  = document.getElementById('filtro-estado').value;
    const centro  = ES_SUPERADMIN ? document.getElementById('filtro-centro').value : '';
    _listaFiltrada = usuariosData.filter(u => {
        return (!buscar  || u.nombre_completo.toLowerCase().includes(buscar) || u.username.toLowerCase().includes(buscar))
            && (!rol     || u.rol === rol)
            && (estado === '' || String(u.activo) === estado)
            && (!centro  || String(u.id_empresa) === centro);
    });
    paginaActual = 1;
    renderTablaUsuarios(_listaFiltrada);
};
function irPaginaUsuarios(p) { paginaActual = p; renderTablaUsuarios(_listaFiltrada); }

// ── Buscar persona ─────────────────────────────────────────────────────────────
async function buscarPersona(q) {
    clearTimeout(buscarTimeout);
    const res_div = document.getElementById('persona-resultados');
    if (q.length < 2) { res_div.style.display = 'none'; return; }
    buscarTimeout = setTimeout(async () => {
        try {
            const res  = await fetch('/GestionPrestamo/api/usuarios.php?action=buscar_persona&q=' + encodeURIComponent(q));
            const data = await res.json();
            if (!data.personas || data.personas.length === 0) {
                res_div.innerHTML = '<div class="persona-result" style="color:var(--muted);text-align:center;padding:14px">Sin resultados</div>';
            } else {
                res_div.innerHTML = data.personas.map(p => `
                    <div class="persona-result" onclick='seleccionarPersona(${JSON.stringify(p)})'>
                        <div class="persona-result-name">${p.nombre_completo}</div>
                        <div class="persona-result-info">
                            ${p.tipo_persona}${p.cedula ? ' · <strong>' + p.cedula + '</strong>' : ''}
                            ${p.rol_sugerido_nombre ? ' · Rol: <em>' + p.rol_sugerido_nombre + '</em>' : ''}
                        </div>
                    </div>`).join('');
            }
            res_div.style.display = 'block';
        } catch { res_div.style.display = 'none'; }
    }, 300);
}

function seleccionarPersona(p) {
    personaSelId = p.id;
    document.getElementById('u-persona-id').value = p.id;

    // Info read-only
    document.getElementById('persona-info-nombre').textContent =  p.nombre_completo;
    document.getElementById('persona-info-detalle').textContent =
        p.tipo_persona + (p.cedula ? ' · Cédula: ' + p.cedula : '');
    document.getElementById('persona-info-box').style.display    = 'block';
    document.getElementById('persona-search-wrapper').style.display = 'none';
    document.getElementById('persona-resultados').style.display  = 'none';

    // Campo nombre visible read-only
    document.getElementById('u-nombre-display').value = p.nombre_completo;

    // Auto-asignar y bloquear rol
    const rolSelect = document.getElementById('u-rol');
    if (p.rol_sugerido_id) {
        rolSelect.value    = p.rol_sugerido_id;
        rolSelect.disabled = true;
        document.getElementById('rol-auto-badge').style.display = 'inline';
    }

    // Auto-sugerir username solo al crear
    if (!document.getElementById('u-id').value) {
        const partes = p.nombre_completo.toLowerCase().split(' ');
        document.getElementById('u-username').value =
            (partes[0] + (partes[1] ? partes[1][0] : '')).replace(/[^a-z0-9.]/g, '');
    }
    document.getElementById('u-username').focus();
}

function deseleccionarPersona() {
    personaSelId = null;
    document.getElementById('u-persona-id').value    = '';
    document.getElementById('u-nombre-display').value = '';
    document.getElementById('persona-info-box').style.display      = 'none';
    document.getElementById('persona-search-wrapper').style.display = 'block';
    document.getElementById('persona-buscar').value  = '';
    document.getElementById('persona-resultados').style.display    = 'none';
    // Desbloquear rol
    document.getElementById('u-rol').disabled = false;
    document.getElementById('u-rol').value    = '';
    document.getElementById('rol-auto-badge').style.display = 'none';
    setTimeout(() => document.getElementById('persona-buscar').focus(), 50);
}

// ── Modal Crear Usuario ────────────────────────────────────────────────────────
function abrirModalCrearUsuario() {
    document.getElementById('modal-usuario-title').textContent = '➕ Nuevo Usuario';
    document.getElementById('u-id').value             = '';
    document.getElementById('u-username').value       = '';
    document.getElementById('u-password').value       = '';
    document.getElementById('u-rol').value            = '';
    document.getElementById('u-rol').disabled         = false;
    document.getElementById('u-cambiar-pass').checked  = true;
    document.getElementById('pass-required').style.display = '';
    document.getElementById('u-password').required    = true;
    document.getElementById('pass-hint').textContent  = 'Se enviará al usuario si el SMTP está configurado';
    document.getElementById('rol-auto-badge').style.display = 'none';
    if (ES_SUPERADMIN) document.getElementById('u-centro').value = '';
    deseleccionarPersona();
    abrirModal('modal-usuario');
}

function editarUsuario(u) {
    document.getElementById('modal-usuario-title').textContent = '✏️ Editar Usuario';
    document.getElementById('u-id').value             = u.id;
    document.getElementById('u-username').value       = u.username;
    document.getElementById('u-password').value       = '';
    document.getElementById('u-rol').value            = u.id_rol;
    document.getElementById('u-rol').disabled         = false;
    document.getElementById('u-cambiar-pass').checked  = u.cambiar_password == 1;
    document.getElementById('pass-required').style.display = 'none';
    document.getElementById('u-password').required    = false;
    document.getElementById('pass-hint').textContent  = 'Déjalo vacío para no cambiar la contraseña';
    document.getElementById('rol-auto-badge').style.display = 'none';
    if (ES_SUPERADMIN) document.getElementById('u-centro').value = u.id_empresa || '';
    if (window.ssRefreshAll) ssRefreshAll(document.getElementById('modal-usuario'));
    // Mostrar persona seleccionada
    personaSelId = u.id_persona;
    document.getElementById('u-persona-id').value      = u.id_persona;
    document.getElementById('u-nombre-display').value  = u.nombre_completo;
    document.getElementById('persona-info-nombre').textContent  = u.nombre_completo;
    document.getElementById('persona-info-detalle').textContent = u.tipo_persona || '';
    document.getElementById('persona-info-box').style.display      = 'block';
    document.getElementById('persona-search-wrapper').style.display = 'none';
    abrirModal('modal-usuario');
}

async function guardarUsuario() {
    const id        = document.getElementById('u-id').value;
    const personaId = document.getElementById('u-persona-id').value;
    const username  = document.getElementById('u-username').value.trim();
    const password  = document.getElementById('u-password').value;
    const rolSelect = document.getElementById('u-rol');
    const rolId     = rolSelect.value; // funciona aunque esté disabled en JS
    const cambiarPass = document.getElementById('u-cambiar-pass').checked ? 1 : 0;
    const centroId  = ES_SUPERADMIN ? (document.getElementById('u-centro').value || null) : ID_CENTRO_SESION;

    if (!personaId) { showToast('❌ Selecciona una persona', 'error'); return; }
    if (!username)  { showToast('❌ El nombre de usuario es obligatorio', 'error'); return; }
    if (!rolId)     { showToast('❌ Selecciona un rol', 'error'); return; }
    if (!id && !password) { showToast('❌ La contraseña es obligatoria para nuevos usuarios', 'error'); return; }
    if (password && password.length < 8) { showToast('❌ La contraseña debe tener al menos 8 caracteres', 'error'); return; }

    try {
        const res  = await fetch('/GestionPrestamo/api/usuarios.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({ action: id ? 'editar' : 'crear', id, id_persona: personaId,
                                   username, password, id_rol: rolId, cambiar_password: cambiarPass,
                                   id_empresa: centroId })
        });
        const data = await res.json();
        if (data.success) {
            showToast('✅ ' + data.mensaje, 'success');
            cerrarModal('modal-usuario');
            cargarUsuarios();
        } else {
            showToast('❌ ' + data.error, 'error');
        }
    } catch { showToast('❌ Error de conexión', 'error'); }
}

// ── Activar / Desactivar ───────────────────────────────────────────────────────
function toggleEstado(id, estadoActual, nombre) {
    const nuevo  = estadoActual == 1 ? 0 : 1;
    const accion = nuevo ? 'activar' : 'desactivar';
    confirmarAccion(
        `${nuevo ? '✅' : '⛔'} ${nuevo ? 'Activar' : 'Desactivar'} usuario`,
        `¿Seguro que deseas ${accion} a <strong>${nombre}</strong>?`,
        async () => {
            const res  = await fetch('/GestionPrestamo/api/usuarios.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({ action: 'toggle_estado', id, activo: nuevo })
            });
            const data = await res.json();
            if (data.success) { showToast('✅ ' + data.mensaje, 'success'); cargarUsuarios(); }
            else showToast('❌ ' + data.error, 'error');
        }
    );
}

// ── Reset contraseña ───────────────────────────────────────────────────────────
function abrirModalReset(id, nombre) {
    document.getElementById('reset-id').value           = id;
    document.getElementById('reset-nombre-usuario').textContent = nombre;
    document.getElementById('reset-password').value     = '';
    document.getElementById('reset-cambiar').checked    = true;
    abrirModal('modal-reset');
}

async function confirmarReset() {
    const id       = document.getElementById('reset-id').value;
    const password = document.getElementById('reset-password').value;
    const cambiar  = document.getElementById('reset-cambiar').checked ? 1 : 0;
    if (!password || password.length < 8) { showToast('❌ Mínimo 8 caracteres', 'error'); return; }
    try {
        const res  = await fetch('/GestionPrestamo/api/usuarios.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({ action: 'reset_password', id, password, cambiar_password: cambiar })
        });
        const data = await res.json();
        if (data.success) { showToast('✅ Contraseña actualizada', 'success'); cerrarModal('modal-reset'); }
        else showToast('❌ ' + data.error, 'error');
    } catch { showToast('❌ Error de conexión', 'error'); }
}

// ── Eliminar usuario ───────────────────────────────────────────────────────────
function confirmarEliminar(tipo, id, nombre) {
    confirmarAccion(
        '🗑️ Eliminar',
        `¿Seguro que deseas eliminar a <strong>${nombre}</strong>? Esta acción no se puede deshacer.`,
        async () => {
            const res  = await fetch('/GestionPrestamo/api/usuarios.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({ action: tipo === 'usuario' ? 'eliminar' : 'eliminar_rol', id })
            });
            const data = await res.json();
            if (data.success) {
                showToast('✅ Eliminado correctamente', 'success');
                tipo === 'usuario' ? cargarUsuarios() : cargarRoles();
            } else showToast('❌ ' + data.error, 'error');
        }
    );
}

// ── Roles ──────────────────────────────────────────────────────────────────────
async function cargarRoles() {
    try {
        const res  = await fetch('/GestionPrestamo/api/usuarios.php?action=listar_roles');
        const data = await res.json();
        if (data.error) { showToast('❌ ' + data.error, 'error'); return; }
        renderRoles(data.roles);
    } catch { showToast('❌ Error al cargar roles', 'error'); }
}

const ROLES_SISTEMA = ['superadmin','admin','gerente','supervisor','cajero','asesor','auditor','cliente','consultor'];

const ROLES_META = {
    'superadmin':   { ico:'🛡️', color:'#7c3aed', bg:'#ede9fe' },
    'admin':        { ico:'⚙️', color:'#1d4ed8', bg:'#dbeafe' },
    'gerente':     { ico:'🏫', color:'#0f766e', bg:'#ccfbf1' },
    'supervisor':  { ico:'📋', color:'#b45309', bg:'#fef3c7' },
    'cajero':   { ico:'📝', color:'#be185d', bg:'#fce7f3' },
    'asesor':      { ico:'👨‍🏫', color:'#15803d', bg:'#dcfce7' },
    'auditor':   { ico:'🧠', color:'#0369a1', bg:'#e0f2fe' },
    'cliente':        { ico:'👨‍👧', color:'#7c3aed', bg:'#ede9fe' },
    'consultor':   { ico:'🎒', color:'#1d4ed8', bg:'#dbeafe' },
};

function renderRoles(roles) {
    const grid = document.getElementById('roles-grid');
    if (!roles.length) { grid.innerHTML = '<div class="empty-state"><div class="empty-icon">🎭</div><div>No hay roles</div></div>'; return; }

    grid.innerHTML = roles.map(r => {
        const esSistema = ROLES_SISTEMA.includes(r.nombre);
        const meta = ROLES_META[r.nombre] || { ico:'🎭', color:'#64748b', bg:'#f1f5f9' };
        const allPerms = (r.permisos || '').split(',').filter(Boolean);
        const showPerms = allPerms.slice(0,5);
        const extra = allPerms.length - 5;
        const permTags = showPerms.map(p =>
            `<span class="perm-tag">${p.replace(/_/g,' ')}</span>`).join('');
        const masPerms = extra > 0
            ? `<span class="perm-tag-more">+${extra} más</span>` : '';
        const acciones = esSistema
            ? `<button class="btn-icon" title="Ver permisos" onclick='verPermisos(${JSON.stringify(r)})'>👁️</button>`
            : `<button class="btn-icon" title="Editar" onclick='editarRol(${JSON.stringify(r)})'>✏️</button>
               <button class="btn-icon danger" title="Eliminar" onclick="confirmarEliminar('rol', ${r.id}, '${r.nombre}')">🗑️</button>`;

        return `<div class="role-card">
            <div class="role-card-header">
                <span class="role-name">
                    <span class="role-ico" style="background:${meta.bg}">${meta.ico}</span>
                    ${r.nombre}
                </span>
                <div style="display:flex;align-items:center;gap:6px">
                    ${esSistema ? '<span class="role-badge-sys">Rol del sistema</span>' : ''}
                    <div class="role-actions">${acciones}</div>
                </div>
            </div>
            <p class="role-desc">${r.descripcion || 'Sin descripción'}</p>
            <div class="role-perms">${permTags}${masPerms}
                ${!allPerms.length ? '<span style="font-size:.74rem;color:var(--muted)">Sin permisos asignados</span>' : ''}
            </div>
        </div>`;
    }).join('');
}

function abrirModalCrearRol() {
    document.getElementById('modal-rol-title').textContent = '➕ Nuevo Rol';
    document.getElementById('r-id').value          = '';
    document.getElementById('r-nombre').value      = '';
    document.getElementById('r-descripcion').value = '';
    document.querySelectorAll('#permisos-container input[type=checkbox]').forEach(c => c.checked = false);
    abrirModal('modal-rol');
}

function editarRol(r) {
    document.getElementById('modal-rol-title').textContent = '✏️ Editar Rol: ' + r.nombre;
    document.getElementById('r-id').value          = r.id;
    document.getElementById('r-nombre').value      = r.nombre;
    document.getElementById('r-descripcion').value = r.descripcion;
    const permsActivos = (r.permisos_ids || '').split(',').filter(Boolean);
    document.querySelectorAll('#permisos-container input[type=checkbox]').forEach(c => {
        c.checked = permsActivos.includes(String(c.value));
    });
    abrirModal('modal-rol');
}

function verPermisos(r) {
    editarRol(r);
    // Deshabilitar edición para roles de sistema
    document.getElementById('r-nombre').disabled      = true;
    document.getElementById('r-descripcion').disabled = true;
    document.querySelectorAll('#permisos-container input').forEach(c => c.disabled = true);
    document.querySelector('#modal-rol .modal-footer .btn-primary').style.display = 'none';
}

function toggleTodosPermisos() {
    const checks  = document.querySelectorAll('#permisos-container input[type=checkbox]:not(:disabled)');
    const todosOn = [...checks].every(c => c.checked);
    checks.forEach(c => c.checked = !todosOn);
}

async function guardarRol() {
    const id          = document.getElementById('r-id').value;
    const nombre      = document.getElementById('r-nombre').value.trim().toLowerCase().replace(/\s+/g,'_');
    const descripcion = document.getElementById('r-descripcion').value.trim();
    const permisos    = [...document.querySelectorAll('#permisos-container input[type=checkbox]:checked')].map(c => c.value);

    if (!nombre)      { showToast('❌ El nombre del rol es obligatorio', 'error'); return; }
    if (!descripcion) { showToast('❌ La descripción es obligatoria', 'error'); return; }

    try {
        const res  = await fetch('/GestionPrestamo/api/usuarios.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({ action: id ? 'editar_rol' : 'crear_rol', id, nombre, descripcion, permisos })
        });
        const data = await res.json();
        if (data.success) {
            showToast('✅ ' + data.mensaje, 'success');
            cerrarModal('modal-rol');
            cargarRoles();
        } else showToast('❌ ' + data.error, 'error');
    } catch { showToast('❌ Error de conexión', 'error'); }
}

// ── Helpers modales ────────────────────────────────────────────────────────────
function abrirModal(id) {
    document.getElementById(id).classList.add('open');
    // Re-habilitar campos si venían deshabilitados
    document.querySelectorAll('#' + id + ' input, #' + id + ' select').forEach(el => el.disabled = false);
    document.querySelectorAll('#' + id + ' .btn-primary').forEach(el => el.style.display = '');
}
function cerrarModal(id) { document.getElementById(id).classList.remove('open'); }

// Cerrar modal al hacer click fuera
document.querySelectorAll('.modal-backdrop').forEach(backdrop => {
    backdrop.addEventListener('click', e => { if (e.target === backdrop) backdrop.classList.remove('open'); });
});

function confirmarAccion(titulo, mensaje, callback) {
    document.getElementById('confirm-title').textContent = titulo;
    document.getElementById('confirm-msg').innerHTML     = mensaje;
    const btn = document.getElementById('confirm-btn');
    btn.onclick = async () => { cerrarModal('modal-confirm'); await callback(); };
    abrirModal('modal-confirm');
}

// ── Init ───────────────────────────────────────────────────────────────────────
cargarUsuarios();
</script>
</body>
</html>