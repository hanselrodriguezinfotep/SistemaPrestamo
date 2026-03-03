<?php
// views/mora.php — Cartera en Mora | GestionPrestamo
require_once __DIR__ . '/../config/session.php';
$sesion = verificarSesion();

$roles = ['superadmin','admin','gerente','supervisor','cajero'];
if (!in_array($sesion['rol'], $roles, true)) { header('Location: /GestionPrestamo/'); exit; }

$esSuperadmin = $sesion['rol'] === 'superadmin';
$puedeActualizar = in_array($sesion['rol'], ['superadmin','admin','gerente'], true);
$activePage   = 'mora';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <?php $pageTitle = 'Cartera en Mora'; require_once __DIR__ . '/../php/partials/head.php'; ?>
    <style>
        /* ── KPIs ─────────────────────────────────────────────────── */
        .kpi-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
            gap: 14px;
            margin-bottom: 22px;
        }
        .kpi {
            background: var(--surface);
            border: 1.5px solid var(--border);
            border-radius: 12px;
            padding: 16px 18px;
            box-shadow: var(--shadow);
        }
        .kpi-label {
            font-size: .7rem;
            font-weight: 800;
            color: var(--muted);
            text-transform: uppercase;
            letter-spacing: .06em;
            margin-bottom: 4px;
        }
        .kpi-val { font-size: 1.35rem; font-weight: 800; }
        .kpi.red   .kpi-val { color: #dc2626; }
        .kpi.amber .kpi-val { color: #d97706; }
        .kpi.green .kpi-val { color: #16a34a; }

        /* ── Toolbar ──────────────────────────────────────────────── */
        .toolbar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
            flex-wrap: wrap;
            margin-bottom: 16px;
        }
        .toolbar-actions { display: flex; gap: 8px; flex-wrap: wrap; }

        /* ── Tabla ────────────────────────────────────────────────── */
        .table-card {
            background: var(--surface);
            border: 1.5px solid var(--border);
            border-radius: 12px;
            overflow: hidden;
            box-shadow: var(--shadow);
        }
        .tbl-wrap { overflow-x: auto; -webkit-overflow-scrolling: touch; }
        table { width: 100%; border-collapse: collapse; font-size: .83rem; min-width: 640px; }
        thead th {
            background: var(--bg);
            padding: 10px 14px;
            text-align: left;
            font-size: .7rem;
            font-weight: 800;
            color: var(--muted);
            text-transform: uppercase;
            border-bottom: 1px solid var(--border);
            white-space: nowrap;
        }
        tbody tr { border-bottom: 1px solid var(--border); }
        tbody tr:hover { background: var(--bg); }
        tbody td { padding: 10px 14px; vertical-align: middle; }

        /* ── Badges ───────────────────────────────────────────────── */
        .dias-badge {
            display: inline-flex;
            align-items: center;
            padding: 3px 10px;
            border-radius: 99px;
            font-size: .7rem;
            font-weight: 800;
        }
        .dias-1-30  { background: #fef9c3; color: #854d0e; }
        .dias-31-60 { background: #fed7aa; color: #9a3412; }
        .dias-60p   { background: #fee2e2; color: #dc2626; }

        /* ── Empty / loading state ────────────────────────────────── */
        .estado-cell {
            text-align: center;
            padding: 48px 20px;
            color: var(--muted);
            font-size: .9rem;
        }

        /* ── RESPONSIVE ───────────────────────────────────────────── */
        @media (max-width: 768px) {
            .kpi-grid { grid-template-columns: repeat(2, 1fr); gap: 10px; }
            .kpi { padding: 12px 14px; }
            .kpi-val { font-size: 1.1rem; }
            .toolbar { flex-direction: column; align-items: flex-start; }
            .toolbar-actions { width: 100%; }
            .toolbar-actions .btn { flex: 1; justify-content: center; min-height: 44px; }
        }
        @media (max-width: 480px) {
            .kpi-grid { grid-template-columns: 1fr 1fr; }
        }
    </style>
</head>
<body>
<div class="app">
    <?php require_once __DIR__ . '/../php/partials/sidebar.php'; ?>
    <div class="main-wrap">
        <header class="topbar">
            <button class="menu-toggle" onclick="toggleSidebar()">☰</button>
        </header>
        <main class="main-content">

            <!-- KPIs -->
            <div class="kpi-grid">
                <div class="kpi red">
                    <div class="kpi-label">Cuotas en Mora</div>
                    <div class="kpi-val" id="kpiCuotas">—</div>
                </div>
                <div class="kpi red">
                    <div class="kpi-label">Monto en Mora</div>
                    <div class="kpi-val" id="kpiMonto">—</div>
                </div>
                <div class="kpi amber">
                    <div class="kpi-label">1–30 días</div>
                    <div class="kpi-val" id="kpi30">—</div>
                </div>
                <div class="kpi amber">
                    <div class="kpi-label">31–60 días</div>
                    <div class="kpi-val" id="kpi60">—</div>
                </div>
                <div class="kpi red">
                    <div class="kpi-label">+60 días</div>
                    <div class="kpi-val" id="kpi60p">—</div>
                </div>
            </div>

            <!-- Toolbar -->
            <div class="toolbar">
                <h2 style="font-size:1rem;font-weight:800;margin:0">⚠️ Cartera en Mora</h2>
                <div class="toolbar-actions">
                    <?php if ($puedeActualizar): ?>
                    <button class="btn" id="btn-calcular" onclick="calcularMora()">🔄 Actualizar Mora</button>
                    <?php endif; ?>
                    <button class="btn btn-primary" onclick="cargar()">↻ Recargar</button>
                </div>
            </div>

            <!-- Tabla -->
            <div class="table-card">
                <div class="tbl-wrap">
                    <table>
                        <thead>
                            <tr>
                                <th>Préstamo</th>
                                <th>Cliente</th>
                                <th>Cuota #</th>
                                <th>Fecha Vence</th>
                                <th>Días Mora</th>
                                <th>Saldo Cuota</th>
                                <th>Mora Estimada</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody id="tbody">
                            <tr><td colspan="8" class="estado-cell">Cargando…</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>

        </main>
    </div>
</div>

<script>
const API = '/GestionPrestamo/api/cuotas.php';

document.addEventListener('DOMContentLoaded', cargar);

// ── Cargar tabla de mora ──────────────────────────────────────────────────────
async function cargar() {
    const tbody = document.getElementById('tbody');
    tbody.innerHTML = '<tr><td colspan="8" class="estado-cell">Cargando…</td></tr>';

    try {
        const r = await fetch(API + '?action=mora');
        if (!r.ok) throw new Error('Error HTTP ' + r.status);
        const j = await r.json();
        if (!j.ok) throw new Error(j.error || 'Error desconocido');

        renderTabla(j.data);
    } catch (e) {
        tbody.innerHTML = `<tr><td colspan="8" class="estado-cell" style="color:#dc2626">❌ ${esc(e.message)}</td></tr>`;
    }
}

// ── Renderizar tabla y KPIs ───────────────────────────────────────────────────
function renderTabla(data) {
    const tbody = document.getElementById('tbody');

    // KPIs
    const montoTotal = data.reduce((a, c) => a + parseFloat(c.monto_total) - parseFloat(c.monto_pagado), 0);
    setText('kpiCuotas', data.length);
    setText('kpiMonto',  fmt(montoTotal));
    setText('kpi30',     data.filter(c => parseInt(c.dias_mora_real) <= 30).length);
    setText('kpi60',     data.filter(c => parseInt(c.dias_mora_real) > 30 && parseInt(c.dias_mora_real) <= 60).length);
    setText('kpi60p',    data.filter(c => parseInt(c.dias_mora_real) > 60).length);

    if (!data.length) {
        tbody.innerHTML = '<tr><td colspan="8" class="estado-cell">✅ No hay cuotas en mora</td></tr>';
        return;
    }

    tbody.innerHTML = data.map(c => {
        const dias   = parseInt(c.dias_mora_real) || 0;
        const dcls   = dias <= 30 ? 'dias-1-30' : dias <= 60 ? 'dias-31-60' : 'dias-60p';
        const saldo  = parseFloat(c.monto_total) - parseFloat(c.monto_pagado);
        const mora   = saldo * 0.02 * (dias / 30);
        return `<tr>
            <td><strong style="font-family:monospace;font-size:.8rem">${esc(c.prestamo_codigo)}</strong></td>
            <td>
                <div style="font-weight:600">${esc(c.cliente_nombre)}</div>
                <div style="font-size:.72rem;color:var(--muted)">${esc(c.cedula || '')}</div>
            </td>
            <td style="font-weight:700">#${c.numero}</td>
            <td>${esc(c.fecha_vence)}</td>
            <td><span class="dias-badge ${dcls}">${dias} días</span></td>
            <td>${fmt(saldo)}</td>
            <td style="color:#dc2626;font-weight:700">~${fmt(mora)}</td>
            <td><a href="/GestionPrestamo/prestamos" class="btn btn-sm">Ver</a></td>
        </tr>`;
    }).join('');
}

// ── Calcular mora ─────────────────────────────────────────────────────────────
async function calcularMora() {
    if (!confirm('¿Actualizar el estado de mora de todas las cuotas vencidas?')) return;

    const btn = document.getElementById('btn-calcular');
    if (btn) { btn.disabled = true; btn.textContent = 'Actualizando…'; }

    try {
        const r = await fetch(API + '?action=calcular_mora', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ tasa_mora: 0.02 }),
        });
        const j = await r.json();
        if (j.ok) {
            mostrarToast('✅ ' + j.mensaje, 'success');
            cargar();
        } else {
            mostrarToast('❌ ' + (j.error || 'Error al calcular'), 'error');
        }
    } catch (e) {
        mostrarToast('❌ Error de conexión', 'error');
    } finally {
        if (btn) { btn.disabled = false; btn.textContent = '🔄 Actualizar Mora'; }
    }
}

// ── Utilidades ────────────────────────────────────────────────────────────────
function fmt(n) {
    return 'RD$ ' + parseFloat(n || 0).toLocaleString('es-DO', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}
function esc(s) {
    const d = document.createElement('div');
    d.textContent = s || '';
    return d.innerHTML;
}
function setText(id, val) {
    const el = document.getElementById(id);
    if (el) el.textContent = val;
}
function mostrarToast(msg, tipo) {
    let t = document.getElementById('_toast');
    if (!t) {
        t = document.createElement('div');
        t.id = '_toast';
        t.style.cssText = 'position:fixed;bottom:24px;right:24px;z-index:9999;padding:12px 20px;border-radius:10px;font-size:.85rem;font-weight:700;box-shadow:0 4px 20px rgba(0,0,0,.2);transition:opacity .3s';
        document.body.appendChild(t);
    }
    t.textContent = msg;
    t.style.background = tipo === 'success' ? '#16a34a' : '#dc2626';
    t.style.color = '#fff';
    t.style.opacity = '1';
    clearTimeout(t._to);
    t._to = setTimeout(() => t.style.opacity = '0', 3500);
}
</script>

<?php require_once __DIR__ . '/../php/partials/footer.php'; ?>
</body>
</html>