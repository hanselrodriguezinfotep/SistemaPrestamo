<?php
// php/php_usuarios.php — API de Usuarios & Roles | GestionPrestamo

require_once __DIR__ . '/../php/helpers.php';
require_once __DIR__ . '/../php/audit_actions.php';
require_once __DIR__ . '/../php/notificaciones.php';

apiHeaders();
$sesion = verificarSesionAPI();

if (!in_array($sesion['rol'], ['superadmin', 'admin', 'gerente'], true)) {
    jsonError('Acceso denegado', 403);
}

$esSuperadmin = $sesion['rol'] === 'superadmin';
$esGerente   = $sesion['rol'] === 'gerente';
// id_empresa viene siempre de la sesión (superadmin ya tiene 1 fijado al login)
$id_empresa    = (int)($sesion['id_empresa'] ?? 0);
$uid_sesion   = $sesion['usuario_id'];
$db           = getDB();

$action = sanitizeStr($_GET['action'] ?? '');
$method = $_SERVER['REQUEST_METHOD'];

match (true) {
    $action === 'listar'         && $method === 'GET'  => listarUsuarios(),
    $action === 'buscar_persona' && $method === 'GET'  => buscarPersona(),
    $action === 'listar_roles'   && $method === 'GET'  => listarRoles(),
    $method === 'POST'                                   => manejarPost(inputJSON()),
    default => jsonError('Acción no válida', 404),
};

// ─────────────────────────────────────────────────────────────────────────────
// GET: listar usuarios (filtrado por centro si es admin)
// ─────────────────────────────────────────────────────────────────────────────
function listarUsuarios(): never {
    global $db, $esSuperadmin, $id_empresa;

    $sql = "
        SELECT
            u.id,
            u.username,
            u.activo,
            u.cambiar_password,
            u.ultimo_login,
            u.id_empresa,
            u.id_persona,
            CONCAT(p.nombre, ' ', p.apellido) AS nombre_completo,
            p.tipo_persona,
            r.id   AS id_rol,
            r.nombre AS rol,
            ce.nombre AS centro_nombre
        FROM usuarios u
        JOIN personas p          ON p.id = u.id_persona
        JOIN usuario_rol ur      ON ur.id_usuario = u.id
        JOIN roles r             ON r.id = ur.id_rol
        LEFT JOIN empresas ce ON ce.id = u.id_empresa
    ";

    // Siempre filtrar por id_empresa (superadmin default = 1)
    $stmt = $db->prepare($sql . " WHERE u.id_empresa = ? ORDER BY p.apellido, p.nombre");
    $stmt->execute([$id_empresa]);

    jsonResponse(['usuarios' => $stmt->fetchAll()]);
}

// ─────────────────────────────────────────────────────────────────────────────
// GET: buscar persona para vincular a usuario
// ─────────────────────────────────────────────────────────────────────────────
function buscarPersona(): never {
    global $db, $esSuperadmin, $id_empresa;

    $q = '%' . sanitizeStr($_GET['q'] ?? '') . '%';

    // Mapa tipo_persona → nombre de rol sugerido
    // Los tipos deben coincidir con el ENUM de personas.tipo_persona
    $rolSugerido = [
        'Cliente'    => 'cliente',
        'Empleado'   => 'gerente',
        'Garante'    => 'consultor',
        'Proveedor'  => 'consultor',
        'Otro'       => 'consultor',
    ];

    // Para no-superadmin: la persona debe pertenecer al centro de la sesión
    // Se verifica contra p.id_empresa directamente (campo más confiable)
    // Los LEFT JOINs se usan solo para datos adicionales, no para el filtro de centro
    // Siempre filtrar por centro (superadmin default = 1)
    $sql = "
        SELECT DISTINCT
            p.id,
            CONCAT(p.nombre, ' ', p.apellido) AS nombre_completo,
            p.tipo_persona,
            p.cedula
        FROM personas p
        WHERE (
            CONCAT(p.nombre, ' ', p.apellido) LIKE :q
            OR p.cedula LIKE :q2
        )
          AND p.id_empresa = :centro
          AND p.id NOT IN (SELECT id_persona FROM usuarios WHERE id_persona IS NOT NULL)
        ORDER BY p.apellido, p.nombre
        LIMIT 30
    ";

    $params = [':q' => $q, ':q2' => $q, ':centro' => $id_empresa];

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $personas = $stmt->fetchAll();

    // Obtener IDs de roles para sugerencias
    $rolesStmt = $db->query("SELECT id, nombre FROM roles");
    $rolesMap  = [];
    foreach ($rolesStmt->fetchAll() as $r) {
        $rolesMap[$r['nombre']] = $r['id'];
    }

    // Añadir rol_sugerido_id y rol_sugerido_nombre a cada persona
    foreach ($personas as &$p) {
        $tipoNorm = $p['tipo_persona'];
        $rolNombre = $rolSugerido[$tipoNorm] ?? '';
        $p['rol_sugerido_nombre'] = $rolNombre;
        $p['rol_sugerido_id']     = $rolesMap[$rolNombre] ?? '';
    }
    unset($p);

    jsonResponse(['personas' => $personas]);
}

// ─────────────────────────────────────────────────────────────────────────────
// GET: listar roles con sus permisos
// ─────────────────────────────────────────────────────────────────────────────
function listarRoles(): never {
    global $db, $esSuperadmin;

    // Admin no ve el rol superadmin
    $where = $esSuperadmin ? '' : "WHERE r.nombre != 'superadmin'";

    $sql = "
        SELECT
            r.id,
            r.nombre,
            r.descripcion,
            GROUP_CONCAT(p.nombre   ORDER BY p.nombre SEPARATOR ',') AS permisos,
            GROUP_CONCAT(p.id       ORDER BY p.nombre SEPARATOR ',') AS permisos_ids,
            COUNT(DISTINCT ur.id_usuario) AS total_usuarios
        FROM roles r
        LEFT JOIN rol_permiso rp ON rp.id_rol    = r.id
        LEFT JOIN permisos p     ON p.id          = rp.id_permiso
        LEFT JOIN usuario_rol ur ON ur.id_rol     = r.id
        $where
        GROUP BY r.id
        ORDER BY r.id
    ";

    jsonResponse(['roles' => $db->query($sql)->fetchAll()]);
}

// ─────────────────────────────────────────────────────────────────────────────
// POST: router de acciones
// ─────────────────────────────────────────────────────────────────────────────
function manejarPost(array $data): never {
    global $esGerente;
    $action = sanitizeStr($data['action'] ?? '');
    // El gerente no puede gestionar roles
    if ($esGerente && in_array($action, ['crear_rol', 'editar_rol', 'eliminar_rol'])) {
        jsonError('No tienes permiso para gestionar roles', 403);
    }
    match ($action) {
        'crear'          => crearUsuario($data),
        'editar'         => editarUsuario($data),
        'toggle_estado'  => toggleEstado($data),
        'reset_password' => resetPassword($data),
        'eliminar'       => eliminarUsuario($data),
        'crear_rol'      => crearRol($data),
        'editar_rol'     => editarRol($data),
        'eliminar_rol'   => eliminarRol($data),
        default          => jsonError('Acción no válida', 404),
    };
}

// ─────────────────────────────────────────────────────────────────────────────
// CREAR USUARIO
// ─────────────────────────────────────────────────────────────────────────────
function crearUsuario(array $d): never {
    global $db, $esSuperadmin, $esGerente, $id_empresa, $uid_sesion;

    $personaId  = (int)($d['id_persona'] ?? 0);
    $username   = sanitizeStr($d['username'] ?? '');
    $password   = (string)($d['password'] ?? '');
    $rolId      = (int)($d['id_rol'] ?? 0);
    $cambiarPass = isset($d['cambiar_password']) ? (int)$d['cambiar_password'] : 1;
    $centro     = $esSuperadmin ? ($d['id_empresa'] ? (int)$d['id_empresa'] : null) : $id_empresa;

    // Validaciones
    if (!$personaId)               jsonError('Selecciona una persona');
    if (!$username)                jsonError('El nombre de usuario es obligatorio');
    if (!preg_match('/^[a-z0-9._]{3,50}$/i', $username)) jsonError('Username: solo letras, números, punto y guión bajo (3-50 chars)');
    if (strlen($password) < 8)     jsonError('La contraseña debe tener al menos 8 caracteres');
    if (!$rolId)                   jsonError('Selecciona un rol');

    // Verificar que el username no exista
    $existe = $db->prepare("SELECT id FROM usuarios WHERE username = ?");
    $existe->execute([$username]);
    if ($existe->fetch()) jsonError('El nombre de usuario ya está en uso');

    // Verificar que la persona no tenga usuario
    $tieneUsuario = $db->prepare("SELECT id FROM usuarios WHERE id_persona = ?");
    $tieneUsuario->execute([$personaId]);
    if ($tieneUsuario->fetch()) jsonError('Esta persona ya tiene un usuario asignado');

    // Verificar que la persona pertenece al centro de la sesión (admin/gerente no puede asignar personas de otro centro)
    if (!$esSuperadmin) {
        $chkPersona = $db->prepare("SELECT id FROM personas WHERE id = ? AND id_empresa = ?");
        $chkPersona->execute([$personaId, $id_empresa]);
        if (!$chkPersona->fetch()) jsonError('No tienes acceso a esta persona', 403);
    }

    // Verificar que el rol existe y admin/gerente no asigna superadmin/admin
    $rol = $db->prepare("SELECT id, nombre FROM roles WHERE id = ?");
    $rol->execute([$rolId]);
    $rolData = $rol->fetch();
    if (!$rolData) jsonError('Rol no válido');
    if (!$esSuperadmin && in_array($rolData['nombre'], ['superadmin','admin'])) {
        jsonError('No tienes permiso para asignar ese rol');
    }
    // El gerente tampoco puede asignar rol gerente
    if ($esGerente && $rolData['nombre'] === 'gerente') {
        jsonError('No tienes permiso para asignar el rol de gerente');
    }

    $hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);

    try {
        $db->beginTransaction();

        $stmt = $db->prepare("
            INSERT INTO usuarios (id_persona, id_empresa, username, password, activo, cambiar_password)
            VALUES (?, ?, ?, ?, 1, ?)
        ");
        $stmt->execute([$personaId, $centro, $username, $hash, $cambiarPass]);
        $nuevoId = (int)$db->lastInsertId();

        // Asignar rol
        $db->prepare("INSERT INTO usuario_rol (id_usuario, id_rol) VALUES (?, ?)")
           ->execute([$nuevoId, $rolId]);

        $db->commit();
        registrarAudit($uid_sesion, 'usuario_creado', 1);

        // Notificar credenciales al usuario recién creado
        try {
            notif_credenciales($personaId, $username, $password, $centro);
        } catch (\Throwable) { /* No interrumpir el flujo si falla la notificación */ }

        jsonResponse(['success' => true, 'mensaje' => 'Usuario creado correctamente']);
    } catch (\Throwable $e) {
        $db->rollBack();
        jsonError('Error al crear usuario: ' . $e->getMessage());
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// EDITAR USUARIO
// ─────────────────────────────────────────────────────────────────────────────
function editarUsuario(array $d): never {
    global $db, $esSuperadmin, $esGerente, $id_empresa, $uid_sesion;

    $id         = (int)($d['id'] ?? 0);
    $username   = sanitizeStr($d['username'] ?? '');
    $password   = (string)($d['password'] ?? '');
    $rolId      = (int)($d['id_rol'] ?? 0);
    $cambiarPass = (int)($d['cambiar_password'] ?? 0);
    $centro     = $esSuperadmin ? ($d['id_empresa'] ? (int)$d['id_empresa'] : null) : $id_empresa;

    if (!$id)       jsonError('ID de usuario inválido');
    if (!$username) jsonError('El nombre de usuario es obligatorio');
    if (!$rolId)    jsonError('Selecciona un rol');

    // Verificar que el usuario pertenece al centro (si admin)
    verificarAccesoUsuario($id);

    // Verificar username único (excluyendo al propio)
    $existe = $db->prepare("SELECT id FROM usuarios WHERE username = ? AND id != ?");
    $existe->execute([$username, $id]);
    if ($existe->fetch()) jsonError('El nombre de usuario ya está en uso');

    // Verificar rol válido
    $rol = $db->prepare("SELECT nombre FROM roles WHERE id = ?");
    $rol->execute([$rolId]);
    $rolData = $rol->fetch();
    if (!$rolData) jsonError('Rol no válido');
    if (!$esSuperadmin && in_array($rolData['nombre'], ['superadmin','admin'])) {
        jsonError('No tienes permiso para asignar ese rol');
    }
    if ($esGerente && $rolData['nombre'] === 'gerente') {
        jsonError('No tienes permiso para asignar el rol de gerente');
    }

    try {
        $db->beginTransaction();

        if ($password) {
            if (strlen($password) < 8) jsonError('La contraseña debe tener al menos 8 caracteres');
            $hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
            $db->prepare("UPDATE usuarios SET username=?, password=?, cambiar_password=?, id_empresa=? WHERE id=?")
               ->execute([$username, $hash, $cambiarPass, $centro, $id]);
        } else {
            $db->prepare("UPDATE usuarios SET username=?, cambiar_password=?, id_empresa=? WHERE id=?")
               ->execute([$username, $cambiarPass, $centro, $id]);
        }

        // Actualizar rol
        $db->prepare("DELETE FROM usuario_rol WHERE id_usuario = ?")->execute([$id]);
        $db->prepare("INSERT INTO usuario_rol (id_usuario, id_rol) VALUES (?, ?)")->execute([$id, $rolId]);

        $db->commit();
        registrarAudit($uid_sesion, 'usuario_editado', 1);
        jsonResponse(['success' => true, 'mensaje' => 'Usuario actualizado correctamente']);
    } catch (\Throwable $e) {
        $db->rollBack();
        jsonError('Error al actualizar: ' . $e->getMessage());
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// ACTIVAR / DESACTIVAR
// ─────────────────────────────────────────────────────────────────────────────
function toggleEstado(array $d): never {
    global $db, $uid_sesion;

    $id     = (int)($d['id']     ?? 0);
    $activo = (int)($d['activo'] ?? 0);

    if (!$id) jsonError('ID inválido');

    // No puede desactivarse a sí mismo
    if ($id === $uid_sesion) jsonError('No puedes desactivar tu propia cuenta');

    verificarAccesoUsuario($id);

    $db->prepare("UPDATE usuarios SET activo = ? WHERE id = ?")->execute([$activo, $id]);
    registrarAudit($uid_sesion, $activo ? 'usuario_activado' : 'usuario_desactivado', 1);

    jsonResponse(['success' => true, 'mensaje' => $activo ? 'Usuario activado' : 'Usuario desactivado']);
}

// ─────────────────────────────────────────────────────────────────────────────
// RESETEAR CONTRASEÑA
// ─────────────────────────────────────────────────────────────────────────────
function resetPassword(array $d): never {
    global $db, $uid_sesion;

    $id       = (int)($d['id']       ?? 0);
    $password = (string)($d['password'] ?? '');
    $cambiar  = (int)($d['cambiar_password'] ?? 1);

    if (!$id)               jsonError('ID inválido');
    if (strlen($password) < 8) jsonError('Mínimo 8 caracteres');

    verificarAccesoUsuario($id);

    $hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
    $db->prepare("UPDATE usuarios SET password=?, cambiar_password=?, reset_token=NULL, reset_expiry=NULL WHERE id=?")
       ->execute([$hash, $cambiar, $id]);

    registrarAudit($uid_sesion, 'reset_password', 1);
    jsonResponse(['success' => true, 'mensaje' => 'Contraseña actualizada']);
}

// ─────────────────────────────────────────────────────────────────────────────
// ELIMINAR USUARIO
// ─────────────────────────────────────────────────────────────────────────────
function eliminarUsuario(array $d): never {
    global $db, $uid_sesion;

    $id = (int)($d['id'] ?? 0);
    if (!$id) jsonError('ID inválido');

    // No puede eliminarse a sí mismo
    if ($id === $uid_sesion) jsonError('No puedes eliminar tu propia cuenta');

    verificarAccesoUsuario($id);

    // No eliminar superadmin
    $stmt = $db->prepare("
        SELECT r.nombre FROM usuarios u
        JOIN usuario_rol ur ON ur.id_usuario = u.id
        JOIN roles r        ON r.id = ur.id_rol
        WHERE u.id = ?
    ");
    $stmt->execute([$id]);
    $rol = $stmt->fetchColumn();
    if ($rol === 'superadmin') jsonError('No se puede eliminar el superadministrador');

    $db->prepare("DELETE FROM usuarios WHERE id = ?")->execute([$id]);
    registrarAudit($uid_sesion, 'usuario_eliminado', 1);

    jsonResponse(['success' => true, 'mensaje' => 'Usuario eliminado']);
}

// ─────────────────────────────────────────────────────────────────────────────
// CREAR ROL
// ─────────────────────────────────────────────────────────────────────────────
function crearRol(array $d): never {
    global $db, $uid_sesion;

    $nombre      = sanitizeStr(strtolower($d['nombre'] ?? ''));
    $descripcion = sanitizeStr($d['descripcion'] ?? '');
    $permisos    = is_array($d['permisos'] ?? null) ? array_map('intval', $d['permisos']) : [];

    if (!$nombre)      jsonError('El nombre es obligatorio');
    if (!$descripcion) jsonError('La descripción es obligatoria');
    if (!preg_match('/^[a-z0-9_]{2,50}$/', $nombre)) jsonError('Nombre: solo minúsculas, números y guión bajo');

    $existe = $db->prepare("SELECT id FROM roles WHERE nombre = ?");
    $existe->execute([$nombre]);
    if ($existe->fetch()) jsonError('Ya existe un rol con ese nombre');

    try {
        $db->beginTransaction();

        $db->prepare("INSERT INTO roles (nombre, descripcion) VALUES (?, ?)")->execute([$nombre, $descripcion]);
        $rolId = (int)$db->lastInsertId();

        if ($permisos) {
            $ins = $db->prepare("INSERT IGNORE INTO rol_permiso (id_rol, id_permiso) VALUES (?, ?)");
            foreach ($permisos as $pId) { if ($pId > 0) $ins->execute([$rolId, $pId]); }
        }

        $db->commit();
        registrarAudit($uid_sesion, 'rol_asignado', 1);
        jsonResponse(['success' => true, 'mensaje' => "Rol '$nombre' creado correctamente"]);
    } catch (\Throwable $e) {
        $db->rollBack();
        jsonError('Error al crear rol: ' . $e->getMessage());
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// EDITAR ROL (nombre, descripción y permisos)
// ─────────────────────────────────────────────────────────────────────────────
function editarRol(array $d): never {
    global $db, $esSuperadmin, $uid_sesion;

    $id          = (int)($d['id'] ?? 0);
    $nombre      = sanitizeStr(strtolower($d['nombre'] ?? ''));
    $descripcion = sanitizeStr($d['descripcion'] ?? '');
    $permisos    = is_array($d['permisos'] ?? null) ? array_map('intval', $d['permisos']) : [];

    if (!$id)          jsonError('ID inválido');
    if (!$nombre)      jsonError('El nombre es obligatorio');
    if (!$descripcion) jsonError('La descripción es obligatoria');

    // No editar roles de sistema si es admin
    $rolActual = $db->prepare("SELECT nombre FROM roles WHERE id = ?");
    $rolActual->execute([$id]);
    $nombreActual = $rolActual->fetchColumn();
    $rolesSistema = ['superadmin','admin','gerente','supervisor','cajero','asesor','auditor','cliente','consultor'];
    if (!$esSuperadmin && in_array($nombreActual, $rolesSistema)) {
        jsonError('No puedes editar roles del sistema');
    }

    // Verificar nombre único excluyendo el propio
    $existe = $db->prepare("SELECT id FROM roles WHERE nombre = ? AND id != ?");
    $existe->execute([$nombre, $id]);
    if ($existe->fetch()) jsonError('Ya existe un rol con ese nombre');

    try {
        $db->beginTransaction();

        $db->prepare("UPDATE roles SET nombre=?, descripcion=? WHERE id=?")->execute([$nombre, $descripcion, $id]);

        // Reemplazar permisos completo
        $db->prepare("DELETE FROM rol_permiso WHERE id_rol = ?")->execute([$id]);
        if ($permisos) {
            $ins = $db->prepare("INSERT IGNORE INTO rol_permiso (id_rol, id_permiso) VALUES (?, ?)");
            foreach ($permisos as $pId) { if ($pId > 0) $ins->execute([$id, $pId]); }
        }

        $db->commit();
        registrarAudit($uid_sesion, 'rol_asignado', 1);
        jsonResponse(['success' => true, 'mensaje' => "Rol actualizado correctamente"]);
    } catch (\Throwable $e) {
        $db->rollBack();
        jsonError('Error al editar rol: ' . $e->getMessage());
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// ELIMINAR ROL
// ─────────────────────────────────────────────────────────────────────────────
function eliminarRol(array $d): never {
    global $db, $esSuperadmin, $uid_sesion;

    $id = (int)($d['id'] ?? 0);
    if (!$id) jsonError('ID inválido');

    $rolActual = $db->prepare("SELECT nombre FROM roles WHERE id = ?");
    $rolActual->execute([$id]);
    $nombre = $rolActual->fetchColumn();
    if (!$nombre) jsonError('Rol no encontrado');

    $rolesSistema = ['superadmin','admin','gerente','supervisor','cajero','asesor','auditor','cliente','consultor'];
    if (in_array($nombre, $rolesSistema)) jsonError("El rol '$nombre' es del sistema y no puede eliminarse");

    // Verificar que no tenga usuarios asignados
    $stmt = $db->prepare("SELECT COUNT(*) FROM usuario_rol WHERE id_rol = ?");
    $stmt->execute([$id]);
    if ((int)$stmt->fetchColumn() > 0) jsonError('No puedes eliminar un rol que tiene usuarios asignados');

    $db->prepare("DELETE FROM roles WHERE id = ?")->execute([$id]);
    jsonResponse(['success' => true, 'mensaje' => "Rol '$nombre' eliminado"]);
}

// ─────────────────────────────────────────────────────────────────────────────
// HELPER: verifica que el admin solo acceda a usuarios de su centro
// ─────────────────────────────────────────────────────────────────────────────
function verificarAccesoUsuario(int $id): void {
    global $db, $esGerente, $id_empresa;

    $stmt = $db->prepare("SELECT u.id_empresa, r.nombre AS rol FROM usuarios u
        JOIN usuario_rol ur ON ur.id_usuario = u.id
        JOIN roles r ON r.id = ur.id_rol
        WHERE u.id = ?");
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    if (!$row || (int)$row['id_empresa'] !== (int)$id_empresa) {
        jsonError('No tienes permiso sobre este usuario', 403);
    }
    // El gerente no puede modificar usuarios con rol admin o superadmin
    if ($esGerente && in_array($row['rol'], ['superadmin', 'admin', 'gerente'])) {
        jsonError('No tienes permiso para modificar este usuario', 403);
    }
}