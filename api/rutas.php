<?php
// api/rutas.php — v3: filtro estado activo + restricción cobrador
require_once __DIR__ . '/../php/helpers.php';

apiHeaders();
$usuario = verificarSesion();
$method  = $_SERVER['REQUEST_METHOD'];
$id      = isset($_GET['id']) ? sanitizeInt($_GET['id']) : null;

match ($method) {
    'GET'    => $id ? getRuta($id, $usuario) : getRutas($usuario),
    'POST'   => crearRuta($usuario),
    'PUT'    => $id ? actualizarRuta($id, $usuario) : jsonError('ID requerido'),
    'DELETE' => $id ? eliminarRuta($id, $usuario) : jsonError('ID requerido'),
    default  => jsonError('Método no permitido', 405),
};

// ─────────────────────────────────────────────────────────────

function getRutas(array $usuario): never {
    $db = getDB();

    if ($usuario['rol'] === 'cobrador') {
        // Cobrador solo ve sus propias rutas
        $stmt = $db->prepare("
            SELECT r.*,
                   u.nombre AS cobrador_nombre,
                   COUNT(rc.id) AS total_clientes
            FROM rutas_cobranza r
            LEFT JOIN usuarios u ON u.id = r.cobrador_id
            LEFT JOIN ruta_clientes rc ON rc.ruta_id = r.id
            WHERE r.activa = 1
              AND r.cobrador_id = ?
            GROUP BY r.id
            ORDER BY r.nombre
        ");
        $stmt->execute([$usuario['id']]);
    } else {
        $stmt = $db->query("
            SELECT r.*,
                   u.nombre AS cobrador_nombre,
                   COUNT(rc.id) AS total_clientes
            FROM rutas_cobranza r
            LEFT JOIN usuarios u ON u.id = r.cobrador_id
            LEFT JOIN ruta_clientes rc ON rc.ruta_id = r.id
            WHERE r.activa = 1
            GROUP BY r.id
            ORDER BY r.nombre
        ");
    }
    jsonResponse($stmt->fetchAll());
}

function getRuta(int $id, array $usuario): never {
    $db   = getDB();
    $stmt = $db->prepare("
        SELECT r.*,
               u.nombre AS cobrador_nombre
        FROM rutas_cobranza r
        LEFT JOIN usuarios u ON u.id = r.cobrador_id
        WHERE r.id = ? AND r.activa = 1
    ");
    $stmt->execute([$id]);
    $ruta = $stmt->fetch();

    if (!$ruta) jsonError('Ruta no encontrada', 404);

    // Cobrador solo puede ver su propia ruta
    if ($usuario['rol'] === 'cobrador' && $ruta['cobrador_id'] != $usuario['id']) {
        jsonError('No tienes permiso para ver esta ruta', 403);
    }

    // Solo incluir clientes con préstamos ACTIVOS (HAVING filtra)
    $clStmt = $db->prepare("
        SELECT c.id, c.nombre, c.apellido, c.telefono,
               c.direccion, c.latitud, c.longitud, rc.orden,
               COUNT(p.id) AS prestamos_activos
        FROM ruta_clientes rc
        JOIN clientes c ON c.id = rc.cliente_id
        LEFT JOIN prestamos p ON p.cliente_id = c.id AND p.estado = 'activo'
        WHERE rc.ruta_id = ?
        GROUP BY c.id, rc.orden
        HAVING COUNT(p.id) > 0
        ORDER BY rc.orden
    ");
    $clStmt->execute([$id]);
    $ruta['clientes'] = $clStmt->fetchAll();

    jsonResponse($ruta);
}

function crearRuta(array $usuario): never {
    if ($usuario['rol'] !== 'admin') jsonError('Solo administradores pueden crear rutas', 403);

    $data       = inputJSON();
    $nombre     = sanitizeStr($data['nombre'] ?? '');
    $cobrador   = sanitizeInt($data['cobrador_id'] ?? 0);
    $dia        = sanitizeInt($data['dia_cobranza'] ?? 1);
    $clientes   = (array) ($data['clientes'] ?? []);

    if ($nombre === '') jsonError('El nombre es requerido');
    if ($dia < 1 || $dia > 7) jsonError('Día de cobranza inválido (1-7)');

    $db = getDB();
    $db->beginTransaction();

    try {
        $stmt = $db->prepare("
            INSERT INTO rutas_cobranza (nombre, descripcion, cobrador_id, dia_cobranza)
            VALUES (?, ?, ?, ?)
        ");
        $stmt->execute([
            $nombre,
            sanitizeStr($data['descripcion'] ?? '', 500),
            $cobrador ?: null,
            $dia,
        ]);
        $rutaId = $db->lastInsertId();

        asignarClientes($db, $rutaId, $clientes);

        $db->commit();
        jsonResponse(['id' => $rutaId, 'mensaje' => 'Ruta creada correctamente'], 201);
    } catch (Exception $e) {
        $db->rollBack();
        jsonError('Error al crear la ruta', 500);
    }
}

function actualizarRuta(int $id, array $usuario): never {
    if ($usuario['rol'] !== 'admin') jsonError('Solo administradores pueden editar rutas', 403);

    $data     = inputJSON();
    $nombre   = sanitizeStr($data['nombre'] ?? '');
    $cobrador = sanitizeInt($data['cobrador_id'] ?? 0);
    $dia      = sanitizeInt($data['dia_cobranza'] ?? 1);
    $clientes = (array) ($data['clientes'] ?? []);

    if ($nombre === '') jsonError('El nombre es requerido');

    $db = getDB();
    $db->beginTransaction();

    try {
        $db->prepare("
            UPDATE rutas_cobranza
            SET nombre = ?, descripcion = ?, cobrador_id = ?, dia_cobranza = ?
            WHERE id = ?
        ")->execute([
            $nombre,
            sanitizeStr($data['descripcion'] ?? '', 500),
            $cobrador ?: null,
            $dia,
            $id,
        ]);

        $db->prepare("DELETE FROM ruta_clientes WHERE ruta_id = ?")->execute([$id]);
        asignarClientes($db, $id, $clientes);

        $db->commit();
        jsonResponse(['mensaje' => 'Ruta actualizada correctamente']);
    } catch (Exception $e) {
        $db->rollBack();
        jsonError('Error al actualizar la ruta', 500);
    }
}

function eliminarRuta(int $id, array $usuario): never {
    if ($usuario['rol'] !== 'admin') jsonError('Solo administradores pueden eliminar rutas', 403);

    $db   = getDB();
    $stmt = $db->prepare("UPDATE rutas_cobranza SET activa = 0 WHERE id = ?");
    $stmt->execute([$id]);

    if ($stmt->rowCount() === 0) jsonError('Ruta no encontrada', 404);
    jsonResponse(['mensaje' => 'Ruta eliminada correctamente']);
}

// ─────────────────────────────────────────────────────────────

function asignarClientes(PDO $db, int $rutaId, array $clientes): void {
    if (empty($clientes)) return;

    $stmt = $db->prepare("INSERT INTO ruta_clientes (ruta_id, cliente_id, orden) VALUES (?, ?, ?)");
    foreach ($clientes as $orden => $clienteId) {
        $stmt->execute([$rutaId, sanitizeInt($clienteId), $orden + 1]);
    }
}
