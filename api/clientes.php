<?php
// api/clientes.php
require_once __DIR__ . '/../php/helpers.php';

apiHeaders();
$usuario = verificarSesion();
$method  = $_SERVER['REQUEST_METHOD'];
$id      = isset($_GET['id']) ? sanitizeInt($_GET['id']) : null;

match ($method) {
    'GET'    => $id ? getCliente($id) : getClientes(),
    'POST'   => crearCliente($usuario),
    'PUT'    => $id ? actualizarCliente($id, $usuario) : jsonError('ID requerido'),
    'DELETE' => $id ? eliminarCliente($id, $usuario) : jsonError('ID requerido'),
    default  => jsonError('Método no permitido', 405),
};

// ─────────────────────────────────────────────────────────────

function getClientes(): never {
    $db      = getDB();
    $search  = sanitizeStr($_GET['q'] ?? '');
    $page    = max(1, sanitizeInt($_GET['page'] ?? 1));
    $perPage = min(100, max(10, sanitizeInt($_GET['per_page'] ?? 20)));
    $offset  = ($page - 1) * $perPage;

    $where = "WHERE c.activo = 1";
    $params = [];

    if ($search !== '') {
        $where .= " AND (c.nombre LIKE ? OR c.apellido LIKE ? OR c.documento LIKE ?)";
        $like = "%$search%";
        $params = [$like, $like, $like];
    }

    $total = $db->prepare("SELECT COUNT(*) FROM clientes c $where");
    $total->execute($params);
    $totalCount = (int) $total->fetchColumn();

    $stmt = $db->prepare("
        SELECT c.*,
               COUNT(p.id) AS total_prestamos,
               SUM(CASE WHEN p.estado = 'activo' THEN 1 ELSE 0 END) AS prestamos_activos
        FROM clientes c
        LEFT JOIN prestamos p ON p.cliente_id = c.id
        $where
        GROUP BY c.id
        ORDER BY c.nombre, c.apellido
        LIMIT ? OFFSET ?
    ");
    $stmt->execute([...$params, $perPage, $offset]);
    $clientes = $stmt->fetchAll();

    jsonResponse([
        'data'       => $clientes,
        'paginacion' => paginar($totalCount, $page, $perPage),
    ]);
}

function getCliente(int $id): never {
    $db   = getDB();
    $stmt = $db->prepare("SELECT * FROM clientes WHERE id = ? AND activo = 1");
    $stmt->execute([$id]);
    $cliente = $stmt->fetch();

    if (!$cliente) jsonError('Cliente no encontrado', 404);
    jsonResponse($cliente);
}

function crearCliente(array $usuario): never {
    $data = inputJSON();
    [$nombre, $apellido, $documento] = validarCamposCliente($data);

    $db = getDB();

    // Verificar documento único
    $check = $db->prepare("SELECT id FROM clientes WHERE documento = ?");
    $check->execute([$documento]);
    if ($check->fetch()) jsonError('El documento ya está registrado');

    $stmt = $db->prepare("
        INSERT INTO clientes (nombre, apellido, documento, telefono, email, direccion, latitud, longitud, notas)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt->execute([
        $nombre, $apellido, $documento,
        sanitizeStr($data['telefono'] ?? ''),
        sanitizeStr($data['email'] ?? ''),
        sanitizeStr($data['direccion'] ?? '', 500),
        isset($data['latitud'])  ? (float) $data['latitud']  : null,
        isset($data['longitud']) ? (float) $data['longitud'] : null,
        sanitizeStr($data['notas'] ?? '', 1000),
    ]);

    $id = $db->lastInsertId();
    $stmt2 = $db->prepare("SELECT * FROM clientes WHERE id = ?");
    $stmt2->execute([$id]);
    jsonResponse($stmt2->fetch(), 201);
}

function actualizarCliente(int $id, array $usuario): never {
    $data = inputJSON();
    [$nombre, $apellido, $documento] = validarCamposCliente($data);

    $db = getDB();

    // Verificar documento único (excluyendo el propio)
    $check = $db->prepare("SELECT id FROM clientes WHERE documento = ? AND id != ?");
    $check->execute([$documento, $id]);
    if ($check->fetch()) jsonError('El documento ya está registrado por otro cliente');

    $stmt = $db->prepare("
        UPDATE clientes
        SET nombre = ?, apellido = ?, documento = ?, telefono = ?,
            email = ?, direccion = ?, latitud = ?, longitud = ?, notas = ?
        WHERE id = ? AND activo = 1
    ");
    $stmt->execute([
        $nombre, $apellido, $documento,
        sanitizeStr($data['telefono'] ?? ''),
        sanitizeStr($data['email'] ?? ''),
        sanitizeStr($data['direccion'] ?? '', 500),
        isset($data['latitud'])  ? (float) $data['latitud']  : null,
        isset($data['longitud']) ? (float) $data['longitud'] : null,
        sanitizeStr($data['notas'] ?? '', 1000),
        $id,
    ]);

    if ($stmt->rowCount() === 0) jsonError('Cliente no encontrado', 404);

    $stmt2 = $db->prepare("SELECT * FROM clientes WHERE id = ?");
    $stmt2->execute([$id]);
    jsonResponse($stmt2->fetch());
}

function eliminarCliente(int $id, array $usuario): never {
    if ($usuario['rol'] !== 'admin') jsonError('Solo administradores pueden eliminar clientes', 403);

    $db = getDB();

    // Verificar que no tenga préstamos activos
    $check = $db->prepare("SELECT id FROM prestamos WHERE cliente_id = ? AND estado = 'activo' LIMIT 1");
    $check->execute([$id]);
    if ($check->fetch()) jsonError('No se puede eliminar: el cliente tiene préstamos activos');

    $stmt = $db->prepare("UPDATE clientes SET activo = 0 WHERE id = ?");
    $stmt->execute([$id]);

    if ($stmt->rowCount() === 0) jsonError('Cliente no encontrado', 404);
    jsonResponse(['mensaje' => 'Cliente eliminado correctamente']);
}

// ─────────────────────────────────────────────────────────────

function validarCamposCliente(array $data): array {
    $nombre   = sanitizeStr($data['nombre'] ?? '');
    $apellido = sanitizeStr($data['apellido'] ?? '');
    $documento = sanitizeStr($data['documento'] ?? '');

    if ($nombre === '')    jsonError('El nombre es requerido');
    if ($apellido === '')  jsonError('El apellido es requerido');
    if ($documento === '') jsonError('El documento es requerido');

    return [$nombre, $apellido, $documento];
}
