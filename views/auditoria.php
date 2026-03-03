<?php
// views/auditoria.php — Log de Auditoría | GestionPrestamo
require_once __DIR__ . '/../config/session.php';
$sesion = verificarSesion();

$roles = ['superadmin','admin'];
if (!in_array($sesion['rol'], $roles, true)) { header('Location: /GestionPrestamo/'); exit; }

$activePage   = 'auditoria';
$id_empresa   = (int)($sesion['id_empresa'] ?? 0);

// Cargar log desde DB directamente (no hay API dedicada)
$registros = [];
try {
    require_once __DIR__ . '/../config/db.php';
    $db    = getDB();
    $stmt  = $db->prepare("
        SELECT al.*,
               CONCAT(p.nombre,' ',p.apellido) AS nombre_usuario,
               u.username
        FROM audit_log al
        LEFT JOIN usuarios u ON u.id = al.id_usuario
        LEFT JOIN personas p ON p.id = u.id_persona
        WHERE al.id_empresa = ?
        ORDER BY al.fecha DESC
        LIMIT 500
    ");
    $stmt->execute([$id_empresa]);
    $registros = $stmt->fetchAll();
} catch (\Throwable $e) {
    $errorDB = $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <?php $pageTitle = 'Auditoría'; require_once __DIR__ . '/../php/partials/head.php'; ?>
    <style>
        .toolbar { display:flex; align-items:center; gap:10px; flex-wrap:wrap; margin-bottom:16px; }
        .toolbar input { flex:1; min-width:180px; padding:9px 12px; border:1.5px solid var(--border); border-radius:9px; font-size:.83rem; }
        .tbl-wrap { overflow-x:auto; -webkit-overflow-scrolling:touch; }
        .table-card { background:var(--surface); border:1.5px solid var(--border); border-radius:12px; overflow:hidden; box-shadow:var(--shadow); }
        table { width:100%; border-collapse:collapse; font-size:.82rem; min-width:640px; }
        thead th { background:var(--bg); padding:10px 14px; text-align:left; font-size:.68rem; font-weight:800; color:var(--muted); text-transform:uppercase; border-bottom:1px solid var(--border); white-space:nowrap; }
        tbody tr { border-bottom:1px solid var(--border); }
        tbody tr:hover { background:var(--bg); }
        tbody td { padding:9px 14px; vertical-align:middle; }
        .badge-ok  { display:inline-flex; padding:2px 9px; border-radius:99px; font-size:.68rem; font-weight:700; background:#d1fae5; color:#065f46; }
        .badge-err { display:inline-flex; padding:2px 9px; border-radius:99px; font-size:.68rem; font-weight:700; background:#fee2e2; color:#991b1b; }
        @media(max-width:768px) { .toolbar { flex-direction:column; align-items:stretch; } }
    </style>
</head>
<body>
<div class="app">
    <?php require_once __DIR__ . '/../php/partials/sidebar.php'; ?>
    <main class="main">
        <header class="header">
            <button class="hamburger" onclick="toggleSidebar()">☰</button>
            <div class="header-title">
                <h1>🗂️ Auditoría</h1>
                <p>Registro de actividad del sistema</p>
            </div>
            <div class="header-actions">
                <span style="font-size:.8rem;color:var(--muted)"><?= count($registros) ?> registros</span>
            </div>
        </header>
        <div class="page">

            <div class="toolbar">
                <input type="text" id="buscador" placeholder="🔍 Buscar acción, usuario, IP…"
                       oninput="filtrar()" data-no-search>
                <select id="fil-exito" data-no-search onchange="filtrar()">
                    <option value="">Todos</option>
                    <option value="1">Exitosos</option>
                    <option value="0">Con error</option>
                </select>
            </div>

            <?php if (!empty($errorDB)): ?>
            <div style="background:#fee2e2;border:1px solid #fca5a5;border-radius:10px;padding:14px;margin-bottom:16px;color:#991b1b;font-size:.84rem">
                ⚠️ Error al cargar auditoría: <?= htmlspecialchars($errorDB) ?>
            </div>
            <?php endif; ?>

            <div class="table-card">
                <div class="tbl-wrap">
                    <table id="tabla">
                        <thead>
                            <tr>
                                <th>Fecha</th><th>Usuario</th><th>Acción</th>
                                <th>Detalle</th><th>IP</th><th>Resultado</th>
                            </tr>
                        </thead>
                        <tbody id="tbody">
                            <?php if (empty($registros)): ?>
                            <tr><td colspan="6" style="text-align:center;padding:48px;color:var(--muted)">No hay registros de auditoría</td></tr>
                            <?php else: ?>
                            <?php foreach ($registros as $r): ?>
                            <tr data-usuario="<?= htmlspecialchars(strtolower($r['username'] ?? '')) ?>"
                                data-accion="<?= htmlspecialchars(strtolower($r['accion'] ?? '')) ?>"
                                data-ip="<?= htmlspecialchars($r['ip'] ?? '') ?>"
                                data-exito="<?= (int)$r['exitoso'] ?>">
                                <td style="white-space:nowrap;font-size:.78rem"><?= htmlspecialchars($r['fecha'] ?? '') ?></td>
                                <td>
                                    <div style="font-weight:600"><?= htmlspecialchars($r['nombre_usuario'] ?? '—') ?></div>
                                    <div style="font-size:.7rem;color:var(--muted)"><?= htmlspecialchars($r['username'] ?? '') ?></div>
                                </td>
                                <td><code style="font-size:.75rem;background:var(--bg);padding:2px 6px;border-radius:5px"><?= htmlspecialchars($r['accion'] ?? '') ?></code></td>
                                <td style="max-width:260px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;color:var(--muted);font-size:.78rem"
                                    title="<?= htmlspecialchars($r['detalle'] ?? '') ?>">
                                    <?= htmlspecialchars(mb_substr($r['detalle'] ?? '', 0, 80)) ?>
                                </td>
                                <td style="font-size:.75rem;color:var(--muted)"><?= htmlspecialchars($r['ip'] ?? '') ?></td>
                                <td>
                                    <?php if ($r['exitoso']): ?>
                                        <span class="badge-ok">✓ OK</span>
                                    <?php else: ?>
                                        <span class="badge-err">✕ Error</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </main>
</div>

<script>
function filtrar() {
    const q   = document.getElementById('buscador').value.toLowerCase();
    const ext = document.getElementById('fil-exito').value;
    document.querySelectorAll('#tbody tr[data-accion]').forEach(tr => {
        const matchQ = !q || tr.dataset.usuario.includes(q) || tr.dataset.accion.includes(q) || tr.dataset.ip.includes(q);
        const matchE = !ext || tr.dataset.exito === ext;
        tr.style.display = matchQ && matchE ? '' : 'none';
    });
}
</script>
<?php require_once __DIR__ . '/../php/partials/footer.php'; ?>
</body>
</html>
