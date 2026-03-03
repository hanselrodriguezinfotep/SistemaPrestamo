<?php
// api/planes.php — Planes de Préstamo | GestionPrestamo
set_error_handler(function(int $errno, string $errstr, string $errfile, int $errline): bool {
    if (!(error_reporting() & $errno)) return false;
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => "Error PHP ($errno): $errstr en linea $errline"], JSON_UNESCAPED_UNICODE);
    exit;
});
set_exception_handler(function(\Throwable $e): void {
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => 'Error interno: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
    exit;
});
ini_set('display_errors', '0');

require_once __DIR__ . '/../php/helpers.php';

apiHeaders();
$sesion = verificarSesionAPI();

$roles = ['superadmin','admin','gerente','supervisor','cajero'];
if (!in_array($sesion['rol'], $roles, true)) jsonError('Acceso denegado', 403);

$esSuperadmin = $sesion['rol'] === 'superadmin';
$id_empresa   = (int)($sesion['id_empresa'] ?? 0);
$uid          = $sesion['usuario_id'];
$db           = getDB();

$GLOBALS['_jsonBody'] = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $raw = file_get_contents('php://input');
    if ($raw) { $d = json_decode($raw, true); if (is_array($d)) $GLOBALS['_jsonBody'] = $d; }
}

$action = sanitizeStr($_GET['action'] ?? '');
$method = $_SERVER['REQUEST_METHOD'];

match(true) {
    $action === 'listar'   && $method === 'GET'  => listar(),
    $action === 'obtener'  && $method === 'GET'  => obtener(),
    $action === 'guardar'  && $method === 'POST' => guardar($GLOBALS['_jsonBody']),
    $action === 'eliminar' && $method === 'POST' => eliminar($GLOBALS['_jsonBody']),
    default => jsonError('Acción no válida', 404),
};

// ── LISTAR ──────────────────────────────────────────────────────────────────
function listar(): never {
    global $db, $esSuperadmin, $id_empresa;
    if ($esSuperadmin) {
        $stmt = $db->query("SELECT p.*, e.nombre AS empresa_nombre FROM planes_prestamo p
                            LEFT JOIN empresas e ON e.id = p.id_empresa
                            ORDER BY p.id_empresa, p.nombre");
    } else {
        $stmt = $db->prepare("SELECT * FROM planes_prestamo WHERE (id_empresa = ? OR id_empresa IS NULL) ORDER BY nombre");
        $stmt->execute([$id_empresa]);
    }
    jsonResponse(['ok' => true, 'data' => $stmt->fetchAll()]);
}

// ── OBTENER ─────────────────────────────────────────────────────────────────
function obtener(): never {
    global $db, $esSuperadmin, $id_empresa;
    $id = (int)($_GET['id'] ?? 0);
    if (!$id) jsonError('ID requerido');
    $stmt = $db->prepare("SELECT * FROM planes_prestamo WHERE id = ? LIMIT 1");
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    if (!$row) jsonError('Plan no encontrado', 404);
    if (!$esSuperadmin && $row['id_empresa'] && $row['id_empresa'] != $id_empresa) jsonError('Acceso denegado', 403);
    jsonResponse(['ok' => true, 'data' => $row]);
}

// ── GUARDAR (crear/editar) ────────────────────────────────────────────────────
function guardar(array $d): never {
    global $db, $esSuperadmin, $id_empresa, $uid;
    $id          = (int)($d['id'] ?? 0);
    $nombre      = sanitizeStr($d['nombre'] ?? '', 150);
    $descripcion = sanitizeStr($d['descripcion'] ?? '', 500);
    $tasa        = max(0, min(1, (float)($d['tasa_interes'] ?? 0.05)));
    $tipo_tasa   = in_array($d['tipo_tasa'] ?? '', ['mensual','anual']) ? $d['tipo_tasa'] : 'mensual';
    $plazo_min   = max(1, (int)($d['plazo_min'] ?? 1));
    $plazo_max   = max($plazo_min, (int)($d['plazo_max'] ?? 60));
    $monto_min   = max(0, (float)($d['monto_min'] ?? 1000));
    $monto_max   = max($monto_min, (float)($d['monto_max'] ?? 500000));
    $tipo_amort  = in_array($d['tipo_amort'] ?? '', ['frances','aleman','americano']) ? $d['tipo_amort'] : 'frances';
    $activo      = isset($d['activo']) ? (int)(bool)$d['activo'] : 1;
    $emp         = $esSuperadmin ? ((int)($d['id_empresa'] ?? $id_empresa) ?: null) : $id_empresa;

    if (!$nombre) jsonError('El nombre del plan es requerido');

    try {
        if ($id) {
            // Editar
            $row = $db->prepare("SELECT id_empresa FROM planes_prestamo WHERE id = ? LIMIT 1");
            $row->execute([$id]);
            $ex = $row->fetch();
            if (!$ex) jsonError('Plan no encontrado', 404);
            if (!$esSuperadmin && $ex['id_empresa'] && $ex['id_empresa'] != $id_empresa) jsonError('Acceso denegado', 403);
            $db->prepare("UPDATE planes_prestamo SET nombre=?,descripcion=?,tasa_interes=?,tipo_tasa=?,
                           plazo_min=?,plazo_max=?,monto_min=?,monto_max=?,tipo_amort=?,activo=?,id_empresa=?
                           WHERE id=?")
               ->execute([$nombre,$descripcion,$tasa,$tipo_tasa,$plazo_min,$plazo_max,$monto_min,$monto_max,$tipo_amort,$activo,$emp,$id]);
            registrarAudit($uid, 'planes.editar', 1, "Plan ID $id actualizado");
            jsonResponse(['ok' => true, 'mensaje' => 'Plan actualizado correctamente']);
        } else {
            // Crear
            $db->prepare("INSERT INTO planes_prestamo (id_empresa,nombre,descripcion,tasa_interes,tipo_tasa,
                           plazo_min,plazo_max,monto_min,monto_max,tipo_amort,activo,creado_en)
                           VALUES (?,?,?,?,?,?,?,?,?,?,?,NOW())")
               ->execute([$emp,$nombre,$descripcion,$tasa,$tipo_tasa,$plazo_min,$plazo_max,$monto_min,$monto_max,$tipo_amort,$activo]);
            $newId = (int)$db->lastInsertId();
            registrarAudit($uid, 'planes.crear', 1, "Plan '$nombre' creado");
            jsonResponse(['ok' => true, 'id' => $newId, 'mensaje' => 'Plan creado correctamente']);
        }
    } catch (\Throwable $e) {
        jsonError(friendlyDbError($e));
    }
}

// ── ELIMINAR ─────────────────────────────────────────────────────────────────
function eliminar(array $d): never {
    global $db, $esSuperadmin, $id_empresa, $uid;
    $id = (int)($d['id'] ?? 0);
    if (!$id) jsonError('ID requerido');
    $row = $db->prepare("SELECT id_empresa FROM planes_prestamo WHERE id = ? LIMIT 1");
    $row->execute([$id]);
    $ex = $row->fetch();
    if (!$ex) jsonError('Plan no encontrado', 404);
    if (!$esSuperadmin && $ex['id_empresa'] && $ex['id_empresa'] != $id_empresa) jsonError('Acceso denegado', 403);
    // Verificar si tiene préstamos activos
    $chk = $db->prepare("SELECT COUNT(*) FROM prestamos WHERE id_plan = ? AND estado NOT IN ('pagado','cancelado')");
    $chk->execute([$id]);
    if ($chk->fetchColumn() > 0) jsonError('No se puede eliminar: tiene préstamos activos asociados');
    $db->prepare("UPDATE planes_prestamo SET activo = 0 WHERE id = ?")->execute([$id]);
    registrarAudit($uid, 'planes.eliminar', 1, "Plan ID $id desactivado");
    jsonResponse(['ok' => true, 'mensaje' => 'Plan desactivado correctamente']);
}
