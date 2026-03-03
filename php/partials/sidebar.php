<?php
/**
 * php/partials/sidebar.php — Sidebar GestionPrestamo
 * Variables esperadas: $sesion, $activePage, $iniciales, $nombreRol
 */

if (!function_exists('getDB')) {
    require_once __DIR__ . '/../../config/db.php';
}

$rolesAdmin   = ['superadmin','admin','gerente','supervisor','cajero'];
$rolesSistema = ['superadmin','admin'];

if (!isset($iniciales)) {
    $iniciales = implode('', array_map(
        fn($w) => strtoupper($w[0]),
        array_slice(explode(' ', trim($sesion['nombre'] ?? 'U')), 0, 2)
    )) ?: 'U';
}
if (!isset($nombreRol)) {
    $nombreRol = match($sesion['rol'] ?? '') {
        'superadmin' => 'Superadministrador', 'admin'  => 'Administrador',
        'gerente'    => 'Gerente',            'supervisor' => 'Supervisor',
        'cajero'     => 'Cajero',             'asesor' => 'Asesor',
        'auditor'    => 'Auditor',            'cliente'=> 'Cliente',
        default      => ucfirst($sesion['rol'] ?? 'Usuario'),
    };
}

if (!function_exists('navActive')) {
    function navActive(string $page, string $current): string {
        return $page === $current ? ' active' : '';
    }
}
$pag = $activePage ?? '';

// Refrescar foto/nombre cada 5 min
$_needRefresh = !array_key_exists('foto_path', $_SESSION)
    || (time() - ($_SESSION['_user_refresh_ts'] ?? 0)) > 300;
if (!empty($sesion['persona_id']) && $_needRefresh) {
    try {
        $_stRef = getDB()->prepare('SELECT CONCAT(nombre," ",apellido) AS n, foto_path FROM personas WHERE id=? LIMIT 1');
        $_stRef->execute([$sesion['persona_id']]);
        $_r = $_stRef->fetch();
        if ($_r) {
            $_SESSION['nombre'] = $_r['n']; $_SESSION['foto_path'] = $_r['foto_path'];
            $_SESSION['_user_refresh_ts'] = time();
            $sesion['nombre'] = $_r['n']; $sesion['foto_path'] = $_r['foto_path'];
            $iniciales = implode('', array_map(fn($w)=>strtoupper($w[0]),array_slice(explode(' ',trim($sesion['nombre'])),0,2)));
        }
    } catch (\Throwable) {}
} else {
    $sesion['foto_path'] = $_SESSION['foto_path'] ?? null;
}

// Brand desde DB
$_bn = 'GestionPrestamo'; $_bs = 'Sistema de Gestión de Préstamos'; $_bl = '';
try {
    $_stB = getDB()->prepare(
        'SELECT e.nombre AS en, cfg.nombre_empresa, cfg.slogan, cfg.logo_path
         FROM empresas e LEFT JOIN configuracion_empresa cfg ON cfg.id_empresa=e.id
         WHERE e.id=? LIMIT 1'
    );
    $_stB->execute([(int)($sesion['id_empresa'] ?? 1)]);
    $_rb = $_stB->fetch();
    if ($_rb) {
        $_bn = !empty($_rb['nombre_empresa']) ? $_rb['nombre_empresa'] : ($_rb['en'] ?: $_bn);
        $_bs = $_rb['slogan'] ?: $_bs;
        $_bl = $_rb['logo_path'] ?: '';
    }
} catch (\Throwable) {}
$_bn = htmlspecialchars($_bn); $_bs = htmlspecialchars($_bs); $_bl = htmlspecialchars($_bl);
?>
<aside class="sidebar" id="sidebar">

    <div class="sidebar-brand">
        <div class="brand-icon">
            <?php if ($_bl): ?>
                <img src="<?= $_bl ?>" alt="Logo" style="width:100%;height:100%;object-fit:contain;border-radius:8px">
            <?php else: ?>💰<?php endif; ?>
        </div>
        <div class="brand-text">
            <h2 title="<?= $_bn ?>"><?= $_bn ?></h2>
            <p><?= $_bs ?></p>
        </div>
    </div>

    <nav class="sidebar-nav">

        <a class="nav-item-solo<?= navActive('dashboard',$pag) ?>" href="/GestionPrestamo/">
            <span class="nav-icon">🏠</span> Dashboard
        </a>

        <!-- PRÉSTAMOS -->
        <div class="nav-group">
            <div class="nav-group-header <?= in_array($pag,['prestamos','mora','simulador','planes'],true)?'open':'' ?>"
                 onclick="toggleGroup(this)">
                <span>💰 Préstamos</span><span class="nav-group-chevron">▼</span>
            </div>
            <div class="nav-group-body <?= in_array($pag,['prestamos','mora','simulador','planes'],true)?'open':'' ?>">
                <div class="nav-group-inner">
                    <a class="nav-item<?= navActive('prestamos',$pag) ?>" href="/GestionPrestamo/prestamos">
                        <span class="nav-icon">💰</span> Gestión de Préstamos</a>
                    <a class="nav-item<?= navActive('mora',$pag) ?>" href="/GestionPrestamo/mora">
                        <span class="nav-icon">⚠️</span> Cartera en Mora</a>
                    <a class="nav-item<?= navActive('simulador',$pag) ?>" href="/GestionPrestamo/simulador">
                        <span class="nav-icon">🧮</span> Simulador</a>
                    <a class="nav-item<?= navActive('amortizacion',$pag) ?>" href="/GestionPrestamo/amortizacion">
                        <span class="nav-icon">📊</span> Amortizacion</a>
                    <?php if (in_array($sesion['rol'],['superadmin','admin','gerente'],true)): ?>
                    <a class="nav-item<?= navActive('planes',$pag) ?>" href="/GestionPrestamo/planes">
                        <span class="nav-icon">📋</span> Planes</a>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- COBROS & PAGOS -->
        <?php if (in_array($sesion['rol'],['superadmin','admin','gerente','cajero'],true)): ?>
        <div class="nav-group">
            <div class="nav-group-header <?= in_array($pag,['pagos','cartera'],true)?'open':'' ?>"
                 onclick="toggleGroup(this)">
                <span>💳 Cobros & Pagos</span><span class="nav-group-chevron">▼</span>
            </div>
            <div class="nav-group-body <?= in_array($pag,['pagos','cartera'],true)?'open':'' ?>">
                <div class="nav-group-inner">
                    <a class="nav-item<?= navActive('pagos',$pag) ?>" href="/GestionPrestamo/pagos">
                        <span class="nav-icon">💳</span> Registro de Pagos</a>
                    <a class="nav-item<?= navActive('cartera',$pag) ?>" href="/GestionPrestamo/cartera">
                        <span class="nav-icon">📊</span> Cartera General</a>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- PERSONAS -->
        <?php if (in_array($sesion['rol'],$rolesAdmin,true)): ?>
        <div class="nav-group">
            <div class="nav-group-header <?= $pag==='personas'?'open':'' ?>" onclick="toggleGroup(this)">
                <span>👥 Personas</span><span class="nav-group-chevron">▼</span>
            </div>
            <div class="nav-group-body <?= $pag==='personas'?'open':'' ?>">
                <div class="nav-group-inner">
                    <a class="nav-item<?= navActive('personas',$pag) ?>" href="/GestionPrestamo/personas">
                        <span class="nav-icon">👥</span> Todas las Personas</a>
                    <a class="nav-item" href="/GestionPrestamo/clientes">
                        <span class="nav-icon">🙍</span> Clientes</a>
                    <a class="nav-item" href="/GestionPrestamo/personas?tipo=Garante">
                        <span class="nav-icon">🛡️</span> Garantes</a>
                    <a class="nav-item" href="/GestionPrestamo/personas?tipo=Empleado">
                        <span class="nav-icon">💼</span> Empleados</a>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- GESTIÓN -->
        <?php if (in_array($sesion['rol'],$rolesAdmin,true)): ?>
        <div class="nav-group">
            <div class="nav-group-header <?= in_array($pag,['notificaciones','reportes'],true)?'open':'' ?>"
                 onclick="toggleGroup(this)">
                <span>📈 Gestión</span><span class="nav-group-chevron">▼</span>
            </div>
            <div class="nav-group-body <?= in_array($pag,['notificaciones','reportes'],true)?'open':'' ?>">
                <div class="nav-group-inner">
                    <a class="nav-item<?= navActive('notificaciones',$pag) ?>"
                       href="/GestionPrestamo/notificaciones"
                       style="display:flex;align-items:center;gap:4px">
                        <span class="nav-icon">🔔</span> Notificaciones
                        <?php
                        try {
                            $_idEmp = (int)($sesion['id_empresa'] ?? 0);
                            if ($_idEmp) {
                                $_stN = getDB()->prepare(
                                    "SELECT COUNT(*) FROM cuotas
                                     WHERE id_empresa=? AND estado IN ('pendiente','parcial')
                                       AND fecha_vence BETWEEN CURDATE() AND DATE_ADD(CURDATE(),INTERVAL 3 DAY)"
                                );
                                $_stN->execute([$_idEmp]);
                                $_cntN = (int)$_stN->fetchColumn();
                                if ($_cntN > 0): ?>
                                <span style="margin-left:auto;background:#ef4444;color:#fff;border-radius:20px;padding:1px 7px;font-size:.62rem;font-weight:700;flex-shrink:0"><?= $_cntN ?></span>
                                <?php endif;
                            }
                        } catch (\Throwable) {}
                        ?>
                    </a>
                    <a class="nav-item<?= navActive('reportes',$pag) ?>" href="/GestionPrestamo/reportes">
                        <span class="nav-icon">📈</span> Reportes</a>
                </div>
            </div>
        </div>
        <?php endif; ?>

        
        <!-- RUTAS DE COBRANZA -->
        <div class="nav-group">
            <div class="nav-group-header <?= in_array($pag,['rutas','visitas'],true)?'open':'' ?>"
                 onclick="toggleGroup(this)">
                <span>🗺️ Rutas</span><span class="nav-group-chevron">▼</span>
            </div>
            <div class="nav-group-body <?= in_array($pag,['rutas','visitas'],true)?'open':'' ?>">
                <div class="nav-group-inner">
                    <a class="nav-item<?= navActive('rutas',$pag) ?>" href="/GestionPrestamo/rutas">
                        <span class="nav-icon">🗺️</span> Rutas de Cobranza</a>
                    <a class="nav-item<?= navActive('visitas',$pag) ?>" href="/GestionPrestamo/visitas">
                        <span class="nav-icon">📍</span> Visitas</a>
                </div>
            </div>
        </div>
        <!-- SISTEMA -->
        <?php if (in_array($sesion['rol'],$rolesSistema,true)): ?>
        <div class="nav-group">
            <div class="nav-group-header <?= in_array($pag,['usuarios','auditoria','configuracion'],true)?'open':'' ?>"
                 onclick="toggleGroup(this)">
                <span>🔐 Sistema</span><span class="nav-group-chevron">▼</span>
            </div>
            <div class="nav-group-body <?= in_array($pag,['usuarios','auditoria','configuracion'],true)?'open':'' ?>">
                <div class="nav-group-inner">
                    <a class="nav-item<?= navActive('usuarios',$pag) ?>" href="/GestionPrestamo/usuarios">
                        <span class="nav-icon">🔐</span> Usuarios & Roles</a>
                    <a class="nav-item<?= navActive('auditoria',$pag) ?>" href="/GestionPrestamo/auditoria">
                        <span class="nav-icon">🗂️</span> Auditoría</a>
                    <a class="nav-item<?= navActive('configuracion',$pag) ?>" href="/GestionPrestamo/configuracion">
                        <span class="nav-icon">⚙️</span> Configuración</a>
                </div>
            </div>
        </div>
        <?php endif; ?>

    </nav>

    <div class="sidebar-footer">
        <div class="user-card">
            <div class="avatar-wrap">
                <?php if (!empty($sesion['foto_path'])): ?>
                    <div class="avatar" style="overflow:hidden;padding:0;background:transparent">
                        <img src="/GestionPrestamo/uploads/fotos/<?= htmlspecialchars($sesion['foto_path']) ?>"
                             alt="<?= htmlspecialchars($sesion['nombre']) ?>"
                             style="width:100%;height:100%;object-fit:cover;border-radius:50%">
                    </div>
                <?php else: ?>
                    <div class="avatar"><?= htmlspecialchars($iniciales) ?></div>
                <?php endif; ?>
                <span class="avatar-online" title="En línea"></span>
            </div>
            <a class="user-info" href="/GestionPrestamo/perfil" title="Editar perfil"
               style="text-decoration:none;color:inherit;flex:1;min-width:0;cursor:pointer">
                <strong><?= htmlspecialchars($sesion['nombre']) ?></strong>
                <span>
                    <span class="user-role-badge"><?= htmlspecialchars($nombreRol) ?></span>
                    <span style="font-size:.6rem;color:var(--primary);opacity:.7;margin-left:2px">✏️</span>
                </span>
            </a>
            <button class="logout-btn" onclick="logout()" title="Cerrar sesión">
                <svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24"
                     fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/>
                    <polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/>
                </svg>
            </button>
        </div>
    </div>

</aside>
<div id="overlay" class="overlay" onclick="toggleSidebar()"></div>