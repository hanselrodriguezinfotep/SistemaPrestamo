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
$activePage = 'visitas';
$pageTitle  = 'Visitas de Cobranza';
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
  <h1 class="header-title">Visitas de Cobranza</h1>
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
    <h3>Visitas de Cobranza</h3>
    <button class="btn btn-primary" onclick="abrirModal()">+ Registrar Visita</button>
  </div>
  <div class="card-body">
    <div class="search-bar">
      <input type="number" id="filtRuta" class="form-control" placeholder="Filtrar por ID de ruta..." style="max-width:200px" oninput="load()">
      <input type="date" id="filtFecha" class="form-control" style="max-width:180px" onchange="load()">
    </div>
    <div class="tbl-wrap">
      <table class="table">
        <thead><tr><th>Fecha</th><th>Cliente</th><th>Cobrador</th><th>Ruta</th><th>Resultado</th><th>Monto</th><th>Notas</th><th>Acc.</th></tr></thead>
        <tbody id="tbody"><tr><td colspan="8" style="text-align:center;padding:32px;color:#94a3b8">Cargando...</td></tr></tbody>
      </table>
    </div>
  </div>
</div>

<div class="modal-overlay" id="modal">
<div class="modal-box">
  <div class="modal-head"><h3>Registrar Visita</h3><button onclick="cerrar()" style="background:none;border:none;font-size:1.5rem;cursor:pointer;color:#64748b">&times;</button></div>
  <form onsubmit="guardar(event)">
    <div class="form-grid">
      <div class="form-group"><label>ID Cliente *</label><input type="number" id="fCliente" class="form-control" required></div>
      <div class="form-group"><label>ID Ruta</label><input type="number" id="fRuta" class="form-control"></div>
      <div class="form-group"><label>Fecha Visita *</label><input type="date" id="fFecha" class="form-control" required value="<?= date('Y-m-d') ?>"></div>
      <div class="form-group"><label>Resultado *</label>
        <select id="fResultado" class="form-control" required>
          <option value="contactado">Contactado</option>
          <option value="no_encontrado">No encontrado</option>
          <option value="promesa_pago">Promesa de pago</option>
          <option value="pago_recibido">Pago recibido</option>
          <option value="rechazo">Rechazo</option>
        </select>
      </div>
      <div class="form-group"><label>Monto Gestionado</label><input type="number" step="0.01" id="fMonto" class="form-control" placeholder="0.00"></div>
      <div class="form-group form-full"><label>Notas</label><textarea id="fNotas" class="form-control" rows="3"></textarea></div>
    </div>
    <div class="err-box" id="errBox"></div>
    <div style="display:flex;gap:10px;justify-content:flex-end;margin-top:18px">
      <button type="button" onclick="cerrar()" class="btn btn-outline">Cancelar</button>
      <button type="submit" class="btn btn-primary" id="btnSave">Registrar</button>
    </div>
  </form>
</div>
</div>

<script>
const resultadoLabel={contactado:'Contactado',no_encontrado:'No encontrado',promesa_pago:'Promesa de pago',pago_recibido:'Pago recibido',rechazo:'Rechazo'};
const resultadoColor={contactado:'badge-activo',no_encontrado:'badge-neutro',promesa_pago:'badge-pagado',pago_recibido:'badge-activo',rechazo:'badge-vencido'};
async function load(){
  const ruta=document.getElementById('filtRuta').value;
  const fecha=document.getElementById('filtFecha').value;
  let url='/GestionPrestamo/api/visitas.php?';
  if(ruta)url+='ruta_id='+ruta+'&';
  if(fecha)url+='fecha='+fecha+'&';
  const r=await fetch(url);const d=await r.json();
  const rows=d.data??d??[];
  document.getElementById('tbody').innerHTML=rows.length?rows.map(v=>`
    <tr>
      <td>${v.fecha_visita}</td>
      <td>${v.cliente_nombre??'ID: '+v.cliente_id}</td>
      <td>${v.cobrador_nombre??'&mdash;'}</td>
      <td>${v.ruta_nombre??'&mdash;'}</td>
      <td><span class="badge ${resultadoColor[v.resultado]??'badge-neutro'}">${resultadoLabel[v.resultado]??v.resultado}</span></td>
      <td>${v.monto_gestionado?'RD$ '+Number(v.monto_gestionado).toLocaleString('es-DO',{minimumFractionDigits:2}):'&mdash;'}</td>
      <td style="max-width:160px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">${v.notas??'&mdash;'}</td>
      <td><button class="btn btn-sm" style="background:#fee2e2;color:#991b1b" onclick="eliminar(${v.id})">X</button></td>
    </tr>`).join(''):'<tr><td colspan="8" style="text-align:center;padding:32px;color:#94a3b8">Sin visitas registradas</td></tr>';
}
function abrirModal(){document.getElementById('modal').style.display='flex';document.getElementById('errBox').style.display='none';}
function cerrar(){document.getElementById('modal').style.display='none';}
async function guardar(e){
  e.preventDefault();
  const body={cliente_id:+document.getElementById('fCliente').value,ruta_id:+document.getElementById('fRuta').value||null,fecha_visita:document.getElementById('fFecha').value,resultado:document.getElementById('fResultado').value,monto_gestionado:+document.getElementById('fMonto').value||null,notas:document.getElementById('fNotas').value};
  const btn=document.getElementById('btnSave');btn.disabled=true;btn.textContent='Guardando...';
  try{
    const r=await fetch('/GestionPrestamo/api/visitas.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(body)});
    const d=await r.json();
    if(d.error){document.getElementById('errBox').style.display='block';document.getElementById('errBox').textContent=d.error;return;}
    cerrar();load();
  }finally{btn.disabled=false;btn.textContent='Registrar';}
}
async function eliminar(id){
  if(!confirm('Eliminar esta visita?'))return;
  await fetch('/GestionPrestamo/api/visitas.php?id='+id,{method:'DELETE'});
  load();
}
document.getElementById('modal').addEventListener('click',e=>{if(e.target===e.currentTarget)cerrar();});
load();
</script>

</main></div></div>
<?php require_once __DIR__ . '/../php/partials/footer.php'; ?>
</body></html>
