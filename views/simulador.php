<?php
// views/simulador.php — Simulador de Préstamos | GestionPrestamo
require_once __DIR__ . '/../config/session.php';
$sesion = verificarSesion();
$nombreRol = match($sesion['rol']) { 'superadmin'=>'Superadministrador','admin'=>'Administrador','gerente'=>'Director','supervisor'=>'Coordinador','cajero'=>'Cajero',default=>ucfirst($sesion['rol']) };
$iniciales = implode('',array_map(fn($w)=>strtoupper($w[0]),array_slice(explode(' ',trim($sesion['nombre'])),0,2)));
$activePage = 'simulador';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <?php $pageTitle = 'Simulador de Préstamos'; require_once __DIR__ . '/../php/partials/head.php'; ?>
    <style>
        .sim-layout { display:grid; grid-template-columns:380px 1fr; gap:24px; align-items:start; }
        .sim-panel { background:var(--surface); border:1.5px solid var(--border); border-radius:14px; padding:22px; box-shadow:var(--shadow); }
        .field { margin-bottom:14px; }
        .field label { display:block; font-size:.72rem; font-weight:800; color:var(--muted); text-transform:uppercase; letter-spacing:.04em; margin-bottom:5px; }
        .field input, .field select { width:100%; box-sizing:border-box; padding:10px 13px; border:1.5px solid var(--border); border-radius:10px; font-family:inherit; font-size:.9rem; outline:none; transition:border-color .12s; }
        .field input:focus, .field select:focus { border-color:var(--primary); }
        .result-box { background:linear-gradient(135deg,#1d4ed8,#2563eb); color:#fff; border-radius:14px; padding:22px; margin-bottom:16px; }
        .result-grid { display:grid; grid-template-columns:1fr 1fr; gap:12px; }
        .result-item .rl { font-size:.7rem; opacity:.75; text-transform:uppercase; letter-spacing:.04em; margin-bottom:3px; }
        .result-item .rv { font-size:1.2rem; font-weight:800; }
        .table-card { background:var(--surface); border:1.5px solid var(--border); border-radius:12px; overflow:hidden; }
        .amort-table { width:100%; font-size:.8rem; border-collapse:collapse; }
        .amort-table th { background:#f8fafc; padding:8px 10px; text-align:right; font-size:.68rem; font-weight:800; color:var(--muted); border-bottom:1px solid var(--border); }
        .amort-table th:first-child { text-align:left; }
        .amort-table td { padding:7px 10px; border-bottom:1px solid var(--border); text-align:right; }
        .amort-table td:first-child { text-align:left; font-weight:700; }
        .amort-table tr:hover td { background:#f8fafc; }
        .tab-btns { display:flex; gap:6px; margin-bottom:14px; }
        .tab-btn { padding:8px 16px; border-radius:9px; border:1.5px solid var(--border); background:var(--bg); font-family:inherit; font-size:.82rem; font-weight:700; cursor:pointer; transition:all .12s; }
        .tab-btn.active { background:var(--primary); color:#fff; border-color:var(--primary); }
        @media(max-width:768px) { .sim-layout { grid-template-columns:1fr; } }
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
            <h2 style="font-size:1rem;font-weight:800;margin:0 0 20px">🧮 Simulador de Préstamos</h2>

            <div class="sim-layout">
                <!-- Panel izquierdo: inputs -->
                <div class="sim-panel">
                    <div class="field">
                        <label>Monto del Préstamo (RD$)</label>
                        <input type="number" id="capital" value="50000" min="1" step="100" oninput="simular()">
                    </div>
                    <div class="field">
                        <label>Tasa de Interés</label>
                        <div style="display:flex;gap:8px">
                            <input type="number" id="tasa" value="5" min="0.01" max="100" step="0.01" oninput="simular()" style="flex:1">
                            <select id="tipoTasa" onchange="simular()" style="width:100px">
                                <option value="mensual">% / mes</option>
                                <option value="anual">% / año</option>
                            </select>
                        </div>
                    </div>
                    <div class="field">
                        <label>Plazo (meses): <strong id="plazoLabel">12</strong></label>
                        <input type="range" id="plazo" min="1" max="120" value="12" oninput="document.getElementById('plazoLabel').textContent=this.value;simular()" style="width:100%;accent-color:var(--primary)">
                        <div style="display:flex;justify-content:space-between;font-size:.72rem;color:var(--muted)"><span>1</span><span>60</span><span>120</span></div>
                    </div>
                    <div class="field">
                        <label>Tipo de Amortización</label>
                        <select id="tipoAmort" onchange="simular()">
                            <option value="frances">🇫🇷 Francés — Cuota fija</option>
                            <option value="aleman">🇩🇪 Alemán — Capital fijo</option>
                            <option value="americano">🇺🇸 Americano — Solo interés</option>
                        </select>
                    </div>
                    <div class="field">
                        <label>Fecha de Inicio</label>
                        <input type="date" id="fechaInicio" value="<?= date('Y-m-d') ?>" onchange="simular()">
                    </div>
                    <div style="background:#f8fafc;border-radius:10px;padding:14px;margin-top:6px;font-size:.78rem;color:var(--muted)">
                        <strong>ℹ️ Tipos de amortización:</strong><br>
                        <b>Francés:</b> Cuota constante. Más interés al inicio.<br>
                        <b>Alemán:</b> Capital fijo. Cuota decrece cada mes.<br>
                        <b>Americano:</b> Solo intereses mensuales + capital al final.
                    </div>
                </div>

                <!-- Panel derecho: resultados -->
                <div>
                    <!-- Resumen -->
                    <div class="result-box">
                        <div style="font-size:.75rem;opacity:.8;text-transform:uppercase;letter-spacing:.05em;margin-bottom:12px">Resumen del Préstamo</div>
                        <div class="result-grid">
                            <div class="result-item"><div class="rl">Cuota Mensual</div><div class="rv" id="rCuota">—</div></div>
                            <div class="result-item"><div class="rl">Total a Pagar</div><div class="rv" id="rTotal">—</div></div>
                            <div class="result-item"><div class="rl">Total Intereses</div><div class="rv" id="rIntereses">—</div></div>
                            <div class="result-item"><div class="rl">% Costo Total</div><div class="rv" id="rPct">—</div></div>
                        </div>
                    </div>

                    <!-- Tabs -->
                    <div class="tab-btns">
                        <button class="tab-btn active" id="tabAmort" onclick="showTab('amort')">📋 Tabla de Amortización</button>
                        <button class="tab-btn" id="tabGraf" onclick="showTab('graf')">📊 Gráfico</button>
                    </div>

                    <!-- Tabla de amortización -->
                    <div id="tabAmortContent" class="table-card">
                        <div style="overflow:auto;max-height:480px">
                            <table class="amort-table">
                                <thead><tr>
                                    <th>#</th><th>Fecha</th><th>Capital</th>
                                    <th>Interés</th><th>Cuota</th><th>Saldo</th>
                                </tr></thead>
                                <tbody id="tbodyAmort"></tbody>
                            </table>
                        </div>
                    </div>

                    <!-- Gráfico -->
                    <div id="tabGrafContent" style="display:none;background:var(--surface);border:1.5px solid var(--border);border-radius:12px;padding:20px">
                        <canvas id="grafCanvas" height="280"></canvas>
                    </div>
                </div>
            </div>
        </main>
    </div>
</div>

<script>
let grafChart = null;

document.addEventListener('DOMContentLoaded', simular);

function simular() {
    const capital  = parseFloat(document.getElementById('capital').value) || 0;
    const tasaIn   = parseFloat(document.getElementById('tasa').value) || 0;
    const tipoTasa = document.getElementById('tipoTasa').value;
    const plazo    = parseInt(document.getElementById('plazo').value) || 1;
    const amort    = document.getElementById('tipoAmort').value;
    const fechaStr = document.getElementById('fechaInicio').value || new Date().toISOString().split('T')[0];

    if (!capital || !tasaIn) return;

    const tasa = tipoTasa === 'anual' ? tasaIn / 100 / 12 : tasaIn / 100;
    const tabla = generarTabla(capital, tasa, plazo, amort, fechaStr);

    // Calcular totales
    const totalPago = tabla.reduce((a,r) => a + r.cuota, 0);
    const totalInt  = tabla.reduce((a,r) => a + r.interes, 0);
    const primeraCuota = tabla[0]?.cuota || 0;

    document.getElementById('rCuota').textContent    = fmt(primeraCuota) + (amort==='aleman'?' *':'');
    document.getElementById('rTotal').textContent    = fmt(totalPago);
    document.getElementById('rIntereses').textContent = fmt(totalInt);
    document.getElementById('rPct').textContent      = ((totalPago/capital - 1)*100).toFixed(1) + '%';

    // Tabla amortización
    document.getElementById('tbodyAmort').innerHTML = tabla.map(r => `
        <tr>
            <td>${r.num}</td>
            <td>${r.fecha}</td>
            <td>${fmt(r.capital)}</td>
            <td style="color:#2563eb">${fmt(r.interes)}</td>
            <td><strong>${fmt(r.cuota)}</strong></td>
            <td>${fmt(r.saldo)}</td>
        </tr>
    `).join('');

    // Gráfico
    dibujarGrafico(tabla);
}

function generarTabla(capital, tasa, plazo, amort, fechaBase) {
    const tabla = [];
    let saldo = capital;

    const cuotaFija = amort === 'frances'
        ? (tasa === 0 ? capital/plazo : capital * tasa / (1 - Math.pow(1+tasa, -plazo)))
        : 0;

    for (let i = 1; i <= plazo; i++) {
        const fecha = new Date(fechaBase);
        fecha.setMonth(fecha.getMonth() + i);
        const fechaStr = fecha.toISOString().split('T')[0];

        const interes = round(saldo * tasa);
        let capitalC, cuota;

        if (amort === 'frances') {
            cuota    = round(cuotaFija);
            capitalC = round(cuota - interes);
        } else if (amort === 'aleman') {
            capitalC = round(capital / plazo);
            cuota    = round(capitalC + interes);
        } else { // americano
            capitalC = i === plazo ? round(saldo) : 0;
            cuota    = round(interes + capitalC);
        }

        saldo = round(saldo - capitalC);
        tabla.push({ num:i, fecha:fechaStr, capital:capitalC, interes, cuota, saldo: saldo < 0 ? 0 : saldo });
    }
    return tabla;
}

function dibujarGrafico(tabla) {
    const ctx = document.getElementById('grafCanvas').getContext('2d');
    if (grafChart) grafChart.destroy();

    const labels   = tabla.map(r => `#${r.num}`);
    const capitales = tabla.map(r => r.capital);
    const intereses = tabla.map(r => r.interes);

    // Simple canvas bar chart (no external lib needed)
    const canvas = document.getElementById('grafCanvas');
    canvas.width = canvas.parentElement.offsetWidth - 40;
    const W = canvas.width, H = canvas.height = 280;
    ctx.clearRect(0,0,W,H);

    const maxVal = Math.max(...tabla.map(r => r.cuota));
    const barW = Math.max(2, (W - 60) / tabla.length - 2);
    const scaleY = (H - 50) / maxVal;

    tabla.forEach((r, i) => {
        const x = 50 + i * ((W-60)/tabla.length);
        // Capital (azul)
        const hCap = r.capital * scaleY;
        ctx.fillStyle = '#3b82f6';
        ctx.fillRect(x, H - 30 - hCap, barW, hCap);
        // Interés (naranja)
        const hInt = r.interes * scaleY;
        ctx.fillStyle = '#f97316';
        ctx.fillRect(x, H - 30 - hCap - hInt, barW, hInt);
    });

    // Eje Y label
    ctx.fillStyle = '#94a3b8';
    ctx.font = '11px sans-serif';
    ctx.fillText('RD$ ' + fmt(maxVal), 0, 15);

    // Leyenda
    ctx.fillStyle = '#3b82f6'; ctx.fillRect(50, H-22, 14, 12);
    ctx.fillStyle = '#374151'; ctx.fillText('Capital', 68, H-12);
    ctx.fillStyle = '#f97316'; ctx.fillRect(140, H-22, 14, 12);
    ctx.fillStyle = '#374151'; ctx.fillText('Interés', 158, H-12);
}

function showTab(t) {
    document.getElementById('tabAmortContent').style.display = t==='amort'?'block':'none';
    document.getElementById('tabGrafContent').style.display  = t==='graf' ?'block':'none';
    document.getElementById('tabAmort').classList.toggle('active', t==='amort');
    document.getElementById('tabGraf').classList.toggle('active', t==='graf');
    if (t==='graf') simular();
}

function round(n) { return Math.round(n * 100) / 100; }
function fmt(n) { return 'RD$ ' + (parseFloat(n)||0).toLocaleString('es-DO',{minimumFractionDigits:2,maximumFractionDigits:2}); }
</script>
<?php require_once __DIR__ . '/../php/partials/footer.php'; ?>
</body>
</html>
