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
$activePage = 'clientes';
$pageTitle  = 'Clientes';
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
  <h1 class="header-title">Clientes</h1>
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
    <h3>Gestion de Clientes</h3>
    <?php if(in_array($sesion['rol'],['superadmin','admin','gerente','cajero','cobrador'],true)): ?>
    <button class="btn btn-primary" onclick="abrirModal()">+ Nuevo Cliente</button>
    <?php endif; ?>
  </div>
  <div class="card-body">
    <div class="search-bar">
      <input type="text" id="q" class="form-control" placeholder="Buscar nombre, apellido o documento..." style="max-width:360px" oninput="buscar()">
    </div>
    <div class="tbl-wrap">
      <table class="table">
        <thead><tr><th>Nombre</th><th>Documento</th><th>Telefono</th><th>Email</th><th>Prestamos</th><th>Activos</th><th>Acciones</th></tr></thead>
        <tbody id="tbody"><tr><td colspan="7" style="text-align:center;padding:32px;color:#94a3b8">Cargando...</td></tr></tbody>
      </table>
    </div>
    <div id="pag" style="display:flex;gap:8px;margin-top:14px;align-items:center;flex-wrap:wrap"></div>
  </div>
</div>

<div class="modal-overlay" id="modal">
<div class="modal-box">
  <div class="modal-head"><h3 id="mTitle">Nuevo Cliente</h3><button onclick="cerrar()" style="background:none;border:none;font-size:1.5rem;cursor:pointer;color:#64748b">&times;</button></div>
  <form onsubmit="guardar(event)">
    <input type="hidden" id="cId">
    <div class="form-grid">
      <div class="form-group"><label>Nombre *</label><input type="text" id="fNombre" class="form-control" required></div>
      <div class="form-group"><label>Apellido *</label><input type="text" id="fApellido" class="form-control" required></div>
      <div class="form-group"><label>Documento *</label><input type="text" id="fDoc" class="form-control" required placeholder="Cedula, pasaporte..."></div>
      <div class="form-group"><label>Telefono</label><input type="text" id="fTel" class="form-control"></div>
      <div class="form-group form-full"><label>Email</label><input type="email" id="fEmail" class="form-control"></div>
      <div class="form-group form-full"><label>Direccion</label><textarea id="fDir" class="form-control" rows="2"></textarea></div>
      <div class="form-group form-full"><label>Notas</label><textarea id="fNotas" class="form-control" rows="2"></textarea></div>
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
let pag = 1, timer;
async function load(p=1){
  pag=p;
  const q=document.getElementById('q').value;
  const r=await fetch(`/GestionPrestamo/api/clientes.php?page=${p}&q=${encodeURIComponent(q)}`);
  const d=await r.json();
  const rows=d.data??[];
  document.getElementById('tbody').innerHTML=rows.length?rows.map(c=>`
    <tr>
      <td><strong>${c.nombre} ${c.apellido}</strong></td>
      <td>${c.documento}</td><td>${c.telefono||'&mdash;'}</td><td>${c.email||'&mdash;'}</td>
      <td style="text-align:center">${c.total_prestamos??0}</td>
      <td style="text-align:center"><span class="badge ${(c.prestamos_activos??0)>0?'badge-activo':'badge-neutro'}">${c.prestamos_activos??0}</span></td>
      <td style="white-space:nowrap">
        <button class="btn btn-sm btn-outline" onclick='editar(${JSON.stringify(c)})'>Editar</button>
        <a href="/GestionPrestamo/prestamos?cliente_id=${c.id}" class="btn btn-sm btn-primary">Prestamos</a>
      </td>
    </tr>`).join(''):'<tr><td colspan="7" style="text-align:center;padding:32px;color:#94a3b8">Sin resultados</td></tr>';
  const pg=d.paginacion;
  const el=document.getElementById('pag');
  el.innerHTML=`<span style="font-size:.8rem;color:#64748b">${pg?.total??0} registros</span>`;
  for(let i=1;i<=(pg?.paginas??1);i++){const b=document.createElement('button');b.textContent=i;b.className='btn btn-sm '+(i===pag?'btn-primary':'btn-outline');b.onclick=()=>load(i);el.appendChild(b);}
}
function buscar(){clearTimeout(timer);timer=setTimeout(()=>load(1),400);}
function abrirModal(c=null){
  document.getElementById('modal').style.display='flex';
  document.getElementById('mTitle').textContent=c?'Editar Cliente':'Nuevo Cliente';
  document.getElementById('cId').value=c?.id??'';
  document.getElementById('fNombre').value=c?.nombre??'';
  document.getElementById('fApellido').value=c?.apellido??'';
  document.getElementById('fDoc').value=c?.documento??'';
  document.getElementById('fTel').value=c?.telefono??'';
  document.getElementById('fEmail').value=c?.email??'';
  document.getElementById('fDir').value=c?.direccion??'';
  document.getElementById('fNotas').value=c?.notas??'';
  document.getElementById('errBox').style.display='none';
}
function editar(c){abrirModal(c);}
function cerrar(){document.getElementById('modal').style.display='none';}
async function guardar(e){
  e.preventDefault();
  const id=document.getElementById('cId').value;
  const body={nombre:document.getElementById('fNombre').value,apellido:document.getElementById('fApellido').value,documento:document.getElementById('fDoc').value,telefono:document.getElementById('fTel').value,email:document.getElementById('fEmail').value,direccion:document.getElementById('fDir').value,notas:document.getElementById('fNotas').value};
  const btn=document.getElementById('btnSave');
  btn.disabled=true;btn.textContent='Guardando...';
  try{
    const r=await fetch(id?`/GestionPrestamo/api/clientes.php?id=${id}`:'/GestionPrestamo/api/clientes.php',{method:id?'PUT':'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(body)});
    const d=await r.json();
    if(d.error){document.getElementById('errBox').style.display='block';document.getElementById('errBox').textContent=d.error;return;}
    cerrar();load(pag);
  }finally{btn.disabled=false;btn.textContent='Guardar';}
}
document.getElementById('modal').addEventListener('click',e=>{if(e.target===e.currentTarget)cerrar();});
load();
</script>

</main></div></div>
<?php require_once __DIR__ . '/../php/partials/footer.php'; ?>
</body></html>
