<?php
// views/planes.php — Planes de Préstamo | GestionPrestamo
require_once __DIR__ . '/../config/session.php';
$sesion = verificarSesion();
$roles  = ['superadmin','admin','gerente'];
if (!in_array($sesion['rol'], $roles, true)) { header('Location: /GestionPrestamo/'); exit; }

$esSuperadmin = $sesion['rol'] === 'superadmin';
$nombreRol = match($sesion['rol']) { 'superadmin'=>'Superadministrador','admin'=>'Administrador','gerente'=>'Director', default=>ucfirst($sesion['rol']) };
$iniciales  = implode('', array_map(fn($w)=>strtoupper($w[0]), array_slice(explode(' ',trim($sesion['nombre'])),0,2)));
$activePage = 'planes';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <?php $pageTitle = 'Planes de Préstamo'; require_once __DIR__ . '/../php/partials/head.php'; ?>
    <style>
        .toolbar { display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:10px; margin-bottom:16px; }
        .table-card { background:var(--surface); border:1.5px solid var(--border); border-radius:12px; overflow:hidden; box-shadow:var(--shadow); }
        table { width:100%; border-collapse:collapse; font-size:.83rem; }
        thead th { background:var(--bg); padding:10px 14px; text-align:left; font-size:.7rem; font-weight:800; color:var(--muted); text-transform:uppercase; border-bottom:1px solid var(--border); white-space:nowrap; }
        tbody tr { border-bottom:1px solid var(--border); }
        tbody tr:hover { background:var(--bg); }
        tbody td { padding:10px 14px; vertical-align:middle; }
        .badge-activo { background:#dcfce7;color:#15803d;padding:3px 10px;border-radius:99px;font-size:.7rem;font-weight:700; }
        .badge-inactivo { background:#f3f4f6;color:#6b7280;padding:3px 10px;border-radius:99px;font-size:.7rem;font-weight:700; }
        .modal-backdrop { display:none;position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:1000;align-items:center;justify-content:center;padding:20px; }
        .modal-backdrop.open { display:flex; }
        .modal-box { background:var(--surface);border-radius:16px;width:100%;max-width:560px;max-height:90vh;overflow-y:auto;box-shadow:0 20px 60px rgba(0,0,0,.3); }
        .modal-head { display:flex;align-items:center;justify-content:space-between;padding:18px 22px;border-bottom:1px solid var(--border); }
        .modal-head h3 { font-size:.95rem;font-weight:800;margin:0; }
        .modal-close { background:none;border:none;font-size:1.3rem;cursor:pointer;color:var(--muted); }
        .modal-body { padding:22px; }
        .modal-foot { padding:14px 22px;border-top:1px solid var(--border);display:flex;justify-content:flex-end;gap:10px; }
        .form-grid { display:grid;gap:14px; }
        .form-2 { grid-template-columns:1fr 1fr; }
        .field label { display:block;font-size:.72rem;font-weight:800;color:var(--muted);text-transform:uppercase;margin-bottom:5px; }
        .field input,.field select,.field textarea { width:100%;box-sizing:border-box;padding:9px 12px;border:1.5px solid var(--border);border-radius:9px;font-family:inherit;font-size:.86rem;outline:none; }
        .field input:focus,.field select:focus { border-color:var(--primary); }
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
            <div class="toolbar">
                <h2 style="font-size:1rem;font-weight:800;margin:0">📋 Planes de Préstamo</h2>
                <button class="btn btn-primary" onclick="abrirNuevo()">➕ Nuevo Plan</button>
            </div>
            <div class="table-card">
                <div style="overflow-x:auto">
                    <table>
                        <thead><tr>
                            <th>Nombre</th><th>Tasa</th><th>Amortización</th>
                            <th>Plazo (meses)</th><th>Monto</th><th>Estado</th><th></th>
                        </tr></thead>
                        <tbody id="tbodyPlanes"><tr><td colspan="7" style="text-align:center;padding:40px;color:var(--muted)">Cargando…</td></tr></tbody>
                    </table>
                </div>
            </div>
        </main>
    </div>
</div>

<div class="modal-backdrop" id="modalPlan">
    <div class="modal-box">
        <div class="modal-head">
            <h3 id="planTitle">Nuevo Plan</h3>
            <button class="modal-close" onclick="cerrar()">✕</button>
        </div>
        <div class="modal-body">
            <input type="hidden" id="planId">
            <div class="field" style="margin-bottom:14px">
                <label>Nombre del Plan *</label>
                <input type="text" id="planNombre" placeholder="Ej: Préstamo Personal Estándar">
            </div>
            <div class="field" style="margin-bottom:14px">
                <label>Descripción</label>
                <textarea id="planDesc" rows="2" placeholder="Descripción breve…"></textarea>
            </div>
            <div class="form-grid form-2" style="margin-bottom:14px">
                <div class="field">
                    <label>Tasa de Interés *</label>
                    <div style="display:flex;gap:6px">
                        <input type="number" id="planTasa" min="0.01" max="100" step="0.01" placeholder="5" style="flex:1">
                        <select id="planTipoTasa" style="width:90px">
                            <option value="mensual">%/mes</option>
                            <option value="anual">%/año</option>
                        </select>
                    </div>
                </div>
                <div class="field">
                    <label>Tipo Amortización</label>
                    <select id="planAmort">
                        <option value="frances">Francés</option>
                        <option value="aleman">Alemán</option>
                        <option value="americano">Americano</option>
                    </select>
                </div>
            </div>
            <div class="form-grid form-2" style="margin-bottom:14px">
                <div class="field">
                    <label>Plazo Mín. (meses)</label>
                    <input type="number" id="planPlazoMin" min="1" value="1">
                </div>
                <div class="field">
                    <label>Plazo Máx. (meses)</label>
                    <input type="number" id="planPlazoMax" min="1" value="60">
                </div>
            </div>
            <div class="form-grid form-2">
                <div class="field">
                    <label>Monto Mínimo (RD$)</label>
                    <input type="number" id="planMontoMin" min="0" step="0.01" value="1000">
                </div>
                <div class="field">
                    <label>Monto Máximo (RD$)</label>
                    <input type="number" id="planMontoMax" min="0" step="0.01" value="500000">
                </div>
            </div>
        </div>
        <div class="modal-foot">
            <button class="btn" onclick="cerrar()">Cancelar</button>
            <button class="btn btn-primary" onclick="guardar()">💾 Guardar</button>
        </div>
    </div>
</div>

<script>
const API = '/GestionPrestamo/api/planes.php';
let planes = [];

document.addEventListener('DOMContentLoaded', cargar);

async function cargar() {
    const r = await fetch(API + '?action=listar');
    const j = await r.json();
    if (!j.ok) return;
    planes = j.data;
    const tbody = document.getElementById('tbodyPlanes');
    if (!planes.length) { tbody.innerHTML = '<tr><td colspan="7" style="text-align:center;padding:40px;color:var(--muted)">No hay planes registrados</td></tr>'; return; }
    tbody.innerHTML = planes.map(p => `
        <tr>
            <td><strong>${esc(p.nombre)}</strong>${p.descripcion ? `<br><span style="font-size:.72rem;color:var(--muted)">${esc(p.descripcion)}</span>` : ''}</td>
            <td>${(p.tasa_interes*100).toFixed(2)}% ${p.tipo_tasa}</td>
            <td>${{frances:'Francés',aleman:'Alemán',americano:'Americano'}[p.tipo_amort]||p.tipo_amort}</td>
            <td>${p.plazo_min}–${p.plazo_max}</td>
            <td>RD$ ${fmt(p.monto_min)} – ${fmt(p.monto_max)}</td>
            <td><span class="badge-${p.activo=='1'?'activo':'inactivo'}">${p.activo=='1'?'Activo':'Inactivo'}</span></td>
            <td>
                <button class="btn btn-sm" onclick="editar(${p.id})">✏️</button>
                <button class="btn btn-sm" style="color:#dc2626" onclick="eliminar(${p.id},'${esc(p.nombre)}')">🗑️</button>
            </td>
        </tr>
    `).join('');
}

function abrirNuevo() {
    document.getElementById('planId').value='';
    document.getElementById('planTitle').textContent='Nuevo Plan';
    ['planNombre','planDesc','planTasa'].forEach(id=>document.getElementById(id).value='');
    document.getElementById('planTipoTasa').value='mensual';
    document.getElementById('planAmort').value='frances';
    document.getElementById('planPlazoMin').value='1';
    document.getElementById('planPlazoMax').value='60';
    document.getElementById('planMontoMin').value='1000';
    document.getElementById('planMontoMax').value='500000';
    document.getElementById('modalPlan').classList.add('open');
}

function editar(id) {
    const p = planes.find(x=>x.id==id);
    if (!p) return;
    document.getElementById('planId').value=p.id;
    document.getElementById('planTitle').textContent='Editar Plan';
    document.getElementById('planNombre').value=p.nombre;
    document.getElementById('planDesc').value=p.descripcion||'';
    document.getElementById('planTasa').value=(p.tasa_interes*100).toFixed(2);
    document.getElementById('planTipoTasa').value=p.tipo_tasa;
    document.getElementById('planAmort').value=p.tipo_amort;
    document.getElementById('planPlazoMin').value=p.plazo_min;
    document.getElementById('planPlazoMax').value=p.plazo_max;
    document.getElementById('planMontoMin').value=p.monto_min;
    document.getElementById('planMontoMax').value=p.monto_max;
    document.getElementById('modalPlan').classList.add('open');
}

async function guardar() {
    const nombre = document.getElementById('planNombre').value.trim();
    const tasa = parseFloat(document.getElementById('planTasa').value);
    if (!nombre) return alert('El nombre es requerido');
    if (!tasa || tasa <= 0) return alert('La tasa debe ser mayor a 0');

    const body = {
        id: parseInt(document.getElementById('planId').value)||0,
        nombre,
        descripcion: document.getElementById('planDesc').value,
        tasa_interes: tasa/100,
        tipo_tasa: document.getElementById('planTipoTasa').value,
        tipo_amort: document.getElementById('planAmort').value,
        plazo_min: parseInt(document.getElementById('planPlazoMin').value),
        plazo_max: parseInt(document.getElementById('planPlazoMax').value),
        monto_min: parseFloat(document.getElementById('planMontoMin').value),
        monto_max: parseFloat(document.getElementById('planMontoMax').value),
        activo: 1,
    };
    const r = await fetch(API+'?action=guardar',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(body)});
    const j = await r.json();
    if (j.ok) { cerrar(); toast('✅ '+j.mensaje,'success'); cargar(); }
    else toast('❌ '+j.error,'error');
}

async function eliminar(id, nombre) {
    if (!confirm(`¿Desactivar el plan "${nombre}"?`)) return;
    const r = await fetch(API+'?action=eliminar',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({id})});
    const j = await r.json();
    if (j.ok) { toast('✅ '+j.mensaje,'success'); cargar(); }
    else toast('❌ '+j.error,'error');
}

function cerrar() { document.getElementById('modalPlan').classList.remove('open'); }
function fmt(n) { return parseFloat(n).toLocaleString('es-DO',{minimumFractionDigits:0}); }
function esc(s) { const d=document.createElement('div');d.textContent=s||'';return d.innerHTML; }
function toast(msg,t) {
    let el=document.getElementById('_t');
    if(!el){el=document.createElement('div');el.id='_t';el.style.cssText='position:fixed;bottom:24px;right:24px;z-index:9999;padding:12px 20px;border-radius:10px;font-size:.85rem;font-weight:700;box-shadow:0 4px 20px rgba(0,0,0,.2)';document.body.appendChild(el);}
    el.textContent=msg;el.style.background=t==='success'?'#16a34a':'#dc2626';el.style.color='#fff';el.style.opacity='1';
    clearTimeout(el._t);el._t=setTimeout(()=>el.style.opacity='0',3500);
}
document.getElementById('modalPlan').addEventListener('click',e=>{if(e.target===document.getElementById('modalPlan'))cerrar();});
</script>
<?php require_once __DIR__ . '/../php/partials/footer.php'; ?>
</body>
</html>
