<?php
// views/reportes.php — Reportes | GestionPrestamo
require_once __DIR__ . '/../config/session.php';
$sesion = verificarSesion();

$roles = ['superadmin','admin','gerente','supervisor','cajero','asesor'];
if (!in_array($sesion['rol'], $roles, true)) { header('Location: /GestionPrestamo/'); exit; }

$activePage = 'reportes';
$id_empresa = (int)($sesion['id_empresa'] ?? 0);

// Stats desde DB
$stats = ['prestamos'=>[], 'pagos_mes'=>0, 'cuotas_vencer'=>0, 'mora_monto'=>0.0];
if ($id_empresa) {
    try {
        require_once __DIR__ . '/../config/db.php';
        $db = getDB();

        // Préstamos por estado
        $r = $db->prepare("SELECT estado, COUNT(*) AS total, COALESCE(SUM(capital),0) AS capital,
                                   COALESCE(SUM(saldo_pendiente),0) AS saldo
                           FROM prestamos WHERE id_empresa=? GROUP BY estado");
        $r->execute([$id_empresa]);
        $stats['prestamos'] = $r->fetchAll();

        // Pagos del mes actual
        $r2 = $db->prepare("SELECT COALESCE(SUM(monto),0) AS total FROM pagos
                            WHERE id_empresa=? AND MONTH(creado_en)=MONTH(CURDATE())
                              AND YEAR(creado_en)=YEAR(CURDATE()) AND estado='aplicado'");
        $r2->execute([$id_empresa]);
        $stats['pagos_mes'] = (float)$r2->fetchColumn();

        // Cuotas a vencer en 7 días
        $r3 = $db->prepare("SELECT COUNT(*) FROM cuotas WHERE id_empresa=?
                             AND estado IN ('pendiente','parcial')
                             AND fecha_vence BETWEEN CURDATE() AND DATE_ADD(CURDATE(),INTERVAL 7 DAY)");
        $r3->execute([$id_empresa]);
        $stats['cuotas_vencer'] = (int)$r3->fetchColumn();

        // Monto total en mora
        $r4 = $db->prepare("SELECT COALESCE(SUM(monto_total - monto_pagado),0) FROM cuotas
                             WHERE id_empresa=? AND estado='mora'");
        $r4->execute([$id_empresa]);
        $stats['mora_monto'] = (float)$r4->fetchColumn();

    } catch (\Throwable) {}
}

function fmtMoney(float $n): string {
    return 'RD$ ' . number_format($n, 2, '.', ',');
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <?php $pageTitle = 'Reportes'; require_once __DIR__ . '/../php/partials/head.php'; ?>
    <style>
        .section-title { font-size:.85rem; font-weight:800; color:var(--muted); text-transform:uppercase;
            letter-spacing:.06em; margin:28px 0 12px; }
        .kpi-grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(150px,1fr)); gap:14px; margin-bottom:4px; }
        .kpi { background:var(--surface); border:1.5px solid var(--border); border-radius:12px; padding:18px 20px; box-shadow:var(--shadow); }
        .kpi-label { font-size:.68rem; font-weight:800; color:var(--muted); text-transform:uppercase; letter-spacing:.06em; margin-bottom:6px; }
        .kpi-val   { font-size:1.4rem; font-weight:800; }
        .kpi.blue  .kpi-val { color:#1d4ed8; }
        .kpi.green .kpi-val { color:#16a34a; }
        .kpi.red   .kpi-val { color:#dc2626; }
        .kpi.amber .kpi-val { color:#d97706; }
        .kpi-sub   { font-size:.72rem; color:var(--muted); margin-top:3px; }

        .estado-grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(200px,1fr)); gap:14px; }
        .estado-card { background:var(--surface); border:1.5px solid var(--border); border-radius:12px; padding:18px; box-shadow:var(--shadow); }
        .estado-card h4 { font-size:.8rem; font-weight:800; margin-bottom:12px; display:flex; align-items:center; gap:8px; }
        .estado-card .val { font-size:1.8rem; font-weight:800; margin-bottom:2px; }
        .estado-card .sub { font-size:.78rem; color:var(--muted); }
        .bar-container { margin-top:12px; }
        .bar-label { display:flex; justify-content:space-between; font-size:.72rem; color:var(--muted); margin-bottom:4px; }
        .bar { height:8px; border-radius:4px; background:#e2e8f0; overflow:hidden; }
        .bar-fill { height:100%; border-radius:4px; }
        @media(max-width:768px) { .kpi-grid, .estado-grid { grid-template-columns:repeat(2,1fr); gap:10px; } }
        @media(max-width:480px) { .kpi-grid, .estado-grid { grid-template-columns:1fr; } }
    </style>
</head>
<body>
<div class="app">
    <?php require_once __DIR__ . '/../php/partials/sidebar.php'; ?>
    <main class="main">
        <header class="header">
            <button class="hamburger" onclick="toggleSidebar()">☰</button>
            <div class="header-title">
                <h1>📈 Reportes</h1>
                <p>Estadísticas e indicadores del sistema</p>
            </div>
            <div class="header-actions">
                <span style="font-size:.78rem;color:var(--muted)">
                    Actualizado: <?= date('d/m/Y H:i') ?>
                </span>
            </div>
        </header>
        <div class="page">

            <!-- KPIs principales -->
            <p class="section-title">📊 Indicadores del mes</p>
            <div class="kpi-grid">
                <div class="kpi green">
                    <div class="kpi-label">Cobros del Mes</div>
                    <div class="kpi-val" style="font-size:1.05rem"><?= fmtMoney($stats['pagos_mes']) ?></div>
                    <div class="kpi-sub"><?= date('F Y') ?></div>
                </div>
                <div class="kpi amber">
                    <div class="kpi-label">Cuotas a Vencer</div>
                    <div class="kpi-val"><?= $stats['cuotas_vencer'] ?></div>
                    <div class="kpi-sub">Próximos 7 días</div>
                </div>
                <div class="kpi red">
                    <div class="kpi-label">Saldo en Mora</div>
                    <div class="kpi-val" style="font-size:1.05rem"><?= fmtMoney($stats['mora_monto']) ?></div>
                    <div class="kpi-sub">Cuotas vencidas</div>
                </div>
            </div>

            <!-- Cartera por estado -->
            <p class="section-title">💰 Cartera por estado</p>
            <?php
            $totalPrestamos = array_sum(array_column($stats['prestamos'], 'total'));
            $colores = [
                'activo'   => ['#1d4ed8','#dbeafe'],
                'moroso'   => ['#dc2626','#fee2e2'],
                'pagado'   => ['#16a34a','#d1fae5'],
                'cancelado'=> ['#64748b','#f1f5f9'],
                'pendiente'=> ['#d97706','#fef3c7'],
            ];
            ?>
            <div class="estado-grid">
                <?php foreach ($stats['prestamos'] as $e): ?>
                <?php
                    $clr  = $colores[$e['estado']] ?? ['#475569','#f1f5f9'];
                    $pct  = $totalPrestamos > 0 ? round($e['total']/$totalPrestamos*100) : 0;
                ?>
                <div class="estado-card">
                    <h4 style="color:<?= $clr[0] ?>">
                        <span style="background:<?= $clr[1] ?>;padding:4px 8px;border-radius:6px"><?= htmlspecialchars(ucfirst($e['estado'])) ?></span>
                    </h4>
                    <div class="val" style="color:<?= $clr[0] ?>"><?= $e['total'] ?></div>
                    <div class="sub">Capital: <?= fmtMoney($e['capital']) ?></div>
                    <div class="sub">Saldo: <?= fmtMoney($e['saldo']) ?></div>
                    <div class="bar-container">
                        <div class="bar-label"><span>Del total</span><span><?= $pct ?>%</span></div>
                        <div class="bar"><div class="bar-fill" style="width:<?= $pct ?>%;background:<?= $clr[0] ?>"></div></div>
                    </div>
                </div>
                <?php endforeach; ?>
                <?php if (empty($stats['prestamos'])): ?>
                <div class="estado-card" style="grid-column:1/-1;text-align:center;color:var(--muted)">
                    No hay préstamos registrados aún.
                </div>
                <?php endif; ?>
            </div>

        </div>
    </main>
</div>
<?php require_once __DIR__ . '/../php/partials/footer.php'; ?>
</body>
</html>
