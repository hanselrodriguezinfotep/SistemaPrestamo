<?php
// php/auth.php — API de autenticación GestionPrestamo
// Patrón Restaurante4: php/ separado, JSON API

require_once __DIR__ . '/../php/helpers.php';

apiHeaders();
iniciarSesionSegura();

$action = sanitizeStr($_GET['action'] ?? '');
$method = $_SERVER['REQUEST_METHOD'];

match (true) {
    $action === 'login'            && $method === 'POST' => doLogin(),
    $action === 'logout'                                  => doLogout(),
    $action === 'check'                                   => checkSession(),
    $action === 'registro'         && $method === 'POST' => doRegistro(),
    $action === 'cambiar_password' && $method === 'POST' => cambiarPassword(),
    $action === 'solicitar_reset'  && $method === 'POST' => solicitarReset(),
    $action === 'reset_con_codigo' && $method === 'POST' => resetConCodigo(),
    default                                               => jsonError('Acción no válida', 404),
};

// ─────────────────────────────────────────────────────────────
// LOGIN — usa la vista v_usuarios_login para traer rol + permisos
// en una sola consulta
// ─────────────────────────────────────────────────────────────
function doLogin(): never {
    checkRateLimit('login', 5, 300);

    $data     = inputJSON();
    $username = sanitizeStr($data['username'] ?? '');
    $password = (string)($data['password'] ?? '');

    if ($username === '' || $password === '') {
        jsonError('Usuario y contraseña son requeridos.');
    }

    $db   = getDB();
    // Consulta a la VISTA que une usuarios + personas + roles + permisos
    $stmt = $db->prepare('
        SELECT *
        FROM   v_usuarios_login
        WHERE  username = ?
        LIMIT  1
    ');
    $stmt->execute([$username]);
    $usuario = $stmt->fetch();

    // Anti timing-attack: siempre calcular hash aunque no exista el usuario
    $hash = $usuario['password'] ?? '$2y$12$invalidsaltinvalidsaltinvalidsaltXXXXXXX';
    if (!$usuario || !$usuario['activo'] || !password_verify($password, $hash)) {
        // Registrar intento fallido en audit_log si el usuario existe
        if ($usuario) {
            registrarAudit((int)$usuario['usuario_id'], 'login_fallido', 0);
        }
        sleep(1);
        jsonError('Credenciales inválidas.', 401);
    }

    // Sesión exitosa
    session_regenerate_id(true);
    resetRateLimit('login');

    // Permisos como array desde el GROUP_CONCAT de la vista
    $permisos = $usuario['permisos'] ? explode(',', $usuario['permisos']) : [];

    // Poblar sesión con datos de la vista
    $_SESSION['usuario_id']   = (int)$usuario['usuario_id'];
    $_SESSION['persona_id']   = (int)$usuario['persona_id'];
    // Superadmin siempre opera sobre centro 1 — nunca null
    $_SESSION['id_empresa']    = $usuario['id_empresa'] ? (int)$usuario['id_empresa'] : ($usuario['rol'] === 'superadmin' ? 1 : null);
    $_SESSION['nombre']       = $usuario['nombre_completo'];
    $_SESSION['tipo_persona'] = $usuario['tipo_persona'];
    $_SESSION['rol_id']       = (int)$usuario['rol_id'];
    $_SESSION['rol']          = $usuario['rol'];
    $_SESSION['permisos']     = $permisos;
    $_SESSION['cambiar_pass'] = (bool)$usuario['cambiar_password'];
    $_SESSION['login_time']   = time();

    // Obtener foto_path de la persona vinculada
    try {
        $stFoto = $db->prepare('SELECT foto_path FROM personas WHERE id = ? LIMIT 1');
        $stFoto->execute([$usuario['persona_id']]);
        $fotoRow = $stFoto->fetch();
        $_SESSION['foto_path'] = $fotoRow['foto_path'] ?? null;
    } catch (\Throwable) {
        $_SESSION['foto_path'] = null;
    }

    // Limpiar cache del brand para forzar lectura fresca desde DB
    $idC = $usuario['id_empresa'] ? (int)$usuario['id_empresa'] : null;
    unset($_SESSION['brand_' . ($idC ?? 'sa')]);

    // Actualizar último login
    $db->prepare('UPDATE usuarios SET ultimo_login = NOW() WHERE id = ?')
       ->execute([$usuario['usuario_id']]);

    // Registrar acceso exitoso
    registrarAudit((int)$usuario['usuario_id'], 'login_exitoso', 1);

    // Redirigir al dashboard (todos los roles autorizados tienen acceso)
    $redirect = '/GestionPrestamo/index.php';

    jsonResponse([
        'success'  => true,
        'redirect' => $redirect,
        'usuario'  => [
            'id'           => (int)$usuario['usuario_id'],
            'nombre'       => $usuario['nombre_completo'],
            'rol'          => $usuario['rol'],
            'tipo_persona' => $usuario['tipo_persona'],
            'cambiar_pass' => (bool)$usuario['cambiar_password'],
        ],
    ]);
}

// ─────────────────────────────────────────────────────────────
// LOGOUT
// ─────────────────────────────────────────────────────────────
function doLogout(): never {
    iniciarSesionSegura();
    if (!empty($_SESSION['usuario_id'])) {
        registrarAudit((int)$_SESSION['usuario_id'], 'logout', 1);
    }
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
    jsonResponse(['success' => true]);
}

// ─────────────────────────────────────────────────────────────
// CHECK SESSION
// ─────────────────────────────────────────────────────────────
function checkSession(): never {
    $logueado = !empty($_SESSION['usuario_id']);
    jsonResponse([
        'logueado' => $logueado,
        'usuario'  => $logueado ? [
            'id'     => $_SESSION['usuario_id'],
            'nombre' => $_SESSION['nombre'] ?? '',
            'rol'    => $_SESSION['rol']    ?? '',
        ] : null,
    ]);
}

// ─────────────────────────────────────────────────────────────
// REGISTRO — crea persona + usuario + asigna rol 'cliente'
// Solo registro público → rol cliente por defecto
// ─────────────────────────────────────────────────────────────
function doRegistro(): never {
    checkRateLimit('registro', 3, 600);

    $data      = inputJSON();
    $nombre    = sanitizeStr($data['nombre']    ?? '');
    $apellido  = sanitizeStr($data['apellido']  ?? '');
    $username  = sanitizeStr($data['username']  ?? '');
    $email     = sanitizeStr($data['email']     ?? '');
    $password  = (string)($data['password']    ?? '');
    $confirm   = (string)($data['confirm']     ?? '');
    $tipo      = sanitizeStr($data['tipo_persona'] ?? 'Cliente');
    $fnac      = sanitizeStr($data['fecha_nacimiento'] ?? '');
    $genero    = sanitizeStr($data['genero']    ?? 'Masculino');

    // ── Validaciones ──────────────────────────────────────────
    if ($nombre === '' || $apellido === '' || $username === '' || $password === '') {
        jsonError('Nombre, apellido, usuario y contraseña son requeridos.');
    }
    if (mb_strlen($username) < 3) {
        jsonError('El usuario debe tener al menos 3 caracteres.');
    }
    if (!preg_match('/^[a-zA-Z0-9_.]+$/', $username)) {
        jsonError('El usuario solo puede tener letras, números, puntos y guiones bajos.');
    }
    if (mb_strlen($password) < 8) {
        jsonError('La contraseña debe tener al menos 8 caracteres.');
    }
    if (!preg_match('/[A-Z]/', $password)) {
        jsonError('La contraseña debe tener al menos una mayúscula.');
    }
    if (!preg_match('/[0-9]/', $password)) {
        jsonError('La contraseña debe tener al menos un número.');
    }
    if ($password !== $confirm) {
        jsonError('Las contraseñas no coinciden.');
    }
    if ($email !== '') {
        $email = sanitizeEmail($email) ?: '';
        if ($email === '') jsonError('Correo electrónico inválido.');
    }

    $tiposValidos = ['Cliente', 'Asesor', 'Empleado', 'Garante'];
    if (!in_array($tipo, $tiposValidos, true)) $tipo = 'Cliente';

    $generosValidos = ['Masculino', 'Femenino', 'Otro'];
    if (!in_array($genero, $generosValidos, true)) $genero = 'Masculino';

    // Validar fecha de nacimiento
    if ($fnac === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $fnac)) {
        jsonError('Fecha de nacimiento requerida (formato YYYY-MM-DD).');
    }

    $db = getDB();

    // Verificar username único
    $chk = $db->prepare('SELECT id FROM usuarios WHERE username = ? LIMIT 1');
    $chk->execute([$username]);
    if ($chk->fetch()) jsonError('Ese nombre de usuario ya está en uso.');

    // Verificar email único (si fue dado)
    if ($email !== '') {
        $chkE = $db->prepare("
            SELECT cp.id FROM contactos_persona cp
            WHERE cp.tipo_contacto = 'Email' AND cp.valor = ? LIMIT 1
        ");
        $chkE->execute([$email]);
        if ($chkE->fetch()) jsonError('Ese correo ya está registrado.');
    }

    // Validar id_empresa — obligatorio, no puede quedar NULL en el sistema
    $idCentroReg = (int)($data['id_empresa'] ?? 0);
    if (!$idCentroReg) {
        jsonError('La empresa es obligatoria para el registro.');
    }
    // Verificar que el centro existe y está activo
    $chkC = $db->prepare("SELECT id FROM empresas WHERE id = ? AND estado = 'Activo' LIMIT 1");
    $chkC->execute([$idCentroReg]);
    if (!$chkC->fetch()) {
        jsonError('Empresa no válida o inactiva.');
    }

    // Transacción: crear persona → usuario → contacto → asignar rol
    $db->beginTransaction();
    try {
        // 1. Insertar persona

        $db->prepare('
            INSERT INTO personas (nombre, apellido, tipo_persona, fecha_nacimiento, genero, id_empresa)
            VALUES (?, ?, ?, ?, ?, ?)
        ')->execute([$nombre, $apellido, $tipo, $fnac, $genero, $idCentroReg]);

        $personaId = (int)$db->lastInsertId();

        // 2. Insertar email en contactos_persona
        if ($email !== '') {
            $db->prepare('
                INSERT INTO contactos_persona (id_persona, tipo_contacto, valor)
                VALUES (?, "Email", ?)
            ')->execute([$personaId, $email]);
        }

        // 3. Crear usuario
        $hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
        $db->prepare('
            INSERT INTO usuarios (id_persona, id_empresa, username, password, activo, cambiar_password)
            VALUES (?, ?, ?, ?, 1, 0)
        ')->execute([$personaId, $idCentroReg, $username, $hash]);

        $usuarioId = (int)$db->lastInsertId();

        // 4. Asignar rol según tipo_persona
        // El registro público asigna el rol más básico
        // 4. Asignar rol — cliente por defecto en registro público
        $rolNombre = 'cliente';
       
        $rolStmt = $db->prepare('SELECT id FROM roles WHERE nombre = ? LIMIT 1');
        $rolStmt->execute([$rolNombre]);
        $rol = $rolStmt->fetch();
        if ($rol) {
            $db->prepare('INSERT INTO usuario_rol (id_usuario, id_rol) VALUES (?, ?)')
               ->execute([$usuarioId, $rol['id']]);
        }

        $db->commit();
        resetRateLimit('registro');

        jsonResponse([
            'success' => true,
            'mensaje' => '¡Cuenta creada! Ya puedes iniciar sesión.',
        ], 201);

    } catch (\Throwable $e) {
        $db->rollBack();
        jsonError('Error al crear la cuenta. Intenta de nuevo.');
    }
}

// ─────────────────────────────────────────────────────────────
// CAMBIAR CONTRASEÑA (usuario autenticado)
// ─────────────────────────────────────────────────────────────
function cambiarPassword(): never {
    verificarSesionAPI();
    $data = inputJSON();
    $pass = (string)($data['password'] ?? '');

    if (mb_strlen($pass) < 8) jsonError('Mínimo 8 caracteres.');

    $hash = password_hash($pass, PASSWORD_BCRYPT, ['cost' => 12]);
    getDB()->prepare('UPDATE usuarios SET password = ?, cambiar_password = 0 WHERE id = ?')
           ->execute([$hash, $_SESSION['usuario_id']]);

    $_SESSION['cambiar_pass'] = false;
    registrarAudit((int)$_SESSION['usuario_id'], 'cambio_password', 1);
    jsonResponse(['success' => true]);
}

// ─────────────────────────────────────────────────────────────
// SOLICITAR RESET (paso 1 — enviar código al email)
// Busca el email en contactos_persona del usuario
// ─────────────────────────────────────────────────────────────
function solicitarReset(): never {
    checkRateLimit('reset', 3, 600);

    $data     = inputJSON();
    $username = sanitizeStr($data['username'] ?? '');
    $email    = strtolower(trim($data['email'] ?? ''));

    if ($username === '' || $email === '') {
        jsonError('Usuario y correo son requeridos.');
    }

    $db   = getDB();
    // Buscar usuario + email en contactos_persona
    $stmt = $db->prepare('
        SELECT u.id, cp.valor AS email
        FROM   usuarios u
        JOIN   personas  p  ON p.id = u.id_persona
        JOIN   contactos_persona cp ON cp.id_persona = p.id AND cp.tipo_contacto = "Email"
        WHERE  u.username = ? AND u.activo = 1
        LIMIT  1
    ');
    $stmt->execute([$username]);
    $usuario = $stmt->fetch();

    // Respuesta genérica: no revelar si el usuario existe
    if (!$usuario || strtolower($usuario['email']) !== $email) {
        sleep(1);
        jsonResponse(['success' => true]);
    }

    $code   = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    $expiry = date('Y-m-d H:i:s', time() + 1800);
    $hashTk = password_hash($code, PASSWORD_BCRYPT, ['cost' => 10]);

    $db->prepare('UPDATE usuarios SET reset_token = ?, reset_expiry = ? WHERE id = ?')
       ->execute([$hashTk, $expiry, $usuario['id']]);

    // TODO: enviar $code al email $email via SMTP
    // Por ahora se puede mostrar en desarrollo:
    // error_log("Código reset para {$username}: {$code}");

    jsonResponse(['success' => true]);
}

// ─────────────────────────────────────────────────────────────
// RESET CON CÓDIGO (paso 2)
// ─────────────────────────────────────────────────────────────
function resetConCodigo(): never {
    $data        = inputJSON();
    $username    = sanitizeStr($data['username']   ?? '');
    $code        = trim((string)($data['code']     ?? ''));
    $newPassword = (string)($data['new_password']  ?? '');
    $confirm     = (string)($data['confirm']       ?? '');

    if ($username === '' || $code === '' || mb_strlen($newPassword) < 8) {
        jsonError('Datos incompletos o contraseña muy corta.');
    }
    if ($newPassword !== $confirm) jsonError('Las contraseñas no coinciden.');

    $db   = getDB();
    $stmt = $db->prepare('
        SELECT id, reset_token, reset_expiry
        FROM   usuarios
        WHERE  username = ? AND activo = 1
        LIMIT  1
    ');
    $stmt->execute([$username]);
    $usuario = $stmt->fetch();

    if (!$usuario || empty($usuario['reset_token'])) {
        jsonError('Código inválido o expirado.', 400);
    }
    if (!password_verify($code, $usuario['reset_token'])) {
        jsonError('Código incorrecto.', 400);
    }
    if (strtotime($usuario['reset_expiry']) < time()) {
        jsonError('El código expiró. Solicita uno nuevo.', 400);
    }

    $hash = password_hash($newPassword, PASSWORD_BCRYPT, ['cost' => 12]);
    $db->prepare('
        UPDATE usuarios
        SET password = ?, cambiar_password = 0, reset_token = NULL, reset_expiry = NULL
        WHERE id = ?
    ')->execute([$hash, $usuario['id']]);

    resetRateLimit('reset');
    registrarAudit((int)$usuario['id'], 'reset_password', 1);
    jsonResponse(['success' => true]);
}

// registrarAudit() movida a helpers.php — disponible globalmente