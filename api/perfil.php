<?php
// api/perfil.php — API Editar Perfil propio | GestionPrestamo

require_once __DIR__ . '/../php/helpers.php';
require_once __DIR__ . '/../php/audit_actions.php';

apiHeaders();
$sesion = verificarSesionAPI();

$db        = getDB();
$uid       = (int)$sesion['usuario_id'];
$personaId = (int)$sesion['persona_id'];
$method    = $_SERVER['REQUEST_METHOD'];
$action    = sanitizeStr($_GET['action'] ?? '');

match (true) {
    $action === 'obtener'          && $method === 'GET'  => obtenerPerfil(),
    $action === 'actualizar'       && $method === 'POST' => actualizarPerfil(),
    $action === 'cambiar_password' && $method === 'POST' => cambiarPassword(),
    $action === 'subir_foto'       && $method === 'POST' => subirFoto(),
    $action === 'eliminar_foto'    && $method === 'POST' => eliminarFoto(),
    default => jsonError('Acción no válida', 404),
};

// ─────────────────────────────────────────────────────────────
// GET: datos del perfil
// ─────────────────────────────────────────────────────────────
function obtenerPerfil(): never {
    global $db, $uid, $personaId;

    // Persona
    $stP = $db->prepare("
        SELECT p.id, p.nombre, p.apellido, p.cedula, p.fecha_nacimiento,
               p.genero, p.nacionalidad, p.estado_civil, p.foto_path,
               u.username, u.cambiar_password
        FROM   personas p
        JOIN   usuarios u ON u.id_persona = p.id
        WHERE  p.id = ? AND u.id = ?
        LIMIT  1
    ");
    $stP->execute([$personaId, $uid]);
    $perfil = $stP->fetch();
    if (!$perfil) jsonError('Perfil no encontrado', 404);

    // Contactos
    $stC = $db->prepare("SELECT tipo_contacto, valor FROM contactos_persona WHERE id_persona = ? ORDER BY id");
    $stC->execute([$personaId]);
    $contactos = $stC->fetchAll();

    // Agrupar contactos por tipo
    $perfil['contactos'] = [];
    foreach ($contactos as $c) {
        $perfil['contactos'][$c['tipo_contacto']] = $c['valor'];
    }

    jsonResponse(['perfil' => $perfil]);
}

// ─────────────────────────────────────────────────────────────
// POST: actualizar datos personales
// ─────────────────────────────────────────────────────────────
function actualizarPerfil(): never {
    global $db, $uid, $personaId, $sesion;

    $d = inputJSON();

    $nombre       = sanitizeStr($d['nombre']       ?? '');
    $apellido     = sanitizeStr($d['apellido']      ?? '');
    $cedula       = sanitizeStr($d['cedula']        ?? '');
    $fnac         = sanitizeStr($d['fecha_nacimiento'] ?? '');
    $genero       = sanitizeStr($d['genero']        ?? '');
    $nacionalidad = sanitizeStr($d['nacionalidad']  ?? '');
    $estadoCivil  = sanitizeStr($d['estado_civil']  ?? '') ?: null;
    $telefono     = sanitizeStr($d['telefono']      ?? '');
    $email        = sanitizeStr($d['email']         ?? '');
    $whatsapp     = sanitizeStr($d['whatsapp']      ?? '');
    $direccion    = sanitizeStr($d['direccion']     ?? '', 500);

    if (!$nombre || !$apellido) jsonError('Nombre y apellido son requeridos.');
    if ($fnac && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $fnac)) jsonError('Fecha de nacimiento inválida.');

    $generosValidos    = ['Masculino','Femenino','Otro'];
    $estadosValidos    = ['Soltero/a','Casado/a','Divorciado/a','Viudo/a','Unión Libre',''];
    if ($genero && !in_array($genero, $generosValidos, true)) jsonError('Género inválido.');
    if ($estadoCivil && !in_array($estadoCivil, $estadosValidos, true)) $estadoCivil = null;

    // Cédula única dentro del mismo centro (excluyendo la propia)
    if ($cedula) {
        $idCentro = (int)($sesion['id_empresa'] ?? 0);
        $chk = $db->prepare("SELECT id FROM personas WHERE cedula = ? AND id != ? AND id_empresa = ? LIMIT 1");
        $chk->execute([$cedula, $personaId, $idCentro]);
        if ($chk->fetch()) jsonError('Esa cédula ya está registrada a otra persona en este centro.');
    }

    try {
        $db->beginTransaction();

        // Actualizar persona
        $db->prepare("
            UPDATE personas
            SET nombre=?, apellido=?, cedula=?, fecha_nacimiento=?,
                genero=?, nacionalidad=?, estado_civil=?, actualizado_en=NOW()
            WHERE id=?
        ")->execute([
            $nombre, $apellido,
            $cedula ?: null,
            $fnac   ?: null,
            $genero ?: null,
            $nacionalidad ?: null,
            $estadoCivil,
            $personaId
        ]);

        // Actualizar/insertar contactos
        $contactosTipos = [
            'Telefono'   => $telefono,
            'Email'      => $email,
            'WhatsApp'   => $whatsapp,
            'Dirección'  => $direccion,
        ];

        foreach ($contactosTipos as $tipo => $valor) {
            $chk = $db->prepare("SELECT id FROM contactos_persona WHERE id_persona=? AND tipo_contacto=? LIMIT 1");
            $chk->execute([$personaId, $tipo]);
            $existe = $chk->fetch();

            if ($valor) {
                if ($existe) {
                    $db->prepare("UPDATE contactos_persona SET valor=? WHERE id_persona=? AND tipo_contacto=?")
                       ->execute([$valor, $personaId, $tipo]);
                } else {
                    $db->prepare("INSERT INTO contactos_persona (id_persona, tipo_contacto, valor) VALUES (?,?,?)")
                       ->execute([$personaId, $tipo, $valor]);
                }
            } elseif ($existe) {
                $db->prepare("DELETE FROM contactos_persona WHERE id_persona=? AND tipo_contacto=?")
                   ->execute([$personaId, $tipo]);
            }
        }

        // Refrescar nombre en sesión
        $_SESSION['nombre'] = trim($nombre . ' ' . $apellido);
        unset($_SESSION['_user_refresh_ts']); // forzar refresh en sidebar

        $db->commit();
        registrarAudit($uid, 'perfil_actualizado', 1);
        jsonResponse(['success' => true, 'mensaje' => 'Perfil actualizado correctamente.']);

    } catch (\Throwable $e) {
        $db->rollBack();
        jsonError('Error al guardar: ' . $e->getMessage());
    }
}

// ─────────────────────────────────────────────────────────────
// POST: cambiar contraseña
// ─────────────────────────────────────────────────────────────
function cambiarPassword(): never {
    global $db, $uid;

    $d           = inputJSON();
    $actual      = (string)($d['password_actual']  ?? '');
    $nueva       = (string)($d['password_nueva']   ?? '');
    $confirmar   = (string)($d['password_confirmar'] ?? '');

    if (!$actual || !$nueva) jsonError('Completa todos los campos.');
    if (mb_strlen($nueva) < 8)          jsonError('La nueva contraseña debe tener al menos 8 caracteres.');
    if (!preg_match('/[A-Z]/', $nueva))  jsonError('Debe incluir al menos una letra mayúscula.');
    if (!preg_match('/[0-9]/', $nueva))  jsonError('Debe incluir al menos un número.');
    if ($nueva !== $confirmar)           jsonError('Las contraseñas nuevas no coinciden.');

    // Verificar contraseña actual
    $stm = $db->prepare("SELECT password FROM usuarios WHERE id = ? LIMIT 1");
    $stm->execute([$uid]);
    $row = $stm->fetch();
    if (!$row || !password_verify($actual, $row['password'])) {
        jsonError('La contraseña actual es incorrecta.');
    }

    $hash = password_hash($nueva, PASSWORD_BCRYPT, ['cost' => 12]);
    $db->prepare("UPDATE usuarios SET password=?, cambiar_password=0, reset_token=NULL, reset_expiry=NULL WHERE id=?")
       ->execute([$hash, $uid]);

    $_SESSION['cambiar_pass'] = false;
    registrarAudit($uid, 'cambio_password', 1);
    jsonResponse(['success' => true, 'mensaje' => 'Contraseña cambiada correctamente.']);
}

// ─────────────────────────────────────────────────────────────
// POST: subir foto de perfil
// ─────────────────────────────────────────────────────────────
function subirFoto(): never {
    global $db, $uid, $personaId;

    if (empty($_FILES['foto'])) jsonError('No se recibió ningún archivo.');

    $file    = $_FILES['foto'];
    $maxSize = 3 * 1024 * 1024; // 3 MB
    $allowed = ['image/jpeg','image/png','image/webp','image/gif'];

    if ($file['error'] !== UPLOAD_ERR_OK) jsonError('Error al subir el archivo.');
    if ($file['size'] > $maxSize)         jsonError('La imagen no puede superar 3 MB.');

    $finfo    = finfo_open(FILEINFO_MIME_TYPE);
    $mimeType = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);

    if (!in_array($mimeType, $allowed, true)) jsonError('Solo se permiten imágenes JPG, PNG, WebP o GIF.');

    $ext      = match($mimeType) {
        'image/png'  => 'png',
        'image/webp' => 'webp',
        'image/gif'  => 'gif',
        default      => 'jpg',
    };

    $uploadDir = __DIR__ . '/../uploads/fotos/';
    if (!is_dir($uploadDir)) mkdir($uploadDir, 0775, true);

    // Eliminar foto anterior si existe
    $stOld = $db->prepare("SELECT foto_path FROM personas WHERE id=? LIMIT 1");
    $stOld->execute([$personaId]);
    $oldFoto = $stOld->fetchColumn();
    if ($oldFoto && file_exists($uploadDir . $oldFoto)) {
        unlink($uploadDir . $oldFoto);
    }

    $filename = 'perfil_' . $personaId . '_' . time() . '.' . $ext;
    $dest     = $uploadDir . $filename;

    if (!move_uploaded_file($file['tmp_name'], $dest)) {
        jsonError('No se pudo guardar la imagen.');
    }

    $db->prepare("UPDATE personas SET foto_path=? WHERE id=?")->execute([$filename, $personaId]);
    $_SESSION['foto_path'] = $filename;
    unset($_SESSION['_user_refresh_ts']);

    registrarAudit($uid, 'foto_perfil_actualizada', 1);
    jsonResponse(['success' => true, 'foto_path' => $filename, 'mensaje' => 'Foto actualizada.']);
}

// ─────────────────────────────────────────────────────────────
// POST: eliminar foto de perfil
// ─────────────────────────────────────────────────────────────
function eliminarFoto(): never {
    global $db, $uid, $personaId;

    $stOld = $db->prepare("SELECT foto_path FROM personas WHERE id=? LIMIT 1");
    $stOld->execute([$personaId]);
    $foto = $stOld->fetchColumn();

    if ($foto) {
        $path = __DIR__ . '/../uploads/fotos/' . $foto;
        if (file_exists($path)) unlink($path);
        $db->prepare("UPDATE personas SET foto_path=NULL WHERE id=?")->execute([$personaId]);
        $_SESSION['foto_path'] = null;
        unset($_SESSION['_user_refresh_ts']);
    }

    registrarAudit($uid, 'foto_perfil_eliminada', 1);
    jsonResponse(['success' => true, 'mensaje' => 'Foto eliminada.']);
}
