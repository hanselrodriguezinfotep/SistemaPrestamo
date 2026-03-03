<?php
// views/notificaciones.php — Preferencias de Notificación por Persona
require_once __DIR__ . '/../config/session.php';
$sesion = verificarSesion();

$rolesPermitidos = ['superadmin','admin','gerente','supervisor','cajero'];
if (!in_array($sesion['rol'], $rolesPermitidos, true)) {
    header('Location: /GestionPrestamo/index.php'); exit;
}
$id_empresa = (int)($sesion['id_empresa'] ?? 0);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <?php $pageTitle = 'Notificaciones por Persona'; require_once __DIR__ . '/../php/partials/head.php'; ?>
    <style>
        .page { max-width: 1200px; }

        .notif-layout {
            display: grid;
            grid-template-columns: 340px 1fr;
            gap: 20px;
            align-items: start;
        }
        @media (max-width: 768px) {
            .notif-layout { grid-template-columns: 1fr; }
            .notif-panel-form { display: none; }
            .notif-panel-form.visible { display: block; }
        }

        /* ── Panel izquierdo ── */
        .notif-panel-list {
            background: var(--surface);
            border: 1.5px solid var(--border);
            border-radius: 14px;
            box-shadow: var(--shadow);
            overflow: hidden;
            position: sticky;
            top: 20px;
        }
        .notif-panel-list-header {
            padding: 14px 16px 10px;
            border-bottom: 1.5px solid var(--border);
            background: var(--bg);
        }
        .notif-panel-list-header h3 {
            margin: 0 0 8px;
            font-size: .88rem;
            font-weight: 800;
            color: var(--text);
        }
        .search-wrap { position: relative; }
        .search-wrap input {
            width: 100%;
            padding: 9px 12px 9px 36px;
            border: 1.5px solid var(--border);
            border-radius: 9px;
            font-family: inherit;
            font-size: .83rem;
            box-sizing: border-box;
            outline: none;
            transition: border-color .12s;
            background: var(--surface);
            color: var(--text);
        }
        .search-wrap input:focus { border-color: var(--primary); }
        .search-wrap .search-icon {
            position: absolute; left: 11px; top: 50%;
            transform: translateY(-50%);
            color: var(--muted); font-size: .9rem; pointer-events: none;
        }
        .filter-tipo-wrap {
            margin-top: 8px;
            display: flex; gap: 5px; flex-wrap: wrap;
        }

        /* ── Barra de selección múltiple ── */
        .bulk-bar {
            display: none;
            align-items: center;
            gap: 8px;
            padding: 8px 14px;
            background: #dbeafe;
            border-bottom: 1.5px solid #93c5fd;
            font-size: .78rem;
            font-weight: 700;
            color: #1e40af;
        }
        .bulk-bar.visible { display: flex; }
        .bulk-bar-count { flex: 1; }
        .bulk-bar button {
            padding: 4px 10px;
            font-size: .72rem;
            border-radius: 6px;
            border: 1.5px solid #3b82f6;
            background: #3b82f6;
            color: #fff;
            cursor: pointer;
            font-weight: 700;
        }
        .bulk-bar button.btn-danger {
            background: transparent;
            color: #3b82f6;
            border-color: #3b82f6;
        }

        /* ── Lista de personas ── */
        .notif-person-list {
            max-height: 480px;
            overflow-y: auto;
        }
        .notif-person-item {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 10px 14px;
            border-bottom: 1px solid var(--border);
            cursor: pointer;
            transition: background .1s;
            color: inherit;
            user-select: none;
        }
        .notif-person-item:hover { background: var(--bg); }
        .notif-person-item.active {
            background: #dbeafe;
            border-left: 3px solid var(--primary);
        }
        .notif-person-item.bulk-selected {
            background: #eff6ff;
            border-left: 3px solid #60a5fa;
        }

        /* ── Checkbox de selección ── */
        .person-chk {
            width: 16px; height: 16px;
            accent-color: var(--primary);
            cursor: pointer;
            flex-shrink: 0;
        }

        .notif-person-avatar {
            width: 34px; height: 34px;
            border-radius: 50%;
            background: linear-gradient(135deg, var(--primary), #60a5fa);
            color: #fff; font-weight: 800; font-size: .78rem;
            display: flex; align-items: center; justify-content: center;
            flex-shrink: 0;
        }
        .notif-person-info { flex: 1; min-width: 0; }
        .notif-person-name {
            font-size: .82rem; font-weight: 700; color: var(--text);
            white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
        }
        .notif-person-tipo { font-size: .68rem; color: var(--muted); margin-top: 1px; }
        .notif-person-canal {
            font-size: .64rem; padding: 2px 7px; border-radius: 20px;
            background: #f1f5f9; color: var(--muted); flex-shrink: 0;
        }
        .notif-person-canal.email    { background: #dbeafe; color: #1e40af; }
        .notif-person-canal.whatsapp { background: #dcfce7; color: #166534; }
        .notif-person-canal.ambos    { background: #fef9c3; color: #854d0e; }
        .notif-person-canal.ninguno  { background: #f1f5f9; color: #94a3b8; }

        /* ── Panel derecho ── */
        .notif-panel-form {
            background: var(--surface);
            border: 1.5px solid var(--border);
            border-radius: 14px;
            box-shadow: var(--shadow);
            overflow: hidden;
        }
        .notif-form-header {
            padding: 16px 20px 12px;
            border-bottom: 1.5px solid var(--border);
            background: var(--bg);
            display: flex; align-items: center; gap: 12px;
        }
        .notif-form-avatar {
            width: 44px; height: 44px; border-radius: 50%;
            background: linear-gradient(135deg, var(--primary), #60a5fa);
            color: #fff; font-weight: 800; font-size: 1rem;
            display: flex; align-items: center; justify-content: center; flex-shrink: 0;
        }
        .notif-form-title  { font-size: .93rem; font-weight: 800; margin: 0; }
        .notif-form-subtitle { font-size: .72rem; color: var(--muted); margin: 2px 0 0; }
        .notif-form-body { padding: 20px; }

        /* ── Canal ── */
        .form-section-label {
            font-size: .72rem; font-weight: 800; color: var(--muted);
            text-transform: uppercase; letter-spacing: .06em; margin: 0 0 10px;
        }
        .canal-grid {
            display: grid; grid-template-columns: repeat(4,1fr);
            gap: 8px; margin-bottom: 20px;
        }
        @media (max-width: 600px) { .canal-grid { grid-template-columns: repeat(2,1fr); } }
        .canal-option {
            border: 2px solid var(--border); border-radius: 10px;
            padding: 10px 8px; text-align: center; cursor: pointer;
            transition: all .12s; user-select: none;
        }
        .canal-option:hover { border-color: var(--primary); background: var(--bg); }
        .canal-option.selected { border-color: var(--primary); background: #dbeafe; }
        .canal-option input { display: none; }
        .canal-option-icon  { font-size: 1.25rem; display: block; margin-bottom: 3px; }
        .canal-option-label { font-size: .7rem; font-weight: 700; color: var(--text); }

        /* ── Contacto ── */
        .contact-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; margin-bottom: 20px; }
        @media (max-width: 600px) { .contact-grid { grid-template-columns: 1fr; } }
        .form-field label { display: block; font-size: .72rem; font-weight: 700; color: var(--muted); margin-bottom: 5px; }
        .form-field input {
            width: 100%; padding: 9px 12px;
            border: 1.5px solid var(--border); border-radius: 9px;
            font-family: inherit; font-size: .83rem; box-sizing: border-box;
            outline: none; transition: border-color .12s;
            color: var(--text); background: var(--surface);
        }
        .form-field input:focus { border-color: var(--primary); }

        /* ── Eventos ── */
        .eventos-grid {
            display: grid; grid-template-columns: repeat(2,1fr);
            gap: 10px; margin-bottom: 20px;
        }
        @media (max-width: 600px) { .eventos-grid { grid-template-columns: 1fr; } }
        .evento-item {
            display: flex; align-items: flex-start; gap: 11px;
            padding: 11px 13px; border: 1.5px solid var(--border);
            border-radius: 10px; cursor: pointer;
            transition: border-color .12s, background .12s; user-select: none;
        }
        .evento-item:hover { border-color: var(--primary); background: var(--bg); }
        .evento-item input[type="checkbox"] {
            margin-top: 2px; accent-color: var(--primary);
            width: 15px; height: 15px; flex-shrink: 0; cursor: pointer;
        }
        .evento-item.checked { border-color: var(--primary); background: #f0f7ff; }
        .evento-label { font-size: .8rem; font-weight: 700; color: var(--text); line-height: 1.3; }
        .evento-desc  { font-size: .68rem; color: var(--muted); margin-top: 2px; line-height: 1.4; }

        /* ── Footer ── */
        .notif-form-footer {
            padding: 12px 20px; border-top: 1.5px solid var(--border);
            background: var(--bg); display: flex;
            align-items: center; justify-content: space-between;
            gap: 12px; flex-wrap: wrap;
        }
        .save-status { font-size: .78rem; color: var(--muted); display: flex; align-items: center; gap: 6px; }

        /* ── Panel masivo ── */
        .bulk-panel {
            background: var(--surface); border: 1.5px solid #3b82f6;
            border-radius: 14px; box-shadow: var(--shadow); overflow: hidden;
        }
        .bulk-panel-header {
            padding: 14px 20px 10px; border-bottom: 1.5px solid #bfdbfe;
            background: #eff6ff; display: flex; align-items: center; gap: 10px;
        }
        .bulk-panel-header h3 { margin: 0; font-size: .9rem; font-weight: 800; color: #1e40af; }
        .bulk-panel-body { padding: 18px 20px; }
        .bulk-info { font-size: .8rem; color: var(--muted); margin-bottom: 16px; line-height: 1.5; }
        .bulk-eventos-grid {
            display: grid; grid-template-columns: repeat(2,1fr);
            gap: 8px; margin-bottom: 16px;
        }
        .bulk-evento-item {
            display: flex; align-items: center; gap: 9px;
            padding: 9px 11px; border: 1.5px solid var(--border);
            border-radius: 9px; cursor: pointer;
            transition: border-color .12s, background .12s; user-select: none;
        }
        .bulk-evento-item:hover { border-color: #3b82f6; background: #f0f7ff; }
        .bulk-evento-item input { accent-color: var(--primary); cursor: pointer; }
        .bulk-evento-item.checked { border-color: #3b82f6; background: #eff6ff; }
        .bulk-evento-label { font-size: .78rem; font-weight: 700; color: var(--text); }
        .bulk-canal-grid {
            display: grid; grid-template-columns: repeat(4,1fr);
            gap: 8px; margin-bottom: 16px;
        }
        .bulk-canal-opt {
            border: 2px solid var(--border); border-radius: 9px;
            padding: 8px 6px; text-align: center; cursor: pointer;
            transition: all .12s; user-select: none;
        }
        .bulk-canal-opt:hover { border-color: #3b82f6; background: #eff6ff; }
        .bulk-canal-opt.selected { border-color: #3b82f6; background: #dbeafe; }
        .bulk-canal-opt input { display: none; }
        .bulk-canal-opt-icon  { font-size: 1.1rem; display: block; margin-bottom: 2px; }
        .bulk-canal-opt-label { font-size: .68rem; font-weight: 700; color: var(--text); }
        .bulk-notice {
            font-size: .72rem; color: #92400e; background: #fef3c7;
            border: 1px solid #fde68a; border-radius: 8px;
            padding: 8px 12px; margin-bottom: 14px;
        }
        .bulk-panel-footer {
            padding: 12px 20px; border-top: 1.5px solid #bfdbfe;
            background: #eff6ff; display: flex;
            justify-content: space-between; align-items: center; gap: 12px;
        }

        /* ── Empty / skeleton ── */
        .notif-empty {
            display: flex; flex-direction: column;
            align-items: center; justify-content: center;
            padding: 50px 30px; text-align: center; color: var(--muted);
        }
        .notif-empty-icon  { font-size: 2.8rem; margin-bottom: 10px; opacity: .5; }
        .notif-empty-title { font-size: .88rem; font-weight: 700; margin-bottom: 5px; color: var(--text); }
        .notif-empty-sub   { font-size: .76rem; }
        .skeleton {
            background: linear-gradient(90deg, #f1f5f9 25%, #e2e8f0 50%, #f1f5f9 75%);
            background-size: 200% 100%; animation: skel 1.2s infinite;
            border-radius: 6px; height: 14px;
        }
        @keyframes skel { 0%{background-position:200% 0} 100%{background-position:-200% 0} }
    </style>
</head>
<body>
<div class="app">
<?php $activePage = 'notificaciones'; require_once __DIR__ . '/../php/partials/sidebar.php'; ?>
<main class="main">
    <header class="header">
        <button class="hamburger" onclick="toggleSidebar()">☰</button>
        <div class="header-title">
            <h1>🔔 Notificaciones por Persona</h1>
            <p>Configura canales y eventos para cada persona del centro</p>
        </div>
        <div class="header-actions">
            <a href="/GestionPrestamo/index.php" style="text-decoration:none">
                <button class="btn btn-ghost" style="padding:8px 14px;font-size:.8rem">← Dashboard</button>
            </a>
            <span class="badge-role"><?= htmlspecialchars($nombreRol) ?></span>
        </div>
    </header>

    <div class="page">
        <div class="notif-layout">

            <!-- ═══════════════════════════════════════
                 PANEL IZQUIERDO
            ═══════════════════════════════════════ -->
            <div class="notif-panel-list">
                <div class="notif-panel-list-header">
                    <h3>👥 Personas</h3>
                    <div class="search-wrap">
                        <span class="search-icon">🔍</span>
                        <input type="text" id="notif-search"
                               placeholder="Buscar por nombre o cédula…"
                               oninput="buscarPersonas(this.value)"
                               autocomplete="off">
                    </div>
                    <div class="filter-tipo-wrap" id="filter-tipo">
                        <button class="btn btn-ghost filter-btn active" data-tipo=""
                                onclick="setTipoFiltro('',this)"
                                style="padding:4px 10px;font-size:.68rem">Todos</button>
                        <button class="btn btn-ghost filter-btn" data-tipo="Cliente"
                                onclick="setTipoFiltro('Cliente',this)"
                                style="padding:4px 10px;font-size:.68rem">🎒 Clientes</button>
                        <button class="btn btn-ghost filter-btn" data-tipo="Garante"
                                onclick="setTipoFiltro('Garante',this)"
                                style="padding:4px 10px;font-size:.68rem">👨‍👩‍👧 Garantees</button>
                        <button class="btn btn-ghost filter-btn" data-tipo="Asesor"
                                onclick="setTipoFiltro('Asesor',this)"
                                style="padding:4px 10px;font-size:.68rem">📚 Asesors</button>
                        <button class="btn btn-ghost filter-btn" data-tipo="Empleado"
                                onclick="setTipoFiltro('Empleado',this)"
                                style="padding:4px 10px;font-size:.68rem">💼 Empleados</button>
                        <button class="btn btn-ghost filter-btn" data-tipo="Supervisor"
                                onclick="setTipoFiltro('Supervisor',this)"
                                style="padding:4px 10px;font-size:.68rem">🧭 Supervisores</button>
                        <button class="btn btn-ghost filter-btn" data-tipo="Consultor"
                                onclick="setTipoFiltro('Consultor',this)"
                                style="padding:4px 10px;font-size:.68rem">🧠 Psicólogos</button>
                    </div>
                </div>

                <!-- Barra de acciones masivas -->
                <div class="bulk-bar" id="bulk-bar">
                    <span class="bulk-bar-count" id="bulk-count">0 seleccionados</span>
                    <button onclick="seleccionarTodos()">Todos</button>
                    <button onclick="abrirConfigMasiva()">⚙️ Configurar</button>
                    <button class="btn-danger" onclick="limpiarSeleccion()">✕ Limpiar</button>
                </div>

                <div class="notif-person-list" id="notif-person-list">
                    <div class="notif-empty">
                        <div class="notif-empty-icon">🔍</div>
                        <div class="notif-empty-title">Busca una persona</div>
                        <div class="notif-empty-sub">Escribe al menos 2 caracteres o selecciona un tipo</div>
                    </div>
                </div>
            </div>

            <!-- ═══════════════════════════════════════
                 PANEL DERECHO
            ═══════════════════════════════════════ -->
            <div class="notif-panel-form" id="notif-panel-form">
                <div class="notif-empty" style="padding:80px 30px">
                    <div class="notif-empty-icon">👤</div>
                    <div class="notif-empty-title">Selecciona una persona</div>
                    <div class="notif-empty-sub">Usa el buscador o filtra por tipo para encontrar<br>una persona y configurar sus notificaciones.<br><br>
                        También puedes marcar varios con ☑ para configurarlos a la vez.</div>
                </div>
            </div>

        </div>
    </div>
</main>
</div>

<script>
(function(){
    const API       = '/GestionPrestamo/api/notif_prefs.php';
    const ID_CENTRO = <?= $id_empresa ?>;

    let _timerBuscar    = null;
    let _personaActual  = null;
    let _filtroBusqueda = '';
    let _filtroTipo     = '';
    let _resultados     = [];
    let _seleccionados  = new Set(); // ids seleccionados para config masiva

    // ── Filtro tipo ──────────────────────────────────────────────────────────
    window.setTipoFiltro = function(tipo, btn) {
        _filtroTipo = tipo;
        document.querySelectorAll('.filter-btn').forEach(b => b.classList.remove('active'));
        btn.classList.add('active');

        if (tipo || _filtroBusqueda.length >= 2) {
            // Tiene tipo o texto: buscar
            ejecutarBusqueda();
        } else {
            // "Todos" sin texto: cargar todos
            ejecutarBusqueda();
        }
    };

    // ── Buscar personas ──────────────────────────────────────────────────────
    window.buscarPersonas = function(q) {
        _filtroBusqueda = q.trim();
        clearTimeout(_timerBuscar);
        _timerBuscar = setTimeout(ejecutarBusqueda, 280);
    };

    function ejecutarBusqueda() {
        // Si no hay texto y no hay tipo, usar '*' para cargar todos
        const q = _filtroBusqueda.length >= 2 ? _filtroBusqueda : (_filtroTipo ? '' : '*');

        const lista = document.getElementById('notif-person-list');
        lista.innerHTML = `
            <div style="padding:16px 18px;display:flex;flex-direction:column;gap:10px">
                ${[1,2,3].map(()=>`
                <div style="display:flex;gap:10px;align-items:center">
                    <div class="skeleton" style="width:34px;height:34px;border-radius:50%;flex-shrink:0"></div>
                    <div style="flex:1"><div class="skeleton" style="width:70%;margin-bottom:6px"></div>
                    <div class="skeleton" style="width:40%;height:10px"></div></div>
                </div>`).join('')}
            </div>`;

        let url = `${API}?action=buscar&id_empresa=${ID_CENTRO}&q=${encodeURIComponent(q)}`;
        if (_filtroTipo) url += `&tipo_filter=${encodeURIComponent(_filtroTipo)}`;

        fetch(url)
            .then(r => r.json())
            .then(d => { _resultados = d.personas || []; renderLista(); })
            .catch(() => {
                lista.innerHTML = '<div class="notif-empty"><div class="notif-empty-icon">⚠️</div><div class="notif-empty-sub">Error al buscar</div></div>';
            });
    }

    function renderLista() {
        const lista = document.getElementById('notif-person-list');
        if (!_resultados.length) {
            lista.innerHTML = `<div class="notif-empty">
                <div class="notif-empty-icon">😕</div>
                <div class="notif-empty-title">Sin resultados</div>
                <div class="notif-empty-sub">No se encontraron personas</div>
            </div>`;
            return;
        }
        lista.innerHTML = _resultados.map(p => {
            const ini = iniciales(p.nombre);
            const canal = p.canal || 'ninguno';
            const canalLabel = {email:'Email',whatsapp:'WhatsApp',ambos:'Ambos',ninguno:'—'}[canal] || canal;
            const isActive = _personaActual === p.id;
            const isSel    = _seleccionados.has(p.id);
            return `<div class="notif-person-item${isActive?' active':''}${isSel?' bulk-selected':''}" id="pitem-${p.id}">
                <input type="checkbox" class="person-chk" id="chk-${p.id}" ${isSel?'checked':''}
                       onclick="event.stopPropagation(); toggleSeleccion(${p.id}, this.checked)">
                <div class="notif-person-avatar" onclick="seleccionarPersona(${p.id})">${ini}</div>
                <div class="notif-person-info" onclick="seleccionarPersona(${p.id})" style="cursor:pointer">
                    <div class="notif-person-name">${esc(p.nombre)}</div>
                    <div class="notif-person-tipo">${esc(p.tipo||'')}</div>
                </div>
                <span class="notif-person-canal ${canal}">${canalLabel}</span>
            </div>`;
        }).join('');
    }

    // ── Selección múltiple ───────────────────────────────────────────────────
    window.toggleSeleccion = function(id, checked) {
        if (checked) _seleccionados.add(id);
        else         _seleccionados.delete(id);

        const item = document.getElementById('pitem-' + id);
        if (item) item.classList.toggle('bulk-selected', checked);
        actualizarBulkBar();
    };

    window.seleccionarTodos = function() {
        _resultados.forEach(p => {
            _seleccionados.add(p.id);
            const item = document.getElementById('pitem-' + p.id);
            const chk  = document.getElementById('chk-' + p.id);
            if (item) item.classList.add('bulk-selected');
            if (chk)  chk.checked = true;
        });
        actualizarBulkBar();
    };

    window.limpiarSeleccion = function() {
        _seleccionados.forEach(id => {
            const item = document.getElementById('pitem-' + id);
            const chk  = document.getElementById('chk-'  + id);
            if (item) item.classList.remove('bulk-selected');
            if (chk)  chk.checked = false;
        });
        _seleccionados.clear();
        actualizarBulkBar();
    };

    function actualizarBulkBar() {
        const bar   = document.getElementById('bulk-bar');
        const count = document.getElementById('bulk-count');
        const n     = _seleccionados.size;
        bar.classList.toggle('visible', n > 0);
        count.textContent = n === 1 ? '1 persona seleccionada' : `${n} personas seleccionadas`;
    }

    // ── Abrir configuración masiva ───────────────────────────────────────────
    window.abrirConfigMasiva = function() {
        if (!_seleccionados.size) return;
        const panel = document.getElementById('notif-panel-form');
        _personaActual = null; // deseleccionar persona individual
        document.querySelectorAll('.notif-person-item').forEach(el => el.classList.remove('active'));

        const todosLosEventos = [
            { key: 'notif_credenciales', icon: '🔑', label: 'Nuevas credenciales'   },
            { key: 'notif_contrato',    icon: '📋', label: 'Confirmación contrato' },
            { key: 'notif_pago',         icon: '✅', label: 'Confirmación de pago'   },
            { key: 'notif_cuota_vencer', icon: '⚠️', label: 'Cuota por vencer'       },
            { key: 'notif_reporte',      icon: '📊', label: 'Reporte disponible'     },
            { key: 'notif_incidencia',   icon: '⚡', label: 'Incidencia disciplinar' },
        ];
        const canales = [
            { val: 'email',    icon: '📧', label: 'Email'    },
            { val: 'whatsapp', icon: '📱', label: 'WhatsApp' },
            { val: 'ambos',    icon: '📲', label: 'Ambos'    },
            { val: 'ninguno',  icon: '🔕', label: 'Ninguno'  },
        ];

        panel.className = 'notif-panel-form bulk-panel';
        panel.innerHTML = `
            <div class="bulk-panel-header">
                <span style="font-size:1.5rem">⚙️</span>
                <div>
                    <h3>Configuración Masiva</h3>
                    <p style="margin:2px 0 0;font-size:.72rem;color:#2563eb">
                        ${_seleccionados.size} persona${_seleccionados.size>1?'s':''} seleccionada${_seleccionados.size>1?'s':''}
                    </p>
                </div>
            </div>
            <div class="bulk-panel-body">
                <div class="bulk-notice">
                    ⚠️ Solo se modificarán los campos que marques aquí. Los demás valores de cada persona se mantendrán intactos.
                </div>

                <!-- Canal -->
                <p class="form-section-label">📡 Canal de envío <span style="font-weight:400;text-transform:none">(opcional)</span></p>
                <div class="bulk-canal-grid" id="bulk-canal-grid">
                    <label class="bulk-canal-opt" title="No cambiar canal">
                        <input type="radio" name="bulk-canal" value="" checked onchange="actualizarBulkCanal(this)">
                        <span class="bulk-canal-opt-icon">⏭️</span>
                        <span class="bulk-canal-opt-label">Sin cambio</span>
                    </label>
                    ${canales.map(c => `
                    <label class="bulk-canal-opt">
                        <input type="radio" name="bulk-canal" value="${c.val}" onchange="actualizarBulkCanal(this)">
                        <span class="bulk-canal-opt-icon">${c.icon}</span>
                        <span class="bulk-canal-opt-label">${c.label}</span>
                    </label>`).join('')}
                </div>

                <!-- Eventos -->
                <p class="form-section-label">🔔 Activar / Desactivar eventos <span style="font-weight:400;text-transform:none">(opcional)</span></p>
                <div class="bulk-eventos-grid">
                    ${todosLosEventos.map(ev => `
                    <label class="bulk-evento-item" id="bev-label-${ev.key}">
                        <input type="checkbox" id="bev-${ev.key}" onclick="actualizarBulkEvento(this,'${ev.key}')">
                        <div style="flex:1">
                            <div class="bulk-evento-label">${ev.icon} ${ev.label}</div>
                        </div>
                        <select id="bev-val-${ev.key}" data-no-search style="font-size:.7rem;padding:3px 6px;border-radius:6px;border:1.5px solid var(--border);background:var(--surface);color:var(--text);cursor:pointer" disabled>
                            <option value="1">✅ Activar</option>
                            <option value="0">❌ Desactivar</option>
                        </select>
                    </label>`).join('')}
                </div>

            </div>
            <div class="bulk-panel-footer">
                <span class="save-status" id="bulk-save-status"></span>
                <div style="display:flex;gap:10px">
                    <button class="btn btn-ghost" onclick="cerrarBulkPanel()" style="padding:9px 18px;font-size:.83rem">
                        Cancelar
                    </button>
                    <button class="btn btn-primary" id="btn-guardar-masivo" onclick="guardarMasivo()"
                            style="padding:9px 22px;font-size:.83rem">
                        💾 Aplicar a ${_seleccionados.size} persona${_seleccionados.size>1?'s':''}
                    </button>
                </div>
            </div>
        `;
        // Marcar "Sin cambio" como selected al inicio
        document.querySelector('.bulk-canal-opt').classList.add('selected');
    };

    window.actualizarBulkCanal = function(radio) {
        document.querySelectorAll('.bulk-canal-opt').forEach(lbl => {
            const inp = lbl.querySelector('input');
            lbl.classList.toggle('selected', inp && inp.checked);
        });
    };

    window.actualizarBulkEvento = function(chk, key) {
        const lbl = document.getElementById('bev-label-' + key);
        const sel = document.getElementById('bev-val-'   + key);
        if (lbl) lbl.classList.toggle('checked', chk.checked);
        if (sel) sel.disabled = !chk.checked;
    };

    window.cerrarBulkPanel = function() {
        const panel = document.getElementById('notif-panel-form');
        panel.className = 'notif-panel-form';
        panel.innerHTML = `<div class="notif-empty" style="padding:80px 30px">
            <div class="notif-empty-icon">👤</div>
            <div class="notif-empty-title">Selecciona una persona</div>
            <div class="notif-empty-sub">Haz clic en un nombre para ver y editar sus preferencias.</div>
        </div>`;
    };

    // ── Guardar masivo ───────────────────────────────────────────────────────
    window.guardarMasivo = async function() {
        const btn    = document.getElementById('btn-guardar-masivo');
        const status = document.getElementById('bulk-save-status');
        if (!_seleccionados.size) return;

        const payload = { ids: [..._seleccionados] };

        // Canal (solo si no es "sin cambio")
        const canalVal = document.querySelector('[name="bulk-canal"]:checked')?.value;
        if (canalVal) payload.canal = canalVal;

        // Eventos (solo los chequeados)
        const eventos = ['notif_credenciales','notif_pago','notif_cuota_vencer','notif_contrato','notif_reporte','notif_incidencia'];
        eventos.forEach(k => {
            const chk = document.getElementById('bev-' + k);
            if (chk && chk.checked) {
                const sel = document.getElementById('bev-val-' + k);
                payload[k] = sel ? parseInt(sel.value) : 1;
            }
        });

        btn.disabled = true; btn.textContent = '⏳ Guardando…';
        if (status) status.innerHTML = '';

        try {
            const r = await fetch(`${API}?action=guardar_masivo&id_empresa=${ID_CENTRO}`, {
                method: 'POST', credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload)
            });
            const d = await r.json();
            if (d.success) {
                if (status) status.innerHTML = `<span style="color:#166534">✅ ${esc(d.mensaje)}</span>`;
                if (typeof showToast === 'function') showToast(d.mensaje, 'success');
                // Si se cambió canal, actualizar badges
                if (payload.canal) {
                    const canalLabel = {email:'Email',whatsapp:'WhatsApp',ambos:'Ambos',ninguno:'—'}[payload.canal];
                    _seleccionados.forEach(id => {
                        const badge = document.querySelector(`#pitem-${id} .notif-person-canal`);
                        if (badge) {
                            badge.className = `notif-person-canal ${payload.canal}`;
                            badge.textContent = canalLabel;
                        }
                        // Actualizar en _resultados también
                        const idx = _resultados.findIndex(p => p.id === id);
                        if (idx >= 0) _resultados[idx].canal = payload.canal;
                    });
                }
                limpiarSeleccion();
                cerrarBulkPanel();
                setTimeout(()=>{ if(status) status.innerHTML = ''; }, 4000);
            } else {
                if (status) status.innerHTML = `<span style="color:#b91c1c">❌ ${esc(d.error||'Error')}</span>`;
                if (typeof showToast === 'function') showToast(d.error || 'Error al guardar', 'error');
            }
        } catch {
            if (status) status.innerHTML = '<span style="color:#b91c1c">❌ Error de conexión</span>';
        } finally {
            btn.disabled = false;
            btn.textContent = `💾 Aplicar a ${_seleccionados.size} persona${_seleccionados.size>1?'s':''}`;
        }
    };

    // ── Seleccionar persona individual ───────────────────────────────────────
    window.seleccionarPersona = async function(id) {
        // Si hay selección masiva activa, no abrir form individual
        if (_seleccionados.size > 0) {
            toggleSeleccion(id, !_seleccionados.has(id));
            const chk = document.getElementById('chk-' + id);
            if (chk) chk.checked = _seleccionados.has(id);
            return;
        }

        _personaActual = id;
        document.querySelectorAll('.notif-person-item').forEach(el => el.classList.remove('active'));
        const item = document.getElementById('pitem-' + id);
        if (item) item.classList.add('active');

        const panel = document.getElementById('notif-panel-form');
        panel.className = 'notif-panel-form';
        panel.innerHTML = `<div class="notif-empty" style="padding:80px 30px">
            <div class="notif-empty-icon" style="animation:spin 1s linear infinite">⏳</div>
            <div class="notif-empty-sub">Cargando preferencias…</div>
        </div>`;

        try {
            const r = await fetch(`${API}?action=cargar&id_empresa=${ID_CENTRO}&id_persona=${id}`);
            const d = await r.json();
            if (d.error) { panel.innerHTML = `<div class="notif-empty"><div class="notif-empty-sub" style="color:red">${esc(d.error)}</div></div>`; return; }
            renderForm(d.persona);
        } catch {
            panel.innerHTML = `<div class="notif-empty"><div class="notif-empty-icon">⚠️</div><div class="notif-empty-sub">Error al cargar</div></div>`;
        }
    };

    // ── Renderizar formulario individual ─────────────────────────────────────
    function renderForm(p) {
        const panel = document.getElementById('notif-panel-form');
        panel.className = 'notif-panel-form';
        const canal = p.canal || 'email';
        const ini   = iniciales(p.nombre);

        const canales = [
            { val: 'email',    icon: '📧', label: 'Solo Email'    },
            { val: 'whatsapp', icon: '📱', label: 'Solo WhatsApp' },
            { val: 'ambos',    icon: '📲', label: 'Ambos'         },
            { val: 'ninguno',  icon: '🔕', label: 'Ninguno'       },
        ];

        const todosLosEventos = [
            { key: 'notif_credenciales', icon: '🔑', label: 'Nuevas credenciales',     desc: 'Usuario y contraseña al crear acceso'        },
            { key: 'notif_contrato',    icon: '📋', label: 'Confirmación contrato',  desc: 'Al registrar o actualizar contrato'          },
            { key: 'notif_pago',         icon: '✅', label: 'Confirmación de pago',    desc: 'Al registrar un pago de cuota'                },
            { key: 'notif_cuota_vencer', icon: '⚠️', label: 'Cuota por vencer',        desc: '3 días antes del vencimiento'                 },
            { key: 'notif_reporte',      icon: '📊', label: 'Reporte disponible',      desc: 'Al publicar reportes del período'       },
            { key: 'notif_incidencia',   icon: '⚡', label: 'Incidencia disciplinar',  desc: 'Al registrar una incidencia sobre la persona' },
        ];

        const eventosPorTipo = {
            'Garante':      ['notif_credenciales','notif_contrato','notif_pago','notif_cuota_vencer','notif_reporte','notif_incidencia'],
            'Cliente': ['notif_credenciales','notif_reporte'],
            'Empleado':   ['notif_credenciales'],
            'Asesor':    ['notif_credenciales','notif_reporte'],
            'Supervisor': ['notif_credenciales','notif_incidencia'],
            'Consultor':  ['notif_credenciales','notif_incidencia'],
        };

        const tiposPersona = Array.isArray(p.tipos) && p.tipos.length ? p.tipos : [(p.tipo||'').trim()];
        const clavesUnion  = new Set();
        tiposPersona.forEach(t => (eventosPorTipo[t]||[]).forEach(c => clavesUnion.add(c)));
        const eventos = todosLosEventos.filter(e => clavesUnion.has(e.key));

        panel.innerHTML = `
            <div class="notif-form-header">
                <div class="notif-form-avatar">${ini}</div>
                <div>
                    <h3 class="notif-form-title">${esc(p.nombre)}</h3>
                    <p class="notif-form-subtitle">
                        ${(Array.isArray(p.tipos) && p.tipos.length > 1
                            ? p.tipos.map(t => `<span style="display:inline-block;padding:1px 7px;border-radius:10px;background:#dbeafe;color:#1e40af;font-size:.68rem;font-weight:700;margin-right:3px">${t}</span>`).join('')
                            : esc(p.tipo||'')
                        )} · ID ${p.id}
                    </p>
                </div>
            </div>

            <div class="notif-form-body">
                <p class="form-section-label">📡 Canal de envío</p>
                <div class="canal-grid" id="canal-grid">
                    ${canales.map(c => `
                    <label class="canal-option${canal===c.val?' selected':''}">
                        <input type="radio" name="nd-canal" value="${c.val}" ${canal===c.val?'checked':''}
                               onchange="actualizarCanal(this)">
                        <span class="canal-option-icon">${c.icon}</span>
                        <span class="canal-option-label">${c.label}</span>
                    </label>`).join('')}
                </div>

                <p class="form-section-label">📬 Datos de contacto</p>
                <div class="contact-grid">
                    <div class="form-field">
                        <label>📧 Email personal</label>
                        <input type="email" id="nd-email" value="${esc(p.pref_email||'')}" placeholder="correo@ejemplo.com">
                    </div>
                    <div class="form-field">
                        <label>📱 WhatsApp</label>
                        <input type="tel" id="nd-wapp" value="${esc(p.pref_whatsapp||'')}" placeholder="+18091234567">
                    </div>
                </div>

                <p class="form-section-label">🔔 Eventos a notificar</p>
                <div class="eventos-grid">
                    ${eventos.map(ev => {
                        const chk = p[ev.key] ? ' checked' : '';
                        return `<label class="evento-item${p[ev.key]?' checked':''}" id="ev-label-${ev.key}">
                            <input type="checkbox" id="nd-${ev.key}" name="${ev.key}"${chk}
                                   onchange="actualizarEvento(this,'${ev.key}')">
                            <div>
                                <div class="evento-label">${ev.icon} ${ev.label}</div>
                                <div class="evento-desc">${ev.desc}</div>
                            </div>
                        </label>`;
                    }).join('')}
                </div>
            </div>

            <div class="notif-form-footer">
                <span class="save-status" id="save-status"></span>
                <button class="btn btn-primary" id="btn-guardar" onclick="guardarPrefs()"
                        style="padding:10px 28px;font-size:.85rem">
                    💾 Guardar preferencias
                </button>
            </div>
        `;
    }

    window.actualizarCanal = function(radio) {
        document.querySelectorAll('.canal-option').forEach(lbl => {
            const inp = lbl.querySelector('input');
            lbl.classList.toggle('selected', inp && inp.checked);
        });
    };

    window.actualizarEvento = function(chk, key) {
        const lbl = document.getElementById('ev-label-' + key);
        if (lbl) lbl.classList.toggle('checked', chk.checked);
    };

    // ── Guardar individual ───────────────────────────────────────────────────
    window.guardarPrefs = async function() {
        if (!_personaActual) return;
        const btn    = document.getElementById('btn-guardar');
        const status = document.getElementById('save-status');

        const payload = {
            id_persona:         _personaActual,
            canal:              document.querySelector('[name="nd-canal"]:checked')?.value || 'email',
            email:              document.getElementById('nd-email')?.value  || '',
            whatsapp:           document.getElementById('nd-wapp')?.value   || '',
            notif_credenciales: document.getElementById('nd-notif_credenciales')?.checked ? 1 : 0,
            notif_pago:         document.getElementById('nd-notif_pago')?.checked          ? 1 : 0,
            notif_cuota_vencer: document.getElementById('nd-notif_cuota_vencer')?.checked  ? 1 : 0,
            notif_contrato:    document.getElementById('nd-notif_contrato')?.checked      ? 1 : 0,
            notif_reporte:      document.getElementById('nd-notif_reporte')?.checked        ? 1 : 0,
            notif_incidencia:   document.getElementById('nd-notif_incidencia')?.checked     ? 1 : 0,
        };

        btn.disabled = true; btn.textContent = '⏳ Guardando…';
        if (status) status.innerHTML = '';

        try {
            const r = await fetch(`${API}?action=guardar&id_empresa=${ID_CENTRO}`, {
                method: 'POST', credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload)
            });
            const d = await r.json();
            if (d.success) {
                if (status) status.innerHTML = '<span style="color:#166534">✅ Guardado correctamente</span>';
                if (typeof showToast === 'function') showToast('Preferencias guardadas ✓', 'success');
                const canalNuevo = payload.canal;
                const badge = document.querySelector(`#pitem-${_personaActual} .notif-person-canal`);
                if (badge) {
                    badge.className = `notif-person-canal ${canalNuevo}`;
                    badge.textContent = {email:'Email',whatsapp:'WhatsApp',ambos:'Ambos',ninguno:'—'}[canalNuevo];
                }
                setTimeout(()=>{ if(status) status.innerHTML = ''; }, 4000);
            } else {
                if (status) status.innerHTML = `<span style="color:#b91c1c">❌ ${esc(d.error||'Error')}</span>`;
                if (typeof showToast === 'function') showToast(d.error || 'Error al guardar', 'error');
            }
        } catch {
            if (status) status.innerHTML = '<span style="color:#b91c1c">❌ Error de conexión</span>';
        } finally {
            btn.disabled = false; btn.textContent = '💾 Guardar preferencias';
        }
    };

    // ── Helpers ──────────────────────────────────────────────────────────────
    function iniciales(nombre) {
        return (nombre||'?').split(' ').slice(0,2).map(w=>w[0]?.toUpperCase()||'').join('');
    }
    function esc(s) {
        return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
    }

    // Cargar todos al iniciar (Todos activo por defecto)
    ejecutarBusqueda();
})();
</script>
<div class="toast" id="toast"></div>
<script src="/GestionPrestamo/js/dashboard.js"></script>
</body>
</html>