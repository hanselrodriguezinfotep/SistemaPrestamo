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
$activePage = 'rutas';
$pageTitle  = 'Rutas de Cobranza';
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
  <h1 class="header-title">Rutas de Cobranza</h1>
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
  <div class="card-header">
    <h3>Rutas de Cobranza</h3>
    <?php if(in_array($sesion['rol'],['superadmin','admin','gerente'],true)): ?>
    <button class="btn btn-primary" onclick="abrirModal()">+ Nueva Ruta</button>
    <?php endif; ?>
  </div>
  <div class="card-body">
    <div class="tbl-wrap">
      <table class="table">
        <thead><tr><th>Nombre</th><th>Cobrador</th><th>Dia</th><th>Clientes</th><th>Estado</th><th>Acciones</th></tr></thead>
        <tbody id="tbody"><tr><td colspan="6" style="text-align:center;padding:32px;color:#94a3b8">Cargando...</td></tr></tbody>
      </table>
    </div>
  </div>
</div>

<div class="modal-overlay" id="modal">
<div class="modal-box">
  <div class="modal-head"><h3 id="mTitle">Nueva Ruta</h3><button onclick="cerrar()" style="background:none;border:none;font-size:1.5rem;cursor:pointer;color:#64748b">&times;</button></div>
  <form onsubmit="guardar(event)">
    <input type="hidden" id="rId">
    <div class="form-group"><label>Nombre de la Ruta *</label><input type="text" id="fNombre" class="form-control" required></div>
    <div class="form-group"><label>Descripcion</label><textarea id="fDesc" class="form-control" rows="2"></textarea></div>
    <div class="form-group">
      <label>Cobrador</label>
      <select id="fCobrador" class="form-control"><option value="">-- Sin asignar --</option></select>
    </div>
    <div class="form-group">
      <label>Dia de Cobranza</label>
      <select id="fDia" class="form-control">
        <option value="1">Lunes</option><option value="2">Martes</option><option value="3">Miercoles</option>
        <option value="4">Jueves</option><option value="5">Viernes</option><option value="6">Sabado</option><option value="7">Domingo</option>
      </select>
    </div>
    <div class="err-box" id="errBox"></div>
    <div style="display:flex;gap:10px;justify-content:flex-end;margin-top:18px">
      <button type="button" onclick="cerrar()" class="btn btn-outline">Cancelar</button>
      <button type="submit" class="btn btn-primary" id="btnSave">Guardar</button>
    </div>
  </form>
</div>
</div>

<script>
const dias=['','Lunes','Martes','Miercoles','Jueves','Viernes','Sabado','Domingo'];
async function load(){
  const r=await fetch('/GestionPrestamo/api/rutas.php');const d=await r.json();
  const rows=Array.isArray(d)?d:(d.data??[]);
  document.getElementById('tbody').innerHTML=rows.length?rows.map(r=>`
    <tr>
      <td><strong>${r.nombre}</strong>${r.descripcion?`<br><small style='color:#94a3b8'>${r.descripcion}</small>`:''}</td>
      <td>${r.cobrador_nombre??'Sin asignar'}</td>
      <td>${dias[r.dia_cobranza]??r.dia_cobranza}</td>
      <td style="text-align:center">${r.total_clientes??0}</td>
      <td><span class="badge ${r.activa?'badge-activo':'badge-neutro'}">${r.activa?'Activa':'Inactiva'}</span></td>
      <td style="white-space:nowrap">
        <button class="btn btn-sm btn-outline" onclick='editar(${JSON.stringify(r)})'>Editar</button>
        <a href="/GestionPrestamo/visitas?ruta_id=${r.id}" class="btn btn-sm btn-primary">Visitas</a>
      </td>
    </tr>`).join(''):'<tr><td colspan="6" style="text-align:center;padding:32px;color:#94a3b8">Sin rutas registradas</td></tr>';
}
async function cargarCobradores(){
  const r=await fetch('/GestionPrestamo/api/usuarios.php');const d=await r.json();
  const users=Array.isArray(d)?d:(d.data??[]);
  const sel=document.getElementById('fCobrador');
  users.filter(u=>u.activo&&(u.rol==='cobrador'||u.rol==='admin'||u.rol==='superadmin')).forEach(u=>{
    const o=document.createElement('option');o.value=u.id;o.textContent=u.nombre+' ('+u.rol+')';sel.appendChild(o);
  });
}
function abrirModal(r=null){
  document.getElementById('modal').style.display='flex';
  document.getElementById('mTitle').textContent=r?'Editar Ruta':'Nueva Ruta';
  document.getElementById('rId').value=r?.id??'';
  document.getElementById('fNombre').value=r?.nombre??'';
  document.getElementById('fDesc').value=r?.descripcion??'';
  document.getElementById('fCobrador').value=r?.cobrador_id??'';
  document.getElementById('fDia').value=r?.dia_cobranza??1;
  document.getElementById('errBox').style.display='none';
}
function editar(r){abrirModal(r);}
function cerrar(){document.getElementById('modal').style.display='none';}
async function guardar(e){
  e.preventDefault();
  const id=document.getElementById('rId').value;
  const body={nombre:document.getElementById('fNombre').value,descripcion:document.getElementById('fDesc').value,cobrador_id:+document.getElementById('fCobrador').value||null,dia_cobranza:+document.getElementById('fDia').value};
  const btn=document.getElementById('btnSave');btn.disabled=true;btn.textContent='Guardando...';
  try{
    const r=await fetch(id?'/GestionPrestamo/api/rutas.php?id='+id:'/GestionPrestamo/api/rutas.php',{method:id?'PUT':'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(body)});
    const d=await r.json();
    if(d.error){document.getElementById('errBox').style.display='block';document.getElementById('errBox').textContent=d.error;return;}
    cerrar();load();
  }finally{btn.disabled=false;btn.textContent='Guardar';}
}
document.getElementById('modal').addEventListener('click',e=>{if(e.target===e.currentTarget)cerrar();});
cargarCobradores();load();
</script>

</main></div></div>
<?php require_once __DIR__ . '/../php/partials/footer.php'; ?>
</body></html>
