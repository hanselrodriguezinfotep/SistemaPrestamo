<?php
// views/prestamos.php — GestionPrestamo
require_once __DIR__ . '/../config/session.php';
$sesion    = verificarSesion();
$iniciales = implode('', array_map(fn($w) => strtoupper($w[0]), array_slice(explode(' ', trim($sesion['nombre'] ?? 'U')), 0, 2))) ?: 'U';
$nombreRol = match($sesion['rol'] ?? '') {
    'superadmin' => 'Superadministrador', 'admin' => 'Administrador',
    'gerente'    => 'Gerente',  'supervisor' => 'Supervisor',
    'cajero'     => 'Cajero',   'cobrador'   => 'Cobrador',
    default      => ucfirst($sesion['rol'] ?? 'Usuario'),
};
$activePage = 'prestamos';
$pageTitle  = 'Préstamos';
$esAdmin    = in_array($sesion['rol'], ['superadmin','admin','gerente','supervisor','cajero'], true);
?>
<!DOCTYPE html>
<html lang="es">
<head>
<?php require_once __DIR__ . '/../php/partials/head.php'; ?>
<style>
/* ── Tabla ── */
.table-wrap { overflow-x: auto; }
table { width: 100%; border-collapse: collapse; font-size: .84rem; }
thead th { background: var(--bg); padding: 10px 14px; text-align: left; font-weight: 700; color: var(--muted); border-bottom: 1px solid var(--border); white-space: nowrap; }
tbody td { padding: 10px 14px; border-bottom: 1px solid var(--border); color: var(--text); vertical-align: middle; }
tbody tr:last-child td { border-bottom: none; }
tbody tr:hover td { background: #f8fafc; }

/* ── Badges ── */
.badge { display: inline-flex; align-items: center; padding: 3px 10px; border-radius: 99px; font-size: .73rem; font-weight: 700; }
.badge-activo    { background: #dcfce7; color: #15803d; }
.badge-pagado    { background: #ede9fe; color: #6d28d9; }
.badge-vencido   { background: #fee2e2; color: #b91c1c; }
.badge-cancelado { background: #f1f5f9; color: var(--muted); }

/* ── Filtros ── */
.filtros { display: flex; gap: 10px; flex-wrap: wrap; margin-bottom: 18px; align-items: center; }
.filtros input, .filtros select {
  padding: 8px 12px; border: 1.5px solid var(--border); border-radius: 9px;
  font-family: inherit; font-size: .82rem; color: var(--text); background: #fff;
  outline: none; transition: border-color .15s;
}
.filtros input:focus, .filtros select:focus { border-color: var(--primary); }

/* ── Modal backdrop ── */
.modal-backdrop {
  display: none; position: fixed; inset: 0;
  background: rgba(0,0,0,.45); z-index: 1000;
  align-items: center; justify-content: center; padding: 20px;
}
.modal-backdrop.open { display: flex; }
.modal {
  background: #fff; border-radius: 20px; padding: 32px;
  width: 100%; max-width: 580px; max-height: 92vh;
  overflow-y: auto; box-shadow: var(--shadow-lg);
  animation: fadeUp .2s ease both;
}
.modal-head { display: flex; justify-content: space-between; align-items: center; margin-bottom: 26px; }
.modal-head h3 { font-size: 1.05rem; font-weight: 800; }
.modal-close { background: none; border: none; font-size: 1.4rem; cursor: pointer; color: var(--muted); line-height: 1; padding: 4px; border-radius: 6px; transition: background .12s; }
.modal-close:hover { background: var(--bg); }

/* ── Formulario ── */
.form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin-bottom: 16px; }
.form-row.full { grid-template-columns: 1fr; }
.form-row.trio { grid-template-columns: 1fr 1fr 1fr; }
.form-group { display: flex; flex-direction: column; gap: 5px; }
.form-group label { font-size: .78rem; font-weight: 700; color: var(--muted); text-transform: uppercase; letter-spacing: .04em; }
.form-group input, .form-group select, .form-group textarea {
  padding: 9px 12px; border: 1.5px solid var(--border); border-radius: 9px;
  font-family: inherit; font-size: .85rem; color: var(--text);
  outline: none; transition: border-color .15s; background: #fff;
}
.form-group input:focus, .form-group select:focus, .form-group textarea:focus { border-color: var(--primary); }
.form-group textarea { resize: vertical; min-height: 72px; }
.form-sep { border: none; border-top: 1px solid var(--border); margin: 18px 0; }

/* ── Tipo préstamo (selector visual) ── */
.tipo-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 10px; margin-bottom: 16px; }
.tipo-btn {
  display: flex; flex-direction: column; align-items: center; justify-content: center;
  gap: 6px; padding: 14px 8px; border: 2px solid var(--border); border-radius: 12px;
  cursor: pointer; background: #fff; transition: all .15s; font-family: inherit;
  font-size: .78rem; font-weight: 600; color: var(--muted);
}
.tipo-btn .tipo-icon { font-size: 1.4rem; }
.tipo-btn:hover { border-color: #818cf8; background: #f5f3ff; color: #4338ca; }
.tipo-btn.selected { border-color: #6366f1; background: #ede9fe; color: #4338ca; font-weight: 800; }

/* ── Búsqueda cliente ── */
.client-search-wrap { position: relative; margin-bottom: 16px; }
.client-search-input {
  width: 100%; padding: 10px 14px; border: 1.5px solid var(--border); border-radius: 10px;
  font-family: inherit; font-size: .85rem; color: var(--text); outline: none;
  transition: border-color .15s;
}
.client-search-input:focus { border-color: var(--primary); }
.client-dropdown {
  position: absolute; top: calc(100% + 4px); left: 0; right: 0;
  background: #fff; border: 1.5px solid var(--border); border-radius: 10px;
  box-shadow: var(--shadow-lg); z-index: 10; max-height: 220px; overflow-y: auto;
  display: none;
}
.client-dropdown.open { display: block; }
.client-option {
  padding: 10px 14px; cursor: pointer; font-size: .84rem;
  border-bottom: 1px solid var(--border); transition: background .1s;
}
.client-option:last-child { border-bottom: none; }
.client-option:hover { background: #f1f5f9; }
.client-option strong { display: block; color: var(--text); }
.client-option span { font-size: .75rem; color: var(--muted); }

/* ── Amortización ── */
.amort-label { font-size: .78rem; font-weight: 700; color: var(--muted); text-transform: uppercase; letter-spacing: .04em; margin-bottom: 5px; display: block; }

/* ── Modal footer ── */
.modal-footer { display: flex; gap: 12px; justify-content: flex-end; margin-top: 24px; padding-top: 18px; border-top: 1px solid var(--border); }

/* ── Error box ── */
.err-box { background: #fee2e2; color: #b91c1c; padding: 10px 14px; border-radius: 9px; font-size: .82rem; font-weight: 600; margin: 12px 0; display: none; }

/* ── Paginación ── */
.pag-wrap { display: flex; gap: 8px; align-items: center; margin-top: 16px; flex-wrap: wrap; }
.pag-info { font-size: .78rem; color: var(--muted); }

/* ── Detail panel ── */
.detail-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
.detail-item label { font-size: .72rem; font-weight: 700; color: var(--muted); text-transform: uppercase; letter-spacing: .04em; display: block; margin-bottom: 2px; }
.detail-item span { font-size: .9rem; font-weight: 600; }

/* ── Responsive extra ── */
@media (max-width: 480px) {
  .tipo-grid { grid-template-columns: repeat(2,1fr); gap: 8px; }
  .tipo-btn { padding: 10px 6px; font-size: .72rem; }
  .tipo-btn .tipo-icon { font-size: 1.1rem; }
  .form-row.trio { grid-template-columns: 1fr 1fr; }
  .form-row.trio .form-group:last-child { grid-column: 1 / -1; }
  .modal { padding: 20px 16px; }
  .modal-footer { flex-direction: column; }
  .modal-footer .btn { width: 100%; justify-content: center; }
  table { font-size: .78rem; }
  thead th, tbody td { padding: 8px 10px; }
  .filtros { flex-direction: column; }
  .filtros input, .filtros select { width: 100%; }
  .stats-grid { grid-template-columns: 1fr 1fr; }
}
</style>
</head>
<body>
<div class="app">
<?php require_once __DIR__ . '/../php/partials/sidebar.php'; ?>
<div class="main">
  <header class="header">
    <button class="hamburger" onclick="toggleSidebar()">&#9776;</button>
    <div class="header-title"><h1>Préstamos</h1><p>Gestión de préstamos activos y cartera</p></div>
    <div class="header-actions">
      <?php if ($esAdmin): ?>
      <button class="btn btn-primary" onclick="abrirModal()">💰 Nuevo Préstamo</button>
      <?php endif; ?>
    </div>
  </header>

  <main class="page">

    <!-- KPIs -->
    <div class="stats-grid" id="kpis">
      <div class="stat-card"><div class="stat-icon" style="background:#dbeafe;color:var(--primary)">💰</div><div class="stat-body"><strong id="kTotal">—</strong><span>Total Préstamos</span></div></div>
      <div class="stat-card"><div class="stat-icon" style="background:#dcfce7;color:#15803d">✅</div><div class="stat-body"><strong id="kActivos">—</strong><span>Activos</span></div></div>
      <div class="stat-card"><div class="stat-icon" style="background:#fee2e2;color:#b91c1c">⚠️</div><div class="stat-body"><strong id="kVencidos">—</strong><span>Vencidos</span></div></div>
      <div class="stat-card"><div class="stat-icon" style="background:#fef9c3;color:#a16207">💵</div><div class="stat-body"><strong id="kPorCobrar">—</strong><span>Por Cobrar</span></div></div>
    </div>

    <!-- Tabla -->
    <div class="card">
      <div class="card-title">
        <span>📋 Listado de Préstamos</span>
        <div style="margin-left:auto">
          <button class="btn btn-ghost" onclick="exportarCSV()" style="font-size:.78rem;padding:6px 12px">⬇ CSV</button>
        </div>
      </div>

      <div class="filtros">
        <input type="text" id="fQ" placeholder="Buscar cliente o código..." style="flex:1;min-width:200px" oninput="buscar()">
        <select id="fEstado" onchange="cargar()">
          <option value="">Todos los estados</option>
          <option value="activo">Activo</option>
          <option value="pagado">Pagado</option>
          <option value="vencido">Vencido</option>
          <option value="cancelado">Cancelado</option>
        </select>
      </div>

      <div class="table-wrap">
        <table>
          <thead>
            <tr>
              <th>Código</th><th>Cliente</th><th>Monto</th><th>Cuota</th>
              <th>Pagado</th><th>Saldo</th><th>Estado</th><th>Vencimiento</th><th>Acciones</th>
            </tr>
          </thead>
          <tbody id="tbody">
            <tr><td colspan="9" style="text-align:center;padding:40px;color:var(--muted)">Cargando...</td></tr>
          </tbody>
        </table>
      </div>
      <div class="pag-wrap">
        <span class="pag-info" id="pagInfo"></span>
        <div id="pagBtns" style="display:flex;gap:6px;flex-wrap:wrap"></div>
      </div>
    </div>

  </main>
</div>
</div>

<!-- ══ MODAL NUEVO/EDITAR PRÉSTAMO ══════════════════════════ -->
<div class="modal-backdrop" id="modalBd">
<div class="modal">
  <div class="modal-head">
    <h3 id="mTitle">💰 Nuevo Préstamo</h3>
    <button class="modal-close" onclick="cerrarModal()">×</button>
  </div>

  <!-- Cliente -->
  <div style="margin-bottom:6px">
    <label class="amort-label">Cliente *</label>
    <div class="client-search-wrap">
      <input type="text" id="clienteSearch" class="client-search-input"
             placeholder="Buscar cliente por nombre o documento..."
             autocomplete="off" oninput="buscarCliente()">
      <div class="client-dropdown" id="clienteDropdown"></div>
    </div>
    <input type="hidden" id="clienteId">
  </div>

  <!-- Tipo de Préstamo -->
  <div style="margin-bottom:16px">
    <label class="amort-label">Tipo de Préstamo *</label>
    <div class="tipo-grid">
      <button type="button" class="tipo-btn selected" data-tipo="mensual"   onclick="selTipo(this)"><span class="tipo-icon">📅</span>Mensual</button>
      <button type="button" class="tipo-btn"           data-tipo="quincenal" onclick="selTipo(this)"><span class="tipo-icon">📆</span>Quincenal</button>
      <button type="button" class="tipo-btn"           data-tipo="semanal"   onclick="selTipo(this)"><span class="tipo-icon">🗓️</span>Semanal</button>
      <button type="button" class="tipo-btn"           data-tipo="diario"    onclick="selTipo(this)"><span class="tipo-icon">☀️</span>Diario</button>
      <button type="button" class="tipo-btn"           data-tipo="unico"     onclick="selTipo(this)"><span class="tipo-icon">💳</span>Único</button>
      <button type="button" class="tipo-btn"           data-tipo="custom"    onclick="selTipo(this)"><span class="tipo-icon">⚙️</span>Custom</button>
    </div>
    <input type="hidden" id="fTipo" value="mensual">
  </div>

  <!-- Tipo Amortización -->
  <div style="margin-bottom:16px">
    <label class="amort-label">Tipo Amortización</label>
    <select id="fAmort" style="width:100%;padding:9px 12px;border:1.5px solid var(--border);border-radius:9px;font-family:inherit;font-size:.85rem;color:var(--text);outline:none;background:#fff">
      <option value="frances">Francés (cuota fija)</option>
      <option value="aleman">Alemán (capital fijo)</option>
      <option value="americano">Americano (solo interés)</option>
    </select>
  </div>

  <hr class="form-sep">

  <!-- Monto / Tasa -->
  <div class="form-row trio">
    <div class="form-group">
      <label>Monto *</label>
      <input type="number" id="fMonto" step="0.01" placeholder="0.00" oninput="previsualizarCuota()">
    </div>
    <div class="form-group">
      <label>Tasa (%)</label>
      <input type="number" id="fTasa" step="0.01" value="24" oninput="previsualizarCuota()">
    </div>
    <div class="form-group">
      <label>Período Tasa</label>
      <select id="fPeriodoTasa" onchange="previsualizarCuota()">
        <option value="anual">Anual</option>
        <option value="semestral">Semestral</option>
        <option value="cuatrimestral">Cuatrimestral</option>
        <option value="trimestral">Trimestral</option>
        <option value="bimestral">Bimestral</option>
        <option value="mensual">Mensual</option>
        <option value="quincenal">Quincenal</option>
        <option value="semanal">Semanal</option>
        <option value="diario">Diario</option>
      </select>
    </div>
  </div>

  <!-- Cuotas / Fecha -->
  <div class="form-row">
    <div class="form-group">
      <label>Número de Cuotas *</label>
      <input type="number" id="fCuotas" value="12" min="1" oninput="previsualizarCuota()">
    </div>
    <div class="form-group">
      <label>Fecha Inicio *</label>
      <input type="date" id="fFecha" value="<?= date('Y-m-d') ?>">
    </div>
  </div>

  <!-- Preview cuota -->
  <div id="previewCuota" style="display:none;background:#f0f9ff;border:1px solid #bae6fd;border-radius:10px;padding:12px 16px;margin-bottom:16px;font-size:.83rem;color:#0369a1">
    <strong>Vista previa:</strong> Cuota estimada: <strong id="previewMonto">—</strong> | Total: <strong id="previewTotal">—</strong>
  </div>

  <!-- Propósito -->
  <div class="form-row full">
    <div class="form-group">
      <label>Propósito</label>
      <input type="text" id="fProposito" placeholder="Capital de trabajo, consumo...">
    </div>
  </div>

  <!-- Notas -->
  <div class="form-row full">
    <div class="form-group">
      <label>Notas</label>
      <textarea id="fNotas" placeholder="Observaciones adicionales..."></textarea>
    </div>
  </div>

  <div class="err-box" id="errBox"></div>

  <div class="modal-footer">
    <button type="button" onclick="cerrarModal()" class="btn btn-ghost">Cancelar</button>
    <button type="button" onclick="guardar()" class="btn btn-primary" id="btnSave">💾 Crear Préstamo</button>
  </div>
</div>
</div>

<!-- ══ MODAL DETALLE ════════════════════════════════════════ -->
<div class="modal-backdrop" id="detalleBd">
<div class="modal" style="max-width:640px">
  <div class="modal-head">
    <h3 id="dTitle">Detalle del Préstamo</h3>
    <button class="modal-close" onclick="cerrarDetalle()">×</button>
  </div>
  <div id="detalleBody"></div>
  <div class="modal-footer">
    <button type="button" onclick="cerrarDetalle()" class="btn btn-ghost">Cerrar</button>
    <button type="button" id="btnPagar" class="btn btn-primary">💳 Registrar Pago</button>
  </div>
</div>
</div>

<?php require_once __DIR__ . '/../php/partials/footer.php'; ?>
<script>
// ── Estado ────────────────────────────────────────────────────
let pag = 1, busTimer, cliTimer, prestamoActual = null;

const fmt = n => 'RD$ ' + Number(n||0).toLocaleString('es-DO',{minimumFractionDigits:2});

// ── Carga KPIs ────────────────────────────────────────────────
async function cargarKpis() {
  try {
    const r = await fetch('/GestionPrestamo/api/estadisticas.php');
    const d = await r.json();
    document.getElementById('kTotal').textContent    = d.total_prestamos ?? 0;
    document.getElementById('kActivos').textContent  = d.activos ?? 0;
    document.getElementById('kVencidos').textContent = d.vencidos ?? 0;
    document.getElementById('kPorCobrar').textContent = fmt(d.total_por_cobrar);
  } catch(e) {}
}

// ── Carga tabla ───────────────────────────────────────────────
async function cargar(p = 1) {
  pag = p;
  const q      = document.getElementById('fQ').value;
  const estado = document.getElementById('fEstado').value;
  let url = `/GestionPrestamo/api/prestamos.php?page=${p}&per_page=15`;
  if (estado) url += '&estado=' + estado;
  if (q)      url += '&q=' + encodeURIComponent(q);

  const r = await fetch(url);
  const d = await r.json();
  const rows = d.data ?? [];

  document.getElementById('tbody').innerHTML = rows.length
    ? rows.map(p => `
      <tr>
        <td><code style="background:#f1f5f9;padding:2px 7px;border-radius:5px;font-size:.78rem">${p.codigo}</code></td>
        <td><strong>${p.cliente ?? '—'}</strong></td>
        <td>${fmt(p.monto_principal)}</td>
        <td>${fmt(p.cuota_mensual ?? p.cuota_monto)}</td>
        <td style="color:var(--success)">${fmt(p.total_pagado)}</td>
        <td><strong>${fmt(p.saldo_pendiente)}</strong></td>
        <td><span class="badge badge-${p.estado}">${p.estado}</span></td>
        <td style="color:var(--muted);font-size:.8rem">${p.fecha_vencimiento ?? '—'}</td>
        <td style="white-space:nowrap">
          <button class="btn btn-ghost" style="padding:5px 10px;font-size:.75rem" onclick="verDetalle(${p.id})">Ver</button>
        </td>
      </tr>`).join('')
    : '<tr><td colspan="9" style="text-align:center;padding:40px;color:var(--muted)">Sin préstamos registrados</td></tr>';

  const pg = d.paginacion;
  document.getElementById('pagInfo').textContent = pg ? `${pg.total} registros` : '';
  const btns = document.getElementById('pagBtns');
  btns.innerHTML = '';
  for (let i = 1; i <= (pg?.paginas ?? 1); i++) {
    const b = document.createElement('button');
    b.textContent = i;
    b.className   = 'btn ' + (i === pag ? 'btn-primary' : 'btn-ghost');
    b.style.padding = '5px 12px';
    b.style.fontSize = '.78rem';
    b.onclick = () => cargar(i);
    btns.appendChild(b);
  }
}

function buscar() { clearTimeout(busTimer); busTimer = setTimeout(() => cargar(1), 380); }

// ── Búsqueda cliente ──────────────────────────────────────────
let cliSelected = false;
function buscarCliente() {
  cliSelected = false;
  document.getElementById('clienteId').value = '';
  clearTimeout(cliTimer);
  const q = document.getElementById('clienteSearch').value;
  if (q.length < 2) { document.getElementById('clienteDropdown').classList.remove('open'); return; }
  cliTimer = setTimeout(async () => {
    const r = await fetch('/GestionPrestamo/api/clientes.php?q=' + encodeURIComponent(q) + '&per_page=8');
    const d = await r.json();
    const rows = d.data ?? [];
    const dd = document.getElementById('clienteDropdown');
    dd.innerHTML = rows.length
      ? rows.map(c => `
          <div class="client-option" onclick="selCliente(${c.id},'${(c.nombre+' '+c.apellido).replace(/'/g,"\\'")}','${c.documento}')">
            <strong>${c.nombre} ${c.apellido}</strong>
            <span>${c.documento}${c.telefono?' · '+c.telefono:''}</span>
          </div>`).join('')
      : '<div class="client-option" style="color:var(--muted)">Sin resultados</div>';
    dd.classList.add('open');
  }, 300);
}

function selCliente(id, nombre, doc) {
  document.getElementById('clienteId').value    = id;
  document.getElementById('clienteSearch').value = nombre + ' — ' + doc;
  document.getElementById('clienteDropdown').classList.remove('open');
  cliSelected = true;
}
document.addEventListener('click', e => {
  if (!e.target.closest('.client-search-wrap'))
    document.getElementById('clienteDropdown').classList.remove('open');
});

// ── Tipo préstamo ─────────────────────────────────────────────
function selTipo(btn) {
  document.querySelectorAll('.tipo-btn').forEach(b => b.classList.remove('selected'));
  btn.classList.add('selected');
  document.getElementById('fTipo').value = btn.dataset.tipo;
  previsualizarCuota();
}

// ── Preview cuota ─────────────────────────────────────────────
function previsualizarCuota() {
  const monto  = parseFloat(document.getElementById('fMonto').value) || 0;
  const tasa   = parseFloat(document.getElementById('fTasa').value)  || 0;
  const cuotas = parseInt(document.getElementById('fCuotas').value)  || 0;
  const periodo = document.getElementById('fPeriodoTasa').value;
  const amort  = document.getElementById('fAmort').value;

  if (!monto || !tasa || !cuotas) {
    document.getElementById('previewCuota').style.display = 'none'; return;
  }

  // Periodos por año para cada período de tasa
  const periodosPorAnio = {
    anual: 1, semestral: 2, cuatrimestral: 3, trimestral: 4,
    bimestral: 6, mensual: 12, quincenal: 24, semanal: 52, diario: 365
  };
  // Periodos por año según frecuencia del préstamo
  const freqPorAnio = {
    mensual: 12, quincenal: 24, semanal: 52, diario: 365, unico: 1, custom: 12
  };
  const tipo = document.getElementById('fTipo').value;
  // Tasa efectiva anual → tasa por período de cuota
  const tasaAnual = Math.pow(1 + (tasa/100) / periodosPorAnio[periodo], periodosPorAnio[periodo]) - 1;
  const freq = freqPorAnio[tipo] || 12;
  const tasaP = Math.pow(1 + tasaAnual, 1 / freq) - 1;

  let cuotaMonto, total;
  if (amort === 'frances') {
    cuotaMonto = tasaP === 0
      ? monto / cuotas
      : monto * (tasaP * Math.pow(1+tasaP, cuotas)) / (Math.pow(1+tasaP, cuotas) - 1);
    total = cuotaMonto * cuotas;
  } else if (amort === 'aleman') {
    const cap = monto / cuotas;
    cuotaMonto = cap + monto * tasaP; // primera cuota
    total = monto + (monto * tasaP * (cuotas + 1)) / 2;
  } else { // americano
    cuotaMonto = monto * tasaP; // solo interés
    total = cuotaMonto * cuotas + monto;
  }

  document.getElementById('previewMonto').textContent = fmt(cuotaMonto);
  document.getElementById('previewTotal').textContent = fmt(total);
  document.getElementById('previewCuota').style.display = 'block';
}

// ── Modal ─────────────────────────────────────────────────────
function abrirModal() {
  document.getElementById('modalBd').classList.add('open');
  document.getElementById('mTitle').textContent = '💰 Nuevo Préstamo';
  document.getElementById('clienteSearch').value = '';
  document.getElementById('clienteId').value = '';
  document.getElementById('fTipo').value = 'mensual';
  document.querySelectorAll('.tipo-btn').forEach((b,i) => b.classList.toggle('selected', i===0));
  document.getElementById('fAmort').value    = 'frances';
  document.getElementById('fMonto').value    = '';
  document.getElementById('fTasa').value     = '24';
  document.getElementById('fCuotas').value   = '12';
  document.getElementById('fFecha').value    = new Date().toISOString().slice(0,10);
  document.getElementById('fProposito').value = '';
  document.getElementById('fNotas').value    = '';
  document.getElementById('previewCuota').style.display = 'none';
  document.getElementById('errBox').style.display = 'none';
  document.getElementById('btnSave').textContent = '💾 Crear Préstamo';
}

function cerrarModal() { document.getElementById('modalBd').classList.remove('open'); }
document.getElementById('modalBd').addEventListener('click', e => { if (e.target === e.currentTarget) cerrarModal(); });

// ── Guardar préstamo ──────────────────────────────────────────
async function guardar() {
  const clienteId = document.getElementById('clienteId').value;
  const monto     = parseFloat(document.getElementById('fMonto').value);
  const cuotas    = parseInt(document.getElementById('fCuotas').value);

  if (!clienteId) { mostrarError('Debes seleccionar un cliente'); return; }
  if (!monto || monto <= 0) { mostrarError('Ingresa un monto válido'); return; }
  if (!cuotas || cuotas < 1) { mostrarError('Ingresa el número de cuotas'); return; }

  const body = {
    cliente_id:   parseInt(clienteId),
    tipo:         document.getElementById('fTipo').value,
    amortizacion: document.getElementById('fAmort').value,
    monto_principal: monto,
    tasa_interes: parseFloat(document.getElementById('fTasa').value),
    periodo_tasa: document.getElementById('fPeriodoTasa').value,
    plazo_meses:  cuotas,
    fecha_inicio: document.getElementById('fFecha').value,
    proposito:    document.getElementById('fProposito').value,
    notas:        document.getElementById('fNotas').value,
  };

  const btn = document.getElementById('btnSave');
  btn.disabled = true; btn.textContent = 'Guardando...';

  try {
    const r = await fetch('/GestionPrestamo/api/prestamos.php', {
      method: 'POST', headers: {'Content-Type':'application/json'}, body: JSON.stringify(body)
    });
    const d = await r.json();
    if (d.error) { mostrarError(d.error); return; }
    cerrarModal();
    cargar(pag);
    cargarKpis();
    toast('Préstamo creado correctamente', 'success');
  } finally {
    btn.disabled = false; btn.textContent = '💾 Crear Préstamo';
  }
}

function mostrarError(msg) {
  const el = document.getElementById('errBox');
  el.textContent = msg; el.style.display = 'block';
  el.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
}

// ── Detalle préstamo ──────────────────────────────────────────
async function verDetalle(id) {
  const r = await fetch('/GestionPrestamo/api/prestamos.php?id=' + id);
  const p = await r.json();
  prestamoActual = p;

  document.getElementById('dTitle').textContent = '📋 ' + (p.codigo ?? 'Préstamo');
  document.getElementById('detalleBody').innerHTML = `
    <div class="detail-grid" style="margin-bottom:20px">
      <div class="detail-item"><label>Cliente</label><span>${p.cliente ?? '—'}</span></div>
      <div class="detail-item"><label>Estado</label><span class="badge badge-${p.estado}">${p.estado}</span></div>
      <div class="detail-item"><label>Capital</label><span>${fmt(p.monto_principal)}</span></div>
      <div class="detail-item"><label>Cuota</label><span>${fmt(p.cuota_mensual ?? p.cuota_monto)}</span></div>
      <div class="detail-item"><label>Total</label><span>${fmt(p.monto_total)}</span></div>
      <div class="detail-item"><label>Pagado</label><span style="color:var(--success)">${fmt(p.total_pagado)}</span></div>
      <div class="detail-item"><label>Saldo</label><span><strong>${fmt(p.saldo_pendiente)}</strong></span></div>
      <div class="detail-item"><label>Tasa</label><span>${p.tasa_interes ?? '—'}%</span></div>
      <div class="detail-item"><label>Inicio</label><span>${p.fecha_inicio ?? '—'}</span></div>
      <div class="detail-item"><label>Vencimiento</label><span>${p.fecha_vencimiento ?? '—'}</span></div>
      ${p.proposito ? `<div class="detail-item" style="grid-column:1/-1"><label>Propósito</label><span>${p.proposito}</span></div>` : ''}
    </div>
    ${await renderCuotas(id)}
  `;
  document.getElementById('btnPagar').onclick = () => {
    cerrarDetalle();
    window.location.href = '/GestionPrestamo/pagos?prestamo_id=' + id;
  };
  document.getElementById('detalleBd').classList.add('open');
}

async function renderCuotas(prestamoId) {
  try {
    const r = await fetch('/GestionPrestamo/api/cuotas.php?prestamo_id=' + prestamoId);
    const d = await r.json();
    const cuotas = d.data ?? d ?? [];
    if (!cuotas.length) return '<p style="color:var(--muted);font-size:.84rem">Sin cuotas registradas</p>';
    return `
      <p style="font-size:.78rem;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.04em;margin-bottom:8px">Calendario de Cuotas</p>
      <div style="overflow-x:auto;max-height:240px;overflow-y:auto">
        <table>
          <thead><tr><th>#</th><th>Vence</th><th>Monto</th><th>Estado</th></tr></thead>
          <tbody>
            ${cuotas.map(c => `
              <tr>
                <td style="font-weight:700">${c.numero ?? c.numero_cuota}</td>
                <td style="font-size:.8rem;color:var(--muted)">${c.fecha_vence ?? c.fecha_vencimiento}</td>
                <td>${fmt(c.monto_total ?? c.monto_cuota)}</td>
                <td><span class="badge badge-${c.estado ?? (c.pagado ? 'pagado' : 'activo')}">${c.estado ?? (c.pagado ? 'pagado' : 'pendiente')}</span></td>
              </tr>`).join('')}
          </tbody>
        </table>
      </div>`;
  } catch(e) { return ''; }
}

function cerrarDetalle() { document.getElementById('detalleBd').classList.remove('open'); }
document.getElementById('detalleBd').addEventListener('click', e => { if (e.target === e.currentTarget) cerrarDetalle(); });

// ── CSV export ────────────────────────────────────────────────
async function exportarCSV() {
  const r = await fetch('/GestionPrestamo/api/prestamos.php?per_page=1000');
  const d = await r.json();
  const rows = d.data ?? [];
  const header = ['Codigo','Cliente','Capital','Cuota','Pagado','Saldo','Estado','Inicio','Vencimiento'];
  const lines = [header.join(',')];
  rows.forEach(p => lines.push([p.codigo,p.cliente,p.monto_principal,p.cuota_mensual??p.cuota_monto,p.total_pagado,p.saldo_pendiente,p.estado,p.fecha_inicio,p.fecha_vencimiento].join(',')));
  const a = document.createElement('a');
  a.href = 'data:text/csv;charset=utf-8,' + encodeURIComponent(lines.join('\n'));
  a.download = 'prestamos.csv'; a.click();
}

// ── Toast ─────────────────────────────────────────────────────
function toast(msg, tipo = 'success') {
  let t = document.getElementById('_toast');
  if (!t) { t = document.createElement('div'); t.id = '_toast'; t.className = 'toast'; document.body.appendChild(t); }
  t.textContent = msg; t.className = 'toast ' + tipo;
  setTimeout(() => t.classList.add('show'), 10);
  setTimeout(() => t.classList.remove('show'), 3200);
}

// ── Init ──────────────────────────────────────────────────────
cargarKpis();
cargar();
</script>
</body>
</html>