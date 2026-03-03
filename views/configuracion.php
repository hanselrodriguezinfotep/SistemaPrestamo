<?php
// configuracion.php — GestionPrestamo | Configuración de Empresa
require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/../config/db.php';

$sesion = verificarSesion();

// Solo superadmin y admin acceden
if (!in_array($sesion['rol'], ['superadmin', 'admin'], true)) {
    header('Location: /GestionPrestamo/index.php?error=noaccess');
    exit;
}

$db           = getDB();
$esSuperadmin = $sesion['rol'] === 'superadmin';
// Superadmin siempre opera sobre el centro 1
$id_empresa = (int)($sesion['id_empresa'] ?? 0);

// Cargar configuración existente
$stmt = $db->prepare('SELECT * FROM configuracion_empresa WHERE id_empresa <=> ? LIMIT 1');
$stmt->execute([$id_empresa]);
$cfg = $stmt->fetch() ?: [];

// Cargar provincias
$provincias = $db->query("SELECT id, nombre FROM provincias ORDER BY nombre")->fetchAll() ?: [];

// Cargar municipios de la provincia seleccionada (si hay)
$municipios = [];
if (!empty($cfg["id_provincia"])) {
    $stmtM = $db->prepare("SELECT id, nombre FROM municipios WHERE id_provincia = ? ORDER BY nombre");
    $stmtM->execute([$cfg["id_provincia"]]);
    $municipios = $stmtM->fetchAll();
}

$nombreRol = $sesion['rol'] === 'superadmin' ? 'Superadministrador' : 'Administrador';

// Cargar centros destino para envío (solo superadmin)
$empresasDestino = [];
if ($esSuperadmin) {
    $empresasDestino = $db->query("SELECT id, nombre, modalidad FROM empresas WHERE id != 1 ORDER BY nombre")->fetchAll();
}

$iniciales = implode('', array_map(
    fn($w) => strtoupper($w[0]),
    array_slice(explode(' ', trim($sesion['nombre'])), 0, 2)
));

$monedas = [
    'DOP' => '🇩🇴 Peso Dominicano (DOP)',
    'USD' => '🇺🇸 Dólar Americano (USD)',
    'EUR' => '🇪🇺 Euro (EUR)',
    'GTQ' => '🇬🇹 Quetzal (GTQ)',
    'HNL' => '🇭🇳 Lempira (HNL)',
    'CRC' => '🇨🇷 Colón CR (CRC)',
    'MXN' => '🇲🇽 Peso Mexicano (MXN)',
    'COP' => '🇨🇴 Peso Colombiano (COP)',
];

$timeouts = [
    0   => '∞ Sin límite',
    15  => '15 minutos',
    30  => '30 minutos',
    60  => '1 hora',
    120 => '2 horas',
    480 => '8 horas',
];

function v(array $cfg, string $key, mixed $default = ''): mixed {
    return $cfg[$key] ?? $default;
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <?php $pageTitle = 'Configuración'; require_once __DIR__ . '/../php/partials/head.php'; ?>
    <style>
        /* Estilos exclusivos de Configuración */
        /* ── Layout unificado — todas las secciones mismo ancho ── */
        .page { width:100%; padding: 8px 28px 0 28px; box-sizing:border-box; }
        .section { display:none; }
        .section.active { display:block; animation:fadeIn .18s ease; }
        /* card raíz de cada sección: ocupa todo el ancho disponible */
        .section > .card,
        .section > form > .card,
        .section > form { width:100%; box-sizing:border-box; }
        /* Enviar Datos — igual que las otras secciones, usa .card normal */
        .envio-grid-gen  { display:grid; grid-template-columns:repeat(auto-fill,minmax(180px,1fr)); gap:8px; }
        .envio-footer    { display:flex; align-items:center; justify-content:space-between; padding-top:18px; margin-top:6px; border-top:1px solid var(--border); }
        .envio-historial { font-size:.74rem; color:var(--muted); }
        #sec-avanzado { width:100%; }
        /* Card politécnico compacta horizontal */
        .poli-card { background:var(--surface);border:1.5px solid var(--border);border-radius:10px;padding:10px 12px;box-shadow:var(--shadow);display:flex;flex-direction:row;align-items:center;gap:10px;transition:all .15s;cursor:pointer;user-select:none; }
        .poli-card:hover { border-color:#c7d7fe;box-shadow:0 3px 10px rgba(29,78,216,.1); }
        .poli-card.selected { border-color:var(--primary)!important;background:#eff6ff; }
        .poli-card input[type=checkbox] { width:15px;height:15px;accent-color:var(--primary);cursor:pointer;flex-shrink:0; }
        .poli-card.locked { opacity:.7;cursor:not-allowed!important; }
        .poli-card.locked input[type=checkbox] { cursor:not-allowed;pointer-events:none; }
        .poli-card.locked * { pointer-events:none; }
        .poli-card.locked .dep-badge { display:inline-flex!important; }
        .dep-badge { display:none;font-size:.6rem;font-weight:700;color:#1d4ed8;background:#dbeafe;border-radius:20px;padding:1px 7px;margin-left:4px;white-space:nowrap;vertical-align:middle; }
        .poli-card-ico { font-size:1.3rem;flex-shrink:0; }
        .poli-card-info { display:flex;flex-direction:column;gap:2px;min-width:0; }
        .poli-card-title { font-weight:700;font-size:.78rem;line-height:1.3; }
        .poli-card-desc { font-size:.68rem;color:var(--muted);line-height:1.3;white-space:nowrap;overflow:hidden;text-overflow:ellipsis; }
        .poli-card-count { font-size:.67rem;color:var(--muted); }
        #grid-generales, #grid-avanzado { display:grid; grid-template-columns:repeat(auto-fill,minmax(220px,1fr)); gap:8px; }
        @media(max-width:900px) {
            .empresa-contacto-grid { grid-template-columns:1fr 1fr !important; }
        }
        @media(max-width:640px) {
            .empresa-top-grid { grid-template-columns:1fr !important; }
            .empresa-contacto-grid { grid-template-columns:1fr !important; }
            .empresa-nombre-grid { grid-template-columns:1fr !important; }
        }
        .tabs { display:flex; gap:4px; flex-wrap:wrap; background:var(--surface); border:1px solid var(--border); border-radius:12px; padding:6px; margin-bottom:10px; box-shadow:var(--shadow); }
        .tab  { flex:1; min-width:100px; padding:9px 12px; border-radius:8px; border:none; background:none; cursor:pointer; font-family:inherit; font-size:.78rem; font-weight:600; color:var(--muted); transition:all .12s; display:flex; align-items:center; gap:5px; justify-content:center; white-space:nowrap; }
        .tab:hover  { background:var(--bg); color:var(--text); }
        .tab.active { background:var(--primary); color:#fff; box-shadow:0 2px 8px rgba(29,78,216,.3); }
        /* .section rules moved to layout block above */
        @keyframes fadeIn { from{opacity:0;transform:translateY(5px)} to{opacity:1;transform:none} }
        @keyframes spin { to { transform: rotate(360deg); } }
        .card-header { display:flex; justify-content:space-between; align-items:flex-start; margin-bottom:22px; padding-bottom:14px; border-bottom:1px solid var(--border); }
        .card-header-left { display:flex; align-items:center; gap:10px; }
        .card-subtitle { font-size:.76rem; color:var(--muted); margin-top:2px; }
        .form-grid { display:grid; grid-template-columns:1fr 1fr; gap:16px; }
        .form-full { grid-column:1/-1; }
        .field { display:flex; flex-direction:column; gap:5px; }
        .field label { font-size:.78rem; font-weight:700; }
        .field input, .field select, .field textarea { padding:9px 13px; border:1.5px solid var(--border); border-radius:9px; font-family:inherit; font-size:.86rem; color:var(--text); background:#fff; transition:border-color .12s,box-shadow .12s; outline:none; width:100%; }
        .field input:focus, .field select:focus, .field textarea:focus { border-color:var(--primary); box-shadow:0 0 0 3px rgba(29,78,216,.1); }
        .field textarea { resize:vertical; min-height:76px; }
        .field-hint { font-size:.7rem; color:var(--muted); }
        .logo-upload-row { display:flex; align-items:center; gap:20px; margin-bottom:22px; }
        .logo-preview { width:76px; height:76px; border-radius:12px; border:2px dashed var(--border); background:var(--bg); display:flex; align-items:center; justify-content:center; font-size:2rem; flex-shrink:0; overflow:hidden; cursor:pointer; transition:border-color .12s; }
        .logo-preview:hover { border-color:var(--primary); }
        .logo-preview img { width:100%; height:100%; object-fit:contain; }
        .btn-upload { display:inline-flex; align-items:center; gap:7px; padding:8px 16px; border-radius:9px; border:none; cursor:pointer; font-family:inherit; font-size:.8rem; font-weight:700; background:linear-gradient(135deg,var(--primary),#2563eb); color:#fff; box-shadow:0 2px 8px rgba(29,78,216,.3); transition:opacity .12s; }
        .btn-upload:hover { opacity:.9; }
        .logo-hint { font-size:.71rem; color:var(--muted); margin-top:5px; }
        .security-box { background:#fffbeb; border:1.5px solid #fde68a; border-radius:10px; padding:16px; margin-top:18px; }
        .security-box-title { font-size:.8rem; font-weight:800; color:#92400e; display:flex; align-items:center; gap:6px; margin-bottom:12px; }
        .security-grid { display:grid; grid-template-columns:1fr 1fr; gap:14px; }
        .security-hint { font-size:.7rem; color:#b45309; margin-top:6px; grid-column:1/-1; }
        .card-footer { display:flex; justify-content:flex-end; gap:10px; padding-top:18px; margin-top:18px; border-top:1px solid var(--border); }
        .info-box { background:#eff6ff; border:1.5px solid #bfdbfe; border-radius:10px; padding:13px 15px; margin-top:14px; font-size:.76rem; color:#1e40af; line-height:1.6; }
        .radio-group { display:flex; flex-direction:column; gap:9px; margin-bottom:18px; }
        .radio-card { display:flex; align-items:center; gap:13px; padding:13px 16px; border:1.5px solid var(--border); border-radius:10px; cursor:pointer; transition:all .12s; }
        .radio-card:has(input:checked) { border-color:var(--primary); background:#eff6ff; }
        .radio-card input[type="radio"] { width:17px; height:17px; accent-color:var(--primary); }
        .radio-card-label { font-size:.86rem; font-weight:700; }
        .radio-card-desc  { font-size:.73rem; color:var(--muted); margin-top:2px; }
        .check-list { display:flex; flex-direction:column; gap:9px; }
        .check-card { display:flex; align-items:flex-start; gap:13px; padding:13px 16px; border:1.5px solid var(--border); border-radius:10px; cursor:pointer; transition:all .12s; }
        .check-card:has(input:checked) { border-color:var(--primary); background:#eff6ff; }
        .check-card input[type="checkbox"] { width:17px; height:17px; accent-color:var(--primary); flex-shrink:0; margin-top:2px; }
        .check-card-label { font-size:.86rem; font-weight:700; }
        .check-card-desc  { font-size:.73rem; color:var(--muted); margin-top:2px; }
        .printer-list { display:flex; flex-direction:column; gap:12px; }
        .printer-row { display:flex; align-items:center; gap:14px; padding:13px 16px; border:1.5px solid var(--border); border-radius:10px; background:var(--bg); }
        .printer-row-info { flex:1; min-width:0; }
        .printer-row-label { font-size:.86rem; font-weight:700; }
        .printer-row-desc  { font-size:.71rem; color:var(--muted); margin-top:2px; }
        .printer-options { display:flex; gap:5px; flex-shrink:0; }
        .printer-btn { padding:6px 11px; border-radius:7px; border:1.5px solid var(--border); background:var(--surface); cursor:pointer; font-family:inherit; font-size:.73rem; font-weight:600; color:var(--muted); transition:all .12s; white-space:nowrap; }
        .printer-btn:hover  { border-color:var(--primary); color:var(--primary); }
        .printer-btn.active { background:var(--primary); color:#fff; border-color:var(--primary); box-shadow:0 2px 8px rgba(29,78,216,.25); }
        /* RESPONSIVE */
        @media(max-width:1024px) {
            .tabs { gap:2px; }
            .tab  { min-width:80px; font-size:.74rem; padding:8px 10px; }
            .form-grid { grid-template-columns:1fr 1fr; }
        }
        @media(max-width:768px) {
            .form-grid,.security-grid { grid-template-columns:1fr; }
            .printer-row { flex-direction:column; align-items:flex-start; }
            .tabs { gap:2px; }
            .tab  { font-size:.72rem; padding:7px 8px; min-width:70px; }
            .card-footer { flex-direction:column; gap:8px; }
            .logo-upload-row { flex-direction:column; align-items:flex-start; gap:12px; }
            .envio-grid-gen { grid-template-columns:1fr 1fr !important; }
        }
        @media(max-width:480px) {
            .tabs { flex-direction:column; gap:2px; }
            .tab  { justify-content:flex-start; min-width:unset; width:100%; }
            .envio-grid-gen { grid-template-columns:1fr !important; }
            .btn  { min-height:44px; }
            .field input, .field select, .field textarea { width:100%; box-sizing:border-box; }
        }
    
        /* ── Toggle switch Soporte ── */
        .toggle-wrap { display:inline-flex; align-items:center; cursor:pointer; }
        .toggle-track {
          width:46px; height:24px; border-radius:12px;
          background:#cbd5e1; transition:background .2s;
          position:relative; box-shadow:inset 0 1px 3px rgba(0,0,0,.15);
        }
        .toggle-track.on { background:#2563eb; }
        .toggle-thumb {
          position:absolute; top:3px; left:3px;
          width:18px; height:18px; border-radius:50%;
          background:#fff; box-shadow:0 1px 4px rgba(0,0,0,.25);
          transition:transform .2s;
        }
        .toggle-track.on .toggle-thumb { transform:translateX(22px); }
</style>
</head>
<body>
<div class="app">
<?php $activePage = 'configuracion'; require_once __DIR__ . '/../php/partials/sidebar.php'; ?>
    <main class="main">
        <header class="header">
            <button class="hamburger" onclick="toggleSidebar()">☰</button>
            <div class="header-title">
                <h1>⚙️ Configuración</h1>
                <p>Personaliza tu empresa, comunicaciones y sistema</p>
            </div>
            <div class="header-actions">
                <a href="index.php" style="text-decoration:none">
                    <button class="btn btn-ghost" style="padding:8px 14px;font-size:.8rem">← Dashboard</button>
                </a>
                <span class="badge-role"><?= htmlspecialchars($nombreRol) ?></span>
            </div>
        </header>

        <!-- Tabs — fuera del page para que no hereden max-width -->
        <div style="padding: 8px 28px 0 28px; box-sizing:border-box; width:100%">
            <div class="tabs">
                <button class="tab active" onclick="switchTab('empresa', this)">🏢 Empresa</button>
                <button class="tab" onclick="switchTab('smtp', this)">📧 Correo SMTP</button>
                <button class="tab" onclick="switchTab('whatsapp', this)">📱 WhatsApp</button>
                <button class="tab" onclick="switchTab('impresoras', this)">🖨️ Impresoras</button>
                <?php if ($esSuperadmin): ?>
                <button class="tab" onclick="switchTab('enviar-datos', this)">📤 Enviar Datos</button>
                <button class="tab" onclick="switchTab('moneda', this)">💱 Monedas</button>
                <button class="tab" onclick="switchTab('agradecimiento', this)">🙏 Mensaje Final</button>
                <button class="tab" onclick="switchTab('soporte', this)">🛠️ Soporte</button>
                <?php endif; ?>
                <?php if (!$esSuperadmin): ?>
                <button class="tab" onclick="switchTab('materias', this)">📚 Materias Académicas</button>
                <button class="tab" onclick="switchTab('avanzado', this)">🔧 Avanzado</button>
                <button class="tab" onclick="switchTab('moneda', this)">💱 Monedas</button>
                <button class="tab" onclick="switchTab('agradecimiento', this)">🙏 Mensaje Final</button>
                <?php endif; ?>
            </div>
        </div><!-- /tabs wrapper -->

        <div class="page">

            <!-- ═══════════════════════════════════════════════ -->
            <!-- SECCIÓN 1: IDENTIDAD DE LA EMPRESA             -->
            <!-- ═══════════════════════════════════════════════ -->
            <div class="section active" id="sec-empresa">
                <form id="form-empresa" onsubmit="guardar(event, 'empresa')">

                    <!-- Fila superior: Logo + Nombre/Slogan -->
                    <div style="display:grid;grid-template-columns:auto 1fr;gap:20px;align-items:start;background:var(--surface);border:1.5px solid var(--border);border-radius:14px;padding:20px 24px;margin-bottom:16px;box-shadow:var(--shadow)">

                        <!-- Logo -->
                        <div style="display:flex;flex-direction:column;align-items:center;gap:10px">
                            <div class="logo-preview" id="logo-preview" onclick="document.getElementById('logo-file').click()" title="Cambiar logo" style="width:88px;height:88px;border-radius:14px;cursor:pointer">
                                <?php if (!empty($cfg['logo_path'])): ?>
                                    <img src="<?= htmlspecialchars($cfg['logo_path']) ?>" alt="Logo" id="logo-img">
                                <?php else: ?>
                                    <span id="logo-emoji" style="font-size:2rem">🏫</span>
                                <?php endif; ?>
                            </div>
                            <button type="button" class="btn-upload" onclick="document.getElementById('logo-file').click()" style="font-size:.72rem;padding:5px 12px;white-space:nowrap">
                                🖼️ Subir Logo
                            </button>
                            <span style="font-size:.67rem;color:var(--muted);text-align:center;line-height:1.4">PNG, JPG o SVG<br>Max 500KB · 200×200px</span>
                            <input type="file" id="logo-file" name="logo" accept="image/png,image/jpeg,image/svg+xml" style="display:none" onchange="previewLogo(this)">
                        </div>

                        <!-- Nombre + Slogan + RNC/Moneda -->
                        <div style="display:flex;flex-direction:column;gap:12px">
                            <div style="display:grid;grid-template-columns:1fr auto;gap:12px;align-items:end">
                                <div class="field">
                                    <label>Nombre de la Empresa *</label>
                                    <input type="text" name="nombre_empresa" placeholder="Ej: Empresa San Marcos"
                                        value="<?= htmlspecialchars(v($cfg,'nombre_empresa')) ?>" required>
                                </div>
                                <div class="field" style="min-width:180px">
                                    <label>Moneda</label>
                                    <select name="moneda">
                                        <?php foreach ($monedas as $code => $label): ?>
                                        <option value="<?= $code ?>" <?= v($cfg,'moneda','DOP') === $code ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($label) ?>
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                            <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
                                <div class="field">
                                    <label>Slogan o Descripción</label>
                                    <input type="text" name="slogan" placeholder="Tu empresa de confianza"
                                        value="<?= htmlspecialchars(v($cfg,'slogan')) ?>">
                                </div>
                                <div class="field">
                                    <label>RNC / Registro</label>
                                    <input type="text" name="rnc" placeholder="000-00000-0"
                                        value="<?= htmlspecialchars(v($cfg,'rnc')) ?>">
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Fila contacto + ubicación -->
                    <div style="background:var(--surface);border:1.5px solid var(--border);border-radius:14px;padding:18px 24px;margin-bottom:16px;box-shadow:var(--shadow)">
                        <div style="font-size:.72rem;font-weight:800;color:var(--muted);text-transform:uppercase;letter-spacing:.06em;margin-bottom:12px">📞 Contacto y Ubicación</div>
                        <div style="display:grid;grid-template-columns:1fr 1fr 1fr 1fr;gap:12px">
                            <div class="field">
                                <label>Teléfono</label>
                                <input type="text" name="telefono" placeholder="809-000-0000"
                                    value="<?= htmlspecialchars(v($cfg,'telefono')) ?>">
                            </div>
                            <div class="field">
                                <label>Email</label>
                                <input type="email" name="email" placeholder="info@centro.edu"
                                    value="<?= htmlspecialchars(v($cfg,'email')) ?>">
                            </div>
                            <div class="field">
                                <label>Provincia</label>
                                <select name="id_provincia" id="sel-provincia" onchange="cargarMunicipios(this.value)">
                                    <option value="">— Provincia —</option>
                                    <?php foreach ($provincias as $prov): ?>
                                    <option value="<?= $prov['id'] ?>" <?= (int)v($cfg,'id_provincia',0) === (int)$prov['id'] ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($prov['nombre']) ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="field">
                                <label>Municipio</label>
                                <select name="id_municipio" id="sel-municipio" data-no-search>
                                    <option value="">— Municipio —</option>
                                    <?php foreach ($municipios as $mun): ?>
                                    <option value="<?= $mun['id'] ?>" <?= (int)v($cfg,'id_municipio',0) === (int)$mun['id'] ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($mun['nombre']) ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-top:12px">
                            <div class="field">
                                <label>Dirección</label>
                                <input type="text" name="direccion" placeholder="Calle, sector, referencia"
                                    value="<?= htmlspecialchars(v($cfg,'direccion')) ?>">
                            </div>
                            <div class="field">
                                <label>Pie de Recibo</label>
                                <input type="text" name="pie_recibo" placeholder="Gracias por su preferencia..."
                                    value="<?= htmlspecialchars(v($cfg,'pie_recibo')) ?>">
                            </div>
                        </div>
                    </div>

                    <!-- Fila seguridad + acciones -->
                    <div style="display:grid;grid-template-columns:1fr auto;gap:16px;align-items:center;background:#fffbeb;border:1.5px solid #fde68a;border-radius:14px;padding:16px 24px;box-shadow:var(--shadow)">
                        <div style="display:flex;align-items:center;gap:20px;flex-wrap:wrap">
                            <div style="font-size:.8rem;font-weight:800;color:#92400e;display:flex;align-items:center;gap:6px;white-space:nowrap">🔒 Seguridad</div>
                            <div class="field" style="min-width:200px;margin:0">
                                <label style="color:#92400e">Tiempo de inactividad</label>
                                <select name="session_timeout" data-no-search>
                                    <?php foreach ($timeouts as $mins => $label): ?>
                                    <option value="<?= $mins ?>" <?= (int)v($cfg,'session_timeout',0) === $mins ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($label) ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="field" style="min-width:140px;margin:0">
                                <label style="color:#92400e">Prefijo WhatsApp</label>
                                <input type="text" name="whatsapp_prefix" placeholder="+1"
                                    value="<?= htmlspecialchars(v($cfg,'whatsapp_prefix','+1')) ?>">
                            </div>
                        </div>
                        <div style="display:flex;gap:10px;flex-shrink:0">
                            <button type="button" class="btn btn-ghost" onclick="previewRecibo()">👁️ Vista Previa</button>
                            <button type="submit" class="btn btn-primary">💾 Guardar</button>
                        </div>
                    </div>

                </form>
            </div>

            <!-- ═══════════════════════════════════════════════ -->
            <!-- SECCIÓN 2: SMTP                                -->
            <!-- ═══════════════════════════════════════════════ -->
            <div class="section" id="sec-smtp">
                <div class="card">
                    <div class="card-header">
                        <div class="card-header-left">
                            <span style="font-size:1.4rem">📧</span>
                            <div>
                                <div class="card-title">Correo Electrónico (SMTP)</div>
                                <div class="card-subtitle">Envío automático de credenciales y notificaciones</div>
                            </div>
                        </div>
                    </div>

                    <form id="form-smtp" onsubmit="guardar(event, 'smtp')">
                        <div class="form-grid">
                            <div class="field">
                                <label>Servidor SMTP</label>
                                <input type="text" name="smtp_host" placeholder="mail.tudominio.com"
                                    value="<?= htmlspecialchars(v($cfg,'smtp_host')) ?>">
                                <span class="field-hint">Gmail: smtp.gmail.com · Hostinger: smtp.hostinger.com · cPanel: mail.tudominio.com</span>
                            </div>

                            <div class="field">
                                <label>Puerto</label>
                                <select name="smtp_port" data-no-search>
                                    <option value="465" <?= (int)v($cfg,'smtp_port',465) === 465 ? 'selected':'' ?>>465 (SSL — recomendado)</option>
                                    <option value="587" <?= (int)v($cfg,'smtp_port',465) === 587 ? 'selected':'' ?>>587 (TLS)</option>
                                    <option value="25"  <?= (int)v($cfg,'smtp_port',465) === 25  ? 'selected':'' ?>>25 (Sin cifrado)</option>
                                </select>
                            </div>

                            <div class="field">
                                <label>Usuario / Email SMTP</label>
                                <input type="email" name="smtp_user" placeholder="correo@tudominio.com"
                                    value="<?= htmlspecialchars(v($cfg,'smtp_user')) ?>">
                            </div>

                            <div class="field">
                                <label>Contraseña SMTP</label>
                                <input type="password" name="smtp_pass" placeholder="••••••••"
                                    value="<?= !empty($cfg['smtp_pass']) ? '••••••••' : '' ?>"
                                    autocomplete="new-password">
                            </div>

                            <div class="field">
                                <label>Nombre del Remitente</label>
                                <input type="text" name="smtp_from_name" placeholder="Empresa"
                                    value="<?= htmlspecialchars(v($cfg,'smtp_from_name')) ?>">
                            </div>

                            <div class="field">
                                <label>Seguridad</label>
                                <select name="smtp_security" data-no-search>
                                    <option value="SSL" <?= v($cfg,'smtp_security','SSL') === 'SSL' ? 'selected':'' ?>>SSL</option>
                                    <option value="TLS" <?= v($cfg,'smtp_security','SSL') === 'TLS' ? 'selected':'' ?>>TLS</option>
                                    <option value="none"<?= v($cfg,'smtp_security','SSL') === 'none'? 'selected':'' ?>>Ninguna</option>
                                </select>
                            </div>
                        </div>

                        <div class="info-box">
                            💡 <strong>Gmail:</strong> Usa una <strong>Contraseña de Aplicación</strong> (no tu contraseña normal).
                            Ve a tu cuenta Google → Seguridad → Verificación en 2 pasos → Contraseñas de aplicaciones.<br>
                            💡 <strong>cPanel/Hostinger:</strong> Crea una cuenta de correo en el panel y usa esas credenciales directamente.
                        </div>

                        <div class="card-footer">
                            <button type="button" class="btn btn-ghost" onclick="enviarPrueba()">📨 Enviar email de prueba</button>
                            <button type="submit" class="btn btn-primary">💾 Guardar SMTP</button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- ═══════════════════════════════════════════════ -->
            <!-- SECCIÓN 3: NOTIFICACIONES                      -->
            <!-- ═══════════════════════════════════════════════ -->
            <div class="section" id="sec-notificaciones">
                <div class="card">
                    <div class="card-header">
                        <div class="card-header-left">
                            <span style="font-size:1.4rem">🔔</span>
                            <div>
                                <div class="card-title">Notificaciones Automáticas</div>
                                <div class="card-subtitle">Define qué eventos envían email al usuario</div>
                            </div>
                        </div>
                    </div>

                    <form id="form-notificaciones" onsubmit="guardar(event, 'notificaciones')">

                        <p style="font-size:.82rem;font-weight:700;color:var(--muted);margin-bottom:10px;">
                            🚀 Canal de envío
                        </p>

                        <div class="radio-group">
                            <label class="radio-card">
                                <input type="radio" name="notif_canal" value="email"
                                    <?= v($cfg,'notif_canal','email') === 'email' ? 'checked':'' ?>>
                                <div class="radio-card-body">
                                    <div class="radio-card-label">📧 Solo Email</div>
                                    <div class="radio-card-desc">Las notificaciones se envían únicamente por correo electrónico</div>
                                </div>
                            </label>
                            <label class="radio-card">
                                <input type="radio" name="notif_canal" value="email_whatsapp"
                                    <?= v($cfg,'notif_canal','email') === 'email_whatsapp' ? 'checked':'' ?>>
                                <div class="radio-card-body">
                                    <div class="radio-card-label">📧 + 📱 Email y WhatsApp</div>
                                    <div class="radio-card-desc">Llegan por correo Y por WhatsApp (requiere CallMeBot activo en el número del cliente)</div>
                                </div>
                            </label>
                        </div>

                        <div class="check-list">
                            <label class="check-card">
                                <input type="checkbox" name="notif_credenciales" value="1"
                                    <?= v($cfg,'notif_credenciales',0) ? 'checked':'' ?>>
                                <div class="check-card-body">
                                    <div class="check-card-label">🔑 Nuevas credenciales</div>
                                    <div class="check-card-desc">Envía usuario y contraseña cuando se crea o resetea acceso al portal</div>
                                </div>
                            </label>
                            <label class="check-card">
                                <input type="checkbox" name="notif_pago" value="1"
                                    <?= v($cfg,'notif_pago',0) ? 'checked':'' ?>>
                                <div class="check-card-body">
                                    <div class="check-card-label">✅ Confirmación de pago</div>
                                    <div class="check-card-desc">Envía recibo al registrar un pago de cuota o arancel</div>
                                </div>
                            </label>
                            <label class="check-card">
                                <input type="checkbox" name="notif_cuota_vencer" value="1"
                                    <?= v($cfg,'notif_cuota_vencer',0) ? 'checked':'' ?>>
                                <div class="check-card-body">
                                    <div class="check-card-label">⚠️ Cuota por vencer</div>
                                    <div class="check-card-desc">Aviso 3 días antes del vencimiento (requiere cron job configurado)</div>
                                </div>
                            </label>
                        </div>

                        <div class="card-footer" style="flex-wrap:wrap;gap:10px">
                            <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap">
                                <button type="button" class="btn btn-ghost" style="font-size:.78rem" onclick="testNotificacion('credenciales')">
                                    📧 Probar: Credenciales
                                </button>
                                <button type="button" class="btn btn-ghost" style="font-size:.78rem" onclick="testNotificacion('pago')">
                                    📧 Probar: Pago
                                </button>
                                <button type="button" class="btn btn-ghost" style="font-size:.78rem" onclick="testNotificacion('contrato')">
                                    📧 Probar: Contrato
                                </button>
                            </div>
                            <button type="submit" class="btn btn-primary">💾 Guardar Notificaciones</button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- ═══════════════════════════════════════════════ -->
            <!-- SECCIÓN 4: WHATSAPP                            -->
            <!-- ═══════════════════════════════════════════════ -->
            <div class="section" id="sec-whatsapp">
                <div class="card">
                    <div class="card-header">
                        <div class="card-header-left">
                            <span style="font-size:1.4rem">📱</span>
                            <div>
                                <div class="card-title">WhatsApp (CallMeBot)</div>
                                <div class="card-subtitle">Configuración de canal WhatsApp y notificaciones automáticas</div>
                            </div>
                        </div>
                    </div>

                    <form id="form-whatsapp" onsubmit="guardar(event, 'whatsapp')">

                        <!-- ── API Key ── -->
                        <div class="form-grid">
                            <div class="field form-full">
                                <label>API Key de CallMeBot</label>
                                <input type="text" name="callmebot_key" placeholder="Ej: 123456"
                                    value="<?= htmlspecialchars(v($cfg,'callmebot_key')) ?>">
                                <span class="field-hint">Obtenida al activar CallMeBot en tu número</span>
                            </div>
                        </div>

                                                <!-- ── Número de CallMeBot (tabla dinámica con toggle) ── -->
                        <div style="margin:0 0 20px;padding:16px;background:#f8fafc;border:1.5px solid var(--border);border-radius:10px">
                            <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:8px;flex-wrap:wrap;gap:8px">
                                <p style="font-size:.78rem;font-weight:800;color:var(--text);margin:0">
                                    📱 Números de CallMeBot
                                </p>
                                <span style="font-size:.7rem;color:var(--muted)">
                                    <?php if ($esSuperadmin): ?>Al agregar/eliminar se aplica a <strong>todos los centros</strong>.<?php endif; ?>
                                    Los mensajes se envían a todos los números <strong>activos</strong>.
                                </span>
                            </div>
                            <p style="font-size:.73rem;color:var(--muted);margin:0 0 14px">
                                Este es el número al que los usuarios envían el mensaje de activación.
                                CallMeBot lo cambia ocasionalmente — añade el nuevo y desactiva el viejo.
                            </p>

                            <!-- Tabla dinámica de números -->
                            <div id="cbm-lista" style="margin-bottom:14px">
                                <div style="display:flex;align-items:center;justify-content:center;padding:20px;color:var(--muted);font-size:.78rem;gap:8px">
                                    <span style="animation:spin 1s linear infinite;display:inline-block">⏳</span> Cargando números…
                                </div>
                            </div>

                            <!-- Formulario agregar -->
                            <div style="display:flex;gap:8px;align-items:flex-end;flex-wrap:wrap;padding-top:12px;border-top:1px solid var(--border)">
                                <div class="field" style="flex:1;min-width:150px;margin:0">
                                    <label style="font-size:.72rem">📱 Número</label>
                                    <input type="text" id="cbm-new-phone" placeholder="+34644663262" style="font-family:monospace;width:100%">
                                </div>
                                <div class="field" style="flex:2;min-width:160px;margin:0">
                                    <label style="font-size:.72rem">Descripción (opcional)</label>
                                    <input type="text" id="cbm-new-desc" placeholder="Ej: Número activo Mar 2026…" style="width:100%">
                                </div>
                                <button type="button" class="btn btn-primary" onclick="cbmAgregar()" style="height:40px;flex-shrink:0">
                                    ➕ <?php echo $esSuperadmin ? 'Agregar (todos los centros)' : 'Agregar'; ?>
                                </button>
                            </div>
                            <div id="cbm-msg" style="display:none;margin-top:8px;font-size:.8rem;padding:7px 12px;border-radius:7px"></div>
                        </div>

                        <!-- ── Instrucciones de activación ── -->
                        <div class="info-box" style="margin-top:0">
                            Cada usuario debe agregar el número activo a WhatsApp y enviarle:
                            <code style="background:#dbeafe;padding:2px 6px;border-radius:4px;font-size:.8rem">I allow callmebot to send me messages</code>
                            · Recibirán su API Key.<br>
                            <span style="color:#64748b">Nota: Los mensajes del sistema se enviarán a todos los números marcados como <strong>Activo</strong>.</span>
                        </div>

                        <!-- ── Probar envío de WhatsApp ── -->
                        <div style="margin-top:18px;padding-top:16px;border-top:1px solid var(--border)">
                            <p style="font-size:.78rem;font-weight:700;color:var(--muted);margin-bottom:10px">
                                🧪 Probar envío de WhatsApp
                            </p>
                            <div style="display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap">
                                <div class="field" style="flex:1;min-width:200px">
                                    <label>Número a probar</label>
                                    <input type="text" id="wa-test-phone" placeholder="Ej: 8091234567 o +18091234567"
                                           style="width:100%">
                                    <span class="field-hint">Debe haber activado CallMeBot en ese número primero</span>
                                </div>
                                <button type="button" class="btn btn-ghost" id="btn-wa-test"
                                        onclick="probarWhatsApp()"
                                        style="height:40px;white-space:nowrap;flex-shrink:0">
                                    📲 Enviar prueba
                                </button>
                            </div>
                            <div id="wa-test-result" style="display:none;margin-top:10px;font-size:.8rem;padding:8px 12px;border-radius:8px"></div>
                        </div>

                        <div class="card-footer" style="flex-wrap:wrap;gap:10px">
                            <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap">
                                <button type="button" class="btn btn-ghost" style="font-size:.78rem" onclick="testNotificacion('credenciales')">
                                    📧 Probar: Credenciales
                                </button>
                                <button type="button" class="btn btn-ghost" style="font-size:.78rem" onclick="testNotificacion('pago')">
                                    📧 Probar: Pago
                                </button>
                                <button type="button" class="btn btn-ghost" style="font-size:.78rem" onclick="testNotificacion('contrato')">
                                    📧 Probar: Contrato
                                </button>
                            </div>
                            <button type="submit" class="btn btn-primary">💾 Guardar WhatsApp</button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- ═══════════════════════════════════════════════ -->
            <!-- SECCIÓN 5: IMPRESORAS                          -->
            <!-- ═══════════════════════════════════════════════ -->
            <div class="section" id="sec-impresoras">
                <div class="card">
                    <div class="card-header">
                        <div class="card-header-left">
                            <span style="font-size:1.4rem">🖨️</span>
                            <div>
                                <div class="card-title">Configuración de Impresoras</div>
                                <div class="card-subtitle">Define qué formato usar para cada documento — se aplicará automáticamente al imprimir</div>
                            </div>
                        </div>
                    </div>

                    <form id="form-impresoras" onsubmit="guardar(event, 'impresoras')">

                        <div class="printer-list">
                            <?php
                            $docs = [
                                ['key'=>'imp_recibo',        'icon'=>'🧾', 'label'=>'Recibo de Pago',        'desc'=>'El ticket que se entrega al cliente al cobrar una cuota'],
                                ['key'=>'imp_contrato',      'icon'=>'📄', 'label'=>'Contrato / Documento',   'desc'=>'Documento oficial con todos los detalles'],
                                ['key'=>'imp_estado_cuenta', 'icon'=>'📊', 'label'=>'Estado de Cuenta',       'desc'=>'Historial de pagos y deudas del consultor'],
                                ['key'=>'imp_amortizacion',  'icon'=>'📋', 'label'=>'Tabla de Cuotas',        'desc'=>'Cuadro completo de cuotas, fechas y montos'],
                            ];
                            foreach ($docs as $doc):
                                $val = v($cfg, $doc['key'], 'normal');
                            ?>
                            <div class="printer-row">
                                <div class="printer-row-info">
                                    <div class="printer-row-label"><?= $doc['icon'] ?> <?= $doc['label'] ?></div>
                                    <div class="printer-row-desc"><?= $doc['desc'] ?></div>
                                </div>
                                <div class="printer-options">
                                    <button type="button" class="printer-btn <?= $val==='normal'?'active':'' ?>"
                                        onclick="setPrinter(this,'<?= $doc['key'] ?>','normal')">
                                        🖨️ Normal (A4/Carta)
                                    </button>
                                    <button type="button" class="printer-btn <?= $val==='pos80'?'active':'' ?>"
                                        onclick="setPrinter(this,'<?= $doc['key'] ?>','pos80')">
                                        🧾 POS 80mm
                                    </button>
                                    <button type="button" class="printer-btn <?= $val==='pos58'?'active':'' ?>"
                                        onclick="setPrinter(this,'<?= $doc['key'] ?>','pos58')">
                                        🧾 POS 58mm
                                    </button>
                                </div>
                                <input type="hidden" name="<?= $doc['key'] ?>" value="<?= $val ?>">
                            </div>
                            <?php endforeach; ?>
                        </div>

                        <div class="info-box" style="margin-top:20px">
                            💡 <strong>Cómo funciona:</strong> Al hacer clic en cualquier botón 🖨️ Imprimir de la aplicación,
                            se abrirá el documento ya con el formato configurado aquí, listo para imprimir directamente con
                            <strong>Ctrl+P</strong> o el botón "Imprimir / PDF".
                        </div>

                        <div class="card-footer">
                            <button type="button" class="btn btn-ghost" onclick="verDemo('recibo')">👁️ Ver demo recibo</button>
                            <button type="button" class="btn btn-ghost" onclick="verDemo('contrato')">👁️ Ver demo contrato</button>
                            <button type="submit" class="btn btn-primary">💾 Guardar Impresoras</button>
                        </div>
                    </form>
                </div>
            </div>
            <!-- ═══════════════════════════════════════════════════════════ -->
            <!-- SECCIÓN: MATERIAS ACADÉMICAS                              -->
            <!-- Admin: gestiona sus asignaturas Académicas del centro     -->
            <!-- ═══════════════════════════════════════════════════════════════ -->
            <!-- SECCIÓN: MATERIAS ACADÉMICAS                                  -->
            <!-- Superadmin → gestiona CATÁLOGO global + Tipos politécnico      -->
            <!-- Admin       → gestiona asignaturas del centro (desde catálogo) -->
            <!-- ═══════════════════════════════════════════════════════════════ -->
            <div class="section" id="sec-materias">

                <?php if ($esSuperadmin): ?>
                <!-- ══ SUPERADMIN ══════════════════════════════════════════════ -->

                <!-- Sub-tabs superadmin -->
                <div style="display:flex;gap:4px;background:var(--surface);border:1px solid var(--border);border-radius:10px;padding:5px;margin-bottom:12px;box-shadow:var(--shadow)">
                    <button class="mat-subtab active" onclick="switchMatTab('catalogo',this)"
                        style="flex:1;padding:8px 10px;border-radius:7px;border:none;background:var(--primary);color:#fff;font-family:inherit;font-size:.78rem;font-weight:700;cursor:pointer;transition:all .12s;display:flex;align-items:center;justify-content:center;gap:5px">
                        📖 Catálogo Académico
                    </button>
                    <button class="mat-subtab" onclick="switchMatTab('tipos-poli',this)"
                        style="flex:1;padding:8px 10px;border-radius:7px;border:none;background:none;color:var(--muted);font-family:inherit;font-size:.78rem;font-weight:700;cursor:pointer;transition:all .12s;display:flex;align-items:center;justify-content:center;gap:5px">
                        🔧 Tipos Avanzado
                    </button>
                </div>

                <!-- ── Sub-sección: Catálogo Académico ── -->
                <div class="mat-subsec active" id="mat-sec-catalogo">
                    <div class="card">
                        <div class="card-header">
                            <div class="card-header-left">
                                <span style="font-size:1.3rem">📖</span>
                                <div>
                                    <div class="card-title">Catálogo de Materias Académicas</div>
                                    <div class="card-subtitle">Materias disponibles para que los centros agreguen a sus asignaturas</div>
                                </div>
                            </div>
                            <button class="btn btn-primary" onclick="abrirModalCatalogo()" style="font-size:.78rem;padding:7px 14px">
                                ➕ Nueva Materia
                            </button>
                        </div>
                        <div style="margin-bottom:12px;display:flex;gap:10px;align-items:center;flex-wrap:wrap">
                            <input type="text" id="buscar-catalogo" placeholder="🔍 Buscar en catálogo..." oninput="filtrarCatalogo()"
                                style="padding:8px 13px;border:1.5px solid var(--border);border-radius:9px;font-family:inherit;font-size:.84rem;flex:1;min-width:180px;outline:none">
                            <span id="catalogo-count" style="font-size:.75rem;color:var(--muted);white-space:nowrap"></span>
                        </div>
                        <div style="overflow-x:auto">
                            <table style="width:100%;border-collapse:collapse;font-size:.82rem">
                                <thead>
                                    <tr style="border-bottom:2px solid var(--border)">
                                        <th style="text-align:left;padding:9px 12px;font-size:.72rem;color:var(--muted);text-transform:uppercase;letter-spacing:.05em;width:36px">#</th>
                                        <th style="text-align:left;padding:9px 12px;font-size:.72rem;color:var(--muted);text-transform:uppercase;letter-spacing:.05em">Materia</th>
                                        <th style="text-align:left;padding:9px 12px;font-size:.72rem;color:var(--muted);text-transform:uppercase;letter-spacing:.05em">Descripción</th>
                                        <th style="text-align:center;padding:9px 12px;font-size:.72rem;color:var(--muted);text-transform:uppercase;letter-spacing:.05em">Centros</th>
                                        <th style="text-align:right;padding:9px 12px;font-size:.72rem;color:var(--muted);text-transform:uppercase;letter-spacing:.05em">Acciones</th>
                                    </tr>
                                </thead>
                                <tbody id="tbody-catalogo">
                                    <tr><td colspan="5" style="text-align:center;padding:28px;color:var(--muted)">⏳ Cargando...</td></tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <!-- ── Sub-sección: Tipos Avanzado ── -->
                <div class="mat-subsec" id="mat-sec-tipos-poli" style="display:none">
                    <div class="card">
                        <div class="card-header">
                            <div class="card-header-left">
                                <span style="font-size:1.3rem">🔧</span>
                                <div>
                                    <div class="card-title">Tipos de Especialidades (Avanzado)</div>
                                    <div class="card-subtitle">Catálogo de especialidades técnicas disponibles para todos los centros</div>
                                </div>
                            </div>
                            <button class="btn btn-primary" onclick="abrirModalTipo()" style="font-size:.78rem;padding:7px 14px">
                                ➕ Nuevo Tipo
                            </button>
                        </div>
                        <div style="margin-bottom:12px;display:flex;gap:10px;align-items:center;flex-wrap:wrap">
                            <input type="text" id="buscar-tipos" placeholder="🔍 Buscar tipo..." oninput="filtrarTipos()"
                                style="padding:8px 13px;border:1.5px solid var(--border);border-radius:9px;font-family:inherit;font-size:.84rem;flex:1;min-width:180px;outline:none">
                            <label style="display:flex;align-items:center;gap:6px;font-size:.8rem;font-weight:600;cursor:pointer">
                                <input type="checkbox" id="chk-inactivos" onchange="filtrarTipos()" style="accent-color:var(--primary)">
                                Ver inactivos
                            </label>
                        </div>
                        <div style="overflow-x:auto">
                            <table style="width:100%;border-collapse:collapse;font-size:.82rem">
                                <thead>
                                    <tr style="border-bottom:2px solid var(--border)">
                                        <th style="text-align:left;padding:9px 12px;font-size:.72rem;color:var(--muted);text-transform:uppercase;letter-spacing:.05em">Nombre</th>
                                        <th style="text-align:left;padding:9px 12px;font-size:.72rem;color:var(--muted);text-transform:uppercase;letter-spacing:.05em">Descripción</th>
                                        <th style="text-align:center;padding:9px 12px;font-size:.72rem;color:var(--muted);text-transform:uppercase;letter-spacing:.05em">Materias</th>
                                        <th style="text-align:center;padding:9px 12px;font-size:.72rem;color:var(--muted);text-transform:uppercase;letter-spacing:.05em">Estado</th>
                                        <th style="text-align:right;padding:9px 12px;font-size:.72rem;color:var(--muted);text-transform:uppercase;letter-spacing:.05em">Acciones</th>
                                    </tr>
                                </thead>
                                <tbody id="tbody-tipos">
                                    <tr><td colspan="5" style="text-align:center;padding:28px;color:var(--muted)">⏳ Cargando tipos...</td></tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <?php else: ?>
                <!-- ══ ADMIN ═══════════════════════════════════════════════════ -->

                <!-- Sub-tabs admin -->
                <div style="display:flex;gap:4px;background:var(--surface);border:1px solid var(--border);border-radius:10px;padding:5px;margin-bottom:12px;box-shadow:var(--shadow)">
                    <button class="mat-subtab active" onclick="switchMatTab('mis-asignaturas',this)"
                        style="flex:1;padding:8px 10px;border-radius:7px;border:none;background:var(--primary);color:#fff;font-family:inherit;font-size:.78rem;font-weight:700;cursor:pointer;transition:all .12s;display:flex;align-items:center;justify-content:center;gap:5px">
                        📚 Mis Asignaturas
                    </button>
                    <button class="mat-subtab" onclick="switchMatTab('agregar-catalogo',this)"
                        style="flex:1;padding:8px 10px;border-radius:7px;border:none;background:none;color:var(--muted);font-family:inherit;font-size:.78rem;font-weight:700;cursor:pointer;transition:all .12s;display:flex;align-items:center;justify-content:center;gap:5px">
                        ➕ Agregar del Catálogo
                    </button>
                </div>

                <!-- ── Sub-sección: Mis Asignaturas del centro ── -->
                <div class="mat-subsec active" id="mat-sec-mis-asignaturas">
                    <div class="card">
                        <div class="card-header">
                            <div class="card-header-left">
                                <span style="font-size:1.3rem">📚</span>
                                <div>
                                    <div class="card-title">Asignaturas del Centro</div>
                                    <div class="card-subtitle">Materias académicas activas en tu empresa</div>
                                </div>
                            </div>
                            <button class="btn btn-primary" onclick="abrirModalAsignatura()" style="font-size:.78rem;padding:7px 14px">
                                ✏️ Nueva Personalizada
                            </button>
                        </div>
                        <div style="margin-bottom:12px;display:flex;gap:10px;align-items:center;flex-wrap:wrap">
                            <input type="text" id="buscar-asignaturas" placeholder="🔍 Buscar asignatura..." oninput="filtrarAsignaturas()"
                                style="padding:8px 13px;border:1.5px solid var(--border);border-radius:9px;font-family:inherit;font-size:.84rem;flex:1;min-width:180px;outline:none">
                            <select id="filtro-tipo-asig" data-no-search onchange="filtrarAsignaturas()"
                                style="padding:8px 13px;border:1.5px solid var(--border);border-radius:9px;font-family:inherit;font-size:.84rem;outline:none">
                                <option value="">Todos los tipos</option>
                                <option value="Academica">📖 Académica</option>
                                <option value="Politecnico">🔧 Avanzado</option>
                            </select>
                            <span id="asig-count" style="font-size:.75rem;color:var(--muted);white-space:nowrap"></span>
                        </div>
                        <div style="overflow-x:auto">
                            <table style="width:100%;border-collapse:collapse;font-size:.82rem">
                                <thead>
                                    <tr style="border-bottom:2px solid var(--border)">
                                        <th style="text-align:left;padding:9px 12px;font-size:.72rem;color:var(--muted);text-transform:uppercase;letter-spacing:.05em">Asignatura</th>
                                        <th style="text-align:left;padding:9px 12px;font-size:.72rem;color:var(--muted);text-transform:uppercase;letter-spacing:.05em">Descripción</th>
                                        <th style="text-align:center;padding:9px 12px;font-size:.72rem;color:var(--muted);text-transform:uppercase;letter-spacing:.05em">Tipo</th>
                                        <th style="text-align:right;padding:9px 12px;font-size:.72rem;color:var(--muted);text-transform:uppercase;letter-spacing:.05em">Acciones</th>
                                    </tr>
                                </thead>
                                <tbody id="tbody-asignaturas">
                                    <tr><td colspan="4" style="text-align:center;padding:28px;color:var(--muted)">⏳ Cargando asignaturas...</td></tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <!-- ── Sub-sección: Agregar del catálogo ── -->
                <div class="mat-subsec" id="mat-sec-agregar-catalogo" style="display:none">
                    <div class="card">
                        <div class="card-header">
                            <div class="card-header-left">
                                <span style="font-size:1.3rem">📋</span>
                                <div>
                                    <div class="card-title">Catálogo de Materias</div>
                                    <div class="card-subtitle">Selecciona las materias del catálogo global para agregar a tu centro</div>
                                </div>
                            </div>
                            <button class="btn btn-primary" id="btn-importar-seleccionadas" onclick="importarSeleccionadasCatalogo()"
                                style="font-size:.78rem;padding:7px 14px;display:none">
                                📥 Importar seleccionadas (<span id="cnt-seleccionadas">0</span>)
                            </button>
                        </div>
                        <div style="margin-bottom:12px;display:flex;gap:10px;align-items:center;flex-wrap:wrap">
                            <input type="text" id="buscar-cat-admin" placeholder="🔍 Buscar en catálogo..." oninput="filtrarCatalogoAdmin()"
                                style="padding:8px 13px;border:1.5px solid var(--border);border-radius:9px;font-family:inherit;font-size:.84rem;flex:1;min-width:180px;outline:none">
                        </div>
                        <!-- Grid de cards como el de politécnico -->
                        <div id="grid-catalogo-admin" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(230px,1fr));gap:8px">
                            <div style="text-align:center;padding:28px;color:var(--muted);grid-column:1/-1">⏳ Cargando catálogo...</div>
                        </div>
                        <div style="margin-top:16px;padding:12px 14px;background:#eff6ff;border-radius:10px;font-size:.76rem;color:#1e40af;line-height:1.6">
                            💡 Las materias que ya están en tu centro aparecen marcadas. Las que selecciones se agregarán como asignaturas académicas.
                        </div>
                    </div>
                </div>

                <?php endif; ?>
            </div><!-- /sec-materias -->

            <!-- ─── MODALES ────────────────────────────────────── -->

            <!-- Modal: Catálogo Académico (superadmin) -->
            <?php if ($esSuperadmin): ?>
            <div id="modal-catalogo" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:1000;align-items:center;justify-content:center">
                <div style="background:var(--surface,#fff);border-radius:14px;padding:28px;width:100%;max-width:480px;box-shadow:0 20px 60px rgba(0,0,0,.3);margin:16px">
                    <div style="font-size:1rem;font-weight:800;margin-bottom:20px;display:flex;justify-content:space-between;align-items:center">
                        <span id="modal-catalogo-title">➕ Nueva Materia al Catálogo</span>
                        <button onclick="cerrarModalCatalogo()" style="background:none;border:none;cursor:pointer;font-size:1.3rem;color:var(--muted)">×</button>
                    </div>
                    <input type="hidden" id="cat-id">
                    <div class="field" style="margin-bottom:14px">
                        <label>Nombre de la Materia *</label>
                        <input type="text" id="cat-nombre" placeholder="Ej: Matemáticas, Lengua Española, Ciencias"
                            style="padding:9px 13px;border:1.5px solid var(--border);border-radius:9px;font-family:inherit;font-size:.86rem;width:100%;outline:none">
                    </div>
                    <div class="field" style="margin-bottom:20px">
                        <label>Descripción</label>
                        <textarea id="cat-descripcion" placeholder="Descripción o áreas que cubre la materia..." rows="3"
                            style="padding:9px 13px;border:1.5px solid var(--border);border-radius:9px;font-family:inherit;font-size:.84rem;width:100%;resize:vertical;outline:none"></textarea>
                    </div>
                    <div style="display:flex;gap:10px;justify-content:flex-end">
                        <button onclick="cerrarModalCatalogo()" class="btn btn-ghost">Cancelar</button>
                        <button onclick="guardarCatalogo()" class="btn btn-primary" id="btn-guardar-catalogo">💾 Guardar</button>
                    </div>
                </div>
            </div>

            <!-- Modal: Tipo Avanzado (superadmin) -->
            <div id="modal-tipo" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:1000;align-items:center;justify-content:center">
                <div style="background:var(--surface,#fff);border-radius:14px;padding:28px;width:100%;max-width:480px;box-shadow:0 20px 60px rgba(0,0,0,.3);margin:16px">
                    <div style="font-size:1rem;font-weight:800;margin-bottom:20px;display:flex;justify-content:space-between;align-items:center">
                        <span id="modal-tipo-title">➕ Nuevo Tipo</span>
                        <button onclick="cerrarModalTipo()" style="background:none;border:none;cursor:pointer;font-size:1.3rem;color:var(--muted)">×</button>
                    </div>
                    <input type="hidden" id="tipo-id">
                    <div class="field" style="margin-bottom:14px">
                        <label>Nombre del Tipo *</label>
                        <input type="text" id="tipo-nombre" placeholder="Ej: Mecánica Industrial"
                            style="padding:9px 13px;border:1.5px solid var(--border);border-radius:9px;font-family:inherit;font-size:.86rem;width:100%;outline:none">
                    </div>
                    <div class="field" style="margin-bottom:14px">
                        <label>Descripción</label>
                        <textarea id="tipo-descripcion" placeholder="Descripción del área técnica..." rows="3"
                            style="padding:9px 13px;border:1.5px solid var(--border);border-radius:9px;font-family:inherit;font-size:.84rem;width:100%;resize:vertical;outline:none"></textarea>
                    </div>
                    <div class="field" style="margin-bottom:20px">
                        <label style="display:flex;align-items:center;gap:8px;cursor:pointer;font-weight:600">
                            <input type="checkbox" id="tipo-activo" checked style="width:16px;height:16px;accent-color:var(--primary)">
                            Tipo activo (visible para los centros)
                        </label>
                    </div>
                    <div style="display:flex;gap:10px;justify-content:flex-end">
                        <button onclick="cerrarModalTipo()" class="btn btn-ghost">Cancelar</button>
                        <button onclick="guardarTipo()" class="btn btn-primary" id="btn-guardar-tipo">💾 Guardar</button>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <!-- Modal: Asignatura personalizada (admin) -->
            <?php if (!$esSuperadmin): ?>
            <div id="modal-asignatura" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:1000;align-items:center;justify-content:center">
                <div style="background:var(--surface,#fff);border-radius:14px;padding:28px;width:100%;max-width:480px;box-shadow:0 20px 60px rgba(0,0,0,.3);margin:16px">
                    <div style="font-size:1rem;font-weight:800;margin-bottom:20px;display:flex;justify-content:space-between;align-items:center">
                        <span id="modal-asig-title">✏️ Asignatura Personalizada</span>
                        <button onclick="cerrarModalAsignatura()" style="background:none;border:none;cursor:pointer;font-size:1.3rem;color:var(--muted)">×</button>
                    </div>
                    <div style="padding:10px 14px;background:#fffbeb;border:1.5px solid #fde68a;border-radius:9px;font-size:.76rem;color:#92400e;margin-bottom:16px">
                        💡 Usa esto para agregar materias propias de tu centro que no están en el catálogo global.
                    </div>
                    <input type="hidden" id="asig-id">
                    <div class="field" style="margin-bottom:14px">
                        <label>Nombre de la Asignatura *</label>
                        <input type="text" id="asig-nombre" placeholder="Ej: Educación Física, Taller de Arte"
                            style="padding:9px 13px;border:1.5px solid var(--border);border-radius:9px;font-family:inherit;font-size:.86rem;width:100%;outline:none">
                    </div>
                    <div class="field" style="margin-bottom:14px">
                        <label>Descripción</label>
                        <textarea id="asig-descripcion" placeholder="Descripción opcional..." rows="2"
                            style="padding:9px 13px;border:1.5px solid var(--border);border-radius:9px;font-family:inherit;font-size:.84rem;width:100%;resize:vertical;outline:none"></textarea>
                    </div>
                    <div class="field" style="margin-bottom:20px">
                        <label>Tipo</label>
                        <select id="asig-tipo" data-no-search style="padding:9px 13px;border:1.5px solid var(--border);border-radius:9px;font-family:inherit;font-size:.86rem;width:100%;outline:none">
                            <option value="Academica">📖 Académica</option>
                            <option value="Politecnico">🔧 Avanzado</option>
                        </select>
                    </div>
                    <div style="display:flex;gap:10px;justify-content:flex-end">
                        <button onclick="cerrarModalAsignatura()" class="btn btn-ghost">Cancelar</button>
                        <button onclick="guardarAsignatura()" class="btn btn-primary" id="btn-guardar-asig">💾 Guardar</button>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <!-- ═══════════════════════════════════════════════ -->
            <!-- SECCIÓN: ENVIAR DATOS + POLITÉCNICO UNIFICADO  -->
            <!-- ═══════════════════════════════════════════════ -->
            <?php if ($esSuperadmin): ?>
            <div class="section" id="sec-enviar-datos">
                <div class="card">

                    <div class="card-header">
                        <div class="card-header-left">
                            <span style="font-size:1.4rem">📤</span>
                            <div>
                                <div class="card-title">Enviar Datos a Empresa</div>
                                <div class="card-subtitle">Copia tandas, niveles, asignaturas, períodos o especialidades a otro centro</div>
                            </div>
                        </div>
                    </div>

                    <!-- Empresa destino + modo -->
                    <div style="display:flex;gap:12px;align-items:center;margin-bottom:20px;flex-wrap:wrap">
                        <div class="field" style="flex:1;min-width:220px;margin:0">
                            <label>Empresa / Centro destino</label>
                            <select id="envio-empresa-destino" data-no-search style="padding:9px 13px;border:1.5px solid var(--border);border-radius:9px;font-family:inherit;font-size:.86rem;width:100%;outline:none">
                                <option value="">— Seleccionar empresa —</option>
                                <?php foreach ($empresasDestino as $emp): ?>
                                <option value="<?= $emp['id'] ?>" data-tipo="<?= htmlspecialchars($emp['modalidad']) ?>"><?= htmlspecialchars($emp['nombre']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="field" style="flex-shrink:0;margin:0">
                            <label>Si ya existe</label>
                            <div style="display:flex;gap:14px;align-items:center;padding:9px 0">
                                <label style="display:flex;align-items:center;gap:6px;cursor:pointer;font-size:.84rem;font-weight:600">
                                    <input type="radio" name="envio-modo" value="omitir" checked style="accent-color:var(--primary)"> Omitir
                                </label>
                                <label style="display:flex;align-items:center;gap:6px;cursor:pointer;font-size:.84rem;font-weight:600">
                                    <input type="radio" name="envio-modo" value="actualizar" style="accent-color:var(--primary)"> Actualizar
                                </label>
                            </div>
                        </div>
                    </div>

                    <!-- Datos Generales -->
                    <div style="margin-bottom:20px">
                        <div style="display:flex;align-items:center;gap:8px;margin-bottom:10px">
                            <span style="font-size:.72rem;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.05em">¿Qué datos enviar?</span>
                            <span style="font-size:.72rem;font-weight:800;color:var(--primary);background:#eff6ff;padding:2px 9px;border-radius:20px">Datos Generales</span>
                        </div>
                        <div id="grid-generales" class="envio-grid-gen">
                            <?php
                            $tiposEnvio = [
                                ['id'=>'tandas',           'ico'=>'🕐', 'label'=>'Tandas',               'desc'=>'Mañana, tarde, noche',        'requerido'=>false, 'solo_academico'=>false],
                                ['id'=>'niveles',          'ico'=>'📊', 'label'=>'Niveles y Grados',      'desc'=>'Primaria, secundaria',         'requerido'=>false, 'solo_academico'=>false],
                                ['id'=>'asignaturas',      'ico'=>'📖', 'label'=>'Asignaturas',            'desc'=>'Plan académico del centro 1',  'requerido'=>false, 'solo_academico'=>true],
                                ['id'=>'materias_catalogo','ico'=>'📚', 'label'=>'Materias Académicas',    'desc'=>'Catálogo global de materias',  'requerido'=>false, 'solo_academico'=>false],
                                ['id'=>'periodos',         'ico'=>'📅', 'label'=>'Períodos',               'desc'=>'Años y períodos',              'requerido'=>false, 'solo_academico'=>false],
                                ['id'=>'secciones',        'ico'=>'🏫', 'label'=>'Secciones',              'desc'=>'A, B, C…',                    'requerido'=>false, 'solo_academico'=>false],
                            ];
                            foreach ($tiposEnvio as $t): ?>
                            <label class="envio-check-row poli-card<?= $t['solo_academico'] ? ' envio-solo-academico' : '' ?>"
                                   data-tipo="<?= $t['id'] ?>"
                                   style="justify-content:flex-start;cursor:pointer">
                                <input type="checkbox" value="<?= $t['id'] ?>" class="envio-chk"
                                    style="width:15px;height:15px;accent-color:var(--primary);flex-shrink:0"
                                    onchange="toggleEnvioCheck(this.closest('.poli-card'), this.checked)">
                                <span style="font-size:1.2rem;flex-shrink:0"><?= $t['ico'] ?></span>
                                <div class="poli-card-info">
                                    <div class="poli-card-title"><?= $t['label'] ?></div>
                                    <div class="poli-card-desc"><?= $t['desc'] ?></div>
                                </div>
                            </label>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <!-- Especialidades Avanzado -->
                    <div id="envio-sec-avanzado" style="margin-bottom:20px;display:none">
                        <div style="display:flex;align-items:center;gap:8px;margin-bottom:10px">
                            <span style="font-size:.72rem;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.05em">Especialidades</span>
                            <span style="font-size:.72rem;font-weight:800;color:#7c3aed;background:#f5f3ff;padding:2px 9px;border-radius:20px">🔧 Avanzado</span>
                        </div>
                        <div id="grid-avanzado-envio">
                            <div style="text-align:center;padding:20px;color:var(--muted)">⏳ Cargando especialidades...</div>
                        </div>
                    </div>

                    <!-- Resultado -->
                    <div id="envio-resultado" style="display:none;padding:12px 14px;border-radius:10px;font-size:.78rem;line-height:1.7;margin-bottom:12px"></div>

                    <!-- Footer -->
                    <div class="envio-footer">
                        <div id="envio-historial" class="envio-historial"></div>
                        <button onclick="ejecutarEnvio()" id="btn-ejecutar-envio" class="btn btn-primary">
                            📤 Enviar ahora
                        </button>
                    </div>

                </div><!-- /.card -->
            </div><!-- /sec-enviar-datos -->
            <?php endif; ?>

            <?php if (!$esSuperadmin): ?>
            <div class="section" id="sec-avanzado">
                <div class="card">
                    <div class="card-header">
                        <div class="card-header-left">
                            <span style="font-size:1.4rem">🔧</span>
                            <div>
                                <div class="card-title">Especialidades Avanzado</div>
                                <div class="card-subtitle">Selecciona las especialidades técnicas de tu centro e importa sus materias al plan académico</div>
                            </div>
                        </div>
                    </div>

                    <div style="margin-bottom:16px">
                        <div style="display:flex;align-items:center;gap:8px;margin-bottom:10px">
                            <span style="font-size:.72rem;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.05em">Tipos disponibles</span>
                            <span style="font-size:.72rem;font-weight:800;color:#7c3aed;background:#f5f3ff;padding:2px 9px;border-radius:20px">🔧 Avanzado</span>
                        </div>
                        <div id="grid-avanzado">
                            <div style="text-align:center;padding:20px;color:var(--muted)">⏳ Cargando especialidades...</div>
                        </div>
                    </div>

                    <!-- Barra de importar seleccionados -->
                    <div id="poli-import-bar" style="display:none;margin-top:12px;padding:12px 14px;background:#f5f3ff;border-radius:10px;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap">
                        <span id="poli-import-label" style="font-size:.82rem;font-weight:600;color:#7c3aed"></span>
                        <button onclick="importarSeleccionados()" class="btn btn-primary" style="background:#7c3aed;border-color:#7c3aed">
                            📥 Importar materias seleccionadas
                        </button>
                    </div>
                </div>
            </div><!-- /sec-avanzado -->
            <?php endif; ?>


            <!-- ══ SECCIÓN: MONEDAS ══════════════════════════════════════ -->
            <div class="section" id="sec-moneda">
              <div style="max-width:860px">
                <h2 style="font-size:1rem;font-weight:700;color:var(--primary);margin:0 0 4px">💱 Monedas del Sistema</h2>
                <p style="font-size:.82rem;color:var(--muted);margin:0 0 22px">
                  Configura la moneda principal de operación y las tasas de cambio de referencia.
                </p>

                <!-- Moneda principal -->
                <div style="background:var(--surface);border:1.5px solid var(--border);border-radius:14px;padding:20px 24px;margin-bottom:16px;box-shadow:var(--shadow)">
                  <div style="font-size:.72rem;font-weight:800;color:var(--muted);text-transform:uppercase;letter-spacing:.06em;margin-bottom:14px">💰 Moneda Principal</div>
                  <form id="form-moneda" onsubmit="guardar(event,'moneda')">
                    <input type="hidden" name="seccion" value="moneda">
                    <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:14px;margin-bottom:18px">
                      <?php foreach ($monedas as $code => $label): ?>
                      <label style="display:flex;align-items:center;gap:12px;padding:13px 16px;border:2px solid <?= v($cfg,'moneda','DOP') === $code ? 'var(--primary)' : 'var(--border)' ?>;border-radius:10px;cursor:pointer;background:<?= v($cfg,'moneda','DOP') === $code ? '#eff6ff' : 'var(--bg)' ?>;transition:all .15s" onclick="selMoneda(this,'<?= $code ?>')">
                        <input type="radio" name="moneda" value="<?= $code ?>" <?= v($cfg,'moneda','DOP') === $code ? 'checked' : '' ?> style="display:none">
                        <span style="font-size:1.4rem"><?= explode(' ', $label)[0] ?></span>
                        <div>
                          <div style="font-weight:700;font-size:.84rem"><?= $code ?></div>
                          <div style="font-size:.72rem;color:var(--muted)"><?= implode(' ', array_slice(explode(' ', $label), 1)) ?></div>
                        </div>
                      </label>
                      <?php endforeach; ?>
                    </div>

                    <!-- Tasas de cambio referencia -->
                    <div style="background:#f8fafc;border:1.5px solid var(--border);border-radius:10px;padding:16px 20px;margin-bottom:18px">
                      <div style="font-size:.72rem;font-weight:800;color:var(--muted);text-transform:uppercase;letter-spacing:.06em;margin-bottom:12px">📊 Tasas de Cambio (referencia)</div>
                      <div style="display:grid;grid-template-columns:1fr 1fr 1fr 1fr;gap:12px">
                        <?php
                        $tasas = [
                          'tasa_usd' => ['🇺🇸', 'USD → DOP', '59.50'],
                          'tasa_eur' => ['🇪🇺', 'EUR → DOP', '64.80'],
                          'tasa_mxn' => ['🇲🇽', 'MXN → DOP', '3.10'],
                          'tasa_cop' => ['🇨🇴', 'COP → DOP', '0.015'],
                        ];
                        foreach ($tasas as $key => [$flag, $label, $default]):
                        ?>
                        <div class="field">
                          <label><?= $flag ?> <?= $label ?></label>
                          <input type="number" step="0.0001" name="<?= $key ?>" placeholder="<?= $default ?>"
                            value="<?= htmlspecialchars(v($cfg, $key, $default)) ?>"
                            style="padding:9px 13px;border:1.5px solid var(--border);border-radius:9px;font-family:inherit;font-size:.86rem;width:100%;box-sizing:border-box;outline:none">
                        </div>
                        <?php endforeach; ?>
                      </div>
                      <p style="font-size:.71rem;color:var(--muted);margin:10px 0 0">
                        💡 Estas tasas son de referencia. Los préstamos se registran en la moneda seleccionada arriba.
                      </p>
                    </div>

                    <!-- Formato de número -->
                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-bottom:18px">
                      <div class="field">
                        <label>Separador de miles</label>
                        <select name="sep_miles" data-no-search>
                          <option value="," <?= v($cfg,'sep_miles',',') === ',' ? 'selected' : '' ?>>, (coma) — 1,000.00</option>
                          <option value="." <?= v($cfg,'sep_miles',',') === '.' ? 'selected' : '' ?>>. (punto) — 1.000,00</option>
                          <option value=" " <?= v($cfg,'sep_miles',',') === ' ' ? 'selected' : '' ?>> (espacio) — 1 000,00</option>
                        </select>
                      </div>
                      <div class="field">
                        <label>Decimales a mostrar</label>
                        <select name="decimales" data-no-search>
                          <option value="0" <?= (string)v($cfg,'decimales','2') === '0' ? 'selected' : '' ?>>0 — Sin decimales</option>
                          <option value="2" <?= (string)v($cfg,'decimales','2') === '2' ? 'selected' : '' ?>>2 — Estándar (1,000.00)</option>
                          <option value="4" <?= (string)v($cfg,'decimales','2') === '4' ? 'selected' : '' ?>>4 — Alta precisión</option>
                        </select>
                      </div>
                    </div>

                    <div style="display:flex;justify-content:flex-end">
                      <button type="submit" class="btn btn-primary">💾 Guardar Monedas</button>
                    </div>
                  </form>
                </div>

                <!-- Preview -->
                <div style="background:#eff6ff;border:1.5px solid #bfdbfe;border-radius:10px;padding:14px 18px">
                  <div style="font-size:.72rem;font-weight:800;color:#1e40af;text-transform:uppercase;letter-spacing:.06em;margin-bottom:8px">👁️ Vista previa de formato</div>
                  <div style="display:flex;gap:24px;flex-wrap:wrap">
                    <div><span style="font-size:.75rem;color:var(--muted)">Monto pequeño</span><br><strong id="prev-small" style="font-size:1.1rem;color:var(--primary)">RD$ 1,250.00</strong></div>
                    <div><span style="font-size:.75rem;color:var(--muted)">Monto mediano</span><br><strong id="prev-med" style="font-size:1.1rem;color:var(--primary)">RD$ 125,000.00</strong></div>
                    <div><span style="font-size:.75rem;color:var(--muted)">Monto grande</span><br><strong id="prev-big" style="font-size:1.1rem;color:var(--primary)">RD$ 2,500,000.00</strong></div>
                  </div>
                </div>
              </div>
            </div><!-- /sec-moneda -->

            <!-- ══ SECCIÓN: MENSAJE DE AGRADECIMIENTO ═══════════════════ -->
            <div class="section" id="sec-agradecimiento">
              <div style="max-width:760px">
                <h2 style="font-size:1rem;font-weight:700;color:var(--primary);margin:0 0 4px">🙏 Mensaje de Agradecimiento</h2>
                <p style="font-size:.82rem;color:var(--muted);margin:0 0 22px">
                  Personaliza el mensaje que aparece en recibos, contratos y comunicaciones al cliente al finalizar una operación.
                </p>

                <form id="form-agradecimiento" onsubmit="guardar(event,'agradecimiento')">
                  <input type="hidden" name="seccion" value="agradecimiento">

                  <!-- Mensaje en recibo -->
                  <div style="background:var(--surface);border:1.5px solid var(--border);border-radius:14px;padding:20px 24px;margin-bottom:16px;box-shadow:var(--shadow)">
                    <div style="font-size:.72rem;font-weight:800;color:var(--muted);text-transform:uppercase;letter-spacing:.06em;margin-bottom:14px">🧾 Mensaje en Recibo de Pago</div>
                    <div class="field" style="margin-bottom:14px">
                      <label>Título del mensaje</label>
                      <input type="text" name="agrad_titulo" placeholder="¡Gracias por su pago!"
                        value="<?= htmlspecialchars(v($cfg,'agrad_titulo','¡Gracias por su pago!')) ?>">
                    </div>
                    <div class="field">
                      <label>Mensaje completo</label>
                      <textarea name="agrad_recibo" rows="3" placeholder="Agradecemos su puntualidad y confianza en nosotros. Su pago ha sido registrado exitosamente."><?= htmlspecialchars(v($cfg,'agrad_recibo','Agradecemos su puntualidad y confianza en nosotros. Su pago ha sido registrado exitosamente.')) ?></textarea>
                    </div>
                  </div>

                  <!-- Mensaje en contrato -->
                  <div style="background:var(--surface);border:1.5px solid var(--border);border-radius:14px;padding:20px 24px;margin-bottom:16px;box-shadow:var(--shadow)">
                    <div style="font-size:.72rem;font-weight:800;color:var(--muted);text-transform:uppercase;letter-spacing:.06em;margin-bottom:14px">📄 Mensaje al Pie del Contrato</div>
                    <div class="field" style="margin-bottom:14px">
                      <label>Clausula de agradecimiento</label>
                      <textarea name="agrad_contrato" rows="3" placeholder="Gracias por elegirnos. Nos comprometemos a brindarle el mejor servicio durante toda la vigencia de su préstamo."><?= htmlspecialchars(v($cfg,'agrad_contrato','Gracias por elegirnos. Nos comprometemos a brindarle el mejor servicio durante toda la vigencia de su préstamo.')) ?></textarea>
                    </div>
                  </div>

                  <!-- Mensaje en email/WhatsApp -->
                  <div style="background:var(--surface);border:1.5px solid var(--border);border-radius:14px;padding:20px 24px;margin-bottom:16px;box-shadow:var(--shadow)">
                    <div style="font-size:.72rem;font-weight:800;color:var(--muted);text-transform:uppercase;letter-spacing:.06em;margin-bottom:14px">📧 Firma en Emails y WhatsApp</div>
                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px">
                      <div class="field">
                        <label>Frase de cierre</label>
                        <input type="text" name="agrad_firma" placeholder="Atentamente,"
                          value="<?= htmlspecialchars(v($cfg,'agrad_firma','Atentamente,')) ?>">
                      </div>
                      <div class="field">
                        <label>Nombre que firma</label>
                        <input type="text" name="agrad_firmante" placeholder="El equipo de GestionPrestamo"
                          value="<?= htmlspecialchars(v($cfg,'agrad_firmante','El equipo de GestionPrestamo')) ?>">
                      </div>
                    </div>
                  </div>

                  <!-- Preview card -->
                  <div style="background:linear-gradient(135deg,#eff6ff,#f0fdf4);border:1.5px solid #bfdbfe;border-radius:14px;padding:20px 24px;margin-bottom:18px">
                    <div style="font-size:.72rem;font-weight:800;color:#1e40af;text-transform:uppercase;letter-spacing:.06em;margin-bottom:12px">👁️ Vista previa — Recibo</div>
                    <div style="background:white;border-radius:10px;padding:16px 20px;border:1px solid #e2e8f0">
                      <div style="text-align:center;padding:12px 0;border-bottom:1px dashed #e2e8f0;margin-bottom:12px">
                        <div style="font-size:.95rem;font-weight:800;color:#1d4ed8" id="prev-titulo">¡Gracias por su pago!</div>
                        <div style="font-size:.8rem;color:#64748b;margin-top:6px" id="prev-msg">Agradecemos su puntualidad y confianza en nosotros.</div>
                      </div>
                      <div style="text-align:right;font-size:.78rem;color:#94a3b8;font-style:italic">
                        <span id="prev-firma">Atentamente,</span> <strong id="prev-firmante">El equipo de GestionPrestamo</strong>
                      </div>
                    </div>
                  </div>

                  <div style="display:flex;justify-content:flex-end">
                    <button type="submit" class="btn btn-primary">💾 Guardar Mensajes</button>
                  </div>
                </form>
              </div>
            </div><!-- /sec-agradecimiento -->


            <!-- PERÍODOS DE EVALUACIÓN — eliminado, gestionado desde contrato -->


            <div class="section" id="sec-soporte">
              <div class="card" style="max-width:720px">
                <div class="card-h">
                  <h3 style="margin:0;font-size:1rem">🛠️ Soporte &amp; Diagnóstico</h3>
                </div>
                <div style="padding:20px 24px 24px">

                  <!-- Bloque modo debug -->
                  <div style="background:#fefce8;border:1.5px solid #fde047;border-radius:10px;padding:16px 20px;margin-bottom:20px">
                    <div style="display:flex;align-items:center;gap:10px;margin-bottom:6px">
                      <span style="font-size:1.25rem">⚠️</span>
                      <strong style="font-size:.9rem;color:#713f12">Modo Debug de Errores de Base de Datos</strong>
                    </div>
                    <p style="margin:0 0 12px;font-size:.8rem;color:#78350f;line-height:1.5">
                      Cuando está <strong>activo</strong>, todos los errores de base de datos en el sistema (al guardar, listar, actualizar o eliminar registros) mostrarán el mensaje técnico completo de MySQL en lugar del mensaje amigable.<br>
                      <span style="color:#b45309">⚡ Usar solo en desarrollo o diagnóstico. <u>Desactivar en producción.</u></span>
                    </p>
                    <div style="display:flex;align-items:center;gap:14px;flex-wrap:wrap">
                      <label style="display:flex;align-items:center;gap:8px;cursor:pointer;font-weight:600;font-size:.85rem;color:#1e293b">
                        <div class="toggle-wrap" id="debug-toggle-wrap" onclick="toggleDebug()" title="Click para activar/desactivar">
                          <div class="toggle-track" id="debug-track">
                            <div class="toggle-thumb" id="debug-thumb"></div>
                          </div>
                        </div>
                        <span id="debug-label">Cargando…</span>
                      </label>
                      <span id="debug-estado-badge" style="display:none;padding:3px 10px;border-radius:20px;font-size:.72rem;font-weight:700"></span>
                    </div>
                  </div>

                  <!-- Info de qué módulos usan friendlyDbError -->
                  <div style="background:#f0f9ff;border:1.5px solid #bae6fd;border-radius:10px;padding:16px 20px;margin-bottom:20px">
                    <div style="font-size:.78rem;font-weight:700;color:#0369a1;margin-bottom:10px;text-transform:uppercase;letter-spacing:.04em">📋 Módulos que respetan esta configuración</div>
                    <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:6px">
                      <?php foreach ([
                        '📚 Boletines','👤 Personas','📝 Contrato','📅 Plan Académico',
                        '🔧 Avanzado','👥 Usuarios','⚙️ Configuración','🏫 Institución',
                        '🔐 Autenticación','💰 Cobros','📊 Calificaciones',
                      ] as $mod): ?>
                      <div style="background:white;border:1px solid #e0f2fe;border-radius:6px;padding:5px 10px;font-size:.77rem;color:#0c4a6e"><?= $mod ?></div>
                      <?php endforeach; ?>
                    </div>
                  </div>

                  <!-- Explicación de los dos modos -->
                  <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
                    <div style="background:#f0fdf4;border:1.5px solid #86efac;border-radius:10px;padding:14px 16px">
                      <div style="font-size:.78rem;font-weight:700;color:#15803d;margin-bottom:6px">✅ MODO AMIGABLE (producción)</div>
                      <div style="font-size:.76rem;color:#166534;line-height:1.5">
                        Los errores muestran mensajes claros al usuario como:<br>
                        <em style="font-style:normal;background:#dcfce7;padding:2px 6px;border-radius:4px;display:inline-block;margin-top:4px">"Ya existe un registro con esos datos."</em>
                      </div>
                    </div>
                    <div style="background:#fef2f2;border:1.5px solid #fca5a5;border-radius:10px;padding:14px 16px">
                      <div style="font-size:.78rem;font-weight:700;color:#dc2626;margin-bottom:6px">🐛 MODO DEBUG (desarrollo)</div>
                      <div style="font-size:.76rem;color:#991b1b;line-height:1.5">
                        Los errores muestran el mensaje técnico de MySQL:<br>
                        <em style="font-style:normal;background:#fee2e2;padding:2px 6px;border-radius:4px;display:inline-block;margin-top:4px">"[DEBUG] PDOException: Table 'X' doesn't exist…"</em>
                      </div>
                    </div>
                  </div>

                  <div id="debug-save-msg" style="margin-top:12px;font-size:.8rem;display:none"></div>
                </div>
              </div>
            </div><!-- /sec-soporte -->

        </div><!-- /page -->

    </main>
</div>


<div class="toast" id="toast"></div>
<script src="/GestionPrestamo/js/dashboard.js?v=31"></script>
<script>
console.log("configuracion.js v31 loaded");

// ── Tabs ─────────────────────────────────────────────────────────────────────
let _avanzadoLoaded = false;
let _catalogoLoaded    = false;
let _asigLoaded        = false;
let _catAdminLoaded    = false;

let _soporteLoaded = false;

function switchTab(id, btn) {
    document.querySelectorAll('.section').forEach(s => s.classList.remove('active'));
    document.querySelectorAll('.tab').forEach(t => t.classList.remove('active'));
    const sec = document.getElementById('sec-' + id);
    if (!sec) return;
    sec.classList.add('active');
    btn.classList.add('active');
    // Cargar config soporte la primera vez
    if (id === 'soporte' && !_soporteLoaded) {
        _soporteLoaded = true;
        cargarSoporteConfig();
    }
    if ((id === 'avanzado' || id === 'enviar-datos') && !_avanzadoLoaded) {
        _avanzadoLoaded = true;
        cargarTiposPolitecnico();
    }
    if (id === 'boletines') {
        cargarPeriodosBoletines();
        cargarBoletinDefaults();
    }
    if (id === 'anio-electivo') {
        cargarAniosElectivos();
    }
    if (id === 'materias') {
        <?php if ($esSuperadmin): ?>
        if (!_catalogoLoaded) { _catalogoLoaded = true; cargarCatalogo(); }
        <?php else: ?>
        if (!_asigLoaded) { _asigLoaded = true; cargarAsignaturas(); }
        <?php endif; ?>
    }
}

function switchMatTab(id, btn) {
    document.querySelectorAll('.mat-subsection').forEach(s => s.classList.remove('active'));
    document.querySelectorAll('.mat-subtab').forEach(t => t.classList.remove('active'));
    const sec = document.getElementById('matsec-' + id);
    if (sec) sec.classList.add('active');
    btn.classList.add('active');
}

// ── Sidebar accordion ────────────────────────────────────────────────────────
function toggleSidebar() {
    document.getElementById('sidebar').classList.toggle('open');
    document.getElementById('overlay').classList.toggle('open');
}
function toggleGroup(header) {
    const body = header.nextElementSibling;
    const isOpen = header.classList.contains('open');
    header.classList.toggle('open', !isOpen);
    body.classList.toggle('open', !isOpen);
}

// ── Logo preview ──────────────────────────────────────────────────────────────
function previewLogo(input) {
    if (!input.files || !input.files[0]) return;
    const file = input.files[0];
    const reader = new FileReader();
    reader.onload = e => {
        const img = document.getElementById('logo-preview');
        if (img) { img.src = e.target.result; img.style.display = 'block'; }
    };
    reader.readAsDataURL(file);
}

// ── Vista previa recibo ───────────────────────────────────────────────────────
function previewRecibo() {
    const txt = document.querySelector('[name="pie_recibo"]')?.value || '';
    alert('Vista previa pie de recibo:\n\n' + txt);
}

// ── Impresoras ────────────────────────────────────────────────────────────────
function setPrinter(tipo, valor) {
    document.querySelector(`[name="${tipo}"]`).value = valor;
    document.querySelectorAll(`.printer-row[data-tipo="${tipo}"] .printer-opt`).forEach(b => b.classList.remove('active'));
    event.currentTarget.classList.add('active');
}

// ── Municipios dinámicos ──────────────────────────────────────────────────────
async function cargarMunicipios(idProvincia) {
    const sel = document.getElementById('sel-municipio');
    if (!sel) return;
    if (!idProvincia) { sel.innerHTML = '<option value="">— Municipio —</option>'; return; }
    try {
        const r = await fetch(`/GestionPrestamo/api/configuracion.php?action=municipios&id_provincia=${idProvincia}`);
        const d = await r.json();
        const curVal = sel.dataset.current || '';
        sel.innerHTML = '<option value="">— Municipio —</option>' +
            (d.municipios || []).map(m => `<option value="${m.id}" ${String(m.id)===curVal?'selected':''}>${m.nombre}</option>`).join('');
    } catch(e) { console.error(e); }
}

// ── Envío datos  ────────────────────────────────────────────────────────
function toggleEnvioCheck(checkbox, tipo) {
    const row = checkbox.closest('.envio-item');
    if (row) row.classList.toggle('selected', checkbox.checked);
}
async function ejecutarEnvio() {
    const btn = document.getElementById('btn-ejecutar-envio');
    if (!btn) return;
    const checked = [...document.querySelectorAll('.envio-chk:checked')].map(c => c.value);
    if (!checked.length) { showToast('⚠️ Selecciona al menos un módulo', 'error'); return; }
    const destino = document.getElementById('envio-empresa-destino')?.value || '';
    if (!destino) { showToast('⚠️ Selecciona un centro destino', 'error'); return; }
    const modoEl = document.querySelector('input[name="envio-modo"]:checked');
    const modo = modoEl ? modoEl.value : 'omitir';
    const orig = btn.innerHTML;
    btn.disabled = true; btn.innerHTML = '⏳ Enviando…';
    try {
        const fd = new FormData();
        fd.append('action', 'enviar_datos');
        fd.append('id_destino', destino);
        fd.append('modo', modo);
        checked.forEach(v => fd.append('modulos[]', v));
        const r = await fetch('/GestionPrestamo/api/configuracion.php', { method: 'POST', body: fd });
        const d = await r.json();
        showToast(d.success ? '✅ Enviado correctamente' : '❌ ' + (d.error || 'Error'), d.success ? 'success' : 'error');
    } catch(e) { showToast('❌ Error de conexión', 'error'); }
    finally { btn.disabled = false; btn.innerHTML = orig; }
}
async function enviarPrueba() {
    const btn = event.currentTarget;
    const orig = btn.innerHTML;
    btn.disabled = true; btn.innerHTML = '⏳ Enviando…';
    try {
        const r = await fetch('/GestionPrestamo/api/configuracion.php?action=test_smtp');
        const d = await r.json();
        showToast(d.success ? '✅ ' + (d.mensaje || 'Email enviado') : '❌ ' + (d.error || 'Error'), d.success ? 'success' : 'error');
    } catch(e) { showToast('❌ Error de conexión', 'error'); }
    finally { btn.disabled = false; btn.innerHTML = orig; }
}

// ── CallMeBot Números — tabla con toggle activo/inactivo ────────────────────
async function cbmCargar() {
    const lista = document.getElementById('cbm-lista');
    if (!lista) return;
    lista.innerHTML = '<div style="display:flex;align-items:center;justify-content:center;padding:20px;color:var(--muted);font-size:.78rem;gap:8px"><span style="animation:spin 1s linear infinite;display:inline-block">⏳</span> Cargando…</div>';
    try {
        const r = await fetch('/GestionPrestamo/api/configuracion.php?action=callmebot_numeros_list');
        const d = await r.json();
        if (!d.success) { lista.innerHTML = `<p style="color:#ef4444;font-size:.78rem;padding:10px">${escH(d.error||'Error al cargar.')}</p>`; return; }
        if (!d.numeros?.length) {
            lista.innerHTML = '<p style="color:var(--muted);font-size:.78rem;text-align:center;padding:16px">Sin números registrados — agrega el primero.</p>';
            return;
        }
        lista.innerHTML = `
        <table style="width:100%;border-collapse:collapse;font-size:.78rem">
            <thead>
                <tr style="background:#f1f5f9">
                    <th style="padding:8px 10px;text-align:left;border-radius:6px 0 0 0;font-weight:700;color:var(--muted)">Número</th>
                    <th style="padding:8px 10px;text-align:left;font-weight:700;color:var(--muted)">Descripción</th>
                    <th style="padding:8px 10px;text-align:center;font-weight:700;color:var(--muted)">Estado</th>
                    <th style="padding:8px 10px;text-align:center;border-radius:0 6px 0 0;font-weight:700;color:var(--muted)">Acciones</th>
                </tr>
            </thead>
            <tbody id="cbm-tbody">
                ${d.numeros.map(n => cbmFila(n)).join('')}
            </tbody>
        </table>`;
    } catch(e) {
        lista.innerHTML = '<p style="color:#ef4444;font-size:.78rem;padding:10px">Error al cargar números.</p>';
    }
}

function cbmFila(n) {
    const activo = parseInt(n.activo);
    return `<tr id="cbm-row-${n.id}" style="border-bottom:1px solid var(--border)">
        <td style="padding:9px 10px;font-family:monospace;font-weight:700">${escH(n.phone)}</td>
        <td style="padding:9px 10px;color:var(--muted)">${escH(n.descripcion||'—')}</td>
        <td style="padding:9px 10px;text-align:center">
            <span id="cbm-badge-${n.id}" style="font-size:.68rem;padding:3px 10px;border-radius:20px;font-weight:700;cursor:pointer;
                background:${activo?'#dcfce7':'#f1f5f9'};color:${activo?'#166534':'#94a3b8'};
                border:1.5px solid ${activo?'#86efac':'#e2e8f0'};transition:all .15s"
                onclick="cbmToggle(${n.id})" title="${activo?'Clic para desactivar':'Clic para activar'}">
                ${activo?'● Activo':'○ Inactivo'}
            </span>
        </td>
        <td style="padding:9px 10px;text-align:center">
            <button onclick="cbmEliminar(${n.id})" type="button"
                style="background:none;border:1.5px solid #fca5a5;border-radius:6px;cursor:pointer;
                       color:#ef4444;font-size:.75rem;padding:4px 10px;transition:all .1s"
                onmouseover="this.style.background='#fee2e2'" onmouseout="this.style.background='none'"
                title="Eliminar">🗑️ Eliminar</button>
        </td>
    </tr>`;
}

function escH(s){ return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }

async function cbmToggle(id) {
    const badge = document.getElementById('cbm-badge-' + id);
    if (badge) badge.style.opacity = '0.5';
    const fd = new FormData();
    fd.append('action', 'callmebot_numeros_toggle');
    fd.append('id', id);
    try {
        const r = await fetch('/GestionPrestamo/api/configuracion.php', {method:'POST', body:fd});
        const d = await r.json();
        if (d.success) {
            await cbmCargar(); // Reload table to reflect state
            const esSA = <?= $esSuperadmin ? 'true' : 'false' ?>;
            const msg = d.activo ? '● Número activado' : '○ Número desactivado';
            cbmMostrarMsg(msg + (esSA ? ' en todos los centros' : ''), d.activo ? 'success' : 'info');
        } else {
            cbmMostrarMsg(d.error||'Error al cambiar estado.','error');
            if (badge) badge.style.opacity = '1';
        }
    } catch(e) {
        cbmMostrarMsg('Error de conexión.','error');
        if (badge) badge.style.opacity = '1';
    }
}

async function cbmAgregar() {
    const phone = (document.getElementById('cbm-new-phone')?.value || '').trim();
    const desc  = (document.getElementById('cbm-new-desc')?.value  || '').trim();
    if (!phone) { cbmMostrarMsg('Ingresa un número de teléfono.','error'); return; }
    const fd = new FormData();
    fd.append('action','callmebot_numeros_add'); fd.append('phone',phone); fd.append('descripcion',desc);
    const r = await fetch('/GestionPrestamo/api/configuracion.php', {method:'POST',body:fd});
    const d = await r.json();
    if (d.success) {
        document.getElementById('cbm-new-phone').value = '';
        document.getElementById('cbm-new-desc').value  = '';
        cbmMostrarMsg(d.mensaje||'Número agregado ✓','success');
        cbmCargar();
    } else {
        cbmMostrarMsg(d.error||'Error al agregar.','error');
    }
}

async function cbmEliminar(id) {
    const esSA = <?= $esSuperadmin ? 'true' : 'false' ?>;
    const confirmMsg = esSA
        ? '¿Eliminar este número de TODOS los centros?'
        : '¿Eliminar este número?';
    if (!confirm(confirmMsg)) return;
    const fd = new FormData();
    fd.append('action','callmebot_numeros_del'); fd.append('id',id);
    const r = await fetch('/GestionPrestamo/api/configuracion.php', {method:'POST',body:fd});
    const d = await r.json();
    if (d.success) { cbmMostrarMsg(d.mensaje||'Número eliminado.','success'); cbmCargar(); }
    else cbmMostrarMsg(d.error||'Error.','error');
}

function cbmMostrarMsg(text, tipo) {
    const el = document.getElementById('cbm-msg');
    if (!el) return;
    el.textContent = text;
    el.style.display = 'block';
    const styles = {
        success: ['#dcfce7','#166534'],
        error:   ['#fee2e2','#991b1b'],
        info:    ['#dbeafe','#1e40af']
    };
    const [bg,color] = styles[tipo] || styles.info;
    el.style.background = bg;
    el.style.color = color;
    setTimeout(()=>{ el.style.display='none'; }, 4000);
}

// ── Enviar Datos: ocultar/mostrar cards según modalidad del destino ──────────
function actualizarEnvioSegunDestino() {
    const sel = document.getElementById('envio-empresa-destino');
    if (!sel) return;
    const opt = sel.options[sel.selectedIndex];
    const modalidad = opt ? (opt.getAttribute('data-tipo') || 'Academica') : 'Academica';
    const esPolitecnico = modalidad === 'Politecnico';

    // Mostrar/ocultar cards solo_academico (ej: Asignaturas)
    document.querySelectorAll('.envio-solo-academico').forEach(card => {
        card.style.display = esPolitecnico ? 'none' : '';
        // Si se oculta, desmarcar el checkbox para no enviarlo
        if (esPolitecnico) {
            const chk = card.querySelector('.envio-chk');
            if (chk) { chk.checked = false; toggleEnvioCheck(card, false); }
        }
    });

    // Mostrar/ocultar sección especialidades politécnico
    const secPoli = document.getElementById('envio-sec-avanzado');
    if (secPoli) secPoli.style.display = esPolitecnico ? '' : 'none';
}

document.addEventListener('DOMContentLoaded', function() {
    const sel = document.getElementById('envio-empresa-destino');
    if (sel) {
        sel.addEventListener('change', actualizarEnvioSegunDestino);
        // Ejecutar al cargar para reflejar el valor inicial
        actualizarEnvioSegunDestino();
    }
});


document.addEventListener('DOMContentLoaded', function(){
    // Patch switchTab para detectar cuando se selecciona whatsapp
    const _origSwitchTab = window.switchTab;
    window.switchTab = function(tab, el) {
        if (_origSwitchTab) _origSwitchTab(tab, el);
        if (tab === 'whatsapp') setTimeout(cbmCargar, 80);
    };
    // Cargar si ya está activo el tab whatsapp (por URL o por defecto)
    const hash = new URLSearchParams(location.search).get('tab');
    if (hash === 'whatsapp') {
        setTimeout(cbmCargar, 300);
    } else {
        // También intentar cargar si sec-whatsapp está visible
        const sec = document.getElementById('sec-whatsapp');
        if (sec && sec.classList.contains('active')) setTimeout(cbmCargar, 200);
    }
});

async function probarWhatsApp() {
    const phoneInput = document.getElementById('wa-test-phone');
    const btn        = document.getElementById('btn-wa-test');
    const result     = document.getElementById('wa-test-result');
    const phone      = (phoneInput?.value || '').trim();

    if (!phone) {
        if (result) {
            result.style.display = 'block';
            result.style.background = '#fef3c7';
            result.style.color = '#92400e';
            result.textContent = '⚠️ Ingresa un número de WhatsApp para probar.';
        }
        return;
    }

    const orig = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '⏳ Enviando…';
    if (result) result.style.display = 'none';

    try {
        const url = `/GestionPrestamo/api/configuracion.php?action=test_notificacion&tipo=credenciales&whatsapp=${encodeURIComponent(phone)}&email=skip`;
        const r = await fetch(url);
        const d = await r.json();

        if (result) {
            result.style.display = 'block';
            if (d.resultados?.whatsapp) {
                result.style.background = '#dcfce7';
                result.style.color = '#166534';
                result.innerHTML = '✅ Mensaje enviado correctamente. Revisa el WhatsApp del número ingresado.';
            } else {
                const err = d.resultados?.whatsapp_error || d.error || 'Error desconocido';
                result.style.background = '#fee2e2';
                result.style.color = '#991b1b';
                result.innerHTML = `❌ <strong>Error:</strong> ${err}`;
            }
        }
    } catch(e) {
        if (result) {
            result.style.display = 'block';
            result.style.background = '#fee2e2';
            result.style.color = '#991b1b';
            result.textContent = '❌ Error de conexión al servidor.';
        }
    } finally {
        btn.disabled = false;
        btn.innerHTML = orig;
    }
}

async function testNotificacion(tipo) {
    const email = prompt(`Ingresa el email donde quieres recibir la prueba de "${tipo}":\n(Déjalo vacío para usar el email configurado en SMTP)`);
    if (email === null) return; // Canceló
    let url = `/GestionPrestamo/api/configuracion.php?action=test_notificacion&tipo=${encodeURIComponent(tipo)}`;
    if (email.trim()) url += `&email=${encodeURIComponent(email.trim())}`;
    showToast('⏳ Enviando notificación de prueba…', 'info');
    try {
        const r = await fetch(url);
        const d = await r.json();
        showToast(d.success ? '✅ ' + (d.mensaje || 'Enviado') : '⚠️ ' + (d.mensaje || d.error || 'Error'), d.success ? 'success' : 'error');
        if (d.resultados?.email_error)    console.warn('Email error:', d.resultados.email_error);
        if (d.resultados?.whatsapp_error) console.warn('WhatsApp error:', d.resultados.whatsapp_error);
    } catch(e) { showToast('❌ Error de conexión', 'error'); }
}
function verDemo() {
    const el = document.getElementById('demo-recibo');
    if (el) el.style.display = el.style.display === 'none' ? 'block' : 'none';
}

// ── Guardar config empresa (AJAX) ─────────────────────────────────────────────
async function guardar(event, seccion) {
    if (event) event.preventDefault();
    // Determinar el formulario: buscar por id específico según sección, o el que disparó el evento
    let form = null;
    if (seccion) {
        form = document.getElementById(`form-${seccion}`);
    }
    if (!form && event && event.target && event.target.tagName === 'FORM') {
        form = event.target;
    }
    if (!form) {
        form = document.getElementById('form-empresa') || document.querySelector('form[data-seccion]');
    }
    if (!form) { showToast('❌ Formulario no encontrado', 'error'); return; }

    // Determinar sección desde el form si no se pasó explícitamente
    if (!seccion) {
        seccion = form.dataset.seccion || form.id.replace('form-', '') || 'empresa';
    }

    // Buscar botón submit dentro del form
    const btn = form.querySelector('[type=submit], .btn-primary') || document.getElementById('btn-guardar');
    const msgEl = document.getElementById('msg-guardar');
    const orig = btn ? btn.innerHTML : '';
    if (btn) { btn.disabled = true; btn.innerHTML = '⏳ Guardando…'; }
    try {
        const fd = new FormData(form);
        // Asegurar que la sección siempre se envíe
        if (!fd.has('seccion')) fd.append('seccion', seccion);
        const r = await fetch('/GestionPrestamo/api/configuracion.php', { method: 'POST', body: fd });
        const d = await r.json();
        if (d.success) {
            showToast('✅ ' + (d.mensaje || 'Guardado'), 'success');
            if (msgEl) { msgEl.style.display=''; msgEl.style.color='#15803d'; msgEl.innerHTML='✅ ' + (d.mensaje||'Guardado'); }
        } else {
            showToast('❌ ' + (d.error || 'Error'), 'error');
            if (msgEl) { msgEl.style.display=''; msgEl.style.color='#b91c1c'; msgEl.innerHTML='❌ ' + (d.error||'Error'); }
        }
    } catch(e) {
        showToast('❌ Error de conexión', 'error');
    } finally {
        if (btn) { btn.disabled = false; btn.innerHTML = orig; }
    }
}

// ── Avanzado ───────────────────────────────────────────────────────────────
async function cargarTiposPolitecnico() {
    const container = document.getElementById('grid-avanzado');
    if (!container) return;
    try {
        const res = await fetch('/GestionPrestamo/api/modulos.php?modulo=asignaturas&action=tipos_avanzado');
        const data = await res.json();
        if (data.error) throw new Error(data.error);
        const tipos = [...(data.tipos||[])].sort((a,b) => a.nombre.localeCompare(b.nombre));
        container.innerHTML = tipos.length ? tipos.map(t => `
<div class="poli-card" style="border:1.5px solid var(--border);border-radius:12px;padding:16px;background:var(--surface)">
  <div style="font-weight:700;font-size:.9rem;margin-bottom:6px">${t.nombre}</div>
  <div style="font-size:.75rem;color:var(--muted)">${t.descripcion||''}</div>
</div>`).join('') : '<p style="color:var(--muted)">No hay tipos registrados.</p>';
    } catch(e) { if(container) container.innerHTML = `<p style="color:#b91c1c">Error: ${e.message}</p>`; }
}

async function abrirModalTipo() {
    const modal = document.getElementById('modal-tipo');
    if (modal) { modal.style.display = 'flex'; document.getElementById('tipo-nombre')?.focus(); }
}
function cerrarModalTipo() {
    const modal = document.getElementById('modal-tipo');
    if (modal) modal.style.display = 'none';
}
async function guardarTipo() {
    const nombre = document.getElementById('tipo-nombre')?.value?.trim();
    if (!nombre) { showToast('⚠️ Ingresa un nombre', 'error'); return; }
    try {
        const fd = new FormData();
        fd.append('modulo', 'asignaturas'); fd.append('action', 'crear_tipo_avanzado'); fd.append('nombre', nombre);
        const r = await fetch('/GestionPrestamo/api/modulos.php', { method: 'POST', body: fd });
        const d = await r.json();
        if (d.success) { showToast('✅ Tipo creado', 'success'); cerrarModalTipo(); cargarTiposPolitecnico(); }
        else showToast('❌ ' + (d.error||'Error'), 'error');
    } catch(e) { showToast('❌ Error', 'error'); }
}
function filtrarTipos(q) {
    document.querySelectorAll('.poli-card').forEach(c => {
        c.style.display = c.textContent.toLowerCase().includes(q.toLowerCase()) ? '' : 'none';
    });
}

// ── Materias / Asignaturas ────────────────────────────────────────────────────
let _catAdminSel = new Set();
async function cargarCatalogo() {
    const container = document.getElementById('grid-catalogo');
    if (!container) return;
    try {
        const r = await fetch('/GestionPrestamo/api/modulos.php?modulo=asignaturas&action=catalogo');
        const d = await r.json();
        if (d.error) throw new Error(d.error);
        container.innerHTML = (d.asignaturas||[]).map(a => `
<div class="cat-card" data-id="${a.id}" onclick="toggleCatSel(this)" style="border:1.5px solid var(--border);border-radius:10px;padding:12px;cursor:pointer;background:var(--surface)">
  <div style="font-weight:600;font-size:.85rem">${a.nombre}</div>
  <div style="font-size:.72rem;color:var(--muted)">${a.tipo||''}</div>
</div>`).join('') || '<p style="color:var(--muted)">Catálogo vacío.</p>';
    } catch(e) { if(container) container.innerHTML=`<p style="color:#b91c1c">Error: ${e.message}</p>`; }
}
function toggleCatSel(card) {
    const id = card.dataset.id;
    if (_catAdminSel.has(id)) { _catAdminSel.delete(id); card.style.borderColor=''; card.style.background=''; }
    else { _catAdminSel.add(id); card.style.borderColor='var(--primary)'; card.style.background='#eff6ff'; }
}
function filtrarAsignaturas(q) {
    const container = document.getElementById('grid-catalogo') || document.getElementById('grid-mis-asignaturas');
    if (!container) return;
    container.querySelectorAll('[class$="-card"]').forEach(c => {
        c.style.display = c.textContent.toLowerCase().includes(q.toLowerCase()) ? '' : 'none';
    });
}
async function cargarAsignaturas() {
    const container = document.getElementById('grid-mis-asignaturas');
    if (!container) return;
    try {
        const r = await fetch('/GestionPrestamo/api/modulos.php?modulo=asignaturas&action=mis_asignaturas');
        const d = await r.json();
        if (d.error) throw new Error(d.error);
        container.innerHTML = (d.asignaturas||[]).map(a => `
<div class="asig-card" style="border:1.5px solid var(--border);border-radius:10px;padding:12px;background:var(--surface)">
  <div style="font-weight:600;font-size:.85rem">${a.nombre}</div>
  <div style="font-size:.72rem;color:var(--muted)">${a.tipo||''}</div>
</div>`).join('') || '<p style="color:var(--muted)">Sin asignaturas asignadas.</p>';
    } catch(e) { if(container) container.innerHTML=`<p style="color:#b91c1c">Error: ${e.message}</p>`; }
}
async function abrirModalAsignatura() {
    const modal = document.getElementById('modal-asignatura');
    if (modal) { modal.style.display='flex'; document.getElementById('asig-nombre')?.focus(); }
}
function cerrarModalAsignatura() {
    const modal = document.getElementById('modal-asignatura');
    if (modal) modal.style.display='none';
}
async function guardarAsignatura() {
    const nombre = document.getElementById('asig-nombre')?.value?.trim();
    if (!nombre) { showToast('⚠️ Ingresa un nombre', 'error'); return; }
    try {
        const fd = new FormData();
        fd.append('modulo','asignaturas'); fd.append('action','crear_asignatura'); fd.append('nombre',nombre);
        const r = await fetch('/GestionPrestamo/api/modulos.php', { method:'POST', body:fd });
        const d = await r.json();
        if (d.success) { showToast('✅ Asignatura creada','success'); cerrarModalAsignatura(); cargarAsignaturas(); }
        else showToast('❌ '+(d.error||'Error'),'error');
    } catch(e) { showToast('❌ Error','error'); }
}
async function abrirModalCatalogo() {
    const modal = document.getElementById('modal-catalogo');
    if (modal) { modal.style.display='flex'; document.getElementById('cat-nombre')?.focus(); }
}
function cerrarModalCatalogo() {
    const modal = document.getElementById('modal-catalogo');
    if (modal) modal.style.display='none';
}
async function guardarCatalogo() {
    const nombre = document.getElementById('cat-nombre')?.value?.trim();
    if (!nombre) { showToast('⚠️ Ingresa un nombre','error'); return; }
    try {
        const fd = new FormData();
        fd.append('modulo','asignaturas'); fd.append('action','crear_catalogo'); fd.append('nombre',nombre);
        const r = await fetch('/GestionPrestamo/api/modulos.php', { method:'POST', body:fd });
        const d = await r.json();
        if (d.success) { showToast('✅ Creado','success'); cerrarModalCatalogo(); cargarCatalogo(); }
        else showToast('❌ '+(d.error||'Error'),'error');
    } catch(e) { showToast('❌ Error','error'); }
}
async function importarSeleccionados() {
    const ids = [..._catAdminSel];
    if (!ids.length) { showToast('⚠️ Selecciona al menos una asignatura','error'); return; }
    try {
        const fd = new FormData();
        fd.append('modulo','asignaturas'); fd.append('action','importar_asignaturas');
        ids.forEach(id => fd.append('ids[]', id));
        const r = await fetch('/GestionPrestamo/api/modulos.php', { method:'POST', body:fd });
        const d = await r.json();
        if (d.success) { showToast('✅ Importadas','success'); _catAdminSel.clear(); cargarAsignaturas(); }
        else showToast('❌ '+(d.error||'Error'),'error');
    } catch(e) { showToast('❌ Error','error'); }
}
async function importarSeleccionadasCatalogo() { importarSeleccionados(); }

// ── Año Electivo ──────────────────────────────────────────────────────────────
async function cargarAniosElectivos() {
    // El div en el HTML se llama 'ae-lista'
    const container = document.getElementById('ae-lista') || document.getElementById('grid-anios-electivos') || document.getElementById('anio-electivo-content');
    if (!container) return;
    container.innerHTML = '<div style="text-align:center;padding:28px;color:var(--muted)">⏳ Cargando años electivos...</div>';
    try {
        const r = await fetch('/GestionPrestamo/api/configuracion.php?action=get_anios_electivos');
        const d = await r.json();
        if (d.error) throw new Error(d.error);
        // La API devuelve 'periodos' (no 'anios')
        const anios = d.periodos || d.anios || [];
        // Banner de activos
        const banner = document.getElementById('ae-activos-banner');
        const activosCount = d.activos_count ?? anios.filter(a => a.activo == 1).length;
        if (banner) {
            banner.innerHTML = activosCount > 0
                ? `<div style="display:inline-flex;align-items:center;gap:8px;padding:7px 14px;background:#dbeafe;border-radius:8px;font-size:.82rem;font-weight:600;color:#1d4ed8">✅ ${activosCount} año${activosCount>1?'s':''} electivo${activosCount>1?'s':''} activo${activosCount>1?'s':''}</div>`
                : '<div style="display:inline-flex;align-items:center;gap:8px;padding:7px 14px;background:#fef9c3;border-radius:8px;font-size:.82rem;font-weight:600;color:#854d0e">⚠️ Ningún año electivo activo</div>';
        }
        if (!anios.length) {
            container.innerHTML = '<p style="color:var(--muted);font-size:.86rem">No hay años electivos registrados. Crea uno abajo.</p>';
            return;
        }
        container.innerHTML = anios.map(a => {
            const activo = a.activo == 1;
            const nombre = a.descripcion || a.nombre || (a.anio_inicio + '-' + a.anio_fin);
            const mats   = parseInt(a.total_contratos) || 0;
            return `<div style="display:flex;align-items:center;gap:12px;padding:13px 18px;background:var(--surface);border:2px solid ${activo?'var(--primary)':'var(--border)'};border-radius:11px;transition:border .2s">
  <div style="flex:1">
    <div style="font-weight:700;font-size:.92rem;color:var(--text)">${nombre}</div>
    <div style="font-size:.75rem;color:var(--muted)">${a.anio_inicio} – ${a.anio_fin}</div>
  </div>
  <span style="font-size:.78rem;font-weight:700;padding:4px 12px;border-radius:20px;background:#f0fdf4;color:#16a34a;white-space:nowrap">${mats} contrato${mats!==1?'s':''}</span>
  ${activo ? '<span style="font-size:.72rem;font-weight:700;padding:4px 12px;border-radius:20px;background:#dbeafe;color:#1d4ed8;white-space:nowrap">✅ Activo</span>' : ''}
  <button onclick="toggleAnioElectivo(${a.id},${activo?0:1})" style="padding:7px 16px;border-radius:8px;border:1.5px solid ${activo?'#dc2626':'var(--border)'};background:${activo?'#fee2e2':'var(--bg)'};color:${activo?'#dc2626':'var(--text)'};font-size:.8rem;font-weight:700;cursor:pointer;white-space:nowrap">
    ${activo ? '🔒 Desactivar' : '✅ Activar'}
  </button>
</div>`;
        }).join('');
    } catch(e) { if(container) container.innerHTML=`<div style="padding:12px;color:#b91c1c;font-size:.86rem">❌ Error al cargar: ${e.message}</div>`; }
}
async function crearAnioElectivo() {
    // Los inputs en el HTML usan IDs: ae-ini, ae-fin, ae-desc
    const ini  = document.getElementById('ae-ini')?.value  || document.getElementById('anio-inicio')?.value;
    const fin  = document.getElementById('ae-fin')?.value  || document.getElementById('anio-fin')?.value;
    const desc = document.getElementById('ae-desc')?.value || '';
    if (!ini || !fin) { showToast('⚠️ Completa el año de inicio y fin','error'); return; }
    const btn = document.getElementById('btn-ae-crear');
    if (btn) { btn.disabled = true; btn.textContent = '⏳ Creando...'; }
    try {
        const r = await fetch('/GestionPrestamo/api/configuracion.php', {
            method:'POST', headers:{'Content-Type':'application/json'},
            body: JSON.stringify({ action:'crear_anio_electivo', anio_inicio: parseInt(ini), anio_fin: parseInt(fin), descripcion: desc })
        });
        const d = await r.json();
        if (d.success || d.ok) {
            showToast('✅ Año electivo creado','success');
            if (document.getElementById('ae-ini')) document.getElementById('ae-ini').value = '';
            if (document.getElementById('ae-fin')) document.getElementById('ae-fin').value = '';
            if (document.getElementById('ae-desc')) document.getElementById('ae-desc').value = '';
            cargarAniosElectivos();
        } else showToast('❌ '+(d.error||'Error al crear'),'error');
    } catch(e) { showToast('❌ Error: '+e.message,'error'); }
    finally { if (btn) { btn.disabled = false; btn.textContent = '➕ Crear'; } }
}
async function toggleAnioElectivo(id, activo) {
    try {
        const r = await fetch('/GestionPrestamo/api/configuracion.php', {
            method:'POST', headers:{'Content-Type':'application/json'},
            body: JSON.stringify({ action:'toggle_anio_electivo', id, activar: activo === 1 || activo === true })
        });
        const d = await r.json();
        if (d.success || d.ok) { showToast('✅ '+(d.mensaje||d.msg||'Actualizado'),'success'); cargarAniosElectivos(); }
        else showToast('❌ '+(d.error||'Error'),'error');
    } catch(e) { showToast('❌ Error','error'); }
}

// ── Boletines: control de períodos Primaria / Secundaria ─────────
const COLORS_P = {1:'#3b82f6',2:'#8b5cf6',3:'#f59e0b',4:'#10b981'};

// Config en memoria: { pri: [{periodo,fecha_inicio,fecha_fin,forzar_activo},...], sec: [...] }
let _periodosCfg = { pri: [], sec: [] };

async function cargarPeriodosBoletines() {
    try {
        const r = await fetch('/GestionPrestamo/api/configuracion.php?action=get_periodos_activos');
        const d = await r.json();
        const hoy = d.hoy || new Date().toISOString().split('T')[0];

        // API returns detalle[] — split by nivel if present, otherwise apply to both
        const detalle = d.detalle || [];
        const priDet  = d.detalle_pri || detalle;  // fallback: same for both
        const secDet  = d.detalle_sec || detalle;

        _periodosCfg.pri = priDet;
        _periodosCfg.sec = secDet;

        priDet.forEach(p => {
            const el = n => document.getElementById(`ppri${p.periodo}_${n}`);
            if (el('ini')) el('ini').value = p.fecha_inicio || '';
            if (el('fin')) el('fin').value = p.fecha_fin    || '';
            if (el('forzar')) el('forzar').checked = !!p.forzar_activo;
            actualizarPcard('pri', p.periodo, p.activo, p.dias_restantes, p.fecha_inicio, p.fecha_fin, hoy, !!p.forzar_activo);
        });
        secDet.forEach(p => {
            const el = n => document.getElementById(`psec${p.periodo}_${n}`);
            if (el('ini')) el('ini').value = p.fecha_inicio || '';
            if (el('fin')) el('fin').value = p.fecha_fin    || '';
            if (el('forzar')) el('forzar').checked = !!p.forzar_activo;
            actualizarPcard('sec', p.periodo, p.activo, p.dias_restantes, p.fecha_inicio, p.fecha_fin, hoy, !!p.forzar_activo);
        });

        // Inicializar períodos sin datos
        ['pri','sec'].forEach(nivel => {
            const det = nivel === 'pri' ? priDet : secDet;
            [1,2,3,4].forEach(num => {
                if (!det.find(p => p.periodo === num))
                    actualizarPcard(nivel, num, false, null, '', '', hoy, false);
            });
        });

        // Banner global
        const activosPri = priDet.filter(p => p.activo).map(p => `P${p.periodo}`);
        const activosSec = secDet.filter(p => p.activo).map(p => `P${p.periodo}`);
        const banner = document.getElementById('bol-status-banner');
        if (banner) {
            const partes = [];
            if (activosPri.length) partes.push(`🏫 Primaria: <strong>${activosPri.join(', ')}</strong>`);
            if (activosSec.length) partes.push(`💰 Secundaria: <strong>${activosSec.join(', ')}</strong>`);
            banner.innerHTML = partes.length
                ? `<div style="padding:8px 14px;background:#f0fdf4;border:1px solid #86efac;border-radius:8px;font-size:.8rem;color:#15803d">🔓 ${partes.join(' &nbsp;·&nbsp; ')}</div>`
                : `<div style="padding:8px 14px;background:#fef2f2;border:1px solid #fca5a5;border-radius:8px;font-size:.8rem;color:#b91c1c">🔒 Todos los períodos bloqueados</div>`;
        }
    } catch(e) { console.error('Error cargando períodos:', e); }
}

function actualizarPcard(nivel, num, activo, diasRestantes, ini, fin, hoy, forzar = false) {
    const pfx   = `p${nivel}${num}`;
    const card  = document.getElementById(`pcard-${nivel}-${num}`);
    const badge = document.getElementById(`pbadge-${nivel}-${num}`);
    const info  = document.getElementById(`pinfo-${nivel}-${num}`);
    const flabel= document.getElementById(`${pfx}_forzar_label`);
    const color = COLORS_P[num];
    if (!card) return;

    if (forzar) {
        card.style.borderColor = color;
        card.style.background  = color + '0d';
        if (badge) { badge.style.background = color+'20'; badge.style.color = color; badge.textContent = '✏️ Activo (manual)'; }
        if (info)  info.innerHTML = `<span style="color:${color};font-weight:700">✅ Abierto manualmente</span>`;
        if (flabel) flabel.textContent = 'Activo — desmarca para cerrar';
        return;
    }
    if (flabel) flabel.textContent = 'Abre sin depender de fechas';

    if (!ini && !fin) {
        card.style.borderColor = '#e2e8f0'; card.style.background = '#fff';
        if (badge) { badge.style.background='#f1f5f9'; badge.style.color='#94a3b8'; badge.textContent='🔒 Sin configurar'; }
        if (info) info.innerHTML = '<span style="color:#94a3b8">Sin fechas — bloqueado</span>';
        return;
    }
    if (activo) {
        card.style.borderColor = color; card.style.background = color + '0d';
        if (badge) { badge.style.background=color+'20'; badge.style.color=color; badge.textContent = diasRestantes !== null ? `🔓 ${diasRestantes}d` : '🔓 Abierto'; }
        if (info) info.innerHTML = diasRestantes !== null
            ? `<span style="color:${diasRestantes<=3?'#b91c1c':color};font-weight:${diasRestantes<=3?'700':'500'}">Cierra el ${formatFecha(fin)} (${diasRestantes}d)</span>`
            : `<span style="color:${color}">Del ${formatFecha(ini)} al ${formatFecha(fin)}</span>`;
    } else {
        card.style.borderColor = '#e2e8f0'; card.style.background = '#fff';
        const futuro = ini > hoy;
        if (badge) { badge.style.background=futuro?'#eff6ff':'#f1f5f9'; badge.style.color=futuro?'#1d4ed8':'#94a3b8'; badge.textContent=futuro?`🕐 Abre ${formatFecha(ini)}`:'🔒 Cerrado'; }
        if (info) info.innerHTML = futuro
            ? `<span style="color:#1d4ed8">Desde ${formatFecha(ini)} al ${formatFecha(fin)}</span>`
            : `<span style="color:#94a3b8">Cerrado (${formatFecha(ini)}–${formatFecha(fin)})</span>`;
    }
}

function onToggleForzar(nivel, num) {
    const forzar = document.getElementById(`p${nivel}${num}_forzar`)?.checked || false;
    const ini    = document.getElementById(`p${nivel}${num}_ini`)?.value || '';
    const fin    = document.getElementById(`p${nivel}${num}_fin`)?.value || '';
    const hoy    = new Date().toISOString().split('T')[0];
    const porFechas = ini && fin && hoy >= ini && hoy <= fin;
    const activo    = forzar || porFechas;
    const dias = (!forzar && porFechas) ? Math.ceil((new Date(fin)-new Date(hoy))/86400000) : null;
    actualizarPcard(nivel, num, activo, dias, ini, fin, hoy, forzar);
    guardarPeriodosBoletines(true);
}

function onFechaChange(nivel, num) {
    const ini = document.getElementById(`p${nivel}${num}_ini`)?.value || '';
    const fin = document.getElementById(`p${nivel}${num}_fin`)?.value || '';
    const forzar = document.getElementById(`p${nivel}${num}_forzar`)?.checked || false;
    const hoy = new Date().toISOString().split('T')[0];
    const porFechas = ini && fin && hoy >= ini && hoy <= fin;
    const activo = forzar || porFechas;
    const dias = (!forzar && porFechas) ? Math.ceil((new Date(fin)-new Date(hoy))/86400000) : null;
    actualizarPcard(nivel, num, activo, dias, ini, fin, hoy, forzar);
}

function formatFecha(ymd) {
    if (!ymd) return '';
    const [y,m,d] = ymd.split('-');
    const meses = ['','Ene','Feb','Mar','Abr','May','Jun','Jul','Ago','Sep','Oct','Nov','Dic'];
    return `${parseInt(d)} ${meses[parseInt(m)]}`;
}

async function guardarPeriodosBoletines(silencioso = false) {
    // Build config for both levels
    const buildNivel = nivel => [1,2,3,4].map(p => ({
        periodo:       p,
        fecha_inicio:  document.getElementById(`p${nivel}${p}_ini`)?.value    || '',
        fecha_fin:     document.getElementById(`p${nivel}${p}_fin`)?.value     || '',
        forzar_activo: document.getElementById(`p${nivel}${p}_forzar`)?.checked || false,
    }));

    const fd = new FormData();
    fd.append('seccion', 'boletines');
    fd.append('periodos_config',     JSON.stringify(buildNivel('pri')));  // backward compat
    fd.append('periodos_config_pri', JSON.stringify(buildNivel('pri')));
    fd.append('periodos_config_sec', JSON.stringify(buildNivel('sec')));

    const msgEl = document.getElementById('bol-msg');
    if (!silencioso) { msgEl.style.display=''; msgEl.innerHTML='⏳ Guardando…'; msgEl.style.color='#64748b'; }

    try {
        const r = await fetch('/GestionPrestamo/api/configuracion.php', { method:'POST', body:fd });
        const d = await r.json();
        if (d.success) {
            silencioso ? showToast('✅ Período actualizado','success') : (msgEl.innerHTML='✅ '+d.mensaje, msgEl.style.color='#15803d');
            cargarPeriodosBoletines();
        } else {
            const err = '❌ '+(d.error||'Error');
            silencioso ? showToast(err,'error') : (msgEl.innerHTML=err, msgEl.style.color='#b91c1c');
        }
    } catch { showToast('❌ Error de conexión','error'); }
}

async function limpiarTodosPeriodos() {
    if (!confirm('¿Cerrar todos los períodos de Primaria y Secundaria?')) return;
    ['pri','sec'].forEach(nivel => [1,2,3,4].forEach(p => {
        const el = n => document.getElementById(`p${nivel}${p}_${n}`);
        if (el('ini')) el('ini').value = '';
        if (el('fin')) el('fin').value = '';
        if (el('forzar')) el('forzar').checked = false;
        actualizarPcard(nivel, p, false, null, '', '', new Date().toISOString().split('T')[0], false);
    }));
    await guardarPeriodosBoletines();
}


// ── Bloqueo de Campos del Reporte ─────────────────────────────

/**
 * Carga desde el servidor la configuración guardada de visibilidad de campos
 * y la aplica a los botones Visible/Bloqueado/Oculto.
 * También carga los valores de firma maestro, firma gerente, etc.
 */
async function cargarBoletinDefaults() {
    try {
        const url = '/GestionPrestamo/api/configuracion.php?action=get_boletin_defaults' +
            (typeof ID_CENTRO !== 'undefined' && ID_CENTRO > 0 ? '&id_empresa=' + ID_CENTRO : '');
        const r = await fetch(url);
        const d = await r.json();
        if (d.error) throw new Error(d.error);

        // Aplicar campos_bloqueados a los botones
        _bcAplicarDesde(d.campos_bloqueados || []);

        // Rellenar campos de texto / checkboxes del encabezado
        const set = (id, val) => { const el = document.getElementById(id); if (el) el.value = val || ''; };
        const chk = (id, val) => { const el = document.getElementById(id); if (el) el.checked = val !== false && val !== 0; };
        set('bd-firma-maestro',  d.firma_maestro  || '');
        set('bd-firma-gerente', d.firma_gerente || '');
        set('bd-anio-escolar',   d.anio_escolar   || '');
        chk('bd-mostrar_asist',  d.mostrar_asist);
        chk('bd-mostrar_obs',    d.mostrar_obs);
        chk('bd-mostrar_firmas', d.mostrar_firmas);
        chk('bd-mostrar_sit',    d.mostrar_sit);
    } catch(e) {
        console.warn('cargarBoletinDefaults error:', e.message);
    }
}

const BC_GRUPOS = {"encabezado": ["asesor", "asignatura", "anio_escolar", "seccion", "numero_orden", "tanda", "codigo_centro", "distrito", "region", "provincia_municipio"], "cal_sec": ["cal_cf", "cal_cec", "cal_ceex", "cal_ce", "cal_pc", "sit_area"], "cal_pri": ["pri_g1", "pri_g2", "pri_g3", "pri_c1", "pri_c2", "pri_c3", "cf_area", "cf_rec", "cf_rec_esp"], "asistencia": ["asistencia_p1", "ausencia_p1", "asistencia_p2", "ausencia_p2", "asistencia_p3", "ausencia_p3", "asistencia_p4", "ausencia_p4", "asistencia_anual_pct", "ausencia_anual_pct"], "observaciones": ["obs_p1", "obs_p2", "obs_p3", "obs_p4", "observaciones", "info_avance"], "firmas": ["firma_p1", "firma_p2", "firma_p3", "firma_p4", "firma_maestro", "firma_gerente"], "situacion": ["condicion_final", "condicion_final_desc"]};
let _bcEstados = {};  // campo -> 'visible'|'bloqueado'|'oculto'

function toggleBcGroup(header) {
    const body  = header.nextElementSibling;
    const arrow = header.querySelector('.bc-arrow');
    const open  = body.style.display !== 'none';
    body.style.display = open ? 'none' : 'block';
    if (arrow) { arrow.textContent = open ? '▼' : '▲'; arrow.style.transform = open ? '' : 'rotate(180deg)'; }
}

function bcExpandAll()   { document.querySelectorAll('.bc-group-header').forEach(h => { h.nextElementSibling.style.display = 'block';  const a=h.querySelector('.bc-arrow'); if(a){a.textContent='▲';a.style.transform='rotate(180deg)';} }); }
function bcCollapseAll() { document.querySelectorAll('.bc-group-header').forEach(h => { h.nextElementSibling.style.display = 'none';   const a=h.querySelector('.bc-arrow'); if(a){a.textContent='▼';a.style.transform='';} }); }

function setBcEstado(campo, estado) {
    _bcEstados[campo] = estado;
    const row = document.querySelector(`.bc-row[data-key="${campo}"]`);
    if (row) {
        row.querySelectorAll('.bc-btn').forEach(btn => {
            const active = btn.classList.contains(`bc-${estado}`);
            btn.style.opacity   = active ? '1'    : '0.3';
            btn.style.transform = active ? 'scale(1.05)' : 'scale(1)';
            btn.style.boxShadow = active ? '0 2px 8px rgba(0,0,0,.15)' : 'none';
            btn.style.fontWeight = active ? '800' : '600';
        });
    }
    _bcRefreshBadges();
}

function _bcRefreshBadges() {
    for (const [gid, keys] of Object.entries(BC_GRUPOS)) {
        const bloq   = keys.filter(k => _bcEstados[k] === 'bloqueado').length;
        const oculto = keys.filter(k => _bcEstados[k] === 'oculto').length;
        const badge  = document.getElementById(`bc-badge-${gid}`);
        if (!badge) continue;
        const parts = [];
        if (bloq)   parts.push(`🔒 ${bloq} bloq.`);
        if (oculto) parts.push(`🚫 ${oculto} oculto${oculto>1?'s':''}`);
        badge.textContent = parts.length ? parts.join(' · ') : '✅ Todo visible';
        badge.style.color = oculto ? '#b91c1c' : bloq ? '#b45309' : '#94a3b8';
    }
}

function _bcAplicarDesde(arr) {
    // Reset all to visible first
    for (const keys of Object.values(BC_GRUPOS)) {
        keys.forEach(k => { _bcEstados[k] = 'visible'; });
    }
    document.querySelectorAll('.bc-row').forEach(row => {
        row.querySelectorAll('.bc-btn').forEach(btn => {
            const active = btn.classList.contains('bc-visible');
            btn.style.opacity = active ? '1' : '0.3';
            btn.style.transform = active ? 'scale(1.05)' : 'scale(1)';
            btn.style.boxShadow = active ? '0 2px 8px rgba(0,0,0,.15)' : 'none';
            btn.style.fontWeight = active ? '800' : '600';
        });
    });
    // Apply saved states
    (arr || []).forEach(entry => {
        const idx = entry.lastIndexOf(':');
        if (idx < 1) return;
        const campo = entry.slice(0, idx);
        const estado = entry.slice(idx + 1);
        if (['bloqueado','oculto'].includes(estado)) setBcEstado(campo, estado);
    });
    _bcRefreshBadges();
}

function resetBcCampos() {
    _bcAplicarDesde([]);
    showToast('↺ Todos los campos restablecidos a Visible', '');
}

// Alias — el bloque 2 (datos predeterminados) y el bloque 3 (campos) comparten el mismo endpoint
async function guardarBoletinDefaults() { await guardarBcCampos(); }

async function guardarBcCampos() {
    const camposBloqueados = Object.entries(_bcEstados)
        .filter(([,e]) => e !== 'visible')
        .map(([c,e]) => `${c}:${e}`);

    const btn    = document.getElementById('btn-bc-guardar');
    const info   = document.getElementById('bc-save-info');
    const orig   = btn ? btn.textContent : '';
    if (btn) { btn.disabled = true; btn.textContent = '⏳ Guardando…'; }
    if (info) info.textContent = '';

    try {
        const g  = id => document.getElementById(id);
        const gc = id => g(id)?.checked ?? true;
        const gv = id => g(id)?.value?.trim() || '';
        const payload = {
            action:           'guardar_boletin_defaults',
            firma_maestro:    gv('bd-firma-maestro'),
            firma_gerente:   gv('bd-firma-gerente'),
            anio_escolar:     gv('bd-anio-escolar'),
            mostrar_asist:    gc('bd-mostrar_asist'),
            mostrar_obs:      gc('bd-mostrar_obs'),
            mostrar_firmas:   gc('bd-mostrar_firmas'),
            mostrar_sit:      gc('bd-mostrar_sit'),
            campos_bloqueados: camposBloqueados,
        };
        const r = await fetch('/GestionPrestamo/api/configuracion.php', {
            method: 'POST', headers: {'Content-Type':'application/json'},
            body: JSON.stringify(payload)
        });
        const d = await r.json();
        if (d.success) {
            showToast('✅ Configuración de campos guardada', 'success');
            if (info) { info.textContent = '✅ Guardado'; info.style.color='#15803d'; }
        } else throw new Error(d.error || 'Error');
    } catch(e) {
        showToast('❌ ' + e.message, 'error');
        if (info) { info.textContent = '❌ ' + e.message; info.style.color='#b91c1c'; }
    } finally {
        if (btn) { btn.disabled = false; btn.textContent = orig; }
    }
}



// ── Soporte tab ──────────────────────────────────────────────────────────────
let _debugMode = false;

async function cargarSoporteConfig() {
  try {
    const r = await fetch('/GestionPrestamo/api/configuracion.php?action=get_soporte_config');
    const d = await r.json();
    if (d.error) { console.warn('Soporte config:', d.error); return; }
    _debugMode = !!d.debug_errors;
    _actualizarToggleDebug();
  } catch(e) { console.warn('Error cargando soporte config:', e); }
}

function _actualizarToggleDebug() {
  const track  = document.getElementById('debug-track');
  const label  = document.getElementById('debug-label');
  const badge  = document.getElementById('debug-estado-badge');
  if (!track) return;
  if (_debugMode) {
    track.classList.add('on');
    if (label) label.textContent = 'Modo debug ACTIVO — errores técnicos visibles';
    if (badge) {
      badge.style.display = 'inline-block';
      badge.style.background = '#fee2e2';
      badge.style.color = '#dc2626';
      badge.style.border = '1px solid #fca5a5';
      badge.textContent = '🐛 DEBUG ON';
    }
  } else {
    track.classList.remove('on');
    if (label) label.textContent = 'Modo debug inactivo — mensajes amigables';
    if (badge) {
      badge.style.display = 'inline-block';
      badge.style.background = '#f0fdf4';
      badge.style.color = '#16a34a';
      badge.style.border = '1px solid #86efac';
      badge.textContent = '✅ PRODUCCIÓN';
    }
  }
}

async function toggleDebug() {
  _debugMode = !_debugMode;
  _actualizarToggleDebug();
  const msg = document.getElementById('debug-save-msg');
  try {
    const r = await fetch('/GestionPrestamo/api/configuracion.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ action: 'guardar_soporte', debug_errors: _debugMode })
    });
    const d = await r.json();
    if (d.success) {
      if (msg) {
        msg.style.display = 'block';
        msg.style.color = '#16a34a';
        msg.textContent = '✅ ' + d.mensaje;
        setTimeout(() => { if(msg) msg.style.display='none'; }, 3000);
      }
      showToast(d.mensaje, 'ok');
    } else {
      // Revertir si hay error
      _debugMode = !_debugMode;
      _actualizarToggleDebug();
      showToast(d.error || 'Error al guardar', 'err');
    }
  } catch(e) {
    _debugMode = !_debugMode;
    _actualizarToggleDebug();
    showToast('Error de conexión', 'err');
  }
}

// Cargar al hacer switchTab a soporte
const _origSwitchTab = switchTab;

// Auto-abrir tab por parámetro URL: ?tab=notificaciones
(function() {
    const urlTab = new URLSearchParams(window.location.search).get('tab');
    if (urlTab) {
        // Esperar a que el DOM esté listo
        const tryOpen = () => {
            const btn = document.querySelector(`.tab[onclick*="switchTab('${urlTab}'"]`);
            if (btn) {
                switchTab(urlTab, btn);
            }
        };
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', tryOpen);
        } else {
            tryOpen();
        }
    }
})();


// ── Monedas: selección visual + preview ──────────────────────────────────────
const MONEDA_SIMBOLOS = {
    'DOP': 'RD$', 'USD': 'US$', 'EUR': '€', 'GTQ': 'Q',
    'HNL': 'L', 'CRC': '₡', 'MXN': 'MX$', 'COP': 'COP$'
};

function selMoneda(label, code) {
    // Visual update
    document.querySelectorAll('#sec-moneda label[onclick]').forEach(l => {
        l.style.borderColor = 'var(--border)';
        l.style.background = 'var(--bg)';
    });
    label.style.borderColor = 'var(--primary)';
    label.style.background = '#eff6ff';
    label.querySelector('input[type=radio]').checked = true;
    actualizarPreviewMoneda(code);
}

function actualizarPreviewMoneda(code) {
    const sep   = document.querySelector('[name="sep_miles"]')?.value || ',';
    const dec   = parseInt(document.querySelector('[name="decimales"]')?.value || '2');
    const simb  = MONEDA_SIMBOLOS[code] || code;
    const fmt   = (n) => {
        const s = n.toFixed(dec);
        const [i, d] = s.split('.');
        const miles = i.replace(/\B(?=(\d{3})+(?!\d))/g, sep);
        return simb + ' ' + (dec > 0 ? miles + '.' + d : miles);
    };
    const ps = document.getElementById('prev-small');
    const pm = document.getElementById('prev-med');
    const pb = document.getElementById('prev-big');
    if (ps) ps.textContent = fmt(1250);
    if (pm) pm.textContent = fmt(125000);
    if (pb) pb.textContent = fmt(2500000);
}

document.addEventListener('DOMContentLoaded', function() {
    const checkedMon = document.querySelector('#sec-moneda input[name="moneda"]:checked');
    if (checkedMon) actualizarPreviewMoneda(checkedMon.value);

    // Live preview for agradecimiento
    const liveFields = {
        'agrad_titulo':   'prev-titulo',
        'agrad_recibo':   'prev-msg',
        'agrad_firma':    'prev-firma',
        'agrad_firmante': 'prev-firmante',
    };
    for (const [name, id] of Object.entries(liveFields)) {
        const el = document.querySelector(`[name="${name}"]`);
        const pv = document.getElementById(id);
        if (el && pv) {
            el.addEventListener('input', () => { pv.textContent = el.value || el.placeholder; });
        }
    }
    // sep_miles y decimales también actualizan preview
    ['sep_miles','decimales'].forEach(n => {
        const el = document.querySelector(`[name="${n}"]`);
        if (el) el.addEventListener('change', () => {
            const checkedMon2 = document.querySelector('#sec-moneda input[name="moneda"]:checked');
            if (checkedMon2) actualizarPreviewMoneda(checkedMon2.value);
        });
    });
});

</script>
</body>
</html>