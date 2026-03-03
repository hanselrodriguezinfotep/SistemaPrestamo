<?php
// api/cuotas.php — Gestión de Cuotas y Mora | GestionPrestamo
set_error_handler(function(int $errno, string $errstr, string $errfile, int $errline): bool {
    if (!(error_reporting() & $errno)) return false;
    http_response_code(500); header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => "Error PHP: $errstr en linea $errline"], JSON_UNESCAPED_UNICODE); exit;
});
set_exception_handler(function(\Throwable $e): void {
    http_response_code(500); header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE); exit;
});
ini_set('display_errors', '0');

require_once __DIR__ . '/../php/helpers.php';

apiHeaders();
$sesion = verificarSesionAPI();

$roles = ['superadmin','admin','gerente','supervisor','cajero'];
if (!in_array($sesion['rol'], $roles, true)) jsonError('Acceso denegado', 403);

$id_empresa = (int)($sesion['id_empresa'] ?? 0);
if (!$id_empresa) jsonError('Empresa no identificada en sesión', 400);

$uid = $sesion['usuario_id'];
$db  = getDB();

$GLOBALS['_jsonBody'] = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $raw = file_get_contents('php://input');
    if ($raw) { $d = json_decode($raw, true); if (is_array($d)) $GLOBALS['_jsonBody'] = $d; }
}

$action = sanitizeStr($_GET['action'] ?? '');
$method = $_SERVER['REQUEST_METHOD'];

match(true) {
    $action === 'listar'        && $method === 'GET'  => listar(),
    $action === 'proximas'      && $method === 'GET'  => proximas(),
    $action === 'mora'          && $method === 'GET'  => listarMora(),
    $action === 'calcular_mora' && $method === 'POST' => calcularMora($GLOBALS['_jsonBody']),
    default => jsonError('Acción no válida', 404),
};

// ── LISTAR cuotas de un préstamo ──────────────────────────────────────────────
function listar(): never {
    global $db, $id_empresa;
    $id_prestamo = (int)($_GET['id_prestamo'] ?? 0);
    if (!$id_prestamo) jsonError('id_prestamo requerido');

    // Verificar que el préstamo pertenece a la empresa
    $pr = $db->prepare("SELECT id_empresa FROM prestamos WHERE id = ? LIMIT 1");
    $pr->execute([$id_prestamo]);
    $p = $pr->fetch();
    if (!$p) jsonError('Préstamo no encontrado', 404);
    if ((int)$p['id_empresa'] !== $id_empresa) jsonError('Acceso denegado', 403);

    $stmt = $db->prepare("
        SELECT *,
               DATEDIFF(CURDATE(), fecha_vence) AS dias_atraso
        FROM cuotas
        WHERE id_prestamo = ?
        ORDER BY numero
    ");
    $stmt->execute([$id_prestamo]);
    jsonResponse(['ok' => true, 'data' => $stmt->fetchAll()]);
}

// ── PRÓXIMAS cuotas a vencer ──────────────────────────────────────────────────
function proximas(): never {
    global $db, $id_empresa;
    $dias = max(1, min(60, (int)($_GET['dias'] ?? 30)));

    $stmt = $db->prepare("
        SELECT c.*,
               pr.codigo AS prestamo_codigo,
               CONCAT(pe.nombre,' ',pe.apellido) AS cliente_nombre,
               pe.cedula,
               DATEDIFF(c.fecha_vence, CURDATE()) AS dias_para_vencer
        FROM cuotas c
        JOIN prestamos pr ON pr.id = c.id_prestamo
        JOIN personas  pe ON pe.id = pr.id_persona
        WHERE c.id_empresa = ?
          AND c.estado IN ('pendiente','parcial')
          AND c.fecha_vence BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL ? DAY)
        ORDER BY c.fecha_vence ASC
        LIMIT 200
    ");
    $stmt->execute([$id_empresa, $dias]);
    jsonResponse(['ok' => true, 'data' => $stmt->fetchAll()]);
}

// ── LISTAR cuotas en MORA ─────────────────────────────────────────────────────
function listarMora(): never {
    global $db, $id_empresa;

    $stmt = $db->prepare("
        SELECT c.*,
               DATEDIFF(CURDATE(), c.fecha_vence) AS dias_mora_real,
               pr.codigo AS prestamo_codigo,
               pr.tasa_interes,
               CONCAT(pe.nombre,' ',pe.apellido) AS cliente_nombre,
               pe.cedula
        FROM cuotas c
        JOIN prestamos pr ON pr.id = c.id_prestamo
        JOIN personas  pe ON pe.id = pr.id_persona
        WHERE c.id_empresa = ?
          AND c.estado IN ('pendiente','parcial','mora')
          AND c.fecha_vence < CURDATE()
        ORDER BY dias_mora_real DESC
        LIMIT 500
    ");
    $stmt->execute([$id_empresa]);
    jsonResponse(['ok' => true, 'data' => $stmt->fetchAll()]);
}

// ── CALCULAR Y REGISTRAR mora ─────────────────────────────────────────────────
function calcularMora(array $d): never {
    global $db, $id_empresa, $uid;
    $tasa_mora = max(0, min(1, (float)($d['tasa_mora'] ?? 0.02)));

    $stmt = $db->prepare("
        SELECT c.*, pr.id_empresa AS empresa_id
        FROM cuotas c
        JOIN prestamos pr ON pr.id = c.id_prestamo
        WHERE c.id_empresa = ?
          AND c.estado IN ('pendiente','parcial')
          AND c.fecha_vence < CURDATE()
    ");
    $stmt->execute([$id_empresa]);
    $cuotas      = $stmt->fetchAll();
    $actualizadas = 0;

    foreach ($cuotas as $c) {
        // Calcular días reales de mora
        $stDias = $db->prepare("SELECT DATEDIFF(CURDATE(), ?)");
        $stDias->execute([$c['fecha_vence']]);
        $diasReal = (int)$stDias->fetchColumn();
        if ($diasReal <= 0) continue;

        $saldoCuota = round($c['monto_total'] - $c['monto_pagado'], 2);
        $monto_mora = round($saldoCuota * $tasa_mora * ($diasReal / 30), 2);

        $db->prepare("UPDATE cuotas SET dias_mora = ?, interes_mora = ?, estado = 'mora' WHERE id = ?")
           ->execute([$diasReal, $monto_mora, $c['id']]);

        // Registrar en mora_registro solo si no existe pendiente
        $ex = $db->prepare("SELECT id FROM mora_registro WHERE id_cuota = ? AND estado = 'pendiente' LIMIT 1");
        $ex->execute([$c['id']]);
        if (!$ex->fetch()) {
            $db->prepare("
                INSERT INTO mora_registro
                    (id_empresa, id_prestamo, id_cuota, dias_mora, tasa_mora, monto_mora, estado, generado_en)
                VALUES (?,?,?,?,?,?,'pendiente',NOW())
            ")->execute([
                $id_empresa, $c['id_prestamo'], $c['id'],
                $diasReal, $tasa_mora, $monto_mora,
            ]);
        }

        // Marcar préstamo como moroso
        $db->prepare("UPDATE prestamos SET estado = 'moroso' WHERE id = ? AND estado = 'activo'")
           ->execute([$c['id_prestamo']]);

        $actualizadas++;
    }

    registrarAudit($uid, 'mora.calcular', 1, "$actualizadas cuotas actualizadas a mora");
    jsonResponse(['ok' => true, 'actualizadas' => $actualizadas,
                  'mensaje' => "$actualizadas cuotas marcadas en mora"]);
}