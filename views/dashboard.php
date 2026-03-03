<?php
// views/dashboard.php — GestionPrestamo | Dashboard Principal
require_once __DIR__ . '/../config/session.php';
$sesion = verificarSesion();
$rolesAdmin = ['superadmin','admin','gerente','supervisor','cajero'];
$esAdmin = in_array($sesion['rol'], $rolesAdmin, true);
$iniciales = implode('', array_map(fn($w) => strtoupper($w[0]), array_slice(explode(' ', trim($sesion['nombre'] ?? 'U')), 0, 2))) ?: 'U';
$nombreRol = match($sesion['rol'] ?? '') {
    'superadmin' => 'Superadministrador', 'admin' => 'Administrador',
    'gerente'    => 'Gerente',            'supervisor' => 'Supervisor',
    'cajero'     => 'Cajero',             'cobrador' => 'Cobrador',
    default      => ucfirst($sesion['rol'] ?? 'Usuario'),
};
$activePage = 'dashboard';
$pageTitle  = 'Dashboard';
?>
<!DOCTYPE html>
<html lang="es">
<head><?php require_once __DIR__ . '/../php/partials/head.php'; ?></head>
<body>
<div class="app">
<?php require_once __DIR__ . '/../php/partials/sidebar.php'; ?>
<div class="main">
  <header class="header">
    <button class="hamburger" onclick="toggleSidebar()">☰</button>
    <h1 class="header-title">Dashboard</h1>
    <div class="header-actions"></div>
  </header>
  <main class="page" id="dashboardPage">

    <!-- KPI Cards -->
    <div class="stats-grid" id="kpiCards">
      <div class="stat-card skeleton"></div>
      <div class="stat-card skeleton"></div>
      <div class="stat-card skeleton"></div>
      <div class="stat-card skeleton"></div>
    </div>

    <!-- Charts row -->
    <div class="grid-2" style="margin-top:24px">
      <div class="card">
        <div class="card-header"><h3>Préstamos por Estado</h3></div>
        <div class="card-body" id="chartEstados" style="min-height:220px;display:flex;align-items:center;justify-content:center"></div>
      </div>
      <div class="card">
        <div class="card-header"><h3>Cuotas Vencidas (Top 5)</h3></div>
        <div class="card-body" id="tblVencidas" style="overflow:auto"></div>
      </div>
    </div>

    <!-- Recent loans -->
    <div class="card" style="margin-top:24px">
      <div class="card-header">
        <h3>Últimos Préstamos</h3>
        <a href="/GestionPrestamo/prestamos" class="btn btn-sm btn-outline">Ver todos</a>
      </div>
      <div class="card-body" id="tblPrestamos" style="overflow:auto"></div>
    </div>

  </main>
</div>
</div>
<?php require_once __DIR__ . '/../php/partials/footer.php'; ?>
<script>
(async () => {
  // Load KPIs
  try {
    const r = await fetch('/GestionPrestamo/api/estadisticas.php');
    const d = await r.json();

    document.getElementById('kpiCards').innerHTML = `
      <div class="stat-card">
        <div class="stat-icon" style="background:var(--primary-l);color:var(--primary)">💰</div>
        <div class="stat-body">
          <p class="stat-label">Total Préstamos</p>
          <h2 class="stat-value">${d.total_prestamos ?? 0}</h2>
        </div>
      </div>
      <div class="stat-card">
        <div class="stat-icon" style="background:#d1fae5;color:#065f46">✅</div>
        <div class="stat-body">
          <p class="stat-label">Activos</p>
          <h2 class="stat-value">${d.activos ?? 0}</h2>
        </div>
      </div>
      <div class="stat-card">
        <div class="stat-icon" style="background:#fee2e2;color:#991b1b">⚠️</div>
        <div class="stat-body">
          <p class="stat-label">Vencidos</p>
          <h2 class="stat-value">${d.vencidos ?? 0}</h2>
        </div>
      </div>
      <div class="stat-card">
        <div class="stat-icon" style="background:#fef3c7;color:#92400e">💵</div>
        <div class="stat-body">
          <p class="stat-label">Por Cobrar</p>
          <h2 class="stat-value">RD$ ${Number(d.total_por_cobrar ?? 0).toLocaleString('es-DO', {minimumFractionDigits:2})}</h2>
        </div>
      </div>
    `;

    // Estados chart (simple bar)
    const estados = [
      {label:'Activos',   val: d.activos ?? 0,    color:'#10b77a'},
      {label:'Pagados',   val: d.pagados ?? 0,    color:'#5b4ef8'},
      {label:'Vencidos',  val: d.vencidos ?? 0,   color:'#f04438'},
      {label:'Cancelados',val: d.cancelados ?? 0, color:'#94a3b8'},
    ];
    const max = Math.max(...estados.map(e=>e.val), 1);
    document.getElementById('chartEstados').innerHTML = `
      <div style="width:100%;padding:8px 0">
        ${estados.map(e => `
          <div style="display:flex;align-items:center;gap:10px;margin-bottom:14px">
            <span style="width:80px;font-size:.82rem;color:#64748b;text-align:right;flex-shrink:0">${e.label}</span>
            <div style="flex:1;background:#f1f5f9;border-radius:99px;height:14px;overflow:hidden">
              <div style="width:${Math.round(e.val/max*100)}%;height:100%;background:${e.color};border-radius:99px;transition:width .6s"></div>
            </div>
            <span style="width:40px;font-size:.85rem;font-weight:700;color:#1e293b">${e.val}</span>
          </div>
        `).join('')}
        <div style="margin-top:16px;padding-top:12px;border-top:1px solid #f1f5f9;display:flex;gap:24px;flex-wrap:wrap">
          <div style="font-size:.8rem;color:#64748b">Capital Colocado: <strong>RD$ ${Number(d.total_capital_colocado??0).toLocaleString('es-DO',{minimumFractionDigits:2})}</strong></div>
          <div style="font-size:.8rem;color:#64748b">Total Cobrado: <strong>RD$ ${Number(d.total_cobrado??0).toLocaleString('es-DO',{minimumFractionDigits:2})}</strong></div>
        </div>
      </div>
    `;

    // Cuotas vencidas
    const cv = d.cuotas_vencidas_top ?? [];
    if (cv.length) {
      document.getElementById('tblVencidas').innerHTML = `
        <table class="table">
          <thead><tr><th>Cliente</th><th>Préstamo</th><th>Cuota</th><th>Días</th><th>Monto</th></tr></thead>
          <tbody>${cv.map(c=>`
            <tr>
              <td>${c.cliente ?? '—'}</td>
              <td><code>${c.codigo_prestamo ?? '—'}</code></td>
              <td>#${c.numero_cuota ?? '—'}</td>
              <td><span style="color:#f04438;font-weight:700">${c.dias_vencida ?? 0}d</span></td>
              <td>RD$ ${Number(c.monto_cuota??0).toLocaleString('es-DO',{minimumFractionDigits:2})}</td>
            </tr>`).join('')}
          </tbody>
        </table>`;
    } else {
      document.getElementById('tblVencidas').innerHTML = '<p style="color:#94a3b8;padding:20px;text-align:center">Sin cuotas vencidas ✅</p>';
    }
  } catch(e) {
    document.getElementById('kpiCards').innerHTML = '<p style="color:#f04438">Error cargando estadísticas</p>';
  }

  // Recent loans
  try {
    const r = await fetch('/GestionPrestamo/api/prestamos.php?per_page=5');
    const d = await r.json();
    const rows = d.data ?? [];
    document.getElementById('tblPrestamos').innerHTML = rows.length ? `
      <table class="table">
        <thead><tr><th>Código</th><th>Cliente</th><th>Monto</th><th>Estado</th><th>Vence</th></tr></thead>
        <tbody>${rows.map(p=>`
          <tr>
            <td><code>${p.codigo}</code></td>
            <td>${p.cliente}</td>
            <td>RD$ ${Number(p.monto_principal??0).toLocaleString('es-DO',{minimumFractionDigits:2})}</td>
            <td><span class="badge badge-${p.estado}">${p.estado}</span></td>
            <td>${p.fecha_vencimiento ?? '—'}</td>
          </tr>`).join('')}
        </tbody>
      </table>` : '<p style="color:#94a3b8;padding:20px;text-align:center">Sin préstamos registrados</p>';
  } catch(e) {}
})();
</script>
<style>
.stats-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:16px}
.stat-card{background:#fff;border-radius:14px;padding:20px;display:flex;gap:16px;align-items:center;box-shadow:0 1px 3px rgba(0,0,0,.06)}
.stat-card.skeleton{min-height:88px;background:linear-gradient(90deg,#f1f5f9 25%,#e2e8f0 50%,#f1f5f9 75%);background-size:200% 100%;animation:shimmer 1.4s infinite}
@keyframes shimmer{0%{background-position:200% 0}100%{background-position:-200% 0}}
.stat-icon{width:48px;height:48px;border-radius:12px;display:flex;align-items:center;justify-content:center;font-size:1.4rem;flex-shrink:0}
.stat-label{font-size:.78rem;color:#64748b;margin-bottom:4px}
.stat-value{font-size:1.5rem;font-weight:800;color:#0f172a;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.grid-2{display:grid;grid-template-columns:1fr 1fr;gap:16px}
@media(max-width:768px){.grid-2{grid-template-columns:1fr}}
.badge{padding:3px 10px;border-radius:99px;font-size:.75rem;font-weight:600;display:inline-block}
.badge-activo{background:#d1fae5;color:#065f46}
.badge-pagado{background:#ede9fe;color:#6d28d9}
.badge-vencido{background:#fee2e2;color:#991b1b}
.badge-cancelado{background:#f1f5f9;color:#64748b}
.table{width:100%;border-collapse:collapse;font-size:.85rem}
.table th{background:#f8fafc;padding:10px 12px;text-align:left;font-weight:600;color:#475569;border-bottom:1px solid #e2e8f0}
.table td{padding:10px 12px;border-bottom:1px solid #f8fafc;color:#1e293b}
.table tr:hover td{background:#fafafa}
code{background:#f1f5f9;padding:2px 6px;border-radius:4px;font-size:.8rem}
</style>
</body>
</html>
