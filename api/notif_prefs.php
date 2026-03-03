<?php
// api/notif_prefs.php — Preferencias de notificación por persona
require_once __DIR__ . '/../php/helpers.php';
apiHeaders();
iniciarSesionSegura();
$sesion = verificarSesionAPI();

if (!in_array($sesion['rol'], ['superadmin','admin','gerente','supervisor','cajero'], true))
    jsonError('Acceso denegado', 403);

$esSuperadmin = $sesion['rol'] === 'superadmin';
// id_empresa viene siempre de la sesión (superadmin ya tiene 1 fijado al login)
$id_empresa    = (int)($sesion['id_empresa'] ?? 0);
$method       = $_SERVER['REQUEST_METHOD'];
$action       = sanitizeStr($_GET['action'] ?? '');
$db           = getDB();

match(true) {
    $action === 'buscar'         && $method === 'GET'  => buscarPersonas($db, $id_empresa),
    $action === 'cargar'         && $method === 'GET'  => cargarPrefs($db, $id_empresa),
    $action === 'guardar'        && $method === 'POST' => guardarPrefs($db, $id_empresa, inputJSON()),
    $action === 'guardar_masivo' && $method === 'POST' => guardarMasivo($db, $id_empresa, inputJSON()),
    default => jsonError('Acción no válida', 404),
};

function buscarPersonas(PDO $db, int $id_empresa): never {
    $q           = trim($_GET['q'] ?? '');
    $tipo_filter = trim($_GET['tipo_filter'] ?? '');

    // Buscar todos si q='*' o si hay tipo_filter sin texto
    $buscarTodos = ($q === '*');
    if (!$buscarTodos && strlen($q) < 2 && !$tipo_filter) {
        jsonResponse(['personas' => []]);
    }

    // Verificar si existe tabla persona_tipos
    $hasMultiRol = false;
    try { $db->query("SELECT 1 FROM persona_tipos LIMIT 1"); $hasMultiRol = true; } catch (\Throwable) {}

    $tiposValidos = ['Cliente','Asesor','Empleado','Garante','Supervisor','Consultor'];

    if ($tipo_filter && in_array($tipo_filter, $tiposValidos, true) && $hasMultiRol) {
        // Buscar en tipo primario Y en tipos secundarios
        $sql = "SELECT DISTINCT p.id, CONCAT(p.nombre, ' ', p.apellido) AS nombre,
                p.tipo_persona AS tipo,
                pnp.email AS pref_email, pnp.whatsapp AS pref_whatsapp,
                pnp.canal,
                pnp.notif_credenciales, pnp.notif_pago,
                pnp.notif_cuota_vencer, 
                 pnp.notif_incidencia
         FROM personas p
         LEFT JOIN persona_notif_prefs pnp ON pnp.id_persona = p.id AND pnp.id_empresa = ?
         LEFT JOIN persona_tipos pt ON pt.id_persona = p.id AND pt.id_empresa = ?
         WHERE p.id_empresa = ?";
        $qparams = [$id_empresa, $id_empresa, $id_empresa];
        if (!$buscarTodos && strlen($q) >= 2) {
            $like = '%' . $q . '%';
            $sql .= " AND (CONCAT(p.nombre,' ',p.apellido) LIKE ? OR p.cedula LIKE ?)";
            $qparams[] = $like; $qparams[] = $like;
        }
        $sql .= " AND (p.tipo_persona = ? OR pt.tipo = ?)";
        $qparams[] = $tipo_filter; $qparams[] = $tipo_filter;
        $sql .= " ORDER BY p.apellido, p.nombre LIMIT 200";
        $stmt = $db->prepare($sql);
        $stmt->execute($qparams);
        $rows = $stmt->fetchAll();
        foreach ($rows as &$row) {
            $stT = $db->prepare("SELECT tipo FROM persona_tipos WHERE id_persona = ? AND id_empresa = ? ORDER BY es_primario DESC");
            $stT->execute([$row['id'], $id_empresa]);
            $row['tipos'] = array_column($stT->fetchAll(), 'tipo');
        }
        jsonResponse(['personas' => $rows]);
    }

    // Query general
    $where  = 'p.id_empresa = ?';
    $params = [$id_empresa]; // para el JOIN pnp
    $wParams = [$id_empresa]; // para el WHERE

    if (!$buscarTodos && strlen($q) >= 2) {
        $like    = '%' . $q . '%';
        $where  .= " AND (CONCAT(p.nombre,' ',p.apellido) LIKE ? OR p.cedula LIKE ?)";
        $wParams[] = $like;
        $wParams[] = $like;
    }
    if ($tipo_filter && in_array($tipo_filter, $tiposValidos, true)) {
        $where   .= ' AND p.tipo_persona = ?';
        $wParams[] = $tipo_filter;
    }

    $stmt = $db->prepare(
        "SELECT p.id, CONCAT(p.nombre, ' ', p.apellido) AS nombre,
                p.tipo_persona AS tipo,
                pnp.email AS pref_email, pnp.whatsapp AS pref_whatsapp,
                pnp.canal,
                pnp.notif_credenciales, pnp.notif_pago,
                pnp.notif_cuota_vencer, 
                 pnp.notif_incidencia
         FROM personas p
         LEFT JOIN persona_notif_prefs pnp
               ON pnp.id_persona = p.id AND pnp.id_empresa = ?
         WHERE {$where}
         ORDER BY p.apellido, p.nombre
         LIMIT 200"
    );
    $stmt->execute(array_merge($params, $wParams));
    $rows = $stmt->fetchAll();
    if ($hasMultiRol) {
        foreach ($rows as &$row) {
            $stT = $db->prepare("SELECT tipo FROM persona_tipos WHERE id_persona = ? AND id_empresa = ? ORDER BY es_primario DESC");
            $stT->execute([$row['id'], $id_empresa]);
            $row['tipos'] = array_column($stT->fetchAll(), 'tipo');
        }
    }
    jsonResponse(['personas' => $rows]);
}

function cargarPrefs(PDO $db, int $id_empresa): never {
    $idPersona = (int)($_GET['id_persona'] ?? 0);
    if (!$idPersona) jsonError('id_persona requerido');

    $stmt = $db->prepare(
        "SELECT p.id, CONCAT(p.nombre,' ',p.apellido) AS nombre, p.tipo_persona AS tipo,
                pnp.email AS pref_email, pnp.whatsapp AS pref_whatsapp, pnp.canal,
                pnp.notif_credenciales, pnp.notif_pago, pnp.notif_cuota_vencer,
                  pnp.notif_incidencia
         FROM personas p
         LEFT JOIN persona_notif_prefs pnp ON pnp.id_persona = p.id AND pnp.id_empresa = ?
         WHERE p.id = ? AND p.id_empresa = ? LIMIT 1"
    );
    $stmt->execute([$id_empresa, $idPersona, $id_empresa]);
    $row = $stmt->fetch();
    if (!$row) jsonError('Persona no encontrada', 404);

    $row['tipos'] = [$row['tipo']];
    try {
        $stT = $db->prepare("SELECT tipo FROM persona_tipos WHERE id_persona = ? AND id_empresa = ? ORDER BY es_primario DESC");
        $stT->execute([$idPersona, $id_empresa]);
        $tipos = array_column($stT->fetchAll(), 'tipo');
        if (!empty($tipos)) $row['tipos'] = $tipos;
    } catch (\Throwable) {}

    jsonResponse(['persona' => $row]);
}

function guardarPrefs(PDO $db, int $id_empresa, array $d): never {
    $idPersona = (int)($d['id_persona'] ?? 0);
    if (!$idPersona) jsonError('id_persona requerido');

    $chk = $db->prepare("SELECT id FROM personas WHERE id = ? AND id_empresa = ? LIMIT 1");
    $chk->execute([$idPersona, $id_empresa]);
    if (!$chk->fetch()) jsonError('Persona no encontrada', 404);

    $canal = in_array($d['canal'] ?? '', ['email','whatsapp','ambos','ninguno'])
             ? $d['canal'] : 'email';
    $email = trim($d['email']    ?? '') ?: null;
    $wapp  = trim($d['whatsapp'] ?? '') ?: null;

    $db->prepare(
        "INSERT INTO persona_notif_prefs
            (id_persona, id_empresa, email, whatsapp, canal,
             notif_credenciales, notif_pago, notif_cuota_vencer,
             )
         VALUES (?,?,?,?,?,?,?,?,?,?,?)
         ON DUPLICATE KEY UPDATE
            email = VALUES(email), whatsapp = VALUES(whatsapp), canal = VALUES(canal),
            notif_credenciales = VALUES(notif_credenciales),
            notif_pago = VALUES(notif_pago),
            notif_cuota_vencer = VALUES(notif_cuota_vencer),
            
            
            notif_incidencia = VALUES(notif_incidencia)"
    )->execute([
        $idPersona, $id_empresa, $email, $wapp, $canal,
        (int)!empty($d['notif_credenciales']),
        (int)!empty($d['notif_pago']),
        (int)!empty($d['notif_cuota_vencer']),
        (int)!empty($d['']),
        (int)!empty($d['']),
        (int)!empty($d['notif_incidencia']),
    ]);

    jsonResponse(['success' => true, 'mensaje' => 'Preferencias guardadas']);
}

function guardarMasivo(PDO $db, int $id_empresa, array $d): never {
    $ids = array_values(array_unique(array_filter(array_map('intval', $d['ids'] ?? []), fn($v) => $v > 0)));
    if (empty($ids)) jsonError('No se enviaron personas');

    // Validar que todos pertenecen al centro
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $chk = $db->prepare("SELECT COUNT(*) FROM personas WHERE id IN ({$placeholders}) AND id_empresa = ?");
    $chk->execute(array_merge($ids, [$id_empresa]));
    if ((int)$chk->fetchColumn() !== count($ids)) jsonError('Personas no válidas para este centro', 403);

    $canal  = in_array($d['canal'] ?? '', ['email','whatsapp','ambos','ninguno']) ? $d['canal'] : null;
    $campos = ['notif_credenciales','notif_pago','notif_cuota_vencer','notif_incidencia'];

    $db->beginTransaction();
    try {
        foreach ($ids as $idPersona) {
            $chkE = $db->prepare("SELECT id_persona FROM persona_notif_prefs WHERE id_persona = ? AND id_empresa = ? LIMIT 1");
            $chkE->execute([$idPersona, $id_empresa]);
            if ($chkE->fetch()) {
                $sets = []; $vals = [];
                if ($canal !== null)  { $sets[] = 'canal = ?'; $vals[] = $canal; }
                foreach ($campos as $c) {
                    if (array_key_exists($c, $d)) { $sets[] = "{$c} = ?"; $vals[] = (int)$d[$c]; }
                }
                if ($sets) {
                    $vals[] = $idPersona; $vals[] = $id_empresa;
                    $db->prepare("UPDATE persona_notif_prefs SET " . implode(', ', $sets) . " WHERE id_persona = ? AND id_empresa = ?")->execute($vals);
                }
            } else {
                $db->prepare(
                    "INSERT INTO persona_notif_prefs (id_persona,id_empresa,email,whatsapp,canal,
                     notif_credenciales,notif_pago,notif_cuota_vencer,notif_incidencia)
                     VALUES (?,?,NULL,NULL,?,?,?,?,?,?,?)"
                )->execute([
                    $idPersona, $id_empresa,
                    $canal ?? 'email',
                    array_key_exists('notif_credenciales', $d) ? (int)$d['notif_credenciales'] : 1,
                    array_key_exists('notif_pago', $d)         ? (int)$d['notif_pago']         : 1,
                    array_key_exists('notif_cuota_vencer', $d) ? (int)$d['notif_cuota_vencer'] : 1,
                    array_key_exists( $d)    ? (int)$d['']    : 1,
                    array_key_exists( $d)      ? (int)$d['']      : 1,
                    array_key_exists('notif_incidencia', $d)   ? (int)$d['notif_incidencia']   : 0,
                ]);
            }
        }
        $db->commit();
        jsonResponse(['success' => true, 'actualizados' => count($ids), 'mensaje' => count($ids) . ' personas actualizadas']);
    } catch (\Throwable $e) {
        $db->rollBack();
        jsonError('Error al guardar: ' . $e->getMessage());
    }
}
