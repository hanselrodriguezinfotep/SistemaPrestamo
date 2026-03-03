<?php
require_once __DIR__ . '/../config/session.php';
$sesion = verificarSesion();
$iniciales = implode('', array_map(fn($w) => strtoupper($w[0]), array_slice(explode(' ', trim($sesion['nombre'] ?? 'U')), 0, 2))) ?: 'U';
$nombreRol = match($sesion['rol'] ?? '') {
    'superadmin' => 'Superadministrador', 'admin' => 'Administrador',
    'gerente' => 'Gerente', 'supervisor' => 'Supervisor',
    'cajero' => 'Cajero', 'cobrador' => 'Cobrador',
    default => ucfirst($sesion['rol'] ?? 'Usuario'),
};
$activePage = 'cartera';
$pageTitle  = 'Cartera General';
?>
<!DOCTYPE html>
<html lang="es">
<head><?php require_once __DIR__ . '/../php/partials/head.php'; ?></head>
<body>
<div class="app">
<?php require_once __DIR__ . '/../php/partials/sidebar.php'; ?>
<div class="main">
<header class="header">
  <button class="hamburger" onclick="toggleSidebar()">&#9776;</button>
  <h1 class="header-title">Cartera General</h1>
  <div class="header-actions" id="hdrActions"></div>
</header>
<main class="page">

<style>
.tbl-wrap{overflow-x:auto}
.table{width:100%;border-collapse:collapse;font-size:.85rem}
.table th{background:#f8fafc;padding:10px 12px;text-align:left;font-weight:600;color:#475569;border-bottom:1px solid #e2e8f0;white-space:nowrap}
.table td{padding:10px 12px;border-bottom:1px solid #f8fafc;color:#1e293b}
.table tr:last-child td{border-bottom:none}
.table tr:hover td{background:#fafafa}
.badge{padding:3px 10px;border-radius:99px;font-size:.75rem;font-weight:600;display:inline-block}
.badge-activo{background:#d1fae5;color:#065f46}
.badge-pagado{background:#ede9fe;color:#6d28d9}
.badge-vencido{background:#fee2e2;color:#991b1b}
.badge-cancelado,.badge-neutro{background:#f1f5f9;color:#64748b}
code{background:#f1f5f9;padding:2px 6px;border-radius:4px;font-size:.8rem;font-family:monospace}
.modal-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:1000;align-items:center;justify-content:center;padding:20px}
.modal-box{background:#fff;border-radius:16px;padding:32px;width:100%;max-width:560px;max-height:90vh;overflow-y:auto}
.modal-head{display:flex;justify-content:space-between;align-items:center;margin-bottom:24px}
.form-grid{display:grid;grid-template-columns:1fr 1fr;gap:14px}
.form-full{grid-column:1/-1}
@media(max-width:520px){.form-grid{grid-template-columns:1fr}}
.err-box{display:none;background:#fee2e2;color:#991b1b;padding:10px;border-radius:8px;margin:10px 0;font-size:.85rem}
.search-bar{display:flex;gap:10px;margin-bottom:16px;flex-wrap:wrap}
</style>

<div class="card">
  <div class="card-header"><h3>Cartera General de Prestamos</h3></div>
  <div class="card-body">
    <div class="search-bar" style="flex-wrap:wrap;gap:10px">
      <select id="filtEstado" class="form-control" style="max-width:160px" onchange="load()">
        <option value="">Todos los estados</option>
        <option value="activo">Activo</option>
        <option value="pagado">Pagado</option>
        <option value="vencido">Vencido</option>
        <option value="cancelado">Cancelado</option>
      </select>
      <input type="text" id="filtCliente" class="form-control" placeholder="Buscar cliente..." style="max-width:260px" oninput="buscar()">
    </div>
    <div class="tbl-wrap">
      <table class="table">
        <thead><tr><th>Codigo</th><th>Cliente</th><th>Capital</th><th>Cuota</th><th>Total</th><th>Pagado</th><th>Saldo</th><th>Estado</th><th>Vencimiento</th></tr></thead>
        <tbody id="tbody"><tr><td colspan="9" style="text-align:center;padding:32px;color:#94a3b8">Cargando...</td></tr></tbody>
      </table>
    </div>
    <div id="pag" style="display:flex;gap:8px;margin-top:14px;align-items:center;flex-wrap:wrap"></div>
  </div>
</div>
<script>
let pag=1,timer;
async function load(p=1){
  pag=p;
  const estado=document.getElementById('filtEstado').value;
  const cli=document.getElementById('filtCliente').value;
  let url=`/GestionPrestamo/api/prestamos.php?page=${p}`;
  if(estado)url+='&estado='+estado;
  const r=await fetch(url);const d=await r.json();
  let rows=d.data??[];
  if(cli){const q=cli.toLowerCase();rows=rows.filter(p=>p.cliente?.toLowerCase().includes(q));}
  document.getElementById('tbody').innerHTML=rows.length?rows.map(p=>`
    <tr>
      <td><code>${p.codigo}</code></td>
      <td>${p.cliente}</td>
      <td>RD$ ${Number(p.monto_principal??0).toLocaleString('es-DO',{minimumFractionDigits:2})}</td>
      <td>RD$ ${Number(p.cuota_mensual??0).toLocaleString('es-DO',{minimumFractionDigits:2})}</td>
      <td>RD$ ${Number(p.monto_total??0).toLocaleString('es-DO',{minimumFractionDigits:2})}</td>
      <td style="color:#10b77a">RD$ ${Number(p.total_pagado??0).toLocaleString('es-DO',{minimumFractionDigits:2})}</td>
      <td><strong>RD$ ${Number(p.saldo_pendiente??0).toLocaleString('es-DO',{minimumFractionDigits:2})}</strong></td>
      <td><span class="badge badge-${p.estado}">${p.estado}</span></td>
      <td>${p.fecha_vencimiento??'&mdash;'}</td>
    </tr>`).join(''):'<tr><td colspan="9" style="text-align:center;padding:32px;color:#94a3b8">Sin registros</td></tr>';
  const pg=d.paginacion;
  const el=document.getElementById('pag');
  el.innerHTML=`<span style="font-size:.8rem;color:#64748b">${pg?.total??0} prestamos</span>`;
  for(let i=1;i<=(pg?.paginas??1);i++){const b=document.createElement('button');b.textContent=i;b.className='btn btn-sm '+(i===pag?'btn-primary':'btn-outline');b.onclick=()=>load(i);el.appendChild(b);}
}
function buscar(){clearTimeout(timer);timer=setTimeout(()=>load(1),400);}
load();
</script>

</main></div></div>
<?php require_once __DIR__ . '/../php/partials/footer.php'; ?>
</body></html>
