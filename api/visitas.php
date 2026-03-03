<?php
// api/visitas.php — Registro de visitas de cobranza
require_once __DIR__ . '/../php/helpers.php';

apiHeaders();
$usuario = verificarSesion();
$method  = $_SERVER['REQUEST_METHOD'];
$id      = isset($_GET['id']) ? sanitizeInt($_GET['id']) : null;

match ($method) {
    'GET'    => getVisitas(),
    'POST'   => registrarVisita($usuario),
    'DELETE' => $id ? eliminarVisita($id, $usuario) : jsonError('ID requerido'),
    default  => jsonError('Método no permitido', 405),
};

// ─────────────────────────────────────────────────────────────

function getVisitas(): never {
    $db        = getDB();
    $rutaId    = isset($_GET['ruta_id'])    ? sanitizeInt($_GET['ruta_id'])    : null;
    $clienteId = isset($_GET['cliente_id']) ? sanitizeInt($_GET['cliente_id']) : null;
    $fecha     = isset($_GET['fecha'])      ? sanitizeDate($_GET['fecha'])     : null;

    $where  = ['1=1'];
    $params = [];

    if ($rutaId)    { $where[] = 'v.ruta_id = ?';    $params[] = $rutaId; }
    if ($clienteId) { $where[] = 'v.cliente_id = ?'; $params[] = $clienteId; }
    if ($fecha)     { $where[] = 'v.fecha_visita = ?'; $params[] = $fecha; }

    $sql = "
        SELECT v.*,
               CONCAT(c.nombre, ' ', c.apellido) AS cliente_nombre,
               c.telefono AS cliente_telefono,
               u.nombre AS cobrador_nombre,
               r.nombre AS ruta_nombre
        FROM visitas_cobranza v
        JOIN clientes c        ON c.id = v.cliente_id
        LEFT JOIN usuarios u   ON u.id = v.cobrador_id
        LEFT JOIN rutas_cobranza r ON r.id = v.ruta_id
        WHERE " . implode(' AND ', $where) . "
        ORDER BY v.creado_en DESC
    ";

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    jsonResponse($stmt->fetchAll());
}

function registrarVisita(array $usuario): never {
    $data      = inputJSON();
    $rutaId    = sanitizeInt($data['ruta_id']    ?? 0);
    $clienteId = sanitizeInt($data['cliente_id'] ?? 0);
    $estado    = in_array($data['estado'] ?? '', ['visitado','no_visitado']) ? $data['estado'] : 'visitado';
    $motivo    = sanitizeStr($data['motivo_no_visita'] ?? '', 500);
    $notas     = sanitizeStr($data['notas']            ?? '', 500);
    $fecha     = sanitizeDate($data['fecha_visita']    ?? date('Y-m-d'));
    $lat       = isset($data['lat']) ? (float)$data['lat'] : null;
    $lng       = isset($data['lng']) ? (float)$data['lng'] : null;

    if ($rutaId    <= 0) jsonError('ruta_id requerido');
    if ($clienteId <= 0) jsonError('cliente_id requerido');
    if ($estado === 'no_visitado' && $motivo === '') jsonError('Debe indicar el motivo de no visita');
    if (!$fecha) jsonError('Fecha inválida');

    $db = getDB();

    // Si ya existe visita hoy para este cliente+ruta, actualizar en vez de duplicar
    $existing = $db->prepare("
        SELECT id FROM visitas_cobranza
        WHERE ruta_id = ? AND cliente_id = ? AND fecha_visita = ?
        LIMIT 1
    ");
    $existing->execute([$rutaId, $clienteId, $fecha]);
    $row = $existing->fetch();

    if ($row) {
        $stmt = $db->prepare("
            UPDATE visitas_cobranza
            SET estado = ?, motivo_no_visita = ?, notas = ?,
                coordenadas_lat = ?, coordenadas_lng = ?,
                cobrador_id = ?
            WHERE id = ?
        ");
        $stmt->execute([$estado, $motivo ?: null, $notas ?: null, $lat, $lng, $usuario['id'], $row['id']]);
        jsonResponse(['id' => $row['id'], 'mensaje' => 'Visita actualizada', 'accion' => 'actualizado']);
    } else {
        $stmt = $db->prepare("
            INSERT INTO visitas_cobranza
                (ruta_id, cliente_id, cobrador_id, fecha_visita, estado, motivo_no_visita, notas, coordenadas_lat, coordenadas_lng)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $rutaId, $clienteId, $usuario['id'], $fecha,
            $estado, $motivo ?: null, $notas ?: null, $lat, $lng
        ]);
        jsonResponse(['id' => $db->lastInsertId(), 'mensaje' => 'Visita registrada', 'accion' => 'creado'], 201);
    }
}

function eliminarVisita(int $id, array $usuario): never {
    if ($usuario['rol'] !== 'admin') jsonError('Solo administradores pueden eliminar visitas', 403);
    $db   = getDB();
    $stmt = $db->prepare("DELETE FROM visitas_cobranza WHERE id = ?");
    $stmt->execute([$id]);
    if ($stmt->rowCount() === 0) jsonError('Visita no encontrada', 404);
    jsonResponse(['mensaje' => 'Visita eliminada']);
}
