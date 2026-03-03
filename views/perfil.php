<?php
// views/perfil.php — Editar Perfil | GestionPrestamo
require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/../config/db.php';

$sesion = verificarSesion();

$nombreRol = match($sesion['rol']) {
    'superadmin' => 'Superadministrador', 'admin' => 'Administrador',
    'gerente'   => 'Director',           'supervisor' => 'Coordinador',
    'cajero' => 'Secretaría',         'asesor' => 'Asesor',
    'auditor' => 'Supervisor',         'cliente' => 'Garante/Padre',
    'consultor' => 'Cliente',         default => ucfirst($sesion['rol']),
};

$iniciales = implode('', array_map(
    fn($w) => strtoupper($w[0]),
    array_slice(explode(' ', trim($sesion['nombre'])), 0, 2)
));

// ── Pre-cargar datos del perfil desde PHP (evita campos vacíos al cargar) ───
$_perfilData = ['nombre'=>'','apellido'=>'','cedula'=>'','fecha_nacimiento'=>'',
                'genero'=>'','estado_civil'=>'','nacionalidad'=>'','username'=>'',
                'telefono'=>'','email'=>'','whatsapp'=>'','direccion'=>''];
try {
    $_db = getDB();
    $_st = $_db->prepare("
        SELECT p.nombre, p.apellido, p.cedula, p.fecha_nacimiento,
               p.genero, p.estado_civil, p.nacionalidad,
               u.username
        FROM   personas p
        JOIN   usuarios u ON u.id_persona = p.id
        WHERE  p.id = ? AND u.id = ?
        LIMIT  1
    ");
    $_st->execute([$sesion['persona_id'], $sesion['usuario_id']]);
    $_row = $_st->fetch();
    if ($_row) {
        $_perfilData = array_merge($_perfilData, $_row);
    }
    // Contactos
    $_stC = $_db->prepare("SELECT tipo_contacto, valor FROM contactos_persona WHERE id_persona = ? ORDER BY id");
    $_stC->execute([$sesion['persona_id']]);
    foreach ($_stC->fetchAll() as $_c) {
        $_tipo = strtolower($_c['tipo_contacto']);
        if ($_tipo === 'telefono')   $_perfilData['telefono']  = $_c['valor'];
        elseif ($_tipo === 'email')  $_perfilData['email']     = $_c['valor'];
        elseif ($_tipo === 'whatsapp') $_perfilData['whatsapp']= $_c['valor'];
        elseif ($_tipo === 'dirección' || $_tipo === 'direccion') $_perfilData['direccion'] = $_c['valor'];
    }
} catch (\Throwable) {}

function pv(string $key): string {
    global $_perfilData;
    return htmlspecialchars($_perfilData[$key] ?? '');
}
function psel(string $key, string $val): string {
    global $_perfilData;
    return (($_perfilData[$key] ?? '') === $val) ? ' selected' : '';
}
$activePage = 'perfil';
$pageTitle  = 'Mi Perfil';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <?php require_once __DIR__ . '/../php/partials/head.php'; ?>
    <style>
        .topbar { height:56px; background:var(--surface); border-bottom:1px solid var(--border);
                  display:flex; align-items:center; padding:0 24px; gap:12px;
                  position:sticky; top:0; z-index:100; }
        .breadcrumb { font-size:.78rem; color:var(--muted); display:flex; align-items:center; gap:6px; }
        .breadcrumb a { color:var(--muted); text-decoration:none; }
        .breadcrumb a:hover { color:var(--primary); }
        .menu-toggle { display:none; width:36px; height:36px; border-radius:8px;
                       border:1.5px solid var(--border); background:none; cursor:pointer;
                       font-size:1.1rem; align-items:center; justify-content:center; }
        @media(max-width:768px){ .menu-toggle { display:flex; } }

        .p-body { padding:24px 28px; max-width:860px; }
        @media(max-width:768px){ .p-body { padding:12px; } }

        /* Hero avatar */
        .hero { background:var(--surface); border:1.5px solid var(--border); border-radius:16px;
                box-shadow:var(--shadow); padding:28px 24px; margin-bottom:18px;
                display:flex; align-items:center; gap:26px; flex-wrap:wrap; }
        .av-wrap { position:relative; flex-shrink:0; cursor:pointer; }
        .av-circle { width:110px; height:110px; border-radius:50%;
                     background:linear-gradient(135deg,var(--primary),#2563eb);
                     color:#fff; font-size:2.2rem; font-weight:800;
                     display:flex; align-items:center; justify-content:center;
                     border:4px solid #e0e7ff; }
        .av-photo { width:110px; height:110px; border-radius:50%; object-fit:cover;
                    border:4px solid var(--primary);
                    box-shadow: 0 0 0 4px rgba(29,78,216,.12); }
        .av-overlay {
            position:absolute; inset:0; border-radius:50%;
            background:rgba(29,78,216,.55);
            display:flex; flex-direction:column; align-items:center; justify-content:center;
            gap:3px; opacity:0; transition:opacity .18s; pointer-events:none;
        }
        .av-overlay span { font-size:1.4rem; line-height:1; }
        .av-overlay small { font-size:.62rem; color:#fff; font-weight:700; letter-spacing:.03em; }
        .av-wrap:hover .av-overlay { opacity:1; }
        .av-badge { position:absolute; bottom:4px; right:4px; width:30px; height:30px;
                    border-radius:50%; background:var(--primary); color:#fff;
                    border:2.5px solid #fff; cursor:pointer; display:flex;
                    align-items:center; justify-content:center; font-size:.8rem;
                    transition:transform .12s, box-shadow .12s;
                    box-shadow:0 2px 10px rgba(29,78,216,.45); }
        .av-badge:hover { transform:scale(1.18); box-shadow:0 4px 14px rgba(29,78,216,.55); }
        .hero-info { flex:1; min-width:0; }
        .hero-name { font-size:1.25rem; font-weight:800; margin-bottom:3px;
                     white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
        .hero-rol  { font-size:.8rem; color:var(--muted); margin-bottom:14px;
                     display:flex; align-items:center; gap:6px; }
        .rol-badge { display:inline-flex; align-items:center; gap:4px;
                     background:#eff6ff; color:var(--primary);
                     border:1px solid #bfdbfe; border-radius:20px;
                     padding:2px 10px; font-size:.72rem; font-weight:700; }
        .hero-btns { display:flex; gap:8px; flex-wrap:wrap; }
        .fbtn { display:inline-flex; align-items:center; gap:7px;
                padding:9px 18px; border-radius:9px;
                border:1.5px solid var(--primary); background:var(--primary);
                font-family:inherit; font-size:.82rem; font-weight:700;
                cursor:pointer; color:#fff; transition:all .15s;
                box-shadow:0 2px 10px rgba(29,78,216,.25); }
        .fbtn:hover { background:#1d4ed8; border-color:#1d4ed8; transform:translateY(-1px);
                      box-shadow:0 4px 16px rgba(29,78,216,.35); }
        .fbtn.del { background:var(--bg); color:#dc2626; border-color:#fca5a5; box-shadow:none; }
        .fbtn.del:hover { border-color:#dc2626; background:#fff5f5; transform:translateY(-1px); }

        /* Tabs */
        .tabs { display:flex; gap:4px; background:var(--bg); border:1px solid var(--border);
                border-radius:10px; padding:4px; margin-bottom:16px;
                overflow-x:auto; -webkit-overflow-scrolling:touch; }
        .tab  { padding:8px 16px; border-radius:7px; border:none; background:none;
                cursor:pointer; font-family:inherit; font-size:.8rem; font-weight:700;
                color:var(--muted); transition:all .12s; white-space:nowrap; flex-shrink:0; }
        .tab.active { background:var(--surface); color:var(--primary);
                      box-shadow:0 1px 6px rgba(29,78,216,.14); }
        .tp { display:none; }
        .tp.active { display:block; animation:fadeUp .15s ease; }
        @keyframes fadeUp { from{opacity:0;transform:translateY(5px)} to{opacity:1;transform:none} }

        /* Card */
        .card { background:var(--surface); border:1.5px solid var(--border);
                border-radius:14px; box-shadow:var(--shadow); }
        .card-hdr { padding:16px 22px 14px; border-bottom:1px solid var(--border);
                    display:flex; align-items:center; gap:10px; }
        .card-ico { width:32px; height:32px; border-radius:9px;
                    background:linear-gradient(135deg,var(--primary),#2563eb);
                    color:#fff; display:flex; align-items:center; justify-content:center; font-size:.9rem; }
        .card-ttl { font-size:.88rem; font-weight:800; }
        .card-bdy { padding:20px 22px; }

        /* Form */
        .fg { display:grid; grid-template-columns:1fr 1fr; gap:14px; }
        .ff { grid-column:1/-1; }
        @media(max-width:540px){ .fg{ grid-template-columns:1fr; } .ff{ grid-column:1; } }
        .fld { display:flex; flex-direction:column; gap:5px; }
        .fld label { font-size:.75rem; font-weight:700; }
        .req { color:#dc2626; }
        .fld input, .fld select, .fld textarea {
            padding:9px 12px; border:1.5px solid var(--border); border-radius:9px;
            font-family:inherit; font-size:.84rem; color:var(--text); background:#fff;
            outline:none; width:100%; box-sizing:border-box; transition:border-color .12s; }
        .fld input:focus, .fld select:focus, .fld textarea:focus {
            border-color:var(--primary); box-shadow:0 0 0 3px rgba(29,78,216,.07); }
        .fld input[readonly] { background:var(--bg); color:var(--muted); cursor:default; }
        .hint { font-size:.7rem; color:var(--muted); }
        .form-foot { display:flex; justify-content:flex-end; gap:10px;
                     padding-top:16px; border-top:1px solid var(--border); margin-top:4px; }

        /* PW checks */
        .pwc { display:flex; flex-direction:column; gap:3px; margin-top:5px; }
        .pwc span { font-size:.71rem; color:var(--muted); display:flex; align-items:center; gap:5px; }
        .pwc span.ok { color:#16a34a; }
        .pwc span::before { content:'○'; font-size:.6rem; }
        .pwc span.ok::before { content:'●'; }

        /* Alert */
        .alert { padding:10px 14px; border-radius:9px; font-size:.8rem; font-weight:600;
                 display:none; align-items:center; gap:8px; margin-bottom:14px; }
        .alert-ok  { background:#dcfce7; color:#166534; border:1px solid #bbf7d0; }
        .alert-err { background:#fee2e2; color:#991b1b; border:1px solid #fecaca; }

        /* Modal */
        .mbd { position:fixed; inset:0; background:rgba(0,0,0,.48); z-index:300;
               display:none; align-items:center; justify-content:center; padding:16px; }
        .mbd.open { display:flex; animation:mf .15s ease; }
        @keyframes mf { from{opacity:0} to{opacity:1} }
        .modal { background:var(--surface); border-radius:16px; width:100%; max-width:420px;
                 box-shadow:0 20px 60px rgba(0,0,0,.22); }
        .mhdr { display:flex; align-items:center; justify-content:space-between;
                padding:16px 20px 14px; border-bottom:1px solid var(--border); }
        .mhdr span { font-size:.9rem; font-weight:800; }
        .mcls { width:28px; height:28px; border-radius:7px; border:1.5px solid var(--border);
                background:none; cursor:pointer; font-size:.95rem; display:flex;
                align-items:center; justify-content:center; transition:all .12s; }
        .mcls:hover { background:#fee2e2; border-color:#fca5a5; }
        .mbdy { padding:20px; }
        .mftr { padding:14px 20px; border-top:1px solid var(--border);
                display:flex; justify-content:flex-end; gap:10px; }

        /* Dropzone */
        .dz { border:2px dashed var(--border); border-radius:12px; padding:32px 16px;
              text-align:center; cursor:pointer; transition:all .15s; }
        .dz:hover, .dz.on { border-color:var(--primary); background:#eff6ff; }
        .dz-ico  { font-size:2.4rem; margin-bottom:8px; }
        .dz-lbl  { font-size:.85rem; font-weight:700; margin-bottom:3px; }
        .dz-hint { font-size:.73rem; color:var(--muted); }
        .dz-prev { display:none; text-align:center; margin-top:8px; position:relative; }
        .dz-prev img { width:120px; height:120px; border-radius:50%; object-fit:cover;
                       border:4px solid var(--primary);
                       box-shadow:0 0 0 4px rgba(29,78,216,.12);
                       display:block; margin:0 auto 8px; }
        .dz-rm { position:absolute; top:0; right:calc(50% - 72px); width:24px; height:24px;
                 border-radius:50%; background:#dc2626; color:#fff; border:none; cursor:pointer;
                 font-size:.75rem; display:flex; align-items:center; justify-content:center;
                 box-shadow:0 2px 6px rgba(220,38,38,.4); }
        .dz-fn { font-size:.73rem; color:var(--muted); }
        .dz-quality { font-size:.7rem; color:#16a34a; font-weight:600; margin-top:4px; }
    </style>
</head>
<body>
<div class="page-wrapper">
<?php require_once __DIR__ . '/../php/partials/sidebar.php'; ?>

<div class="main-content">
    <div class="topbar">
        <button class="menu-toggle" onclick="toggleSidebar()">☰</button>
        <nav class="breadcrumb">
            <a href="/GestionPrestamo/index.php">Inicio</a>
            <span>›</span><span>Mi Perfil</span>
        </nav>
    </div>

    <div class="p-body">

        <!-- ── Banner con avatar ── -->
        <div class="hero">
            <div class="av-wrap" onclick="abrirModal()" title="Cambiar foto">
                <?php if (!empty($sesion['foto_path'])): ?>
                    <img id="avPhoto"
                         src="/GestionPrestamo/uploads/fotos/<?= htmlspecialchars($sesion['foto_path']) ?>"
                         alt="Foto" class="av-photo">
                    <div id="avCircle" class="av-circle" style="display:none"><?= htmlspecialchars($iniciales) ?></div>
                <?php else: ?>
                    <div id="avCircle" class="av-circle"><?= htmlspecialchars($iniciales) ?></div>
                    <img id="avPhoto" src="" alt="" class="av-photo" style="display:none">
                <?php endif; ?>
                <div class="av-overlay">
                    <span>📷</span>
                    <small>CAMBIAR</small>
                </div>
                <button class="av-badge" onclick="event.stopPropagation();abrirModal()" title="Cambiar foto">📷</button>
            </div>
            <div class="hero-info">
                <div class="hero-name" id="heroNombre"><?= htmlspecialchars($sesion['nombre']) ?></div>
                <div class="hero-rol">
                    <span class="rol-badge">💰 <?= htmlspecialchars($nombreRol) ?></span>
                </div>
                <div class="hero-btns">
                    <button class="fbtn" onclick="abrirModal()">📷 Cambiar foto</button>
                    <button class="fbtn del" id="btnDel" onclick="eliminarFoto()"
                            <?= empty($sesion['foto_path']) ? 'style="display:none"' : '' ?>>
                        🗑️ Quitar foto
                    </button>
                </div>
            </div>
        </div>

        <!-- ── Tabs ── -->
        <div class="tabs">
            <button class="tab active" data-tab="datos"     onclick="st('datos')">👤 Datos Personales</button>
            <button class="tab"        data-tab="contacto"  onclick="st('contacto')">📞 Contacto</button>
            <button class="tab"        data-tab="seguridad" onclick="st('seguridad')">🔐 Seguridad</button>
        </div>

        <!-- ── Tab Datos ── -->
        <div class="tp active card" id="tp-datos">
            <div class="card-hdr"><div class="card-ico">👤</div><span class="card-ttl">Información Personal</span></div>
            <div class="card-bdy">
                <div id="al-datos" class="alert"></div>
                <form id="fDatos">
                    <div class="fg">
                        <div class="fld"><label>Nombre <span class="req">*</span></label>
                            <input type="text" id="iNombre" required placeholder="Nombre" value="<?= pv('nombre') ?>"></div>
                        <div class="fld"><label>Apellido <span class="req">*</span></label>
                            <input type="text" id="iApellido" required placeholder="Apellido" value="<?= pv('apellido') ?>"></div>
                        <div class="fld"><label>Cédula</label>
                            <input type="text" id="iCedula" placeholder="000-0000000-0" value="<?= pv('cedula') ?>"></div>
                        <div class="fld"><label>Fecha de Nacimiento</label>
                            <input type="date" id="iFnac" value="<?= pv('fecha_nacimiento') ?>"></div>
                        <div class="fld"><label>Género</label>
                            <select id="iGenero" data-no-search>
                                <option value="">— Seleccionar —</option>
                                <option value="Masculino"<?= psel('genero','Masculino') ?>>Masculino</option>
                                <option value="Femenino"<?= psel('genero','Femenino') ?>>Femenino</option>
                                <option value="Otro"<?= psel('genero','Otro') ?>>Otro</option>
                            </select></div>
                        <div class="fld"><label>Estado Civil</label>
                            <select id="iEstado" data-no-search>
                                <option value="">— Seleccionar —</option>
                                <option value="Soltero/a"<?= psel('estado_civil','Soltero/a') ?>>Soltero/a</option>
                                <option value="Casado/a"<?= psel('estado_civil','Casado/a') ?>>Casado/a</option>
                                <option value="Divorciado/a"<?= psel('estado_civil','Divorciado/a') ?>>Divorciado/a</option>
                                <option value="Viudo/a"<?= psel('estado_civil','Viudo/a') ?>>Viudo/a</option>
                                <option value="Unión Libre"<?= psel('estado_civil','Unión Libre') ?>>Unión Libre</option>
                            </select></div>
                        <div class="fld ff"><label>Nacionalidad</label>
                            <input type="text" id="iNac" placeholder="Dominicana" value="<?= pv('nacionalidad') ?>"></div>
                        <div class="fld"><label>Usuario</label>
                            <input type="text" id="iUser" readonly value="<?= pv('username') ?>">
                            <span class="hint">No se puede cambiar desde aquí.</span></div>
                        <div class="fld"><label>Rol</label>
                            <input type="text" value="<?= htmlspecialchars($nombreRol) ?>" readonly></div>
                    </div>
                    <div class="form-foot">
                        <button type="button" class="btn btn-ghost" onclick="cargar()">↩ Restablecer</button>
                        <button type="submit" class="btn btn-primary" id="bDatos">💾 Guardar cambios</button>
                    </div>
                </form>
            </div>
        </div>

        <!-- ── Tab Contacto ── -->
        <div class="tp card" id="tp-contacto">
            <div class="card-hdr"><div class="card-ico">📞</div><span class="card-ttl">Información de Contacto</span></div>
            <div class="card-bdy">
                <div id="al-contacto" class="alert"></div>
                <form id="fContacto">
                    <div class="fg">
                        <div class="fld"><label>Teléfono</label>
                            <input type="tel" id="iTel" placeholder="809-000-0000" value="<?= pv('telefono') ?>"></div>
                        <div class="fld"><label>WhatsApp</label>
                            <input type="tel" id="iWa" placeholder="809-000-0000" value="<?= pv('whatsapp') ?>"></div>
                        <div class="fld ff"><label>Correo Electrónico</label>
                            <input type="email" id="iEmail" placeholder="correo@ejemplo.com" value="<?= pv('email') ?>"></div>
                        <div class="fld ff"><label>Dirección</label>
                            <textarea id="iDir" rows="2" placeholder="Calle, sector, ciudad..."><?= pv('direccion') ?></textarea></div>
                    </div>
                    <div class="form-foot">
                        <button type="button" class="btn btn-ghost" onclick="cargar()">↩ Restablecer</button>
                        <button type="submit" class="btn btn-primary" id="bContacto">💾 Guardar contacto</button>
                    </div>
                </form>
            </div>
        </div>

        <!-- ── Tab Seguridad ── -->
        <div class="tp card" id="tp-seguridad">
            <div class="card-hdr"><div class="card-ico">🔐</div><span class="card-ttl">Cambiar Contraseña</span></div>
            <div class="card-bdy">
                <div id="al-seg" class="alert"></div>
                <form id="fSeg" autocomplete="off">
                    <div class="fg">
                        <div class="fld ff"><label>Contraseña Actual <span class="req">*</span></label>
                            <input type="password" id="iPwAct" autocomplete="current-password" placeholder="••••••••"></div>
                        <div class="fld"><label>Nueva Contraseña <span class="req">*</span></label>
                            <input type="password" id="iPwNew" autocomplete="new-password" placeholder="••••••••">
                            <div class="pwc">
                                <span id="r1">Mínimo 8 caracteres</span>
                                <span id="r2">Una letra mayúscula</span>
                                <span id="r3">Un número</span>
                            </div></div>
                        <div class="fld"><label>Confirmar Nueva Contraseña <span class="req">*</span></label>
                            <input type="password" id="iPwCfm" autocomplete="new-password" placeholder="••••••••"></div>
                    </div>
                    <div class="form-foot">
                        <button type="reset" class="btn btn-ghost">✕ Limpiar</button>
                        <button type="submit" class="btn btn-primary" id="bSeg">🔐 Cambiar contraseña</button>
                    </div>
                </form>
            </div>
        </div>

    </div>
</div>
</div>

<!-- ── Modal Foto ── -->
<div class="mbd" id="mFoto">
    <div class="modal">
        <div class="mhdr">
            <span>📷 Cambiar foto de perfil</span>
            <button class="mcls" onclick="cerrarModal()">✕</button>
        </div>
        <div class="mbdy">
            <div id="al-foto" class="alert"></div>
            <div class="dz" id="dz"
                 onclick="document.getElementById('iFile').click()"
                 ondragover="dzOver(event)" ondragleave="dzOut()" ondrop="dzDrop(event)">
                <div class="dz-ico">🖼️</div>
                <div class="dz-lbl">Arrastra tu foto o haz clic para seleccionar</div>
                <div class="dz-hint">JPG · PNG · WebP — máx. 5 MB · Se optimiza automáticamente</div>
            </div>
            <input type="file" id="iFile" accept="image/*" style="display:none" onchange="dzPrev(event)">
            <div class="dz-prev" id="dzPrev">
                <img id="prevImg" src="" alt="">
                <button class="dz-rm" onclick="dzClean()">✕</button>
                <div class="dz-fn" id="dzFn"></div>
                <div class="dz-quality">✅ Se aplicará optimización automática de calidad</div>
            </div>
        </div>
        <div class="mftr">
            <button class="btn btn-ghost" onclick="cerrarModal()">Cancelar</button>
            <button class="btn btn-primary" id="bSubir" disabled onclick="subirFoto()">📤 Subir foto</button>
        </div>
    </div>
</div>

<script>
'use strict';
const API = '/GestionPrestamo/api/perfil.php';

/* tabs */
function st(t) {
    document.querySelectorAll('.tab').forEach(b => b.classList.toggle('active', b.dataset.tab === t));
    document.querySelectorAll('.tp').forEach(p => p.classList.toggle('active', p.id === 'tp-' + t));
    document.querySelectorAll('.alert').forEach(a => a.style.display = 'none');
}

/* alerts */
function al(id, msg, ok) {
    const e = document.getElementById(id);
    e.className = 'alert ' + (ok ? 'alert-ok' : 'alert-err');
    e.innerHTML = msg; e.style.display = 'flex';
    e.scrollIntoView({behavior:'smooth', block:'nearest'});
}
function hal(id) { const e = document.getElementById(id); if (e) e.style.display='none'; }

/* api */
async function api(action, data, method='POST') {
    const o = {method, headers:{}};
    if (data instanceof FormData) o.body = data;
    else if (data) { o.headers['Content-Type'] = 'application/json'; o.body = JSON.stringify(data); }
    return (await fetch(`${API}?action=${action}`, o)).json();
}

/* cargar */
async function cargar() {
    try {
        const r = await api('obtener', null, 'GET');
        if (r.error) {
            // Mostrar error para diagnóstico
            console.error('Error API perfil:', r.error, r);
            al('al-datos', '⚠️ No se pudieron cargar los datos: ' + (r.error || 'Error desconocido'), false);
            return;
        }
        if (!r.perfil) {
            al('al-datos', '⚠️ Respuesta inesperada del servidor.', false);
            return;
        }
        const p = r.perfil, c = p.contactos || {};
        sv('iNombre', p.nombre); sv('iApellido', p.apellido); sv('iCedula', p.cedula||'');
        sv('iFnac', p.fecha_nacimiento||''); sv('iGenero', p.genero||'');
        sv('iEstado', p.estado_civil||''); sv('iNac', p.nacionalidad||'');
        sv('iUser', p.username); sv('iTel', c['Telefono']||'');
        sv('iWa', c['WhatsApp']||''); sv('iEmail', c['Email']||'');
        sv('iDir', c['Dirección']||'');
        if (p.foto_path) setAv('/GestionPrestamo/uploads/fotos/' + p.foto_path + '?t=' + Date.now(), true);
    } catch(e) {
        console.error('Error cargar perfil:', e);
        al('al-datos', '❌ Error de conexión al cargar perfil.', false);
    }
}
function sv(id, v) { const e = document.getElementById(id); if(e) e.value = v??''; }

/* guardar datos */
document.getElementById('fDatos').addEventListener('submit', async e => {
    e.preventDefault(); hal('al-datos');
    const b = document.getElementById('bDatos');
    b.disabled=true; b.innerHTML='⏳ Guardando…';
    const nombre   = document.getElementById('iNombre').value.trim();
    const apellido = document.getElementById('iApellido').value.trim();
    try {
        const r = await api('actualizar', {
            nombre, apellido,
            cedula: document.getElementById('iCedula').value.trim(),
            fecha_nacimiento: document.getElementById('iFnac').value,
            genero: document.getElementById('iGenero').value,
            estado_civil: document.getElementById('iEstado').value,
            nacionalidad: document.getElementById('iNac').value.trim(),
            telefono:'', email:'', whatsapp:'', direccion:''
        });
        if (r.success) {
            al('al-datos','✅ '+r.mensaje, true);
            document.getElementById('heroNombre').textContent = nombre+' '+apellido;
        } else al('al-datos','⚠️ '+(r.error||'Error.'), false);
    } catch { al('al-datos','❌ Error de conexión.', false); }
    finally { b.disabled=false; b.innerHTML='💾 Guardar cambios'; }
});

/* guardar contacto */
document.getElementById('fContacto').addEventListener('submit', async e => {
    e.preventDefault(); hal('al-contacto');
    const b = document.getElementById('bContacto');
    b.disabled=true; b.innerHTML='⏳ Guardando…';
    try {
        const r = await api('actualizar', {
            nombre: document.getElementById('iNombre').value.trim()||' ',
            apellido: document.getElementById('iApellido').value.trim()||' ',
            telefono: document.getElementById('iTel').value.trim(),
            email: document.getElementById('iEmail').value.trim(),
            whatsapp: document.getElementById('iWa').value.trim(),
            direccion: document.getElementById('iDir').value.trim()
        });
        if (r.success) al('al-contacto','✅ Contacto actualizado.', true);
        else al('al-contacto','⚠️ '+(r.error||'Error.'), false);
    } catch { al('al-contacto','❌ Error de conexión.', false); }
    finally { b.disabled=false; b.innerHTML='💾 Guardar contacto'; }
});

/* pw checks */
document.getElementById('iPwNew').addEventListener('input', function() {
    const v = this.value;
    document.getElementById('r1').classList.toggle('ok', v.length>=8);
    document.getElementById('r2').classList.toggle('ok', /[A-Z]/.test(v));
    document.getElementById('r3').classList.toggle('ok', /[0-9]/.test(v));
});

/* cambiar pass */
document.getElementById('fSeg').addEventListener('submit', async e => {
    e.preventDefault(); hal('al-seg');
    const b    = document.getElementById('bSeg');
    const act  = document.getElementById('iPwAct').value;
    const nva  = document.getElementById('iPwNew').value;
    const cfm  = document.getElementById('iPwCfm').value;
    if (!act||!nva) { al('al-seg','⚠️ Completa todos los campos.', false); return; }
    if (nva!==cfm)  { al('al-seg','⚠️ Las contraseñas no coinciden.', false); return; }
    b.disabled=true; b.innerHTML='⏳ Cambiando…';
    try {
        const r = await api('cambiar_password', {password_actual:act, password_nueva:nva, password_confirmar:cfm});
        if (r.success) {
            al('al-seg','✅ '+r.mensaje, true);
            document.getElementById('fSeg').reset();
            ['r1','r2','r3'].forEach(id => document.getElementById(id)?.classList.remove('ok'));
        } else al('al-seg','⚠️ '+(r.error||'Error.'), false);
    } catch { al('al-seg','❌ Error de conexión.', false); }
    finally { b.disabled=false; b.innerHTML='🔐 Cambiar contraseña'; }
});

/* avatar */
function setAv(url, tieneFoto) {
    const ph = document.getElementById('avPhoto');
    const ci = document.getElementById('avCircle');
    const bd = document.getElementById('btnDel');
    if (url && tieneFoto) {
        ph.src=url; ph.style.display='block';
        if(ci) ci.style.display='none';
        if(bd) bd.style.display='';
    } else {
        ph.src=''; ph.style.display='none';
        if(ci) ci.style.display='flex';
        if(bd) bd.style.display='none';
    }
    // Actualizar sidebar al instante con cache-bust
    const sideAvImg = document.querySelector('.sidebar .avatar img, aside .avatar img');
    if (sideAvImg && url && tieneFoto) {
        sideAvImg.src = url + '?t=' + Date.now();
    }
}

/* Comprimir imagen via Canvas antes de subir */
function comprimirImagen(file, maxPx, quality) {
    return new Promise((resolve) => {
        const reader = new FileReader();
        reader.onload = ev => {
            const img = new Image();
            img.onload = () => {
                let { width, height } = img;
                if (width > maxPx || height > maxPx) {
                    if (width >= height) { height = Math.round(height * maxPx / width); width = maxPx; }
                    else { width = Math.round(width * maxPx / height); height = maxPx; }
                }
                const canvas = document.createElement('canvas');
                canvas.width = width; canvas.height = height;
                const ctx = canvas.getContext('2d');
                ctx.imageSmoothingEnabled = true;
                ctx.imageSmoothingQuality = 'high';
                ctx.drawImage(img, 0, 0, width, height);
                canvas.toBlob(blob => resolve(blob), 'image/jpeg', quality);
            };
            img.src = ev.target.result;
        };
        reader.readAsDataURL(file);
    });
}

/* modal foto */
function abrirModal()  { document.getElementById('mFoto').classList.add('open'); }
function cerrarModal() { document.getElementById('mFoto').classList.remove('open'); dzClean(); }

function dzPrev(e) { if(e.target.files[0]) showPrev(e.target.files[0]); }
function showPrev(file) {
    const r = new FileReader();
    r.onload = ev => {
        document.getElementById('prevImg').src = ev.target.result;
        document.getElementById('dzFn').textContent = file.name;
        document.getElementById('dzPrev').style.display = 'block';
        document.getElementById('dz').style.display = 'none';
        document.getElementById('bSubir').disabled = false;
    };
    r.readAsDataURL(file);
}
function dzClean() {
    document.getElementById('prevImg').src='';
    document.getElementById('dzPrev').style.display='none';
    document.getElementById('dz').style.display='block';
    document.getElementById('bSubir').disabled=true;
    document.getElementById('iFile').value='';
    hal('al-foto');
}
function dzOver(e)  { e.preventDefault(); document.getElementById('dz').classList.add('on'); }
function dzOut()    { document.getElementById('dz').classList.remove('on'); }
function dzDrop(e)  {
    e.preventDefault(); dzOut();
    const f = e.dataTransfer.files[0];
    if (f?.type.startsWith('image/')) showPrev(f);
    else al('al-foto','⚠️ Solo se permiten imágenes.', false);
}

async function subirFoto() {
    const f = document.getElementById('iFile').files[0];
    if (!f) return;
    const b = document.getElementById('bSubir');
    hal('al-foto'); b.disabled=true; b.innerHTML='⚙️ Optimizando…';

    try {
        // Comprimir: máx 800px, calidad 92% — mantiene buena resolución sin perder calidad
        const blob = await comprimirImagen(f, 800, 0.92);
        const optimized = new File([blob], f.name.replace(/\.[^.]+$/, '') + '.jpg', { type: 'image/jpeg' });

        b.innerHTML='📤 Subiendo…';
        const fd = new FormData(); fd.append('foto', optimized);
        const r = await api('subir_foto', fd);
        if (r.success) {
            const urlFinal = '/GestionPrestamo/uploads/fotos/' + r.foto_path + '?t=' + Date.now();
            // Actualizar foto en hero
            setAv(urlFinal, true);
            // Actualizar foto en sidebar (avatar con imagen)
            const sideAv = document.querySelector('aside .avatar');
            if (sideAv) {
                sideAv.innerHTML = `<img src="${urlFinal}" alt="foto" style="width:100%;height:100%;object-fit:cover;border-radius:50%">`;
                sideAv.style.overflow = 'hidden';
                sideAv.style.padding = '0';
                sideAv.style.background = 'transparent';
            }
            cerrarModal();
        } else al('al-foto','⚠️ '+(r.error||'Error.'), false);
    } catch(err) { al('al-foto','❌ Error: '+err.message, false); }
    finally { b.disabled=false; b.innerHTML='📤 Subir foto'; }
}

async function eliminarFoto() {
    if (!confirm('¿Eliminar la foto de perfil?')) return;
    try {
        const r = await api('eliminar_foto', {});
        if (r.success) {
            setAv(null, false);
            // Restaurar iniciales en sidebar
            const sideAv = document.querySelector('aside .avatar');
            if (sideAv) {
                const iniciales = '<?= htmlspecialchars($iniciales) ?>';
                sideAv.innerHTML = iniciales;
                sideAv.style.overflow = '';
                sideAv.style.padding = '';
                sideAv.style.background = '';
            }
        }
    } catch { alert('Error de conexión.'); }
}

/* sidebar */
function toggleSidebar() {
    document.getElementById('sidebar')?.classList.toggle('open');
    document.getElementById('overlay')?.classList.toggle('open');
}
function toggleGroup(h) {
    h.classList.toggle('open');
    h.nextElementSibling?.classList.toggle('open');
}
function logout() {
    fetch('/GestionPrestamo/api/auth.php?action=logout')
        .finally(() => window.location.href='/GestionPrestamo/login.php');
}

cargar();
</script>
</body>
</html>