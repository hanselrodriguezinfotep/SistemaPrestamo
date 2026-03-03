<?php
// api/personas.php — API de Personas | GestionPrestamo

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
require_once __DIR__ . '/../php/audit_actions.php';

apiHeaders();
$sesion = verificarSesionAPI();

$rolesPermitidos = ['superadmin','admin','gerente','supervisor','cajero'];
if (!in_array($sesion['rol'], $rolesPermitidos, true)) {
    jsonError('Acceso denegado', 403);
}

$esSuperadmin = $sesion['rol'] === 'superadmin';

// Cachear el body JSON una sola vez (php://input solo se puede leer una vez)
$GLOBALS['_cachedJsonBody'] = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $__raw = file_get_contents('php://input');
    if ($__raw) {
        $__decoded = json_decode($__raw, true);
        if (is_array($__decoded)) $GLOBALS['_cachedJsonBody'] = $__decoded;
    }
}

// id_empresa viene siempre de la sesion (superadmin ya tiene 1 fijado al login)
$id_empresa = (int)($sesion['id_empresa'] ?? 0);
$uid        = $sesion['usuario_id'];
$db         = getDB();

// Cache de existencia de tablas opcionales
$tablaCache = [];
function tablaExiste(string $tabla): bool {
    global $db, $tablaCache;
    if (!isset($tablaCache[$tabla])) {
        try { $db->query("SELECT 1 FROM `$tabla` LIMIT 1"); $tablaCache[$tabla] = true; }
        catch (\Throwable) { $tablaCache[$tabla] = false; }
    }
    return $tablaCache[$tabla];
}

$action = sanitizeStr($_GET['action'] ?? '');
$method = $_SERVER['REQUEST_METHOD'];

match (true) {
    $action === 'listar'       && $method === 'GET'  => listar(),
    $action === 'obtener'      && $method === 'GET'  => obtener(),
    $action === 'tipos_listar' && $method === 'GET'  => tiposListar(),
    $action === 'subir_foto'   && $method === 'POST' => subirFoto(),
    $action === 'crear_admin'  && $method === 'POST' => crearAdmin($GLOBALS['_cachedJsonBody']),
    $method === 'POST'                                => manejarPost($GLOBALS['_cachedJsonBody']),
    default => jsonError('Accion no valida', 404),
};

// ─────────────────────────────────────────────────────────────────────────────
// SUBIR FOTO
// ─────────────────────────────────────────────────────────────────────────────
function subirFoto(): never {
    global $db, $id_empresa;

    if (empty($_FILES['foto']) || $_FILES['foto']['error'] !== UPLOAD_ERR_OK) {
        jsonError('No se recibio ningun archivo valido');
    }

    $file    = $_FILES['foto'];
    $maxSize = 5 * 1024 * 1024;
    if ($file['size'] > $maxSize) jsonError('La imagen supera el limite de 5 MB');

    $mime    = mime_content_type($file['tmp_name']);
    $allowed = ['image/jpeg','image/png','image/gif','image/webp'];
    if (!in_array($mime, $allowed, true)) jsonError('Tipo de archivo no permitido');

    $ext = match($mime) {
        'image/jpeg' => 'jpg', 'image/png' => 'png',
        'image/gif'  => 'gif', 'image/webp' => 'webp',
        default      => 'jpg'
    };

    $uploadDir = __DIR__ . '/../uploads/fotos/';
    if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);

    $filename = 'persona_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
    $destPath = $uploadDir . $filename;

    if (!move_uploaded_file($file['tmp_name'], $destPath)) {
        jsonError('Error al guardar la imagen');
    }

    $personaId = (int)($_POST['persona_id'] ?? 0);
    if ($personaId > 0) {
        $st = $db->prepare("SELECT id_empresa FROM personas WHERE id = ?");
        $st->execute([$personaId]);
        $row = $st->fetch();
        if (!$row || (int)$row['id_empresa'] !== (int)$id_empresa) {
            @unlink($destPath);
            jsonError('No tienes acceso a esta persona', 403);
        }
        $stOld = $db->prepare("SELECT foto_path FROM personas WHERE id = ?");
        $stOld->execute([$personaId]);
        $old = $stOld->fetchColumn();
        if ($old && file_exists($uploadDir . $old)) @unlink($uploadDir . $old);

        $db->prepare("UPDATE personas SET foto_path = ? WHERE id = ?")->execute([$filename, $personaId]);
    }

    jsonResponse(['success' => true, 'foto_path' => $filename]);
}

// ─────────────────────────────────────────────────────────────────────────────
// LISTAR personas
// ─────────────────────────────────────────────────────────────────────────────
function listar(): never {
    global $db, $id_empresa;

    // Siempre filtrar por id_empresa — nunca exponer registros de otras empresas
    if (!$id_empresa) jsonError('Empresa no identificada en sesión', 400);
    $where  = 'WHERE p.id_empresa = :centro';
    $params = [':centro' => $id_empresa];

    $sql = "
        SELECT
            p.id,
            p.id_empresa,
            p.nombre,
            p.apellido,
            p.cedula,
            p.tipo_persona,
            p.genero,
            p.nacionalidad,
            p.fecha_nacimiento,
            p.estado_civil,
            p.foto_path,
            p.activo,
            p.creado_en,
            ce.nombre AS empresa_nombre,
            cp_min.valor AS contacto_principal
        FROM personas p
        LEFT JOIN empresas ce ON ce.id = p.id_empresa
        LEFT JOIN (
            SELECT cp2.id_persona, cp2.valor
            FROM contactos_persona cp2
            INNER JOIN (
                SELECT id_persona, MIN(id) AS min_id
                FROM contactos_persona
                GROUP BY id_persona
            ) cp_grp ON cp2.id = cp_grp.min_id
        ) cp_min ON cp_min.id_persona = p.id
        $where
        ORDER BY p.apellido, p.nombre
    ";

    try {
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        jsonResponse(['personas' => $stmt->fetchAll()]);
    } catch (\Throwable $e) {
        jsonError('Error al listar personas: ' . $e->getMessage());
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// OBTENER — un registro completo con sus contactos
// ─────────────────────────────────────────────────────────────────────────────
function obtener(): never {
    global $db, $id_empresa;

    $id = (int)($_GET['id'] ?? 0);
    if (!$id) jsonError('ID invalido');

    verificarAcceso($id);

    $stmt = $db->prepare("SELECT p.* FROM personas p WHERE p.id = ?");
    $stmt->execute([$id]);
    $persona = $stmt->fetch();
    if (!$persona) jsonError('Persona no encontrada', 404);

    // Contactos
    $stmt2 = $db->prepare("SELECT tipo_contacto, valor FROM contactos_persona WHERE id_persona = ? ORDER BY id");
    $stmt2->execute([$id]);
    $persona['contactos'] = $stmt2->fetchAll();

    // Tipos adicionales si la tabla existe
    $persona['tipos_adicionales'] = [];
    if (tablaExiste('persona_tipos')) {
        $stTipos = $db->prepare("SELECT tipo, es_primario FROM persona_tipos WHERE id_persona = ? AND id_empresa = ? ORDER BY es_primario DESC, tipo ASC");
        $stTipos->execute([$id, (int)($persona['id_empresa'] ?? $id_empresa)]);
        $persona['tipos_adicionales'] = $stTipos->fetchAll();
    }

    jsonResponse(['persona' => $persona]);
}

// ─────────────────────────────────────────────────────────────────────────────
// POST router
// ─────────────────────────────────────────────────────────────────────────────
function manejarPost(array $d): never {
    match (sanitizeStr($d['action'] ?? '')) {
        'crear'         => crear($d),
        'crear_admin'   => crearAdmin($d),
        'editar'        => editar($d),
        'eliminar'      => eliminar($d),
        'tipos_agregar' => tiposAgregar($d),
        'tipos_quitar'  => tiposQuitar($d),
        default         => jsonError('Accion no valida', 404),
    };
}

// ─────────────────────────────────────────────────────────────────────────────
// CREAR
// ─────────────────────────────────────────────────────────────────────────────
function crear(array $d): never {
    global $db, $id_empresa, $uid;

    [$err, $datos] = validarDatos($d);
    if ($err) jsonError($err);

    // Para superadmin: respetar el centro seleccionado en el formulario
    $centroId = ((int)($d['id_empresa'] ?? 0)) ?: ($id_empresa ?: null);

    // Validacion de cedula duplicada
    if ($datos['cedula']) {
        $st = $db->prepare("SELECT id FROM personas WHERE cedula = ? AND tipo_persona = ? AND id_empresa = ?");
        $st->execute([$datos['cedula'], $datos['tipo_persona'], $centroId]);
        if ($st->fetch()) jsonError('Ya existe una persona con esa cedula y tipo en este centro.');
    }

    try {
        $db->beginTransaction();

        $fotoPath = sanitizeStr($d['foto_path'] ?? '') ?: null;
        $db->prepare("
            INSERT INTO personas
                (id_empresa, nombre, apellido, cedula, tipo_persona,
                 fecha_nacimiento, genero, nacionalidad, estado_civil, foto_path, creado_en)
            VALUES (?,?,?,?,?,?,?,?,?,?,NOW())
        ")->execute([
            $centroId,
            $datos['nombre'], $datos['apellido'], $datos['cedula'],
            $datos['tipo_persona'], $datos['fecha_nacimiento'],
            $datos['genero'], $datos['nacionalidad'], $datos['estado_civil'],
            $fotoPath,
        ]);
        $personaId = (int)$db->lastInsertId();

        guardarContactos($db, $personaId, $d['contactos'] ?? []);

        $db->commit();
        registrarAudit($uid, 'persona_creada', 1, [
            'id'         => $personaId,
            'nombre'     => $datos['nombre'] . ' ' . $datos['apellido'],
            'tipo'       => $datos['tipo_persona'],
            'cedula'     => $datos['cedula'],
            'id_empresa' => $centroId,
        ]);
        jsonResponse(['success' => true, 'mensaje' => 'Persona registrada correctamente', 'id' => $personaId]);
    } catch (\Throwable $e) {
        $db->rollBack();
        jsonError(friendlyDbError($e));
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// EDITAR
// ─────────────────────────────────────────────────────────────────────────────
function editar(array $d): never {
    global $db, $id_empresa, $uid;

    $id = (int)($d['id'] ?? 0);
    if (!$id) jsonError('ID invalido');
    verificarAcceso($id);

    [$err, $datos] = validarDatos($d);
    if ($err) jsonError($err);

    $centroId = ((int)($d['id_empresa'] ?? 0)) ?: ($id_empresa ?: null);

    if ($datos['cedula']) {
        $st = $db->prepare("SELECT id FROM personas WHERE cedula = ? AND tipo_persona = ? AND id_empresa = ? AND id != ?");
        $st->execute([$datos['cedula'], $datos['tipo_persona'], $centroId, $id]);
        if ($st->fetch()) jsonError('Ya existe una persona con esa cedula y tipo en este centro.');
    }

    try {
        $db->beginTransaction();

        $fotoPath = sanitizeStr($d['foto_path'] ?? '') ?: null;
        $db->prepare("
            UPDATE personas SET
                id_empresa=?, nombre=?, apellido=?, cedula=?,
                fecha_nacimiento=?, genero=?, nacionalidad=?, estado_civil=?,
                foto_path=COALESCE(?,foto_path)
            WHERE id=?
        ")->execute([
            $centroId,
            $datos['nombre'], $datos['apellido'], $datos['cedula'],
            $datos['fecha_nacimiento'], $datos['genero'],
            $datos['nacionalidad'], $datos['estado_civil'],
            $fotoPath,
            $id,
        ]);

        $db->prepare("DELETE FROM contactos_persona WHERE id_persona=?")->execute([$id]);
        guardarContactos($db, $id, $d['contactos'] ?? []);

        $db->commit();
        registrarAudit($uid, 'persona_editada', 1, [
            'id'     => $id,
            'nombre' => $datos['nombre'] . ' ' . $datos['apellido'],
            'tipo'   => $datos['tipo_persona'],
            'cedula' => $datos['cedula'],
        ]);
        jsonResponse(['success' => true, 'mensaje' => 'Persona actualizada correctamente']);
    } catch (\Throwable $e) {
        $db->rollBack();
        jsonError(friendlyDbError($e));
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// ELIMINAR
// ─────────────────────────────────────────────────────────────────────────────
function eliminar(array $d): never {
    global $db, $uid;

    $id = (int)($d['id'] ?? 0);
    if (!$id) jsonError('ID invalido');
    verificarAcceso($id);

    $st = $db->prepare("SELECT id FROM usuarios WHERE id_persona = ?");
    $st->execute([$id]);
    if ($st->fetch()) jsonError('Esta persona tiene un usuario vinculado. Elimina el usuario primero.');

    $stInfo = $db->prepare("SELECT nombre, apellido, cedula, tipo_persona FROM personas WHERE id=?");
    $stInfo->execute([$id]);
    $info = $stInfo->fetch() ?: [];

    $db->prepare("DELETE FROM personas WHERE id=?")->execute([$id]);
    registrarAudit($uid, 'persona_eliminada', 1, [
        'id'     => $id,
        'nombre' => ($info['nombre'] ?? '') . ' ' . ($info['apellido'] ?? ''),
        'tipo'   => $info['tipo_persona'] ?? '',
        'cedula' => $info['cedula'] ?? null,
    ]);
    jsonResponse(['success' => true, 'mensaje' => 'Persona eliminada']);
}

// ─────────────────────────────────────────────────────────────────────────────
// HELPERS
// ─────────────────────────────────────────────────────────────────────────────

function validarDatos(array $d): array {
    $tipo  = sanitizeStr($d['tipo_persona'] ?? '');
    // ENUM exacto de la BD: 'Cliente','Empleado','Proveedor','Garante','Otro'
    $tipos = ['Cliente','Empleado','Proveedor','Garante','Otro'];
    if (!in_array($tipo, $tipos, true)) return ['Tipo de persona invalido', null];

    $nombre   = sanitizeStr($d['nombre']   ?? '');
    $apellido = sanitizeStr($d['apellido'] ?? '');
    $genero   = sanitizeStr($d['genero']   ?? '');
    $fechaNac = sanitizeStr($d['fecha_nacimiento'] ?? '') ?: null;

    if (!$nombre)   return ['El nombre es obligatorio', null];
    if (!$apellido) return ['El apellido es obligatorio', null];
    if (!in_array($genero, ['Masculino','Femenino','Otro'], true)) return ['Genero invalido', null];

    $cedula = sanitizeStr($d['cedula'] ?? '') ?: null;
    $nac    = sanitizeStr($d['nacionalidad'] ?? '') ?: 'Dominicana';
    $ec     = sanitizeStr($d['estado_civil'] ?? '') ?: null;

    // Normalizar estado civil para coincidir exactamente con ENUM de BD
    // BD ENUM: 'Soltero','Casado','Divorciado','Viudo','Union Libre'
    $ecMap = [
        'Soltero/a'    => 'Soltero',
        'Casado/a'     => 'Casado',
        'Divorciado/a' => 'Divorciado',
        'Viudo/a'      => 'Viudo',
        'Union Libre'  => 'Union Libre',
        'Uni\u00f3n Libre' => 'Union Libre',
    ];
    if ($ec && isset($ecMap[$ec])) $ec = $ecMap[$ec];
    // Normalizar variantes con tilde
    if ($ec === 'Uni' . chr(0xc3) . chr(0xb3) . 'n Libre') $ec = 'Union Libre';

    $ecOpts = ['Soltero','Casado','Divorciado','Viudo','Union Libre'];
    if ($ec && !in_array($ec, $ecOpts, true)) $ec = null;

    return [null, [
        'tipo_persona'     => $tipo,
        'nombre'           => $nombre,
        'apellido'         => $apellido,
        'cedula'           => $cedula,
        'fecha_nacimiento' => $fechaNac,
        'genero'           => $genero,
        'nacionalidad'     => $nac,
        'estado_civil'     => $ec,
    ]];
}

function guardarContactos(\PDO $db, int $personaId, array $contactos): void {
    $tiposValidos = ['Telefono','Email','Direccion','WhatsApp','Otro'];
    $stmt = $db->prepare("INSERT INTO contactos_persona (id_persona, tipo_contacto, valor) VALUES (?,?,?)");
    foreach ($contactos as $c) {
        $tipo  = sanitizeStr($c['tipo_contacto'] ?? '');
        $valor = sanitizeStr($c['valor'] ?? '', 255);
        if ($valor && in_array($tipo, $tiposValidos, true)) {
            $stmt->execute([$personaId, $tipo, $valor]);
        }
    }
}

function verificarAcceso(int $id): void {
    global $db, $id_empresa;
    $st = $db->prepare("SELECT id_empresa FROM personas WHERE id = ?");
    $st->execute([$id]);
    $row = $st->fetch();
    if (!$row || (int)$row['id_empresa'] !== (int)$id_empresa) {
        jsonError('No tienes acceso a esta persona', 403);
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// CREAR ADMIN — solo superadmin
// ─────────────────────────────────────────────────────────────────────────────
function crearAdmin(array $d): never {
    global $db, $esSuperadmin, $uid;

    if (!$esSuperadmin) jsonError('Solo el superadmin puede crear admins', 403);

    $nombre   = sanitizeStr($d['nombre']   ?? '');
    $apellido = sanitizeStr($d['apellido'] ?? '');
    $centroId = (int)($d['id_empresa']     ?? 0);

    if (!$nombre)   jsonError('El nombre es obligatorio');
    if (!$apellido) jsonError('El apellido es obligatorio');
    if (!$centroId) jsonError('El centro es obligatorio');

    $chkC = $db->prepare('SELECT id FROM empresas WHERE id = ? LIMIT 1');
    $chkC->execute([$centroId]);
    if (!$chkC->fetch()) jsonError('Centro no encontrado', 404);

    $db->prepare("
        INSERT INTO personas (nombre, apellido, tipo_persona, id_empresa, fecha_nacimiento, genero, creado_en)
        VALUES (?, ?, 'Empleado', ?, '1990-01-01', 'Masculino', NOW())
    ")->execute([$nombre, $apellido, $centroId]);

    $personaId = (int)$db->lastInsertId();
    registrarAudit($uid, 'persona_creada', 1, ['id' => $personaId, 'tipo' => 'admin', 'id_empresa' => $centroId]);

    jsonResponse(['success' => true, 'id' => $personaId], 201);
}

// ─────────────────────────────────────────────────────────────────────────────
// MULTI-ROL — persona_tipos
// ─────────────────────────────────────────────────────────────────────────────

function tiposListar(): never {
    global $db, $id_empresa;
    $idPersona = (int)($_GET['id_persona'] ?? 0);
    if (!$idPersona) jsonError('id_persona requerido');

    if (!tablaExiste('persona_tipos')) {
        $stmt = $db->prepare("SELECT tipo_persona AS tipo, 1 AS es_primario FROM personas WHERE id = ? AND id_empresa = ? LIMIT 1");
        $stmt->execute([$idPersona, $id_empresa]);
        $row = $stmt->fetch();
        jsonResponse(['tipos' => $row ? [['tipo' => $row['tipo'], 'es_primario' => 1]] : []]);
    }

    $stmt = $db->prepare("
        SELECT pt.id, pt.tipo, pt.es_primario
        FROM persona_tipos pt
        WHERE pt.id_persona = ? AND pt.id_empresa = ?
        ORDER BY pt.es_primario DESC, pt.tipo ASC
    ");
    $stmt->execute([$idPersona, $id_empresa]);
    jsonResponse(['tipos' => $stmt->fetchAll()]);
}

function tiposAgregar(array $d): never {
    global $db, $id_empresa, $uid;
    $idPersona    = (int)($d['id_persona'] ?? 0);
    $tipo         = sanitizeStr($d['tipo'] ?? '');
    $tiposValidos = ['Cliente','Empleado','Proveedor','Garante','Otro'];

    if (!$idPersona) jsonError('id_persona requerido');
    if (!in_array($tipo, $tiposValidos, true)) jsonError('Tipo de persona invalido');
    if (!tablaExiste('persona_tipos')) jsonError('La tabla persona_tipos no existe. Ejecute la migracion primero.');

    $chk = $db->prepare("SELECT tipo_persona FROM personas WHERE id = ? AND id_empresa = ? LIMIT 1");
    $chk->execute([$idPersona, $id_empresa]);
    $persona = $chk->fetch();
    if (!$persona) jsonError('Persona no encontrada');
    if ($persona['tipo_persona'] === $tipo) jsonError('Ese ya es el tipo principal de esta persona');

    try {
        $db->beginTransaction();

        $ins = $db->prepare("
            INSERT IGNORE INTO persona_tipos (id_persona, id_empresa, tipo, es_primario, creado_en)
            VALUES (?, ?, ?, 0, NOW())
        ");
        $ins->execute([$idPersona, $id_empresa, $tipo]);

        if ($ins->rowCount() === 0) jsonError('Esta persona ya tiene ese tipo asignado');

        $db->commit();
        registrarAudit($uid, 'persona_tipo_agregado', 1, ['id_persona' => $idPersona, 'tipo' => $tipo]);
        jsonResponse(['success' => true, 'mensaje' => "Rol '$tipo' agregado correctamente"]);
    } catch (\Throwable $e) {
        $db->rollBack();
        jsonError(friendlyDbError($e));
    }
}

function tiposQuitar(array $d): never {
    global $db, $id_empresa, $uid;
    $idPersona = (int)($d['id_persona'] ?? 0);
    $tipo      = sanitizeStr($d['tipo'] ?? '');

    if (!$idPersona) jsonError('id_persona requerido');
    if (!tablaExiste('persona_tipos')) jsonError('La tabla persona_tipos no existe');

    $chk = $db->prepare("SELECT tipo_persona FROM personas WHERE id = ? AND id_empresa = ? LIMIT 1");
    $chk->execute([$idPersona, $id_empresa]);
    $persona = $chk->fetch();
    if (!$persona) jsonError('Persona no encontrada');
    if ($persona['tipo_persona'] === $tipo) jsonError('No se puede quitar el tipo principal. Cambia el tipo de persona primero.');

    $del = $db->prepare("DELETE FROM persona_tipos WHERE id_persona = ? AND id_empresa = ? AND tipo = ? AND es_primario = 0");
    $del->execute([$idPersona, $id_empresa, $tipo]);

    if ($del->rowCount() === 0) jsonError('Tipo no encontrado o es el tipo primario');

    registrarAudit($uid, 'persona_tipo_quitado', 1, ['id_persona' => $idPersona, 'tipo' => $tipo]);
    jsonResponse(['success' => true, 'mensaje' => "Rol '$tipo' quitado correctamente"]);
}