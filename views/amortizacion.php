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
$activePage = 'amortizacion';
$pageTitle  = 'Tabla de Amortizacion';
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
  <h1 class="header-title">Tabla de Amortizacion</h1>
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

<div class="card" style="max-width:680px;margin:0 auto">
  <div class="card-header"><h3>Simulador de Amortizacion</h3></div>
  <div class="card-body">
    <div class="form-grid">
      <div class="form-group"><label>Monto del Prestamo *</label><input type="number" step="0.01" id="sMonto" class="form-control" placeholder="Ej: 50000" oninput="calcular()"></div>
      <div class="form-group"><label>Tasa Anual (%) *</label><input type="number" step="0.01" id="sTasa" class="form-control" placeholder="Ej: 24" oninput="calcular()"></div>
      <div class="form-group"><label>Plazo (meses) *</label><input type="number" id="sPlazo" class="form-control" placeholder="Ej: 12" oninput="calcular()"></div>
      <div class="form-group"><label>Fecha Inicio</label><input type="date" id="sFecha" class="form-control" value="<?= date('Y-m-d') ?>" onchange="calcular()"></div>
    </div>

    <div id="resumen" style="display:none;background:#f8fafc;border-radius:10px;padding:16px;margin:16px 0;display:none">
      <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:12px" id="kpiAmort"></div>
    </div>
  </div>
</div>

<div class="card" id="tblCard" style="margin-top:20px;display:none">
  <div class="card-header">
    <h3>Tabla de Amortizacion</h3>
    <button class="btn btn-outline btn-sm" onclick="imprimir()">Imprimir</button>
  </div>
  <div class="card-body">
    <div class="tbl-wrap">
      <table class="table" id="tblAmort">
        <thead><tr><th>#</th><th>Fecha</th><th>Cuota</th><th>Capital</th><th>Interes</th><th>Saldo</th></tr></thead>
        <tbody id="amortBody"></tbody>
        <tfoot id="amortFoot"></tfoot>
      </table>
    </div>
  </div>
</div>

<script>
let timer;
function calcular(){
  clearTimeout(timer);
  timer = setTimeout(()=>{
    const monto=+document.getElementById('sMonto').value;
    const tasa=+document.getElementById('sTasa').value;
    const plazo=+document.getElementById('sPlazo').value;
    const fecha=document.getElementById('sFecha').value;
    if(!monto||!tasa||!plazo||plazo<1)return;

    const tasaMensual=tasa/100/12;
    const cuota=tasaMensual===0?monto/plazo:monto*(tasaMensual*Math.pow(1+tasaMensual,plazo))/(Math.pow(1+tasaMensual,plazo)-1);
    const totalPagar=cuota*plazo;
    const totalInteres=totalPagar-monto;

    // KPIs
    document.getElementById('resumen').style.display='block';
    document.getElementById('kpiAmort').innerHTML=`
      <div><p style="font-size:.75rem;color:#64748b">Cuota Mensual</p><h3 style="color:var(--primary)">RD$ ${cuota.toLocaleString('es-DO',{minimumFractionDigits:2})}</h3></div>
      <div><p style="font-size:.75rem;color:#64748b">Total a Pagar</p><h3>RD$ ${totalPagar.toLocaleString('es-DO',{minimumFractionDigits:2})}</h3></div>
      <div><p style="font-size:.75rem;color:#64748b">Total Intereses</p><h3 style="color:#f04438">RD$ ${totalInteres.toLocaleString('es-DO',{minimumFractionDigits:2})}</h3></div>
      <div><p style="font-size:.75rem;color:#64748b">Costo Financiero</p><h3>${((totalInteres/monto)*100).toFixed(1)}%</h3></div>
    `;

    // Tabla
    let saldo=monto;
    let totalCap=0,totalInt=0,totalCuota=0;
    const rows=[];
    let dt=fecha?new Date(fecha+'T00:00:00'):new Date();
    for(let i=1;i<=plazo;i++){
      dt.setMonth(dt.getMonth()+1);
      const interes=saldo*tasaMensual;
      const capital=Math.min(cuota-interes,saldo);
      saldo=Math.max(0,saldo-capital);
      totalCap+=capital;totalInt+=interes;totalCuota+=cuota;
      rows.push(`<tr>
        <td>${i}</td>
        <td>${dt.toLocaleDateString('es-DO',{year:'numeric',month:'2-digit',day:'2-digit'})}</td>
        <td>RD$ ${cuota.toLocaleString('es-DO',{minimumFractionDigits:2})}</td>
        <td>RD$ ${capital.toLocaleString('es-DO',{minimumFractionDigits:2})}</td>
        <td style="color:#f04438">RD$ ${interes.toLocaleString('es-DO',{minimumFractionDigits:2})}</td>
        <td><strong>RD$ ${saldo.toLocaleString('es-DO',{minimumFractionDigits:2})}</strong></td>
      </tr>`);
    }
    document.getElementById('amortBody').innerHTML=rows.join('');
    document.getElementById('amortFoot').innerHTML=`<tr style="background:#f8fafc;font-weight:700">
      <td colspan="2">TOTALES</td>
      <td>RD$ ${totalCuota.toLocaleString('es-DO',{minimumFractionDigits:2})}</td>
      <td>RD$ ${totalCap.toLocaleString('es-DO',{minimumFractionDigits:2})}</td>
      <td style="color:#f04438">RD$ ${totalInt.toLocaleString('es-DO',{minimumFractionDigits:2})}</td>
      <td>&mdash;</td>
    </tr>`;
    document.getElementById('tblCard').style.display='block';
  },300);
}
function imprimir(){window.print();}
</script>

</main></div></div>
<?php require_once __DIR__ . '/../php/partials/footer.php'; ?>
</body></html>
