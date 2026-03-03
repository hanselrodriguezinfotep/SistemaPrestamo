<?php
// router.php — Despachador central de vistas GestionPrestamo
require_once __DIR__ . '/config/session.php';

$pagina = basename($_GET['p'] ?? 'dashboard');

$vistas = [
    'dashboard'      => __DIR__ . '/views/dashboard.php',
    'clientes'       => __DIR__ . '/views/clientes.php',
    'personas'       => __DIR__ . '/views/personas.php',
    'usuarios'       => __DIR__ . '/views/usuarios.php',
    'configuracion'  => __DIR__ . '/views/configuracion.php',
    'notificaciones' => __DIR__ . '/views/notificaciones.php',
    'perfil'         => __DIR__ . '/views/perfil.php',
    'prestamos'      => __DIR__ . '/views/prestamos.php',
    'planes'         => __DIR__ . '/views/planes.php',
    'mora'           => __DIR__ . '/views/mora.php',
    'simulador'      => __DIR__ . '/views/simulador.php',
    'amortizacion'   => __DIR__ . '/views/amortizacion.php',
    'pagos'          => __DIR__ . '/views/pagos.php',
    'cartera'        => __DIR__ . '/views/cartera.php',
    'rutas'          => __DIR__ . '/views/rutas.php',
    'visitas'        => __DIR__ . '/views/visitas.php',
    'auditoria'      => __DIR__ . '/views/auditoria.php',
    'reportes'       => __DIR__ . '/views/reportes.php',
];

if (isset($vistas[$pagina]) && file_exists($vistas[$pagina])) {
    require $vistas[$pagina];
    exit;
}

$sesion = verificarSesion();
$iniciales = implode('', array_map(fn($w) => strtoupper($w[0]), array_slice(explode(' ', trim($sesion['nombre'] ?? 'U')), 0, 2))) ?: 'U';
$nombreRol = ucfirst($sesion['rol'] ?? 'Usuario');
$activePage = $pagina;
$pageTitle  = '404';
?>
<!DOCTYPE html>
<html lang="es">
<head><?php require_once __DIR__ . '/php/partials/head.php'; ?></head>
<body>
<div class="app">
<?php require_once __DIR__ . '/php/partials/sidebar.php'; ?>
<div class="main"><main class="page" style="display:flex;align-items:center;justify-content:center;min-height:80vh">
<div style="text-align:center"><div style="font-size:3rem">🔍</div>
<h2>Pagina no encontrada</h2>
<a href="/GestionPrestamo/" style="color:var(--primary)">Volver al inicio</a>
</div></main></div></div>
<?php require_once __DIR__ . '/php/partials/footer.php'; ?>
</body></html>
