<?php
// api/estadisticas.php — optimizado
require_once __DIR__ . '/../php/helpers.php';

apiHeaders();
verificarSesion();

// Caché simple en sesión (30 segundos)
$cacheKey = 'stats_cache';
$cacheTTL = 30;

if (
    isset($_SESSION[$cacheKey], $_SESSION[$cacheKey . '_ts']) &&
    (time() - $_SESSION[$cacheKey . '_ts']) < $cacheTTL
) {
    echo $_SESSION[$cacheKey];
    exit;
}

$db = getDB();

// KPIs en una sola query
$kpis = $db->query("
    SELECT
        COUNT(*) AS total_prestamos,
        SUM(CASE WHEN estado = 'activo'    THEN 1 ELSE 0 END) AS activos,
        SUM(CASE WHEN estado = 'pagado'    THEN 1 ELSE 0 END) AS pagados,
        SUM(CASE WHEN estado = 'vencido'   THEN 1 ELSE 0 END) AS vencidos,
        SUM(CASE WHEN estado = 'cancelado' THEN 1 ELSE 0 END) AS cancelados,
        COALESCE(SUM(monto_principal), 0) AS total_capital_colocado,
        COALESCE(SUM(total_pagado), 0) AS total_cobrado,
        COALESCE(SUM(monto_total - total_pagado), 0) AS total_por_cobrar
    FROM prestamos
")->fetch();

// Cuotas vencidas sin usar la vista
$vencidas = $db->query("
    SELECT COUNT(*) AS cantidad, COALESCE(SUM(cp.monto_cuota), 0) AS monto
    FROM calendario_pagos cp
    JOIN prestamos p ON cp.prestamo_id = p.id
    WHERE cp.pagado = 0
      AND cp.fecha_vencimiento < CURDATE()
      AND p.estado = 'activo'
")->fetch();

// Total clientes
$clientes = $db->query("SELECT COUNT(*) AS total FROM clientes WHERE activo = 1")->fetch();

// Cobros del mes
$cobros_mes = $db->query("
    SELECT COALESCE(SUM(monto), 0) AS monto
    FROM pagos
    WHERE anulado = 0
      AND fecha_pago >= DATE_FORMAT(CURDATE(), '%Y-%m-01')
")->fetch();

// Prestamos por mes
$prestamos_por_mes = $db->query("
    SELECT DATE_FORMAT(fecha_inicio, '%Y-%m') AS mes,
           COUNT(*) AS cantidad,
           SUM(monto_principal) AS monto
    FROM prestamos
    WHERE fecha_inicio >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)
    GROUP BY DATE_FORMAT(fecha_inicio, '%Y-%m')
    ORDER BY mes ASC
")->fetchAll();

// Pagos por metodo
$pagos_por_metodo = $db->query("
    SELECT metodo_pago, COUNT(*) AS cantidad, SUM(monto) AS monto
    FROM pagos
    WHERE anulado = 0
    GROUP BY metodo_pago
    ORDER BY monto DESC
")->fetchAll();

// Top 5 clientes
$top_clientes = $db->query("
    SELECT CONCAT(c.nombre, ' ', c.apellido) AS cliente,
           COUNT(p.id) AS prestamos,
           SUM(p.monto_principal) AS total_prestado
    FROM clientes c
    JOIN prestamos p ON p.cliente_id = c.id
    GROUP BY c.id, c.nombre, c.apellido
    ORDER BY total_prestado DESC
    LIMIT 5
")->fetchAll();

$response = json_encode([
    'kpis'              => $kpis,
    'cuotas_vencidas'   => $vencidas,
    'total_clientes'    => (int) $clientes['total'],
    'cobros_mes_actual' => (float) $cobros_mes['monto'],
    'prestamos_por_mes' => $prestamos_por_mes,
    'pagos_por_metodo'  => $pagos_por_metodo,
    'top_clientes'      => $top_clientes,
    'generado_en'       => date('Y-m-d H:i:s'),
], JSON_UNESCAPED_UNICODE);

$_SESSION[$cacheKey]         = $response;
$_SESSION[$cacheKey . '_ts'] = time();

echo $response;