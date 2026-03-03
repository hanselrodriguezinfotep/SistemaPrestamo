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
$activePage = 'pagos';
$pageTitle  = 'Registro de Pagos';
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
  <h1 class="header-title">Registro de Pagos</h1>
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
    <h3>Registro de Pagos</h3>
    <button class="btn btn-primary" onclick="abrirModal()">+ Registrar Pago</button>
  </div>
  <div class="card-body">
    <div class="search-bar">
      <input type="number" id="filtPrestamoId" class="form-control" placeholder="Filtrar por ID de prestamo..." style="max-width:220px" oninput="load()">
    </div>
    <div class="tbl-wrap">
      <table class="table">
        <thead><tr><th>Fecha</th><th>Prestamo</th><th>Cliente</th><th>Cuota</th><th>Monto</th><th>Metodo</th><th>Estado</th><th>Acciones</th></tr></thead>
        <tbody id="tbody"><tr><td colspan="8" style="text-align:center;padding:32px;color:#94a3b8">Cargando...</td></tr></tbody>
      </table>
    </div>
    <div id="pag" style="display:flex;gap:8px;margin-top:14px;align-items:center;flex-wrap:wrap"></div>
  </div>
</div>

<div class="modal-overlay" id="modal">
<div class="modal-box">
  <div class="modal-head"><h3>Registrar Pago</h3><button onclick="cerrar()" style="background:none;border:none;font-size:1.5rem;cursor:pointer;color:#64748b">&times;</button></div>
  <form onsubmit="guardar(event)">
    <div class="form-grid">
      <div class="form-group form-full"><label>ID del Prestamo *</label><input type="number" id="fPrestamo" class="form-control" required placeholder="Numero de prestamo" oninput="cargarCuotas()"></div>
      <div class="form-group form-full"><label>Cuota *</label>
        <select id="fCuota" class="form-control" required>
          <option value="">-- Ingresa ID de prestamo primero --</option>
        </select>
      </div>
      <div class="form-group"><label>Monto *</label><input type="number" step="0.01" id="fMonto" class="form-control" required></div>
      <div class="form-group"><label>Fecha Pago *</label><input type="date" id="fFecha" class="form-control" required value="<?= date('Y-m-d') ?>"></div>
      <div class="form-group"><label>Metodo</label>
        <select id="fMetodo" class="form-control">
          <option value="efectivo">Efectivo</option>
          <option value="transferencia">Transferencia</option>
          <option value="cheque">Cheque</option>
          <option value="tarjeta">Tarjeta</option>
        </select>
      </div>
      <div class="form-group"><label>Referencia</label><input type="text" id="fRef" class="form-control" placeholder="Num. cheque, transaccion..."></div>
      <div class="form-group form-full"><label>Notas</label><textarea id="fNotas" class="form-control" rows="2"></textarea></div>
    </div>
    <div class="err-box" id="errBox"></div>
    <div style="display:flex;gap:10px;justify-content:flex-end;margin-top:18px">
      <button type="button" onclick="cerrar()" class="btn btn-outline">Cancelar</button>
      <button type="submit" class="btn btn-primary" id="btnSave">Registrar Pago</button>
    </div>
  </form>
</div>
</div>

<script>
let pag=1;
async function load(p=1){
  pag=p;
  const pid=document.getElementById('filtPrestamoId').value;
  const url='/GestionPrestamo/api/pagos.php?page='+p+(pid?'&prestamo_id='+pid:'');
  const r=await fetch(url);const d=await r.json();
  const rows=d.data??[];
  document.getElementById('tbody').innerHTML=rows.length?rows.map(p=>`
    <tr>
      <td>${p.fecha_pago}</td>
      <td><a href="/GestionPrestamo/prestamos?id=${p.prestamo_id}" style="color:var(--primary)"><code>${p.codigo_prestamo??p.prestamo_id}</code></a></td>
      <td>${p.cliente_nombre??'&mdash;'}</td>
      <td style="text-align:center">#${p.numero_cuota}</td>
      <td><strong>RD$ ${Number(p.monto).toLocaleString('es-DO',{minimumFractionDigits:2})}</strong></td>
      <td>${p.metodo_pago}</td>
      <td><span class="badge ${p.anulado?'badge-vencido':'badge-activo'}">${p.anulado?'Anulado':'Registrado'}</span></td>
      <td>${!p.anulado?`<button class="btn btn-sm" style="background:#fee2e2;color:#991b1b" onclick="anular(${p.id})">Anular</button>`:'&mdash;'}</td>
    </tr>`).join(''):'<tr><td colspan="8" style="text-align:center;padding:32px;color:#94a3b8">Sin pagos registrados</td></tr>';
  const pg=d.paginacion;
  const el=document.getElementById('pag');
  el.innerHTML=`<span style="font-size:.8rem;color:#64748b">${pg?.total??0} registros</span>`;
  for(let i=1;i<=(pg?.paginas??1);i++){const b=document.createElement('button');b.textContent=i;b.className='btn btn-sm '+(i===pag?'btn-primary':'btn-outline');b.onclick=()=>load(i);el.appendChild(b);}
}
async function cargarCuotas(){
  const pid=document.getElementById('fPrestamo').value;
  if(!pid)return;
  const r=await fetch('/GestionPrestamo/api/cuotas.php?prestamo_id='+pid+'&pendientes=1');
  const d=await r.json();
  const cuotas=d.data??d??[];
  const sel=document.getElementById('fCuota');
  sel.innerHTML=cuotas.length?cuotas.map(c=>`<option value="${c.id}" data-monto="${c.monto_total??c.saldo_pendiente??0}">#${c.numero} — RD$ ${Number(c.monto_total??0).toLocaleString('es-DO',{minimumFractionDigits:2})} — Vence: ${c.fecha_vence??c.fecha_vencimiento??''}</option>`):'<option value="">Sin cuotas pendientes</option>';
  if(cuotas.length){const opt=sel.options[0];if(opt.dataset.monto)document.getElementById('fMonto').value=Number(opt.dataset.monto).toFixed(2);}
  sel.onchange=()=>{const o=sel.options[sel.selectedIndex];if(o?.dataset?.monto)document.getElementById('fMonto').value=Number(o.dataset.monto).toFixed(2);};
}
function abrirModal(){document.getElementById('modal').style.display='flex';document.getElementById('errBox').style.display='none';}
function cerrar(){document.getElementById('modal').style.display='none';}
async function guardar(e){
  e.preventDefault();
  const btn=document.getElementById('btnSave');btn.disabled=true;btn.textContent='Guardando...';
  try{
    const r=await fetch('/GestionPrestamo/api/pagos.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({
      prestamo_id:+document.getElementById('fPrestamo').value,
      cuota_id:+document.getElementById('fCuota').value||null,
      numero_cuota:document.getElementById('fCuota').selectedOptions[0]?.text?.match(/#(\d+)/)?.[1]??1,
      monto:+document.getElementById('fMonto').value,
      fecha_pago:document.getElementById('fFecha').value,
      metodo_pago:document.getElementById('fMetodo').value,
      referencia:document.getElementById('fRef').value,
      notas:document.getElementById('fNotas').value,
    })});
    const d=await r.json();
    if(d.error){document.getElementById('errBox').style.display='block';document.getElementById('errBox').textContent=d.error;return;}
    cerrar();load(pag);
  }finally{btn.disabled=false;btn.textContent='Registrar Pago';}
}
async function anular(id){
  if(!confirm('Anular este pago?'))return;
  const r=await fetch('/GestionPrestamo/api/pagos.php?id='+id,{method:'DELETE'});
  const d=await r.json();
  if(d.error){alert(d.error);return;}
  load(pag);
}
document.getElementById('modal').addEventListener('click',e=>{if(e.target===e.currentTarget)cerrar();});
load();
</script>

</main></div></div>
<?php require_once __DIR__ . '/../php/partials/footer.php'; ?>
</body></html>
