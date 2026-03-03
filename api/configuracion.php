<?php
// php/configuracion.php — API de Configuración de Empresa
require_once __DIR__ . '/../php/helpers.php';
require_once __DIR__ . '/../php/notificaciones.php';

apiHeaders();
iniciarSesionSegura();

// Solo superadmin y admin
$rol = $_SESSION['rol'] ?? '';
if (!in_array($rol, ['superadmin', 'admin'], true)) {
    jsonError('No autorizado', 401);
}

$esSuperadmin = $rol === 'superadmin';
// superadmin opera sobre centro 1; los demás usan su propio centro
// Superadmin siempre opera sobre el centro 1
// id_empresa viene siempre de la sesión (superadmin ya tiene 1 fijado al login)
$id_empresa = (int)($_SESSION['id_empresa'] ?? 0);
$method    = $_SERVER['REQUEST_METHOD'];
$action    = sanitizeStr($_GET['action'] ?? $_POST['action'] ?? '');

// GET actions
if ($method === 'GET') {
    match ($action) {
        'test_smtp'          => testSmtp(),
        'preview_recibo'     => previewRecibo(),
        'demo_recibo'        => demoRecibo(),
        'demo_contrato'      => demoContrato(),
        'municipios'         => getMunicipios(),
        'get_periodos_activos'   => getPeriodosActivos(),
        'get_boletin_defaults'   => getBoletinDefaults(),
        'get_anios_electivos'    => getAniosElectivos(),
        'get_soporte_config'     => getSoporteConfig(),
        'test_notificacion'      => testNotificacion(),
        'callmebot_numeros_list'   => callmebotNumerosList(),
        'callmebot_numeros_add'    => callmebotNumerosAdd(),
        'callmebot_numeros_del'    => callmebotNumerosDel(),
        'callmebot_numeros_toggle' => callmebotNumerosToggle(),
        default              => jsonError('Acción no válida', 404),
    };
}

// POST: guardar sección
if ($method === 'POST') {
    // JSON body actions
    $raw  = file_get_contents('php://input');
    $body = [];
    if ($raw) {
        $decoded = json_decode($raw, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            $body = $decoded;
        }
    }
    $jsonAction = sanitizeStr($body['action'] ?? '');

    if ($jsonAction === 'enviar_datos') {
        enviarDatos($body);
        exit;
    }
    if ($jsonAction === 'guardar_boletin_defaults') { guardarBoletinDefaults($body); }
    if ($jsonAction === 'guardar_soporte') { guardarSoporte($body); }
    if ($jsonAction === 'toggle_anio_electivo') {
        toggleAnioElectivo($body);
        exit;
    }
    if ($jsonAction === 'crear_anio_electivo') {
        crearAnioElectivo($body);
        exit;
    }

    if ($action !== '') {
        match ($action) {
            'test_smtp'                => testSmtp(),
            'callmebot_numeros_add'    => callmebotNumerosAdd(),
            'callmebot_numeros_del'    => callmebotNumerosDel(),
            'callmebot_numeros_toggle' => callmebotNumerosToggle(),
            default                    => jsonError('Acción no válida', 404),
        };
    }

    $seccion = sanitizeStr($_POST['seccion'] ?? '');

    match ($seccion) {
        'empresa'        => guardarEmpresa(),
        'smtp'           => guardarSmtp(),
        'notificaciones' => guardarNotificaciones(),
        'whatsapp'       => guardarWhatsApp(),
        'impresoras'     => guardarImpresoras(),
        'boletines'      => guardarBoletines(),
        default          => jsonError('Sección no válida', 400),
    };
}

// ══════════════════════════════════════════════════════════════
// SOPORTE — Configuración de modo debug de errores
// ══════════════════════════════════════════════════════════════

/**
 * Garantiza que la columna debug_errors exista en configuracion_empresa.
 * Compatible con MySQL 5.4+ (sin IF NOT EXISTS en ALTER).
 */
function _ensureDebugColumn(): void {
    static $checked = false;
    if ($checked) return;
    $checked = true;
    try {
        $db   = getDB();
        $cols = array_column($db->query("SHOW COLUMNS FROM configuracion_empresa LIKE 'debug_errors'")->fetchAll(), 'Field');
        if (empty($cols)) {
            $db->exec("ALTER TABLE configuracion_empresa ADD COLUMN debug_errors TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Modo debug: muestra errores técnicos de BD en lugar de mensajes amigables'");
        }
    } catch (\Throwable) {}
}

/**
 * GET ?action=get_soporte_config
 * Retorna la configuración de soporte (modo debug) del centro.
 * Solo accesible por superadmin.
 */
function getSoporteConfig(): never {
    global $id_empresa, $esSuperadmin, $sesion;
    // Solo superadmin puede ver/modificar esta configuración
    if (!$esSuperadmin) jsonError('Solo el superadministrador puede acceder a esta configuración.', 403);
    _ensureDebugColumn();
    $db   = getDB();
    $stmt = $db->prepare("SELECT debug_errors FROM configuracion_empresa WHERE id_empresa <=> ? LIMIT 1");
    $stmt->execute([$id_empresa]);
    $row = $stmt->fetch() ?: [];
    jsonResponse([
        'debug_errors' => (bool)($row['debug_errors'] ?? false),
    ]);
}

/**
 * POST body: { action: 'guardar_soporte', debug_errors: true/false }
 * Guarda la configuración de soporte del centro.
 * Solo accesible por superadmin.
 */
function guardarSoporte(array $body): never {
    global $id_empresa, $esSuperadmin, $sesion;
    if (!$esSuperadmin) jsonError('Solo el superadministrador puede modificar esta configuración.', 403);
    _ensureDebugColumn();
    updateCfg(['debug_errors' => (int)(bool)($body['debug_errors'] ?? false)]);
    $uid = (int)($_SESSION['usuario_id'] ?? 0);
    $val = (bool)($body['debug_errors'] ?? false) ? 'activado' : 'desactivado';
    registrarAudit($uid, "soporte_debug_errors_{$val}", 1, ['id_empresa' => $id_empresa]);
    jsonResponse(['success' => true, 'mensaje' => "Modo debug de errores {$val} correctamente."]);
}


jsonError('Método no permitido', 405);

// ── Helpers ──────────────────────────────────────────────────────────────────

function getCfgId(): int {
    global $id_empresa;
    $db   = getDB();
    $stmt = $db->prepare('SELECT id FROM configuracion_empresa WHERE id_empresa <=> ? LIMIT 1');
    $stmt->execute([$id_empresa]);
    $row = $stmt->fetch();

    if ($row) return (int)$row['id'];

    // Crear fila si no existe
    $ins = $db->prepare(
        'INSERT INTO configuracion_empresa (id_empresa) VALUES (?)'
    );
    $ins->execute([$id_empresa]);
    return (int)$db->lastInsertId();
}

function updateCfg(array $fields): void {
    $id  = getCfgId();
    $db  = getDB();
    $set = implode(', ', array_map(fn($k) => "`$k` = ?", array_keys($fields)));
    $vals = array_values($fields);
    $vals[] = $id;
    $db->prepare("UPDATE configuracion_empresa SET $set WHERE id = ?")->execute($vals);
}

// ── Guardar Empresa ───────────────────────────────────────────────────────────
function guardarEmpresa(): never {
    $fields = [
        'nombre_empresa'  => sanitizeStr($_POST['nombre_empresa'] ?? ''),
        'slogan'          => sanitizeStr($_POST['slogan']         ?? ''),
        'rnc'             => sanitizeStr($_POST['rnc']            ?? '', 20),
        'moneda'          => sanitizeStr($_POST['moneda']         ?? 'DOP', 10),
        'telefono'        => sanitizeStr($_POST['telefono']       ?? '', 30),
        'email'           => sanitizeStr($_POST['email']          ?? ''),
        'direccion'       => sanitizeStr($_POST['direccion']      ?? '', 1000),
        'pie_recibo'      => sanitizeStr($_POST['pie_recibo']     ?? '', 1000),
        'session_timeout' => (int)($_POST['session_timeout'] ?? 0),
        'whatsapp_prefix' => sanitizeStr($_POST['whatsapp_prefix'] ?? '+1', 10),
        'id_provincia'    => (isset($_POST['id_provincia']) && $_POST['id_provincia'] !== '') ? (int)$_POST['id_provincia'] : null,
        'id_municipio'    => (isset($_POST['id_municipio'])  && $_POST['id_municipio']  !== '') ? (int)$_POST['id_municipio']  : null,
    ];

    if ($fields['nombre_empresa'] === '') jsonError('El nombre de la empresa es obligatorio.');

    $monedas = ['DOP','USD','EUR','GTQ','HNL','CRC','MXN','COP'];
    if (!in_array($fields['moneda'], $monedas, true)) $fields['moneda'] = 'DOP';

    // Logo upload
    if (!empty($_FILES['logo']['tmp_name'])) {
        $logo = handleLogoUpload();
        if ($logo) $fields['logo_path'] = $logo;
    }

    updateCfg($fields);
    limpiarBrandCache(isset($_SESSION['id_empresa']) ? (int)$_SESSION['id_empresa'] : null);
    registrarAudit((int)$_SESSION['usuario_id'], 'config_empresa_guardada', 1);
    jsonResponse(['success' => true, 'mensaje' => 'Configuración de empresa guardada.']);
}

function handleLogoUpload(): string|false {
    $file     = $_FILES['logo'];
    $maxSize  = 512000; // 500KB
    $allowed  = ['image/png', 'image/jpeg', 'image/svg+xml'];
    $mimeType = mime_content_type($file['tmp_name']);

    if ($file['size'] > $maxSize) return false;
    if (!in_array($mimeType, $allowed, true)) return false;

    $ext     = match ($mimeType) {
        'image/png'     => 'png',
        'image/svg+xml' => 'svg',
        default         => 'jpg',
    };

    $dir = __DIR__ . '/../uploads/logos/';
    if (!is_dir($dir)) mkdir($dir, 0755, true);

    $filename = 'logo_' . ($_SESSION['id_empresa'] ?? 'global') . '_' . time() . '.' . $ext;
    $dest     = $dir . $filename;

    if (!move_uploaded_file($file['tmp_name'], $dest)) return false;

    return '/GestionPrestamo/uploads/logos/' . $filename;
}

// ── Guardar SMTP ──────────────────────────────────────────────────────────────
function guardarSmtp(): never {
    $passRaw = $_POST['smtp_pass'] ?? '';
    $fields  = [
        'smtp_host'      => sanitizeStr($_POST['smtp_host']      ?? ''),
        'smtp_port'      => (int)($_POST['smtp_port'] ?? 465),
        'smtp_user'      => sanitizeStr($_POST['smtp_user']      ?? ''),
        'smtp_from_name' => sanitizeStr($_POST['smtp_from_name'] ?? ''),
        'smtp_security'  => sanitizeStr($_POST['smtp_security']  ?? 'SSL', 10),
    ];

    if (!in_array($fields['smtp_security'], ['SSL','TLS','none'], true)) {
        $fields['smtp_security'] = 'SSL';
    }

    // Solo actualizar contraseña si no es el placeholder
    if ($passRaw !== '__KEEP__' && $passRaw !== '') {
        // En producción cifrar con openssl_encrypt o similar
        $fields['smtp_pass'] = $passRaw;
    }

    updateCfg($fields);
    registrarAudit((int)$_SESSION['usuario_id'], 'config_smtp_guardada', 1);
    jsonResponse(['success' => true, 'mensaje' => 'Configuración SMTP guardada.']);
}

// ── Guardar Notificaciones ────────────────────────────────────────────────────
function guardarNotificaciones(): never {
    $canales = ['email', 'email_whatsapp'];
    $canal   = sanitizeStr($_POST['notif_canal'] ?? 'email');
    if (!in_array($canal, $canales, true)) $canal = 'email';

    updateCfg([
        'notif_canal'          => $canal,
        'notif_credenciales'   => (int)($_POST['notif_credenciales']  ?? 0),
        'notif_pago'           => (int)($_POST['notif_pago']          ?? 0),
        'notif_cuota_vencer'   => (int)($_POST['notif_cuota_vencer']  ?? 0),
    ]);

    registrarAudit((int)$_SESSION['usuario_id'], 'config_notificaciones_guardada', 1);
    jsonResponse(['success' => true, 'mensaje' => 'Notificaciones guardadas.']);
}

// ── Guardar WhatsApp ──────────────────────────────────────────────────────────
function guardarWhatsApp(): never {
    updateCfg([
        'callmebot_key'    => sanitizeStr($_POST['callmebot_key']    ?? '', 100),
        'callmebot_number' => sanitizeStr($_POST['callmebot_number'] ?? '', 30),
    ]);
    registrarAudit((int)$_SESSION['usuario_id'], 'config_whatsapp_guardada', 1);
    jsonResponse(['success' => true, 'mensaje' => 'Configuración de WhatsApp guardada.']);
}

// ── Guardar Impresoras ────────────────────────────────────────────────────────
function guardarImpresoras(): never {
    $opts = ['normal', 'pos80', 'pos58'];
    $get  = fn($k) => in_array($_POST[$k] ?? '', $opts, true) ? $_POST[$k] : 'normal';

    updateCfg([
        'imp_recibo'        => $get('imp_recibo'),
        'imp_contrato'      => $get('imp_contrato'),
        'imp_estado_cuenta' => $get('imp_estado_cuenta'),
        'imp_amortizacion'  => $get('imp_amortizacion'),
    ]);

    registrarAudit((int)$_SESSION['usuario_id'], 'config_impresoras_guardada', 1);
    jsonResponse(['success' => true, 'mensaje' => 'Configuración de impresoras guardada.']);
}


// ── Control de períodos por fechas — boletines ───────────────────────────────

// ── Helper: garantizar columnas de boletin_defaults en configuracion_empresa ──
function _ensureBoletinDefaults(): void {
    static $checked = false;
    if ($checked) return;
    $checked = true;
    try {
        $db = getDB();
        $cols = array_column($db->query("SHOW COLUMNS FROM configuracion_empresa")->fetchAll(), 'Field');
        // IMPORTANTE: el orden importa — cada columna referencia la anterior en AFTER
        $needed = [
            'boletin_mostrar_asist'   => "ALTER TABLE configuracion_empresa ADD COLUMN boletin_mostrar_asist TINYINT(1) NOT NULL DEFAULT 1",
            'boletin_mostrar_firmas'  => "ALTER TABLE configuracion_empresa ADD COLUMN boletin_mostrar_firmas TINYINT(1) NOT NULL DEFAULT 1",
            'boletin_mostrar_sit'     => "ALTER TABLE configuracion_empresa ADD COLUMN boletin_mostrar_sit TINYINT(1) NOT NULL DEFAULT 1",
            'boletin_mostrar_obs'     => "ALTER TABLE configuracion_empresa ADD COLUMN boletin_mostrar_obs TINYINT(1) NOT NULL DEFAULT 1",
            'boletin_campos_config'   => "ALTER TABLE configuracion_empresa ADD COLUMN boletin_campos_config LONGTEXT DEFAULT NULL",
            'boletin_periodos_config' => "ALTER TABLE configuracion_empresa ADD COLUMN boletin_periodos_config LONGTEXT DEFAULT NULL",
            'boletin_periodos_pri'    => "ALTER TABLE configuracion_empresa ADD COLUMN boletin_periodos_pri LONGTEXT DEFAULT NULL",
            'boletin_periodos_sec'    => "ALTER TABLE configuracion_empresa ADD COLUMN boletin_periodos_sec LONGTEXT DEFAULT NULL",
            'boletin_firma_maestro'   => "ALTER TABLE configuracion_empresa ADD COLUMN boletin_firma_maestro VARCHAR(255) DEFAULT NULL",
            'boletin_firma_director'  => "ALTER TABLE configuracion_empresa ADD COLUMN boletin_firma_director VARCHAR(255) DEFAULT NULL",
            'boletin_anio_escolar'    => "ALTER TABLE configuracion_empresa ADD COLUMN boletin_anio_escolar VARCHAR(20) DEFAULT NULL",
        ];
        foreach ($needed as $col => $sql) {
            if (!in_array($col, $cols)) {
                $db->exec($sql);
            }
        }
    } catch (\Throwable) {}
}

// ── Helper: garantizar que columna 'activo' exista en periodos_escolares ──────
function _ensureActivoColumn(): void {
    static $checked = false;
    if ($checked) return;
    $checked = true;
    try {
        $db = getDB();
        // Comprobar si la columna existe
        $cols = $db->query("SHOW COLUMNS FROM periodos_escolares LIKE 'activo'")->fetchAll();
        if (empty($cols)) {
            $db->exec("ALTER TABLE periodos_escolares ADD COLUMN activo TINYINT(1) NOT NULL DEFAULT 0");
            // Activar el año más reciente de cada centro por defecto
            $db->exec("UPDATE periodos_escolares pe1
                       SET pe1.activo = 1
                       WHERE pe1.anio_inicio = (
                           SELECT MAX(pe2.anio_inicio)
                           FROM (SELECT * FROM periodos_escolares) pe2
                           WHERE pe2.id_empresa <=> pe1.id_empresa
                       )");
        }
    } catch (\Throwable $e) {
        // Si falla, continuar — las queries individuales manejarán el error
    }
}

// Listar años electivos del centro con su estado activo
function getAniosElectivos(): never {
    global $id_empresa;
    _ensureActivoColumn();
    $db = getDB();
    // Superadmin usa centro 1; admin usa su propio centro
    $stmt = $db->prepare('SELECT pe.id, pe.anio_inicio, pe.anio_fin, pe.descripcion,
                                 COALESCE(pe.activo,0) AS activo,
                                 COUNT(DISTINCT m.id) AS total_matriculas
                          FROM periodos_escolares pe
                          LEFT JOIN matriculas m ON m.id_periodo_escolar = pe.id
                          WHERE pe.id_empresa = ?
                          GROUP BY pe.id
                          ORDER BY pe.activo DESC, pe.anio_inicio DESC');
    $stmt->execute([$id_empresa]);
    $periodos = $stmt->fetchAll();
    $activos_count = count(array_filter($periodos, fn($p) => (int)$p['activo'] === 1));
    jsonResponse(['periodos' => $periodos, 'activos_count' => $activos_count]);
}

// Activar/desactivar un año electivo (máximo 2 activos por centro)
function toggleAnioElectivo(array $body): never {
    global $id_empresa, $esSuperadmin;
    _ensureActivoColumn();
    $db = getDB();
    $id = (int)($body['id'] ?? 0);
    $activar = (bool)($body['activar'] ?? false);
    if (!$id) jsonError('ID inválido', 400);

    // Verificar que el período existe
    $stmt = $db->prepare('SELECT id, id_empresa FROM periodos_escolares WHERE id = ? LIMIT 1');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    if (!$row) jsonError('Período no encontrado', 404);

    $centro = (int)$row['id_empresa'];
    if (!$esSuperadmin && $centro !== $id_empresa) jsonError('Acceso denegado', 403);

    if ($activar) {
        // Verificar máximo 2 activos por centro
        $cntStmt = $db->prepare('SELECT COUNT(*) AS cnt FROM periodos_escolares WHERE id_empresa = ? AND activo = 1');
        $cntStmt->execute([$centro]);
        $cnt = (int)($cntStmt->fetch()['cnt'] ?? 0);
        if ($cnt >= 2) {
            jsonError('Solo se permiten máximo 2 años electivos activos por centro. Desactiva uno primero.', 409);
        }
        $db->prepare('UPDATE periodos_escolares SET activo = 1 WHERE id = ?')->execute([$id]);
    } else {
        $db->prepare('UPDATE periodos_escolares SET activo = 0 WHERE id = ?')->execute([$id]);
    }
    registrarAudit((int)$_SESSION['usuario_id'], $activar ? 'anio_electivo_activado' : 'anio_electivo_desactivado', $id);
    jsonResponse(['success' => true, 'mensaje' => $activar ? 'Año electivo activado.' : 'Año electivo desactivado.']);
}

// Crear nuevo año electivo
function crearAnioElectivo(array $body): never {
    global $id_empresa, $esSuperadmin;
    _ensureActivoColumn();
    $db = getDB();

    // Superadmin usa centro 1; admin usa el suyo
    $centro = $id_empresa;

    $ini  = (int)($body['anio_inicio'] ?? 0);
    $fin  = (int)($body['anio_fin']    ?? 0);
    $desc = sanitizeStr($body['descripcion'] ?? "Año Escolar {$ini}-{$fin}");
    if (!$ini || !$fin || $fin <= $ini) jsonError('Años inválidos: el año fin debe ser mayor al inicio.', 400);
    if (!$desc) $desc = "Año Escolar {$ini}-{$fin}";

    // Verificar duplicado
    $dup = $db->prepare('SELECT id FROM periodos_escolares WHERE id_empresa = ? AND anio_inicio = ? AND anio_fin = ? LIMIT 1');
    $dup->execute([$centro, $ini, $fin]);
    if ($dup->fetch()) jsonError('Ya existe un período con esos años para este centro.', 409);

    $db->prepare('INSERT INTO periodos_escolares (id_empresa, anio_inicio, anio_fin, descripcion, activo) VALUES (?,?,?,?,0)')
       ->execute([$centro, $ini, $fin, $desc]);
    $nuevoId = (int)$db->lastInsertId();
    registrarAudit((int)$_SESSION['usuario_id'], 'anio_electivo_creado', $nuevoId);
    jsonResponse(['success' => true, 'id' => $nuevoId, 'mensaje' => "Año Escolar {$ini}-{$fin} creado correctamente."]);
}



function getBoletinDefaults(): never {
    global $id_empresa, $esSuperadmin;
    _ensureBoletinDefaults();
    // Permitir que boletines.php envíe su id_empresa por GET (igual que getPeriodosActivos)
    $centro = $esSuperadmin
        ? (int)($_GET['id_empresa'] ?? $id_empresa)
        : $id_empresa;
    $db  = getDB();
    $row = $db->prepare('SELECT boletin_firma_maestro, boletin_firma_director, boletin_anio_escolar,
                                boletin_mostrar_asist, boletin_mostrar_firmas,
                                boletin_mostrar_sit, boletin_mostrar_obs, boletin_campos_config
                         FROM configuracion_empresa WHERE id_empresa <=> ? LIMIT 1');
    $row->execute([$centro]);
    $d = $row->fetch() ?: [];
    $camposRaw = $d['boletin_campos_config'] ?? null;
    $camposBloqueados = $camposRaw ? (json_decode($camposRaw, true) ?? []) : [];
    jsonResponse([
        'firma_maestro'     => $d['boletin_firma_maestro']  ?? '',
        'firma_director'    => $d['boletin_firma_director'] ?? '',
        'anio_escolar'      => $d['boletin_anio_escolar']   ?? '',
        'mostrar_asist'     => (bool)($d['boletin_mostrar_asist']  ?? 1),
        'mostrar_firmas'    => (bool)($d['boletin_mostrar_firmas'] ?? 1),
        'mostrar_sit'       => (bool)($d['boletin_mostrar_sit']    ?? 1),
        'mostrar_obs'       => (bool)($d['boletin_mostrar_obs']    ?? 1),
        'campos_bloqueados' => $camposBloqueados,
    ]);
}

function guardarBoletinDefaults(array $body): never {
    global $id_empresa;
    _ensureBoletinDefaults();
    $camposBloqueados = $body['campos_bloqueados'] ?? [];
    if (!is_array($camposBloqueados)) $camposBloqueados = [];
    // Solo permitir strings en el array
    $camposBloqueados = array_values(array_filter(array_map('strval', $camposBloqueados)));
    updateCfg([
        'boletin_firma_maestro'  => sanitizeStr($body['firma_maestro']  ?? ''),
        'boletin_firma_director' => sanitizeStr($body['firma_director'] ?? ''),
        'boletin_anio_escolar'   => sanitizeStr($body['anio_escolar']   ?? ''),
        'boletin_mostrar_asist'  => (int)(bool)($body['mostrar_asist']  ?? true),
        'boletin_mostrar_firmas' => (int)(bool)($body['mostrar_firmas'] ?? true),
        'boletin_mostrar_sit'    => (int)(bool)($body['mostrar_sit']    ?? true),
        'boletin_mostrar_obs'    => (int)(bool)($body['mostrar_obs']    ?? true),
        'boletin_campos_config'  => json_encode($camposBloqueados, JSON_UNESCAPED_UNICODE),
    ]);
    registrarAudit((int)$_SESSION['usuario_id'], 'boletin_defaults_guardados', $id_empresa);
    jsonResponse(['success' => true, 'mensaje' => 'Configuración del boletín guardada correctamente.']);
}

function getPeriodosActivos(): never {
    global $id_empresa, $esSuperadmin;
    _ensureActivoColumn();
    _ensureBoletinDefaults();
    $db   = getDB();
    // Permitir que boletines.php envíe su id_empresa por GET
    $centro = $esSuperadmin
        ? (int)($_GET['id_empresa'] ?? $id_empresa)
        : $id_empresa;
    $stmt = $db->prepare('SELECT boletin_periodos_config FROM configuracion_empresa WHERE id_empresa <=> ? LIMIT 1');
    $stmt->execute([$centro]);
    $row  = $stmt->fetch();
    $raw  = $row['boletin_periodos_config'] ?? null;
    $config = $raw ? json_decode($raw, true) : [];

    $hoy = date('Y-m-d');

    // Also load pri/sec specific configs
    $stmtPri = $db->prepare('SELECT boletin_periodos_pri FROM configuracion_empresa WHERE id_empresa <=> ? LIMIT 1');
    $stmtPri->execute([$centro]);
    $rowPri = $stmtPri->fetch();
    $rawPri = $rowPri['boletin_periodos_pri'] ?? null;
    $configPri = ($rawPri ? json_decode($rawPri, true) : null) ?? $config;

    $stmtSec = $db->prepare('SELECT boletin_periodos_sec FROM configuracion_empresa WHERE id_empresa <=> ? LIMIT 1');
    $stmtSec->execute([$centro]);
    $rowSec = $stmtSec->fetch();
    $rawSec = $rowSec['boletin_periodos_sec'] ?? null;
    $configSec = ($rawSec ? json_decode($rawSec, true) : null) ?? $config;

    function _buildDetalle(array $cfg, string $hoy): array {
        $activos = []; $detalle = [];
        foreach ($cfg as $p) {
            $num    = (int)($p['periodo'] ?? 0);
            $ini    = $p['fecha_inicio'] ?? '';
            $fin    = $p['fecha_fin']    ?? '';
            $forzar = (bool)($p['forzar_activo'] ?? false);
            $porFechas = ($ini && $fin && $hoy >= $ini && $hoy <= $fin);
            $abierto   = $forzar || $porFechas;
            if ($abierto) $activos[] = $num;
            $diasRestantes = null;
            if ($porFechas && $fin) $diasRestantes = (int)ceil((strtotime($fin) - strtotime($hoy)) / 86400);
            $detalle[] = ['periodo'=>$num,'fecha_inicio'=>$ini,'fecha_fin'=>$fin,'forzar_activo'=>$forzar,'activo'=>$abierto,'dias_restantes'=>$diasRestantes];
        }
        return ['activos'=>$activos,'detalle'=>$detalle];
    }

    $resPri = _buildDetalle($configPri, $hoy);
    $resSec = _buildDetalle($configSec, $hoy);
    $resGen = _buildDetalle($config, $hoy);

    jsonResponse([
        'periodos_activos' => $resGen['activos'],
        'detalle'          => $resGen['detalle'],
        'detalle_pri'      => $resPri['detalle'],
        'detalle_sec'      => $resSec['detalle'],
        'activos_pri'      => $resPri['activos'],
        'activos_sec'      => $resSec['activos'],
        'hoy'              => $hoy,
    ]);
}

function _parsePeriodosConfig(array $parsed): array {
    $limpio = [];
    foreach ($parsed as $p) {
        $num    = (int)($p['periodo'] ?? 0);
        if ($num < 1 || $num > 4) continue;
        $ini    = sanitizeStr($p['fecha_inicio'] ?? '');
        $fin    = sanitizeStr($p['fecha_fin']    ?? '');
        $forzar = (bool)($p['forzar_activo']     ?? false);
        $reIni  = preg_match('/^\d{4}-\d{2}-\d{2}$/', $ini);
        $reFin  = preg_match('/^\d{4}-\d{2}-\d{2}$/', $fin);
        $limpio[] = [
            'periodo'       => $num,
            'fecha_inicio'  => $reIni ? $ini : '',
            'fecha_fin'     => $reFin ? $fin : '',
            'forzar_activo' => $forzar,
        ];
    }
    usort($limpio, fn($a, $b) => $a['periodo'] - $b['periodo']);
    return $limpio;
}

function guardarBoletines(): never {
    // Recibe JSON con array de {periodo, fecha_inicio, fecha_fin}
    // Save per-level configs
    $rawPri = $_POST['periodos_config_pri'] ?? '';
    $rawSec = $_POST['periodos_config_sec'] ?? '';
    $raw = $_POST['periodos_config'] ?? $rawPri;  // backward compat
    $parsed = json_decode($raw, true);
    if (!is_array($parsed)) jsonError('Datos inválidos', 400);

    $limpio = _parsePeriodosConfig($parsed);

    // Also save per-level configs
    $limpioPri = $rawPri ? _parsePeriodosConfig(json_decode($rawPri, true) ?? []) : $limpio;
    $limpioSec = $rawSec ? _parsePeriodosConfig(json_decode($rawSec, true) ?? []) : $limpio;
    updateCfg([
        'boletin_periodos_config' => json_encode($limpio,     JSON_UNESCAPED_UNICODE),
        'boletin_periodos_pri'    => json_encode($limpioPri,  JSON_UNESCAPED_UNICODE),
        'boletin_periodos_sec'    => json_encode($limpioSec,  JSON_UNESCAPED_UNICODE),
    ]);
    registrarAudit((int)$_SESSION['usuario_id'], 'config_boletines_guardada', 1);
    jsonResponse(['success' => true, 'mensaje' => 'Configuración de períodos guardada correctamente.']);
}

// ── Municipios por provincia (AJAX) ──────────────────────────────────────────
function getMunicipios(): never {
    $id_prov = (int)($_GET['id_provincia'] ?? 0);
    if (!$id_prov) jsonResponse(['municipios' => []]);

    $db   = getDB();
    $stmt = $db->prepare('SELECT id, nombre FROM municipios WHERE id_provincia = ? ORDER BY nombre');
    $stmt->execute([$id_prov]);
    jsonResponse(['municipios' => $stmt->fetchAll()]);
}

// ── Test SMTP ─────────────────────────────────────────────────────────────────
function testSmtp(): never {
    global $id_empresa;
    $db   = getDB();
    $stmt = $db->prepare('SELECT * FROM configuracion_empresa WHERE id_empresa <=> ? LIMIT 1');
    $stmt->execute([$id_empresa]);
    $cfg  = $stmt->fetch();

    if (empty($cfg['smtp_host']) || empty($cfg['smtp_user'])) {
        jsonError('Configura primero el servidor SMTP antes de enviar una prueba.');
    }

    // Enviar email real de prueba al mismo remitente configurado
    $destino = $cfg['email'] ?? $cfg['smtp_user'];
    $empresa = $cfg['nombre_empresa'] ?? 'Centro Educativo';
    $res = _notif_sendEmail(
        $cfg,
        $destino,
        'Administrador',
        "✅ Prueba SMTP — {$empresa}",
        "<p>Este es un mensaje de prueba del sistema <strong>{$empresa}</strong>.</p><p>Si recibes este correo, la configuración SMTP está funcionando correctamente. 🎉</p>",
        "Prueba SMTP de {$empresa}. Si recibes este correo, la configuración es correcta."
    );

    if ($res === true) {
        jsonResponse([
            'success' => true,
            'mensaje' => "Email de prueba enviado correctamente a {$destino}. Revisa tu bandeja de entrada.",
        ]);
    } else {
        jsonError("Error al enviar: {$res}");
    }
}

/**
 * GET ?action=test_notificacion&tipo=credenciales|pago|matricula&email=...
 * Envía una notificación de prueba al email indicado (o al configurado).
 * Solo para superadmin.
 */
function testNotificacion(): never {
    global $id_empresa, $esSuperadmin;

    $db   = getDB();
    $stmt = $db->prepare('SELECT * FROM configuracion_empresa WHERE id_empresa <=> ? LIMIT 1');
    $stmt->execute([$id_empresa]);
    $cfg  = $stmt->fetch();

    if (!$cfg) {
        jsonError('No hay configuración para este centro.');
    }
    // Solo bloquear si intentan enviar email sin SMTP
    $soloWA = (($_GET['email'] ?? '') === 'skip') && !empty($_GET['whatsapp']);
    if (!$soloWA && empty($cfg['smtp_host'])) {
        jsonError('Configura primero el servidor SMTP, o usa el botón de prueba de WhatsApp directamente.');
    }

    $tipo      = sanitizeStr($_GET['tipo'] ?? 'credenciales');
    $emailRaw  = sanitizeStr($_GET['email'] ?? '');
    $skipEmail = ($emailRaw === 'skip');
    $destino   = $skipEmail ? '' : ($emailRaw ?: ($cfg['email'] ?? $cfg['smtp_user'] ?? ''));
    $empresa   = $cfg['nombre_empresa'] ?? 'Centro Educativo';

    // Si no es skip, validar email; si solo va WhatsApp, omitir email
    if (!$skipEmail && !filter_var($destino, FILTER_VALIDATE_EMAIL)) {
        if (empty($_GET['whatsapp'])) {
            jsonError('Email de destino inválido o no configurado.');
        }
        $skipEmail = true; // Solo WhatsApp
    }

    // Sobreescribir temporalmente el email destino en cfg para el test
    $cfgTest = $cfg;

    $subject  = '';
    $bodyHtml = '';
    $msgWA    = '';

    switch ($tipo) {
        case 'pago':
            $subject  = "Confirmación de pago — {$empresa} [PRUEBA]";
            $bodyHtml = _notif_email_wrap(
                "Confirmación de Pago [PRUEBA]",
                "<p>Este es un <strong>mensaje de prueba</strong> de confirmación de pago.</p>
                 <div class='field'><label>Estudiante</label><span>Juan Pérez (Demo)</span></div>
                 <div class='field'><label>Concepto</label><span>Cuota Mensual</span></div>
                 <div class='field'><label>Monto</label><span>RD$ 2,500.00</span></div>
                 <div class='field'><label>Fecha</label><span>" . date('d/m/Y') . "</span></div>",
                $empresa,
                'Mensaje de prueba — generado desde Configuración → Notificaciones.'
            );
            $msgWA = "🏫 *{$empresa}* [PRUEBA]\n\n✅ *Pago recibido*\nEstudiante: Juan Pérez\nConcepto: Cuota Mensual\nMonto: RD$ 2,500.00\nFecha: " . date('d/m/Y');
            break;

        case 'matricula':
            $subject  = "Matrícula confirmada — {$empresa} [PRUEBA]";
            $bodyHtml = _notif_email_wrap(
                "Matrícula Confirmada [PRUEBA]",
                "<p>Este es un <strong>mensaje de prueba</strong> de confirmación de matrícula.</p>
                 <div class='field'><label>Estudiante</label><span>María García (Demo)</span></div>
                 <div class='field'><label>Período</label><span>2025-2026</span></div>
                 <div class='field'><label>Grado</label><span>1ro de Secundaria</span></div>
                 <div class='field'><label>Sección</label><span>A</span></div>",
                $empresa,
                'Mensaje de prueba — generado desde Configuración → Notificaciones.'
            );
            $msgWA = "🏫 *{$empresa}* [PRUEBA]\n\n✅ *Matrícula confirmada*\nEstudiante: María García\nPeríodo: 2025-2026\nGrado: 1ro de Secundaria\nSección: A";
            break;

        default: // credenciales
            $subject  = "Credenciales de acceso — {$empresa} [PRUEBA]";
            $bodyHtml = _notif_email_wrap(
                "Credenciales de Acceso [PRUEBA]",
                "<p>Este es un <strong>mensaje de prueba</strong> de envío de credenciales.</p>
                 <div class='field'><label>Usuario</label><span>demo.usuario</span></div>
                 <div class='field'><label>Contraseña temporal</label><span>Demo@12345</span></div>
                 <p style='font-size:.82rem;color:#64748b'>⚠️ Esto es solo una prueba. No hay credenciales reales en este mensaje.</p>",
                $empresa,
                'Mensaje de prueba — generado desde Configuración → Notificaciones.'
            );
            $msgWA = "🏫 *{$empresa}* [PRUEBA]\n\nHola Demo, tu cuenta ha sido creada.\n👤 Usuario: `demo.usuario`\n🔑 Contraseña: `Demo@12345`\n_Este mensaje es solo una prueba._";
    }

    $resultados = [];

    // Enviar email (si no se saltó)
    if (!$skipEmail && $destino) {
        $resEmail = _notif_sendEmail($cfgTest, $destino, 'Administrador (Prueba)', $subject, $bodyHtml);
        $resultados['email'] = $resEmail === true;
        if ($resEmail !== true) $resultados['email_error'] = $resEmail;
    }

    // Enviar WhatsApp si se proporcionó número
    if (!empty($_GET['whatsapp'])) {
        $apiKey = $cfg['callmebot_key'] ?? '';
        if (!$apiKey) {
            $resultados['whatsapp']       = false;
            $resultados['whatsapp_error'] = 'No hay API Key de CallMeBot configurada. Guarda la API Key en la pestaña WhatsApp primero.';
        } else {
            $phone = sanitizeStr($_GET['whatsapp']);
            if (!str_starts_with($phone, '+')) {
                $phone = ($cfg['whatsapp_prefix'] ?? '+1') . ltrim($phone, '0');
            }
            $resWA = _notif_sendWhatsApp($phone, $msgWA, $apiKey);
            $resultados['whatsapp'] = $resWA === true;
            if ($resWA !== true) $resultados['whatsapp_error'] = $resWA;
        }
    }

    $okEmail = $resultados['email']    ?? null;
    $okWA    = $resultados['whatsapp'] ?? null;
    // Éxito si al menos uno de los canales usados funcionó
    $ok = ($okEmail === true) || ($okWA === true);

    $canal = [];
    if ($okEmail === true) $canal[] = "email a {$destino}";
    if ($okWA    === true) $canal[] = 'WhatsApp';
    $canalMsg = $canal ? implode(' y ', $canal) : 'ningún canal';

    jsonResponse([
        'success'     => $ok,
        'mensaje'     => $ok
            ? "✅ Prueba enviada por {$canalMsg}."
            : "❌ Falló el envío. Revisa los errores.",
        'resultados'  => $resultados,
    ]);
}


function previewRecibo(): never {
    header('Content-Type: text/html; charset=utf-8');
    echo '<html><body style="font-family:sans-serif;padding:40px;max-width:400px;margin:auto">';
    echo '<h2>Vista previa — Recibo de Pago</h2>';
    echo '<p style="color:#64748b">Aquí aparecerá el recibo con los datos de tu empresa una vez configurada.</p>';
    echo '</body></html>';
    exit;
}

function demoRecibo(): never {
    header('Content-Type: text/html; charset=utf-8');
    echo '<html><body style="font-family:sans-serif;padding:40px;max-width:400px;margin:auto">';
    echo '<h2>Demo — Recibo de Pago</h2><hr>';
    echo '<p>Estudiante: Juan Pérez | Monto: RD$ 2,500.00 | Fecha: ' . date('d/m/Y') . '</p>';
    echo '</body></html>';
    exit;
}

function demoContrato(): never {
    header('Content-Type: text/html; charset=utf-8');
    echo '<html><body style="font-family:sans-serif;padding:40px;max-width:700px;margin:auto">';
    echo '<h2>Demo — Contrato / Documento</h2><hr>';
    echo '<p>Aquí aparecerá el documento oficial con los datos de matrícula y condiciones.</p>';
    echo '</body></html>';
    exit;
}

// ── Enviar Datos a Empresa ────────────────────────────────────────────────────
function enviarDatos(array $body): never {
    global $esSuperadmin;
    if (!$esSuperadmin) jsonError('Solo el superadmin puede enviar datos', 403);

    $idDestino = (int)($body['id_empresa_destino'] ?? 0);
    $tipos     = $body['tipos'] ?? [];
    $modo      = $body['modo'] ?? 'omitir'; // 'omitir' | 'actualizar'

    if (!$idDestino) jsonError('Empresa destino requerida', 400);
    if (empty($tipos)) jsonError('Selecciona al menos un tipo de dato', 400);

    $db = getDB();

    // Verificar que el destino existe
    $dest = $db->prepare('SELECT id, nombre FROM empresas WHERE id = ?');
    $dest->execute([$idDestino]);
    $destino = $dest->fetch();
    if (!$destino) jsonError('Empresa destino no encontrada', 404);

    $resultados = [];
    $tiposValidos = ['tandas', 'niveles', 'asignaturas', 'periodos', 'secciones', 'materias_catalogo'];
    $idOrigen = (int)($body['id_empresa_origen'] ?? $GLOBALS['id_empresa'] ?? 0);
    if (!$idOrigen) jsonError('Centro origen requerido', 400);

    foreach ($tipos as $tipo) {
        if (!in_array($tipo, $tiposValidos, true)) continue;
        $res = match($tipo) {
            'tandas'           => enviarTandas($db, $idOrigen, $idDestino, $modo),
            'niveles'          => enviarNiveles($db, $idOrigen, $idDestino, $modo),
            'asignaturas'      => enviarAsignaturas($db, $idOrigen, $idDestino, $modo),
            'periodos'         => enviarPeriodos($db, $idOrigen, $idDestino, $modo),
            'secciones'        => enviarSecciones($db, $idOrigen, $idDestino, $modo),
            'materias_catalogo'=> enviarCatalogoAcademico($db, $idOrigen, $idDestino, $modo),
        };
        $resultados[$tipo] = $res;
    }

    $totalIns = array_sum(array_column($resultados, 'insertados'));
    $totalOmi = array_sum(array_column($resultados, 'omitidos'));

    jsonResponse([
        'success'    => true,
        'mensaje'    => "Datos enviados a \"{$destino['nombre']}\": {$totalIns} registros insertados, {$totalOmi} omitidos.",
        'resultados' => $resultados,
    ]);
}

function enviarTandas(PDO $db, int $origen, int $dest, string $modo): array {
    // tandas: (id, id_empresa, nombre) — no tiene descripcion
    $rows = $db->query("SELECT nombre FROM tandas WHERE id_empresa = $origen OR id_empresa IS NULL")->fetchAll();
    $ins = 0; $omi = 0;
    foreach ($rows as $r) {
        $exists = $db->prepare("SELECT id FROM tandas WHERE nombre = ? AND id_empresa = ?");
        $exists->execute([$r['nombre'], $dest]);
        if ($exists->fetch()) { $omi++; }
        else {
            $db->prepare("INSERT INTO tandas (nombre, id_empresa) VALUES (?,?)")->execute([$r['nombre'], $dest]);
            $ins++;
        }
    }
    return ['insertados' => $ins, 'omitidos' => $omi];
}

function enviarNiveles(PDO $db, int $origen, int $dest, string $modo): array {
    // Copiar niveles
    $rows = $db->query("SELECT id, nombre FROM niveles WHERE id_empresa = $origen OR id_empresa IS NULL")->fetchAll();
    $ins = 0; $omi = 0;
    $mapaIdNivel = []; // id_nivel origen => id_nivel destino
    foreach ($rows as $r) {
        $exists = $db->prepare("SELECT id FROM niveles WHERE nombre = ? AND id_empresa = ?");
        $exists->execute([$r['nombre'], $dest]);
        $found = $exists->fetch();
        if ($found) {
            $mapaIdNivel[(int)$r['id']] = (int)$found['id'];
            $omi++;
        } else {
            $db->prepare("INSERT INTO niveles (nombre, id_empresa) VALUES (?,?)")->execute([$r['nombre'], $dest]);
            $nuevoId = (int)$db->lastInsertId();
            $mapaIdNivel[(int)$r['id']] = $nuevoId;
            $ins++;
        }
    }
    // Copiar grados asociados (necesarios para secciones)
    $grados = $db->query("SELECT id, nombre, id_nivel FROM grados WHERE id_empresa = $origen OR id_empresa IS NULL")->fetchAll();
    foreach ($grados as $g) {
        $idNivelDest = $mapaIdNivel[(int)$g['id_nivel']] ?? null;
        if (!$idNivelDest) continue;
        $ex = $db->prepare("SELECT id FROM grados WHERE nombre = ? AND id_empresa = ? AND id_nivel = ?");
        $ex->execute([$g['nombre'], $dest, $idNivelDest]);
        if (!$ex->fetch()) {
            $db->prepare("INSERT INTO grados (nombre, id_nivel, id_empresa) VALUES (?,?,?)")
               ->execute([$g['nombre'], $idNivelDest, $dest]);
        }
    }
    return ['insertados' => $ins, 'omitidos' => $omi];
}

function enviarAsignaturas(PDO $db, int $origen, int $dest, string $modo): array {
    $rows = $db->query("SELECT nombre, descripcion, tipo FROM asignaturas WHERE id_empresa = $origen")->fetchAll();
    $ins = 0; $omi = 0;
    foreach ($rows as $r) {
        $exists = $db->prepare("SELECT id FROM asignaturas WHERE nombre = ? AND id_empresa = ?");
        $exists->execute([$r['nombre'], $dest]);
        if ($exists->fetch()) {
            if ($modo === 'actualizar') {
                $db->prepare("UPDATE asignaturas SET descripcion=?, tipo=? WHERE nombre=? AND id_empresa=?")->execute([$r['descripcion'], $r['tipo'], $r['nombre'], $dest]);
                $ins++;
            } else { $omi++; }
        } else {
            $db->prepare("INSERT INTO asignaturas (nombre, descripcion, tipo, id_empresa) VALUES (?,?,?,?)")->execute([$r['nombre'], $r['descripcion'], $r['tipo'], $dest]);
            $ins++;
        }
    }
    return ['insertados' => $ins, 'omitidos' => $omi];
}

function enviarPeriodos(PDO $db, int $origen, int $dest, string $modo): array {
    $rows = $db->query("SELECT anio_inicio, anio_fin, descripcion FROM periodos_escolares WHERE id_empresa = $origen")->fetchAll();
    $ins = 0; $omi = 0;
    foreach ($rows as $r) {
        $exists = $db->prepare("SELECT id FROM periodos_escolares WHERE anio_inicio = ? AND anio_fin = ? AND id_empresa = ?");
        $exists->execute([$r['anio_inicio'], $r['anio_fin'], $dest]);
        if ($exists->fetch()) {
            if ($modo === 'actualizar') {
                $db->prepare("UPDATE periodos_escolares SET descripcion=? WHERE anio_inicio=? AND anio_fin=? AND id_empresa=?")
                   ->execute([$r['descripcion'], $r['anio_inicio'], $r['anio_fin'], $dest]);
                $ins++;
            } else { $omi++; }
        } else {
            $db->prepare("INSERT INTO periodos_escolares (anio_inicio, anio_fin, descripcion, id_empresa) VALUES (?,?,?,?)")
               ->execute([$r['anio_inicio'], $r['anio_fin'], $r['descripcion'], $dest]);
            $ins++;
        }
    }
    return ['insertados' => $ins, 'omitidos' => $omi];
}

function enviarCatalogoAcademico(PDO $db, int $origen, int $dest, string $modo): array {
    // Copia todas las materias del catálogo académico al centro destino como asignaturas tipo='Academica'
    $rows = $db->query("SELECT nombre, descripcion FROM materias_academicas_catalogo ORDER BY nombre")->fetchAll();
    $ins = 0; $omi = 0;
    $stChk = $db->prepare("SELECT id FROM asignaturas WHERE LOWER(nombre)=LOWER(?) AND id_empresa=? AND tipo='Academica'");
    $stIns = $db->prepare("INSERT INTO asignaturas (nombre, descripcion, tipo, id_empresa) VALUES (?, ?, 'Academica', ?)");
    $stUpd = $db->prepare("UPDATE asignaturas SET descripcion=? WHERE LOWER(nombre)=LOWER(?) AND id_empresa=? AND tipo='Academica'");
    foreach ($rows as $r) {
        $stChk->execute([$r['nombre'], $dest]);
        if ($stChk->fetch()) {
            if ($modo === 'actualizar') {
                $stUpd->execute([$r['descripcion'], $r['nombre'], $dest]);
                $ins++;
            } else { $omi++; }
        } else {
            $stIns->execute([$r['nombre'], $r['descripcion'], $dest]);
            $ins++;
        }
    }
    return ['insertados' => $ins, 'omitidos' => $omi];
}

function enviarSecciones(PDO $db, int $origen, int $dest, string $modo): array {
    // Obtener secciones del centro origen con sus grado y tanda
    $rows = $db->query(
        "SELECT s.nombre, g.nombre AS grado_nombre, n.nombre AS nivel_nombre, t.nombre AS tanda_nombre
         FROM secciones s
         JOIN grados g ON g.id = s.id_grado
         JOIN niveles n ON n.id = g.id_nivel
         JOIN tandas  t ON t.id = s.id_tanda
         WHERE s.id_empresa = $origen"
    )->fetchAll();
    $ins = 0; $omi = 0;
    foreach ($rows as $r) {
        // Resolver id_nivel destino
        $stNivel = $db->prepare("SELECT id FROM niveles WHERE nombre = ? AND id_empresa = ?");
        $stNivel->execute([$r['nivel_nombre'], $dest]);
        $nivel = $stNivel->fetch();
        if (!$nivel) { $omi++; continue; }

        // Resolver id_grado destino
        $stGrado = $db->prepare("SELECT id FROM grados WHERE nombre = ? AND id_nivel = ? AND id_empresa = ?");
        $stGrado->execute([$r['grado_nombre'], $nivel['id'], $dest]);
        $grado = $stGrado->fetch();
        if (!$grado) { $omi++; continue; }

        // Resolver id_tanda destino
        $stTanda = $db->prepare("SELECT id FROM tandas WHERE nombre = ? AND id_empresa = ?");
        $stTanda->execute([$r['tanda_nombre'], $dest]);
        $tanda = $stTanda->fetch();
        if (!$tanda) { $omi++; continue; } // tanda no fue enviada aún

        // Verificar duplicado
        $exists = $db->prepare("SELECT id FROM secciones WHERE nombre = ? AND id_grado = ? AND id_tanda = ? AND id_empresa = ?");
        $exists->execute([$r['nombre'], $grado['id'], $tanda['id'], $dest]);
        if ($exists->fetch()) { $omi++; continue; }

        $db->prepare("INSERT INTO secciones (nombre, id_grado, id_tanda, id_empresa) VALUES (?,?,?,?)")
           ->execute([$r['nombre'], $grado['id'], $tanda['id'], $dest]);
        $ins++;
    }
    return ['insertados' => $ins, 'omitidos' => $omi];
}

// ══════════════════════════════════════════════════════════════════════════════
// GESTIÓN TABLA callmebot_numeros
// ══════════════════════════════════════════════════════════════════════════════

function callmebotNumerosList(): never {
    global $id_empresa;
    $db   = getDB();
    $stmt = $db->prepare(
        'SELECT id, phone, descripcion, activo
         FROM callmebot_numeros WHERE id_empresa = ? ORDER BY id ASC'
    );
    $stmt->execute([$id_empresa]);
    jsonResponse(['success' => true, 'numeros' => $stmt->fetchAll()]);
}

function callmebotNumerosAdd(): never {
    global $id_empresa, $esSuperadmin;
    $phone = sanitizeStr($_POST['phone'] ?? '', 30);
    $desc  = sanitizeStr($_POST['descripcion'] ?? '', 100);
    if (!$phone) jsonError('Número de teléfono requerido.');
    // Limpiar y validar formato básico
    $phone = preg_replace('/[\s\-\(\)]/', '', $phone);
    if (!preg_match('/^\+?\d{7,15}$/', $phone)) jsonError('Número inválido. Use formato: +18091234567');
    $db = getDB();

    if ($esSuperadmin) {
        // Superadmin: insertar en TODOS los centros activos
        $centros = $db->query('SELECT id FROM empresas ORDER BY id ASC')->fetchAll(PDO::FETCH_COLUMN);
        $ins = $db->prepare('INSERT INTO callmebot_numeros (id_empresa, phone, descripcion, activo, created_at) VALUES (?,?,?,1,NOW()) ON DUPLICATE KEY UPDATE descripcion=VALUES(descripcion), activo=1');
        foreach ($centros as $cid) {
            $ins->execute([(int)$cid, $phone, $desc]);
        }
        registrarAudit((int)$_SESSION['usuario_id'], 'callmebot_numero_agregado_global', 1);
        jsonResponse(['success' => true, 'mensaje' => 'Número agregado en ' . count($centros) . ' centros.']);
    } else {
        // Admin normal: solo su centro
        $dup = $db->prepare('SELECT id FROM callmebot_numeros WHERE id_empresa = ? AND phone = ? LIMIT 1');
        $dup->execute([$id_empresa, $phone]);
        if ($dup->fetch()) jsonError('Este número ya está registrado para este centro.');
        $db->prepare('INSERT INTO callmebot_numeros (id_empresa, phone, descripcion, activo, created_at) VALUES (?,?,?,1,NOW())')
           ->execute([$id_empresa, $phone, $desc]);
        $newId = (int)$db->lastInsertId();
        registrarAudit((int)$_SESSION['usuario_id'], 'callmebot_numero_agregado', 1);
        jsonResponse(['success' => true, 'id' => $newId, 'mensaje' => 'Número agregado.']);
    }
}

function callmebotNumerosDel(): never {
    global $id_empresa, $esSuperadmin;
    $id = (int)($_POST['id'] ?? 0);
    if (!$id) jsonError('ID requerido.');
    $db = getDB();

    if ($esSuperadmin) {
        // Superadmin: obtener el phone del registro y eliminarlo en TODOS los centros
        $row = $db->prepare('SELECT phone FROM callmebot_numeros WHERE id = ? LIMIT 1');
        $row->execute([$id]);
        $rec = $row->fetch();
        if (!$rec) jsonError('Número no encontrado.');
        $stmt = $db->prepare('DELETE FROM callmebot_numeros WHERE phone = ?');
        $stmt->execute([$rec['phone']]);
        $deleted = $stmt->rowCount();
        registrarAudit((int)$_SESSION['usuario_id'], 'callmebot_numero_eliminado_global', 1);
        jsonResponse(['success' => true, 'mensaje' => 'Número eliminado de ' . $deleted . ' centros.']);
    } else {
        // Admin normal: solo su centro
        $stmt = $db->prepare('DELETE FROM callmebot_numeros WHERE id = ? AND id_empresa = ? LIMIT 1');
        $stmt->execute([$id, $id_empresa]);
        if ($stmt->rowCount() === 0) jsonError('Número no encontrado o sin permisos.');
        registrarAudit((int)$_SESSION['usuario_id'], 'callmebot_numero_eliminado', 1);
        jsonResponse(['success' => true, 'mensaje' => 'Número eliminado.']);
    }
}

// Toggle activo/inactivo de un número CallMeBot
function callmebotNumerosToggle(): never {
    global $id_empresa, $esSuperadmin;
    $id = (int)($_POST['id'] ?? 0);
    if (!$id) jsonError('ID requerido.');
    $db = getDB();

    if ($esSuperadmin) {
        // Superadmin: toggle en todos los centros (por phone)
        $row = $db->prepare('SELECT phone, activo FROM callmebot_numeros WHERE id = ? LIMIT 1');
        $row->execute([$id]);
        $rec = $row->fetch();
        if (!$rec) jsonError('Número no encontrado.');
        $nuevoEstado = $rec['activo'] ? 0 : 1;
        $db->prepare('UPDATE callmebot_numeros SET activo = ? WHERE phone = ?')
           ->execute([$nuevoEstado, $rec['phone']]);
        registrarAudit((int)$_SESSION['usuario_id'], 'callmebot_numero_toggle_global', 1);
        jsonResponse(['success' => true, 'activo' => $nuevoEstado]);
    } else {
        $row = $db->prepare('SELECT activo FROM callmebot_numeros WHERE id = ? AND id_empresa = ? LIMIT 1');
        $row->execute([$id, $id_empresa]);
        $rec = $row->fetch();
        if (!$rec) jsonError('Número no encontrado.');
        $nuevoEstado = $rec['activo'] ? 0 : 1;
        $db->prepare('UPDATE callmebot_numeros SET activo = ? WHERE id = ? AND id_empresa = ?')
           ->execute([$nuevoEstado, $id, $id_empresa]);
        registrarAudit((int)$_SESSION['usuario_id'], 'callmebot_numero_toggle', 1);
        jsonResponse(['success' => true, 'activo' => $nuevoEstado]);
    }
}